<?php
// src/p_home_ta.php -- Peteramati home page for PC
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Home_TA_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var bool */
    public $profile = false;
    /** @var float */
    public $tinit;
    /** @var float */
    public $t0;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
    }


    /** @param bool $anonymous
     * @return array */
    private function flag_row_json(Pset $pset, ?Contact $s, FlagTableRow $row, $anonymous) {
        $j = $s ? StudentSet::json_basics($s, $anonymous) : [];
        if (($gcid = $row->jnote("gradercid") ?? null)) {
            $j["gradercid"] = $gcid;
        } else if ($row->rpi && $row->rpi->gradercid) {
            $j["gradercid"] = $row->rpi->gradercid;
        }
        $j["pset"] = $pset->id;
        $bhash = $row->bhash();
        $j["commit"] = bin2hex($bhash);
        $j["flagid"] = $row->flagid;
        if ($row->rpi && $row->rpi->gradehash && $row->rpi->placeholder <= 0) {
            $j["grade_commit"] = $row->rpi->gradehash;
        }
        if ($row->cpi->haslinenotes) {
            $j["has_notes"] = true;
        }
        if (isset($row->flag->conversation)
            && ($conv = $row->flag->conversation[0][2] ?? "") !== "") {
            if (strlen($conv) < 40) {
                $j["conversation"] = $conv;
            } else {
                $j["conversation_pfx"] = UnicodeHelper::utf8_word_prefix($conv, 40);
            }
        }
        if (($t = $row->cpi->grade_total($pset)) !== null) {
            $j["total"] = $t;
        }
        if ($row->flagid[0] === "t" && ctype_digit(substr($row->flagid, 1))) {
            $j["at"] = (int) substr($row->flagid, 1);
        } else if ($row->flag->at ?? 0) {
            $j["at"] = $row->flag->at;
        }
        return $j;
    }

    /** @param bool $all
     * @return bool */
    private function render_flags_table($all) {
        // 1. load commit notes
        $result = Dbl::qe("select * from CommitNotes where " . ($all ? "hasflags" : "hasactiveflags") . "=1");
        $flagrows = $uids = $psets = [];
        while (($cpi = CommitPsetInfo::fetch($result))) {
            foreach ((array) $cpi->jnote("flags") as $flagid => $v) {
                if ($all || !($v->resolved ?? false)) {
                    $uids[$v->uid ?? 0] = $psets[$cpi->pset] = true;
                    $flagrows[] = new FlagTableRow($cpi, $flagid, $v);
                }
            }
        }
        Dbl::free($result);
        if (empty($flagrows)) {
            return false;
        }

        // 2. load repouids and branches
        $repouids = $branches = $rgwanted = [];
        $result = $this->conf->qe("select cid, type, pset, link from ContactLink where (type=" . LINK_REPO . " or type=" . LINK_BRANCH . ") and pset?a", array_keys($psets));
        while (($row = $result->fetch_row())) {
            if ($row[1] == LINK_REPO) {
                $repouids["{$row[3]},{$row[2]}"][] = (int) $row[0];
                $rgwanted[] = "(repoid={$row[3]} and pset={$row[2]})";
            } else {
                $branches["{$row[0]},{$row[2]}"] = (int) $row[3];
            }
        }
        Dbl::free($result);

        // 3. load RepositoryGrades
        $rgs = [];
        $result = $this->conf->qe("select * from RepositoryGrade where " . join(" or ", $rgwanted));
        while (($row = RepositoryPsetInfo::fetch($result))) {
            $rgs["{$row->repoid},{$row->pset},{$row->branchid}"] = $row;
        }
        Dbl::free($result);

        // 4. resolve `repocids`, `main_gradercid`, `gradebhash`
        foreach ($flagrows as $row) {
            foreach ($repouids["{$row->repoid()},{$row->pset()}"] ?? [] as $uid) {
                $row->repouids[] = $uid;
                $uids[$uid] = true;
                if (!isset($row->rpi)) {
                    $branch = $branches["{$uid},{$row->pset()}"] ?? 0;
                    $bkey = "{$row->repoid()},{$row->pset()},{$branch}";
                    $row->rpi = $rgs["{$row->repoid()},{$row->pset()},{$branch}"] ?? null;
                }
            }
        }

        // 5. load users
        $contacts = [];
        $result = $this->conf->qe("select * from ContactInfo where contactId?a", array_keys($uids));
        while (($c = Contact::fetch($result, $this->conf))) {
            $contacts[$c->contactId] = $c;
        }
        Dbl::free($result);

        $nintotal = 0;
        $anonymous = null;
        if ($this->qreq->anonymous !== null && $this->viewer->privChair) {
            $anonymous = !!$this->qreq->anonymous;
        }
        $any_anonymous = $any_nonanonymous = false;
        $jx = [];
        foreach ($flagrows as $row) {
            if (!$row->repouids
                || !($pset = $this->conf->pset_by_id($row->pset()))) {
                continue;
            }
            $anon = $anonymous ?? $pset->anonymous;
            $any_anonymous = $any_anonymous || $anon;
            $any_nonanonymous = $any_nonanonymous || !$anon;

            $partners = [];
            foreach ($row->repouids as $uid) {
                if (($c = $contacts[(int) $uid] ?? null)) {
                    $c->set_anonymous($anon);
                    $partners[] = $c;
                }
            }
            usort($partners, $this->conf->user_comparator());

            $j = $this->flag_row_json($pset, $partners[0], $row, $anon);
            for ($i = 1; $i < count($partners); ++$i) {
                $j["partners"][] = $this->flag_row_json($pset, $partners[$i], $row, $anon);
            }
            $j["pos"] = count($jx);
            $jx[] = $j;
            if (isset($j["total"])) {
                ++$nintotal;
            }
        }

        echo '<div>',
            Ht::form("", ["id" => "pa-pset-flagged"]),
            '<h3 class="pset-title">flagged commits</h3>',
            '<div class="gtable-container-0">',
            '<div class="gtable-container-1">',
            '<div class="gtable-container-2">',
            '<table class="gtable want-gtable-fixed"></table></div></div></div>',
            Ht::button("Resolve flags", ["class" => "btn ui js-multiresolveflag"]),
            '</form></div>', "\n";
        $jd = [
            "id" => "flagged",
            "flagged_commits" => true,
            "anonymous" => $any_anonymous,
            "has_nonanonymous" => $any_nonanonymous,
            "checkbox" => true
        ];
        if ($this->viewer->privChair && $any_anonymous) {
            $jd["overridable_anonymous"] = true;
        }
        if ($nintotal) {
            $jd["need_total"] = 1;
        }
        echo Ht::unstash(), '<script>$pa.pset_table($("#pa-pset-flagged")[0],',
            json_encode_browser($jd), ',', json_encode_browser($jx), ')</script>';
        return true;
    }


    /** @param Pset $pset */
    function render_pset_actions($pset) {
        echo Ht::form($this->conf->hoturl("=index", ["pset" => $pset->urlkey, "reconfig" => 1]), ["divstyle" => "margin-bottom:1em", "class" => "need-pa-pset-actions"]);
        $options = array("disabled" => "Disabled",
                         "invisible" => "Hidden",
                         "visible" => "Visible without grades",
                         "scores_visible" => "Visible with grades");
        if ($pset->disabled) {
            $state = "disabled";
        } else if (!$pset->visible) {
            $state = "invisible";
        } else if (!$pset->scores_visible) {
            $state = "visible";
        } else {
            $state = "scores_visible";
        }
        echo Ht::select("state", $options, $state);

        echo '<span class="pa-if-visible"> &nbsp;<span class="barsep">·</span>&nbsp; ',
            Ht::select("frozen", array("no" => "Student updates allowed", "yes" => "Submissions frozen"), $pset->frozen ? "yes" : "no"),
            '</span>';

        echo '<span class="pa-if-enabled"> &nbsp;<span class="barsep">·</span>&nbsp; ',
            Ht::select("anonymous", array("no" => "Open grading", "yes" => "Anonymous grading"), $pset->anonymous ? "yes" : "no"),
            '</span>';

        echo ' &nbsp;', Ht::submit("reconfig", "Save");

        echo "</form>";
        echo Ht::unstash_script('$(".need-pa-pset-actions").each($pa.pset_actions)');
    }

    /** @return array */
    function pset_row_json(Pset $pset, StudentSet $sset, PsetView $info,
                           GradeExport $gexp) {
        $j = StudentSet::json_basics($info->user, $pset->anonymous);
        if (($gcid = $info->gradercid())) {
            $j["gradercid"] = $gcid;
        }
        if (($svh = $info->pinned_scores_visible()) !== null) {
            $j["scores_visible"] = $svh;
        }

        // are any commits committed?
        if (!$pset->gitless_grades && $info->repo) {
            if (!$info->user->dropped
                && !$this->profile
                && ($svh ?? $pset->scores_visible_student())) {
                $info->update_placeholder(function ($info, $rpi) {
                    $placeholder_at = $rpi ? $rpi->placeholder_at : 0;
                    if (($rpi && $rpi->placeholder <= 0)
                        || microtime(true) - $this->tinit >= 0.2) {
                        return false;
                    } else if ($placeholder_at && $placeholder_at < $this->tinit - 3600) {
                        return rand(0, 2) == 0;
                    } else {
                        return $placeholder_at < $this->tinit - 600 && rand(0, 10) == 0;
                    }
                });
            }
            if (($h = $info->grading_hash()) !== null) {
                $j["grade_commit"] = $h;
            } else if (($h = $info->hash()) !== null) {
                $j["commit"] = $h;
            }
            if ($info->is_do_not_grade()) {
                $j["do_not_grade"] = true;
            }
            if ($h && $info->empty_diff_likely()) {
                $j["emptydiff"] = true;
            }
        }
        if ($pset->has_timermark) {
            $j["student_timestamp"] = $info->student_timestamp(false);
        }

        if ($gexp->value_entries()) {
            if (!$pset->gitless_grades) {
                $gradercid = $info->gradercid();
                $gi = $info->grade_jnotes();
                if ($gi && ($gi->linenotes ?? null)) {
                    $j["has_notes"] = true;
                } else if ($info->viewer->contactId == $gradercid
                           && !$info->user->dropped
                           && !$info->empty_diff_likely()) {
                    $info->user->incomplete = "no line notes";
                }
                if ($gi
                    && $gradercid != ($gi->gradercid ?? null)
                    && $info->viewer->privChair) {
                    $j["has_nongrader_notes"] = true;
                }
            }
            if (($total = $info->visible_total()) !== null) {
                $j["total"] = $total;
            }
            $info->grade_export_grades($gexp);
            $info->grade_export_formulas($gexp);
            $j["grades"] = $gexp->grades;
            $want_incomplete = !$info->user->dropped && $info->viewer_is_grader();
            if ($want_incomplete || $gexp->autogrades !== null) {
                $gv = $gexp->grades;
                '@phan-var-force list $gv';
                $agv = $gexp->autogrades ?? [];
                $want_autogrades = false;
                foreach ($gexp->value_entries() as $i => $ge) {
                    if ($want_incomplete
                        && !isset($gv[$i])
                        && $ge->grader_entry_required()) {
                        $info->user->incomplete = "grade missing";
                    }
                    if (isset($agv[$i])
                        && $agv[$i] !== $gv[$i]) {
                        $want_autogrades = true;
                    }
                }
                if ($want_autogrades) {
                    $j["autogrades"] = $gexp->autogrades;
                }
            }
            if (($lh = $info->fast_late_hours())) {
                $j["late_hours"] = $lh;
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

    /** @return array */
    private function pconf(Pset $pset, GradeExport $gexp) {
        $jd = [
            "id" => $pset->id,
            "checkbox" => $this->viewer->isPC,
            "anonymous" => $pset->anonymous,
            "grades" => $gexp,
            "gitless" => $pset->gitless,
            "gitless_grades" => $pset->gitless_grades,
            "key" => $pset->urlkey,
            "title" => $pset->title,
            "disabled" => $pset->disabled,
            "visible" => $pset->visible,
            "scores_visible" => $pset->scores_visible_student(),
            "frozen" => $pset->frozen
        ];
        if (!$pset->gitless
            && PsetConfig_API::older_enabled_repo_same_handout($pset)) {
            $jd["has_older_repo"] = true;
        }
        if ($pset->anonymous) {
            $jd["overridable_anonymous"] = true;
        }
        $nintotal = $last_in_total = 0;
        foreach ($gexp->value_entries() as $ge) {
            if (!$ge->no_total) {
                ++$nintotal;
                $last_in_total = $ge->key;
            }
        }
        if ($nintotal > 1) {
            $jd["need_total"] = true;
        } else if ($nintotal == 1) {
            $jd["total_key"] = $last_in_total;
        }
        foreach ($pset->runners as $r) {
            if ($this->viewer->can_run($pset, $r)) {
                $jd["runners"][$r->name] = $r->title;
            }
        }
        if (!$pset->gitless_grades) {
            foreach ($pset->all_diffconfig() as $dc) {
                if (($dc->collate || $dc->gradable || ($dc->full && $dc->collate !== false))
                    && ($f = $dc->exact_filename())) {
                    $jd["diff_files"][] = $f;
                }
            }
            foreach ($pset->reports as $r) {
                $jd["reports"][] = ["key" => $r->key, "title" => $r->title];
            }
        }
        return $jd;
    }

    /** @param StudentSet $sset */
    private function render_pset_table($sset) {
        assert($this->viewer->isPC);
        $pset = $sset->pset;

        $gexp = new GradeExport($pset);
        $gexp->export_entries();
        $vf = [];
        foreach ($pset->grades as $ge) {
            $vf[] = $ge->type_tabular ? $ge->vf() : 0;
        }
        $gexp->set_fixed_values_vf($vf);

        $jd = $this->pconf($pset, $gexp);

        $psettitle = "pset-title"
            . ($pset->disabled || !$pset->visible ? " pa-p-hidden\">" : "\">")
            . ($pset->visible && !$pset->scores_visible ? '<span class="pa-scores-hidden-marker"></span>' : '')
            . htmlspecialchars($pset->title);

        echo '<form id="', $pset->urlkey, '">';
        echo '<h3 class="', $psettitle;
        if ($this->viewer->privChair) {
            echo '<button type="button" class="btn-t small ui js-pset-gconfig ml-2"><span class="filter-gray-if-disabled">⚙️</span></button>';
        }
        echo "</h3>";
        if ($pset->disabled) {
            echo Ht::unstash_script('$pa.pset_table($("#' . $pset->urlkey . '")[0],' . json_encode_browser($jd) . ',null)'),
                "</form>\n";
            return;
        }

        // load students
        $rows = [];
        $incomplete = $incompleteu = [];
        $jx = [];
        $gradercounts = [];
        foreach ($sset as $s) {
            if (!$s->user->visited) {
                $j = $this->pset_row_json($pset, $sset, $s, $gexp);
                if (!$s->user->dropped && isset($j["gradercid"])) {
                    $gradercounts[$j["gradercid"]] = ($gradercounts[$j["gradercid"]] ?? 0) + 1;
                }
                if (!$pset->partner_repo) {
                    foreach ($s->user->links(LINK_PARTNER, $pset->id) as $pcid) {
                        if (($ss = $sset->info($pcid)))
                            $j["partners"][] = $this->pset_row_json($pset, $sset, $ss, $gexp);
                    }
                }
                $jx[] = $j;
                if ($s->user->incomplete) {
                    $u = $this->viewer->user_linkpart($s->user);
                    $t = '<a href="' . $sset->conf->hoturl("pset", ["pset" => $pset->urlkey, "u" => $u]) . '">'
                        . htmlspecialchars($u);
                    if ($s->user->incomplete !== true) {
                        $t .= " (" . $s->user->incomplete . ")";
                    }
                    $incomplete[] = $t . '</a>';
                    $incompleteu[] = "~" . urlencode($u);
                }
            }
        }

        if (!empty($incomplete)) {
            echo '<div id="incomplete_pset', $pset->id, '" class="merror hidden has-hotlist" data-hotlist="',
                htmlspecialchars(json_encode_browser(["pset" => $pset->urlkey, "items" => $incompleteu])),
                '"><strong><a href="#', $pset->urlkey, '">', htmlspecialchars($pset->title), '</a></strong>: ',
                'Your grading is incomplete. Missing grades: ', join(", ", $incomplete), '</div>',
                '<script>$("#incomplete_pset', $pset->id, '").remove().removeClass("hidden").appendTo("#incomplete_notices")</script>';
        }

        if ($this->viewer->isPC) {
            echo Ht::form($sset->conf->hoturl("=index", ["pset" => $pset->urlkey, "save" => 1, "college" => $this->qreq->college, "extension" => $this->qreq->extension]));
            if ($pset->anonymous) {
                echo Ht::hidden("anonymous", 1);
            }
        }

        echo '<div class="gtable-container-0">',
            '<div class="gtable-container-1">',
            '<div class="gtable-container-gutter">',
            '<div class="gtable-gutter-content">',
            '<button type="button" class="ui js-gdialog mb-2 need-tooltip" aria-label="Set and configure grades" disabled><span class="filter-gray-if-disabled">🛎️</span></button>',
            '<button type="button" class="ui js-ptable-diff mb-2 need-tooltip" aria-label="Diffs, gradesheets, reports">±</button>';
        if (isset($jd["runners"])) {
            echo '<button type="button" class="ui js-ptable-run mb-2 need-tooltip" aria-label="Run commands"><span class="filter-gray-if-disabled">🏃🏽‍♀️</span></button>';
        }
        if (count($jx) > 20) {
            echo '<div class="gtable-gutter-pset ', $psettitle, '</div>';
        }
        echo '</div></div>',
            '<div class="gtable-container-2"><table class="gtable want-gtable-fixed"></table></div></div></div>';
        echo Ht::unstash(),
            '<script>$pa.pset_table($("#', $pset->urlkey, '")[0],',
            json_encode_browser($jd), ',',
            json_encode_browser($jx), ')</script></form>';
    }


    private function profile_step() {
        $t1 = microtime(true);
        echo sprintf("<div>Δt %.06fs, %.06fs total</div>", $t1 - $this->t0, $t1 - $this->tinit);
        $this->t0 = $t1;
    }

    function render_default() {
        echo '<div id="incomplete_notices"></div>', "\n";
        $any = false;
        $this->tinit = $this->t0 = microtime(true);

        // set `siteinfo.psets` in Javascript
        $psetj = [];
        foreach ($this->conf->psets() as $pset) {
            if ($this->viewer->can_view_pset($pset)) {
                $pj = [
                    "pset" => $pset->urlkey,
                    "psetid" => $pset->psetid,
                    "title" => $pset->title,
                    "pos" => count($psetj)
                ];
                if ($pset->gitless) {
                    $pj["gitless"] = true;
                }
                if ($pset->gitless || $pset->gitless_grades) {
                    $pj["gitless_grades"] = true;
                }
                $psetj[$pset->urlkey] = $pj;
            }
        }
        $this->conf->set_siteinfo("psets", $psetj);

        // set PC info in Javascript
        $pctable = [];
        foreach ($this->conf->pc_members_and_admins() as $pc) {
            if (($pc->nickname || $pc->firstName) && !$pc->nicknameAmbiguous) {
                $pctable[$pc->contactId] = $pc->nickname ? : $pc->firstName;
            } else {
                $pctable[$pc->contactId] = Text::name_text($pc);
            }
        }
        $this->conf->stash_hotcrp_pc($this->viewer);

        // show flags
        if ($this->render_flags_table(!!$this->qreq->allflags)) {
            $any = true;
            $this->profile && $this->profile_step();
        }

        // step through psets
        $sset = null;
        foreach ($this->conf->psets_newest_first() as $pset) {
            if ($this->viewer->can_view_pset($pset)) {
                if (!$sset) {
                    $ssflags = 0;
                    if ($this->qreq->extension) {
                        $ssflags |= StudentSet::DCE;
                    }
                    if ($this->qreq->college) {
                        $ssflags |= StudentSet::COLLEGE;
                    }
                    $sset = new StudentSet($this->viewer, $ssflags ? : StudentSet::ALL);
                }
                $sset->set_pset($pset);
                echo $any ? "<hr>" : "";
                $this->render_pset_table($sset);
                $any = true;
                $this->profile && $this->profile_step();
            }
        }
    }
}
