<?php
// pset.php -- Peteramati problem set page
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty()) {
    $Me->escape();
}

class PsetRequest {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Contact */
    public $user;
    /** @var Pset */
    public $pset;
    /** @var PsetView */
    public $info;
    /** @var Qrequest */
    public $qreq;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;

        // user
        if (isset($qreq->u)) {
            $this->user = ContactView::prepare_user($qreq, $viewer);
            if (!$this->user) {
                $this->conf->redirect_self($qreq, ["u" => null]);
            }
        } else {
            $this->user = $viewer;
        }
        $this->conf->set_siteinfo("uservalue", $viewer->user_linkpart($this->user));

        // pset
        $this->pset = ContactView::find_pset_redirect($qreq->pset, $viewer);

        // info, commit
        $this->info = PsetView::make($this->pset, $this->user, $viewer, $qreq->newcommit ?? $qreq->commit, true);
        if ($this->info->repo) {
            $this->info->repo->upgrade();
        }
        if (($qreq->newcommit ?? $qreq->commit) && !$this->info->hash()) {
            if ($this->info->repo) {
                $this->conf->errorMsg("Commit " . htmlspecialchars($qreq->newcommit ?? $qreq->commit) . " isn’t connected to this repository.");
                $this->info->set_grading_or_latest_nontrivial_commit(); // XXX
                unset($qreq->newcommit, $qreq->commit);
            } else {
                $this->conf->errorMsg("No repository has been configured for this pset.");
                ContactView::error_exit("404 Not Found", htmlspecialchars($this->pset->title));
            }
        }
        $this->conf->set_active_list(SessionList::find($viewer, $qreq));

