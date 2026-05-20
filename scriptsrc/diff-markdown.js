// diff-markdown.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { Filediff } from "./diff.js";
import { hasClass, addClass, removeClass, toggleClass, handle_ui } from "./ui.js";
import { hoturl } from "./hoturl.js";
import { markdownit_minihtml } from "./markdown-minihtml.js";
import { markdownit_katex } from "./markdown-katex.js";
import { markdownit_deflist } from "./markdown-deflist.js";
import {
    supports_language, can_support, ensure_language, highlight_line,
    highlight_fences_when_ready
} from "./shiki-highlight.js";

let md, mdcontext;

function render_map(map) {
    if (map[0] + 1 === map[1]) {
        return String(map[1]);
    }
    return (map[0] + 1) + "-" + map[1];
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
    }
    return function (tokens, idx, options, env, self) {
        var token = tokens[idx];
        return fix_landmark_html(base(tokens, idx, options, env, self), token);
    };
}

function modify_landmark_image(base) {
    function fix(user, pi, file) {
        let t = siteinfo.site_relative;
        if (user) {
            t += "~" + encodeURIComponent(user) + "/";
        }
        t += "raw/" + pi.getAttribute("data-pa-pset") + "/" + pi.getAttribute("data-pa-commit") + "/" + file;
        if (siteinfo.defaults) {
            t += "?" + (new URLSearchParams(siteinfo.defaults)).toString();
        }
        return t;
    }
    return function (tokens, idx, options, env, self) {
        let token = tokens[idx],
            srci = token.attrIndex("src"),
            src = token.attrs[srci][1],
            pi, m, m2;
        if (mdcontext && (pi = mdcontext.closest(".pa-psetinfo"))) {
            const user = pi.getAttribute("data-pa-user") || siteinfo.uservalue;
            if (!/\/\//.test(src)) {
                let fd = Filediff.closest(mdcontext),
                    dir = fd ? fd.file.replace(/^(.*)\/[^/]*$/, '$1') : "";
                while (true) {
                    if (src.startsWith("./")) {
                        src = src.substring(2).replace(/^\/+/, "");
                    } else if (src.startsWith("../") && dir !== "") {
                        src = src.substring(3).replace(/^\/+/, "");
                        dir = dir.replace(/(?:^|\/)[^/]+\/*$/, "");
                    } else if (src.startsWith("../") || src.startsWith("/")) {
                        src = null;
                        break;
                    } else if ((m = src.match(/(^|\/+)[^/]+\/\.\.(?:\/+|$)(.*)$/))) {
                        src = m[1] + m[2];
                    } else {
                        break;
                    }
                }
                if (src) {
                    token.attrs[srci][1] = fix(user, pi, dir ? dir + "/" + src : src);
                } else {
                    token.attrs[srci][1] = "data:image/jpg,";
                }
            } if ((m = src.match(/^https:\/\/github\.com\/([^/]+\/[^/]+)\/(?:blob|raw)\/([^/]+)\/(.*)$/))
                  && (m2 = (pi.getAttribute("data-pa-repourl") || "").match(/^(?:https:\/\/github\.com\/|git@github\.com:)(.*?)\/?$/))
                  && m2[1] == m[1]
                  && pi.getAttribute("data-pa-branch") == m[2]) {
                token.attrs[srci][1] = fix(user, pi, m[3]);
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

        // Lines are emitted unhighlighted and tagged `need-highlight`; a Shiki
        // pass upgrades them once the (async) highlighter and grammar load.
        let xtoken = token;
        if (lang) {
            let i = token.attrIndex("class");
            const cls = options.langPrefix + lang + " need-highlight";
            xtoken = {attrs: token.attrs ? token.attrs.slice() : []};
            if (i < 0) {
                xtoken.attrs.push(["class", cls]);
            } else {
                xtoken.attrs[i] = [xtoken.attrs[i][0], xtoken.attrs[i][1] + " " + cls];
            }
        }
        let xcode = '><code'.concat(self.renderAttrs(xtoken));

        if (token.map && token.level === 0) {
            // split into lines, assign landmarks
            const x = token.content.split(/\n/), y = [];
            x[x.length - 1] === "" && x.pop();
            const xl = x.length;
            let ln0 = token.map[0] + 2;
            for (let i = 0; i !== xl; ++i) {
                y.push('<pre data-landmark="', ln0 + i,
                       i + 1 !== xl ? '" class="partial"' : '"', xcode);
                if (lang) {
                    y.push(' data-language="', md.utils.escapeHtml(lang),
                           '" data-pa-text="', md.utils.escapeHtml(x[i] + "\n"), '"');
                }
                y.push('>', md.utils.escapeHtml(x[i]), "\n</code></pre>");
            }
            return y.join("");
        } else {
            return '<pre'.concat(xcode, '>', md.utils.escapeHtml(token.content), '</code></pre>');
        }
    };
}

function make_markdownit() {
    if (!md) {
        md = markdownit('hotcrp', {linkify: true})
            .use(markdownit_katex)
            .use(markdownit_minihtml)
            .use(markdownit_deflist);
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
    if (hasClass(elt, "pa-markdown")
        || hasClass(elt, "pa-highlight")) {
        return;
    }
    const hidelm = hasClass(elt, "pa-hide-landmarks"),
        drclass = hidelm ? "pa-dr pa-dhlm" : "pa-dr";
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
            e.hidden = true;
        }
        e = n;
    }
    // render to markdown
    let dx = document.createElement("div"), d, lr;
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
        la.hidden = hidelm;
        const lb = document.createElement("div");
        lb.className = "pa-db";
        lb.hidden = hidelm;

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

        lr = document.createElement("div");
        lr.className = drclass;
        if (d === dx.firstChild) {
            dx.removeChild(d);
        }
        lr.appendChild(d);
        while (dx.firstChild && dx.firstChild.nodeType !== 1) {
            lr.appendChild(dx.removeChild(dx.firstChild));
        }

        lp.append(la, lb, lr);
        elt.insertBefore(lp, e);
    }

    if (lr) {
        addClass(lr, "pa-dr-last");
    }
    addClass(elt, "pa-markdown");
    removeClass(elt, "need-markdown");
    highlight_fences_when_ready(elt);
});

Filediff.define_method("unmarkdown", function () {
    const elt = this.element;
    let e = elt.firstChild;
    while (e) {
        var n = e.nextSibling;
        if (hasClass(e, "pa-dlr")) {
            elt.removeChild(e);
        } else if (hasClass(e, "pa-gi") || hasClass(e, "pa-gc")) {
            e.hidden = false;
        }
        e = n;
    }
    removeClass(elt, "pa-markdown");
});

Filediff.define_method("highlight", function () {
    const elt = this.element;
    // compute language
    let lang;
    if (!(lang = elt.getAttribute("data-language"))) {
        let file = this.file;
        if (/\.(?:cc|cpp|hh|hpp|c\+\+|h\+\+|C|H)$/.test(file)) {
            lang = "c++";
        } else if (/\.(?:c|h)$/.test(file)) {
            lang = "c";
        }
        lang && elt.setAttribute("data-language", lang);
    }
    if (!lang || hasClass(elt, "pa-markdown")) {
        return;
    }
    if (!supports_language(lang)) {
        // The highlighter and/or grammar may still be loading. Keep the
        // `need-highlight` marker and retry once available; give up if the
        // language is not highlightable at all.
        if (can_support(lang)) {
            ensure_language(lang).then(() => {
                if (hasClass(elt, "need-highlight")) {
                    this.highlight();
                }
            });
        } else {
            removeClass(elt, "need-highlight");
        }
        return;
    }
    // collect content; thread grammar state separately for the a-side
    // (context + deleted) and b-side (context + inserted)
    const langclass = "language-" + lang;
    let e = elt.firstChild, statei = null, stated = null;
    for (; e; e = e.nextSibling) {
        const type = hasClass(e, "pa-gi") ? 2 : (hasClass(e, "pa-gc") ? 3 : (hasClass(e, "pa-gd") ? 1 : 0));
        if (type === 0) {
            continue;
        }
        const ce = e.lastChild,
            ishl = hasClass(ce, langclass),
            s = ishl ? ce.getAttribute("data-pa-text") : ce.textContent,
            result = highlight_line(lang, s, type & 2 ? statei : stated);
        if (type & 1) {
            stated = result.state;
        }
        if (type & 2) {
            statei = result.state;
        }
        if (ishl) {
            continue;
        }
        ce.setAttribute("data-pa-text", s);
        ce.innerHTML = result.html;
        addClass(ce, langclass);
        if (result.kind === "blank") {
            addClass(e, "pa-gblank");
        } else if (result.kind === "comment") {
            addClass(e, "pa-gcomment");
        }
    }
    addClass(elt, "pa-highlight");
    removeClass(elt, "need-highlight");
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

Filediff.add_decorator(function (fd) {
    if (hasClass(fd.element, "need-markdown")) {
        fd.markdown();
    } else if (hasClass(fd.element, "need-highlight")) {
        fd.highlight();
    }
});


handle_ui.on("pa-diff-toggle-markdown", function (evt) {
    const $es = evt.metaKey ? $(".pa-diff-toggle-markdown") : $(this),
        fd = Filediff.referenced(this),
        show = !hasClass(fd.element, "pa-markdown");
    $es.each(function () {
        const fd = Filediff.referenced(this),
            shown = hasClass(fd.element, "pa-markdown");
        if (show && !shown) {
            fd.markdown();
        } else if (!show && shown) {
            fd.unmarkdown();
        }
        toggleClass(this, "btn-primary", show);
    });
    if (!evt.metaKey) {
        $.post(hoturl("=api/diffconfig", {psetinfo: fd.element}),
            {file: fd.file, markdown: show ? 1 : 0});
    }
});
