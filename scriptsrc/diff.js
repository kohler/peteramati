// diff.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, removeClass, toggleClass, fold61, handle_ui,
         ImmediatePromise } from "./ui.js";
import { hoturl, hoturl_gradeparts } from "./hoturl.js";
import { html_id_decode } from "./encoders.js";

export function resolve_fileref(e) {
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

handle_ui.on("pa-unfold-file-diff", function (evt) {
    if (evt.metaKey) {
        $(".pa-unfold-file-diff").each(function () {
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
