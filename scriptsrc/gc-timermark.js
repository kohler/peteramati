// gc-timermark.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { handle_ui, addClass, removeClass } from "./ui.js";
import { sprintf, strftime, sec2text } from "./utils.js";


const timefmt = "%Y-%m-%d %H:%M";

function timeout_value(ge, gi) {
    let toge, tov;
    if (ge.timeout_entry
        && gi
        && (toge = gi.entries[ge.timeout_entry])
        && (tov = toge.value_in(gi)) != null) {
        return tov;
    } else {
        return ge.timeout;
    }
}

GradeClass.add("timermark", {
    text: function (v) {
        if (v == null || v === 0) {
            return "–";
        } else {
            return strftime(timefmt, v);
        }
    },
    simple_text: GradeClass.basic_text,
    tcell: function (v) {
        if (v == null || v === 0) {
            return "";
        } else {
            return strftime(timefmt, v);
        }
    },
    tcell_width: 10,
    make_compare: function (col) {
        const gidx = col.gidx;
        return function (a, b) {
            const ag = a.grades && a.grades[gidx],
                bg = b.grades && b.grades[gidx];
            if (ag === "" || ag == null || ag == 0 || bg === "" || bg == null || bg == 0) {
                if (ag !== "" && ag != null && ag != 0) {
                    return -1;
                } else if (bg !== "" && bg != null && bg != 0) {
                    return 1;
                }
            } else if (ag < bg) {
                return -1;
            } else if (ag > bg) {
                return 1;
            }
            return a._sort_user.localeCompare(b._sort_user);
        };
    },
    mount_show: function (elt, id) {
        elt.id = id;
        addClass(elt, "pa-gradevalue");
    },
    update_show: function (elt, v, opts) {
        const gi = opts.gradesheet;
        if (v == null || v === 0) {
            elt.textContent = "";
        } else {
            let ch = [strftime(timefmt, v)];
            if (gi && (gi.student_timestamp || 0) > v) {
                const sts = strftime(timefmt, gi.student_timestamp),
                    delta = gi.student_timestamp - v,
                    timeout = timeout_value(this, gi);
                if (timeout && delta > timeout + 120) {
                    ch[0] += " → " + sts + " ";
                    const strong = document.createElement("strong");
                    strong.className = "overdue";
                    strong.textContent = sprintf("(%dh%dm later)", delta / 3600, (delta / 60) % 60);
                    ch.push(strong);
                } else {
                    ch[0] += sprintf(" → %s (%dh%dm later)", sts, delta / 3600, (delta / 60) % 60);
                }
            }
            elt.replaceChildren(ch);
        }
        return false;
    },
    mount_edit: function (elt) {
        removeClass(elt, "pa-gradevalue");
        const but = document.createElement("button");
        but.type = "button";
        but.className = "ui js-timermark hidden mr-2";
        but.name = this.key + ":b";
        but.value = 1;
        but.disabled = this.disabled;
        but.append("Press to start");
        const fr = new DocumentFragment;
        fr.append(but);
        if (siteinfo.user.is_pclike) {
            const rbut = document.createElement("button");
            rbut.type = "button";
            rbut.className = "ui js-timermark hidden mr-2";
            rbut.name = this.key + ":r";
            rbut.value = 0;
            rbut.disabled = this.disabled;
            rbut.append("Reset");
            fr.append(rbut);
        }
        const sp = document.createElement("span");
        sp.className = "pa-timermark-result hidden";
        const gv = document.createElement("input");
        gv.type = "hidden";
        gv.className = "uich pa-gradevalue";
        gv.name = this.key;
        fr.append(sp, gv);
        return fr;
    },
    update_edit: function (elt, v, opts) {
        const gi = opts.gradesheet;
        let timeout = timeout_value(this, gi);
        elt.querySelectorAll(".js-timermark").forEach(function (e) {
            e.classList.toggle("hidden", !v !== (e.value === "1"));
        });
        const tm = elt.querySelector(".pa-timermark-result");
        tm.classList.toggle("hidden", !v && !timeout);
        tm.pa_tmto && clearTimeout(tm.pa_tmto);
        tm.pa_tmto = null;
        if (v
            && timeout
            && v + timeout > +document.body.getAttribute("data-now")) {
            timermark_interval(this, tm, v, timeout);
        } else if (v) {
            let t = strftime(timefmt, v);
            if (gi && (gi.student_timestamp || 0) > v) {
                const delta = gi.student_timestamp - v;
                t += sprintf(" (updated %dh%dm later at %s)", delta / 3600, (delta / 60) % 60, strftime(timefmt, gi.student_timestamp));
            }
            tm.textContent = t;
        } else if (timeout) {
            tm.textContent = "Time once started: " + sec2text(timeout);
        }
    },
    justify: "left",
    sort: "forward"
});

handle_ui.on("js-timermark", function () {
    const colon = this.name.indexOf(":"),
        name = this.name.substring(0, colon),
        elt = this.closest("form").elements[name];
    elt.value = this.value;
    $(elt).trigger("change");
});

function timermark_interval(ge, tm, gv, timeout) {
    const delta = +document.body.getAttribute("data-time-skew"),
        now = new Date().getTime(),
        left = gv + timeout + delta - now / 1000,
        ch = [strftime(timefmt, gv)];
    let next;
    if (left > 360) {
        ch[0] = ch[0].concat(" (", sec2text(left), " left)");
        next = 30000 + Math.floor(left * 1000) % 1000;
    } else if (left > 0) {
        ch[0] += " ";
        const strong = document.createElement("strong");
        strong.className = "overdue";
        strong.textContent = "(".concat(sec2text(left), " left)");
        ch.push(strong);
        next = 500 + Math.floor(left * 1000) % 500;
    } else {
        next = 0;
    }
    tm.replaceChildren(...ch);

    if (next) {
        tm.pa_tmto = setTimeout(timermark_interval, next - 1, ge, tm, gv, timeout);
    } else {
        tm.pa_tmto = null;
    }
}
