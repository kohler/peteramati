<?php
// psetview.php -- CS61-monster helper class for pset view
// Peteramati is Copyright (c) 2006-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class PsetView {
    public $conf;
    public $pset;
    public $user;
    public $viewer;
    public $pc_view;
    public $repo;
    public $partner;
    public $branch;
    public $branchid;
    private $partner_same;

    private $grade = false;         // either ContactGrade or RepositoryGrade+CommitNotes
    private $contact_grade = false; // always ContactGrade
    private $repo_grade;            // RepositoryGrade+CommitNotes
    private $_repo_grade_placeholder_bhash;
    private $_repo_grade_placeholder_at;
    private $grade_notes;
    private $can_view_grades;
    private $user_can_view_grades;

    private $hash;
    private $commit_record = false; // CommitNotes (maybe +RepositoryGrade)
    private $commit_notes = false;
    private $tabwidth = false;
    private $derived_handout_commit;
    private $n_visible_grades;
    private $n_visible_in_total;
    private $n_set_grades;
    private $need_format = false;
    private $added_diffinfo = false;

    const ERROR_NOTRUN = 1;
    const ERROR_LOGMISSING = 2;
    public $last_runner_error;
    private $transferred_warnings;
    private $transferred_warnings_priority;
    public $viewed_gradeentries = [];

    private $_diff_tabwidth;
    private $_diff_lnorder;

    function __construct(Pset $pset, Contact $user, Contact $viewer) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->user = $user;
        $this->viewer = $viewer;
        $this->pc_view = $viewer->isPC && $viewer !== $user;
    }

    static function make(Pset $pset, Contact $user, Contact $viewer) {
        $info = new PsetView($pset, $user, $viewer);
        $info->partner = $user->partner($pset->id);
        if (!$pset->gitless) {
            $info->repo = $user->repo($pset->id);
            $info->branchid = $user->branchid($pset);
            $info->branch = $info->branchid ? $info->conf->branch($info->branchid) : null;
        }
        $info->set_hash(null);
        return $info;
    }

    static function make_from_set(StudentSet $sset, Contact $user) {
        $info = new PsetView($sset->pset, $user, $sset->viewer);
        if (($pcid = $user->link(LINK_PARTNER, $info->pset->id)))
            $info->partner = $user->partner($info->pset->id, $sset->user($pcid));
        if (!$info->pset->gitless) {
            $info->repo = $user->repo($info->pset->id, $sset->repo($user));
            $info->branchid = $user->branchid($info->pset);
            $info->branch = $info->branchid ? $info->conf->branch($info->branchid) : null;
        }

        $info->contact_grade = $sset->contact_grade($user);
        if ($info->pset->gitless_grades) {
            $info->grade = $info->contact_grade;
        } else if ($info->repo) {
            $info->repo_grade = $sset->repo_grade_with_notes($user);
        }
        $info->analyze_grade();
        return $info;
    }

    function connected_hash($hash) {
        $c = $this->repo ? $this->repo->connected_commit($hash, $this->pset, $this->branch) : null;
        return $c ? $c->hash : false;
    }

    function find_commit($hash) {
        if ($hash === "handout") {
            return $this->base_handout_commit();
        }
        if ($hash && ($c = git_commit_in_list($this->pset->handout_commits(), $hash))) {
            return $c;
        }
        if ($this->repo) {
            if ($hash === "head" || $hash === "latest") {
                return $this->latest_commit();
            } else if ($hash === "grade" || $hash === "grading") {
                $hash = $this->grading_hash();
            }
            if ($hash) {
                return $this->repo->connected_commit($hash, $this->pset, $this->branch);
            }
        }
        return null;
    }

    function set_hash($reqhash) {
        $this->hash = false;
        $this->commit_record = $this->commit_notes = $this->derived_handout_commit = $this->tabwidth = false;
        $this->n_visible_grades = null;
        if (!$this->repo) {
            return false;
        }
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

    function set_commit(CommitRecord $commit) {
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
        if ($this->hash) {
            return $this->recent_commits($this->hash);
        } else {
            return false;
        }
    }

    function can_have_grades() {
        return $this->pset->gitless_grades || $this->commit();
    }

    function recent_commits($hash = null) {
        if (!$this->repo) {
            return $hash ? null : [];
        } else if (!$hash) {
            return $this->repo->commits($this->pset, $this->branch);
        } else {
            return $this->repo->connected_commit($hash, $this->pset, $this->branch);
        }
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

    function derived_handout_commit() {
        if ($this->derived_handout_commit === false) {
            $this->derived_handout_commit = null;
            $hbases = $this->pset->handout_commits();
            $seen_hash = !$this->hash;
            foreach ($this->recent_commits() as $c) {
                if ($c->hash === $this->hash)
                    $seen_hash = true;
                if (isset($hbases[$c->hash])) {
                    $this->derived_handout_commit = $c;
                    if ($seen_hash)
                        break;
                }
            }
        }
        return $this->derived_handout_commit;
    }

    function derived_handout_hash() {
        $c = $this->derived_handout_commit();
        return $c ? $c->hash : false;
    }

    function base_handout_commit() {
        if ($this->pset->handout_hash
            && ($c = $this->pset->handout_commits($this->pset->handout_hash))) {
            return $c;
        } else if (($c = $this->derived_handout_commit())) {
            return $c;
        } else if (($c = $this->pset->latest_handout_commit())) {
            return $c;
        } else {
            return new CommitRecord(0, "4b825dc642cb6eb9a060e54bf8d69288fbee4904", "", CommitRecord::HANDOUTHEAD);
        }
    }

    function is_handout_commit() {
        return $this->hash && $this->hash === $this->derived_handout_hash();
    }

    function commit_record() {
        if ($this->commit_record === false) {
            if (!$this->hash
                || ($this->repo_grade && $this->repo_grade->gradehash === $this->hash)) {
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
        if ($this_commit_record) {
            $record = $this->commit_record();
        } else {
            $record = $this->pset->commit_notes($hash);
        }

        // compare-and-swap loop
        while (true) {
            // change notes
            $new_notes = json_update($record ? $record->notes : null, $updates);
            self::clean_notes($new_notes);

            // update database
            $notes = json_encode_db($new_notes);
            $haslinenotes = self::notes_haslinenotes($new_notes);
            $hasflags = self::notes_hasflags($new_notes);
            $hasactiveflags = self::notes_hasactiveflags($new_notes);
            if (!$record) {
                $result = $this->conf->qe("insert into CommitNotes set pset=?, bhash=?, notes=?, haslinenotes=?, hasflags=?, hasactiveflags=?, repoid=?",
                                          $this->pset->id, hex2bin($hash),
                                          $notes, $haslinenotes, $hasflags, $hasactiveflags, $this->repo->repoid);
            } else {
                $result = $this->conf->qe("update CommitNotes set notes=?, haslinenotes=?, hasflags=?, hasactiveflags=?, notesversion=? where pset=? and bhash=? and notesversion=?",
                                          $notes, $haslinenotes, $hasflags, $hasactiveflags, $record->notesversion + 1,
                                          $this->pset->id, hex2bin($hash), $record->notesversion);
            }
            if ($result && $result->affected_rows) {
                break;
            }

            // reload record
            $record = $this->pset->commit_notes($hash);
        }

        if (!$record) {
            $record = (object) ["hash" => $hash, "pset" => $this->pset->id, "repoid" => $this->repo->repoid, "notesversion" => 0];
        }
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
            if (isset($updates["grades"]) || isset($updates["autogrades"]))
                $this->conf->invalidate_grades($this->pset);
        }
    }

    function update_commit_info($updates, $reset_keys = false) {
        assert(!!$this->hash);
        $this->update_commit_info_at($this->hash, $updates, $reset_keys);
    }

    function update_user_info($updates, $reset_keys = false) {
        // find original
        $this->ensure_contact_grade();
        $record = $this->contact_grade;

        // compare-and-swap loop
        while (true) {
            // change notes
            $new_notes = json_update($record ? $record->notes : null, $updates);
            self::clean_notes($new_notes);

            // update database
            $notes = json_encode_db($new_notes);
            $hasactiveflags = self::notes_hasactiveflags($new_notes);
            if (!$record) {
                $result = $this->conf->qx("insert into ContactGrade set cid=?, pset=?, notes=?, hasactiveflags=?",
                                          $this->user->contactId, $this->pset->id,
                                          $notes, $hasactiveflags);
            } else {
                $result = $this->conf->qe("update ContactGrade set notes=?, hasactiveflags=?, notesversion=? where cid=? and pset=? and notesversion=?",
                                          $notes, $hasactiveflags, $record->notesversion + 1,
                                          $this->user->contactId, $this->pset->id, $record->notesversion);
            }
            if ($result && $result->affected_rows)
                break;

            // reload record
            $record = $this->pset->contact_grade_for($this->user);
        }

        if (!$record) {
            $record = (object) ["cid" => $this->user->contactId, "pset" => $this->pset->id, "gradercid" => null, "hidegrade" => 0, "notesversion" => 0];
        }
        $record->notes = $new_notes;
        $record->hasactiveflags = $hasactiveflags;
        $record->notesversion = $record->notesversion + 1;
        $this->contact_grade = $record;
        if ($this->pset->gitless || $this->pset->gitless_grades) {
            $this->grade = $record;
            $this->grade_notes = $record->notes;
        }
        $this->can_view_grades = $this->user_can_view_grades = null;
        if (isset($updates["grades"]) || isset($updates["autogrades"]))
            $this->conf->invalidate_grades($this->pset);
    }

    function update_current_info($updates, $reset_keys = false) {
        if ($this->pset->gitless)
            $this->update_user_info($updates, $reset_keys);
        else
            $this->update_commit_info($updates, $reset_keys);
    }

    function update_grade_info($updates, $reset_keys = false) {
        if ($this->pset->gitless || $this->pset->gitless_grades)
            $this->update_user_info($updates, $reset_keys);
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
        if ($this->partner_same === null) {
            $bp = $this->backpartners();
            if ($this->partner)
                $this->partner_same = count($bp) == 1 && $this->partner->contactId == $bp[0];
            else
                $this->partner_same = empty($bp);
        }
        return $this->partner_same;
    }


    private function analyze_grade() {
        $this->_repo_grade_placeholder_bhash = null;
        $this->_repo_grade_placeholder_at = 0;
        if ($this->repo_grade && $this->repo_grade->placeholder) {
            $this->_repo_grade_placeholder_at = +$this->repo_grade->placeholder_at;
            $this->_repo_grade_placeholder_bhash = $this->repo_grade->gradebhash;
            $this->repo_grade = null;
        }
        if ($this->repo_grade) {
            if ($this->repo_grade->gradebhash !== null)
                $this->repo_grade->gradehash = bin2hex($this->repo_grade->gradebhash);
            if ($this->repo_grade->bhash !== null)
                $this->repo_grade->hash = bin2hex($this->repo_grade->bhash);
        }
        if (!$this->pset->gitless_grades) {
            $this->grade = $this->repo_grade;
        }
        if ($this->grade && $this->grade->notes && is_string($this->grade->notes)) {
            $this->grade->notes = json_decode($this->grade->notes);
        }
        $this->grade_notes = $this->grade ? $this->grade->notes : null;
        if ($this->grade_notes
            && $this->repo_grade
            && $this->grade->gradercid
            && !get($this->grade_notes, "gradercid")) {
            $this->update_commit_info_at($this->grade->gradehash, ["gradercid" => $this->grade->gradercid]);
        }
        $this->n_visible_grades = null;
    }

    private function load_contact_grade() {
        $this->contact_grade = $this->pset->contact_grade_for($this->user);
    }

    private function ensure_contact_grade() {
        if ($this->contact_grade === false) {
            $this->load_contact_grade();
        }
    }

    private function load_grade() {
        if ($this->pset->gitless_grades) {
            $this->load_contact_grade();
            $this->grade = $this->contact_grade;
        } else {
            $this->repo_grade = null;
            if ($this->repo) {
                $result = $this->conf->qe("select rg.*, cn.bhash, cn.notes, cn.notesversion
                    from RepositoryGrade rg
                    left join CommitNotes cn on (cn.pset=rg.pset and cn.bhash=rg.gradebhash)
                    where rg.repoid=? and rg.branchid=? and rg.pset=?",
                    $this->repo->repoid, $this->branchid, $this->pset->id);
                $this->repo_grade = $result ? $result->fetch_object() : null;
                Dbl::free($result);
            }
        }
        $this->analyze_grade();
    }

    private function ensure_grade() {
        if ($this->grade === false) {
            $this->load_grade();
            if ($this->repo_grade
                && $this->repo_grade->gradebhash !== null
                && $this->hash === null)
                $this->hash = bin2hex($this->repo_grade->gradebhash);
        }
        return $this->grade;
    }

    function is_current_grades() {
        return $this->pset->gitless_grades || $this->grading_commit();
    }

    function grading_hash() {
        if ($this->pset->gitless_grades)
            return false;
        $this->ensure_grade();
        if ($this->repo_grade)
            return $this->repo_grade->gradehash;
        return false;
    }

    function update_grading_hash($update_chance = false) {
        if ($this->pset->gitless_grades)
            return false;
        $this->ensure_grade();
        if ((!$this->repo_grade || $this->repo_grade->gradebhash === null)
            && $update_chance
            && ($update_chance === true
                || (is_callable($update_chance) && call_user_func($update_chance, $this, $this->_repo_grade_placeholder_at))
                || (is_float($update_chance) && rand(0, 999999999) < 1000000000 * $update_chance)))
            $this->update_placeholder_repo_grade();
        if ($this->repo_grade)
            return $this->repo_grade->gradehash;
        return false;
    }

    function update_placeholder_repo_grade() {
        global $Now;
        assert(!$this->pset->gitless_grades
               && (!$this->repo_grade || $this->repo_grade->gradebhash === null));
        $c = $this->latest_commit();
        $h = $c ? hex2bin($c->hash) : null;
        if ($this->_repo_grade_placeholder_at === 0
            || $this->_repo_grade_placeholder_bhash !== $h) {
            $this->conf->qe("insert into RepositoryGrade set repoid=?, branchid=?, pset=?, gradebhash=?, placeholder=1, placeholder_at=? on duplicate key update gradebhash=(if(placeholder=1,values(gradebhash),gradebhash)), placeholder_at=values(placeholder_at)",
                    $this->repo->repoid, $this->branchid, $this->pset->id,
                    $c ? hex2bin($c->hash) : null, $Now);
            $this->clear_grade();
            $this->ensure_grade();
        }
    }

    function grading_commit() {
        if ($this->pset->gitless_grades)
            return false;
        $this->ensure_grade();
        if ($this->repo_grade)
            return $this->recent_commits($this->repo_grade->gradehash);
        return false;
    }

    function repo_grade() {
        if ($this->pset->gitless_grades)
            return false;
        else
            return $this->repo_grade;
    }

    function is_grading_commit() {
        if ($this->pset->gitless_grades)
            return true;
        $this->ensure_grade();
        return $this->hash
            && $this->repo_grade
            && $this->hash === $this->repo_grade->gradehash;
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

    function can_view_grade_statistics() {
        return ($this->viewer->isPC && $this->viewer !== $this->user)
            || $this->user_can_view_grade_statistics();
    }

    function user_can_view_grade_statistics() {
        global $Now;
        // also see API_GradeStatistics
        $gsv = $this->pset->grade_statistics_visible;
        return $gsv === true
            || (is_int($gsv) && $gsv <= $Now)
            || ($gsv !== false && $this->user_can_view_grades());
    }

    function can_view_grade_statistics_graph() {
        return ($this->viewer->isPC && $this->viewer !== $this->user)
            || ($this->pset->grade_cdf_cutoff < 1
                && $this->user_can_view_grade_statistics());
    }

    function can_edit_grades() {
        return $this->viewer !== $this->user;
    }


    function can_view_repo_contents($cached = false) {
        return $this->viewer->can_view_repo_contents($this->repo, $this->branch, $cached);
    }

    function user_can_view_repo_contents($cached = false) {
        return $this->user->can_view_repo_contents($this->repo, $this->branch, $cached);
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
                foreach ($this->pset->visible_grades($this->pc_view) as $ge) {
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
        $total = $total_noextra = $maxtotal = 0;
        $notes = $this->current_info();
        $ag = get($notes, "autogrades");
        $g = get($notes, "grades");
        foreach ($this->pset->visible_grades_in_total($this->pc_view) as $ge) {
            $gv = $g ? get($g, $ge->key) : null;
            if ($gv === null && $ag)
                $gv = get($ag, $ge->key);
            if ($gv)
                $total += $gv;
            if ($gv && !$ge->is_extra)
                $total_noextra += $gv;
            if (!$ge->is_extra && $ge->max && $ge->max_visible)
                $maxtotal += $ge->max;
        }
        return [$total, $maxtotal, $total_noextra];
    }

    function gradercid() {
        $this->ensure_grade();
        if ($this->pset->gitless_grades) {
            return $this->grade ? $this->grade->gradercid : 0;
        } else if ($this->repo_grade
                   && $this->hash === $this->repo_grade->gradehash) {
            return $this->repo_grade->gradercid;
        } else {
            return $this->commit_info("gradercid") ? : 0;
        }
    }

    function can_edit_line_note($file, $lineid) {
        return $this->pc_view;
    }


    function grading_info($key = null) {
        $this->ensure_grade();
        if ($key && $this->grade_notes) {
            return get($this->grade_notes, $key);
        } else {
            return $this->grade_notes;
        }
    }

    function current_info($key = null) {
        if ($this->pset->gitless_grades || $this->hash === null) {
            return $this->grading_info($key);
        } else {
            return $this->commit_info($key);
        }
    }

    function user_info($key = null) {
        $this->ensure_contact_grade();
        if (!$this->contact_grade || !$this->contact_grade->notes) {
            return null;
        } else if ($key) {
            return get($this->contact_grade->notes, $key);
        } else {
            return $this->contact_grade->notes;
        }
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
        if (!$deadline) {
            return null;
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


    function clear_grade() {
        $this->grade = $this->contact_grade = $this->repo_grade = false;
        $this->can_view_grades = $this->user_can_view_grades = null;
    }

    function change_grader($grader) {
        if (is_object($grader))
            $grader = $grader->contactId;
        if ($this->pset->gitless_grades)
            $q = Dbl::format_query
                ("insert into ContactGrade (cid,pset,gradercid) values (?, ?, ?) on duplicate key update gradercid=values(gradercid)",
                 $this->user->contactId, $this->pset->id, $grader);
        else {
            assert(!!$this->hash);
            if (!$this->repo_grade || $this->repo_grade->gradebhash === null)
                $q = Dbl::format_query
                    ("insert into RepositoryGrade set repoid=?, branchid=?, pset=?, gradebhash=?, gradercid=?, placeholder=0 on duplicate key update gradebhash=values(gradebhash), gradercid=values(gradercid), placeholder=0",
                     $this->repo->repoid, $this->branchid, $this->pset->id,
                     $this->hash ? hex2bin($this->hash) : null, $grader);
            else
                $q = Dbl::format_query
                    ("update RepositoryGrade set gradebhash=?, gradercid=?, placeholder=0 where repoid=? and branchid=? and pset=? and gradebhash=?",
                     $this->hash ? hex2bin($this->hash) : $this->repo_grade->gradebhash, $grader,
                     $this->repo->repoid, $this->branchid, $this->pset->id, $this->repo_grade->gradebhash);
            $this->update_commit_info(array("gradercid" => $grader));
        }
        if ($q)
            $this->conf->qe_raw($q);
        $this->clear_grade();
    }

    function mark_grading_commit() {
        if ($this->pset->gitless_grades)
            $this->conf->qe("insert into ContactGrade set cid=?, pset=?, gradercid=? on duplicate key update gradercid=gradercid",
                    $this->user->contactId, $this->pset->psetid,
                    $this->viewer->contactId);
        else {
            assert(!!$this->hash);
            $grader = $this->commit_info("gradercid");
            if (!$grader)
                $grader = $this->grading_info("gradercid");
            $this->conf->qe("insert into RepositoryGrade set repoid=?, branchid=?, pset=?, gradebhash=?, gradercid=?, placeholder=0 on duplicate key update gradebhash=values(gradebhash), gradercid=values(gradercid), placeholder=0",
                    $this->repo->repoid, $this->branchid, $this->pset->psetid,
                    $this->hash ? hex2bin($this->hash) : null, $grader ? : null);
            $this->conf->invalidate_grades($this->pset);
        }
        $this->clear_grade();
    }

    function set_hidden_grades($hidegrade) {
        if ($this->pset->gitless_grades)
            $this->conf->qe("update ContactGrade set hidegrade=? where cid=? and pset=?", $hidegrade, $this->user->contactId, $this->pset->psetid);
        else
            $this->conf->qe("update RepositoryGrade set hidegrade=? where repoid=? and branchid=? and pset=?", $hidegrade, $this->repo->repoid, $this->branchid, $this->pset->psetid);
        $this->clear_grade();
    }


    function runner_logfile($checkt) {
        global $ConfSitePATH;
        return $ConfSitePATH . "/log/run" . $this->repo->cacheid
            . ".pset" . $this->pset->id . "/repo" . $this->repo->repoid
            . ".pset" . $this->pset->id . "." . $checkt . ".log";
    }

    function runner_output($checkt) {
        return @file_get_contents($this->runner_logfile($checkt));
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

    private function transfer_one_warning($file, $line, $text, $priority) {
        if ($file !== null && $text !== "") {
            $loc = "$file:$line";
            if (!isset($this->transferred_warnings[$file])) {
                $this->transferred_warnings[$file] = [];
            }
            if (!isset($this->transferred_warnings_priority[$loc])) {
                $this->transferred_warnings_priority[$loc] = $priority - 1;
            }
            if ($this->transferred_warnings_priority[$loc] < $priority) {
                $this->transferred_warnings[$file][$line] = [];
                $this->transferred_warnings_priority[$loc] = $priority;
            }
            if ($this->transferred_warnings_priority[$loc] == $priority) {
                $this->transferred_warnings[$file][$line][] .= $text;
            }
        }
    }

    private function transfer_warning_lines($lines, $prio) {
        $file = $line = null;
        $expect_context = false;
        $in_instantiation = 0;
        $text = "";
        $nlines = count($lines);
        for ($i = 0; $i !== $nlines; ++$i) {
            $s = $lines[$i];
            $sda = preg_replace('/\x1b\[[\d;]*m|\x1b\[\d*K/', '', $s);
            if (preg_match('/\A([^\s:]*):(\d+):(?:\d+:)?\s*(\S*)/', $sda, $m)) {
                $this_instantiation = strpos($sda, "required from") !== false;
                if ($file && $m[3] === "note:") {
                    if (strpos($sda, "in expansion of macro") !== false) {
                        $file = $m[1];
                        $line = $m[2];
                    }
                } else {
                    if (!$in_instantiation) {
                        $this->transfer_one_warning($file, $line, $text, $prio);
                        $text = "";
                    }
                    if ($in_instantiation !== 2 || $this_instantiation) {
                        $file = $m[1];
                        $line = $m[2];
                    }
                }
                $text .= $s . "\n";
                $expect_context = true;
                if ($in_instantiation !== 0 && $this_instantiation) {
                    $in_instantiation = 2;
                } else {
                    $in_instantiation = 0;
                }
            } else if (preg_match('/\A(?:\S|\s+[A-Z]+\s)/', $sda)) {
                if (str_starts_with($sda, "In file included")) {
                    $text .= $s . "\n";
                    while ($i + 1 < $nlines && str_starts_with($lines[$i + 1], " ")) {
                        ++$i;
                        $text .= $lines[$i] . "\n";
                    }
                    $in_instantiation = 1;
                } else if (strpos($sda, "In instantiation of")) {
                    if (!$in_instantiation) {
                        $this->transfer_one_warning($file, $line, $text, $prio);
                        $file = $line = null;
                        $text = "";
                    }
                    $text .= $s . "\n";
                    $in_instantiation = 1;
                } else if ($expect_context
                           && $i + 1 < $nlines
                           && strpos($lines[$i + 1], "^") !== false) {
                    $text .= $s . "\n" . $lines[$i + 1] . "\n";
                    ++$i;
                    $in_instantiation = 0;
                } else {
                    $this->transfer_one_warning($file, $line, $text, $prio);
                    $file = $line = null;
                    $text = "";
                    $in_instantiation = 0;
                }
                $expect_context = false;
            } else if ($file !== null) {
                $text .= $s . "\n";
                $expect_context = false;
            }
        }
        $this->transfer_one_warning($file, $line, $text, $prio);
    }

    private function transfer_warnings() {
        $this->transferred_warnings = [];

        // collect warnings from runner output
        foreach ($this->pset->runners as $runner) {
            if ($runner->transfer_warnings
                && $this->viewer->can_view_transferred_warnings($this->pset, $runner, $this->user)
                && ($output = $this->runner_output_for($runner))) {
                $this->transfer_warning_lines(explode("\n", $output), $runner->transfer_warnings_priority ? : 0);
            }
        }

        // squeeze out redundant warnings
        foreach ($this->transferred_warnings as $file => &$linemap) {
            foreach ($linemap as $line => &$wlist) {
                $wmap = [];
                $wtext = "";
                $nw = count($wlist);
                for ($i = 0; $i !== $nw; ++$i) {
                    $w = $wlist[$i];
                    if ($w[0] !== " " && isset($wmap[$w])) {
                        $j = $wmap[$w];
                        while ($i + 1 !== $nw && $wlist[$i + 1] === $wlist[$j + 1]) {
                            ++$i;
                            ++$j;
                        }
                    } else {
                        $wmap[$w] = $i;
                        $wtext .= $w;
                    }
                }
                $wlist = $wtext;
            }
            unset($wlist);
        }
        unset($linemap);
    }

    function transferred_warnings_for($file) {
        if ($this->transferred_warnings === null) {
            $this->transfer_warnings();
        }
        if (isset($this->transferred_warnings[$file])) {
            return $this->transferred_warnings[$file];
        }
        $slash = strrpos($file, "/");
        if ($slash !== false
            && isset($this->transferred_warnings[substr($file, $slash + 1)])) {
            return $this->transferred_warnings[substr($file, $slash + 1)];
        } else {
            return [];
        }
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


    function grade_json($no_entries = false) {
        $this->ensure_grade();
        if (!$this->can_view_grades()) {
            return null;
        }

        $notes = $this->current_info();
        if ($no_entries)
            $result = [];
        else
            $result = $this->pset->gradeentry_json($this->pc_view);
        $result["uid"] = $this->user->contactId;
        $agx = get($notes, "autogrades");
        $gx = get($notes, "grades");
        if ($agx || $gx || $this->is_grading_commit()) {
            $g = $ag = [];
            $total = $total_noextra = 0;
            foreach ($this->pset->visible_grades($this->pc_view) as $ge) {
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
            $result["grades"] = $g;
            if ($this->pc_view && !empty($ag)) {
                $result["autogrades"] = $ag;
            }
            $result["total"] = round_grade($total);
            if ($total != $total_noextra) {
                $result["total_noextra"] = round_grade($total_noextra);
            }
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

        // maybe hide extra-credits that are missing
        if (!$this->pc_view) {
            $gi = 0;
            $deleted = false;
            foreach ($this->pset->visible_grades($this->pc_view) as $ge) {
                if ($ge->is_extra
                    && !$ge->max_visible
                    && (!isset($result["grades"])
                        || $result["grades"][$gi] === null)) {
                    unset($result["entries"][$key]);
                    $result["order"][$gi] = null;
                    $deleted = true;
                }
                ++$gi;
            }
            if ($deleted) {
                for ($gi = count($result["order"]) - 1;
                     $gi >= 0 && !isset($result["order"][$gi]);
                     --$gi) {
                     array_pop($result["order"]);
                     if (isset($result["grades"])) {
                         array_pop($result["grades"]);
                     }
                }
            }
        }

        return $result;
    }


    function viewable_line_notes() {
        if ($this->viewer->can_view_comments($this->pset)) {
            return new LineNotesOrder($this->commit_info("linenotes"), $this->can_view_grades(), $this->pc_view);
        } else {
            return $this->empty_line_notes();
        }
    }

    function empty_line_notes() {
        return new LineNotesOrder(null, $this->can_view_grades(), $this->pc_view);
    }

    function diff($commita, $commitb, LineNotesOrder $lnorder = null, $args = []) {
        if (!$this->added_diffinfo) {
            if (($rs = $this->commit_info("runsettings"))
                && ($id = get($rs, "IGNOREDIFF"))) {
                $this->pset->add_diffconfig(new DiffConfig($id, (object) ["ignore" => true]));
            }
            $this->added_diffinfo = true;
        }

        assert(!isset($args["needfiles"]));
        if ($lnorder) {
            $args["needfiles"] = $lnorder->fileorder();
        }
        $diff = $this->repo->diff($this->pset, $commita, $commitb, $args);

        // expand diff to include all grade landmarks
        if ($this->pset->has_grade_landmark
            && $this->pc_view) {
            foreach ($this->pset->grades() as $g) {
                if ($g->landmark_file
                    && ($di = get($diff, $g->landmark_file))
                    && !$di->contains_linea($g->landmark_line)
                    && $di->is_handout_commit_a()) {
                    $di->expand_linea($g->landmark_line - 2, $g->landmark_line + 3);
                }
                if ($g->landmark_range_file
                    && ($di = get($diff, $g->landmark_range_file))
                    && $di->is_handout_commit_a()) {
                    $di->expand_linea($g->landmark_range_first, $g->landmark_range_last);
                }
            }
        }

        if ($lnorder) {
            // expand diff to include fake files
            foreach ($lnorder->fileorder() as $fn => $order) {
                if (isset($diff[$fn])
                    || !($diffc = $this->pset->find_diffconfig($fn))
                    || !$diffc->fileless) {
                    continue;
                }
                $diff[$fn] = $diffi = new DiffInfo($fn, $diffc);
                foreach ((array) $lnorder->file($fn) as $ln => $note) {
                    $diffi->add("Z", "", (int) substr($ln, 1), "");
                }
                uasort($diff, "DiffInfo::compare");
            }

            // add diff to linenotes
            $lnorder->set_diff($diff);
        }

        return $diff;
    }

    private function diff_line_code($t) {
        while (($p = strpos($t, "\t")) !== false) {
            $t = substr($t, 0, $p)
                . str_repeat(" ", $this->_diff_tabwidth - ($p % $this->_diff_tabwidth))
                . substr($t, $p + 1);
        }
        return htmlspecialchars($t);
    }

    function echo_file_diff($file, DiffInfo $dinfo, LineNotesOrder $lnorder, $args) {
        if (($dinfo->hide_if_anonymous && $this->user->is_anonymous)
            || ($dinfo->is_empty() && $dinfo->loaded)) {
            return;
        }

        $this->_diff_tabwidth = get($args, "tabwidth", $this->tabwidth());
        $this->_diff_lnorder = $lnorder;
        $open = !!get($args, "open");
        $only_content = get($args, "only_content");
        $no_heading = get($args, "no_heading") || $only_content;
        $id_by_user = !!get($args, "id_by_user");
        $no_grades = get($args, "only_diff") || $only_content;

        $fileid = html_id_encode($file);
        if ($id_by_user) {
            $fileid = html_id_encode($this->user->username) . "-" . $fileid;
        }
        $tabid = "pa-file-" . $fileid;
        $linenotes = $lnorder->file($file);
        if ($this->can_view_note_authors()) {
            $this->conf->stash_hotcrp_pc($this->viewer);
        }
        $lineanno = [];
        if ($this->pset->has_grade_landmark
            && $this->pc_view
            && !$this->is_handout_commit()
            && $dinfo->is_handout_commit_a()
            && !$no_grades) {
            foreach ($this->pset->grades() as $g) {
                if ($g->landmark_range_file === $file) {
                    $la = PsetViewLineAnno::ensure($lineanno, "a" . $g->landmark_range_first);
                    $la->grade_first[] = $g;
                    $la = PsetViewLineAnno::ensure($lineanno, "a" . ($g->landmark_range_last + 1));
                    $la->grade_last[] = $g;
                }
                if ($g->landmark_file === $file) {
                    $la = PsetViewLineAnno::ensure($lineanno, "a" . $g->landmark_line);
                    $la->grade_entries[] = $g;
                }
            }
        }
        if ($this->pset->has_transfer_warnings
            && !$this->is_handout_commit()) {
            foreach ($this->transferred_warnings_for($file) as $lineno => $w) {
                $la = PsetViewLineAnno::ensure($lineanno, "b" . $lineno);
                $la->warnings = $w;
                if (!$only_content)
                    $this->need_format = true;
            }
        }

        if (!$no_heading) {
            echo '<h3><a class="qq ui pa-unfold-file-diff" href=""><span class="foldarrow">',
                ($open ? "&#x25BC;" : "&#x25B6;"),
                "</span>", htmlspecialchars($dinfo->title ? : $file), "</a>";
            if (!$dinfo->fileless && !$dinfo->removed) {
                $rawfile = $file;
                if ($this->repo->truncated_psetdir($this->pset)
                    && str_starts_with($rawfile, $this->pset->directory_slash))
                    $rawfile = substr($rawfile, strlen($this->pset->directory_slash));
                echo '<a style="display:inline-block;margin-left:2em;font-weight:normal" href="', $this->hoturl("raw", ["file" => $rawfile]), '">[Raw]</a>';
            }
            echo '</h3>';
        }
        echo '<div id="', $tabid, '" class="pa-filediff';
        if ($this->pc_view) {
            echo " uim pa-editablenotes live";
        }
        if (!$this->user_can_view_grades()) {
            echo " hidegrades";
        }
        if (!$open) {
            echo " hidden";
        }
        if (!$dinfo->loaded) {
            echo " need-load";
        } else {
            $maxline = max(1000, $dinfo->max_lineno()) - 1;
            echo " pa-linenod", ceil(log10($maxline));
        }
        if ($id_by_user) {
            echo '" data-pa-file-user="', htmlspecialchars($this->user->username);
        }
        echo '" data-pa-file="', htmlspecialchars($file), "\"";
        if ($this->conf->default_format) {
            echo ' data-default-format="', $this->conf->default_format, '"';
        }
        echo ">";
        $curanno = new PsetViewLineAnno;
        foreach ($dinfo as $l) {
            $this->echo_line_diff($l, $file, $fileid, $linenotes, $lineanno, $curanno);
        }
        if ($curanno->grade_first) {
            echo '</div></div>';
        }
        echo "</div>\n";
        if ($this->need_format && !$only_content) {
            echo "<script>render_text.on_page()</script>\n";
            $this->need_format = false;
        }
    }

    private function echo_line_diff($l, $file, $fileid, $linenotes, $lineanno,
                                    $curanno) {
        if ($l[0] === "@") {
            $cx = strlen($l[3]) > 76 ? substr($l[3], 0, 76) . "..." : $l[3];
            $x = [" pa-gx ui", "pa-dcx", "", "", $cx];
        } else if ($l[0] === " ") {
            $x = [" pa-gc", "pa-dd", $l[1], $l[2], $l[3]];
        } else if ($l[0] === "-") {
            $x = [" pa-gd", "pa-dd", $l[1], "", $l[3]];
        } else if ($l[0] === "+") {
            $x = [" pa-gi", "pa-dd", "", $l[2], $l[3]];
        } else {
            $x = [null, null, "", $l[2], $l[3]];
        }

        $aln = $x[2] ? "a" . $x[2] : "";
        $bln = $x[3] ? "b" . $x[3] : "";
        $ala = $aln && isset($lineanno[$aln]) ? $lineanno[$aln] : null;

        if ($ala
            && $ala->grade_last
            && $curanno->grade_first
            && array_search($curanno->grade_first, $ala->grade_last) !== false) {
            echo '</div></div>';
            $curanno->grade_first = null;
        }
        if ($ala
            && $ala->grade_first
            && !$curanno->grade_first) {
            $g = $curanno->grade_first = $ala->grade_first[0];
            echo '<div class="pa-dg pa-with-sidegrade"><div class="pa-sidegrade">',
                '<div class="pa-gradebox pa-ps need-pa-grade" data-pa-grade="', $g->key, '"';
            if ($g->landmark_buttons) {
                echo ' data-pa-landmark-buttons="', htmlspecialchars(json_encode_browser($g->landmark_buttons)), '"';
            }
            echo '></div></div><div class="pa-dg">';
            $this->viewed_gradeentries[$g->key] = true;
        }

        $ak = $bk = "";
        if ($linenotes && $aln && isset($linenotes->$aln)) {
            $ak = ' id="L' . $aln . '_' . $fileid . '"';
        }
        if ($linenotes && $bln && isset($linenotes->$bln)) {
            $bk = ' id="L' . $bln . '_' . $fileid . '"';
        }

        if (!$x[2] && !$x[3]) {
            $x[2] = $x[3] = "...";
        }
        if ($x[2]) {
            $ak .= ' data-landmark="' . $x[2] . '"';
        }
        if ($x[3]) {
            $bk .= ' data-landmark="' . $x[3] . '"';
        }

        $nx = null;
        if ($linenotes) {
            if ($bln && isset($linenotes->$bln))
                $nx = LineNote::make_json($file, $bln, $linenotes->$bln);
            if (!$nx && $aln && isset($linenotes->$aln))
                $nx = LineNote::make_json($file, $aln, $linenotes->$aln);
        }

        if ($x[0]) {
            echo '<div class="pa-dl', $x[0], '">',
                '<div class="pa-da"', $ak, '></div>',
                '<div class="pa-db"', $bk, '></div>',
                '<div class="', $x[1];
            if (isset($l[4]) && ($l[4] & DiffInfo::LINE_NONL)) {
                echo ' pa-dnonl';
            }
            echo '">', $this->diff_line_code($x[4]), "</div></div>\n";
        }

        if ($bln && isset($lineanno[$bln]) && $lineanno[$bln]->warnings !== null) {
            echo '<div class="pa-dl pa-gn"><div class="pa-warnbox need-format" data-format="2">', htmlspecialchars($lineanno[$bln]->warnings), '</div></div>';
        }

        if ($ala) {
            foreach ($ala->grade_entries ? : [] as $g) {
                echo '<div class="pa-dl pa-gn';
                if ($curanno->grade_first === $g) {
                    echo ' pa-no-sidegrade';
                }
                echo '"><div class="pa-graderow">',
                    '<div class="pa-gradebox need-pa-grade" data-pa-grade="', $g->key, '"';
                if ($g->landmark_file === $g->landmark_range_file
                    && $g->landmark_buttons) {
                    echo ' data-pa-landmark-buttons="', htmlspecialchars(json_encode_browser($g->landmark_buttons)), '"';
                }
                echo '></div></div></div>';
                $this->viewed_gradeentries[$g->key] = true;
            }
        }

        if ($nx) {
            $this->echo_linenote($nx);
        }
    }

    private function echo_linenote(LineNote $note) {
        echo '<div class="pa-dl pa-gw'; /* NB script depends on this class exactly */
        if ((string) $note->note === "") {
            echo ' hidden';
        }
        echo '" data-pa-note="', htmlspecialchars(json_encode_browser($note->render_json($this->can_view_note_authors()))),
            '"><div class="pa-notebox">';
        if ((string) $note->note === "") {
            echo '</div></div>';
            return;
        }
        echo '<div class="pa-notediv">';
        $links = array();
        list($nfile, $nlineid) = $this->_diff_lnorder->get_next($note->file, $note->lineid);
        if ($nfile) {
            $links[] = '<a href="#L' . $nlineid . '_'
                . html_id_encode($nfile) . '" class="uix pa-goto">Next &gt;</a>';
        } else {
            $links[] = '<a href="#">Top</a>';
        }
        if (!empty($links)) {
            echo '<div class="pa-note-links">',
                join("&nbsp;&nbsp;&nbsp;", $links) , '</div>';
        }
        if ($this->can_view_note_authors() && !empty($note->users)) {
            $pcmembers = $this->conf->pc_members_and_admins();
            $autext = [];
            foreach ($note->users as $au) {
                if (($p = get($pcmembers, $au))) {
                    if ($p->nicknameAmbiguous)
                        $autext[] = Text::name_html($p);
                    else
                        $autext[] = htmlspecialchars($p->nickname ? : $p->firstName);
                }
            }
            if (!empty($autext)) {
                echo '<div class="pa-note-author">[', join(", ", $autext), ']</div>';
            }
        }
        echo '<div class="pa-note', ($note->iscomment ? ' pa-commentnote' : ' pa-gradenote');
        if ($note->format) {
            echo ' need-format" data-format="', $note->format;
            $this->need_format = true;
        } else {
            echo ' format0';
        }
        echo '">', htmlspecialchars($note->note), '</div>';
        echo '</div></div></div>';
    }
}

class PsetViewLineAnno {
    public $grade_entries;
    public $grade_first;
    public $grade_last;
    public $warnings;

    static function ensure(&$lineanno, $line) {
        if (!isset($lineanno[$line])) {
            $lineanno[$line] = new PsetViewLineAnno;
        }
        return $lineanno[$line];
    }
}
