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
    private function flag_row_json(Pset $pset, Contact $s = null, FlagTableRow $row, $anonymous) {
        $j = $s ? StudentSet::json_basics($s, $anonymous) : [];
        if (($gcid = $row->jnote("gradercid") ?? null)) {
            $j["gradercid"] = $gcid;
        } else if ($row->rpi && $row->rpi->gradercid) {
            $j["gradercid"] = $row->rpi->gradercid;
        }
        $j["psetid"] = $pset->id;
        $bhash = $row->bhash();
        $j["commit"] = bin2hex($bhash);
        $j["flagid"] = $row->flagid;
        if ($bhash !== null && $row->rpi && $row->rpi->gradebhash === $bhash) {
            $j["is_grade"] = true;
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
            $anon = $anonymous === null ? $pset->anonymous : $anonymous;
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
            "<h3>flagged commits</h3>",
            Ht::form(""),
            '<div class="gtable-container-0"><div class="gtable-container-1">',
            '<table class="gtable" id="pa-pset-flagged"></table></div></div>',
            Ht::button("Resolve flags", ["class" => "btn ui js-multiresolveflag"]),
            '</form></div>', "\n";
        $jd = [
            "id" => "flagged",
            "flagged_commits" => true,
            "anonymous" => true,
            "has_nonanonymous" => $any_nonanonymous,
            "checkbox" => true
        ];
        if ($this->viewer->privChair) {
            $jd["can_override_anonymous"] = true;
        }
        if ($nintotal) {
            $jd["need_total"] = 1;
        }
        echo Ht::unstash(), '<script>$("#pa-pset-flagged").each(function(){$pa.render_pset_table.call(this,', json_encode_browser($jd), ',', json_encode_browser($jx), ')})</script>';
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

    /** @param bool $anonymous
     * @return array */
    function pset_row_json(Pset $pset, StudentSet $sset, PsetView $info,
                           GradeExport $gex, $anonymous) {
        $j = StudentSet::json_basics($info->user, $anonymous);
        if (($gcid = $info->gradercid())) {
            $j["gradercid"] = $gcid;
        }
        if (($svh = $info->pinned_scores_visible()) !== null) {
            $j["scores_visible_student"] = $svh;
        }

        // are any commits committed?
        if (!$pset->gitless_grades && $info->repo) {
            if (!$info->user->dropped
                && !$this->profile
                && ($svh ?? $pset->scores_visible_student())) {
                $info->update_placeholder(function ($info, $rpi) {
                    $placeholder_at = $rpi ? $rpi->placeholder_at : 0;
                    if (($rpi && !$rpi->placeholder)
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
            if ($h && $info->empty_diff_likely()) {
                $j["emptydiff"] = true;
            }
        }

        if ($gex->value_entries()) {
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
            $info->grade_export_grades($gex);
            $info->grade_export_formulas($gex);
            $j["grades"] = $gex->grades;
            $want_incomplete = !$info->user->dropped && $info->viewer_is_grader();
            if ($want_incomplete || $gex->autogrades !== null) {
                $gv = $gex->grades;
                '@phan-var-force list $gv';
                $agv = $gex->autogrades ?? [];
                foreach ($gex->value_entries() as $i => $ge) {
                    if ($want_incomplete
                        && !isset($gv[$i])
                        && $ge->grader_entry_required()) {
                        $info->user->incomplete = "grade missing";
                    }
                    if (isset($agv[$i])
                        && $agv[$i] !== $gv[$i]) {
                        $j["highlight_grades"][$ge->key] = true;
                    }
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

    /** @param StudentSet $sset */
    private function render_pset_table($sset) {
        $pset = $sset->pset;
        echo '<div id="', $pset->urlkey, '">';
        echo "<h3>", htmlspecialchars($pset->title), "</h3>";
        if ($this->viewer->privChair) {
            $this->render_pset_actions($pset);
        }
        if ($pset->disabled) {
            echo "</div>\n";
            return;
        }

        // load students
        $anonymous = $pset->anonymous;
        if ($this->qreq->anonymous !== null && $this->viewer->privChair) {
            $anonymous = !!$this->qreq->anonymous;
        }

        $checkbox = $this->viewer->isPC
            || (!$pset->gitless && $pset->runners);

        $rows = array();
        $incomplete = $incompleteu = [];
        $jx = [];
        $gradercounts = [];
        $gex = new GradeExport($pset, VF_TF);
        $gex->set_exported_values($pset->tabular_grades());
        $gex->set_exported_entries(null);
        foreach ($sset as $s) {
            if (!$s->user->visited) {
                $j = $this->pset_row_json($pset, $sset, $s, $gex, $anonymous);
                if (!$s->user->dropped && isset($j["gradercid"])) {
                    $gradercounts[$j["gradercid"]] = ($gradercounts[$j["gradercid"]] ?? 0) + 1;
                }
                if (!$pset->partner_repo) {
                    foreach ($s->user->links(LINK_PARTNER, $pset->id) as $pcid) {
                        if (($ss = $sset->info($pcid)))
                            $j["partners"][] = $this->pset_row_json($pset, $sset, $ss, $gex, $anonymous);
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

        if ($checkbox) {
            echo Ht::form($sset->conf->hoturl("=index", ["pset" => $pset->urlkey, "save" => 1, "college" => $this->qreq->college, "extension" => $this->qreq->extension]));
            if ($pset->anonymous) {
                echo Ht::hidden("anonymous", $anonymous ? 1 : 0);
            }
        }

        echo '<div class="gtable-container-0"><div class="gtable-container-1"><table class="gtable want-gtable-fixed" id="pa-pset' . $pset->id . '"></table></div></div>';
        $jd = [
            "id" => $pset->id,
            "checkbox" => $checkbox,
            "anonymous" => $anonymous,
            "grades" => $gex,
            "gitless" => $pset->gitless,
            "gitless_grades" => $pset->gitless_grades,
            "key" => $pset->urlkey,
            "title" => $pset->title,
            "scores_visible_student" => $pset->scores_visible_student()
        ];
        if ($anonymous) {
            $jd["can_override_anonymous"] = true;
        }
        $i = $nintotal = $last_in_total = 0;
        foreach ($gex->value_entries() as $ge) {
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
        echo Ht::unstash(), '<script>$("#pa-pset', $pset->id, '").each(function(){$pa.render_pset_table.call(this,', json_encode_browser($jd), ',', json_encode_browser($jx), ')})</script>';

        $actions = [];
        if ($this->viewer->isPC) {
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
            }
            if ($pset->has_grade_collate) {
                foreach ($pset->grades() as $ge) {
                    if ($ge->collate) {
                        if ($stage !== -1 && $stage !== 1) {
                            $actions[] = null;
                        }
                        $stage = 1;
                        $actions["grademany_{$ge->key}"] = "Grade " . $ge->text_title();
                    }
                }
            }
            if ($stage !== -1 && $stage !== 2) {
                $actions[] = null;
            }
            $stage = 2;
            if (!$pset->gitless) {
                $actions["clearrepo"] = "Clear repo";
                if (older_enabled_repo_same_handout($pset)) {
                    $actions["copyrepo"] = "Adopt previous repo";
                }
            }
            if ($pset->reports) {
                $actions[] = null;
                foreach ($pset->reports as $r) {
                    $actions["report_{$r->key}"] = $r->title;
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
            if ($this->viewer->can_run($pset, $r)) {
                $sel[$r->name] = htmlspecialchars($r->title);
                $esel[$r->name . ".ensure"] = htmlspecialchars($r->title);
            }
        }
        if (count($sel) > 1) {
            echo '<span class="nb" style="padding-right:2em">',
                Ht::select("run", $sel + $esel),
                ' &nbsp;', Ht::submit("Run all", ["formaction" => $pset->conf->hoturl("=run", ["pset" => $pset->urlkey, "runmany" => 1])]),
                '</span>';
        }

        if ($checkbox) {
            echo "</form>\n";
        }

        echo "</div>\n";
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
