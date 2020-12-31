// gradeentry.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities, html_id_encode } from "./encoders.js";
import { hasClass, toggleClass } from "./ui.js";
import { Filediff } from "./diff.js";
import { GradeClass } from "./gc.js";
import { render_ftext } from "./render.js";


let id_counter = 0, late_hours_entry;
const want_props = {
    "uid": true, "last_hours": true, "auto_late_hours": true, "updateat": true,
    "version": true, "editable": true, "maxtotal": true, "history": true, "total": true,
    "total_noextra": true, "grading_hash": true, "answer_version": true
};

export class GradeEntry {
    constructor(x) {
        Object.assign(this, x);
        this.type = this.type || "numeric";
        this.gc = GradeClass.find(this.type);
        this._abbr = null;
        this._all = null;
    }

    get type_tabular() {
        return this.gc.type_tabular;
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
            const k = order[i], ge = entries[k];
            let t = ge.title || k;
            if (t.startsWith("<1>")) {
                t = t.substring(3);
            }
            grade_titles.push(t);
            let m = t.match(/^(p)(?:art[\s\d]|(?=\d))([-.a-z\d]+)(?:[\s:]+|$)/i)
                || t.match(/^(q)(?:uestion[\s\d]|(?=\d))([-.a-z\d]+)(?:[\s:]+|$)/i)
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
            title = (this.title ? render_ftext(this.title) : name).trim();
        let t;
        if ((editable || this.answer) && !this.readonly) {
            live = live !== false;
            let opts = {editable: editable},
                id = "pa-ge" + ++id_counter;
            t = (live ? '<form class="ui-submit ' : '<div class="') + 'pa-grade pa-p';
            if (this.type === "section") {
                t += ' pa-p-section';
            }
            if (this.visible === false) {
                t += ' pa-p-hidden';
            }
            t = t.concat('" data-pa-grade="', name);
            if (!live) {
                t = t.concat('" data-pa-grade-type="', this.gc.type);
            }
            t = t.concat('"><label class="pa-pt" for="', id, '">', title, '</label>');
            if (this.description) {
                t = t.concat('<div class="pa-pdesc pa-dr">', render_ftext(this.description), '</div>');
            }
            t = t.concat('<div class="pa-pd">', this.gc.entry.call(this, id, opts), '</div>', live ? '</form>' : '</div>');
        } else {
            t = '<div class="pa-grade pa-p';
            if (this.type === "section") {
                t += ' pa-p-section';
            }
            t = t.concat('" data-pa-grade="', name, '"><div class="pa-pt">', title, '</div>');
            if (this.type === "text") {
                t += '<div class="pa-pd pa-gradevalue"></div>';
            } else {
                t += '<div class="pa-pd"><span class="pa-gradevalue pa-gradewidth"></span>';
                if (this.max && this.type !== "letter") {
                    t = t.concat(' <span class="pa-gradedesc">of ', this.max, '</span>');
                }
                t += '</div>';
            }
            t += '</div>';
        }
        return t;
    }

    fill_dom(element, g, options) {
        const $g = $(element),
            v = $g.find(".pa-gradevalue")[0];

        if (v && v.tagName !== "SPAN" && v.tagName !== "DIV") {
            this.fill_dom_editable(element, v, g, options || {});
        } else if (this.gc.fill_dom) {
            this.gc.fill_dom.call(this, g, v);
            toggleClass(element, "hidden", v.firstChild !== null && this.type !== "section");
        } else {
            const gt = this.text(g);
            if ($(v).text() !== gt) {
                $(v).text(gt);
            }
            toggleClass(element, "hidden", gt === "" && !this.max && this.type !== "section");
        }

        // maybe add landmark reference
        if (this.landmark) {
            const gl = element.closest(".pa-gradelist");
            if (gl && hasClass(gl, "want-landmark-links")) {
                let want_gbr = "";
                const m = /^(.*):(\d+)$/.exec(this.landmark);
                if (m && Filediff.find(m[1])) {
                    const pi = element.closest(".pa-psetinfo"),
                        directory = pi.getAttribute("data-pa-directory") || "",
                        filename = m[1].startsWith(directory) ? m[1].substring(directory.length) : m[1];
                    want_gbr = '@<a href="#La'.concat(m[2], '_', html_id_encode(m[1]), '">', escape_entities(filename), ":", m[2], '</a>');
                }
                const $pgbr = $g.find(".pa-gradeboxref");
                if (want_gbr === "") {
                    $pgbr.remove();
                } else if (!$pgbr.length || $pgbr.html() !== want_gbr) {
                    $pgbr.remove();
                    $g.find(".pa-pd").first().append('<span class="pa-gradeboxref">' + want_gbr + '</span>');
                }
            }
        }
    }

    fill_dom_editable(element, v, g, options) {
        const $g = $(element);

        // “grade is above max” message
        if (this.max) {
            if (!g || g <= this.max) {
                $g.find(".pa-gradeabovemax").remove();
            } else if (!$g.find(".pa-gradeabovemax").length) {
                $g.find(".pa-pd").after('<div class="pa-pd pa-gradeabovemax">Grade is above max</div>');
            }
        }

        // “autograde differs” message
        if (options.autograde == null || g === options.autograde) {
            $g.find(".pa-gradediffers").remove();
        } else {
            const txt = (this.key === "late_hours" ? "auto-late hours" : "autograde") +
                " is " + this.text(options.autograde);
            if (!$g.find(".pa-gradediffers").length) {
                $g.find(".pa-pd").first().append('<span class="pa-gradediffers"></span>');
            }
            const $ag = $g.find(".pa-gradediffers");
            if ($ag.text() !== txt) {
                $ag.text(txt);
            }
        }

        // grade value
        this.gc.reflect_value.call(this, v, g, options);
        if (options.reset && options.mixed) {
            v.setAttribute("placeholder", "Mixed");
        } else if (v.hasAttribute("placeholder")) {
            v.removeAttribute("placeholder");
        }
        if (options.reset) {
            v.setAttribute("data-default-value", this.simple_text(g));
        }
    }

