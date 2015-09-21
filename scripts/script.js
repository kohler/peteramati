// script.js -- HotCRP JavaScript library
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

var siteurl, siteurl_postvalue, siteurl_suffix, siteurl_defaults,
    siteurl_absolute_base,
    hotcrp_paperid, hotcrp_list, hotcrp_status, hotcrp_user,
    peteramati_uservalue,
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

function staged_foreach(a, f, backwards) {
    var i = (backwards ? a.length - 1 : 0);
    var step = (backwards ? -1 : 1);
    var stagef = function () {
        var x;
        for (x = 0; i >= 0 && i < a.length && x < 100; i += step, ++x)
            f(a[i]);
        if (i < a.length)
            setTimeout(stagef, 0);
    };
    stagef();
}


// promises
function Promise(value) {
    this.value = value;
    this.state = value === undefined ? false : 1;
    this.c = [];
}
Promise.prototype.then = function (yes, no) {
    var next = new Promise;
    this.c.push([no, yes, next]);
    if (this.state !== false)
        this._resolve();
    else if (this.on) {
        this.on(this);
        this.on = null;
    }
    return next;
};
Promise.prototype._resolve = function () {
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
Promise.prototype.fulfill = function (value) {
    if (this.state === false) {
        this.value = value;
        this.state = 1;
        this._resolve();
    }
};
Promise.prototype.reject = function (reason) {
    if (this.state === false) {
        this.value = reason;
        this.state = 0;
        this._resolve();
    }
};
Promise.prototype.onThen = function (f) {
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
    jQuery.ajax({
        url: hoturl("api", "fn=jserror"),
        type: "POST", cache: false, data: errormsg
    });
    if (error && !noconsole && typeof console === "object" && console.error)
        console.error(errormsg.error);
}

(function () {
    var old_onerror = window.onerror, nerrors_logged = 0;
    window.onerror = function (errormsg, url, lineno, colno, error) {
        if (++nerrors_logged <= 10) {
            var x = {"error": errormsg, "url": url, "lineno": lineno};
            if (colno)
                x.colno = colno;
            log_jserror(x, error, true);
        }
        return old_onerror ? old_onerror.apply(this, arguments) : false;
    };
})();


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
        var p = this.geometry(), w = jQuery(window).geometry();
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
        return s.replace(re, function (match) {
            return rep[match];
        });
    };
})();

function text_to_html(text) {
    var n = document.createElement("div");
    n.appendChild(document.createTextNode(text));
    return n.innerHTML;
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
    joinword = joinword || "and";
    if (a.length == 0)
        return "";
    else if (a.length == 1)
        return a[0];
    else if (a.length == 2)
        return a[0] + " " + joinword + " " + a[1];
    else
        return a.slice(0, a.length - 1).join(", ") + ", " + joinword + " " + a[a.length - 1];
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
    code_map = {
        "9": "Tab", "13": "Enter", "16": "Shift", "17": "Control", "18": "Option",
        "27": "Escape", "186": ":", "219": "[", "221": "]"
    };
return function (evt) {
    if (evt.key != null)
        return key_map[evt.key] || evt.key;
    var code = evt.charCode || evt.keyCode;
    if (code)
        return code_map[code] || String.fromCharCode(code);
    else
        return "";
};
})();

function event_modkey(evt) {
    return (evt.shiftKey ? 1 : 0) + (evt.ctrlKey ? 2 : 0) + (evt.altKey ? 4 : 0) + (evt.metaKey ? 8 : 0);
}
event_modkey.SHIFT = 1;
event_modkey.CTRL = 2;
event_modkey.ALT = 4;
event_modkey.META = 8;


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
    if ((kind = kind || jelt.attr("rangetype")))
        kindsearch = "[rangetype~='" + kind + "']";
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
    dir_to_taildir = {
        "0": 0, "1": 1, "2": 2, "3": 3,
        "t": 0, "r": 1, "b": 2, "l": 3,
        "n": 0, "e": 1, "s": 2, "w": 3
    },
    SPACE = 8;

function cssbc(dir) {
    return "border" + capdir[dir] + "Color";
}

function cssbw(dir) {
    return "border" + capdir[dir] + "Width";
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
    var j = make_model(color), tail = j.children();
    var sizes = [tail.width(), tail.height()];
    j.remove();
    return sizes;
}

