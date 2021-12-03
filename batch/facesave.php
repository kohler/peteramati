<?php
// facesave.php -- Peteramati script for storing faces
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once(dirname(__DIR__) . "/src/init.php");

$arg = Getopt::rest($argv, "hn:e:a", ["help", "name:", "email:", "all"]);
if (isset($arg["email"]) && !isset($arg["e"])) {
    $arg["e"] = $arg["email"];
}
if (isset($arg["name"]) && !isset($arg["n"])) {
    $arg["n"] = $arg["name"];
}
if (isset($arg["all"]) && !isset($arg["a"])) {
    $arg["a"] = $arg["all"];
}
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


$where = $argdesc = [];
if (isset($arg["n"])) {
    if (strpos($arg["n"], ",") !== false || strpos($arg["n"], " ") !== false) {
        list($f, $l) = Text::split_name($arg["n"]);
        $where[] = "(firstName like '" . sqlq_for_like($f) . "%' and lastName like '" . sqlq_for_like($l) . "%')";
    } else {
        $where[] = "(firstName like '" . sqlq_for_like($arg["n"]) . "%' or lastName like '" . sqlq_for_like($arg["n"]) . "')";
    }
    $argdesc[] = "name " . $arg["n"];
}
if (isset($arg["e"])) {
    $where[] = "email like '" . sqlq_for_like($arg["e"]) . "'";
    $argdesc[] = "email " . $arg["e"];
}
if (empty($argdesc)) {
    $argdesc[] = "all";
}
$q = "select contactId, dropped from ContactInfo where " . join(" and ", $where);
$result = $Conf->qe($q);
$users = array();
while (($row = $result->fetch_object())) {
    $users[] = $row;
}
Dbl::free($result);

if (count($users) > 1) {
    $users = array_filter($users, function ($u) { return !$u->dropped; });
}
if (count($users) !== 1) {
    $t = "user " . join("+", $argdesc);
    if (empty($users)) {
        fwrite(STDERR, "$t unknown, 0 matches\n");
    } else {
        fwrite(STDERR, "$t ambiguous, " . count($users) . " matches\n");
    }
    exit(1);
}
$cid = $users[0]->contactId;


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

if (is_string($worked)) {
    fwrite(STDERR, strlen($image_data) . "B " . $content_type . " ($worked)\n");
} else if ($worked) {
    fwrite(STDERR, strlen($image_data) . "B " . $content_type . "\n");
} else {
    fwrite(STDERR, "failed\n");
}
exit($worked ? 0 : 1);
