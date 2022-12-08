// encoders.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

const ee_re = /[&<>\"']/g,
    ee_rep = {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#39;"};

export function escape_entities(s) {
    if (s !== null && typeof s !== "number")
        return s.replace(ee_re, function (match) { return ee_rep[match]; });
    else
        return s;
}

const ue_re = /&.*?;/g,
    ue_rep = {"&amp;": "&", "&lt;": "<", "&gt;": ">", "&quot;": "\"", "&apos;": "'", "&#039;": "'", "&#39;": "'"};

export function unescape_entities(s) {
    if (s !== null && typeof s !== "number")
        return s.replace(ue_re, function (match) { return ue_rep[match]; });
    else
        return s;
}

const urle_re = /%20|[!~*'()]/g,
    urle_rep = {"%20": "+", "!": "%21", "~": "%7E", "*": "%2A", "'": "%27", "(": "%28", ")": "%29"};

export function urlencode(s) {
    if (s !== null && typeof s !== "number")
        return encodeURIComponent(s).replace(urle_re, function (match) { return urle_rep[match]; });
    else
        return s;
}

export function urldecode(s) {
    if (s !== null && typeof s !== "number")
        return decodeURIComponent(s.replace(/\+/g, "%20"));
    else
        return s;
}

export function text_to_html(text) {
    var n = document.createElement("div");
    n.appendChild(document.createTextNode(text));
    return n.innerHTML;
}

export function regexp_quote(s) {
    return String(s).replace(/([-()\[\]{}+?*.$\^|,:#<!\\])/g, '\\$1').replace(/\x08/g, '\\x08');
}

export function html_id_encode(s) {
    return encodeURIComponent(s).replace(/[^-A-Za-z0-9_.%]|%../g, function (s) {
        if (s.length === 1) {
            return "@" + s.charCodeAt(0).toString(16);
        } else if (s === "%2F") {
            return "/";
        } else {
            return "@" + s.substring(1);
        }
    });
}

export function html_id_decode(s) {
    return decodeURIComponent(s.replace(/@/g, "%"));
}
