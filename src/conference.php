<?php
// conference.php -- HotCRP central helper class (singleton)
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class APIData {
    public $conf;
    public $user;
    public $pset;
    public $repo;
    public $branch;
    public $hash;
    public $commit;
    public $at;
    function __construct(Contact $user, Pset $pset = null, Repository $repo = null) {
        global $Now;
        $this->conf = $user->conf;
        $this->user = $user;
        $this->pset = $pset;
        $this->repo = $repo;
        $this->at = $Now;
    }
    function prepare_grading_commit($info) {
        if (!$this->pset->gitless_grades) {
            if (!$this->repo)
                return ["ok" => false, "error" => "Missing repository."];
            $this->commit = $this->conf->check_api_hash($this->hash, $this);
            if (!$this->commit)
                return ["ok" => false, "error" => ($this->hash ? "Disconnected commit." : "Missing commit.")];
            $info->force_set_hash($this->commit->hash);
        }
        return false;
    }
}

class Conf {
    public $dblink = null;

    private $settings;
    private $settingTexts;
    public $sversion;
    private $_gsettings = [];
    private $_gsettings_data = [];
    private $_gsettings_loaded = [];

    public $dbname;
    public $dsn = null;

    public $short_name;
    public $long_name;
    public $download_prefix;
    public $sort_by_last;
    public $opt;
    public $opt_override = null;
    public $default_main_branch;

    public $validate_timeout;
    public $validate_overall_timeout;
    public $default_format;

    private $save_messages = true;
    var $headerPrinted = false;
    private $_save_logs = false;
    private $_session_list = false;
    public $_session_handler;

    private $usertimeId = 1;

    private $_psets = [];
    private $_psets_by_urlkey = [];
    private $_psets_sorted = false;
    private $_group_weights = [];
    private $_group_weight_defaults = [];
    private $_grouped_psets;
    private $_group_has_extra;

    private $_date_format_initialized = false;
    private $_pc_members_cache = null;
    private $_pc_tags_cache = null;
    private $_pc_members_and_admins_cache = null;
    private $_handout_repos = [];
    private $_handout_commits = [];
    private $_handout_latest_commit = [];
    private $_api_map = null;
    private $_repository_site_classes = null;
    private $_branch_map;
    const USERNAME_GITHUB = 1;
    const USERNAME_HARVARDSEAS = 2;
    const USERNAME_EMAIL = 4;
    const USERNAME_HUID = 8;
    const USERNAME_USERNAME = 16;
    private $_username_classes = 0;

    static public $g = null;

    static public $hoturl_defaults = null;

    function __construct($options, $make_dsn) {
        // unpack dsn, connect to database, load current settings
        if ($make_dsn && ($this->dsn = Dbl::make_dsn($options))) {
            list($this->dblink, $options["dbName"]) = Dbl::connect_dsn($this->dsn);
        }
        if (!isset($options["confid"])) {
            $options["confid"] = get($options, "dbName");
        }
        $this->opt = $options;
        $this->dbname = $options["dbName"];
        if ($this->dblink && !Dbl::$default_dblink) {
            Dbl::set_default_dblink($this->dblink);
            Dbl::set_error_handler(array($this, "query_error_handler"));
        }
        if ($this->dblink) {
            Dbl::$landmark_sanitizer = "/^(?:Dbl::|Conf::q|call_user_func)/";
            $this->load_settings();
        } else {
            $this->crosscheck_options();
        }
    }


    //
    // Initialization functions
    //

    function load_settings() {
        global $Now;

        // load settings from database
        $this->settings = [];
        $this->settingTexts = [];
        foreach ($this->opt_override ? : [] as $k => $v) {
            if ($v === null)
                unset($this->opt[$k]);
            else
                $this->opt[$k] = $v;
        }
        $this->opt_override = [];

        $this->_pc_seeall_cache = null;

        $result = $this->q_raw("select name, value, data from Settings");
        while ($result && ($row = $result->fetch_row())) {
            $this->settings[$row[0]] = (int) $row[1];
            if ($row[2] !== null)
                $this->settingTexts[$row[0]] = $row[2];
            if (substr($row[0], 0, 4) == "opt.") {
                $okey = substr($row[0], 4);
                $this->opt_override[$okey] = get($this->opt, $okey);
                $this->opt[$okey] = ($row[2] === null ? (int) $row[1] : $row[2]);
            }
        }
        Dbl::free($result);

        // update schema
        $this->sversion = $this->settings["allowPaperOption"];
        if ($this->sversion < 132) {
            require_once("updateschema.php");
            $old_nerrors = Dbl::$nerrors;
            updateSchema($this);
            Dbl::$nerrors = $old_nerrors;
        }

        // invalidate all caches after loading from backup
        if (isset($this->settings["frombackup"])
            && $this->invalidate_caches()) {
            $this->qe_raw("delete from Settings where name='frombackup' and value=" . $this->settings["frombackup"]);
            unset($this->settings["frombackup"]);
        } else
            $this->invalidate_caches(["rf" => true]);

        // update options
        if (isset($this->opt["ldapLogin"]) && !$this->opt["ldapLogin"])
            unset($this->opt["ldapLogin"]);
        if (isset($this->opt["httpAuthLogin"]) && !$this->opt["httpAuthLogin"])
            unset($this->opt["httpAuthLogin"]);

        // set conferenceKey
        if (!isset($this->opt["conferenceKey"])) {
            if (!isset($this->settingTexts["conf_key"])
                && ($key = random_bytes(32)) !== false)
                $this->save_setting("conf_key", 1, $key);
            $this->opt["conferenceKey"] = get($this->settingTexts, "conf_key", "");
        }

        // set capability key
        if (!get($this->settings, "cap_key")
            && !get($this->opt, "disableCapabilities")
            && !(($key = random_bytes(16)) !== false
                 && ($key = base64_encode($key))
                 && $this->save_setting("cap_key", 1, $key)))
            $this->opt["disableCapabilities"] = true;

        // GC old capabilities
        if (defval($this->settings, "__capability_gc", 0) < $Now - 86400) {
            foreach (array($this->dblink, Contact::contactdb()) as $db)
                if ($db)
                    Dbl::ql($db, "delete from Capability where timeExpires>0 and timeExpires<$Now");
            $this->q_raw("insert into Settings (name, value) values ('__capability_gc', $Now) on duplicate key update value=values(value)");
            $this->settings["__capability_gc"] = $Now;
        }

        $this->crosscheck_settings();
        $this->crosscheck_options();
    }

    private function crosscheck_settings() {
    }

