<?php
// api/api_grade.php -- Peteramati API for grading
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Grade_API {
    /** @param array<string,string> &$errf
     * @param bool $isnew
     * @return array */
    static private function parse_full_grades(PsetView $info, $x, &$errf, $isnew) {
        if (is_string($x)) {
            $x = json_decode($x, true);
            if (!is_array($x)) {
                $errf["!invalid"] = true;
                return [];
            }
        } else if (is_object($x)) {
            $x = (array) $x;
        } else if (!is_array($x)) {
            return [];
        }
        foreach ($x as $k => &$v) {
            if (($ge = $info->gradelike_by_key($k))) {
                $v = $ge->parse_value($v, $isnew);
                if ($v === false && !isset($errf[$k])) {
                    $errf[$k] = $ge->parse_value_error();
                }
            }
        }
        return $x;
    }

    /** @param PsetView $info
     * @param array<string,string> &$errf */
    static private function apply_grades($info, $g, $ag, $og, &$errf) {
        $v = [];
        foreach ($info->pset->grades() as $ge) {
            $k = $ge->key;
            $oldgv = $info->grade_value($ge);
            if (array_key_exists($k, $og)) {
                if ($ge->value_differs($oldgv, $og[$k])
                    && (!array_key_exists($k, $g)
                        || $ge->value_differs($oldgv, $g[$k]))) {
                    $errf[$k] = "Edit conflict.";
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
                if ($ge->allow_edit($gv, $oldgv, $agv, $info)) {
                    $v["grades"][$k] = $gv ?? (isset($agv) ? false : null);
                    if ($ge->answer) {
                        $v["linenotes"]["/g/{$ge->key}"] = null;
                    }
                } else {
                    $errf[$k] = $ge->parse_value_error();
                }
            }
        }
        if (array_key_exists("late_hours", $g)
            && $info->pc_view // XXX separate permission check?
            && ($gv = GradeEntryConfig::parse_numeric_value($g["late_hours"])) !== false) {
            $curlhd = $info->late_hours_data() ? : (object) [];
            $lh = $curlhd->hours ?? 0;
            $alh = $curlhd->autohours ?? $lh;
            if (isset($og["late_hours"])
                && ($ogv = GradeEntryConfig::parse_numeric_value($og["late_hours"])) !== null
                && $ogv !== false
                && abs($ogv - $lh) >= 0.0001) {
                $errf["late_hours"] = true;
            } else if ($gv === null
                       || abs($gv - $alh) < 0.0001) {
                $v["late_hours"] = null;
            } else {
                $v["late_hours"] = $gv;
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
        // XXX match commit with grading commit
        if ($qreq->is_post()) {
            if (!check_post($qreq)) {
                return ["ok" => false, "error" => "Missing credentials."];
            } else if ($info->is_handout_commit()) {
                return ["ok" => false, "error" => "Cannot set grades on handout commit."];
            } else if (!$info->pset->gitless_grades
                       && $qreq->commit_is_grade
                       && $info->grading_hash() !== $info->commit_hash()) {
                return ["ok" => false, "error" => "The grading commit has changed."];
            }

            // parse grade elements
            $qreq->allow_a("grades", "autogrades", "oldgrades");
            $errf = [];
            $g = self::parse_full_grades($info, $qreq->grades, $errf, true);
            $ag = self::parse_full_grades($info, $qreq->autogrades, $errf, false);
            $og = self::parse_full_grades($info, $qreq->oldgrades, $errf, false);
            if (!empty($errf)) {
                if (isset($errf["!invalid"])) {
                    return ["ok" => false, "error" => "Invalid request."];
                } else {
                    reset($errf);
                    return ["ok" => false, "error" => (count($errf) === 1 ? current($errf) : "Invalid grades."), "errf" => $errf];
                }
            }

            // assign grades
            $v = self::apply_grades($info, $g, $ag, $og, $errf);
            if (!empty($errf)) {
                $j = (array) $info->grade_json(0, $known_entries);
                $j["ok"] = false;
                reset($errf);
                $j["error"] = current($errf);
                return $j;
            } else if (!empty($v)) {
                $info->update_grade_notes($v);
            }
        } else if (!$info->can_view_grade()) {
            return ["ok" => false, "error" => "Permission error."];
        }
        $j = (array) $info->grade_json(0, $known_entries);
        $j["ok"] = true;
        return $j;
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
        return StudentSet::make_for($viewer, $us);
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
        if (!isset($qreq->us)
            || !($ugs = json_decode($qreq->us))
            || !is_object($ugs)) {
            return ["ok" => false, "error" => "Missing parameter."];
        }
        $ugs = (array) $ugs;
        if ($qreq->is_post() && !$qreq->valid_post()) {
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
            if (!$info->can_view_grade()
                || ($qreq->is_post() && !$info->can_edit_scores())) {
                return ["ok" => false, "error" => "Permission error for user " . $info->user_linkpart() . "."];
            }
            if (!$api->pset->gitless) {
                if (!$info->repo) {
                    self::update_error($error, 5, $info->user_linkpart());
                    continue;
                }
                $commit = null;
                if (($hash = $ugs[$uid]->commit ?? null)) {
                    $commit = $info->pset->handout_commit($hash)
                        ?? $info->repo->connected_commit($hash, $info->pset, $info->branch);
                }
                if (!$commit) {
                    self::update_error($error, $hash ? 4 : 3, $info->user_linkpart());
                    continue;
                }
                $info->set_commit($commit);
                if (($ugs[$uid]->commit_is_grade ?? false)
                    && $commit->hash !== $info->grading_hash()) {
                    self::update_error($error, 2, $info->user_linkpart());
                }
                if ($qreq->is_post() && $info->is_handout_commit()) {
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
        if ($qreq->is_post()) {
            // parse grade elements
            $g = $ag = $og = $errf = [];
            foreach ($sset as $uid => $info) {
                $gx = $ugs[$uid];
                $g[$uid] = self::parse_full_grades($info, $gx->grades ?? null, $errf, true);
                $ag[$uid] = self::parse_full_grades($info, $gx->autogrades ?? null, $errf, false);
                $og[$uid] = self::parse_full_grades($info, $gx->oldgrades ?? null, $errf, false);
            }
            if (!empty($errf)) {
                reset($errf);
                if (isset($errf["!invalid"])) {
                    return ["ok" => false, "error" => "Invalid request."];
                } else {
                    return ["ok" => false, "error" => (count($errf) === 1 ? current($errf) : "Invalid grades."), "errf" => $errf];
                }
            }

            // assign grades
            $v = [];
            foreach ($ugs as $uid => $gx) {
                $v[$uid] = self::apply_grades($sset[$uid], $g[$uid], $ag[$uid], $og[$uid], $errf);
            }
            if (!empty($errf)) {
                $j = (array) $info->grade_json();
                $j["ok"] = false;
                $j["error"] = "Grade edit conflict, your update was ignored.";
                return $j;
            } else {
                foreach ($ugs as $uid => $gx) {
                    if (!empty($v[$uid]))
                        $sset[$uid]->update_grade_notes($v[$uid]);
                }
            }
        }
        $j = (new GradeExport($api->pset, true))->jsonSerialize();
        $j["ok"] = true;
        $j["us"] = [];
        foreach ($sset as $uid => $info) {
            $j["us"][$uid] = $info->grade_json(PsetView::GRADEJSON_SLICE);
        }
        return $j;
    }

    /** @param PsetView $info
     * @param Contact $user
     * @return array{ok:false,error:string}|array{ok:true,note:LineNote} */
    static private function apply_linenote($info, $user, $apply) {
        // check filename and line number
        if (!isset($apply->file)
            || !isset($apply->line)
            || !$apply->file
            || strlen($apply->line) < 2
            || ($apply->line[0] !== "a" && $apply->line[0] !== "b")
            || !ctype_digit(substr($apply->line, 1))) {
            return ["ok" => false, "error" => "Invalid request."];
        }

        // check permissions and filename
        if (!$info->pc_view) {
            return ["ok" => false, "error" => "Permission error."];
        } else if (str_starts_with($apply->file, "/g/")) {
            if (!($ge = $info->pset->grades[substr($apply->file, 3)])
                || !$ge->answer) {
                return ["ok" => false, "error" => "No such grade."];
            }
        } else if (str_starts_with($apply->file, "/")) {
            return ["ok" => false, "error" => "Invalid request."];
        } else if (!$info->repo) {
            return ["ok" => false, "error" => "Missing repository."];
        } else if ($info->hash() === null) {
            return ["ok" => false, "error" => "Missing commit."];
        } else if ($info->is_handout_commit()) {
            return ["ok" => false, "error" => "Refusing to leave note on handout commit."];
        }

        // find or create note
        $note = $info->line_note($apply->file, $apply->line);
        if (isset($apply->oldversion)
            && $apply->oldversion != +$note->version) {
            return ["ok" => false, "error" => "Edit conflict, you need to reload."];
        }

        // modify note
        if (array_search($user->contactId, $note->users) === false) {
            $note->users[] = $user->contactId;
        }
        $note->iscomment = isset($apply->iscomment) && $apply->iscomment;
        $note->text = rtrim(cleannl($apply->text ?? $apply->note ?? ""));
        $note->version = intval($note->version) + 1;
        if (isset($apply->format) && ctype_digit($apply->format)) {
            $note->format = intval($apply->format);
        }
        return ["ok" => true, "note" => $note];
    }

    static function linenote(Contact $user, Qrequest $qreq, APIData $api) {
        if ($qreq->line && ctype_digit($qreq->line)) {
            $qreq->line = "b" . $qreq->line;
        }

        // set up info, repo, commit
        $info = PsetView::make($api->pset, $api->user, $user);
        assert($api->repo === null || $api->repo === $info->repo);
        $api->repo = $info->repo;
        assert($info->repo !== null || $api->commit === null);
        if ($info->repo
            && $api->hash
            && !$api->commit
            && !($api->commit = $info->conf->check_api_hash($api->hash, $api))) {
            return ["ok" => false, "error" => "Disconnected commit."];
        }
        if ($api->commit) {
            $info->set_commit($api->commit);
        } else if (!$api->pset->has_answers) {
            return ["ok" => false, "error" => "Missing commit."];
        }

        // apply line notes
        if ($qreq->method() === "POST") {
            $ans = self::apply_linenote($info, $user, $qreq);
            if (!$ans["ok"]) {
                return $ans;
            }
            $ln = $ans["note"];
            if ($info->pset->gitless_grades
                && str_starts_with($ln->file, "/")) {
                $info->update_user_notes(["linenotes" => [$ln->file => [$ln->lineid => $ln]]]);
            } else {
                $info->update_commit_notes(["linenotes" => [$ln->file => [$ln->lineid => $ln]]]);
            }
        }

        if (!$user->can_view_comments($api->pset, $info)) {
            return ["ok" => false, "error" => "Permission error."];
        }

        $notes = [];
        $lnorder = $info->visible_line_notes();
        foreach ($lnorder->fileorder() as $file => $order) {
            if (!$qreq->file || $file === $qreq->file) {
                foreach ($lnorder->file($file) as $lineid => $note) {
                    if ((!$qreq->line || $lineid === $qreq->line)
                        && ($j = $note->render()))
                        $notes[$file][$lineid] = $j;
                }
            }
        }
        return ["ok" => true, "linenotes" => $notes];
    }
}
