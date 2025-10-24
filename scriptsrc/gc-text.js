// gc-text.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { render_onto } from "./render.js";
import { addClass } from "./ui.js";


function mount_edit(elt, id) {
    addClass(elt, "pa-textv");
    const ta = document.createElement("textarea");
    ta.className = "uich uii pa-gradevalue need-autogrow pa-fresh";
    if (this.type === "shorttext") {
        ta.setAttribute("rows", 1);
    }
    ta.name = this.key;
    ta.id = id;
    ta.disabled = this.disabled;
    return ta;
}

function update_show(ve, v, opts) {
    if (v == null || v === "") {
        ve.replaceChildren();
    } else if (this.answer) {
        opts.gradesheet.show_text_as_diff(ve, v, `/g/${this.key}`);
    } else {
        render_onto(ve, 0, v);
    }
    return ve.firstChild === null;
}

GradeClass.add("text", {
    mount_show: function (elt, id) {
        elt.id = id;
        addClass(elt, "pa-textv");
        addClass(elt, "pa-gradevalue");
    },
    mount_edit: mount_edit,
    update_show: update_show,
    justify: "left",
    sort: "forward",
    type_tabular: false
});

GradeClass.add("shorttext", {
    mount_show: function (elt, id) {
        elt.id = id;
        addClass(elt, "pa-gradevalue");
    },
    mount_edit: mount_edit,
    update_show: update_show,
    justify: "left",
    sort: "forward",
    type_tabular: false
});
