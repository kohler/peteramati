// grade-ui.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hoturl } from "./hoturl.js";
import { api_conditioner } from "./xhr.js";
import { GradeSheet } from "./gradeentry.js";
import { handle_ui } from "./ui.js";

export function grades_fetch(p) {
    api_conditioner(hoturl("api/grade", {psetinfo: p}), null, "GET")
        .then(function (data) {
            if (data && data.ok) {
                GradeSheet.store(p, data);
            }
        });
}

function toggle_grade_update() {
    const gelt = this.closest(".pa-grade");
    gelt.removeAttribute("data-pa-gv");
    this.classList.toggle("pa-has-grade-update");
    this.classList.toggle("pa-is-grade-update");
    GradeSheet.closest(this).update_at(gelt);
}

handle_ui.on("pa-has-grade-update", toggle_grade_update);
handle_ui.on("pa-is-grade-update", toggle_grade_update);
