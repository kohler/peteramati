<?php
// profile.php -- Peteramati user profile page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
global $User, $Pset, $Info, $Commit;
ContactView::set_path_request($Qreq, ["/u"], $Conf);
if ($Me->is_empty()) {
    $Me->escape();
}

$User = $Me;
if (isset($Qreq->u)
    && (!$Me->isPC
        || !($User = ContactView::prepare_user($Qreq, $Me))))
    $Conf->redirect_self($Qreq, ["u" => null]);
assert($User == $Me || $Me->isPC);
assert($Me->privChair);


if (@$_POST["enable"] && $Qreq->valid_post()) {
    UserActions::enable(array($User->contactId), $Me);
    $Conf->redirect_self($Qreq);
}
if (@$_POST["disable"] && $Qreq->valid_post()) {
    UserActions::disable(array($User->contactId), $Me);
    $Conf->redirect_self($Qreq);
}
if (@$_POST["update"] && $Qreq->valid_post()) {
    $ck = $cv = array();

    $roles = 0;
    if (@$_POST["pctype"] === "chair")
        $roles |= Contact::ROLE_CHAIR | Contact::ROLE_PC;
    else if (@$_POST["pctype"] === "pc")
        $roles |= Contact::ROLE_PC;
    if (@$_POST["sysadmin"])
        $roles |= Contact::ROLE_ADMIN;
    $ck[] = "roles=$roles";

    Dbl::qe_apply("update ContactInfo set " . join(",", $ck) . " where contactId=" . $User->contactId, $cv);

    $Conf->redirect_self($Qreq);
}


$Conf->header("Profile", "profile");
$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";



echo "<div id='homeinfo'>";
echo "<h2 class='homeemail'>", Text::user_html($User), "</h2>";
if ($User->username || $User->huid) {
    echo '<h3><a href="', $Conf->hoturl("index", array("u" => $Me->user_linkpart($User))), '">', htmlspecialchars($User->username ? : $User->huid), '</a>';
    if ($Me->privChair) {
        echo "&nbsp;", become_user_link($User);
    }
    echo "</h3>";
}
if ($User->dropped)
    ContactView::echo_group("", '<strong class="err">You have dropped the course.</strong> If this is incorrect, contact us.');


echo Ht::form($Conf->hoturl("=profile", array("u" => $User->email))), "<div>";
if ($User->is_disabled() || $User->password == "") {
    echo Ht::submit("enable", "Enable user", array("value" => 1));
} else {
    echo Ht::submit("disable", "Disable user", array("value" => 1));
}
echo '<hr>';

echo '<table class="pltable" style="margin-top:1em"><tbody class="pltable pltable_alternate">';

echo '<tr><td class="pl pls">Roles</td><td class="pl">';
echo Ht::radio("pctype", "chair", !!($User->roles & Contact::ROLE_CHAIR)),
    "&nbsp;", Ht::label("Course instructor"), "<br>";
echo Ht::radio("pctype", "pc", ($User->roles & Contact::ROLE_PC) && !($User->roles & Contact::ROLE_CHAIR)),
    "&nbsp;", Ht::label("Course staff"), "<br>";
echo Ht::radio("pctype", "no", !($User->roles & (Contact::ROLE_PC | Contact::ROLE_CHAIR))),
    "&nbsp;", Ht::label("Not on course staff"), "<br>";
echo "</td></tr>\n";

echo '</tbody></table>';

echo Ht::submit("update", "Save changes", array("value" => 1));

echo "</div></form>\n";


echo "<div class='clear'></div>\n";
$Conf->footer();
