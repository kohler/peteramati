// script.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2016 Eddie Kohler
// See LICENSE for open-source distribution terms

var siteurl, siteurl_postvalue, siteurl_suffix, siteurl_defaults,
    siteurl_absolute_base,
    hotcrp_paperid, hotcrp_list, hotcrp_status, hotcrp_user,
    peteramati_uservalue, peteramati_psets,
    hotcrp_want_override_conflict;

function $$(id) {
    return document.getElementById(id);
}

function serialize_object(x) {
    if (typeof x === "string")
        return x;
    else if (x) {
        var k, v, a = [];
        for (k in x)
            if ((v = x[k]) != null)
                a.push(encodeURIComponent(k) + "=" + encodeURIComponent(v));
        return a.join("&");
    } else
        return "";
}


function bind_append(f, args) {
    return function () {
        var a = Array.prototype.slice.call(arguments);
        a.push.apply(a, args);
        return f.apply(this, a);
    };
}

// callback combination
function add_callback(cb1, cb2) {
    if (cb1 && cb2)
        return function () {
            cb1.apply(this, arguments);
            cb2.apply(this, arguments);
        };
    else
        return cb1 || cb2;
}


// promises
function HPromise(value) {
    this.value = value;
    this.state = value === undefined ? false : 1;
    this.c = [];
}
HPromise.prototype.then = function (yes, no) {
    var next = new HPromise;
    this.c.push([no, yes, next]);
    if (this.state !== false)
        this._resolve();
    else if (this.on) {
        this.on(this);
        this.on = null;
    }
    return next;
};
HPromise.prototype._resolve = function () {
    var i, x, f, v;
    for (i in this.c) {
        x = this.c[i];
        f = x[this.state];
        if ($.isFunction(f)) {
            try {
                v = f(this.value);
                x[2].fulfill(v);
            } catch (e) {
                x[2].reject(e);
            }
        } else
            x[2][this.state ? "fulfill" : "reject"](this.value);
    }
    this.c = [];
};
HPromise.prototype.fulfill = function (value) {
    if (this.state === false) {
        this.value = value;
        this.state = 1;
        this._resolve();
    }
};
HPromise.prototype.reject = function (reason) {
    if (this.state === false) {
        this.value = reason;
        this.state = 0;
        this._resolve();
    }
};
HPromise.prototype.onThen = function (f) {
    this.on = add_callback(this.on, f);
    return this;
};


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
    $.ajax(hoturl("api", "fn=jserror"), {
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
    if (status == "parsererror")
        return "Internal error: bad response from server.";
    else if (errormsg)
        return errormsg.toString();
    else if (status == "timeout")
        return "Connection timed out.";
    else if (status)
        return "Failed [" + status + "].";
    else
        return "Failed.";
}

$(document).ajaxError(function (event, jqxhr, settings, httperror) {
    if (jqxhr.readyState == 4)
        log_jserror(settings.url + " API failure: status " + jqxhr.status + ", " + httperror);
});

$.ajaxPrefilter(function (options, originalOptions, jqxhr) {
    if (options.global === false)
        return;
    var f = options.success;
    function onerror(jqxhr, status, errormsg) {
        f && f({ok: false, error: jqxhr_error_message(jqxhr, status, errormsg)}, jqxhr, status);
    }
    if (!options.error)
        options.error = onerror;
    else if ($.isArray(options.error))
        options.error.push(onerror);
    else
        options.error = [options.error, onerror];
    if (options.timeout == null)
        options.timeout = 10000;
    if (options.method == null)
        options.method = "POST";
    if (options.dataType == null)
        options.dataType = "json";
});


// geometry
jQuery.fn.extend({
    geometry: function (outer) {
        var g, d;
        if (this[0] == window)
            g = {left: this.scrollLeft(), top: this.scrollTop()};
        else if (this.length == 1 && this[0].getBoundingClientRect) {
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
        } else
            g = this.offset();
        if (g) {
            g.width = outer ? this.outerWidth() : this.width();
            g.height = outer ? this.outerHeight() : this.height();
            g.right = g.left + g.width;
            g.bottom = g.top + g.height;
        }
        return g;
    },
    scrollIntoView: function () {
        var p = this.geometry(), x = this[0].parentNode;
        while (x && x.tagName && $(x).css("overflow-y") === "visible")
            x = x.parentNode;
        var w = jQuery(x && x.tagName ? x : window).geometry();
        if (p.top < w.top)
            this[0].scrollIntoView();
        else if (p.bottom > w.bottom)
            this[0].scrollIntoView(false);
        return this;
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


// text transformation
window.escape_entities = (function () {
    var re = /[&<>\"]/g, rep = {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return s.replace(re, function (match) { return rep[match]; });
    };
})();

window.unescape_entities = (function () {
    var re = /&.*?;/g, rep = {"&amp;": "&", "&lt;": "<", "&gt;": ">", "&quot;": "\"", "&apos;": "'", "&#039;": "'"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return s.replace(re, function (match) { return rep[match]; });
    };
})();

function text_to_html(text) {
    var n = document.createElement("div");
    n.appendChild(document.createTextNode(text));
    return n.innerHTML;
}

function text_eq(a, b) {
    if (a === b)
        return true;
    a = (a == null ? "" : a).replace(/\r\n?/g, "\n");
    b = (b == null ? "" : b).replace(/\r\n?/g, "\n");
    return a === b;
}

function regexp_quote(s) {
    return String(s).replace(/([-()\[\]{}+?*.$\^|,:#<!\\])/g, '\\$1').replace(/\x08/g, '\\x08');
}

function html_id_encode(s) {
    return encodeURIComponent(s).replace(/[^-A-Za-z0-9%]/g, function (s) {
        return "_" + s.charCodeAt(0).toString(16);
    }).replace(/%../g, function (m) { return "_" + m.substr(1).toLowerCase(); });
}

function html_id_decode(s) {
    return decodeURIComponent(s.replace(/_/g, "%"));
}

function plural_noun(n, what) {
    if (jQuery.isArray(n))
        n = n.length;
    if (n == 1)
        return what;
    if (what == "this")
        return "these";
    if (/^.*?(?:s|sh|ch|[bcdfgjklmnpqrstvxz][oy])$/.test(what)) {
        if (what.substr(-1) == "y")
            return what.substr(0, what.length - 1) + "ies";
        else
            return what + "es";
    } else
        return what + "s";
}

function plural(n, what) {
    if (jQuery.isArray(n))
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
    var words = fmt.split(/(%(?:%|-?(?:\d*|\*?)(?:[.]\d*)?[sdefgoxX]))/), wordno, word,
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
            if (conv[4] >= "e" && conv[4] <= "g" && conv[3] == null)
                conv[3] = 6;
            if (conv[4] == "g") {
                arg = Number(arg).toPrecision(conv[3]).toString();
                arg = arg.replace(/\.(\d*[1-9])?0+(|e.*)$/,
                                  function (match, p1, p2) {
                                      return (p1 == null ? "" : "." + p1) + p2;
                                  });
            } else if (conv[4] == "f")
                arg = Number(arg).toFixed(conv[3]);
            else if (conv[4] == "e")
                arg = Number(arg).toExponential(conv[3]);
            else if (conv[4] == "d")
                arg = Math.floor(arg);
            else if (conv[4] == "o")
                arg = Math.floor(arg).toString(8);
            else if (conv[4] == "x")
                arg = Math.floor(arg).toString(16);
            else if (conv[4] == "X")
                arg = Math.floor(arg).toString(16).toUpperCase();
            arg = arg.toString();
            if (conv[2] !== "" && conv[2] !== "0") {
                pad = conv[2].charAt(0) === "0" ? "0" : " ";
                while (arg.length < parseInt(conv[2], 10))
                    arg = conv[1] ? arg + pad : pad + arg;
            }
            t += arg;
        }
    }
    return t;
}

window.strftime = (function () {
    function pad(num, str, n) {
        str += num.toString();
        return str.length <= n ? str : str.substr(str.length - n);
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
        p: function (d) { return d.getHours() < 12 ? "AM" : "PM"; },
        P: function (d) { return d.getHours() < 12 ? "am" : "pm"; },
        r: function (d, alt) {
            if (alt && d.getSeconds())
                return strftime("%#l:%M:%S%P", d);
            else if (alt && d.getMinutes())
                return strftime("%#l:%M%P", d);
            else if (alt)
                return strftime("%#l%P", d);
            else
                return strftime("%I:%M:%S %p", d);
        },
        R: function (d, alt) {
            if (alt && d.getSeconds())
                return strftime("%H:%M:%S", d);
            else
                return strftime("%H:%M", d);
        },
        S: function (d) { return pad(d.getSeconds(), "0", 2); },
        T: function (d) { return strftime("%H:%M:%S", d); },
        /* XXX z Z */
        D: function (d) { return strftime("%m/%d/%y", d); },
        F: function (d) { return strftime("%Y-%m-%d", d); },
        s: function (d) { return Math.trunc(d.getTime() / 1000); },
        n: function (d) { return "\n"; },
        t: function (d) { return "\t"; },
        "%": function (d) { return "%"; }
    };
    return function(fmt, d) {
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
})();


// events
function event_stop(evt) {
    if (evt.stopPropagation)
        evt.stopPropagation();
    else
        evt.cancelBubble = true;
}

function event_prevent(evt) {
    if (evt.preventDefault)
        evt.preventDefault();
    else
        evt.returnValue = false;
}

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
        "PageUp": 1,
        "PageDown": 1,
        "Escape": 1,
        "Enter": 1
    };
function event_key(evt) {
    var x;
    if (typeof evt === "string")
        return evt;
    if ((x = evt.key) != null)
        return key_map[x] || x;
    if ((x = evt.charCode))
        return charCode_map[x] || String.fromCharCode(x);
    if ((x = evt.keyCode)) {
        if (keyCode_map[x])
            return keyCode_map[x];
        else if ((x >= 48 && x <= 57) || (x >= 65 && x <= 90))
            return String.fromCharCode(x);
    }
    return "";
}
event_key.printable = function (evt) {
    return !nonprintable_map[event_key(evt)];
};
event_key.modifier = function (evt) {
    return nonprintable_map[event_key(evt)] > 1;
};
return event_key;
})();

function event_modkey(evt) {
    return (evt.shiftKey ? 1 : 0) + (evt.ctrlKey ? 2 : 0) + (evt.altKey ? 4 : 0) + (evt.metaKey ? 8 : 0);
}
event_modkey.SHIFT = 1;
event_modkey.CTRL = 2;
event_modkey.ALT = 4;
event_modkey.META = 8;

function make_onkeypress_enter(f) {
    return function (evt) {
        if (!event_modkey(evt) && event_key(evt) == "Enter") {
            evt.preventDefault();
            evt.stopImmediatePropagation();
            f.call(this);
            return false;
        } else
            return true;
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
    return x ? jQuery.parseJSON(x) : false;
};


// hoturl
function hoturl_add(url, component) {
    return url + (url.indexOf("?") < 0 ? "?" : "&") + component;
}

function hoturl_clean_before(x, page_component, prefix) {
    if (x.first !== false && x.v.length) {
        for (var i = 0; i < x.v.length; ++i) {
            var m = page_component.exec(x.v[i]);
            if (m) {
                x.pt += prefix + m[1] + "/";
                x.v.splice(i, 1);
                x.first = m[1];
                return;
            }
        }
        x.first = false;
    }
}

function hoturl_clean(x, page_component) {
    if (x.last !== false && x.v.length) {
        for (var i = 0; i < x.v.length; ++i) {
            var m = page_component.exec(x.v[i]);
            if (m) {
                x.t += "/" + m[1];
                x.v.splice(i, 1);
                x.last = m[1];
                return;
            }
        }
        x.last = false;
    }
}

function hoturl(page, options) {
    var k, v, t, a, m, x, anchor = "", want_forceShow;
    if (siteurl == null || siteurl_suffix == null) {
        siteurl = siteurl_suffix = "";
        log_jserror("missing siteurl");
    }

    x = {pt: "", t: page + siteurl_suffix};
    if (typeof options === "string") {
        if ((m = options.match(/^(.*?)(#.*)$/))) {
            options = m[1];
            anchor = m[2];
        }
        x.v = options.split(/&/);
    } else {
        x.v = [];
        for (k in options) {
            v = options[k];
            if (v == null)
                /* skip */;
            else if (k === "anchor")
                anchor = "#" + v;
            else
                x.v.push(encodeURIComponent(k) + "=" + encodeURIComponent(v));
        }
    }

    if (page === "help")
        hoturl_clean(x, /^t=(\w+)$/);
    else if (page.substr(0, 3) === "api") {
        if (page.length > 3) {
            x.t = "api" + siteurl_suffix;
            x.v.push("fn=" + page.substr(4));
        }
        hoturl_clean_before(x, /^u=([^?&#]+)$/, "~");
        hoturl_clean(x, /^fn=(\w+)$/);
        hoturl_clean(x, /^pset=([^?&#]+)$/);
        hoturl_clean(x, /^commit=([0-9A-Fa-f]+)$/);
        want_forceShow = true;
    } else if (page === "index")
        hoturl_clean_before(x, /^u=([^?&#]+)$/, "~");
    else if (page === "pset" || page === "run") {
        hoturl_clean_before(x, /^u=([^?&#]+)$/, "~");
        hoturl_clean(x, /^pset=([^?&#]+)$/);
        hoturl_clean(x, /^commit=([0-9A-Fa-f]+)$/);
    }

    if (hotcrp_want_override_conflict && want_forceShow
        && hoturl_clean_find(x.v, /^forceShow=/) < 0)
        x.v.push("forceShow=1");

    if (siteurl_defaults)
        x.v.push(serialize_object(siteurl_defaults));
    if (x.v.length)
        x.t += "?" + x.v.join("&");
    return siteurl + x.pt + x.t + anchor;
}

function hoturl_post(page, options) {
    options = serialize_object(options);
    options += (options ? "&" : "") + "post=" + siteurl_postvalue;
    return hoturl(page, options);
}

function url_absolute(url, loc) {
    var x = "", m;
    loc = loc || window.location.href;
    if (!/^\w+:\/\//.test(url)
        && (m = loc.match(/^(\w+:)/)))
        x = m[1];
    if (x && !/^\/\//.test(url)
        && (m = loc.match(/^\w+:(\/\/[^\/]+)/)))
        x += m[1];
    if (x && !/^\//.test(url)
        && (m = loc.match(/^\w+:\/\/[^\/]+(\/[^?#]*)/))) {
        x = (x + m[1]).replace(/\/[^\/]+$/, "/");
        while (url.substring(0, 3) == "../") {
            x = x.replace(/\/[^\/]*\/$/, "/");
            url = url.substring(3);
        }
    }
    return x + url;
}

function hoturl_absolute_base() {
    if (!siteurl_absolute_base)
        siteurl_absolute_base = url_absolute(siteurl);
    return siteurl_absolute_base;
}


// rangeclick
function rangeclick(evt, elt, kind) {
    elt = elt || this;
    var jelt = jQuery(elt), jform = jelt.closest("form"), kindsearch;
    if ((kind = kind || jelt.attr("data-range-type")))
        kindsearch = "[data-range-type~='" + kind + "']";
    else
        kindsearch = "[name='" + elt.name + "']";
    var cbs = jform.find("input[type=checkbox]" + kindsearch);

    var lastelt = jform.data("rangeclick_last_" + kindsearch),
        thispos, lastpos, i, j, x;
    for (i = 0; i != cbs.length; ++i) {
        if (cbs[i] == elt)
            thispos = i;
        if (cbs[i] == lastelt)
            lastpos = i;
    }
    jform.data("rangeclick_last_" + kindsearch, elt);

    if (evt.shiftKey && lastelt) {
        if (lastpos <= thispos) {
            i = lastpos;
            j = thispos - 1;
        } else {
            i = thispos + 1;
            j = lastpos;
        }
        for (; i <= j; ++i)
            cbs[i].checked = elt.checked;
    }

    return true;
}


// bubbles and tooltips
var make_bubble = (function () {
var capdir = ["Top", "Right", "Bottom", "Left"],
    lcdir = ["top", "right", "bottom", "left"],
    szdir = ["height", "width"],
    SPACE = 8;

function cssborder(dir, suffix) {
    return "border" + capdir[dir] + suffix;
}

function cssbc(dir) {
    return cssborder(dir, "Color");
}

var roundpixel = Math.round;
if (window.devicePixelRatio && window.devicePixelRatio > 1)
    roundpixel = (function (dpr) {
        return function (x) { return Math.round(x * dpr) / dpr; };
    })(window.devicePixelRatio);

function to_rgba(c) {
    var m = c.match(/^rgb\((.*)\)$/);
    return m ? "rgba(" + m[1] + ", 1)" : c;
}

function make_model(color) {
    return $('<div class="bubble hidden' + color + '"><div class="bubtail bubtail0' + color + '"></div></div>').appendTo(document.body);
}

function calculate_sizes(color) {
    var $model = make_model(color), tail = $model.children(), ds, x;
    var sizes = [tail.width(), tail.height()];
    for (ds = 0; ds < 4; ++ds) {
        sizes[lcdir[ds]] = 0;
        if ((x = $model.css("margin" + capdir[ds])) && (x = parseFloat(x)))
            sizes[lcdir[ds]] = x;
    }
    $model.remove();
    return sizes;
}

return function (content, bubopt) {
    if (!bubopt && content && typeof content === "object") {
        bubopt = content;
        content = bubopt.content;
    } else if (!bubopt)
        bubopt = {};
    else if (typeof bubopt === "string")
        bubopt = {color: bubopt};

    var nearpos = null, dirspec = bubopt.dir, dir = null,
        color = bubopt.color ? " " + bubopt.color : "";

    var bubdiv = $('<div class="bubble' + color + '" style="margin:0"><div class="bubtail bubtail0' + color + '" style="width:0;height:0"></div><div class="bubcontent"></div><div class="bubtail bubtail1' + color + '" style="width:0;height:0"></div></div>')[0];
    document.body.appendChild(bubdiv);
    if (bubopt["pointer-events"])
        $(bubdiv).css({"pointer-events": bubopt["pointer-events"]});
    var bubch = bubdiv.childNodes;
    var sizes = null;
    var divbw = null;

    function change_tail_direction() {
        var bw = [0, 0, 0, 0], trw = sizes[1], trh = sizes[0] / 2;
        divbw = parseFloat($(bubdiv).css(cssborder(dir, "Width")));
        divbw !== divbw && (divbw = 0); // eliminate NaN
        bw[dir^1] = bw[dir^3] = trh + "px";
        bw[dir^2] = trw + "px";
        bubch[0].style.borderWidth = bw.join(" ");
        bw[dir^1] = bw[dir^3] = trh + "px";
        bw[dir^2] = trw + "px";
        bubch[2].style.borderWidth = bw.join(" ");

        for (var i = 1; i <= 3; ++i)
            bubch[0].style[lcdir[dir^i]] = bubch[2].style[lcdir[dir^i]] = "";
        bubch[0].style[lcdir[dir]] = (-trw - divbw) + "px";
        // Offset the inner triangle so that the border width in the diagonal
        // part of the tail, is visually similar to the border width
        var trdelta = (divbw / trh) * Math.sqrt(trw * trw + trh * trh);
        bubch[2].style[lcdir[dir]] = (-trw - divbw + trdelta) + "px";

        for (i = 0; i < 3; i += 2)
            bubch[i].style.borderLeftColor = bubch[i].style.borderRightColor =
            bubch[i].style.borderTopColor = bubch[i].style.borderBottomColor = "transparent";

        var yc = to_rgba($(bubdiv).css("backgroundColor")).replace(/([\d.]+)\)/, function (s, p1) {
            return (0.75 * p1 + 0.25) + ")";
        });
        bubch[0].style[cssbc(dir^2)] = $(bubdiv).css(cssbc(dir));
        assign_style_property(bubch[2], cssbc(dir^2), yc);
    }

    function constrainmid(nearpos, wpos, ds, ds2) {
        var z0 = nearpos[lcdir[ds]], z1 = nearpos[lcdir[ds^2]],
            z = (1 - ds2) * z0 + ds2 * z1;
        z = Math.max(z, Math.min(z1, wpos[lcdir[ds]] + SPACE));
        return Math.min(z, Math.max(z0, wpos[lcdir[ds^2]] - SPACE));
    }

    function constrain(za, wpos, bpos, ds, ds2, noconstrain) {
        var z0 = wpos[lcdir[ds]], z1 = wpos[lcdir[ds^2]],
            bdim = bpos[szdir[ds&1]],
            z = za - ds2 * bdim;
        if (!noconstrain && z < z0 + SPACE)
            z = Math.min(za - sizes[0], z0 + SPACE);
        else if (!noconstrain && z + bdim > z1 - SPACE)
            z = Math.max(za + sizes[0] - bdim, z1 - SPACE - bdim);
        return z;
    }

    function bpos_wconstraint(wpos, ds) {
        var xw = Math.max(ds === 3 ? 0 : nearpos.left - wpos.left,
                          ds === 1 ? 0 : wpos.right - nearpos.right);
        if ((ds === "h" || ds === 1 || ds === 3) && xw > 100)
            return Math.min(wpos.width, xw) - 3*SPACE;
        else
            return wpos.width - 3*SPACE;
    }

    function make_bpos(wpos, ds) {
        var $b = $(bubdiv);
        $b.css("maxWidth", "");
        var bg = $b.geometry(true);
        var wconstraint = bpos_wconstraint(wpos, ds);
        if (wconstraint < bg.width) {
            $b.css("maxWidth", wconstraint);
            bg = $b.geometry(true);
        }
        // bpos[D] is the furthest position in direction D, assuming
        // the bubble was placed on that side. E.g., bpos[0] is the
        // top of the bubble, assuming the bubble is placed over the
        // reference.
        var bpos = [nearpos.top - sizes.bottom - bg.height - sizes[0],
                    nearpos.right + sizes.left + bg.width + sizes[0],
                    nearpos.bottom + sizes.top + bg.height + sizes[0],
                    nearpos.left - sizes.right - bg.width - sizes[0]];
        bpos.width = bg.width;
        bpos.height = bg.height;
        bpos.wconstraint = wconstraint;
        return bpos;
    }

    function remake_bpos(bpos, wpos, ds) {
        var wconstraint = bpos_wconstraint(wpos, ds);
        if ((wconstraint < bpos.wconstraint && wconstraint < bpos.width)
            || (wconstraint > bpos.wconstraint && bpos.width >= bpos.wconstraint))
            bpos = make_bpos(wpos, ds);
        return bpos;
    }

    function parse_dirspec(dirspec, pos) {
        var res;
        if (dirspec.length > pos
            && (res = "0123trblnesw".indexOf(dirspec.charAt(pos))) >= 0)
            return res % 4;
        return -1;
    }

    function csscornerradius(corner, index) {
        var divbr = $(bubdiv).css("border" + corner + "Radius"), pos;
        if (!divbr)
            return 0;
        if ((pos = divbr.indexOf(" ")) > -1)
            divbr = index ? divbr.substring(pos + 1) : divbr.substring(0, pos);
        return parseFloat(divbr);
    }

    function constrainradius(x, bpos, ds) {
        var x0, x1;
        if (ds & 1) {
            x0 = csscornerradius(capdir[0] + capdir[ds], 1);
            x1 = csscornerradius(capdir[2] + capdir[ds], 1);
        } else {
            x0 = csscornerradius(capdir[ds] + capdir[3], 1);
            x1 = csscornerradius(capdir[ds] + capdir[1], 1);
        }
        return Math.min(Math.max(x, x0), bpos[szdir[(ds&1)^1]] - x1 - sizes[0]);
    }

    function show() {
        if (!sizes)
            sizes = calculate_sizes(color);

        // parse dirspec
        if (dirspec == null)
            dirspec = "r";
        var noflip = /!/.test(dirspec),
            noconstrain = /\*/.test(dirspec),
            dsx = dirspec.replace(/[^a0-3neswtrblhv]/, ""),
            ds = parse_dirspec(dsx, 0),
            ds2 = parse_dirspec(dsx, 1);
        if (ds >= 0 && ds2 >= 0 && (ds2 & 1) != (ds & 1))
            ds2 = (ds2 === 1 || ds2 === 2 ? 1 : 0);
        else
            ds2 = 0.5;
        if (ds < 0)
            ds = /^[ahv]$/.test(dsx) ? dsx : "a";

        var wpos = $(window).geometry();
        var bpos = make_bpos(wpos, dsx);

        if (ds === "a") {
            if (bpos.height + sizes[0] > Math.max(nearpos.top - wpos.top, wpos.bottom - nearpos.bottom)) {
                ds = "h";
                bpos = remake_bpos(bpos, wpos, ds);
            } else
                ds = "v";
        }

        var wedge = [wpos.top + 3*SPACE, wpos.right - 3*SPACE,
                     wpos.bottom - 3*SPACE, wpos.left + 3*SPACE];
        if ((ds === "v" || ds === 0 || ds === 2) && !noflip && ds2 < 0
            && bpos[2] > wedge[2] && bpos[0] < wedge[0]
            && (bpos[3] >= wedge[3] || bpos[1] <= wedge[1])) {
            ds = "h";
            bpos = remake_bpos(bpos, wpos, ds);
        }
        if ((ds === "v" && bpos[2] > wedge[2] && bpos[0] > wedge[0])
            || (ds === 0 && !noflip && bpos[2] > wpos.bottom
                && wpos.top - bpos[0] < bpos[2] - wpos.bottom)
            || (ds === 2 && (noflip || bpos[0] >= wpos.top + SPACE)))
            ds = 2;
        else if (ds === "v" || ds === 0 || ds === 2)
            ds = 0;
        else if ((ds === "h" && bpos[3] - wpos.left < wpos.right - bpos[1])
                 || (ds === 1 && !noflip && bpos[3] < wpos.left)
                 || (ds === 3 && (noflip || bpos[1] <= wpos.right - SPACE)))
            ds = 3;
        else
            ds = 1;
        bpos = remake_bpos(bpos, wpos, ds);

        if (ds !== dir) {
            dir = ds;
            change_tail_direction();
        }

        var x, y, xa, ya, d;
        var divbw = parseFloat($(bubdiv).css(cssborder(ds & 1 ? 0 : 3, "Width")));
        if (ds & 1) {
            ya = constrainmid(nearpos, wpos, 0, ds2);
            y = constrain(ya, wpos, bpos, 0, ds2, noconstrain);
            d = constrainradius(roundpixel(ya - y - sizes[0] / 2 - divbw), bpos, ds);
            bubch[0].style.top = bubch[2].style.top = d + "px";

            if (ds == 1)
                x = nearpos.left - sizes.right - bpos.width - sizes[1] - 1;
            else
                x = nearpos.right + sizes.left + sizes[1];
        } else {
            xa = constrainmid(nearpos, wpos, 3, ds2);
            x = constrain(xa, wpos, bpos, 3, ds2, noconstrain);
            d = constrainradius(roundpixel(xa - x - sizes[0] / 2 - divbw), bpos, ds);
            bubch[0].style.left = bubch[2].style.left = d + "px";

            if (ds == 0)
                y = nearpos.bottom + sizes.top + sizes[1];
            else
                y = nearpos.top - sizes.bottom - bpos.height - sizes[1] - 1;
        }

        bubdiv.style.left = roundpixel(x) + "px";
        bubdiv.style.top = roundpixel(y) + "px";
        bubdiv.style.visibility = "visible";
    }

    function remove() {
        bubdiv && bubdiv.parentElement.removeChild(bubdiv);
        bubdiv = null;
    }

    var bubble = {
        near: function (epos, reference) {
            var i, off;
            if (typeof epos === "string" || epos.tagName || epos.jquery) {
                epos = $(epos);
                if (dirspec == null)
                    dirspec = $(epos).data("tooltipDir");
                epos = epos.geometry(true);
            }
            for (i = 0; i < 4; ++i)
                if (!(lcdir[i] in epos) && (lcdir[i ^ 2] in epos))
                    epos[lcdir[i]] = epos[lcdir[i ^ 2]];
            if (reference && (reference = $(reference)) && reference.length
                && reference[0] != window)
                epos = geometry_translate(epos, reference.geometry());
            nearpos = epos;
            show();
            return bubble;
        },
        at: function (x, y, reference) {
            return bubble.near({top: y, left: x}, reference);
        },
        dir: function (dir) {
            dirspec = dir;
            return bubble;
        },
        remove: remove,
        color: function (newcolor) {
            newcolor = newcolor ? " " + newcolor : "";
            if (color !== newcolor) {
                color = newcolor;
                bubdiv.className = "bubble" + color;
                bubch[0].className = "bubtail bubtail0" + color;
                bubch[2].className = "bubtail bubtail1" + color;
                dir = sizes = null;
                nearpos && show();
            }
            return bubble;
        },
        html: function (content) {
            var n = bubch[1];
            if (content === undefined)
                return n.innerHTML;
            if (typeof content === "string"
                && content === n.innerHTML
                && bubdiv.style.visibility === "visible")
                return bubble;
            nearpos && $(bubdiv).css({maxWidth: "", left: "", top: ""});
            if (typeof content === "string")
                n.innerHTML = content;
            else {
                while (n.childNodes.length)
                    n.removeChild(n.childNodes[0]);
                if (content && content.jquery)
                    content.appendTo(n);
                else
                    n.appendChild(content);
            }
            nearpos && show();
            return bubble;
        },
        text: function (text) {
            if (text === undefined)
                return $(bubch[1]).text();
            else
                return bubble.html(text ? text_to_html(text) : text);
        },
        hover: function (enter, leave) {
            $(bubdiv).hover(enter, leave);
            return bubble;
        },
        removeOn: function (jq, event) {
            if (arguments.length > 1)
                $(jq).on(event, remove);
            else if (bubdiv)
                $(bubdiv).on(jq, remove);
            return bubble;
        },
        self: function () {
            return bubdiv ? $(bubdiv) : null;
        },
        outerHTML: function () {
            return bubdiv ? bubdiv.outerHTML : null;
        }
    };

    content && bubble.html(content);
    return bubble;
};
})();


var tooltip = (function ($) {
var builders = {};

function prepare_info($self, info) {
    var xinfo = $self.data("tooltipInfo");
    if (xinfo) {
        if (typeof xinfo === "string" && xinfo.charAt(0) === "{")
            xinfo = JSON.parse(xinfo);
        else if (typeof xinfo === "string")
            xinfo = {builder: xinfo};
        info = $.extend(xinfo, info);
    }
    if (info.builder && builders[info.builder])
        info = builders[info.builder].call($self[0], info) || info;
    if (info.dir == null)
        info.dir = $self.data("tooltipDir") || "v";
    if (info.type == null)
        info.type = $self.data("tooltipType");
    if (info.className == null)
        info.className = $self.data("tooltipClass") || "dark";
    if (info.content == null)
        info.content = $self.data("tooltip");
    return info;
}

function show_tooltip(info) {
    if (window.disable_tooltip)
        return null;

    var $self = $(this);
    info = prepare_info($self, $.extend({}, info || {}));
    info.element = this;

    var tt, bub = null, to = null, refcount = 0, content = info.content;
    function erase() {
        to = clearTimeout(to);
        bub && bub.remove();
        $self.removeData("tooltipState");
        if (window.global_tooltip === tt)
            window.global_tooltip = null;
    }
    function show_bub() {
        if (content && !bub) {
            bub = make_bubble(content, {color: "tooltip " + info.className, dir: info.dir});
            bub.near(info.near || info.element).hover(tt.enter, tt.exit);
        } else if (content)
            bub.html(content);
        else if (bub) {
            bub && bub.remove();
            bub = null;
        }
    }
    tt = {
        enter: function () {
            to = clearTimeout(to);
            ++refcount;
            return tt;
        },
        exit: function () {
            var delay = info.type === "focus" ? 0 : 200;
            to = clearTimeout(to);
            if (--refcount == 0 && info.type !== "sticky")
                to = setTimeout(erase, delay);
            return tt;
        },
        erase: erase,
        _element: $self[0],
        html: function (new_content) {
            if (new_content === undefined)
                return content;
            else {
                content = new_content;
                show_bub();
            }
            return tt;
        },
        text: function (new_text) {
            return tt.html(escape_entities(new_text));
        }
    };

    function complete(new_content) {
        if (new_content instanceof HPromise)
            new_content.then(complete);
        else {
            var tx = window.global_tooltip;
            content = new_content;
            if (tx && tx._element === info.element
                && tx.html() === content
                && !info.done)
                tt = tx;
            else {
                tx && tx.erase();
                $self.data("tooltipState", tt);
                show_bub();
                window.global_tooltip = tt;
            }
        }
    }

    complete(content);
    info.done = true;
    return tt;
}

function ttenter() {
    var tt = $(this).data("tooltipState") || show_tooltip.call(this);
    tt && tt.enter();
}

function ttleave() {
    var tt = $(this).data("tooltipState");
    tt && tt.exit();
}

function tooltip() {
    var $self = $(this).removeClass("need-tooltip");
    if ($self.data("tooltipType") === "focus")
        $self.on("focus", ttenter).on("blur", ttleave);
    else
        $self.hover(ttenter, ttleave);
}
tooltip.erase = function () {
    var tt = $(this).data("tooltipState");
    tt && tt.erase();
};
tooltip.add_builder = function (name, f) {
    builders[name] = f;
};

$(function () { $(".need-tooltip").each(tooltip); });
return tooltip;
})($);


// temporary text
if (Object.prototype.toString.call(window.operamini) === '[object OperaMini]'
    || !("placeholder" in document.createElement("input"))
    || !("placeholder" in document.createElement("textarea"))) {
    window.mktemptext = (function () {
    function ttaction(event) {
        var $e = $(this), p = $e.attr("placeholder"), v = $e.val();
        if (event.type == "focus" && v === p)
            $e.val("");
        if (event.type == "blur" && (v === "" | v === p))
            $e.val(p);
        $e.toggleClass("temptext", event.type != "focus" && (v === "" || v === p));
    }

    return function ($base) {
        $base.find("input[placeholder], textarea[placeholder]").each(function () {
            if (!hasClass(this, "has-mktemptext")) {
                $(this).on("focus blur change input", ttaction).addClass("has-mktemptext");
                ttaction.call(this, {type: "blur"});
            }
        });
    };
    })();

    $(function () { mktemptext($(document)); });
} else {
    window.mktemptext = $.noop;
}


// style properties
// IE8 can't handle rgba and throws exceptions. Those exceptions
// clutter my inbox. XXX Revisit in mid-2016.
window.assign_style_property = (function () {
var e = document.createElement("div");
try {
    e.style.outline = "4px solid rgba(9,9,9,0.3)";
    return function (elt, property, value) {
        elt.style[property] = value;
    };
} catch (err) {
    return function (elt, property, value) {
        value = value.replace(/\brgba\((.*?),\s*[\d.]+\)/, "rgb($1)");
        elt.style[property] = value;
    };
}
})();


// initialization
function event_stop(evt) {
    if (evt.stopPropagation)
        evt.stopPropagation();
    else
        evt.cancelBubble = true;
}

function event_prevent(evt) {
    if (evt.preventDefault)
        evt.preventDefault();
    else
        evt.returnValue = false;
}

function sprintf(fmt) {
    var words = fmt.split(/(%(?:%|-?\d*(?:[.]\d*)?[sdefgoxX]))/), wordno, word,
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
            conv = word.match(/^%(-?)(\d*)(?:|[.](\d*))(\w)/);
            if (conv[4] >= "e" && conv[4] <= "g" && conv[3] == null)
                conv[3] = 6;
            if (conv[4] == "g") {
                arg = Number(arg).toPrecision(conv[3]).toString();
                arg = arg.replace(/[.](\d*[1-9])?0+(|e.*)$/,
                                  function (match, p1, p2) {
                                      return (p1 == null ? "" : "." + p1) + p2;
                                  });
            } else if (conv[4] == "f")
                arg = Number(arg).toFixed(conv[3]);
            else if (conv[4] == "e")
                arg = Number(arg).toExponential(conv[3]);
            else if (conv[4] == "d")
                arg = Math.floor(arg);
            else if (conv[4] == "o")
                arg = Math.floor(arg).toString(8);
            else if (conv[4] == "x")
                arg = Math.floor(arg).toString(16);
            else if (conv[4] == "X")
                arg = Math.floor(arg).toString(16).toUpperCase();
            arg = arg.toString();
            if (conv[2] !== "" && conv[2] !== "0") {
                pad = conv[2].charAt(0) === "0" ? "0" : " ";
                while (arg.length < parseInt(conv[2], 10))
                    arg = conv[1] ? arg + pad : pad + arg;
            }
            t += arg;
        }
    }
    return t;
}

var strftime = (function () {
    function pad(num, str, n) {
        str += num.toString();
        return str.length <= n ? str : str.substr(str.length - n);
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
        p: function (d) { return d.getHours() < 12 ? "AM" : "PM"; },
        P: function (d) { return d.getHours() < 12 ? "am" : "pm"; },
        r: function (d, alt) {
            if (alt && d.getSeconds())
                return strftime("%#l:%M:%S%P", d);
            else if (alt && d.getMinutes())
                return strftime("%#l:%M%P", d);
            else if (alt)
                return strftime("%#l%P", d);
            else
                return strftime("%I:%M:%S %p", d);
        },
        R: function (d, alt) {
            if (alt && d.getSeconds())
                return strftime("%H:%M:%S", d);
            else
                return strftime("%H:%M", d);
        },
        S: function (d) { return pad(d.getSeconds(), "0", 2); },
        T: function (d) { return strftime("%H:%M:%S", d); },
        /* XXX z Z */
        D: function (d) { return strftime("%m/%d/%y", d); },
        F: function (d) { return strftime("%Y-%m-%d", d); },
        s: function (d) { return Math.trunc(d.getTime() / 1000); },
        n: function (d) { return "\n"; },
        t: function (d) { return "\t"; },
        "%": function (d) { return "%"; }
    };
    return function(fmt, d) {
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
})();


// initialization
window.setLocalTime = (function () {
var servhr24, showdifference = false;
function setLocalTime(elt, servtime) {
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
setLocalTime.initialize = function (servzone, hr24) {
    servhr24 = hr24;
    // print local time if server time is in a different time zone
    showdifference = Math.abs((new Date).getTimezoneOffset() - servzone) >= 60;
};
return setLocalTime;
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
    setLocalTime.initialize(servzone, hr24);
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

function foldup(e, event, opts) {
    var dofold = false, attr, m, foldnum;
    while (e && (!e.id || e.id.substr(0, 4) != "fold")
           && (!e.getAttribute || !e.getAttribute("hotcrp_fold")))
        e = e.parentNode;
    if (!e)
        return true;
    if (typeof opts === "number")
        opts = {n: opts};
    else if (!opts)
        opts = {};
    foldnum = opts.n || 0;
    if (!foldnum && (m = e.className.match(/\bfold(\d*)[oc]\b/)))
        foldnum = m[1];
    dofold = !(new RegExp("\\bfold" + (foldnum ? foldnum : "") + "c\\b")).test(e.className);
    if ("f" in opts && !!opts.f == !dofold)
        return false;
    if (opts.s)
        jQuery.get(hoturl("sessionvar", "j=1&var=" + opts.s + "&val=" + (dofold ? 1 : 0)));
    if (event)
        event_stop(event);
    m = fold(e, dofold, foldnum);
    if ((attr = e.getAttribute(dofold ? "onfold" : "onunfold")))
        (new Function("foldnum", attr)).call(e, opts);
    return m;
}

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
function make_ajaxcheck_swinger(elt) {
    return function () {
        var h = elt.hotcrp_ajaxcheck;
        var now = (new Date).getTime(), delta = now - h.start, opacity = 0;
        if (delta < 2000)
            opacity = 0.5;
        else if (delta <= 7000)
            opacity = 0.5 * Math.cos((delta - 2000) / 5000 * Math.PI);
        if (opacity <= 0.03) {
            elt.style.outline = h.old_outline;
            clearInterval(h.interval);
            h.interval = null;
        } else
            elt.style.outline = "4px solid rgba(0, 200, 0, " + opacity + ")";
    };
}

function setajaxcheck(elt, rv) {
    if (typeof elt == "string")
        elt = $$(elt);
    if (elt) {
        var h = elt.hotcrp_ajaxcheck;
        if (!h)
            h = elt.hotcrp_ajaxcheck = {old_outline: elt.style.outline};
        if (h.interval) {
            clearInterval(h.interval);
            h.interval = null;
        }

        var s;
        if (rv.ok)
            s = "Saved";
        else if (rv.error)
            s = rv.error.replace(/<\/?.*?>/g, "").replace(/\(Override conflict\)\s*/g, "").replace(/\s+$/, "");
        else
            s = "Error";
        elt.setAttribute("title", s);

        if (rv.ok) {
            h.start = (new Date).getTime();
            h.interval = setInterval(make_ajaxcheck_swinger(elt), 13);
        } else
            elt.style.outline = "5px solid red";
    }
}

function link_urls(t) {
    var re = /((?:https?|ftp):\/\/(?:[^\s<>\"&]|&amp;)*[^\s<>\"().,:;&])([\"().,:;]*)(?=[\s<>&]|$)/g;
    return t.replace(re, function (m, a, b) {
        return '<a href="' + a + '" rel="noreferrer">' + a + '</a>' + b;
    });
}

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
    if (pos == null)
        pos = Math.max(0, this.open.length - 1);
    while (this.open.length > pos) {
        this.html = this.open[this.open.length - 1] + this.html +
            this.close[this.open.length - 1];
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


// text rendering
window.render_text = (function () {
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
})(window.markdownit());


// popup dialogs
function popup_near(elt, anchor) {
    var parent_offset = {left: 0, top: 0};
    if (/popupbg/.test(elt.parentNode.className)) {
        elt.parentNode.style.display = "block";
        parent_offset = $(elt.parentNode).offset();
    }
    var anchorPos = $(anchor).geometry();
    var wg = $(window).geometry();
    var x = (anchorPos.right + anchorPos.left - elt.offsetWidth) / 2;
    var y = (anchorPos.top + anchorPos.bottom - elt.offsetHeight) / 2;
    x = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) - parent_offset.left;
    y = Math.max(wg.top + 5, Math.min(wg.bottom - 5 - elt.offsetHeight, y)) - parent_offset.top;
    elt.style.left = x + "px";
    elt.style.top = y + "px";
    var efocus = $(elt).find("input, button, textarea, select").filter(":visible").filter(":not(.dangerous)")[0];
    efocus && efocus.focus();
}

function popup(anchor, which, dofold, populate) {
    var elt, form, elts, populates, i, xelt, type;
    if (typeof which === "string") {
        elt = $$("popup_" + which);
        if (!elt)
            log_jserror("no popup " + which);
        anchor = anchor || $$("popupanchor_" + which);
    }

    if (dofold) {
        elt.className = "popupc";
        if (/popupbg/.test(elt.parentNode.className))
            elt.parentNode.style.display = "none";
    } else {
        elt.className = "popupo";
        popup_near(elt, anchor);
    }

    // transfer input values to the new form if asked
    if (anchor && populate) {
        elts = elt.getElementsByTagName("input");
        populates = {};
        for (i = 0; i < elts.length; ++i)
            if (elts[i].className.indexOf("popup_populate") >= 0)
                populates[elts[i].name] = elts[i];
        form = anchor;
        while (form && form.tagName && form.tagName != "FORM")
            form = form.parentNode;
        elts = (form && form.tagName ? form.getElementsByTagName("input") : []);
        for (i = 0; i < elts.length; ++i)
            if (elts[i].name && (xelt = populates[elts[i].name])) {
                if (elts[i].type == "checkbox" && !elts[i].checked)
                    xelt.value = "";
                else if (elts[i].type != "radio" || elts[i].checked)
                    xelt.value = elts[i].value;
            }
    }

    return false;
}


// list management, conflict management
(function ($) {
function set_cookie(info) {
    var p = ";max-age=2", m;
    if (siteurl && (m = /^[a-z]+:\/\/[^\/]*(\/.*)/.exec(hoturl_absolute_base())))
        p += ";path=" + m[1];
    if (info) {
        if (typeof info === "object")
            info = JSON.stringify(info);
        if (info.indexOf("'") < 0)
            info = info.replace(/,/g, "'");
        info = encodeURIComponent(info);
        var pos = 0, suffix = 0;
        while (pos + 3000 < info.length) {
            var epos = pos + 3000;
            if (info.charAt(epos - 1) === "%")
                --epos;
            else if (info.charAt(epos - 2) === "%")
                epos -= 2;
            document.cookie = "hotlist-info" + (suffix ? "_" + suffix : "") + "=" + info.substring(pos, epos) + p;
            pos = epos;
            ++suffix;
        }
        document.cookie = "hotlist-info" + (suffix ? "_" + suffix : "") + "=" + info.substring(pos) + p;
    }
    set_cookie = function () {};
}
function is_listable(href) {
    return /(?:^|\/)pset(?:|\.php)(?:$|\/)/.test(href.substring(siteurl.length));
}
function add_list() {
    var $self = $(this), $hl, ls,
        href = this.getAttribute(this.tagName === "FORM" ? "action" : "href");
    if (href && href.substring(0, siteurl.length) === siteurl
        && is_listable(href)
        && ($hl = $self.closest(".has-hotlist")).length)
        set_cookie($hl.attr("data-hotlist"));
}
function unload_list() {
    hotcrp_list && set_cookie(hotcrp_list);
}
function row_click(e) {
    var j = $(e.target);
    if (j.hasClass("pl_id") || j.hasClass("pl_title")
        || j.closest("td").hasClass("pl_rowclick"))
        $(this).find("a.pnum")[0].click();
}
function prepare() {
    $(document).on("click", "a", add_list);
    $(document).on("submit", "form", add_list);
    //$(document).on("click", "tbody.pltable > tr.pl", row_click);
    hotcrp_list && $(window).on("beforeunload", unload_list);
}
prepare();
})(jQuery);


// mail
function setmailpsel(sel) {
    fold("psel", !!sel.value.match(/^(?:pc$|pc:|all$)/), 9);
    fold("psel", !sel.value.match(/^new.*rev$/), 10);
}

if (window.DOMTokenList && "contains" in window.DOMTokenList.prototype) {
    window.has_class = function (elt, className) {
        return elt && elt.classList.contains(className);
    };
} else {
    window.has_class = function (elt, className) {
        return elt && $(elt).hasClass(className);
    };
}

function pa_diff_locate(target, direction) {
    if (!target || target.tagName === "TEXTAREA" || target.tagName === "A")
        return null;
    while (target && target.tagName !== "TR") {
        if (target.tagName === "FORM")
            return null;
        target = target.parentNode;
    }

    var tr;
    if (direction)
        tr = target[direction];
    else {
        tr = target;
        direction = "previousSibling";
    }
    while (tr && (tr.nodeType !== Node.ELEMENT_NODE || has_class(tr, "pa-gw") || has_class(tr, "pa-gg")))
        tr = tr[direction];

    var table = tr, file;
    while (table && !(file = table.getAttribute("data-pa-file")))
        table = table.parentNode;
    if (!tr || !table || !/\bpa-dl\b.*\bpa-g[idc]\b/.test(tr.className))
        return null;

    var aline = +tr.firstChild.getAttribute("data-landmark");
    var bline = +tr.firstChild.nextSibling.getAttribute("data-landmark");
    var result = {
        file: file, aline: aline, bline: bline,
        lineid: bline ? "b" + bline : "a" + aline,
        tr: tr
    };

    var next_tr = tr.nextSibling;
    while (next_tr && (next_tr.nodeType !== Node.ELEMENT_NODE || has_class(next_tr, "pa-gg")))
        next_tr = next_tr.nextSibling;
    if (next_tr && has_class(next_tr, "pa-gw"))
        result.notetr = next_tr;

    return result;
}

function pa_notedata($j) {
    var note = $j.data("pa-note");
    if (typeof note === "string")
        note = JSON.parse(note);
    if (typeof note === "number")
        note = [false, "", 0, note];
    return note || [false, ""];
}

window.pa_linenote = (function ($) {
var labelctr = 0;
var curanal, mousedown_selection;
var scrolled_at;

function add_notetr(linetr) {
    var next_tr = linetr.nextSibling;
    while (next_tr && (next_tr.nodeType !== Node.ELEMENT_NODE || has_class(next_tr, "pa-gg"))) {
        linetr = next_tr;
        next_tr = next_tr.nextSibling;
    }
    return $('<tr class="pa-dl pa-gw"><td colspan="2" class="pa-note-edge"></td><td class="pa-notebox"></td></tr>').insertAfter(linetr);
}

function render_note($tr, note, transition) {
    $tr.data("pa-note", note);
    var $td = $tr.find(".pa-notebox");
    if (transition) {
        var $content = $td.children();
        $content.slideUp(80).queue(function () { $content.remove(); });
    }
    if (note[1] === "") {
        fix_notelinks($tr);
        transition ? $tr.children().slideUp(80) : $tr.children().hide();
        return;
    }

    var t = '<div class="pa-notediv">';
    if (note[2]) {
        var authorids = $.isArray(note[2]) ? note[2] : [note[2]];
        var authors = [];
        for (var i in authorids) {
            var p = hotcrp_pc[authorids[i]];
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

    if (!note[4])
        $td.find(".pa-note").addClass("format0").text(note[1]);
    else {
        var r = render_text(note[4], note[1]);
        $td.find(".pa-note").addClass("format" + (r.format || 0)).html(r.content);
    }

    fix_notelinks($tr);

    if (transition)
        $td.find(".pa-notediv").hide().slideDown(80);
}

function render_form($tr, note, transition) {
    $tr.addClass("editing");
    note && $tr.data("pa-note", note);
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
        escape_entities(hoturl_post("api/linenote", hoturl_gradeparts($pi, {file: curanal.file, line: curanal.lineid, oldversion: (note && note[3]) || 0, format: format}))) +
        '" enctype="multipart/form-data" accept-charset="UTF-8">' +
        '<div class="f-contain"><textarea class="pa-note-entry" name="note"></textarea>' +
        '<div class="aab aabr pa-note-aa">' +
        '<div class="aabut"><input type="submit" value="Save comment" /></div>' +
        '<div class="aabut"><button type="button" name="cancel">Cancel</button></div>';
    if (!$pi[0].hasAttribute("data-pa-user-can-view-grades")) {
        ++labelctr;
        t += '<div class="aabut"><input type="checkbox" id="pa-linenotecb' + labelctr + '" name="iscomment" value="1" /> <label for="pa-linenotecb' + labelctr + '">Show immediately</label></div>';
    }
    var $form = $(t).appendTo($td);

    var $ta = $form.find("textarea");
    if (note && note[1] !== null) {
        $ta.text(note[1]);
        $ta[0].setSelectionRange && $ta[0].setSelectionRange(note[1].length, note[1].length);
    }
    $ta.autogrow().keydown(keydown);
    $form.find("input[name=iscomment]").prop("checked", !!(note && note[0]));
    $form.find("button[name=cancel]").click(cancel);
    $form.on("submit", make_submit(curanal));
    if (transition) {
        $ta.focus();
        $form.hide().slideDown(100);
    }
}

function fix_notelinks($tr) {
    function note_skippable(tr) {
        return pa_notedata($(tr))[1] === "";
    }

    function note_anchor(tr) {
        var anal = pa_diff_locate(tr);
        if (anal) {
            var $td = pa_ensureline(anal.file, anal.lineid);
            return "#" + $td[0].id;
        } else
            return "#";
    }

    function set_link(tr, next_tr) {
        var $a = $(tr).find(".pa-note-links a");
        if (!$a.length) {
            $a = $('<a onclick="pa_gotoline(this)"></a>');
            $('<div class="pa-note-links"></div>').append($a).prependTo($(tr).find(".pa-notediv"));
        }

        $a.attr("href", note_anchor(next_tr));
        var t = next_tr ? "NEXT >" : "TOP";
        if ($a.text() !== t)
            $a.text(t);
    }

    var notes = $(".pa-gw");
    var notepos = 0;
    while (notepos < notes.length && notes[notepos] !== $tr[0])
        ++notepos;
    if (notepos >= notes.length)
        return;

    var prevpos = notepos - 1;
    while (prevpos >= 0 && note_skippable(notes[prevpos]))
        --prevpos;

    var nextpos = notepos + 1;
    while (nextpos < notes.length && note_skippable(notes[nextpos]))
        ++nextpos;

    if (prevpos >= 0)
        set_link(notes[prevpos], note_skippable($tr[0]) ? notes[nextpos] : $tr[0]);
    set_link($tr[0], notes[nextpos]);
}

function traverse(tr, down) {
    var direction = down ? "nextSibling" : "previousSibling";
    var table = tr.parentElement.parentElement;
    tr = tr[direction];
    while (1) {
        while (tr && !/\bpa-dl\b.*\bpa-g[idc]\b/.test(tr.className))
            tr = tr[direction];
        if (tr)
            return tr;
        table = table[direction];
        while (table && (table.nodeType !== Node.ELEMENT_NODE
                         || table.tagName !== "TABLE"
                         || !table.hasAttribute("data-pa-file")))
            table = table[direction];
        if (!table)
            return null;
        tr = table.firstChild[down ? "firstChild" : "lastChild"];
    }
}

function anal_tr() {
    if (curanal) {
        var $e = pa_ensureline(curanal.file, curanal.lineid);
        return $e.closest("tr")[0];
    } else
        return null;
}

function arrowcapture(evt) {
    var key;
    if (evt.type === "mousemove" && scrolled_at
        && evt.timeStamp - scrolled_at <= 200)
        return;
    if (evt.type === "keydown" && event_key.modifier(evt))
        return;
    if (evt.type !== "keydown"
        || ((key = event_key(evt)) !== "ArrowUp" && key !== "ArrowDown"
            && key !== "ArrowLeft" && key !== "ArrowRight"
            && key !== "Enter")
        || event_modkey(evt)
        || !curanal)
        return uncapture();
    if (key === "ArrowLeft" || key === "ArrowRight")
        return;

    var tr = anal_tr();
    if (!tr)
        return uncapture();
    if (key === "ArrowDown" || key === "ArrowUp") {
        $(tr).removeClass("live");
        tr = traverse(tr, key === "ArrowDown");
        if (!tr)
            return;
    }

    curanal = pa_diff_locate(tr);
    evt.preventDefault();
    if (key === "Enter")
        make_linenote();
    else {
        scrolled_at = evt.timeStamp;
        $(tr).addClass("live").scrollIntoView();
    }
    return true;
}

function capture(tr, keydown) {
    $(tr).addClass("live");
    $(".pa-filediff").removeClass("live");
    $(document).off(".pa-linenote");
    $(document).on((keydown ? "keydown.pa-linenote " : "") + "mousemove.pa-linenote mousedown.pa-linenote", arrowcapture);
}

function uncapture() {
    $("tr.live").removeClass("live");
    $(".pa-filediff").addClass("live");
    $(document).off(".pa-linenote");
}

function unedit(tr, always) {
    var $tr = $(tr).closest("tr");
    var note = pa_notedata($tr);
    if (!$tr.length || (!always && !text_eq(note[1], $tr.find("textarea").val())))
        return false;
    $tr.removeClass("editing");
    $tr.find(":focus").blur();
    render_note($tr, note, true);

    var click_tr = anal_tr();
    if (click_tr)
        capture(click_tr, true);
    return true;
}

function make_submit(anal) {
    return function () {
        var $f = $(this);
        if ($f.prop("outstanding"))
            return false;
        $f.prop("outstanding", true);
        var $tr = $f.closest("tr");
        $f.find(".ajaxsave61").remove();
        $f.find(".aab").append('<div class="aabut ajaxsave61">Saving…</div>');
        $.ajax($f.attr("action"), {
            data: $f.serialize(), method: "POST", cache: false, dataType: "json",
            success: function (data) {
                $f.prop("outstanding", false);
                if (data && data.ok) {
                    $f.find(".ajaxsave61").html("Saved");
                    var note = data.linenotes[anal.file];
                    note = note && note[anal.lineid];
                    $tr.data("pa-note", note);
                    unedit($tr[0]);
                    $tr.data("pa-savefailed", null);
                } else {
                    $f.find(".ajaxsave61").html('<strong class="err">' + escape_entities(data.error || "Failed") + '</strong>');
                    $tr.data("pa-savefailed", true);
                }
            }
        });
        return false;
    };
}

function cancel(evt) {
    unedit(this, true);
    return true;
}

function keydown(evt) {
    if (event_key(evt) === "Escape" && !event_modkey(evt) && unedit(this))
        return false;
    else if (event_key(evt) === "Enter" && event_modkey(evt) === event_modkey.META) {
        $(this).closest("form").submit();
        return false;
    } else
        return true;
}

function selection_string() {
    var s;
    if (window.getSelection
        && (s = window.getSelection())
        && s.toString)
        return s.toString();
    else
        return "";
}

function pa_linenote(event) {
    var anal = pa_diff_locate(event.target);
    if (anal && event.type == "mousedown") {
        curanal = anal;
        mousedown_selection = selection_string();
        return true;
    } else if (anal
               && ((event.type === "mouseup"
                    && curanal && curanal.tr == anal.tr
                    && mousedown_selection == selection_string())
                   || event.type === "click")) {
        curanal = anal;
        return make_linenote(event);
    } else {
        curanal = mousedown_selection = null;
        return true;
    }
}

function make_linenote(event) {
    var $tr = curanal.notetr ? $(curanal.notetr) : add_notetr(curanal.tr);
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
        render_form($tr, pa_notedata($tr), true);
        capture(curanal.tr, false);
        return false;
    }
}

pa_linenote.bind = function (selector) {
    $(selector).on("mouseup mousedown", ".pa-editablenotes", pa_linenote);
};
return pa_linenote;
})($);

window.pa_expandcontext = (function ($) {

function expand(evt) {
    var contextrow = evt.currentTarget;
    var panal = pa_diff_locate(contextrow, "previousSibling");
    var nanal = pa_diff_locate(contextrow, "nextSibling");
    if (!panal && !nanal)
        return false;
    var paline = panal ? panal.aline + 1 : 1;
    var pbline = panal ? panal.bline + 1 : 1;
    var lbline = nanal ? nanal.bline : 0;
    if (nanal && nanal.aline <= 1)
        return false;
    var args = {file: (panal || nanal).file, fromline: pbline};
    if (pbline && lbline)
        args.linecount = lbline - pbline;
    $.ajax(hoturl("api/blob", hoturl_gradeparts($(this), args)), {
        success: function (data) {
            if (data.ok && data.data) {
                var lines = data.data.replace(/\n$/, "").split("\n");
                for (var i = lines.length - 1; i >= 0; --i) {
                    var t = '<tr class="pa-dl pa-gc"><td class="pa-da">' +
                        (paline + i) + '</td><td class="pa-db">' +
                        (pbline + i) + '</td><td class="pa-dd"></td></tr>';
                    $(t).insertAfter(contextrow).find(".pa-dd").text(lines[i]);
                }
                $(contextrow).remove();
            }
        }
    });
    return true;
}

return {
    bind: function (selector) {
        $(selector).on("click", ".pa-gx", expand);
    }
};
})($);

jQuery.fn.extend({
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

function ajaxsave61(form, success) {
    form = jQuery(form);
    if (form.prop("outstanding"))
        return true;
    form.prop("outstanding", true);
    form.find(".ajaxsave61").html("Saving...");
    jQuery.ajax(form.attr("action"), {
        data: form.serializeWith({ajax: 1}),
        type: "POST", cache: false,
        dataType: "json",
        success: function (data) {
            form.prop("outstanding", false);
            if (data && data.ok) {
                form.find(".ajaxsave61").html("Saved");
                success && success(data);
            } else
                form.find(".ajaxsave61").html('<strong class="err">' + ((data && data.error) || "Failed") + '</strong>');
        },
        error: function () {
            form.find(".ajaxsave61").html("Failed!");
        }
    });
    return false;
}

function pa_makegrade(name, ge, editable) {
    var name = escape_entities(name);
    var t = '<table class="pa-grade pa-grp" data-pa-grade="' + name +
        '"><tbody><tr><td class="pa-grp-title">' +
        (ge.title ? escape_entities(ge.title) : name) + '</td><td>';
    if (editable) {
        t += '<form onsubmit="return pa_savegrades(this)">' +
            '<div class="pa-gradeentry"><span class="pa-gradeholder">' +
            '<input type="text" class="pa-gradevalue" name="' + name +
            '" onchange="$(this).closest(\'form\').submit()" /></span>';
        if (ge.max)
            t += ' <span class="pa-grademax" style="display:inline-block;min-width:3.5em">of ' + ge.max + '</span>';
        t += ' <input type="submit" value="Save" tabindex="1" style="display:none" /></div></form>';
    } else {
        t += '<span class="pa-gradevalue"></span>';
        if (ge.max)
            t += ' <span class="pa-grademax">of ' + ge.max + '</span>';
    }
    t += '</td></tr></tbody></table>';
    return t;
}

function hoturl_gradeparts($j, args) {
    var $x = $j.closest(".pa-psetinfo"), v;
    args = args || {};
    v = $x.attr("data-pa-user");
    args.u = v || peteramati_uservalue;
    if ((v = $x.attr("data-pa-pset")))
        args.pset = v;
    if ((v = $x.attr("data-pa-hash")))
        args.commit = v;
    return args;
}

function pa_savegrades(form) {
    var $f = $(form);
    if ($f.prop("outstanding"))
        return;

    $f.prop("outstanding", true);
    $f.find("input[type=submit]").prop("disabled", true);
    $f.find(".pa-gradediffers, .ajaxsave61").remove();
    $f.find(".pa-gradeentry").append('<span class="ajaxsave61">Saving…</span>');

    var gi = $f.closest(".pa-psetinfo").data("pa-gradeinfo");
    if (typeof gi === "string") {
        gi = JSON.parse(gi);
    }
    var g = {}, og = {};
    $f.find("input.pa-gradevalue").each(function () {
        var ge = gi.entries[this.name];
        if (gi.grades && ge && gi.grades[ge.pos] != null)
            og[this.name] = gi.grades[ge.pos];
        else if (this.name === "late_hours" && gi.late_hours != null)
            og[this.name] = gi.late_hours;
        g[this.name] = this.value;
    });

    $.ajax(hoturl_post("api/grade", hoturl_gradeparts($f)), {
        type: "POST", cache: false, data: {grades: g, oldgrades: og},
        success: function (data) {
            $f.prop("outstanding", false);
            $f.find("input[type=submit]").prop("disabled", false);
            if (data.ok)
                $f.find(".ajaxsave61").html("Saved");
            else
                $f.find(".ajaxsave61").html('<strong class="err">' + data.error + '</strong>');
            pa_loadgrades.call(form, data);
        }
    });
    return false;
}

function pa_loadgrades(gi) {
    var $pi = $(this).closest(".pa-psetinfo");
    if (gi === true)
        gi = $pi.data("pa-gradeinfo");
    if (!gi || !gi.order)
        return;

    $pi.data("pa-gradeinfo", gi);
    var editable = $pi[0].hasAttribute("data-pa-can-set-grades");
    var directory = $pi[0].getAttribute("data-pa-directory") || "";

    $pi.find(".pa-need-grade").each(function () {
        var k = this.getAttribute("data-pa-grade");
        var ge = gi.entries[k];
        if (ge)
            $(this).html(pa_makegrade(k, ge, editable)).removeClass("pa-need-grade");
    });

    var $pge = $pi.find(".pa-grade");
    var last_in_gradelist = null;

    // handle grade entries
    for (var i = 0; i < gi.order.length; ++i) {
        var k = gi.order[i];
        var ge = gi.entries[k];
        var $g = [], in_gradelist = null, $pg;
        for (var j = 0; j < $pge.length; ++j) {
            if ($pge[j].getAttribute("data-pa-grade") == k) {
                $g.push($pge[j]);
                if (has_class($pge[j].parentElement, "pa-gradelist"))
                    in_gradelist = $pge[j];
            }
        }
        if (!in_gradelist) {
            $pg = $(pa_makegrade(k, ge, editable));
            $g.push($pg[0]);
            if (last_in_gradelist)
                $pg.insertAfter(last_in_gradelist);
            else
                $pg.appendTo($pi.find(".pa-gradelist"));
            in_gradelist = $pg[0];
        }
        last_in_gradelist = in_gradelist;
        $g = $($g);

        var g = gi.grades ? gi.grades[i] : null;
        var ag = gi.autogrades ? gi.autogrades[i] : null;
        // “grade is above max” message
        if (ge.max && editable) {
            if (!g || g <= ge.max)
                $g.find(".pa-gradeabovemax").remove();
            else if (!$g.find(".pa-gradeabovemax").length)
                $g.find(".pa-gradeentry").after('<div class="pa-gradeabovemax">Grade is above max</div>');
        }
        // “autograde differs” message
        if (ag !== null && editable) {
            if (g === ag)
                $g.find(".pa-gradediffers").remove();
            else {
                var txt = "autograde is " + ag;
                if (!$g.find(".pa-gradediffers").length)
                    $g.find(".pa-gradeentry").append('<span class="pa-gradediffers"></span>');
                var $ag = $g.find(".pa-gradediffers");
                if ($ag.text() !== txt)
                    $ag.text(txt);
            }
        }
        // actual grade value
        g = g === null ? "" : "" + g;
        for (j = 0; j < $g.length; ++j) {
            var $gj = $($g[j]);
            var $v = $gj.find(".pa-gradevalue");
            if (editable && $v.val() !== g && !$v.is(":focus")) {
                $v.val(g);
            } else if (!editable && $v.text() !== g) {
                $v.text(g);
            }
            if (ge.landmark && has_class($g[j].parentElement, "pa-gradelist")) {
                var m = /^(.*):(\d+)$/.exec(ge.landmark);
                var $line = pa_ensureline(m[1], "a" + m[2]);
                var want_gbr = "";
                if ($line.length) {
                    if (directory && m[1].substr(0, directory.length) === directory)
                        m[1] = m[1].substr(directory.length);
                    want_gbr = '@<a href="#' + $line[0].id + '" onclick="return pa_gotoline(this)">' + escape_entities(m[1] + ":" + m[2]) + '</a>';
                }
                var $pgbr = $gj.find(".pa-gradeboxref");
                if (!$line.length)
                    $pgbr.remove();
                else if (!$pgbr.length || $pgbr.html() !== want_gbr) {
                    $pgbr.remove();
                    $gj.find(".pa-gradeentry").append('<span class="pa-gradeboxref">' + want_gbr + '</span>');
                }
            }
        }
    }

    // handle late hours
    for (var j = 0; j < $pge.length; ++j) {
        if ($pge[j].getAttribute("data-pa-grade") === "late_hours") {
            var $g = $($pge[j]);
            var g = gi.late_hours;
            var ag = gi.auto_late_hours;
            var $v = $g.find(".pa-gradevalue");
            var lh_editable = $v.is("input");
            // “auto-late hours differs” message
            if (ag !== null && lh_editable) {
                if (g === ag || ag == null)
                    $g.find(".pa-gradediffers").remove();
                else {
                    var txt = "auto-late hours is " + ag;
                    if (!$g.find(".pa-gradediffers").length)
                        $g.find(".pa-gradeentry").append('<span class="pa-gradediffers"></span>');
                    var $ag = $g.find(".pa-gradediffers");
                    if ($ag.text() !== txt)
                        $ag.text(txt);
                }
            }
            // late hours value
            if (lh_editable && $v.val() !== g && !$v.is(":focus")) {
                $v.val(g);
            } else if (!lh_editable && $v.text() !== g) {
                $v.text(g);
            }
        }
    }

    // print totals
    var tm = pa_gradeinfo_total(gi);
    $g = $pi.find(".pa-total");
    if (tm[0] && !$g.length) {
        $g = $('<table class="pa-total pa-grp"><tbody><tr>' +
            '<td class="pa-grp-title">total</td><td class="nw">' +
            '<span class="pa-gradevalue' + (editable ? " pa-gradeholder" : "") +
            '"></span> <span class="pa-grademax">of ' + tm[1] +
            '</span></td></tr></tbody></table>');
        $g.prependTo($pi.find(".pa-gradelist"));
    }
    $v = $g.find(".pa-gradevalue");
    g = "" + tm[0];
    if ($v.text() !== g) {
        $v.text(g);
        pa_draw_gradecdf($pi.find(".pa-gradecdf"));
    }
}

function fold61(sel, arrowholder, direction) {
    var j = $(sel);
    j.toggle(direction);
    if (arrowholder)
        $(arrowholder).find("span.foldarrow").html(
            j.is(":visible") ? "&#x25BC;" : "&#x25B6;"
        );
    return false;
}

function sb() {
    var wp, de, dde, e, dh;
    de = document;
    dde = de.documentElement;
    if (window.innerWidth)
        wp = {x: window.pageXOffset, y: window.pageYOffset,
              h: window.innerHeight};
    else {
        e = (dde && dde.clientWidth ? dde : document.body);
        wp = {x: e.scrollLeft, y: e.scrollTop, h: e.clientHeight};
    }
    dh = Math.max(de.scrollHeight || 0, de.offsetHeight || 0);
    if (dde)
        dh = Math.max(dh, dde.clientHeight || 0, dde.scrollHeight || 0, dde.offsetHeight || 0);
    window.scroll(wp.x, Math.max(0, wp.y, dh - wp.h));
}

function runfold61(name) {
    var therun = jQuery("#pa-run-" + name), thebutton;
    if (therun[0].dataset.paTimestamp && !therun.is(":visible")) {
        thebutton = jQuery(".pa-runner[value='" + name + "']")[0];
        pa_run(thebutton, {unfold: true});
    } else
        fold61(therun, jQuery("#pa-runout-" + name));
    return false;
}

function pa_ensureline(filename, lineid) {
    // decode arguments: either (lineref) or (filename, lineid)
    if (lineid == null) {
        if (filename instanceof Node)
            filename = filename.hash;
        var m = filename.match(/^#?L([ab]\d+)_(.*)$/);
        if (!m)
            return $(null);
        filename = m[2];
        lineid = m[1];
    } else
        filename = html_id_encode(filename);

    // check lineref
    var lineref = "L" + lineid + "_" + filename;
    var e = document.getElementById(lineref);
    if (e)
        return $(e);

    // create link
    var file = document.getElementById("pa-file-" + filename);
    if (!file)
        return $(null);
    var $tds = $(file).find("td.pa-d" + lineid.charAt(0));
    var lineno = lineid.substr(1);
    for (var i = 0; i < $tds.length; ++i)
        if ($tds[i].getAttribute("data-landmark") === lineno) {
            $tds[i].id = lineref;
            return $($tds[i]);
        }
    return $(null);
}

function pa_gotoline(x, lineid) {
    var $e;
    function flasher() {
        $e.css("backgroundColor", "#ffff00");
        $(this).dequeue();
    }
    function restorer() {
        $e.css("backgroundColor", "");
        $(this).dequeue();
    }

    var $ref = pa_ensureline(x, lineid);
    if ($ref.length) {
        $(".anchorhighlight").removeClass("anchorhighlight").finish();
        $ref.closest("table").show();
        $e = $ref.closest("tr");
        var color = $e.css("backgroundColor");
        $e.addClass("anchorhighlight")
            .queue(flasher)
            .delay(100).queue(restorer)
            .delay(100).queue(flasher)
            .delay(100).queue(restorer)
            .delay(100).queue(flasher)
            .animate({backgroundColor: color}, 1200)
            .queue(restorer);
    }
    return true;
}

function pa_beforeunload(evt) {
    var ok = true;
    $(".pa-gw textarea").each(function () {
        var $tr = $(this).closest("tr");
        var note = pa_notedata($tr);
        if (note && !text_eq(this.value, note[1]) && !$tr.data("pa-savefailed"))
            ok = false;
    });
    if (!ok)
        return (event.returnValue = "You have unsaved notes. You will lose them if you leave the page now.");
}

function loadgrade61($b) {
    var $p = $b.closest(".pa-psetinfo");
    $.ajax(hoturl("api/grade", hoturl_gradeparts($p)), {
        type: "GET", cache: false, dataType: "json",
        success: function (data) { pa_loadgrades.call($p[0], data); }
    });
}

function setgrader61(button) {
    var form = jQuery(button).closest("form");
    jQuery.ajax(form.attr("action"), {
        data: form.serializeWith({}),
        type: "POST", cache: false,
        dataType: "json",
        success: function (data) {
            var a;
            form.find(".ajaxsave61").html(data.ok ? "Saved" : "<span class='error'>Error: " + data.error + "</span>");
            if (data.ok && (a = form.find("a.actas")).length)
                a.attr("href", a.attr("href").replace(/actas=[^&;]+/, "actas=" + encodeURIComponent(data.grader_email)));
        },
        error: function () {
            form.find(".ajaxsave61").html("<span class='error'>Failed</span>");
        }
    });
}

function flag61(button) {
    var $b = $(button), $form = $b.closest("form");
    if (button.name == "flag" && !$form.find("[name=flagreason]").length) {
        $b.before('<span class="flagreason">Why do you want to flag this commit? &nbsp;<input type="text" name="flagreason" value="" placeholder="Optional reason" /> &nbsp;</span>');
        $form.find("[name=flagreason]").on("keypress", make_onkeypress_enter(function () { $b.click(); })).autogrow()[0].focus();
        $b.html("OK");
    } else if (button.name == "flag") {
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
    } else if (button.name == "resolveflag") {
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
}

window.pa_render_terminal = (function () {
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
    var styles = container.dataset.paTerminalStyle;
    var cursor = null;
    if (options && options.cursor === true)
        cursor = container.lastChild;

    function addlinepart(node, text) {
        node.appendChild(style_text(text, styles));
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
            return this.dataset.paFile === file;
        });
    }

    function add_file_link(node, file, line, link) {
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
            var anchor = "Lb" + line + "_" + html_id_encode(file);
            var a = $("<a href=\"#" + anchor + "\" onclick=\"return pa_gotoline(this)\"></a>");
            a.text(link.replace(/(?:\x1b\[[\d;]*m|\x1b\[\d*K)/g, ""));
            addlinepart(node, a);
            return true;
        }
        return false;
    }

    function render_line(line, node) {
        var m, filematch, a, i, x, isnew = !node, displaylen = 0;
        node = node || document.createElement("span");

        if (/\r/.test(line))
            line = clean_cr(line);

        if (((m = line.match(/^([^:\s]+):(\d+)(?=:)/))
             || (m = line.match(/^file \"(.*?)\", line (\d+)/i)))
            && add_file_link(node, m[1], m[2], m[0])) {
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
                addlinepart(node, render + "…\n");
                isnew && container.insertBefore(node, cursor);
                node = document.createElement("span");
                isnew = true;
                displaylen = 0;
            } else {
                addlinepart(node, render);
                displaylen += render.length;
            }
            line = line.substr(render.length);
        }
        isnew && container.insertBefore(node, cursor);
    }

    // hide newline on last line
    var lines = string.split(/^/m);
    if (lines[lines.length - 1] === "")
        lines.pop();
    var lastfull = ends_with(lines[lines.length - 1], "\n");

    var node = cursor ? cursor.previousSibling : container.lastChild;
    if (node
        && (string = node.getAttribute("data-pa-outputpart")) !== null
        && string !== ""
        && lines.length) {
        while (node.firstChild)
            node.removeChild(node.firstChild);
        lines[0] = string + lines[0];
        node.removeAttribute("data-pa-outputpart");
    } else {
        if (node && lines.length)
            node.appendChild(document.createTextNode("\n"));
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

    if (!lastfull) {
        styles = laststyles;
        node = cursor ? cursor.previousSibling : container.lastChild;
        if (node)
            node.setAttribute("data-pa-outputpart", last);
    }

    container.dataset.paTerminalStyle = styles;
};
})();

function pa_render_need_terminal() {
    $(".need-pa-terminal").each(function () {
        $(this).removeClass("need-pa-terminal");
        pa_render_terminal(this, this.dataset.paTerminalOutput);
        delete this.dataset.paTerminalOutput;
    });
}
$(pa_render_need_terminal);

function pa_run(button, opt) {
    var $f = $(button).closest("form"),
        category = button.getAttribute("data-pa-run-category") || button.value,
        directory = $(button).closest(".pa-psetinfo").attr("data-pa-directory"),
        therun = $("#pa-run-" + category),
        thepre = therun.find("pre"),
        thexterm,
        checkt;

    if (typeof opt !== "object")
        opt = {};
    if (opt.unfold && therun[0].dataset.paTimestamp)
        checkt = +therun[0].dataset.paTimestamp;
    else {
        if ($f.prop("outstanding"))
            return true;
        $f.find("button").prop("disabled", true);
        $f.prop("outstanding", true);
    }
    delete therun[0].dataset.paTimestamp;

    fold61(therun, jQuery("#pa-runout-" + category).show(), true);
    if (!checkt && !opt.noclear) {
        thepre.html("");
        delete thepre[0].dataset.paTerminalStyle;
    } else
        therun.find("span.pa-runcursor").remove();

    if (therun[0].dataset.paXtermJs
        && therun[0].dataset.paXtermJs !== "false"
        && window.Terminal) {
        thexterm = new Terminal({cols: 132, rows: 25});
        thexterm.open(thepre[0], false);
        thexterm.on('key', function(key) {
            write(key);
        });
    } else
        thepre.append("<span class='pa-runcursor'>_</span>");

    if (checkt && !therun.prop("openedbefore")) {
        therun.scrollTop(therun.children().height() - therun.height());
        therun.prop("openedbefore", true);
    }

    var ibuffer = "", // initial buffer; holds data before any results arrive
        offset = -1, backoff = 50, queueid = null,
        thecursor = therun.find("span.pa-runcursor")[0];

    function animate() {
        jQuery(thecursor).dequeue().animate({opacity: 0.1}, 200).delay(100).animate({opacity: 1}, 200).delay(400).queue(animate);
    }
    thecursor && animate();

    function done() {
        $f.find("button").prop("disabled", false);
        $f.prop("outstanding", false);
        if (thexterm)
            thexterm.write("\x1b[?25l"); // “hide cursor” escape
        else
            $(thecursor).finish().remove();
        if ($(button).attr("data-pa-loadgrade"))
            loadgrade61($(button));
    }

    function append(str) {
        if (thexterm)
            thexterm.write(str);
        else {
            var atbottom = therun.scrollTop() >= therun.children().height() - therun.height() - 10;
            pa_render_terminal(thepre[0], str, {cursor: true, directory: directory});
            if (atbottom)
                therun.scrollTop(Math.max(Math.ceil(therun.children().height() - therun.height()), 0));
        }
    }

    function append_html(html) {
        var atbottom = therun.scrollTop() >= therun.children().height() - therun.height() - 10;

        var node = thepre[0].lastChild.previousSibling;
        if (node)
            node.appendChild(document.createTextNode("\n"));
        thepre[0].insertBefore(jQuery(html)[0], thepre[0].lastChild);

        if (atbottom)
            therun.scrollTop(Math.max(Math.ceil(therun.children().height() - therun.height()), 0));
    }

    function append_data(str, data) {
        if (ibuffer !== null) { // haven't started generating output
            ibuffer += str;
            var pos = ibuffer.indexOf("\n\n");
            if (pos < 0)
                return; // not ready yet

            str = ibuffer.substr(pos + 2);
            ibuffer = null;

            if (thecursor) {
                var j = $(thecursor).detach();
                if (!opt.noclear)
                    thepre.html("");
                thepre.append(j);
            }

            if (data && data.timestamp) {
                var d = new Date(data.timestamp * 1000);
                var msg = "...started " + strftime("%l:%M:%S%P %e %b %Y", d);
                if (thexterm)
                    append("\x1b[3;1;38;5;86m" + msg + "\x1b[m\r\n");
                else
                    append_html("<span class=\"pa-runtime\">" + msg + "</span>");
            }
        }
        if (str !== "")
            append(str);
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
                $("<button id=\"pa-runstop-" + category + "\" class=\"pa-runstop\" type=\"button\">Stop</button>")
                    .click(stop).appendTo("#pa-runout-" + category + " > h3");
        } else
            $("#pa-runstop-" + category).remove();

        if (!data || !data.ok) {
            if (data && data.loggedout)
                x = "You have been logged out (perhaps due to inactivity). Please reload this page.";
            else if (data)
                x = data.error_text || data.error || "Unknown";
            else
                x = "Unknown";
            append("\x1b[1;3;31m" + x + "\x1b[m\r\n");
            return done();
        }

        checkt = checkt || data.timestamp;
        if (data.data && data.offset < offset)
            data.data = data.data.substring(offset - data.offset);
        if (data.data) {
            offset = data.lastoffset;
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

        if (data.status == "old")
            setTimeout(send, 2000);
        else if (!data.done)
            setTimeout(send, backoff);
        else
            done();
    }

    function send(args) {
        var a = {run: category, offset: offset};
        checkt && (a.check = checkt);
        queueid && (a.queueid = queueid);
        args && $.extend(a, args);
        jQuery.ajax($f.attr("action"), {
            data: $f.serializeWith(a),
            type: "POST", cache: false,
            dataType: "json",
            success: succeed,
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

    if (opt.headline && opt.noclear && !thexterm && thepre[0].firstChild != thecursor)
        append("\n\n");
    if (opt.headline && opt.headline instanceof Node)
        append_html(opt.headline);
    else if (opt.headline)
        append("\x1b[1;37m" + opt.headline + "\x1b[m\n");
    if (opt.unfold && therun.attr("data-pa-content"))
        append(therun.attr("data-pa-content"));
    therun.removeAttr("data-pa-content");

    send();
    return false;
}

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
        var $x = jQuery("<a href=\"" + siteurl + "~" + encodeURIComponent(user) + "/pset/" + $f.find("[name='pset']").val() + "\" class=\"q ansib ansifg7\"></a>");
        $x.text(user);
        pa_run($manybutton[0], {noclear: true, headline: $x[0]});
    }
    setTimeout(runmany61, 10);
}

var runsetting61 = (function ($) {

function save() {
    var $j = $("#runsettings61 .pa-grp"), j = {}, i, k, v;
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
    var $j = $("#runsettings61"), num = $j.find(".n").length;
    while ($j.find("[runsetting61num=" + num + "]").length)
        ++num;
    var $x = $("<table class=\"pa-grp\" runsetting61num=\"" + num + "\"><tr><td class=\"pa-grp-title\"></td><td><input name=\"n" + num + "\" class=\"n\" size=\"30\" placeholder=\"Name\" /> &nbsp; <input name=\"v" + num + "\" class=\"v\" size=\"40\" placeholder=\"Value\" /></td></tr></table>");
    if (name) {
        $x.find(".n").val(name);
        $x.find(".v").val(value);
    } else
        $x.find(".n").focus();
    $x.find("input").on("change", save);
    $j.append($x);
}

function load(j) {
    var $j = $("#runsettings61"), $n = $j.find(".n"), i, x;
    $n.attr("outstanding61", "1");
    for (x in j) {
        for (i = 0; i != $n.length && $.trim($($n[0]).val()) != x; ++i)
            /* nada */;
        if (i == $n.length)
            add(x, j[x]);
        else if ($.trim($j.find("[name=v" + i + "]").val()) != j[x]) {
            $j.find("[name=v" + i + "]").val(j[x]);
            $($n[i]).removeAttr("outstanding61");
        }
    }
    for (i = 0; i != $n.length; ++i)
        if ($($n[i]).attr("outstanding61"))
            $("[runsetting61num=" + $($n[i]).attr("name").substr(1) + "]").remove();
}

return {add: add, load: load};
})(jQuery);

function pa_gradecdf_series(d, total) {
    var i, data = [];
    for (i = 0; i < d.cdf.length; i += 2) {
        if (i != 0 || !d.cutoff)
            data.push([d.cdf[i], i > 0 ? d.cdf[i-1] / d.n : 0]);
        else
            data.push([d.cdf[0], d.cutoff]);
        data.push([d.cdf[i], d.cdf[i+1]/d.n]);
        if (data.totalx == null && d.cdf[i] >= total)
            data.totalx = d.cdf[i+1] / d.n;
    }
    return data;
}

function pa_gradeinfo_total(gi) {
    if (typeof gi === "string")
        gi = JSON.parse(gi);
    var total = 0, maxtotal = 0;
    for (var i = 0; i < gi.order.length; ++i) {
        var ge = gi.entries[gi.order[i]];
        if (ge.in_total) {
            total += (gi.grades && gi.grades[i]) || 0;
            if (!ge.is_extra)
                maxtotal += ge.max || 0;
        }
    }
    return [Math.round(total * 1000) / 1000,
            Math.round(maxtotal * 1000) / 1000];
}

function pa_draw_gradecdf($graph) {
    var d = $graph.data("pa-gradecdfinfo");
    if (!d)
        return;

    // load user grade
    var gi = $graph.closest(".pa-psetinfo").data("pa-gradeinfo");
    var tm = pa_gradeinfo_total(gi);

    // series
    var dx = d.extension;
    var series;
    if (dx)
        series = [{data: pa_gradecdf_series(dx, tm[0]), color: "#ee6666", label: "extension"},
                  {data: pa_gradecdf_series(d, tm[0]), color: "#ffaaaa", lines: {lineWidth: 0.8}, label: "all"}];
    else if (d.noextra)
        series = [{data: pa_gradecdf_series(d, tm[0]), color: "#ee6666", label: "all"},
                  {data: pa_gradecdf_series(d.noextra, tm[0]), color: "#ffaaaa", lines: {lineWidth: 0.8}, label: "noextra"}];
    else
        series = [{data: pa_gradecdf_series(d, tm[0]), color: "#ee6666"}];
    series.push({data: [[tm[0], series[0].data.totalx]], color: "#222266", points: {show: true, radius: 5, fillColor: "#ffff00"}});

    // check max-x
    var xaxis = {min: 0};
    var datamax = d.cdf[d.cdf.length - 2];
    if (dx && dx.cdf)
        datamax = Math.max(datamax, dx.cdf[dx.cdf.length - 2]);
    var grid = {markings: []};
    if (d.maxtotal) {
        if (d.maxtotal > datamax)
            xaxis.max = d.maxtotal;
        else
            grid.markings.push({
                    xaxis: {from: d.maxtotal, to: d.maxtotal},
                    color: "rgba(0,0,255,0.2)"
                });
    }

    // check grid
    if (d.cutoff)
        grid.markings.push({
                xaxis: {from: 0, to: xaxis.max || datamax},
                yaxis: {from: 0, to: d.cutoff},
                color: "rgba(255,0,0,0.1)"
            });

    // plot
    var $table = $graph.find(".gradecdf61table");
    $table.find(".plot > div").plot(series, {
        xaxis: xaxis,
        yaxis: {min: 0, max: 1},
        grid: grid,
        legend: {position: "nw", labelBoxBorderColor: "transparent"}
    });
    $table.find(".yaxislabelcontainer").html('<div class="yaxislabel">fraction of results</div>');
    $table.find(".yaxislabel").css("left", -0.5 * $table.find(".yaxislabel").width());
    $table.find(".xaxislabelcontainer").html('<div class="xaxislabel">grade</div>');

    // summary
    for (var i in {"all": 1, "extension": 1}) {
        var $sum = $graph.find(".gradecdf61summary." + i);
        var dd = (i == "all" ? d : dx) || {};
        for (var x in {"mean":1, "median":1, "stddev":1}) {
            var $v = $sum.find(".gradecdf61" + x);
            if (x in dd)
                $v.show().find(".val").text(dd[x].toFixed(1));
            else
                $v.hide();
        }
    }
}

function pa_gradecdf($graph) {
    jQuery.ajax(hoturl_post("pset", hoturl_gradeparts($graph, {gradecdf:1})), {
        type: "GET", cache: false,
        dataType: "json",
        success: function (d) {
            if (d.cdf) {
                $graph.data("pa-gradecdfinfo", d);
                pa_draw_gradecdf($graph);
            }
        }
    });
}

function pa_checklatest() {
    var start = (new Date).getTime(), timeout, pset, hash;

    function checkdata(d) {
        if (d && d.hash && d.hash !== hash && (!d.snaphash || d.snaphash !== hash)) {
            jQuery(".commitcontainer61 .cs61infgroup").first().append("<div class=\"pa-inf-error\"><span class=\"pa-inf-alert\">Newer commits are available.</span> <a href=\"" + hoturl("pset", {u: peteramati_uservalue, pset: pset, commit: d.hash}) + "\">Load them</a></div>");
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

    pset = jQuery(".commitcontainer61").first().attr("data-pa-pset");
    if (pset) {
        hash = jQuery(".commitcontainer61").first().attr("data-pa-commit");
        setTimeout(docheck, 2000);
    }
}

function repoclip() {
    var node = document.createTextNode(this.getAttribute("data-pa-repo"));
    var bub = make_bubble(node, {color: "tooltip", dir: "t"});
    bub.near(this);
    var range = document.createRange();
    range.selectNode(node);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    var worked;
    try {
        worked = document.execCommand("copy");
    } catch (err) {
    }
    window.getSelection().removeAllRanges();
    bub.remove();
    if (global_tooltip && global_tooltip.elt == this)
        global_tooltip.text(this.getAttribute("data-pa-repo"));
}

function pa_init_repoclip() {
    $(this).click(repoclip);
}

function pa_pset_actions() {
    var $f = $(this);
    function update() {
        var st = $f.find("select[name='state']").val();
        $f.find(".pa-if-enabled").toggle(st !== "disabled");
        $f.find(".pa-if-visible").toggle(st !== "disabled" && st !== "invisible");
    }
    update();
    $f.find("select[name='state']").on("change", update);
    $f.find("input, select").on("change", function () {
        $f.find("[type='submit']").addClass("alert");
    });
    $f.removeClass("need-pa-pset-actions");
}

function pa_anonymize_linkto(link, event) {
    if (event && event.metaKey)
        window.open(link);
    else
        window.location = link;
    return false;
}

function pa_render_pset_table(psetid, pconf, data) {
    var $j = $("#pa-pset" + psetid), dmap = [],
        flagged = pconf.flagged_commits,
        grade_keys = pconf.grade_keys || [],
        grade_abbr,
        need_ngrades,
        sort = {f: flagged ? "at" : "username", last: true, rev: 1},
        sorting_last, displaying_last_first = null,
        anonymous = pconf.anonymous;

    function initialize() {
        var x = wstorage(true, "pa-pset" + psetid + "-table");
        x && (sort = JSON.parse(x));
        if (!sort.f || !/^\w+$/.test(sort.f))
            sort.f = "username";
        if (sort.rev !== 1 && sort.rev !== -1)
            sort.rev = 1;
        if (!anonymous || !pconf.can_override_anonymous || !sort.override_anonymous)
            delete sort.override_anonymous;
        if (anonymous && sort.override_anonymous)
            anonymous = false;
        var ngrades_expected = -1, ngrades;
        for (var i = 0; i < data.length; ++i) {
            var s = data[i];
            if (s.dropped)
                s.boringness = 2;
            else if (!s.gradehash && !pconf.gitless_grades)
                s.boringness = 1;
            else
                s.boringness = 0;
            ngrades = 0;
            for (var j = 0; j < grade_keys.length; ++j) {
                if (grade_keys[j] != pconf.total_key && s.grades[j] != null && s.grades[j] !== "")
                    ++ngrades;
            }
            s.ngrades_nonempty = ngrades;
            if (ngrades_expected === -1)
                ngrades_expected = ngrades;
            else if (ngrades_expected !== ngrades && (!s.boringness || ngrades > 0))
                ngrades_expected = -2;
        }
        need_ngrades = ngrades_expected === -2;
        grade_abbr = [];
        var grade_abbr_count = {}, m, grade_titles = pconf.grade_titles || [];
        for (i = 0; i < grade_keys.length; ++i) {
            grade_titles[i] = grade_titles[i] || grade_keys[i];
            m = grade_titles[i].match(/^(p)(?:art\s*|(?=\d))([.a-z\d]+)(?:[\s:]|$)/i);
            m = m || grade_titles[i].match(/^(q)(?:uestion\s*|(?=\d))([.a-z\d]+)(?:[\s:]|$)/i);
            m = m || grade_titles[i].match(/^()(\S{1,3})/);
            x = m ? m[1] + m[2] : ":" + i + ":";
            grade_abbr.push(x);
            grade_abbr_count[x] = (grade_abbr_count[x] || 0) + 1;
        }
        for (i = 0; i < grade_keys.length; ++i) {
            if (grade_abbr_count[grade_abbr[i]] > 1
                && (m = grade_titles[i].match(/\s+(\S{1,3})/))) {
                grade_abbr[i] += m[1];
            }
        }
    }
    function calculate_ncol() {
        return (pconf.checkbox ? 1 : 0) + 5 + (pconf.gitless_grades ? 0 : 1) +
            (pconf.need_total ? 1 : 0) + grade_keys.length + (need_ngrades ? 1 : 0) +
            (pconf.gitless ? 0 : 1);
    }
    function ukey(s) {
        return (anonymous && s.anon_username) || s.username || "";
    }
    function escaped_href(s) {
        var psetkey = s.psetid ? peteramati_psets[s.psetid].urlkey : pconf.psetkey;
        var args = {pset: psetkey, u: ukey(s)};
        if (s.hash && !s.is_grade)
            args.commit = s.hash;
        return escape_entities(hoturl("pset", args));
    }
    function render_username_td(s) {
        var t = '<a href="' + escaped_href(s), un;
        if (s.dropped)
            t += '" style="text-decoration:line-through';
        if (anonymous && s.anon_username)
            un = s.anon_username;
        else if (sort.email && s.email)
            un = s.email;
        else
            un = s.username || "";
        return t + '">' + escape_entities(un) + '</a>';
    }
    function render_name(s, last_first) {
        if (s.first != null && s.last != null) {
            if (last_first)
                return s.last + ", " + s.first;
            else
                return s.first + " " + s.last;
        } else if (s.first != null)
            return s.first;
        else if (s.last != null)
            return s.last;
        else
            return "";
    }
    function render_display_name(s) {
        var txt = escape_entities(render_name(s, displaying_last_first));
        if (!s.anon_username || !pconf.can_override_anonymous)
            return txt;
        else
            return '<span class="pap-nonanonymous">' + txt + '</span>';
    }
    function render_checkbox_name(s) {
        var u = anonymous ? s.anon_username || s.username : s.username;
        return "s61_" + encodeURIComponent(u).replace(/\./g, "%2E");
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
    function render_tds(s, row_number) {
        var a = [], txt, j, klass, ngrades;
        if (row_number == "") {
            if (pconf.checkbox)
                a.push('<td></td>');
            a.push('<td></td>');
        } else {
            if (pconf.checkbox)
                a.push('<td class="pap-checkbox"><input type="checkbox" name="' + render_checkbox_name(s) + '" value="1" class="pap-check" /></td>');
            a.push('<td class="pap-rownumber">' + row_number + '.</td>');
        }
        if (flagged) {
            a.push('<td class="pap-pset"><a href="' + escaped_href(s) + '">' +
                   escape_entities(peteramati_psets[s.psetid].title) +
                   (s.hash ? "/" + s.hash.substr(0, 7) : "") + '</a></td>');
            a.push('<td class="pap-at">' +
                   (s.at ? strftime("%#e %b %#k:%M", s.at) : "") + '</td>');
        }
        a.push('<td class="pap-username">' + render_username_td(s) + '</td>');
        // a.push('<td>' + (s.gradehash || "") + '</td>');
        a.push('<td class="pap-name' + (!s.anon_username || pconf.has_nonanonymous ? "" : " pap-nonanonymous") + '">' + render_display_name(s) + '</td>');
        a.push('<td class="pap-extension">' + (s.x ? "X" : "") + '</td>');
        if (s.gradercid && hotcrp_pc[s.gradercid])
            txt = grader_name(hotcrp_pc[s.gradercid]);
        else
            txt = s.gradercid ? "???" : "";
        a.push('<td class="pap-grader">' + txt + '</td>');
        if (flagged) {
            txt = '';
            if (s.is_grade)
                txt += '✱';
            if (s.has_notes)
                txt += '♪';
            a.push('<td class="pap-notes">' + txt + '</td>');
        } else if (!pconf.gitless_grades) {
            txt = '';
            if (s.has_notes)
                txt += '♪';
            if (s.has_nongrader_notes)
                txt += '<sup>*</sup>';
            a.push('<td class="pap-notes">' + txt + '</td>');
        }
        if (pconf.need_total)
            a.push('<td class="pap-total r">' + s.total + '</td>');
        for (j = 0; j < grade_keys.length; ++j) {
            var grade_empty = s.grades[j] == null || s.grades[j] === "";
            klass = "pap-grade";
            if (grade_keys[j] == pconf.total_key && !grade_empty)
                klass = "pap-total";
            if (s.highlight_grades && s.highlight_grades[grade_keys[j]])
                klass += " pap-highlight";
            a.push('<td class="' + klass + ' r">' + (grade_empty ? "" : s.grades[j]) + '</td>');
        }
        if (need_ngrades)
            a.push('<td class="pap-ngrades r">' + (s.ngrades_nonempty || "") + '</td>');
        if (!pconf.gitless) {
            if (!s.repo)
                txt = '';
            else if (anonymous)
                txt = '<a href="#" onclick="return pa_anonymize_linkto(' + escape_entities(JSON.stringify(s.repo)) + ',event)">repo</a>';
            else
                txt = '<a href="' + escape_entities(s.repo) + '">repo</a>';
            if (s.repo_broken)
                txt += ' <strong class="err">broken</strong>';
            if (s.repo_unconfirmed)
                txt += ' <strong class="err">unconfirmed</strong>';
            if (s.repo_too_open)
                txt += ' <strong class="err">open</strong>';
            if (s.repo_partner_error)
                txt += ' <strong class="err">partner</strong>';
            if (s.repo_sharing)
                txt += ' <strong class="err">sharing</strong>';
            a.push('<td class="pap-repo">' + txt + '</td>');
        }
        return a;
    }
    function set_hotlist($b) {
        var j = {sort: sort.f}, i, l, p;
        if (anonymous)
            j.anon = true;
        l = [];
        for (i = 0; i < data.length; ++i)
            l.push(data[i].uid);
        j.ids = l.join("'");
        if (flagged) {
            p = [];
            l = [];
            for (i = 0; i < data.length; ++i) {
                p.push(data[i].psetid);
                if (data[i].is_grade) {
                    l.push("x");
                } else if (data[i].hash) {
                    l.push(data[i].hash.substr(0, 7));
                } else {
                    l.push("");
                }
            }
            j.psetids = p.join("'");
            j.hashes = l.join("'");
        }
        $b.attr("data-hotlist", JSON.stringify(j));
    }
    function render_body() {
        var $b = $j.find("tbody");
        $b.html("");
        var trn = 0, was_boringness = 0;
        displaying_last_first = sort.f === "name" && sort.last;
        for (var i = 0; i < data.length; ++i) {
            var s = data[i];
            s._spos = dmap.length;
            dmap.push(s);
            var a = [];
            ++trn;
            if (s.boringness !== was_boringness && trn != 1)
                a.push('<tr class="pap-boring"><td colspan="' + calculate_ncol() + '"><hr /></td></tr>');
            was_boringness = s.boringness;
            var stds = render_tds(s, trn);
            a.push('<tr class="k' + (trn % 2) + '" data-pa-spos="' + s._spos + '">' + stds.join('') + '</tr>');
            for (var j = 0; s.partners && j < s.partners.length; ++j) {
                var ss = s.partners[j];
                ss._spos = dmap.length;
                dmap.push(ss);
                var sstds = render_tds(s.partners[j], "");
                for (var k = 0; k < sstds.length; ++k)
                    if (sstds[k] === stds[k])
                        sstds[k] = '<td></td>';
                a.push('<tr class="k' + (trn % 2) + ' papr-partner" data-pa-spos="' + ss._spos + '" data-pa-partner="1">' + sstds.join('') + '</tr>');
            }
            $b.append(a.join(''));
        }
        set_hotlist($b);
        $b.on("click", ".pap-check", checkbox_click);
    }
    function resort() {
        var $b = $j.find("tbody"), tb = $b[0];
        var rmap = {}, last = null, tr = tb.firstChild;
        while (tr) {
            if (tr.hasAttribute("data-pa-partner"))
                last.push(tr);
            else
                rmap[tr.getAttribute("data-pa-spos")] = last = [tr];
            tr = tr.nextSibling;
        }
        var i, j, trn = 0, was_boringness = 0;
        last = tb.firstChild;
        for (i = 0; i < data.length; ++i) {
            ++trn;
            while ((j = last) && j.className === "pap-boring") {
                last = last.nextSibling;
                tb.removeChild(j);
            }
            if (data[i].boringness !== was_boringness && trn != 1)
                tb.insertBefore($('<tr class="pap-boring"><td colspan="' + calculate_ncol() + '"><hr /></td></tr>')[0], last);
            was_boringness = data[i].boringness;
            tr = rmap[data[i]._spos];
            for (j = 0; j < tr.length; ++j) {
                if (last != tr[j])
                    tb.insertBefore(tr[j], last);
                else
                    last = last.nextSibling;
                tr[j].className = "k" + (trn % 2) + " " + tr[j].className.replace(/\bk[01]\s*/, "");
            }
            $(tr[0]).find(".pap-rownumber").html(trn + ".");
        }
        var display_last_first = sort.f && sort.last;
        if (display_last_first !== displaying_last_first) {
            displaying_last_first = display_last_first;
            $b.find(".pap-name").html(function () {
                var s = dmap[this.parentNode.getAttribute("data-pa-spos")];
                return render_display_name(s);
            });
        }
        set_hotlist($b);
        wstorage(true, "pa-pset" + psetid + "-table", JSON.stringify(sort));
    }
    function rerender_usernames() {
        $j.find("tbody td.pap-username").each(function () {
            var s = dmap[this.parentNode.getAttribute("data-pa-spos")];
            $(this).html(render_username_td(s));
        });
        $j.find("thead > tr > th.pap-username > span.heading").html(anonymous || !sort.email ? "Username" : "Email");
    }
    function switch_anon() {
        anonymous = !anonymous;
        if (!anonymous)
            sort.override_anonymous = true;
        $j.toggleClass("pap-anonymous", anonymous);
        rerender_usernames();
        $j.find("tbody input.pap-check").each(function () {
            var s = dmap[this.parentNode.parentNode.getAttribute("data-pa-spos")];
            this.setAttribute("name", render_checkbox_name(s));
        });
        sort_data();
        resort();
        return false;
    }
    function render_head() {
        var a = [], j, t;
        if (pconf.checkbox)
            a.push('<th class="pap-checkbox"></th>');
        a.push('<th class="pap-rownumber"></th>');
        if (flagged) {
            a.push('<th class="pap-pset l plsortable" data-pa-sort="pset">Pset</th>');
            a.push('<th class="pap-at l plsortable" data-pa-sort="at">Flagged</th>');
        }
        t = '<span class="heading">' + (anonymous || !sort.email ? "Username" : "Email") + '</span>';
        if (pconf.anonymous && pconf.can_override_anonymous)
            t += ' <a href="#" class="uu" style="font-weight:normal">[anon]</a>';
        else if (pconf.anonymous)
            t += ' <span style="font-weight:normal">[anon]</span>';
        a.push('<th class="pap-username l plsortable" data-pa-sort="username">' + t + '</th>');
        a.push('<th class="pap-name l' + (pconf.has_nonanonymous ? "" : " pap-nonanonymous") + ' plsortable" data-pa-sort="name">Name</th>');
        a.push('<th class="pap-extension l plsortable" data-pa-sort="extension">X?</th>');
        a.push('<th class="pap-grader l plsortable" data-pa-sort="grader">Grader</th>');
        if (!pconf.gitless_grades)
            a.push('<th class="pap-notes"></th>');
        if (pconf.need_total)
            a.push('<th class="pap-total r plsortable" data-pa-sort="total">Tot</th>');
        for (j = 0; j < grade_keys.length; ++j)
            a.push('<th class="pap-grade r plsortable" data-pa-sort="grade' + j + '">' + grade_abbr[j] + '</th>');
        if (need_ngrades)
            a.push('<th class="pap-ngrades r plsortable" data-pa-sort="ngrades">#G</th>');
        if (!pconf.gitless)
            a.push('<th class="pap-repo"></th>');
        $j.find("thead").html('<tr>' + a.join('') + '</tr>');
        $j.find("thead .pap-username a").click(switch_anon);
    }
    function user_compare(a, b) {
        var au = ukey(a).toLowerCase();
        var bu = ukey(b).toLowerCase();
        var rev = sort.rev;
        if (au < bu)
            return -rev;
        else if (au > bu)
            return rev;
        else if (a.psetid != b.psetid)
            return peteramati_psets[a.psetid].pos < peteramati_psets[b.psetid].pos ? -rev : rev;
        else if (a.at != b.at)
            return a.at < b.at ? -rev : rev;
        else
            return 0;
    }
    function set_name_sorters() {
        if (!!sort.last !== sorting_last) {
            sorting_last = !!sort.last;
            for (var i = 0; i < data.length; ++i)
                data[i]._sort_name = render_name(data[i], sorting_last).toLowerCase();
        }
    }
    function sort_data() {
        var f = sort.f, rev = sort.rev;
        if (f === "name" && !anonymous) {
            set_name_sorters();
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else if (a._sort_name != b._sort_name)
                    return a._sort_name < b._sort_name ? -rev : rev;
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
                else {
                    var ap = a.gradercid ? hotcrp_pc[a.gradercid] : null;
                    var bp = b.gradercid ? hotcrp_pc[b.gradercid] : null;
                    var ag = (ap && grader_name(ap)) || "~~~";
                    var bg = (bp && grader_name(bp)) || "~~~";
                    if (ag != bg)
                        return ag < bg ? -rev : rev;
                    else
                        return user_compare(a, b);
                }
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
        } else if ((m = /^grade(\d+)$/.exec(f))) {
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else {
                    var ag = a.grades && a.grades[m[1]];
                    if (ag === "" || ag == null)
                        ag = -1000;
                    var bg = b.grades && b.grades[m[1]];
                    if (bg === "" || bg == null)
                        bg = -1000;
                    if (ag != bg)
                        return ag < bg ? -rev : rev;
                    else
                        return -user_compare(a, b);
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
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else {
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
            f = "username";
            data.sort(function (a, b) {
                if (a.boringness !== b.boringness)
                    return a.boringness - b.boringness;
                else
                    return user_compare(a, b);
            });
        }
        $j.find(".plsortable").removeClass("plsortactive plsortreverse");
        $j.find("th[data-pa-sort='" + f + "']").addClass("plsortactive").
            toggleClass("plsortreverse", sort.rev < 0);
    }
    function head_click(event) {
        if (!this.hasAttribute("data-pa-sort"))
            return;
        var sf = this.getAttribute("data-pa-sort");
        if (sf !== sort.f) {
            sort.f = sf;
            if (sf === "username" || sf === "name" || sf === "grader"
                || sf === "extension" || sf === "pset" || sf === "at")
                sort.rev = 1;
            else
                sort.rev = -1;
        } else if (sf === "name") {
            sort.last = !sort.last;
            if (sort.last)
                sort.rev = -sort.rev;
        } else if (sf === "username") {
            if (sort.rev === -1 && !anonymous) {
                sort.email = !sort.email;
                rerender_usernames();
            }
            sort.rev = -sort.rev;
        } else {
            sort.rev = -sort.rev;
        }
        sort_data();
        resort();
    }
    function checkbox_click(event) {
        var $checks = $j.find(".pap-check"), pos, i, j;
        for (pos = 0; pos != $checks.length && $checks[pos] != this; ++pos)
            /* skip */;
        if (i != $checks.length) {
            var last = $j.data("pap-check-lastclick");
            if (event.shiftKey && last != null) {
                if (last <= pos) {
                    i = last;
                    j = pos - 1;
                } else {
                    i = pos + 1;
                    j = last;
                }
                for (; i <= j; ++i)
                    $checks[i].checked = this.checked;
            }
            $j.data("pap-check-lastclick", pos);
        }
        return true;
    }

    initialize();
    $j.html("<thead></thead><tbody class='has-hotlist'></tbody>");
    $j.toggleClass("pap-anonymous", !!anonymous);
    $j.toggleClass("pap-useemail", !!sort.email);
    $j.find("thead").on("click", "th", head_click);
    render_head();
    if (!pconf.no_sort)
        sort_data();
    render_body();
}

function pa_diff_toggle_hide_left() {
    var $x = $("head .style-hide-left");
    if ($x.length)
        $x.remove();
    else {
        var styles = $('<style class="style-hide-left"></style>').appendTo("head")[0].sheet;
        styles.insertRule('.pa-gd { display: none; }');
        styles.insertRule('.pa-gi { background-color: inherit; }');
    }
    if (this.tagName === "BUTTON")
        $(this).html($x.length ? "Hide left" : "Show left");
}

// autogrowing text areas; based on https://github.com/jaz303/jquery-grab-bag
function textarea_shadow($self) {
    return jQuery("<div></div>").css({
        position:    'absolute',
        top:         -10000,
        left:        -10000,
        width:       $self.width(),
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
function do_autogrow_textarea($self) {
    if ($self.data("autogrowing")) {
        $self.data("autogrowing")();
        return;
    }

    var shadow, minHeight, lineHeight;
    var update = function (event) {
        var width = $self.width();
        if (width <= 0)
            return;
        if (!shadow) {
            shadow = textarea_shadow($self);
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
    }

    $self.on("change input", update).data("autogrowing", update);
    $(window).resize(update);
    $self.val() && update();
}
function do_autogrow_text_input($self) {
    if ($self.data("autogrowing")) {
        $self.data("autogrowing")();
        return;
    }

    var shadow;
    var update = function (event) {
        var width = $self.width(), val = $self[0].value, ws;
        if (width <= 0)
            return;
        if (!shadow) {
            shadow = textarea_shadow($self);
            var p = $self.css(["paddingRight", "paddingLeft", "borderLeftWidth", "borderRightWidth"]);
            shadow.css({width: "auto", display: "table-cell", paddingLeft: $self.css("paddingLeft"), paddingLeft: (parseFloat(p.paddingRight) + parseFloat(p.paddingLeft) + parseFloat(p.borderLeftWidth) + parseFloat(p.borderRightWidth)) + "px"});
            ws = $self.css(["minWidth", "maxWidth"]);
            if (ws.minWidth == "0px")
                $self.css("minWidth", width + "px");
            if (ws.maxWidth == "none")
                $self.css("maxWidth", "640px");
        }
        shadow.text($self[0].value + "  ");
        ws = $self.css(["minWidth", "maxWidth"]);
        $self.outerWidth(Math.max(Math.min(shadow.outerWidth(), parseFloat(ws.maxWidth), $(window).width()), parseFloat(ws.minWidth)));
    }

    $self.on("change input", update).data("autogrowing", update);
    $(window).resize(update);
    $self.val() && update();
}
$.fn.autogrow = function () {
    this.filter("textarea").each(function () { do_autogrow_textarea($(this)); });
    this.filter("input[type='text']").each(function () { do_autogrow_text_input($(this)); });
    return this;
};
})(jQuery);

$(function () { $(".need-autogrow").autogrow().removeClass("need-autogrow"); });
