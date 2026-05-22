// minihighlight.js -- Peteramati line-by-line syntax highlighter
// Peteramati is Copyright (c) 2006-2026 Eddie Kohler
// See LICENSE for open-source distribution terms

// A small, fast, *stateful* syntax highlighter for a limited set of languages.
// Unlike the per-line highlight.js path, this threads lexer state across lines,
// so multi-line constructs (block comments, multi-line/raw/triple strings,
// template literals) highlight correctly even when they open off-screen.
//
// `minihighlight_line(lang, text, state)` returns `{value, top}`, where `value`
// is HTML using highlight.js class names and `top` is the opaque lexer state to
// pass to the next line.

const AMPRE = /[&<>]/g;
function esc(s) {
    return s.replace(AMPRE, c => (c === "&" ? "&amp;" : c === "<" ? "&lt;" : "&gt;"));
}

function words(s) {
    return new Set(s.trim().split(/\s+/));
}

// Shared patterns (sticky, matched at a given position).
const NUM = /(?:0[xX][0-9a-fA-F][0-9a-fA-F']*|0[bB][01][01']*|0[oO][0-7][0-7']*|(?:\d[\d']*\.?[\d']*|\.\d[\d']*)(?:[eEpP][-+]?\d+)?)[uUlLfFjJ]*/y;
const ID = /[A-Za-z_$\u0080-\uffff][\w$\u0080-\uffff]*/y;
const CPP_RAW = /(?:u8|u|U|L)?R"([^()\\ \t]{0,16})\(/y;

const C_KEYWORDS = "alignas alignof asm auto break case catch class concept const consteval constexpr constinit const_cast continue co_await co_return co_yield decltype default delete do dynamic_cast else enum explicit export extern for friend goto if inline mutable namespace new noexcept operator private protected public register reinterpret_cast requires return sizeof static static_assert static_cast struct switch template this thread_local throw try typedef typeid typename union using virtual volatile while restrict _Alignas _Alignof _Atomic _Generic _Noreturn _Static_assert _Thread_local";
const C_TYPES = "bool char char8_t char16_t char32_t double float int long short signed unsigned void wchar_t size_t ssize_t ptrdiff_t intptr_t uintptr_t int8_t int16_t int32_t int64_t uint8_t uint16_t uint32_t uint64_t FILE va_list";
const C_LITERALS = "true false nullptr NULL";

const JS_KEYWORDS = "as async await break case catch class const continue debugger default delete do else export extends finally for from function get if import in instanceof let new of return set static super switch this throw try typeof var void while with yield enum implements interface namespace declare type readonly public private protected abstract is keyof infer satisfies override";
const JS_LITERALS = "true false null undefined NaN Infinity";

const PY_KEYWORDS = "and as assert async await break class continue def del elif else except finally for from global if import in is lambda nonlocal not or pass raise return try while with yield match case";
const PY_LITERALS = "True False None NotImplemented Ellipsis self cls";

const SH_KEYWORDS = "if then elif else fi for while until do done case esac in function select time return break continue local export readonly declare set unset shift eval exec trap source";

const MAKE_KEYWORDS = "ifeq ifneq ifdef ifndef else endif define endef include sinclude vpath override export unexport private";

const STR_DQ = { o: '"', c: '"', e: true };
const STR_SQ = { o: "'", c: "'", e: true };
const STR_C_LONG = [
    { o: 'L"', c: '"', e: true }, { o: 'u"', c: '"', e: true },
    { o: 'U"', c: '"', e: true }, { o: 'u8"', c: '"', e: true },
    { o: "L'", c: "'", e: true }, { o: "u'", c: "'", e: true },
    { o: "U'", c: "'", e: true }, { o: "u8'", c: "'", e: true }
];
const STR_SQ_RAW = { o: "'", c: "'", e: false };
const STR_TMPL = { o: "`", c: "`", e: true, multiline: true };
// Triple-quoted strings must precede single-character delimiters.
const STR_PY = [
    { o: '"""', c: '"""', e: true, multiline: true },
    { o: "'''", c: "'''", e: true, multiline: true },
    STR_DQ, STR_SQ
];

const CHF_ID = 1, CHF_STRING = 2, CHF_CPP_RAW = 4, CHF_META = 8,
    CHF_LC = 16, CHF_BC = 32, CHF_NUM = 64;

const SPECS = {
    c: null, cpp: null, javascript: null, typescript: null,
    python: null, shell: null, make: null
};

