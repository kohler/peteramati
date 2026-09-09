<?php
// authorize.php -- Peteramati authorization page
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty()) {
    $Me->escape();
}

function error_exit($conf, $msg) {
    $conf->header("GitHub authorization", "home");
    $conf->error_msg($msg);
    $conf->footer();
    exit(0);
}

/** Credentials for the separate OAuth app, which is used only to obtain the
 * course-wide `githubOAuthToken`. The student flow below uses the GitHub App
 * instead, so this is checked only where it is actually needed.
 * @return array{string,string} */
function github_oauth_app_credentials(Conf $conf) {
    $clientid = $conf->opt("githubOAuthClientId");
    $clientsecret = $conf->opt("githubOAuthClientSecret");
    if (!$clientid || !$clientsecret) {
        error_exit($conf, "This installation has not been configured yet. Follow the instructions in <code>README.md</code> to obtain a GitHub OAuth Client ID and Client Secret, then configure those values in <code>conf/options.php</code>.");
    }
    return [$clientid, $clientsecret];
}

/** Send the user to GitHub. `state` is a single-use capability recording who
 * started the request, why, and (for a repository setup) for which problem
 * set; nothing else about the request is trusted on the way back.
 * @param string $purpose
 * @param string $scope
 * @param int $psetid
 * @return never */
function github_authorize_redirect(Conf $conf, Contact $me, $clientid, $purpose, $scope = null, $psetid = 0) {
    $capmgr = $conf->capability_manager();
    $capmgr->delete_expired(CAPTYPE_GITHUB_OAUTH);
    $state = $capmgr->create(CAPTYPE_GITHUB_OAUTH, [
        "user" => $me, "timeExpires" => Conf::$now + 600,
        "paperId" => $psetid,
        "data" => json_encode(["purpose" => $purpose])
    ]);
    if (!$state) {
        error_exit($conf, "Internal error creating an authorization request.");
    }
    $conf->redirect("https://github.com/login/oauth/authorize"
        . "?client_id=" . urlencode($clientid)
        . "&redirect_uri=" . urlencode($conf->hoturl_absolute("authorize"))
        . ($scope === null ? "" : "&scope=" . urlencode($scope))
        . "&state=" . urlencode($state));
}

/** Exchange an authorization code for an access token.
 * @param string $code
 * @return ?string */
function github_access_token(Conf $conf, $clientid, $clientsecret, $code) {
    $response = new GitHubResponse("https://github.com/login/oauth/access_token");
    $response->run_request($conf, "POST", "application/x-www-form-urlencoded", [
            "client_id" => $clientid, "client_secret" => $clientsecret,
            "code" => $code,
            "redirect_uri" => $conf->hoturl_absolute("authorize")
        ], "Accept: application/json\r\n");
    if ($response->status !== 200
        || !$response->response
        || !isset($response->response->access_token)) {
        return null;
    }
    return $response->response->access_token;
}

if (isset($Qreq->state)) {
    // Returning from GitHub.
    $capmgr = $Conf->capability_manager();
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
    if ($purpose === "site" ? !$Me->privChair : $purpose !== "user") {
        error_exit($Conf, "Permission denied.");
    }
    if (!$Qreq->code) {
        error_exit($Conf, "GitHub did not authorize this request. Follow the link again if you meant to authorize it.");
    }

    if ($purpose === "site") {
        list($clientid, $clientsecret) = github_oauth_app_credentials($Conf);
        if (!($token = github_access_token($Conf, $clientid, $clientsecret, $Qreq->code))) {
            error_exit($Conf, "Failed response to authorization attempt.");
        }
        $Conf->save_setting("opt.githubOAuthToken", 1, $token);
        $Conf->redirect();
    }

    // Set up this student's repository. The token proves which GitHub
    // account they own, and can accept the collaborator invitation.
    if (!($app = $Conf->github_app()) || !$app->has_user_auth()) {
        error_exit($Conf, "This installation is not configured to create repositories.");
    }
    if (!($token = github_access_token($Conf, $app->client_id(), $app->client_secret(), $Qreq->code))) {
        error_exit($Conf, "Failed response to authorization attempt.");
    }
    if (!($pset = $Conf->pset_by_id((int) $capdata->paperId))) {
        error_exit($Conf, "That problem set no longer exists.");
    }
    $ms = new MessageSet;
    $setup = new GitHub_StudentSetup($Me, $pset, $token);
    if (($repo = $setup->run($ms))) {
        $Conf->success_msg("<5>Your repository is "
            . Ht::link(htmlspecialchars($repo->friendly_url()), $repo->https_url())
            . ($setup->created ? "." : " (it already existed)."));
    }
    $Conf->feedback_msg($ms);
    $Conf->redirect();

} else if ($Qreq->setup_repo) {
    // A student asking for their repository.
    if (!$Qreq->valid_post()) {
        error_exit($Conf, "Your session has expired. Reload the home page and try again.");
    } else if (!GitHub_StudentSetup::available($Conf, $Me)) {
        error_exit($Conf, "You have no repository to set up.");
    }
    $psetid = 0;
    foreach (GitHub_StudentSetup::target_psets($Conf, $Me) as $pset) {
        if (!$Me->repo($pset->id)) {
            $psetid = $pset->id;
            break;
        }
    }
    // no scope: a GitHub App user token's abilities come from the app
    github_authorize_redirect($Conf, $Me, $Conf->github_app()->client_id(),
        "user", null, $psetid);

} else {
    // The chair obtaining the course-wide token used for all GitHub API access.
    if (!$Me->privChair) {
        error_exit($Conf, "Permission denied.");
    }
    list($clientid, $clientsecret) = github_oauth_app_credentials($Conf);
    github_authorize_redirect($Conf, $Me, $clientid, "site",
        "repo read:org read:user user:email user:follow");
}
