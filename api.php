<?php
// api.php -- HotCRP JSON API access page
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

// parse request
if ($Qreq->base !== null)
    $Conf->set_siteurl($Qreq->base);
if ($Qreq->path_front() && substr($Qreq->path_front(), 0, 1) === "~")
    $Qreq->u = substr(urldecode($Qreq->shift_path()), 1);
if (($x = $Qreq->shift_path()))
    $Qreq->fn = urldecode($x);
if (($x = $Qreq->shift_path())) {
    if (!$Qreq->pset)
        $Qreq->pset = urldecode($x);
    if (($x = $Qreq->shift_path())) {
        if (!$Qreq->commit)
            $Qreq->commit = urldecode($x);
    }
}

// check user
$api = new APIData($Me);
if ($Qreq->u && !($api->user = ContactView::prepare_user($Qreq->u))) {
    json_exit(["ok" => false, "error" => "User permission error."]);
}

// check pset
if ($Qreq->pset && !($api->pset = $Conf->pset_by_key($Qreq->pset))) {
    json_exit(["ok" => false, "error" => "No such pset."]);
}
if ($api->pset && $api->pset->disabled) {
    if ($Me->isPC)
        json_exit(["ok" => false, "error" => "Pset disabled."]);
    else
        json_exit(["ok" => false, "error" => "No such pset."]);
} else if ($api->pset && !$api->pset->visible && !$Me->isPC) {
    json_exit(["ok" => false, "error" => "No such pset."]);
}

// check commit
if ($api->pset && !$api->pset->gitless && !$Me->is_empty()) {
    $api->repo = $api->user->repo($api->pset);
    $api->branch = $api->user->branch_name($api->pset);
}
if ($api->repo && $Qreq->commit) {
    $api->hash = $Qreq->commit;
}

// call api
$Conf->call_api_exit($Qreq->fn, $Me, $Qreq, $api);