        // notes version
        if ($this->pset->grades_history) {
            if (!isset($qreq->snv) && $this->info->has_pinned_answers()) {
                $this->conf->redirect_self($qreq, ["snv" => $this->info->pinsnv()]);
            }
            if (isset($qreq->snv) && ctype_digit($qreq->snv)) {
                $this->info->set_answer_version(intval($qreq->snv));
            } else if ($qreq->snv === "latest") {
                $this->info->set_answer_version(true);
            }
        }
    }

    static function go(Contact $viewer, Qrequest $qreq) {
        $psetreq = new PsetRequest($viewer, $qreq);
        $psetreq->handle_requests();
        $psetreq->render_page();
    }


    function handle_setgrader() {
        if (isset($this->qreq->grader)
            && $this->qreq->valid_post()
            && $this->info->can_have_grades()
            && $this->viewer->can_set_grader($this->pset, $this->user)) {
            $grader = 0;
            foreach ($this->conf->pc_members_and_admins() as $pcm) {
                if ($pcm->email === $this->qreq->grader)
                    $grader = $pcm->contactId;
            }
            if ($grader !== 0 || $this->qreq->grader === "none") {
                $this->info->change_grader($grader);
                json_exit(["ok" => true, "grader_email" => $this->qreq->grader]);
            } else {
                json_exit(["ok" => false, "error" => "Invalid grader."]);
            }
        }
    }

    function handle_setcommit() {
        $this->conf->redirect($this->info->hoturl("pset"));
    }

    function handle_download() {
        if (!$this->qreq->valid_post()) {
            return;
        }
        $dl = $this->pset->downloads[$this->qreq->download] ?? null;
        if (!$dl
            || ($this->viewer === $this->user
                && (!$dl->visible
                    || (is_int($dl->visible) && $dl->visible > Conf::$now)))) {
            header("HTTP/1.0 404 Not Found");
            exit;
        }
        $content = @file_get_contents($dl->file);
        if ($content === false) {
            if (!file_exists($dl->file)) {
                header("HTTP/1.0 404 Not Found");
            } else if (!is_readable($dl->file)) {
                header("HTTP/1.0 403 Forbidden");
            } else {
                header("HTTP/1.0 500 Internal Server Error");
            }
            exit;
        }
        if ($dl->timed) {
            $dls = $this->info->user_jnote("downloaded_at") ?? null;
            $old_dls = $dls ? $dls->{$dl->key} ?? [] : [];
            $old_dls[] = ($this->viewer === $this->user ? [Conf::$now] : [Conf::$now, $this->viewer->contactId]);
            $this->info->update_user_notes(["downloaded_at" => [$dl->key => $old_dls]]);
        }
        session_write_close();
        header("Content-Type: " . Mimetype::type_with_charset(Mimetype::content_type($content)));
        header("Content-Disposition: attachment; filename=" . mime_quote_string($dl->filename));
        header("X-Content-Type-Options: nosniff");
        // Etag
        header("Content-Length: " . strlen($content));
        echo $content;
        exit;
    }

    function handle_tabwidth() {
        if (ctype_digit($this->qreq->tab)) {
            $tab = intval($this->qreq->tab);
            if ($tab >= 1 && $tab <= 16) {
                $this->info->update_commit_notes(["tabwidth" => $tab === 4 ? null : $tab]);
            }
        } else if ($this->qreq->tab === "" || $this->qreq->tab === "none") {
            $this->info->update_commit_notes(["tabwidth" => null]);
        }
    }

    function handle_wdiff() {
        $wdiff = friendly_boolean($this->qreq->wdiff);
        $this->info->update_commit_notes(["wdiff" => $wdiff ? true : null]);
    }

    function handle_saverunsettings() {
        if ($this->qreq->valid_post()
            && $this->viewer->isPC
            && $this->viewer !== $this->user) {
            $rs = $this->qreq->get_a("runsettings");
            $rs = empty($rs) ? null : new JsonReplacement($rs);
            $this->info->update_commit_notes(["runsettings" => $rs]);
            if ($this->qreq->ajax) {
                json_exit(["ok" => true, "runsettings" => $rs]);
            }
        }
    }

    function handle_pinsnv() {
        if ($this->qreq->valid_post()
            && $this->viewer->isPC
            && $this->viewer !== $this->user
            && $this->pset->gitless) {
            $this->info->set_pinned_answer_version();
            $this->conf->redirect($this->info->hoturl("pset"));
        }
    }

    /** @param array|CsvRow $values
     * @param bool $isauto
     * @return ?string */
    function handle_upload_row(PsetView $info, $values, $isauto) {
        if ($info->is_handout_commit()
            && !$info->pset->gitless_grades) {
            return "this is a handout commit";
        }
        $grades = [];
        foreach ($this->pset->grades() as $ge) {
            if (isset($values[$ge->key])) {
                $v = $ge->parse_value($values[$ge->key], !$isauto);
                if (!($v instanceof GradeError)) {
                    if ($v === false && $isauto) {
                        $v = null;
                    }
                    $grades[$ge->key] = $v;
                }
            }
        }
        $updates = [($isauto ? "autogrades" : "grades") => $grades];
        if (isset($values["timestamp"]) && is_numeric($values["timestamp"])) {
            $timestamp = intval($values["timestamp"]);
            if ($timestamp >= 1400000000) {
                $updates["timestamp"] = $timestamp;
            } else if ($timestamp <= 0) {
                $updates["timestamp"] = null;
            }
        }
        if (!empty($updates)) {
            $info->update_grade_notes($updates);
        }
        return null;
    }

    /** @param string $text
     * @param string $fname
     * @return bool */
    function handle_upload_file($text, $fname) {
        assert($this->pset->gitless_grades);
        $csv = new CsvParser($text);
        $csv->set_header($csv->next_list());
        $errors = [];
        while (($line = $csv->next_row())) {
            if (($who = $line["email"]) && $who !== "-") {
                $user = $this->conf->user_by_email($who);
            } else if (($who = $line["github_username"]) && $who !== "-") {
                $user = $this->conf->user_by_whatever($who, Conf::USERNAME_GITHUB);
            } else if (($who = $line["huid"]) && $who !== "-") {
                $user = $this->conf->user_by_whatever($who, Conf::USERNAME_HUID);
            } else if (($who = $line["username"]) && $who !== "-") {
                $user = $this->conf->user_by_whatever($who, Conf::USERNAME_USERNAME);
            } else if (($who = $line["name"])) {
                list($first, $last) = Text::split_name($who);
                $user = $this->conf->user_by_query("firstName like '?s%' and lastName=?", [$first, $last]);
                if ($user
                    && $user->firstName != $first
                    && !str_starts_with($user->firstName, "$first ")) {
                    $user = null;
                }
            } else {
                continue;
            }
            if ($user) {
                $info = PsetView::make($this->pset, $user, $this->viewer);
                $error = $this->handle_upload_row($info, $line, true);
                if (is_string($error)) {
                    $errors[] = htmlspecialchars($fname) . ":" . $csv->lineno() . ": no grades set; $error";
                }
            } else {
                $errors[] = htmlspecialchars($fname) . ":" . $csv->lineno() . ": unknown user " . htmlspecialchars($who);
            }
        }
        if (empty($errors)) {
            return true;
        } else {
            $this->conf->errorMsg(join("<br />\n", $errors));
            return false;
        }
    }

    function handle_uploadgrades() {
        if ($this->qreq->valid_post()
            && $this->viewer->isPC
            && file_uploaded($_FILES["file"])) {
            $text = file_get_contents($_FILES["file"]["tmp_name"]);
            if ($text === false) {
                $this->conf->errorMsg("Internal error: cannot read uploaded file.");
            } else if ($this->handle_upload_file($text, $_FILES["file"]["name"])) {
                $this->conf->redirect_self($this->qreq);
            }
        }
    }

    function handle_requests() {
        if (isset($this->qreq->setgrader)) {
            $this->handle_setgrader();
        }
        if (isset($this->qreq->setcommit)) {
            $this->handle_setcommit();
        }
        if ($this->qreq->set_partner) {
            ContactView::set_partner_action($this->user, $this->viewer, $this->qreq);
        }
        if ($this->qreq->download) {
            $this->handle_download();
        }
        if (isset($this->qreq->uploadgrades)) {
            $this->handle_uploadgrades();
        }
        if (isset($this->qreq->tab)) {
            $this->handle_tabwidth();
        }
        if (isset($this->qreq->wdiff)) {
            $this->handle_wdiff();
        }
        if (isset($this->qreq->saverunsettings)) {
            $this->handle_saverunsettings();
        }
        if ($this->qreq->pinsnv) {
            $this->handle_pinsnv();
        }
    }


    /** @param string $where
     * @param bool $isprev
     * @return string */
    private function session_list_link($where, $isprev) {
        $x = [];
        if (preg_match('/\A~(.*?)\/pset\/(.*?)(\/[0-9a-f]+|)\z/', $where, $m)) {
            $p = $this->conf->pset_by_key($m[2]) ?? $this->pset;
            $t = htmlspecialchars(urldecode($m[1])) . " @" . htmlspecialchars($p->title);
            $x = ["pset" => $p->urlkey, "u" => urldecode($m[1])];
            if ($m[3] !== "") {
                $t .= substr($m[3], 0, 8);
                $x["commit"] = substr($m[3], 1);
            }
        } else if ($where[0] === "~" && strpos($where, "/") === false) {
            $where = urldecode(substr($where, 1));
            $t = htmlspecialchars($where);
            $x = ["pset" => $this->pset->urlkey, "u" => $where];
        } else {
            return "";
        }
        return '<a href="' . $this->conf->hoturl("pset", $x) . '" class="track">'
            . ($isprev ? "« " : "") . $t . ($isprev ? "" : " »") . '</a>';
    }

    private function echo_session_list_links() {
        if (($sl = $this->conf->active_list())) {
            echo '<div class="pa-list-links">';
            if ($sl->prev ?? null) {
                echo $this->session_list_link($sl->prev, true);
            }
            if (($sl->prev ?? null) && ($sl->next ?? null)) {
                echo ' · ';
            }
            if ($sl->next ?? null) {
                echo $this->session_list_link($sl->next, false);
            }
            echo '</div>';
        }
    }

    private function echo_pset_grades_history() {
        $snv = $this->info->answer_version();
        $newest = $newer = $older = $match = null;
        foreach ($this->info->answer_versions() as $nv) {
            $newest = $newest ?? $nv;
            if ($nv > $snv) {
                $newer = $nv;
            } else if ($match === null) {
                $match = $nv;
            } else {
                $older = $nv;
                break;
            }
        }

        $b = [];
        if ($newer || $older) {
            if ($older !== null) {
                $b[] = Ht::link("←", $this->info->hoturl("pset", ["snv" => $older]), ["class" => "btn need-tooltip", "aria-label" => "Older answers"]);
            } else {
                $b[] = Ht::button("←", ["type" => "button", "disabled" => true]);
            }
        }
        if ($this->info->pc_view) {
            $cl = $match === $this->info->pinsnv() ? " btn-primary" : "";
            $b[] = Ht::button("Ⓖ", ["type" => "submit", "formmethod" => "post", "formaction" => $this->info->hoturl("=pset", ["pinsnv" => 1]), "class" => "btn need-tooltip{$cl}", "aria-label" => "Mark these answers for grading"]);
        }
        if ($newer || $older) {
            if ($newer !== null) {
                $b[] = Ht::link("→", $this->info->hoturl("pset", ["snv" => $newer === $newest ? "latest" : $newer]), ["class" => "btn need-tooltip", "aria-label" => "Newer answers"]);
            } else {
                $b[] = Ht::button("→", ["type" => "button", "disabled" => true]);
            }
        }
        echo '<form class="float-right ml-4"><div class="btnbox">',
            join("", $b), '</div></form>';
    }

    private function echo_pset_info() {
        if ($this->pset->gitless_grades && $this->info->can_edit_scores()) {
            echo '<div class="float-right ml-4"><button type="button" class="ui js-pset-upload-grades">upload</button></div>';
        }
        if ($this->pset->grades_history
            && !$this->info->is_handout_commit()) {
            $this->echo_pset_grades_history();
        }
        echo '<h2 class="pset-title">', htmlspecialchars($this->pset->title), "</h2>";
        ContactView::echo_partner_group($this->info);
        ContactView::echo_repo_group($this->info, $this->info->can_edit_grade());
        ContactView::echo_downloads_group($this->info);
        if ($this->pset->gitless_grades && $this->info->can_edit_scores()) {
            echo '<div id="upload" class="hidden"><hr/>',
                Ht::form($this->info->hoturl("=pset", ["uploadgrades" => 1])),
                '<div class="f-contain">',
                '<input type="file" name="file">',
                Ht::submit("Upload"),
                '</div></form></div>';
        }
    }

    private function echo_grade_cdf() {
        if (!$this->info->can_view_grade_statistics()) {
            return;
        }
        echo '<div id="pa-grade-statistics" class="pa-grgraph pa-grade-statistics hidden';
        if (!$this->info->user_can_view_grade_statistics()) {
            echo ' pa-grp-hidden';
        }
        echo '" data-pa-pset="', $this->pset->urlkey;
        if (is_string($this->qreq->gg)) {
            echo '" data-pa-gg-type="', htmlspecialchars($this->qreq->gg);
        }
        echo '"><button type="button" class="qo ui js-grgraph-flip prev">←</button>';
        echo '<button type="button" class="qo ui js-grgraph-flip next">→</button>';
        echo '<h4 class="title pa-grgraph-type">grade statistics</h4>';
        if ($this->info->can_view_grade_statistics_graph()) {
            echo '<div class="pa-plot" style="width:350px;height:200px"></div>';
        }
        echo '<div class="statistics"></div></div>';
        Ht::stash_script("\$(\"#pa-grade-statistics\").each(\$pa.grgraph)");
    }

    private function echo_grader() {
        if ((!$this->pset->gitless && !$this->info->is_grading_commit())
            || !$this->viewer->can_view_grader($this->pset, $this->user)) {
            return;
        }
        $gradercid = $this->info->gradercid();
        $pcm = $this->conf->pc_members_and_admins();
        $gpc = $pcm[$gradercid] ?? null;
        $value_post = "";
        if ($this->viewer->can_set_grader($this->pset, $this->user)) {
            $sel = [];
            if (!$gpc) {
                $sel["none"] = "(None)";
                $sel[] = null;
            }
            foreach ($this->conf->pc_members_and_admins() as $pcm) {
                $sel[$pcm->email] = Text::name_html($pcm);
            }

            // if no current grader, highlight previous grader
            if (!$gpc && ($pred_pset = $this->pset->predecessor())) {
                $xinfo = PsetView::make($pred_pset, $this->user, $this->viewer);
                if (($xcid = $xinfo->gradercid())
                    && ($pcm = ($this->conf->pc_members_and_admins())[$xcid] ?? null)) {
                    $sel[$pcm->email] .= " [✱" . htmlspecialchars($pred_pset->title) . "]";
                }
            }

            $value = Ht::form($this->info->hoturl("=pset", ["setgrader" => 1]))
                . "<div>" . Ht::select("grader", $sel, $gpc ? $gpc->email : "none", ["class" => "uich js-pset-setgrader"]);
            $value_post = "<span class=\"ajaxsave61\"></span></div></form>";
        } else {
            if (isset($pcm[$gradercid])) {
                $value = Text::name_html($pcm[$gradercid]);
            } else {
                $value = "???";
            }
        }
        if ($this->viewer->privChair) {
            $value .= "&nbsp;" . become_user_link($gpc);
        }
        ContactView::echo_group("grader", $value . $value_post);
    }

    private function echo_all_grades() {
        $info = $this->info;
        if ($info->is_handout_commit()
            && !$this->pset->gitless_grades) {
            return;
        }

        $has_grades = $info->can_view_nonempty_grade();
        if ($has_grades || $info->can_edit_grade()) {
            if ($this->pset->grade_script && $info->can_edit_grade()) {
                foreach ($this->pset->grade_script as $gs) {
                    Ht::stash_html($this->conf->make_script_file($gs));
                }
            }
            echo '<div class="pa-gradelist is-main want-pa-landmark-links',
                $info->can_edit_scores() ? " has-editable-scores" : "",
                '"></div>';
            Ht::stash_script('$pa.gradesheet_store($(".pa-psetinfo")[0],' . json_encode_browser($info->grade_json()) . ');');
            if ($this->pset->has_grade_landmark) {
                Ht::stash_script('$(function(){$(".pa-psetinfo").each($pa.loadgrades)})');
            }
            echo Ht::unstash();
        }

        $lhd = $info->late_hours_data();
        if ($lhd && $info->can_view_some_grade() && !$info->can_edit_scores()) {
            if (($has_grades
                 && $info->can_view_nonempty_score())
                || (isset($lhd->hours)
                    && $lhd->hours > 0
                    && !$this->pset->obscure_late_hours)) {
                echo '<div class="pa-grade pa-p" data-pa-grade="late_hours">',
                    '<label class="pa-pt" for="late_hours">late hours</label>',
                    '<div class="pa-pv pa-gradevalue" id="late_hours">', $lhd->hours ?? 0, '</div>',
                    '</div>';
            }
        } else if ($info->can_edit_scores() && $this->pset->late_hours_entry()) {
            echo '<div class="pa-grade pa-p e" data-pa-grade="late_hours">',
                '<label class="pa-pt" for="late_hours">late hours</label>',
                '<form class="ui-submit pa-pv"><span class="pa-gradewidth">',
                Ht::entry("late_hours", $lhd && isset($lhd->hours) ? $lhd->hours : "",
                          ["class" => "uich pa-gradevalue pa-gradewidth", "id" => "late_hours"]),
                '</span> <span class="pa-gradedesc"></span>';
            if ($lhd && isset($lhd->autohours) && $lhd->hours !== $lhd->autohours) {
                echo '<span class="pa-gradediffers">auto-late hours is ', $lhd->autohours, '</span>';
            }
            echo '</form></div>';
        }
    }


    /** @param CommitRecord $k
     * @return string */
    private function format_commit($k) {
        $x = UnicodeHelper::utf8_prefix($k->subject, 72);
        if (strlen($x) !== strlen($k->subject)) {
            $x .= "...";
        }
        $t = date("Y-m-d H:i", $k->commitat);
        return substr($k->hash, 0, 7) . " [{$t}] " . htmlspecialchars($x);
    }

    private function echo_commit() {
        $conf = $this->conf;
        $pset = $this->pset;
        $Notes = $this->info->commit_jnotes();
        $TABWIDTH = $Notes->tabwidth ?? $pset->baseline_diffconfig(".*")->tabwidth ?? 4;
        $WDIFF = $Notes->wdiff ?? false;
        $this->info->update_placeholder(null);

        // current commit and commit selector
        $rc = $this->info->commit_list();
        $sel = $bhashes = [];
        $curhead = $grouphead = null;
        foreach ($rc as $k) {
            // visually separate older heads
            if ($curhead !== null
                && $curhead !== $k->fromhead
                && !$k->is_handout($pset)) {
                if (!$grouphead) {
                    $sel["from.{$k->fromhead}"] = (object) [
                        "type" => "optgroup", "label" => "Other snapshots"
                    ];
                } else {
                    $sel["from.{$k->fromhead}"] = null;
                }
                $curhead = $grouphead = $k->fromhead;
            }
            $curhead = $k->fromhead;
            $sel[$k->hash] = $this->format_commit($k);
            $bhashes[] = hex2bin($k->hash);
        }
        if (($k = $this->info->commit())
            && !isset($sel[$k->hash])) {
            $sel["from.{$k->hash}"] = null;
            $sel[$k->hash] = $this->format_commit($k);
            $bhashes[] = hex2bin($k->hash);
        }

        $notesflag = HASNOTES_ANY;
        if (!$this->info->pc_view && !$this->info->can_view_any_grade()) {
            $notesflag = HASNOTES_COMMENT;
        }
        $result = $this->conf->qe("select bhash, haslinenotes, hasflags, hasactiveflags
            from CommitNotes where pset=? and bhash?a and (haslinenotes or hasflags)",
            $pset->psetid, $bhashes);
        while (($row = $result->fetch_row())) {
            $f = "";
            if (((int) $row[1]) & $notesflag) {
                $f .= "♪";
            }
            if ($row[3]) {
                $f .= "⚑";
            } else if ($row[2]) {
                $f .= "⚐";
            }
            if ($f !== "") {
                $sel[bin2hex($row[0])] .= "  {$f}";
            }
        }
        Dbl::free($result);

        if (!empty($sel)
            && ($h = $this->info->grading_hash())
            && isset($sel[$h])) {
            $sel[$h] = preg_replace('/\A(.*?)(?:  |)((?:|♪)(?:|⚑|⚐))\z/', '$1  G⃝$2', $sel[$h]);
        }
        assert(isset($sel[$this->info->hash()]));

        if ($this->info->is_grading_commit()) {
            $key = "grading commit";
        } else {
            $key = "this commit";
        }
        $value = Ht::select("newcommit", $sel, $this->info->commit_hash(), ["class" => "uich js-pset-setcommit pa-commit-selector"]);

        // view options
        $fold_viewoptions = !isset($this->qreq->tab) && !isset($this->qreq->wdiff);
        $value .= '<div class="pa-viewoptions">'
            . '<button type="button" class="q ui js-pset-viewoptions">'
            . '<span class="foldarrow">'
            . ($fold_viewoptions ? '&#x25B6;' : '&#x25BC;')
            . '</span> options</button><span style="padding-left:1em"'
            . ($fold_viewoptions ? ' class="hidden"' : '') . '>tab width:';
        foreach ([2, 4, 8] as $i) {
            $value .= '&nbsp;<a href="' . $this->conf->selfurl($this->qreq, ["tab" => $i]) . '"'
                . ($TABWIDTH == $i ? " class=\"q\"><strong>$i</strong>" : '>' . $i)
                . '</a>';
        }
        $value .= '<span style="padding-left:1em">wdiff:';
        foreach (["no", "yes"] as $i => $t) {
            $value .= '&nbsp;<a href="' . $this->conf->selfurl($this->qreq, ["wdiff" => $i]) . '"'
                . (!$WDIFF == !$i ? " class=\"q\"><strong>$t</strong>" : '>' . $t)
                . '</a>';
        }
        $value .= '</span></span></div>';

        // warnings
        $remarks = [];
        if (!$pset->gitless_grades) {
            $gc = $this->info->grading_commit();
            if ($this->info->pc_view && !$gc) {
                $remarks[] = [true, "No commit has been marked for grading."];
            } else if ($gc && $gc->hash !== $this->info->commit_hash()) {
                $tc = $this->info->commit();
                $args = $tc->commitat > $gc->commitat
                    ? ["commit" => $gc->hash, "commit1" => $tc->hash]
                    : ["commit" => $tc->hash, "commit1" => $gc->hash];
                $remarks[] = [true, "This is not "
                    . "<a class=\"qh\" href=\"" . $this->info->hoturl("pset", ["commit" => $gc->hash])
                    . "\">the commit currently marked for grading</a>"
                    . " <span class=\"n\">(<a class=\"qh\" href=\"" . $this->info->hoturl("diff", $args)
                    . "\">see diff</a>)</span>."
                ];
            }
        }
        if (!$this->info->is_lateish_commit()) {
            $remarks[] = [true, "This is not "
                . Ht::link("the latest commit", $this->info->hoturl("pset", ["commit" => $this->info->latest_hash()]), ["class" => "qh"])
                . " <span class=\"n\">("
                . Ht::link("see diff", $this->info->hoturl("diff", ["commit1" => $this->info->latest_hash()]), ["class" => "qh"])
                . ")</span>."];
        }
        if ($rc->suspicious_directory) {
            $remarks[] = [true, "This list only shows commits that affect "
                . Ht::maybe_link("the " . htmlspecialchars($pset->directory_noslash) . " subdirectory", $this->info->repo->https_branch_tree_url($this->info->branch(), $pset->directory_noslash), ["class" => "qh"])
                . ", which is where your pset files belong. You may have checked in files to "
                . Ht::maybe_link("the parent directory", $this->info->repo->https_branch_tree_url($this->info->branch(), ""), ["class" => "qh"])
                . " by mistake."];
        }
        $lhd = $this->info->late_hours_data();
        if ($lhd
            && isset($lhd->hours)
            && $lhd->hours > 0
            && ($this->info->viewer->isPC || !$pset->obscure_late_hours)) {
            $extra = [];
            if (isset($lhd->timestamp)) {
                $extra[] = "commit at " . $this->conf->unparse_time($lhd->timestamp);
            }
            if (isset($lhd->deadline)) {
                $extra[] = "deadline " . $this->conf->unparse_time($lhd->deadline);
            }
            $extra = count($extra) ? ' <span style="font-weight:normal">(' . join(", ", $extra) . ')</span>' : "";
            $remarks[] = [!$pset->obscure_late_hours, "This commit uses " . plural($lhd->hours, "late hour") . $extra . "."];
        }
        if (($this->info->is_lateish_commit() || $this->viewer->isPC)
            && $pset->handout_repo_url) {
            $last_handout = $pset->latest_handout_commit();
            $last_myhandout = $last_handout ? $this->info->derived_handout_hash() : null;
            if ($last_handout
                && $last_myhandout
                && $last_handout->hash === $last_myhandout) {
                /* this is ideal: they have the latest handout commit */
            } else if ($last_handout && $last_myhandout) {
                $need_handout_hash = $pset->handout_warn_hash ? : $pset->handout_hash;
                if ($need_handout_hash
                    && ($hcf = $pset->handout_commits_from($last_myhandout))
                    && isset($hcf[$last_handout->hash])) {
                    // also fine
                } else {
                    // they don't have the latest updates
                    $cmd = "git pull handout";
                    if ($pset->handout_hash) {
                        $cmd .= " " . htmlspecialchars($pset->handout_hash);
                    } else {
                        $cmd .= " " . htmlspecialchars($pset->handout_branch);
                    }
                    $remarks[] = [true, "Updates are available for this problem set <span style=\"font-weight:normal\">(<a href=\"" . $this->info->hoturl("diff", array("commit" => $last_myhandout, "commit1" => $need_handout_hash ? : $last_handout->hash)) . "\">see diff</a>)</span>. Run <code>" . $cmd . "</code> to merge these updates."];
                }
            } else if ($last_handout && $pset->handout_warn_merge !== false) {
                $remarks[] = [true, "Please create your repository by cloning our repository. Creating your repository from scratch makes it harder for you to get pset updates.<br>This <em>somewhat dangerous</em> command will merge your repository with ours; back up your Git repository before trying it:<br><pre>git pull --allow-unrelated-histories --no-edit -s ours \"" . htmlspecialchars($pset->handout_repo_url) . "\" &amp;&amp; git push</pre>"];
            } else if (!$last_handout && $this->viewer->isPC) {
                $handout_files = $pset->handout_repo()->ls_files($pset->handout_branch);
                if (!count($handout_files)) {
                    $remarks[] = [true, "The handout repository, " . htmlspecialchars($pset->handout_repo_url) . ", contains no files; perhaps handout_repo_url is misconfigured."];
                } else {
                    $remarks[] = [true, "The handout repository, " . htmlspecialchars($pset->handout_repo_url) . ", does not contain problem set code yet."];
                }
            }
        }

        $xnotes = [];
        if (($c = $this->info->commit())) {
            $xnotes[] = "committed " . ago($c->commitat);
        }
        //$xnotes[] = "fetched " . ago($this->info->repo->snapat);
        $xnotes[] = "last checked " . ago($this->info->repo->snapcheckat);
        $remarks[] = join(", ", $xnotes);

        // actually print
        echo Ht::form($this->info->hoturl("=pset", ["commit" => null, "setcommit" => 1]),
                ["class" => "pa-commitcontainer", "data-pa-pset" => $this->pset->urlkey, "data-pa-checkhash" => $this->info->latest_hash()]);
        ContactView::echo_group($key, $value, $remarks);
        echo "</form>\n";
    }


    private function echo_flags() {
        assert(!$this->pset->gitless);
        $user = $this->user;
        $viewer = $this->viewer;
        $admin = $viewer->isPC && $viewer !== $user;
        if (($viewer !== $user && !$admin)
            || $this->info->is_handout_commit()) {
            return;
        }

        echo '<div class="pa-p"><div class="pa-pt"></div><div class="pa-pv"><form>';
        $any = false;
        $all_resolved = true;
        foreach ($this->info->commit_jnote("flags") ?? [] as $k => $v) {
            $resolved = $v->resolved ?? false;
            $all_resolved = $all_resolved && $resolved;
            $conversation = "";
            if ($v->conversation ?? false) {
                $conversation = htmlspecialchars((string) $v->conversation[0][2]);
            }
            if ($resolved && $conversation === "") {
                continue;
            }
            echo $any ? "" : '<ul class="x mb-0">',
                $resolved ? '<li class="pa-flag-resolved">🏳️' : '<li class="pa-flag-active">🏴';
            $any = true;
            if ($conversation !== "") {
                echo ' ', $conversation;
            }
            if (!$resolved) {
                echo '<span style="display:inline-block;margin-left:1em">',
                    Ht::button("✓", ["class" => "ui pa-flagger-resolve", "data-flagid" => $k, "title" => "Resolve flag"]),
                    '</span>';
            }
            echo '</li>';
        }
        if ($any) {
            echo '</li>';
        }

        $rpi = $this->info->rpi();
        echo '<div>';
        $gradelock = $rpi->placeholder === 0;
        if ($admin) {
            echo '<div class="btnbox mr-3">',
                Ht::button("Grade this commit", ["class" => "ui pa-flagger-grade" . ($this->info->is_grading_commit() ? " active" : "")]),
                Ht::button("🔒", ["class" => "ui pa-flagger-gradelock" . ($this->info->is_grading_commit() && $gradelock ? " active" : "")]),
                '</div>';
        } else if (!$gradelock && !$this->pset->frozen && !$this->pset->gitless_grades) {
            echo Ht::button("Grade this commit", ["class" => "ui pa-flagger-grade mr-3" . ($this->info->is_grading_commit() ? " active" : "")]);
        }
        if ($admin || (!$gradelock && !$this->pset->frozen)) {
            echo Ht::button("DO NOT GRADE", ["class" => "ui pa-flagger-nograde mr-4" . ($this->info->is_do_not_grade() ? " active" : "")]);
        }
        echo Ht::button("Flag this commit", ["class" => "ui pa-flagger" . ($all_resolved ? "" : " hidden"), "name" => "flag"]),
            '</div>',
            '</form></div></div>';
    }

    private function echo_runner_buttons() {
        $user = $this->user;
        $viewer = $this->viewer;

        $rbrunners = $hiddenrunners = [];
        foreach ($this->pset->runners as $r) {
            if ($viewer->can_view_run($this->pset, $r, $user)) {
                if ($viewer->can_run($this->pset, $r, $user)) {
                    $rbrunners[] = $r;
                } else {
                    $hiddenrunners[] = $r;
                }
            }
        }

        foreach ($hiddenrunners as $r) {
            echo '<input type="hidden" class="pa-runner" value="', htmlspecialchars($r->name), '">';
        }

        if (!empty($rbrunners)) {
            $want_plus = $viewer->isPC && $viewer !== $user;
            echo '<div class="pa-p"><div class="pa-pt"></div><div class="pa-pv">',
                Ht::form($this->info->hoturl("=run"));
            $nrunners = count($rbrunners);
            for ($i = 0; $i !== $nrunners; ++$i) {
                $r = $rbrunners[$i];
                echo Ht::button(htmlspecialchars($r->title), [
                    "value" => $r->name,
                    "class" => "btn ui pa-runner " . ($i === $nrunners - 1 && $want_plus ? "mr-3" : "mr-2"),
                    "data-pa-run-grade" => isset($r->evaluate_function) ? "true" : null
                ]);
            }
            if ($want_plus) {
                echo Ht::button("+", ["class" => "btn ui pa-runconfig ui font-weight-bold", "name" => "define"]);
            }
            echo "</form></div></div>\n";
        }

        if ($viewer->isPC && $viewer !== $user) {
            echo Ht::form($this->info->hoturl("=pset", ["saverunsettings" => 1, "ajax" => 1])),
                "<div id=\"pa-runsettings\"></div></form>\n";
            // XXX always using grading commit's settings?
            if (($runsettings = $this->info->commit_jnote("runsettings"))) {
                Ht::stash_script("\$pa.load_runsettings(" . json_encode_browser($runsettings) . ")");
            }
        }

        Ht::stash_script("\$('button.pa-runner').prop('disabled',false)");
    }

    /** @param PsetView $info
     * @param RunnerConfig $runner */
    static function default_runner_display_function($info, $runner) {
        $rr = null;
        if (!$info->is_handout_commit()
            && ($jobid = $info->latest_recorded_job($runner->name))) {
            $rr = $info->run_logger()->job_response($jobid);
        }
        if (!$rr
            && !$info->viewer->can_run($info->pset, $runner, $info->user)) {
            return;
        }
        echo '<div id="run-', $runner->name, '" class="pa-runout';
        if (!$rr || !isset($rr->timestamp)) {
            echo ' hidden';
        }
        echo '"><h3><button type="button" class="qo ui pa-run-show">',
            '<span class="foldarrow">&#x25B6;</span>',
            htmlspecialchars($runner->display_title), '</button></h3>',
            '<div class="pa-run pa-run-short need-run hidden"',
            $runner->div_attributes($info->pset);
        if ($rr && isset($rr->timestamp)) {
            echo ' data-pa-timestamp="', $rr->timestamp, '"';
        }
        // XXX following never runs currently (job_response returns null data)
        if ($rr && isset($rr->data) && ($pos = strpos($rr->data, "\n\n"))) {
            echo ' data-pa-content="', htmlspecialchars(substr($rr->data, $pos + 2)), '"';
        }
        echo '><pre class="pa-runpre"></pre></div></div>', "\n";
    }

    private function echo_runner_output() {
        $n = 0;
        foreach ($this->pset->runners as $runner) {
            if ($this->viewer->can_view_run($this->pset, $runner, $this->user)) {
                echo $n ? '' : '<div class="pa-runoutlist">';
                ++$n;
                if ($runner->display_function) {
                    SiteLoader::require_includes(null, $runner->require);
                    call_user_func($runner->display_function, $this->info, $runner);
                } else {
                    self::default_runner_display_function($this->info, $runner);
                }
            }
        }
        echo $n ? '</div>' : '';
    }


    function render_page() {
        // check for new commit
        if ($this->info->repo) {
            $this->info->repo->refresh(30);
        }

        // header
        if ($this->pset->has_xterm_js) {
            $this->conf->add_stylesheet("stylesheets/xterm.css");
            $this->conf->add_javascript("scripts/xterm.js");
        }
        $this->conf->header('<span class="pset-title">' . htmlspecialchars($this->pset->title) . '</span>', "body-pset");
        if ($this->viewer->isPC) {
            $this->echo_session_list_links();
        }
        ContactView::echo_heading($this->user, $this->viewer);

        // pset
        echo "<hr>\n";
        $this->echo_pset_info();

        // grades
        echo "<hr>\n";
        echo '<div class="pa-psetinfo" data-pa-pset="', htmlspecialchars($this->pset->urlkey);
        if (!$this->pset->gitless && $this->info->hash()) {
            echo '" data-pa-repourl="', htmlspecialchars($this->info->repo->url),
                '" data-pa-branch="', htmlspecialchars($this->info->branch()),
                '" data-pa-commit="', htmlspecialchars($this->info->commit_hash());
        }
        if (!$this->pset->gitless && $this->pset->directory) {
            echo '" data-pa-directory="', htmlspecialchars($this->pset->directory_slash);
        }
        if ($this->user->extension) {
            echo '" data-pa-user-extension="yes';
        }
        echo '">';

        if ($this->pset->gitless) {
            $this->echo_grade_cdf();
            $this->echo_grader();
            $this->echo_all_grades();

        } else if ($this->info->repo && !$this->info->can_view_repo_contents()) {
            $this->echo_grade_cdf();
            ContactView::echo_commit_groups($this->info);
            $this->echo_grader();
            $this->echo_all_grades();

        } else if ($this->info->repo && $this->info->commit_list()->nonempty()) {
            $this->echo_grade_cdf();
            $this->echo_commit();
            $this->echo_flags();
            $this->echo_grader();

            // print runner buttons
            echo '<hr>';
            $this->echo_runner_buttons();

            // print grade entries
            $this->echo_all_grades();

            // collect diff and sort line notes
            $lnorder = $this->info->visible_line_notes();
            if ($this->info->commit()) {
                $diff = $this->info->base_diff($this->info->commit(),
                    $lnorder, ["wdiff" => !!$this->info->commit_jnote("wdiff")]);
            } else {
                $diff = [];
            }

            // print line notes
            $notelinks = [];
            foreach ($lnorder->seq() as $note) {
                if (!$note->is_empty()) {
                    $notelinks[] = $note->render_line_link_html($this->pset);
                }
            }
            if (!empty($notelinks)) {
                ContactView::echo_group("notes", join(", ", $notelinks));
            }

            // print runners
            $this->echo_runner_output();

            // line notes
            if (!empty($diff)) {
                echo "<hr>\n";
                echo '<div class="pa-diffset">';
                $sbflags = 0;
                if ($this->info->can_edit_scores() && !$this->pset->has_grade_landmark_range) {
                    $sbflags |= PsetView::SIDEBAR_GRADELIST | PsetView::SIDEBAR_GRADELIST_LINKS;
                }
                if (count($diff) > 2) {
                    $sbflags |= PsetView::SIDEBAR_FILENAV;
                }
                PsetView::print_sidebar_open($sbflags, $diff);
                foreach ($diff as $file => $dinfo) {
                    $this->info->echo_file_diff($file, $dinfo, $lnorder, ["hide_left" => $this->info->can_edit_scores()]);
                }
                PsetView::print_sidebar_close($sbflags);
                echo '</div>';
            }

            Ht::stash_script('$(window).on("beforeunload",$pa.beforeunload)');
        } else {
            if ($this->pset->gitless_grades) {
                $this->echo_grade_cdf();
            }
            ContactView::echo_commit_groups($this->info);
            if ($this->pset->gitless_grades) {
                $this->echo_grader();
                $this->echo_all_grades();
            }
        }

        echo "</div>\n";


        if (!$this->pset->gitless) {
            Ht::stash_script("\$pa.checklatest(\"{$this->pset->urlkey}\")", "pa_checklatest");
        }

        echo "<hr class=\"c\">\n";
        $this->conf->footer();
    }
}


ContactView::set_path_request($Qreq, ["/@", "/@/p", "/@/p/h", "/p", "/p/h", "/p/u/h"], $Conf);
PsetRequest::go($Me, $Qreq);
