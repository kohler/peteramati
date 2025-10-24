// cluster-diff.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, handle_ui } from "./ui.js";
import { GradeSheet } from "./gradeentry.js";

function pa_cluster_diff() {
    const cgi = GradeSheet.closest(this),
        cpi = this.closest(".pa-psetinfo"),
        gkey = this.getAttribute("data-pa-grade");
    if (!cgi || !cpi || !gkey) {
        return;
    }
    const ge = cgi.xentry(gkey);
    if (!ge) {
        return;
    }

    // collect info
    const sxs = [];
    let max_index = 0, any_unindexed = false, last = null;
    for (let e = cpi.firstElementChild; e; e = e.nextElementSibling) {
        if (!hasClass(e, "pa-psetinfo")) {
            sxs.length || (last = e);
            continue;
        }
        const sgi = GradeSheet.closest(e), index = +e.getAttribute("data-pa-index");
        sxs.push({
            element: e,
            value: ge.value_in(sgi),
            index: index,
            sorted: false
        });
        max_index = Math.max(max_index, index);
        any_unindexed = any_unindexed || index === 0;
    }
    if (any_unindexed) {
        for (const sx of sxs) {
            if (sx.index === 0) {
                ++max_index;
                sx.index = max_index;
                sx.element.setAttribute("data-pa-index", max_index);
            }
        }
    }

    // cluster
    let ckey = this.getAttribute("data-pa-clustering"), carg = "";
    const colon = ckey.indexOf(":");
    if (colon >= 0) {
        carg = ckey.substring(colon + 1);
        ckey = ckey.substring(0, colon);
    }
    if (ckey === "none") {
        cluster_by_none(sxs);
    } else if (ckey === "multiple-choice") {
        cluster_by_multiple_choice(sxs, carg);
    } else {
        cluster_by_length(sxs);
    }

    // apply clustering
    last = last.nextElementSibling;
    for (const sx of sxs) {
        if (sx.element !== last) {
            cpi.insertBefore(sx.element, last);
        } else {
            last = last.nextElementSibling;
        }
    }
}

function cluster_by_none(sxs) {
    const coll = new Intl.Collator("en-US", {numeric: true});
    sxs.sort((a, b) => {
        const aid = a.element.id, bid = b.element.id;
        return aid && bid ? coll.compare(aid, bid) : a.index - b.index;
    });
}

function cluster_by_length(sxs) {
    sxs.sort((a, b) => {
        const av = (a.value || "").length, bv = (b.value || "").length;
        if (av !== bv) {
            return av < bv ? 1 : -1;
        }
        return a.index < b.index ? -1 : 1;
    });
}

function analyze_multiple_choice(v, mcallow) {
    if (!v) {
        return "~~~";
    }
    v = v.replace(/\([^)]+\)|:.*$|becau?se.*$/gm, "").toUpperCase();
    let m, vx = [];
    if ((m = v.match(/^((?:\d+|[A-Z])(?:[\s,]+(?:AND\s+)?(?:\d+|[A-Z]))*)(?!\w)/m))) {
        vx = m[1].replace(/[,\s]+(?:AND\s+)?/g, " ").split(" ");
    } else if ((m = v.matchAll(/^(\d+|[A-Z])(?!\w)/mg)).length) {
        for (const mx of m) {
            vx.push(mx[1]);
        }
    }
    let vxx = [];
    for (const zz of vx) {
        if (zz !== "" && (!mcallow || mcallow[zz]))
            vxx.push(zz);
    }
    if (vxx.length === 0) {
        return "?";
    }
    return vxx.sort().join(" ");
}

function cluster_by_multiple_choice(sxs, mcarg) {
    let mcallow = null;
    if (mcarg && /^[A-Z]$/.test(mcarg)) {
        mcallow = {};
        for (let ch = "A"; ch <= mcarg; ch = String.fromCharCode(ch.charCodeAt(0) + 1)) {
            mcallow[ch] = true;
        }
    } else if (mcarg && /^\d+$/.test(mcarg)) {
        mcallow = {};
        for (let n = 1; n <= +mcarg; ++n) {
            mcallow[n] = true;
        }
    } else if (mcarg && /^[-\d,]+$/.test(mcarg)) {
        mcallow = {};
        for (const x of mcarg.split(/,/)) {
            const dash = x.indexOf("-");
            if (dash > 0) {
                const last = +x.substring(dash + 1);
                for (let i = +x.substring(0, dash); i <= last; ++i) {
                    mcallow[i] = true;
                }
            } else if (x) {
                mcallow[+x] = true;
            }
        }
    }

    for (const sx of sxs) {
        sx.multiple_choice = analyze_multiple_choice(sx.value, mcallow);
    }

    sxs.sort((a, b) => {
        const amc = a.multiple_choice, bmc = b.multiple_choice;
        if (amc !== bmc) {
            return amc < bmc ? -1 : 1;
        }
        return a.index < b.index ? -1 : 1;
    });
}

handle_ui.on("pa-cluster-diff", pa_cluster_diff);
