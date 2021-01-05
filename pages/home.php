<?php
// home.php -- Peteramati home page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");

// access only allowed through index.php
if (!$Conf) {
    exit;
}

global $Qreq, $MicroNow;
ContactView::set_path_request(array("/u"));

$Profile = $Me && $Me->privChair && $Qreq->profile;
$ProfileElapsed = 0.0;

// signin links
// auto-signin when email & password set
if (isset($_REQUEST["email"]) && isset($_REQUEST["password"])) {
    $_REQUEST["action"] = $_REQUEST["action"] ?? "login";
    $_REQUEST["signin"] = $_REQUEST["signin"] ?? "go";
}
// CSRF protection: ignore unvalidated signin/signout for known users
if (!$Me->is_empty() && !$Qreq->valid_token()) {
    unset($_REQUEST["signout"]);
}
if ($Me->has_email()
    && (!$Qreq->valid_token() || strcasecmp($Me->email, trim($Qreq->email)) == 0)) {
    unset($_REQUEST["signin"]);
}
if (!isset($_REQUEST["email"]) || !isset($_REQUEST["action"])) {
    unset($_REQUEST["signin"]);
}
// signout
if (isset($_REQUEST["signout"])) {
    $Me = LoginHelper::logout($Me, true);
} else if (isset($_REQUEST["signin"]) && !$Conf->opt("httpAuthLogin")) {
    $Me = LoginHelper::logout($Me, false);
}
// signin
if ($Conf->opt("httpAuthLogin")) {
    LoginHelper::check_http_auth($Me, $Qreq);
} else if (isset($_REQUEST["signin"])) {
    LoginHelper::login_redirect($Me->conf, $Qreq);
} else if ((isset($_REQUEST["signin"]) || isset($_REQUEST["signout"]))
           && isset($_REQUEST["post"])) {
    redirectSelf();
}

// set interesting user
$User = null;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq->u))) {
    redirectSelf(["u" => null]);
}
if (!$Me->isPC || !$User) {
    $User = $Me;
}

// check problem set openness
if (!$Me->is_empty()
    && ($Me === $User || $Me->isPC)
    && $Qreq->set_username
    && $Qreq->valid_post()
    && ($repoclass = RepositorySite::$sitemap[$Qreq->reposite])
    && in_array($repoclass, RepositorySite::site_classes($Conf), true)) {
    if ($repoclass::save_username($User, $Qreq->username)) {
        redirectSelf();
    }
}

if (!$Me->is_empty() && $Qreq->set_repo !== null) {
    ContactView::set_repo_action($User, $Qreq);
}
if (!$Me->is_empty() && $Qreq->set_branch !== null) {
    ContactView::set_branch_action($User, $Qreq);
}
if ($Qreq->set_partner !== null) {
    ContactView::set_partner_action($User, $Qreq);
}

if ((isset($Qreq->set_drop) || isset($Qreq->set_undrop))
    && $Me->isPC
    && $User->is_student()
    && $Qreq->valid_post()) {
    $Conf->qe("update ContactInfo set dropped=? where contactId=?",
              isset($Qreq->set_drop) ? Conf::$now : 0, $User->contactId);
    $Conf->qe("delete from Settings where name like '__gradets.%'");
    redirectSelf();
}



function qreq_usernames(Qrequest $qreq) {
    $users = [];
    foreach ($qreq as $k => $v) {
        if (substr($k, 0, 2) === "s:"
            && $v
            && ($uname = urldecode(substr($k, 2))))
            $users[] = $uname;
    }
    if (empty($users) && $qreq->slist)
        $users = preg_split('/\s+/', $qreq->slist, -1, PREG_SPLIT_NO_EMPTY);
    return $users;
}

function qreq_users(Qrequest $qreq) {
    global $Conf;
    $users = [];
    foreach (qreq_usernames($qreq) as $uname) {
        if (($user = $Conf->user_by_whatever($uname)))
            $users[] = $user;
    }
    return $users;
}

function set_grader(Qrequest $qreq) {
    global $Conf, $Me;
    if (!($pset = $Conf->pset_by_key($qreq->pset))) {
        return $Conf->errorMsg("No such pset");
    } else if ($pset->gitless) {
        return $Conf->errorMsg("Pset has no repository");
    }

    // collect grader weights
    $graderw = [];
    foreach ($Conf->pc_members_and_admins() as $pcm) {
        if ($qreq->grader === "__random_tf__" && ($pcm->roles & Contact::ROLE_PC)) {
            if (in_array($pcm->email, ["cassels@college.harvard.edu", "tnguyenhuy@college.harvard.edu", "skandaswamy@college.harvard.edu"])) {
                $graderw[$pcm->contactId] = 1.0 / 1.5;
            } else if ($pcm->email === "yihehuang@g.harvard.edu") {
                continue;
            } else {
                $graderw[$pcm->contactId] = 1.0;
            }
            continue;
        }
        if (strcasecmp($pcm->email, $qreq->grader) == 0
            || $qreq->grader === "__random__"
            || ($qreq->grader === "__random_tf__" && ($pcm->roles & Contact::ROLE_PC))) {
            $graderw[$pcm->contactId] = 1.0;
        }
    }

    // enumerate grader positions
    $graderp = [];
    foreach ($graderw as $cid => $w) {
        if ($w > 0)
            $graderp[$cid] = 0.0;
    }
    if (!$qreq->grader || empty($graderp))
        return $Conf->errorMsg("No grader");

    foreach (qreq_users($qreq) as $user) {
        // XXX check if can_set_grader
        $info = PsetView::make($pset, $user, $Me, "none");
        if ($info->repo) {
            $info->repo->refresh(2700, true);
        }
        $info->set_hash(null);
        if (!$info->hash()) {
            error_log("cannot set_hash for $user->email");
            continue;
        }
        // sort by position
        asort($graderp);
        // pick one of the lowest positions
        $gs = [];
        $gpos = null;
        foreach ($graderp as $cid => $pos) {
            if ($gpos === null || $gpos == $pos) {
                $gs[] = $cid;
                $gpos = $pos;
            } else {
                break;
            }
        }
        // account for grader
        $g = $gs[mt_rand(0, count($gs) - 1)];
        $info->change_grader($g);
        $graderp[$g] += $graderw[$g];
    }

    redirectSelf();
}

if ($Me->isPC && $Qreq->valid_post() && $Qreq->setgrader) {
    set_grader($Qreq);
}


