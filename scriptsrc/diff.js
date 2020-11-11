// diff.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { ImmediatePromise } from "./utils.js";
import { hasClass, addClass, removeClass, toggleClass, fold61, handle_ui } from "./ui.js";
import { push_history_state } from "./ui-history.js";
import { hoturl, hoturl_gradeparts } from "./hoturl.js";
import { html_id_encode, html_id_decode } from "./encoders.js";


export class Filediff {
    constructor(e) {
        if (e.nodeType !== Node.ELEMENT_NODE || !hasClass(e, "pa-filediff")) {
            throw new Error;
        }
        this.element = e;
    }
    static find(e) {
        if (typeof e === "string") {
            e = document.getElementById("F_" + html_id_encode(e));
        } else {
            e = e.closest(".pa-filediff")
                || document.getElementById(e.closest(".pa-fileref").getAttribute("data-pa-fileid"));
        }
        return e ? new Filediff(e) : null;
    }
    load() {
        if (!hasClass(this.element, "need-load")) {
            return new ImmediatePromise(this);
        } else {
            const p = this.element.closest(".pa-psetinfo");
            removeClass(this.element, "need-load");
            return new Promise(resolve => {
                $.ajax(hoturl("api/filediff", hoturl_gradeparts(this.element)), {
                    type: "GET", cache: false, dataType: "json",
                    data: {
                        file: html_id_decode(this.element.id.substr(2)),
                        base_hash: p.getAttribute("data-pa-base-hash"),
                        hash: p.getAttribute("data-pa-hash")
                    },
                    success: data => {
                        if (data.ok && data.content_html) {
                            const result = $(data.content_html);
                            $(this.element).html(result.children());
                        }
                        resolve(this);
                    }
                })
            });
        }
    }
    toggle(show) {
        if (show == null) {
            show = hasClass(this.element, "hidden");
        }
        const h3 = this.element.previousSibling,
            isarrow = h3 && h3.getAttribute("data-pa-fileid") === this.element.id;
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
        return this.element.getAttribute("data-pa-file");
    }
    expand(ctx) {
        const em = ctx.getAttribute("data-expandmark"),
            m = em ? em.match(/^a(\d+)b(\d+)\+(\d*)$/) : null;
        if (!m) {
            return new ImmediatePromise(ctx.nextSibling); // xxx
        }
        ctx.removeAttribute("data-expandmark");
        const a0 = +m[1], b0 = +m[2], args = {file: this.file, fromline: b0};
        m[3] !== "" && (args.linecount = +m[3]);
        return new Promise(resolve => {
            $.ajax(hoturl("api/blob", hoturl_gradeparts(this.element, args)), {
                success: function (data) {
                    if (data.ok && data.data) {
                        const lines = data.data.replace(/\n$/, "").split("\n");
                        for (let i = lines.length - 1; i >= 0; --i) {
                            const t = '<div class="pa-dl pa-gc"><div class="pa-da" data-landmark="'.concat(a0 + i, '"></div><div class="pa-db" data-landmark="', b0 + i, '"></div><div class="pa-dd"></div></div>');
                            $(t).insertAfter(ctx).find(".pa-dd").text(lines[i]);
                        }
                        const next = ctx.nextSibling;
                        $(ctx).remove();
                        resolve(next);
                    }
                }
            });
        });
    }
    line(lineid) {
        return this.load().then(() => {
            const isb = lineid.charAt(0) === "b", line = lineid.substring(1);
            let dp = this.element, dl = this.element.firstChild, em, m;
            while (true) {
                while (!dl && dp !== this) {
                    dl = dp.nextSibling;
                    dp = dp.parentElement;
                }
                if (!dl) {
                    throw new Error;
                } else if (dl.nodeType !== Node.ELEMENT_NODE) {
                    dl = dl.nextSibling;
                } else if (/^pa-dl pa-g[idc]/.test(dl.className)
                           && line === (isb ? dl.firstChild.nextSibling : dl.firstChild).getAttribute("data-landmark")) {
                    return dl;
                } else if ((em = dl.getAttribute("data-expandmark"))
                           && (m = em.match(/^a(\d+)b(\d+)\+(\d*)$/))) {
                    const delta = line - (isb ? m[2] : m[1]);
                    if (delta >= 0 && (m[3] === "" || delta < m[3])) {
                        return this.expand(dl).then(() => this.line(lineid));
                    }
                    dl = dl.nextSibling;
                } else if (hasClass(dl, "pa-dg")) {
                    dp = dl;
                    dl = dp.firstChild;
                } else {
                    dl = dl.nextSibling;
                }
            }
        }).then(dl => {
            if (!dl.offsetParent) {
                for (let e = dl.previousSibling; e; e = e.previousSibling) {
                    if (hasClass(e, "pa-dlr")) {
                        return e;
                    }
                }
            }
            return dl;
        });
    }
}


