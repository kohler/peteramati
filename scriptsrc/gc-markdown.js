// gc-markdown.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { render_ftext } from "./render.js";
import { hasClass, addClass, removeClass, handle_ui } from "./ui.js";


GradeClass.add("markdown", {
    text: function (v) {
        return v == null ? "" : "" + v;
    },
    fill_dom: function (v, e) {
        if (v == null || v === "") {
            e.innerHTML = "";
        } else {
            e.innerHTML = render_ftext('<1>'.concat(v));
        }
    },
    entry: function (id) {
        return '<p class="pa-preview-notice"><span>Markdown styling and LaTeX math supported · </span><a href="" class="ui js-toggle-gc-markdown-preview" tabindex="-1">Preview</a></p><textarea class="uich pa-pd pa-gradevalue pa-wide need-autogrow" name="'.concat(this.key, '" id="', id, '"></textarea>');
    },
    justify: "left",
    sort: "forward",
    type_tabular: false
});

handle_ui.on("js-toggle-gc-markdown-preview", function () {
    const pd = this.closest(".pa-pd"),
        ta = pd.querySelector("textarea");
    if (hasClass(ta, "hidden")) {
        pd.removeChild(ta.previousSibling);
        removeClass(ta, "hidden");
        ta.focus();
        this.innerHTML = "Preview";
        removeClass(this.previousSibling, "hidden");
    } else {
        $(ta).before('<div class="pa-preview"><hr class="pa-preview-border"><div class="pa-dr"></div><hr class="pa-preview-border"></div>');
        addClass(ta, "hidden");
        ta.previousSibling.firstChild.nextSibling.innerHTML = render_ftext('<1>'.concat(ta.value));
        this.innerHTML = "Edit";
        addClass(this.previousSibling, "hidden");
    }
});
