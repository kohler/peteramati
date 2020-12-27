// gc-timermark.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { handle_ui } from "./ui.js";
import { sprintf, strftime } from "./utils.js";


const timefmt = "%Y-%m-%d %H:%M";

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
    entry: function () {
        let t = '<button class="ui js-timermark hidden" type="button" name="'.concat(this.key, ':b" value="1">Press to start</button>');
        if (siteinfo.user.is_pclike) {
            t = t.concat('<button class="ui js-timermark hidden" type="button" name="', this.key, ':r" value="0">Reset</button>');
        }
        return t.concat('<span class="pa-timermark-result hidden"></span><input type="hidden" class="uich pa-gradevalue" name="', this.key, '">');
    },
    reflect_value: function (elt, g) {
        const pd = elt.closest(".pa-pd");
        pd.querySelectorAll(".js-timermark").forEach(function (e) {
            e.classList.toggle("hidden", !g !== (e.value === "1"));
        });
        const tm = pd.querySelector(".pa-timermark-result");
        tm.classList.toggle("hidden", !g);
        const to = $(elt).data("pa-timermark-interval");
        to && clearInterval(to);
        if (g
            && this.timeout
            && g + this.timeout > +document.body.getAttribute("data-now")) {
            timermark_interval(this, tm, g);
            $(elt).data("pa-timermark-interval", setInterval(timermark_interval, 15000, this, tm, g));
        } else if (g) {
            let t = strftime(timefmt, g);
            if (this._all && (this._all.updateat || 0) > g) {
                const delta = this._all.updateat - g;
                t += sprintf(" (updated %dh%dm later)", delta / 3600, (delta / 60) % 60);
            }
            tm.innerHTML = t;
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

function timermark_interval(ge, tm, gv) {
    const delta = +document.body.getAttribute("data-time-skew"),
        left = gv + ge.timeout - new Date().getTime() / 1000 + delta;
    let t = strftime(timefmt, gv);
    if (left >= 3600) {
        t += sprintf(" (%dh%dm left)", left / 3600, (left / 60) % 60);
    } else if (left > 360) {
        t += sprintf(" (%dm left)", left / 60);
    } else if (left > 0) {
        t += sprintf(" <strong class=\"overdue\">(%dm%ds left)</strong>", left / 60, left % 60);
    }
    tm.innerHTML = t;
}