/** @return ?Pset */
function older_enabled_repo_same_handout($pset) {
    $result = false;
    foreach ($pset->conf->psets() as $p) {
        if ($p !== $pset
            && !$p->disabled
            && !$p->gitless
            && $p->handout_repo_url === $pset->handout_repo_url
            && (!$result || $result->deadline < $p->deadline)) {
            $result = $p;
        }
    }
    return $result;
}

function download_pset_report(Pset $pset, Qrequest $qreq, $report) {
    $sset = StudentSet::make_for($pset->conf->site_contact(), qreq_users($qreq));
    $sset->set_pset($pset, false);
    $csv = new CsvGenerator;
    $csv->select($report->fields);

    $fobj = [];
    foreach ($report->fields as $ft) {
        if (in_array($ft, ["first", "last", "huid"])) {
            $fobj[] = $ft;
        } else if (($f = $pset->conf->formula_by_name($ft))) {
            $fobj[] = $ft;
        } else if (($ge = $pset->gradelike_by_key($ft))) {
            $fobj[] = $ge;
        }
    }

    foreach ($sset as $info) {
        $x = [];
        foreach ($fobj as $f) {
            if ($f instanceof GradeEntryConfig) {
                if (($v = $info->grade_value($f)) !== null) {
                    $x[$f->key] = $f->unparse_value($v);
                }
            } else if ($f instanceof FormulaConfig) {
                $x[$f->name] = $f->formula()->evaluate($info->user);
            } else if ($f === "first") {
                $x[$f] = $info->user->firstName;
            } else if ($f === "last") {
                $x[$f] = $info->user->lastName;
            } else if ($f === "huid") {
                $x[$f] = $info->user->huid;
            }
        }
        $csv->add_row($x);
    }

    $csv->download_headers($report->key);
    $csv->download();
    exit;
}

function doaction(Contact $viewer, Qrequest $qreq) {
    $conf = $viewer->conf;
    if (!($pset = $conf->pset_by_key($qreq->pset))
        || $pset->disabled) {
        return $conf->errorMsg("No such pset");
    }
    $hiddengrades = null;
    if ($qreq->action === "showgrades") {
        $hiddengrades = -1;
    } else if ($qreq->action === "hidegrades") {
        $hiddengrades = 1;
    } else if ($qreq->action === "defaultgrades") {
        $hiddengrades = 0;
    } else if ($qreq->action === "clearrepo") {
        foreach (qreq_users($qreq) as $user) {
            $user->set_repo($pset->id, null);
            $user->clear_links(LINK_BRANCH, $pset->id);
        }
    } else if ($qreq->action === "copyrepo") {
        if (($old_pset = older_enabled_repo_same_handout($pset))) {
            foreach (qreq_users($qreq) as $user) {
                if (!$user->repo($pset->id)
                    && ($r = $user->repo($old_pset->id))) {
                    $user->set_repo($pset->id, $r);
                    if (($b = $user->branchid($old_pset)))
                        $user->set_link(LINK_BRANCH, $pset->id, $b);
                }
            }
        }
    } else if (str_starts_with($qreq->action, "grademany_")) {
        $g = $pset->all_grades[substr($qreq->action, 10)];
        assert($g && $g->collate);
        if (!!$g->landmark_range_file) {
            Navigation::redirect($conf->hoturl_post("diffmany", ["pset" => $pset->urlkey, "file" => $g->landmark_range_file, "lines" => "{$g->landmark_range_first}-{$g->landmark_range_last}", "users" => join(" ", qreq_usernames($qreq))]));
        } else {
            Navigation::redirect($conf->hoturl_post("diffmany", ["pset" => $pset->urlkey, "grade" => $g->key, "users" => join(" ", qreq_usernames($qreq))]));
        }
    } else if (str_starts_with($qreq->action, "diffmany_")) {
        Navigation::redirect($conf->hoturl_post("diffmany", ["pset" => $pset->urlkey, "file" => substr($qreq->action, 9), "users" => join(" ", qreq_usernames($qreq))]));
    } else if (str_starts_with($qreq->action, "report_")) {
        foreach ($pset->reports as $r) {
            if (substr($qreq->action, 7) === $r->key) {
                download_pset_report($pset, $qreq, $r);
            }
        }
    } else if ($qreq->action === "diffmany") {
        Navigation::redirect($conf->hoturl_post("diffmany", ["pset" => $pset->urlkey, "users" => join(" ", qreq_usernames($qreq))]));
    }
    if ($hiddengrades !== null) {
        foreach (qreq_users($qreq) as $user) {
            $info = PsetView::make($pset, $user, $viewer);
            if ($info->grading_hash()) {
                $info->set_grades_hidden($hiddengrades);
            }
        }
    }
    redirectSelf();
}

if ($Me->isPC && $Qreq->valid_post() && $Qreq->doaction) {
    doaction($Me, $Qreq);
}


function psets_json_diff_from($original, $update) {
    $res = null;
    foreach (get_object_vars($update) as $k => $vu) {
        $vo = $original->$k ?? null;
        if (is_object($vo) && is_object($vu)) {
            if (!($vu = psets_json_diff_from($vo, $vu))) {
                continue;
            }
        } else if ($vo === $vu) {
            continue;
        }
        $res = $res ?? (object) array();
        $res->$k = $vu;
    }
    return $res;
}

function save_config_overrides($psetkey, $overrides, $json = null) {
    global $Conf, $Qreq;

    $dbjson = $Conf->setting_json("psets_override") ? : (object) array();
    $all_overrides = (object) array();
    $all_overrides->$psetkey = $overrides;
    object_replace_recursive($dbjson, $all_overrides);
    $dbjson = psets_json_diff_from($json ? : load_psets_json(true), $dbjson);
    $Conf->save_setting("psets_override", Conf::$now, $dbjson);

    unset($_GET["pset"], $_REQUEST["pset"], $Qreq->pset);
    redirectSelf(array("anchor" => $psetkey));
}

/** @param Conf $conf
 * @param Pset $pset
 * @param Pset $from_pset */
function forward_pset_links($conf, $pset, $from_pset) {
    $links = [LINK_REPO, LINK_REPOVIEW, LINK_BRANCH];
    if ($pset->partner && $from_pset->partner) {
        array_push($links, LINK_PARTNER, LINK_BACKPARTNER);
    }
    $conf->qe("insert into ContactLink (cid, type, pset, link)
        select l.cid, l.type, ?, l.link from ContactLink l where l.pset=? and l.type?a",
              $pset->id, $from_pset->id, $links);
}

/** @param Contact $user
 * @param Qrequest $qreq */
