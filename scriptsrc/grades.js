// grades.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hoturl, hoturl_gradeparts, api_conditioner } from "./hoturl.js";

export function grades_fetch() {
    var p = this.closest(".pa-psetinfo");
    api_conditioner(hoturl("api/grade", hoturl_gradeparts(p)), null, "GET")
        .then(function (data) {
            if (data && data.ok) {
                $(p).data("pa-gradeinfo", data).each($pa.loadgrades);
            }
        });
}
