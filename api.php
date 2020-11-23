<?php
// api.php -- HotCRP JSON API access page
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

// parse request
function prepare_api_request(Conf $conf, Qrequest $qreq) {
    if ($qreq->base !== null) {
        $conf->set_siteurl($qreq->base);
    }
    if ($qreq->path_component(0)
        && substr($qreq->path_component(0), 0, 1) === "~") {
        $qreq->u = substr(urldecode($qreq->shift_path_components(1)), 2);
    }
    if (($x = $qreq->shift_path_components(1))) {
        $qreq->fn = substr(urldecode($x), 1);
    }
    if (($x = $qreq->shift_path_components(1))) {
        if (!$qreq->pset) {
            $qreq->pset = substr(urldecode($x), 1);
        }
        if (($x = $qreq->shift_path_components(1))) {
            if (!$qreq->commit) {
                $qreq->commit = substr(urldecode($x), 1);
            }
        }
    }
}
prepare_api_request($Conf, $Qreq);

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
    if ($Me->isPC) {
        json_exit(["ok" => false, "error" => "Pset disabled."]);
    } else {
        json_exit(["ok" => false, "error" => "No such pset."]);
    }
} else if ($api->pset && !$api->pset->visible && !$Me->isPC) {
    json_exit(["ok" => false, "error" => "No such pset."]);
}

// check commit
if ($api->pset && !$api->pset->gitless && !$Me->is_empty()) {
    $api->repo = $api->user->repo($api->pset);
    $api->branch = $api->user->branch($api->pset);
}
if ($api->repo && $Qreq->commit) {
    $api->hash = $Qreq->commit;
}

// call api
$Conf->call_api_exit($Qreq->fn, $Me, $Qreq, $api);
