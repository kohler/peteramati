<?php
// api/api_psetconfig.php -- Peteramati API for problem set configuration
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class PsetConfig_API {
    /** @return ?Pset */
    static function older_enabled_repo_same_handout(Pset $pset) {
        $result = false;
        foreach ($pset->conf->psets() as $p) {
            if ($p !== $pset
                && !$p->disabled
                && !$p->gitless
                && $p->handout_repo_url === $pset->handout_repo_url
                && (!$result || $result->deadline < $p->deadline)) {
                $result = $p;
            }
        }
        return $result;
    }

    /** @param Pset $pset
     * @param Pset $from_pset */
    private static function forward_pset_links($pset, $from_pset) {
        $links = [LINK_REPO, LINK_REPOVIEW, LINK_BRANCH];
        if ($pset->partner && $from_pset->partner) {
            array_push($links, LINK_PARTNER, LINK_BACKPARTNER);
        }
        $pset->conf->qe("insert into ContactLink (cid, type, pset, link)
            select l.cid, l.type, ?, l.link from ContactLink l where l.pset=? and l.type?a",
                        $pset->id, $from_pset->id, $links);
    }

    /** @param object $original
     * @param object $update
     * @return ?object */
    static private function psets_json_diff_from($original, $update) {
        $res = null;
        foreach (get_object_vars($update) as $k => $vu) {
            $vo = $original->$k ?? null;
            if (is_object($vo) && is_object($vu)) {
                if (!($vu = self::psets_json_diff_from($vo, $vu))) {
                    continue;
                }
            } else if ($vo === $vu) {
                continue;
            }
            $res = $res ?? (object) [];
            $res->$k = $vu;
        }
        return $res;
    }

    /** @return ?array */
    static private function psetconfig_post(Pset $pset, Qrequest $qreq) {
        $psetkey = $pset->key;

        $json = load_psets_json(true);
        object_merge_recursive($json->$psetkey, $json->_defaults);
        $old_pset = new Pset($pset->conf, $psetkey, $json->$psetkey);

        $o = (object) [];

        if (isset($qreq->state)) {
            if (!in_array($qreq->state, ["disabled", "hidden", "visible", "scores_visible", "default"])) {
                return ["ok" => false, "error" => "Bad `state` parameter"];
            }

            if ($qreq->state === "disabled") {
                $o->disabled = true;
            } else if ($old_pset->disabled && $qreq->state !== "default") {
                $o->disabled = false;
            } else {
                $o->disabled = null; // delete override
            }

            if ($qreq->state === "visible" || $qreq->state === "scores_visible") {
                $o->visible = true;
            } else if (!$old_pset->disabled && $old_pset->visible && $qreq->state !== "default") {
                $o->visible = false;
            } else {
                $o->visible = null; // delete override
            }

            if ($qreq->state === "scores_visible") {
                $o->scores_visible = true;
            } else if ($qreq->state === "visible" && $old_pset->scores_visible) {
                $o->scores_visible = false;
            } else {
                $o->scores_visible = null; // delete override
            }
        }

        if (isset($qreq->frozen)) {
            $o->frozen = null;
            if ($qreq->frozen !== "default") {
                $b = friendly_boolean($qreq->frozen);
                if ($b === null) {
                    return ["ok" => false, "error" => "Bad `frozen` parameter"];
                }
                if ($b !== $old_pset->frozen) {
                    $o->frozen = $b;
                }
            }
        }

        if (isset($qreq->anonymous)) {
            $o->anonymous = null;
            if ($qreq->anonymous !== "default") {
                $b = friendly_boolean($qreq->anonymous);
                if ($b === null) {
                    return ["ok" => false, "error" => "Bad `anonymous` parameter"];
                }
                if ($b !== $old_pset->anonymous) {
                    $o->anonymous = $b;
                }
            }
        }

        // maybe forward pset links
        if (($pset->disabled || !$pset->visible)
            && (!$pset->disabled || ($o->disabled ?? null) === false)
            && ($pset->visible || ($o->visible ?? null))
            && !$pset->gitless
            && !$pset->conf->fetch_ivalue("select exists (select * from ContactLink where pset=?)", $pset->id)
            && ($older_pset = self::older_enabled_repo_same_handout($pset))) {
            self::forward_pset_links($pset, $older_pset);
        }

        // save changes
        $dbjson = $pset->conf->setting_json("psets_override") ? : (object) array();
        object_replace_recursive($dbjson, (object) [$psetkey => $o]);
        $dbjson = self::psets_json_diff_from($json, $dbjson);
        $pset->conf->save_setting("psets_override", Conf::$now, $dbjson);
        return null;
    }

    static function psetconfig(Contact $user, Qrequest $qreq, APIData $api) {
        $pset = $api->pset;
        if (!$user->isPC && ($pset->disabled || !$pset->visible)) {
            return ["ok" => false, "error" => "Pset not found"];
        }
        if ($qreq->is_post()) {
            if (!$user->privChair) {
                return ["ok" => false, "error" => "Permission error"];
            }
            if (($e = self::psetconfig_post($api->pset, $qreq))) {
                return $e;
            }
            $json = load_psets_json(false);
            object_merge_recursive($json->{$pset->key}, $json->_defaults);
            $pset = new Pset($user->conf, $pset->key, $json->{$pset->key});
        }
        if ($pset->disabled) {
            $state = "disabled";
        } else if (!$pset->visible) {
            $state = "hidden";
        } else if (!$pset->scores_visible) {
            $state = "visible";
        } else {
            $state = "scores_visible";
        }
        return [
            "ok" => true,
            "state" => $state,
            "frozen" => $pset->frozen,
            "anonymous" => $pset->anonymous
        ];
    }
}
