<?php

// download
function report_set($s, $k, $total, $total_noextra, $normfactor) {
    $s->$k = $total;
    $x = "{$k}_noextra";
    $s->$x = $total_noextra;
    if ($normfactor !== null) {
        $x = "{$k}_norm";
        $s->$x = round($total * $normfactor);
        $x = "{$k}_noextra_norm";
        $s->$x = round($total_noextra * $normfactor);
    }
}

/** @param StudentSet $sset */
function collect_pset_info(&$students, $sset, $entries) {
    global $Conf, $Me, $Qreq;

    $pset = $sset->pset;
    if ($pset->category && $pset->weight) {
        $grp = $pset->category;
        $factor = (100.0 * $pset->weight) / ($pset->max_grade(VF_TF) * $Conf->category_weight($grp));
    } else {
        $grp = null;
        $factor = 1.0;
    }

    foreach ($sset as $info) {
        $s = $info->user;
        $ss = $students[$s->username] ?? null;
        if (!$ss && $s->is_anonymous) {
            $students[$s->username] = $ss = (object)
                array("username" => $s->username,
                      "extension" => ($s->extension ? "Y" : "N"),
                      "sorter" => $s->username,
                      "npartners" => 0);
        } else if (!$ss) {
            $students[$s->username] = $ss = (object)
                array("name" => trim("$s->lastName, $s->firstName"),
                      "email" => $s->email,
                      "username" => $s->username,
                      "anon_username" => $s->anon_username,
                      "huid" => $s->huid,
                      "extension" => ($s->extension ? "Y" : "N"),
                      "sorter" => $s->sorter,
                      "npartners" => 0);
        }

        if ($info->can_view_nonempty_score()) {
            list($total, $max, $total_noextra) = $info->visible_total();
            report_set($ss, $pset->key, $total, $total_noextra, 100.0 / $max);

            if ($grp) {
                $ss->{$grp} = (float) ($ss->{$grp} ?? 0.0) + $total * $factor;
                $k = "{$grp}_noextra";
                $ss->{$k} = (float) ($ss->{$k} ?? 0.0) + $total_noextra * $factor;
            }

            if ($entries) {
                foreach ($pset->tabular_grades() as $ge) {
                    if (($g = $info->grade_value($ge)) !== null)
                        $ss->{$ge->key} = $g;
                }
            }
        }

        if ($s->partnercid)
            ++$ss->npartners;
    }
}

function set_ranks(&$students, &$selection, $key, $round = false) {
    $selection[] = $key;
    $selection[] = $key . "_rank";
    if ($round) {
        foreach ($students as $s) {
            if (isset($s->$key)) {
                $s->$key = round($s->$key * 10) / 10;
            }
        }
    }
    uasort($students, function ($a, $b) use ($key) {
            $av = $a->$key ?? null;
            $bv = $b->$key ?? null;
            if (!$av) {
                return $bv ? 1 : -1;
            } else if (!$bv) {
                return $av ? -1 : 1;
            } else {
                return $av < $bv ? 1 : -1;
            }
        });
    $rank = $key . "_rank";
    $relrank = $key . "_rank_norm";
    $nstudents = count($students);
    $r = $i = 1;
    $rr = 100.0;
    $lastval = null;
    foreach ($students as $s) {
        if (($s->$key ?? null) != $lastval) {
            $lastval = $s->$key ?? null;
            $r = $i;
            $rr = round(($nstudents + 1 - $i) * 100.0 / $nstudents);
        }
        $s->{$rank} = $r;
        $s->{$relrank} = $rr;
        ++$i;
    }
}

function parse_formula($conf, &$t, $example, $minprec) {
    $t = ltrim($t);

    if ($t === "") {
        $e = null;
    } else if ($t[0] === "(") {
        $t = substr($t, 1);
        $e = parse_formula($conf, $t, $example, 0);
        if ($e !== null) {
            $t = ltrim($t);
            if ($t === "" || $t[0] !== ")") {
                return $e;
            }
            $t = substr($t, 1);
        }
    } else if ($t[0] === "-" || $t[0] === "+") {
        $op = $t[0];
        $t = substr($t, 1);
        $e = parse_formula($conf, $t, $example, 12);
        if ($e !== null && $op === "-") {
            $e = ["neg", $e];
        }
    } else if (preg_match('{\A(\d+\.?\d*|\.\d+)(.*)\z}s', $t, $m)) {
        $t = $m[2];
        $e = (float) $m[1];
    } else if (preg_match('{\A(?:pi|π|m_pi)(.*)\z}si', $t, $m)) {
        $t = $m[1];
        $e = (float) M_PI;
    } else if (preg_match('{\A(log10|log|ln|lg|exp)\b(.*)\z}s', $t, $m)) {
        $t = $m[2];
        $e = parse_formula($conf, $t, $example, 12);
        if ($e !== null) {
            $e = [$m[1], $e];
        }
    } else if (preg_match('{\A(\w+)(.*)\z}s', $t, $m)) {
        $t = $m[2];
        $k = $m[1];
        if ($k === "nstudents") {
            $e = $example->nstudents;
        } else {
            $kbase = $k;
            $noextra = false;
            $rank = $norm = "";
            while (true) {
                if (str_ends_with($kbase, "_noextra")) {
                    $kbase = substr($kbase, 0, -8);
                    $noextra = true;
                } else if (str_ends_with($kbase, "_norm") && !$norm) {
                    $kbase = substr($kbase, 0, -5);
                    $norm = "_norm";
                } else if (str_ends_with($kbase, "_rank") && !$rank) {
                    $kbase = substr($kbase, 0, -5);
                    $rank = "_rank";
                } else {
                    break;
                }
            }
            if (($pset = $conf->pset_by_key_or_title($kbase))) {
                $noextra = $noextra && $pset->has_extra;
                $kbase = $pset->key;
            } else if ($conf->pset_category($kbase)) {
                $noextra = $noextra && $conf->pset_category_has_extra($kbase);
            } else {
                return null;
            }
            $e = $kbase . ($noextra ? "_noextra" : "") . $rank . $norm;
        }
    } else {
        $e = null;
    }

    if ($e === null) {
        return null;
    }

    while (true) {
        $t = ltrim($t);
        if (preg_match('{\A(\+|-|\*\*?|/|%)(.*)\z}s', $t, $m)) {
            $op = $m[1];
            if ($op === "**") {
                $prec = 13;
            } else if ($op === "*" || $op === "/" || $op === "%") {
                $prec = 11;
            } else {
                $prec = 10;
            }
            if ($prec < $minprec) {
                return $e;
            }
            $t = $m[2];
            $e2 = parse_formula($conf, $t, $example, $op === "**" ? $prec : $prec + 1);
            if ($e === null) {
                return null;
            }
            $e = [$op, $e, $e2];
        } else {
            return $e;
        }
    }
}

