// gc-markdown.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
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
    const fileid = "/g/" + key;
    let div = ve.firstChild;
    if (!div) {
        div = document.createElement("div");
        div.className = "pa-filediff";
        div.id = gi.file_anchor(fileid);
        ve.appendChild(div);
    }
    if (div.tagName !== "DIV" || div.nextSibling || !hasClass(div, "pa-filediff")) {
        throw new Error("bad ve.firstChild in gc-markdown");
    }
    div.className = "pa-filediff pa-dg pa-hide-left pa-hide-landmarks uim" + (gi.scores_editable ? " pa-editablenotes live" : "") + (gi.scores_visible ? "" : " pa-scores-hidden");

    // apply new lines
    let pos1 = 0, lineno = 1, dl = div.firstChild;
    while (pos1 !== v.length) {
        let pos2 = v.indexOf("\n", pos1);
        pos2 = pos2 < 0 ? v.length : pos2 + 1;
        const str = v.substring(pos1, pos2);
        // find next textual line
        while (dl && dl.className !== "pa-dl pa-gi") {
            const ndl = dl.nextSibling;
            if (!hasClass(dl, "pa-gw")) {
                dl.remove();
            }
            dl = ndl;
        }
        // insert line or replace its contents
        if (!dl) {
            dl = document.createElement("div");
            dl.className = "pa-dl pa-gi";
            const da = document.createElement("div"),
                db = document.createElement("div"),
                dd = document.createElement("div");
            da.className = "pa-da";
            da.hidden = true;
            db.className = "pa-db";
            db.hidden = true;
            db.setAttribute("data-landmark", lineno);
            dd.className = "pa-dd pa-dhlm";
            dl.append(da, db, dd);
            div.append(dl);
        } else if (dl.firstChild.nextSibling.getAttribute("data-landmark") != lineno) {
            throw new Error(`bad data-landmark in gc-markdown, ${dl.firstChild.nextSibling.getAttribute("data-landmark")} vs. ${lineno}`);
        }
        dl.lastChild.textContent = str;
        dl = dl.nextSibling;
        ++lineno;
        pos1 = pos2;
    }

    // remove old lines
    while (dl) {
        const ndl = dl.nextSibling;
        if (!hasClass(dl, "pa-gw")) {
            dl.remove();
        }
        dl = ndl;
    }

    // apply markdown
    const fd = new Filediff(div);
    if (hasClass(ve.parentElement, "pa-markdown")) {
        fd.markdown();
    }
    let ln;
    if (gi.linenotes
        && (ln = gi.linenotes[fileid])) {
        for (let lineid in ln) {
            add_note_at(fd, lineid, ln[lineid]);
        }
    }
}

function add_note_at(fd, lineid, ln) {
    fd.line(lineid).then(line => {
        Note.near(line).assign(ln).render(false);
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
