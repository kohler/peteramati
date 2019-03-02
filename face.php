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
if (isset($Qreq->u))
    $User = ContactView::prepare_user($Qreq->u);

if (isset($Qreq->imageid)) {
    if ($User
        && ($User === $Me || $Me->isPC)
        && $Qreq->imageid
        && ($result = Dbl::qe("select mimetype, `data` from ContactImage where contactId=? and contactImageId=?", $User->contactId, $Qreq->imageid))
        && ($row = edb_row($result))) {
        header("Content-Type: $row[0]");
        header("Cache-Control: public, max-age=31557600");
        header("Expires: " . gmdate("D, d M Y H:i:s", $Now + 31557600) . " GMT");
        if (!$zlib_output_compression)
            header("Content-Length: " . strlen($row[1]));
        print $row[1];
    } else {
        header("Content-Type: image/gif");
        if (!$zlib_output_compression)
            header("Content-Length: 43");
        print "GIF89a\001\0\001\0\x80\0\0\0\0\0\0\0\0\x21\xf9\x04\x01\0\0\0\0\x2c\0\0\0\0\x01\0\x01\0\0\x02\x02\x44\x01\0\x3b";
    }
    exit;
}

if (!$Me->isPC)
    $Me->escape();

function output($User) {
    global $Me;
    $u = $Me->user_linkpart($User);
    echo '<div class="pa-facebook-entry">',
        '<a href="', hoturl("index", ["u" => $u]), '">',
        '<img class="pa-face" src="' . hoturl("face", ["u" => $u, "imageid" => $User->contactImageId ? : 0]) . '" border="0" />',
        '</a>',
        '<h2><a class="q" href="', hoturl("index", ["u" => $u]), '">', htmlspecialchars($u), '</a>';
    if ($Me->privChair)
        echo "&nbsp;", become_user_link($User);
    echo '</h2>';
    if ($User !== $Me)
        echo Text::name_html($User),
            ($User->extension ? " (X)" : ""),
            ($User->email ? " &lt;" . htmlspecialchars($User->email) . "&gt;" : "");
    echo '</div>';
}

$Conf->header("Thefacebook", "face");

$result = Dbl::qe("select contactId, email, firstName, lastName, github_username, contactImageId, extension from ContactInfo where roles=0");
echo '<div class="pa-facebook">';
while (($user = Contact::fetch($result, $Conf)))
    output($user);
echo '</div>';

$Conf->footer();
