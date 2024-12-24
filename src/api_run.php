<?php
// api/api_run.php -- Peteramati API for runs
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Run_API {
    static function runchainhead(Contact $user, Qrequest $qreq, APIData $api) {
        if (($chain = QueueItem::parse_chain($qreq->chain)) === false) {
            return ["ok" => false, "error" => "Invalid request."];
        }
        $qi = QueueItem::by_chain($user->conf, $chain);
        if (!$qi) {
            return ["ok" => true, "done" => true, "njobs" => 0];
        } else if (!$user->isPC && $qi->reqcid !== $user->contactId) {
            return ["ok" => false, "error" => "Permission error."];
        } else if (!($pset = $qi->pset())) {
            return ["ok" => false, "error" => "Invalid pset."];
        } else if (!($u = $qi->user())) {
            return ["ok" => false, "error" => "Invalid user."];
        }
        if ($qi->schedulable()) {
            $qi->schedule(0);
        }
        $anon = $qreq->anonymous ?? $pset->anonymous;
        return [
            "ok" => true,
            "u" => $user->user_linkpart($u, !!$anon),
            "pset" => $pset->urlkey,
            "hash" => $qi->hash(),
            "queueid" => $qi->queueid,
            "runner" => $qi->runnername,
            "timestamp" => $qi->runat,
            "njobs" => $user->conf->fetch_ivalue("select count(*) from ExecutionQueue where chain=? and status<?", $chain, QueueItem::STATUS_CANCELLED)
        ];
    }
}
