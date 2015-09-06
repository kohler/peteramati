// script.js -- HotCRP JavaScript library
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

var siteurl, siteurl_postvalue, siteurl_suffix, siteurl_defaults,
    siteurl_absolute_base, hotcrp_paperid,
    hotcrp_list, hotcrp_status, hotcrp_user, peteramati_uservalue,
    hotcrp_want_override_conflict;

function $$(id) {
    return document.getElementById(id);
}

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

function hoturl_clean(x, page_component) {
    var m;
    if (x.o && (m = x.o.match(new RegExp("^(.*)(?:^|&)" + page_component + "(?:&|$)(.*)$")))) {
        x.t += "/" + m[2];
        x.o = m[1] + (m[1] && m[3] ? "&" : "") + m[3];
    }
}

function hoturl(page, options) {
    var k, t, a, m, x;
    options = serialize_object(options);
    x = {t: siteurl + page + siteurl_suffix, o: serialize_object(options)};
    if (page === "paper" || page === "review")
        hoturl_clean(x, "p=(\\d+)");
    else if (page === "help")
        hoturl_clean(x, "t=(\\w+)");
    else if (page === "index")
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
    a = [];
    if (siteurl_defaults)
        a.push(serialize_object(siteurl_defaults));
    if (x.o)
        a.push(x.o);
    if (a.length)
        x.t += "?" + a.join("&");
    return x.t;
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


(function () {
    var old_onerror = window.onerror, nerrors_logged = 0;
    window.onerror = function (errormsg, url, lineno) {
        if (++nerrors_logged <= 10)
            jQuery.ajax({
                url: hoturl("api", "jserror=1"),
                type: "POST", cache: false,
                data: {"error": errormsg, "url": url, "lineno": lineno}
            });
        return old_onerror ? old_onerror.apply(this, arguments) : false;
    };
})();

jQuery.fn.extend({
    geometry: function (outer) {
        var x;
        if (this[0] == window)
            x = {left: this.scrollLeft(), top: this.scrollTop()};
        else
            x = this.offset();
        if (x) {
            x.width = outer ? this.outerWidth() : this.width();
            x.height = outer ? this.outerHeight() : this.height();
            x.right = x.left + x.width;
            x.bottom = x.top + x.height;
        }
        return x;
    }
});

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



function text_to_html(text) {
    var n = document.createElement("div");
    n.appendChild(document.createTextNode(text));
    return n.innerHTML;
}


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
    while (e && e.id.substr(0, 4) != "fold" && !e.getAttribute("hotcrp_fold"))
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
        Miniajax.get(hoturl("sessionvar", "j=1&var=" + opts.s + "&val=" + (dofold ? 1 : 0)));
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
        window.scrollTo(0, 0);
    return !(selt || felt);
}

function crpSubmitKeyFilter(elt, event) {
    var e = event || window.event;
    var code = e.charCode || e.keyCode;
    var form;
    if (e.ctrlKey || e.altKey || e.shiftKey || code != 13)
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
    if (e.value == "")
        e.value = text;
    setclass(e, e.value == text);
};
})();


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

function make_bubble(content) {
    var bubdiv = make_e_class("div", "bubble"), dir = "r";
    bubdiv.appendChild(make_e_class("div", "bubtail0 r"));
    bubdiv.appendChild(make_e_class("div", "bubcontent"));
    bubdiv.appendChild(make_e_class("div", "bubtail1 r"));
    get_body().appendChild(bubdiv);

    function position_tail() {
        var ch = bubdiv.childNodes, x, y;
        var pos = eltPos(bubdiv), tailpos = eltPos(ch[0]);
        if (dir == "r" || dir == "l")
            y = Math.floor((pos.height - tailpos.height) / 2);
        if (x != null)
            ch[0].style.left = ch[2].style.left = x + "px";
        if (y != null)
            ch[0].style.top = ch[2].style.top = y + "px";
    }

    var bubble = {
        show: function (x, y) {
            var pos = eltPos(bubdiv);
            if (dir == "r")
                x -= pos.width, y -= pos.height / 2;
            bubdiv.style.visibility = "visible";
            bubdiv.style.left = Math.floor(x) + "px";
            bubdiv.style.top = Math.floor(y) + "px";
        },
        remove: function () {
            bubdiv.parentElement.removeChild(bubdiv);
            bubdiv = null;
        },
        color: function (color) {
            var ch = bubdiv.childNodes;
            color = (color ? " " + color : "");
            bubdiv.className = "bubble" + color;
            ch[0].className = "bubtail0 " + dir + color;
            ch[2].className = "bubtail1 " + dir + color;
        },
        content: function (content) {
            var n = bubdiv.childNodes[1];
            if (typeof content == "string")
                n.innerHTML = content;
            else {
                while (n.childNodes.length)
                    n.removeChild(n.childNodes[0]);
                if (content)
                    n.appendChild(content);
            }
            position_tail();
        }
    };

    bubble.content(content);
    return bubble;
}


