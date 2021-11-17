// ui.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

export let hasClass, addClass, removeClass, toggleClass, classList;
if ("classList" in document.createElement("span")
    && !/MSIE|rv:11\.0/.test(navigator.userAgent || "")) {
    hasClass = function (e, k) {
        let l = e.classList;
        return l && l.contains(k);
    };
    addClass = function (e, k) {
        e.classList.add(k);
    };
    removeClass = function (e, k) {
        e.classList.remove(k);
    };
    toggleClass = function (e, k, v) {
        e.classList.toggle(k, v);
    };
    classList = function (e) {
        return e.classList;
    };
} else {
    hasClass = function (e, k) {
        return $(e).hasClass(k);
    };
    addClass = function (e, k) {
        $(e).addClass(k);
    };
    removeClass = function (e, k) {
        $(e).removeClass(k);
    };
    toggleClass = function (e, k, v) {
        $(e).toggleClass(k, v);
    };
    classList = function (e) {
        let k = $.trim(e.className);
        return k === "" ? [] : k.split(/\s+/);
    };
}

// ui
let callbacks = {};

export function handle_ui(event) {
    let e = event.target;
    if ((e && (hasClass(e, "ui") || hasClass(e, "ui-submit")))
        || (this.tagName === "A" && hasClass(this, "ui"))) {
        event.preventDefault();
    }
    let k = classList(this);
    for (let i = 0; i < k.length; ++i) {
        let c = callbacks[k[i]];
        if (c) {
            for (let j = 0; j < c.length; ++j) {
                c[j].call(this, event);
            }
        }
    }
}

handle_ui.on = function (className, callback) {
    callbacks[className] = callbacks[className] || [];
    callbacks[className].push(callback);
};

handle_ui.trigger = function (className, event) {
    let c = callbacks[className];
    if (c) {
        if (typeof event === "string") {
            event = $.Event(event); // XXX IE8: `new Event` is not supported
        }
        for (let j = 0; j < c.length; ++j) {
            if (!event.isImmediatePropagationStopped()) {
                c[j].call(this, event);
            }
        }
    }
};

$(document).on("click", ".ui, .uic", handle_ui);
$(document).on("change", ".uich", handle_ui);
$(document).on("error", ".ui-error", handle_ui);
$(document).on("input", ".uii", handle_ui);
$(document).on("keydown", ".uikd", handle_ui);
$(document).on("load", ".ui-load", handle_ui);
$(document).on("mouseup mousedown", ".uim", handle_ui);
$(document).on("unfold", ".ui-unfold", handle_ui);


let in_tab = false;
$(document).on("keydown keyup", function (event) {
    if (event.key === "Tab" && !event.metaKey) {
        in_tab = event.type === "keydown";
    }
});
$(document).on("focus", "textarea.ta1", function () {
    if (in_tab) {
        let self = this;
        setTimeout(function () { self.setSelectionRange(0, self.value.length); }, 0);
    }
});

export function fold61(sel, arrowholder, direction) {
    if (direction != null) {
        direction = !direction;
    }
    toggleClass(sel, "hidden", direction);
    arrowholder && $(arrowholder).find("span.foldarrow").html(
        hasClass(sel, "hidden") ? "&#x25B6;" : "&#x25BC;"
    );
    return false;
}


function input_is_checkboxlike(elt) {
    return elt.type === "checkbox" || elt.type === "radio";
}

export function input_default_value(elt) {
    if (input_is_checkboxlike(elt)) {
        if (elt.hasAttribute("data-default-checked")) {
            return elt.getAttribute("data-default-checked") !== "false";
        } else if (elt.hasAttribute("data-default-value")) {
            return elt.value == elt.getAttribute("data-default-value");
        } else {
            return elt.defaultChecked;
        }
    } else {
        if (elt.hasAttribute("data-default-value")) {
            return elt.getAttribute("data-default-value");
        } else {
            return elt.defaultValue;
        }
    }
}

export function input_set_default_value(elt, val) {
    if (input_is_checkboxlike(elt)) {
        elt.removeAttribute("data-default-checked");
        elt.defaultChecked = val == "";
    } else {
        elt.removeAttribute("data-default-value");
        elt.value = elt.value; // set dirty value flag
        elt.defaultValue = val;
    }
}
