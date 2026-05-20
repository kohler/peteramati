// shiki-fallback.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

// Fallback Shiki highlighter: JavaScript regex engine + normal grammars,
// loaded lazily only when a non-primary language appears. Covers languages
// that are broken when precompiled (python, html, yaml, perl) plus a curated
// set of additional languages. This chunk pulls in oniguruma-to-es, which is
// why it is kept out of the primary chunk. The language set here must match
// FALLBACK_LANGS in shiki-highlight.js.

import { createHighlighterCore } from "shiki/core";
import { createJavaScriptRegexEngine } from "shiki/engine/javascript";
import themeGithubLight from "@shikijs/themes/github-light";
import langPython from "@shikijs/langs/python";
import langHtml from "@shikijs/langs/html";
import langXml from "@shikijs/langs/xml";
import langYaml from "@shikijs/langs/yaml";
import langPerl from "@shikijs/langs/perl";
import langRuby from "@shikijs/langs/ruby";
import langPhp from "@shikijs/langs/php";
import langLua from "@shikijs/langs/lua";
import langHaskell from "@shikijs/langs/haskell";
import langOcaml from "@shikijs/langs/ocaml";
import langScheme from "@shikijs/langs/scheme";
import langCommonLisp from "@shikijs/langs/common-lisp";
import langAsm from "@shikijs/langs/asm";
import langMake from "@shikijs/langs/make";
import langMakefile from "@shikijs/langs/makefile";
import langLatex from "@shikijs/langs/latex";
import langR from "@shikijs/langs/r";
import langMatlab from "@shikijs/langs/matlab";
import langSwift from "@shikijs/langs/swift";
import langKotlin from "@shikijs/langs/kotlin";
import langScala from "@shikijs/langs/scala";
import langCsharp from "@shikijs/langs/csharp";
import langClojure from "@shikijs/langs/clojure";
import langDockerfile from "@shikijs/langs/dockerfile";
import langIni from "@shikijs/langs/ini";
import langToml from "@shikijs/langs/toml";
import langScss from "@shikijs/langs/scss";
import langLess from "@shikijs/langs/less";
import langLlvm from "@shikijs/langs/llvm";

/** @return {Promise} */
export function createFallback() {
    return createHighlighterCore({
        langs: [
            langPython, langHtml, langXml, langYaml, langPerl, langRuby, langPhp,
            langLua, langHaskell, langOcaml, langScheme, langCommonLisp, langAsm,
            langMake, langMakefile, langLatex, langR, langMatlab, langSwift,
            langKotlin, langScala, langCsharp, langClojure, langDockerfile,
            langIni, langToml, langScss, langLess, langLlvm
        ],
        themes: [themeGithubLight],
        engine: createJavaScriptRegexEngine()
    });
}
