<?php
// facefetch.php -- Peteramati script for fetching & storing faces
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// See LICENSE for open-source distribution terms

$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:l:a", array("help", "name:", "limit:", "all"));
if (isset($arg["h"]) || isset($arg["help"]) || count($arg["_"]) > 1) {
    fwrite(STDOUT, "Usage: php batch/facefetch.php [-l LIMIT] [FETCHSCRIPT]\n");
    exit(0);
}

$facefetch_urlpattern = get($Opt, "facefetch_urlpattern");
if (get($PsetInfo, "_facefetch_urlpattern"))
    $facefetch_urlpattern = $PsetInfo->_facefetch_urlpattern;
if (!$facefetch_urlpattern) {
    fwrite(STDERR, 'Need `_facefetch_urlpattern` configuration option.' . "\n");
    exit(1);
}

$fetchscript = get($PsetInfo, "_facefetch_script");
if (count($arg["_"]))
    $fetchscript = $arg["_"][0];
if (!$fetchscript) {
    fwrite(STDERR, "Need `_facefetch_script` configuration option or argument.\n");
    exit(1);
}

if (!isset($arg["limit"]))
    $arg["limit"] = get($arg, "l");
$limit = (int) $arg["limit"];

$result = Dbl::qe("select contactId, email, huid from ContactInfo" . (isset($arg["all"]) || isset($arg["a"]) ? "" : " where contactImageId is null"));
$rows = array();
while (($row = edb_row($result)))
    $rows[] = $row;
if ($limit)
    shuffle($rows);
Dbl::free($result);

$n = $nworked = 0;
foreach ($rows as $row) {
    $url = $facefetch_urlpattern;
    if (($row[1] === null && strpos($url, '${EMAIL}') !== false)
        || ($row[2] === null && strpos($url, '${ID}') !== false))
        continue;
    $url = str_replace('${EMAIL}', urlencode($row[1]), $url);
    $url = str_replace('${ID}', urlencode($row[2]), $url);
    // fwrite(STDOUT, "$row[1] $fetchscript " . escapeshellarg($url) . " ");
    fwrite(STDOUT, "$row[1] ");

    $handle = popen($fetchscript . " " . escapeshellarg($url), "r");
    $data = stream_get_contents($handle);
    $status = pclose($handle);

    $content_type = "";
    if ($data && ($nl = strpos($data, "\n")) !== false) {
        $content_type = substr($data, 0, $nl);
        $data = substr($data, $nl + 1);
    }

    $worked = false;
    if (pcntl_wifexited($status) && pcntl_wexitstatus($status) == 0
        && preg_match(',\Aimage/,', $content_type)) {
        $sresult = Dbl::fetch_first_object(Dbl::qe("select ContactImage.* from ContactImage join ContactInfo using (contactImageId) where ContactInfo.contactId=?", $row[0]));
        if ($sresult && $sresult->mimetype === $content_type && $sresult->data === $data)
            $worked = "unchanged";
        else {
            $iresult = Dbl::qe("insert into ContactImage set contactId=?, mimetype=?, data=?", $row[0], $content_type, $data);
            if ($iresult) {
                Dbl::qe("update ContactInfo set contactImageId=? where contactId=?", $iresult->insert_id, $row[0]);
                $worked = true;
            }
        }
    }

    if ($worked === "unchanged")
        fwrite(STDERR, strlen($data) . "B " . $content_type . " (unchanged)\n");
    else if ($worked)
        fwrite(STDERR, strlen($data) . "B " . $content_type . "\n");
    else
        fwrite(STDERR, "failed\n");

    $nworked += $worked ? 1 : 0;
    ++$n;
    if ($n == $limit)
        break;
}

exit($nworked ? 0 : 1);
