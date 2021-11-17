// gc-markdown.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { render_text } from "./render.js";
import { hasClass, addClass, removeClass, handle_ui } from "./ui.js";
import { Filediff } from "./diff.js";
import { Note } from "./note.js";


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
        return '<p class="pa-preview-notice"><span>Markdown styling and LaTeX math supported · </span><a href="" class="ui js-toggle-gc-markdown-preview" tabindex="-1">Preview</a></p><textarea class="uich pa-gradevalue need-autogrow" name="'.concat(this.key, '" id="', id, '"></textarea>');
    },
    update_show: function (ve, v) {
        if (v == null || v === "") {
            ve.innerHTML = "";
        } else if (this.answer) {
            addClass(ve, "bg-none");
            addClass(ve, "align-self-start");
            const div = document.createElement("div");
            div.className = "pa-filediff pa-dg pa-hide-left pa-hide-landmarks uim" + (this._all.editable_scores ? " pa-editablenotes live" : "");
            let fileid = "/g/" + this.key;
            div.id = this._all.file_anchor(fileid);
            let pos1 = 0, lineno = 1;
            while (pos1 !== v.length) {
                let pos2 = v.indexOf("\n", pos1);
                pos2 = pos2 < 0 ? v.length : pos2 + 1;
                const line = document.createElement("div"),
                    da = document.createElement("div"),
                    db = document.createElement("div"),
                    dd = document.createElement("div");
                line.className = "pa-dl pa-gi";
                da.className = "pa-da hidden";
                db.className = "pa-db hidden";
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
            if (this._all.linenotes
                && (ln = this._all.linenotes[fileid])) {
                for (let lineid in ln) {
                    add_note_at(fd, lineid, Note.parse(ln[lineid]));
                }
            }
        } else if (hasClass(ve.parentElement, "pa-markdown")) {
            render_text(1, v, ve);
        } else {
            render_text(0, v, ve);
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
    if (hasClass(ta, "hidden")) {
        ta.parentElement.removeChild(ta.previousSibling);
        removeClass(ta, "hidden");
        ta.focus();
        this.innerHTML = "Preview";
        removeClass(this.previousSibling, "hidden");
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
        addClass(ta, "hidden");
        render_text(1, ta.value, inner);
        this.innerHTML = "Edit";
        addClass(this.previousSibling, "hidden");
    }
});
