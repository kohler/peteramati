// ui-pset.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { handle_ui } from "./ui.js";
import { hoturl } from "./hoturl.js";
import { escape_entities } from "./encoders.js";
import { Bubble } from "./tooltip.js";

handle_ui.on("js-copy-repo", function () {
    var node = document.createTextNode(this.getAttribute("data-pa-repo"));
    var bub = Bubble(node, {color: "tooltip", dir: "t"});
    bub.near(this);
    var range = document.createRange();
    range.selectNode(node);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    var worked;
    try {
        worked = document.execCommand("copy");
    } catch (err) {
    }
    window.getSelection().removeAllRanges();
    bub.remove();
    if (global_tooltip && global_tooltip.elt == this)
        global_tooltip.text(this.getAttribute("data-pa-repo"));
});

handle_ui.on("js-repositories", function (event) {
    var self = this;
    $.ajax(hoturl("api", {fn: "repositories", u: this.getAttribute("data-pa-user")}), {
        method: "POST", cache: false,
        success: function (data) {
            var t = "Error loading repositories";
            if (data.repositories && data.repositories.length) {
                t = "Repositories: ";
                for (var i = 0; i < data.repositories.length; ++i) {
                    var r = data.repositories[i];
                    i && (t += ", ");
                    t += "<a href=\"" + escape_entities(r.url) + "\">" + escape_entities(r.name) + "</a>";
                }
            } else if (data.repositories) {
                t = "No repositories";
            }
            $("<div style=\"font-size:medium;font-weight:normal\"></div>").html(t).insertAfter(self);
        }
    });
    event.preventDefault();
});
