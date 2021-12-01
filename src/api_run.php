<?php
// api/api_run.php -- Peteramati API for runs
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Run_API {
    static function runchainhead(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$qreq->chain || !ctype_digit($qreq->chain)) {
            return ["ok" => false, "error" => "Invalid request."];
        }
        $qi = QueueItem::by_chain($user->conf, intval($qreq->chain));
        if (!$qi) {
            return ["ok" => false, "error" => "Chain done.", "chain" => false];
        } else if (!$user->isPC && $qi->reqcid !== $user->contactId) {
            return ["ok" => false, "error" => "Permission error."];
        } else if (!($pset = $qi->pset())) {
            return ["ok" => false, "error" => "Invalid pset."];
        } else if (!($u = $qi->user())) {
            return ["ok" => false, "error" => "Invalid user."];
        } else {
            if ($qi->status === -1) {
                $qi->schedule(0);
            }
            $anon = $qreq->anonymous ?? $pset->anonymous;
            return [
                "ok" => true,
                "queueid" => $qi->queueid,
                "u" => $user->user_linkpart($u, !!$anon),
                "pset" => $pset->urlkey,
                "runner" => $qi->runnername,
                "timestamp" => $qi->runat
            ];
        }
    }
}
