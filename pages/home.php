<?php
// home.php -- HotCRP home page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");

// access only allowed through index.php
if (!$Conf)
    exit();

global $Qreq;
ContactView::set_path_request(array("/u"));
$Qreq = make_qreq();

$email_class = "";
$password_class = "";
$LastPsetFix = false;
$Profile = $Me && $Me->privChair && $Qreq->profile;

// signin links
// auto-signin when email & password set
if (isset($_REQUEST["email"]) && isset($_REQUEST["password"])) {
    $_REQUEST["action"] = defval($_REQUEST, "action", "login");
    $_REQUEST["signin"] = defval($_REQUEST, "signin", "go");
}
// CSRF protection: ignore unvalidated signin/signout for known users
if (!$Me->is_empty() && !check_post())
    unset($_REQUEST["signout"]);
if ($Me->has_email()
    && (!check_post() || strcasecmp($Me->email, trim($Qreq->email)) == 0))
    unset($_REQUEST["signin"]);
if (!isset($_REQUEST["email"]) || !isset($_REQUEST["action"]))
    unset($_REQUEST["signin"]);
// signout
if (isset($_REQUEST["signout"]))
    LoginHelper::logout(true);
else if (isset($_REQUEST["signin"]) && !opt("httpAuthLogin"))
    LoginHelper::logout(false);
// signin
if (opt("httpAuthLogin"))
    LoginHelper::check_http_auth();
else if (isset($_REQUEST["signin"]))
    LoginHelper::check_login();
else if ((isset($_REQUEST["signin"]) || isset($_REQUEST["signout"]))
         && isset($_REQUEST["post"]))
    redirectSelf();

// set interesting user
$User = null;
if (isset($_REQUEST["u"])
    && !($User = ContactView::prepare_user($_REQUEST["u"])))
    redirectSelf(array("u" => null));
if (!$Me->isPC || !$User)
    $User = $Me;

// check problem set openness
$max_pset = $Conf->setting("pset_forwarded");
foreach ($Conf->psets() as $pset)
    if (Contact::student_can_view_pset($pset) && $pset->id > $max_pset
        && !$pset->gitless)
        Contact::forward_pset_links($pset->id);

if (!$Me->is_empty() && ($Me === $User || $Me->isPC) && $Qreq->set_username && check_post()
    && ($repoclass = RepositorySite::$sitemap[$Qreq->reposite])
    && in_array($repoclass, RepositorySite::site_classes($Conf))) {
    if ($repoclass::save_username($User, $Qreq->username))
        redirectSelf();
}

if (!$Me->is_empty() && $Qreq->set_repo !== null)
    ContactView::set_repo_action($User);
if (!$Me->is_empty() && $Qreq->set_branch !== null)
    ContactView::set_branch_action($User);
if ($Qreq->set_partner !== null)
    ContactView::set_partner_action($User);

if ((isset($_REQUEST["set_drop"]) || isset($_REQUEST["set_undrop"]))
    && $Me->isPC && $User->is_student() && check_post()) {
    Dbl::qe("update ContactInfo set dropped=? where contactId=?",
            isset($_REQUEST["set_drop"]) ? $Now : 0, $User->contactId);
    redirectSelf();
}


