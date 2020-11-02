// ui-history.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

export let push_history_state;

if ("pushState" in window.history) {
    push_history_state = function (href) {
        var state;
        if (!history.state) {
            state = {href: location.href};
            $(document).trigger("collectState", [state]);
            history.replaceState(state, document.title, state.href);
        }
        if (href) {
            state = {href: href};
            $(document).trigger("collectState", [state]);
            history.pushState(state, document.title, state.href);
        }
        return true;
    };
} else {
    push_history_state = function () { return false; };
}
