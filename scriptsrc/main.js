// main.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { sprintf, strftime, text_eq } from "./utils.js";
import {
    hasClass, addClass, removeClass, toggleClass, classList,
    handle_ui
    } from "./ui.js";
import { event_key } from "./ui-key.js";
import "./ui-autogrow.js";
import "./ui-range.js";
import "./ui-sessionlist.js";
import { hoturl } from "./hoturl.js";
import { api_conditioner } from "./xhr.js";
import { tooltip } from "./tooltip.js";
import "./pset.js";
import { Filediff } from "./diff.js";
import "./diff-markdown.js";
import { Note } from "./note.js";
import "./note-edit.js";
import { render_text, render_feedback_list } from "./render.js";
import "./render-terminal.js";
import { run } from "./run.js";
import { run_settings_load } from "./run-settings.js";
import { grgraph } from "./grgraph-ui.js";
import "./grgraph-highlight.js";
import { GradeEntry, GradeSheet } from "./gradeentry.js";
import "./gc-checkbox.js";
import "./gc-letter.js";
import "./gc-multicheckbox.js";
import "./gc-select.js";
import "./gc-markdown.js";
import "./gc-timermark.js";
import "./ptable-grades.js";
import "./ptable-diff.js";
import { pa_pset_table } from "./ptable.js";
import LinkifyIt from "linkify-it";
window.markdownit.linkify = LinkifyIt();
window.markdownit.linkify.set({fuzzyEmail: false, "---": true});

function $$(id) {
    return document.getElementById(id);
}

// geometry
$.fn.extend({
    geometry: function (outer) {
        var g, d;
        if (this[0] == window) {
            g = {left: this.scrollLeft(), top: this.scrollTop()};
        } else if (this.length == 1 && this[0].getBoundingClientRect) {
            g = $.extend({}, this[0].getBoundingClientRect());
            if ((d = window.pageXOffset))
                g.left += d, g.right += d;
            if ((d = window.pageYOffset))
                g.top += d, g.bottom += d;
            if (!("width" in g)) {
                g.width = g.right - g.left;
                g.height = g.bottom - g.top;
            }
            return g;
        } else {
            g = this.offset();
        }
        if (g) {
            g.width = outer ? this.outerWidth() : this.width();
            g.height = outer ? this.outerHeight() : this.height();
            g.right = g.left + g.width;
            g.bottom = g.top + g.height;
        }
        return g;
    },
    scrollIntoView: function (opts) {
        opts = opts || {};
        for (let i = 0; i !== this.length; ++i) {
            let p = $(this[i]).geometry(), x = this[i].parentNode;
            while (x && x.tagName && $(x).css("overflow-y") === "visible") {
                x = x.parentNode;
            }
            x = x && x.tagName ? x : window;
            let w = $(x).geometry();
            if (p.top < w.top + (opts.marginTop || 0) || opts.atTop) {
                let pos = Math.max(p.top - (opts.marginTop || 0), 0);
                if (x === window) {
                    x.scrollTo(x.scrollX, pos);
                } else {
                    x.scrollTop = pos;
                }
            } else if (p.bottom > w.bottom - (opts.marginBottom || 0)) {
                let pos = Math.max(p.bottom + (opts.marginBottom || 0) - w.height, 0);
                if (x === window) {
                    x.scrollTo(x.scrollX, pos);
                } else {
                    x.scrollTop = pos;
                }
            }
        }
        return this;
    },
    serializeWith: function(data) {
        var s = this.serialize();
        if (s != null && data) {
            let sep = s.length && s[s.length - 1] != "&" ? "&" : "";
            for (let i in data) {
                if (data[i] != null) {
                    s += sep + encodeURIComponent(i) + "=" + encodeURIComponent(data[i]);
                    sep = "&";
                }
            }
        }
        return s;
    }
});


// initialization
var set_local_time = (function () {
var servhr24, showdifference = false;
function set_local_time(elt, servtime) {
    var d, s;
    if (elt && typeof elt == "string")
        elt = $$(elt);
    if (elt && showdifference) {
        d = new Date(servtime * 1000);
        if (servhr24)
            s = strftime("%A %#e %b %Y %#R your time", d);
        else
            s = strftime("%A %#e %b %Y %#r your time", d);
        if (elt.tagName == "SPAN") {
            elt.textContent = " (" + s + ")";
            elt.style.display = "inline";
        } else {
            elt.textContent = s;
            elt.style.display = "block";
        }
    }
}
set_local_time.initialize = function (servzone, hr24) {
    servhr24 = hr24;
    // print local time if server time is in a different time zone
    showdifference = Math.abs((new Date).getTimezoneOffset() - servzone) >= 60;
};
return set_local_time;
})();


var hotcrp_onload = [];
function hotcrp_load(arg) {
    if (!arg)
        for (var x = 0; x < hotcrp_onload.length; ++x)
            hotcrp_onload[x]();
    else if (typeof arg === "string")
        hotcrp_onload.push(hotcrp_load[arg]);
    else
        hotcrp_onload.push(arg);
}
hotcrp_load.time = function (servzone, hr24) {
    set_local_time.initialize(servzone, hr24);
};


