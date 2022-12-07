<?php
// conference.php -- HotCRP central helper class (singleton)
// HotCRP is Copyright (c) 2006-2021 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class APIData {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var ?Pset */
    public $pset;
    /** @var ?Repository */
    public $repo;
    /** @var ?string */
    public $branch;
    /** @var ?string */
    public $hash;
    /** @var ?CommitRecord */
    public $commit;
    /** @var int */
    public $at;

    const ERRORCODE_RUNCONFLICT = 1001;

    function __construct(Contact $user, Pset $pset = null, Repository $repo = null) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->pset = $pset;
        $this->repo = $repo;
        $this->at = Conf::$now;
    }
    /** @param PsetView $info
     * @return ?array */
    function prepare_commit($info) {
        if ($this->repo) {
            $this->commit = $this->conf->check_api_hash($this->hash, $this);
            if ($this->commit) {
                $info->set_commit($this->commit);
                return null;
            } else if ($this->hash) {
                return ["ok" => false, "error" => "Disconnected commit."];
            } else {
                return ["ok" => false, "error" => "Missing commit."];
            }
        } else {
            return ["ok" => false, "error" => "Missing repository."];
        }
    }
    /** @param PsetView $info
     * @return ?array */
    function prepare_grading_commit($info) {
        return $this->pset->gitless_grades ? null : $this->prepare_commit($info);
    }
}

class Conf {
    /** @var ?mysqli
     * @readonly */
    public $dblink;
    /** @var string
     * @readonly */
    public $dbname;
    /** @var string
     * @readonly */
    public $session_key;

    /** @var array<string,int> */
    private $settings;
    /** @var array<string,null|string|object> */
    private $settingTexts;
    /** @var int */
    public $sversion;
    private $_gsettings = [];
    private $_gsettings_data = [];
    /** @var array|true */
    private $_gsettings_loaded = [];

    public $dsn = null;

    /** @var string */
    public $short_name;
    /** @var string */
    public $long_name;
    /** @var int */
    public $default_format;
    /** @var string */
    public $download_prefix;
    public $sort_by_last;
    /** @var array<string,mixed> */
    public $opt;
    /** @var array<string,mixed> */
    public $opt_override;

    /** @var object */
    public $config;
    /** @var ?string */
    public $default_main_branch;

    public $validate_timeout;
    public $validate_overall_timeout;

    /** @var bool
     * @readonly */
    public $multiuser_page = false;
    /** @var bool */
    public $_header_printed = false;
    public $_session_handler;
    /** @var ?list<array{string,string}> */
    private $_save_msgs;
    /** @var false|null|array<string,mixed> */
    private $_session_list = false;
    /** @var ?Collator */
    private $_collator;

    private $usertimeId = 1;

    /** @var array<int,Pset> */
    private $_psets = [];
    /** @var array<string,Pset> */
    private $_psets_by_urlkey = [];
    /** @var bool */
    private $_psets_sorted = false;
    /** @var ?list<Pset> */
    private $_psets_newest_first;
    /** @var array<string,float> */
    private $_category_weight = [];
    /** @var array<string,bool> */
    private $_category_weight_default = [];
    /** @var array<string,list<Pset>> */
    private $_psets_by_category;
    /** @var array<string,bool> */
    private $_category_has_extra;
    /** @var array<string,QueueConfig> */
    private $_queues = [];

    /** @var ?list<FormulaConfig> */
    private $_global_formulas;
    /** @var array<string,FormulaConfig> */
    private $_formulas_by_name;
    /** @var list<GradeFormula> */
    private $_canon_formulas = [];

    /** @var bool */
    private $_date_format_initialized = false;
    /** @var ?DateTimeZone */
    private $_dtz;
    /** @var ?array<int,Contact> */
    private $_pc_members_cache;
    private $_pc_tags_cache;
    private $_pc_members_and_admins_cache;
    /** @var ?array<int,string> */
    private $_username_cache;
    /** @var ?array<int,string> */
    private $_anon_username_cache;
    /** @var ?Contact */
    private $_root_user;
    /** @var ?Contact */
    private $_site_contact;
    /** @var array<string,Repository> */
    private $_handout_repos = [];
    /** @var array<int,array<string,CommitRecord>> */
    private $_handout_commits = [];
    /** @var array<int,?CommitRecord> */
    private $_handout_latest_commit = [];
    private $_api_map;
    private $_repository_site_classes;
    private $_branch_map;
    const USERNAME_GITHUB = 1;
    const USERNAME_EMAIL = 2;
    const USERNAME_HUID = 4;
    const USERNAME_USERNAME = 8;
    /** @var int */
    private $_username_classes = 0;

    /** @var false|null|SessionList */
    private $_active_list = false;
    /** @var ?array */
    private $_siteinfo;

    /** @var Conf */
    static public $main;
    /** @var int */
    static public $now;
    /** @var int|float */
    static public $unow;
    /** @var float */
    static public $blocked_time = 0.0;

    static public $next_xt_subposition = 0;

    const INVALID_TOKEN = "INVALID";