function reconfig($user, $qreq) {
    if (!($pset = $user->conf->pset_by_key($qreq->pset))
        || $pset->admin_disabled) {
        return $user->conf->errorMsg("No such pset");
    }
    $psetkey = $pset->key;

    $json = load_psets_json(true);
    object_merge_recursive($json->$psetkey, $json->_defaults);
    $old_pset = new Pset($user->conf, $psetkey, $json->$psetkey);

    $o = (object) array();
    $o->disabled = $o->visible = $o->grades_visible = null;
    $state = $_POST["state"] ?? null;
    if ($state === "disabled") {
        $o->disabled = true;
    } else if ($old_pset->disabled) {
        $o->disabled = false;
    }
    if ($state === "visible" || $state === "grades_visible") {
        $o->visible = true;
    } else if (!$old_pset->disabled && $old_pset->visible) {
        $o->visible = false;
    }
    if ($state === "grades_visible") {
        $o->grades_visible = true;
    } else if ($state === "visible" && $old_pset->grades_visible) {
        $o->grades_visible = false;
    }

    if (($_POST["frozen"] ?? null) === "yes") {
        $o->frozen = true;
    } else {
        $o->frozen = ($old_pset->frozen ? false : null);
    }

    if (($_POST["anonymous"] ?? null) === "yes") {
        $o->anonymous = true;
    } else {
        $o->anonymous = ($old_pset->anonymous ? false : null);
    }

    if (($pset->disabled || !$pset->visible)
        && (!$pset->disabled || (isset($o->disabled) && !$o->disabled))
        && ($pset->visible || (isset($o->visible) && $o->visible))
        && !$pset->gitless
        && !$user->conf->fetch_ivalue("select exists (select * from ContactLink where pset=?)", $pset->id)
        && ($older_pset = older_enabled_repo_same_handout($pset))) {
        forward_pset_links($user->conf, $pset, $older_pset);
    }

    save_config_overrides($psetkey, $o, $json);
}

if ($Me->privChair && $Qreq->valid_post() && $Qreq->reconfig) {
    reconfig($Me, $Qreq);
}


// check global system settings
if ($Me->privChair) {
    require_once("adminhome.php");
}


// Enable users
if ($Me->privChair
    && $Qreq->valid_token()
    && (isset($Qreq->enable_user)
        || isset($Qreq->send_account_info)
        || isset($Qreq->reset_password))) {
    $who = $who_user = null;
    if (isset($Qreq->u)) {
        $who_user = $Qreq->u;
    } else if (isset($Qreq->enable_user)) {
        $who = $Qreq->enable_user;
    } else if (isset($Qreq->send_account_info)) {
        $who = $Qreq->send_account_info;
    } else if (isset($Qreq->reset_password)) {
        $who = $Qreq->reset_password;
    }
    if ($who_user !== null) {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where email=?", $who_user);
    } else if ($who === "college") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension");
    } else if ($who === "college-empty") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension and password=''");
    } else if ($who === "college-nologin") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension and lastLogin=0");
    } else if ($who === "extension") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and extension");
    } else if ($who === "extension-empty") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and extension and password=''");
    } else if ($who === "extension-nologin") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and extension and lastLogin=0");
    } else if ($who === "ta") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")!=0");
    } else if ($who === "ta-empty") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")!=0 and password=''");
    } else if ($who === "ta-nologin") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")!=0 and lastLogin=0");
    } else {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where email like ?", $who);
    }
    if (empty($users)) {
        $Conf->warnMsg("No users match “" . htmlspecialchars($who) . "”.");
    } else {
        if (isset($Qreq->enable_user)) {
            UserActions::enable($users, $Me);
        } else if (isset($Qreq->reset_password)) {
            UserActions::reset_password($users, $Me, isset($Qreq->ifempty) && $Qreq->ifempty);
        } else {
            UserActions::send_account_info($users, $Me);
        }
        redirectSelf();
    }
}


$title = (!$Me->is_empty() && !isset($_REQUEST["signin"]) ? "Home" : "Sign in");
$Conf->header($title, "home");
$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";

if ($Me->privChair) {
    echo "<div id='clock_drift_container'></div>";
}


// Sidebar
echo "<div class='homeside'>";
echo "<noscript><div class='homeinside'>",
    "<strong>Javascript required.</strong> ",
    "Many features will work without Javascript, but not all.<br />",
    "<a style='font-size:smaller' href='http://read.seas.harvard.edu/~kohler/hotcrp/'>Report bad compatibility problems</a></div></noscript>";
echo "</div>\n";

// Conference management
if ($Me->privChair && (!$User || $User === $Me)) {
    echo "<div id='homeadmin'>
  <h3>administration</h3>
  <ul>
    <!-- <li><a href='", hoturl("settings"), "'>Settings</a></li>
    <li><a href='", hoturl("users", "t=all"), "'>Users</a></li> -->
    <li><a href='", hoturl("mail"), "'>Send mail</a></li>\n";

    $pclike = Contact::ROLE_PCLIKE;
    $result = $Conf->qe("select exists (select * from ContactInfo where password='' and disabled=0 and (roles=0 or (roles&$pclike)=0) and college=1), exists (select * from ContactInfo where password='' and disabled=0 and (roles=0 or (roles&$pclike)=0) and extension=1), exists (select * from ContactInfo where password='' and disabled=0 and roles!=0 and (roles&$pclike)!=0), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and (roles=0 or (roles&$pclike)=0) and lastLogin=0 and college=1), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and (roles=0 or (roles&$pclike)=0) and lastLogin=0 and extension=1), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and roles!=0 and (roles&$pclike)!=0 and lastLogin=0)");
    $row = $result->fetch_row();
    '@phan-var-force list<?string> $row';
    Dbl::free($result);

    $m = [];
    if ($row[0]) {
        $m[] = '<a href="' . $Conf->hoturl_post("index", "enable_user=college-empty") . '">college users</a>';
    }
    if ($row[1]) {
        $m[] = '<a href="' . $Conf->hoturl_post("index", "enable_user=extension-empty") . '">extension users</a>';
    }
    if ($row[2]) {
        $m[] = '<a href="' . $Conf->hoturl_post("index", "enable_user=ta-empty") . '">TAs</a>';
    }
    if (!empty($m)) {
        echo '    <li>Enable ', join(", ", $m), "</li>\n";
    }

    $m = [];
    if ($row[3])
        $m[] = '<a href="' . $Conf->hoturl_post("index", "send_account_info=college-nologin") . '">college users</a>';
    if ($row[4])
        $m[] = '<a href="' . $Conf->hoturl_post("index", "send_account_info=extension-nologin") . '">extension users</a>';
    if ($row[5])
        $m[] = '<a href="' . $Conf->hoturl_post("index", "send_account_info=ta-nologin") . '">TAs</a>';
    if (!empty($m))
        echo '    <li>Send account info to ', join(", ", $m), "</li>\n";

    echo "    <li><a href='", $Conf->hoturl_post("index", "report=nonanonymous"), "'>Overall grade report</a> (<a href='", $Conf->hoturl_post("index", "report=nonanonymous+college"), "'>college</a>, <a href='", $Conf->hoturl_post("index", "report=nonanonymous+extension"), "'>extension</a>)</li>
    <!-- <li><a href='", $Conf->hoturl("log"), "'>Action log</a></li> -->
  </ul>
</div>\n";
}

