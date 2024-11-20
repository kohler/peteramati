// gradegraph.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import * as svgutil from "./svgpathutil.js";
import IntervalSeq from "./intervalseq.js";
import { sprintf } from "./utils.js";
import { hasClass, addClass } from "./ui.js";

function mksvg(tag) {
    return document.createElementNS("http://www.w3.org/2000/svg", tag);
}

export class GradeGraph {
    constructor(parent, d, plot_type) {
        const $parent = $(parent);

        let mainplot = "all";
        if (plot_type.indexOf("extension") >= 0) {
            mainplot = "extension";
        } else if (plot_type.indexOf("noextra") >= 0) {
            mainplot = "noextra";
        }
        const dd = d.series[mainplot] || d.series.all,
            ddmin = dd.min();
        let xmin = ddmin < 0 ? ddmin - 1 : 0;
        if (d.entry && d.entry.type === "letter") {
            xmin = Math.min(65, ddmin < 0 ? ddmin : Math.max(ddmin - 5, 0));
        }
        this.min = xmin;
        this.max = dd.max();
        if (d.maxtotal) {
            this.max = Math.max(this.max, d.maxtotal);
        }
        if (this.min == this.max) {
            xmin = this.min = this.min - 1;
            this.max += 1;
        }
        this.total = d.maxtotal;
        this.cutoff = dd.cutoff;

        this.svg = mksvg("svg");
        this.gg = mksvg("g");
        this.gx = mksvg("g");
        this.gy = mksvg("g");
        this.xl = this.xt = true;
        this.yl = this.yt = plot_type.substring(0, 3) !== "pdf";
        this.tw = $parent.width();
        this.th = $parent.height();
        this.svg.setAttribute("preserveAspectRatio", "none");
        this.svg.setAttribute("width", this.tw);
        this.svg.setAttribute("height", this.th);
        this.svg.setAttribute("overflow", "visible");
        this.svg.appendChild(this.gg);
        this.gx.setAttribute("class", "pa-gg-axis pa-gg-xaxis");
        this.svg.appendChild(this.gx);
        this.gy.setAttribute("class", "pa-gg-axis pa-gg-yaxis");
        this.svg.appendChild(this.gy);
        this.maxp = 0;
        this.hoveranno = null;
        this.hoveron = false;
        $parent.html(this.svg);

        const digits = mksvg("text");
        digits.appendChild(document.createTextNode("888"));
        this.gx.appendChild(digits);
        var domr = digits.getBBox();
        this.xdw = domr.width / 3;
        this.xdh = domr.height;
        this.gx.removeChild(digits);

        this.xlw = this.xdw * (this.max > 0 ? Math.floor(Math.log10(this.max)) + 1 : 1);

        this.mt = Math.ceil(Math.max(this.yl ? this.xdh / 2 : 0, 2));
        this.mr = Math.ceil(this.xl ? this.xlw / 2 : 0);
        this.mb = (this.xt ? 5 : 0) + (this.xl ? this.xdh + 3 : 0);
        if (this.yl) {
            const h = this.th - this.mt - Math.max(this.mb, Math.ceil(this.xdh / 2));
            if (h > this.xdh) {
                var minyaxis = $parent.hasClass("pa-grgraph-min-yaxis");
                if (minyaxis) {
                    this.yfmt = "%.0r%%";
                } else {
                    this.yfmt = "%.0r";
                }
                const labelcap = h / this.xdh;
                this.ymax = 100;
                if (labelcap > 15) {
                    this.ylu = 10;
                } else if (labelcap > 5) {
                    this.ylu = 25;
                } else if (labelcap > 3) {
                    this.ylu = 50;
                } else {
                    this.ylu = 100;
                }
                this.ml = (this.yt ? 5 : 0) + 5 + (minyaxis ? 4.2 : 3) * this.xdw;

                if (!$parent.hasClass("pa-grgraph-min-yaxis")) {
                    this.yltext = mksvg("text");
                    this.yltext.appendChild(document.createTextNode("% of grades"));
                    this.gy.appendChild(this.yltext);
                    domr = this.yltext.getBBox();
                    if (domr.width <= 0.875 * h) {
                        this.ml += this.xdw * 0.5 + this.xdh;
                    } else {
                        this.gy.removeText(this.yltext);
                        this.yltext = null;
                    }
                }

                this.mb = Math.max(this.mb, Math.ceil(this.xdh / 2));
            } else {
                this.yl = false;
                this.ml = 0;
                this.mt = 2;
            }
        } else {
            this.ml = this.yt ? 5 : 0;
        }
        if (this.xl) {
            this.ml = Math.max(this.ml, Math.ceil(this.xdw / 2));
        }

        this.gw = this.tw - this.ml - this.mr;
        var gh = this.gh = this.th - this.mt - this.mb;
        const xfactor = this.gw / Math.max(this.max - this.min, 0.001);
        this.xax = function (x) {
            return (x - xmin) * xfactor;
        };
        this.yax = function (y) {
            return gh - y * gh;
        };
        this.unxax = function (ax) {
            return (ax / xfactor) + xmin;
        };
        this.unyax = function (ay) {
            return -(ay - gh) / gh;
        };
        if (d.entry && d.entry.type) {
            const gt = window.pa_grade_types[d.entry.type];
            if (gt && gt.tics)
                this.xtics = gt.tics.call(gt);
        }
        if (this.max - this.min > 900) {
            this.xfmt = "%.0r";
        } else if (this.max - this.min > 10) {
            this.xfmt = "%.1r";
        } else {
            this.xfmt = "%.3r";
        }

        this.gg.setAttribute("transform", `translate(${this.ml},${this.mt})`);
        this.gx.setAttribute("transform", `translate(${this.ml},${this.mt + this.gh + (this.xt ? 2 : -5)})`);
        this.gy.setAttribute("transform", `translate(${this.ml + (this.yt ? -2 : 5)},${this.mt})`);

        this.hlcurve = null;
        this.hlcurve_series = null;
        this.hlcurve_priority = null;
        this.hlcurve_ucdf = null;
    }

