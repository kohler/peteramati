<?php
// home.php -- Peteramati home page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");

// access only allowed through index.php
if (!$Conf) {
    exit;
}

global $Qreq, $MicroNow;
ContactView::set_path_request(array("/u"));

$Profile = $Me && $Me->privChair && $Qreq->profile;

// signin links
// auto-signin when email & password set
if (isset($_REQUEST["email"]) && isset($_REQUEST["password"])) {
    $_REQUEST["action"] = $_REQUEST["action"] ?? "login";
    $_REQUEST["signin"] = $_REQUEST["signin"] ?? "go";
}
// CSRF protection: ignore unvalidated signin/signout for known users
if (!$Me->is_empty() && !$Qreq->post_ok()) {
    unset($_REQUEST["signout"]);
}
if ($Me->has_email()
    && (!$Qreq->post_ok() || strcasecmp($Me->email, trim($Qreq->email)) == 0)) {
    unset($_REQUEST["signin"]);
}
if (!isset($_REQUEST["email"]) || !isset($_REQUEST["action"])) {
    unset($_REQUEST["signin"]);
}
// signout
if (isset($_REQUEST["signout"])) {
    $Me = LoginHelper::logout($Me, true);
} else if (isset($_REQUEST["signin"]) && !$Conf->opt("httpAuthLogin")) {
    $Me = LoginHelper::logout($Me, false);
}
// signin
if ($Conf->opt("httpAuthLogin")) {
    LoginHelper::check_http_auth($Me, $Qreq);
} else if (isset($_REQUEST["signin"])) {
    LoginHelper::login_redirect($Me->conf, $Qreq);
} else if ((isset($_REQUEST["signin"]) || isset($_REQUEST["signout"]))
           && isset($_REQUEST["post"])) {
    redirectSelf();
}

// set interesting user
$User = null;
if (isset($Qreq->u)
    && !($User = ContactView::prepare_user($Qreq->u))) {
    redirectSelf(array("u" => null));
}
if (!$Me->isPC || !$User) {
    $User = $Me;
}

// check problem set openness
if (!$Me->is_empty()
    && ($Me === $User || $Me->isPC)
    && $Qreq->set_username
    && $Qreq->post_ok()
    && ($repoclass = RepositorySite::$sitemap[$Qreq->reposite])
    && in_array($repoclass, RepositorySite::site_classes($Conf), true)) {
    if ($repoclass::save_username($User, $Qreq->username)) {
        redirectSelf();
    }
}

if (!$Me->is_empty() && $Qreq->set_repo !== null) {
    ContactView::set_repo_action($User, $Qreq);
}
if (!$Me->is_empty() && $Qreq->set_branch !== null) {
    ContactView::set_branch_action($User, $Qreq);
}
if ($Qreq->set_partner !== null) {
    ContactView::set_partner_action($User, $Qreq);
}

if ((isset($Qreq->set_drop) || isset($Qreq->set_undrop))
    && $Me->isPC
    && $User->is_student()
    && $Qreq->post_ok()) {
    $Conf->qe("update ContactInfo set dropped=? where contactId=?",
              isset($Qreq->set_drop) ? Conf::$now : 0, $User->contactId);
    $Conf->qe("delete from Settings where name like '__gradets.%'");
    redirectSelf();
}


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

