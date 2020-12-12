// gc-checkbox.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { GradeEntry } from "./gradeentry.js";
import { hasClass, handle_ui } from "./ui.js";


GradeClass.add("checkbox", {
    text: function (v) {
        if (v == null || v === 0) {
            return "–";
        } else if (v == (this.max || 1)) {
            return "✓";
        } else {
            return "" + v;
        }
    },
    simple_text: GradeClass.basic_text,
    tcell: function (v) {
        if (v == null || v === 0) {
            return "";
        } else if (v == (this.max || 1)) {
            return "✓";
        } else {
            return "" + v;
        }
    },
    entry: function (id, opts) {
        let t = '<span class="pa-gradewidth"><input type="checkbox" class="ui pa-gradevalue ml-0" name="'.concat(this.key, '" id="', id, '" value="', this.max, '"></span>');
        if (opts.editable) {
            t = t.concat(' <span class="pa-gradedesc">of ', this.max, ' <a href="" class="x ui pa-grade-uncheckbox" tabindex="-1">#</a></span>');
        }
        return t;
    },
    justify: "center",
    reflect_value: function (elt, v, opts) {
        const want_checkbox = v == null || v === "" || v === 0 || (this && v === this.max);
        if (!want_checkbox && elt.type === "checkbox") {
            Checkbox_GradeClass.uncheckbox(elt);
        } else if (want_checkbox && elt.type !== "checkbox" && opts.reset) {
            Checkbox_GradeClass.recheckbox(elt);
        }
        if (elt.type === "checkbox") {
            elt.checked = !!v;
            elt.indeterminate = opts.mixed;
        } else if (elt.value !== v && (opts.reset || !$(elt).is(":focus"))) {
            elt.value = "" + v;
        }
    }
});

export class Checkbox_GradeClass {
    static uncheckbox(element) {
        const ge = GradeEntry.closest(element);
        if (element.type === "checkbox") {
            element.value = element.checked ? ge.max : "";
        }
        element.type = "text";
        element.className = (hasClass(element, "ui") || hasClass(element, "uich") ? "uich " : "") + "pa-gradevalue pa-gradewidth";
        const container = element.closest(".pa-pd");
        $(container).find(".pa-grade-uncheckbox").remove();
        $(container).find("input[name^=\"" + element.name + ":\"]").addClass("hidden");
    }
    static recheckbox(element) {
        const v = element.value.trim(), ge = GradeEntry.closest(element);
        element.type = "checkbox";
        element.checked = v !== "" && v !== "0";
        element.value = ge.max;
        element.className = (hasClass(element, "uich") ? "ui " : "") + "pa-gradevalue";
        $(element.closest(".pa-pd")).find(".pa-gradedesc").append(' <a href="" class="x ui pa-grade-uncheckbox" tabindex="-1">#</a>');
    }
}

handle_ui.on("pa-grade-uncheckbox", function () {
    $(this.closest(".pa-pd")).find(".pa-gradevalue").each(function () {
        Checkbox_GradeClass.uncheckbox(this);
        this.focus();
        this.select();
    });
});
