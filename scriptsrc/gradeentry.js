// gradeentry.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities, html_id_encode } from "./encoders.js";
import { hasClass, addClass, toggleClass, removeClass, $e,
    input_default_value, input_set_default_value, input_differs } from "./ui.js";
import { Filediff, Linediff } from "./diff.js";
import { Note } from "./note.js";
import { GradeClass } from "./gc.js";
import { render_onto } from "./render.js";


let id_counter = 0, late_hours_entry;
const gradesheet_props = {
    // must include props from GradeExport and from StudentSet::json_basics
    "pset": true,
    "uid": true, "user": true, "anon_user": true, "email": true, "first": true, "last": true,
    "year": true, "x": true, "dropped": true, "imageid": true,
    "commit": true, "base_commit": true, "base_handout": true, "grade_commit": true, "emptydiff": true, "grade_status": true,
    "late_hours": true, "auto_late_hours": true,
    "student_timestamp": true, "grades_latest": true,
    "version": true, "history": true, "total_type": true, "total": true,
    "total_noextra": true, "answer_version": true,
    "scores_editable": true, "answers_editable": true,
    "linenotes": true, "gradercid": true, "has_notes": true, "has_nongrader_notes": true,
    "repo": true, "repo_broken": true, "repo_unconfirmed": true,
    "repo_too_open": true, "repo_handout_old": true, "repo_partner_error": true,
    "repo_sharing": true,
    "flagid": true, "conversation": true, "conversation_pfx": true, "at": true
};

function pa_resetgrade() {
    removeClass(this, "pa-resetgrade");
    this.removeEventListener(this, pa_resetgrade, false);
    if (!input_differs(this)) {
        const gi = GradeSheet.closest(this),
            gr = this.closest(".pa-grade");
        queueMicrotask(function () { gi.update_at(gr); });
    }
}

function title_span(ftext) {
    const x = document.createElement("span");
    document.body.appendChild(x);
    render_onto(x, "f", ftext);
    x.remove();
    return x;
}

export class GradeEntry {
    constructor(x) {
        Object.assign(this, x);
        this.type = this.type || "numeric";
        this.gc = GradeClass.find(this.type);
        this.disabled = !!this.disabled;
        this.normal = true;
        this._abbr = null;
        this._all = null;
    }

    get type_tabular() {
        return this.gc.type_tabular;
    }

    student_visible(gi) {
        return this.visible === true
            || (this.visible == null && (this.answer || gi.scores_visible));
    }

    get title_node() {
        let t = this.title, ch;
        if (!t) {
            return this.key;
        } else if (t.charCodeAt(0) === 60 /* "<" */
                   && (ch = t.charCodeAt(1)) >= 48
                   && ch <= 57) {
            return title_span(t);
        } else {
            return t;
        }
    }

    get title_html() {
        let t = this.title, ch;
        if (!t) {
            return this.key;
        } else if (t.charCodeAt(0) === 60 /* "<" */
                   && (ch = t.charCodeAt(1)) >= 48
                   && ch <= 57) {
            return title_span(t).innerHTML;
        } else {
            return escape_entities(t);
        }
    }

