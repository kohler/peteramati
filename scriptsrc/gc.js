// gc.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { addClass, hasClass, input_set_default_value } from "./ui.js";
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
            e.className = "ta1 uich uii pa-gradevalue pa-gradewidth pa-fresh";
            e.setAttribute("rows", 1);
            e.setAttribute("cols", 10000);
            e.setAttribute("wrap", "none");
        } else {
            e = document.createElement("input");
            e.type = "text";
            e.className = "uich uii pa-gradevalue pa-gradewidth pa-fresh";
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

    basic_unmount: function (elt) {
        elt.remove();
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

    basic_configure_column: function (col) {
        col.ge = this;
        col.gabbr = this.abbr();
        col.justify = this.gc.justify || "right";
        col.sort_forward = this.gc.sort === "forward";
        const justify = this.gc.justify || "right";
        if (col.gkey === col.ptconf.total_key) {
            col.className = this.readonly ? "gt-total" : "gt-total gt-grade";
        } else {
            col.className = this.readonly ? "gt-rograde" : "gt-grade";
        }
        col.className += justify === "left" ? " l" : " r";
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
        x.unmount = x.unmount || GradeClass.basic_unmount;
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
    },
    tcell_width: 6
});

GradeClass.add("duration", {
    text: function (v) {
        if (v == null) {
            return "";
        } else {
            return sec2text(v, "quarterhour");
        }
    },
    tcell_width: 6
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
        ta.className = "uich uii pa-gradevalue need-autogrow pa-fresh";
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
        ta.className = "uich uii pa-gradevalue need-autogrow pa-fresh";
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
    update_edit: function () {
        return false;
    },
    type_tabular: false
});

GradeClass.add("none", {
    mount_show: function (elt, id) {
    },
    mount_edit: function (elt) {
    },
    update_show: function () {
        return false;
    },
    update_edit: function () {
        return false;
    },
    type_tabular: false
});


const DGSELECTOR = ".pa-dg, .pa-gradelist";

function tabgroup_traverse(sb, bwd, sidebar) {
    const dir = bwd ? "previousElementSibling" : "nextElementSibling",
        childdir = bwd ? "lastElementChild" : "firstElementChild";
    if (sidebar) {
        sb = sb.closest(DGSELECTOR);
    }
    let parent = sb.parentElement, ch, down = false;
    while (parent) {
        if (!down) {
            sb = sb[dir];
        }
        down = false;
        if (!sb) {
            sb = parent;
            parent = sb.parentElement;
        } else if (hasClass(sb, "pa-dg") || hasClass(sb, "pa-gradelist")) {
            if (sidebar) {
                if ((ch = sb.querySelector(".pa-sidebar"))) {
                    return ch;
                }
            } else {
                ch = sb.querySelectorAll(".pa-gradevalue");
                if (ch.length > 0) {
                    return ch[bwd ? ch.length - 1 : 0].closest(DGSELECTOR);
                }
            }
        } else if (hasClass(sb, "pa-psetinfo")) {
            if ((ch = sb[childdir])) {
                parent = sb;
                sb = ch;
                down = true;
            }
        }
    }
    return null;
}

function tab_traverse(e, bwd, sidebar) {
    let sb = e.closest(sidebar ? ".pa-sidebar" : DGSELECTOR),
        sbtabs = sb.querySelectorAll(".pa-gradevalue"),
        pos = Array.prototype.indexOf.call(sbtabs, e);
    if (pos < 0) {
        return null;
    }
    while (true) {
        pos += bwd ? -1 : 1;
        if (pos < 0 || pos >= sbtabs.length) {
            if (!(sb = tabgroup_traverse(sb, bwd, sidebar))) {
                return null;
            }
            sbtabs = sb.querySelectorAll(".pa-gradevalue");
            pos = bwd ? sbtabs.length : -1;
        } else {
            const ch = sbtabs[pos], tn = ch.tagName;
            if (ch.offsetParent
                && (tn === "INPUT" || tn === "TEXTAREA" || tn === "SELECT" || tn === "BUTTON")
                && (sidebar || !ch.closest(".pa-sidebar"))) {
                return ch;
            }
        }
    }
}

document.body.addEventListener("keydown", function (evt) {
    if (evt.key !== "Tab"
        || !hasClass(evt.target, "pa-gradevalue")
        || evt.ctrlKey
        || evt.metaKey) {
        return;
    }
    let dest;
    if (evt.target.closest(".pa-sidebar")) {
        dest = tab_traverse(evt.target, evt.shiftKey, true);
    } else if (evt.altKey) {
        dest = tab_traverse(evt.target, evt.shiftKey, false);
    }
    if (dest) {
        dest.focus({focusVisible: true});
        if (dest.type === "textarea" || dest.type === "text") {
            dest.setSelectionRange(0, dest.value.length);
        }
        evt.preventDefault();
    }
});
