// tooltip.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { removeClass } from "./ui.js";
import { text_to_html, escape_entities } from "./encoders.js";


const capdir = ["Top", "Right", "Bottom", "Left"],
    lcdir = ["top", "right", "bottom", "left"],
    szdir = ["height", "width"],
    SPACE = 8;

function geometry_translate(g, offset) {
    g = $.extend({}, g);
    g.top += offset.top;
    g.right += offset.left;
    g.bottom += offset.top;
    g.left += offset.left;
    return g;
}

function cssborder(dir, suffix) {
    return "border" + capdir[dir] + suffix;
}

function cssbc(dir) {
    return cssborder(dir, "Color");
}

let roundpixel = Math.round;
if (window.devicePixelRatio && window.devicePixelRatio > 1) {
    roundpixel = (function (dpr) {
        return function (x) { return Math.round(x * dpr) / dpr; };
    })(window.devicePixelRatio);
}

function to_rgba(c) {
    var m = c.match(/^rgb\((.*)\)$/);
    return m ? "rgba(" + m[1] + ", 1)" : c;
}

function make_model(color) {
    const div = document.createElement("div");
    div.className = "bubble hidden" + color;
    const tail = document.createElement("div");
    tail.className = "bubtail bubtail0 nomargin" + color;
    div.appendChild(tail);
    document.body.appendChild(div);
    return div;
}

function calculate_sizes(color) {
    const model = make_model(color),
        $tail = $(model).children(),
        sizes = [$tail.width(), $tail.height()],
        css = window.getComputedStyle(model);
    for (let ds = 0; ds !== 4; ++ds) {
        let x = css["margin" + capdir[ds]];
        if (!x || !(x = parseFloat(x))) {
            x = 0;
        }
        sizes[lcdir[ds]] = x;
    }
    model.remove();
    return sizes;
}

