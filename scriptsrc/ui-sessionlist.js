// ui-sessionlist.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { wstorage } from "./utils.js";
import { hoturl_absolute_base } from "./hoturl.js";
import { after_outstanding } from "./xhr.js";
import { hasClass, handle_ui } from "./ui.js";
import { event_key } from "./ui-key.js";


let cookie_set_at;

function make_link_callback(elt) {
    return function () {
        window.location = elt.href;
    };
}

function list_digest_update(info) {
    var add = typeof info === "string" ? 1 : 0,
        digests = wstorage.site_json(false, "list_digests") || [],
        found = -1, now = new Date().getTime();
    for (var i = 0; i < digests.length; ++i) {
        var digest = digests[i];
        if (digest[add] === info) {
            found = i;
        } else if (digest[2] < now - 30000) {
            digests.splice(i, 1);
            --i;
        } else if (now <= digest[0]) {
            now = digest[0] + 1;
        }
    }
    if (found >= 0) {
        digests[found][2] = now;
    } else if (add) {
        digests.push([now, info, now]);
        found = digests.length - 1;
    }
    wstorage.site(false, "list_digests", digests);
    if (found >= 0) {
        return digests[found][1 - add];
    } else {
        return false;
    }
}

function list_digest_create(info) {
    var digest = list_digest_update(info);
    return digest ? "list" + digest : info;
}

function list_digest_resolve(info) {
    var xinfo, m;
    if ((m = /^list(\d+)(?=\s|$)/.exec(info))
        && (xinfo = list_digest_update(+m[1]))) {
        return xinfo;
    } else {
        return info;
    }
}

function set_prevnext(info, sitehref) {
    var m = /(?:^|\/)(~[^\/]+)\/pset(?:|\.php)\/([^\/]+)((?:\/[0-9a-f]+)?)(?=[\/?#]|$)/.exec(sitehref), want1, want2;
    if (m) {
        info = JSON.parse(info);
        if (!info.pset) {
            want2 = m[1] + "/pset/" + m[2];
            want1 = want2 + m[3];
        } else if (info.pset === m[2]) {
            want1 = want2 = m[1];
        } else {
            return "";
        }
        var idx = info.items.indexOf(want1);
        if (idx < 0 && want2 !== want1) {
            idx = info.items.indexOf(want2);
        }
        if (idx >= 0) {
            return " " + JSON.stringify({
                cur: m[1] + "/pset/" + m[2],
                prev: idx === 0 ? null : info.items[idx-1],
                next: idx === info.items.length - 1 ? null : info.items[idx+1]
            });
        }
    }
    return "";
}

function set_cookie(info, sitehref) {
    info = list_digest_resolve(info);
    var digest = list_digest_create(info);
    if (sitehref && /(?:^|\/)pset(?:|\.php)\//.test(sitehref)) {
        digest += set_prevnext(info, sitehref);
    }
    cookie_set_at = new Date().getTime();
    var p = "; Max-Age=20", m;
    if (siteinfo.site_relative && (m = /^[a-z]+:\/\/[^\/]*(\/.*)/.exec(hoturl_absolute_base()))) {
        p += "; Path=" + m[1];
    }
    document.cookie = "hotlist-info-" + cookie_set_at + "=" + encodeURIComponent(digest) + siteinfo.cookie_params + p;
}

function is_listable(sitehref) {
    return /(?:^|\/)pset(?:|\.php)(?:$|\/)/.test(sitehref);
}

function handle_list(e, href) {
    var hl, sitehref;
    if (href
        && href.startsWith(siteinfo.site_relative)
        && is_listable((sitehref = href.substring(siteinfo.site_relative.length)))
        && (hl = e.closest(".has-hotlist"))) {
        var info = hl.getAttribute("data-hotlist");
        if (!info) {
            var event = jQuery.Event("pa-hotlist");
            $(hl).trigger(event);
            info = event.hotlist;
            if (info && typeof info !== "string") {
                info = JSON.stringify(info);
            }
        }
        info && set_cookie(info, sitehref);
    }
}

function unload_list() {
    var hl = document.body.getAttribute("data-hotlist");
    if (hl && (!cookie_set_at || cookie_set_at + 3 < new Date().getTime())) {
        set_cookie(hl, location.href);
    }
}

function list_default_click() {
    var base = location.href;
    if (location.hash) {
        base = base.substring(0, base.length - location.hash.length);
    }
    if (this.href.substring(0, base.length + 1) === base + "#") {
        return true;
    } else if (after_outstanding()) {
        after_outstanding(make_link_callback(this));
        return true;
    } else {
        return false;
    }
}

$(document).on("click", "a", function (evt) {
    if (!hasClass(this, "ui")) {
        if (!event_key.is_default_a(evt)
            || this.target
            || !list_default_click.call(this, evt)) {
            handle_list(this, this.getAttribute("href"));
        }
    }
});

$(document).on("submit", "form", function (evt) {
    if (hasClass(this, "ui-submit")) {
        evt.preventDefault();
        handle_ui.call(this, evt);
    } else {
        handle_list(this, this.getAttribute("action"));
    }
});

$(window).on("beforeunload", unload_list);

$(function () {
    // resolve list digests
    $(".has-hotlist").each(function () {
        var info = this.getAttribute("data-hotlist");
        if (info
            && info.startsWith("list")
            && (info = list_digest_resolve(info))) {
            this.setAttribute("data-hotlist", info);
        }
    });
});
