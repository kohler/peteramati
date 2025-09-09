// gc-select.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { input_set_default_value } from "./ui.js";


GradeClass.add("select", {
    mount_edit: function (elt, id) {
        const sel = document.createElement("select");
        sel.className = "uich pa-gradevalue pa-fresh";
        sel.name = this.key;
        sel.id = id;
        sel.disabled = this.disabled;
        const nonee = document.createElement("option");
        nonee.value = "";
        nonee.append("None");
        sel.append(nonee);
        for (const opt of this.options) {
            const oe = document.createElement("option");
            if (typeof opt === "object") {
                oe.value = opt.value;
                oe.append(opt.title || opt.value);
            } else {
                oe.value = opt;
                oe.append(opt);
            }
            sel.append(oe);
        }
        const sp = document.createElement("span");
        sp.className = "select";
        sp.append(sel);
        return sp;
    },
    update_edit: function (elt, v, opts) {
        const gt = this.simple_text(v),
            ve = elt.firstChild.firstChild;
        input_set_default_value(ve, gt);
        ve.value = gt;
        if (opts.reset && opts.mixed) {
            if (ve.options[0].value !== "") {
                const oe = document.createElement("option");
                oe.value = "";
                oe.append("Mixed");
                ve.insertBefore(oe, ve.firstChild);
            }
            ve.selectedIndex = 0;
        } else if (gt !== "" && ve.options[0].value === "") {
            ve.remove(0);
        }
    },
    configure_column: function (col) {
        col = GradeClass.basic_configure_column.call(this, col);
        col.className += " gt-el";
        return col;
    },
    tcell_width: function (col) {
        let w = 0;
        for (const opt of this.options) {
            let ot = typeof opt === "object" ? opt.title || opt.value : opt;
            w = Math.max(w, opt.toString().length);
        }
        return Math.max(GradeClass.basic_tcell_width.call(this, col),
                        Math.floor(Math.min(w, 10) * 1.25) / 2);
    },
    justify: "left",
    sort: "forward"
});
