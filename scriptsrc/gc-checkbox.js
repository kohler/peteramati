// gc-checkbox.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { GradeEntry } from "./gradeentry.js";
import { hasClass, handle_ui } from "./ui.js";


GradeClass.add("checkbox", {
    text: function (v) {
        if (v == null || v === 0 || v === false) {
            return "–";
        } else if (v === (this.max || 1) || v === true) {
            return "✓";
        } else {
            return "" + v;
        }
    },
    simple_text: GradeClass.basic_text,
    tcell: function (v) {
        if (v == null || v === 0 || v === false) {
            return "";
        } else if (v === (this.max || 1) || v === true) {
            return "✓";
        } else {
            return "" + v;
        }
    },
    mount_edit: function (elt, id) {
        return '<span class="pa-gradewidth"><input type="checkbox" class="ui pa-gradevalue ml-0" name="'.concat(
            this.key, '" id="', id, '" value="', this.max, '"></span>',
            ' <span class="pa-gradedesc">of ', this.max, ' <a href="" class="x ui pa-grade-uncheckbox" tabindex="-1">#</a></span>');
    },
    update_edit: function (elt, v, opts) {
        const want_checkbox = v == null || v === "" || v === 0 || (this && v === this.max),
            ve = elt.firstChild.firstChild;
        if (!want_checkbox && ve.type === "checkbox") {
            Checkbox_GradeClass.uncheckbox(ve);
        } else if (want_checkbox && ve.type !== "checkbox" && opts.reset) {
            Checkbox_GradeClass.recheckbox(ve);
        }
        if (ve.type === "checkbox") {
            ve.checked = !!v;
            ve.indeterminate = !!opts.mixed;
        } else if (ve.value !== v && (opts.reset || !$(ve).is(":focus"))) {
            ve.value = "" + v;
        }
    },
    justify: "center"
});

export class Checkbox_GradeClass {
    static uncheckbox(element) {
        const ge = GradeEntry.closest(element);
        if (element.type === "checkbox") {
            element.value = element.checked ? ge.max : "";
        }
        element.type = "text";
        element.className = (hasClass(element, "ui") || hasClass(element, "uich") ? "uich " : "") + "pa-gradevalue pa-gradewidth";
        const container = element.closest(".pa-pv");
        $(container).find(".pa-grade-uncheckbox").remove();
        $(container).find("input[name^=\"" + element.name + ":\"]").addClass("hidden");
    }
    static recheckbox(element) {
        const v = element.value.trim(), ge = GradeEntry.closest(element);
        element.type = "checkbox";
        element.checked = v !== "" && v !== "0";
        element.value = ge.max;
        element.className = (hasClass(element, "uich") ? "ui " : "") + "pa-gradevalue";
        $(element.closest(".pa-pv")).find(".pa-gradedesc").append(' <a href="" class="x ui pa-grade-uncheckbox" tabindex="-1">#</a>');
    }
}

handle_ui.on("pa-grade-uncheckbox", function () {
    $(this.closest(".pa-pv")).find(".pa-gradevalue").each(function () {
        Checkbox_GradeClass.uncheckbox(this);
        this.focus();
        this.select();
    });
});
