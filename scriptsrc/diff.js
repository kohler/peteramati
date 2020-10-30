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

handle_ui.on("pa-diff-unfold", function (evt) {
    if (evt.metaKey) {
        $(".pa-diff-unfold").each(function () {
            fold61(resolve_fileref(this), this, false);
        });
    } else {
        filediff_load(resolve_fileref(this)).then(f => fold61(f, this));
    }
    return false;
});

handle_ui.on("pa-diff-toggle-hide-left", function (evt) {
    var f = resolve_fileref(this), show = hasClass(f, "pa-hide-left");
    if (evt.metaKey) {
        $(".pa-diff-toggle-hide-left").each(function () {
            toggleClass(resolve_fileref(this), "pa-hide-left", !show);
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
