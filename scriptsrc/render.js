// render.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities } from "./encoders.js";
import { markdownit_minihtml } from "./markdown-minihtml.js";

function render_class(c, format) {
    if (c) {
        return c.replace(/(?:^|\s)(?:need-format|format\d+)(?=$|\s)/g, "").concat(" format", format).trimStart();
    } else {
        return "format" + format;
    }
}

function render_with(r, text, context) {
    const t = r.render(text);
    if (context == null) {
        return t;
    } else if (context instanceof Element) {
        context.className = render_class(context.className, r.format);
        context.innerHTML = t;
    } else {
        return '<div class="'.concat(render_class(context, r.format), '">', t, '</div>');
    }
}


let default_format = 0;
const renderers = {};

export function render_text(format, text, context) {
    return render_with(renderers[format] || renderers[0], text, context);
}

render_text.add_format = function (r) {
    if (r.format == null || r.format === "" || renderers[r.format]) {
        throw new Error("bad or reused format");
    }
    renderers[r.format] = r;
};

render_text.on_page = function () {
    $(".need-format").each(function () {
        const format = this.getAttribute("data-format") || default_format,
            content = this.getAttribute("data-content") || this.textContent;
        render_text(format, content, this);
    });
};


function link_urls(t) {
    var re = /((?:https?|ftp):\/\/(?:[^\s<>\"&]|&amp;)*[^\s<>\"().,:;&])([\"().,:;]*)(?=[\s<>&]|$)/g;
    return t.replace(re, function (m, a, b) {
        return '<a href="' + a + '" rel="noreferrer">' + a + '</a>' + b;
    });
}

render_text.add_format({
    format: 0,
    render: function (text) {
        return link_urls(escape_entities(text));
    }
});

let md, md2;
function try_highlight(str, lang) {
    if (lang && hljs.getLanguage(lang)) {
        try {
            return hljs.highlight(lang, str, true).value;
        } catch (ex) {
        }
    }
    return "";
}

render_text.add_format({
    format: 1,
    render: function (text) {
        if (!md) {
            md = window.markdownit({highlight: try_highlight, linkify: true}).use(markdownit_katex).use(markdownit_minihtml);
        }
        return md.render(text);
    }
});

render_text.add_format({
    format: 3,
    render: function (text) {
        if (!md2) {
            md2 = window.markdownit({highlight: try_highlight, linkify: true, html: true}).use(markdownit_katex);
        }
        return md2.render(text);
    }
});

render_text.add_format({
    format: 5,
    render: function (text) {
        return text;
    }
});


export function render_ftext(ftext, context) {
    let ch, pos, dig, r;
    if (ftext.charAt(0) === "<"
        && (ch = ftext.charAt(1)) >= "0"
        && ch <= "9"
        && (pos = ftext.indexOf(">")) >= 2
        && /^\d+$/.test((dig = ftext.substring(1, pos)))
        && (r = renderers[+dig])) {
        return render_with(r, ftext.substring(pos + 1), context);
    } else {
        return render_with(renderers[0], ftext, context);
    }
}

$(render_text.on_page);
