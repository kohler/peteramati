// gradeentry.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities, unescape_entities, html_id_encode } from "./encoders.js";
import { hasClass, toggleClass } from "./ui.js";
import { Filediff, Linediff } from "./diff.js";
import { Note } from "./note.js";
import { GradeClass } from "./gc.js";
import { render_ftext } from "./render.js";


let id_counter = 0, late_hours_entry;
const want_props = {
    "uid": true, "last_hours": true, "auto_late_hours": true, "updateat": true,
    "version": true, "editable": true, "maxtotal": true, "history": true, "total": true,
    "total_noextra": true, "grading_hash": true, "answer_version": true,
    "editable_answers": true
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

    get title_html() {
        let t = this.title, ch, int;
        if (!t) {
            return this.key;
        } else if (t.charAt(0) === "<"
                   && (ch = t.charAt(1)) >= "0"
                   && ch <= "9") {
            t = render_ftext(t).trim();
            if (t.startsWith("<p>")
                && t.endsWith("</p>")
                && (int = t.substring(3, t.length - 4)).indexOf("</p>") < 0) {
                t = int;
            }
            return t;
        } else {
            return escape_entities(t);
        }
    }

    get title_text() {
        let t = this.title, ch, int;
        if (!t) {
            return this.key;
        } else if (t.charAt(0) === "<"
                   && (ch = t.charAt(1)) >= "0"
                   && ch <= "9") {
            t = render_ftext(t).trim();
            if (t.startsWith("<p>")
                && t.endsWith("</p>")
                && (int = t.substring(3, t.length - 4)).indexOf("</p>") < 0) {
                t = int;
            }
            return unescape_entities(t);
        } else {
            return t;
        }
    }

    abbr() {
        this._abbr || this.compute_abbr();
        return this._abbr;
    }

    compute_abbr() {
        const order = this._all ? this._all.order : [this.key],
            value_order = this._all ? this._all.value_order : [this.key],
            entries = this._all ? this._all.entries : {[this.key]: this};
        let pabbr = {}, grade_titles = [];

        for (let i = 0; i !== order.length; ++i) {
            entries[order[i]]._abbr = ":" + i;
        }
        for (let i = 0; i !== value_order.length; ++i) {
            const k = value_order[i], ge = entries[k], t = ge.title_text;
            grade_titles.push(t);
            let m = t.match(/^(p)(?:art[\s\d]|(?=\d))([-.a-z\d]+)(?:[\s:]+|$)/i)
                || t.match(/^(q)(?:uestion[\s\d]|(?=\d))([-.a-z\d]+)(?:[\s:]+|$)/i)
                || t.match(/^[\s:]*()(\S{1,3}[\d.]*)[^\s\d]*[\s:]*/);
            if (m) {
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

    render(gi, mode) {
        if (mode == null) {
            if (this.readonly
                || (this.answer ? gi.editable_answers === false : !gi.editable)) {
                mode = 0;
            } else {
                mode = 2;
            }
        }
        const id = "pe-ge" + ++id_counter;
        let t = (mode === 2 ? '<form class="ui-submit ' : '<div class="').concat(
            'pa-grade pa-p',
            this.visible === false ? ' pa-p-hidden' : '',
            '" data-pa-grade="', this.key,
            '"><label class="pa-pt" for="', id, '">', this.title_html, '</label>');
        if (this.description) {
            t = t.concat('<div class="pa-pdesc pa-dr">', render_ftext(this.description), '</div>');
        }
        const e = $(t.concat('<div class="pa-pd', mode ? ' e' : '', '"></div>',
                             mode === 2 ? '</form>' : '</div>'))[0];
        this.mount_at(e.lastChild, id, mode);
        return e;
    }

    mount_at(pde, id, mode) {
        let t;
        if (mode) {
            t = this.gc.mount_edit.call(this, pde, id);
        } else {
            t = this.gc.mount_show.call(this, pde, id);
        }
        if (typeof t === "string") {
            pde.innerHTML = t;
        }
    }

    update_edit(elt, v, opts) {
        const $g = $(elt);

        // “grade is above max” message
        if (this.max) {
            if (!v || v <= this.max) {
                $g.find(".pa-gradeabovemax").remove();
            } else if (!$g.find(".pa-gradeabovemax").length) {
                $g.find(".pa-pd").after('<div class="pa-pd pa-gradeabovemax">Grade is above max</div>');
            }
        }

        // “autograde differs” message
        if (opts.autograde == null || v === opts.autograde) {
            $g.find(".pa-gradediffers").remove();
        } else {
            const txt = (this.key === "late_hours" ? "auto-late hours" : "autograde") +
                " is " + this.text(opts.autograde);
            if (!$g.find(".pa-gradediffers").length) {
                $g.find(".pa-pd").first().append('<span class="pa-gradediffers"></span>');
            }
            const $ag = $g.find(".pa-gradediffers");
            if ($ag.text() !== txt) {
                $ag.text(txt);
            }
        }

        // grade value
        this.gc.update_edit.call(this, elt, v, opts);

        let ve;
        if (opts.reset && (ve = elt.querySelector(".pa-gradevalue"))) {
            ve.setAttribute("data-default-value", this.simple_text(v));
        }
        this.landmark && this.update_landmark(elt);
    }

    update_show(elt, v) {
        const ve = elt.querySelector(".pa-gradevalue");
        let hidden;
        if (this.gc.update_show) {
            hidden = this.gc.update_show.call(this, ve, v);
        } else {
            const gt = this.text(v);
            if (ve.innerText !== gt) {
                ve.innerText = gt;
            }
            hidden = gt === "" && !this.max;
        }
        hidden != null && toggleClass(elt, "hidden", hidden);
        this.landmark && this.update_landmark(elt);
    }

    update_at(elt, v, opts) {
        let pde = elt.firstChild;
        while (!hasClass(pde, "pa-pd")) {
            pde = pde.nextSibling;
        }
        if (hasClass(pde, "e")) {
            this.update_edit(pde, v, opts);
        } else {
            this.update_show(pde, v, opts);
        }
    }

    update_landmark(elt) {
        let gl = elt.closest(".pa-gradelist");
        if (gl && hasClass(gl, "want-landmark-links")) {
            let want_gbr = "";
            const m = /^(.*):(\d+)$/.exec(this.landmark);
            if (m && Filediff.find(m[1])) {
                const pi = elt.closest(".pa-psetinfo"),
                    directory = pi.getAttribute("data-pa-directory") || "",
                    filename = m[1].startsWith(directory) ? m[1].substring(directory.length) : m[1];
                want_gbr = '@<a href="#La'.concat(m[2], '_', html_id_encode(m[1]), '">', escape_entities(filename), ":", m[2], '</a>');
            }
            const $g = $(elt), $pgbr = $g.find(".pa-gradeboxref");
            if (want_gbr === "") {
                $pgbr.remove();
            } else if (!$pgbr.length || $pgbr.html() !== want_gbr) {
                $pgbr.remove();
                $g.find(".pa-pd").first().append('<span class="pa-gradeboxref">' + want_gbr + '</span>');
            }
        }
    }

    text(v) {
        return this.gc.text.call(this, v);
    }

    simple_text(v) {
        return this.gc.simple_text.call(this, v);
    }

    configure_column(col, pconf) {
        return this.gc.configure_column.call(this, col, pconf);
    }

    tcell_width() {
        const w = this.gc.tcell_width || 3;
        return typeof w === "function" ? w.call(this) : w;
    }

    tcell(v) {
        return this.gc.tcell.call(this, v);
    }

    landmark_lines(t, selector) {
        const m = (this.landmark_range ? /:(\d+):(\d+)$/.exec(this.landmark_range) : null);
        if (!m) {
            throw new Error("bad landmark_lines");
        }
        const lo = +m[1];
        let fd = t instanceof Linediff ? t.element : t, lm;
        while (!hasClass(fd, "pa-filediff")
               && (!(lm = fd.getAttribute("data-pa-landmark"))
                   || lm.charAt(0) !== "a"
                   || parseInt(lm.substring(1)) > lo)) {
            fd = fd.parentElement;
        }
        return Linediff.range(fd, +m[1], +m[2], selector);
    }

    landmark_grade(t) {
        let sum = null;

        for (let ln of this.landmark_lines(t, ".pa-gw")) {
            const note = Note.at(ln.element);
            let m, gch;
            if (note.text
                && ((m = /^[\s❮→]*(\+)(\d+(?:\.\d+)?|\.\d+)((?![.,]\w|[\w%$*])\S*?)[.,;:❯]?(?:\s|$)/.exec(note.text))
                    || (m = /^[\s❮→]*()(\d+(?:\.\d+)?|\.\d+)(\/[\d.]+(?![.,]\w|[\w%$*\/])\S*?)[.,;:❯]?(?:\s|$)/.exec(note.text)))) {
                if (sum === null) {
                    sum = 0.0;
                }
                sum += parseFloat(m[2]);
                gch = escape_entities(this.title_text).concat(": ", escape_entities(m[1]), "<b>", escape_entities(m[2]), "</b>", escape_entities(m[3]));
            }
            let $nd = $(ln.element).find(".pa-note-gradecontrib");
            if (!$nd.length && gch) {
                $nd = $('<div class="pa-note-gradecontrib"></div>').insertBefore($(ln.element).find(".pa-note"));
            }
            gch ? $nd.html(gch) : $nd.remove();
        }

        if (this.round && sum != null) {
            if (this.round === "up") {
                sum = Math.ceil(sum);
            } else if (this.round === "down") {
                sum = Math.floor(sum);
            } else {
                sum = Math.round(sum);
            }
        }

        const gh = t.closest(".pa-grade") || t.closest(".pa-grade-range-block");
        let $gnv = $(gh).find(".pa-notes-grade");
        if (sum === null) {
            $gnv.remove();
        } else {
            if (!$gnv.length) {
                const $gs = hasClass(gh, "pa-grade") ? $(gh) : $(gh).find(".pa-grade");
                $gs.each(function () {
                    let e = this.lastChild.firstChild;
                    while (e && (e.nodeType !== 1 || hasClass(e, "pa-gradewidth") || hasClass(e, "pa-gradedesc"))) {
                        e = e.nextSibling;
                    }
                    this.lastChild.insertBefore($('<a class="uic uikd pa-notes-grade" href=""></a>')[0], e);
                });
                $gnv = $(gh).find(".pa-notes-grade");
            }
            $gnv.text("Notes grade " + sum);
        }

        return sum;
    }

    save_landmark_grade(t) {
        const sum = this.landmark_grade(t);

        const gv = $(t).find(".pa-gradevalue")[0];
        if (gv) {
            const sumstr = sum === null ? "" : "" + sum,
                gval = $(gv).val();
            if (gval == gv.getAttribute("data-pa-notes-grade")
                && sumstr != gval) {
                $(gv).val(sumstr).change();
            }
            gv.setAttribute("data-pa-notes-grade", sumstr);
        }

        return sum;
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
        let need_gpos = false;
        if (x.entries) {
            for (let i in x.entries) {
                this.entries[i] = new GradeEntry(x.entries[i]);
                this.entries[i]._all = this;
            }
        }
        if (x.value_order && !this.explicit_value_order) {
            this.value_order = x.value_order;
            this.explicit_value_order = true;
            need_gpos = true;
        }
        if (x.order) {
            this.order = x.order;
            if (!this.explicit_value_order) {
                this.value_order = x.order;
                need_gpos = true;
            }
        }
        if (need_gpos) {
            this.gpos = {};
            for (let i = 0; i < this.value_order.length; ++i) {
                this.gpos[this.value_order[i]] = i;
            }
        }
        if (x.grades) {
            this.grades = this.merge_grades(this.grades, x.grades, x);
        }
        if (x.autogrades) {
            this.autogrades = this.merge_grades(this.autogrades, x.autogrades, x);
        }
        for (let k in x) {
            if (want_props[k])
                this[k] = x[k];
        }
    }

    merge_grades(myg, ing, x) {
        let inorder = x.value_order || x.order || this.value_order;
        if (!myg && inorder === this.value_order) {
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

    remount_at(elt, mode) {
        const k = elt.getAttribute("data-pa-grade");
        let ge;
        if (k === "late_hours") {
            ge = GradeEntry.late_hours();
        } else {
            ge = this.entries[k];
        }
        if (ge) {
            let pde = elt.firstChild, id;
            while (!hasClass(pde, "pa-pd")) {
                pde.tagName === "LABEL" && (id = pde.id);
                pde = pde.nextSibling;
            }
            while (pde.nextSibling) {
                elt.removeChild(pde.nextSibling);
            }
            while (pde.firstChild) {
                pde.removeChild(pde.firstChild);
            }
            pde.className = mode ? 'pa-pd e' : 'pa-pd';
            ge.mount_at(pde, id, mode);
        }
    }

    update_at(elt) {
        const k = elt.getAttribute("data-pa-grade");
        let ge, v, opts, gpos;
        if (k === "late_hours") {
            ge = GradeEntry.late_hours();
            v = this.late_hours;
            opts = {autograde: this.auto_late_hours};
        } else if ((ge = this.entries[k]) && (gpos = this.gpos[k]) != null) {
            v = this.grades ? this.grades[gpos] : null;
            opts = {autograde: this.autogrades ? this.autogrades[gpos] : null};
        } else {
            return;
        }
        ge.update_at(elt, v, opts);
    }

    get_total(noextra) {
        let total = 0;
        for (let i = 0; i !== this.order.length; ++i) {
            const ge = this.entries[this.order[i]];
            if (ge && ge.in_total && (!noextra || !this.is_extra)) {
                total += (this.grades && this.grades[i]) || 0;
            }
        }
        return Math.round(total * 1000) / 1000;
    }

    get has_sections() {
        for (let i = 0; i !== this.order.length; ++i) {
            const ge = this.entries[this.order[i]];
            if (ge.type === "section") {
                return true;
            }
        }
        return false;
    }

    grade_value(ge) {
        const i = this.grades ? this.gpos[ge.key] : null;
        return i != null ? this.grades[i] : null;
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

    section_has(ge, f) {
        let start = this.gpos[ge.key];
        while (start != null && start < this.order.length) {
            const xge = this.entries[this.order[start]];
            if ((xge === ge) !== (xge.type === "section")) {
                break;
            } else if (f(xge, this)) {
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
            gs = new GradeSheet;
            $(element).data("pa-gradeinfo", gs);
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
                    return jx;
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
