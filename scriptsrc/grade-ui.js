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

function toggle_grade_history() {
    const gelt = this.closest(".pa-grade");
    this.classList.toggle("pa-grade-latest");
    this.classList.toggle("pa-grade-earlier");
    GradeSheet.closest(this).update_at(gelt);
}

handle_ui.on("pa-grade-latest", toggle_grade_history);
handle_ui.on("pa-grade-earlier", toggle_grade_history);
