// tooltip.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2026 Eddie Kohler
// See LICENSE for open-source distribution terms

import { $e, $$list, $svg, hasClass, removeClass } from "./ui.js";
import { escape_entities } from "./encoders.js";


const ucdir = ["Top", "Right", "Bottom", "Left"],
    lcdir = ["top", "right", "bottom", "left"],
    szdir = ["height", "width"],
    SPACE = 8,
    sizemap = {},
    dpr = window.devicePixelRatio || 1,
    roundpixel = dpr > 1 ? x => Math.round(x * dpr) / dpr : Math.round;

function to_rgba(c) {
    const m = c.match(/^rgb\((.*)\)$/);
    return m ? "rgba(" + m[1] + ", 1)" : c;
}

function cssfloat(s) {
    const v = parseFloat(s);
    return v === v ? v : 0;
}

function calculate_sizes(color) {
    if (!sizemap[color]) {
        const et = $e("div", "bubtail"),
            eb = $e("div", "bubble" + color, et);
        eb.hidden = true;
        document.body.appendChild(eb);
        const ets = window.getComputedStyle(et),
            ebs = window.getComputedStyle(eb),
            sizes = {"0": parseFloat(ets.width), "1": parseFloat(ets.height)};
        for (let ds = 0; ds < 4; ++ds) {
            sizes[lcdir[ds]] = cssfloat(ebs[`margin${ucdir[ds]}`] || "0");
        }
        eb.remove();
        sizemap[color] = sizes;
    }
    return sizemap[color];
}

function parse_dirspec(dirspec, pos) {
    let res;
    if (dirspec.length > pos
        && (res = "0123trblnesw".indexOf(dirspec.charAt(pos))) >= 0) {
        return res % 4;
    }
    return -1;
}

function csscornerradius(styles, corner, index) {
    let divbr = styles[`border${corner}Radius`];
    if (!divbr) {
        return 0;
    }
    const pos = divbr.indexOf(" ");
    if (pos > -1) {
        divbr = index ? divbr.substring(pos + 1) : divbr.substring(0, pos);
    }
    return cssfloat(divbr);
}

function constrainradius(styles, x, bpos, ds, sizes) {
    let x0, x1;
    if (ds & 1) {
        x0 = csscornerradius(styles, ucdir[0] + ucdir[ds], 1);
        x1 = csscornerradius(styles, ucdir[2] + ucdir[ds], 1);
    } else {
        x0 = csscornerradius(styles, ucdir[ds] + ucdir[3], 1);
        x1 = csscornerradius(styles, ucdir[ds] + ucdir[1], 1);
    }
    return Math.min(Math.max(x, x0), bpos[szdir[(ds&1)^1]] - x1 - sizes[0]);
}

function geometry_translate(g, offset) {
    g = $.extend({}, g);
    g.top += offset.top;
    g.right += offset.left;
    g.bottom += offset.top;
    g.left += offset.left;
    return g;
}

function change_tail_direction(tail, bubsty, sizes, dir) {
    const wx = sizes[dir&1], wy = sizes[(dir&1)^1],
        bw = cssfloat(bubsty[`border${ucdir[dir]}Width`]),
        wx1 = wx + (dir&1 ? bw : 0), wy1 = wy + (dir&1 ? 0 : bw);
    let d;
    if (dir === 0) {
        d = `M0 ${wy1}L${wx/2} ${wy1-wy} ${wx} ${wy1}`;
    } else if (dir === 1) {
        d = `M0 0L${wx} ${wy/2} 0 ${wy}`;
    } else if (dir === 2) {
        d = `M0 0L${wx/2} ${wy} ${wx} 0`;
    } else {
        d = `M${wx1} 0L${wx1-wx} ${wy/2} ${wx1} ${wy}`;
    }
    const stroke = bubsty[`border${ucdir[dir]}Color`],
        fill = to_rgba(bubsty.backgroundColor)
            .replace(/([\d.]+)(?=\))/, (s, p1) => 0.75 * p1 + 0.25);
    tail.replaceChildren($svg("svg",
        {width: `${wx1}px`, height: `${wy1}px`, class: "d-block"},
        $svg("path", {
            d: d, stroke: stroke, fill: fill, "stroke-width": bw
        })));
    tail.style.width = `${wx1}px`;
    tail.style.height = `${wy1}px`;
    tail.style.top = tail.style.right = tail.style.bottom = tail.style.left = "";
    if (dir & 1) {
        tail.style[lcdir[dir]] = `${-wx1}px`;
    } else {
        tail.style[lcdir[dir]] = `${-wy1}px`;
    }
}

