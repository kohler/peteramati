// diff-markdown.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { Filediff } from "./diff.js";
import { hasClass, addClass, removeClass, toggleClass, handle_ui } from "./ui.js";
import { hoturl_gradeapi } from "./hoturl.js";
import { markdownit_minihtml } from "./markdown-minihtml.js";

let md, mdcontext;


function hljs_line(lang, s, hlstate) {
    try {
        const result = hljs.highlight(lang, s, true, hlstate),
            ns = result.value;
        if (s.endsWith("\r\n") && ns.endsWith("\n\n")) {
            result.value = ns.substring(0, ns.length - 1);
        }
        return result;
    } catch (exc) {
        return null;
    }
}

function render_map(map) {
    if (map[0] + 1 === map[1]) {
        return String(map[1]);
    } else {
        return (map[0] + 1) + "-" + map[1];
    }
}

function add_landmark(tokens, idx, options, env, self) {
    var token = tokens[idx];
    if (token.map && token.level === 0) {
        token.attrSet("data-landmark", render_map(token.map));
    }
    return self.renderToken(tokens, idx, options, env);
}

function add_landmark_1(tokens, idx, options, env, self) {
    var token = tokens[idx];
    if (token.map && token.level <= 1) {
        token.attrSet("data-landmark", render_map(token.map));
    }
    return self.renderToken(tokens, idx, options, env);
}

function fix_landmark_html(html, token) {
    if (token.map && token.level === 0) {
        var lm = " data-landmark=\"" + render_map(token.map) + "\"",
            sp = html.indexOf(" "),
            gt = html.indexOf(">");
        if (sp > 0 && sp < gt) {
            gt = sp;
        }
        html = html.substring(0, gt) + lm + html.substring(gt);
    }
    return html;
}

function modify_landmark(base) {
    if (!base) {
        return add_landmark;
    } else {
        return function (tokens, idx, options, env, self) {
            var token = tokens[idx];
            return fix_landmark_html(base(tokens, idx, options, env, self), token);
        };
    }
}

