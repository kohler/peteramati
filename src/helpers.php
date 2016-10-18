<?php
// helpers.php -- HotCRP non-class helper functions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

function defappend(&$var, $str) {
    if (!isset($var))
        $var = "";
    $var .= $str;
}

function arrayappend(&$var, $value) {
    if (isset($var))
        $var[] = $value;
    else
        $var = array($value);
}

function mkarray($value) {
    if (is_array($value))
        return $value;
    else
        return array($value);
}

function &array_ensure(&$arr, $key, $val) {
    if (!isset($arr[$key]))
        $arr[$key] = $val;
    return $arr[$key];
}

function set_error_html($x, $error_html = null) {
    if (!$error_html) {
        $error_html = $x;
        $x = (object) array();
    }
    $x->error = true;
    $x->error_html = $error_html;
    return $x;
}

function ago($t) {
    global $Now;
    if ($t + 60 >= $Now)
        return "less than a minute ago";
    else if ($t + 7200 >= $Now)
        return plural((int)(($Now - $t) / 60 + 0.5), "minute") . " ago";
    else if ($t + 259200 >= $Now)
        return plural((int)(($Now - $t) / 3600 + 0.5), "hour") . " ago";
    else
        return plural((int)(($Now - $t) / 86400 + 0.5), "day") . " ago";
}

function parse_time($d, $reference = null) {
    global $Now, $Opt;
    if ($reference === null)
        $reference = $Now;
    if (!isset($Opt["dateFormatTimezoneRemover"])
        && function_exists("timezone_abbreviations_list")) {
        $mytz = date_default_timezone_get();
        $x = array();
        foreach (timezone_abbreviations_list() as $tzname => $tzinfo) {
            foreach ($tzinfo as $tz)
                if ($tz["timezone_id"] == $mytz)
                    $x[] = preg_quote($tzname);
        }
        if (count($x) == 0)
            $x[] = preg_quote(date("T", $reference));
        $Opt["dateFormatTimezoneRemover"] =
            "/(?:\\s|\\A)(?:" . join("|", $x) . ")(?:\\s|\\z)/i";
    }
    if (@$Opt["dateFormatTimezoneRemover"])
        $d = preg_replace($Opt["dateFormatTimezoneRemover"], " ", $d);
    $d = preg_replace('/\butc([-+])/i', 'GMT$1', $d);
    return strtotime($d, $reference);
}


// string helpers

function csvq($text, $quote_empty = false) {
    if ($text == "")
        return $quote_empty ? '""' : $text;
    else if (preg_match('/\A[-_@\$#+A-Za-z0-9.](?:[-_@\$#+A-Za-z0-9. \t]*[-_\$#+A-Za-z0-9.]|)\z/', $text))
        return $text;
    else
        return '"' . str_replace('"', '""', $text) . '"';
}

function cvtint($value, $default = -1) {
    $v = trim((string) $value);
    if (is_numeric($v)) {
        $ival = intval($v);
        if ($ival == floatval($v))
            return $ival;
    }
    return $default;
}

function cvtnum($value, $default = -1) {
    $v = trim((string) $value);
    if (is_numeric($v))
        return floatval($v);
    return $default;
}

function rcvtint(&$value, $default = -1) {
    return (isset($value) ? cvtint($value, $default) : $default);
}

if (function_exists("iconv")) {
    function utf8_substr($str, $off, $len) {
        return iconv_substr($str, $off, $len, "UTF-8");
    }
} else if (function_exists("mb_substr")) {
    function utf8_substr($str, $off, $len) {
        return mb_substr($str, $off, $len, "UTF-8");
    }
} else {
    function utf8_substr($str, $off, $len) {
        $x = substr($str, $off, $len);
        $poff = 0;
        while (($n = preg_match_all("/[\200-\277]/", $x, $m, PREG_PATTERN_ORDER, $poff))) {
            $poff = strlen($x);
            $x .= substr($str, $poff, $n);
        }
        if (preg_match("/\\A([\200-\277]+)/", substr($str, strlen($x)), $m))
            $x .= $m[1];
        return $x;
    }
}

function json_update($j, $updates) {
    if ($j && is_array($j))
        $j = (object) $j;
    else if (!$j || !is_object($j))
        $j = (object) array();
    foreach ($updates as $k => $v) {
        if ((string) $k === "") {
            global $Me;
            error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) . ", user $Me->email");
            continue;
        }
        if ($v === null)
            unset($j->$k);
        else if (is_object($v) || is_array($v)) {
            $v = json_update(isset($j->$k) ? $j->$k : null, $v);
            if ($v !== null)
                $j->$k = $v;
            else
                unset($j->$k);
        } else
            $j->$k = $v;
    }
    $n = count(get_object_vars($j));
    if ($n == 0)
        return null;
    for ($i = 0; $i != $n; ++$i)
        if (!property_exists($j, $i))
            return $j;
    $j = get_object_vars($j);
    ksort($j, SORT_NUMERIC);
    return array_values($j);
}

if (!function_exists("json_encode") || !function_exists("json_decode"))
    require_once("$ConfSitePATH/lib/json.php");

if (!function_exists("json_last_error_msg")) {
    function json_last_error_msg() {
        return "unknown JSON error";
    }
}


// web helpers

global $_hoturl_defaults;
$_hoturl_defaults = null;

function hoturl_defaults($options = array()) {
    global $_hoturl_defaults;
    foreach ($options as $k => $v)
        if ($v !== null)
            $_hoturl_defaults[$k] = urlencode($v);
        else
            unset($_hoturl_defaults[$k]);
    $ret = array();
    if ($_hoturl_defaults)
        foreach ($_hoturl_defaults as $k => $v)
            $ret[$k] = urldecode($v);
    return $ret;
}

