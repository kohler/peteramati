// render-terminal.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { sprintf } from "./utils.js";
import { hasClass } from "./ui.js";
import { render_text } from "./render.js";
import { Filediff } from "./diff.js";


const ansistyle_map = {
    "0": false, "1": {b: true}, "2": {f: true}, "3": {i: true},
    "4": {u: true}, "5": {bl: true}, "7": {rv: true}, "8": {x: true},
    "9": {s: true}, "21": {du: true}, "22": {b: false, f: false},
    "23": {i: false}, "24": {u: false}, "25": {bl: false}, "27": {rv: false},
    "28": {x: false}, "29": {s: false}, "30": {fg: 0}, "31": {fg: 1},
    "32": {fg: 2}, "33": {fg: 3}, "34": {fg: 4}, "35": {fg: 5},
    "36": {fg: 6}, "37": {fg: 7}, "38": "fg", "39": {fg: false},
    "40": {bg: 0}, "41": {bg: 1}, "42": {bg: 2}, "43": {bg: 3},
    "44": {bg: 4}, "45": {bg: 5}, "46": {bg: 6}, "47": {bg: 7},
    "48": "bg", "49": {bg: false}, "90": {fg: 8}, "91": {fg: 9},
    "92": {fg: 10}, "93": {fg: 11}, "94": {fg: 12}, "95": {fg: 13},
    "96": {fg: 14}, "97": {fg: 15},
    "100": {bg: 8}, "101": {bg: 9}, "102": {bg: 10}, "103": {bg: 11},
    "104": {bg: 12}, "105": {bg: 13}, "106": {bg: 14}, "107": {bg: 15}
}, ansistyle_keymap = {
    "b": 1, "f": 2, "i": 3, "u": 4, "bl": 5, "rv": 7, "x": 8, "s": 9,
    "du": 21
};

function ansistyle_parse(dst, style) {
    if (arguments.length === 1) {
        style = dst;
        dst = null;
    }
    if (!style || style === "\x1b[m" || style === "\x1b[0m") {
        return null;
    }
    let a;
    if (style.charAt(0) === "\x1b") {
        a = style.substring(2, style.length - 1).split(";");
    } else {
        a = style.split(";");
    }
    for (let i = 0; i < a.length; ++i) {
        const cmp = ansistyle_map[parseInt(a[i])];
        if (cmp === false) {
            dst = null;
        } else if (!cmp) {
            /* do nothing */
        } else if (typeof cmp === "object") {
            for (let j in cmp) {
                if (cmp[j] !== false) {
                    dst = dst || {};
                    dst[j] = cmp[j];
                } else if (dst) {
                    delete dst[j];
                }
            }
        } else if (cmp === "fg" || cmp === "bg") {
            let r, g, b;
            dst = dst || {};
            if (i + 4 < a.length && parseInt(a[i+1]) === 2) {
                r = parseInt(a[i+2]);
                g = parseInt(a[i+3]);
                b = parseInt(a[i+4]);
                if (r <= 255 && g <= 255 && b <= 255) {
                    dst[cmp] = [r, g, b];
                }
            } else if (i + 2 < a.length && parseInt(a[i+1]) === 5) {
                const c = parseInt(a[i+1]);
                if (c <= 15) {
                    dst[cmp] = c;
                } else if (c <= 0xe7) {
                    b = (c - 16) % 6;
                    g = ((c - 16 - b) / 6) % 6;
                    r = (c - 16 - b - 6 * g) / 36;
                    dst[cmp] = [r * 51, g * 51, b * 51];
                } else if (c <= 255) {
                    b = Math.round((c - 0xe8) * 255 / 23);
                    dst[cmp] = [b, b, b];
                }
            }
        }
    }
    return dst && $.isEmptyObject(dst) ? null : dst;
}

function ansistyle_unparse(dst) {
    if (!dst) {
        return "\x1b[m";
    }
    const a = [];
    for (let key in ansistyle_keymap) {
        if (dst[key])
            a.push(ansistyle_keymap[key]);
    }
    if (dst.fg) {
        if (typeof dst.fg === "number") {
            a.push(dst.fg < 8 ? 30 + dst.fg : 90 + dst.fg - 8);
        } else {
            a.push(38, 2, dst.fg[0], dst.fg[1], dst.fg[2]);
        }
    }
    if (dst.bg) {
        if (typeof dst.bg === "number") {
            a.push(dst.bg < 8 ? 40 + dst.bg : 100 + dst.bg - 8);
        } else {
            a.push(48, 2, dst.bg[0], dst.bg[1], dst.bg[2]);
        }
    }
    return "\x1b[" + a.join(";") + "m";
}

