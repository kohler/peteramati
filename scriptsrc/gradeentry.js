// gradeentry.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities } from "./encoders.js";


export class GradeEntry {
    static id_counter = 0;

    constructor(x) {
        this.key = x.key;
        this.title = x.title;
        this.pos = x.pos || null;
        this.type = x.type || "numeric";
        this.options = x.options || null;
        this.round = x.round || null;
        this.max = x.max || null;
        this.in_total = x.in_total || null;
        this.is_extra = x.is_extra || null;
        this.landmark = x.landmark || null;
        this.landmark_range = x.landmark_range || null;
        this.visible = x.visible || null;
        this.student = x.student || null;
        this.edit_description = x.edit_description || null;
        this._abbr = null;
        this._all = null;
    }

    abbr() {
        this._abbr || this.compute_abbr();
        return this._abbr;
    }

    compute_abbr() {
        const order = this._all ? this._all.order : [this.key],
            entries = this._all ? this._all.entries : {[this.key]: this};
        let pabbr = {}, grade_titles = [];

        for (let i = 0; i !== order.length; ++i) {
            const k = order[i], ge = entries[k], t = ge.title || k;
            grade_titles.push(t);
            let m = t.match(/^(p)(?:art\s*|(?=\d))([-.a-z\d]+)(?:[\s:]+|$)/i)
                || t.match(/^(q)(?:uestion\s*|(?=\d))([-.a-z\d]+)(?:[\s:]+|$)/i)
                || t.match(/^[\s:]*()(\S{1,3}[\d.]*)[^\s\d]*[\s:]*/);
            if (!m) {
                ge._abbr = ":" + i;
            } else {
                let abbr = m[1] + m[2],
                    rest = t.substring(m[0].length),
                    abbrx;
                while ((abbrx = pabbr[abbr])) {
                    if (abbrx !== true
                        && (m = abbrx[1].match(/^(\S{1,3}[\d.]*)\S*[\s:]*(.*)$/))) {
                        abbrx[0]._abbr += m[1];
                        pabbr[abbrx[0]._abbr] = [abbrx[0], m[2]];
                        pabbr[abbr] = abbrx = true;
                    }
                    if ((m = rest.match(/^(\S{1,3}[\d.]*)[^\s\d]*[\s:]*(.*)$/))) {
                        abbr += m[1];
                        rest = m[2];
                    } else {
                        if (abbrx !== true) {
                            abbr += ":" + i;
                        }
                        break;
                    }
                }
                ge._abbr = abbr;
                pabbr[abbr] = [ge, rest];
            }
        }
    }

    html_skeleton(editable, live) {
        const name = this.key,
            title = this.title ? escape_entities(this.title) : name,
            typeinfo = window.pa_grade_types[this.type];
        let t;
        if ((editable || this.student) && typeinfo.entry) {
            live = live !== false;
            let opts = {editable: editable},
                id = "pa-ge" + ++GradeEntry.id_counter;
            t = (live ? '<form class="ui-submit ' : '<div class="') + 'pa-grade pa-p';
            if (this.type === "section") {
                t += ' pa-p-section';
            }
            if (this.visible === false) {
                t += ' pa-p-hidden';
            }
            t = t.concat('" data-pa-grade="', name);
            if (!live) {
                t = t.concat('" data-pa-grade-type="', typeinfo.type);
            }
            t = t.concat('"><label class="pa-pt" for="', id, '">', title, '</label>');
            if (this.edit_description) {
                t += '<div class="pa-pdesc">' + escape_entities(this.edit_description) + '</div>';
            }
            t += typeinfo.entry.call(this, id, opts) + (live ? '</form>' : '</div>');
        } else {
            t = '<div class="pa-grade pa-p';
            if (this.type === "section") {
                t += ' pa-p-section';
            }
            t += '" data-pa-grade="'.concat(name, '"><div class="pa-pt">', title, '</div>');
            if (this.type === "text") {
                t += '<div class="pa-pd pa-gradevalue"></div>';
            } else {
                t += '<div class="pa-pd"><span class="pa-gradevalue pa-gradewidth"></span>';
                if (this.max && this.type !== "letter") {
                    t += ' <span class="pa-gradedesc">of ' + this.max + '</span>';
                }
                t += '</div>';
            }
            t += '</div>';
        }
        return t;
    }

    text(g) {
        const typeinfo = window.pa_grade_types[this.type];
        return typeinfo.text.call(this, g);
    }

    simple_text(g) {
        const typeinfo = window.pa_grade_types[this.type];
        return typeinfo.simple_text.call(this, g);
    }

    tcell(g) {
        const typeinfo = window.pa_grade_types[this.type];
        return typeinfo.tcell.call(this, g);
    }

    static parse_json(x) {
        return GradeEntry.realize(JSON.parse(x));
    }
    static realize(x) {
        if (typeof x === "object" && x.entries) {
            for (let i in x.entries) {
                x.entries[i] = new GradeEntry(x.entries[i]);
                x.entries[i]._all = x;
            }
        }
        return x;
    }
    static store(element, x) {
        $(element).data("pa-gradeinfo", GradeEntry.realize(x));
        window.$pa.loadgrades.call(element);
    }
    static closest_set(element) {
        let e = element.closest(".pa-psetinfo"), gi = null;
        while (e) {
            var gix = $(e).data("pa-gradeinfo");
            if (typeof gix === "string") {
                gix = GradeEntry.parse_json(gix);
                $(e).data("pa-gradeinfo", gix);
            }
            gi = gi ? $.extend(gi, gix) : gix;
            if (gi && gi.entries) {
                break;
            }
            e = e.parentElement.closest(".pa-psetinfo");
        }
        return gi;
    }
    static closest(element) {
        const e = element.closest(".pa-grade"),
            gi = GradeEntry.closest_set(e);
        return gi.entries[e.getAttribute("data-pa-grade")];
    }
}
