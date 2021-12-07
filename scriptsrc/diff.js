// diff.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { ImmediatePromise } from "./utils.js";
import { hasClass, addClass, removeClass, toggleClass, fold61, handle_ui } from "./ui.js";
import { hoturl } from "./hoturl.js";
import { html_id_encode, html_id_decode } from "./encoders.js";


const BACKWARD = 1;
const ANYFILE = 2;
const decorators = [];

export class Filediff {
    constructor(e) {
        if (e.nodeType !== Node.ELEMENT_NODE || !hasClass(e, "pa-filediff")) {
            throw new Error;
        }
        this.element = e;
    }
    static closest(e) {
        const ed = e.closest(".pa-filediff");
        return ed ? new Filediff(ed) : null;
    }
    static referenced(e) {
        const er = e.closest(".pa-fileref");
        let fd;
        if (er.firstChild.tagName === "A") {
            fd = Filediff.by_hash(er.firstChild.hash);
        }
        return fd || Filediff.closest(e);
    }
    static by_file(fn) {
        const e = document.getElementById("F" + html_id_encode(fn));
        return e ? new Filediff(e) : null;
    }
    static by_hash(hash) {
        let e;
        if (hash.startsWith("#F") || hash.startsWith("#U")) {
            e = document.getElementById(hash.substring(1));
        }
        return e ? new Filediff(e) : null;
    }
    static add_decorator(f) {
        decorators.push(f);
    }
    load() {
        if (!hasClass(this.element, "need-load")) {
            return new ImmediatePromise(this);
        } else {
            const p = this.element.closest(".pa-psetinfo"),
                wdiff = hasClass(this.element, "pa-wdiff");
            removeClass(this.element, "need-load");
            return new Promise(resolve => {
                $.ajax(hoturl("api/filediff", {psetinfo: p, wdiff: wdiff ? 1 : null}), {
                    type: "GET", cache: false, dataType: "json",
                    data: {
                        file: this.file,
                        base_hash: p.getAttribute("data-pa-base-hash"),
                        hash: p.getAttribute("data-pa-hash")
                    },
                    success: data => {
                        if (data.ok && data.content_html) {
                            const result = $(data.content_html);
                            $(this.element).html(result.children());
                            this.decorate();
                        }
                        resolve(this);
                    }
                })
            });
        }
    }
    decorate() {
        for (let df of decorators) {
            df(this);
        }
    }
    static decorate_page() {
        $(".pa-filediff.need-decorate").each(function () {
            removeClass(this, "need-decorate");
            if (!hasClass(this, "need-load")) {
                (new Filediff(this)).decorate();
            }
        });
    }
    toggle(show) {
        if (show == null) {
            show = hasClass(this.element, "hidden");
        }
        const h3 = this.element.previousSibling,
            h3fd = h3 && hasClass(h3, "pa-fileref") ? Filediff.referenced(h3) : null,
            isarrow = h3fd && h3fd.element === this.element;
        fold61(this.element, isarrow ? h3 : null, show);
    }
    toggle_show_left(show) {
        if (show == null) {
            show = hasClass(this.element, "pa-hide-left");
        }
        toggleClass(this.element, "pa-hide-left", !show);
        $(this.element.previousSibling).find(".pa-diff-toggle-hide-left").toggleClass("btn-primary", show);
    }
    get file() {
        const id = this.element.id;
        if (id.charAt(0) === "U") {
            const sl = id.indexOf("/F");
            return html_id_decode(id.substring(sl + 2));
        } else {
            return html_id_decode(id.substring(1));
        }
    }
    lineid_anchor(lineid) {
        return "L".concat(lineid, this.element.id);
    }
    lines() {
        return Linediff.all(this.element.firstChild);
    }
    line(isb, lineno, start) {
        if (lineno == null) {
            lineno = +isb.substring(1);
            isb = isb.charAt(0) === "b";
        }
        return this.load().then(() => {
            for (let ln of Linediff.all(start || this.element.firstChild)) {
                if (ln.base_contains(isb, lineno)) {
                    return ln;
                } else if (ln.expansion_contains(isb, lineno)) {
                    return ln.expand().then(start => this.line(isb, lineno, start));
                }
            }
            throw null;
        });
    }
    static define_method(name, f) {
        if (!Object.prototype.hasOwnProperty.call(Filediff.prototype, name)) {
            Object.defineProperty(Filediff.prototype, name, {
                value: f, enumerable: false, configurable: true, writable: true
            });
        }
    }
}

