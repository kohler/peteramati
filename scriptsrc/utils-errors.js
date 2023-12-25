// utils-errors.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hoturl } from "./hoturl.js";

export function log_jserror(errormsg, error, noconsole) {
    if (!error && errormsg instanceof Error) {
        error = errormsg;
        errormsg = {"error": error.toString()};
    } else if (typeof errormsg === "string") {
        errormsg = {"error": errormsg};
    }
    if (error && error.fileName && !errormsg.url) {
        errormsg.url = error.fileName;
    }
    if (error && error.lineNumber && !errormsg.lineno) {
        errormsg.lineno = error.lineNumber;
    }
    if (error && error.columnNumber && !errormsg.colno) {
        errormsg.colno = error.columnNumber;
    }
    if (error && error.stack) {
        errormsg.stack = error.stack;
    }
    $.ajax(hoturl("=api/jserror"), {
        global: false, method: "POST", cache: false, data: errormsg
    });
    if (error && !noconsole && typeof console === "object" && console.error) {
        console.error(errormsg.error);
    }
}


let old_onerror = window.onerror, nerrors_logged = 0;
window.onerror = function (errormsg, url, lineno, colno, error) {
    if ((url || !lineno)
        && errormsg.indexOf("ResizeObserver loop limit exceeded") < 0
        && ++nerrors_logged <= 10) {
        var x = {error: errormsg, url: url, lineno: lineno};
        if (colno) {
            x.colno = colno;
        }
        log_jserror(x, error, true);
    }
    return old_onerror ? old_onerror.apply(this, arguments) : false;
};


$(function () {
    function locator(e) {
        var p = [];
        while (e && e.nodeName !== "BODY" && e.nodeName !== "MAIN") {
            var t = e.nodeName, s = e.className.replace(/\s+/g, ".");
            if (e.id !== "") {
                t += "#" + e.id;
            }
            if (s !== "") {
                t += "." + s;
            }
            p.push(t);
            e = e.parentElement;
        }
        p.reverse();
        return p.join(">");
    }
    var err = [], elt = [];
    $("button.btn-link,button.btn-qlink,button.btn-qolink,.btn-xlink,.btn-ulink,.btn-disabled,a.qx,a.u,a.x").each(function () {
        err.push(locator(this));
        elt.push(this);
    });
    if (err.length > 0) {
        if (window.console) {
            console.log(err.join("\n"));
            for (var i = 0; i !== elt.length; ++i) {
                console.log(elt[i]);
            }
        }
        log_jserror(err.join("\n"));
    }
});
