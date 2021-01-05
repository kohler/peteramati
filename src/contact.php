<?php
// contact.php -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class Contact_Update {
    public $qv = [];
    public $cdb_uqv = [];
    public $different_email;
    public function __construct($inserting, $different_email) {
        if ($inserting)
            $this->qv["firstName"] = $this->qv["lastName"] = "";
        $this->different_email = $different_email;
    }
}

class Contact {
    static public $rights_version = 1;
    static public $true_user;
    static public $allow_nonexistent_properties = false;

    public $contactId = 0;
    public $contactDbId = 0;
    private $cid;               // for forward compatibility
    /** @var Conf */
    public $conf;
    /** @var StudentSet */
    public $student_set;

    public $firstName = "";
    public $lastName = "";
    public $nickname = "";
    public $unaccentedName = "";
    public $nameAmbiguous;
    public $nicknameAmbiguous;
    public $email = "";
    public $preferredEmail = "";
    public $sorter = "";
    public $sort_position;

    public $affiliation = "";

    private $password = "";
    private $passwordTime = 0;
    private $passwordUseTime = 0;
    private $defaultWatch;
    private $visits;
    private $creationTime;
    private $updateTime;
    private $lastLogin;
    public $gradeUpdateTime;

    public $disabled = false;
    private $_disabled;
    public $activity_at = false;
    public $note;
    public $data;
    public $studentYear;

    public $huid;
    public $college;
    public $extension;
    public $dropped;
    public $username;
    public $seascode_username;
    public $github_username;
    public $anon_username;
    public $contactImageId;
    public $is_anonymous = false;

    public $visited = false;
    public $incomplete = false;
    public $viewable_by;

    /** @var ?array<int,array<int,list<int>>> */
    private $links;
    /** @var ?string */
    private $contactLinks;
    /** @var array<int,?Repository> */
    private $repos = [];
    /** @var array<int,?Contact> */
    private $partners = [];
    /** @var array<int,?GradeExport> */
    private $_gcache = [];
    /** @var array<int,int> */
    private $_gcache_flags = [];
    /** @var array<string,list<null|false|float>> */
    private $_gcache_group = [];

    // Roles
    const ROLE_PC = 1;
    const ROLE_ADMIN = 2;
    const ROLE_CHAIR = 4;
    const ROLE_PCLIKE = 15;
    public $is_site_contact = false;
    public $roles = 0;
    public $isPC = false;
    public $privChair = false;
    public $contactTags = null;
    const CAP_AUTHORVIEW = 1;
    private $capabilities = null;
    private $activated_ = false;

    static private $contactdb_dblink = false;
    static private $active_forceShow = false;


    public function __construct($trueuser = null, Conf $conf = null) {
        global $Conf;
        $this->conf = $conf ?? $Conf;
        if ($trueuser) {
            $this->merge($trueuser);
        } else if ($this->contactId || $this->contactDbId) {
            $this->db_load();
        } else if ($this->conf->opt("disableNonPC")) {
            $this->disabled = true;
        }
    }

    /** @return ?Contact */
    static function fetch($result, Conf $conf) {
        $user = $result ? $result->fetch_object("Contact", [null, $conf]) : null;
        if ($user && !is_int($user->contactId)) {
            $user->conf = $conf;
            $user->db_load();
        }
        return $user;
    }

    private function merge($user) {
        if (is_array($user))
            $user = (object) $user;
        if (!isset($user->dsn) || $user->dsn == $this->conf->dsn) {
            if (isset($user->contactId)) {
                $this->contactId = $this->cid = (int) $user->contactId;
            }
            //else if (isset($user->cid))
            //    $this->contactId = $this->cid = (int) $user->cid;
        }
        if (isset($user->contactDbId)) {
            $this->contactDbId = (int) $user->contactDbId;
        }
        if (isset($user->firstName) && isset($user->lastName)) {
            $name = $user;
        } else {
            $name = Text::analyze_name($user);
        }
        $this->firstName = get_s($name, "firstName");
        $this->lastName = get_s($name, "lastName");
        $this->nickname = get_s($name, "nickname");
        if (isset($user->unaccentedName)) {
            $this->unaccentedName = $user->unaccentedName;
        } else if (isset($name->unaccentedName)) {
            $this->unaccentedName = $name->unaccentedName;
        } else {
            $this->unaccentedName = Text::unaccented_name($name);
        }
        foreach (["email", "preferredEmail", "affiliation"] as $k) {
            if (isset($user->$k))
                $this->$k = simplify_whitespace($user->$k);
        }
        if (isset($user->collaborators)) {
            $this->collaborators = "";
            foreach (preg_split('/[\r\n]+/', $user->collaborators) as $c) {
                if (($c = simplify_whitespace($c)) !== "")
                    $this->collaborators .= "$c\n";
            }
        }
        self::set_sorter($this, $this->conf);
        if (isset($user->password)) {
            $this->password = (string) $user->password;
        }
        if (isset($user->disabled)) {
            $this->disabled = !!$user->disabled;
        }
        foreach (["defaultWatch", "passwordTime", "passwordUseTime",
                  "updateTime", "creationTime", "gradeUpdateTime"] as $k) {
            if (isset($user->$k))
                $this->$k = (int) $user->$k;
        }
        if (property_exists($user, "contactTags")) {
            $this->contactTags = $user->contactTags;
        } else {
            $this->contactTags = false;
        }
        if (isset($user->activity_at)) {
            $this->activity_at = (int) $user->activity_at;
        } else if (isset($user->lastLogin)) {
            $this->activity_at = (int) $user->lastLogin;
        }
        if (isset($user->extension)) {
            $this->extension = !!$user->extension;
        }
        if (isset($user->seascode_username)) {
            $this->seascode_username = $user->seascode_username;
        }
        if (isset($user->github_username)) {
            $this->github_username = $user->github_username;
        }
        if (isset($user->anon_username)) {
            $this->anon_username = $user->anon_username;
        }
        if (isset($user->contactImageId)) {
            $this->contactImageId = (int) $user->contactImageId;
        }
        if (isset($user->roles) || isset($user->isPC) || isset($user->isAssistant)
            || isset($user->isChair)) {
            $roles = (int) get($user, "roles");
            if ($user->isPC ?? false) {
                $roles |= self::ROLE_PC;
            }
            if ($user->isAssistant ?? false) {
                $roles |= self::ROLE_ADMIN;
            }
            if ($user->isChair ?? false) {
                $roles |= self::ROLE_CHAIR;
            }
            $this->assign_roles($roles);
        }
        if (!$this->isPC && $this->conf->opt("disableNonPC")) {
            $this->disabled = true;
        }
        if (isset($user->is_site_contact)) {
            $this->is_site_contact = $user->is_site_contact;
        }
        $this->username = $this->github_username ? : $this->seascode_username;
    }