// thank you David Flanagan
var Geometry = null;
if (window.innerWidth) {
    Geometry = function () {
        return {
            left: window.pageXOffset,
            top: window.pageYOffset,
            width: window.innerWidth,
            height: window.innerHeight,
            right: window.pageXOffset + window.innerWidth,
            bottom: window.pageYOffset + window.innerHeight
        };
    };
} else if (document.documentElement && document.documentElement.clientWidth) {
    Geometry = function () {
        var e = document.documentElement;
        return {
            left: e.scrollLeft,
            top: e.scrollTop,
            width: e.clientWidth,
            height: e.clientHeight,
            right: e.scrollLeft + e.clientWidth,
            bottom: e.scrollTop + e.clientHeight
        };
    };
} else if (document.body.clientWidth) {
    Geometry = function () {
        var e = document.body;
        return {
            left: e.scrollLeft,
            top: e.scrollTop,
            width: e.clientWidth,
            height: e.clientHeight,
            right: e.scrollLeft + e.clientWidth,
            bottom: e.scrollTop + e.clientHeight
        };
    };
}


// popup dialogs
function popup(anchor, which, dofold, populate) {
    var elt = $$("popup_" + which), form, elts, populates, i, xelt, type;
    if (elt && dofold)
        elt.className = "popupc";
    else if (elt && Geometry) {
        if (!anchor)
            anchor = $$("popupanchor_" + which);
        var anchorPos = eltPos(anchor);
        var wg = Geometry();
        elt.className = "popupo";
        var x = (anchorPos.right + anchorPos.left - elt.offsetWidth) / 2;
        var y = (anchorPos.top + anchorPos.bottom - elt.offsetHeight) / 2;
        elt.style.left = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) + "px";
        elt.style.top = Math.max(wg.top + 5, Math.min(wg.bottom - 5 - elt.offsetHeight, y)) + "px";
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
            resultelt.innerHTML = rv.response;
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
        var i;
        if (req.readyState != 4)
            return;
        clearTimeout(timer);
        if (req.status == 200) {
            resultelt.innerHTML = "";
            var rv = jQuery.parseJSON(req.responseText);
            callback(rv);
            if (rv.ok)
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
function getorpost(method, url, callback, timeout) {
    callback = callback || function () {};
    var req = newRequest(), timer = setTimeout(function () {
            req.abort();
            callback(null);
        }, timeout ? timeout : 4000);
    req.onreadystatechange = function () {
        if (req.readyState != 4)
            return;
        clearTimeout(timer);
        if (req.status == 200)
            callback(jQuery.parseJSON(req.responseText));
        else
            callback(null);
    };
    req.open(method, url);
    req.send(null); /* old Firefox needs an arg */
    return false;
};
Miniajax.get = function (url, callback, timeout) {
    return getorpost("GET", url, callback, timeout);
};
Miniajax.post = function (url, callback, timeout) {
    return getorpost("POST", url, callback, timeout);
};
Miniajax.getjsonp = function (url, callback, timeout) {
    // Written with reference to jquery
    var head, script, timer, cbname = "mjp" + jsonp;
    function readystatechange(_, isAbort) {
        var err;
        try {
            if (isAbort || !script.readyState || /loaded|complete/.test(script.readyState)) {
                script.onload = script.onreadystatechange = null;
                if (head && script.parentNode)
                    head.removeChild(script);
                script = undefined;
                window[cbname] = function () {};
                if (timer) {
                    clearTimeout(timer);
                    timer = null;
                }
            }
        } catch (err) {
        }
    }
    timer = setTimeout(function () {
            timer = null;
            callback(null);
            readystatechange(null, true);
        }, timeout ? timeout : 4000);
    window[cbname] = callback;
    head = document.head || document.getElementsByTagName("head")[0] || document.documentElement;
    script = document.createElement("script");
    script.async = "async";
    script.src = url.replace(/=\?/, "=" + cbname);
    script.onload = script.onreadystatechange = readystatechange;
    head.insertBefore(script, head.firstChild);
    ++jsonp;
};
Miniajax.isoutstanding = function (formname, callback) {
    var myoutstanding = outstanding[formname];
    myoutstanding && callback && myoutstanding.push(callback);
    return !!myoutstanding;
};
return Miniajax;
})();


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
    window.scrollTo(wp.x, Math.max(0, wp.y, dh - wp.h));
}

