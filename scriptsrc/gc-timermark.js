// gc-timermark.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { GradeClass } from "./gc.js";
import { handle_ui, addClass, removeClass, toggleClass } from "./ui.js";
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

function remove_tmto(ge, tm, makesticky) {
    if (tm && tm.pa__tmto) {
        clearTimeout(tm.pa__tmto);
        delete tm.pa__tmto;
    }
    if (tm && tm.pa__tmsticky && !makesticky) {
        tm.pa__tmsticky.parentElement.remove();
        delete tm.pa__tmsticky;
    } else if (tm && !tm.pa__tmsticky && makesticky) {
        const gsection = tm.closest(".pa-gsection");
        if (gsection) {
            const e = document.createElement("div"),
                tms = document.createElement("div");
            e.className = "pa-gnote pa-timermark-sticky";
            e.setAttribute("data-pa-grade", ge.key);
            tms.className = "pa-timermark-alert";
            e.appendChild(tms);
            gsection.parentElement.insertBefore(e, gsection);
            tm.pa__tmsticky = tms;
        }
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
                    ch[0] = ch[0].concat(" → ", sts, " ");
                    const strong = document.createElement("strong");
                    strong.className = "overdue";
                    strong.textContent = "(" + sec2text(delta) + " later)";
                    ch.push(strong);
                } else {
                    ch[0] = ch[0].concat(" → ", sts, " (", sec2text(delta), " later)");
                }
            }
            elt.replaceChildren(...ch);
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
    unmount: function (elt) {
        const tm = elt.querySelector(".pa-timermark-result");
        remove_tmto(this, tm, false);
        elt.remove();
    },
    update_edit: function (elt, v, opts) {
        const gi = opts.gradesheet;
        let timeout = timeout_value(this, gi);
        elt.querySelectorAll(".js-timermark").forEach(function (e) {
            e.classList.toggle("hidden", !v !== (e.value === "1"));
        });
        const tm = elt.querySelector(".pa-timermark-result");
        tm.classList.toggle("hidden", !v && !timeout);
        remove_tmto(this, tm, true);
        let t = strftime(timefmt, v);
        if (v
            && timeout
            && v + timeout + 600 > +document.body.getAttribute("data-now")) {
            const arg = {
                deadline: v + timeout + +document.body.getAttribute("data-time-skew"),
                start_at_text: t,
                timeout: timeout,
                tmelt: tm,
                stelt: tm.pa__tmsticky
            };
            timermark_interval(arg);
        } else if (v) {
            if (gi && (gi.student_timestamp || 0) > v) {
                t = t.concat(" (updated ", sec2text(gi.student_timestamp - v), " later at ", strftime(timefmt, gi.student_timestamp), ")");
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

function timermark_interval(arg) {
    const now = new Date().getTime(),
        dt = Math.round(arg.deadline - now / 1000);

    const tmch = [arg.start_at_text];
    if (dt > 630) {
        tmch[0] = tmch[0].concat(" (", sec2text(Math.ceil(dt / 60) * 60), " left)");
    } else if (dt > 300) {
        tmch[0] = tmch[0].concat(" (", sec2text(dt), " left)");
    } else if (dt >= -599) {
        const e = document.createElement("strong");
        e.className = "overdue";
        e.textContent = "(".concat(sec2text(Math.abs(dt)), dt < 0 ? " over)" : " left)");
        tmch[0] += " ";
        tmch.push(e);
    } else {
        tmch[0] = tmch[0].concat(" (", sec2text(-Math.ceil(dt / 60) * 60), " over)");
    }
    arg.tmelt.replaceChildren(...tmch);

    if (arg.stelt) {
        let t;
        if (dt > 630) {
            t = sec2text(Math.ceil(dt / 60) * 60) + " left";
        } else if (dt >= 0) {
            t = sprintf("%d:%02d left", Math.floor(dt / 60), dt % 60);
        } else if (dt > -600) {
            t = sprintf("%d:%02d over", Math.floor(-dt / 60), -dt % 60);
        } else {
            t = sec2text(Math.floor(-dt / 60) * 60) + " over";
        }
        arg.stelt.replaceChildren(t);
        toggleClass(arg.stelt, "urgent", dt <= 600 && dt > -900);
    }

    let nextdt;
    if (dt > 660 || dt <= -600) {
        nextdt = Math.floor((dt - 1) / 60) * 60;
    } else if (dt > 630) {
        nextdt = 630;
    } else {
        nextdt = Math.floor(dt - 0.1);
    }

    let alarmdelay = (arg.deadline - nextdt) * 1000 - 490 - now;
    if (alarmdelay <= 0) {
        alarmdelay += 1000;
    }
    arg.tmelt.pa__tmto = setTimeout(timermark_interval, alarmdelay, arg);
}
