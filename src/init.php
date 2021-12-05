<?php
// init.php -- HotCRP initialization (test or site)
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

const TAG_REGEX_NOTWIDDLE = '[a-zA-Z@*_:.][-+a-zA-Z0-9?!@*_:.\/]*';
const TAG_MAXLEN = 40;

const CAPTYPE_RESETPASSWORD = 1;

// see also forward_pset_links
const LINK_PARTNER = 1;
const LINK_BACKPARTNER = 2;
const LINK_REPO = 3;         // see also gitfetch
const LINK_REPOVIEW = 4;
const LINK_BRANCH = 5;

const HASNOTES_GRADE = 1;
const HASNOTES_COMMENT = 2;
const HASNOTES_ANY = 3;

global $OK;
$OK = 1;


require_once("siteloader.php");
require_once(SiteLoader::find("lib/polyfills.php"));
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

function expand_json_includes_callback($includelist, $callback) {
    $includes = [];
    foreach (is_array($includelist) ? $includelist : [$includelist] as $k => $str) {
        $expandable = null;
        if (is_string($str)) {
            if (str_starts_with($str, "@")) {
                $expandable = substr($str, 1);
            } else if (!str_starts_with($str, "{")
                       && (!str_starts_with($str, "[") || !str_ends_with(rtrim($str), "]"))
                       && !ctype_space($str[0])) {
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
            $x = json_decode($entry);
            if ($x === null && json_last_error()) {
                $x = Json::decode($entry);
                if ($x === null) {
                    error_log("$landmark: Invalid JSON: " . Json::last_error_msg());
                }
            }
            $entry = $x;
        }
        foreach (is_array($entry) ? $entry : [$entry] as $k => $v) {
            if ($v === null || $v === false) {
                continue;
            }
            if (is_object($v)) {
                $v->__subposition = ++Conf::$next_xt_subposition;
            }
            if (!call_user_func($callback, $v, $k, $landmark)) {
                error_log((Conf::$main ? Conf::$main->dbname . ": " : "") . "$landmark: Invalid expansion " . json_encode($v) . "\n" . debug_string_backtrace());
            }
        }
    }
}

function read_main_options() {
    global $Opt;
    if (!$Opt) {
        $Opt = [];
    }
    if (!($Opt["loaded"] ?? false)) {
        if (defined("HOTCRP_OPTIONS")) {
            $options = HOTCRP_OPTIONS;
        } else {
            $options = SiteLoader::$root . "/conf/options.php";
        }
        if ((include $options) !== false) {
            $Opt["loaded"] = true;
        } else {
            $Opt["missing"][] = $options;
        }
        if ($Opt["multiconference"] ?? false) {
            Multiconference::init();
        }
        if ($Opt["include"] ?? false) {
            SiteLoader::read_included_options();
        }
    }
    if (!($Opt["loaded"] ?? false) || ($Opt["missing"] ?? false)) {
        Multiconference::fail_bad_options();
    }

    // Respond to main options
    if ($Opt["dbLogQueries"] ?? false) {
        Dbl::log_queries($Opt["dbLogQueries"]);
    }
    // Allow lots of memory
    if (!($Opt["memoryLimit"] ?? false) && ini_get_bytes("memory_limit") < (128 << 20)) {
        $Opt["memoryLimit"] = "128M";
    }
    if ($Opt["memoryLimit"] ?? false) {
        ini_set("memory_limit", $Opt["memoryLimit"]);
    }
}

read_main_options();


// Create the conference
global $Conf, $Opt;
if (!Conf::$main) {
    Conf::set_main_instance(new Conf($Opt, true));
}
if (!$Conf->dblink) {
    Multiconference::fail_bad_database();
}


// Extract problem set information
/** @return array<string,string> */
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

/** @return object */
function load_psets_json($exclude_overrides) {
    $mtime = 0;
    $datamap = psets_json_data($exclude_overrides, $mtime);
    if (empty($datamap)) {
        Multiconference::fail_message("\$Opt[\"psetsConfig\"] is not set correctly.");
    }
    $json = (object) ["_defaults" => (object) []];
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
    global $Conf, $PsetOverrides;
    // read initial messages
    Messages::$main = new Messages;
    $x = json_decode(file_get_contents(SiteLoader::$root . "/src/messages.json"));
    foreach ($x as $j) {
        Messages::$main->add($j);
    }

    // read psets
    try {
        $Conf->set_config(load_psets_json(false));
    } catch (Exception $exception) {
        // Want to give a good error message, so discover where the error is.
        if ($exception instanceof PsetConfigException
            && $exception->key) {
            // - create pset landmark object
            $locinfo = (object) [];
            $mtime = 0;
            foreach (psets_json_data(false, $mtime) as $fname => $data) {
                $x = Json::decode_landmarks($data, $fname);
                object_replace_recursive($locinfo, $x);
            }
            // - read location  information
            $locp = $locinfo->{$exception->key};
            if ($locp && isset($locp->group) && is_string($locp->group)) {
                $g = "_defaults_" . $locp->group;
                if (isset($locinfo->$g)) {
                    object_merge_recursive($locp, $locinfo->$g);
                }
            }
            if (isset($locinfo->_defaults)) {
                object_merge_recursive($locp, $locinfo->_defaults);
            }
            // - lookup exception path in landmark object
            foreach ($exception->path as $i => $component) {
                if ($locp && !is_string($locp)) {
                    $locp = is_array($locp) ? $locp[$component] : $locp->$component;
                }
            }
            // - report error
            if (is_object($locp) && ($locp->__LANDMARK__ ?? null)) {
                $locp = $locp->__LANDMARK__;
            } else if (!is_string($locp)) {
                $locp = $locinfo->{$exception->key}->__LANDMARK__;
            }
            $locp .= ": ";
        } else {
            $locp = "";
        }
        Multiconference::fail_message($locp . "Configuration error: " . $exception->getMessage());
    }

    foreach ($Conf->config->_messagedefs as $k => $v) {
        Messages::$main->define($k, $v);
    }

    // also create log/ and repo/ directories
    foreach ([SiteLoader::$root . "/log", SiteLoader::$root . "/repo"] as $d) {
        if (!is_dir($d) && !mkdir($d, 02770, true)) {
            $e = error_get_last();
            Multiconference::fail_message("`$d` missing and cannot be created (" . $e["message"] . ").");
        }
        if (!file_exists("$d/.htaccess")
            && ($x = file_get_contents(SiteLoader::$root . "/src/.htaccess")) !== false
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
            break;
        }
    }

    // update schema as required by psets
    if ($Conf->sversion === 107) {
        updateSchema($Conf);
    }
    if ($Conf->sversion === 138) {
        updateSchema($Conf);
    }
}

load_pset_info();

putenv("GIT_REPOCACHE=" . SiteLoader::$root . "/repo");
if ($Conf->opt("mysql") !== null) {
    putenv("MYSQL=" . $Conf->opt("mysql"));
}
if (!$Conf->opt("disableRemote")
    && !is_executable(SiteLoader::$root . "/jail/pa-timeout")) {
    $Conf->set_opt("disableRemote", "The `pa-timeout` program has not been built. Run `cd DIR/jail; make` to access remote repositories.");
}
