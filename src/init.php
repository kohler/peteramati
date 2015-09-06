<?php
// init.php -- HotCRP initialization (test or site)
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
                               "DiffInfo" => "src/diffinfo.php",
                               "DocumentHelper" => "lib/documenthelper.php",
                               "Ht" => "lib/ht.php",
                               "Json" => "lib/json.php",
                               "LoginHelper" => "lib/login.php",
                               "Mailer" => "lib/mailer.php",
                               "Messages" => "lib/messages.php",
                               "MimeText" => "lib/mailer.php",
                               "Mimetype" => "lib/mimetype.php",
                               "Multiconference" => "src/multiconference.php",
                               "Pset" => "src/psetconfig.php",
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
function load_pset_info() {
    global $ConfSitePATH, $PsetInfo, $Opt;
    // read initial messages
    Messages::$main = new Messages;
    $x = json_decode(file_get_contents("$ConfSitePATH/src/messages.json"));
    foreach ($x as $j)
        Messages::$main->add($j);

    // read psets
    $PsetInfo = (object) array();
    $psets = expand_includes($ConfSitePATH, $Opt["psetsConfig"],
                             array("CONFID" => @$Opt["confid"] ? : @$Opt["dbName"],
                                   "HOSTTYPE" => @$Opt["hostType"] ? : ""));
    if (!count($psets))
        Multiconference::fail_message("\$Opt[\"psetsConfig\"] is not set correctly.");
    foreach ($psets as $fname) {
        $data = file_get_contents($fname);
        if ($data === false)
            Multiconference::fail_message("$fname: Required configuration file cannot be read.");
        $x = json_decode($data);
        if (!$x) {
            Json::decode($data); // our JSON decoder provides error positions
            Multiconference::fail_message("$fname: Invalid JSON. " . Json::last_error_msg());
        } else if (!is_object($x))
            Multiconference::fail_message("$fname: Not a JSON object.");
        object_replace_recursive($PsetInfo, $x);
    }
    if (!isset($PsetInfo->_defaults))
        $PsetInfo->_defaults = (object) array();

    // parse psets
    foreach ($PsetInfo as $pk => $p) {
        if (!is_object($p) || !isset($p->psetid))
            continue;
        object_merge_recursive($p, $PsetInfo->_defaults);

        try {
            $pset = new Pset($pk, $p);
            Pset::register($pset);
        } catch (Exception $exception) {
            // Want to give a good error message, so discover where the error is
            // create pset landmark object
            $locinfo = (object) array();
            foreach ($psets as $fname) {
                $x = Json::decode_landmarks(file_get_contents($fname), $fname);
                object_replace_recursive($locinfo, $x);
            }
            $locp = $locinfo->$pk;
            if (isset($locinfo->_defaults))
                object_merge_recursive($locp, $locinfo->_defaults);
            $path = $exception instanceof PsetConfigException ? $exception->path : array();
            for ($pathpos = 0; $pathpos < count($path) && $locp && !is_string($locp); ++$pathpos) {
                $component = $path[$pathpos];
                $locp = is_array($locp) ? $locp[$component] : $locp->$component;
            }
            if (!is_string($locp))
                $locp = $locinfo->$pk->__LANDMARK__;
            Multiconference::fail_message($locp . ": Configuration error: " . $exception->getMessage());
        }
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