    private function db_load() {
        $this->contactId = $this->cid = (int) $this->contactId;
        $this->contactDbId = (int) $this->contactDbId;
        if ($this->unaccentedName === "") {
            $this->unaccentedName = Text::unaccented_name($this->firstName, $this->lastName);
        }
        self::set_sorter($this, $this->conf);
        $this->password = (string) $this->password;
        if (isset($this->disabled)) {
            $this->disabled = !!$this->disabled;
        }
        foreach (["defaultWatch", "passwordTime", "gradeUpdateTime"] as $k) {
            $this->$k = (int) $this->$k;
        }
        if (isset($this->activity_at)) {
            $this->activity_at = (int) $this->activity_at;
        } else if (isset($this->lastLogin)) {
            $this->activity_at = (int) $this->lastLogin;
        }
        if (isset($this->extension)) {
            $this->extension = !!$this->extension;
        }
        if (isset($this->contactImageId)) {
            $this->contactImageId = (int) $this->contactImageId;
        }
        if (isset($this->roles)) {
            $this->assign_roles((int) $this->roles);
        }
        if (!$this->isPC && $this->conf->opt("disableNonPC")) {
            $this->disabled = true;
        }
        $this->username = $this->github_username ? : $this->seascode_username;
    }

    // begin changing contactId to cid
    function __get($name) {
        if ($name === "cid")
            return $this->contactId;
        else
            return null;
    }

    function __set($name, $value) {
        if ($name === "cid") {
            $this->contactId = $this->cid = $value;
        } else {
            if (!self::$allow_nonexistent_properties) {
                error_log(caller_landmark(1) . ": writing nonexistent property $name");
            }
            $this->$name = $value;
        }
    }

    function set_anonymous($is_anonymous) {
        if ($is_anonymous !== $this->is_anonymous) {
            $this->is_anonymous = $is_anonymous;
            if ($this->is_anonymous)
                $this->username = $this->anon_username;
            else
                $this->username = $this->github_username ? : $this->seascode_username;
            self::set_sorter($this, $this->conf);
        }
    }

    static function set_sorter($c, Conf $conf) {
        $sort_by_last = true;
        if ($c->is_anonymous) {
            $c->sorter = $c->anon_username;
            return;
        } else if (isset($c->unaccentedName) && $sort_by_last) {
            $c->sorter = trim("$c->unaccentedName $c->email");
            return;
        }
        list($first, $middle) = Text::split_first_middle($c->firstName);
        if ($sort_by_last) {
            if (($m = Text::analyze_von($c->lastName))) {
                $c->sorter = "$m[1] $first $m[0]";
            } else {
                $c->sorter = "$c->lastName $first";
            }
        } else {
            $c->sorter = "$first $c->lastName";
        }
        $c->sorter = trim($c->sorter . " " . $c->username . " " . $c->email);
        if (preg_match('/[\x80-\xFF]/', $c->sorter)) {
            $c->sorter = UnicodeHelper::deaccent($c->sorter);
        }
    }

    static function compare($a, $b) {
        return strnatcasecmp($a->sorter, $b->sorter);
    }

    /** @return Contact */
    static function site_contact() {
        global $Opt;
        if (!get($Opt, "contactEmail") || $Opt["contactEmail"] == "you@example.com") {
            $result = Dbl::ql("select firstName, lastName, nickname, email from ContactInfo where (roles&" . (self::ROLE_CHAIR | self::ROLE_ADMIN) . ")!=0 order by (roles&" . self::ROLE_CHAIR . ") desc limit 1");
            if ($result && ($row = $result->fetch_object())) {
                $Opt["defaultSiteContact"] = true;
                $Opt["contactName"] = Text::name_text($row);
                $Opt["contactEmail"] = $row->email;
            }
        }
        return new Contact((object) array("fullName" => $Opt["contactName"],
                                          "email" => $Opt["contactEmail"],
                                          "isChair" => true,
                                          "isPC" => true,
                                          "is_site_contact" => true,
                                          "contactTags" => null));
    }

