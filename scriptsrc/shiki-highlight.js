// shiki-highlight.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, removeClass } from "./ui.js";

// Public client-side syntax-highlighting API backed by Shiki. The heavy Shiki
// runtime and grammars are code-split into separate chunks (`shiki.min.js`,
// `shiki-fallback.min.js`) loaded on demand:
//   * the PRIMARY highlighter uses the JavaScript "raw" engine with precompiled
//     grammars (no WASM, no oniguruma-to-es) for a curated set of fast-path
//     languages;
//   * the FALLBACK highlighter uses the JavaScript regex engine with normal
//     grammars for languages that are broken when precompiled (python, html,
//     yaml, perl) or otherwise outside the primary set.
// Once a highlighter is loaded its `codeToTokens` is synchronous, so callers
// guard with `supports_language()` and, when false, await `ensure_language()`
// and retry.

// Languages bundled into the primary (raw engine, precompiled) highlighter.
// Keep in sync with the static imports in shiki-primary.js.
const PRIMARY_LANGS = [
    "c", "cpp", "javascript", "typescript", "json", "css", "markdown",
    "java", "go", "rust", "sql", "shellscript", "diff"
];
const PRIMARY_SET = new Set(PRIMARY_LANGS);

// Languages bundled into the lazy fallback (regex engine) highlighter.
// Keep in sync with the static imports in shiki-fallback.js.
const FALLBACK_LANGS = [
    "python", "html", "xml", "yaml", "perl", "ruby", "php", "lua",
    "haskell", "ocaml", "scheme", "common-lisp", "asm", "make", "makefile",
    "latex", "r", "matlab", "swift", "kotlin", "scala", "csharp",
    "clojure", "dockerfile", "ini", "toml", "scss", "less", "llvm"
];
const FALLBACK_SET = new Set(FALLBACK_LANGS);

const THEME = "github-light";

const ALIASES = {
    "c++": "cpp", "h": "c", "hpp": "cpp", "hh": "cpp", "cc": "cpp", "cxx": "cpp",
    "js": "javascript", "jsx": "javascript", "ts": "typescript", "tsx": "typescript",
    "py": "python", "py3": "python", "sh": "shellscript", "bash": "shellscript",
    "shell": "shellscript", "zsh": "shellscript", "yml": "yaml", "md": "markdown",
    "rs": "rust", "golang": "go", "cs": "csharp", "lisp": "common-lisp",
    "tex": "latex", "docker": "dockerfile"
};

/** @param {?string} lang
 * @return {?string} */
function canon(lang) {
    if (!lang) {
        return null;
    }
    const l = lang.toLowerCase();
    return Object.prototype.hasOwnProperty.call(ALIASES, l) ? ALIASES[l] : l;
}

// `langHL` maps a loaded (canonical or alias) language name to the highlighter
// instance that can render it synchronously.
const langHL = new Map();
let primary = null, primaryPromise = null;
let fallbackPromise = null;

function register(hl) {
    // First loader wins, so the primary (raw-engine) highlighter stays
    // authoritative for languages it shares with the fallback.
    for (const name of hl.getLoadedLanguages()) {
        if (!langHL.has(name)) {
            langHL.set(name, hl);
        }
    }
}

function loadPrimary() {
    if (!primaryPromise) {
        primaryPromise = import(/* webpackChunkName: "shiki" */ "./shiki-primary.js")
            .then(m => m.createPrimary())
            .then(hl => {
                primary = hl;
                register(hl);
                return hl;
            });
    }
    return primaryPromise;
}

function loadFallback() {
    if (!fallbackPromise) {
        fallbackPromise = import(/* webpackChunkName: "shiki-fallback" */ "./shiki-fallback.js")
            .then(m => m.createFallback())
            .then(hl => {
                register(hl);
                return hl;
            });
    }
    return fallbackPromise;
}

/** Resolves once the primary highlighter is ready.
 * @return {Promise} */
export function getHighlighter() {
    return loadPrimary();
}

/** @return {boolean} true once the primary highlighter is usable synchronously */
export function is_ready() {
    return primary !== null;
}

/** @param {?string} lang
 * @return {boolean} true if `lang` can be highlighted synchronously right now */
export function supports_language(lang) {
    return langHL.has(canon(lang));
}

/** @param {?string} lang
 * @return {boolean} true if `lang` is highlightable once its chunk loads
 * (i.e. `ensure_language(lang)` will make `supports_language(lang)` true) */
export function can_support(lang) {
    const l = canon(lang);
    return !!l && (langHL.has(l) || PRIMARY_SET.has(l) || FALLBACK_SET.has(l));
}

/** Ensures the grammar for `lang` is loaded (loading the primary and/or
 * fallback chunk as needed). Resolves once `supports_language(lang)` is true,
 * or immediately if `lang` is not highlightable at all.
 * @param {?string} lang
 * @return {Promise} */
export function ensure_language(lang) {
    const l = canon(lang);
    if (!l || langHL.has(l)) {
        return Promise.resolve();
    } else if (PRIMARY_SET.has(l)) {
        return loadPrimary();
    } else if (FALLBACK_SET.has(l)) {
        return loadFallback();
    } else {
        return Promise.resolve();
    }
}