if ($Me->isPC && $User === $Me) {
    $a = [];
    foreach ($Conf->psets_newest_first() as $pset) {
        if ($Me->can_view_pset($pset) && !$pset->disabled)
            $a[] = '<a href="#' . $pset->urlkey . '">' . htmlspecialchars($pset->title) . '</a>';
    }
    if (!empty($a)) {
        echo '<div class="home-pset-links"><h4>', join(" • ", $a), '</h4></div>';
    }
}

// Home message
if (($v = $Conf->setting_data("homemsg"))) {
    $Conf->infoMsg($v);
}
if ($Me->isPC) {
    $gc = new GradeFormulaCompiler($Conf);
    $gc->check_all();
    $t = "";
    for ($i = 0; $i !== count($gc->errors); ++$i) {
        $t .= "<div>" . htmlspecialchars($gc->error_ident[$i]) . ": Formula error:<pre>" . $gc->error_decor[$i] . "</pre></div>";
    }
    if ($t !== "") {
        $Conf->warnMsg($t);
    }
}


// Sign in
if ($Me->is_empty() || isset($_REQUEST["signin"])) {
    echo '<div class="homegrp">',
        'This is the ', htmlspecialchars($Conf->long_name), ' turnin site.
Sign in to tell us about your code.';
    if ($Conf->opt("conferenceSite")) {
        echo " For general information about ", htmlspecialchars($Conf->short_name), ", see <a href=\"", htmlspecialchars($Conf->opt("conferenceSite")), "\">the conference site</a>.";
    }
    $passwordFocus = Ht::problem_status_at("email") === 0 && Ht::problem_status_at("password") !== 0;
    echo "</div>
<hr class='home' />
<div class='homegrp' id='homeaccount'>\n",
        Ht::form($Conf->hoturl_post("index")),
        "<div class='f-contain foldo fold2o' id='logingroup'>
<input type='hidden' name='cookie' value='1' />
<div class=\"", Ht::control_class("email", "f-ii"), "\">
  <div class=\"f-c\"><span class='fx2'>",
    ($Conf->opt("ldapLogin") ? "Username" : "Email/username"),
    "</span><span class='fn2'>Email</span></div><div class=\"f-e\">",
    Ht::entry("email", $Qreq->email, ["size" => 36, "tabindex" => 1, "autofocus" => !$passwordFocus, "autocomplete" => "email"]),
    Ht::render_feedback_at("email"), "</div>
</div>
<div class=\"", Ht::control_class("password", "f-i fx"), "\">
  <div class=\"f-c\">Password</div><div class=\"f-e\">",
    Ht::password("password", "", ["size" => 36, "tabindex" => 1, "autofocus" => $passwordFocus, "autocomplete" => "current-password"]),
    Ht::render_feedback_at("password"), "</div>
</div>\n";
    if ($Conf->opt("ldapLogin")) {
        echo "<input type='hidden' name='action' value='login' />\n";
    } else {
        echo "<div class='f-i'>\n  ",
            Ht::radio("action", "login", true, ["id" => "signin_action_login", "tabindex" => 2, "class" => "uic pa-signin-radio"]),
        "&nbsp;", Ht::label("<b>Sign me in</b>"), "<br />\n";
        echo Ht::radio("action", "forgot", false, ["tabindex" => 2, "class" => "uic pa-signin-radio"]),
            "&nbsp;", Ht::label("I forgot my password"), "<br />\n";
        if (!$Conf->opt("disableNewUsers"))
            echo Ht::radio("action", "new", false, ["id" => "signin_action_new", "tabindex" => 2, "class" => "uic pa-signin-radio"]),
                "&nbsp;", Ht::label("Create an account"), "<br />\n";
        Ht::stash_script("\$('#homeaccount input[name=action]:checked').click()");
        echo "\n</div>\n";
    }
    echo '<div class="f-i"><button class="btn btn-primary" type="submit" name="signin" id="signin" tabindex="1" value="1">Sign in</button></div></div></form>',
        '<hr class="home"></div>', "\n";
}


// Top: user info
if (!$Me->is_empty() && (!$Me->isPC || $User !== $Me)) {
    echo "<div id='homeinfo'>";
    $u = $Me->user_linkpart($User);
    if ($User !== $Me && !$User->is_anonymous && $User->contactImageId) {
        echo '<img class="pa-face float-left" src="' . hoturl("face", array("u" => $Me->user_linkpart($User), "imageid" => $User->contactImageId)) . '" />';
    }
    echo '<h2 class="homeemail"><a class="q" href="',
        hoturl("index", ["u" => $u]), '">', htmlspecialchars($u), '</a>';
    if ($Me->privChair) {
        echo "&nbsp;", become_user_link($User);
    }
    echo '</h2>';
    if (!$User->is_anonymous && $User !== $Me) {
        echo '<h3>', Text::user_html($User), '</h3>';
    }

    if (!$User->is_anonymous) {
        RepositorySite::echo_username_forms($User);
    }

    if ($User->dropped) {
        ContactView::echo_group("", '<strong class="err">You have dropped the course.</strong> If this is incorrect, contact us.');
    }

    echo '<hr class="c" />', "</div>\n";
}


// Per-pset
/** @return ?PsetView */
function home_psetview(Pset $pset, Contact $user, Contact $viewer) {
    if ($pset->disabled
        || !$viewer->can_view_pset($pset)
        || ($pset->gitless_grades
            && $viewer === $user
            && !$pset->partner
            && !$pset->upi_for($user)
            && !$pset->student_can_edit_grades())) {
        return null;
    } else {
        return PsetView::make($pset, $user, $viewer);
    }
}

