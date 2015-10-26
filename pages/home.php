<?php
// home.php -- HotCRP home page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

// access only allowed through index.php
if (!$Conf)
    exit();

ContactView::set_path_request(array("/u"));

$email_class = "";
$password_class = "";
$LastPsetFix = false;
$Profile = $Me && $Me->privChair && @$_REQUEST["profile"];

// signin links
if (isset($_REQUEST["email"]) && isset($_REQUEST["password"])) {
    $_REQUEST["action"] = defval($_REQUEST, "action", "login");
    $_REQUEST["signin"] = defval($_REQUEST, "signin", "go");
}

if ((isset($_REQUEST["email"]) && isset($_REQUEST["password"])
     && isset($_REQUEST["signin"]) && !isset($Opt["httpAuthLogin"]))
    || isset($_REQUEST["signout"]))
    LoginHelper::logout();

if (isset($Opt["httpAuthLogin"]))
    LoginHelper::check_http_auth();
else if (isset($_REQUEST["email"])
         && isset($_REQUEST["action"])
         && isset($_REQUEST["signin"]))
    LoginHelper::check_login();

// set interesting user
$User = null;
if (isset($_REQUEST["u"])
    && !($User = ContactView::prepare_user($_REQUEST["u"])))
    redirectSelf(array("u" => null));
if (!$Me->isPC || !$User)
    $User = $Me;

// check problem set openness
$max_pset = $Conf->setting("pset_forwarded");
foreach (Pset::$all as $pset)
    if (Contact::student_can_see_pset($pset) && $pset->id > $max_pset
        && !$pset->gitless)
        Contact::forward_pset_links($pset->id);

if (!$Me->is_empty() && ($Me === $User || $Me->isPC)
    && isset($_REQUEST["set_seascode_username"]) && check_post()) {
    if ($User->set_seascode_username(defval($_REQUEST, "username")))
        redirectSelf();
} else if (!$Me->is_empty() && !$User->seascode_username
           && preg_match('/\A(.*?)@.*harvard\.edu\z/', $User->email, $m)
           && $User->check_seascode_username($m[1], false))
    $User->set_seascode_username($m[1]);

function try_set_seascode_repo() {
    global $Me, $Conf;
    $min_pset = null;
    foreach (Pset::$all as $p)
        if (!$min_pset || ContactView::pset_compare($min_pset, $p) > 0)
            $min_pset = $p;
    if ($p && !$Me->repo($p->psetid))
        $Me->set_seascode_repo($p->psetid, $Me->seascode_username, false);
    $Conf->save_session("autorepotry", $Me->contactId);
}

if (!$Me->is_empty() && isset($_REQUEST["set_seascode_repo"]))
    ContactView::set_seascode_repo_action($User);
else if (!$Me->is_empty() && !$Me->isPC && $Me->seascode_username
         && $Conf->session("autorepotry", 0) != $Me->contactId)
    try_set_seascode_repo();

if (isset($_REQUEST["set_partner"]))
    ContactView::set_partner_action($User);

if ((isset($_REQUEST["set_drop"]) || isset($_REQUEST["set_undrop"]))
    && $Me->isPC && $User->is_student() && check_post()) {
    Dbl::qe("update ContactInfo set dropped=? where contactId=?",
            isset($_REQUEST["set_drop"]) ? $Now : 0, $User->contactId);
    redirectSelf();
}