function hoturl_site_relative($page, $options = null) {
    global $Conf, $Me, $_hoturl_defaults;
    $t = $page . Navigation::php_suffix();
    // parse options, separate anchor; see also redirectSelf
    $anchor = "";
    if ($options && is_array($options)) {
        $x = "";
        foreach ($options as $k => $v)
            if ($v === null || $v === false)
                /* skip */;
            else if ($k !== "anchor")
                $x .= ($x === "" ? "" : "&amp;") . $k . "=" . urlencode($v);
            else
                $anchor = "#" . urlencode($v);
        $options = $x;
    } else if (preg_match('/\A(.*?)(#.*)\z/', $options, $m))
        list($options, $anchor) = array($m[1], $m[2]);
    // append defaults
    $are = '/\A(|.*?(?:&|&amp;))';
    $zre = '(?:&(?:amp;)?|\z)(.*)\z/';
    if ($_hoturl_defaults)
        foreach ($_hoturl_defaults as $k => $v)
            if (!preg_match($are . preg_quote($k) . '=/', $options))
                $options .= "&amp;" . $k . "=" . $v;
    // create slash-based URLs if appropriate
    if ($options && !$Conf->opt("disableSlashURLs")) {
        if (preg_match('{\A(?:index|pset|diff|run|raw)\z}', $page)
            && preg_match($are . 'u=([^&#?]+)' . $zre, $options, $m)) {
            $t = "~" . $m[2] . ($t === "index" ? "" : "/$t");
            $options = $m[1] . $m[3];
        }
        if (($page == "pset" || $page == "run")
            && preg_match($are . 'pset=(\w+)' . $zre, $options, $m)) {
            $t .= "/" . $m[2];
            $options = $m[1] . $m[3];
            if (preg_match($are . 'u=([^&#?]+)' . $zre, $options, $m)) {
                $t .= "/" . $m[2];
                $options = $m[1] . $m[3];
            }
            if (preg_match($are . 'commit=([0-9a-f]+)' . $zre, $options, $m)) {
                $t .= "/" . $m[2];
                $options = $m[1] . $m[3];
            }
        } else if (($page == "file" || $page == "raw")
                   && preg_match($are . 'pset=\w+' . $zre, $options)) {
            if (preg_match($are . 'u=([^&#?]+)' . $zre, $options, $m)) {
                $t .= "/~" . $m[2];
                $options = $m[1] . $m[3];
            }
            if (preg_match($are . 'pset=(\w+)' . $zre, $options, $m)) {
                $t .= "/" . $m[2];
                $options = $m[1] . $m[3];
            }
            if (preg_match($are . 'commit=([0-9a-f]+)' . $zre, $options, $m)) {
                $t .= "/" . $m[2];
                $options = $m[1] . $m[3];
            }
            if (preg_match($are . 'file=([^&#?]+)' . $zre, $options, $m)) {
                $t .= "/" . str_replace("%2F", "/", $m[2]);
                $options = $m[1] . $m[3];
            }
        } else if ($page == "diff"
                   && preg_match($are . 'pset=\w+' . $zre, $options)) {
            if (preg_match($are . 'u=([^&#?]+)' . $zre, $options, $m)) {
                $t .= "/~" . $m[2];
                $options = $m[1] . $m[3];
            }
            if (preg_match($are . 'pset=(\w+)' . $zre, $options, $m)) {
                $t .= "/" . $m[2];
                $options = $m[1] . $m[3];
            }
            if (preg_match($are . 'commit=([0-9a-f]+)' . $zre, $options, $m)) {
                $t .= "/" . $m[2];
                $options = $m[1] . $m[3];
            }
            if (preg_match($are . 'commit1=([0-9a-f]+)' . $zre, $options, $m)) {
                $t .= "/" . $m[2];
                $options = $m[1] . $m[3];
            }
        } else if (($page == "profile" || $page == "face")
                   && preg_match($are . 'u=([^&#?]+)' . $zre, $options, $m)) {
            $t .= "/" . $m[2];
            $options = $m[1] . $m[3];
        } else if ($page == "help"
                   && preg_match($are . 't=(\w+)' . $zre, $options, $m)) {
            $t .= "/" . $m[2];
            $options = $m[1] . $m[3];
        } else if (preg_match($are . '__PATH__=([^&]+)' . $zre, $options, $m)) {
            $t .= "/" . str_replace("%2F", "/", $m[2]);
            $options = $m[1] . $m[3];
        }
        $options = preg_replace('/&(?:amp;)?\z/', "", $options);
    }
    if ($options && preg_match('/\A&(?:amp;)?(.*)\z/', $options, $m))
        $options = $m[1];
    if ($options)
        return $t . "?" . $options . $anchor;
    else
        return $t . $anchor;
}

function hoturl($page, $options = null) {
    $siteurl = Navigation::siteurl();
    $t = hoturl_site_relative($page, $options);
    if ($page !== "index")
        return $siteurl . $t;
    $expectslash = 5 + strlen(Navigation::php_suffix());
    if (strlen($t) < $expectslash
        || substr($t, 0, $expectslash) !== "index" . Navigation::php_suffix()
        || (strlen($t) > $expectslash && $t[$expectslash] === "/"))
        return $siteurl . $t;
    else
        return ($siteurl !== "" ? $siteurl : Navigation::site_path())
            . substr($t, $expectslash);
}

function hoturl_post($page, $options = null) {
    if (is_array($options))
        $options["post"] = post_value();
    else if ($options)
        $options .= "&amp;post=" . post_value();
    else
        $options = "post=" . post_value();
    return hoturl($page, $options);
}

function hoturl_absolute($page, $options = null) {
    return opt("paperSite") . "/" . hoturl_site_relative($page, $options);
}

function hoturl_absolute_nodefaults($page, $options = null) {
    global $_hoturl_defaults;
    $defaults = $_hoturl_defaults;
    $_hoturl_defaults = null;
    $url = hoturl_absolute($page, $options);
    $_hoturl_defaults = $defaults;
    return $url;
}

function hoturl_site_relative_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl_site_relative($page, $options));
}

function hoturl_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl($page, $options));
}

function hoturl_post_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl_post($page, $options));
}

function hoturl_absolute_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl_absolute($page, $options));
}

function hoturl_image($page) {
    return Navigation::siteurl() . $page;
}


function file_uploaded(&$var) {
    global $Conf;
    if (!isset($var) || ($var['error'] != UPLOAD_ERR_OK && !$Conf))
        return false;
    switch ($var['error']) {
    case UPLOAD_ERR_OK:
        return is_uploaded_file($var['tmp_name'])
            || (PHP_SAPI === "cli" && get($var, "tmp_name_safe"));
    case UPLOAD_ERR_NO_FILE:
        return false;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        $Conf->errorMsg("You tried to upload a file that’s too big for our system to accept.  The maximum size is " . ini_get("upload_max_filesize") . "B.");
        return false;
    case UPLOAD_ERR_PARTIAL:
        $Conf->errorMsg("You appear to have interrupted the upload process; I am not storing that file.");
        return false;
    default:
        $Conf->errorMsg("Internal upload error " . $var['error'] . "!");
        return false;
    }
}

function self_href($extra = array(), $options = null) {
    global $CurrentList;
    // clean parameters from pathinfo URLs
    foreach (array("paperId" => "p", "pap" => "p", "reviewId" => "r", "commentId" => "c") as $k => $v)
        if (isset($_REQUEST[$k]) && !isset($_REQUEST[$v]))
            $_REQUEST[$v] = $_REQUEST[$k];

    $param = "";
    foreach (array("p", "r", "c", "m", "pset", "u", "commit", "mode", "forceShow", "validator", "ls", "list", "t", "q", "qa", "qo", "qx", "qt", "tab", "atab", "group", "sort", "monreq", "noedit", "contact", "reviewer") as $what)
        if (isset($_REQUEST[$what]) && !array_key_exists($what, $extra))
            $param .= "&$what=" . urlencode($_REQUEST[$what]);
    foreach ($extra as $key => $value)
        if ($key != "anchor" && $value !== null)
            $param .= "&$key=" . urlencode($value);
    if (isset($CurrentList) && $CurrentList > 0
        && !isset($_REQUEST["ls"]) && !array_key_exists("ls", $extra))
        $param .= "&ls=" . $CurrentList;

    $param = $param ? substr($param, 1) : "";
    if (!$options || !@$options["site_relative"])
        $uri = hoturl(Navigation::page(), $param);
    else
        $uri = hoturl_site_relative(Navigation::page(), $param);
    if (isset($extra["anchor"]))
        $uri .= "#" . $extra["anchor"];
    $uri = str_replace("&amp;", "&", $uri);
    if (!$options || @$options["raw"])
        return $uri;
    else
        return htmlspecialchars($uri);
}

function redirectSelf($extra = array()) {
    go(self_href($extra, array("raw" => true)));
}

function foldsessionpixel($name, $var, $sub = false) {
    $val = "&amp;val=";
    if ($sub === false)
        $val .= defval($_SESSION, $var, 1);
    else if ($sub === null)
        $val .= "&amp;sub=";
    else if ($sub !== null) {
        if (!isset($_SESSION[$var])
            || array_search($sub, explode(" ", $_SESSION[$var])) === false)
            $val .= "1";
        else
            $val .= "0";
        $val = "&amp;sub=" . $sub . $val;
    }

    return "<img id='foldsession." . $name . "' alt='' src='" . hoturl("sessionvar", "var=" . $var . $val . "&amp;cache=1") . "' width='1' height='1' />";
}