function show_home_pset(PsetView $info) {
    echo "<hr>\n";
    $user_can_view = $info->user->can_view_pset($info->pset);
    if (!$user_can_view) {
        echo '<div class="pa-pset-hidden">';
    }
    $pseturl = $info->hoturl("pset", ["commit" => null]);
    echo "<h2><a class=\"btn\" style=\"font-size:inherit\" href=\"", $pseturl, "\">",
        htmlspecialchars($info->pset->title), "</a>";
    if (($user_see_grade = $info->user_can_view_grades())) {
        $x = [];
        $c = null;
        if ($info->needs_answers()) {
            $x[] = "empty";
            $c = "gradesmissing";
        }
        if ($info->has_nonempty_assigned_grades()) {
            $x[] = "grade ready";
            $c = "gradesready";
        }
        if ($x) {
            echo ' <a class="', $c, '" href="', $pseturl, '">(', join(", ", $x), ')</a>';
        }
    }
    echo "</h2>";
    ContactView::echo_deadline_group($info);
    ContactView::echo_partner_group($info);
    ContactView::echo_repo_group($info);
    if ($info->repo) {
        $info->repo->refresh(30);
    }
    if ($info->grading_hash()) {
        ContactView::echo_repo_grade_commit_group($info);
    } else {
        ContactView::echo_repo_last_commit_group($info, true);
    }
    if ($info->can_view_grades()
        && ($t = $info->grade_total()) !== null) {
        $t = "<strong>{$t}</strong>";
        if (($max = $info->grade_max_total())) {
            $t .= " / {$max}";
        }
        if (!$user_see_grade) {
            echo '<div class="pa-grp-hidden">';
        }
        ContactView::echo_group("grade", $t);
        if (!$user_see_grade) {
            echo '</div>';
        }
    }
    if ($info->repo && $user_can_view) {
        ContactView::echo_group("", '<strong><a href="' . $pseturl . '">view code</a></strong>');
    }
    if (!$user_can_view) {
        echo '</div>';
    }
    echo "\n";
}

if (!$Me->is_empty() && $User->is_student()) {
    $Conf->set_siteinfo("uservalue", $Me->user_linkpart($User));
    $ss = StudentSet::make_empty_for($Me, $User);
    foreach ($Conf->psets_newest_first() as $pset) {
        if (($info = home_psetview($pset, $User, $Me)))
            $ss->add_info($info);
    }
    foreach ($Conf->formulas_by_home_position() as $fc) {
        if (($User !== $Me || $fc->visible)
            && ($gf = $fc->formula())
            && ($v = $gf->evaluate($User)) !== null
            && (!($fc->nonzero ?? false) || (float) $v !== 0.0)) {
            ContactView::echo_group(htmlspecialchars($fc->title), $v);
        }
    }
    foreach ($ss->infos($User->contactId) as $info) {
        show_home_pset($info);
    }
    if ($Me->isPC) {
        echo "<div style='margin-top:5em'></div>\n";
        if ($User->dropped) {
            echo Ht::form($Conf->hoturl_post("index", ["set_undrop" => 1, "u" => $Me->user_linkpart($User)])),
                "<div>", Ht::submit("Undrop"), "</div></form>";
        } else {
            echo Ht::form($Conf->hoturl_post("index", ["set_drop" => 1, "u" => $Me->user_linkpart($User)])),
                "<div>", Ht::submit("Drop"), "</div></form>";
        }
    }
    if ($Me->privChair && $User->can_enable()) {
        echo Ht::form($Conf->hoturl_post("index", ["enable_user" => 1, "u" => $Me->user_linkpart($User)])),
            Ht::submit("Enable"), "</form>";
    }
}

function render_flag_row(Pset $pset, Contact $s = null, FlagTableRow $row, $anonymous) {
    global $Conf, $Me;
    $j = $s ? StudentSet::json_basics($s, $anonymous) : [];
    if (($gcid = $row->jnote("gradercid") ?? null)) {
        $j["gradercid"] = $gcid;
    } else if ($row->rpi && $row->rpi->gradercid) {
        $j["gradercid"] = $row->rpi->gradercid;
    }
    $j["psetid"] = $pset->id;
    $bhash = $row->bhash();
    $j["hash"] = bin2hex($bhash);
    $j["flagid"] = $row->flagid;
    if ($bhash !== null && $row->rpi && $row->rpi->gradebhash === $bhash) {
        $j["is_grade"] = true;
    }
    if ($row->cpi->haslinenotes) {
        $j["has_notes"] = true;
    }
    if (isset($row->flag->conversation)
        && ($conv = $row->flag->conversation[0][2] ?? "") !== "") {
        if (strlen($conv) < 40) {
            $j["conversation"] = $conv;
        } else {
            $j["conversation_pfx"] = UnicodeHelper::utf8_word_prefix($conv, 40);
        }
    }
    if (($t = $row->cpi->grade_total($pset)) !== null) {
        $j["total"] = $t;
    }
    if ($row->flagid[0] === "t" && ctype_digit(substr($row->flagid, 1))) {
        $j["at"] = (int) substr($row->flagid, 1);
    } else if ($row->flag->at ?? 0) {
        $j["at"] = $row->flag->at;
    }
    return $j;
}

