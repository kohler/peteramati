// leaderboard.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2026 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, removeClass, toggleClass, handle_ui, $e } from "./ui.js";
import { hoturl } from "./hoturl.js";
import { strftime } from "./utils.js";
import { escape_entities } from "./encoders.js";
import { tooltip } from "./tooltip.js";

const MAX_ROWS = 25;


// Colors: d3’s interpolatePiYG, adapted from d3-scale-chromatic and
// d3-interpolate by Mike Bostock. t = 0 is dark pink, t = 1 dark green.

const PIYG = "8e0152c51b7dde77aef1b6dafde0eff7f7f7e6f5d0b8e1867fbc414d9221276419"
    .match(/.{6}/g).map(s => [0, 2, 4].map(i => parseInt(s.substring(i, i + 2), 16)));

// Cell backgrounds are tinted, not saturated
const CELL_OPACITY = 0.25;

function basis(t1, v0, v1, v2, v3) {
    const t2 = t1 * t1, t3 = t2 * t1;
    return ((1 - 3 * t1 + 3 * t2 - t3) * v0
            + (4 - 6 * t2 + 3 * t3) * v1
            + (1 + 3 * t1 + 3 * t2 - 3 * t3) * v2
            + t3 * v3) / 6;
}

/** @param {number} t
 * @return {number[]} */
function piyg(t) {
    const n = PIYG.length - 1,
        i = t <= 0 ? (t = 0) : t >= 1 ? (t = 1, n - 1) : Math.floor(t * n);
    return [0, 1, 2].map(c => {
        const v1 = PIYG[i][c], v2 = PIYG[i + 1][c],
            v0 = i > 0 ? PIYG[i - 1][c] : 2 * v1 - v2,
            v3 = i < n - 1 ? PIYG[i + 2][c] : 2 * v2 - v1;
        return Math.max(0, Math.min(255, Math.round(basis((t - i / n) * n, v0, v1, v2, v3))));
    });
}

/** Return `m`’s color range as `[bad, good]`: its configured `range`, or
 * else the range of measured values, oriented by `best`. */
function metric_range(m) {
    if (!m.pa__range) {
        if (m.range) {
            m.pa__range = m.range;
        } else {
            let lo = Infinity, hi = -Infinity;
            for (const e of m.entries) {
                lo = Math.min(lo, e.value);
                hi = Math.max(hi, e.value);
            }
            m.pa__range = m.best === "min" ? [hi, lo] : [lo, hi];
        }
    }
    return m.pa__range;
}

/** Color a cell by where its value falls in `m`’s range: bad is pink, good
 * green. Out-of-range values get the end colors; if every value is the
 * same, the cell is neutral. */
function style_cell(td, m, value) {
    const [bad, good] = metric_range(m);
    let t = 0.5;
    if (bad !== good && isFinite(bad) && isFinite(good)) {
        t = Math.max(0, Math.min(1, (value - bad) / (good - bad)));
    }
    td.style.backgroundColor = `rgba(${piyg(t).join(",")},${CELL_OPACITY})`;
}


// Model

/** Sort `m`’s entries best first and set their `rank`s; ties share a rank. */
function rank_metric(m) {
    const sign = m.best === "min" ? 1 : -1;
    m.entries.sort((a, b) => sign * (a.value - b.value) || a.name.localeCompare(b.name));
    let rank = 0;
    m.entries.forEach((e, i) => {
        if (i === 0 || e.value !== m.entries[i - 1].value) {
            rank = i + 1;
        }
        e.rank = rank;
    });
}

/** Rank each metric and build one row per user, with each metric’s entry;
 * cache the rows on `lb`. */
function rows(lb) {
    if (lb.pa__rows) {
        return lb.pa__rows;
    }
    const byname = new Map;
    for (const m of lb.metrics) {
        rank_metric(m);
        m.pa__count = m.entries.length;
        for (const e of m.entries) {
            let row = byname.get(e.name);
            if (!row) {
                row = {name: e.name, user: lb.users[e.name] || {}, entries: {}};
                byname.set(e.name, row);
            }
            row.entries[m.key] = e;
        }
    }
    lb.pa__rows = Array.from(byname.values());
    return lb.pa__rows;
}

function metric_by_key(lb, key) {
    return lb.metrics.find(m => m.key === key) || null;
}