    #numeric_xaxis() {
        // determine number
        const ndigit_max = Math.floor(Math.log10(this.max)) + 1,
            labelw = this.xdw * (ndigit_max + 0.5),
            labelcap = this.gw / labelw;

        const unitbase = Math.pow(10, Math.max(0, ndigit_max - 2)),
            nunits = (this.max - this.min) / unitbase;
        let unit;
        if (labelcap > nunits * 4 && unitbase > 1) {
            unit = unitbase / 2;
        } else if (labelcap > nunits * 2) {
            unit = unitbase;
        } else if (labelcap > nunits * (unitbase <= 1 ? 0.75 : 1)) {
            unit = 2 * unitbase;
        } else if (unitbase > 1 && labelcap > nunits * 0.6) {
            unit = 2.5 * unitbase;
        } else if (labelcap > nunits * 0.3) {
            unit = 5 * unitbase;
        } else {
            unit = 10 * unitbase;
        }

        const d = [];
        let x = Math.floor(this.min / unit) * unit, total_done = false;
        while (x < this.max + unit) {
            let xx = x, draw = this.xl;
            if (this.total) {
                if (xx > this.total
                    && xx - unit < this.total
                    && !total_done) {
                    xx = this.total;
                    x -= unit;
                }
                if (xx == this.total) {
                    total_done = true;
                }
            }
            x += unit;
            if (xx < this.min) {
                continue;
            }
            if (xx > this.max) {
                xx = this.max;
            }

            const xxv = this.xax(xx);
            d.push("M", xxv, ",0v5");

            if ((this.total
                 && xx != this.total
                 && Math.abs(xxv - this.xax(this.total)) < labelw)
                || (xx != this.max
                    && xx != this.total
                    && Math.abs(xxv - this.xax(this.max)) < labelw)) {
                draw = false;
            }

            if (draw) {
                const e = mksvg("text");
                e.appendChild(document.createTextNode(xx));
                e.setAttribute("x", xxv);
                e.setAttribute("y", this.xdh + 3);
                this.gx.appendChild(e);
            }
        }

