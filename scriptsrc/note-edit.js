// note-edit.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities } from "./encoders.js";
import { hoturl_gradeapi } from "./hoturl.js";
import { hasClass, addClass, removeClass, handle_ui } from "./ui.js";
import { event_key, event_modkey } from "./ui-key.js";
import { Linediff } from "./diff.js";
import { Note } from "./note.js";
import { GradeSheet } from "./gradeentry.js";


let curline, down_event, scrolled_x, scrolled_y, scrolled_at;

function locate(e) {
    while (e && e.tagName !== "TEXTAREA" && e.tagName !== "A") {
        if (hasClass(e, "pa-dl")) {
            for (let ln of Linediff.all(e)) {
                if (ln.is_note()) {
                    return ln;
                } else if (ln.element !== e && ln.is_visible() && !ln.is_annotation()) {
                    break;
                }
            }
            return new Linediff(e);
        }
        e = e.parentElement;
    }
    return null;
}

function render_form($tr, note, transition) {
    $tr.removeClass("hidden").addClass("editing");
    var $td = $tr.find(".pa-notebox");
    if (transition) {
        $tr.css("display", "").children().css("display", "");
        var $content = $td.children();
        $content.slideUp(80).queue(function () { $content.remove(); });
    }

    let gi = GradeSheet.closest(curline.element);
    var format = note ? note.format : null;
    if (format == null)
        format = $tr.closest(".pa-filediff").attr("data-default-format");
    var t = '<form method="post" action="' +
        escape_entities(hoturl_gradeapi(gi.element, "=api/linenote", {file: curline.file, line: curline.note_lineid, oldversion: (note && note.version) || 0, format: format})) +
        '" enctype="multipart/form-data" accept-charset="UTF-8" class="ui-submit pa-noteform">' +
        '<textarea class="pa-note-entry" name="note"></textarea>' +
        '<div class="aab aabr pa-note-aa">' +
        '<div class="aabut"><button class="btn-primary" type="submit">Save comment</button></div>' +
        '<div class="aabut"><button type="button" name="cancel">Cancel</button></div>';
    if (!gi.user_visible_scores) {
        t += '<div class="aabut"><label><input type="checkbox" name="iscomment" value="1">Show immediately</label></div>';
    }
    var $form = $(t + '</div></form>').appendTo($td);

    var $ta = $form.find("textarea");
    if (note && note.text !== null) {
        $ta.text(note.text);
        $ta[0].setSelectionRange && $ta[0].setSelectionRange(note.text.length, note.text.length);
    }
    $ta.autogrow().keydown(textarea_keydown);
    $form.find("input[name=iscomment]").prop("checked", !!(note && note.iscomment));
    $form.find("button[name=cancel]").click(cancel);
    $form.on("submit", function () {
        pa_save_my_note(this.closest(".pa-dl"));
    });
    if (transition) {
        $ta.focus();
        $form.hide().slideDown(100);
    }
}

function set_scrolled_at(evt) {
    if (evt && evt.screenX != null) {
        scrolled_at = evt.timeStamp;
        scrolled_x = evt.screenX;
        scrolled_y = evt.screenY;
    }
}

function arrowcapture(evt) {
    let key, modkey;
    if ((evt.type === "mousemove"
         && scrolled_at
         && ((Math.abs(evt.screenX - scrolled_x) <= 1 && Math.abs(evt.screenY - scrolled_y) <= 1)
             || evt.timeStamp - scrolled_at <= 500))
        || ((evt.type === "keydown" || evt.type === "keyup")
            && event_key.modifier(evt))) {
        return;
    } else if (evt.type !== "keydown"
               || ((key = event_key(evt)) !== "ArrowUp"
                   && key !== "ArrowDown"
                   && key !== "Enter")
               || ((modkey = event_modkey(evt))
                   && (modkey !== event_modkey.META || key !== "Enter"))
               || !curline) {
        return uncapture();
    }

    let ln = curline.visible_source();
    if (ln && (key === "ArrowDown" || key === "ArrowUp")) {
        removeClass(ln.element, "live");
        let start = ln;
        const flags = Linediff.ANYFILE + (key === "ArrowDown" ? 0 : Linediff.BACKWARD);
        ln = null;
        for (let lnx of Linediff.all(start, flags)) {
            if (lnx.element !== start.element && lnx.is_visible() && lnx.is_source()) {
                ln = lnx;
                break;
            }
        }
    }
    if (ln) {
        curline = ln;
        evt.preventDefault();
        set_scrolled_at(evt);
        if (key === "Enter") {
            make_linenote();
        } else {
            const wf = ln.element.closest(".pa-with-fixed");
            $(ln.element).addClass("live").scrollIntoView(wf ? {marginTop: wf.firstChild.offsetHeight} : null);
        }
        return true;
    } else {
        return uncapture();
    }
}

