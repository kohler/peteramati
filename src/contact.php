<?php
// contact.php -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
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
    static public $trueuser_privChair = null;
    static public $allow_nonexistent_properties = false;

    public $contactId = 0;
    public $contactDbId = 0;
    private $cid;               // for forward compatibility
    public $conf;

    public $firstName = "";
    public $lastName = "";
    public $unaccentedName = "";
    public $nameAmbiguous = null;
    public $firstNameAmbiguous = null;
    public $email = "";
    public $preferredEmail = "";
    public $sorter = "";
    public $sort_position = null;

    public $affiliation = "";

    private $password = "";
    private $passwordTime = 0;
    private $passwordUseTime = 0;
    private $defaultWatch;
    private $visits;
    private $creationTime;
    private $updateTime;
    private $lastLogin;

    public $disabled = false;
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
    public $pcid;
    public $rpcid;
    public $repoid;
    public $cacheid;
    public $heads;
    public $url;
    public $open;
    public $working;
    public $lastpset;
    public $snapcheckat;
    public $repoviewable;
    public $gradehash;
    public $gradercid;
    public $placeholder;
    public $placeholder_at;
    public $viewable_by;

    private $links = null;
    private $repos = array();
    private $partners = array();

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
        $this->conf = $conf ? : $Conf;
        if ($trueuser)
            $this->merge($trueuser);
        else if ($this->contactId || $this->contactDbId)
            $this->db_load();
        else if ($this->conf->opt("disableNonPC"))
            $this->disabled = true;
    }

    public static function fetch($result, Conf $conf = null) {
        global $Conf;
        $conf = $conf ? : $Conf;
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
            if (isset($user->contactId))
                $this->contactId = $this->cid = (int) $user->contactId;
            //else if (isset($user->cid))
            //    $this->contactId = $this->cid = (int) $user->cid;
        }
        if (isset($user->contactDbId))
            $this->contactDbId = (int) $user->contactDbId;
        if (isset($user->firstName) && isset($user->lastName))
            $name = $user;
        else
            $name = Text::analyze_name($user);
        $this->firstName = get_s($name, "firstName");
        $this->lastName = get_s($name, "lastName");
        if (isset($user->unaccentedName))
            $this->unaccentedName = $user->unaccentedName;
        else if (isset($name->unaccentedName))
            $this->unaccentedName = $name->unaccentedName;
        else
            $this->unaccentedName = Text::unaccented_name($name);
        foreach (array("email", "preferredEmail", "affiliation") as $k)
            if (isset($user->$k))
                $this->$k = simplify_whitespace($user->$k);
        if (isset($user->collaborators)) {
            $this->collaborators = "";
            foreach (preg_split('/[\r\n]+/', $user->collaborators) as $c)
                if (($c = simplify_whitespace($c)) !== "")
                    $this->collaborators .= "$c\n";
        }
        self::set_sorter($this);
        if (isset($user->password))
            $this->password = (string) $user->password;
        if (isset($user->disabled))
            $this->disabled = !!$user->disabled;
        foreach (["defaultWatch", "passwordTime", "passwordUseTime",
                  "updateTime", "creationTime"] as $k)
            if (isset($user->$k))
                $this->$k = (int) $user->$k;
        if (property_exists($user, "contactTags"))
            $this->contactTags = $user->contactTags;
        else
            $this->contactTags = false;
        if (isset($user->activity_at))
            $this->activity_at = (int) $user->activity_at;
        else if (isset($user->lastLogin))
            $this->activity_at = (int) $user->lastLogin;
        if (isset($user->extension))
            $this->extension = !!$user->extension;
        if (isset($user->seascode_username))
            $this->seascode_username = $user->seascode_username;
        if (isset($user->github_username))
            $this->github_username = $user->github_username;
        if (isset($user->anon_username))
            $this->anon_username = $user->anon_username;
        if (isset($user->contactImageId))
            $this->contactImageId = (int) $user->contactImageId;
        if (isset($user->roles) || isset($user->isPC) || isset($user->isAssistant)
            || isset($user->isChair)) {
            $roles = (int) get($user, "roles");
            if (get($user, "isPC"))
                $roles |= self::ROLE_PC;
            if (get($user, "isAssistant"))
                $roles |= self::ROLE_ADMIN;
            if (get($user, "isChair"))
                $roles |= self::ROLE_CHAIR;
            $this->assign_roles($roles);
        }
        if (!$this->isPC && $this->conf->opt("disableNonPC"))
            $this->disabled = true;
        if (isset($user->is_site_contact))
            $this->is_site_contact = $user->is_site_contact;
        $this->username = $this->github_username ? : $this->seascode_username;
    }

    private function db_load() {
        $this->contactId = $this->cid = (int) $this->contactId;
        $this->contactDbId = (int) $this->contactDbId;
        if ($this->unaccentedName === "")
            $this->unaccentedName = Text::unaccented_name($this->firstName, $this->lastName);
        self::set_sorter($this);
        $this->password = (string) $this->password;
        if (isset($this->disabled))
            $this->disabled = !!$this->disabled;
        foreach (["defaultWatch", "passwordTime"] as $k)
            $this->$k = (int) $this->$k;
        if (isset($this->activity_at))
            $this->activity_at = (int) $this->activity_at;
        else if (isset($this->lastLogin))
            $this->activity_at = (int) $this->lastLogin;
        if (isset($this->extension))
            $this->extension = !!$this->extension;
        if (isset($this->contactImageId))
            $this->contactImageId = (int) $this->contactImageId;
        if (isset($this->roles))
            $this->assign_roles((int) $this->roles);
        if (!$this->isPC && $this->conf->opt("disableNonPC"))
            $this->disabled = true;
        $this->username = $this->github_username ? : $this->seascode_username;
    }

    // begin changing contactId to cid
    public function __get($name) {
        if ($name === "cid")
            return $this->contactId;
        else
            return null;
    }

    public function __set($name, $value) {
        if ($name === "cid")
            $this->contactId = $this->cid = $value;
        else {
            if (!self::$allow_nonexistent_properties)
                error_log(caller_landmark(1) . ": writing nonexistent property $name");
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
        }
    }

    static public function set_sorter($c, $sorttype = null) {
        $sort_by_last = $sorttype === "last";
        if ($c->is_anonymous) {
            $c->sorter = $c->anon_username;
            return;
        } else if (isset($c->unaccentedName) && $sort_by_last) {
            $c->sorter = trim("$c->unaccentedName $c->email");
            return;
        }
        list($first, $middle) = Text::split_first_middle($c->firstName);
        if ($sort_by_last) {
            if (($m = Text::analyze_von($c->lastName)))
                $c->sorter = "$m[1] $first $m[0]";
            else
                $c->sorter = "$c->lastName $first";
        } else
            $c->sorter = "$first $c->last";
        $c->sorter = trim($c->sorter . " " . $c->username . " " . $c->email);
        if (preg_match('/[\x80-\xFF]/', $c->sorter))
            $c->sorter = UnicodeHelper::deaccent($c->sorter);
    }

    static public function compare($a, $b) {
        return strnatcasecmp($a->sorter, $b->sorter);
    }

    static public function site_contact() {
        global $Opt;
        if (!get($Opt, "contactEmail") || $Opt["contactEmail"] == "you@example.com") {
            $result = Dbl::ql("select firstName, lastName, email from ContactInfo where (roles&" . (self::ROLE_CHAIR | self::ROLE_ADMIN) . ")!=0 order by (roles&" . self::ROLE_CHAIR . ") desc limit 1");
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

    public function activate() {
        global $Now;
        $this->activated_ = true;
        $trueuser = get($_SESSION, "trueuser");
        $truecontact = null;

        // Handle actas requests
        $actas = req("actas");
        if ($actas && $trueuser) {
            if (is_numeric($actas)) {
                $acct = $this->conf->user_by_query("contactId=? or huid=? order by contactId=? desc limit 1", [$actas, $actas, $actas]);
                $actasemail = $acct ? $acct->email : null;
            } else if ($actas === "admin")
                $actasemail = $trueuser->email;
            else
                $actasemail = $actas;
            unset($_GET["actas"], $_POST["actas"], $_REQUEST["actas"]);
            if ($actasemail
                && strcasecmp($actasemail, $this->email) != 0
                && (strcasecmp($actasemail, $trueuser->email) == 0
                    || $this->privChair
                    || (($truecontact = $this->conf->user_by_email($trueuser->email))
                        && $truecontact->privChair))
                && ($actascontact = $this->conf->user_by_whatever($actasemail))) {
                $this->conf->save_session("l", null);
                if ($actascontact->email !== $trueuser->email) {
                    hoturl_defaults(array("actas" => $actascontact->email));
                    $_SESSION["last_actas"] = $actascontact->email;
                }
                if ($this->privChair || ($truecontact && $truecontact->privChair))
                    self::$trueuser_privChair = $actascontact;
                return $actascontact->activate();
            }
        }

        // Handle invalidate-caches requests
        if (req("invalidatecaches") && $this->privChair) {
            unset($_GET["invalidatecaches"], $_POST["invalidatecaches"], $_REQUEST["invalidatecaches"]);
            $this->conf->invalidate_caches();
        }

        // If validatorContact is set, use it
        if ($this->contactId <= 0 && req("validator")
            && ($vc = $this->conf->opt("validatorContact"))) {
            unset($_GET["validator"], $_POST["validator"], $_REQUEST["validator"]);
            if (($newc = $this->conf->user_by_email($vc))) {
                $this->activated_ = false;
                return $newc->activate();
            }
        }

        // Add capabilities from session and request
        if (!$this->conf->opt("disableCapabilities")) {
            if (($caps = $this->conf->session("capabilities"))) {
                $this->capabilities = $caps;
                ++self::$rights_version;
            }
            if (isset($_REQUEST["cap"]) || isset($_REQUEST["testcap"]))
                $this->activate_capabilities();
        }

        // Maybe set up the shared contacts database
        if ($this->conf->opt("contactdb_dsn") && $this->has_database_account()
            && $this->conf->session("contactdb_roles", 0) != $this->all_roles()) {
            if ($this->contactdb_update())
                $this->conf->save_session("contactdb_roles", $this->all_roles());
        }

        // Check forceShow
        self::$active_forceShow = $this->privChair && req("forceShow");

        return $this;
    }

    public function set_forceShow($on) {
        global $Me;
        if ($this->contactId == $Me->contactId) {
            self::$active_forceShow = $this->privChair && $on;
            if (self::$active_forceShow)
                $_GET["forceShow"] = $_POST["forceShow"] = $_REQUEST["forceShow"] = 1;
            else
                unset($_GET["forceShow"], $_POST["forceShow"], $_REQUEST["forceShow"]);
        }
    }

    public function activate_database_account() {
        assert($this->has_email());
        if (!$this->has_database_account()) {
            $reg = clone $_SESSION["trueuser"];
            if (strcasecmp($reg->email, $this->email) != 0)
                $reg = (object) array();
            $reg->email = $this->email;
            if (($c = Contact::create($this->conf, $reg))) {
                $this->load_by_id($c->contactId);
                $this->activate();
            }
        }
        return $this;
    }

    static public function contactdb() {
        return null;
    }

    public function contactdb_user() {
        return null;
    }

    public function update_trueuser($always) {
        if (($trueuser = get($_SESSION, "trueuser"))
            && strcasecmp($trueuser->email, $this->email) == 0) {
            foreach (array("firstName", "lastName", "affiliation", "country") as $k)
                if ($this->$k && ($always || !get($trueuser, $k)))
                    $trueuser->$k = $this->$k;
            return true;
        } else
            return false;
    }

    function is_empty() {
        return $this->contactId <= 0 && !$this->capabilities && !$this->email;
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

    function has_database_account() {
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
        if (($this->roles & self::ROLE_PC) && strcasecmp($t, "pc") == 0)
            return true;
        if ($this->contactTags)
            return stripos($this->contactTags, " $t#") !== false;
        if ($this->contactTags === false) {
            trigger_error(caller_landmark(1, "/^Conf::/") . ": Contact $this->email contactTags missing");
            $this->contactTags = null;
        }
        return false;
    }

    function tag_value($t) {
        if (($this->roles & self::ROLE_PC) && strcasecmp($t, "pc") == 0)
            return 0.0;
        if ($this->contactTags
            && ($p = stripos($this->contactTags, " $t#")) !== false)
            return (float) substr($this->contactTags, $p + strlen($t) + 2);
        return false;
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

    function capability($pid) {
        $caps = $this->capabilities ? : array();
        return get($caps, $pid) ? : 0;
    }

    function change_capability($pid, $c, $on = null) {
        if (!$this->capabilities)
            $this->capabilities = array();
        $oldval = get($this->capabilities, $pid) ? : 0;
        if ($on === null)
            $newval = ($c != null ? $c : 0);
        else
            $newval = ($oldval | ($on ? $c : 0)) & ~($on ? 0 : $c);
        if ($newval !== $oldval) {
            ++self::$rights_version;
            if ($newval !== 0)
                $this->capabilities[$pid] = $newval;
            else
                unset($this->capabilities[$pid]);
        }
        if (!count($this->capabilities))
            $this->capabilities = null;
        if ($this->activated_ && $newval !== $oldval)
            $this->conf->save_session("capabilities", $this->capabilities);
        return $newval != $oldval;
    }

    function apply_capability_text($text) {
        if (preg_match(',\A([-+]?)0([1-9][0-9]*)(a)(\S+)\z,', $text, $m)
            && ($result = $this->conf->ql("select paperId, capVersion from Paper where paperId=$m[2]"))
            && ($row = edb_orow($result))) {
            $rowcap = $this->conf->capability_text($row, $m[3]);
            $text = substr($text, strlen($m[1]));
            if ($rowcap === $text
                || $rowcap === str_replace("/", "_", $text))
                return $this->change_capability((int) $m[2], self::CAP_AUTHORVIEW, $m[1] !== "-");
        }
        return null;
    }

    private function make_data() {
        if (is_string($this->data_))
            $this->data_ = json_decode($this->data_);
        if (!$this->data_)
            $this->data_ = (object) array();
    }

    function data($key = null) {
        $this->make_data();
        if ($key)
            return get($this->data_, $key);
        else
            return $this->data_;
    }

    private function encode_data() {
        if ($this->data_ && ($t = json_encode($this->data_)) !== "{}")
            return $t;
        else
            return null;
    }

    function save_data($key, $value) {
        $this->merge_and_save_data((object) array($key => array_to_object_recursive($value)));
    }

    function merge_data($data) {
        $this->make_data();
        object_replace_recursive($this->data_, array_to_object_recursive($data));
    }

    function merge_and_save_data($data) {
        $this->activate_database_account();
        $this->make_data();
        $old = $this->encode_data();
        object_replace_recursive($this->data_, array_to_object_recursive($data));
        $new = $this->encode_data();
        if ($old !== $new)
            $this->conf->qe("update ContactInfo set data=? where contactId=?", $new, $this->contactId);
    }

    private function data_str() {
        $d = null;
        if (is_string($this->data))
            $d = $this->data;
        else if (is_object($this->data))
            $d = json_encode($this->data);
        return $d === "{}" ? null : $d;
    }

    private function trim() {
        $this->contactId = (int) trim($this->contactId);
        $this->cid = $this->contactId;
        $this->visits = trim($this->visits);
        $this->firstName = simplify_whitespace($this->firstName);
        $this->lastName = simplify_whitespace($this->lastName);
        foreach (array("email", "preferredEmail", "affiliation", "note") as $k)
            if ($this->$k)
                $this->$k = trim($this->$k);
        self::set_sorter($this);
    }

    function escape() {
        if (req("ajax") || req("latestcommit")) {
            if ($this->is_empty())
                $this->conf->ajaxExit(array("ok" => 0, "loggedout" => 1));
            else
                $this->conf->ajaxExit(array("ok" => 0, "error" => "You don’t have permission to access that page."));
        }

        if ($this->is_empty()) {
            // Preserve post values across session expiration.
            $x = array();
            if (Navigation::path())
                $x["__PATH__"] = preg_replace(",^/+,", "", Navigation::path());
            if (req("anchor"))
                $x["anchor"] = req("anchor");
            $url = self_href($x, array("raw" => true, "site_relative" => true));
            $_SESSION["login_bounce"] = array($this->conf->dsn, $url, Navigation::page(), $_POST);
            if (check_post())
                error_go(false, "You’ve been logged out due to inactivity, so your changes have not been saved. After logging in, you may submit them again.");
            else
                error_go(false, "You must sign in to access that page.");
        } else
            error_go(false, "You don’t have permission to access that page.");
    }

    function save() {
        global $Now;
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
            $this->creationTime = $Now;
            $q .= ", creationTime=$Now";
        } else
            $q .= " where contactId=" . $this->contactId;
        $result = $this->conf->qe_apply($q, $qv);
        if (!$result)
            return $result;
        if ($inserting)
            $this->contactId = $this->cid = $result->insert_id;

        // add to contact database
        if ($this->conf->opt("contactdb_dsn") && ($cdb = self::contactdb())) {
            Dbl::ql($cdb, "insert into ContactInfo set firstName=?, lastName=?, email=?, affiliation=? on duplicate key update firstName=values(firstName), lastName=values(lastName), affiliation=values(affiliation)",
                    $this->firstName, $this->lastName, $this->email, $this->affiliation);
            if ($this->password_plaintext
                && ($cdb_user = self::contactdb_find_by_email($this->email))
                && !$cdb_user->password
                && !$cdb_user->disable_shared_password
                && !$this->conf->opt("contactdb_noPasswords"))
                $cdb_user->change_password($this->password_plaintext, true);
        }

        return $result;
    }

    private function load_links() {
        $result = $this->conf->qe("select type, pset, link from ContactLink where cid=?", $this->contactId);
        $this->links = [1 => [], 2 => [], 3 => [], 4 => []];
        while (($row = edb_row($result)))
            $this->links[(int) $row[0]][(int) $row[1]][] = (int)$row[2];
    }

    function link($type, $pset = 0) {
        if ($this->links === null)
            $this->load_links();
        $l = get($this->links[$type], $pset);
        return count($l) == 1 ? $l[0] : null;
    }

    function links($type, $pset = 0) {
        if ($this->links === null)
            $this->load_links();
        $pset = is_object($pset) ? $pset->psetid : $pset;
        return get($this->links[$type], $pset, []);
    }

    private function adjust_links($type, $pset) {
        if ($type == LINK_REPO)
            $this->repos = array();
        else if ($type == LINK_PARTNER)
            $this->partners = array();
    }

    function clear_links($type, $pset = 0, $nolog = false) {
        unset($this->links[$type][$pset]);
        $this->adjust_links($type, $pset);
        if ($this->conf->qe("delete from ContactLink where cid=? and type=? and pset=?", $this->contactId, $type, $pset)) {
            if (!$nolog)
                $this->conf->log("Clear links [$type,$pset]", $this);
            return true;
        } else
            return false;
    }

    function set_link($type, $pset, $value) {
        if ($this->links === null)
            $this->load_links();
        $this->clear_links($type, $pset, false);
        $this->links[$type][$pset] = array($value);
        if ($this->conf->qe("insert into ContactLink (cid,type,pset,link) values (?,?,?,?)", $this->contactId, $type, $pset, $value)) {
            $this->conf->log("Set links [$type,$pset,$value]", $this);
            return true;
        } else
            return false;
    }

    function add_link($type, $pset, $value) {
        if ($this->links === null)
            $this->load_links();
        if (!isset($this->links[$type][$pset]))
            $this->links[$type][$pset] = array();
        if (!in_array($value, $this->links[$type][$pset])) {
            $this->links[$type][$pset][] = array($value);
            if ($this->conf->qe("insert into ContactLink (cid,type,pset,link) values (?,?,?,?)", $this->contactId, $type, $pset, $value)) {
                $this->conf->log("Add link [$type,$pset,$value]", $this);
                return true;
            } else
                return false;
        }
        return true;
    }

    function repo($pset) {
        $pset = is_object($pset) ? $pset->id : $pset;
        if (!array_key_exists($pset, $this->repos)) {
            $this->repos[$pset] = null;
            if (($link = $this->link(LINK_REPO, $pset)))
                $this->repos[$pset] = Repository::find_id($link, $this->conf);
        }
        return $this->repos[$pset];
    }

    private static function update_repo_lastpset($pset, $repo) {
        global $Conf;
        $Conf->qe("update Repository set lastpset=(select coalesce(max(pset),0) from ContactLink l where l.type=" . LINK_REPO . " and l.link=?) where repoid=?", $repo->repoid, $repo->repoid);
    }

    function set_repo($pset, $repo) {
        $pset = is_object($pset) ? $pset->psetid : $pset;
        $old_repo = $this->repo($pset);

        if ($repo)
            $this->set_link(LINK_REPO, $pset, $repo->repoid);
        else
            $this->clear_links(LINK_REPO, $pset);
        $this->repos[$pset] = $repo;

        if ($old_repo && (!$repo || $repo->repoid != $old_repo->repoid))
            self::update_repo_lastpset($pset, $old_repo);
        if ($repo && (!$old_repo || $repo->repoid != $old_repo->repoid))
            self::update_repo_lastpset($pset, $repo);
        return true;
    }

    function pcid($pset) {
        return $this->link(LINK_PARTNER, $pset);
    }

    function partner($pset) {
        $pset = is_object($pset) ? $pset->id : $pset;
        if (!array_key_exists($pset, $this->partners)) {
            $this->partners[$pset] = null;
            if (($pcid = $this->pcid($pset))
                && ($pc = self::find_by_id($pcid))) {
                if ($this->is_anonymous)
                    $pc->set_anonymous(true);
                $this->partners[$pset] = $pc;
            }
        }
        return $this->partners[$pset];
    }


    function save_roles($new_roles, $actor) {
        $old_roles = $this->roles;
        // ensure there's at least one system administrator
        if (!($new_roles & self::ROLE_ADMIN) && ($old_roles & self::ROLE_ADMIN)
            && !(($result = $this->conf->qe("select contactId from ContactInfo where (roles&" . self::ROLE_ADMIN . ")!=0 and contactId!=" . $this->contactId . " limit 1"))
                 && edb_nrows($result) > 0))
            $new_roles |= self::ROLE_ADMIN;
        // log role change
        $actor_email = ($actor ? " by $actor->email" : "");
        foreach (array(self::ROLE_PC => "pc",
                       self::ROLE_ADMIN => "sysadmin",
                       self::ROLE_CHAIR => "chair") as $role => $type)
            if (($new_roles & $role) && !($old_roles & $role))
                $this->conf->log("Added as $type$actor_email", $this);
            else if (!($new_roles & $role) && ($old_roles & $role))
                $this->conf->log("Removed as $type$actor_email", $this);
        // save the roles bits
        if ($old_roles != $new_roles) {
            $this->conf->qe("update ContactInfo set roles=$new_roles where contactId=$this->contactId");
            $this->assign_roles($new_roles);
        }
        return $old_roles != $new_roles;
    }

    private function load_by_query($where) {
        $result = $this->conf->q_raw("select ContactInfo.* from ContactInfo where $where");
        if (($row = $result ? $result->fetch_object() : null))
            $this->merge($row);
        Dbl::free($result);
        return !!$row;
    }

    static function find_by_id($cid) {
        $result = Dbl::q("select ContactInfo.* from ContactInfo where contactId=" . (int) $cid);
        return self::fetch($result);
    }

    static function safe_registration($reg) {
        $safereg = (object) array();
        foreach (array("email", "firstName", "lastName", "name", "preferredEmail",
                       "affiliation", "collaborators", "seascode_username", "github_username",
                       "unaccentedName") as $k)
            if (isset($reg[$k]))
                $safereg->$k = $reg[$k];
        return $safereg;
    }

    private function _create_password($cdbu, Contact_Update $cu) {
        global $Now;
        if ($cdbu && ($cdbu = $cdbu->contactdb_user())
            && $cdbu->allow_contactdb_password()) {
            $cu->qv["password"] = $this->password = "";
            $cu->qv["passwordTime"] = $this->passwordTime = $cdbu->passwordTime;
        } else if (!$this->conf->external_login()) {
            $cu->qv["password"] = $this->password = self::random_password();
            $cu->qv["passwordTime"] = $this->passwordTime = $Now;
        } else
            $cu->qv["password"] = $this->password = "";
    }

    static function create(Conf $conf, $reg, $send = false) {
        global $Me, $Now;
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

    public static function valid_password($input) {
        return $input !== "" && $input !== "0" && $input !== "*"
            && trim($input) === $input;
    }

    public static function random_password($length = 14) {
        return hotcrp_random_password($length);
    }

    public static function password_storage_cleartext() {
        return opt("safePasswords") < 1;
    }

    public function allow_contactdb_password() {
        $cdbu = $this->contactdb_user();
        return $cdbu && $cdbu->password;
    }

    private function prefer_contactdb_password() {
        $cdbu = $this->contactdb_user();
        return $cdbu && $cdbu->password
            && (!$this->has_database_account() || $this->password === "");
    }

    public function plaintext_password() {
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
            $keyid = $this->conf->opt("passwordHmacKeyid", 0);
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
            return $this->conf->opt("contactdb_passwordHmacKeyid", 0);
        else
            return $this->conf->opt("passwordHmacKeyid", 0);
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

    public function check_password($input) {
        global $Now;
        assert(!$this->conf->external_login());
        if (($this->contactId && $this->disabled)
            || !self::valid_password($input))
            return false;
        // update passwordUseTime once a month
        $update_use_time = $Now - 31 * 86400;

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
                Dbl::ql(self::contactdb(), "update ContactInfo set passwordUseTime=? where contactDbId=?", $Now, $cdbu->contactDbId);
                $cdbu->passwordUseTime = $Now;
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
                $this->conf->ql("update ContactInfo set passwordUseTime=? where contactId=?", $Now, $this->contactId);
                $this->passwordUseTime = $Now;
            }
        }

        return $cdbok || $localok;
    }

    const CHANGE_PASSWORD_PLAINTEXT = 1;
    const CHANGE_PASSWORD_NO_CDB = 2;

    public function change_password($old, $new, $flags) {
        global $Now;
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
                $cdbu->passwordTime = $Now;
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
                $this->passwordTime = $Now;
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
        if ($prep->sendable || !$sensitive
            || $this->conf->opt("debugShowSensitiveEmail")) {
            Mailer::send_preparation($prep);
            return $template;
        } else {
            Conf::msg_error("Mail cannot be sent to " . htmlspecialchars($this->email) . " at this time.");
            return false;
        }
    }


    public function mark_login() {
        global $Now;
        // at least one login every 90 days is marked as activity
        if (!$this->activity_at || $this->activity_at <= $Now - 7776000
            || (($cdbu = $this->contactdb_user())
                && (!$cdbu->activity_at || $cdbu->activity_at <= $Now - 7776000)))
            $this->mark_activity();
    }

    public function mark_activity() {
        global $Now;
        if (!$this->activity_at || $this->activity_at < $Now) {
            $this->activity_at = $Now;
            if ($this->contactId && !$this->is_anonymous_user())
                $this->conf->ql("update ContactInfo set lastLogin=$Now where contactId=$this->contactId");
            if ($this->contactDbId)
                Dbl::ql(self::contactdb(), "update ContactInfo set activity_at=$Now where contactDbId=$this->contactDbId");
        }
    }

    function log_activity($text, $paperId = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user())
            $this->conf->log($text, $this, $paperId);
    }

    function log_activity_for($user, $text, $paperId = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user())
            $this->conf->log($text . " by $this->email", $user, $paperId);
    }

    function change_username($prefix, $username) {
        assert($prefix === "github" || $prefix === "seascode");
        $k = $prefix . "_username";
        $this->$k = $username;
        if ($this->conf->qe("update ContactInfo set $k=? where contactId=?", $username, $this->contactId))
            $this->conf->log("Set $k to $username", $this);
        return true;
    }


    function link_repo($html, $url) {
        if ($this->is_anonymous)
            return '<a href="#" onclick=\'return pa_anonymize_linkto(' . htmlspecialchars(json_encode($url)) . ',event)\'>' . $html . '</a>';
        else
            return '<a href="' . htmlspecialchars($url) . '">' . $html . '</a>';
    }

    function can_set_repo($pset, $user = null) {
        global $Now;
        if (is_string($pset) || is_int($pset))
            $pset = $this->conf->pset_by_id($pset);
        if ($this->privChair)
            return true;
        $is_pc = $user && $user != $this && $this->isPC;
        return $pset && $this->has_database_account()
            && (!isset($pset->repo_edit_deadline)
                || $pset->repo_edit_deadline === false
                || (is_int($pset->repo_edit_deadline)
                    && $pset->repo_edit_deadline >= $Now))
            && (!$user || $user == $this || $is_pc)
            && ($is_pc || !$pset->frozen || !self::show_setting_on($pset->frozen));
    }

    function set_partner($pset, $partner) {
        global $ConfSitePATH;
        $pset = is_object($pset) ? $pset->psetid : $pset;

        // does it contain odd characters?
        $partner = trim($partner);
        $pc = $this->conf->user_by_whatever($partner);
        if (!$pc && ($partner == "" || strcasecmp($partner, "none") == 0))
            $pc = $this;
        else if (!$pc || !$pc->contactId)
            return Conf::msg_error("I can’t find someone with email/username " . htmlspecialchars($partner) . ". Check your spelling.");

        foreach ($this->links(LINK_PARTNER, $pset) as $link)
            $this->conf->qe("delete from ContactLink where cid=? and type=? and pset=? and link=?", $link, LINK_BACKPARTNER, $pset, $this->contactId);
        if ($pc->contactId == $this->contactId)
            return $this->clear_links(LINK_PARTNER, $pset);
        else
            return $this->set_link(LINK_PARTNER, $pset, $pc->contactId)
                && $this->conf->qe("insert into ContactLink set cid=?, type=?, pset=?, link=?",
                                   $pc->contactId, LINK_BACKPARTNER, $pset, $this->contactId);
    }

    static function check_repo($repo, $delta, $foreground = false) {
        global $ConfSitePATH, $Now;
        assert(isset($repo->repoid) && isset($repo->cacheid) && isset($repo->url) && property_exists($repo, "snapcheckat"));
        if ($repo->repoid && (!$repo->snapcheckat || $repo->snapcheckat + $delta <= $Now)) {
            Dbl::qe("update Repository set snapcheckat=$Now where repoid=$repo->repoid");
            $repo->snapcheckat = $Now;
            if ($foreground)
                set_time_limit(30);
            // see also handout_repo
            $command = "$ConfSitePATH/src/gitfetch $repo->repoid $repo->cacheid " . escapeshellarg($repo->ssh_url()) . " 1>&2" . ($foreground ? "" : " &");
            shell_exec($command);
        }
    }

    static private function _file_glob_to_regex($x, $prefix) {
        $x = str_replace(array('\*', '\?', '\[', '\]', '\-', '_'),
                         array('[^/]*', '[^/]', '[', ']', '-', '\_'),
                         preg_quote($x));
        if ($x === "")
            return "";
        else if (strpos($x, "/") === false) {
            if ($prefix)
                return '|\A' . preg_quote($prefix) . '/' . $x;
            else
                return '|' . $x;
        } else {
            if ($prefix)
                return '|\A' . preg_quote($prefix) . '/' . $x . '\z';
            else
                return '|\A' . $x . '\z';
        }
    }

    private static function file_ignore_regex(Pset $pset, Repository $repo) {
        global $Conf, $Now;
        if ($pset && get($pset, "file_ignore_regex"))
            return $pset->file_ignore_regex;
        $regex = '.*\.swp|.*~|#.*#|.*\.core|.*\.dSYM|.*\.o|core.*\z|.*\.backup|tags|tags\..*|typescript';
        if ($pset && $Conf->setting("__gitignore_pset{$pset->id}_at", 0) < $Now - 900) {
            $hrepo = $pset->handout_repo($repo);
            $result = "";
            if ($pset->directory_slash !== "")
                $result .= $repo->gitrun("git show repo{$hrepo->repoid}/master:" . escapeshellarg($pset->directory_slash) . ".gitignore 2>/dev/null");
            $result .= $repo->gitrun("git show repo{$hrepo->repoid}/master:.gitignore 2>/dev/null");
            $Conf->save_setting("__gitignore_pset{$pset->id}_at", $Now);
            $Conf->save_setting("gitignore_pset{$pset->id}", 1, $result);
        }
        if ($pset && ($result = $Conf->setting_data("gitignore_pset{$pset->id}")))
            foreach (preg_split('/\s+/', $Conf->setting_data("gitignore_pset$pset->id")) as $x)
                $regex .= self::_file_glob_to_regex($x, $pset->directory_noslash);
        if ($pset && ($xarr = $pset->ignore)) {
            if (!is_array($xarr))
                $xarr = preg_split('/\s+/', $xarr);
            foreach ($xarr as $x)
                $regex .= self::_file_glob_to_regex($x, false);
        }
        return $regex;
    }

    static private function _temp_repo_clone($repo) {
        global $Now, $ConfSitePATH;
        assert(isset($repo->repoid) && isset($repo->cacheid));
        $suffix = "";
        while (1) {
            $d = "$ConfSitePATH/repo/tmprepo.$Now$suffix";
            if (mkdir($d, 0770))
                break;
            $suffix = $suffix ? "_" . substr($suffix, 1) + 1 : "_1";
        }
        $answer = shell_exec("cd $d && git init >/dev/null && git remote add origin $ConfSitePATH/repo/repo$repo->cacheid >/dev/null && echo yes");
        return ($answer == "yes\n" ? $d : null);
    }

    static private function _repo_prepare_truncated_handout($repo, $base, $pset) {
        $commit = trim($repo->gitrun("git log --format=%H -n1 $base"));
        $check_tag = trim($repo->gitrun("test -f .git/refs/heads/truncated_$commit && echo yes"));
        if ($check_tag == "yes")
            return "truncated_$commit";

        $pset_files = $repo->ls_files($commit, $pset->directory_noslash);
        foreach ($pset_files as &$f)
            $f = substr($f, strlen($pset->directory_slash));
        unset($f);

        if (!($trepo = self::_temp_repo_clone($repo)))
            return false;
        foreach ($pset_files as $f)
            $repo->gitrun("mkdir -p \"`dirname $trepo/$f`\" && git show $commit:$pset->directory_slash$f > $trepo/$f");

        foreach ($pset_files as &$f)
            $f = escapeshellarg($f);
        unset($f);
        shell_exec("cd $trepo && git add " . join(" ", $pset_files) . " && git commit -m 'Truncated version of $commit'");
        shell_exec("cd $trepo && git push -f origin master:truncated_$commit");
        shell_exec("rm -rf $trepo");
        return "truncated_$commit";
    }

    static private function save_repo_diff(&$diff_files, $fname, &$diff, $diffinfo, $blineno) {
        $x = new DiffInfo($fname, $diff, $diffinfo, $blineno);
        $diff_files[$fname] = $x;
    }

    static private function unquote_filename_regex($regex) {
        $unquoted = preg_replace(",\\\\(.),", '$1', $regex);
        if (preg_quote($unquoted) == $regex)
            return $unquoted;
        else
            return false;
    }

    static private function pset_diffinfo($pset, $repo) {
        if ($pset && !get($pset, "__applied_file_ignore_regex")) {
            if (($regex = self::file_ignore_regex($pset, $repo)))
                $pset->diffs[] = new DiffConfig($regex, (object) array("ignore" => true, "match_priority" => -10));
            $pset->__applied_file_ignore_regex = true;
        }
        return $pset->diffs;
    }

    static private function find_diffinfo($pset_diffs, $fname) {
        $diffinfo = false;
        foreach ($pset_diffs as $d)
            if (preg_match('{(?:\A|/)(?:' . $d->regex . ')(?:/|\z)}', $fname))
                $diffinfo = DiffConfig::combine($diffinfo, $d);
        return $diffinfo;
    }

    static function repo_diff_compare($a, $b) {
        list($ap, $bp) = array((float) $a->priority, (float) $b->priority);
        if ($ap != $bp)
            return $ap < $bp ? 1 : -1;
        return strcmp($a->filename, $b->filename);
    }

    static function repo_diff(Repository $repo, $hashb, Pset $pset, $options = null) {
        global $Conf, $Now;
        $options = $options ? : array();
        $diff_files = array();
        assert($pset); // code remains for `!$pset`; maybe revive it?

        $psetdir = $pset ? escapeshellarg($pset->directory_noslash) : null;
        if ($pset && $repo->truncated_psetdir($pset)) {
            $repodir = "";
            $truncpfx = $pset->directory_noslash . "/";
        } else {
            $repodir = $psetdir . "/"; // Some gits don't do `git show HASH:./FILE`!
            $truncpfx = "";
        }

        if (get($options, "hasha"))
            $hasha = $options["hasha"];
        else {
            $hrepo = $pset->handout_repo($repo);
            if ($pset && isset($pset->gradebranch))
                $hasha = "repo{$hrepo->repoid}/" . $pset->gradebranch;
            else if ($pset && isset($pset->handout_repo_branch))
                $hasha = "repo{$hrepo->repoid}/" . $pset->handout_repo_branch;
            else
                $hasha = "repo{$hrepo->repoid}/master";
            $options["hasha_hrepo"] = true;
        }
        if ($truncpfx && get($options, "hasha_hrepo") && !get($options, "hashb_hrepo"))
            $hasha = self::_repo_prepare_truncated_handout($repo, $hasha, $pset);

        $ignore_diffinfo = get($options, "hasha_hrepo") && get($options, "hashb_hrepo");

        // read "full" files
        $pset_diffs = self::pset_diffinfo($pset, $repo);
        foreach ($pset_diffs as $diffinfo)
            if (!$ignore_diffinfo
                && $diffinfo->full
                && ($fname = self::unquote_filename_regex($diffinfo->regex)) !== false) {
                $result = $repo->gitrun("git show $hashb:${repodir}$fname");
                $fdiff = array();
                foreach (explode("\n", $result) as $idx => $line)
                    $fdiff[] = array("+", 0, $idx + 1, $line);
                self::save_repo_diff($diff_files, "{$pset->directory_slash}$fname", $fdiff, $diffinfo, count($fdiff) ? 1 : 0);
            }

        $command = "git diff --name-only $hasha $hashb";
        if ($pset && !$truncpfx)
            $command .= " -- " . escapeshellarg($pset->directory_noslash);
        $result = $repo->gitrun($command);

        $files = array();
        foreach (explode("\n", $result) as $line)
            if ($line != "") {
                $diffinfo = self::find_diffinfo($pset_diffs, $truncpfx . $line);
                // skip files presented in their entirety
                if ($diffinfo && !$ignore_diffinfo && get($diffinfo, "full"))
                    continue;
                // skip ignored files, unless user requested them
                if ($diffinfo && !$ignore_diffinfo && get($diffinfo, "ignore")
                    && (!get($options, "needfiles")
                        || !get($options["needfiles"], $truncpfx . $line)))
                    continue;
                $files[] = escapeshellarg(quotemeta($line));
            }

        if (count($files)) {
            $command = "git diff";
            if (get($options, "wdiff"))
                $command .= " -w";
            $command .= " $hasha $hashb -- " . join(" ", $files);
            $result = $repo->gitrun($command);
            $file = null;
            $alineno = $blineno = null;
            $fdiff = null;
            $pos = 0;
            $len = strlen($result);
            while (1) {
                if (count($fdiff) > DiffInfo::MAXLINES) {
                    while ($pos < $len
                           && (($ch = $result[$pos]) === " " || $ch === "+" || $ch === "-")) {
                        $nlpos = strpos($result, "\n", $pos);
                        $pos = $nlpos === false ? $len : $nlpos + 1;
                    }
                }
                if ($pos >= $len)
                    break;
                $nlpos = strpos($result, "\n", $pos);
                $line = $nlpos === false ? substr($result, $pos) : substr($result, $pos, $nlpos - $pos);
                $pos = $nlpos === false ? $len : $nlpos + 1;
                if ($line == "")
                    /* do nothing */;
                else if ($line[0] == " " && $file && $alineno) {
                    $fdiff[] = array(" ", $alineno, $blineno, substr($line, 1));
                    ++$alineno;
                    ++$blineno;
                } else if ($line[0] == "-" && $file && $alineno) {
                    $fdiff[] = array("-", $alineno, $blineno, substr($line, 1));
                    ++$alineno;
                } else if ($line[0] == "+" && $file && $blineno) {
                    $fdiff[] = array("+", $alineno, $blineno, substr($line, 1));
                    ++$blineno;
                } else if ($line[0] == "@" && $file && preg_match('_\A@@ -(\d+),\d+ \+(\d+),\d+ @@_', $line, $m)) {
                    $fdiff[] = array("@", null, null, $line);
                    $alineno = +$m[1];
                    $blineno = +$m[2];
                } else if ($line[0] == "d" && preg_match('_\Adiff --git a/(.*) b/\1\z_', $line, $m)) {
                    if ($fdiff)
                        self::save_repo_diff($diff_files, $file, $fdiff, $diffinfo, $blineno);
                    $file = $truncpfx . $m[1];
                    $diffinfo = self::find_diffinfo($pset_diffs, $file);
                    $fdiff = array();
                    $alineno = $blineno = null;
                } else if ($line[0] == "B" && $file && preg_match('_\ABinary files_', $line)) {
                    $fdiff[] = array("@", null, null, $line);
                } else
                    $alineno = $blineno = null;
            }
            if ($fdiff)
                self::save_repo_diff($diff_files, $file, $fdiff, $diffinfo, $blineno);
        }

        uasort($diff_files, "Contact::repo_diff_compare");
        return $diff_files;
    }

    function can_view_repo_contents($repo, $cache_only = false) {
        if (!$this->conf->opt("restrictRepoView")
            || $this->isPC
            || $repo->is_handout)
            return true;
        $allowed = get($repo->viewable_by, $this->contactId);
        if ($allowed === null) {
            $allowed = in_array($this->contactId, $this->links(LINK_REPOVIEW));
            if (!$allowed && !$cache_only) {
                $users = $repo->author_emails();
                $allowed = isset($users[strtolower($this->email)]);
                if ($allowed)
                    $this->add_link(LINK_REPOVIEW, 0, $repo->repoid);
            }
            if ($allowed || !$cache_only)
                $repo->viewable_by[$this->contactId] = $allowed;
        }
        return $allowed;
    }

    static function update_all_repo_lastpset() {
        global $Conf;
        $Conf->qe("update Repository join (select link, max(pset) as pset from ContactLink where type=" . LINK_REPO . " group by link) l on (l.link=repoid) set lastpset=l.pset");
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
            self::update_all_repo_lastpset();
            $Conf->log("forward pset links from $forwarded to $pset", $Me);
        }
        $Conf->qe("insert into Settings (name,value) values ('pset_forwarded',$pset) on duplicate key update value=greatest(value,values(value))");
    }

    static function show_setting_on($setting) {
        global $Now;
        return $setting === true || (is_int($setting) && $setting >= $Now);
    }

    static function student_can_see_pset($pset) {
        return isset($pset->visible)
            && self::show_setting_on($pset->visible)
            && !$pset->disabled;
    }

    static function student_can_see_grades(Pset $pset, $extension = null) {
        if ($extension === null)
            $k = "grades_visible";
        else if ($extension)
            $k = "grades_visible_extension";
        else
            $k = "grades_visible_college";
        return self::student_can_see_pset($pset)
            && isset($pset->$k)
            && self::show_setting_on($pset->$k);
    }

    static function student_can_see_grade_cdf(Pset $pset) {
        return self::student_can_see_pset($pset)
            && (isset($pset->grade_cdf_visible)
                ? self::show_setting_on($pset->grade_cdf_visible)
                : self::student_can_see_grades($pset));
    }

    function can_see_grader(Pset $pset, Contact $user = null) {
        return ($this->isPC && (!$user || $user != $this))
            || $this->privChair;
    }

    function can_set_grader(Pset $pset, Contact $user = null) {
        return ($this->isPC && (!$user || $user != $this))
            || $this->privChair;
    }

    function can_see_comments(Pset $pset, Contact $user = null, $info = null) {
        return (!$pset || !$pset->disabled)
            && (($this->isPC && $user && $user != $this)
                || $this->privChair
                || (!$pset || !$pset->hide_comments));
    }

    function can_see_grades(Pset $pset, Contact $user = null, $info = null) {
        return (!$pset || !$pset->disabled)
            && (($this->isPC && $user && $user != $this)
                || $this->privChair
                || ($pset && self::student_can_see_grades($pset, $this->extension)
                    && (!$info || !$info->grades_hidden())));
    }

    function can_run(Pset $pset, $runner, $user = null) {
        if (!$runner || $runner->disabled)
            return false;
        if ($this->isPC && (!$user || $user != $this))
            return true;
        $s2s = $runner->visible;
        return $s2s === true
            || (($s2s === "grades" || $s2s === "grade")
                && $this->can_see_grades($pset));
    }

    function can_view_run(Pset $pset, $runner, $user = null) {
        if (!$runner || $runner->disabled)
            return false;
        if ($this->isPC && (!$user || $user != $this))
            return true;
        $s2s = $runner->visible;
        $r2s = $runner->output_visible;
        return ($s2s === true || $r2s === true)
            || (($s2s === "grades" || $s2s === "grade"
                 || $r2s === "grades" || $r2s === "grade")
                && $this->can_see_grades($pset));
    }

    function user_linkpart(Contact $user = null, $is_anonymous = false) {
        $user = $user ? : $this;
        if ($this->isPC && ($user->is_anonymous || (!$user->isPC && $is_anonymous)))
            return $user->anon_username;
        else if ($this->isPC || get($_SESSION, "last_actas"))
            return $user->username ? : $user->email;
        else
            return null;
    }

    function user_idpart(Contact $user = null) {
        $user = $user ? $user : $this;
        if (!$this->isPC && !get($_SESSION, "last_actas"))
            return null;
        else
            return $user->github_username ? : ($user->seascode_username ? : $user->huid);
    }
}
