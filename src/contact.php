<?php
// contact.php -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Contact {

    // Information from the SQL definition
    public $contactId = 0;
    public $contactDbId = 0;
    private $cid;               // for forward compatibility
    var $firstName = "";
    var $lastName = "";
    var $email = "";
    var $preferredEmail = "";
    var $sorter = "";
    var $affiliation = "";
    var $password = "";
    public $password_type = 0;
    public $password_plaintext = "";
    public $passwordTime = 0;
    public $disabled = false;
    public $activity_at = false;
    var $note;

    public $seascode_username;
    public $anon_username;
    public $contactImageId;
    public $is_anonymous = false;

    private $links = null;
    private $repos = array();
    private $partners = array();

    // Roles
    const ROLE_PC = 1;
    const ROLE_ADMIN = 2;
    const ROLE_CHAIR = 4;
    const ROLE_PCLIKE = 15;
    var $roles = 0;
    var $isPC = false;
    var $privChair = false;
    var $contactTags = null;
    const CAP_AUTHORVIEW = 1;
    private $capabilities = null;
    private $activated_ = false;

    private static $_handout_repo = array();
    private static $_handout_repo_cacheid = array();


    public function __construct($trueuser = null) {
        if ($trueuser)
            $this->merge($trueuser);
        else if ($this->contactId)
            $this->db_load();
    }

    static public function make($o) {
        return new Contact($o);
    }

    private function merge($user) {
        global $Conf;
        if (!isset($user->dsn) || $user->dsn == $Conf->dsn) {
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
        $this->firstName = (string) @$name->firstName;
        $this->lastName = (string) @$name->lastName;
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
            $this->set_encoded_password($user->password);
        if (isset($user->disabled))
            $this->disabled = !!$user->disabled;
        foreach (array("defaultWatch", "passwordTime") as $k)
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
        if (isset($user->anon_username))
            $this->anon_username = $user->anon_username;
        if (isset($user->contactImageId))
            $this->contactImageId = (int) $user->contactImageId;
        if (isset($user->roles) || isset($user->isPC) || isset($user->isAssistant)
            || isset($user->isChair)) {
            $roles = (int) @$user->roles;
            if (@$user->isPC)
                $roles |= self::ROLE_PC;
            if (@$user->isAssistant)
                $roles |= self::ROLE_ADMIN;
            if (@$user->isChair)
                $roles |= self::ROLE_CHAIR;
            $this->assign_roles($roles);
        }
    }

    private function db_load() {
        $this->contactId = $this->cid = (int) $this->contactId;
        if (isset($this->contactDbId))
            $this->contactDbId = (int) $this->contactDbId;
        self::set_sorter($this);
        if (isset($this->password))
            $this->set_encoded_password($this->password);
        if (isset($this->disabled))
            $this->disabled = !!$this->disabled;
        foreach (array("defaultWatch", "passwordTime") as $k)
            if (isset($this->$k))
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
    }

    // begin changing contactId to cid
    public function __get($name) {
        if ($name == "cid")
            return $this->contactId;
        else
            return null;
    }

    public function __set($name, $value) {
        if ($name == "cid")
            $this->contactId = $this->cid = $value;
        else
            $this->$name = $value;
    }

    static public function set_sorter($o, $sorttype = null) {
        if ($o->is_anonymous)
            $o->sorter = $o->anon_username;
        else {
            $first = defval($o, "firstName", "");
            if (($p = strpos($first, " ")))
                $first = substr($first, 0, $p);
            $last = defval($o, "lastName", "");
            $email = defval($o, "email", "");
            $username = defval($o, "seascode_username", "") ? : $email;
            if ($sorttype === "last")
                $o->sorter = trim("$last $first $username $email");
            else
                $o->sorter = trim("$first $last $username $email");
        }
    }

    static public function compare($a, $b) {
        return strcasecmp($a->sorter, $b->sorter);
    }

    public function set_encoded_password($password) {
        if ($password === null || $password === false)
            $password = "";
        $this->password = $password;
        $this->password_type = substr($this->password, 0, 1) == " " ? 1 : 0;
        if ($this->password_type == 0)
            $this->password_plaintext = $password;
    }

    static public function site_contact() {
        global $Opt;
        if (!@$Opt["contactEmail"] || $Opt["contactEmail"] == "you@example.com") {
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

    static function external_login() {
        global $Opt;
        return @$Opt["ldapLogin"] || @$Opt["httpAuthLogin"];
    }


    // initialization

    function activate() {
        global $Conf, $Opt;
        $this->activated_ = true;
        $trueuser = @$_SESSION["trueuser"];

        // Handle actas requests
        if (isset($_REQUEST["actas"]) && $trueuser) {
            $actasemail = $_REQUEST["actas"];
            if ($actasemail === "admin")
                $actasemail = $trueuser->email;
            unset($_REQUEST["actas"]);
            if ($actasemail
                && strcasecmp($actasemail, $this->email) != 0
                && (strcasecmp($actasemail, $trueuser->email) == 0
                    || $this->privChair
                    || (($truecontact = self::find_by_email($trueuser->email))
                        && $truecontact->privChair))
                && ($actascontact = self::find_by_whatever($actasemail))) {
                $Conf->save_session("l", null);
                if ($actascontact->email !== $trueuser->email) {
                    hoturl_defaults(array("actas" => $actascontact->email));
                    $_SESSION["last_actas"] = $actascontact->email;
                }
                return $actascontact->activate();
            }
        }

        // Handle invalidate-caches requests
        if (@$_REQUEST["invalidatecaches"] && $this->privChair) {
            unset($_REQUEST["invalidatecaches"]);
            $Conf->invalidateCaches();
        }

        // If validatorContact is set, use it
        if ($this->contactId <= 0 && @$Opt["validatorContact"]
            && @$_REQUEST["validator"]) {
            unset($_REQUEST["validator"]);
            if (($newc = self::find_by_email($Opt["validatorContact"]))) {
                $this->activated_ = false;
                return $newc->activate();
            }
        }

        // Add capabilities from session and request
        if (!@$Opt["disableCapabilities"]) {
            if (($caps = $Conf->session("capabilities"))) {
                $this->capabilities = $caps;
                ++$this->rights_version_;
            }
            if (isset($_REQUEST["cap"]) || isset($_REQUEST["testcap"]))
                $this->activate_capabilities();
        }

        // Maybe set up the shared contacts database
        if (@$Opt["contactdb_dsn"] && $this->has_database_account()
            && $Conf->session("contactdb_roles", 0) != $this->all_roles()) {
            if ($this->contactdb_update())
                $Conf->save_session("contactdb_roles", $this->all_roles());
        }

        return $this;
    }

    public function activate_database_account() {
        assert(!$this->has_database_account() && $this->has_email());
        $contact = self::find_by_email($this->email, $_SESSION["trueuser"], false);
        return $contact ? $contact->activate() : $this;
    }

    static public function contactdb() {
        return null;
    }

    public function update_trueuser($always) {
        if (($trueuser = @$_SESSION["trueuser"])
            && strcasecmp($trueuser->email, $this->email) == 0) {
            foreach (array("firstName", "lastName", "affiliation") as $k)
                if ($this->$k && ($always || !@$trueuser->$k))
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
        return preg_match('/\Aanonymous\d*\z/', $email);
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
        return $this->privChair
            && ($fs = @$_REQUEST["forceShow"])
            && $fs != "0";
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
        if ($this->contactTags)
            return strpos($this->contactTags, " $t ") !== false;
        if ($this->contactTags === false) {
            trigger_error(caller_landmark(1, "/^Conference::/") . ": Contact $this->email contactTags missing");
            $this->contactTags = null;
        }
        return false;
    }

    static function roles_all_contact_tags($roles, $tags) {
        $t = "";
        if ($roles & self::ROLE_PC)
            $t = " pc";
        if ($tags)
            return $t . $tags;
        else
            return $t ? $t . " " : "";
    }

    function all_contact_tags() {
        return self::roles_all_contact_tags($this->roles, $this->contactTags);
    }

    function change_capability($pid, $c, $on) {
        global $Conf;
        if (!$this->capabilities)
            $this->capabilities = array();
        $oldval = @$cap[$pid] ? $cap[$pid] : 0;
        $newval = ($oldval | ($on ? $c : 0)) & ~($on ? 0 : $c);
        if ($newval != $oldval) {
            ++$this->rights_version_;
            if ($newval != 0)
                $this->capabilities[$pid] = $newval;
            else
                unset($this->capabilities[$pid]);
        }
        if (!count($this->capabilities))
            $this->capabilities = null;
        if ($this->activated_ && $newval != $oldval)
            $Conf->save_session("capabilities", $this->capabilities);
        return $newval != $oldval;
    }

    function apply_capability_text($text) {
        global $Conf;
        if (preg_match(',\A([-+]?)0([1-9][0-9]*)(a)(\S+)\z,', $text, $m)
            && ($result = $Conf->ql("select paperId, capVersion from Paper where paperId=$m[2]"))
            && ($row = edb_orow($result))) {
            $rowcap = $Conf->capability_text($row, $m[3]);
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
            return @$this->data_->$key;
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
        $this->make_data();
        $old = $this->encode_data();
        object_replace_recursive($this->data_, array_to_object_recursive($data));
        $new = $this->encode_data();
        if ($old !== $new)
            Dbl::qe("update ContactInfo set data=? where contactId=$this->contactId", $new);
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
        global $Conf;
        if (@$_REQUEST["ajax"]) {
            if ($this->is_empty())
                $Conf->ajaxExit(array("ok" => 0, "loggedout" => 1));
            else
                $Conf->ajaxExit(array("ok" => 0, "error" => "You don’t have permission to access that page."));
        }

        if ($this->is_empty()) {
            // Preserve post values across session expiration.
            $x = array();
            if (Navigation::path())
                $x["__PATH__"] = preg_replace(",^/+,", "", Navigation::path());
            if (@$_REQUEST["anchor"])
                $x["anchor"] = $_REQUEST["anchor"];
            $url = self_href($x, array("raw" => true, "site_relative" => true));
            $_SESSION["login_bounce"] = array($Conf->dsn, $url, Navigation::page(), $_POST);
            if (check_post())
                error_go(false, "You’ve been logged out due to inactivity, so your changes have not been saved. After logging in, you may submit them again.");
            else
                error_go(false, "You must sign in to access that page.");
        } else
            error_go(false, "You don’t have permission to access that page.");
    }

    function save() {
        global $Conf, $Now, $Opt;
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
        $result = Dbl::qe_apply($Conf->dblink, $q, $qv);
        if (!$result)
            return $result;
        if ($inserting)
            $this->contactId = $this->cid = $result->insert_id;

        // add to contact database
        if (@$Opt["contactdb_dsn"] && ($cdb = self::contactdb())) {
            Dbl::ql($cdb, "insert into ContactInfo set firstName=?, lastName=?, email=?, affiliation=? on duplicate key update firstName=values(firstName), lastName=values(lastName), affiliation=values(affiliation)",
                    $this->firstName, $this->lastName, $this->email, $this->affiliation);
            if ($this->password_plaintext
                && ($cdb_user = self::contactdb_find_by_email($this->email))
                && !$cdb_user->password
                && !$cdb_user->disable_shared_password
                && !@$Opt["contactdb_noPasswords"])
                $cdb_user->change_password($this->password_plaintext, true);
        }

        return $result;
    }

    private function load_links() {
        $result = Dbl::qe("select type, pset, link from ContactLink where cid=$this->contactId");
        $this->links = array();
        while (($row = edb_row($result)))
            @($this->links[(int) $row[0]][(int) $row[1]][] = (int)$row[2]);
    }

    function link($type, $pset = 0) {
        if ($this->links === null)
            $this->load_links();
        if (count(@$this->links[$type][$pset]) == 1)
            return $this->links[$type][$pset][0];
        else
            return null;
    }

    function links($type, $pset = 0) {
        if ($this->links === null)
            $this->load_links();
        $pset = is_object($pset) ? $pset->psetid : $pset;
        if (@isset($this->links[$type][$pset]))
            return $this->links[$type][$pset];
        else
            return array();
    }

    private function adjust_links($type, $pset) {
        if ($type == LINK_REPO)
            $this->repos = array();
        else if ($type == LINK_PARTNER)
            $this->partners = array();
    }

    function clear_links($type, $pset = 0, $nolog = false) {
        global $Conf;
        unset($this->links[$type][$pset]);
        $this->adjust_links($type, $pset);
        if ($Conf->qe("delete from ContactLink where cid=$this->contactId and type=$type and pset=$pset")) {
            if (!$nolog)
                $Conf->log("Clear links [$type,$pset]", $this);
            return true;
        } else
            return false;
    }

    function set_link($type, $pset, $value) {
        global $Conf;
        if ($this->links === null)
            $this->load_links();
        $this->clear_links($type, $pset, false);
        $this->links[$type][$pset] = array($value);
        if ($Conf->qe("insert into ContactLink (cid,type,pset,link) values ($this->contactId,$type,$pset,$value)")) {
            $Conf->log("Set links [$type,$pset,$value]", $this);
            return true;
        } else
            return false;
    }

    function add_link($type, $pset, $value) {
        global $Conf;
        if ($this->links === null)
            $this->load_links();
        if (!isset($this->links[$type][$pset]))
            $this->links[$type][$pset] = array();
        if (!in_array($value, $this->links[$type][$pset])) {
            $this->links[$type][$pset][] = array($value);
            if ($Conf->qe("insert into ContactLink (cid,type,pset,link) values ($this->contactId,$type,$pset,$value)")) {
                $Conf->log("Add link [$type,$pset,$value]", $this);
                return true;
            } else
                return false;
        }
        return true;
    }

    function repo($pset) {
        global $Conf, $Opt;
        $pset = is_object($pset) ? $pset->id : $pset;
        if (!array_key_exists($pset, $this->repos)) {
            $this->repos[$pset] = null;
            if (($link = $this->link(LINK_REPO, $pset))
                && ($result = Dbl::qe("select * from Repository where repoid=" . $link))
                && ($row = edb_orow($result)))
                $this->repos[$pset] = $row;
        }
        return $this->repos[$pset];
    }

    static function handout_repo($pset, $inrepo = null) {
        global $Conf, $Now, $ConfSitePATH;
        $url = $pset->handout_repo_url;
        $hrepo = @self::$_handout_repo[$url];
        if (!$hrepo) {
            $result = Dbl::qe("select * from Repository where url=?", $url);
            $hrepo = edb_orow($result);
            if (!$hrepo)
                $hrepo = self::add_repo($url, time(), 1);
            if ($hrepo)
                $hrepo->repo_is_handout = true;
            self::$_handout_repo[$url] = $hrepo;
        }
        if ($hrepo) {
            $hrepoid = $hrepo->repoid;
            $cacheid = $inrepo ? $inrepo->cacheid : $hrepo->cacheid;
            $hset = $Conf->setting_json("handoutrepos");
            $save = false;
            if (!$hset)
                $save = $hset = (object) array();
            if (!($hme = @$hset->$hrepoid))
                $save = $hme = $hset->$hrepoid = (object) array();
            if ((int) @$hme->$cacheid + 300 < $Now) {
                $save = $hme->$cacheid = $Now;
                shell_exec("$ConfSitePATH/src/gitfetch $hrepo->repoid $cacheid " . escapeshellarg($hrepo->url) . " 1>&2 &");
            }
            if ($save)
                $Conf->save_setting("handoutrepos", 1, $hset);
        }
        return $hrepo;
    }

    public static function handout_repo_recent_commits($pset) {
        global $Conf, $Now;
        if (!($hrepo = self::handout_repo($pset)))
            return null;
        $hrepoid = $hrepo->repoid;
        $key = "handoutcommits_{$hrepoid}" . ($pset ? "_" . $pset->id : "");
        $hset = $Conf->setting_json($key);
        if (!$hset)
            $hset = (object) array();
        if (@$hset->snaphash !== $hrepo->snaphash
            || (int) @$hset->snaphash_at + 300 < $Now
            || $hset->recent === null) {
            $xlist = array();
            foreach (self::repo_recent_commits($hrepo, $pset, 100) as $c)
                $xlist[] = array($c->hash, $c->commitat, $c->subject);
            $hset->snaphash = $hrepo->snaphash;
            $hset->snaphash_at = $Now;
            $hset->recent = $xlist;
            $Conf->save_setting($key, 1, $hset);
            Dbl::qe("delete from Settings where name!=? and name like 'handoutcommits_%_?s'",
                    $key, $pset->id);
        }
        $list = array();
        foreach ($hset->recent as $c)
            $list[$c[0]] = (object) array("commitat" => $c[1], "hash" => $c[0], "subject" => $c[2]);
        return $list;
    }

    static function handout_repo_latest_commit($pset) {
        $recent = self::handout_repo_recent_commits($pset);
        if ($recent) {
            $first = current($recent);
            return $first->hash;
        } else
            return false;
    }

    private static function update_repo_lastpset($pset, $repo) {
        global $Conf;
        $Conf->qe("update Repository set lastpset=(select coalesce(max(pset),0) from ContactLink l where l.type=" . LINK_REPO . " and l.link=$repo->repoid) where repoid=$repo->repoid");
    }

    function set_repo($pset, $repo) {
        global $Conf;
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
        global $Conf;
        $pset = is_object($pset) ? $pset->id : $pset;
        if (!array_key_exists($pset, $this->partners)) {
            $this->partners[$pset] = null;
            if (($pcid = $this->pcid($pset))
                && ($pc = self::find_by_id($pcid)))
                $this->partners[$pset] = $pc;
        }
        return $this->partners[$pset];
    }

    function email_authored_papers($email, $reg) {
        global $Conf;
        $aupapers = array();
        $result = $Conf->q("select paperId, authorInformation from Paper where authorInformation like '%\t" . sqlq_for_like($email) . "\t%'");
        while (($row = edb_orow($result))) {
            cleanAuthor($row);
            foreach ($row->authorTable as $au)
                if (strcasecmp($au[2], $email) == 0) {
                    $aupapers[] = $row->paperId;
                    if ($reg && !@$reg->firstName && $au[0])
                        $reg->firstName = $au[0];
                    if ($reg && !@$reg->lastName && $au[1])
                        $reg->lastName = $au[1];
                    if ($reg && !@$reg->affiliation && $au[3])
                        $reg->affiliation = $au[3];
                    break;
                }
        }
        return $aupapers;
    }

    function save_authored_papers($aupapers) {
        if (count($aupapers) && $this->contactId) {
            $q = array();
            foreach ($aupapers as $pid)
                $q[] = "($pid, $this->contactId, " . CONFLICT_AUTHOR . ")";
            Dbl::ql("insert into PaperConflict (paperId, contactId, conflictType) values " . join(", ", $q) . " on duplicate key update conflictType=greatest(conflictType, " . CONFLICT_AUTHOR . ")");
        }
    }

    function save_roles($new_roles, $actor) {
        global $Conf;
        $old_roles = $this->roles;
        // ensure there's at least one system administrator
        if (!($new_roles & self::ROLE_ADMIN) && ($old_roles & self::ROLE_ADMIN)
            && !(($result = Dbl::qe("select contactId from ContactInfo where (roles&" . self::ROLE_ADMIN . ")!=0 and contactId!=" . $this->contactId . " limit 1"))
                 && edb_nrows($result) > 0))
            $new_roles |= self::ROLE_ADMIN;
        // log role change
        $actor_email = ($actor ? " by $actor->email" : "");
        foreach (array(self::ROLE_PC => "pc",
                       self::ROLE_ADMIN => "sysadmin",
                       self::ROLE_CHAIR => "chair") as $role => $type)
            if (($new_roles & $role) && !($old_roles & $role))
                $Conf->log("Added as $type$actor_email", $this);
            else if (!($new_roles & $role) && ($old_roles & $role))
                $Conf->log("Removed as $type$actor_email", $this);
        // save the roles bits
        if ($old_roles != $new_roles) {
            Dbl::qe("update ContactInfo set roles=$new_roles where contactId=$this->contactId");
            $this->assign_roles($new_roles);
        }
        return $old_roles != $new_roles;
    }

    private function load_by_query($where) {
        $result = Dbl::q("select ContactInfo.* from ContactInfo where $where");
        if ($result && ($row = $result->fetch_object())) {
            $this->merge($row);
            return true;
        } else
            return false;
    }

    static function find_by_id($cid) {
        $result = Dbl::q("select ContactInfo.* from ContactInfo where contactId=" . (int) $cid);
        return $result ? $result->fetch_object("Contact") : null;
    }

    static function safe_registration($reg) {
        $safereg = array();
        foreach (array("firstName", "lastName", "name", "preferredEmail",
                       "affiliation", "collaborators", "seascode_username")
                 as $k)
            if (isset($reg[$k]))
                $safereg[$k] = $reg[$k];
        return $safereg;
    }

    private function register_by_email($email, $reg) {
        // For more complicated registrations, use UserStatus
        global $Conf, $Opt, $Now;
        $reg = (object) ($reg === true ? array() : $reg);
        $reg_keys = array("firstName", "lastName", "affiliation", "collaborators",
                          "voicePhoneNumber", "preferredEmail");

        // Set up registration
        $name = Text::analyze_name($reg);
        $reg->firstName = $name->firstName;
        $reg->lastName = $name->lastName;

        // Combine with information from contact database
        $cdb_user = null;
        if (@$Opt["contactdb_dsn"])
            $cdb_user = self::contactdb_find_by_email($email);
        if ($cdb_user)
            foreach ($reg_keys as $k)
                if (@$cdb_user->$k && !@$reg->$k)
                    $reg->$k = $cdb_user->$k;

        if (($password = @trim($reg->password)) !== "")
            $this->change_password($password, false);
        else if ($cdb_user && $cdb_user->password
                 && !$cdb_user->disable_shared_password)
            $this->set_encoded_password($cdb_user->password);
        else
            // Always store initial, randomly-generated user passwords in
            // plaintext. The first time a user logs in, we will encrypt
            // their password.
            //
            // Why? (1) There is no real security problem to storing random
            // values. (2) We get a better UI by storing the textual password.
            // Specifically, if someone tries to "create an account", then
            // they don't get the email, then they try to create the account
            // again, the password will be visible in both emails.
            $this->set_encoded_password(self::random_password());

        $best_email = @$reg->preferredEmail ? $reg->preferredEmail : $email;
        $authored_papers = Contact::email_authored_papers($best_email, $reg);

        // Insert
        $qf = array("email=?, password=?, creationTime=$Now");
        $qv = array($email, $this->password);
        foreach ($reg_keys as $k)
            if (isset($reg->$k)) {
                $qf[] = "$k=?";
                $qv[] = $reg->$k;
            }
        $result = Dbl::ql_apply("insert into ContactInfo set " . join(", ", $qf), $qv);
        if (!$result)
            return false;
        $cid = (int) $result->insert_id;
        if (!$cid)
            return false;

        // Having added, load it
        if (!$this->load_by_query("ContactInfo.contactId=$cid"))
            return false;

        // Success! Save newly authored papers
        if (count($authored_papers))
            $this->save_authored_papers($authored_papers);
        // Maybe add to contact db
        if (@$Opt["contactdb_dsn"] && !$cdb_user)
            $this->contactdb_update();

        return true;
    }

    static function find_by_email($email, $reg = false, $send = false) {
        global $Conf, $Me;

        // Lookup by email
        $email = trim($email ? $email : "");
        if ($email != "") {
            $result = Dbl::q("select ContactInfo.* from ContactInfo where email=?", $email);
            if (($acct = $result ? $result->fetch_object("Contact") : null))
                return $acct;
        }

        // Not found: register
        if (!$reg || !validate_email($email))
            return null;
        $acct = new Contact;
        $ok = $acct->register_by_email($email, $reg);

        // Log
        if ($ok)
            $acct->mark_create($send, true);
        else
            $Conf->log("Account $email creation failure", $Me);

        return $ok ? $acct : null;
    }

    static function query_by_whatever($whatever) {
        $whatever = trim($whatever);
        if (preg_match('/\A\d{8}\z/', $whatever))
            return "ContactInfo.seascode_username='$whatever' or ContactInfo.huid='$whatever' "
                . "order by ContactInfo.seascode_username='$whatever' desc limit 1";
        else if (preg_match('/\A\[anon\w+\]\z/', $whatever))
            return "ContactInfo.anon_username='$whatever'";
        else if (strpos($whatever, "@") === false)
            return "ContactInfo.seascode_username='" . sqlq($whatever)
                . "' or (coalesce(ContactInfo.seascode_username,'')='' and "
                . "email like '" . sqlq_for_like(sqlq($whatever)) . "') "
                . "order by ContactInfo.seascode_username='" . sqlq($whatever) . "' desc "
                . "limit 1";
        else {
            if (preg_match('_.*@(?:fas|college|seas)\z_', $whatever))
                $whatever .= ".harvard.edu";
            else if (preg_match('_.*@.*?\.harvard\z_', $whatever))
                $whatever .= ".edu";
            return "ContactInfo.email='" . sqlq($whatever) . "'";
        }
    }

    function mark_create($send_email, $message_chair) {
        global $Conf, $Me;
        if ($Me && $Me->privChair && $message_chair)
            $Conf->infoMsg("Created account for <a href=\"" . hoturl("profile", "u=" . urlencode($this->email)) . "\">" . Text::user_html_nolink($this) . "</a>.");
        if ($send_email)
            $this->sendAccountInfo("create", false);
        if ($Me && $Me->has_email() && $Me->email !== $this->email)
            $Conf->log("Created account ($Me->email)", $this);
        else
            $Conf->log("Created account", $this);
    }

    static function find_by_query($q, $args = array()) {
        if ($q != "") {
            $result = Dbl::q_apply("select ContactInfo.* from ContactInfo where $q", $args);
            if (($acct = $result ? $result->fetch_object("Contact") : null)
                && $acct->contactId)
                return $acct;
        }
        return null;
    }

    static function find_by_whatever($whatever) {
        return self::find_by_query(self::query_by_whatever($whatever));
    }

    static function find_by_username($x) {
        $x = trim($x);
        if ($x != "")
            return self::find_by_query("seascode_username='" . sqlq($x) . "'");
        else
            return null;
    }

    static function id_by_query($q) {
        global $Conf;
        $result = $Conf->q("select c.contactId from ContactInfo c where $q");
        $row = edb_row($result);
        return $row ? $row[0] : null;
    }

    static function id_by_whatever($whatever) {
        return self::id_by_query(self::query_by_whatever($whatever));
    }

    static function id_by_email($email) {
        $result = Dbl::qe("select contactId from ContactInfo where email=?", trim($email));
        $row = edb_row($result);
        return $row ? $row[0] : false;
    }

    static function email_by_id($id) {
        $result = Dbl::qe("select email from ContactInfo where contactId=" . (int) $id);
        $row = edb_row($result);
        return $row ? $row[0] : false;
    }


    public static function password_hmac_key($keyid) {
        global $Conf, $Opt;
        if ($keyid === null)
            $keyid = defval($Opt, "passwordHmacKeyid", 0);
        $key = @$Opt["passwordHmacKey.$keyid"];
        if (!$key && $keyid == 0)
            $key = @$Opt["passwordHmacKey"];
        if (!$key) /* backwards compatibility */
            $key = $Conf->setting_data("passwordHmacKey.$keyid");
        if (!$key) {
            error_log("missing passwordHmacKey.$keyid, using default");
            $key = "NdHHynw6JwtfSZyG3NYPTSpgPFG8UN8NeXp4tduTk2JhnSVy";
        }
        return $key;
    }

    public static function valid_password($password) {
        return $password != "" && trim($password) === $password
            && $password !== "*";
    }

    public function check_password($password) {
        global $Conf, $Opt;
        assert(!isset($Opt["ldapLogin"]) && !isset($Opt["httpAuthLogin"]));
        if ($password == "" || $password === "*")
            return false;
        if ($this->password_type == 0)
            return $password === $this->password;
        if ($this->password_type == 1
            && ($hash_method_pos = strpos($this->password, " ", 1)) !== false
            && ($keyid_pos = strpos($this->password, " ", $hash_method_pos + 1)) !== false
            && strlen($this->password) > $keyid_pos + 17
            && function_exists("hash_hmac")) {
            $hash_method = substr($this->password, 1, $hash_method_pos - 1);
            $keyid = substr($this->password, $hash_method_pos + 1, $keyid_pos - $hash_method_pos - 1);
            $salt = substr($this->password, $keyid_pos + 1, 16);
            return hash_hmac($hash_method, $salt . $password,
                             self::password_hmac_key($keyid), true)
                == substr($this->password, $keyid_pos + 17);
        } else if ($this->password_type == 1)
            error_log("cannot check hashed password for user " . $this->email);
        return false;
    }

    static public function password_hash_method() {
        global $Opt;
        if (isset($Opt["passwordHashMethod"]) && $Opt["passwordHashMethod"])
            return $Opt["passwordHashMethod"];
        else
            return PHP_INT_SIZE == 8 ? "sha512" : "sha256";
    }

    static public function password_cleartext() {
        global $Opt;
        return $Opt["safePasswords"] < 1;
    }

    private function preferred_password_keyid() {
        global $Opt;
        if ($this->contactDbId)
            return defval($Opt, "contactdb_passwordHmacKeyid", 0);
        else
            return defval($Opt, "passwordHmacKeyid", 0);
    }

    public function check_password_encryption($is_change) {
        global $Opt;
        if ($Opt["safePasswords"] < 1
            || ($Opt["safePasswords"] == 1 && !$is_change)
            || !function_exists("hash_hmac"))
            return false;
        if ($this->password_type == 0)
            return true;
        $expected_prefix = " " . self::password_hash_method() . " "
            . $this->preferred_password_keyid() . " ";
        return $this->password_type == 1
            && !str_starts_with($this->password, $expected_prefix . " ");
    }

    public function change_password($new_password, $save) {
        global $Conf, $Opt, $Now;
        // set password fields
        $this->password_type = 0;
        if ($new_password && $this->check_password_encryption(true))
            $this->password_type = 1;
        if (!$new_password)
            $new_password = self::random_password();
        $this->password_plaintext = $new_password;
        if ($this->password_type == 1) {
            $keyid = $this->preferred_password_keyid();
            $key = self::password_hmac_key($keyid);
            $hash_method = self::password_hash_method();
            $salt = hotcrp_random_bytes(16);
            $this->password = " " . $hash_method . " " . $keyid . " " . $salt
                . hash_hmac($hash_method, $salt . $new_password, $key, true);
        } else
            $this->password = $new_password;
        $this->passwordTime = $Now;
        // save possibly-encrypted password
        if ($save && $this->contactId)
            Dbl::ql($Conf->dblink, "update ContactInfo set password=?, passwordTime=? where contactId=?", $this->password, $this->passwordTime, $this->contactId);
        if ($save && $this->contactDbId)
            Dbl::ql(self::contactdb(), "update ContactInfo set password=?, passwordTime=? where contactDbId=?", $this->password, $this->passwordTime, $this->contactDbId);
    }

    static function random_password($length = 14) {
        global $Opt;
        if (isset($Opt["ldapLogin"]))
            return "<stored in LDAP>";
        else if (isset($Opt["httpAuthLogin"]))
            return "<using HTTP authentication>";

        // see also regexp in randompassword.php
        $l = explode(" ", "a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w tr cr br fr th dr ch ph wr st sp sw pr sl cl 2 3 4 5 6 7 8 9 - @ _ + =");
        $n = count($l);

        $bytes = hotcrp_random_bytes($length + 10, true);
        if ($bytes === false) {
            $bytes = "";
            while (strlen($bytes) < $length)
                $bytes .= sha1($Opt["conferenceKey"] . pack("V", mt_rand()));
        }

        $pw = "";
        $nvow = 0;
        for ($i = 0;
             $i < strlen($bytes) &&
                 strlen($pw) < $length + max(0, ($nvow - 3) / 3);
             ++$i) {
            $x = ord($bytes[$i]) % $n;
            if ($x < 30)
                ++$nvow;
            $pw .= $l[$x];
        }
        return $pw;
    }

    function sendAccountInfo($sendtype, $sensitive) {
        global $Conf, $Opt;
        $rest = array();
        if ($sendtype == "create")
            $template = "@createaccount";
        else if ($this->password_type == 0
                 && (!@$Opt["safePasswords"]
                     || (is_int($Opt["safePasswords"]) && $Opt["safePasswords"] <= 1)
                     || $sendtype != "forgot")
                 && $this->password !== "*")
            $template = "@accountinfo";
        else {
            $rest["capability"] = $Conf->create_capability(CAPTYPE_RESETPASSWORD, array("contactId" => $this->contactId, "timeExpires" => time() + 259200));
            $Conf->log("Created password reset request", $this);
            $template = "@resetpassword";
        }

        $mailer = new CS61Mailer($this, null, $rest);
        $prep = $mailer->make_preparation($template, $rest);
        if ($prep->sendable || !$sensitive
            || @$Opt["debugShowSensitiveEmail"]) {
            Mailer::send_preparation($prep);
            return $template;
        } else {
            $Conf->errorMsg("Mail cannot be sent to " . htmlspecialchars($this->email) . " at this time.");
            return false;
        }
    }


    function mark_activity() {
    }

    function log_activity($text, $paperId = null) {
        global $Conf;
        $this->mark_activity();
        if (!$this->is_anonymous_user())
            $Conf->log($text, $this, $paperId);
    }

    function log_activity_for($user, $text, $paperId = null) {
        global $Conf;
        $this->mark_activity();
        if (!$this->is_anonymous_user())
            $Conf->log($text . " by $this->email", $user, $paperId);
    }




    // code.seas integration
    const SEASCODE_URL = "https://code.seas.harvard.edu/";

    static private function _seascode_finish($url, $text) {
        return $text ? "<a href=\"$url\">$text</a>" : $url;
    }

    static function seascode_home($text = null) {
        return self::_seascode_finish(self::SEASCODE_URL, $text);
    }

    static function seascode_user($user, $text = null) {
        $user = is_object($user) ? $user->seascode_username : $user;
        return self::_seascode_finish(self::SEASCODE_URL . "~" . htmlspecialchars($user), $text);
    }

    static function repo_https_url($repo) {
        if (preg_match('_\A(?:(?:https?|git|ssh)://code\.seas\.harvard\.edu/|(?:git@)?code\.seas\.harvard\.edu:|)/*(.*?)(?:\.git|)\z_', $repo, $m))
            return self::SEASCODE_URL . $m[1];
        else
            return $repo;
    }

    static function seascode_repo_base($repo) {
        if (is_object($repo))
            $repo = $repo->url;
        if (preg_match('_\A(?:(?:https?|git|ssh)://code\.seas\.harvard\.edu/|(?:git@)?code\.seas\.harvard\.edu:|)/*(.*?)(?:\.git|)\z_', $repo, $m))
            $repo = $m[1];
        return $repo;
    }

    function repo_messagedefs($repo) {
        if (!is_string($repo))
            $repo = $repo ? self::seascode_repo_base($repo->url) : "";
        if ($this->is_anonymous && $repo)
            $repo = "[anonymous]";
        if ($repo)
            return array("REPOGITURL" => "git@code.seas.harvard.edu:$repo.git", "REPOBASE" => $repo);
        else
            return array("REPOGITURL" => null, "REPOBASE" => null);
    }

    function repo_link($repo, $text, $suffix = "") {
        $repo = self::SEASCODE_URL . self::seascode_repo_base($repo) . $suffix;
        if ($this->is_anonymous)
            return '<a href="#" onclick=\'window.location=' . htmlspecialchars(json_encode($repo)) . ';return false\'>' . $text . '</a>';
        else
            return '<a href="' . htmlspecialchars($repo) . '">' . $text . '</a>';
    }

    static function seascode_repo_memberships($repo, $text = null) {
        $repo = self::seascode_repo_base($repo);
        return self::_seascode_finish(self::SEASCODE_URL . htmlspecialchars($repo) . "/memberships", $text);
    }

    function check_seascode_username($username, $error = true) {
        global $Conf;
        $code_seas = self::seascode_home("code.seas");

        // does it contain odd characters?
        if (preg_match('_[@,;:~/\[\](){}\\<>&#=\\000-\\027]_', $username))
            return $error && $Conf->errorMsg("The $code_seas username “" . htmlspecialchars($username) . "” contains funny characters. Remove them.");

        // is it in use?
        $result = $Conf->qe("select contactId from ContactInfo where seascode_username='" . sqlq($username) . "'");
        if (($row = edb_row($result)) && $row[0] != $this->contactId)
            return $error && $Conf->errorMsg("That $code_seas username is already in use.");

        // is it valid?
        $htopt = array("timeout" => 5, "ignore_errors" => true);
        $context = stream_context_create(array("http" => $htopt));
        $response_code = 509;
        if (($stream = fopen(self::seascode_user($username), "r", false, $context))) {
            if (($metadata = stream_get_meta_data($stream))
                && ($w = @$metadata["wrapper_data"])
                && is_array($w)
                && preg_match(',\AHTTP/[\d.]+\s+(\d+)\s+(.+)\z,', $w[0], $m))
                $response_code = (int) $m[1];
            fclose($stream);
        }

        if ($response_code == 200)
            return true;
        else if ($response_code == 404)
            return $error && $Conf->errorMsg("That username doesn’t appear to have a $code_seas account. Check your spelling.");
        else
            return $error && $Conf->errorMsg("Error contacting $code_seas at " . htmlspecialchars(self::seascode_user($username)) . " (response code $response_code). Maybe try again?");
    }

    function set_seascode_username($username) {
        global $Conf;

        // does it contain odd characters?
        if (!$this->check_seascode_username($username, true))
            return false;

        $this->seascode_username = $username;
        if ($Conf->qe("update ContactInfo set seascode_username='" . sqlq($username) . "' where contactId=" . $this->contactId))
            $Conf->log("Set seascode_username to $username", $this);
        return true;
    }

    function check_seascode_repo($pset, $repo, $error) {
        global $Conf, $ConfSitePATH;
        $code_seas = "<a href='https://code.seas.harvard.edu/'>code.seas</a>";

        // get rid of git nonsense
        $repo = self::seascode_repo_base($repo);

        // does it contain odd characters?
        if (preg_match('_[@,;:\[\](){}\\<>&#=\\000-\\027]_', $repo))
            return $error && $Conf->errorMsg("That $code_seas repository contains funny characters. Remove them.");

        // clean it up some
        if (!is_object($pset)) {
            if (!Pset::$all[$pset])
                error_log("bad pset $pset: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
            $pset = Pset::$all[$pset];
        }
        if ($pset && $pset->repo_guess_patterns)
            for ($i = 0; $i < count($pset->repo_guess_patterns); $i += 2) {
                $x = preg_replace('`' . str_replace("`", "\\`", $pset->repo_guess_patterns[$i]) . '`s',
                                  $pset->repo_guess_patterns[$i + 1],
                                  $repo, -1, $nreplace);
                if ($x !== null && $nreplace) {
                    $repo = $x;
                    break;
                }
            }
        if (preg_match(':\A(.*?)\.git\z:', $repo, $m))
            $repo = $m[1];
        if (!$repo)
            return false;

        // check ssh url; should work
        $ssh_url = "git@code.seas.harvard.edu:$repo.git";
        $answer = shell_exec("git ls-remote $ssh_url 2>&1");
        //$Conf->errorMsg("<pre>" . htmlspecialchars($answer) . "</pre>");
        if (!preg_match('/^[a-f0-9]{40}\s+/m', $answer))
            return $error && $Conf->errorMsg(Messages::$main->expand_html("repo_unreadable", $this->repo_messagedefs($repo)));
        if (!preg_match(',^[a-f0-9]{40}\s+refs/heads/master,m', $answer))
            return $error && $Conf->errorMsg(Messages::$main->expand_html("repo_nomaster", $this->repo_messagedefs($repo)));

        return $repo;
    }

    function can_set_repo($pset, $user = null) {
        global $Now;
        if (is_string($pset) || is_int($pset))
            $pset = @Pset::$all[$pset];
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

    static function add_repo($url, $now, $open) {
        global $Conf;
        $repo_hash = substr(sha1($url), 10, 1);
        $result = $Conf->qe("insert into Repository (url,cacheid,working,open,opencheckat) values ('" . sqlq($url) . "','$repo_hash',$now,$open,$now)");
        if (!$result)
            return false;
        $repoid = $Conf->dblink->insert_id;
        $result = $Conf->qe("select * from Repository where repoid=$repoid");
        return edb_orow($result);
    }

    function set_seascode_repo($pset, $repo, $error = true) {
        global $Conf, $ConfSitePATH;
        $code_seas = "<a href='http://code.seas.harvard.edu/'>code.seas</a>";

        // is it valid?
        if (!($repo = $this->check_seascode_repo($pset, $repo, $error)))
            return false;

        // is it the current repo?
        $ssh_url = "git@code.seas.harvard.edu:$repo.git";
        $result = $Conf->qe("select * from Repository where url='" . sqlq($ssh_url) . "'");
        $reporow = edb_orow($result);

        // check git url; should not work b/c student repos should require auth
        $open = PsetView::is_repo_url_open($repo);

        // set repo
        $now = time();
        if ($reporow) {
            $Conf->qe("update Repository set `open`=$open, opencheckat=$now, working=$now where repoid=$reporow->repoid");
            $reporow->open = $open;
            $reporow->opencheckat = $now;
            $reporow->working = $now;
        } else if (!($reporow = self::add_repo($ssh_url, $now, $open)))
            return false;
        return $this->set_repo($pset, $reporow);
    }

    function set_partner($pset, $partner) {
        global $Conf, $ConfSitePATH;
        $pset = is_object($pset) ? $pset->psetid : $pset;
        $code_seas = "<a href='http://code.seas.harvard.edu/'>code.seas</a>";

        // does it contain odd characters?
        $partner = trim($partner);
        $pc = Contact::find_by_whatever($partner);
        if (!$pc && ($partner == "" || strcasecmp($partner, "none") == 0))
            $pc = $this;
        else if (!$pc || !$pc->contactId)
            return $Conf->errorMsg("I can’t find someone with email/username " . htmlspecialchars($partner) . ". Check your spelling.");

        foreach ($this->links(LINK_PARTNER, $pset) as $link)
            $Conf->qe("delete from ContactLink where cid=$link and type=" . LINK_BACKPARTNER . " and pset=$pset and link=$this->contactId");
        if ($pc->contactId == $this->contactId)
            return $this->clear_links(LINK_PARTNER, $pset);
        else
            return $this->set_link(LINK_PARTNER, $pset, $pc->contactId)
                && $Conf->qe("insert into ContactLink (cid,type,pset,link) values ($pc->contactId," . LINK_BACKPARTNER . ",$pset,$this->contactId)");
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
            shell_exec("$ConfSitePATH/src/gitfetch $repo->repoid $repo->cacheid " . escapeshellarg($repo->url) . " 1>&2" . ($foreground ? "" : " &"));
        }
    }

    static function repo_gitrun($repo, $command) {
        global $Me, $ConfSitePATH;
        if (!isset($repo->repoid) || !isset($repo->cacheid))
            error_log(json_encode($repo) . " / " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) . " user $Me->email");
        assert(isset($repo->repoid) && isset($repo->cacheid));
        $command = str_replace("REPO", "repo" . $repo->repoid, $command);
        return shell_exec("cd $ConfSitePATH/repo/repo" . $repo->cacheid . " && $command");
    }

    static function repo_ls_files($repo, $tree, $files = array()) {
        $suffix = "";
        if (is_string($files))
            $files = array($files);
        foreach ($files as $f)
            $suffix .= " " . escapeshellarg(preg_replace(',/+\z,', '', $f));
        $result = self::repo_gitrun($repo, "git ls-tree -r --name-only $tree" . $suffix);
        $x = explode("\n", $result);
        if (count($x) && $x[count($x) - 1] == "")
            array_pop($x);
        return $x;
    }

    static function repo_author_emails($repo, $pset = null, $limit = null) {
        if (is_object($pset) && $pset->directory_noslash !== "")
            $dir = " -- " . escapeshellarg($pset->directory_noslash);
        else if (is_string($pset) && $pset !== "")
            $dir = " -- " . escapeshellarg($pset);
        else
            $dir = "";
        $limit = $limit ? " -n$limit" : "";
        $users = array();
        $heads = explode(" ", $repo->heads);
        $heads[0] = "REPO/master";
        foreach ($heads as $h) {
            $result = self::repo_gitrun($repo, "git log$limit --simplify-merges --format=%ae $h$dir");
            foreach (explode("\n", $result) as $line)
                if ($line !== "")
                    $users[strtolower($line)] = $line;
        }
        return $users;
    }

    static function repo_recent_commits($repo, $pset = null, $limit = null) {
        if (is_object($pset) && $pset->directory_noslash !== "")
            $dir = " -- " . escapeshellarg($pset->directory_noslash);
        else if (is_string($pset) && $pset !== "")
            $dir = " -- " . escapeshellarg($pset);
        else
            $dir = "";
        $limit = $limit ? " -n$limit" : "";
        $list = array();
        $heads = explode(" ", $repo->heads);
        $heads[0] = "REPO/master";
        foreach ($heads as $h) {
            $result = self::repo_gitrun($repo, "git log$limit --simplify-merges --format='%ct %H %s' $h$dir");
            foreach (explode("\n", $result) as $line)
                if (preg_match(',\A(\S+)\s+(\S+)\s+(.*)\z,', $line, $m)
                    && !isset($list[$m[2]]))
                    $list[$m[2]] = (object) array("commitat" => (int) $m[1],
                                                  "hash" => $m[2],
                                                  "subject" => $m[3],
                                                  "fromhead" => $h);
        }
        return $list;
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

    private static function file_ignore_regex($pset, $repo) {
        global $Conf, $Now;
        if ($pset && @$pset->file_ignore_regex)
            return $pset->file_ignore_regex;
        $regex = '.*\.swp|.*~|#.*#|.*\.core|.*\.dSYM|.*\.o|core.*\z|.*\.backup|tags|tags\..*|typescript';
        if ($pset && $Conf->setting("__gitignore_pset{$pset->id}_at", 0) < $Now - 900) {
            $hrepo = self::handout_repo($pset, $repo);
            if ($pset->directory_slash !== "")
                $result = self::repo_gitrun($repo, "git show repo{$hrepo->repoid}/master:" . escapeshellarg($pset->directory_slash) . ".gitignore 2>/dev/null");
            $result .= self::repo_gitrun($repo, "git show repo{$hrepo->repoid}/master:.gitignore 2>/dev/null");
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
        $commit = trim(self::repo_gitrun($repo, "git log --format=%H -n1 $base"));
        $check_tag = trim(self::repo_gitrun($repo, "test -f .git/refs/heads/truncated_$commit && echo yes"));
        if ($check_tag == "yes")
            return "truncated_$commit";

        $pset_files = self::repo_ls_files($repo, $commit, $pset->directory_noslash);
        foreach ($pset_files as &$f)
            $f = substr($f, strlen($pset->directory_slash));
        unset($f);

        if (!($trepo = self::_temp_repo_clone($repo)))
            return false;
        foreach ($pset_files as $f)
            self::repo_gitrun($repo, "mkdir -p \"`dirname $trepo/$f`\" && git show $commit:$pset->directory_slash$f > $trepo/$f");

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
        if ($pset && !@$pset->__applied_file_ignore_regex) {
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

    static function repo_diff($repo, $hash, $pset, $options = null) {
        global $Conf, $Now;
        $options = $options ? : array();
        $diff_files = array();
        assert($pset); // code remains for `!$pset`; maybe revive it?

        $psetdir = $pset ? escapeshellarg($pset->directory_noslash) : null;
        if (isset($repo->truncated_psetdir) && $pset
            && defval($repo->truncated_psetdir, $pset->id)) {
            $repodir = "";
            $truncpfx = $pset->directory_noslash . "/";
        } else {
            $repodir = $psetdir . "/"; // Some gits don't do `git show HASH:./FILE`!
            $truncpfx = "";
        }

        if (@$options["basehash"])
            $base = $options["basehash"];
        else {
            $hrepo = self::handout_repo($pset, $repo);
            $base = "repo{$hrepo->repoid}/master";
            if ($pset && isset($pset->gradebranch))
                $base = "repo{$hrepo->repoid}/" . $pset->gradebranch;
            $options["basehash_hrepo"] = true;
        }
        if ($truncpfx && @$options["basehash_hrepo"])
            $base = self::_repo_prepare_truncated_handout($repo, $base, $pset);

        // read "full" files
        $pset_diffs = self::pset_diffinfo($pset, $repo);
        foreach ($pset_diffs as $diffinfo)
            if ($diffinfo->full && ($fname = self::unquote_filename_regex($diffinfo->regex)) !== false) {
                $result = self::repo_gitrun($repo, "git show $hash:${repodir}$fname");
                $fdiff = array();
                foreach (explode("\n", $result) as $idx => $line)
                    $fdiff[] = array("+", 0, $idx + 1, $line);
                self::save_repo_diff($diff_files, "{$pset->directory_slash}$fname", $fdiff, $diffinfo, count($fdiff) ? 1 : 0);
            }

        $command = "git diff --name-only $base $hash";
        if ($pset && !$truncpfx)
            $command .= " -- " . escapeshellarg($pset->directory_noslash);
        $result = self::repo_gitrun($repo, $command);

        $files = array();
        foreach (explode("\n", $result) as $line)
            if ($line != "") {
                $diffinfo = self::find_diffinfo($pset_diffs, $truncpfx . $line);
                if (!$diffinfo || (!@$diffinfo->ignore && !@$diffinfo->full))
                    $files[] = escapeshellarg(quotemeta($line));
            }

        if (count($files)) {
            $command = "git diff";
            if (@$options["wdiff"])
                $command .= " -w";
            $command .= " $base $hash -- " . join(" ", $files);
            $result = self::repo_gitrun($repo, $command);
            $file = null;
            $alineno = $blineno = null;
            $fdiff = null;
            $lines = explode("\n", $result);
            while (($line = current($lines)) !== null && $line !== false) {
                next($lines);
                if (count($fdiff) > DiffInfo::MAXLINES) {
                    while ($line && ($line[0] == " " || $line[0] == "+" || $line[0] == "-"))
                        $line = next($lines);
                }
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

    static function repo_grade($repo, $pset) {
        global $Conf;
        $pset = is_object($pset) ? $pset->psetid : $pset;
        $result = $Conf->qe("select rg.*, cn.notes
                from RepositoryGrade rg
                left join CommitNotes cn on (cn.hash=rg.gradehash and cn.pset=rg.pset)
                where rg.repoid=" . $repo->repoid . " and rg.pset='" . sqlq($pset) . "' and not rg.placeholder");
        if (($rg = edb_orow($result)) && $rg->notes)
            $rg->notes = json_decode($rg->notes);
        return $rg;
    }

    function can_view_repo_contents($repo, $cache_only = false) {
        global $Opt;
        if (!@$Opt["restrictRepoView"]
            || $this->isPC
            || @$repo->repo_is_handout)
            return true;
        if (!isset($repo->repo_viewable_by))
            $repo->repo_viewable_by = array();
        assert(is_array($repo->repo_viewable_by));
        $allowed = @$repo->repo_viewable_by[$this->contactId];
        if ($allowed === null) {
            $allowed = in_array($this->contactId, $this->links(LINK_REPOVIEW));
            if (!$allowed && !$cache_only) {
                $users = self::repo_author_emails($repo);
                $allowed = isset($users[strtolower($this->email)]);
                if ($allowed)
                    $this->add_link(LINK_REPOVIEW, 0, $repo->repoid);
            }
            if ($allowed || !$cache_only)
                $repo->repo_viewable_by[$this->contactId] = $allowed;
        }
        return $allowed;
    }

    static function commit_info($commit, $pset) {
        global $Conf;
        $pset = is_object($pset) ? $pset->psetid : $pset;
        $psetlim = $pset <= 0 ? " limit 1" : " and pset=$pset";
        if (($result = $Conf->qe("select notes from CommitNotes where hash='" . sqlq($commit) . "'$psetlim"))
            && ($row = edb_row($result)))
            return json_decode($row[0]);
        else
            return null;
    }

    static function commit_info_haslinenotes($info) {
        $x = 0;
        if ($info && isset($info->linenotes))
            foreach ($info->linenotes as $fn => $fnn) {
                foreach ($fnn as $ln => $n)
                    $x |= (is_array($n) && $n[0] ? HASNOTES_COMMENT : HASNOTES_GRADE);
            }
        return $x;
    }

    static function save_commit_info($commit, $repo, $pset, $info) {
        global $Conf;
        $pset = is_object($pset) ? $pset->psetid : $pset;
        $repo = is_object($repo) ? $repo->repoid : $repo;
        if ($info) {
            // if grade == autograde, do not save grade separately
            if (is_object($info)
                && isset($info->grades) && is_object($info->grades)
                && isset($info->autogrades) && is_object($info->autogrades)) {
                foreach ($info->autogrades as $k => $v) {
                    if (@$info->grades->$k === $v)
                        unset($info->grades->$k);
                }
                if (!count(get_object_vars($info->grades)))
                    unset($info->grades);
            }

            $Conf->qe("insert into CommitNotes (hash, pset, notes, haslinenotes, repoid)
                values ('" . sqlq($commit) . "', $pset, '" . sqlq(json_encode($info))
                . "'," . self::commit_info_haslinenotes($info) . ","
                . ($repo ? $repo : 0) . ")
                on duplicate key update notes=values(notes), haslinenotes=values(haslinenotes)");
        } else
            $Conf->qe("delete from CommitNotes where hash='" . sqlq($commit) . "' and pset=$pset");
    }

    static function update_commit_info($commit, $repo, $pset,
                                       $updates, $reset_keys = false) {
        global $Conf;
        $Conf->qe("lock tables CommitNotes write");
        $cinfo = self::commit_info($commit, $pset);
        if ($reset_keys)
            foreach ((array) $updates as $k => $v)
                unset($cinfo->$k);
        $cinfo = json_update($cinfo, $updates);
        self::save_commit_info($commit, $repo, $pset, $cinfo);
        $Conf->qe("unlock tables");
        return $cinfo;
    }

    static function contact_grade_for($cid, $pset) {
        global $Conf;
        $cid = is_object($cid) ? $cid->contactId : $cid;
        $pset = is_object($pset) ? $pset->psetid : $pset;
        $result = $Conf->qe("select * from ContactGrade
                where cid='" . sqlq($cid) . "' and pset='" . sqlq($pset) . "'");
        $cg = edb_orow($result);
        if ($cg && $cg->notes)
            $cg->notes = json_decode($cg->notes);
        return $cg;
    }

    function contact_grade($pset) {
        return self::contact_grade_for($this, $pset);
    }

    function save_contact_grade($pset, $info) {
        global $Conf;
        $pset = is_object($pset) ? $pset->psetid : $pset;
        $Conf->qe("insert into ContactGrade (cid, pset, notes)
                values ($this->contactId, $pset, "
                  . ($info ? "'" . sqlq(json_encode($info)) . "'" : "NULL")
                  . ")
                on duplicate key update notes=values(notes)");
    }

    function update_contact_grade($pset, $updates) {
        global $Conf;
        $Conf->qe("lock tables ContactGrade write");
        $cg = $this->contact_grade($pset);
        $info = json_update($cg ? $cg->notes : null, $updates);
        self::save_contact_grade($pset, $info);
        $Conf->qe("unlock tables");
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
            $Conf->qe("insert into ContactLink (cid,type,pset,link) select l.cid, l.type, $pset, l.link from ContactLink l left join ContactLink l2 on (l2.cid=l.cid and l2.type=l.type and l2.pset=$pset) where l.pset=" . $forwarded . " and l2.cid is null group by l.cid, l.type, l.link");
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

    static function student_can_see_grades($pset, $extension = null) {
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

    static function student_can_see_grade_cdf($pset) {
        return self::student_can_see_pset($pset)
            && (isset($pset->grade_cdf_visible)
                ? self::show_setting_on($pset->grade_cdf_visible)
                : self::student_can_see_grades($pset));
    }

    function can_see_grader($pset, $user) {
        return ($this->isPC && (!$user || $user != $this))
            || $this->privChair;
    }

    function can_set_grader($pset, $user) {
        return ($this->isPC && (!$user || $user != $this))
            || $this->privChair;
    }

    function can_see_grades($pset, $user = null, $info = null) {
        return (!$pset || !$pset->disabled)
            && (($this->isPC && $user && $user != $this)
                || $this->privChair
                || ($pset && self::student_can_see_grades($pset, $this->extension)
                    && (!$info || !$info->grades_hidden())));
    }

    function can_run($pset, $runner, $user = null) {
        if (!$runner || $runner->disabled)
            return false;
        if ($this->isPC && (!$user || $user != $this))
            return true;
        $s2s = $runner->visible;
        return $s2s === true
            || (($s2s === "grades" || $s2s === "grade")
                && $this->can_see_grades($pset));
    }

    function can_view_run($pset, $runner, $user = null) {
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

    function user_linkpart($user = null, $is_anonymous = false) {
        $user = $user ? : $this;
        if ($this->isPC && ($user->is_anonymous || (!$user->isPC && $is_anonymous)))
            return $user->anon_username;
        else if ($this->isPC || @$_SESSION["last_actas"])
            return $user->seascode_username ? : $user->email;
        else
            return null;
    }

    function user_idpart($user = null) {
        $user = $user ? $user : $this;
        if (!$this->isPC && !@$_SESSION["last_actas"])
            return null;
        else if ($user->seascode_username)
            return $user->seascode_username;
        else
            return $user->huid;
    }

}
