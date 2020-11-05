// note-edit.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities } from "./encoders.js";
import { hoturl_post, hoturl_gradeparts } from "./hoturl.js";
import { hasClass, addClass, removeClass, handle_ui } from "./ui.js";
import { event_key, event_modkey } from "./ui-key.js";
import { text_eq } from "./utils.js";
import { linediff_find, linediff_traverse, linediff_locate } from "./diff.js";
import { api_conditioner } from "./xhr.js";
import { Note } from "./note.js";


let curanal, down_event, scrolled_x, scrolled_y, scrolled_at;

function render_form($tr, note, transition) {
    $tr.removeClass("hidden").addClass("editing");
    note && note.store_at($tr[0]);
    var $td = $tr.find(".pa-notebox");
    if (transition) {
        $tr.css("display", "").children().css("display", "");
        var $content = $td.children();
        $content.slideUp(80).queue(function () { $content.remove(); });
    }

    var $pi = $(curanal.tr).closest(".pa-psetinfo");
    var format = note ? note.format : null;
    if (format == null)
        format = $tr.closest(".pa-filediff").attr("data-default-format");
    var t = '<form method="post" action="' +
        escape_entities(hoturl_post("api/linenote", hoturl_gradeparts($pi[0], {file: curanal.file, line: curanal.lineid, oldversion: (note && note.version) || 0, format: format}))) +
        '" enctype="multipart/form-data" accept-charset="UTF-8" class="ui-submit pa-noteform">' +
        '<textarea class="pa-note-entry" name="note"></textarea>' +
        '<div class="aab aabr pa-note-aa">' +
        '<div class="aabut"><button class="btn-primary" type="submit">Save comment</button></div>' +
        '<div class="aabut"><button type="button" name="cancel">Cancel</button></div>';
    if (!$pi[0].hasAttribute("data-pa-user-can-view-grades")) {
        t += '<div class="aabut"><label><input type="checkbox" name="iscomment" value="1">Show immediately</label></div>';
    }
    var $form = $(t + '</div></form>').appendTo($td);

    var $ta = $form.find("textarea");
    if (note && note.text !== null) {
        $ta.text(note.text);
        $ta[0].setSelectionRange && $ta[0].setSelectionRange(note.text.length, note.text.length);
    }
    $ta.autogrow().keydown(keydown);
    $form.find("input[name=iscomment]").prop("checked", !!(note && note.iscomment));
    $form.find("button[name=cancel]").click(cancel);
    $form.on("submit", function () {
        pa_save_note.call(this.closest(".pa-dl"));
    });
    if (transition) {
        $ta.focus();
        $form.hide().slideDown(100);
    }
}

function anal_tr() {
    var elt;
    if (curanal && (elt = linediff_find(curanal.ufile, curanal.lineid))) {
        for (elt = elt.closest(".pa-dl"); elt && !elt.offsetParent; ) {
            elt = elt.previousSibling;
        }
        return elt;
    } else {
        return null;
    }
}

function set_scrolled_at(evt) {
    if (evt && evt.pageX != null) {
        scrolled_at = evt.timeStamp;
        scrolled_x = evt.screenX;
        scrolled_y = evt.screenY;
    }
}

function arrowcapture(evt) {
    var key, modkey;
    if ((evt.type === "mousemove"
         && scrolled_at
         && ((Math.abs(evt.screenX - scrolled_x) <= 1 && Math.abs(evt.screenY - scrolled_y) <= 1)
             || evt.timeStamp - scrolled_at <= 200))
        || ((evt.type === "keydown" || evt.type === "keyup")
            && event_key.modifier(evt))) {
        return;
    } else if (evt.type !== "keydown"
               || ((key = event_key(evt)) !== "ArrowUp"
                   && key !== "ArrowDown"
                   && key !== "Enter")
               || ((modkey = event_modkey(evt))
                   && (modkey !== event_modkey.META || key !== "Enter"))
               || !curanal) {
        return uncapture();
    }

    var tr = anal_tr();
    if (!tr) {
        return uncapture();
    }
    if (key === "ArrowDown" || key === "ArrowUp") {
        removeClass(tr, "live");
        tr = linediff_traverse(tr, key === "ArrowDown", 0);
        if (!tr) {
            return;
        }
    }

    curanal = linediff_locate(tr);
    evt.preventDefault();
    set_scrolled_at(evt);
    if (key === "Enter") {
        make_linenote();
    } else {
        var wf = tr.closest(".pa-with-fixed");
        $(tr).addClass("live").scrollIntoView(wf ? {marginTop: wf.firstChild.offsetHeight} : null);
    }
    return true;
}

function capture(tr, keydown) {
    addClass(tr, "live");
    $(".pa-filediff").removeClass("live");
    $(document).off(".pa-linenote");
    $(document).on((keydown ? "keydown.pa-linenote " : "") + "mousemove.pa-linenote mousedown.pa-linenote", arrowcapture);
}

function uncapture() {
    $(".pa-dl.live").removeClass("live");
    $(".pa-filediff").addClass("live");
    $(document).off(".pa-linenote");
}