    get title_text() {
        let t = this.title, ch;
        if (!t) {
            return this.key;
        } else if (t.charCodeAt(0) === 60 /* "<" */
                   && (ch = t.charCodeAt(1)) >= 48
                   && ch <= 57) {
            return title_span(t).textContext;
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
                && (this.answer ? gi.answers_editable : gi.scores_editable) ? 2 : 0;
        }
        const id = "pe-ge" + ++id_counter,
            pge = document.createElement("div"),
            le = document.createElement("label"),
            pde = document.createElement(mode === 2 ? "form" : "div"),
            hidden = this.student_visible(gi) ? "" : " pa-p-hidden";
        pge.className = "pa-grade pa-p".concat(mode ? " e" : "", this.answer ? " pa-ans" : "", hidden);
        pge.setAttribute("data-pa-grade", this.key);
        le.className = "pa-pt";
        le.htmlFor = id;
        le.innerHTML = this.title_html;
        pge.appendChild(le);
        if (this.description) {
            const de = document.createElement("div");
            de.className = "pa-pdesc pa-dr";
            render_onto(de, "f", this.description);
            pge.appendChild(de);
        }
        if (mode === 2) {
            pde.className = "ui-submit pa-pv" + (this.description ? " pa-textv" : "");
        } else {
            pde.className = "pa-pv";
        }
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
        } else if (t) {
            pde.replaceChildren(t);
        }
        if (edit) {
            $(pde).find(".need-autogrow").autogrow();
        }
    }

    update_edit(pde, v, opts) {
        const ve = pde.querySelector(".pa-gradevalue"),
            vt = this.simple_text(v);

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
        let gd = pde.querySelector(".pa-gradediffers");
        if (opts.autograde == null || v === opts.autograde) {
            gd && gd.remove();
        } else {
            const txt = (this.key === "late_hours" ? "auto-late hours" : "autograde") +
                " is " + this.text(opts.autograde);
            if (!gd) {
                pde.appendChild((gd = $e("span", "pa-gradediffers")));
            }
            if (gd.textContent !== txt) {
                gd.textContent = txt;
            }
        }

        // do not update if user has edited
        if (hasClass(pde, "pa-dirty")) {
            return;
        }

        // update on blur if element is focused
        if (ve && ve.type !== "hidden" && ve.matches(":focus")) {
            if (!hasClass(ve, "pa-resetgrade")
                && vt !== input_default_value(ve)) {
                addClass(ve, "pa-resetgrade");
                ve.addEventListener("blur", pa_resetgrade, false);
            }
            return;
        }

        // grade value: update value if reset or fresh;
        // update default value always
        const fresh = ve && hasClass(ve, "pa-fresh"),
            reset = opts.reset || fresh;
        if (reset || !ve) {
            this.gc.update_edit.call(this, pde, v, opts);
        }
        if (ve) {
            input_set_default_value(ve, vt);
        }

        // landmark
        if (this.landmark) {
            this.update_landmark(pde);
        }

        // UI: sidebar tabbing, autogrow
        if (ve && opts.sidebar) {
            ve.tabIndex = -1;
        }
        if (fresh) {
            removeClass(ve, "pa-fresh");
        }
        $(pde).find("input, textarea").autogrow();
    }

    update_show(pde, v, opts) {
        const ve = pde.classList.contains("pa-gradevalue")
            ? pde
            : pde.querySelector(".pa-gradevalue");
        let hidden;
        if (this.gc.update_show) {
            hidden = this.gc.update_show.call(this, ve, v, opts);
        } else {
            let gt = this.text(v);
            hidden = gt === "" && (!this.max || this.required) && /*???*/ !this.answer;
            if (!hidden) {
                if (gt === "") {
                    gt = "—";
                }
                if (ve.textContent !== gt) {
                    ve.textContent = gt;
                }
            }
        }
        if (hidden != null) {
            const clg = pde.closest(".pa-grade");
            toggleClass(clg, this.description ? "pa-hidden-description" : "hidden", hidden);
        }
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
        const gl = pde.closest(".pa-gradelist");
        if (!gl || !hasClass(gl, "want-landmark-links")) {
            return;
        }
        let pgbr = pde.querySelector(".pa-gradeboxref");
        if (this.landmark ? pgbr && pgbr.getAttribute("data-landmark") === this.landmark : !pgbr) {
            return;
        }
        const m = /^(.*):(\d+)$/.exec(this.landmark);
        if (m && Filediff.by_file(m[1])) {
            const pi = pde.closest(".pa-psetinfo"),
                directory = pi.getAttribute("data-pa-directory") || "",
                filename = m[1].startsWith(directory) ? m[1].substring(directory.length) : m[1];
            pgbr || pde.append((pgbr = $e("span", "pa-gradeboxref")));
            pgbr.setAttribute("data-landmark", this.landmark);
            pgbr.replaceChildren("@", $e("a", {href: "#La".concat(m[2], "F", html_id_encode(m[1]))}, filename + ":" + m[2]));
        } else {
            pgbr && pgbr.remove();
        }
    }

    unmount_at(elt) {
        this.gc.unmount.call(this, elt);
    }

    text(v) {
        return this.gc.text.call(this, v);
    }

    simple_text(v) {
        return this.gc.simple_text.call(this, v);
    }

    configure_column(col) {
        return this.gc.configure_column.call(this, col);
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
                    || (m = /^[\s❮→]*()(\d+(?:\.\d+)?|\.\d+)(\/[\d.]+(?![.,]\w|[\w%$*/])\S*?)[.,;:❯]?(?:\s|$)/.exec(note.text)))) {
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
        let gnv = gh.querySelector(".pa-notes-grade");
        if (sum === null) {
            gnv.remove();
        } else {
            if (!gnv) {
                const gs = hasClass(gh, "pa-grade") ? gh : gh.querySelector(".pa-grade");
                let e = gs.firstChild;
                while (e && !hasClass(e, "pa-pv")) {
                    e = e.nextSibling;
                }
                e = e.firstChild;
                while (e && (e.nodeType !== 1 || hasClass(e, "pa-gradewidth") || hasClass(e, "pa-gradedesc"))) {
                    e = e.nextSibling;
                }
                gnv = $e("button", {type: "button", "class": "link uic uikd pa-notes-grade"});
                this.lastChild.insertBefore(gnv, e);
            }
            gnv.textContent = "Notes grade " + sum;
        }

        return sum;
    }

    save_landmark_grade(t) {
        const sum = this.landmark_grade(t);

        const gv = t.querySelector(".pa-gradevalue");
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

    value_order_in(gi) {
        const i = gi.vpos[this.key];
        return i != null ? i : null;
    }

    value_in(gi) {
        const i = gi.grades ? gi.vpos[this.key] : null;
        return i != null ? gi.grades[i] : null;
    }

    autovalue_in(gi) {
        const i = gi.autogrades ? gi.vpos[this.key] : null;
        return i != null ? gi.autogrades[i] : null;
    }

    has_later_value_in(gi) {
        return this.answer
            && gi.grades_latest
            && this.key in gi.grades_latest
            && this.value_in(gi) !== gi.grades_latest[this.key];
    }

    static closest(elt) {
        const e = elt.closest(".pa-grade"),
            gi = GradeSheet.closest(e);
        return gi.entries[e.getAttribute("data-pa-grade")];
    }

    static late_hours() {
        late_hours_entry = late_hours_entry || new LateHoursEntry;
        return late_hours_entry;
    }
}

