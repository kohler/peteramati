<?php
// initweb.php -- HotCRP initialization (test or site)
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

define("TAG_REGEX", '~?~?[a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*');
define("TAG_REGEX_OPTVALUE", '~?~?[a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*([#=](-\d)?\d*)?');
define("TAG_MAXLEN", 40);

define("CAPTYPE_RESETPASSWORD", 1);

// see also forward_pset_links
define("LINK_PARTNER", 1);
define("LINK_BACKPARTNER", 2);
define("LINK_REPO", 3);         // see also gitfetch
define("LINK_REPOVIEW", 4);

define("HASNOTES_GRADE", 1);
define("HASNOTES_COMMENT", 2);
define("HASNOTES_ANY", 3);

global $OK;
$OK = 1;
global $Now;
$Now = time();

// set $ConfSitePATH (path to conference site), $ConfSiteBase, and $ConfSiteSuffix
function set_path_variables() {
    global $ConfSitePATH, $ConfSiteBase, $ConfSiteSuffix;
    if (!@$ConfSitePATH) {
        $ConfSitePATH = substr(__FILE__, 0, strrpos(__FILE__, "/"));
        while ($ConfSitePATH !== "" && !file_exists("$ConfSitePATH/src/init.php"))
            $ConfSitePATH = substr($ConfSitePATH, 0, strrpos($ConfSitePATH, "/"));
        if ($ConfSitePATH === "")
            $ConfSitePATH = "/var/www/html";
    }
    require_once("$ConfSitePATH/lib/navigation.php");
    Navigation::analyze();
    if (@$ConfSiteBase === null)
        $ConfSiteBase = Navigation::siteurl();
    if (@$ConfSiteSuffix === null)
        $ConfSiteSuffix = Navigation::php_suffix();
}
set_path_variables();


// Load code
function __autoload($class_name) {
    global $ConfSitePATH, $ConfAutoloads;
    if (!@$ConfAutoloads)
        $ConfAutoloads = array("ContactView" => "src/contactview.php",
                               "CS61Mailer" => "src/cs61mailer.php",
                               "CsvGenerator" => "lib/csv.php",
                               "CsvParser" => "lib/csv.php",
                               "DocumentHelper" => "lib/documenthelper.php",
                               "Ht" => "lib/ht.php",
                               "LoginHelper" => "lib/login.php",
                               "Mailer" => "lib/mailer.php",
                               "Messages" => "lib/messages.php",
                               "MimeText" => "lib/mailer.php",
                               "Mimetype" => "lib/mimetype.php",
                               "Multiconference" => "src/multiconference.php",
                               "PsetView" => "src/psetview.php",
                               "RunnerState" => "src/runner.php",
                               "Qobject" => "lib/qobject.php",
                               "Text" => "lib/text.php",
                               "UnicodeHelper" => "lib/unicodehelper.php",
                               "UserActions" => "src/useractions.php",
                               "XlsxGenerator" => "lib/xlsx.php",
                               "ZipDocument" => "lib/documenthelper.php");
    if (($f = @$ConfAutoloads[$class_name]))
        require_once("$ConfSitePATH/$f");
}

require_once("$ConfSitePATH/lib/base.php");
require_once("$ConfSitePATH/lib/redirect.php");
require_once("$ConfSitePATH/lib/dbl.php");
require_once("$ConfSitePATH/src/helpers.php");
require_once("$ConfSitePATH/src/conference.php");
require_once("$ConfSitePATH/src/contact.php");


// Set locale to C (so that, e.g., strtolower() on UTF-8 data doesn't explode)
setlocale(LC_COLLATE, "C");
setlocale(LC_CTYPE, "C");


// Set up conference options (also used in mailer.php)
function expand_includes($sitedir, $files, $expansions = array()) {
    global $Opt;
    if (is_string($files))
        $files = array($files);
    $confname = @$Opt["confid"] ? : @$Opt["dbName"];
    $results = array();
    $cwd = null;
    foreach ($files as $f) {
        if (strpos($f, '$') !== false) {
            $f = preg_replace(',\$\{conf(?:id|name)\}|\$conf(?:id|name)\b,', $confname, $f);
            foreach ($expansions as $k => $v)
                if ($v !== false && $v !== null)
                    $f = preg_replace(',\$\{' . $k . '\}|\$' . $k . '\b,', $v, $f);
                else if (preg_match(',\$\{' . $k . '\}|\$' . $k . '\b,', $f)) {
                    $f = false;
                    break;
                }
        }
        if ($f === false)
            /* skip */;
        else if (preg_match(',[\[\]\*\?],', $f)) {
            if ($cwd === null) {
                $cwd = getcwd();
                chdir($sitedir);
            }
            foreach (glob($f, GLOB_BRACE) as $x)
                $results[] = $x;
        } else
            $results[] = $f;
    }
    foreach ($results as &$f)
        $f = ($f[0] == "/" ? $f : "$sitedir/$f");
    if ($cwd)
        chdir($cwd);
    return $results;
}