// download
function collect_pset_info(&$students, $pset, $where, $entries, $nonanonymous) {
    global $Conf, $Me, $Qreq;
    $result = $Conf->qe_raw("select c.contactId, c.firstName, c.lastName, c.email,
	c.huid, c.anon_username, c.seascode_username, c.github_username, c.extension,
	r.repoid, r.url, r.open, r.working, r.lastpset,
	rg.gradehash, rg.gradercid
	from ContactInfo c
	left join ContactLink l on (l.cid=c.contactId and l.type=" . LINK_REPO . " and l.pset=$pset->id)
	left join Repository r on (r.repoid=l.link)
	left join RepositoryGrade rg on (rg.repoid=r.repoid and rg.pset=$pset->id and not rg.placeholder)
	where ($where)
	and (rg.repoid is not null or not c.dropped)
	group by c.contactId");
    $sort = $Qreq->sort;
    while (($s = edb_orow($result))) {
        $s->is_anonymous = $pset->anonymous && !$nonanonymous;
        $s->username = $s->is_anonymous ? $s->anon_username : ($s->github_username ? : $s->seascode_username);
        Contact::set_sorter($s, $Conf);
        $ss = get($students, $s->username);
        if (!$ss && $s->is_anonymous)
            $students[$s->username] = $ss = (object)
                array("username" => $s->username,
                      "extension" => ($s->extension ? "Y" : "N"),
                      "sorter" => $s->username);
        else if (!$ss)
            $students[$s->username] = $ss = (object)
                array("name" => trim("$s->lastName, $s->firstName"),
                      "email" => $s->email,
                      "username" => $s->username,
                      "anon_username" => $s->anon_username,
                      "huid" => $s->huid,
                      "extension" => ($s->extension ? "Y" : "N"),
                      "sorter" => $s->sorter);

        $gi = null;
        if ($pset->gitless_grades)
            $gi = $pset->contact_grade_for($s);
        else if (get($s, "gradehash"))
            $gi = $pset->commit_notes($s->gradehash);
        else
            continue;
        $gi = $gi ? $gi->notes : null;

        $gd = ContactView::pset_grade($gi, $pset);
        if ($gd) {
            $k = $pset->psetkey;
            $ss->{$k} = $gd->total;
            $k .= "_noextra";
            $ss->{$k} = $gd->total_noextra;
            if (($k = $pset->group)) {
                $ss->{$k} = get_f($ss, $k) + $gd->total;
                $k .= "_noextra";
                $ss->{$k} = get_f($ss, $k) + $gd->total_noextra;
            }
            if ($entries)
                foreach ($pset->grades as $ge) {
                    $k = $ge->key;
                    if (get($gd, $k) !== null)
                        $ss->{$k} = $gd->{$k};
                }
        }
    }
    Dbl::free($result);
}

function set_ranks(&$students, &$selection, $key) {
    $selection[] = $key;
    $selection[] = $key . "_rank";
    uasort($students, function ($a, $b) use ($key) {
            $av = get($a, $key);
            $bv = get($b, $key);
            if (!$av)
                return $bv ? 1 : -1;
            else if (!$bv)
                return $av ? -1 : 1;
            else
                return $av < $bv ? 1 : -1;
        });
    $rank = $key . "_rank";
    $r = $i = 1;
    $lastval = null;
    foreach ($students as $s) {
        if (get($s, $key) != $lastval) {
            $lastval = get($s, $key);
            $r = $i;
        }
        $s->{$rank} = $r;
        ++$i;
    }
}

function download_psets_report($request) {
    global $Conf;
    $where = array();
    $report = $request["report"];
    $nonanonymous = false;
    foreach (explode(" ", strtolower($report)) as $rep)
        if ($rep === "college")
            $where[] = "not c.extension";
        else if ($rep === "extension")
            $where[] = "c.extension";
        else if ($rep === "nonanonymous")
            $nonanonymous = true;
    if (count($where))
        $where = array("(" . join(" or ", $where) . ")");
    $where[] = "(c.roles&" . Contact::ROLE_PCLIKE . ")=0";
    $where[] = "not c.dropped";
    $where = join(" and ", $where);

    $sel_pset = null;
    if (get($request, "pset") && !($sel_pset = $Conf->pset_by_key($request["pset"])))
        return $Conf->errorMsg("No such pset");

    $students = array();
    if (isset($request["fields"]))
        $selection = explode(",", $request["fields"]);
    else
        $selection = array("name", "grade", "username", "anon_username", "huid", "extension");
    $maxbyg = array();
    $max = $max_noextra = 0;
    foreach ($Conf->psets() as $pset)
        if (!$pset->disabled && (!$sel_pset || $sel_pset === $pset)) {
            collect_pset_info($students, $pset, $where, !!$sel_pset, $nonanonymous);
            if (($g = $pset->group)) {
                if (!isset($maxbyg[$g]))
                    $maxbyg[$g] = $maxbyg["${g}_noextra"] = 0;
                foreach ($pset->grades as $ge)
                    if ($ge->max && !$ge->no_total) {
                        $maxbyg[$g] += $ge->max;
                        if (!$ge->is_extra)
                            $maxbyg["${g}_noextra"] += $ge->max;
                    }
            }
        }

    foreach ($Conf->psets() as $pset)
        if (!$pset->disabled && (!$sel_pset || $sel_pset === $pset)) {
            set_ranks($students, $selection, $pset->psetkey);
            if ($pset->has_extra)
                set_ranks($students, $selection, $pset->psetkey . "_noextra");
            if ($sel_pset)
                foreach ($pset->grades as $ge)
                    $selection[] = $ge->key;
        }

    if (!$sel_pset) {
        set_ranks($students, $selection, "psets");
        set_ranks($students, $selection, "psets_noextra");
        set_ranks($students, $selection, "tests");
        $m_noextra = $maxbyg["psets_noextra"];
        $m_psets = $maxbyg["psets"];
        $m_tests = $maxbyg["tests"];

        foreach ($students as $s) {
            $s->performance_noextra = round(1000 * (1.65 * ($s->psets_noextra / $m_noextra) + 1.2 * ($s->tests / $m_tests))) / 10;
            $s->performance = round(1000 * (0.9 * ($s->psets_noextra / $m_noextra) + 0.75 * ($s->psets / $m_psets) + 1.2 * ($s->tests / $m_tests))) / 10;
        }
        set_ranks($students, $selection, "performance");
        set_ranks($students, $selection, "performance_noextra");
    }

    $csv = new CsvGenerator;
    $csv->set_header($selection);
    $csv->set_selection($selection);
    foreach ($students as $s)
        $csv->add($s);
    $csv->download_headers("gradereport.csv");
    $csv->download();
    exit;
}

if ($Me->isPC && check_post() && $Qreq->report)
    download_psets_report($Qreq);

function set_grader($qreq) {
    global $Conf, $Me;
    if (!($pset = $Conf->pset_by_key($qreq->pset)))
        return $Conf->errorMsg("No such pset");
    else if ($pset->gitless)
        return $Conf->errorMsg("Pset has no repository");
    $graders = array();
    foreach ($Conf->pc_members() as $pcm)
        if ($pcm->email == $qreq->grader
            || (!$pcm->privChair && $qreq->grader == "__random__"))
            $graders[] = $pcm;
    if (!$qreq->grader || !count($graders))
        return $Conf->errorMsg("No grader");
    $cur_graders = $graders;
    foreach ($qreq as $k => $v)
        if (substr($k, 0, 4) == "s61_"
            && $v
            && ($uname = urldecode(substr($k, 4)))
            && ($user = ContactView::prepare_user($uname))) {
            $info = new PsetView($pset, $user, $Me);
            if ($info->repo)
                $info->repo->refresh(2700, true);
            if ($info->set_hash(null)) {
                $which_grader = mt_rand(0, count($cur_graders) - 1);
                $info->change_grader($cur_graders[$which_grader]->contactId);
                array_splice($cur_graders, $which_grader, 1);
                if (count($cur_graders) == 0)
                    $cur_graders = $graders;
            } else
                error_log("cannot set_hash for $user->email");
        }
    redirectSelf();
}

if ($Me->isPC && check_post() && $Qreq->setgrader)
    set_grader($Qreq);


function runmany($qreq) {
    global $Conf, $Me;
    if (!($pset = $Conf->pset_by_key($qreq->pset)) || $pset->disabled)
        return $Conf->errorMsg("No such pset");
    else if ($pset->gitless)
        return $Conf->errorMsg("Pset has no repository");
    $users = array();
    foreach ($qreq as $k => $v)
        if (substr($k, 0, 4) == "s61_"
            && $v
            && ($uname = urldecode(substr($k, 4)))
            && ($user = ContactView::prepare_user($uname, $pset)))
            $users[] = $Me->user_linkpart($user);
    if (empty($users))
        return $Conf->errorMsg("No users selected.");
    go(hoturl_post("run", array("pset" => $pset->urlkey,
                                "run" => $qreq->runner,
                                "runmany" => join(" ", $users))));
    redirectSelf();
}

if ($Me->isPC && check_post() && $Qreq->runmany)
    runmany($Qreq);


function psets_json_diff_from($original, $update) {
    $res = null;
    foreach (get_object_vars($update) as $k => $vu) {
        $vo = get($original, $k);
        if (is_object($vo) && is_object($vu)) {
            if (!($vu = psets_json_diff_from($vo, $vu)))
                continue;
        } else if ($vo === $vu)
            continue;
        $res = $res ? : (object) array();
        $res->$k = $vu;
    }
    return $res;
}

function save_config_overrides($psetkey, $overrides, $json = null) {
    global $Conf;

    $dbjson = $Conf->setting_json("psets_override") ? : (object) array();
    $all_overrides = (object) array();
    $all_overrides->$psetkey = $overrides;
    object_replace_recursive($dbjson, $all_overrides);
    $dbjson = psets_json_diff_from($json ? : load_psets_json(true), $dbjson);
    $Conf->save_setting("psets_override", 1, $dbjson);

    unset($_GET["pset"], $_REQUEST["pset"]);
    redirectSelf(array("anchor" => $psetkey));
}

function reconfig() {
    global $Conf, $Me, $PsetOverrides, $PsetInfo;
    if (!($pset = $Conf->pset_by_key(req("pset"))) || $pset->ui_disabled)
        return $Conf->errorMsg("No such pset");
    $psetkey = $pset->psetkey;

    $json = load_psets_json(true);
    object_merge_recursive($json->$psetkey, $json->_defaults);
    $old_pset = new Pset($Conf, $psetkey, $json->$psetkey);

    $o = (object) array();
    $o->disabled = $o->visible = $o->grades_visible = null;
    $state = get($_POST, "state");
    if ($state === "disabled")
        $o->disabled = true;
    else if ($old_pset->disabled)
        $o->disabled = false;
    if ($state === "visible" || $state === "grades_visible")
        $o->visible = true;
    else if (!$old_pset->disabled && $old_pset->visible)
        $o->visible = false;
    if ($state === "grades_visible")
        $o->grades_visible = true;
    else if ($state === "visible" && $old_pset->grades_visible)
        $o->grades_visible = false;

    if (get($_POST, "frozen") === "yes")
        $o->frozen = true;
    else
        $o->frozen = ($old_pset->frozen ? false : null);

    if (get($_POST, "anonymous") === "yes")
        $o->anonymous = true;
    else
        $o->anonymous = ($old_pset->anonymous ? false : null);

    save_config_overrides($psetkey, $o, $json);
}

if ($Me->privChair && check_post() && get($_GET, "reconfig"))
    reconfig();


// check global system settings
if ($Me->privChair)
    require_once("adminhome.php");


// Enable users
if ($Me->privChair && check_post()
    && (isset($_GET["enable_user"]) || isset($_GET["send_account_info"]) || isset($_GET["reset_password"]))) {
    $who = get($_GET, "enable_user", get($_GET, "send_account_info", get($_GET, "reset_password", null)));
    if ($who == "college")
        $users = edb_first_columns(Dbl::qe_raw("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension"));
    else if ($who == "college-empty")
        $users = edb_first_columns(Dbl::qe_raw("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension and password=''"));
    else if ($who == "extension")
        $users = edb_first_columns(Dbl::qe_raw("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and extension"));
    else if ($who == "extension-empty")
        $users = edb_first_columns(Dbl::qe_raw("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension and password=''"));
    else if ($who == "pc")
        $users = edb_first_columns(Dbl::qe_raw("select contactId from ContactInfo where (roles&" . Contact::ROLE_PC . ")!=0"));
    else if ($who == "pc-empty")
        $users = edb_first_columns(Dbl::qe_raw("select contactId from ContactInfo where (roles&" . Contact::ROLE_PC . ")!=0 and password=''"));
    else
        $users = edb_first_columns(Dbl::qe("select contactId from ContactInfo where email like ?", $who));
    if (empty($users))
        $Conf->warnMsg("No users match “" . htmlspecialchars($who) . "”.");
    else {
        if (isset($_GET["enable_user"]))
            UserActions::enable($users, $Me);
        else if (isset($_GET["reset_password"]))
            UserActions::reset_password($users, $Me, isset($_GET["ifempty"]) && $_GET["ifempty"]);
        else
            UserActions::send_account_info($users, $Me);
        redirectSelf();
    }
}


$title = (!$Me->is_empty() && !isset($_REQUEST["signin"]) ? "Home" : "Sign in");
$Conf->header($title, "home");
$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";

if ($Me->privChair)
    echo "<div id='clock_drift_container'></div>";


// Sidebar
echo "<div class='homeside'>";
echo "<noscript><div class='homeinside'>",
    "<strong>HotCRP requires Javascript.</strong> ",
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
    <li><a href='", hoturl("mail"), "'>Send mail</a></li>
    <li><a href='", hoturl_post("index", "report=nonanonymous"), "'>Overall grade report</a> (<a href='", hoturl_post("index", "report=nonanonymous+college"), "'>college</a>, <a href='", hoturl_post("index", "report=nonanonymous+extension"), "'>extension</a>)</li>
    <!-- <li><a href='", hoturl("log"), "'>Action log</a></li> -->
  </ul>
</div>\n";
}