class LateHoursEntry extends GradeEntry {
    constructor() {
        super({key: "late_hours", title: "late hours"});
        this.normal = false;
    }
    value_in(gi) {
        return gi.late_hours;
    }
    autovalue_in(gi) {
        return gi.auto_late_hours;
    }
}

export class GradeSheet {
    constructor(x) {
        this.entries = {};
        this.parent = null;
        this.root = this;
        if (x) {
            this.assign(x);
        }
    }

    make_child() {
        const gi = new GradeSheet;
        Object.assign(gi, this);
        gi.parent = gi.root = this;
        return gi;
    }

    set_entry(ge) {
        if (this.entries !== this.root.entries) { throw new Error("!"); }
        this.entries[ge.key] = ge;
        ge._all = this.root;
    }

    assign(x) {
        const old_value_order = this.value_order;
        if (x.entries) {
            for (let i in x.entries) {
                this.set_entry(new GradeEntry(x.entries[i]));
            }
        }
        if (x.order) {
            this.order = x.order;
        }
        if (x.fixed_value_order && !this.parent) {
            this.fixed_value_order = x.fixed_value_order;
        }
        this.value_order = this.fixed_value_order || this.order;
        if (this.value_order !== old_value_order) {
            this.vpos = {};
            for (let i = 0; i !== this.value_order.length; ++i) {
                this.vpos[this.value_order[i]] = i;
            }
            if (old_value_order) {
                this.grades = this.autogrades = null;
            }
        }
        if (x.grades) {
            this.grades = this.merge_grades(this.grades, x.grades, x);
        }
        if (x.autogrades) {
            this.autogrades = this.merge_grades(this.autogrades, x.autogrades, x);
        }
        if ("scores_visible" in x) {
            if (this.parent) {
                if ((this.scores_visible_pinned = x.scores_visible != null)) {
                    this.scores_visible = x.scores_visible;
                } else {
                    this.scores_visible = this.parent.scores_visible;
                }
            } else {
                this.scores_visible = x.scores_visible;
            }
        }
        for (let k in x) {
            if (gradesheet_props[k])
                this[k] = x[k];
        }
        return this;
    }