const AMPRE = /[&<>]/g;
function esc(s) {
    return s.replace(AMPRE, c => (c === "&" ? "&amp;" : c === "<" ? "&lt;" : "&gt;"));
}

function token_style(tok) {
    let s = tok.color ? "color:" + tok.color : "";
    const fs = tok.fontStyle;
    if (fs > 0) {
        if (fs & 1) {
            s += (s ? ";" : "") + "font-style:italic";
        }
        if (fs & 2) {
            s += (s ? ";" : "") + "font-weight:bold";
        }
        if (fs & 4) {
            s += (s ? ";" : "") + "text-decoration:underline";
        }
    }
    return s;
}

function token_is_comment(tok) {
    return tok.explanation
        ? tok.explanation.every(e => e.scopes.some(s => s.scopeName.startsWith("comment")))
        : false;
}

function line_html(toks) {
    let h = "";
    for (const t of toks) {
        const st = token_style(t), cm = token_is_comment(t);
        if (st || cm) {
            h += "<span"
                + (cm ? ' class="pa-hlc"' : "")
                + (st ? ' style="' + st + '"' : "")
                + ">" + esc(t.content) + "</span>";
        } else {
            h += esc(t.content);
        }
    }
    return h;
}

// Classify a line for diff styling: "blank" (only whitespace), "comment" (all
// non-whitespace tokens are comments), or "code".
function line_kind(toks) {
    let nonws = false, comment = true;
    for (const t of toks) {
        if (!/\S/.test(t.content)) {
            continue;
        }
        nonws = true;
        if (!token_is_comment(t)) {
            comment = false;
        }
    }
    if (!nonws) {
        return "blank";
    }
    return comment ? "comment" : "code";
}

/** Highlight a single line, threading grammar state. Synchronous; the caller
 * must have ensured `supports_language(lang)`.
 * @param {string} lang
 * @param {string} code one line of source (a trailing newline is preserved)
 * @param {*} state grammar state from the previous line, or null
 * @return {{html: string, state: *, kind: string}} */
export function highlight_line(lang, code, state) {
    const l = canon(lang), hl = langHL.get(l);
    const nl = code.endsWith("\n"), body = nl ? code.substring(0, code.length - 1) : code;
    let toks, gstate;
    try {
        const r = hl.codeToTokens(body, {
            lang: l, theme: THEME, includeExplanation: true,
            grammarState: state || undefined
        });
        toks = r.tokens[0] || [];
        gstate = r.grammarState;
    } catch {
        // e.g. unsupported RegExp features on an old browser: fall back to plain
        return {
            html: esc(body) + (nl ? "\n" : ""), state: null,
            kind: /\S/.test(body) ? "code" : "blank"
        };
    }
    let html = line_html(toks);
    if (nl) {
        html += "\n";
    }
    return { html: html, state: gstate, kind: line_kind(toks) };
}

/** Highlight a whole block at once (correct multi-line constructs).
 * Synchronous; the caller must have ensured `supports_language(lang)`.
 * @param {string} lang
 * @param {string} code
 * @return {{lines: string[]}} per-line highlighted HTML */
export function highlight_block(lang, code) {
    const l = canon(lang), hl = langHL.get(l);
    try {
        const r = hl.codeToTokens(code, { lang: l, theme: THEME });
        return { lines: r.tokens.map(line_html) };
    } catch {
        return { lines: code.split("\n").map(esc) };
    }
}

// Markdown code fences are rendered unhighlighted and tagged
// `code.need-highlight[data-pa-text][data-language]`. The sweep below upgrades
// them in place; consecutive `<pre class="partial">` lines of one fence share
// grammar state.

/** Highlight all tagged fence lines under `root` (synchronous; assumes the
 * needed grammars are already loaded). The marker is always cleared so a line
 * is processed at most once, even for unsupported languages.
 * @param {Element} root */
function highlight_fences(root) {
    const els = root.querySelectorAll("code.need-highlight[data-pa-text]");
    let state = null;
    for (const ce of els) {
        const lang = ce.getAttribute("data-language");
        if (supports_language(lang)) {
            const r = highlight_line(lang, ce.getAttribute("data-pa-text"), state);
            ce.innerHTML = r.html;
            const pre = ce.parentElement;
            state = pre && hasClass(pre, "partial") ? r.state : null;
        } else {
            state = null;
        }
        removeClass(ce, "need-highlight");
    }
}

/** Load the grammars needed by the tagged fence lines under `root`, then
 * highlight them.
 * @param {Element} root */
export function highlight_fences_when_ready(root) {
    const els = root.querySelectorAll("code.need-highlight[data-pa-text]");
    if (!els.length) {
        return;
    }
    const langs = new Set();
    for (const ce of els) {
        langs.add(ce.getAttribute("data-language"));
    }
    Promise.all(Array.from(langs, ensure_language)).then(() => highlight_fences(root));
}
