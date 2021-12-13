<?php
// face.php -- Peteramati face page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
ContactView::set_path_request(array("/u"));
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info;

$User = $Me;
if (isset($Qreq->u)) {
    $User = ContactView::prepare_user($Qreq->u, $Me);
}

if (isset($Qreq->imageid)) {
    if ($User
        && ($User === $Me || $Me->isPC)
        && $Qreq->imageid
        && ($result = Dbl::qe("select mimetype, `data` from ContactImage where contactId=? and contactImageId=?", $User->contactId, $Qreq->imageid))
        && ($row = $result->fetch_row())) {
        header("Content-Type: $row[0]");
        header("Cache-Control: public, max-age=31557600");
        header("Expires: " . gmdate("D, d M Y H:i:s", Conf::$now + 31557600) . " GMT");
        if (zlib_get_coding_type() === false) {
            header("Content-Length: " . strlen($row[1]));
        }
        print $row[1];
    } else {
        header("Content-Type: image/gif");
        if (zlib_get_coding_type() === false) {
            header("Content-Length: 43");
        }
        print "GIF89a\001\0\001\0\x80\0\0\0\0\0\0\0\0\x21\xf9\x04\x01\0\0\0\0\x2c\0\0\0\0\x01\0\x01\0\0\x02\x02\x44\x01\0\x3b";
    }
    exit;
}

if (!$Me->isPC)
    $Me->escape();

/** @param Contact $user
 * @param Contact $viewer */
function face_output($user, $viewer) {
    $u = $viewer->user_linkpart($user);
    echo '<div class="pa-facebook-entry">',
        '<a href="', $viewer->conf->hoturl("index", ["u" => $u]), '">',
        '<img class="pa-face" src="' . $viewer->conf->hoturl("face", ["u" => $u, "imageid" => $user->contactImageId ? : 0]) . '" border="0" />',
        '</a>',
        '<h2><a class="q" href="', $viewer->conf->hoturl("index", ["u" => $u]), '">', htmlspecialchars($u), '</a>';
    if ($viewer->privChair) {
        echo "&nbsp;", become_user_link($user);
    }
    echo '</h2>';
    if ($user !== $viewer) {
        echo Text::name_html($user),
            ($user->extension ? " (X)" : ""),
            ($user->email ? " &lt;" . htmlspecialchars($user->email) . "&gt;" : "");
    }
    echo '</div>';
}

$Conf->header("Thefacebook", "face");

$result = Dbl::qe("select contactId, email, firstName, lastName, github_username, contactImageId, extension from ContactInfo where roles=0");
echo '<div class="pa-facebook">';
while (($user = Contact::fetch($result, $Conf))) {
    face_output($user, $Me);
}
echo '</div>';

$Conf->footer();
