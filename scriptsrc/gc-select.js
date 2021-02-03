// gc-select.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { escape_entities } from "./encoders.js";


GradeClass.add("select", {
    entry: function (id) {
        let t = '<span class="select"><select class="uich pa-gradevalue" name="'.concat(this.key, '" id="', id, '"><option value="">None</option>');
        for (let i = 0; i !== this.options.length; ++i) {
            const n = escape_entities(this.options[i]);
            t = t.concat('<option value="', n, '">', n, '</option>');
        }
        return t + '</select></span>';
    },
    configure_column: function (col, pconf) {
        col = GradeClass.basic_configure_column.call(this, col, pconf);
        col.className += " gt-el";
        return col;
    },
    tcell_width: function (col) {
        let w = 0;
        for (let opt of this.options) {
            w = Math.max(w, opt.length);
        }
        return Math.max(GradeClass.basic_tcell_width(col), Math.floor(Math.min(w, 10) * 1.25) / 2);
    },
    justify: "left",
    sort: "forward",
    reflect_value: function (elt, g, opts) {
        const gt = this.simple_text(g);
        if ($(elt).val() !== gt && (opts.reset || !$(elt).is(":focus"))) {
            $(elt).val(gt);
        }
        if (opts.reset && opts.mixed) {
            if (elt.options[0].value !== "") {
                $(elt).prepend('<option value="">Mixed</option>');
            }
            elt.selectedIndex = 0;
        } else if (gt !== "") {
            elt.remove(0);
        }
    }
});
