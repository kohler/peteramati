// pset.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

import { handle_ui, fold61, addClass, hasClass, $e } from "./ui.js";
import { event_key } from "./ui-key.js";
import { hoturl } from "./hoturl.js";
import { escape_entities } from "./encoders.js";
import { Bubble, global_tooltip } from "./tooltip.js";


handle_ui.on("js-repo-copy", function () {
    var node = document.createTextNode(this.getAttribute("data-pa-repo"));
    var bub = Bubble(node, {color: "tooltip", dir: "t"});
    bub.near(this);
    var range = document.createRange();
    range.selectNode(node);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    try {
        document.execCommand("copy");
    } catch {
    }
    window.getSelection().removeAllRanges();
    bub.remove();
    if (global_tooltip && global_tooltip.elt == this) {
        global_tooltip.text(this.getAttribute("data-pa-repo"));
    }
});

handle_ui.on("js-repo-list", function (event) {
    var self = this;
    $.ajax(hoturl("=api/repositories", {u: this.getAttribute("data-pa-user")}), {
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

handle_ui.on("js-pset-viewoptions", function () {
    fold61(this.nextSibling, this.parentNode);
});

handle_ui.on("js-pset-setgrader", function () {
    var $form = $(this.closest("form"));
    $.ajax($form[0].getAttribute("action"), {
        data: $form.serializeWith({}),
        type: "POST", cache: false,
        dataType: "json",
        success: function (data) {
            var a;
            $form.find(".ajaxsave61").html(data.ok ? "Saved" : "<span class='error'>Error: " + data.error + "</span>");
            if (data.ok && (a = $form.find("a.actas")).length) {
                a.attr("href", a.attr("href").replace(/actas=[^&;]+/, "actas=" + encodeURIComponent(data.grader_email)));
            }
        },
        error: function () {
            $form.find(".ajaxsave61").html("<span class='error'>Failed</span>");
        }
    });
});

handle_ui.on("js-pset-setcommit", function () {
    this.closest("form").submit();
});

handle_ui.on("pa-flagger", function () {
    let self = this, form = self.closest("form");
    if (!form.elements.flagreason) {
        self.parentElement.append($e("div", "flagreasoneditor mt-1",
            $e("span", "flagreason mr-2", "Why do you want to flag this commit?"),
            $e("input", {type: "text", name: "flagreason", value: "", placeholder: "Optional reason", class: "need-autogrow mr-2"}),
            $e("button", {type: "button", class: "ui pa-flagger"}, "OK"),
            $e("span", "ajaxsave61")));
        $(form.elements.flagreason).on("keypress", function (evt) {
            if (!event_key.modcode(evt) && event_key(evt) === "Enter") {
                evt.preventDefault();
                evt.stopImmediatePropagation();
                $(self).click();
            }
        }).autogrow()[0].focus();
        addClass(self, "hidden");
    } else {
        $.post(hoturl("=api/flag", {psetinfo: this, flagid: "new"}),
            {reason: form.elements.flagreason.value},
            function (data) {
                if (data && data.ok) {
                    $(form).find(".flagreasoneditor").remove();
                    $(self).replaceWith($e("strong", null, "Flagged"));
                } else {
                    $(form).find(".ajaxsave61").html('<span class="error">Failed</span>');
                }
            });
    }
});

function apply_flagger(e, data) {
    const psetinfo = e.closest(".pa-psetinfo"),
        grade = !!data.gradecommit && data.gradecommit === psetinfo.getAttribute("data-pa-commit");
    $(psetinfo).find(".pa-flagger-grade").toggleClass("active", grade);
    $(psetinfo).find(".pa-flagger-gradelock").toggleClass("active", grade && data.gradelock);
    $(psetinfo).find(".pa-flagger-nograde").toggleClass("active", data.gradecommit === "");
}

function make_flagger(what) {
    return function () {
        const self = this, arg = {psetinfo: this, [what]: hasClass(this, "active") ? 0 : 1};
        if (what === "gradelock") {
            arg.grade = 1;
        }
        $.post(hoturl("=api/gradeflag", arg), {}, function (data) {
            data && data.ok && apply_flagger(self, data);
        });
    };
}

handle_ui.on("pa-flagger-grade", make_flagger("grade"));
handle_ui.on("pa-flagger-nograde", make_flagger("nograde"));
handle_ui.on("pa-flagger-gradelock", make_flagger("gradelock"));

handle_ui.on("pa-flagger-resolve", function () {
    let self = this, form = self.closest("form");
    $.post(hoturl("=api/flag", {psetinfo: this, flagid: this.getAttribute("data-flagid"), resolve: 1}), {},
        function (data) {
            if (data && data.ok) {
                $(self).replaceWith("<strong>Resolved</strong>");
            } else {
                $(form).find(".ajaxsave61").html('<span class="error">Failed</span>');
            }
        });
});
