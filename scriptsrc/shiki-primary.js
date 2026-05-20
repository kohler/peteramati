// shiki-primary.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

// Primary Shiki highlighter: JavaScript "raw" engine + precompiled grammars.
// No WASM and no oniguruma-to-es, so this chunk stays small. The language set
// here must match PRIMARY_LANGS in shiki-highlight.js.

import { createHighlighterCore } from "shiki/core";
import { createJavaScriptRawEngine } from "shiki/engine/javascript";
import themeGithubLight from "@shikijs/themes/github-light";
import langC from "@shikijs/langs-precompiled/c";
import langCpp from "@shikijs/langs-precompiled/cpp";
import langJavascript from "@shikijs/langs-precompiled/javascript";
import langTypescript from "@shikijs/langs-precompiled/typescript";
import langJson from "@shikijs/langs-precompiled/json";
import langCss from "@shikijs/langs-precompiled/css";
import langMarkdown from "@shikijs/langs-precompiled/markdown";
import langJava from "@shikijs/langs-precompiled/java";
import langGo from "@shikijs/langs-precompiled/go";
import langRust from "@shikijs/langs-precompiled/rust";
import langSql from "@shikijs/langs-precompiled/sql";
import langShellscript from "@shikijs/langs-precompiled/shellscript";
import langDiff from "@shikijs/langs-precompiled/diff";

/** @return {Promise} */
export function createPrimary() {
    return createHighlighterCore({
        langs: [
            langC, langCpp, langJavascript, langTypescript, langJson, langCss,
            langMarkdown, langJava, langGo, langRust, langSql, langShellscript,
            langDiff
        ],
        themes: [themeGithubLight],
        engine: createJavaScriptRawEngine()
    });
}