    private function assign_roles($roles) {
        $this->roles = $roles;
        $this->isPC = ($roles & self::ROLE_PCLIKE) != 0;
        $this->privChair = ($roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
    }


    // initialization

    /** @return Contact */
    private function actas_user($x) {
        assert(!self::$true_user || self::$true_user === $this);

        // translate to email
        if (is_numeric($x)) {
            $acct = $this->conf->user_by_id($x);
            $email = $acct ? $acct->email : null;
        } else if ($x === "admin") {
            $email = $this->email;
        } else {
            $email = $x;
        }
        if (!$email
            || strcasecmp($email, $this->email) === 0
            || !$this->privChair) {
            return $this;
        }

        // new account must exist
        $u = $this->conf->user_by_email($email);
        return $u ?? $this;
    }

    /** @return Contact */
    function activate($qreq, $signin = false) {
        global $Qreq;
        $qreq = $qreq ? : $Qreq;
        $this->activated_ = true;

        // Handle actas requests
        if ($qreq && $qreq->actas && $signin && $this->email) {
            $actas = $qreq->actas;
            unset($qreq->actas, $_GET["actas"], $_POST["actas"]);
            $actascontact = $this->actas_user($actas);
            if ($actascontact !== $this) {
                $this->conf->save_session("l", null);
                Conf::$hoturl_defaults["actas"] = urlencode($actascontact->email);
                $_SESSION["last_actas"] = $actascontact->email;
                self::$true_user = $this;
                return $actascontact->activate($qreq);
            }
        }

        // Handle invalidate-caches requests
        if ($qreq && $qreq->invalidatecaches && $this->privChair) {
            unset($_GET["invalidatecaches"], $_POST["invalidatecaches"], $_REQUEST["invalidatecaches"], $qreq->invalidatecaches);
            $this->conf->invalidate_caches();
        }

        // Check forceShow
        self::$active_forceShow = $this->privChair && $qreq->forceShow;

        return $this;
    }

    function set_forceShow($on) {
        global $Me;
        if ($this->contactId == $Me->contactId) {
            self::$active_forceShow = $this->privChair && $on;
            if (self::$active_forceShow) {
                $_GET["forceShow"] = $_POST["forceShow"] = $_REQUEST["forceShow"] = 1;
            } else {
                unset($_GET["forceShow"], $_POST["forceShow"], $_REQUEST["forceShow"]);
            }
        }
    }

    static function contactdb() {
        return null;
    }

    function contactdb_user() {
        return null;
    }


    function session($name, $defval = null) {
        return $this->conf->session($name, $defval);
    }

    function save_session($name, $value) {
        $this->conf->save_session($name, $value);
    }


    function is_empty() {
        return $this->contactId <= 0 && !$this->capabilities && !$this->email;
    }

    function owns_email($email) {
        return (string) $email !== "" && strcasecmp($email, $this->email) === 0;
    }

    function is_disabled() {
        if ($this->_disabled === null) {
            $this->_disabled = $this->disabled
                || (!$this->isPC && $this->conf->opt("disableNonPC"));
        }
        return $this->_disabled;
    }

    function can_enable() {
        if (!$this->isPC && $this->conf->opt("disableNonPC")) {
            return false;
        } else {
            return $this->disabled || $this->password === "";
        }
    }

    function has_email() {
        return !!$this->email;
    }

    static function is_anonymous_email($email) {
        // see also PaperSearch, Mailer
        return substr($email, 0, 9) === "anonymous"
            && (strlen($email) === 9 || ctype_digit(substr($email, 9)));
    }

    function is_anonymous_user() {
        return $this->email && self::is_anonymous_email($this->email);
    }

    function has_account_here() {
        return $this->contactId > 0;
    }

    function is_admin() {
        return $this->privChair;
    }

    function is_admin_force() {
        global $Me;
        return self::$active_forceShow && $this->contactId == $Me->contactId;
    }

    function is_pc_member() {
        return $this->roles & self::ROLE_PC;
    }

    function is_pclike() {
        return $this->roles & self::ROLE_PCLIKE;
    }

    function is_student() {
        return !($this->roles & self::ROLE_PCLIKE);
    }

    function has_tag($t) {
        if (($this->roles & self::ROLE_PC) && strcasecmp($t, "pc") == 0) {
            return true;
        } else if ($this->contactTags) {
            return stripos($this->contactTags, " $t#") !== false;
        } else if ($this->contactTags === false) {
            trigger_error(caller_landmark(1, "/^Conf::/") . ": Contact $this->email contactTags missing");
            $this->contactTags = null;
        } else {
            return false;
        }
    }

    function tag_value($t) {
        if (($this->roles & self::ROLE_PC) && strcasecmp($t, "pc") == 0) {
            return 0.0;
        } if ($this->contactTags
              && ($p = stripos($this->contactTags, " $t#")) !== false) {
            return (float) substr($this->contactTags, $p + strlen($t) + 2);
        } else {
            return false;
        }
    }

    static function roles_all_contact_tags($roles, $tags) {
        $t = "";
        if ($roles & self::ROLE_PC)
            $t = " pc#0";
        if ($tags)
            return $t . $tags;
        else
            return $t ? $t . " " : "";
    }

    function all_contact_tags() {
        return self::roles_all_contact_tags($this->roles, $this->contactTags);
    }

    private function trim() {
        $this->contactId = (int) $this->contactId;
        $this->cid = $this->contactId;
        $this->visits = trim($this->visits);
        $this->firstName = simplify_whitespace($this->firstName);
        $this->lastName = simplify_whitespace($this->lastName);
        foreach (array("email", "preferredEmail", "affiliation", "note") as $k)
            if ($this->$k)
                $this->$k = trim($this->$k);
        self::set_sorter($this, $this->conf);
    }

    function escape() {
        global $Qreq;
        if ($Qreq->ajax || $Qreq->latestcommit) {
            if ($this->is_empty()) {
                json_exit(["ok" => false, "loggedout" => true]);
            } else {
                json_exit(["ok" => false, "error" => "You don’t have permission to access that page."]);
            }
        }

        if ($this->is_empty()) {
            // Preserve post values across session expiration.
            $x = array();
            if (Navigation::path()) {
                $x["__PATH__"] = preg_replace(",^/+,", "", Navigation::path());
            }
            if ($Qreq->anchor) {
                $x["anchor"] = $Qreq->anchor;
            }
            $url = $this->conf->selfurl($Qreq, [], Conf::HOTURL_RAW | Conf::HOTURL_SITE_RELATIVE);
            $_SESSION["login_bounce"] = array($this->conf->dsn, $url, Navigation::page(), $_POST);
            if (check_post()) {
                error_go(false, "You’ve been logged out due to inactivity, so your changes have not been saved. After logging in, you may submit them again.");
            } else {
                error_go(false, "You must sign in to access that page.");
            }
        } else {
            error_go(false, "You don’t have permission to access that page.");
        }
    }

    function save() {
        $this->trim();
        $inserting = !$this->contactId;
        $qf = $qv = array();
        foreach (array("firstName", "lastName", "email", "affiliation",
                       "password", "collaborators",
                       "roles", "defaultWatch", "passwordTime") as $k) {
            $qf[] = "$k=?";
            $qv[] = $this->$k;
        }
        $qf[] = "preferredEmail=?";
        $qv[] = $this->preferredEmail != "" ? $this->preferredEmail : null;
        $qf[] = "contactTags=?";
        $qv[] = $this->contactTags ? : null;
        $qf[] = "disabled=" . ($this->disabled ? 1 : 0);
        $q = ($inserting ? "insert into" : "update")
            . " ContactInfo set " . join(", ", $qf);
        if ($inserting) {
            $this->creationTime = Conf::$now;
            $q .= ", creationTime=" . Conf::$now;
        } else {
            $q .= " where contactId=" . $this->contactId;
        }
        $result = $this->conf->qe_apply($q, $qv);
        if (!$result) {
            return $result;
        }
        if ($inserting) {
            $this->contactId = $this->cid = $result->insert_id;
        }

        // add to contact database
        if ($this->conf->opt("contactdb_dsn") && ($cdb = self::contactdb())) {
            Dbl::ql($cdb, "insert into ContactInfo set firstName=?, lastName=?, email=?, affiliation=? on duplicate key update firstName=values(firstName), lastName=values(lastName), affiliation=values(affiliation)",
                    $this->firstName, $this->lastName, $this->email, $this->affiliation);
            if ($this->password_plaintext
                && ($cdb_user = self::contactdb_find_by_email($this->email))
                && !$cdb_user->password
                && !$cdb_user->disable_shared_password
                && !$this->conf->opt("contactdb_noPasswords")) {
                $cdb_user->change_password($this->password_plaintext, true, 0);
            }
        }

        return $result;
    }

    static function contactdb_find_by_email($email) {
        return null;
    }

    private function load_links() {
        $this->links = [1 => [], 2 => [], 3 => [], 4 => [], 5 => []];
        if ($this->contactLinks === null) {
            $this->contactLinks = $this->conf->fetch_value("select group_concat(type, ' ', pset, ' ', link) from ContactLink where cid=?", $this->contactId);
        }
        foreach (explode(",", $this->contactLinks ?? "") as $l) {
            if ($l !== "") {
                $a = explode(" ", $l);
                $this->links[(int) $a[0]][(int) $a[1]][] = (int) $a[2];
            }
        }
        $this->contactLinks = null;
    }

    /** @param int $type
     * @param int $pset
     * @return ?int */
    function link($type, $pset = 0) {
        if ($this->links === null) {
            $this->load_links();
        }
        $l = $this->links[$type][$pset] ?? null;
        return $l !== null && count($l) === 1 ? $l[0] : null;
    }

    /** @param int $type
     * @param int $pset
     * @return list<int> */
    function links($type, $pset = 0) {
        if ($this->links === null) {
            $this->load_links();
        }
        $pset = is_object($pset) ? $pset->psetid : $pset;
        return $this->links[$type][$pset] ?? [];
    }

    /** @return int */
    function branchid(Pset $pset) {
        if ($this->links === null) {
            $this->load_links();
        }
        $l = $this->links[LINK_BRANCH][$pset->id] ?? null;
        return $l !== null && count($l) === 1 && !$pset->no_branch ? $l[0] : 0;
    }

    /** @return string */
    function branch(Pset $pset) {
        return $this->conf->branch($this->branchid($pset));
    }

    /** @param int $type
     * @param int $psetid */
    private function adjust_links($type, $psetid) {
        if ($type == LINK_REPO) {
            $this->repos = [];
        } else if ($type == LINK_PARTNER) {
            $this->partners = [];
        }
        if ($type === LINK_REPO || $type === LINK_BRANCH) {
            $this->invalidate_grades($psetid);
        }
    }

    /** @param int $type
     * @param int $psetid
     * @return bool */
    function clear_links($type, $psetid = 0, $nolog = false) {
        unset($this->links[$type][$psetid]);
        $this->adjust_links($type, $psetid);
        if ($this->conf->qe("delete from ContactLink where cid=? and type=? and pset=?", $this->contactId, $type, $psetid)) {
            if (!$nolog) {
                $this->conf->log("Clear links [$type,$psetid]", $this);
            }
            return true;
        } else {
            return false;
        }
    }

    /** @param int $type
     * @param int $psetid
     * @param int $link
     * @return bool */
    function set_link($type, $psetid, $link) {
        if ($this->links === null) {
            $this->load_links();
        }
        $this->clear_links($type, $psetid, false);
        $this->links[$type][$psetid] = [$link];
        if ($this->conf->qe("insert into ContactLink (cid,type,pset,link) values (?,?,?,?)", $this->contactId, $type, $psetid, $link)) {
            $this->conf->log("Set links [$type,$psetid,$link]", $this);
            return true;
        } else {
            return false;
        }
    }

    /** @param int $type
     * @param int $psetid
     * @param int $value
     * @return bool */
    function add_link($type, $psetid, $value) {
        assert($type !== LINK_REPO && $type !== LINK_BRANCH);
        if ($this->links === null) {
            $this->load_links();
        }
        if (!isset($this->links[$type][$psetid])) {
            $this->links[$type][$psetid] = array();
        }
        if (!in_array($value, $this->links[$type][$psetid])) {
            $this->links[$type][$psetid][] = $value;
            if ($this->conf->qe("insert into ContactLink (cid,type,pset,link) values (?,?,?,?)", $this->contactId, $type, $psetid, $value)) {
                $this->conf->log("Add link [$type,$psetid,$value]", $this);
                return true;
            } else {
                return false;
            }
        }
        return true;
    }

    /** @param int $pset
     * @return ?Repository */
    function repo($pset, Repository $repo = null) {
        $pset = is_object($pset) ? $pset->id : $pset;
        if (!array_key_exists($pset, $this->repos)) {
            $this->repos[$pset] = null;
            $repoid = $this->link(LINK_REPO, $pset);
            if ($repoid && (!$repo || $repo->repoid != $repoid)) {
                $repo = Repository::find_id($repoid, $this->conf);
            }
            if ($repoid && $repo) {
                $this->repos[$pset] = $repo;
            }
        }
        return $this->repos[$pset];
    }

    /** @param int $pset
     * @param ?Repository $repo
     * @return bool */
    function set_repo($pset, $repo) {
        $pset = is_object($pset) ? $pset->psetid : $pset;
        if ($repo) {
            $this->set_link(LINK_REPO, $pset, $repo->repoid);
        } else {
            $this->clear_links(LINK_REPO, $pset);
        }
        $this->repos[$pset] = $repo;
        return true;
    }

    /** @param int $pset
     * @return ?Contact */
    function partner($pset, Contact $partner = null) {
        $pset = is_object($pset) ? $pset->id : $pset;
        if (!array_key_exists($pset, $this->partners)) {
            $this->partners[$pset] = null;
            $pcid = $this->link(LINK_PARTNER, $pset);
            if ($pcid && (!$partner || $partner->contactId != $pcid)) {
                $partner = $this->conf->user_by_id($pcid);
            }
            if ($pcid && $partner) {
                if ($this->is_anonymous) {
                    $partner->set_anonymous(true);
                }
                $this->partners[$pset] = $partner;
            }
        }
        return $this->partners[$pset];
    }


    /** @param int $psetid */
    function invalidate_grades($psetid) {
        $this->conf->qe("delete from Settings where name=? or name=?",
                        "__gradets.p$psetid", "__gradets.pp$psetid");
        $this->conf->qe("update ContactInfo set gradeUpdateTime=greatest(?,gradeUpdateTime+1) where contactId=?", Conf::$now, $this->contactId);
        $this->gradeUpdateTime = max(Conf::$now, $this->gradeUpdateTime + 1);
        $this->_gcache = $this->_gcache_flags = $this->_gcache_group = [];
    }

    /** @return ?GradeExport */
    private function ensure_gcache(Pset $pset, $flags, GradeEntryConfig $ge = null) {
        $flagbase = PsetView::GRADEJSON_NO_LATE_HOURS | PsetView::GRADEJSON_NO_FORMULAS;
        assert(($flags & $flagbase) === $flags);

        $cflags = $this->_gcache_flags[$pset->id] ?? 0;
        if ($cflags === 0) {
            $this->_gcache[$pset->id] = null;
            $this->_gcache_flags[$pset->id] = 0;
        }
        $gexp = $this->_gcache[$pset->id];

        if ($cflags === 0
            || ($cflags & (~$flags & $flagbase)) !== 0) {
            //error_log("computing for {$this->email}/{$pset->nonnumeric_key}." . ($ge ? $ge->key : "total"));
            if ($this->student_set) {
                $info = $this->student_set->info_at($this->contactId, $pset);
            } else {
                $info = PsetView::make($pset, $this, $this->conf->site_contact());
            }
            if ($info && !$gexp) {
                $this->_gcache_flags[$pset->id] = $cflags = PsetView::GRADEJSON_NO_LATE_HOURS | PsetView::GRADEJSON_NO_FORMULAS;
                $this->_gcache[$pset->id] = $gexp = $info->grade_export(PsetView::GRADEJSON_SLICE | PsetView::GRADEJSON_OVERRIDE_VIEW | $cflags);
            }
            if ($info
                && !($flags & PsetView::GRADEJSON_NO_LATE_HOURS)
                && ($cflags & PsetView::GRADEJSON_NO_LATE_HOURS)) {
                $this->_gcache_flags[$pset->id] = $cflags = $cflags & ~PsetView::GRADEJSON_NO_LATE_HOURS;
                $info->grade_export_late_hours($gexp);
            }
            if ($info
                && !($flags & PsetView::GRADEJSON_NO_FORMULAS)
                && ($cflags & PsetView::GRADEJSON_NO_FORMULAS)) {
                $this->_gcache_flags[$pset->id] = $cflags = $cflags & ~PsetView::GRADEJSON_NO_FORMULAS;
                $info->grade_export_formulas($gexp);
            }
        }

        return $this->_gcache[$pset->id];
    }

    function gcache_entry(Pset $pset, GradeEntryConfig $ge) {
        $lh = $ge->key === "late_hours";
        $flags = ($lh ? 0 : PsetView::GRADEJSON_NO_LATE_HOURS)
            | ($ge->is_formula() ? 0 : PsetView::GRADEJSON_NO_FORMULAS);
        if (($gexp = $this->ensure_gcache($pset, $flags, $ge))) {
            return $lh ? $gexp->late_hours : $gexp->grades[$ge->pcview_index] ?? null;
        } else {
            return null;
        }
    }

    /** @param bool $noextra
     * @param bool $norm
     * @return null|int|float */
    function gcache_total(Pset $pset, $noextra, $norm) {
        if (($gexp = $this->ensure_gcache($pset, PsetView::GRADEJSON_NO_LATE_HOURS, null))) {
            $v = $noextra ? $gexp->total_noextra() : $gexp->total();
            if ($v !== null && $norm) {
                $v = round(($v * 1000.0) / $pset->max_grade(true)) / 10;
            }
            return $v;
        } else {
            return null;
        }
    }

    /** @param string $group
     * @param bool $noextra
     * @param bool $norm
     * @return ?float */
    function gcache_category_total($group, $noextra, $norm) {
        if (!isset($this->_gcache_group[$group])) {
            $this->_gcache_group[$group] = [false, false, false, false];
        }
        $i = ($noextra ? 1 : 0) | ($norm ? 2 : 0);
        if ($this->_gcache_group[$group][$i] === false) {
            $gw = $this->conf->category_weight($group);
            $x = null;
            foreach ($this->conf->psets() as $p) {
                if (!$p->disabled && $p->category === $group) {
                    $v = $this->gcache_total($p, $noextra, $norm);
                    if ($v !== null) {
                        if ($norm) {
                            $v *= $p->weight / $gw;
                        }
                        $x = ($x === null ? 0.0 : $x) + $v;
                    }
                }
            }
            $this->_gcache_group[$group][$i] = $x !== null ? round($x * 10.0) / 10 : null;
        }
        return $this->_gcache_group[$group][$i];
    }


    /** @param int $new_roles
     * @param Contact $actor
     * @return bool */
    function save_roles($new_roles, $actor) {
        $old_roles = $this->roles;
        // ensure there's at least one system administrator
        if (!($new_roles & self::ROLE_ADMIN) && ($old_roles & self::ROLE_ADMIN)
            && !(($result = $this->conf->qe("select contactId from ContactInfo where (roles&" . self::ROLE_ADMIN . ")!=0 and contactId!=" . $this->contactId . " limit 1"))
                 && $result->num_rows > 0)) {
            $new_roles |= self::ROLE_ADMIN;
        }
        // log role change
        $actor_email = ($actor ? " by $actor->email" : "");
        foreach ([self::ROLE_PC => "pc",
                  self::ROLE_ADMIN => "sysadmin",
                  self::ROLE_CHAIR => "chair"] as $role => $type) {
            if (($new_roles & $role) && !($old_roles & $role)) {
                $this->conf->log("Added as $type$actor_email", $this);
            } else if (!($new_roles & $role) && ($old_roles & $role)) {
                $this->conf->log("Removed as $type$actor_email", $this);
            }
        }
        // save the roles bits
        if ($old_roles != $new_roles) {
            $this->conf->qe("update ContactInfo set roles=$new_roles where contactId=$this->contactId");
            $this->assign_roles($new_roles);
        }
        return $old_roles != $new_roles;
    }

    private function load_by_query($where) {
        $result = $this->conf->q_raw("select ContactInfo.* from ContactInfo where $where");
        if (($row = $result ? $result->fetch_object() : null)) {
            $this->merge($row);
        }
        Dbl::free($result);
        return !!$row;
    }

    static function safe_registration($reg) {
        $safereg = (object) array();
        foreach (["email", "firstName", "lastName", "name", "preferredEmail",
                  "affiliation", "collaborators", "seascode_username", "github_username",
                  "unaccentedName"] as $k) {
            if (isset($reg[$k]))
                $safereg->$k = $reg[$k];
        }
        return $safereg;
    }

    private function _create_password($cdbu, Contact_Update $cu) {
        if ($cdbu && ($cdbu = $cdbu->contactdb_user())
            && $cdbu->allow_contactdb_password()) {
            $cu->qv["password"] = $this->password = "";
            $cu->qv["passwordTime"] = $this->passwordTime = $cdbu->passwordTime;
        } else if (!$this->conf->external_login()) {
            $cu->qv["password"] = $this->password = self::random_password();
            $cu->qv["passwordTime"] = $this->passwordTime = Conf::$now;
        } else {
            $cu->qv["password"] = $this->password = "";
        }
    }

    static function create(Conf $conf, $reg, $send = false) {
        global $Me;
        if (is_array($reg))
            $reg = (object) $reg;
        assert(is_string($reg->email));
        $email = trim($reg->email);
        assert($email !== "");

        // look up account first
        if (($acct = $conf->user_by_email($email)))
            return $acct;

        // validate email, check contactdb
        if (!get($reg, "no_validate_email") && !validate_email($email))
            return null;
        $cdbu = Contact::contactdb_find_by_email($email);
        if (get($reg, "only_if_contactdb") && !$cdbu)
            return null;

        $cj = (object) array();
        foreach (array("firstName", "lastName", "email", "affiliation",
                       "collaborators", "preferredEmail") as $k)
            if (($v = $cdbu && $cdbu->$k ? $cdbu->$k : get($reg, $k)))
                $cj->$k = $v;
        if (($v = $cdbu && $cdbu->voicePhoneNumber ? $cdbu->voicePhoneNumber : get($reg, "voicePhoneNumber")))
            $cj->phone = $v;
        if (($cdbu && $cdbu->disabled) || get($reg, "disabled"))
            $cj->disabled = true;

        $acct = new Contact;
        if ($acct->save_json($cj, null, $send)) {
            if ($Me && $Me->privChair) {
                $type = $acct->disabled ? "disabled " : "";
                $conf->infoMsg("Created {$type}account for <a href=\"" . hoturl("profile", "u=" . urlencode($acct->email)) . "\">" . Text::user_html_nolink($acct) . "</a>.");
            }
            return $acct;
        } else {
            $conf->log("Account $email creation failure", $Me);
            return null;
        }
    }

    function mark_create($send_email, $message_chair) {
        global $Me;
        if ($Me && $Me->privChair && $message_chair)
            $this->conf->infoMsg("Created account for <a href=\"" . hoturl("profile", "u=" . urlencode($this->email)) . "\">" . Text::user_html_nolink($this) . "</a>.");
        if ($send_email)
            $this->sendAccountInfo("create", false);
        if ($Me && $Me->has_email() && $Me->email !== $this->email)
            $this->conf->log("Created account ($Me->email)", $this);
        else
            $this->conf->log("Created account", $this);
    }


    // PASSWORDS
    //
    // password "": disabled user; example: anonymous users for review tokens
    // password "*": invalid password, used to require the contactdb
    // password starting with " ": legacy hashed password using hash_hmac
    //     format: " HASHMETHOD KEYID SALT[16B]HMAC"
    // password starting with " $": password hashed by password_hash
    //
    // contactdb_user password falsy: contactdb password unusable
    // contactdb_user password truthy: follows rules above (but no "*")
    //
    // PASSWORD PRINCIPLES
    //
    // - prefer contactdb password
    // - require contactdb password if it is newer
    //
    // PASSWORD CHECKING RULES
    //
    // if (contactdb password exists)
    //     check contactdb password;
    // if (contactdb password matches && contactdb password needs upgrade)
    //     upgrade contactdb password;
    // if (contactdb password matches && local password was from contactdb)
    //     set local password to contactdb password;
    // if (local password was not from contactdb || no contactdb)
    //     check local password;
    // if (local password matches && local password needs upgrade)
    //     upgrade local password;
    //
    // PASSWORD CHANGING RULES
    //
    // change(expected, new):
    // if (contactdb password allowed
    //     && (!expected || expected matches contactdb)) {
    //     change contactdb password and update time;
    //     set local password to "*";
    // } else
    //     change local password and update time;

    static function valid_password($input) {
        return $input !== "" && $input !== "0" && $input !== "*"
            && trim($input) === $input;
    }

    static function random_password($length = 14) {
        return hotcrp_random_password($length);
    }

    static function password_storage_cleartext() {
        return opt("safePasswords") < 1;
    }

    function allow_contactdb_password() {
        $cdbu = $this->contactdb_user();
        return $cdbu && $cdbu->password;
    }

    private function prefer_contactdb_password() {
        $cdbu = $this->contactdb_user();
        return $cdbu && $cdbu->password
            && (!$this->has_account_here() || $this->password === "");
    }

    function plaintext_password() {
        // Return the currently active plaintext password. This might not
        // equal $this->password because of the cdb.
        if ($this->password === "") {
            if ($this->contactId
                && ($cdbu = $this->contactdb_user()))
                return $cdbu->plaintext_password();
            else
                return false;
        } else if ($this->password[0] === " ")
            return false;
        else
            return $this->password;
    }


    // obsolete
    private function password_hmac_key($keyid) {
        if ($keyid === null)
            $keyid = $this->conf->opt("passwordHmacKeyid") ?? 0;
        $key = $this->conf->opt("passwordHmacKey.$keyid");
        if (!$key && $keyid == 0)
            $key = $this->conf->opt("passwordHmacKey");
        if (!$key) /* backwards compatibility */
            $key = $this->conf->setting_data("passwordHmacKey.$keyid");
        if (!$key) {
            error_log("missing passwordHmacKey.$keyid, using default");
            $key = "NdHHynw6JwtfSZyG3NYPTSpgPFG8UN8NeXp4tduTk2JhnSVy";
        }
        return $key;
    }

    private function check_hashed_password($input, $pwhash, $email) {
        if ($input == "" || $input === "*" || $pwhash === null || $pwhash === "")
            return false;
        else if ($pwhash[0] !== " ")
            return $pwhash === $input;
        else if ($pwhash[1] === "\$") {
            if (function_exists("password_verify"))
                return password_verify($input, substr($pwhash, 2));
        } else {
            if (($method_pos = strpos($pwhash, " ", 1)) !== false
                && ($keyid_pos = strpos($pwhash, " ", $method_pos + 1)) !== false
                && strlen($pwhash) > $keyid_pos + 17
                && function_exists("hash_hmac")) {
                $method = substr($pwhash, 1, $method_pos - 1);
                $keyid = substr($pwhash, $method_pos + 1, $keyid_pos - $method_pos - 1);
                $salt = substr($pwhash, $keyid_pos + 1, 16);
                return hash_hmac($method, $salt . $input, $this->password_hmac_key($keyid), true)
                    == substr($pwhash, $keyid_pos + 17);
            }
        }
        error_log("cannot check hashed password for user $email");
        return false;
    }

    private function password_hash_method() {
        $m = $this->conf->opt("passwordHashMethod");
        if (function_exists("password_verify") && !is_string($m))
            return is_int($m) ? $m : PASSWORD_DEFAULT;
        if (!function_exists("hash_hmac"))
            return false;
        if (is_string($m))
            return $m;
        return PHP_INT_SIZE == 8 ? "sha512" : "sha256";
    }

    private function preferred_password_keyid($iscdb) {
        if ($iscdb)
            return $this->conf->opt("contactdb_passwordHmacKeyid") ?? 0;
        else
            return $this->conf->opt("passwordHmacKeyid") ?? 0;
    }

    private function check_password_encryption($hash, $iscdb) {
        $safe = $this->conf->opt($iscdb ? "contactdb_safePasswords" : "safePasswords");
        if ($safe < 1
            || ($method = $this->password_hash_method()) === false
            || ($hash !== "" && $safe == 1 && $hash[0] !== " "))
            return false;
        else if ($hash === "" || $hash[0] !== " ")
            return true;
        else if (is_int($method))
            return $hash[1] !== "\$"
                || password_needs_rehash(substr($hash, 2), $method);
        else {
            $prefix = " " . $method . " " . $this->preferred_password_keyid($iscdb) . " ";
            return !str_starts_with($hash, $prefix);
        }
    }

    private function hash_password($input, $iscdb) {
        $method = $this->password_hash_method();
        if ($method === false)
            return $input;
        else if (is_int($method))
            return " \$" . password_hash($input, $method);
        else {
            $keyid = $this->preferred_password_keyid($iscdb);
            $key = $this->password_hmac_key($keyid);
            $salt = random_bytes(16);
            return " " . $method . " " . $keyid . " " . $salt
                . hash_hmac($method, $salt . $input, $key, true);
        }
    }

    function check_password($input) {
        assert(!$this->conf->external_login());
        if (($this->contactId && $this->disabled)
            || !self::valid_password($input))
            return false;
        // update passwordUseTime once a month
        $update_use_time = Conf::$now - 31 * 86400;

        $cdbu = $this->contactdb_user();
        $cdbok = false;
        if ($cdbu && ($hash = $cdbu->password)
            && $cdbu->allow_contactdb_password()
            && ($cdbok = $this->check_hashed_password($input, $hash, $this->email))) {
            if ($this->check_password_encryption($hash, true)) {
                $hash = $this->hash_password($input, true);
                Dbl::ql(self::contactdb(), "update ContactInfo set password=? where contactDbId=?", $hash, $cdbu->contactDbId);
                $cdbu->password = $hash;
            }
            if ($cdbu->passwordUseTime <= $update_use_time) {
                Dbl::ql(self::contactdb(), "update ContactInfo set passwordUseTime=? where contactDbId=?", Conf::$now, $cdbu->contactDbId);
                $cdbu->passwordUseTime = Conf::$now;
            }
        }

        $localok = false;
        if ($this->contactId && ($hash = $this->password)
            && ($localok = $this->check_hashed_password($input, $hash, $this->email))) {
            if ($this->check_password_encryption($hash, false)) {
                $hash = $this->hash_password($input, false);
                $this->conf->ql("update ContactInfo set password=? where contactId=?", $hash, $this->contactId);
                $this->password = $hash;
            }
            if ($this->passwordUseTime <= $update_use_time) {
                $this->conf->ql("update ContactInfo set passwordUseTime=? where contactId=?", Conf::$now, $this->contactId);
                $this->passwordUseTime = Conf::$now;
            }
        }

        return $cdbok || $localok;
    }

    const CHANGE_PASSWORD_PLAINTEXT = 1;
    const CHANGE_PASSWORD_NO_CDB = 2;

    function change_password($old, $new, $flags) {
        assert(!$this->conf->external_login());
        if ($new === null)
            $new = self::random_password();
        assert(self::valid_password($new));

        $cdbu = null;
        if (!($flags & self::CHANGE_PASSWORD_NO_CDB))
            $cdbu = $this->contactdb_user();
        if ($cdbu
            && (!$old || $cdbu->password)
            && (!$old || $this->check_hashed_password($old, $cdbu->password, $this->email))) {
            $hash = $new;
            if ($hash && !($flags & self::CHANGE_PASSWORD_PLAINTEXT)
                && $this->check_password_encryption("", true))
                $hash = $this->hash_password($hash, true);
            $cdbu->password = $hash;
            if (!$old || $old !== $new)
                $cdbu->passwordTime = Conf::$now;
            Dbl::ql(self::contactdb(), "update ContactInfo set password=?, passwordTime=? where contactDbId=?", $cdbu->password, $cdbu->passwordTime, $cdbu->contactDbId);
            if ($this->contactId && $this->password) {
                $this->password = "";
                $this->passwordTime = $cdbu->passwordTime;
                $this->conf->ql("update ContactInfo set password=?, passwordTime=? where contactId=?", $this->password, $this->passwordTime, $this->contactId);
            }
        } else if ($this->contactId
                   && (!$old || $this->check_hashed_password($old, $this->password, $this->email))) {
            $hash = $new;
            if ($hash && !($flags & self::CHANGE_PASSWORD_PLAINTEXT)
                && $this->check_password_encryption("", false))
                $hash = $this->hash_password($hash, false);
            $this->password = $hash;
            if (!$old || $old !== $new)
                $this->passwordTime = Conf::$now;
            $this->conf->ql("update ContactInfo set password=?, passwordTime=? where contactId=?", $this->password, $this->passwordTime, $this->contactId);
        }
    }


    function sendAccountInfo($sendtype, $sensitive) {
        assert(!$this->disabled);
        $rest = array();
        if ($sendtype == "create" && $this->prefer_contactdb_password())
            $template = "@activateaccount";
        else if ($sendtype == "create")
            $template = "@createaccount";
        else if ($this->plaintext_password()
                 && ($this->conf->opt("safePasswords") <= 1 || $sendtype != "forgot"))
            $template = "@accountinfo";
        else {
            if ($this->contactDbId && $this->prefer_contactdb_password())
                $capmgr = $this->conf->capability_manager("U");
            else
                $capmgr = $this->conf->capability_manager();
            $rest["capability"] = $capmgr->create(CAPTYPE_RESETPASSWORD, array("user" => $this, "timeExpires" => time() + 259200));
            $this->conf->log("Created password reset " . substr($rest["capability"], 0, 8) . "...", $this);
            $template = "@resetpassword";
        }

        $mailer = new CS61Mailer($this, null, $rest);
        $prep = $mailer->make_preparation($template, $rest);
        if ($prep->sendable
            || !$sensitive
            || $this->conf->opt("debugShowSensitiveEmail")) {
            Mailer::send_preparation($prep);
            return $template;
        } else {
            Conf::msg_error("Mail cannot be sent to " . htmlspecialchars($this->email) . " at this time.");
            return false;
        }
    }


    function mark_login() {
        // at least one login every 90 days is marked as activity
        if (!$this->activity_at || $this->activity_at <= Conf::$now - 7776000
            || (($cdbu = $this->contactdb_user())
                && (!$cdbu->activity_at || $cdbu->activity_at <= Conf::$now - 7776000))) {
            $this->mark_activity();
        }
    }

    function mark_activity() {
        if (!$this->activity_at || $this->activity_at < Conf::$now) {
            $this->activity_at = Conf::$now;
            if ($this->contactId && !$this->is_anonymous_user()) {
                $this->conf->ql("update ContactInfo set lastLogin=" . Conf::$now . " where contactId=$this->contactId");
            }
            if ($this->contactDbId) {
                Dbl::ql(self::contactdb(), "update ContactInfo set activity_at=" . Conf::$now . " where contactDbId=$this->contactDbId");
            }
        }
    }

    function log_activity($text, $paperId = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user()) {
            $this->conf->log($text, $this, $paperId);
        }
    }