// Home message
if (($v = $Conf->setting_data("homemsg")))
    $Conf->infoMsg($v);


// Sign in
if ($Me->is_empty() || isset($_REQUEST["signin"])) {
    echo "<div class='homegrp'>
This is the ", htmlspecialchars($Conf->long_name), " turnin site.
Sign in to tell us about your code.";
    if ($Conf->opt("conferenceSite"))
	echo " For general information about ", htmlspecialchars($Conf->short_name), ", see <a href=\"", htmlspecialchars($Conf->opt("conferenceSite")), "\">the conference site</a>.";
    $passwordFocus = ($email_class == "" && $password_class != "");
    echo "</div>
<hr class='home' />
<div class='homegrp' id='homeacct'>\n",
        Ht::form(hoturl_post("index")),
        "<div class='f-contain foldo fold2o' id='logingroup'>
<input type='hidden' name='cookie' value='1' />
<div class='f-ii'>
  <div class='f-c", $email_class, "'><span class='fx2'>",
	($Conf->opt("ldapLogin") ? "Username" : "Email/username/HUID"),
	"</span><span class='fn2'>Email</span></div>
  <div class='f-e", $email_class, "'><input",
	($passwordFocus ? "" : " id='login_d'"),
	" type='text' class='textlite' name='email' size='36' tabindex='1' ";
    if (isset($_REQUEST["email"]))
	echo "value=\"", htmlspecialchars($_REQUEST["email"]), "\" ";
    echo " /></div>
</div>
<div class='f-i fx'>
  <div class='f-c", $password_class, "'>Password</div>
  <div class='f-e'><input",
	($passwordFocus ? " id='login_d'" : ""),
	" type='password' class='textlite' name='password' size='36' tabindex='1' value='' /></div>
</div>\n";
    if ($Conf->opt("ldapLogin"))
	echo "<input type='hidden' name='action' value='login' />\n";
    else {
	echo "<div class='f-i'>\n  ",
	    Ht::radio("action", "login", true, array("id" => "signin_action_login", "tabindex" => 2, "onclick" => "fold('logingroup',false);fold('logingroup',false,2);\$\$('signin').value='Sign in'")),
	    "&nbsp;", Ht::label("<b>Sign me in</b>"), "<br />\n";
	echo Ht::radio("action", "forgot", false, array("tabindex" => 2, "onclick" => "fold('logingroup',true);fold('logingroup',false,2);\$\$('signin').value='Reset password'")),
	    "&nbsp;", Ht::label("I forgot my password"), "<br />\n";
        if (!$Conf->opt("disableNewUsers"))
            echo Ht::radio("action", "new", false, array("id" => "signin_action_new", "tabindex" => 2, "onclick" => "fold('logingroup',true);fold('logingroup',true,2);\$\$('signin').value='Create account'")),
                "&nbsp;", Ht::label("Create an account"), "<br />\n";
	Ht::stash_script("jQuery('#homeacct input[name=action]:checked').click()");
	echo "\n</div>\n";
    }
    echo "<div class='f-i'>
  <input class='b' type='submit' value='Sign in' name='signin' id='signin' tabindex='1' />
</div>
</div></form>
<hr class='home' /></div>\n";
    Ht::stash_script("crpfocus(\"login\", null, 2)");
}


