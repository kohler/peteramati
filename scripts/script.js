// script.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2016 Eddie Kohler
// See LICENSE for open-source distribution terms

var siteurl, siteurl_postvalue, siteurl_suffix, siteurl_defaults,
    siteurl_absolute_base,
    hotcrp_paperid, hotcrp_list, hotcrp_status, hotcrp_user,
    peteramati_uservalue, peteramati_grader_map,
    hotcrp_want_override_conflict;

function $$(id) {
    return document.getElementById(id);
}

function geval(__str) {
    return eval(__str);
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
        return "Error [" + status + "].";
    else
        return "Error.";
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
        "AltLeft": true,
        "AltRight": true,
        "CapsLock": true,
        "ControlLeft": true,
        "ControlRight": true,
        "OSLeft": true,
        "OSRight": true,
        "ShiftLeft": true,
        "ShiftRight": true,
        "ArrowLeft": true,
        "ArrowRight": true,
        "ArrowUp": true,
        "ArrowDown": true,
        "PageUp": true,
        "PageDown": true,
        "Escape": true,
        "Enter": true
    };
function event_key(evt) {
    var x;
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

function hoturl_clean(x, page_component) {
    var m;
    if (x.o && x.last !== false
        && (m = x.o.match(new RegExp("^(.*)(?:^|&)" + page_component + "(?:&|$)(.*)$")))) {
        x.t += "/" + m[2];
        x.o = m[1] + (m[1] && m[3] ? "&" : "") + m[3];
        x.last = m[2];
    } else
        x.last = false;
}

function hoturl(page, options) {
    var k, t, a, m, x, anchor = "", want_forceShow;
    if (siteurl == null || siteurl_suffix == null) {
        siteurl = siteurl_suffix = "";
        log_jserror("missing siteurl");
    }
    x = {t: siteurl + page + siteurl_suffix, o: serialize_object(options)};
    if ((m = x.o.match(/^(.*?)#(.*)()$/))
        || (m = x.o.match(/^(.*?)(?:^|&)anchor=(.*?)(?:&|$)(.*)$/))) {
        x.o = m[1] + (m[1] && m[3] ? "&" : "") + m[3];
        anchor = "#" + m[2];
    }
    if (page === "paper") {
        hoturl_clean(x, "p=(\\d+)");
        hoturl_clean(x, "m=(\\w+)");
        if (x.last === "api") {
            hoturl_clean(x, "fn=(\\w+)");
            want_forceShow = true;
        }
    } else if (page === "review")
        hoturl_clean(x, "p=(\\d+)");
    else if (page === "help")
        hoturl_clean(x, "t=(\\w+)");
    else if (page === "api") {
        hoturl_clean(x, "fn=(\\w+)");
        want_forceShow = true;
    } else if (page === "index")
        hoturl_clean(x, "u=([^?&]+)");
    else if (page === "pset" || page === "run") {
        hoturl_clean(x, "pset=([^?&]+)");
        hoturl_clean(x, "u=([^?&]+)");
        hoturl_clean(x, "commit=([0-9A-Fa-f]+)");
    }
    if (x.o && hotcrp_list
        && (m = x.o.match(/^(.*(?:^|&)ls=)([^&]*)((?:&|$).*)$/))
        && hotcrp_list.id == decodeURIComponent(m[2]))
        x.o = m[1] + hotcrp_list.num + m[3];
    if (hotcrp_want_override_conflict && want_forceShow
        && (!x.o || !/(?:^|&)forceShow=/.test(x.o)))
        x.o = (x.o ? x.o + "&" : "") + "forceShow=1";
    a = [];
    if (siteurl_defaults)
        a.push(serialize_object(siteurl_defaults));
    if (x.o)
        a.push(x.o);
    if (a.length)
        x.t += "?" + a.join("&");
    return x.t + anchor;
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
    return $('<div style="display:none" class="bubble' + color + '"><div class="bubtail bubtail0' + color + '"></div></div>').appendTo(document.body);
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
                    dirspec = epos.attr("data-tooltip-dir");
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
            nearpos && $(bubdiv).css({maxWidth: "", left: "", top: ""});
            if (typeof content == "string")
                n.innerHTML = content;
            else {
                while (n.childNodes.length)
                    n.removeChild(n.childNodes[0]);
                if (content)
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
            jQuery(bubdiv).hover(enter, leave);
            return bubble;
        },
        removeOn: function (jq, event) {
            jQuery(jq).on(event, remove);
            return bubble;
        },
        self: function () {
            return bubdiv ? jQuery(bubdiv) : null;
        }
    };

    content && bubble.html(content);
    return bubble;
};
})();


