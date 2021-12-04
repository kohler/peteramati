// main.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { wstorage, sprintf, strftime, text_eq } from "./utils.js";
import {
    hasClass, addClass, removeClass, toggleClass, classList, handle_ui
    } from "./ui.js";
import { event_key } from "./ui-key.js";
import "./ui-autogrow.js";
import "./ui-range.js";
import "./ui-sessionlist.js";
import { hoturl, hoturl_post_go } from "./hoturl.js";
import { api_conditioner } from "./xhr.js";
import { escape_entities } from "./encoders.js";
import { tooltip } from "./tooltip.js";
import "./pset.js";
import { Filediff } from "./diff.js";
import "./diff-markdown.js";
import { Note } from "./note.js";
import "./note-edit.js";
import { render_text } from "./render.js";
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


// render_xmsg
function render_xmsg(status, msg) {
    if (typeof msg === "string")
        msg = msg === "" ? [] : [msg];
    if (msg.length === 0)
        return '';
    else if (msg.length === 1)
        msg = msg[0];
    else
        msg = '<p>' + msg.join('</p><p>') + '</p>';
    if (status === 0 || status === 1 || status === 2)
        status = ["info", "warning", "error"][status];
    return '<div class="msg msg-' + status + '">' + msg + '</div>';
}


// differences and focusing
function input_is_checkboxlike(elt) {
    return elt.type === "checkbox" || elt.type === "radio";
}

function input_default_value(elt) {
    if (elt.hasAttribute("data-default-value")) {
        return elt.getAttribute("data-default-value");
    } else if (input_is_checkboxlike(elt)) {
        var c;
        if (elt.hasAttribute("data-default-checked"))
            c = elt.getAttribute("data-default-checked");
        else
            c = elt.defaultChecked;
        // XXX what if elt.value === ""?
        return c ? elt.value : "";
    } else {
        return elt.defaultValue;
    }
}

function input_differs(elt) {
    var expected = input_default_value(elt);
    if (input_is_checkboxlike(elt)) {
        return elt.checked ? expected !== elt.value : expected !== "";
    } else {
        var current = elt.tagName === "SELECT" ? $(elt).val() : elt.value;
        return !text_eq(current, expected);
    }
}

function focus_at(felt) {
    felt.jquery && (felt = felt[0]);
    felt.focus();
    if (!felt.hotcrp_ever_focused) {
        if (felt.select && hasClass(felt, "want-select")) {
            felt.select();
        } else if (felt.setSelectionRange) {
            try {
                felt.setSelectionRange(felt.value.length, felt.value.length);
            } catch (e) { // ignore errors
            }
        }
        felt.hotcrp_ever_focused = true;
    }
}


// HtmlCollector
function HtmlCollector() {
    this.clear();
}
HtmlCollector.prototype.push = function (open, close) {
    if (open && close) {
        this.open.push(this.html + open);
        this.close.push(close);
        this.html = "";
        return this.open.length - 1;
    } else
        this.html += open;
    return this;
};
HtmlCollector.prototype.pop = function (pos) {
    var n = this.open.length;
    if (pos == null)
        pos = Math.max(0, n - 1);
    while (n > pos) {
        --n;
        this.html = this.open[n] + this.html + this.close[n];
        this.open.pop();
        this.close.pop();
    }
    return this;
};
HtmlCollector.prototype.pop_n = function (n) {
    this.pop(Math.max(0, this.open.length - n));
    return this;
};
HtmlCollector.prototype.push_pop = function (text) {
    this.html += text;
    return this.pop();
};
HtmlCollector.prototype.pop_push = function (open, close) {
    this.pop();
    return this.push(open, close);
};
HtmlCollector.prototype.pop_collapse = function (pos) {
    if (pos == null)
        pos = this.open.length ? this.open.length - 1 : 0;
    while (this.open.length > pos) {
        if (this.html !== "")
            this.html = this.open[this.open.length - 1] + this.html +
                this.close[this.open.length - 1];
        this.open.pop();
        this.close.pop();
    }
    return this;
};
HtmlCollector.prototype.render = function () {
    this.pop(0);
    return this.html;
};
HtmlCollector.prototype.clear = function () {
    this.open = [];
    this.close = [];
    this.html = "";
    return this;
};
HtmlCollector.prototype.next_htctl_id = (function () {
var id = 1;
return function () {
    while (document.getElementById("htctl" + id))
        ++id;
    ++id;
    return "htctl" + (id - 1);
};
})();


// popup dialogs
function popup_skeleton(options) {
    var hc = new HtmlCollector, $d = null;
    options = options || {};
    hc.push('<div class="modal" role="dialog"><div class="modal-dialog'
        + (!options.anchor || options.anchor === window ? " modal-dialog-centered" : "")
        + (options.style ? '" style="' + escape_entities(options.style) : '')
        + '" role="document"><div class="modal-content"><form enctype="multipart/form-data" accept-charset="UTF-8"'
        + (options.form_class ? ' class="' + options.form_class + '"' : '')
        + '>', '</form></div></div></div>');
    hc.push_actions = function (actions) {
        hc.push('<div class="popup-actions">', '</div>');
        if (actions)
            hc.push(actions.join("")).pop();
        return hc;
    };
    function show_errors(data) {
        var form = $d.find("form")[0],
            dbody = $d.find(".popup-body"),
            m = render_xmsg(2, data.error);
        $d.find(".msg-error").remove();
        dbody.length ? dbody.prepend(m) : $d.find("h2").after(m);
        for (var f in data.errf || {}) {
            var e = form[f];
            if (e) {
                var x = $(e).closest(".entryi, .f-i");
                (x.length ? x : $(e)).addClass("has-error");
            }
        }
        return $d;
    }
    function close() {
        tooltip.erase();
        $d.find("textarea, input").unautogrow();
        $d.trigger("closedialog");
        $d.remove();
        removeClass(document.body, "modal-open");
    }
    hc.show = function (visible) {
        if (!$d) {
            $d = $(hc.render()).appendTo(document.body);
            $d.find(".need-tooltip").each(tooltip);
            $d.on("click", function (event) {
                event.target === $d[0] && close();
            });
            $d.find("button[name=cancel]").on("click", close);
            if (options.action) {
                if (options.action instanceof HTMLFormElement) {
                    $d.find("form").attr({action: options.action.action, method: options.action.method});
                } else {
                    $d.find("form").attr({action: options.action, method: options.method || "post"});
                }
            }
            for (var k in {minWidth: 1, maxWidth: 1, width: 1}) {
                if (options[k] != null)
                    $d.children().css(k, options[k]);
            }
            $d.show_errors = show_errors;
            $d.close = close;
        }
        if (visible !== false) {
            popup_near($d, options.anchor || window);
            $d.find(".need-autogrow").autogrow();
            $d.find(".need-tooltip").each(tooltip);
        }
        return $d;
    };
    return hc;
}

function popup_near(elt, anchor) {
    tooltip.erase();
    if (elt.jquery)
        elt = elt[0];
    while (!hasClass(elt, "modal-dialog"))
        elt = elt.childNodes[0];
    var bgelt = elt.parentNode;
    addClass(bgelt, "show");
    addClass(document.body, "modal-open");
    if (!hasClass(elt, "modal-dialog-centered")) {
        var anchorPos = $(anchor).geometry(),
            wg = $(window).geometry(),
            po = $(bgelt).offset(),
            y = (anchorPos.top + anchorPos.bottom - elt.offsetHeight) / 2;
        y = Math.max(wg.top + 5, Math.min(wg.bottom - 5 - elt.offsetHeight, y)) - po.top;
        elt.style.top = y + "px";
        var x = (anchorPos.right + anchorPos.left - elt.offsetWidth) / 2;
        x = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) - po.left;
        elt.style.left = x + "px";
    }
    var efocus;
    $(elt).find("input, button, textarea, select").filter(":visible").each(function () {
        if (hasClass(this, "want-focus")) {
            efocus = this;
            return false;
        } else if (!efocus
                   && !hasClass(this, "dangerous")
                   && !hasClass(this, "no-focus")) {
            efocus = this;
        }
    });
    efocus && focus_at(efocus);
}


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
            elt.innerHTML = " (" + s + ")";
            elt.style.display = "inline";
        } else {
            elt.innerHTML = s;
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


handle_ui.on("pa-gradevalue", function () {
    var f = this.closest("form"), ge, self = this;
    if (f && hasClass(f, "pa-pv")) {
        $(f).submit();
    } else if (self.type === "hidden" && (ge = GradeEntry.closest(self))) {
        setTimeout(function () { ge.gc.update_edit.call(ge, self, +self.value, {}) }, 0);
    }
});


(function () {
function save_grade(self) {
    var $f = $(self);
    $f.find(".pa-gradediffers, .pa-save-message").remove();
    $f.append('<span class="pa-save-message compact"><span class="spinner"></span></span>');

    var gi = GradeSheet.closest(self), g = {}, og = {};
    $f.find("input.pa-gradevalue, textarea.pa-gradevalue, select.pa-gradevalue").each(function () {
        let ge = gi.entries[this.name], gv;
        if (ge && (gv = gi.grade_value(ge)) != null) {
            og[this.name] = gv;
        } else if (this.name === "late_hours" && gi.late_hours != null) {
            og[this.name] = gi.late_hours;
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
            $f.removeData("paOutstandingPromise");
            if (data.ok) {
                $f.find(".pa-save-message").addClass("compact fadeout").html('<span class="savesuccess"></span>');
                GradeSheet.store(self.closest(".pa-psetinfo"), data);
                resolve(self);
            } else {
                $f.find(".pa-save-message").removeClass("compact").html('<strong class="err">' + data.error + '</strong>');
                reject(self);
            }
        });
    }));
}
handle_ui.on("pa-pv", function (event) {
    event.preventDefault();
    var p = $(this).data("paOutstandingPromise");
    if (p) {
        p.then(save_grade);
    } else {
        save_grade(this);
    }
});
})();

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