function spec(o) {
    const table = new Uint8Array(129);
    function flag(ch, f) {
        if (typeof ch === "string") {
            ch = ch.charCodeAt(0);
        }
        table[ch < 128 ? ch : 128] |= f;
    }
    const s = {
        lc: o.lc || [],
        bc: o.bc || [],
        strings: o.strings || [],
        cpp_raw: !!o.cpp_raw,
        meta: o.meta || null,
        kw: o.kw || new Set,
        type: o.type || null,
        lit: o.lit || null,
        num: o.num || NUM,
        id: o.id || ID,
        table: table,
        flag: flag
    };
    for (const lc of s.lc) {
        flag(lc, CHF_LC);
    }
    for (const bc of s.bc) {
        flag(bc[0], CHF_BC);
    }
    for (const str of s.strings) {
        flag(str.o, CHF_STRING);
    }
    if (s.cpp_raw) {
        for (const ch of "uULR") {
            flag(ch, CHF_CPP_RAW);
        }
    }
    if (s.num === NUM) {
        for (const ch of "0123456789.") {
            flag(ch, CHF_NUM);
        }
    }
    if (s.id === ID) {
        for (const ch of "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_$\u0080") {
            flag(ch, CHF_ID);
        }
    }
    // sanity-check regular expressions
    for (const re of [s.meta, s.num, s.id, s.cpp_raw ? CPP_RAW : null]) {
        if (re) {
            re.lastIndex = 0;
            if (!re.sticky || re.test("")) {
                throw new Error("bad regular expression: matches empty or non-sticky");
            }
        }
    }
    return s;
}

function create_spec(lang) {
    if (SPECS[lang]) {
        return SPECS[lang];
    }
    if (lang === "c") {
        SPECS[lang] = spec({
            lc: ["//"], bc: [["/*", "*/"]],
            strings: [STR_DQ, STR_SQ, ...STR_C_LONG],
            meta: /#\s*[A-Za-z_]+/y, kw: words(C_KEYWORDS),
            type: words(C_TYPES), lit: words(C_LITERALS)
        });
        SPECS[lang].flag("#", CHF_META);
    } else if (lang === "cpp") {
        SPECS[lang] = spec({
            lc: ["//"], bc: [["/*", "*/"]],
            strings: [STR_DQ, STR_SQ, ...STR_C_LONG],
            meta: /#\s*[A-Za-z_]+/y, kw: words(C_KEYWORDS),
            type: words(C_TYPES), lit: words(C_LITERALS), cpp_raw: true
        });
        SPECS[lang].flag("#", CHF_META);
    } else if (lang === "javascript" || lang === "typescript") {
        SPECS[lang] = spec({
            lc: ["//"], bc: [["/*", "*/"]], strings: [STR_DQ, STR_SQ, STR_TMPL],
            kw: words(JS_KEYWORDS), lit: words(JS_LITERALS)
        });
    } else if (lang === "python") {
        SPECS[lang] = spec({
            lc: ["#"], strings: STR_PY,
            kw: words(PY_KEYWORDS), lit: words(PY_LITERALS)
        });
    } else if (lang === "shell") {
        SPECS[lang] = spec({
            lc: ["#"], strings: [STR_DQ, STR_SQ_RAW], kw: words(SH_KEYWORDS)
        });
    } else if (lang === "make") {
        SPECS[lang] = spec({
            lc: ["#"], strings: [STR_DQ, STR_SQ_RAW], kw: words(MAKE_KEYWORDS)
        });
    }
    return SPECS[lang];
}


const ALIASES = {
    "c++": "cpp", cc: "cpp", cxx: "cpp", hpp: "cpp", hh: "cpp", "h++": "cpp",
    h: "c", js: "javascript", jsx: "javascript", mjs: "javascript", cjs: "javascript",
    ts: "typescript", tsx: "typescript", py: "python", py3: "python",
    sh: "shell", bash: "shell", zsh: "shell", makefile: "make", mk: "make"
};

function canon(lang) {
    if (!lang) {
        return null;
    }
    const l = lang.toLowerCase();
    return Object.prototype.hasOwnProperty.call(ALIASES, l) ? ALIASES[l] : l;
}

/** @param {?string} lang
 * @return {boolean} */
export function minihighlight_supports(lang) {
    return lang ? SPECS[canon(lang)] !== undefined : false;
}


// Scan from `from` for the (unescaped) `close` delimiter.
function consume(text, len, pos, close, escaped) {
    const closech = close.charCodeAt(0);
    while (pos < len) {
        const ch = text.charCodeAt(pos);
        if (escaped && ch === 92 /* \ */) {
            pos += 2;
        } else if (ch === closech && text.startsWith(close, pos)) {
            return { end: pos + close.length, closed: true };
        } else {
            ++pos;
        }
    }
    return { end: len, closed: false };
}

function string_at(strings, text, pos) {
    for (const d of strings) {
        if (text.startsWith(d.o, pos)) {
            return d;
        }
    }
    return null;
}