function collect_pset_info(&$students, $sset, $entries) {
    global $Conf, $Me, $Qreq;

    $pset = $sset->pset;
    $grp = null;
    if ($pset->category && $pset->weight) {
        $grp = $pset->category;
        $max = $pset->max_grade(true);
        $factor = (100.0 * $pset->weight) / ($max * $Conf->category_weight($grp));
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

        if ($info->has_nonempty_assigned_grades()) {
            list($total, $max, $total_noextra) = $info->grade_total();
            report_set($ss, $pset->key, $total, $total_noextra, 100.0 / $max);

            if ($grp) {
                $ss->{$grp} = get_f($ss, $grp) + $total * $factor;
                $k = "{$grp}_noextra";
                $ss->{$k} = get_f($ss, $k) + $total_noextra * $factor;
            }

            if ($entries) {
                foreach ($pset->numeric_grades() as $ge) {
                    if (($g = $info->current_grade_entry($ge->key)) !== null)
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
        $t = $m[2];
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
            $ssflags |= StudentSet::EXTENSION;
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
    $csv->download_headers("gradereport.csv");
    $csv->download();
    exit;
}

if ($Me->isPC && $Qreq->post_ok() && $Qreq->report) {
    download_psets_report($Qreq);
}


function qreq_usernames(Qrequest $qreq) {
    $users = [];
    foreach ($qreq as $k => $v) {
        if (substr($k, 0, 2) === "s:"
            && $v
            && ($uname = urldecode(substr($k, 2))))
            $users[] = $uname;
    }
    if (empty($users) && $qreq->slist)
        $users = preg_split('/\s+/', $qreq->slist, -1, PREG_SPLIT_NO_EMPTY);
    return $users;
}

function qreq_users(Qrequest $qreq) {
    global $Conf;
    $users = [];
    foreach (qreq_usernames($qreq) as $uname) {
        if (($user = $Conf->user_by_whatever($uname)))
            $users[] = $user;
    }
    return $users;
}

function set_grader(Qrequest $qreq) {
    global $Conf, $Me;
    if (!($pset = $Conf->pset_by_key($qreq->pset))) {
        return $Conf->errorMsg("No such pset");
    } else if ($pset->gitless) {
        return $Conf->errorMsg("Pset has no repository");
    }

    // collect grader weights
    $graderw = [];
    foreach ($Conf->pc_members_and_admins() as $pcm) {
        if ($qreq->grader === "__random_tf__" && ($pcm->roles & Contact::ROLE_PC)) {
            if (in_array($pcm->email, ["cassels@college.harvard.edu", "tnguyenhuy@college.harvard.edu", "skandaswamy@college.harvard.edu"])) {
                $graderw[$pcm->contactId] = 1.0 / 1.5;
            } else if ($pcm->email === "yihehuang@g.harvard.edu") {
                continue;
            } else {
                $graderw[$pcm->contactId] = 1.0;
            }
            continue;
        }
        if (strcasecmp($pcm->email, $qreq->grader) == 0
            || $qreq->grader === "__random__"
            || ($qreq->grader === "__random_tf__" && ($pcm->roles & Contact::ROLE_PC))) {
            $graderw[$pcm->contactId] = 1.0;
        }
    }

    // enumerate grader positions
    $graderp = [];
    foreach ($graderw as $cid => $w) {
        if ($w > 0)
            $graderp[$cid] = 0.0;
    }
    if (!$qreq->grader || empty($graderp))
        return $Conf->errorMsg("No grader");

    foreach (qreq_users($qreq) as $user) {
        // XXX check if can_set_grader
        $info = PsetView::make($pset, $user, $Me);
        if ($info->repo) {
            $info->repo->refresh(2700, true);
        }
        if (!$info->set_hash(null)) {
            error_log("cannot set_hash for $user->email");
            continue;
        }
        // sort by position
        asort($graderp);
        // pick one of the lowest positions
        $gs = [];
        $gpos = null;
        foreach ($graderp as $cid => $pos) {
            if ($gpos === null || $gpos == $pos) {
                $gs[] = $cid;
                $gpos = $pos;
            } else {
                break;
            }
        }
        // account for grader
        $g = $gs[mt_rand(0, count($gs) - 1)];
        $info->change_grader($g);
        $graderp[$g] += $graderw[$g];
    }

    redirectSelf();
}

if ($Me->isPC && $Qreq->post_ok() && $Qreq->setgrader) {
    set_grader($Qreq);
}


function runmany($qreq) {
    global $Conf, $Me;
    if (!($pset = $Conf->pset_by_key($qreq->pset)) || $pset->disabled) {
        return $Conf->errorMsg("No such pset");
    }
    $users = [];
    foreach (qreq_users($qreq) as $user) {
        $users[] = $Me->user_linkpart($user);
    }
    if (empty($users)) {
        return $Conf->errorMsg("No users selected.");
    }
    $args = ["pset" => $pset->urlkey, "run" => $qreq->runner, "runmany" => 1, "users" => join(" ", $users)];
    if (str_ends_with($qreq->runner, ".ensure")) {
        $args["run"] = substr($qreq->runner, 0, -7);
        $args["ensure"] = 1;
    }
    Navigation::redirect(hoturl_post("run", $args));
    redirectSelf();
}

if ($Me->isPC && $Qreq->post_ok() && $Qreq->runmany) {
    runmany($Qreq);
}


/** @return ?Pset */
function older_enabled_repo_same_handout($pset) {
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

function doaction(Qrequest $qreq) {
    global $Conf, $Me;
    if (!($pset = $Conf->pset_by_key($qreq->pset)) || $pset->disabled) {
        return $Conf->errorMsg("No such pset");
    }
    $hiddengrades = null;
    if ($qreq->action === "showgrades") {
        $hiddengrades = -1;
    } else if ($qreq->action === "hidegrades") {
        $hiddengrades = 1;
    } else if ($qreq->action === "defaultgrades") {
        $hiddengrades = 0;
    } else if ($qreq->action === "clearrepo") {
        foreach (qreq_users($qreq) as $user) {
            $user->set_repo($pset, null);
            $user->clear_links(LINK_BRANCH, $pset->id);
        }
    } else if ($qreq->action === "copyrepo") {
        if (($old_pset = older_enabled_repo_same_handout($pset))) {
            foreach (qreq_users($qreq) as $user) {
                if (!$user->repo($pset)
                    && ($r = $user->repo($old_pset))) {
                    $user->set_repo($pset, $r);
                    if (($b = $user->branchid($old_pset)))
                        $user->set_link(LINK_BRANCH, $pset->id, $b);
                }
            }
        }
    } else if (str_starts_with($qreq->action, "grademany_")) {
        $g = $pset->all_grades[substr($qreq->action, 10)];
        assert($g && !!$g->landmark_range_file);
        Navigation::redirect(hoturl_post("diffmany", ["pset" => $pset->urlkey, "file" => $g->landmark_range_file, "lines" => "{$g->landmark_range_first}-{$g->landmark_range_last}", "users" => join(" ", qreq_usernames($qreq))]));
    } else if (str_starts_with($qreq->action, "diffmany_")) {
        Navigation::redirect(hoturl_post("diffmany", ["pset" => $pset->urlkey, "file" => substr($qreq->action, 9), "users" => join(" ", qreq_usernames($qreq))]));
    } else if ($qreq->action === "diffmany") {
        Navigation::redirect(hoturl_post("diffmany", ["pset" => $pset->urlkey, "users" => join(" ", qreq_usernames($qreq))]));
    }
    if ($hiddengrades !== null) {
        foreach (qreq_users($qreq) as $user) {
            $info = PsetView::make($pset, $user, $Me);
            if ($info->grading_hash()) {
                $info->set_hidden_grades($hiddengrades);
            }
        }
    }
    redirectSelf();
}

if ($Me->isPC && $Qreq->post_ok() && $Qreq->doaction) {
    doaction($Qreq);
}


function psets_json_diff_from($original, $update) {
    $res = null;
    foreach (get_object_vars($update) as $k => $vu) {
        $vo = get($original, $k);
        if (is_object($vo) && is_object($vu)) {
            if (!($vu = psets_json_diff_from($vo, $vu)))
                continue;
        } else if ($vo === $vu) {
            continue;
        }
        $res = $res ? : (object) array();
        $res->$k = $vu;
    }
    return $res;
}

function save_config_overrides($psetkey, $overrides, $json = null) {
    global $Conf, $Qreq;

    $dbjson = $Conf->setting_json("psets_override") ? : (object) array();
    $all_overrides = (object) array();
    $all_overrides->$psetkey = $overrides;
    object_replace_recursive($dbjson, $all_overrides);
    $dbjson = psets_json_diff_from($json ? : load_psets_json(true), $dbjson);
    $Conf->save_setting("psets_override", Conf::$now, $dbjson);

    unset($_GET["pset"], $_REQUEST["pset"], $Qreq->pset);
    redirectSelf(array("anchor" => $psetkey));
}

function forward_pset_links($conf, $pset, $from_pset) {
    $links = [LINK_REPO, LINK_REPOVIEW, LINK_BRANCH];
    if ($pset->partner && $from_pset->partner) {
        array_push($links, LINK_PARTNER, LINK_BACKPARTNER);
    }
    $conf->qe("insert into ContactLink (cid, type, pset, link)
        select l.cid, l.type, ?, l.link from ContactLink l where l.pset=? and l.type?a",
              $pset->id, $from_pset->id, $links);
}

function reconfig($qreq) {
    global $Conf, $Me;
    if (!($pset = $Conf->pset_by_key($qreq->pset))
        || $pset->admin_disabled) {
        return $Conf->errorMsg("No such pset");
    }
    $psetkey = $pset->key;

    $json = load_psets_json(true);
    object_merge_recursive($json->$psetkey, $json->_defaults);
    $old_pset = new Pset($Conf, $psetkey, $json->$psetkey);

    $o = (object) array();
    $o->disabled = $o->visible = $o->grades_visible = null;
    $state = $_POST["state"] ?? null;
    if ($state === "disabled") {
        $o->disabled = true;
    } else if ($old_pset->disabled) {
        $o->disabled = false;
    }
    if ($state === "visible" || $state === "grades_visible") {
        $o->visible = true;
    } else if (!$old_pset->disabled && $old_pset->visible) {
        $o->visible = false;
    }
    if ($state === "grades_visible") {
        $o->grades_visible = true;
    } else if ($state === "visible" && $old_pset->grades_visible) {
        $o->grades_visible = false;
    }

    if (($_POST["frozen"] ?? null) === "yes") {
        $o->frozen = true;
    } else {
        $o->frozen = ($old_pset->frozen ? false : null);
    }

    if (($_POST["anonymous"] ?? null) === "yes") {
        $o->anonymous = true;
    } else {
        $o->anonymous = ($old_pset->anonymous ? false : null);
    }

    if (($pset->disabled || !$pset->visible)
        && (!$pset->disabled || (isset($o->disabled) && !$o->disabled))
        && ($pset->visible || (isset($o->visible) && $o->visible))
        && !$pset->gitless
        && !$Conf->fetch_ivalue("select exists (select * from ContactLink where pset=?)", $pset->id)
        && ($older_pset = older_enabled_repo_same_handout($pset))) {
        forward_pset_links($Conf, $pset, $older_pset);
    }

    save_config_overrides($psetkey, $o, $json);
}

if ($Me->privChair && $Qreq->post_ok() && $Qreq->reconfig)
    reconfig($Qreq);


// check global system settings
if ($Me->privChair)
    require_once("adminhome.php");


// Enable users
if ($Me->privChair
    && $Qreq->post_ok()
    && (isset($Qreq->enable_user)
        || isset($Qreq->send_account_info)
        || isset($Qreq->reset_password))) {
    $who = $who_user = null;
    if (isset($Qreq->u)) {
        $who_user = $Qreq->u;
    } else if (isset($Qreq->enable_user)) {
        $who = $Qreq->enable_user;
    } else if (isset($Qreq->send_account_info)) {
        $who = $Qreq->send_account_info;
    } else if (isset($Qreq->reset_password)) {
        $who = $Qreq->reset_password;
    }
    if ($who_user !== null) {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where email=?", $who_user);
    } else if ($who === "college") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension");
    } else if ($who === "college-empty") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension and password=''");
    } else if ($who === "college-nologin") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and not extension and lastLogin=0");
    } else if ($who === "extension") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and extension");
    } else if ($who === "extension-empty") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and extension and password=''");
    } else if ($who === "extension-nologin") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")=0 and extension and lastLogin=0");
    } else if ($who === "ta") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")!=0");
    } else if ($who === "ta-empty") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")!=0 and password=''");
    } else if ($who === "ta-nologin") {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where (roles&" . Contact::ROLE_PCLIKE . ")!=0 and lastLogin=0");
    } else {
        $users = Dbl::fetch_first_columns("select contactId from ContactInfo where email like ?", $who);
    }
    if (empty($users)) {
        $Conf->warnMsg("No users match “" . htmlspecialchars($who) . "”.");
    } else {
        if (isset($Qreq->enable_user)) {
            UserActions::enable($users, $Me);
        } else if (isset($Qreq->reset_password)) {
            UserActions::reset_password($users, $Me, isset($Qreq->ifempty) && $Qreq->ifempty);
        } else {
            UserActions::send_account_info($users, $Me);
        }
        redirectSelf();
    }
}