function gradelist_resolve_section(gi, ge, insp) {
    let t = "";
    if (gi.section_has(ge, xge => xge.description)) {
        t += '<button class="btn ui pa-grade-toggle-description need-tooltip'.concat(insp.closest(".pa-gsection").classList.contains("pa-hide-description") ? ' btn-primary' : '', '" aria-label="Toggle description">…</button>');
    }
    if (gi.section_has(ge, xge => xge.type === "markdown")) {
        t += '<button class="btn ui pa-grade-toggle-markdown need-tooltip" aria-label="Toggle Markdown">M</button>';
    }
    if (gi.scores_editable && gi.section_has(ge, xge => xge.answer && xge.type !== "section")) {
        t += '<button class="btn ui pa-grade-toggle-answer need-tootlip" aria-label="Toggle answer editing">E</button>';
    }
    let e = insp.firstChild;
    if (!e.classList.contains("pa-p-section")) {
        if (t === "") {
            return;
        }
        e = document.createElement("div");
        e.className = "pa-p pa-p-section";
        e.appendChild(document.createElement("label"));
        e.firstChild.className = "pa-pt";
        e.firstChild.textContent = "—";
        insp.insertBefore(e, insp.firstChild);
    }
    if (hasClass(document.body, "pa-multiuser") && gi.user) {
        let sp = document.createElement("span");
        sp.className = "pa-fileref-context";
        sp.innerText = gi.user + " / ";
        e.firstChild.insertBefore(sp, e.firstChild.firstChild);
    }

    addClass(insp, "pa-with-sticky");
    addClass(e, "pa-sticky");
    const desc = e.firstChild.nextSibling;
    if (desc && hasClass(desc, "pa-pdesc")) {
        addClass(desc, "pa-pdesc-external");
        insp.insertBefore(desc, e.nextSibling);
    }
    if (t !== "") {
        const btnbox = document.createElement("div");
        btnbox.className = "hdr-actions btnbox";
        btnbox.innerHTML = t;
        e.firstChild.appendChild(btnbox);
    }
}

function find_top_element_position($es) {
    let section, e, bottom;
    $es.each(function () {
        section = this;
        if (this.getBoundingClientRect().bottom > 40)
            return false;
    });
    e = section.firstChild;
    while (e) {
        if (e.nodeType === Node.ELEMENT_NODE) {
            if (hasClass(e, "pa-p")
                && !hasClass(e, "pa-sticky")
                && (bottom = e.getBoundingClientRect().bottom) > 40) {
                return {element: e, bottom: bottom};
            } else if (hasClass(e, "pa-dg")
                       && e.firstChild) {
                e = e.firstChild;
                continue;
            }
        }
        while (!e.nextSibling && !hasClass(e.parentElement, "pa-gsection")) {
            e = e.parentElement;
        }
        e = e.nextSibling;
    }
    return null;
}

function reset_top_element_position(tep) {
    if (tep) {
        const bottom = tep.element.getBoundingClientRect().bottom;
        window.scrollBy(0, Math.ceil(bottom - tep.bottom));
    }
}

handle_ui.on("pa-grade-toggle-description", function (event) {
    const me = this.closest(".pa-gsection"),
        $es = event.metaKey ? $(".pa-gsection") : $(me),
        show = hasClass(me, "pa-hide-description"),
        tep = find_top_element_position($es);
    $es.each(function () {
        toggleClass(this, "pa-hide-description", !show);
        $(this).find(".pa-grade-toggle-description").toggleClass("btn-primary", !show);
    });
    reset_top_element_position(tep);
    tooltip.erase();
});

handle_ui.on("pa-grade-toggle-markdown", function (event) {
    const me = this.closest(".pa-gsection"),
        $es = event.metaKey ? $(".pa-gsection") : $(me),
        show = !hasClass(this, "btn-primary"),
        tep = find_top_element_position($es);
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
        $(this).find(".pa-grade-toggle-markdown").toggleClass("btn-primary", show);
    });
    reset_top_element_position(tep);
    tooltip.erase();
});

handle_ui.on("pa-grade-toggle-answer", function (event) {
    const me = this.closest(".pa-gsection"),
        $es = event.metaKey ? $(".pa-gsection") : $(me),
        mode = hasClass(this, "btn-primary") ? 0 : 2,
        tep = find_top_element_position($es);
    $es.each(function () {
        const gi = GradeSheet.closest(this);
        $(this).find(".pa-grade").each(function () {
            const ge = gi.entries[this.getAttribute("data-pa-grade")];
            if (ge.answer) {
                gi.remount_at(this, mode);
                gi.update_at(this);
            }
        });
        $(this).find(".pa-grade-toggle-answer").toggleClass("btn-primary", mode !== 0);
    });
    reset_top_element_position(tep);
    tooltip.erase();
});

