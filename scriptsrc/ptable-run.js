// ptable-run.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { handle_ui } from "./ui.js";
import { hoturl_post_go } from "./hoturl.js";
import { escape_entities } from "./encoders.js";
import { popup_skeleton, popup_close } from "./popup.js";


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
            popup_close.call(form);
        }
    }

    function gdialog() {
        slist = ptconf.users_in(f, ptconf.SOME_USERS);

        const hc = popup_skeleton();
        hc.push('<h2 class="pa-home-pset">' + escape_entities(ptconf.title) + ' Commands</h2>');
        hc.push('<h3 class="gdialog-userids"></h3>');
        hc.push('<div class="pa-messages"></div>');

        hc.push('<div class="mt-1 multicol-2">', '</div>');
        for (let rn in ptconf.runners) {
            hc.push('<label class="checki"><span class="checkc"><input type="radio" name="runner" value="' + rn + '"></span>' + escape_entities(ptconf.runners[rn]) + '</label>');
        }
        hc.pop();

        hc.push('<label class="checki mt-2"><span class="checkc"><input type="checkbox" name="ifneeded"></span>Use prerecorded runs when available</label>');

        hc.push('<label class="checki mt-2 has-fold foldc"><span class="checkc"><input type="checkbox" name="selectcommits" class="uic js-foldup" data-fold-target="#U"></span>Select commits<span class="fx">: <input type="text" name="commitq" placeholder="grading" class="ml-1"></span></label>');

        hc.push_actions();
        hc.push('<button type="button" name="run" class="btn-primary">Run</button>');
        hc.push('<button type="button" name="cancel">Cancel</button>');
        $gdialog = hc.show(false);
        ptconf.render_gdialog_users($gdialog.find("h3")[0], slist);
        form = $gdialog.find("form")[0];
        $(form.elements.run).on("click", submit);
        hc.show();
    }

    gdialog();
});
