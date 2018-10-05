<?php
// api.php -- HotCRP JSON API access page
// HotCRP and Peteramati are Copyright (c) 2006-2018 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");

// parse request
$qreq = make_qreq();
if ($qreq->base !== null)
    $Conf->set_siteurl($qreq->base);
if ($qreq->path_front() && substr($qreq->path_front(), 0, 1) === "~")
    $qreq->u = substr(urldecode($qreq->shift_path()), 1);
if (($x = $qreq->shift_path()))
    $qreq->fn = urldecode($x);
if (($x = $qreq->shift_path())) {
    if (!$qreq->pset)
        $qreq->pset = urldecode($x);
    if (($x = $qreq->shift_path())) {
        if (!$qreq->commit)
            $qreq->commit = urldecode($x);
    }
}

// check user
$api = new APIData($Me);
if ($qreq->u && !($api->user = ContactView::prepare_user($qreq->u)))
    json_exit(["ok" => false, "error" => "User permission error."]);

// check pset
if ($qreq->pset && !($api->pset = $Conf->pset_by_key($qreq->pset)))
    json_exit(["ok" => false, "error" => "No such pset."]);
if ($api->pset && $api->pset->disabled) {
    if ($Me->isPC)
        json_exit(["ok" => false, "error" => "Pset disabled."]);
    else
        json_exit(["ok" => false, "error" => "No such pset."]);
}
if ($api->pset && !$api->pset->visible && !$Me->isPC)
    json_exit(["ok" => false, "error" => "No such pset."]);

// check commit
if ($api->pset && !$api->pset->gitless && !$Me->is_empty()) {
    $api->repo = $api->user->repo($api->pset);
    if (!$api->pset->no_branch)
        $api->branch = $api->user->branch_link($api->pset->id);
}
if ($api->repo && $qreq->commit)
    $api->hash = $qreq->commit;

// call api
$Conf->call_api_exit($qreq->fn, $Me, $qreq, $api);
