// intervalseq.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

export default class IntervalSeq {
    constructor() {
        this.is = [];
    }
    lower(x) {
        var is = this.is, l = 0, r = is.length;
        while (l < r) {
            var m = l + ((r - l) >> 2) * 2;
            if (is[m] > x)
                r = m;
            else if (x > is[m + 1])
                l = m + 2;
            else /* is[m] <= x <= is[m + 1] */
                return m;
        }
        return l;
    }
    contains(x) {
        var i = this.lower(x);
        return i < this.is.length && x >= this.is[i];
    }
    overlaps(lo, hi) {
        var i = this.lower(lo);
        return i < this.is.length && hi >= this.is[i];
    }
    add(lo, hi) {
        var is = this.is, i = this.lower(lo);
        if (i >= is.length || lo < is[i])
            is.splice(i, 0, lo, lo);
        var j = i;
        while (j + 2 < is.length && hi >= is[j + 2])
            j += 2;
        if (j !== i)
            is.splice(i + 1, j - i);
        is[i + 1] = Math.max(is[i + 1], hi);
    }
    clear() {
        this.is = [];
    }
}
