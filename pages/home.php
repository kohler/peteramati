<?php
// home.php -- Peteramati home page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");

// access only allowed through index.php
if (!$Conf) {
    exit(0);
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
    && $Qreq->email
    && (!$Qreq->valid_token() || strcasecmp($Me->email, trim($Qreq->email)) === 0)) {
    unset($_REQUEST["signin"]);
}
if (!isset($_REQUEST["email"]) || !isset($_REQUEST["action"])) {
    unset($_REQUEST["signin"]);
}
// signout
if (isset($_REQUEST["signout"])) {
    $Me = LoginHelper::logout($Me, $Qreq, true);
} else if (isset($_REQUEST["signin"]) && !$Conf->opt("httpAuthLogin")) {
    $Me = LoginHelper::logout($Me, $Qreq, false);
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
if (!$Me->isPC) {
    $User = $Me;
}

// check problem set openness
if ($User
    && ($Me === $User || $Me->isPC)
    && $Qreq->set_username
    && $Qreq->valid_post()
    && ($repoclass = RepositorySite::$sitemap[$Qreq->reposite ?? ""])
    && in_array($repoclass, RepositorySite::site_classes($Conf), true)) {
    if ($repoclass::save_username($User, $Qreq->username)) {
        $Conf->redirect_self($Qreq);
    }
}

if ($User
    && $Qreq->set_partner !== null) {
    ContactView::set_partner_action($User, $Me, $Qreq);
}

if ($User
    && (isset($Qreq->set_drop) || isset($Qreq->set_undrop))
    && $Me->isPC
    && $User->is_student()
    && $Qreq->valid_post()) {
    $Conf->qe("update ContactInfo set dropped=? where contactId=?",
              isset($Qreq->set_drop) ? Conf::$now : 0, $User->contactId);
    $Conf->qe("delete from Settings where name like '__gradets.%'");
    $Conf->redirect_self($Qreq);
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
if ($Me->privChair && !$User) {
    echo "<div id='homeadmin'>
  <h3>administration</h3>
  <ul>
    <!-- <li><a href='", $Conf->hoturl("settings"), "'>Settings</a></li>
    <li><a href='", $Conf->hoturl("users", ["t" => "all"]), "'>Users</a></li> -->
    <li><a href='", $Conf->hoturl("mail"), "'>Send mail</a></li>\n";

    $pclike = Contact::ROLE_PCLIKE;
    $result = $Conf->qe("select exists (select * from ContactInfo where password='' and disabled=0 and (roles=0 or (roles&$pclike)=0) and college=1), exists (select * from ContactInfo where password='' and disabled=0 and (roles=0 or (roles&$pclike)=0) and extension=1), exists (select * from ContactInfo where password='' and disabled=0 and roles!=0 and (roles&$pclike)!=0), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and (roles=0 or (roles&$pclike)=0) and lastLogin=0 and college=1), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and (roles=0 or (roles&$pclike)=0) and lastLogin=0 and extension=1), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and roles!=0 and (roles&$pclike)!=0 and lastLogin=0)");
    $row = $result->fetch_row();
    '@phan-var-force list<?string> $row';
    Dbl::free($result);

    $m = [];
    if ($row[0]) {
        $m[] = '<a href="' . $Conf->hoturl("=index", ["enable_user" => "college-empty"]) . '">college users</a>';
    }
    if ($row[1]) {
        $m[] = '<a href="' . $Conf->hoturl("=index", ["enable_user" => "extension-empty"]) . '">extension users</a>';
    }
    if ($row[2]) {
        $m[] = '<a href="' . $Conf->hoturl("=index", ["enable_user" => "ta-empty"]) . '">TAs</a>';
    }
    if (!empty($m)) {
        echo '    <li>Enable ', join(", ", $m), "</li>\n";
    }

    $m = [];
    if ($row[3])
        $m[] = '<a href="' . $Conf->hoturl("=index", ["send_account_info" => "college-nologin"]) . '">college users</a>';
    if ($row[4])
        $m[] = '<a href="' . $Conf->hoturl("=index", ["send_account_info" => "extension-nologin"]) . '">extension users</a>';
    if ($row[5])
        $m[] = '<a href="' . $Conf->hoturl("=index", ["send_account_info" => "ta-nologin"]) . '">TAs</a>';
    if (!empty($m))
        echo '    <li>Send account info to ', join(", ", $m), "</li>\n";

    echo "    <li><a href='", $Conf->hoturl("=index", ["report" => "nonanonymous"]), "'>Overall grade report</a> (<a href='", $Conf->hoturl("=index", ["report" => "nonanonymous college"]), "'>college</a>, <a href='", $Conf->hoturl("=index", ["report" => "nonanonymous extension"]), "'>extension</a>)</li>
    <!-- <li><a href='", $Conf->hoturl("log"), "'>Action log</a></li> -->
  </ul>
</div>\n";
}

if ($Me->isPC && !$User) {
    $a = [];
    foreach ($Conf->psets_newest_first() as $pset) {
        if ($Me->can_view_pset($pset) && !$pset->disabled)
            $a[] = '<a class="ulh pset-title" href="#' . $pset->urlkey . '">' . htmlspecialchars($pset->title) . '</a>';
    }
    if (!empty($a)) {
        echo '<div class="home-pset-links">', join(" • ", $a), '</div>';
    }
}

// Home message
if (($v = $Conf->setting_data("homemsg"))) {
    $Conf->infoMsg($v);
}
if ($Me->privChair) {
    $gc = new GradeFormulaCompiler($Conf);
    $gc->check_all();
    if ($gc->ms->has_problem()) {
        $Conf->errorMsg($gc->ms->full_feedback_html());
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
        '<div class="f-contain foldo fold2o" id="logingroup">',
        Ht::hidden("cookie", 1),
        '<div class="', Ht::control_class("email", "f-i"), '">',
        '<label for="email">',
        '<span class="fx2">', $Conf->opt("ldapLogin") ? "Username" : "Email/username",
        '</span><span class="fn2">Email</span></label>',
        Ht::entry("email", $Qreq->email, ["size" => 36, "autofocus" => !$passwordFocus, "autocomplete" => "email"]),
        Ht::feedback_html_at("email"),
        '</div>',
        '<div class="', Ht::control_class("password", "f-i fx"), '">',
        '<label for="password">Password</label>',
        Ht::password("password", "", ["size" => 36, "autofocus" => $passwordFocus, "autocomplete" => "current-password"]),
        Ht::feedback_html_at("password"),
        '</div></div>';
    if ($Conf->opt("ldapLogin")) {
        echo "<input type='hidden' name='action' value='login' />\n";
    } else {
        echo '<div class="mb-4">',
            '<label class="checki"><span class="checkc">',
                Ht::radio("action", "login", true, ["id" => "signin_action_login", "class" => "uic pa-signin-radio"]),
            '</span><strong>Sign me in</strong></label>',
            '<label class="checki"><span class="checkc">',
                Ht::radio("action", "forgot", false, ["class" => "uic pa-signin-radio"]),
            '</span>I forgot my password</label>';
        if (!$Conf->opt("disableNewUsers")) {
            echo '<label class="checki"><span class="checkc">',
                    Ht::radio("action", "new", false, ["id" => "signin_action_new", "class" => "uic pa-signin-radio"]),
                '</span>Create an account</label>';
        }
        echo '</div>';
        Ht::stash_script("$('#homeaccount input[name=action]:checked').click()");
    }
    echo '<div class="f-i"><button class="btn btn-primary" type="submit" name="signin" id="signin" tabindex="1" value="1">Sign in</button></div></div></form>',
        '<hr class="home"></div>', "\n";
}


// Top: user info
if (!$Me->is_empty() && $User) {
    echo "<div id='homeinfo'>";
    $u = $Me->user_linkpart($User);
    if ($User !== $Me && !$User->is_anonymous && $User->contactImageId) {
        echo '<img class="pa-face float-left" src="' . $Conf->hoturl("face", ["u" => $Me->user_linkpart($User), "imageid" => $User->contactImageId]) . '" />';
    }
    if ($u) {
        echo '<h2 class="homeemail"><a class="q" href="',
            $Conf->hoturl("index", ["u" => $u]), '">', htmlspecialchars($u), '</a>';
    }
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
if ($Me->is_empty()) {
    // render nothing
} else if ($User) {
    $hp = new Home_Student_Page($User, $Me);
    $hp->render_default();
} else if ($Me->isPC) {
    $hp = new Home_TA_Page($Me, $Qreq);
    $hp->profile = $Me->privChair && $Qreq->profile;
    $hp->render_default();
}

echo "<div class='clear'></div>\n";
$Conf->footer();