var foldmap = {}, foldsession_unique = 1;
function fold(which, dofold, foldtype) {
    var i, elt, selt, opentxt, closetxt, foldnum, foldnumid;
    if (which instanceof Array) {
        for (i = 0; i < which.length; i++)
            fold(which[i], dofold, foldtype);

    } else if (typeof which == "string") {
        foldnum = foldtype;
        if (foldmap[which] != null && foldmap[which][foldtype] != null)
            foldnum = foldmap[which][foldtype];
        foldnumid = foldnum ? foldnum : "";

        elt = $$("fold" + which) || $$(which);
        fold(elt, dofold, foldnum);

        // check for session
        if ((selt = $$('foldsession.' + which + foldnumid)))
            selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0) + '&u=' + foldsession_unique++);
        else if ((selt = $$('foldsession.' + which)))
            selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0) + '&sub=' + (foldtype || foldnumid) + '&u=' + foldsession_unique++);

        // check for focus
        if (!dofold && (selt = $$("fold" + which + foldnumid + "_d"))) {
            if (selt.setSelectionRange && selt.hotcrp_ever_focused == null) {
                selt.setSelectionRange(selt.value.length, selt.value.length);
                selt.hotcrp_ever_focused = true;
            }
            selt.focus();
        }

    } else if (which) {
        foldnumid = foldtype ? foldtype : "";
        opentxt = "fold" + foldnumid + "o";
        closetxt = "fold" + foldnumid + "c";
        if (dofold == null && which.className.indexOf(opentxt) >= 0)
            dofold = true;
        if (dofold)
            which.className = which.className.replace(opentxt, closetxt);
        else
            which.className = which.className.replace(closetxt, opentxt);
        // IE won't actually do the fold unless we yell at it
        if (document.recalc)
            try {
                which.innerHTML = which.innerHTML + "";
            } catch (err) {
            }
    }

    return false;
}