$title = (!$Me->is_empty() && !isset($_REQUEST["signin"]) ? "Home" : "Sign in");
$Conf->header($title, "home");
$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";

if ($Me->privChair)
    echo "<div id='clock_drift_container'></div>";


// Sidebar
echo "<div class='homeside'>";
echo "<noscript><div class='homeinside'>",
    "<strong>Javascript required.</strong> ",
    "Many features will work without Javascript, but not all.<br />",
    "<a style='font-size:smaller' href='http://read.seas.harvard.edu/~kohler/hotcrp/'>Report bad compatibility problems</a></div></noscript>";
echo "</div>\n";

// Conference management
if ($Me->privChair && (!$User || $User === $Me)) {
    echo "<div id='homeadmin'>
  <h3>administration</h3>
  <ul>
    <!-- <li><a href='", hoturl("settings"), "'>Settings</a></li>
    <li><a href='", hoturl("users", "t=all"), "'>Users</a></li> -->
    <li><a href='", hoturl("mail"), "'>Send mail</a></li>\n";

    $pclike = Contact::ROLE_PCLIKE;
    $result = $Conf->qe("select exists (select * from ContactInfo where password='' and disabled=0 and (roles=0 or (roles&$pclike)=0) and college=1), exists (select * from ContactInfo where password='' and disabled=0 and (roles=0 or (roles&$pclike)=0) and extension=1), exists (select * from ContactInfo where password='' and disabled=0 and roles!=0 and (roles&$pclike)!=0), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and (roles=0 or (roles&$pclike)=0) and lastLogin=0 and college=1), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and (roles=0 or (roles&$pclike)=0) and lastLogin=0 and extension=1), exists (select * from ContactInfo where password!='' and disabled=0 and dropped=0 and roles!=0 and (roles&$pclike)!=0 and lastLogin=0)");
    $row = $result->fetch_row();
    Dbl::free($result);

    $m = [];
    if ($row[0])
        $m[] = '<a href="' . hoturl_post("index", "enable_user=college-empty") . '">college users</a>';
    if ($row[1])
        $m[] = '<a href="' . hoturl_post("index", "enable_user=extension-empty") . '">extension users</a>';
    if ($row[2])
        $m[] = '<a href="' . hoturl_post("index", "enable_user=ta-empty") . '">TAs</a>';
    if (!empty($m))
        echo '    <li>Enable ', join(", ", $m), "</li>\n";

    $m = [];
    if ($row[3])
        $m[] = '<a href="' . hoturl_post("index", "send_account_info=college-nologin") . '">college users</a>';
    if ($row[4])
        $m[] = '<a href="' . hoturl_post("index", "send_account_info=extension-nologin") . '">extension users</a>';
    if ($row[5])
        $m[] = '<a href="' . hoturl_post("index", "send_account_info=ta-nologin") . '">TAs</a>';
    if (!empty($m))
        echo '    <li>Send account info to ', join(", ", $m), "</li>\n";

    echo "    <li><a href='", hoturl_post("index", "report=nonanonymous"), "'>Overall grade report</a> (<a href='", hoturl_post("index", "report=nonanonymous+college"), "'>college</a>, <a href='", hoturl_post("index", "report=nonanonymous+extension"), "'>extension</a>)</li>
    <!-- <li><a href='", hoturl("log"), "'>Action log</a></li> -->
  </ul>
</div>\n";
}

