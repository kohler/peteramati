// ui.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
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
const callbacks = {};

function collect_callbacks(cbs, c, etype) {
    for (let j = 0; j !== c.length; j += 3) {
        if (!c[j] || c[j] === etype) {
            let k = cbs.length;
            while (k !== 0 && c[j+1] > cbs[k-2]) {
                k -= 2;
            }
            cbs.splice(k, 0, c[j+1], c[j+2]);
        }
    }
}

function call_callbacks(cbs, element, event) {
    for (let i = 0; i !== cbs.length && !event.isImmediatePropagationStopped(); i += 2) {
        cbs[i+1].call(element, event);
    }
}

export function handle_ui(event) {
    let e = event.target;
    if ((e && (hasClass(e, "ui") || hasClass(e, "uin")))
        || (this.tagName === "A" && hasClass(this, "ui"))) {
        event.preventDefault();
    }
    let k = classList(this), cbs = null;
    for (let i = 0; i < k.length; ++i) {
        let c = callbacks[k[i]];
        if (c) {
            cbs = cbs || [];
            collect_callbacks(cbs, c, event.type);
        }
    }
    cbs && cbs.length && call_callbacks(cbs, this, event);
}

handle_ui.on = function (className, callback, priority) {
    let dot = className.indexOf("."), type = null;
    if (dot >= 0) {
        type = className.substring(0, dot);
        className = className.substring(dot + 1);
    }
    callbacks[className] = callbacks[className] || [];
    callbacks[className].push(type, priority || 0, callback);
};

handle_ui.trigger = function (className, event) {
    let c = callbacks[className];
    if (c) {
        if (typeof event === "string") {
            event = $.Event(event); // XXX IE8: `new Event` is not supported
        }
        let cbs = [];
        collect_callbacks(cbs, c, event.type);
        cbs.length && call_callbacks(cbs, this, event);
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
$(document).on("focusin", ".ui-focusin", handle_ui);
$(document).on("focusout", ".ui-focusout", handle_ui);


let in_tab = false;
document.addEventListener("keydown", function (event) {
    if (event.key === "Tab" && !event.metaKey) {
        in_tab = true;
    }
});
document.addEventListener("keyup", function (event) {
    if (event.key === "Tab" && !event.metaKey) {
        in_tab = false;
    }
});
document.addEventListener("focusin", function (event) {
    if (in_tab && event.target.nodeName === "TEXTAREA" && hasClass(event.target, "ta1")) {
        requestAnimationFrame(() => event.target.select());
    }
});


export function fold61(sel, arrowholder, direction) {
    if (direction != null) {
        direction = !direction;
    }
    toggleClass(sel, "hidden", direction);
    if (arrowholder) {
        const fa = arrowholder.querySelector("span.foldarrow");
        fa && fa.classList.toggle("isopen", !hasClass(sel, "hidden"));
    }
    return false;
}


export function input_is_checkboxlike(elt) {
    return elt.type === "checkbox" || elt.type === "radio";
}

export function input_is_buttonlike(elt) {
    return elt.type === "button" || elt.type === "submit" || elt.type === "reset";
}

export function input_successful(elt) {
    if (elt.disabled || !elt.name)
        return false;
    else if (elt.type === "checkbox" || elt.type === "radio")
        return elt.checked;
    else
        return elt.type !== "button" && elt.type !== "submit" && elt.type !== "reset";
}

export function input_default_value(elt) {
    if (elt.hasAttribute("data-default-value")) {
        return elt.getAttribute("data-default-value");
    } else if (input_is_checkboxlike(elt)) {
        let checked = elt.defaultChecked;
        if (elt.hasAttribute("data-default-checked")) {
            checked = elt.getAttribute("data-default-checked") !== "false";
        }
        return checked ? elt.defaultValue : "";
    } else {
        return elt.defaultValue;
    }
}

export function input_set_default_value(elt, val) {
    if (input_is_checkboxlike(elt)) {
        // set dirty checkedness flag:
        elt.checked = elt.checked; // eslint-disable-line no-self-assign
        elt.removeAttribute("data-default-checked");
        elt.removeAttribute("data-default-value");
        if (val == null || val == "") {
            elt.defaultChecked = false;
        } else if (val == elt.value) {
            elt.defaultChecked = true;
        } else {
            elt.setAttribute("data-default-value", val);
        }
    } else {
        // set dirty value flag:
        elt.value = elt.value; // eslint-disable-line no-self-assign
        if (elt.type !== "select") {
            elt.removeAttribute("data-default-value");
            elt.defaultValue = val;
        } else {
            elt.setAttribute("data-default-value", val);
        }
    }
}

function text_eq(a, b) {
    if (a !== b) {
        a = (a == null ? "" : a).replace(/\r\n?/g, "\n");
        b = (b == null ? "" : b).replace(/\r\n?/g, "\n");
    }
    return a === b;
}

export function input_differs(elt) {
    const type = elt.type;
    if (!type) {
        if (elt instanceof RadioNodeList) {
            for (let i = 0; i !== elt.length; ++i) {
                if (input_differs(elt[i]))
                    return true;
            }
        }
        return false;
    } else if (type === "button" || type === "submit" || type === "reset") {
        return false;
    } else if (type === "checkbox" || type === "radio") {
        return elt.checked !== input_default_value(elt);
    } else {
        return !text_eq(elt.value, input_default_value(elt));
    }
}

export function form_differs(form) {
    let coll;
    if (form instanceof HTMLFormElement) {
        coll = form.elements;
    } else {
        coll = $(form).find("input, select, textarea");
        coll.length || (coll = $(form).filter("input, select, textarea"));
    }
    const colllen = coll.length;
    for (let i = 0; i !== colllen; ++i) {
        const e = coll[i];
        if (e.name
            && !hasClass(e, "ignore-diff")
            && !e.disabled
            && input_differs(e))
            return e;
    }
    return null;
}

export function check_form_differs(form, elt) {
    (form instanceof HTMLElement) || (form = $(form)[0]);
    const differs = (elt && form_differs(elt)) || form_differs(form);
    toggleClass(form, "differs", !!differs);
    if (form.hasAttribute("data-differs-toggle")) {
        $("." + form.getAttribute("data-differs-toggle")).toggleClass("hidden", !differs);
    }
}

export function $e(tag, attr) {
    const e = document.createElement(tag);
    if (!attr) {
        // nothing
    } else if (typeof attr === "string") {
        e.className = attr;
    } else {
        for (const i in attr) {
            if (attr[i] == null) {
                // skip
            } else if (typeof attr[i] === "boolean") {
                attr[i] ? e.setAttribute(i, "") : e.removeAttribute(i);
            } else {
                e.setAttribute(i, attr[i]);
            }
        }
    }
    for (let i = 2; i < arguments.length; ++i) {
        if (arguments[i] != null) {
            e.append(arguments[i]);
        }
    }
    return e;
}

function awakenf() {
    /*if (hasClass(this, "need-diff-check")) {
        hotcrp.add_diff_check(this);
    }*/
    if (hasClass(this, "need-autogrow")) {
        $(this).autogrow();
    }
    /*if (hasClass(this, "need-suggest")) {
        hotcrp.suggest.call(this);
    }*/
    if (hasClass(this, "need-tooltip")) {
        $pa.tooltip.call(this);
    }
}

$.fn.awaken = function () {
    this.each(awakenf);
    //this.find(".need-diff-check, .need-autogrow, .need-suggest, .need-tooltip").each(awakenf);
    this.find(".need-autogrow, .need-tooltip").each(awakenf);
    return this;
};

$(function () { $(document.body).awaken(); });
