// note-edit.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { escape_entities, regexp_quote } from "./encoders.js";
import { hoturl } from "./hoturl.js";
import { hasClass, addClass, removeClass, handle_ui } from "./ui.js";
import { event_key } from "./ui-key.js";
import { Linediff } from "./diff.js";
import { Note } from "./note.js";
import { GradeSheet } from "./gradeentry.js";
import { ftext, render_onto } from "./render.js";
import { tooltip } from "./tooltip.js";


let curline, curgrade, down_event, scrolled_x, scrolled_y, scrolled_at;

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
    $tr[0].hidden = false;
    $tr.addClass("editing");
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
        '<div class="aabut"><button type="button" class="btn ui pa-load-note-suggestions">↡</button></div>');
    if (!gi.scores_visible) {
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
    if ((evt.type === "mousemove"
         && scrolled_at
         && (evt.timeStamp - scrolled_at <= 500
             || (Math.abs(evt.screenX - scrolled_x) <= 1
                 && Math.abs(evt.screenY - scrolled_y) <= 1)))
        || ((evt.type === "keydown" || evt.type === "keyup")
            && event_key.is_modifier(evt))) {
        return;
    }

    if (evt.type !== "keydown") {
        uncapture();
        return;
    }

    const key = event_key(evt), modkey = event_key.modcode(evt);
    if ((key === "ArrowUp" || key === "ArrowDown") && !modkey) {
        arrowcapture_arrow(evt, key);
    } else if (key === "Enter" && (!modkey || modkey === event_key.META)) {
        arrowcapture_enter(evt);
    } else if (!curgrade) {
        uncapture();
    }
}

function arrowcapture_focusat(what, evt) {
    if (what.nodeName === "PA-LINEDIFF") {
        curline = what;
        curgrade = null;
        what = what.element;
        addClass(what, "live");
        what.tabIndex = 0;
    } else {
        curline = null;
        curgrade = what;
        requestAnimationFrame(() => what.select());
    }
    what.focus();
    const wf = what.closest(".pa-with-fixed");
    $(wf).scrollIntoView(wf ? {marginTop: wf.firstChild.offsetHeight} : null);
    evt.preventDefault();
}

function arrowcapture_arrow(evt, key) {
    let ln = curline && curline.visible_source();
    if (ln || curgrade) {
        let start = curgrade || ln.element;
        if (ln) {
            removeClass(ln.element, "live");
            ln.element.tabIndex = -1;
        }
        ln = null;
        const flags = Linediff.ANYFILE + (key === "ArrowDown" ? 0 : Linediff.BACKWARD) + Linediff.GRADES;
        for (let lnx of Linediff.all(start, flags)) {
            if (lnx.nodeName !== "PA-LINEDIFF") {
                arrowcapture_focusat(lnx, evt);
                return;
            } else if (lnx.element !== start && lnx.is_visible() && lnx.is_source()) {
                ln = lnx;
                break;
            }
        }
    }
    if (ln) {
        arrowcapture_focusat(ln, evt);
    } else {
        uncapture();
    }
}

function arrowcapture_enter(evt) {
    let ln = curline && curline.visible_source();
    if (!ln) {
        uncapture();
        return;
    }
    curline = ln;
    curgrade = null;
    evt.preventDefault();
    set_scrolled_at(evt);
    make_linenote();
}

function capture(tr, keydown) {
    if (!hasClass(tr, "pa-gw")) {
        addClass(tr, "live");
        tr.tabIndex = 0;
        tr.focus();
    }
    $(".pa-filediff").removeClass("live");
    $(document).off(".pa-linenote");
    $(document).on((keydown ? "keydown.pa-linenote " : "") + "mousemove.pa-linenote mousedown.pa-linenote", arrowcapture);
}

function uncapture() {
    for (const tr of document.querySelectorAll(".pa-dl.live")) {
        removeClass(tr, "live");
        tr.tabIndex = -1;
    }
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
            const ctr = curline ? curline.visible_source() : null;
            ctr && capture(ctr.element, true);
        });
    }
}

