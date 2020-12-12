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

    add: (name, x) => {
        x.type = name;
        x.text = x.text || GradeClass.basic_text;
        x.simple_text = x.simple_text || x.text;
        x.tcell = x.tcell || x.text;
        x.reflect_value = x.reflect_value || GradeClass.basic_reflect_value;
        map[name] = x;
    },
    find: name => map[name] || map.numeric
};

GradeClass.add("numeric", { entry: GradeClass.basic_entry });

GradeClass.add("formula", { text: function (v) { return v == null ? "" : v.toFixed(1); } });

GradeClass.add("text", {
    entry: function (id) {
        return '<textarea class="uich pa-pd pa-gradevalue need-autogrow" name="'.concat(this.key, '" id="', id, '"></textarea>');
    },
    justify: "left",
    sort: "forward"
});

GradeClass.add("shorttext", {
    entry: function (id) {
        return '<textarea class="uich pa-pd pa-gradevalue need-autogrow" rows="1" name="'.concat(this.key, '" id="', id, '"></textarea>');
    },
    justify: "left",
    sort: "forward"
});

GradeClass.add("section", {
    text: () => "",
    entry: () => ""
});