/** Sort state: `{key, rev}`, where `key` is a metric key or `"name"`. By
 * default, sort by the first metric, best first. */
function sort_state(elt, lb) {
    let s = elt.pa__sort;
    if (!s || (s.key !== "name" && !metric_by_key(lb, s.key))) {
        s = elt.pa__sort = {key: lb.metrics[0].key, rev: false};
    }
    return s;
}

function sorted_rows(lb, s) {
    const rs = rows(lb).slice(), sign = s.rev ? -1 : 1;
    if (s.key === "name") {
        rs.sort((a, b) => sign * a.name.localeCompare(b.name));
    } else {
        // `rows()` ranked the entries; users without an entry always sort
        // last
        rs.sort((a, b) => {
            const ea = a.entries[s.key], eb = b.entries[s.key];
            if (!ea || !eb) {
                return ea ? -1 : (eb ? 1 : a.name.localeCompare(b.name));
            }
            return sign * (ea.rank - eb.rank) || a.name.localeCompare(b.name);
        });
    }
    return rs;
}


// Rendering

function render_user(lb, row) {
    const u = row.user;
    if (!u.name) {
        return $e("span", "dim", "(unknown)");
    }
    const name = $e("code", "pa-leaderboard-name", u.name);
    if (!u.user) {
        // students see only pseudonyms; mark their own row
        return u.is_viewer
            ? $e("span", null, name, " ", $e("span", "pa-leaderboard-you", "This is you!"))
            : name;
    }
    // course staff can click through to the student’s pset page
    return $e("span", null,
        $e("a", {href: hoturl("pset", {u: u.user, pset: lb.pset}), class: "q"}, name),
        " ",
        $e("span", "pa-leaderboard-realname", u.display_name || u.user));
}

/** A two-row header cell: a one-line title (ellipsized if long) above a
 * unit row, which also holds the sort indicator. */
function render_th(cls, title, unit) {
    return $e("th", cls,
        $e("div", "pa-leaderboard-htitle", title),
        $e("div", "pa-leaderboard-hunit", unit || null));
}

function render_head(lb, s) {
    const tr = $e("tr", null, render_th("pa-leaderboard-rank", "#"));
    function sort_th(key, cls, title, unit, tooltip) {
        const active = s.key === key,
            th = render_th(`ui pa-leaderboard-sort ${cls}`
                + (active ? " active" : "") + (active && s.rev ? " reverse" : ""),
                title, unit);
        th.setAttribute("data-pa-sort", key);
        if (tooltip) {
            addClass(th, "need-tooltip");
            th.setAttribute("data-tooltip", escape_entities(tooltip));
            th.setAttribute("data-tooltip-anchor", "b");
        }
        active && th.setAttribute("aria-sort", s.rev ? "descending" : "ascending");
        tr.append(th);
    }
    sort_th("name", "pa-leaderboard-who", "student", null, null);
    for (const m of lb.metrics) {
        sort_th(m.key, "pa-leaderboard-metric", m.abbr || m.title, m.unit,
            `${m.title} (${m.best === "min" ? "lower" : "higher"} is better)`);
    }
    return $e("thead", null, tr);
}

function render_row(lb, row, sm, is_self, changed) {
    const se = sm ? row.entries[sm.key] : null,
        tr = $e("tr", is_self ? "pa-leaderboard-self" : null,
            $e("td", "pa-leaderboard-rank", se ? se.rank : null),
            $e("td", "pa-leaderboard-who", render_user(lb, row)));
    for (const m of lb.metrics) {
        const e = row.entries[m.key], td = $e("td", "pa-leaderboard-value");
        if (e) {
            td.append(e.text);
            style_cell(td, m, e.value);
            if (changed && changed.has(`${row.name} ${m.key}`)) {
                addClass(td, "pa-leaderboard-changed");
            }
            let title = `rank ${e.rank} of ${m.pa__count}`;
            if (e.at) {
                title += ", " + strftime("%Y-%m-%d %H:%M", new Date(e.at * 1000));
            }
            td.title = title;
        }
        tr.append(td);
    }
    return tr;
}