function cancel() {
    unedit(Note.closest(this).cancel_edit());
    return true;
}

function textarea_keydown(evt) {
    if (event_key(evt) === "Escape" && !event_key.modcode(evt) && unedit(Note.closest(this))) {
        return false;
    } else if (event_key(evt) === "Enter" && event_key.modcode(evt) === event_key.META) {
        $(this).closest("form").submit();
        return false;
    }
    return true;
}

function nearby(dx, dy) {
    return (dx * dx) + (dy * dy) < 144;
}

function pa_linenote(event) {
    var dl = event.target.closest(".pa-dl");
    if (event.button !== 0
        || !dl
        || hasClass(dl, "pa-gx")
        || event.target.matches("button, a, textarea, input, label")
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
            curgrade = null;
            down_event = [event.clientX, event.clientY, t, false];
        }
    } else if (event.type === "mouseup" && line) {
        if (curline
            && curline.element === line.element
            && down_event
            && nearby(down_event[0] - event.clientX, down_event[1] - event.clientY)
            && !down_event[3]) {
            curline = line;
            curgrade = null;
            down_event[3] = true;
            make_linenote(event);
        }
    } else if (event.type === "click" && line) {
        curline = line;
        curgrade = null;
        make_linenote(event);
    } else {
        curline = null;
        curgrade = null;
        down_event = null;
    }
}

function make_linenote(event) {
    const note = Note.near(curline), tr = note.force_element();
    set_scrolled_at(event);
    if (hasClass(tr, "editing")) {
        if (unedit(note)) {
            event && event.stopPropagation();
            return true;
        }
        const $ta = $(tr).find("textarea").focus();
        $ta[0].setSelectionRange && $ta[0].setSelectionRange(0, $ta.val().length);
        return false;
    }
    capture(curline.element, false);
    render_form($(tr), note, true);
    return false;
}

handle_ui.on("pa-editablenotes", pa_linenote);


function my_note_feedback(ln) {
    const uid = siteinfo.user.cid;
    if (ln.like && ln.like.indexOf(uid) >= 0) {
        return 1;
    } else if (ln.dislike && ln.dislike.indexOf(uid) >= 0) {
        return -1;
    }
    return 0;
}

function display_note_suggestions(form, ns) {
    let suggctr = form.querySelector(".pa-note-suggestions"),
        searchre;
    if (!suggctr) {
        const aa = form.querySelector(".pa-note-aa"),
            search = document.createElement("input");
        search.type = "search";
        search.className = "uikd uii pa-search-suggestions mt-4 mb-3";
        search.placeholder = "Search…";
        suggctr = document.createElement("div");
        suggctr.className = "pa-note-suggestions";
        aa.before(search, suggctr);
    } else {
        const search = form.querySelector(".pa-search-suggestions");
        searchre = search_suggestions_re(search ? search.value : "");
    }
    const known = new Set;
    for (let ch = suggctr.firstChild; ch; ch = ch.nextSibling) {
        known.add(ch.getAttribute("data-content"));
    }
    for (const n of ns) {
        if (known.has(n.ftext)) {
            continue;
        }
        const mf = my_note_feedback(n),
            li = document.createElement("div"),
            gf = (n.like || []).length - (n.dislike || []).length;
        li.className = "pa-note-suggestion".concat(mf < 0 || (mf === 0 && gf < 0) ? " dim" : "");
        li.setAttribute("data-content", n.ftext);
        if (searchre && !searchre.test(n.ftext)) {
            li.hidden = true;
        }
        suggctr.appendChild(li);
        const bbox = document.createElement("div"),
            b1 = document.createElement("button"),
            b2 = document.createElement("button"),
            tx = document.createElement("div");
        bbox.className = "btnbox small";
        bbox.append(b1, b2);
        tx.className = "flex-grow-1";
        li.append(bbox, tx);
        b1.type = b2.type = "button";
        b1.className = "ui pa-use-suggestion like".concat(mf > 0 ? " taken" : "");
        b1.setAttribute("aria-label", "Use");
        b1.textContent = "✔️";
        b2.className = "ui pa-use-suggestion dislike".concat(mf < 0 ? " taken" : "");
        b2.setAttribute("aria-label", "Downrank");
        b2.textContent = "➖";
        render_onto(tx, "f", n.ftext);
        tooltip.call(b1);
        tooltip.call(b2);
    }
}

