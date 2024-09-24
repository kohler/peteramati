// ui-range.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, handle_ui } from "./ui.js";
import { event_key, event_modkey } from "./ui-key.js";

handle_ui.on("js-range-click", function (event) {
    if (event.type === "change") {
        return;
    }

    const f = this.closest("form"),
        rangeclick_state = f.jsRangeClick || {},
        kind = this.getAttribute("data-range-type") || this.name;
    f.jsRangeClick = rangeclick_state;

    let key = false;
    if (event.type === "keydown" && !event_modkey(event)) {
        key = event_key(event);
    }
    if (rangeclick_state.__clicking__
        || (event.type === "updaterange" && rangeclick_state["__update__" + kind])
        || (event.type === "keydown" && key !== "ArrowDown" && key !== "ArrowUp")) {
        return;
    }

    // find checkboxes and groups of this type
    const cbs = [], cbgs = [];
    for (const cb of f.querySelectorAll("input.js-range-click").values()) {
        if (kind === (cb.getAttribute("data-range-type") || cb.name)
            && cb.checkVisibility()) {
            cbs.push(cb);
            if (hasClass(cb, "is-range-group"))
                cbgs.push(cb);
        }
    }

    // find positions
    let lastelt = rangeclick_state[kind], thispos, lastpos;
    for (let i = 0; i !== cbs.length; ++i) {
        if (cbs[i] === this) {
            thispos = i;
        }
        if (cbs[i] === lastelt) {
            lastpos = i;
        }
    }

    if (key) {
        if (thispos !== 0 && key === "ArrowUp") {
            --thispos;
        } else if (thispos < cbs.length - 1 && key === "ArrowDown") {
            ++thispos;
        }
        $(cbs[thispos]).focus().scrollIntoView();
        event.preventDefault();
        return;
    }

    // handle click
    let group = false, single_group = false;
    if (event.type === "click") {
        rangeclick_state.__clicking__ = true;
        let i, j;

        if (hasClass(this, "is-range-group")) {
            i = 0;
            j = cbs.length - 1;
            group = this.getAttribute("data-range-group");
        } else {
            rangeclick_state[kind] = this;
            if (event.shiftKey && lastelt) {
                if (lastpos <= thispos) {
                    i = lastpos;
                    j = thispos - 1;
                } else {
                    i = thispos + 1;
                    j = lastpos;
                }
            } else {
                i = 1;
                j = 0;
                single_group = this.getAttribute("data-range-group");
            }
        }

        while (i <= j) {
            if (cbs[i].checked !== this.checked
                && !hasClass(cbs[i], "is-range-group")
                && (!group || cbs[i].getAttribute("data-range-group") === group))
                $(cbs[i]).trigger("click");
            ++i;
        }

        delete rangeclick_state.__clicking__;
    } else if (event.type === "updaterange") {
        rangeclick_state["__updated__" + kind] = true;
    }

    // update groups
    for (const cbg of cbgs) {
        const group = cbg.getAttribute("data-range-group");
        if (single_group && group !== single_group) {
            continue;
        }

        let state = null;
        for (const cb of cbs) {
            if (cb.getAttribute("data-range-group") === group
                && !hasClass(cb, "is-range-group")) {
                if (state === null) {
                    state = cb.checked;
                } else if (state !== cb.checked) {
                    state = "indeterminate";
                    break;
                }
            }
        }

        let changed = false;
        if (state === "indeterminate") {
            changed = !cbg.indeterminate;
            cbg.indeterminate = true;
            cbg.checked = true;
        } else {
            changed = cbg.indeterminate || cbg.checked !== state;
            cbg.indeterminate = false;
            cbg.checked = state;
        }
        if (changed || cbg === event.target) {
            const event = new CustomEvent("rangechange", {
                bubbles: true, cancelable: true,
                detail: {rangeType: kind, rangeGroup: group, newState: state}
            });
            f.dispatchEvent(event);
        }
    }
}, -1);

$(function () {
    $(".is-range-group").each(function () {
        handle_ui.trigger.call(this, "js-range-click", "updaterange");
    });
});