// bubbles and tooltips
export function Bubble(content, bubopt) {
    if (!bubopt && content && typeof content === "object") {
        bubopt = content;
        content = bubopt.content;
    } else if (!bubopt)
        bubopt = {};
    else if (typeof bubopt === "string")
        bubopt = {color: bubopt};

    var nearpos = null, dirspec = bubopt.dir, dir = null,
        color = bubopt.color ? " " + bubopt.color : "";

    let bubdiv = document.createElement("div");
    bubdiv.className = "bubble nomargin" + color;
    {
        let bubtail0 = document.createElement("div");
        bubtail0.className = "bubtail bubtail0 nomargin" + color;
        let bubcontent = document.createElement("div");
        let bubtail1 = document.createElement("div");
        bubtail1.className = "bubtail bubtail1 nomargin" + color;
        bubdiv.append(bubtail0, bubcontent, bubtail1);
        bubtail0.style.width = bubtail0.style.height =
            bubtail1.style.width = bubtail1.style.height = "0px";
    }
    document.body.appendChild(bubdiv);
    if (bubopt["pointer-events"]) {
        $(bubdiv).css({"pointer-events": bubopt["pointer-events"]});
    }
    var bubch = bubdiv.childNodes;
    var sizes = null;
    var divbw = null;

    function change_tail_direction() {
        var bw = [0, 0, 0, 0], trw = sizes[1], trh = sizes[0] / 2;
        divbw = parseFloat($(bubdiv).css(cssborder(dir, "Width")));
        divbw !== divbw && (divbw = 0); // eliminate NaN
        bw[dir^1] = bw[dir^3] = trh + "px";
        bw[dir^2] = trw + "px";
        bubch[0].style.borderWidth = bw.join(" ");
        bw[dir^1] = bw[dir^3] = trh + "px";
        bw[dir^2] = trw + "px";
        bubch[2].style.borderWidth = bw.join(" ");

        for (var i = 1; i <= 3; ++i)
            bubch[0].style[lcdir[dir^i]] = bubch[2].style[lcdir[dir^i]] = "";
        bubch[0].style[lcdir[dir]] = (-trw - divbw) + "px";
        // Offset the inner triangle so that the border width in the diagonal
        // part of the tail, is visually similar to the border width
        var trdelta = (divbw / trh) * Math.sqrt(trw * trw + trh * trh);
        bubch[2].style[lcdir[dir]] = (-trw - divbw + trdelta) + "px";

        for (i = 0; i < 3; i += 2)
            bubch[i].style.borderLeftColor = bubch[i].style.borderRightColor =
            bubch[i].style.borderTopColor = bubch[i].style.borderBottomColor = "transparent";

        var yc = to_rgba($(bubdiv).css("backgroundColor")).replace(/([\d.]+)\)/, function (s, p1) {
            return (0.75 * p1 + 0.25) + ")";
        });
        bubch[0].style[cssbc(dir^2)] = $(bubdiv).css(cssbc(dir));
        bubch[2].style[cssbc(dir^2)] = yc;
    }

    function constrainmid(nearpos, wpos, ds, ds2) {
        var z0 = nearpos[lcdir[ds]], z1 = nearpos[lcdir[ds^2]],
            z = (1 - ds2) * z0 + ds2 * z1;
        z = Math.max(z, Math.min(z1, wpos[lcdir[ds]] + SPACE));
        return Math.min(z, Math.max(z0, wpos[lcdir[ds^2]] - SPACE));
    }

    function constrain(za, wpos, bpos, ds, ds2, noconstrain) {
        var z0 = wpos[lcdir[ds]], z1 = wpos[lcdir[ds^2]],
            bdim = bpos[szdir[ds&1]],
            z = za - ds2 * bdim;
        if (!noconstrain && z < z0 + SPACE)
            z = Math.min(za - sizes[0], z0 + SPACE);
        else if (!noconstrain && z + bdim > z1 - SPACE)
            z = Math.max(za + sizes[0] - bdim, z1 - SPACE - bdim);
        return z;
    }

    function bpos_wconstraint(wpos, ds) {
        var xw = Math.max(ds === 3 ? 0 : nearpos.left - wpos.left,
                          ds === 1 ? 0 : wpos.right - nearpos.right);
        if ((ds === "h" || ds === 1 || ds === 3) && xw > 100)
            return Math.min(wpos.width, xw) - 3*SPACE;
        else
            return wpos.width - 3*SPACE;
    }

    function make_bpos(wpos, ds) {
        var $b = $(bubdiv);
        $b.css("maxWidth", "");
        var bg = $b.geometry(true);
        var wconstraint = bpos_wconstraint(wpos, ds);
        if (wconstraint < bg.width) {
            $b.css("maxWidth", wconstraint);
            bg = $b.geometry(true);
        }
        // bpos[D] is the furthest position in direction D, assuming
        // the bubble was placed on that side. E.g., bpos[0] is the
        // top of the bubble, assuming the bubble is placed over the
        // reference.
        var bpos = [nearpos.top - sizes.bottom - bg.height - sizes[0],
                    nearpos.right + sizes.left + bg.width + sizes[0],
                    nearpos.bottom + sizes.top + bg.height + sizes[0],
                    nearpos.left - sizes.right - bg.width - sizes[0]];
        bpos.width = bg.width;
        bpos.height = bg.height;
        bpos.wconstraint = wconstraint;
        return bpos;
    }

    function remake_bpos(bpos, wpos, ds) {
        var wconstraint = bpos_wconstraint(wpos, ds);
        if ((wconstraint < bpos.wconstraint && wconstraint < bpos.width)
            || (wconstraint > bpos.wconstraint && bpos.width >= bpos.wconstraint))
            bpos = make_bpos(wpos, ds);
        return bpos;
    }

    function parse_dirspec(dirspec, pos) {
        var res;
        if (dirspec.length > pos
            && (res = "0123trblnesw".indexOf(dirspec.charAt(pos))) >= 0)
            return res % 4;
        return -1;
    }

    function csscornerradius(corner, index) {
        var divbr = $(bubdiv).css("border" + corner + "Radius"), pos;
        if (!divbr)
            return 0;
        if ((pos = divbr.indexOf(" ")) > -1)
            divbr = index ? divbr.substring(pos + 1) : divbr.substring(0, pos);
        return parseFloat(divbr);
    }

    function constrainradius(x, bpos, ds) {
        var x0, x1;
        if (ds & 1) {
            x0 = csscornerradius(capdir[0] + capdir[ds], 1);
            x1 = csscornerradius(capdir[2] + capdir[ds], 1);
        } else {
            x0 = csscornerradius(capdir[ds] + capdir[3], 1);
            x1 = csscornerradius(capdir[ds] + capdir[1], 1);
        }
        return Math.min(Math.max(x, x0), bpos[szdir[(ds&1)^1]] - x1 - sizes[0]);
    }

    function show() {
        sizes = sizes || calculate_sizes(color);

        // parse dirspec
        if (dirspec == null)
            dirspec = "r";
        var noflip = /!/.test(dirspec),
            noconstrain = /\*/.test(dirspec),
            dsx = dirspec.replace(/[^a0-3neswtrblhv]/, ""),
            ds = parse_dirspec(dsx, 0),
            ds2 = parse_dirspec(dsx, 1);
        if (ds >= 0 && ds2 >= 0 && (ds2 & 1) != (ds & 1))
            ds2 = (ds2 === 1 || ds2 === 2 ? 1 : 0);
        else
            ds2 = 0.5;
        if (ds < 0)
            ds = /^[ahv]$/.test(dsx) ? dsx : "a";

        var wpos = $(window).geometry();
        var bpos = make_bpos(wpos, dsx);

        if (ds === "a") {
            if (bpos.height + sizes[0] > Math.max(nearpos.top - wpos.top, wpos.bottom - nearpos.bottom)) {
                ds = "h";
                bpos = remake_bpos(bpos, wpos, ds);
            } else
                ds = "v";
        }

        var wedge = [wpos.top + 3*SPACE, wpos.right - 3*SPACE,
                     wpos.bottom - 3*SPACE, wpos.left + 3*SPACE];
        if ((ds === "v" || ds === 0 || ds === 2) && !noflip && ds2 < 0
            && bpos[2] > wedge[2] && bpos[0] < wedge[0]
            && (bpos[3] >= wedge[3] || bpos[1] <= wedge[1])) {
            ds = "h";
            bpos = remake_bpos(bpos, wpos, ds);
        }
        if ((ds === "v" && bpos[2] > wedge[2] && bpos[0] > wedge[0])
            || (ds === 0 && !noflip && bpos[2] > wpos.bottom
                && wpos.top - bpos[0] < bpos[2] - wpos.bottom)
            || (ds === 2 && (noflip || bpos[0] >= wpos.top + SPACE)))
            ds = 2;
        else if (ds === "v" || ds === 0 || ds === 2)
            ds = 0;
        else if ((ds === "h" && bpos[3] - wpos.left < wpos.right - bpos[1])
                 || (ds === 1 && !noflip && bpos[3] < wpos.left)
                 || (ds === 3 && (noflip || bpos[1] <= wpos.right - SPACE)))
            ds = 3;
        else
            ds = 1;
        bpos = remake_bpos(bpos, wpos, ds);

        if (ds !== dir) {
            dir = ds;
            change_tail_direction();
        }

        var x, y, xa, ya, d;
        var divbw = parseFloat($(bubdiv).css(cssborder(ds & 1 ? 0 : 3, "Width")));
        if (ds & 1) {
            ya = constrainmid(nearpos, wpos, 0, ds2);
            y = constrain(ya, wpos, bpos, 0, ds2, noconstrain);
            d = constrainradius(roundpixel(ya - y - sizes[0] / 2 - divbw), bpos, ds);
            bubch[0].style.top = bubch[2].style.top = d + "px";

            if (ds == 1)
                x = nearpos.left - sizes.right - bpos.width - sizes[1] - 1;
            else
                x = nearpos.right + sizes.left + sizes[1];
        } else {
            xa = constrainmid(nearpos, wpos, 3, ds2);
            x = constrain(xa, wpos, bpos, 3, ds2, noconstrain);
            d = constrainradius(roundpixel(xa - x - sizes[0] / 2 - divbw), bpos, ds);
            bubch[0].style.left = bubch[2].style.left = d + "px";

            if (ds == 0)
                y = nearpos.bottom + sizes.top + sizes[1];
            else
                y = nearpos.top - sizes.bottom - bpos.height - sizes[1] - 1;
        }

        bubdiv.style.left = roundpixel(x) + "px";
        bubdiv.style.top = roundpixel(y) + "px";
        bubdiv.style.visibility = "visible";
    }

    function remove() {
        bubdiv && bubdiv.parentElement.removeChild(bubdiv);
        bubdiv = null;
    }

    var bubble = {
        near: function (epos, reference) {
            if (typeof epos === "string" || epos.tagName || epos.jquery) {
                epos = $(epos);
                if (dirspec == null && epos[0]) {
                    dirspec = epos[0].getAttribute("data-tooltip-dir");
                }
                epos = epos.geometry(true);
            }
            for (let i = 0; i < 4; ++i) {
                if (!(lcdir[i] in epos) && (lcdir[i ^ 2] in epos))
                    epos[lcdir[i]] = epos[lcdir[i ^ 2]];
            }
            if (reference && (reference = $(reference)) && reference.length
                && reference[0] != window) {
                epos = geometry_translate(epos, reference.geometry());
            }
            nearpos = epos;
            show();
            return bubble;
        },
        at: function (x, y, reference) {
            return bubble.near({top: y, left: x}, reference);
        },
        dir: function (dir) {
            dirspec = dir;
            return bubble;
        },
        remove: remove,
        color: function (newcolor) {
            newcolor = newcolor ? " " + newcolor : "";
            if (color !== newcolor) {
                color = newcolor;
                bubdiv.className = "bubble" + color;
                bubch[0].className = "bubtail bubtail0" + color;
                bubch[2].className = "bubtail bubtail1" + color;
                dir = sizes = null;
                nearpos && show();
            }
            return bubble;
        },
        html: function (content) {
            var n = bubch[1];
            if (content === undefined)
                return n.innerHTML;
            if (typeof content === "string"
                && content === n.innerHTML
                && bubdiv.style.visibility === "visible")
                return bubble;
            nearpos && $(bubdiv).css({maxWidth: "", left: "", top: ""});
            if (typeof content === "string")
                n.innerHTML = content;
            else {
                while (n.childNodes.length)
                    n.removeChild(n.childNodes[0]);
                if (content && content.jquery)
                    content.appendTo(n);
                else
                    n.appendChild(content);
            }
            nearpos && show();
            return bubble;
        },
        text: function (text) {
            if (text === undefined)
                return $(bubch[1]).text();
            else
                return bubble.html(text ? text_to_html(text) : text);
        },
        content_node: function () {
            return bubch[1].firstChild;
        },
        hover: function (enter, leave) {
            $(bubdiv).hover(enter, leave);
            return bubble;
        },
        removeOn: function (jq, event) {
            if (arguments.length > 1)
                $(jq).on(event, remove);
            else if (bubdiv)
                $(bubdiv).on(jq, remove);
            return bubble;
        },
        self: function () {
            return bubdiv ? $(bubdiv) : null;
        },
        outerHTML: function () {
            return bubdiv ? bubdiv.outerHTML : null;
        }
    };

    content && bubble.html(content);
    return bubble;
}


