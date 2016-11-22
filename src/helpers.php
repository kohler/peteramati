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

function json_update($j, $updates) {
    if ($j && is_object($j))
        $j = (array) $j;
    else if (!is_array($j))
        $j = [];
    foreach ($updates as $k => $v) {
        if ((string) $k === "") {
            global $Me;
            error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) . ", user $Me->email");
            continue;
        }
        if ($v === null)
            unset($j[$k]);
        else if (is_object($v) || is_array($v)) {
            $v = json_update(isset($j[$k]) ? $j[$k] : null, $v);
            if ($v !== null)
                $j[$k] = $v;
            else
                unset($j[$k]);
        } else
            $j[$k] = $v;
    }
    $n = count($j);
    if ($n == 0)
        return null;
    for ($i = 0; $i != $n; ++$i)
        if (!array_key_exists($i, $j))
            return (object) $j;
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

class JsonResultException extends Exception {
    public $result;
    static public $capturing = false;
    function __construct($j) {
        $this->result = $j;
    }
}

function json_exit($json, $div = false) {
    global $Conf;
    if (JsonResultException::$capturing)
        throw new JsonResultException($json);
    else
        $Conf->ajaxExit($json, $div);
}

function foldbutton($foldtype, $title, $foldnum = 0) {
    $showtitle = ($title ? " title='" . htmlspecialchars("Show $title") . "'" : "");
    $hidetitle = ($title ? " title='" . htmlspecialchars("Hide $title") . "'" : "");
    $foldclass = ($foldnum ? $foldnum : "");
    $foldnum = ($foldnum ? ",$foldnum" : "");
    return "<a href=\"javascript:void fold('$foldtype',0$foldnum)\" class='foldbutton fn$foldclass'$showtitle>+</a><a href=\"javascript:void fold('$foldtype',1$foldnum)\" class='foldbutton fx$foldclass'$hidetitle>&minus;</a>";
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

function rm_rf_tempdir($tempdir) {
    assert(substr($tempdir, 0, 1) === "/");
    exec("/bin/rm -rf " . escapeshellarg($tempdir));
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
            && $mtime < $now - 1800)
            rm_rf_tempdir("$dir/$fname");
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
        if (mkdir($path, $mode)) {
            register_shutdown_function("rm_rf_tempdir", $path);
            return $path;
        }
    }
    return false;
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

function ini_get_bytes($varname, $value = null) {
    $val = trim($value !== null ? $value : ini_get($varname));
    $last = strlen($val) ? strtolower($val[strlen($val) - 1]) : ".";
    return $val * (1 << (+strpos(".kmg", $last) * 10));
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
