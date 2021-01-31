<?php
// facefetch.php -- Peteramati script for fetching & storing faces
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

$ConfSitePATH = preg_replace('/\/batch\/[^\/]+/', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:l:aV", array("help", "name:", "limit:", "all",
     "college", "extension", "tf", "verbose"));
$verbose = isset($arg["V"]) || isset($arg["verbose"]);
if (isset($arg["h"]) || isset($arg["help"]) || count($arg["_"]) > 1) {
    fwrite(STDOUT, "Usage: php batch/facefetch.php [-l LIMIT] [FETCHSCRIPT]\n");
    exit(0);
}

$fetchcommand = $Conf->config->_facefetch_command ?? null;
if (count($arg["_"])) {
    $fetchcommand = $arg["_"];
}
if (!$fetchcommand) {
    fwrite(STDERR, "Need `_facefetch_command` configuration option or argument.\n");
    exit(1);
}
if (is_string($fetchcommand)) {
    $fetchcommand = [$fetchcommand];
}

if (!isset($arg["limit"])) {
    $arg["limit"] = $arg["l"] ?? null;
}
$limit = (int) $arg["limit"];

$where = [];
if (!isset($arg["all"]) && !isset($arg["a"])) {
    $where[] = "contactImageId is null";
}
if (isset($arg["college"])) {
    $where[] = "college=1";
}
if (isset($arg["extension"])) {
    $where[] = "extension=1";
}
if (isset($arg["tf"])) {
    $where[] = "roles!=0";
}
if (empty($where)) {
    $where[] = "true";
}
$result = Dbl::qe("select contactId, email, firstName, lastName, huid from ContactInfo where " . join(" and ", $where));
$rows = array();
while (($row = $result->fetch_object())) {
    $rows[] = $row;
}
if ($limit) {
    shuffle($rows);
}
Dbl::free($result);


function one_facefetch($row, $cmd) {
    global $verbose;

    $args = [];
    foreach (preg_split('/\s+/', $cmd) as $word) {
        if (strpos($word, '${EMAIL}') !== false) {
            if ($row->email === null) {
                return false;
            }
            $word = str_replace('${EMAIL}', urlencode($row->email), $word);
        }
        if (strpos($word, '${ID}') !== false) {
            if ($row->huid === null) {
                return false;
            }
            $word = str_replace('${ID}', urlencode($row->huid), $word);
        }
        if (strpos($word, '${NAMESEARCH}') !== false) {
            if ((string) $row->lastName === "")
                return false;
            $name = strtolower($row->lastName);
            if ((string) $row->firstName !== "") {
                $name .= " " . strtolower(preg_replace('/(^\S+)\s+.*/', '$1', $row->firstName));
            }
            $word = str_replace('${NAMESEARCH}', urlencode($name), $word);
        }
        $args[] = escapeshellarg($word);
    }
    if ($verbose) {
        error_log("    " . join(" ", $args) . "\n");
    }

    $handle = popen(join(" ", $args), "r");
    $data = stream_get_contents($handle);
    $status = pclose($handle);

    $content_type = "";
    if ($data && ($nl = strpos($data, "\n")) !== false) {
        $content_type = substr($data, 0, $nl);
        $data = substr($data, $nl + 1);
    }

    $result = false;
    if (pcntl_wifexitedwith($status)
        && preg_match(',\Aimage/,', $content_type)) {
        $sresult = Dbl::fetch_first_object(Dbl::qe("select ContactImage.* from ContactImage join ContactInfo using (contactImageId) where ContactInfo.contactId=?", $row->contactId));
        if ($sresult
            && $sresult->mimetype === $content_type
            && $sresult->data === $data) {
            $result = ["unchanged", $content_type, strlen($data)];
        } else {
            $iresult = Dbl::qe("insert into ContactImage set contactId=?, mimetype=?, data=?", $row->contactId, $content_type, $data);
            if ($iresult) {
                Dbl::qe("update ContactInfo set contactImageId=? where contactId=?", $iresult->insert_id, $row->contactId);
                $result = [true, $content_type, strlen($data)];
            }
        }
    }
    return $result;
}

$n = $nworked = 0;
foreach ($rows as $row) {
    $ok = false;
    fwrite(STDOUT, "$row->email ");

    foreach ($fetchcommand as $cmd) {
        if (($ok = one_facefetch($row, $cmd)))
            break;
    }

    if ($ok && $ok[0] === "unchanged") {
        fwrite(STDERR, "{$ok[2]}B {$ok[1]} (unchanged)\n");
    } else if ($ok) {
        fwrite(STDERR, "{$ok[2]}B {$ok[1]}\n");
    } else {
        fwrite(STDERR, "failed\n");
    }

    $nworked += $ok ? 1 : 0;
    ++$n;
    if ($n == $limit) {
        break;
    }
}

exit($nworked ? 0 : 1);
