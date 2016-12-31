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
            else if (preg_match('_\A(?:0|[1-9]\d*)\z_', $g))
                $g = intval($g);
            else if (preg_match('_\A(?:0\.|\.\d|[1-9]\d*\.)\d*\z_', $g))
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
            if (!$qreq->commit)
                return ["ok" => false, "error" => "Missing commit."];
            $c = $api->repo->connected_commit($qreq->commit);
            if (!$c)
                return ["ok" => false, "error" => "Disconnected commit."];
            $info->force_set_hash($c->hash);
        }
        if (!$user->can_view_grades($api->pset, $info))
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
                self::check_grade_entry($g, $ge->name, $errors);
                self::check_grade_entry($ag, $ge->name, $errors);
                self::check_grade_entry($og, $ge->name, $errors);
            }
            if ($errors)
                return ["ok" => false, "error" => "Invalid request."];

            // assign grades
            $gv = $agv = [];
            foreach ($api->pset->grades as $ge) {
                if (array_key_exists($ge->name, $og)) {
                    $curgv = $info->current_grade_entry($ge->name);
                    if ($og[$ge->name] === null
                        ? $curgv !== null
                        : $curgv === null || abs($curgv - $og[$ge->name]) >= 0.0001) {
                        $j = (array) $info->grade_json();
                        $j["ok"] = false;
                        $j["error"] = "Grades have been updated, please reload.";
                        return $j;
                    }
                }
                if (array_key_exists($ge->name, $g))
                    $gv[$ge->name] = $g[$ge->name];
                if (array_key_exists($ge->name, $ag))
                    $agv[$ge->name] = $ag[$ge->name];
            }
            $v = [];
            if (!empty($gv))
                $v["grades"] = $gv;
            if (!empty($agv))
                $v["autogrades"] = $agv;
            if (!empty($v))
                $info->update_current_info($v);
        }
        $j = (array) $info->grade_json2();
        $j["ok"] = true;
        return $j;
    }
}
