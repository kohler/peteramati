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
        && (tov = gi.grade_value(toge)) != null) {
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
            elt.innerText = "";
        } else {
            let t = strftime(timefmt, v);
            if (gi && (gi.student_timestamp || 0) > v) {
                const sts = strftime(timefmt, gi.student_timestamp),
                    delta = gi.student_timestamp - v,
                    timeout = timeout_value(this, gi);
                if (timeout && delta > timeout + 120) {
                    t += sprintf(" → %s <strong class=\"overdue\">(%dh%dm later)</strong>", sts, delta / 3600, (delta / 60) % 60);
                } else {
                    t += sprintf(" → %s (%dh%dm later)", sts, delta / 3600, (delta / 60) % 60);
                }
            }
            elt.innerHTML = t;
        }
        return false;
    },
    mount_edit: function (elt) {
        removeClass(elt, "pa-gradevalue");
        let t = '<button class="ui js-timermark hidden mr-2" type="button" name="'.concat(this.key, ':b" value="1">Press to start</button>');
        if (siteinfo.user.is_pclike) {
            t = t.concat('<button class="ui js-timermark hidden mr-2" type="button" name="', this.key, ':r" value="0">Reset</button>');
        }
        return t.concat('<span class="pa-timermark-result hidden"></span><input type="hidden" class="uich pa-gradevalue" name="', this.key, '">');
    },
    update_edit: function (elt, v, opts) {
        const gi = opts.gradesheet;
        let timeout = timeout_value(this, gi);
        elt.querySelectorAll(".js-timermark").forEach(function (e) {
            e.classList.toggle("hidden", !v !== (e.value === "1"));
        });
        const tm = elt.querySelector(".pa-timermark-result");
        tm.classList.toggle("hidden", !v && !timeout);
        const ve = elt.querySelector(".pa-gradevalue"),
            to = $(ve).data("pa-timermark-interval");
        to && clearInterval(to);
        if (v
            && timeout
            && v + timeout > +document.body.getAttribute("data-now")) {
            timermark_interval(this, tm, v, timeout);
            $(ve).data("pa-timermark-interval", setInterval(timermark_interval, 15000, this, tm, v, timeout));
        } else if (v) {
            let t = strftime(timefmt, v);
            if (gi && (gi.student_timestamp || 0) > v) {
                const delta = gi.student_timestamp - v;
                t += sprintf(" (updated %dh%dm later at %s)", delta / 3600, (delta / 60) % 60, strftime(timefmt, gi.student_timestamp));
            }
            tm.innerHTML = t;
        } else if (timeout) {
            tm.innerHTML = "Time once started: " + sec2text(timeout);
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
        left = gv + timeout - new Date().getTime() / 1000 + delta;
    let t = strftime(timefmt, gv);
    if (left > 360) {
        t = t.concat(" (", sec2text(left), " left)");
    } else if (left > 0) {
        t = t.concat(" <strong class=\"overdue\">(", sec2text(left), " left)</strong>");
    }
    tm.innerHTML = t;
}
