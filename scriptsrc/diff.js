// diff.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { ImmediatePromise } from "./utils.js";
import { hasClass, addClass, removeClass, toggleClass, fold61, handle_ui, $e, with_scroll_anchor } from "./ui.js";
import { dropmenu } from "./dropmenu.js";
import { hoturl } from "./hoturl.js";
import { html_id_encode, html_id_decode } from "./encoders.js";


const BACKWARD = 1;
const ANYFILE = 2;
const GRADES = 4;
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
        let a = er.firstChild, fd;
        while (a && a.nodeName === "SPAN") {
            a = a.nextSibling;
        }
        if (a.tagName === "A") {
            fd = Filediff.by_hash(a.hash);
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
    static by_href(href) {
        const m = href.match(/^[^#]*#(?:L[ab]\d+|)([UF][-A-Za-z0-9_.@/]+)$/),
            e = m && m[1] ? document.getElementById(m[1]) : null;
        return e ? new Filediff(e) : null;
    }
    static add_decorator(f) {
        decorators.push(f);
    }
    load() {
        if (!hasClass(this.element, "need-load")) {
            return new ImmediatePromise(this);
        }
        const p = this.element.closest(".pa-psetinfo"),
            wdiff = hasClass(this.element, "pa-wdiff");
        removeClass(this.element, "need-load");
        return new Promise(resolve => {
            $.ajax(hoturl("api/filediff", {psetinfo: p, wdiff: wdiff ? 1 : null}), {
                type: "GET", cache: false, dataType: "json",
                data: {
                    file: this.file,
                    base_commit: p.getAttribute("data-pa-base-commit"),
                    commit: p.getAttribute("data-pa-commit")
                },
                success: data => {
                    if (data.ok && data.content_html) {
                        const result = $(data.content_html);
                        for (const k of ["data-pa-hlsummary-a", "data-pa-hlsummary-b"]) {
                            const v = result.attr(k);
                            if (v != null) {
                                this.element.setAttribute(k, v);
                            } else {
                                this.element.removeAttribute(k);
                            }
                        }
                        $(this.element).html(result.children());
                        this.decorate();
                        $pa.render_text_page();
                    }
                    resolve(this);
                }
            })
        });
    }
    load_if(show) {
        if (!show) {
            return new ImmediatePromise(this);
        }
        return this.load();
    }
    decorate() {
        removeClass(this.element, "need-decorate");
        for (let df of decorators) {
            df(this);
        }
    }
    static decorate_page() {
        for (const fdiff of document.querySelectorAll(".pa-filediff.need-decorate")) {
            (new Filediff(fdiff)).decorate();
        }
    }
    toggle(show) {
        if (show == null) {
            show = this.element.hidden;
        }
        fold61(this.element, this.header_element, show);
    }
    toggle_show_left(show) {
        if (show == null) {
            show = hasClass(this.element, "pa-hide-left");
        }
        toggleClass(this.element, "pa-hide-left", !show);
        $(this.element.previousSibling).find(".pa-diff-toggle-hide-left").toggleClass("btn-primary", show);
    }
    toggle_show_comments(show) {
        if (show == null) {
            show = hasClass(this.element, "pa-hide-comments");
        }
        toggleClass(this.element, "pa-hide-comments", !show);
        $(this.element.previousSibling).find(".pa-diff-toggle-hide-comments").toggleClass("btn-primary", !show);
    }
    get file() {
        const id = this.element.id;
        if (id.charCodeAt(0) === 85 /* U */) {
            const sl = id.indexOf("/F");
            return html_id_decode(id.substring(sl + 2));
        }
        return html_id_decode(id.substring(1));
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
            isb = isb.charCodeAt(0) === 98 /* b */;
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
    get psetinfo() {
        if (this._psetinfo === undefined) {
            this._psetinfo = this.element.closest(".pa-psetinfo");
        }
        return this._psetinfo;
    }
    get pset() {
        return this.psetinfo.getAttribute("data-pa-pset");
    }
    get repourl() {
        return this.psetinfo.getAttribute("data-pa-repourl");
    }
    get header_element() {
        const h3 = this.element.previousSibling;
        return h3 && hasClass(h3, "pa-fileref") ? h3 : null;
    }
    scroll_position() {
        const cr = this.element.getBoundingClientRect();
        let top = cr.y + window.scrollY;
        const header = this.header_element;
        if (header) {
            top -= header.getBoundingClientRect().height + 8;
        }
        return Math.max(top - 8, 0);
    }
    static define_method(name, f) {
        if (!Object.prototype.hasOwnProperty.call(Filediff.prototype, name)) {
            Object.defineProperty(Filediff.prototype, name, {
                value: f, enumerable: false, configurable: true, writable: true
            });
        }
    }
    static register_navbox_page() {
        for (const box of document.querySelectorAll(".pa-filenavbox")) {
            FilenavScrollspy.at(box);
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
    static closest(e) {
        const el = e.closest(".pa-dl");
        return el ? new Linediff(el) : null;
    }
    get nodeName() {
        return "PA-LINEDIFF";
    }
    get filediff() {
        if (this._filediff === undefined) {
            this._filediff = Filediff.closest(this.element);
        }
        return this._filediff;
    }
    get file() {
        return this.filediff.file;
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
    get linea() {
        const e = this.element;
        if (hasClass(e, "pa-gc") || (hasClass(e, "pa-gd") && !hasClass(e, "pa-dlr"))) {
            return +e.firstChild.getAttribute("data-landmark");
        } else {
            return this.linea_within(Infinity);
        }
    }
    get lineb() {
        const lineid = this.note_lineid;
        if (lineid.startsWith("b")) {
            return +lineid.substring(1);
        } else {
            return null;
        }
    }
    linea_within(bound) {
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
    get psetinfo() {
        return this.filediff.psetinfo;
    }
    get pset() {
        return this.filediff.pset;
    }
    get repourl() {
        return this.filediff.repourl;
    }
    get hash() {
        const e = this.element, fd = this.filediff, uf = fd.element.id;
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
        if (!m) {
            return false;
        }
        const delta = lineno - m[isb ? 2 : 1];
        return delta >= 0 && (!m[3] || delta < m[3]);
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
    static get GRADES() {
        return GRADES;
    }

    static* all(t, flags) {
        if (t instanceof Linediff) {
            t = t.element;
        }
        flags = flags || 0;
        let p = t.parentElement;
        const direction = flags & BACKWARD ? "previousSibling" : "nextSibling",
            extreme = flags & BACKWARD ? "lastChild" : "firstChild";
        while (true) {
            while (!t && p) {
                if (!(flags & ANYFILE) && hasClass(p, "pa-filediff")) {
                    return;
                }
                t = p[direction];
                p = p.parentElement;
            }
            if (!t) {
                return;
            } else if (t.nodeType !== Node.ELEMENT_NODE) {
                // skip
            } else if (hasClass(t, "pa-dl")) {
                yield new Linediff(t);
            } else if (hasClass(t, "pa-dg")) {
                p = t;
                t = p[extreme];
                continue;
            } else if (hasClass(t, "pa-grade")) {
                if (hasClass(t, "pa-ans")
                    && hasClass(t.lastChild.firstChild, "pa-filediff")) {
                    p = t.lastChild.firstChild;
                    t = p[extreme];
                    continue;
                } else if (flags & GRADES) {
                    const inp = t.firstChild.nextSibling.firstChild;
                    if ((inp.nodeName === "TEXTAREA" && hasClass(inp, "ta1"))
                        || (inp.nodeName === "INPUT" && inp.type === "text")) {
                        yield inp;
                    }
                }
            } else if (hasClass(t, "pa-diffcontext")) {
                if (!(flags & ANYFILE)) {
                    return;
                }
                const dg = t.querySelectorAll(".pa-dg");
                if (dg.length !== 0) {
                    let tt = dg[flags & BACKWARD ? dg.length - 1 : 0];
                    while (true) {
                        const ttp = tt.parentElement.closest(".pa-dg");
                        if (!ttp || !t.contains(ttp)) {
                            break;
                        }
                        tt = ttp;
                    }
                    t = tt;
                    p = t.parentElement;
                    continue;
                }
            }
            t = t[direction];
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
        show = fd.element.hidden,
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

handle_ui.on("pa-diff-toggle-hide-comments", function (evt) {
    const $es = evt.metaKey ? $(".pa-diff-toggle-hide-comments") : $(this),
        show = hasClass(Filediff.referenced(this).element, "pa-hide-comments");
    $es.each(function () { Filediff.referenced(this).toggle_show_comments(show); });
});

handle_ui.on("pa-filenav", function () {
    goto_hash(this.hash);
});

handle_ui.on("pa-filenav-all", function () {
    for (const e of this.closest("nav").querySelectorAll(".pa-filenav")) {
        const fd = Filediff.by_href(e.href);
        fd && fd.load().then(() => { fd.toggle(true); });
    }
});

function filenav_dir_collect(container, recurse, fds) {
    for (let e = container.firstElementChild; e; e = e.nextElementSibling) {
        if (hasClass(e, "pa-filenav")) {
            const fd = Filediff.by_href(e.href);
            fd && fds.push(fd);
        } else if (hasClass(e, "pa-filenav-dir") && recurse) {
            filenav_dir_collect(e, recurse, fds);
        }
    }
}

// Folding a filenav directory folds the matching diffs to match. The `toggle`
// event does not bubble, so listen in the capture phase.
document.addEventListener("toggle", function (evt) {
    const details = evt.target;
    if (!hasClass(details, "pa-filenav-dir")) {
        return;
    }
    // Workaround: Safari (2026) fires `toggle` on initial page load. Don't process it
    const initial_open = details.hasAttribute("data-initial-open"), open = details.open;
    initial_open && details.removeAttribute("data-initial-open");
    if (open && initial_open) {
        return;
    }
    const fds = [];
    filenav_dir_collect(details, !open, fds);
    if (!fds.length) {
        return;
    }
    with_scroll_anchor(fds[0].element, fds[fds.length - 1].element, () => {
        for (const fd of fds) {
            if (open && hasClass(fd.element, "need-load")) {
                fd.load().then(() => { fd.toggle(true); });
            } else {
                fd.toggle(open);
            }
        }
    });
}, true);

// Scroll-spy: as the document scrolls, highlight the file navigator entries for
// every file currently intersecting the viewport, and keep the topmost such
// entry visible within the (scrollable) navigator box.

// One scroll-spy per navigator box, shared by every file in that box. The
// decorator below grows the spy a section at a time as each file is decorated.
const filenav_scrollspies = new Map();

class FilenavScrollspy {
    constructor(box) {
        this.box = box;
        this.by_dst = new WeakMap();  // dst heading -> ref
        this.dirs = new Map();        // directory <details> in nav -> {s, nvisible, visible, p}
        this.refs = [];
        this.connect_hint = 0;
        this.first_visible = this.refs.length;
        this.observer = new IntersectionObserver(this.update.bind(this), {threshold: 0});
        for (const a of box.querySelectorAll("a.pa-filenav")) {
            let i = this.refs.length;
            this.refs.push({dst: null, ref: a, visible: false, i: i, p: null});
            if (a.parentElement.nodeName === "DETAILS") {
                this.refs[i].p = this.dir(a.parentElement);
            }
            let dst = Filediff.by_hash(a.hash);
            dst && this.connect(dst);
        }
    }
    static at(box) {
        let spy = filenav_scrollspies.get(box);
        if (!spy) {
            spy = new FilenavScrollspy(box);
            filenav_scrollspies.set(box, spy);
        }
        return spy;
    }
    dir(details) {
        let dir = this.dirs.get(details);
        if (!dir) {
            dir = {s: details.firstElementChild, visible: false, nvisible: 0, p: null};
            this.dirs.set(details, dir);
            if (details.parentElement.nodeName === "DETAILS") {
                dir.p = this.dir(details.parentElement);
            }
        }
        return dir;
    }
    connect(fd) {
        if (!fd.header_element || this.by_dst.has(fd.header_element)) {
            return;
        }
        // find ref element pointing to `fd``
        const search = "#" + fd.element.id;
        let refidx = this.connect_hint;
        if (refidx >= this.refs.length
            || this.refs[refidx].ref.getAttribute("href") !== search) {
            refidx = 0;
            while (refidx < this.refs.length
                   && this.refs[refidx].ref.getAttribute("href") !== search) {
                ++refidx;
            }
        }
        // connect it
        if (refidx < this.refs.length) {
            this.by_dst.set(fd.header_element, this.refs[refidx]);
            this.observer.observe(fd.header_element);
            this.connect_hint = refidx + 1;
        }
    }
    update(entries) {
        let min = this.refs.length, max = 0;
        for (const entry of entries) {
            const ref = this.by_dst.get(entry.target);
            if (ref && ref.visible !== entry.isIntersecting) {
                ref.visible = entry.isIntersecting;
                for (let p = ref.p; p; p = p.p) {
                    p.nvisible += entry.isIntersecting ? 1 : -1;
                }
                min = ref.i < min ? ref.i : min;
                max = ref.i > max ? ref.i : max;
                if (ref.visible && ref.i < this.first_visible) {
                    this.first_visible = ref.i;
                }
            }
        }
        for (; min <= max; ++min) {
            toggleClass(this.refs[min].ref, "pa-filenav-active", this.refs[min].visible);
        }
        for (const dir of this.dirs.values()) {
            if (dir.visible !== (dir.nvisible > 0)) {
                dir.visible = dir.nvisible > 0;
                toggleClass(dir.s, "pa-filenav-active", dir.visible);
            }
        }
        while (this.first_visible < this.refs.length
               && !this.refs[this.first_visible].visible) {
            ++this.first_visible;
        }
        if (this.first_visible < this.refs.length) {
            this.reveal(this.refs[this.first_visible]);
        }
    }
    reveal(ref) {
        if (this.box.scrollHeight <= this.box.clientHeight) {
            return;
        }
        let repr = ref.ref;
        for (let p = ref.p; p && repr.offsetParent === null; p = p.p) {
            repr = p.s;
        }
        if (!repr || repr.offsetParent === null) {
            return;
        }
        const boxrect = this.box.getBoundingClientRect(),
            reprrect = repr.getBoundingClientRect();
        if (reprrect.top >= boxrect.top && reprrect.bottom <= boxrect.bottom) {
            return;
        }
        const target = this.box.scrollTop
            + (reprrect.top - boxrect.top)
            - (this.box.clientHeight - reprrect.height) / 2;
        this.box.scrollTop = Math.max(0, Math.min(target, this.box.scrollHeight - this.box.clientHeight));
    }
}

if (window.IntersectionObserver) {
    Filediff.add_decorator(function (fd) {
        for (const spy of filenav_scrollspies.values()) {
            spy.connect(fd);
        }
    });
}


function goto_hash(hash) {
    const m = hash.match(/^[^#]*#(L[ab]\d+|)((?:U[-A-Za-z0-9_.@]+\/|)F[-A-Za-z0-9_.@/]+)$/),
        lineid = m && m[1] ? m[1].substring(1) : null,
        fd = m ? Filediff.by_hash("#" + m[2]) : null;
    if (!fd) {
        return;
    }
    if (!lineid) {
        fd.load().then(() => {
            fd.toggle(true);
            window.scrollTo(0, fd.scroll_position());
        }).catch(() => {
            window.console && window.console.error(`File \`${m[2]}\` not loadable`);
        });
        return;
    }
    fd.line(lineid).then(ln => {
        fd.toggle(true);
        const e = ln.visible_predecessor().element;
        hasClass(e, "pa-gd") && fd.toggle_show_left(true);
        addClass(e, "pa-line-highlight");
        window.scrollTo(0, Math.max($(e).geometry().top - Math.max(window.innerHeight * 0.1, 24), 0));
    }).catch(() => {
        window.console && window.console.error(`Line \`${m[1]}${m[2]}\` not loadable`);
    });
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
$(Filediff.register_navbox_page);


function diffmany_dropmenu_toggle() {
    const h3 = this.closest(".pa-fileref");
    if (this.open) {
        h3 && (h3.style.zIndex = 2);
        const menu = this.lastElementChild.firstChild;
        $(menu).awaken().find(".want-focus").focus();
    } else {
        h3 && (h3.style.zIndex = null);
        this.removeEventListener("toggle", diffmany_dropmenu_toggle);
    }
}

dropmenu.add_builder("pa-dropmenu-diffmany", function () {
    const psim = this.closest(".pa-diffset"),
        pme = this.closest(".pa-psetinfo"),
        menu = $e("ul", "dropmenu need-dropmenu-events");
    for (let e = psim.firstElementChild; e; e = e.nextElementSibling) {
        menu.appendChild($e("li", "has-link",
            $e("a", {href: "#" + e.id, class: "dropmenu-close" + (e === pme ? " want-focus" : ""), role: "menuitem"}, e.getAttribute("data-pa-user"))));
    }
    const details = this.closest("details");
    details.lastElementChild.replaceChildren(menu);
    details.addEventListener("toggle", diffmany_dropmenu_toggle);
});
