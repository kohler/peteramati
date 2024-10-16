// ptable-run.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { handle_ui, $e } from "./ui.js";
import { hoturl_post_go } from "./hoturl.js";
import { $popup } from "./popup.js";


handle_ui.on("js-ptable-run", function () {
    let f = this.closest("form"),
        ptconf = f.pa__ptconf, $gdialog, form, slist;

    function submit() {
        if (form.elements.runner.value) {
            const run = form.elements.runner.value +
                    (form.elements.ifneeded.checked ? ".ifneeded" : ""),
                skeys = [],
                param = {pset: ptconf.key, run: run, runmany: 1},
                data = {};
            for (const su of slist) {
                skeys.push(ptconf.ukey(su));
            }
            const users = skeys.join(" ");
            if (users.length <= 160) {
                param.users = users;
            } else {
                data.users = users;
            }
            if (form.elements.selectcommits.checked) {
                param.commitq = form.elements.commitq.value;
            }
            hoturl_post_go("=run", param, data);
        } else {
            $gdialog.close();
        }
    }

    function gdialog() {
        slist = ptconf.users_in(f, ptconf.SOME_USERS);

        const ru = $e("div", "mt-1 multicol-2");
        for (let rn in ptconf.runners) {
            ru.append($e("label", "checki",
                $e("span", "checkc", $e("input", {type: "radio", name: "runner", value: rn})),
                ptconf.runners[rn]));
        }

        $gdialog = $popup()
            .append($e("h2", "pa-home-pset", ptconf.title + " Commands"),
                $e("h3", "gdialog-userids"),
                $e("div", "pa-messages"),
                ru,
                $e("label", "checki mt-2",
                    $e("span", "checkc", $e("input", {type: "checkbox", name: "ifneeded"})),
                    "Use prerecorded runs when available"),
                $e("label", "checki mt-2 has-fold foldc",
                    $e("span", "checkc", $e("input", {type: "checkbox", name: "selectcommits", class: "uic js-foldup", "data-fold-target": "#U"})),
                    "Select commits",
                    $e("span", "fx", ": ", $e("input", {type: "text", name: "commitq", placeholder: "grading", class: "ml-1"}))))
            .append_actions($e("button", {type: "button", name: "run", class: "btn-primary"}, "Run"), "Cancel");
        ptconf.render_gdialog_users($gdialog.querySelector("h3"), slist);
        form = $gdialog.form();
        $(form.elements.run).on("click", submit);
        $gdialog.show();
    }

    gdialog();
});