if ($Me->isPC && $User === $Me) {
    $a = [];
    foreach ($Conf->psets_newest_first() as $pset) {
        if ($Me->can_view_pset($pset) && !$pset->disabled)
            $a[] = '<a href="#' . $pset->urlkey . '">' . htmlspecialchars($pset->title) . '</a>';
    }
    if (!empty($a)) {
        echo '<div class="home-pset-links"><h4>', join(" • ", $a), '</h4></div>';
    }
}

// Home message
if (($v = $Conf->setting_data("homemsg"))) {
    $Conf->infoMsg($v);
}


// Sign in
if ($Me->is_empty() || isset($_REQUEST["signin"])) {
    echo '<div class="homegrp">',
        'This is the ', htmlspecialchars($Conf->long_name), ' turnin site.
Sign in to tell us about your code.';
    if ($Conf->opt("conferenceSite")) {
        echo " For general information about ", htmlspecialchars($Conf->short_name), ", see <a href=\"", htmlspecialchars($Conf->opt("conferenceSite")), "\">the conference site</a>.";
    }
    $passwordFocus = Ht::problem_status_at("email") === 0 && Ht::problem_status_at("password") !== 0;
    echo "</div>
<hr class='home' />
<div class='homegrp' id='homeaccount'>\n",
        Ht::form(hoturl_post("index")),
        "<div class='f-contain foldo fold2o' id='logingroup'>
<input type='hidden' name='cookie' value='1' />
<div class=\"", Ht::control_class("email", "f-ii"), "\">
  <div class=\"f-c\"><span class='fx2'>",
    ($Conf->opt("ldapLogin") ? "Username" : "Email/username"),
    "</span><span class='fn2'>Email</span></div><div class=\"f-e\">",
    Ht::entry("email", $Qreq->email, ["size" => 36, "tabindex" => 1, "autofocus" => !$passwordFocus, "autocomplete" => "email"]),
    Ht::render_feedback_at("email"), "</div>
</div>
<div class=\"", Ht::control_class("password", "f-i fx"), "\">
  <div class=\"f-c\">Password</div><div class=\"f-e\">",
    Ht::password("password", "", ["size" => 36, "tabindex" => 1, "autofocus" => $passwordFocus, "autocomplete" => "current-password"]),
    Ht::render_feedback_at("password"), "</div>
</div>\n";
    if ($Conf->opt("ldapLogin")) {
        echo "<input type='hidden' name='action' value='login' />\n";
    } else {
        echo "<div class='f-i'>\n  ",
            Ht::radio("action", "login", true, ["id" => "signin_action_login", "tabindex" => 2, "class" => "uic pa-signin-radio"]),
        "&nbsp;", Ht::label("<b>Sign me in</b>"), "<br />\n";
        echo Ht::radio("action", "forgot", false, ["tabindex" => 2, "class" => "uic pa-signin-radio"]),
            "&nbsp;", Ht::label("I forgot my password"), "<br />\n";
        if (!$Conf->opt("disableNewUsers"))
            echo Ht::radio("action", "new", false, ["id" => "signin_action_new", "tabindex" => 2, "class" => "uic pa-signin-radio"]),
                "&nbsp;", Ht::label("Create an account"), "<br />\n";
        Ht::stash_script("\$('#homeaccount input[name=action]:checked').click()");
        echo "\n</div>\n";
    }
    echo '<div class="f-i"><button class="btn btn-primary" type="submit" name="signin" id="signin" tabindex="1" value="1">Sign in</button></div></div></form>',
        '<hr class="home"></div>', "\n";
}


// Top: user info
if (!$Me->is_empty() && (!$Me->isPC || $User !== $Me)) {
    echo "<div id='homeinfo'>";
    $u = $Me->user_linkpart($User);
    if ($User !== $Me && !$User->is_anonymous && $User->contactImageId) {
        echo '<img class="pa-face float-left" src="' . hoturl("face", array("u" => $Me->user_linkpart($User), "imageid" => $User->contactImageId)) . '" />';
    }
    echo '<h2 class="homeemail"><a class="q" href="',
        hoturl("index", ["u" => $u]), '">', htmlspecialchars($u), '</a>';
    if ($Me->privChair) {
        echo "&nbsp;", become_user_link($User);
    }
    echo '</h2>';
    if (!$User->is_anonymous && $User !== $Me) {
        echo '<h3>', Text::user_html($User), '</h3>';
    }

    if (!$User->is_anonymous) {
        RepositorySite::echo_username_forms($User);
    }

    if ($User->dropped) {
        ContactView::echo_group("", '<strong class="err">You have dropped the course.</strong> If this is incorrect, contact us.');
    }

    echo '<hr class="c" />', "</div>\n";
}


// Per-pset
function render_grades($pset, $gi, $s) {
    global $Me;
    $total = 0;
    $garr = $gvarr = $different = [];
    foreach ($pset->numeric_grades() as $ge) {
        $k = $ge->key;
        $gv = $ggv = $agv = null;
        if ($gi && isset($gi->grades)) {
            $ggv = $gi->grades->$k ?? null;
        }
        if ($gi && isset($gi->autogrades)) {
            $agv = $gi->autogrades->$k ?? null;
        }
        if ($ge->formula && $gi && isset($gi->formula)) {
            $ggv = $gi->formula->$k ?? null;
        }
        $gv = $ggv ?? $agv;
        if ($gv !== null && !$ge->no_total) {
            $total += $gv;
        }
        if ($gv === null
            && !$ge->is_extra
            && $s
            && $Me->contactId == $s->gradercid) {
            $s->incomplete = "grade missing";
        }
        $gvarr[] = $gv;
        if ($ggv && $agv && $ggv != $agv) {
            $different[$k] = true;
        }
    }
    return (object) ["allv" => $gvarr,  "totalv" => round_grade($total), "differentk" => $different];
}

