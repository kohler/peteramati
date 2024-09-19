// ptable-diff.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, handle_ui, $e } from "./ui.js";
import { hoturl_get_go } from "./hoturl.js";
import { escape_entities } from "./encoders.js";
import { popup_skeleton } from "./popup.js";


function gdialog_section_click(event) {
    if (event.type === "click" && !event.shiftKey) {
        const checked = this.checked;
        let l = this.closest("label");
        while ((l = l.nextSibling)) {
            const ch = l.firstChild.firstChild;
            if (ch.classList.contains("pa-gdialog-section"))
                break;
            ch.checked = checked;
        }
    }
}


function ptable_diffdialog(ptconf, sus) {
    let $gdialog, gdform,
        gradesheet = ptconf.gradesheet;

    function sus_uids() {
        const uids = [];
        for (const su of sus) {
            uids.push(su.uid);
        }
        return uids.join(" ");
    }

    function do_diff() {
        const v = gdform.elements.diff.value,
            opt = {pset: ptconf.key, anonymous: ptconf.anonymous ? 1 : "", users: sus_uids()};
        if (v === "other" && gdform.elements.otherdiff.value) {
            opt.files = gdform.elements.otherdiff.value.trim();
        } else if (v.startsWith("file:") && v.length > 5) {
            opt.file = v.substring(5);
        } else if (v.startsWith("grade:") && v.length > 6) {
            const ge = gradesheet.entries[v.substring(6)];
            if (ge.landmark_range_file) {
                opt.file = ge.landmark_range_file;
                opt.lines = ge.landmark_range_first + "-" + ge.landmark_range_last;
            } else {
                opt.grade = ge.key;
            }
        }
        hoturl_get_go("diffmany", opt);
    }

    function do_gradesheet() {
        const $gsi = $gdialog.find(".pa-gdialog-gradesheet input"),
            ge = [];
        for (let i = 0; i !== $gsi.length; ++i) {
            if ($gsi[i].checked)
                ge.push(gradesheet.entries[$gsi[i].name]);
        }
        if (ge.length === 0) {
            alert("No grades selected.");
        } else {
            const opt = {pset: ptconf.key, anonymous: ptconf.anonymous ? 1 : "", users: sus_uids()};
            if (ge.length === 1 && ge[0].landmark_range_file) {
                opt.file = ge[0].landmark_range_file;
                opt.lines = ge[0].landmark_range_first + "-" + ge[0].landmark_range_last;
            }
            opt.grade = ge[0].key;
            for (let i = 1; i !== ge.length; ++i) {
                opt.grade += " " + ge[i].key;
            }
            hoturl_get_go("diffmany", opt);
        }
    }

    function do_report() {
        const report = gdform.elements.report.value || "default";
        if (report === "copyemails") {
            const us = [];
            for (const su of sus) {
                let name = "";
                if (su.first != null && su.last != null) {
                    name = su.first.concat(" ", su.last);
                } else if (su.first != null) {
                    name = su.first;
                } else if (su.last != null) {
                    name = su.last;
                }
                if (name !== "" && /^[-A-Z a-z]*$/.test(name)) {
                    us.push(name.concat(" <", su.email, ">"));
                } else if (name !== "") {
                    name = name.replace(/\"/g, "\\\"");
                    us.push("\"".concat(name, "\" <", su.email, ">"));
                } else {
                    us.push(su.email);
                }
            }
            navigator.clipboard.writeText(us.join(", "));
            $gdialog.close();
        } else {
            const opt = {
                pset: ptconf.key,
                anonymous: ptconf.anonymous ? 1 : "",
                users: sus_uids(),
                report: gdform.elements.report.value || "default"
            };
            hoturl_get_go("report", opt);
        }
    }

    function do_submit() {
        if (hasClass(gdform.elements["mode-diff"], "btn-primary")) {
            do_diff();
        } else if (hasClass(gdform.elements["mode-gradesheet"], "btn-primary")) {
            do_gradesheet();
        } else {
            do_report();
        }
    }

    function do_key(event) {
        if (event.key === "Return" || event.key === "Enter") {
            do_submit(null);
            event.stopImmediatePropagation();
            event.preventDefault();
        } else if (event.key === "Esc" || event.key === "Escape") {
            event.stopImmediatePropagation();
            event.preventDefault();
            $gdialog.close();
        }
    }

    function mode_diff() {
        const dv = $gdialog.find(".pa-gdialog-diff")[0];
        if (!dv.firstChild) {
            const es = [];
            es.push($e("label", "checki", $e("span", "checkc", $e("input", {type: "radio", name: "diff", value: "all", checked: true})), $e("strong", null, "Full diffs")));
            let sep = " mt-2";
            for (const fd of ptconf.diff_files) {
                es.push($e("label", "checki" + sep, $e("span", "checkc", $e("input", {type: "radio", name: "diff", value: "file:" + fd})), fd));
                sep = "";
            }
            sep = " mt-2";
            for (const gkey of gradesheet.order) {
                const ge = gradesheet.entries[gkey];
                if (ge.collate) {
                    es.push($e("label", "checki" + sep, $e("span", "checkc", $e("input", {type: "radio", name: "diff", value: "grade:" + ge.key})), ge.title_node));
                    sep = "";
                }
            }
            if (!ptconf.gitless) {
                const input = $e("input", {type: "text", name: "otherdiff", size: "30", class: "ml-2"});
                es.push($e("label", "checki mt-2", $e("span", "checkc", $e("input", {type: "radio", name: "diff", value: "other"})), "Diffs in ", input));
                input.addEventListener("input", function () {
                    this.closest("label").querySelector("input[type=radio]").click();
                });
            }
            dv.replaceChildren(...es);
        }
        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        dv.classList.remove("hidden");
        gdform.elements.bsubmit.textContent = "Show diffs";
    }
    function mode_gradesheet() {
        const gs = $gdialog.find(".pa-gdialog-gradesheet")[0];
        if (!gs.firstChild) {
            const es = [];
            let in_section = false;
            for (const gkey of gradesheet.order) {
                const ge = gradesheet.entries[gkey],
                    gcl = in_section && ge.type !== "section" ? "checki ml-4" : "checki",
                    ccl = ge.type === "section" ? " pa-gdialog-section" : "";
                es.push($e("label", gcl, $e("span", "checkc", $e("input", {type: "checkbox", name: ge.key, class: "uic js-rage-click" + ccl, "data-range-type": "mge"})), ge.title_node));
                in_section = in_section || ge.type === "section";
            }
            gs.replaceChildren(...es);
        }
        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        gs.classList.remove("hidden");
        gdform.elements.bsubmit.textContent = "Edit gradesheet";
    }
    function mode_report() {
        const dv = $gdialog.find(".pa-gdialog-report")[0];
        if (!dv.firstChild) {
            const es = [];
            es.push($e("label", "checki", $e("span", "checkc", $e("input", {type: "radio", name: "report", value: "default", checked: true})), "Grades"));
            if (!ptconf.gitless) {
                es.push($e("label", "checki", $e("span", "checkc", $e("input", {type: "radio", name: "report", value: "git"})), "Git information"));
                es.push($e("label", "checki", $e("span", "checkc", $e("input", {type: "radio", name: "report", value: "githistory"})), "Git history"));
            }
            for (const fd of ptconf.reports) {
                es.push($e("label", "checki", $e("span", "checkc", $e("input", {type: "radio", name: "report", value: fd.key})), fd.title));
            }
            es.push($e("label", "checki", $e("span", "checkc", $e("input", {type: "radio", name: "report", value: "copyemails"})), "Copy emails to clipboard"));
            dv.replaceChildren(...es);
        }
        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        dv.classList.remove("hidden");
        gdform.elements.bsubmit.textContent = "Download";
    }
    function do_mode() {
        $gdialog.find(".nav-pills button").removeClass("btn-primary");
        if (this.name === "mode-report") {
            mode_report();
        } else if (this.name === "mode-gradesheet") {
            mode_gradesheet();
        } else {
            mode_diff();
        }
        this.classList.add("btn-primary");
    }

    function show() {
        const hc = popup_skeleton();
        hc.push('<h2 class="pa-home-pset">' + escape_entities(ptconf.title) + ' Diffs</h2>');
        hc.push('<h3 class="gdialog-userids hidden"></h3>');
        hc.push('<div class="pa-messages"></div>');

        hc.push('<div class="nav-pills">', '</div>');
        hc.push('<button type="button" class="btn btn-primary no-focus is-mode" name="mode-diff">Diff</button>');
        hc.push('<button type="button" class="btn no-focus is-mode" name="mode-gradesheet">Gradesheet</button>');
        hc.push('<button type="button" class="btn no-focus is-mode" name="mode-report">Reports</button>');
        hc.pop();

        hc.push('<div class="pa-gdialog-tab pa-gdialog-diff is-modal"></div>');
        hc.push('<div class="pa-gdialog-tab pa-gdialog-gradesheet multicol-3 hidden"></div>');
        hc.push('<div class="pa-gdialog-tab pa-gdialog-report hidden"></div>');

        hc.push_actions();
        hc.push('<button type="button" name="bsubmit" class="btn-primary">Save</button>');
        hc.push('<button type="button" name="cancel">Cancel</button>');
        $gdialog = hc.show(false);
        $gdialog.children(".modal-dialog").addClass("modal-dialog-wide");
        gdform = $gdialog.find("form")[0];
        addClass(gdform, "pa-psetinfo");
        gdform.pa__gradesheet = ptconf.gradesheet;
        mode_diff();
        $gdialog.on("click", ".pa-gdialog-section", gdialog_section_click);
        $gdialog.on("keydown", do_key);
        $gdialog.on("keydown", "input, textarea, select", do_key);
        $(gdform.elements.bsubmit).on("click", do_submit);
        $gdialog.find("button.is-mode").on("click", do_mode);
        hc.show();
    }

    show();
}

handle_ui.on("js-ptable-diff", function () {
    const f = this.closest("form"), ptconf = f.pa__ptconf;
    ptable_diffdialog(ptconf, ptconf.users_in(f, ptconf.SOME_USERS));
});