function foldbutton($foldtype, $title, $foldnum = 0) {
    $showtitle = ($title ? " title='" . htmlspecialchars("Show $title") . "'" : "");
    $hidetitle = ($title ? " title='" . htmlspecialchars("Hide $title") . "'" : "");
    $foldclass = ($foldnum ? $foldnum : "");
    $foldnum = ($foldnum ? ",$foldnum" : "");
    return "<a href=\"javascript:void fold('$foldtype',0$foldnum)\" class='foldbutton fn$foldclass'$showtitle>+</a><a href=\"javascript:void fold('$foldtype',1$foldnum)\" class='foldbutton fx$foldclass'$hidetitle>&minus;</a>";
}

function reviewType($paperId, $row, $long = 0) {
    if ($row->reviewType == REVIEW_PRIMARY)
        return "<span class='rtype rtype_pri'>Primary</span>";
    else if ($row->reviewType == REVIEW_SECONDARY)
        return "<span class='rtype rtype_sec'>Secondary</span>";
    else if ($row->reviewType == REVIEW_EXTERNAL)
        return "<span class='rtype rtype_req'>External</span>";
    else if ($row->conflictType >= CONFLICT_AUTHOR)
        return "<span class='author'>Author</span>";
    else if ($row->conflictType > 0)
        return "<span class='conflict'>Conflict</span>";
    else if (!($row->reviewId === null) || $long)
        return "<span class='rtype rtype_pc'>PC</span>";
    else
        return "";
}

function paperDocumentData($prow, $documentType = DTYPE_SUBMISSION, $paperStorageId = 0) {
    global $Conf, $Opt;
    assert($paperStorageId || $documentType == DTYPE_SUBMISSION || $documentType == DTYPE_FINAL);
    if ($documentType == DTYPE_FINAL && $prow->finalPaperStorageId <= 0)
        $documentType = DTYPE_SUBMISSION;
    if ($paperStorageId == 0 && $documentType == DTYPE_FINAL)
        $paperStorageId = $prow->finalPaperStorageId;
    else if ($paperStorageId == 0)
        $paperStorageId = $prow->paperStorageId;
    if ($paperStorageId <= 1)
        return null;

    // pre-load document object from paper
    $doc = (object) array("paperId" => $prow->paperId,
                          "mimetype" => defval($prow, "mimetype", ""),
                          "size" => defval($prow, "size", 0),
                          "timestamp" => defval($prow, "timestamp", 0),
                          "sha1" => defval($prow, "sha1", ""));
    if ($prow->finalPaperStorageId > 0) {
        $doc->paperStorageId = $prow->finalPaperStorageId;
        $doc->documentType = DTYPE_FINAL;
    } else {
        $doc->paperStorageId = $prow->paperStorageId;
        $doc->documentType = DTYPE_SUBMISSION;
    }

    // load document object from database if pre-loaded version doesn't work
    if ($paperStorageId > 0
        && ($doc->documentType != $documentType
            || $paperStorageId != $doc->paperStorageId)) {
        $result = $Conf->qe("select paperStorageId, paperId, length(paper) as size, mimetype, timestamp, sha1, filename, documentType from PaperStorage where paperStorageId=$paperStorageId", "while reading documents");
        $doc = edb_orow($result);
    }

    return $doc;
}

function requestDocumentType($req, $default = DTYPE_SUBMISSION) {
    if (is_string($req))
        $req = array("dt" => $req);
    if (($dt = defval($req, "dt"))) {
        if (preg_match('/\A-?\d+\z/', $dt))
            return (int) $dt;
        $dt = strtolower($dt);
        if ($dt == "paper" || $dt == "submission")
            return DTYPE_SUBMISSION;
        if ($dt == "final")
            return DTYPE_FINAL;
        if (substr($dt, 0, 4) == "opt-")
            $dt = substr($dt, 4);
        foreach (paperOptions() as $o)
            if ($dt == $o->optionAbbrev)
                return $o->optionId;
    }
    if (defval($req, "final", 0) != 0)
        return DTYPE_FINAL;
    return $default;
}

function topicTable($prow, $active = 0) {
    global $Conf;
    $rf = reviewForm();
    $paperId = ($prow ? $prow->paperId : -1);

    // read from paper row if appropriate
    if ($paperId > 0 && $active < 0 && isset($prow->topicIds)) {
        $top = $rf->webTopicArray($prow->topicIds, defval($prow, "topicInterest"));
        return join(" <span class='sep'>&nbsp;</span> ", $top);
    }

    // get current topics
    $paperTopic = array();
    if ($paperId > 0) {
        $result = $Conf->q("select topicId from PaperTopic where paperId=$paperId");
        while ($row = edb_row($result))
            $paperTopic[$row[0]] = $rf->topicName[$row[0]];
    }
    $allTopics = ($active < 0 ? $paperTopic : $rf->topicName);
    if (count($allTopics) == 0)
        return "";

    $out = "<table><tr><td class='pad'>";
    $colheight = (int) ((count($allTopics) + 1) / 2);
    $i = 0;
    foreach ($rf->topicOrder as $tid => $bogus) {
        if (!isset($allTopics[$tid]))
            continue;
        if ($i > 0 && ($i % $colheight) == 0)
            $out .= "</td><td>";
        $tname = htmlspecialchars($rf->topicName[$tid]);
        if ($paperId <= 0 || $active >= 0) {
            $out .= tagg_checkbox_h("top$tid", 1, ($active > 0 ? isset($_REQUEST["top$tid"]) : isset($paperTopic[$tid])),
                                    array("disabled" => $active < 0))
                . "&nbsp;" . tagg_label($tname) . "<br />\n";
        } else
            $out .= $tname . "<br />\n";
        $i++;
    }
    return $out . "</td></tr></table>";
}

function become_user_link($link, $text = "user") {
    global $Conf;
    if (is_object($link) && $link->seascode_username)
        $link = $link->seascode_username;
    else if (is_object($link))
        $link = $link->email;
    return "<a class=\"actas\" href=\"" . self_href(array("actas" => $link)) . "\">"
        . $Conf->cacheableImage("viewas.png", "[Become user]", "Act as " . htmlspecialchars($text)) . "</a>";
}

function authorTable($aus, $viewAs = null) {
    global $Conf;
    $out = "";
    if (!is_array($aus))
        $aus = explode("\n", $aus);
    foreach ($aus as $aux) {
        $au = trim(is_array($aux) ? Text::user_html($aux) : $aux);
        if ($au != '') {
            if (strlen($au) > 30)
                $out .= "<span class='autblentry_long'>";
            else
                $out .= "<span class='autblentry'>";
            $out .= $au;
            if ($viewAs !== null && is_array($aux) && count($aux) >= 2 && $viewAs->email != $aux[2] && $viewAs->privChair)
                $out .= " " . become_user_link($aux[2], Text::name_html($aux));
            $out .= "</span> ";
        }
    }
    return $out;
}

function highlightMatch($match, $text, &$n = null) {
    if ($match == "") {
        $n = 0;
        return $text;
    }
    if ($match[0] != "{")
        $match = "{(" . $match . ")}i";
    return preg_replace($match, "<span class='match'>\$1</span>", $text, -1, $n);
}

