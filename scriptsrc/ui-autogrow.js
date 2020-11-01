// ui-autogrow.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { removeClass } from "./ui.js";


// based on https://github.com/jaz303/jquery-grab-bag
let autogrowers = null;

function textarea_shadow($self, width) {
    return $("<div></div>").css({
        position:    'absolute',
        top:         -10000,
        left:        -10000,
        width:       width || $self.width(),
        fontSize:    $self.css('fontSize'),
        fontFamily:  $self.css('fontFamily'),
        fontWeight:  $self.css('fontWeight'),
        lineHeight:  $self.css('lineHeight'),
        resize:      'none',
        'word-wrap': 'break-word',
        whiteSpace:  'pre-wrap'
    }).appendTo(document.body);
}

function resizer() {
    for (var i = autogrowers.length - 1; i >= 0; --i) {
        autogrowers[i]();
    }
}

function remover($self, shadow) {
    var f = $self.data("autogrower");
    $self.removeData("autogrower");
    shadow && shadow.remove();
    for (var i = autogrowers.length - 1; i >= 0; --i) {
        if (autogrowers[i] === f) {
            autogrowers[i] = autogrowers[autogrowers.length - 1];
            autogrowers.pop();
        }
    }
}

function make_textarea_autogrower($self) {
    var shadow, minHeight, lineHeight;
    return function (event) {
        if (event === false) {
            return remover($self, shadow);
        }
        var width = $self.width();
        if (width <= 0) {
            return;
        }
        if (!shadow) {
            shadow = textarea_shadow($self, width);
            minHeight = $self.height();
            lineHeight = shadow.text("!").height();
        }

        // Did enter get pressed?  Resize in this keydown event so that the flicker doesn't occur.
        var val = $self[0].value;
        if (event && event.type == "keydown" && event.keyCode === 13) {
            val += "\n";
        }
        shadow.css("width", width).text(val + "...");

        var wh = Math.max($(window).height() - 4 * lineHeight, 4 * lineHeight);
        $self.height(Math.min(wh, Math.max(shadow.height(), minHeight)));
    };
}

function make_input_autogrower($self) {
    var shadow;
    return function (event) {
        if (event === false) {
            return remover($self, shadow);
        }
        var width = 0, ws;
        try {
            width = $self.outerWidth();
        } catch (e) { // IE11 is annoying here
        }
        if (width <= 0) {
            return;
        }
        if (!shadow) {
            shadow = textarea_shadow($self, width);
            var p = $self.css(["paddingRight", "paddingLeft", "borderLeftWidth", "borderRightWidth"]);
            shadow.css({
                width: "auto",
                display: "table-cell",
                paddingLeft: p.paddingLeft,
                paddingRight: (parseFloat(p.paddingRight) + parseFloat(p.paddingLeft) + parseFloat(p.borderLeftWidth) + parseFloat(p.borderRightWidth)) + "px"
            });
            ws = $self.css(["minWidth", "maxWidth"]);
            if (ws.minWidth === "0px") {
                $self.css("minWidth", width + "px");
            }
            if (ws.maxWidth === "none" && !$self.hasClass("wide")) {
                $self.css("maxWidth", "640px");
            }
        }
        shadow.text($self[0].value + "  ");
        ws = $self.css(["minWidth", "maxWidth"]);
        var outerWidth = Math.min(shadow.outerWidth(), $(window).width()),
            maxWidth = parseFloat(ws.maxWidth);
        if (maxWidth === maxWidth) { // i.e., isn't NaN
            outerWidth = Math.min(outerWidth, maxWidth);
        }
        $self.outerWidth(Math.max(outerWidth, parseFloat(ws.minWidth)));
    };
}

$.fn.autogrow = function () {
    this.each(function () {
        var $self = $(this), f = $self.data("autogrower");
        removeClass(this, "need-autogrow");
        if (!f) {
            if (this.tagName === "TEXTAREA") {
                f = make_textarea_autogrower($self);
            } else if (this.tagName === "INPUT" && this.type === "text") {
                f = make_input_autogrower($self);
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