    merge_grades(myg, ing, x) {
        let inorder = x.fixed_value_order || x.order || this.value_order;
        if (!myg && inorder === this.value_order) {
            return ing;
        } else {
            myg = myg || [];
            for (let i in inorder) {
                const j = this.vpos[inorder[i]];
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

    xentry(key) {
        const ge = this.entries[key];
        if (ge) {
            return ge;
        } else if (key === "late_hours") {
            return GradeEntry.late_hours();
        } else {
            return null;
        }
    }

    *value_entries() {
        for (const key of this.value_order) {
            yield this.entries[key];
        }
    }

    remount_at(elt, mode) {
        const ge = this.xentry(elt.getAttribute("data-pa-grade"));
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
            if (mode === 2) {
                pdx.className = "ui-submit pa-pv" + (this.description ? " pa-textv" : "");
            } else {
                pdx.className = "pa-pv";
            }
            toggleClass(elt, "e", mode !== 0);
            elt.replaceChild(pdx, pde);
            mode === 2 && removeClass(elt, "hidden");
            ge.mount_at(pdx, id, mode !== 0);
            $(pde).find("input, textarea").unautogrow();
        }
    }

    update_at(elt, opts) {
        const ge = this.xentry(elt.getAttribute("data-pa-grade"));
        // find value to assign
        let gval = ge.value_in(this);
        if (ge.has_later_value_in(this)) {
            const label = elt.firstChild;
            if (label.classList.contains("pa-grade-latest")) {
                gval = this.grades_latest[ge.key];
            } else if (!label.classList.contains("uic")) {
                label.classList.add("pa-grade-earlier", "uic", "need-tooltip");
                label.setAttribute("aria-label", "Toggle latest version");
            }
        }
        // perform update
        const xopts = {gradesheet: this, autograde: ge.autovalue_in(this)};
        if (opts) {
            Object.assign(xopts, opts);
        }
        ge.update_at(elt, gval, xopts);
    }

    grade_total(noextra) {
        let total = null;
        for (let i = 0; i !== this.value_order.length; ++i) {
            const ge = this.entries[this.value_order[i]];
            if (ge && ge.in_total && (!noextra || !ge.is_extra)) {
                const gv = this.grades && this.grades[i];
                if (gv != null) {
                    total = (total || 0) + gv;
                }
            }
        }
        return total !== null ? Math.round(total * 1000) / 1000 : null;
    }

    grade_maxtotal() {
        let maxtotal = null;
        for (let i = 0; i !== this.value_order.length; ++i) {
            const ge = this.entries[this.value_order[i]];
            if (ge && ge.in_total && !ge.is_extra && ge.max
                && (!ge.required
                    || this.scores_editable
                    || (this.grades && this.grades[i] !== null))) {
                maxtotal = (maxtotal || 0) + ge.max;
            }
        }
        return maxtotal !== null ? Math.round(maxtotal * 1000) / 1000 : null;
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
        let start = this.vpos[ge.key];
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
        return this.scores_editable && this.section_has(ge, gei => {
            answer |= gei.answer ? 1 : 2;
            return answer === 3;
        });
    }

    add_linenotes(linenotes) {
        this.linenotes = this.linenotes || {};
        for (const [k, v] of Object.entries(linenotes || {})) {
            this.linenotes[k] = this.linenotes[k] || {};
            Object.assign(this.linenotes[k], v);
        }
    }

    static store(element, x) {
        if (!hasClass(element, "pa-psetinfo")) {
            throw new Error("bad GradeSheet.store");
        }
        const gi = GradeSheet.closest(element);
        gi.assign(x);
        window.$pa.loadgrades.call(element);
    }

    static closest(element) {
        element = element.closest(".pa-psetinfo");
        if (!element) {
            return null;
        }
        if (element.pa__gradesheet) {
            return element.pa__gradesheet;
        }
        let gi;
        if (hasClass(element, "pa-psetinfo-partial")
            && (gi = GradeSheet.closest(element.parentElement))) {
            gi = gi.make_child();
        }
        gi = gi || new GradeSheet;
        if (element.hasAttribute("data-pa-gradeinfo")) {
            try {
                let x = JSON.parse(element.getAttribute("data-pa-gradeinfo") || "{}");
                gi.assign(x);
            } catch {
            }
        }
        Object.defineProperty(element, "pa__gradesheet", {
            value: gi, configurable: true, writable: true
        });
        return gi;
    }
}
