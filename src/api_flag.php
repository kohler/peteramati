<?php
// api/api_flag.php -- Peteramati API for flagging
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Flag_API {
    /** @param string $flagid
     * @param bool $create
     * @param int $uid
     * @param ?string $reason
     * @param bool $resolve
     * @param int $when
     * @return bool */
    static function update_flag(PsetView $info, $flagid, $create, $uid, $reason, $resolve, $when) {
        $flags = (array) $info->commit_jnote("flags");
        if (!$create && !isset($flags[$flagid])) {
            return false;
        }
        $flag = (array) ($flags[$flagid] ?? []);
        $flag["uid"] = $flag["uid"] ?? $uid;
        $flag["started"] = $flag["started"] ?? $when;
        if ($flag["started"] !== $when) {
            $flag["updated"] = max($flag["updated"] ?? $when, $when);
        }
        if (($reason ?? "") !== "") {
            $flag["conversation"][] = [$when, $uid, $reason];
        }
        if ($resolve && !($flag["resolved"] ?? false)) {
            $flag["resolved"] = [$when, $uid];
        }
        $info->update_commit_notes(["flags" => [$flagid => $flag]]);
        return true;
    }

    static function flag(Contact $user, Qrequest $qreq, APIData $api) {
        $info = PsetView::make($api->pset, $api->user, $user);
        if (($err = $api->prepare_commit($info))) {
            return $err;
        } else if (!$user->isPC && $user !== $api->user) {
            return ["ok" => false, "error" => "Permission error."];
        }
        if ($qreq->is_post()) {
            $flagid = $qreq->flagid;
            $create = !$flagid || $flagid === "new";
            $when = Conf::$now;
            self::update_flag($info, $create ? "t$when" : $flagid, $create,
                              $user->contactId, trim($qreq->reason ?? ""), !!$qreq->resolve, $when);
        }
        return ["ok" => true, "flags" => $info->commit_jnote("flags")];
    }

    static function multiresolve(Contact $viewer, Qrequest $qreq, APIData $api) {
        if (!isset($qreq->flags)
            || !($flags = json_decode($qreq->flags))
            || !is_array($flags)) {
            return ["ok" => false, "error" => "Missing parameter."];
        }
        if ($qreq->is_post() && !$qreq->valid_post()) {
            return ["ok" => false, "error" => "Missing credentials."];
        }

        $info = null;
        $users = [];
        $work = [];
        $errors = [];
        // XXX This does what it can as long as there are no serious errors
        foreach ($flags as $flag) {
            if (!is_object($flag)
                || !is_int($flag->uid ?? null)
                || !is_int($flag->pset ?? null)
                || !is_string($flag->commit ?? null)
                || !ctype_xdigit($flag->commit)
                || !is_string($flag->flagid ?? null)) {
                return ["ok" => false, "error" => "Format error."];
            }
            if (!($u = $users[$flag->uid] ?? $viewer->conf->user_by_id($flag->uid))
                || ($u->contactId !== $viewer->contactId && !$viewer->isPC)) {
                return ["ok" => false, "error" => "Permission error."];
            }
            if (!($pset = $viewer->conf->pset_by_id($flag->pset))
                || $pset->gitless) {
                return ["ok" => false, "error" => "Bad psetid."];
            }
            if (!isset($users[$flag->uid])) {
                $u->set_anonymous($pset->anonymous);
                $users[$flag->uid] = $u;
            } else if ($u->is_anonymous !== $pset->anonymous) {
                $u = $viewer->conf->user_by_id($flag->uid);
                $u->set_anonymous($pset->anonymous);
            }

            $info = PsetView::make($pset, $u, $viewer);
            if (!$info->repo) {
                $flag->error = "No repository.";
                $errors[] = $flag;
                continue;
            }
            $commit = $info->repo->connected_commit($flag->commit, $info->pset);
            if (!$commit) {
                $flag->error = "Disconnected commit.";
                $errors[] = $flag;
                continue;
            }
            $info->set_commit($commit);
            $flags = (array) $info->commit_jnote("flags");
            if (isset($flags[$flag->flagid])) {
                $work[] = [$info, $flag];
            } else {
                $flag->error = "Flag not found.";
                $errors[] = $flag;
            }
        }

        $done = [];
        foreach ($work as $info_flag) {
            list($info, $flag) = $info_flag;
            self::update_flag($info, $flag->flagid, false,
                              $viewer->contactId, null, true, Conf::$now);
            $done[] = $flag;
        }

        return ["ok" => !empty($done), "doneflags" => $done, "errorflags" => $errors];
    }
}
