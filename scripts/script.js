// script.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

var siteurl, siteurl_base_path,
    siteurl_postvalue, siteurl_suffix, siteurl_defaults,
    siteurl_absolute_base, siteurl_cookie_params, assetsurl,
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

if (!window.JSON || !window.JSON.parse)
    window.JSON = {parse: $.parseJSON};

var hasClass, addClass, removeClass, toggleClass, classList;
if ("classList" in document.createElement("span")
    && !/MSIE|rv:11\.0/.test(navigator.userAgent || "")) {
    hasClass = function (e, k) {
        var l = e.classList;
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
        var k = $.trim(e.className);
        return k === "" ? [] : k.split(/\s+/);
    };
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
function HPromise(executor) {
    this.state = -1;
    this.c = [];
    if (executor) {
        try {
            executor(this._resolver(1), this._resolver(0));
        } catch (e) {
            this._resolver(0)(e);
        }
    }
}
HPromise.prototype._resolver = function (state) {
    var self = this;
    return function (value) {
        if (self.state === -1) {
            self.state = state;
            self.value = value;
            self._resolve();
        }
    };
};
HPromise.prototype.then = function (yes, no) {
    var next = new HPromise;
    this.c.push([no, yes, next]);
    if (this.state === 0 || this.state === 1)
        this._resolve();
    return next;
};
HPromise.prototype._resolve = function () {
    var i, x, ss = this.state, s, v, f;
    this.state = 2;
    for (i in this.c) {
        x = this.c[i];
        s = ss;
        v = this.value;
        f = x[s];
        if ($.isFunction(f)) {
            try {
                v = f(v);
            } catch (e) {
                s = 0;
                v = e;
            }
        }
        x[2]._resolver(s)(v);
    }
    this.c = [];
    this.state = ss;
};
HPromise.resolve = function (value) {
    var p = new HPromise;
    p.value = value;
    p.state = 1;
    return p;
};
HPromise.reject = function (reason) {
    var p = new HPromise;
    p.value = reason;
    p.state = 0;
    return p;
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
    if (errormsg.lineno == null || errormsg.lineno > 1)
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
        if (hotcrp_user && hotcrp_user.email)
            msg += "user " + hotcrp_user.email + ", ";
        msg += jqxhr.status;
        if (httperror)
            msg += ", " + httperror;
        if (jqxhr.responseText)
            msg += ", " + jqxhr.responseText.substr(0, 100);
        log_jserror(msg);
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
var escape_entities = (function () {
    var re = /[&<>\"']/g;
    var rep = {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#39;"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return s.replace(re, function (match) { return rep[match]; });
    };
})();

var unescape_entities = (function () {
    var re = /&.*?;/g, rep = {"&amp;": "&", "&lt;": "<", "&gt;": ">", "&quot;": "\"", "&apos;": "'", "&#039;": "'"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return s.replace(re, function (match) { return rep[match]; });
    };
})();

var urlencode = (function () {
    var re = /%20|[!~*'()]/g;
    var rep = {"%20": "+", "!": "%21", "~": "%7E", "*": "%2A", "'": "%27", "(": "%28", ")": "%29"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return encodeURIComponent(s).replace(re, function (match) { return rep[match]; });
    };
})();

var urldecode = function (s) {
    if (s === null || typeof s === "number")
        return s;
    return decodeURIComponent(s.replace(/\+/g, "%20"));
};

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
    if ($.isArray(n))
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
    var words = fmt.split(/(%(?:%|-?(?:\d*|\*?)(?:\.\d*)?[sdefgoxX]))/), wordno, word,
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

function now_msec() {
    return (new Date).getTime();
}

function now_sec() {
    return now_msec() / 1000;
}

var strftime = (function () {
    function pad(num, str, n) {
        str += num.toString();
        return str.length <= n ? str : str.substr(str.length - n);
    }
    function unparse_q(d, alt, is24) {
        if (is24 && alt && d.getSeconds())
            return strftime("%H:%M:%S", d);
        else if (is24)
            return strftime("%H:%M", d);
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
    return !nonprintable_map[event_key(evt)]
        && (typeof evt === "string" || !(evt.ctrlKey || evt.metaKey));
};
event_key.modifier = function (evt) {
    return nonprintable_map[event_key(evt)] > 1;
};
event_key.is_default_a = function (evt, a) {
    return !evt.metaKey && !evt.ctrlKey && evt.which != 2
        && (!a || !/(?:^|\s)(?:ui|btn)(?=\s|$)/i.test(a.className || ""));
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
    if (siteurl_base_path !== "/")
        key = siteurl_base_path + key;
    return wstorage(is_session, key, value);
};
wstorage.site_json = function (is_session, key) {
    if (siteurl_base_path !== "/")
        key = siteurl_base_path + key;
    return wstorage.json(is_session, key);
};


// hoturl
function hoturl_add(url, component) {
    var hash = url.indexOf("#");
    if (hash >= 0) {
        component += url.substring(hash);
        url = url.substring(0, hash);
    }
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
        while (url.substring(0, 3) === "../") {
            x = x.replace(/\/[^\/]*\/$/, "/");
            url = url.substring(3);
        }
    }
    return x + url;
}

function hoturl_absolute_base() {
    if (!siteurl_absolute_base)
        siteurl_absolute_base = url_absolute(siteurl_base_path);
    return siteurl_absolute_base;
}


// ui
var handle_ui = (function ($) {
var callbacks = {};
function handle_ui(event) {
    var e = event.target;
    if ((e && hasClass(e, "ui"))
        || (this.tagName === "A" && hasClass(this, "ui"))) {
        event.preventDefault();
    }
    var k = classList(this);
    for (var i = 0; i < k.length; ++i) {
        var c = callbacks[k[i]];
        if (c) {
            for (var j = 0; j < c.length; ++j) {
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
    var c = callbacks[className];
    if (c) {
        if (typeof event === "string")
            event = $.Event(event); // XXX IE8: `new Event` is not supported
        for (var j = 0; j < c.length; ++j) {
            c[j].call(this, event);
        }
    }
};
return handle_ui;
})($);
$(document).on("click", ".ui, .uix", handle_ui);
$(document).on("change", ".uich", handle_ui);
$(document).on("keydown", ".uikd", handle_ui);
$(document).on("input", ".uii", handle_ui);
$(document).on("unfold", ".ui-unfold", handle_ui);
$(document).on("mouseup mousedown", ".uim", handle_ui);
$(document).on("submit", ".ui-submit", handle_ui);


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
        bubch[2].style[cssbc(dir^2)] = yc;
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
                if (dirspec == null && epos[0])
                    dirspec = epos[0].getAttribute("data-tooltip-dir");
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
        content_node: function () {
            return bubch[1].firstChild;
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

function prepare_info(elt, info) {
    var xinfo = elt.getAttribute("data-tooltip-info");
    if (xinfo) {
        if (typeof xinfo === "string" && xinfo.charAt(0) === "{")
            xinfo = JSON.parse(xinfo);
        else if (typeof xinfo === "string")
            xinfo = {builder: xinfo};
        info = $.extend(xinfo, info);
    }
    if (info.builder && builders[info.builder])
        info = builders[info.builder].call(elt, info) || info;
    if (info.dir == null || elt.hasAttribute("data-tooltip-dir"))
        info.dir = elt.getAttribute("data-tooltip-dir") || "v";
    if (info.type == null || elt.hasAttribute("data-tooltip-type"))
        info.type = elt.getAttribute("data-tooltip-type");
    if (info.className == null || elt.hasAttribute("data-tooltip-class"))
        info.className = elt.getAttribute("data-tooltip-class") || "dark";
    if (elt.hasAttribute("data-tooltip"))
        info.content = elt.getAttribute("data-tooltip");
    else if (info.content == null && elt.hasAttribute("aria-label"))
        info.content = elt.getAttribute("aria-label");
    else if (info.content == null && elt.hasAttribute("title"))
        info.content = elt.getAttribute("title");
    return info;
}

function show_tooltip(info) {
    if (window.disable_tooltip)
        return null;

    var $self = $(this);
    info = prepare_info($self[0], $.extend({}, info || {}));
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
    if ($self[0].getAttribute("data-tooltip-type") === "focus")
        $self.on("focus", ttenter).on("blur", ttleave);
    else
        $self.hover(ttenter, ttleave);
}
tooltip.erase = function () {
    var tt = this === tooltip ? window.global_tooltip : $(this).data("tooltipState");
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
    event && event.stopPropagation();
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
        make_bubble(rv.error, "errorbubble").near(elt).removeOn(elt, "input change click hide");
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
render_text.add_format({
    format: 2,
    render: function (text) {
        return pa_render_terminal(text);
    }
})
})(window.markdownit());

$(render_text.on_page);


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
var cookie_set_at;
function set_cookie(info) {
    if (info) {
        cookie_set_at = (new Date).getTime();
        var p = ";max-age=20", m;
        if (siteurl && (m = /^[a-z]+:\/\/[^\/]*(\/.*)/.exec(hoturl_absolute_base())))
            p += "; path=" + m[1];
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
            document.cookie = "hotlist-info-" + cookie_set_at + (suffix ? "_" + suffix : "") + "=" + info.substring(pos, epos) + p;
            pos = epos;
            ++suffix;
        }
        document.cookie = "hotlist-info-" + cookie_set_at + (suffix ? "_" + suffix : "") + "=" + info.substring(pos) + p;
    }
}
function is_listable(href) {
    return /(?:^|\/)pset(?:|\.php)(?:$|\/)/.test(href.substring(siteurl.length));
}
function add_list() {
    var href = this.getAttribute(this.tagName === "FORM" ? "action" : "href");
    if (href
        && href.substring(0, siteurl.length) === siteurl
        && (this.tagName === "FORM" || is_listable(href))) {
        var $hl = $(this).closest(".has-hotlist");
        if ($hl.length === 0 && this.tagName === "FORM")
            $hl = $(this).find(".has-hotlist");
        if ($hl.length === 1)
            set_cookie($hl[0].getAttribute("data-hotlist"));
    }
}
function unload_list() {
    if (hotcrp_list && (!cookie_set_at || cookie_set_at + 10 < (new Date).getTime()))
        set_cookie(hotcrp_list);
}
function prepare() {
    $(document).on("click", "a", add_list);
    $(document).on("submit", "form", add_list);
hotcrp_list && $(window).on("beforeunload", unload_list);
}
prepare();
})(jQuery);


// mail
function setmailpsel(sel) {
    fold("psel", !!sel.value.match(/^(?:pc$|pc:|all$)/), 9);
    fold("psel", !sel.value.match(/^new.*rev$/), 10);
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
    while (tr
           && (tr.nodeType !== Node.ELEMENT_NODE
               || hasClass(tr, "pa-gw")
               || hasClass(tr, "pa-gg")))
        tr = tr[direction];

    var table = tr, file;
    while (table && !(file = table.getAttribute("data-pa-file")))
        table = table.parentNode;
    if (!tr || !table || !/\bpa-dl\b.*\bpa-g[idc]\b/.test(tr.className))
        return null;

    var aline = +tr.firstChild.getAttribute("data-landmark");
    var bline = +tr.firstChild.nextSibling.getAttribute("data-landmark");
    var result = {
        ufile: file, file: file, aline: aline, bline: bline,
        lineid: bline ? "b" + bline : "a" + aline,
        tr: tr
    };
    var user = table.getAttribute("data-pa-file-user");
    if (user)
        result.ufile = user + "-" + result.file;

    var next_tr = tr.nextSibling;
    while (next_tr && (next_tr.nodeType !== Node.ELEMENT_NODE || hasClass(next_tr, "pa-gg")))
        next_tr = next_tr.nextSibling;
    if (next_tr && hasClass(next_tr, "pa-gw"))
        result.notetr = next_tr;

    return result;
}

function pa_note(elt) {
    var note = elt.getAttribute("data-pa-note");
    if (typeof note === "string" && note !== "")
        note = JSON.parse(note);
    if (typeof note === "number")
        note = [false, "", 0, note];
    return note || [false, ""];
}

function pa_set_note(elt, note) {
    if (note === false || note === null)
        elt.removeAttribute("data-pa-note");
    else if (note !== undefined)
        elt.setAttribute("data-pa-note", JSON.stringify(note));
}

function pa_save_note(elt, text) {
    if (!hasClass(elt, "pa-gw"))
        throw new Error("!");
    var note = pa_note(elt);
    var tr = elt.previousSibling;
    while (tr && !/\bpa-dl\b.*\bpa-g[idc]\b/.test(tr.className))
        tr = tr.previousSibling;
    var $pf = $(elt).closest(".pa-filediff");
    var file = $pf[0].getAttribute("data-pa-file"), lineid;
    if (hasClass(tr, "pa-gd"))
        lineid = "a" + tr.firstChild.getAttribute("data-landmark");
    else
        lineid = "b" + tr.firstChild.nextSibling.getAttribute("data-landmark");
    var $pi = $pf.closest(".pa-psetinfo");
    var format = note ? note[4] : null;
    if (format == null)
        format = $pf.attr("data-default-format");
    if (typeof text === "function")
        text = text(note ? note[1] : "", note);
    return new Promise(function (resolve, reject) {
        $.ajax(hoturl_post("api/linenote", hoturl_gradeparts($pi, {
            file: file, line: lineid, oldversion: (note && note[3]) || 0, format: format
        })), {
            data: {note: text},
            method: "POST", cache: false, dataType: "json",
            success: function (data) {
                if (data && data.ok) {
                    note = data.linenotes[file];
                    note = note && note[lineid];
                    pa_set_note(elt, note);
                    pa_render_note.call(elt, note);
                    resolve(elt);
                } else
                    reject(elt);
            }
        });
    });
}

function pa_fix_note_links() {
    function note_skippable(tr) {
        return pa_note(tr)[1] === "";
    }

    function note_anchor(tr) {
        var anal = pa_diff_locate(tr), td;
        if (anal && (td = pa_ensureline(anal.ufile, anal.lineid)))
            return "#" + td.id;
        else
            return "";
    }

    function set_link(tr, next_tr) {
        var $a = $(tr).find(".pa-note-links a");
        if (!$a.length) {
            $a = $('<a class="uix pa-goto"></a>');
            $('<div class="pa-note-links"></div>').append($a).prependTo($(tr).find(".pa-notediv"));
        }

        $a.attr("href", note_anchor(next_tr));
        var t = next_tr ? "NEXT >" : "TOP";
        if ($a.text() !== t)
            $a.text(t);
    }

    var notes = $(".pa-gw");
    var notepos = 0;
    while (notepos < notes.length && notes[notepos] !== this)
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
        set_link(notes[prevpos], note_skippable(this) ? notes[nextpos] : this);
    set_link(this, notes[nextpos]);
}

function pa_render_note(note, transition) {
    var tr = this, $tr = $(this);
    if (!hasClass(tr, "pa-gw")) {
        while (tr.tagName === "TD")
            tr = tr.parentElement;
        var ntr = tr.nextSibling;
        while (ntr && (ntr.nodeType !== Node.ELEMENT_NODE || hasClass(ntr, "pa-gg"))) {
            tr = ntr;
            ntr = ntr.nextSibling;
        }
        $tr = $('<tr class="pa-dl pa-gw"><td colspan="2" class="pa-note-edge"></td><td class="pa-notebox"></td></tr>').insertAfter(tr);
        tr = $tr[0];
    }
    if (arguments.length == 0)
        return tr;
    pa_set_note(tr, note);
    var $td = $tr.find(".pa-notebox"), $content = $td.children();
    if (transition)
        $content.slideUp(80).queue(function () { $content.remove(); });
    else
        $content.remove();
    if (note[1] === "") {
        pa_fix_note_links.call(tr);
        transition ? $tr.children().slideUp(80) : $tr.children().hide();
        return tr;
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

    pa_fix_note_links.call(tr);

    if (transition)
        $td.find(".pa-notediv").hide().slideDown(80);
    else
        removeClass(tr, "hidden");
    return tr;
}


// pa_linenote
(function ($) {
var labelctr = 0, curanal, down_event, scrolled_at;

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
        var elt = pa_ensureline(curanal.ufile, curanal.lineid);
        return $(elt).closest("tr")[0];
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
    var note = pa_note($tr[0]);
    if (!$tr.length
        || (!always
            && !text_eq(note[1], $tr.find("textarea").val().replace(/\s+$/, ""))))
        return false;
    $tr.removeClass("editing");
    $tr.find(":focus").blur();
    pa_render_note.call($tr[0], note, true);

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
                    pa_set_note($tr[0], note);
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

function nearby(dx, dy) {
    return (dx * dx) + (dy * dy) < 144;
}

function pa_linenote(event) {
    if (event.button !== 0)
        return;
    var anal = pa_diff_locate(event.target), t = now_msec();
    if (event.type === "mousedown" && anal) {
        if (curanal
            && curanal.tr === anal.tr
            && down_event
            && nearby(down_event[0] - event.clientX, down_event[1] - event.clientY)
            && t - down_event[2] <= 500)
            /* skip */;
        else {
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
    if (curanal.notetr)
        $tr = $(curanal.notetr);
    else
        $tr = $(pa_render_note.call(curanal.tr));
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
    var panal = pa_diff_locate(contextrow, "previousSibling");
    while (panal && !panal.bline)
        panal = pa_diff_locate(panal.tr, "previousSibling");
    var nanal = pa_diff_locate(contextrow, "nextSibling");
    if (!panal && !nanal)
        return false;
    var paline = panal ? panal.aline + 1 : 1;
    var pbline = panal ? panal.bline + 1 : 1;
    var lbline = nanal ? nanal.bline : 0;
    if (nanal && nanal.aline <= 1)
        return false;
    var args = {file: (panal || nanal).file, fromline: pbline};
    if (lbline)
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

handle_ui.on("pa-gx", expand);

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

function pa_makegrade(gi, k, editable) {
    var ge = gi.entries[k],
        g = gi.grades ? gi.grades[ge.pos] : null,
        t,
        name = escape_entities(k),
        title = ge.title ? escape_entities(ge.title) : name;
    if (editable) {
        var id = "pa-ge" + ++pa_makegrade.id_counter;
        t = '<form class="ui-submit pa-grade pa-p pa-gradevalue-form" data-pa-grade="' +
            name + '"><label class="pa-pt" for="' + id + '">' + title + '</label>';
        if (ge.type === "text") {
            t += '<div class="pa-pd"><textarea class="uich pa-pd pa-gradevalue" name="' + name +
                '" id="' + id + '"></textarea></div>';
        } else if (ge.type === "checkbox"
                   && (g == null || g === 0 || g === ge.max)) {
            t += '<div class="pa-gradeentry pa-pd"><span class="pa-gradewidth">' +
                '<input type="checkbox" class="ui pa-gradevalue" name="' + name +
                '" id="' + id + '" value="' + ge.max + '" /></span>' +
                ' <span class="pa-grademax">of ' + ge.max +
                ' <a href="" class="x ui pa-grade-uncheckbox">#</a></span></div>';
        } else {
            t += '<div class="pa-gradeentry pa-pd"><span class="pa-gradewidth">' +
                '<input type="text" class="uich pa-gradevalue" name="' + name +
                '" id="' + id + '" /></span>';
            if (ge.type === "letter")
                t += ' <span class="pa-grademax">letter grade</span>';
            else if (ge.max)
                t += ' <span class="pa-grademax">of ' + ge.max + '</span>';
            t += '</div>';
        }
        t += '</form>';
    } else {
        t = '<div class="pa-grade pa-p" data-pa-grade="' + name + '">' +
            '<div class="pa-pt">' + title + '</div>';
        if (ge.type === "text")
            t += '<div class="pa-pd pa-gradevalue"></div>';
        else {
            t += '<div class="pa-pd"><span class="pa-gradevalue pa-gradewidth"></span>';
            if (ge.max && ge.type !== "letter")
                t += ' <span class="pa-grademax">of ' + ge.max + '</span>';
            t += '</div>';
        }
        t += '</div>';
    }
    return t;
}
pa_makegrade.id_counter = 0;

function pa_grade_uncheckbox() {
    this.type = "text";
    this.className = "uich pa-gradevalue";
    $(this.parentElement.parentElement).find(".pa-grade-uncheckbox").remove();
}
handle_ui.on("pa-grade-uncheckbox", function () {
    $(this).closest(".pa-gradeentry").find(".pa-gradevalue").each(pa_grade_uncheckbox);
});

var pa_grade_types = {
    numeric: {
        text: function (v) {
            return v + "";
        }
    },
    text: {
        text: function (v) {
            return v;
        },
        justify: "left"
    },
    checkbox: {
        text: function (v) {
            if (v == null || v === 0)
                return "";
            else if (v == this.max)
                return "✓";
            else
                return v + "";
        },
        justify: "center"
    },
    letter: (function () {
        var lm = {
            98: "A+", 95: "A", 92: "A-", 88: "B+", 85: "B", 82: "B-",
            78: "C+", 75: "C", 72: "C-", 68: "D+", 65: "D", 62: "D-", 50: "F"
        };
        return {
            text: function (v) {
                if (lm[v])
                    return lm[v];
                else
                    return v + "";
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
        };
    })()
};

function pa_setgrade(gi, editable) {
    var k = this.getAttribute("data-pa-grade"),
        ge = gi.entries[k];
    if (!ge)
        return;

    var g = gi.grades ? gi.grades[ge.pos] : null,
        ag = gi.autogrades ? gi.autogrades[ge.pos] : null,
        $g = $(this);

    // “grade is above max” message
    if (ge.max && editable) {
        if (!g || g <= ge.max)
            $g.find(".pa-gradeabovemax").remove();
        else if (!$g.find(".pa-gradeabovemax").length)
            $g.find(".pa-pd").append('<div class="pa-gradeabovemax">Grade is above max</div>');
    }

    // “autograde differs” message
    if (ag != null && editable) {
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
    var $v = $g.find(".pa-gradevalue");
    var gt = "";
    if (g != null)
        gt = pa_grade_types[ge.type || "numeric"].text.call(ge, g);
    if (!editable) {
        if ($v.text() !== gt)
            $v.text(gt);
        toggleClass(this, "hidden", gt === "" && !ge.max);
    } else {
        if ($v[0].type === "checkbox"
            && g != null
            && g !== 0
            && g !== ge.max)
            pa_grade_uncheckbox.call($v[0]);
        if ($v[0].type === "checkbox") {
            $v.prop("checked", !!g);
        } else if ($v.val() !== gt && !$v.is(":focus")) {
            $v.val(gt);
        }
    }

    // maybe add landmark reference
    if (ge.landmark
        && this.parentElement
        && hasClass(this.parentElement, "pa-gradelist")) {
        var m = /^(.*):(\d+)$/.exec(ge.landmark);
        var $line = $(pa_ensureline(m[1], "a" + m[2]));
        var want_gbr = "";
        if ($line.length) {
            var $pi = $(this).closest(".pa-psetinfo"),
                directory = $pi[0].getAttribute("data-pa-directory") || "";
            if (directory && m[1].substr(0, directory.length) === directory)
                m[1] = m[1].substr(directory.length);
            want_gbr = '@<a href="#' + $line[0].id + '" class="uix pa-goto">' + escape_entities(m[1] + ":" + m[2]) + '</a>';
        }
        var $pgbr = $g.find(".pa-gradeboxref");
        if (!$line.length)
            $pgbr.remove();
        else if (!$pgbr.length || $pgbr.html() !== want_gbr) {
            $pgbr.remove();
            $g.find(".pa-gradeentry").append('<span class="pa-gradeboxref">' + want_gbr + '</span>');
        }
    }
}

handle_ui.on("pa-gradevalue", function () {
    $(this).closest("form").submit();
});

handle_ui.on("pa-gradevalue-form", function (event) {
    event.preventDefault();
    if (this.getAttribute("data-outstanding"))
        return;

    var self = this, $f = $(self);
    self.setAttribute("data-outstanding", "1");
    $f.find(".pa-gradediffers, .pa-save-message").remove();
    $f.find(".pa-gradeentry").append('<span class="pa-save-message">Saving…</span>');

    var gi = $f.closest(".pa-psetinfo").data("pa-gradeinfo");
    if (typeof gi === "string")
        gi = JSON.parse(gi);

    var g = {}, og = {};
    $f.find("input.pa-gradevalue, textarea.pa-gradevalue").each(function () {
        var ge = gi.entries[this.name];
        if (gi.grades && ge && gi.grades[ge.pos] != null)
            og[this.name] = gi.grades[ge.pos];
        else if (this.name === "late_hours" && gi.late_hours != null)
            og[this.name] = gi.late_hours;
        if ((this.type !== "checkbox" && this.type !== "radio")
            || this.checked)
            g[this.name] = this.value;
        else if (this.type === "checkbox")
            g[this.name] = 0;
    });

    $.ajax(hoturl_post("api/grade", hoturl_gradeparts($f)), {
        type: "POST", cache: false, data: {grades: g, oldgrades: og},
        success: function (data) {
            self.removeAttribute("data-outstanding");
            if (data.ok)
                $f.find(".pa-save-message").html("Saved");
            else
                $f.find(".pa-save-message").html('<strong class="err">' + data.error + '</strong>');
            pa_loadgrades.call(self, data);
        }
    });
});

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
        if (gi.entries[k]) {
            $(this).html(pa_makegrade(gi, k, editable)).removeClass("pa-need-grade");
            if (this.hasAttribute("data-pa-landmark-range"))
                $(this).find(".pa-gradeentry").append('<button type="button" class="btn ui pa-compute-grade">Grade from notes</button>');
            if (this.hasAttribute("data-pa-landmark-buttons")) {
                var lb = JSON.parse(this.getAttribute("data-pa-landmark-buttons"));
                for (var i = 0; i < lb.length; ++i) {
                    if (typeof lb[i] === "string")
                        $(this).find(".pa-gradeentry").append(lb[i]);
                    else {
                        var t = '<button type="button" class="btn ui';
                        if (lb[i].className)
                            t += ' ' + lb[i].className;
                        t += '">' + lb[i].title + '</button>';
                        $(this).find(".pa-gradeentry").append(t);
                    }
                }
            }
        }
    });

    $pi.find(".pa-gradelist").each(function () {
        var ch = this.firstChild;
        while (ch && !hasClass(ch, "pa-grade"))
            ch = ch.nextSibling;
        for (var i = 0; i < gi.order.length; ++i) {
            var k = gi.order[i];
            if (k) {
                if (ch && ch.getAttribute("data-pa-grade") === k)
                    ch = ch.nextSibling;
                else
                    this.insertBefore($(pa_makegrade(gi, k, editable))[0], ch);
            }
        }
        while (ch) {
            var e = ch;
            ch = ch.nextSibling;
            this.removeChild(e);
        }
    });

    $pi.find(".pa-grade").each(function () {
        pa_setgrade.call(this, gi, editable);
    });

    var $pge = $pi.find(".pa-grade");

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
        var t = '<div class="pa-total pa-p';
        var ne = 0;
        for (var k in gi.entries)
            if (gi.entries[k].type !== "text")
                ++ne;
        if (ne <= 1)
            t += ' hidden';
        $g = $(t + '"><div class="pa-pt">total</div>' +
            '<div class="pa-pd"><span class="pa-gradevalue pa-gradewidth"></span> ' +
            '<span class="pa-grademax">of ' + tm[1] + '</span></div></div>');
        $g.prependTo($pi.find(".pa-gradelist"));
    }
    $v = $g.find(".pa-gradevalue");
    g = "" + tm[0];
    if ($v.text() !== g) {
        $v.text(g);
        pa_draw_gradecdf($pi.find(".pa-gradecdf"));
    }
}

function pa_process_landmark_range(func, selector) {
    var lr = $(this).closest(".pa-gradebox").attr("data-pa-landmark-range");
    var m = lr ? /^(\d+),(\d+)$/.exec(lr) : null;
    if (!m)
        return;
    var lnfirst = +m[1], lnlast = +m[2], lna = -1, lnb = -1;
    var tr = $(this).closest(".pa-filediff")[0].firstChild.firstChild;
    while (tr) {
        if (tr.nodeType === Node.ELEMENT_NODE) {
            if (!hasClass(tr, "pa-gw")) {
                var td = tr.firstChild;
                if (td.hasAttribute("data-landmark"))
                    lna = +td.getAttribute("data-landmark");
                td = td.nextSibling;
                if (td && td.hasAttribute("data-landmark"))
                    lnb = +td.getAttribute("data-landmark");
            }
            if (lna >= lnfirst
                && lna <= lnlast
                && (!selector || tr.matches(selector))) {
                func.call(this, tr, lna, lnb);
            }
        }
        tr = tr.nextSibling;
    }
}

handle_ui.on("pa-compute-grade", function () {
    var sum = 0, noteparts = [];
    pa_process_landmark_range.call(this, function (tr, lna, lnb) {
        var note = pa_note(tr), m;
        if (note[1]
            && ((m = /^\s*(\(?\+)(\d+(?:\.\d*)?|\.\d+)((?!\.\d|[\w%$*])\S*)/.exec(note[1]))
                || (m = /^\s*(\(?)(\d+(?:\.\d*)?|\.\d+)(\/[\d.]+(?!\.\d|[\w%$*\/])\S*)/.exec(note[1])))) {
            sum += parseFloat(m[2]);
            noteparts.push("@" + (lnb || lna) + ": " + escape_entities(m[1]) + "<b>" + escape_entities(m[2]) + "</b>" + escape_entities(m[3]));
        }
    }, ".pa-gw");
    if (noteparts.length) {
        var $ge = $(this).closest(".pa-gradeentry"),
            $gv = $ge.find(".pa-gradevalue"),
            $gi = $ge.find(".pa-compute-grade-info");
        if (!$gi.length)
            $gi = $('<div class="pa-compute-grade-info"></div>').appendTo($ge);
        $gi.html("<strong>" + sum + "</strong> &nbsp; (" + noteparts.join(", ") + "; was " + escape_entities($gv.val() || "0") + ")");
        $gv.val(sum).change();
    }
});


function fold61(sel, arrowholder, direction) {
    var j = $(sel);
    if (direction != null)
        direction = !direction;
    j.toggleClass("hidden", direction);
    if (arrowholder)
        $(arrowholder).find("span.foldarrow").html(
            j.hasClass("hidden") ? "&#x25B6;" : "&#x25BC;"
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
    var therun = document.getElementById("pa-run-" + name), thebutton;
    if (therun.dataset.paTimestamp && !$(therun).is(":visible")) {
        thebutton = jQuery(".pa-runner[value='" + name + "']")[0];
        pa_run(thebutton, {unfold: true});
    } else
        fold61(therun, jQuery("#pa-runout-" + name));
    return false;
}

function pa_loadfilediff(filee, callback) {
    if (hasClass(filee, "need-load")) {
        var $p = $(filee).closest(".pa-psetinfo");
        $.ajax(hoturl("api/filediff", hoturl_gradeparts($(filee))), {
            type: "GET", cache: false, dataType: "json",
            data: {"file": html_id_decode(filee.id.substr(8)),
                "base_hash": $p[0].getAttribute("data-pa-base-hash"),
                "hash": $p[0].getAttribute("data-pa-hash")},
            success: function (data) {
                if (data.ok && data.table_html) {
                    var $h = $(data.table_html);
                    $(filee).html($h.html());
                }
                removeClass(filee, "need-load");
                callback();
            }
        });
    } else {
        callback();
    }
}


handle_ui.on("pa-unfold-file-diff", function () {
    var self = this, filee = this.parentElement.nextSibling;
    pa_loadfilediff(filee, function () {
        fold61(filee, self);
    });
    return false;
});


function pa_ensureline_callback(filename, lineid, callback) {
    // decode arguments: either (lineref) or (filename, lineid)
    if (lineid == null) {
        if (filename instanceof Node)
            filename = filename.hash;
        var m = filename.match(/^#?L([ab]\d+)_(.*)$/);
        if (!m)
            return $(null);
        filename = m[2];
        lineid = m[1];
    } else {
        if (filename instanceof Node) {
            var node = filename;
            while (node && !node.hasAttribute("data-pa-file"))
                node = node.parentElement;
            if (!node)
                return $(null);
            filename = node.getAttribute("data-pa-file");
            if (node.hasAttribute("data-pa-file-user"))
                filename = node.getAttribute("data-pa-file-user") + "-" + filename;
        }
        filename = html_id_encode(filename);
    }

    // check lineref
    var lineref = "L" + lineid + "_" + filename;
    var e = document.getElementById(lineref);
    if (e) {
        callback(e);
        return;
    }

    // create link
    var filee = document.getElementById("pa-file-" + filename);
    if (!filee) {
        callback(false);
        return;
    }

    function try_file() {
        var $tds = $(filee).find("td.pa-d" + lineid.charAt(0));
        var lineno = lineid.substr(1);
        // XXX expand
        for (var i = 0; i < $tds.length; ++i) {
            if ($tds[i].getAttribute("data-landmark") === lineno) {
                $tds[i].id = lineref;
                callback($tds[i]);
                return;
            }
        }
        callback(false);
    }

    pa_loadfilediff(filee, try_file);
}

function pa_ensureline(filename, lineid) {
    var e = null;
    pa_ensureline_callback(filename, lineid, function (ee) {
        ee && (e = ee);
    });
    return e;
}

handle_ui.on("pa-goto", function () {
    pa_ensureline_callback(this, null, function (ref) {
        if (ref) {
            $(".pa-line-highlight").removeClass("pa-line-highlight");
            $(ref).closest("table").removeClass("hidden");
            $e = $(ref).closest("tr");
            $e.addClass("pa-line-highlight");
        }
    });
});

function pa_beforeunload(evt) {
    var ok = true;
    $(".pa-gw textarea").each(function () {
        var $tr = $(this).closest("tr");
        var note = pa_note($tr[0]);
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
        $form.find("[name=flagreason]").on("keypress", make_onkey("Enter", function () { $b.click(); })).autogrow()[0].focus();
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
            var a = $("<a href=\"#" + anchor + "\" class=\"uu uix pa-goto\"></a>");
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
        thexterm.attachCustomKeyEventHandler(function(e) {
            write(e.key);
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
        if ($(button).attr("data-pa-loadgrade"))
            loadgrade61($(button));
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
                $("<button id=\"pa-runstop-" + category + "\" class=\"pa-runstop\" type=\"button\">Stop</button>")
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
    var $x = $("<div class=\"pa-p\" data-runsetting-num=\"" + num + "\"><div class=\"pa-pt\"></div><div class=\"pa-pd\"><input name=\"n" + num + "\" class=\"n\" size=\"30\" placeholder=\"Name\"> &nbsp; <input name=\"v" + num + "\" class=\"v\" size=\"40\" placeholder=\"Value\"></div></div>");
    if (name) {
        $x.find(".n").val(name);
        $x.find(".v").val(value);
    }
    $x.find("input").on("change", save);
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

return {add: add, load: load};
})(jQuery);


function pa_gradeinfo_total(gi, noextra) {
    if (typeof gi === "string")
        gi = JSON.parse(gi);
    var total = 0, maxtotal = 0;
    for (var i = 0; i < gi.order.length; ++i) {
        var k = gi.order[i];
        var ge = k ? gi.entries[k] : null;
        if (ge && ge.in_total && (!noextra || !ge.is_extra)) {
            total += (gi.grades && gi.grades[i]) || 0;
            if (!ge.is_extra)
                maxtotal += ge.max || 0;
        }
    }
    return [Math.round(total * 1000) / 1000,
            Math.round(maxtotal * 1000) / 1000];
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

function path_y_at_x(x) {
    var l = 0, r = this.getTotalLength(), m, pt;
    while (r - l > 0.5) {
        m = l + (r - l) / 2;
        pt = this.getPointAtLength(m);
        if (pt.x >= x + 0.25)
            r = m;
        else if (pt.x >= x - 0.25)
            return pt.y;
        else
            l = m;
    }
    pt = this.getPointAtLength(r === m ? l : r);
    if (pt.x >= x - 0.25 && pt.x <= x + 0.25)
        return pt.y;
    return null;
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

function PAIntervals() {
    this.is = [];
}
PAIntervals.prototype.lower = function (x) {
    var is = this.is, l = 0, r = is.length;
    while (l < r) {
        var m = l + ((r - l) >> 2) * 2;
        if (is[m] > x)
            r = m;
        else if (x > is[m + 1])
            l = m + 2;
        else /* is[m] <= x <= is[m + 1] */
            return m;
    }
    return l;
};
PAIntervals.prototype.contains = function (x) {
    var is = this.is, i = this.lower(x);
    return i < is.length && x >= is[i];
};
PAIntervals.prototype.overlaps = function (lo, hi) {
    var is = this.is, i = this.lower(lo);
    return i < is.length && hi >= is[i];
};
PAIntervals.prototype.add = function (lo, hi) {
    var is = this.is, i = this.lower(lo);
    if (i >= is.length || lo < is[i])
        is.splice(i, 0, lo, lo);
    var j = i;
    while (j + 2 < is.length && hi >= is[j + 2])
        j += 2;
    if (j !== i)
        is.splice(i + 1, j - i);
    is[i + 1] = Math.max(is[i + 1], hi);
};
PAIntervals.prototype.clear = function () {
    this.is = [];
};

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
    this.svg.appendChild(this.gg);
    this.gx.setAttribute("class", "pa-gg-axis pa-gg-xaxis");
    this.svg.appendChild(this.gx);
    this.gy.setAttribute("class", "pa-gg-axis pa-gg-yaxis");
    this.svg.appendChild(this.gy);
    this.maxp = 0;
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
            var labelcap = h / this.xdh;
            if (labelcap > 15)
                this.ylu = 1;
            else if (labelcap > 5)
                this.ylu = 2.5;
            else if (labelcap > 3)
                this.ylu = 5;
            else
                this.ylu = 10;
            this.ml = (this.yt ? 5 : 0) + 5 +
                (this.ylu == 10 ? 1.5 : (this.ylu == 2.5 ? 3.5 : 2.5)) * this.xdw;

            if (this.yltext !== false
                && this.yltext !== null
                && this.yltext !== "") {
                var text = this.yltext || "% of grades";
                this.yltext = mksvg("text");
                this.yltext.appendChild(document.createTextNode(text));
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
    if (d.entry && d.entry.type) {
        var gt = pa_grade_types[d.entry.type];
        if (gt && gt.tics)
            this.xtics = gt.tics.call(gt);
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
    var label_restrictions = new PAIntervals,
        tic_restrictions = new PAIntervals,
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
    while (y <= 10 && this.yl) {
        e = mksvg("text");
        e.appendChild(document.createTextNode(y * 10));
        e.setAttribute("x", -8);
        e.setAttribute("y", this.yax(y / 10) + 0.25 * this.xdh);
        this.gy.appendChild(e);

        d.push("M-5,", this.yax(y / 10), "h5");

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
        if (i !== 0) {
            x = Math.max(0, cdf[i] - Math.min(1, cdf[i] - cdf[i - 2]) / 2);
            data.push("H", this.xax(x));
        } else
            data.push("M", this.xax(Math.max(0, cdf[i] - 0.5)), ",", this.yax(cutoff));
        data.push("V", this.yax(cdf[i+1] * nr));
    }
    if (cdf.length)
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
    for (i = 0; i !== bins.length; ++i) {
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
                yv = path_y_at_x.call(this.last_curve, xv);
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
PAGradeGraph.prototype.default_annotation = function (klass) {
    var dot = mksvg("circle");
    dot.setAttribute("class", klass || "pa-gg-mark-grade");
    dot.setAttribute("r", 5);
    return dot;
};
PAGradeGraph.prototype.annotate_last_curve = function (x, elt) {
    if (this.last_curve) {
        var xv = this.xax(x), yv = path_y_at_x.call(this.last_curve, xv);
        if (yv === null && this.cutoff)
            yv = this.yax(this.cutoff);
        if (yv !== null) {
            elt = elt || this.default_annotation();
            elt.setAttribute("transform", "translate(" + xv + "," + yv + ")");
            this.gg.appendChild(elt);
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
    var uids = this.container().getAttribute("data-pa-highlight-users") || "";
    if (this.last_curve
        && this.last_curve_data.xcdf
        && uids !== (this.highlight_users || "")) {
        var uidm = {}, uidx = uids.split(/\s+/), x;
        for (var i = 0; i !== uidx.length; ++i) {
            if (uidx[i] !== "")
                uidm[uidx[i]] = 1;
        }
        this.remove_if(function () {
            if (hasClass(this, "pa-gg-highlight")) {
                var uid = +this.getAttribute("data-pa-uid");
                if (uidm[uid]) {
                    uidm[uid] = 2;
                } else {
                    return true;
                }
            }
        });
        for (var i = 0; i !== uidx.length; ++i) {
            if (uidm[uidx[i]] === 1
                && (x = this.user_x(uidx[i])) != null) {
                var e = this.default_annotation("pa-gg-mark-grade pa-gg-highlight");
                e.setAttribute("data-pa-uid", uidx[i]);
                this.annotate_last_curve(x, e);
            }
        }
    }
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
    if (d.extension && $pi.length && user_extension)
        plot_types.push("cdf-extension", "pdf-extension");
    plot_types.push("cdf", "pdf");
    if (d.extension && !$pi.length)
        plot_types.push("cdf-extension", "pdf-extension");
    if (d.noextra)
        plot_types.push("cdf-noextra", "pdf-noextra");
    plot_types.push("all");
    $graph[0].setAttribute("data-pa-gg-types", plot_types.join(" "));

    // compute this plot type
    var plot_type = $graph[0].getAttribute("data-pa-gg-type");
    if (!plot_type)
        plot_type = wstorage(false, "pa-gg-type");
    if (!plot_type)
        plot_type = plot_types[0];
    if (plot_types.indexOf(plot_type) < 0) {
        if (plot_type.substring(0, 3) === "pdf")
            plot_type = plot_types[1];
        else
            plot_type = plot_types[0];
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
        total.setAttribute("class", "pa-gg-mark-total");
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

    if ($graph[0].hasAttribute("data-pa-highlight-users"))
        gi.highlight_users();

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
            y = pa_gradecdf_findy(dd, total);
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
}

handle_ui.on("js-grgraph-flip", function () {
    var $graph = $(this).closest(".pa-grgraph"),
        plot_types = ($graph[0].getAttribute("data-pa-gg-types") || "").split(/ /),
        plot_type = $graph[0].getAttribute("data-pa-gg-type"),
        i = plot_types.indexOf(plot_type);
    if (i >= 0) {
        i = (i + (hasClass(this, "prev") ? plot_types.length - 1 : 1)) % plot_types.length;
        $graph[0].setAttribute("data-pa-gg-type", plot_types[i]);
        wstorage(false, "pa-gg-type", plot_types[i]);
        pa_draw_gradecdf($graph);
    }
});

handle_ui.on("js-grgraph-highlight", function (event) {
    if (event.type !== "change")
        return;
    var rt = this.getAttribute("data-range-type"),
        $cb = $(this).closest("form").find("input[type=checkbox]"),
        a = [];
    $(this).closest("form").find("input[type=checkbox]").each(function () {
        var $tr;
        if (this.getAttribute("data-range-type") === rt
            && this.checked
            && ($tr = $(this).closest("tr"))
            && $tr[0].hasAttribute("data-pa-uid"))
            a.push(+$tr[0].getAttribute("data-pa-uid"));
    });
    a.sort();
    var attr = a.length ? a.join(" ") : null;
    $(this).closest("form").find(".pa-grgraph").each(function () {
        if (this.getAttribute("data-pa-highlight-users") !== attr) {
            if (attr === null)
                this.removeAttribute("data-pa-highlight-users");
            else
                this.setAttribute("data-pa-highlight-users", attr);
            var gg = $(this).data("paGradeGraph");
            gg && window.requestAnimationFrame(function () {
                gg.highlight_users();
            });
        }
    });
})



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

function pa_anonymize_linkto(link, event) {
    if (event && event.metaKey)
        window.open(link);
    else
        window.location = link;
    return false;
}

function pa_render_pset_table(pconf, data) {
    var $j = $(this), dmap = [],
        flagged = pconf.flagged_commits,
        visible = pconf.grades_visible,
        grade_entries, grade_keys, need_ngrades,
        sort = {f: flagged ? "at" : "username", last: true, rev: 1},
        sorting_last, displaying_last_first = null,
        anonymous = pconf.anonymous,
        col;

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

        grade_entries = {};
        grade_keys = [];
        var grade_abbr = [];
        if (pconf.grades) {
            var grade_abbr_count = {}, grade_titles = [];
            for (var i = 0; i !== pconf.grades.order.length; ++i) {
                var k = pconf.grades.order[i], ge = pconf.grades.entries[k];
                if (ge.type !== "text") {
                    grade_entries[k] = ge;
                    grade_keys.push(k);
                    var t = ge.title || k;
                    grade_titles.push(t);
                    var m = t.match(/^(p)(?:art\s*|(?=\d))([.a-z\d]+)(?:[\s:]|$)/i);
                    m = m || t.match(/^(q)(?:uestion\s*|(?=\d))([.a-z\d]+)(?:[\s:]|$)/i);
                    m = m || t.match(/^()(\S{1,3})/);
                    var tx = m ? m[1] + m[2] : ":" + grade_keys.length + ":";
                    grade_abbr.push(tx);
                    grade_abbr_count[tx] = (grade_abbr_count[tx] || 0) + 1;
                }
            }
            for (i = 0; i !== grade_abbr.length; ++i) {
                if (grade_abbr_count[grade_abbr[i]] > 1
                    && (m = grade_titles[i].match(/\s+(\S{1,3})/))) {
                    grade_abbr[i] += m[1];
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
            if (pconf.checkbox)
                col.push("checkbox");
            col.push("rownumber");
            if (flagged) {
                col.push("pset");
                col.push("at");
            }
            col.push("username", "name", "extension", "grader");
            if (flagged || !pconf.gitless_grades || visible)
                col.push("notes");
            if (pconf.need_total)
                col.push("total");
            for (i = 0; i !== grade_keys.length; ++i)
                col.push({type: "grade", gidx: i, gkey: grade_keys[i], gabbr: grade_abbr[i], gtype: grade_entries[grade_keys[i]].type});
            if (need_ngrades)
                col.push("ngrades");
            if (!pconf.gitless)
                col.push("repo");
        }
        for (i = 0; i !== col.length; ++i)
            if (typeof col[i] === "string")
                col[i] = {type: col[i]};
    }

    function ukey(s) {
        return (anonymous && s.anon_username) || s.username || "";
    }
    function url_gradeparts(s) {
        var args = {
            u: ukey(s),
            pset: s.psetid ? peteramati_psets[s.psetid].urlkey : pconf.psetkey
        };
        if (s.hash && (!s.is_grade || flagged))
            args.commit = s.hash;
        return args;
    }
    function escaped_href(s) {
        return escape_entities(hoturl("pset", url_gradeparts(s)));
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

    var th_render = {}, td_render = {};
    th_render.checkbox = '<th class="pap-checkbox"></th>';
    td_render.checkbox = function (s, rownum) {
        return rownum == "" ? '<td></td>' :
            '<td class="pap-checkbox"><input type="checkbox" name="' +
            render_checkbox_name(s) + '" value="1" class="' +
            (this.className || "uix js-range-click") + '" data-range-type="s61"></td>';
    };
    th_render.rownumber = '<th class="pap-rownumber"></th>';
    td_render.rownumber = function (s, rownum) {
        return rownum == "" ? '<td></td>' : '<td class="pap-rownumber">' + rownum + '.</td>';
    };
    th_render.pset = '<th class="pap-pset l plsortable" data-pa-sort="pset">Pset</th>';
    td_render.pset = function (s) {
        return '<td class="pap-pset"><a href="' + escaped_href(s) + '">' +
           escape_entities(peteramati_psets[s.psetid].title) +
           (s.hash ? "/" + s.hash.substr(0, 7) : "") + '</a></td>';
    };
    th_render.at = '<th class="pap-at l plsortable" data-pa-sort="at">Flagged</th>';
    td_render.at = function (s) {
        return '<td class="pap-at">' + (s.at ? strftime("%#e %b %#k:%M", s.at) : "") + '</td>';
    };
    th_render.username = function () {
        var t = '<span class="heading">' + (anonymous || !sort.email ? "Username" : "Email") + '</span>';
        if (pconf.anonymous && pconf.can_override_anonymous)
            t += ' <a href="" class="uu n">[anon]</a>';
        else if (pconf.anonymous)
            t += ' <span class="n">[anon]</span>';
        return '<th class="pap-username l plsortable" data-pa-sort="username">' + t + '</th>';
    };
    td_render.username = function (s) {
        return '<td class="pap-username">' + render_username_td(s) + '</td>';
    };
    th_render.name = '<th class="pap-name l' + (pconf.has_nonanonymous ? "" : " pap-nonanonymous") + ' plsortable" data-pa-sort="name">Name</th>';
    td_render.name = function (s) {
        var k = !s.anon_username || pconf.has_nonanonymous ? "" : " pap-nonanonymous";
        return '<td class="pap-name' + k + '">' + render_display_name(s) + '</td>';
    };
    th_render.extension = '<th class="pap-extension l plsortable" data-pa-sort="extension">X?</th>';
    td_render.extension = function (s) {
        return '<td class="pap-extension">' + (s.x ? "X" : "") + '</td>';
    };
    th_render.grader = '<th class="pap-grader l plsortable" data-pa-sort="grader">Grader</th>';
    td_render.grader = function (s) {
        var t = s.gradercid ? "???" : "";
        if (s.gradercid && hotcrp_pc[s.gradercid])
            t = grader_name(hotcrp_pc[s.gradercid]);
        return '<td class="pap-grader">' + t + '</td>';
    };
    th_render.notes = '<th class="pap-notes l plsortable" data-pa-sort="gradestatus">⎚</th>';
    td_render.notes = function (s) {
        var t = '';
        if (s.grades_visible)
            t += '⎚';
        if (flagged && s.is_grade)
            t += '✱';
        if (s.has_notes)
            t += '♪';
        if (!flagged && s.has_nongrader_notes)
            t += '<sup>*</sup>';
        return '<td class="pap-notes">' + t + '</td>';
    };
    th_render.total = '<th class="pap-total r plsortable" data-pa-sort="total">Tot</th>';
    td_render.total = function (s) {
        return '<td class="pap-total r">' + s.total + '</td>';
    };
    th_render.grade = function () {
        var justify = (this.gtype ? pa_grade_types[this.gtype].justify : false) || "right";
        var klass = justify === "right" ? "pap-grade r" : "pap-grade";
        return '<th class="' + klass + ' plsortable" data-pa-sort="grade' + this.gidx + '">' + this.gabbr + '</th>';
    };
    td_render.grade = function (s, rownum, text) {
        var gr = s.grades[this.gidx];
        if (gr == null)
            gr = "";
        if (gr !== "" && this.gtype)
            gr = escape_entities(pa_grade_types[this.gtype].text.call(grade_entries[this.gkey], gr));
        if (text)
            return gr;
        else {
            var k = this.gkey === pconf.total_key ? "pap-total" : "pap-grade";
            if (s.highlight_grades && s.highlight_grades[this.gkey])
                k += " pap-highlight";
            if (!this.gtype || (pa_grade_types[this.gtype].justify || "right") === "right")
                k += " r";
            return '<td class="' + k + '">' + gr + '</td>';
        }
    };
    th_render.ngrades = '<th class="pap-ngrades r plsortable" data-pa-sort="ngrades">#G</th>';
    td_render.ngrades = function (s) {
        return '<td class="pap-ngrades r">' + (s.ngrades_nonempty || "") + '</td>';
    };
    th_render.repo = '<th class="pap-repo"></th>';
    td_render.repo = function (s) {
        var txt;
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
        return '<td class="pap-repo">' + txt + '</td>';
    };

    function render_tds(s, rownum) {
        var a = [];
        for (var i = 0; i !== col.length; ++i)
            a.push(td_render[col[i].type].call(col[i], s, rownum));
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
                a.push('<tr class="pap-boring"><td colspan="' + col.length + '"><hr /></td></tr>');
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
                for (var k = 0; k < sstds.length; ++k)
                    if (sstds[k] === stds[k])
                        sstds[k] = '<td></td>';
                a.push('<tr class="k' + (trn % 2) + ' papr-partner" data-pa-spos="' + ss._spos + '" data-pa-partner="1">' + sstds.join('') + '</tr>');
            }
            $b.append(a.join(''));
        }
        set_hotlist($b);
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
                tb.insertBefore($('<tr class="pap-boring"><td colspan="' + col.length + '"><hr /></td></tr>')[0], last);
            was_boringness = data[i].boringness;
            tr = rmap[data[i]._spos];
            for (j = 0; j < tr.length; ++j) {
                if (last != tr[j])
                    tb.insertBefore(tr[j], last);
                else
                    last = last.nextSibling;
                removeClass(tr[j], "k" + (1 - trn % 2));
                addClass(tr[j], "k" + (trn % 2));
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
        wstorage.site(true, "pa-pset" + pconf.id + "-table", JSON.stringify(sort));
    }
    function rerender_usernames() {
        $j.find("td.pap-username").each(function () {
            var s = dmap[this.parentNode.getAttribute("data-pa-spos")];
            $(this).html(render_username_td(s));
        });
        $j.find("th.pap-username > span.heading").html(anonymous || !sort.email ? "Username" : "Email");
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
        $j.closest("form").find("input[name=anonymous]").val(anonymous ? 1 : 0);
        return false;
    }
    function render_head() {
        var a = [];
        for (var i = 0; i !== col.length; ++i) {
            var r = th_render[col[i].type];
            if (typeof r === "string")
                a.push(r);
            else
                a.push(r.call(col[i]));
        }
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
    function grader_compare(a, b) {
        var ap = a.gradercid ? hotcrp_pc[a.gradercid] : null;
        var bp = b.gradercid ? hotcrp_pc[b.gradercid] : null;
        var ag = (ap && grader_name(ap)) || "~~~";
        var bg = (bp && grader_name(bp)) || "~~~";
        if (ag != bg)
            return ag < bg ? -sort.rev : sort.rev;
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
        var f = sort.f, rev = sort.rev, m;
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

    function grade_click(evt) {
        if ((!evt
             || (evt.metaKey
                 && evt.button === 0
                 && evt.target.tagName !== "A"))
            && !this.hasAttribute("contenteditable")) {
            grade_start.call(this);
        }
    }
    function grade_start() {
        this.setAttribute("contenteditable", "true");
        if (!this.hasAttribute("data-pa-original-grade"))
            this.setAttribute("data-pa-original-grade", this.innerText);
        this.addEventListener("keydown", grade_key, false);
        this.addEventListener("blur", grade_blur, false);
        this.focus();
        document.execCommand("selectAll", false, null);
    }
    function grade_blur() {
        this.removeAttribute("contenteditable");
        this.removeEventListener("keydown", grade_key, false);
        this.removeEventListener("blur", grade_blur, false);

        var t = this.innerText,
            original_grade = this.getAttribute("data-pa-original-grade"),
            spos = this.parentElement.getAttribute("data-pa-spos"),
            gidx = grade_index(this),
            self = this;
        if (original_grade !== t
            && spos !== null
            && dmap[spos].uid) {
            var grades = {}, oldgrades = {};
            grades[grade_keys[gidx]] = t;
            oldgrades[grade_keys[gidx]] = original_grade;
            $.ajax(hoturl_post("api/grade", url_gradeparts(dmap[spos])), {
                type: "POST", cache: false,
                data: {grades: grades, oldgrades: oldgrades},
                success: function (rv) {
                    if (rv.ok) {
                        dmap[spos].grades[gidx] = rv.grades[gidx];
                        var c = col[self.cellIndex];
                        var gr = td_render[c.type].call(c, dmap[spos], null, true);
                        self.setAttribute("data-pa-original-grade", gr);
                        self.innerText = gr;
                    }
                    setajaxcheck(self, rv);
                }
            });
        }
    }
    function grade_td(n) {
        while (n.tagName !== "TD")
            n = n.parentElement;
        return n;
    }
    function grade_index(n) {
        n = grade_td(n);
        var i = n.cellIndex, m;
        var table = n.parentElement.parentElement.parentElement;
        var th = table.tHead.firstChild.cells[i];
        var sort = th ? th.getAttribute("data-pa-sort") : null;
        if (sort && (m = sort.match(/^grade(\d+)$/)))
            return +sort.substring(5);
        else
            return null;
    }
    function grade_key(evt) {
        var key = event_key(evt), mod = event_modkey(evt);
        if (key === "Tab" && (mod === 0 || mod === event_modkey.SHIFT)) {
            var td = grade_td(this), tr = td.parentElement, i = td.cellIndex;
            var dir = mod ? "previousSibling" : "nextSibling";
            do {
                tr = tr[dir];
            } while (tr && hasClass(tr, "pap-boring"));
            if (tr) {
                grade_click.call(tr.cells[i]);
                evt.preventDefault();
            }
            return;
        }
    }

    initialize();
    $j.html("<thead></thead><tbody class='has-hotlist'></tbody>");
    $j.toggleClass("pap-anonymous", !!anonymous);
    $j.toggleClass("pap-useemail", !!sort.email);
    $j.find("thead").on("click", "th", head_click);
    $j.find("tbody").on("click", "td.pap-grade", grade_click);
    $j.find("tbody").on("click", "td.pap-total", grade_click);
    render_head();
    if (!pconf.no_sort)
        sort_data();
    render_body();
}


handle_ui.on("pa-diff-toggle-hide-left", function () {
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
});


handle_ui.on("js-repositories", function (event) {
    var self = this;
    $.ajax(hoturl("api", {fn: "repositories", u: this.getAttribute("data-pa-user")}), {
        method: "POST", cache: false,
        success: function (data) {
            var t = "Error loading repositories";
            if (data.repositories && data.repositories.length) {
                t = "Repositories: ";
                for (var i = 0; i < data.repositories.length; ++i) {
                    var r = data.repositories[i];
                    i && (t += ", ");
                    t += "<a href=\"" + escape_entities(r.url) + "\">" + escape_entities(r.name) + "</a>";
                }
            } else if (data.repositories)
                t = "No repositories";
            $("<div style=\"font-size:medium;font-weight:normal\"></div>").html(t).insertAfter(self);
        }
    });
    event.preventDefault();
});


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
