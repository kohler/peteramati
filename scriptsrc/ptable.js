// ptable.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, removeClass, toggleClass,
         handle_ui, $e } from "./ui.js";
import { event_key } from "./ui-key.js";
import { wstorage, sprintf, strftime } from "./utils.js";
import { hoturl } from "./hoturl.js";
import { regexp_quote } from "./encoders.js";
import { GradeSheet } from "./gradeentry.js";
import { $popup } from "./popup.js";
import { tooltip } from "./tooltip.js";
import { ptable_gdialog } from "./ptable-grades.js";
import { SearchParser } from "./search.js";
import { feedback } from "./render.js";


function render_name(s, last_first) {
    if (s.first != null && s.last != null) {
        if (last_first) {
            return s.last.concat(", ", s.first);
        } else {
            return s.first.concat(" ", s.last);
        }
    } else if (s.first != null) {
        return s.first;
    } else if (s.last != null) {
        return s.last;
    } else {
        return "";
    }
}

function grader_name(p) {
    if (!p.__nickname) {
        if (p.nick)
            p.__nickname = p.nick;
        else if (p.nicklen || p.lastpos)
            p.__nickname = p.name.substr(0, p.nicklen || p.lastpos - 1);
        else
            p.__nickname = p.name;
    }
    return p.__nickname;
}

function render_year(yr) {
    if (!yr) {
        return "";
    } else if (typeof yr === "number") {
        if (yr >= 1 && yr <= 20 && Math.floor(yr) === yr) {
            return String.fromCharCode(9311 + yr);
        } else {
            return yr.toString();
        }
    } else if (yr.length === 1 && yr >= "A" && yr <= "Z") {
        return String.fromCharCode(9333 + yr.charCodeAt(0));
    }
    return yr;
}

function user_compare(a, b) {
    return a._sort_user.localeCompare(b._sort_user);
}

function grader_compare(a, b) {
    const ap = a.gradercid ? siteinfo.pc[a.gradercid] : null,
        bp = b.gradercid ? siteinfo.pc[b.gradercid] : null,
        ag = (ap && grader_name(ap)) || "~~~",
        bg = (bp && grader_name(bp)) || "~~~";
    if (ag != bg) {
        return ag < bg ? -1 : 1;
    } else {
        return 0;
    }
}


function strong(t) {
    const stre = document.createElement("strong");
    stre.className = "err";
    stre.append(t);
    return stre;
}

function user_hover(evt) {
    if (evt.target.tagName === "A" && evt.target.classList.contains("pa-user")) {
        if (evt.type === "mouseenter") {
            tooltip.enter(evt.target, "pa-ptable-user");
        } else {
            tooltip.leave(evt.target);
        }
    }
}


const ALL_USERS = 0;
const CHECKED_USERS = 1;
const SOME_USERS = 2;

class PtableConf {
    id;
    key;
    title;
    gitless;
    gitless_grades;
    has_older_repo;
    disabled;
    visible;
    scores_visible;
    frozen;
    anonymous;
    has_nonanonymous;
    overridable_anonymous;
    flagged_commits;
    sort;
    no_sort;
    checkbox;
    grades;
    need_total;
    total_key;
    runners;
    diff_files;
    reports;
    requested_columns;
    col;
    pincol;
    colmap;
    data;
    mode;
    smap;
    uidmap;
    input_timeout;
    has_hidden;

    constructor(pconf, data) {
        this.id = pconf.id;
        this.key = pconf.key;
        this.title = pconf.title;
        this.gitless = !!pconf.gitless;
        this.gitless_grades = !!pconf.gitless_grades;
        this.has_older_repo = !!pconf.has_older_repo;
        this.disabled = !!pconf.disabled;
        this.visible = !!pconf.visible;
        this.scores_visible = !!pconf.scores_visible;
        this.frozen = !!pconf.frozen;
        this.anonymous = this.original_anonymous = !!pconf.anonymous;
        this.has_nonanonymous = !this.anonymous || pconf.has_nonanonymous;
        this.overridable_anonymous = this.anonymous && pconf.overridable_anonymous;
        this.flagged_commits = pconf.flagged_commits;
        this.sort = {
            f: this.flagged_commits ? "at" : "name", last: false, rev: 1,
            u: "name"
        };
        this.no_sort = pconf.no_sort;
        this.checkbox = !!pconf.checkbox;
        this.grades = pconf.grades;
        this.need_total = !!pconf.need_total;
        this.total_key = pconf.total_key;
        this.runners = pconf.runners || [];
        this.diff_files = pconf.diff_files || [];
        this.reports = pconf.reports || [];
        this.requested_columns = pconf.col || null;

        this.col = [];
        this.pincol = [];
        this.colmap = {};

        this.data = data;

        this.mode = 0;
        this.smap = [null];
        this.uidmap = [];
        this.has_hidden = false;

        this.input_timeout = null;
    }

    set_gradesheet(gi) {
        this.gradesheet = gi;
        this.in_total_indexes = [];
        this.answer_indexes = [];
        for (let i = 0; i !== gi.value_order.length; ++i) {
            const ge = gi.entries[gi.value_order[i]];
            if (ge.answer) {
                this.answer_indexes.push(i);
            } else if (ge.in_total && ge.key !== this.total_key) {
                this.in_total_indexes.push(i);
            }
        }
    }

    add_column(c) {
        c.index = this.col.length;
        this.col.push(c);
        this.colmap[c.name] = this.colmap[c.name] || c;
        if (c.pin) {
            c.pin_index = this.pincol.length;
            this.pincol.push(c);
        }
    }

    get columns() {
        return this.col;
    }

    get pinned_columns() {
        return this.pincol;
    }

    columns_in(table) {
        return table.classList.contains("gtable-left-pin") ? this.pincol : this.col;
    }

    column_index_in(key, table) {
        const c = this.colmap[key];
        if (!c) {
            return -1;
        }
        const k = table.classList.contains("gtable-left-pin") ? "pin_index" : "index";
        return c[k] != null ? c[k] : -1;
    }

    ukey(s) {
        return (this.anonymous && s.anon_user) || s.user || "";
    }

    url_gradeparts(s) {
        const args = {
            u: this.ukey(s),
            pset: this.key
        };
        let p;
        if (s.pset && (p = siteinfo.psets[s.pset])) {
            args.pset = p.urlkey;
        }
        if (s.commit && (!s.is_grade || this.flagged_commits)) {
            args.commit = s.commit;
        } else if (s.grade_commit) {
            args.commit = s.grade_commit;
            args.commit_is_grade = 1;
        }
        return args;
    }

    href(s) {
        return hoturl("pset", this.url_gradeparts(s));
    }

    make_student_ae(s) {
        const ae = document.createElement("a");
        ae.href = hoturl("index", {u: this.ukey(s)});
        ae.className = "track";
        return ae;
    }

    make_pset_ae(s) {
        const ae = document.createElement("a");
        ae.href = this.href(s);
        ae.className = "pa-user track" + (s.dropped ? " gt-dropped" : "");
        return ae;
    }

    render_username_td(tde, s) {
        const ae = this.make_pset_ae(s);
        if (this.anonymous && s.anon_user) {
            ae.append(s.anon_user);
        } else if (this.sort.email && s.email) {
            ae.append(s.email);
        } else if (s.user) {
            ae.append(s.user);
        }
        tde.replaceChildren(ae);
    }

    render_user_td(tde, s) {
        const ae = this.make_pset_ae(s), sort = this.sort;
        if (this.anonymous && s.anon_user) {
            ae.append(s.anon_user);
        } else if (sort.u === "email" && s.email) {
            ae.append(s.email);
        } else if (sort.u === "name") {
            ae.append(render_name(s, sort.last) || s.email);
        } else {
            ae.append(s.user);
        }
        tde.replaceChildren(ae);
    }

    render_display_name(tde, s, is2) {
        const t = is2 && this.anonymous
            ? s.anon_user || "?"
            : render_name(s, this.sort.last) || s.email;
        if (is2) {
            const ae = this.make_pset_ae(s);
            ae.textContent = t;
            tde.replaceChildren(ae);
        } else {
            tde.replaceChildren(t);
        }
    }

