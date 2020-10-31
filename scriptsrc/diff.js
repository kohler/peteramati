// diff.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, removeClass, toggleClass, fold61, handle_ui,
         ImmediatePromise } from "./ui.js";
import { hoturl, hoturl_gradeparts } from "./hoturl.js";
import { html_id_encode, html_id_decode } from "./encoders.js";

export function fileref_resolve(e) {
    var fd = e.closest(".pa-filediff");
    return fd || document.getElementById(e.closest(".pa-fileref").getAttribute("data-pa-fileid"));
}

export function filediff_load(filee) {
    if (hasClass(filee, "need-load")) {
        let p = filee.closest(".pa-psetinfo");
        return new Promise(function (resolve, reject) {
            $.ajax(hoturl("api/filediff", hoturl_gradeparts(filee)), {
                type: "GET", cache: false, dataType: "json",
                data: {
                    "file": html_id_decode(filee.id.substr(8)),
                    "base_hash": p.getAttribute("data-pa-base-hash"),
                    "hash": p.getAttribute("data-pa-hash")
                },
                success: function (data) {
                    if (data.ok && data.content_html) {
                        var $h = $(data.content_html);
                        $(filee).html($h.html());
                    }
                    removeClass(filee, "need-load");
                    resolve(filee);
                }
            });
        });
    } else {
        return new ImmediatePromise(filee);
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
        return filediff_load(filee).then(_ => {
            // look for present line
            const $tds = $(filee).find(".pa-d" + lineid.charAt(0)),
                lineno = lineid.substr(1);
            for (let i = 0; i < $tds.length; ++i) {
                if ($tds[i].getAttribute("data-landmark") === lineno) {
                    $tds[i].id = lineref;
                    return $tds[i].id;
                }
            }
            // XXX missing: expand context lines
            // look for absent line with present linenote
            const $dls = $(filee).find(".pa-dl[data-landmark='" + lineid + "']");
            if ($dls.length)
                return $dls[0];
            // give up
            throw null;
        });
    } else {
        return Promise.reject(null);
    }
}

export function linediff_find(filename, lineid) {
    var e = null;
    linediff_find_promise(filename, lineid).then(ee => e = ee, null);
    return e;
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

    var thisline = tr.closest(".pa-dl"),
        nearline = linediff_traverse(tr, down, 1),
        filediff;
    if (!nearline || !(filediff = nearline.closest(".pa-filediff"))) {
        return null;
    }

    var file = filediff.getAttribute("data-pa-file"),
        result = {ufile: file, file: file, tr: nearline},
        user = filediff.getAttribute("data-pa-file-user");
    if (user) {
        result.ufile = user + "-" + file;
    }

    var lm;
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
        do {
            nearline = nearline.nextSibling;
        } while (nearline
                 && (nearline.nodeType !== Node.ELEMENT_NODE
                     || hasClass(nearline, "pa-gn")
                     || !nearline.offsetParent));
        if (nearline && hasClass(nearline, "pa-gw")) {
            result.notetr = nearline;
        }
    }
    return result;
}


handle_ui.on("pa-diff-unfold", function (evt) {
    if (evt.metaKey) {
        $(".pa-diff-unfold").each(function () {
            fold61(fileref_resolve(this), this, false);
        });
    } else {
        filediff_load(fileref_resolve(this)).then(f => fold61(f, this));
    }
    return false;
});

handle_ui.on("pa-diff-toggle-hide-left", function (evt) {
    var f = fileref_resolve(this), show = hasClass(f, "pa-hide-left");
    if (evt.metaKey) {
        $(".pa-diff-toggle-hide-left").each(function () {
            toggleClass(fileref_resolve(this), "pa-hide-left", !show);
            toggleClass(this, "btn-primary", show);
        });
    } else {
        toggleClass(f, "pa-hide-left", !show);
        toggleClass(this, "btn-primary", show);
    }
});

handle_ui.on("pa-goto", function () {
    $(".pa-line-highlight").removeClass("pa-line-highlight");
    linediff_find_promise(this, null).then(ref => {
        $(ref).closest(".pa-filediff").removeClass("hidden");
        let $e = $(ref).closest(".pa-dl");
        $e.addClass("pa-line-highlight");
        window.scrollTo(0, Math.max($e.geometry().top - Math.max(window.innerHeight * 0.1, 24), 0));
        push_history_state(this.href);
    }, null);
});
