<?php
$ConfSiteBase = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSiteBase/src/init.php");
require_once("$ConfSiteBase/lib/getopt.php");

$arg = getopt_rest($argv, "hn:l:", array("help", "name:", "limit:"));
if (isset($arg["h"]) || isset($arg["help"]) || count($arg["_"]) != 1) {
    fwrite(STDOUT, "Usage: php batch/facefetch.php FETCHSCRIPT\n");
    exit(0);
}
$fetchscript = $arg["_"][0];
if (!isset($Opt["facefetch_urlpattern"])) {
    fwrite(STDERR, '$Opt["facefetch_urlpattern"] not set' . "\n");
    exit(1);
}
if (!isset($arg["limit"]))
    $arg["limit"] = @$arg["l"];
$limit = (int) $arg["limit"];

$result = Dbl::qe("select contactId, email, huid from ContactInfo where huid is not null and contactImageId is null");
$n = $nworked = 0;
while (($row = edb_row($result))) {
    $url = $Opt["facefetch_urlpattern"];
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
        $iresult = Dbl::qe("insert into ContactImage set contactId=?, mimetype=?, data=?", $row[0], $headerm[1], $data);
        if ($iresult) {
            Dbl::qe("update ContactInfo set contactImageId=? where contactId=?", $iresult->insert_id, $row[0]);
            ++$nworked;
            $worked = true;
        }
    }

    if ($worked)
        fwrite(STDERR, strlen($data) . "B " . $headerm[1] . "\n");
    else
        fwrite(STDERR, "failed\n");

    if ($fprefix && file_exists($fprefix . ".h") && $worked) {
        unlink($fprefix . ".h");
        unlink($fprefix . ".d");
    }

    ++$n;
    if ($n == $limit)
        break;
}

exit($nworked ? 0 : 1);
