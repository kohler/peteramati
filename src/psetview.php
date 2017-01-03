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
    private $partner_same = null;

    private $grade = false;         // either ContactGrade or RepositoryGrade+CommitNotes
    private $repo_grade = null;     // RepositoryGrade+CommitNotes
    private $grade_notes = null;
    private $can_view_grades = null;
    private $user_can_view_grades = null;

    private $hash = null;
    private $commit_record = false; // CommitNotes (maybe +RepositoryGrade)
    private $commit_notes = false;
    private $derived_handout_commit = null;
    private $n_visible_grades = null;
    private $n_visible_in_total;
    private $n_set_grades;

    function __construct(Pset $pset, Contact $user, Contact $viewer, $hash = null) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->user = $user;
        $this->viewer = $viewer;
        $this->pc_view = $viewer->isPC && $viewer !== $user;
        $this->partner = $user->partner($pset->id);
        if (!$pset->gitless)
            $this->repo = $user->repo($pset->id);
        $this->hash = $hash;
    }

    function connected_hash($hash) {
        $c = $this->repo ? $this->repo->connected_commit($hash) : null;
        return $c ? $c->hash : false;
    }

    function set_hash($reqhash) {
        $this->hash = false;
        $this->commit_record = $this->commit_notes = $this->derived_handout_commit = false;
        $this->n_visible_grades = null;
        if (!$this->repo)
            return false;
        if ($reqhash)
            $c = $this->repo->connected_commit($reqhash);
        else {
            $c = null;
            if ($this->repo_grade)
                $c = $this->repo->connected_commit($this->repo_grade->gradehash);
            if (!$c)
                $c = $this->latest_commit();
        }
        $this->hash = $c ? $c->hash : false;
        return $this->hash;
    }

    function force_set_hash($reqhash) {
        assert($reqhash === false || strlen($reqhash) === 40);
        if ($this->hash !== $reqhash) {
            $this->hash = $reqhash;
            $this->commit_notes = $this->derived_handout_commit = false;
        }
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
            return $this->repo->commits($this->pset);
        else
            return $this->repo->connected_commit($hash, $this->pset);
    }

    function latest_commit() {
        $cs = $this->repo ? $this->repo->commits($this->pset) : [];
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
        assert(!!$this->pset->gitless);
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

    function can_view_grades() {
        if ($this->can_view_grades === null)
            $this->can_view_grades = $this->viewer->can_view_pset_grades($this->pset)
                && ($this->pc_view || !$this->grades_hidden());
        return $this->can_view_grades;
    }

    function user_can_view_grades() {
        if ($this->user_can_view_grades === null)
            $this->user_can_view_grades = $this->user->can_view_pset_grades($this->pset)
                && !$this->grades_hidden();
        return $this->user_can_view_grades;
    }

    private function ensure_n_visible_grades() {
        if ($this->n_visible_grades === null) {
            $this->n_visible_grades = $this->n_set_grades = $this->n_visible_in_total = 0;
            if ($this->can_view_grades()) {
                $notes = $this->current_info();
                $ag = get($notes, "autogrades");
                $g = get($notes, "grades");
                foreach ($this->pset->grades as $ge)
                    if (!$ge->hide || $this->pc_view) {
                        ++$this->n_visible_grades;
                        if (($ag && get($ag, $ge->name) !== null)
                            || ($g && get($g, $ge->name) !== null))
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
                $gv = $g ? get($g, $ge->name) : null;
                if ($gv === null && $ag)
                    $gv = get($ag, $ge->name);
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

    function grades_hidden() {
        $this->ensure_grade();
        return $this->grade && $this->grade->hidegrade;
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

    function late_hours($no_auto = false) {
        $cinfo = $this->current_info();
        if (!$no_auto && get($cinfo, "late_hours") !== null)
            return (object) array("hours" => $cinfo->late_hours,
                                  "override" => true);

        $deadline = $this->pset->deadline;
        if (!$this->user->extension && $this->pset->deadline_college)
            $deadline = $this->pset->deadline_college;
        else if ($this->user->extension && $this->pset->deadline_extension)
            $deadline = $this->pset->deadline_extension;
        if (!$deadline)
            return null;

        $timestamp = get($cinfo, "timestamp");
        if (!$timestamp
            && ($h = $this->hash ? : $this->grading_hash())
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
            Dbl::qe_raw($q);
        $this->grade = $this->repo_grade = false;
        $this->can_view_grades = $this->user_can_view_grades = null;
    }

    function mark_grading_commit() {
        if ($this->pset->gitless_grades)
            Dbl::qe("insert into ContactGrade (cid,pset,gradercid) values (?, ?, ?) on duplicate key update gradercid=gradercid",
                    $this->user->contactId, $this->pset->psetid,
                    $this->viewer->contactId);
        else {
            assert(!!$this->hash);
            $grader = $this->commit_info("gradercid");
            if (!$grader)
                $grader = $this->grading_info("gradercid");
            Dbl::qe("insert into RepositoryGrade (repoid,pset,gradehash,gradercid,placeholder) values (?, ?, ?, ?, 0) on duplicate key update gradehash=values(gradehash), gradercid=values(gradercid), placeholder=0",
                    $this->repo->repoid, $this->pset->psetid,
                    $this->hash ? : null, $grader ? : null);
        }
        $this->grade = $this->repo_grade = false;
        $this->can_view_grades = $this->user_can_view_grades = null;
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
        if (!$this->can_view_grades())
            return null;
        $notes = $this->current_info();
        $result = $this->pset->gradeentry_json($this->pc_view);
        $agx = get($notes, "autogrades");
        $gx = get($notes, "grades");
        if ($agx || $gx || $this->is_grading_commit()) {
            $g = $ag = [];
            $total = $total_noextra = 0;
            foreach ($this->pset->grades as $ge)
                if (!$ge->hide || $this->pc_view) {
                    $key = $ge->name;
                    $gv = null;
                    if ($agx) {
                        $gv = property_exists($agx, $key) ? $agx->$key : null;
                        $ag[] = $gv;
                    }
                    if ($gx)
                        $gv = property_exists($gx, $key) ? $gx->$key : $gv;
                    $g[] = $gv;
                    if (!$ge->no_total && $gv) {
                        $total += $gv;
                        if (!$ge->is_extra)
                            $total_noextra += $gv;
                    }
                }
            $result["grades"] = $g;
            if ($this->pc_view && !empty($ag))
                $result["autogrades"] = $ag;
            $result["total"] = $total;
            if ($total != $total_noextra)
                $g["total_noextra"] = $total_noextra;
        }
        if (!$this->pset->gitless_grades && !$this->is_grading_commit())
            $result["grading_hash"] = $this->grading_hash();
        return $result;
    }


    function echo_file_diff($file, DiffInfo $dinfo, LinenotesOrder $lnorder, $open) {
        $fileid = html_id_encode($file);
        $tabid = "file61_" . $fileid;
        $linenotes = $lnorder->file($file);

        echo '<h3><a class="fold61" href="#" onclick="return fold61(',
            "'#$tabid'", ',this)"><span class="foldarrow">',
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
        echo '<table id="', $tabid, '" class="code61 diff61 filediff61';
        if ($this->pc_view)
            echo " live";
        if (!$this->user_can_view_grades())
            echo " hidegrade61";
        if (!$open)
            echo '" style="display:none';
        echo '" data-pa-file="', htmlspecialchars($file), '" data-pa-fileid="', $fileid, "\"><tbody>\n";
        if ($this->pc_view)
            Ht::stash_script("jQuery('#$tabid').mousedown(linenote61).mouseup(linenote61)");
        foreach ($dinfo->diff as $l) {
            if ($l[0] == "@")
                $x = array(" gx", "pa-dcx", "", "", $l[3]);
            else if ($l[0] == " ")
                $x = array(" gc", "pa-dd", $l[1], $l[2], $l[3]);
            else if ($l[0] == "-")
                $x = array(" gd", "pa-dd", $l[1], "", $l[3]);
            else
                $x = array(" gi", "pa-dd", "", $l[2], $l[3]);

            $aln = $x[2] ? "a" . $x[2] : "";
            $bln = $x[3] ? "b" . $x[3] : "";

            $ak = $bk = "";
            if ($linenotes && $aln && isset($linenotes->$aln))
                $ak = ' id="L' . $aln . '_' . $fileid . '"';
            if ($bln)
                $bk = ' id="L' . $bln . '_' . $fileid . '"';

            if (!$x[2] && !$x[3])
                $x[2] = $x[3] = "...";

            echo '<tr class="pa-dl', $x[0], '">',
                '<td class="pa-da"', $ak, '>', $x[2], '</td>',
                '<td class="pa-db"', $bk, '>', $x[3], '</td>',
                '<td class="', $x[1], '">', diff_line_code($x[4]), "</td></tr>\n";

            if ($linenotes && $bln && isset($linenotes->$bln))
                $this->echo_linenote($file, $bln, $linenotes->$bln, $lnorder);
            if ($linenotes && $aln && isset($linenotes->$aln))
                $this->echo_linenote($file, $aln, $linenotes->$aln, $lnorder);
        }
        echo "</tbody></table>\n";
    }

    function echo_linenote($file, $lineid, $note,
                           LinenotesOrder $lnorder = null) {
        $note_object = null;
        if (is_object($note)) { // How the fuck did this shit get in the DB, why does PHP suck
            $note_object = $note;
            $note = [];
            for ($i = 0; property_exists($note_object, $i); ++$i)
                $note[] = $note_object->$i;
        }
        if (!is_array($note))
            $note = array(false, $note);
        if ($this->can_view_grades() || $note[0]) {
            echo '<tr class="pa-dl gw">', /* NB script depends on this class */
                '<td colspan="2" class="difflnoteborder61"></td>',
                '<td class="difflnote61">';
            if ($lnorder) {
                $links = array();
                //list($pfile, $plineid) = $lnorder->get_prev($file, $lineid);
                //if ($pfile)
                //    $links[] = '<a href="#L' . $plineid . '_'
                //        . html_id_encode($pfile) . '">&larr; Prev</a>';
                list($nfile, $nlineid) = $lnorder->get_next($file, $lineid);
                if ($nfile)
                    $links[] = '<a href="#L' . $nlineid . '_'
                        . html_id_encode($nfile) . '">Next &gt;</a>';
                else
                    $links[] = '<a href="#">Top</a>';
                if (!empty($links))
                    echo '<div class="difflnoteptr61">',
                        join("&nbsp;&nbsp;&nbsp;", $links) , '</div>';
            }
            if ($this->pc_view && get($note, 2)) {
                global $Conf;
                $pcmembers = $Conf->pc_members_and_admins();
                if (isset($pcmembers[$note[2]])) {
                    $p = $pcmembers[$note[2]];
                    echo '<div class="difflnoteauthor61">[',
                        htmlspecialchars($p->firstNameAmbiguous ? Text::name_text($p) : $p->firstName),
                        ']</div>';
                }
            }
            if (!is_string($note[1]))
                error_log("fudge {$this->user->github_username} error: " . json_encode($note));
            echo '<div class="note61',
                ($note[0] ? ' commentnote' : ' gradenote'),
                '">', htmlspecialchars($note[1]), '</div>',
                '<div class="clear"></div></td></tr>';
        }
    }

    function echo_linenote_entry_prototype() {
        echo '<tr class="pa-dl gw iscomment61"',
            ' data-pa-savednote="">', /* NB script depends on this class */
            '<td colspan="2" class="difflnoteborder61"></td>',
            '<td class="difflnote61"><div class="diffnoteholder61" style="display:none">',
            Ht::form($this->hoturl_post("pset", array("savelinenote" => 1)),
                     array("onsubmit" => "return savelinenote61(this)")),
            '<div class="f-contain">',
            Ht::hidden("file", ""),
            Ht::hidden("line", ""),
            Ht::hidden("iscomment", "", array("class" => "iscomment")),
            '<textarea class="diffnoteentry61" name="note"></textarea>',
            '<div class="aab aabr difflnoteaa61">',
            '<div class="aabut">',
            Ht::submit("Save comment"),
            '</div><div class="aabut">';
        if ($this->user_can_view_grades())
            echo Ht::hidden("iscomment", 1);
        else
            echo Ht::checkbox("iscomment", 1), '&nbsp;', Ht::label("Show immediately");
        echo '</div></div></div></form></div></td></tr>';
    }
}
