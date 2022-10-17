// gc.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { addClass, handle_ui, input_set_default_value } from "./ui.js";
import { event_key } from "./ui-key.js";
import { strftime, sec2text } from "./utils.js";

let map = {};

const color_map = {
    "red": "cR", "orange": "cO", "yellow": "cY", "green": "cG",
    "blue": "cB", "purple": "cP", "gray": "cA", "grey": "cA"
};

export const GradeClass = {
    basic_text: v => (v == null ? "" : "" + v),

    basic_mount_edit: function (elt, id, opts) {
        let sp = document.createElement("span");
        sp.className = "pa-gradedesc";
        if (opts && opts.max_text) {
            sp.append(opts.max_text);
        } else if (this.max) {
            sp.append('of ' + this.max);
        }
        const df = new DocumentFragment;
        let e;
        if (window.$pa.long_page) {
            e = document.createElement("textarea");
            e.className = "ta1 uich pa-gradevalue pa-gradewidth";
            e.setAttribute("rows", 1);
            e.setAttribute("cols", 10000);
            e.setAttribute("wrap", "none");
        } else {
            e = document.createElement("input");
            e.type = "text";
            e.className = "uich pa-gradevalue pa-gradewidth";
        }
        e.name = this.key;
        e.id = id;
        e.disabled = this.disabled;
        df.append(e, " ", sp);
        return df;
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
        input_set_default_value(ve, gt);
        ve.value = gt;
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
        const ta = document.createElement("textarea");
        ta.className = "uich pa-gradevalue need-autogrow";
        ta.name = this.key;
        ta.id = id;
        ta.disabled = this.disabled;
        return ta;
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
        const ta = document.createElement("textarea");
        ta.className = "uich pa-gradevalue need-autogrow";
        ta.setAttribute("rows", 1);
        ta.name = this.key;
        ta.id = id;
        ta.disabled = this.disabled;
        return ta;
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


function sidebar_tab_traverse(e, bwd) {
    let sb = e.closest(".pa-sidebar"),
        sbtabs = sb.querySelectorAll(".pa-sidebar-tab"),
        pos = Array.prototype.indexOf.call(sbtabs, e);
    if (pos < 0) {
        return null;
    }
    let allsb, sbpos;
    while (true) {
        pos += bwd ? -1 : 1;
        if (pos < 0 || pos >= sbtabs.length) {
            if (allsb == null) {
                allsb = document.querySelectorAll(".pa-sidebar");
                sbpos = Array.prototype.indexOf.call(allsb, sb);
                if (sbpos < 0) {
                    return null;
                }
            }
            sbpos += bwd ? -1 : 1;
            if (sbpos >= 0 && sbpos < allsb.length) {
                sb = allsb[sbpos];
                sbtabs = sb.querySelectorAll(".pa-sidebar-tab");
                pos = bwd ? sbtabs.length : -1;
            } else {
                return null;
            }
        } else if (sbtabs[pos].offsetParent) {
            return sbtabs[pos];
        }
    }
}

handle_ui.on("keydown.pa-sidebar-tab", function (event) {
    if (event_key(event) === "Tab"
        && !event.ctrlKey
        && !event.altKey
        && !event.metaKey) {
        const e = sidebar_tab_traverse(this, event.shiftKey);
        if (e) {
            e.focus();
            if (e.setSelectionRange) {
                e.setSelectionRange(0, e.value.length);
            }
            event.preventDefault();
        }
    }
});
