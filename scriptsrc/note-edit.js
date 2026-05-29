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
    } else if ((key === "PageUp" || key === "PageDown") && !modkey) {
        arrowcapture_page(evt, key);
    } else if (key === "Enter" && (!modkey || modkey === event_key.META)) {
        arrowcapture_enter(evt);
    }
}

function arrowcapture_setfocus(what) {
    const ln = curline && curline.visible_source();
    if (ln) {
        removeClass(ln.element, "live");
        ln.element.tabIndex = -1;
    }
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
    what.focus({preventScroll: true});
    return what;
}

function arrowcapture_focusat(what, evt) {
    what = arrowcapture_setfocus(what);
    const wf = what.closest(".pa-filediff-ctr"),
        marginTop = wf && wf.firstChild.nodeName === "H3" ? wf.firstChild.offsetHeight : 0;
    $(what).scrollIntoView({marginTop: marginTop, atCenter: true});
    evt.preventDefault();
}

function arrowcapture_arrow(evt, key) {
    const ln = curline && curline.visible_source();
    if (!ln && !curgrade) {
        uncapture();
        return;
    }
    const start = curgrade || ln.element,
        flags = Linediff.ANYFILE + (key === "ArrowDown" ? 0 : Linediff.BACKWARD) + Linediff.GRADES;
    let target = null;
    for (let lnx of Linediff.all(start, flags)) {
        if (lnx.nodeName !== "PA-LINEDIFF") {
            target = lnx;
            break;
        } else if (lnx.element !== start && lnx.is_visible() && lnx.is_source()) {
            target = lnx;
            break;
        }
    }
    if (target) {
        arrowcapture_focusat(target, evt);
    } else {
        // already at the first/last navigable line; keep focus where it is
        evt.preventDefault();
    }
}

function scroll_parent(e) {
    let x = e.parentNode;
    while (x && x.tagName && $(x).css("overflow-y") === "visible") {
        x = x.parentNode;
    }
    return x && x.tagName ? x : window;
}

function arrowcapture_page(evt, key) {
    const ln = curline && curline.visible_source();
    if (!ln && !curgrade) {
        uncapture();
        return;
    }
    evt.preventDefault();
    const start = curgrade || ln.element,
        down = key === "PageDown",
        x = scroll_parent(start),
        win = x === window;
    // record the cursor's current screen position so it stays put as text scrolls
    const sr = start.getBoundingClientRect(),
        anchor = sr.top + sr.height / 2;
    // viewport geometry and current/max scroll
    let vheight, curscroll, maxscroll;
    if (win) {
        vheight = window.innerHeight;
        curscroll = window.scrollY;
        maxscroll = document.documentElement.scrollHeight - vheight;
    } else {
        vheight = x.clientHeight;
        curscroll = x.scrollTop;
        maxscroll = x.scrollHeight - x.clientHeight;
    }
    const wf = start.closest(".pa-filediff-ctr"),
        marginTop = wf && wf.firstChild.nodeName === "H3" ? wf.firstChild.offsetHeight : 0,
        goal = (vheight - marginTop) * (down ? 1 : -1); // screen distance to advance
    // Find the navigable line about one screenful away, measuring each line's
    // screen offset from the cursor. Picking the line first (rather than
    // scrolling a fixed amount and snapping) keeps the highlight from drifting:
    // we scroll by exactly the offset to that line, so it lands back on `anchor`.
    const flags = Linediff.ANYFILE + Linediff.GRADES + (down ? 0 : Linediff.BACKWARD);
    let target = null, targetdelta = 0, bestdist = Infinity;
    for (let lnx of Linediff.all(start, flags)) {
        let el;
        if (lnx.nodeName !== "PA-LINEDIFF") {
            el = lnx;
        } else if (lnx.element !== start && lnx.is_visible() && lnx.is_source()) {
            el = lnx.element;
        } else {
            continue;
        }
        const r = el.getBoundingClientRect(),
            delta = r.top + r.height / 2 - anchor,
            dist = Math.abs(delta - goal);
        if (dist <= bestdist) {
            bestdist = dist;
            target = lnx;
            targetdelta = delta;
        } else {
            break; // |delta - goal| only grows from here
        }
    }
    if (!target) {
        return; // already at the first/last navigable line
    }
    const pos = Math.max(0, Math.min(curscroll + targetdelta, maxscroll));
    if (win) {
        window.scrollTo(window.scrollX, pos);
    } else {
        x.scrollTop = pos;
    }
    arrowcapture_setfocus(target);
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

function line_at_viewport_fraction(frac, vh) {
    const vw = window.innerWidth || document.documentElement.clientWidth,
        y = Math.round(vh * frac);
    for (const fx of [0.5, 0.35, 0.65, 0.2, 0.8]) {
        const el = document.elementFromPoint(Math.round(vw * fx), y),
            dl = el ? el.closest(".pa-dl") : null;
        if (dl) {
            const ln = new Linediff(dl);
            if (ln.is_visible()) {
                return ln;
            }
        }
    }
    return null;
}

function adjacent_visible_source(ln, backward) {
    for (const lnx of Linediff.all(ln, backward ? Linediff.BACKWARD : 0)) {
        if (lnx.element !== ln.element && lnx.is_visible() && lnx.is_source()) {
            return lnx;
        }
    }
    return null;
}

function make_live(ln) {
    const cur = document.querySelector(".pa-dl.live");
    if (cur && cur !== ln.element) {
        removeClass(cur, "live");
        cur.tabIndex = -1;
    }
    curline = ln;
    curgrade = null;
    addClass(ln.element, "live");
    ln.element.tabIndex = 0;
    ln.element.focus({preventScroll: true});
}

// Capture the current logical scroll position and return a closure that
// restores it. Use around diff display toggles (markdown, hide-left, comments),
// which show or hide lines and change line heights, making the viewport jump
// when content above the fold changes size: call `active_scroll_anchor()`
// before the toggle and invoke the returned closure afterward. The anchor is
// the live line if one is on screen, otherwise the line about 15% down the
// viewport. If the toggle hides the anchor line, the closure re-anchors to the
// nearest following visible line — transferring liveness to it if the anchor
// was live.
export function active_scroll_anchor() {
    const vh = window.innerHeight || document.documentElement.clientHeight;

    let anchor = null, anchor_is_live = false;
    const liveel = document.querySelector(".pa-dl.live");
    if (liveel) {
        const r = liveel.getBoundingClientRect();
        if (r.top < vh && r.bottom > 0) {
            anchor = new Linediff(liveel);
            anchor_is_live = true;
        }
    }
    if (!anchor) {
        anchor = line_at_viewport_fraction(0.15, vh);
    }
    const anchor_top = anchor ? anchor.element.getBoundingClientRect().top : 0;

    return function () {
        if (!anchor) {
            return;
        }
        let target = anchor;
        if (!anchor.is_visible()) {
            const next = adjacent_visible_source(anchor, false);
            if (next) {
                target = next;
                anchor_is_live && make_live(next);
            } else {
                target = adjacent_visible_source(anchor, true);
            }
        }
        if (target && target.is_visible()) {
            const delta = Math.round(target.element.getBoundingClientRect().top - anchor_top);
            delta && window.scrollBy(0, delta);
        }
    };
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
