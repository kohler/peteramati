// ptable.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, removeClass, toggleClass, input_differs,
         HtmlCollector } from "./ui.js";
import { wstorage, sprintf, strftime } from "./utils.js";
import { hoturl, hoturl_post_go } from "./hoturl.js";
import { api_conditioner } from "./xhr.js";
import { escape_entities } from "./encoders.js";
import { GradeSheet } from "./gradeentry.js";
import { render_xmsg } from "./render.js";
import { popup_skeleton } from "./popup.js";


function render_name(s, last_first) {
    if (s.first != null && s.last != null) {
        if (last_first)
            return s.last.concat(", ", s.first);
        else
            return s.first.concat(" ", s.last);
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


class PtableConf {
    constructor(pconf) {
        this.title = pconf.title;
        this.anonymous = this.original_anonymous = pconf.anonymous;
        this.can_override_anonymous = pconf.can_override_anonymous;
        this.key = pconf.key;
        this.flagged_commits = pconf.flagged_commits;
        this.last_first = null;
        this.mode = 0;
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
        ae.href = this.href(s);
        ae.className = "track" + (s.dropped ? " gt-dropped" : "");
        return ae;
    }

    render_username_td(tde, s) {
        const ae = this.make_student_ae(s);
        if (this.anonymous && s.anon_user) {
            ae.append(s.anon_user);
        } else if (this.sort.email && s.email) {
            ae.append(s.email);
        } else if (s.user) {
            ae.append(s.user);
        }
        tde.replaceChildren(ae);
    }

    render_display_name(tde, s, is2) {
        const t = is2 && this.anonymous
            ? s.anon_user || "?"
            : render_name(s, this.last_first);
        if (is2) {
            const ae = this.make_student_ae(s);
            ae.textContent = t;
            tde.replaceChildren(ae);
        } else {
            tde.replaceChildren(t);
        }
    }

    render_name_text(s) {
        if (s) {
            return (this.anonymous ? s.anon_user : render_name(s, this.last_first)) || "?";
        } else {
            return "[none]";
        }
    }

    render_checkbox_name(s) {
        var u = this.anonymous ? s.anon_user || s.user : s.user;
        return "s:" + encodeURIComponent(u).replace(/\./g, "%2E");
    }
}


const gcoldef = {
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

    extension: {
        th: '<th class="gt-extension l plsortable" data-pa-sort="extension" scope="col">X?</th>',
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
        th: '<th class="gt-year c plsortable" data-pa-sort="year" scole="col">Yr</th>',
        td: function (tde, s) {
            var t = '';
            if (s.year) {
                if (typeof s.year === "number") {
                    if (s.year >= 1 && s.year <= 20) {
                        t = String.fromCharCode(9311 + s.year);
                    } else {
                        t = s.year;
                    }
                } else if (s.year.length === 1 && s.year >= "A" && s.year <= "Z") {
                    t = String.fromCharCode(9333 + s.year.charCodeAt(0));
                } else {
                    t = escape_entities(s.year);
                }
            }
            tde.className = "gt-year c";
            tde.replaceChildren(t);
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
        th: '<th class="gt-notes c plsortable" data-pa-sort="gradestatus" scope="col">⎚</th>',
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


export function pa_render_pset_table(pconf, data) {
    var $j = $(this), table_width = 0, smap = [],
        $overlay = null, name_col, slist_input,
        $gdialog, gdialog_su,
        flagged = pconf.flagged_commits,
        visible = false,
        gradesheet = null,
        table_entries, need_ngrades,
        sort = {
            f: flagged ? "at" : "name", last: false, rev: 1
        },
        active_nameflag = -1, displaying_last_first = null,
        anonymous = pconf.anonymous,
        col, colmap;
    const ptconf = new PtableConf(pconf);

    var col_renderers = {
        rownumber: {
            th: '<th class="gt-rownumber" scope="col"></th>',
            td: function (tde) {
                tde.className = "gt-rownumber";
            },
            tw: Math.ceil(Math.log10(Math.max(data.length, 1))) * 0.75
        },
        username: {
            th: function () {
                var t = '<span class="heading">' + (anonymous || !sort.email ? "Username" : "Email") + '</span>';
                if (ptconf.anonymous && ptconf.can_override_anonymous)
                    t += ' <button type="button" class="btn-ulink n js-switch-anon">[anon]</button>';
                else if (ptconf.original_anonymous)
                    t += ' <span class="n">[anon]</span>';
                return '<th class="gt-username l plsortable" data-pa-sort="username" scope="col">' + t + '</th>';
            },
            td: function (tde, s) {
                tde.className = "gt-username";
                render_username_td(tde, s);
            },
            tw: 12,
            pin: true,
            sort_forward: true
        },
        name: {
            th: function () {
                return '<th class="gt-name l plsortable" data-pa-sort="name" scope="col">Name</th>';
            },
            td: function (tde, s) {
                tde.className = "gt-name";
                render_display_name(tde, s, false);
            },
            tw: 14,
            sort_forward: true
        },
        name2: {
            th: function () {
                var t = '<span class="heading">' + (anonymous ? "Username" : "Name") + '</span>';
                if (ptconf.anonymous && ptconf.can_override_anonymous)
                    t += ' <button type="button" class="btn-ulink n js-switch-anon">[anon]</button>';
                return '<th class="gt-name2 l plsortable" data-pa-sort="name2" scope="col">' + t + '</th>';
            },
            td: function (tde, s) {
                tde.className = "gt-name2";
                render_display_name(tde, s, true);
            },
            tw: 14,
            sort_forward: true
        },

        gdialog: {
            th: '<th class="gt-gdialog"></th>',
            td: function (tde) {
                tde.className = "gt-gdialog";
                const be = document.createElement("button");
                be.type = "button";
                be.className = "btn-xlink ui js-gdialog";
                be.tabIndex = -1;
                be.scope = "col";
                be.append("Ⓖ");
                tde.append(be);
            },
            tw: 1.5,
            pin: true
        }
    };

    function string_function(s) {
        return function () { return s; };
    }
    function set_sort_nameflag() {
        if (sort.f === "name" || sort.f === "name2" || sort.f === "username"
            || sort.f === "email" || sort.nameflag == null) {
            sort.nameflag = 0;
            if (sort.f === "name" || sort.f === "name2") {
                sort.nameflag |= 1;
            }
            if (sort.last) {
                sort.nameflag |= 2;
            }
            if (sort.email) {
                sort.nameflag |= 4;
            }
            if (anonymous) {
                sort.nameflag |= 8;
            }
        }
    }
    function initialize() {
        var x = wstorage.site(true, "pa-pset" + ptconf.id + "-table");
        x && (sort = JSON.parse(x));
        if (!sort.f || !/^\w+$/.test(sort.f)) {
            sort.f = "name";
        }
        if (sort.rev !== 1 && sort.rev !== -1) {
            sort.rev = 1;
        }
        if (!anonymous
            || !ptconf.can_override_anonymous
            || !sort.override_anonymous) {
            delete sort.override_anonymous;
        }
        if (anonymous && sort.override_anonymous) {
            anonymous = false;
        }
        if (sort.nameflag == null) {
            set_sort_nameflag();
        }

        table_entries = [];
        if (pconf.grades) {
            gradesheet = new GradeSheet(pconf.grades);
            for (let i = 0; i !== gradesheet.value_order.length; ++i) {
                const k = gradesheet.value_order[i],
                    ge = gradesheet.entries[k];
                if (ge.type_tabular) {
                    table_entries.push(ge);
                }
            }
        } else {
            gradesheet = new GradeSheet;
        }
        gradesheet.scores_visible = visible = pconf.scores_visible;

        let ngrades_expected = -1, has_late_hours = false, any_visible = visible;
        for (let i = 0; i !== data.length; ++i) {
            const s = data[i] = gradesheet.make_child().assign(data[i]);
            if (s.dropped) {
                s.boringness = 2;
            } else if (s.emptydiff
                       || (!s.grade_commit && !s.commit && !pconf.gitless_grades)) {
                s.boringness = 1;
            } else {
                s.boringness = 0;
            }
            let ngrades = 0;
            if (s.grades) {
                for (var j = 0; j !== table_entries.length; ++j) {
                    if (table_entries[j].key != pconf.total_key
                        && s.grades[j] != null
                        && s.grades[j] !== "")
                        ++ngrades;
                }
            }
            s.ngrades = ngrades;
            if (ngrades_expected === -1) {
                ngrades_expected = ngrades;
            } else if (ngrades_expected !== ngrades && (!s.boringness || ngrades > 0)) {
                ngrades_expected = -2;
            }
            has_late_hours = has_late_hours || !!s.late_hours;
            any_visible = any_visible || s.scores_visible_pinned;
        }
        need_ngrades = ngrades_expected === -2;

        if (pconf.col) {
            col = pconf.col;
        } else {
            col = ["rownumber"];
            if (pconf.checkbox) {
                col.push(ptconf.flagged_commits ? "flagcheckbox" : "checkbox");
            }
            if (flagged) {
                col.push("pset");
                col.push("at");
            }
            col.push("username", "name", "extension", "year", "grader");
            if (has_late_hours) {
                col.push("latehours");
            }
            if (flagged) {
                col.push("conversation");
            }
            if (flagged || !pconf.gitless_grades || any_visible) {
                col.push("notes");
            }
            if (pconf.need_total) {
                col.push("total");
            }
            for (let i = 0; i !== table_entries.length; ++i) {
                const ge = table_entries[i];
                col.push(ge.configure_column({
                    type: "grade",
                    name: "/g/" + ge.key,
                    gidx: i,
                    gkey: ge.key
                }, pconf));
            }
            if (need_ngrades) {
                col.push("ngrades");
            }
            if (!pconf.gitless) {
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
            Object.assign(c, gcoldef[c.type] || col_renderers[c.type]);
            if (typeof c.th === "string") {
                c.th = string_function(c.th);
            }
            colmap[c.name] = colmap[c.name] || c;
        }
        name_col = colmap.name;

        if ($j[0].closest("form")) {
            slist_input = $('<input name="slist" type="hidden" value="">')[0];
            $j.after(slist_input);
        }
    }

    function render_username_td(tde, s) {
        let ae = ptconf.make_student_ae(s);
        if (anonymous && s.anon_user) {
            ae.append(s.anon_user);
        } else if (sort.email && s.email) {
            ae.append(s.email);
        } else if (s.user) {
            ae.append(s.user);
        }
        tde.replaceChildren(ae);
    }
    function render_name(s, last_first) {
        if (s.first != null && s.last != null) {
            if (last_first)
                return s.last.concat(", ", s.first);
            else
                return s.first.concat(" ", s.last);
        } else if (s.first != null) {
            return s.first;
        } else if (s.last != null) {
            return s.last;
        } else {
            return "";
        }
    }
    function render_display_name(tde, s, is2) {
        let t = is2 && anonymous ? s.anon_user || "?" : render_name(s, displaying_last_first);
        if (is2) {
            let ae = ptconf.make_student_ae(s);
            ae.textContent = t;
            tde.replaceChildren(ae);
        } else {
            tde.replaceChildren(t);
        }
    }
    function render_name_text(s) {
        if (s) {
            return (anonymous ? s.anon_user : render_name(s, displaying_last_first)) || "?";
        } else {
            return "[none]";
        }
    }
    function render_checkbox_name(s) {
        var u = anonymous ? s.anon_user || s.user : s.user;
        return "s:" + encodeURIComponent(u).replace(/\./g, "%2E");
    }


    function make_hotlist(event) {
        var j = [];
        for (var i = 0; i < data.length; ++i) {
            var s = data[i],
                t = "~".concat(encodeURIComponent(ptconf.ukey(s)));
            if (flagged) {
                t = t.concat("/pset/", s.pset);
                if (s.commit)
                    t = t.concat("/", s.commit);
            }
            j.push(t);
        }
        event.hotlist = {pset: flagged ? null : ptconf.key, items: j};
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
            var s = data[i];
            while ((j = last) && j.className === "gt-boring") {
                last = last.nextSibling;
                tb.removeChild(j);
            }
            if (s.boringness !== was_boringness && was_boringness !== false) {
                tb.insertBefore($('<tr class="gt-boring"><td colspan="' + ncol + '"><hr></td></tr>')[0], last);
            }
            was_boringness = s.boringness;
            var tr = rmap[s._spos];
            for (j = 0; j < tr.length; ++j) {
                if (last !== tr[j])
                    tb.insertBefore(tr[j], last);
                else
                    last = last.nextSibling;
                removeClass(tr[j], "k" + (1 - trn % 2));
                addClass(tr[j], "k" + (trn % 2));
            }
            ++trn;
        }

        render_rownumbers();

        var display_last_first = sort.f && sort.last;
        if (display_last_first !== displaying_last_first) {
            displaying_last_first = display_last_first;
            $b.find(".gt-name, .gt-name2").each(function () {
                var s = smap[this.parentNode.getAttribute("data-pa-spos")];
                render_display_name(this, s, hasClass(this, "gt-name2"));
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
        wstorage.site(true, "pa-pset" + pconf.id + "-table", JSON.stringify(sort));
    }
    function make_uid2tr() {
        var uid2tr = {}, tr = $j.find("tbody")[0].firstChild;
        while (tr) {
            uid2tr[tr.getAttribute("data-pa-uid")] = tr;
            tr = tr.nextSibling;
        }
        return uid2tr;
    }
    function rerender_usernames() {
        var $x = $overlay ? $([$j[0], $overlay[0]]) : $j;
        $x.find("td.gt-username").each(function () {
            var s = smap[this.parentNode.getAttribute("data-pa-spos")];
            render_username_td(this, s);
        });
        $x.find("th.gt-username > span.heading").html(anonymous || !sort.email ? "Username" : "Email");
        $x.find("td.gt-name2").each(function () {
            var s = smap[this.parentNode.getAttribute("data-pa-spos")];
            render_display_name(this, s, true);
        });
        $x.find("th.gt-name2 > span.heading").html(anonymous ? "Username" : "Name");
    }
    function display_anon() {
        $j.toggleClass("gt-anonymous", !!anonymous);
        if (table_width && name_col) {
            $j.css("width", (table_width - (anonymous ? name_col.width : 0)) + "px");
            $($j[0].firstChild).find(".gt-name").css("width", (anonymous ? 0 : name_col.width) + "px");
        }
    }
    function switch_anon(evt) {
        anonymous = !anonymous;
        if (!anonymous)
            sort.override_anonymous = true;
        display_anon();
        rerender_usernames();
        $j.find("tbody input.gt-check").each(function () {
            var s = smap[this.parentNode.parentNode.getAttribute("data-pa-spos")];
            this.setAttribute("name", render_checkbox_name(s));
        });
        sort_data();
        resort();
        $j.closest("form").find("input[name=anonymous]").val(anonymous ? 1 : 0);
        evt.preventDefault();
        evt.stopPropagation();
    }
    function make_overlay() {
        let tw = 0, cx = [];
        for (let i = 0; i !== col.length; ++i) {
            if (col[i].pin) {
                cx.push(col[i]);
                tw += col[i].width;
            }
        }

        let t = '<table class="gtable gtable-fixed gtable-overlay new" style="position:absolute;left:0;width:' +
            (tw + 24) + 'px"><thead><tr class="gt k0 kfade">';
        for (let c of cx) {
            t += '<th style="width:' + c.width + 'px"' +
                c.th.call(c).substring(3);
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
        if ($overlay) {
            queueMicrotask(function () { removeClass($overlay[0], "new"); });
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
            t += sprintf(" %5d", u.pset);
        }
        if (u.at != null) {
            t += sprintf(" %11g", u.at);
        }
        return t.toLowerCase();
    }
    function set_user_sorters() {
        if (sort.nameflag !== active_nameflag) {
            active_nameflag = sort.nameflag;
            for (var i = 0; i < data.length; ++i) {
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
        } else if ((f === "name" || f === "name2") && !anonymous) {
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
        } else if (sort.email && !anonymous) {
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
        if (!this.hasAttribute("data-pa-sort"))
            return;
        const sf = this.getAttribute("data-pa-sort");
        if (sf !== sort.f) {
            sort.f = sf;
            const col = colmap[sf];
            sort.rev = col && col.sort_forward ? 1 : -1;
        } else if (sf === "name" || (sf === "name2" && !anonymous)) {
            sort.rev = -sort.rev;
            if (sort.rev === 1) {
                sort.last = !sort.last;
            }
        } else if (sf === "username") {
            if (sort.rev === -1 && !anonymous) {
                sort.email = !sort.email;
                rerender_usernames();
            }
            sort.rev = -sort.rev;
        } else {
            sort.rev = -sort.rev;
        }
        set_sort_nameflag();
        sort_data();
        resort();
    }
    function checkbox_click() {
        const range = this.getAttribute("data-range-type");
        if ((range === "s61" && $overlay) || range === "s61o") {
            const wanto = range === "s61",
                b0 = this.closest("tbody"),
                b1 = (wanto ? $overlay : $j)[0].tBodies[0];
            let r0 = b0.firstChild, r1 = b1.firstChild, rd = this.closest("tr");
            while (r0 !== rd) {
                r0 = r0.nextSibling;
                r1 = r1.nextSibling;
            }
            r1.querySelector("[data-range-type=" + (wanto ? "s61o]" : "s61]")).checked = this.checked;
        }
    }

    function grade_update(uid2tr, rv) {
        const tr = uid2tr[rv.uid],
            su = smap[tr.getAttribute("data-pa-spos")];
        su.assign(rv);
        let ngrades = 0;
        for (let gv of su.grades || []) {
            if (gv != null && gv !== "")
                ++ngrades;
        }
        su.ngrades = ngrades;
        for (let c of col) {
            if (c.refreshable)
                c.td.call(c, tr.childNodes[c.index], su);
        }
    }

    function gdialog_store_start(rv) {
        $gdialog.find(".has-error").removeClass("has-error");
        if (rv.ok) {
            $gdialog.find(".pa-messages").html("");
        } else {
            $gdialog.find(".pa-messages").html(render_xmsg(2, escape_entities(rv.error)));
            if (rv.errf) {
                $gdialog.find(".pa-gradevalue").each(function () {
                    if (rv.errf[this.name])
                        addClass(this, "has-error");
                });
            }
        }
    }
    function gdialog_gradesheet_submit() {
        const $gsi = $gdialog.find(".pa-gdialog-gradesheet input"),
            ge = [], us = [];
        for (let i = 0; i !== $gsi.length; ++i) {
            if ($gsi[i].checked)
                ge.push(gradesheet.entries[$gsi[i].name]);
        }
        for (let su of gdialog_su) {
            us.push(su.uid);
        }
        if (ge.length === 0) {
            alert("No grades selected.");
        } else {
            const opt = {pset: ptconf.key, anonymous: anonymous ? 1 : "", users: us.join(" ")};
            if (ge.length === 1 && ge[0].landmark_range_file) {
                opt.file = ge[0].landmark_range_file;
                opt.lines = ge[0].landmark_range_first + "-" + ge[0].landmark_range_last;
            }
            opt.grade = ge[0].key;
            for (let i = 1; i !== ge.length; ++i) {
                opt.grade += " " + ge[i].key;
            }
            hoturl_post_go("=diffmany", opt);
        }
    }
    function gdialog_store(next) {
        const gradesheet_mode = $gdialog.find("button[name=mode-gradesheet]").hasClass("btn-primary");
        let any = false, byuid = {};
        if (gradesheet_mode) {
            if (!next) {
                gdialog_gradesheet_submit();
                return;
            }
        } else {
            $gdialog.find(".pa-gradevalue").each(function () {
                if ((this.hasAttribute("data-pa-unmixed") || input_differs(this))
                    && !this.indeterminate) {
                    let k = this.name, ge = gradesheet.entries[k], v;
                    if (this.type === "checkbox") {
                        v = this.checked ? this.value : "";
                    } else {
                        v = $(this).val();
                    }
                    for (let su of gdialog_su) {
                        byuid[su.uid] = byuid[su.uid] || {grades: {}, oldgrades: {}};
                        byuid[su.uid].grades[k] = v;
                        byuid[su.uid].oldgrades[k] = ge.value_in(su);
                    }
                    any = true;
                }
            });
        }
        next = next || function () { $gdialog.close(); };
        if (!any) {
            next();
        } else if (gdialog_su.length === 1) {
            api_conditioner(hoturl("=api/grade", ptconf.url_gradeparts(gdialog_su[0])),
                byuid[gdialog_su[0].uid])
            .then(function (rv) {
                gdialog_store_start(rv);
                if (rv.ok) {
                    grade_update(make_uid2tr(), rv);
                    next();
                }
            });
        } else {
            for (let su of gdialog_su) {
                if (su.grade_commit) {
                    byuid[su.uid].commit = su.grade_commit;
                    byuid[su.uid].commit_is_grade = 1;
                } else if (su.commit) {
                    byuid[su.uid].commit = su.commit;
                }
            }
            api_conditioner(hoturl("=api/multigrade", {pset: ptconf.key}),
                {us: JSON.stringify(byuid)})
            .then(function (rv) {
                gdialog_store_start(rv);
                if (rv.ok) {
                    const uid2tr = make_uid2tr();
                    for (let rvu of rv.us) {
                        grade_update(uid2tr, rvu);
                    }
                    next();
                }
            });
        }
    }
    function gdialog_traverse() {
        const next_spos = this.getAttribute("data-pa-spos");
        gdialog_store(function () { gdialog_fill([next_spos]); });
    }
    function gdialog_fill_user(su1) {
        let t;
        if (su1.first || su1.last) {
            t = su1.first.concat(" ", su1.last, " <", su1.email, ">");
        } else {
            t = "<".concat(su1.email, ">");
        }
        $gdialog.find(".gt-name-email").html(escape_entities(t)).removeClass("hidden");
        let tr = $j.find("tbody")[0].firstChild, tr1;
        while (tr && tr.getAttribute("data-pa-spos") != su1._spos) {
            tr = tr.nextSibling;
        }
        for (tr1 = tr; tr1 && (tr1 === tr || !tr1.hasAttribute("data-pa-spos")); ) {
            tr1 = tr1.previousSibling;
        }
        $gdialog.find("button[name=prev]").attr("data-pa-spos", tr1 ? tr1.getAttribute("data-pa-spos") : "").prop("disabled", !tr1);
        for (tr1 = tr; tr1 && (tr1 === tr || !tr1.hasAttribute("data-pa-spos")); ) {
            tr1 = tr1.nextSibling;
        }
        $gdialog.find("button[name=next]").attr("data-pa-spos", tr1 ? tr1.getAttribute("data-pa-spos") : "").prop("disabled", !tr1);
    }
    function gdialog_fill(spos) {
        gdialog_su = [];
        for (let i = 0; i !== spos.length; ++i) {
            gdialog_su.push(smap[spos[i]]);
        }
        $gdialog.find("h2").html(escape_entities(ptconf.title) + " : " +
            gdialog_su.map(function (su) {
                return escape_entities(anonymous ? su.anon_user : su.user || su.email);
            }).join(", "));
        if (gdialog_su.length === 1) {
            gdialog_fill_user(gdialog_su[0]);
        } else {
            $gdialog.find(".gt-name-email").addClass("hidden");
        }

        $gdialog.find(".pa-grade").each(function () {
            let k = this.getAttribute("data-pa-grade"),
                ge = gradesheet.entries[k],
                gidx = ge.value_order_in(gradesheet),
                sv = gdialog_su[0].grades[gidx],
                opts = {reset: true, mixed: false};
            for (let i = 1; i !== gdialog_su.length; ++i) {
                let suv = gdialog_su[i].grades[gidx];
                if (suv !== sv
                    && !(suv == null && sv === "")
                    && !(suv === "" && sv == null)) {
                    sv = null;
                    opts.mixed = true;
                    break;
                }
            }
            ge.update_at(this, sv, opts);
        });
    }
    function gdialog_key(event) {
        let $b;
        if (event.ctrlKey
            && (event.key === "n" || event.key === "p")
            && ($b = $gdialog.find("button[name=" + (event.key === "n" ? "next" : "prev") + "]"))
            && !$b[0].disabled) {
            gdialog_traverse.call($b[0]);
            event.stopImmediatePropagation();
            event.preventDefault();
        } else if (event.key === "Return" || event.key === "Enter") {
            gdialog_store(null);
            event.stopImmediatePropagation();
            event.preventDefault();
        } else if (event.key === "Esc" || event.key === "Escape") {
            event.stopImmediatePropagation();
            $gdialog.close();
        } else if (event.key === "Backspace"
                   && this.hasAttribute("placeholder")
                   && this.closest(".pa-gradelist")) {
            gdialog_gradelist_input.call(this);
        }
    }
    function gdialog_gradelist_input() {
        removeClass(this, "has-error");
        if (this.hasAttribute("placeholder")) {
            this.setAttribute("data-pa-unmixed", 1);
            this.removeAttribute("placeholder");
            gdialog_gradelist_change.call(this);
        }
    }
    function gdialog_gradelist_change() {
        toggleClass(this.closest(".pa-pv"), "pa-grade-changed",
                    this.hasAttribute("data-pa-unmixed") || input_differs(this));
    }
    function gdialog_section_click(event) {
        if (event.type === "click" && !event.shiftKey) {
            const checked = this.checked;
            let l = this.closest("label");
            while ((l = l.nextSibling)) {
                const ch = l.firstChild.firstChild;
                if (ch.classList.contains("pa-gdialog-section"))
                    break;
                ch.checked = checked;
            }
        }
    }
    function gdialog_mode_values() {
        const gl = $gdialog.find(".pa-gradelist")[0];
        if (!gl.firstChild) {
            const gi = GradeSheet.closest(gl);
            for (let i = 0; i !== table_entries.length; ++i) {
                gl.appendChild(table_entries[i].render(gi, 1));
            }
        }
        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        gl.classList.remove("hidden");
        $gdialog.find("button[name=bsubmit]").text("Save").removeClass("hidden");
    }
    function gdialog_mode_gradesheet() {
        const gs = $gdialog.find(".pa-gdialog-gradesheet")[0];
        if (!gs.firstChild) {
            const yc = new HtmlCollector;
            let in_section = false;
            for (let i = 0; i !== gradesheet.order.length; ++i) {
                const ge = gradesheet.entries[gradesheet.order[i]],
                    gcl = in_section && ge.type !== "section" ? "checki ml-4" : "checki",
                    ccl = ge.type === "section" ? " pa-gdialog-section" : "";
                yc.push('<label class="'.concat(gcl, '"><span class="checkc"><input type="checkbox" name="', ge.key, '" class="uic js-range-click', ccl, '" data-range-type="mge"></span>', ge.title_html, '</label>'));
                in_section = in_section || ge.type === "section";
            }
            gs.innerHTML = yc.render();
        }
        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        gs.classList.remove("hidden");
        $gdialog.find("button[name=bsubmit]").text("Edit gradesheet").removeClass("hidden");
    }
    function gdialog_settings_submit() {
        const gs = $gdialog.find(".pa-gdialog-settings")[0],
            f = gs.closest("form"),
            us = {};
        for (let su of gdialog_su) {
            us[su.uid] = {uid: su.uid};
        }
        if (this.name === "save-gvis") {
            let gvisarg;
            $(gs).find(".pa-gvis").each(function () {
                if (this.checked && !this.indeterminate)
                    gvisarg = JSON.parse(this.value);
            });
            for (let su of gdialog_su) {
                us[su.uid].scores_visible = gvisarg;
            }
        } else if (this.name === "save-grader"
                   && f.elements.gradertype.value === "clear") {
            for (let su of gdialog_su) {
                us[su.uid].gradercid = 0;
            }
        } else if (this.name === "save-grader"
                   && f.elements.gradertype.value === "previous") {
            for (let su of gdialog_su) {
                us[su.uid].gradercid = "previous";
            }
        } else if (this.name === "save-grader") {
            let gr = [], gri = [], grn = 0;
            $(gs).find(".pa-grader").each(function () {
                if (this.checked && !this.indeterminate) {
                    gr.push(+this.name.substring(6));
                    gri.push(1);
                    ++grn;
                }
            });
            if (grn) {
                for (let su of gdialog_su) {
                    let trigger = Math.floor(Math.random() * grn), gi = 0;
                    while (trigger >= gri[gi]) {
                        trigger -= Math.max(gri[gi], 0);
                        ++gi;
                    }
                    us[su.uid].gradercid = gr[gi];
                    grn -= Math.min(gri[gi], 1);
                    --gri[gi];
                    if (grn <= 0) {
                        grn = 0;
                        for (gi in gri) {
                            ++gri[gi];
                            grn += Math.max(gri[gi], 0);
                        }
                    }
                }
            }
        }
        this.disabled = true;
        const usc = [];
        for (let su of gdialog_su) {
            usc.push(us[su.uid]);
        }
        const progress = document.createElement("progress");
        progress.max = usc.length;
        progress.className = "float-right";
        this.after(progress);
        let usci = 0;
        function more() {
            const byuid = usc.slice(usci, usci + 16);
            usci += byuid.length;
            api_conditioner(hoturl("=api/gradesettings", {pset: ptconf.key}),
                {us: JSON.stringify(byuid)},
                {timeout: 20000})
            .then(function (rv) {
                gdialog_store_start(rv);
                progress.value = usci;
                if (rv.ok && rv.us) {
                    const uid2tr = make_uid2tr();
                    for (let rvu of rv.us) {
                        grade_update(uid2tr, rvu);
                    }
                }
                if (rv.ok && usci < usc.length) {
                    more();
                } else {
                    $gdialog.close();
                }
            })
        }
        more();
    }
    function gdialog_settings_gvis_click() {
        const gs = $gdialog.find(".pa-gdialog-settings")[0];
        $(gs).find(".pa-gvis").prop("checked", false).prop("indeterminate", false);
        this.checked = true;
    }
    function gdialog_settings_grader_click() {
        const gs = $gdialog.find(".pa-gdialog-settings")[0], self = this;
        $(gs).find(".pa-grader:indeterminate").each(function () {
            if (self !== this && this.indeterminate) {
                this.checked = false;
            }
            this.indeterminate = false;
        });
        if (this.checked) {
            gs.closest("form").elements.gradertype.value = "set";
        } else {
            setTimeout(function () {
                if ($(gs).find(".pa-grader:checked").length === 0)
                    gs.closest("form").elements.gradertype.value = "clear";
            }, 0);
        }
    }
    function gdialog_mode_settings() {
        const gs = $gdialog.find(".pa-gdialog-settings")[0], f = gs.closest("form");
        if (!gs.firstChild) {
            const yc = new HtmlCollector;
            yc.push('<fieldset class="mb-3"><legend>Grade visibility</legend>', '</fieldset>');
            yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="gvis" value="true" class="uic pa-gvis"></span>Visible</label>');
            yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="gvis" value="false" class="uic pa-gvis"></span>Hidden</label>');
            yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="gvis" value="null" class="uic pa-gvis"></span>Default (' + (visible ? 'visible' : 'hidden') + ')</label>');
            yc.push('<div class="popup-actions"><button type="button" class="btn btn-primary" name="save-gvis">Save</button></div>');
            yc.pop();

            yc.push('<fieldset><legend>Grader</legend>', '</fieldset>');
            yc.push('<label class="checki"><span class="checkc"><input type="radio" name="gradertype" value="clear" class="uic"></span>Clear</label>');
            //yc.push('<label class="checki"><span class="checkc"><input type="radio" name="gradertype" value="previous" class="uic"></span>Adopt from previous problem set</label>');
            yc.push('<label class="checki"><span class="checkc"><input type="radio" name="gradertype" value="set" class="uic"></span>Set grader</label>');
            yc.push('<div class="checki mt-1 multicol-3">', '</div>');
            for (let i = 0; i !== siteinfo.pc.__order__.length; ++i) {
                const cid = siteinfo.pc.__order__[i], pc = siteinfo.pc[cid];
                yc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="grader'.concat(cid, '" class="uic js-range-click pa-grader" data-range-type="grader"></span>', escape_entities(pc.name), '<span class="ct small dim"></span></label>'));
            }
            yc.pop();
            yc.push('<div class="popup-actions"><button type="button" class="btn btn-primary" name="save-grader">Save</button></div>');
            gs.innerHTML = yc.render();
            $(gs).on("click", "button", gdialog_settings_submit);
            $(gs).on("click", ".pa-grader", gdialog_settings_grader_click);
            $(gs).on("click", ".pa-gvis", gdialog_settings_gvis_click);
        }

        const gvis = {}, ggr = {}, ggra = {};
        for (let su of gdialog_su) {
            if (!su.scores_visible_pinned) {
                gvis["null"] = (gvis["null"] || 0) + 1;
            } else if (su.scores_visible) {
                gvis["true"] = (gvis["true"] || 0) + 1;
            } else {
                gvis["false"] = (gvis["false"] || 0) + 1;
            }
            const grcid = su.gradercid || 0;
            ggr[grcid] = (ggr[grcid] || 0) + 1;
        }
        for (let su of smap) {
            const grcid = su.gradercid || 0;
            ggra[grcid] = (ggra[grcid] || 0) + 1;
        }
        $(gs).find("input").prop("checked", false).prop("indeterminate", false);
        for (let x in gvis) {
            $(gs).find(".pa-gvis[value=" + x + "]").prop("checked", !!gvis[x]).prop("indeterminate", gvis[x] && gvis[x] !== gdialog_su.length);
        }
        $(gs).find("input[name=gradertype][value=" + ((ggr[0] || 0) === gdialog_su.length ? "clear" : "set") + "]").prop("checked", true);
        for (let x in ggr) {
            if (x != 0) {
                const e = f.elements["grader" + x];
                e.checked = true;
                e.indeterminate = ggr[x] !== gdialog_su.length;
            }
        }
        $(gs).find(".pa-grader").each(function () {
            const grcid = +this.name.substring(6),
                ngr = ggr[grcid] || 0, ngra = ggra[grcid] || 0,
                elt = this.closest(".checki").lastChild;
            if (ngra && ngr === ngra) {
                elt.textContent = " (".concat(ngra, ")");
            } else if (ngra) {
                elt.textContent = " (".concat(ngr, "/", ngra, ")");
            } else {
                elt.textContent = "";
            }
        });

        $gdialog.find(".pa-gdialog-tab").addClass("hidden");
        gs.classList.remove("hidden");
        $gdialog.find("button[name=bsubmit]").addClass("hidden");
    }
    function gdialog_mode() {
        $gdialog.find(".nav-pills button").removeClass("btn-primary");
        if (this.name === "mode-gradesheet") {
            gdialog_mode_gradesheet();
        } else if (this.name === "mode-settings") {
            gdialog_mode_settings();
        } else {
            gdialog_mode_values();
        }
        this.classList.add("btn-primary");
    }
    function gdialog() {
        const checked_spos = $j.find(".papsel:checked").toArray().map(function (x) {
                return x.parentElement.parentElement.getAttribute("data-pa-spos");
            });
        if (checked_spos.length === 0) {
            alert("Select one or more students first");
            return;
        }
        const hc = popup_skeleton();
        hc.push('<h2></h2>');
        if (!anonymous)
            hc.push('<strong class="gt-name-email"></strong>');
        hc.push('<div class="pa-messages"></div>');

        hc.push('<div class="nav-pills">', '</div>');
        hc.push('<button type="button" class="btn btn-primary no-focus is-mode" name="mode-values">Values</button>');
        hc.push('<button type="button" class="btn no-focus is-mode" name="mode-gradesheet">Gradesheet</button>');
        hc.push('<button type="button" class="btn no-focus is-mode" name="mode-settings">Settings</button>');
        hc.pop();

        hc.push('<div class="pa-gdialog-tab pa-gradelist is-modal"></div>');
        hc.push('<div class="pa-gdialog-tab pa-gdialog-gradesheet multicol-3 hidden"></div>');
        hc.push('<div class="pa-gdialog-tab pa-gdialog-settings hidden"></div>');

        hc.push_actions();
        hc.push('<button type="button" name="bsubmit" class="btn-primary">Save</button>');
        hc.push('<button type="button" name="cancel">Cancel</button>');
        hc.push('<span class="btnbox"><button type="button" name="prev" class="btnl">&lt;</button><button type="button" name="next" class="btnl">&gt;</button></span>');
        $gdialog = hc.show(false);
        $gdialog.children(".modal-dialog").addClass("modal-dialog-wide");
        $gdialog.find("form").addClass("pa-psetinfo")[0].pa__gradesheet = gradesheet;
        gdialog_mode_values();
        $gdialog.on("click", ".pa-gdialog-section", gdialog_section_click);
        $gdialog.on("change blur", ".pa-gradevalue", gdialog_gradelist_change);
        $gdialog.on("input change", ".pa-gradevalue", gdialog_gradelist_input);
        $gdialog.on("keydown", gdialog_key);
        $gdialog.on("keydown", "input, textarea, select", gdialog_key);
        //$gdialog.find(".pa-gradelist").on("input", "input, textarea, select", gdialog_gradelist_input);
        $gdialog.find("button[name=bsubmit]").on("click", function () { gdialog_store(null); });
        $gdialog.find("button[name=prev], button[name=next]").on("click", gdialog_traverse);
        $gdialog.find("button.is-mode").on("click", gdialog_mode);
        $gdialog.find("button[name=prev], button[name=next]").prop("disabled", true).addClass("hidden");
        gdialog_fill(checked_spos);
        hc.show();
    }
    $j.closest("form").on("click", ".js-gdialog", function (event) {
        gdialog.call(this);
        event.preventDefault();
    });

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
    function render_tds(tre, s) {
        for (let i = 0; i !== col.length; ++i) {
            const tde = document.createElement("td");
            col[i].td.call(col[i], tde, s, ptconf);
            tre.appendChild(tde);
        }
    }
    function render_trs(tbody, a) {
        const tpl = document.createElement("template");
        tpl.innerHTML = a.join("");
        tbody.appendChild(tpl.content);
    }
    function render() {
        const tfixed = $j.hasClass("want-gtable-fixed"),
            rem = parseFloat(window.getComputedStyle(document.documentElement).fontSize);
        let a = ['<tr class="gt k0">'];
        for (const c of col) {
            a.push(c.th.call(c));
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
        const thead = document.createElement("thead");
        render_trs(thead, a);
        a = [];
        if (tfixed) {
            let td = thead.firstChild.firstChild;
            for (const c of col) {
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
            $j.removeClass("want-gtable-fixed").css("table-layout", "fixed");
        }

        const tbody = $('<tbody class="has-hotlist"></tbody>')[0];
        $j[0].appendChild(tbody);
        if (!pconf.no_sort) {
            sort_data();
        }
        displaying_last_first = sort.f === "name" && sort.last;

        let trn = 0, was_boringness = 0;
        for (let i = 0; i !== data.length; ++i) {
            const s = data[i];
            s._spos = smap.length;
            smap.push(s);
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
            ptconf.mode = 0;
            render_tds(tre, s);
            tbody.appendChild(tre);
            for (let j = 0; s.partners && j < s.partners.length; ++j) {
                const ss = s.partners[j];
                ss._spos = smap.length;
                smap.push(ss);
                const trep = document.createElement("tr");
                trep.className = "gt k".concat(trn % 2, " gtrow-partner");
                trep.setAttribute("data-pa-spos", ss._spos);
                if (ss.uid) {
                    trep.setAttribute("data-pa-uid", ss.uid);
                }
                trep.setAttribute("data-pa-partner", 1);
                ptconf.mode = 1;
                render_tds(trep, s.partners[j]);
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

    $j.data("paTable", {
        name_text: function (uid) {
            var spos = $j.find("tr[data-pa-uid=" + uid + "]").attr("data-pa-spos");
            return spos ? render_name_text(smap[spos]) : null;
        },
        s: function (spos) {
            return data[spos];
        }
    });
    $j.children("tbody").on("pa-hotlist", make_hotlist);
}


