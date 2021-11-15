// gc-markdown.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { render_text } from "./render.js";
import { hasClass, addClass, removeClass, handle_ui } from "./ui.js";
import { Filediff } from "./diff.js";
import { html_id_encode } from "./encoders.js";
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
        addClass(elt, "pa-gradevalue");
    },
    mount_edit: function (elt, id) {
        return '<p class="pa-preview-notice"><span>Markdown styling and LaTeX math supported · </span><a href="" class="ui js-toggle-gc-markdown-preview" tabindex="-1">Preview</a></p><div class="pa-textgrade-width pa-wide"><textarea class="uich pa-pd pa-gradevalue need-autogrow" name="'.concat(this.key, '" id="', id, '"></textarea></div>');
    },
    update_show: function (ve, v) {
        if (v == null || v === "") {
            ve.innerHTML = "";
        } else if (false) {
            const div = document.createElement("div");
            div.className = "pa-filediff pa-dg pa-hide-left uim pa-editablenotes live";
            let fileid = "/g/" + html_id_encode(this.key);
            if (hasClass(document.body, "pa-multiuser")) {
                div.id = "U".concat(html_id_encode(ve.closest(".pa-psetinfo").getAttribute("data-pa-user")), "/F", fileid);
            } else {
                div.id = "F" + fileid;
            }
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
                db.className = "pa-db";
                db.setAttribute("data-landmark", lineno);
                dd.className = "pa-dd";
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
            if (!hasClass(ve, "pa-markdown")) {
                fd.markdown();
            }
            let ln;
            if (this._all.linenotes
                && (ln = this._all.linenotes[fileid])) {
                for (let lineid in ln) {
                    add_note_at(fd, lineid, Note.parse(ln[lineid]));
                }
            }
        } else if (hasClass(ve, "pa-markdown")) {
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
    const pd = this.closest(".pa-pd"),
        ta = pd.querySelector("textarea");
    if (hasClass(ta, "hidden")) {
        ta.parentElement.removeChild(ta.previousSibling);
        removeClass(ta, "hidden");
        ta.focus();
        this.innerHTML = "Preview";
        removeClass(this.previousSibling, "hidden");
    } else {
        $(ta).before('<div class="pa-preview"><hr class="pa-preview-border"><div class="pa-dr"></div><hr class="pa-preview-border"></div>');
        addClass(ta, "hidden");
        render_text(1, ta.value, ta.previousSibling.firstChild.nextSibing);
        this.innerHTML = "Edit";
        addClass(this.previousSibling, "hidden");
    }
});
