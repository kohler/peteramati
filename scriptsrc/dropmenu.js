// dropmenu.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { handle_ui, hasClass, removeClass, addClass, $e } from "./ui.js";
import { event_key } from "./ui-key.js";


const dropmenu_builders = {};

function dropmenu_close() {
    const modal = document.getElementById("dropmenu-modal");
    if (modal && modal.previousSibling.matches("details[open]")) {
        modal.previousSibling.open = false;
    } else {
        $("details.dropmenu-details").each(function () { this.open = false; });
    }
    if (modal) {
        modal.remove();
    }
}

function dropmenu_close1(e) {
    if (e && e.open) {
        const modal = document.getElementById("dropmenu-modal");
        modal && modal.remove();
        e.open = false;
    }
}

function dropmenu_clickable(li) {
    if (!li) {
        return null;
    }
    const bs = li.querySelectorAll("button");
    if (bs.length === 1) {
        return bs[0];
    }
    const as = li.querySelectorAll("a");
    if (as.length === 1) {
        return as[0];
    }
    return null;
}

function dropmenu_click(evt) {
    const tgt = evt.target, details = this.closest("details");
    if (tgt.tagName === "A" || tgt.tagName === "BUTTON") {
        if (tgt.classList.contains("dropmenu-close")) {
            queueMicrotask(() => dropmenu_close1(details));
        }
        return;
    }
    if (tgt.closest("ul") !== this) {
        return;
    }
    const ke = dropmenu_clickable(tgt.closest("li"));
    if (!ke) {
        return;
    }
    if (ke.tagName === "A"
        && ke.href
        && evt.type === "click"
        && !event_key.is_default_a(evt)) {
        window.open(ke.href, "_blank", "noopener");
    } else {
        ke.click();
        if (ke.classList.contains("dropmenu-close")) {
            queueMicrotask(() => dropmenu_close1(details));
        }
    }
    evt.preventDefault();
    evt.stopPropagation();
}

function dropmenu_selection(menu) {
    if (!document.activeElement
        || !menu.contains(document.activeElement)) {
        return null;
    }
    return document.activeElement.closest("li");
}

function dropmenu_clickable_starting_with(li, prefix) {
    for (; li; li = li.nextElementSibling) {
        const ke = hasClass(li, "has-link") ? dropmenu_clickable(li) : null;
        if (ke) {
            const s = ke.textContent.normalize("NFKD").replace(/\p{M}/gu, "").toLowerCase();
            if (s.startsWith(prefix)) {
                return ke;
            }
        }
    }
    return null;
}

let dmkey_menu, dmkey_time, dmkey_string;

function dropmenu_keydown(evt) {
    const key = event_key(evt);
    if (key === "Escape") {
        dmkey_menu = null;
        dropmenu_close1(this.closest("details"));
    } else if (key === "Enter" || key === "Space") {
        dmkey_menu = null;
        dropmenu_click.call(this, evt);
        return;
    } else if (key === "ArrowUp" || key === "ArrowDown" || key === "Home" || key == "End") {
        dmkey_menu = null;
        removeClass(this, "dropmenu-hovering");
        const up = key === "ArrowUp" || key === "End";
        let li = key === "Home" || key === "End" ? null : dropmenu_selection(this);
        if (li) {
            li = li[up ? "previousElementSibling" : "nextElementSibling"];
        }
        if (!li) {
            li = this[up ? "lastElementChild" : "firstElementChild"];
        }
        while (li && !hasClass(li, "has-link")) {
            li = li[up ? "previousElementSibling" : "nextElementSibling"];
        }
        const ke = dropmenu_clickable(li);
        ke && ke.focus();
    } else if (event_key.printable(evt) && /^(?:\p{L}|\p{N})$/u.test(key)) {
        if (dmkey_menu !== this || evt.timeStamp - dmkey_time > 500) {
            dmkey_string = "";
        }
        dmkey_menu = this;
        dmkey_time = evt.timeStamp;
        dmkey_string += key;
        const li = dropmenu_selection(this);
        let ke = li ? dropmenu_clickable_starting_with(li.nextElementSibling, dmkey_string) : null;
        ke = ke || dropmenu_clickable_starting_with(this.firstElementChild, dmkey_string);
        if (ke) {
            removeClass(this, "dropmenu-hovering");
            ke.focus();
        }
    } else {
        if (event_key.printable(evt)) {
            dmkey_menu = null;
        }
        return;
    }
    evt.preventDefault();
    evt.stopPropagation();
}

function dropmenu_mousemove() {
    addClass(this, "dropmenu-hovering");
}

function dropmenu_open(menu, details) {
    menu.style.maxHeight = null;
    const maxHeight = parseFloat(window.getComputedStyle(menu).maxHeight),
        drect = details.getBoundingClientRect();
    menu.style.maxHeight = Math.max(Math.min(maxHeight, visualViewport.height - drect.bottom - 24), 120) + "px";

    removeClass(menu, "dropmenu-hovering");

    if (!hasClass(menu, "has-dropmenu-events")) {
        menu.addEventListener("click", dropmenu_click);
        menu.addEventListener("keydown", dropmenu_keydown);
        menu.addEventListener("mousemove", dropmenu_mousemove);
        addClass(menu, "has-dropmenu-events");
    }
}

handle_ui.on("click.js-dropmenu-open", function (evt) {
    let modal = document.getElementById("dropmenu-modal");
    if (hasClass(this, "need-dropmenu")) {
        for (const c of this.classList) {
            if (dropmenu_builders[c])
                dropmenu_builders[c].call(this, evt);
        }
    }
    const summary = this.nodeName === "BUTTON" ? this.closest("summary") : this,
        details = summary.parentElement;
    window.$pa.tooltip.close();
    if (!details.open) {
        if (!modal) {
            modal = $e("div", "modal transparent");
            modal.id = "dropmenu-modal";
            details.parentElement.insertBefore(modal, details.nextSibling);
            modal.addEventListener("click", dropmenu_close, false);
        }
        details.open = true;
        const menu = details.querySelector(".dropmenu");
        menu && dropmenu_open(menu, details);
    } else if (this.tagName === "BUTTON") {
        modal && modal.remove();
        details.open = false;
    }
    evt.preventDefault();
    evt.stopPropagation();
});

export const dropmenu = {
    add_builder: function (s, f) {
        dropmenu_builders[s] = f;
    },
    close: function (e) {
        e ? dropmenu_close1(e.closest("details[open]")) : dropmenu_close();
    }
};
