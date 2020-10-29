// main.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import * as svgutil from "./svgpathutil.js";
import IntervalSeq from "./intervalseq.js";
import {
    hasClass, addClass, removeClass, toggleClass, classList, handle_ui,
    fold61, ImmediatePromise
    } from "./ui.js";
import {
    hoturl, hoturl_post, hoturl_absolute_base, url_absolute, hoturl_gradeparts
    } from "./hoturl.js";
import "./ui-pset.js";
import {
    escape_entities, unescape_entities, urlencode, urldecode,
    text_to_html, regexp_quote, html_id_encode
    } from "./encoders.js";
import { Bubble, tooltip } from "./tooltip.js";
import { filediff_load } from "./diff.js";
import { filediff_markdown } from "./diff-markdown.js";

function $$(id) {
    return document.getElementById(id);
}

if (!window.JSON || !window.JSON.parse) {
    window.JSON = {parse: $.parseJSON};
}

if (!Element.prototype.closest) {
    Element.prototype.closest = function (s) {
        return $(this).closest(s)[0];
    };
}

if (!String.prototype.startsWith) {
    String.prototype.startsWith = function (search, pos) {
        pos = !pos || pos < 0 ? 0 : +pos;
        return this.substring(pos, pos + search.length) === search;
    };
}
if (!String.prototype.endsWith) {
    String.prototype.endsWith = function (search, this_len) {
        if (this_len === undefined || this_len > this.length) {
            this_len = this.length;
        }
        return this.substring(this_len - search.length, this_len) === search;
    };
}
if (!String.prototype.trimStart) {
    String.prototype.trimStart = function () {
        return this.replace(/^[\s\uFEFF\xA0]+/, '');
    };
}


// error logging
function log_jserror(errormsg, error, noconsole) {
    if (!error && errormsg instanceof Error) {
        error = errormsg;
        errormsg = {"error": error.toString()};
    } else if (typeof errormsg === "string")
        errormsg = {"error": errormsg};
    if (error && error.fileName && !errormsg.url)
        errormsg.url = error.fileName;
    if (error && error.lineNumber && !errormsg.lineno)
        errormsg.lineno = error.lineNumber;
    if (error && error.columnNumber && !errormsg.colno)
        errormsg.colno = error.columnNumber;
    if (error && error.stack)
        errormsg.stack = error.stack;
    $.ajax(hoturl_post("api/jserror"), {
        global: false, method: "POST", cache: false, data: errormsg
    });
    if (error && !noconsole && typeof console === "object" && console.error)
        console.error(errormsg.error);
}

(function () {
    var old_onerror = window.onerror, nerrors_logged = 0;
    window.onerror = function (errormsg, url, lineno, colno, error) {
        if ((url || !lineno) && ++nerrors_logged <= 10) {
            var x = {error: errormsg, url: url, lineno: lineno};
            if (colno)
                x.colno = colno;
            log_jserror(x, error, true);
        }
        return old_onerror ? old_onerror.apply(this, arguments) : false;
    };
})();

function jqxhr_error_message(jqxhr, status, errormsg) {
    if (status === "parsererror")
        return "Internal error: bad response from server.";
    else if (errormsg)
        return errormsg.toString();
    else if (status === "timeout")
        return "Connection timed out.";
    else if (status)
        return "Failed [" + status + "].";
    else
        return "Failed.";
}

var after_outstanding = (function () {
var outstanding = 0, after = [];

$(document).ajaxError(function (event, jqxhr, settings, httperror) {
    if (jqxhr.readyState != 4)
        return;
    var data;
    if (jqxhr.responseText && jqxhr.responseText.charAt(0) === "{") {
        try {
            data = JSON.parse(jqxhr.responseText);
        } catch (e) {
        }
    }
    if (!data || !data.user_error) {
        var msg = url_absolute(settings.url) + " API failure: ";
        if (siteinfo.user && siteinfo.user.email)
            msg += "user " + siteinfo.user.email + ", ";
        msg += jqxhr.status;
        if (httperror)
            msg += ", " + httperror;
        if (jqxhr.responseText)
            msg += ", " + jqxhr.responseText.substring(0, 100);
        log_jserror(msg);
    }
});

$(document).ajaxComplete(function (event, jqxhr, settings) {
    if (settings.trackOutstanding && --outstanding === 0) {
        while (after.length)
            after.shift()();
    }
});

$.ajaxPrefilter(function (options, originalOptions, jqxhr) {
    if (options.global === false)
        return;
    var f = options.success;
    function onerror(jqxhr, status, errormsg) {
        if (f) {
            var rjson;
            if (/application\/json/.test(jqxhr.getResponseHeader("Content-Type") || "")
                && jqxhr.responseText) {
                try {
                    rjson = JSON.parse(jqxhr.responseText);
                } catch (e) {
                }
            }
            if (!rjson
                || typeof rjson !== "object"
                || rjson.ok !== false)
                rjson = {ok: false};
            if (!rjson.error)
                rjson.error = jqxhr_error_message(jqxhr, status, errormsg);
            f(rjson, jqxhr, status);
        }
    }
    if (!options.error)
        options.error = onerror;
    else if ($.isArray(options.error))
        options.error.push(onerror);
    else
        options.error = [options.error, onerror];
    if (options.timeout == null)
        options.timeout = 10000;
    if (options.dataType == null)
        options.dataType = "json";
    if (options.trackOutstanding)
        ++outstanding;
});

return function (f) {
    if (f === undefined)
        return outstanding > 0;
    else if (outstanding > 0)
        after.push(f);
    else
        f();
};
})();


// geometry
jQuery.fn.extend({
    geometry: function (outer) {
        var g, d;
        if (this[0] == window) {
            g = {left: this.scrollLeft(), top: this.scrollTop()};
        } else if (this.length == 1 && this[0].getBoundingClientRect) {
            g = jQuery.extend({}, this[0].getBoundingClientRect());
            if ((d = window.pageXOffset))
                g.left += d, g.right += d;
            if ((d = window.pageYOffset))
                g.top += d, g.bottom += d;
            if (!("width" in g)) {
                g.width = g.right - g.left;
                g.height = g.bottom - g.top;
            }
            return g;
        } else {
            g = this.offset();
        }
        if (g) {
            g.width = outer ? this.outerWidth() : this.width();
            g.height = outer ? this.outerHeight() : this.height();
            g.right = g.left + g.width;
            g.bottom = g.top + g.height;
        }
        return g;
    },
    scrollIntoView: function (opts) {
        opts = opts || {};
        for (var i = 0; i !== this.length; ++i) {
            var p = $(this[i]).geometry(), x = this[i].parentNode;
            while (x && x.tagName && $(x).css("overflow-y") === "visible") {
                x = x.parentNode;
            }
            x = x && x.tagName ? x : window;
            var w = $(x).geometry();
            if (p.top < w.top + (opts.marginTop || 0) || opts.atTop) {
                var pos = Math.max(p.top - (opts.marginTop || 0), 0);
                if (x === window) {
                    x.scrollTo(x.scrollX, pos);
                } else {
                    x.scrollTop = pos;
                }
            } else if (p.bottom > w.bottom - (opts.marginBottom || 0)) {
                var pos = Math.max(p.bottom + (opts.marginBottom || 0) - w.height, 0);
                if (x === window) {
                    x.scrollTo(x.scrollX, pos);
                } else {
                    x.scrollTop = pos;
                }
            }
        }
        return this;
    },
    serializeWith: function(data) {
        var s = this.serialize(), i, sep;
        if (s != null && data) {
            sep = s.length && s[s.length - 1] != "&" ? "&" : "";
            for (i in data)
                if (data[i] != null) {
                    s += sep + encodeURIComponent(i) + "=" + encodeURIComponent(data[i]);
                    sep = "&";
                }
        }
        return s;
    }
});

function geometry_translate(g, dx, dy) {
    if (typeof dx === "object")
        dy = dx.top, dx = dx.left;
    g = jQuery.extend({}, g);
    g.top += dy;
    g.right += dx;
    g.bottom += dy;
    g.left += dx;
    return g;
}


// history

var push_history_state, ever_push_history_state = false;
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
        ever_push_history_state = true;
        return true;
    };
} else {
    push_history_state = function () { return false; };
}


// text transformation
function text_eq(a, b) {
    if (a === b)
        return true;
    a = (a == null ? "" : a).replace(/\r\n?/g, "\n");
    b = (b == null ? "" : b).replace(/\r\n?/g, "\n");
    return a === b;
}

function plural_noun(n, what) {
    if ($.isArray(n))
        n = n.length;
    if (n == 1)
        return what;
    if (what == "this")
        return "these";
    if (/^.*?(?:s|sh|ch|[bcdfgjklmnpqrstvxz][oy])$/.test(what)) {
        if (what.charAt(what.length - 1) === "y")
            return what.substring(0, what.length - 1) + "ies";
        else
            return what + "es";
    } else
        return what + "s";
}

function plural(n, what) {
    if ($.isArray(n))
        n = n.length;
    return n + " " + plural_noun(n, what);
}

function ordinal(n) {
    if (n >= 1 && n <= 3)
        return n + ["st", "nd", "rd"][Math.floor(n - 1)];
    else
        return n + "th";
}

function commajoin(a, joinword) {
    var l = a.length;
    joinword = joinword || "and";
    if (l == 0)
        return "";
    else if (l == 1)
        return a[0];
    else if (l == 2)
        return a[0] + " " + joinword + " " + a[1];
    else
        return a.slice(0, l - 1).join(", ") + ", " + joinword + " " + a[l - 1];
}

function sprintf(fmt) {
    var words = fmt.split(/(%(?:%|-?(?:\d*|\*?)(?:\.\d*)?[sdefgroxX]))/), wordno, word,
        arg, argno, conv, pad, t = "";
    for (wordno = 0, argno = 1; wordno != words.length; ++wordno) {
        word = words[wordno];
        if (word.charAt(0) != "%")
            t += word;
        else if (word.charAt(1) == "%")
            t += "%";
        else {
            arg = arguments[argno];
            ++argno;
            conv = word.match(/^%(-?)(\d*|\*?)(?:|[.](\d*))(\w)/);
            if (conv[2] == "*") {
                conv[2] = arg.toString();
                arg = arguments[argno];
                ++argno;
            }
            if (conv[4] >= "e" && conv[4] <= "g" && conv[3] == null) {
                conv[3] = 6;
            }
            if (conv[4] === "g") {
                arg = Number(arg).toPrecision(conv[3]).toString();
                arg = arg.replace(/\.(\d*[1-9])?0+(|e.*)$/,
                                  function (match, p1, p2) {
                                      return (p1 == null ? "" : "." + p1) + p2;
                                  });
            } else if (conv[4] === "f") {
                arg = Number(arg).toFixed(conv[3]);
            } else if (conv[4] === "e") {
                arg = Number(arg).toExponential(conv[3]);
            } else if (conv[4] === "r") {
                arg = Number(arg).toFixed(+(conv[3] || 0));
                if (+(conv[3] || 0))
                    arg = arg.replace(/\.?0*$/, "");
            } else if (conv[4] === "d") {
                arg = Math.floor(arg);
            } else if (conv[4] === "o") {
                arg = Math.floor(arg).toString(8);
            } else if (conv[4] === "x") {
                arg = Math.floor(arg).toString(16);
            } else if (conv[4] === "X") {
                arg = Math.floor(arg).toString(16).toUpperCase();
            }
            arg = arg.toString();
            if (conv[2] !== "" && conv[2] !== "0") {
                pad = conv[2].charAt(0) === "0" ? "0" : " ";
                while (arg.length < parseInt(conv[2], 10)) {
                    arg = conv[1] ? arg + pad : pad + arg;
                }
            }
            t += arg;
        }
    }
    return t;
}

function now_msec() {
    return (new Date).getTime();
}

function now_sec() {
    return now_msec() / 1000;
}