    /** @param array<string,mixed> $options
     * @param bool $connect */
    function __construct($options, $connect) {
        // unpack dsn, connect to database, load current settings
        if (($cp = Dbl::parse_connection_params($options))) {
            $this->dblink = $connect ? $cp->connect() : null;
            $this->dbname = $cp->name;
            $this->session_key = "@{$this->dbname}";
        }
        $this->opt = $options;
        $this->opt["confid"] = $this->opt["confid"] ?? $this->dbname;
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

    /** @param int|float $t */
    static function set_current_time($t) {
        global $Now;
        self::$unow = $t;
        $Now = Conf::$now = (int) $t;
    }

    /** @param int|float $advance_past */
    static function advance_current_time($advance_past) {
        if ($advance_past + 1 > Conf::$now) {
            self::set_current_time($advance_past + 1);
        }
    }


    //
    // Initialization functions
    //

    function load_settings() {
        // load settings from database
        $this->settings = [];
        $this->settingTexts = [];
        foreach ($this->opt_override ? : [] as $k => $v) {
            if ($v === null) {
                unset($this->opt[$k]);
            } else {
                $this->opt[$k] = $v;
            }
        }
        $this->opt_override = [];

        $result = $this->q_raw("select name, value, data from Settings");
        while ($result && ($row = $result->fetch_row())) {
            $this->settings[$row[0]] = (int) $row[1];
            if ($row[2] !== null) {
                $this->settingTexts[$row[0]] = $row[2];
            }
            if (substr($row[0], 0, 4) == "opt.") {
                $okey = substr($row[0], 4);
                $this->opt_override[$okey] = $this->opt[$okey] ?? null;
                $this->opt[$okey] = ($row[2] === null ? (int) $row[1] : $row[2]);
            }
        }
        Dbl::free($result);

        // update schema
        $this->sversion = $this->settings["allowPaperOption"];
        if ($this->sversion < 171) {
            $old_nerrors = Dbl::$nerrors;
            (new UpdateSchema($this))->run();
            Dbl::$nerrors = $old_nerrors;
        }

        // invalidate all caches after loading from backup
        if (isset($this->settings["frombackup"])
            && $this->invalidate_caches()) {
            $this->qe_raw("delete from Settings where name='frombackup' and value=" . $this->settings["frombackup"]);
            unset($this->settings["frombackup"]);
        } else {
            $this->invalidate_caches(["rf" => true]);
        }

        // update options
        if (isset($this->opt["ldapLogin"]) && !$this->opt["ldapLogin"]) {
            unset($this->opt["ldapLogin"]);
        }
        if (isset($this->opt["httpAuthLogin"]) && !$this->opt["httpAuthLogin"]) {
            unset($this->opt["httpAuthLogin"]);
        }

        // set conferenceKey
        if (!isset($this->opt["conferenceKey"])) {
            if (!isset($this->settingTexts["conf_key"])
                && ($key = random_bytes(32)) !== false) {
                $this->save_setting("conf_key", 1, $key);
            }
            $this->opt["conferenceKey"] = $this->settingTexts["conf_key"] ?? "";
        }

        // set capability key
        if (!($this->settings["cap_key"] ?? null)
            && !($this->opt["disableCapabilities"] ?? null)
            && !(($key = random_bytes(16)) !== false
                 && ($key = base64_encode($key))
                 && $this->save_setting("cap_key", 1, $key))) {
            $this->opt["disableCapabilities"] = true;
        }

        // GC old capabilities
        if (($this->settings["__capability_gc"] ?? 0) < Conf::$now - 86400) {
            foreach (array($this->dblink, Contact::contactdb()) as $db) {
                if ($db)
                    Dbl::ql($db, "delete from Capability where timeExpires>0 and timeExpires<" . Conf::$now);
            }
            $this->q_raw("insert into Settings (name, value) values ('__capability_gc', " . Conf::$now . ") on duplicate key update value=values(value)");
            $this->settings["__capability_gc"] = Conf::$now;
        }

        $this->crosscheck_settings();
        $this->crosscheck_options();
    }

    private function crosscheck_settings() {
    }

    private function crosscheck_options() {
        // set longName, downloadPrefix, etc.
        $confid = $this->opt["confid"];
        if ((!isset($this->opt["longName"]) || $this->opt["longName"] == "")
            && (!isset($this->opt["shortName"]) || $this->opt["shortName"] == "")) {
            $this->opt["shortNameDefaulted"] = true;
            $this->opt["longName"] = $this->opt["shortName"] = $confid;
        } else if (!isset($this->opt["longName"]) || $this->opt["longName"] == "") {
            $this->opt["longName"] = $this->opt["shortName"];
        } else if (!isset($this->opt["shortName"]) || $this->opt["shortName"] == "") {
            $this->opt["shortName"] = $this->opt["longName"];
        }
        if (!isset($this->opt["downloadPrefix"]) || $this->opt["downloadPrefix"] == "") {
            $this->opt["downloadPrefix"] = $confid . "-";
        }
        $this->short_name = $this->opt["shortName"];
        $this->long_name = $this->opt["longName"];

        // expand ${confid}, ${confshortname}
        foreach (["sessionName", "downloadPrefix", "conferenceSite",
                  "paperSite", "defaultPaperSite", "contactName",
                  "contactEmail", "docstore"] as $k) {
            if (isset($this->opt[$k]) && is_string($this->opt[$k])
                && strpos($this->opt[$k], "\$") !== false) {
                $this->opt[$k] = preg_replace(',\$\{confid\}|\$confid\b,', $confid, $this->opt[$k]);
                $this->opt[$k] = preg_replace(',\$\{confshortname\}|\$confshortname\b,', $this->short_name, $this->opt[$k]);
            }
        }
        $this->download_prefix = $this->opt["downloadPrefix"];

        foreach (["emailFrom", "emailSender", "emailCc", "emailReplyTo"] as $k) {
            if (isset($this->opt[$k]) && is_string($this->opt[$k])
                && strpos($this->opt[$k], "\$") !== false) {
                $this->opt[$k] = preg_replace('/\$\{confid\}|\$confid\b/', $confid, $this->opt[$k]);
                if (strpos($this->opt[$k], "confshortname") !== false) {
                    $v = rfc2822_words_quote($this->short_name);
                    if ($v[0] === "\"" && strpos($this->opt[$k], "\"") !== false) {
                        $v = substr($v, 1, strlen($v) - 2);
                    }
                    $this->opt[$k] = preg_replace('/\$\{confshortname\}|\$confshortname\b/', $v, $this->opt[$k]);
                }
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
        foreach (["assetsURL" => "assetsUrl",
                  "jqueryURL" => "jqueryUrl", "jqueryCDN" => "jqueryCdn",
                  "disableCSV" => "disableCsv"] as $kold => $knew) {
            if (isset($this->opt[$kold]) && !isset($this->opt[$knew])) {
                $this->opt[$knew] = $this->opt[$kold];
            }
        }

        // set assetsUrl and scriptAssetsUrl
        if (!isset($this->opt["scriptAssetsUrl"])
            && isset($_SERVER["HTTP_USER_AGENT"])
            && strpos($_SERVER["HTTP_USER_AGENT"], "MSIE") !== false) {
            $this->opt["scriptAssetsUrl"] = Navigation::siteurl();
        }
        if (!isset($this->opt["assetsUrl"])) {
            $this->opt["assetsUrl"] = (string) Navigation::siteurl();
        }
        if ($this->opt["assetsUrl"] !== ""
            && !str_ends_with($this->opt["assetsUrl"], "/")) {
            $this->opt["assetsUrl"] .= "/";
        }
        if (!isset($this->opt["scriptAssetsUrl"])) {
            $this->opt["scriptAssetsUrl"] = $this->opt["assetsUrl"];
        }

        // clean stylesheets and scripts
        $this->opt["stylesheets"] = $this->opt["stylesheets"] ?? [];
        if (is_string($this->opt["stylesheets"])) {
            $this->opt["stylesheets"] = [$this->opt["stylesheets"]];
        }
        $this->opt["scripts"] = $this->opt["scripts"] ?? $this->opt["javascripts"] ?? [];
        if (is_string($this->opt["scripts"])) {
            $this->opt["scripts"] = [$this->opt["scripts"]];
        }

        // set safePasswords
        if (!($this->opt["safePasswords"] ?? null)
            || (is_int($this->opt["safePasswords"]) && $this->opt["safePasswords"] < 1)) {
            $this->opt["safePasswords"] = 0;
        } else if ($this->opt["safePasswords"] === true) {
            $this->opt["safePasswords"] = 1;
        }
        if (!isset($this->opt["contactdb_safePasswords"])) {
            $this->opt["contactdb_safePasswords"] = $this->opt["safePasswords"];
        }

        // set validate timeouts
        $this->validate_timeout = (float) ($this->opt["validateTimeout"] ?? 5);
        if ($this->validate_timeout <= 0) {
            $this->validate_timeout = 5;
        }
        $this->validate_overall_timeout = (float) ($this->opt["validateOverallTimeout"] ?? 15);
        if ($this->validate_overall_timeout <= 0) {
            $this->validate_overall_timeout = 5;
        }

        // repository site classes
        $this->_repository_site_classes = ["github"];
        if (isset($this->opt["repositorySites"])) {
            $x = $this->opt["repositorySites"];
            if (is_array($x)
                || (is_string($x) && ($x = json_decode($x)) && is_array($x))) {
                $this->_repository_site_classes = $x;
            }
        }
        $this->_username_classes = 0;
        if (in_array("github", $this->_repository_site_classes)) {
            $this->_username_classes |= self::USERNAME_GITHUB;
        }

        // check tokens
        if (isset($this->opt["githubOAuthToken"])
            && !preg_match('/\A[-a-zA-Z0-9_.]+\z/', $this->opt["githubOAuthToken"])) {
            $this->opt["githubOAuthToken"] = self::INVALID_TOKEN;
        }

        $sort_by_last = !!($this->opt["sortByLastName"] ?? false);
        if (!$this->sort_by_last != !$sort_by_last) {
            $this->_pc_members_cache = $this->_pc_members_and_admins_cache = null;
        }
        $this->_username_cache = $this->_anon_username_cache = null;
        $this->sort_by_last = $sort_by_last;
        $this->default_format = (int) ($this->opt["defaultFormat"] ?? 0);
        $this->_root_user = $this->_site_contact = null;
        $this->_api_map = null;
        $this->_date_format_initialized = false;
        $this->_dtz = null;
    }

    function crosscheck_globals() {
        Ht::$img_base = $this->opt["assetsUrl"] . "images/";

        if (isset($this->opt["timezone"])) {
            if (!date_default_timezone_set($this->opt["timezone"])) {
                self::msg_error("Timezone option “" . htmlspecialchars($this->opt["timezone"]) . "” is invalid; falling back to “America/New_York”.");
                date_default_timezone_set("America/New_York");
            }
        } else if (!ini_get("date.timezone") && !getenv("TZ")) {
            date_default_timezone_set("America/New_York");
        }
    }

    static function set_main_instance(Conf $conf) {
        global $Conf;
        $Conf = Conf::$main = $conf;
        $conf->crosscheck_globals();
    }


    /** @param object $config */
    function set_config($config) {
        assert(isset($config->_defaults));
        assert($this->config === null);
        $this->config = $config;

        // parse psets
        foreach (get_object_vars($config) as $pk => $p) {
            if (!str_starts_with($pk, "_")
                && is_object($p)
                && isset($p->psetid)) {
                if (isset($p->group) && is_string($p->group)) {
                    $g = "_defaults_" . $p->group;
                    if (isset($config->$g)) {
                        object_merge_recursive($p, $config->$g);
                    }
                }
                object_merge_recursive($p, $config->_defaults);
                try {
                    $pset = new Pset($this, $pk, $p);
                    $this->register_pset($pset);
                } catch (PsetConfigException $exception) {
                    $exception->key = $pk;
                    throw $exception;
                }
            }
        }

        // parse queues
        if (is_object($config->_queues ?? null)) {
            foreach (get_object_vars($config->_queues) as $qk => $q) {
                $queue = QueueConfig::make_named($qk, $q);
                $this->_queues[$queue->key] = $queue;
            }
        }

        // extract defaults
        if ($config->_defaults->main_branch ?? null) {
            $this->set_default_main_branch($config->_defaults->main_branch);
        }
        if (!($config->_messagedefs ?? null)) {
            $config->_messagedefs = (object) array();
        }
        if (!($config->_messagedefs->SYSTEAM ?? null)) {
            $config->_messagedefs->SYSTEAM = "cs61-staff";
        }
    }

    /** @param string $b */
    function set_default_main_branch($b) {
        $this->default_main_branch = $b;
    }


    /** @return bool */
    function has_setting($name) {
        return isset($this->settings[$name]);
    }

    /** @param string $name
     * @return ?int */
    function setting($name) {
        return $this->settings[$name] ?? null;
    }

    /** @param string $name
     * @return ?string */
    function setting_data($name) {
        $x = $this->settingTexts[$name] ?? null;
        if ($x && is_object($x) && isset($this->settingTexts[$name])) {
            $x = $this->settingTexts[$name] = json_encode_db($x);
        }
        return $x;
    }

    /** @param string $name
     * @return ?object */
    function setting_json($name) {
        $x = $this->settingTexts[$name] ?? null;
        if ($x && is_string($x) && is_object(($o = json_decode($x)))) {
            $this->settingTexts[$name] = $o;
            return $o;
        } else {
            return $x;
        }
    }

    /** @param string $name
     * @param ?int $value */
    function __save_setting($name, $value, $data = null) {
        $change = false;
        if ($value === null && $data === null) {
            $result = $this->qe("delete from Settings where name=?", $name);
            if (!Dbl::is_error($result)) {
                unset($this->settings[$name], $this->settingTexts[$name]);
                $change = true;
            }
        } else {
            $value = (int) $value;
            $dval = $data;
            if (is_array($dval) || is_object($dval)) {
                $dval = json_encode_db($dval);
            }
            $result = $this->qe("insert into Settings set name=?, value=?, data=? on duplicate key update value=values(value), data=values(data)", $name, $value, $dval);
            if (!Dbl::is_error($result)) {
                $this->settings[$name] = $value;
                $this->settingTexts[$name] = $data;
                $change = true;
            }
        }
        if ($change && str_starts_with($name, "opt.")) {
            $oname = substr($name, 4);
            if ($value === null && $data === null) {
                $this->opt[$oname] = $this->opt_override[$oname] ?? null;
            } else {
                $this->opt[$oname] = $data === null ? $value : $data;
            }
        }
        return $change;
    }

    /** @param string $name
     * @param ?int $value */
    function save_setting($name, $value, $data = null) {
        $change = $this->__save_setting($name, $value, $data);
        if ($change) {
            $this->crosscheck_settings();
            if (str_starts_with($name, "opt.")) {
                $this->crosscheck_options();
            }
        }
        return $change;
    }


    /** @param ?string $name */
    function load_gsetting($name) {
        if ($name === null || $name === "") {
            $this->_gsettings_loaded = true;
            $filter = function ($k) { return false; };
            $where = "true";
            $qv = [];
        } else if (($dot = strpos($name, ".")) === false) {
            if ($this->_gsettings_loaded !== true) {
                $this->_gsettings_loaded[$name] = true;
            }
            $filter = function ($k) use ($name) {
                return substr($k, 0, strlen($name)) !== $name
                    || ($k !== $name && $k[strlen($name)] !== ".");
            };
            $where = "name=? or (name>=? and name<=?)";
            $qv = [$name, $name . ".", $name . "/"];
        } else {
            if ($this->_gsettings_loaded !== true) {
                $this->_gsettings_loaded[$name] = true;
            }
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

    /** @param ?string $name */
    function ensure_gsetting($name) {
        if ($this->_gsettings_loaded !== true
            && !isset($this->_gsettings_loaded[$name])
            && (($dot = strpos($name, ".")) === false
                || !isset($this->_gsettings_loaded[substr($name, 0, $dot)]))) {
            $this->load_gsetting($name);
        }
    }

    /** @param string $name
     * @return ?int */
    function gsetting($name) {
        $this->ensure_gsetting($name);
        return $this->_gsettings[$name] ?? null;
    }

    /** @param string $name
     * @return ?string */
    function gsetting_data($name) {
        $this->ensure_gsetting($name);
        $x = $this->_gsettings_data[$name] ?? null;
        if ($x && is_object($x) && isset($this->_gsettings_data[$name])) {
            $x = $this->_gsettings_data[$name] = json_encode_db($x);
        }
        return $x;
    }

    /** @param string $name
     * @return ?object */
    function gsetting_json($name) {
        $this->ensure_gsetting($name);
        $x = $this->_gsettings_data[$name] ?? null;
        if ($x && is_string($x) && isset($this->_gsettings_data[$name])
            && is_object(($x = json_decode($x)))) {
            $this->_gsettings_data[$name] = $x;
        }
        return $x;
    }

    /** @param string $name
     * @param ?int $value
     * @param mixed $data
     * @return bool */
    function save_gsetting($name, $value, $data = null) {
        $change = false;
        if ($value === null && $data === null) {
            $result = $this->qe("delete from GroupSettings where name=?", $name);
            if (!Dbl::is_error($result)) {
                unset($this->_gsettings[$name], $this->_gsettings_data[$name]);
                $change = true;
            }
        } else {
            $value = (int) $value;
            $dval = $data;
            if (is_array($dval) || is_object($dval)) {
                $dval = json_encode_db($dval);
            }
            if (strlen($dval) > 32700) {
                $odval = $dval;
                $dval = null;
            } else {
                $odval = null;
            }
            $result = $this->qe("insert into GroupSettings set name=?, value=?, data=?, dataOverflow=? on duplicate key update value=values(value), data=values(data), dataOverflow=values(dataOverflow)", $name, $value, $dval, $odval);
            if (!Dbl::is_error($result)) {
                $this->_gsettings[$name] = $value;
                $this->_gsettings_data[$name] = isset($odval) ? $odval : $dval;
                $change = true;
            }
        }
        if ($this->_gsettings_loaded !== true) {
            $this->_gsettings_loaded[$name] = true;
        }
        return $change;
    }


    /** @param string $name
     * @return mixed */
    function opt($name) {
        return $this->opt[$name] ?? null;
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

    /** @return Dbl_Result */
    function q(/* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), 0);
    }
    /** @return Dbl_Result */
    function q_raw(/* $qstr */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_RAW);
    }
    /** @return Dbl_Result */
    function q_apply(/* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_APPLY);
    }

    /** @return Dbl_Result */
    function ql(/* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_LOG);
    }
    /** @return Dbl_Result */
    function ql_raw(/* $qstr */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_RAW | Dbl::F_LOG);
    }
    /** @return Dbl_Result */
    function ql_apply(/* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_APPLY | Dbl::F_LOG);
    }
    /** @return ?Dbl_Result */
    function ql_ok(/* $qstr, ... */) {
        $result = Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_LOG);
        return Dbl::is_error($result) ? null : $result;
    }

    /** @return Dbl_Result */
    function qe(/* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR);
    }
    /** @return Dbl_Result */
    function qe_raw(/* $qstr */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_RAW | Dbl::F_ERROR);
    }
    /** @return Dbl_Result */
    function qe_apply(/* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_APPLY | Dbl::F_ERROR);
    }

    /** @return list<list<?string>> */
    function fetch_rows(/* $qstr, ... */) {
        return Dbl::fetch_rows(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }
    /** @return ?list<?string> */
    function fetch_first_row(/* $qstr, ... */) {
        return Dbl::fetch_first_row(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }
    /** @return ?object */
    function fetch_first_object(/* $qstr, ... */) {
        return Dbl::fetch_first_object(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }
    /** @return ?string */
    function fetch_value(/* $qstr, ... */) {
        return Dbl::fetch_value(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }
    /** @return ?int */
    function fetch_ivalue(/* $qstr, ... */) {
        return Dbl::fetch_ivalue(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }

    function db_error_html($getdb = true) {
        $text = "<p>Database error";
        if ($getdb) {
            $text .= ": " . htmlspecialchars($this->dblink->error);
        }
        return $text . "</p>";
    }

    function db_error_text($getdb = true) {
        $text = "Database error";
        if ($getdb) {
            $text .= ": " . $this->dblink->error;
        }
        return $text;
    }

    function query_error_handler($dblink, $query) {
        $landmark = caller_landmark(1, "/^(?:Dbl::|Conf::q|call_user_func)/");
        if (PHP_SAPI == "cli") {
            fwrite(STDERR, "$landmark: database error: $dblink->error in $query\n");
        } else {
            error_log("$landmark: database error: $dblink->error in $query");
            self::msg_error("<p>" . htmlspecialchars($landmark) . ": database error: " . htmlspecialchars($this->dblink->error) . " in " . Ht::pre_text_wrap($query) . "</p>");
        }
    }


    /** @return Collator */
    function collator() {
        if (!$this->_collator) {
            $this->_collator = new Collator("en_US.utf8");
            $this->_collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        }
        return $this->_collator;
    }

    /** @param ?int $sortspec
     * @return callable(Contact,Contact):int */
    function user_comparator($sortspec = null) {
        $sortspec = $sortspec ?? ($this->sort_by_last ? 0312 : 0321);
        $collator = $this->collator();
        return function ($a, $b) use ($sortspec, $collator) {
            $as = Contact::get_sorter($a, $sortspec);
            $bs = Contact::get_sorter($b, $sortspec);
            return $collator->compare($as, $bs);
        };
    }


    // name

    /** @return string */
    function full_name() {
        if ($this->short_name && $this->short_name != $this->long_name) {
            return $this->long_name . " (" . $this->short_name . ")";
        } else {
            return $this->long_name;
        }
    }


    // users

    /** @return bool */
    function external_login() {
        return isset($this->opt["ldapLogin"]) || isset($this->opt["httpAuthLogin"]);
    }

    /** @return object */
    function default_site_contact() {
        $result = $this->ql("select firstName, lastName, affiliation, email from ContactInfo where roles!=0 and (roles&" . (Contact::ROLE_CHAIR | Contact::ROLE_ADMIN) . ")!=0 order by (roles&" . Contact::ROLE_CHAIR . ") desc, contactId asc limit 1");
        $chair = $result->fetch_object();
        Dbl::free($result);
        return $chair;
    }

    /** @return Contact */
    function root_user() {
        if (!$this->_root_user) {
            $this->_root_user = Contact::make_site_contact($this, ["email" => "rootuser"]);
        }
        return $this->_root_user;
    }

    /** @return Contact */
    function site_contact() {
        if (!$this->_site_contact) {
            $args = ["email" => $this->opt("contactEmail") ?? ""];
            if (($args["email"] === "" || $args["email"] === "you@example.com")
                && ($row = $this->default_site_contact())) {
                $args["email"] = $row->email;
                $args["firstName"] = $row->firstName;
                $args["lastName"] = $row->lastName;
            } else if (($name = $this->opt("contactName"))) {
                list($args["firstName"], $args["lastName"]) = Text::split_name($name);
            }
            $this->_site_contact = Contact::make_site_contact($this, $args);
        }
        return $this->_site_contact;
    }

    /** @param int $id
     * @return ?Contact */
    function user_by_id($id) {
        $result = $this->qe("select ContactInfo.* from ContactInfo where contactId=?", $id);
        $u = Contact::fetch($result, $this);
        Dbl::free($result);
        return $u;
    }

    /** @param list<int> $ids
     * @return array<int,Contact> */
    function users_by_id($ids) {
        $result = $this->qe("select ContactInfo.* from ContactInfo where contactId?a", $ids);
        $us = [];
        while (($u = Contact::fetch($result, $this))) {
            $us[$u->contactId] = $u;
        }
        Dbl::free($result);
        return $us;
    }

    /** @param string $email
     * @return ?Contact */
    function fresh_user_by_email($email) {
        $u = null;
        if (($email = trim((string) $email)) !== "") {
            $result = $this->qe("select * from ContactInfo where email=?", $email);
            $u = Contact::fetch($result, $this);
            Dbl::free($result);
        }
        return $u;
    }

    /** @param string $email
     * @return ?Contact */
    function user_by_email($email) {
        return $this->fresh_user_by_email($email);
    }

    /** @return ?Contact */
    function user_by_query($qpart, $args) {
        $result = $this->qe_apply("select ContactInfo.* from ContactInfo where $qpart", $args);
        $u = Contact::fetch($result, $this);
        Dbl::free($result);
        return $u && $u->contactId ? $u : null;
    }

    /** @param int $types
     * @return ?Contact */
    function user_by_whatever($whatever, $types = 0) {
        if ($types === 0) {
            $types = $this->_username_classes | self::USERNAME_HUID | self::USERNAME_EMAIL;
        } else if ($types & self::USERNAME_USERNAME) {
            $types |= $this->_username_classes;
        }
        $q = $qv = [];
        $whatever = trim($whatever);
        $user_type = 0;
        $guess = Contact::$main_user;
        if ($whatever === "") {
            return null;
        } else if (str_starts_with($whatever, "[anon")) {
            if ($guess && $guess->anon_username === $whatever) {
                return $guess;
            }
            $q[] = "anon_username=?";
            $qv[] = $whatever;
            $user_type = 1;
        } else if (strpos($whatever, "@") === false) {
            if ($types & self::USERNAME_GITHUB) {
                if ($guess && $guess->github_username === $whatever) {
                    return $guess;
                }
                $q[] = "github_username=" . Dbl::utf8ci("?");
                $qv[] = $whatever;
            }
            if (($types & self::USERNAME_HUID) && ctype_digit($whatever)) {
                if ($guess && $guess->huid === intval($whatever)) {
                    return $guess;
                }
                $q[] = "huid=?";
                $qv[] = $whatever;
            }
            $user_type = 2;
        } else if ($types & self::USERNAME_EMAIL) {
            if (str_ends_with($whatever, "@*")) {
                $q[] = "email like '" . sqlq_for_like(substr($whatever, 0, -1)) . "%'";
            } else {
                if ($guess && $guess->email === $whatever) {
                    return $guess;
                }
                $q[] = "email=?";
                $qv[] = $whatever;
            }
        }
        if (empty($q)) {
            return null;
        }
        $result = $this->qe_apply("select * from ContactInfo where " . join(" or ", $q), $qv);
        $users = [];
        while (($user = Contact::fetch($result, $this))) {
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

    /** @param string $email
     * @return int|false */
    function user_id_by_email($email) {
        $result = $this->qe("select contactId from ContactInfo where email=?", trim($email));
        $row = $result->fetch_row();
        Dbl::free($result);
        return $row ? (int) $row[0] : false;
    }

    /** @param int $uid
     * @param bool $anonymous
     * @return string */
    function cached_username_by_id($uid, $anonymous = false) {
        if ($this->_username_cache === null) {
            $this->_username_cache = $this->_anon_username_cache = [];
            $result = $this->qe("select contactId, github_username, email, anon_username from ContactInfo");
            while (($row = $result->fetch_row())) {
                $u = intval($row[0]);
                if ($row[1] === null || $row[1] === "") {
                    $this->_username_cache[$u] = $row[2];
                } else {
                    $this->_username_cache[$u] = $row[1];
                }
                $this->_anon_username_cache[$u] = $row[3];
            }
            Dbl::free($result);
        }
        $unc = $anonymous ? $this->_anon_username_cache : $this->_username_cache;
        return $unc[$uid] ?? "[user{$uid}]";
    }

    /** @return associative-array<int,Contact> */
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
            }
            Dbl::free($result);
            uasort($pc, $this->user_comparator());
            $order = 0;
            foreach ($pc as $row) {
                $row->sort_position = $order;
                ++$order;
            }
            $this->_pc_members_cache = $pc;
            uasort($pca, $this->user_comparator());
            $this->_pc_members_and_admins_cache = $pca;
            ksort($this->_pc_tags_cache);
        }
        return $this->_pc_members_cache;
    }

    /** @return associative-array<int,Contact> */
    function pc_members_and_admins() {
        if ($this->_pc_members_and_admins_cache === null) {
            $this->pc_members();
        }
        return $this->_pc_members_and_admins_cache;
    }

    /** @param string $email
     * @return ?Contact */
    function pc_member_by_email($email) {
        foreach ($this->pc_members() as $p) {
            if (strcasecmp($p->email, $email) == 0)
                return $p;
        }
        return null;
    }

    function pc_tags() {
        if ($this->_pc_tags_cache === null) {
            $this->pc_members();
        }
        return $this->_pc_tags_cache;
    }

    function pc_tag_exists($tag) {
        if ($this->_pc_tags_cache === null) {
            $this->pc_members();
        }
        return isset($this->_pc_tags_cache[strtolower($tag)]);
    }


    // session data

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

    /** @return DateTimeZone */
    function timezone() {
        if ($this->_dtz === null) {
            $this->_dtz = timezone_open($this->opt["timezone"] ?? date_default_timezone_get());
        }
        return $this->_dtz;
    }
    /** @param string $format
     * @param int|float $t
     * @return string */
    private function _date_format($format, $t) {
        if ($this !== self::$main && !$this->_dtz && isset($this->opt["timezone"])) {
            $this->timezone();
        }
        if ($this->_dtz) {
            $dt = new DateTime("@" . (int) $t);
            $dt->setTimeZone($this->_dtz);
            return $dt->format($format);
        } else {
            return date($format, $t);
        }
    }
    /** @param string $type
     * @param int|float $t
     * @return string */
    private function _date_unparse($type, $t) {
        if (!$this->_date_format_initialized) {
            if (!isset($this->opt["time24hour"]) && isset($this->opt["time24Hour"])) {
                $this->opt["time24hour"] = $this->opt["time24Hour"];
            }
            if (!isset($this->opt["dateFormatLong"]) && isset($this->opt["dateFormat"])) {
                $this->opt["dateFormatLong"] = $this->opt["dateFormat"];
            }
            if (!isset($this->opt["dateFormat"])) {
                $this->opt["dateFormat"] = ($this->opt["time24hour"] ?? false) ? "j M Y H:i:s" : "j M Y g:i:sa";
            }
            if (!isset($this->opt["dateFormatLong"])) {
                $this->opt["dateFormatLong"] = "l " . $this->opt["dateFormat"];
            }
            if (!isset($this->opt["dateFormatObscure"])) {
                $this->opt["dateFormatObscure"] = "j M Y";
            }
            if (!isset($this->opt["timestampFormat"])) {
                $this->opt["timestampFormat"] = $this->opt["dateFormat"];
            }
            if (!isset($this->opt["dateFormatSimplifier"])) {
                $this->opt["dateFormatSimplifier"] = ($this->opt["time24hour"] ?? false) ? "/:00(?!:)/" : "/:00(?::00|)(?= ?[ap]m)/";
            }
            $this->_date_format_initialized = true;
        }
        if ($type === "timestamp") {
            $f = $this->opt["timestampFormat"];
        } else if ($type === "obscure") {
            $f = $this->opt["dateFormatObscure"];
        } else if ($type === "long") {
            $f = $this->opt["dateFormatLong"];
        } else if ($type === "zone") {
            $f = "T";
        } else {
            $f = $this->opt["dateFormat"];
        }
        return $this->_date_format($f, $t);
    }
    /** @param int|float $value
     * @return string */
    private function _unparse_timezone($value) {
        $z = $this->opt["dateFormatTimezone"] ?? null;
        if ($z === null) {
            $z = $this->_date_unparse("zone", $value);
            if ($z === "-12") {
                $z = "AoE";
            } else if ($z && ($z[0] === "+" || $z[0] === "-")) {
                $z = "UTC" . $z;
            }
        }
        return $z;
    }

    /** @param int $value
     * @param bool $include_zone
     * @return string */
    function parseableTime($value, $include_zone) {
        $d = $this->_date_unparse("short", $value);
        if ($this->opt["dateFormatSimplifier"]) {
            $d = preg_replace($this->opt["dateFormatSimplifier"], "", $d);
        }
        if ($include_zone && ($z = $this->_unparse_timezone($value))) {
            $d .= " $z";
        }
        return $d;
    }
    /** @param string $d
     * @param ?int $reference
     * @return int|float|false */
    function parse_time($d, $reference = null) {
        $reference = $reference ?? Conf::$now;
        if (!isset($this->opt["dateFormatTimezoneRemover"])) {
            $x = array();
            if (function_exists("timezone_abbreviations_list")) {
                $mytz = date_default_timezone_get();
                foreach (timezone_abbreviations_list() as $tzname => $tzinfo) {
                    foreach ($tzinfo as $tz) {
                        if ($tz["timezone_id"] == $mytz) {
                            $x[] = preg_quote($tzname);
                        }
                    }
                }
            }
            if (empty($x)) {
                $x[] = preg_quote($this->_unparse_timezone($reference));
            }
            $this->opt["dateFormatTimezoneRemover"] =
                "/(?:\\s|\\A)(?:" . join("|", $x) . ")(?:\\s|\\z)/i";
        }
        if ($this->opt["dateFormatTimezoneRemover"]) {
            $d = preg_replace($this->opt["dateFormatTimezoneRemover"], " ", $d);
        }
        if (preg_match('/\A(.*)\b(utc(?=[-+])|aoe(?=\s|\z))(.*)\z/i', $d, $m)) {
            if (strcasecmp($m[2], "aoe") === 0) {
                $d = strtotime($m[1] . "GMT-1200" . $m[3], $reference);
                if ($d !== false
                    && $d % 86400 == 43200
                    && ($dx = strtotime($m[1] . " T23:59:59 GMT-1200" . $m[3], $reference)) === $d + 86399) {
                    return $dx;
                } else {
                    return $d;
                }
            } else {
                return strtotime($m[1] . "GMT" . $m[3], $reference);
            }
        } else {
            return strtotime($d, $reference);
        }
    }

    // NB must return HTML-safe plaintext
    /** @param int $timestamp */
    private function _unparse_time($timestamp, $type) {
        if ($timestamp <= 0) {
            return "N/A";
        }
        $t = $this->_date_unparse($type, $timestamp);
        if ($this->opt["dateFormatSimplifier"]) {
            $t = preg_replace($this->opt["dateFormatSimplifier"], "", $t);
        }
        if ($type !== "obscure" && ($z = $this->_unparse_timezone($timestamp))) {
            $t .= " $z";
        }
        return $t;
    }
    /** @param int|float|null $timestamp
     * @return ?int */
    function obscure_time($timestamp) {
        if ($timestamp !== null) {
            $timestamp = (int) ($timestamp + 0.5);
        }
        if ($timestamp > 0) {
            $offset = 0;
            if (($zone = $this->timezone())) {
                $offset = $zone->getOffset(new DateTime("@$timestamp"));
            }
            $timestamp += 43200 - ($timestamp + $offset) % 86400;
        }
        return $timestamp;
    }
    /** @param int $timestamp */
    function unparse_time_long($timestamp) {
        return $this->_unparse_time($timestamp, "long");
    }
    /** @param int $timestamp */
    function unparse_time($timestamp) {
        return $this->_unparse_time($timestamp, "timestamp");
    }
    /** @param int $timestamp */
    function unparse_time_obscure($timestamp) {
        return $this->_unparse_time($timestamp, "obscure");
    }
    /** @param int $timestamp */
    function unparse_time_point($timestamp) {
        return $this->_date_format("j M Y", $timestamp);
    }
    /** @param int $timestamp */
    function unparse_time_log($timestamp) {
        return $this->_date_format("d/M/Y:H:i:s O", $timestamp);
    }
    /** @param int $timestamp */
    function unparse_time_iso($timestamp) {
        return $this->_date_format("Ymd\\THis", $timestamp);
    }
    /** @param int $timestamp
     * @param int $now */
    function unparse_time_relative($timestamp, $now = 0, $format = 0) {
        $d = abs($timestamp - ($now ? : Conf::$now));
        if ($d >= 5227200) {
            if (!($format & 1)) {
                return ($format & 8 ? "on " : "") . $this->_date_unparse("obscure", $timestamp);
            }
            $unit = 5;
        } else if ($d >= 259200) {
            $unit = 4;
        } else if ($d >= 28800) {
            $unit = 3;
        } else if ($d >= 3630) {
            $unit = 2;
        } else if ($d >= 180.5) {
            $unit = 1;
        } else if ($d >= 1) {
            $unit = 0;
        } else {
            return "now";
        }
        $units = [1, 60, 1800, 3600, 86400, 604800];
        $x = $units[$unit];
        $d = ceil(($d - $x / 2) / $x);
        if ($unit === 2) {
            $d /= 2;
        }
        if ($format & 4) {
            $d .= substr("smhhdw", $unit, 1);
        } else {
            $unit_names = ["second", "minute", "hour", "hour", "day", "week"];
            $d .= " " . $unit_names[$unit] . ($d == 1 ? "" : "s");
        }
        if ($format & 2) {
            return $d;
        } else {
            return $timestamp < ($now ? : Conf::$now) ? $d . " ago" : "in " . $d;
        }
    }
    /** @param int $timestamp */
    function unparse_usertime_span($timestamp) {
        return '<span class="usertime hidden need-usertime" data-time="' . $timestamp . '"></span>';
    }
    /** @param string $name */
    function unparse_setting_time($name) {
        $t = $this->settings[$name] ?? 0;
        return $this->unparse_time_long($t);
    }

    function settingsAfter($name) {
        $t = $this->settings[$name] ?? null;
        return $t !== null && $t > 0 && $t <= Conf::$now;
    }
    function deadlinesAfter($name, $grace = null) {
        $t = $this->settings[$name] ?? null;
        if ($t !== null && $t > 0 && $grace && ($g = $this->settings[$grace] ?? null)) {
            $t += $g;
        }
        return $t !== null && $t > 0 && $t <= Conf::$now;
    }
    function deadlinesBetween($name1, $name2, $grace = null) {
        $t = $this->settings[$name1] ?? null;
        if (($t === null || $t <= 0 || $t > Conf::$now) && $name1) {
            return false;
        }
        $t = $this->settings[$name2] ?? null;
        if ($t !== null && $t > 0 && $grace && ($g = $this->settings[$grace] ?? null)) {
            $t += $g;
        }
        return $t === null || $t <= 0 || $t >= Conf::$now;
    }

    /** @return list<string> */
    function repository_site_classes() {
        if (isset($this->opt["repositorySites"]) && is_array($this->opt["repositorySites"])) {
            return $this->opt["repositorySites"];
        } else {
            return ["github"];
        }
    }


    /** @suppress PhanAccessReadOnlyProperty */
    function set_multiuser_page() {
        assert(!$this->_header_printed);
        $this->multiuser_page = true;
    }

    function cacheableImage($name, $alt, $title = null, $class = null, $style = null) {
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

    /** @param string|list<string> $text
     * @param int|string $type */
    function msg($text, $type) {
        if (PHP_SAPI == "cli") {
            if (is_array($text)) {
                $text = join("\n", $text);
            }
            if ($type === "xmerror" || $type === "merror" || $type === 2) {
                fwrite(STDERR, "$text\n");
            } else if ($type === "xwarning" || $type === "warning"
                       || !defined("HOTCRP_TESTHARNESS")) {
                fwrite(STDOUT, "$text\n");
            }
        } else if (!$this->_header_printed) {
            $this->_save_msgs[] = [$text, $type];
        } else if (is_int($type) || $type[0] === "x") {
            echo Ht::msg($text, $type);
        } else {
            if (is_array($text)) {
                $text = '<div class="multimessage">' . join("", array_map(function ($x) { return '<div class="mmm">' . $x . '</div>'; }, $text)) . '</div>';
            }
            echo "<div class=\"$type\">$text</div>";
        }
    }

    function infoMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xinfo" : "info");
    }

    static public function msg_info($text, $minimal = false) {
        self::$main->msg($text, $minimal ? "xinfo" : "info");
    }

    function warnMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xwarning" : "warning");
    }

    static public function msg_warning($text, $minimal = false) {
        self::$main->msg($text, $minimal ? "xwarning" : "warning");
    }

    function confirmMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xconfirm" : "confirm");
    }

    static public function msg_confirm($text, $minimal = false) {
        self::$main->msg($text, $minimal ? "xconfirm" : "confirm");
    }

    /** @return false */
    function errorMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xmerror" : "merror");
        return false;
    }

    /** @return false */
    static public function msg_error($text, $minimal = false) {
        self::$main->msg($text, $minimal ? "xmerror" : "merror");
        return false;
    }

    static public function msg_debugt($text) {
        if (is_object($text) || is_array($text) || $text === null || $text === false || $text === true)
            $text = json_encode_browser($text);
        self::$main->msg(Ht::pre_text_wrap($text), "merror");
        return false;
    }

    function post_missing_msg() {
        $this->msg("Your uploaded data wasn’t received. This can happen on unusually slow connections, or if you tried to upload a file larger than I can accept.", "merror");
    }


    //
    // Conference header, footer
    //

    /** @return bool */
    function has_active_list() {
        return !!$this->_active_list;
    }

    /** @return ?SessionList */
    function active_list() {
        if ($this->_active_list === false) {
            $this->_active_list = null;
        }
        return $this->_active_list;
    }

    function set_active_list(SessionList $list = null) {
        assert($this->_active_list === false);
        $this->_active_list = $list;
    }

    function set_siteurl($base) {
        $old_siteurl = Navigation::siteurl();
        $base = Navigation::set_siteurl($base);
        if ($this->opt["assetsUrl"] === $old_siteurl) {
            $this->opt["assetsUrl"] = $base;
            Ht::$img_base = $this->opt["assetsUrl"] . "images/";
        }
        if ($this->opt["scriptAssetsUrl"] === $old_siteurl) {
            $this->opt["scriptAssetsUrl"] = $base;
        }
    }

    const HOTURL_RAW = 1;
    const HOTURL_POST = 2;
    const HOTURL_ABSOLUTE = 4;
    const HOTURL_SITEREL = 8;
    const HOTURL_SITE_RELATIVE = 8;
    const HOTURL_SERVERREL = 16;
    const HOTURL_NO_DEFAULTS = 32;

    /** @param string $page
     * @param null|string|array $params
     * @param int $flags
     * @return string */
    function hoturl($page, $params = null, $flags = 0) {
        $qreq = Qrequest::$main_request;
        $amp = ($flags & self::HOTURL_RAW ? "&" : "&amp;");
        if (str_starts_with($page, "=")) {
            $page = substr($page, 1);
            $flags |= self::HOTURL_POST;
        }
        $t = $page;
        $are = '/\A(|.*?(?:&|&amp;))';
        $zre = '(?:&(?:amp;)?|\z)(.*)\z/';
        // parse options, separate anchor
        $anchor = "";
        $defaults = [];
        if (($flags & self::HOTURL_NO_DEFAULTS) === 0
            && $qreq
            && $qreq->user()) {
            $defaults = $qreq->user()->hoturl_defaults();
        }
        if (is_array($params)) {
            $param = $sep = "";
            foreach ($params as $k => $v) {
                if ($v === null || $v === false) {
                    continue;
                }
                $v = urlencode($v);
                if ($k === "anchor" /* XXX deprecated */ || $k === "#") {
                    $anchor = "#{$v}";
                } else {
                    $param .= "{$sep}{$k}={$v}";
                    $sep = $amp;
                }
            }
            foreach ($defaults as $k => $v) {
                if (!array_key_exists($k, $params)) {
                    $param .= "{$sep}{$k}={$v}";
                    $sep = $amp;
                }
            }
        } else {
            $param = (string) $params;
            if (($pos = strpos($param, "#"))) {
                $anchor = substr($param, $pos);
                $param = substr($param, 0, $pos);
            }
            $sep = $param === "" ? "" : $amp;
            foreach ($defaults as $k => $v) {
                if (!preg_match($are . preg_quote($k) . '=/', $param)) {
                    $param .= "{$sep}{$k}={$v}";
                    $sep = $amp;
                }
            }
        }
        if ($flags & self::HOTURL_POST) {
            $param .= "{$sep}post=" . $qreq->post_value();
            $sep = $amp;
        }
        // create slash-based URLs if appropriate
        if ($param) {
            $has_commit = false;
            if (in_array($page, ["index", "pset", "diff", "run", "raw", "file"])
                && preg_match($are . 'u=([^&#?]+)' . $zre, $param, $m)) {
                $t = "~" . $m[2] . ($page === "index" ? "" : "/$t");
                $param = $m[1] . $m[3];
            }
            if (in_array($page, ["pset", "diff", "raw", "file"])
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
        $nav = $qreq ? $qreq->navigation() : Navigation::get();
        if ($nav->php_suffix !== "") {
            if (($slash = strpos($t, "/")) !== false) {
                $a = substr($t, 0, $slash);
                $b = substr($t, $slash);
            } else {
                $a = $t;
                $b = "";
            }
            if (!str_ends_with($a, $nav->php_suffix)) {
                $a .= $nav->php_suffix;
            }
            $t = $a . $b;
        }
        if ($param !== "" && preg_match('/\A&(?:amp;)?(.*)\z/', $param, $m)) {
            $param = $m[1];
        }
        if ($param !== "") {
            $t .= "?" . $param;
        }
        if ($anchor !== "") {
            $t .= $anchor;
        }
        if ($flags & self::HOTURL_SITEREL) {
            return $t;
        } else if ($flags & self::HOTURL_SERVERREL) {
            return $nav->site_path . $t;
        }
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
        if (($flags & self::HOTURL_ABSOLUTE) || $this !== Conf::$main) {
            return $this->opt("paperSite") . "/" . $t;
        } else {
            $siteurl = $nav->site_path_relative;
            if ($need_site_path && $siteurl === "") {
                $siteurl = $nav->site_path;
            }
            return $siteurl . $t;
        }
    }

    /** @param string $page
     * @param null|string|array $param
     * @param int $flags
     * @return string */
    function hoturl_absolute($page, $param = null, $flags = 0) {
        return $this->hoturl($page, $param, self::HOTURL_ABSOLUTE | $flags);
    }

    /** @param string $page
     * @param null|string|array $param
     * @return string */
    function hoturl_site_relative_raw($page, $param = null) {
        return $this->hoturl($page, $param, self::HOTURL_SITE_RELATIVE | self::HOTURL_RAW);
    }

    /** @param string $page
     * @param null|string|array $param
     * @return string */
    function hoturl_post($page, $param = null) {
        return $this->hoturl($page, $param, self::HOTURL_POST);
    }

    /** @param string $html
     * @param string $page
     * @param null|string|array $param
     * @param ?array $js
     * @return string */
    function hotlink($html, $page, $param = null, $js = null) {
        return Ht::link($html, $this->hoturl($page, $param), $js);
    }


    static $selfurl_safe = [
        "u" => true, "pset" => true, "commit" => true, "commit1" => true,
        "file" => true,
        "forceShow" => true, "sort" => true, "t" => true, "group" => true
    ];

    function selfurl(Qrequest $qreq = null, $param = null, $flags = 0) {
        global $Qreq;
        $qreq = $qreq ? : $Qreq;
        $param = $param ?? [];

        $x = [];
        foreach ($qreq as $k => $v) {
            $ak = self::$selfurl_safe[$k] ?? null;
            if ($ak === true) {
                $ak = $k;
            }
            if ($ak
                && ($ak === $k || !isset($qreq[$ak]))
                && !array_key_exists($ak, $param)
                && !is_array($v)) {
                $x[$ak] = $v;
            }
        }
        foreach ($param as $k => $v) {
            $x[$k] = $v;
        }
        return $this->hoturl($qreq->page(), $x, $flags);
    }


    /** @return int */
    function saved_messages_status() {
        $st = 0;
        foreach ($this->_save_msgs ?? [] as $mx) {
            $st = max($st, $mx[1]);
        }
        return $st;
    }

    /** @return list<array{string,int}> */
    function take_saved_messages() {
        $ml = $this->_save_msgs ?? [];
        $this->_save_msgs = null;
        return $ml;
    }

    /** @return int */
    function report_saved_messages() {
        $st = $this->saved_messages_status();
        foreach ($this->take_saved_messages() as $mx) {
            $this->msg($mx[0], $mx[1]);
        }
        return $st;
    }

    /** @param ?string $url */
    function redirect($url = null) {
        $qreq = Qrequest::$main_request;
        if ($this->_save_msgs) {
            $qreq->open_session();
            $qreq->set_csession("msgs", $this->_save_msgs);
        }
        $qreq->qsession()->commit();
        Navigation::redirect_absolute($qreq->navigation()->make_absolute($url ?? $this->hoturl("index")));
    }

    /** @param string $page
     * @param null|string|array $param */
    function redirect_hoturl($page, $param = null) {
        $this->redirect($this->hoturl($page, $param, self::HOTURL_RAW));
    }

    /** @param Qrequest $qreq
     * @param ?array $param */
    function redirect_self(Qrequest $qreq, $param = null) {
        $this->redirect($this->selfurl($qreq, $param, self::HOTURL_RAW));
    }

    /** @param string $siteurl
     * @return string */
    function make_absolute_site($siteurl) {
        $nav = Navigation::get();
        if (str_starts_with($siteurl, "u/")) {
            return $nav->make_absolute($siteurl, $nav->base_path);
        } else {
            return $nav->make_absolute($siteurl, $nav->site_path);
        }
    }



    //
    // Conference header, footer
    //

    /** @param array $x
     * @return string */
    function make_css_link($x) {
        $x["rel"] = $x["rel"] ?? "stylesheet";
        $url = $x["href"];
        if ($url[0] !== "/"
            && (($url[0] !== "h" && $url[0] !== "H")
                || (strtolower(substr($url, 0, 5)) !== "http:"
                    && strtolower(substr($url, 0, 6)) !== "https:"))) {
            if (($mtime = @filemtime(SiteLoader::find($url))) !== false) {
                $url .= "?mtime=$mtime";
            }
            $x["href"] = $this->opt["assetsUrl"] . $url;
        }
        return "<link" . Ht::extra($x) . ">";
    }

    /** @param non-empty-string $url
     * @return string */
    function make_script_file($url, $no_strict = false, $integrity = null) {
        if (str_starts_with($url, "scripts/")) {
            $post = "";
            if (($mtime = @filemtime(SiteLoader::find($url))) !== false) {
                $post = "mtime=$mtime";
            }
            if (($this->opt["strictJavascript"] ?? false) && !$no_strict) {
                $url = $this->opt["scriptAssetsUrl"] . "cacheable.php/"
                    . str_replace("%2F", "/", urlencode($url))
                    . "?strictjs=1" . ($post ? "&$post" : "");
            } else {
                $url = $this->opt["scriptAssetsUrl"] . $url . ($post ? "?$post" : "");
            }
            if ($this->opt["scriptAssetsUrl"] === Navigation::siteurl()) {
                return Ht::script_file($url);
            }
        }
        return Ht::script_file($url, ["crossorigin" => "anonymous", "integrity" => $integrity]);
    }

    private function make_jquery_script_file($jqueryVersion) {
        $integrity = null;
        if ($this->opt("jqueryCdn")) {
            if ($jqueryVersion === "3.7.0") {
                $integrity = "sha256-2Pmvv0kuTBOenSvLm6bvfBSSHrUJ+3A7x6P5Ebd07/g=";
            } else if ($jqueryVersion === "3.5.1") {
                $integrity = "sha384-ZvpUoO/+PpLXR1lu4jmpXWu80pZlYUAfxl5NsBMWOEPSjUn/6Z/hRTt8+pR6L4N2";
            } else if ($jqueryVersion === "3.4.1") {
                $integrity = "sha384-vk5WoKIaW/vJyUAd9n/wmopsmNhiy+L2Z+SBxGYnUkunIxVxAv/UtMOhba/xskxh";
            } else if ($jqueryVersion === "3.3.1") {
                $integrity = "sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=";
            } else if ($jqueryVersion === "1.12.4") {
                $integrity = "sha256-ZosEbRLbNQzLpnKIkEdrPv7lOy9C27hHQ+Xp8a4MxAQ=";
            }
            $jquery = "//code.jquery.com/jquery-{$jqueryVersion}.min.js";
        } else {
            $jquery = "scripts/jquery-{$jqueryVersion}.min.js";
        }
        '@phan-var non-empty-string $jquery';
        return $this->make_script_file($jquery, true, $integrity);
    }

    /** @param string $file */
    function add_stylesheet($file) {
        $this->opt["stylesheets"][] = $file;
    }

    /** @param string $file */
    function add_javascript($file) {
        $this->opt["scripts"][] = $file;
    }

    /** @param string $key */
    function set_siteinfo($key, $value) {
        if ($this->_header_printed) {
            Ht::stash_script("siteinfo.$key = " . json_encode_browser($value) . ";");
        } else {
            $this->_siteinfo[$key] = $value;
        }
    }

    /** @param string $name
     * @param string $value
     * @param int $expires_at */
    function set_cookie($name, $value, $expires_at) {
        $secure = $this->opt("sessionSecure") ?? false;
        $samesite = $this->opt("sessionSameSite") ?? "Lax";
        $opt = [
            "expires" => $expires_at,
            "path" => Navigation::base_path(),
            "domain" => $this->opt("sessionDomain") ?? "",
            "secure" => $secure
        ];
        if ($samesite && ($secure || $samesite !== "None")) {
            $opt["samesite"] = $samesite;
        }
        if (!hotcrp_setcookie($name, $value, $opt)) {
            error_log(debug_string_backtrace());
        }
    }

    /** @param Qrequest $qreq
     * @param string|list<string> $title */
    function print_head_tag($qreq, $title, $extra = []) {
        echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n",
            "<meta http-equiv=\"content-type\" content=\"text/html; charset=utf-8\">\n";

        // gather stylesheets
        $cssx = [];
        $has_default_css = $has_media = false;
        foreach ($this->opt("stylesheets") ?? [] as $css) {
            if (is_string($css)) {
                $css = ["href" => $css];
            }
            $cssx[] = $this->make_css_link($css);
            $has_default_css = $has_default_css || $css["href"] === "stylesheets/style.css";
            $has_media = $has_media || ($css["media"] ?? null) !== null;
        }

        // meta elements
        $meta = $this->opt("metaTags") ?? [];
        if ($has_media) {
            $meta["viewport"] = $meta["viewport"] ?? "width=device-width, initial-scale=1";
        }
        foreach ($meta as $key => $value) {
            if ($value === false) {
                // nothing
            } else if (is_int($key)) {
                assert(str_starts_with($value, "<meta"));
                echo $value, "\n";
            } else if ($key === "default-style" || $key === "content-security-policy") {
                echo "<meta http-equiv=\"", $key, "\" content=\"", htmlspecialchars($value), "\">\n";
            } else {
                echo "<meta name=\"", htmlspecialchars($key), "\" content=\"", htmlspecialchars($value), "\">\n";
            }
        }

        // css references
        if (!$has_default_css) {
            echo $this->make_css_link(["href" => "stylesheets/style.css"]), "\n";
            echo $this->make_css_link(["href" => "https://cdn.jsdelivr.net/npm/katex@0.16.3/dist/katex.min.css", "crossorigin" => "anonymous", "integrity" => "sha384-Juol1FqnotbkyZUT5Z7gUPjQ9gzlwCENvUZTpQBAPxtusdwFLRy382PSDx5UUJ4/"]), "\n";
        }
        foreach ($cssx as $css) {
            echo $css, "\n";
        }

        // favicon
        $favicon = $this->opt("favicon");
        if ($favicon) {
            if (strpos($favicon, "://") === false && $favicon[0] != "/") {
                if ($this->opt["assetsUrl"] && substr($favicon, 0, 7) === "images/") {
                    $favicon = $this->opt["assetsUrl"] . $favicon;
                } else {
                    $favicon = $qreq->navigation()->siteurl() . $favicon;
                }
            }
            if (substr($favicon, -4) == ".png") {
                echo "<link rel=\"icon\" type=\"image/png\" href=\"$favicon\">\n";
            } else if (substr($favicon, -4) == ".ico") {
                echo "<link rel=\"shortcut icon\" href=\"$favicon\">\n";
            } else if (substr($favicon, -4) == ".gif") {
                echo "<link rel=\"icon\" type=\"image/gif\" href=\"$favicon\">\n";
            } else {
                echo "<link rel=\"icon\" href=\"$favicon\">\n";
            }
        }

        // title
        echo "<title>";
        if ($title) {
            if (is_array($title)) {
                if (count($title) === 3 && $title[2]) {
                    $title = $title[1] . " - " . $title[0];
                } else {
                    $title = $title[0];
                }
            }
            $title = preg_replace("/<([^>\"']|'[^']*'|\"[^\"]*\")*>/", "", $title);
            $title = preg_replace(",(?: |&nbsp;|\302\240)+,", " ", $title);
            $title = str_replace("&#x2215;", "-", $title);
        }
        if ($title && $title !== "Home" && $title !== "Sign in") {
            echo $title, " - ";
        }
        echo htmlspecialchars($this->short_name), "</title>\n</head>\n";

        // jQuery
        $stash = Ht::unstash();
        if (($jqurl = $this->opt["jqueryUrl"] ?? null)) {
            Ht::stash_html($this->make_script_file($jqurl, true) . "\n");
        } else {
            $jqueryVersion = $this->opt["jqueryVersion"] ?? "3.7.0";
            Ht::stash_html($this->make_jquery_script_file($jqueryVersion) . "\n");
        }
        Ht::stash_html($this->make_script_file("scripts/jquery.color-2.1.2.min.js", true) . "\n");
        Ht::stash_html($this->make_script_file("scripts/markdown-it.min.js", true) . "\n");
        Ht::stash_html($this->make_script_file("scripts/highlight.min.js", true) . "\n");
        Ht::stash_html($this->make_script_file("scripts/markdown-it-katexx.min.js", true) . "\n");
        Ht::stash_html('<script src="https://cdn.jsdelivr.net/npm/katex@0.16.3/dist/katex.min.js" integrity="sha384-97gW6UIJxnlKemYavrqDHSX3SiygeOwIZhwyOKRfSaf0JWKRVj9hLASHgFTzT+0O" crossorigin="anonymous"></script>');

        // Javascript settings to set before pa.js
        $nav = $qreq->navigation();
        $siteinfo = [
            "site_relative" => $nav->site_path_relative,
            "base" => $nav->base_path,
            "suffix" => $nav->php_suffix,
            "assets" => $this->opt["assetsUrl"],
            "cookie_params" => "",
            "postvalue" => $qreq->post_value(true),
            "user" => []
        ];
        if (($x = $this->opt("sessionDomain"))) {
            $siteinfo["cookie_params"] .= "; Domain=$x";
        }
        if ($this->opt("sessionSecure")) {
            $siteinfo["cookie_params"] .= "; Secure";
        }
        if (($samesite = $this->opt("sessionSameSite") ?? "Lax")) {
            $siteinfo["cookie_params"] .= "; SameSite={$samesite}";
        }
        if (($user = $qreq->user())) {
            if ($user->email) {
                $siteinfo["user"]["email"] = $user->email;
            }
            if ($user->is_pclike()) {
                $siteinfo["user"]["is_pclike"] = true;
            }
            if ($user->privChair) {
                $siteinfo["user"]["is_admin"] = true;
            }
            if ($user->has_account_here()) {
                $siteinfo["user"]["cid"] = $user->contactId;
            }
            if ($user->is_actas_user()) {
                $siteinfo["user"]["is_actas"] = true;
            }
            if (($defaults = $user->hoturl_defaults())) {
                $siteinfo["defaults"] = [];
                foreach ($defaults as $k => $v) {
                    $siteinfo["defaults"][$k] = urldecode($v);
                }
            }
        }
        foreach ($this->_siteinfo ?? [] as $k => $v) {
            $siteinfo[$k] = $v;
        }
        $this->_siteinfo = null;

        Ht::stash_script("window.siteinfo=" . json_encode_browser($siteinfo));

        // pa.js
        if (!$this->opt("noDefaultScript")) {
            Ht::stash_html($this->make_script_file("scripts/pa.min.js") . "\n");
        }

        // other scripts
        foreach ($this->opt("scripts") ?? [] as $file) {
            Ht::stash_html($this->make_script_file($file) . "\n");
        }

        if ($stash) {
            Ht::stash_html($stash);
        }
    }

    /** @param Qrequest $qreq
     * @param string|list<string> $title */
    function print_body_entry($qreq, $title, $id, $extra = []) {
        $user = $qreq->user();
        echo "<body";
        if ($id) {
            echo ' id="body-', $id, '"';
        }
        $class = $extra["body_class"] ?? "";
        if ($this->multiuser_page) {
            $class = $class === "" ? "pa-multiuser" : "{$class} pa-multiuser";
        }
        if ($this->_active_list) {
            $class = $class === "" ? "has-hotlist" : "{$class} has-hotlist";
        }
        if ($class !== "") {
            echo ' class="', $class, '"';
        }
        if ($this->_active_list) {
            echo ' data-hotlist="', htmlspecialchars($this->_active_list->listid), '"';
        }
        if ($this->default_format) {
            echo ' data-default-format="', $this->default_format, '"';
        }
        echo ' data-upload-limit="', ini_get_bytes("upload_max_filesize"), '"';
        if (($x = $this->opt["uploadMaxFilesize"] ?? null) !== null) {
            echo ' data-document-max-size="', ini_get_bytes(null, $x), '"';
        }
        echo " onload=\"\$pa.onload()\" data-now=\"", Conf::$now, '"><div id="prebody">';

        // initial load (JS's timezone offsets are negative of PHP's)
        Ht::stash_script("\$pa.onload.time(" . (-(int) date("Z", Conf::$now) / 60) . "," . ($this->opt("time24hour") ? 1 : 0) . ")");

        echo Ht::unstash();

        // <head>
        if ($title === "Home") {
            $title = "";
        }

        echo "<div id='header'>\n<div id='header_left_conf'><h1>";
        if ($title && ($title == "Home" || $title == "Sign in"))
            echo "<a class='q' href='", $this->hoturl("index"), "' title='Home'>", htmlspecialchars($this->short_name), "</a>";
        else
            echo "<a class='u' href='", $this->hoturl("index"), "' title='Home'>", htmlspecialchars($this->short_name), "</a></h1></div><div id='header_left_page'><h1>", $title;
        echo "</h1></div><div id='header_right'>";
        if ($user && !$user->is_empty()) {
            // profile link
            $profile_parts = [];
            if ($user->has_email() && !$user->is_disabled()) {
	        $profile_parts[] = '<strong>' . htmlspecialchars($user->email) . '</strong>';
                /*echo '<a class="q" href="', hoturl("profile"), '"><strong>',
                    htmlspecialchars($user->email),
                    '</strong></a> &nbsp; <a href="', hoturl("profile"), '">Profile</a>',
                    $xsep;*/
            }

            // "act as" link
            $actas_email = null;
            if ($user->privChair && !$user->is_actas_user()) {
                $actas_email = $qreq->gsession("last_actas");
            }
            if ($actas_email || Contact::$base_auth_user) {
                // Link becomes true user if not currently chair.
                $actas = Contact::$base_auth_user ? Contact::$base_auth_user->email : $actas_email;
                $profile_parts[] = "<a href=\""
                    . $this->selfurl(null, ["actas" => Contact::$base_auth_user ? null : $actas]) . "\">"
                    . (Contact::$base_auth_user ? "Admin" : htmlspecialchars($actas))
                    . "&nbsp;" . Ht::img("viewas.png", "Act as " . htmlspecialchars($actas))
                    . "</a>";
            }

            // help, sign out
            $x = ($id == "search" ? "t=$id" : ($id == "settings" ? "t=chair" : ""));
            if (!$user->has_email() && !isset($this->opt["httpAuthLogin"]))
                $profile_parts[] = '<a href="' . $this->hoturl("index", "signin=1") . '">Sign&nbsp;in</a>';
            if (!$user->is_empty() || isset($this->opt["httpAuthLogin"]))
                $profile_parts[] = '<a href="' . $this->hoturl("=index", "signout=1") . '">Sign&nbsp;out</a>';

            if (!empty($profile_parts))
                echo join(' <span class="barsep">·</span> ', $profile_parts);
        }
        echo '<div id="maindeadline" style="display:none"></div></div>', "\n";

        echo "  <hr class=\"c\" />\n";

        $this->_header_printed = true;
        echo "</div>\n<div id=\"initialmsgs\">\n";
        if (($x = $this->opt("maintenance"))) {
            echo Ht::msg(is_string($x) ? $x : "<strong>The site is down for maintenance.</strong> Please check back later.", 2);
        }
        if ($this->_save_msgs && !($extra["save_messages"] ?? false)) {
            $this->report_saved_messages();
            echo "<div id=\"initialmsgspacer\"></div>";
        }
        echo "</div>\n";

        echo "</div>\n<div class=\"body\">\n";
    }

    function header($title, $id, $extra = []) {
        if (!$this->_header_printed) {
            $this->print_head_tag(Qrequest::$main_request, $title, $extra);
            $this->print_body_entry(Qrequest::$main_request, $title, $id, $extra);
        }
    }

    static function git_status() {
        $args = [];
        if (is_dir(SiteLoader::find(".git"))) {
            exec("export GIT_DIR=" . escapeshellarg(SiteLoader::$root) . "/.git; git rev-parse HEAD 2>/dev/null; git rev-parse v" . PA_VERSION . " 2>/dev/null", $args);
        }
        return count($args) === 2 ? $args : null;
    }

    function footer() {
        global $Me;
        echo "</div>\n", // class='body'
            "<div id='footer'>\n";
        $footy = $this->opt("extraFooter") ?? "";
        if (!$this->opt("noFooterVersion")) {
            if ($Me && $Me->privChair) {
                if (is_dir(SiteLoader::$root . "/.git")) {
                    $args = array();
                    exec("export GIT_DIR=" . escapeshellarg(SiteLoader::$root) . "/.git; git rev-parse HEAD 2>/dev/null", $args);
                    if (count($args) == 2 && $args[0] != $args[1])
                        $footy .= " [" . substr($args[0], 0, 7) . "...]";
                }
            }
        }
        if ($footy)
            echo "<div id='footer_crp'>$footy</div>";
        echo "<div class='clear'></div></div>\n";
        echo Ht::unstash(), "</body>\n</html>\n";
    }

    function stash_hotcrp_pc(Contact $user) {
        if (!Ht::mark_stash("hotcrp_pc")) {
            return;
        }
        $hpcj = $list = [];
        foreach ($this->pc_members_and_admins() as $pcm) {
            $hpcj[$pcm->contactId] = $j = (object) ["name" => Text::name_html($pcm), "email" => $pcm->email];
            if ($pcm->lastName) {
                $r = Text::analyze_name($pcm);
                if (strlen($r->lastName) !== strlen($r->name)) {
                    $j->lastpos = strlen(htmlspecialchars($r->firstName)) + 1;
                }
                if ($r->nameAmbiguous && $r->name && $r->email) {
                    $j->emailpos = strlen(htmlspecialchars($r->name)) + 1;
                }
            }
            if (!$pcm->nameAmbiguous && ($pcm->nickname || $pcm->firstName)) {
                if ($pcm->nicknameAmbiguous) {
                    $j->nicklen = strlen(htmlspecialchars($r->name));
                } else {
                    $nick = htmlspecialchars($pcm->nickname ? : $pcm->firstName);
                    if (str_starts_with($j->name, $nick)) {
                        $j->nicklen = strlen($nick);
                    } else {
                        $j->nick = $nick;
                    }
                }
            }
            if (!($pcm->roles & Contact::ROLE_PC)) {
                $j->admin_only = true;
            }
            $list[] = $pcm->contactId;
        }
        $hpcj["__order__"] = $list;
        if ($this->sort_by_last) {
            $hpcj["__sort__"] = "last";
        }
        Ht::stash_script("window.siteinfo.pc=" . json_encode_browser($hpcj) . ";");
    }


    //
    // Action recording
    //

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


    function clean_queue() {
        if (($this->settings["__qcleanat"] ?? 0) < Conf::$now - 600) {
            $this->__save_setting("__qcleanat", Conf::$now);
            $this->qe("delete from ExecutionQueue where status>=? and updateat<? and runat<?", QueueItem::STATUS_CANCELLED, Conf::$now - 600, Conf::$now - 600);
        }
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
        if (!$pset->disabled && $pset->category) {
            if (!isset($this->_category_weight[$pset->category])) {
                $this->_category_weight[$pset->category] = $pset->weight;
                $this->_category_weight_default[$pset->category] = $pset->weight_default;
            } else if ($this->_category_weight_default[$pset->category] === $pset->weight_default) {
                $this->_category_weight[$pset->category] += $pset->weight;
            } else {
                throw new Exception("pset category `{$pset->category}` has not all group weights set");
            }
        }
        $this->_psets_sorted = false;
        $this->_psets_newest_first = null;
    }

    /** @return array<int,Pset> */
    function psets() {
        if (!$this->_psets_sorted) {
            uasort($this->_psets, "Pset::compare");
            $this->_psets_sorted = true;
        }
        return $this->_psets;
    }

    /** @return list<Pset> */
    function psets_newest_first() {
        if ($this->_psets_newest_first === null) {
            $this->_psets_newest_first = array_values($this->_psets);
            uasort($this->_psets_newest_first, "Pset::compare_newest_first");
        }
        return $this->_psets_newest_first;
    }

    /** @param int $id
     * @return ?Pset */
    function pset_by_id($id) {
        return $this->_psets[$id] ?? null;
    }

    /** @param string $key
     * @return ?Pset */
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

    /** @param string $key
     * @return ?Pset */
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

    /** @param string $group
     * @return float */
    function category_weight($group) {
        return $this->_category_weight[$group] ?? 0.0;
    }

    /** @return array<string,list<Pset>> */
    function psets_by_category() {
        if ($this->_psets_by_category === null) {
            $this->_psets_by_category = [];
            $this->_category_has_extra = [];
            foreach ($this->psets() as $pset) {
                if (!$pset->disabled) {
                    $category = $pset->category ?? "";
                    $this->_psets_by_category[$category][] = $pset;
                    if ($pset->has_extra) {
                        $this->_category_has_extra[$category] = true;
                    }
                }
            }
            uksort($this->_psets_by_category, function ($a, $b) {
                if ($a === "") {
                    return 1;
                } else {
                    return strnatcmp($a, $b);
                }
            });
        }
        return $this->_psets_by_category;
    }

    /** @param string $category
     * @return list<Pset> */
    function pset_category($category) {
        return ($this->psets_by_category())[$category] ?? [];
    }

    /** @param string $category
     * return bool */
    function pset_category_has_extra($category) {
        $this->psets_by_category();
        return $this->_category_has_extra[$category] ?? false;
    }


    /** @return list<FormulaConfig> */
    function global_formulas() {
        if (!isset($this->_global_formulas)) {
            $this->_global_formulas = [];
            $n = 0;
            foreach ($this->config->_formulas ?? [] as $name => $fc) {
                $this->_global_formulas[] = $f = new FormulaConfig($this, $name, $fc, $n++);
                if ($f->name) {
                    $this->_formulas_by_name[$f->name] = $f;
                }
            }
        }
        return $this->_global_formulas;
    }

    /** @return ?FormulaConfig */
    function formula_by_name($name) {
        if (!isset($this->_global_formulas)) {
            $this->global_formulas();
        }
        return $this->_formulas_by_name[$name] ?? null;
    }

    /** @return list<FormulaConfig> */
    function formulas_by_home_position() {
        $fs = [];
        foreach ($this->global_formulas() as $f) {
            if ($f->home_position !== null)
                $fs[] = $f;
        }
        usort($fs, function ($a, $b) {
            if ($a->home_position != $b->home_position) {
                return $a->home_position < $b->home_position ? -1 : 1;
            } else {
                return $a->subposition - $b->subposition;
            }
        });
        return $fs;
    }

    /** @return GradeFormula */
    function canonical_formula(GradeFormula $gf) {
        $n = $gf->canonical_id();
        foreach ($this->_canon_formulas as $ff) {
            if ($ff->canonical_id() === $n) {
                return $ff;
            }
        }
        $this->_canon_formulas[] = $gf;
        return $gf;
    }


    /** @param string $name
     * @return QueueConfig */
    function queue($name) {
        $name = $name ?? "";
        if (($pos = strpos($name, "#")) !== false
            && ctype_digit(substr($name, $pos + 1))) {
            $nconcurrent = intval(substr($name, $pos + 1));
            $name = substr($name, 0, $pos);
        } else {
            $nconcurrent = null;
        }
        $qc = $this->_queues[$name] ?? $this->_queues["default"] ?? new QueueConfig;
        if ($nconcurrent !== null) {
            $qc->nconcurrent = $nconcurrent;
        }
        return $qc;
    }


    function handout_repo(Pset $pset, Repository $inrepo = null) {
        $url = $pset->handout_repo_url;
        if (!$url) {
            return null;
        }
        if ($this->opt("noGitTransport") && substr($url, 0, 6) === "git://") {
            $url = "ssh://git@" . substr($url, 6);
        }
        $hrepo = $this->_handout_repos[$url] ?? null;
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
                $save = $hset = (object) [];
            }
            if (!($hme = $hset->{"$hrepoid"} ?? null)) {
                $save = $hme = $hset->{"$hrepoid"} = (object) array();
            }
            if ((int) ($hme->{"$cacheid"} ?? 0) + 300 < Conf::$now
                && !$this->opt("disableRemote")) {
                $save = $hme->{"$cacheid"} = Conf::$now;
                $hrepo->reposite->gitfetch($hrepo->repoid, $cacheid, false);
            }
            if ($save) {
                $this->save_setting("handoutrepos", 1, $hset);
            }
        }
        return $hrepo;
    }

    private function populate_handout_commits(Pset $pset) {
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
        if (($hset->snaphash ?? null) !== $hrepo->snaphash
            || (int) ($hset->snaphash_at ?? 0) + 300 < Conf::$now
            || !($hset->commits ?? null)) {
            $hset->snaphash = $hrepo->snaphash;
            $hset->snaphash_at = Conf::$now;
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

    /** @return array<string,CommitRecord> */
    function handout_commits(Pset $pset) {
        if (!array_key_exists($pset->id, $this->_handout_commits)) {
            $this->populate_handout_commits($pset);
        }
        return $this->_handout_commits[$pset->id];
    }

    /** @return ?CommitRecord */
    function handout_commit(Pset $pset, $hash) {
        $commits = $this->handout_commits($pset);
        if (strlen($hash) === 40 || strlen($hash) === 64) {
            return $commits[$hash] ?? null;
        } else {
            $matches = [];
            foreach ($commits as $h => $c) {
                if (str_starts_with($h, $hash))
                    $matches[] = $c;
            }
            return count($matches) === 1 ? $matches[0] : null;
        }
    }

    /** @return ?array<string,CommitRecord> */
    function handout_commits_from(Pset $pset, $hash) {
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

    /** @return ?CommitRecord */
    function latest_handout_commit(Pset $pset) {
        if (!array_key_exists($pset->id, $this->_handout_latest_commit)) {
            $this->populate_handout_commits($pset);
        }
        return $this->_handout_latest_commit[$pset->id] ?? null;
    }


    private function branch_map() {
        if ($this->_branch_map === null) {
            $this->_branch_map = [0 => "master", 1 => "main"];
            $result = $this->qe("select branchid, branch from Branch");
            while (($row = $result->fetch_row())) {
                $this->_branch_map[(int) $row[0]] = $row[1];
            }
            Dbl::free($result);
        }
        return $this->_branch_map;
    }

    function branch($branchid) {
        if (($branchid ?? 0) === 0) {
            return "master";
        } else if ($branchid === 1) {
            return "main";
        } else {
            return ($this->branch_map())[$branchid] ?? null;
        }
    }

    function clear_branch_map() {
        $this->_branch_map = null;
    }

    /** @param ?string $branch
     * @return ?int */
    function ensure_branch($branch) {
        if ($branch === null || $branch === "" || $branch === "master") {
            return 0;
        } else if ($branch === "main") {
            return 1;
        } else if (!Repository::validate_branch($branch)) {
            return null;
        } else {
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
    }


    // API

    function call_api($uf, Contact $user, Qrequest $qreq, APIData $api) {
        if (!$uf) {
            return ["ok" => false, "error" => "API function not found"];
        } else if ($qreq->method() !== "GET" && $qreq->method() !== "POST") {
            return ["ok" => false, "error" => "Method not supported"];
        } else if ($qreq->is_post() && !$qreq->valid_token()) {
            return ["ok" => false, "error" => "Missing credentials"];
        } else if (!($uf->get ?? null) && !$qreq->is_post()) {
            return ["ok" => false, "error" => "Method not supported"];
        }
        $need_hash = !!($uf->hash ?? false);
        $need_repo = !!($uf->repo ?? false);
        $need_pset = $need_repo || $need_hash || !!($uf->pset ?? false);
        $need_user = !!($uf->user ?? false);
        if ($need_user && !$api->user) {
            return ["ok" => false, "error" => "User not found"];
        } else if ($need_pset && !$api->pset) {
            return ["ok" => false, "error" => "Pset not found"];
        } else if ($need_repo && !$api->repo) {
            return ["ok" => false, "error" => "Repository not found"];
        } else if ($need_hash) {
            $api->commit = $this->check_api_hash($api->hash, $api);
            if (!$api->commit) {
                return ["ok" => false, "error" => $api->hash ? "Missing commit." : "Disconnected commit."];
            }
        }
        if (($req = $uf->require ?? [])) {
            foreach (SiteLoader::expand_includes($req) as $f) {
                require_once $f;
            }
        }
        return call_user_func($uf->function, $user, $qreq, $api, $uf);
    }
    /** @return ?CommitRecord */
    function check_api_hash($input_hash, APIData $api) {
        if (!$input_hash) {
            return null;
        }
        $commit = $api->pset->handout_commit($input_hash);
        if (!$commit && $api->repo) {
            $commit = $api->repo->connected_commit($input_hash, $api->pset, $api->branch);
        }
        return $commit;
    }
    function _add_api_json($fj) {
        if (is_string($fj->fn)
            && !isset($this->_api_map[$fj->fn])
            && isset($fj->function)) {
            $this->_api_map[$fj->fn] = $fj;
            return true;
        } else {
            return false;
        }
    }
    private function fill_api_map() {
        $this->_api_map = [
            "blob" => "15 Repo_API::blob",
            "branch" => "19 RepoConfig_API::branch",
            "branches" => "1 Repo_API::branches",
            "diffconfig" => "15 Repo_API::diffconfig",
            "filediff" => "15 Repo_API::filediff",
            "flag" => "15 Flag_API::flag",
            "grade" => "3 Grade_API::grade",
            "gradesettings" => "3 Grade_API::gradesettings",
            "gradestatistics" => "3 GradeStatistics_API::run",
            "jserror" => "1 JSError_API::jserror",
            "latestcommit" => "1 Repo_API::latestcommit",
            "linenote" => "3 LineNote_API::linenote",
            "linenotesuggest" => "3 LineNote_API::linenotesuggest",
            "linenotemark" => "3 LineNote_API::linenotemark",
            "multigrade" => "3 Grade_API::multigrade",
            "multiresolveflag" => "0 Flag_API::multiresolve",
            "psetconfig" => "35 PsetConfig_API::psetconfig",
            "repo" => "19 RepoConfig_API::repo",
            "repositories" => "17 RepoConfig_API::user_repositories",
            "runchainhead" => "1 Run_API::runchainhead"
        ];
        if (($olist = $this->opt("apiFunctions"))) {
            expand_json_includes_callback($olist, [$this, "_add_api_json"]);
        }
    }
    /** @param string $fn
     * @return bool */
    function has_api($fn) {
        if ($this->_api_map === null) {
            $this->fill_api_map();
        }
        return isset($this->_api_map[$fn]);
    }
    /** @param string $fn
     * @return ?object */
    function api($fn) {
        if ($this->_api_map === null) {
            $this->fill_api_map();
        }
        $uf = $this->_api_map[$fn] ?? null;
        if ($uf && is_string($uf)) {
            $space = strpos($uf, " ");
            $flags = (int) substr($uf, 0, $space);
            $uf = $this->_api_map[$fn] = (object) ["function" => substr($uf, $space + 1)];
            if ($flags & 1) {
                $uf->get = true;
            }
            if ($flags & 2) {
                $uf->pset = true;
            }
            if ($flags & 4) {
                $uf->repo = true;
            }
            if ($flags & 8) {
                $uf->hash = true;
            }
            if ($flags & 16) {
                $uf->user = true;
            }
            if ($flags & 32) {
                $uf->anypset = true;
            }
        }
        return $uf;
    }
}