function decorateNumber($n) {
    if ($n < 0)
        return "&minus;" . (-$n);
    else if ($n > 0)
        return $n;
    else
        return 0;
}

function preferenceSpan($preference, $topicInterestScore = 0) {
    if (is_array($preference))
        list($preference, $topicInterestScore) = $preference;
    if ($preference != 0)
        $type = ($preference > 0 ? 1 : -1);
    else
        $type = ($topicInterestScore > 0 ? 1 : -1);
    $t = " <span class='asspref$type'>";
    if ($preference)
        $t .= "P" . decorateNumber($preference);
    if ($preference && $topicInterestScore)
        $t .= " ";
    if ($topicInterestScore)
        $t .= "T" . decorateNumber($topicInterestScore);
    return $t . "</span>";
}

function allocateListNumber($listid) {
    if (!isset($_SESSION["l"]))
        $_SESSION["l"] = array();
    $oldest = $empty = 0;
    for ($i = 1; $i <= 8; ++$i)
        if (($l = defval($_SESSION["l"], $i))) {
            if (defval($l, "listid") == $listid)
                return $i;
            else if (!$oldest || defval($l, "timestamp", 0) < defval($_SESSION["l"][$oldest], "timestamp", 0))
                $oldest = $i;
        } else if (!$empty)
            $empty = $i;
    return $empty ? $empty : $oldest;
}

function _tryNewList($opt, $listtype) {
    global $Conf, $ConfSiteSuffix, $Me;
    if ($listtype == "u" && $Me->privChair) {
        $searchtype = (defval($opt, "t") === "all" ? "all" : "pc");
        $q = "select email from ContactInfo";
        if ($searchtype == "pc")
            $q .= " where (roles&" . Contact::ROLE_PC . ")!=0";
        $result = $Conf->qx("$q order by lastName, firstName, email");
        $a = array();
        while (($row = edb_row($result)))
            $a[] = $row[0];
        $a["description"] = ($searchtype == "pc" ? "Program committee" : "Users");
        $a["listid"] = "u:" . $searchtype . "::";
        $a["url"] = "users$ConfSiteSuffix?t=" . $searchtype;
        return $a;
    } else {
        require_once("search.inc");
        $search = new PaperSearch($Me, $opt);
        return $search->sessionList();
    }
}

function goPaperForm($baseUrl = null, $args = array()) {
    global $Conf, $Me, $CurrentList;
    if ($Me->is_empty())
            return "";
    if ($baseUrl === null)
            $baseUrl = ($Me->isPC && $Conf->setting("rev_open") ? "review" : "paper");
    $x = "<form class='gopaper' action='" . hoturl($baseUrl) . "' method='get' accept-charset='UTF-8'><div class='inform'>";
    $x .= "<input id='quicksearchq' class='textlite temptext' type='text' size='10' name='p' value='(All)' title='Enter paper numbers or search terms' />";
    Ht::stash_script("mktemptext('quicksearchq','(All)')");
    foreach ($args as $what => $val)
        $x .= "<input type='hidden' name=\"" . htmlspecialchars($what) . "\" value=\"" . htmlspecialchars($val) . "\" />";
    if (isset($CurrentList) && $CurrentList > 0)
        $x .= "<input type='hidden' name='ls' value='$CurrentList' />";
    $x .= "&nbsp; <input class='b' type='submit' value='Search' /></div></form>";
    return $x;
}

function clean_tempdirs() {
    $dir = null;
    if (function_exists("sys_get_temp_dir"))
        $dir = sys_get_temp_dir();
    if (!$dir)
        $dir = "/tmp";
    while (substr($dir, -1) == "/")
        $dir = substr($dir, 0, -1);
    $dirh = opendir($dir);
    $now = time();
    while (($fname = readdir($dirh)) !== false)
        if (preg_match('/\Ahotcrptmp\d+\z/', $fname)
            && is_dir("$dir/$fname")
            && ($mtime = @filemtime("$dir/$fname")) !== false
            && $mtime < $now - 1800) {
            $xdirh = @opendir("$dir/$fname");
            while (($xfname = readdir($xdirh)) !== false)
                @unlink("$dir/$fname/$xfname");
            @closedir("$dir/$fname");
            @rmdir("$dir/$fname");
        }
    closedir($dirh);
}

function tempdir($mode = 0700) {
    $dir = null;
    if (function_exists("sys_get_temp_dir"))
        $dir = sys_get_temp_dir();
    if (!$dir)
        $dir = "/tmp";
    while (substr($dir, -1) == "/")
        $dir = substr($dir, 0, -1);
    for ($i = 0; $i < 100; $i++) {
        $path = $dir . "/hotcrptmp" . mt_rand(0, 9999999);
        if (mkdir($path, $mode))
            return $path;
    }
    return false;
}


function reviewBlind($rrow) {
    global $Conf;
    $br = $Conf->blindReview();
    return $br == BLIND_ALWAYS
        || ($br == BLIND_OPTIONAL && (!$rrow || $rrow->reviewBlind));
}

function setCommentType($crow) {
    if ($crow && !isset($crow->commentType)) {
        if ($crow->forAuthors == 2)
            $crow->commentType = COMMENTTYPE_RESPONSE | COMMENTTYPE_AUTHOR
                | ($crow->forReviewers ? 0 : COMMENTTYPE_DRAFT);
        else if ($crow->forAuthors == 1)
            $crow->commentType = COMMENTTYPE_AUTHOR;
        else if ($crow->forReviewers == 2)
            $crow->commentType = COMMENTTYPE_ADMINONLY;
        else if ($crow->forReviewers)
            $crow->commentType = COMMENTTYPE_REVIEWER;
        else
            $crow->commentType = COMMENTTYPE_PCONLY;
        if (isset($crow->commentBlind) ? $crow->commentBlind : $crow->blind)
            $crow->commentType |= COMMENTTYPE_BLIND;
    }
}

// watch functions
function saveWatchPreference($paperId, $contactId, $watchtype, $on) {
    global $Conf, $OK;
    $explicit = ($watchtype << WATCHSHIFT_EXPLICIT);
    $selected = ($watchtype << WATCHSHIFT_NORMAL);
    $onvalue = $explicit | ($on ? $selected : 0);
    $Conf->qe("insert into PaperWatch (paperId, contactId, watch)
                values ($paperId, $contactId, $onvalue)
                on duplicate key update watch = (watch & ~" . ($explicit | $selected) . ") | $onvalue",
              "while saving email notification preference");
    return $OK;
}

