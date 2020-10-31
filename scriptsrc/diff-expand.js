// diff-expand.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { linediff_locate } from "./diff.js";
import { hoturl, hoturl_gradeparts } from "./hoturl.js";
import { handle_ui } from "./ui.js";

handle_ui.on("pa-gx", function (evt) {
    var contextrow = evt.currentTarget;
    var panal = linediff_locate(contextrow, false);
    while (panal && !panal.bline) {
        panal = linediff_locate(panal.tr, false);
    }
    var nanal = linediff_locate(contextrow, true);
    if (!panal && !nanal) {
        return;
    }
    var paline = panal ? panal.aline + 1 : 1;
    var pbline = panal ? panal.bline + 1 : 1;
    var lbline = nanal ? nanal.bline : 0;
    if (nanal && nanal.aline <= 1) {
        return;
    }
    var args = {file: (panal || nanal).file, fromline: pbline};
    if (lbline) {
        args.linecount = lbline - pbline;
    }
    $.ajax(hoturl("api/blob", hoturl_gradeparts(this, args)), {
        success: function (data) {
            if (data.ok && data.data) {
                var lines = data.data.replace(/\n$/, "").split("\n");
                for (var i = lines.length - 1; i >= 0; --i) {
                    var t = '<div class="pa-dl pa-gc"><div class="pa-da" data-landmark="' +
                        (paline + i) + '"></div><div class="pa-db" data-landmark="' +
                        (pbline + i) + '"></div><div class="pa-dd"></div></div>';
                    $(t).insertAfter(contextrow).find(".pa-dd").text(lines[i]);
                }
                $(contextrow).remove();
            }
        }
    });
    return true;
});
