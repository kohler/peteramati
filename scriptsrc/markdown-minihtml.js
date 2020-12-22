// markdown-minihtml.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms


function skip_space(str, pos, len) {
    let ch;
    while (pos !== len && ((ch = str.charCodeAt(pos)) === 0x20 || ch === 0x09)) {
        ++pos;
    }
    return pos;
}

function skip_name(str, pos, len, pos0) {
    while (pos !== len) {
        const ch = str.charCodeAt(pos), lch = ch | 0x20;
        if (lch >= 0x61 && lch <= 0x7A) {
            // always ok
        } else if (pos0 !== pos || (ch !== 0x2D && (ch < 0x30 || ch > 0x39))) {
            break;
        }
        ++pos;
    }
    return pos;
}

function parse_tag(str, pos, len) {
    let ch;
    const result = {tag: null, ok: false, open: null, close: null, attr: {}, pos: pos};
    if (str.charCodeAt(pos) !== 0x3C) {
        return result;
    }
    pos = skip_space(str, pos + 1, len);
    if (pos !== len && str.charCodeAt(pos) === 0x2F) {
        result.open = false;
        result.close = true;
        pos = skip_space(str, pos + 1, len);
    }
    const tag0 = pos;
    pos = skip_name(str, pos, len, pos);
    if (pos === tag0) {
        return result;
    } else if (result.open === null) {
        result.open = true;
    }
    result.tag = str.substring(tag0, pos).toLowerCase();
    while (true) {
        pos = skip_space(str, pos, len);
        ch = pos !== len ? str.charCodeAt(pos) : -1;
        if (ch === 0x3E) {
            result.pos = pos + 1;
            result.ok = true;
            return result;
        } else if (ch === 0x2F) {
            result.close = true;
            ++pos;
        } else if (result.close) {
            return result;
        } else if ((ch | 0x20) >= 0x61 && (ch | 0x20) <= 0x7A) {
            const name0 = pos, name1 = skip_name(str, pos, len, pos),
                attrname = str.substring(name0, name1).toLowerCase();
            pos = skip_space(str, name1, len);
            if (pos !== len && str.charCodeAt(pos) === 0x3D) {
                pos = skip_space(str, pos + 1, len);
                ch = pos !== len ? str.charCodeAt(pos) : -1;
                if (ch === 0x22 || ch === 0x27) {
                    const nextquote = str.indexOf(str.charAt(pos), pos + 1),
                        nextnl = str.indexOf("\n", pos + 1);
                    if (nextquote === -1 || (nextnl > 0 && nextquote > nextnl)) {
                        return result;
                    }
                    result.attr[attrname] = str.substring(pos + 1, nextquote);
                    pos = nextquote + 1;
                } else if (ch === 0x2D || (ch >= 0x30 && ch <= 0x39) || ((ch | 0x20) >= 0x61 && (ch | 0x20) <= 0x7A)) {
                    const val0 = pos;
                    pos = skip_name(str, pos + 1, len, pos);
                    result.attr[attrname] = str.substring(val0, pos);
                } else {
                    return result;
                }
            } else {
                result.attr[attrname] = true;
            }
        } else {
            return result;
        }
    }
}

function minihtml_inline(state, silent) {
    let src = state.src, pos = state.pos, max = state.posMax;

    // Check start
    if (src.charCodeAt(pos) !== 0x3C/* < */ ||
        pos + 2 >= max) {
        return false;
    }

    // Quick fail on second char
    let ch = src.charCodeAt(pos + 1) | 0x20;
    if (ch !== 0x2f && ch !== 0x62 && ch !== 0x69 && ch !== 0x73) {
        return false;
    }

    const tag = parse_tag(src, pos, max);
    if (!tag.ok) {
        return false;
    } else if (tag.tag === "br") {
        if (tag.open) {
            const tag1 = parse_tag(src, skip_space(src, tag.pos, max), max);
            if (tag1.ok && tag1.tag === "br" && !tag1.open) {
                tag.pos = tag1.pos;
            }
        }
        if (!silent) {
            state.push('hardbreak', 'br', 0);
        }
    } else if ((tag.tag === "sub" || tag.tag === "sup") && !tag.close) {
        const lt = src.indexOf("<", tag.pos), nl = src.indexOf("\n", tag.pos);
        let tag1 = lt >= tag.pos && (nl < 0 || nl > lt) ? parse_tag(src, lt, max) : null;
        if (tag1 && tag1.ok && !tag1.open && tag1.tag === tag.tag) {
            if (!silent) {
                state.push(tag.tag + "_open", tag.tag, 1);
                let token = state.push("text", "", 0);
                token.content = src.substring(tag.pos, lt);
                state.push(tag.tag + "_close", tag.tag, -1);
            }
            tag.pos = tag1.pos;
        } else {
            return false;
        }
    } else if (tag.tag === "img" && tag.attr.src) {
        let token = state.push("image", "img", 0);
        token.attrs = [["src", tag.attr.src], ["alt", ""]];
        if (tag.attr.title) {
            token.attrs.push(["title", tag.attr.title]);
        }
        token.children = [];
        token.content = tag.attr.alt || "";
        if (token.content !== "") {
            state.md.inline.parse(token.content, state.md, state.env, token.children);
        }
    } else {
        return false;
    }
    state.pos = tag.pos;
    return true;
}

export function markdownit_minihtml(md) {
    md.inline.ruler.after("autolink", "minihtml_inline", minihtml_inline);
}