// bubbles and tooltips
export function Bubble(bubopts, buboptsx) {
    if (typeof bubopts === "string") {
        bubopts = {content: bubopts};
    }
    if (buboptsx) {
        bubopts = Object.assign({}, bubopts,
            typeof buboptsx === "string" ? {class: buboptsx} : buboptsx);
    }

    let color = bubopts.class || bubopts.color || "", dirspec = bubopts.anchor;
    if (color !== "") {
        color = " " + color;
    }

    let bubdiv = bubopts.element, bubdiv_temporary = !bubdiv;
    if (bubdiv) {
        if (!hasClass(bubdiv, "bubble")) {
            throw new Error("bad bubble element");
        }
        if (!bubdiv.lastChild
            || bubdiv.lastChild.nodeName !== "DIV"
            || bubdiv.lastChild.className !== "bubtail") {
            bubdiv.appendChild($e("div", {class: "bubtail", role: "none"}));
        }
        if (bubdiv.childNodes.length !== 2
            || bubdiv.firstChild.nodeName !== "DIV"
            || !hasClass(bubdiv.firstChild, "bubcontent")) {
            const content = $e("div", "bubcontent"),
                tail = bubdiv.lastChild;
            bubdiv.insertBefore(content, bubdiv.firstChild);
            while (content.nextSibling !== tail) {
                content.appendChild(content.nextSibling);
            }
        }
        bubdiv.className = "bubble" + color;
        bubdiv.style.marginLeft = bubdiv.style.marginRight = bubdiv.style.marginTop = bubdiv.style.marginBottom = "0";
        if (bubopts["pointer-events"]) {
            bubdiv.style.pointerEvents = bubopts["pointer-events"];
        }
        bubdiv.style.visibility = "hidden";
    }

    let nearpos = null, dir = null, sizes = null;

    function ensure() {
        if (!bubdiv) {
            bubdiv = Bubble.skeleton();
            bubdiv.className = "bubble" + color;
            if (bubopts["pointer-events"]) {
                bubdiv.style.pointerEvents = bubopts["pointer-events"];
            }
            const container = bubopts.container || document.body;
            container.appendChild(bubdiv);
        }
    }
    ensure();

    function constrainmid(nearpos, wpos, ds, tailfrac) {
        const z0 = nearpos[lcdir[ds]], z1 = nearpos[lcdir[ds^2]];
        let z = (1 - tailfrac) * z0 + tailfrac * z1;
        z = Math.max(z, Math.min(z1, wpos[lcdir[ds]] + SPACE));
        return Math.min(z, Math.max(z0, wpos[lcdir[ds^2]] - SPACE));
    }

    function constrain(za, wpos, bpos, ds, tailfrac, noconstrain) {
        const z0 = wpos[lcdir[ds]], z1 = wpos[lcdir[ds^2]], bdim = bpos[szdir[ds&1]];
        let z = za - tailfrac * bdim;
        if (!noconstrain && z < z0 + SPACE) {
            z = Math.min(za - sizes[0], z0 + SPACE);
        } else if (!noconstrain && z + bdim > z1 - SPACE) {
            z = Math.max(za + sizes[0] - bdim, z1 - SPACE - bdim);
        }
        return z;
    }

    function bpos_wconstraint(wpos, ds) {
        const xw = Math.max(ds === 3 ? 0 : nearpos.left - wpos.left,
                            ds === 1 ? 0 : wpos.right - nearpos.right);
        if ((ds === "h" || ds === 1 || ds === 3) && xw > 100) {
            return Math.min(wpos.width, xw) - 3*SPACE;
        }
        return wpos.width - 3*SPACE;
    }

    function make_bpos(wpos, ds) {
        bubdiv.style.maxWidth = "";
        let bg = $(bubdiv).geometry(true);
        const wconstraint = bpos_wconstraint(wpos, ds);
        if (wconstraint < bg.width) {
            bubdiv.style.maxWidth = wconstraint + "px";
            bg = $(bubdiv).geometry(true);
        }
        // bpos[D] is the furthest position in direction D, assuming
        // the bubble was placed on that side. E.g., bpos[0] is the
        // top of the bubble, assuming the bubble is placed over the
        // reference.
        return {
            "0": nearpos.top - sizes.bottom - bg.height - sizes[0],
            "1": nearpos.right + sizes.left + bg.width + sizes[0],
            "2": nearpos.bottom + sizes.top + bg.height + sizes[0],
            "3": nearpos.left - sizes.right - bg.width - sizes[0],
            width: bg.width,
            height: bg.height,
            wconstraint: wconstraint
        };
    }

    function remake_bpos(bpos, wpos, ds) {
        const wconstraint = bpos_wconstraint(wpos, ds);
        if ((wconstraint < bpos.wconstraint && wconstraint < bpos.width)
            || (wconstraint > bpos.wconstraint && bpos.width >= bpos.wconstraint)) {
            bpos = make_bpos(wpos, ds);
        }
        return bpos;
    }

    function show() {
        ensure();
        bubdiv.hidden = false;
        if (!sizes) {
            sizes = calculate_sizes(color);
        }

        // parse dirspec
        if (dirspec == null) {
            dirspec = "r";
        }
        dirspec = dirspec.toString();
        let noflip = /!/.test(dirspec),
            noconstrain = /\*/.test(dirspec),
            dsx = dirspec.replace(/[^a0-3neswtrblhv]/, ""),
            ds = parse_dirspec(dsx, 0),
            tailfrac = parse_dirspec(dsx, 1);
        if (ds >= 0 && tailfrac >= 0 && (tailfrac & 1) != (ds & 1)) {
            tailfrac = (tailfrac === 1 || tailfrac === 2 ? 1 : 0);
        } else {
            tailfrac = 0.5;
        }
        if (ds < 0) {
            ds = /^[ahv]$/.test(dsx) ? dsx : "a";
        }

        const wpos = $(window).geometry();
        bubdiv.style.maxWidth = bubdiv.style.left = bubdiv.style.top = "";
        let bpos = make_bpos(wpos, dsx);

        if (ds === "a") {
            if (bpos.height + sizes[0] > Math.max(nearpos.top - wpos.top, wpos.bottom - nearpos.bottom)) {
                ds = "h";
                bpos = remake_bpos(bpos, wpos, ds);
            } else {
                ds = "v";
            }
        }

        const wedge = [wpos.top + 3*SPACE, wpos.right - 3*SPACE,
                       wpos.bottom - 3*SPACE, wpos.left + 3*SPACE];
        if ((ds === "v" || ds === 0 || ds === 2) && !noflip && tailfrac < 0
            && bpos[2] > wedge[2] && bpos[0] < wedge[0]
            && (bpos[3] >= wedge[3] || bpos[1] <= wedge[1])) {
            ds = "h";
            bpos = remake_bpos(bpos, wpos, ds);
        }
        if ((ds === "v" && bpos[2] > wedge[2] && bpos[0] > wedge[0])
            || (ds === 0 && !noflip && bpos[2] > wpos.bottom
                && wpos.top - bpos[0] < bpos[2] - wpos.bottom)
            || (ds === 2 && (noflip || bpos[0] >= wpos.top + SPACE))) {
            ds = 2;
        } else if (ds === "v" || ds === 0 || ds === 2) {
            ds = 0;
        } else if ((ds === "h" && bpos[3] - wpos.left < wpos.right - bpos[1])
                   || (ds === 1 && !noflip && bpos[3] < wpos.left)
                   || (ds === 3 && (noflip || bpos[1] <= wpos.right - SPACE))) {
            ds = 3;
        } else {
            ds = 1;
        }
        bpos = remake_bpos(bpos, wpos, ds);

        const bubsty = window.getComputedStyle(bubdiv);
        if (ds !== dir) {
            dir = ds;
            change_tail_direction(bubdiv.lastChild, bubsty, sizes, dir);
        }

        let x, y, xa, ya;
        if (ds & 1) {
            ya = constrainmid(nearpos, wpos, 0, tailfrac);
            y = constrain(ya, wpos, bpos, 0, tailfrac, noconstrain);
            if (ds === 1) {
                x = nearpos.left - sizes.right - bpos.width - sizes[1];
            } else {
                x = nearpos.right + sizes.left + sizes[1];
            }
        } else {
            xa = constrainmid(nearpos, wpos, 3, tailfrac);
            x = constrain(xa, wpos, bpos, 3, tailfrac, noconstrain);
            if (ds === 0) {
                y = nearpos.bottom + sizes.top + sizes[1];
            } else {
                y = nearpos.top - sizes.bottom - bpos.height - sizes[1];
            }
        }

        let dx = 0, dy = 0;
        const container = bubdiv.parentElement;
        if (bubsty.position === "fixed" || container !== document.body) {
            dx -= window.scrollX;
            dy -= window.scrollY;
        }
        if (bubsty.position !== "fixed" && container !== document.body) {
            const cg = $(container).geometry();
            dx -= cg.x - container.scrollLeft;
            dy -= cg.y - container.scrollTop;
        }
        x = roundpixel(x + dx);
        y = roundpixel(y + dy);

        let d;
        if (ds & 1) {
            d = ya + dy - y - cssfloat(bubsty.borderTopWidth) - sizes[0]/2;
        } else {
            d = xa + dx - x - cssfloat(bubsty.borderLeftWidth) - sizes[0]/2;
        }
        bubdiv.lastChild.style[lcdir[ds&1?0:3]] = constrainradius(bubsty, d, bpos, ds, sizes) + "px";

        bubdiv.style.left = x + "px";
        bubdiv.style.top = y + "px";
        bubdiv.style.visibility = "visible";
        bubdiv.hidden = false;
    }

    function remove() {
        if (bubdiv && bubdiv_temporary) {
            bubdiv.remove();
            bubdiv = null;
        } else if (bubdiv) {
            bubdiv.hidden = true;
        }
    }

    function reclass(newcolor) {
        newcolor = newcolor ? " " + newcolor : "";
        if (color !== newcolor) {
            color = newcolor;
            bubdiv.className = "bubble" + color;
            dir = sizes = null;
            nearpos && show();
        }
        return bubble;
    }

    const bubble = {
        near: function (epos, reference) {
            if (typeof epos === "string" || epos.tagName || epos.jquery) {
                epos = $(epos);
                if (dirspec == null && epos[0]) {
                    dirspec = epos[0].getAttribute("data-tooltip-anchor");
                }
                epos = epos.geometry(true);
            }
            for (let i = 0; i < 4; ++i) {
                if (!(lcdir[i] in epos) && (lcdir[i ^ 2] in epos))
                    epos[lcdir[i]] = epos[lcdir[i ^ 2]];
            }
            if (reference
                && (reference = $(reference))
                && reference.length
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
        anchor: function (dir) {
            dirspec = dir;
            return bubble;
        },
        remove: remove,
        className: reclass,
        color: reclass,
        html: function (content) {
            if (content === undefined) {
                return bubdiv ? bubdiv.firstChild.innerHTML : "";
            }
            ensure();
            const n = bubdiv.firstChild;
            if (typeof content === "string"
                && content === n.innerHTML
                && bubdiv.style.visibility === "visible") {
                return bubble;
            }
            if (typeof content === "string") {
                n.innerHTML = content;
            } else if (content && content.jquery) {
                n.replaceChildren();
                content.appendTo(n);
            } else {
                n.replaceChildren(content);
            }
            nearpos && show();
            return bubble;
        },
        text: function (text) {
            if (text === undefined) {
                return bubdiv ? bubdiv.firstChild.textContent : "";
            }
            return bubble.replace_content(text);
        },
        content_node: function () {
            return bubdiv.firstChild;
        },
        replace_content: function (...es) {
            ensure();
            bubdiv.firstChild.replaceChildren(...es);
            nearpos && show();
            return bubble;
        },
        hover: function (enter, leave) {
            bubdiv.addEventListener("pointerenter", enter);
            bubdiv.addEventListener("pointerleave", leave);
            return bubble;
        },
        removeOn: function (jq, evt) {
            if (arguments.length > 1) {
                $(jq).on(evt, remove);
            } else if (bubdiv) {
                $(bubdiv).on(jq, remove);
            }
            return bubble;
        },
        element: function () {
            return bubdiv;
        },
        self: function () {
            return $(bubdiv);
        },
        outerHTML: function () {
            return bubdiv ? bubdiv.outerHTML : null;
        }
    };

    if (bubopts.content) {
        bubble.html(bubopts.content);
    }
    return bubble;
}

Bubble.skeleton = function () {
    return $e("div", {class: "bubble", style: "margin:0", role: "tooltip", hidden: true},
        $e("div", "bubcontent"),
        $e("div", {class: "bubtail", role: "none"}));
};


const builders = {};
export let global_tooltip = null;
const tooltip_map = new WeakMap;

function prepare_info(elt, info) {
    let xinfo = elt.getAttribute("data-tooltip-info");
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
    if (info.anchor == null || elt.hasAttribute("data-tooltip-anchor")) {
        info.anchor = elt.getAttribute("data-tooltip-anchor") || "v";
    }
    if (info.type == null || elt.hasAttribute("data-tooltip-type")) {
        info.type = elt.getAttribute("data-tooltip-type");
    }
    if (info.className == null || elt.hasAttribute("data-tooltip-class")) {
        info.className = elt.getAttribute("data-tooltip-class") || "";
    }
    let es;
    if (elt.hasAttribute("data-tooltip")) {
        info.content = elt.getAttribute("data-tooltip");
    } else if (info.content != null) {
        // leave alone
    } else if (elt.hasAttribute("aria-describedby")
               && (es = $$list(elt.getAttribute("aria-describedby"))).length === 1
               && hasClass(es[0], "bubble")) {
        info.contentElement = es[0];
    } else if (elt.hasAttribute("aria-label")) {
        info.content = escape_entities(elt.getAttribute("aria-label"));
    } else if (elt.hasAttribute("title")) {
        info.content = escape_entities(elt.getAttribute("title"));
    }
    return info;
}

function show_tooltip(info) {
    if (window.disable_tooltip) {
        return null;
    }

    const self = this;
    info = prepare_info(self, $.extend({}, info || {}));

    let bub = null, to = null, refcount = 0;

    function close() {
        to = clearTimeout(to);
        if (bub) {
            bub.element().removeEventListener("pointerenter", tt.enter);
            bub.element().removeEventListener("pointerleave", tt.leave);
            bub.remove();
        }
        bub && bub.remove();
        tooltip_map.delete(self);
        if (global_tooltip === tt) {
            global_tooltip = null;
        }
    }

    let tt = {
        enter: function () {
            to = clearTimeout(to);
            ++refcount;
            return tt;
        },
        leave: function () {
            const delay = info.type === "focus" ? 0 : 200;
            to = clearTimeout(to);
            if (--refcount === 0 && info.type !== "sticky") {
                to = setTimeout(close, delay);
            }
            return tt;
        },
        close: close,
        owner: function () {
            return self;
        },
        near: function () {
            return info.near || self;
        },
        bubbleElement: function () {
            return bub ? bub.element() : null;
        }
    };

    function complete(content) {
        if (content instanceof Promise) {
            content.then(complete);
            return;
        }

        let tx = global_tooltip;
        if (tx
            && tx.owner() === info.element
            && (info.contentElement
                ? info.contentElement === tx.bubbleElement()
                : content === tx.html())
            && !info.done) {
            tt = tx;
            return;
        }
        if (tx) {
            tx.close();
            tx = null;
        }

        const className = info.className ? `tooltip ${info.className}` : "tooltip",
            bubinfo = {class: className, anchor: info.anchor};
        if (info.type === "focus") {
            bubinfo.class += " position-absolute";
        }
        if (info.contentElement) {
            bubinfo.element = info.contentElement;
        } else if (content) {
            bubinfo.content = content;
        } else {
            return;
        }

        tooltip_map.set(self, tt);
        bub = Bubble(bubinfo).near(info.near || self);
        bub.element().addEventListener("pointerenter", tt.enter);
        bub.element().addEventListener("pointerleave", tt.leave);
        global_tooltip = tt;
    }
    complete(info.content);
    info.done = true;
    return tt;
}

function ttenter() {
    const tt = tooltip_map.get(this) || show_tooltip.call(this);
    tt && tt.enter();
}

function ttleave() {
    const tt = tooltip_map.get(this);
    tt && tt.leave();
}

export function tooltip() {
    removeClass(this, "need-tooltip");
    const tt = this.getAttribute("data-tooltip-type");
    if (tt === "within") {
        tooltip_within(this);
    } else {
        this.addEventListener("focusin", ttenter);
        this.addEventListener("focusout", ttleave);
        if (tt !== "focus") {
            this.addEventListener("pointerenter", ttenter);
            this.addEventListener("pointerleave", ttleave);
        }
    }
}

export function tooltip_within(elt) {
    const info = prepare_info(elt, {});
    function enter(evt) {
        const wte = evt.target.closest(".need-tooltip-within");
        if (wte) {
            const tt = tooltip_map.get(wte) || show_tooltip.call(wte, info);
            tt && tt.enter();
        }
    }
    function leave(evt) {
        const wte = evt.target.closest(".need-tooltip-within");
        wte && ttleave.call(wte);
    }
    elt.addEventListener("mouseover", enter);
    elt.addEventListener("mouseout", leave);
    elt.addEventListener("focusin", enter);
    elt.addEventListener("focusout", leave);
}

tooltip.close = function (e) {
    const tt = e ? tooltip_map.get(e) : global_tooltip;
    tt && tt.close();
};

tooltip.close_under = function (e) {
    if (global_tooltip && e.contains(global_tooltip.near())) {
        global_tooltip.close();
    }
};

tooltip.add_builder = function (name, f) {
    builders[name] = f;
};

tooltip.enter = function (e, info) {
    const tt = tooltip_map.get(e);
    tt ? tt.enter() : show_tooltip.call(e, info);
};

tooltip.leave = function (e) {
    ttleave.call(e);
};
