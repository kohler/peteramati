// gc-markdown.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { render_onto } from "./render.js";
import { hasClass, addClass, handle_ui } from "./ui.js";


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
            opts.gradesheet.show_text_as_diff(ve, v, `/g/${this.key}`);
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
