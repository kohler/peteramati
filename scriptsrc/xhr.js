// xhr.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { log_jserror } from "./utils-errors.js";

function jqxhr_error_ftext(jqxhr, status, errormsg) {
    if (status === "parsererror")
        return "<0>Internal error: bad response from server";
    else if (errormsg)
        return "<0>" + errormsg.toString();
    else if (status === "timeout")
        return "<0>Connection timed out";
    else if (status)
        return "<0>Failed [" + status + "]";
    else
        return "<0>Failed";
}


function check_message_list(data, options) {
    if (typeof data === "object") {
        if (data.message_list && !$.isArray(data.message_list)) {
            log_jserror(options.url + ": bad message_list");
            data.message_list = [{message: "<0>Internal error", status: 2}];
        } else if (data.error && !data.message_list) {
            // log_jserror(options.url + ": `error` obsolete"); // XXX backward compat
            data.message_list = [{message: "<0>" + data.error, status: 2}];
        } else if (data.warning) {
            log_jserror(options.url + ": `warning` obsolete"); // XXX backward compat
        }
    }
}

function check_sessioninfo(data, options) {
    if (siteinfo.user.cid == data.sessioninfo.cid) {
        siteinfo.postvalue = data.sessioninfo.postvalue;
        $("form").each(function () {
            var m = /^([^#]*[&?;]post=)([^&?;#]*)/.exec(this.action);
            if (m) {
                this.action = m[1].concat(siteinfo.postvalue, this.action.substring(m[0].length));
            }
            this.elements.post && (this.elements.post.value = siteinfo.postvalue);
        });
    } else {
        $("form").each(function () {
            this.elements.sessionreport && (this.elements.sessionreport = options.url.concat(": bad response ", JSON.stringify(data.sessioninfo), ", current user ", JSON.stringify(siteinfo.user)));
        });
    }
}

$(document).ajaxError(function (event, jqxhr, settings, httperror) {
    if (jqxhr.readyState != 4) {
        return;
    }
    let data;
    if (jqxhr.responseText && jqxhr.responseText.charAt(0) === "{") {
        try {
            data = JSON.parse(jqxhr.responseText);
        } catch (e) {
        }
    }
    check_message_list(data, settings);
    if (jqxhr.status !== 502) {
        let msg = new URL(settings.url, document.baseURI).href + " API failure: ";
        if (siteinfo.user && siteinfo.user.email) {
            msg += "user " + siteinfo.user.email + ", ";
        }
        msg += jqxhr.status;
        if (httperror) {
            msg += ", " + httperror;
        }
        if (jqxhr.responseText) {
            msg += ", " + jqxhr.responseText.substring(0, 100);
        }
        log_jserror(msg);
    }
});


let tracked_out = 0, tracked_after = [];

$.ajaxPrefilter(function (options /* originalOptions, jqxhr */) {
    if (options.global === false) {
        return;
    }
    let success = options.success || [], error = options.error;
    function onsuccess(data) {
        check_message_list(data, options);
        if (typeof data === "object"
            && data.sessioninfo
            && options.url.startsWith(siteinfo.site_relative)
            && (siteinfo.site_relative !== "" || !/^(?:[a-z][-a-z0-9+.]*:|\/|\.\.(?:\/|\z))/i.test(options.url))) {
            check_sessioninfo(data, options);
        }
    }
    function onerror(jqxhr, status, errormsg) {
        let rjson;
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
        check_message_list(rjson, options);
        if (!rjson.message_list) {
            rjson.message_list = [{message: jqxhr_error_ftext(jqxhr, status, errormsg), status: 2}];
        }
        for (let i = 0; i !== success.length; ++i) {
            success[i](rjson, jqxhr, status);
        }
    }
    if (!$.isArray(success)) {
        success = [success];
    }
    options.success = [onsuccess];
    if (success.length) {
        Array.prototype.push.apply(options.success, success);
    }
    options.error = [];
    if (error) {
        Array.prototype.push.apply(options.error, $.isArray(error) ? error : [error]);
    }
    options.error.push(onerror);
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
    if (typeof method === "string") {
        method = {method: method};
    }
    return new Promise(function (resolve) {
        api_conditioner.then(function () {
            $.ajax(url, Object.assign({
                data: data, method: "POST", cache: false, dataType: "json",
                success: function (data) {
                    resolve(data);
                    api_conditioner.done();
                }
            }, method || {}));
        });
    });
}

api_conditioner.then = function (f) {
    // call `f` when ready. `f` must call `api_conditioner.done()` when done
    if (cond_out < 5) {
        ++cond_out;
        f();
    } else {
        cond_waiting.push(f);
    }
};

api_conditioner.done = function () {
    --cond_out;
    if (cond_waiting.length) {
        cond_waiting.shift()();
    }
};

api_conditioner.retry = function (f) {
    if (cond_out <= 1) {
        throw new Error("api_conditioner.after called with nothing outstanding");
    }
    --cond_out;
    cond_waiting.push(f);
};