    render_tooltip_name(s) {
        if (!s) {
            return "???";
        } else if (this.anonymous && s.anon_user) {
            return s.anon_user;
        } else if (this.sort.u === "email" && s.email) {
            return s.email;
        } else if (this.sort.u === "name") {
            return render_name(s, false) || s.email;
        } else {
            return s.user;
        }
    }

    render_checkbox_name(s) {
        let u = this.anonymous ? s.anon_user || s.user : s.user;
        return "s:" + encodeURIComponent(u).replace(/\./g, "%2E");
    }

    render_table_row(su, mode) {
        this.mode = mode;
        const tre = document.createElement("tr");
        tre.className = "gt";
        tre.setAttribute("data-pa-spos", su._spos);
        if (su.uid) {
            tre.setAttribute("data-pa-uid", su.uid);
        }
        if (mode & 2) {
            tre.classList.add("kfade");
        }
        if (mode & 1) {
            tre.classList.add("gtrow-partner");
            tre.setAttribute("data-pa-partner", 1);
        }
        const cols = mode & 2 ? this.pincol : this.col;
        for (const c of cols) {
            const tde = document.createElement("td");
            c.td.call(c, tde, su);
            tre.appendChild(tde);
        }
        return tre;
    }

    tbody_rowmap(tbody) {
        if (tbody.pa__rowmap) {
            return tbody.pa__rowmap;
        }
        const rowmap = tbody.pa__rowmap = {rows: [], users: new WeakMap};
        rowmap.rows.length = this.smap.length;
        rowmap.rows.fill(null);
        for (let tr = tbody.firstChild; tr; tr = tr.nextSibling) {
            const spos = tr.getAttribute("data-pa-spos");
            if (spos) {
                rowmap.rows[+spos] = tr;
                rowmap.users.set(tr, this.smap[spos]);
            }
        }
        return rowmap;
    }


    render_gdialog_users(h3, slist) {
        if (slist.length === this.data.length || slist.length === 0) {
            addClass(h3, "hidden");
        } else {
            removeClass(h3, "hidden");
            let i = 0, n = [], max = slist.length === 20 ? 20 : 19;
            while (i < max && i < slist.length) {
                n.push(this.render_tooltip_name(slist[i]));
                ++i;
            }
            if (i < slist.length) {
                n.push("(".concat(slist.length - i, " more)"));
            }
            h3.replaceChildren(n.join(", "));
        }
    }

    push(s) {
        s._spos = this.smap.length;
        s.hidden = false;
        this.smap.push(s);
        if (s.uid > 0) {
            if (s.uid >= this.uidmap.length) {
                this.uidmap.length = s.uid + 1;
            }
            this.uidmap[s.uid] = s;
        }
    }

    user_row_checkbox(tr) {
        const overlay = hasClass(tr.parentElement.parentElement, "gtable-left-pin"),
            cbidx = this.colmap.checkbox[overlay ? "pin_index" : "index"];
        return cbidx != null ? tr.children[cbidx].firstChild : null;
    }


    get ALL_USERS() {
        return ALL_USERS;
    }

    get CHECKED_USERS() {
        return CHECKED_USERS;
    }

    get SOME_USERS() {
        return SOME_USERS;
    }

    users_in(form, users) {
        const tb = form.querySelector("table.gtable > tbody"),
            rowmap = this.tbody_rowmap(tb),
            cbidx = this.colmap.checkbox.index,
            sus = [], chsus = [];
        for (let tr = tb.firstChild; tr; tr = tr.nextSibling) {
            const ss = rowmap.users.get(tr);
            if (ss) {
                sus.push(ss);
                if (users && tr.children[cbidx].firstChild.checked)
                    chsus.push(ss);
            }
        }
        if (users === SOME_USERS) {
            return chsus.length > 0 ? chsus : sus;
        } else if (users === CHECKED_USERS) {
            return chsus;
        } else {
            return sus;
        }
    }
}


function ptable_head_click_change_sort(tgt) {
    if (tgt.tagName === "TH"
        ? !tgt.hasAttribute("data-pa-sort")
        : tgt.tagName !== "BUTTON" || !tgt.classList.contains("js-switch-anon")) {
        return null;
    }

    const ptconf = tgt.closest("form").pa__ptconf,
        sort = Object.assign({}, ptconf.sort);
    if (tgt.tagName === "BUTTON") {
        if (!ptconf.overridable_anonymous) {
            return null;
        }
        if (sort.deblind) {
            delete sort.deblind;
        } else {
            sort.deblind = (new Date).getTime() / 1000;
        }
        return sort;
    }

    const sf = tgt.getAttribute("data-pa-sort");
    if (sf !== sort.f) {
        sort.f = sf;
        const col = ptconf.colmap[sf];
        sort.rev = col && col.sort_forward ? 1 : -1;
        if (sf === "name") {
            sort.u = "name";
        } else if (sf === "name2") {
            sort.u = ptconf.anonymous ? "user" : "name";
        } else if (sf === "email") {
            sort.u = "email";
        } else if (sf === "username") {
            if ((ptconf.anonymous && !ptconf.has_nonanonymous) || !sort.email) {
                sort.u = "user";
            } else {
                sort.u = "email";
            }
        }
    } else {
        sort.rev = -sort.rev;
        if (sort.rev === 1) {
            if (sf === "name") {
                sort.last = !sort.last;
            } else if (ptconf.anonymous && !ptconf.has_nonanonymous) {
                // skip
            } else if (sf === "name2") {
                sort.last = !sort.last;
            } else if (sf === "username") {
                if (sort.email) {
                    sort.u = "user";
                    delete sort.email;
                } else {
                    sort.u = "email";
                    sort.email = true;
                }
            } else if (sf === "user") {
                if (sort.u === "name") {
                    sort.last = !sort.last;
                    if (!sort.last) {
                        sort.u = "email";
                    }
                } else if (sort.u === "email") {
                    sort.u = "user";
                } else {
                    sort.u = "name";
                }
            }
        }
    }
    return sort;
}

function ptable_head_click(evt) {
    let tgt = evt.target;
    while (tgt !== this
           && tgt.tagName !== "TH"
           && tgt.tagName !== "BUTTON"
           && tgt.tagName !== "A") {
        tgt = tgt.parentElement;
    }
    const sort = ptable_head_click_change_sort(tgt);
    if (sort) {
        tgt.closest("form").dispatchEvent(new CustomEvent("sortchange", {
            bubbles: true, cancelable: true,
            detail: {sort: sort}
        }));
    }
}

function ptable_body_click(evt) {
    let tgt = evt.target;
    while (tgt !== this && tgt.tagName !== "TD") {
        tgt = tgt.parentElement;
    }
    if (tgt.tagName !== "TD") {
        return;
    }
    let i = 0;
    for (let td = tgt.parentElement.firstChild; td !== tgt; td = td.nextSibling) {
        ++i;
    }
    const ptconf = tgt.closest("form").pa__ptconf,
        ge = ptconf.col[i].ge,
        tr = tgt.closest("tr");
    if (ge
        && !ge.readonly
        && tr.hasAttribute("data-pa-spos")
        && !evt.shiftKey
        && !evt.ctrlKey) {
        ptable_gdialog(ptconf, [+tr.getAttribute("data-pa-spos")], tgt.closest("table"), ge.key);
    }
}

function ptable_make_hotlist(evt) {
    const ptconf = this.closest("form").pa__ptconf,
        rowmap = ptconf.tbody_rowmap(this),
        j = [];
    for (let tr = this.firstChild; tr; tr = tr.nextElementSibling) {
        const su = rowmap.users.get(tr);
        if (!su || su.hidden) {
            continue;
        }
        let t = "~" + encodeURIComponent(ptconf.ukey(su)), p;
        if (ptconf.flagged_commits && su.pset && (p = siteinfo.psets[su.pset])) {
            t = t.concat("/pset/", p.urlkey);
            if (su.commit) {
                t = t.concat("/", su.commit);
            }
        }
        j.push(t);
    }
    event.detail.hotlist = {pset: ptconf.flagged_commits ? null : ptconf.key, items: j};
}


function ptable_body_scroller(ctrh) {
    return function () {
        ctrh.scrollLeft = this.scrollLeft;
        if (hasClass(ctrh.lastChild, "gtable-left-pin")) {
            ctrh.lastChild.style.left = this.scrollLeft + "px";
        }
    };
}

