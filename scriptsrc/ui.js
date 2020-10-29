// ui.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
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
$(document).on("keydown", ".uikd", handle_ui);
$(document).on("input", ".uii", handle_ui);
$(document).on("unfold", ".ui-unfold", handle_ui);
$(document).on("mouseup mousedown", ".uim", handle_ui);


export function fold61(sel, arrowholder, direction) {
    if (direction != null)
        direction = !direction;
    toggleClass(sel, "hidden", direction);
    if (arrowholder)
        $(arrowholder).find("span.foldarrow").html(
            hasClass(sel, "hidden") ? "&#x25B6;" : "&#x25BC;"
        );
    return false;
}


export class ImmediatePromise {
    constructor(value) {
        this.value = value;
    }
    then(executor) {
        try {
            return new ImmediatePromise(executor(this.value));
        } catch (e) {
            return Promise.reject(e);
        }
    }
    catch(executor) {
        return this;
    }
    finally(executor) {
        try {
            executor();
        } catch (e) {
        }
        return this;
    }
}
