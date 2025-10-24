// pinnable.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, removeClass, $e, handle_ui } from "./ui.js";
import { GradeSheet } from "./gradeentry.js";
import { render_onto } from "./render.js";

const pinmap = new WeakMap;
let dragging, drag_rect, drag_x1, drag_y1, resizing;

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
    for (const attr of this.attributes) {
        if (attr.name === "title" || attr.name.startsWith("data-pa-"))
            d.setAttribute(attr.name, attr.value);
    }
    e.parentElement.replaceChild(d, e);
    d.append(e, $e("button", {type: "button", class: "ui pa-pinnable-pin"}, "📌"));
};

function dragstart(evt) {
    dragging = this;
    drag_rect = dragging.getBoundingClientRect();
    drag_x1 = evt.clientX;
    drag_y1 = evt.clientY;
    evt.dataTransfer.dropEffect = "move";
    evt.dataTransfer.effectAllowed = "move";
    evt.dataTransfer.setData("application/x-pa-dragging-pinnable", "N/A");
    evt.dataTransfer.setDragImage(this, drag_x1 - drag_rect.x, drag_y1 - drag_rect.y);
    document.body.addEventListener("dragover", dragover);
    document.body.addEventListener("drop", drop);
}

function dragover(evt) {
    evt.preventDefault();
}

function drop(evt) {
    dragging.style.left = (evt.clientX - drag_x1 + drag_rect.x) + "px";
    dragging.style.top = (evt.clientY - drag_y1 + drag_rect.y) + "px";
}

function dragend() {
    document.body.removeEventListener("dragover", dragover);
    document.body.removeEventListener("drop", drop);
}

function resizestart(evt) {
    dragging = this.parentElement;
    resizing = this.getAttribute("data-pa-resize");
    drag_rect = dragging.getBoundingClientRect();
    drag_x1 = evt.clientX;
    drag_y1 = evt.clientY;
    evt.dataTransfer.dropEffect = "move";
    evt.dataTransfer.effectAllowed = "move";
    evt.dataTransfer.setData("application/x-pa-dragging-pinnable", "N/A");
    evt.dataTransfer.setDragImage(this, drag_x1 - drag_rect.x, drag_y1 - drag_rect.y);
    document.body.addEventListener("dragover", resizeover);
    evt.stopImmediatePropagation();
}

function resizeover(evt) {
    evt.preventDefault();
    if (resizing === "l") {
        const left = Math.min(drag_rect.x + evt.clientX - drag_x1, drag_rect.right - 20);
        dragging.style.left = left + "px";
        dragging.style.width = (drag_rect.right - left) + "px";
    } else if (resizing === "r") {
        const right = Math.max(drag_rect.right + evt.clientX - drag_x1, drag_rect.x + 20);
        dragging.style.width = (right - drag_rect.x) + "px";
    } else if (resizing === "t") {
        const top = Math.min(drag_rect.y + evt.clientY - drag_y1, drag_rect.bottom - 40);
        dragging.style.top = top + "px";
        dragging.style.height = (drag_rect.bottom - top) + "px";
    } else if (resizing === "b") {
        const bottom = Math.max(drag_rect.bottom + evt.clientY - drag_y1, drag_rect.y + 40);
        dragging.style.height = (bottom - drag_rect.y) + "px";
    }
}

function resizeend() {
    document.body.removeEventListener("dragover", resizeover);
}

function unpin(e) {
    // unpin: reveal normal element, remove this element
    removeClass(e, "pinned");
    const orig = pinmap.get(e);
    if (orig) {
        orig.style.opacity = "";
    }
    e.closest(".pa-pinnable-fixed").remove();
    pinmap.delete(e);
}

handle_ui.on("pa-pinnable-pin", function () {
    const e = this.parentElement;
    if (hasClass(e, "pinned")) {
        unpin(e);
        return;
    }

    // pin: copy this element
    const ecopy = e.cloneNode(true);
    addClass(ecopy, "pinned");
    for (const img of ecopy.querySelectorAll("img")) {
        img.draggable = false;
    }
    let par = e.parentElement, pag = par, pine = $e("div", "pa-pinned", ecopy);
    while (!hasClass(par, "pa-gsection")) {
        for (let k of par.classList) {
            if (k === "pa-pdesc" || k === "pa-dr" || k.startsWith("format")) {
                addClass(pine, k);
            } else if (k === "pa-grade") {
                pag = par;
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
    pine.addEventListener("dblclick", () => unpin(ecopy));
    addClass(pine, "pa-pinnable-fixed");
    par.appendChild(pine);
    e.style.opacity = 0.2;
    pinmap.set(ecopy, e);

    // append title
    let t = "";
    if (par.hasAttribute("data-pa-grade")) {
        const gs = GradeSheet.closest(e),
            sge = gs ? gs.xentry(par.getAttribute("data-pa-grade")) : null,
            gge = gs && pag !== par ? gs.xentry(pag.getAttribute("data-pa-grade")) : null;
        if (sge && sge.title) {
            t = sge.title.replace(/\s*\([\d.]+ points?\)\s*$/, "");
        }
        if (gge && gge.title && !e.getAttribute("title")) {
            t += (t ? " → " : "") + gge.title.replace(/\s*\([\d.]+ points?\)\s*$/, "");
        }
    }
    if (e.getAttribute("title")) {
        t += (t ? " → " : "") + e.getAttribute("title");
    }
    if (t !== "") {
        const te = $e("div", "pa-pinnable-title");
        render_onto(te, "f", t);
        pine.append(te);
        const tebr = te.getBoundingClientRect();
        pine.style.height = (br.height + tebr.height) + "px";
    }

    for (const k of ["l", "r", "t", "b"]) {
        const drage = $e("div", "pa-pinnable-resize-" + k);
        drage.setAttribute("data-pa-resize", k);
        drage.draggable = true;
        pine.append(drage);
        drage.addEventListener("dragstart", resizestart);
        drage.addEventListener("dragend", resizeend);
    }
});