function show_pset($pset, $user) {
    global $Me;
    if ($pset->gitless_grades
        && $Me === $user
        && !$pset->partner
        && !$pset->contact_grade_for($user)
        && !$pset->student_can_edit_grades()) {
        return;
    }
    echo "<hr>\n";
    $user_can_view = $user->can_view_pset($pset);
    if (!$user_can_view) {
        echo '<div class="pa-pset-hidden">';
    }
    $pseturl = hoturl("pset", ["pset" => $pset->urlkey, "u" => $Me->user_linkpart($user)]);
    echo "<h2><a class=\"btn\" style=\"font-size:inherit\" href=\"", $pseturl, "\">",
        htmlspecialchars($pset->title), "</a>";
    $info = PsetView::make($pset, $user, $Me);
    $grade_check_user = $Me->isPC && $Me != $user ? $user : $Me;
    if (($user_see_grade = $info->user_can_view_grades())) {
        $x = [];
        $c = null;
        if ($info->needs_student_grades()) {
            $x[] = "data missing";
            $c = "gradesmissing";
        }
        if ($info->has_nonempty_assigned_grades()) {
            $x[] = "grade ready";
            $c = "gradesready";
        }
        if ($x) {
            echo ' <a class="', $c, '" href="', $pseturl, '">(', join(", ", $x), ')</a>';
        }
    }
    echo "</h2>";
    ContactView::echo_deadline_group($info);
    ContactView::echo_partner_group($info);
    ContactView::echo_repo_group($info);
    if ($info->repo) {
        $info->repo->refresh(30);
    }
    if ($info->grading_hash()) {
        ContactView::echo_repo_grade_commit_group($info);
    } else {
        ContactView::echo_repo_last_commit_group($info, true);
    }
    if ($info->can_view_grades()
        && $info->has_nonempty_assigned_grades()
        && $info->needs_total()) {
        $tm = $info->grade_total();
        $t = "<strong>" . $tm[0] . "</strong>";
        if ($tm[1]) {
            $t .= " / " . $tm[1];
        }
        if (!$user_see_grade) {
            echo '<div class="pa-grp-hidden">';
        }
        ContactView::echo_group("grade", $t);
        if (!$user_see_grade) {
            echo '</div>';
        }
    }
    if ($info->repo && $user_can_view) {
        ContactView::echo_group("", '<strong><a href="' . $pseturl . '">view code</a></strong>');
    }
    if (!$user_can_view) {
        echo '</div>';
    }
    echo "\n";
}

if (!$Me->is_empty() && $User->is_student()) {
    $Conf->set_siteinfo("uservalue", $Me->user_linkpart($User));
    foreach ($Conf->psets_newest_first() as $pset) {
        if ($Me->can_view_pset($pset) && !$pset->disabled)
            show_pset($pset, $User);
    }
    if ($Me->isPC) {
        echo "<div style='margin-top:5em'></div>\n";
        if ($User->dropped) {
            echo Ht::form(hoturl_post("index", array("set_undrop" => 1,
                                                     "u" => $Me->user_linkpart($User)))),
                "<div>", Ht::submit("Undrop"), "</div></form>";
        } else {
            echo Ht::form(hoturl_post("index", array("set_drop" => 1,
                                                     "u" => $Me->user_linkpart($User)))),
                "<div>", Ht::submit("Drop"), "</div></form>";
        }
    }
    if ($Me->privChair && $User->can_enable()) {
        echo Ht::form(hoturl_post("index", ["enable_user" => 1,
                                            "u" => $Me->user_linkpart($User)])),
            Ht::submit("Enable"), "</form>";
    }
}

function render_regrade_row(Pset $pset, Contact $s = null, $row, $anonymous) {
    global $Conf, $Me, $Profile;
    $j = $s ? StudentSet::json_basics($s, $anonymous) : [];
    if (($gcid = get($row->notes, "gradercid"))) {
        $j["gradercid"] = $gcid;
    } else if ($row->main_gradercid) {
        $j["gradercid"] = $row->main_gradercid;
    }
    $j["psetid"] = $pset->id;
    $j["hash"] = bin2hex($row->bhash);
    if ($row->gradebhash === $row->bhash && $row->bhash !== null) {
        $j["is_grade"] = true;
    }
    if ($row->haslinenotes) {
        $j["has_notes"] = true;
    }
    if ($row->conversation) {
        if (strlen($row->conversation) < 40) {
            $j["conversation"] = $row->conversation;
        } else {
            $j["conversation_pfx"] = UnicodeHelper::utf8_word_prefix($row->conversation, 40);
        }
    }
    if ($row->notes) {
        $garr = render_grades($pset, $row->notes, null);
        $j["total"] = $garr->totalv;
    }
    $time = 0;
    if ($row->notes && isset($row->notes->flags)) {
        foreach ((array) $row->notes->flags as $k => $v) {
            if ($k[0] === "t" && ctype_digit(substr($k, 1))) {
                $thistime = +substr($k, 1);
            } else {
                $thistime = get($v, "at", 0);
            }
            $time = max($time, $thistime);
        }
    }
    if ($time) {
        $j["at"] = $time;
    }
    return $j;
}

