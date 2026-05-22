// minihighlight.js -- Peteramati line-by-line syntax highlighter
// Peteramati is Copyright (c) 2006-2026 Eddie Kohler
// See LICENSE for open-source distribution terms

// A small, fast, *stateful* syntax highlighter for a limited set of languages.
// Unlike the per-line highlight.js path, this threads lexer state across lines,
// so multi-line constructs (block comments, multi-line/raw/triple strings,
// template literals) highlight correctly even when they open off-screen.
//
// `minihighlight(text, lang, state)` returns `{value, top}`, where `value`
// is HTML using highlight.js class names and `top` is the opaque lexer state to
// pass to the next line.

const AMPRE = /[&<>]/g;
function esc(s) {
    return s.replace(AMPRE, c => (c === "&" ? "&amp;" : c === "<" ? "&lt;" : "&gt;"));
}

// Word classification flags. A word can carry several (e.g. `class` is both a
// keyword and a class introducer), so they live together in one Map.
const WF_KEYWORD = 1, WF_TYPE = 2, WF_LITERAL = 4, WF_CLASSKW = 8;

function add_words(map, s, flag) {
    for (const w of (s || "").trim().split(/\s+/)) {
        if (w !== "") {
            map.set(w, (map.get(w) || 0) | flag);
        }
    }
}

