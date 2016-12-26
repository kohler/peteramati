<?php
// conference.php -- HotCRP central helper class (singleton)
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class Conf {
    public $dblink = null;

    var $settings;
    var $settingTexts;
    public $sversion;
    var $deadlineCache;

    public $dbname;
    public $dsn = null;

    public $short_name;
    public $long_name;
    public $download_prefix;
    public $sort_by_last;
    public $opt;
    public $opt_override = null;

    public $validate_timeout;
    public $validate_overall_timeout;

    private $save_messages = true;
    var $headerPrinted = false;
    private $_save_logs = false;
    private $_psets = [];
    private $_psets_by_urlkey = [];
    private $_session_list = false;

    private $usertimeId = 1;

    private $_date_format_initialized = false;
    private $_pc_members_cache = null;
    private $_pc_tags_cache = null;
    private $_pc_members_and_admins_cache = null;
    private $_handout_repos = [];
    private $_handout_commits = [];
    private $_handout_latest_commit = [];
    private $_api_map = null;

    static public $g = null;

    function __construct($options, $make_dsn) {
        // unpack dsn, connect to database, load current settings
        if ($make_dsn && ($this->dsn = Dbl::make_dsn($options)))
            list($this->dblink, $options["dbName"]) = Dbl::connect_dsn($this->dsn);
        if (!isset($options["confid"]))
            $options["confid"] = get($options, "dbName");
        $this->opt = $options;
        $this->dbname = $options["dbName"];
        if ($this->dblink && !Dbl::$default_dblink) {
            Dbl::set_default_dblink($this->dblink);
            Dbl::set_error_handler(array($this, "query_error_handler"));
        }
        if ($this->dblink) {
            Dbl::$landmark_sanitizer = "/^(?:Dbl::|Conf::q|call_user_func)/";
            $this->load_settings();
        } else
            $this->crosscheck_options();
    }


    //
    // Initialization functions
    //

    function load_settings() {
        global $Now;

        // load settings from database
        $this->settings = array();
        $this->settingTexts = array();
        foreach ($this->opt_override ? : [] as $k => $v) {
            if ($v === null)
                unset($this->opt[$k]);
            else
                $this->opt[$k] = $v;
        }
        $this->opt_override = [];

        $this->_pc_seeall_cache = null;
        $this->deadlineCache = null;

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
        if ($this->sversion < 109) {
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
                && ($key = hotcrp_random_bytes(32)) !== false)
                $this->save_setting("conf_key", 1, $key);
            $this->opt["conferenceKey"] = get($this->settingTexts, "conf_key", "");
        }

        // set capability key
        if (!get($this->settings, "cap_key")
            && !get($this->opt, "disableCapabilities")
            && !(($key = hotcrp_random_bytes(16)) !== false
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
        if (!isset($this->opt["paperSite"]) || $this->opt["paperSite"] == "")
            $this->opt["paperSite"] = Navigation::site_absolute();
        if ($this->opt["paperSite"] == "" && isset($this->opt["defaultPaperSite"]))
            $this->opt["paperSite"] = $this->opt["defaultPaperSite"];
        $this->opt["paperSite"] = preg_replace('|/+\z|', "", $this->opt["paperSite"]);

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

        $sort_by_last = !!get($this->opt, "sortByLastName");
        if (!$this->sort_by_last != !$sort_by_last)
            $this->_pc_members_cache = $this->_pc_members_and_admins_cache = null;
        $this->sort_by_last = $sort_by_last;
        $this->_api_map = null;
    }

    function has_setting($name) {
        return isset($this->settings[$name]);
    }

    function setting($name, $defval = null) {
        return get($this->settings, $name, $defval);
    }

    function setting_data($name, $defval = false) {
        $x = get($this->settingTexts, $name, $defval);
        if ($x && is_object($x))
            $x = $this->settingTexts[$name] = json_encode($x);
        return $x;
    }

    function setting_json($name, $defval = false) {
        $x = get($this->settingTexts, $name, $defval);
        if ($x && is_string($x)) {
            $x = json_decode($x);
            if (is_object($x))
                $this->settingTexts[$name] = $x;
        }
        return $x;
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

    private function user_by_whatever_query($whatever) {
        $whatever = trim($whatever);
        if (preg_match('/\A\d{8}\z/', $whatever))
            return ["ContactInfo.seascode_username=? or ContactInfo.huid=? order by ContactInfo.seascode_username=? desc limit 1",
                    [$whatever, $whatever, $whatever]];
        else if (preg_match('/\A\[anon\w+\]\z/', $whatever))
            return ["ContactInfo.anon_username=?", [$whatever]];
        else if (strpos($whatever, "@") === false)
            return ["ContactInfo.github_username=? or ContactInfo.seascode_username=?
                     or (coalesce(ContactInfo.github_username,ContactInfo.seascode_username,'')='' and email like ?l)
                     order by ContactInfo.github_username=? desc, ContactInfo.seascode_username=? desc limit 1",
                    [$whatever, $whatever, $whatever, $whatever, $whatever]];
        else {
            if (preg_match('_.*@(?:fas|college|seas)\z_', $whatever))
                $whatever .= ".harvard.edu";
            else if (preg_match('_.*@.*?\.harvard\z_', $whatever))
                $whatever .= ".edu";
            return ["ContactInfo.email=?", [$whatever]];
        }
    }

    function user_by_whatever($whatever) {
        list($qpart, $args) = $this->user_by_whatever_query($whatever);
        return $this->user_by_query($qpart, $args);
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
            $result = $this->q("select firstName, lastName, affiliation, email, contactId, roles, contactTags, disabled from ContactInfo where roles!=0 and (roles&" . Contact::ROLE_PCLIKE . ")!=0");
            $by_name_text = $by_first_text = [];
            $this->_pc_tags_cache = ["pc" => "pc"];
            while ($result && ($row = Contact::fetch($result, $this))) {
                $pca[$row->contactId] = $row;
                if ($row->roles & Contact::ROLE_PC)
                    $pc[$row->contactId] = $row;
                if ($row->firstName || $row->lastName) {
                    $name_text = Text::name_text($row);
                    if (isset($by_name_text[$name_text]))
                        $row->nameAmbiguous = $by_name_text[$name_text]->nameAmbiguous = true;
                    $by_name_text[$name_text] = $row;
                }
                if ($row->firstName) {
                    if (isset($by_first_text[$row->firstName]))
                        $row->firstNameAmbiguous = $by_first_text[$row->firstName]->firstNameAmbiguous = true;
                    $by_first_text[$row->firstName] = $row;
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

    function save_session_array($name, $index, $value) {
        if (!isset($_SESSION[$this->dsn][$name])
            || !is_array($_SESSION[$this->dsn][$name]))
            $_SESSION[$this->dsn][$name] = array();
        if ($index !== true)
            $_SESSION[$this->dsn][$name][$index] = $value;
        else
            $_SESSION[$this->dsn][$name][] = $value;
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


    function save_setting($name, $value, $data = null) {
        $change = false;
        if ($value === null && $data === null) {
            if ($this->qe("delete from Settings where name=?", $name)) {
                unset($this->settings[$name]);
                unset($this->settingTexts[$name]);
                $change = true;
            }
        } else {
            $dval = $data;
            if (is_array($dval) || is_object($dval))
                $dval = json_encode($dval);
            if ($this->qe("insert into Settings (name, value, data) values (?, ?, ?) on duplicate key update value=values(value), data=values(data)", $name, $value, $dval)) {
                $this->settings[$name] = $value;
                $this->settingTexts[$name] = $data;
                $change = true;
            }
        }
        if ($change) {
            $this->crosscheck_settings();
            if (str_starts_with($name, "opt."))
                $this->crosscheck_options();
        }
        return $change;
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

    function msg($type, $text) {
        if (PHP_SAPI == "cli") {
            if ($type === "xmerror" || $type === "merror")
                fwrite(STDERR, "$text\n");
            else if ($type === "xwarning" || $type === "warning"
                     || !defined("HOTCRP_TESTHARNESS"))
                fwrite(STDOUT, "$text\n");
        } else if ($this->save_messages) {
            ensure_session();
            $this->save_session_array("msgs", true, array($type, $text));
        } else if ($type[0] == "x")
            echo Ht::xmsg($type, $text);
        else
            echo "<div class=\"$type\">$text</div>";
    }

    function infoMsg($text, $minimal = false) {
        $this->msg($minimal ? "xinfo" : "info", $text);
    }

    static public function msg_info($text, $minimal = false) {
        self::$g->msg($minimal ? "xinfo" : "info", $text);
    }

    function warnMsg($text, $minimal = false) {
        $this->msg($minimal ? "xwarning" : "warning", $text);
    }

    static public function msg_warning($text, $minimal = false) {
        self::$g->msg($minimal ? "xwarning" : "warning", $text);
    }

    function confirmMsg($text, $minimal = false) {
        $this->msg($minimal ? "xconfirm" : "confirm", $text);
    }

    static public function msg_confirm($text, $minimal = false) {
        self::$g->msg($minimal ? "xconfirm" : "confirm", $text);
    }

    function errorMsg($text, $minimal = false) {
        $this->msg($minimal ? "xmerror" : "merror", $text);
        return false;
    }

    static public function msg_error($text, $minimal = false) {
        self::$g->msg($minimal ? "xmerror" : "merror", $text);
        return false;
    }

    static public function msg_debugt($text) {
        if (is_object($text) || is_array($text) || $text === null || $text === false || $text === true)
            $text = json_encode($text);
        self::$g->msg("merror", Ht::pre_text_wrap($text));
        return false;
    }

    function post_missing_msg() {
        $this->msg("merror", "Your uploaded data wasn’t received. This can happen on unusually slow connections, or if you tried to upload a file larger than I can accept.");
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

    function encoded_session_list() {
        global $Now;
        if ($this->_session_list === false) {
            $this->_session_list = null;
            if (isset($_COOKIE["hotlist-info"])) {
                if (($j = json_decode($_COOKIE["hotlist-info"]))
                    && is_object($j) && isset($j->ids))
                    $this->_session_list = $j;
                setcookie("hotlist-info", "", $Now - 86400, Navigation::site_path());
            }
        }
        return $this->_session_list;
    }

    function session_list() {
        if (($j = $this->encoded_session_list())) {
            if (isset($j->ids) && is_string($j->ids)
                && preg_match('/\A[\s\d\']*\z/', $j->ids))
                $j->ids = array_map(function ($x) { return (int) $x; },
                                    preg_split('/[\s\']+/', $j->ids));
            if (isset($j->psetids) && is_string($j->psetids)
                && preg_match('/\A[\s\d\']*\z/', $j->psetids))
                $j->psetids = array_map(function ($x) { return (int) $x; },
                                        preg_split('/[\s\']+/', $j->psetids));
            if (isset($j->hashes) && is_string($j->hashes)
                && preg_match('/\A[\sA-Fa-fx\d\']*\z/', $j->hashes))
                $j->hashes = preg_split('/[\s\']+/', $j->hashes);
        }
        return $this->_session_list;
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

    private function header_head($title) {
        global $Me, $ConfSitePATH, $CurrentList;
        echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Language\" content=\"en\" />
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
        Ht::stash_html($this->make_script_file("scripts/jquery.flot.min.js", true) . "\n");

        // Javascript settings to set before script.js
        Ht::stash_script("siteurl=" . json_encode(Navigation::siteurl()) . ";siteurl_suffix=\"" . Navigation::php_suffix() . "\"");
        if (session_id() !== "")
            Ht::stash_script("siteurl_postvalue=\"" . post_value() . "\"");
        if (($list = $this->encoded_session_list()))
            Ht::stash_script("hotcrp_list=" . json_encode($list) . ";");
        if (($urldefaults = hoturl_defaults()))
            Ht::stash_script("siteurl_defaults=" . json_encode($urldefaults) . ";");
        Ht::stash_script("assetsurl=" . json_encode($this->opt["assetsUrl"]) . ";");
        $huser = (object) array();
        if ($Me && $Me->email)
            $huser->email = $Me->email;
        if ($Me && $Me->is_pclike())
            $huser->is_pclike = true;
        Ht::stash_script("hotcrp_user=" . json_encode($huser));

        // script.js
        if (!$this->opt("noDefaultScript"))
            Ht::stash_html($this->make_script_file("scripts/script.js") . "\n");

        // other scripts
        foreach ($this->opt("scripts", []) as $file)
            Ht::stash_html($this->make_script_file($file) . "\n");

        echo $stash, Ht::unstash(), "</head>\n";
    }

    function header($title, $id = "", $actionBar = null, $showTitle = true) {
        global $ConfSitePATH, $Me, $Now;
        if ($this->headerPrinted)
            return;

        // <head>
        if ($title === "Home")
            $title = "";
        $this->header_head($title);
        $this->encoded_session_list(); // clear cookie if set

        // <body>
        echo "<body", ($id ? " id=\"$id\"" : ""), " onload=\"hotcrp_load()\">\n";

        // initial load (JS's timezone offsets are negative of PHP's)
        Ht::stash_script("hotcrp_load.time(" . (-date("Z", $Now) / 60) . "," . ($this->opt("time24hour") ? 1 : 0) . ")");

        echo "<div id='prebody'>\n";

        echo "<div id='header'>\n<div id='header_left_conf'><h1>";
        if ($title && $showTitle && ($title == "Home" || $title == "Sign in"))
            echo "<a name='' class='qq' href='", hoturl("index"), "' title='Home'>", htmlspecialchars($this->short_name), "</a>";
        else
            echo "<a name='' class='uu' href='", hoturl("index"), "' title='Home'>", htmlspecialchars($this->short_name), "</a></h1></div><div id='header_left_page'><h1>", $title;
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
                && get($_SESSION, "trueuser")
                && ($Me->privChair || Contact::$trueuser_privChair === $Me)) {
                // Link becomes true user if not currently chair.
                if (!$Me->privChair || strcasecmp($Me->email, $actas) == 0)
                    $actas = $_SESSION["trueuser"]->email;
                if (strcasecmp($Me->email, $actas) != 0)
                    $profile_parts[] = "<a href=\"" . self_href(["actas" => $actas]) . "\">"
                        . ($Me->privChair ? htmlspecialchars($actas) : "Admin")
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

        echo $actionBar;

        echo "</div>\n<div id=\"initialmsgs\">\n";
        if (($x = $this->opt("maintenance")))
            echo "<div class=\"merror\"><strong>The site is down for maintenance.</strong> ", (is_string($x) ? $x : "Please check back later."), "</div>";
        $this->save_messages = false;
        if (($msgs = $this->session("msgs")) && count($msgs)) {
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
        echo json_encode($values);
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
        if (isset($this->_psets[$pset->id]))
            throw new Exception("pset id `{$pset->id}` reused");
        $this->_psets[$pset->id] = $pset;
        if (isset($this->_psets_by_urlkey[$pset->urlkey]))
            throw new Exception("pset urlkey `{$pset->urlkey}` reused");
        $this->_psets_by_urlkey[$pset->urlkey] = $pset;
    }

    function psets() {
        return $this->_psets;
    }

    function pset_by_id($id) {
        return get($this->_psets, $id);
    }

    function pset_by_key($key) {
        if (($p = get($this->_psets_by_urlkey, $key)))
            return $p;
        foreach ($this->_psets as $p)
            if ($key === $p->psetkey)
                return $p;
        return null;
    }


    function handout_repo(Pset $pset, Repository $inrepo = null) {
        global $Now, $ConfSitePATH;
        $url = $pset->handout_repo_url;
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
            if (!$hset)
                $save = $hset = (object) array();
            if (!($hme = get($hset, $hrepoid)))
                $save = $hme = $hset->$hrepoid = (object) array();
            if ((int) get($hme, $cacheid) + 300 < $Now
                && !$this->opt("disableGitfetch")
                && !$this->opt("disableRemote")) {
                $save = $hme->$cacheid = $Now;
                shell_exec("$ConfSitePATH/src/gitfetch $hrepo->repoid $cacheid " . escapeshellarg($hrepo->ssh_url()) . " </dev/null 1>&2 &");
            }
            if ($save)
                $this->save_setting("handoutrepos", 1, $hset);
        }
        return $hrepo;
    }

    function handout_commits(Pset $pset) {
        global $Now;
        if (($commits = get($this->_handout_commits, $pset->id)))
            return $commits;
        if (!($hrepo = $this->handout_repo($pset)))
            return null;
        $hrepoid = $hrepo->repoid;
        $key = "handoutcommits_{$hrepoid}_{$pset->id}";
        $hset = $this->setting_json($key);
        if (!$hset)
            $hset = (object) array();
        if (get($hset, "snaphash") !== $hrepo->snaphash
            || (int) get($hset, "snaphash_at") + 300 < $Now
            || !get($hset, "commits")) {
            $hset->snaphash = $hrepo->snaphash;
            $hset->snaphash_at = $Now;
            $hset->commits = [];
            foreach ($hrepo->commits($pset) as $c)
                $hset->commits[] = [$c->hash, $c->commitat, $c->subject];
            $this->save_setting($key, 1, $hset);
            $this->qe("delete from Settings where name!=? and name like 'handoutcommits_%_?s'", $key, $pset->id);
        }
        $commits = [];
        foreach ($hset->commits as $c)
            $commits[$c[0]] = new RepositoryCommitInfo($c[1], $c[0], $c[2]);
        $this->_handout_commits[$pset->id] = $commits;
        $latest = empty($commits) ? null : $commits[$hset->commits[0][0]];
        $this->_handout_latest_commit[$pset->id] = $latest;
        return $commits;
    }

    function latest_handout_commit(Pset $pset) {
        if (!array_key_exists($pset->id, $this->_handout_latest_commit))
            $this->handout_commits($pset);
        return get($this->_handout_latest_commit, $pset->id);
    }
}