function show_regrades($result, $all) {
    global $Conf, $Me, $Qreq;

    // 1. load commit notes
    $flagrows = $uids = $psets = [];
    while (($row = $result->fetch_object())) {
        $row->notes = json_decode($row->notes);
        $flags = (array) get($row->notes, "flags");
        $any = false;
        foreach ($flags as $t => $v) {
            if ($all || !get($v, "resolved")) {
                $uids[get($v, "uid", 0)] = $any = true;
                if (isset($v->conversation))
                    $row->conversation = (string) $v->conversation[0][2];
            }
        }
        if ($any) {
            $flagrows[] = $row;
            $psets[$row->pset] = true;
        }
    }
    Dbl::free($result);
    if (empty($flags)) {
        return;
    }

    // 2. load repouids and branches
    $repouids = $branches = $rgwanted = [];
    $result = $Conf->qe("select cid, type, pset, link from ContactLink where (type=" . LINK_REPO . " or type=" . LINK_BRANCH . ") and pset?a", array_keys($psets));
    while (($row = $result->fetch_row())) {
        if ($row[1] == LINK_REPO) {
            $repouids[$row[3] . "," . $row[2]][] = (int) $row[0];
            $rgwanted[] = "(repoid={$row[3]} and pset={$row[2]})";
        } else {
            $branches[$row[0] . "," . $row[2]] = (int) $row[3];
        }
    }
    Dbl::free($result);

    // 3. load RepositoryGrades
    $rgs = [];
    $result = $Conf->qe("select * from RepositoryGrade where " . join(" or ", $rgwanted));
    while (($row = $result->fetch_object())) {
        $rgs["{$row->repoid},{$row->pset},{$row->branchid}"] = $row;
    }
    Dbl::free($result);

    // 4. resolve `repocids`, `main_gradercid`, `gradebhash`
    foreach ($flagrows as $row) {
        $rowuids = [];
        foreach (get($repouids, "{$row->repoid},{$row->pset}", []) as $uid) {
            $rowuids[] = $uid;
            $uids[$uid] = true;

            $branch = get($branches, "{$uid},{$row->pset}", 0);
            $bkey = "{$row->repoid},{$row->pset},{$branch}";
            if (isset($rgs[$bkey])) {
                $row->main_gradercid = $rgs[$bkey]->gradercid;
                $row->gradebhash = $rgs[$bkey]->gradebhash;
            }
        }
        $row->repocids = join(",", $rowuids);
    }

    // 5. load users
    $result = $Conf->qe("select * from ContactInfo where contactId?a", array_keys($uids));
    while (($c = Contact::fetch($result, $Conf))) {
        $contacts[$c->contactId] = $c;
    }
    Dbl::free($result);

    echo '<div>';
    echo "<h3>flagged commits</h3>";
    $nintotal = 0;
    $anonymous = null;
    if ($Qreq->anonymous !== null && $Me->privChair)
        $anonymous = !!$Qreq->anonymous;
    $any_anonymous = $any_nonanonymous = false;
    $jx = [];
    foreach ($flagrows as $row) {
        if (!$row->repocids) {
            continue;
        }
        $pset = $Conf->pset_by_id($row->pset);
        $anon = $anonymous === null ? $pset->anonymous : $anonymous;
        $any_anonymous = $any_anonymous || $anon;
        $any_nonanonymous = $any_nonanonymous || !$anon;

        $partners = [];
        foreach (explode(",", $row->repocids) as $uid) {
            if (($c = get($contacts, $uid))) {
                $c->set_anonymous($anon);
                $partners[] = $c;
            }
        }
        usort($partners, "Contact::compare");

        $j = render_regrade_row($pset, $partners[0], $row, $anon);
        for ($i = 1; $i < count($partners); ++$i) {
            $j["partners"][] = render_regrade_row($pset, $partners[$i], $row, $anon);
        }
        $j["pos"] = count($jx);
        $jx[] = $j;
        if (isset($j["total"])) {
            ++$nintotal;
        }
    }
    echo '<table class="gtable" id="pa-pset-flagged"></table></div>', "\n";
    $jd = [
        "id" => "flagged",
        "flagged_commits" => true,
        "anonymous" => true,
        "has_nonanonymous" => $any_nonanonymous
    ];
    if ($Me->privChair) {
        $jd["can_override_anonymous"] = true;
    }
    if ($nintotal) {
        $jd["need_total"] = 1;
    }
    echo Ht::unstash(), '<script>$("#pa-pset-flagged").each(function(){$pa.render_pset_table.call(this,', json_encode_browser($jd), ',', json_encode_browser($jx), ')})</script>';
}

function show_pset_actions($pset) {
    global $Conf;

    echo Ht::form(hoturl_post("index", array("pset" => $pset->urlkey, "reconfig" => 1)), ["divstyle" => "margin-bottom:1em", "class" => "need-pa-pset-actions"]);
    $options = array("disabled" => "Disabled",
                     "invisible" => "Hidden",
                     "visible" => "Visible without grades",
                     "grades_visible" => "Visible with grades");
    if ($pset->disabled) {
        $state = "disabled";
    } else if (!$pset->visible) {
        $state = "invisible";
    } else if (!$pset->grades_visible) {
        $state = "visible";
    } else {
        $state = "grades_visible";
    }
    echo Ht::select("state", $options, $state);

    echo '<span class="pa-if-visible"> &nbsp;<span class="barsep">·</span>&nbsp; ',
        Ht::select("frozen", array("no" => "Student updates allowed", "yes" => "Submissions frozen"), $pset->frozen ? "yes" : "no"),
        '</span>';

    echo '<span class="pa-if-enabled"> &nbsp;<span class="barsep">·</span>&nbsp; ',
        Ht::select("anonymous", array("no" => "Open grading", "yes" => "Anonymous grading"), $pset->anonymous ? "yes" : "no"),
        '</span>';

    echo ' &nbsp;', Ht::submit("reconfig", "Save");

    if (!$pset->disabled) {
        echo ' &nbsp;<span class="barsep">·</span>&nbsp; ';
        echo Ht::link("Grade report", hoturl_post("index", ["pset" => $pset->urlkey, "report" => 1]), ["class" => "btn"]);
    }

    echo "</form>";
    echo Ht::unstash_script("$('.need-pa-pset-actions').each(\$pa.pset_actions)");
}

