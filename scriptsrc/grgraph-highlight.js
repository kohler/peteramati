// grgraph-highlight.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, handle_ui } from "./ui.js";
import { push_history_state } from "./ui-history.js";


function hash_parse(hash) {
    hash = hash.replace(/^[^#]*/, "");
    let regex = /([^#=/]+)[=/]?([^/]*)/g, m, h = {};
    while ((m = regex.exec(hash))) {
        h[m[1]] = decodeURIComponent(m[2].replace(/\+/g, " "));
    }
    return h;
}

function hash_unparse(h) {
    const a = [];
    for (var k in h) {
        if (h[k] === null || h[k] === undefined || h[k] === "") {
            a.push(k);
        } else {
            a.push(k + "=" + encodeURIComponent(h[k]).replace(/%20/g, "+"));
        }
    }
    a.sort();
    return a.length ? "#" + a.join("/") : "";
}

function hash_modify(changes) {
    const h = hash_parse(location.hash);
    for (let k in changes) {
        if (changes[k] === null || changes[k] === undefined) {
            delete h[k];
        } else {
            h[k] = changes[k];
        }
    }
    const newhash = hash_unparse(h);
    if (newhash !== location.hash) {
        push_history_state(location.origin + location.pathname + location.search + newhash);
    }
}

function want_grgraph_hash() {
    return hasClass(document.body, "want-grgraph-hash");
}


function update(where, color, attr) {
    const key = "data-pa-highlight" + (color === "main" ? "" : "-" + color);
    attr = attr || "";
    $(where).find(".pa-grgraph").each(function () {
        const old_attr = this.getAttribute(key) || "", that = this;
        if (old_attr !== attr
            && (color === "main" || this.getAttribute("data-pa-pset") !== "course")) {
            attr ? this.setAttribute(key, attr) : this.removeAttribute(key);
            window.requestAnimationFrame(function () {
                const gg = $(that).data("paGradeGraph");
                gg && gg.highlight_users();
            });
        }
    });
}

function course_xcdf() {
    let xd = null;
    $(".pa-grgraph[data-pa-pset=course]").each(function () {
        const d = $(this).data("paGradeData");
        if (d && d.series.all && d.series.all.cdfu) {
            xd = d.series.all;
            return false;
        }
    });
    return xd;
}

function update_course(str, tries) {
    const ranges = {}, colors = {};
    let any = false;
    for (let range of str.match(/[-\d.]+/g) || []) {
        ranges[range] = true;
    }
    $(".js-grgraph-highlight-course").each(function () {
        const range = this.getAttribute("data-pa-highlight-range") || "90-100",
            color = this.getAttribute("data-pa-highlight-type") || "h00";
        colors[color] = colors[color] || [];
        if ((this.checked = !!ranges[range])) {
            let m = range.match(/^([-+]?(?:\d+\.?\d*|\.\d+))-([-+]?(?:\d+\.?\d*|\.\d))(\.?)$/);
            if (m) {
                colors[color].push(+m[1], +m[2] + (m[3] ? 0.00001 : 0));
                any = true;
            } else if ((m = range.match(/^([-+]?(?:\d+\.?\d*|\.\d+))-$/))) {
                colors[color].push(+m[1], Infinity);
                any = true;
            } else {
                throw new Error("bad range");
            }
        }
    });
    let xd;
    if (!any) {
        for (let color in colors) {
            update(document.body, color, "");
        }
    } else if ((xd = course_xcdf())) {
        for (let color in colors) {
            const ranges = colors[color], a = [];
            if (ranges.length) {
                for (let i = 0; i !== xd.cdf.length; i += 2) {
                    for (let j = 0; j !== ranges.length; j += 2) {
                        if (xd.cdf[i] >= ranges[j] && xd.cdf[i] < ranges[j+1]) {
                            const ui0 = i ? xd.cdf[i-1] : 0;
                            Array.prototype.push.apply(a, xd.cdfu.slice(ui0, xd.cdf[i+1]));
                            break;
                        }
                    }
                }
                a.sort();
            }
            update(document.body, color, a.join(" "));
        }
    } else {
        setTimeout(function () {
            update_course(hash_parse(location.hash).hr || "", tries + 1);
        }, 8 << Math.max(tries, 230));
    }
}

function update_hash(href) {
    const h = hash_parse(href);
    update(document.body, "main", h.hs || "");
    $("input[type=checkbox].js-grgraph-highlight").prop("checked", false);
    for (let uid of (h.hs || "").match(/\d+/g) || []) {
        $("tr[data-pa-uid=" + uid + "] input[type=checkbox].js-grgraph-highlight").prop("checked", true);
    }
    update_course(h.hr || "", 0);
    $(".js-grgraph-highlight-course").prop("checked", false);
    for (let range of (h.hr || "").match(/[-\d.]+/g) || []) {
        $(".js-grgraph-highlight-course[data-pa-highlight-range=\"" + range + "\"]").prop("checked", true);
    }
}

function change_highlight(f, range) {
    const a = [];
    for (let e of f.querySelectorAll("input[type=checkbox]")) {
        let tr;
        if (e.checked
            && e.getAttribute("data-range-type") === range
            && (tr = e.closest("tr"))
            && tr.hasAttribute("data-pa-uid"))
            a.push(+tr.getAttribute("data-pa-uid"));
    }
    a.sort((x, y) => x - y);
    if (want_grgraph_hash()) {
        hash_modify({hs: a.length ? a.join(" ") : null});
        update(document.body, "main", a.join(" "));
    } else {
        update(f, "main", a.join(" "));
    }
    f.removeAttribute("data-pa-grgraph-hl-scheduled-" + range);
}

handle_ui.on("change.js-grgraph-highlight", function () {
    const range = this.getAttribute("data-range-type"),
        f = this.closest("form");
    if (!f.hasAttribute("data-pa-grgraph-hl-scheduled-" + range)) {
        f.setAttribute("data-pa-grgraph-hl-scheduled-" + range, "");
        queueMicrotask(() => change_highlight(f, range));
    }
});

handle_ui.on("js-grgraph-highlight-course", function () {
    const a = [];
    $(".js-grgraph-highlight-course").each(function () {
        if (this.checked)
            a.push(this.getAttribute("data-pa-highlight-range"));
    });
    if (want_grgraph_hash()) {
        hash_modify({hr: a.length ? a.join(" ") : null});
    }
    update_course(a.join(" "), 0);
});

if (want_grgraph_hash()) {
    $(window).on("popstate", function (event) {
        const state = (event.originalEvent || event).state;
        state && state.href && update_hash(state.href);
    }).on("hashchange", function () {
        update_hash(location.href);
    });
    $(function () { update_hash(location.href); });
}
