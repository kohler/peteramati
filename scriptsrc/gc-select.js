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
