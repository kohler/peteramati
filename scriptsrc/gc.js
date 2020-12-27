// gc.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

let map = {};

export const GradeClass = {
    basic_text: v => (v == null ? "" : "" + v),

    basic_entry: function (id, opts) {
        let x;
        if (opts.max_text) {
            x = opts.max_text;
        } else if (this.max) {
            x = 'of ' + this.max;
        } else {
            x = '';
        }
        return '<span class="pa-gradewidth"><input type="text" class="uich pa-gradevalue pa-gradewidth" name="'.concat(this.key, '" id="', id, '"></span> <span class="pa-gradedesc">', x, '</span>');
    },

    basic_reflect_value: function (elt, g, opts) {
        const gt = this.simple_text(g);
        if ($(elt).val() !== gt && (opts.reset || !$(elt).is(":focus"))) {
            $(elt).val(gt);
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

    add: (name, x) => {
        x.type = name;
        x.type_tabular = x.type_tabular == null ? true : x.type_tabular;
        x.text = x.text || GradeClass.basic_text;
        x.simple_text = x.simple_text || x.text;
        x.configure_column = x.configure_column || GradeClass.basic_configure_column;
        x.tcell_width = x.tcell_width || GradeClass.basic_tcell_width;
        x.tcell = x.tcell || x.text;
        x.make_compare = x.make_compare || GradeClass.basic_make_compare;
        x.reflect_value = x.reflect_value || GradeClass.basic_reflect_value;
        map[name] = x;
    },

    find: name => map[name] || map.numeric
};

GradeClass.add("numeric", {
    entry: GradeClass.basic_entry
});

GradeClass.add("formula", {
});

GradeClass.add("text", {
    entry: function (id) {
        return '<textarea class="uich pa-pd pa-gradevalue need-autogrow" name="'.concat(this.key, '" id="', id, '"></textarea>');
    },
    justify: "left",
    sort: "forward",
    type_tabular: false
});

GradeClass.add("shorttext", {
    entry: function (id) {
        return '<textarea class="uich pa-pd pa-gradevalue need-autogrow" rows="1" name="'.concat(this.key, '" id="', id, '"></textarea>');
    },
    justify: "left",
    sort: "forward",
    type_tabular: false
});

GradeClass.add("section", {
    text: () => "",
    entry: () => "",
    type_tabular: false
});
