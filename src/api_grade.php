<?php
// api/api_grade.php -- Peteramati API for grading
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Grade_API {
    /** @var array<string,true|string> */
    public $errf = [];
    /** @var bool */
    public $diff = false;


    /** @param array<string,string> $j
     * @return array<string,mixed> */
    function error_json($j = []) {
        assert(!empty($this->errf));
        $j["ok"] = false;
        if (isset($this->errf["!invalid"])) {
            $j["error"] = "Invalid request.";
        } else {
            if (count($this->errf) === 1) {
                $j["error"] = (array_values($this->errf))[0];
            } else {
                $j["error"] = "Invalid grades.";
            }
            $j["errf"] = $this->errf;
        }
        return $j;
    }

    /** @param bool $isnew
     * @return array */
    private function parse_full_grades(PsetView $info, $x, $isnew) {
        if (is_string($x)) {
            $x = json_decode($x, true);
            if (!is_array($x)) {
                $this->errf["!invalid"] = true;
                return [];
            }
        } else if (is_object($x)) {
            $x = get_object_vars($x);
        } else if (!is_array($x)) {
            return [];
        }
        foreach ($x as $k => &$v) {
            if (($ge = $info->gradelike_by_key($k))) {
                $v = $ge->parse_value($v, $isnew);
                if ($v instanceof GradeError && !isset($this->errf[$k])) {
                    $this->errf[$k] = $v->message;
                }
            }
        }
        return $x;
    }

    /** @param PsetView $info */
    private function apply_grades($info, $g, $ag, $og) {
        $v = [];
        foreach ($info->pset->grades() as $ge) {
            $k = $ge->key;
            $oldgv = $info->grade_value($ge);
            if (array_key_exists($k, $og)) {
                if ($ge->value_differs($oldgv, $og[$k])
                    && (!array_key_exists($k, $g)
                        || $ge->value_differs($oldgv, $g[$k]))) {
                    $this->errf[$k] = "Edit conflict.";
                }
            }
            $has_agv = false;
            $agv = null;
            if (array_key_exists($k, $ag)
                && $info->pc_view) {
                $v["autogrades"][$k] = $agv = $ag[$k];
                $has_agv = true;
            }
            if (array_key_exists($k, $g)) {
                $gv = $g[$k];
                if (!$has_agv
                    && ($notes = $info->grade_jnotes())
                    && isset($notes->autogrades)) {
                    $agv = $notes->autogrades->$k ?? null;
                }
                $allowed = $ge->allow_edit($gv, $oldgv, $agv, $info);
                if ($allowed === true) {
                    $v["grades"][$k] = $gv ?? (isset($agv) ? false : null);
                    if ($ge->answer) {
                        $v["linenotes"]["/g/{$ge->key}"] = null;
                    }
                    $this->diff = $this->diff || $ge->value_differs($gv, $oldgv);
                } else {
                    $this->errf[$k] = $allowed->message;
                }
            }
        }
        if (array_key_exists("late_hours", $g) && $info->pc_view) {
            // XXX separate permission check?
            $gv = GradeEntry::parse_numeric_value($g["late_hours"]);
            $ogv = isset($og["late_hours"]) ? GradeEntry::parse_numeric_value($og["late_hours"]) : null;
            if ($gv instanceof GradeError) {
                $this->errf["late_hours"] = $gv->message;
            } else {
                $curlhd = $info->late_hours_data() ? : (object) [];
                $lh = $curlhd->hours ?? 0;
                $alh = $curlhd->autohours ?? $lh;
                if ($ogv
                    && !($ogv instanceof GradeError)
                    && abs($ogv - $lh) >= 0.0001) {
                    $this->errf["late_hours"] = true;
                } else if ($gv === null
                           || abs($gv - $alh) < 0.0001) {
                    $v["late_hours"] = null;
                } else {
                    $v["late_hours"] = $gv;
                }
            }
        }
        return $v;
    }

    static function grade(Contact $user, Qrequest $qreq, APIData $api) {
        $info = PsetView::make($api->pset, $api->user, $user);
        if (($err = $api->prepare_grading_commit($info))) {
            return $err;
        }
        $known_entries = $qreq->knowngrades ? explode(" ", $qreq->knowngrades) : null;
        $gapi = new Grade_API;
        // XXX match commit with grading commit
        if ($qreq->is_post()) {
            if (!$qreq->valid_post()) {
                return ["ok" => false, "error" => "Missing credentials."];
            } else if ($info->is_handout_commit()) {
                return ["ok" => false, "error" => "Cannot set grades on handout commit."];
            } else if (!$info->pset->gitless_grades
                       && $qreq->commit_is_grade
                       && $info->grading_hash() !== $info->commit_hash()) {
                return ["ok" => false, "error" => "The grading commit has changed."];
            }

            // parse grade elements
            $g = $gapi->parse_full_grades($info, $qreq->get_a("grades"), true);
            $ag = $gapi->parse_full_grades($info, $qreq->get_a("autogrades"), false);
            $og = $gapi->parse_full_grades($info, $qreq->get_a("oldgrades"), false);
            if (!empty($gapi->errf)) {
                return $gapi->error_json();
            }

            // assign grades
            $v = $gapi->apply_grades($info, $g, $ag, $og);
            if (!empty($gapi->errf)) {
                return $gapi->error_json((array) $info->grade_json(0, $known_entries));
            } else if (!empty($v)) {
                $info->update_grade_notes($v);
            }
        } else if (!$info->can_view_some_grade()) {
            return ["ok" => false, "error" => "Permission error."];
        }
        $j = (array) $info->grade_json(0, $known_entries);
        $j["ok"] = true;
        if ($gapi->diff
            && !$info->pc_view
            && ($to = $info->timermark_timeout(null))
            && $to < Conf::$now) {
            $j["answer_timeout"] = true;
        }
        return $j;
    }

    /** @return ?array */
    static function parse_users($us) {
        $ux = $us ? json_decode($us) : null;
        if (is_object($ux)) {
            return (array) $ux;
        } else if (is_array($ux)) {
            $uy = [];
            foreach ($ux as $u) {
                if (isset($u->uid) && is_int($u->uid) && !isset($uy[$u->uid])) {
                    $uy[$u->uid] = $u;
                } else {
                    return null;
                }
            }
            return $uy;
        } else {
            return null;
        }
    }

    /** @return StudentSet */
    static function student_set($uids, Contact $viewer, APIData $api) {
        $uidlist = [];
        foreach ($uids as $uid) {
            if ($uid && (is_int($uid) || ctype_digit($uid))) {
                $uidlist[] = is_int($uid) ? $uid : intval($uid);
            }
        }
        $us = $api->conf->users_by_id($uidlist);
        if (count($us) !== count($uids)) {
            throw new Error("Invalid users.");
        }
        foreach ($us as $u) {
            if ($u->contactId !== $viewer->contactId && !$viewer->isPC)
                throw new Error("Permission error.");
        }
        return StudentSet::make_for($us, $viewer);
    }

    static function update_error(&$error, $errno, $s) {
        if ($errno > $error[0]) {
            $error[0] = $errno;
            $error[1] = $s ? [$s] : [];
        } else if ($errno === $error[0] && $s) {
            $error[1][] = $s;
        }
    }

    static function multigrade(Contact $viewer, Qrequest $qreq, APIData $api) {
        if (($ugs = self::parse_users($qreq->us)) === null) {
            return ["ok" => false, "error" => "Missing parameter."];
        } else if ($qreq->is_post() && !$qreq->valid_post()) {
            return ["ok" => false, "error" => "Missing credentials."];
        }
        try {
            $sset = self::student_set(array_keys($ugs), $viewer, $api);
            $sset->set_pset($api->pset);
        } catch (Error $err) {
            return ["ok" => false, "error" => $err->getMessage()];
        }

        $error = [0, ""];
        foreach ($sset as $uid => $info) {
            // XXX extract the following into a function
            // XXX branch nonsense
            if (!$info->can_view_some_grade()
                || ($qreq->is_post() && !$info->can_edit_scores())) {
                return ["ok" => false, "error" => "Permission error for user " . $info->user_linkpart() . "."];
            }
            if (!$api->pset->gitless_grades) {
                if (!$info->repo) {
                    self::update_error($error, 5, $info->user_linkpart());
                    continue;
                }
                if (isset($ugs[$uid]->commit) && $ugs[$uid]->commit !== $info->hash()) {
                    $info->set_hash($ugs[$uid]->commit);
                }
                if (!$info->hash()) {
                    self::update_error($error, isset($ugs[$uid]->commit) ? 4 : 3, $info->user_linkpart());
                    continue;
                }
                if (($ugs[$uid]->commit_is_grade ?? false)
                    && !$info->is_grading_commit()) {
                    self::update_error($error, 2, $info->user_linkpart());
                }
                if ($qreq->is_post()
                    && $info->is_handout_commit()) {
                    self::update_error($error, 1, $info->user_linkpart());
                }
            }
        }

        if (count($sset) === 0) {
            return ["ok" => false, "error" => "No users."];
        } else if ($error[0] === 5) {
            return ["ok" => false, "error" => "Missing repository (" . join(", ", $error[1]) . ")."];
        } else if ($error[0] === 4) {
            return ["ok" => false, "error" => "Disconnected commit."];
        } else if ($error[0] === 3) {
            return ["ok" => false, "error" => "Missing commit (" . join(", ", $error[1]) . ")."];
        } else if ($error[0] === 2) {
            return ["ok" => false, "error" => "The grading commit has changed."];
        } else if ($error[0] === 1) {
            return ["ok" => false, "error" => "Cannot set grades on handout commit."];
        }

        // XXX match commit with grading commit
        $gexp = new GradeExport($api->pset);
        $gexp->export_entries();
        $jx = $gexp->jsonSerialize();
        $jx["ok"] = true;
        if ($qreq->is_post()) {
            // parse grade elements
            $g = $ag = $og = [];
            $gapi = new Grade_API;
            foreach ($sset as $uid => $info) {
                $gx = $ugs[$uid];
                $g[$uid] = $gapi->parse_full_grades($info, $gx->grades ?? null, true);
                $ag[$uid] = $gapi->parse_full_grades($info, $gx->autogrades ?? null, false);
                $og[$uid] = $gapi->parse_full_grades($info, $gx->oldgrades ?? null, false);
            }
            if (!empty($gapi->errf)) {
                return $gapi->error_json();
            }

            // assign grades
            $v = [];
            foreach ($ugs as $uid => $gx) {
                $v[$uid] = $gapi->apply_grades($sset[$uid], $g[$uid], $ag[$uid], $og[$uid]);
            }
            if (!empty($gapi->errf)) {
                $jx["ok"] = false;
                $jx["error"] = "Grade edit conflict, your update was ignored.";
            } else {
                foreach ($ugs as $uid => $gx) {
                    if (!empty($v[$uid]))
                        $sset[$uid]->update_grade_notes($v[$uid]);
                }
            }
        }
        $jx["us"] = [];
        foreach ($sset as $uid => $info) {
            $jx["us"][] = $info->grade_json(PsetView::GRADEJSON_SLICE);
        }
        return $jx;
    }

    /** @param PsetView $info
     * @param ?StudentSet $sset */
    static private function gradesettings1($ug, $info, &$old_pset, $sset) {
        $pset = $info->pset;
        if (property_exists($ug, "scores_visible")
            && ($pset->gitless_grades || $info->repo)) {
            $info->set_pinned_scores_visible($ug->scores_visible);
        }
        if (isset($ug->gradercid)
            && ($pset->gitless_grades || $info->repo)) {
            if (!$pset->gitless_grades && !$info->grading_hash()) {
                $info->repo->refresh(2700, true);
                $info->set_latest_nontrivial_commit($sset);
                if ($info->hash()) {
                    $info->mark_grading_commit();
                }
            }
            if ($pset->gitless_grades || $info->hash()) {
                $info->change_grader($ug->gradercid);
            }
        }
        if ((($ug->clearrepo ?? false) || ($ug->adoptoldrepo ?? false))
            && !$pset->gitless) {
            if (($ug->clearrepo ?? false) && $info->repo) {
                $info->user->set_repo($pset, null);
                $info->user->clear_links(LINK_BRANCH, $pset->id);
                $info->reload_repo();
            }
            if (($ug->adoptoldrepo ?? false) && !$info->repo) {
                $old_pset = $old_pset ?? PsetConfig_API::older_enabled_repo_same_handout($pset);
                $old_repo = $old_pset ? $info->user->repo($old_pset->id) : null;
                if ($old_repo) {
                    $info->user->set_repo($pset, $old_repo);
                    if (($b = $info->user->branchid($old_pset))) {
                        $info->user->set_link(LINK_BRANCH, $old_pset->id, $b);
                    }
                    $info->reload_repo();
                }
            }
        }
    }

    static function gradesettings(Contact $viewer, Qrequest $qreq, APIData $api) {
        if (($ugs = self::parse_users($qreq->us)) === null) {
            return ["ok" => false, "error" => "Missing parameter"];
        } else if ($qreq->is_post() && !$qreq->valid_post()) {
            return ["ok" => false, "error" => "Missing credentials"];
        } else if (!$viewer->privChair) {
            return ["ok" => false, "error" => "Permission error"];
        }
        try {
            $sset = self::student_set(array_keys($ugs), $viewer, $api);
            $sset->set_pset($api->pset);
        } catch (Error $err) {
            return ["ok" => false, "error" => $err->getMessage()];
        }
        if (count($sset) === 0) {
            return ["ok" => false, "error" => "No users"];
        }

        // XXX match commit with grading commit
        $pcm = $api->conf->pc_members_and_admins();
        if ($qreq->is_post()) {
            foreach ($sset as $uid => $info) {
                $ug = $ugs[$uid];
                if (isset($ug->scores_visible) && !is_bool($ug->scores_visible)) {
                    return ["ok" => false, "error" => "Invalid `scores_visible` request"];
                }
                if (isset($ug->gradercid)
                    && (!is_int($ug->gradercid)
                        || ($ug->gradercid !== 0 && !isset($pcm[$ug->gradercid])))) {
                    return ["ok" => false, "error" => "Invalid `gradercid` request"];
                }
                if (isset($ug->clearrepo) && !is_bool($ug->clearrepo)) {
                    return ["ok" => false, "error" => "Invalid `clearrepo` request"];
                }
                if (isset($ug->adoptoldrepo) && !is_bool($ug->adoptoldrepo)) {
                    return ["ok" => false, "error" => "Invalid `adoptoldrepo` request"];
                }
            }
            $old_pset = null;
            foreach ($sset as $uid => $info) {
                self::gradesettings1($ugs[$uid], $info, $old_pset, $sset);
            }
        }
        $j = ["ok" => true, "us" => []];
        foreach ($sset as $uid => $info) {
            if (!$api->pset->gitless_grades && !$info->repo) {
                $j["us"][] = [
                    "uid" => $uid,
                    "error" => "No repository"
                ];
            } else {
                $j["us"][] = [
                    "uid" => $uid,
                    "scores_visible" => $info->pinned_scores_visible(),
                    "gradercid" => $info->gradercid()
                ];
            }
        }
        return $j;
    }
}