function evaluate_formula($student, $formula) {
    if (is_float($formula)) {
        return $formula;
    } else if (is_string($formula)) {
        if (property_exists($student, $formula)) {
            return $student->$formula;
        } else {
            return 0.0;
        }
    } else {
        $ex = [];
        for ($i = 1; $i !== count($formula); ++$i) {
            $e = evaluate_formula($student, $formula[$i]);
            if ($e === null) {
                return null;
            }
            $ex[] = $e;
        }
        switch ($formula[0]) {
        case "+":
            return $ex[0] + $ex[1];
        case "-":
            return $ex[0] - $ex[1];
        case "*":
            return $ex[0] * $ex[1];
        case "/":
            return $ex[0] / $ex[1];
        case "%":
            return $ex[0] % $ex[1];
        case "**":
            return $ex[0] ** $ex[1];
        case "log":
        case "log10":
            return log10($ex[0]);
        case "ln":
            return log($ex[0]);
        case "lg":
            return log($ex[0]) / log(2);
        case "exp":
            return exp($ex[0]);
        default:
            return null;
        }
    }
}

function download_psets_report($request) {
    global $Conf, $Me;
    $where = array();
    $report = $request["report"];
    $anonymous = null;
    $ssflags = StudentSet::ENROLLED;
    foreach (explode(" ", strtolower($report)) as $rep) {
        if ($rep === "college") {
            $ssflags |= StudentSet::COLLEGE;
        } else if ($rep === "extension") {
            $ssflags |= StudentSet::DCE;
        } else if ($rep === "nonanonymous") {
            $anonymous = false;
        }
    }
    $sset = new StudentSet($Me, $ssflags);

    $sel_pset = null;
    if (($request["pset"] ?? null)
        && !($sel_pset = $Conf->pset_by_key($request["pset"]))) {
        return $Conf->errorMsg("No such pset");
    }

    $students = [];
    if (isset($request["fields"])) {
        $selection = explode(",", $request["fields"]);
    } else {
        $selection = ["name", "username", "anon_username", "email", "huid", "extension", "npartners"];
    }

    $grouped_psets = $sel_pset ? ["" => [$sel_pset]] : $Conf->psets_by_category();

    foreach ($grouped_psets as $grp => $psets) {
        foreach ($psets as $pset) {
            $sset->set_pset($pset, $anonymous);

            collect_pset_info($students, $sset, !!$sel_pset);
            set_ranks($students, $selection, $pset->key);
            if ($pset->has_extra) {
                set_ranks($students, $selection, "{$pset->key}_noextra");
            }
        }
    }

    foreach ($grouped_psets as $grp => $psets) {
        if ($grp !== "") {
            set_ranks($students, $selection, $grp, true);
            if ($Conf->pset_category_has_extra($grp)) {
                set_ranks($students, $selection, "{$grp}_noextra", true);
            }
        }
    }

    $example = (object) ["nstudents" => count($students)];

    if (!$sel_pset) {
        foreach ($Conf->config->_report_summaries ?? [] as $fname => $formula) {
            $fexpr = parse_formula($Conf, $formula, $example, 0);
            if ($fexpr !== null && trim($formula) === "") {
                foreach ($students as $s) {
                    $s->$fname = round(evaluate_formula($s, $fexpr) * 10) / 10;
                }
                set_ranks($students, $selection, $fname);
            } else {
                error_log("bad formula $fname @$formula");
            }
        }
    }

    if ($sel_pset) {
        foreach ($sel_pset->grades() as $ge) {
            $selection[] = $ge->key;
        }
    }

    $csv = new CsvGenerator;
    $csv->select($selection);
    usort($students, function ($a, $b) {
        return strcasecmp($a->name, $b->name);
    });
    foreach ($students as $s) {
        $csv->add_row(get_object_vars($s));
    }
    $csv->set_filename("gradereport.csv");
    $csv->download_headers();
    $csv->download();
    exit;
}

if ($Me->isPC && $Qreq->valid_token() && $Qreq->report) {
    download_psets_report($Qreq);
}

