// gc.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { addClass } from "./ui.js";
import { strftime, sec2text } from "./utils.js";

let map = {};

const color_map = {
    "red": "cR", "orange": "cO", "yellow": "cY", "green": "cG",
    "blue": "cB", "purple": "cP", "gray": "cA", "grey": "cA"
};

export const GradeClass = {
    basic_text: v => (v == null ? "" : "" + v),

    basic_mount_edit: function (elt, id, opts) {
        let x;
        if (opts && opts.max_text) {
            x = opts.max_text;
        } else if (this.max) {
            x = 'of ' + this.max;
        } else {
            x = '';
        }
        if (window.$pa.long_page) {
            return '<textarea class="ta1 uich pa-gradevalue pa-gradewidth" name="'.concat(this.key, '" id="', id, '" rows="1" wrap="none" cols="10000"></textarea> <span class="pa-gradedesc">', x, '</span>');
        } else {
            return '<input type="text" class="uich pa-gradevalue pa-gradewidth" name="'.concat(this.key, '" id="', id, '"> <span class="pa-gradedesc">', x, '</span>');
        }
    },

    basic_mount_show: function (elt, id) {
        elt.id = id;
        const es = [document.createElement("span")];
        es[0].className = "pa-gradevalue pa-gradewidth";
        if (this.max && this.type !== "letter") {
            es.push(" ", document.createElement("span"));
            es[2].className = "pa-gradedesc";
            es[2].append("of " + this.max);
        }
        elt.replaceChildren(...es);
    },

    basic_update_edit: function (elt, v, opts) {
        const ve = elt.querySelector(".pa-gradevalue"),
            gt = this.simple_text(v);
        if ($(ve).val() !== gt && (opts.reset || !$(ve).is(":focus"))) {
            $(ve).val(gt);
        }
        if (opts.mixed != null) {
            opts.mixed ? ve.setAttribute("placeholder", "Mixed") : ve.removeAttribute("placeholder");
        }
    },

    basic_configure_column: function (col, pconf) {
        col.ge = this;
        col.gabbr = this.abbr();
        col.justify = this.gc.justify || "right";
        col.sort_forward = this.gc.sort === "forward";
        const justify = this.gc.justify || "right";
        col.className = (col.gkey === pconf.total_key ? "gt-total" : "gt-grade") +
            (justify === "left" ? " l" : " r");
        if (this.table_color && color_map[this.table_color]) {
            col.className += " " + color_map[this.table_color];
        }
        col.make_compare = sort => this.gc.make_compare.call(this, col, sort);
        return col;
    },

    basic_make_compare: function (col) {
        const gidx = col.gidx, erev = this.gc.sort === "forward" ? 1 : -1;
        return function (a, b) {
            const ag = a.grades && a.grades[gidx],
                bg = b.grades && b.grades[gidx];
            if (ag === "" || ag == null || bg === "" || bg == null) {
                if (ag !== "" && ag != null) {
                    return -erev;
                } else if (bg !== "" && bg != null) {
                    return erev;
                }
            } else if (ag < bg) {
                return -1;
            } else if (ag > bg) {
                return 1;
            }
            return erev * a._sort_user.localeCompare(b._sort_user);
        };
    },

    basic_tcell_width: function () {
        return 3;
    },

    add: (name, x) => {
        x.type = name;
        x.type_tabular = x.type_tabular == null ? true : x.type_tabular;
        x.text = x.text || GradeClass.basic_text;
        x.simple_text = x.simple_text || x.text;
        x.configure_column = x.configure_column || GradeClass.basic_configure_column;
        x.tcell_width = x.tcell_width || GradeClass.basic_tcell_width;
        x.tcell = x.tcell || x.text;
        x.make_compare = x.make_compare || GradeClass.basic_make_compare;
        x.mount_show = x.mount_show || GradeClass.basic_mount_show;
        x.mount_edit = x.mount_edit || GradeClass.basic_mount_edit;
        x.update_edit = x.update_edit || GradeClass.basic_update_edit;
        map[name] = x;
    },

    find: name => map[name] || map.numeric
};


GradeClass.add("numeric", {
    mount_edit: GradeClass.basic_mount_edit
});

GradeClass.add("formula", {
    text: function (v) {
        if (v == null || v === false) {
            return "";
        } else if (v === true) {
            return "✓";
        } else {
            const t = v.toFixed(1);
            return t.endsWith(".0") ? t.substring(0, t.length - 2) : t;
        }
    }
});

GradeClass.add("time", {
    text: function (v) {
        if (v == null || v === 0) {
            return "";
        } else {
            return strftime("%Y-%m-%d %H:%M", v);
        }
    }
});

GradeClass.add("duration", {
    text: function (v) {
        if (v == null) {
            return "";
        } else {
            return sec2text(v);
        }
    }
});

GradeClass.add("text", {
    mount_show: function (elt, id) {
        elt.id = id;
        addClass(elt, "pa-textv");
        addClass(elt, "pa-gradevalue");
    },
    mount_edit: function (elt, id) {
        addClass(elt, "pa-textv");
        return '<textarea class="uich pa-gradevalue need-autogrow" name="'.concat(this.key, '" id="', id, '"></textarea>');
    },
    justify: "left",
    sort: "forward",
    type_tabular: false
});

GradeClass.add("shorttext", {
    mount_show: function (elt, id) {
        elt.id = id;
        addClass(elt, "pa-gradevalue");
    },
    mount_edit: function (elt, id) {
        return '<textarea class="uich pa-gradevalue need-autogrow" rows="1" name="'.concat(this.key, '" id="', id, '"></textarea>');
    },
    justify: "left",
    sort: "forward",
    type_tabular: false
});

GradeClass.add("section", {
    text: () => "",
    mount_show: function (elt, id) {
        elt.id = id;
        addClass(elt.closest(".pa-p"), "pa-p-section");
    },
    mount_edit: function (elt) {
        addClass(elt.closest(".pa-p"), "pa-p-section");
    },
    update_show: function () {
        return false;
    },
    type_tabular: false
});
