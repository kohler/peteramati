// gc-markdown.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { render_text } from "./render.js";
import { hasClass, addClass, removeClass, handle_ui } from "./ui.js";


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