    private function crosscheck_options() {
        global $ConfSitePATH;

        // set longName, downloadPrefix, etc.
        $confid = $this->opt["confid"];
        if ((!isset($this->opt["longName"]) || $this->opt["longName"] == "")
            && (!isset($this->opt["shortName"]) || $this->opt["shortName"] == "")) {
            $this->opt["shortNameDefaulted"] = true;
            $this->opt["longName"] = $this->opt["shortName"] = $confid;
        } else if (!isset($this->opt["longName"]) || $this->opt["longName"] == "")
            $this->opt["longName"] = $this->opt["shortName"];
        else if (!isset($this->opt["shortName"]) || $this->opt["shortName"] == "")
            $this->opt["shortName"] = $this->opt["longName"];
        if (!isset($this->opt["downloadPrefix"]) || $this->opt["downloadPrefix"] == "")
            $this->opt["downloadPrefix"] = $confid . "-";
        $this->short_name = $this->opt["shortName"];
        $this->long_name = $this->opt["longName"];

        // expand ${confid}, ${confshortname}
        foreach (array("sessionName", "downloadPrefix", "conferenceSite",
                       "paperSite", "defaultPaperSite", "contactName",
                       "contactEmail", "docstore") as $k)
            if (isset($this->opt[$k]) && is_string($this->opt[$k])
                && strpos($this->opt[$k], "$") !== false) {
                $this->opt[$k] = preg_replace(',\$\{confid\}|\$confid\b,', $confid, $this->opt[$k]);
                $this->opt[$k] = preg_replace(',\$\{confshortname\}|\$confshortname\b,', $this->short_name, $this->opt[$k]);
            }
        $this->download_prefix = $this->opt["downloadPrefix"];

        foreach (array("emailFrom", "emailSender", "emailCc", "emailReplyTo") as $k)
            if (isset($this->opt[$k]) && is_string($this->opt[$k])
                && strpos($this->opt[$k], "$") !== false) {
                $this->opt[$k] = preg_replace(',\$\{confid\}|\$confid\b,', $confid, $this->opt[$k]);
                if (strpos($this->opt[$k], "confshortname") !== false) {
                    $v = rfc2822_words_quote($this->short_name);
                    if ($v[0] === "\"" && strpos($this->opt[$k], "\"") !== false)
                        $v = substr($v, 1, strlen($v) - 2);
                    $this->opt[$k] = preg_replace(',\$\{confshortname\}|\$confshortname\b,', $v, $this->opt[$k]);
                }
            }

        // remove final slash from $Opt["paperSite"]
        if (!isset($this->opt["paperSite"]) || $this->opt["paperSite"] === "") {
            $this->opt["paperSite"] = Navigation::base_absolute();
        }
        if ($this->opt["paperSite"] == "" && isset($this->opt["defaultPaperSite"])) {
            $this->opt["paperSite"] = $this->opt["defaultPaperSite"];
        }
        while (str_ends_with($this->opt["paperSite"], "/")) {
            $this->opt["paperSite"] = substr($this->opt["paperSite"], 0, -1);
        }

        // option name updates (backwards compatibility)
        foreach (array("assetsURL" => "assetsUrl",
                       "jqueryURL" => "jqueryUrl", "jqueryCDN" => "jqueryCdn",
                       "disableCSV" => "disableCsv") as $kold => $knew)
            if (isset($this->opt[$kold]) && !isset($this->opt[$knew]))
                $this->opt[$knew] = $this->opt[$kold];

        // set assetsUrl and scriptAssetsUrl
        if (!isset($this->opt["scriptAssetsUrl"]) && isset($_SERVER["HTTP_USER_AGENT"])
            && strpos($_SERVER["HTTP_USER_AGENT"], "MSIE") !== false)
            $this->opt["scriptAssetsUrl"] = Navigation::siteurl();
        if (!isset($this->opt["assetsUrl"]))
            $this->opt["assetsUrl"] = Navigation::siteurl();
        if ($this->opt["assetsUrl"] !== "" && !str_ends_with($this->opt["assetsUrl"], "/"))
            $this->opt["assetsUrl"] .= "/";
        if (!isset($this->opt["scriptAssetsUrl"]))
            $this->opt["scriptAssetsUrl"] = $this->opt["assetsUrl"];
        Ht::$img_base = $this->opt["assetsUrl"] . "images/";

        // set docstore
        if (get($this->opt, "docstore") === true)
            $this->opt["docstore"] = "docs";
        else if (!get($this->opt, "docstore") && get($this->opt, "filestore")) { // backwards compat
            $this->opt["docstore"] = $this->opt["filestore"];
            if ($this->opt["docstore"] === true)
                $this->opt["docstore"] = "filestore";
            $this->opt["docstoreSubdir"] = get($this->opt, "filestoreSubdir");
        }
        if (get($this->opt, "docstore") && $this->opt["docstore"][0] !== "/")
            $this->opt["docstore"] = $ConfSitePATH . "/" . $this->opt["docstore"];
        $this->_docstore = false;
        if (($fdir = get($this->opt, "docstore"))) {
            $fpath = $fdir;
            $use_subdir = get($this->opt, "docstoreSubdir");
            if ($use_subdir && ($use_subdir === true || $use_subdir > 0))
                $fpath .= "/%" . ($use_subdir === true ? 2 : $use_subdir) . "h";
            $this->_docstore = [$fdir, $fpath . "/%h%x"];
        }

        // handle timezone
        if (function_exists("date_default_timezone_set")) {
            if (isset($this->opt["timezone"])) {
                if (!date_default_timezone_set($this->opt["timezone"])) {
                    self::msg_error("Timezone option “" . htmlspecialchars($this->opt["timezone"]) . "” is invalid; falling back to “America/New_York”.");
                    date_default_timezone_set("America/New_York");
                }
            } else if (!ini_get("date.timezone") && !getenv("TZ"))
                date_default_timezone_set("America/New_York");
        }
        $this->_date_format_initialized = false;

        // set safePasswords
        if (!get($this->opt, "safePasswords")
            || (is_int($this->opt["safePasswords"]) && $this->opt["safePasswords"] < 1))
            $this->opt["safePasswords"] = 0;
        else if ($this->opt["safePasswords"] === true)
            $this->opt["safePasswords"] = 1;
        if (!isset($this->opt["contactdb_safePasswords"]))
            $this->opt["contactdb_safePasswords"] = $this->opt["safePasswords"];

        // set validate timeouts
        $this->validate_timeout = (float) get($this->opt, "validateTimeout", 5);
        if ($this->validate_timeout <= 0)
            $this->validate_timeout = 5;
        $this->validate_overall_timeout = (float) get($this->opt, "validateOverallTimeout", 15);
        if ($this->validate_overall_timeout <= 0)
            $this->validate_overall_timeout = 5;

        // repository site classes
        $this->_repository_site_classes = ["github"];
        if (isset($this->opt["repositorySites"])) {
            $x = $this->opt["repositorySites"];
            if (is_array($x) || (is_string($x) && ($x = json_decode($x)) && is_array($x)))
                $this->_repository_site_classes = $x;
        }
        $this->_username_classes = 0;
        if (in_array("github", $this->_repository_site_classes))
            $this->_username_classes |= self::USERNAME_GITHUB;
        if (in_array("harvardseas", $this->_repository_site_classes))
            $this->_username_classes |= self::USERNAME_HARVARDSEAS;

        $sort_by_last = !!get($this->opt, "sortByLastName");
        if (!$this->sort_by_last != !$sort_by_last)
            $this->_pc_members_cache = $this->_pc_members_and_admins_cache = null;
        $this->sort_by_last = $sort_by_last;
        $this->default_format = (int) get($this->opt, "defaultFormat");
        $this->_api_map = null;
    }

    function set_default_main_branch($b) {
        $this->default_main_branch = $b;
    }


    function has_setting($name) {
        return isset($this->settings[$name]);
    }

    function setting($name, $defval = null) {
        return get($this->settings, $name, $defval);
    }

    function setting_data($name, $defval = false) {
        $x = get($this->settingTexts, $name, $defval);
        if ($x && is_object($x) && isset($this->settingTexts[$name]))
            $x = $this->settingTexts[$name] = json_encode_db($x);
        return $x;
    }

    function setting_json($name, $defval = false) {
        $x = get($this->settingTexts, $name, $defval);
        if ($x && is_string($x) && isset($this->settingTexts[$name])
            && is_object(($x = json_decode($x))))
            $this->settingTexts[$name] = $x;
        return $x;
    }

    private function __save_setting($name, $value, $data = null) {
        $change = false;
        if ($value === null && $data === null) {
            if ($this->qe("delete from Settings where name=?", $name)) {
                unset($this->settings[$name], $this->settingTexts[$name]);
                $change = true;
            }
        } else {
            $value = (int) $value;
            $dval = $data;
            if (is_array($dval) || is_object($dval))
                $dval = json_encode_db($dval);
            if ($this->qe("insert into Settings set name=?, value=?, data=? on duplicate key update value=values(value), data=values(data)", $name, $value, $dval)) {
                $this->settings[$name] = $value;
                $this->settingTexts[$name] = $data;
                $change = true;
            }
        }
        if ($change && str_starts_with($name, "opt.")) {
            $oname = substr($name, 4);
            if ($value === null && $data === null)
                $this->opt[$oname] = get($this->opt_override, $oname);
            else
                $this->opt[$oname] = $data === null ? $value : $data;
        }
        return $change;
    }

    function save_setting($name, $value, $data = null) {
        $change = $this->__save_setting($name, $value, $data);
        if ($change) {
            $this->crosscheck_settings();
            if (str_starts_with($name, "opt."))
                $this->crosscheck_options();
        }
        return $change;
    }


    function load_gsetting($name) {
        if ($name === null || $name === "") {
            $this->_gsettings_loaded = true;
            $filter = function ($k) { return false; };
            $where = "true";
            $qv = [];
        } else if (($dot = strpos($name, ".")) === false) {
            if ($this->_gsettings_loaded !== true)
                $this->_gsettings_loaded[$name] = true;
            $filter = function ($k) use ($name) {
                return substr($k, 0, strlen($name)) !== $name
                    || ($k !== $name && $k[strlen($name)] !== ".");
            };
            $where = "name=? or (name>=? and name<=?)";
            $qv = [$name, $name . ".", $name . "/"];
        } else {
            if ($this->_gsettings_loaded !== true)
                $this->_gsettings_loaded[$name] = true;
            $filter = function ($k) use ($name) {
                return $k !== $name;
            };
            $where = "name=?";
            $qv = [$name];
        }
        $this->_gsettings = array_filter($this->_gsettings, $filter, ARRAY_FILTER_USE_KEY);
        $this->_gsettings_data = array_filter($this->_gsettings_data, $filter, ARRAY_FILTER_USE_KEY);
        $result = $this->qe_apply("select name, value, `data`, dataOverflow from GroupSettings where $where", $qv);
        while (($row = $result->fetch_row())) {
            $this->_gsettings[$row[0]] = (int) $row[1];
            $this->_gsettings_data[$row[0]] = isset($row[3]) ? $row[3] : $row[2];
        }
        Dbl::free($result);
    }

    function ensure_gsetting($name) {
        if ($this->_gsettings_loaded !== true
            && !isset($this->_gsettings_loaded[$name])
            && (($dot = strpos($name, ".")) === false
                || !isset($this->_gsettings_loaded[substr($name, 0, $dot)]))) {
            $this->load_gsetting($name);
        }
    }

    function gsetting($name, $defval = null) {
        $this->ensure_gsetting($name);
        return isset($this->_gsettings[$name]) ? $this->_gsettings[$name] : $defval;
    }

    function gsetting_data($name, $defval = false) {
        $this->ensure_gsetting($name);
        $x = get($this->_gsettings_data, $name, $defval);
        if ($x && is_object($x) && isset($this->_gsettings_data[$name]))
            $x = $this->_gsettings_data[$name] = json_encode_db($x);
        return $x;
    }

    function gsetting_json($name, $defval = false) {
        $this->ensure_gsetting($name);
        $x = get($this->_gsettings_data, $name, $defval);
        if ($x && is_string($x) && isset($this->_gsettings_data[$name])
            && is_object(($x = json_decode($x))))
            $this->_gsettings_data[$name] = $x;
        return $x;
    }

    function save_gsetting($name, $value, $data = null) {
        $change = false;
        if ($value === null && $data === null) {
            if ($this->qe("delete from GroupSettings where name=?", $name)) {
                unset($this->_gsettings[$name], $this->_gsettings_data[$name]);
                $change = true;
            }
        } else {
            $value = (int) $value;
            $dval = $data;
            if (is_array($dval) || is_object($dval))
                $dval = json_encode_db($dval);
            if (strlen($dval) > 32700) {
                $odval = $dval;
                $dval = null;
            } else {
                $odval = null;
            }
            if ($this->qe("insert into GroupSettings set name=?, value=?, data=?, dataOverflow=? on duplicate key update value=values(value), data=values(data), dataOverflow=values(dataOverflow)", $name, $value, $dval, $odval)) {
                $this->_gsettings[$name] = $value;
                $this->_gsettings_data[$name] = isset($odval) ? $odval : $dval;
                $change = true;
            }
        }
        if ($this->_gsettings_loaded !== true)
            $this->_gsettings_loaded[$name] = true;
        return $change;
    }