let builders = {};

function prepare_info(elt, info) {
    var xinfo = elt.getAttribute("data-tooltip-info");
    if (xinfo) {
        if (typeof xinfo === "string" && xinfo.charAt(0) === "{") {
            xinfo = JSON.parse(xinfo);
        } else if (typeof xinfo === "string") {
            xinfo = {builder: xinfo};
        }
        info = $.extend(xinfo, info);
    }
    if (info.builder && builders[info.builder]) {
        info = builders[info.builder].call(elt, info) || info;
    }
    if (info.dir == null || elt.hasAttribute("data-tooltip-dir")) {
        info.dir = elt.getAttribute("data-tooltip-dir") || "v";
    }
    if (info.type == null || elt.hasAttribute("data-tooltip-type")) {
        info.type = elt.getAttribute("data-tooltip-type");
    }
    if (info.className == null || elt.hasAttribute("data-tooltip-class")) {
        info.className = elt.getAttribute("data-tooltip-class") || "tooltip dark";
    }
    if (elt.hasAttribute("data-tooltip")) {
        info.content = elt.getAttribute("data-tooltip");
    } else if (info.content == null) {
        if (elt.hasAttribute("aria-label")) {
            info.content = elt.getAttribute("aria-label");
        } else if (elt.hasAttribute("title")) {
            info.content = elt.getAttribute("title");
        }
    }
    if (elt.hasAttribute("data-tooltip-delay")) {
        info.delay = parseInt(elt.getAttribute("data-tooltip-delay"));
    } else if (info.delay == null && (info.type == null || info.type === "hover")) {
        info.delay = 150;
    }
    return info;
}

