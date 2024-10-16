// popup.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { addClass, hasClass, removeClass, form_differs, $e } from "./ui.js";
import { event_key } from "./ui-key.js";
import { tooltip } from "./tooltip.js";
import { feedback } from "./render.js";


export function $popup(options) {
    options = options || {};
    const near = options.near || options.anchor || window,
        forme = $e("form", {enctype: "multipart/form-data", "accept-charset": "UTF-8", class: options.form_class || null}),
        modale = $e("div", {class: "modal", role: "dialog", hidden: true},
            $e("div", {class: "modal-dialog".concat(near === window ? " modal-dialog-centered" : "", options.className ? " " + options.className : ""), role: "document"},
                $e("div", "modal-content", forme)));
    if (options.action) {
        if (options.action instanceof HTMLFormElement) {
            forme.setAttribute("action", options.action.action);
            forme.setAttribute("method", options.action.method);
        } else {
            forme.setAttribute("action", options.action);
            forme.setAttribute("method", options.method || "post");
        }
        if (forme.getAttribute("method") === "post"
            && !/post=/.test(forme.getAttribute("action"))
            && !/^(?:[a-z][-a-z0-9+.]*:|\/\/)/i.test(forme.getAttribute("action"))) {
            forme.prepend($e("input", {type: "hidden", name: "post", value: siteinfo.postvalue}));
        }
    }
    for (const k of ["minWidth", "maxWidth", "width"]) {
        if (options[k] != null)
            $(modale.firstChild).css(k, options[k]);
    }
    $(modale).on("click", dialog_click);
    document.body.appendChild(modale);
    document.body.addEventListener("keydown", dialog_keydown);
    let prior_focus, actionse;

    function show_errors(data, filter_fields) {
        $(forme).find(".msg-error, .feedback, .feedback-list").remove();
        const gmlist = [];
        for (const mi of data.message_list || []) {
            const e = mi.field && forme.elements[mi.field];
            if (e ? !feedback.append_item_near(e, mi) : !filter_fields || !mi.field) {
                gmlist.push(mi);
            }
        }
        if (gmlist.length) {
            $(forme).find("h2").after(feedback.render_alert(gmlist));
        }
    }
    function close() {
        removeClass(document.body, "modal-open");
        document.body.removeEventListener("keydown", dialog_keydown);
        if (document.activeElement
            && modale.contains(document.activeElement)) {
            document.activeElement.blur();
        }
        $pa.tooltip.close();
        $(modale).find("textarea, input").unautogrow();
        $(forme).trigger("closedialog");
        modale.remove();
        if (prior_focus) {
            prior_focus.focus({preventScroll: true});
        }
    }
    function dialog_click(evt) {
        if (evt.button === 0
            && ((evt.target === modale && !form_differs(forme))
                || (evt.target.nodeName === "BUTTON" && evt.target.name === "cancel"))) {
            close();
        }
    }
    function dialog_keydown(evt) {
        if (event_key(evt) === "Escape"
            && event_key.modcode(evt) === 0
            && !hasClass(modale, "hidden")
            && !form_differs(forme)) {
            close();
            evt.preventDefault();
        }
    }
    const self = {
        show: function () {
            const e = document.activeElement;
            $(modale).awaken();
            popup_near(modale, near);
            if (e && document.activeElement !== e) {
                prior_focus = e;
            }
            $pa.tooltip.close();
            // XXX also close down suggestions
            return self;
        },
        append: function (...es) {
            for (const e of es) {
                if (e != null) {
                    forme.append(e);
                }
            }
            return self;
        },
        append_actions: function (...actions) {
            if (!actionse) {
                forme.appendChild((actionse = $e("div", "popup-actions")));
            }
            for (const e of actions) {
                if (e === "Cancel") {
                    actionse.append($e("button", {type: "button", name: "cancel"}, "Cancel"));
                } else if (e != null) {
                    actionse.append(e);
                }
            }
            return self;
        },
        on: function (...args) {
            $(forme).on(...args);
            return self;
        },
        find: function (selector) {
            return $(modale).find(selector);
        },
        querySelector: function (selector) {
            return forme.querySelector(selector);
        },
        querySelectorAll: function (selector) {
            return forme.querySelectorAll(selector);
        },
        form: function () {
            return forme;
        },
        show_errors: show_errors,
        close: close
    };
    return self;
}

// differences and focusing
function focus_at(felt) {
    felt.jquery && (felt = felt[0]);
    felt.focus();
    if (!felt.hotcrp_ever_focused) {
        if (felt.select && hasClass(felt, "want-select")) {
            felt.select();
        } else if (felt.setSelectionRange) {
            try {
                felt.setSelectionRange(felt.value.length, felt.value.length);
            } catch { // ignore errors
            }
        }
        felt.hotcrp_ever_focused = true;
    }
}

function popup_near(elt, anchor) {
    tooltip.close();
    if (elt.jquery) {
        elt = elt[0];
    }
    while (!hasClass(elt, "modal-dialog")) {
        elt = elt.childNodes[0];
    }
    const bgelt = elt.parentNode;
    bgelt.hidden = false;
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
    queueMicrotask(function () {
        for (const e of elt.querySelectorAll(".want-focus")) {
            if (e.offsetWidth > 0) {
                focus_at(e);
                return;
            }
        }
        for (const e of elt.querySelector("form").elements) {
            if (e.type !== "hidden"
                && !hasClass(e, "btn-danger")
                && !hasClass(e, "no-focus")
                && e.offsetWidth > 0) {
                focus_at(e);
                return;
            }
        }
    });
}