function pa_resolve_gradelist() {
    removeClass(this, "need-pa-gradelist");
    addClass(this, "pa-gradelist");
    const pi = this.closest(".pa-psetinfo"),
        gi = GradeSheet.closest(pi);
    if (!gi) {
        return;
    }
    // obtain list of grades
    const grl = {};
    let ch = this.firstChild;
    while (ch) {
        if (hasClass(ch, "pa-grade")) {
            grl[ch.getAttribute("data-pa-grade")] = ch;
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
    // fill out list of grades
    ch = this.firstChild;
    while (ch && !hasClass(ch, "pa-dg") && !hasClass(ch, "pa-grade")) {
        ch = ch.nextSibling;
    }
    function remove_from(p, e) {
        while (e) {
            const ee = e;
            e = e.nextSibling;
            p.removeChild(ee);
        }
    }
    let insp = this,
        sidebar = null,
        sidebare = null,
        sectioned = gi.has(xge => xge.description || xge.answer ||
                xge.type === "section" ||
                (xge.type === "markdown" && gi.answers_editable === false)),
        section_class = "pa-dg pa-gsection".concat(gi.scores_editable ? " pa-hide-description" : "");
    for (let i = 0; i !== gi.order.length; ++i) {
        const k = gi.order[i], ge = gi.entries[k];
        if (!gi.scores_editable && ge.concealed) {
            continue;
        }

        let new_section = null;
        if (sectioned && (ge.type === "section" || insp === this)) {
            // end current section
            while (insp !== this) {
                remove_from(insp, ch);
                ch = insp.nextSibling;
                insp = insp.parentElement;
            }
            remove_from(sidebar, sidebare);
            sidebar = sidebare = null;
            // add new section if needed
            if (!ch || !hasClass(ch, "pa-gsection")) {
                new_section = document.createElement("div");
                new_section.className = section_class;
                insp.insertBefore(new_section, ch);
                ch = new_section;
                if (gi.section_wants_sidebar(ge)) {
                    new_section.className += " pa-with-sidebar";
                    const sb = document.createElement("div");
                    sb.className = "pa-sidebar";
                    new_section.appendChild(sb);
                    const sdiv = document.createElement("div");
                    sdiv.className = "pa-dg";
                    new_section.appendChild(sdiv);
                }
            }
            // navigate into section
            while (ch) {
                if (hasClass(ch, "pa-dg")) {
                    insp = ch;
                    ch = ch.firstChild;
                } else if (hasClass(ch, "pa-sidebar")) {
                    sidebar = ch;
                    sidebare = ch.firstChild;
                    ch = ch.nextSibling;
                } else {
                    break;
                }
            }
        }

        // add grade
        const gre = grl[k];
        if (gre && gre === ch) {
            ch = ch.nextSibling;
            while (ch && hasClass(ch, "pa-pdesc")) {
                ch = ch.nextSibling;
            }
        } else if (gre) {
            insp.insertBefore(gre, ch);
        } else {
            const e = ge.render(gi);
            insp.insertBefore(e, ch);
            gi.update_at(e);
        }

        // separate section heading from description
        if (new_section) {
            gradelist_resolve_section(gi, ge, insp);
        }

        // add grade to sidebar
        if (!ge.answer && sidebar) {
            if (sidebar.className === "pa-sidebar") {
                if (sidebar.firstChild === null) {
                    const div = document.createElement("div");
                    div.className = "pa-gradebox pa-ps";
                    sidebar.appendChild(div);
                }
                sidebar = sidebar.firstChild;
                sidebare = sidebar.firstChild;
            }
            if (sidebare && sidebare.getAttribute("data-pa-grade") === k) {
                sidebare = sidebare.nextSibling;
            } else {
                const e = ge.render(gi);
                sidebar.insertBefore(e, sidebare);
                gi.update_at(e);
            }
        }
    }
    remove_from(insp, ch);
    sectioned && remove_from(this, insp.nextSibling);
    remove_from(sidebar, sidebare);
}

$(function () {
    $(".need-pa-grade").each(pa_resolve_grade);
    $(".need-pa-gradelist").each(pa_resolve_gradelist);
});

function pa_render_total(gi, tm) {
    var t = '<div class="pa-total pa-p', ne = 0;
    for (var k in gi.entries) {
        if (gi.entries[k].type_tabular)
            ++ne;
    }
    if (ne <= 1) {
        t += ' hidden';
    }
    return t + '"><div class="pa-pt">total</div>' +
        '<div class="pa-pv"><span class="pa-gradevalue pa-gradewidth"></span> ' +
        '<span class="pa-gradedesc">of ' + tm[1] + '</span></div></div>';
}

function pa_loadgrades() {
    if (!hasClass(this, "pa-psetinfo")) {
        throw new Error("bad pa_loadgrades");
    }
    const gi = GradeSheet.closest(this);
    if (!gi || !gi.order) {
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
    const tm = [gi.grade_total(), gi.grade_maxtotal], total = "" + tm[0];
    let drawgraph = false;
    if (tm[0]) {
        $(this).find(".pa-gradelist:not(.pa-gradebox)").each(function () {
            const $t = $(this).find(".pa-total");
            $t.length || $(this).prepend(pa_render_total(gi, tm));
        });
    }
    $(this).find(".pa-total").each(function () {
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
    let $manybutton = $("#pa-runmany"), $f = $manybutton.closest("form");
    if (!$f.prop("pa-runmany-unload")) {
        $(window).on("beforeunload", function () {
            if ($f.prop("outstanding") || $("#pa-runmany-list").text())
                return "Several server requests are outstanding.";
        });
        $f.prop("pa-runmany-unload", "1");
    }
    function check() {
        if (!$f.prop("outstanding")) {
            $.ajax(hoturl("=api/runchainhead", {chain: chain}), {
                type: "POST", cache: false, dataType: "json", timeout: 30000,
                success: function (data) {
                    if (data && data.ok) {
                        $f[0].elements.u.value = data.u;
                        $f[0].elements.pset.value = data.pset;
                        let $x = $("<a href=\"" + siteinfo.site_relative + "~" + encodeURIComponent(data.u) + "/pset/" + data.pset + "\" class=\"q ansib ansifg7\"></a>");
                        $x.text(data.u);
                        $("#pa-runmany-user").text(data.u);
                        run($manybutton[0], {noclear: true, queueid: data.queueid, timestamp: data.timestamp, headline: $x[0]});
                        setTimeout(check, 10);
                    }
                }
            });
        } else {
            setTimeout(check, 4);
        }
    }
    check();
}

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

function pa_render_pset_table(pconf, data) {
    var $j = $(this), table_width = 0, smap = [],
        $overlay = null, name_col, slist_input,
        $gdialog, gdialog_self, gdialog_su,
        flagged = pconf.flagged_commits,
        visible = pconf.scores_visible,
        table_entries, need_ngrades,
        sort = {
            f: flagged ? "at" : "username", last: true, rev: 1
        },
        active_nameflag = -1, displaying_last_first = null,
        anonymous = pconf.anonymous,
        col, colmap, total_colpos, ngrades_colpos;

    function scores_visible_for(s) {
        return s.pinned_scores_visible == null ? visible : s.pinned_scores_visible;
    }

    var col_renderers = {
        checkbox: {
            th: '<th class="gt-checkbox" scope="col"></th>',
            td: function (s, rownum) {
                return rownum == "" ? '<td></td>' :
                    '<td class="gt-checkbox"><input type="checkbox" name="'.concat(
                        render_checkbox_name(s), '" value="1" class="',
                        this.className || "uic js-range-click papsel",
                        '" data-range-type="s61"></td>');
            },
            tw: 1.5
        },
        flagcheckbox: {
            th: '<th class="gt-checkbox" scope="col"></th>',
            td: function (s, rownum) {
                return rownum == "" ? '<td></td>' :
                    '<td class="gt-checkbox"><input type="checkbox" name="s:'.concat(
                        s._spos, '" value="1" class="',
                        this.className || "uic js-range-click papsel",
                        '" data-range-type="s61"></td>');
            },
            tw: 1.5
        },
        rownumber: {
            th: '<th class="gt-rownumber" scope="col"></th>',
            td: function (s, rownum) {
                return rownum == "" ? '<td></td>' : '<td class="gt-rownumber">' + rownum + '.</td>';
            },
            tw: Math.ceil(Math.log10(Math.max(data.length, 1))) * 0.75 + 1
        },
        pset: {
            th: '<th class="gt-pset l plsortable" data-pa-sort="pset" scope="col">Pset</th>',
            td: function (s) {
                return '<td class="gt-pset"><a href="' + escaped_href(s) + '" class="track">' +
                   escape_entities(siteinfo.psets[s.psetid].title) +
                   (s.hash ? "/" + s.hash.substr(0, 7) : "") + '</a></td>';
            },
            tw: 12,
            sort_forward: true,
            compare: function (a, b) {
                if (a.psetid != b.psetid)
                    return siteinfo.psets[a.psetid].pos < siteinfo.psets[b.psetid].pos ? -1 : 1;
                else
                    return a.pos < b.pos ? -1 : 1;
            }
        },
        at: {
            th: '<th class="gt-at l plsortable" data-pa-sort="at" scope="col">Flagged</th>',
            td: function (s) {
                return '<td class="gt-at">' + (s.at ? strftime("%#e %b %#k:%M", s.at) : "") + '</td>';
            },
            tw: 8,
            sort_forward: true,
            compare: function (a, b) {
                if (a.at != b.at)
                    return a.at < b.at ? -1 : 1;
                else
                    return a.pos < b.pos ? -1 : 1;
            }
        },
        username: {
            th: function () {
                var t = '<span class="heading">' + (anonymous || !sort.email ? "Username" : "Email") + '</span>';
                if (pconf.anonymous && pconf.can_override_anonymous)
                    t += ' <a href="" class="uu n js-switch-anon">[anon]</a>';
                else if (pconf.anonymous)
                    t += ' <span class="n">[anon]</span>';
                return '<th class="gt-username l plsortable" data-pa-sort="username" scope="col">' + t + '</th>';
            },
            td: function (s) {
                return '<td class="gt-username">' + render_username_td(s) + '</td>';
            },
            tw: 12,
            pin: true,
            sort_forward: true
        },
        name: {
            th: function () {
                return '<th class="gt-name l plsortable" data-pa-sort="name" scope="col">Name</th>';
            },
            td: function (s) {
                return '<td class="gt-name">' + render_display_name(s, false) + '</td>';
            },
            tw: 14,
            sort_forward: true
        },
        name2: {
            th: function () {
                var t = '<span class="heading">' + (anonymous ? "Username" : "Name") + '</span>';
                if (pconf.anonymous && pconf.can_override_anonymous)
                    t += ' <a href="" class="uu n js-switch-anon">[anon]</a>';
                return '<th class="gt-name2 l plsortable" data-pa-sort="name2" scope="col">' + t + '</th>';
            },
            td: function (s) {
                return '<td class="gt-name2">' + render_display_name(s, true) + '</td>';
            },
            tw: 14,
            sort_forward: true
        },
        extension: {
            th: '<th class="gt-extension l plsortable" data-pa-sort="extension" scope="col">X?</th>',
            td: function (s) {
                return s.x ? '<td class="gt-extension">X</td>' : '<td class="gt-extension"></td>';
            },
            tw: 2,
            sort_forward: true,
            compare: function (a, b) {
                if (a.x != b.x)
                    return a.x ? -1 : 1;
                else
                    return user_compare(a, b);
            }
        },
        year: {
            th: '<th class="gt-year c plsortable" data-pa-sort="year" scole="col">Yr</th>',
            td: function (s) {
                var t = '';
                if (s.year) {
                    if (typeof s.year === "number") {
                        if (s.year >= 1 && s.year <= 20) {
                            t = String.fromCharCode(9311 + s.year);
                        } else {
                            t = s.year;
                        }
                    } else if (s.year.length === 1 && s.year >= "A" && s.year <= "Z") {
                        t = String.fromCharCode(9333 + s.year.charCodeAt(0));
                    } else {
                        t = escape_entities(s.year);
                    }
                }
                return '<td class="gt-year c">'.concat(t, '</td>');
            },
            tw: 2,
            sort_forward: true,
            compare: function (a, b) {
                if (a.year != b.year) {
                    if (!a.year || !b.year)
                        return a.year ? -1 : 1;
                    else if (typeof a.year !== typeof b.year)
                        return typeof a.year === "number" ? -1 : 1;
                    else
                        return a.year < b.year ? -1 : 1;
                } else
                    return user_compare(a, b);
            }
        },
        grader: {
            th: '<th class="gt-grader l plsortable" data-pa-sort="grader" scope="col">Grader</th>',
            td_html: function (s) {
                if (s.gradercid && siteinfo.pc[s.gradercid]) {
                    return grader_name(siteinfo.pc[s.gradercid]);
                } else if (s.gradercid) {
                    return "???";
                } else {
                    return "";
                }
            },
            td: function (s) {
                return '<td class="gt-grader">'.concat(this.td_html(s), '</td>');
            },
            tw: 6,
            sort_forward: true,
            compare: function (a, b) {
                return grader_compare(a, b) || user_compare(a, b);
            }
        },
        latehours: {
            th: '<th class="gt-latehours r plsortable" data-pa-sort="latehours" scope="col" title="Late">⏰</th>',
            td: function (s) {
                return '<td class="gt-latehours r">'.concat(s.late_hours || "", '</td>');
            },
            tw: 2.5,
            compare: function (a, b) {
                if (a.late_hours != b.late_hours) {
                    return (a.late_hours || 0) - (b.late_hours || 0);
                } else {
                    return user_compare(a, b);
                }
            }
        },
        notes: {
            th: '<th class="gt-notes c plsortable" data-pa-sort="gradestatus" scope="col">⎚</th>',
            td_html: function (s) {
                let t = scores_visible_for(s) ? '⎚' : '';
                flagged && s.is_grade && (t += '✱');
                s.has_notes && (t += '♪');
                !flagged && s.has_nongrader_notes && (t += '<sup>*</sup>');
                return t;
            },
            td: function (s) {
                return '<td class="gt-notes c">'.concat(this.td_html(s), '</td>');
            },
            tw: 2
        },
        conversation: {
            th: '<th class="gt-conversation l plsortable" data-pa-sort="conversation" scope="col">Flag</th>',
            td: function (s) {
                return '<td class="gt-conversation l">'.concat(
                    escape_entities(s.conversation || s.conversation_pfx || ""),
                    (s.conversation_pfx ? "…" : ""), '</td>');
            },
            compare: function (a, b) {
                const sa = a.conversation || a.conversation_pfx || "",
                      sb = b.conversation || b.conversation_pfx || "";
                if (sa === "" || sb === "") {
                    return sa === sb ? 0 : (sa === "" ? 1 : -1);
                } else {
                    return sa.localeCompare(sb);
                }
            },
            sort_forward: true,
            tw: 20
        },
        gdialog: {
            th: '<th></th>',
            td: function () {
                return '<td><a href="" class="ui x js-gdialog" tabindex="-1" scope="col">Ⓖ</a></td>';
            },
            tw: 1.5,
            pin: true
        },
        total: {
            th: '<th class="gt-total r plsortable" data-pa-sort="total" scope="col">Tot</th>',
            td: function (s) {
                const t = s.total == null ? "" : s.total;
                return '<td class="gt-total r">'.concat(t, '</td>');
            },
            compare: function (a, b) {
                if (a.total == null || b.total == null) {
                    if (a.total != null) {
                        return 1;
                    } else if (b.total != null) {
                        return -1;
                    }
                } else if (a.total != b.total) {
                    return a.total < b.total ? -1 : 1;
                }
                return -user_compare(a, b);
            },
            tw: 3
        },
        grade: {
            th: function () {
                return '<th class="'.concat(this.className, ' plsortable" data-pa-sort="grade', this.gidx, '" scope="col" title="', escape_entities(this.ge.title_text), '">', this.ge.abbr(), '</th>');
            },
            td_text: function (s) {
                const gr = s.grades[this.gidx];
                return this.ge.tcell(gr);
            },
            td: function (s) {
                const gr = s.grades[this.gidx],
                    hl = s.highlight_grades && s.highlight_grades[this.gkey];
                return '<td class="'.concat(hl ? 'gt-highlight ' : '', this.className, '">',
                    escape_entities(this.ge.tcell(gr)), '</td>');
            },
            tw: function () {
                const w = this.ge.abbr().length * 0.5 + 1.5;
                return Math.max(w, this.ge.tcell_width());
            }
        },
        ngrades: {
            th: '<th class="gt-ngrades r plsortable" data-pa-sort="ngrades" scope="col">#G</th>',
            td: function (s) {
                return '<td class="gt-ngrades r">' + (s.ngrades_nonempty || "") + '</td>';
            },
            tw: 2,
            sort_forward: true,
            compare: function (a, b) {
                if (a.ngrades_nonempty !== b.ngrades_nonempty)
                    return a.ngrades_nonempty < b.ngrades_nonempty ? -1 : 1;
                else
                    return -user_compare(a, b);
            }
        },
        repo: {
            th: '<th class="gt-repo" scope="col"></th>',
            td: function (s) {
                var txt;
                if (!s.repo) {
                    txt = '';
                } else if (anonymous) {
                    txt = '<a href="" data-pa-link="'.concat(escape_entities(s.repo), '" class="ui pa-anonymized-link">repo</a>');
                } else {
                    txt = '<a class="track" href="'.concat(escape_entities(s.repo), '">repo</a>');
                }
                if (s.repo_broken) {
                    txt += ' <strong class="err">broken</strong>';
                }
                if (s.repo_unconfirmed) {
                    txt += ' <strong class="err">unconfirmed</strong>';
                }
                if (s.repo_too_open) {
                    txt += ' <strong class="err">open</strong>';
                }
                if (s.repo_handout_old) {
                    txt += ' <strong class="err">handout</strong>';
                }
                if (s.repo_partner_error) {
                    txt += ' <strong class="err">partner</strong>';
                }
                if (s.repo_sharing) {
                    txt += ' <strong class="err">sharing</strong>';
                }
                return '<td class="gt-repo">'.concat(txt, '</td>');
            },
            tw: 10
        }
    };

    function string_function(s) {
        return function () { return s; };
    }
    function set_sort_nameflag() {
        if (sort.f === "name" || sort.f === "name2" || sort.f === "username"
            || sort.f === "email" || sort.nameflag == null) {
            sort.nameflag = 0;
            if (sort.f === "name" || sort.f === "name2") {
                sort.nameflag |= 1;
            }
            if (sort.last) {
                sort.nameflag |= 2;
            }
            if (sort.email) {
                sort.nameflag |= 4;
            }
            if (anonymous) {
                sort.nameflag |= 8;
            }
        }
    }
    function initialize() {
        var x = wstorage.site(true, "pa-pset" + pconf.id + "-table");
        x && (sort = JSON.parse(x));
        if (!sort.f || !/^\w+$/.test(sort.f)) {
            sort.f = "username";
        }
        if (sort.rev !== 1 && sort.rev !== -1) {
            sort.rev = 1;
        }
        if (!anonymous
            || !pconf.can_override_anonymous
            || !sort.override_anonymous) {
            delete sort.override_anonymous;
        }
        if (anonymous && sort.override_anonymous) {
            anonymous = false;
        }
        if (sort.nameflag == null) {
            set_sort_nameflag();
        }

        table_entries = [];
        if (pconf.grades) {
            pconf.grades = new GradeSheet(pconf.grades);
            for (let i = 0; i !== pconf.grades.value_order.length; ++i) {
                const k = pconf.grades.value_order[i], ge = pconf.grades.entries[k];
                if (ge.type_tabular) {
                    table_entries.push(ge);
                }
            }
        }

        let ngrades_expected = -1, has_late_hours = false;
        for (let i = 0; i < data.length; ++i) {
            const s = data[i];
            if (s.dropped) {
                s.boringness = 2;
            } else if (s.emptydiff
                       || (!s.gradehash && !s.hash && !pconf.gitless_grades)) {
                s.boringness = 1;
            } else {
                s.boringness = 0;
            }
            let ngrades = 0;
            for (var j = 0; j !== table_entries.length; ++j) {
                if (table_entries[j].key != pconf.total_key
                    && s.grades[j] != null
                    && s.grades[j] !== "")
                    ++ngrades;
            }
            s.ngrades_nonempty = ngrades;
            if (ngrades_expected === -1) {
                ngrades_expected = ngrades;
            } else if (ngrades_expected !== ngrades && (!s.boringness || ngrades > 0)) {
                ngrades_expected = -2;
            }
            has_late_hours = has_late_hours || !!s.late_hours;
        }
        need_ngrades = ngrades_expected === -2;

        if (pconf.col) {
            col = pconf.col;
        } else {
            col = [];
            if (pconf.checkbox) {
                col.push(pconf.flagged_commits ? "flagcheckbox" : "checkbox");
            }
            col.push("rownumber");
            if (flagged) {
                col.push("pset");
                col.push("at");
            } else {
                col.push("gdialog");
            }
            col.push("username", "name", "extension", "year", "grader");
            if (has_late_hours) {
                col.push("latehours");
            }
            if (flagged) {
                col.push("conversation");
            }
            if (flagged || !pconf.gitless_grades || visible) {
                col.push("notes");
            }
            if (pconf.need_total) {
                total_colpos = col.length;
                col.push("total");
            }
            for (let i = 0; i !== table_entries.length; ++i) {
                const ge = table_entries[i];
                ge.gpos = i;
                ge.colpos = col.length;
                col.push(ge.configure_column({
                    type: "grade",
                    name: "grade" + i,
                    gidx: i,
                    gkey: ge.key
                }, pconf));
            }
            if (need_ngrades) {
                ngrades_colpos = col.length;
                col.push("ngrades");
            }
            if (!pconf.gitless) {
                col.push("repo");
            }
        }

        colmap = {};
        for (let i = 0; i !== col.length; ++i) {
            if (typeof col[i] === "string") {
                col[i] = {type: col[i], name: col[i]};
            }
            col[i].index = i;
            Object.assign(col[i], col_renderers[col[i].type]);
            if (typeof col[i].th === "string") {
                col[i].th = string_function(col[i].th);
            }
            colmap[col[i].name] = colmap[col[i].name] || col[i];
        }
        name_col = colmap.name;

        if ($j[0].closest("form")) {
            slist_input = $('<input name="slist" type="hidden" value="">')[0];
            $j.after(slist_input);
        }
    }

    function ukey(s) {
        return (anonymous && s.anon_username) || s.username || "";
    }
    function url_gradeparts(s) {
        var args = {
            u: ukey(s),
            pset: s.psetid ? siteinfo.psets[s.psetid].urlkey : pconf.key
        };
        if (s.hash && (!s.is_grade || flagged)) {
            args.commit = s.hash;
        } else if (s.gradehash) {
            args.commit = s.gradehash;
            args.commit_is_grade = 1;
        }
        return args;
    }
    function escaped_href(s) {
        return escape_entities(hoturl("pset", url_gradeparts(s)));
    }
    function render_student_link(t, s) {
        return '<a href="'.concat(escaped_href(s), '" class="track',
            s.dropped ? ' gt-dropped">' : '">', t, '</a>');
    }
    function render_username_td(s) {
        var un;
        if (anonymous && s.anon_username) {
            un = s.anon_username;
        } else if (sort.email && s.email) {
            un = s.email;
        } else {
            un = s.username || "";
        }
        return render_student_link(escape_entities(un), s);
    }
    function render_name(s, last_first) {
        if (s.first != null && s.last != null) {
            if (last_first)
                return s.last.concat(", ", s.first);
            else
                return s.first.concat(" ", s.last);
        } else if (s.first != null) {
            return s.first;
        } else if (s.last != null) {
            return s.last;
        } else {
            return "";
        }
    }
    function render_display_name(s, is2) {
        var t = escape_entities(is2 && anonymous ? s.anon_username || "?" : render_name(s, displaying_last_first));
        return is2 ? render_student_link(t, s) : t;
    }
    function render_name_text(s) {
        if (s) {
            return (anonymous ? s.anon_username : render_name(s, displaying_last_first)) || "?";
        } else {
            return "[none]";
        }
    }
    function render_checkbox_name(s) {
        var u = anonymous ? s.anon_username || s.username : s.username;
        return "s:" + encodeURIComponent(u).replace(/\./g, "%2E");
    }
    function grader_name(p) {
        if (!p.__nickname) {
            if (p.nick)
                p.__nickname = p.nick;
            else if (p.nicklen || p.lastpos)
                p.__nickname = p.name.substr(0, p.nicklen || p.lastpos - 1);
            else
                p.__nickname = p.name;
        }
        return p.__nickname;
    }

    function make_hotlist(event) {
        var j = [];
        for (var i = 0; i < data.length; ++i) {
            var s = data[i],
                t = "~".concat(encodeURIComponent(ukey(s)));
            if (flagged) {
                t = t.concat("/pset/", siteinfo.psets[s.psetid].urlkey);
                if (s.hash)
                    t = t.concat("/", s.hash);
            }
            j.push(t);
        }
        event.hotlist = {pset: flagged ? null : pconf.key, items: j};
    }
    function make_rmap($j) {
        var rmap = {}, tr = $j.find("tbody")[0].firstChild, last = null;
        while (tr) {
            if (tr.hasAttribute("data-pa-partner"))
                last.push(tr);
            else
                rmap[tr.getAttribute("data-pa-spos")] = last = [tr];
            tr = tr.nextSibling;
        }
        return rmap;
    }
    function resort_table($j) {
        var $b = $j.children("tbody"),
            ncol = $j.children("thead")[0].firstChild.childNodes.length,
            tb = $b[0],
            rmap = make_rmap($j),
            i, j, trn = 0, was_boringness = false,
            last = tb.firstChild;
        for (i = 0; i !== data.length; ++i) {
            var s = data[i];
            while ((j = last) && j.className === "gt-boring") {
                last = last.nextSibling;
                tb.removeChild(j);
            }
            if (s.boringness !== was_boringness && was_boringness !== false) {
                tb.insertBefore($('<tr class="gt-boring"><td colspan="' + ncol + '"><hr></td></tr>')[0], last);
            }
            was_boringness = s.boringness;
            var tr = rmap[s._spos];
            for (j = 0; j < tr.length; ++j) {
                if (last !== tr[j])
                    tb.insertBefore(tr[j], last);
                else
                    last = last.nextSibling;
                removeClass(tr[j], "k" + (1 - trn % 2));
                addClass(tr[j], "k" + (trn % 2));
            }
            ++trn;
        }

        trn = 0;
        $b.find(".gt-rownumber").html(function () {
            ++trn;
            return trn + ".";
        });

        var display_last_first = sort.f && sort.last;
        if (display_last_first !== displaying_last_first) {
            displaying_last_first = display_last_first;
            $b.find(".gt-name, .gt-name2").html(function () {
                var s = smap[this.parentNode.getAttribute("data-pa-spos")];
                return render_display_name(s, hasClass(this, "gt-name2"));
            });
        }
    }
    function assign_slist() {
        var j = [];
        for (var i = 0; i !== data.length; ++i) {
            j.push(ukey(data[i]));
        }
        slist_input.value = j.join(" ");
    }
    function resort() {
        resort_table($j);
        $overlay && resort_table($overlay);
        slist_input && assign_slist();
        wstorage.site(true, "pa-pset" + pconf.id + "-table", JSON.stringify(sort));
    }
    function make_uid2tr() {
        var uid2tr = {}, tr = $j.find("tbody")[0].firstChild;
        while (tr) {
            uid2tr[tr.getAttribute("data-pa-uid")] = tr;
            tr = tr.nextSibling;
        }
        return uid2tr;
    }
    function rerender_usernames() {
        var $x = $overlay ? $([$j[0], $overlay[0]]) : $j;
        $x.find("td.gt-username").each(function () {
            var s = smap[this.parentNode.getAttribute("data-pa-spos")];
            $(this).html(render_username_td(s));
        });
        $x.find("th.gt-username > span.heading").html(anonymous || !sort.email ? "Username" : "Email");
        $x.find("td.gt-name2").each(function () {
            var s = smap[this.parentNode.getAttribute("data-pa-spos")];
            $(this).html(render_display_name(s, true));
        });
        $x.find("th.gt-name2 > span.heading").html(anonymous ? "Username" : "Name");
    }
    function display_anon() {
        $j.toggleClass("gt-anonymous", !!anonymous);
        if (table_width && name_col) {
            $j.css("width", (table_width - (anonymous ? name_col.width : 0)) + "px");
            $($j[0].firstChild).find(".gt-name").css("width", (anonymous ? 0 : name_col.width) + "px");
        }
    }
    function switch_anon(evt) {
        anonymous = !anonymous;
        if (!anonymous)
            sort.override_anonymous = true;
        display_anon();
        rerender_usernames();
        $j.find("tbody input.gt-check").each(function () {
            var s = smap[this.parentNode.parentNode.getAttribute("data-pa-spos")];
            this.setAttribute("name", render_checkbox_name(s));
        });
        sort_data();
        resort();
        $j.closest("form").find("input[name=anonymous]").val(anonymous ? 1 : 0);
        evt.preventDefault();
        evt.stopPropagation();
    }
    function overlay_create() {
        let li = 0, ri, tw = 0, t, a = [];
        while (li !== col.length && !col[li].pin) {
            ++li;
        }
        for (ri = li; ri !== col.length && col[ri].pin; ++ri) {
            tw += col[ri].width;
        }

        t = '<table class="gtable gtable-fixed gtable-overlay new" style="position:absolute;left:-24px;width:' +
            (tw + 24) + 'px"><thead><tr class="k0 kfade"><th style="width:24px"></th>';
        for (let i = li; i !== ri; ++i) {
            t += '<th style="width:' + col[i].width + 'px"' +
                col[i].th.call(col[i]).substring(3);
        }
        $overlay = $(t + '</thead><tbody></tbody></table>');

        var tr = $j[0].firstChild.firstChild,
            otr = $overlay[0].firstChild.firstChild;
        for (let i = li; i !== ri; ++i) {
            otr.childNodes[i - li + 1].className = tr.childNodes[i].className;
        }

        $j[0].parentNode.prepend($('<div style="position:sticky;left:0;z-index:2"></div>').append($overlay)[0]);
        $overlay.find("thead").on("click", "th", head_click);
        $overlay.find(".js-switch-anon").click(switch_anon);

        tr = $j.children("tbody")[0].firstChild;
        while (tr) {
            if (hasClass(tr, "gt-boring")) {
                a.push('<tr class="gt-boring"><td colspan="' + (ri - li + 1) + '"><hr></td></tr>');
            } else {
                let spos = tr.getAttribute("data-pa-spos"),
                    t = '<tr class="' + tr.className + ' kfade" data-pa-spos="' + spos;
                if (tr.hasAttribute("data-pa-uid")) {
                    t += '" data-pa-uid="' + tr.getAttribute("data-pa-uid");
                }
                if (tr.hasAttribute("data-pa-partner")) {
                    t += '" data-pa-partner="1';
                }
                t += '"><td></td>';
                for (let i = li; i !== ri; ++i) {
                    t += '<td style="height:' + tr.childNodes[i].clientHeight +
                        'px"' + col[i].td.call(col[i], smap[spos], "").substring(3);
                }
                a.push(t + '</tr>');
            }
            tr = tr.nextSibling;
        }
        $overlay.find("tbody").html(a.join(""));
        setTimeout(function () {
            $overlay && removeClass($overlay[0], "new");
        }, 0);
    }
    function render_user_compare(u) {
        let t = "";
        if ((active_nameflag & 8) && u.anon_username) {
            t = u.anon_username + " ";
        } else if (active_nameflag & 1) {
            t = render_name(u, (active_nameflag & 2) === 2) + " ";
        }
        if ((active_nameflag & 4) && u.email) {
            t += u.email;
        } else {
            t += u.username || "";
        }
        if (u.psetid != null) {
            t += sprintf(" %5d", u.psetid);
        }
        if (u.at != null) {
            t += sprintf(" %11g", u.at);
        }
        return t.toLowerCase();
    }
    function user_compare(a, b) {
        return a._sort_user.localeCompare(b._sort_user);
    }
    function grader_compare(a, b) {
        var ap = a.gradercid ? siteinfo.pc[a.gradercid] : null;
        var bp = b.gradercid ? siteinfo.pc[b.gradercid] : null;
        var ag = (ap && grader_name(ap)) || "~~~";
        var bg = (bp && grader_name(bp)) || "~~~";
        if (ag != bg) {
            return ag < bg ? -1 : 1;
        } else {
            return 0;
        }
    }
    function set_user_sorters() {
        if (sort.nameflag !== active_nameflag) {
            active_nameflag = sort.nameflag;
            for (var i = 0; i < data.length; ++i) {
                data[i]._sort_user = render_user_compare(data[i]);
            }
        }
    }
    function sort_data() {
        let f = sort.f;
        set_user_sorters();
        let colr = colmap[f];
        if (colr && colr.compare) {
            data.sort(colr.compare);
        } else if (colr && colr.make_compare) {
            data.sort(colr.make_compare(sort));
        } else if ((f === "name" || f === "name2") && !anonymous) {
            data.sort(user_compare);
        } else if (f === "gradestatus") {
            data.sort(function (a, b) {
                const av = scores_visible_for(a), bv = scores_visible_for(b);
                if (av !== bv) {
                    return av ? -1 : 1;
                } else if (a.has_notes != b.has_notes) {
                    return a.has_notes ? -1 : 1;
                } else {
                    return grader_compare(a, b) || user_compare(a, b);
                }
            });
        } else if (sort.email && !anonymous) {
            f = "username";
            data.sort(function (a, b) {
                var ae = (a.email || "").toLowerCase(), be = (b.email || "").toLowerCase();
                if (ae !== be) {
                    if (ae === "" || be === "")
                        return ae === "" ? 1 : -1;
                    else
                        return ae < be ? -1 : 1;
                } else {
                    return user_compare(a, b);
                }
            });
        } else { /* "username" */
            if (f !== "name2") {
                f = "username";
            }
            data.sort(user_compare);
        }

        if (sort.rev < 0) {
            data.reverse();
        }
        data.sort(function (a, b) {
            return a.boringness !== b.boringness ? a.boringness - b.boringness : 0;
        });

        var $x = $overlay ? $([$j[0].firstChild, $overlay[0].firstChild]) : $($j[0].firstChild);
        $x.find(".plsortable").removeClass("plsortactive plsortreverse");
        $x.find("th[data-pa-sort='" + f + "']").addClass("plsortactive").
            toggleClass("plsortreverse", sort.rev < 0);
    }
    function head_click() {
        if (!this.hasAttribute("data-pa-sort"))
            return;
        const sf = this.getAttribute("data-pa-sort");
        if (sf !== sort.f) {
            sort.f = sf;
            const col = colmap[sf];
            sort.rev = col.sort_forward ? 1 : -1;
        } else if (sf === "name" || (sf === "name2" && !anonymous)) {
            sort.rev = -sort.rev;
            if (sort.rev === 1) {
                sort.last = !sort.last;
            }
        } else if (sf === "username") {
            if (sort.rev === -1 && !anonymous) {
                sort.email = !sort.email;
                rerender_usernames();
            }
            sort.rev = -sort.rev;
        } else {
            sort.rev = -sort.rev;
        }
        set_sort_nameflag();
        sort_data();
        resort();
    }

    function grade_update(uid2tr, rv, gorder) {
        const tr = uid2tr[rv.uid],
            su = smap[tr.getAttribute("data-pa-spos")];
        let ngrades_nonempty = 0;
        for (let i = 0; i !== gorder.length; ++i) {
            let k = gorder[i], ge = pconf.grades.entries[k], c;
            if (ge && ge.gpos != null && (c = col[ge.colpos])) {
                if (su.grades[ge.gpos] !== rv.grades[i]) {
                    su.grades[ge.gpos] = rv.grades[i];
                    tr.childNodes[ge.colpos].innerText = c.td_text.call(c, su);
                }
                if (rv.grades[i] != null && rv.grades[i] !== "") {
                    ++ngrades_nonempty;
                }
            }
        }
        if (rv.total !== su.total) {
            su.total = rv.total;
            if (total_colpos) {
                const t = su.total == null ? "" : su.total;
                tr.childNodes[total_colpos].innerText = t;
            }
        }
        if (ngrades_nonempty !== su.ngrades_nonempty) {
            su.ngrades_nonempty = ngrades_nonempty;
            if (ngrades_colpos)
                tr.childNodes[ngrades_colpos].innerText = su.ngrades_nonempty || "";
        }
    }
    function gradesetting_update(uid2tr, rv) {
        const tr = uid2tr[rv.uid],
            su = smap[tr.getAttribute("data-pa-spos")],
            gradercol = colmap.grader,
            notescol = colmap.notes;
        Object.assign(su, rv);
        if (gradercol) {
            tr.childNodes[gradercol.index].innerHTML = gradercol.td_html.call(gradercol, su);
        }
        if (notescol) {
            tr.childNodes[notescol.index].innerHTML = notescol.td_html.call(notescol, su);
        }
    }

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
    function gdialog_gradesheet_submit() {
        const $gsi = $gdialog.find(".pa-gdialog-gradesheet input"),
            ge = [], us = [];
        for (let i = 0; i !== $gsi.length; ++i) {
            if ($gsi[i].checked)
                ge.push(pconf.grades.entries[$gsi[i].name]);
        }
        for (let su of gdialog_su) {
            us.push(su.uid);
        }
        if (ge.length === 0) {
            alert("No grades selected.");
        } else {
            const opt = {pset: pconf.key, anonymous: anonymous ? 1 : "", users: us.join(" ")};
            if (ge.length === 1 && ge[0].landmark_range_file) {
                opt.file = ge[0].landmark_range_file;
                opt.lines = ge[0].landmark_range_first + "-" + ge[0].landmark_range_last;
            }
            opt.grade = ge[0].key;
            for (let i = 1; i !== ge.length; ++i) {
                opt.grade += " " + ge[i].key;
            }
            hoturl_post_go("=diffmany", opt);
        }
    }
    function gdialog_store(next) {
        const gradesheet_mode = $gdialog.find("button[name=mode-gradesheet]").hasClass("btn-primary");
        let any = false, byuid = {};
        if (gradesheet_mode) {
            if (!next) {
                gdialog_gradesheet_submit();
                return;
            }
        } else {
            $gdialog.find(".pa-gradevalue").each(function () {
                if ((this.hasAttribute("data-pa-unmixed") || input_differs(this))
                    && !this.indeterminate) {
                    let k = this.name, ge = pconf.grades.entries[k], v;
                    if (this.type === "checkbox") {
                        v = this.checked ? this.value : "";
                    } else {
                        v = $(this).val();
                    }
                    for (let su of gdialog_su) {
                        byuid[su.uid] = byuid[su.uid] || {grades: {}, oldgrades: {}};
                        byuid[su.uid].grades[k] = v;
                        byuid[su.uid].oldgrades[k] = su.grades[ge.gpos];
                    }
                    any = true;
                }
            });
        }
        next = next || function () { $gdialog.close(); };
        if (!any) {
            next();
        } else if (gdialog_su.length === 1) {
            api_conditioner(hoturl("=api/grade", url_gradeparts(gdialog_su[0])),
                byuid[gdialog_su[0].uid])
            .then(function (rv) {
                gdialog_store_start(rv);
                if (rv.ok) {
                    grade_update(make_uid2tr(), rv, rv.value_order || rv.order);
                    next();
                }
            });
        } else {
            for (let su of gdialog_su) {
                if (su.gradehash) {
                    byuid[su.uid].commit = su.gradehash;
                    byuid[su.uid].commit_is_grade = 1;
                } else if (su.hash) {
                    byuid[su.uid].commit = su.hash;
                }
            }
            api_conditioner(hoturl("=api/multigrade", {pset: pconf.key}),
                {us: JSON.stringify(byuid)})
            .then(function (rv) {
                gdialog_store_start(rv);
                if (rv.ok) {
                    const uid2tr = make_uid2tr();
                    for (let rvu of rv.us) {
                        grade_update(uid2tr, rvu, rv.value_order || rv.order);
                    }
                    next();
                }
            });
        }
    }
    function gdialog_traverse() {
        const next_spos = this.getAttribute("data-pa-spos");
        gdialog_store(function () { gdialog_fill([next_spos]); });
    }
    function gdialog_fill_user(su1) {
        let t;
        if (su1.first || su1.last) {
            t = su1.first.concat(" ", su1.last, " <", su1.email, ">");
        } else {
            t = "<".concat(su1.email, ">");
        }
        $gdialog.find(".gt-name-email").html(escape_entities(t)).removeClass("hidden");
        let tr = $j.find("tbody")[0].firstChild, tr1;
        while (tr && tr.getAttribute("data-pa-spos") != su1._spos) {
            tr = tr.nextSibling;
        }
        for (tr1 = tr; tr1 && (tr1 === tr || !tr1.hasAttribute("data-pa-spos")); ) {
            tr1 = tr1.previousSibling;
        }
        $gdialog.find("button[name=prev]").attr("data-pa-spos", tr1 ? tr1.getAttribute("data-pa-spos") : "").prop("disabled", !tr1);
        for (tr1 = tr; tr1 && (tr1 === tr || !tr1.hasAttribute("data-pa-spos")); ) {
            tr1 = tr1.nextSibling;
        }
        $gdialog.find("button[name=next]").attr("data-pa-spos", tr1 ? tr1.getAttribute("data-pa-spos") : "").prop("disabled", !tr1);
    }
    function gdialog_fill(spos) {
        gdialog_su = [];
        for (let i = 0; i !== spos.length; ++i) {
            gdialog_su.push(smap[spos[i]]);
        }
        $gdialog.find("h2").html(escape_entities(pconf.title) + " : " +
            gdialog_su.map(function (su) {
                return escape_entities(anonymous ? su.anon_username : su.username || su.email);
            }).join(", "));
        if (gdialog_su.length === 1) {
            gdialog_fill_user(gdialog_su[0]);
        } else {
            $gdialog.find(".gt-name-email").addClass("hidden");
        }

        $gdialog.find(".pa-gradelist").toggleClass("pa-pset-hidden",
            !!gdialog_su.find(function (su) { return !scores_visible_for(su); }));
        $gdialog.find(".pa-grade").each(function () {
            let k = this.getAttribute("data-pa-grade"),
                ge = pconf.grades.entries[k],
                sv = gdialog_su[0].grades[ge.gpos],
                opts = {reset: true, mixed: false};
            for (let i = 1; i !== gdialog_su.length; ++i) {
                let suv = gdialog_su[i].grades[ge.gpos];
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
    function gdialog_mode_values() {
        const gl = $gdialog.find(".pa-gradelist")[0];
        if (!gl.firstChild) {
            const gi = GradeSheet.closest(gl);
            for (let i = 0; i !== table_entries.length; ++i) {
                gl.appendChild(table_entries[i].render(gi, 1));
            }
        }
        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        gl.classList.remove("hidden");
        $gdialog.find("button[name=bsubmit]").text("Save").removeClass("hidden");
    }
    function gdialog_mode_gradesheet() {
        const gs = $gdialog.find(".pa-gdialog-gradesheet")[0];
        if (!gs.firstChild) {
            const yc = new HtmlCollector;
            let in_section = false;
            for (let i = 0; i !== pconf.grades.order.length; ++i) {
                const ge = pconf.grades.entries[pconf.grades.order[i]],
                    gcl = in_section && ge.type !== "section" ? "checki ml-4" : "checki",
                    ccl = ge.type === "section" ? " pa-gdialog-section" : "";
                yc.push('<label class="'.concat(gcl, '"><span class="checkc"><input type="checkbox" name="', ge.key, '" class="uic js-range-click', ccl, '" data-range-type="mge"></span>', ge.title_html, '</label>'));
                in_section = in_section || ge.type === "section";
            }
            gs.innerHTML = yc.render();
        }
        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        gs.classList.remove("hidden");
        $gdialog.find("button[name=bsubmit]").text("Edit gradesheet").removeClass("hidden");
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
                us[su.uid].pinned_scores_visible = gvisarg;
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
        }
        const usc = [];
        for (let su of gdialog_su) {
            usc.push(us[su.uid]);
        }
        let usci = 0;
        function more() {
            const byuid = usc.slice(usci, usci + 16);
            usci += byuid.length;
            api_conditioner(hoturl("=api/gradesettings", {pset: pconf.key}),
                {us: JSON.stringify(byuid)},
                {timeout: 20000})
            .then(function (rv) {
                gdialog_store_start(rv);
                if (rv.ok && rv.us) {
                    const uid2tr = make_uid2tr();
                    for (let rvu of Object.values(rv.us)) {
                        gradesetting_update(uid2tr, rvu);
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
            yc.push('<fieldset class="mb-3"><legend>Grade visibility</legend>', '</fieldset>');
            yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="gvis" value="true" class="uic pa-gvis"></span>Visible</label>');
            yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="gvis" value="false" class="uic pa-gvis"></span>Hidden</label>');
            yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="gvis" value="null" class="uic pa-gvis"></span>Default (' + (pconf.scores_visible ? 'visible' : 'hidden') + ')</label>');
            yc.push('<div class="popup-actions"><button type="button" class="btn btn-primary" name="save-gvis">Save</button></div>');
            yc.pop();

            yc.push('<fieldset><legend>Grader</legend>', '</fieldset>');
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
            gs.innerHTML = yc.render();
            $(gs).on("click", "button", gdialog_settings_submit);
            $(gs).on("click", ".pa-grader", gdialog_settings_grader_click);
            $(gs).on("click", ".pa-gvis", gdialog_settings_gvis_click);
        }

        const gvis = {}, ggr = {}, ggra = {};
        for (let su of gdialog_su) {
            if (su.pinned_scores_visible == null) {
                gvis["null"] = (gvis["null"] || 0) + 1;
            } else if (su.pinned_scores_visible) {
                gvis["true"] = (gvis["true"] || 0) + 1;
            } else {
                gvis["false"] = (gvis["false"] || 0) + 1;
            }
            const grcid = su.gradercid || 0;
            ggr[grcid] = (ggr[grcid] || 0) + 1;
        }
        for (let su of smap) {
            const grcid = su.gradercid || 0;
            ggra[grcid] = (ggra[grcid] || 0) + 1;
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

        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        gs.classList.remove("hidden");
        $gdialog.find("button[name=bsubmit]").addClass("hidden");
    }
    function gdialog_mode() {
        $gdialog.find(".nav-pills button").removeClass("btn-primary");
        if (this.name === "mode-gradesheet") {
            gdialog_mode_gradesheet();
        } else if (this.name === "mode-settings") {
            gdialog_mode_settings();
        } else {
            gdialog_mode_values();
        }
        this.classList.add("btn-primary");
    }
    function gdialog() {
        gdialog_self = this;
        const hc = popup_skeleton();
        hc.push('<h2></h2>');
        if (!anonymous)
            hc.push('<strong class="gt-name-email"></strong>');
        hc.push('<div class="pa-messages"></div>');

        hc.push('<div class="nav-pills">', '</div>');
        hc.push('<button type="button" class="btn btn-primary no-focus is-mode" name="mode-values">Values</button>');
        hc.push('<button type="button" class="btn no-focus is-mode" name="mode-gradesheet">Gradesheet</button>');
        hc.push('<button type="button" class="btn no-focus is-mode" name="mode-settings">Settings</button>');
        hc.pop();

        hc.push('<div class="pa-gdialog-tab pa-gradelist is-modal"></div>');
        hc.push('<div class="pa-gdialog-tab pa-gdialog-gradesheet multicol-3 hidden"></div>');
        hc.push('<div class="pa-gdialog-tab pa-gdialog-settings hidden"></div>');

        hc.push_actions();
        hc.push('<button type="button" name="bsubmit" class="btn-primary">Save</button>');
        hc.push('<button type="button" name="cancel">Cancel</button>');
        hc.push('<span class="btnbox"><button type="button" name="prev" class="btnl">&lt;</button><button type="button" name="next" class="btnl">&gt;</button></span>');
        $gdialog = hc.show(false);
        $gdialog.children(".modal-dialog").addClass("modal-dialog-wide");
        $gdialog.find("form").addClass("pa-psetinfo").data("pa-gradeinfo", pconf.grades);
        gdialog_mode_values();
        $gdialog.on("click", ".pa-gdialog-section", gdialog_section_click);
        $gdialog.on("change blur", ".pa-gradevalue", gdialog_gradelist_change);
        $gdialog.on("input change", ".pa-gradevalue", gdialog_gradelist_input);
        $gdialog.on("keydown", gdialog_key);
        $gdialog.on("keydown", "input, textarea, select", gdialog_key);
        //$gdialog.find(".pa-gradelist").on("input", "input, textarea, select", gdialog_gradelist_input);
        $gdialog.find("button[name=bsubmit]").on("click", function () { gdialog_store(null); });
        $gdialog.find("button[name=prev], button[name=next]").on("click", gdialog_traverse);
        $gdialog.find("button.is-mode").on("click", gdialog_mode);
        const checked_spos = $j.find(".papsel:checked").toArray().map(function (x) {
                return x.parentElement.parentElement.getAttribute("data-pa-spos");
            }),
            my_spos = gdialog_self.closest("tr").getAttribute("data-pa-spos");
        if (checked_spos.indexOf(my_spos) < 0) {
            gdialog_fill([my_spos]);
        } else {
            $gdialog.find("button[name=prev], button[name=next]").prop("disabled", true).addClass("hidden");
            gdialog_fill(checked_spos);
        }
        hc.show();
    }
    $j.parent().on("click", "a.js-gdialog", function (event) {
        gdialog.call(this);
        event.preventDefault();
    });

    function make_overlay_observer() {
        let i = 0;
        while (i !== col.length && !col[i].pin) {
            ++i;
        }
        var overlay_div = $('<div style="position:absolute;left:0;top:0;bottom:0;width:' + (col[i].left - 10) + 'px;pointer-events:none"></div>').prependTo($j.parent())[0],
            table_hit = false, left_hit = false;
        function observer_fn(entries) {
            for (var e of entries) {
                if (e.target === overlay_div) {
                    left_hit = e.isIntersecting;
                } else {
                    table_hit = e.isIntersecting;
                }
            }
            if (table_hit && !left_hit && !$overlay) {
                overlay_create();
            } else if (table_hit && left_hit && $overlay) {
                $overlay.parent().remove();
                $overlay = null;
            }
        }
        var observer = new IntersectionObserver(observer_fn);
        observer.observe(overlay_div);
        observer.observe($j.parent()[0]);
    }
    function render_tds(s, rownum) {
        var a = [];
        for (var i = 0; i !== col.length; ++i)
            a.push(col[i].td.call(col[i], s, rownum));
        return a;
    }
    function render() {
        var thead = $('<thead><tr class="k0"></tr></thead>')[0],
            tfixed = $j.hasClass("want-gtable-fixed"),
            rem = parseFloat(window.getComputedStyle(document.documentElement).fontSize);
        for (let i = 0; i !== col.length; ++i) {
            var th = col[i].th.call(col[i]), $th = $(th);
            if (tfixed) {
                col[i].left = table_width;
                var w = col[i].tw;
                if (typeof w !== "number") {
                    w = w.call(col[i]);
                }
                w *= rem;
                col[i].width = w;
                $th.css("width", w + "px");
                table_width += w;
            }
            thead.firstChild.appendChild($th[0]);
        }
        display_anon();
        $j[0].appendChild(thead);
        $j.toggleClass("gt-useemail", !!sort.email);
        $j.find("thead").on("click", "th", head_click);
        $j.find(".js-switch-anon").click(switch_anon);
        if (tfixed) {
            $j.removeClass("want-gtable-fixed").css("table-layout", "fixed");
        }

        var tbody = $('<tbody class="has-hotlist"></tbody>')[0],
            trn = 0, was_boringness = 0, a = [];
        $j[0].appendChild(tbody);
        if (!pconf.no_sort) {
            sort_data();
        }
        displaying_last_first = sort.f === "name" && sort.last;
        for (let i = 0; i !== data.length; ++i) {
            var s = data[i];
            s._spos = smap.length;
            smap.push(s);
            ++trn;
            if (s.boringness !== was_boringness && trn != 1) {
                a.push('<tr class="gt-boring"><td colspan="' + col.length + '"><hr></td></tr>');
            }
            was_boringness = s.boringness;
            var stds = render_tds(s, trn);
            var t = '<tr class="k'.concat(trn % 2, '" data-pa-spos="', s._spos);
            if (s.uid)
                t += '" data-pa-uid="' + s.uid;
            a.push(t.concat('">', stds.join(''), '</tr>'));
            for (var j = 0; s.partners && j < s.partners.length; ++j) {
                var ss = s.partners[j];
                ss._spos = smap.length;
                smap.push(ss);
                var sstds = render_tds(s.partners[j], "");
                for (var k = 0; k < sstds.length; ++k) {
                    if (sstds[k] === stds[k])
                        sstds[k] = '<td></td>';
                }
                t = '<tr class="k'.concat(trn % 2, ' gtrow-partner" data-pa-spos="', ss._spos);
                if (ss.uid)
                    t += '" data-pa-uid="' + ss.uid;
                a.push(t.concat('" data-pa-partner="1">', sstds.join(''), '</tr>'));
            }
            if (a.length > 50) {
                $(a.join('')).appendTo(tbody);
                a = [];
            }
        }
        if (a.length !== 0) {
            $(a.join('')).appendTo(tbody);
        }
        slist_input && assign_slist();

        if (tfixed && window.IntersectionObserver) {
            make_overlay_observer();
        }
    }

    initialize();
    render();

    $j.data("paTable", {
        name_text: function (uid) {
            var spos = $j.find("tr[data-pa-uid=" + uid + "]").attr("data-pa-spos");
            return spos ? render_name_text(smap[spos]) : null;
        },
        s: function (spos) {
            return data[spos];
        }
    });
    $j.children("tbody").on("pa-hotlist", make_hotlist);
}

handle_ui.on("js-multiresolveflag", function () {
    const $gt = $(this.closest("form")).find(".gtable").first(),
        pat = $gt.data("paTable"),
        flags = [];
    $gt.find(".papsel:checked").each(function () {
        const s = pat.s(this.closest("tr").getAttribute("data-pa-spos"));
        flags.push({psetid: s.psetid, uid: s.uid, hash: s.hash, flagid: s.flagid});
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
    render_text_page: render_text.on_page,
    render_pset_table: pa_render_pset_table,
    runmany: pa_runmany,
    store_gradeinfo: GradeSheet.store,
    text_eq: text_eq
};
