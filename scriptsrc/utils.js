// utils.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

// localStorage
export let wstorage = function () { return false; };
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


export function sprintf(fmt) {
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

const unparsers = {
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

export function strftime(fmt, d) {
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
}
