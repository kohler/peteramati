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
        let format = this.getAttribute("data-format"),
            content = this.getAttribute("data-content");
        if (content == null) {
            content = this.textContent;
        }
        if (format == null) {
            const ft = parse_ftext(content);
            format = ft[0];
            content = ft[1];
        }
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
function try_highlight(str, lang, langAttr, token) {
    if (lang && hljs.getLanguage(lang)) {
        try {
            var str = hljs.highlight(lang, str, true).value,
                classIndex = token ? token.attrIndex("class") : -1,
                lineIndex = token ? token.attrIndex("data-lineno-start") : -1,
                m, i, firstLine = 1, lines;
            if (classIndex >= 0 && /^(.*(?: |^))need-lineno((?: |$).*)$/.test(token.attrs[classIndex][1])) {
                if (lineIndex >= 0 && token.attrs[lineIndex][1]) {
                    firstLine = +token.attrs[lineIndex][1];
                }
                if (firstLine !== firstLine) {
                    firstLine = 1;
                }
                lines = str.split(/\n/);
                if (lines.length > 0 && lines[lines.length - 1] === "") {
                    lines.pop();
                }
                for (i = 0; i !== lines.length; ++i, ++firstLine) {
                    lines[i] = '<span class="has-lineno" data-lineno="'.concat(firstLine, '">', lines[i], '</span>');
                }
                str = lines.join("\n") + "\n";
            }
            return str;
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
            md2 = window.markdownit({highlight: try_highlight, linkify: true, html: true, attributes: true}).use(markdownit_katex);
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
    const ft = parse_ftext(ftext);
    return render_with(renderers[ft[0]] || renderers[0], ft[1], context);
}


export const ftext = {
    parse: parse_ftext,
    unparse: function (format, text) {
        return format || text.startsWith("<") ? "<".concat(format || 0, ">", text) : text;
    },
    render: render_ftext
};


$(render_text.on_page);