export class Linediff {
    constructor(e) {
        if (e.nodeType !== Node.ELEMENT_NODE || !hasClass(e, "pa-dl")) {
            throw new Error;
        }
        this.element = e;
    }
    get file() {
        return Filediff.closest(this.element).file;
    }
    get note_lineid() {
        const e = this.element;
        let re, lm, dash;
        if (e.hasAttribute("data-landmark")) {
            return e.getAttribute("data-landmark");
        } else if (hasClass(e, "pa-dlr")
                   && (re = e.lastChild.firstChild)
                   && (lm = re.getAttribute("data-landmark"))
                   && (dash = lm.indexOf("-")) >= 0) {
            return (hasClass(e, "pa-gd") ? "a" : "b").concat(lm.substring(dash + 1));
        } else if (hasClass(e, "pa-gd")) {
            return "a".concat(e.firstChild.getAttribute("data-landmark"));
        } else {
            return "b".concat(e.firstChild.nextSibling.getAttribute("data-landmark"));
        }
    }
    get aline() {
        return this.aline_within(Infinity);
    }
    aline_within(bound) {
        let e = this.element;
        while (e && bound >= 0) {
            if (hasClass(e, "pa-gc")) {
                return +e.firstChild.getAttribute("data-landmark");
            }
            e = e.previousSibling;
            --bound;
        }
        return 0;
    }
    get hash() {
        const e = this.element, fd = Filediff.closest(e), uf = fd.element.id;
        if (e.hasAttribute("data-landmark")) {
            return "#L".concat(e.getAttribute("data-landmark"), uf);
        } else if (hasClass(e, "pa-gd")) {
            return "#La".concat(e.firstChild.getAttribute("data-landmark"), uf);
        } else if (hasClass(e, "pa-gi") || hasClass(e, "pa-gc")) {
            return "#Lb".concat(e.firstChild.nextSibling.getAttribute("data-landmark"), uf);
        } else {
            return null;
        }
    }
    is_visible() {
        return !!this.element.offsetParent;
    }
    visible_predecessor() {
        if (!this.element.offsetParent) {
            for (let e = this.element.previousSibling; e; e = e.previousSibling) {
                if (hasClass(e, "pa-dlr")) {
                    return new Linediff(e);
                }
            }
        }
        return this;
    }
    visible_source() {
        for (let ln of Linediff.all(this, BACKWARD)) {
            if (ln.is_visible() && ln.is_source())
                return ln;
        }
        return null;
    }
    is_base() {
        return /^pa-dl pa-g[idc]/.test(this.element.className);
    }
    base_contains(isb, lineno) {
        const e = this.element;
        return /^pa-dl pa-g[idc]/.test(e.className)
            && (isb ? e.firstChild.nextSibling : e.firstChild).getAttribute("data-landmark") == lineno;
    }
    is_source() {
        return / pa-g[idc]/.test(this.element.className);
    }
    is_expandable() {
        return this.element.hasAttribute("data-expandmark");
    }
    expansion_contains(isb, lineno) {
        const em = this.element.getAttribute("data-expandmark"),
            m = em ? em.match(/^a(\d+)b(\d+)\+(\d*)$/) : null;
        if (m) {
            const delta = m[isb ? 2 : 1] - lineno;
            return delta >= 0 && (!m[3] || delta < m[3]);
        } else {
            return false;
        }
    }
    expand() {
        const e = this.element,
            em = e.getAttribute("data-expandmark"),
            m = em ? em.match(/^a(\d+)b(\d+)\+(\d*)$/) : null;
        if (!m || m[3] === "0") {
            return new ImmediatePromise(this); // xxx
        }
        e.removeAttribute("data-expandmark");
        const a0 = +m[1], b0 = +m[2], args = {
            psetinfo: this.element, file: this.file, fromline: b0
        };
        m[3] !== "" && (args.linecount = +m[3]);
        return new Promise(resolve => {
            $.ajax(hoturl("api/blob", args), {
                success: function (data) {
                    if (data.ok && data.data) {
                        const lines = data.data.replace(/\n$/, "").split("\n");
                        for (let i = lines.length - 1; i >= 0; --i) {
                            const t = '<div class="pa-dl pa-gc"><div class="pa-da" data-landmark="'.concat(a0 + i, '"></div><div class="pa-db" data-landmark="', b0 + i, '"></div><div class="pa-dd"></div></div>');
                            $(t).insertAfter(e).find(".pa-dd").text(lines[i]);
                        }
                        const next = e.nextSibling;
                        $(e).remove();
                        const fd = Filediff.closest(next);
                        if (hasClass(fd.element, "pa-highlight")) {
                            fd.highlight();
                        }
                        resolve(new Linediff(next));
                    }
                }
            });
        });
    }
    is_annotation() {
        return hasClass(this.element, "pa-gn");
    }
    is_note() {
        return hasClass(this.element, "pa-gw");
    }
    upper_bound(isb, lineno) {
        if (lineno == null) {
            lineno = +isb.substring(1);
            isb = isb.charAt(0) === "b";
        }
        let match = false;
        for (let ln of Linediff.all(this)) {
            const e = ln.element;
            if (match && (hasClass(e, "pa-gx") || hasClass(e, "pa-dlr"))) {
                return ln;
            } else if (ln.is_source()) {
                const curlineno = +(isb ? e.firstChild.nextSibling : e.firstChild).getAttribute("data-landmark");
                if ((!curlineno && match) || lineno < curlineno) {
                    return ln;
                } else if (lineno === curlineno && ln.is_base()) {
                    match = true;
                }
            } else if (e.hasAttribute("data-landmark")) {
                const curlm = e.getAttribute("data-landmark");
                if (curlm.charAt(0) === (isb ? "b" : "a")
                    && lineno < +curlm.substring(1)) {
                    return ln;
                }
            }
        }
        return null;
    }