function linediff_find_promise(filename, lineid) {
    // decode arguments: either (lineref) or (filename, lineid)
    if (lineid == null) {
        if (filename instanceof Node) {
            filename = filename.hash;
        }
        var m = filename.match(/^#?L([ab]\d+)_(.*)$/);
        if (!m) {
            return Promise.reject(null);
        }
        filename = m[2];
        lineid = m[1];
    } else {
        if (filename instanceof Node) {
            var node = filename;
            while (node && !node.hasAttribute("data-pa-file")) {
                node = node.parentElement;
            }
            if (!node) {
                return Promise.reject(null);
            }
            filename = node.getAttribute("data-pa-file");
            if (node.hasAttribute("data-pa-file-user")) {
                filename = node.getAttribute("data-pa-file-user") + "-" + filename;
            }
        }
        filename = html_id_encode(filename);
    }

    // check lineref
    var lineref = "L" + lineid + "_" + filename;
    var e = document.getElementById(lineref);
    if (e) {
        return new ImmediatePromise(e);
    }

    // create link
    var filee = document.getElementById("pa-file-" + filename);
    if (filee) {
        return new Filediff(filee).load().then(() => {
            // look for present line
            const $tds = $(filee).find(".pa-d" + lineid.charAt(0)),
                lineno = lineid.substr(1);
            for (let i = 0; i < $tds.length; ++i) {
                if ($tds[i].getAttribute("data-landmark") === lineno) {
                    $tds[i].id = lineref;
                    return $tds[i];
                }
            }
            // XXX missing: expand context lines
            // look for absent line with present linenote
            const $dls = $(filee).find(".pa-dl[data-landmark='" + lineid + "']");
            if ($dls.length) {
                return $dls[0];
            }
            // give up
            throw null;
        });
    } else {
        return Promise.reject(null);
    }
}

export function linediff_find(filename, lineid) {
    var e = null;
    linediff_find_promise(filename, lineid).then((ee => e = ee), null);
    return e;
}

export function linediff_lineid(elt) {
    let e, lm, dash;
    if (hasClass(elt, "pa-gd")) {
        return "a" + elt.firstChild.getAttribute("data-landmark");
    } else if (hasClass(elt, "pa-dlr")
               && (e = elt.lastChild.firstChild)
               && (lm = e.getAttribute("data-landmark"))
               && (dash = lm.indexOf("-")) >= 0) {
        return "b" + lm.substring(dash + 1);
    } else {
        return "b" + elt.firstChild.nextSibling.getAttribute("data-landmark");
    }
}

// linediff_traverse(tr, down, flags)
//    Find the diff line (pa-d[idc]) near `tr` in the direction of `down`.
//    If `down === null`, look up *starting* from `tr`.
//    Flags: 1 means stay within the current file; otherwise traverse
//    between files. 2 means return all lines.
export function linediff_traverse(tr, down, flags) {
    tr = tr.closest(".pa-dl, .pa-dg, .pa-filediff");
    var tref = tr ? tr.parentElement : null, direction;
    if (down == null) {
        down = false;
        direction = "previousSibling";
    } else {
        direction = down ? "nextSibling" : "previousSibling";
        if (hasClass(tr, "pa-dl")) {
            tr = tr[direction];
        }
    }
    while (true) {
        while (!tr && tref) {
            if ((flags & 1) && hasClass(tref, "pa-filediff")) {
                return null;
            }
            tr = tref[direction];
            tref = tref.parentElement;
        }
        if (!tr) {
            return null;
        } else if (tr.nodeType !== Node.ELEMENT_NODE) {
            tr = tr[direction];
        } else if (hasClass(tr, "pa-dl")
                   && ((flags & 2) || / pa-g[idc]/.test(tr.className))
                   && tr.offsetParent) {
            return tr;
        } else if (hasClass(tr, "pa-dg") || hasClass(tr, "pa-filediff")) {
            tref = tr;
            tr = tref[down ? "firstChild" : "lastChild"];
        } else {
            tr = tr[direction];
        }
    }
}

// linediff_locate(tr, down)
//    Analyze a click on `tr`. Returns `null` if the target
//    is a <textarea> or <a>.
export function linediff_locate(tr, down) {
    if (!tr
        || tr.tagName === "TEXTAREA"
        || tr.tagName === "A") {
        return null;
    }

    const thisline = tr.closest(".pa-dl");
    let nearline = linediff_traverse(tr, down, 1), filediff;
    if (!nearline || !(filediff = nearline.closest(".pa-filediff"))) {
        return null;
    }

    const file = filediff.getAttribute("data-pa-file"),
        result = {ufile: file, file: file, tr: nearline},
        user = filediff.getAttribute("data-pa-file-user");
    if (user) {
        result.ufile = user + "-" + file;
    }

    let lm;
    if (thisline
        && (lm = thisline.getAttribute("data-landmark"))
        && /^[ab]\d+$/.test(lm)) {
        result[lm.charAt(0) + "line"] = +lm.substring(1);
        result.lineid = lm;
    } else {
        result.aline = +nearline.firstChild.getAttribute("data-landmark");
        result.bline = +nearline.firstChild.nextSibling.getAttribute("data-landmark");
        result.lineid = result.bline ? "b" + result.bline : "a" + result.aline;
    }

    if (thisline && hasClass(thisline, "pa-gw")) {
        result.notetr = thisline;
    } else {
        while (true) {
            nearline = nearline.nextSibling;
            if (!nearline) {
                break;
            } else if (nearline.nodeType === Node.ELEMENT_NODE) {
                if (hasClass(nearline, "pa-gw")) {
                    result.notetr = nearline;
                    break;
                } else if (nearline.offsetParent && !hasClass(nearline, "pa-gn")) {
                    break;
                }
            }
        }
    }
    return result;
}


handle_ui.on("pa-diff-unfold", function (evt) {
    const $es = evt.metaKey ? $(".pa-diff-unfold") : $(this),
        direction = evt.metaKey ? true : undefined;
    $es.each(function () {
        Filediff.find(this).load().then(fd => fd.toggle(direction));
    });
    return false;
});

handle_ui.on("pa-diff-toggle-hide-left", function (evt) {
    const $es = evt.metaKey ? $(".pa-diff-toggle-hide-left") : $(this),
        show = hasClass(Filediff.find(this).element, "pa-hide-left");
    $es.each(function () { Filediff.find(this).toggle_show_left(show); });
});

function goto_hash(hash) {
    let m, line, fd;
    if ((m = hash.match(/^[^#]*#F_([-A-Za-z0-9_.@\/]+)$/))) {
        fd = Filediff.find(html_id_decode(m[1]));
    } else if ((m = hash.match(/^[^#]*#L([ab]\d+)_([-A-Za-z0-9_.@\/]+)$/))) {
        fd = Filediff.find(html_id_decode(m[2]));
        line = m[1];
    }
    if (fd && line) {
        fd.line(line).then(dl => {
            fd.toggle(true);
            hasClass(dl, "pa-gd") && fd.toggle_show_left(true);
            addClass(dl, "pa-line-highlight");
            window.scrollTo(0, Math.max($(dl).geometry().top - Math.max(window.innerHeight * 0.1, 24), 0));
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
    const ctx = evt.currentTarget;
    Filediff.find(ctx).expand(ctx);
});