function show_flags($result, $all) {
    global $Conf, $Me, $Qreq;

    // 1. load commit notes
    $flagrows = $uids = $psets = [];
    while (($row = CommitPsetInfo::fetch($result))) {
        foreach ((array) $row->jnote("flags") as $flagid => $v) {
            if ($all || !($v->resolved ?? false)) {
                $uids[$v->uid ?? 0] = $psets[$row->pset] = true;
                $flagrows[] = new FlagTableRow($row, $flagid, $v);
            }
        }
    }
    Dbl::free($result);
    if (empty($flagrows)) {
        return;
    }

    // 2. load repouids and branches
    $repouids = $branches = $rgwanted = [];
    $result = $Conf->qe("select cid, type, pset, link from ContactLink where (type=" . LINK_REPO . " or type=" . LINK_BRANCH . ") and pset?a", array_keys($psets));
    while (($row = $result->fetch_row())) {
        if ($row[1] == LINK_REPO) {
            $repouids["{$row[3]},{$row[2]}"][] = (int) $row[0];
            $rgwanted[] = "(repoid={$row[3]} and pset={$row[2]})";
        } else {
            $branches["{$row[0]},{$row[2]}"] = (int) $row[3];
        }
    }
    Dbl::free($result);

    // 3. load RepositoryGrades
    $rgs = [];
    $result = $Conf->qe("select * from RepositoryGrade where " . join(" or ", $rgwanted));
    while (($row = RepositoryPsetInfo::fetch($result))) {
        $rgs["{$row->repoid},{$row->pset},{$row->branchid}"] = $row;
    }
    Dbl::free($result);

    // 4. resolve `repocids`, `main_gradercid`, `gradebhash`
    foreach ($flagrows as $row) {
        foreach ($repouids["{$row->repoid()},{$row->pset()}"] ?? [] as $uid) {
            $row->repouids[] = $uid;
            $uids[$uid] = true;
            if (!isset($row->rpi)) {
                $branch = $branches["{$uid},{$row->pset()}"] ?? 0;
                $bkey = "{$row->repoid()},{$row->pset()},{$branch}";
                $row->rpi = $rgs["{$row->repoid()},{$row->pset()},{$branch}"] ?? null;
            }
        }
    }

    // 5. load users
    $contacts = [];
    $result = $Conf->qe("select * from ContactInfo where contactId?a", array_keys($uids));
    while (($c = Contact::fetch($result, $Conf))) {
        $contacts[$c->contactId] = $c;
    }
    Dbl::free($result);

    echo '<div>',
        "<h3>flagged commits</h3>",
        Ht::form(""),
        '<div class="gtable-container-0"><div class="gtable-container-1">';
    $nintotal = 0;
    $anonymous = null;
    if ($Qreq->anonymous !== null && $Me->privChair) {
        $anonymous = !!$Qreq->anonymous;
    }
    $any_anonymous = $any_nonanonymous = false;
    $jx = [];
    foreach ($flagrows as $row) {
        if (!$row->repouids) {
            continue;
        }
        $pset = $Conf->pset_by_id($row->pset());
        $anon = $anonymous === null ? $pset->anonymous : $anonymous;
        $any_anonymous = $any_anonymous || $anon;
        $any_nonanonymous = $any_nonanonymous || !$anon;

        $partners = [];
        foreach ($row->repouids as $uid) {
            if (($c = $contacts[(int) $uid] ?? null)) {
                $c->set_anonymous($anon);
                $partners[] = $c;
            }
        }
        usort($partners, "Contact::compare");

        $j = render_flag_row($pset, $partners[0], $row, $anon);
        for ($i = 1; $i < count($partners); ++$i) {
            $j["partners"][] = render_flag_row($pset, $partners[$i], $row, $anon);
        }
        $j["pos"] = count($jx);
        $jx[] = $j;
        if (isset($j["total"])) {
            ++$nintotal;
        }
    }
    echo '<table class="gtable" id="pa-pset-flagged"></table></div></div>',
        Ht::button("Resolve flags", ["class" => "btn ui js-multiresolveflag"]),
        '</form></div>', "\n";
    $jd = [
        "id" => "flagged",
        "flagged_commits" => true,
        "anonymous" => true,
        "has_nonanonymous" => $any_nonanonymous,
        "checkbox" => true
    ];
    if ($Me->privChair) {
        $jd["can_override_anonymous"] = true;
    }
    if ($nintotal) {
        $jd["need_total"] = 1;
    }
    echo Ht::unstash(), '<script>$("#pa-pset-flagged").each(function(){$pa.render_pset_table.call(this,', json_encode_browser($jd), ',', json_encode_browser($jx), ')})</script>';
}

/** @param Pset $pset */
function show_pset_actions($pset) {
    global $Conf;

    echo Ht::form($Conf->hoturl_post("index", ["pset" => $pset->urlkey, "reconfig" => 1]), ["divstyle" => "margin-bottom:1em", "class" => "need-pa-pset-actions"]);
    $options = array("disabled" => "Disabled",
                     "invisible" => "Hidden",
                     "visible" => "Visible without grades",
                     "grades_visible" => "Visible with grades");
    if ($pset->disabled) {
        $state = "disabled";
    } else if (!$pset->visible) {
        $state = "invisible";
    } else if (!$pset->grades_visible) {
        $state = "visible";
    } else {
        $state = "grades_visible";
    }
    echo Ht::select("state", $options, $state);

    echo '<span class="pa-if-visible"> &nbsp;<span class="barsep">·</span>&nbsp; ',
        Ht::select("frozen", array("no" => "Student updates allowed", "yes" => "Submissions frozen"), $pset->frozen ? "yes" : "no"),
        '</span>';

    echo '<span class="pa-if-enabled"> &nbsp;<span class="barsep">·</span>&nbsp; ',
        Ht::select("anonymous", array("no" => "Open grading", "yes" => "Anonymous grading"), $pset->anonymous ? "yes" : "no"),
        '</span>';

    echo ' &nbsp;', Ht::submit("reconfig", "Save");

    if (!$pset->disabled) {
        echo ' &nbsp;<span class="barsep">·</span>&nbsp; ';
        echo Ht::link("Grade report", $Conf->hoturl_post("index", ["pset" => $pset->urlkey, "report" => 1]), ["class" => "btn"]);
    }

    echo "</form>";
    echo Ht::unstash_script("$('.need-pa-pset-actions').each(\$pa.pset_actions)");
}

