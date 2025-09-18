// run.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { wstorage, sprintf, strftime } from "./utils.js";
import { $e, hasClass, addClass, removeClass, fold61, handle_ui } from "./ui.js";
import { event_key } from "./ui-key.js";
import { render_terminal } from "./render-terminal.js";
import { grades_fetch } from "./grade-ui.js";

function make_xterm_write_handler(write) {
    return function (event) {
        if (event.type === "keydown") {
            let key = event_key(event), mod = event_key.modcode(event);
            if (key.length === 1) {
                mod &= ~event_key.SHIFT;
                if (mod === 0 && event_key.printable(event)) {
                    // keep `key`
                } else if (mod === event_key.CTRL
                           && key >= "a"
                           && key <= "z") {
                    key = String.fromCharCode(key.charCodeAt(0) - 96);
                } else if ((mod === event_key.META && key == "v")
                           || (mod == event_key.CTRL && key == "V")) {
                    navigator.clipboard.readText().then(tx => write(tx));
                    event.preventDefault();
                    return;
                } else {
                    key = "";
                }
            } else {
                // send escape sequences compatible with xterm256-color terminfo
                if (key === "Enter" && !mod) {
                    key = "\r";
                } else if (key === "Escape" && !mod) {
                    key = "\x1b";
                } else if (key === "Backspace" && !mod) {
                    key = "\x7f";
                } else if (key === "Tab" && !mod) {
                    key = "\x09";
                } else if (key === "ArrowUp" && !mod) {
                    key = "\x1bOA";
                } else if (key === "ArrowDown" && !mod) {
                    key = "\x1bOB";
                } else if (key === "ArrowRight" && !mod) {
                    key = "\x1bOC";
                } else if (key === "Home" && !mod) {
                    key = "\x1bOH";
                } else if (key === "End" && !mod) {
                    key = "\x1bOF";
                } else if (key === "ArrowLeft" && !mod) {
                    key = "\x1bOD";
                } else if (key === "Delete" && !mod) {
                    key = "\x1b[3~";
                } else if (key === "PageUp" && !mod) {
                    key = "\x1b[5~";
                } else if (key === "PageDown" && !mod) {
                    key = "\x1b[6~";
                } else {
                    key = "";
                }
            }
            if (key !== "") {
                write(key);
                event.preventDefault();
            }
        }
        return false;
    };
}

function trim_data_to_offset(data, offset) {
    const re = /^([\x00-\x7F]*)([\u0080-\u07FF]*)([\u0800-\uD7FF\uE000-\uFFFF]*)((?:[\uD800-\uDBFF][\uDC00-\uDFFF])*)/y;
    let matchIndex = 0;
    while (matchIndex < data.data.length && data.offset < offset) {
        re.lastIndex = matchIndex;
        const m = data.data.match(re);
        if (!m) {
            break;
        }
        if (m[1].length) {
            const n = Math.min(offset - data.offset, m[1].length);
            matchIndex += n;
            data.offset += n;
        }
        if (m[2].length) {
            const n = Math.min(offset - data.offset, m[2].length * 2);
            matchIndex += n / 2;
            data.offset += n;
        }
        if (m[3].length) {
            const n = Math.min(offset - data.offset, m[3].length * 3);
            matchIndex += n / 3;
            data.offset += n;
        }
        if (m[4].length) {
            const n = Math.min(offset - data.offset, m[4].length * 2);
            matchIndex += n / 2; // surrogate pairs
            data.offset += n;
        }
    }
    data.data = data.data.substring(matchIndex);
}

function utf8_length(str) {
    const re = /^([\x00-\x7F]*)([\u0080-\u07FF]*)([\u0800-\uD7FF\uE000-\uFFFF]*)((?:[\uD800-\uDBFF][\uDC00-\uDFFF])*)/y;
    let matchIndex = 0, offset = 0;
    while (matchIndex < str.length) {
        re.lastIndex = matchIndex;
        const m = str.match(re);
        if (!m) {
            break;
        }
        if (m[1].length) {
            matchIndex += m[1].length;
            offset += m[1].length;
        }
        if (m[2].length) {
            matchIndex += m[2].length;
            offset += m[2].length * 2;
        }
        if (m[3].length) {
            matchIndex += m[3].length;
            offset += m[3].length * 3;
        }
        if (m[4].length) {
            matchIndex += m[4].length;
            offset += m[4].length * 2; // surrogate pairs
        }
    }
    return offset;
}

