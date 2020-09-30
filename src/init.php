<?php
// init.php -- HotCRP initialization (test or site)
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

define("TAG_REGEX", '~?~?[a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*');
define("TAG_REGEX_OPTVALUE", '~?~?[a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*([#=](-\d)?\d*)?');
define("TAG_MAXLEN", 40);

define("CAPTYPE_RESETPASSWORD", 1);

// see also forward_pset_links
define("LINK_PARTNER", 1);
define("LINK_BACKPARTNER", 2);
define("LINK_REPO", 3);         // see also gitfetch
define("LINK_REPOVIEW", 4);
define("LINK_BRANCH", 5);

define("HASNOTES_GRADE", 1);
define("HASNOTES_COMMENT", 2);
define("HASNOTES_ANY", 3);

global $OK;
$OK = 1;


require_once("siteloader.php");
require_once(SiteLoader::find("lib/navigation.php"));
require_once(SiteLoader::find("lib/base.php"));
require_once(SiteLoader::find("lib/redirect.php"));
require_once(SiteLoader::find("lib/dbl.php"));
require_once(SiteLoader::find("src/helpers.php"));
require_once(SiteLoader::find("src/conference.php"));
require_once(SiteLoader::find("src/contact.php"));
Conf::set_current_time(time());


// Set locale to C (so that, e.g., strtolower() on UTF-8 data doesn't explode)
setlocale(LC_COLLATE, "C");
setlocale(LC_CTYPE, "C");


// Set up conference options (also used in mailer.php)

function read_included_options(&$files) {
    global $Opt;
    if (is_string($files)) {
        $files = [$files];
    }
    for ($i = 0; $i != count($files); ++$i) {
        foreach (SiteLoader::expand_includes($files[$i]) as $f)
            if (!@include $f)
                $Opt["missing"][] = $f;
    }
}

function expand_json_includes_callback($includelist, $callback, $extra_arg = null, $no_validate = false) {
    $includes = [];
    foreach (is_array($includelist) ? $includelist : [$includelist] as $k => $str) {
        $expandable = null;
        if (is_string($str)) {
            if (str_starts_with($str, "@")) {
                $expandable = substr($str, 1);
            } else if (!preg_match('/\A[\s\[\{]/', $str)) {
                $expandable = $str;
            }
        }
        if ($expandable) {
            foreach (SiteLoader::expand_includes($expandable) as $f) {
                if (($x = file_get_contents($f)))
                    $includes[] = [$x, $f];
            }
        } else {
            $includes[] = [$str, "entry $k"];
        }
    }
    foreach ($includes as $xentry) {
        list($entry, $landmark) = $xentry;
        if (is_string($entry)) {
            if (($x = json_decode($entry)) !== false) {
                $entry = $x;
            } else {
                if (json_last_error()) {
                    Json::decode($entry);
                    error_log("$landmark: Invalid JSON. " . Json::last_error_msg());
                }
                continue;
            }
        }
        if (is_object($entry)
            && !$no_validate
            && !isset($entry->id)
            && !isset($entry->factory)
            && !isset($entry->factory_class)
            && !isset($entry->callback)) {
            $entry = get_object_vars($entry);
        }
        foreach (is_array($entry) ? $entry : [$entry] as $obj) {
            if ((!is_object($obj) && !$no_validate) || !call_user_func($callback, $obj, $extra_arg))
                error_log("$landmark: Invalid expansion " . json_encode($obj) . ".");
        }
    }
}

global $Opt;
if (!$Opt) {
    $Opt = array();
}
if (!get($Opt, "loaded")) {
    if (defined("HOTCRP_OPTIONS")) {
        if ((@include HOTCRP_OPTIONS) !== false)
            $Opt["loaded"] = true;
    } else if ((@include "$ConfSitePATH/conf/options.php") !== false
               || (@include "$ConfSitePATH/conf/options.inc") !== false
               || (@include "$ConfSitePATH/Code/options.inc") !== false) {
        $Opt["loaded"] = true;
    }
    if (get($Opt, "multiconference")) {
        Multiconference::init();
    }
    if (get($Opt, "include")) {
        read_included_options($Opt["include"]);
    }
}
if (!get($Opt, "loaded") || get($Opt, "missing")) {
    Multiconference::fail_bad_options();
}
if (get($Opt, "dbLogQueries")) {
    Dbl::log_queries($Opt["dbLogQueries"]);
}


// Allow lots of memory
if (!get($Opt, "memoryLimit") && ini_get_bytes("memory_limit") < (128 << 20)) {
    $Opt["memoryLimit"] = "128M";
}
if (get($Opt, "memoryLimit")) {
    ini_set("memory_limit", $Opt["memoryLimit"]);
}


// Create the conference
global $Conf;
if (!Conf::$main) {
    Conf::set_main_instance(new Conf($Opt, true));
}
if (!$Conf->dblink) {
    Multiconference::fail_bad_database();
}


// Extract problem set information
function psets_json_data($exclude_overrides, &$mtime) {
    global $Conf;
    $datamap = array();
    $fnames = SiteLoader::expand_includes($Conf->opt("psetsConfig"),
                              ["CONFID" => $Conf->opt("confid") ? : $Conf->dbname,
                               "HOSTTYPE" => $Conf->opt("hostType") ?? ""]);
    foreach ($fnames as $fname) {
        $datamap[$fname] = @file_get_contents($fname);
        $mtime = max($mtime, @filemtime($fname));
    }
    if (!$exclude_overrides
        && ($override_data = $Conf->setting_data("psets_override"))) {
        $datamap["<overrides>"] = $override_data;
        $mtime = max($mtime, $Conf->setting("psets_override"));
    }
    return $datamap;
}

