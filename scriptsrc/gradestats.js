// gradestats.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class GradeSeries {
    n;
    cdf;
    cdfu;
    mean;
    median;
    stddev;
    cutoff;

    constructor(d) {
        if (d) {
            this.n = d.n;
            this.cdf = d.cdf;
            this.cdfu = d.cdfu || null;
            this.mean = d.mean || null;
            this.median = d.median || null;
            this.stddev = d.stddev || null;
            this.cutoff = d.cutoff || null;
        }
    }

    static from_sorted_array(a) {
        const cdf = [];
        let ci = -2, n = 0;
        for (const x of a) {
            ++n;
            if (ci < 0 || cdf[ci] !== x) {
                cdf.push(x, n);
                ci += 2;
            } else {
                cdf[ci + 1] = n;
            }
        }
        return new GradeSeries({n: n, cdf: cdf});
    }

    check() {
        if (!this.cdf) {
            this.#fail_check();
        }
        if (this.n === 0 ? this.cdf.length > 0 : this.cdf[this.cdf.length - 1] !== this.n) {
            this.#fail_check();
        }
        if (this.cdfu && this.cdfu.length !== this.n) {
            this.#fail_check();
        }
        for (let ci = 0; ci !== this.cdf.length; ci += 2) {
            if (this.cdf[ci + 1] <= 0
                || (ci > 0 && this.cdf[ci + 1] <= this.cdf[ci - 1])
                || (ci > 0 && this.cdf[ci] <= this.cdf[ci - 2])) {
                this.#fail_check();
            }
        }
    }

    #fail_check() {
        throw new Error;
    }

    min() {
        return this.n > 0 ? this.cdf[0] : 0;
    }

    max() {
        return this.n > 0 ? this.cdf[this.cdf.length - 2] : 0;
    }

    #x_lower_bound(x) {
        const cdf = this.cdf;
        let l = 0, r = cdf.length;
        while (l + 2 < r) {
            const m = l + (((r - l) >> 1) & ~1);
            if (cdf[m] > x) {
                r = m;
            } else {
                l = m;
            }
        }
        return l;
    }

    #y_lower_bound(y) {
        const cdf = this.cdf;
        let l = 0, r = cdf.length;
        while (l + 2 < r) {
            const m = l + (((r - l) >> 1) & ~1);
            if (m > 0 && cdf[m - 1] <= y) {
                l = m;
            } else {
                r = m;
            }
        }
        return l;
    }

    count_at(x) {
        const ci = this.#x_lower_bound(x);
        if (this.n === 0 || x < this.cdf[ci]) {
            return 0;
        }
        return this.cdf[ci + 1];
    }

    quantile(p) {
        if (this.n === 0) {
            return 0;
        }
        const pos = p * (this.n - 1),
            poslb = pos | 0,
            ci = this.#y_lower_bound(poslb);
        if (pos === poslb
            || poslb < 0
            || poslb > this.n - 1
            || poslb + 1 < this.cdf[ci + 1]) {
            return this.cdf[ci];
        }
        return this.cdf[ci] + (pos - poslb) * (this.cdf[ci + 2] - this.cdf[ci]);
    }

    #slice(n) {
        const r = new GradeSeries;
        r.n = n;
        if (this.cdfu) {
            r.cdfu = this.cdfu.slice(0, n);
        }
        const ci = n > 0 && this.n > 0 ? this.#y_lower_bound(n - 1) + 2 : 0;
        r.cdf = this.cdf.slice(0, ci);
        if (ci > 0 && r.n < r.cdf[ci - 1]) {
            r.cdf[ci - 1] = r.n;
        }
        return r;
    }

    #assign_statistics() {
        if (this.n === 0) {
            this.mean = this.median = this.stddev = null;
            return;
        }
        let ci = 0, cx = this.cdf[0], cy = this.cdf[1],
            sum = 0, sumsq = 0;
        const n = this.n;
        for (let ui = 0; ui !== n; ++ui) {
            while (ui === cy) {
                ci += 2;
                cx = this.cdf[ci];
                cy = this.cdf[ci + 1];
            }
            sum += cx;
            sumsq += cx * cx;
        }
        this.mean = sum / n;
        this.median = this.quantile(0.5);
        if (n > 1) {
            this.stddev = Math.sqrt((sumsq - sum * sum / n) / (n - 1));
        } else {
            this.stddev = 0;
        }
    }

    filter(predicate) {
        let r = null, n = this.n, ci = 0, rci = 0;
        for (let ui = 0; ui !== n; ++ui) {
            while (this.cdf[ci + 1] === ui) {
                ci += 2;
            }
            const x = this.cdf[ci], u = this.cdfu ? this.cdfu[ui] : null;
            if (!predicate(x, u)) {
                if (!r) {
                    r = this.#slice(ui);
                    rci = r.cdf.length;
                }
                continue;
            } else if (!r) {
                continue;
            }
            ++r.n;
            if (this.cdfu) {
                r.cdfu.push(u);
            }
            if (rci === 0 || x !== r.cdf[rci - 2]) {
                r.cdf.push(x, r.n);
                rci += 2;
            }
            r.cdf[rci - 1] = r.n;
        }
        if (!r) {
            return this;
        }
        r.#assign_statistics();
        return r;
    }
}

export class GradeKde {
    series;
    kde;
    maxp;
    binwidth;

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
        let cdf = series.cdf;
        if (series.cutoff && cdf.length > 0) {
            const dy = Math.floor(cdf[cdf.length - 1] * series.cutoff);
            if (dy > 0) {
                const cdf0 = [0, 0], xd = (cdf[0] - ming) / dy;
                for (let x = ming, y = 0, ci = 0; y !== dy; x += xd, ++y) {
                    const xt = Math.floor(x);
                    if (cdf0[ci] === xt) {
                        cdf0[ci + 1] = y;
                    } else {
                        cdf0.push(xt, y);
                        ci += 2;
                    }
                }
                cdf0.push(...cdf);
                cdf = cdf0;
            }
        }
        const dx = (maxg - ming) / nbins, idx = 1 / dx;
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
