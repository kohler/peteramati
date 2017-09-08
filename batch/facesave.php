<?php
// facesave.php -- Peteramati script for storing faces
// HotCRP and Peteramati are Copyright (c) 2006-2017 Eddie Kohler and others
// See LICENSE for open-source distribution terms

$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:e:a", array("help", "name:", "email:", "all"));
if (isset($arg["email"]) && !isset($arg["e"]))
    $arg["e"] = $arg["email"];
if (isset($arg["name"]) && !isset($arg["n"]))
    $arg["n"] = $arg["name"];
if (isset($arg["all"]) && !isset($arg["a"]))
    $arg["a"] = $arg["all"];
if (isset($arg["h"]) || isset($arg["help"])
    || (!isset($arg["e"]) && !isset($arg["n"]))
    || count($arg["_"]) != 1) {
    fwrite(STDOUT, "Usage: php batch/facefetch.php [-n NAME | -e EMAIL] FILE\n");
    exit(0);
}

$image_filename = $arg["_"][0];
if ($image_filename === "-") {
    $image_data = stream_get_contents(STDIN);
} else {
    $image_data = file_get_contents($image_filename);
}
if ($image_data === false) {
    fwrite(STDERR, "$image_filename: Cannot read\n");
    exit(1);
}
$content_type = Mimetype::content_type($image_data);
if ($content_type !== Mimetype::JPG_TYPE && $content_type !== Mimetype::PNG_TYPE && $content_type !== Mimetype::GIF_TYPE) {
    fwrite(STDERR, "$image_filename: Not an image\n");
    exit(1);
}


$where = [];
if (isset($arg["n"])) {
    if (strpos($arg["n"], ",") !== false || strpos($arg["n"], " ") !== false) {
        list($f, $l) = Text::split_name($arg["n"]);
        $where[] = "(firstName like '" . sqlq_for_like($f) . "%' and lastName like '" . sqlq_for_like($l) . "%')";
    } else {
        $where[] = "(firstName like '" . sqlq_for_like($arg["n"]) . "%' or lastName like '" . sqlq_for_like($arg["n"]) . "')";
    }
    $argdesc = "name " . $arg["n"];
}
if (isset($arg["e"])) {
    $where[] = "email like '" . sqlq_for_like($arg["e"]) . "'";
    $argdesc = "email " . $arg["e"];
}
$q = "select contactId from ContactInfo where " . join(" and ", $where);
error_log($q);
$result = $Conf->qe($q);
$cids = array();
while (($row = edb_row($result)))
    $cids[] = $row[0];
Dbl::free($result);

if (count($cids) > 1) {
    fwrite(STDERR, "$argdesc ambiguous, " . count($cids) . " matches\n");
    exit(1);
} else if (empty($cids)) {
    fwrite(STDERR, "$argdesc unknown, 0 matches\n");
    exit(1);
}
$cid = $cids[0];


$worked = false;
$sresult = Dbl::fetch_first_object(Dbl::qe("select ContactImage.* from ContactImage join ContactInfo using (contactImageId) where ContactInfo.contactId=?", $cid));
if ($sresult && $sresult->mimetype === $content_type && $sresult->data === $image_data)
    $worked = "unchanged";
else if ($sresult && !isset($arg["a"]))
    $worked = "has image, ignoring";
else {
    $iresult = Dbl::qe("insert into ContactImage set contactId=?, mimetype=?, data=?", $cid, $content_type, $image_data);
    if ($iresult) {
        Dbl::qe("update ContactInfo set contactImageId=? where contactId=?", $iresult->insert_id, $cid);
        $worked = true;
    }
}

if (is_string($worked))
    fwrite(STDERR, strlen($image_data) . "B " . $content_type . " ($worked)\n");
else if ($worked)
    fwrite(STDERR, strlen($image_data) . "B " . $content_type . "\n");
else
    fwrite(STDERR, "failed\n");
exit($worked ? 0 : 1);
