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


export function input_is_checkboxlike(elt) {
    return elt.type === "checkbox" || elt.type === "radio";
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
    const expected = input_default_value(elt);
    if (input_is_checkboxlike(elt)) {
        const val = elt.checked ? elt.value : "";
        return val !== expected;
    } else if (elt.type === "button" || elt.type === "submit" || elt.type === "reset") {
        return false;
    } else {
        return !text_eq(elt.value, expected);
    }
}


// HtmlCollector

export class HtmlCollector {
    constructor() {
        this.clear();
    }

    clear() {
        this.open = [];
        this.close = [];
        this.html = "";
        return this;
    }

    push(open, close) {
        if (open && close) {
            this.open.push(this.html + open);
            this.close.push(close);
            this.html = "";
            return this.open.length - 1;
        } else {
            this.html += open;
        }
        return this;
    }

    pop(pos) {
        let n = this.open.length;
        if (pos == null) {
            pos = Math.max(0, n - 1);
        }
        while (n > pos) {
            --n;
            this.html = this.open[n] + this.html + this.close[n];
            this.open.pop();
            this.close.pop();
        }
        return this;
    }

    pop_n(n) {
        return this.pop(Math.max(0, this.open.length - n));
    }

    push_pop(text) {
        this.html += text;
        return this.pop();
    }

    pop_push(open, close) {
        this.pop();
        return this.push(open, close);
    }

    pop_collapse(pos) {
        if (pos == null) {
            pos = this.open.length ? this.open.length - 1 : 0;
        }
        while (this.open.length > pos) {
            if (this.html !== "") {
                this.html = this.open[this.open.length - 1] + this.html +
                    this.close[this.open.length - 1];
            }
            this.open.pop();
            this.close.pop();
        }
        return this;
    }

    render() {
        this.pop(0);
        return this.html;
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