// Shared patterns (sticky, matched at a given position).
const NUM = /(?:0[xX][0-9a-fA-F][0-9a-fA-F']*|0[bB][01][01']*|0[oO][0-7][0-7']*|(?:\d[\d']*\.?[\d']*|\.\d[\d']*)(?:[eEpP][-+]?\d+)?)[uUlLfFjJ]*/y;
const ID = /[A-Za-z_$\u0080-\uffff][\w$\u0080-\uffff]*/y;
const CPP_RAW = /(?:u8|u|U|L)?R"([^()\\ \t]{0,16})\(/y;
// An identifier is a function name if directly followed by `(` (with optional
// template arguments), matching hljs's `function.dispatch` heuristic.
const FN_CALL = /(?:<[^<>]*>)?[ \t]*\(/y;

const C_KEYWORDS = "alignas alignof asm auto break case catch class concept const consteval constexpr constinit const_cast continue co_await co_return co_yield decltype default delete do dynamic_cast else enum explicit export extern for friend goto if inline mutable namespace new noexcept operator private protected public register reinterpret_cast requires return sizeof static static_assert static_cast struct switch template this thread_local throw try typedef typeid typename union using virtual volatile while restrict _Alignas _Alignof _Atomic _Generic _Noreturn _Static_assert _Thread_local";
const C_TYPES = "bool char char8_t char16_t char32_t double float int long short signed unsigned void wchar_t size_t ssize_t ptrdiff_t intptr_t uintptr_t int8_t int16_t int32_t int64_t uint8_t uint16_t uint32_t uint64_t FILE va_list";
const C_LITERALS = "true false nullptr NULL";

const JS_KEYWORDS = "as async await break case catch class const continue debugger default delete do else export extends finally for from function get if import in instanceof let new of return set static super switch this throw try typeof var void while with yield enum implements interface namespace declare type readonly public private protected abstract is keyof infer satisfies override";
const JS_LITERALS = "true false null undefined NaN Infinity";

const PY_KEYWORDS = "and as assert async await break class continue def del elif else except finally for from global if import in is lambda nonlocal not or pass raise return try while with yield match case";
const PY_LITERALS = "True False None NotImplemented Ellipsis self cls";

const SH_KEYWORDS = "if then elif else fi for while until do done case esac in function select time return break continue local export readonly declare set unset shift eval exec trap source";

const MAKE_KEYWORDS = "ifeq ifneq ifdef ifndef else endif define endef include sinclude vpath override export unexport private";

const SF_ESCAPE = 1, SF_MULTILINE = 2;

const STR_DQ = { o: '"', c: '"', f: SF_ESCAPE };
const STR_SQ = { o: "'", c: "'", f: SF_ESCAPE };
const STR_C_LONG = [
    { o: 'L"', c: '"', f: SF_ESCAPE }, { o: 'u"', c: '"', f: SF_ESCAPE },
    { o: 'U"', c: '"', f: SF_ESCAPE }, { o: 'u8"', c: '"', f: SF_ESCAPE },
    { o: "L'", c: "'", f: SF_ESCAPE }, { o: "u'", c: "'", f: SF_ESCAPE },
    { o: "U'", c: "'", f: SF_ESCAPE }, { o: "u8'", c: "'", f: SF_ESCAPE }
];
const STR_SQ_RAW = { o: "'", c: "'", f: 0 };
const STR_TMPL = { o: "`", c: "`", f: SF_ESCAPE + SF_MULTILINE };
// Triple-quoted strings must precede single-character delimiters.
const STR_PY = [
    { o: '"""', c: '"""', f: SF_ESCAPE + SF_MULTILINE },
    { o: "'''", c: "'''", f: SF_ESCAPE + SF_MULTILINE },
    STR_DQ, STR_SQ
];

// Character flags, used in `spec.table`
const CHF_ID = 1, CHF_STRING = 2, CHF_CPP_RAW = 4, CHF_META = 8,
    CHF_LC = 16, CHF_BC = 32, CHF_NUM = 64, CHF_NL = 128;

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
    const wordmap = new Map;
    add_words(wordmap, o.kw, WF_KEYWORD);
    add_words(wordmap, o.type, WF_TYPE);
    add_words(wordmap, o.lit, WF_LITERAL);
    add_words(wordmap, o.classkw, WF_CLASSKW);
    const s = {
        lc: o.lc || [],
        bc: o.bc || [],
        strings: o.strings || [],
        cpp_raw: !!o.cpp_raw,
        meta: o.meta || null,
        words: wordmap,
        num: o.num || NUM,
        id: o.id || ID,
        fn: !!o.fn,
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
    flag("\r", CHF_NL);
    flag("\n", CHF_NL);
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
            meta: /#\s*[A-Za-z_]+/y, kw: C_KEYWORDS,
            type: C_TYPES, lit: C_LITERALS, fn: true,
            classkw: "struct enum union"
        });
        SPECS[lang].flag("#", CHF_META);
    } else if (lang === "cpp") {
        SPECS[lang] = spec({
            lc: ["//"], bc: [["/*", "*/"]],
            strings: [STR_DQ, STR_SQ, ...STR_C_LONG],
            meta: /#\s*[A-Za-z_]+/y, kw: C_KEYWORDS,
            type: C_TYPES, lit: C_LITERALS, cpp_raw: true, fn: true,
            classkw: "class struct enum union"
        });
        SPECS[lang].flag("#", CHF_META);
    } else if (lang === "javascript" || lang === "typescript") {
        SPECS[lang] = spec({
            lc: ["//"], bc: [["/*", "*/"]], strings: [STR_DQ, STR_SQ, STR_TMPL],
            kw: JS_KEYWORDS, lit: JS_LITERALS
        });
    } else if (lang === "python") {
        SPECS[lang] = spec({
            lc: ["#"], strings: STR_PY,
            kw: PY_KEYWORDS, lit: PY_LITERALS
        });
    } else if (lang === "shell") {
        SPECS[lang] = spec({
            lc: ["#"], strings: [STR_DQ, STR_SQ_RAW], kw: SH_KEYWORDS
        });
    } else if (lang === "make") {
        SPECS[lang] = spec({
            lc: ["#"], strings: [STR_DQ, STR_SQ_RAW], kw: MAKE_KEYWORDS
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
function consume(text, pos, len, k, close, sf) {
    const closech = close.charCodeAt(0);
    while (pos < len) {
        const ch = text.charCodeAt(pos);
        if (ch === 92 /* \ */ && (sf & SF_ESCAPE)) {
            ++pos;
            const nch = pos < len ? text.charCodeAt(pos) : 10 /* \n */;
            if (nch == 10 /* \n */ || nch === 13 /* \r */) {
                return { end: pos, state: { k: k, c: close, f: sf } };
            }
            ++pos;
        } else if (ch === closech && text.startsWith(close, pos)) {
            return { end: pos + close.length, state: null };
        } else if (ch === 10 /* \n */ || ch === 13 /* \r */) {
            break;
        } else {
            ++pos;
        }
    }
    return { end: pos, state: sf & SF_MULTILINE ? { k: k, c: close, f: sf } : null };
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

/** @param {*} sp
 * @param {string} text one line, optionally ending in \n|\r|\r\n
 * @param {*} state lexer state from the previous line, or null
 * @return {?{value: string, top: *}} null if `lang` is unsupported */
function minihighlight_substring(sp, text, pos, len, state) {
    if (len > pos && text.charCodeAt(len - 1) === 10 /* \n */) {
        --len;
    }
    if (len > pos && text.charCodeAt(len - 1) === 13 /* \r */) {
        --len;
    }
    // `last_idf` holds the word flags of the most recent identifier token, so an
    // identifier can be classified from the one before it (a name after a type
    // is a function definition, a name after `class`/`struct`/... is a type
    // name) without scanning backward. Only `emit` clears it, so it carries
    // across unflagged characters (whitespace, `*`, `&`, other operators); it
    // starts fresh each call, i.e. per line in the diff path.
    let out = "", last = pos, sol = true /* start of line */, last_idf = 0;

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
        last_idf = 0;
    }
    function done() {
        flush();
        return { value: out + (len !== text.length ? "\n" : ""), top: state };
    }

    const table = sp.table;
    while (true) {
        let ch, f;
        // continue a multi-line construct opened on a previous line
        if (state) {
            // if we get here, `last === pos` by definition
            if (pos === len) {
                break;
            }
            if (pos < len
                && ((ch = text.charCodeAt(pos)) === 10 /* \n */ || ch === 13 /* \r */)) {
                out += "\n";
                ++pos;
                if (ch === 13 && pos < len && text.charCodeAt(pos) === 10) {
                    ++pos;
                }
                last = pos;
            }
            const r = consume(text, pos, len, state.k, state.c, state.f);
            if (r.end > pos) {
                emit(state.k === "bc" ? "comment" : "string", r.end);
            }
            state = r.state;
            continue;
        }
        // whitespace
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
                const r = consume(text, pos + bc[0].length, len, "bc", bc[1], SF_MULTILINE);
                emit("comment", r.end);
                state = r.state;
                continue;
            }
        }
        // C++ raw string
        if (f & CHF_CPP_RAW) {
            CPP_RAW.lastIndex = pos;
            const rm = CPP_RAW.exec(text);
            if (rm) {
                const close = ")" + rm[1] + '"',
                    r = consume(text, pos + rm[0].length, len, "s", close, SF_MULTILINE);
                emit("string", r.end);
                state = r.state;
                continue;
            }
        }
        // strings (triple/template before single-char delimiters)
        if (f & CHF_STRING) {
            const sd = string_at(sp.strings, text, pos);
            if (sd) {
                const r = consume(text, pos + sd.o.length, len, "s", sd.c, sd.f);
                emit("string", r.end);
                state = r.state;
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
                const w = idm[0], wf = sp.words.get(w) || 0;
                let cls = null;
                if (wf & WF_KEYWORD) {
                    cls = "keyword";
                } else if (wf & WF_TYPE) {
                    cls = "type";
                } else if (wf & WF_LITERAL) {
                    cls = "literal";
                } else if (last_idf & WF_CLASSKW) {
                    cls = "title class_";
                } else if (sp.fn) {
                    // A name followed by `(` is a function. We don't try to
                    // distinguish definitions (hljs-title) from calls: our type
                    // detection is too limited (user types aren't known), so the
                    // split would color methods inconsistently within a class.
                    FN_CALL.lastIndex = pos + w.length;
                    if (FN_CALL.test(text)) {
                        cls = "built_in";
                    }
                }
                if (cls) {
                    emit(cls, pos + w.length);
                } else {
                    pos += w.length;
                }
                last_idf = wf;
                continue;
            }
        }
        // newline
        if (f & CHF_NL) {
            if (ch === 13 /* \r */) {
                flush();
                last = pos + 1;
                if (last < len && text.charCodeAt(last) === 10 /* \n */) {
                    ++pos;
                } else {
                    out += "\n";
                }
            }
            sol = true;
        }
        // skip character (most previous cases advanced `pos` and `continue`d)
        ++pos;
    }

    return done();
}

/** @param {string} text
 * @param {string} lang
 * @param {*} state lexer state from the previous line, or null
 * @return {?{value: string, top: *}} null if `lang` is unsupported */
export function minihighlight(text, lang, state) {
    const sp = create_spec(canon(lang));
    return sp ? minihighlight_substring(sp, text, 0, text.length, state) : null;
}