function render_pset_row(Pset $pset, StudentSet $sset, PsetView $info,
                         GradeExport $gex, $anonymous) {
    global $Profile, $MicroNow;
    $t0 = microtime(true);
    $j = StudentSet::json_basics($info->user, $anonymous);
    if (($gcid = $info->gradercid())) {
        $j["gradercid"] = $gcid;
    }

    // are any commits committed?
    if (!$pset->gitless_grades && $info->repo) {
        if ($t0 - $MicroNow < 0.2
            && !$info->user->dropped
            && !$Profile
            && $pset->student_can_view_grades()) {
            $info->update_placeholder(function ($info, $rpi) use ($t0) {
                $placeholder_at = $rpi ? $rpi->placeholder_at : 0;
                if ($rpi && !$rpi->placeholder) {
                    return false;
                } else if ($placeholder_at && $placeholder_at < $t0 - 3600) {
                    return rand(0, 2) == 0;
                } else {
                    return $placeholder_at < $t0 - 600 && rand(0, 10) == 0;
                }
            });
        }
        if (($h = $info->grading_hash()) !== null) {
            $j["gradehash"] = $h;
        } else if (($h = $info->hash()) !== null) {
            $j["hash"] = $h;
        }
        if ($h && $info->empty_diff_likely()) {
            $j["emptydiff"] = true;
        }
    }

    if ($gex->visible_grades()) {
        if (!$pset->gitless_grades) {
            $gradercid = $info->gradercid();
            $gi = $info->grade_jnotes();
            if ($gi && ($gi->linenotes ?? null)) {
                $j["has_notes"] = true;
            } else if ($info->viewer->contactId == $gradercid
                       && !$info->user->dropped
                       && !$info->empty_diff_likely()) {
                $info->user->incomplete = "no line notes";
            }
            if ($gi
                && $gradercid != ($gi->gradercid ?? null)
                && $info->viewer->privChair) {
                $j["has_nongrader_notes"] = true;
            }
        }
        if (($total = $info->grade_total()) !== null) {
            $j["total"] = $total;
        }
        foreach ($gex->visible_grades() as $ge) {
            $gv = $info->grade_value($ge);
            $agv = $info->autograde_value($ge);
            if ($gv === null
                && !$info->user->dropped
                && $ge->grader_entry_required()
                && $info->viewer_is_grader()) {
                $info->user->incomplete = "grade missing";
            }
            $j["grades"][] = $gv;
            if ($agv !== null && $gv !== $agv) {
                $j["highlight_grades"][$ge->key] = true;
            }
        }
        if ($info->user_can_view_grades()) {
            $j["grades_visible"] = true;
        }
        if (($lh = $info->fast_late_hours())) {
            $j["late_hours"] = $lh;
        }
    }

    //echo "<td><a href=\"mailto:", htmlspecialchars($s->email), "\">",
    //htmlspecialchars($s->email), "</a></td>";

    if (!$pset->gitless && $info->repo) {
        $j["repo"] = RepositorySite::make_https_url($info->repo->url, $info->conf);
        if (!$info->repo->working) {
            $j["repo_broken"] = true;
        } else if (!$info->user_can_view_repo_contents(true)) {
            $j["repo_unconfirmed"] = true;
        }
        if ($info->repo->open) {
            $j["repo_too_open"] = true;
        }
        if ($pset->handout_warn_hash
            && isset($_GET["handout"])
            && !$info->repo->connected_commit($pset->handout_warn_hash, $pset, $info->branch)) {
            $j["repo_handout_old"] = true;
        }
        if (!$info->partner_same()) {
            $j["repo_partner_error"] = true;
        } else if ($pset->partner_repo
                   && $info->partner
                   && $info->repo
                   && $info->repo->repoid != $info->partner->link(LINK_REPO, $pset->id)) {
            $j["repo_partner_error"] = true;
        } else if ($sset->repo_sharing($info->user)) {
            $j["repo_sharing"] = true;
        }
    }

    $info->user->visited = true;
    return $j;
}

/** @param StudentSet $sset */
function show_pset_table($sset) {
    global $Qreq;

    $pset = $sset->pset;
    echo '<div id="', $pset->urlkey, '">';
    echo "<h3>", htmlspecialchars($pset->title), "</h3>";
    if ($sset->viewer->privChair) {
        show_pset_actions($pset);
    }
    if ($pset->disabled) {
        echo "</div>\n";
        return;
    }

/*
    // load links
    $restrict_repo_view = $pset->conf->opt("restrictRepoView");
    $result = $pset->conf->qe("select * from ContactLink where pset=$pset->id"
        . ($restrict_repo_view ? " or type=" . LINK_REPOVIEW : ""));
    $links = [];
    while (($link = $result->fetch_object("ContactLink"))) {
        if (!isset($links[$link->type]))
            $links[$link->type] = [];
        if (!isset($links[$link->type][$link->cid]))
            $links[$link->type][$link->cid] = [];
        $links[$link->type][$link->cid][] = $link;
    }
*/

    // load students
    $anonymous = $pset->anonymous;
    if ($Qreq->anonymous !== null && $sset->viewer->privChair) {
        $anonymous = !!$Qreq->anonymous;
    }

    $checkbox = $sset->viewer->isPC
        || (!$pset->gitless && $pset->runners);

    $rows = array();
    $incomplete = $incompleteu = [];
    $grades_visible = false;
    $jx = [];
    $gradercounts = [];
    $gex = new GradeExport($pset, true);
    $gex->set_visible_grades($pset->tabular_grades());
    foreach ($sset as $s) {
        if (!$s->user->visited) {
            $j = render_pset_row($pset, $sset, $s, $gex, $anonymous);
            if (!$s->user->dropped && isset($j["gradercid"])) {
                $gradercounts[$j["gradercid"]] = ($gradercounts[$j["gradercid"]] ?? 0) + 1;
            }
            if (!$pset->partner_repo) {
                foreach ($s->user->links(LINK_PARTNER, $pset->id) as $pcid) {
                    if (($ss = $sset->info($pcid)))
                        $j["partners"][] = render_pset_row($pset, $sset, $ss, $gex, $anonymous);
                }
            }
            $jx[] = $j;
            if ($s->user->incomplete) {
                $u = $sset->viewer->user_linkpart($s->user);
                $t = '<a href="' . hoturl("pset", ["pset" => $pset->urlkey, "u" => $u]) . '">'
                    . htmlspecialchars($u);
                if ($s->user->incomplete !== true) {
                    $t .= " (" . $s->user->incomplete . ")";
                }
                $incomplete[] = $t . '</a>';
                $incompleteu[] = "~" . urlencode($u);
            }
            if ($s->user_can_view_grades()) {
                $grades_visible = true;
            }
        }
    }

    if (!empty($incomplete)) {
        echo '<div id="incomplete_pset', $pset->id, '" class="merror hidden has-hotlist" data-hotlist="',
            htmlspecialchars(json_encode_browser(["pset" => $pset->urlkey, "items" => $incompleteu])),
            '"><strong><a href="#', $pset->urlkey, '">', htmlspecialchars($pset->title), '</a></strong>: ',
            'Your grading is incomplete. Missing grades: ', join(", ", $incomplete), '</div>',
            '<script>$("#incomplete_pset', $pset->id, '").remove().removeClass("hidden").appendTo("#incomplete_notices")</script>';
    }

    if ($checkbox) {
        echo Ht::form($sset->conf->hoturl_post("index", ["pset" => $pset->urlkey, "save" => 1, "college" => $Qreq->college, "extension" => $Qreq->extension]));
        if ($pset->anonymous) {
            echo Ht::hidden("anonymous", $anonymous ? 1 : 0);
        }
    }

    echo '<div class="gtable-container-0"><div class="gtable-container-1"><table class="gtable want-gtable-fixed" id="pa-pset' . $pset->id . '"></table></div></div>';
    $jd = [
        "id" => $pset->id,
        "checkbox" => $checkbox,
        "anonymous" => $anonymous,
        "grades" => $gex,
        "gitless" => $pset->gitless,
        "gitless_grades" => $pset->gitless_grades,
        "key" => $pset->urlkey,
        "title" => $pset->title
    ];
    if ($anonymous) {
        $jd["can_override_anonymous"] = true;
    }
    $i = $nintotal = $last_in_total = 0;
    foreach ($gex->visible_grades() as $ge) {
        if (!$ge->no_total) {
            ++$nintotal;
            $last_in_total = $ge->key;
        }
        ++$i;
    }
    if ($nintotal > 1) {
        $jd["need_total"] = true;
    } else if ($nintotal == 1) {
        $jd["total_key"] = $last_in_total;
    }
    if ($grades_visible) {
        $jd["grades_visible"] = true;
    }
    echo Ht::unstash(), '<script>$("#pa-pset', $pset->id, '").each(function(){$pa.render_pset_table.call(this,', json_encode_browser($jd), ',', json_encode_browser($jx), ')})</script>';

    if ($sset->viewer->privChair && !$pset->gitless_grades) {
        $sel = array("none" => "N/A");
        foreach ($sset->conf->pc_members_and_admins() as $uid => $pcm) {
            $n = ($gradercounts[$uid] ?? 0) ? " ({$gradercounts[$uid]})" : "";
            $sel[$pcm->email] = Text::name_html($pcm) . $n;
        }
        $sel["__random__"] = "Random";
        $sel["__random_tf__"] = "Random TF";
        echo '<span class="nb" style="padding-right:2em">',
            Ht::select("grader", $sel, "none"),
            ' &nbsp;', Ht::submit("setgrader", "Set grader"),
            '</span>';
    }

    $actions = [];
    if ($sset->viewer->isPC) {
        $stage = -1;
        $actions["diffmany"] = $pset->gitless ? "Grades" : "Diffs";
        if (!$pset->gitless_grades) {
            foreach ($pset->all_diffconfig() as $dc) {
                if (($dc->collate
                     || $dc->gradable
                     || ($dc->full && $dc->collate !== false))
                    && ($f = $dc->exact_filename())) {
                    if ($stage !== -1 && $stage !== 0)
                        $actions[] = null;
                    $stage = 0;
                    $actions["diffmany_$f"] = "$f diffs";
                }
            }
        }
        if ($pset->has_grade_collate) {
            foreach ($pset->grades() as $ge) {
                if ($ge->collate) {
                    if ($stage !== -1 && $stage !== 1) {
                        $actions[] = null;
                    }
                    $stage = 1;
                    $actions["grademany_{$ge->key}"] = "Grade " . $ge->text_title();
                }
            }
        }
        if ($stage !== -1 && $stage !== 2) {
            $actions[] = null;
        }
        $stage = 2;
        $actions["showgrades"] = "Show grades";
        $actions["hidegrades"] = "Hide grades";
        $actions["defaultgrades"] = "Default grades";
        if (!$pset->gitless) {
            $actions["clearrepo"] = "Clear repo";
            if (older_enabled_repo_same_handout($pset)) {
                $actions["copyrepo"] = "Adopt previous repo";
            }
        }
        if ($pset->reports) {
            $actions[] = null;
            foreach ($pset->reports as $r) {
                $actions["report_{$r->key}"] = $r->title;
            }
        }
    }
    if (!empty($actions)) {
        echo '<span class="nb" style="padding-right:2em">',
            Ht::select("action", $actions),
            ' &nbsp;', Ht::submit("doaction", "Go"),
            '</span>';
    }

    $sel = ["__run_group" => ["optgroup", "Run"]];
    $esel = ["__ensure_group" => ["optgroup", "Ensure"]];
    foreach ($pset->runners as $r) {
        if ($sset->viewer->can_run($pset, $r)) {
            $sel[$r->name] = htmlspecialchars($r->title);
            $esel[$r->name . ".ensure"] = htmlspecialchars($r->title);
        }
    }
    if (count($sel) > 1) {
        echo '<span class="nb" style="padding-right:2em">',
            Ht::select("run", $sel + $esel),
            ' &nbsp;', Ht::submit("Run all", ["formaction" => $pset->conf->hoturl_post("run", ["pset" => $pset->urlkey, "runmany" => 1])]),
            '</span>';
    }

    if ($checkbox) {
        echo "</form>\n";
    }

    echo "</div>\n";
}