var strftime = (function () {
    function pad(num, str, n) {
        str += num.toString();
        return str.length <= n ? str : str.substring(str.length - n);
    }
    function unparse_q(d, alt, is24) {
        if (is24 && alt && !d.getSeconds())
            return strftime("%H:%M", d);
        else if (is24)
            return strftime("%H:%M:%S", d);
        else if (alt && d.getSeconds())
            return strftime("%#l:%M:%S%P", d);
        else if (alt && d.getMinutes())
            return strftime("%#l:%M%P", d);
        else if (alt)
            return strftime("%#l%P", d);
        else
            return strftime("%I:%M:%S %p", d);
    }
    var unparsers = {
        a: function (d) { return (["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"])[d.getDay()]; },
        A: function (d) { return (["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"])[d.getDay()]; },
        d: function (d) { return pad(d.getDate(), "0", 2); },
        e: function (d, alt) { return pad(d.getDate(), alt ? "" : " ", 2); },
        u: function (d) { return d.getDay() || 7; },
        w: function (d) { return d.getDay(); },
        b: function (d) { return (["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"])[d.getMonth()]; },
        B: function (d) { return (["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"])[d.getMonth()]; },
        h: function (d) { return unparsers.b(d); },
        m: function (d) { return pad(d.getMonth() + 1, "0", 2); },
        y: function (d) { return d.getFullYear() % 100; },
        Y: function (d) { return d.getFullYear(); },
        H: function (d) { return pad(d.getHours(), "0", 2); },
        k: function (d, alt) { return pad(d.getHours(), alt ? "" : " ", 2); },
        I: function (d) { return pad(d.getHours() % 12 || 12, "0", 2); },
        l: function (d, alt) { return pad(d.getHours() % 12 || 12, alt ? "" : " ", 2); },
        M: function (d) { return pad(d.getMinutes(), "0", 2); },
        X: function (d) { return strftime("%#e %b %Y %#q", d); },
        p: function (d) { return d.getHours() < 12 ? "AM" : "PM"; },
        P: function (d) { return d.getHours() < 12 ? "am" : "pm"; },
        q: function (d, alt) { return unparse_q(d, alt, strftime.is24); },
        r: function (d, alt) { return unparse_q(d, alt, false); },
        R: function (d, alt) { return unparse_q(d, alt, true); },
        S: function (d) { return pad(d.getSeconds(), "0", 2); },
        T: function (d) { return strftime("%H:%M:%S", d); },
        /* XXX z Z */
        D: function (d) { return strftime("%m/%d/%y", d); },
        F: function (d) { return strftime("%Y-%m-%d", d); },
        s: function (d) { return Math.floor(d.getTime() / 1000); },
        n: function (d) { return "\n"; },
        t: function (d) { return "\t"; },
        "%": function (d) { return "%"; }
    };
    function strftime(fmt, d) {
        var words = fmt.split(/(%#?\S)/), wordno, word, alt, f, t = "";
        if (d == null)
            d = new Date;
        else if (typeof d == "number")
            d = new Date(d * 1000);
        for (wordno = 0; wordno != words.length; ++wordno) {
            word = words[wordno];
            alt = word.charAt(1) == "#";
            if (word.charAt(0) == "%"
                && (f = unparsers[word.charAt(1 + alt)]))
                t += f(d, alt);
            else
                t += word;
        }
        return t;
    };
    return strftime;
})();


// events
var event_key = (function () {
var key_map = {"Spacebar": " ", "Esc": "Escape"},
    charCode_map = {"9": "Tab", "13": "Enter", "27": "Escape"},
    keyCode_map = {
        "9": "Tab", "13": "Enter", "16": "ShiftLeft", "17": "ControlLeft",
        "18": "AltLeft", "20": "CapsLock", "27": "Escape", "33": "PageUp",
        "34": "PageDown", "37": "ArrowLeft", "38": "ArrowUp", "39": "ArrowRight",
        "40": "ArrowDown", "91": "OSLeft", "92": "OSRight", "93": "OSRight",
        "224": "OSLeft", "225": "AltRight"
    },
    nonprintable_map = {
        "Alt": 2,
        "AltLeft": 2,
        "AltRight": 2,
        "CapsLock": 2,
        "Control": 2,
        "ControlLeft": 2,
        "ControlRight": 2,
        "Meta": 2,
        "OSLeft": 2,
        "OSRight": 2,
        "Shift": 2,
        "ShiftLeft": 2,
        "ShiftRight": 2,
        "ArrowLeft": 1,
        "ArrowRight": 1,
        "ArrowUp": 1,
        "ArrowDown": 1,
        "Backspace": 1,
        "Enter": 1,
        "Escape": 1,
        "PageUp": 1,
        "PageDown": 1,
        "Tab": 1
    };
function event_key(evt) {
    var x;
    if (typeof evt === "string") {
        return evt;
    } else if ((x = evt.key) != null) {
        return key_map[x] || x;
    } else if ((x = evt.charCode)) {
        return charCode_map[x] || String.fromCharCode(x);
    } else if ((x = evt.keyCode)) {
        if (keyCode_map[x])
            return keyCode_map[x];
        else if ((x >= 48 && x <= 57) || (x >= 65 && x <= 90))
            return String.fromCharCode(x);
    } else {
        return "";
    }
}
event_key.printable = function (evt) {
    return !nonprintable_map[event_key(evt)]
        && (typeof evt === "string" || !(evt.ctrlKey || evt.metaKey));
};
event_key.modifier = function (evt) {
    return nonprintable_map[event_key(evt)] > 1;
};
event_key.is_default_a = function (evt, a) {
    return !evt.shiftKey && !evt.metaKey && !evt.ctrlKey
        && evt.button == 0
        && (!a || !hasClass("ui", a));
};
return event_key;
})();

function event_modkey(evt) {
    return (evt.shiftKey ? 1 : 0) + (evt.ctrlKey ? 2 : 0) + (evt.altKey ? 4 : 0) + (evt.metaKey ? 8 : 0);
}
event_modkey.SHIFT = 1; // NB values matter
event_modkey.CTRL = 2;
event_modkey.ALT = 4;
event_modkey.META = 8;

function make_onkey(key, f) {
    return function (evt) {
        if (!event_modkey(evt) && event_key(evt) === key) {
            evt.preventDefault();
            evt.stopImmediatePropagation();
            f.call(this, evt);
        }
    };
}


// localStorage
var wstorage = function () { return false; };
try {
    if (window.localStorage && window.JSON)
        wstorage = function (is_session, key, value) {
            try {
                var s = is_session ? window.sessionStorage : window.localStorage;
                if (typeof key === "undefined")
                    return !!s;
                else if (typeof value === "undefined")
                    return s.getItem(key);
                else if (value === null)
                    return s.removeItem(key);
                else if (typeof value === "object")
                    return s.setItem(key, JSON.stringify(value));
                else
                    return s.setItem(key, value);
            } catch (err) {
                return false;
            }
        };
} catch (err) {
}
wstorage.json = function (is_session, key) {
    var x = wstorage(is_session, key);
    return x ? JSON.parse(x) : false;
};
wstorage.site = function (is_session, key, value) {
    if (siteinfo.base !== "/")
        key = siteinfo.base + key;
    return wstorage(is_session, key, value);
};
wstorage.site_json = function (is_session, key) {
    if (siteinfo.base !== "/")
        key = siteinfo.base + key;
    return wstorage.json(is_session, key);
};


// render_xmsg
function render_xmsg(status, msg) {
    if (typeof msg === "string")
        msg = msg === "" ? [] : [msg];
    if (msg.length === 0)
        return '';
    else if (msg.length === 1)
        msg = msg[0];
    else
        msg = '<p>' + msg.join('</p><p>') + '</p>';
    if (status === 0 || status === 1 || status === 2)
        status = ["info", "warning", "error"][status];
    return '<div class="msg msg-' + status + '">' + msg + '</div>';
}


// differences and focusing
function input_is_checkboxlike(elt) {
    return elt.type === "checkbox" || elt.type === "radio";
}

function input_default_value(elt) {
    if (elt.hasAttribute("data-default-value")) {
        return elt.getAttribute("data-default-value");
    } else if (input_is_checkboxlike(elt)) {
        var c;
        if (elt.hasAttribute("data-default-checked"))
            c = elt.getAttribute("data-default-checked");
        else
            c = elt.defaultChecked;
        // XXX what if elt.value === ""?
        return c ? elt.value : "";
    } else {
        return elt.defaultValue;
    }
}

function input_differs(elt) {
    var expected = input_default_value(elt);
    if (input_is_checkboxlike(elt)) {
        return elt.checked ? expected !== elt.value : expected !== "";
    } else {
        var current = elt.tagName === "SELECT" ? $(elt).val() : elt.value;
        return !text_eq(current, expected);
    }
}

function form_differs(form, want_ediff) {
    var ediff = null, $is = $(form).find("input, select, textarea");
    if (!$is.length)
        $is = $(form).filter("input, select, textarea");
    $is.each(function () {
        if (!hasClass(this, "ignore-diff") && input_differs(this)) {
            ediff = this;
            return false;
        }
    });
    return want_ediff ? ediff : !!ediff;
}

function form_highlight(form, elt) {
    (form instanceof HTMLElement) || (form = $(form)[0]);
    toggleClass(form, "alert", (elt && form_differs(elt)) || form_differs(form));
}

function hiliter_children(form) {
    form = $(form)[0];
    form_highlight(form);
    $(form).on("change input", "input, select, textarea", function () {
        if (!hasClass(this, "ignore-diff") && !hasClass(form, "ignore-diff"))
            form_highlight(form, this);
    });
}

$(function () {
    $("form.need-unload-protection").each(function () {
        var form = this;
        removeClass(form, "need-unload-protection");
        $(form).on("submit", function () { addClass(this, "submitting"); });
        $(window).on("beforeunload", function () {
            if (hasClass(form, "alert") && !hasClass(form, "submitting"))
                return "If you leave this page now, your edits may be lost.";
        });
    });
});

function focus_at(felt) {
    felt.jquery && (felt = felt[0]);
    felt.focus();
    if (!felt.hotcrp_ever_focused) {
        if (felt.select && hasClass(felt, "want-select")) {
            felt.select();
        } else if (felt.setSelectionRange) {
            try {
                felt.setSelectionRange(felt.value.length, felt.value.length);
            } catch (e) { // ignore errors
            }
        }
        felt.hotcrp_ever_focused = true;
    }
}

function focus_within(elt, subfocus_selector) {
    var $wf = $(elt).find(".want-focus");
    if (subfocus_selector)
        $wf = $wf.filter(subfocus_selector);
    if ($wf.length == 1)
        focus_at($wf[0]);
    return $wf.length == 1;
}

function refocus_within(elt) {
    var focused = document.activeElement;
    if (focused && focused.tagName !== "A" && !$(focused).is(":visible")) {
        while (focused && focused !== elt)
            focused = focused.parentElement;
        if (focused) {
            var focusable = $(elt).find("input, select, textarea, a, button").filter(":visible").first();
            focusable.length ? focusable.focus() : $(document.activeElement).blur();
        }
    }
}


// rangeclick
handle_ui.on("js-range-click", function (event) {
    if (event.type === "change")
        return;

    var $f = $(this).closest("form"),
        rangeclick_state = $f[0].jsRangeClick || {},
        kind = this.getAttribute("data-range-type") || this.name;
    $f[0].jsRangeClick = rangeclick_state;

    var key = false;
    if (event.type === "keydown" && !event_modkey(event))
        key = event_key(event);
    if (rangeclick_state.__clicking__
        || (event.type === "updaterange" && rangeclick_state["__update__" + kind])
        || (event.type === "keydown" && key !== "ArrowDown" && key !== "ArrowUp"))
        return;

    // find checkboxes and groups of this type
    var cbs = [], cbgs = [];
    $f.find("input.js-range-click").each(function () {
        var tkind = this.getAttribute("data-range-type") || this.name;
        if (kind === tkind) {
            cbs.push(this);
            if (hasClass(this, "is-range-group"))
                cbgs.push(this);
        }
    });

    // find positions
    var lastelt = rangeclick_state[kind], thispos, lastpos, i;
    for (i = 0; i !== cbs.length; ++i) {
        if (cbs[i] === this)
            thispos = i;
        if (cbs[i] === lastelt)
            lastpos = i;
    }

    if (key) {
        if (thispos !== 0 && key === "ArrowUp")
            --thispos;
        else if (thispos < cbs.length - 1 && key === "ArrowDown")
            ++thispos;
        $(cbs[thispos]).focus().scrollIntoView();
        event.preventDefault();
        return;
    }

    // handle click
    var group = false, single_group = false, j;
    if (event.type === "click") {
        rangeclick_state.__clicking__ = true;

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
    for (j = 0; j !== cbgs.length; ++j) {
        group = cbgs[j].getAttribute("data-range-group");
        if (single_group && group !== single_group)
            continue;

        var state = null;
        for (i = 0; i !== cbs.length; ++i) {
            if (cbs[i].getAttribute("data-range-group") === group
                && !hasClass(cbs[i], "is-range-group")) {
                if (state === null)
                    state = cbs[i].checked;
                else if (state !== cbs[i].checked) {
                    state = 2;
                    break;
                }
            }
        }

        if (state === 2) {
            cbgs[j].indeterminate = true;
            cbgs[j].checked = true;
        } else {
            cbgs[j].indeterminate = false;
            cbgs[j].checked = state;
        }
    }
});

$(function () {
    $(".is-range-group").each(function () {
        handle_ui.trigger.call(this, "js-range-click", "updaterange");
    });
});


// HtmlCollector
function HtmlCollector() {
    this.clear();
}
HtmlCollector.prototype.push = function (open, close) {
    if (open && close) {
        this.open.push(this.html + open);
        this.close.push(close);
        this.html = "";
        return this.open.length - 1;
    } else
        this.html += open;
    return this;
};
HtmlCollector.prototype.pop = function (pos) {
    var n = this.open.length;
    if (pos == null)
        pos = Math.max(0, n - 1);
    while (n > pos) {
        --n;
        this.html = this.open[n] + this.html + this.close[n];
        this.open.pop();
        this.close.pop();
    }
    return this;
};
HtmlCollector.prototype.pop_n = function (n) {
    this.pop(Math.max(0, this.open.length - n));
    return this;
};
HtmlCollector.prototype.push_pop = function (text) {
    this.html += text;
    return this.pop();
};
HtmlCollector.prototype.pop_push = function (open, close) {
    this.pop();
    return this.push(open, close);
};
HtmlCollector.prototype.pop_collapse = function (pos) {
    if (pos == null)
        pos = this.open.length ? this.open.length - 1 : 0;
    while (this.open.length > pos) {
        if (this.html !== "")
            this.html = this.open[this.open.length - 1] + this.html +
                this.close[this.open.length - 1];
        this.open.pop();
        this.close.pop();
    }
    return this;
};
HtmlCollector.prototype.render = function () {
    this.pop(0);
    return this.html;
};
HtmlCollector.prototype.clear = function () {
    this.open = [];
    this.close = [];
    this.html = "";
    return this;
};
HtmlCollector.prototype.next_htctl_id = (function () {
var id = 1;
return function () {
    while (document.getElementById("htctl" + id))
        ++id;
    ++id;
    return "htctl" + (id - 1);
};
})();


// popup dialogs
function popup_skeleton(options) {
    var hc = new HtmlCollector, $d = null;
    options = options || {};
    hc.push('<div class="modal" role="dialog"><div class="modal-dialog'
        + (!options.anchor || options.anchor === window ? " modal-dialog-centered" : "")
        + (options.style ? '" style="' + escape_entities(options.style) : '')
        + '" role="document"><div class="modal-content"><form enctype="multipart/form-data" accept-charset="UTF-8"'
        + (options.form_class ? ' class="' + options.form_class + '"' : '')
        + '>', '</form></div></div></div>');
    hc.push_actions = function (actions) {
        hc.push('<div class="popup-actions">', '</div>');
        if (actions)
            hc.push(actions.join("")).pop();
        return hc;
    };
    function show_errors(data) {
        var form = $d.find("form")[0],
            dbody = $d.find(".popup-body"),
            m = render_xmsg(2, data.error);
        $d.find(".msg-error").remove();
        dbody.length ? dbody.prepend(m) : $d.find("h2").after(m);
        for (var f in data.errf || {}) {
            var e = form[f];
            if (e) {
                var x = $(e).closest(".entryi, .f-i");
                (x.length ? x : $(e)).addClass("has-error");
            }
        }
        return $d;
    }
    function close() {
        tooltip.erase();
        $d.find("textarea, input").unautogrow();
        $d.trigger("closedialog");
        $d.remove();
        removeClass(document.body, "modal-open");
    }
    hc.show = function (visible) {
        if (!$d) {
            $d = $(hc.render()).appendTo(document.body);
            $d.find(".need-tooltip").each(tooltip);
            $d.on("click", function (event) {
                event.target === $d[0] && close();
            });
            $d.find("button[name=cancel]").on("click", close);
            if (options.action) {
                if (options.action instanceof HTMLFormElement) {
                    $d.find("form").attr({action: options.action.action, method: options.action.method});
                } else {
                    $d.find("form").attr({action: options.action, method: options.method || "post"});
                }
            }
            for (var k in {minWidth: 1, maxWidth: 1, width: 1}) {
                if (options[k] != null)
                    $d.children().css(k, options[k]);
            }
            $d.show_errors = show_errors;
            $d.close = close;
        }
        if (visible !== false) {
            popup_near($d, options.anchor || window);
            $d.find(".need-autogrow").autogrow();
            $d.find(".need-tooltip").each(tooltip);
        }
        return $d;
    };
    return hc;
}

function popup_near(elt, anchor) {
    tooltip.erase();
    if (elt.jquery)
        elt = elt[0];
    while (!hasClass(elt, "modal-dialog"))
        elt = elt.childNodes[0];
    var bgelt = elt.parentNode;
    addClass(bgelt, "show");
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
    var efocus;
    $(elt).find("input, button, textarea, select").filter(":visible").each(function () {
        if (hasClass(this, "want-focus")) {
            efocus = this;
            return false;
        } else if (!efocus
                   && !hasClass(this, "dangerous")
                   && !hasClass(this, "no-focus")) {
            efocus = this;
        }
    });
    efocus && focus_at(efocus);
}


// initialization
var set_local_time = (function () {
var servhr24, showdifference = false;
function set_local_time(elt, servtime) {
    var d, s;
    if (elt && typeof elt == "string")
        elt = $$(elt);
    if (elt && showdifference) {
        d = new Date(servtime * 1000);
        if (servhr24)
            s = strftime("%A %#e %b %Y %#R your time", d);
        else
            s = strftime("%A %#e %b %Y %#r your time", d);
        if (elt.tagName == "SPAN") {
            elt.innerHTML = " (" + s + ")";
            elt.style.display = "inline";
        } else {
            elt.innerHTML = s;
            elt.style.display = "block";
        }
    }
}
set_local_time.initialize = function (servzone, hr24) {
    servhr24 = hr24;
    // print local time if server time is in a different time zone
    showdifference = Math.abs((new Date).getTimezoneOffset() - servzone) >= 60;
};
return set_local_time;
})();


var hotcrp_onload = [];
function hotcrp_load(arg) {
    if (!arg)
        for (var x = 0; x < hotcrp_onload.length; ++x)
            hotcrp_onload[x]();
    else if (typeof arg === "string")
        hotcrp_onload.push(hotcrp_load[arg]);
    else
        hotcrp_onload.push(arg);
}
hotcrp_load.time = function (servzone, hr24) {
    set_local_time.initialize(servzone, hr24);
};
hotcrp_load.opencomment = function () {
    if (location.hash.match(/^\#?commentnew$/))
        open_new_comment();
};


var foldmap = {}, foldsession_unique = 1;
function fold(which, dofold, foldtype) {
    var i, elt, selt, opentxt, closetxt, foldnum, foldnumid;
    if (which instanceof Array) {
        for (i = 0; i < which.length; i++)
            fold(which[i], dofold, foldtype);

    } else if (typeof which == "string") {
        foldnum = foldtype;
        if (foldmap[which] != null && foldmap[which][foldtype] != null)
            foldnum = foldmap[which][foldtype];
        foldnumid = foldnum ? foldnum : "";

        elt = $$("fold" + which) || $$(which);
        fold(elt, dofold, foldnum);

        // check for session
        if ((selt = $$('foldsession.' + which + foldnumid)))
            selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0) + '&u=' + foldsession_unique++);
        else if ((selt = $$('foldsession.' + which)))
            selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0) + '&sub=' + (foldtype || foldnumid) + '&u=' + foldsession_unique++);

        // check for focus
        if (!dofold && (selt = $$("fold" + which + foldnumid + "_d"))) {
            if (selt.setSelectionRange && selt.hotcrp_ever_focused == null) {
                selt.setSelectionRange(selt.value.length, selt.value.length);
                selt.hotcrp_ever_focused = true;
            }
            selt.focus();
        }

    } else if (which) {
        foldnumid = foldtype ? foldtype : "";
        opentxt = "fold" + foldnumid + "o";
        closetxt = "fold" + foldnumid + "c";
        if (dofold == null && which.className.indexOf(opentxt) >= 0)
            dofold = true;
        if (dofold)
            which.className = which.className.replace(opentxt, closetxt);
        else
            which.className = which.className.replace(closetxt, opentxt);
        // IE won't actually do the fold unless we yell at it
        if (document.recalc)
            try {
                which.innerHTML = which.innerHTML + "";
            } catch (err) {
            }
    }

    return false;
}

function foldup(event, opts) {
    var e = this, dofold = false, m, x;
    if (typeof opts === "number") {
        opts = {n: opts};
    } else if (!opts) {
        opts = {};
    }
    if (this.tagName === "DIV"
        && event
        && event.target.closest("a")
        && !opts.required) {
        return;
    }
    if (!("n" in opts)
        && e.hasAttribute("data-fold-target")
        && (m = e.getAttribute("data-fold-target").match(/^(\D[^#]*$|.*(?=#)|)#?(\d*)([cou]?)$/))) {
        if (m[1] !== "") {
            e = document.getElementById(m[1]);
        }
        opts.n = parseInt(m[2]) || 0;
        if (!("f" in opts) && m[3] !== "") {
            if (m[3] === "u" && this.tagName === "INPUT" && this.type === "checkbox") {
                opts.f = this.checked;
            } else {
                opts.f = m[3] === "c";
            }
        }
    }
    var foldname = "fold" + (opts.n || "");
    while (e
           && (!e.id || e.id.substr(0, 4) != "fold")
           && !hasClass(e, "has-fold")
           && (opts.n == null
               || (!hasClass(e, foldname + "c")
                   && !hasClass(e, foldname + "o")))) {
        e = e.parentNode;
    }
    if (!e) {
        return true;
    }
    if (opts.n == null) {
        x = classList(e);
        for (var i = 0; i !== x.length; ++i) {
            if (x[i].substring(0, 4) === "fold"
                && (m = x[i].match(/^fold(\d*)[oc]$/))
                && (opts.n == null || +m[1] < opts.n)) {
                opts.n = +m[1];
                foldname = "fold" + (opts.n || "");
            }
        }
    }
    if (!("f" in opts)
        && (this.tagName === "INPUT" || this.tagName === "SELECT")) {
        var value = null;
        if (this.type === "checkbox") {
            opts.f = !this.checked;
        } else if (this.type === "radio") {
            if (!this.checked)
                return true;
            value = this.value;
        } else if (this.type === "select-one") {
            value = this.selectedIndex < 0 ? "" : this.options[this.selectedIndex].value;
        }
        if (value !== null) {
            var values = (e.getAttribute("data-" + foldname + "-values") || "").split(/\s+/);
            opts.f = values.indexOf(value) < 0;
        }
    }
    dofold = !hasClass(e, foldname + "c");
    if (!("f" in opts) || !opts.f !== dofold) {
        opts.f = dofold;
        fold(e, dofold, opts.n || 0);
        $(e).trigger(opts.f ? "fold" : "unfold", opts);
    }
    if (this.hasAttribute("aria-expanded")) {
        this.setAttribute("aria-expanded", dofold ? "false" : "true");
    }
    if (event
        && typeof event === "object"
        && event.type === "click"
        && !hasClass(event.target, "uic")) {
        event.stopPropagation();
        event.preventDefault(); // needed for expanders despite handle_ui!
    }
}

handle_ui.on("js-foldup", foldup);

function crpfocus(id, subfocus, seltype) {
    var selt = $$(id);
    if (selt && subfocus)
        selt.className = selt.className.replace(/links[0-9]*/, 'links' + subfocus);
    var felt = $$(id + (subfocus ? subfocus : "") + "_d");
    if (felt && !(felt.type == "text" && felt.value && seltype == 1))
        felt.focus();
    if (felt && felt.type == "text" && seltype == 3 && felt.select)
        felt.select();
    if ((selt || felt) && window.event)
        window.event.returnValue = false;
    if (seltype && seltype >= 1)
        window.scroll(0, 0);
    return !(selt || felt);
}

function make_link_callback(elt) {
    return function () {
        window.location = elt.href;
    };
}


// check marks for ajax saves
function make_outline_flasher(elt, rgba, duration) {
    var h = elt.hotcrp_outline_flasher, hold_duration;
    if (!h)
        h = elt.hotcrp_outline_flasher = {old_outline: elt.style.outline};
    if (h.interval) {
        clearInterval(h.interval);
        h.interval = null;
    }
    if (rgba) {
        duration = duration || 3000;
        hold_duration = duration * 0.6;
        h.start = now_msec();
        h.interval = setInterval(function () {
            var now = now_msec(), delta = now - h.start, opacity = 0;
            if (delta < hold_duration)
                opacity = 0.5;
            else if (delta <= duration)
                opacity = 0.5 * Math.cos((delta - hold_duration) / (duration - hold_duration) * Math.PI);
            if (opacity <= 0.03) {
                elt.style.outline = h.old_outline;
                clearInterval(h.interval);
                h.interval = null;
            } else
                elt.style.outline = "4px solid rgba(" + rgba + ", " + opacity + ")";
        }, 13);
    }
}

function setajaxcheck(elt, rv) {
    if (typeof elt == "string")
        elt = document.getElementById(elt);
    if (!elt)
        return;
    if (elt.jquery && rv && !rv.ok && rv.errf) {
        var i, e, f = elt.closest("form")[0];
        for (i in rv.errf)
            if (f && (e = f.elements[i])) {
                elt = e;
                break;
            }
    }
    if (elt.jquery)
        elt = elt[0];
    make_outline_flasher(elt);
    if (rv && !rv.ok && !rv.error)
        rv = {error: "Error"};
    if (!rv || rv.ok)
        make_outline_flasher(elt, "0, 200, 0");
    else
        elt.style.outline = "5px solid red";
    if (rv && rv.error)
        Bubble(rv.error, "errorbubble").near(elt).removeOn(elt, "input change click hide");
}

function link_urls(t) {
    var re = /((?:https?|ftp):\/\/(?:[^\s<>\"&]|&amp;)*[^\s<>\"().,:;&])([\"().,:;]*)(?=[\s<>&]|$)/g;
    return t.replace(re, function (m, a, b) {
        return '<a href="' + a + '" rel="noreferrer">' + a + '</a>' + b;
    });
}

// text rendering
var render_text = (function () {
function render0(text) {
    return link_urls(escape_entities(text));
}

var default_format = 0, renderers = {"0": {format: 0, render: render0}};

function lookup(format) {
    var r, p;
    if (format && (r = renderers[format]))
        return r;
    if (format
        && typeof format === "string"
        && (p = format.indexOf(".")) > 0
        && (r = renderers[format.substring(0, p)]))
        return r;
    if (format == null)
        format = default_format;
    return renderers[format] || renderers[0];
}

function do_render(format, is_inline, a) {
    var r = lookup(format);
    if (r.format)
        try {
            var f = (is_inline && r.render_inline) || r.render;
            return {
                format: r.formatClass || r.format,
                content: f.apply(this, a)
            };
        } catch (e) {
            log_jserror("do_render format " + r.format + ": " + e.toString(), e);
        }
    return {format: 0, content: render0(a[0])};
}

function render_text(format, text /* arguments... */) {
    var a = [text], i;
    for (i = 2; i < arguments.length; ++i)
        a.push(arguments[i]);
    return do_render.call(this, format, false, a);
}

function render_inline(format, text /* arguments... */) {
    var a = [text], i;
    for (i = 2; i < arguments.length; ++i)
        a.push(arguments[i]);
    return do_render.call(this, format, true, a);
}

function on() {
    var $j = $(this), format = this.getAttribute("data-format"),
        content = this.getAttribute("data-content") || $j.text(), args = null, f, i;
    if ((i = format.indexOf(".")) > 0) {
        var a = format.split(/\./);
        format = a[0];
        args = {};
        for (i = 1; i < a.length; ++i)
            args[a[i]] = true;
    }
    if (this.tagName == "DIV")
        f = render_text.call(this, format, content, args);
    else
        f = render_inline.call(this, format, content, args);
    if (f.format)
        $j.html(f.content);
    var s = $.trim(this.className.replace(/(?:^| )(?:need-format|format\d+)(?= |$)/g, " "));
    this.className = s + (s ? " format" : "format") + (f.format || 0);
    if (f.format)
        $j.trigger("renderText", f);
}

$.extend(render_text, {
    add_format: function (x) {
        x.format && (renderers[x.format] = x);
    },
    format: function (format) {
        return lookup(format);
    },
    set_default_format: function (format) {
        default_format = format;
    },
    inline: render_inline,
    on: on,
    on_page: function () { $(".need-format").each(on); }
});
return render_text;
})();

(function (md) {
render_text.add_format({
    format: 1,
    render: function (text) {
        return md.render(text);
    }
});
render_text.add_format({
    format: 2,
    render: function (text) {
        return pa_render_terminal(text);
    }
})
})(window.markdownit({
    highlight: function (str, lang) {
        if (lang && hljs.getLanguage(lang)) {
            try {
                return hljs.highlight(lang, str, true).value;
            } catch (ex) {
            }
        }
        return "";
    }
}).use(markdownit_katex));

$(render_text.on_page);


// list management, conflict management
(function ($) {
var cookie_set_at;
function update_digest(info) {
    var add = typeof info === "string" ? 1 : 0,
        digests = wstorage.site_json(false, "list_digests") || [],
        found = -1, now = now_msec();
    for (var i = 0; i < digests.length; ++i) {
        var digest = digests[i];
        if (digest[add] === info)
            found = i;
        else if (digest[2] < now - 30000) {
            digests.splice(i, 1);
            --i;
        } else if (now <= digest[0])
            now = digest[0] + 1;
    }
    if (found >= 0)
        digests[found][2] = now;
    else if (add) {
        digests.push([now, info, now]);
        found = digests.length - 1;
    }
    wstorage.site(false, "list_digests", digests);
    if (found >= 0)
        return digests[found][1 - add];
    else
        return false;
}
function make_digest(info) {
    var digest = update_digest(info);
    return digest ? "list" + digest : info;
}
function resolve_digest(info) {
    var xinfo, m;
    if ((m = /^list(\d+)(?=\s|$)/.exec(info))
        && (xinfo = update_digest(+m[1])))
        return xinfo;
    else
        return info;
}
function set_prevnext(info, sitehref) {
    var m = /(?:^|\/)(~[^\/]+)\/pset(?:|\.php)\/([^\/]+)((?:\/[0-9a-f]+)?)(?=[\/?#]|$)/.exec(sitehref), want1, want2;
    if (m) {
        info = JSON.parse(info);
        if (!info.pset) {
            want2 = m[1] + "/pset/" + m[2];
            want1 = want2 + m[3];
        } else if (info.pset === m[2]) {
            want1 = want2 = m[1];
        } else {
            return "";
        }
        var idx = info.items.indexOf(want1);
        if (idx < 0 && want2 !== want1)
            idx = info.items.indexOf(want2);
        if (idx >= 0) {
            return " " + JSON.stringify({
                cur: m[1] + "/pset/" + m[2],
                prev: idx === 0 ? null : info.items[idx-1],
                next: idx === info.items.length - 1 ? null : info.items[idx+1]
            });
        }
    }
    return "";
}
function set_cookie(info, sitehref) {
    info = resolve_digest(info);
    var digest = make_digest(info);
    if (sitehref && /(?:^|\/)pset(?:|\.php)\//.test(sitehref))
        digest += set_prevnext(info, sitehref);
    cookie_set_at = now_msec();
    var p = "; Max-Age=20", m;
    if (siteinfo.site_relative && (m = /^[a-z]+:\/\/[^\/]*(\/.*)/.exec(hoturl_absolute_base())))
        p += "; Path=" + m[1];
    document.cookie = "hotlist-info-" + cookie_set_at + "=" + encodeURIComponent(digest) + siteinfo.cookie_params + p;
}
function is_listable(sitehref) {
    return /(?:^|\/)pset(?:|\.php)(?:$|\/)/.test(sitehref);
}
function find_hotlist(e) {
    var hl, $hl;
    if ((hl = e.closest(".has-hotlist")))
        return hl;
    else if (e.tagName === "FORM"
             && ($hl = $(e).find(".has-hotlist")).length === 1)
        return $hl[0];
    else
        return null;
}
function handle_list(e, href) {
    var hl, sitehref;
    if (href
        && href.startsWith(siteinfo.site_relative)
        && is_listable((sitehref = href.substring(siteinfo.site_relative.length)))
        && (hl = e.closest(".has-hotlist"))) {
        var info = hl.getAttribute("data-hotlist");
        if (!info) {
            var event = jQuery.Event("pa-hotlist");
            $(hl).trigger(event);
            info = event.hotlist;
            if (info && typeof info !== "string")
                info = JSON.stringify(info);
        }
        info && set_cookie(info, sitehref);
    }
}
function unload_list() {
    var hl = document.body.getAttribute("data-hotlist");
    if (hl && (!cookie_set_at || cookie_set_at + 3 < now_msec()))
        set_cookie(hl, location.href);
}

function default_click(evt) {
    var base = location.href;
    if (location.hash) {
        base = base.substring(0, base.length - location.hash.length);
    }
    if (this.href.substring(0, base.length + 1) === base + "#") {
        return true;
    } else if (after_outstanding()) {
        after_outstanding(make_link_callback(this));
        return true;
    } else {
        return false;
    }
}

$(document).on("click", "a", function (evt) {
    if (!hasClass(this, "ui")) {
        if (!event_key.is_default_a(evt)
            || this.target
            || !default_click.call(this, evt)) {
            handle_list(this, this.getAttribute("href"));
        }
    }
});

$(document).on("submit", "form", function (evt) {
    if (hasClass(this, "ui-submit")) {
        handle_ui.call(this, evt);
    } else {
        handle_list(this, this.getAttribute("action"));
    }
});

$(window).on("beforeunload", unload_list);

$(function () {
    // resolve list digests
    $(".has-hotlist").each(function () {
        var info = this.getAttribute("data-hotlist");
        if (info && info.startsWith("list") && (info = resolve_digest(info))) {
            this.setAttribute("data-hotlist", info);
        }
    });
});

})(jQuery);


// mail
function setmailpsel(sel) {
    fold("psel", !!sel.value.match(/^(?:pc$|pc:|all$)/), 9);
    fold("psel", !sel.value.match(/^new.*rev$/), 10);
}


handle_ui.on("pa-show-viewoptions", function () {
    fold61(this.nextSibling, this.parentNode);
});

handle_ui.on("pa-pset-upload-grades", function () {
    $("#upload").show();
});

handle_ui.on("pa-pset-setcommit", function () {
    this.closest("form").submit();
});

handle_ui.on("pa-signin-radio", function (event) {
    if (this.value === "login") {
        fold("logingroup", false);
        fold("logingroup", false, 2);
        $$("signin").value = "Sign in";
    } else if (this.value === "forgot") {
        fold("logingroup", true);
        fold("logingroup", false, 2);
        $$("signin").value = "Reset password";
    } else if (this.value === "new") {
        fold("logingroup", true);
        fold("logingroup", true, 2);
        $$("signin").value = "Create account";
    }
});


// pa_diff_traverse(tr, down, flags)
//    Find the diff line (pa-d[idc]) near `tr` in the direction of `down`.
//    If `down === null`, look up *starting* from `tr`.
//    Flags: 1 means stay within the current file; otherwise traverse
//    between files. 2 means return all lines.
function pa_diff_traverse(tr, down, flags) {
    tr = tr.closest(".pa-dl, .pa-dg, .pa-filediff");
    var tref = tr ? tr.parentElement : null, direction;
    if (down == null) {
        down = false;
        direction = "previousSibling";
    } else {
        direction = down ? "nextSibling" : "previousSibling";
        if (hasClass(tr, "pa-dl")) {
            tr = tr[direction];
        }
    }
    while (true) {
        while (!tr && tref) {
            if ((flags & 1) && hasClass(tref, "pa-filediff")) {
                return null;
            }
            tr = tref[direction];
            tref = tref.parentElement;
        }
        if (!tr) {
            return null;
        } else if (tr.nodeType !== Node.ELEMENT_NODE) {
            tr = tr[direction];
        } else if (hasClass(tr, "pa-dl")
                   && ((flags & 2) || / pa-g[idc]/.test(tr.className))
                   && tr.offsetParent) {
            return tr;
        } else if (hasClass(tr, "pa-dg") || hasClass(tr, "pa-filediff")) {
            tref = tr;
            tr = tref[down ? "firstChild" : "lastChild"];
        } else {
            tr = tr[direction];
        }
    }
}

// pa_diff_locate(tr, down)
//    Analyze a click on `tr`. Returns `null` if the target
//    is a <textarea> or <a>.
function pa_diff_locate(tr, down) {
    if (!tr
        || tr.tagName === "TEXTAREA"
        || tr.tagName === "A") {
        return null;
    }

    var thisline = tr.closest(".pa-dl"),
        nearline = pa_diff_traverse(tr, down, 1),
        filediff;
    if (!nearline || !(filediff = nearline.closest(".pa-filediff"))) {
        return null;
    }

    var file = filediff.getAttribute("data-pa-file"),
        result = {ufile: file, file: file, tr: nearline},
        user = filediff.getAttribute("data-pa-file-user");
    if (user) {
        result.ufile = user + "-" + file;
    }

    var lm;
    if (thisline
        && (lm = thisline.getAttribute("data-landmark"))
        && /^[ab]\d+$/.test(lm)) {
        result[lm.charAt(0) + "line"] = +lm.substring(1);
        result.lineid = lm;
    } else {
        result.aline = +nearline.firstChild.getAttribute("data-landmark");
        result.bline = +nearline.firstChild.nextSibling.getAttribute("data-landmark");
        result.lineid = result.bline ? "b" + result.bline : "a" + result.aline;
    }

    if (thisline && hasClass(thisline, "pa-gw")) {
        result.notetr = thisline;
    } else {
        do {
            nearline = nearline.nextSibling;
        } while (nearline
                 && (nearline.nodeType !== Node.ELEMENT_NODE
                     || hasClass(nearline, "pa-gn")
                     || !nearline.offsetParent));
        if (nearline && hasClass(nearline, "pa-gw")) {
            result.notetr = nearline;
        }
    }
    return result;
}

function pa_note(elt) {
    var note = elt.getAttribute("data-pa-note");
    if (typeof note === "string" && note !== "") {
        note = JSON.parse(note);
    }
    if (typeof note === "number") {
        note = [false, "", 0, note];
    }
    return note || [false, ""];
}

function pa_set_note(elt, note) {
    if (note === false || note === null) {
        elt.removeAttribute("data-pa-note");
    } else if (note !== undefined) {
        elt.setAttribute("data-pa-note", JSON.stringify(note));
    }
}

function pa_fix_note_links() {
    function note_skippable(tr) {
        return pa_note(tr)[1] === "";
    }

    function note_anchor(tr) {
        var anal = pa_diff_locate(tr), td;
        if (anal && (td = pa_ensureline(anal.ufile, anal.lineid))) {
            return "#" + td.id;
        } else {
            return "";
        }
    }

    function set_link(tr, next_tr) {
        var $a = $(tr).find(".pa-note-links a");
        if (!$a.length) {
            $a = $('<a class="ui pa-goto"></a>');
            $('<div class="pa-note-links"></div>').append($a).prependTo($(tr).find(".pa-notecontent"));
        }

        $a.attr("href", note_anchor(next_tr));
        var t = next_tr ? "NEXT >" : "TOP";
        if ($a.text() !== t) {
            $a.text(t);
        }
    }

    var notes = $(".pa-gw"), notepos = 0;
    while (notepos < notes.length && notes[notepos] !== this) {
        ++notepos;
    }
    if (notepos < notes.length) {
        var prevpos = notepos - 1;
        while (prevpos >= 0 && note_skippable(notes[prevpos])) {
            --prevpos;
        }

        var nextpos = notepos + 1;
        while (nextpos < notes.length && note_skippable(notes[nextpos])) {
            ++nextpos;
        }

        if (prevpos >= 0) {
            set_link(notes[prevpos], note_skippable(this) ? notes[nextpos] : this);
        }
        set_link(this, notes[nextpos]);
    }
}

function pa_render_note(note, transition) {
    var tr = this, $tr = $(this);
    if (!hasClass(tr, "pa-gw")) {
        while (!hasClass(tr, "pa-dl")) {
            tr = tr.parentElement;
        }
        var ntr = tr.nextSibling;
        while (ntr && (ntr.nodeType !== Node.ELEMENT_NODE
                       || hasClass(ntr, "pa-gn"))) {
            tr = ntr;
            ntr = ntr.nextSibling;
        }
        $tr = $('<div class="pa-dl pa-gw"><div class="pa-notebox"></div></div>').insertAfter(tr);
        tr = $tr[0];
        var tp = pa_diff_traverse(tr, false, 1), lineid, e, lm, dash;
        if (tp) {
            if (hasClass(tp, "pa-gd")) {
                lineid = "a" + tp.firstChild.getAttribute("data-landmark");
            } else if (hasClass(tp, "pa-gr")
                       && (e = tp.lastChild.firstChild)
                       && (lm = e.getAttribute("data-landmark"))
                       && (dash = lm.indexOf("-")) >= 0) {
                lineid = "b" + lm.substring(dash + 1);
            } else {
                lineid = "b" + tp.firstChild.nextSibling.getAttribute("data-landmark");
            }
            tr.setAttribute("data-landmark", lineid);
        }
    }
    if (arguments.length == 0) {
        return tr;
    }

    pa_set_note(tr, note);
    var $td = $tr.find(".pa-notebox"), $content = $td.children();
    if (transition) {
        $content.slideUp(80).queue(function () { $content.remove(); });
    } else {
        $content.remove();
    }

    if (note[1] === "") {
        pa_fix_note_links.call(tr);
        if (transition) {
            $tr.children().slideUp(80);
        } else {
            addClass(tr, "hidden");
        }
        return tr;
    }

    var t = '<div class="pa-notecontent clearfix">';
    if (note[2]) {
        var authorids = $.isArray(note[2]) ? note[2] : [note[2]];
        var authors = [];
        for (var i in authorids) {
            var p = siteinfo.pc[authorids[i]];
            if (p) {
                if (p.nick)
                    authors.push(p.nick);
                else if (p.nicklen || p.lastpos)
                    authors.push(p.name.substr(0, p.nicklen || p.lastpos - 1));
                else
                    authors.push(p.name);
            }
        }
        if (authors.length)
            t += '<div class="pa-note-author">[' + authors.join(', ') + ']</div>';
    }
    t += '<div class="pa-note pa-' + (note[0] ? 'comment' : 'grade') + 'note';
    if (note[4] && typeof note[4] === "number")
        t += '" data-format="' + note[4];
    t += '"></div></div>';
    $td.append(t);

    if (!note[4]) {
        $td.find(".pa-note").addClass("format0").text(note[1]);
    } else {
        var r = render_text(note[4], note[1]);
        $td.find(".pa-note").addClass("format" + (r.format || 0)).html(r.content);
    }

    pa_fix_note_links.call(tr);

    if (transition) {
        $td.find(".pa-notecontent").hide().slideDown(80);
    } else {
        removeClass(tr, "hidden");
    }
    return tr;
}

// pa_api_conditioner

var pa_api_conditioner = (function () {
var outstanding = 0, waiting = [];

function post_ajax(url, data, method, resolve) {
    return function () {
        ++outstanding;
        $.ajax(url, {
            data: data, method: method || "POST", cache: false, dataType: "json",
            success: function (data) {
                resolve(data);
                --outstanding;
                waiting.length && waiting.shift()();
            }
        });
    };
}

return function (url, data, method) {
    return new Promise(function (resolve, reject) {
        var f = post_ajax(url, data, method, resolve);
        outstanding < 5 ? f() : waiting.push(f);
    });
};

})();


// pa_linenote
(function ($) {
var labelctr = 0, curanal, down_event, scrolled_x, scrolled_y, scrolled_at;

function render_form($tr, note, transition) {
    $tr.removeClass("hidden").addClass("editing");
    note && pa_set_note($tr[0], note);
    var $td = $tr.find(".pa-notebox");
    if (transition) {
        $tr.css("display", "").children().css("display", "");
        var $content = $td.children();
        $content.slideUp(80).queue(function () { $content.remove(); });
    }

    var $pi = $(curanal.tr).closest(".pa-psetinfo");
    var format = note ? note[4] : null;
    if (format == null)
        format = $tr.closest(".pa-filediff").attr("data-default-format");
    var t = '<form method="post" action="' +
        escape_entities(hoturl_post("api/linenote", hoturl_gradeparts($pi[0], {file: curanal.file, line: curanal.lineid, oldversion: (note && note[3]) || 0, format: format}))) +
        '" enctype="multipart/form-data" accept-charset="UTF-8" class="ui-submit pa-noteform">' +
        '<textarea class="pa-note-entry" name="note"></textarea>' +
        '<div class="aab aabr pa-note-aa">' +
        '<div class="aabut"><button class="btn-primary" type="submit">Save comment</button></div>' +
        '<div class="aabut"><button type="button" name="cancel">Cancel</button></div>';
    if (!$pi[0].hasAttribute("data-pa-user-can-view-grades")) {
        t += '<div class="aabut"><label><input type="checkbox" name="iscomment" value="1">Show immediately</label></div>';
    }
    var $form = $(t + '</div></form>').appendTo($td);

    var $ta = $form.find("textarea");
    if (note && note[1] !== null) {
        $ta.text(note[1]);
        $ta[0].setSelectionRange && $ta[0].setSelectionRange(note[1].length, note[1].length);
    }
    $ta.autogrow().keydown(keydown);
    $form.find("input[name=iscomment]").prop("checked", !!(note && note[0]));
    $form.find("button[name=cancel]").click(cancel);
    $form.on("submit", function () {
        pa_save_note.call(this.closest(".pa-dl"));
    });
    if (transition) {
        $ta.focus();
        $form.hide().slideDown(100);
    }
}

function anal_tr() {
    var elt;
    if (curanal && (elt = pa_ensureline(curanal.ufile, curanal.lineid))) {
        for (elt = elt.closest(".pa-dl"); elt && !elt.offsetParent; )
            elt = elt.previousSibling;
        return elt;
    } else {
        return null;
    }
}

function set_scrolled_at(evt) {
    if (evt && evt.pageX != null) {
        scrolled_at = evt.timeStamp;
        scrolled_x = evt.screenX;
        scrolled_y = evt.screenY;
    }
}

function arrowcapture(evt) {
    var key, modkey;
    if ((evt.type === "mousemove"
         && scrolled_at
         && ((Math.abs(evt.screenX - scrolled_x) <= 1 && Math.abs(evt.screenY - scrolled_y) <= 1)
             || evt.timeStamp - scrolled_at <= 200))
        || ((evt.type === "keydown" || evt.type === "keyup")
            && event_key.modifier(evt))) {
        return;
    } else if (evt.type !== "keydown"
               || ((key = event_key(evt)) !== "ArrowUp"
                   && key !== "ArrowDown"
                   && key !== "Enter")
               || ((modkey = event_modkey(evt))
                   && (modkey !== event_modkey.META || key !== "Enter"))
               || !curanal) {
        return uncapture();
    }

    var tr = anal_tr();
    if (!tr) {
        return uncapture();
    }
    if (key === "ArrowDown" || key === "ArrowUp") {
        removeClass(tr, "live");
        tr = pa_diff_traverse(tr, key === "ArrowDown", 0);
        if (!tr) {
            return;
        }
    }

    curanal = pa_diff_locate(tr);
    evt.preventDefault();
    set_scrolled_at(evt);
    if (key === "Enter") {
        make_linenote();
    } else {
        var wf = tr.closest(".pa-with-fixed");
        $(tr).addClass("live").scrollIntoView(wf ? {marginTop: wf.firstChild.offsetHeight} : null);
    }
    return true;
}

function capture(tr, keydown) {
    addClass(tr, "live");
    $(".pa-filediff").removeClass("live");
    $(document).off(".pa-linenote");
    $(document).on((keydown ? "keydown.pa-linenote " : "") + "mousemove.pa-linenote mousedown.pa-linenote", arrowcapture);
}

function uncapture() {
    $(".pa-dl.live").removeClass("live");
    $(".pa-filediff").addClass("live");
    $(document).off(".pa-linenote");
}

function unedit(tr, always) {
    tr = tr.closest(".pa-dl");
    var note = pa_note(tr),
        $text = tr ? $(tr).find("textarea") : null;
    if (!tr
        || (!always
            && $text.length
            && !text_eq(note[1], $text.val().replace(/\s+$/, "")))) {
        return false;
    } else {
        removeClass(tr, "editing");
        $(tr).find(":focus").blur();
        pa_render_note.call(tr, note, true);
        var click_tr = anal_tr();
        if (click_tr) {
            capture(click_tr, true);
        }
        return true;
    }
}

function resolve_grade_range(grb) {
    var count = +grb.getAttribute("data-pa-notes-outstanding") - 1;
    if (count) {
        grb.setAttribute("data-pa-notes-outstanding", count);
    } else {
        grb.removeAttribute("data-pa-notes-outstanding");
        $(grb).find(".pa-grade").each(function () {
            pa_compute_landmark_range_grade.call(this, null, true);
        });
    }
}

var pa_save_note = function (text) {
    if (!hasClass(this, "pa-gw")) {
        throw new Error("!");
    }
    if (hasClass(this, "pa-outstanding")) {
        return false;
    }
    addClass(this, "pa-outstanding");

    var self = this,
        note = pa_note(this),
        editing = hasClass(this, "editing"),
        table = this.closest(".pa-filediff"),
        pi = table.closest(".pa-psetinfo"),
        grb = this.closest(".pa-grade-range-block"),
        file = table.getAttribute("data-pa-file"),
        tr = pa_diff_traverse(this, false, 1),
        data, lineid;
    if (this.hasAttribute("data-landmark")) {
        lineid = this.getAttribute("data-landmark");
    } else if (hasClass(tr, "pa-gd")) {
        lineid = "a" + tr.firstChild.getAttribute("data-landmark");
    } else {
        lineid = "b" + tr.firstChild.nextSibling.getAttribute("data-landmark");
    }
    if (editing) {
        var f = $(this).find("form")[0];
        data = {note: f.note.value};
        if (f.iscomment && f.iscomment.checked) {
            data.iscomment = 1;
        }
        $(f).find(".pa-save-message").remove();
        $(f).find(".aab").append('<div class="aabut pa-save-message">Saving…</div>');
    } else {
        if (typeof text === "function") {
            text = text(note ? note[1] : "", note);
        }
        data = {note: text};
    }
    data.format = note ? note[4] : null;
    if (data.format == null) {
        data.format = table.getAttribute("data-default-format");
    }

    grb && grb.setAttribute("data-pa-notes-outstanding", +grb.getAttribute("data-pa-notes-outstanding") + 1);
    return new Promise(function (resolve, reject) {
        pa_api_conditioner(
            hoturl_post("api/linenote", hoturl_gradeparts(pi, {
                file: file, line: lineid, oldversion: (note && note[3]) || 0
            })), data
        ).then(function (data) {
            removeClass(self, "pa-outstanding");
            if (data && data.ok) {
                removeClass(self, "pa-save-failed");
                note = data.linenotes[file];
                note = note && note[lineid];
                pa_set_note(self, note);
                if (editing) {
                    $(self).find(".pa-save-message").html("Saved");
                    unedit(self);
                } else {
                    pa_render_note.call(self, note);
                }
                resolve(self);
            } else {
                addClass(self, "pa-save-failed");
                editing && $(self).find(".pa-save-message").html('<strong class="err">' + escape_entities(data.error || "Failed") + '</strong>');
                reject(self);
            }
            grb && resolve_grade_range(grb);
        });
    });
}

function cancel(evt) {
    unedit(this, true);
    return true;
}

function keydown(evt) {
    if (event_key(evt) === "Escape" && !event_modkey(evt) && unedit(this)) {
        return false;
    } else if (event_key(evt) === "Enter" && event_modkey(evt) === event_modkey.META) {
        $(this).closest("form").submit();
        return false;
    } else {
        return true;
    }
}

function nearby(dx, dy) {
    return (dx * dx) + (dy * dy) < 144;
}

function pa_linenote(event) {
    var dl = event.target.closest(".pa-dl");
    if (event.button !== 0
        || !dl
        || dl.matches(".pa-gn, .pa-gx")) {
        return;
    }
    var anal = pa_diff_locate(event.target),
        t = now_msec();
    if (event.type === "mousedown" && anal) {
        if (curanal
            && curanal.tr === anal.tr
            && down_event
            && nearby(down_event[0] - event.clientX, down_event[1] - event.clientY)
            && t - down_event[2] <= 500) {
            // skip
        } else {
            curanal = anal;
            down_event = [event.clientX, event.clientY, t, false];
        }
    } else if (event.type === "mouseup" && anal) {
        if (curanal
            && curanal.tr === anal.tr
            && down_event
            && nearby(down_event[0] - event.clientX, down_event[1] - event.clientY)
            && !down_event[3]) {
            curanal = anal;
            down_event[3] = true;
            make_linenote(event);
        }
    } else if (event.type === "click" && anal) {
        curanal = anal;
        make_linenote(event);
    } else {
        curanal = down_event = null;
    }
}

function make_linenote(event) {
    var $tr;
    if (curanal.notetr) {
        $tr = $(curanal.notetr);
    } else {
        $tr = $(pa_render_note.call(curanal.tr));
    }
    set_scrolled_at(event);
    if ($tr.hasClass("editing")) {
        if (unedit($tr[0])) {
            event && event.stopPropagation();
            return true;
        } else {
            var $ta = $tr.find("textarea").focus();
            $ta[0].setSelectionRange && $ta[0].setSelectionRange(0, $ta.val().length);
            return false;
        }
    } else {
        render_form($tr, pa_note($tr[0]), true);
        capture(curanal.tr, false);
        return false;
    }
}

handle_ui.on("pa-editablenotes", pa_linenote);

})($);


// pa_expandcontext
(function ($) {

function expand(evt) {
    var contextrow = evt.currentTarget;
    var panal = pa_diff_locate(contextrow, false);
    while (panal && !panal.bline) {
        panal = pa_diff_locate(panal.tr, false);
    }
    var nanal = pa_diff_locate(contextrow, true);
    if (!panal && !nanal) {
        return;
    }
    var paline = panal ? panal.aline + 1 : 1;
    var pbline = panal ? panal.bline + 1 : 1;
    var lbline = nanal ? nanal.bline : 0;
    if (nanal && nanal.aline <= 1) {
        return;
    }
    var args = {file: (panal || nanal).file, fromline: pbline};
    if (lbline) {
        args.linecount = lbline - pbline;
    }
    $.ajax(hoturl("api/blob", hoturl_gradeparts(this, args)), {
        success: function (data) {
            if (data.ok && data.data) {
                var lines = data.data.replace(/\n$/, "").split("\n");
                for (var i = lines.length - 1; i >= 0; --i) {
                    var t = '<div class="pa-dl pa-gc"><div class="pa-da" data-landmark="' +
                        (paline + i) + '"></div><div class="pa-db" data-landmark="' +
                        (pbline + i) + '"></div><div class="pa-dd"></div></div>';
                    $(t).insertAfter(contextrow).find(".pa-dd").text(lines[i]);
                }
                $(contextrow).remove();
            }
        }
    });
    return true;
}

handle_ui.on("pa-gx", expand);
})($);

/*
var pa_observe_diff = (function () {
var observers = new WeakMap;
function make_observer_fn(ds) {
    var tops = [], top = null;
    return function (entries) {
        for (var i = 0; i !== entries.length; ++i) {
            var e = entries[i], p = tops.indexOf(e.target);
            if (e.isIntersecting && p < 0) {
                tops.push(e.target);
            } else if (!e.isIntersecting && p >= 0) {
                tops.splice(p, 1);
            }
        }
        tops.sort(function (a, b) {
            return a.offsetTop < b.offsetTop ? -1 : 1;
        });
        if (tops.length && tops[0] !== top) {
            top = tops[0];
            var e = top, t = top.getAttribute("data-pa-file");
            while (e && (e = e.parentElement.closest(".pa-diffcontext"))) {
                if (e.hasAttribute("data-pa-diffcontext"))
                    t = e.getAttribute("data-pa-diffcontext") + "/" + t;
                else
                    t = e.getAttribute("data-pa-user") + "/" + t;
            }
            $(ds).find(".pa-diffbar-top").removeClass("hidden").text(t);
        }
    };
}
return function () {
    if (!this || this === window || this === document) {
        $(".need-pa-observe-diff").each(pa_observe_diff);
    } else {
        removeClass(this, "need-pa-observe-diff");
        var ds = this.closest(".pa-diffset");
        if (ds && window.IntersectionObserver) {
            if (!observers.has(ds)) {
                observers.set(ds, new IntersectionObserver(make_observer_fn(ds), {threshold: 0.01}));
            }
            observers.get(ds).observe(this);
        }
    }
};
})();
$(pa_observe_diff);
*/


var pa_grade_types = {};

function pa_add_grade_type(name, rest) {
    rest.type = name;
    rest.tcell = rest.tcell || rest.text;
    pa_grade_types[name] = rest;
}

function pa_render_editable_entry(id, opts) {
    var t = '<div class="pa-pd"><span class="pa-gradewidth"><input type="text" class="uich pa-gradevalue pa-gradewidth" name="'.concat(this.key, '" id="', id, '"></span> <span class="pa-gradedesc">');
    if (opts.max_text) {
        t += opts.max_text;
    } else if (this.max) {
        t += 'of ' + this.max;
    }
    return t + '</span></div>';
}

pa_add_grade_type("numeric", {
    text: function (v) { return v == null ? "" : v + ""; },
    entry: pa_render_editable_entry
});

pa_add_grade_type("formula", {
    text: function (v) { return v == null ? "" : v.toFixed(1); }
});

pa_add_grade_type("text", {
    text: function (v) { return v == null ? "" : v; },
    entry: function (id, opts) {
        return '<div class="pa-pd"><textarea class="uich pa-pd pa-gradevalue need-autogrow" name="'.concat(this.key, '" id="', id, '"></textarea></div>');
    },
    justify: "left",
    sort: "forward"
});

pa_add_grade_type("select", {
    text: function (v) { return v == null ? "" : v; },
    entry: function (id, opts) {
        var t = '<div class="pa-pd"><span class="select"><select class="uich pa-gradevalue" name="'.concat(this.key, '" id="', id, '"><option value="">None</option>');
        for (var i = 0; i !== this.options.length; ++i) {
            var n = escape_entities(this.options[i]);
            t = t.concat('<option value="', n, '">', n, '</option>');
        }
        return t + '</select></span></div>';
    },
    justify: "left",
    sort: "forward"
});

pa_add_grade_type("checkbox", {
    text: function (v, nopretty) {
        if (nopretty)
            return v + "";
        else if (v == null || v === 0)
            return "–";
        else if (v == (this.max || 1))
            return "✓";
        else
            return v + "";
    },
    tcell: function (v) {
        if (v == null || v === 0)
            return "";
        else if (v == (this.max || 1))
            return "✓";
        else
            return v + "";
    },
    entry: function (id, opts) {
        var t = '<div class="pa-pd"><span class="pa-gradewidth"><input type="checkbox" class="ui pa-gradevalue ml-0" name="'.concat(this.key, '" id="', id, '" value="', this.max, '"></span>');
        if (opts.editable) {
            t = t.concat(' <span class="pa-gradedesc">of ', this.max, ' <a href="" class="x ui pa-grade-uncheckbox" tabindex="-1">#</a></span>');
        }
        return t + '</div>';
    },
    justify: "center",
    reflect_value: function (elt, v, opts) {
        var want_checkbox = v == null || v === "" || v === 0 || (this && v === this.max);
        if (!want_checkbox && elt.type === "checkbox") {
            pa_grade_uncheckbox.call(elt);
        } else if (want_checkbox && elt.type !== "checkbox" && opts.reset) {
            pa_grade_recheckbox.call(elt);
        }
        if (elt.type === "checkbox") {
            elt.checked = !!v;
            elt.indeterminate = opts.mixed;
        } else if (elt.value !== v && (opts.reset || !$(elt).is(":focus")))
            elt.value = v + "";
    }
});

(function () {
function make_checkboxlike(str) {
    return {
        text: function (v, nopretty) {
            if (nopretty)
                return v + "";
            else if (v == null || v === 0)
                return "–";
            else if (v > 0 && Math.abs(v - Math.round(v)) < 0.05)
                return str.repeat(Math.round(v));
            else
                return v + "";
        },
        tcell: function (v) {
            if (v == null || v === 0)
                return "";
            else if (v > 0 && Math.abs(v - Math.round(v)) < 0.05)
                return str.repeat(Math.round(v));
            else
                return v + "";
        },
        entry: function (id, opts) {
            var t = '<div class="pa-pd"><span class="pa-gradewidth"><input type="hidden" class="uich pa-gradevalue" name="'.concat(this.key, '">');
            for (var i = 0; i < this.max; ++i) {
                t = t.concat('<input type="checkbox" class="ui js-checkboxes-grade ml-0" name="', this.key, ':', i, '" value="1"');
                if (i === this.max - 1)
                    t += ' id="' + id + '"';
                t += '>';
            }
            t += '</span>';
            if (opts.editable) {
                t += ' <span class="pa-gradedesc">of ' + this.max +
                    ' <a href="" class="x ui pa-grade-uncheckbox" tabindex="-1">#</a></span>';
            }
            return t + '</div>';
        },
        justify: "left",
        reflect_value: function (elt, v, opts) {
            var want_checkbox = v == null || v === "" || v === 0
                || (v >= 0 && (!this || v <= this.max) && Math.abs(v - Math.round(v)) < 0.05);
            if (!want_checkbox && elt.type === "hidden") {
                pa_grade_uncheckbox.call(elt);
            } else if (want_checkbox && elt.type !== "hidden" && opts.reset) {
                pa_grade_recheckbox.call(elt);
            }
            if (elt.value !== v && (opts.reset || !$(elt).is(":focus")))
                elt.value = v;
            var name = elt.name + ":", value = Math.round(v || 0);
            $(elt.closest(".pa-pd")).find("input[type=checkbox]").each(function () {
                if (this.name.startsWith(name)) {
                    var i = +this.name.substring(name.length);
                    this.checked = i < value;
                    this.indeterminate = opts.mixed;
                }
            });
        }
    };
}

pa_add_grade_type("checkboxes", make_checkboxlike("✓"));
pa_add_grade_type("stars", make_checkboxlike("⭐"));

handle_ui.on("js-checkboxes-grade", function (evt) {
    var colon = this.name.indexOf(":"),
        name = this.name.substring(0, colon),
        num = this.name.substring(colon + 1),
        elt = this.closest("form").elements[name],
        v = this.checked ? (+num + 1) + "" : num;
    if (elt.value !== v) {
        elt.value = v;
        $(elt).trigger("change");
    }
});
})();

pa_add_grade_type("section", {
    text: function () { return ""; },
    entry: function () { return ""; }
});

(function () {
var lm = {
    98: "A+", 95: "A", 92: "A-", 88: "B+", 85: "B", 82: "B-",
    78: "C+", 75: "C", 72: "C-", 68: "D+", 65: "D", 62: "D-", 50: "F"
};
pa_add_grade_type("letter", {
    text: function (v) { return v == null ? "" : lm[v] || v + ""; },
    entry: function (id, opts) {
        opts.max_text = "letter grade";
        return pa_render_editable_entry.call(this, id, opts);
    },
    justify: "left",
    tics: function () {
        var a = [];
        for (var g in lm) {
            if (lm[g].length === 1)
                a.push({x: g, text: lm[g]});
        }
        for (var g in lm) {
            if (lm[g].length === 2)
                a.push({x: g, text: lm[g], label_space: 5});
        }
        for (var g in lm) {
            if (lm[g].length === 2)
                a.push({x: g, text: lm[g].substring(1), label_space: 2, notic: true});
        }
        return a;
    }
});
})();

function pa_render_grade_entry(ge, editable, live) {
    var t, name = ge.key, title = ge.title ? escape_entities(ge.title) : name,
        typeinfo = pa_grade_types[ge.type || "numeric"];
    if ((editable || ge.student) && typeinfo.entry) {
        live = live !== false;
        var opts = {editable: editable},
            id = "pa-ge" + ++pa_render_grade_entry.id_counter;
        t = (live ? '<form class="ui-submit ' : '<div class="') + 'pa-grade pa-p';
        if (ge.type === "section") {
            t += ' pa-p-section';
        }
        if (ge.visible === false) {
            t += ' pa-p-hidden';
        }
        t = t.concat('" data-pa-grade="', name);
        if (!live) {
            t = t.concat('" data-pa-grade-type="', typeinfo.type);
        }
        t = t.concat('"><label class="pa-pt" for="', id, '">', title, '</label>');
        if (ge.edit_description) {
            t += '<div class="pa-pdesc">' + escape_entities(ge.edit_description) + '</div>';
        }
        t += typeinfo.entry.call(ge, id, opts) + (live ? '</form>' : '</div>');
    } else {
        t = '<div class="pa-grade pa-p';
        if (ge.type === "section") {
            t += ' pa-p-section';
        }
        t += '" data-pa-grade="' + name + '">' +
            '<div class="pa-pt">' + title + '</div>';
        if (ge.type === "text") {
            t += '<div class="pa-pd pa-gradevalue"></div>';
        } else {
            t += '<div class="pa-pd"><span class="pa-gradevalue pa-gradewidth"></span>';
            if (ge.max && ge.type !== "letter") {
                t += ' <span class="pa-gradedesc">of ' + ge.max + '</span>';
            }
            t += '</div>';
        }
        t += '</div>';
    }
    return t;
}
pa_render_grade_entry.id_counter = 0;

function pa_grade_uncheckbox() {
    var ge = pa_grade_entry.call(this);
    if (this.type === "checkbox")
        this.value = this.checked ? ge.max : "";
    this.type = "text";
    this.className = (hasClass(this, "ui") || hasClass(this, "uich") ? "uich " : "") + "pa-gradevalue pa-gradewidth";
    var container = this.closest(".pa-pd");
    $(container).find(".pa-grade-uncheckbox").remove();
    $(container).find("input[name^=\"" + this.name + ":\"]").addClass("hidden");
}
handle_ui.on("pa-grade-uncheckbox", function () {
    $(this.closest(".pa-pd")).find(".pa-gradevalue").each(function () {
        pa_grade_uncheckbox.call(this);
        this.focus();
        this.select();
    });
});

function pa_grade_recheckbox() {
    var v = this.value.trim(), ge = pa_grade_entry.call(this);
    this.type = "checkbox";
    this.checked = v !== "" && v !== "0";
    this.value = ge.max;
    this.className = (hasClass(this, "uich") ? "ui " : "") + "pa-gradevalue";
    $(this.closest(".pa-pd")).find(".pa-gradedesc").append(' <a href="" class="x ui pa-grade-uncheckbox" tabindex="-1">#</a>');
}

function pa_set_grade(ge, g, ag, options) {
    var $g = $(this),
        $v = $g.find(".pa-gradevalue"),
        editable = $v[0] && $v[0].tagName !== "SPAN" && $v[0].tagName !== "DIV",
        typeinfo = pa_grade_types[ge.type || "numeric"];

    // “grade is above max” message
    if (ge.max && editable) {
        if (!g || g <= ge.max) {
            $g.find(".pa-gradeabovemax").remove();
        } else if (!$g.find(".pa-gradeabovemax").length) {
            $g.find(".pa-pd").after('<div class="pa-pd pa-gradeabovemax">Grade is above max</div>');
        }
    }

    // “autograde differs” message
    if (editable) {
        if (ag == null || g === ag) {
            $g.find(".pa-gradediffers").remove();
        } else {
            var txt = (ge.key === "late_hours" ? "auto-late hours" : "autograde") +
                " is " + typeinfo.text.call(ge, ag);
            if (!$g.find(".pa-gradediffers").length) {
                $g.find(".pa-pd").first().append('<span class="pa-gradediffers"></span>');
            }
            var $ag = $g.find(".pa-gradediffers");
            if ($ag.text() !== txt) {
                $ag.text(txt);
            }
        }
    }

    // actual grade value
    var gt;
    if (editable) {
        gt = g == null ? "" : typeinfo.text.call(ge, g, true);
        if (typeinfo.reflect_value)
            typeinfo.reflect_value.call(ge, $v[0], g, options || {});
        else if ($v.val() !== gt) {
            if (!$v.is(":focus") || (options && options.reset))
                $v.val(gt);
        }
        if ($v[0].hasAttribute("placeholder")) {
            $v[0].removeAttribute("placeholder");
            if (typeinfo.type === "select"
                && $v[0].options[0].value === "")
                $v[0].remove(0);
        }
        if ($v[0].type === "checkbox") {
            $v[0].checked = !!g;
            $v[0].indeterminate = options && options.mixed;
        } else if ($v.val() !== gt) {
            if (!$v.is(":focus") || (options && options.reset)) {
                $v.val(gt);
            }
        }
        if (options && options.reset) {
            $v[0].setAttribute("data-default-value", g == null ? "" : typeinfo.text.call(ge, g, true));
            if (options.mixed) {
                $v[0].setAttribute("placeholder", "Mixed");
                if (typeinfo.type === "select") {
                    $v.prepend('<option value="">Mixed</option>');
                    $v[0].selectedIndex = 0;
                }
            }
        }
    } else {
        gt = typeinfo.text.call(ge, g, false);
        if ($v.text() !== gt) {
            $v.text(gt);
        }
        toggleClass(this, "hidden", gt === "" && !ge.max && ge.type !== "section");
    }

    // maybe add landmark reference
    if (ge.landmark
        && this.parentElement
        && hasClass(this.parentElement, "want-pa-landmark-links")) {
        var m = /^(.*):(\d+)$/.exec(ge.landmark),
            $line = $(pa_ensureline(m[1], "a" + m[2])),
            want_gbr = "";
        if ($line.length) {
            var $pi = $(this).closest(".pa-psetinfo"),
                directory = $pi[0].getAttribute("data-pa-directory") || "";
            if (directory && m[1].substr(0, directory.length) === directory) {
                m[1] = m[1].substr(directory.length);
            }
            want_gbr = '@<a href="#' + $line[0].id + '" class="ui pa-goto">' + escape_entities(m[1] + ":" + m[2]) + '</a>';
        }
        var $pgbr = $g.find(".pa-gradeboxref");
        if (!$line.length) {
            $pgbr.remove();
        } else if (!$pgbr.length || $pgbr.html() !== want_gbr) {
            $pgbr.remove();
            $g.find(".pa-pd").first().append('<span class="pa-gradeboxref">' + want_gbr + '</span>');
        }
    }
}

handle_ui.on("pa-gradevalue", function (evt) {
    var f = this.closest("form"), gt, typeinfo, self = this;
    if (f && hasClass(f, "pa-grade"))
        $(f).submit();
    else if (self.type === "hidden"
             && (gt = self.closest(".pa-grade").getAttribute("data-pa-grade-type"))
             && (typeinfo = pa_grade_types[gt]).reflect_value)
        setTimeout(function () { typeinfo.reflect_value.call(null, self, +self.value, {}) }, 0);
});

function pa_gradeinfo() {
    var e = this.closest(".pa-psetinfo"), gi = null;
    while (e) {
        var gix = $(e).data("pa-gradeinfo");
        if (typeof gix === "string") {
            gix = JSON.parse(gix);
            $(e).data("pa-gradeinfo", gix);
        }
        gi = gi ? $.extend(gi, gix) : gix;
        if (gi && gi.entries) {
            break;
        }
        e = e.parentElement.closest(".pa-psetinfo");
    }
    return gi;
}

function pa_grade_entry() {
    var e = this.closest(".pa-grade"),
        gi = pa_gradeinfo.call(e);
    return gi.entries[e.getAttribute("data-pa-grade")];
}



(function () {
function save_grade(self) {
    var $f = $(self);
    $f.find(".pa-gradediffers, .pa-save-message").remove();
    var $pd = $f.find(".pa-pd").first(),
        $gd = $pd.find(".pa-gradedesc");
    if (!$gd.length) {
        $($pd[0].firstChild).after(' <span class="pa-gradedesc"></span>');
        $gd = $pd.find(".pa-gradedesc");
    }
    $gd.append('<span class="pa-save-message"><span class="spinner"></span></span>');

    var gi = pa_gradeinfo.call(self), g = {}, og = {};
    $f.find("input.pa-gradevalue, textarea.pa-gradevalue, select.pa-gradevalue").each(function () {
        var ge = gi.entries[this.name];
        if (gi.grades && ge && gi.grades[ge.pos] != null) {
            og[this.name] = gi.grades[ge.pos];
        } else if (this.name === "late_hours" && gi.late_hours != null) {
            og[this.name] = gi.late_hours;
        }
        if ((this.type !== "checkbox" && this.type !== "radio")
            || this.checked) {
            g[this.name] = this.value;
        } else if (this.type === "checkbox") {
            g[this.name] = 0;
        }
    });

    $f.data("paOutstandingPromise", new Promise(function (resolve, reject) {
        pa_api_conditioner(hoturl_post("api/grade", hoturl_gradeparts($f[0])),
            {grades: g, oldgrades: og})
        .then(function (data) {
            $f.removeData("paOutstandingPromise");
            if (data.ok) {
                $f.find(".pa-save-message").html('<span class="savesuccess"></span>').addClass("fadeout");
                $(self).closest(".pa-psetinfo").data("pa-gradeinfo", data).each(pa_loadgrades);
                resolve(self);
            } else {
                $f.find(".pa-save-message").html('<strong class="err">' + data.error + '</strong>');
                reject(self);
            }
        });
    }));
}
handle_ui.on("pa-grade", function (event) {
    event.preventDefault();
    var p = $(this).data("paOutstandingPromise");
    if (p) {
        p.then(save_grade);
    } else {
        save_grade(this);
    }
});
})();

function pa_show_grade(gi) {
    gi = gi || pa_gradeinfo.call(this);
    var k = this.getAttribute("data-pa-grade"), ge = gi.entries[k];
    if (k === "late_hours") {
        pa_set_grade.call(this, {key: "late_hours"}, gi.late_hours, gi.auto_late_hours);
    } else if (ge) {
        pa_set_grade.call(this, ge, gi.grades ? gi.grades[ge.pos] : null, gi.autogrades ? gi.autogrades[ge.pos] : null);
    }
}

function pa_resolve_grade() {
    removeClass(this, "need-pa-grade");
    var k = this.getAttribute("data-pa-grade"),
        gi = pa_gradeinfo.call(this), ge;
    if (!gi || !k || !(ge = gi.entries[k])) {
        return;
    }
    $(this).html(pa_render_grade_entry(ge, gi.editable));
    $(this).find(".need-autogrow").autogrow();
    pa_show_grade.call($(this).find(".pa-grade")[0], gi);
    if (ge.landmark_range && this.closest(".pa-gradebox")) {
        // XXX maybe calling compute_landmark_range_grade too often
        pa_compute_landmark_range_grade.call(this.firstChild, ge);
    }
    if (this.hasAttribute("data-pa-landmark-buttons")) {
        var lb = JSON.parse(this.getAttribute("data-pa-landmark-buttons"));
        for (var i = 0; i < lb.length; ++i) {
            if (typeof lb[i] === "string") {
                $(this).find(".pa-pd").first().append(lb[i]);
            } else if (lb[i].className) {
                $(this).find(".pa-pd").first().append('<button type="button" class="btn uic uikd pa-grade-button" data-pa-grade-button="' + lb[i].className + '">' + lb[i].title + '</button>');
            }
        }
    }
}

handle_ui.on("pa-grade-button", function (event) {
    if (event.type === "keydown") {
        var k = event_key(event), gr;
        if (event.ctrlKey
            && (k === "n" || k === "N" || k === "p" || k === "P" || k === "A")
            && (gr = this.closest(".pa-grade"))) {
            var gradekey = gr.getAttribute("data-pa-grade"),
                buttons = $(".pa-grade[data-pa-grade=" + gradekey + "] .pa-grade-button[data-pa-grade-button=" + this.getAttribute("data-pa-grade-button") + "]").filter(":visible"),
                i = buttons.toArray().indexOf(this);
            if (i >= 0) {
                if (k === "n" || k === "N" || k === "p" || k === "P") {
                    var delta = k === "n" || k === "N" ? 1 : buttons.length - 1,
                        button = buttons[(i + delta) % buttons.length],
                        dg = button.closest(".pa-dg");
                    dg && $(dg).scrollIntoView({marginTop: 24, atTop: true});
                    button.focus();
                    $(button).scrollIntoView();
                } else {
                    buttons.click();
                }
                event.preventDefault();
            }
        }
    } else if (event.type === "click") {
        handle_ui.trigger.call(this, this.getAttribute("data-pa-grade-button"), event);
    }
});

function pa_resolve_gradelist() {
    removeClass(this, "need-pa-gradelist");
    addClass(this, "pa-gradelist");
    var pi = this.closest(".pa-psetinfo"), gi = pa_gradeinfo.call(pi);
    if (!gi) {
        return;
    }
    var ch = this.firstChild;
    while (ch && !hasClass(ch, "pa-grade")) {
        ch = ch.nextSibling;
    }
    for (var i = 0; i !== gi.order.length; ++i) {
        var k = gi.order[i];
        if (k) {
            if (ch && ch.getAttribute("data-pa-grade") === k) {
                ch = ch.nextSibling;
            } else {
                var e = $(pa_render_grade_entry(gi.entries[k], gi.editable))[0];
                this.insertBefore(e, ch);
                pa_show_grade.call(e, gi);
            }
        }
    }
    while (ch) {
        var e = ch;
        ch = ch.nextSibling;
        this.removeChild(e);
    }
    $(this).find(".need-autogrow").autogrow();
}

$(function () {
    $(".need-pa-grade").each(pa_resolve_grade);
    $(".need-pa-gradelist").each(pa_resolve_gradelist);
});

function pa_render_total(gi, tm) {
    var t = '<div class="pa-total pa-p', ne = 0;
    for (var k in gi.entries) {
        if (gi.entries[k].type !== "text"
            && gi.entries[k].type !== "select"
            && gi.entries[k].type !== "section")
            ++ne;
    }
    if (ne <= 1) {
        t += ' hidden';
    }
    return t + '"><div class="pa-pt">total</div>' +
        '<div class="pa-pd"><span class="pa-gradevalue pa-gradewidth"></span> ' +
        '<span class="pa-gradedesc">of ' + tm[1] + '</span></div></div>';
}

function pa_loadgrades() {
    if (!hasClass(this, "pa-psetinfo")) {
        throw new Error("bad pa_loadgrades");
    }
    var gi = pa_gradeinfo.call(this);
    if (!gi || !gi.order) {
        return;
    }

    $(this).find(".need-pa-grade").each(function () {
        pa_resolve_grade.call(this, true);
    });

    $(this).find(".pa-gradelist").each(function () {
        pa_resolve_gradelist.call(this, true);
    });

    $(this).find(".pa-grade").each(function () {
        var k = this.getAttribute("data-pa-grade"),
            ge = gi.entries[k];
        if (k === "late_hours") {
            pa_set_grade.call(this, {key: "late_hours"}, gi.late_hours, gi.auto_late_hours);
        } else if (ge) {
            pa_set_grade.call(this, ge, gi.grades ? gi.grades[ge.pos] : null, gi.autogrades ? gi.autogrades[ge.pos] : null);
        }
    });

    // print totals
    var tm = pa_gradeinfo_total(gi), total = "" + tm[0], drawgraph = false;
    if (tm[0]) {
        $(this).find(".pa-gradelist:not(.pa-gradebox)").each(function () {
            var $t = $(this).find(".pa-total");
            $t.length || $(this).prepend(pa_render_total(gi, tm));
        });
    }
    $(this).find(".pa-total").each(function () {
        var $v = $(this).find(".pa-gradevalue");
        if ($v.text() !== total) {
            $v.text(total);
            drawgraph = true;
        }
    });
    if (drawgraph) {
        pa_draw_gradecdf($(this).find(".pa-grgraph"));
    }
}

function pa_process_landmark_range(lnfirst, lnlast, func, selector) {
    var lna = -1, lnb = -1, tr = this;
    if (typeof lnfirst === "function") {
        func = lnfirst;
        selector = lnlast;
        var ge = pa_grade_entry.call(this),
            m = ge && ge.landmark_range ? /:(\d+):(\d+)$/.exec(ge.landmark_range) : null;
        if (!m || !(tr = tr.closest(".pa-filediff"))) {
            return null;
        }
        lnfirst = +m[1];
        lnlast = +m[2];
    }
    while ((tr = pa_diff_traverse(tr, true, 3))) {
        var td = tr.firstChild;
        if (td.hasAttribute("data-landmark")) {
            lna = +td.getAttribute("data-landmark");
        }
        td = td.nextSibling;
        if (td && td.hasAttribute("data-landmark")) {
            lnb = +td.getAttribute("data-landmark");
        }
        if (lna >= lnfirst
            && lna <= lnlast
            && (!selector || tr.matches(selector))) {
            func.call(this, tr, lna, lnb);
        }
    }
}

function pa_compute_landmark_range_grade(ge, allow_save) {
    var gr = this.closest(".pa-grade"),
        title = $(gr).find(".pa-pt").html(),
        sum = null;
    if (!ge) {
        ge = pa_grade_entry.call(gr);
    }

    pa_process_landmark_range.call(this, function (tr, lna, lnb) {
        var note = pa_note(tr), m, gch;
        if (note[1]
            && ((m = /^[\s❮→]*(\+)(\d+(?:\.\d+)?|\.\d+)((?![.,]\w|[\w%$*])\S*?)[.,;:❯]?(?:\s|$)/.exec(note[1]))
                || (m = /^[\s❮→]*()(\d+(?:\.\d+)?|\.\d+)(\/[\d.]+(?![.,]\w|[\w%$*\/])\S*?)[.,;:❯]?(?:\s|$)/.exec(note[1])))) {
            if (sum === null) {
                sum = 0.0;
            }
            sum += parseFloat(m[2]);
            gch = title + ": " + escape_entities(m[1]) + "<b>" + escape_entities(m[2]) + "</b>" + escape_entities(m[3]);
        }
        var $nd = $(tr).find(".pa-note-gradecontrib");
        if (!$nd.length && gch) {
            $nd = $('<div class="pa-note-gradecontrib"></div>').insertBefore($(tr).find(".pa-note"));
        }
        gch ? $nd.html(gch) : $nd.remove();
    }, ".pa-gw");

    if (ge.round && sum != null) {
        if (ge.round === "up") {
            sum = Math.ceil(sum);
        } else if (ge.round === "down") {
            sum = Math.floor(sum);
        } else {
            sum = Math.round(sum);
        }
    }

    var $gnv = $(this).find(".pa-notes-grade");
    if (sum === null) {
        $gnv.remove();
    } else {
        if (!$gnv.length) {
            $gnv = $('<a class="uic uikd pa-notes-grade" href=""></a>');
            var e = this.lastChild.firstChild;
            while (e && (e.nodeType !== 1 || hasClass(e, "pa-gradewidth") || hasClass(e, "pa-gradedesc"))) {
                e = e.nextSibling;
            }
            this.firstChild.nextSibling.insertBefore($gnv[0], e);
        }
        $gnv.text("Notes grade " + sum);
    }

    var gv = $(this).find(".pa-gradevalue")[0];
    if (gv) {
        var sums = sum === null ? "" : "" + sum, gval;
        if (allow_save
            && (gval = $(gv).val()) == gv.getAttribute("data-pa-notes-grade")
            && sums != gval) {
            $(gv).val(sums).change();
        }
        gv.setAttribute("data-pa-notes-grade", sums);
    }

    return sum;
}

handle_ui.on("pa-notes-grade", function (event) {
    if (event.type === "keydown") {
        var k = event_key(event), gr;
        if (event.ctrlKey
            && (k === "n" || k === "N" || k === "p" || k === "P" || k === "A")
            && (gr = this.closest(".pa-grade"))) {
            var gradekey = gr.getAttribute("data-pa-grade"),
                links = $(".pa-grade[data-pa-grade=" + gradekey + "] .pa-notes-grade").filter(":visible"),
                i = links.toArray().indexOf(this);
            if (i >= 0) {
                if (k === "n" || k === "N" || k === "p" || k === "P") {
                    var delta = k === "n" || k === "N" ? 1 : links.length - 1,
                        link = links[(i + delta) % links.length],
                        dg = link.closest(".pa-dg");
                    dg && $(dg).scrollIntoView({marginTop: 24, atTop: true});
                    link.focus();
                    $(link).scrollIntoView();
                } else {
                    links.click();
                }
                event.preventDefault();
            }
        }
    } else if (event.type === "click") {
        var $gv = $(this).closest(".pa-grade").find(".pa-gradevalue");
        if ($gv.length
            && $gv.val() != $gv.attr("data-pa-notes-grade")) {
            $gv.val($gv.attr("data-pa-notes-grade")).change();
        }
        event.preventDefault();
    }
});


handle_ui.on("pa-show-run", function () {
    var parent = this.closest(".pa-runout"),
        name = parent.id.substring(10),
        therun = document.getElementById("pa-run-" + name),
        thebutton;
    if (therun.dataset.paTimestamp && !$(therun).is(":visible")) {
        thebutton = jQuery(".pa-runner[value='" + name + "']")[0];
        pa_run(thebutton, {unfold: true});
    } else {
        fold61(therun, jQuery("#pa-runout-" + name));
    }
});


function pa_ensureline_promise(filename, lineid) {
    // decode arguments: either (lineref) or (filename, lineid)
    if (lineid == null) {
        if (filename instanceof Node) {
            filename = filename.hash;
        }
        var m = filename.match(/^#?L([ab]\d+)_(.*)$/);
        if (!m) {
            return Promise.reject(null);
        }
        filename = m[2];
        lineid = m[1];
    } else {
        if (filename instanceof Node) {
            var node = filename;
            while (node && !node.hasAttribute("data-pa-file")) {
                node = node.parentElement;
            }
            if (!node) {
                return Promise.reject(null);
            }
            filename = node.getAttribute("data-pa-file");
            if (node.hasAttribute("data-pa-file-user")) {
                filename = node.getAttribute("data-pa-file-user") + "-" + filename;
            }
        }
        filename = html_id_encode(filename);
    }

    // check lineref
    var lineref = "L" + lineid + "_" + filename;
    var e = document.getElementById(lineref);
    if (e) {
        return new ImmediatePromise(e);
    }

    // create link
    var filee = document.getElementById("pa-file-" + filename);
    if (filee) {
        return filediff_load(filee).then(_ => {
            // look for present line
            const $tds = $(filee).find(".pa-d" + lineid.charAt(0)),
                lineno = lineid.substr(1);
            for (let i = 0; i < $tds.length; ++i) {
                if ($tds[i].getAttribute("data-landmark") === lineno) {
                    $tds[i].id = lineref;
                    return $tds[i].id;
                }
            }
            // XXX missing: expand context lines
            // look for absent line with present linenote
            const $dls = $(filee).find(".pa-dl[data-landmark='" + lineid + "']");
            if ($dls.length)
                return $dls[0];
            // give up
            throw null;
        });
    } else {
        return Promise.reject(null);
    }
}

function pa_ensureline(filename, lineid) {
    var e = null;
    pa_ensureline_promise(filename, lineid).then(ee => e = ee);
    return e;
}

handle_ui.on("pa-goto", function () {
    $(".pa-line-highlight").removeClass("pa-line-highlight");
    pa_ensureline_promise(this, null).then(ref => {
        $(ref).closest(".pa-filediff").removeClass("hidden");
        let $e = $(ref).closest(".pa-dl");
        $e.addClass("pa-line-highlight");
        window.scrollTo(0, Math.max($e.geometry().top - Math.max(window.innerHeight * 0.1, 24), 0));
        push_history_state(this.href);
    }, null);
});

function pa_beforeunload(evt) {
    var ok = true;
    $(".pa-gw textarea").each(function () {
        var tr = this.closest(".pa-dl"), note = pa_note(tr);
        if (note && !text_eq(this.value, note[1]) && !hasClass(tr, "pa-save-failed"))
            ok = false;
    });
    if (!ok)
        return (event.returnValue = "You have unsaved notes. You will lose them if you leave the page now.");
}

function pa_fetchgrades() {
    var p = this.closest(".pa-psetinfo");
    pa_api_conditioner(hoturl("api/grade", hoturl_gradeparts(p)), null, "GET")
        .then(function (data) {
            if (data && data.ok) {
                $(p).data("pa-gradeinfo", data).each(pa_loadgrades);
            }
        });
}

handle_ui.on("pa-pset-setgrader", function () {
    var $form = $(this.closest("form"));
    jQuery.ajax($form[0].getAttribute("action"), {
        data: $form.serializeWith({}),
        type: "POST", cache: false,
        dataType: "json",
        success: function (data) {
            var a;
            $form.find(".ajaxsave61").html(data.ok ? "Saved" : "<span class='error'>Error: " + data.error + "</span>");
            if (data.ok && (a = $form.find("a.actas")).length)
                a.attr("href", a.attr("href").replace(/actas=[^&;]+/, "actas=" + encodeURIComponent(data.grader_email)));
        },
        error: function () {
            $form.find(".ajaxsave61").html("<span class='error'>Failed</span>");
        }
    });
});

handle_ui.on("pa-flag", function () {
    var $b = $(this), $form = $b.closest("form");
    if (this.name == "flag" && !$form.find("[name=flagreason]").length) {
        $b.before('<span class="flagreason">Why do you want to flag this commit? &nbsp;<input type="text" name="flagreason" value="" placeholder="Optional reason" /> &nbsp;</span>');
        $form.find("[name=flagreason]").on("keypress", make_onkey("Enter", function () { $b.click(); })).autogrow()[0].focus();
        $b.html("OK");
    } else if (this.name == "flag") {
        $.ajax($form.attr("action"), {
            data: $form.serializeWith({flag: 1}),
            type: "POST", cache: false,
            dataType: "json",
            success: function (data) {
                if (data && data.ok) {
                    $form.find(".flagreason").remove();
                    $b.replaceWith("<strong>Flagged</strong>");
                }
            },
            error: function () {
                $form.find(".ajaxsave61").html("<span class='error'>Failed</span>");
            }
        });
    } else if (this.name == "resolveflag") {
        $.ajax($form.attr("action"), {
            data: $form.serializeWith({resolveflag: 1, flagid: $b.attr("data-flagid")}),
            type: "POST", cache: false,
            dataType: "json",
            success: function (data) {
                if (data && data.ok)
                    $b.replaceWith("<strong>Resolved</strong>");
            },
            error: function () {
                $form.find(".ajaxsave61").html("<span class='error'>Failed</span>");
            }
        })
    }
});

var pa_render_terminal = (function () {
var styleset = {
    "0": false, "1": {b: true}, "2": {f: true}, "3": {i: true},
    "4": {u: true}, "5": {bl: true}, "7": {rv: true}, "8": {x: true},
    "9": {s: true}, "21": {du: true}, "22": {b: false, f: false},
    "23": {i: false}, "24": {u: false}, "25": {bl: false}, "27": {rv: false},
    "28": {x: false}, "29": {s: false}, "30": {fg: 0}, "31": {fg: 1},
    "32": {fg: 2}, "33": {fg: 3}, "34": {fg: 4}, "35": {fg: 5},
    "36": {fg: 6}, "37": {fg: 7}, "38": "fg", "39": {fg: false},
    "40": {bg: 0}, "41": {bg: 1}, "42": {bg: 2}, "43": {bg: 3},
    "44": {bg: 4}, "45": {bg: 5}, "46": {bg: 6}, "47": {bg: 7},
    "48": "bg", "49": {bg: false}, "90": {fg: 8}, "91": {fg: 9},
    "92": {fg: 10}, "93": {fg: 11}, "94": {fg: 12}, "95": {fg: 13},
    "96": {fg: 14}, "97": {fg: 15},
    "100": {bg: 8}, "101": {bg: 9}, "102": {bg: 10}, "103": {bg: 11},
    "104": {bg: 12}, "105": {bg: 13}, "106": {bg: 14}, "107": {bg: 15}
};
var styleback = {
    "b": 1, "f": 2, "i": 3, "u": 4, "bl": 5, "rv": 7, "x": 8, "s": 9,
    "du": 21
};

function parse_styles(dst, style) {
    var a;
    if (arguments.length === 1) {
        style = dst;
        dst = null;
    }
    if (!style || style === "\x1b[m" || style === "\x1b[0m")
        return null;
    if (style.charAt(0) === "\x1b")
        a = style.substring(2, style.length - 1).split(";");
    else
        a = style.split(";");
    for (var i = 0; i < a.length; ++i) {
        var cmp = styleset[parseInt(a[i])];
        if (cmp === false)
            dst = null;
        else if (!cmp)
            /* do nothing */;
        else if (typeof cmp === "object") {
            for (var j in cmp) {
                if (cmp[j] !== false) {
                    dst = dst || {};
                    dst[j] = cmp[j];
                } else if (dst)
                    delete dst[j];
            }
        } else if (cmp === "fg" || cmp === "bg") {
            var r, g, b;
            dst = dst || {};
            if (i + 4 < a.length && parseInt(a[i+1]) === 2) {
                r = parseInt(a[i+2]);
                g = parseInt(a[i+3]);
                b = parseInt(a[i+4]);
                if (r <= 255 && g <= 255 && b <= 255)
                    dst[cmp] = [r, g, b];
            } else if (i + 2 < a.length && parseInt(a[i+1]) === 5) {
                var c = parseInt(a[i+1]);
                if (c <= 15)
                    dst[cmp] = c;
                else if (c <= 0xe7) {
                    b = (c - 16) % 6;
                    g = ((c - 16 - b) / 6) % 6;
                    r = (c - 16 - b - 6 * g) / 36;
                    dst[cmp] = [r * 51, g * 51, b * 51];
                } else if (c <= 255) {
                    b = Math.round((c - 0xe8) * 255 / 23);
                    dst[cmp] = [b, b, b];
                }
            }
        }
    }
    return dst && $.isEmptyObject(dst) ? null : dst;
}

function unparse_styles(dst) {
    if (!dst)
        return "\x1b[m";
    var a = [];
    for (var key in styleback)
        if (dst[key])
            a.push(styleback[key]);
    if (dst.fg) {
        if (typeof dst.fg === "number")
            a.push(dst.fg < 8 ? 30 + dst.fg : 90 + dst.fg - 8);
        else
            a.push(38, 2, dst.fg[0], dst.fg[1], dst.fg[2]);
    }
    if (dst.bg) {
        if (typeof dst.bg === "number")
            a.push(dst.bg < 8 ? 40 + dst.bg : 100 + dst.bg - 8);
        else
            a.push(48, 2, dst.bg[0], dst.bg[1], dst.bg[2]);
    }
    return "\x1b[" + a.join(";") + "m";
}

function style_text(text, style) {
    if (typeof text === "string")
        text = document.createTextNode(text);
    else if (text instanceof jQuery)
        text = text[0];
    if (!style || style === "\x1b[m"
        || (typeof style === "string" && !(style = parse_styles(style))))
        return text;
    var node = document.createElement("span");
    var cl = [];
    for (var key in styleback)
        if (style[key])
            cl.push("ansi" + key);
    if (style.fg) {
        if (typeof style.fg === "number")
            cl.push("ansifg" + style.fg);
        else
            node.styles.foregroundColor = sprintf("#%02x%02x%02x", style.fg[0], style.fg[1], style.fg[2]);
    }
    if (style.bg) {
        if (typeof style.bg === "number")
            cl.push("ansibg" + style.bg);
        else
            node.styles.backgroundColor = sprintf("#%02x%02x%02x", style.bg[0], style.bg[1], style.bg[2]);
    }
    if (cl.length)
        node.className = cl.join(" ");
    node.appendChild(text);
    return node;
}

return function (container, string, options) {
    var return_html = false;
    if (typeof container === "string") {
        options = string;
        string = container;
        container = document.createElement("div");
        return_html = true;
    }

    if (options && options.clear) {
        container.removeAttribute("data-pa-terminal-style");
        container.removeAttribute("data-pa-outputpart");
        while (container.firstChild)
            container.removeChild(container.firstChild);
    }

    var styles = container.getAttribute("data-pa-terminal-style"),
        fragment = null;

    function addlinepart(node, text) {
        node.appendChild(style_text(text, styles));
    }

    function addfragment(node) {
        if (!fragment)
            fragment = document.createDocumentFragment();
        fragment.appendChild(node);
    }

    function ansi_combine(a1, a2) {
        if (/^\x1b\[[\d;]*m$/.test(a2))
            return unparse_styles(parse_styles(parse_styles(null, a1), a2));
        else
            return a1;
    }

    function ends_with(str, chr) {
        return str !== "" && str.charAt(str.length - 1) === chr;
    }

    function clean_cr(line) {
        var lineend = /\n$/.test(line);
        if (lineend && line.indexOf("\r") === line.length - 1)
            return line.substring(0, line.length - 2) + "\n";
        var curstyle = styles || "\x1b[m",
            parts = (lineend ? line.substr(0, line.length - 1) : line).split(/\r/),
            partno, i, m, r = [];
        for (partno = 0; partno < parts.length; ++partno) {
            var g = [], glen = 0, clearafter = null;
            var lsplit = parts[partno].split(/(\x1b\[[\d;]*m|\x1b\[0?K)/);
            for (var j = 0; j < lsplit.length; j += 2) {
                if (lsplit[j] !== "") {
                    g.push(curstyle, lsplit[j]);
                    glen += lsplit[j].length;
                }
                if (j + 1 < lsplit.length) {
                    if (ends_with(lsplit[j + 1], "K"))
                        clearafter = glen;
                    else
                        curstyle = ansi_combine(curstyle, lsplit[j + 1]);
                }
            }
            // glen: number of characters to overwrite
            var rpos = 0;
            while (rpos < r.length && glen >= r[rpos + 1].length) {
                glen -= r[rpos + 1].length;
                rpos += 2;
            }
            while (rpos < r.length && glen < r[rpos + 1].length && clearafter === null) {
                g.push(r[rpos], r[rpos + 1].substr(glen));
                glen = 0;
                rpos += 2;
            }
            r = g;
        }
        r.push(curstyle);
        lineend && r.push("\n");
        return r.join("");
    }

    function find_filediff(file) {
        return $(".pa-filediff").filter(function () {
            return this.getAttribute("data-pa-file") === file;
        });
    }

    function add_file_link(node, prefix, file, line, link) {
        var m;
        while ((m = file.match(/^(\x1b\[[\d;]*m|\x1b\[\d*K)([^]*)$/))) {
            styles = ansi_combine(styles, m[1]);
            file = m[2];
        }
        var filematch = find_filediff(file);
        if (!filematch.length && options && options.directory) {
            file = options.directory + file;
            filematch = find_filediff(file);
        }
        if (filematch.length) {
            if (prefix.length)
                addlinepart(node, prefix);
            var anchor = "Lb" + line + "_" + html_id_encode(file);
            var a = $("<a href=\"#" + anchor + "\" class=\"uu uic pa-goto\"></a>");
            a.text(link.substring(prefix.length).replace(/(?:\x1b\[[\d;]*m|\x1b\[\d*K)/g, ""));
            addlinepart(node, a);
            return true;
        }
        return false;
    }

    function render_line(line, node) {
        var m, filematch, a, i, x, isnew = !node, displaylen = 0;
        if (isnew)
            node = document.createElement("span");

        if (/\r/.test(line))
            line = clean_cr(line);

        while ((m = line.match(/^(\x1b\[[\d;]*m|\x1b\[\d*K)([^]*)$/))) {
            styles = ansi_combine(styles, m[1]);
            line = m[2];
        }

        if (((m = line.match(/^([ \t]*)([^:\s]+):(\d+)(?=:)/))
             || (m = line.match(/^([ \t]*)file \"(.*?)\", line (\d+)/i)))
            && add_file_link(node, m[1], m[2], m[3], m[0])) {
            displaylen = m[0].length;
            line = line.substr(displaylen);
        }

        var render;
        while (line !== "") {
            render = line;
            if ((m = line.match(/^(.*?)(\x1b\[[\d;]*m|\x1b\[\d*K)([^]*)$/))) {
                if (m[1] === "") {
                    styles = ansi_combine(styles, m[2]);
                    line = m[3];
                    continue;
                }
                render = m[1];
            }
            if (displaylen + render.length > 133
                || (displaylen + render.length == 133 && render.charAt(132) !== "\n")) {
                render = render.substr(0, 132 - displaylen);
                addlinepart(node, render);
                node.className = "pa-rl-continues";
                isnew && addfragment(node);
                node = document.createElement("span");
                isnew = true;
                displaylen = 0;
            } else {
                addlinepart(node, render);
                displaylen += render.length;
            }
            line = line.substr(render.length);
        }
        isnew && addfragment(node);
    }

    // hide newline on last line
    var lines, lastfull;
    if (typeof string === "string") {
        lines = string.split(/^/m);
        if (lines.length && lines[lines.length - 1] === "")
            lines.pop();
        lastfull = lines.length && ends_with(lines[lines.length - 1], "\n");
    } else {
        lines = [];
        lastfull = true;
        fragment = string;
    }

    var node = container.lastChild, cursor = null;
    if (node
        && node.lastChild
        && hasClass(node.lastChild, "pa-runcursor")) {
        cursor = node.lastChild;
        node.removeChild(cursor);
    }

    if (node
        && (string = node.getAttribute("data-pa-outputpart")) !== null
        && string !== ""
        && lines.length) {
        while (node.firstChild)
            node.removeChild(node.firstChild);
        lines[0] = string + lines[0];
        node.removeAttribute("data-pa-outputpart");
    } else {
        if (node && (lines.length || fragment)) {
            node.appendChild(document.createTextNode("\n"));
            node.removeAttribute("data-pa-outputpart");
        }
        node = null;
    }

    var laststyles = styles, i, j, last;
    for (i = 0; i < lines.length; i = j) {
        laststyles = styles;
        last = lines[i];
        for (j = i + 1; !ends_with(last, "\n") && j < lines.length; ++j)
            last += lines[j];
        if (j == lines.length && lastfull)
            last = last.substring(0, last.length - 1);
        render_line(last, i ? null : node);
    }

    if (options && options.cursor && !container.lastChild && !fragment)
        addfragment("");

    if (fragment)
        container.appendChild(fragment);

    var len = container.childNodes.length;
    if (len >= 4000) {
        i = container.firstChild;
        while (i.tagName === "DIV" && i.className === "pa-rl-group") {
            i = i.nextSibling;
            --len;
        }
        var div = null, divlen = 0;
        while (i && (j = i.nextSibling)) {
            if (!div
                || (divlen >= 4000 && len >= 2000)) {
                div = document.createElement("div");
                div.className = "pa-rl-group";
                container.insertBefore(div, i);
                divlen = 0;
            }
            container.removeChild(i);
            div.appendChild(i);
            i = j;
            ++divlen;
            --len;
        }
    }

    if (options && options.cursor) {
        if (!cursor) {
            cursor = document.createElement("span");
            cursor.className = "pa-runcursor";
        }
        container.lastChild.appendChild(cursor);
    }

    if (!lastfull && container.lastChild) {
        styles = laststyles;
        container.lastChild.setAttribute("data-pa-outputpart", last);
    }

    if (styles != null)
        container.setAttribute("data-pa-terminal-style", styles);
    else
        container.removeAttribute("data-pa-terminal-style");

    if (return_html)
        return container.innerHTML;
};
})();

function pa_run(button, opt) {
    var $f = $(button).closest("form"),
        category = button.getAttribute("data-pa-run-category") || button.value,
        directory = $(button).closest(".pa-psetinfo").attr("data-pa-directory"),
        therun = document.getElementById("pa-run-" + category),
        thepre = $(therun).find("pre"),
        thexterm,
        checkt;

    if (typeof opt !== "object")
        opt = {};
    if (opt.unfold && therun.dataset.paTimestamp)
        checkt = +therun.dataset.paTimestamp;
    else {
        if ($f.prop("outstanding"))
            return true;
        $f.find("button").prop("disabled", true);
        $f.prop("outstanding", true);
    }
    delete therun.dataset.paTimestamp;

    fold61(therun, jQuery("#pa-runout-" + category).removeClass("hidden"), true);
    if (!checkt && !opt.noclear) {
        thepre.html("");
        addClass(thepre[0].parentElement, "pa-run-short");
        thepre[0].removeAttribute("data-pa-terminal-style");
        $(therun).children(".pa-runrange").remove();
    } else if (therun.lastChild)
        $(therun.lastChild).find("span.pa-runcursor").remove();

    function terminal_char_width(min, max) {
        var x = $('<span style="position:absolute">0</span>').appendTo(thepre),
            w = Math.trunc(thepre.width() / x.width() / 1.33);
        x.remove();
        return Math.max(min, Math.min(w, max));
    }

    if (therun.dataset.paXtermJs
        && therun.dataset.paXtermJs !== "false"
        && window.Terminal) {
        removeClass(thepre[0].parentElement, "pa-run-short");
        addClass(thepre[0].parentElement, "pa-run-xterm-js");
        thexterm = new Terminal({cols: terminal_char_width(80, 132), rows: 25});
        thexterm.open(thepre[0]);
        thexterm.attachCustomKeyEventHandler(function (e) {
            if (e.type === "keydown") {
                var key = event_key(e), mod = event_modkey(e);
                if (key === "Enter" && !mod) {
                    key = "\r";
                } else if (key === "Escape" && !mod) {
                    key = "\x1B";
                } else if (key === "Backspace" && !mod) {
                    key = "\x08";
                } else if (key >= "a"
                           && key <= "z"
                           && (mod & 0xE) === event_modkey.CTRL) {
                    key = String.fromCharCode(key.charCodeAt(0) - 96);
                } else if (key.length !== 1
                           || (mod & 0xE) !== 0
                           || !event_key.printable(e)) {
                    key = "";
                }
                if (key !== "") {
                    write(key);
                }
            }
            return false;
        });
    }

    function scroll_therun() {
        if (!thexterm
            && (hasClass(therun, "pa-run-short")
                || therun.hasAttribute("data-pa-runbottom")))
            requestAnimationFrame(function () {
                if (therun.scrollHeight > therun.clientHeight)
                    removeClass(therun, "pa-run-short");
                if (therun.hasAttribute("data-pa-runbottom"))
                    therun.scrollTop = Math.max(therun.scrollHeight - therun.clientHeight, 0);
            });
    }

    if (!therun.hasAttribute("data-pa-opened")) {
        therun.setAttribute("data-pa-opened", "true");
        if (!thexterm) {
            therun.setAttribute("data-pa-runbottom", "true");
            therun.addEventListener("scroll", function () {
                requestAnimationFrame(function () {
                    if (therun.scrollTop + therun.clientHeight >= therun.scrollHeight - 10)
                        therun.setAttribute("data-pa-runbottom", "true");
                    else
                        therun.removeAttribute("data-pa-runbottom");
                });
            });
            scroll_therun();
        }
    }

    var ibuffer = "", // initial buffer; holds data before any results arrive
        offset = -1, backoff = 50, queueid = null, times = null;

    function hide_cursor() {
        if (thexterm)
            thexterm.write("\x1b[?25l"); // “hide cursor” escape
        else if (therun.lastChild)
            $(therun.lastChild).find(".pa-runcursor").remove();
    }

    function done() {
        $f.find("button").prop("disabled", false);
        $f.prop("outstanding", false);
        hide_cursor();
        if (button.hasAttribute("data-pa-run-grade")) {
            pa_fetchgrades.call(button.closest(".pa-psetinfo"));
        }
    }

    function append(str) {
        if (thexterm)
            thexterm.write(str);
        else
            pa_render_terminal(thepre[0], str, {cursor: true, directory: directory});
    }

    function append_html(html) {
        if (typeof html === "string")
            html = $(html)[0];
        if (thexterm) {
            if (window.console)
                console.log("xterm.js cannot render " + html);
        } else
            pa_render_terminal(thepre[0], html, {cursor: true});
    }

    function append_data(str, data) {
        if (ibuffer !== null) { // haven't started generating output
            ibuffer += str;
            var pos = ibuffer.indexOf("\n\n");
            if (pos < 0)
                return; // not ready yet

            str = ibuffer.substr(pos + 2);
            ibuffer = null;

            var tsmsg = "";
            if (data && data.timestamp) {
                tsmsg = "...started " + strftime("%l:%M:%S%P %e %b %Y", new Date(data.timestamp * 1000));
            }

            if (thexterm) {
                if (tsmsg !== "")
                    tsmsg = "\x1b[3;1;38;5;86m" + tsmsg + "\x1b[m\r\n";
                if (!opt.noclear)
                    tsmsg = "\x1bc" + tsmsg;
                str = tsmsg + str;
            } else {
                if (!opt.noclear)
                    thepre.html("");
                if (tsmsg !== "")
                    append_html("<span class=\"pa-runtime\">" + tsmsg + "</span>");
            }
        }
        if (str !== "")
            append(str);
    }

    function parse_times(times) {
        var a = [0, 0], p = 0;
        while (p < times.length) {
            var c = times.indexOf(",", p);
            if (c < 0)
                break;
            var n = times.indexOf("\n", c + 1);
            if (n < 0)
                n = times.length;
            a.push(+times.substring(p, c), +times.substring(c + 1, n));
            p = n + 1;
        }
        return a;
    }

    function append_timed(data, at_end) {
        var erange, etime, ebutton, espeed,
            tpos, tstart, tlast, timeout, running, factor;
        if (times)
            return;
        times = data.time_data;
        if (typeof times === "string")
            times = parse_times(times);
        factor = data.time_factor;
        if (times.length > 2) {
            erange = $('<div class="pa-runrange"><button type="button" class="pa-runrange-play"></button><input type="range" class="pa-runrange-range" min="0" max="' + times[times.length - 2] + '"><span class="pa-runrange-time"></span><span class="pa-runrange-speed-slow" title="Slow">🐢</span><input type="range" class="pa-runrange-speed" min="0.1" max="10" step="0.1"><span class="pa-runrange-speed-fast" title="Fast">🐇</span></div>').prependTo(therun);
            etime = erange[0].lastChild;
            ebutton = erange[0].firstChild;
            erange = ebutton.nextSibling;
            etime = erange.nextSibling;
            espeed = etime.nextSibling.nextSibling;
            erange.addEventListener("input", function (event) {
                running = false;
                addClass(ebutton, "paused");
                f(+this.value);
            }, false);
            ebutton.addEventListener("click", function (event) {
                if (hasClass(ebutton, "paused")) {
                    removeClass(ebutton, "paused");
                    running = true;
                    tstart = (new Date).getTime();
                    if (tlast < times[times.length - 2])
                        tstart -= tlast / factor;
                    f(null);
                } else {
                    addClass(ebutton, "paused");
                    running = false;
                }
            }, false);
            espeed.addEventListener("input", function (event) {
                factor = +this.value;
                wstorage.site(false, "pa-runspeed-" + category, [factor, (new Date).getTime()]);
                if (running) {
                    tstart = (new Date).getTime() - tlast / factor;
                    f(null);
                }
            }, false);
        }
        if ((tpos = wstorage.site_json(false, "pa-runspeed-" + category))
            && tpos[1] >= (new Date).getTime() - 86400000)
            factor = tpos[0];
        if (factor < 0.1 || factor > 10)
            factor = 1;
        if (espeed)
            espeed.value = factor;
        data = {data: data.data, timestamp: data.timestamp};

        function set_time() {
            if (erange) {
                erange.value = tlast;
                etime.innerHTML = sprintf("%d:%02d.%03d", Math.trunc(tlast / 60000), Math.trunc(tlast / 1000) % 60, Math.trunc(tlast) % 1000);
            }
        }

        function f(time) {
            if (time === null) {
                if (running)
                    time = ((new Date).getTime() - tstart) * factor;
                else
                    return;
            }
            var npos = tpos;
            if (npos >= times.length || time < times[npos])
                npos = 0;
            if (npos + 2 < times.length && time >= times[npos]) {
                var rpos = times.length;
                while (npos < rpos) {
                    var m = npos + (((rpos - npos) >> 1) & ~1);
                    if (time <= times[m])
                        rpos = m;
                    else
                        npos = m + 2;
                }
            }
            while (npos < times.length && time >= times[npos])
                npos += 2;
            tlast = time;

            if (npos < tpos) {
                ibuffer = "";
                tpos = 0;
            }

            var str = data.data;
            append_data(str.substring(tpos < times.length ? times[tpos + 1] : str.length,
                                      npos < times.length ? times[npos + 1] : str.length),
                        data);
            scroll_therun();
            set_time();

            tpos = npos;
            if (timeout)
                timeout = clearTimeout(timeout);
            if (running) {
                if (tpos < times.length)
                    timeout = setTimeout(f, Math.min(100, (times[tpos] - (tpos ? times[tpos - 2] : 0)) / factor), null);
                else {
                    if (ebutton)
                        addClass(ebutton, "paused");
                    hide_cursor();
                }
            }
        }

        if (at_end) {
            tpos = times.length;
            tlast = times[tpos - 2];
            running = false;
            ebutton && addClass(ebutton, "paused");
            set_time();
        } else {
            tpos = 0;
            tlast = 0;
            tstart = (new Date).getTime();
            running = true;
            if (times.length)
                f(null);
        }
    }

    function succeed(data) {
        var x, t;

        if (queueid)
            thepre.find("span.pa-runqueue").remove();
        if (data && data.onqueue) {
            queueid = data.queueid;
            t = "On queue, " + data.nahead + (data.nahead == 1 ? " job" : " jobs") + " ahead";
            if (data.headage) {
                if (data.headage < 10)
                    x = data.headage;
                else
                    x = Math.round(data.headage / 5 + 0.5) * 5;
                t += ", oldest began about " + x + (x == 1 ? " second" : " seconds") + " ago";
            }
            thepre[0].insertBefore(($("<span class='pa-runqueue'>" + t + "</span>"))[0], thepre[0].lastChild);
            setTimeout(send, 10000);
            return;
        }

        if (data && data.status == "working") {
            if (!$("#pa-runstop-" + category).length)
                $("<button id=\"pa-runstop-" + category + "\" class=\"btn btn-danger pa-runstop\" type=\"button\">Stop</button>")
                    .click(stop).appendTo("#pa-runout-" + category + " > h3");
        } else
            $("#pa-runstop-" + category).remove();

        if (!data || !data.ok) {
            x = "Unknown error";
            if (data && data.loggedout) {
                x = "You have been logged out (perhaps due to inactivity). Please reload this page.";
            } else if (data) {
                if (data.error_text)
                    x = data.error_text;
                else if (data.error && data.error !== true)
                    x = data.error;
                else if (data.message)
                    x = data.message;
            }
            append("\x1b[1;3;31m" + x + "\x1b[m\r\n");
            scroll_therun();
            return done();
        }

        checkt = checkt || data.timestamp;
        if (data.data && data.offset < offset)
            data.data = data.data.substring(offset - data.offset);
        if (data.data) {
            offset = data.lastoffset;
            if (data.done && data.time_data != null && ibuffer === "") {
                // Parse timing data
                append_timed(data);
                return;
            }

            append_data(data.data, data);
            backoff = 100;
        }
        if (data.result) {
            if (ibuffer !== null)
                append_data("\n\n", data);
            append_data(data.result, data);
        }
        if (!data.data && !data.result)
            backoff = Math.min(backoff * 2, 500);

        scroll_therun();
        if (data.status == "old")
            setTimeout(send, 2000);
        else if (!data.done)
            setTimeout(send, backoff);
        else {
            done();
            if (data.timed && !hasClass(therun.firstChild, "pa-runrange"))
                send({offset: 0}, succeed_add_times);
        }
    }

    function succeed_add_times(data) {
        if (data.data && data.done && data.time_data != null)
            append_timed(data, true);
    }

    function send(args, success) {
        var a = {};
        if (!$f[0].run)
            a.run = category;
        a.offset = offset;
        checkt && (a.check = checkt);
        queueid && (a.queueid = queueid);
        args && $.extend(a, args);
        jQuery.ajax($f.attr("action"), {
            data: $f.serializeWith(a),
            type: "POST", cache: false, dataType: "json",
            success: success || succeed, timeout: 30000,
            error: function () {
                $f.find(".ajaxsave61").html("Failed");
                $f.prop("outstanding", false);
            }
        });
    }

    function stop() {
        send({stop: 1});
    }

    function write(value) {
        send({write: value});
    }

    if (opt.headline && opt.noclear && !thexterm && thepre[0].firstChild)
        append("\n\n");
    if (opt.headline && opt.headline instanceof Node)
        append_html(opt.headline);
    else if (opt.headline)
        append("\x1b[1;37m" + opt.headline + "\x1b[m\n");
    if (opt.unfold && therun.getAttribute("data-pa-content"))
        append(therun.getAttribute("data-pa-content"));
    therun.removeAttribute("data-pa-content");
    scroll_therun();

    send();
    return false;
}

handle_ui.on("pa-runner", function () {
    pa_run(this);
});

function runmany61() {
    var $manybutton = jQuery("#runmany61");
    var $f = $manybutton.closest("form");
    if (!$f.prop("unload61")) {
        jQuery(window).on("beforeunload", function () {
            if ($f.prop("outstanding") || jQuery("#runmany61_users").text())
                return "Several server requests are outstanding.";
        });
        $f.prop("unload61", "1");
    }
    if (!$f.prop("outstanding")) {
        var users = jQuery("#runmany61_users").text().split(/[\s,;]+/);
        var user;
        while (!user && users.length)
            user = users.shift();
        if (!user) {
            jQuery("#runmany61_who").text("<done>");
            jQuery("#runmany61_users").text("");
            return;
        }
        jQuery("#runmany61_who").text(user);
        $f.find("[name='u']").val(user);
        jQuery("#runmany61_users").text(users.join(" "));
        var $x = jQuery("<a href=\"" + siteinfo.site_relative + "~" + encodeURIComponent(user) + "/pset/" + $f.find("[name='pset']").val() + "\" class=\"q ansib ansifg7\"></a>");
        $x.text(user);
        pa_run($manybutton[0], {noclear: true, headline: $x[0]});
    }
    setTimeout(runmany61, 10);
}


var pa_runsetting = (function ($) {

function save() {
    var $j = $("#pa-runsettings .pa-p"), j = {}, i, k, v;
    for (i = 0; i != $j.length; ++i) {
        k = $.trim($($j[i]).find(".n").val());
        v = $.trim($($j[i]).find(".v").val());
        if (k != "")
            j[k] = v;
    }
    $.ajax($j.closest("form").attr("action"), {
        data: {runsettings: j},
        type: "POST", cache: false,
        dataType: "json"
    });
}

function add(name, value) {
    var $j = $("#pa-runsettings"), num = $j.find(".n").length;
    while ($j.find("[data-runsetting-num=" + num + "]").length)
        ++num;
    var $x = $("<div class=\"pa-p\" data-runsetting-num=\"" + num + "\"><div class=\"pa-pt\"></div><div class=\"pa-pd\"><input name=\"n" + num + "\" class=\"uich pa-runconfig ignore-diff n\" size=\"30\" placeholder=\"Name\"> &nbsp; <input name=\"v" + num + "\" class=\"uich pa-runconfig ignore-diff v\" size=\"40\" placeholder=\"Value\"></div></div>");
    if (name) {
        $x.find(".n").val(name);
        $x.find(".v").val(value);
    }
    $j.append($x);
    if (!name)
        $x.find(".n").focus();
}

function load(j) {
    var $j = $("#pa-runsettings"), $n = $j.find(".n"), i, x;
    $n.attr("data-outstanding", "1");
    for (x in j) {
        for (i = 0; i != $n.length && $.trim($($n[0]).val()) != x; ++i)
            /* nada */;
        if (i == $n.length)
            add(x, j[x]);
        else if ($.trim($j.find("[name=v" + i + "]").val()) != j[x]) {
            $j.find("[name=v" + i + "]").val(j[x]);
            $($n[i]).removeAttr("data-outstanding");
        }
    }
    for (i = 0; i != $n.length; ++i)
        if ($($n[i]).attr("data-outstanding"))
            $("[data-runsetting-num=" + $($n[i]).attr("name").substr(1) + "]").remove();
}

handle_ui.on("pa-runconfig", function (event) {
    if (this.name === "define") {
        add();
    } else {
        save();
    }
});

return {add: add, load: load};
})(jQuery);


function pa_gradeinfo_total(gi, noextra) {
    if (typeof gi === "string") {
        gi = JSON.parse(gi);
    }
    var total = 0;
    for (var i = 0; i < gi.order.length; ++i) {
        var k = gi.order[i];
        var ge = k ? gi.entries[k] : null;
        if (ge && ge.in_total && (!noextra || !ge.is_extra))
            total += (gi.grades && gi.grades[i]) || 0;
    }
    return [Math.round(total * 1000) / 1000,
            Math.round(gi.maxtotal * 1000) / 1000];
}


function pa_cdf(d) {
    if (!d.cdf && d.xcdf) {
        var xcdf = d.xcdf, cdf = [], i = 0, y = 0;
        while (i < xcdf.length) {
            y += xcdf[i+1].length;
            cdf.push(xcdf[i], y);
            i += 2;
        }
        d.cdf = cdf;
    }
    return d.cdf;
}

function pa_cdfmin(d) {
    var cdf = d.cdf || d.xcdf;
    return cdf.length ? cdf[0] : 0;
}
function pa_cdfmax(d) {
    var cdf = d.cdf || d.xcdf;
    return cdf.length ? cdf[cdf.length - 2] : 0;
}

function pa_gradecdf_findy(d, x) {
    var cdf = pa_cdf(d), l = 0, r = cdf.length;
    while (l < r) {
        var m = l + ((r - l) >> 2) * 2;
        if (cdf[m] >= x)
            r = m;
        else
            l = m + 2;
    }
    return cdf[l+1];
}

function pa_gradecdf_kde(d, gi, hfrac, nbins) {
    var maxg = gi.max, ming = gi.min,
        H = (maxg - ming) * hfrac, iH = 1 / H;
    function epanechnikov(x) {
        if (x >= -H && x <= H) {
            x *= iH;
            return 0.75 * iH * (1 - x * x);
        } else {
            return 0;
        }
    }
    var bins = [], i;
    for (i = 0; i !== nbins + 1; ++i) {
        bins.push(0);
    }
    var cdf = pa_cdf(d), dx = (maxg - ming) / nbins, idx = 1 / dx;
    for (i = 0; i < cdf.length; i += 2) {
        var y = cdf[i+1] - (i === 0 ? 0 : cdf[i-1]);
        var x1 = Math.floor((cdf[i] - ming - H) * idx);
        var x2 = Math.ceil((cdf[i] - ming + H) * idx);
        while (x1 < x2) {
            var x = Math.max(-1, Math.min(nbins + 1, x1));
            if (x >= 0 && x <= nbins) {
                bins[x] += epanechnikov(x1 * dx - cdf[i] + ming) * y;
            }
            ++x1;
        }
    }
    var maxp = 0;
    if (d.n) {
        var nr = 1 / d.n;
        for (i = 0; i !== nbins + 1; ++i) {
            bins[i] *= nr;
            maxp = Math.max(maxp, bins[i]);
        }
    }
    return {data: d, kde: bins, maxp: maxp, binwidth: dx};
}

/*function pa_gradecdf_kdepath(kde, xax, yax) {
    var data = [], bins = kde.kde, nrdy = 0.9 / kde.maxp;
    for (i = 0; i !== bins.length; ++i) {
        if (i !== 0)
            data.push(" ", xax(i * kde.binwidth), ",", yax(bins[i] * nrdy));
        else
            data.push("M", xax(i * kde.binwidth), ",", yax(bins[i] * nrdy), "L");
    }
    return data.join("");
}*/

function mksvg(tag) {
    return document.createElementNS("http://www.w3.org/2000/svg", tag);
}


function PAGradeGraph(parent, d, plot_type) {
    var $parent = $(parent);

    var dd = plot_type.indexOf("noextra") >= 0 ? d.noextra : d.all;
    var ddmin = pa_cdfmin(dd);
    var xmin = xmin < 0 ? xmin - 1 : 0;
    if (d.entry && d.entry.type === "letter")
        xmin = Math.min(65, ddmin < 0 ? ddmin : Math.max(ddmin - 5, 0));
    this.min = xmin;
    this.max = pa_cdfmax(dd);
    if (d.maxtotal)
        this.max = Math.max(this.max, d.maxtotal);
    this.total = d.maxtotal;
    this.cutoff = d.cutoff;

    this.svg = mksvg("svg");
    this.gg = mksvg("g");
    this.gx = mksvg("g");
    this.gy = mksvg("g");
    this.xl = this.xt = true;
    this.yl = this.yt = plot_type.substring(0, 3) !== "pdf";
    this.tw = $parent.width();
    this.th = $parent.height();
    this.svg.setAttribute("preserveAspectRatio", "none");
    this.svg.setAttribute("width", this.tw);
    this.svg.setAttribute("height", this.th);
    this.svg.setAttribute("overflow", "visible");
    this.svg.appendChild(this.gg);
    this.gx.setAttribute("class", "pa-gg-axis pa-gg-xaxis");
    this.svg.appendChild(this.gx);
    this.gy.setAttribute("class", "pa-gg-axis pa-gg-yaxis");
    this.svg.appendChild(this.gy);
    this.maxp = 0;
    this.hoveranno = null;
    this.hoveron = false;
    $parent.html(this.svg);

    var digits = mksvg("text");
    digits.appendChild(document.createTextNode("888"));
    this.gx.appendChild(digits);
    var domr = digits.getBBox();
    this.xdw = domr.width / 3;
    this.xdh = domr.height;
    this.gx.removeChild(digits);

    this.xlw = this.xdw * (Math.floor(Math.log10(this.max)) + 1);

    this.mt = Math.ceil(Math.max(this.yl ? this.xdh / 2 : 0, 2));
    this.mr = Math.ceil(this.xl ? this.xlw / 2 : 0);
    this.mb = (this.xt ? 5 : 0) + (this.xl ? this.xdh + 3 : 0);
    if (this.yl) {
        var h = this.th - this.mt - Math.max(this.mb, Math.ceil(this.xdh / 2));
        if (h > this.xdh) {
            var minyaxis = $parent.hasClass("pa-grgraph-min-yaxis");
            if (minyaxis) {
                this.yfmt = "%.0r%%";
            } else {
                this.yfmt = "%.0r";
            }
            var labelcap = h / this.xdh;
            this.ymax = 100;
            if (labelcap > 15)
                this.ylu = 10;
            else if (labelcap > 5)
                this.ylu = 25;
            else if (labelcap > 3)
                this.ylu = 50;
            else
                this.ylu = 100;
            this.ml = (this.yt ? 5 : 0) + 5 + (minyaxis ? 4.2 : 3) * this.xdw;

            if (!$parent.hasClass("pa-grgraph-min-yaxis")) {
                this.yltext = mksvg("text");
                this.yltext.appendChild(document.createTextNode("% of grades"));
                this.gy.appendChild(this.yltext);
                domr = this.yltext.getBBox();
                if (domr.width <= 0.875 * h) {
                    this.ml += this.xdw * 0.5 + this.xdh;
                } else {
                    this.gy.removeText(this.yltext);
                    this.yltext = null;
                }
            }

            this.mb = Math.max(this.mb, Math.ceil(this.xdh / 2));
        } else {
            this.yl = false;
            this.ml = 0;
            this.mt = 2;
        }
    } else {
        this.ml = this.yt ? 5 : 0;
    }
    if (this.xl) {
        this.ml = Math.max(this.ml, Math.ceil(this.xdw / 2));
    }

    this.gw = this.tw - this.ml - this.mr;
    var gh = this.gh = this.th - this.mt - this.mb;
    var xfactor = this.gw / (this.max - this.min);
    this.xax = function (x) {
        return (x - xmin) * xfactor;
    };
    this.yax = function (y) {
        return gh - y * gh;
    };
    this.unxax = function (ax) {
        return (ax / xfactor) + xmin;
    };
    this.unyax = function (ay) {
        return -(ay - gh) / gh;
    };
    if (d.entry && d.entry.type) {
        var gt = pa_grade_types[d.entry.type];
        if (gt && gt.tics)
            this.xtics = gt.tics.call(gt);
    }
    if (this.max - this.min > 900) {
        this.xfmt = "%.0r";
    } else if (this.max - this.min > 10) {
        this.xfmt = "%.1r";
    } else {
        this.xfmt = "%.3r";
    }

    this.gg.setAttribute("transform", "translate(" + this.ml + "," + this.mt + ")");
    this.gx.setAttribute("transform", "translate(" + this.ml + "," + (this.mt + this.gh + (this.xt ? 2 : -5)) + ")");
    this.gy.setAttribute("transform", "translate(" + (this.ml + (this.yt ? -2 : 5)) + "," + this.mt + ")");
}
PAGradeGraph.prototype.numeric_xaxis = function () {
    // determine number
    var ndigit_max = Math.floor(Math.log10(this.max)) + 1,
        labelw = this.xdw * (ndigit_max + 0.5),
        labelcap = this.gw / labelw;

    var unitbase = Math.pow(10, Math.max(0, ndigit_max - 2)),
        nunits = (this.max - this.min) / unitbase,
        unit;
    if (labelcap > nunits * 4 && unitbase > 1)
        unit = unitbase / 2;
    else if (labelcap > nunits * 2)
        unit = unitbase;
    else if (labelcap > nunits * (unitbase <= 1 ? 0.75 : 1))
        unit = 2 * unitbase;
    else if (unitbase > 1 && labelcap > nunits * 0.6)
        unit = 2.5 * unitbase;
    else if (labelcap > nunits * 0.3)
        unit = 5 * unitbase;
    else
        unit = 10 * unitbase;

    var x = Math.floor(this.min / unit) * unit,
        d = [], total_done = false, e;
    while (x < this.max + unit) {
        var xx = x, draw = this.xl;
        if (this.total) {
            if (xx > this.total
                && xx - unit < this.total
                && !total_done) {
                xx = this.total;
                x -= unit;
            }
            if (xx == this.total)
                total_done = true;
        }
        x += unit;
        if (xx < this.min)
            continue;
        if (xx > this.max)
            xx = this.max;

        var xxv = this.xax(xx);
        d.push("M", xxv, ",0v5");

        if ((this.total
             && xx != this.total
             && Math.abs(xxv - this.xax(this.total)) < labelw)
            || (xx != this.max
                && xx != this.total
                && Math.abs(xxv - this.xax(this.max)) < labelw))
            draw = false;

        if (draw) {
            e = mksvg("text");
            e.appendChild(document.createTextNode(xx));
            e.setAttribute("x", xxv);
            e.setAttribute("y", this.xdh + 3);
            this.gx.appendChild(e);
        }
    }

    if (this.xt) {
        e = mksvg("path");
        e.setAttribute("d", d.join(""));
        e.setAttribute("fill", "none");
        e.setAttribute("stroke", "black");
        this.gx.appendChild(e);
    }
};
PAGradeGraph.prototype.xtics_xaxis = function () {
    // determine number
    var label_restrictions = new IntervalSeq,
        tic_restrictions = new IntervalSeq,
        d = [];

    for (i = 0; i !== this.xtics.length; ++i) {
        xt = this.xtics[i];
        if (xt.x < this.min || xt.x > this.max)
            continue;
        var xxv = this.xax(xt.x);
        if (xt.notic || !tic_restrictions.contains(xxv)) {
            if (!xt.notic) {
                d.push("M", xxv, ",0v5");
                tic_restrictions.add(xxv - 3, xxv + 3);
            }

            if (this.xl && xt.text) {
                var lw = this.xdw * (xt.label_space || xt.text.length + 0.5) * 0.5;
                if (!label_restrictions.overlaps(xxv - lw, xxv + lw)) {
                    var e = mksvg("text");
                    e.appendChild(document.createTextNode(xt.text));
                    e.setAttribute("x", xxv);
                    e.setAttribute("y", this.xdh + 3);
                    this.gx.appendChild(e);
                    lw = this.xdw * (xt.text.length + 0.5) * 0.5;
                    label_restrictions.add(xxv - lw, xxv + lw);
                }
            }
        }
    }

    if (this.xt) {
        e = mksvg("path");
        e.setAttribute("d", d.join(""));
        e.setAttribute("fill", "none");
        e.setAttribute("stroke", "black");
        this.gx.appendChild(e);
    }
};
PAGradeGraph.prototype.xaxis = function () {
    if (this.xtics)
        this.xtics_xaxis();
    else
        this.numeric_xaxis();
};
PAGradeGraph.prototype.yaxis = function () {
    var y = 0, d = [], e;
    while (y <= this.ymax && this.yl) {
        e = mksvg("text");
        e.appendChild(document.createTextNode(sprintf(this.yfmt, y)));
        e.setAttribute("x", -8);
        e.setAttribute("y", this.yax(y / this.ymax) + 0.25 * this.xdh);
        this.gy.appendChild(e);

        d.push("M-5,", this.yax(y / this.ymax), "h5");

        y += this.ylu;
    }

    if (this.yt) {
        e = mksvg("path");
        e.setAttribute("d", d.join(""));
        e.setAttribute("fill", "none");
        e.setAttribute("stroke", "black");
        this.gy.appendChild(e);
    }

    if (this.yltext) {
        this.yltext.setAttribute("transform", "translate(" + (-this.ml + this.xdh) + "," + this.yax(0.5) + ") rotate(-90)");
        this.yltext.setAttribute("text-anchor", "middle");
    }
};
PAGradeGraph.prototype.container = function () {
    return $(this.svg).closest(".pa-grgraph")[0];
};
PAGradeGraph.prototype.append_cdf = function (d, klass) {
    var cdf = pa_cdf(d), data = [], nr = 1 / d.n,
        cutoff = this.cutoff || 0, i = 0, x;
    if (cutoff) {
        while (i < cdf.length && cdf[i+1] < cutoff * d.n) {
            i += 2;
        }
    }
    for (; i < cdf.length; i += 2) {
        if (data.length !== 0) {
            x = Math.max(0, cdf[i] - Math.min(1, cdf[i] - cdf[i - 2]) / 2);
            data.push("H", this.xax(x));
        } else
            data.push("M", this.xax(Math.max(0, cdf[i] - 0.5)), ",", this.yax(cutoff));
        data.push("V", this.yax(cdf[i+1] * nr));
    }
    if (data.length !== 0)
        data.push("H", this.xax(cdf[cdf.length-2] + 0.5));
    var path = mksvg("path");
    path.setAttribute("d", data.join(""));
    path.setAttribute("fill", "none");
    path.setAttribute("class", klass);
    this.gg.appendChild(path);
    this.last_curve = path;
    this.last_curve_data = d;
    return path;
};
PAGradeGraph.prototype.append_pdf = function (kde, klass) {
    if (kde.maxp === 0)
        return null;
    var data = [], bins = kde.kde, nrdy = 0.9 / this.maxp,
        xax = this.xax, yax = this.yax;
    // adapted from d3-shape by Mike Bostock
    var xs = [0, 0, 0, 0], ys = [0, 0, 0, 0],
        la = [0, 0, 0, 0], la2 = [0, 0, 0, 0],
        epsilon = 1e-6;
    function point(i2) {
        var i0 = (i2 + 2) % 4, i1 = (i2 + 3) % 4, i3 = (i2 + 1) % 4;
        var x1 = xs[i1], y1 = ys[i1], x2 = xs[i2], y2 = ys[i2];
        if (la[i1] > epsilon) {
            var a = 2 * la2[i1] + 3 * la[i1] * la[i2] + la2[i2],
                n = 3 * la[i1] * (la[i1] + la[i2]);
            x1 = (x1 * a - xs[i0] * la2[i2] + xs[i2] * la2[i1]) / n;
            y1 = (y1 * a - ys[i0] * la2[i2] + ys[i2] * la2[i1]) / n;
        }
        if (la[i3] > epsilon) {
            var b = 2 * la2[i3] + 3 * la[i3] * la[i2] + la2[i2],
                m = 3 * la[i3] * (la[i3] + la[i2]);
            x2 = (x2 * b - xs[i3] * la2[i2] + xs[i1] * la2[i3]) / m;
            y2 = (y2 * b - ys[i3] * la2[i2] + ys[i1] * la2[i3]) / m;
        }
        data.push("C", x1, y1, x2, y2, xs[i2], ys[i2]);
    }
    for (var i = 0; i !== bins.length; ++i) {
        var x = xax(this.min + i * kde.binwidth),
            y = yax(bins[i] * nrdy);
        if (i === 0) {
            data.push("M", x, y);
            xs[3] = xs[0] = x;
            ys[3] = ys[0] = y;
        } else {
            var i1 = (i + 3) % 4, i2 = i % 4;
            xs[i2] = x;
            ys[i2] = y;

            var dx = xs[i1] - x, dy = ys[i1] - y;
            la2[i2] = Math.sqrt(dx * dx + dy * dy);
            la[i2] = Math.sqrt(la2[i2]);
            if (i > 1)
                point(i1);

            if (i === bins.length - 1) {
                var i3 = (i + 1) % 4;
                xs[i3] = x;
                ys[i3] = y;
                la2[i3] = 0;
                la[i3] = 0;
                point(i2);
            }
        }
    }
    var path = mksvg("path");
    path.setAttribute("d", data.join(" "));
    path.setAttribute("fill", "none");
    path.setAttribute("class", klass);
    this.gg.appendChild(path);
    this.last_curve = path;
    this.last_curve_data = kde.data;
    return path;
};
PAGradeGraph.prototype.remove_if = function (predicate) {
    var e = this.gg.firstChild;
    while (e) {
        var next = e.nextSibling;
        if (predicate.call(e))
            this.gg.removeChild(e);
        e = next;
    }
};
PAGradeGraph.prototype.highlight_last_curve = function (d, predicate, klass) {
    if (!this.last_curve || !this.last_curve_data.xcdf)
        return null;
    var ispdf = hasClass(this.last_curve, "pa-gg-pdf"),
        xcdf = this.last_curve_data.xcdf, data = [], nr, nrgh,
        i, xv, yv, cdfy = 0, cids, j, yc;
    if (ispdf) {
        nr = 0.9 / (this.maxp * d.n);
    } else {
        nr = 1 / d.n;
    }
    nrgh = nr * this.gh;
    for (i = 0; i < xcdf.length; i += 2) {
        cids = xcdf[i + 1];
        cdfy += cids.length;
        for (j = yc = 0; j < cids.length; ++j) {
            if (predicate(cids[j], d))
                ++yc;
        }
        if (yc) {
            xv = this.xax(xcdf[i]);
            if (ispdf) {
                yv = svgutil.eval_function_path.call(this.last_curve, xv);
            } else {
                yv = this.yax(cdfy * nr);
            }
            if (yv != null)
                data.push("M", xv, ",", yv, "v", yc * nrgh);
        }
    }
    if (!data.length)
        return null;
    else {
        var path = mksvg("path");
        path.setAttribute("d", data.join(""));
        path.setAttribute("fill", "none");
        path.setAttribute("class", klass);
        this.gg.appendChild(path);
        addClass(this.gg, "pa-gg-has-hl");
        return path;
    }
};
PAGradeGraph.prototype.typed_annotation = function (klass) {
    var dot = mksvg("circle");
    dot.setAttribute("class", "pa-gg-mark hl-" + (klass || "main"));
    dot.setAttribute("r", !klass || klass === "main" ? 5 : 3.5);
    return dot;
};
PAGradeGraph.prototype.star_annotation = function (rs, start, n, klass) {
    if (start == null) {
        start = Math.PI / 2;
    }
    if (n == null) {
        n = 5;
    }
    var star = mksvg("path");
    star.setAttribute("class", klass);
    var d = ["M"], cos = Math.cos, sin = Math.sin, delta = Math.PI / n;
    for (var i = 0; i < 2 * n; ++i) {
        d.push(rs[i & 1] * cos(start), " ", rs[i & 1] * sin(start), i ? " " : "L");
        start += delta;
    }
    d.push("z");
    star.setAttribute("d", d.join(""));
    return star;
};
PAGradeGraph.prototype.annotate_last_curve = function (x, elt, after) {
    if (this.last_curve) {
        var xv = this.xax(x), yv = svgutil.eval_function_path.call(this.last_curve, xv);
        if (yv === null && this.cutoff)
            yv = this.yax(this.cutoff);
        if (yv !== null) {
            elt = elt || this.typed_annotation();
            elt.setAttribute("transform", "translate(" + xv + "," + yv + ")");
            this.gg.insertBefore(elt, after || null);
            return true;
        }
    }
    return false;
};
PAGradeGraph.prototype.user_x = function (uid) {
    if (!this.last_curve_data.xcdf)
        return undefined;
    if (!this.last_curve_data.ucdf) {
        var xcdf = this.last_curve_data.xcdf,
            ucdf = this.last_curve_data.ucdf = {};
        for (var i = 0; i !== xcdf.length; i += 2) {
            var uids = xcdf[i + 1];
            for (var j = 0; j !== uids.length; ++j)
                ucdf[uids[j]] = xcdf[i];
        }
    }
    return this.last_curve_data.ucdf[uid];
};
PAGradeGraph.prototype.highlight_users = function () {
    if (!this.last_curve || !this.last_curve_data.xcdf)
        return;

    this.last_highlight = this.last_highlight || {};
    var attrs = this.container().attributes, desired = {}, x;
    for (var i = 0; i !== attrs.length; ++i) {
        if (attrs[i].name.startsWith("data-pa-highlight")) {
            var type;
            if (attrs[i].name === "data-pa-highlight")
                type = "main";
            else
                type = attrs[i].name.substring(18);
            desired[type] = attrs[i].value;
            this.last_highlight[type] = this.last_highlight[type] || "";
        }
    }

    for (var type in this.last_highlight) {
        var uids = desired[type] || "";
        if (this.last_highlight[type] === uids)
            continue;

        var uidm = {}, uidx = uids.split(/\s+/), x;
        for (var i = 0; i !== uidx.length; ++i) {
            if (uidx[i] !== "")
                uidm[uidx[i]] = 1;
        }

        var el = this.gg.firstChild, elnext;
        var klass = "pa-gg-mark hl-" + type;
        while (el && (!hasClass(el, "pa-gg-mark") || el.className.animVal < klass)) {
            el = el.nextSibling;
        }

        while (el && el.className.animVal === klass) {
            elnext = el.nextSibling;
            var uid = +el.getAttribute("data-pa-uid");
            if (uidm[uid]) {
                uidm[uid] = 2;
            } else {
                this.gg.removeChild(el);
            }
            el = elnext;
        }

        for (var i = 0; i !== uidx.length; ++i) {
            if (uidm[uidx[i]] === 1
                && (x = this.user_x(uidx[i])) != null) {
                var e = this.typed_annotation(type);
                e.setAttribute("data-pa-uid", uidx[i]);
                this.annotate_last_curve(x, e, el);
            }
        }

        this.last_highlight[type] = uids;
    }
};
PAGradeGraph.prototype.hover = function () {
    var that = this;
    function closer_mark(hlpaths, pt, bestDistance2) {
        var hlpt = null;
        for (var hlp of hlpaths) {
            var m = hlp.getAttribute("transform").match(/^translate\(([-+\d.]+),([-+\d.]+)\)$/);
            if (m) {
                var dx = +m[1] - pt[0], dy = +m[2] - pt[1],
                    distance2 = dx * dx + dy * dy;
                if (distance2 < bestDistance2) {
                    hlpt = [+m[1], +m[2]];
                    hlpt.pathNode = hlp;
                    bestDistance2 = distance2;
                }
            }
        }
        hlpt && (hlpt.distance = Math.sqrt(bestDistance2));
        return hlpt;
    }
    function handle(event) {
        var pt = {distance: 20}, xfmt = that.xfmt;
        if (event.type !== "mousemove")
            that.hoveron = event.type !== "mouseleave";
        if (that.hoveron) {
            var loc = svgutil.event_to_point(that.svg, event),
                paths = that.gg.querySelectorAll(".pa-gg-pdf, .pa-gg-cdf");
            loc[0] -= that.ml;
            loc[1] -= that.mt;
            for (var p of paths) {
                pt = svgutil.closest_point(p, loc, pt);
            }
            if (pt.pathNode) {
                var hlpt = closer_mark(that.gg.querySelectorAll(".pa-gg-mark.hl-main"), pt, 36)
                    || closer_mark(that.gg.querySelectorAll(".pa-gg-mark:not(.hl-main)"), pt, 25);
                if (hlpt) {
                    pt = hlpt;
                    if (xfmt === "%.0r" || xfmt === "%.1f") {
                        xfmt = "%.2r";
                    }
                }
            }
        }
        var ha = that.hoveranno;
        if (pt.pathNode) {
            if (!ha) {
                ha = that.hoveranno = [that.star_annotation([4, 10], null, null, "pa-gg-hover-mark")];
                that.gg.appendChild(ha[0]);

                var e = mksvg("path");
                e.setAttribute("d", "M0,0v5");
                e.setAttribute("fill", "none");
                e.setAttribute("stroke", "black");
                that.gx.appendChild(e);
                ha.push(e);

                e = mksvg("rect");
                e.setAttribute("class", "pa-gg-hover-box");
                e.setAttribute("y", 5);
                e.setAttribute("height", that.xdh + 1.5);
                e.setAttribute("rx", 3);
                that.gx.appendChild(e);
                ha.push(e);

                e = mksvg("text");
                e.appendChild(document.createTextNode(""));
                e.setAttribute("class", "pa-gg-hover-text");
                e.setAttribute("y", that.xdh + 3);
                that.gx.appendChild(e);
                ha.push(e);

                e = mksvg("text");
                e.appendChild(document.createTextNode(""));
                e.setAttribute("class", "pa-gg-anno-name");
                e.setAttribute("text-anchor", "end");
                e.setAttribute("dx", -8);
                that.gg.appendChild(e);
                ha.push(e);

                if (that.yl) {
                    e = mksvg("path");
                    e.setAttribute("d", "M-5,0h5");
                    e.setAttribute("fill", "none");
                    e.setAttribute("stroke", "black");
                    that.gy.appendChild(e);
                    ha.push(e);

                    e = mksvg("rect");
                    e.setAttribute("class", "pa-gg-hover-box");
                    e.setAttribute("height", that.xdh + 1);
                    e.setAttribute("rx", 3);
                    that.gy.appendChild(e);
                    ha.push(e);

                    e = mksvg("text");
                    e.appendChild(document.createTextNode(""));
                    e.setAttribute("class", "pa-gg-hover-text");
                    e.setAttribute("x", -8);
                    that.gy.appendChild(e);
                    ha.push(e);
                }
            }
            ha[0].setAttribute("transform", "translate(" + pt[0] + "," + pt[1] + ")");

            ha[1].setAttribute("transform", "translate(" + pt[0] + ",0)");
            ha[3].setAttribute("x", pt[0]);
            ha[3].firstChild.data = sprintf(xfmt, that.unxax(pt[0]));
            var bb = ha[3].getBBox();
            ha[2].setAttribute("x", pt[0] - bb.width / 2 - 2);
            ha[2].setAttribute("width", bb.width + 4);

            var table, name;
            if (pt.pathNode.hasAttribute("data-pa-uid")
                && (table = $(".gtable").data("paTable"))
                && (name = table.name_text(pt.pathNode.getAttribute("data-pa-uid")))) {
                ha[4].firstChild.data = name;
                ha[4].setAttribute("x", pt[0]);
                ha[4].setAttribute("y", pt[1]);
            } else {
                ha[4].firstChild.data = "";
            }

            if (that.yl) {
                ha[5].setAttribute("transform", "translate(0," + pt[1] + ")");
                ha[7].setAttribute("y", pt[1] + 0.25 * that.xdh);
                ha[7].firstChild.data = sprintf(that.yfmt, that.unyax(pt[1]) * that.ymax);
                bb = ha[7].getBBox();
                ha[6].setAttribute("x", -bb.width - 10);
                ha[6].setAttribute("y", pt[1] - (that.xdh + 2) / 2);
                ha[6].setAttribute("width", bb.width + 4);
            }
        } else if (ha) {
            that.gg.removeChild(ha[0]);
            that.gx.removeChild(ha[1]);
            that.gx.removeChild(ha[2]);
            that.gx.removeChild(ha[3]);
            that.gg.removeChild(ha[4]);
            if (that.yl) {
                that.gy.removeChild(ha[5]);
                that.gy.removeChild(ha[6]);
                that.gy.removeChild(ha[7]);
            }
            that.hoveranno = null;
        }
    }
    this.svg.addEventListener("mouseenter", handle, false);
    this.svg.addEventListener("mousemove", handle, false);
    this.svg.addEventListener("mouseleave", handle, false);
};


function pa_draw_gradecdf($graph) {
    var d = $graph.data("paGradeData");
    if (!d) {
        $graph.addClass("hidden");
        $graph.removeData("paGradeGraph");
        return;
    }

    var $pi = $graph.closest(".pa-psetinfo");
    var user_extension = !$pi.length
        || $pi[0].hasAttribute("data-pa-user-extension");

    // compute plot types
    var plot_types = [];
    if (d.extension && $pi.length && user_extension) {
        plot_types.push("cdf-extension", "pdf-extension");
    }
    plot_types.push("cdf", "pdf");
    if (d.extension && !$pi.length) {
        plot_types.push("cdf-extension", "pdf-extension");
    }
    if (d.noextra) {
        plot_types.push("cdf-noextra", "pdf-noextra");
    }
    plot_types.push("all");
    $graph[0].setAttribute("data-pa-gg-types", plot_types.join(" "));

    // compute this plot type
    var plot_type = $graph[0].getAttribute("data-pa-gg-type");
    if (!plot_type) {
        plot_type = wstorage(true, "pa-gg-type");
    }
    if (!plot_type) {
        var plotarg = wstorage(false, "pa-gg-type");
        if (plotarg && plotarg[0] === "{") {
            try {
                plotarg = JSON.parse(plotarg);
                // remember previous plot choice for up to two hours
                if (typeof plotarg.type === "string"
                    && typeof plotarg.at === "number"
                    && plotarg.at >= now_sec() - 7200) {
                    plot_type = plotarg.type;
                }
            } catch (e) {
            }
        }
    }
    if (!plot_type || plot_type === "default") {
        plot_type = plot_types[0];
    }
    if (plot_types.indexOf(plot_type) < 0) {
        if (plot_type.substring(0, 3) === "pdf") {
            plot_type = plot_types[1];
        } else {
            plot_type = plot_types[0];
        }
    }
    $graph[0].setAttribute("data-pa-gg-type", plot_type);
    $graph.removeClass("cdf pdf all cdf-extension pdf-extension all-extension cdf-noextra pdf-noextra all-noextra");
    $graph.addClass(plot_type);

    var want_all = plot_type.substring(0, 3) === "all";
    var want_pdf = plot_type.substring(0, 3) === "pdf";
    var want_cdf = want_all || plot_type.substring(0, 3) === "cdf";
    var want_extension = plot_type.indexOf("-extension") >= 0
        || (want_all && user_extension && d.extension);
    var want_noextra = plot_type.indexOf("-noextra") >= 0
        || (want_all && d.noextra && !want_extension);

    // maxes
    var datamax = 0;
    if (want_noextra)
        datamax = Math.max(datamax, pa_cdfmax(d.noextra));
    if (want_extension)
        datamax = Math.max(datamax, pa_cdfmax(d.extension));
    if (want_all || (!want_noextra && !want_extension))
        datamax = Math.max(datamax, pa_cdfmax(d.all));
    var max = d.maxtotal ? Math.max(datamax, d.maxtotal) : datamax;

    $graph.removeClass("hidden");
    var $plot = $graph.find(".plot");
    if (!$plot.length)
        $plot = $graph;

    var gi = new PAGradeGraph($plot[0], d, plot_type);
    $graph.data("paGradeGraph", gi);

    if (gi.total && gi.total < gi.max) {
        var total = mksvg("line");
        total.setAttribute("x1", gi.xax(gi.total));
        total.setAttribute("y1", gi.yax(0));
        total.setAttribute("x2", gi.xax(gi.total));
        total.setAttribute("y2", gi.yax(1));
        total.setAttribute("class", "pa-gg-anno-total");
        gi.gg.appendChild(total);
    }

    // series
    var kde_nbins = Math.ceil((gi.max - gi.min) / 2), kde_hfactor = 0.08, kdes = [];
    if (plot_type === "pdf-extension")
        kdes.extension = pa_gradecdf_kde(d.extension, gi, kde_hfactor, kde_nbins);
    if (plot_type === "pdf-noextra")
        kdes.noextra = pa_gradecdf_kde(d.noextra, gi, kde_hfactor, kde_nbins);
    if (plot_type === "pdf")
        kdes.main = pa_gradecdf_kde(d.all, gi, kde_hfactor, kde_nbins);
    for (var i in kdes)
        gi.maxp = Math.max(gi.maxp, kdes[i].maxp);

    if (plot_type === "pdf-noextra")
        gi.append_pdf(kdes.noextra, "pa-gg-pdf pa-gg-noextra");
    if (plot_type === "pdf")
        gi.append_pdf(kdes.main, "pa-gg-pdf");
    if (plot_type === "pdf-extension")
        gi.append_pdf(kdes.extension, "pa-gg-pdf pa-gg-extension");
    if (plot_type === "cdf-noextra" || (plot_type === "all" && d.noextra))
        gi.append_cdf(d.noextra, "pa-gg-cdf pa-gg-noextra");
    if (plot_type === "cdf" || plot_type === "all")
        gi.append_cdf(d.all, "pa-gg-cdf");
    if (plot_type === "cdf-extension" || (plot_type === "all" && d.extension && user_extension))
        gi.append_cdf(d.extension, "pa-gg-cdf pa-gg-extension");

    // cutoff
    if (d.cutoff && plot_type.substring(0, 3) !== "pdf") {
        var cutoff = mksvg("rect");
        cutoff.setAttribute("x", gi.xax(0));
        cutoff.setAttribute("y", gi.yax(d.cutoff));
        cutoff.setAttribute("width", gi.xax(gi.max));
        cutoff.setAttribute("height", gi.yax(0) - gi.yax(d.cutoff));
        cutoff.setAttribute("fill", "rgba(255,0,0,0.1)");
        gi.gg.appendChild(cutoff);
    }

    // load user grade
    var total = null, gri = $pi.data("pa-gradeinfo");
    if (gri)
        total = pa_gradeinfo_total(gri, want_noextra && !want_all)[0];
    if (total != null)
        gi.annotate_last_curve(total);

    // axes
    gi.xaxis();
    gi.yaxis();

    if ($graph[0].hasAttribute("data-pa-highlight"))
        gi.highlight_users();

    gi.hover();

    // summary
    $graph.find(".statistics").each(function () {
        var dd = gi.last_curve_data, x = [];
        if (dd && dd.mean)
            x.push("mean " + dd.mean.toFixed(1));
        if (dd && dd.median)
            x.push("median " + dd.median.toFixed(1));
        if (dd && dd.stddev)
            x.push("stddev " + dd.stddev.toFixed(1));
        x = [x.join(", ")];
        if (dd && total != null) {
            var y = pa_gradecdf_findy(dd, total);
            if (dd.cutoff && y < dd.cutoff * dd.n)
                x.push("≤" + Math.round(dd.cutoff * 100) + " %ile");
            else
                x.push(Math.round(Math.min(Math.max(1, y * 100 / dd.n), 99)) + " %ile");
        }
        if (x.length) {
            removeClass(this, "hidden");
            this.innerHTML = x.join(" · ");
        } else {
            addClass(this, "hidden");
            this.innerHTML = "";
        }
    });

    $graph.find(".pa-grgraph-type").each(function () {
        var title = [];
        if (plot_type.startsWith("cdf"))
            title.push("CDF");
        else if (plot_type.startsWith("pdf"))
            title.push("PDF");
        if (want_extension && !want_all)
            title.push("extension");
        if (want_noextra && !want_all)
            title.push("no extra credit");
        var t = title.length ? " (" + title.join(", ") + ")" : "";
        this.innerHTML = "grade statistics" + t;
    });

    gi.highlight_users();
}

handle_ui.on("js-grgraph-flip", function () {
    var $graph = $(this).closest(".pa-grgraph"),
        plot_types = ($graph[0].getAttribute("data-pa-gg-types") || "").split(/ /),
        plot_type = $graph[0].getAttribute("data-pa-gg-type"),
        i = plot_types.indexOf(plot_type);
    if (i >= 0) {
        i = (i + (hasClass(this, "prev") ? plot_types.length - 1 : 1)) % plot_types.length;
        $graph[0].setAttribute("data-pa-gg-type", plot_types[i]);
        wstorage(true, "pa-gg-type", plot_types[i]);
        wstorage(false, "pa-gg-type", {type: plot_types[i], at: now_sec()});
        pa_draw_gradecdf($graph);
    }
});

function pa_parse_hash(hash) {
    hash = hash.replace(/^[^#]*/, "");
    var regex = /([^#=\/]+)[=\/]?([^\/]*)/g, m, h = {};
    while ((m = regex.exec(hash))) {
        h[m[1]] = decodeURIComponent(m[2].replace(/\+/g, " "));
    }
    return h;
}

function pa_unparse_hash(h) {
    var a = [];
    for (var k in h) {
        if (h[k] === null || h[k] === undefined || h[k] === "") {
            a.push(k);
        } else {
            a.push(k + "=" + encodeURIComponent(h[k]).replace(/%20/g, "+"));
        }
    }
    a.sort();
    return a.length ? "#" + a.join("/") : "";
}

function pa_update_hash(changes) {
    var h = pa_parse_hash(location.hash);
    for (var k in changes) {
        if (changes[k] === null || changes[k] === undefined) {
            delete h[k];
        } else {
            h[k] = changes[k];
        }
    }
    var newhash = pa_unparse_hash(h);
    if (newhash !== location.hash) {
        push_history_state(location.origin + location.pathname + location.search + newhash);
    }
}

(function () {
var hashy = hasClass(document.body, "want-grgraph-hash");

function update(where, color, attr) {
    var key = "data-pa-highlight" + (color === "main" ? "" : "-" + color);
    attr = attr || "";
    $(where).find(".pa-grgraph").each(function () {
        var old_attr = this.getAttribute(key) || "", that = this;
        if (old_attr !== attr
            && (color === "main" || this.getAttribute("data-pa-pset") !== "course")) {
            attr ? this.setAttribute(key, attr) : this.removeAttribute(key);
            window.requestAnimationFrame(function () {
                var gg = $(that).data("paGradeGraph");
                gg && gg.highlight_users();
            });
        }
    });
}

function course_xcdf() {
    var xcdf = null;
    $(".pa-grgraph[data-pa-pset=course]").each(function () {
        var d = $(this).data("paGradeData");
        if (d && d.all && d.all.xcdf) {
            xcdf = d.all.xcdf;
            return false;
        }
    })
    return xcdf;
}

function update_course(str, tries) {
    var ranges = {}, colors = {}, any = false;
    for (var range of str.match(/[-\d.]+/g) || []) {
        ranges[range] = true;
    }
    $(".js-grgraph-highlight-course").each(function () {
        var range = this.getAttribute("data-pa-highlight-range") || "90-100",
            color = this.getAttribute("data-pa-highlight-type") || "h00",
            min, max, m;
        colors[color] = colors[color] || [];
        if ((this.checked = !!ranges[range])) {
            if ((m = range.match(/^([-+]?(?:\d+\.?\d*|\.\d+))-([-+]?(?:\d+\.?\d*|\.\d))(\.?)$/))) {
                colors[color].push(+m[1], +m[2] + (m[3] ? 0.00001 : 0));
                any = true;
            } else if ((m = range.match(/^([-+]?(?:\d+\.?\d*|\.\d+))-$/))) {
                colors[color].push(+m[1], Infinity);
                any = true;
            } else {
                throw new Error("bad range");
            }
        }
    });
    var xcdf;
    if (!any) {
        for (var color in colors)
            update(document.body, color, "");
    } else if ((xcdf = course_xcdf())) {
        for (var color in colors) {
            var ranges = colors[color], a = [];
            if (ranges.length) {
                for (var i = 0; i !== xcdf.length; i += 2) {
                    for (var j = 0; j !== ranges.length; j += 2) {
                        if (xcdf[i] >= ranges[j] && xcdf[i] < ranges[j + 1]) {
                            Array.prototype.push.apply(a, xcdf[i + 1]);
                            break;
                        }
                    }
                }
                a.sort();
            }
            update(document.body, color, a.join(" "));
        }
    } else {
        setTimeout(function () {
            update_course(pa_parse_hash(location.hash).hr || "", tries + 1);
        }, 8 << Math.max(tries, 230));
    }
}

function update_hash(href) {
    var h = pa_parse_hash(href);
    update(document.body, "main", h.hs || "");
    $("input[type=checkbox].js-grgraph-highlight").prop("checked", false);
    for (var uid of (h.hs || "").match(/\d+/g) || []) {
        $("tr[data-pa-uid=" + uid + "] input[type=checkbox].js-grgraph-highlight").prop("checked", true);
    }
    update_course(h.hr || "", 0);
    $(".js-grgraph-highlight-course").prop("checked", false);
    for (var range of (h.hr || "").match(/[-\d.]+/g) || []) {
        $(".js-grgraph-highlight-course[data-pa-highlight-range=\"" + range + "\"]").prop("checked", true);
    }
}

handle_ui.on("js-grgraph-highlight", function (event) {
    if (event.type !== "change")
        return;
    var rt = this.getAttribute("data-range-type"),
        $cb = $(this).closest("form").find("input[type=checkbox]"),
        a = [];
    $(this).closest("form").find("input[type=checkbox]").each(function () {
        var tr;
        if (this.getAttribute("data-range-type") === rt
            && this.checked
            && (tr = this.closest("tr"))
            && tr.hasAttribute("data-pa-uid"))
            a.push(+tr.getAttribute("data-pa-uid"));
    });
    a.sort(function (x, y) { return x - y; });
    if (hashy) {
        pa_update_hash({hs: a.length ? a.join(" ") : null});
        update(document.body, "main", a.join(" "));
    } else {
        update(this.closest("form"), "main", a.join(" "));
    }
});

handle_ui.on("js-grgraph-highlight-course", function (event) {
    var a = [];
    $(".js-grgraph-highlight-course").each(function () {
        if (this.checked)
            a.push(this.getAttribute("data-pa-highlight-range"));
    });
    if (hashy) {
        pa_update_hash({hr: a.length ? a.join(" ") : null});
    }
    update_course(a.join(" "), 0);
});


if (hashy) {
    $(window).on("popstate", function (event) {
        var state = (event.originalEvent || event).state;
        state && state.href && update_hash(state.href);
    }).on("hashchange", function (event) {
        update_hash(location.href);
    });
    $(function () { update_hash(location.href); });
}
})();


$(function () {
var delta = document.body.getAttribute("data-now") - ((new Date).getTime() / 1000);
$(".pa-download-timed").each(function () {
    var that = this, timer = setInterval(show, 15000);
    function show() {
        var downloadat = +that.getAttribute("data-pa-download-at"),
            commitat = +that.getAttribute("data-pa-commit-at"),
            expiry = +that.getAttribute("data-pa-download-expiry"),
            now = ((new Date).getTime() / 1000 + delta), t;
        if (now > expiry) {
            t = strftime("%Y/%m/%d %H:%M", downloadat);
        } else {
            t = Math.round((now - downloadat) / 60) + " min";
        }
        if (commitat > downloadat) {
            t += " · " + Math.round((commitat - downloadat) / 60) + " min before commit";
        }
        $(that).find(".pa-download-timer").text(t);
        if (now > expiry) {
            clearInterval(timer);
        }
    }
    show();
});
});


function pa_gradecdf() {
    var self = this, p = self.getAttribute("data-pa-pset");
    jQuery.ajax(hoturl_post("api/gradestatistics", p ? {pset: p} : {}), {
        type: "GET", cache: true, dataType: "json",
        success: function (d) {
            if (d.all) {
                $(self).data("paGradeData", d);
                pa_draw_gradecdf($(self));
            }
        }
    });
}

function pa_checklatest() {
    var start = (new Date).getTime(), timeout, pset, hash;

    function checkdata(d) {
        if (d && d.hash && d.hash !== hash && (!d.snaphash || d.snaphash !== hash)) {
            jQuery(".pa-commitcontainer .pa-pd").first().append("<div class=\"pa-inf-error\"><span class=\"pa-inf-alert\">Newer commits are available.</span> <a href=\"" + hoturl("pset", {u: peteramati_uservalue, pset: pset, commit: d.hash}) + "\">Load them</a></div>");
            clearTimeout(timeout);
        }
    }

    function docheck() {
        var now = (new Date).getTime();
        if (now - start <= 60000)
            timeout = setTimeout(docheck, hash ? 10000 : 2000);
        else if (now - start <= 600000)
            timeout = setTimeout(docheck, hash ? 20000 : 10000);
        else if (now - start <= 3600000)
            timeout = setTimeout(docheck, (now - start) * 1.25);
        else
            timeout = null;
        jQuery.ajax(hoturl_post("api/latestcommit", {u: peteramati_uservalue, pset: pset}), {
                type: "GET", cache: false, dataType: "json", success: checkdata
            });
    }

    pset = jQuery(".pa-commitcontainer").first().attr("data-pa-pset");
    if (pset) {
        hash = jQuery(".pa-commitcontainer").first().attr("data-pa-commit");
        setTimeout(docheck, 2000);
    }
}

function pa_pset_actions() {
    var $f = $(this);
    function update() {
        var st = $f.find("select[name='state']").val();
        $f.find(".pa-if-enabled").toggleClass("hidden", st === "disabled");
        $f.find(".pa-if-visible").toggleClass("hidden", st === "disabled" || st === "invisible");
    }
    update();
    $f.find("select[name='state']").on("change", update);
    $f.find("input, select").on("change", function () {
        $f.find("[type='submit']").addClass("alert");
    });
    $f.removeClass("need-pa-pset-actions");
}

handle_ui.on("pa-anonymized-link", function (event) {
    var link = this.getAttribute("data-pa-link");
    if (event && event.metaKey) {
        window.open(link);
    } else {
        window.location = link;
    }
});

function pa_render_pset_table(pconf, data) {
    var $j = $(this), table_width = 0, dmap = [],
        $overlay = null, username_col, name_col, slist_input,
        $gdialog, gdialog_su,
        flagged = pconf.flagged_commits,
        visible = pconf.grades_visible,
        grade_entries, grade_keys, need_ngrades,
        sort = {
            f: flagged ? "at" : "username", last: true, rev: 1
        },
        active_nameflag = -1, displaying_last_first = null,
        anonymous = pconf.anonymous,
        col, total_colpos, ngrades_colpos;

    var col_renderers = {
        checkbox: {
            th: '<th class="gt-checkbox" scope="col"></th>',
            td: function (s, rownum) {
                return rownum == "" ? '<td></td>' :
                    '<td class="gt-checkbox"><input type="checkbox" name="' +
                    render_checkbox_name(s) + '" value="1" class="' +
                    (this.className || "uic js-range-click papsel") + '" data-range-type="s61"></td>';
            },
            tw: 1.5
        },
        rownumber: {
            th: '<th class="gt-rownumber" scope="col"></th>',
            td: function (s, rownum) {
                return rownum == "" ? '<td></td>' : '<td class="gt-rownumber">' + rownum + '.</td>';
            },
            tw: Math.ceil(Math.log10(Math.max(data.length, 1))) * 0.75 + 1
        },
        pset: {
            th: '<th class="gt-pset l plsortable" data-pa-sort="pset" scope="col">Pset</th>',
            td: function (s) {
                return '<td class="gt-pset"><a href="' + escaped_href(s) + '" class="track">' +
                   escape_entities(peteramati_psets[s.psetid].title) +
                   (s.hash ? "/" + s.hash.substr(0, 7) : "") + '</a></td>';
            },
            tw: 12
        },
        at: {
            th: '<th class="gt-at l plsortable" data-pa-sort="at" scope="col">Flagged</th>',
            td: function (s) {
                return '<td class="gt-at">' + (s.at ? strftime("%#e %b %#k:%M", s.at) : "") + '</td>';
            },
            tw: 8
        },
        username: {
            th: function () {
                var t = '<span class="heading">' + (anonymous || !sort.email ? "Username" : "Email") + '</span>';
                if (pconf.anonymous && pconf.can_override_anonymous)
                    t += ' <a href="" class="uu n js-switch-anon">[anon]</a>';
                else if (pconf.anonymous)
                    t += ' <span class="n">[anon]</span>';
                return '<th class="gt-username l plsortable" data-pa-sort="username" scope="col">' + t + '</th>';
            },
            td: function (s) {
                return '<td class="gt-username">' + render_username_td(s) + '</td>';
            },
            tw: 12,
            pin: true
        },
        name: {
            th: function () {
                return '<th class="gt-name l plsortable" data-pa-sort="name" scope="col">Name</th>';
            },
            td: function (s) {
                return '<td class="gt-name">' + render_display_name(s, false) + '</td>';
            },
            tw: 14
        },
        name2: {
            th: function () {
                var t = '<span class="heading">' + (anonymous ? "Username" : "Name") + '</span>';
                if (pconf.anonymous && pconf.can_override_anonymous)
                    t += ' <a href="" class="uu n js-switch-anon">[anon]</a>';
                return '<th class="gt-name2 l plsortable" data-pa-sort="name2" scope="col">' + t + '</th>';
            },
            td: function (s) {
                return '<td class="gt-name2">' + render_display_name(s, true) + '</td>';
            },
            tw: 14
        },
        extension: {
            th: '<th class="gt-extension l plsortable" data-pa-sort="extension" scope="col">X?</th>',
            td: function (s) {
                return '<td class="gt-extension">' + (s.x ? "X" : "") + '</td>';
            },
            tw: 2
        },
        grader: {
            th: '<th class="gt-grader l plsortable" data-pa-sort="grader" scope="col">Grader</th>',
            td: function (s) {
                var t = s.gradercid ? "???" : "";
                if (s.gradercid && siteinfo.pc[s.gradercid])
                    t = grader_name(siteinfo.pc[s.gradercid]);
                return '<td class="gt-grader">' + t + '</td>';
            },
            tw: 6
        },
        notes: {
            th: '<th class="gt-notes l plsortable" data-pa-sort="gradestatus" scope="col">⎚</th>',
            td: function (s) {
                var t = '';
                if (s.grades_visible)
                    t += '⎚';
                if (flagged && s.is_grade)
                    t += '✱';
                if (s.has_notes)
                    t += '♪';
                if (!flagged && s.has_nongrader_notes)
                    t += '<sup>*</sup>';
                return '<td class="gt-notes">' + t + '</td>';
            },
            tw: 2
        },
        conversation: {
            th: '<th class="gt-conversation l" scope="col">Flag</th>',
            td: function (s) {
                return '<td class="gt-conversation l">' +
                    escape_entities(s.conversation || s.conversation_pfx || "") +
                    (s.conversation_pfx ? "…" : "") + '</td>';
            },
            tw: 20
        },
        gdialog: {
            th: '<th></th>',
            td: function (s) {
                return '<td><a href="" class="ui x js-gdialog" tabindex="-1" scope="col">Ⓖ</a></td>';
            },
            tw: 1.5,
            pin: true
        },
        total: {
            th: '<th class="gt-total r plsortable" data-pa-sort="total" scope="col">Tot</th>',
            td: function (s) {
                return '<td class="gt-total r">' + s.total + '</td>';
            },
            tw: 3
        },
        grade: {
            th: function () {
                var klass = this.justify === "right" ? "gt-grade r" : "gt-grade l";
                return '<th class="' + klass + ' plsortable" data-pa-sort="grade' + this.gidx + '" scope="col">' + this.gabbr + '</th>';
            },
            td: function (s, rownum, text) {
                var gr = s.grades[this.gidx],
                    gt = escape_entities(this.typeinfo.tcell.call(this.ge, gr));
                if (text) {
                    return gt;
                } else {
                    var t = '<td class="' + this.klass;
                    if (s.highlight_grades && s.highlight_grades[this.gkey]) {
                        t += " gt-highlight";
                    }
                    return t + '">' + gt + '</td>';
                }
            },
            tw: function () {
                var w = 0;
                this.klass = this.gkey === pconf.total_key ? "gt-total" : "gt-grade";
                if (this.justify === "right") {
                    this.klass += " r";
                }
                if (this.typeinfo.type === "select") {
                    this.klass += " gt-el";
                    for (var i = 0; i !== this.ge.options.length; ++i)
                        w = Math.max(w, this.ge.options[i].length);
                    w = Math.floor(Math.min(w, 10) * 1.25) / 2;
                } else {
                    w = 3;
                }
                return Math.max(w, this.gabbr.length * 0.5 + 1.5);
            }
        },
        ngrades: {
            th: '<th class="gt-ngrades r plsortable" data-pa-sort="ngrades" scope="col">#G</th>',
            td: function (s) {
                return '<td class="gt-ngrades r">' + (s.ngrades_nonempty || "") + '</td>';
            },
            tw: 2
        },
        repo: {
            th: '<th class="gt-repo" scope="col"></th>',
            td: function (s) {
                var txt;
                if (!s.repo)
                    txt = '';
                else if (anonymous)
                    txt = '<a href="" data-pa-link="' + escape_entities(s.repo) + '" class="ui pa-anonymized-link">repo</a>';
                else
                    txt = '<a class="track" href="' + escape_entities(s.repo) + '">repo</a>';
                if (s.repo_broken)
                    txt += ' <strong class="err">broken</strong>';
                if (s.repo_unconfirmed)
                    txt += ' <strong class="err">unconfirmed</strong>';
                if (s.repo_too_open)
                    txt += ' <strong class="err">open</strong>';
                if (s.repo_handout_old)
                    txt += ' <strong class="err">handout</strong>';
                if (s.repo_partner_error)
                    txt += ' <strong class="err">partner</strong>';
                if (s.repo_sharing)
                    txt += ' <strong class="err">sharing</strong>';
                return '<td class="gt-repo">' + txt + '</td>';
            },
            tw: 10
        }
    };

    function string_function(s) {
        return function () { return s; };
    }
    function set_sort_nameflag() {
        if (sort.f === "name" || sort.f === "name2" || sort.f === "username"
            || sort.f === "email" || sort.nameflag == null) {
            sort.nameflag = 0;
            if (sort.f === "name" || sort.f === "name2")
                sort.nameflag |= 1;
            if (sort.last)
                sort.nameflag |= 2;
            if (sort.email)
                sort.nameflag |= 4;
            if (anonymous)
                sort.nameflag |= 8;
        }
    }
    function initialize() {
        var x = wstorage.site(true, "pa-pset" + pconf.id + "-table");
        x && (sort = JSON.parse(x));
        if (!sort.f || !/^\w+$/.test(sort.f))
            sort.f = "username";
        if (sort.rev !== 1 && sort.rev !== -1)
            sort.rev = 1;
        if (!anonymous || !pconf.can_override_anonymous || !sort.override_anonymous)
            delete sort.override_anonymous;
        if (anonymous && sort.override_anonymous)
            anonymous = false;
        if (sort.nameflag == null)
            set_sort_nameflag();

        grade_entries = [];
        grade_keys = [];
        var grade_abbr = [];
        if (pconf.grades) {
            var pabbr = {}, grade_titles = [], grade_titles_rest = [];
            for (var i = 0; i !== pconf.grades.order.length; ++i) {
                var k = pconf.grades.order[i], ge = pconf.grades.entries[k];
                if (ge.type !== "text") {
                    grade_entries.push(ge);
                    grade_keys.push(k);
                    var t = ge.title || k;
                    grade_titles.push(t);
                    var m = t.match(/^(p)(?:art\s*|(?=\d))([-.a-z\d]+)(?:[\s:]+|$)/i);
                    m = m || t.match(/^(q)(?:uestion\s*|(?=\d))([-.a-z\d]+)(?:[\s:]+|$)/i);
                    m = m || t.match(/^[\s:]*()(\S{1,3}[\d.]*)[^\s\d]*[\s:]*/);
                    if (!m) {
                        grade_abbr.push(":" + grade_keys.length);
                    } else {
                        var abbr = m[1] + m[2],
                            rest = t.substring(m[0].length),
                            abbrx;
                        while ((abbrx = pabbr[abbr])) {
                            if (abbrx !== true
                                && (m = abbrx[1].match(/^(\S{1,3}[\d.]*)\S*[\s:]*(.*)$/))) {
                                grade_abbr[abbrx[0]] += m[1];
                                pabbr[grade_abbr[abbrx[0]]] = [abbrx[0], m[2]];
                                pabbr[abbr] = abbrx = true;
                            }
                            if ((m = rest.match(/^(\S{1,3}[\d.]*)[^\s\d]*[\s:]*(.*)$/))) {
                                abbr += m[1];
                                rest = m[2];
                            } else {
                                if (abbrx !== true) {
                                    abbr += ":" + grade_keys.length;
                                }
                                break;
                            }
                        }
                        grade_abbr.push(abbr);
                        pabbr[abbr] = [grade_keys.length - 1, rest];
                    }
                }
            }
        }

        var ngrades_expected = -1;
        for (var i = 0; i < data.length; ++i) {
            var s = data[i];
            if (s.dropped)
                s.boringness = 2;
            else if (!s.gradehash && !pconf.gitless_grades)
                s.boringness = 1;
            else
                s.boringness = 0;
            var ngrades = 0;
            for (var j = 0; j < grade_keys.length; ++j) {
                if (grade_keys[j] != pconf.total_key
                    && s.grades[j] != null
                    && s.grades[j] !== "")
                    ++ngrades;
            }
            s.ngrades_nonempty = ngrades;
            if (ngrades_expected === -1)
                ngrades_expected = ngrades;
            else if (ngrades_expected !== ngrades && (!s.boringness || ngrades > 0))
                ngrades_expected = -2;
        }
        need_ngrades = ngrades_expected === -2;

        if (pconf.col) {
            col = pconf.col;
        } else {
            col = [];
            if (pconf.checkbox) {
                col.push("checkbox");
            }
            col.push("rownumber");
            if (flagged) {
                col.push("pset");
                col.push("at");
            } else {
                col.push("gdialog");
            }
            col.push("username", "name", "extension", "grader");
            if (flagged) {
                col.push("conversation");
            }
            if (flagged || !pconf.gitless_grades || visible) {
                col.push("notes");
            }
            if (pconf.need_total) {
                total_colpos = col.length;
                col.push("total");
            }
            for (i = 0; i !== grade_keys.length; ++i) {
                var gt = grade_entries[i].type,
                    typeinfo = pa_grade_types[gt || "numeric"];
                grade_entries[i].colpos = col.length;
                col.push({
                    type: "grade",
                    gidx: i,
                    gkey: grade_keys[i],
                    gabbr: grade_abbr[i],
                    ge: grade_entries[i],
                    typeinfo: typeinfo,
                    justify: typeinfo.justify || "right"
                });
            }
            if (need_ngrades) {
                ngrades_colpos = col.length;
                col.push("ngrades");
            }
            if (!pconf.gitless) {
                col.push("repo");
            }
        }
        for (i = 0; i !== col.length; ++i) {
            if (typeof col[i] === "string") {
                col[i] = {type: col[i]};
            }
            col[i].index = i;
            Object.assign(col[i], col_renderers[col[i].type]);
            if (typeof col[i].th === "string") {
                col[i].th = string_function(col[i].th);
            }
            if (col[i].type === "username" && !username_col) {
                username_col = col[i];
            }
            if (col[i].type === "name" && !name_col) {
                name_col = col[i];
            }
        }
        if ($j[0].closest("form")) {
            slist_input = $('<input name="slist" type="hidden" value="">')[0];
            $j.after(slist_input);
        }
    }

    function ukey(s) {
        return (anonymous && s.anon_username) || s.username || "";
    }
    function url_gradeparts(s) {
        var args = {
            u: ukey(s),
            pset: s.psetid ? peteramati_psets[s.psetid].urlkey : pconf.key
        };
        if (s.hash && (!s.is_grade || flagged)) {
            args.commit = s.hash;
        } else if (s.gradehash) {
            args.commit = s.gradehash;
            args.commit_is_grade = 1;
        }
        return args;
    }
    function escaped_href(s) {
        return escape_entities(hoturl("pset", url_gradeparts(s)));
    }
    function render_student_link(t, s) {
        return '<a href="'.concat(escaped_href(s), '" class="track',
            s.dropped ? ' gt-dropped">' : '">', t, '</a>');
    }
    function render_username_td(s) {
        var un;
        if (anonymous && s.anon_username) {
            un = s.anon_username;
        } else if (sort.email && s.email) {
            un = s.email;
        } else {
            un = s.username || "";
        }
        return render_student_link(escape_entities(un), s);
    }
    function render_name(s, last_first) {
        if (s.first != null && s.last != null) {
            if (last_first)
                return s.last.concat(", ", s.first);
            else
                return s.first.concat(" ", s.last);
        } else if (s.first != null) {
            return s.first;
        } else if (s.last != null) {
            return s.last;
        } else {
            return "";
        }
    }
    function render_display_name(s, is2) {
        var t = escape_entities(is2 && anonymous ? s.anon_username || "?" : render_name(s, displaying_last_first));
        return is2 ? render_student_link(t, s) : t;
    }
    function render_name_text(s) {
        if (s) {
            return (anonymous ? s.anon_username : render_name(s, displaying_last_first)) || "?";
        } else {
            return "[none]";
        }
    }
    function render_checkbox_name(s) {
        var u = anonymous ? s.anon_username || s.username : s.username;
        return "s:" + encodeURIComponent(u).replace(/\./g, "%2E");
    }
    function grader_name(p) {
        if (!p.__nickname) {
            if (p.nick)
                p.__nickname = p.nick;
            else if (p.nicklen || p.lastpos)
                p.__nickname = p.name.substr(0, p.nicklen || p.lastpos - 1);
            else
                p.__nickname = p.name;
        }
        return p.__nickname;
    }

    function make_hotlist(event) {
        var j = [];
        for (var i = 0; i < data.length; ++i) {
            var s = data[i],
                t = "~".concat(encodeURIComponent(ukey(s)));
            if (flagged) {
                t = t.concat("/pset/", peteramati_psets[s.psetid].urlkey);
                if (s.hash)
                    t = t.concat("/", s.hash);
            }
            j.push(t);
        }
        event.hotlist = {pset: flagged ? null : pconf.key, items: j};
    }
    function make_rmap($j) {
        var rmap = {}, tr = $j.find("tbody")[0].firstChild, last = null;
        while (tr) {
            if (tr.hasAttribute("data-pa-partner"))
                last.push(tr);
            else
                rmap[tr.getAttribute("data-pa-spos")] = last = [tr];
            tr = tr.nextSibling;
        }
        return rmap;
    }
    function resort_table($j) {
        var $b = $j.children("tbody"),
            ncol = $j.children("thead")[0].firstChild.childNodes.length,
            tb = $b[0],
            rmap = make_rmap($j),
            i, j, trn = 0, was_boringness = false,
            last = tb.firstChild;
        for (i = 0; i !== data.length; ++i) {
            var s = data[i];
            while ((j = last) && j.className === "gt-boring") {
                last = last.nextSibling;
                tb.removeChild(j);
            }
            if (s.boringness !== was_boringness && was_boringness !== false) {
                tb.insertBefore($('<tr class="gt-boring"><td colspan="' + ncol + '"><hr></td></tr>')[0], last);
            }
            was_boringness = s.boringness;
            var tr = rmap[s._spos];
            for (j = 0; j < tr.length; ++j) {
                if (last !== tr[j])
                    tb.insertBefore(tr[j], last);
                else
                    last = last.nextSibling;
                removeClass(tr[j], "k" + (1 - trn % 2));
                addClass(tr[j], "k" + (trn % 2));
            }
            ++trn;
        }

        var trn = 0;
        $b.find(".gt-rownumber").html(function () {
            ++trn;
            return trn + ".";
        });

        var display_last_first = sort.f && sort.last;
        if (display_last_first !== displaying_last_first) {
            displaying_last_first = display_last_first;
            $b.find(".gt-name, .gt-name2").html(function () {
                var s = dmap[this.parentNode.getAttribute("data-pa-spos")];
                return render_display_name(s, hasClass(this, "gt-name2"));
            });
        }
    }
    function assign_slist() {
        var j = [];
        for (var i = 0; i !== data.length; ++i) {
            j.push(ukey(data[i]));
        }
        slist_input.value = j.join(" ");
    }
    function resort() {
        resort_table($j);
        $overlay && resort_table($overlay);
        slist_input && assign_slist();
        wstorage.site(true, "pa-pset" + pconf.id + "-table", JSON.stringify(sort));
    }
    function make_umap() {
        var umap = {}, tr = $j.find("tbody")[0].firstChild;
        while (tr) {
            umap[tr.getAttribute("data-pa-uid")] = tr;
            tr = tr.nextSibling;
        }
        return umap;
    }
    function rerender_usernames() {
        var $x = $overlay ? $([$j[0], $overlay[0]]) : $j;
        $x.find("td.gt-username").each(function () {
            var s = dmap[this.parentNode.getAttribute("data-pa-spos")];
            $(this).html(render_username_td(s));
        });
        $x.find("th.gt-username > span.heading").html(anonymous || !sort.email ? "Username" : "Email");
        $x.find("td.gt-name2").each(function () {
            var s = dmap[this.parentNode.getAttribute("data-pa-spos")];
            $(this).html(render_display_name(s, true));
        });
        $x.find("th.gt-name2 > span.heading").html(anonymous ? "Username" : "Name");
    }
    function display_anon() {
        $j.toggleClass("gt-anonymous", !!anonymous);
        if (table_width && name_col) {
            $j.css("width", (table_width - (anonymous ? name_col.width : 0)) + "px");
            $($j[0].firstChild).find(".gt-name").css("width", (anonymous ? 0 : name_col.width) + "px");
        }
    }
    function switch_anon(evt) {
        anonymous = !anonymous;
        if (!anonymous)
            sort.override_anonymous = true;
        display_anon();
        rerender_usernames();
        $j.find("tbody input.gt-check").each(function () {
            var s = dmap[this.parentNode.parentNode.getAttribute("data-pa-spos")];
            this.setAttribute("name", render_checkbox_name(s));
        });
        sort_data();
        resort();
        $j.closest("form").find("input[name=anonymous]").val(anonymous ? 1 : 0);
        evt.preventDefault();
        evt.stopPropagation();
    }
    function overlay_create() {
        var li, ri, i, tw = 0, t, a = [];
        for (li = 0; li !== col.length && !col[li].pin; ++li) {
        }
        for (ri = li; ri !== col.length && col[ri].pin; ++ri) {
            tw += col[ri].width;
        }

        t = '<table class="gtable gtable-fixed gtable-overlay new" style="position:absolute;left:-24px;width:' +
            (tw + 24) + 'px"><thead><tr class="k0 kfade"><th style="width:24px"></th>';
        for (i = li; i !== ri; ++i) {
            t += '<th style="width:' + col[i].width + 'px"' +
                col[i].th.call(col[i]).substring(3);
        }
        $overlay = $(t + '</thead><tbody></tbody></table>');

        var tr = $j[0].firstChild.firstChild,
            otr = $overlay[0].firstChild.firstChild;
        for (i = li; i !== ri; ++i) {
            otr.childNodes[i - li + 1].className = tr.childNodes[i].className;
        }

        $j[0].parentNode.prepend($('<div style="position:sticky;left:0;z-index:2"></div>').append($overlay)[0]);
        $overlay.find("thead").on("click", "th", head_click);
        $overlay.find(".js-switch-anon").click(switch_anon);

        tr = $j.children("tbody")[0].firstChild;
        while (tr) {
            if (hasClass(tr, "gt-boring")) {
                a.push('<tr class="gt-boring"><td colspan="' + (ri - li + 1) + '"><hr></td></tr>');
            } else {
                var spos = tr.getAttribute("data-pa-spos"),
                    t = '<tr class="' + tr.className + ' kfade" data-pa-spos="' + spos;
                if (tr.hasAttribute("data-pa-uid")) {
                    t += '" data-pa-uid="' + tr.getAttribute("data-pa-uid");
                }
                if (tr.hasAttribute("data-pa-partner")) {
                    t += '" data-pa-partner="1';
                }
                t += '"><td></td>';
                for (i = li; i !== ri; ++i) {
                    t += '<td style="height:' + tr.childNodes[i].clientHeight +
                        'px"' + col[i].td.call(col[i], dmap[spos], "").substring(3);
                }
                a.push(t + '</tr>');
            }
            tr = tr.nextSibling;
        }
        $overlay.find("tbody").html(a.join(""));
        setTimeout(function () {
            $overlay && removeClass($overlay[0], "new");
        }, 0);
    }
    function render_user_compare(u) {
        var t = "";
        if ((active_nameflag & 8) && u.anon_username) {
            t = u.anon_username + " ";
        } else if (active_nameflag & 1) {
            t = render_name(u, (active_nameflag & 2) === 2) + " ";
        }
        if ((active_nameflag & 4) && u.email) {
            t += u.email;
        } else {
            t += u.username || "";
        }
        if (u.psetid != null)
            t += sprintf(" %5d", u.psetid);
        if (u.at != null)
            t += sprintf(" %11g", u.at);
        return t.toLowerCase();
    }
    function user_compare(a, b) {
        return a._sort_user < b._sort_user ? -sort.rev : (a._sort_user == b._sort_user ? 0 : sort.rev);
    }
    function grader_compare(a, b) {
        var ap = a.gradercid ? siteinfo.pc[a.gradercid] : null;
        var bp = b.gradercid ? siteinfo.pc[b.gradercid] : null;
        var ag = (ap && grader_name(ap)) || "~~~";
        var bg = (bp && grader_name(bp)) || "~~~";
        if (ag != bg)
            return ag < bg ? -sort.rev : sort.rev;
        else
            return 0;
    }
    function set_user_sorters() {
        if (sort.nameflag !== active_nameflag) {
            active_nameflag = sort.nameflag;
            for (var i = 0; i < data.length; ++i) {
                data[i]._sort_user = render_user_compare(data[i]);
            }
        }
    }
    function sort_data() {
        var f = sort.f, rev = sort.rev, m;
        set_user_sorters();
        if ((f === "name" || f === "name2") && !anonymous) {
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else
                    return user_compare(a, b);
            });
        } else if (f === "extension") {
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else if (a.x != b.x)
                    return a.x ? rev : -rev;
                else
                    return user_compare(a, b);
            });
        } else if (f === "grader") {
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else
                    return grader_compare(a, b) || user_compare(a, b);
            });
        } else if (f === "gradestatus") {
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else if (a.grades_visible != b.grades_visible)
                    return a.grades_visible ? -1 : 1;
                else if (a.has_notes != b.has_notes)
                    return a.has_notes ? -1 : 1;
                else
                    return grader_compare(a, b) || user_compare(a, b);
            });
        } else if (f === "pset") {
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else if (a.psetid != b.psetid)
                    return peteramati_psets[a.psetid].pos < peteramati_psets[b.psetid].pos ? -rev : rev;
                else
                    return a.pos < b.pos ? -rev : rev;
            });
        } else if (f === "at") {
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else if (a.at != b.at)
                    return a.at < b.at ? -rev : rev;
                else
                    return a.pos < b.pos ? -rev : rev;
            });
        } else if (f === "total") {
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else if (a.total != b.total)
                    return a.total < b.total ? -rev : rev;
                else
                    return -user_compare(a, b);
            });
        } else if ((m = /^grade(\d+)$/.exec(f)) && grade_entries[+m[1]]) {
            var gidx = +m[1],
                fwd = grade_entries[gidx].type && pa_grade_types[grade_entries[gidx].type].sort === "forward",
                erev = fwd ? -rev : rev;
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else {
                    var ag = a.grades && a.grades[gidx],
                        bg = b.grades && b.grades[gidx];
                    if (ag === "" || ag == null || bg === "" || bg == null) {
                        if (ag !== "" && ag != null)
                            return erev;
                        else if (bg !== "" && bg != null)
                            return -erev;
                        else
                            return -user_compare(a, b);
                    } else if (ag < bg) {
                        return -rev;
                    } else if (ag > bg) {
                        return rev;
                    } else if (fwd) {
                        return user_compare(a, b);
                    } else {
                        return -user_compare(a, b);
                    }
                }
            });
        } else if (f === "ngrades" && need_ngrades) {
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else if (a.ngrades_nonempty !== b.ngrades_nonempty)
                    return a.ngrades_nonempty < b.ngrades_nonempty ? -rev : rev;
                else
                    return -user_compare(a, b);
            });
        } else if (sort.email && !anonymous) {
            f = "username";
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness) {
                    return a.boringness - b.boringness;
                } else {
                    var ae = (a.email || "").toLowerCase(), be = (b.email || "").toLowerCase();
                    if (ae !== be) {
                        if (ae === "" || be === "")
                            return ae === "" ? rev : -rev;
                        else
                            return ae < be ? -rev : rev;
                    } else
                        return user_compare(a, b);
                }
            });
        } else { /* "username" */
            if (f !== "name2") {
                f = "username";
            }
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else
                    return user_compare(a, b);
            });
        }

        var $x = $overlay ? $([$j[0].firstChild, $overlay[0].firstChild]) : $($j[0].firstChild);
        $x.find(".plsortable").removeClass("plsortactive plsortreverse");
        $x.find("th[data-pa-sort='" + f + "']").addClass("plsortactive").
            toggleClass("plsortreverse", sort.rev < 0);
    }
    function head_click(event) {
        if (!this.hasAttribute("data-pa-sort"))
            return;
        var sf = this.getAttribute("data-pa-sort"), m;
        if (sf !== sort.f) {
            sort.f = sf;
            if (sf === "username" || sf === "name" || sf === "name2"
                || sf === "grader" || sf === "extension" || sf === "pset"
                || sf === "at"
                || ((m = sf.match(/^grade(\d+)$/))
                    && grade_entries[m[1]].type
                    && pa_grade_types[grade_entries[m[1]].type].sort === "forward")) {
                sort.rev = 1;
            } else {
                sort.rev = -1;
            }
        } else if (sf === "name" || (sf === "name2" && !anonymous)) {
            sort.rev = -sort.rev;
            if (sort.rev === 1)
                sort.last = !sort.last;
        } else if (sf === "username") {
            if (sort.rev === -1 && !anonymous) {
                sort.email = !sort.email;
                rerender_usernames();
            }
            sort.rev = -sort.rev;
        } else {
            sort.rev = -sort.rev;
        }
        set_sort_nameflag();
        sort_data();
        resort();
    }

    function grade_index(n) {
        n = n.closest("td");
        var i = n.cellIndex, m;
        var table = n.parentElement.parentElement.parentElement;
        var th = table.tHead.firstChild.cells[i];
        var sorter = th ? th.getAttribute("data-pa-sort") : null;
        if (sorter && /^grade\d+$/.test(sorter))
            return +sorter.substring(5);
        else
            return null;
    }
    function gdialog_change() {
        toggleClass(this.closest(".pa-pd"), "pa-grade-changed",
                    this.hasAttribute("data-pa-unmixed") || input_differs(this));
    }
    function grade_update(umap, rv, gorder) {
        var tr = umap[rv.uid],
            su = dmap[tr.getAttribute("data-pa-spos")],
            total = 0, ngrades_nonempty = 0;
        for (var i = 0; i !== gorder.length; ++i) {
            var k = gorder[i], ge = pconf.grades.entries[k], c;
            if (ge && (c = col[ge.colpos])) {
                if (su.grades[ge.pos] !== rv.grades[i]) {
                    su.grades[ge.pos] = rv.grades[i];
                    tr.childNodes[ge.colpos].innerText = c.td.call(c, su, null, true);
                }
                if (rv.grades[i] != null && rv.grades[i] !== "") {
                    ++ngrades_nonempty;
                }
            }
        }
        if (rv.total !== su.total) {
            su.total = rv.total;
            if (total_colpos)
                tr.childNodes[total_colpos].innerText = su.total;
        }
        if (ngrades_nonempty !== su.ngrades_nonempty) {
            su.ngrades_nonempty = ngrades_nonempty;
            if (ngrades_colpos)
                tr.childNodes[ngrades_colpos].innerText = su.ngrades_nonempty || "";
        }
    }
    function gdialog_store_start(rv) {
        $gdialog.find(".has-error").removeClass("has-error");
        if (rv.ok) {
            $gdialog.find(".pa-messages").html("");
        } else {
            $gdialog.find(".pa-messages").html(render_xmsg(2, escape_entities(rv.error)));
            if (rv.errf) {
                $gdialog.find(".pa-gradevalue").each(function () {
                    if (rv.errf[this.name])
                        addClass(this, "has-error");
                });
            }
        }
    }
    function gdialog_store(next) {
        var any = false, byuid = {};
        $gdialog.find(".pa-gradevalue").each(function () {
            if ((this.hasAttribute("data-pa-unmixed") || input_differs(this))
                && !this.indeterminate) {
                var k = this.name, ge = pconf.grades.entries[k], v;
                if (this.type === "checkbox") {
                    v = this.checked ? this.value : "";
                } else {
                    v = $(this).val();
                }
                for (var i = 0; i !== gdialog_su.length; ++i) {
                    var su = gdialog_su[i];
                    byuid[su.uid] = byuid[su.uid] || {grades: {}, oldgrades: {}};
                    byuid[su.uid].grades[k] = v;
                    byuid[su.uid].oldgrades[k] = su.grades[ge.pos];
                }
                any = true;
            }
        });
        if (!any) {
            next();
        } else if (gdialog_su.length === 1) {
            pa_api_conditioner(hoturl_post("api/grade", url_gradeparts(gdialog_su[0])),
                byuid[gdialog_su[0].uid])
            .then(function (rv) {
                gdialog_store_start(rv);
                if (rv.ok) {
                    grade_update(make_umap(), rv, rv.order);
                    next();
                }
            });
        } else {
            for (var i = 0; i !== gdialog_su.length; ++i) {
                if (gdialog_su[i].gradehash) {
                    byuid[gdialog_su[i].uid].commit = gdialog_su[i].gradehash;
                    byuid[gdialog_su[i].uid].commit_is_grade = 1;
                }
            }
            pa_api_conditioner(hoturl_post("api/multigrade", {pset: pconf.key}),
                {us: JSON.stringify(byuid)})
            .then(function (rv) {
                gdialog_store_start(rv);
                if (rv.ok) {
                    var umap = make_umap();
                    for (var i in rv.us) {
                        grade_update(umap, rv.us[i], rv.order);
                    }
                    next();
                }
            });
        }
    }
    function gdialog_traverse() {
        var next_spos = this.getAttribute("data-pa-spos");
        gdialog_store(function () {
            gdialog_fill([next_spos]);
        });
    }
    function gdialog_clear_error() {
        removeClass(this, "has-error");
    }
    function gdialog_fill(spos) {
        gdialog_su = [];
        for (var i = 0; i !== spos.length; ++i) {
            gdialog_su.push(dmap[spos[i]]);
        }
        $gdialog.find("h2").html(escape_entities(pconf.title) + " : " +
            gdialog_su.map(function (su) {
                return escape_entities(anonymous ? su.anon_username : su.username || su.email);
            }).join(", "));
        var su1 = gdialog_su.length === 1 ? gdialog_su[0] : null;
        if (su1) {
            var t = (su1.first || su1.last ? su1.first + " " + su1.last + " " : "") + "<" + su1.email + ">";
            $gdialog.find(".gt-name-email").html(escape_entities(t)).removeClass("hidden");
        } else {
            $gdialog.find(".gt-name-email").addClass("hidden");
        }

        $gdialog.find(".pa-gradelist").toggleClass("pa-pset-hidden",
            !!gdialog_su.find(function (su) { return !su.grades_visible; }));
        $gdialog.find(".pa-grade").each(function () {
            var k = this.getAttribute("data-pa-grade"),
                ge = pconf.grades.entries[k],
                sv = gdialog_su[0].grades[ge.pos],
                mixed = false;
            for (var i = 1; i !== gdialog_su.length; ++i) {
                var suv = gdialog_su[i].grades[ge.pos];
                if (suv !== sv
                    && !(suv == null && sv === "")
                    && !(suv === "" && sv == null)) {
                    mixed = true;
                }
            }
            if (mixed) {
                pa_set_grade.call(this, ge, null, null, {reset: true, mixed: true});
            } else {
                pa_set_grade.call(this, ge, sv, null, {reset: true});
            }
        });
        if (su1) {
            var tr = $j.find("tbody")[0].firstChild, tr1;
            while (tr && tr.getAttribute("data-pa-spos") != su1._spos) {
                tr = tr.nextSibling;
            }
            for (tr1 = tr; tr1 && (tr1 === tr || !tr1.hasAttribute("data-pa-spos")); ) {
                tr1 = tr1.previousSibling;
            }
            $gdialog.find("button[name=prev]").attr("data-pa-spos", tr1 ? tr1.getAttribute("data-pa-spos") : "").prop("disabled", !tr1);
            for (tr1 = tr; tr1 && (tr1 === tr || !tr1.hasAttribute("data-pa-spos")); ) {
                tr1 = tr1.nextSibling;
            }
            $gdialog.find("button[name=next]").attr("data-pa-spos", tr1 ? tr1.getAttribute("data-pa-spos") : "").prop("disabled", !tr1);
        }
    }
    function gdialog_key(event) {
        if (event.ctrlKey
            && (event.key === "n" || event.key === "p")
            && ($b = $gdialog.find("button[name=" + (event.key === "n" ? "next" : "prev") + "]"))
            && !$b[0].disabled) {
            gdialog_traverse.call($b[0]);
            event.stopImmediatePropagation();
            event.preventDefault();
        } else if (event.key === "Return" || event.key === "Enter") {
            gdialog_store(function () { $gdialog.close(); });
            event.stopImmediatePropagation();
            event.preventDefault();
        } else if (event.key === "Esc" || event.key === "Escape") {
            event.stopImmediatePropagation();
            $gdialog.close();
        } else if (event.key === "Backspace" && this.hasAttribute("placeholder")) {
            gdialog_input.call(this);
        }
    }
    function gdialog_input() {
        if (this.hasAttribute("placeholder")) {
            this.setAttribute("data-pa-unmixed", 1);
            this.removeAttribute("placeholder");
            gdialog_change.call(this);
        }
    }
    function gdialog() {
        var hc = popup_skeleton();
        hc.push('<h2></h2>');
        if (!anonymous)
            hc.push('<strong class="gt-name-email"></strong>');
        hc.push('<div class="pa-messages"></div>');

        hc.push('<div class="pa-gradelist in-modal">', '</div>');
        for (var i = 0; i !== grade_entries.length; ++i) {
            hc.push(pa_render_grade_entry(grade_entries[i], true, false));
        }
        hc.pop();
        hc.push_actions();
        hc.push('<button type="button" name="bsubmit" class="btn-primary">Save</button>');
        hc.push('<button type="button" name="cancel">Cancel</button>');
        hc.push('<button type="button" name="prev" class="btnl">&lt;</button>');
        hc.push('<button type="button" name="next" class="btnl">&gt;</button>');
        $gdialog = hc.show(false);
        $gdialog.children(".modal-dialog").addClass("modal-dialog-wide");

        var checked_spos = $j.find(".papsel:checked").toArray().map(function (x) {
                return x.parentElement.parentElement.getAttribute("data-pa-spos");
            }),
            my_spos = this.closest("tr").getAttribute("data-pa-spos");
        if (checked_spos.indexOf(my_spos) < 0) {
            gdialog_fill([my_spos]);
        } else {
            $gdialog.find("button[name=prev], button[name=next]").prop("disabled", true).addClass("hidden");
            gdialog_fill(checked_spos);
        }
        $gdialog.on("change blur", ".pa-gradevalue", gdialog_change);
        $gdialog.on("input change", ".pa-gradevalue", gdialog_clear_error);
        $gdialog.on("keydown", gdialog_key);
        $gdialog.on("keydown", "input, textarea, select", gdialog_key);
        $gdialog.on("input", "input, textarea, select", gdialog_input);
        $gdialog.find("button[name=bsubmit]").on("click", function () {
            gdialog_store(function () { $gdialog.close(); });
        });
        $gdialog.find("button[name=prev], button[name=next]").on("click", gdialog_traverse);
        hc.show();
    }
    $j.parent().on("click", "a.js-gdialog", function (event) {
        gdialog.call(this);
        event.preventDefault();
    });

    function make_overlay_observer() {
        for (var i = 0; i !== col.length && !col[i].pin; ++i) {
        }
        var overlay_div = $('<div style="position:absolute;left:0;top:0;bottom:0;width:' + (col[i].left - 10) + 'px;pointer-events:none"></div>').prependTo($j.parent())[0],
            table_hit = false, left_hit = false;
        function observer_fn(entries) {
            for (var e of entries) {
                if (e.target === overlay_div) {
                    left_hit = e.isIntersecting;
                } else {
                    table_hit = e.isIntersecting;
                }
            }
            if (table_hit && !left_hit && !$overlay) {
                overlay_create();
            } else if (table_hit && left_hit && $overlay) {
                $overlay.parent().remove();
                $overlay = null;
            }
        }
        var observer = new IntersectionObserver(observer_fn);
        observer.observe(overlay_div);
        observer.observe($j.parent()[0]);
    }
    function render_tds(s, rownum) {
        var a = [];
        for (var i = 0; i !== col.length; ++i)
            a.push(col[i].td.call(col[i], s, rownum));
        return a;
    }
    function render() {
        var thead = $('<thead><tr class="k0"></tr></thead>')[0],
            tfixed = $j.hasClass("want-gtable-fixed"),
            rem = parseFloat(window.getComputedStyle(document.documentElement).fontSize);
        for (var i = 0; i !== col.length; ++i) {
            var th = col[i].th.call(col[i]), $th = $(th);
            if (tfixed) {
                col[i].left = table_width;
                var w = col[i].tw;
                if (typeof w !== "number") {
                    w = w.call(col[i]);
                }
                w *= rem;
                col[i].width = w;
                $th.css("width", w + "px");
                table_width += w;
            }
            thead.firstChild.appendChild($th[0]);
        }
        display_anon();
        $j[0].appendChild(thead);
        $j.toggleClass("gt-useemail", !!sort.email);
        $j.find("thead").on("click", "th", head_click);
        $j.find(".js-switch-anon").click(switch_anon);
        if (tfixed) {
            $j.removeClass("want-gtable-fixed").css("table-layout", "fixed");
        }

        var tbody = $('<tbody class="has-hotlist"></tbody>')[0],
            trn = 0, was_boringness = 0, a = [];
        $j[0].appendChild(tbody);
        if (!pconf.no_sort) {
            sort_data();
        }
        displaying_last_first = sort.f === "name" && sort.last;
        for (var i = 0; i !== data.length; ++i) {
            var s = data[i];
            s._spos = dmap.length;
            dmap.push(s);
            ++trn;
            if (s.boringness !== was_boringness && trn != 1)
                a.push('<tr class="gt-boring"><td colspan="' + col.length + '"><hr></td></tr>');
            was_boringness = s.boringness;
            var stds = render_tds(s, trn);
            var t = '<tr class="k' + (trn % 2) + '" data-pa-spos="' + s._spos;
            if (s.uid)
                t += '" data-pa-uid="' + s.uid;
            a.push(t + '">' + stds.join('') + '</tr>');
            for (var j = 0; s.partners && j < s.partners.length; ++j) {
                var ss = s.partners[j];
                ss._spos = dmap.length;
                dmap.push(ss);
                var sstds = render_tds(s.partners[j], "");
                for (var k = 0; k < sstds.length; ++k) {
                    if (sstds[k] === stds[k])
                        sstds[k] = '<td></td>';
                }
                t = '<tr class="k' + (trn % 2) + ' gtrow-partner" data-pa-spos="' + ss._spos;
                if (ss.uid)
                    t += '" data-pa-uid="' + ss.uid;
                a.push(t + '" data-pa-partner="1">' + sstds.join('') + '</tr>');
            }
            if (a.length > 50) {
                $(a.join('')).appendTo(tbody);
                a = [];
            }
        }
        if (a.length !== 0) {
            $(a.join('')).appendTo(tbody);
        }
        slist_input && assign_slist();

        if (tfixed && window.IntersectionObserver) {
            make_overlay_observer();
        }
    }

    initialize();
    render();

    $j.data("paTable", {
        name_text: function (uid) {
            var spos = $j.find("tr[data-pa-uid=" + uid + "]").attr("data-pa-spos");
            return spos ? render_name_text(dmap[spos]) : null;
        }
    });
    $j.children("tbody").on("pa-hotlist", make_hotlist);
}


// autogrowing text areas; based on https://github.com/jaz303/jquery-grab-bag
function textarea_shadow($self, width) {
    return jQuery("<div></div>").css({
        position:    'absolute',
        top:         -10000,
        left:        -10000,
        width:       width || $self.width(),
        fontSize:    $self.css('fontSize'),
        fontFamily:  $self.css('fontFamily'),
        fontWeight:  $self.css('fontWeight'),
        lineHeight:  $self.css('lineHeight'),
        resize:      'none',
        'word-wrap': 'break-word',
        whiteSpace:  'pre-wrap'
    }).appendTo(document.body);
}

(function ($) {
var autogrowers = null;
function resizer() {
    for (var i = autogrowers.length - 1; i >= 0; --i)
        autogrowers[i]();
}
function remover($self, shadow) {
    var f = $self.data("autogrower");
    $self.removeData("autogrower");
    shadow && shadow.remove();
    for (var i = autogrowers.length - 1; i >= 0; --i)
        if (autogrowers[i] === f) {
            autogrowers[i] = autogrowers[autogrowers.length - 1];
            autogrowers.pop();
        }
}
function make_textarea_autogrower($self) {
    var shadow, minHeight, lineHeight;
    return function (event) {
        if (event === false)
            return remover($self, shadow);
        var width = $self.width();
        if (width <= 0)
            return;
        if (!shadow) {
            shadow = textarea_shadow($self, width);
            minHeight = $self.height();
            lineHeight = shadow.text("!").height();
        }

        // Did enter get pressed?  Resize in this keydown event so that the flicker doesn't occur.
        var val = $self[0].value;
        if (event && event.type == "keydown" && event.keyCode === 13)
            val += "\n";
        shadow.css("width", width).text(val + "...");

        var wh = Math.max($(window).height() - 4 * lineHeight, 4 * lineHeight);
        $self.height(Math.min(wh, Math.max(shadow.height(), minHeight)));
    };
}
function make_input_autogrower($self) {
    var shadow;
    return function (event) {
        if (event === false) {
            return remover($self, shadow);
        }
        var width = 0, ws;
        try {
            width = $self.outerWidth();
        } catch (e) { // IE11 is annoying here
        }
        if (width <= 0) {
            return;
        }
        if (!shadow) {
            shadow = textarea_shadow($self, width);
            var p = $self.css(["paddingRight", "paddingLeft", "borderLeftWidth", "borderRightWidth"]);
            shadow.css({
                width: "auto",
                display: "table-cell",
                paddingLeft: p.paddingLeft,
                paddingLeft: (parseFloat(p.paddingRight) + parseFloat(p.paddingLeft) + parseFloat(p.borderLeftWidth) + parseFloat(p.borderRightWidth)) + "px"
            });
            ws = $self.css(["minWidth", "maxWidth"]);
            if (ws.minWidth === "0px") {
                $self.css("minWidth", width + "px");
            }
            if (ws.maxWidth === "none" && !$self.hasClass("wide")) {
                $self.css("maxWidth", "640px");
            }
        }
        shadow.text($self[0].value + "  ");
        ws = $self.css(["minWidth", "maxWidth"]);
        var outerWidth = Math.min(shadow.outerWidth(), $(window).width()),
            maxWidth = parseFloat(ws.maxWidth);
        if (maxWidth === maxWidth) { // i.e., isn't NaN
            outerWidth = Math.min(outerWidth, maxWidth);
        }
        $self.outerWidth(Math.max(outerWidth, parseFloat(ws.minWidth)));
    };
}
$.fn.autogrow = function () {
    this.each(function () {
        var $self = $(this), f = $self.data("autogrower");
        removeClass(this, "need-autogrow");
        if (!f) {
            if (this.tagName === "TEXTAREA") {
                f = make_textarea_autogrower($self);
            } else if (this.tagName === "INPUT" && this.type === "text") {
                f = make_input_autogrower($self);
            }
            if (f) {
                $self.data("autogrower", f).on("change input", f);
                if (!autogrowers) {
                    autogrowers = [];
                    $(window).resize(resizer);
                }
                autogrowers.push(f);
            }
        }
        if (f && $self.val() !== "") {
            f();
        }
    });
    return this;
};
$.fn.unautogrow = function () {
    this.each(function () {
        var f = $(this).data("autogrower");
        f && f(false);
    });
    return this;
};
})(jQuery);

$(function () { $(".need-autogrow").autogrow(); });

window.$pa = {
    beforeunload: pa_beforeunload,
    checklatest: pa_checklatest,
    crpfocus: crpfocus, // XXX
    filediff_markdown: filediff_markdown,
    fold: fold,
    gradecdf: pa_gradecdf,
    onload: hotcrp_load,
    loadgrades: pa_loadgrades,
    load_runsettings: pa_runsetting.load,
    pset_actions: pa_pset_actions,
    render_text_page: render_text.on_page,
    render_pset_table: pa_render_pset_table,
    runmany: runmany61
};
