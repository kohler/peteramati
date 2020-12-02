// ui-format.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities } from "./encoders.js";
import { log_jserror } from "./utils-errors.js";
import { markdownit_minihtml } from "./markdown-minihtml.js";


let default_format = 0;
const renderers = {"0": {format: 0, render: render0}};

function link_urls(t) {
    var re = /((?:https?|ftp):\/\/(?:[^\s<>\"&]|&amp;)*[^\s<>\"().,:;&])([\"().,:;]*)(?=[\s<>&]|$)/g;
    return t.replace(re, function (m, a, b) {
        return '<a href="' + a + '" rel="noreferrer">' + a + '</a>' + b;
    });
}

function render0(text) {
    return link_urls(escape_entities(text));
}

function lookup(format) {
    var r, p;
    if (format
        && ((r = renderers[format])
            || (typeof format === "string"
                && (p = format.indexOf(".")) > 0
                && (r = renderers[format.substring(0, p)])))) {
        return r;
    }
    if (format == null) {
        format = default_format;
    }
    return renderers[format] || renderers[0];
}

function do_render(format, is_inline, a) {
    var r = lookup(format);
    if (r.format) {
        try {
            var f = (is_inline && r.render_inline) || r.render;
            return {
                format: r.formatClass || r.format,
                content: f.apply(this, a)
            };
        } catch (e) {
            log_jserror("do_render format " + r.format + ": " + e.toString(), e);
        }
    }
    return {format: 0, content: render0(a[0])};
}

export function render_text(format, text /* arguments... */) {
    var a = [text], i;
    for (i = 2; i < arguments.length; ++i) {
        a.push(arguments[i]);
    }
    return do_render.call(this, format, false, a);
}

function render_inline(format, text /* arguments... */) {
    var a = [text], i;
    for (i = 2; i < arguments.length; ++i) {
        a.push(arguments[i]);
    }
    return do_render.call(this, format, true, a);
}

function on() {
    var $j = $(this),
        format = this.getAttribute("data-format"),
        content = this.getAttribute("data-content") || $j.text(),
        args = null, f, i;
    if ((i = format.indexOf(".")) > 0) {
        var a = format.split(/\./);
        format = a[0];
        args = {};
        for (i = 1; i < a.length; ++i) {
            args[a[i]] = true;
        }
    }
    if (this.tagName == "DIV") {
        f = render_text.call(this, format, content, args);
    } else {
        f = render_inline.call(this, format, content, args);
    }
    if (f.format) {
        $j.html(f.content);
    }
    var s = $.trim(this.className.replace(/(?:^| )(?:need-format|format\d+)(?= |$)/g, " "));
    this.className = s + (s ? " format" : "format") + (f.format || 0);
    if (f.format) {
        $j.trigger("renderText", f);
    }
}

$.extend(render_text, {
    add_format: function (x) {
        x.format && (renderers[x.format] = x);
    },
    format: function (format) {
        return lookup(format);
    },
    set_default_format: function (format) {
        default_format = format;
    },
    inline: render_inline,
    on: on,
    on_page: function () { $(".need-format").each(on); }
});


function try_highlight(str, lang) {
    if (lang && hljs.getLanguage(lang)) {
        try {
            return hljs.highlight(lang, str, true).value;
        } catch (ex) {
        }
    }
    return "";
}

let md;

render_text.add_format({
    format: 1,
    render: function (text) {
        if (!md) {
            md = window.markdownit({highlight: try_highlight}).use(markdownit_katex).use(markdownit_minihtml);
        }
        return md.render(text);
    }
});

$(render_text.on_page);
