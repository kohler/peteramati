<?php
// initweb.php -- HotCRP initialization for web scripts
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

require_once("init.php");
global $Conf, $Me, $Opt, $Qreq;

// Check and fix zlib output compression
global $zlib_output_compression;
$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}

// Initialize user
function initialize_request() {
    $conf = Conf::$main;
    $nav = Navigation::get();

    // check PHP suffix
    if (($php_suffix = $conf->opt("phpSuffix")) !== null) {
        $nav->php_suffix = $php_suffix;
    }

    // maybe redirect to https
    if ($conf->opt("redirectToHttps")) {
        $nav->redirect_http_to_https($conf->opt("allowLocalHttp"));
    }

    // collect $qreq
    $qreq = Qrequest::make_global();

    // check method
    if ($qreq->method() !== "GET"
        && $qreq->method() !== "POST"
        && $qreq->method() !== "HEAD"
        && ($qreq->method() !== "OPTIONS" || $nav->page !== "api")) {
        header("HTTP/1.0 405 Method Not Allowed");
        exit;
    }

    // mark as already expired to discourage caching, but allow the browser
    // to cache for history buttons
    header("Cache-Control: max-age=0,must-revalidate,private");

    // skip user initialization if requested
    if ($conf->opt["__no_main_user"] ?? null) {
        return;
    }

    // set up session
    if (($sh = $conf->opt["sessionHandler"] ?? null)) {
        /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName, PhanNonClassMethodCall */
        $conf->_session_handler = new $sh($conf);
        session_set_save_handler($conf->_session_handler, true);
    }
    set_session_name($conf);
    $sn = session_name();

    // check CSRF token, using old value of session ID
    if ($qreq->post && $sn && isset($_COOKIE[$sn])) {
        $sid = $_COOKIE[$sn];
        $l = strlen($qreq->post);
        if ($l >= 8 && $qreq->post === substr($sid, strlen($sid) > 16 ? 8 : 0, $l)) {
            $qreq->approve_token();
        }
    }
    ensure_session(ENSURE_SESSION_ALLOW_EMPTY);

    // upgrade session format
    if (!isset($_SESSION["u"]) && isset($_SESSION["trueuser"])) {
        $_SESSION["u"] = $_SESSION["trueuser"]->email;
    }

    // determine user
    $trueemail = isset($_SESSION["u"]) ? $_SESSION["u"] : null;

    // look up and activate user
    $guser = null;
    if ($trueemail) {
        $guser = $conf->user_by_email($trueemail);
    }
    if (!$guser) {
        $guser = new Contact($trueemail ? (object) ["email" => $trueemail] : null);
    }
    $guser = $guser->activate($qreq, true);
    Contact::set_main_user($guser);

    // redirect if disabled
    if ($guser->is_disabled()) {
        if ($nav->page === "api") {
            json_exit(["ok" => false, "error" => "Your account is disabled."]);
        } else if ($nav->page !== "index" && $nav->page !== "resetpassword") {
            $conf->redirect();
        }
    }

    // if bounced through login, add post data
    if (isset($_SESSION["login_bounce"][4])
        && $_SESSION["login_bounce"][4] <= Conf::$now) {
        unset($_SESSION["login_bounce"]);
    }

    if (!$guser->is_empty()
        && isset($_SESSION["login_bounce"])
        && !isset($_SESSION["testsession"])) {
        $lb = $_SESSION["login_bounce"];
        if ($lb[0] == $conf->dsn
            && $lb[2] !== "index"
            && $lb[2] == Navigation::page()) {
            foreach ($lb[3] as $k => $v)
                if (!isset($qreq[$k]))
                    $qreq[$k] = $v;
            $qreq->set_annex("after_login", true);
        }
        unset($_SESSION["login_bounce"]);
    }

    // set $_SESSION["addrs"]
    if ($_SERVER["REMOTE_ADDR"]
        && (!$guser->is_empty()
            || isset($_SESSION["addrs"]))
        && (!isset($_SESSION["addrs"])
            || !is_array($_SESSION["addrs"])
            || $_SESSION["addrs"][0] !== $_SERVER["REMOTE_ADDR"])) {
        $as = [$_SERVER["REMOTE_ADDR"]];
        if (isset($_SESSION["addrs"]) && is_array($_SESSION["addrs"])) {
            foreach ($_SESSION["addrs"] as $a)
                if ($a !== $_SERVER["REMOTE_ADDR"] && count($as) < 5)
                    $as[] = $a;
        }
        $_SESSION["addrs"] = $as;
    }
}

initialize_request();


// Extract an error that we redirected through
if (isset($_SESSION["redirect_error"])) {
    global $Error;
    $Error = $_SESSION["redirect_error"];
    unset($_SESSION["redirect_error"]);
}
