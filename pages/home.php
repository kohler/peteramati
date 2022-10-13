<?php
// home.php -- Peteramati home page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");

// access only allowed through index.php
if (!$Conf) {
    exit;
}

global $Qreq;
ContactView::set_path_request($Qreq, ["/u"], $Conf);

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
    $Conf->redirect_self($Qreq);
}

// set interesting user
$User = null;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq, $Me))) {
    $Conf->redirect_self($Qreq, ["u" => null]);
}
if (!$Me->isPC || !$User) {
    $User = $Me;
}

// check problem set openness
if (!$Me->is_empty()
    && ($Me === $User || $Me->isPC)
    && $Qreq->set_username
    && $Qreq->valid_post()
    && ($repoclass = RepositorySite::$sitemap[$Qreq->reposite ?? ""])
    && in_array($repoclass, RepositorySite::site_classes($Conf), true)) {
    if ($repoclass::save_username($User, $Qreq->username)) {
        $Conf->redirect_self($Qreq);
    }
}

if (!$Me->is_empty() && $Qreq->set_repo !== null) {
    ContactView::set_repo_action($User, $Me, $Qreq);
}
if (!$Me->is_empty() && $Qreq->set_branch !== null) {
    ContactView::set_branch_action($User, $Me, $Qreq);
}
if ($Qreq->set_partner !== null) {
    ContactView::set_partner_action($User, $Me, $Qreq);
}