function pair_at(pairs, text, pos) {
    for (const p of pairs) {
        if (text.startsWith(p[0], pos)) {
            return p;
        }
    }
    return null;
}

function prefix_at(prefixes, text, pos) {
    for (const p of prefixes) {
        if (text.startsWith(p, pos)) {
            return p;
        }
    }
    return null;
}

/** @param {string} lang
 * @param {string} text one line, optionally ending in \n|\r|\r\n
 * @param {*} state lexer state from the previous line, or null
 * @return {?{value: string, top: *}} null if `lang` is unsupported */
export function minihighlight_line(lang, text, state) {
    const sp = create_spec(canon(lang));
    if (!sp) {
        return null;
    }
    let len = text.length;
    if (len > 0 && text.charCodeAt(len - 1) === 10 /* \n */) {
        --len;
    }
    if (len > 0 && text.charCodeAt(len - 1) === 13 /* \r */) {
        --len;
    }
    let out = "", pos = 0, last = 0, sol = true /* start of line */;

    function flush() {
        if (pos !== last) {
            out += esc(text.substring(last, pos));
            last = pos;
        }
    }
    function emit(cls, pos1) {
        flush();
        out += '<span class="hljs-' + cls + '">' + esc(text.substring(pos, pos1)) + "</span>";
        pos = last = pos1;
        sol = false;
    }
    function done() {
        flush();
        return { value: out + (len !== text.length ? "\n" : ""), top: state };
    }

    // Continue a multi-line construct opened on a previous line.
    if (state) {
        const r = consume(text, len, 0, state.c, state.e);
        emit(state.k === "block" ? "comment" : "string", r.end);
        if (!r.closed) {
            return done();
        }
        state = null;
    }

    const table = sp.table;
    while (true) {
        // whitespace
        let ch, f;
        while (pos < len) {
            ch = text.charCodeAt(pos);
            if ((f = table[ch < 128 ? ch : 128]) !== 0) {
                break;
            }
            if (sol && ch !== 32 && ch !== 9) {
                sol = false;
            }
            ++pos;
        }
        if (pos >= len) {
            break;
        }
        // preprocessor / directive at start of line
        if (sol && (f & CHF_META)) {
            sp.meta.lastIndex = pos;
            const mm = sp.meta.exec(text);
            if (mm) {
                emit("meta", pos + mm[0].length);
                continue;
            }
        }
        sol = false;
        // line comment
        if ((f & CHF_LC) && prefix_at(sp.lc, text, pos)) {
            emit("comment", len);
            break;
        }
        // block comment
        if (f & CHF_BC) {
            const bc = pair_at(sp.bc, text, pos);
            if (bc) {
                const r = consume(text, len, pos + bc[0].length, bc[1], false);
                emit("comment", r.end);
                if (!r.closed) {
                    state = { k: "block", c: bc[1], e: false };
                    break;
                }
                continue;
            }
        }
        // C++ raw string
        if (f & CHF_CPP_RAW) {
            CPP_RAW.lastIndex = pos;
            const rm = CPP_RAW.exec(text);
            if (rm) {
                const close = ")" + rm[1] + '"',
                    r = consume(text, len, pos + rm[0].length, close, false);
                emit("string", r.end);
                if (!r.closed) {
                    state = { k: "str", c: close, e: false };
                    break;
                }
                continue;
            }
        }
        // strings (triple/template before single-char delimiters)
        if (f & CHF_STRING) {
            const sd = string_at(sp.strings, text, pos);
            if (sd) {
                const r = consume(text, len, pos + sd.o.length, sd.c, sd.e);
                emit("string", r.end);
                if (!r.closed
                    && (sd.multiline || text.charCodeAt(len - 1) === 92 /* \ */)) {
                    state = { k: "str", c: sd.c, e: sd.e };
                    break;
                }
                continue;
            }
        }
        // number
        if (f & CHF_NUM) {
            sp.num.lastIndex = pos;
            const num = sp.num.exec(text);
            if (num) {
                emit("number", pos + num[0].length);
                continue;
            }
        }
        // identifier / keyword
        if (f & CHF_ID) {
            sp.id.lastIndex = pos;
            const idm = sp.id.exec(text);
            if (idm) {
                const w = idm[0];
                let cls = null;
                if (sp.kw.has(w)) {
                    cls = "keyword";
                } else if (sp.type && sp.type.has(w)) {
                    cls = "type";
                } else if (sp.lit && sp.lit.has(w)) {
                    cls = "literal";
                }
                if (cls) {
                    emit(cls, pos + w.length);
                } else {
                    pos += w.length;
                }
                continue;
            }
        }
        // any other character
        ++pos;
    }

    return done();
}
