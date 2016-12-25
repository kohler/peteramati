<?php
// api.php -- HotCRP JSON API access page
// HotCRP and Peteramati are Copyright (c) 2006-2016 Eddie Kohler and others
// See LICENSE for open-source distribution terms

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

$qreq = make_qreq();
if ($qreq->base !== null)
    $Conf->set_siteurl($qreq->base);
if ($qreq->fn === "jserror") {
    $url = $qreq->url;
    if (preg_match(',[/=]((?:script|jquery)[^/&;]*[.]js),', $url, $m))
        $url = $m[1];
    if (($n = $qreq->lineno))
        $url .= ":" . $n;
    if (($n = $qreq->colno))
        $url .= ":" . $n;
    if ($url !== "")
        $url .= ": ";
    $errormsg = trim((string) $qreq->error);
    if ($errormsg) {
        $suffix = "";
        if ($Me->email)
            $suffix .= ", user " . $Me->email;
        if (isset($_SERVER["REMOTE_ADDR"]))
            $suffix .= ", host " . $_SERVER["REMOTE_ADDR"];
        error_log("JS error: $url$errormsg$suffix");
        if (($stacktext = $qreq->stack)) {
            $stack = array();
            foreach (explode("\n", $stacktext) as $line) {
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
    json_exit(["ok" => true]);
}

json_exit(["ok" => false]);
