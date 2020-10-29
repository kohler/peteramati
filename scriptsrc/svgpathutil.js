// svgpathutil.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

export function eval_function_path(x) {
    var l = 0, r = this.getTotalLength(), m, pt;
    if (l != r) {
        while (r - l > 0.5) {
            m = l + (r - l) / 2;
            pt = this.getPointAtLength(m);
            if (pt.x >= x + 0.25)
                r = m;
            else if (pt.x >= x - 0.25)
                return pt.y;
            else
                l = m;
        }
        pt = this.getPointAtLength(r === m ? l : r);
        if (pt.x >= x - 0.25 && pt.x <= x + 0.25)
            return pt.y;
    }
    return null;
}


const PATHSEG_ARGMAP = {
    m: 2, M: 2, z: 0, Z: 0, l: 2, L: 2, h: 1, H: 1, v: 1, V: 1, c: 6, C: 6,
    s: 4, S: 4, q: 4, Q: 4, t: 2, T: 2, a: 7, A: 7, b: 1, B: 1
};
let normalized_path_cache = {},
    normalized_path_cache_size = 0,
    normalized_path_complaint = false;

function svg_path_number_of_items(s) {
    if (s instanceof SVGPathElement)
        s = s.getAttribute("d");
    if (normalized_path_cache[s])
        return normalized_path_cache[s].length;
    else
        return s.replace(/[^A-DF-Za-df-z]+/g, "").length;
}

function make_svg_path_parser(s) {
    if (s instanceof SVGPathElement)
        s = s.getAttribute("d");
    s = s.split(/([a-zA-Z]|[-+]?(?:\d+\.?\d*|\.\d+)(?:[Ee][-+]?\d+)?)/);
    var i = 1, e = s.length, next_cmd;
    return function () {
        var a = null, m, ch;
        while (i < e) {
            ch = s[i];
            if (ch >= "A") {
                if (a)
                    break;
                a = [ch];
                next_cmd = ch;
                if (ch === "m" || ch === "M" || ch === "z" || ch === "Z")
                    next_cmd = (ch === "m" || ch === "z" ? "l" : "L");
            } else {
                if (!a && next_cmd)
                    a = [next_cmd];
                else if (!a || a.length === PATHSEG_ARGMAP[a[0]] + 1)
                    break;
                a.push(+ch);
            }
            i += 2;
        }
        return a;
    };
}