if (!$Me->is_empty() && $Me->isPC && $User === $Me) {
    echo '<div id="incomplete_notices"></div>', "\n";
    $sep = "";
    $t0 = $Profile ? microtime(true) : 0;

    $psetj = [];
    foreach ($Conf->psets() as $pset) {
        if ($Me->can_view_pset($pset)) {
            $pj = [
                "title" => $pset->title, "urlkey" => $pset->urlkey,
                "pos" => count($psetj)
            ];
            if ($pset->gitless) {
                $pj["gitless"] = true;
            }
            if ($pset->gitless || $pset->gitless_grades) {
                $pj["gitless_grades"] = true;
            }
            $psetj[$pset->psetid] = $pj;
        }
    }
    $Conf->set_siteinfo("psets", $psetj);

    $pctable = [];
    foreach ($Conf->pc_members_and_admins() as $pc) {
        if (($pc->nickname || $pc->firstName) && !$pc->nicknameAmbiguous) {
            $pctable[$pc->contactId] = $pc->nickname ? : $pc->firstName;
        } else {
            $pctable[$pc->contactId] = Text::name_text($pc);
        }
    }
    $Conf->stash_hotcrp_pc($Me);

    $allflags = !!$Qreq->allflags;
    $field = $allflags ? "hasflags" : "hasactiveflags";
    $result = Dbl::qe("select * from CommitNotes where $field=1");
    if ($result->num_rows) {
        echo $sep;
        show_flags($result, $allflags);
        if ($Profile) {
            $deltat = microtime(true) - $t0;
            $ProfileElapsed += $deltat;
            echo sprintf("<div>Δt %.06fs, %.06fs total</div>", $deltat, $ProfileElapsed);
        }
        $sep = "<hr />\n";
    }

    $sset = null;
    $MicroNow = microtime(true);
    foreach ($Conf->psets_newest_first() as $pset) {
        if ($Me->can_view_pset($pset)) {
            $t0 = $Profile ? microtime(true) : 0.0;
            if (!$sset) {
                $ssflags = 0;
                if ($Qreq->extension) {
                    $ssflags |= StudentSet::EXTENSION;
                }
                if ($Qreq->college) {
                    $ssflags |= StudentSet::COLLEGE;
                }
                $sset = new StudentSet($Me, $ssflags ? : StudentSet::ALL);
            }
            $sset->set_pset($pset);
            echo $sep;
            show_pset_table($sset);
            $sep = "<hr />\n";
            if ($Profile) {
                $deltat = microtime(true) - $t0;
                $ProfileElapsed += $deltat;
                echo sprintf("<div>Δt %.06fs, %.06fs total</div>", $deltat, $ProfileElapsed);
            }
        }
    }
}

echo "<div class='clear'></div>\n";
$Conf->footer();