// Top: user info
if (!$Me->is_empty() && (!$Me->isPC || $User !== $Me)) {
    echo "<div id='homeinfo'>";
    $u = $Me->user_linkpart($User);
    if ($User !== $Me && !$User->is_anonymous && $User->contactImageId)
        echo '<img class="bigface61" src="' . hoturl("face", array("u" => $Me->user_linkpart($User), "imageid" => $User->contactImageId)) . '" />';
    echo '<h2 class="homeemail"><a class="q" href="',
        hoturl("index", array("u" => $u)), '">', htmlspecialchars($u), '</a>';
    if ($Me->privChair)
        echo "&nbsp;", become_user_link($User);
    echo '</h2>';
    if (!$User->is_anonymous && $User !== $Me)
        echo '<h3>', Text::user_html($User), '</h3>';

    if (!$User->is_anonymous)
        RepositorySite::echo_username_forms($User);

    if ($User->dropped)
        ContactView::echo_group("", '<strong class="err">You have dropped the course.</strong> If this is incorrect, contact us.');

    echo '<hr class="c" />', "</div>\n";
}


// Per-pset
function render_grades($pset, $gi, $s) {
    global $Me;
    $total = 0;
    $garr = $gvarr = $different = [];
    foreach ($pset->grades as $ge) {
        $k = $ge->key;
        $gv = $ggv = $agv = "";
        if ($gi && isset($gi->grades))
            $ggv = get($gi->grades, $k);
        if ($gi && isset($gi->autogrades))
            $agv = get($gi->autogrades, $k);
        if ($ggv !== null && $ggv !== "")
            $gv = $ggv;
        else if ($agv !== null && $agv !== "")
            $gv = $agv;
        if ($gv != "" && !$ge->no_total)
            $total += $gv;
        if ($gv === "" && !$ge->is_extra && $s
            && $Me->contactId == $s->gradercid)
            $s->incomplete = "grade missing";
        $gvarr[] = $gv;
        if ($ggv && $agv && $ggv != $agv)
            $different[$k] = true;
    }
    return (object) ["allv" => $gvarr,  "totalv" => $total, "differentk" => $different];
}