function unedit(tr, always) {
    tr = tr.closest(".pa-dl");
    var note = Note.at(tr),
        $text = tr ? $(tr).find("textarea") : null;
    if (!tr
        || (!always
            && $text.length
            && !text_eq(note.text, $text.val().replace(/\s+$/, "")))) {
        return false;
    } else {
        removeClass(tr, "editing");
        $(tr).find(":focus").blur();
        note.html_near(tr, true);
        var click_tr = anal_tr();
        if (click_tr) {
            capture(click_tr, true);
        }
        return true;
    }
}

function resolve_grade_range(grb) {
    var count = +grb.getAttribute("data-pa-notes-outstanding") - 1;
    if (count) {
        grb.setAttribute("data-pa-notes-outstanding", count);
    } else {
        grb.removeAttribute("data-pa-notes-outstanding");
        $(grb).find(".pa-grade").each(function () {
            pa_compute_landmark_range_grade.call(this, null, true);
        });
    }
}

function pa_save_note(text) {
    if (!hasClass(this, "pa-gw")) {
        throw new Error("!");
    }
    if (hasClass(this, "pa-outstanding")) {
        return false;
    }
    addClass(this, "pa-outstanding");

    var self = this,
        note = Note.at(this),
        editing = hasClass(this, "editing"),
        table = this.closest(".pa-filediff"),
        pi = table.closest(".pa-psetinfo"),
        grb = this.closest(".pa-grade-range-block"),
        data;
    if (editing) {
        let f = $(this).find("form")[0];
        data = {note: f.note.value};
        if (f.iscomment && f.iscomment.checked) {
            data.iscomment = 1;
        }
        $(f).find(".pa-save-message").remove();
        $(f).find(".aab").append('<div class="aabut pa-save-message">Saving…</div>');
    } else {
        if (typeof text === "function") {
            text = text(note.text, note);
        }
        data = {note: text};
    }
    data.format = note.format;
    if (data.format == null) {
        data.format = table.getAttribute("data-default-format");
    }

    grb && grb.setAttribute("data-pa-notes-outstanding", +grb.getAttribute("data-pa-notes-outstanding") + 1);
    return new Promise(function (resolve, reject) {
        api_conditioner(
            hoturl_post("api/linenote", hoturl_gradeparts(pi, {
                file: note.file, line: note.lineid, oldversion: note.version || 0
            })), data
        ).then(function (data) {
            removeClass(self, "pa-outstanding");
            if (data && data.ok) {
                removeClass(self, "pa-save-failed");
                const nd = data.linenotes[note.file],
                    newnote = Note.parse(nd && nd[note.lineid]);
                newnote.store_at(self);
                if (editing) {
                    $(self).find(".pa-save-message").html("Saved");
                    unedit(self);
                } else {
                    newnote.html_near(self);
                }
                resolve(self);
            } else {
                addClass(self, "pa-save-failed");
                editing && $(self).find(".pa-save-message").html('<strong class="err">' + escape_entities(data.error || "Failed") + '</strong>');
                reject(self);
            }
            grb && resolve_grade_range(grb);
        });
    });
}

function cancel() {
    unedit(this, true);
    return true;
}

function keydown(evt) {
    if (event_key(evt) === "Escape" && !event_modkey(evt) && unedit(this)) {
        return false;
    } else if (event_key(evt) === "Enter" && event_modkey(evt) === event_modkey.META) {
        $(this).closest("form").submit();
        return false;
    } else {
        return true;
    }
}

function nearby(dx, dy) {
    return (dx * dx) + (dy * dy) < 144;
}

function pa_linenote(event) {
    var dl = event.target.closest(".pa-dl");
    if (event.button !== 0
        || !dl
        || dl.matches(".pa-gn, .pa-gx")) {
        return;
    }
    var anal = linediff_locate(event.target),
        t = new Date().getTime();
    if (event.type === "mousedown" && anal) {
        if (curanal
            && curanal.tr === anal.tr
            && down_event
            && nearby(down_event[0] - event.clientX, down_event[1] - event.clientY)
            && t - down_event[2] <= 500) {
            // skip
        } else {
            curanal = anal;
            down_event = [event.clientX, event.clientY, t, false];
        }
    } else if (event.type === "mouseup" && anal) {
        if (curanal
            && curanal.tr === anal.tr
            && down_event
            && nearby(down_event[0] - event.clientX, down_event[1] - event.clientY)
            && !down_event[3]) {
            curanal = anal;
            down_event[3] = true;
            make_linenote(event);
        }
    } else if (event.type === "click" && anal) {
        curanal = anal;
        make_linenote(event);
    } else {
        curanal = down_event = null;
    }
}

function make_linenote(event) {
    var $tr = $(curanal.notetr || Note.html_skeleton_near(curanal.tr));
    set_scrolled_at(event);
    if ($tr.hasClass("editing")) {
        if (unedit($tr[0])) {
            event && event.stopPropagation();
            return true;
        } else {
            var $ta = $tr.find("textarea").focus();
            $ta[0].setSelectionRange && $ta[0].setSelectionRange(0, $ta.val().length);
            return false;
        }
    } else {
        render_form($tr, Note.at($tr[0]), true);
        capture(curanal.tr, false);
        return false;
    }
}

handle_ui.on("pa-editablenotes", pa_linenote);
