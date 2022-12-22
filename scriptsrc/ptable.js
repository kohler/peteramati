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
    if (evt.type === "mouseenter") {
        tooltip.enter(this, "pa-ptable-user");
    } else {
        tooltip.leave(this);
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
        this.can_override_anonymous = pconf.can_override_anonymous;
        this.flagged_commits = pconf.flagged_commits;
        this.last_first = null;
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
        this.col = pconf.col || null;

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
        const ae = this.make_pset_ae(s);
        if (this.anonymous && s.anon_user) {
            ae.append(s.anon_user);
        } else if (this.sort.u === "email" && s.email) {
            ae.append(s.email);
        } else if (this.sort.u === "name") {
            ae.append(render_name(s, this.last_first));
        } else {
            ae.append(s.user);
        }
        tde.replaceChildren(ae);
    }

    render_display_name(tde, s, is2) {
        const t = is2 && this.anonymous
            ? s.anon_user || "?"
            : render_name(s, this.last_first);
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
        const overlay = hasClass(tr.parentElement.parentElement, "gtable-overlay"),
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


const gcoldef = {
    rownumber: {
        th: '<th class="gt-rownumber" scope="col"></th>',
        td: function (tde) {
            tde.className = "gt-rownumber";
        },
        tw: function (ptconf) {
            return Math.ceil(Math.log10(Math.max(ptconf.data.length, 1))) * 0.75;
        }
    },

    checkbox: {
        th: '<th class="gt-checkbox" scope="col"><input type="checkbox" class="uic js-range-click is-range-group ignore-diff" data-range-type="s61" aria-label="Select all"></th>',
        td: function (tde, s, ptconf) {
            if (ptconf.mode & 1) {
                return;
            }
            tde.className = "gt-checkbox";
            let cbe = document.createElement("input");
            cbe.type = "checkbox";
            cbe.value = 1;
            if (ptconf.mode & 2) {
                cbe.className = "uic js-range-click";
                cbe.setAttribute("data-range-type", "s61o");
            } else {
                cbe.name = ptconf.render_checkbox_name(s);
                cbe.className = this.className || "uic js-range-click papsel";
                cbe.setAttribute("data-range-type", "s61");
            }
            tde.appendChild(cbe);
        },
        tw: 2,
        pin: true
    },

    flagcheckbox: {
        th: '<th class="gt-checkbox" scope="col"></th>',
        td: function (tde, s, ptconf) {
            if (ptconf.mode) {
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
        th: '<th class="gt-pset l plsortable" data-pa-sort="pset" scope="col">Pset</th>',
        td: function (tde, s, ptconf) {
            tde.className = "gt-pset";
            let ae = document.createElement("a");
            ae.href = ptconf.href(s);
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
        th: function (ptconf) {
            let t = '<span class="heading">';
            if (ptconf.anonymous || ptconf.sort.u === "user") {
                t += "Username";
            } else if (ptconf.sort.u === "email") {
                t += "Email";
            } else {
                t += "Name";
            }
            t += '</span>';
            if (ptconf.original_anonymous && ptconf.can_override_anonymous) {
                t += ' <button type="button" class="btn-ulink n js-switch-anon">[anon]</button>';
            } else if (ptconf.original_anonymous) {
                t += ' <span class="n">[anon]</span>';
            }
            return '<th class="gt-user l plsortable" data-pa-sort="user" scope="col">' + t + '</th>';
        },
        td: function (tde, s, ptconf) {
            tde.className = "gt-user";
            ptconf.render_user_td(tde, s);
        },
        tw: 14,
        pin: true,
        sort_forward: true
    },

    username: {
        th: function (ptconf) {
            let t = '<span class="heading">' +
                (ptconf.anonymous || !ptconf.sort.email ? "Username" : "Email") +
                '</span>';
            if (ptconf.original_anonymous && ptconf.can_override_anonymous) {
                t += ' <button type="button" class="btn-ulink n js-switch-anon">[anon]</button>';
            } else if (ptconf.original_anonymous) {
                t += ' <span class="n">[anon]</span>';
            }
            return '<th class="gt-username l plsortable" data-pa-sort="username" scope="col">' + t + '</th>';
        },
        td: function (tde, s, ptconf) {
            tde.className = "gt-username";
            ptconf.render_username_td(tde, s);
        },
        tw: 12,
        pin: true,
        sort_forward: true
    },

    name: {
        th: '<th class="gt-name l plsortable" data-pa-sort="name" scope="col">Name</th>',
        td: function (tde, s, ptconf) {
            tde.className = "gt-name";
            ptconf.render_display_name(tde, s, false);
        },
        tw: 14,
        sort_forward: true
    },

    name2: {
        th: function (ptconf) {
            let t = '<span class="heading">' + (ptconf.anonymous ? "Username" : "Name") + '</span>';
            if (ptconf.original_anonymous && ptconf.can_override_anonymous) {
                t += ' <button type="button" class="btn-ulink n js-switch-anon">[anon]</button>';
            }
            return '<th class="gt-name2 l plsortable" data-pa-sort="name2" scope="col">' + t + '</th>';
        },
        td: function (tde, s, ptconf) {
            tde.className = "gt-name2";
            ptconf.render_display_name(tde, s, true);
        },
        tw: 14,
        sort_forward: true
    },

    at: {
        th: '<th class="gt-at l plsortable" data-pa-sort="at" scope="col">Flagged</th>',
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
        th: '<th class="gt-extension plsortable" data-pa-sort="extension" scope="col">X?</th>',
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
        th: '<th class="gt-year plsortable" data-pa-sort="year" scole="col">Yr</th>',
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
        th: '<th class="gt-grader l plsortable" data-pa-sort="grader" scope="col">Grader</th>',
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
        refreshable: true
    },

    latehours: {
        th: '<th class="gt-latehours r plsortable" data-pa-sort="latehours" scope="col" title="Late">⏰</th>',
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
        refreshable: true
    },

    notes: {
        th: '<th class="gt-notes plsortable" data-pa-sort="gradestatus" scope="col">⎚</th>',
        td: function (tde, s, ptconf) {
            tde.className = "gt-notes c";
            let t = s.scores_visible ? '⎚' : '';
            if (ptconf.flagged_commits && s.grade_commit && s.grade_commit === s.commit) {
                t += '✱';
            }
            if (s.has_notes) {
                t += '♪';
            }
            if (!ptconf.flagged_commits && s.has_nongrader_notes) {
                t += "+";
            }
            tde.replaceChildren(t);
        },
        tw: 2,
        refreshable: true
    },

    total: {
        th: '<th class="gt-total r plsortable" data-pa-sort="total" scope="col">Tot</th>',
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
        refreshable: true
    },

    grade: {
        th: function () {
            return '<th class="'.concat(this.className, ' plsortable" data-pa-sort="', this.name, '" scope="col" title="', escape_entities(this.ge.title_text), '">', this.ge.abbr(), '</th>');
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
        refreshable: true
    },

    ngrades: {
        th: '<th class="gt-ngrades r plsortable" data-pa-sort="ngrades" scope="col">#G</th>',
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
        refreshable: true
    },

    repo: {
        th: '<th class="gt-repo" scope="col"></th>',
        td: function (tde, s, ptconf) {
            tde.className = "gt-repo";
            if (!s.repo) {
                return;
            }
            const ae = document.createElement("a");
            if (ptconf.anonymous) {
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
        th: '<th class="gt-conversation l plsortable" data-pa-sort="conversation" scope="col">Flag</th>',
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


export function pa_pset_table(form, pconf, data) {
    const ptconf = new PtableConf(pconf, data || []);
    form.pa__ptconf = ptconf;
    let table = $(form).find("table.gtable");
    if (table.length) {
        pa_render_pset_table.call(table[0], ptconf);
    }
}

function pa_render_pset_table(ptconf) {
    let $j = $(this), table_width = 0, smap = ptconf.smap,
        $overlay = null, name_col, slist_input,
        gradesheet = null,
        need_ngrades,
        active_nameflag = -1,
        col, colmap, data = ptconf.data;
    let sort = ptconf.sort;

    function string_function(s) {
        return function () { return s; };
    }

    function sort_nameflag() {
        if (ptconf.anonymous) {
            return 8;
        } else if (sort.u === "name") {
            return 1 | (sort.last ? 2 : 0);
        } else if (sort.u === "email") {
            return 4;
        } else {
            return 0;
        }
    }

    function initialize() {
        let x = wstorage.site(true, "pa-pset" + ptconf.id + "-table");
        if (x) {
            try {
                x = JSON.parse(x);
                if (x && typeof x === "object") {
                    sort = ptconf.sort = x;
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
        if (!ptconf.anonymous
            || !ptconf.can_override_anonymous
            || !sort.override_anonymous) {
            delete sort.override_anonymous;
        }
        if (ptconf.anonymous
            && sort.override_anonymous
            && sort.override_anonymous + 3600 > (new Date).getTime() / 1000) {
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
        need_ngrades = ngrades_expected === -2;

        if (ptconf.col) {
            col = ptconf.col;
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
                    gkey: ge.key
                }, ptconf));
            }
            if (need_ngrades) {
                col.push("ngrades");
            }
            if (!ptconf.gitless) {
                col.push("repo");
            }
        }

        colmap = {};
        for (let i = 0; i !== col.length; ++i) {
            let c = col[i];
            if (typeof c === "string") {
                c = col[i] = {type: c, name: c};
            }
            c.index = i;
            Object.assign(c, gcoldef[c.type]);
            if (typeof c.th === "string") {
                c.th = string_function(c.th);
            }
            colmap[c.name] = colmap[c.name] || c;
        }
        name_col = colmap.name;
        ptconf.col = col;
        ptconf.colmap = colmap;

        if ($j[0].closest("form")) {
            slist_input = $('<input name="slist" type="hidden" value="">')[0];
            $j.after(slist_input);
        }
    }


    function make_hotlist(event) {
        var j = [];
        for (var i = 0; i < data.length; ++i) {
            var s = data[i],
                t = "~".concat(encodeURIComponent(ptconf.ukey(s)));
            if (ptconf.flagged_commits) {
                t = t.concat("/pset/", s.pset);
                if (s.commit)
                    t = t.concat("/", s.commit);
            }
            j.push(t);
        }
        event.hotlist = {pset: ptconf.flagged_commits ? null : ptconf.key, items: j};
    }

    function make_rmap($j) {
        var rmap = {}, tr = $j.find("tbody")[0].firstChild, last = null;
        while (tr) {
            if (tr.hasAttribute("data-pa-partner"))
                last.push(tr);
            else
                rmap[tr.getAttribute("data-pa-spos")] = last = [tr];
            tr = tr.nextSibling;
        }
        return rmap;
    }

    function resort_table($j) {
        var $b = $j.children("tbody"),
            ncol = $j.children("thead")[0].firstChild.childNodes.length,
            tb = $b[0],
            rmap = make_rmap($j),
            i, j, trn = 0, was_boringness = false,
            last = tb.firstChild;
        for (i = 0; i !== data.length; ++i) {
            const s = data[i];
            ++trn;
            while ((j = last) && j.className === "gt-boring") {
                last = last.nextSibling;
                tb.removeChild(j);
            }
            if (s.boringness !== was_boringness && was_boringness !== false) {
                tb.insertBefore($('<tr class="gt-boring"><td colspan="' + ncol + '"><hr></td></tr>')[0], last);
            }
            was_boringness = s.boringness;
            const tr = rmap[s._spos];
            for (j = 0; j < tr.length; ++j) {
                if (last !== tr[j]) {
                    tb.insertBefore(tr[j], last);
                } else {
                    last = last.nextSibling;
                }
                removeClass(tr[j], "k" + (1 - trn % 2));
                addClass(tr[j], "k" + (trn % 2));
            }
        }

        render_rownumbers();

        var last_first = sort.last;
        if (last_first !== ptconf.last_first) {
            ptconf.last_first = last_first;
            $b.find(".gt-name, .gt-name2").each(function () {
                const s = smap[this.parentNode.getAttribute("data-pa-spos")];
                ptconf.render_display_name(this, s, hasClass(this, "gt-name2"));
            });
        }
    }

    function render_rownumbers() {
        const c = colmap.rownumber;
        if (!c) {
            return;
        }
        const idx = c.index;
        let trn = 0;
        for (let tr = $j.children("tbody")[0].firstChild; tr; tr = tr.nextSibling) {
            if (hasClass(tr, "gt")) {
                ++trn;
                tr.children.item(idx).textContent = trn + ".";
            }
        }
    }

    function assign_slist() {
        var j = [];
        for (var i = 0; i !== data.length; ++i) {
            j.push(ptconf.ukey(data[i]));
        }
        slist_input.value = j.join(" ");
    }

    function resort() {
        resort_table($j);
        $overlay && resort_table($overlay);
        slist_input && assign_slist();
        wstorage.site(true, "pa-pset" + ptconf.id + "-table", JSON.stringify(sort));
    }

    function rerender_usernames() {
        var $x = $overlay ? $([$j[0], $overlay[0]]) : $j;
        $x.find("td.gt-username").each(function () {
            const s = smap[this.parentNode.getAttribute("data-pa-spos")];
            ptconf.render_username_td(this, s);
        });
        $x.find("th.gt-username > span.heading").html(ptconf.anonymous || !sort.email ? "Username" : "Email");
        $x.find("td.gt-name2").each(function () {
            const s = smap[this.parentNode.getAttribute("data-pa-spos")];
            ptconf.render_display_name(this, s, true);
        });
        $x.find("th.gt-name2 > span.heading").html(ptconf.anonymous ? "Username" : "Name");
    }

    function rerender_users() {
        let $x = $overlay ? $([$j[0], $overlay[0]]) : $j, th;
        $x.find("td.gt-user").each(function () {
            const s = smap[this.parentNode.getAttribute("data-pa-spos")];
            ptconf.render_user_td(this, s);
        });
        if (ptconf.anonymous || sort.u === "user") {
            th = "Username";
        } else if (sort.u === "email") {
            th = "Email";
        } else {
            th = "Name";
        }
        $x.find("th.gt-user > span.heading").html(th);
    }

    function display_anon() {
        $j.toggleClass("gt-anonymous", !!ptconf.anonymous);
        if (table_width && name_col) {
            $j.css("width", (table_width - (ptconf.anonymous ? name_col.width : 0)) + "px");
            $($j[0].firstChild).find(".gt-name").css("width", (ptconf.anonymous ? 0 : name_col.width) + "px");
        }
    }

    function switch_anon(evt) {
        ptconf.anonymous = !ptconf.anonymous;
        if (ptconf.anonymous) {
            delete sort.override_anonymous;
        } else {
            sort.override_anonymous = (new Date).getTime() / 1000;
        }
        display_anon();
        rerender_usernames();
        rerender_users();
        $j.find("tbody input.gt-check").each(function () {
            var s = smap[this.parentNode.parentNode.getAttribute("data-pa-spos")];
            this.setAttribute("name", ptconf.render_checkbox_name(s));
        });
        sort_data();
        resort();
        $j.closest("form").find("input[name=anonymous]").val(ptconf.anonymous ? 1 : 0);
        evt.preventDefault();
        evt.stopPropagation();
    }


    function make_overlay() {
        let tw = 0, cx = [];
        for (let i = 0; i !== col.length; ++i) {
            if (col[i].pin) {
                col[i].pin_index = cx.length;
                cx.push(col[i]);
                tw += col[i].width;
            }
        }

        let t = '<table class="gtable gtable-fixed gtable-overlay new" style="position:absolute;left:0;width:' +
            (tw + 24) + 'px"><thead><tr class="gt k0 kfade">';
        for (let c of cx) {
            t += '<th style="width:' + c.width + 'px"' +
                c.th.call(c, ptconf).substring(3);
        }
        $overlay = $(t + '</thead><tbody></tbody></table>');

        let tr = $j[0].firstChild.firstChild,
            otr = $overlay[0].firstChild.firstChild;
        for (let i = 0; i !== cx.length; ++i) {
            otr.childNodes[i].className = tr.childNodes[cx[i].index].className;
        }

        $j[0].parentNode.prepend($('<div style="position:sticky;left:0;z-index:2"></div>').append($overlay)[0]);
        $overlay.find("thead").on("click", "th", head_click);
        $overlay.find("tbody").on("click", "input[type=checkbox]", checkbox_click);
        $overlay.find(".js-switch-anon").click(switch_anon);

        tr = $j.children("tbody")[0].firstChild;
        let tbodye = $overlay.find("tbody")[0];
        tbodye.replaceChildren();
        while (tr) {
            let tre = document.createElement("tr");
            tre.className = tr.className;
            if (hasClass(tr, "gt-boring")) {
                let tde = document.createElement("td");
                tde.colSpan = cx.length;
                tde.append(document.createElement("hr"));
                tre.append(tde);
            } else {
                let spos = tr.getAttribute("data-pa-spos");
                tre.className += " kfade";
                tre.setAttribute("data-pa-spos", spos);
                if (tr.hasAttribute("data-pa-uid")) {
                    tre.setAttribute("data-pa-uid", tr.getAttribute("data-pa-uid"));
                }
                ptconf.mode = 2;
                if (tr.hasAttribute("data-pa-partner")) {
                    tre.setAttribute("data-pa-partner", 1);
                    ptconf.mode |= 1;
                }
                for (let c of cx) {
                    const tde = document.createElement("td");
                    c.td.call(c, tde, smap[spos], ptconf);
                    tre.append(tde);
                }
            }
            tbodye.appendChild(tre);
            tr = tr.nextSibling;
        }
        $j.find("input[data-range-type=s61]:checked").each(checkbox_click);
        $overlay.on("mouseenter mouseleave", "a.pa-user", user_hover);
        queueMicrotask(function () { removeClass($overlay[0], "new"); });
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
        let f = sort.f;
        set_user_sorters();
        let colr = colmap[f];
        if (colr && colr.compare) {
            data.sort(colr.compare);
        } else if (colr && colr.make_compare) {
            data.sort(colr.make_compare(sort, colr.subtype || null));
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
        } else if (sort.email && !ptconf.anonymous) {
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

        if (sort.rev < 0) {
            data.reverse();
        }
        data.sort(function (a, b) {
            return a.boringness !== b.boringness ? a.boringness - b.boringness : 0;
        });

        var $x = $overlay ? $([$j[0].firstChild, $overlay[0].firstChild]) : $($j[0].firstChild);
        $x.find(".plsortable").removeClass("plsortactive plsortreverse");
        $x.find("th[data-pa-sort='" + f + "']").addClass("plsortactive").
            toggleClass("plsortreverse", sort.rev < 0);
    }

    function head_click() {
        if (!this.hasAttribute("data-pa-sort")) {
            return;
        }
        const sf = this.getAttribute("data-pa-sort");
        if (sf !== sort.f) {
            sort.f = sf;
            const col = colmap[sf];
            sort.rev = col && col.sort_forward ? 1 : -1;
            if (sf === "name") {
                sort.u = "name";
            } else if (sf === "name2") {
                sort.u = ptconf.anonymous ? "user" : "name";
            } else if (sf === "email") {
                sort.u = "email";
            } else if (sf === "username") {
                sort.u = ptconf.anonymous || !ptconf.email ? "user" : "email";
            }
        } else {
            sort.rev = -sort.rev;
            if (sort.rev === 1) {
                if (sf === "name" || (sf === "name2" && !ptconf.anonymous)) {
                    ptconf.last_first = sort.last = !sort.last;
                } else if (sf === "username" && !ptconf.anonymous) {
                    sort.email = !sort.email;
                    sort.u = ptconf.anonymous || !ptconf.email ? "user" : "email";
                    rerender_usernames();
                } else if (sf === "user" && (!ptconf.anonymous || ptconf.has_nonanonymous)) {
                    if (sort.u === "name") {
                        ptconf.last_first = sort.last = !sort.last;
                        if (!sort.last) {
                            sort.u = "email";
                        }
                    } else if (sort.u === "email") {
                        sort.u = "user";
                    } else {
                        sort.u = "name";
                    }
                    rerender_users();
                }
            }
        }
        sort_data();
        resort();
    }

    function checkbox_click() {
        const range = this.getAttribute("data-range-type");
        if ((range === "s61" && $overlay) || range === "s61o") {
            const wanto = range === "s61",
                tr0 = this.closest("tr"),
                tr1 = (wanto ? $overlay[0] : $j[0]).rows[tr0.rowIndex],
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
            if (table_hit && !left_hit && !$overlay) {
                make_overlay();
            } else if (table_hit && left_hit && $overlay) {
                $overlay.parent().remove();
                $overlay = null;
            }
        }
        var observer = new IntersectionObserver(observer_fn);
        observer.observe(overlay_div);
        observer.observe($j.parent()[0]);
    }

    function render_tds(tre, s, mode) {
        ptconf.mode = mode;
        for (let i = 0; i !== col.length; ++i) {
            const tde = document.createElement("td");
            col[i].td.call(col[i], tde, s, ptconf);
            tre.appendChild(tde);
        }
    }

    function render() {
        const tfixed = $j.hasClass("want-gtable-fixed"),
            rem = parseFloat(window.getComputedStyle(document.documentElement).fontSize);
        let a = ['<tr class="gt k0">'];
        for (const c of col) {
            a.push(c.th.call(c, ptconf));
            if (tfixed) {
                let w = c.tw;
                if (typeof w !== "number") {
                    w = w.call(c, ptconf);
                }
                w *= rem;
                c.left = table_width;
                c.width = w;
                table_width += w;
            }
        }
        const thead = document.createElement("thead"),
            tpl = document.createElement("template");
        tpl.innerHTML = a.join("");
        thead.appendChild(tpl.content);

        let tw = 0;
        if (tfixed) {
            let td = thead.firstChild.firstChild;
            for (const c of col) {
                tw += c.width;
                td.style.width = c.width + "px";
                td = td.nextSibling;
            }
        }

        display_anon();
        $j[0].appendChild(thead);
        $j.toggleClass("gt-useemail", !!sort.email);
        $j.find("thead").on("click", "th", head_click);
        $j.find(".js-switch-anon").click(switch_anon);
        if (tfixed) {
            $j[0].style.width = tw + "px";
            $j.removeClass("want-gtable-fixed").css("table-layout", "fixed");
        }

        const tbody = $('<tbody class="has-hotlist"></tbody>')[0];
        $j[0].appendChild(tbody);
        if (!ptconf.no_sort) {
            sort_data();
        }
        ptconf.last_first = sort.last;

        let trn = 0, was_boringness = 0;
        for (let i = 0; i !== data.length; ++i) {
            const s = data[i];
            ptconf.push(s);
            ++trn;
            if (s.boringness !== was_boringness && trn != 1) {
                const tre = document.createElement("tr");
                tre.className = "gt-boring";
                const tde = document.createElement("td");
                tde.colSpan = col.length;
                tde.append(document.createElement("hr"));
                tre.append(tde);
                tbody.append(tre);
            }
            was_boringness = s.boringness;
            const tre = document.createElement("tr");
            tre.className = "gt k".concat(trn % 2);
            tre.setAttribute("data-pa-spos", s._spos);
            if (s.uid) {
                tre.setAttribute("data-pa-uid", s.uid);
            }
            render_tds(tre, s, 0);
            tbody.appendChild(tre);
            for (let j = 0; s.partners && j < s.partners.length; ++j) {
                const ss = s.partners[j];
                ptconf.push(ss);
                const trep = document.createElement("tr");
                trep.className = "gt k".concat(trn % 2, " gtrow-partner");
                trep.setAttribute("data-pa-spos", ss._spos);
                if (ss.uid) {
                    trep.setAttribute("data-pa-uid", ss.uid);
                }
                trep.setAttribute("data-pa-partner", 1);
                render_tds(trep, s.partners[j], 1);
                tbody.appendChild(trep);
            }
        }
        render_rownumbers();
        slist_input && assign_slist();
        $j.find("tbody").on("click", "input[type=checkbox]", checkbox_click);

        if (tfixed && window.IntersectionObserver) {
            make_overlay_observer();
        }
    }

    initialize();
    render();

    $j.children("tbody").on("pa-hotlist", make_hotlist);
    $j.closest("form")[0].addEventListener("rangechange", function (evt) {
        if (evt.detail.rangeType === "s61")
            $(this).find(".js-gdialog").prop("disabled", !evt.detail.newState);
    });
    $j.on("mouseenter mouseleave", "a.pa-user", user_hover);
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
        hc.push('<div class="d-grid-1">', '</div>');

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
