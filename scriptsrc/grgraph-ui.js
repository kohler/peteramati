// grgraph-ui.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hasClass, addClass, removeClass, handle_ui } from "./ui.js";
import { hoturl_post } from "./hoturl.js";
import { wstorage } from "./utils.js";
import { GradeGraph } from "./grgraph.js";
import { GradeKde, GradeStats } from "./gradestats.js";


function mksvg(tag) {
    return document.createElementNS("http://www.w3.org/2000/svg", tag);
}

function draw_grgraph() {
    const self = this, d = $(self).data("paGradeData");
    if (!d) {
        addClass(self, "hidden");
        $(self).removeData("paGradeGraph").off("redrawgraph");
        return;
    }

    const pi = self.closest(".pa-psetinfo"),
        user_extension = !pi || pi.hasAttribute("data-pa-user-extension");

    // compute plot types
    const plot_types = [];
    if (d.series.extension && pi && user_extension) {
        plot_types.push("cdf-extension", "pdf-extension");
    }
    plot_types.push("cdf", "pdf");
    if (d.series.extension && !pi) {
        plot_types.push("cdf-extension", "pdf-extension");
    }
    if (d.series.noextra) {
        plot_types.push("cdf-noextra", "pdf-noextra");
    }
    plot_types.push("all");
    self.setAttribute("data-pa-gg-types", plot_types.join(" "));

    // compute this plot type
    let plot_type = self.getAttribute("data-pa-gg-type");
    if (!plot_type) {
        plot_type = wstorage(true, "pa-gg-type");
    }
    if (!plot_type) {
        let plotarg = wstorage(false, "pa-gg-type");
        if (plotarg && plotarg[0] === "{") {
            try {
                plotarg = JSON.parse(plotarg);
                // remember previous plot choice for up to two hours
                if (typeof plotarg.type === "string"
                    && typeof plotarg.at === "number"
                    && plotarg.at >= new Date().getTime() - 7200000) {
                    plot_type = plotarg.type;
                }
            } catch (e) {
            }
        }
    }
    if (!plot_type || plot_type === "default") {
        plot_type = plot_types[0];
    }
    if (plot_types.indexOf(plot_type) < 0) {
        if (plot_type.substring(0, 3) === "pdf") {
            plot_type = plot_types[1];
        } else {
            plot_type = plot_types[0];
        }
    }
    self.setAttribute("data-pa-gg-type", plot_type);
    $(self).removeClass("cdf pdf all cdf-extension pdf-extension all-extension cdf-noextra pdf-noextra all-noextra hidden").addClass(plot_type);

    const want_all = plot_type.substring(0, 3) === "all",
        want_extension = plot_type.indexOf("-extension") >= 0
            || (want_all && user_extension && d.extension),
        want_noextra = plot_type.indexOf("-noextra") >= 0
            || (want_all && d.series.noextra && !want_extension);

    let $plot = $(self).find(".plot");
    if (!$plot.length) {
        $plot = $(self);
    }

    const grgr = new GradeGraph($plot[0], d, plot_type);
    $(self).data("paGradeGraph", grgr);

    if (grgr.total && grgr.total < grgr.max) {
        let total = mksvg("line");
        total.setAttribute("x1", grgr.xax(grgr.total));
        total.setAttribute("y1", grgr.yax(0));
        total.setAttribute("x2", grgr.xax(grgr.total));
        total.setAttribute("y2", grgr.yax(1));
        total.setAttribute("class", "pa-gg-anno-total");
        grgr.gg.appendChild(total);
    }

    // series
    const kde_nbins = Math.ceil((grgr.max - grgr.min) / 2), kde_hfactor = 0.08, kdes = {};
    if (plot_type === "pdf-extension") {
        kdes.extension = new GradeKde(d.series.extension, grgr, kde_hfactor, kde_nbins);
    } else if (plot_type === "pdf-noextra") {
        kdes.noextra = new GradeKde(d.series.noextra, grgr, kde_hfactor, kde_nbins);
    } else if (plot_type === "pdf") {
        kdes.main = new GradeKde(d.series.all, grgr, kde_hfactor, kde_nbins);
    }
    for (let i in kdes) {
        grgr.maxp = Math.max(grgr.maxp, kdes[i].maxp);
    }

    if (plot_type === "pdf-noextra") {
        grgr.append_pdf(kdes.noextra, "pa-gg-pdf pa-gg-noextra");
    } else if (plot_type === "pdf") {
        grgr.append_pdf(kdes.main, "pa-gg-pdf");
    } else if (plot_type === "pdf-extension") {
        grgr.append_pdf(kdes.extension, "pa-gg-pdf pa-gg-extension");
    } else if (plot_type === "cdf-noextra"
               || (plot_type === "all" && d.series.noextra)) {
        grgr.append_cdf(d.series.noextra, "pa-gg-cdf pa-gg-noextra");
    }
    if (plot_type === "cdf"
        || plot_type === "all") {
        grgr.append_cdf(d.series.all, "pa-gg-cdf");
    }
    if (plot_type === "cdf-extension"
        || (plot_type === "all" && d.series.extension && user_extension)) {
        grgr.append_cdf(d.series.extension, "pa-gg-cdf pa-gg-extension");
    }

    // cutoff
    if (d.cutoff && plot_type.substring(0, 3) !== "pdf") {
        const cutoff = mksvg("rect");
        cutoff.setAttribute("x", grgr.xax(0));
        cutoff.setAttribute("y", grgr.yax(d.cutoff));
        cutoff.setAttribute("width", grgr.xax(grgr.max));
        cutoff.setAttribute("height", grgr.yax(0) - grgr.yax(d.cutoff));
        cutoff.setAttribute("fill", "rgba(255,0,0,0.1)");
        grgr.gg.appendChild(cutoff);
    }

    // load user grade
    const sheet = $(pi).data("pa-gradeinfo"),
        total = sheet ? sheet.get_total(want_noextra && !want_all) : null;
    if (total != null) {
        grgr.annotate_last_curve(total);
    }

    // axes
    grgr.xaxis();
    grgr.yaxis();

    if (self.hasAttribute("data-pa-highlight")) {
        grgr.highlight_users();
    }

    grgr.hover();

    // summary
    $(self).find(".statistics").each(function () {
        const dd = grgr.last_curve_series, stt = [];
        if (dd && dd.mean) {
            stt.push("mean " + dd.mean.toFixed(1));
        }
        if (dd && dd.median) {
            stt.push("median " + dd.median.toFixed(1));
        }
        if (dd && dd.stddev) {
            stt.push("stddev " + dd.stddev.toFixed(1));
        }
        const t = [stt.join(", ")];
        if (dd && total != null) {
            const y = dd.count_at(total);
            if (dd.cutoff && y < dd.cutoff * dd.n) {
                t.push("≤" + Math.round(dd.cutoff * 100) + " %ile");
            } else {
                t.push(Math.round(Math.min(Math.max(1, y * 100 / dd.n), 99)) + " %ile");
            }
        }
        if (t.length) {
            removeClass(this, "hidden");
            this.innerHTML = t.join(" · ");
        } else {
            addClass(this, "hidden");
            this.innerHTML = "";
        }
    });

    $(self).find(".pa-grgraph-type").each(function () {
        const title = [];
        if (plot_type.startsWith("cdf")) {
            title.push("CDF");
        } else if (plot_type.startsWith("pdf")) {
            title.push("PDF");
        }
        if (want_extension && !want_all) {
            title.push("extension");
        }
        if (want_noextra && !want_all) {
            title.push("no extra credit");
        }
        const t = title.length ? " (" + title.join(", ") + ")" : "";
        this.innerHTML = "grade statistics" + t;
    });

    grgr.highlight_users();
    $(self).off("redrawgraph").on("redrawgraph", draw_grgraph);
}

export function grgraph() {
    const self = this, p = self.getAttribute("data-pa-pset");
    $.ajax(hoturl_post("api/gradestatistics", p ? {pset: p} : {}), {
        type: "GET", cache: true, dataType: "json",
        success: function (d) {
            if (d.series && d.series.all) {
                $(self).data("paGradeData", new GradeStats(d));
                draw_grgraph.call(self);
            }
        }
    });
}

handle_ui.on("js-grgraph-flip", function () {
    const elt = this.closest(".pa-grgraph"),
        plot_types = (elt.getAttribute("data-pa-gg-types") || "").split(/ /),
        plot_type = elt.getAttribute("data-pa-gg-type");
    let i = plot_types.indexOf(plot_type);
    if (i >= 0) {
        i = (i + (hasClass(this, "prev") ? plot_types.length - 1 : 1)) % plot_types.length;
        elt.setAttribute("data-pa-gg-type", plot_types[i]);
        wstorage(true, "pa-gg-type", plot_types[i]);
        wstorage(false, "pa-gg-type", {type: plot_types[i], at: new Date().getTime()});
        draw_grgraph.call(elt);
    }
});