function load_psets_json($exclude_overrides) {
    $mtime = 0;
    $datamap = psets_json_data($exclude_overrides, $mtime);
    if (empty($datamap)) {
        Multiconference::fail_message("\$Opt[\"psetsConfig\"] is not set correctly.");
    }
    $json = (object) array("_defaults" => (object) array());
    foreach ($datamap as $fname => $data) {
        if ($data === false) {
            Multiconference::fail_message("$fname: Required configuration file cannot be read.");
        }
        $x = json_decode($data);
        if (!$x) {
            Json::decode($data); // our JSON decoder provides error positions
            Multiconference::fail_message("$fname: Invalid JSON. " . Json::last_error_msg());
        } else if (!is_object($x)) {
            Multiconference::fail_message("$fname: Not a JSON object.");
        }
        object_replace_recursive($json, $x);
    }
    $json->_defaults->config_signature = md5(json_encode(array_keys($datamap)) . $mtime);
    $json->_defaults->config_mtime = $mtime;
    return $json;
}

function load_pset_info() {
    global $ConfSitePATH, $Conf, $PsetInfo, $PsetOverrides;
    // read initial messages
    Messages::$main = new Messages;
    $x = json_decode(file_get_contents("$ConfSitePATH/src/messages.json"));
    foreach ($x as $j) {
        Messages::$main->add($j);
    }

    // read psets
    $PsetInfo = load_psets_json(false);

    // parse psets
    foreach ($PsetInfo as $pk => $p) {
        if (!is_object($p) || !isset($p->psetid)) {
            continue;
        }
        object_merge_recursive($p, $PsetInfo->_defaults);

        try {
            $pset = new Pset($Conf, $pk, $p);
            $Conf->register_pset($pset);
        } catch (Exception $exception) {
            // Want to give a good error message, so discover where the error is.
            // - create pset landmark object
            $locinfo = (object) array();
            $mtime = 0;
            foreach (psets_json_data(false, $mtime) as $fname => $data) {
                $x = Json::decode_landmarks($data, $fname);
                object_replace_recursive($locinfo, $x);
            }
            $locp = $locinfo->$pk;
            if (isset($locinfo->_defaults)) {
                object_merge_recursive($locp, $locinfo->_defaults);
            }
            // - lookup exception path in landmark object
            $path = $exception instanceof PsetConfigException ? $exception->path : array();
            for ($pathpos = 0; $pathpos < count($path) && $locp && !is_string($locp); ++$pathpos) {
                $component = $path[$pathpos];
                $locp = is_array($locp) ? $locp[$component] : $locp->$component;
            }
            // - report error
            if (is_object($locp) && get($locp, "__LANDMARK__")) {
                $locp = $locp->__LANDMARK__;
            } else if (!is_string($locp)) {
                $locp = $locinfo->$pk->__LANDMARK__;
            }
            Multiconference::fail_message($locp . ": Configuration error: " . $exception->getMessage());
        }
    }

    // read message data
    if ($PsetInfo->_defaults->main_branch ?? null) {
        $Conf->set_default_main_branch($PsetInfo->_defaults->main_branch);
    }
    if (!($PsetInfo->_messagedefs ?? null)) {
        $PsetInfo->_messagedefs = (object) array();
    }
    if (!($PsetInfo->_messagedefs->SYSTEAM ?? null)) {
        $PsetInfo->_messagedefs->SYSTEAM = "cs61-staff";
    }
    foreach ($PsetInfo->_messagedefs as $k => $v) {
        Messages::$main->define($k, $v);
    }

    // also create log/ and repo/ directories
    foreach (["$ConfSitePATH/log", "$ConfSitePATH/repo"] as $d) {
        if (!is_dir($d) && !mkdir($d, 02770, true)) {
            $e = error_get_last();
            Multiconference::fail_message("`$d` missing and cannot be created (" . $e["message"] . ").");
        }
        if (!file_exists("$d/.htaccess")
            && ($x = file_get_contents("$ConfSitePATH/src/.htaccess")) !== false
            && file_put_contents("$d/.htaccess", $x) != strlen($x)) {
            Multiconference::fail_message("Error creating `$d/.htaccess`");
        }
    }

    // if any anonymous problem sets, create anonymous usernames
    foreach ($Conf->psets() as $p) {
        if (!$p->disabled && $p->anonymous) {
            while (($row = Dbl::fetch_first_row(Dbl::qe("select contactId from ContactInfo where anon_username is null limit 1")))) {
                Dbl::q("update ContactInfo set anon_username='[anon" . sprintf("%08u", mt_rand(1, 99999999)) . "]' where contactId=?", $row[0]);
            }
        }
    }

    // update repository regrade requests
    if ($Conf->sversion == 107) {
        updateSchema($Conf);
    }
}

load_pset_info();

putenv("GIT_REPOCACHE=$ConfSitePATH/repo");
if ($Conf->opt("mysql") !== null) {
    putenv("MYSQL=" . $Conf->opt("mysql"));
}
if (!$Conf->opt("disableRemote")
    && !is_executable("$ConfSitePATH/jail/pa-timeout")) {
    $Conf->set_opt("disableRemote", "The `pa-timeout` program has not been built. Run `cd DIR/jail; make` to access remote repositories.");
}