        if (this.xt) {
            const e = mksvg("path");
            e.setAttribute("d", d.join(""));
            e.setAttribute("fill", "none");
            e.setAttribute("stroke", "black");
            this.gx.appendChild(e);
        }
    }

    #xtics_xaxis() {
        // determine number
        let label_restrictions = new IntervalSeq,
            tic_restrictions = new IntervalSeq,
            d = [];

        for (let i = 0; i !== this.xtics.length; ++i) {
            let xt = this.xtics[i];
            if (xt.x < this.min || xt.x > this.max) {
                continue;
            }
            let xxv = this.xax(xt.x);
            if (xt.notic || !tic_restrictions.contains(xxv)) {
                if (!xt.notic) {
                    d.push("M", xxv, ",0v5");
                    tic_restrictions.add(xxv - 3, xxv + 3);
                }

                if (this.xl && xt.text) {
                    let lw = this.xdw * (xt.label_space || xt.text.length + 0.5) * 0.5;
                    if (!label_restrictions.overlaps(xxv - lw, xxv + lw)) {
                        const e = mksvg("text");
                        e.appendChild(document.createTextNode(xt.text));
                        e.setAttribute("x", xxv);
                        e.setAttribute("y", this.xdh + 3);
                        this.gx.appendChild(e);
                        lw = this.xdw * (xt.text.length + 0.5) * 0.5;
                        label_restrictions.add(xxv - lw, xxv + lw);
                    }
                }
            }
        }

        if (this.xt) {
            const e = mksvg("path");
            e.setAttribute("d", d.join(""));
            e.setAttribute("fill", "none");
            e.setAttribute("stroke", "black");
            this.gx.appendChild(e);
        }
    }

    xaxis() {
        if (this.xtics) {
            this.#xtics_xaxis();
        } else {
            this.#numeric_xaxis();
        }
    }

    yaxis() {
        const d = [];
        let y = 0;
        while (y <= this.ymax && this.yl) {
            const e = mksvg("text");
            e.appendChild(document.createTextNode(sprintf(this.yfmt, y)));
            e.setAttribute("x", -8);
            e.setAttribute("y", this.yax(y / this.ymax) + 0.25 * this.xdh);
            this.gy.appendChild(e);

            d.push("M-5,", this.yax(y / this.ymax), "h5");

            y += this.ylu;
        }

        if (this.yt) {
            const e = mksvg("path");
            e.setAttribute("d", d.join(""));
            e.setAttribute("fill", "none");
            e.setAttribute("stroke", "black");
            this.gy.appendChild(e);
        }

        if (this.yltext) {
            this.yltext.setAttribute("transform", "translate(" + (-this.ml + this.xdh) + "," + this.yax(0.5) + ") rotate(-90)");
            this.yltext.setAttribute("text-anchor", "middle");
        }
    }

    container() {
        return this.svg.closest(".pa-grgraph");
    }

    #add_path_curve(d, klass, series, prio) {
        const path = mksvg("path");
        path.setAttribute("d", d);
        path.setAttribute("fill", "none");
        path.setAttribute("class", klass);
        this.gg.appendChild(path);
        if (prio == null) {
            prio = 0;
        }
        if (this.hlcurve_priority == null || this.hlcurve_priority <= prio) {
            this.hlcurve = path;
            this.hlcurve_series = series;
            this.hlcurve_priority = prio;
            this.hlcurve_ucdf = null;
        }
        return path;
    }

    append_cdf(d, klass, prio) {
        const cdf = d.cdf, data = [], nr = 1 / d.n,
              cutoff = this.cutoff || 0, xmin = this.min;
        let i = 0;
        if (cutoff && cdf.length > 0 && cdf[0] > xmin) {
            const path = mksvg("path"),
                x = Math.max(xmin, cdf[0] - 0.5);
            path.setAttribute("d", `M${this.xax(xmin)},${this.yax(0)}L${this.xax(x)},${this.yax(cutoff)}`);
            path.setAttribute("fill", "none");
            path.setAttribute("class", klass + " pa-gg-cutoff");
            this.gg.appendChild(path);
        }
        for (; i < cdf.length; i += 2) {
            if (data.length !== 0) {
                const x = Math.max(xmin, cdf[i] - Math.min(1, cdf[i] - cdf[i-2]) / 2);
                data.push(`H${this.xax(x)}`);
            } else {
                data.push(`M${this.xax(Math.max(xmin, cdf[i] - 0.5))},${this.yax(cutoff)}`);
            }
            data.push(`V${this.yax(cdf[i+1] * nr)}`);
        }
        if (data.length !== 0) {
            data.push(`H${this.xax(Math.max(this.max, cdf[cdf.length-2]))}`);
        }
        return this.#add_path_curve(data.join(""), klass, d, prio);
    }

    append_pdf(kde, klass, prio) {
        if (kde.maxp === 0) {
            return null;
        }
        const data = [], bins = kde.kde, nrdy = 0.9 / this.maxp,
            xax = this.xax, yax = this.yax;
        let xcutoff = null;
        if (this.cutoff && kde.series.cdf.length > 0) {
            xcutoff = this.xax(kde.series.cdf[0]);
        }
        // adapted from d3-shape by Mike Bostock
        const xs = [0, 0, 0, 0], ys = [0, 0, 0, 0],
            la = [0, 0, 0, 0], la2 = [0, 0, 0, 0],
            epsilon = 1e-6;
        function point(i2) {
            const i0 = (i2 + 2) % 4, i1 = (i2 + 3) % 4, i3 = (i2 + 1) % 4;
            let x1 = xs[i1], y1 = ys[i1], x2 = xs[i2], y2 = ys[i2];
            if (la[i1] > epsilon) {
                let a = 2 * la2[i1] + 3 * la[i1] * la[i2] + la2[i2],
                    n = 3 * la[i1] * (la[i1] + la[i2]);
                x1 = (x1 * a - xs[i0] * la2[i2] + xs[i2] * la2[i1]) / n;
                y1 = (y1 * a - ys[i0] * la2[i2] + ys[i2] * la2[i1]) / n;
            }
            if (la[i3] > epsilon) {
                let b = 2 * la2[i3] + 3 * la[i3] * la[i2] + la2[i2],
                    m = 3 * la[i3] * (la[i3] + la[i2]);
                x2 = (x2 * b - xs[i3] * la2[i2] + xs[i1] * la2[i3]) / m;
                y2 = (y2 * b - ys[i3] * la2[i2] + ys[i1] * la2[i3]) / m;
            }
            data.push(`C${x1},${y1} ${x2},${y2} ${xs[i2]},${ys[i2]}`);
        }
        for (let i = 0; i !== bins.length; ++i) {
            const x = xax(this.min + i * kde.binwidth),
                y = yax(bins[i] * nrdy);
            if (i === 0) {
                data.push(`M${x},${y}`);
                xs[3] = xs[0] = x;
                ys[3] = ys[0] = y;
            } else {
                const i1 = (i + 3) % 4, i2 = i % 4;
                xs[i2] = x;
                ys[i2] = y;

                const dx = xs[i1] - x, dy = ys[i1] - y;
                la2[i2] = Math.sqrt(dx * dx + dy * dy);
                la[i2] = Math.sqrt(la2[i2]);

                if (i > 1) {
                    point(i1);
                    if (xcutoff != null && xs[i1] > xcutoff) {
                        this.#add_path_curve(data.join(""), klass + " pa-gg-cutoff", kde.series, prio);
                        data.splice(0);
                        data.push(`M${xs[i1]},${ys[i1]}`);
                        xcutoff = null;
                    }
                }

                if (i === bins.length - 1) {
                    const i3 = (i + 1) % 4;
                    xs[i3] = x;
                    ys[i3] = y;
                    la2[i3] = 0;
                    la[i3] = 0;
                    point(i2);
                }
            }
        }
        return this.#add_path_curve(data.join(""), klass, kde.series, prio);
    }

    remove_if(predicate) {
        let e = this.gg.firstChild;
        while (e) {
            const next = e.nextSibling;
            if (predicate.call(e)) {
                this.gg.removeChild(e);
            }
            e = next;
        }
    }

    highlight(d, predicate, klass) {
        if (!this.hlcurve || !this.hlcurve_series.cdfu) {
            return null;
        }
        const ispdf = hasClass(this.hlcurve, "pa-gg-pdf"),
            cdf = this.hlcurve_series.cdf,
            cdfu = this.hlcurve_series.cdfu,
            data = [];
        let nr, nrgh, i, ui, xv, yv, yc;
        if (ispdf) {
            nr = 0.9 / (this.maxp * d.n);
        } else {
            nr = 1 / d.n;
        }
        nrgh = nr * this.gh;
        for (i = ui = 0; i !== cdf.length; i += 2) {
            for (yc = 0; ui !== cdf[i + 1]; ++ui) {
                if (predicate(cdfu[ui], d))
                    ++yc;
            }
            if (yc) {
                xv = this.xax(cdf[i]);
                if (ispdf) {
                    yv = svgutil.eval_function_path.call(this.hlcurve, xv);
                } else {
                    yv = this.yax(cdf[i+1] * nr);
                }
                if (yv != null) {
                    data.push(`M${xv},${yv}v${yc * nrgh}`);
                }
            }
        }
        if (!data.length) {
            return null;
        }
        const path = mksvg("path");
        path.setAttribute("d", data.join(""));
        path.setAttribute("fill", "none");
        path.setAttribute("class", klass);
        this.gg.appendChild(path);
        addClass(this.gg, "pa-gg-has-hl");
        return path;
    }

    typed_annotation(klass) {
        const dot = mksvg("circle");
        dot.setAttribute("class", "pa-gg-mark hl-" + (klass || "main"));
        dot.setAttribute("r", !klass || klass === "main" ? 5 : 3.5);
        return dot;
    }

    star_annotation(rs, start, n, klass) {
        if (start == null) {
            start = Math.PI / 2;
        }
        if (n == null) {
            n = 5;
        }
        var star = mksvg("path");
        star.setAttribute("class", klass);
        var d = ["M"], cos = Math.cos, sin = Math.sin, delta = Math.PI / n;
        for (var i = 0; i < 2 * n; ++i) {
            d.push(rs[i & 1] * cos(start), ",", rs[i & 1] * sin(start), i ? " " : "L");
            start += delta;
        }
        d.push("z");
        star.setAttribute("d", d.join(""));
        return star;
    }

    annotate(x, elt, after) {
        if (!this.hlcurve)
            return false;
        let xv = this.xax(x), yv = svgutil.eval_function_path.call(this.hlcurve, xv);
        if (yv === null && this.cutoff) {
            const yv0 = this.yax(0), yv1 = this.yax(this.cutoff),
                frac = (x - this.min) / (this.hlcurve_series.cdf[0] - this.min);
            yv = yv0 + (yv1 - yv0) * frac;
        }
        if (yv === null)
            return false;
        elt = elt || this.typed_annotation();
        elt.setAttribute("transform", `translate(${xv},${yv})`);
        this.gg.insertBefore(elt, after || null);
        return true;
    }

    user_x(uid) {
        let ucdf = this.hlcurve_ucdf;
        if (!ucdf) {
            if (!this.hlcurve_series || !this.hlcurve_series.cdfu) {
                return undefined;
            }
            const cdf = this.hlcurve_series.cdf,
                cdfu = this.hlcurve_series.cdfu;
            this.hlcurve_ucdf = ucdf = {};
            let i = 0;
            for (let ui = 0; ui !== cdfu.length; ++ui) {
                while (ui >= cdf[i + 1]) {
                    i += 2;
                }
                ucdf[cdfu[ui]] = cdf[i];
            }
        }
        return ucdf[uid];
    }

    highlight_users() {
        if (!this.hlcurve_series || !this.hlcurve_series.cdfu) {
            return;
        }

        this.last_highlight = this.last_highlight || {};
        const attrs = this.container().attributes, desired = {};
        for (let i = 0; i !== attrs.length; ++i) {
            if (attrs[i].name.startsWith("data-pa-highlight")) {
                let type;
                if (attrs[i].name === "data-pa-highlight") {
                    type = "main";
                } else {
                    type = attrs[i].name.substring(18);
                }
                desired[type] = attrs[i].value;
                this.last_highlight[type] = this.last_highlight[type] || "";
            }
        }

        for (let type in this.last_highlight) {
            var uids = desired[type] || "";
            if (this.last_highlight[type] === uids) {
                continue;
            }

            const uidm = {}, uidx = uids.split(/\s+/);
            for (const uid of uidx) {
                if (uid !== "")
                    uidm[uid] = 1;
            }

            var el = this.gg.firstChild, elnext;
            var klass = "pa-gg-mark hl-" + type;
            while (el && (!hasClass(el, "pa-gg-mark") || el.className.animVal < klass)) {
                el = el.nextSibling;
            }

            while (el && el.className.animVal === klass) {
                elnext = el.nextSibling;
                const uid = +el.getAttribute("data-pa-uid");
                if (uidm[uid]) {
                    uidm[uid] = 2;
                } else {
                    this.gg.removeChild(el);
                }
                el = elnext;
            }

            for (const uid in uidm) {
                if (uidm[uid] !== 1) {
                    continue;
                }
                const x = this.user_x(uid);
                if (x != null) {
                    const e = this.typed_annotation(type);
                    e.setAttribute("data-pa-uid", uid);
                    this.annotate(x, e, el);
                }
            }

            this.last_highlight[type] = uids;
        }
    }

    hover() {
        const that = this;
        function handle(event) {
            that.#handle_hover(this, event);
        }
        this.svg.addEventListener("mouseenter", handle, false);
        this.svg.addEventListener("mousemove", handle, false);
        this.svg.addEventListener("mouseleave", handle, false);
    }

    #handle_hover(elt, event) {
        let pt = {distance: 20}, xfmt = this.xfmt;
        if (event.type !== "mousemove") {
            this.hoveron = event.type !== "mouseleave";
        }
        if (this.hoveron) {
            const loc = svgutil.event_to_point(this.svg, event),
                paths = this.gg.querySelectorAll(".pa-gg-pdf, .pa-gg-cdf");
            loc[0] -= this.ml;
            loc[1] -= this.mt;
            for (var p of paths) {
                pt = svgutil.closest_point(p, loc, pt);
            }
            if (pt.pathNode) {
                const hlpt = closer_mark(this.gg.querySelectorAll(".pa-gg-mark.hl-main"), pt, 36)
                    || closer_mark(this.gg.querySelectorAll(".pa-gg-mark:not(.hl-main)"), pt, 25);
                if (hlpt) {
                    pt = hlpt;
                    if (xfmt === "%.0r" || xfmt === "%.1f") {
                        xfmt = "%.2r";
                    }
                }
            }
        }
        if (!pt.pathNode) {
            this.hoveranno && this.#remove_hover();
            return;
        }

        const ha = this.hoveranno || this.#create_hover();
        ha[0].setAttribute("transform", `translate(${pt[0]},${pt[1]})`);

        ha[1].setAttribute("transform", `translate(${pt[0]},0)`);
        ha[3].setAttribute("x", pt[0]);
        ha[3].firstChild.data = sprintf(xfmt, this.unxax(pt[0]));
        var bb = ha[3].getBBox();
        ha[2].setAttribute("x", pt[0] - bb.width / 2 - 2);
        ha[2].setAttribute("width", bb.width + 4);

        let ptconf, s, name;
        if (pt.pathNode.hasAttribute("data-pa-uid")
            && (ptconf = elt.closest("form").pa__ptconf)
            && (s = ptconf.uidmap[pt.pathNode.getAttribute("data-pa-uid")])
            && (name = ptconf.render_tooltip_name(s))) {
            ha[4].firstChild.data = name;
            ha[4].setAttribute("x", pt[0]);
            ha[4].setAttribute("y", pt[1]);
        } else {
            ha[4].firstChild.data = "";
        }

        if (this.yl) {
            const yv = this.unyax(pt[1]);
            if (this.cutoff && (yv < this.cutoff) !== ha[8]) {
                if ((ha[8] = yv < this.cutoff)) {
                    ha[5].setAttribute("visibility", "hidden");
                    ha[6].setAttribute("visibility", "hidden");
                    ha[7].setAttribute("visibility", "hidden");
                } else {
                    ha[5].removeAttribute("visibility");
                    ha[6].removeAttribute("visibility");
                    ha[7].removeAttribute("visibility");
                }
            }
            ha[5].setAttribute("transform", `translate(0,${pt[1]})`);
            ha[7].setAttribute("y", pt[1] + 0.25 * this.xdh);
            ha[7].firstChild.data = sprintf(this.yfmt, yv * this.ymax);
            bb = ha[7].getBBox();
            ha[6].setAttribute("x", -bb.width - 10);
            ha[6].setAttribute("y", pt[1] - (this.xdh + 2) / 2);
            ha[6].setAttribute("width", bb.width + 4);
        }
    }

    #create_hover() {
        const ha = this.hoveranno = [this.star_annotation([4, 10], null, null, "pa-gg-hover-mark")];
        this.gg.appendChild(ha[0]);

        let e = mksvg("path");
        e.setAttribute("d", "M0,0v5");
        e.setAttribute("fill", "none");
        e.setAttribute("stroke", "black");
        this.gx.appendChild(e);
        ha.push(e);

        e = mksvg("rect");
        e.setAttribute("class", "pa-gg-hover-box");
        e.setAttribute("y", 5);
        e.setAttribute("height", this.xdh + 1.5);
        e.setAttribute("rx", 3);
        this.gx.appendChild(e);
        ha.push(e);

        e = mksvg("text");
        e.appendChild(document.createTextNode(""));
        e.setAttribute("class", "pa-gg-hover-text");
        e.setAttribute("y", this.xdh + 3);
        this.gx.appendChild(e);
        ha.push(e);

        e = mksvg("text");
        e.appendChild(document.createTextNode(""));
        e.setAttribute("class", "pa-gg-anno-name");
        e.setAttribute("text-anchor", "end");
        e.setAttribute("dx", -8);
        this.gg.appendChild(e);
        ha.push(e);

        if (this.yl) {
            e = mksvg("path");
            e.setAttribute("d", "M-5,0h5");
            e.setAttribute("fill", "none");
            e.setAttribute("stroke", "black");
            this.gy.appendChild(e);
            ha.push(e);

            e = mksvg("rect");
            e.setAttribute("class", "pa-gg-hover-box");
            e.setAttribute("height", this.xdh + 1);
            e.setAttribute("rx", 3);
            this.gy.appendChild(e);
            ha.push(e);

            e = mksvg("text");
            e.appendChild(document.createTextNode(""));
            e.setAttribute("class", "pa-gg-hover-text");
            e.setAttribute("x", -8);
            this.gy.appendChild(e);
            ha.push(e);

            if (this.cutoff) {
                ha.push(false);
            }
        }

        return ha;
    }

    #remove_hover() {
        const ha = this.hoveranno;
        this.gg.removeChild(ha[0]);
        this.gx.removeChild(ha[1]);
        this.gx.removeChild(ha[2]);
        this.gx.removeChild(ha[3]);
        this.gg.removeChild(ha[4]);
        if (this.yl) {
            this.gy.removeChild(ha[5]);
            this.gy.removeChild(ha[6]);
            this.gy.removeChild(ha[7]);
        }
        this.hoveranno = null;
    }
}


function closer_mark(hlpaths, pt, bestDistance2) {
    let hlpt = null;
    for (const hlp of hlpaths) {
        const m = hlp.getAttribute("transform").match(/^translate\(([-+\d.]+),([-+\d.]+)\)$/);
        if (m) {
            const dx = +m[1] - pt[0], dy = +m[2] - pt[1],
                distance2 = dx * dx + dy * dy;
            if (distance2 < bestDistance2) {
                hlpt = [+m[1], +m[2]];
                hlpt.pathNode = hlp;
                bestDistance2 = distance2;
            }
        }
    }
    hlpt && (hlpt.distance = Math.sqrt(bestDistance2));
    return hlpt;
}
