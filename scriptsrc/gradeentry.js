// gradeentry.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities, unescape_entities, html_id_encode } from "./encoders.js";
import { hasClass, toggleClass, input_set_default_value } from "./ui.js";
import { Filediff, Linediff } from "./diff.js";
import { Note } from "./note.js";
import { GradeClass } from "./gc.js";
import { render_ftext } from "./render.js";


let id_counter = 0, late_hours_entry;
const gradesheet_props = {
    "uid": true, "user": true,
    "late_hours": true, "auto_late_hours": true, "updateat": true,
    "version": true, "history": true, "total": true,
    "total_noextra": true, "grading_hash": true, "answer_version": true,
    "user_visible_scores": true, "editable_scores": true, "editable_answers": true,
    "linenotes": true
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
            mode = !this.readonly
                && (this.answer ? gi.editable_answers : gi.editable_scores) ? 2 : 0;
        }
        const id = "pe-ge" + ++id_counter;
        let hsv;
        if (this.visible == null) {
            hsv = !this.answer && gi.user_visible_scores === false;
        } else {
            hsv = this.visible === false || this.visible === "none";
        }
        const pge = document.createElement("div"),
            le = document.createElement("label"),
            pde = document.createElement(mode === 2 ? "form" : "div");
        pge.className = "pa-grade pa-p".concat(hsv ? "pa-p-hidden" : "", this.answer ? " pa-ans" : "", mode ? " e" : "");
        pge.setAttribute("data-pa-grade", this.key);
        le.className = "pa-pt";
        le.htmlFor = id;
        le.innerHTML = this.title_html;
        pge.appendChild(le);
        if (this.description) {
            const de = document.createElement("div");
            de.className = "pa-pdesc pa-dr";
            de.innerHTML = render_ftext(this.description);
            pge.appendChild(de);
        }
        pde.className = mode === 2 ? "ui-submit pa-pv" : "pa-pv";
        pge.appendChild(pde);
        this.mount_at(pde, id, mode);
        return pge;
    }

    mount_at(pde, id, edit) {
        let t;
        if (edit) {
            t = this.gc.mount_edit.call(this, pde, id);
        } else {
            t = this.gc.mount_show.call(this, pde, id);
        }
        if (typeof t === "string") {
            pde.innerHTML = t;
        }
        if (edit) {
            $(pde).find(".need-autogrow").autogrow();
        }
    }

    update_edit(pde, v, opts) {
        const $pde = $(pde);

        // “grade is above max” message
        if (this.max) {
            const has_max = pde.nextSibling && pde.nextSibling.classList.contains("pa-gradeabovemax");
            if (!v || v <= this.max) {
                has_max && pde.parentElement.removeChild(pde.nextSibling);
            } else if (!has_max) {
                const e = document.createElement("div");
                e.classList = "pa-pvnote pa-gradeabovemax";
                e.textContent = "Grade is above max";
                pde.parentElement.insertBefore(e, pde.nextSibling);
            }
        }

        // “autograde differs” message
        if (opts.autograde == null || v === opts.autograde) {
            $pde.find(".pa-gradediffers").remove();
        } else {
            const txt = (this.key === "late_hours" ? "auto-late hours" : "autograde") +
                " is " + this.text(opts.autograde);
            if (!$pde.find(".pa-gradediffers").length) {
                $pde.append('<span class="pa-gradediffers"></span>');
            }
            const $ag = $pde.find(".pa-gradediffers");
            if ($ag.text() !== txt) {
                $ag.text(txt);
            }
        }

        // grade value
        this.gc.update_edit.call(this, pde, v, opts);

        let ve;
        if (opts.reset && (ve = pde.querySelector(".pa-gradevalue"))) {
            input_set_default_value(ve, this.simple_text(v));
        }
        this.landmark && this.update_landmark(pde);
        $(pde).find("input, textarea").autogrow();
    }

    update_show(pde, v) {
        const ve = pde.classList.contains("pa-gradevalue") ? pde : pde.querySelector(".pa-gradevalue");
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
        hidden != null && toggleClass(pde, "hidden", hidden);
        this.landmark && this.update_landmark(pde);
    }

    update_at(elt, v, opts) {
        let pde = elt.firstChild;
        while (!hasClass(pde, "pa-pv")) {
            pde = pde.nextSibling;
        }
        if (hasClass(elt, "e")) {
            this.update_edit(pde, v, opts);
        } else {
            this.update_show(pde, v, opts);
        }
    }

    update_landmark(pde) {
        let gl = pde.closest(".pa-gradelist");
        if (gl && hasClass(gl, "want-landmark-links")) {
            let want_gbr = "";
            const m = /^(.*):(\d+)$/.exec(this.landmark);
            if (m && Filediff.by_file(m[1])) {
                const pi = pde.closest(".pa-psetinfo"),
                    directory = pi.getAttribute("data-pa-directory") || "",
                    filename = m[1].startsWith(directory) ? m[1].substring(directory.length) : m[1];
                want_gbr = '@<a href="#La'.concat(m[2], 'F', html_id_encode(m[1]), '">', escape_entities(filename), ":", m[2], '</a>');
            }
            const $pgbr = $(pde).find(".pa-gradeboxref");
            if (want_gbr === "") {
                $pgbr.remove();
            } else if (!$pgbr.length || $pgbr.html() !== want_gbr) {
                $pgbr.remove();
                $(pde).append('<span class="pa-gradeboxref">' + want_gbr + '</span>');
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
                    let e = this.firstChild;
                    while (e && !hasClass(e, "pa-pv")) {
                        e = e.nextSibling;
                    }
                    e = e.firstChild;
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
        this.gversion = [];
        x && this.extend(x);
    }

    extend(x, replace_order) {
        let need_gpos = false;
        if (x.entries) {
            for (let i in x.entries) {
                this.entries[i] = new GradeEntry(x.entries[i]);
                this.entries[i]._all = this;
            }
        }
        if (x.value_order && (!this.explicit_value_order || replace_order)) {
            this.value_order = x.value_order;
            this.explicit_value_order = true;
            need_gpos = true;
        }
        if (x.order && (!this.order || replace_order)) {
            this.order = x.order;
            if (!this.explicit_value_order) {
                this.value_order = x.order;
                need_gpos = true;
            }
        }
        if (need_gpos) {
            while (this.gversion.length < this.value_order.length) {
                this.gversion.push(0);
            }
            this.gpos = {};
            for (let i = 0; i < this.value_order.length; ++i) {
                this.gpos[this.value_order[i]] = i;
                ++this.gversion[i];
            }
            this.grades = this.autogrades = this.maxtotal = null;
        }
        if (x.grades) {
            this.grades = this.merge_grades(this.grades, x.grades, x);
        }
        if (x.autogrades) {
            this.autogrades = this.merge_grades(this.autogrades, x.autogrades, x);
        }
        while (this.grades && this.gversion.length < this.grades.length) {
            this.gversion.push(0);
        }
        for (let k in x) {
            if (gradesheet_props[k])
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
                    if (myg[j] != ing[i]) {
                        myg[j] = ing[i];
                        ++this.gversion[j];
                    }
                }
            }
            return myg;
        }
    }

    remount_at(elt, mode) {
        const k = elt.getAttribute("data-pa-grade"), islh = k === "late_hours",
            ge = islh ? GradeEntry.late_hours() : this.entries[k];
        if (ge) {
            let pde = elt.firstChild, id;
            while (!hasClass(pde, "pa-pv")) {
                pde.tagName === "LABEL" && (id = pde.htmlFor);
                pde = pde.nextSibling;
            }
            while (pde.nextSibling) {
                elt.removeChild(pde.nextSibling);
            }
            if (typeof mode === "boolean") {
                mode = mode ? 2 : 0;
            }
            const pdx = document.createElement(mode === 2 ? "form" : "div");
            pdx.className = mode === 2 ? "ui-submit pa-pv" : "pa-pv";
            toggleClass(elt, "e", mode !== 0);
            elt.replaceChild(pdx, pde);
            ge.mount_at(pdx, id, mode !== 0);
            elt.removeAttribute("data-pa-gv");
            $(pde).find("input, textarea").unautogrow();
        }
    }

    update_at(elt, opts) {
        const k = elt.getAttribute("data-pa-grade"), islh = k === "late_hours";
        opts = opts ? Object.assign({}, opts) : {};
        let ge, gpos, gv;
        if (islh) {
            ge = GradeEntry.late_hours();
            gv = this.late_hours;
            opts.autograde = this.auto_late_hours;
        } else if ((ge = this.entries[k])
                   && (gpos = this.gpos[k]) != null
                   && elt.getAttribute("data-pa-gv") != this.gversion[gpos]) {
            gv = this.grades ? this.grades[gpos] : null;
            opts.autograde = this.autogrades ? this.autogrades[gpos] : null;
            elt.setAttribute("data-pa-gv", this.gversion[gpos]);
        } else {
            return;
        }
        ge.update_at(elt, gv, opts);
    }

    grade_value(ge) {
        const i = this.grades ? this.gpos[ge.key] : null;
        return i != null ? this.grades[i] : null;
    }

    grade_total(noextra) {
        let total = 0;
        for (let i = 0; i !== this.value_order.length; ++i) {
            const ge = this.entries[this.value_order[i]];
            if (ge && ge.in_total && (!noextra || !ge.is_extra)) {
                total += (this.grades && this.grades[i]) || 0;
            }
        }
        return Math.round(total * 1000) / 1000;
    }

    get grade_maxtotal() {
        if (this.maxtotal === null) {
            let maxtotal = 0;
            for (let i = 0; i !== this.value_order.length; ++i) {
                const ge = this.entries[this.value_order[i]];
                if (ge && ge.in_total && !ge.is_extra && ge.max) {
                    maxtotal += ge.max;
                }
            }
            this.maxtotal = Math.round(maxtotal * 1000) / 1000;
        }
        return this.maxtotal;
    }

    file_anchor(file) {
        if (hasClass(document.body, "pa-multiuser")) {
            return "U".concat(html_id_encode(this.user), "/F", html_id_encode(file));
        } else {
            return "F" + html_id_encode(file);
        }
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

    has(f) {
        for (let i = 0; i !== this.value_order.length; ++i) {
            const gei = this.entries[this.value_order[i]];
            if (f(gei, this))
                return true;
        }
        return false;
    }

    section_has(ge, f) {
        let start = this.gpos[ge.key];
        while (start != null && start < this.value_order.length) {
            const gei = this.entries[this.value_order[start]];
            if (gei !== ge && gei.type === "section") {
                break;
            } else if (f(gei, this)) {
                return true;
            }
            ++start;
        }
        return false;
    }

    section_wants_sidebar(ge) {
        let answer = 0;
        return this.editable_scores && this.section_has(ge, gei => {
            answer |= gei.answer ? 1 : 2;
            return answer === 3;
        });
    }

    static parse_json(x) {
        return new GradeSheet(JSON.parse(x));
    }

    static store(element, x) {
        let gs = $(element).data("pa-gradeinfo");
        if (!gs) {
            gs = new GradeSheet;
            gs.element = element;
            $(element).data("pa-gradeinfo", gs);
        }
        gs.extend(x, !element.classList.contains("pa-psetinfo-partial"));
        window.$pa.loadgrades.call(element);
    }

    static closest(element) {
        let e = element.closest(".pa-psetinfo"), gi = null;
        while (e) {
            let jx = $(e).data("pa-gradeinfo");
            if (jx) {
                if (gi) {
                    gi.extend(jx);
                } else if (jx instanceof GradeSheet) {
                    gi = jx;
                    break;
                } else {
                    gi = new GradeSheet(jx);
                    gi.element = e;
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
