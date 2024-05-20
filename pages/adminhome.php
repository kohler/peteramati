<?php
// adminhome.php -- HotCRP home page administrative messages
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

// access only allowed through index.php
if (!$Conf) {
    exit();
}

function admin_home_messages($conf) {
    $m = array();
    $errmarker = "<span class=\"error\">Error:</span> ";
    if (preg_match("/^(?:[1-4]\\.|5\\.[012])/", phpversion())) {
        $m[] = $errmarker . "HotCRP requires PHP version 5.3 or higher.  You are running PHP version " . htmlspecialchars(phpversion()) . ".";
    }
    if (defined("JSON_HOTCRP")) {
        $m[] = "Your PHP was built without JSON functionality. HotCRP is using its built-in replacements; the native functions would be faster.";
    }
    if (($conf->opt["qsessionFunction"] ?? "+DbQsession") === "+PHPQsession") {
        $gc_lifetime = (int) ini_get("session.gc_maxlifetime");
        $co_lifetime = (session_get_cookie_params())["lifetime"];
        if ($co_lifetime > 0 && $gc_lifetime < $co_lifetime) {
            $m[] = "PHP’s systemwide <code>session.gc_maxlifetime</code> setting, which is {$gc_lifetime} seconds, is less than Peteramati’s preferred session expiration time, which is {$co_lifetime} seconds.  You should update <code>session.gc_maxlifetime</code> in the <code>php.ini</code> file or users may be booted off the system earlier than you expect.";
        }
    }
    // Conference names
    if ($conf->opt["shortNameDefaulted"] ?? false) {
        $m[] = "<a href=\"" . $conf->hoturl("settings", "group=msg") . "\">Set the conference abbreviation</a> to a short name for your conference, such as “OSDI ’14”.";
    } else if (simplify_whitespace($conf->opt["shortName"] ?? "") !== ($conf->opt["shortName"] ?? "")) {
        $m[] = "The <a href=\"" . $conf->hoturl("settings", "group=msg") . "\">conference abbreviation</a> setting has a funny value. To fix it, remove leading and trailing spaces, use only space characters (no tabs or newlines), and make sure words are separated by single spaces (never two or more).";
    }
    $site_contact = $conf->site_contact();
    if (!$site_contact->email || $site_contact->email == "you@example.com") {
        $m[] = "<a href=\"" . $conf->hoturl("settings", "group=msg") . "\">Set the conference contact’s name and email</a> so submitters can reach someone if things go wrong.";
    }
    // Weird URLs?
    foreach (array("conferenceSite", "paperSite") as $k) {
        if (($conf->opt[$k] ?? false)
            && !preg_match('`\Ahttps?://(?:[-.~\w:/?#\[\]@!$&\'()*+,;=]|%[0-9a-fA-F][0-9a-fA-F])*\z`', $conf->opt[$k]))
            $m[] = $errmarker . "The <code>\$Opt[\"$k\"]</code> setting, ‘<code>" . htmlspecialchars($conf->opt[$k]) . "</code>’, is not a valid URL.  Edit the <code>conf/options.php</code> file to fix this problem.";
    }
    // Double-encoding bugs found?
    if ($conf->setting("bug_doubleencoding")) {
        $m[] = "Double-encoded URLs have been detected. Incorrect uses of Apache’s <code>mod_rewrite</code>, and other middleware, can encode URL parameters twice. This can cause problems, for instance when users log in via links in email. (“<code>a@b.com</code>” should be encoded as “<code>a%40b.com</code>”; a double encoding will produce “<code>a%2540b.com</code>”.) HotCRP has tried to compensate, but you really should fix the problem. For <code>mod_rewrite</code> add <a href='http://httpd.apache.org/docs/current/mod/mod_rewrite.html'>the <code>[NE]</code> option</a> to the relevant RewriteRule. <a href=\"" . $conf->hoturl("=index", "clearbug=doubleencoding") . "\">(Clear&nbsp;this&nbsp;message)</a>";
    }

    if (count($m)) {
        $conf->warnMsg("<div>" . join('</div><div style="margin-top:0.5em">', $m) . "</div>");
    }
}

assert($Me->privChair);

if (isset($_REQUEST["clearbug"]) && $Qreq->valid_token()) {
    $Conf->save_setting("bug_" . $_REQUEST["clearbug"], null);
}
if (isset($_REQUEST["clearbug"]) || isset($_REQUEST["clearnewpcrev"])) {
    $Conf->redirect_self($Qreq, ["clearbug" => null, "clearnewpcrev" => null]);
}
admin_home_messages($Conf);
