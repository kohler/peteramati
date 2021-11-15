// gc-multicheckbox.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { Checkbox_GradeClass } from "./gc-checkbox.js";
import { handle_ui } from "./ui.js";


function make_multicheckbox(mark) {
    return {
        text: function (v) {
            if (v == null || v === 0) {
                return "–";
            } else if (v > 0 && Math.abs(v - Math.round(v)) < 0.05) {
                return mark.repeat(Math.round(v));
            } else {
                return "" + v;
            }
        },
        simple_text: GradeClass.basic_text,
        tcell: function (v) {
            if (v == null || v === 0) {
                return "";
            } else if (v > 0 && Math.abs(v - Math.round(v)) < 0.05) {
                return mark.repeat(Math.round(v));
            } else {
                return "" + v;
            }
        },
        mount_edit: function (elt, id) {
            let t = '<span class="pa-gradewidth"><input type="hidden" class="uich pa-gradevalue" name="'.concat(this.key, '">');
            for (let i = 0; i < this.max; ++i) {
                t = t.concat('<input type="checkbox" class="ui js-multicheckbox-grade ml-0" name="', this.key, ':', i, '" value="1"');
                if (i === this.max - 1) {
                    t += ' id="' + id + '"';
                }
                t += '>';
            }
            return t.concat('</span>  <span class="pa-gradedesc">of ', this.max, ' <a href="" class="x ui pa-grade-uncheckbox" tabindex="-1">#</a></span>');
        },
        update_edit: function (elt, v, opts) {
            const want_checkbox = v == null || v === "" || v === 0
                    || (v >= 0 && (!this || v <= this.max) && Math.abs(v - Math.round(v)) < 0.05),
                ve = elt.firstChild.firstChild;
            if (!want_checkbox && ve.type === "hidden") {
                Checkbox_GradeClass.uncheckbox(ve);
            } else if (want_checkbox && ve.type !== "hidden" && opts.reset) {
                Checkbox_GradeClass.recheckbox(ve);
            }
            if (ve.value !== v && (opts.reset || !$(ve).is(":focus"))) {
                ve.value = v;
            }
            const name = ve.name + ":", value = Math.round(v || 0);
            $(elt).find("input[type=checkbox]").each(function () {
                if (this.name.startsWith(name)) {
                    const i = +this.name.substring(name.length);
                    this.checked = i < value;
                    this.indeterminate = opts.mixed;
                }
            });
        },
        justify: "left"
    };
}

GradeClass.add("checkboxes", make_multicheckbox("✓"));
GradeClass.add("stars", make_multicheckbox("⭐"));

handle_ui.on("js-multicheckbox-grade", function () {
    const colon = this.name.indexOf(":"),
        name = this.name.substring(0, colon),
        num = this.name.substring(colon + 1),
        elt = this.closest("form").elements[name],
        v = this.checked ? (+num + 1) + "" : num;
    if (elt.value !== v) {
        elt.value = v;
        $(elt).trigger("change");
    }
});
