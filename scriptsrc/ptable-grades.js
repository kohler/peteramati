// ptable-grades.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { addClass, removeClass, toggleClass, HtmlCollector, input_differs, handle_ui } from "./ui.js";
import { hoturl } from "./hoturl.js";
import { api_conditioner } from "./xhr.js";
import { escape_entities } from "./encoders.js";
import { popup_skeleton } from "./popup.js";
import { render_xmsg } from "./render.js";
import { GradeSheet } from "./gradeentry.js";


function make_uid2tr(tr) {
    const uid2tr = {};
    while (tr) {
        const uid = tr.getAttribute("data-pa-uid");
        uid && (uid2tr[tr.getAttribute("data-pa-uid")] = tr);
        tr = tr.nextSibling;
    }
    return uid2tr;
}

function grade_update(ptconf, uid2tr, rv, refreshflags) {
    const tr = uid2tr[rv.uid], su = ptconf.assign_user(rv);
    let ngrades = 0;
    for (let gv of su.grades || []) {
        if (gv != null && gv !== "")
            ++ngrades;
    }
    su.ngrades = ngrades;
    for (let c of ptconf.col) {
        if (((c.refreshable || 0) & refreshflags) !== 0)
            c.td.call(c, tr.childNodes[c.index], su, ptconf);
    }
}

function gdialog_gradelist_change() {
    toggleClass(this.closest(".pa-pv"), "pa-grade-changed",
                this.hasAttribute("data-pa-unmixed") || input_differs(this));
}

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