function ptable_track_header(table) {
    let ctr2;
    if (!window.IntersectionObserver
        || table.rows.length <= 10
        || !(ctr2 = table.closest(".gtable-container-2"))) {
        return null;
    }
    // find containers
    const ctr1 = ctr2.parentElement, ctr0 = ctr1.parentElement;
    // create pinned header table
    const tctable = document.createElement("table");
    tctable.className = table.className;
    tctable.classList.add("gtable-top-pin");
    tctable.classList.remove("gtable");
    tctable.style.width = table.style.width;
    tctable.appendChild(table.tHead.cloneNode(true));
    // hide table’s own header
    const thead_bounds = table.tHead.getBoundingClientRect(),
        ctr0_bounds = ctr0.getBoundingClientRect(),
        ctr2_bounds = ctr2.getBoundingClientRect();
    table.style.marginTop = (-thead_bounds.height - 0.5) + "px";
    table.tHead.style.visibility = "hidden";
    // create header container
    if (!hasClass(ctr0.firstChild, "gtable-container-headers")) {
        const ctrh = document.createElement("div");
        ctrh.className = "gtable-container-headers";
        ctrh.style.marginLeft = (ctr2_bounds.left - ctr0_bounds.left) + "px";
        ctrh.scrollLeft = ctr2.scrollLeft;
        ctr0.prepend(ctrh);
        ctr2.addEventListener("scroll", ptable_body_scroller(ctrh), {passive: true});
    }
    // append to header container
    const ctrh = ctr0.firstChild;
    if (hasClass(tctable, "gtable-left-pin")) {
        tctable.style.left = ctrh.scrollLeft + "px";
    }
    ctrh.append(tctable);
    tctable.tHead.addEventListener("click", ptable_head_click);
    return tctable;
}

function ptable_decorate_name_th(the, ptconf) {
    if (ptconf.overridable_anonymous) {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "q n js-switch-anon";
        b.append("[anon]");
        the.append(" ", b);
    } else if (ptconf.original_anonymous) {
        const sp = document.createElement("span");
        sp.className = "n";
        sp.append("[anon]");
        the.append(" ", sp);
    }
}

function ptable_thead(cols, ptconf, tfixed) {
    const tr = document.createElement("tr");
    let rem, current_width = 0;
    tr.className = "gtable-headrow gt k0";
    for (const c of cols) {
        const the = document.createElement("th");
        the.scope = "col";
        if (c.compare || c.make_compare) {
            the.className = "plsortable";
            the.setAttribute("data-pa-sort", c.name);
        }
        c.th.call(c, the);
        if (tfixed) {
            let w = c.tw;
            if (typeof w !== "number") {
                w = w.call(c);
            }
            if (rem == null) {
                rem = parseFloat(window.getComputedStyle(document.documentElement).fontSize);
            }
            w *= rem;
            if (c.left == null) {
                c.left = current_width;
            }
            c.width = w;
            the.style.width = w + "px";
            current_width += w;
        }
        tr.appendChild(the);
    }
    const thead = document.createElement("thead");
    thead.append(tr);
    return thead;
}

let queue_update_microtask_scheduled = 0, queue_update_microtask_tds = [];

function update_microtask() {
    queue_update_microtask_scheduled = 0;
    for (const td of queue_update_microtask_tds) {
        removeClass(td, "update");
    }
    queue_update_microtask_tds = [];
}

function queue_update_microtask(td) {
    if (++queue_update_microtask_scheduled === 1) {
        setTimeout(update_microtask, 0);
    }
    queue_update_microtask_tds.push(td);
}


