<?php
// authorize.php -- Peteramati authorization page
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty()) {
    $Me->escape();
}

$clientid = $Conf->opt("githubOAuthClientId");
$clientsecret = $Conf->opt("githubOAuthClientSecret");

function error_exit($conf, $msg) {
    $conf->header("GitHub authorization", "home");
    $conf->error_msg($msg);
    $conf->footer();
    exit(0);
}

if (!$clientid || !$clientsecret) {
    error_exit($Conf, "This installation has not been configured yet. Follow the instructions in <code>README.md</code> to obtain a GitHub OAuth Client ID and Client Secret, then configure those values in <code>conf/options.php</code>.");
}

$capmgr = $Conf->capability_manager();

if (isset($Qreq->state)) {
    // Returning from GitHub. `state` is a single-use capability recording
    // which user started the request and why.
    $capdata = is_string($Qreq->state) ? $capmgr->check($Qreq->state) : false;
    if (!$capdata || $capdata->capabilityType != CAPTYPE_GITHUB_OAUTH) {
        error_exit($Conf, "Unexpected attempt to authorize (the request has expired, or did not come from this site).");
    }
    $capmgr->delete($capdata);
    if ($capdata->contactId != $Me->contactId) {
        error_exit($Conf, "Unexpected attempt to authorize (the request was started by a different user).");
    }
    $capdatax = json_decode((string) $capdata->data);
    $purpose = is_object($capdatax) ? ($capdatax->purpose ?? null) : null;
    if ($purpose !== "site" || !$Me->privChair) {
        error_exit($Conf, "Permission denied.");
    }
    if (!$Qreq->code) {
        error_exit($Conf, "GitHub did not authorize this request. Follow the link again if you meant to authorize it.");
    }

    $response = new GitHubResponse("https://github.com/login/oauth/access_token");
    $response->run_request($Conf, "POST", "application/x-www-form-urlencoded", [
            "client_id" => $clientid, "client_secret" => $clientsecret,
            "code" => $Qreq->code,
            "redirect_uri" => $Conf->hoturl_absolute("authorize")
        ], "Accept: application/json\r\n");
    if ($response->status !== 200
        || !$response->response
        || !isset($response->response->access_token)) {
        error_exit($Conf, "Failed response to authorization attempt.");
    }

    $Conf->save_setting("opt.githubOAuthToken", 1, $response->response->access_token);
    $Conf->redirect();

} else {
    if (!$Me->privChair) {
        error_exit($Conf, "Permission denied.");
    }
    $capmgr->delete_expired(CAPTYPE_GITHUB_OAUTH);
    $state = $capmgr->create(CAPTYPE_GITHUB_OAUTH, [
        "user" => $Me, "timeExpires" => Conf::$now + 600,
        "data" => json_encode(["purpose" => "site"])
    ]);
    if (!$state) {
        error_exit($Conf, "Internal error creating an authorization request.");
    }
    $Conf->redirect("https://github.com/login/oauth/authorize"
        . "?client_id=" . urlencode($clientid)
        . "&redirect_uri=" . urlencode($Conf->hoturl_absolute("authorize"))
        // scope for the course-wide token used for all GitHub API access
        . "&scope=" . urlencode("repo read:org read:user user:email user:follow")
        . "&state=" . urlencode($state));
}
