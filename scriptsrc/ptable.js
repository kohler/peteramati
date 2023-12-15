// ptable.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, removeClass, toggleClass,
         handle_ui } from "./ui.js";
import { wstorage, sprintf, strftime } from "./utils.js";
import { hoturl, hoturl_post_go } from "./hoturl.js";
import { escape_entities } from "./encoders.js";
import { GradeSheet } from "./gradeentry.js";
import { popup_skeleton, popup_close } from "./popup.js";
import { tooltip } from "./tooltip.js";


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

function render_year(yr, html) {
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
    } else {
        return html ? escape_entities(yr) : yr;
    }
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
            pset: s.pset || this.key
        };
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
            ae.append(render_name(s, sort.last));
        } else {
            ae.append(s.user);
        }
        tde.replaceChildren(ae);
    }

    render_display_name(tde, s, is2) {
        const t = is2 && this.anonymous
            ? s.anon_user || "?"
            : render_name(s, this.sort.last);
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
            return render_name(s, false);
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
        const table = form.querySelector("table.gtable"),
            cbidx = this.colmap.checkbox.index,
            sus = [], chsus = [];
        for (let tr = table.tBodies[0].firstChild; tr; tr = tr.nextSibling) {
            const spos = tr.getAttribute("data-pa-spos"),
                su = spos ? this.smap[spos] : null;
            if (su) {
                sus.push(su);
                if (users && tr.children[cbidx].firstChild.checked)
                    chsus.push(su);
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


let ptable_observer, ptables = [];

function ptable_header_observer(entries) {
    for (const entry of entries) {
        entry.target.pa__intersecting = entry.isIntersecting;
    }
}

function ptable_header_scroller() {
    for (const table of ptables) {
        if (table.pa__intersecting) {
            const r = table.getBoundingClientRect(),
                pt = table.previousSibling;
            if (r.top > 0) {
                pt.style.display = "none";
            } else {
                pt.style.display = "table";
                pt.style.top = Math.min(-r.top, r.height - 48) + "px";
            }
        }
    }
}

function ptable_track_header(table) {
    if (!window.IntersectionObserver || table.rows.length <= 10) {
        return null;
    }
    if (!ptable_observer) {
        ptable_observer = new IntersectionObserver(ptable_header_observer, {rootMargin: "-32px 0px"});
        document.addEventListener("scroll", ptable_header_scroller, {passive: true});
    }
    ptable_observer.observe(table);
    ptables.push(table);
    const tctable = document.createElement("table");
    tctable.className = table.className;
    tctable.classList.add("gtable-top-pin");
    tctable.classList.remove("gtable");
    tctable.style.width = table.style.width;
    tctable.appendChild(table.tHead.cloneNode(true));
    table.before(tctable);
    tctable.tHead.addEventListener("click", ptable_head_click);
    table.pa__intersecting = true;
    queueMicrotask(ptable_header_scroller);
    return tctable;
}

function ptable_decorate_name_th(the, ptconf) {
    if (ptconf.overridable_anonymous) {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "btn-ulink n js-switch-anon";
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
            ae.append(siteinfo.psets[s.pset].title + (s.commit ? "/" + s.commit.substr(0, 7) : ""));
            tde.append(ae);
        },
        tw: 12,
        sort_forward: true,
        compare: function (a, b) {
            if (a.pset != b.pset)
                return siteinfo.psets[a.pset].pos < siteinfo.psets[b.pset].pos ? -1 : 1;
            else
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
                t += '✱';
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
        td: function (tde, s) {
            const gr = s.grades[this.gidx];
            tde.className = this.className;
            if (s.autogrades
                && s.autogrades[this.gidx] != null
                && s.autogrades[this.gidx] !== gr) {
                tde.className += " gt-highlight";
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

function ptable_permute_rmap(table) {
    const rmap = {};
    let tr = table.tBodies[0].firstChild;
    while (tr) {
        rmap[tr.getAttribute("data-pa-spos")] = tr;
        tr = tr.nextSibling;
    }
    return rmap;
}

function ptable_permute(ptconf, table, data) {
    const tb = table.tBodies[0],
        rmap = ptable_permute_rmap(table),
        mode = table.classList.contains("gtable-left-pin") ? 2 : 0,
        klasses = ["k0", "k1"];
    let was_boringness = false, trn = 0,
        last = tb.firstChild;
    for (let i = 0; i !== data.length; ++i) {
        const su = data[i];
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

        ++trn;
        for (const ss of su.partners ? [su, ...su.partners] : [su]) {
            const tr = rmap[ss._spos]
                || ptconf.render_table_row(su, su === ss ? mode : mode | 1);
            if (tr !== last) {
                tb.insertBefore(tr, last);
            } else {
                last = last.nextSibling;
            }
            tr.classList.remove(klasses[(trn ^ 1) & 1]);
            tr.classList.add(klasses[trn & 1]);
        }
    }
}



export function pa_pset_table(form, pconf, data) {
    const ptconf = new PtableConf(pconf, data || []);
    form.pa__ptconf = ptconf;
    let table = $(form).find("table.gtable");
    if (table.length) {
        pa_render_pset_table.call(table[0], ptconf);
    }
}

function pa_render_pset_table(ptconf) {
    const table = this, $j = $(table),
        smap = ptconf.smap;
    let $alltables = $(table), lpintable = null, slist_input,
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
            } catch (e) {
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
                       || (!s.grade_commit && !s.commit && !ptconf.gitless_grades)) {
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
            any_visible = any_visible || s.scores_visible_pinned;
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
                c = {type: c, name: c, ptconf: ptconf};
            }
            Object.assign(c, gcoldef[c.type]);
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


    function make_hotlist(event) {
        const j = [];
        for (const s of data) {
            let t = "~".concat(encodeURIComponent(ptconf.ukey(s)));
            if (ptconf.flagged_commits) {
                t = t.concat("/pset/", s.pset);
                if (s.commit)
                    t = t.concat("/", s.commit);
            }
            j.push(t);
        }
        event.detail.hotlist = {pset: ptconf.flagged_commits ? null : ptconf.key, items: j};
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
            if (hasClass(tr, "gt")) {
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
        lpintable.className = "gtable-left-pin gtable-fixed new";
        lpintable.setAttribute("style", "position:absolute;left:0;width:".concat(tw + 24, "px"));
        lpintable.appendChild(ptable_thead(cx, ptconf, true));
        lpintable.appendChild(document.createElement("tbody"));
        lpintable.tHead.firstChild.classList.add("kfade");

        let tr = table.rows[0],
            otr = lpintable.rows[0];
        for (let i = 0; i !== cx.length; ++i) {
            otr.childNodes[i].className = tr.childNodes[cx[i].index].className;
        }

        const div = document.createElement("div");
        div.setAttribute("style", "position:sticky;left:0;z-index:2");
        div.appendChild(lpintable);
        table.parentNode.prepend(div);
        lpintable.tHead.addEventListener("click", ptable_head_click);
        $(lpintable).find("tbody").on("click", "input[type=checkbox]", checkbox_click);

        ptable_permute(ptconf, lpintable, data);
        const ltpintable = ptable_track_header(lpintable);
        $j.find("input[data-range-type=s61]:checked").each(checkbox_click);
        lpintable.addEventListener("mouseenter", user_hover, true);
        lpintable.addEventListener("mouseleave", user_hover, true);
        queueMicrotask(function () {
            removeClass(lpintable, "new");
            ltpintable && removeClass(ltpintable, "new");
        });

        $alltables = $alltables.add(lpintable, ltpintable);
    }

    function set_tables_width() {
        const lastc = col[col.length - 1];
        let width = lastc.left + lastc.width;
        if (ptconf.anonymous) {
            colmap.name && (width -= colmap.name.width);
            colmap.year && (width -= colmap.year.width);
        }
        $alltables.each(function () {
            this.style.width = width + "px";
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

        $alltables = $alltables.add(ptable_track_header(table));
        if (tfixed && window.IntersectionObserver) {
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
                const s = smap[this.parentNode.parentNode.getAttribute("data-pa-spos")];
                this.setAttribute("name", ptconf.render_checkbox_name(s));
            });
            table.closest("form").elements.anonymous.value = ptconf.anonymous ? "1" : "0";
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
        table.tBodies[0].addEventListener("pa-hotlist", make_hotlist);
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
                nameae.append(render_name(su, false));
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
        const hc = popup_skeleton();
        hc.push('<h2 class="pa-home-pset">' + escape_entities(ptconf.title) + ' Settings</h2>');
        hc.push('<div class="pa-messages"></div>');
        hc.push('<div class="d-grid-1 grid-gap-2">', '</div>');

        hc.push('<span class="select"><select name="state">', '</select></span>');
        hc.push('<option value="disabled">Disabled</option>');
        hc.push('<option value="hidden">Hidden</option>');
        hc.push('<option value="visible">Visible without grades</option>');
        hc.push('<option value="scores_visible">Visible with grades</option>');
        hc.pop();

        hc.push('<span class="select"><select name="frozen">', '</select></span>');
        hc.push('<option value="no">Student updates allowed</option>');
        hc.push('<option value="yes">Submissions frozen</option>');
        hc.pop();

        hc.push('<span class="select"><select name="anonymous">', '</select></span>');
        hc.push('<option value="no">Open grading</option>');
        hc.push('<option value="yes">Anonymous grading</option>');
        hc.pop();

        hc.pop();
        hc.push_actions();
        hc.push('<button type="button" name="bsubmit" class="btn-primary">Save</button>');
        hc.push('<button type="button" name="cancel">Cancel</button>');
        $gdialog = hc.show(false);
        form = $gdialog.find("form")[0];

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
        hc.show();
    }
    gdialog();
});


handle_ui.on("js-ptable-run", function () {
    let f = this.closest("form"),
        ptconf = f.pa__ptconf, $gdialog, form, slist;

    function submit() {
        if (form.elements.runner.value) {
            const run = form.elements.runner.value +
                    (form.elements.ifneeded.checked ? ".ifneeded" : ""),
                skeys = [],
                param = {pset: ptconf.key, run: run, runmany: 1},
                data = {};
            for (const su of slist) {
                skeys.push(ptconf.ukey(su));
            }
            const users = skeys.join(" ");
            if (users.length <= 160) {
                param.users = users;
            } else {
                data.users = users;
            }
            hoturl_post_go("=run", param, data);
        } else {
            popup_close.call(form);
        }
    }

    function gdialog() {
        slist = ptconf.users_in(f, SOME_USERS);

        const hc = popup_skeleton();
        hc.push('<h2 class="pa-home-pset">' + escape_entities(ptconf.title) + ' Commands</h2>');
        hc.push('<h3 class="gdialog-userids"></h3>');
        hc.push('<div class="pa-messages"></div>');

        hc.push('<div class="mt-1 multicol-2">', '</div>');
        for (let rn in ptconf.runners) {
            hc.push('<label class="checki"><span class="checkc"><input type="radio" name="runner" value="' + rn + '"></span>' + escape_entities(ptconf.runners[rn]) + '</label>');
        }
        hc.pop();

        hc.push('<label class="checki mt-2"><span class="checkc"><input type="checkbox" name="ifneeded" value="1"></span>Use prerecorded runs when available</label>');

        hc.push_actions();
        hc.push('<button type="button" name="run" class="btn-primary">Run</button>');
        hc.push('<button type="button" name="cancel">Cancel</button>');
        $gdialog = hc.show(false);
        ptconf.render_gdialog_users($gdialog.find("h3")[0], slist);
        form = $gdialog.find("form")[0];
        $(form.elements.run).on("click", submit);
        hc.show();
    }

    gdialog();
});