function capture(tr, keydown) {
    if (!hasClass(tr, "pa-gw")) {
        addClass(tr, "live");
    }
    $(".pa-filediff").removeClass("live");
    $(document).off(".pa-linenote");
    $(document).on((keydown ? "keydown.pa-linenote " : "") + "mousemove.pa-linenote mousedown.pa-linenote", arrowcapture);
}

function uncapture() {
    $(".pa-dl.live").removeClass("live");
    $(".pa-filediff").addClass("live");
    $(document).off(".pa-linenote");
}

function unedit(note) {
    const done = note.render(true),
        ctr = curline && curline.visible_source();
    ctr && capture(ctr.element, true);
    return done;
}

function pa_save_my_note(elt) {
    if (!hasClass(elt, "pa-gw")) {
        throw new Error("bad `elt` in pa_save_my_note");
    } else if (!hasClass(elt, "pa-outstanding")) {
        const f = $(elt).find("form")[0],
            text = f.elements.note.value,
            iscomment = f.elements.iscomment && f.elements.iscomment.checked;
        $(f).find(".pa-save-message").remove();
        $(f).find(".aab").append('<div class="aabut pa-save-message">Saving…</div>');
        Note.at(elt).save(text, iscomment).then(() => {
            const click_tr = curline ? curline.visible_source() : null;
            if (click_tr) {
                capture(click_tr.element, true);
            }
        });
    }
}

function cancel() {
    unedit(Note.closest(this).cancel_edit());
    return true;
}

function textarea_keydown(evt) {
    if (event_key(evt) === "Escape" && !event_modkey(evt) && unedit(Note.closest(this))) {
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
        || hasClass(dl, "pa-gx")
        || event.target.matches("button, a, textarea")) {
        return;
    }
    var line = locate(event.target),
        t = new Date().getTime();
    if (event.type === "mousedown" && line) {
        if (curline
            && curline.element === line.element
            && down_event
            && nearby(down_event[0] - event.clientX, down_event[1] - event.clientY)
            && t - down_event[2] <= 500) {
            // skip
        } else {
            curline = line;
            down_event = [event.clientX, event.clientY, t, false];
        }
    } else if (event.type === "mouseup" && line) {
        if (curline
            && curline.element === line.element
            && down_event
            && nearby(down_event[0] - event.clientX, down_event[1] - event.clientY)
            && !down_event[3]) {
            curline = line;
            down_event[3] = true;
            make_linenote(event);
        }
    } else if (event.type === "click" && line) {
        curline = line;
        make_linenote(event);
    } else {
        curline = down_event = null;
    }
}

function make_linenote(event) {
    const note = Note.near(curline), tr = note.force_element();
    set_scrolled_at(event);
    if (hasClass(tr, "editing")) {
        if (unedit(note)) {
            event && event.stopPropagation();
            return true;
        } else {
            var $ta = $(tr).find("textarea").focus();
            $ta[0].setSelectionRange && $ta[0].setSelectionRange(0, $ta.val().length);
            return false;
        }
    } else {
        render_form($(tr), note, true);
        capture(curline.element, false);
        return false;
    }
}

handle_ui.on("pa-editablenotes", pa_linenote);
