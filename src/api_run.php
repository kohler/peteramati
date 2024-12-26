<?php
// api/api_run.php -- Peteramati API for runs
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Run_API {
    static function runchainhead(Contact $user, Qrequest $qreq, APIData $api) {
        if (($chain = QueueItem::parse_chain($qreq->chain)) === false) {
            return ["ok" => false, "error" => "Invalid request"];
        }
        $cursor = PHP_INT_MAX;
        if (isset($qreq->cursor) && ctype_digit($qreq->cursor)) {
            $cursor = intval($qreq->cursor);
        }
        $njobs = $ncancelled = $ndone = 0; // shut up phan

        for ($ntries = 0; $ntries < 5; ++$ntries) {
            // load and check chain
            $qis = QueueItem::list_by_chain($user->conf, $chain, true);
            if (empty($qis)) {
                return ["ok" => false, "error" => "Queue not found"];
            } else if (!$user->isPC && $qis[0]->reqcid !== $user->contactId) {
                return ["ok" => false, "error" => "Permission error"];
            }

            // enumerate chain
            $njobs = $ncancelled = $ndone = $nscheduled = $nunscheduled = 0;
            $active = [];
            foreach ($qis as $qi) {
                ++$njobs;
                if ($qi->status() >= QueueItem::STATUS_DONE) {
                    ++$ndone;
                } else if ($qi->status() >= QueueItem::STATUS_CANCELLED) {
                    ++$ncancelled;
                } else if ($qi->status() >= QueueItem::STATUS_SCHEDULED) {
                    ++$nscheduled;
                } else if ($qi->status() >= QueueItem::STATUS_UNSCHEDULED) {
                    ++$nunscheduled;
                }
                if ($qi->status() >= QueueItem::STATUS_SCHEDULED && $qi->queueid > $cursor) {
                    $active[] = $qi;
                }
            }

            // maybe step chain
            if ($nscheduled === 0
                && $nunscheduled > 0
                && QueueItem::step_chain($user->conf, $chain)) {
                continue;
            }
            break;
        }

        // prepare result
        $r = [
            "ok" => true,
            "njobs" => $njobs,
            "nleft" => $njobs - $ncancelled - $ndone,
            "ncancelled" => $ncancelled,
            "ndone" => $ndone
        ];

        // sort active jobs, expose them
        usort($active, function ($a, $b) {
            return $a->queueid <=> $b->queueid;
        });
        $anon = friendly_boolean($qreq->anonymous);
        foreach ($active as $qi) {
            $pset = $qi->pset();
            $u = $qi->user();
            if ($pset && $u) {
                $r["active"][] = [
                    "queueid" => $qi->queueid,
                    "u" => $user->user_linkpart($u, $anon ?? $pset->anonymous),
                    "pset" => $pset->urlkey,
                    "runner" => $qi->runnername,
                    "timestamp" => $qi->runat,
                    "status" => $qi->status_text(),
                    "cursor" => $qi->queueid
                ];
            }
        }

        return $r;
    }
}
