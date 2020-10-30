// run-settings.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { handle_ui } from "./ui.js";

function add(name, value) {
    var $j = $("#pa-runsettings"), num = $j.find(".n").length;
    while ($j.find("[data-runsetting-num=" + num + "]").length)
        ++num;
    var $x = $("<div class=\"pa-p\" data-runsetting-num=\"" + num + "\"><div class=\"pa-pt\"></div><div class=\"pa-pd\"><input name=\"n" + num + "\" class=\"uich pa-runconfig ignore-diff n\" size=\"30\" placeholder=\"Name\"> &nbsp; <input name=\"v" + num + "\" class=\"uich pa-runconfig ignore-diff v\" size=\"40\" placeholder=\"Value\"></div></div>");
    if (name) {
        $x.find(".n").val(name);
        $x.find(".v").val(value);
    }
    $j.append($x);
    if (!name)
        $x.find(".n").focus();
}

function save() {
    var $j = $("#pa-runsettings .pa-p"), j = {}, i, k, v;
    for (i = 0; i != $j.length; ++i) {
        k = $.trim($($j[i]).find(".n").val());
        v = $.trim($($j[i]).find(".v").val());
        if (k != "")
            j[k] = v;
    }
    $.ajax($j.closest("form").attr("action"), {
        data: {runsettings: j},
        type: "POST", cache: false,
        dataType: "json"
    });
}

export function run_settings_load(j) {
    var $j = $("#pa-runsettings"), $n = $j.find(".n"), i, x;
    $n.attr("data-outstanding", "1");
    for (x in j) {
        for (i = 0; i != $n.length && $.trim($($n[0]).val()) != x; ++i)
            /* nada */;
        if (i == $n.length)
            add(x, j[x]);
        else if ($.trim($j.find("[name=v" + i + "]").val()) != j[x]) {
            $j.find("[name=v" + i + "]").val(j[x]);
            $($n[i]).removeAttr("data-outstanding");
        }
    }
    for (i = 0; i != $n.length; ++i)
        if ($($n[i]).attr("data-outstanding"))
            $("[data-runsetting-num=" + $($n[i]).attr("name").substr(1) + "]").remove();
}

handle_ui.on("pa-runconfig", function (event) {
    if (this.name === "define") {
        add();
    } else {
        save();
    }
});