function render_pset_row(Pset $pset, $sset, PsetView $info, $anonymous) {
    global $Profile, $MicroNow;
    $t0 = microtime(true);
    $j = StudentSet::json_basics($info->user, $anonymous);
    if (($gcid = $info->gradercid())) {
        $j["gradercid"] = $gcid;
    }

    // are any commits committed?
    if (!$pset->gitless_grades && $info->repo) {
        if ($t0 - $MicroNow < 0.2
            && $pset->student_can_view_grades()) {
            $gh = $info->update_grading_hash(function ($info, $placeholder_at) use ($t0) {
                if ($placeholder_at && $placeholder_at < $t0 - 3600) {
                    return rand(0, 2) == 0;
                } else if ($placeholder_at < $t0 - 600 && !$info->user->dropped) {
                    return rand(0, 10) == 0;
                } else {
                    return false;
                }
            });
        } else {
            $gh = $info->grading_hash();
        }
        if ($gh !== null) {
            $j["gradehash"] = $gh;
        }
    }

    if ($pset->grades()) {
        if ($pset->has_formula) {
            $info->ensure_formula();
        }
        $gi = $info->current_info();

        if (!$pset->gitless_grades) {
            $gradercid = $info->gradercid();
            if ($gi && get($gi, "linenotes")) {
                $j["has_notes"] = true;
            } else if ($info->viewer->contactId == $gradercid) {
                $info->user->incomplete = "no line notes";
            }
            if ($gi
                && $gradercid != ($gi->gradercid ?? null)
                && $info->viewer->privChair) {
                $j["has_nongrader_notes"] = true;
            }
        }

        $garr = render_grades($pset, $gi, $info->user);
        $j["grades"] = $garr->allv;
        $j["total"] = $garr->totalv;
        if ($garr->differentk) {
            $j["highlight_grades"] = $garr->differentk;
        }
        if ($info->user_can_view_grades()) {
            $j["grades_visible"] = true;
        }
    }

    //echo "<td><a href=\"mailto:", htmlspecialchars($s->email), "\">",
    //htmlspecialchars($s->email), "</a></td>";

    if (!$pset->gitless && $info->repo) {
        $j["repo"] = RepositorySite::make_https_url($info->repo->url, $info->conf);
        if (!$info->repo->working) {
            $j["repo_broken"] = true;
        } else if (!$info->user_can_view_repo_contents(true)) {
            $j["repo_unconfirmed"] = true;
        }
        if ($info->repo->open) {
            $j["repo_too_open"] = true;
        }
        if ($pset->handout_warn_hash
            && isset($_GET["handout"])
            && !$info->repo->connected_commit($pset->handout_warn_hash, $pset, $info->branch)) {
            $j["repo_handout_old"] = true;
        }
        if (!$info->partner_same()) {
            $j["repo_partner_error"] = true;
        } else if ($pset->partner_repo
                   && $info->partner
                   && $info->repo
                   && $info->repo->repoid != $info->partner->link(LINK_REPO, $pset->id)) {
            $j["repo_partner_error"] = true;
        } else if ($sset->repo_sharing($info->user)) {
            $j["repo_sharing"] = true;
        }
    }

    $info->user->visited = true;
    return $j;
}

function show_pset_table($sset) {
    global $Profile, $Qreq;

    $pset = $sset->pset;
    echo '<div id="', $pset->urlkey, '">';
    echo "<h3>", htmlspecialchars($pset->title), "</h3>";
    if ($sset->viewer->privChair) {
        show_pset_actions($pset);
    }
    if ($pset->disabled) {
        echo "</div>\n";
        return;
    }

    $t0 = $Profile ? microtime(true) : 0;

/*
    // load links
    $restrict_repo_view = $Conf->opt("restrictRepoView");
    $result = $Conf->qe("select * from ContactLink where pset=$pset->id"
        . ($restrict_repo_view ? " or type=" . LINK_REPOVIEW : ""));
    $links = [];
    while (($link = $result->fetch_object("ContactLink"))) {
        if (!isset($links[$link->type]))
            $links[$link->type] = [];
        if (!isset($links[$link->type][$link->cid]))
            $links[$link->type][$link->cid] = [];
        $links[$link->type][$link->cid][] = $link;
    }
*/

    // load students
    $anonymous = $pset->anonymous;
    if ($Qreq->anonymous !== null && $sset->viewer->privChair) {
        $anonymous = !!$Qreq->anonymous;
    }

    $checkbox = $sset->viewer->isPC
        || (!$pset->gitless && $pset->runners);

    $rows = array();
    $incomplete = $incompleteu = [];
    $grades_visible = false;
    $jx = [];
    $gradercounts = [];
    foreach ($sset as $s) {
        if (!$s->user->visited) {
            $j = render_pset_row($pset, $sset, $s, $anonymous);
            if (!$s->user->dropped && isset($j["gradercid"])) {
                $gradercounts[$j["gradercid"]] = get($gradercounts, $j["gradercid"], 0) + 1;
            }
            if (!$pset->partner_repo) {
                foreach ($s->user->links(LINK_PARTNER, $pset->id) as $pcid) {
                    if (($ss = $sset->info($pcid)))
                        $j["partners"][] = render_pset_row($pset, $sset, $ss, $anonymous);
                }
            }
            $jx[] = $j;
            if ($s->user->incomplete) {
                $u = $sset->viewer->user_linkpart($s->user);
                $t = '<a href="' . hoturl("pset", ["pset" => $pset->urlkey, "u" => $u]) . '">'
                    . htmlspecialchars($u);
                if ($s->user->incomplete !== true) {
                    $t .= " (" . $s->user->incomplete . ")";
                }
                $incomplete[] = $t . '</a>';
                $incompleteu[] = "~" . urlencode($u);
            }
            if ($s->user_can_view_grades()) {
                $grades_visible = true;
            }
        }
    }

    if (!empty($incomplete)) {
        echo '<div id="incomplete_pset', $pset->id, '" class="merror hidden has-hotlist" data-hotlist="',
            htmlspecialchars(json_encode_browser(["pset" => $pset->urlkey, "items" => $incompleteu])),
            '"><strong>', htmlspecialchars($pset->title), '</strong>: ',
            'Your grading is incomplete. Missing grades: ', join(", ", $incomplete), '</div>',
            '<script>$("#incomplete_pset', $pset->id, '").remove().removeClass("hidden").appendTo("#incomplete_notices")</script>';
    }

    if ($checkbox) {
        echo Ht::form(hoturl_post("index", ["pset" => $pset->urlkey, "save" => 1]));
        if ($pset->anonymous) {
            echo Ht::hidden("anonymous", $anonymous ? 1 : 0);
        }
    }

    echo '<div class="gtable-container-0"><div class="gtable-container-1"><table class="gtable want-gtable-fixed" id="pa-pset' . $pset->id . '"></table></div></div>';
    $grades = $pset->numeric_grades();
    $jd = [
        "id" => $pset->id,
        "checkbox" => $checkbox,
        "anonymous" => $anonymous,
        "grades" => new GradeExport($pset, true),
        "gitless" => $pset->gitless,
        "gitless_grades" => $pset->gitless_grades,
        "key" => $pset->urlkey,
        "title" => $pset->title
    ];
    if ($anonymous) {
        $jd["can_override_anonymous"] = true;
    }
    $i = $nintotal = $last_in_total = 0;
    foreach ($grades as $ge) {
        if (!$ge->no_total) {
            ++$nintotal;
            $last_in_total = $ge->key;
        }
        ++$i;
    }
    if ($nintotal > 1) {
        $jd["need_total"] = true;
    } else if ($nintotal == 1) {
        $jd["total_key"] = $last_in_total;
    }
    if ($grades_visible) {
        $jd["grades_visible"] = true;
    }
    echo Ht::unstash(), '<script>$("#pa-pset', $pset->id, '").each(function(){$pa.render_pset_table.call(this,', json_encode_browser($jd), ',', json_encode_browser($jx), ')})</script>';

    if ($sset->viewer->privChair && !$pset->gitless_grades) {
        $sel = array("none" => "N/A");
        foreach ($sset->conf->pc_members_and_admins() as $uid => $pcm) {
            $n = ($gradercounts[$uid] ?? 0) ? " ({$gradercounts[$uid]})" : "";
            $sel[$pcm->email] = Text::name_html($pcm) . $n;
        }
        $sel["__random__"] = "Random";
        $sel["__random_tf__"] = "Random TF";
        echo '<span class="nb" style="padding-right:2em">',
            Ht::select("grader", $sel, "none"),
            ' &nbsp;', Ht::submit("setgrader", "Set grader"),
            '</span>';
    }

    $actions = [];
    if ($sset->viewer->isPC) {
        $stage = -1;
        $actions["diffmany"] = $pset->gitless ? "Grades" : "Diffs";
        if (!$pset->gitless_grades) {
            foreach ($pset->all_diffconfig() as $dc) {
                if (($dc->collate
                     || $dc->gradable
                     || ($dc->full && $dc->collate !== false))
                    && ($f = $dc->exact_filename())) {
                    if ($stage !== -1 && $stage !== 0)
                        $actions[] = null;
                    $stage = 0;
                    $actions["diffmany_$f"] = "$f diffs";
                }
            }
            if ($pset->has_grade_landmark) {
                foreach ($pset->grades() as $ge)
                    if ($ge->landmark_range_file) {
                        if ($stage !== -1 && $stage !== 1)
                            $actions[] = null;
                        $stage = 1;
                        $actions["grademany_{$ge->key}"] = "Grade {$ge->title}";
                    }
            }
        }
        if ($stage !== -1 && $stage !== 2)
            $actions[] = null;
        $stage = 2;
        $actions["showgrades"] = "Show grades";
        $actions["hidegrades"] = "Hide grades";
        $actions["defaultgrades"] = "Default grades";
        if (!$pset->gitless) {
            $actions["clearrepo"] = "Clear repo";
            if (older_enabled_repo_same_handout($pset)) {
                $actions["copyrepo"] = "Adopt previous repo";
            }
        }
    }
    if (!empty($actions)) {
        echo '<span class="nb" style="padding-right:2em">',
            Ht::select("action", $actions),
            ' &nbsp;', Ht::submit("doaction", "Go"),
            '</span>';
    }

    $sel = ["__run_group" => ["optgroup", "Run"]];
    $esel = ["__ensure_group" => ["optgroup", "Ensure"]];
    foreach ($pset->runners as $r) {
        if ($sset->viewer->can_run($pset, $r)) {
            $sel[$r->name] = htmlspecialchars($r->title);
            $esel[$r->name . ".ensure"] = htmlspecialchars($r->title);
        }
    }
    if (count($sel) > 1) {
        echo '<span class="nb" style="padding-right:2em">',
            Ht::select("runner", $sel + $esel),
            ' &nbsp;', Ht::submit("runmany", "Run all"),
            '</span>';
    }

    if ($checkbox) {
        echo "</form>\n";
    }

    if ($Profile) {
        $t2 = microtime(true);
        echo sprintf("<div>Δt %.06f total</div>", $t2 - $t0);
    }

    echo "</div>\n";
}