function show_tooltip(info) {
    if (window.disable_tooltip) {
        return null;
    }

    const self = this;
    if (info && typeof info === "string") {
        info = {builder: info};
    } else {
        info = Object.assign({}, info || {});
    }
    info = prepare_info(self, info);
    info.element = this;

    var tt, bub = null, to = null, near = null, delayto = null,
        refcount = 1, content = info.content;

    if (info.delay) {
        delayto = setTimeout(function () {
            delayto = null;
            content && !bub && show_bub();
        }, info.delay);
    }

    function erase() {
        to = clearTimeout(to);
        bub && bub.remove();
        $(self).removeData("tooltipState");
        if (window.global_tooltip === tt) {
            window.global_tooltip = null;
        }
    }

    function show_bub() {
        if (delayto || refcount === 0) {
            // do not show
            return;
        }
        if (!content) {
            // remove
            bub && bub.remove();
            bub = near = null;
        } else if (content instanceof Promise) {
            content.then(function (nc) {
                content = nc;
                show_bub();
            });
        } else if (bub) {
            bub.html(content);
        } else {
            bub = Bubble(content, {color: info.className, dir: info.dir});
            near = info.near || info.element;
            bub.near(near).hover(tt.enter, tt.exit);
        }
    }

    tt = {
        enter: function () {
            to = clearTimeout(to);
            ++refcount;
            return tt;
        },
        exit: function () {
            var delay = info.type === "focus" ? 0 : 200;
            to = clearTimeout(to);
            if (--refcount <= 0 && info.type !== "sticky") {
                to = setTimeout(erase, delay);
            }
            return tt;
        },
        erase: erase,
        _element: self,
        html: function (new_content) {
            if (new_content === undefined) {
                return content;
            } else {
                content = new_content;
                show_bub();
                return tt;
            }
        },
        text: function (new_text) {
            return tt.html(escape_entities(new_text));
        },
        near: function () {
            return near;
        }
    };

    {
        let tx = window.global_tooltip;
        if (tx
            && tx._element === info.element
            && tx.html() === content) {
            tt = tx;
        } else {
            tx && tx.erase();
            $(self).data("tooltipState", tt);
            show_bub();
            window.global_tooltip = tt;
        }
    }
    return tt;
}

function ttenter() {
    const tt = $(this).data("tooltipState");
    tt ? tt.enter() : show_tooltip.call(this);
}

function ttleave() {
    var tt = $(this).data("tooltipState");
    tt && tt.exit();
}

export function tooltip() {
    removeClass(this, "need-tooltip");
    var tt = this.getAttribute("data-tooltip-type");
    if (tt === "focus") {
        $(this).on("focus", ttenter).on("blur", ttleave);
    } else {
        $(this).hover(ttenter, ttleave);
    }
}

tooltip.erase = function () {
    var tt = this === tooltip ? window.global_tooltip : $(this).data("tooltipState");
    tt && tt.erase();
};

tooltip.add_builder = function (name, f) {
    builders[name] = f;
};

tooltip.enter = function (e, info) {
    const tt = $(e).data("tooltipState");
    tt ? tt.enter() : show_tooltip.call(e, info);
};

tooltip.leave = function (e) {
    ttleave.call(e);
};

$(function () { $(".need-tooltip").each(tooltip); });