    function opt($name, $defval = null) {
        return get($this->opt, $name, $defval);
    }

    function set_opt($name, $value) {
        global $Opt;
        $Opt[$name] = $this->opt[$name] = $value;
    }

    function unset_opt($name) {
        global $Opt;
        unset($Opt[$name], $this->opt[$name]);
    }


    // database

    function q(/* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), 0);
    }
    function q_raw(/* $qstr */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_RAW);
    }
    function q_apply(/* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_APPLY);
    }

    function ql(/* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_LOG);
    }
    function ql_raw(/* $qstr */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_RAW | Dbl::F_LOG);
    }
    function ql_apply(/* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_APPLY | Dbl::F_LOG);
    }

    function qe(/* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR);
    }
    function qe_raw(/* $qstr */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_RAW | Dbl::F_ERROR);
    }
    function qe_apply(/* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_APPLY | Dbl::F_ERROR);
    }

    function qx(/* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ALLOWERROR);
    }
    function qx_raw(/* $qstr */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_RAW | Dbl::F_ALLOWERROR);
    }
    function qx_apply(/* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_APPLY | Dbl::F_ALLOWERROR);
    }

    function fetch_rows(/* $qstr, ... */) {
        return Dbl::fetch_rows(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }
    function fetch_value(/* $qstr, ... */) {
        return Dbl::fetch_value(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }
    function fetch_ivalue(/* $qstr, ... */) {
        return Dbl::fetch_ivalue(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }

    function db_error_html($getdb = true, $while = "") {
        $text = "<p>Database error";
        if ($while)
            $text .= " $while";
        if ($getdb)
            $text .= ": " . htmlspecialchars($this->dblink->error);
        return $text . "</p>";
    }

    function db_error_text($getdb = true, $while = "") {
        $text = "Database error";
        if ($while)
            $text .= " $while";
        if ($getdb)
            $text .= ": " . $this->dblink->error;
        return $text;
    }

    function query_error_handler($dblink, $query) {
        $landmark = caller_landmark(1, "/^(?:Dbl::|Conf::q|call_user_func)/");
        if (PHP_SAPI == "cli")
            fwrite(STDERR, "$landmark: database error: $dblink->error in $query\n");
        else {
            error_log("$landmark: database error: $dblink->error in $query");
            self::msg_error("<p>" . htmlspecialchars($landmark) . ": database error: " . htmlspecialchars($this->dblink->error) . " in " . Ht::pre_text_wrap($query) . "</p>");
        }
    }


    // name

    function full_name() {
        if ($this->short_name && $this->short_name != $this->long_name)
            return $this->long_name . " (" . $this->short_name . ")";
        else
            return $this->long_name;
    }


    // documents

    function docstore() {
        return $this->_docstore;
    }


    // users

    function external_login() {
        return isset($this->opt["ldapLogin"]) || isset($this->opt["httpAuthLogin"]);
    }

    function site_contact() {
        $contactEmail = $this->opt("contactEmail");
        if (!$contactEmail || $contactEmail == "you@example.com") {
            $result = $this->ql("select firstName, lastName, email from ContactInfo where (roles&" . (Contact::ROLE_CHAIR | Contact::ROLE_ADMIN) . ")!=0 order by (roles&" . Contact::ROLE_CHAIR . ") desc limit 1");
            if ($result && ($row = $result->fetch_object())) {
                $this->set_opt("defaultSiteContact", true);
                $this->set_opt("contactName", Text::name_text($row));
                $this->set_opt("contactEmail", $row->email);
            }
            Dbl::free($result);
        }
        return new Contact((object) array("fullName" => $this->opt["contactName"],
                                          "email" => $this->opt["contactEmail"],
                                          "isChair" => true,
                                          "isPC" => true,
                                          "is_site_contact" => true,
                                          "contactTags" => null), $this);
    }

    function user_by_id($id) {
        $result = $this->qe("select ContactInfo.* from ContactInfo where contactId=?", $id);
        $acct = Contact::fetch($result, $this);
        Dbl::free($result);
        return $acct;
    }

    function user_by_email($email) {
        $acct = null;
        if (($email = trim((string) $email)) !== "") {
            $result = $this->qe("select * from ContactInfo where email=?", $email);
            $acct = Contact::fetch($result, $this);
            Dbl::free($result);
        }
        return $acct;
    }

    function user_by_query($qpart, $args) {
        $result = $this->qe_apply("select ContactInfo.* from ContactInfo where $qpart", $args);
        $acct = Contact::fetch($result, $this);
        Dbl::free($result);
        return $acct && $acct->contactId ? $acct : null;
    }

    function user_by_whatever($whatever, $types = 0) {
        if ($types === 0) {
            $types = $this->_username_classes | self::USERNAME_HUID | self::USERNAME_EMAIL;
        } else if ($types & self::USERNAME_USERNAME) {
            $types |= $this->_username_classes;
        }
        $q = $qv = [];
        $whatever = trim($whatever);
        $user_type = 0;
        if ($whatever === "") {
            return null;
        } else if (str_starts_with($whatever, "[anon")) {
            $q[] = "anon_username=?";
            $qv[] = $whatever;
            $user_type = 1;
        } else if (strpos($whatever, "@") === false) {
            if ($types & self::USERNAME_GITHUB) {
                $q[] = "github_username=" . Dbl::utf8ci("?");
                $qv[] = $whatever;
            }
            if ($types & self::USERNAME_HARVARDSEAS) {
                $q[] = "seascode_username=?";
                $qv[] = $whatever;
            }
            if (($types & self::USERNAME_HUID) && ctype_digit($whatever)) {
                $q[] = "huid=?";
                $qv[] = $whatever;
            }
            $user_type = 2;
        } else if ($types & self::USERNAME_EMAIL) {
            if (str_ends_with($whatever, "@*")) {
                $q[] = "email like '" . sqlq_for_like(substr($whatever, 0, -1)) . "%'";
            } else {
                $q[] = "email=?";
                $qv[] = $whatever;
            }
        }
        if (empty($q)) {
            return null;
        }
        $result = $this->qe_apply("select * from ContactInfo where " . join(" or ", $q), $qv);
        $users = [];
        while (($user = Contact::fetch($result))) {
            $users[] = $user;
        }
        Dbl::free($result);
        if (empty($users) && $user_type === 2 && ($types & self::USERNAME_EMAIL)) {
            return $this->user_by_whatever($whatever . "@*", self::USERNAME_EMAIL);
        } else if (count($users) > 1 && $user_type === 2) {
            $users = array_filter($users, function ($u) use ($whatever) {
                return $u->huid !== $whatever;
            });
        }
        if (count($users) === 1) {
            if ($user_type === 1) {
                $users[0]->set_anonymous(true);
            }
            return $users[0];
        } else {
            return null;
        }
    }

    function user_id_by_email($email) {
        $result = $this->qe("select contactId from ContactInfo where email=?", trim($email));
        $row = edb_row($result);
        Dbl::free($result);
        return $row ? (int) $row[0] : false;
    }

    function pc_members() {
        if ($this->_pc_members_cache === null) {
            $pc = $pca = array();
            $result = $this->q("select firstName, lastName, nickname, affiliation, email, contactId, roles, contactTags, disabled from ContactInfo where roles!=0 and (roles&" . Contact::ROLE_PCLIKE . ")!=0");
            $by_name_text = $by_nick_text = [];
            $this->_pc_tags_cache = ["pc" => "pc"];
            while ($result && ($row = Contact::fetch($result, $this))) {
                $pca[$row->contactId] = $row;
                if ($row->roles & Contact::ROLE_PC)
                    $pc[$row->contactId] = $row;
                if ($row->firstName || $row->lastName) {
                    $name_text = Text::name_text($row);
                    $lname_text = strtolower($name_text);
                    if (isset($by_name_text[$lname_text]))
                        $row->nameAmbiguous = $by_name_text[$lname_text]->nameAmbiguous = true;
                    $by_name_text[$lname_text] = $row;
                }
                $nickname = $row->nickname ? : $row->firstName;
                if ($nickname) {
                    $lnick = strtolower($nickname);
                    if (isset($by_nick_text[$lnick]))
                        $row->nicknameAmbiguous = $by_nick_text[$lnick]->nicknameAmbiguous = true;
                    $by_nick_text[$lnick] = $row;
                }
                if ($row->contactTags)
                    foreach (explode(" ", $row->contactTags) as $t) {
                        list($tag, $value) = TagInfo::split_index($t);
                        if ($tag)
                            $this->_pc_tags_cache[strtolower($tag)] = $tag;
                    }
            }
            Dbl::free($result);
            uasort($pc, "Contact::compare");
            $order = 0;
            foreach ($pc as $row) {
                $row->sort_position = $order;
                ++$order;
            }
            $this->_pc_members_cache = $pc;
            uasort($pca, "Contact::compare");
            $this->_pc_members_and_admins_cache = $pca;
            ksort($this->_pc_tags_cache);
        }
        return $this->_pc_members_cache;
    }

    function pc_members_and_admins() {
        if ($this->_pc_members_and_admins_cache === null)
            $this->pc_members();
        return $this->_pc_members_and_admins_cache;
    }

    function pc_member_by_email($email) {
        foreach ($this->pc_members() as $p)
            if (strcasecmp($p->email, $email) == 0)
                return $p;
        return null;
    }

    function pc_tags() {
        if ($this->_pc_tags_cache === null)
            $this->pc_members();
        return $this->_pc_tags_cache;
    }

    function pc_tag_exists($tag) {
        if ($this->_pc_tags_cache === null)
            $this->pc_members();
        return isset($this->_pc_tags_cache[strtolower($tag)]);
    }


    // session data

    function session($name, $defval = null) {
        if (isset($_SESSION[$this->dsn][$name]))
            return $_SESSION[$this->dsn][$name];
        else
            return $defval;
    }

    function save_session($name, $value) {
        if ($value !== null)
            $_SESSION[$this->dsn][$name] = $value;
        else
            unset($_SESSION[$this->dsn][$name]);
    }

    function capability_text($prow, $capType) {
        // A capability has the following representation (. is concatenation):
        //    capFormat . paperId . capType . hashPrefix
        // capFormat -- Character denoting format (currently 0).
        // paperId -- Decimal representation of paper number.
        // capType -- Capability type (e.g. "a" for author view).
        // To create hashPrefix, calculate a SHA-1 hash of:
        //    capFormat . paperId . capType . paperCapVersion . capKey
        // where paperCapVersion is a decimal representation of the paper's
        // capability version (usually 0, but could allow conference admins
        // to disable old capabilities paper-by-paper), and capKey
        // is a random string specific to the conference, stored in Settings
        // under cap_key (created in load_settings).  Then hashPrefix
        // is the base-64 encoding of the first 8 bytes of this hash, except
        // that "+" is re-encoded as "-", "/" is re-encoded as "_", and
        // trailing "="s are removed.
        //
        // Any user who knows the conference's cap_key can construct any
        // capability for any paper.  Longer term, one might set each paper's
        // capVersion to a random value; but the only way to get cap_key is
        // database access, which would give you all the capVersions anyway.

        if (!isset($this->settingTexts["cap_key"]))
            return false;
        $start = "0" . $prow->paperId . $capType;
        $hash = sha1($start . $prow->capVersion . $this->settingTexts["cap_key"], true);
        $suffix = str_replace(array("+", "/", "="), array("-", "_", ""),
                              base64_encode(substr($hash, 0, 8)));
        return $start . $suffix;
    }


    function update_schema_version($n) {
        if (!$n)
            $n = $this->fetch_ivalue("select value from Settings where name='allowPaperOption'");
        if ($n && $this->ql("update Settings set value=$n where name='allowPaperOption'")) {
            $this->sversion = $this->settings["allowPaperOption"] = $n;
            return true;
        } else
            return false;
    }

    function invalidate_caches($caches = null) {
        global $OK;
        $inserts = array();
        $removes = array();
        $time = time();
        if ($caches ? isset($caches["pc"]) : $this->setting("pc") > 0) {
            if (!$caches || $caches["pc"]) {
                $inserts[] = "('pc',$time)";
                $this->settings["pc"] = $time;
            } else {
                $removes[] = "'pc'";
                unset($this->settings["pc"]);
            }
        }
        $ok = true;
        if (count($inserts))
            $ok = $ok && ($this->qe_raw("insert into Settings (name, value) values " . join(",", $inserts) . " on duplicate key update value=values(value)") !== false);
        if (count($removes))
            $ok = $ok && ($this->qe_raw("delete from Settings where name in (" . join(",", $removes) . ")") !== false);
        return $ok;
    }


    // times

    function printableInterval($amt) {
        if ($amt > 259200 /* 3 days */) {
            $amt = ceil($amt / 86400);
            $what = "day";
        } else if ($amt > 28800 /* 8 hours */) {
            $amt = ceil($amt / 3600);
            $what = "hour";
        } else if ($amt > 3600 /* 1 hour */) {
            $amt = ceil($amt / 1800) / 2;
            $what = "hour";
        } else if ($amt > 180) {
            $amt = ceil($amt / 60);
            $what = "minute";
        } else if ($amt > 0) {
            $amt = ceil($amt);
            $what = "second";
        } else
            return "past";
        return plural($amt, $what);
    }

    private function _dateFormat($type) {
        if (!$this->_date_format_initialized) {
            if (!isset($this->opt["time24hour"]) && isset($this->opt["time24Hour"]))
                $this->opt["time24hour"] = $this->opt["time24Hour"];
            if (!isset($this->opt["dateFormatLong"]) && isset($this->opt["dateFormat"]))
                $this->opt["dateFormatLong"] = $this->opt["dateFormat"];
            if (!isset($this->opt["dateFormat"]))
                $this->opt["dateFormat"] = get($this->opt, "time24hour") ? "j M Y H:i:s" : "j M Y g:i:sa";
            if (!isset($this->opt["dateFormatLong"]))
                $this->opt["dateFormatLong"] = "l " . $this->opt["dateFormat"];
            if (!isset($this->opt["dateFormatObscure"]))
                $this->opt["dateFormatObscure"] = "j M Y";
            if (!isset($this->opt["timestampFormat"]))
                $this->opt["timestampFormat"] = $this->opt["dateFormat"];
            if (!isset($this->opt["dateFormatSimplifier"]))
                $this->opt["dateFormatSimplifier"] = get($this->opt, "time24hour") ? "/:00(?!:)/" : "/:00(?::00|)(?= ?[ap]m)/";
            if (!isset($this->opt["dateFormatTimezone"]))
                $this->opt["dateFormatTimezone"] = null;
            $this->_date_format_initialized = true;
        }
        if ($type == "timestamp")
            return $this->opt["timestampFormat"];
        else if ($type == "obscure")
            return $this->opt["dateFormatObscure"];
        else if ($type)
            return $this->opt["dateFormatLong"];
        else
            return $this->opt["dateFormat"];
    }

    function parseableTime($value, $include_zone) {
        $f = $this->_dateFormat(false);
        $d = date($f, $value);
        if ($this->opt["dateFormatSimplifier"])
            $d = preg_replace($this->opt["dateFormatSimplifier"], "", $d);
        if ($include_zone) {
            if ($this->opt["dateFormatTimezone"] === null)
                $d .= " " . date("T", $value);
            else if ($this->opt["dateFormatTimezone"])
                $d .= " " . $this->opt["dateFormatTimezone"];
        }
        return $d;
    }
    function parse_time($d, $reference = null) {
        global $Now;
        if ($reference === null)
            $reference = $Now;
        if (!isset($this->opt["dateFormatTimezoneRemover"])
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
            $this->opt["dateFormatTimezoneRemover"] =
                "/(?:\\s|\\A)(?:" . join("|", $x) . ")(?:\\s|\\z)/i";
        }
        if ($this->opt["dateFormatTimezoneRemover"])
            $d = preg_replace($this->opt["dateFormatTimezoneRemover"], " ", $d);
        $d = preg_replace('/\butc([-+])/i', 'GMT$1', $d);
        return strtotime($d, $reference);
    }

    function _printableTime($value, $type, $useradjust, $preadjust = null) {
        if ($value <= 0)
            return "N/A";
        $t = date($this->_dateFormat($type), $value);
        if ($this->opt["dateFormatSimplifier"])
            $t = preg_replace($this->opt["dateFormatSimplifier"], "", $t);
        if ($type !== "obscure") {
            if ($this->opt["dateFormatTimezone"] === null)
                $t .= " " . date("T", $value);
            else if ($this->opt["dateFormatTimezone"])
                $t .= " " . $this->opt["dateFormatTimezone"];
        }
        if ($preadjust)
            $t .= $preadjust;
        if ($useradjust) {
            $sp = strpos($useradjust, " ");
            $t .= "<$useradjust class=\"usertime\" id=\"usertime$this->usertimeId\" style=\"display:none\"></" . ($sp ? substr($useradjust, 0, $sp) : $useradjust) . ">";
            Ht::stash_script("setLocalTime('usertime$this->usertimeId',$value)");
            ++$this->usertimeId;
        }
        return $t;
    }
    function printableTime($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, true, $useradjust, $preadjust);
    }
    function printableTimestamp($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, "timestamp", $useradjust, $preadjust);
    }
    function obscure_time($timestamp) {
        if ($timestamp !== null)
            $timestamp = (int) ($timestamp + 0.5);
        if ($timestamp > 0) {
            $offset = 0;
            if (($zone = timezone_open(date_default_timezone_get())))
                $offset = $zone->getOffset(new DateTime("@$timestamp"));
            $timestamp += 43200 - ($timestamp + $offset) % 86400;
        }
        return $timestamp;
    }
    function unparse_time_log($value) {
        return date("d/M/Y:H:i:s O", $value);
    }

    function printableTimeSetting($what, $useradjust = false, $preadjust = null) {
        return $this->printableTime(defval($this->settings, $what, 0), $useradjust, $preadjust);
    }
    function printableDeadlineSetting($what, $useradjust = false, $preadjust = null) {
        if (!isset($this->settings[$what]) || $this->settings[$what] <= 0)
            return "No deadline";
        else
            return "Deadline: " . $this->printableTime($this->settings[$what], $useradjust, $preadjust);
    }

    function settingsAfter($name) {
        global $Now;
        $t = get($this->settings, $name);
        return $t !== null && $t > 0 && $t <= $Now;
    }
    function deadlinesAfter($name, $grace = null) {
        global $Now;
        $t = get($this->settings, $name);
        if ($t !== null && $t > 0 && $grace && ($g = get($this->settings, $grace)))
            $t += $g;
        return $t !== null && $t > 0 && $t <= $Now;
    }
    function deadlinesBetween($name1, $name2, $grace = null) {
        global $Now;
        $t = get($this->settings, $name1);
        if (($t === null || $t <= 0 || $t > $Now) && $name1)
            return false;
        $t = get($this->settings, $name2);
        if ($t !== null && $t > 0 && $grace && ($g = get($this->settings, $grace)))
            $t += $g;
        return $t === null || $t <= 0 || $t >= $Now;
    }

    function repository_site_classes() {
        if (isset($this->opt["repositorySites"]) && is_array($this->opt["repositorySites"]))
            return $this->opt["repositorySites"];
        else
            return ["github"];
    }


    function cacheableImage($name, $alt, $title = null, $class = null, $style = null) {
        global $ConfSitePATH;
        $t = "<img src=\"" . Navigation::siteurl() . "images/$name\" alt=\"$alt\"";
        if ($title)
            $t .= " title=\"$title\"";
        if ($class)
            $t .= " class=\"$class\"";
        if ($style)
            $t .= " style=\"$style\"";
        return $t . " />";
    }


    //
    // Message routines
    //

    function msg($text, $type) {
        if (PHP_SAPI == "cli") {
            if ($type === "xmerror" || $type === "merror")
                fwrite(STDERR, "$text\n");
            else if ($type === "xwarning" || $type === "warning"
                     || !defined("HOTCRP_TESTHARNESS"))
                fwrite(STDOUT, "$text\n");
        } else if ($this->save_messages) {
            ensure_session();
            $_SESSION[$this->dsn]["msgs"][] = [$text, $type];
        } else if ($type[0] == "x") {
            echo Ht::xmsg($text, $type);
        } else {
            echo "<div class=\"$type\">$text</div>";
        }
    }

    function infoMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xinfo" : "info");
    }

    static public function msg_info($text, $minimal = false) {
        self::$g->msg($text, $minimal ? "xinfo" : "info");
    }

    function warnMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xwarning" : "warning");
    }

    static public function msg_warning($text, $minimal = false) {
        self::$g->msg($text, $minimal ? "xwarning" : "warning");
    }

    function confirmMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xconfirm" : "confirm");
    }

    static public function msg_confirm($text, $minimal = false) {
        self::$g->msg($text, $minimal ? "xconfirm" : "confirm");
    }

    function errorMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xmerror" : "merror");
        return false;
    }

    static public function msg_error($text, $minimal = false) {
        self::$g->msg($text, $minimal ? "xmerror" : "merror");
        return false;
    }

    static public function msg_debugt($text) {
        if (is_object($text) || is_array($text) || $text === null || $text === false || $text === true)
            $text = json_encode_browser($text);
        self::$g->msg(Ht::pre_text_wrap($text), "merror");
        return false;
    }

    function post_missing_msg() {
        $this->msg("Your uploaded data wasn’t received. This can happen on unusually slow connections, or if you tried to upload a file larger than I can accept.", "merror");
    }


    //
    // Conference header, footer
    //

    function set_siteurl($base) {
        $old_siteurl = Navigation::siteurl();
        $base = Navigation::set_siteurl($base);
        if ($this->opt["assetsUrl"] === $old_siteurl) {
            $this->opt["assetsUrl"] = $base;
            Ht::$img_base = $this->opt["assetsUrl"] . "images/";
        }
        if ($this->opt["scriptAssetsUrl"] === $old_siteurl)
            $this->opt["scriptAssetsUrl"] = $base;
    }

    const HOTURL_RAW = 1;
    const HOTURL_POST = 2;
    const HOTURL_ABSOLUTE = 4;
    const HOTURL_SITE_RELATIVE = 8;
    const HOTURL_NO_DEFAULTS = 16;

    function hoturl($page, $param = null, $flags = 0) {
        global $Me;
        $nav = Navigation::get();
        $amp = ($flags & self::HOTURL_RAW ? "&" : "&amp;");
        $t = $page . $nav->php_suffix;
        $are = '/\A(|.*?(?:&|&amp;))';
        $zre = '(?:&(?:amp;)?|\z)(.*)\z/';
        // parse options, separate anchor
        $anchor = "";
        if (is_array($param)) {
            $x = "";
            foreach ($param as $k => $v) {
                if ($v === null || $v === false)
                    /* skip */;
                else if ($k === "anchor")
                    $anchor = "#" . urlencode($v);
                else
                    $x .= ($x === "" ? "" : $amp) . $k . "=" . urlencode($v);
            }
            if (Conf::$hoturl_defaults && !($flags & self::HOTURL_NO_DEFAULTS))
                foreach (Conf::$hoturl_defaults as $k => $v)
                    if (!array_key_exists($k, $param))
                        $x .= ($x === "" ? "" : $amp) . $k . "=" . $v;
            $param = $x;
        } else {
            $param = (string) $param;
            if (($pos = strpos($param, "#"))) {
                $anchor = substr($param, $pos);
                $param = substr($param, 0, $pos);
            }
            if (Conf::$hoturl_defaults && !($flags & self::HOTURL_NO_DEFAULTS))
                foreach (Conf::$hoturl_defaults as $k => $v)
                    if (!preg_match($are . preg_quote($k) . '=/', $param))
                        $param .= ($param === "" ? "" : $amp) . $k . "=" . $v;
        }
        if ($flags & self::HOTURL_POST)
            $param .= ($param === "" ? "" : $amp) . "post=" . post_value();
        // create slash-based URLs if appropriate
        if ($param) {
            $has_commit = false;
            if (in_array($page, ["index", "pset", "diff", "run", "raw", "file"])
                && preg_match($are . 'u=([^&#?]+)' . $zre, $param, $m)) {
                $t = "~" . $m[2] . ($page === "index" ? "" : "/$t");
                $param = $m[1] . $m[3];
            }
            if (in_array($page, ["pset", "run", "diff", "raw", "file"])
                && preg_match($are . 'pset=(\w+)' . $zre, $param, $m)) {
                $t .= "/" . $m[2];
                $param = $m[1] . $m[3];
                if (preg_match($are . 'commit=([0-9a-f]+)' . $zre, $param, $m)) {
                    $t .= "/" . $m[2];
                    $param = $m[1] . $m[3];
                    $has_commit = true;
                }
            }
            if (($page === "raw" || $page === "file")
                && preg_match($are . 'file=([^&#?]+)' . $zre, $param, $m)) {
                $t .= "/" . str_replace("%2F", "/", $m[2]);
                $param = $m[1] . $m[3];
            } else if ($page == "diff"
                       && $has_commit
                       && preg_match($are . 'commit1=([0-9a-f]+)' . $zre, $param, $m)) {
                $t .= "/" . $m[2];
                $param = $m[1] . $m[3];
            } else if (($page == "profile" || $page == "face")
                       && preg_match($are . 'u=([^&#?]+)' . $zre, $param, $m)) {
                $t .= "/" . $m[2];
                $param = $m[1] . $m[3];
            } else if ($page == "help"
                       && preg_match($are . 't=(\w+)' . $zre, $param, $m)) {
                $t .= "/" . $m[2];
                $param = $m[1] . $m[3];
            } else if (preg_match($are . '__PATH__=([^&]+)' . $zre, $param, $m)) {
                $t .= "/" . urldecode($m[2]);
                $param = $m[1] . $m[3];
            }
            $param = preg_replace('/&(?:amp;)?\z/', "", $param);
        }
        if ($param !== "" && preg_match('/\A&(?:amp;)?(.*)\z/', $param, $m))
            $param = $m[1];
        if ($param !== "")
            $t .= "?" . $param;
        if ($anchor !== "")
            $t .= $anchor;
        if ($flags & self::HOTURL_SITE_RELATIVE)
            return $t;
        $need_site_path = false;
        if ($page === "index") {
            $expect = "index" . $nav->php_suffix;
            $lexpect = strlen($expect);
            if (substr($t, 0, $lexpect) === $expect
                && ($t === $expect || $t[$lexpect] === "?" || $t[$lexpect] === "#")) {
                $need_site_path = true;
                $t = substr($t, $lexpect);
            }
        }
        if (($flags & self::HOTURL_ABSOLUTE) || $this !== Conf::$g)
            return $this->opt("paperSite") . "/" . $t;
        else {
            $siteurl = $nav->site_path_relative;
            if ($need_site_path && $siteurl === "")
                $siteurl = $nav->site_path;
            return $siteurl . $t;
        }
    }

    function hoturl_absolute($page, $param = null, $flags = 0) {
        return $this->hoturl($page, $param, self::HOTURL_ABSOLUTE | $flags);
    }

    function hoturl_site_relative_raw($page, $param = null) {
        return $this->hoturl($page, $param, self::HOTURL_SITE_RELATIVE | self::HOTURL_RAW);
    }

    function hoturl_post($page, $param = null) {
        return $this->hoturl($page, $param, self::HOTURL_POST);
    }

    function hotlink($html, $page, $param = null, $js = null) {
        return Ht::link($html, $this->hoturl($page, $param), $js);
    }


    static $selfurl_safe = [
        "u" => true, "pset" => true, "commit" => true, "commit1" => true,
        "file" => true,
        "forceShow" => true, "sort" => true, "t" => true, "group" => true
    ];

    function selfurl(Qrequest $qreq = null, $params = [], $flags = 0) {
        global $Qreq;
        $qreq = $qreq ? : $Qreq;

        $x = [];
        foreach ($qreq as $k => $v) {
            $ak = get(self::$selfurl_safe, $k);
            if ($ak === true)
                $ak = $k;
            if ($ak
                && ($ak === $k || !isset($qreq[$ak]))
                && !array_key_exists($ak, $params)
                && !is_array($v))
                $x[$ak] = $v;
        }
        foreach ($params as $k => $v)
            $x[$k] = $v;
        return $this->hoturl(Navigation::page(), $x, $flags);
    }

    function self_redirect(Qrequest $qreq = null, $params = []) {
        Navigation::redirect($this->selfurl($qreq, $params, self::HOTURL_RAW));
    }


    function encoded_session_list() {
        global $Now;
        if ($this->_session_list === false) {
            $this->_session_list = null;

            $found = null;
            foreach ($_COOKIE as $k => $v) {
                if (($k === "hotlist-info" && $found === null)
                    || (str_starts_with($k, "hotlist-info-")
                        && strpos($k, "_") === false
                        && ($found === null || strnatcmp($k, $found) > 0)))
                    $found = $k;
            }

            $found_text = null;
            if ($found) {
                $found_text = $_COOKIE[$found];
                setcookie($found, "", $Now - 86400, Navigation::site_path());
                for ($i = 1; isset($_COOKIE["{$found}_{$i}"]); ++$i) {
                    $found_text .= $_COOKIE["{$found}_{$i}"];
                    setcookie("{$found}_{$i}", "", $Now - 86400, Navigation::site_path());
                }
            }

            if ($found_text) {
                $j = json_decode($found_text);
                if ($j === null)
                    $j = json_decode(str_replace("'", ",", $found_text));
                if (is_object($j) && isset($j->ids))
                    $this->_session_list = $j;
            }
        }
        return $this->_session_list;
    }

    function session_list() {
        if (($j = $this->encoded_session_list())) {
            if (isset($j->ids)
                && is_string($j->ids)
                && preg_match('/\A[\s\d\']*\z/', $j->ids))
                $j->ids = array_map(function ($x) { return (int) $x; },
                                    preg_split('/[\s\']+/', $j->ids));
            if (isset($j->psetids)
                && is_string($j->psetids)
                && preg_match('/\A[\s\d\']*\z/', $j->psetids))
                $j->psetids = array_map(function ($x) { return (int) $x; },
                                        preg_split('/[\s\']+/', $j->psetids));
            if (isset($j->hashes)
                && is_string($j->hashes)
                && preg_match('/\A[\sA-Fa-fx\d\']*\z/', $j->hashes))
                $j->hashes = preg_split('/[\s\']+/', $j->hashes);
        }
        return $j;
    }


    function make_css_link($url, $media = null) {
        global $ConfSitePATH;
        $t = '<link rel="stylesheet" type="text/css" href="';
        if (str_starts_with($url, "stylesheets/")
            || !preg_match(',\A(?:https?:|/),i', $url))
            $t .= $this->opt["assetsUrl"];
        $t .= $url;
        if (($mtime = @filemtime("$ConfSitePATH/$url")) !== false)
            $t .= "?mtime=$mtime";
        if ($media)
            $t .= '" media="' . $media;
        return $t . '" />';
    }

    function make_script_file($url, $no_strict = false, $integrity = null) {
        global $ConfSitePATH;
        if (str_starts_with($url, "scripts/")) {
            $post = "";
            if (($mtime = @filemtime("$ConfSitePATH/$url")) !== false)
                $post = "mtime=$mtime";
            if (get($this->opt, "strictJavascript") && !$no_strict)
                $url = $this->opt["scriptAssetsUrl"] . "cacheable.php?file=" . urlencode($url)
                    . "&strictjs=1" . ($post ? "&$post" : "");
            else
                $url = $this->opt["scriptAssetsUrl"] . $url . ($post ? "?$post" : "");
            if ($this->opt["scriptAssetsUrl"] === Navigation::siteurl())
                return Ht::script_file($url);
        }
        return Ht::script_file($url, ["crossorigin" => "anonymous", "integrity" => $integrity]);
    }

    function add_stylesheet($file) {
        $this->opt["stylesheets"] = mkarray($this->opt("stylesheets", []));
        $this->opt["stylesheets"][] = $file;
    }

    function add_javascript($file) {
        $this->opt["javascripts"] = mkarray($this->opt("javascripts", []));
        $this->opt["javascripts"][] = $file;
    }

    private function header_head($title, $id, $options) {
        global $Me, $ConfSitePATH, $Now;
        echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta name=\"google\" content=\"notranslate\" />\n";

        echo $this->opt("fontScript", "");

        echo $this->make_css_link("stylesheets/style.css"), "\n";
        if ($this->opt("mobileStylesheet")) {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">', "\n";
            echo $this->make_css_link("stylesheets/mobile.css", "screen and (max-width: 768px)"), "\n";
        }
        foreach (mkarray($this->opt("stylesheets", [])) as $css)
            echo $this->make_css_link($css), "\n";

        // favicon
        $favicon = $this->opt("favicon");
        if ($favicon) {
            if (strpos($favicon, "://") === false && $favicon[0] != "/") {
                if ($this->opt["assetsUrl"] && substr($favicon, 0, 7) === "images/")
                    $favicon = $this->opt["assetsUrl"] . $favicon;
                else
                    $favicon = Navigation::siteurl() . $favicon;
            }
            if (substr($favicon, -4) == ".png")
                echo "<link rel=\"icon\" type=\"image/png\" href=\"$favicon\" />\n";
            else if (substr($favicon, -4) == ".ico")
                echo "<link rel=\"shortcut icon\" href=\"$favicon\" />\n";
            else if (substr($favicon, -4) == ".gif")
                echo "<link rel=\"icon\" type=\"image/gif\" href=\"$favicon\" />\n";
            else
                echo "<link rel=\"icon\" href=\"$favicon\" />\n";
        }

        // title
        echo "<title>";
        if ($title) {
            $title = preg_replace("/<([^>\"']|'[^']*'|\"[^\"]*\")*>/", "", $title);
            $title = preg_replace(",(?: |&nbsp;|\302\240)+,", " ", $title);
            $title = str_replace("&#x2215;", "-", $title);
        }
        if ($title)
            echo $title, " - ";
        echo htmlspecialchars($this->short_name), "</title>\n";

        // <body>
        echo "</head>\n<body", ($id ? " id=\"$id\"" : "");
        if (isset($options["body_class"]))
            echo ' class="', $options["body_class"], '"';
        echo " onload=\"hotcrp_load()\" data-now=\"$Now\">\n";

        // jQuery
        $stash = Ht::unstash();
        $jqueryVersion = get($this->opt, "jqueryVersion", "1.12.4");
        $integrity = null;
        if (isset($this->opt["jqueryUrl"]))
            $jquery = $this->opt["jqueryUrl"];
        else if ($this->opt("jqueryCdn")) {
            $jquery = "//code.jquery.com/jquery-{$jqueryVersion}.min.js";
            if ($jqueryVersion === "1.12.4")
                $integrity = "sha256-ZosEbRLbNQzLpnKIkEdrPv7lOy9C27hHQ+Xp8a4MxAQ=";
            else if ($jqueryVersion === "3.1.1")
                $integrity = "sha256-hVVnYaiADRTO2PzUGmuLJr8BLUSjGIZsDYGmIJLv2b8=";
        } else
            $jquery = "scripts/jquery-{$jqueryVersion}.min.js";
        Ht::stash_html($this->make_script_file($jquery, true, $integrity) . "\n");
        if ($this->opt("jqueryMigrate"))
            Ht::stash_html($this->make_script_file("//code.jquery.com/jquery-migrate-3.0.0.min.js", true));
        Ht::stash_html($this->make_script_file("scripts/jquery.color-2.1.2.min.js", true) . "\n");
        Ht::stash_html($this->make_script_file("scripts/markdown-it.min.js", true) . "\n");
        foreach (mkarray($this->opt("javascripts", [])) as $scriptfile)
            Ht::stash_html($this->make_script_file($scriptfile, true) . "\n");

        // Javascript settings to set before script.js
        $nav = Navigation::get();
        Ht::stash_script("siteurl=" . json_encode_browser($nav->site_path_relative)
            . ";siteurl_base_path=" . json_encode_browser($nav->base_path)
            . ";siteurl_suffix=\"" . $nav->php_suffix . "\"");
        if (session_id() !== "")
            Ht::stash_script("siteurl_postvalue=\"" . post_value() . "\"");
        if (($list = $this->encoded_session_list()))
            Ht::stash_script("hotcrp_list=" . json_encode_browser($list) . ";");
        if (self::$hoturl_defaults) {
            $urldefaults = [];
            foreach (self::$hoturl_defaults as $k => $v) {
                $urldefaults[$k] = urldecode($v);
            }
            Ht::stash_script("siteurl_defaults=" . json_encode_browser($urldefaults) . ";");
        }
        Ht::stash_script("assetsurl=" . json_encode_browser($this->opt["assetsUrl"]) . ";");
        $huser = (object) array();
        if ($Me && $Me->email)
            $huser->email = $Me->email;
        if ($Me && $Me->is_pclike())
            $huser->is_pclike = true;
        Ht::stash_script("hotcrp_user=" . json_encode_browser($huser));

        // script.js
        if (!$this->opt("noDefaultScript"))
            Ht::stash_html($this->make_script_file("scripts/script.js") . "\n");

        // other scripts
        foreach ($this->opt("scripts", []) as $file)
            Ht::stash_html($this->make_script_file($file) . "\n");

        $this->encoded_session_list(); // clear cookie if set

        // initial load (JS's timezone offsets are negative of PHP's)
        Ht::stash_script("hotcrp_load.time(" . (-date("Z", $Now) / 60) . "," . ($this->opt("time24hour") ? 1 : 0) . ")");

        echo $stash, Ht::unstash();
    }

    function header($title, $id = "", $options = []) {
        global $ConfSitePATH, $Me, $Now;
        if ($this->headerPrinted)
            return;

        // <head>
        if ($title === "Home")
            $title = "";
        $this->header_head($title, $id, $options);

        echo "<div id='prebody'>\n";

        echo "<div id='header'>\n<div id='header_left_conf'><h1>";
        if ($title && ($title == "Home" || $title == "Sign in"))
            echo "<a class='qq' href='", hoturl("index"), "' title='Home'>", htmlspecialchars($this->short_name), "</a>";
        else
            echo "<a class='uu' href='", hoturl("index"), "' title='Home'>", htmlspecialchars($this->short_name), "</a></h1></div><div id='header_left_page'><h1>", $title;
        echo "</h1></div><div id='header_right'>";
        if ($Me && !$Me->is_empty()) {
            // profile link
            $profile_parts = [];
            if ($Me->has_email() && !$Me->disabled) {
	        $profile_parts[] = '<strong>' . htmlspecialchars($Me->email) . '</strong>';
                /*echo '<a class="q" href="', hoturl("profile"), '"><strong>',
                    htmlspecialchars($Me->email),
                    '</strong></a> &nbsp; <a href="', hoturl("profile"), '">Profile</a>',
                    $xsep;*/
            }

            // "act as" link
            if (($actas = get($_SESSION, "last_actas"))
                && (($Me->privChair && strcasecmp($actas, $Me->email) !== 0)
                    || Contact::$true_user)) {
                // Link becomes true user if not currently chair.
                $actas = Contact::$true_user ? Contact::$true_user->email : $actas;
                $profile_parts[] = "<a href=\""
                    . $this->selfurl(null, ["actas" => Contact::$true_user ? null : $actas]) . "\">"
                    . (Contact::$true_user ? "Admin" : htmlspecialchars($actas))
                    . "&nbsp;" . Ht::img("viewas.png", "Act as " . htmlspecialchars($actas))
                    . "</a>";
            }

            // help, sign out
            $x = ($id == "search" ? "t=$id" : ($id == "settings" ? "t=chair" : ""));
            if (!$Me->has_email() && !isset($this->opt["httpAuthLogin"]))
                $profile_parts[] = '<a href="' . hoturl("index", "signin=1") . '">Sign&nbsp;in</a>';
            if (!$Me->is_empty() || isset($this->opt["httpAuthLogin"]))
                $profile_parts[] = '<a href="' . hoturl_post("index", "signout=1") . '">Sign&nbsp;out</a>';

            if (!empty($profile_parts))
                echo join(' <span class="barsep">·</span> ', $profile_parts);
        }
        echo '<div id="maindeadline" style="display:none"></div></div>', "\n";

        echo "  <hr class=\"c\" />\n";

        echo "</div>\n<div id=\"initialmsgs\">\n";
        if (($x = $this->opt("maintenance")))
            echo "<div class=\"merror\"><strong>The site is down for maintenance.</strong> ", (is_string($x) ? $x : "Please check back later."), "</div>";
        $this->save_messages = false;
        if (($msgs = $this->session("msgs"))
            && !empty($msgs)) {
            $this->save_session("msgs", null);
            foreach ($msgs as $m)
                $this->msg($m[0], $m[1]);
            echo "<div id=\"initialmsgspacer\"></div>";
        }
        echo "</div>\n";

        $this->headerPrinted = true;
        echo "</div>\n<div class='body'>\n";
    }

    function footer() {
        global $Me, $ConfSitePATH;
        echo "</div>\n", // class='body'
            "<div id='footer'>\n";
        $footy = $this->opt("extraFooter", "");
        if (false)
            $footy .= "<a href='http://read.seas.harvard.edu/~kohler/hotcrp/'>HotCRP</a> Conference Management Software";
        if (!$this->opt("noFooterVersion")) {
            if ($Me && $Me->privChair) {
                if (is_dir("$ConfSitePATH/.git")) {
                    $args = array();
                    exec("export GIT_DIR=" . escapeshellarg($ConfSitePATH) . "/.git; git rev-parse HEAD 2>/dev/null", $args);
                    if (count($args) == 2 && $args[0] != $args[1])
                        $footy .= " [" . substr($args[0], 0, 7) . "...]";
                }
            }
        }
        if ($footy)
            echo "<div id='footer_crp'>$footy</div>";
        echo "<div class='clear'></div></div>\n";
        echo Ht::take_stash(), "</body>\n</html>\n";
    }

    function stash_hotcrp_pc(Contact $user) {
        if (!Ht::mark_stash("hotcrp_pc"))
            return;
        $hpcj = $list = [];
        foreach ($this->pc_members_and_admins() as $pcm) {
            $hpcj[$pcm->contactId] = $j = (object) ["name" => Text::name_html($pcm), "email" => $pcm->email];
            if ($pcm->lastName) {
                $r = Text::analyze_name($pcm);
                if (strlen($r->lastName) !== strlen($r->name))
                    $j->lastpos = strlen(htmlspecialchars($r->firstName)) + 1;
                if ($r->nameAmbiguous && $r->name && $r->email)
                    $j->emailpos = strlen(htmlspecialchars($r->name)) + 1;
            }
            if (!$pcm->nameAmbiguous && ($pcm->nickname || $pcm->firstName)) {
                if ($pcm->nicknameAmbiguous)
                    $j->nicklen = strlen(htmlspecialchars($r->name));
                else {
                    $nick = htmlspecialchars($pcm->nickname ? : $pcm->firstName);
                    if (str_starts_with($j->name, $nick))
                        $j->nicklen = strlen($nick);
                    else
                        $j->nick = $nick;
                }
            }
            if (!($pcm->roles & Contact::ROLE_PC))
                $j->admin_only = true;
            $list[] = $pcm->contactId;
        }
        $hpcj["__order__"] = $list;
        if ($this->sort_by_last)
            $hpcj["__sort__"] = "last";
        Ht::stash_script("hotcrp_pc=" . json_encode_browser($hpcj) . ";");
    }

    function output_ajax($values = null, $div = false) {
        if ($values === false || $values === true)
            $values = array("ok" => $values);
        else if ($values === null)
            $values = array();
        else if (is_object($values))
            $values = get_object_vars($values);
        $t = "";
        if (session_id() !== ""
            && ($msgs = $this->session("msgs", array()))) {
            $this->save_session("msgs", null);
            foreach ($msgs as $msg) {
                if (($msg[0] === "merror" || $msg[0] === "xmerror")
                    && !isset($values["error"]))
                    $values["error"] = $msg[1];
                if ($div)
                    $t .= Ht::xmsg($msg[0], $msg[1]);
                else
                    $t .= "<span class=\"$msg[0]\">$msg[1]</span>";
            }
        }
        if ($t !== "")
            $values["response"] = $t . get_s($values, "response");
        if (isset($_REQUEST["jsontext"]) && $_REQUEST["jsontext"])
            header("Content-Type: text/plain");
        else
            header("Content-Type: application/json");
        if (check_post())
            header("Access-Control-Allow-Origin: *");
        echo json_encode_browser($values);
    }


    //
    // Action recording
    //

    function save_logs($on) {
        if ($on && $this->_save_logs === false)
            $this->_save_logs = array();
        else if (!$on && $this->_save_logs !== false) {
            $x = $this->_save_logs;
            $this->_save_logs = false;
            foreach ($x as $cid_text => $pids) {
                $pos = strpos($cid_text, "|");
                $this->log(substr($cid_text, $pos + 1),
                           substr($cid_text, 0, $pos), $pids);
            }
        }
    }

    function log($text, $who, $pids = null) {
        if (!$who)
            $who = 0;
        else if (!is_numeric($who))
            $who = $who->contactId;

        if (is_object($pids))
            $pids = array($pids->paperId);
        else if (!is_array($pids))
            $pids = $pids > 0 ? array($pids) : array();
        $ps = array();
        foreach ($pids as $p)
            $ps[] = is_object($p) ? $p->paperId : $p;

        if ($this->_save_logs !== false) {
            foreach ($ps as $p)
                $this->_save_logs["$who|$text"][] = $p;
            return;
        }

        if (count($ps) == 0)
            $ps = "null";
        else if (count($ps) == 1)
            $ps = $ps[0];
        else {
            $text .= " (papers " . join(", ", $ps) . ")";
            $ps = "null";
        }
        $this->q("insert into ActionLog (ipaddr, contactId, paperId, action) values ('" . sqlq(@$_SERVER["REMOTE_ADDR"]) . "', " . (int) $who . ", $ps, '" . sqlq(substr($text, 0, 4096)) . "')");
    }


    //
    // Miscellaneous
    //

    public function capability_manager($for = null) {
        if ($for && substr($for, 0, 1) === "U") {
            if (($cdb = Contact::contactdb()))
                return new CapabilityManager($cdb, "U");
            else
                return null;
        } else
            return new CapabilityManager($this->dblink, "");
    }


    function register_pset(Pset $pset) {
        if (isset($this->_psets[$pset->id])) {
            throw new Exception("pset id `{$pset->id}` reused");
        }
        $this->_psets[$pset->id] = $pset;
        if (isset($this->_psets_by_urlkey[$pset->urlkey])) {
            throw new Exception("pset urlkey `{$pset->urlkey}` reused");
        }
        $this->_psets_by_urlkey[$pset->urlkey] = $pset;
        if (!$pset->disabled && $pset->group) {
            if (!isset($this->_group_weights[$pset->group])) {
                $this->_group_weights[$pset->group] = $pset->group_weight;
                $this->_group_weight_defaults[$pset->group] = $pset->group_weight_default;
            } else if ($this->_group_weight_defaults[$pset->group] === $pset->group_weight_default) {
                $this->_group_weights[$pset->group] += $pset->group_weight;
            } else {
                throw new Exception("pset group `{$pset->group}` has not all group weights set");
            }
        }
        $this->_psets_sorted = false;
    }

    function psets() {
        if (!$this->_psets_sorted) {
            uasort($this->_psets, "Pset::compare");
            $this->_psets_sorted = true;
        }
        return $this->_psets;
    }

    function psets_newest_first() {
        return array_reverse($this->psets(), true);
    }

    function pset_by_id($id) {
        return $this->_psets[$id] ?? null;
    }

    function pset_by_key($key) {
        if (($p = $this->_psets_by_urlkey[$key] ?? null)) {
            return $p;
        }
        foreach ($this->_psets as $p) {
            if ($key === $p->key)
                return $p;
        }
        return null;
    }

    function pset_by_key_or_title($key) {
        if (($p = $this->_psets_by_urlkey[$key] ?? null)) {
            return $p;
        } else if (str_starts_with($key, "pset")
                   && ctype_digit(substr($key, 4))) {
            return $this->_psets[(int) substr($key, 4)] ?? null;
        }
        $tm = null;
        foreach ($this->_psets as $p) {
            if ($key === $p->key) {
                return $p;
            } else if ($key === $p->title) {
                $tm = $p;
            }
        }
        return $tm;
    }

    function group_weight($group) {
        return get($this->_group_weights, $group, 0.0);
    }

    function pset_groups() {
        if ($this->_grouped_psets === null) {
            $this->_grouped_psets = [];
            $this->_group_has_extra = [];
            foreach ($this->psets() as $pset) {
                if (!$pset->disabled) {
                    $group = $pset->group ? : "";
                    $this->_grouped_psets[$group][] = $pset;
                    if ($pset->has_extra) {
                        $this->_group_has_extra[$group] = true;
                    }
                }
            }
            uksort($this->_grouped_psets, function ($a, $b) {
                if ($a === "") {
                    return 1;
                } else {
                    return strnatcmp($a, $b);
                }
            });
        }
        return $this->_grouped_psets;
    }

    function pset_group($group) {
        return get($this->pset_groups(), $group, []);
    }

    function pset_group_has_extra($group) {
        $this->pset_groups();
        return get($this->_group_has_extra, $group, false);
    }


    function handout_repo(Pset $pset, Repository $inrepo = null) {
        global $Now, $ConfSitePATH;
        $url = $pset->handout_repo_url;
        if (!$url)
            return null;
        if ($this->opt("noGitTransport") && substr($url, 0, 6) === "git://")
            $url = "ssh://git@" . substr($url, 6);
        $hrepo = get($this->_handout_repos, $url);
        if (!$hrepo && ($hrepo = Repository::find_or_create_url($url, $this))) {
            $hrepo->is_handout = true;
            $this->_handout_repos[$url] = $hrepo;
        }
        if ($hrepo) {
            $hrepoid = $hrepo->repoid;
            $cacheid = $inrepo ? $inrepo->cacheid : $hrepo->cacheid;
            $hset = $this->setting_json("handoutrepos");
            $save = false;
            if (!$hset) {
                $save = $hset = (object) array();
            }
            if (!($hme = get($hset, $hrepoid))) {
                $save = $hme = $hset->$hrepoid = (object) array();
            }
            if ((int) get($hme, $cacheid) + 300 < $Now
                && !$this->opt("disableRemote")) {
                $save = $hme->$cacheid = $Now;
                $hrepo->reposite->gitfetch($hrepo->repoid, $cacheid, false);
            }
            if ($save) {
                $this->save_setting("handoutrepos", 1, $hset);
            }
        }
        return $hrepo;
    }

    private function populate_handout_commits(Pset $pset) {
        global $Now;
        if (!($hrepo = $this->handout_repo($pset))) {
            $this->_handout_commits[$pset->id] = [];
            return;
        }
        $hrepoid = $hrepo->repoid;
        $key = "handoutcommits_{$hrepoid}_{$pset->id}";
        $hset = $this->setting_json($key);
        if (!$hset) {
            $hset = (object) [];
        }
        if (get($hset, "snaphash") !== $hrepo->snaphash
            || (int) get($hset, "snaphash_at") + 300 < $Now
            || !get($hset, "commits")) {
            $hset->snaphash = $hrepo->snaphash;
            $hset->snaphash_at = $Now;
            $hset->commits = [];
            foreach ($hrepo->commits($pset, $pset->handout_branch) as $c) {
                $hset->commits[] = [$c->hash, $c->commitat, $c->subject];
            }
            $this->save_setting($key, 1, $hset);
            $this->qe("delete from Settings where name!=? and name like 'handoutcommits_%_?s'", $key, $pset->id);
        }
        $commits = [];
        foreach ($hset->commits as $c) {
            $commits[$c[0]] = new CommitRecord($c[1], $c[0], $c[2], CommitRecord::HANDOUTHEAD);
        }
        $this->_handout_commits[$pset->id] = $commits;
        reset($commits);
        $this->_handout_latest_commit[$pset->id] = current($commits);
    }

    function handout_commits(Pset $pset, $hash = null) {
        global $Now;
        if (!array_key_exists($pset->id, $this->_handout_commits)) {
            $this->populate_handout_commits($pset);
        }
        $commits = $this->_handout_commits[$pset->id];
        if (!$hash) {
            return $commits;
        } else if (strlen($hash) === 40 || strlen($hash) === 64) {
            return get($commits, $hash);
        } else {
            $matches = [];
            foreach ($commits as $h => $c)
                if (str_starts_with($h, $hash))
                    $matches[] = $c;
            return count($matches) === 1 ? $matches[0] : null;
        }
    }

    function handout_commits_from(Pset $pset, $hash) {
        global $Now;
        if (!array_key_exists($pset->id, $this->_handout_commits)) {
            $this->populate_handout_commits($pset);
        }
        $commits = $this->_handout_commits[$pset->id];
        $matches = $list = [];
        foreach ($commits as $h => $c) {
            if (str_starts_with($h, $hash)) {
                $matches[] = $c;
            }
            if (!empty($matches)) {
                $list[$h] = $c;
            }
        }
        return count($matches) === 1 ? $list : null;
    }

    function latest_handout_commit(Pset $pset) {
        if (!array_key_exists($pset->id, $this->_handout_latest_commit)) {
            $this->populate_handout_commits($pset);
        }
        return get($this->_handout_latest_commit, $pset->id);
    }


    private function branch_map() {
        if ($this->_branch_map === null) {
            $this->_branch_map = [];
            $result = $this->qe("select branchid, branch from Branch");
            while (($row = $result->fetch_row()))
                $this->_branch_map[+$row[0]] = $row[1];
            Dbl::free($result);
        }
        return $this->_branch_map;
    }

    function branch($branchid) {
        return get($this->branch_map(), $branchid);
    }

    function ensure_branch($branch) {
        if ((string) $branch === "" || $branch === "master")
            return null;
        $key = array_search($branch, $this->branch_map(), true);
        if ($key === false) {
            $this->qe("insert into Branch set branch=?", $branch);
            if (!$this->dblink->insert_id) {
                $this->_branch_map = null;
                return $this->ensure_branch($branch);
            }
            $key = $this->dblink->insert_id;
            $this->_branch_map[$key] = $branch;
        }
        return $key;
    }


    // API

    private function call_api($uf, Contact $user, Qrequest $qreq, APIData $api) {
        if (!$uf)
            return ["ok" => false, "error" => "API function not found."];
        if (!get($uf, "get") && !check_post($qreq))
            return ["ok" => false, "error" => "Missing credentials."];
        $need_hash = !!get($uf, "hash");
        $need_repo = !!get($uf, "repo");
        $need_pset = $need_repo || $need_hash || !!get($uf, "pset");
        $need_user = !!get($uf, "user");
        if ($need_user && !$api->user)
            return ["ok" => false, "error" => "Missing user."];
        if ($need_pset && !$api->pset)
            return ["ok" => false, "error" => "Missing pset."];
        if ($need_repo && !$api->repo)
            return ["ok" => false, "error" => "Missing repository."];
        if ($need_hash) {
            $api->commit = $this->check_api_hash($api->hash, $api);
            if (!$api->commit)
                return ["ok" => false, "error" => ($api->hash ? "Missing commit." : "Disconnected commit.")];
        }
        if (($req = get($uf, "require")))
            foreach (expand_includes($req) as $f)
                require_once $f;
        return call_user_func($uf->callback, $user, $qreq, $api, $uf);
    }
    function check_api_hash($input_hash, APIData $api) {
        if (!$input_hash)
            return null;
        $commit = $api->pset->handout_commits($input_hash);
        if (!$commit && $api->repo)
            $commit = $api->repo->connected_commit($input_hash, $api->pset, $api->branch);
        return $commit;
    }
    function _add_api_json($fj) {
        if (is_string($fj->fn) && !isset($this->_api_map[$fj->fn])
            && isset($fj->callback)) {
            $this->_api_map[$fj->fn] = $fj;
            return true;
        } else
            return false;
    }
    private function fill_api_map() {
        $this->_api_map = [
            "blob" => "15 API_Repo::blob",
            "filediff" => "15 API_Repo::filediff",
            "grade" => "3 API_Grade::grade",
            "gradestatistics" => "3 API_GradeStatistics::run",
            "jserror" => "1 API_JSError::jserror",
            "latestcommit" => "3 API_Repo::latestcommit",
            "linenote" => "15 API_Grade::linenote",
            "multigrade" => "3 API_Grade::multigrade",
            "repositories" => "17 API_Repo::user_repositories"
        ];
        if (($olist = $this->opt("apiFunctions")))
            expand_json_includes_callback($olist, [$this, "_add_api_json"]);
    }
    function has_api($fn) {
        if ($this->_api_map === null)
            $this->fill_api_map();
        return isset($this->_api_map[$fn]);
    }
    function api($fn) {
        if ($this->_api_map === null)
            $this->fill_api_map();
        $uf = get($this->_api_map, $fn);
        if ($uf && is_string($uf)) {
            $space = strpos($uf, " ");
            $flags = (int) substr($uf, 0, $space);
            $uf = $this->_api_map[$fn] = (object) ["callback" => substr($uf, $space + 1)];
            if ($flags & 1)
                $uf->get = true;
            if ($flags & 2)
                $uf->pset = true;
            if ($flags & 4)
                $uf->repo = true;
            if ($flags & 8)
                $uf->hash = true;
            if ($flags & 16)
                $uf->user = true;
        }
        return $uf;
    }
    function call_api_exit($uf, Contact $user, Qrequest $qreq, APIData $info) {
        if (is_string($uf))
            $uf = $this->api($uf);
        if ($uf && get($uf, "redirect") && $qreq->redirect
            && preg_match('@\A(?![a-z]+:|/).+@', $qreq->redirect)) {
            try {
                JsonResultException::$capturing = true;
                $j = $this->call_api($uf, $user, $qreq, $info);
            } catch (JsonResultException $ex) {
                $j = $ex->result;
            }
            if (is_object($j) && $j instanceof JsonResult) {
                $j = $j->content;
            }
            if (!get($j, "ok") && !get($j, "error")) {
                Conf::msg_error("Internal error.");
            } else if (($x = get($j, "error"))) {
                Conf::msg_error(htmlspecialchars($x));
            } else if (($x = get($j, "error_html"))) {
                Conf::msg_error($x);
            }
            Navigation::redirect_site($qreq->redirect);
        } else {
            $j = $this->call_api($uf, $user, $qreq, $info);
            json_exit($j);
        }
    }
}
