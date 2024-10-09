// pinnable.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, removeClass, $e, handle_ui } from "./ui.js";
import { GradeSheet } from "./gradeentry.js";
import { render_onto } from "./render.js";

const pinmap = new WeakMap;
let dragging, drag_dx, drag_dy;

export function resolve_pinnable() {
    removeClass(this, "pinnable");
    let e = this;
    if (e.tagName === "CODE" && e.parentElement.tagName === "PRE") {
        e = e.parentElement;
    }
    if (!e.closest(".pa-gsection")) {
        return;
    }
    const d = $e("div", "pa-pinnable");
    e.parentElement.replaceChild(d, e);
    d.append(e, $e("button", {type: "button", class: "ui pa-pinnable-pin"}, "📌"));
};

function dragstart(evt) {
    dragging = this;
    const br = dragging.getBoundingClientRect();
    drag_dx = evt.clientX - br.x;
    drag_dy = evt.clientY - br.y;
    evt.dataTransfer.dropEffect = "move";
    evt.dataTransfer.effectAllowed = "move";
    evt.dataTransfer.setDragImage(this, drag_dx, drag_dy);
    document.body.addEventListener("dragover", dragover);
    document.body.addEventListener("drop", drop);
}

function dragover(evt) {
    evt.preventDefault();
}

function drop(evt) {
    dragging.style.left = (evt.clientX - drag_dx) + "px";
    dragging.style.top = (evt.clientY - drag_dy) + "px";
}

function dragend(evt) {
    document.body.removeEventListener("dragover", dragover);
    document.body.removeEventListener("drop", drop);
}

handle_ui.on("pa-pinnable-pin", function (evt) {
    const e = this.parentElement;
    if (hasClass(e, "pinned")) {
        // unpin: reveal normal element, remove this element
        removeClass(e, "pinned");
        const orig = pinmap.get(e);
        if (orig) {
            orig.style.visibility = "";
        }
        e.closest(".pa-pinnable-fixed").remove();
        pinmap.delete(e);
        return
    }

    // pin: copy this element
    const ecopy = e.cloneNode(true);
    addClass(ecopy, "pinned");
    let par = e.parentElement, pine = ecopy;
    while (!hasClass(par, "pa-gsection")) {
        for (let k of par.classList) {
            if (k === "pa-pdesc" || k === "pa-dr" || k.startsWith("format")) {
                if (pine === ecopy) {
                    pine = $e("div", null, ecopy);
                }
                addClass(pine, k);
            }
        }
        par = par.parentElement;
    }
    const br = e.getBoundingClientRect();
    //pine.style.position = "sticky";
    //pine.style.top = (br.y - par.getBoundingClientRect().y) + "px";
    pine.style.position = "fixed";
    pine.style.top = br.y + "px";
    pine.style.left = br.x + "px";
    pine.style.width = br.width + "px";
    pine.style.height = br.height + "px";
    pine.draggable = true;
    pine.addEventListener("dragstart", dragstart);
    pine.addEventListener("dragend", dragend);
    addClass(pine, "pa-pinnable-fixed");
    par.appendChild(pine);
    e.style.visibility = "hidden";
    pinmap.set(ecopy, e);

    // append title
    if (par.hasAttribute("data-pa-grade")) {
        const gs = GradeSheet.closest(e),
            ge = gs ? gs.xentry(par.getAttribute("data-pa-grade")) : null;
        if (ge && ge.title) {
            const s = ge.title.replace(/\s*\([\d.]+ points?\)\s*$/, ""),
                te = $e("div", "pa-pinnable-title");
            render_onto(te, "f", s);
            ecopy.append(te);
        }
    }
});