function my_note_compare(a, b) {
    if (a.status !== b.status) {
        return a.status > b.status ? -1 : 1;
    } else if (a.ftext.substring(0, 3) === b.ftext.substring(0, 3)) {
        return a.ftext.localeCompare(b.ftext);
    }
    const af = ftext.parse(a.ftext), bf = ftext.parse(b.ftext);
    return af.localeString(bf);
}

function note_suggestions(form, suggdata) {
    display_note_suggestions(form, suggdata.notelist);
    $(form).find(".pa-load-note-suggestions").prop("disabled", !suggdata.more);
}

handle_ui.on("pa-load-note-suggestions", function () {
    const form = this.closest("form");
    let suggdata = $(form).data("paNoteSuggestions");
    if (suggdata && !suggdata.more) {
        note_suggestions(form, suggdata);
        return;
    }
    const ld = Linediff.closest(this),
        args = {file: ld.file, pset: ld.pset},
        gi = GradeSheet.closest(this);
    if (!gi || !gi.base_commit || gi.base_handout) {
        args.linea = ld.linea;
    } else if (gi.user && gi.commit) {
        let x = ld.lineb;
        if (x !== null) {
            args.u = gi.user;
            args.line = "b" + x;
            args.commit = gi.commit;
        }
    }
    if (!suggdata) {
        args.neighborhood = 5;
        args.my_neighborhood = 20;
    } else if (suggdata.neighborhood >= 0 && suggdata.neighborhood <= 5) {
        args.neighborhood = 20;
        args.my_neighborhood = -1;
    } else {
        args.neighborhood = -1;
    }
    this.disabled = true;
    $.ajax(hoturl("api/linenotesuggest", args), {
        success: function (data) {
            if (!data.ok) {
                return;
            }
            data.notelist = data.notelist || [];
            for (const note of data.notelist) {
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
            data.notelist.sort(my_note_compare);
            $(form).data("paNoteSuggestions", data);
            note_suggestions(form, data);
        }
    });
});

function linenotemark(context, mark) {
    const ld = Linediff.closest(context);
    $.ajax(hoturl("=api/linenotemark", {file: ld.file, linea: ld.linea, mark: mark, pset: ld.pset}),
        { data: { ftext: context.getAttribute("data-content") }, method: "POST" });
}

handle_ui.on("pa-use-suggestion", function () {
    const e = this.closest(".pa-note-suggestion"),
        f = e.closest("form");
    if (this.classList.contains("like")) {
        linenotemark(e, "like");
        f.elements.note.value = ftext.parse(e.getAttribute("data-content"))[1];
    } else {
        linenotemark(e, "dislike");
        e.remove();
    }
});

function search_suggestions_re(value) {
    let patterns = "";
    for (const str of value.split(/\s+/)) {
        str !== "" && (patterns = patterns.concat("(?=.*", regexp_quote(str), ")"));
    }
    return patterns ? new RegExp(patterns, "i") : null;
}

handle_ui.on("pa-search-suggestions", function (event) {
    if (event.type === "input") {
        const el = this.nextSibling, regex = search_suggestions_re(this.value);
        for (let ne = el.firstChild; ne; ne = ne.nextSibling) {
            const hidden = regex && !regex.test(ne.getAttribute("data-content"));
            ne.hidden = !!hidden;
        }
    } else if (event.type === "keydown"
               && !(event_key.modcode(event) & (event_key.SHIFT | event_key.ALT))
               && event_key(event) === "Enter") {
        event.preventDefault();
    }
});