const gcoldef = {
    rownumber: {
        th: function (the) {
            the.classList.add("gt-rownumber");
        },
        td: function (tde) {
            tde.className = "gt-rownumber";
        },
        tw: function () {
            return Math.ceil(Math.log10(Math.max(this.ptconf.data.length, 1))) * 0.75;
        }
    },

    checkbox: {
        th: function (the) {
            the.classList.add("gt-checkbox");
            const e = document.createElement("input");
            e.type = "checkbox";
            e.className = "uic js-range-click is-range-group ignore-diff";
            e.ariaLabel = "Select all";
            e.setAttribute("data-range-type", "s61");
            the.appendChild(e);
        },
        td: function (tde, s) {
            if (this.ptconf.mode & 1) {
                return;
            }
            tde.className = "gt-checkbox";
            let cbe = document.createElement("input");
            cbe.type = "checkbox";
            cbe.value = 1;
            if (this.ptconf.mode & 2) {
                cbe.className = "uic js-range-click";
                cbe.setAttribute("data-range-type", "s61o");
            } else {
                cbe.name = this.ptconf.render_checkbox_name(s);
                cbe.className = this.className || "uic js-range-click papsel";
                cbe.setAttribute("data-range-type", "s61");
            }
            tde.appendChild(cbe);
        },
        tw: 2,
        pin: true
    },

    flagcheckbox: {
        th: function (the) {
            the.className = "gt-checkbox";
        },
        td: function (tde, s) {
            if (this.ptconf.mode) {
                return;
            }
            tde.className = "gt-checkbox";
            let cbe = document.createElement("input");
            cbe.type = "checkbox";
            cbe.name = "s:" + s._spos;
            cbe.value = 1;
            cbe.className = this.className || "uic js-range-click papsel";
            cbe.setAttribute("data-range-type", "s61");
            tde.appendChild(cbe);
        },
        tw: 2,
        pin: true
    },

    pset: {
        th: function (the) {
            the.classList.add("gt-pset", "l");
            the.append("Pset");
        },
        td: function (tde, s) {
            tde.className = "gt-pset";
            let ae = document.createElement("a");
            ae.href = this.ptconf.href(s);
            ae.classname = "track";
            const p = siteinfo.psets[s.pset];
            ae.append((p ? p.urlkey : `<#${s.pset}>`) + (s.commit ? "/" + s.commit.substr(0, 7) : ""));
            tde.append(ae);
        },
        tw: 8,
        sort_forward: true,
        compare: function (a, b) {
            if (a.pset != b.pset) {
                const ap = siteinfo.psets[a.pset], bp = siteinfo.psets[b.pset];
                if (ap && bp) {
                    return ap.pos < bp.pos ? -1 : 1;
                }
            }
            return a.pos < b.pos ? -1 : 1;
        }
    },

    user: {
        th: function (the) {
            let sp = the.firstChild;
            if (!sp) {
                the.classList.add("gt-user", "l");
                the.appendChild((sp = document.createElement("span")));
                sp.className = "heading";
                ptable_decorate_name_th(the, this.ptconf);
            }
            if (this.ptconf.anonymous || this.ptconf.sort.u === "user") {
                sp.replaceChildren("Username");
            } else if (this.ptconf.sort.u === "email") {
                sp.replaceChildren("Email");
            } else {
                sp.replaceChildren("Name");
            }
        },
        td: function (tde, s) {
            tde.className = "gt-user";
            this.ptconf.render_user_td(tde, s);
        },
        tw: 14,
        pin: true,
        sort_forward: true,
        compare: true,
        refreshable: 2
    },

    username: {
        th: function (the) {
            let sp = the.firstChild;
            if (!sp) {
                the.classList.add("gt-username", "l");
                the.appendChild((sp = document.createElement("span")));
                sp.className = "heading";
                ptable_decorate_name_th(the, this.ptconf);
            }
            if (this.ptconf.anonymous || !this.ptconf.sort.email) {
                sp.replaceChildren("Username");
            } else {
                sp.replaceChildren("Email");
            }
        },
        td: function (tde, s) {
            tde.className = "gt-username";
            this.ptconf.render_username_td(tde, s);
        },
        tw: 12,
        pin: true,
        sort_forward: true,
        compare: true,
        refreshable: 2
    },

    name: {
        th: function (the) {
            the.classList.add("gt-name", "l");
            the.replaceChildren("Name");
        },
        td: function (tde, s) {
            tde.className = "gt-name";
            this.ptconf.render_display_name(tde, s, false);
        },
        tw: 14,
        sort_forward: true,
        compare: true,
        refreshable: 2
    },

    name2: {
        th: function (the) {
            let sp = the.firstChild;
            if (!sp) {
                the.classList.add("gt-name2", "l");
                the.appendChild((sp = document.createElement("span")));
                sp.className = "heading";
                ptable_decorate_name_th(the, this.ptconf);
            }
            if (this.ptconf.anonymous) {
                sp.replaceChildren("Username");
            } else {
                sp.replaceChildren("Name");
            }
        },
        td: function (tde, s) {
            tde.className = "gt-name2";
            this.ptconf.render_display_name(tde, s, true);
        },
        tw: 14,
        sort_forward: true,
        compare: true,
        refreshable: 2
    },

    at: {
        th: function (the) {
            the.classList.add("gt-at", "l");
            the.replaceChildren("Flagged");
        },
        td: function (tde, s) {
            tde.className = "gt-at";
            if (s.at)
                tde.append(strftime("%#e %b %#k:%M", s.at));
        },
        tw: 8,
        sort_forward: true,
        compare: function (a, b) {
            if (a.at != b.at)
                return a.at < b.at ? -1 : 1;
            else
                return a.pos < b.pos ? -1 : 1;
        }
    },

    extension: {
        th: function (the) {
            the.classList.add("gt-extension");
            the.replaceChildren("X?");
        },
        td: function (tde, s) {
            tde.className = "gt-extension";
            s.x && tde.append("X");
        },
        tw: 2,
        sort_forward: true,
        compare: function (a, b) {
            if (a.x != b.x)
                return a.x ? -1 : 1;
            else
                return user_compare(a, b);
        }
    },

    year: {
        th: function (the) {
            the.classList.add("gt-year");
            the.replaceChildren("Yr");
        },
        td: function (tde, s) {
            tde.className = "gt-year c";
            tde.replaceChildren(render_year(s.year));
        },
        tw: 2,
        sort_forward: true,
        compare: function (a, b) {
            if (a.year != b.year) {
                if (!a.year || !b.year)
                    return a.year ? -1 : 1;
                else if (typeof a.year !== typeof b.year)
                    return typeof a.year === "number" ? -1 : 1;
                else
                    return a.year < b.year ? -1 : 1;
            } else
                return user_compare(a, b);
        }
    },

    grader: {
        th: function (the) {
            the.classList.add("gt-grader", "l");
            the.replaceChildren("Grader");
        },
        td: function (tde, s) {
            tde.className = "gt-grader";
            if (s.gradercid && siteinfo.pc[s.gradercid]) {
                tde.replaceChildren(grader_name(siteinfo.pc[s.gradercid]));
            } else if (s.gradercid) {
                tde.replaceChildren("???");
            }
        },
        tw: 6,
        sort_forward: true,
        compare: function (a, b) {
            return grader_compare(a, b) || user_compare(a, b);
        },
        refreshable: 2
    },

    latehours: {
        th: function (the) {
            the.classList.add("gt-latehours", "r");
            the.title = "Late";
            the.replaceChildren("⏰");
        },
        td: function (tde, s) {
            tde.className = "gt-latehours r";
            tde.replaceChildren(s.late_hours || "");
        },
        tw: 2.5,
        compare: function (a, b) {
            if (a.late_hours != b.late_hours) {
                return (a.late_hours || 0) - (b.late_hours || 0);
            } else {
                return user_compare(a, b);
            }
        },
        refreshable: 1
    },

    notes: {
        th: function (the) {
            the.classList.add("gt-notes");
            the.replaceChildren("⎚");
        },
        td: function (tde, s) {
            tde.className = "gt-notes c";
            let t = s.scores_visible ? '⎚' : '';
            if (s.grade_commit
                && s.grade_commit === s.commit
                && this.ptconf.flagged_commits) {
                t += 'G⃝';
            }
            if (s.grade_status < 0) {
                t += ' ⃠';
            }
            if (s.has_notes) {
                t += '♪';
            }
            if (s.has_nongrader_notes
                && !this.ptconf.flagged_commits) {
                t += "+";
            }
            tde.replaceChildren(t);
        },
        tw: 2,
        refreshable: 1
    },

    total: {
        th: function (the) {
            the.classList.add("gt-total", "r");
            the.replaceChildren("Tot");
        },
        td: function (tde, s) {
            tde.className = "gt-total r";
            tde.replaceChildren(s.total != null ? s.total + "" : "");
        },
        compare: function (a, b) {
            if (a.total == null || b.total == null) {
                if (a.total != null) {
                    return 1;
                } else if (b.total != null) {
                    return -1;
                }
            } else if (a.total != b.total) {
                return a.total < b.total ? -1 : 1;
            }
            return -user_compare(a, b);
        },
        tw: 3.5,
        refreshable: 1
    },

    grade: {
        th: function (the) {
            the.classList.add(...this.className.split(/\s+/));
            the.title = this.ge.title_text;
            the.replaceChildren(this.ge.abbr());
        },
        td: function (tde, s, opt) {
            const gr = s.grades[this.gidx];
            tde.className = this.className;
            if (s.autogrades
                && s.autogrades[this.gidx] != null
                && s.autogrades[this.gidx] !== gr) {
                tde.className += " gt-highlight";
            }
            if (opt && opt.oldgrades && opt.oldgrades[this.gidx] !== gr) {
                tde.className += " update";
                queue_update_microtask(tde);
            }
            tde.replaceChildren(this.ge.tcell(gr));
        },
        tw: function () {
            const w = this.ge.abbr().length * 0.5 + 1.5;
            return Math.max(w, this.ge.tcell_width());
        },
        refreshable: 1
    },

    ngrades: {
        th: function (the) {
            the.classList.add("gt-ngrades", "r");
            the.replaceChildren("#G");
        },
        td: function (tde, s) {
            tde.className = "gt-ngrades r";
            tde.replaceChildren(s.ngrades ? s.ngrades + "" : "");
        },
        tw: 2,
        sort_forward: true,
        compare: function (a, b) {
            if (a.ngrades !== b.ngrades)
                return a.ngrades < b.ngrades ? -1 : 1;
            else
                return -user_compare(a, b);
        },
        refreshable: 1
    },

    repo: {
        th: function () {
        },
        td: function (tde, s) {
            tde.className = "gt-repo";
            if (!s.repo) {
                return;
            }
            const ae = document.createElement("a");
            if (this.ptconf.anonymous) {
                ae.className = "ui pa-anonymized-link";
                ae.href = "";
                ae.setAttribute("data-pa-link", s.repo);
            } else {
                ae.className = "track";
                ae.href = s.repo;
            }
            ae.append("repo");
            tde.replaceChildren(ae);
            if (s.repo_broken) {
                tde.append(" ", strong("broken"));
            }
            if (s.repo_unconfirmed) {
                tde.append(" ", strong("unconfirmed"));
            }
            if (s.repo_too_open) {
                tde.append(" ", strong("open"));
            }
            if (s.repo_handout_old) {
                tde.append(" ", strong("handout"));
            }
            if (s.repo_partner_error) {
                tde.append(" ", strong("partner"));
            }
            if (s.repo_sharing) {
                tde.append(" ", strong("sharing"));
            }
        },
        tw: 10
    },

    conversation: {
        th: function (the) {
            the.classList.add("gt-conversation", "l");
            the.replaceChildren("Flag");
        },
        td: function (tde, s) {
            tde.className = "gt-conversation l";
            if (s.conversation) {
                tde.append(s.conversation);
            } else if (s.conversation_pfx) {
                tde.append(s.conversation_pfx + "…");
            }
        },
        compare: function (a, b) {
            const sa = a.conversation || a.conversation_pfx || "",
                  sb = b.conversation || b.conversation_pfx || "";
            if (sa === "" || sb === "") {
                return sa === sb ? 0 : (sa === "" ? 1 : -1);
            } else {
                return sa.localeCompare(sb);
            }
        },
        sort_forward: true,
        tw: 20
    }
};


function ptables_cellpos(ptconf, tables, key) {
    const a = [];
    for (let i = 0; i !== tables.length; ++i) {
        const table = tables[i],
            idx = ptconf.column_index_in(key, table);
        if (idx >= 0) {
            a.push({table: table, index: idx});
        }
    }
    return a;
}