// download
function collect_pset_info(&$students, $pset, $where, $entries = false) {
    global $Conf, $Me;
    $result = $Conf->qe("select c.contactId, c.firstName, c.lastName, c.email,
	c.huid, c.anon_username, c.seascode_username, c.extension,
	r.repoid, r.url, r.open, r.working, r.lastpset,
	rg.gradehash, rg.gradercid
	from ContactInfo c
	left join ContactLink l on (l.cid=c.contactId and l.type=" . LINK_REPO . " and l.pset=$pset->id)
	left join Repository r on (r.repoid=l.link)
	left join RepositoryGrade rg on (rg.repoid=r.repoid and rg.pset=$pset->id and not rg.placeholder)
	where ($where)
	and (rg.repoid is not null or not c.dropped)
	group by c.contactId");
    while (($s = edb_orow($result))) {
        Contact::set_sorter($s, @$_REQUEST["sort"]);
        $username = $pset->anonymous ? $s->anon_username : $s->seascode_username;
        $ss = @$students[$username];
        if (!$ss && $pset->anonymous)
            $students[$username] = $ss = (object)
                array("username" => $username,
                      "extension" => ($s->extension ? "Y" : "N"),
                      "sorter" => $username);
        else
            $students[$username] = $ss = (object)
                array("name" => trim("$s->lastName, $s->firstName"),
                      "email" => $s->email,
                      "username" => $username,
                      "huid" => $s->huid,
                      "extension" => ($s->extension ? "Y" : "N"),
                      "sorter" => $s->sorter);

        if ($pset->gitless_grades) {
            $gi = Contact::contact_grade_for($s, $pset);
            $gi = $gi ? $gi->notes : null;
        } else if (@$s->gradehash)
            $gi = $Me->commit_info($s->gradehash, $pset);
        else
            continue;

        $gd = ContactView::pset_grade($gi, $pset);
        if ($gd) {
            $k = $pset->psetkey;
            $ss->$k = $gd->total;
            $k .= "_noextra";
            $ss->$k = $gd->total_noextra;
            if (($k = $pset->group)) {
                @($ss->$k += $gd->total);
                $k .= "_noextra";
                @($ss->$k += $gd->total_noextra);
            }
            if ($entries)
                foreach ($pset->grades as $ge) {
                    $k = $ge->name;
                    if (@$gd->$k !== null)
                        $ss->$k = $gd->$k;
                }
        }
    }
}

function set_ranks(&$students, &$selection, $key) {
    $selection[] = $key;
    $selection[] = $key . "_rank";
    uasort($students, function ($a, $b) use ($key) {
            $av = @$a->$key;
            $bv = @$b->$key;
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
        if (@$s->$key != $lastval) {
            $lastval = @$s->$key;
            $r = $i;
        }
        $s->$rank = $r;
        ++$i;
    }
}

function download_psets_report($request) {
    $where = array();
    $report = $request["report"];
    foreach (explode(" ", strtolower($report)) as $rep)
        if ($rep === "college")
            $where[] = "not c.extension";
        else if ($rep === "extension")
            $where[] = "c.extension";
    if (count($where))
        $where = array("(" . join(" or ", $where) . ")");
    $where[] = "(c.roles&" . Contact::ROLE_PCLIKE . ")=0";
    $where[] = "not c.dropped";
    $where = join(" and ", $where);

    $sel_pset = null;
    if (@$request["pset"] && !($sel_pset = Pset::find($request["pset"])))
        return $Conf->errorMsg("No such pset");

    $students = array();
    if (isset($request["fields"]))
        $selection = explode(",", $request["fields"]);
    else
        $selection = array("name", "grade", "seascode_username", "huid", "extension");
    $maxbyg = array();
    $max = $max_noextra = 0;
    foreach (Pset::$all as $pset)
        if (!$pset->disabled && (!$sel_pset || $sel_pset === $pset)) {
            collect_pset_info($students, $pset, $where, !!$sel_pset);
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

    foreach (Pset::$all as $pset)
        if (!$pset->disabled && (!$sel_pset || $sel_pset === $pset)) {
            set_ranks($students, $selection, $pset->psetkey);
            if ($pset->has_extra)
                set_ranks($students, $selection, $pset->psetkey . "_noextra");
            if ($sel_pset)
                foreach ($pset->grades as $ge)
                    $selection[] = $ge->name;
        }

    if (!$sel_pset) {
        set_ranks($students, $selection, "psets");
        set_ranks($students, $selection, "psets_noextra");
        set_ranks($students, $selection, "tests");

        foreach ($students as $s)
            $s->performance = sprintf("%.1f",
                                      100 * @(0.9 * ($s->psets_noextra / $maxbyg["psets_noextra"])
                                              + 0.75 * ($s->psets / $maxbyg["psets"])
                                              + 1.2 * ($s->tests / $maxbyg["tests"])));
        set_ranks($students, $selection, "performance");
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

if ($Me->isPC && check_post() && @$_GET["report"])
    download_psets_report($_REQUEST);

function set_grader() {
    global $Conf, $Me;
    if (!($pset = Pset::find(@$_REQUEST["pset"])))
        return $Conf->errorMsg("No such pset");
    else if ($pset->gitless)
        return $Conf->errorMsg("Pset has no repository");
    $graders = array();
    foreach (pcMembers() as $pcm)
        if ($pcm->email == @$_POST["grader"]
            || (!$pcm->privChair && @$_POST["grader"] == "__random__"))
            $graders[] = $pcm;
    if (!@$_POST["grader"] || !count($graders))
        return $Conf->errorMsg("No grader");
    $cur_graders = $graders;
    foreach ($_POST as $k => $v)
        if (substr($k, 0, 4) == "s61_"
            && $v
            && ($uname = substr($k, 4))
            && ($user = ContactView::prepare_user($uname))) {
            $info = ContactView::user_pset_info($user, $pset);
            if ($info->repo)
                Contact::check_repo($info->repo, 2700, true);
            if ($info->set_commit(null)) {
                $which_grader = mt_rand(0, count($cur_graders) - 1);
                $info->change_grader($cur_graders[$which_grader]->contactId);
                array_splice($cur_graders, $which_grader, 1);
                if (count($cur_graders) == 0)
                    $cur_graders = $graders;
            } else
                error_log("cannot set_commit for $user->email");
        }
    redirectSelf();
}

if ($Me->isPC && check_post() && @$_POST["setgrader"])
    set_grader();


function runmany() {
    global $Conf, $Me;
    if (!($pset = Pset::find(@$_REQUEST["pset"])) || $pset->disabled)
        return $Conf->errorMsg("No such pset");
    else if ($pset->gitless)
        return $Conf->errorMsg("Pset has no repository");
    $users = array();
    foreach ($_POST as $k => $v)
        if (substr($k, 0, 4) == "s61_"
            && $v
            && ($uname = substr($k, 4))
            && ($user = ContactView::prepare_user($uname, $pset)))
            $users[] = $Me->user_linkpart($user);
    if (!count($users))
        return $Conf->errorMsg("No users selected.");
    go(hoturl_post("run", array("pset" => $pset->urlkey,
                                "run" => $_REQUEST["runner"],
                                "runmany" => join(" ", $users))));
    redirectSelf();
}

if ($Me->isPC && check_post() && @$_POST["runmany"])
    runmany();


function psets_json_diff_from($original, $update) {
    $res = null;
    foreach (get_object_vars($update) as $k => $vu) {
        $vo = @$original->$k;
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
    $dbjson = psets_json_diff_from($json ? : load_psets_json(), $dbjson);
    $Conf->save_setting("psets_override", 1, $dbjson);

    unset($_GET["pset"], $_REQUEST["pset"]);
    redirectSelf(array("anchor" => $psetkey));
}

function reconfig() {
    global $Conf, $Me, $PsetOverrides, $PsetInfo;
    if (!($pset = Pset::find(@$_REQUEST["pset"])))
        return $Conf->errorMsg("No such pset");
    $psetkey = $pset->psetkey;

    $json = load_psets_json();
    object_merge_recursive($json->$psetkey, $PsetInfo->_defaults);
    $old_pset = new Pset($psetkey, $json->$psetkey);

    $o = (object) array();
    if (@$_POST["action"] === "setstate") {
        $o->disabled = $o->visible = $o->grades_visible = null;
        $state = @$_POST["state"];
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
    }

    if (@$_POST["action"] = "setfrozen") {
        if (@$_POST["frozen"] === "yes")
            $o->frozen = true;
        else
            $o->frozen = ($old_pset->frozen ? false : null);
    }

    if (@$_POST["action"] = "setanonymous") {
        if (@$_POST["anonymous"] === "yes")
            $o->anonymous = true;
        else
            $o->anonymous = ($old_pset->anonymous ? false : null);
    }

    save_config_overrides($psetkey, $o, $json);
}

if ($Me->privChair && check_post() && @$_GET["reconfig"])
    reconfig();


// check global system settings
if ($Me->privChair)
    require_once("adminhome.php");


// Enable users
if ($Me->privChair && check_post() && isset($_GET["enable_user"])) {
    if ($_GET["enable_user"] == "college")
        $users = edb_first_columns(Dbl::qe_raw("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension"));
    else if ($_GET["enable_user"] == "extension")
        $users = edb_first_columns(Dbl::qe_raw("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and extension"));
    else if ($_GET["enable_user"] == "pc")
        $users = edb_first_columns(Dbl::qe_raw("select contactId from ContactInfo where (roles&" . Contact::ROLE_PC . ")!=0"));
    else
        $users = edb_first_columns(Dbl::qe("select contactId from ContactInfo where email like ?", $_GET["enable_user"]));
    if (!count($users))
        $Conf->warnMsg("No users match.");
    else {
        UserActions::enable($users, $Me);
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

// Conference management
if ($Me->privChair) {
    echo "<div id='homemgmt' class='homeinside'>
  <h4>Administration</h4>
  <ul>
    <!-- <li><a href='", hoturl("settings"), "'>Settings</a></li>
    <li><a href='", hoturl("users", "t=all"), "'>Users</a></li> -->
    <li><a href='", hoturl("mail"), "'>Send mail</a></li>
    <!-- <li><a href='", hoturl("log"), "'>Action log</a></li> -->
  </ul>
</div>\n";
}

echo "</div>\n";

// Home message
if (($v = $Conf->setting_data("homemsg")))
    $Conf->infoMsg($v);


// Sign in
if ($Me->is_empty() || isset($_REQUEST["signin"])) {
    echo "<div class='homegrp'>
This is the ", htmlspecialchars($Opt["longName"]), " turnin site.
Sign in to tell us about your code.";
    if (isset($Opt["conferenceSite"]))
	echo " For general information about ", htmlspecialchars($Opt["shortName"]), ", see <a href=\"", htmlspecialchars($Opt["conferenceSite"]), "\">the conference site</a>.";
    $passwordFocus = ($email_class == "" && $password_class != "");
    echo "</div>
<hr class='home' />
<div class='homegrp' id='homeacct'>\n",
        Ht::form(hoturl_post("index")),
        "<div class='f-contain foldo fold2o' id='logingroup'>
<input type='hidden' name='cookie' value='1' />
<div class='f-ii'>
  <div class='f-c", $email_class, "'><span class='fx2'>",
	(isset($Opt["ldapLogin"]) ? "Username" : "Email/<a href='https://code.seas.harvard.edu/'>code.seas</a>&nbsp;username/HUID"),
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
    if (isset($Opt["ldapLogin"]))
	echo "<input type='hidden' name='action' value='login' />\n";
    else {
	echo "<div class='f-i'>\n  ",
	    Ht::radio("action", "login", true, array("id" => "signin_action_login", "tabindex" => 2, "onclick" => "fold('logingroup',false);fold('logingroup',false,2);\$\$('signin').value='Sign in'")),
	    "&nbsp;", Ht::label("<b>Sign me in</b>"), "<br />\n";
	echo Ht::radio("action", "forgot", false, array("tabindex" => 2, "onclick" => "fold('logingroup',true);fold('logingroup',false,2);\$\$('signin').value='Reset password'")),
	    "&nbsp;", Ht::label("I forgot my password"), "<br />\n";
        if (!@$Opt["disableNewUsers"])
            echo Ht::radio("action", "new", false, array("id" => "signin_action_new", "tabindex" => 2, "onclick" => "fold('logingroup',true);fold('logingroup',true,2);\$\$('signin').value='Create account'")),
                "&nbsp;", Ht::label("Create an account"), "<br />\n";
	$Conf->footerScript("jQuery('#homeacct input[name=action]:checked').click()");
	echo "\n</div>\n";
    }
    echo "<div class='f-i'>
  <input class='b' type='submit' value='Sign in' name='signin' id='signin' tabindex='1' />
</div>
</div></form>
<hr class='home' /></div>\n";
    $Conf->footerScript("crpfocus(\"login\", null, 2)");
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

    if (!$User->is_anonymous) {
        echo Ht::form(hoturl_post("index", array("set_seascode_username" => 1,
                                                 "u" => $Me->user_linkpart($User)))),
            '<div class="f-contain">';
        $notes = array();
        if (!$User->seascode_username)
            $notes[] = array(true, "Please enter your " . Contact::seascode_home("code.seas.harvard.edu") . " username and click “Save.”");
        ContactView::echo_group(Contact::seascode_home("code.seas") . " username",
                                Ht::entry("username", $User->seascode_username)
                                . "  " . Ht::submit("Save"), $notes);
        echo "</div></form>";
    }

    if ($User->dropped)
        ContactView::echo_group("", '<strong class="err">You have dropped the course.</strong> If this is incorrect, contact us.');

    echo '<hr class="c" />', "</div>\n";
}


// Per-pset
function render_grades($pset, $gi, $s) {
    global $Me;
    $total = $nintotal = $max = 0;
    $lastintotal = null;
    $garr = array();
    foreach ($pset->grades as $ge) {
        $k = $ge->name;
        if (!$ge->no_total) {
            ++$nintotal;
            if ($ge->max && !$ge->hide_max && !$ge->is_extra)
                $max += $ge->max;
        }
        $gv = $ggv = $agv = "";
        if ($gi) {
            $ggv = @$gi->grades->$k;
            $agv = @$gi->autogrades->$k;
            if ($ggv !== null && $ggv !== "")
                $gv = $ggv;
            else if ($agv !== null && $agv !== "")
                $gv = $agv;
        }
        if ($gv != "" && !$ge->no_total) {
            $total += $gv;
            $lastintotal = count($garr);
        }
        if ($gv === "" && !$ge->is_extra && $s
            && $Me->contactId == $s->gradercid)
            $s->incomplete = true;
        $gv = htmlspecialchars($gv);
        if ($ggv && $agv && $ggv != $agv)
            $gv = "<span style=\"color:red\">$gv</span>";
        $garr[] = $gv;
    }
    if ($nintotal > 1) {
        array_unshift($garr, '<strong>' . $total . '</strong>');
        $lastintotal = 0;
    } else if ($nintotal == 1 && $lastintotal !== null)
        $garr[$lastintotal] = '<strong>' . $garr[$lastintotal] . '</strong>';
    return (object) array("all" => $garr, "totalindex" => $lastintotal,
                          "maxtotal" => $max);
}

function show_pset($pset, $user) {
    global $Me;
    if ($pset->gitless_grades && $Me == $user && !$pset->partner
        && !$user->contact_grade($pset))
        return;
    echo "<hr/>\n";
    $pseturl = hoturl("pset", array("pset" => $pset->urlkey,
                                    "u" => $Me->user_linkpart($user),
                                    "sort" => @$_REQUEST["sort"]));
    echo "<h2><a href=\"", $pseturl, "\">",
        htmlspecialchars($pset->title), "</a>";
    $info = ContactView::user_pset_info($user, $pset);
    $grade_check_user = $Me->isPC && $Me != $user ? $user : $Me;
    $can_grade = $grade_check_user->can_see_grades($pset, $user, $info);
    if ($can_grade && $info->has_grading())
        echo ' <a class="gradesready" href="', $pseturl, '">(grade ready)</a>';
    echo "</a></h2>";
    ContactView::echo_partner_group($info);
    ContactView::echo_repo_group($info);
    if ($info->repo)
        Contact::check_repo($info->repo, 30);
    if ($info->has_grading()) {
        ContactView::echo_repo_grade_commit_group($info);
        if ($can_grade && ($gi = $info->grading_info())) {
            $garr = render_grades($pset, $gi, null);
            if ($garr->totalindex !== null) {
                $t = $garr->all[$garr->totalindex] . " / " . $garr->maxtotal;
                ContactView::echo_group("grade", $t);
            }
        }
        ContactView::echo_repo_regrades_group($info);
    } else
        ContactView::echo_repo_last_commit_group($info, true);
}

if (!$Me->is_empty() && $User->is_student()) {
    $Conf->footerScript("peteramati_uservalue=" . json_encode($Me->user_linkpart($User)));
    foreach (ContactView::pset_list(false, true) as $pset)
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

function render_pset_row($pset, $students, $s, $row, $pcmembers) {
    global $Me, $Now, $Profile;
    $row->sortprefix = "";
    $ncol = 0;
    $t0 = $Profile ? microtime(true) : 0;

    $t = "<td class=\"s61username\">";
    if ($pset->anonymous)
        $x = $s->anon_username;
    else
        $x = $s->seascode_username ? : ($s->huid ? : $s->email);
    $t .= "<a href=\"" . hoturl("pset", array("pset" => $pset->urlkey, "u" => $x, "sort" => @$_REQUEST["sort"])) . "\">"
        . htmlspecialchars($x) . "</a>";
    if ($Me->privChair)
        $t .= "&nbsp;" . become_user_link($x, Text::name_html($s));
    $t .= "</td>";
    ++$ncol;

    if (!$pset->anonymous) {
        $t .= "<td>" . Text::name_html($s) . ($s->extension ? " (X)" : "") . "</td>";
        ++$ncol;
    }

    $t .= "<td>";
    if ($s->gradercid) {
        if (isset($pcmembers[$s->gradercid]))
            $t .= htmlspecialchars($pcmembers[$s->gradercid]->firstName);
        else
            $t .= "???";
    }
    $t .= "</td>";
    ++$ncol;

    // are any commits committed?
    if (!$pset->gitless_grades) {
        if (($s->placeholder || $s->gradehash === null)
            && $s->repoid
            && ($s->placeholder_at < $Now - 3600 && rand(0, 2) == 0
                || ($s->placeholder_at < $Now - 600 && rand(0, 10) == 0))
            && (!$s->repoviewable || !$s->gradehash)) {
            // XXX this is slow given that most info is already loaded
            $info = ContactView::user_pset_info($s, $pset);
            $info->set_commit(null);
            $s->gradehash = $info->commit_hash() ? : null;
            $s->placeholder = 1;
            Dbl::qe("insert into RepositoryGrade (repoid, pset, gradehash, placeholder, placeholder_at) values (?, ?, ?, 1, ?) on duplicate key update gradehash=(if(placeholder=1,values(gradehash),gradehash)), placeholder_at=values(placeholder_at)",
                    $s->repoid, $pset->id, $s->gradehash, $Now);
            if (!$s->repoviewable)
                $s->repoviewable = $s->can_view_repo_contents($s);
        }
        if (!$s->gradehash || $s->dropped)
            $row->sortprefix = "~1 ";
    }

    if (count($pset->grades)) {
        if ($pset->gitless_grades) {
            $gi = Contact::contact_grade_for($s, $pset);
            $gi = $gi ? $gi->notes : null;
        } else if ($s->gradehash && !$s->placeholder)
            $gi = $Me->commit_info($s->gradehash, $pset);
        else
            $gi = null;

        if (!$pset->gitless_grades) {
            $t .= "<td>";
            if ($gi && @$gi->linenotes)
                $t .= "♪";
            else if ($Me->contactId == $s->gradercid)
                $s->incomplete = true;
            if ($gi && $s->gradercid != @$gi->gradercid && $Me->privChair)
                $t .= "<sup>*</sup>";
            $t .= "</td>";
            ++$ncol;
        }

        $garr = render_grades($pset, $gi, $s);
        $t .= '<td class="r">' . join('</td><td class="r">', $garr->all) . '</td>';
        $ncol += count($garr->all);
    }

    //echo "<td><a href=\"mailto:", htmlspecialchars($s->email), "\">",
    //htmlspecialchars($s->email), "</a></td>";

    $t .= "<td>";
    if ($s->url) {
        $t .= $s->repo_link($s->url, "repo");
        if (!$s->working)
            $t .= ' <strong class="err">broken</strong>';
        else if (!$s->repoviewable)
            $t .= ' <strong class="err">unconfirmed</strong>';
        if ($s->open)
            $t .= ' <strong class="err">open</strong>';
        if ($s->pcid != $s->rpcid
            || ($s->pcid && (!isset($students[$s->pcid])
                             || $students[$s->pcid]->repoid != $s->repoid)))
            $t .= ' <strong class="err">partner</strong>';
    }
    $t .= "</td>";
    ++$ncol;

    if ($Profile) {
        $t .= sprintf("<td class=\"r\">%.06f</td>", microtime(true) - $t0);
        ++$ncol;
    }

    if (!@$row->ncol || $ncol > $row->ncol)
        $row->ncol = $ncol;

    $s->printed = true;
    return $t;
}

function show_regrades($result) {
    global $Conf, $Me, $Now, $LastPsetFix;
    $rows = array();

    echo '<div id="_regrades">';
    echo "<h3>regrade requests</h3>";
    echo '<table class="s61"><tbody>';
    $trn = 0;
    $checkbox = false;
    $sprefix = "";
    $pcmembers = pcMembers();
    while (($row = edb_orow($result))) {
        ++$trn;
        echo '<tr class="k', ($trn % 2), '">';
        if ($checkbox)
            echo '<td class="s61rownumber">', Ht::checkbox("s61_" . $Me->user_idpart($row->student), 1, array("class" => "s61check")), '</td>';
        echo '<td class="s61rownumber">', $trn, '.</td>';
        $pset = Pset::$all[$row->pset];
        echo '<td class="s61pset">', htmlspecialchars($pset->title), '</td>';

        $row->usernames = explode(" ", $row->usernames);
        sort($row->usernames);
        $x = array();
        foreach ($row->usernames as $u)
            $x[] = '<a href="' . hoturl("pset", array("pset" => $pset->urlkey, "u" => $u, "commit" => $row->hash, "sort" => @$_REQUEST["sort"])) . '">' . htmlspecialchars($u) . '</a>';
        echo '<td class="s61username">', join(", ", $x), '</td>';
        echo '<td class="s61hash"><a href="', hoturl("pset", array("pset" => $pset->urlkey, "u" => $row->usernames[0], "commit" => $row->hash, "sort" => @$_REQUEST["sort"])), '">', substr($row->hash, 0, 7), '</a></td>';

        if (@$row->gradercid || @$row->main_gradercid) {
            $gcid = @$row->gradercid ? : $row->main_gradercid;
            if (isset($pcmembers[$gcid]))
                echo "<td>" . htmlspecialchars($pcmembers[$gcid]->firstName) . "</td>";
            else
                echo "<td>???</td>";
        } else
            echo "<td></td>";

        if ($row->haslinenotes)
            echo "<td>♪</td>";
        else
            echo "<td></td>";

        $total = "";
        if ($row->notes) {
            $garr = render_grades($pset, json_decode($row->notes), null);
            if ($garr->totalindex !== null)
                $total = $garr->all[$garr->totalindex];
        }
        echo '<td class="r">' . $total . '</td>';

        echo '</tr>';
    }
    echo "</tbody></table></div>\n";
}

function show_pset_actions($pset) {
    global $Conf;

    echo Ht::form_div(hoturl_post("index", array("pset" => $pset->urlkey, "reconfig" => 1)), array("style" => "margin-bottom:1em"));
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
    $Conf->footerScript("function reconfig(e, what) { var j = $(e).closest(\"form\"); j.find('input[name=\"action\"]').val(what); j.submit(); }", "reconfig");
    echo Ht::select("state", $options, $state, array("onchange" => "reconfig(this, 'setstate')")),
        Ht::hidden("action", "");

    if (!$pset->disabled && $pset->visible) {
        echo ' &nbsp;<span class="barsep">·</span>&nbsp; ';
        echo Ht::select("frozen", array("no" => "Student updates allowed", "yes" => "Submissions frozen"), $pset->frozen ? "yes" : "no", array("onchange" => "reconfig(this, 'setfrozen')"));
    }

    if (!$pset->disabled) {
        echo ' &nbsp;<span class="barsep">·</span>&nbsp; ';
        echo Ht::select("anonymous", array("no" => "Open grading", "yes" => "Anonymous grading"), $pset->anonymous ? "yes" : "no", array("onchange" => "reconfig(this, 'setanonymous')"));

        echo ' &nbsp;<span class="barsep">·</span>&nbsp; ';
        echo Ht::js_button("Grade report", "window.location=\"" . hoturl_post("index", ["pset" => $pset->urlkey, "report" => 1]) . "\"");
    }

    echo "</div></form>";
}

function show_pset_table($pset) {
    global $Conf, $Me, $Now, $Opt, $Profile, $LastPsetFix;

    echo '<div id="', $pset->urlkey, '">';
    echo "<h3>", htmlspecialchars($pset->title), "</h3>";
    if ($Me->privChair)
        show_pset_actions($pset);
    if ($pset->disabled)
        return;

    $t0 = $Profile ? microtime(true) : 0;

    // load students
    if (@$Opt["restrictRepoView"]) {
        $view = "l2.link repoviewable";
        $viewjoin = "left join ContactLink l2 on (l2.cid=c.contactId and l2.type=" . LINK_REPOVIEW . " and l2.link=l.link)\n";
    } else {
        $view = "4 repoviewable";
        $viewjoin = "";
    }
    $result = Dbl::qe("select c.contactId, c.firstName, c.lastName, c.email,
	c.huid, c.seascode_username, c.anon_username, c.extension, c.disabled, c.dropped, c.roles, c.contactTags,
	pl.link pcid, group_concat(rpl.link) rpcid,
	r.repoid, r.cacheid, r.heads, r.url, r.open, r.working, r.lastpset, r.snapcheckat, $view,
	rg.gradehash, rg.gradercid, rg.placeholder, rg.placeholder_at
	from ContactInfo c
	left join ContactLink l on (l.cid=c.contactId and l.type=" . LINK_REPO . " and l.pset=$pset->id)
	$viewjoin
	left join Repository r on (r.repoid=l.link)
	left join ContactLink pl on (pl.cid=c.contactId and pl.type=" . LINK_PARTNER . " and pl.pset=$pset->id)
	left join ContactLink rpl on (rpl.cid=c.contactId and rpl.type=" . LINK_BACKPARTNER . " and rpl.pset=$pset->id)
	left join RepositoryGrade rg on (rg.repoid=r.repoid and rg.pset=$pset->id)
	where (c.roles&" . Contact::ROLE_PCLIKE . ")=0
	and (rg.repoid is not null or not c.dropped)
	group by c.contactId");
    $t1 = $Profile ? microtime(true) : 0;

    $students = array();
    while ($result && ($s = $result->fetch_object("Contact"))) {
        $s->is_anonymous = $pset->anonymous;
        Contact::set_sorter($s, @$_REQUEST["sort"]);
        $students[$s->contactId] = $s;
        // maybe lastpset links are out of order
        if ($s->lastpset < $pset)
            $LastPsetFix = true;
    }
    uasort($students, "Contact::compare");

    $checkbox = $Me->privChair
        || (!$pset->gitless && $pset->runners);

    $rows = array();
    $max_ncol = 0;
    $incomplete = array();
    $pcmembers = pcMembers();
    foreach ($students as $s)
        if (!isset($s->printed)) {
            $row = (object) array("student" => $s);
            $row->text = render_pset_row($pset, $students, $s, $row, $pcmembers);
            if ($s->pcid && isset($students[$s->pcid]))
                $row->ptext = render_pset_row($pset, $students, $students[$s->pcid], $row, $pcmembers);
            $rows[$row->sortprefix . $s->sorter] = $row;
            $max_ncol = max($max_ncol, $row->ncol);
            if (@$s->incomplete) {
                $u = $Me->user_linkpart($s);
                $incomplete[] = '<a href="' . hoturl("pset", array("pset" => $pset->urlkey, "u" => $u, "sort" => @$_REQUEST["sort"])) . '">'
                    . htmlspecialchars($u) . '</a>';
            }
        }
    ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);

    if (count($incomplete)) {
        echo '<div id="incomplete_pset', $pset->id, '" style="display:none" class="merror">',
            '<strong>', htmlspecialchars($pset->title), '</strong>: ',
            'Your grading is incomplete. Missing grades: ', join(", ", $incomplete), '</div>',
            '<script>jQuery("#incomplete_pset', $pset->id, '").remove().show().appendTo("#incomplete_notices")</script>';
    }

    if ($checkbox)
        echo Ht::form_div(hoturl_post("index", array("pset" => $pset->urlkey, "save" => 1)));
    echo '<table class="s61"><tbody>';
    $trn = 0;
    $sprefix = "";
    foreach ($rows as $row) {
        ++$trn;
        if ($row->sortprefix !== $sprefix && $row->sortprefix[0] == "~")
            echo "\n", '<tr><td colspan="' . ($max_ncol + ($checkbox ? 2 : 1)) . '"><hr></td></tr>', "\n";
        $sprefix = $row->sortprefix;
        echo '<tr class="k', ($trn % 2), '">';
        if ($checkbox)
            echo '<td class="s61rownumber">', Ht::checkbox("s61_" . $Me->user_idpart($row->student), 1, array("class" => "s61check")), '</td>';
        echo '<td class="s61rownumber">', $trn, '.</td>', $row->text, "</tr>\n";
        if (@$row->ptext) {
            echo '<tr class="k', ($trn % 2), ' s61partner">';
            if ($checkbox)
                echo '<td></td>';
            echo '<td></td>', $row->ptext, "</tr>\n";
        }
    }
    echo "</tbody></table>\n";

    if ($Me->privChair && !$pset->gitless_grades) {
        echo "<div class='g'></div>";
        $sel = array("none" => "N/A");
        foreach (pcMembers() as $pcm)
            $sel[$pcm->email] = Text::name_html($pcm);
        $sel["__random__"] = "Random";
        echo '<span class="nowrap" style="padding-right:2em">',
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
            echo '<span class="nowrap" style="padding-right:2em">',
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

    $result = Dbl::qe("select rgr.*, rg.gradercid main_gradercid,
	cn.notes, cn.haslinenotes, u.cids, u.usernames
	from RepositoryGradeRequest rgr
	left join CommitNotes cn on (cn.hash=rgr.hash and cn.pset=rgr.pset)
	left join RepositoryGrade rg on (rg.repoid=rgr.repoid and rg.pset=rgr.pset and not rg.placeholder)
	left join (select link repoid, pset, group_concat(cid separator ' ') as cids, group_concat(seascode_username separator ' ') as usernames
		   from ContactLink l
		   join ContactInfo c on (l.cid=c.contactId and l.type=" . LINK_REPO . ")
		   group by repoid, pset) u
	on (rgr.repoid=u.repoid and rgr.pset=u.pset)
	order by requested_at");
    if (edb_nrows($result)) {
        echo $sep;
        show_regrades($result);
        if ($Profile)
            echo "<div>Δt ", sprintf("%.06f", microtime(true) - $t0), "</div>";
        $sep = "<hr />\n";
    }

    foreach (ContactView::pset_list($Me, true) as $pset) {
        echo $sep;
        show_pset_table($pset, $Me);
        $sep = "<hr />\n";
    }
    Ht::stash_script("$('.s61check').click(click_s61check)");

    if ($LastPsetFix) {
        $Conf->log("Repository.lastpset links are bogus", $Me);
        Contact::update_all_repo_lastpset();
    }
}

echo "<div class='clear'></div>\n";
$Conf->footer();
