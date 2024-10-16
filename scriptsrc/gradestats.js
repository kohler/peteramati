// gradestats.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class GradeSeries {
    constructor(d) {
        this.n = d.n;
        this.cdf = d.cdf;
        this.cdfu = d.cdfu || null;
        this.mean = d.mean || null;
        this.median = d.median || null;
        this.stddev = d.stddev || null;
        this.cutoff = d.cutoff || null;
    }
    min() {
        return this.cdf.length ? this.cdf[0] : 0;
    }
    max() {
        return this.cdf.length ? this.cdf[this.cdf.length - 2] : 0;
    }
    count_at(x) {
        const cdf = this.cdf;
        let l = 0, r = cdf.length;
        while (l < r) {
            const m = l + ((r - l) >> 2) * 2;
            if (cdf[m] >= x) {
                r = m;
            } else {
                l = m + 2;
            }
        }
        return cdf[l+1];
    }
}

export class GradeKde {
    constructor(series, gi, hfrac, nbins) {
        const maxg = gi.max, ming = gi.min,
            H = (maxg - ming) * hfrac, iH = 1 / H;
        function epanechnikov(x) {
            if (x >= -H && x <= H) {
                x *= iH;
                return 0.75 * iH * (1 - x * x);
            } else {
                return 0;
            }
        }
        const bins = [];
        for (let i = 0; i !== nbins + 1; ++i) {
            bins.push(0);
        }
        const cdf = series.cdf, dx = (maxg - ming) / nbins, idx = 1 / dx;
        for (let i = 0; i < cdf.length; i += 2) {
            const y = cdf[i+1] - (i === 0 ? 0 : cdf[i-1]);
            let x1 = Math.floor((cdf[i] - ming - H) * idx);
            const x2 = Math.ceil((cdf[i] - ming + H) * idx);
            while (x1 < x2) {
                const x = Math.max(-1, Math.min(nbins + 1, x1));
                if (x >= 0 && x <= nbins) {
                    bins[x] += epanechnikov(x1 * dx - cdf[i] + ming) * y;
                }
                ++x1;
            }
        }
        let maxp = 0;
        if (series.n) {
            const nr = 1 / series.n;
            for (let i = 0; i !== nbins + 1; ++i) {
                bins[i] *= nr;
                maxp = Math.max(maxp, bins[i]);
            }
        }
        this.series = series;
        this.kde = bins;
        this.maxp = maxp;
        this.binwidth = dx;
    }
}

// pset psetid all extension noextra extension_noextra maxtotal entry
export class GradeStats {
    constructor(d) {
        this.pset = d.pset;
        this.psetid = d.psetid;
        this.maxtotal = d.maxtotal || null;
        this.entry = d.entry || null;
        this.series = {};
        for (let i in d.series) {
            this.series[i] = new GradeSeries(d.series[i]);
        }
    }
}
