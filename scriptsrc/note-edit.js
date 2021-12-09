// note-edit.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities } from "./encoders.js";
import { hoturl } from "./hoturl.js";
import { hasClass, addClass, removeClass, handle_ui } from "./ui.js";
import { event_key, event_modkey } from "./ui-key.js";
import { Linediff } from "./diff.js";
import { Note } from "./note.js";
import { GradeSheet } from "./gradeentry.js";
import { ftext } from "./render.js";


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

    let gi = GradeSheet.closest(curline.element),
        format = note ? note.format : null;
    if (format == null) {
        format = document.body.getAttribute("data-default-format");
    }
    let t = '<form method="post" action="'.concat(
        escape_entities(hoturl("=api/linenote", {psetinfo: gi.element, file: curline.file, line: curline.note_lineid, oldversion: (note && note.version) || 0, format: format})),
        '" enctype="multipart/form-data" accept-charset="UTF-8" class="ui-submit pa-noteform">',
        '<textarea class="pa-note-entry need-autogrow" name="note"></textarea>',
        '<div class="aab aabr pa-note-aa justify-content-between">',
        '<div class="aabutr order-100"><button class="btn-primary" type="submit">Save comment</button></div>',
        '<div class="aabutr order-99"><button type="button" name="cancel">Cancel</button></div>',
        '<div class="aabut"><button type="button" class="btn-xlink ui pa-load-note-suggestions">↡</button></div>');
    if (!gi.user_scores_visible) {
        t += '<div class="aabut"><label class="checki"><input type="checkbox" name="iscomment" value="1" class="checkc">Show immediately</label></div>';
    }
    t += '<div class="aabut flex-grow-1"></div>';
    var $form = $(t + '</div></form>').appendTo($td);

    var $ta = $form.find("textarea");
    if (note && note.ftext !== null) {
        const text = note.editable_text;
        $ta.text(text);
        $ta[0].setSelectionRange && $ta[0].setSelectionRange(text.length, text.length);
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
        Note.at(elt).save_text(text, iscomment).then(() => {
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
        || event.target.matches("button, a, textarea")
        || event.target.closest(".pa-note-suggestions")) {
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


function display_note_suggestions(form, ns, clear) {
    let $f = $(form).find(".pa-note-suggestions");
    if (!$f.length) {
        $f = $('<div class="pa-note-suggestions mt-4"></div>').insertBefore($(form).find(".pa-note-aa"));
    } else if (clear) {
        $f.empty();
    }
    console.log($f[0]);
    for (const n of ns) {
        const li = document.createElement("div");
        li.className = "pa-note-suggestion";
        li.setAttribute("data-content", n.ftext);
        $f[0].appendChild(li);
        const bbox = document.createElement("div"),
            b1 = document.createElement("button"),
            b2 = document.createElement("button"),
            tx = document.createElement("div");
        bbox.className = "btnbox small";
        bbox.append(b1, b2);
        tx.className = "flex-grow-1";
        li.append(bbox, tx);
        b1.type = b2.type = "button";
        b1.className = "ui pa-accept-suggestion";
        b2.className = "ui pa-reject-suggestion";
        b1.textContent = "✅";
        b2.textContent = "❌";
        ftext.render(n.ftext, tx);
    }
}

function my_note_feedback(ln) {
    const uid = siteinfo.user.cid;
    if (ln.like && ln.like.indexOf(uid) >= 0) {
        return 1;
    } else if (ln.dislike && ln.dislike.indexOf(uid) >= 0) {
        return -1;
    } else {
        return 0;
    }
}

function my_note_compare(linea) {
    return function (a, b) {
        if (a.status !== b.status) {
            return a.status > b.status ? -1 : 1;
        }
        const adist = Math.abs(linea - a.linea),
            bdist = Math.abs(linea - b.linea);
        if (adist !== bdist) {
            return adist < bdist ? -1 : 1;
        } else if (a.linea !== b.linea) {
            return a.linea < b.linea ? -1 : 1;
        } else {
            return 0;
        }
    };
}

function note_suggestions(form, notelist) {
    let oldstatus = $(form).data("paNoteSuggestionStatus"), newstatus;
    if (oldstatus === null) {
        oldstatus = 4;
        newstatus = 2;
    } else {
        newstatus = oldstatus - 1;
    }
    let oldindex = 0, newindex = 0;
    for (const note of notelist) {
        if (note.status >= oldstatus) {
            ++oldindex;
        }
        if (note.status >= newstatus) {
            ++newindex;
        } else if (newindex === oldindex) {
            ++newindex;
            newstatus = note.status;
        }
    }
    console.log([oldindex, newindex]);
    display_note_suggestions(form, notelist.slice(oldindex, newindex), oldstatus === 4);
    $(form).data("paNoteSuggestionStatus", newstatus)
        .find(".pa-load-note-suggestions")
        .prop("disabled", newindex === notelist.length);
}

handle_ui.on("pa-load-note-suggestions", function () {
    const form = this.closest("form");
    let notelist = $(form).data("paNoteSuggestions");
    if (notelist) {
        note_suggestions(form, notelist);
    } else {
        const ld = Linediff.closest(this),
            linea = ld.linea;
        this.disabled = true;
        $.ajax(hoturl("api/linenotesuggest", {file: ld.file, linea: linea, pset: ld.pset}), {
            success: function (data) {
                if (data.ok) {
                    notelist = data.notelist || [];
                    for (const note of notelist) {
                        const mf = my_note_feedback(note),
                            gf = (note.like || []).length - (note.dislike || []).length;
                        if (mf > 0 && gf > 1) {
                            note.status = 3;
                        } else if (mf > 0) {
                            note.status = 2;
                        } else if (mf === 0 && gf > 0) {
                            note.status = 1;
                        } else if (mf === 0 && gf === 0) {
                            note.status = 0;
                        } else if (mf === -1 && gf >= 0) {
                            note.status = -1;
                        } else {
                            note.status = -2;
                        }
                    }
                    notelist.sort(my_note_compare(linea));
                    console.log(notelist);
                    $(form).data("paNoteSuggestions", notelist);
                    note_suggestions(form, notelist);
                }
            }
        });
    }
});

function linenotemark(context, mark) {
    const ld = Linediff.closest(context);
    $.ajax(hoturl("=api/linenotemark", {file: ld.file, linea: ld.linea, mark: mark, pset: ld.pset}),
        { data: { ftext: context.getAttribute("data-content") }, method: "POST" });
}

handle_ui.on("pa-accept-suggestion", function () {
    const e = this.closest(".pa-note-suggestion"),
        f = e.closest("form");
    linenotemark(e, "like");
    f.elements.note.value = ftext.parse(e.getAttribute("data-content"))[1];
});

handle_ui.on("pa-reject-suggestion", function () {
    const e = this.closest(".pa-note-suggestion");
    linenotemark(e, "dislike");
    e.remove();
});
