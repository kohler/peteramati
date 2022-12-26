// render.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities } from "./encoders.js";
import { markdownit_minihtml } from "./markdown-minihtml.js";
import { hasClass } from "./ui.js";
import { string_utf8_index } from "./utils.js";

function render_class(c, format) {
    if (c) {
        return c.replace(/(?:^|\s)(?:need-format|format\d+)(?=$|\s)/g, "").concat(" format", format).trimStart();
    } else {
        return "format" + format;
    }
}

export function parse_ftext(t) {
    let fmt = 0, pos = 0;
    while (true) {
        const ch = t.charCodeAt(pos);
        if (pos === 0 ? ch !== 60 : ch !== 62 && (ch < 48 || ch > 57)) {
            return [0, t];
        } else if (pos !== 0 && ch >= 48 && ch <= 57) {
            fmt = 10 * fmt + ch - 48;
        } else if (ch === 62) {
            return pos === 1 ? [0, t] : [fmt, t.substring(pos + 1)];
        }
        ++pos;
    }
}


const renderers = {};

function add_format(r) {
    if (r.format == null || r.format === "" || renderers[r.format]) {
        throw new Error("bad or reused format");
    }
    renderers[r.format] = r;
}


function link_urls(t) {
    var re = /((?:https?|ftp):\/\/(?:[^\s<>\"&]|&amp;)*[^\s<>\"().,:;&])([\"().,:;]*)(?=[\s<>&]|$)/g;
    return t.replace(re, function (m, a, b) {
        return '<a href="' + a + '" rel="noreferrer">' + a + '</a>' + b;
    });
}

function render0(text) {
    var lines = text.split(/((?:\r\n?|\n)(?:[-+*][ \t]|\d+\.)?)/), ch;
    for (var i = 1; i < lines.length; i += 2) {
        if (lines[i - 1].length > 49
            && lines[i].length <= 2
            && (ch = lines[i + 1].charAt(0)) !== ""
            && ch !== " "
            && ch !== "\t")
            lines[i] = " ";
    }
    text = "<p>" + link_urls(escape_entities(lines.join(""))) + "</p>";
    return text.replace(/\r\n?(?:\r\n?)+|\n\n+/g, "</p><p>");
}

function render0_inline(text) {
    return link_urls(escape_entities(text));
}

add_format({
    format: 0,
    render: render0,
    render_inline: render0_inline
});

let md, md2;
function try_highlight(str, lang, langAttr, token) {
    if (lang && hljs.getLanguage(lang)) {
        try {
            var hlstr = hljs.highlight(lang, str, true).value,
                classIndex = token ? token.attrIndex("class") : -1,
                lineIndex = token ? token.attrIndex("data-lineno-start") : -1;
            if (classIndex >= 0 && /^(.*(?: |^))need-lineno((?: |$).*)$/.test(token.attrs[classIndex][1])) {
                let n = lineIndex >= 0 ? token.attrs[lineIndex][1] : "1";
                const m = n.match(/^(.*?)(\d*)(\D*)$/),
                    pfx = m[1], minlen = m[2].startsWith("0") ? m[2].length : 0, sfx = m[3],
                    fmt = (n) => pfx + n.toString().padStart(minlen, "0") + sfx;
                n = m[2] ? +m[2] : 1;
                let lines = hlstr.split(/\n/);
                if (lines.length > 0 && lines[lines.length - 1] === "") {
                    lines.pop();
                }
                const linestart = '<span class="has-lineno has-lineno-'.concat(fmt(n + lines.length - 1).length, '" data-lineno="');
                for (let i = 0; i !== lines.length; ++i, ++n) {
                    lines[i] = linestart.concat(fmt(n), '">', lines[i], '</span>');
                }
                hlstr = lines.join("\n") + "\n";
            }
            return hlstr;
        } catch (ex) {
        }
    }
    return "";
}

add_format({
    format: 1,
    render: function (text) {
        if (!md) {
            md = window.markdownit({highlight: try_highlight, linkify: true}).use(markdownit_katex).use(markdownit_minihtml);
        }
        return md.render(text);
    }
});

add_format({
    format: 3,
    render: function (text) {
        if (!md2) {
            md2 = window.markdownit({highlight: try_highlight, linkify: true, html: true, attributes: true}).use(markdownit_katex);
        }
        return md2.render(text);
    }
});

add_format({
    format: 5,
    render: function (text) {
        return text;
    }
});


function render_with(context, renderer, text) {
    const want_inline = hasClass(context, "format-inline")
            || window.getComputedStyle(context).display.startsWith("inline"),
        renderf = (want_inline && renderer.render_inline) || renderer.render,
        html = renderf.call(context, text, context);
    context.className = render_class(context.className, renderer.format);
    context.innerHTML = html;
    if (want_inline
        && !renderer.render_inline
        && context.children.length === 1
        && context.firstChild.tagName === "P") {
        context.firstChild.replaceWith(...context.firstChild.childNodes);
    }
}

export function render_onto(context, format, text) {
    if (format === "f") {
        var ft = parse_ftext(text);
        format = ft[0];
        text = ft[1];
    }
    try {
        render_with(context, renderers[format] || renderers[0], text);
    } catch (err) {
        render_with(context, renderers[0], text);
        delete renderers[format];
    }
}


function render_this() {
    const format = this.hasAttribute("data-format")
            ? this.getAttribute("data-format")
            : "f",
        content = this.hasAttribute("data-content")
            ? this.getAttribute("data-content")
            : this.textContent;
    render_onto(this, format !== "" ? format : "f", content);
}

function on_page() {
    $(".need-format").each(render_this);
}

export const render_text = {
    add_format: add_format,
    on_page: on_page
};

$(on_page);

export const ftext = {
    parse: parse_ftext,
    unparse: function (format, text) {
        return format || text.startsWith("<") ? "<".concat(format || 0, ">", text) : text;
    }
};


// render_xmsg
export function render_xmsg(status, msg) {
    if (typeof msg === "string") {
        msg = msg === "" ? [] : [msg];
    }
    if (msg.length === 0) {
        return [];
    }
    const div = document.createElement("div");
    if (status === 0 || status === 1 || status === 2) {
        div.className = "msg msg-".concat(["info", "warning", "error"][status]);
    } else {
        div.className = "msg msg-error";
    }
    for (let i = 0; i !== msg.length; ++i) {
        const p = document.createElement("p");
        p.append(msg[i]);
        div.append(p);
    }
    return div;
}


function message_list_status(ml) {
    var i, status = 0;
    for (i = 0; i !== (ml || []).length; ++i) {
        if (ml[i].status === -3 && status === 0) {
            status = -3;
        } else if (ml[i].status >= 1 && ml[i].status > status) {
            status = ml[i].status;
        }
    }
    return status;
}

export function render_message_list(ml) {
    var status = message_list_status(ml),
        div = document.createElement("div");
    if (status === -3) {
        div.className = "msg msg-success";
    } else if (status >= 2) {
        div.className = "msg msg-error";
    } else if (status === 1) {
        div.className = "msg msg-warning";
    } else {
        div.className = "msg msg-info";
    }
    div.appendChild(render_feedback_list(ml));
    return div;
}

export function render_feedback_list(ml) {
    const ul = document.createElement("ul");
    ul.className = "feedback-list";
    for (let i = 0; i !== (ml || []).length; ++i) {
        append_feedback_to(ul, ml[i]);
    }
    return ul;
}

function append_feedback_to(ul, mi) {
    var sklass, li, div;
    if (mi.message != null && mi.message !== "") {
        if (ul.tagName !== "UL")
            throw new Error("bad append_feedback");
        sklass = "";
        if (mi.status != null && mi.status >= -4 && mi.status <= 3)
            sklass = ["warning-note", "success", "urgent-note", "note", "", "warning", "error", "error"][mi.status + 4];
        div = document.createElement("div");
        if (mi.status !== -5 || !ul.firstChild) {
            li = document.createElement("li");
            ul.appendChild(li);
            div.className = sklass ? "is-diagnostic format-inline is-" + sklass : "is-diagnostic format-inline";
        } else {
            li = ul.lastChild;
            div.className = "msg-inform format-inline";
        }
        li.appendChild(div);
        render_onto(div, "f", mi.message);
    }
    if (mi.context) {
        div = document.createElement("div");
        div.className = "msg-context";
        var s = mi.context[0],
            p1 = string_utf8_index(s, mi.context[1]),
            p2 = string_utf8_index(s, mi.context[2]),
            span = document.createElement("span");
        sklass = mi.status > 1 ? "is-error" : "is-warning";
        span.className = (p2 > p1 + 2 ? "context-mark " : "context-caret-mark ") +
            (mi.status > 1 ? "is-error" : "is-warning");
        span.append(s.substring(p1, p2));
        div.append(s.substring(0, p1), span, s.substring(p2));
        ul.lastChild.appendChild(div);
    }
}