function strftime(format, date) {
    var s = "", i, ch, x;
    for (i = 0; i != format.length; ++i) {
        ch = format.charAt(i);
        if (ch != "%" || i == format.length - 1)
            s += ch;
        else {
            ch = format.charAt(++i);
            if (ch == "l") {
                x = date.getHours() % 12;
                s += x ? x : 12;
            } else if (ch == "M") {
                x = date.getMinutes();
                s += (x < 10 ? "0" : "") + x;
            } else if (ch == "S") {
                x = date.getSeconds();
                s += (x < 10 ? "0" : "") + x;
            } else if (ch == "P") {
                x = date.getHours();
                s += x < 12 ? "am" : "pm";
            } else if (ch == "e")
                s += date.getDate();
            else if (ch == "b")
                s += "JanFebMarAprMayJunJulAugSepOctNovDec".substr(date.getMonth() * 3, 3);
            else if (ch == "Y")
                s += date.getYear() + 1900;
        }
    }
    return s;
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
        if (styles) {
            var sclass = [],
                fb = styles.rv ? {fg: "bg", bg: "fg"} : {fg: "fg", bg: "bg"};
            if (styles[fb.fg] != null && styles[fb.fg] != 9)
                sclass.push("ansifg" + styles[fb.fg]);
            if (styles[fb.bg] != null && styles[fb.bg] != 9)
                sclass.push("ansibg" + styles[fb.bg]);
            if (styles.b)
                sclass.push("ansib");
            if (styles.i)
                sclass.push("ansii");
            if (styles.u)
                sclass.push("ansiu");
            if (styles.rv)
                sclass.push("ansirv");
            if (sclass.length) {
                var decor = document.createElement("span");
                decor.className = sclass.join(" ");
                decor.appendChild(text);
                text = decor;
            }
        }
        node.appendChild(text);
    }

    function render_line(line, node, continuation) {
        var m, filematch, dir, a, i, x, isnew = !node;
        node = node || document.createElement("span");

        var linerest = null;
        if (line.length > 132) {
            linerest = line.substr(132);
            line = line.substr(0, 132) + "…\n";
        }

        if (!continuation && (m = line.match(/^([^:\s]+):(\d+):/))) {
            filematch = jQuery(".filediff61[run61file='" + m[1] + "']");
            if (!filematch.length && (dir = therun.attr("run61directory")))
                filematch = jQuery(".filediff61[run61file='" + dir + "/" + m[1] + "']");
            if (filematch.length) {
                x = "Lb" + m[2] + "_" + filematch.attr("run61fileid");
                if (document.getElementById(x)) {
                    a = jQuery("<a href=\"#" + x + "\" onclick=\"return gotoline61(this)\"></a>");
                    a.text(m[1] + ":" + m[2]);
                    addlinepart(node, a);
                    line = line.substr(1 + m[1].length + m[2].length);
                }
            }
        }

        while ((m = line.match(/^(.*?)\x1b\[([\d;]+)m([^]*)$/))) {
            if (m[1] !== "")
                addlinepart(node, m[1]);
            a = m[2].split(/;/);
            for (i = 0; i < a.length; ++i) {
                if (a[i] == "")
                    /* do nothing */;
                else if (+a[i] == 0)
                    styles = null;
                else if (run61.ansimap[+a[i]] != null)
                    styles = jQuery.extend({}, styles, run61.ansimap[+a[i]]);
            }
            line = m[3];
        }

        addlinepart(node, line);
        if (isnew)
            thepre[0].insertBefore(node, thepre[0].lastChild);

        if (linerest !== null)
            render_line(linerest, null, true);
    }

    function ends_with_newline(str) {
        return str !== "" && str.charAt(str.length - 1) === "\n";
    }

    function append(str) {
        var atbottom = therun.scrollTop() >= therun.children().height() - therun.height() - 10;

        // hide newline on last line
        var lines = str.split(/^/m);
        if (lines.length && lines[lines.length - 1] === "")
            lines.pop();
        var last = lines[lines.length - 1];
        var lastfull = ends_with_newline(last);
        if (lastfull)
            lines[lines.length - 1] = last.substring(0, last.length - 1);

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

        var laststyles = styles;
        for (i = 0; i < lines.length; ++i) {
            laststyles = styles;
            render_line(lines[i], i ? null : node);
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

run61.ansimap = {
    "1": {b: true}, "3": {i: true}, "4": {u: true}, "7": {rv: true},
    "9": {s: true},
    "22": {b: false}, "23": {i: false}, "24": {u: false}, "27": {rv: false},
    "29": {s: false},
    "30": {fg: 0}, "31": {fg: 1}, "32": {fg: 2}, "33": {fg: 3},
    "34": {fg: 4}, "35": {fg: 5}, "36": {fg: 6}, "37": {fg: 7}, "39": {fg: 9},
    "40": {bg: 0}, "41": {bg: 1}, "42": {bg: 2}, "43": {bg: 3},
    "44": {bg: 4}, "45": {bg: 5}, "46": {bg: 6}, "47": {bg: 7}, "49": {bg: 9}
};

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
(function ($) {
    $.fn.autogrow = function (options)
    {
        return this.filter('textarea').each(function()
        {
            var self         = this;
            var $self        = $(self);
            var minHeight    = $self.height();
            var noFlickerPad = $self.hasClass('autogrow-short') ? 0 : parseInt($self.css('lineHeight')) || 0;
            var settings = $.extend({
                preGrowCallback: null,
                postGrowCallback: null
              }, options );

            var shadow = $('<div></div>').css({
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

            var update = function(event)
            {
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

            $self.on("change keyup", update).keydown({event:'keydown'},update);
            $(window).resize(update);

            update();
        });
    };
})(jQuery);