function ptables_rerender_key(ptconf, tables, ...keys) {
    for (const key of keys) {
        const c = ptconf.colmap[key];
        for (const ti of ptables_cellpos(ptconf, tables, key)) {
            const table = ti.table, index = ti.index;
            if (table.tBodies.length > 0) {
                for (let tr = table.tBodies[0].firstChild; tr; tr = tr.nextSibling) {
                    const spos = tr.getAttribute("data-pa-spos");
                    if (spos) {
                        const su = ptconf.smap[spos], tde = tr.children[index];
                        c.td.call(c, tde, su);
                    }
                }
            }
            c.th.call(c, table.tHead.firstChild.children[index]);
        }
    }
}


function ptable_rangechange(evt) {
    if (evt.detail.rangeType === "s61")
        $(this).find(".js-gdialog").prop("disabled", !evt.detail.newState);
}


function ptable_boring_row(table) {
    const tr = document.createElement("tr");
    tr.className = "gt-boring";
    const td = document.createElement("td");
    td.colSpan = table.rows[0].childNodes.length;
    td.appendChild(document.createElement("hr"));
    tr.appendChild(td);
    return tr;
}

function ptable_permute(ptconf, table, data) {
    const tb = table.tBodies[0],
        mode = table.classList.contains("gtable-left-pin") ? 2 : 0,
        klasses = ["k0", "k1"],
        rowmap = tb.firstChild && ptconf.tbody_rowmap(tb);
    let was_boringness = false, trn = 1,
        last = tb.firstChild;
    for (const su of data) {
        if (su.boringness !== was_boringness && was_boringness !== false) {
            if (last && last.className === "gt-boring") {
                last = last.nextSibling;
            } else {
                tb.insertBefore(ptable_boring_row(table), last);
            }
        }
        was_boringness = su.boringness;

        while (last && last.className === "gt-boring") {
            const tr = last;
            last = last.nextSibling;
            tb.removeChild(tr);
        }

        let any_visible = false;
        for (const ss of su.partners ? [su, ...su.partners] : [su]) {
            let tr;
            if (!rowmap || !(tr = rowmap.rows[ss._spos])) {
                tr = ptconf.render_table_row(ss, su === ss ? mode : mode | 1);
                if (rowmap) {
                    rowmap.rows[ss._spos] = tr;
                    rowmap.users.set(tr, ss);
                }
            }
            if (tr !== last) {
                tb.insertBefore(tr, last);
            } else {
                last = last.nextSibling;
            }
            tr.classList.remove(klasses[(trn ^ 1) & 1]);
            tr.classList.add(klasses[trn & 1]);
            tr.hidden = ss.hidden;
            any_visible = any_visible || !tr.hidden;
        }
        any_visible && ++trn;
    }
}


export function pa_pset_table(form, pconf, data) {
    const ptconf = new PtableConf(pconf, data || []);
    form.pa__ptconf = ptconf;
    const table = form.querySelector("table.gtable");
    if (table) {
        pa_render_pset_table.call(table, ptconf);
    }
}