function modify_landmark_image(base) {
    function fix(pi, file) {
        return siteinfo.site_relative + "~" + encodeURIComponent(siteinfo.uservalue) + "/raw/" + pi.getAttribute("data-pa-pset") + "/" + pi.getAttribute("data-pa-hash") + "/" + file;
    }
    return function (tokens, idx, options, env, self) {
        var token = tokens[idx],
            srci = token.attrIndex("src"),
            src = token.attrs[srci][1],
            pi, m, m2;
        if (siteinfo.uservalue
            && mdcontext
            && (pi = mdcontext.closest(".pa-psetinfo"))) {
            if (!/\/\//.test(src)) {
                var fileref = mdcontext.closest(".pa-filediff"),
                    dir = fileref && fileref.hasAttribute("data-pa-file") ? fileref.getAttribute("data-pa-file").replace(/^(.*)\/[^\/]*$/, '$1') : "";
                while (true) {
                    if (src.startsWith("./")) {
                        src = src.substring(2).replace(/^\/+/, "");
                    } else if (src.startsWith("../") && dir !== "") {
                        src = src.substring(3).replace(/^\/+/, "");
                        dir = dir.replace(/(?:^|\/)[^\/]+\/*$/, "");
                    } else if (src.startsWith("../") || src.startsWith("/")) {
                        src = null;
                        break;
                    } else if ((m = src.match(/(^|\/+)[^\/]+\/\.\.(?:\/+|$)(.*)$/))) {
                        src = m[1] + m[2];
                    } else {
                        break;
                    }
                }
                if (src) {
                    token.attrs[srci][1] = fix(pi, dir ? dir + "/" + src : src);
                } else {
                    token.attrs[srci][1] = "data:image/jpg,";
                }
            } if ((m = src.match(/^https:\/\/github\.com\/([^\/]+\/[^\/]+)\/(?:blob|raw)\/([^\/]+)\/(.*)$/))
                  && (m2 = (pi.getAttribute("data-pa-repourl") || "").match(/^(?:https:\/\/github\.com\/|git@github\.com:)(.*?)\/?$/))
                  && m2[1] == m[1]
                  && pi.getAttribute("data-pa-branch") == m[2]) {
                token.attrs[srci][1] = fix(pi, m[3]);
            }
        }
        return fix_landmark_html(base(tokens, idx, options, env, self), token);
    };
}

function render_landmark_fence(md) {
    return function (tokens, idx, options, env, self) {
        const token = tokens[idx];
        let info = token.info ? md.utils.unescapeAll(token.info) : "", m;
        if (info && info.indexOf(" ") >= 0) {
            if ((m = info.match(/^ *([-a-z+]+) *$/))) {
                info = m[1];
            } else {
                token.content = info + "\n" + token.content;
                token.map && (token.map[0] -= 1);
                info = "";
            }
        }
        let lang = info ? info.trim().split(/\s+/g)[0] : "";
        if (!lang || !hljs.getLanguage(lang)) {
            lang = null;
        }

        let xtoken = token;
        if (lang) {
            let i = token.attrIndex("class");
            xtoken = {attrs: token.attrs ? token.attrs.slice() : []};
            if (i < 0) {
                xtoken.attrs.push(["class", options.langPrefix + lang]);
            } else {
                xtoken.attrs[i][1] += " " + options.langPrefix + lang;
            }
        }
        let xattrs = '><code'.concat(self.renderAttrs(xtoken), '>');

        if (token.map && token.level === 0) {
            // split into lines, assign landmarks
            const x = token.content.split(/\n/), y = [];
            x[x.length - 1] === "" && x.pop();
            const xl = x.length;
            let i = 0, ln0 = token.map[0] + 2, hlstate = null;
            while (lang && i !== xl) {
                let result = hljs_line(lang, x + "\n", hlstate);
                if (result) {
                    y.push('<pre data-landmark="', ln0 + i,
                           i + 1 !== xl ? '" class="partial"' : '"',
                           xattrs, result.value, "</code></pre>");
                    hlstate = result.top;
                    ++i;
                } else {
                    break;
                }
            }
            while (i !== xl) {
                y.push('<pre data-landmark="', ln0 + i,
                       i + 1 !== xl ? '" class="partial"' : '"',
                       xattrs, x[i], "\n</code></pre>");
                ++i;
            }
            return y.join("");
        } else {
            return '<pre'.concat(xattrs, md.utils.escapeHtml(token.content), '</code></pre>');
        }
    };
}

function make_markdownit() {
    if (!md) {
        md = markdownit({linkify: true}).use(markdownit_katex).use(markdownit_minihtml);
        for (var x of ["paragraph_open", "heading_open", "ordered_list_open",
                       "bullet_list_open", "table_open", "blockquote_open",
                       "hr"]) {
            md.renderer.rules[x] = modify_landmark(md.renderer.rules[x]);
        }
        md.renderer.rules.fence = md.renderer.rules.code_block = render_landmark_fence(md);
        md.renderer.rules.image = modify_landmark_image(md.renderer.rules.image);
        md.renderer.rules.list_item_open = add_landmark_1;
    }
    return md;
}

function fix_list_item(d) {
    var dc;
    while ((dc = d.firstChild) && dc.nodeType !== 1) {
        d.removeChild(dc);
    }
    if (dc && dc.hasAttribute("data-landmark")) {
        while (dc.nextSibling && dc.nextSibling.nodeType !== 1) {
            d.removeChild(dc.nextSibling);
        }
        if (dc.nextSibling) {
            if (d.tagName === "OL") {
                if (!dc.hasAttribute("value")) {
                    dc.value = d.start;
                }
                d.start = dc.value + 1;
            }
            var nd = document.createElement(d.tagName);
            nd.appendChild(d.removeChild(dc));
            d = nd;
        }
        d.setAttribute("data-landmark", dc.getAttribute("data-landmark"));
    }
    return d;
}

Filediff.define_method("markdown", function () {
    const elt = this.element;
    if (hasClass(elt, "pa-markdown") || hasClass(elt, "pa-highlight")) {
        return;
    }
    // collect content
    let e = elt.firstChild, l = [], lineno = 1;
    while (e) {
        let n = e.nextSibling;
        if (hasClass(e, "pa-dlr")) {
            elt.removeChild(e);
        } else if (hasClass(e, "pa-gi") || hasClass(e, "pa-gc")) {
            const this_lineno = +e.firstChild.nextSibling.getAttribute("data-landmark");
            while (lineno < this_lineno) {
                l.push("\n");
                ++lineno;
            }
            l.push(e.lastChild.textContent);
            ++lineno;
            addClass(e, "hidden");
        }
        e = n;
    }
    // render to markdown
    let dx = document.createElement("div"), d;
    mdcontext = elt;
    dx.innerHTML = make_markdownit().render(l.join(""));
    mdcontext = null;
    // split up and insert into order
    e = elt.firstChild;
    while ((d = dx.firstChild)) {
        if (d.nodeType !== 1) {
            dx.removeChild(d);
            continue;
        } else if (d.tagName === "OL" || d.tagName === "UL") {
            d = fix_list_item(d);
        } else if (d.tagName === "P"
                   && d.firstChild.nodeType === 1
                   && d.firstChild.tagName === "IMG"
                   && d.firstChild === d.lastChild) {
            addClass(d, "image-container");
        }

        const lp = document.createElement("div");
        lp.className = "pa-dl pa-dlr";
        const la = document.createElement("div");
        la.className = "pa-da";
        const lb = document.createElement("div");
        lb.className = "pa-db";

        const lm = d.getAttribute("data-landmark");
        if (lm) {
            let l1 = parseInt(lm),
                dash = lm.indexOf("-"),
                l2 = dash >= 0 ? parseInt(lm.substring(dash + 1)) : l1;
            while (e) {
                if ((hasClass(e, "pa-gi") || hasClass(e, "pa-gc"))
                    && +e.firstChild.nextSibling.getAttribute("data-landmark") >= l1) {
                    break;
                }
                e = e.nextSibling;
            }
            lb.setAttribute("data-landmark", l1);
            let klass = 0, ee = e;
            while (ee) {
                if (hasClass(ee, "pa-gi") || hasClass(ee, "pa-gc")) {
                    if (+ee.firstChild.nextSibling.getAttribute("data-landmark") >= l2) {
                        break;
                    }
                    klass |= hasClass(ee, "pa-gi") ? 1 : 2;
                }
                ee = ee.nextSibling;
            }
            lp.className += klass === 2 ? " pa-gc" : " pa-gi";
        }

        const lr = document.createElement("div");
        lr.className = "pa-dr";
        if (d === dx.firstChild) {
            dx.removeChild(d);
        }
        lr.appendChild(d);
        while (dx.firstChild && dx.firstChild.nodeType !== 1) {
            lr.appendChild(dx.removeChild(dx.firstChild));
        }

        lp.appendChild(la);
        lp.appendChild(lb);
        lp.appendChild(lr);
        elt.insertBefore(lp, e);
    }
    addClass(elt, "pa-markdown");
});

Filediff.define_method("unmarkdown", function () {
    const elt = this.element;
    let e = elt.firstChild;
    while (e) {
        var n = e.nextSibling;
        if (hasClass(e, "pa-dlr")) {
            elt.removeChild(e);
        } else if (hasClass(e, "pa-gi") || hasClass(e, "pa-gc")) {
            removeClass(e, "hidden");
        }
        e = n;
    }
    removeClass(elt, "pa-markdown");
});

Filediff.define_method("highlight", function () {
    const elt = this.element;
    // compute language
    var file = elt.getAttribute("data-pa-file"), lang;
    if (!(lang = elt.getAttribute("data-language"))) {
        if (/\.(?:cc|cpp|hh|hpp|c\+\+|h\+\+|C|H)$/.test(file)) {
            lang = "c++";
        } else if (/\.(?:c|h)$/.test(file)) {
            lang = "c";
        }
        lang && elt.setAttribute("data-language", lang);
    }
    if (!lang || !hljs.getLanguage(lang)
        || hasClass(elt, "pa-highlight")
        || hasClass(elt, "pa-markdown"))
        return;
    // collect content
    const langclass = "language-" + lang;
    let e = elt.firstChild, hlstatei = null, hlstated = null;
    while (e) {
        const type = hasClass(e, "pa-gi") ? 2 : (hasClass(e, "pa-gc") ? 3 : (hasClass(e, "pa-gd") ? 1 : 0));
        if (type !== 0) {
            const ce = e.lastChild,
                s = ce.textContent,
                result = hljs_line(lang, s, type & 2 ? hlstatei : hlstated);
            if (!result) {
                break;
            }
            if (type & 1) {
                hlstated = result.top;
            }
            if (type & 2) {
                hlstatei = result.top;
            }
            ce.setAttribute("data-pa-text", s);
            ce.innerHTML = result.value;
            addClass(ce, langclass);
        }
        e = e.nextSibling;
    }
    addClass(elt, "pa-highlight");
});

Filediff.define_method("unhighlight", function () {
    // compute language
    const elt = this.element,
        lang = elt.getAttribute("data-language"),
        langclass = lang ? "language-" + lang : "";
    let e = elt.firstChild, et;
    while (e) {
        if ((et = e.lastChild)
            && et.hasAttribute("data-pa-text")
            && (!langclass || hasClass(et, langclass))) {
            et.innerText = et.getAttribute("data-pa-text");
            et.removeAttribute("data-pa-text");
            langclass && removeClass(et, langclass);
        }
        e = e.nextSibling;
    }
    removeClass(elt, "pa-highlight");
});


handle_ui.on("pa-diff-toggle-markdown", function (evt) {
    const $es = evt.metaKey ? $(".pa-diff-toggle-markdown") : $(this),
        fd = Filediff.find(this),
        show = !hasClass(fd.element, "pa-markdown");
    $es.each(function () {
        const f = Filediff.find(this),
            shown = hasClass(f.element, "pa-markdown");
        if (show && !shown) {
            f.markdown();
        } else if (!show && shown) {
            f.unmarkdown();
        }
        toggleClass(this, "btn-primary", show);
    });
    if (!evt.metaKey) {
        $.post(hoturl_gradeapi(fd.element, "=api/diffconfig"),
            {file: fd.file, markdown: show ? 1 : 0});
    }
});

$(function () {
    $(".pa-filediff.need-highlight:not(.need-load)").each(function () {
        new Filediff(this).highlight();
    });
});