function genericWatch($prow, $watchtype, $callback) {
    global $Conf, $Me;

    $q = "select C.contactId, firstName, lastName, email,
                password, roles, defaultWatch,
                R.reviewType as myReviewType,
                R.reviewSubmitted as myReviewSubmitted,
                R.reviewNeedsSubmit as myReviewNeedsSubmit,
                conflictType, watch, preferredEmail";
    if ($Conf->sversion >= 47)
        $q .= ", disabled";

    $q .= "\nfrom ContactInfo C
                left join PaperConflict Conf on (Conf.paperId=$prow->paperId and Conf.contactId=C.contactId)
                left join PaperWatch W on (W.paperId=$prow->paperId and W.contactId=C.contactId)
                left join PaperReview R on (R.paperId=$prow->paperId and R.contactId=C.contactId)
                left join PaperComment Cmt on (Cmt.paperId=$prow->paperId and Cmt.contactId=C.contactId)\n";

    $q .= "where watch is not null"
        . " or conflictType>=" . CONFLICT_AUTHOR
        . " or reviewType is not null or commentId is not null"
        . " or (defaultWatch & " . ($watchtype << WATCHSHIFT_ALL) . ")!=0";

    // save review information since we modify $prow
    $saveProw = (object) null;
    setReviewInfo($saveProw, $prow);

    $result = $Conf->qe($q, "while processing email notifications");
    $watchers = array();
    $lastContactId = 0;
    while (($row = edb_orow($result))) {
        if ($row->contactId == $lastContactId
            || $row->contactId == $Me->contactId
            || preg_match('/\Aanonymous\d*\z/', $row->email))
            continue;
        $lastContactId = $row->contactId;

        if ($row->watch
            && ($row->watch & ($watchtype << WATCHSHIFT_EXPLICIT))) {
            if (!($row->watch & ($watchtype << WATCHSHIFT_NORMAL)))
                continue;
        } else {
            if (!($row->defaultWatch & (($watchtype << WATCHSHIFT_NORMAL) | ($watchtype << WATCHSHIFT_ALL))))
                continue;
        }

        $watchers[$row->contactId] = $row;
    }

    // Need to check for outstanding reviews if the settings might prevent a
    // person with outstanding reviews from seeing a comment.
    if (count($watchers)
        && (($Conf->timePCViewAllReviews(false, false) && !$Conf->timePCViewAllReviews(false, true))
            || ($Conf->timeAuthorViewReviews(false) && !$Conf->timeAuthorViewReviews(true)))) {
        $result = $Conf->qe("select C.contactId, R.contactId, max(R.reviewNeedsSubmit) from ContactInfo C
                left join PaperReview R on (R.contactId=C.contactId)
                where C.contactId in (" . join(",", array_keys($watchers)) . ")
                group by C.contactId", "while processing email notifications");
        while (($row = edb_row($result))) {
            $watchers[$row[0]]->isReviewer = $row[1] > 0;
            $watchers[$row[0]]->reviewsOutstanding = $row[2] > 0;
        }
    }

    $method = is_array($callback) ? $callback[1] : null;
    foreach ($watchers as $row) {
        $minic = Contact::fetch($row);
        setReviewInfo($prow, $row);
        if ($method)
            $callback[0]->$method($prow, $minic);
        else
            $callback($prow, $minic);
    }

    setReviewInfo($prow, $saveProw);
}


// text helpers
function commajoin($what, $joinword = "and") {
    $what = array_values($what);
    $c = count($what);
    if ($c == 0)
        return "";
    else if ($c == 1)
        return $what[0];
    else if ($c == 2)
        return $what[0] . " " . $joinword . " " . $what[1];
    else
        return join(", ", array_slice($what, 0, -1)) . ", " . $joinword . " " . $what[count($what) - 1];
}

function prefix_commajoin($what, $prefix, $joinword = "and") {
    return commajoin(array_map(function ($x) use ($prefix) {
        return $prefix . $x;
    }, $what), $joinword);
}

function numrangejoin($range) {
    $i = 0;
    $a = array();
    while ($i < count($range)) {
        for ($j = $i + 1;
             $j < count($range) && $range[$j-1] == $range[$j] - 1;
             $j++)
            /* nada */;
        if ($j == $i + 1)
            $a[] = $range[$i];
        else
            $a[] = $range[$i] . "&ndash;" . $range[$j - 1];
        $i = $j;
    }
    return commajoin($a);
}

function pluralx($n, $what) {
    if (is_array($n))
        $n = count($n);
    return $n == 1 ? $what : pluralize($what);
}

function pluralize($what) {
    if ($what == "this")
        return "these";
    else if ($what == "has")
        return "have";
    else if ($what == "is")
        return "are";
    else if (str_ends_with($what, ")") && preg_match('/\A(.*?)(\s*\([^)]*\))\z/', $what, $m))
        return pluralize($m[1]) . $m[2];
    else if (preg_match('/\A.*?(?:s|sh|ch|[bcdfgjklmnpqrstvxz][oy])\z/', $what)) {
        if (substr($what, -1) == "y")
            return substr($what, 0, -1) . "ies";
        else
            return $what . "es";
    } else
        return $what . "s";
}

function plural($n, $what) {
    return (is_array($n) ? count($n) : $n) . ' ' . pluralx($n, $what);
}

function ordinal($n) {
    $x = $n;
    if ($x > 100)
        $x = $x % 100;
    if ($x > 20)
        $x = $x % 10;
    return $n . ($x < 1 || $x > 3 ? "th" : ($x == 1 ? "st" : ($x == 2 ? "nd" : "rd")));
}

function tabLength($text, $all) {
    $len = 0;
    for ($i = 0; $i < strlen($text); $i++)
        if ($text[$i] == ' ')
            $len++;
        else if ($text[$i] == '\t')
            $len += 8 - ($len % 8);
        else if (!$all)
            break;
        else
            $len++;
    return $len;
}

function wordWrapIndent($text, $info, $indent = 18, $totWidth = 75, $rjinfo = true) {
    if (is_int($indent)) {
        $indentlen = $indent;
        $indent = str_pad("", $indent);
    } else
        $indentlen = strlen($indent);

    $out = "";
    while ($text != "" && ctype_space($text[0])) {
        $out .= $text[0];
        $text = substr($text, 1);
    }

    $out .= preg_replace("/^(?!\\Z)/m", $indent, wordwrap($text, $totWidth - $indentlen));
    if (strlen($info) <= $indentlen) {
        $info = str_pad($info, $indentlen, " ", ($rjinfo ? STR_PAD_LEFT : STR_PAD_RIGHT));
        return $info . substr($out, $indentlen);
    } else
        return $info . "\n" . $out;
}

function htmlWrapText($text) {
    $lines = explode("\n", $text);
    while (count($lines) && $lines[count($lines) - 1] == "")
        array_pop($lines);
    $text = "";
    for ($i = 0; $i < count($lines); $i++) {
        $l = $lines[$i];
        while (($pos = strpos($l, "\t")) !== false)
            $l = substr($l, 0, $pos) . substr('        ', 0, 8 - ($pos % 8)) . substr($l, $pos + 1);
        if (preg_match("/\\A  +.*[^\s.?!-'\")]   +/", $l))
            $l = str_replace(" ", "\xC2\xA0", $l);
        else if (strlen($l) && $l[0] == " ") {
            for ($x = 0; $x < strlen($l) && $l[$x] == " "; $x++)
                /* nada */;
            $l = str_repeat("\xC2\xA0", $x) . substr($l, $x);
        }
        $l = preg_replace('@((?:https?|ftp)://\S+[^\s").,:;])([").,:;]*(?:\s|\z))@',
                          '<a href="$1" rel="noreferrer">$1</a>$2', $l);
        $lines[$i] = $l . "<br />\n";
    }
    return join("", $lines);

    // $lines = explode("\n", $text);
    // Rules: Indented line that starts with "-", "*", or "#[.]" starts
    //   indented text.
    //      Other indented text is preformatted.
    //
    // States: -1 initial, 0 normal text, 1 preformatted text, 2 indented text
    // $state = -1;
    // $savedPar = "";
    // $savedParLines = 0;
    // $indent = 0;
    // $out = "";
    // for ($i = 0; $i < count($lines); $i++) {
    //    $line = $lines[$i];
    //    if (preg_match("/^\\s*\$/", $line)) {
    //          $savedPar .= $line . "\n";
    //          $savedParLines++;
    //    } else if ($state == 1 && ctype_isspace($line[0]))
    //          $out .= $line . "\n";
    //    else if (preg_match("/^(\\s+)(-+|\\*+|\\d+\\.?)\\s/", $line, $matches)) {
    //          $x = tabLength($line, false);
    //    }
    // }
}

function htmlFold($text, $maxWords) {
    global $foldId;

    if (strlen($text) < $maxWords * 7)
        return $text;
    $words = preg_split('/\\s+/', $text);
    if (count($words) < $maxWords)
        return $text;

    $x = join(" ", array_slice($words, 0, $maxWords));

    $fid = (isset($foldId) ? $foldId : 1);
    $foldId = $fid + 1;

    $x .= "<span id='fold$fid' class='foldc'><span class='fn'> ... </span><a class='fn' href='javascript:void fold($fid, 0)'>[More]</a><span class='fx'> " . join(" ", array_slice($words, $maxWords)) . " </span><a class='fx' href='javascript:void fold($fid, 1)'>[Less]</a></span>";

    return $x;
}

function ini_get_bytes($varname) {
    // from PHP manual
    $val = trim(ini_get($varname));
    $last = strtolower($val[strlen($val)-1]);
    switch ($last) {
    case 'g':
        $val *= 1024; // fallthru
    case 'm':
        $val *= 1024; // fallthru
    case 'k':
        $val *= 1024;
    }
    return $val;
}

function whyNotText($whyNot, $action) {
    global $Conf;
    if (!is_array($whyNot))
        $whyNot = array($whyNot => 1);
    $paperId = (isset($whyNot['paperId']) ? $whyNot['paperId'] : -1);
    $reviewId = (isset($whyNot['reviewId']) ? $whyNot['reviewId'] : -1);
    $thisPaper = ($paperId < 0 ? "this paper" : "paper #$paperId");
    $text = '';
    if (isset($whyNot['invalidId'])) {
        $x = $whyNot['invalidId'] . "Id";
        $xid = (isset($whyNot[$x]) ? " \"" . $whyNot[$x] . "\"" : "");
        $text .= "Invalid " . $whyNot['invalidId'] . " number" . htmlspecialchars($xid) . ". ";
    }
    if (isset($whyNot['noPaper']))
        $text .= "No such paper" . ($paperId < 0 ? "" : " #$paperId") . ". ";
    if (isset($whyNot['noReview']))
        $text .= "No such review" . ($reviewId < 0 ? "" : " #$reviewId") . ". ";
    if (isset($whyNot['dbError']))
        $text .= $whyNot['dbError'] . " ";
    if (isset($whyNot['permission']))
        $text .= "You don’t have permission to $action $thisPaper. ";
    if (isset($whyNot['withdrawn']))
        $text .= ucfirst($thisPaper) . " has been withdrawn. ";
    if (isset($whyNot['notWithdrawn']))
        $text .= ucfirst($thisPaper) . " has not been withdrawn. ";
    if (isset($whyNot['notSubmitted']))
        $text .= ucfirst($thisPaper) . " was never officially submitted. ";
    if (isset($whyNot['notAccepted']))
        $text .= ucfirst($thisPaper) . " was not accepted for publication. ";
    if (isset($whyNot["decided"]))
        $text .= "The review process for $thisPaper has completed. ";
    if (isset($whyNot['updateSubmitted']))
        $text .= ucfirst($thisPaper) . " has already been submitted and can no longer be updated. ";
    if (isset($whyNot['notUploaded']))
        $text .= ucfirst($thisPaper) . " can’t be submitted because you haven’t yet uploaded the paper itself. Upload the paper and try again. ";
    if (isset($whyNot['reviewNotSubmitted']))
        $text .= "This review is not yet ready for others to see. ";
    if (isset($whyNot['reviewNotComplete']))
        $text .= "Your own review for $thisPaper is not complete, so you can’t view other people’s reviews. ";
    if (isset($whyNot['responseNotReady']))
        $text .= "The authors&rsquo; response for $thisPaper is not yet ready for reviewers to view. ";
    if (isset($whyNot['reviewsOutstanding']))
        $text .= "You will get access to the reviews once you complete <a href=\"" . hoturl("search", "q=&amp;t=r") . "\">your assigned reviews for other papers</a>.  If you can’t complete your reviews, please let the conference organizers know via the “Refuse review” links. ";
    if (isset($whyNot['reviewNotAssigned']))
        $text .= "You are not assigned to review $thisPaper. ";
    if (isset($whyNot['deadline'])) {
        $dname = $whyNot['deadline'];
        if ($dname[0] == "s")
            $start = $Conf->setting("sub_open", -1);
        else if ($dname[0] == "p" || $dname[0] == "e")
            $start = $Conf->setting("rev_open", -1);
        else
            $start = 1;
        $end = $Conf->setting($dname, -1);
        $now = time();
        if ($start <= 0)
            $text .= "You can’t $action $thisPaper yet. ";
        else if ($start > 0 && $now < $start)
            $text .= "You can’t $action $thisPaper until " . $Conf->printableTime($start, "span") . ". ";
        else if ($end > 0 && $now > $end) {
            if ($dname == "sub_reg")
                $text .= "The paper registration deadline has passed. ";
            else if ($dname == "sub_update")
                $text .= "The deadline to update papers has passed. ";
            else if ($dname == "sub_sub")
                $text .= "The paper submission deadline has passed. ";
            else if ($dname == "extrev_hard")
                $text .= "The external review deadline has passed. ";
            else if ($dname == "pcrev_hard")
                $text .= "The PC review deadline has passed. ";
            else
                $text .= "The deadline to $action $thisPaper has passed. ";
            $text .= "It was " . $Conf->printableTime($end, "span") . ". ";
        } else if ($dname == "au_seerev") {
            if ($Conf->setting("au_seerev") == AU_SEEREV_YES)
                $text .= "Authors who are also reviewers can’t see reviews for their papers while they still have <a href='" . hoturl("search", "t=rout&amp;q=") . "'>incomplete reviews</a> of their own. ";
            else
                $text .= "Authors can’t view paper reviews at the moment. ";
        } else
            $text .= "You can’t $action $thisPaper at the moment. ";
        $text .= "(<a class='nw' href='" . hoturl("deadlines") . "'>View deadlines</a>) ";
    }
    if (isset($whyNot['override']) && $whyNot['override'])
        $text .= "“Override deadlines” can override this restriction. ";
    if (isset($whyNot['blindSubmission']))
        $text .= "Submission to this conference is blind. ";
    if (isset($whyNot['author']))
        $text .= "You aren’t a contact for $thisPaper. ";
    if (isset($whyNot['conflict']))
        $text .= "You have a conflict with $thisPaper. ";
    if (isset($whyNot['externalReviewer']))
        $text .= "External reviewers may not view other reviews for the papers they review. ";
    if (isset($whyNot['differentReviewer']))
        $text .= "You didn’t write this review, so you can’t change it. ";
    if (isset($whyNot['reviewToken']))
        $text .= "If you know a valid review token, enter it above to edit that review. ";
    // finish it off
    if (isset($whyNot['chairMode']))
        $text .= "(<a class='nw' href=\"" . self_href(array("forceShow" => 1)) . "\">" . ucfirst($action) . " the paper anyway</a>) ";
    if (isset($whyNot['forceShow']))
        $text .= "(<a class='nw' href=\"". self_href(array("forceShow" => 1)) . "\">Override conflict</a>) ";
    if ($text && $action == "view")
        $text .= "Enter a paper number above, or <a href='" . hoturl("search", "q=") . "'>list the papers you can view</a>. ";
    return rtrim($text);
}

function parseReviewOrdinal($text) {
    $text = strtoupper($text);
    if (ctype_alpha($text)) {
        if (strlen($text) == 1)
            return ord($text) - 64;
        else if (strlen($text) == 2)
            return (ord($text[0]) - 64) * 26 + ord($text[1]) - 64;
    }
    return -1;
}

function unparseReviewOrdinal($ord) {
    if ($ord === null)
        return "x";
    else if (is_object($ord)) {
        if ($ord->reviewOrdinal)
            return $ord->paperId . unparseReviewOrdinal($ord->reviewOrdinal);
        else
            return $ord->reviewId;
    } else if ($ord <= 26)
        return chr($ord + 64);
    else
        return chr(intval(($ord - 1) / 26) + 64) . chr((($ord - 1) % 26) + 65);
}

function titleWords($title, $chars = 40) {
    // assume that title whitespace has been simplified
    if (strlen($title) <= $chars)
        return $title;
    // don't over-shorten due to UTF-8
    $xtitle = utf8_substr($title, 0, $chars);
    if (($pos = strrpos($xtitle, " ")) > 0
        && substr($title, strlen($xtitle), 1) != " ")
        $xtitle = substr($xtitle, 0, $pos);
    return $xtitle . "...";
}

function __downloadCSV(&$row, $csv) {
    $t = array();
    reset($row);
    if (count($row) == 0)
        return "";
    else if (is_array(current($row))) {
        foreach ($row as &$x)
            $t[] = __downloadCSV($x, $csv);
        unset($x);
        return join("", $t);
    } else if ($csv) {
        foreach ($row as &$x)
            $t[] = csvq($x);
        unset($x);
        return join(",", $t) . "\n";
    } else
        return join("\t", $row) . "\n";
}

function downloadCSV($info, $header, $filename, $description, $opt = array()) {
    global $Conf, $Opt, $zlib_output_compression;
    $iscsv = defval($opt, "type", "csv") == "csv" && !isset($Opt["disableCSV"]);
    if (is_array($info))
        $text = __downloadCSV($info, $iscsv);
    else
        $text = $info;
    if ($header && $iscsv)
        $headertext = __downloadCSV($header, $iscsv);
    else if ($header)
        $headertext = "#" . __downloadCSV($header, $iscsv);
    else
        $headertext = "";
    header("Content-Description: " . $Opt["shortName"] . " $description, PHP generated data");
    header("Content-Disposition: " . (defval($opt, "inline") ? "inline" : "attachment") . "; filename=" . mime_quote_string($Opt["downloadPrefix"] . $filename . ($iscsv ? ".csv" : ".txt")));
    if ($iscsv)
        header("Content-Type: text/csv; charset=utf-8; header=" . ($headertext ? "present" : "absent"));
    else
        header("Content-Type: text/plain; charset=utf-8");
    if (!defval($opt, "nolength") && !$zlib_output_compression)
        header("Content-Length: " . (strlen($headertext) + strlen($text)));
    echo $headertext, $text;
}

function downloadText($text, $filename, $description, $inline = false, $length = true) {
    downloadCSV($text, false, preg_replace('/\.txt\z/', "", $filename), $description, array("inline" => $inline, "nolength" => !$length, "type" => "txt"));
}

function cvtpref($n) {
    $n = trim($n);
    if (preg_match('/^-+$/', $n))
        return -strlen($n);
    else if (preg_match('/^\++$/', $n))
        return strlen($n);
    else if ($n == "")
        return 0;
    else if (is_numeric($n) && $n <= 1000000)
        return round($n);
    else if (strpos($n, "\xE2") !== false)
        // Translate UTF-8 for minus sign into a real minus sign ;)
        return cvtpref(str_replace("\xE2\x88\x92", '-', $n));
    else
        return -1000001;
}

function decisionSelector($curOutcome = 0, $id = null, $extra = "") {
    $text = "<select" . ($id === null ? "" : " id='$id'") . " name='decision'$extra>\n";
    $rf = reviewForm();
    $outcomeMap = $rf->options['outcome'];
    if (!isset($outcomeMap[$curOutcome]))
        $curOutcome = null;
    $outcomes = array_keys($outcomeMap);
    sort($outcomes);
    $outcomes = array_unique(array_merge(array(0), $outcomes));
    if ($curOutcome === null)
        $text .= "    <option value='' selected='selected'><b>Set decision...</b></option>\n";
    foreach ($outcomes as $key)
        $text .= "    <option value='$key'" . ($curOutcome == $key && $curOutcome !== null ? " selected='selected'" : "") . ">" . htmlspecialchars($outcomeMap[$key]) . "</option>\n";
    return $text . "  </select>";
}

function _sort_pcMember($a, $b) {
    return strcasecmp($a->sorter, $b->sorter);
}

function pcMembers() {
    global $Conf;
    return $Conf->pc_members();
}

function pcTags() {
    global $Conf;
    return $Conf->pc_tags();
}

function pcByEmail($email) {
    $pc = pcMembers();
    foreach ($pc as $id => $row)
        if ($row->email == $email)
            return $row;
    return null;
}

function matchContact($pcm, $firstName, $lastName, $email) {
    $lastmax = $firstmax = false;
    if (!$lastName) {
        $lastName = $email;
        $lastmax = true;
    }
    if (!$firstName) {
        $firstName = $lastName;
        $firstmax = true;
    }
    assert(is_string($email) && is_string($firstName) && is_string($lastName));

    $cid = -2;
    $matchprio = 0;
    foreach ($pcm as $pcid => $pc) {
        // Match full email => definite match.
        // Otherwise, sum priorities as follows:
        //   Entire front of email, or entire first or last name => +10 each
        //   Part of word in email, first, or last name          => +1 each
        // If a string is used for more than one of email, first, and last,
        // don't count a match more than once.  Pick closest match.

        $emailprio = $firstprio = $lastprio = 0;
        if ($email !== "") {
            if ($pc->email === $email)
                return $pcid;
            if (($pos = stripos($pc->email, $email)) !== false) {
                if ($pos === 0 && $pc->email[strlen($email)] == "@")
                    $emailprio = 10;
                else if ($pos === 0 || !ctype_alnum($pc->email[$pos - 1]))
                    $emailprio = 1;
            }
        }
        if ($firstName != "") {
            if (($pos = stripos($pc->firstName, $firstName)) !== false) {
                if ($pos === 0 && strlen($pc->firstName) == strlen($firstName))
                    $firstprio = 10;
                else if ($pos === 0 || !ctype_alnum($pc->firstName[$pos - 1]))
                    $firstprio = 1;
            }
        }
        if ($lastName != "") {
            if (($pos = stripos($pc->lastName, $lastName)) !== false) {
                if ($pos === 0 && strlen($pc->lastName) == strlen($lastName))
                    $lastprio = 10;
                else if ($pos === 0 || !ctype_alnum($pc->firstName[$pos - 1]))
                    $lastprio = 1;
            }
        }
        if ($lastmax && $firstmax)
            $thisprio = max($emailprio, $firstprio, $lastprio);
        else if ($lastmax)
            $thisprio = max($emailprio, $lastprio) + $firstprio;
        else if ($firstmax)
            $thisprio = $emailprio + max($firstprio, $lastprio);
        else
            $thisprio = $emailprio + $firstprio + $lastprio;

        if ($thisprio && $matchprio <= $thisprio) {
            $cid = ($matchprio < $thisprio ? $pcid : -1);
            $matchprio = $thisprio;
        }
    }
    return $cid;
}

function matchValue($a, $word, $allowKey = false) {
    $outa = array();
    $outb = array();
    $outc = array();
    foreach ($a as $k => $v)
        if (strcmp($word, $v) == 0
            || ($allowKey && strcmp($word, $k) == 0))
            $outa[] = $k;
        else if (strcasecmp($word, $v) == 0)
            $outb[] = $k;
        else if (stripos($v, $word) !== false)
            $outc[] = $k;
    if (count($outa) > 0)
        return $outa;
    else if (count($outb) > 0)
        return $outb;
    else
        return $outc;
}

function paperOptions($id = null) {
    global $Conf;
    if ($Conf->setting("paperOption") <= 0 || $Conf->sversion <= 0)
        return array();
    $svar = defval($_SESSION, "paperOption", null);
    if (!$svar || !is_array($svar) || count($svar) < 3 || $svar[2] < 2
        || $svar[0] < $Conf->setting("paperOption")) {
        $opt = array();
        $result = $Conf->q("select * from OptionType order by sortOrder, optionName");
        $order = 0;
        while (($row = edb_orow($result))) {
            // begin backwards compatibility to old schema versions
            if (!isset($row->optionValues))
                $row->optionValues = "";
            if (!isset($row->type) && $row->optionValues == "\x7Fi")
                $row->type = PaperOption::T_NUMERIC;
            else if (!isset($row->type))
                $row->type = ($row->optionValues ? PaperOption::T_SELECTOR : PaperOption::T_CHECKBOX);
            // end backwards compatibility to old schema versions
            $row->optionAbbrev = preg_replace("/-+\$/", "", preg_replace("/[^a-z0-9_]+/", "-", strtolower($row->optionName)));
            if ($row->optionAbbrev == "paper" || $row->optionAbbrev == "submission"
                || $row->optionAbbrev == "final" || ctype_digit($row->optionAbbrev))
                $row->optionAbbrev = "opt" . $row->optionId;
            $row->sortOrder = $order++;
            if (!isset($row->displayType))
                $row->displayType = PaperOption::DT_NORMAL;
            if ($row->type == PaperOption::T_FINALPDF)
                $row->displayType = PaperOption::DT_SUBMISSION;
            $row->isDocument = PaperOption::type_is_document($row->type);
            $row->isFinal = PaperOption::type_is_final($row->type);
            $opt[$row->optionId] = $row;
        }
        $_SESSION["paperOption"] = $svar = array($Conf->setting("paperOption"), $opt, 2);
    }
    return $id ? defval($svar[1], $id, null) : $svar[1];
}

function scoreCounts($text, $max = null) {
    $merit = ($max ? array_fill(1, $max, 0) : array());
    $n = $sum = $sumsq = 0;
    foreach (preg_split('/[\s,]+/', $text) as $i)
        if (($i = cvtint($i)) > 0) {
            while ($i > count($merit))
                $merit[count($merit) + 1] = 0;
            $merit[$i]++;
            $sum += $i;
            $sumsq += $i * $i;
            $n++;
        }
    $avg = ($n > 0 ? $sum / $n : 0);
    $dev = ($n > 1 ? sqrt(($sumsq - $sum*$sum/$n) / ($n - 1)) : 0);
    return (object) array("v" => $merit, "max" => count($merit),
                          "n" => $n, "avg" => $avg, "stddev" => $dev);
}

function displayOptionsSet($sessionvar, $var = null, $val = null) {
    global $Conf;
    if (isset($_SESSION[$sessionvar]))
        $x = $_SESSION[$sessionvar];
    else if ($sessionvar == "pldisplay")
        $x = (string) $Conf->setting_data("pldisplay_default");
    else if ($sessionvar == "ppldisplay")
        $x = (string) $Conf->setting_data("ppldisplay_default");
    else
        $x = "";
    if ($x == null || strpos($x, " ") === false) {
        if ($sessionvar == "pldisplay")
            $x = " overAllMerit ";
        else if ($sessionvar == "ppldisplay")
            $x = " tags ";
        else
            $x = " ";
    }

    // set $var to $val in list
    if ($var) {
        $x = str_replace(" $var ", " ", $x);
        if ($val)
            $x .= "$var ";
    }

    // store list in $_SESSION
    return ($_SESSION[$sessionvar] = $x);
}


if (!function_exists("random_bytes")) {
    function random_bytes($length) {
        $x = @file_get_contents("/dev/urandom", false, null, 0, $length);
        if (($x === false || $x === "")
            && function_exists("openssl_random_pseudo_bytes")) {
            $x = openssl_random_pseudo_bytes($length, $strong);
            $x = $strong ? $x : false;
        }
        return $x === "" ? false : $x;
    }
}

function hotcrp_random_password($length = 14) {
    $bytes = random_bytes($length + 10);
    if ($bytes === false) {
        $bytes = "";
        while (strlen($bytes) < $length)
            $bytes .= sha1(opt("conferenceKey") . pack("V", mt_rand()));
    }

    $l = "a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w trcrbrfrthdrchphwrstspswprslcl2 3 4 5 6 7 8 9 - @ _ + = ";
    $pw = "";
    $nvow = 0;
    for ($i = 0;
         $i < strlen($bytes) &&
             strlen($pw) < $length + max(0, ($nvow - 3) / 3);
         ++$i) {
        $x = ord($bytes[$i]) % (strlen($l) / 2);
        if ($x < 30)
            ++$nvow;
        $pw .= rtrim(substr($l, 2 * $x, 2));
    }
    return $pw;
}


function encode_token($x, $format = "") {
    $s = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $t = "";
    if (is_int($x))
        $format = "V";
    if ($format)
        $x = pack($format, $x);
    $i = 0;
    $have = 0;
    $n = 0;
    while ($have > 0 || $i < strlen($x)) {
        if ($have < 5 && $i < strlen($x)) {
            $n += ord($x[$i]) << $have;
            $have += 8;
            ++$i;
        }
        $t .= $s[$n & 31];
        $n >>= 5;
        $have -= 5;
    }
    if ($format == "V")
        return preg_replace('/(\AA|[^A])A*\z/', '$1', $t);
    else
        return $t;
}

function decode_token($x, $format = "") {
    $map = "//HIJKLMNO///////01234567/89:;</=>?@ABCDEFG";
    $t = "";
    $n = $have = 0;
    $x = trim(strtoupper($x));
    for ($i = 0; $i < strlen($x); ++$i) {
        $o = ord($x[$i]);
        if ($o >= 48 && $o <= 90 && ($out = ord($map[$o - 48])) >= 48)
            $o = $out - 48;
        else if ($o == 46 /*.*/ || $o == 34 /*"*/)
            continue;
        else
            return false;
        $n += $o << $have;
        $have += 5;
        while ($have >= 8 || ($n && $i == strlen($x) - 1)) {
            $t .= chr($n & 255);
            $n >>= 8;
            $have -= 8;
        }
    }
    if ($format == "V") {
        $x = unpack("Vx", $t . "\x00\x00\x00\x00\x00\x00\x00");
        return $x["x"];
    } else if ($format)
        return unpack($format, $t);
    else
        return $t;
}

function git_commit_in_list($list, $commit) {
    if ($commit == "")
        return false;
    if (strlen($commit) == 40)
        return isset($list[$commit]) ? $commit : false;
    $cx = false;
    foreach ($list as $hash => $v)
        if (str_starts_with($hash, $commit)) {
            if ($cx)
                return false;
            $cx = $hash;
        }
    return $cx;
}