if (!$Me->is_empty() && $Me->isPC && $User === $Me) {
    echo '<div id="incomplete_notices"></div>', "\n";
    $sep = "";
    $t0 = $Profile ? microtime(true) : 0;

    $psetj = [];
    foreach ($Conf->psets() as $pset) {
        if ($Me->can_view_pset($pset)) {
            $pj = [
                "title" => $pset->title, "urlkey" => $pset->urlkey,
                "pos" => count($psetj)
            ];
            if ($pset->gitless) {
                $pj["gitless"] = true;
            }
            if ($pset->gitless || $pset->gitless_grades) {
                $pj["gitless_grades"] = true;
            }
            $psetj[$pset->psetid] = $pj;
        }
    }
    $Conf->set_siteinfo("psets", $psetj);

    $pctable = [];
    foreach ($Conf->pc_members_and_admins() as $pc) {
        if (($pc->nickname || $pc->firstName) && !$pc->nicknameAmbiguous) {
            $pctable[$pc->contactId] = $pc->nickname ? : $pc->firstName;
        } else {
            $pctable[$pc->contactId] = Text::name_text($pc);
        }
    }
    $Conf->stash_hotcrp_pc($Me);

    $allflags = !!$Qreq->allflags;
    $field = $allflags ? "hasflags" : "hasactiveflags";
    $result = Dbl::qe("select *, null as main_gradercid, null as gradebhash, null as repocids, null as conversation from CommitNotes where $field=1");
    if ($result->num_rows) {
        echo $sep;
        show_regrades($result, $allflags);
        if ($Profile) {
            echo "<div>Δt ", sprintf("%.06f", microtime(true) - $t0), "</div>";
        }
        $sep = "<hr />\n";
    }

    $sset = null;
    $MicroNow = microtime(true);
    foreach ($Conf->psets_newest_first() as $pset) {
        if ($Me->can_view_pset($pset)) {
            if (!$sset) {
                $flags = 0;
                if ($Qreq->extension) {
                    $flags |= StudentSet::EXTENSION;
                }
                if ($Qreq->college) {
                    $flags |= StudentSet::COLLEGE;
                }
                $sset = new StudentSet($Me, $flags);
            }
            $sset->set_pset($pset);
            echo $sep;
            show_pset_table($sset);
            $sep = "<hr />\n";
        }
    }
}

echo "<div class='clear'></div>\n";
$Conf->footer();
