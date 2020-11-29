<?php
// api/api_flag.php -- Peteramati API for flagging
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class API_Flag {
    static function flag(Contact $user, Qrequest $qreq, APIData $api) {
        $info = PsetView::make($api->pset, $api->user, $user);
        if (($err = $api->prepare_commit($info))) {
            return $err;
        } else if (!$user->isPC && $user !== $api->user) {
            return ["ok" => false, "error" => "Permission error."];
        }
        $flags = (array) $info->current_jnote("flags");
        if ($qreq->is_post()) {
            $flagid = $qreq->flagid;
            if (!$flagid || $flagid === "new") {
                $flagid = "t" . Conf::$now;
            } else if (!isset($flags[$flagid])) {
                return ["ok" => false, "error" => "Not found."];
            }
            $flag = (array) ($flags[$flagid] ?? []);
            $flag["uid"] = $flag["uid"] ?? $user->contactId;
            $flag["started"] = $flag["started"] ?? Conf::$now;
            if ($flag["started"] !== Conf::$now) {
                $flag["updated"] = Conf::$now;
            }
            $reason = trim($qreq->reason ?? "");
            if ($reason !== "") {
                $flag["conversation"][] = [Conf::$now, $user->contactId, $reason];
            }
            if ($qreq->resolve && !($flag["resolved"] ?? false)) {
                $flag["resolved"] = [Conf::$now, $user->contactId];
            }
            $info->update_current_notes(["flags" => [$flagid => $flag]]);
        }
        return ["ok" => true, "flags" => $info->current_jnote("flags")];
    }
}
