<?php
// api/api_grade.php -- Peteramati API for grading
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class API_Grade {
    static function parse_full_grades($x) {
        if (is_string($x)) {
            $x = json_decode($x, true);
            if (!is_array($x))
                return false;
        }
        return is_array($x) ? $x : [];
    }

    static private function check_grade_entry(&$gv, GradeEntryConfig $ge, &$errors) {
        if (isset($gv[$ge->key])
            && ($gv[$ge->key] = $ge->parse_value($gv[$ge->key])) === false) {
            $errors = true;
        }
    }

    static function grade(Contact $user, Qrequest $qreq, APIData $api) {
        $info = PsetView::make($api->pset, $api->user, $user);
        if (($err = $api->prepare_grading_commit($info))) {
            return $err;
        }
        if (!$info->can_view_grades()) {
            return ["ok" => false, "error" => "Permission error."];
        }
        if ($qreq->method() === "POST") {
            if (!check_post($qreq)) {
                return ["ok" => false, "error" => "Missing credentials."];
            } else if ($info->is_handout_commit()) {
                return ["ok" => false, "error" => "This is a handout commit."];
            } else if (!$user->can_set_grades($info->pset, $info)) {
                return ["ok" => false, "error" => "Permission error."];
            }

            // parse full grades
            $g = self::parse_full_grades($qreq->grades);
            $ag = self::parse_full_grades($qreq->autogrades);
            $og = self::parse_full_grades($qreq->oldgrades);
            if ($g === false || $ag === false || $og === false) {
                return ["ok" => false, "error" => "Invalid request."];
            }

            // add grade elements
            foreach ($qreq as $k => $v) {
                if (preg_match('_\A(auto|old|)grades\[(.*)\]\z_', $k, $m)) {
                    if ($m[1] === "") {
                        $g[$m[2]] = $v;
                    } else if ($m[1] === "auto") {
                        $ag[$m[2]] = $v;
                    } else {
                        $og[$m[2]] = $v;
                    }
                }
            }

            // check grade entries
            $errors = false;
            foreach ($info->pset->grades() as $ge) {
                self::check_grade_entry($g, $ge, $errors);
                self::check_grade_entry($ag, $ge, $errors);
                self::check_grade_entry($og, $ge, $errors);
            }
            if (($ge = $info->pset->late_hours_entry())) {
                self::check_grade_entry($g, $ge, $errors);
                self::check_grade_entry($og, $ge, $errors);
            }
            if ($errors) {
                return ["ok" => false, "error" => "Invalid request."];
            }

            // assign grades
            $gv = $agv = [];
            foreach ($api->pset->grades() as $ge) {
                if (array_key_exists($ge->key, $og)) {
                    $curgv = $info->current_grade_entry($ge->key);
                    if ($ge->value_differs($curgv, $og[$ge->key])) {
                        $j = (array) $info->grade_json();
                        $j["ok"] = false;
                        $j["error"] = "Grade edit conflict, your update was ignored.";
                        return $j;
                    }
                }
                if (array_key_exists($ge->key, $g)) {
                    $gv[$ge->key] = $g[$ge->key];
                }
                if (array_key_exists($ge->key, $ag)) {
                    $agv[$ge->key] = $ag[$ge->key];
                }
            }
            $v = [];
            if (!empty($gv)) {
                $v["grades"] = $gv;
            }
            if (!empty($agv)) {
                $v["autogrades"] = $agv;
            }
            if (array_key_exists("late_hours", $g)) { // XXX separate permission check?
                $curlhd = $info->late_hours_data() ? : (object) [];
                $lh = get($curlhd, "hours", 0);
                $alh = get($curlhd, "autohours", $lh);
                if ($og["late_hours"] !== null
                    && abs($og["late_hours"] - $lh) >= 0.0001) {
                    $j = (array) $info->grade_json();
                    $j["ok"] = false;
                    $j["error"] = "Grade edit conflict, your update was ignored. " . $og["late_hours"];
                    return $j;
                }
                if ($g["late_hours"] === null
                    || abs($g["late_hours"] - $alh) < 0.0001) {
                    $v["late_hours"] = null;
                } else {
                    $v["late_hours"] = $g["late_hours"];
                }
            }
            if (!empty($v)) {
                $info->update_grade_info($v);
            }
        }
        $j = (array) $info->grade_json();
        $j["ok"] = true;
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
