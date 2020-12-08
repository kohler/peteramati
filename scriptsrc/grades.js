// grades.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hoturl, hoturl_gradeparts } from "./hoturl.js";
import { api_conditioner } from "./xhr.js";
import { GradeSheet } from "./gradeentry.js";

export function grades_fetch() {
    var p = this.closest(".pa-psetinfo");
    api_conditioner(hoturl("api/grade", hoturl_gradeparts(p)), null, "GET")
        .then(function (data) {
            if (data && data.ok) {
                $(p).data("pa-gradeinfo", new GradeSheet(data)).each($pa.loadgrades);
            }
        });
}