export function run(button, opts) {
    const form = button.closest("form"),
        category = button.getAttribute("data-pa-run-category") || button.value,
        psetinfo = button.closest(".pa-psetinfo"),
        directory = psetinfo ? psetinfo.getAttribute("data-pa-directory") : "",
        therun = document.getElementById("pa-run-" + category),
        therunout = therun.closest(".pa-runout"),
        thepre = $(therun).find("pre");
    let thexterm,
        checkt,
        kill_checkt,
        queueid = opts.queueid || null, was_onqueue = false,
        eventsource = null,
        sendtimeout = null,
        completed = false;

    therunout && removeClass(therunout, "hidden");
    removeClass(therun, "need-run");
    fold61(therun, therunout, true);

    if (hasClass(therun, "pa-run-active")) {
        return true;
    }
    addClass(therun, "pa-run-active");

    if (opts.unfold && therun.hasAttribute("data-pa-timestamp")) {
        checkt = +therun.getAttribute("data-pa-timestamp");
    } else if (opts.timestamp) {
        checkt = opts.timestamp;
    }
    if (!checkt) {
        therun.removeAttribute("data-pa-timestamp");
    }
    if (!checkt && opts.clear !== false) {
        thepre[0].replaceChildren();
        addClass(thepre[0].parentElement, "pa-run-short");
        thepre[0].removeAttribute("data-pa-terminal-style");
        $(therun).children(".pa-runrange").remove();
    } else if (therun.lastChild) {
        $(therun.lastChild).find("span.pa-runcursor").remove();
    }

    function stop_button(on) {
        const h3 = therunout ? therunout.firstChild : null;
        if (h3 && h3.tagName === "H3") {
            const btn = $(h3).find(".pa-runstop");
            if (on && !btn.length) {
                const b = $e("button", {class: "btn-danger pa-runstop", type: "button"}, "Stop");
                $(b).click(stop).appendTo(h3);
            } else if (!on && btn.length) {
                btn.remove();
            }
        }
    }

    function terminal_char_width(min, max) {
        const x = $e("span", {style: "position:absolute"}, "0");
        thepre.append(x);
        const w = Math.trunc(thepre.width() / $(x).width() / 1.33);
        x.remove();
        return Math.max(min, Math.min(w, max));
    }

    if (therun.getAttribute("data-pa-xterm-js")
        && therun.getAttribute("data-pa-xterm-js") !== "false"
        && window.Terminal) {
        removeClass(thepre[0].parentElement, "pa-run-short");
        addClass(thepre[0].parentElement, "pa-run-xterm-js");
        const cols = +therun.getAttribute("data-pa-columns"),
            rows = +therun.getAttribute("data-pa-rows"),
            fontsize = +therun.getAttribute("data-pa-font-size"),
            args = {
                cols: !cols || cols < 0 || cols != cols ? terminal_char_width(80, 132) : cols,
                rows: !rows || rows < 0 || rows != rows ? 25 : rows
            };
        if (fontsize && fontsize > 0 && fontsize == fontsize) {
            args.fontSize = fontsize;
        }
        thexterm = new Terminal(args);
        thexterm.open(thepre[0]);
        thexterm.attachCustomKeyEventHandler(make_xterm_write_handler(write));
        if (opts.focus) {
            thexterm.focus();
        }
    }

    function scroll_therun() {
        if (!thexterm
            && (hasClass(therun, "pa-run-short")
                || therun.hasAttribute("data-pa-runbottom"))) {
            requestAnimationFrame(function () {
                if (therun.scrollHeight > therun.clientHeight) {
                    removeClass(therun, "pa-run-short");
                }
                if (therun.hasAttribute("data-pa-runbottom")) {
                    therun.scrollTop = Math.max(therun.scrollHeight - therun.clientHeight, 0);
                }
            });
        }
    }

    if (!therun.hasAttribute("data-pa-opened")) {
        therun.setAttribute("data-pa-opened", "true");
        if (!thexterm) {
            therun.setAttribute("data-pa-runbottom", "true");
            therun.addEventListener("scroll", function () {
                requestAnimationFrame(function () {
                    if (therun.scrollTop + therun.clientHeight >= therun.scrollHeight - 10)
                        therun.setAttribute("data-pa-runbottom", "true");
                    else
                        therun.removeAttribute("data-pa-runbottom");
                });
            });
            scroll_therun();
        }
    }

    let ibuffer = "", // initial buffer; holds data before any results arrive
        preamble_offset = null,
        offset = 0, backoff = 50, times = null;

    function hide_cursor() {
        if (thexterm) {
            thexterm.write("\x1b[?25l"); // “hide cursor” escape
        } else if (therun.lastChild) {
            $(therun.lastChild).find(".pa-runcursor").remove();
        }
    }

    function complete(isdone) {
        removeClass(therun, "pa-run-active");
        if (isdone !== false && !completed) {
            hide_cursor();
            if (button.hasAttribute("data-pa-run-grade")) {
                grades_fetch(psetinfo); // XXX not on replay
            }
            opts.done_function && opts.done_function();
            completed = true;
        }
        send_after(-1);
    }

    function append(str, done) {
        if (thexterm) {
            thexterm.write(str, done);
        } else {
            render_terminal(thepre[0], str, {cursor: true, directory: directory});
            done && Promise.resolve(true).then(done);
        }
    }

    function append_data(str, data, done) {
        if (ibuffer !== null) { // haven't started generating output
            ibuffer += str;
            const pos = ibuffer.indexOf("\n\n");
            if (pos < 0) {
                return; // not ready yet
            }
            preamble_offset = utf8_length(ibuffer.substr(0, pos + 2));
            let tsmsg = opts.clear === false ? "" : "\x1bc";
            if (data && data.timestamp) {
                tsmsg += "\x1b[38;5;108;3m...started " + strftime("%l:%M:%S%P %e %b %Y", new Date(data.timestamp * 1000)) + "\x1b[m\r\n";
            }
            str = tsmsg + ibuffer.substr(pos + 2);
            ibuffer = null;
        }
        if (str !== "") {
            append(str, done);
        }
    }

    function parse_times(times) {
        let a = [0, 0], p = 0;
        while (p < times.length) {
            let n = times.indexOf("\n", p + 1), c;
            if (n < 0) {
                n = times.length;
            }
            const ch = times.charCodeAt(p);
            if ((ch === 43 /* + */ || (ch >= 48 && ch <= 57 /* 0-9 */))
                && (c = times.indexOf(",", p)) >= 0
                && c < n) {
                let time = +times.substring(p, c),
                    offset = +times.substring(c + 1, n);
                if (ch === 43) {
                    time += a[a.length - 2];
                }
                if (times.charCodeAt(c + 1) === 43) {
                    offset += a[a.length - 1];
                }
                a.push(time, offset);
            }
            p = n + 1;
        }
        return a;
    }

    function set_time_data(data, at_end) {
        let tpos, tstart, tlast, timeout, running,
            partial_outstanding = false, partial_time;
        if (times) {
            return;
        } else if (data.offset !== 0) {
            throw new Error("fuck");
        }

        let factor = data.time_factor;
        {
            const runspeed = wstorage.site_json(false, "pa-runspeed-" + category);
            if (runspeed && runspeed[1] >= (new Date).getTime() - 86400000) {
                factor = runspeed[0];
            } else if (runspeed) {
                wstorage.site_json(false, "pa-runspeed-" + category, null);
            }
            if (factor < 0.1 || factor > 10) {
                factor = 1;
            }
        }

        times = data.time_data;
        if (typeof times === "string") {
            times = parse_times(times);
        }

        let ebutton, erange, etime, espeed;
        if (times.length > 2) {
            therun.prepend($e("div", "pa-runrange",
                (ebutton = $e("button", {type: "button", "class": "pa-runrange-play"})),
                (erange = $e("input", {type: "range", "class": "pa-runrange-range", min: 0, max: times[times.length -2]})),
                (etime = $e("span", "pa-runrange-time")),
                $e("span", {title: "Slow", "class": "pa-runrange-speed-slow"}, "🐢"),
                (espeed = $e("input", {type: "range", "class": "pa-runrange-speed", min: "0.1", max: 10, step: "0.1"})),
                $e("span", {title: "Fast", "class": "pa-runrange-speen-fast"}, "🐇")));
            erange.addEventListener("input", function () {
                running = false;
                addClass(ebutton, "paused");
                f(+this.value);
            }, false);
            ebutton.addEventListener("click", function () {
                if (hasClass(ebutton, "paused")) {
                    removeClass(ebutton, "paused");
                    running = true;
                    tstart = (new Date).getTime();
                    if (tlast < times[times.length - 2])
                        tstart -= tlast / factor;
                    f(null);
                } else {
                    addClass(ebutton, "paused");
                    running = false;
                }
            }, false);
            espeed.addEventListener("input", function () {
                factor = +this.value;
                wstorage.site(false, "pa-runspeed-" + category, [factor, (new Date).getTime()]);
                if (running) {
                    tstart = (new Date).getTime() - tlast / factor;
                    f(null);
                }
            }, false);
            espeed.value = factor;
        }

        data = {
            data: data.data,
            end_offset: data.end_offset,
            size: data.size || data.end_offset,
            timestamp: data.timestamp
        };

        function set_time() {
            if (erange) {
                erange.value = tlast;
                etime.textContent = sprintf("%d:%02d.%03d", Math.trunc(tlast / 60000), Math.trunc(tlast / 1000) % 60, Math.trunc(tlast) % 1000);
            }
        }

        function load_more() {
            if (!partial_outstanding) {
                partial_outstanding = true;
                send({offset: data.end_offset}, function (xdata) {
                    if (xdata.ok && xdata.offset === data.end_offset) {
                        data.data += xdata.data;
                        data.end_offset = xdata.end_offset;
                    } else if (!xdata.ok) {
                        data.size = data.end_offset;
                    }
                    partial_outstanding = false;
                    partial_time && f(partial_time);
                });
            }
        }

        function add_data() {
            if (ibuffer !== null) {
                const nlnl = data.data.indexOf("\n\n");
                append_data(data.data.substring(0, nlnl + 2), data);
                // ... which sets `ibuffer = null`
            }
        }

        function tpos_offset(tpos) {
            if (tpos < times.length) {
                return preamble_offset + times[tpos + 1];
            } else {
                return data.size;
            }
        }

        function tpos_lower_bound(l, r, time) {
            while (l < r) {
                const m = l + (((r - l) >> 1) & ~1);
                if (time <= times[m]) {
                    r = m;
                } else {
                    l = m + 2;
                }
            }
            return l;
        }

        function offset_lower_bound(offset) {
            let l = 0, r = times.length;
            while (l < r) {
                const m = l + (((r - l) >> 1) & ~1);
                if (offset <= times[m + 1]) {
                    r = m;
                } else {
                    l = m + 2;
                }
            }
            return l;
        }

        function f(time) {
            if (time === null) {
                if (running) {
                    time = ((new Date).getTime() - tstart) * factor;
                } else {
                    return;
                }
            }

            // find `npos`, the new time position
            let npos = tpos;
            if (npos >= times.length || time < times[npos]) {
                npos = 0;
            }
            if (npos + 2 < times.length && time >= times[npos]) {
                npos = tpos_lower_bound(npos, times.length, time);
            }
            while (npos < times.length && time >= times[npos]) {
                npos += 2;
            }

            // reset buffer if new time position is less than current
            if (npos < tpos) {
                ibuffer = "";
                tpos = 0;
            }
            if (ibuffer !== null) {
                add_data();
            }

            // range of data [boffset, eoffset) to feed to xterm.js
            let boffset = tpos_offset(tpos), eoffset = tpos_offset(npos);

            // flow control: give xterm.js 8MB of data at a time
            const maxdata = 8 << 20;
            if (boffset + maxdata < eoffset) {
                let lpos = tpos;
                while (lpos < npos) {
                    const m = lpos + (((npos - lpos) >> 1) & ~1);
                    if (boffset + maxdata < times[m + 1] + preamble_offset) {
                        npos = m;
                        eoffset = tpos_offset(m);
                    } else {
                        lpos = m + 2;
                    }
                }
            }

            // maybe load more data
            if (data.end_offset < data.size) {
                if (eoffset > data.end_offset) {
                    partial_time = time;
                    load_more();
                    return;
                } else if (eoffset > data.end_offset - (1 << 20)) {
                    load_more();
                }
            }

            tlast = npos < times.length ? Math.min(time, times[npos]) : time;
            partial_time = time > tlast ? time : null;
            if (partial_time) {
                const moffset = Math.min(boffset + (4 << 20), eoffset);
                append_data(data.data.substring(boffset, moffset), data, () => { f(partial_time) });
                boffset = moffset;
            }
            append_data(data.data.substring(boffset, eoffset), data);
            scroll_therun();
            set_time();

            tpos = npos;
            if (timeout) {
                timeout = clearTimeout(timeout);
            }
            if (running && !partial_time) {
                if (tpos < times.length) {
                    timeout = setTimeout(f, Math.min(100, (times[tpos] - (tpos ? times[tpos - 2] : 0)) / factor), null);
                } else {
                    ebutton && addClass(ebutton, "paused");
                    hide_cursor();
                }
            }
        }

        if (at_end) {
            tpos = times.length;
            tlast = times[tpos - 2];
            running = false;
            ebutton && addClass(ebutton, "paused");
            set_time();
        } else {
            tpos = 0;
            tlast = 0;
            tstart = (new Date).getTime();
            running = true;
            if (times.length) {
                if (therun.getAttribute("data-pa-start") === "alternate-screen") {
                    const nlnl = data.data.indexOf("\n\n"),
                        altscr = data.data.indexOf("\x1b[?1049h"),
                        xtpos = altscr > nlnl ? offset_lower_bound(altscr - nlnl - 2) : -1;
                    if (xtpos >= 0 && xtpos < times.length) {
                        tstart -= times[xtpos] / factor;
                    }
                }
                f(null);
            }
        }
    }

    let send_out = 0, send_args = {};

    function succeed_eventsource(msge) {
        let ok = false;
        if (msge && msge.data) {
            try {
                let json = JSON.parse(msge.data);
                if (json
                    && typeof json === "object"
                    && json.data != null
                    && json.offset != null) {
                    json.offset += preamble_offset;
                    json.end_offset += preamble_offset;
                    if (json.ok == null) {
                        json.ok = true;
                    }
                    if (json.offset <= offset) {
                        succeed(json);
                    } else {
                        send({write: ""});
                    }
                    ok = true;
                }
            } catch {
            }
        }
        ok || error_eventsource();
    }

    function error_eventsource() {
        if (eventsource) {
            eventsource.close();
            eventsource = false;
            send();
        }
    }

    function send_after(ms) {
        if (sendtimeout) {
            clearTimeout(sendtimeout);
        }
        if (ms >= 0) {
            sendtimeout = setTimeout(send_from_timeout, ms);
        } else {
            sendtimeout = null;
        }
    }

    function send_from_timeout() {
        sendtimeout = null;
        send();
    }

    function succeed(data) {
        if (was_onqueue) {
            append("\r\x1b[K");
            was_onqueue = false;
        }
        if (data && data.onqueue) {
            queueid = data.queueid;
            let t = `\x1b[38;5;108;3mOn queue, ${data.nahead} ${data.nahead == 1 ? "job" : "jobs"} ahead`;
            if (data.headage) {
                let headage = data.headage;
                if (headage > 10) {
                    headage = Math.round(headage / 5 + 0.5) * 5;
                }
                t += `, oldest began about ${headage} ${headage == 1 ? "second" : "seconds"} ago`;
            }
            append(t + "\x1b[m");
            scroll_therun();
            was_onqueue = true;
            send_after(8000);
            return;
        }

        stop_button(data && (data.status == null || data.status === "working") && !data.done);

        if (!data || !data.ok) {
            let x = "Unknown error";
            if (data && data.loggedout) {
                x = "You have been logged out (perhaps due to inactivity). Please reload this page.";
            } else if (data) {
                if (data.error && data.error !== true) {
                    x = data.error;
                } else if (data.message) {
                    x = data.message;
                }
                if (data.errorcode === 1001 // ERRORCODE_RUNCONFLICT
                    && data.conflict_timestamp) {
                    kill_checkt = data.conflict_timestamp;
                }
            }
            append("\x1b[1;3;31m" + x + "\x1b[m\r\n");
            scroll_therun();
            return complete();
        } else if (data.done) {
            complete(false);
        }

        if (data.eventsource
            && data.status === "working"
            && eventsource == null) {
            eventsource = new EventSource(window.siteinfo.base.concat("runevents/v1/", data.eventsource));
            eventsource.onmessage = succeed_eventsource;
            eventsource.onerror = error_eventsource;
        }

        if (!checkt && data.timestamp) {
            checkt = data.timestamp;
            therun.setAttribute("data-pa-timestamp", data.timestamp);
        }

        // Skip data up to UTF-8 `offset`
        if (data.data
            && data.offset < offset) {
            trim_data_to_offset(data, offset);
            if (data.offset < offset) {
                send_after(0);
                return;
            }
        }

        // Stay on alternate screen when done (rather than clearing it)
        let m;
        if (data.data
            && data.done
            && data.end_offset < data.size
            && (m = data.data.match(/\x1b\[\?1049l(?:[\r\n]|\x1b\[\?1l|\x1b\[23;0;0t|\x1b>)*$/))) {
            data.data = data.data.substring(0, data.data.length - m[0].length);
        }

        // Pure replay -> set_time_data
        if (data.done
            && data.time_data != null
            && ibuffer === ""
            && opts.timed !== false) {
            // Parse timing data
            set_time_data(data);
            return;
        }

        // Append data
        if (data.data != null
            && data.offset === offset
            && data.end_offset >= offset) {
            offset = data.end_offset;
            append_data(data.data, data);
            backoff = 100;
        }

        if (data.result) {
            if (!data.done || data.end_offset < data.size) {
                throw new Error("data.result must only be present on last");
            }
            if (ibuffer !== null) {
                append_data("\n\n", data);
            }
            append_data(data.result, data);
        }
        if (!data.data && !data.result) {
            backoff = Math.min(backoff * 2, 500);
        }

        scroll_therun();
        if (data.status === "old") {
            send_after(2000);
        } else if (!data.done || data.end_offset < data.size) {
            send_after(backoff);
        } else {
            complete();
            if (data.timed
                && !hasClass(therun.firstChild, "pa-runrange")
                && opts.timed !== false) {
                send({offset: 0}, succeed_add_times);
            }
        }
    }

    function succeed_add_times(data) {
        --send_out;
        if (data.data && data.done && data.time_data != null) {
            set_time_data(data, true);
        }
    }

    function send(args, success) {
        if (args && args.stop) {
            send_args.stop = 1;
        }
        if (args && args.write != null) {
            send_args.write = (send_args.write || "").concat(args.write);
        }
        if ((send_args.write && send_out > 0)
            || (!send_args.stop && send_args.write == null && eventsource)) {
            return;
        }

        let a = {};
        if (!form.elements.run) {
            a.run = category;
        }
        a.offset = offset;
        if (args && args.offset != null) {
            a.offset = args.offset;
        }
        if (checkt) {
            a.check = checkt;
        } else if (args && args.stop && kill_checkt) {
            a.check = kill_checkt;
        }
        if (queueid) {
            a.queueid = queueid;
        }
        Object.assign(a, send_args);
        delete send_args.write;
        delete send_args.stop;
        ++send_out;

        jQuery.ajax(form.getAttribute("action"), {
            data: $(form).serializeWith(a),
            type: "POST", cache: false, dataType: "json", timeout: 30000,
            success: function (data) {
                --send_out;
                (success || succeed)(data);
                send_args.write && send();
            },
            error: function () {
                $(form).find(".ajaxsave61").html("Failed");
                removeClass(therun, "pa-run-active");
            }
        });
    }

    function stop() {
        send({stop: 1});
    }

    function write(value) {
        send({write: value});
    }

    if (opts.headline && opts.clear === false && thepre[0].firstChild) {
        append("\r\n\r\n");
    }
    if (opts.headline) {
        append("\x1b[1;37m" + opts.headline + "\x1b[m\r\n");
    }
    if (opts.unfold && therun.getAttribute("data-pa-content")) {
        append(therun.getAttribute("data-pa-content"));
    }
    if (opts.focus) {
        $(therunout).scrollIntoView();
    }
    therun.removeAttribute("data-pa-content");
    scroll_therun();

    send();
    return false;
}


handle_ui.on("pa-runner", function () {
    run(this, {focus: true});
});

handle_ui.on("pa-run-show", function () {
    const parent = this.closest(".pa-runout"),
        name = parent.id.substring(4),
        therun = document.getElementById("pa-run-" + name);
    if (therun.hasAttribute("data-pa-timestamp") && hasClass(therun, "need-run")) {
        const thebutton = jQuery(".pa-runner[value='" + name + "']")[0];
        run(thebutton, {unfold: true});
    } else {
        fold61(therun, document.getElementById("run-" + name));
    }
});
