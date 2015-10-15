<?php
// face.php -- Peteramati face page
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
ContactView::set_path_request(array("/u"));
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info;

$User = $Me;
if (isset($_REQUEST["u"]))
    $User = ContactView::prepare_user($_REQUEST["u"]);

if (isset($_REQUEST["imageid"])) {
    if ($User
        && ($User === $Me || $Me->isPC)
        && $_REQUEST["imageid"]
        && ($result = Dbl::qe("select mimetype, `data` from ContactImage where contactId=? and contactImageId=?", $User->contactId, $_REQUEST["imageid"]))
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
    echo '<div class="facebook61">',
        '<a href="', hoturl("index", ["u" => $u]), '">',
        '<img class="bigface61" src="' . hoturl("face", ["u" => $u, "imageid" => $User->contactImageId ? : 0]) . '" border="0" />',
        '</a>',
        '<h2 class="infacebook61"><a class="q" href="', hoturl("index", ["u" => $u]), '">', htmlspecialchars($u), '</a>';
    if ($Me->privChair)
        echo "&nbsp;", become_user_link($User);
    echo '</h2>';
    if ($User !== $Me)
        echo '<h3 class="infacebook61">', Text::user_html($User), '</h3>';
    echo '</div>';
}

$Conf->header("Thefacebook", "face");

$u = Dbl::qe("select contactId, email, firstName, lastName, seascode_username, contactImageId from ContactInfo where college!=0 or extension!=0");
while (($user = edb_orow($u)))
    output($user);

echo "<div class='clear'></div>\n";
$Conf->footer();
