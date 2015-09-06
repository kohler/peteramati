<?php
// diff.php -- HotCRP diff page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
ContactView::set_path_request(array("/u"));
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Info;

$User = $Me;
if (isset($_REQUEST["u"]))
    $User = ContactView::prepare_user($_REQUEST["u"]);

if ($User
    && ($User === $Me || $Me->isPC)
    && $User->contactImageId
    && ($result = Dbl::qe("select mimetype, `data` from ContactImage where contactImageId=?", $User->contactImageId))
    && ($row = edb_row($result))) {
    $age = 3600;
    if (@$_GET["imageid"] == $User->contactImageId)
        $age = 31557600;
    header("Content-Type: $row[0]");
    header("Cache-Control: public, max-age=$age");
    header("Expires: " . gmdate("D, d M Y H:i:s", $Now + $age) . " GMT");
    if (!$zlib_output_compression)
        header("Content-Length: " . strlen($row[1]));
    print $row[1];
} else {
    error_log($User ? "!" : ".");
    error_log($User->contactImageId);
    header("Content-Type: image/gif");
    if (!$zlib_output_compression)
        header("Content-Length: 43");
    print "GIF89a\001\0\001\0\x80\0\0\0\0\0\0\0\0\x21\xf9\x04\x01\0\0\0\0\x2c\0\0\0\0\x01\0\x01\0\0\x02\x02\x44\x01\0\x3b";
}
