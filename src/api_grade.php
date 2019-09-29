<?php
// api/api_grade.php -- Peteramati API for grading
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class API_Grade {
    static private function parse_full_grades($pset, $x, &$errf) {
        if (is_string($x)) {
            $x = json_decode($x, true);
            if (!is_array($x)) {
                $errf["!invalid"] = true;
                return [];
            }
        } else if (is_object($x)) {
            $x = (array) $x;
        }
        if (is_array($x)) {
            foreach ($x as $k => &$v) {
                if (($ge = $pset->gradelike_by_key($k))) {
                    $v = $ge->parse_value($v);
                    if ($v === false && !isset($errf[$k]))
                        $errf[$k] = $ge->parse_value_error();
                }
            }
            return $x;
        } else {
            return [];
        }
    }

    static private function apply_grades($info, $g, $ag, $og, &$errf) {
        $v = [];
        foreach ($info->pset->grades() as $ge) {
            if (array_key_exists($ge->key, $og)) {
                $curgv = $info->current_grade_entry($ge->key);
                if ($ge->value_differs($curgv, $og[$ge->key])
                    && (!array_key_exists($ge->key, $g)
                        || $ge->value_differs($curgv, $g[$ge->key])))
                    $errf[$ge->key] = true;
            }
            if (array_key_exists($ge->key, $g)) {
                $v["grades"][$ge->key] = $g[$ge->key];
            }
            if (array_key_exists($ge->key, $ag)) {
                $v["autogrades"][$ge->key] = $ag[$ge->key];
            }
        }
        if (array_key_exists("late_hours", $g)) { // XXX separate permission check?
            $curlhd = $info->late_hours_data() ? : (object) [];
            $lh = get($curlhd, "hours", 0);
            $alh = get($curlhd, "autohours", $lh);
            if ($og["late_hours"] !== null
                && abs($og["late_hours"] - $lh) >= 0.0001) {
                $errf["late_hours"] = true;
            } else if ($g["late_hours"] === null
                       || abs($g["late_hours"] - $alh) < 0.0001) {
                $v["late_hours"] = null;
            } else {
                $v["late_hours"] = $g["late_hours"];
            }
        }
        return $v;
    }

    static function grade(Contact $user, Qrequest $qreq, APIData $api) {
        $info = PsetView::make($api->pset, $api->user, $user);
        if (($err = $api->prepare_grading_commit($info))) {
            return $err;
        }
        if (!$info->can_view_grades() || !$user->isPC) {
            return ["ok" => false, "error" => "Permission error."];
        }
        // XXX match commit with grading commit
        if ($qreq->method() === "POST") {
            if (!check_post($qreq)) {
                return ["ok" => false, "error" => "Missing credentials."];
            } else if ($info->is_handout_commit()) {
                return ["ok" => false, "error" => "This is a handout commit."];
            } else if (!$user->can_set_grades($info->pset, $info)) {
                return ["ok" => false, "error" => "Permission error."];
            }

            // parse grade elements
            $qreq->allow_a("grades", "autogrades", "oldgrades");
            $errf = [];
            $g = self::parse_full_grades($info->pset, $qreq->grades, $errf);
            $ag = self::parse_full_grades($info->pset, $qreq->autogrades, $errf);
            $og = self::parse_full_grades($info->pset, $qreq->oldgrades, $errf);
            if (!empty($errf)) {
                reset($errf);
                if (isset($errf["!invalid"]))
                    return ["ok" => false, "error" => "Invalid request."];
                else
                    return ["ok" => false, "error" => (count($errf) === 1 ? current($errf) : "Invalid grades."), "errf" => $errf];
            }

            // assign grades
            $v = self::apply_grades($info, $g, $ag, $og, $errf);
            if (!empty($errf)) {
                $j = (array) $info->grade_json();
                $j["ok"] = false;
                $j["error"] = "Grade edit conflict, your update was ignored.";
                return $j;
            } else if (!empty($v)) {
                $info->update_grade_info($v);
            }
        }
        $j = (array) $info->grade_json();
        $j["ok"] = true;
        return $j;
    }

    static function multigrade(Contact $viewer, Qrequest $qreq, APIData $api) {
        if (!isset($qreq->us)
            || !($ugs = json_decode($qreq->us))
            || !is_object($ugs)) {
            return ["ok" => false, "error" => "Missing parameter."];
        }
        $ugs = (array) $ugs;
        $ispost = $qreq->method() === "POST";
        if ($ispost && !$qreq->post_ok()) {
            return ["ok" => false, "error" => "Missing credentials."];
        }

        $infos = [];
        $errno = 0;
        foreach (array_keys($ugs) as $uid) {
            if (!$uid
                || (!is_int($uid) && !ctype_digit($uid))
                || !($u = $viewer->conf->user_by_id($uid))
                || ($u->contactId != $viewer->contactId
                    && !$viewer->isPC)) {
                return ["ok" => false, "error" => "Permission error."];
            }
            $u->set_anonymous($api->pset->anonymous);

            $info = PsetView::make($api->pset, $u, $viewer);
            // XXX extract the following into a function
            // XXX branch nonsense
            if (!$api->pset->gitless) {
                if (!$info->repo) {
                    $errno = max($errno, 4);
                    continue;
                }
                $commit = null;
                if (($hash = get($ugs[$uid], "commit"))) {
                    $commit = $info->pset->handout_commits($hash)
                        ? : $info->repo->connected_commit($hash, $info->pset, $info->branch);
                }
                if (!$commit) {
                    $errno = max($errno, $hash ? 2 : 3);
                    continue;
                }
                $info->force_set_hash($commit->hash);
            }
            if (!$info->can_view_grades()
                || ($ispost && !$viewer->can_set_grades($info->pset, $info)))
                return ["ok" => false, "error" => "Permission error."];
            else if ($ispost && $info->is_handout_commit())
                $errno = max($errno, 1);
            $infos[$u->contactId] = $info;
        }

        if ($errno === 4)
            return ["ok" => false, "error" => "Missing repository."];
        else if ($errno === 3)
            return ["ok" => false, "error" => "Disconnected commit."];
        else if ($errno === 2)
            return ["ok" => false, "error" => "Missing commit."];
        else if ($errno === 1)
            return ["ok" => false, "error" => "Cannot set grades on handout commit."];

        // XXX match commit with grading commit
        if ($ispost) {
            // parse grade elements
            $g = $ag = $og = $errf = [];
            foreach ($ugs as $uid => $gx) {
                $g[$uid] = self::parse_full_grades($api->pset, get($gx, "grades"), $errf);
                $ag[$uid] = self::parse_full_grades($api->pset, get($gx, "autogrades"), $errf);
                $og[$uid] = self::parse_full_grades($api->pset, get($gx, "oldgrades"), $errf);
            }
            if (!empty($errf)) {
                reset($errf);
                if (isset($errf["!invalid"]))
                    return ["ok" => false, "error" => "Invalid request."];
                else
                    return ["ok" => false, "error" => (count($errf) === 1 ? current($errf) : "Invalid grades."), "errf" => $errf];
            }

            // assign grades
            $v = [];
            foreach ($ugs as $uid => $gx) {
                $v[$uid] = self::apply_grades($infos[$uid], $g[$uid], $ag[$uid], $og[$uid], $errf);
            }
            if (!empty($errf)) {
                $j = (array) $info->grade_json();
                $j["ok"] = false;
                $j["error"] = "Grade edit conflict, your update was ignored.";
                return $j;
            } else {
                foreach ($ugs as $uid => $gx) {
                    if (!empty($v[$uid]))
                        $infos[$uid]->update_grade_info($v[$uid]);
                }
            }
        }
        $j = (array) $api->pset->gradeentry_json(true);
        $j["ok"] = true;
        $j["us"] = [];
        foreach ($infos as $uid => $info) {
            $j["us"][$uid] = $infos[$uid]->grade_json(true);
        }
        return $j;
    }

    static function linenote(Contact $user, Qrequest $qreq, APIData $api) {
        $info = PsetView::make($api->pset, $api->user, $user);
        $info->set_commit($api->commit);
        if ($qreq->line && ctype_digit($qreq->line))
            $qreq->line = "b" . $qreq->line;

        if ($qreq->method() === "POST") {
            if (!$qreq->file || !$qreq->line
                || !preg_match('/\A[ab]\d+\z/', $qreq->line))
                return ["ok" => false, "error" => "Invalid request."];
            if (!$info->can_edit_line_note($qreq->file, $qreq->line))
                return ["ok" => false, "error" => "Permission error."];
            if ($info->is_handout_commit())
                return ["ok" => false, "error" => "This is a handout commit."];

            $note = $info->current_line_note($qreq->file, $qreq->line);
            if (isset($qreq->oldversion) && $qreq->oldversion != +$note->version)
                return ["ok" => false, "error" => "Edit conflict, you need to reload."];

            if (array_search($user->contactId, $note->users) === false)
                $note->users[] = $user->contactId;
            $note->iscomment = !!$qreq->iscomment;
            $note->note = (string) rtrim(cleannl($qreq->note));
            $note->version = intval($note->version) + 1;
            if ($qreq->format && ctype_digit($qreq->format))
                $note->format = intval($qreq->format);

            $lnotes = ["linenotes" => [$qreq->file => [$qreq->line => $note]]];
            $info->update_current_info($lnotes);
        }

        if (!$user->can_view_comments($api->pset, $info))
            return ["ok" => false, "error" => "Permission error."];
        $can_view_grades = $info->can_view_grades();
        $can_view_note_authors = $info->can_view_note_authors();
        $notes = [];
        foreach ((array) $info->current_info("linenotes") as $file => $linemap) {
            if ($qreq->file && $file !== $qreq->file)
                continue;
            $filenotes = [];
            foreach ((array) $linemap as $lineid => $note) {
                $note = LineNote::make_json($file, $lineid, $note);
                if (($can_view_grades || $note->iscomment)
                    && (!$qreq->line || $qreq->line === $lineid))
                    $filenotes[$lineid] = $note->render_json($can_view_note_authors);
            }
            if (!empty($filenotes))
                $notes[$file] = $filenotes;
        }
        return ["ok" => true, "linenotes" => $notes];
    }
}