    text(g) {
        return this.gc.text.call(this, g);
    }

    simple_text(g) {
        return this.gc.simple_text.call(this, g);
    }

    configure_column(col, pconf) {
        return this.gc.configure_column.call(this, col, pconf);
    }

    tcell_width() {
        const w = this.gc.tcell_width || 3;
        return typeof w === "function" ? w.call(this) : w;
    }

    tcell(g) {
        return this.gc.tcell.call(this, g);
    }

    static closest(elt) {
        const e = elt.closest(".pa-grade"),
            gi = GradeSheet.closest(e);
        return gi.entries[e.getAttribute("data-pa-grade")];
    }

    static late_hours() {
        late_hours_entry = late_hours_entry || new GradeEntry({key: "late_hours", title: "late hours"});
        return late_hours_entry;
    }
}

export class GradeSheet {
    constructor(x) {
        this.entries = {};
        x && this.extend(x);
    }

    extend(x) {
        if (x.entries) {
            for (let i in x.entries) {
                this.entries[i] = new GradeEntry(x.entries[i]);
                this.entries[i]._all = this;
            }
        }
        if (x.order && !this.order) {
            this.order = x.order;
            this.gpos = {};
            for (let i = 0; i < this.order.length; ++i) {
                this.gpos[this.order[i]] = i;
            }
        }
        if (x.grades) {
            this.grades = this.merge_grades(this.grades, x.grades, x.order || this.order);
        }
        if (x.autogrades) {
            this.autogrades = this.merge_grades(this.autogrades, x.autogrades, x.order || this.order);
        }
        for (let k in x) {
            if (want_props[k])
                this[k] = x[k];
        }
    }

    merge_grades(myg, ing, inorder) {
        if (!myg && (!this.order || inorder === this.order)) {
            return ing;
        } else {
            myg = myg || [];
            for (let i in inorder) {
                const j = this.gpos[inorder[i]];
                if (j != null) {
                    while (myg.length <= j) {
                        myg.push(null);
                    }
                    myg[j] = ing[i];
                }
            }
            return myg;
        }
    }

    fill_dom_at(element) {
        const k = element.getAttribute("data-pa-grade"),
            ge = this.entries[k];
        if (k === "late_hours") {
            GradeEntry.late_hours().fill_dom(element, this.late_hours, {autograde: this.auto_late_hours});
        } else if (ge) {
            const gpos = this.gpos[k];
            if (gpos != null) {
                ge.fill_dom(element, this.grades ? this.grades[gpos] : null, {autograde: this.autogrades ? this.autogrades[gpos] : null});
            }
        }
    }

    get_total(noextra) {
        let total = 0;
        for (let i = 0; i < this.order.length; ++i) {
            const ge = this.entries[this.order[i]];
            if (ge && ge.in_total && (!noextra || !this.is_extra)) {
                total += (this.grades && this.grades[i]) || 0;
            }
        }
        return Math.round(total * 1000) / 1000;
    }

    get has_sections() {
        for (let i = 0; i < this.order.length; ++i) {
            const ge = this.entries[this.order[i]];
            if (ge.type === "section") {
                return true;
            }
        }
        return false;
    }

    grade_value(ge) {
        if (this.grades) {
            const i = this.gpos[ge.key];
            return this.grades[i];
        } else {
            return null;
        }
    }

    section_wants_sidebar(ge) {
        let start = this.gpos[ge.key], answer = 0;
        while (start != null && start < this.order.length) {
            const xge = this.entries[this.order[start]];
            if (xge !== ge && xge.type === "section") {
                break;
            } else if (xge.answer) {
                answer |= 1;
            } else {
                answer |= 2;
            }
            ++start;
        }
        return answer === 3;
    }

    section_has_description(ge) {
        let start = this.gpos[ge.key];
        while (start != null && start < this.order.length) {
            const xge = this.entries[this.order[start]];
            if ((xge === ge) !== (xge.type === "section")) {
                break;
            } else if (xge.description) {
                return true;
            }
            ++start;
        }
        return false;
    }

    static parse_json(x) {
        return new GradeSheet(JSON.parse(x));
    }

    static store(element, x) {
        let gs = $(element).data("pa-gradeinfo");
        if (!gs) {
            $(element).data("pa-gradeinfo", (gs = new GradeSheet));
        }
        gs.extend(x);
        window.$pa.loadgrades.call(element);
    }

    static closest(element) {
        let e = element.closest(".pa-psetinfo"), gi = null;
        while (e) {
            const edata = $(e).data("pa-gradeinfo");
            if (edata) {
                const jx = typeof edata === "string" ? JSON.parse(edata) : edata;
                if (gi) {
                    gi.extend(jx);
                } else if (jx instanceof GradeSheet) {
                    gi = jx;
                } else {
                    gi = new GradeSheet(jx);
                    $(e).data("pa-gradeinfo", gi);
                }
            }
            if (gi && !hasClass(e, "pa-psetinfo-partial")) {
                break;
            }
            e = e.parentElement.closest(".pa-psetinfo");
        }
        return gi;
    }
}