function read_included_options($sitedir, $files) {
    global $Opt;
    foreach (expand_includes($sitedir, $files) as $f)
        if (!@include $f)
            $Opt["missing"][] = $f;
}

global $Opt, $OptOverride;
if (!@$Opt)
    $Opt = array();
if (!@$OptOverride)
    $OptOverride = array();
if (!@$Opt["loaded"]) {
    if (defined("HOTCRP_OPTIONS")) {
        if ((@include HOTCRP_OPTIONS) !== false)
            $Opt["loaded"] = true;
    } else if ((@include "$ConfSitePATH/conf/options.php") !== false
               || (@include "$ConfSitePATH/conf/options.inc") !== false
               || (@include "$ConfSitePATH/Code/options.inc") !== false)
        $Opt["loaded"] = true;
    if (@$Opt["multiconference"])
        Multiconference::init();
    if (@$Opt["include"])
        read_included_options($ConfSitePATH, $Opt["include"]);
}
if (!@$Opt["loaded"] || @$Opt["missing"])
    Multiconference::fail_bad_options();


// Allow lots of memory
function set_memory_limit() {
    global $Opt;
    if (!@$Opt["memoryLimit"]) {
        $suf = array("" => 1, "k" => 1<<10, "m" => 1<<20, "g" => 1<<30);
        if (preg_match(',\A(\d+)\s*([kmg]?)\z,', strtolower(ini_get("memory_limit")), $m)
            && $m[1] * $suf[$m[2]] < (128<<20))
            $Opt["memoryLimit"] = "128M";
    }
    if (@$Opt["memoryLimit"])
        ini_set("memory_limit", $Opt["memoryLimit"]);
}
set_memory_limit();


// Create the conference
// XXX more modern method
if (isset($Opt["dbName"])) {
    if (!isset($Opt["sessionName"]))
	$Opt["sessionName"] = $Opt["dbName"];
    if (!isset($Opt["downloadPrefix"]))
	$Opt["downloadPrefix"] = $Opt["dbName"] . "-";
}

global $Conf;
if (!@$Conf)
    $Conf = new Conference(Dbl::make_dsn($Opt));
if (!$Conf->dblink)
    Multiconference::fail_bad_database();

// Set server timezone
if (function_exists("date_default_timezone_set")) {
    if (isset($Opt["timezone"]))
        date_default_timezone_set($Opt["timezone"]);
    else if (!ini_get("date.timezone") && !getenv("TZ"))
        date_default_timezone_set("America/New_York");
}


// Extract problem set information
function load_pset_info_pset_order($p, $runkey, $orderkey) {
    if (!isset($p->$runkey))
        $p->$runkey = (object) array();
    else if (is_array($p->$runkey)) {
        $rs = (object) array();
        foreach ($p->$runkey as $r) {
            if (!isset($r->name))
                $r->name = count($rs) + 1;
            $n = $r->name;
            if (isset($rs->$n))
                Multiconference::fail_message("`psets.json` error: {$p->psetid}->{$runkey}->$n reused");
            $rs->$n = $r;
        }
        $p->$runkey = $rs;
    } else if (is_object($p->$runkey)) {
        foreach (get_object_vars($p->$runkey) as $k => $r)
            if (!isset($r->name))
                $r->name = $k;
    } else
        Multiconference::fail_message("`psets.json` error: {$p->psetid}->$runkey isn’t an array");

    foreach (get_object_vars($p->$runkey) as $k => $x)
        if (!preg_match(',\A[-_\w]+\z,', $k))
            Multiconference::fail_message("`psets.json` error: {$p->psetid}->$runkey format (bad key `$k`; consider using `title`)");

    if (!isset($p->$orderkey))
        $p->$orderkey = array_keys(get_object_vars($p->$runkey));
    else if (is_array($p->$orderkey)) {
        foreach ($p->$orderkey as $k)
            if (!is_string($k) || $k === "" || !isset($p->$runkey->$k))
                Multiconference::fail_message("`psets.json` error: {$p->psetid}->$orderkey format");
    } else
        Multiconference::fail_message("`psets.json` error: {$p->psetid}->$orderkey format");
}