function foldup(event, opts) {
    var e = this, dofold = false, m, x;
    if (typeof opts === "number") {
        opts = {n: opts};
    } else if (!opts) {
        opts = {};
    }
    if (this.tagName === "DIV"
        && event
        && event.target.closest("a")
        && !opts.required) {
        return;
    }
    if (!("n" in opts)
        && e.hasAttribute("data-fold-target")
        && (m = e.getAttribute("data-fold-target").match(/^(\D[^#]*$|.*(?=#)|)#?(\d*)([cou]?)$/))) {
        if (m[1] !== "") {
            e = document.getElementById(m[1]);
        }
        opts.n = parseInt(m[2]) || 0;
        if (!("f" in opts) && m[3] !== "") {
            if (m[3] === "u" && this.tagName === "INPUT" && this.type === "checkbox") {
                opts.f = this.checked;
            } else {
                opts.f = m[3] === "c";
            }
        }
    }
    var foldname = "fold" + (opts.n || "");
    while (e
           && (!e.id || e.id.substr(0, 4) != "fold")
           && !hasClass(e, "has-fold")
           && (opts.n == null
               || (!hasClass(e, foldname + "c")
                   && !hasClass(e, foldname + "o")))) {
        e = e.parentNode;
    }
    if (!e) {
        return true;
    }
    if (opts.n == null) {
        x = classList(e);
        for (var i = 0; i !== x.length; ++i) {
            if (x[i].substring(0, 4) === "fold"
                && (m = x[i].match(/^fold(\d*)[oc]$/))
                && (opts.n == null || +m[1] < opts.n)) {
                opts.n = +m[1];
                foldname = "fold" + (opts.n || "");
            }
        }
    }
    if (!("f" in opts)
        && (this.tagName === "INPUT" || this.tagName === "SELECT")) {
        var value = null;
        if (this.type === "checkbox") {
            opts.f = !this.checked;
        } else if (this.type === "radio") {
            if (!this.checked)
                return true;
            value = this.value;
        } else if (this.type === "select-one") {
            value = this.selectedIndex < 0 ? "" : this.options[this.selectedIndex].value;
        }
        if (value !== null) {
            var values = (e.getAttribute("data-" + foldname + "-values") || "").split(/\s+/);
            opts.f = values.indexOf(value) < 0;
        }
    }
    dofold = !hasClass(e, foldname + "c");
    if (!("f" in opts) || !opts.f !== dofold) {
        opts.f = dofold;
        fold(e, dofold, opts.n || 0);
        $(e).trigger(opts.f ? "fold" : "unfold", opts);
    }
    if (this.hasAttribute("aria-expanded")) {
        this.setAttribute("aria-expanded", dofold ? "false" : "true");
    }
    if (event
        && typeof event === "object"
        && event.type === "click"
        && !hasClass(event.target, "uic")) {
        event.stopPropagation();
        event.preventDefault(); // needed for expanders despite handle_ui!
    }
}

handle_ui.on("js-foldup", foldup);

handle_ui.on("js-hide-error", function () {
    addClass(this, "hidden");
});

handle_ui.on("js-pset-upload-grades", function () {
    $("#upload").removeClass("hidden");
});

handle_ui.on("pa-signin-radio", function () {
    let v;
    if (this.value === "login") {
        fold("logingroup", false);
        fold("logingroup", false, 2);
        v = "Sign in";
    } else if (this.value === "forgot") {
        fold("logingroup", true);
        fold("logingroup", false, 2);
        v = "Reset password";
    } else if (this.value === "new") {
        fold("logingroup", true);
        fold("logingroup", true, 2);
        v = "Create account";
    }
    document.getElementById("signin").value = v;
});


handle_ui.on("change.pa-gradevalue", function () {
    var f = this.form, ge, self = this;
    if (f && hasClass(f, "pa-pv")) {
        $(f).submit();
    } else if (self.type === "hidden" && (ge = GradeEntry.closest(self))) {
        queueMicrotask(function () { ge.gc.update_edit.call(ge, self, +self.value, {}) });
    }
});

handle_ui.on("click.pa-gradevalue", function () {
    if (this.type === "checkbox" || this.type === "radio") {
        queueMicrotask(() => { $(this).trigger("change"); });
    }
});


function save_grade(self) {
    addClass(self, "pa-saving");
    var p = $(self).data("paOutstandingPromise");
    if (p) {
        p.then(save_grade, reject_save_grade);
        return;
    }

    var $f = $(self);
    $f.find(".pa-gradediffers, .pa-save-message").remove();
    $f.append('<span class="pa-save-message compact"><span class="spinner"></span></span>');

    var gi = GradeSheet.closest(self), g = {}, og = {};
    $f.find("input.pa-gradevalue, textarea.pa-gradevalue, select.pa-gradevalue").each(function () {
        let ge = gi.xentry(this.name), gv;
        if (ge && (gv = ge.value_in(gi)) != null) {
            og[this.name] = gv;
        }
        if ((this.type !== "checkbox" && this.type !== "radio")
            || this.checked) {
            g[this.name] = this.value;
        } else if (this.type === "checkbox") {
            g[this.name] = 0;
        }
    });

    $f.data("paOutstandingPromise", new Promise(function (resolve, reject) {
        api_conditioner(hoturl("=api/grade", {psetinfo: $f[0]}),
            {grades: g, oldgrades: og})
        .then(function (data) {
            var e, $sm = $f.find(".pa-save-message");
            $f.removeData("paOutstandingPromise");
            reject_save_grade(self);
            if (data.ok) {
                if (data.answer_timeout
                    && (e = self.closest(".pa-grade"))
                    && hasClass(e, "pa-ans")) {
                    $sm.remove();
                    $sm = $('<div class="pa-save-message"><strong class="err">Your exam period has closed.</strong> Your change was saved anyway, but the version used for grading will be selected from within the exam window.</div>').appendTo($f);
                } else {
                    $sm.addClass("compact fadeout").html('<span class="savesuccess"></span>');
                }
                GradeSheet.store(self.closest(".pa-psetinfo"), data);
                resolve(self);
            } else {
                $sm.removeClass("compact").html('<strong class="err">' + data.error + '</strong>');
                reject(self);
            }
        });
    }));
}

function reject_save_grade(self) {
    removeClass(self, "pa-saving");
}

handle_ui.on("pa-pv", function (event) {
    event.preventDefault();
    save_grade(this);
});


function pa_resolve_grade() {
    removeClass(this, "need-pa-grade");
    const ge = GradeEntry.closest(this),
        gi = GradeSheet.closest(this);
    if (ge && gi) {
        const e = ge.render(gi);
        this.parentElement.replaceChild(this, e);
        gi.update_at(e);
        if (ge.landmark_range && this.closest(".pa-gradebox")) {
            // XXX maybe calling compute_landmark_range_grade too often
            ge.landmark_grade(this.firstChild);
        }
        if (this.hasAttribute("data-pa-landmark-buttons")) {
            const lb = JSON.parse(this.getAttribute("data-pa-landmark-buttons"));
            for (let i = 0; i !== lb.length; ++i) {
                if (typeof lb[i] === "string") {
                    $(e).find(".pa-pv").append(lb[i]);
                } else if (lb[i].className) {
                    $(e).find(".pa-pv").append('<button type="button" class="btn uic uikd pa-grade-button" data-pa-grade-button="' + lb[i].className + '">' + lb[i].title + '</button>');
                }
            }
        }
    }
}

handle_ui.on("pa-grade-button", function (event) {
    if (event.type === "keydown") {
        var k = event_key(event), gr;
        if (event.ctrlKey
            && (k === "n" || k === "N" || k === "p" || k === "P" || k === "A")
            && (gr = this.closest(".pa-grade"))) {
            var gradekey = gr.getAttribute("data-pa-grade"),
                buttons = $(".pa-grade[data-pa-grade=" + gradekey + "] .pa-grade-button[data-pa-grade-button=" + this.getAttribute("data-pa-grade-button") + "]").filter(":visible"),
                i = buttons.toArray().indexOf(this);
            if (i >= 0) {
                if (k === "n" || k === "N" || k === "p" || k === "P") {
                    var delta = k === "n" || k === "N" ? 1 : buttons.length - 1,
                        button = buttons[(i + delta) % buttons.length],
                        dg = button.closest(".pa-dg");
                    dg && $(dg).scrollIntoView({marginTop: 24, atTop: true});
                    button.focus();
                    $(button).scrollIntoView();
                } else {
                    buttons.click();
                }
                event.preventDefault();
            }
        }
    } else if (event.type === "click") {
        handle_ui.trigger.call(this, this.getAttribute("data-pa-grade-button"), event);
    }
});

function button_position_delta(button) {
    return button.getBoundingClientRect().top - window.scrollY;
}

function button_reposition_delta(button, delta) {
    queueMicrotask(() => {
        const y = button.getBoundingClientRect().top - delta;
        window.scrollBy(0, y - window.scrollY);
    })
}

handle_ui.on("pa-grade-toggle-description", function (event) {
    const me = this.closest(".pa-gsection"),
        $es = event.metaKey ? $(".pa-gsection") : $(me),
        show = hasClass(me, "pa-hide-description"),
        tep = button_position_delta(this);
    tooltip.erase();
    $es.each(function () {
        toggleClass(this, "pa-hide-description", !show);
        $(this).find(".pa-grade-toggle-description > span").toggleClass("filter-gray", !show);
    });
    button_reposition_delta(this, tep);
});

handle_ui.on("pa-grade-toggle-markdown", function (event) {
    const me = this.closest(".pa-gsection"),
        $es = event.metaKey ? $(".pa-gsection") : $(me),
        show = hasClass(this.firstChild, "filter-gray"),
        tep = button_position_delta(this);
    tooltip.erase();
    $es.each(function () {
        const gi = GradeSheet.closest(this);
        $(this).find(".pa-grade").each(function () {
            const ge = gi.entries[this.getAttribute("data-pa-grade")];
            if (ge.type === "markdown"
                && hasClass(this, "pa-markdown") !== show) {
                toggleClass(this, "pa-markdown", show);
                if (!hasClass(this, "e")) {
                    gi.remount_at(this, 0);
                    gi.update_at(this);
                }
            }
        });
        $(this).find(".pa-grade-toggle-markdown > span").toggleClass("filter-gray", !show);
    });
    button_reposition_delta(this, tep);
});

handle_ui.on("pa-grade-toggle-answer", function (event) {
    const me = this.closest(".pa-gsection"),
        $es = event.metaKey ? $(".pa-gsection") : $(me),
        mode = hasClass(this.firstChild, "filter-gray") ? 2 : 0,
        tep = button_position_delta(this);
    tooltip.erase();
    $es.each(function () {
        const gi = GradeSheet.closest(this);
        $(this).find(".pa-grade").each(function () {
            const ge = gi.entries[this.getAttribute("data-pa-grade")];
            if (ge.answer) {
                gi.remount_at(this, mode);
                gi.update_at(this, {reset: true});
            }
        });
        $(this).find(".pa-grade-toggle-answer > span").toggleClass("filter-gray", mode === 0);
        $(this).find(".pa-grade-toggle-markdown").toggleClass("hidden", mode !== 0);
    });
    button_reposition_delta(this, tep);
});

function gradelist_elementmap(glelt) {
    const geltmap = {};
    let ch = glelt.firstChild;
    while (ch) {
        if (hasClass(ch, "pa-grade")) {
            geltmap[ch.getAttribute("data-pa-grade")] = ch;
        }
        if (hasClass(ch, "pa-dg")) {
            ch = ch.firstChild;
        } else {
            while (!ch.nextSibling && hasClass(ch.parentElement, "pa-dg")) {
                ch = ch.parentElement;
            }
            ch = ch.nextSibling;
        }
    }
    return geltmap;
}

function gradelist_make_sections(glelt, gi) {
    const section_class = "pa-dg pa-gsection".concat(
        gi.scores_editable ? " pa-hide-description" : "",
        hasClass(glelt, "is-main") ? " is-main" : ""),
        new_sections = [];
    let ch = glelt.firstChild, section = null;
    for (const k of gi.value_order) {
        const ge = gi.entries[k];
        if ((ge.concealed && !gi.scores_editable)
            || (section !== null && ge.type !== "section")) {
            continue;
        }
        while (ch && !hasClass(ch, "pa-dg") && !hasClass(ch, "pa-grade")) {
            ch = ch.nextSibling;
        }
        if (!ch
            || !hasClass(ch, "pa-gsection")
            || ch.getAttribute("data-pa-grade") !== k) {
            const div = document.createElement("div");
            div.className = section_class;
            div.setAttribute("data-pa-grade", k);
            glelt.insertBefore(div, ch);
            ch = div;
            new_sections.push(ch);
        }
        if (gi.section_wants_sidebar(ge)
            && !hasClass(ch, "pa-with-sidebar")) {
            addClass(ch, "pa-with-sidebar");
            const sb = document.createElement("div");
            sb.className = "pa-sidebar";
            const sdiv = document.createElement("div");
            sdiv.className = "pa-dg is-mainsb";
            ch.append(sb, sdiv);
        }
        section = ch;
        ch = ch.nextSibling;
    }
    return new_sections;
}

function gradelist_section_button(klass, label) {
    const button = document.createElement("button");
    button.className = "btn-t ui small need-tooltip " + klass;
    button.setAttribute("aria-label", label);
    button.append(document.createElement("span"));
    button.firstChild.className = "filter-gray";
    return button;
}

function gradelist_resolve_section(gi, sectione) {
    const ge = gi.entries[sectione.getAttribute("data-pa-grade")];

    // mark user prefix
    let prefix = null;
    if (gi.user && hasClass(document.body, "pa-multiuser")) {
        prefix = document.createElement("span");
        prefix.className = "pa-fileref-context";
        prefix.textContent = gi.user + " / ";
    }

    // mark buttons
    const buttons = [];
    if (gi.scores_editable && gi.section_has(ge, xge => xge.description)) {
        const e = gradelist_section_button("pa-grade-toggle-description", "Toggle description");
        toggleClass(e.firstChild, "filter-gray", hasClass(sectione, "pa-hide-description"));
        e.firstChild.textContent = "📃";
        buttons.push(e);
    }
    if (gi.scores_editable && gi.section_has(ge, xge => xge.answer && xge.type !== "section")) {
        const e = gradelist_section_button("pa-grade-toggle-answer", "Toggle answer editing");
        e.firstChild.textContent = "✍️";
        buttons.push(e);
    }
    if (!gi.answers_editable && gi.section_has(ge, xge => xge.type === "markdown")) {
        const e = gradelist_section_button("pa-grade-toggle-markdown", "Toggle Markdown");
        e.firstChild.className = "icon-markdown filter-gray";
        buttons.push(e);
    }

    // find grade parent
    let parente = sectione;
    while (!parente.firstChild || !hasClass(parente.firstChild, "pa-p")) {
        parente = hasClass(parente, "pa-dg") ? parente.firstChild : parente.nextSibling;
    }

    // add section header if required
    let headere = parente.firstChild;
    if (!hasClass(headere, "pa-p-section")) {
        if (!prefix && !buttons.length) {
            return;
        }
        headere = document.createElement("div");
        headere.className = "pa-p pa-p-section";
        const e = document.createElement("label");
        e.className = "pa-pt";
        e.textContent = "—";
        headere.appendChild(e);
        parente.insertBefore(headere, parente.firstChild);
    }

    // decorate section header
    if (prefix) {
        headere.firstChild.insertBefore(prefix, headere.firstChild.firstChild);
    }
    if (buttons.length) {
        const e = document.createElement("div");
        e.className = "btnbox ml-3";
        e.append(...buttons);
        headere.firstChild.append(e);
    }

    // set stickiness and move description out of sticky region
    addClass(parente, "pa-with-sticky");
    addClass(headere, "pa-sticky");
    const desce = headere.firstChild.nextSibling;
    if (desce && hasClass(desce, "pa-pdesc")) {
        addClass(desce, "pa-ptop");
        hasClass(headere, "pa-p-hidden") && addClass(desce, "pa-p-hidden");
        parente.insertBefore(desce, headere.nextSibling);
    }
}

function gradelist_finish_sidebar(sidebar, sidebarch) {
    while (sidebarch) {
        const next = sidebarch.nextSibling;
        sidebarch.remove();
        sidebarch = next;
    }
    while (!sidebar.firstChild) {
        const next = sidebar.parentElement;
        sidebar.remove();
        sidebar = next;
    }
}

function pa_resolve_gradelist() {
    removeClass(this, "need-pa-gradelist");
    addClass(this, "pa-gradelist");
    const pi = this.closest(".pa-psetinfo"),
        gi = GradeSheet.closest(pi);
    if (!gi) {
        return;
    }

    // obtain list of grade entries
    const geltmap = gradelist_elementmap(this);

    // set up sections
    const sectioned = gi.has(xge => xge.description ||
            xge.answer ||
            xge.type === "section" ||
            (xge.type === "markdown" && gi.answers_editable === false)),
        new_sections = sectioned ? gradelist_make_sections(this, gi) : [];

    // fill out list of grades
    let chp = this, ch = chp.firstChild,
        sidebar = null, sidebarch = null;
    for (let i = 0; i !== gi.value_order.length; ++i) {
        const k = gi.value_order[i], ge = gi.entries[k];
        if (!gi.scores_editable && ge.concealed) {
            continue;
        }

        // exit current section if required
        if (sectioned && chp !== this && ge.type === "section") {
            while (chp !== this) {
                ch = chp.nextSibling;
                chp = chp.parentElement;
            }
            if (sidebar) {
                gradelist_finish_sidebar(sidebar, sidebarch);
                sidebar = sidebarch = null;
            }
        }

        // move into group
        while (ch && !hasClass(ch, "pa-grade")) {
            if (hasClass(ch, "pa-dg")) {
                chp = ch;
                ch = ch.firstChild;
            } else {
                if (hasClass(ch, "pa-sidebar")) {
                    sidebar = ch;
                }
                ch = ch.nextSibling;
            }
        }

        // add grade
        let gelt = geltmap[k];
        if (!gelt || gelt !== ch) {
            if (gelt) {
                ge.unmount_at(gelt);
            } else {
                gelt = ge.render(gi);
            }
            chp.insertBefore(gelt, ch);
            gi.update_at(gelt);
            ch = gelt;
        }
        delete geltmap[k];

        // add grade to sidebar
        if (sidebar && !ge.answer) {
            if (sidebar.className === "pa-sidebar") {
                if (sidebar.firstChild === null) {
                    const e = document.createElement("div");
                    e.className = "pa-gradebox pa-ps";
                    sidebar.appendChild(e);
                }
                sidebar = sidebar.firstChild;
                sidebarch = sidebar.firstChild;
            }
            if (sidebarch && sidebarch.getAttribute("data-pa-grade") === k) {
                sidebarch = sidebarch.nextSibling;
            } else {
                const e = ge.render(gi);
                sidebar.insertBefore(e, sidebarch);
                gi.update_at(e, {sidebar: true});
            }
        }

        ch = ch.nextSibling;
    }

    // finish sidebar
    if (sidebar) {
        gradelist_finish_sidebar(sidebar, sidebarch);
    }

    // remove remaining grades
    for (const k in geltmap) {
        const gelt = geltmap[k],
            ge = gi.entries[gelt.getAttribute("data-pa-grade")];
        ge.unmount_at(gelt);
    }

    // resolve sections
    for (const sectione of new_sections) {
        gradelist_resolve_section(gi, sectione);
    }

    // add links
    if (this.classList.contains("want-psetinfo-links")) {
        const bb = document.createElement("div");
        bb.className = "pa-psetinfo-links btnbox mt-2 mb-2 hidden";
        this.append(bb);
        for (const dir of [true, false]) {
            const sib = psetinfo_sibling(this, dir);
            if (sib) {
                psetinfo_sibling_button(bb, dir);
                psetinfo_sibling_button(sib.querySelector(".pa-psetinfo-links"), !dir);
            }
        }
    }
}

$(function () {
    $(".need-pa-grade").each(pa_resolve_grade);
    $(".need-pa-gradelist").each(pa_resolve_gradelist);
});

function psetinfo_sibling(elt, prev) {
    const sibling = prev ? "previousSibling" : "nextSibling",
        pi = elt.closest(".pa-psetinfo");
    let xpi = pi;
    while (xpi && (xpi === pi || xpi.nodeType !== 1 || !xpi.classList.contains("pa-psetinfo"))) {
        xpi = xpi[sibling];
    }
    return xpi;
}

function psetinfo_sibling_button(bbox, prev) {
    if (bbox && !bbox.querySelector(prev ? ".prev" : ".next")) {
        bbox.classList.remove("hidden");
        const btn = document.createElement("button");
        btn.textContent = prev ? "←" : "→";
        btn.className = "ui pa-psetinfo-link " + (prev ? "prev" : "next");
        btn.type = "button";
        bbox[prev ? "prepend" : "append"](btn);
    }
}

handle_ui.on("pa-psetinfo-link", function () {
    const xpi = psetinfo_sibling(this, this.classList.contains("prev"));
    if (xpi && xpi.id) {
        location.hash = "#" + xpi.id;
    }
});

function pa_render_total(gi, tm) {
    var ne = 0, nv = 0;
    for (var k in gi.entries) {
        if (gi.entries[k].in_total) {
            ++ne;
            if (gi.entries[k].student_visible(gi))
                ++nv;
        }
    }
    const pdiv = document.createElement("div"),
        ptdiv = document.createElement("div"),
        pvdiv = document.createElement("div"),
        gvspan = document.createElement("span"),
        gdspan = document.createElement("span");
    pdiv.className = "pa-total pa-p" + (ne <= 1 ? " hidden" : "") + (nv < ne ? " pa-p-hidden" : "");
    ptdiv.className = "pa-pt";
    ptdiv.append(gi.total_type === "subset" ? "subtotal" : "total");
    pvdiv.className = "pa-pv";
    gvspan.className = "pa-gradevalue pa-gradewidth";
    gdspan.className = "pa-gradedesc";
    gdspan.append("of " + tm[1]);
    pdiv.append(ptdiv, pvdiv);
    pvdiv.append(gvspan, " ", gdspan);
    return pdiv;
}

function pa_loadgrades() {
    if (!hasClass(this, "pa-psetinfo")) {
        throw new Error("bad pa_loadgrades");
    }
    const gi = GradeSheet.closest(this);
    if (!gi || !gi.value_order) {
        return;
    }

    $(this).find(".need-pa-grade").each(function () {
        pa_resolve_grade.call(this, true);
    });

    $(this).find(".pa-gradelist").each(function () {
        pa_resolve_gradelist.call(this, true);
    });

    $(this).find(".pa-grade").each(function () {
        gi.update_at(this, {reset: true});
    });

    // print totals
    const tm = gi.total_type === "hidden" ? [null, null] : [gi.grade_total(), gi.grade_maxtotal],
          total = tm[0] === null ? "" : "" + tm[0];
    if (tm[0] !== null) {
        $(this).find(".pa-gradelist:not(.pa-gradebox)").each(function () {
            const $t = $(this).find(".pa-total");
            $t.length || $(this).prepend(pa_render_total(gi, tm));
        });
    }
    let drawgraph = false;
    $(this).find(".pa-total").each(function () {
        toggleClass(this, "hidden", total === "");
        const $v = $(this).find(".pa-gradevalue");
        if ($v.text() !== total) {
            $v.text(total);
            drawgraph = true;
        }
    });
    if (drawgraph) {
        $(this).find(".pa-grgraph").trigger("redrawgraph");
    }
}

handle_ui.on("pa-notes-grade", function (event) {
    if (event.type === "keydown") {
        var k = event_key(event), gr;
        if (event.ctrlKey
            && (k === "n" || k === "N" || k === "p" || k === "P" || k === "A")
            && (gr = this.closest(".pa-grade"))) {
            var gradekey = gr.getAttribute("data-pa-grade"),
                links = $(".pa-grade[data-pa-grade=" + gradekey + "] .pa-notes-grade").filter(":visible"),
                i = links.toArray().indexOf(this);
            if (i >= 0) {
                if (k === "n" || k === "N" || k === "p" || k === "P") {
                    var delta = k === "n" || k === "N" ? 1 : links.length - 1,
                        link = links[(i + delta) % links.length],
                        dg = link.closest(".pa-dg");
                    dg && $(dg).scrollIntoView({marginTop: 24, atTop: true});
                    link.focus();
                    $(link).scrollIntoView();
                } else {
                    links.click();
                }
                event.preventDefault();
            }
        }
    } else if (event.type === "click") {
        var $gv = $(this).closest(".pa-grade").find(".pa-gradevalue");
        if ($gv.length
            && $gv.val() != $gv.attr("data-pa-notes-grade")) {
            $gv.val($gv.attr("data-pa-notes-grade")).change();
        }
        event.preventDefault();
    }
});


function pa_beforeunload() {
    var ok = true;
    $(".pa-gw textarea").each(function () {
        var tr = this.closest(".pa-dl"), note = Note.at(tr);
        if (!text_eq(this.value, note.text) && !hasClass(tr, "pa-save-failed"))
            ok = false;
    });
    if (!ok) {
        return (event.returnValue = "You have unsaved notes. You will lose them if you leave the page now.");
    }
}

function pa_runmany(chain) {
    const f = document.getElementById("pa-runmany-form"),
        doneinfo = [];
    let timeout;
    if (f && !f.hasAttribute("data-pa-runmany-unload")) {
        $(window).on("beforeunload", function () {
            const progress = document.getElementById("pa-runmany-progress");
            if (progress && progress.max < progress.value) {
                return "Several server requests are outstanding.";
            }
        });
        f.setAttribute("data-pa-runmany-unload", "");
    }
    function take_jobinfo() {
        const button = f.elements.run,
            category = button.getAttribute("data-pa-run-category") || button.value,
            therun = document.getElementById("pa-run-" + category),
            timestamp = therun ? therun.getAttribute("data-pa-timestamp") : null;
        if (category && timestamp) {
            therun.removeAttribute("data-pa-timestamp"); // don't retake
            return {
                u: f.elements.u.value,
                pset: f.elements.pset.value,
                run: category,
                timestamp: +timestamp
            };
        } else {
            return null;
        }
    }
    function check() {
        if (!hasClass(f, "pa-run-active")) {
            const ji = take_jobinfo();
            if (ji && f.elements.jobs) {
                doneinfo.push(ji);
                f.elements.jobs.value = JSON.stringify(doneinfo);
                if (doneinfo.length === 1) {
                    const button = document.createElement("button");
                    button.append("Download");
                    button.className = "ui js-runmany-download";
                    statusui().lastChild.append(button);
                }
            }
            $.ajax(hoturl("=api/runchainhead", {chain: chain}), {
                type: "POST", cache: false, dataType: "json", timeout: 30000,
                success: success
            });
        } else if (!timeout) {
            timeout = setTimeout(check, 2000);
        }
    }
    function statusui() {
        let e = document.getElementById("pa-runmany-statusui");
        if (!e) {
            e = document.createElement("div");
            e.id = "pa-runmany-statusui";
            e.className = "d-flex justify-content-between";
            const progress = document.createElement("div"),
                download = document.createElement("div");
            progress.className = "flex-grow-1";
            e.append(progress, download);
            $("#run-" + f.elements.run.value).after(e);
        }
        return e;
    }
    function progress(njobs) {
        let progress = document.getElementById("pa-runmany-progress");
        if (!progress) {
            progress = document.createElement("progress");
            progress.id = "pa-runmany-progress";
            progress.className = "ml-3 mr-3";
            statusui().firstChild.append("Progress: ", progress);
        }
        progress.max = Math.max(progress.max, njobs + 1);
        progress.value = progress.max - njobs;
        progress.textContent = sprintf("%d%%", progress.value / progress.max);
        if (njobs === 0) {
            progress.after("Done!");
        }
    }
    function success(data) {
        timeout && clearTimeout(timeout);
        timeout = null;
        if (data && data.ok && data.queueid) {
            f.elements.u.value = data.u;
            f.elements.pset.value = data.pset;
            let $x = $("<a href=\"" + siteinfo.site_relative + "~" + encodeURIComponent(data.u) + "/pset/" + data.pset + "\" class=\"q ansib ansifg7\"></a>");
            $x.text(data.u);
            run(f.elements.run, {noclear: true, queueid: data.queueid, timestamp: data.timestamp, headline: $x[0], done_function: check});
        }
        if (data && data.njobs != null) {
            progress(data.njobs);
        }
        if (!data || data.njobs !== 0) {
            timeout = setTimeout(check, 4000);
        }
    }
    check();
}

handle_ui.on("js-runmany-download", function () {
    const f = document.getElementById("pa-runmany-form");
    if (f.elements.jobs && f.elements.jobs.value) {
        const download = document.createElement("input");
        download.type = "hidden";
        download.name = "download";
        download.value = "1";
        f.elements.jobs.disabled = false;
        f.append(download);
        f.submit();
        f.elements.jobs.disabled = true;
        f.removeChild(download);
    }
});

$(function () {
document.body.setAttribute("data-time-skew", Math.floor(new Date().getTime() / 1000) - +document.body.getAttribute("data-now"));
});

$(".pa-download-timed").each(function () {
    var that = this, timer = setInterval(show, 15000);
    function show() {
        const downloadat = +that.getAttribute("data-pa-download-at"),
            commitat = +that.getAttribute("data-pa-commit-at"),
            expiry = +that.getAttribute("data-pa-download-expiry"),
            now = new Date().getTime() / 1000 + +document.body.getAttribute("data-time-skew");
        let t;
        if (now > expiry) {
            t = strftime("%Y/%m/%d %H:%M", downloadat);
        } else {
            t = Math.round((now - downloadat) / 60) + " min";
        }
        if (commitat > downloadat) {
            t += " · " + Math.round((commitat - downloadat) / 60) + " min before commit";
        }
        $(that).find(".pa-download-timer").text(t);
        if (now > expiry) {
            clearInterval(timer);
        }
    }
    show();
});

handle_ui.on("submit.pa-setrepo", function (evt) {
    let f = this;
    this.classList.remove("has-error", "has-warning");
    if (hasClass(this.firstChild, "feedback-list")) {
        this.removeChild(this.firstChild);
    }
    this.querySelector("button[type=submit]").disabled = true;
    $.ajax(hoturl("=api/repo", {
        pset: this.getAttribute("data-pa-pset"),
        u: siteinfo.uservalue
    }), {
        type: "POST", cache: false, data: $(this).serialize(),
        success: function (data) {
            f.querySelector("button[type=submit]").disabled = false;
            if (data.message_list) {
                f.insertBefore(render_feedback_list(data.message_list), f.firstChild);
            } else {
                location.reload();
            }
        }
    })
    evt.preventDefault();
});

handle_ui.on("submit.pa-setbranch", function (evt) {
    let f = this;
    this.classList.remove("has-error", "has-warning");
    if (hasClass(this.firstChild, "feedback-list")) {
        this.removeChild(this.firstChild);
    }
    this.querySelector("button[type=submit]").disabled = true;
    $.ajax(hoturl("=api/branch", {
        pset: this.getAttribute("data-pa-pset"),
        u: siteinfo.uservalue
    }), {
        type: "POST", cache: false, data: $(this).serialize(),
        success: function (data) {
            f.querySelector("button[type=submit]").disabled = false;
            if (data.message_list) {
                f.insertBefore(render_feedback_list(data.message_list), f.firstChild);
            } else {
                location.reload();
            }
        }
    })
    evt.preventDefault();
});

function pa_checklatest(pset) {
    var start = (new Date).getTime(), timeout;

    function checkdata(d) {
        if (d && d.commits) {
            $(".pa-commitcontainer").each(function () {
                var pset = this.getAttribute("data-pa-pset"),
                    latesthash = this.getAttribute("data-pa-checkhash");
                for (var c of d.commits) {
                    if (c.pset == pset
                        && c.hash
                        && c.hash !== latesthash
                        && c.snaphash !== latesthash) {
                        $(this).find(".pa-pv").append("<div class=\"pa-inf-error\"><span class=\"pa-inf-alert\">Newer commits are available.</span> <a href=\"" + hoturl("pset", {u: siteinfo.uservalue, pset: pset, commit: c.hash}) + "\">Load them</a></div>");
                        clearTimeout(timeout);
                        break;
                    }
                }
            });
        }
    }

    function docheck() {
        var now = (new Date).getTime(),
            anyhash = $(".pa-commitcontainer[data-pa-checkhash]").length > 0;
        if (now - start <= 60000)
            timeout = setTimeout(docheck, anyhash ? 10000 : 2000);
        else if (now - start <= 600000)
            timeout = setTimeout(docheck, anyhash ? 20000 : 10000);
        else if (now - start <= 3600000)
            timeout = setTimeout(docheck, (now - start) * 1.25);
        else
            timeout = null;
        $.ajax(hoturl("=api/latestcommit", {u: siteinfo.uservalue, pset: pset}), {
                type: "GET", cache: false, dataType: "json", success: checkdata
            });
    }

    setTimeout(docheck, 2000);
}

function pa_pset_actions() {
    var $f = $(this);
    function update() {
        var st = $f.find("select[name='state']").val();
        $f.find(".pa-if-enabled").toggleClass("hidden", st === "disabled");
        $f.find(".pa-if-visible").toggleClass("hidden", st === "disabled" || st === "invisible");
    }
    update();
    $f.find("select[name='state']").on("change", update);
    $f.find("input, select").on("change", function () {
        $f.find("[type='submit']").addClass("alert");
    });
    $f.removeClass("need-pa-pset-actions");
}

handle_ui.on("pa-anonymized-link", function (event) {
    var link = this.getAttribute("data-pa-link");
    if (event && event.metaKey) {
        window.open(link);
    } else {
        window.location = link;
    }
});

handle_ui.on("js-multiresolveflag", function () {
    const form = this.closest("form"),
        ptconf = form.pa__ptconf, flags = [];
    $(form).find(".papsel:checked").each(function () {
        const s = ptconf.smap[this.closest("tr").getAttribute("data-pa-spos")];
        flags.push({pset: s.pset, uid: s.uid, hash: s.commit, flagid: s.flagid});
    });
    if (flags.length !== 0) {
        $.ajax(hoturl("=api/multiresolveflag"), {
                type: "POST", cache: false, data: {flags: JSON.stringify(flags)}
            });
    } else {
        window.alert("No flags selected.");
    }
});


window.$pa = {
    beforeunload: pa_beforeunload,
    checklatest: pa_checklatest,
    decorate_diff_page: Filediff.decorate_page,
    gradeentry_closest: GradeEntry.closest,
    fold: fold,
    grgraph: grgraph,
    hoturl: hoturl,
    note_near: Note.near,
    on: handle_ui.on,
    onload: hotcrp_load,
    loadgrades: pa_loadgrades,
    load_runsettings: run_settings_load,
    pset_actions: pa_pset_actions,
    pset_table: pa_pset_table,
    render_text_page: render_text.on_page,
    runmany: pa_runmany,
    gradesheet_store: GradeSheet.store,
    text_eq: text_eq
};