function render_body(elt, lb, s, changed) {
    // the page’s student: set on the element, or else the viewer
    const self_name = elt.getAttribute("data-pa-self")
            || (Object.values(lb.users).find(u => u.is_viewer) || {}).name,
        showing_all = hasClass(elt, "pa-leaderboard-all"),
        sm = s.key === "name" ? null : metric_by_key(lb, s.key),
        ncol = lb.metrics.length + 2,
        tbody = $e("tbody");
    let n = 0;
    for (const row of sorted_rows(lb, s)) {
        const is_self = row.name === self_name;
        if (n >= MAX_ROWS && !showing_all) {
            if (!is_self) {
                continue;
            }
            // show the viewer’s own row after a gap
            tbody.append($e("tr", "pa-leaderboard-gap", $e("td", {colspan: ncol}, "…")));
        }
        tbody.append(render_row(lb, row, sm, is_self, changed));
        ++n;
    }
    if (n === 0) {
        tbody.append($e("tr", null, $e("td", {colspan: ncol, class: "dim"}, "No results yet.")));
    }
    return tbody;
}

function render(elt) {
    const lb = elt.pa__leaderboard, body = elt.querySelector(".pa-leaderboard-body");
    // a header being re-rendered may own the visible tooltip
    tooltip.close();
    body.replaceChildren();
    if (!lb || !lb.metrics.length) {
        addClass(elt, "hidden");
        return;
    }
    removeClass(elt, "hidden");

    // flash changed cells once, not on every re-sort
    const s = sort_state(elt, lb), nrows = rows(lb).length, changed = elt.pa__changed;
    elt.pa__changed = null;
    body.append($e("div", "pa-leaderboard-scroll",
        $e("table", "pa-leaderboard-table", render_head(lb, s), render_body(elt, lb, s, changed))));
    $(body).awaken();
    if (nrows > MAX_ROWS) {
        body.append($e("button", {
            type: "button", class: "ui link pa-leaderboard-more"
        }, hasClass(elt, "pa-leaderboard-all") ? "Show top " + MAX_ROWS : "Show all " + nrows));
    }
}

/** Return the cells, as `"NAME KEY"` strings, whose values differ between
 * leaderboard responses `old` and `lb`.
 * @return {Set<string>} */
function changed_cells(old, lb) {
    const changed = new Set;
    for (const m of lb.metrics) {
        const om = metric_by_key(old, m.key),
            ovalues = new Map((om ? om.entries : []).map(e => [e.name, e.value]));
        for (const e of m.entries) {
            if (ovalues.get(e.name) !== e.value) {
                changed.add(`${e.name} ${m.key}`);
            }
        }
    }
    return changed;
}

/** Load `elt`’s leaderboard. If `fresh`, bypass the browser cache and mark
 * cells that changed since the last load. */
function load(elt, fresh) {
    const arg = {pset: elt.getAttribute("data-pa-pset")};
    $.ajax(hoturl("api/leaderboard", arg), {
        type: "GET", cache: !fresh, dataType: "json",
        success: function (d) {
            if (d.ok && d.metrics) {
                const old = elt.pa__leaderboard;
                if (fresh && old) {
                    elt.pa__changed = changed_cells(old, d);
                }
                elt.pa__leaderboard = d;
                render(elt);
            } else {
                addClass(elt, "hidden");
            }
        }
    });
}

export function leaderboard() {
    load(this, false);
}

// refresh leaderboards fed by a runner when a watched run of it completes
document.addEventListener("pa-runcomplete", function (evt) {
    const runner = evt.detail && evt.detail.runner;
    for (const elt of document.querySelectorAll(".pa-leaderboard")) {
        const lb = elt.pa__leaderboard;
        if (lb && lb.metrics.some(m => m.runner === runner)) {
            load(elt, true);
        }
    }
});

handle_ui.on("pa-leaderboard-sort", function () {
    const elt = this.closest(".pa-leaderboard"),
        lb = elt.pa__leaderboard,
        s = sort_state(elt, lb),
        key = this.getAttribute("data-pa-sort");
    elt.pa__sort = {key: key, rev: s.key === key ? !s.rev : false};
    render(elt);
});

handle_ui.on("pa-leaderboard-more", function () {
    const elt = this.closest(".pa-leaderboard");
    toggleClass(elt, "pa-leaderboard-all", !hasClass(elt, "pa-leaderboard-all"));
    render(elt);
});
