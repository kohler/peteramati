<?php

class API_Grade {
    static function parse_full_grades($x) {
        if (is_string($x)) {
            $x = json_decode($x, true);
            if (!is_array($x))
                return false;
        }
        return is_array($x) ? $x : [];
    }

    static function check_grade_entry(&$gv, $name, &$errors) {
        $g = get($gv, $name);
        if ($g === null || is_int($g) || is_float($g))
            return $g;
        else if (is_string($g)) {
            if ($g === "")
                $g = null;
            else if (preg_match('_\A\+?\d+\z_', $g))
                $g = intval($g);
            else if (preg_match('_\A\+?(?:\d+\.|\.\d)\d*\z_', $g))
                $g = floatval($g);
            else {
                $g = false;
                $errors = true;
            }
        } else {
            $errors = true;
            $g = false;
        }
        return $gv[$name] = $g;
    }

    static function grade(Contact $user, Qrequest $qreq, APIData $api) {
        $info = new PsetView($api->pset, $api->user, $user);
        $hash = null;
        if (!$api->pset->gitless_grades) {
            if (!$api->repo)
                return ["ok" => false, "error" => "Missing repository."];
            $api->commit = $api->conf->check_api_hash($api->hash, $api);
            if (!$api->commit)
                return ["ok" => false, "error" => ($api->hash ? "Missing commit." : "Disconnected commit.")];
            $info->force_set_hash($api->commit->hash);
        }
        if (!$info->can_view_grades())
            return ["ok" => false, "error" => "Permission error."];
        if ($qreq->method() === "POST") {
            if (!check_post($qreq))
                return ["ok" => false, "error" => "Missing credentials."];
            if ($info->is_handout_commit())
                return ["ok" => false, "error" => "This is a handout commit."];
            if (!$user->can_set_grades($info->pset, $info))
                return ["ok" => false, "error" => "Permission error."];

            // parse full grades
            $g = self::parse_full_grades($qreq->grades);
            $ag = self::parse_full_grades($qreq->autogrades);
            $og = self::parse_full_grades($qreq->oldgrades);
            if ($g === false || $ag === false || $og === false)
                return ["ok" => false, "error" => "Invalid request."];

            // add grade elements
            foreach ($qreq as $k => $v)
                if (preg_match('_\A(auto|old|)grades\[(.*)\]\z_', $k, $m)) {
                    if ($m[1] === "")
                        $g[$m[2]] = $v;
                    else if ($m[1] === "auto")
                        $ag[$m[2]] = $v;
                    else
                        $og[$m[2]] = $v;
                }

            // check grade entries
            $errors = false;
            foreach ($info->pset->grades as $ge) {
                self::check_grade_entry($g, $ge->key, $errors);
                self::check_grade_entry($ag, $ge->key, $errors);
                self::check_grade_entry($og, $ge->key, $errors);
            }
            if ($errors)
                return ["ok" => false, "error" => "Invalid request."];

            // assign grades
            $gv = $agv = [];
            foreach ($api->pset->grades as $ge) {
                if (array_key_exists($ge->key, $og)) {
                    $curgv = $info->current_grade_entry($ge->key);
                    if ($og[$ge->key] === null
                        ? $curgv !== null
                        : $curgv === null || abs($curgv - $og[$ge->key]) >= 0.0001) {
                        $j = (array) $info->grade_json();
                        $j["ok"] = false;
                        $j["error"] = "Grade edit conflict, your update was ignored.";
                        return $j;
                    }
                }
                if (array_key_exists($ge->key, $g))
                    $gv[$ge->key] = $g[$ge->key];
                if (array_key_exists($ge->key, $ag))
                    $agv[$ge->key] = $ag[$ge->key];
            }
            $v = [];
            if (!empty($gv))
                $v["grades"] = $gv;
            if (!empty($agv))
                $v["autogrades"] = $agv;
            if (!empty($v))
                $info->update_current_info($v);
        }
        $j = (array) $info->grade_json();
        $j["ok"] = true;
        return $j;
    }

    static function linenote(Contact $user, Qrequest $qreq, APIData $api) {
        $info = new PsetView($api->pset, $api->user, $user);
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
            $note->note = (string) $qreq->note;
            $note->version = +$note->version + 1;

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