function show_pset($pset, $user) {
    global $Me;
    if ($pset->gitless_grades && $Me == $user && !$pset->partner
        && !$pset->contact_grade_for($user))
        return;
    echo "<hr/>\n";
    $user_can_view = $user->can_view_pset($pset);
    if (!$user_can_view)
        echo '<div class="pa-pset-hidden">';
    $pseturl = hoturl("pset", ["pset" => $pset->urlkey, "u" => $Me->user_linkpart($user)]);
    echo "<h2><a class=\"btn\" style=\"font-size:inherit\" href=\"", $pseturl, "\">",
        htmlspecialchars($pset->title), "</a>";
    $info = new PsetView($pset, $user, $Me);
    $grade_check_user = $Me->isPC && $Me != $user ? $user : $Me;
    $user_see_grade = $user->can_view_grades($pset, $info);
    if ($user_see_grade && $info->has_assigned_grades())
        echo ' <a class="gradesready" href="', $pseturl, '">(grade ready)</a>';
    echo "</a></h2>";
    ContactView::echo_partner_group($info);
    ContactView::echo_repo_group($info);
    if ($info->repo)
        $info->repo->refresh(30);
    if ($info->grading_hash())
        ContactView::echo_repo_grade_commit_group($info);
    else
        ContactView::echo_repo_last_commit_group($info, true);
    if ($info->can_view_grades() && $info->has_assigned_grades()) {
        $tm = $info->grade_total();
        $t = "<strong>" . $tm[0] . "</strong> / " . $tm[1];
        if (!$user_see_grade)
            echo '<div class="pa-grp-hidden">';
        ContactView::echo_group("grade", $t);
        if (!$user_see_grade)
            echo '</div>';
    }
    ContactView::echo_repo_flags_group($info);
    if ($info->repo && $user_can_view)
        ContactView::echo_group("", '<strong><a href="' . $pseturl . '">view code</a></strong>');
    if (!$user_can_view)
        echo '</div>';
    echo "\n";
}

if (!$Me->is_empty() && $User->is_student()) {
    Ht::stash_script("peteramati_uservalue=" . json_encode($Me->user_linkpart($User)));
    foreach ($Conf->psets_newest_first() as $pset)
        if ($Me->can_view_pset($pset) && !$pset->disabled)
            show_pset($pset, $User);
    if ($Me->isPC) {
        echo "<div style='margin-top:5em'></div>\n";
        if ($User->dropped)
            echo Ht::form(hoturl_post("index", array("set_undrop" => 1,
                                                     "u" => $Me->user_linkpart($User)))),
                "<div>", Ht::submit("Undrop"), "</div></form>";
        else
            echo Ht::form(hoturl_post("index", array("set_drop" => 1,
                                                     "u" => $Me->user_linkpart($User)))),
                "<div>", Ht::submit("Drop"), "</div></form>";
    }
}

function render_grading_student(Contact $s, $anonymous) {
    $j = ["uid" => $s->contactId];
    $j["username"] = ($s->github_username ? : $s->seascode_username) ? : ($s->email ? : $s->huid);
    if ($s->email)
        $j["email"] = $s->email;
    if ($anonymous)
        $j["anon_username"] = $s->anon_username;

    if ((string) $s->firstName !== "" && (string) $s->lastName === "")
        $j["last"] = $s->firstName;
    else {
        if ((string) $s->firstName !== "")
            $j["first"] = $s->firstName;
        if ((string) $s->lastName !== "")
            $j["last"] = $s->lastName;
    }
    if ($s->extension)
        $j["x"] = true;
    if ($s->dropped)
        $j["dropped"] = true;
    return $j;
}

function render_regrade_row(Pset $pset, Contact $s = null, $row, $anonymous) {
    global $Conf, $Me, $Now, $Profile;
    $j = $s ? render_grading_student($s, $anonymous) : [];
    if (($gcid = get($row->notes, "gradercid")))
        $j["gradercid"] = $gcid;
    else if ($row->main_gradercid)
        $j["gradercid"] = $row->main_gradercid;
    $j["psetid"] = $pset->id;
    $j["hash"] = $row->hash;
    if ($row->gradehash === $row->hash && $row->hash)
        $j["is_grade"] = true;
    if ($row->haslinenotes)
        $j["has_notes"] = true;
    if ($row->notes) {
        $garr = render_grades($pset, $row->notes, null);
        $j["total"] = $garr->totalv;
    }
    $time = 0;
    if ($row->notes && isset($row->notes->flags)) {
        foreach ((array) $row->notes->flags as $k => $v) {
            if ($k[0] === "t" && ctype_digit(substr($k, 1)))
                $thistime = +substr($k, 1);
            else
                $thistime = get($v, "at", 0);
            $time = max($time, $thistime);
        }
    }
    if ($time)
        $j["at"] = $time;
    return $j;
}

