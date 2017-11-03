<?php
// psetview.php -- CS61-monster helper class for pset view
// Peteramati is Copyright (c) 2006-2016 Eddie Kohler
// See LICENSE for open-source distribution terms

class PsetView {
    public $conf;
    public $pset;
    public $user;
    public $viewer;
    public $pc_view;
    public $repo = null;
    public $partner;
    public $branch;
    private $partner_same = null;

    private $grade = false;         // either ContactGrade or RepositoryGrade+CommitNotes
    private $repo_grade = null;     // RepositoryGrade+CommitNotes
    private $grade_notes = null;
    private $can_view_grades = null;
    private $user_can_view_grades = null;

    private $hash = null;
    private $commit_record = false; // CommitNotes (maybe +RepositoryGrade)
    private $commit_notes = false;
    private $tabwidth = false;
    private $derived_handout_commit = null;
    private $n_visible_grades = null;
    private $n_visible_in_total;
    private $n_set_grades;

    const ERROR_NOTRUN = 1;
    const ERROR_LOGMISSING = 2;
    public $last_runner_error;
    private $transferred_warnings;

    function __construct(Pset $pset, Contact $user, Contact $viewer, $hash = null) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->user = $user;
        $this->viewer = $viewer;
        $this->pc_view = $viewer->isPC && $viewer !== $user;
        $this->partner = $user->partner($pset->id);
        if (!$pset->gitless) {
            $this->repo = $user->repo($pset->id);
            if ($pset->want_branch)
                $this->branch = $user->link(LINK_BRANCH, $pset->id) ? : null;
        }
        $this->hash = $hash;
    }

    function connected_hash($hash) {
        $c = $this->repo ? $this->repo->connected_commit($hash, $this->pset, $this->branch) : null;
        return $c ? $c->hash : false;
    }

    function set_hash($reqhash) {
        $this->hash = false;
        $this->commit_record = $this->commit_notes = $this->derived_handout_commit = $this->tabwidth = false;
        $this->n_visible_grades = null;
        if (!$this->repo)
            return false;
        if ($reqhash) {
            $c = $this->repo->connected_commit($reqhash, $this->pset, $this->branch);
        } else {
            $gh = $this->grading_hash();
            if ($gh) {
                $this->hash = $gh;
                return $this->hash;
            } else {
                $c = $this->latest_commit();
            }
        }
        if ($c) {
            $this->hash = $c->hash;
        }
        return $this->hash;
    }

    function force_set_hash($reqhash) {
        assert($reqhash === false || strlen($reqhash) === 40);
        if ($this->hash !== $reqhash) {
            $this->hash = $reqhash;
            $this->commit_notes = $this->derived_handout_commit = $this->tabwidth = false;
        }
    }

    function set_commit(RepositoryCommitInfo $commit) {
        $this->force_set_hash($commit->hash);
    }

    function has_commit_set() {
        return $this->hash !== null;
    }

    function commit_hash() {
        assert($this->hash !== null);
        return $this->hash;
    }

    function maybe_commit_hash() {
        return $this->hash;
    }

    function commit() {
        if ($this->hash === null)
            error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) . " " . $this->viewer->email);
        assert($this->hash !== null);
        if ($this->hash)
            return $this->recent_commits($this->hash);
        else
            return false;
    }

    function can_have_grades() {
        return $this->pset->gitless_grades || $this->commit();
    }

    function recent_commits($hash = null) {
        if (!$this->repo)
            return [];
        else if (!$hash)
            return $this->repo->commits($this->pset, $this->branch);
        else
            return $this->repo->connected_commit($hash, $this->pset, $this->branch);
    }

    function latest_commit() {
        $cs = $this->repo ? $this->repo->commits($this->pset, $this->branch) : [];
        reset($cs);
        return current($cs);
    }

    function latest_hash() {
        $lc = $this->latest_commit();
        return $lc ? $lc->hash : false;
    }

    function is_latest_commit() {
        return $this->hash && $this->hash === $this->latest_hash();
    }

    function derived_handout_hash() {
        if ($this->derived_handout_commit === false) {
            $this->derived_handout_commit = null;
            $hbases = $this->pset->handout_commits();
            foreach ($this->recent_commits() as $c)
                if (isset($hbases[$c->hash])) {
                    $this->derived_handout_commit = $c->hash;
                    break;
                }
        }
        return $this->derived_handout_commit ? : false;
    }

    function is_handout_commit() {
        return $this->hash && $this->hash === $this->derived_handout_hash();
    }

    function commit_record() {
        if ($this->commit_record === false) {
            if (!$this->hash
                || ($this->repo_grade && $this->repo_grade->gradehash == $this->hash)) {
                $this->commit_record = $this->repo_grade;
                $this->commit_notes = $this->grade_notes;
            } else {
                $this->commit_record = $this->pset->commit_notes($this->hash);
                $this->commit_notes = $this->commit_record ? $this->commit_record->notes : null;
            }
        }
        return $this->commit_record;
    }

    function commit_info($key = null) {
        if ($this->commit_record === false)
            $this->commit_record();
        if ($key && $this->commit_notes)
            return get($this->commit_notes, $key);
        else
            return $this->commit_notes;
    }

    function tabwidth() {
        if ($this->tabwidth === false)
            $this->tabwidth = $this->commit_info("tabwidth") ? : 4;
        return $this->tabwidth;
    }

    static private function clean_notes($j) {
        if (is_object($j)
            && isset($j->grades) && is_object($j->grades)
            && isset($j->autogrades) && is_object($j->autogrades)) {
            foreach ($j->autogrades as $k => $v) {
                if (get($j->grades, $k) === $v)
                    unset($j->grades->$k);
            }
            if (!count(get_object_vars($j->grades)))
                unset($j->grades);
        }
    }

    static function notes_haslinenotes($j) {
        $x = 0;
        if ($j && isset($j->linenotes))
            foreach ($j->linenotes as $fn => $fnn) {
                foreach ($fnn as $ln => $n)
                    $x |= (is_array($n) && $n[0] ? HASNOTES_COMMENT : HASNOTES_GRADE);
            }
        return $x;
    }

    static function notes_hasflags($j) {
        return $j && isset($j->flags) && count((array) $j->flags) ? 1 : 0;
    }

    static function notes_hasactiveflags($j) {
        if ($j && isset($j->flags))
            foreach ($j->flags as $f)
                if (!get($f, "resolved"))
                    return 1;
        return 0;
    }

    function update_commit_info_at($hash, $updates, $reset_keys = false) {
        // find original
        $this_commit_record = $this->hash === $hash
            || (!$this->hash && $this->repo_grade && $this->repo_grade->gradehash === $hash);
        if ($this_commit_record)
            $record = $this->commit_record();
        else
            $record = $this->pset->commit_notes($hash);

        // compare-and-swap loop
        while (1) {
            // change notes
            $new_notes = json_update($record ? $record->notes : null, $updates);
            self::clean_notes($new_notes);

            // update database
            $notes = json_encode($new_notes);
            $haslinenotes = self::notes_haslinenotes($new_notes);
            $hasflags = self::notes_hasflags($new_notes);
            $hasactiveflags = self::notes_hasactiveflags($new_notes);
            if (!$record)
                $result = $this->conf->qx("insert into CommitNotes set hash=?, pset=?, notes=?, haslinenotes=?, hasflags=?, hasactiveflags=?, repoid=?",
                                          $hash, $this->pset->psetid,
                                          $notes, $haslinenotes, $hasflags, $hasactiveflags, $this->repo->repoid);
            else
                $result = $this->conf->qe("update CommitNotes set notes=?, haslinenotes=?, hasflags=?, hasactiveflags=?, notesversion=? where hash=? and pset=? and notesversion=?",
                                          $notes, $haslinenotes, $hasflags, $hasactiveflags, $record->notesversion + 1,
                                          $hash, $this->pset->psetid, $record->notesversion);
            if ($result && $result->affected_rows)
                break;

            // reload record
            $record = $this->pset->commit_notes($hash);
        }

        if (!$record)
            $record = (object) ["hash" => $hash, "pset" => $this->pset->psetid, "repoid" => $this->repo->repoid, "notesversion" => 0];
        $record->notes = $new_notes;
        $record->haslinenotes = $haslinenotes;
        $record->hasflags = $hasflags;
        $record->hasactiveflags = $hasactiveflags;
        $record->notesversion = $record->notesversion + 1;
        if ($this_commit_record) {
            $this->commit_record = $record;
            $this->commit_notes = $new_notes;
        }
        if ($this->repo_grade && $this->repo_grade->gradehash === $hash) {
            $this->repo_grade->notes = $record->notes;
            $this->repo_grade->haslinenotes = $record->haslinenotes;
            $this->repo_grade->hasflags = $record->hasflags;
            $this->repo_grade->hasactiveflags = $record->hasactiveflags;
            $this->repo_grade->notesversion = $record->notesversion;
            $this->grade_notes = $record->notes;
        }
    }

    function update_commit_info($updates, $reset_keys = false) {
        assert(!!$this->hash);
        $this->update_commit_info_at($this->hash, $updates, $reset_keys);
    }

    function update_contact_grade_info($updates, $reset_keys = false) {
        assert(!!$this->pset->gitless_grades);
        // find original
        $record = $this->grade;

        // compare-and-swap loop
        while (1) {
            // change notes
            $new_notes = json_update($record ? $record->notes : null, $updates);
            self::clean_notes($new_notes);

            // update database
            $notes = json_encode($new_notes);
            $hasactiveflags = self::notes_hasactiveflags($new_notes);
            if (!$record)
                $result = $this->conf->qx("insert into ContactGrade set cid=?, pset=?, notes=?, hasactiveflags=?",
                                          $this->user->contactId, $this->pset->psetid,
                                          $notes, $hasactiveflags);
            else
                $result = $this->conf->qe("update ContactGrade set notes=?, hasactiveflags=?, notesversion=? where cid=? and pset=? and notesversion=?",
                                          $notes, $hasactiveflags, $record->notesversion + 1,
                                          $this->user->contactId, $this->pset->psetid, $record->notesversion);
            if ($result && $result->affected_rows)
                break;

            // reload record
            $record = $this->pset->contact_grade_for($this->user);
        }

        if (!$record)
            $record = (object) ["cid" => $this->user->contactId, "pset" => $this->pset->psetid, "gradercid" => null, "hidegrade" => 0, "notesversion" => 0];
        $record->notes = $new_notes;
        $record->hasactiveflags = $hasactiveflags;
        $record->notesversion = $record->notesversion + 1;
        $this->grade = $record;
        $this->grade_notes = $record->notes;
        $this->can_view_grades = $this->user_can_view_grades = null;
    }

    function update_current_info($updates, $reset_keys = false) {
        if ($this->pset->gitless)
            $this->update_contact_grade_info($updates, $reset_keys);
        else
            $this->update_commit_info($updates, $reset_keys);
    }

    function update_grade_info($updates, $reset_keys = false) {
        if ($this->pset->gitless || $this->pset->gitless_grades)
            $this->update_contact_grade_info($updates, $reset_keys);
        else
            $this->update_commit_info($updates, $reset_keys);
    }


    function tarball_url() {
        if ($this->repo && $this->hash !== null
            && $this->pset->repo_tarball_patterns) {
            for ($i = 0; $i + 1 < count($this->pset->repo_tarball_patterns); $i += 2) {
                $x = preg_replace('`' . str_replace("`", "\\`", $this->pset->repo_tarball_patterns[$i]) . '`s',
                                  $this->pset->repo_tarball_patterns[$i + 1],
                                  $this->repo->ssh_url(), -1, $nreplace);
                if ($x !== null && $nreplace)
                    return str_replace('${HASH}', $this->hash, $x);
            }
        }
        return null;
    }


    function backpartners() {
        return array_unique($this->user->links(LINK_BACKPARTNER, $this->pset->id));
    }

    function partner_same() {
        if ($this->partner_same === null && $this->partner) {
            $backpartners = $this->backpartners();
            $this->partner_same = count($backpartners) == 1
                && $this->partner->contactId == $backpartners[0];
        } else if ($this->partner_same === null)
            $this->partner_same = false;
        return $this->partner_same;
    }


    private function load_grade() {
        if ($this->pset->gitless_grades) {
            $this->grade = $this->pset->contact_grade_for($this->user);
            $this->grade_notes = get($this->grade, "notes");
        } else {
            $this->repo_grade = null;
            if ($this->repo) {
                $result = $this->conf->qe("select rg.*, cn.hash, cn.notes, cn.notesversion
                    from RepositoryGrade rg
                    left join CommitNotes cn on (cn.hash=rg.gradehash and cn.pset=rg.pset)
                    where rg.repoid=? and rg.pset=? and not rg.placeholder",
                    $this->repo->repoid, $this->pset->psetid);
                $this->repo_grade = $result ? $result->fetch_object() : null;
                Dbl::free($result);
                if ($this->repo_grade && $this->repo_grade->notes)
                    $this->repo_grade->notes = json_decode($this->repo_grade->notes);
            }
            $this->grade = $this->repo_grade;
            $this->grade_notes = get($this->grade, "notes");
            if ($this->grade_notes
                && get($this->grade, "gradercid")
                && !get($this->grade_notes, "gradercid"))
                $this->update_commit_info_at($this->grade->gradehash, ["gradercid" => $this->grade->gradercid]);
            if (get($this->grade, "gradehash") && $this->hash === null)
                // NB don't check recent_commits association here
                $this->hash = $this->grade->gradehash;
        }
        $this->n_visible_grades = null;
    }

    private function ensure_grade() {
        if ($this->grade === false)
            $this->load_grade();
        return $this->grade;
    }

    function grading_hash() {
        if ($this->pset->gitless_grades)
            return false;
        $this->ensure_grade();
        if ($this->repo_grade)
            return $this->repo_grade->gradehash;
        return false;
    }

    function grading_commit() {
        if ($this->pset->gitless_grades)
            return false;
        $this->ensure_grade();
        if ($this->repo_grade)
            return $this->recent_commits($this->repo_grade->gradehash);
        return false;
    }

    function is_grading_commit() {
        if ($this->pset->gitless_grades)
            return true;
        $this->ensure_grade();
        return $this->hash
            && $this->repo_grade
            && $this->hash == $this->repo_grade->gradehash;
    }

    private function contact_can_view_grades(Contact $user) {
        if ($user->isPC && $user !== $this->user)
            return $user->can_view_pset($this->pset);
        if (!$this->pset->student_can_view() || $user !== $this->user)
            return false;
        $this->ensure_grade();
        return $this->grade
            && $this->grade->hidegrade <= 0
            && ($this->grade->hidegrade < 0
                || $this->pset->student_can_view_grades($user->extension))
            && ($this->pset->gitless_grades
                || ($this->repo && $this->user_can_view_repo_contents()));
    }

    function can_view_grades() {
        if ($this->can_view_grades === null)
            $this->can_view_grades = $this->contact_can_view_grades($this->viewer);
        return $this->can_view_grades;
    }

    function user_can_view_grades() {
        if ($this->user_can_view_grades === null)
            $this->user_can_view_grades = $this->contact_can_view_grades($this->user);
        return $this->user_can_view_grades;
    }

    function user_can_view_grade_cdf() {
        global $Now;
        return $this->pset->grade_cdf_visible
            && $this->user_can_view_grades()
            && ($this->pset->grade_cdf_visible === "grades"
                || $this->pset->grade_cdf_visible === true
                || $this->pset->grade_cdf_visible <= $Now);
    }


    function can_view_repo_contents() {
        return $this->viewer->can_view_repo_contents($this->repo, $this->branch);
    }

    function user_can_view_repo_contents() {
        return $this->user->can_view_repo_contents($this->repo, $this->branch);
    }

    function can_view_note_authors() {
        return $this->pc_view;
    }

    private function ensure_n_visible_grades() {
        if ($this->n_visible_grades === null) {
            $this->n_visible_grades = $this->n_set_grades = $this->n_visible_in_total = 0;
            if ($this->can_view_grades() && $this->ensure_grade()) {
                $notes = $this->current_info();
                $ag = get($notes, "autogrades");
                $g = get($notes, "grades");
                foreach ($this->pset->grades as $ge)
                    if (!$ge->hide || $this->pc_view) {
                        ++$this->n_visible_grades;
                        if (($ag && get($ag, $ge->key) !== null)
                            || ($g && get($g, $ge->key) !== null))
                            ++$this->n_set_grades;
                        if (!$ge->no_total)
                            ++$this->n_visible_in_total;
                    }
            }
        }
    }

    function has_assigned_grades() {
        $this->ensure_n_visible_grades();
        return $this->n_set_grades > 0;
    }

    function needs_total() {
        $this->ensure_n_visible_grades();
        return $this->n_visible_in_total > 1;
    }

    function grade_total() {
        $total = $maxtotal = 0;
        $notes = $this->current_info();
        $ag = get($notes, "autogrades");
        $g = get($notes, "grades");
        foreach ($this->pset->grades as $ge)
            if ((!$ge->hide || $this->pc_view) && !$ge->no_total) {
                $gv = $g ? get($g, $ge->key) : null;
                if ($gv === null && $ag)
                    $gv = get($ag, $ge->key);
                if ($gv)
                    $total += $gv;
                if (!$ge->is_extra && !$ge->no_total && $ge->max && !$ge->hide_max)
                    $maxtotal += $ge->max;
            }
        return [$total, $maxtotal];
    }

    function gradercid() {
        $this->ensure_grade();
        if ($this->pset->gitless_grades)
            return $this->grade ? $this->grade->gradercid : 0;
        else if ($this->repo_grade
                 && $this->hash == $this->repo_grade->gradehash)
            return $this->repo_grade->gradercid;
        else
            return $this->commit_info("gradercid") ? : 0;
    }

    function can_edit_line_note($file, $lineid) {
        return $this->pc_view;
    }


    function grading_info($key = null) {
        $this->ensure_grade();
        if ($key && $this->grade_notes)
            return get($this->grade_notes, $key);
        else
            return $this->grade_notes;
    }

    function current_info($key = null) {
        if ($this->pset->gitless_grades || !$this->commit())
            return $this->grading_info($key);
        else
            return $this->commit_info($key);
    }

    function current_line_note($file, $lineid) {
        $ln = $this->current_info("linenotes");
        if ($ln)
            $ln = get($ln, $file);
        if ($ln)
            $ln = get($ln, $lineid);
        if ($ln)
            return LineNote::make_json($file, $lineid, $ln);
        else
            return new LineNote($file, $lineid);
    }

    function current_grade_entry($k, $type = null) {
        $gn = $this->current_info();
        $grade = null;
        if ((!$type || $type == "autograde") && isset($gn->autogrades)
            && property_exists($gn->autogrades, $k))
            $grade = $gn->autogrades->$k;
        if ((!$type || $type == "grade") && isset($gn->grades)
            && property_exists($gn->grades, $k))
            $grade = $gn->grades->$k;
        return $grade;
    }

    function late_hours_data() {
        $cinfo = $this->current_info();

        $deadline = $this->pset->deadline;
        if (!$this->user->extension && $this->pset->deadline_college) {
            $deadline = $this->pset->deadline_college;
        } else if ($this->user->extension && $this->pset->deadline_extension) {
            $deadline = $this->pset->deadline_extension;
        }

        $timestamp = get($cinfo, "timestamp");
        if (!$timestamp
            && !$this->pset->gitless
            && ($h = $this->hash ? : $this->grading_hash())
            && ($ls = $this->recent_commits($h))) {
            $timestamp = $ls->commitat;
        }

        if ($deadline && $timestamp) {
            if ($deadline < $timestamp) {
                $autohours = (int) ceil(($timestamp - $deadline) / 3600);
            } else {
                $autohours = 0;
            }
        } else {
            $autohours = null;
        }

        $ld = [];
        if (isset($cinfo->late_hours)) {
            $ld["hours"] = $cinfo->late_hours;
            if ($autohours !== null && $cinfo->late_hours !== $autohours) {
                $ld["autohours"] = $autohours;
            }
        } else if (isset($autohours)) {
            $ld["hours"] = $autohours;
        }
        if ($timestamp) {
            $ld["timestamp"] = $timestamp;
        }
        if ($deadline) {
            $ld["deadline"] = $deadline;
        }
        return empty($ld) ? null : (object) $ld;
    }

    function late_hours() {
        if (($lh = $this->current_info("late_hours")) !== null) {
            return $lh;
        } else if (($lhd = $this->late_hours_data()) && isset($lhd->hours)) {
            return $lhd->hours;
        } else {
            return null;
        }
    }


    function change_grader($grader) {
        if (is_object($grader))
            $grader = $grader->contactId;
        if ($this->pset->gitless_grades)
            $q = Dbl::format_query
                ("insert into ContactGrade (cid,pset,gradercid) values (?, ?, ?) on duplicate key update gradercid=values(gradercid)",
                 $this->user->contactId, $this->pset->psetid, $grader);
        else {
            assert(!!$this->hash);
            if (!$this->repo_grade || !$this->repo_grade->gradehash)
                $q = Dbl::format_query
                    ("insert into RepositoryGrade set repoid=?, pset=?, gradehash=?, gradercid=?, placeholder=0 on duplicate key update gradehash=values(gradehash), gradercid=values(gradercid), placeholder=0",
                     $this->repo->repoid, $this->pset->psetid,
                     $this->hash ? : null, $grader);
            else
                $q = Dbl::format_query
                    ("update RepositoryGrade set gradehash=?, gradercid=?, placeholder=0 where repoid=? and pset=? and gradehash=?",
                     $this->hash ? : $this->repo_grade->gradehash, $grader,
                     $this->repo->repoid, $this->pset->psetid, $this->repo_grade->gradehash);
            $this->update_commit_info(array("gradercid" => $grader));
        }
        if ($q)
            $this->conf->qe_raw($q);
        $this->grade = $this->repo_grade = false;
        $this->can_view_grades = $this->user_can_view_grades = null;
    }

    function mark_grading_commit() {
        if ($this->pset->gitless_grades)
            $this->conf->qe("insert into ContactGrade (cid,pset,gradercid) values (?, ?, ?) on duplicate key update gradercid=gradercid",
                    $this->user->contactId, $this->pset->psetid,
                    $this->viewer->contactId);
        else {
            assert(!!$this->hash);
            $grader = $this->commit_info("gradercid");
            if (!$grader)
                $grader = $this->grading_info("gradercid");
            $this->conf->qe("insert into RepositoryGrade (repoid,pset,gradehash,gradercid,placeholder) values (?, ?, ?, ?, 0) on duplicate key update gradehash=values(gradehash), gradercid=values(gradercid), placeholder=0",
                    $this->repo->repoid, $this->pset->psetid,
                    $this->hash ? : null, $grader ? : null);
        }
        $this->grade = $this->repo_grade = false;
        $this->can_view_grades = $this->user_can_view_grades = null;
    }

    function set_hidden_grades($hidegrade) {
        if ($this->pset->gitless_grades)
            $this->conf->qe("update ContactGrade set hidegrade=? where cid=? and pset=?", $hidegrade, $this->user->contactId, $this->pset->psetid);
        else
            $this->conf->qe("update RepositoryGrade set hidegrade=? where repoid=? and pset=?", $hidegrade, $this->repo->repoid, $this->pset->psetid);
        $this->grade = $this->repo_grade = false;
        $this->can_view_grades = $this->user_can_view_grades = null;
    }


    function runner_logfile($checkt) {
        global $ConfSitePATH;
        return $ConfSitePATH . "/log/run" . $this->repo->cacheid
            . ".pset" . $this->pset->id . "/repo" . $this->repo->repoid
            . ".pset" . $this->pset->id . "." . $checkt . ".log";
    }

    function runner_output($checkt) {
        return file_get_contents($this->runner_logfile($checkt));
    }

    function runner_output_for($runner) {
        if (is_string($runner))
            $runner = $this->pset->all_runners[$runner];
        $cnotes = $this->commit_info();
        if ($cnotes && isset($cnotes->run) && isset($cnotes->run->{$runner->name})) {
            $f = $this->runner_output($cnotes->run->{$runner->name});
            $this->last_runner_error = $f === false ? self::ERROR_LOGMISSING : 0;
            return $f;
        } else {
            $this->last_runner_error = self::ERROR_NOTRUN;
            return false;
        }
    }

    private function transfer_one_warning($file, $line, $text) {
        if ($file !== null && $text !== "") {
            if (!isset($this->transferred_warnings[$file]))
                $this->transferred_warnings[$file] = [];
            if (!isset($this->transferred_warnings[$file][$line]))
                $this->transferred_warnings[$file][$line] = "";
            $this->transferred_warnings[$file][$line] .= $text;
        }
    }

    private function transfer_warnings() {
        $this->transferred_warnings = [];
        foreach ($this->pset->runners as $runner) {
            if ($runner->transfer_warnings
                && $this->viewer->can_view_run($this->pset, $runner, $this->user)
                && ($output = $this->runner_output_for($runner))) {
                $file = $line = null;
                $text = "";
                foreach (explode("\n", $output) as $s) {
                    $sda = preg_replace('/\x1b\[[\d;]*m|\x1b\[\d*K/', '', $s);
                    if (preg_match('/\A(\S[^:]*):(\d+):/', $sda, $m)) {
                        $this->transfer_one_warning($file, $line, $text);
                        $file = $m[1];
                        $line = $m[2];
                        $text = $s . "\n";
                    } else if (preg_match('/\A(?:\S|\s+[A-Z]+\s)/', $sda)) {
                        $this->transfer_one_warning($file, $line, $text);
                        $file = $line = $text = "";
                    } else if ($file !== null) {
                        $text .= $s . "\n";
                    }
                }
                $this->transfer_one_warning($file, $line, $text);
            }
        }
    }

    function transferred_warnings_for($file) {
        if ($this->transferred_warnings === null)
            $this->transfer_warnings();
        if (isset($this->transferred_warnings[$file]))
            return $this->transferred_warnings[$file];
        $slash = strrpos($file, "/");
        if ($slash !== false
            && isset($this->transferred_warnings[substr($file, $slash + 1)]))
            return $this->transferred_warnings[substr($file, $slash + 1)];
        return [];
    }


    function hoturl_args($args = null) {
        $xargs = array("pset" => $this->pset->urlkey,
                       "u" => $this->viewer->user_linkpart($this->user));
        if ($this->hash)
            $xargs["commit"] = $this->commit_hash();
        if ($args)
            foreach ((array) $args as $k => $v)
                $xargs[$k] = $v;
        return $xargs;
    }

    function hoturl($base, $args = null) {
        return hoturl($base, $this->hoturl_args($args));
    }

    function hoturl_post($base, $args = null) {
        return hoturl_post($base, $this->hoturl_args($args));
    }


    function grade_json() {
        $this->ensure_grade();
        if (!$this->can_view_grades()) {
            return null;
        }
        $notes = $this->current_info();
        $result = $this->pset->gradeentry_json($this->pc_view);
        $agx = get($notes, "autogrades");
        $gx = get($notes, "grades");
        if ($agx || $gx || $this->is_grading_commit()) {
            $g = $ag = [];
            $total = $total_noextra = 0;
            foreach ($this->pset->grades as $ge) {
                if (!$ge->hide || $this->pc_view) {
                    $key = $ge->key;
                    $gv = null;
                    if ($agx) {
                        $gv = property_exists($agx, $key) ? $agx->$key : null;
                        $ag[] = $gv;
                    }
                    if ($gx) {
                        $gv = property_exists($gx, $key) ? $gx->$key : $gv;
                    }
                    $g[] = $gv;
                    if (!$ge->no_total && $gv) {
                        $total += $gv;
                        if (!$ge->is_extra) {
                            $total_noextra += $gv;
                        }
                    }
                }
            }
            $result["grades"] = $g;
            if ($this->pc_view && !empty($ag))
                $result["autogrades"] = $ag;
            $result["total"] = $total;
            if ($total != $total_noextra)
                $g["total_noextra"] = $total_noextra;
        }
        if (!$this->pset->gitless_grades && !$this->is_grading_commit()) {
            $result["grading_hash"] = $this->grading_hash();
        }
        if (($lhd = $this->late_hours_data())) {
            if (isset($lhd->hours)) {
                $result["late_hours"] = $lhd->hours;
            }
            if (isset($lhd->autohours) && $lhd->autohours !== $lhd->hours) {
                $result["auto_late_hours"] = $lhd->autohours;
            }
        }
        return $result;
    }


    function viewable_line_notes() {
        if ($this->viewer->can_view_comments($this->pset))
            return new LinenotesOrder($this->commit_info("linenotes"), $this->can_view_grades());
        else
            return $this->empty_line_notes();
    }

    function empty_line_notes() {
        return new LinenotesOrder(null, $this->can_view_grades());
    }

    function expand_diff_for_grades($diffs) {
        if ($this->pset->has_grade_landmark && $this->pc_view) {
            foreach ($this->pset->grades as $g) {
                if ($g->landmark_file
                    && ($di = get($diffs, $g->landmark_file))
                    && !$di->contains_linea($g->landmark_line))
                    $di->expand_linea($g->landmark_line - 2, $g->landmark_line + 3);
            }
        }
    }

    private function diff_line_code($t) {
        while (($p = strpos($t, "\t")) !== false)
            $t = substr($t, 0, $p) . str_repeat(" ", $this->tabwidth - ($p % $this->tabwidth)) . substr($t, $p + 1);
        return htmlspecialchars($t);
    }

    function echo_file_diff($file, DiffInfo $dinfo, LinenotesOrder $lnorder, $open, $nofold = false) {
        if (($dinfo->hide_if_anonymous && $this->user->is_anonymous)
            || $dinfo->is_empty())
            return;

        $fileid = html_id_encode($file);
        $tabid = "pa-file-" . $fileid;
        $linenotes = $lnorder->file($file);
        if ($this->can_view_note_authors())
            $this->conf->stash_hotcrp_pc($this->viewer);
        $gentries = null;
        if ($this->pset->has_grade_landmark
            && $this->pc_view
            && !$this->is_handout_commit()) {
            foreach ($this->pset->grades as $g)
                if ($g->landmark_file === $file)
                    $gentries["a" . $g->landmark_line][] = $g;
        }
        $wentries = null;
        if ($this->pset->has_transfer_warnings
            && !$this->is_handout_commit()) {
            foreach ($this->transferred_warnings_for($file) as $lineno => $w)
                $wentries["b" . $lineno] = $w;
        }
        $this->tabwidth();

        if (!$nofold) {
            echo '<h3><a class="fold61" href="#" onclick="return fold61(this.parentElement.nextSibling,this)"><span class="foldarrow">',
                ($open ? "&#x25BC;" : "&#x25B6;"),
                "</span>&nbsp;", htmlspecialchars($file), "</a>";
            if (!$dinfo->removed) {
                $rawfile = $file;
                if ($this->repo->truncated_psetdir($this->pset)
                    && str_starts_with($rawfile, $this->pset->directory_slash))
                    $rawfile = substr($rawfile, strlen($this->pset->directory_slash));
                echo '<a style="display:inline-block;margin-left:2em;font-weight:normal" href="', $this->hoturl("raw", ["file" => $rawfile]), '">[Raw]</a>';
            }
            echo '</h3>';
        }
        echo '<table id="', $tabid, '" class="pa-filediff';
        if ($this->pc_view) {
            echo " pa-editablenotes live";
            Ht::stash_script('pa_linenote.bind(document.body)', "pa_linenote");
        }
        if (!$this->user_can_view_grades())
            echo " hidegrades";
        if (!$open)
            echo '" style="display:none';
        echo '" data-pa-file="', htmlspecialchars($file), "\"><tbody>\n";
        Ht::stash_script('pa_expandcontext.bind(document.body)', "pa_expandcontext");
        foreach ($dinfo as $l) {
            if ($l[0] == "@")
                $x = array(" pa-gx", "pa-dcx", "", "", $l[3]);
            else if ($l[0] == " ")
                $x = array(" pa-gc", "pa-dd", $l[1], $l[2], $l[3]);
            else if ($l[0] == "-")
                $x = array(" pa-gd", "pa-dd", $l[1], "", $l[3]);
            else
                $x = array(" pa-gi", "pa-dd", "", $l[2], $l[3]);

            $aln = $x[2] ? "a" . $x[2] : "";
            $bln = $x[3] ? "b" . $x[3] : "";

            $ak = $bk = "";
            if ($linenotes && $aln && isset($linenotes->$aln))
                $ak = ' id="L' . $aln . '_' . $fileid . '"';
            if ($linenotes && $bln && isset($linenotes->$bln))
                $bk = ' id="L' . $bln . '_' . $fileid . '"';

            if (!$x[2] && !$x[3])
                $x[2] = $x[3] = "...";
            if ($x[2])
                $ak .= ' data-landmark="' . $x[2] . '"';
            if ($x[3])
                $bk .= ' data-landmark="' . $x[3] . '"';

            $nx = $nj = null;
            if ($linenotes) {
                if ($bln && isset($linenotes->$bln)) {
                    $n = LineNote::make_json($file, $bln, $linenotes->$bln);
                    if ($this->can_view_grades() || $n->iscomment)
                        $nx = $n;
                }
                if (!$nx && $aln && isset($linenotes->$aln)) {
                    $n = LineNote::make_json($file, $aln, $linenotes->$aln);
                    if ($this->can_view_grades() || $n->iscomment)
                        $nx = $n;
                }
            }

            echo '<tr class="pa-dl', $x[0], '">',
                '<td class="pa-da"', $ak, '></td>',
                '<td class="pa-db"', $bk, '></td>',
                '<td class="', $x[1], '">', $this->diff_line_code($x[4]), "</td></tr>\n";

            if ($wentries !== null && $bln && isset($wentries[$bln])) {
                echo '<tr class="pa-dl pa-gg"><td colspan="2" class="pa-warn-edge"></td><td class="pa-warnbox">', htmlspecialchars($wentries[$bln]), '</td></tr>';
            }

            if ($gentries !== null && $aln && isset($gentries[$aln])) {
                foreach ($gentries[$aln] as $g)
                    echo '<tr class="pa-dl pa-gg"><td colspan="3" class="pa-graderow"><div class="pa-gradebox pa-need-grade" data-pa-grade="', $g->key, '"></div></td></tr>';
            }

            if ($nx)
                $this->echo_linenote($nx, $lnorder);
        }
        echo "</tbody></table>\n";
    }

    private function echo_linenote(LineNote $note, LinenotesOrder $lnorder = null) {
        if ($this->can_view_grades() || $note->iscomment) {
            echo '<tr class="pa-dl pa-gw"', /* NB script depends on this class exactly */
                ' data-pa-note="', htmlspecialchars(json_encode($note->render_json($this->can_view_note_authors()))), '"';
            if ((string) $note->note === "")
                echo ' style="display:none"';
            echo '><td colspan="2" class="pa-note-edge"></td><td class="pa-notebox">';
            if ((string) $note->note === "") {
                echo '</td></tr>';
                return;
            }
            echo '<div class="pa-notediv">';
            if ($lnorder) {
                $links = array();
                //list($pfile, $plineid) = $lnorder->get_prev($note->file, $note->lineid);
                //if ($pfile)
                //    $links[] = '<a href="#L' . $plineid . '_'
                //        . html_id_encode($pfile) . '" onclick="return pa_gotoline(this)">&larr; Prev</a>';
                list($nfile, $nlineid) = $lnorder->get_next($note->file, $note->lineid);
                if ($nfile)
                    $links[] = '<a href="#L' . $nlineid . '_'
                        . html_id_encode($nfile) . '" onclick="return pa_gotoline(this)">Next &gt;</a>';
                else
                    $links[] = '<a href="#">Top</a>';
                if (!empty($links))
                    echo '<div class="pa-note-links">',
                        join("&nbsp;&nbsp;&nbsp;", $links) , '</div>';
            }
            if ($this->can_view_note_authors() && !empty($note->users)) {
                $pcmembers = $this->conf->pc_members_and_admins();
                $autext = [];
                foreach ($note->users as $au)
                    if (($p = get($pcmembers, $au))) {
                        if ($p->nicknameAmbiguous)
                            $autext[] = Text::name_html($p);
                        else
                            $autext[] = htmlspecialchars($p->nickname ? : $p->firstName);
                    }
                if (!empty($autext))
                    echo '<div class="pa-note-author">[', join(", ", $autext), ']</div>';
            }
            echo '<div class="pa-note', ($note->iscomment ? ' pa-commentnote' : ' pa-gradenote'),
                '">', htmlspecialchars($note->note), '</div>',
                '</div></td></tr>';
        }
    }
}