function pa_render_pset_table(ptconf) {
    const table = this, $j = $(table);
    let $alltables = $(table),
        hdrtable = null, lpintable = null, lpinhdrtable = null,
        slist_input,
        gradesheet = null,
        active_nameflag = -1,
        col, colmap, data = ptconf.data;

    function sort_nameflag() {
        if (ptconf.anonymous) {
            return 8;
        } else if (ptconf.sort.u === "name") {
            return 1 | (ptconf.sort.last ? 2 : 0);
        } else if (ptconf.sort.u === "email") {
            return 4;
        } else {
            return 0;
        }
    }

    function initialize() {
        let sort = ptconf.sort,
            x = wstorage.site(true, "pa-pset" + ptconf.id + "-table");
        if (x) {
            try {
                x = JSON.parse(x);
                if (x && typeof x === "object") {
                    sort = ptconf.sort = x;
                    delete sort.override_anonymous; // XXX backward compat 21-Dec-2022
                }
            } catch {
            }
        }
        if (!sort.f || !/^\w+$/.test(sort.f)) {
            sort.f = "name";
        }
        if (sort.rev !== 1 && sort.rev !== -1) {
            sort.rev = 1;
        }
        if (!ptconf.overridable_anonymous) {
            delete sort.deblind;
        }
        if (sort.deblind) {
            let now = (new Date).getTime() / 1000;
            if (typeof sort.deblind === "number" && sort.deblind + 3600 > now) {
                sort.deblind = now;
            } else {
                delete sort.deblind;
            }
            wstorage.site(true, "pa-pset" + ptconf.id + "-table", JSON.stringify(sort));
        }
        if (sort.deblind) {
            ptconf.anonymous = false;
        }

        if (ptconf.grades) {
            gradesheet = new GradeSheet(ptconf.grades);
        } else {
            gradesheet = new GradeSheet({order: []});
        }
        gradesheet.scores_visible = ptconf.scores_visible;
        ptconf.set_gradesheet(gradesheet);

        let ngrades_expected = -1,
            has_late_hours = false, any_visible = ptconf.scores_visible;
        for (let i = 0; i !== data.length; ++i) {
            const s = data[i] = gradesheet.make_child().assign(data[i]);
            if (s.dropped) {
                s.boringness = 2;
            } else if (s.emptydiff
                       || (!s.grade_commit && !s.commit && !ptconf.gitless_grades)
                       || s.grade_status < 0) {
                s.boringness = 1;
            } else {
                s.boringness = 0;
            }
            let ngrades = 0;
            if (s.grades) {
                for (let x of ptconf.in_total_indexes) {
                    if (s.grades[x] != null && s.grades[x] !== "")
                        ++ngrades;
                }
            }
            s.ngrades = ngrades;
            if (ngrades_expected === -1) {
                ngrades_expected = ngrades;
            } else if (ngrades_expected >= 0
                       && ngrades_expected !== ngrades
                       && (!s.boringness || ngrades > 0)) {
                ngrades_expected = -2;
            }
            has_late_hours = has_late_hours || !!s.late_hours;
            any_visible = any_visible || s.scores_visible_pinned || s.grade_status < 0;
        }
        const need_ngrades = ngrades_expected === -2;

        if (ptconf.requested_columns) {
            col = ptconf.requested_columns;
        } else {
            col = ["rownumber"];
            if (ptconf.checkbox) {
                col.push(ptconf.flagged_commits ? "flagcheckbox" : "checkbox");
            }
            if (ptconf.flagged_commits) {
                col.push("pset");
                col.push("at");
            }
            col.push("user", "extension", "year", "grader");
            if (has_late_hours) {
                col.push("latehours");
            }
            if (ptconf.flagged_commits) {
                col.push("conversation");
            }
            if (ptconf.flagged_commits || !ptconf.gitless_grades || any_visible) {
                col.push("notes");
            }
            if (ptconf.need_total) {
                col.push("total");
            }
            let gi = ptconf.gradesheet;
            for (let i = 0; i !== gi.value_order.length; ++i) {
                const ge = gi.entries[gi.value_order[i]];
                col.push(ge.configure_column({
                    type: "grade",
                    name: "/g/" + ge.key,
                    gidx: i,
                    gkey: ge.key,
                    ptconf: ptconf
                }));
            }
            if (need_ngrades) {
                col.push("ngrades");
            }
            if (!ptconf.gitless) {
                col.push("repo");
            }
        }

        for (let c of col) {
            if (typeof c === "string") {
                c = {type: c, name: c};
            }
            Object.assign(c, gcoldef[c.type]);
            c.ptconf = ptconf;
            ptconf.add_column(c);
        }
        col = ptconf.col;
        colmap = ptconf.colmap;

        if (table.closest("form")) {
            slist_input = document.createElement("input");
            slist_input.type = "hidden";
            slist_input.name = "slist";
            table.after(slist_input);
        }
    }


    function assign_slist() {
        var j = [];
        for (var i = 0; i !== data.length; ++i) {
            j.push(ptconf.ukey(data[i]));
        }
        slist_input.value = j.join(" ");
    }

    function rerender_rownumber() {
        const idx = colmap.rownumber.index;
        let trn = 0;
        for (let tr = table.tBodies[0].firstChild; tr; tr = tr.nextSibling) {
            if (hasClass(tr, "gt") && !tr.hidden) {
                ++trn;
                tr.children.item(idx).textContent = trn + ".";
            }
        }
    }

    function render_user_compare(u) {
        let t = "";
        if ((active_nameflag & 8) && u.anon_user) {
            t = u.anon_user + " ";
        } else if (active_nameflag & 1) {
            t = render_name(u, (active_nameflag & 2) === 2) + " ";
        }
        if ((active_nameflag & 4) && u.email) {
            t += u.email;
        } else {
            t += u.user || "";
        }
        if (u.pset != null) {
            t += sprintf(" %5s", u.pset);
        }
        if (u.at != null) {
            t += sprintf(" %.11g", u.at);
        }
        return t.toLowerCase();
    }

    function set_user_sorters() {
        const nf = sort_nameflag();
        if (nf !== active_nameflag) {
            active_nameflag = nf;
            for (var i = 0; i !== data.length; ++i) {
                data[i]._sort_user = render_user_compare(data[i]);
            }
        }
    }

    function sort_data() {
        let f = ptconf.sort.f;
        set_user_sorters();
        let colr = colmap[f];
        if (colr && colr.compare && colr.compare !== true) {
            data.sort(colr.compare);
        } else if (colr && colr.make_compare) {
            data.sort(colr.make_compare(ptconf.sort, colr.subtype || null));
        } else if (((f === "name" || f === "name2") && !ptconf.anonymous)
                   || f === "user") {
            data.sort(user_compare);
        } else if (f === "gradestatus") {
            data.sort(function (a, b) {
                const av = a.scores_visible, bv = b.scores_visible;
                if (av !== bv) {
                    return av ? -1 : 1;
                } else if (a.has_notes != b.has_notes) {
                    return a.has_notes ? -1 : 1;
                } else {
                    return grader_compare(a, b) || user_compare(a, b);
                }
            });
        } else if (ptconf.sort.email && !ptconf.anonymous) {
            f = "username";
            data.sort(function (a, b) {
                var ae = (a.email || "").toLowerCase(), be = (b.email || "").toLowerCase();
                if (ae !== be) {
                    if (ae === "" || be === "")
                        return ae === "" ? 1 : -1;
                    else
                        return ae < be ? -1 : 1;
                } else {
                    return user_compare(a, b);
                }
            });
        } else { /* "username" */
            if (f !== "name2") {
                f = "username";
            }
            data.sort(user_compare);
        }

        if (ptconf.sort.rev < 0) {
            data.reverse();
        }
        data.sort(function (a, b) {
            return a.boringness !== b.boringness ? a.boringness - b.boringness : 0;
        });
    }

    function checkbox_click() {
        const range = this.getAttribute("data-range-type");
        if ((range === "s61" && lpintable) || range === "s61o") {
            const wanto = range === "s61",
                tr0 = this.closest("tr"),
                tr1 = (wanto ? lpintable : table).rows[tr0.rowIndex],
                cb1 = ptconf.user_row_checkbox(tr1);
            if (cb1.checked !== this.checked) {
                cb1.click();
            }
        }
    }

    function make_overlay_observer() {
        let i = 0;
        while (i !== col.length && !col[i].pin) {
            ++i;
        }
        var overlay_div = $('<div style="position:absolute;left:0;top:0;bottom:0;width:' + (col[i].left - 10) + 'px;pointer-events:none"></div>').prependTo($j.parent())[0],
            table_hit = false, left_hit = false;
        function observer_fn(entries) {
            for (let e of entries) {
                if (e.target === overlay_div) {
                    left_hit = e.isIntersecting;
                } else {
                    table_hit = e.isIntersecting;
                }
            }
            if (table_hit && (!left_hit || lpintable)) {
                if (!left_hit && !lpintable) {
                    make_overlay();
                }
                toggleClass(lpintable.parentElement, "hidden", left_hit);
                lpinhdrtable && toggleClass(lpinhdrtable, "hidden", left_hit);
            }
        }
        var observer = new IntersectionObserver(observer_fn);
        observer.observe(overlay_div);
        observer.observe(table.parentElement);
    }

    function make_overlay() {
        const cx = ptconf.pinned_columns;
        let tw = 0;
        for (const c of cx) {
            tw += c.width;
        }

        lpintable = document.createElement("table");
        lpintable.className = "gtable-left-pin user-gtable gtable-fixed new";
        lpintable.style.width = (tw + 24) + "px";
        lpintable.appendChild(ptable_thead(cx, ptconf, true));
        lpintable.appendChild(document.createElement("tbody"));
        lpintable.tHead.firstChild.classList.add("kfade");

        let tr = table.rows[0], otr = lpintable.rows[0];
        for (let i = 0; i !== cx.length; ++i) {
            otr.childNodes[i].className = tr.childNodes[cx[i].index].className;
        }

        const div = document.createElement("div");
        div.className = "gtable-container-left-pin";
        div.appendChild(lpintable);
        table.parentNode.prepend(div);
        lpintable.tHead.addEventListener("click", ptable_head_click);
        $(lpintable).find("tbody").on("click", "input[type=checkbox]", checkbox_click);

        ptable_permute(ptconf, lpintable, data);
        lpinhdrtable = ptable_track_header(lpintable);
        $j.find("input[data-range-type=s61]:checked").each(checkbox_click);
        lpintable.addEventListener("mouseenter", user_hover, true);
        lpintable.addEventListener("mouseleave", user_hover, true);
        queueMicrotask(function () {
            removeClass(lpintable, "new");
            lpinhdrtable && removeClass(lpinhdrtable, "new");
        });

        $alltables = $alltables.add(lpintable).add(lpinhdrtable);
    }

    function set_tables_width() {
        const lastc = col[col.length - 1];
        let width = lastc.left + lastc.width;
        if (ptconf.anonymous) {
            colmap.name && (width -= colmap.name.width);
            colmap.year && (width -= colmap.year.width);
        }
        $alltables.each(function () {
            if (!hasClass(this, "gtable-left-pin")) {
                this.style.width = width + "px";
            }
        });
    }

    function render() {
        const tfixed = $j.hasClass("want-gtable-fixed"),
            thead = ptable_thead(col, ptconf, true);

        toggleClass(table, "gt-anonymous", !!ptconf.anonymous);
        table.appendChild(thead);
        toggleClass(table, "gt-useemail", !!ptconf.sort.email);
        if (tfixed) {
            set_tables_width();
            removeClass(table, "want-gtable-fixed");
            addClass(table, "gtable-fixed");
        }

        const tbody = document.createElement("tbody");
        tbody.className = "has-hotlist";
        table.appendChild(tbody);
        if (!ptconf.no_sort) {
            sort_data();
            rerender_sort_header();
        }

        for (const su of data) {
            ptconf.push(su);
            for (const psu of su.partners || []) {
                ptconf.push(psu);
            }
        }

        ptable_permute(ptconf, table, data);
        colmap.rownumber && rerender_rownumber();
        slist_input && assign_slist();
        $j.find("tbody").on("click", "input[type=checkbox]", checkbox_click);

        hdrtable = ptable_track_header(table);
        $alltables = $alltables.add(hdrtable);
        if (tfixed
            && window.IntersectionObserver
            && table.closest(".gtable-container-2")) {
            make_overlay_observer();
        }
    }

    function rerender_sort_header() {
        $alltables.children("thead").find(".plsortable").removeClass("plsortactive plsortreverse");
        $alltables.children("thead")
            .find("th[data-pa-sort='" + ptconf.sort.f + "']")
            .addClass("plsortactive")
            .toggleClass("plsortreverse", ptconf.sort.rev < 0);
    }

    function sortchange(evt) {
        const osort = ptconf.sort, nsort = evt.detail.sort;
        ptconf.sort = nsort;
        if (ptconf.overridable_anonymous
            && nsort.deblind != osort.deblind) {
            ptconf.anonymous = !nsort.deblind;
            $alltables.toggleClass("gt-anonymous", !!ptconf.anonymous);
            set_tables_width();
            $alltables.children("tbody").find("input.gt-check").each(function () {
                const s = ptconf.smap[this.parentNode.parentNode.getAttribute("data-pa-spos")];
                this.setAttribute("name", ptconf.render_checkbox_name(s));
            });
            const anon = table.closest("form").elements.anonymous;
            anon && (anon.value = ptconf.anonymous ? "1" : "0");
        }
        if (nsort.last !== osort.last
            || nsort.deblind != osort.deblind) {
            ptables_rerender_key(ptconf, $alltables, "name", "name2", "username", "user");
        } else if (nsort.u !== osort.u) {
            ptables_rerender_key(ptconf, $alltables, "username", "user");
        }

        sort_data();
        ptable_permute(ptconf, table, data);
        lpintable && ptable_permute(ptconf, lpintable, data);
        colmap.rownumber && rerender_rownumber();
        rerender_sort_header();
        slist_input && assign_slist();
        wstorage.site(true, "pa-pset" + ptconf.id + "-table", JSON.stringify(nsort));
    }

    function events() {
        const f = table.closest("form");
        table.tBodies[0].addEventListener("pa-hotlist", ptable_make_hotlist);
        table.tBodies[0].addEventListener("click", ptable_body_click);
        table.addEventListener("mouseenter", user_hover, true);
        table.addEventListener("mouseleave", user_hover, true);
        table.tHead.addEventListener("click", ptable_head_click);
        f.addEventListener("rangechange", ptable_rangechange);
        f.addEventListener("sortchange", sortchange);
    }

    initialize();
    render();
    events();
}