function normalize(s) {
    if (s instanceof SVGPathElement)
        s = s.getAttribute("d");
    if (normalized_path_cache[s])
        return normalized_path_cache[s];

    var res = [],
        cx = 0, cy = 0, cx0 = 0, cy0 = 0, copen = false,
        cb = 0, sincb = 0, coscb = 1,
        i, dx, dy,
        parser = make_svg_path_parser(s), a, ch, preva;
    while ((a = parser())) {
        ch = a[0];
        // special commands: bearing, closepath
        if (ch === "b" || ch === "B") {
            cb = ch === "b" ? cb + a[1] : a[1];
            coscb = Math.cos(cb);
            sincb = Math.sin(cb);
            continue;
        } else if (ch === "z" || ch === "Z") {
            preva = res.length ? res[res.length - 1] : null;
            if (copen) {
                if (cx != cx0 || cy != cy0)
                    res.push(["L", cx, cy, cx0, cy0]);
                res.push(["Z"]);
                copen = false;
            }
            cx = cx0, cy = cy0;
            continue;
        }

        // normalize command 1: remove horiz/vert
        if (PATHSEG_ARGMAP[ch] == 1) {
            if (a.length == 1)
                a = ["L"]; // all data is missing
            else if (ch === "h")
                a = ["l", a[1], 0];
            else if (ch === "H")
                a = ["L", a[1], cy];
            else if (ch === "v")
                a = ["l", 0, a[1]];
            else if (ch === "V")
                a = ["L", cx, a[1]];
        }

        // normalize command 2: relative -> absolute
        ch = a[0];
        if (ch >= "a" && !cb) {
            for (i = ch !== "a" ? 1 : 6; i < a.length; i += 2) {
                a[i] += cx;
                a[i+1] += cy;
            }
        } else if (ch >= "a") {
            if (ch === "a")
                a[3] += cb;
            for (i = ch !== "a" ? 1 : 6; i < a.length; i += 2) {
                dx = a[i], dy = a[i + 1];
                a[i] = cx + dx * coscb + dy * sincb;
                a[i+1] = cy + dx * sincb + dy * coscb;
            }
        }
        ch = a[0] = ch.toUpperCase();

        // normalize command 3: use cx0,cy0 for missing data
        while (a.length < PATHSEG_ARGMAP[ch] + 1)
            a.push(cx0, cy0);

        // normalize command 4: shortcut -> full
        if (ch === "S") {
            dx = dy = 0;
            if (preva && preva[0] === "C")
                dx = cx - preva[3], dy = cy - preva[4];
            a = ["C", cx + dx, cy + dy, a[1], a[2], a[3], a[4]];
            ch = "C";
        } else if (ch === "T") {
            dx = dy = 0;
            if (preva && preva[0] === "Q")
                dx = cx - preva[1], dy = cy - preva[2];
            a = ["Q", cx + dx, cy + dy, a[1], a[2]];
            ch = "Q";
        }

        // process command
        if (!copen && ch !== "M") {
            if (res.length !== 0 && res[res.length - 1][0] !== "Z")
                res.push(["M"]);
            copen = true;
        }
        if (ch === "M") {
            cx0 = a[1];
            cy0 = a[2];
            copen = false;
        } else if (ch === "L") {
            res.push(["L", cx, cy, a[1], a[2]]);
        } else if (ch === "C") {
            res.push(["C", cx, cy, a[1], a[2], a[3], a[4], a[5], a[6]]);
        } else if (ch === "Q") {
            res.push(["C", cx, cy,
                      cx + 2 * (a[1] - cx) / 3, cy + 2 * (a[2] - cy) / 3,
                      a[3] + 2 * (a[1] - a[3]) / 3, a[4] + 2 * (a[2] - a[4]) / 3,
                      a[3], a[4]]);
        } else {
            // XXX should render "A" as a bezier
            if (++normalized_path_complaint == 1)
                log_jserror("bad normalize " + ch);
            res.push(a);
        }

        preva = a;
        cx = a[a.length - 2];
        cy = a[a.length - 1];
    }

    if (normalized_path_cache_size >= 1000) {
        normalized_path_cache = {};
        normalized_path_cache_size = 0;
    }
    normalized_path_cache[s] = res;
    ++normalized_path_cache_size;
    return res;
}


function pathNodeMayBeNearer(pathNode, point, dist) {
    function oob(l, t, r, b) {
        return l - point[0] >= dist || point[0] - r >= dist
            || t - point[1] >= dist || point[1] - b >= dist;
    }
    // check bounding rectangle of path
    if ("clientX" in point) {
        var bounds = pathNode.getBoundingClientRect(),
            dx = point[0] - point.clientX, dy = point[1] - point.clientY;
        if (bounds && oob(bounds.left + dx, bounds.top + dy,
                          bounds.right + dx, bounds.bottom + dy)) {
            return false;
        }
    }
    // check path
    var npsl = normalize(pathNode);
    var l, t, r, b;
    for (var i = 0; i !== npsl.length; ++i) {
        var item = npsl[i];
        if (item[0] === "L") {
            l = Math.min(item[1], item[3]);
            t = Math.min(item[2], item[4]);
            r = Math.max(item[1], item[3]);
            b = Math.max(item[2], item[4]);
        } else if (item[0] === "C") {
            l = Math.min(item[1], item[3], item[5], item[7]);
            t = Math.min(item[2], item[4], item[6], item[8]);
            r = Math.max(item[1], item[3], item[5], item[7]);
            b = Math.max(item[2], item[4], item[6], item[8]);
        } else if (item[0] === "Z" || item[0] === "M") {
            continue;
        } else {
            return true;
        }
        if (!oob(l, t, r, b)) {
            return true;
        }
    }
    return false;
}