function tooltip(info) {
    if (window.disable_tooltip)
        return null;

    var j;
    if (info.tagName)
        info = {element: info};
    j = $(info.element);

    function jqnear(x) {
        if (x && x.charAt(0) == ">")
            return j.find(x.substr(1));
        else if (x)
            return $(x);
        else
            return $();
    }

    var tt = null, content = info.content, bub = null, to = null, refcount = 0;
    function erase() {
        to = clearTimeout(to);
        bub && bub.remove();
        j.removeData("hotcrp_tooltip");
        if (window.global_tooltip === tt)
            window.global_tooltip = null;
    }
    function show_bub() {
        if (content && !bub) {
            bub = make_bubble(content, {color: "tooltip dark", dir: info.dir});
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
            var delay = info.type == "focus" ? 0 : 200;
            to = clearTimeout(to);
            if (--refcount == 0)
                to = setTimeout(erase, delay);
            return tt;
        },
        erase: erase,
        elt: info.element,
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

    if (info.dir == null)
        info.dir = j.attr("data-tooltip-dir") || "v";
    if (info.type == null)
        info.type = j.attr("data-tooltip-type");
    if (info.near == null)
        info.near = j.attr("data-tooltip-near");
    if (info.near)
        info.near = jqnear(info.near)[0];

    function complete(new_content) {
        var tx = window.global_tooltip;
        content = new_content;
        if (tx && tx.elt == info.element && tx.html() == content && !info.done)
            tt = tx;
        else {
            tx && tx.erase();
            j.data("hotcrp_tooltip", tt);
            show_bub();
            window.global_tooltip = tt;
        }
    }

    if (content == null && j[0].hasAttribute("data-tooltip"))
        content = j.attr("data-tooltip");
    if (content == null && j[0].hasAttribute("data-tooltip-content-selector"))
        content = jqnear(j.attr("data-tooltip-content-selector")).html();
    if (content == null && j[0].hasAttribute("data-tooltip-content-promise"))
        geval.call(this, j[0].getAttribute("data-tooltip-content-promise")).then(complete);
    else
        complete(content);
    info.done = true;
    return tt;
}

function tooltip_enter() {
    var tt = $(this).data("hotcrp_tooltip") || tooltip(this);
    tt && tt.enter();
}

function tooltip_leave() {
    var tt = $(this).data("hotcrp_tooltip");
    tt && tt.exit();
}

function tooltip_erase() {
    var tt = $(this).data("hotcrp_tooltip");
    tt && tt.erase();
}

function add_tooltip() {
    var j = jQuery(this);
    if (j.attr("data-tooltip-type") == "focus")
        j.on("focus", tooltip_enter).on("blur", tooltip_leave);
    else
        j.hover(tooltip_enter, tooltip_leave);
    j.removeClass("need-tooltip");
}

jQuery(function () { jQuery(".hottooltip, .need-tooltip").each(add_tooltip); });


// temporary text
window.mktemptext = (function () {
function ttaction(event) {
    var $e = $(this), p = $e.attr("placeholder"), v = $e.val();
    if (event.type == "focus" && v === p)
        $e.val("");
    if (event.type == "blur" && (v === "" | v === p))
        $e.val(p);
    $e.toggleClass("temptext", event.type != "focus" && (v === "" || v === p));
}

if (Object.prototype.toString.call(window.operamini) === '[object OperaMini]'
    || !("placeholder" in document.createElement("input"))
    || !("placeholder" in document.createElement("textarea")))
    return function (e) {
        e = typeof e === "number" ? this : e;
        $(e).on("focus blur change", ttaction);
        ttaction.call(e, {type: "blur"});
    };
else
    return function (e) {
        ttaction.call(typeof e === "number" ? this : e, {type: "focus"});
    };
})();


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
function e_value(id, value) {
    var elt = $$(id);
    if (value == null)
        return elt ? elt.value : undefined;
    else if (elt)
        elt.value = value;
}

function eltPos(e) {
    if (typeof e == "string")
        e = $$(e);
    var pos = {
        top: 0, left: 0, width: e.offsetWidth, height: e.offsetHeight,
        right: e.offsetWidth, bottom: e.offsetHeight
    };
    while (e) {
        pos.left += e.offsetLeft;
        pos.top += e.offsetTop;
        pos.right += e.offsetLeft;
        pos.bottom += e.offsetTop;
        e = e.offsetParent;
    }
    return pos;
}

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
hotcrp_load.temptext = function () {
    jQuery("input[hottemptext]").each(mktemptext);
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


// Thank you David Flanagan
var Miniajax = (function () {
var Miniajax = {}, outstanding = {}, jsonp = 0,
    _factories = [
        function () { return new XMLHttpRequest(); },
        function () { return new ActiveXObject("Msxml2.XMLHTTP"); },
        function () { return new ActiveXObject("Microsoft.XMLHTTP"); }
    ];
function newRequest() {
    while (_factories.length) {
        try {
            var req = _factories[0]();
            if (req != null)
                return req;
        } catch (err) {
        }
        _factories.shift();
    }
    return null;
}
Miniajax.onload = function (formname) {
    var req = newRequest();
    if (req)
        fold($$(formname), 1, 7);
};
Miniajax.submit = function (formname, callback, timeout) {
    var form, req = newRequest(), resultname, myoutstanding;
    if (typeof formname !== "string") {
        resultname = formname[1];
        formname = formname[0];
    } else
        resultname = formname;
    outstanding[formname] = myoutstanding = [];

    form = $$(formname);
    if (!form || !req || form.method != "post") {
        fold(form, 0, 7);
        return true;
    }
    var resultelt = $$(resultname + "result") || {};
    if (!callback)
        callback = function (rv) {
            resultelt.innerHTML = ("response" in rv ? rv.response : "");
        };
    if (!timeout)
        timeout = 4000;

    // set request
    var timer = setTimeout(function () {
                               req.abort();
                               resultelt.innerHTML = "<span class='merror'>Network timeout. Please try again.</span>";
                               form.onsubmit = "";
                               fold(form, 0, 7);
                           }, timeout);

    req.onreadystatechange = function () {
        var i, j;
        if (req.readyState != 4)
            return;
        clearTimeout(timer);
        if (req.status == 200)
            try {
                j = jQuery.parseJSON(req.responseText);
            } catch (err) {
                err.message += " [" + form.action + "]";
                log_jserror(err);
            }
        if (j) {
            resultelt.innerHTML = "";
            callback(j);
            if (j.ok)
                hiliter(form, true);
        } else {
            resultelt.innerHTML = "<span class='merror'>Network error. Please try again.</span>";
            form.onsubmit = "";
            fold(form, 0, 7);
        }
        delete outstanding[formname];
        for (i = 0; i < myoutstanding.length; ++i)
            myoutstanding[i]();
    };

    // collect form value
    var pairs = [];
    for (var i = 0; i < form.elements.length; i++) {
        var elt = form.elements[i];
        if (elt.name && elt.type != "submit" && elt.type != "cancel"
            && (elt.type != "checkbox" || elt.checked))
            pairs.push(encodeURIComponent(elt.name) + "="
                       + encodeURIComponent(elt.value));
    }
    pairs.push("ajax=1");

    // send
    req.open("POST", form.action);
    req.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    req.send(pairs.join("&").replace(/%20/g, "+"));
    return false;
};
Miniajax.isoutstanding = function (formname, callback) {
    var myoutstanding = outstanding[formname];
    myoutstanding && callback && myoutstanding.push(callback);
    return !!myoutstanding;
};
return Miniajax;
})();


// list management, conflict management
(function ($) {
function set_cookie(info) {
    var p = "", m;
    if (siteurl && (m = /^[a-z]+:\/\/[^\/]*(\/.*)/.exec(hoturl_absolute_base())))
        p = "; path=" + m[1];
    if (info)
        document.cookie = "hotlist-info=" + encodeURIComponent(info) + "; max-age=2" + p;
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
    return true;
}
function unload_list() {
    hotcrp_list && set_cookie(JSON.stringify(hotcrp_list));
}
function row_click(e) {
    var j = $(e.target);
    if (j.hasClass("pl_id") || j.hasClass("pl_title")
        || j.closest("td").hasClass("pl_rowclick"))
        $(this).find("a.pnum")[0].click();
}
function prepare() {
    $(document.body).on("click", "a", add_list);
    $(document.body).on("submit", "form", add_list);
    //$(document.body).on("click", "tbody.pltable > tr.pl", row_click);
    hotcrp_list && $(window).on("beforeunload", unload_list);
}
document.body ? prepare() : $(prepare);
})(jQuery);


// mail
function setmailpsel(sel) {
    fold("psel", !!sel.value.match(/^(?:pc$|pc:|all$)/), 9);
    fold("psel", !sel.value.match(/^new.*rev$/), 10);
}


window.linenote61 = (function ($) {
function analyze(target) {
    var table, linetype, linenumber, tr, result, x;
    if (!target || target.tagName == "TEXTAREA" || target.tagName == "A")
        return null;
    while (target && target.tagName != "TR") {
        if (target.tagName == "FORM")
            return null;
        target = target.parentNode;
    }
    tr = target;
    while (tr && (tr.nodeType != Node.ELEMENT_NODE
                  || /\bdiffl61\b.*\bgw\b/.test(tr.className)))
        tr = tr.previousSibling;
    table = tr;
    while (table && !table.getAttribute("data-pa-file"))
        table = table.parentNode;
    if (!tr || !table || !/\bdiffl61\b.*\bg[idc]\b/.test(tr.className))
        return null;

    result = {filename: table.getAttribute("data-pa-file"), tr: tr};
    if ((x = $(tr).find("td.difflnb61").text()))
        result.lineid = "b" + x;
    else
        result.lineid = "a" + $(tr).find("td.difflna61").text();

    if (tr == target)
        do {
            target = target.nextSibling;
        } while (target && target.nodeType != Node.ELEMENT_NODE);
    if (target && /\bdiffl61\b.*\bgw\b/.test(target.className)
        && !target.getAttribute("deleting61"))
        result.notetr = target;
    return result;
}

function remove_tr(tr) {
    tr.setAttribute("deleting61", "1");
    $(tr).find(":focus").blur();
    $(tr).children().slideUp(80).queue(function () { $(tr).remove(); });
}

function unedit(tr) {
    var savednote;
    while (tr && (savednote = tr.getAttribute("data-pa-savednote")) === null)
        tr = tr.parentNode;
    if (tr && text_eq(savednote, $(tr).find("textarea").val())) {
        var $tr = $(tr);
        $tr.find(":focus").blur();
        if (savednote === "")
            remove_tr(tr);
        else {
            var iscomment = !!tr.getAttribute("data-pa-iscomment"),
                $td = $tr.find("td.difflnote61"),
                $note = $('<div class="note61' + (iscomment ? " commentnote" : " gradenote") + '" style="display:none"></div>'),
                $edit = $td.find(".diffnoteholder61");
            $note.text(savednote);
            $td.append($note);
            $edit.slideUp(80).queue(function () { $edit.remove(); });
            $note.slideDown(80);
        }
        return true;
    } else
        return false;
}

function keyup(evt) {
    if (!(evt.ctrlKey || evt.altKey || evt.shiftKey)
        && evt.keyCode == 27
        && unedit(this))
        return false;
    else
        return true;
}

var mousedown_tr, mousedown_selection;

function selection_string() {
    var s;
    if (window.getSelection
        && (s = window.getSelection())
        && s.toString)
        return s.toString();
    else
        return "";
}

function linenote61(event) {
    var anal = analyze(event.target);
    if (anal && event.type == "mousedown") {
        mousedown_tr = anal.tr;
        mousedown_selection = selection_string();
        return true;
    } else if (anal && event.type == "mouseup"
               && mousedown_tr == anal.tr
               && mousedown_selection == selection_string())
        /* this is a click */;
    else if (anal && event.type == "click")
        /* this is an old-style click */;
    else {
        mousedown_tr = mousedown_selection = null;
        return true;
    }

    var $tr = anal.notetr && $(anal.notetr), j, text = null, iscomment = false;
    if ($tr && !$tr.find("textarea").length) {
        text = $tr.find("div.note61").text();
        iscomment = $tr.find("div.note61").is(".commentnote");
        remove_tr($tr[0]);
        $tr = anal.notetr = null;
    } else if ($tr) {
        if (!unedit($tr[0])) {
            j = $tr.find("textarea").focus();
            j[0].setSelectionRange && j[0].setSelectionRange(0, j.val().length);
        }
        return false;
    }

    $tr = $($("#diff61linenotetemplate > tbody").html());
    $tr.insertAfter(anal.tr);
    $tr.attr("data-pa-savednote", text === null ? "" : text).attr("data-pa-iscomment", iscomment ? "1" : null);
    $tr.find(".diffnoteholder61").show();
    j = $tr.find("textarea").focus();
    if (text !== null) {
        $tr.removeClass("iscomment61 isgrade61").addClass(iscomment ? "iscomment61" : "isgrade61");
        j.text(text);
        j[0].setSelectionRange && j[0].setSelectionRange(text.length, text.length);
    }
    j.autogrow().keyup(keyup);
    $tr.find("input[name=file]").val(anal.filename);
    $tr.find("input[name=line]").val(anal.lineid);
    $tr.find("input[name=iscomment]").prop("checked", iscomment);
    $tr.children().hide().slideDown(100);
    return false;
}

linenote61.unedit = unedit;
return linenote61;
})(jQuery);

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

function savelinenote61(form) {
    return ajaxsave61(form, function (data) {
            jQuery(form).closest("tr").attr("data-pa-savednote", data.savednote)
                .attr("data-pa-iscomment", data.iscomment ? "1" : null);
            linenote61.unedit(form);
        });
}

function gradetotal61(data) {
    var i, j = $(".grader61.gradepart"), total = 0, x;
    for (i = 0; i < j.length; ++i) {
        x = parseFloat(jQuery(j[i]).val());
        x == x && (total += x);
    }
    total = Math.floor(total * 100 + 0.5) / 100;
    var $gt = $(".gradetotal61");
    if ($gt.text() != total) {
        $gt.text(total);
        gradecdf61();
    }
    if (data.grades)
        for (i in data.grades)
            $("input[name='old;" + i + "']").val(data.grades[i]);
}

function gradesubmit61(form) {
    return ajaxsave61(form, gradetotal61);
}

function fold61(sel, arrowholder, direction) {
    var j = sel instanceof jQuery ? sel : jQuery(sel);
    j.toggle(direction);
    if (arrowholder)
        jQuery(arrowholder).find("span.foldarrow").html(
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
    var therun = jQuery("#run61_" + name), thebutton;
    if (therun.attr("data-pa-timestamp") && !therun.is(":visible")) {
        thebutton = jQuery(".runner61[value='" + name + "']")[0];
        run61(thebutton, {unfold: true});
    } else
        fold61(therun, jQuery("#run61out_" + name));
    return false;
}

function gotoline61(x) {
    var m, e, color;
    function flasher() {
        e.css("backgroundColor", "#ffff00");
        jQuery(this).dequeue();
    }
    function restorer() {
        e.css("backgroundColor", "");
        jQuery(this).dequeue();
    }
    if (x instanceof Node)
        x = x.hash;
    if ((m = x.match(/^#?(L[ab]\d+_(.*))$/))) {
        jQuery(".anchorhighlight").removeClass("anchorhighlight").finish();
        jQuery("#file61_" + m[2]).show();
        e = jQuery("#" + m[1]).closest("tr");
        color = e.css("backgroundColor");
        e.addClass("anchorhighlight")
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

function beforeunload61(evt) {
    var x = jQuery("tr.gw"), i, j, textarea, note;
    for (i = 0; i < x.length; ++i)
        if ((note = x[i].getAttribute("data-pa-savednote")) !== null) {
            textarea = jQuery(x[i]).find("textarea");
            if (textarea.length && textarea.val() != note)
                return (event.returnValue = "You have unsaved notes. You will lose them if you leave the page now.");
        }
}

function loadgrade61() {
    jQuery.ajax(psetpost61, {
        type: "GET", cache: false, data: "gradestatus=1",
        dataType: "json", success: function (d) {
            jQuery(".grader61").each(function (i, elt) {
                elt = jQuery(elt);
                var n = elt.attr("data-pa-grade") || elt.attr("name");
                if (n in d.grades) {
                    if (elt.is("input"))
                        elt.val(d.grades[n]);
                    else
                        elt.text(d.grades[n]);
                }
                if (n in d.grades && d.autogrades && n in d.autogrades) {
                    elt = elt.closest("td");
                    elt.find(".autograde61").remove();
                    if (d.grades[n] != d.autogrades[n]) {
                        elt.append(" <span class=\"autograde61\">autograde is </span>");
                        elt.find(".autograde61").append(document.createTextNode(d.autogrades[n]));
                    }
                }
                gradetotal61();
            });
        }
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

function run61(button, opt) {
    var form = $(button).closest("form"),
        runclass = button.getAttribute("data-pa-runclass") || button.value,
        therun = $("#run61_" + runclass), thepre = therun.find("pre"), checkt;

    if (typeof opt !== "object")
        opt = {};
    if (opt.unfold && therun.attr("data-pa-timestamp"))
        checkt = +therun.attr("data-pa-timestamp");
    else {
        if (form.prop("outstanding"))
            return true;
        form.find("button").prop("disabled", true);
        form.prop("outstanding", true);
    }
    therun.removeAttr("data-pa-timestamp");

    fold61(therun, jQuery("#run61out_" + runclass).show(), true);
    if (!checkt && !opt.noclear)
        thepre.html("");
    else
        therun.find("span.run61cursor").remove();
    thepre.append("<span class='run61cursor'>_</span>");

    if (checkt && !therun.prop("openedbefore")) {
        therun.scrollTop(therun.children().height() - therun.height());
        therun.prop("openedbefore", true);
    }

    var ibuffer = "", // initial buffer; holds data before any results arrive
        styles = null,
        offset = -1, backoff = 50, queueid = null,
        thecursor = therun.find("span.run61cursor")[0];

    function animate() {
        jQuery(thecursor).dequeue().animate({opacity: 0.1}, 200).delay(100).animate({opacity: 1}, 200).delay(400).queue(animate);
    }
    animate();

    function done() {
        form.find("button").prop("disabled", false);
        form.prop("outstanding", false);
        jQuery(thecursor).finish().remove();
        if (jQuery(button).attr("data-pa-loadgrade"))
            loadgrade61();
    }

    function addlinepart(node, text) {
        if (typeof text === "string")
            text = document.createTextNode(text);
        else if (text instanceof jQuery)
            text = text[0];
        if (styles && styles !== "\x1b[0m") {
            var sclass = [], col = [], rv = 0, m;
            if ((m = styles.match(/;3\d[;m]/)))
                col[0] = m[0].charAt(2);
            if ((m = styles.match(/;4\d[;m]/)))
                col[1] = m[0].charAt(2);
            if (/;1[;m]/.test(styles))
                sclass.push("ansib");
            if (/;3[;m]/.test(styles))
                sclass.push("ansii");
            if (/;4[;m]/.test(styles))
                sclass.push("ansiu");
            if (/;7[;m]/.test(styles)) {
                sclass.push("ansirv");
                rv = 1;
            }
            if (/;9[;m]/.test(styles))
                sclass.push("ansis");
            if (col[rv] != null)
                sclass.push("ansifg" + col[rv]);
            if (col[1-rv] != null)
                sclass.push("ansibg" + col[1-rv]);
            if (sclass.length) {
                var decor = document.createElement("span");
                decor.className = sclass.join(" ");
                decor.appendChild(text);
                text = decor;
            }
        }
        node.appendChild(text);
    }

    function ansi_combine(a1, a2) {
        var m, i, a;
        if ((m = a2.match(/^\x1b\[([\d;]+)m$/))) {
            a1 = a1 ? a1.substring(2, a1.length - 1) + ";" : "0;";
            a = m[1].split(/;/);
            for (i = 0; i < a.length; ++i) {
                if (a[i] == "")
                    /* do nothing */;
                else if (+a[i] == 0)
                    a1 = "0;";
                else if (+a[i] <= 9)
                    a1 = a1.replace(";" + a[i] + ";", ";") + a[i] + ";";
                else if (+a[i] <= 29)
                    a1 = a1.replace(";" + (a[i] - 20) + ";", ";");
                else
                    a1 = a1.replace(new RegExp(";" + a[i].charAt(0) + "\\d;"), ";") + a[i] + ";";
            }
            a1 = "\x1b[" + a1.substring(0, a1.length - 1) + "m";
        }
        return a1;
    }

    function ends_with_newline(str) {
        return str !== "" && str.charAt(str.length - 1) === "\n";
    }

    function clean_cr(line) {
        var curstyle = styles || "\x1b[0m",
            parts = line.split(/\r/),
            partno, i, m, r = [];
        for (partno = 0; partno < parts.length; ++partno) {
            var g = [], glen = 0, j;
            var lsplit = parts[partno].split(/(\x1b\[[\d;]+m)/);
            for (j = 0; j < lsplit.length; j += 2) {
                if (lsplit[j] !== "") {
                    g.push(curstyle, lsplit[j]);
                    glen += lsplit[j].length;
                }
                if (j + 1 < lsplit.length)
                    curstyle = ansi_combine(curstyle, lsplit[j + 1]);
            }
            var rpos = 0;
            while (rpos < r.length && glen >= r[rpos + 1].length) {
                glen -= r[rpos + 1].length;
                rpos += 2;
            }
            if (g.length && !/\n$|\x1b\[0?K/.test(g[g.length - 1]))
                while (rpos < r.length) {
                    g.push(r[rpos], r[rpos + 1].substr(glen));
                    glen = 0;
                    rpos += 2;
                }
            r = g;
        }
        return r.join("");
    }

    function add_file_link(node, file, line, link) {
        var filematch = $(".filediff61[data-pa-file='" + file + "']"), dir;
        if (!filematch.length && (dir = therun.attr("data-pa-directory")))
            filematch = $(".filediff61[data-pa-file='" + dir + "/" + file + "']");
        if (filematch.length) {
            var anchor = "Lb" + line + "_" + filematch.attr("data-pa-fileid");
            if (document.getElementById(anchor)) {
                var a = $("<a href=\"#" + anchor + "\" onclick=\"return gotoline61(this)\"></a>");
                a.text(link);
                addlinepart(node, a);
                return true;
            }
        }
        return false;
    }

    function render_line(line, node) {
        var m, filematch, dir, a, i, x, isnew = !node, displaylen = 0;
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
            if ((m = line.match(/^(.*?)(\x1b\[[\d;]+m|\x1b\[\d*K)([^]*)$/))) {
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
                isnew && thepre[0].insertBefore(node, thepre[0].lastChild);
                node = document.createElement("span");
                isnew = true;
                displaylen = 0;
            } else {
                addlinepart(node, render);
                displaylen += render.length;
            }
            line = line.substr(render.length);
        }
        isnew && thepre[0].insertBefore(node, thepre[0].lastChild);
    }

    function append(str) {
        var atbottom = therun.scrollTop() >= therun.children().height() - therun.height() - 10;

        // hide newline on last line
        var lines = str.split(/^/m);
        if (lines[lines.length - 1] === "")
            lines.pop();
        var lastfull = ends_with_newline(lines[lines.length - 1]);

        var node = thepre[0].lastChild.previousSibling, str;
        if (node && (str = node.getAttribute("data-pa-outputpart")) !== null
            && str !== "" && lines.length) {
            while (node.firstChild)
                node.removeChild(node.firstChild);
            lines[0] = str + lines[0];
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
            for (j = i + 1; !ends_with_newline(last) && j < lines.length; ++j)
                last += lines[j];
            if (j == lines.length && lastfull)
                last = last.substring(0, last.length - 1);
            render_line(last, i ? null : node);
        }

        if (!lastfull) {
            styles = laststyles;
            if ((node = thepre[0].lastChild.previousSibling))
                node.setAttribute("data-pa-outputpart", last);
        }
        if (atbottom)
            therun.scrollTop(Math.max(Math.ceil(therun.children().height() - therun.height()), 0));
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

            var j = jQuery(thecursor).detach();
            if (!opt.noclear)
                thepre.html("");
            thepre.append(j);

            if (data && data.timestamp) {
                var d = new Date(data.timestamp * 1000);
                append_html("<span class='run61timestamp'>...started "
                            + strftime("%l:%M:%S%P %e %b %Y", d) + "</span>");
            }
        }
        if (str !== "")
            append(str);
    }

    function succeed(data) {
        var x, t;

        if (queueid)
            thepre.find("span.run61queue").remove();
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
            thepre[0].insertBefore(($("<span class='run61queue'>" + t + "</span>"))[0], thepre[0].lastChild);
            setTimeout(send, 10000);
            return;
        }

        if (data && data.status == "working") {
            if (!$("#run61stop_" + runclass).length)
                $("<button id=\"run61stop_" + runclass + "\" class=\"run61stop\" type=\"button\">Stop</button>")
                    .click(stop).appendTo("#run61out_" + runclass + " > h3");
        } else
            $("#run61stop_" + runclass).remove();

        if (!data || !data.ok) {
            if (data && data.loggedout)
                x = "You have been logged out (perhaps due to inactivity). Please reload this page.";
            else if (data && (data.message || typeof(data.error) === "string"))
                x = data.message || data.error;
            else
                x = "Unknown";
            append_html("<i><strong>Error: " + x + "</strong></i>");
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
        var a = {run: runclass, offset: offset};
        checkt && (a.check = checkt);
        queueid && (a.queueid = queueid);
        args && $.extend(a, args);
        jQuery.ajax(form.attr("action"), {
            data: form.serializeWith(a),
            type: "POST", cache: false,
            dataType: "json",
            success: succeed,
            error: function () {
                form.find(".ajaxsave61").html("Failed");
                form.prop("outstanding", false);
            }
        });
    }

    function stop() {
        send({stop: 1});
    }

    if (opt.headline && opt.noclear && thepre[0].firstChild != thecursor)
        append("\n\n");
    if (opt.headline && opt.headline instanceof Node)
        append_html(opt.headline);
    else if (opt.headline)
        append("\x1b[01;37m" + opt.headline + "\x1b[0m\n");
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
        var $x = jQuery("<a href=\"" + siteurl + "pset/" + $f.find("[name='pset']").val() + "/" + encodeURIComponent(user) + "\" class=\"q ansib ansifg7\"></a>");
        $x.text(user);
        run61($manybutton[0], {noclear: true, headline: $x[0]});
    }
    setTimeout(runmany61, 10);
}

var runsetting61 = (function ($) {

function save() {
    var $j = $("#runsettings61 .cs61grp"), j = {}, i, k, v;
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
    var $x = $("<table class=\"cs61grp\" runsetting61num=\"" + num + "\"><tr><td class=\"cs61key\"></td><td><input name=\"n" + num + "\" class=\"n\" size=\"30\" placeholder=\"Name\" /> &nbsp; <input name=\"v" + num + "\" class=\"v\" size=\"40\" placeholder=\"Value\" /></td></tr></table>");
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

function gradecdf61_series(d, total) {
    var i, data = [];
    for (i = 0; i < d.cdf.length; i += 2) {
        if (i != 0 || !d.cutoff)
            data.push([d.cdf[i], i > 0 ? d.cdf[i-1]/d.n : 0]);
        else
            data.push([d.cdf[0], d.cutoff]);
        data.push([d.cdf[i], d.cdf[i+1]/d.n]);
        if (data.totalx == null && d.cdf[i] >= total)
            data.totalx = d.cdf[i+1]/d.n;
    }
    return data;
}

function gradecdf61(url) {
    if (!url && !(url = jQuery(".gradecdf61table").prop("gradecdf61url")))
        return;
    jQuery.ajax(url, {
        type: "GET", cache: false,
        dataType: "json",
        success: function (d) {
            var dx, i, $all, j, jentry, series, x, total, totalx, grid;
            if (d.cdf) {
                // load user grade
                total = 0;
                j = jQuery(".grader61.gradepart");
                for (i = 0; i < j.length; ++i) {
                    jentry = jQuery(j[i]);
                    x = (jentry.is("input") ? jentry.val() : jentry.text());
                    x = parseFloat(x);
                    x == x && (total += x);
                }

                // load cdf
                dx = d.extension || null;
                if (dx)
                    series = [{data: gradecdf61_series(dx, total), color: "#ee6666", label: "extension"},
                              {data: gradecdf61_series(d, total), color: "#ffaaaa", lines: {lineWidth: 0.8}, label: "all"}];
                else if (d.noextra)
                    series = [{data: gradecdf61_series(d, total), color: "#ee6666", label: "all"},
                              {data: gradecdf61_series(d.noextra, total), color: "#ffaaaa", lines: {lineWidth: 0.8}, label: "noextra"}];
                else
                    series = [{data: gradecdf61_series(d, total), color: "#ee6666"}];
                series.push({data: [[total, series[0].data.totalx]], color: "#222266", points: {show: true, radius: 5, fillColor: "#ffff00"}});

                // check max-x
                var xaxis = {min: 0};
                var datamax = d.cdf[d.cdf.length - 2];
                if (dx && dx.cdf)
                    datamax = Math.max(datamax, dx.cdf[dx.cdf.length - 2]);
                grid = {markings: []};
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
                $all = jQuery("#gradecdf61");
                j = $all.find(".gradecdf61table");
                j.prop("gradecdf61url", url);
                j.find(".plot > div").plot(series, {
                    xaxis: xaxis,
                    yaxis: {min: 0, max: 1},
                    grid: grid,
                    legend: {position: "nw", labelBoxBorderColor: "transparent"}
                });
                j.find(".yaxislabelcontainer").html('<div class="yaxislabel">fraction of results</div>');
                j.find(".yaxislabel").css("left", -0.5*j.find(".yaxislabel").width());
                j.find(".xaxislabelcontainer").html('<div class="xaxislabel">grade</div>');

                // summary
                for (i in {"all": 1, "extension": 1}) {
                    var $sum = $all.find(".gradecdf61summary." + i);
                    var dd = (i == "all" ? d : dx) || {};
                    for (x in {"mean":1, "median":1, "stddev":1}) {
                        j = $sum.find(".gradecdf61" + x);
                        if (x in dd)
                            j.show().find(".val").text(dd[x].toFixed(1));
                        else
                            j.hide();
                    }
                }
            }
        }
    });
}

function checklatest61() {
    var start = (new Date).getTime(), timeout, pset, hash;

    function checkdata(d) {
        if (d && d.hash && d.hash != hash && (!d.snaphash || d.snaphash != hash)) {
            jQuery(".commitcontainer61 .cs61infgroup").first().append("<div class=\"cs61einf\"><span class=\"cs61hienote\">Newer commits are available.</span> <a href=\"#\" onclick=\"location.reload(true)\">Load them</a></div>");
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
        jQuery.ajax(hoturl_post("pset", {u: peteramati_uservalue, pset: pset}),
                    {
                        type: "GET", cache: false, data: "latestcommit=1",
                        dataType: "json", success: checkdata
                    });
    }

    pset = jQuery(".commitcontainer61").first().attr("data-pa-pset");
    if (pset) {
        hash = jQuery(".commitcontainer61").first().attr("data-pa-commit");
        setTimeout(docheck, 2000);
    }
}

function click_s61check(evt) {
    var $form = $(this).closest("form"), $checks = $form.find(".s61check");
    var pos, i, j;
    for (pos = 0; pos != $checks.length && $checks[pos] != this; ++pos)
        /* skip */;
    if (i != $checks.length) {
        var last = $form.attr("check_s61_last");
        if (evt.shiftKey && last != null) {
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
        $form.attr("check_s61_last", pos);
    }
    return true;
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
    var $j = $("#pa-pset" + psetid), dmap = {},
        sort = {f: "username", last: true, rev: 1}, sorting_last,
        displaying_last_first = null,
        anonymous = pconf.anonymous,
        username_key = anonymous ? "anon_username" : "username";

    function default_sorting() {
        var x = wstorage(true, "pa-pset" + psetid + "-table");
        x && (sort = JSON.parse(x));
        if (!sort.f || !/^\w+$/.test(sort.f))
            sort.f = "username";
        if (sort.rev !== 1 && sort.rev !== -1)
            sort.rev = 1;
    }
    function calculate_ncol() {
        return (pconf.checkbox ? 1 : 0) + 5 + (pconf.gitless_grades ? 0 : 1) +
            (pconf.need_total ? 1 : 0) + (pconf.grade_keys || []).length +
            (pconf.gitless ? 0 : 1);
    }
    function render_username_td(s) {
        var t = '<a href="' + pconf.urlpattern.replace(/%40/, encodeURIComponent(s[username_key]));
        if (s.dropped)
            t += '" style="text-decoration:line-through';
        return t + '">' + escape_entities(s[username_key]) + '</a>';
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
    function set_name_sorters() {
        if (!!sort.last !== sorting_last) {
            sorting_last = !!sort.last;
            for (var i = 0; i < data.length; ++i)
                data[i]._sort_name = render_name(data[i], sorting_last).toLowerCase();
        }
    }
    function render_tds(s, row_number) {
        var grades = pconf.grade_keys || [];
        var a = [], txt, j, klass;
        if (row_number == "") {
            if (pconf.checkbox)
                a.push('<td></td>');
            a.push('<td></td>');
        } else {
            if (pconf.checkbox)
                a.push('<td class="s61checkbox"><input type="checkbox" name="s61_' + encodeURIComponent(s.username).replace(/\./g, "%2E") + '" value="1" class="s61check" /></td>');
            a.push('<td class="s61rownumber">' + row_number + '.</td>');
        }
        a.push('<td class="s61username">' + render_username_td(s) + '</td>');
        a.push('<td class="s61name s61nonanonymous">' +
               escape_entities(render_name(s, displaying_last_first)) + '</td>');
        a.push('<td class="s61extension">' + (s.x ? "X" : "") + '</td>');
        if (s.gradercid && peteramati_grader_map[s.gradercid])
            a.push('<td>' + escape_entities(peteramati_grader_map[s.gradercid]) + '</td>');
        else
            a.push(s.gradercid ? '<td>???</td>' : '<td></td>');
        if (!pconf.gitless_grades) {
            txt = '';
            if (s.has_notes)
                txt += '♪';
            if (s.has_nongrader_notes)
                txt += '<sup>*</sup>';
            a.push('<td>' + txt + '</td>');
        }
        if (pconf.need_total)
            a.push('<td class="r s61total">' + s.total + '</td>');
        for (j = 0; j < grades.length; ++j) {
            klass = "r";
            if (grades[j] == pconf.total_key && s.grades[j] != null && s.grades[j] != "")
                klass += " s61total";
            if (s.highlight_grades && s.highlight_grades[grades[j]])
                klass += " s61highlight";
            a.push('<td class="' + klass + '">' + (s.grades[j] == null ? '' : s.grades[j]) + '</td>');
        }
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
            a.push('<td>' + txt + '</td>');
        }
        return a.join('');
    }
    function set_hotlist($b, uids) {
        var j = {"ids": uids.join(" "), "sort": sort.f};
        $b.attr("data-hotlist", JSON.stringify(j));
    }
    function render_body() {
        var $b = $j.find("tbody");
        $b.html("");
        var i, s, a, trn = 0, was_boring = false, uids = [];
        displaying_last_first = sort.f === "name" && sort.last;
        for (i = 0; i < data.length; ++i) {
            s = data[i];
            dmap[s.username] = s;
            uids.push(s.uid);
            a = [];
            ++trn;
            if (s.boring && !was_boring && trn != 1)
                a.push('<tr class="s61boring"><td colspan="' + calculate_ncol() + '"><hr /></td></tr>');
            was_boring = s.boring;
            a.push('<tr class="k' + (trn % 2) + '" data-pa-student="' + escape_entities(s.username) + '">' + render_tds(s, trn) + '</tr>');
            for (var j = 0; s.partners && j < s.partners.length; ++j) {
                a.push('<tr class="k' + (trn % 2) + ' s61partner" data-pa-student="' + escape_entities(s.partners[j].username) + '" data-pa-partner="1">' + render_tds(s.partners[j], "") + '</tr>');
                dmap[s.partners[j].username] = s.partners[j];
            }
            $b.append(a.join(''));
        }
        set_hotlist($b, uids);
    }
    function resort() {
        var $b = $j.find("tbody"), tb = $b[0];
        var rmap = {}, last = null, tr = tb.firstChild;
        while (tr) {
            if (tr.hasAttribute("data-pa-partner"))
                last.push(tr);
            else
                rmap[tr.getAttribute("data-pa-student")] = last = [tr];
            tr = tr.nextSibling;
        }
        var i, j, trn = 0, was_boring = false, uids = [];
        last = tb.firstChild;
        for (i = 0; i < data.length; ++i) {
            ++trn;
            uids.push(data[i].uid);
            while ((j = last) && j.className === "s61boring") {
                last = last.nextSibling;
                tb.removeChild(j);
            }
            if (data[i].boring && !was_boring && trn != 1)
                tb.insertBefore($('<tr class="s61boring"><td colspan="' + calculate_ncol() + '"><hr /></td></tr>')[0], last);
            was_boring = data[i].boring;
            tr = rmap[data[i].username];
            for (j = 0; j < tr.length; ++j) {
                if (last != tr[j])
                    tb.insertBefore(tr[j], last);
                else
                    last = last.nextSibling;
                tr[j].className = "k" + (trn % 2) + " " + tr[j].className.replace(/\bk[01]\s*/, "");
            }
            $(tr[0]).find(".s61rownumber").html(trn + ".");
        }
        var display_last_first = sort.f && sort.last;
        if (display_last_first !== displaying_last_first) {
            displaying_last_first = display_last_first;
            $b.find(".s61name").text(function () {
                var student = this.parentNode.getAttribute("data-pa-student");
                return render_name(dmap[student], displaying_last_first);
            });
        }
        set_hotlist($b, uids);
    }
    function switch_anon() {
        anonymous = !anonymous;
        username_key = anonymous ? "anon_username" : "username";
        $j.find("tbody td.s61username").each(function () {
            var s = dmap[this.parentNode.getAttribute("data-pa-student")];
            $(this).html(render_username_td(s));
        });
        sort_data();
        resort();
        return false;
    }
    function render_head() {
        var a = [], j, grades = pconf.grade_keys || [], t;
        if (pconf.checkbox)
            a.push('<th></th>');
        a.push('<th></th>');
        t = pconf.anonymous ? ' <a href="#" class="uu" style="font-weight:normal">[anon]</a>' : '';
        a.push('<th class="l s61username plsortable" data-pa-sort="username">Username' + t + '</th>');
        a.push('<th class="l s61nonanonymous plsortable" data-pa-sort="name">Name</th>');
        a.push('<th class="l s61extension plsortable" data-pa-sort="extension">X?</th>');
        a.push('<th class="l plsortable" data-pa-sort="grader">Grader</th>');
        if (!pconf.gitless_grades)
            a.push('<th></th>');
        if (pconf.need_total)
            a.push('<th class="r plsortable" data-pa-sort="total">Tot</th>');
        for (j = 0; j < grades.length; ++j)
            a.push('<th class="r plsortable" data-pa-sort="grade' + j + '">' + grades[j].substr(0, 3) + '</th>');
        if (!pconf.gitless)
            a.push('<th></th>');
        $j.find("thead").html('<tr>' + a.join('') + '</tr>');
        $j.find("thead .s61username a").click(switch_anon);
        $j.find("th[data-pa-sort='" + sort.f + "']").addClass("plsortactive").
            toggleClass("plsortreverse", sort.rev < 0);
    }
    function user_compar(a, b) {
        var au = a[username_key].toLowerCase(), bu = b[username_key].toLowerCase();
        if (au < bu)
            return -sort.rev;
        else if (au > bu)
            return sort.rev;
        else
            return 0;
    }
    function sort_data() {
        var f = sort.f, rev = sort.rev;
        set_name_sorters();
        if (f == "name")
            data.sort(function (a, b) {
                if (a.boring != b.boring)
                    return a.boring ? 1 : -1;
                else if (a._sort_name != b._sort_name)
                    return a._sort_name < b._sort_name ? -rev : rev;
                else
                    return user_compar(a, b);
            });
        else if (f == "extension")
            data.sort(function (a, b) {
                if (a.boring != b.boring)
                    return a.boring ? 1 : -1;
                else if (a.x != b.x)
                    return a.x ? rev : -rev;
                else
                    return user_compar(a, b);
            });
        else if (f == "grader")
            data.sort(function (a, b) {
                if (a.boring != b.boring)
                    return a.boring ? 1 : -1;
                else {
                    var ag = (a.gradercid && peteramati_grader_map[a.gradercid]) || "~~~";
                    var bg = (b.gradercid && peteramati_grader_map[b.gradercid]) || "~~~";
                    if (ag != bg)
                        return ag < bg ? -rev : rev;
                    else
                        return user_compar(a, b);
                }
            });
        else if (f == "total")
            data.sort(function (a, b) {
                if (a.boring != b.boring)
                    return a.boring ? 1 : -1;
                else if (a.total != b.total)
                    return a.total < b.total ? -rev : rev;
                else
                    return -user_compar(a, b);
            });
        else if ((m = /^grade(\d+)$/.exec(f)))
            data.sort(function (a, b) {
                if (a.boring != b.boring)
                    return a.boring ? 1 : -1;
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
                        return -user_compar(a, b);
                }
            });
        else /* "username" */
            data.sort(function (a, b) {
                if (a.boring != b.boring)
                    return a.boring ? 1 : -1;
                else
                    return user_compar(a, b);
            });
    }
    function head_click(event) {
        if (!this.hasAttribute("data-pa-sort"))
            return;
        var sf = this.getAttribute("data-pa-sort");
        if (sf != sort.f) {
            sort.f = sf;
            if (sf == "username" || sf == "name" || sf == "grader" || sf == "extension")
                sort.rev = 1;
            else
                sort.rev = -1;
        } else if (sf === "name") {
            sort.last = !sort.last;
            if (sort.last)
                sort.rev = -sort.rev;
        } else
            sort.rev = -sort.rev;
        sort_data();
        resort();
        $j.find(".plsortable").removeClass("plsortactive plsortreverse");
        $(this).addClass("plsortactive" + (sort.rev < 0 ? " plsortreverse" : ""));
        wstorage(true, "pa-pset" + psetid + "-table", JSON.stringify(sort));
    }

    $j.html("<thead></thead><tbody class='has-hotlist'></tbody>");
    $j.find("thead").on("click", "th", head_click);
    default_sorting();
    render_head();
    sort_data();
    render_body();
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

    $self.on("change keyup keydown", update).data("autogrowing", update);
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