    static get BACKWARD() {
        return BACKWARD;
    }
    static get ANYFILE() {
        return ANYFILE;
    }

    static* all(t, flags) {
        if (t instanceof Linediff) {
            t = t.element;
        }
        flags = flags || 0;
        let p = t.parentElement;
        const direction = flags & BACKWARD ? "previousSibling" : "nextSibling";
        while (true) {
            while (!t && p) {
                if (!(flags & ANYFILE) && hasClass(p, "pa-filediff")) {
                    return;
                }
                t = p[direction];
                p = p.parentElement;
            }
            if (!t) {
                break;
            } else if (t.nodeType !== Node.ELEMENT_NODE) {
                t = t[direction];
            } else if (hasClass(t, "pa-dg")) {
                p = t;
                t = p[flags & BACKWARD ? "lastChild" : "firstChild"];
            } else {
                if (hasClass(t, "pa-dl")) {
                    yield new Linediff(t);
                }
                t = t[direction];
            }
        }
    }

    static* range(t, lo, hi, selector) {
        let linea = -1, lineb = -1;
        for (let ln of Linediff.all(t)) {
            const e = ln.element;
            if (!hasClass(e, "pa-dlr")) {
                const c = e.firstChild;
                if (hasClass(c, "pa-da")) {
                    if (c.hasAttribute("data-landmark")) {
                        linea = +c.getAttribute("data-landmark");
                    }
                    if (c.nextSibling.hasAttribute("data-landmark")) {
                        lineb = +c.getAttribute("data-landmark");
                    }
                } else if (e.hasAttribute("data-landmark")) {
                    const lm = e.getAttribute("data-landmark");
                    if (lm.charAt(0) === "a") {
                        linea = +lm.substring(1);
                    } else {
                        lineb = +lm.substring(1);
                    }
                }
                if (linea > hi) {
                    break;
                } else if (linea >= lo && (!selector || e.matches(selector))) {
                    ln.linea = linea;
                    ln.lineb = lineb;
                    yield ln;
                }
            }
        }
    }
}


handle_ui.on("pa-diff-unfold", function (evt) {
    const $es = evt.metaKey ? $(".pa-diff-unfold") : $(this),
        fd = Filediff.by_hash(this.hash),
        show = hasClass(fd.element, "hidden"),
        direction = evt.metaKey ? true : show;
    $es.each(function () {
        Filediff.by_hash(this.hash).load().then(fd => fd.toggle(direction));
    });
    if (!evt.metaKey) {
        $.post(hoturl("=api/diffconfig", {psetinfo: fd.element, file: fd.file, collapse: show ? 0 : 1}));
    }
});

handle_ui.on("pa-diff-toggle-hide-left", function (evt) {
    const $es = evt.metaKey ? $(".pa-diff-toggle-hide-left") : $(this),
        show = hasClass(Filediff.referenced(this).element, "pa-hide-left");
    $es.each(function () { Filediff.referenced(this).toggle_show_left(show); });
});

function goto_hash(hash) {
    let m, lineid, fd;
    if ((m = hash.match(/^[^#]*(#(?:U[-A-Za-z0-9_.@]+\/|)F[-A-Za-z0-9_.@\/]+)$/))) {
        fd = Filediff.by_hash(m[1]);
    } else if ((m = hash.match(/^[^#]*#L([ab]\d+)((?:U[-A-Za-z0-9_.@]+\/|)F[-A-Za-z0-9_.@\/]+)$/))) {
        fd = Filediff.by_hash("#" + m[2]);
        lineid = m[1];
    }
    if (fd && lineid) {
        fd.line(lineid).then(ln => {
            fd.toggle(true);
            const e = ln.visible_predecessor().element;
            hasClass(e, "pa-gd") && fd.toggle_show_left(true);
            addClass(e, "pa-line-highlight");
            window.scrollTo(0, Math.max($(e).geometry().top - Math.max(window.innerHeight * 0.1, 24), 0));
        }).catch(null);
    } else if (fd) {
        fd.toggle(true);
        window.scrollTo(0, Math.max($(fd.element).geometry().top - Math.max(window.innerHeight * 0.1, 24), 0));
    }
}

if (!hasClass(document.body, "want-grgraph-hash")) {
    $(window).on("popstate", function (event) {
        const state = (event.originalEvent || event).state;
        state && state.href && goto_hash(state.href);
    }).on("hashchange", function () {
        goto_hash(location.href);
    });
    $(function () { goto_hash(location.href); });
}

handle_ui.on("pa-gx", function (evt) {
    new Linediff(evt.currentTarget).expand();
});

$(Filediff.decorate_page);
