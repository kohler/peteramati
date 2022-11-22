// run.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

import { wstorage, sprintf, strftime } from "./utils.js";
import { hasClass, addClass, removeClass, fold61, handle_ui } from "./ui.js";
import { event_key, event_modkey } from "./ui-key.js";
import { render_terminal } from "./render-terminal.js";
import { grades_fetch } from "./grade-ui.js";

function make_xterm_write_handler(write) {
    return function (event) {
        if (event.type === "keydown") {
            let key = event_key(event), mod = event_modkey(event);
            if (key.length === 1) {
                if ((mod & 0xE) === 0 && event_key.printable(event)) {
                    // keep `key`
                } else if ((mod & 0xE) === event_modkey.CTRL
                           && key >= "a"
                           && key <= "z") {
                    key = String.fromCharCode(key.charCodeAt(0) - 96);
                } else if ((mod & 0xE) === event_modkey.META
                           && key == "v") {
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
                    key = "\x1B";
                } else if (key === "Backspace" && !mod) {
                    key = "\x7F";
                } else if (key === "Tab" && !mod) {
                    key = "\x09";
                } else if (key === "ArrowUp" && !mod) {
                    key = "\x1BOA";
                } else if (key === "ArrowDown" && !mod) {
                    key = "\x1BOB";
                } else if (key === "ArrowRight" && !mod) {
                    key = "\x1BOC";
                } else if (key === "ArrowLeft" && !mod) {
                    key = "\x1BOD";
                } else if (key === "PageUp" && !mod) {
                    key = "\x1B[5~";
                } else if (key === "PageDown" && !mod) {
                    key = "\x1B[6~";
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
        queueid = opts.queueid || null,
        eventsource = null,
        sendtimeout = null;

    therunout && removeClass(therunout, "hidden");
    removeClass(therun, "need-run");
    fold61(therun, therunout, true);

    if (opts.unfold && therun.hasAttribute("data-pa-timestamp")) {
        checkt = +therun.getAttribute("data-pa-timestamp");
    } else if (opts.timestamp) {
        checkt = opts.timestamp;
    }

    if (hasClass(form, "pa-run-active")) {
        return true;
    }
    $(form).find("button").prop("disabled", true);
    addClass(form, "pa-run-active");
    if (!checkt) {
        therun.removeAttribute("data-pa-timestamp");
    }

    if (!checkt && !opts.noclear) {
        thepre.html("");
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
                $("<button class=\"btn btn-danger pa-runstop\" type=\"button\">Stop</button>").click(stop).appendTo(h3);
            } else if (!on && btn.length) {
                btn.remove();
            }
        }
    }

    function terminal_char_width(min, max) {
        const x = $('<span style="position:absolute">0</span>').appendTo(thepre),
            w = Math.trunc(thepre.width() / x.width() / 1.33);
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

    function done(complete) {
        if (hasClass(form, "pa-run-active")) {
            removeClass(form, "pa-run-active");
            $(form).find("button").prop("disabled", false);
        }
        if (complete !== false) {
            hide_cursor();
            if (button.hasAttribute("data-pa-run-grade")) {
                grades_fetch(psetinfo); // XXX not on replay
            }
            opts.done_function && opts.done_function();
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

    function append_html(html) {
        if (typeof html === "string") {
            html = $(html)[0];
        }
        if (thexterm) {
            if (window.console) {
                console.log("xterm.js cannot render " + html);
            }
        } else {
            render_terminal(thepre[0], html, {cursor: true});
        }
    }

    function append_data(str, data, done) {
        if (ibuffer !== null) { // haven't started generating output
            ibuffer += str;
            var pos = ibuffer.indexOf("\n\n");
            if (pos < 0) {
                return; // not ready yet
            }

            preamble_offset = utf8_length(ibuffer.substr(0, pos + 2));
            str = ibuffer.substr(pos + 2);
            ibuffer = null;

            var tsmsg = "";
            if (data && data.timestamp) {
                tsmsg = "...started " + strftime("%l:%M:%S%P %e %b %Y", new Date(data.timestamp * 1000));
            }

            if (thexterm) {
                if (tsmsg !== "") {
                    tsmsg = "\x1b[3;1;38;5;86m" + tsmsg + "\x1b[m\r\n";
                }
                if (!opts.noclear) {
                    tsmsg = "\x1bc" + tsmsg;
                }
                str = tsmsg + str;
            } else {
                if (!opts.noclear) {
                    thepre.html("");
                }
                if (tsmsg !== "") {
                    append_html("<span class=\"pa-runtime\">" + tsmsg + "</span>");
                }
            }
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
            if (times.charCodeAt(p) !== 35 /* # */
                && (c = times.indexOf(",", p)) >= 0
                && c < n) {
                a.push(+times.substring(p, c), +times.substring(c + 1, n));
            }
            p = n + 1;
        }
        return a;
    }

    function append_timed(data, at_end) {
        let erange, etime, ebutton, espeed,
            tpos, tstart, tlast, timeout, running, factor,
            partial_outstanding = 0, partial_time;
        if (times) {
            return;
        } else if (data.offset !== 0) {
            throw new Error("fuck");
        }

        {
            const runspeed = wstorage.site_json(false, "pa-runspeed-" + category);
            factor = data.time_factor;
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
        if (times.length > 2) {
            erange = $('<div class="pa-runrange"><button type="button" class="pa-runrange-play"></button><input type="range" class="pa-runrange-range" min="0" max="' + times[times.length - 2] + '"><span class="pa-runrange-time"></span><span class="pa-runrange-speed-slow" title="Slow">🐢</span><input type="range" class="pa-runrange-speed" min="0.1" max="10" step="0.1"><span class="pa-runrange-speed-fast" title="Fast">🐇</span></div>').prependTo(therun);
            etime = erange[0].lastChild;
            ebutton = erange[0].firstChild;
            erange = ebutton.nextSibling;
            etime = erange.nextSibling;
            espeed = etime.nextSibling.nextSibling;
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
                etime.innerHTML = sprintf("%d:%02d.%03d", Math.trunc(tlast / 60000), Math.trunc(tlast / 1000) % 60, Math.trunc(tlast) % 1000);
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

        function f(time) {
            if (time === null) {
                if (running) {
                    time = ((new Date).getTime() - tstart) * factor;
                } else {
                    return;
                }
            }

            // find `npos`: the new time position
            let npos = tpos;
            if (npos >= times.length || time < times[npos]) {
                npos = 0;
            }
            if (npos + 2 < times.length && time >= times[npos]) {
                let rpos = times.length;
                while (npos < rpos) {
                    const m = npos + (((rpos - npos) >> 1) & ~1);
                    if (time <= times[m]) {
                        rpos = m;
                    } else {
                        npos = m + 2;
                    }
                }
            }
            while (npos < times.length && time >= times[npos]) {
                npos += 2;
            }

            // find `tpos`, the first time position, and `boffset`, data position
            if (npos < tpos) {
                ibuffer = "";
                tpos = 0;
            }
            let boffset = tpos < times.length ? times[tpos + 1] : data.size;

            // flow control: give xterm.js 8MB of data at a time
            const maxdata = 8 << 20;
            let eoffset = npos < times.length ? times[npos + 1] : data.size;
            if (boffset + maxdata < eoffset) {
                let lpos = tpos;
                while (lpos < npos) {
                    const m = lpos + (((npos - lpos) >> 1) & ~1);
                    if (boffset + maxdata < times[m + 1]) {
                        npos = m;
                        eoffset = times[m + 1];
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
            } catch (e) {
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
        if (queueid) {
            thepre.find("span.pa-runqueue").remove();
        }
        if (data && data.onqueue) {
            queueid = data.queueid;
            let t = "On queue, ".concat(data.nahead, (data.nahead == 1 ? " job" : " jobs"), " ahead");
            if (data.headage) {
                let headage = data.headage;
                if (headage > 10) {
                    headage = Math.round(headage / 5 + 0.5) * 5;
                }
                t = t.concat(", oldest began about ", headage, (headage == 1 ? " second" : " seconds"), " ago");
            }
            const span = document.createElement("span");
            span.className = "pa-runqueue";
            span.append(t);
            thepre[0].insertBefore(span, thepre[0].lastChild);
            send_after(10000);
            return;
        }

        stop_button(data && (data.status == null || data.status === "working") && !data.done);

        if (!data || !data.ok) {
            let x = "Unknown error";
            if (data && data.loggedout) {
                x = "You have been logged out (perhaps due to inactivity). Please reload this page.";
            } else if (data) {
                if (data.error_text) {
                    x = data.error_text;
                } else if (data.error && data.error !== true) {
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
            return done();
        } else if (data.done) {
            done(false);
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
            && !data.partial
            && data.done
            && (m = data.data.match(/\x1b\[\?1049l(?:[\r\n]|\x1b\[\?1l|\x1b>)*$/))) {
            data.data = data.data.substring(0, data.data.length - m[0].length);
        }

        // Pure replay -> append_timed
        if (data.done
            && data.time_data != null
            && ibuffer === "") {
            // Parse timing data
            append_timed(data);
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
            if (!data.done || data.partial) {
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
        } else if (!data.done || data.partial) {
            send_after(backoff);
        } else {
            done();
            if (data.timed && !hasClass(therun.firstChild, "pa-runrange")) {
                send({offset: 0}, succeed_add_times);
            }
        }
    }

    function succeed_add_times(data) {
        --send_out;
        if (data.data && data.done && data.time_data != null) {
            append_timed(data, true);
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
                removeClass(form, "pa-run-active");
            }
        });
    }

    function stop() {
        send({stop: 1});
    }

    function write(value) {
        send({write: value});
    }

    if (opts.headline && opts.noclear && !thexterm && thepre[0].firstChild) {
        append("\n\n");
    }
    if (opts.headline && opts.headline instanceof Node) {
        append_html(opts.headline);
    } else if (opts.headline) {
        append("\x1b[1;37m" + opts.headline + "\x1b[m\n");
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
        fold61(therun, jQuery("#run-" + name));
    }
});