function show_regrades($result, $all) {
    global $Conf, $Me, $Now, $LastPsetFix;
    $rows = $uids = [];
    $pcmembers = $Conf->pc_members_and_admins();
    while (($row = edb_orow($result))) {
        $row->notes = json_decode($row->notes);
        $flags = (array) get($row->notes, "flags");
        $any = false;
        foreach ($flags as $t => $v)
            if ($all || !get($v, "resolved"))
                $uids[get($v, "uid", 0)] = $any = true;
        if ($any) {
            $rows[] = $row;
            foreach (explode(",", $row->repocids) as $uid)
                $uids[$uid] = true;
        }
    }
    Dbl::free($result);
    if (empty($rows))
        return;

    $contacts = [];
    $result = $Conf->qe("select * from ContactInfo where contactId?a", array_keys($uids));
    while (($c = Contact::fetch($result, $Conf)))
        $contacts[$c->contactId] = $c;
    Dbl::free($result);

    echo '<div>';
    echo "<h3>flagged commits</h3>";
    $nintotal = 0;
    $anonymous = null;
    if (req("anonymous") !== null && $Me->privChair)
        $anonymous = !!req("anonymous");
    $any_anonymous = $any_nonanonymous = false;
    $jx = [];
    foreach ($rows as $row) {
        if (!$row->repocids)
            continue;
        $pset = $Conf->pset_by_id($row->pset);
        $anon = $anonymous === null ? $pset->anonymous : $anonymous;
        $any_anonymous = $any_anonymous || $anon;
        $any_nonanonymous = $any_nonanonymous || !$anon;

        $partners = [];
        foreach (explode(",", $row->repocids) as $uid) {
            if (($c = get($contacts, $uid))) {
                $c->set_anonymous($anon);
                $partners[] = $c;
            }
        }
        usort($partners, "Contact::compare");

        $j = render_regrade_row($pset, $partners[0], $row, $anon);
        for ($i = 1; $i < count($partners); ++$i)
            $j["partners"][] = render_regrade_row($pset, $partners[$i], $row, $anon);
        $j["pos"] = count($jx);
        $jx[] = $j;
        if (isset($j["total"]))
            ++$nintotal;
    }
    echo '<table class="pap" id="pa-pset-flagged"></table></div>', "\n";
    $jd = ["flagged_commits" => true, "anonymous" => true, "has_nonanonymous" => $any_nonanonymous];
    if ($nintotal)
        $jd["need_total"] = 1;
    echo Ht::unstash(), '<script>pa_render_pset_table("-flagged",', json_encode($jd), ',', json_encode($jx), ')</script>';
}

class ContactLink {
    public $cid;
    public $pset;
    public $type;
    public $link;
    public $data;
}

function show_pset_actions($pset) {
    global $Conf;

    echo Ht::form_div(hoturl_post("index", array("pset" => $pset->urlkey, "reconfig" => 1)), ["divstyle" => "margin-bottom:1em", "class" => "need-pa-pset-actions"]);
    $options = array("disabled" => "Disabled",
                     "invisible" => "Hidden",
                     "visible" => "Visible without grades",
                     "grades_visible" => "Visible with grades");
    if ($pset->disabled)
        $state = "disabled";
    else if (!$pset->visible)
        $state = "invisible";
    else if (!$pset->grades_visible)
        $state = "visible";
    else
        $state = "grades_visible";
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
        echo Ht::js_button("Grade report", "window.location=\"" . hoturl_post("index", ["pset" => $pset->urlkey, "report" => 1]) . "\"");
    }

    echo "</div></form>";
    echo Ht::unstash_script("$('.need-pa-pset-actions').each(pa_pset_actions)");
}

