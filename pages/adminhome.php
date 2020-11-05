<?php
// homeadmin.php -- HotCRP home page administrative messages
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

// access only allowed through index.php
if (!$Conf)
    exit();

function admin_home_messages($conf) {
    global $Opt;
    $m = array();
    $errmarker = "<span class=\"error\">Error:</span> ";
    if (preg_match("/^(?:[1-4]\\.|5\\.[012])/", phpversion()))
        $m[] = $errmarker . "HotCRP requires PHP version 5.3 or higher.  You are running PHP version " . htmlspecialchars(phpversion()) . ".";
    if (defined("JSON_HOTCRP"))
        $m[] = "Your PHP was built without JSON functionality. HotCRP is using its built-in replacements; the native functions would be faster.";
    if ((int) ini_get("session.gc_maxlifetime") < ($conf->opt("sessionLifetime") ?? 86400)
        && !isset($conf->opt["sessionHandler"]))
        $m[] = "PHP’s systemwide <code>session.gc_maxlifetime</code> setting, which is " . htmlspecialchars(ini_get("session.gc_maxlifetime")) . " seconds, is less than HotCRP’s preferred session expiration time, which is " . ($conf->opt("sessionLifetime") ?? 86400) . " seconds.  You should update <code>session.gc_maxlifetime</code> in the <code>php.ini</code> file or users may be booted off the system earlier than you expect.";
    if (!function_exists("imagecreate"))
        $m[] = $errmarker . "This PHP installation lacks support for the GD library, so HotCRP cannot generate score charts (as backup for browsers that don’t support &lt;canvas&gt;). You should update your PHP installation. For example, on Ubuntu Linux, install the <code>php5-gd</code> package.";
    $result = $conf->qe("show variables like 'max_allowed_packet'");
    $max_file_size = ini_get_bytes("upload_max_filesize");
    if (($row = $result->fetch_row())
        && $row[1] < $max_file_size
        && !@$Opt["dbNoPapers"])
        $m[] = $errmarker . "MySQL’s <code>max_allowed_packet</code> setting, which is " . htmlspecialchars($row[1]) . "&nbsp;bytes, is less than the PHP upload file limit, which is $max_file_size&nbsp;bytes.  You should update <code>max_allowed_packet</code> in the system-wide <code>my.cnf</code> file or the system may not be able to handle large papers.";
    // Conference names
    if (@$Opt["shortNameDefaulted"])
        $m[] = "<a href=\"" . hoturl("settings", "group=msg") . "\">Set the conference abbreviation</a> to a short name for your conference, such as “OSDI ’14”.";
    else if (simplify_whitespace($Opt["shortName"]) != $Opt["shortName"])
        $m[] = "The <a href=\"" . hoturl("settings", "group=msg") . "\">conference abbreviation</a> setting has a funny value. To fix it, remove leading and trailing spaces, use only space characters (no tabs or newlines), and make sure words are separated by single spaces (never two or more).";
    $site_contact = Contact::site_contact();
    if (!$site_contact->email || $site_contact->email == "you@example.com")
        $m[] = "<a href=\"" . hoturl("settings", "group=msg") . "\">Set the conference contact’s name and email</a> so submitters can reach someone if things go wrong.";
    // Backwards compatibility
    if (@$conf->setting_data("clickthrough_submit")) // delete 12/2014
        $m[] = "You need to recreate the <a href=\"" . hoturl("settings", "group=msg") . "\">clickthrough submission terms</a>.";
    // Weird URLs?
    foreach (array("conferenceSite", "paperSite") as $k)
        if (isset($Opt[$k]) && $Opt[$k] && !preg_match('`\Ahttps?://(?:[-.~\w:/?#\[\]@!$&\'()*+,;=]|%[0-9a-fA-F][0-9a-fA-F])*\z`', $Opt[$k]))
            $m[] = $errmarker . "The <code>\$Opt[\"$k\"]</code> setting, ‘<code>" . htmlspecialchars($Opt[$k]) . "</code>’, is not a valid URL.  Edit the <code>conf/options.php</code> file to fix this problem.";
    // Double-encoding bugs found?
    if ($conf->setting("bug_doubleencoding"))
        $m[] = "Double-encoded URLs have been detected. Incorrect uses of Apache’s <code>mod_rewrite</code>, and other middleware, can encode URL parameters twice. This can cause problems, for instance when users log in via links in email. (“<code>a@b.com</code>” should be encoded as “<code>a%40b.com</code>”; a double encoding will produce “<code>a%2540b.com</code>”.) HotCRP has tried to compensate, but you really should fix the problem. For <code>mod_rewrite</code> add <a href='http://httpd.apache.org/docs/current/mod/mod_rewrite.html'>the <code>[NE]</code> option</a> to the relevant RewriteRule. <a href=\"" . hoturl_post("index", "clearbug=doubleencoding") . "\">(Clear&nbsp;this&nbsp;message)</a>";
    // Unnotified reviews?
    if ($conf->setting("pcrev_assigntime", 0) > $conf->setting("pcrev_informtime", 0)) {
        $assigntime = $conf->setting("pcrev_assigntime");
        $result = $conf->qe("select paperId from PaperReview where reviewType>" . REVIEW_PC . " and timeRequested>timeRequestNotified and reviewSubmitted is null and reviewNeedsSubmit!=0 limit 1");
        if ($result->num_rows) {
            $m[] = "PC review assignments have changed. You may want to <a href=\"" . hoturl("mail", "template=newpcrev") . "\">send mail about the new assignments</a>. <a href=\"" . hoturl_post("index", "clearnewpcrev=$assigntime") . "\">(Clear&nbsp;this&nbsp;message)</a>";
        } else {
            $conf->save_setting("pcrev_informtime", $assigntime);
        }
    }

    if (count($m))
        $conf->warnMsg("<div>" . join('</div><div style="margin-top:0.5em">', $m) . "</div>");
}

assert($Me->privChair);

if (isset($_REQUEST["clearbug"]) && check_post())
    $Conf->save_setting("bug_" . $_REQUEST["clearbug"], null);
if (isset($_REQUEST["clearnewpcrev"]) && ctype_digit($_REQUEST["clearnewpcrev"])
    && check_post() && $Conf->setting("pcrev_informtime", 0) <= $_REQUEST["clearnewpcrev"])
    $Conf->save_setting("pcrev_informtime", $_REQUEST["clearnewpcrev"]);
if (isset($_REQUEST["clearbug"]) || isset($_REQUEST["clearnewpcrev"]))
    redirectSelf(array("clearbug" => null, "clearnewpcrev" => null));
admin_home_messages($Conf);