if ((isset($Qreq->set_drop) || isset($Qreq->set_undrop))
    && $Me->isPC
    && $User->is_student()
    && $Qreq->valid_post()) {
    $Conf->qe("update ContactInfo set dropped=? where contactId=?",
              isset($Qreq->set_drop) ? Conf::$now : 0, $User->contactId);
    $Conf->qe("delete from Settings where name like '__gradets.%'");
    $Conf->redirect_self($Qreq);
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
    $sset = StudentSet::make_for(qreq_users($qreq), $pset->conf->site_contact());
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
            if ($f instanceof GradeEntry) {
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
    if ($qreq->action === "clearrepo") {
        foreach (qreq_users($qreq) as $user) {
            $user->set_repo($pset, null);
            $user->clear_links(LINK_BRANCH, $pset->id);
        }
    } else if ($qreq->action === "copyrepo") {
        if (($old_pset = older_enabled_repo_same_handout($pset))) {
            foreach (qreq_users($qreq) as $user) {
                if (!$user->repo($pset->id)
                    && ($r = $user->repo($old_pset->id))) {
                    $user->set_repo($pset, $r);
                    if (($b = $user->branchid($old_pset)))
                        $user->set_link(LINK_BRANCH, $pset->id, $b);
                }
            }
        }
    } else if (str_starts_with($qreq->action, "grademany_")) {
        $g = $pset->all_grades[substr($qreq->action, 10)];
        assert($g && $g->collate);
        if (!!$g->landmark_range_file) {
            $conf->redirect_hoturl("=diffmany", ["pset" => $pset->urlkey, "file" => $g->landmark_range_file, "lines" => "{$g->landmark_range_first}-{$g->landmark_range_last}", "users" => join(" ", qreq_usernames($qreq))]);
        } else {
            $conf->redirect_hoturl("=diffmany", ["pset" => $pset->urlkey, "grade" => $g->key, "users" => join(" ", qreq_usernames($qreq))]);
        }
    } else if (str_starts_with($qreq->action, "diffmany_")) {
        $conf->redirect_hoturl("=diffmany", ["pset" => $pset->urlkey, "file" => substr($qreq->action, 9), "users" => join(" ", qreq_usernames($qreq))]);
    } else if (str_starts_with($qreq->action, "report_")) {
        foreach ($pset->reports as $r) {
            if (substr($qreq->action, 7) === $r->key) {
                download_pset_report($pset, $qreq, $r);
            }
        }
    } else if ($qreq->action === "diffmany") {
        $conf->redirect_hoturl("=diffmany", ["pset" => $pset->urlkey, "users" => join(" ", qreq_usernames($qreq))]);
    }
    $conf->redirect_self($qreq);
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
    $Conf->redirect_self($Qreq, ["#" => $psetkey]);
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
        || $pset->removed) {
        return $user->conf->errorMsg("No such pset");
    }
    $psetkey = $pset->key;

    $json = load_psets_json(true);
    object_merge_recursive($json->$psetkey, $json->_defaults);
    $old_pset = new Pset($user->conf, $psetkey, $json->$psetkey);

    $o = (object) array();
    $o->disabled = $o->visible = $o->grades_visible = $o->scores_visible = null;
    $state = $_POST["state"] ?? null;
    if ($state === "grades_visible") {
        $state = "scores_visible";
    }
    if ($state === "disabled") {
        $o->disabled = true;
    } else if ($old_pset->disabled) {
        $o->disabled = false;
    }
    if ($state === "visible" || $state === "scores_visible") {
        $o->visible = true;
    } else if (!$old_pset->disabled && $old_pset->visible) {
        $o->visible = false;
    }
    if ($state === "scores_visible") {
        $o->scores_visible = true;
    } else if ($state === "visible" && $old_pset->scores_visible) {
        $o->scores_visible = false;
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
        $Conf->redirect_self($Qreq);
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
    <!-- <li><a href='", $Conf->hoturl("settings"), "'>Settings</a></li>
    <li><a href='", $Conf->hoturl("users", "t=all"), "'>Users</a></li> -->
    <li><a href='", $Conf->hoturl("mail"), "'>Send mail</a></li>\n";

    $pclike = Contact::ROLE_PCLIKE;
    $result = $Conf->qe("select exists (select * from ContactInfo where password='' and disabled=0 and (roles=0 or (roles&$pclike)=0) and college=1), exists (select * from ContactInfo where password='' and disabled=0 and (roles=0 or (roles&$pclike)=0) and extension=1), exists (select * from ContactInfo where password='' and disabled=0 and roles!=0 and (roles&$pclike)!=0), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and (roles=0 or (roles&$pclike)=0) and lastLogin=0 and college=1), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and (roles=0 or (roles&$pclike)=0) and lastLogin=0 and extension=1), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and roles!=0 and (roles&$pclike)!=0 and lastLogin=0)");
    $row = $result->fetch_row();
    '@phan-var-force list<?string> $row';
    Dbl::free($result);

    $m = [];
    if ($row[0]) {
        $m[] = '<a href="' . $Conf->hoturl("=index", "enable_user=college-empty") . '">college users</a>';
    }
    if ($row[1]) {
        $m[] = '<a href="' . $Conf->hoturl("=index", "enable_user=extension-empty") . '">extension users</a>';
    }
    if ($row[2]) {
        $m[] = '<a href="' . $Conf->hoturl("=index", "enable_user=ta-empty") . '">TAs</a>';
    }
    if (!empty($m)) {
        echo '    <li>Enable ', join(", ", $m), "</li>\n";
    }

    $m = [];
    if ($row[3])
        $m[] = '<a href="' . $Conf->hoturl("=index", "send_account_info=college-nologin") . '">college users</a>';
    if ($row[4])
        $m[] = '<a href="' . $Conf->hoturl("=index", "send_account_info=extension-nologin") . '">extension users</a>';
    if ($row[5])
        $m[] = '<a href="' . $Conf->hoturl("=index", "send_account_info=ta-nologin") . '">TAs</a>';
    if (!empty($m))
        echo '    <li>Send account info to ', join(", ", $m), "</li>\n";

    echo "    <li><a href='", $Conf->hoturl("=index", "report=nonanonymous"), "'>Overall grade report</a> (<a href='", $Conf->hoturl("=index", "report=nonanonymous+college"), "'>college</a>, <a href='", $Conf->hoturl("=index", "report=nonanonymous+extension"), "'>extension</a>)</li>
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
if ($Me->privChair) {
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
        Ht::form($Conf->hoturl("=index")),
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
        echo '<img class="pa-face float-left" src="' . $Conf->hoturl("face", array("u" => $Me->user_linkpart($User), "imageid" => $User->contactImageId)) . '" />';
    }
    echo '<h2 class="homeemail"><a class="q" href="',
        $Conf->hoturl("index", ["u" => $u]), '">', htmlspecialchars($u), '</a>';
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
if (!$Me->is_empty() && $User->is_student()) {
    $hp = new Home_Student_Page($User, $Me);
    $hp->render_default();
} else if (!$Me->is_empty() && $Me->isPC && $User === $Me) {
    $hp = new Home_TA_Page($Me, $Qreq);
    $hp->profile = $Me->privChair && $Qreq->profile;
    $hp->render_default();
}

echo "<div class='clear'></div>\n";
$Conf->footer();