function expand_near(epos, color) {
    var dir, x, j = make_model(color);
    epos = jQuery.extend({}, epos);
    for (dir = 0; dir < 4; ++dir)
        if ((x = j.css("margin" + capdir[dir])) && (x = parseFloat(x)))
            epos[lcdir[dir]] += (dir == 0 || dir == 3 ? -x : x);
    j.remove();
    return epos;
}

return function (content, bubopt) {
    if (!bubopt && content && typeof content === "object") {
        bubopt = content;
        content = bubopt.content;
    } else if (!bubopt)
        bubopt = {};
    else if (typeof bubopt === "string")
        bubopt = {color: bubopt};

    var nearpos = null, dirspec = bubopt.dir || "r", dir = null,
        color = bubopt.color ? " " + bubopt.color : "";

    var bubdiv = $('<div class="bubble' + color + '" style="margin:0"><div class="bubtail bubtail0' + color + '" style="width:0;height:0"></div><div class="bubcontent"></div><div class="bubtail bubtail1' + color + '" style="width:0;height:0"></div></div>')[0];
    document.body.appendChild(bubdiv);
    if (bubopt["pointer-events"])
        $(bubdiv).css({"pointer-events": bubopt["pointer-events"]});
    var bubch = bubdiv.childNodes;
    var sizes = null;
    var divbw = null;

    function change_tail_direction() {
        var bw = [0, 0, 0, 0];
        divbw = parseFloat($(bubdiv).css(cssbw(dir)));
        divbw !== divbw && (divbw = 0); // eliminate NaN
        bw[dir^1] = bw[dir^3] = (sizes[0] / 2) + "px";
        bw[dir^2] = sizes[1] + "px";
        bubch[0].style.borderWidth = bw.join(" ");
        bw[dir^1] = bw[dir^3] = Math.max(sizes[0] / 2 - 0.77*divbw, 0) + "px";
        bw[dir^2] = Math.max(sizes[1] - 0.77*divbw, 0) + "px";
        bubch[2].style.borderWidth = bw.join(" ");

        var i, yc;
        for (i = 1; i <= 3; ++i)
            bubch[0].style[lcdir[dir^i]] = bubch[2].style[lcdir[dir^i]] = "";
        bubch[0].style[lcdir[dir]] = (-sizes[1]) + "px";
        bubch[2].style[lcdir[dir]] = (-sizes[1] + divbw) + "px";

        for (i = 0; i < 3; i += 2)
            bubch[i].style.borderLeftColor = bubch[i].style.borderRightColor =
            bubch[i].style.borderTopColor = bubch[i].style.borderBottomColor = "transparent";

        yc = to_rgba($(bubdiv).css("backgroundColor")).replace(/([\d.]+)\)/, function (s, p1) {
            return (0.75 * p1 + 0.25) + ")";
        });
        bubch[0].style[cssbc(dir^2)] = $(bubdiv).css(cssbc(dir));
        bubch[2].style[cssbc(dir^2)] = yc;
    }

    function constrain(za, z0, z1, bdim, noconstrain) {
        var z = za - bdim / 2, size = sizes[0];
        if (!noconstrain && z < z0 + SPACE)
            z = Math.min(za - size, z0 + SPACE);
        else if (!noconstrain && z + bdim > z1 - SPACE)
            z = Math.max(za + size - bdim, z1 - SPACE - bdim);
        return z;
    }

    function errlog(d, ya, y, wpos, bpos, err) {
        var ex = [d, divbw, ya, y];
        if (window.JSON)
            ex.push(JSON.stringify({"n": nearpos, "w": wpos, "b": bpos}));
        log_jserror({"error": ex.join(" ")}, err);
    }

    function make_bpos(wpos, ds) {
        var bj = $(bubdiv);
        bj.css("maxWidth", "");
        var bpos = bj.geometry(true);
        var lw = nearpos.left - wpos.left, rw = wpos.right - nearpos.right;
        var xw = Math.max(ds === 1 ? 0 : lw, ds === 3 ? 0 : rw);
        var wb = wpos.width;
        if ((ds === "h" || ds === 1 || ds === 3) && xw > 100)
            wb = Math.min(wb, xw);
        if (wb < bpos.width - 3*SPACE) {
            bj.css("maxWidth", wb - 3*SPACE);
            bpos = bj.geometry(true);
        }
        return bpos;
    }

    function show() {
        var noflip = /!/.test(dirspec), noconstrain = /\*/.test(dirspec),
            ds = dirspec.replace(/[!*]/g, "");
        if (dir_to_taildir[ds] != null)
            ds = dir_to_taildir[ds];
        if (!sizes)
            sizes = calculate_sizes(color);

        var wpos = $(window).geometry();
        var bpos = make_bpos(wpos, ds);
        var size = sizes[0];
        var bw = bpos.width + size, bh = bpos.height + size;

        if (ds === "a" || ds === "") {
            if (bh > Math.max(nearpos.top - wpos.top, wpos.bottom - nearpos.bottom))
                ds = "h";
            else
                ds = "v";
        }
        if ((ds === "v" || ds === 0 || ds === 2) && !noflip
            && nearpos.bottom + bh > wpos.bottom - 3*SPACE
            && nearpos.top - bh < wpos.top + 3*SPACE
            && (nearpos.left - bw >= wpos.left + 3*SPACE
                || nearpos.right + bw <= wpos.right - 3*SPACE))
            ds = "h";
        if ((ds === "v" && nearpos.bottom + bh > wpos.bottom - 3*SPACE
             && nearpos.top - bh > wpos.top + 3*SPACE)
            || (ds === 0 && !noflip && nearpos.bottom + bh > wpos.bottom)
            || (ds === 2 && (noflip || nearpos.top - bh >= wpos.top + SPACE)))
            ds = 2;
        else if (ds === "v" || ds === 0 || ds === 2)
            ds = 0;
        else if ((ds === "h" && nearpos.left - bw < wpos.left + 3*SPACE
                  && nearpos.right + bw < wpos.right - 3*SPACE)
                 || (ds === 1 && !noflip && nearpos.left - bw < wpos.left)
                 || (ds === 3 && (noflip || nearpos.right + bw <= wpos.right - SPACE)))
            ds = 3;
        else
            ds = 1;

        if (ds !== dir) {
            dir = ds;
            change_tail_direction();
        }

        var x, y, xa, ya, d;
        if (dir & 1) {
            ya = (nearpos.top + nearpos.bottom) / 2;
            y = constrain(ya, wpos.top, wpos.bottom, bpos.height, noconstrain);
            d = roundpixel(ya - y - size / 2);
            try {
                bubch[0].style.top = d + "px";
                bubch[2].style.top = (d + 0.77*divbw) + "px";
            } catch (err) {
                errlog(d, ya, y, wpos, bpos, err);
            }

            if (dir == 1)
                x = nearpos.left - bpos.width - sizes[1] - 1;
            else
                x = nearpos.right + sizes[1];
        } else {
            xa = (nearpos.left + nearpos.right) / 2;
            x = constrain(xa, wpos.left, wpos.right, bpos.width, noconstrain);
            d = roundpixel(xa - x - size / 2);
            try {
                bubch[0].style.left = d + "px";
                bubch[2].style.left = (d + 0.77*divbw) + "px";
            } catch (err) {
                errlog(d, xa, x, wpos, bpos, err);
            }

            if (dir == 0)
                y = nearpos.bottom + sizes[1];
            else
                y = nearpos.top - bpos.height - sizes[1] - 1;
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
            if (typeof epos === "string" || epos.tagName || epos.jquery)
                epos = $(epos).geometry(true);
            for (i = 0; i < 4; ++i)
                if (!(lcdir[i] in epos) && (lcdir[i ^ 2] in epos))
                    epos[lcdir[i]] = epos[lcdir[i ^ 2]];
            if (reference && (reference = $(reference)) && reference.length
                && reference[0] != window)
                epos = geometry_translate(epos, reference.geometry());
            if (!epos.exact)
                epos = expand_near(epos, color);
            nearpos = epos;
            show();
            return bubble;
        },
        at: function (x, y, reference) {
            return bubble.near({top: y, left: x, exact: true}, reference);
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


function tooltip(elt) {
    var j = $(elt), near, tt;

    function jqnear(attr) {
        var x = j.attr(attr);
        if (x && x.charAt(0) == ">")
            return j.find(x.substr(1));
        else if (x)
            return $(x);
        else
            return $();
    }

    var content = j.attr("hottooltip") || jqnear("hottooltipcontent").html();
    if (!content)
        return null;

    if ((tt = window.global_tooltip)) {
        if (tt.elt !== elt || tt.content !== content)
            tt.erase();
        else
            return tt;
    }

    var dir = j.attr("hottooltipdir") || "v",
        bub = make_bubble(content, {color: "tooltip", dir: dir}),
        to = null, refcount = 0;
    function erase() {
        to = clearTimeout(to);
        bub.remove();
        j.removeData("hotcrp_tooltip");
        if (window.global_tooltip === tt)
            window.global_tooltip = null;
    }
    tt = {
        enter: function () {
            to = clearTimeout(to);
            ++refcount;
        },
        exit: function () {
            var delay = j.attr("hottooltiptype") == "focus" ? 0 : 200;
            to = clearTimeout(to);
            if (--refcount == 0)
                to = setTimeout(erase, delay);
        },
        erase: erase, elt: elt, content: content
    };
    j.data("hotcrp_tooltip", tt);
    near = jqnear("hottooltipnear")[0] || elt;
    bub.near(near).hover(tt.enter, tt.exit);
    return window.global_tooltip = tt;
}

function tooltip_enter(evt) {
    var j = $(this), x, text;
    var tt = j.data("hotcrp_tooltip");
    if (!tt && !window.disable_tooltip)
        tt = tooltip(this);
    if (tt)
        tt.enter();
}

function tooltip_leave(evt) {
    var j = $(this), tt;
    if ((tt = j.data("hotcrp_tooltip")))
        tt.exit();
}

function add_tooltip() {
    var j = jQuery(this);
    if (j.attr("hottooltiptype") == "focus")
        j.on("focus", tooltip_enter).on("blur", tooltip_leave);
    else
        j.hover(tooltip_enter, tooltip_leave);
}

jQuery(function () { jQuery(".hottooltip").each(add_tooltip); });


// temporary text
window.mktemptext = (function () {
function setclass(e, on) {
    jQuery(e).toggleClass("temptext", on);
}
function blank() {
}

return function (e, text) {
    if (typeof this === "object" && typeof this.tagName === "string"
        && this.tagName.toUpperCase() == "INPUT") {
        text = typeof e === "number" ? this.getAttribute("hottemptext") : e;
        e = this;
    } else if (typeof e === "string")
        e = $$(e);
    var onfocus = e.onfocus || blank, onblur = e.onblur || blank;
    e.onfocus = function (evt) {
        if (this.value == text) {
            this.value = "";
            setclass(this, false);
        }
        onfocus.call(this, evt);
    };
    e.onblur = function (evt) {
        if (this.value == "" || this.value == text) {
            this.value = text;
            setclass(this, true);
        }
        onblur.call(this, evt);
    };
    jQuery(e).on("change", function () {
        setclass(this, this.value == "" || this.value == text);
    });
    if (e.value == "")
        e.value = text;
    setclass(e, e.value == text);
};
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
        if (elt.tagName.toUpperCase() == "SPAN") {
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
        for (x = 0; x < hotcrp_onload.length; ++x)
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

function crpSubmitKeyFilter(elt, e) {
    e = e || window.event;
    var form;
    if (event_modkey(e) || event_key(e) != "Enter")
        return true;
    form = elt;
    while (form && form.tagName && form.tagName.toUpperCase() != "FORM")
        form = form.parentNode;
    if (form && form.tagName) {
        elt.blur();
        if (!form.onsubmit || !(form.onsubmit instanceof Function) || form.onsubmit())
            form.submit();
        return false;
    } else
        return true;
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
    var anchorPos = $(anchor).geometry();
    var wg = $(window).geometry();
    var x = (anchorPos.right + anchorPos.left - elt.offsetWidth) / 2;
    var y = (anchorPos.top + anchorPos.bottom - elt.offsetHeight) / 2;
    elt.style.left = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) + "px";
    elt.style.top = Math.max(wg.top + 5, Math.min(wg.bottom - 5 - elt.offsetHeight, y)) + "px";
}

function popup(anchor, which, dofold, populate) {
    var elt, form, elts, populates, i, xelt, type;
    if (typeof which === "string") {
        elt = $$("popup_" + which);
        anchor = anchor || $$("popupanchor_" + which);
    }

    if (elt && dofold)
        elt.className = "popupc";
    else if (elt) {
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
        while (form && form.tagName && form.tagName.toUpperCase() != "FORM")
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


// JSON
if (!window.JSON || !JSON.parse) {
    JSON = window.JSON || {};
    JSON.parse = function (text) {
        return eval("(" + text + ")"); /* sigh */
    };
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


// mail
function setmailpsel(sel) {
    fold("psel", !!sel.value.match(/^(?:pc$|pc:|all$)/), 9);
    fold("psel", !sel.value.match(/^new.*rev$/), 10);
}


linenote61 = (function ($) {
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
    while (table && !table.getAttribute("run61file"))
        table = table.parentNode;
    if (!tr || !table || !/\bdiffl61\b.*\bg[idc]\b/.test(tr.className))
        return null;

    result = {filename: table.getAttribute("run61file"), tr: tr};
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

function note_same(a, b) {
    return a == b || a.replace(/\r\n?/g, "\n") == b;
}

function remove_tr(tr) {
    tr.setAttribute("deleting61", "1");
    $(tr).find(":focus").blur();
    $(tr).children().slideUp(80).queue(function () { $(tr).remove(); });
}

function unedit(tr) {
    while (tr && tr.getAttribute("savednote61") === null)
        tr = tr.parentNode;
    if (tr && note_same(tr.getAttribute("savednote61"), $(tr).find("textarea").val())) {
        var $tr = $(tr);
        $tr.find(":focus").blur();
        if (tr.getAttribute("savednote61") === "")
            remove_tr(tr);
        else {
            var iscomment = !!tr.getAttribute("iscomment61"),
                $td = $tr.find("td.difflnote61"),
                $note = $('<div class="note61' + (iscomment ? " commentnote" : " gradenote") + '" style="display:none"></div>'),
                $edit = $td.find(".diffnoteholder61");
            $note.text(tr.getAttribute("savednote61"));
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

    var $tr = anal.notetr && $(anal.notetr), j, text = null, iscomment = null;
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
    $tr.attr("savednote61", text === null ? "" : text).attr("iscomment61", iscomment ? "1" : null);
    $tr.find(".diffnoteholder61").show();
    j = $tr.find("textarea").focus();
    if (text !== null) {
        $tr.removeClass("iscomment61 isgrade61").addClass(iscomment ? "iscomment61" : "isgrade61");
        j.text(text);
        j[0].setSelectionRange && j[0].setSelectionRange(text.length, text.length);
    }
    j.autogrow().keyup(keyup);
    $tr.find("[name='file']").val(anal.filename);
    $tr.find("[name='line']").val(anal.lineid);
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
            if (data && data.ok) {
                form.prop("outstanding", false);
                form.find(".ajaxsave61").html("Saved");
                success && success(data);
            } else
                form.find(".ajaxsave61").html("Failed");
        },
        error: function () {
            form.find(".ajaxsave61").html("Failed");
        }
    });
    return false;
}

function savelinenote61(form) {
    return ajaxsave61(form, function (data) {
            jQuery(form).closest("tr").attr("savednote61", data.savednote)
                .attr("iscomment61", data.iscomment ? "1" : null);
            linenote61.unedit(form);
        });
}

function gradetotal61() {
    var i, j = jQuery(".grader61.gradepart"), total = 0, x;
    for (i = 0; i < j.length; ++i) {
        x = parseFloat(jQuery(j[i]).val());
        x == x && (total += x);
    }
    total = Math.floor(total * 100 + 0.5) / 100;
    if (jQuery(".gradetotal61").text() != total) {
        jQuery(".gradetotal61").text(total);
        gradecdf61();
    }
}

function gradesubmit61(form) {
    return ajaxsave61(form, gradetotal61);
}

function setiscomment61(e, val) {
    jQuery(e).closest("form").find(".iscomment").val(val);
    return true;
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
    if (therun.attr("run61timestamp") && !therun.is(":visible")) {
        thebutton = jQuery(".runner61[value='" + name + "']")[0];
        run61(thebutton, +therun.attr("run61timestamp"));
        therun.attr("run61timestamp", "");
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
        if ((note = x[i].getAttribute("savednote61")) !== null) {
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
                var n = elt.attr("name61") || elt.attr("name");
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

function reqregrade61(button) {
    var form = jQuery(button).closest("form");
    jQuery.ajax(form.attr("action"), {
        data: form.serializeWith({reqregrade: 1}),
        type: "POST", cache: false,
        dataType: "json",
        success: function (data) {
            if (data && data.ok)
                jQuery(button).html("Regrade Requested");
        },
        error: function () {
            form.find(".ajaxsave61").html("<span class='error'>Failed</span>");
        }
    });
}

function run61(button, opt) {
    var form = jQuery(button).closest("form"),
        therun = jQuery("#run61_" + button.value),
        thepre = therun.find("pre"), checkt;

    if (typeof opt === "number")
        checkt = opt;
    if (typeof opt !== "object")
        opt = {};
    checkt = checkt || opt.checkt;

    if (!checkt) {
        if (form.prop("outstanding"))
            return true;
        form.find("button").prop("disabled", true);
        form.prop("outstanding", true);
        therun.attr("run61timestamp", "");
    }

    fold61(therun, jQuery("#run61out_" + button.value).show(), true);
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
        if (jQuery(button).attr("loadgrade61"))
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
            lines = line.split(/\r/),
            lineno, i, m, r = [];
        for (lineno = 0; lineno < lines.length; ++lineno) {
            var g = [], glen = 0, j;
            var lsplit = lines[lineno].split(/(\x1b\[[\d;]+m)/);
            for (j = 0; j < lsplit.length; j += 2) {
                if (lsplit[j] !== "") {
                    g.push(curstyle, lsplit[j]);
                    glen += lsplit[j].length;
                }
                if (j + 1 < lsplit.length)
                    curstyle = ansi_combine(curstyle, m[2]);
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
        var filematch = $(".filediff61[run61file='" + file + "']"), dir;
        if (!filematch.length && (dir = therun.attr("run61directory")))
            filematch = $(".filediff61[run61file='" + dir + "/" + file + "']");
        if (filematch.length) {
            var anchor = "Lb" + line + "_" + filematch.attr("run61fileid");
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
            if (displaylen + render.length > 132) {
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
        if (node && (str = node.getAttribute("run61partline")) !== null
            && str !== "" && lines.length) {
            while (node.firstChild)
                node.removeChild(node.firstChild);
            lines[0] = str + lines[0];
            node.removeAttribute("run61partline");
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
                node.setAttribute("run61partline", last);
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
            thepre[0].insertBefore((jQuery("<span class='run61queue'>" + t + "</span>"))[0], thepre[0].lastChild);
            setTimeout(send, 10000);
            return;
        }

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

    function send() {
        jQuery.ajax(form.attr("action"), {
            data: form.serializeWith({run: button.value, ajax: 1,
                                      offset: offset,
                                      check: (checkt ? checkt : null),
                                      queueid: (queueid ? queueid : null)}),
            type: "POST", cache: false,
            dataType: "json",
            success: succeed,
            error: function () {
                form.find(".ajaxsave61").html("Failed");
                form.prop("outstanding", false);
            }
        });
    }

    if (opt.headline && opt.noclear && thepre[0].firstChild != thecursor)
        append("\n\n");
    if (opt.headline && opt.headline instanceof Node)
        append_html(opt.headline);
    else if (opt.headline)
        append("\x1b[01;37m" + opt.headline + "\x1b[0m\n");
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
        var $x = jQuery("<a href=\"pset/" + $f.find("[name='pset']").val() + "/" + encodeURIComponent(user) + "\" class=\"q ansib ansifg7\"></a>");
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
    var $x = $("<table class=\"cs61grp\" runsetting61num=\"" + num + "\"><tr><td class=\"cs61key\"></td><td><input name=\"n" + num + "\" class=\"n\" size=\"30\" /> &nbsp; <input name=\"v" + num + "\" class=\"v\" size=\"40\" /></td></tr></table>");
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

    pset = jQuery(".commitcontainer61").first().attr("peteramati_pset");
    if (pset) {
        hash = jQuery(".commitcontainer61").first().attr("peteramati_commit");
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
$.fn.autogrow = function (options) {
	return this.filter("textarea").each(function () {
	    var self	     = this;
	    var $self	     = $(self);
	    var minHeight    = $self.height();
	    var noFlickerPad = $self.hasClass('autogrow-short') ? 0 : parseInt($self.css('lineHeight')) || 0;
        var settings = $.extend({
            preGrowCallback: null,
            postGrowCallback: null
        }, options);

        var shadow = textarea_shadow($self);
        var update = function (event) {
            var val = self.value;

            // Did enter get pressed?  Resize in this keydown event so that the flicker doesn't occur.
            if (event && event.data && event.data.event === 'keydown' && event.keyCode === 13) {
                val += "\n";
            }

            shadow.css('width', $self.width());
            shadow.text(val + (noFlickerPad === 0 ? '...' : '')); // Append '...' to resize pre-emptively.

            var newHeight=Math.max(shadow.height() + noFlickerPad, minHeight);
            if(settings.preGrowCallback!=null){
                newHeight=settings.preGrowCallback($self,shadow,newHeight,minHeight);
            }

            $self.height(newHeight);

            if(settings.postGrowCallback!=null){
                settings.postGrowCallback($self);
            }
        }

        $self.on("change keyup", update).keydown({event:'keydown'}, update);
        $(window).resize(update);

        update();
	});
};
})(jQuery);
