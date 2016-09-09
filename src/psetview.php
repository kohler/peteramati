<?php
// psetview.php -- CS61-monster helper class for pset view
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler
// See LICENSE for open-source distribution terms

class PsetView {
    public $pset;
    public $user;
    public $repo = null;
    public $partner;
    private $partner_same = null;
    public $can_set_repo;
    public $can_view_repo_contents;
    public $can_see_grades;

    private $grade = false;
    private $repo_grade = null;
    private $grade_notes = null;

    private $commit = null;
    private $commit_notes = false;
    private $recent_commits = false;
    private $recent_commits_truncated = null;
    private $latest_commit = null;

    public function __construct($pset, $user) {
        global $Me;
        $this->pset = $pset;
        $this->user = $user;
        $this->partner = $user->partner($pset->id);
        $this->can_set_repo = $Me->can_set_repo($pset, $user);
        if (!$pset->gitless)
            $this->repo = $user->repo($pset->id);
        $this->can_view_repo_contents = $this->repo
            && $user->can_view_repo_contents($this->repo);
        $this->load_grade();
    }

    public function set_commit($reqcommit) {
        global $Conf;
        $this->commit = $this->commit_notes = false;
        if (!$this->repo)
            return false;
        if ($this->recent_commits === false)
            $this->load_recent_commits();
        if ($reqcommit)
            $this->commit = git_commit_in_list($this->recent_commits, $reqcommit);
        else if ($this->repo_grade
                 && isset($this->recent_commits[$this->repo_grade->gradehash]))
            $this->commit = $this->repo_grade->gradehash;
        else if ($this->latest_commit)
            $this->commit = $this->latest_commit->hash;
        return $this->commit;
    }

    public function has_commit_set() {
        return $this->commit !== null;
    }

    public function commit_hash() {
        assert($this->commit !== null);
        return $this->commit;
    }

    public function maybe_commit_hash() {
        return $this->commit;
    }

