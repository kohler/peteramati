// ui-autogrow.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { removeClass } from "./ui.js";


// based on https://github.com/jaz303/jquery-grab-bag
let autogrowers = null, shadows = {};

function get_shadow(elt, width) {
    const css = window.getComputedStyle(elt);
    let t = 'font-size:'.concat(css.fontSize,
        ';font-family:', css.fontFamily,
        ';font-weight:', css.fontWeight,
        ';font-style:', css.fontStyle,
        ';line-height:', css.lineHeight,
        'px;position:absolute;top:-10000px;left:-10000px;resize:none;word-wrap:break-word;white-space:pre-wrap;');
    if (elt.tagName !== "TEXTAREA") {
        const pl = parseFloat(css.paddingLeft),
            pr = parseFloat(css.paddingRight),
            bl = parseFloat(css.borderLeftWidth),
            br = parseFloat(css.borderRightWidth);
        t = t.concat('width:auto;display:table-cell;padding-left:', pl,
            'px;padding-right:', pl + pr + bl + br, 'px;');
        if (css.minWidth === "0px" && width) {
            elt.style.minWidth = width + "px";
        }
        if (css.maxWidth === "none" && !elt.classList.contains("wide")) {
            elt.style.maxWidth = "640px";
        }
    }
    let s = shadows[t];
    if (!s) {
        s = shadows[t] = {
            signature: t,
            elt: document.createElement("div"),
            refcount: 0
        };
        s.elt.setAttribute("style", t);
        document.body.appendChild(s.elt);
    }
    ++s.refcount;
    return s;
}

function resizer() {
    for (var i = autogrowers.length - 1; i >= 0; --i) {
        autogrowers[i]();
    }
}

function remover($self, shadow) {
    var f = $self.data("autogrower");
    $self.removeData("autogrower");
    if (shadow) {
        if (shadow instanceof Element) {
            shadow.remove();
        } else if (--shadow.refcount === 0) {
            shadow.elt.remove();
            delete shadows[shadow.signature];
        }
    }
    shadow && shadow.remove();
    for (var i = autogrowers.length - 1; i >= 0; --i) {
        if (autogrowers[i] === f) {
            autogrowers[i] = autogrowers[autogrowers.length - 1];
            autogrowers.pop();
        }
    }
}

function make_textarea_autogrower(self) {
    var shadow, minHeight, lineHeight;
    return function (event) {
        if (event === false) {
            return remover($(self), shadow);
        }
        var width = self.clientWidth;
        if (width <= 0) {
            return;
        }
        if (!shadow) {
            shadow = get_shadow(self);
            minHeight = self.clientHeight;
            shadow.elt.innerText = "!";
            lineHeight = shadow.elt.clientHeight;
        }

        // Did enter get pressed?  Resize in this keydown event so that the flicker doesn't occur.
        var val = self.value;
        if (event && event.type == "keydown" && event.keyCode === 13) {
            val += "\n";
        }
        shadow.elt.style.width = width + "px";
        shadow.elt.innerText = val + "...";

        var wh = Math.max($(window).height() - 4 * lineHeight, 4 * lineHeight);
        $(self).height(Math.min(wh, Math.max(shadow.elt.clientHeight, minHeight)));
    };
}

function make_input_autogrower(self) {
    var shadow, minWidth, maxWidth;
    return function (event) {
        if (event === false) {
            return remover($(self), shadow);
        }
        var width = 0;
        try {
            width = self.offsetWidth;
        } catch (e) { // IE11 is annoying here
        }
        if (width <= 0) {
            return;
        }
        if (!shadow) {
            shadow = get_shadow(self, width);
            let css = window.getComputedStyle(self);
            minWidth = parseFloat(css.minWidth);
            maxWidth = parseFloat(css.maxWidth);
        }
        shadow.elt.innerText = self.value + "  ";
        let outerWidth = Math.min(shadow.elt.offsetWidth, $(window).width());
        if (maxWidth === maxWidth) { // i.e., isn't NaN
            outerWidth = Math.min(outerWidth, maxWidth);
        }
        $(self).outerWidth(Math.max(outerWidth, minWidth));
    };
}

$.fn.autogrow = function () {
    this.each(function () {
        var $self = $(this), f = $self.data("autogrower");
        removeClass(this, "need-autogrow");
        if (!f) {
            if (this.tagName === "TEXTAREA") {
                f = make_textarea_autogrower(this);
            } else if (this.tagName === "INPUT" && this.type === "text") {
                f = make_input_autogrower(this);
            }
            if (f) {
                $self.data("autogrower", f).on("change input", f);
                if (!autogrowers) {
                    autogrowers = [];
                    $(window).resize(resizer);
                }
                autogrowers.push(f);
            }
        }
        if (f && $self.val() !== "") {
            f();
        }
    });
    return this;
};

$.fn.unautogrow = function () {
    this.each(function () {
        var f = $(this).data("autogrower");
        f && f(false);
    });
    return this;
};

$(function () { $(".need-autogrow").autogrow(); });