function path_x_distance2_buckets(pathNode, point) {
    var npsl = normalize(pathNode);
    function pdist(l, t, r, b) {
        var xd = point[0] < l ? l - point[0] : (point[0] > r ? point[0] - r : 0),
            yd = point[1] < t ? t - point[1] : (point[1] > b ? point[1] - b : 0);
        return Math.max(xd, yd);
    }
    var xmin = Infinity, xmax = -Infinity, width = 24, i, item;
    for (i = 0; i !== npsl.length; ++i) {
        item = npsl[i];
        if (item[0] === "L") {
            xmin = Math.min(xmin, item[1], item[3]);
            xmax = Math.max(xmax, item[1], item[3]);
        } else if (item[0] === "C") {
            xmin = Math.min(xmin, item[1], item[3], item[5], item[7]);
            xmax = Math.max(xmax, item[1], item[3], item[5], item[7]);
        }
    }
    xmin -= width;
    xmax += width;
    var n = Math.max(Math.ceil((xmax - xmin) / width), 1),
        a = new Array(n + 1),
        l, t, r, b, d, j;
    a.fill(Infinity);
    for (i = 0; i !== npsl.length; ++i) {
        item = npsl[i];
        if (item[0] === "L") {
            l = Math.min(item[1], item[3]);
            t = Math.min(item[2], item[4]);
            r = Math.max(item[1], item[3]);
            b = Math.max(item[2], item[4]);
        } else if (item[0] === "C") {
            l = Math.min(item[1], item[3], item[5], item[7]);
            t = Math.min(item[2], item[4], item[6], item[8]);
            r = Math.max(item[1], item[3], item[5], item[7]);
            b = Math.max(item[2], item[4], item[6], item[8]);
        } else {
            continue;
        }
        d = pdist(l, t, r, b);
        l = Math.floor((l - xmin) / width);
        r = Math.ceil((r - xmin) / width);
        while (l <= r) {
            a[l] = Math.min(a[l], d);
            ++l;
        }
    }
    for (i = 0; i !== a.length; ++i) {
        if (a[i] < Infinity)
            a[i] = a[i] * a[i];
    }
    a.xmin = xmin;
    a.xmax = xmax;
    a.width = width;
    a.point = point;
    return a;
}

export function closest_point(pathNode, point, inbest) {
    // originally from Mike Bostock http://bl.ocks.org/mbostock/8027637
    if (inbest && !pathNodeMayBeNearer(pathNode, point, inbest.distance)) {
        return inbest;
    }

    var pathLength = pathNode.getTotalLength(),
        pathSegments = svg_path_number_of_items(pathNode),
        precision = Math.max(pathLength / pathSegments / 8, 4),
        best, bestLength, sl,
        bestDistance2 = inbest ? (inbest.distance + 0.01) * (inbest.distance + 0.01) : Infinity;

    function check(pLength) {
        var p = pathNode.getPointAtLength(pLength),
            dx = point[0] - p.x, dy = point[1] - p.y,
            d2 = dx * dx + dy * dy;
        if (d2 < bestDistance2) {
            best = [p.x, p.y];
            bestLength = pLength;
            bestDistance2 = d2;
        }
        return p;
    }

    if (pathSegments > 20) {
        // big-step/small-step
        var xdb = path_x_distance2_buckets(pathNode, point),
            xmin = xdb.xmin, width = xdb.width, p, xx;
        for (sl = 0; sl < pathLength; ) {
            p = check(sl);
            xx = Math.floor((p.x - xmin) / width);
            if (xdb[xx] > bestDistance2
                && xdb[xx-1] > bestDistance2
                && xdb[xx+1] > bestDistance2)
                sl += width;
            else
                sl += precision;
        }
    } else {
        // linear scan for coarse approximation
        for (sl = 0; sl < pathLength; sl += precision)
            check(sl);
    }
    // edge condition: always check both ends
    check(pathLength);

    // binary search for precise estimate
    do {
        sl = bestLength - precision / 2;
        sl > 0 && check(sl);
        sl += precision;
        sl < pathLength && check(sl);
        precision /= 2;
    } while (precision > 0.5);

    if (best) {
        best.distance = Math.sqrt(bestDistance2);
        best.pathNode = pathNode;
        best.pathLength = bestLength;
    }
    if (best && (!inbest || best.distance <= inbest.distance + 0.01)) {
        return best;
    } else {
        return inbest;
    }
}

export function tangent_angle(pathNode, length) {
    var length0 = Math.max(0, length - 0.25);
    if (length0 == length)
        length += 0.25;
    var p0 = pathNode.getPointAtLength(length0),
        p1 = pathNode.getPointAtLength(length);
    return Math.atan2(p1.y - p0.y, p1.x - p0.x);
}

export function event_to_point(element, event) {
    // borrowed from D3
    var svg = element.ownerSVGElement || element, point;
    if (svg.createSVGPoint) {
        point = svg.createSVGPoint();
        point.x = event.clientX;
        point.y = event.clientY;
        point = point.matrixTransform(element.getScreenCTM().inverse());
    } else {
        var rect = element.getBoundingClientRect();
        point = {x: event.clientX - rect.left - element.clientLeft,
                 y: event.clientY - rect.top - element.clientTop};
    }
    var result = [point.x, point.y];
    result.clientX = event.clientX;
    result.clientY = event.clientY;
    return result;
}