    public function commit() {
        if ($this->commit === null) {
            global $Me;
            error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) . " " . $Me->email);
        }
        assert($this->commit !== null);
        if ($this->commit)
            return $this->recent_commits($this->commit);
        else
            return false;
    }

    public function can_have_grades() {
        return $this->pset->gitless_grades || $this->commit();
    }

    public function load_recent_commits() {
        list($user, $repo, $pset) = array($this->user, $this->repo, $this->pset);
        if (!$repo)
            return;
        $this->recent_commits = $user->repo_recent_commits($repo, $pset, 100);
        if (!$this->recent_commits && isset($pset->test_file)
            && $user->repo_ls_files($repo, "REPO/master", $pset->test_file)) {
            $repo->_truncated_psetdir[$pset->id] = true;
            $this->recent_commits = $user->repo_recent_commits($repo, null, 100);
        }
        $this->recent_commits_truncated = count($this->recent_commits) == 100;
        if (count($this->recent_commits))
            $this->latest_commit = current($this->recent_commits);
        else
            $this->latest_commit = false;
    }

    public function recent_commits($hash = null) {
        if ($this->recent_commits === false)
            $this->load_recent_commits();
        if (!$hash)
            return $this->recent_commits;
        if (strlen($hash) != 40)
            $hash = git_commit_in_list($this->recent_commits, $hash);
        if (($c = @$this->recent_commits[$hash]))
            return $c;
        return false;
    }

    public function latest_commit() {
        if ($this->recent_commits === false)
            $this->load_recent_commits();
        return $this->latest_commit;
    }

    public function latest_hash() {
        if ($this->recent_commits === false)
            $this->load_recent_commits();
        return $this->latest_commit ? $this->latest_commit->hash : false;
    }

    public function is_latest_commit() {
        return $this->commit
            && ($lc = $this->latest_commit())
            && $this->commit == $lc->hash;
    }

    public function derived_handout_hash() {
        $hbases = array();
        foreach (Contact::handout_repo_recent_commits($this->pset) as $c)
            $hbases[$c->hash] = true;
        foreach ($this->recent_commits() as $c)
            if (@$hbases[$c->hash])
                return $c->hash;
        return false;
    }

    public function commit_info($key = null) {
        if ($this->commit_notes === false) {
            if (!$this->commit
                || ($this->repo_grade
                    && $this->repo_grade->gradehash == $this->commit))
                $this->commit_notes = $this->grade_notes;
            else
                $this->commit_notes = $this->user->commit_info
                    ($this->commit, $this->pset);
        }
        if (!$key)
            return $this->commit_notes;
        else
            return $this->commit_notes ? @$this->commit_notes->$key : null;
    }

    public function update_commit_info($updates, $reset_keys = false) {
        assert(!!$this->commit);
        $this->commit_notes = Contact::update_commit_info
            ($this->commit, $this->repo, $this->pset,
             $updates, $reset_keys);
        if ($this->repo_grade && $this->repo_grade->gradehash == $this->commit)
            $this->grade_notes = $this->commit_notes;
    }

    public function tarball_url() {
        if ($this->repo && $this->commit !== null
            && $this->pset->repo_tarball_patterns) {
            for ($i = 0; $i + 1 < count($this->pset->repo_tarball_patterns); $i += 2) {
                $x = preg_replace('`' . str_replace("`", "\\`", $this->pset->repo_tarball_patterns[$i]) . '`s',
                                  $this->pset->repo_tarball_patterns[$i + 1],
                                  $this->repo->ssh_url(), -1, $nreplace);
                if ($x !== null && $nreplace)
                    return str_replace('${HASH}', $this->commit, $x);
            }
        }
        return null;
    }


    public function backpartners() {
        return array_unique($this->user->links(LINK_BACKPARTNER, $this->pset->id));
    }

    public function partner_same() {
        if ($this->partner_same === null && $this->partner) {
            $backpartners = $this->backpartners();
            $this->partner_same = count($backpartners) == 1
                && $this->partner->contactId == $backpartners[0];
        } else if ($this->partner_same === null)
            $this->partner_same = false;
        return $this->partner_same;
    }


    public function load_grade() {
        global $Me;
        if ($this->pset->gitless_grades) {
            $this->grade = $this->user->contact_grade($this->pset);
            $this->grade_notes = @$this->grade->notes;
        } else {
            $this->repo_grade = null;
            if ($this->repo)
                $this->repo_grade = $this->user->repo_grade
                    ($this->repo, $this->pset);
            $this->grade = $this->repo_grade;
            $this->grade_notes = @$this->grade->notes;
            if ($this->grade_notes
                && @$this->grade->gradercid
                && !@$this->grade_notes->gradercid)
                $this->grade_notes = Contact::update_commit_info
                    ($this->grade->gradehash, $this->repo, $this->pset,
                     array("gradercid" => $this->grade->gradercid));
            if (@$this->grade->gradehash)
                // NB don't check recent_commits association here
                $this->commit = $this->grade->gradehash;
        }
        $this->can_see_grades = $Me->can_see_grades($this->pset, $this->user, $this);
        $this->user_can_see_grades = $this->user->can_see_grades($this->pset, $this->user, $this);
    }

    public function has_grading() {
        if ($this->grade === false)
            $this->load_grade();
        return !!$this->grade;
    }

    public function grading_hash() {
        if ($this->pset->gitless_grades)
            return false;
        if ($this->grade === false)
            $this->load_grade();
        if ($this->repo_grade)
            return $this->repo_grade->gradehash;
        return false;
    }

    public function grading_commit() {
        if ($this->pset->gitless_grades)
            return false;
        if ($this->grade === false)
            $this->load_grade();
        if ($this->repo_grade)
            return $this->recent_commits($this->repo_grade->gradehash);
        return false;
    }

    public function is_grading_commit() {
        if ($this->pset->gitless_grades)
            return true;
        if ($this->grade === false)
            $this->load_grade();
        return $this->commit
            && $this->repo_grade
            && $this->commit == $this->repo_grade->gradehash;
    }

    public function gradercid() {
        if ($this->grade === false)
            $this->load_grade();
        if ($this->pset->gitless_grades)
            return $this->grade ? $this->grade->gradercid : 0;
        else if ($this->repo_grade
                 && $this->commit == $this->repo_grade->gradehash)
            return $this->repo_grade->gradercid;
        else
            return $this->commit_info("gradercid") ? : 0;
    }


    public function grading_info($key = null) {
        if ($this->grade === false)
            $this->load_grade();
        if (!$key)
            return $this->grade_notes;
        else
            return $this->grade_notes ? @$this->grade_notes->$key : null;
    }

    public function commit_or_grading_info() {
        if ($this->pset->gitless_grades || !$this->commit())
            return $this->grading_info();
        else
            return $this->commit_info();
    }

    public function grading_info_empty() {
        if ($this->grade === false)
            $this->load_grade();
        if (!$this->grade_notes)
            return true;
        $gn = (array) $this->grade_notes;
        return !$gn || count($gn) == 0
            || (count($gn) == 1 && isset($gn["gradercid"]));
    }

    public function grades_hidden() {
        if ($this->grade === false)
            $this->load_grade();
        return $this->grade && $this->grade->hidegrade;
    }

    public function late_hours($no_auto = false) {
        $cinfo = $this->commit_or_grading_info();
        if (!$no_auto && @$cinfo->late_hours !== null)
            return (object) array("hours" => $cinfo->late_hours,
                                  "override" => true);

        $deadline = $this->pset->deadline;
        if (!$this->user->extension && $this->pset->deadline_college)
            $deadline = $this->pset->deadline_college;
        else if ($this->user->extension && $this->pset->deadline_extension)
            $deadline = $this->pset->deadline_extension;
        if (!$deadline)
            return null;

        $timestamp = @$cinfo->timestamp;
        if (!$timestamp
            && ($h = $this->commit ? : $this->grading_hash())
            && ($ls = $this->recent_commits($h)))
            $timestamp = $ls->commitat;
        if (!$timestamp)
            return null;

        $lh = 0;
        if ($timestamp > $deadline)
            $lh = (int) ceil(($timestamp - $deadline) / 3600);
        return (object) array("hours" => $lh,
                              "commitat" => $timestamp,
                              "deadline" => $deadline);
    }


    function change_grader($grader) {
        if (is_object($grader))
            $grader = $grader->contactId;
        if ($this->pset->gitless_grades)
            $q = Dbl::format_query
                ("insert into ContactGrade (cid,pset,gradercid) values (?, ?, ?) on duplicate key update gradercid=values(gradercid)",
                 $this->user->contactId, $this->pset->psetid, $grader);
        else {
            assert(!!$this->commit);
            if (!$this->repo_grade || !$this->repo_grade->gradehash)
                $q = Dbl::format_query
                    ("insert into RepositoryGrade set repoid=?, pset=?, gradehash=?, gradercid=?, placeholder=0 on duplicate key update gradehash=values(gradehash), gradercid=values(gradercid), placeholder=0",
                     $this->repo->repoid, $this->pset->psetid,
                     $this->commit ? : null, $grader);
            else
                $q = Dbl::format_query
                    ("update RepositoryGrade set gradehash=?, gradercid=?, placeholder=0 where repoid=? and pset=? and gradehash=?",
                     $this->commit ? : $this->repo_grade->gradehash, $grader,
                     $this->repo->repoid, $this->pset->psetid, $this->repo_grade->gradehash);
            $this->update_commit_info(array("gradercid" => $grader));
        }
        if ($q)
            Dbl::qe_raw($q);
        $this->grade = $this->repo_grade = false;
    }

    function mark_grading_commit() {
        global $Me;
        if ($this->pset->gitless_grades)
            Dbl::qe("insert into ContactGrade (cid,pset,gradercid) values (?, ?, ?) on duplicate key update gradercid=gradercid",
                    $this->user->contactId, $this->pset->psetid,
                    $Me->contactId);
        else {
            assert(!!$this->commit);
            $grader = $this->commit_info("gradercid") ? : null;
            Dbl::qe("insert into RepositoryGrade (repoid,pset,gradehash,gradercid,placeholder) values (?, ?, ?, ?, 0) on duplicate key update gradehash=values(gradehash), gradercid=values(gradercid), placeholder=0",
                    $this->repo->repoid, $this->pset->psetid,
                    $this->commit ? : null, $grader);
        }
        $this->grade = $this->repo_grade = false;
    }


    function hoturl_args($args = null) {
        global $Me;
        $xargs = array("pset" => $this->pset->urlkey,
                       "u" => $Me->user_linkpart($this->user));
        if ($this->commit)
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
}
