// markdown-attributes.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

const attr_re = /\{(?:(?:\.[^#.=\s}]+(?=[#.\s}])|\#[^#.=\s}]+(?=[#.\s}])|[^.#="}]+="[^"]*"(?=[\s}]))\s*)+\}[ \t]*(?:\n|$)/y;

function find_opener(state, idx) {
    let nest = state.tokens[idx].nesting || 0;
    for (--idx; nest !== 0 && idx >= 0; --idx) {
        nest += state.tokens[idx].nesting || 0;
        if (nest === 0) {
            return state.tokens[idx];
        }
    }
    return null;
}

function attribute_block(state, startLine, endLine, silent) {
    if (state.sCount[startLine] - state.blkIndent >= 4) {
        return false;
    }
    let pos = state.bMarks[startLine] + state.tShift[startLine];
    if (state.src.charCodeAt(pos) !== 0x7b /* { */) {
        return false;
    }
    attr_re.lastIndex = pos;
    let m = attr_re.exec(state.src);
    if (!m) {
        return false;
    }
    if (silent) {
        return true;
    }
    const last_token = state.tokens[state.tokens.length - 1],
        t = last_token.type;
    let dest = null;
    if (t === "paragraph_close"
        || t === "blockquote_close"
        || t === "ordered_list_close"
        || t === "bullet_list_close"
        || t === "table_close") {
        dest = find_opener(state, state.tokens.length - 1);
    } else if (t === "fence") {
        dest = last_token;
    }
    if (!dest) {
        return false;
    }
    const text = m[0];
    for (let mm of text.matchAll(/\.([^#.=\s}]+)|#([^#.=\s}]+)|([^.#="{}]+)="([^"]*)"/g)) {
        if (mm[1]) {
            let c = dest.attrGet("class") || "";
            dest.attrSet("class", c ? c + " " + mm[1] : mm[1]);
        } else if (mm[2]) {
            dest.attrSet("id", mm[2]);
        } else {
            dest.attrSet(mm[3], mm[4] /* XXX quoting */);
        }

    }
    state.line = startLine + 1;
    return true;
}

export function markdownit_attributes(md) {
    md.block.ruler.after("lheading", "mdattribute", attribute_block, {alt: ["list", "blockquote", "paragraph"]});
}