tooltip.add_builder("pa-ptable-user", function () {
    let spos = this.closest("tr").getAttribute("data-pa-spos"),
        ptconf = this.closest("form").pa__ptconf,
        su = ptconf.smap[spos];
    return {content: new Promise((resolve) => {
        const maindiv = document.createElement("div"),
            anon = ptconf.anonymous && su.anon_user;
        maindiv.className = "d-flex align-items-center";
        if (su.imageid && !anon) {
            const ae = ptconf.make_student_ae(su),
                img = document.createElement("img");
            img.className = "pa-tinyface";
            img.src = hoturl("face", {u: su.user, imageid: su.imageid});
            ae.append(img);
            maindiv.append(ae);
        }
        const idiv = document.createElement("div");
        maindiv.append(idiv);
        const userae = ptconf.make_student_ae(su);
        userae.className += " font-weight-bold";
        userae.append(anon ? su.anon_user : su.user);
        idiv.append(userae);
        if (su.x) {
            idiv.append(" (X)");
        } else if (!anon && su.year) {
            idiv.append(" " + render_year(su.year));
        }
        idiv.append(document.createElement("br"));
        if (!anon) {
            const name = render_name(su, false);
            if (name !== "") {
                const nameae = ptconf.make_student_ae(su);
                nameae.className = "q";
                nameae.append(name);
                idiv.append(nameae, document.createElement("br"));
            }
            if (su.email) {
                idiv.append(su.email, document.createElement("br"));
            }
        }
        resolve(maindiv);
    }), delay: 400, className: "gray small ml-2", dir: "w",
        noDelayClass: "pa-ptable-user"};
});


function ptable_recolor(tb) {
    const klasses = ["k0", "k1"];
    let trn = 1, boring = null, rownum = false;
    for (let tr = tb.firstChild; tr; tr = tr.nextElementSibling) {
        if (tr.className === "gt-boring") {
            tr.hidden = true;
            boring = trn > 1 ? tr : null;
            continue;
        } else if (tr.hidden) {
            continue;
        }
        if (rownum === false) {
            rownum = null;
            for (let e = tr.firstChild, i = 0; e; e = e.nextSibling, ++i) {
                if (e.className === "gt-rownumber")
                    rownum = i;
            }
        }
        if (rownum !== null) {
            tr.children.item(rownum).textContent = trn + ".";
        }
        tr.classList.remove(klasses[(trn ^ 1) & 1]);
        tr.classList.add(klasses[trn & 1]);
        if (!tr.classList.contains("gtrow-partner")) {
            ++trn;
        }
        if (boring) {
            boring.hidden = false;
            boring = null;
        }
    }
}

let search_ptconf, search_target;

const search_keywords = {
    grade: (text, ml) => {
        if (text === "no") {
            return (se, su) => su.grade_status < 0 || (!su.grade_status && su.emptydiff);
        } else if (text === "yes") {
            return (se, su) => su.grade_status > 0;
        } else if (text === "user") {
            return (se, su) => su.grade_status === 1;
        } else if (text === "none") {
            return (se, su) => !su.grade_status;
        }
        ml.push({status: 2, message: `<0>Expected \`grade:no\`, \`grade:yes\`, \`grade:user\`, or \`grade:none\``});
        return () => false;
    },
    grader: (text, ml) => {
        if (text === "any") {
            return (se, su) => !!su.gradercid;
        } else if (text === "none") {
            return (se, su) => !su.gradercid;
        }
        const re = new RegExp(regexp_quote(SearchParser.unquote(text)), "i"),
            mcids = [];
        for (const cid in siteinfo.pc) {
            const pc = siteinfo.pc[cid];
            if (re.test(grader_name(pc)))
                mcids.push(+cid);
        }
        if (mcids.length === 1) {
            return (se, su) => su.gradercid === mcids[0];
        } else if (mcids.length > 1) {
            return (se, su) => mcids.includes(su.gradercid);
        }
        ml.push({status: 2, message: `<0>\`${text}\` matches no graders`});
        return () => false;
    },
    year: (text, ml) => {
        if (!/^(?:(?:\d+[-–—]\d+|[a-zA-Z\d]+)(?=,\w|$),?)+$/.test(text)) {
            ml.push({status: 2, message: `<0>Bad \`year\``});
            return () => false;
        }
        text = text.toUpperCase();
        const a = [];
        for (const m of text.matchAll(/(\w+)[-–—]?(\w*)/g)) {
            if (m[2]) {
                a.push(+m[1], +m[2]);
            } else if (/^\d+$/.test(m[1])) {
                a.push(+m[1], null);
            } else {
                a.push(m[1], null);
            }
        }
        return (se, su) => {
            if (!su.year) {
                return false;
            }
            const num = typeof su.year === "number";
            for (let i = 0; i !== a.length; i += 2) {
                if (a[i + 1] === null
                    ? su.year === a[i]
                    : num && su.year >= a[i] && su.year <= a[i + 1])
                    return true;
            }
            return false;
        };
    },
    is: (text, ml) => {
        if (text === "x" || text === "X") {
            return (se, su) => !!su.x;
        } else if (text === "college") {
            return (se, su) => !su.x;
        } else if (text === "dropped") {
            return (se, su) => !!su.dropped;
        }
        ml.push({status: 2, message: `<0>Expected \`is:x\`, \`is:college\`, or \`is:dropped\``});
        return () => false;
    }
};

function make_search_name(text) {
    const re = new RegExp(regexp_quote(text), "i");
    return () => {
        if (search_ptconf.anonymous) {
            return re.test(search_target.anon_user);
        }
        return re.test(search_target.first)
            || re.test(search_target.last)
            || re.test(search_target.email)
            || re.test(search_target.user);
    };
}

const grade_search_keywords = {
    any: (gidx) => { return () => search_target.grades[gidx] != null; },
    none: (gidx) => { return () => search_target.grades[gidx] == null; },
    eq: (gidx, v) => { return () => search_target.grades[gidx] == v; },
    ne: (gidx, v) => { return () => search_target.grades[gidx] != v; },
    gt: (gidx, v) => { return () => search_target.grades[gidx] > v; },
    ge: (gidx, v) => { return () => search_target.grades[gidx] >= v; },
    lt: (gidx, v) => { return () => search_target.grades[gidx] < v; },
    le: (gidx, v) => { return () => search_target.grades[gidx] <= v; }
};

function parse_search_compar(text) {
    const m = text.match(/^(<=?|>=?|==?|!=?|≤|≥|≠|(?=[\d\.]))\s*(\d+\.?\d*|\.\d+)$/);
    if (!m) {
        return null;
    }
    const gv = +m[2];
    if (m[1] === "=" | m[1] === "==" || m[1] === "") {
        return {op: "eq", value: gv};
    } else if (m[1] === "!" || m[1] === "!=" || m[1] === "≠") {
        return {op: "ne", value: gv};
    } else if (m[1] === "<") {
        return {op: "lt", value: gv};
    } else if (m[1] === "<=" || m[1] === "≤") {
        return {op: "le", value: gv};
    } else if (m[1] === ">") {
        return {op: "gt", value: gv};
    } else {
        return {op: "ge", value: gv};
    }
}

