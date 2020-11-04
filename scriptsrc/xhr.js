// xhr.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

function jqxhr_error_message(jqxhr, status, errormsg) {
    if (status === "parsererror") {
        return "Internal error: bad response from server.";
    } else if (errormsg) {
        return errormsg.toString();
    } else if (status === "timeout") {
        return "Connection timed out.";
    } else if (status) {
        return "Failed [" + status + "].";
    } else {
        return "Failed.";
    }
}


let tracked_out = 0, tracked_after = [];

$.ajaxPrefilter(function (options /* originalOptions, jqxhr */) {
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
                || rjson.ok !== false) {
                rjson = {ok: false};
            }
            if (!rjson.error) {
                rjson.error = jqxhr_error_message(jqxhr, status, errormsg);
            }
            f(rjson, jqxhr, status);
        }
    }
    if (!options.error) {
        options.error = onerror;
    } else if ($.isArray(options.error)) {
        options.error.push(onerror);
    } else{
        options.error = [options.error, onerror];
    }
    if (options.timeout == null) {
        options.timeout = 10000;
    }
    if (options.dataType == null) {
        options.dataType = "json";
    }
    if (options.trackOutstanding) {
        ++tracked_out;
    }
});

$(document).ajaxComplete(function (event, jqxhr, settings) {
    if (settings.trackOutstanding && --tracked_out === 0) {
        while (tracked_after.length) {
            tracked_after.shift()();
        }
    }
});

export function after_outstanding(f) {
    if (f === undefined) {
        return tracked_out > 0;
    } else if (tracked_out > 0) {
        tracked_after.push(f);
    } else {
        f();
    }
}


let cond_out = 0, cond_waiting = [];

export function api_conditioner(url, data, method) {
    return new Promise(function (resolve) {
        function process() {
            ++cond_out;
            $.ajax(url, {
                data: data, method: method || "POST", cache: false, dataType: "json",
                success: function (data) {
                    resolve(data);
                    --cond_out;
                    cond_waiting.length && cond_waiting.shift()();
                }
            });
        }
        cond_out < 5 ? process() : cond_waiting.push(process);
    });
}
