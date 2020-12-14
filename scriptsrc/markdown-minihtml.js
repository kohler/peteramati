// markdown-minihtml.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms


function minihtml_inline(state, silent) {
    var ch, match, match2, token,
        pos = state.pos, max = state.posMax;

    // Check start
    max = state.posMax;
    if (state.src.charCodeAt(pos) !== 0x3C/* < */ ||
        pos + 2 >= max) {
        return false;
    }

    // Quick fail on second char
    ch = state.src.charCodeAt(pos + 1) | 0x20;
    if (ch !== 0x62 && ch !== 0x2f && ch !== 0x73) {
        return false;
    }

    match = state.src.slice(pos).match(/<br\s*\/?>(?:\s*<\/br\s*>)?|<\/br\s*>|<su[bp]\s*>\s*([^<]+?)\s*<\/\s*su/i);
    if (!match) {
        return false;
    }
    var mlen = match[0].length;

    if (ch === 0x62 || (ch === 0x2f && match[0].startsWith("</br"))) {
        if (!silent) {
            state.push('hardbreak', 'br', 0);
        }
    } else {
        if (pos + mlen + 1 >= max
            || (state.src.charCodeAt(pos+mlen)|0x20) !== (state.src.charCodeAt(pos+3)|0x20)
            || !(match2 = state.src.slice(pos+mlen).match(/[bp]\s*>/i))) {
            return false;
        } else {
            mlen += match2[0].length;
            if (!silent) {
                var tag = state.src.slice(pos+1,pos+4).toLowerCase();
                state.push(tag + "_open", tag, 1);
                token = state.push('text', '', 0);
                token.content = match[1];
                state.push(tag + "_close", tag, -1);
            }
        }
    }
    state.pos += mlen;
    return true;
}

export function markdownit_minihtml(md) {
    md.inline.ruler.after("autolink", "minihtml_inline", minihtml_inline);
}