export function ptable_gdialog(ptconf, checked_spos, table, hlgrade) {
    let $gdialog, gdialog_su,
        gradesheet = ptconf.gradesheet,
        uid2tr = make_uid2tr(table.tBodies[0].firstChild);

    function gdialog_store_start(rv) {
        $gdialog.find(".has-error").removeClass("has-error");
        if (rv.ok) {
            $gdialog.find(".pa-messages").html("");
        } else {
            $gdialog.find(".pa-messages").html(render_xmsg(2, escape_entities(rv.error)));
            if (rv.errf) {
                $gdialog.find(".pa-gradevalue").each(function () {
                    if (rv.errf[this.name])
                        addClass(this, "has-error");
                });
            }
        }
    }

    function gdialog_store(next) {
        let any = false, byuid = {};
        $gdialog.find(".pa-gradevalue").each(function () {
            if ((this.hasAttribute("data-pa-unmixed") || input_differs(this))
                && !this.indeterminate) {
                let k = this.name, ge = gradesheet.entries[k], v;
                if (this.type === "checkbox") {
                    v = this.checked ? this.value : "";
                } else {
                    v = $(this).val();
                }
                for (let su of gdialog_su) {
                    byuid[su.uid] = byuid[su.uid] || {grades: {}, oldgrades: {}};
                    byuid[su.uid].grades[k] = v;
                    byuid[su.uid].oldgrades[k] = ge.value_in(su);
                }
                any = true;
            }
        });
        next = next || function () { $gdialog.close(); };
        if (!any) {
            next();
        } else if (gdialog_su.length === 1) {
            api_conditioner(hoturl("=api/grade", ptconf.url_gradeparts(gdialog_su[0])),
                byuid[gdialog_su[0].uid])
            .then(function (rv) {
                gdialog_store_start(rv);
                if (rv.ok) {
                    grade_update(ptconf, uid2tr, rv, 1);
                    next();
                }
            });
        } else {
            for (let su of gdialog_su) {
                if (su.grade_commit) {
                    byuid[su.uid].commit = su.grade_commit;
                    byuid[su.uid].commit_is_grade = 1;
                } else if (su.commit) {
                    byuid[su.uid].commit = su.commit;
                }
            }
            api_conditioner(hoturl("=api/multigrade", {pset: ptconf.key}),
                {us: JSON.stringify(byuid)})
            .then(function (rv) {
                gdialog_store_start(rv);
                if (rv.ok) {
                    for (let rvu of rv.us) {
                        grade_update(ptconf, uid2tr, rvu, 1);
                    }
                    next();
                }
            });
        }
    }

    function gdialog_clearauto() {
        const byuid = {}, noauto = {};
        for (const te of ptconf.gradesheet.value_entries()) {
            noauto[te.key] = null;
        }
        for (let su of gdialog_su) {
            const x = byuid[su.uid] = {};
            if (su.grade_commit) {
                x.commit = su.grade_commit;
                x.commit_is_grade = 1;
            } else if (su.commit) {
                x.commit = su.commit;
            }
            x.autogrades = noauto;
        }
        api_conditioner(hoturl("=api/multigrade", {pset: ptconf.key}),
            {us: JSON.stringify(byuid)})
        .then(function (rv) {
            gdialog_store_start(rv);
            if (rv.ok) {
                for (let rvu of rv.us) {
                    grade_update(ptconf, uid2tr, rvu, 1);
                }
                $gdialog.close();
            }
        });
    }

    function gdialog_traverse() {
        const next_spos = this.getAttribute("data-pa-spos");
        gdialog_store(function () { gdialog_fill([next_spos]); });
    }

    function gdialog_fill_user(su1) {
        let tr = table.tBodies[0].firstChild, tr1;
        while (tr && tr.getAttribute("data-pa-spos") != su1._spos) {
            tr = tr.nextSibling;
        }
        for (tr1 = tr; tr1 && (tr1 === tr || !tr1.hasAttribute("data-pa-spos")); ) {
            tr1 = tr1.previousSibling;
        }
        $gdialog.find("button[name=prev]").attr("data-pa-spos", tr1 ? tr1.getAttribute("data-pa-spos") : "").prop("disabled", !tr1).removeClass("hidden");
        for (tr1 = tr; tr1 && (tr1 === tr || !tr1.hasAttribute("data-pa-spos")); ) {
            tr1 = tr1.nextSibling;
        }
        $gdialog.find("button[name=next]").attr("data-pa-spos", tr1 ? tr1.getAttribute("data-pa-spos") : "").prop("disabled", !tr1).removeClass("hidden");
    }
    function gdialog_fill(spos) {
        gdialog_su = [];
        for (let i = 0; i !== spos.length; ++i) {
            gdialog_su.push(ptconf.smap[spos[i]]);
        }
        ptconf.render_gdialog_users($gdialog.find("h3")[0], gdialog_su);
        if (gdialog_su.length === 1) {
            gdialog_fill_user(gdialog_su[0]);
        }

        $gdialog.find(".pa-grade").each(function () {
            let k = this.getAttribute("data-pa-grade"),
                ge = gradesheet.entries[k],
                gidx = ge.value_order_in(gradesheet),
                sv = gdialog_su[0].grades[gidx],
                opts = {reset: true, mixed: false};
            for (let i = 1; i !== gdialog_su.length; ++i) {
                let suv = gdialog_su[i].grades[gidx];
                if (suv !== sv
                    && !(suv == null && sv === "")
                    && !(suv === "" && sv == null)) {
                    sv = null;
                    opts.mixed = true;
                    break;
                }
            }
            ge.update_at(this, sv, opts);
        });
    }

    function gdialog_key(event) {
        let $b;
        if (event.ctrlKey
            && (event.key === "n" || event.key === "p")
            && ($b = $gdialog.find("button[name=" + (event.key === "n" ? "next" : "prev") + "]"))
            && !$b[0].disabled) {
            gdialog_traverse.call($b[0]);
            event.stopImmediatePropagation();
            event.preventDefault();
        } else if (event.key === "Return" || event.key === "Enter") {
            gdialog_store(null);
            event.stopImmediatePropagation();
            event.preventDefault();
        } else if (event.key === "Esc" || event.key === "Escape") {
            event.stopImmediatePropagation();
            event.preventDefault();
            $gdialog.close();
        } else if (event.key === "Backspace"
                   && this.hasAttribute("placeholder")
                   && this.closest(".pa-gradelist")) {
            gdialog_gradelist_input.call(this);
        }
    }

    function gdialog_gradelist_input() {
        removeClass(this, "has-error");
        if (this.hasAttribute("placeholder")) {
            this.setAttribute("data-pa-unmixed", 1);
            this.removeAttribute("placeholder");
            gdialog_gradelist_change.call(this);
        }
    }

    function gdialog_mode_values() {
        const gl = $gdialog.find(".pa-gradelist")[0];
        if (!gl.firstChild) {
            const gi = GradeSheet.closest(gl);
            for (const ge of gi.value_entries()) {
                if (!ge.readonly) {
                    gl.appendChild(ge.render(gi, 1));
                }
            }
        }
        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        gl.classList.remove("hidden");
        $gdialog.find("button[name=bsubmit]").text("Save").removeClass("hidden");
    }
    function gdialog_settings_submit() {
        const gs = $gdialog.find(".pa-gdialog-settings")[0],
            f = gs.closest("form"),
            us = {};
        for (let su of gdialog_su) {
            us[su.uid] = {uid: su.uid};
        }
        if (this.name === "save-gvis") {
            let gvisarg;
            $(gs).find(".pa-gvis").each(function () {
                if (this.checked && !this.indeterminate)
                    gvisarg = JSON.parse(this.value);
            });
            for (let su of gdialog_su) {
                us[su.uid].scores_visible = gvisarg;
            }
        } else if (this.name === "save-grader"
                   && f.elements.gradertype.value === "clear") {
            for (let su of gdialog_su) {
                us[su.uid].gradercid = 0;
            }
        } else if (this.name === "save-grader"
                   && f.elements.gradertype.value === "previous") {
            for (let su of gdialog_su) {
                us[su.uid].gradercid = "previous";
            }
        } else if (this.name === "save-grader") {
            let gr = [], gri = [], grn = 0;
            $(gs).find(".pa-grader").each(function () {
                if (this.checked && !this.indeterminate) {
                    gr.push(+this.name.substring(6));
                    gri.push(1);
                    ++grn;
                }
            });
            if (grn) {
                for (let su of gdialog_su) {
                    let trigger = Math.floor(Math.random() * grn), gi = 0;
                    while (trigger >= gri[gi]) {
                        trigger -= Math.max(gri[gi], 0);
                        ++gi;
                    }
                    us[su.uid].gradercid = gr[gi];
                    grn -= Math.min(gri[gi], 1);
                    --gri[gi];
                    if (grn <= 0) {
                        grn = 0;
                        for (gi in gri) {
                            ++gri[gi];
                            grn += Math.max(gri[gi], 0);
                        }
                    }
                }
            }
        } else if (this.name === "save-repo") {
            const clearrepo = f.elements.clearrepo.checked,
                adoptoldrepo = f.elements.adoptoldrepo.checked;
            for (let su of gdialog_su) {
                clearrepo && (us[su.uid].clearrepo = true);
                adoptoldrepo && (us[su.uid].adoptoldrepo = true);
            }
        } else if (this.name === "save-dropped") {
            const l = f.querySelectorAll(".pa-dropped:checked");
            if (l.length !== 1) {
                return;
            }
            for (let su of gdialog_su) {
                us[su.uid].dropped = l[0].value === "true";
            }
        }
        this.disabled = true;
        const usc = [];
        for (let su of gdialog_su) {
            usc.push(us[su.uid]);
        }
        const progress = document.createElement("progress");
        progress.max = usc.length;
        progress.className = "float-right";
        this.after(progress);
        let usci = 0;
        function more() {
            const byuid = usc.slice(usci, usci + 16);
            usci += byuid.length;
            api_conditioner(hoturl("=api/gradesettings", {pset: ptconf.key}),
                {us: JSON.stringify(byuid)},
                {timeout: 20000})
            .then(function (rv) {
                gdialog_store_start(rv);
                progress.value = usci;
                if (rv.ok && rv.us) {
                    for (let rvu of rv.us) {
                        grade_update(ptconf, uid2tr, rvu, 3);
                    }
                }
                if (rv.ok && usci < usc.length) {
                    more();
                } else {
                    $gdialog.close();
                }
            })
        }
        more();
    }
    function gdialog_settings_gvis_click() {
        const gs = $gdialog.find(".pa-gdialog-settings")[0];
        $(gs).find(".pa-gvis").prop("checked", false).prop("indeterminate", false);
        this.checked = true;
    }
    function gdialog_settings_dropped_click() {
        const gs = $gdialog.find(".pa-gdialog-settings")[0];
        $(gs).find(".pa-dropped").prop("checked", false).prop("indeterminate", false);
        $(gs).find("button[name=save-dropped]").prop("disabled", false);
        this.checked = true;
    }
    function gdialog_settings_grader_click() {
        const gs = $gdialog.find(".pa-gdialog-settings")[0], self = this;
        $(gs).find(".pa-grader:indeterminate").each(function () {
            if (self !== this && this.indeterminate) {
                this.checked = false;
            }
            this.indeterminate = false;
        });
        if (this.checked) {
            gs.closest("form").elements.gradertype.value = "set";
        } else {
            setTimeout(function () {
                if ($(gs).find(".pa-grader:checked").length === 0)
                    gs.closest("form").elements.gradertype.value = "clear";
            }, 0);
        }
    }
    function gdialog_mode_settings() {
        const gs = $gdialog.find(".pa-gdialog-settings")[0], f = gs.closest("form");
        if (!gs.firstChild) {
            const yc = new HtmlCollector;
            yc.push('<fieldset><legend>Grade visibility</legend><div class="multicol-3">', '</fieldset>');
            yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="gvis" value="true" class="uic pa-gvis"></span>Visible</label>');
            yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="gvis" value="false" class="uic pa-gvis"></span>Hidden</label>');
            yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="gvis" value="null" class="uic pa-gvis"></span>Default (' + (ptconf.scores_visible ? 'visible' : 'hidden') + ')</label>');
            yc.push('</div><div class="popup-actions"><button type="button" class="btn btn-primary" name="save-gvis">Save</button></div>');
            yc.pop();

            yc.push('<fieldset class="mt-3"><legend>Grader</legend>', '</fieldset>');
            yc.push('<label class="checki"><span class="checkc"><input type="radio" name="gradertype" value="clear" class="uic"></span>Clear</label>');
            //yc.push('<label class="checki"><span class="checkc"><input type="radio" name="gradertype" value="previous" class="uic"></span>Adopt from previous problem set</label>');
            yc.push('<label class="checki"><span class="checkc"><input type="radio" name="gradertype" value="set" class="uic"></span>Set grader</label>');
            yc.push('<div class="checki mt-1 multicol-3">', '</div>');
            for (let i = 0; i !== siteinfo.pc.__order__.length; ++i) {
                const cid = siteinfo.pc.__order__[i], pc = siteinfo.pc[cid];
                yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="grader'.concat(cid, '" class="uic js-range-click pa-grader" data-range-type="grader"></span>', escape_entities(pc.name), '<span class="ct small dim"></span></label>'));
            }
            yc.pop();
            yc.push('<div class="popup-actions"><button type="button" class="btn btn-primary" name="save-grader">Save</button></div>');
            yc.pop();

            if (!ptconf.gitless) {
                yc.push('<fieldset class="mt-3"><legend>Repository</legend>', '</fieldset>');
                yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="clearrepo" value="1" class="uic"></span>Clear repository</label>');
                if (ptconf.has_older_repo) {
                    yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="adoptoldrepo" value="1" class="uic"></span>Adopt previous repository</label>');
                }
                yc.push('<div class="popup-actions"><button type="button" class="btn btn-primary" name="save-repo">Save</button></div>');
                yc.pop();
            }

            if (siteinfo.user.is_admin) {
                yc.push('<fieldset class="mt-3"><legend>Enrollment</legend><div class="multicol-2">', '</fieldset>');
                yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="dropped" value="false" class="uic pa-dropped"></span>Enrolled</label>');
                yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="dropped" value="true" class="uic pa-dropped"></span>Dropped</label>');
                yc.push('</div><div class="popup-actions"><button type="button" class="btn btn-primary" name="save-dropped">Save</button></div>');
                yc.pop();
            }

            gs.innerHTML = yc.render();
            $(gs).on("click", "button", gdialog_settings_submit);
            $(gs).on("click", ".pa-grader", gdialog_settings_grader_click);
            $(gs).on("click", ".pa-gvis", gdialog_settings_gvis_click);
            $(gs).on("click", ".pa-dropped", gdialog_settings_dropped_click);
        }

        const gvis = {}, ggr = {}, ggra = {}, gdropped = {};
        for (let su of gdialog_su) {
            if (!su.scores_visible_pinned) {
                gvis["null"] = (gvis["null"] || 0) + 1;
            } else if (su.scores_visible) {
                gvis["true"] = (gvis["true"] || 0) + 1;
            } else {
                gvis["false"] = (gvis["false"] || 0) + 1;
            }
            const grcid = su.gradercid || 0;
            ggr[grcid] = (ggr[grcid] || 0) + 1;
            const dropped = su.dropped ? "true" : "false";
            gdropped[dropped] = (gdropped[dropped] || 0) + 1;
        }
        for (let su of ptconf.smap) {
            if (su) {
                const grcid = su.gradercid || 0;
                ggra[grcid] = (ggra[grcid] || 0) + 1;
            }
        }
        $(gs).find("input").prop("checked", false).prop("indeterminate", false);
        for (let x in gvis) {
            $(gs).find(".pa-gvis[value=" + x + "]").prop("checked", !!gvis[x]).prop("indeterminate", gvis[x] && gvis[x] !== gdialog_su.length);
        }
        $(gs).find("input[name=gradertype][value=" + ((ggr[0] || 0) === gdialog_su.length ? "clear" : "set") + "]").prop("checked", true);
        for (let x in ggr) {
            if (x != 0) {
                const e = f.elements["grader" + x];
                e.checked = true;
                e.indeterminate = ggr[x] !== gdialog_su.length;
            }
        }
        $(gs).find(".pa-grader").each(function () {
            const grcid = +this.name.substring(6),
                ngr = ggr[grcid] || 0, ngra = ggra[grcid] || 0,
                elt = this.closest(".checki").lastChild;
            if (ngra && ngr === ngra) {
                elt.textContent = " (".concat(ngra, ")");
            } else if (ngra) {
                elt.textContent = " (".concat(ngr, "/", ngra, ")");
            } else {
                elt.textContent = "";
            }
        });
        for (let x in gdropped) {
            $(gs).find(".pa-dropped[value=" + x + "]").prop("checked", !!gdropped[x]).prop("indeterminate", gdropped[x] && gdropped[x] !== gdialog_su.length);
        }
        if (gdropped["true"] && gdropped["false"]) {
            $(gs).find("button[name=save-dropped]").prop("disabled", true);
        }

        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        gs.classList.remove("hidden");
        $gdialog.find("button[name=bsubmit]").addClass("hidden");
    }
    function gdialog_mode() {
        $gdialog.find(".nav-pills button").removeClass("btn-primary");
        if (this.name === "mode-settings") {
            gdialog_mode_settings();
        } else {
            gdialog_mode_values();
        }
        this.classList.add("btn-primary");
    }

    function show() {
        const hc = popup_skeleton();
        hc.push('<h2 class="pa-home-pset">' + escape_entities(ptconf.title) + ' Grades</h2>');
        hc.push('<h3 class="gdialog-userids hidden"></h3>');
        hc.push('<div class="pa-messages"></div>');

        hc.push('<div class="nav-pills">', '</div>');
        hc.push('<button type="button" class="btn btn-primary no-focus is-mode" name="mode-values">Values</button>');
        hc.push('<button type="button" class="btn no-focus is-mode" name="mode-settings">Settings</button>');
        hc.pop();

        hc.push('<div class="pa-gdialog-tab pa-gradelist is-modal"></div>');
        hc.push('<div class="pa-gdialog-tab pa-gdialog-settings hidden"></div>');

        hc.push_actions();
        hc.push('<button type="button" name="bsubmit" class="btn-primary">Save</button>');
        hc.push('<button type="button" name="cancel">Cancel</button>');
        hc.push('<span class="btnbox btnl"><button type="button" name="prev" class="btnl">&lt;</button><button type="button" name="next" class="btnl">&gt;</button></span>');
        hc.push('<button type="button" name="clearauto" class="btnl">Clear autogrades</button>');
        $gdialog = hc.show(false);
        $gdialog.children(".modal-dialog").addClass("modal-dialog-wide");
        const form = $gdialog.find("form")[0];
        addClass(form, "pa-psetinfo");
        form.pa__gradesheet = ptconf.gradesheet;
        gdialog_mode_values();
        $gdialog.on("click", ".pa-gdialog-section", gdialog_section_click);
        $gdialog.on("change blur", ".pa-gradevalue", gdialog_gradelist_change);
        $gdialog.on("input change", ".pa-gradevalue", gdialog_gradelist_input);
        $gdialog.on("keydown", gdialog_key);
        $gdialog.on("keydown", "input, textarea, select", gdialog_key);
        //$gdialog.find(".pa-gradelist").on("input", "input, textarea, select", gdialog_gradelist_input);
        $gdialog.find("button[name=bsubmit]").on("click", function () { gdialog_store(null); });
        $gdialog.find("button[name=clearauto]").on("click", gdialog_clearauto);
        $gdialog.find("button[name=prev], button[name=next]").on("click", gdialog_traverse);
        $gdialog.find("button.is-mode").on("click", gdialog_mode);
        $gdialog.find("button[name=prev], button[name=next]").prop("disabled", true).addClass("hidden");
        gdialog_fill(checked_spos);
        if (hlgrade && form.elements[hlgrade]) {
            addClass(form.elements[hlgrade], "want-focus");
            addClass(form.elements[hlgrade], "want-select");
        }
        hc.show();
    }

    show();
}

handle_ui.on("js-gdialog", function () {
    const f = this.closest("form"), ptconf = f.pa__ptconf,
        sus = ptconf.users_in(f, ptconf.CHECKED_USERS);
    if (sus.length === 0) {
        alert("Select one or more students first");
    } else {
        const spos = [];
        for (const su of sus) {
            spos.push(su._spos);
        }
        ptable_gdialog(ptconf, spos, f.querySelector("table.gtable"));
    }
});
