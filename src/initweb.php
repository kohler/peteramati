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
    $qreq = Qrequest::make_global($nav);
    $qreq->set_conf($conf);

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
    $qsessionf = $conf->opt["qsessionFunction"] ?? "+PHPQsession";
    if (str_starts_with($qsessionf, "+")) {
        $class = substr($qsessionf, 1);
        $qreq->set_qsession(new $class($conf, $qreq));
    } else if ($qsessionf) {
        $qreq->set_qsession(call_user_func($qsessionf, $conf, $qreq));
    }
    $qreq->qsession()->maybe_open();

    // determine user
    $trueemail = $qreq->gsession("u");
    $userset = $qreq->gsession("us") ?? ($trueemail ? [$trueemail] : []);
    $usercount = count($userset);
    '@phan-var list<string> $userset';

    // look up and activate user
    $muser = $trueemail ? $conf->fresh_user_by_email($trueemail) : null;
    if (!$muser) {
        $muser = Contact::make_email($conf, $trueemail);
    }
    $muser = $muser->activate($qreq, true);
    Contact::set_main_user($muser);
    $qreq->set_user($muser);

    // redirect if disabled
    if ($muser->is_disabled()) {
        if ($nav->page === "api") {
            json_exit(["ok" => false, "error" => "Your account is disabled."]);
        } else if ($nav->page !== "index" && $nav->page !== "resetpassword") {
            $conf->redirect();
        }
    }

    // if bounced through login, add post data
    $login_bounce = $qreq->gsession("login_bounce");
    if (isset($login_bounce[4]) && $login_bounce[4] <= Conf::$now) {
        $qreq->unset_gsession("login_bounce");
        $login_bounce = null;
    }

    if (!$muser->is_empty() && $login_bounce !== null) {
        if ($login_bounce[0] === $conf->session_key
            && $login_bounce[2] !== "index"
            && $login_bounce[2] === $nav->page) {
            foreach ($login_bounce[3] as $k => $v) {
                if (!isset($qreq[$k]))
                    $qreq[$k] = $v;
            }
            $qreq->set_annex("after_login", true);
        }
        $qreq->unset_gsession("login_bounce");
    }

    // set $_SESSION["addrs"]
    $addr = $_SERVER["REMOTE_ADDR"];
    if ($addr
        && $qreq->qsid()
        && (!$muser->is_empty() || $qreq->has_gsession("addrs"))) {
        $addrs = $qreq->gsession("addrs");
        if (!is_array($addrs) || empty($addrs)) {
            $addrs = [];
        }
        if (($addrs[0] ?? null) !== $_SERVER["REMOTE_ADDR"]) {
            $naddrs = [$addr];
            foreach ($addrs as $a) {
                if ($a !== $addr && count($naddrs) < 5)
                    $naddrs[] = $a;
            }
            $qreq->set_gsession("addrs", $naddrs);
        }
    }
}

initialize_request();