    function log_activity_for($user, $text, $paperId = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user()) {
            $this->conf->log($text . " by $this->email", $user, $paperId);
        }
    }

    function change_username($prefix, $username) {
        assert($prefix === "github" || $prefix === "seascode");
        $k = $prefix . "_username";
        $this->$k = $username;
        if ($this->conf->qe("update ContactInfo set $k=? where contactId=?", $username, $this->contactId)) {
            $this->conf->log("Set $k to $username", $this);
        }
        return true;
    }


    function link_repo($html, $url) {
        if ($this->is_anonymous) {
            return '<a href="" data-pa-link="' . htmlspecialchars($url) . '" class="ui pa-anonymized-link">' . $html . '</a>';
        } else {
            return '<a href="' . htmlspecialchars($url) . '">' . $html . '</a>';
        }
    }

    /** @param Pset $pset
     * @param ?Contact $user
     * @return bool */
    function can_set_repo($pset, $user = null) {
        if (is_string($pset) || is_int($pset)) {
            $pset = $this->conf->pset_by_id($pset);
        }
        if ($this->privChair) {
            return true;
        }
        $is_pc = $user && $user != $this && $this->isPC;
        return $pset
            && $this->has_account_here()
            && (!$user || $user === $this || $is_pc)
            && ($is_pc || !$pset->frozen || !$this->show_setting_on($pset->frozen, $pset));
    }

    function set_partner($pset, $partner) {
        $pset = is_object($pset) ? $pset->psetid : $pset;

        // does it contain odd characters?
        $partner = trim($partner);
        $pc = $this->conf->user_by_whatever($partner);
        if (!$pc && ($partner == "" || strcasecmp($partner, "none") == 0)) {
            $pc = $this;
        } else if (!$pc || !$pc->contactId) {
            return Conf::msg_error("I can’t find someone with email/username " . htmlspecialchars($partner) . ". Check your spelling.");
        }

        foreach ($this->links(LINK_PARTNER, $pset) as $link) {
            $this->conf->qe("delete from ContactLink where cid=? and type=? and pset=? and link=?", $link, LINK_BACKPARTNER, $pset, $this->contactId);
        }
        if ($pc->contactId == $this->contactId) {
            return $this->clear_links(LINK_PARTNER, $pset);
        } else {
            return $this->set_link(LINK_PARTNER, $pset, $pc->contactId)
                && $this->conf->qe("insert into ContactLink set cid=?, type=?, pset=?, link=?",
                                   $pc->contactId, LINK_BACKPARTNER, $pset, $this->contactId);
        }
    }

    function can_view_repo_contents(Repository $repo, $branch = null, $cached = false) {
        if (!$this->conf->opt("restrictRepoView")
            || $this->isPC
            || $repo->is_handout) {
            return true;
        }
        $allowed = get($repo->viewable_by, $this->contactId);
        if ($allowed === null) {
            $allowed = in_array($repo->repoid, $this->links(LINK_REPOVIEW));
            if (!$allowed && $cached) {
                return false;
            }
            if (!$allowed) {
                $users = $repo->author_emails();
                $allowed = isset($users[strtolower($this->email)]);
                if (!$allowed && $branch && $branch !== $this->conf->default_main_branch) {
                    $users = $repo->author_emails(null, $branch);
                    $allowed = isset($users[strtolower($this->email)]);
                }
                if ($allowed) {
                    $this->add_link(LINK_REPOVIEW, 0, $repo->repoid);
                }
            }
            $repo->viewable_by[$this->contactId] = $allowed;
        }
        return $allowed;
    }

    static function forward_pset_links($pset) {
        global $Conf, $Me;
        $pset = is_object($pset) ? $pset->psetid : $pset;
        assert(is_int($pset));
        $forwarded = $Conf->setting("pset_forwarded");
        if ($forwarded) {
            $Conf->qe("insert into ContactLink (cid, type, pset, link)
                    select l.cid, l.type, ?, l.link
                    from ContactLink l
                    left join ContactLink l2 on (l2.cid=l.cid and l2.type=l.type and l2.pset=?)
                    where l.pset=? and l2.cid is null
                    group by l.cid, l.type, l.link",
                    $pset, $pset, $forwarded);
            $Conf->log("forward pset links from $forwarded to $pset", $Me);
        }
        $Conf->qe("insert into Settings (name,value) values ('pset_forwarded',$pset) on duplicate key update value=greatest(value,values(value))");
    }

    private function show_setting_on($setting, Pset $pset) {
        return $setting === true
            || (is_int($setting) && $setting >= Conf::$now)
            || ($setting === "grades" && $this->xxx_can_view_grades($pset));
    }

    function can_view_pset(Pset $pset) {
        return (!$pset->disabled && $pset->gitless && $this->isPC)
            || (!$pset->admin_disabled && $this->privChair)
            || $pset->student_can_view();
    }

    function xxx_can_view_grades(Pset $pset) {
        return $this->can_view_pset($pset)
            && ($this->isPC || $pset->student_can_view_grades());
    }

    function can_view_grader(Pset $pset, Contact $user = null) {
        return $this->isPC;
    }

    function can_set_grader(Pset $pset, Contact $user = null) {
        return $this->isPC;
    }

    function can_view_comments(Pset $pset, PsetView $info = null) {
        return $this->can_view_pset($pset)
            && ($this->isPC || !$pset->hide_comments)
            && (!$info
                || ($this->isPC && $info->pc_view)
                || ($this === $info->user
                    && ($pset->gitless
                        || ($info->repo && $info->user_can_view_repo_contents()))));
    }

    function can_run(Pset $pset, RunnerConfig $runner = null, $user = null) {
        if (!$runner || $runner->disabled) {
            return false;
        } else if ($this->isPC) {
            return true;
        } else {
            return $runner->visible && $this->show_setting_on($runner->visible, $pset);
        }
    }

    function can_view_run(Pset $pset, RunnerConfig $runner, $user = null) {
        if ($runner->disabled) {
            return false;
        } else if ($this->isPC) {
            return true;
        } else {
            return ($runner->visible && $this->show_setting_on($runner->visible, $pset))
                || ($runner->output_visible && $this->show_setting_on($runner->output_visible, $pset));
        }
    }

    function can_view_transferred_warnings(Pset $pset, RunnerConfig $runner, $user = null) {
        if ($runner->disabled) {
            return false;
        } else if ($this->isPC) {
            return true;
        } else {
            return ($runner->visible && $this->show_setting_on($runner->visible, $pset))
                || ($runner->output_visible && $this->show_setting_on($runner->output_visible, $pset))
                || ($runner->transfer_warnings === "grades" && $this->show_setting_on($runner->transfer_warnings, $pset));
        }
    }

    function user_linkpart(Contact $user = null, $is_anonymous = false) {
        $user = $user ?? $this;
        if ($this->isPC && ($user->is_anonymous || (!$user->isPC && $is_anonymous))) {
            return $user->anon_username;
        } else if ($this->isPC || get($_SESSION, "last_actas")) {
            return $user->username ? : $user->email;
        } else {
            return null;
        }
    }

    function user_idpart(Contact $user = null) {
        $user = $user ?? $this;
        if (!$this->isPC && !get($_SESSION, "last_actas")) {
            return null;
        } else {
            return $user->github_username ? : ($user->seascode_username ? : $user->huid);
        }
    }
}