function make_compare(op) {
    if (op === "eq") {
        return (a, b) => a == b;
    } else if (op === "ne") {
        return (a, b) => a != b;
    } else if (op === "lt") {
        return (a, b) => a < b;
    } else if (op === "le") {
        return (a, b) => a <= b;
    } else if (op === "gt") {
        return (a, b) => a > b;
    } else if (op === "ge") {
        return (a, b) => a >= b;
    } else {
        return () => false;
    }
}

function make_search_keyword_compare(gi, ge, text, ml) {
    const gidx = ge.value_order_in(gi);
    if (text === "any") {
        return grade_search_keywords.any(gidx);
    } else if (text === "none") {
        return grade_search_keywords.none(gidx);
    }
    const compar = parse_search_compar(text);
    if (!compar) {
        ml.push({status: 2, message: `<0>Search comparison \`${text}\` not understood`});
        return null;
    }
    return grade_search_keywords[compar.op](gidx, compar.value);
}

function find_search_keyword(se, ptconf, ml) {
    let kw = se.kword, text = se.text;
    if (!kw) {
        if (text === "" || text === "*" || text === "ANY" || text === "ALL") {
            return () => true;
        }
        let m = text.match(/^([a-zA-Z][-_a-zA-Z0-9]*)((?:<=?|>=?|==?|!=?|≤|≥|≠)[^<>=!]*)$/);
        if (!m) {
            return make_search_name(se.unquoted_text());
        }
        kw = m[1];
        text = m[2];
    }

    const skw = search_keywords[kw];
    if (skw) {
        return skw(text, ml);
    }

    const gi = ptconf.gradesheet,
        collator = new Intl.Collator(undefined, {usage: "search", sensitivity: "accent"});
    let matchge = null, titlege = [];
    for (let i = 0; i !== gi.value_order.length; ++i) {
        const ge = gi.entries[gi.value_order[i]];
        if (ge.key === kw) {
            matchge = ge;
            break;
        } else if (collator.compare(ge.title_text, kw) === 0
                   || collator.compare(ge.abbr(), kw) === 0) {
            titlege.push(ge);
        }
    }
    if (!matchge && titlege.length === 1) {
        matchge = titlege[0];
    }
    if (matchge) {
        return make_search_keyword_compare(gi, matchge, text, ml);
    }
    if ((kw === "tot" || kw === "total")
        && ptconf.need_total) {
        const compar = parse_search_compar(text);
        if (compar) {
            const f = make_compare(compar.op);
            return (se, su) => f(su.total, compar.value);
        }
    }
    ml.push({status: 2, message: `<0>Search \`${kw}\` not understood`});
    return null;
}

function prepare_search(se, ml) {
    se.user_data = find_search_keyword(se, search_ptconf, ml);
}

function evaluate_search(se) {
    return se.user_data ? se.user_data(se, search_target) : false;
}

function ptable_search(search) {
    const form = search.closest("form"),
        ptconf = form.pa__ptconf;
    ptconf.input_timeout = null;
    if (hasClass(search, "js-ptable-search-api")) {
        return ptable_search_api(search, ptconf);
    }
    let pexpr = new SearchParser(search.value).parse_expression();
    if (pexpr && !pexpr.op && !pexpr.kword && !pexpr.text) {
        pexpr = null;
    }
    tooltip.close_under(search);
    if (pexpr) {
        const ml = [];
        pexpr.prepare_simple(prepare_search, ml);
        if (ml.length > 0) {
            tooltip.enter(search, {content: feedback.render_list(ml), className: "gray"});
        }
    }
    search_ptconf = ptconf;
    const changed = [];
    ptconf.has_hidden = false;
    for (const ss of ptconf.smap) {
        if (!ss) {
            continue;
        }
        search_target = ss;
        const hidden = pexpr ? !pexpr.evaluate_simple(evaluate_search) : false;
        if (ss.hidden !== hidden) {
            changed.push(ss);
            ss.hidden = hidden;
        }
        if (hidden) {
            ptconf.has_hidden = true;
        }
    }
    search_ptconf = null;
    ptable_search_results(ptconf, form, changed);
}

function ptable_search_api(search, ptconf) {
    $.ajax(hoturl("api/search", {q: search.value, anonymous: ptconf.anonymous ? 1 : 0}), {
        method: "GET", cache: false, dataType: "json",
        success: function (data) {
            if (!data.ok) {
                return;
            }
            const changed = [];
            ptconf.has_hidden = false;
            for (const ss of ptconf.smap) {
                if (!ss) {
                    continue;
                }
                const hidden = !data.uids.includes(ss.uid);
                if (ss.hidden !== hidden) {
                    changed.push(ss);
                    ss.hidden = hidden;
                }
                if (hidden) {
                    ptconf.has_hidden = true;
                }
            }
            ptable_search_results(ptconf, search.form, changed);
        }
    });
}

function ptable_search_results(ptconf, form, changed) {
    if (!changed.length) {
        return;
    }
    for (const tbody of form.querySelectorAll("table.user-gtable > tbody")) {
        const rowmap = ptconf.tbody_rowmap(tbody);
        for (const ss of changed) {
            const tr = rowmap.rows[ss._spos];
            tr && (tr.hidden = ss.hidden);
        }
        ptable_recolor(tbody);
        const cb = tbody.parentElement.tHead.querySelector(".js-range-click");
        cb && handle_ui.trigger.call(cb, "js-range-click", "updaterange");
    }
    $(form).find(".pa-grgraph").trigger("redrawgraph");
}

handle_ui.on("js-ptable-search", function (evt) {
    let ptconf = this.closest("form").pa__ptconf, timeout = 300;
    if (evt.type === "keydown" && event_key(evt) === "Enter") {
        evt.preventDefault();
        timeout = 0;
    }
    ptconf.input_timeout && clearTimeout(ptconf.input_timeout);
    ptconf.input_timeout = setTimeout(ptable_search, timeout, this);
});


handle_ui.on("js-pset-gconfig", function () {
    let ptconf = this.closest("form").pa__ptconf;
    let $gdialog, form;

    function change_state() {
        const state = form.elements.state.value;
        toggleClass(form.elements.frozen.parentElement, "invisible",
                    state === "disabled" || state === "hidden");
        toggleClass(form.elements.anonymous.parentElement, "invisible",
                    state === "disabled");
    }

    function submit() {
        $.ajax(hoturl("=api/psetconfig", {pset: ptconf.key}), {
            method: "POST",
            data: $(form).serializeWith({}),
            success: function () {
                location.hash = "#" + ptconf.key;
                location.reload();
            }
        });
    }

    function gdialog() {
        $gdialog = $popup()
            .append($e("h2", "pa-home-pset", ptconf.title + " Settings"),
                $e("div", "pa-messages"),
                $e("div", "d-grid-1 grid-gap-2",
                    $e("span", "select", $e("select", {name: "state"},
                        $e("option", {value: "disabled"}, "Disabled"),
                        $e("option", {value: "hidden"}, "Hidden"),
                        $e("option", {value: "visible"}, "Visible without grades"),
                        $e("option", {value: "scores_visible"}, "Visible with grades"))),
                    $e("span", "select", $e("select", {name: "frozen"},
                        $e("option", {value: "no"}, "Student updates allowed"),
                        $e("option", {value: "yes"}, "Submissions frozen"))),
                    $e("span", "select", $e("select", {name: "anonymous"},
                        $e("option", {value: "no"}, "Open grading"),
                        $e("option", {value: "yes"}, "Anonymous grading")))))
            .append_actions($e("button", {type: "button", name: "bsubmit", class: "btn-primary"}, "Save"), "Cancel");
        form = $gdialog.form();

        let v;
        if (ptconf.disabled) {
            v = "disabled";
        } else if (!ptconf.visible) {
            v = "hidden";
        } else {
            v = ptconf.scores_visible ? "scores_visible" : "visible";
        }
        form.elements.state.value = v;
        form.elements.state.setAttribute("data-default-value", v);
        form.elements.state.addEventListener("change", change_state);
        change_state();

        v = ptconf.frozen ? "yes" : "no";
        form.elements.frozen.value = v;
        form.elements.frozen.setAttribute("data-default-value", v);

        v = ptconf.anonymous ? "yes" : "no";
        form.elements.anonymous.value = v;
        form.elements.anonymous.setAttribute("data-default-value", v);

        $gdialog.find("button[name=bsubmit]").on("click", submit);
        $gdialog.show();
    }
    gdialog();
});
