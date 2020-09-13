<?php
// authorize.php -- Peteramati authorization page
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty() || !$Me->privChair) {
    $Me->escape();
}

$clientid = $Conf->opt("githubOAuthClientId");
$clientsecret = $Conf->opt("githubOAuthClientSecret");

function error_exit($conf, $msg) {
    $conf->header("GitHub authorization", "home");
    $conf->errorMsg($msg);
    $conf->footer();
    exit;
}

if (!$clientid || !$clientsecret) {
    error_exit($Conf, "This installation has not been configured yet. Follow the instructions in <code>README.md</code> to obtain a GitHub OAuth Client ID and Client Secret, then configure those values in <code>conf/options.php</code>.");
}

if ($Qreq->code) {
    $when = $Conf->setting("__github_oauth");
    $state = $Conf->setting_data("__github_oauth");
    if (Conf::$now - $when > 120) {
        error_exit($Conf, "Unexpected attempt to authorize (too old).");
    } else if ($state !== $Qreq->state) {
        error_exit($Conf, "Unexpected attempt to authorize (bad state).");
    }

    $response = new GitHubResponse("https://github.com/login/oauth/access_token");
    $response->run_post($Conf, "application/x-www-form-urlencoded", [
            "client_id" => $clientid, "client_secret" => $clientsecret,
            "code" => $Qreq->code, "state" => $state
        ], "Accept: application/json\r\n");
    if ($response->status === 200
        && $response->j
        && isset($response->j->access_token)) {
        $Conf->save_setting("opt.githubOAuthToken", 1, $response->j->access_token);
        $Conf->save_setting("__github_oauth", null);
        Navigation::redirect_site("");
    } else {
        error_exit($Conf, "Failed response to authorization attempt.");
    }

} else {
    $state = bin2hex(random_bytes(24));
    $Conf->save_setting("__github_oauth", Conf::$now, $state);
    Navigation::redirect("https://github.com/login/oauth/authorize"
        . "?client_id=" . urlencode($clientid)
        . "&redirect_uri=" . urlencode($Conf->hoturl_absolute("authorize"))
        . "&scope=" . urlencode("repo read:org read:user user:email user:follow")
        . "&state=" . $state);
}