function load_pset_info() {
    global $ConfSitePATH, $Psets, $PsetKeys, $PsetInfo, $Opt;
    // read initial messages
    Messages::$main = new Messages;
    $x = json_decode(file_get_contents("$ConfSitePATH/src/messages.json"));
    foreach ($x as $j)
        Messages::$main->add($j);

    // read psets
    $psets = expand_includes($ConfSitePATH, $Opt["psetsConfig"],
                             array("CONFID" => @$Opt["confid"] ? : @$Opt["dbName"],
                                   "HOSTTYPE" => @$Opt["hostType"] ? : ""));
    if (!count($psets))
        Multiconference::fail_message("\$Opt[\"psetsConfig\"] is not set correctly.");
    $PsetInfo = (object) array();
    foreach ($psets as $f) {
        $x = json_decode(file_get_contents($f));
        if (!$x)
            Multiconference::fail_message("`$f` is not present or contains errors. Decoding error: " . json_last_error_msg());
        if (@$x->_messages && is_array($x->_messages)) {
            foreach ($x->_messages as $j)
                Messages::add($j);
        } else if (@$x->_messages && is_object($x->_messages)) {
            foreach (get_object_vars($x->_messages) as $type => $j) {
                $jj = is_array($j) ? $j : array($j);
                foreach ($jj as $j)
                    Messages::add($j, $type);
            }
        }
        object_replace_recursive($PsetInfo, $x);
    }

    // check psets contents
    if (!isset($PsetInfo->_defaults))
        $PsetInfo->_defaults = (object) array();
    $Psets = (object) array();
    $PsetKeys = (object) array();
    $psetids = array();
    $pset_key_regex = '/\A[0-9A-Za-z][-0-9A-Za-z_.]*\z/';
    foreach ($PsetInfo as $pk => $p)
        if (is_object($p) && isset($p->psetid)) {
            // pset id
            if (!is_int($p->psetid) || $p->psetid <= 0)
                Multiconference::fail_message("`psets.json` error: psetid “{$p->psetid}” must be positive integer");
            if (@$psetids[$p->psetid])
                Multiconference::fail_message("`psets.json` error: psetid {$p->psetid} reused");
            $psetids[$p->psetid] = true;
            $psetid = $p->id = $p->psetid;
            $Psets->$psetid = $p;

            // pset key
            if (ctype_digit($pk) && intval($pk) !== $p->psetid)
                Multiconference::fail_message("`psets.json` error: numeric pset key “{$pk}” disagrees with psetid");
            if (!preg_match(',\A[^_./&;#][^/&;#]*\z,', $pk))
                Multiconference::fail_message("`psets.json` error: pset key “{$pk}” format error");
            $p->psetkey = $pk;

            // title
            if (!@$p->title)
                $p->title = $pk;

            // url keys
            if (!isset($p->urlkey)) {
                $p->urlkey = $p->psetid;
                if (preg_match($pset_key_regex, $pk) && $pk != "pset" . $p->psetid)
                    $p->urlkey = $pk;
            }
            if (!preg_match($pset_key_regex, $p->urlkey))
                Multiconference::fail_message("`psets.json` error: invalid URL key “{$p->urlkey}”");
            $x = $p->urlkey;
            if (!property_exists($PsetKeys, $x))
                $PsetKeys->$x = $p->id;
            else if ($PsetKeys->$x !== $p->id)
                Multiconference::fail_message("`psets.json` error: reusing URL key “{$p->urlkey}”");
            $x = $p->psetkey;
            if (!property_exists($PsetKeys, $x))
                $PsetKeys->$x = $p->id;
            else if ($PsetKeys->$x !== $p->id)
                Multiconference::fail_message("`psets.json` error: reusing pset key for URL “{$p->psetkey}”");

            // defaults
            foreach ($PsetInfo->_defaults as $k => $v)
                if (!property_exists($p, $k))
                    $p->$k = $v;
                else if (is_object($p->$k) && is_object($v))
                    object_replace_recursive($p->$k, $v);

            // directory
            if (!isset($p->directory))
                $p->directory = "";
            if (!is_string($p->directory))
                Multiconference::fail_message("`psets.json` error: {$pk}->directory should be a string");
            $p->directory_slash = $p->directory;
            if ($p->directory_slash !== "" && !str_ends_with($p->directory_slash, "/"))
                $p->directory_slash .= "/";
            $p->directory_noslash = preg_replace(',/+\z,', '', $p->directory_slash);

            // backwards compatibility
            foreach (["deadline_college" => "college_deadline",
                      "deadline_extension" => "extension_deadline",
                      "visible" => "show_to_students",
                      "grades_visible" => "show_grades_to_students",
                      "grades_visible_college" => "show_grades_to_college",
                      "grades_visible_extension" => "show_grades_to_extension",
                      "grade_cdf_visible" => "show_grade_cdf_to_students"]
                      as $k => $oldk)
                if (@$p->$k === null && @$p->$oldk !== null)
                    $p->$k = $p->$oldk;

            // grades
            if (@$p->grades_visible_college == null && @$p->grades_visible)
                $p->grades_visible_college = $p->grades_visible;
            if (@$p->grades_visible_extension == null && @$p->grades_visible)
                $p->grades_visible_extension = $p->grades_visible;

            // deadlines
            foreach (array("deadline", "deadline_college", "deadline_extension",
                           "visible", "grades_visible",
                           "grades_visible_college", "grades_visible_extension",
                           "grade_cdf_visible") as $dl)
                if (is_string(@$p->$dl) && ($p->$dl = parse_time($p->$dl)) <= 0)
                    Multiconference::fail_message("`{$pk}->$dl` error: bad date format");

            // runners, grades
            load_pset_info_pset_order($p, "runners", "runner_order");
            load_pset_info_pset_order($p, "grades", "grade_order");
            $p->has_extra = false;
            foreach (get_object_vars($p->runners) as $r)
                if (@$r->visible === null && @$r->show_to_students !== null)
                    $r->visible = $r->show_to_students;
            foreach (get_object_vars($p->grades) as $ge)
                if (@$ge->is_extra) {
                    $p->has_extra = true;
                    break;
                }

            // diffs
            if (!isset($p->diffs))
                $p->diffs = (object) array();
            if (!is_object($p->diffs))
                Multiconference::fail_message("`psets.json` error: {$pk}->diffs should be an object");
            foreach (get_object_vars($p->diffs) as $k => $v)
                if (!is_object($v))
                    Multiconference::fail_message("`psets.json` error: {$pk}->diffs->$k should be an object");

            // handout_repo_url
            if (!is_string(@$p->handout_repo_url))
                Multiconference::fail_message("`psets.json` error: {$pk}->handout_repo_url must exist and be a string");
        }

    // read message data
    if (!@$PsetInfo->_messagedefs)
        $PsetInfo->_messagedefs = (object) array();
    if (!@$PsetInfo->_messagedefs->SYSTEAM)
        $PsetInfo->_messagedefs->SYSTEAM = "cs61-staff";
    foreach ($PsetInfo->_messagedefs as $k => $v)
        Messages::$main->define($k, $v);

    // also create log/ and repo/ directories
    foreach (array("$ConfSitePATH/log", "$ConfSitePATH/repo") as $d) {
        if (!is_dir($d) && !mkdir($d, 02770, true)) {
            $e = error_get_last();
            Multiconference::fail_message("`$d` missing and cannot be created (" . $e["message"] . ").");
        }
        if (!file_exists("$d/.htaccess")
            && ($x = file_get_contents("$ConfSitePATH/src/.htaccess")) !== false
            && file_put_contents("$d/.htaccess", $x) != strlen($x))
            Multiconference::fail_message("Error creating `$d/.htaccess`");
    }
}

load_pset_info();

putenv("GIT_SSH=$ConfSitePATH/src/gitssh");
putenv("GITSSH_CONFIG=$ConfSitePATH/conf/gitssh_config");
putenv("GITSSH_REPOCACHE=$ConfSitePATH/repo");
if (isset($Opt["mysql"]))
    putenv("MYSQL=" . $Opt["mysql"]);