function render_pset_row(Pset $pset, $students, $repos, Contact $s, $anonymous) {
    global $Conf, $Me, $Now, $Profile;
    $t0 = $Profile ? microtime(true) : 0;
    $j = render_grading_student($s, $anonymous);
    if ($s->gradercid)
        $j["gradercid"] = $s->gradercid;

    // are any commits committed?
    if (!$pset->gitless_grades) {
        if (($s->placeholder || $s->gradehash === null)
            && $s->repoid
            && ($s->placeholder_at < $Now - 3600 && rand(0, 2) == 0
                || ($s->placeholder_at < $Now - 600 && rand(0, 10) == 0))
            && (!$s->repoviewable || !$s->gradehash)) {
            // XXX this is slow given that most info is already loaded
            $info = new PsetView($pset, $s, $Me);
            $info->set_hash(null);
            $s->gradehash = $info->commit_hash() ? : null;
            $s->placeholder = 1;
            Dbl::qe("insert into RepositoryGrade (repoid, pset, gradehash, placeholder, placeholder_at) values (?, ?, ?, 1, ?) on duplicate key update gradehash=(if(placeholder=1,values(gradehash),gradehash)), placeholder_at=values(placeholder_at)",
                    $s->repoid, $pset->id, $s->gradehash, $Now);
            if (!$s->repoviewable)
                $s->repoviewable = $info->user_can_view_repo_contents();
        }
        if ($s->gradehash)
            $j["gradehash"] = $s->gradehash;
    }

    if (!empty($pset->grades)) {
        $gi = null;
        if ($pset->gitless_grades)
            $gi = $pset->contact_grade_for($s);
        else if ($s->gradehash && !$s->placeholder)
            $gi = $pset->commit_notes($s->gradehash);
        $gi = $gi ? $gi->notes : null;

        if (!$pset->gitless_grades) {
            if ($gi && get($gi, "linenotes"))
                $j["has_notes"] = true;
            else if ($Me->contactId == $s->gradercid)
                $s->incomplete = "no line notes";
            if ($gi && $s->gradercid != get($gi, "gradercid") && $Me->privChair)
                $j["has_nongrader_notes"] = true;
        }

        $garr = render_grades($pset, $gi, $s);
        $j["grades"] = $garr->allv;
        $j["total"] = $garr->totalv;
        if ($garr->differentk)
            $j["highlight_grades"] = $garr->differentk;
    }

    //echo "<td><a href=\"mailto:", htmlspecialchars($s->email), "\">",
    //htmlspecialchars($s->email), "</a></td>";

    if (!$pset->gitless && $s->url) {
        $j["repo"] = RepositorySite::make_web_url($s->url, $Conf);
        if (!$s->working)
            $j["repo_broken"] = true;
        else if (!$s->repoviewable)
            $j["repo_unconfirmed"] = true;
        if ($s->open)
            $j["repo_too_open"] = true;
        if ($s->pcid != $s->rpcid
            || ($s->pcid && (!isset($students[$s->pcid])
                             || $students[$s->pcid]->repoid != $s->repoid)))
            $j["repo_partner_error"] = true;
        else if ($s->repoid) {
            $expected_cids = [$s->contactId];
            if ($s->pcid)
                $expected_cids[] = $s->pcid;
            if (count(array_intersect($expected_cids, $repos[$s->repoid])) != count($expected_cids))
                $j["repo_sharing"] = true;
        }
    }

    $s->visited = true;
    return $j;
}

