<?php
// facefetch.php -- Peteramati script for fetching & storing faces
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// Distributed under an MIT-like license; see LICENSE

$ConfSiteBase = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSiteBase/src/init.php");
require_once("$ConfSiteBase/lib/getopt.php");

$arg = getopt_rest($argv, "hn:l:", array("help", "name:", "limit:"));
if (isset($arg["h"]) || isset($arg["help"]) || count($arg["_"]) > 1) {
    fwrite(STDOUT, "Usage: php batch/facefetch.php [FETCHSCRIPT]\n");
    exit(0);
}

$facefetch_urlpattern = @$Opt["facefetch_urlpattern"];
if (@$PsetInfo->_facefetch_urlpattern)
    $facefetch_urlpattern = $PsetInfo->_facefetch_urlpattern;
if (!$facefetch_urlpattern) {
    fwrite(STDERR, 'Need `_facefetch_urlpattern` configuration option.' . "\n");
    exit(1);
}

$fetchscript = @$PsetInfo->_facefetch_script;
if (count($arg["_"]))
    $fetchscript = $arg["_"][0];
if (!$fetchscript) {
    fwrite(STDERR, "Need `_facefetch_script` configuration option or argument.\n");
    exit(1);
} else if (!is_executable($fetchscript)) {
    fwrite(STDERR, "$fetchscript: Not executable.\n");
    exit(1);
}

if (!isset($arg["limit"]))
    $arg["limit"] = @$arg["l"];
$limit = (int) $arg["limit"];

$result = Dbl::qe("select contactId, email, huid from ContactInfo where huid is not null and contactImageId is null");
$n = $nworked = 0;
while (($row = edb_row($result))) {
    $url = $facefetch_urlpattern;
    $url = str_replace('${ID}', urlencode($row[2]), $url);
    $url = str_replace('${EMAIL}', urlencode($row[1]), $url);
    fwrite(STDOUT, $row[1] . " ");

    $handle = popen($fetchscript . " -q " . escapeshellarg($url), "r");
    $fprefix = trim(stream_get_contents($handle));
    $status = pclose($handle);

    $worked = false;
    if (pcntl_wifexited($status) && pcntl_wexitstatus($status) == 0
        && $fprefix
        && ($headers = file_get_contents($fprefix . ".h")) !== false
        && ($data = file_get_contents($fprefix . ".d")) !== false
        && preg_match(',^Content-Type:\s*(image/\S+),mi', $headers, $headerm)) {
        $sresult = Dbl::fetch_first_object(Dbl::qe("select ContactImage.* from ContactImage join ContactInfo using (contactImageId) where contactId=?", $row[0]));
        if ($sresult && $sresult->mimetype === $headerm[1] && $sresult->data === $data)
            $worked = "unchanged";
        else {
            $iresult = Dbl::qe("insert into ContactImage set contactId=?, mimetype=?, data=?", $row[0], $headerm[1], $data);
            if ($iresult) {
                Dbl::qe("update ContactInfo set contactImageId=? where contactId=?", $iresult->insert_id, $row[0]);
                $worked = true;
            }
        }
    }

    if ($worked === "unchanged")
        fwrite(STDERR, strlen($data) . "B " . $headerm[1] . " (unchanged)\n");
    else if ($worked)
        fwrite(STDERR, strlen($data) . "B " . $headerm[1] . "\n");
    else
        fwrite(STDERR, "failed\n");

    if ($fprefix && file_exists($fprefix . ".h") && $worked) {
        unlink($fprefix . ".h");
        unlink($fprefix . ".d");
    }

    $nworked += $worked ? 1 : 0;
    ++$n;
    if ($n == $limit)
        break;
}

exit($nworked ? 0 : 1);
