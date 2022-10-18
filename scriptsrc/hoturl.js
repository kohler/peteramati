// hoturl.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

function encodeURIComponentPlus(s) {
    return encodeURIComponent(s).replace(/%20/g, "+");
}

function serialize_object(x) {
    if (typeof x === "string") {
        return x;
    } else if (x) {
        var k, v, a = [""];
        for (k in x) {
            if ((v = x[k]) != null)
                a.push(encodeURIComponent(k), "=", encodeURIComponentPlus(v), "&");
        }
        a[a.length - 1] = "";
        return a.join("");
    } else {
        return "";
    }
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

function hoturl_psetinfo(elt, page, args) {
    const p = elt.closest(".pa-psetinfo");
    let v;
    if ((v = p.getAttribute("data-pa-user") || siteinfo.uservalue)) {
        args.push("u=" + encodeURIComponent(v));
    }
    if ((v = p.getAttribute("data-pa-pset"))) {
        args.push("pset=" + encodeURIComponent(v));
    }
    if ((v = p.getAttribute("data-pa-commit"))) {
        args.push("commit=" + encodeURIComponent(v));
    }
    let sheet;
    if ((page === "api/grade" || page === "=api/grade")
        && (sheet = p.pa__gradesheet)) {
        const enames = [];
        for (let i in sheet.entries) {
            enames.push(i);
        }
        args.push("knowngrades=" + encodeURIComponentPlus(enames.join(" ")));
    }
}

export function hoturl(page, options) {
    let k, v, m, x, xv, anchor = "";
    if (siteinfo.site_relative == null || siteinfo.suffix == null) {
        siteinfo.site_relative = siteinfo.suffix = "";
    }

    if (typeof options === "string") {
        if ((m = options.match(/^(.*?)(#.*)$/))) {
            options = m[1];
            anchor = m[2];
        }
        xv = options.split(/&/);
    } else {
        xv = [];
        for (k in options) {
            v = options[k];
            if (v == null) {
                /* do nothing */
            } else if (k === "anchor") {
                anchor = "#" + v;
            } else if (k === "psetinfo" && v instanceof Element) {
                hoturl_psetinfo(v, page, xv);
            } else {
                xv.push(encodeURIComponent(k).concat("=", encodeURIComponentPlus(v)));
            }
        }
    }

    if (page.startsWith("=")) {
        xv.push("post=" + siteinfo.postvalue);
        page = page.substring(1);
    }
    x = {pt: "", v: xv, t: page + siteinfo.suffix};

    if (page === "help") {
        hoturl_clean(x, /^t=(\w+)$/);
    } else if (page.substr(0, 3) === "api") {
        if (page.length > 3) {
            x.t = "api" + siteinfo.suffix;
            xv.push("fn=" + page.substr(4));
        }
        hoturl_clean_before(x, /^u=([^?&#]+)$/, "~");
        hoturl_clean(x, /^fn=(\w+)$/);
        hoturl_clean(x, /^pset=([^?&#]+)$/);
        hoturl_clean(x, /^commit=([0-9A-Fa-f]+)$/);
    } else if (page === "index") {
        hoturl_clean_before(x, /^u=([^?&#]+)$/, "~");
    } else if (page === "pset" || page === "run") {
        hoturl_clean_before(x, /^u=([^?&#]+)$/, "~");
        hoturl_clean(x, /^pset=([^?&#]+)$/);
        hoturl_clean(x, /^commit=([0-9A-Fa-f]+)$/);
    }

    if (siteinfo.defaults) {
        xv.push(serialize_object(siteinfo.defaults));
    }
    if (xv.length) {
        x.t += "?" + xv.join("&");
    }
    return siteinfo.site_relative + x.pt + x.t + anchor;
}

export function url_absolute(url, loc) {
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

export function hoturl_absolute_base() {
    if (!siteinfo.absolute_base) {
        siteinfo.absolute_base = url_absolute(siteinfo.base);
    }
    return siteinfo.absolute_base;
}

export function hoturl_post_go(page, options) {
    const form = document.createElement("form");
    form.setAttribute("method", "post");
    form.setAttribute("enctype", "multipart/form-data");
    form.setAttribute("accept-charset", "UTF-8");
    form.action = hoturl(page, options);
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = "____empty____";
    input.value = "1";
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}