function show_pset_table($pset) {
    global $Conf, $Me, $Now, $Profile, $LastPsetFix;

    echo '<div id="', $pset->urlkey, '">';
    echo "<h3>", htmlspecialchars($pset->title), "</h3>";
    if ($Me->privChair)
        show_pset_actions($pset);
    if ($pset->disabled) {
        echo "</div>\n";
        return;
    }

    $t0 = $Profile ? microtime(true) : 0;

/*
    // load links
    $restrict_repo_view = $Conf->opt("restrictRepoView");
    $result = $Conf->qe("select * from ContactLink where pset=$pset->id"
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
    if ($Conf->opt("restrictRepoView")) {
        $view = "exists (select * from ContactLink where cid=c.contactId and type=" . LINK_REPOVIEW . " and link=l.link)";
    } else {
        $view = "1";
    }
    $q = "select c.contactId, c.firstName, c.lastName, c.email,
	c.huid, c.github_username, c.seascode_username, c.anon_username, c.extension, c.disabled, c.dropped, c.roles, c.contactTags,
	group_concat(pl.link) pcid, group_concat(rpl.link) rpcid,
	r.repoid, r.cacheid, r.heads, r.url, r.open, r.working, r.lastpset, r.snapcheckat, $view repoviewable,
	rg.gradehash, rg.gradercid, rg.placeholder, rg.placeholder_at
	from ContactInfo c
	left join ContactLink l on (l.cid=c.contactId and l.type=" . LINK_REPO . " and l.pset=$pset->id)
	left join Repository r on (r.repoid=l.link)
	left join ContactLink pl on (pl.cid=c.contactId and pl.type=" . LINK_PARTNER . " and pl.pset=$pset->id)
	left join ContactLink rpl on (rpl.cid=c.contactId and rpl.type=" . LINK_BACKPARTNER . " and rpl.pset=$pset->id)
	left join RepositoryGrade rg on (rg.repoid=r.repoid and rg.pset=$pset->id)
	where (c.roles&" . Contact::ROLE_PCLIKE . ")=0
	and (rg.repoid is not null or not c.dropped)
	group by c.contactId, l.link";
    //error_log(preg_replace('/\s+/', ' ', $q));
    $result = $Conf->qe_raw($q);
    $t1 = $Profile ? microtime(true) : 0;

    $anonymous = $pset->anonymous;
    if (req("anonymous") !== null && $Me->privChair)
        $anonymous = !!req("anonymous");
    $students = $repos = array();
    while ($result && ($s = Contact::fetch($result))) {
        $s->set_anonymous($anonymous);
        $students[$s->contactId] = $s;
        if (!$pset->gitless && $s->repoid)
            $repos[$s->repoid][] = $s->contactId;
        // maybe lastpset links are out of order
        if ($s->lastpset < $pset)
            $LastPsetFix = true;
    }
    uasort($students, "Contact::compare");

    $checkbox = $Me->privChair
        || (!$pset->gitless && $pset->runners);

    $rows = array();
    $incomplete = array();
    $pcmembers = $Conf->pc_members();
    $jx = [];
    foreach ($students as $s)
        if (!$s->visited) {
            $j = render_pset_row($pset, $students, $repos, $s, $anonymous);
            if ($s->pcid) {
                foreach (array_unique(explode(",", $s->pcid)) as $pcid)
                    if (isset($students[$pcid])) {
                        $ss = $students[$pcid];
                        $jj = render_pset_row($pset, $students, $repos, $ss, $anonymous);
                        $j["partners"][] = $jj;
                    }
            }
            $jx[] = $j;
            if ($s->incomplete) {
                $u = $Me->user_linkpart($s);
                $t = '<a href="' . hoturl("pset", ["pset" => $pset->urlkey, "u" => $u]) . '">'
                    . htmlspecialchars($u);
                if ($s->incomplete !== true)
                    $t .= " (" . $s->incomplete . ")";
                $incomplete[] = $t . '</a>';
            }
        }

    if (!empty($incomplete)) {
        echo '<div id="incomplete_pset', $pset->id, '" style="display:none" class="merror">',
            '<strong>', htmlspecialchars($pset->title), '</strong>: ',
            'Your grading is incomplete. Missing grades: ', join(", ", $incomplete), '</div>',
            '<script>$("#incomplete_pset', $pset->id, '").remove().show().appendTo("#incomplete_notices")</script>';
    }

    if ($checkbox)
        echo Ht::form_div(hoturl_post("index", array("pset" => $pset->urlkey, "save" => 1)));

    echo '<table class="pap" id="pa-pset' . $pset->id . '"></table>';
    $jd = ["checkbox" => $checkbox, "anonymous" => $anonymous, "grade_keys" => array_keys($pset->grades),
           "gitless" => $pset->gitless, "gitless_grades" => $pset->gitless_grades,
           "psetkey" => $pset->urlkey];
    if ($anonymous)
        $jd["can_override_anonymous"] = true;
    $i = $nintotal = $last_in_total = 0;
    foreach ($pset->grades as $ge) {
        if (!$ge->no_total) {
            ++$nintotal;
            $last_in_total = $ge->key;
        }
        ++$i;
    }
    if ($nintotal > 1)
        $jd["need_total"] = true;
    else if ($nintotal == 1)
        $jd["total_key"] = $last_in_total;
    echo Ht::unstash(), '<script>pa_render_pset_table(', $pset->id, ',', json_encode($jd), ',', json_encode($jx), ')</script>';

    if ($Me->privChair && !$pset->gitless_grades) {
        echo "<div class='g'></div>";
        $sel = array("none" => "N/A");
        foreach ($Conf->pc_members() as $pcm)
            $sel[$pcm->email] = Text::name_html($pcm);
        $sel["__random__"] = "Random";
        echo '<span class="nb" style="padding-right:2em">',
            Ht::select("grader", $sel, "none"),
            Ht::submit("setgrader", "Set grader"),
            '</span>';
    }

    if (!$pset->gitless) {
        $sel = array();
        foreach ($pset->runners as $r)
            if ($Me->can_run($pset, $r))
                $sel[$r->name] = htmlspecialchars($r->title);
        if (count($sel))
            echo '<span class="nb" style="padding-right:2em">',
                Ht::select("runner", $sel),
                Ht::submit("runmany", "Run all"),
                '</span>';
    }

    if ($checkbox)
        echo "</div></form>\n";

    if ($Profile) {
        $t2 = microtime(true);
        echo sprintf("<div>Δt %.06f DB, %.06f total</div>", $t1 - $t0, $t2 - $t0);
    }

    echo "</div>\n";
}

if (!$Me->is_empty() && $Me->isPC && $User === $Me) {
    echo '<div id="incomplete_notices"></div>', "\n";
    $sep = "";
    $t0 = $Profile ? microtime(true) : 0;

    $psetj = [];
    foreach ($Conf->psets() as $pset)
        if ($Me->can_view_pset($pset)) {
            $pj = ["title" => $pset->title, "urlkey" => $pset->urlkey,
                   "pos" => count($psetj)];
            if ($pset->gitless)
                $pj["gitless"] = true;
            if ($pset->gitless || $pset->gitless_grades)
                $pj["gitless_grades"] = true;
            $psetj[$pset->psetid] = $pj;
        }
    Ht::stash_script('peteramati_psets=' . json_encode($psetj) . ';');

    $pctable = [];
    foreach ($Conf->pc_members() as $pc)
        if ($pc->firstName && !$pc->firstNameAmbiguous)
            $pctable[$pc->contactId] = $pc->firstName;
        else
            $pctable[$pc->contactId] = Text::name_text($pc);
    Ht::stash_script('peteramati_grader_map=' . json_encode($pctable) . ';');

    $allflags = !!req("allflags");
    $field = req("allflags") ? "hasflags" : "hasactiveflags";
    $result = Dbl::qe("select cn.*, rg.gradercid main_gradercid, rg.gradehash,
        (select group_concat(cid) from ContactLink where pset=cn.pset and type=" . LINK_REPO . " and link=cn.repoid) repocids
        from CommitNotes cn
        left join RepositoryGrade rg on (rg.repoid=cn.repoid and rg.pset=cn.pset)
        where $field=1");
    if (edb_nrows($result)) {
        echo $sep;
        show_regrades($result, !!req("allflags"));
        if ($Profile)
            echo "<div>Δt ", sprintf("%.06f", microtime(true) - $t0), "</div>";
        $sep = "<hr />\n";
    }

    foreach ($Conf->psets_newest_first() as $pset)
        if ($Me->can_view_pset($pset)) {
            echo $sep;
            show_pset_table($pset, $Me);
            $sep = "<hr />\n";
        }

    if ($LastPsetFix) {
        $Conf->log("Repository.lastpset links are bogus", $Me);
        Contact::update_all_repo_lastpset();
    }
}

echo "<div class='clear'></div>\n";
$Conf->footer();
