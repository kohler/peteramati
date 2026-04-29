// markdown-katex.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2026 Eddie Kohler
// See LICENSE for open-source distribution terms

export function markdownit_katex(md) {
    md.renderer.rules.math_inline = (tokens, idx) => {
        return window.katex.renderToString(tokens[idx].content, { throwOnError: false });
    };
    md.renderer.rules.math_block = (tokens, idx) => {
        return `<p class="katex-block">${window.katex.renderToString(tokens[idx].content, { displayMode: true, throwOnError: false })}</p>\n`;
    };
}