function ansistyle_combine(a1, a2) {
    if (/^\x1b\[[\d;]*m$/.test(a2)) {
        return ansistyle_unparse(ansistyle_parse(ansistyle_parse(null, a1), a2));
    } else {
        return a1;
    }
}

function ansistyle_hexcolor(r, g, b) {
    return "#".concat(r < 16 ? 0 : "", r.toString(16), g < 16 ? 0 : "", g.toString(16), b < 16 ? 0 : "", b.toString(16));
}

function ansistyle_render(text, style) {
    if (typeof text === "string") {
        text = document.createTextNode(text);
    } else if (text instanceof jQuery) {
        text = text[0];
    }
    if (!style || style === "\x1b[m"
        || (typeof style === "string" && !(style = ansistyle_parse(style)))) {
        return text;
    }
    const node = document.createElement("span"), cl = [];
    for (let key in ansistyle_keymap) {
        if (style[key])
            cl.push("ansi" + key);
    }
    if (style.fg) {
        if (typeof style.fg === "number") {
            cl.push("ansifg" + style.fg);
        } else {
            node.styles.foregroundColor = ansistyle_hexcolor(style.fg[0], style.fg[1], style.fg[2]);
        }
    }
    if (style.bg) {
        if (typeof style.bg === "number") {
            cl.push("ansibg" + style.bg);
        } else {
            node.styles.backgroundColor = ansistyle_hexcolor(style.bg[0], style.bg[1], style.bg[2]);
        }
    }
    if (cl.length) {
        node.className = cl.join(" ");
    }
    node.appendChild(text);
    return node;
}

export function render_terminal(container, string, options) {
    var return_html = false;
    if (typeof container === "string") {
        options = string;
        string = container;
        container = document.createElement("div");
        return_html = true;
    }

    if (options && options.clear) {
        container.removeAttribute("data-pa-terminal-style");
        container.removeAttribute("data-pa-outputpart");
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
    }

    var styles = container.getAttribute("data-pa-terminal-style"),
        fragment = null;

    function addlinepart(node, text) {
        node.appendChild(ansistyle_render(text, styles));
    }

    function addfragment(node) {
        if (!fragment) {
            fragment = document.createDocumentFragment();
        }
        fragment.appendChild(node);
    }

    function clean_cr(line) {
        var lineend = /\n$/.test(line);
        if (lineend && line.indexOf("\r") === line.length - 1)
            return line.substring(0, line.length - 2) + "\n";
        var curstyle = styles || "\x1b[m",
            parts = (lineend ? line.substr(0, line.length - 1) : line).split(/\r/),
            r = [];
        for (let partno = 0; partno < parts.length; ++partno) {
            var g = [], glen = 0, clearafter = null;
            var lsplit = parts[partno].split(/(\x1b\[[\d;]*m|\x1b\[0?K)/);
            for (var j = 0; j < lsplit.length; j += 2) {
                if (lsplit[j] !== "") {
                    g.push(curstyle, lsplit[j]);
                    glen += lsplit[j].length;
                }
                if (j + 1 < lsplit.length) {
                    if (lsplit[j + 1].endsWith("K")) {
                        clearafter = glen;
                    } else {
                        curstyle = ansistyle_combine(curstyle, lsplit[j + 1]);
                    }
                }
            }
            // glen: number of characters to overwrite
            var rpos = 0;
            while (rpos < r.length && glen >= r[rpos + 1].length) {
                glen -= r[rpos + 1].length;
                rpos += 2;
            }
            while (rpos < r.length && glen < r[rpos + 1].length && clearafter === null) {
                g.push(r[rpos], r[rpos + 1].substr(glen));
                glen = 0;
                rpos += 2;
            }
            r = g;
        }
        r.push(curstyle);
        lineend && r.push("\n");
        return r.join("");
    }

    function add_file_link(node, prefix, file, line, link) {
        let m;
        while ((m = file.match(/^(\x1b\[[\d;]*m|\x1b\[\d*K)([^]*)$/))) {
            styles = ansistyle_combine(styles, m[1]);
            file = m[2];
        }
        let filematch = Filediff.by_file(file);
        if (!filematch && options && options.directory) {
            file = options.directory + file;
            filematch = Filediff.by_file(file);
        }
        if (filematch) {
            if (prefix.length) {
                addlinepart(node, prefix);
            }
            var anchor = filematch.lineid_anchor("b" + line);
            var a = $("<a href=\"#".concat(anchor, "\" class=\"u pa-goto\"></a>"));
            a.text(link.substring(prefix.length).replace(/(?:\x1b\[[\d;]*m|\x1b\[\d*K)/g, ""));
            addlinepart(node, a);
            return true;
        }
        return false;
    }

    function render_line(line, node) {
        var m, isnew = !node, displaylen = 0;
        if (isnew)
            node = document.createElement("span");

        if (/\r/.test(line))
            line = clean_cr(line);

        while ((m = line.match(/^(\x1b\[[\d;]*m|\x1b\[\d*K)([^]*)$/))) {
            styles = ansistyle_combine(styles, m[1]);
            line = m[2];
        }

        if (((m = line.match(/^([ \t]*)([^:\s]+):(\d+)(?=:)/))
             || (m = line.match(/^([ \t]*)file \"(.*?)\", line (\d+)/i)))
            && add_file_link(node, m[1], m[2], m[3], m[0])) {
            displaylen = m[0].length;
            line = line.substr(displaylen);
        }

        var render;
        while (line !== "") {
            render = line;
            if ((m = line.match(/^(.*?)(\x1b\[[\d;]*m|\x1b\[\d*K)([^]*)$/))) {
                if (m[1] === "") {
                    styles = ansistyle_combine(styles, m[2]);
                    line = m[3];
                    continue;
                }
                render = m[1];
            }
            if (displaylen + render.length > 133
                || (displaylen + render.length == 133 && render.charAt(132) !== "\n")) {
                render = render.substr(0, 132 - displaylen);
                addlinepart(node, render);
                node.className = "pa-rl-continues";
                isnew && addfragment(node);
                node = document.createElement("span");
                isnew = true;
                displaylen = 0;
            } else {
                addlinepart(node, render);
                displaylen += render.length;
            }
            line = line.substr(render.length);
        }
        isnew && addfragment(node);
    }

    // hide newline on last line
    var lines, lastfull;
    if (typeof string === "string") {
        lines = string.split(/^/m);
        if (lines.length && lines[lines.length - 1] === "") {
            lines.pop();
        }
        lastfull = lines.length && lines[lines.length - 1].endsWith("\n");
    } else {
        lines = [];
        lastfull = true;
        fragment = string;
    }

    var node = container.lastChild, cursor = null;
    if (node
        && node.lastChild
        && hasClass(node.lastChild, "pa-runcursor")) {
        cursor = node.lastChild;
        node.removeChild(cursor);
    }

    if (node
        && (string = node.getAttribute("data-pa-outputpart")) !== null
        && string !== ""
        && lines.length) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
        lines[0] = string + lines[0];
        node.removeAttribute("data-pa-outputpart");
    } else {
        if (node && (lines.length || fragment)) {
            node.appendChild(document.createTextNode("\n"));
            node.removeAttribute("data-pa-outputpart");
        }
        node = null;
    }

    var laststyles = styles, i, j, last;
    for (i = 0; i < lines.length; i = j) {
        laststyles = styles;
        last = lines[i];
        for (j = i + 1; !last.endsWith("\n") && j < lines.length; ++j) {
            last += lines[j];
        }
        if (j == lines.length && lastfull) {
            last = last.substring(0, last.length - 1);
        }
        render_line(last, i ? null : node);
    }

    if (options && options.cursor && !container.lastChild && !fragment) {
        addfragment("");
    }

    if (fragment) {
        container.appendChild(fragment);
    }

    var len = container.childNodes.length;
    if (len >= 4000) {
        i = container.firstChild;
        while (i.tagName === "DIV" && i.className === "pa-rl-group") {
            i = i.nextSibling;
            --len;
        }
        var div = null, divlen = 0;
        while (i && (j = i.nextSibling)) {
            if (!div
                || (divlen >= 4000 && len >= 2000)) {
                div = document.createElement("div");
                div.className = "pa-rl-group";
                container.insertBefore(div, i);
                divlen = 0;
            }
            container.removeChild(i);
            div.appendChild(i);
            i = j;
            ++divlen;
            --len;
        }
    }

    if (options && options.cursor) {
        if (!cursor) {
            cursor = document.createElement("span");
            cursor.className = "pa-runcursor";
        }
        container.lastChild.appendChild(cursor);
    }

    if (!lastfull && container.lastChild) {
        styles = laststyles;
        container.lastChild.setAttribute("data-pa-outputpart", last);
    }

    if (styles != null) {
        container.setAttribute("data-pa-terminal-style", styles);
    } else {
        container.removeAttribute("data-pa-terminal-style");
    }

    if (return_html) {
        return container.innerHTML;
    }
}

render_text.add_format({
    format: 2,
    render: function (text) {
        return render_terminal(text);
    }
});
