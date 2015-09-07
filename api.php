<?php
// api.php -- HotCRP JSON API access page
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

// backward compatibility
if (!isset($_GET["fn"])) {
    if (Navigation::path_component(0))
        $_GET["fn"] = Navigation::path_component(0);
    else if (isset($_GET["jserror"]))
        $_GET["fn"] = "jserror";
    else if (isset($_GET["track"]))
        $_GET["fn"] = "track";
    else
        $_GET["fn"] = "deadlines";
}

if (@$_GET["fn"] == "jserror") {
    $url = defval($_REQUEST, "url", "");
    if (preg_match(',[/=]((?:script|jquery)[^/&;]*[.]js),', $url, $m))
        $url = $m[1];
    if (isset($_REQUEST["lineno"]) && $_REQUEST["lineno"] != "0")
        $url .= ":" . $_REQUEST["lineno"];
    if (isset($_REQUEST["colno"]) && $_REQUEST["colno"] != "0")
        $url .= ":" . $_REQUEST["colno"];
    if ($url != "")
        $url .= ": ";
    $errormsg = trim((string) @$_REQUEST["error"]);
    if ($errormsg) {
        $suffix = ($Me->email ? ", user $Me->email" : "");
        error_log("JS error: $url$errormsg$suffix");
        if (isset($_REQUEST["stack"])) {
            $stack = array();
            foreach (explode("\n", $_REQUEST["stack"]) as $line) {
                $line = trim($line);
                if ($line === "" || $line === $errormsg || "Uncaught $line" === $errormsg)
                    continue;
                if (preg_match('/\Aat (\S+) \((\S+)\)/', $line, $m))
                    $line = $m[1] . "@" . $m[2];
                else if (substr($line, 0, 1) === "@")
                    $line = substr($line, 1);
                else if (substr($line, 0, 3) === "at ")
                    $line = substr($line, 3);
                $stack[] = $line;
            }
            error_log("JS error: {$url}via " . join(" ", $stack));
        }
    }
    $Conf->ajaxExit(array("ok" => true));
}

$Conf->ajaxExit(array("ok" => false));
