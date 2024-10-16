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
        const none = document.createElement("option");
        none.value = "";
        none.append("None");
        sel.append(none);
        for (let i = 0; i !== this.options.length; ++i) {
            const opt = document.createElement("option");
            opt.value = this.options[i];
            opt.append(this.options[i]);
            sel.append(opt);
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
                const opt = document.createElement("option");
                opt.value = "";
                opt.append("Mixed");
                ve.insertBefore(opt, ve.firstChild);
            }
            ve.selectedIndex = 0;
        } else if (gt !== "") {
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
        for (let opt of this.options) {
            w = Math.max(w, opt.length);
        }
        return Math.max(GradeClass.basic_tcell_width.call(this, col),
                        Math.floor(Math.min(w, 10) * 1.25) / 2);
    },
    justify: "left",
    sort: "forward"
});
