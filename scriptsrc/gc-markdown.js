// gc-markdown.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { render_onto } from "./render.js";
import { hasClass, addClass, removeClass, handle_ui } from "./ui.js";
import { Filediff } from "./diff.js";
import { Note } from "./note.js";


function update_answer_show(key, ve, v, opts) {
    const gi = opts.gradesheet;
    addClass(ve, "bg-none");
    addClass(ve, "align-self-start");
    const div = document.createElement("div");
    div.className = "pa-filediff pa-dg pa-hide-left pa-hide-landmarks uim" + (gi.scores_editable ? " pa-editablenotes live" : "") + (gi.scores_visible ? "" : " pa-scores-hidden");
    const fileid = "/g/" + key;
    div.id = gi.file_anchor(fileid);
    let pos1 = 0, lineno = 1;
    while (pos1 !== v.length) {
        let pos2 = v.indexOf("\n", pos1);
        pos2 = pos2 < 0 ? v.length : pos2 + 1;
        const line = document.createElement("div"),
            da = document.createElement("div"),
            db = document.createElement("div"),
            dd = document.createElement("div");
        line.className = "pa-dl pa-gi";
        da.className = "pa-da";
        da.hidden = true;
        db.className = "pa-db";
        db.hidden = true;
        db.setAttribute("data-landmark", lineno);
        dd.className = "pa-dd pa-dhlm";
        dd.textContent = v.substring(pos1, pos2);
        line.appendChild(da);
        line.appendChild(db);
        line.appendChild(dd);
        div.appendChild(line);
        ++lineno;
        pos1 = pos2;
    }
    ve.replaceChildren(div);
    const fd = new Filediff(div);
    if (hasClass(ve.parentElement, "pa-markdown")) {
        fd.markdown();
    }
    let ln;
    if (gi.linenotes
        && (ln = gi.linenotes[fileid])) {
        for (let lineid in ln) {
            add_note_at(fd, lineid, Note.parse(ln[lineid]));
        }
    }
}

function add_note_at(fd, lineid, note) {
    fd.line(lineid).then(line => {
        note.source = line.element;
        note.render(false);
    });
}

GradeClass.add("markdown", {
    text: function (v) {
        return v == null ? "" : "" + v;
    },
    mount_show: function (elt, id) {
        elt.id = id;
        addClass(elt, "pa-textv");
        addClass(elt, "pa-gradevalue");
    },
    mount_edit: function (elt, id) {
        addClass(elt, "pa-textv");
        const descspan = document.createElement("span");
        descspan.append("Markdown styling and LaTeX math supported · ");
        const desclink = document.createElement("a");
        desclink.href = "";
        desclink.className = "ui js-toggle-gc-markdown-preview";
        desclink.tabIndex = -1;
        desclink.append("Preview");
        const descp = document.createElement("p");
        descp.className = "pa-preview-notice";
        descp.append(descspan, desclink);
        const ta = document.createElement("textarea");
        ta.className = "uich uii pa-gradevalue need-autogrow pa-fresh";
        ta.name = this.key;
        ta.id = id;
        ta.disabled = this.disabled;
        const df = new DocumentFragment;
        df.append(descp, ta);
        return df;
    },
    update_show: function (ve, v, opts) {
        if (v == null || v === "") {
            ve.replaceChildren();
        } else if (this.answer) {
            update_answer_show(this.key, ve, v, opts);
        } else if (hasClass(ve.parentElement, "pa-markdown")) {
            render_onto(ve, 1, v);
        } else {
            render_onto(ve, 0, v);
        }
        return ve.firstChild === null;
    },
    justify: "left",
    sort: "forward",
    type_tabular: false
});

handle_ui.on("js-toggle-gc-markdown-preview", function () {
    const pd = this.closest(".pa-pv"),
        ta = pd.querySelector("textarea");
    if (ta.hidden) {
        ta.parentElement.removeChild(ta.previousSibling);
        ta.hidden = false;
        ta.focus();
        this.textContent = "Preview";
        this.previousSibling.hidden = false;
    } else {
        const div = document.createElement("div"),
            hr1 = document.createElement("hr"),
            hr2 = document.createElement("hr"),
            inner = document.createElement("div");
        div.className = "pa-preview";
        hr1.className = hr2.className = "pa-preview-border";
        inner.className = "pa-dr";
        div.append(hr1, inner, hr2);
        ta.parentElement.insertBefore(div, ta);
        ta.hidden = true;
        render_onto(inner, 1, ta.value);
        this.textContent = "Edit";
        this.previousSibling.hidden = true;
    }
});
