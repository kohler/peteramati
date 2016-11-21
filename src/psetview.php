<?php
// psetview.php -- CS61-monster helper class for pset view
// Peteramati is Copyright (c) 2006-2016 Eddie Kohler
// See LICENSE for open-source distribution terms

class PsetView {
    public $pset;
    public $user;
    public $viewer;
    public $pc_view;
    public $repo = null;
    public $partner;
    private $partner_same = null;
    public $can_set_repo;
    public $can_view_repo_contents;
    public $user_can_view_repo_contents;
    public $can_see_comments;
    public $can_see_grades;
    public $user_can_see_grades;

    private $grade = false;
    private $repo_grade = null;
    private $grade_notes = null;

    private $commit = null;
    private $commit_notes = false;
    private $recent_commits = null;
    private $recent_commits_truncated = null;
    private $latest_commit = null;

    function __construct(Pset $pset, Contact $user, Contact $viewer) {
        $this->pset = $pset;
        $this->user = $user;
        $this->viewer = $viewer;
        $this->pc_view = $viewer->isPC && $viewer !== $user;
        $this->partner = $user->partner($pset->id);
        $this->can_set_repo = $viewer->can_set_repo($pset, $user);
        if (!$pset->gitless)
            $this->repo = $user->repo($pset->id);
        $this->can_view_repo_contents = $this->repo
            && $viewer->can_view_repo_contents($this->repo);
        $this->user_can_view_repo_contents = $this->repo
            && $user->can_view_repo_contents($this->repo);
        $this->load_grade();
    }

    function connected_commit($commit) {
        if ($this->recent_commits === null)
            $this->load_recent_commits();
        if (($c = git_commit_in_list($this->recent_commits, $commit)))
            return $c;
        else if (($c = $this->repo->find_snapshot($commit))) {
            $this->recent_commits[$c->hash] = $c;
            return $c->hash;
        } else
            return false;
    }

    function set_commit($reqcommit) {
        $this->commit = $this->commit_notes = false;
        if (!$this->repo)
            return false;
        if ($this->recent_commits === null)
            $this->load_recent_commits();
        if ($reqcommit)
            $this->commit = $this->connected_commit($reqcommit);
        else if ($this->repo_grade && ($c = $this->connected_commit($this->repo_grade->gradehash)))
            $this->commit = $c;
        else if ($this->latest_commit)
            $this->commit = $this->latest_commit->hash;
        return $this->commit;
    }

    function has_commit_set() {
        return $this->commit !== null;
    }

    function commit_hash() {
        assert($this->commit !== null);
        return $this->commit;
    }

    function maybe_commit_hash() {
        return $this->commit;
    }

    function commit() {
        if ($this->commit === null)
            error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) . " " . $this->viewer->email);
        assert($this->commit !== null);
        if ($this->commit)
            return $this->recent_commits($this->commit);
        else
            return false;
    }

    function can_have_grades() {
        return $this->pset->gitless_grades || $this->commit();
    }

    function load_recent_commits() {
        list($user, $repo, $pset) = array($this->user, $this->repo, $this->pset);
        if (!$repo)
            return;
        $this->recent_commits = $repo->commits($pset, 100) ? : [];
        if (!$this->recent_commits && isset($pset->test_file)
            && $repo->ls_files("REPO/master", $pset->test_file)) {
            $repo->_truncated_psetdir[$pset->id] = true;
            $this->recent_commits = $repo->commits(null, 100) ? : [];
        }
        $this->recent_commits_truncated = count($this->recent_commits) == 100;
        if (!empty($this->recent_commits))
            $this->latest_commit = current($this->recent_commits);
        else
            $this->latest_commit = false;
    }

    function recent_commits($hash = null) {
        if ($this->recent_commits === false)
            $this->load_recent_commits();
        if (!$hash)
            return $this->recent_commits;
        if (strlen($hash) != 40)
            $hash = git_commit_in_list($this->recent_commits, $hash);
        if (($c = get($this->recent_commits, $hash)))
            return $c;
        return false;
    }

    function latest_commit() {
        if ($this->recent_commits === false)
            $this->load_recent_commits();
        return $this->latest_commit;
    }

    function latest_hash() {
        if ($this->recent_commits === false)
            $this->load_recent_commits();
        return $this->latest_commit ? $this->latest_commit->hash : false;
    }

    function is_latest_commit() {
        return $this->commit
            && ($lc = $this->latest_commit())
            && $this->commit == $lc->hash;
    }

    function derived_handout_hash() {
        $hbases = $this->pset->handout_commits();
        foreach ($this->recent_commits() as $c)
            if (isset($hbases[$c->hash]))
                return $c->hash;
        return false;
    }

    function commit_info($key = null) {
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
            return $this->commit_notes ? get($this->commit_notes, $key) : null;
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


    function load_grade() {
        if ($this->pset->gitless_grades) {
            $this->grade = $this->pset->contact_grade_for($this->user);
            $this->grade_notes = get($this->grade, "notes");
        } else {
            $this->repo_grade = null;
            if ($this->repo)
                $this->repo_grade = $this->user->repo_grade
                    ($this->repo, $this->pset);
            $this->grade = $this->repo_grade;
            $this->grade_notes = get($this->grade, "notes");
            if ($this->grade_notes
                && get($this->grade, "gradercid")
                && !get($this->grade_notes, "gradercid"))
                $this->grade_notes = Contact::update_commit_info
                    ($this->grade->gradehash, $this->repo, $this->pset,
                     array("gradercid" => $this->grade->gradercid));
            if (get($this->grade, "gradehash"))
                // NB don't check recent_commits association here
                $this->commit = $this->grade->gradehash;
        }
        $this->can_see_comments = $this->viewer->can_see_comments($this->pset, $this->user, $this);
        $this->can_see_grades = $this->viewer->can_see_grades($this->pset, $this->user, $this);
        $this->user_can_see_grades = $this->user->can_see_grades($this->pset, $this->user, $this);
    }

    function has_grading() {
        if ($this->grade === false)
            $this->load_grade();
        return !!$this->grade;
    }

    function grading_hash() {
        if ($this->pset->gitless_grades)
            return false;
        if ($this->grade === false)
            $this->load_grade();
        if ($this->repo_grade)
            return $this->repo_grade->gradehash;
        return false;
    }

    function grading_commit() {
        if ($this->pset->gitless_grades)
            return false;
        if ($this->grade === false)
            $this->load_grade();
        if ($this->repo_grade)
            return $this->recent_commits($this->repo_grade->gradehash);
        return false;
    }

    function is_grading_commit() {
        if ($this->pset->gitless_grades)
            return true;
        if ($this->grade === false)
            $this->load_grade();
        return $this->commit
            && $this->repo_grade
            && $this->commit == $this->repo_grade->gradehash;
    }

    function gradercid() {
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


    function grading_info($key = null) {
        if ($this->grade === false)
            $this->load_grade();
        if (!$key)
            return $this->grade_notes;
        else
            return $this->grade_notes ? get($this->grade_notes, $key) : null;
    }

    function commit_or_grading_info() {
        if ($this->pset->gitless_grades || !$this->commit())
            return $this->grading_info();
        else
            return $this->commit_info();
    }

    function grading_info_empty() {
        if ($this->grade === false)
            $this->load_grade();
        if (!$this->grade_notes)
            return true;
        $gn = (array) $this->grade_notes;
        return !$gn || empty($gn)
            || (count($gn) == 1 && isset($gn["gradercid"]));
    }

    function grades_hidden() {
        if ($this->grade === false)
            $this->load_grade();
        return $this->grade && $this->grade->hidegrade;
    }

    function commit_or_grading_entry($k, $type = null) {
        $gn = $this->commit_or_grading_info();
        $grade = null;
        if ((!$type || $type == "autograde") && isset($gn->autogrades) && property_exists($gn->autogrades, $k))
            $grade = $gn->autogrades->$k;
        if ((!$type || $type == "grade") && isset($gn->grades) && property_exists($gn->grades, $k))
            $grade = $gn->grades->$k;
        return $grade;
    }

    function late_hours($no_auto = false) {
        $cinfo = $this->commit_or_grading_info();
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
        if ($this->pset->gitless_grades)
            Dbl::qe("insert into ContactGrade (cid,pset,gradercid) values (?, ?, ?) on duplicate key update gradercid=gradercid",
                    $this->user->contactId, $this->pset->psetid,
                    $this->viewer->contactId);
        else {
            assert(!!$this->commit);
            $grader = $this->commit_info("gradercid");
            if (!$grader)
                $grader = $this->grading_info("gradercid");
            Dbl::qe("insert into RepositoryGrade (repoid,pset,gradehash,gradercid,placeholder) values (?, ?, ?, ?, 0) on duplicate key update gradehash=values(gradehash), gradercid=values(gradercid), placeholder=0",
                    $this->repo->repoid, $this->pset->psetid,
                    $this->commit ? : null, $grader ? : null);
        }
        $this->grade = $this->repo_grade = false;
    }


    function hoturl_args($args = null) {
        $xargs = array("pset" => $this->pset->urlkey,
                       "u" => $this->viewer->user_linkpart($this->user));
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
        if (!$this->user_can_see_grades)
            echo " hidegrade61";
        if (!$open)
            echo '" style="display:none';
        echo '" data-pa-file="', htmlspecialchars($file), '" data-pa-fileid="', $fileid, "\"><tbody>\n";
        if ($this->pc_view)
            Ht::stash_script("jQuery('#$tabid').mousedown(linenote61).mouseup(linenote61)");
        foreach ($dinfo->diff as $l) {
            if ($l[0] == "@")
                $x = array(" gx", "difflctx61", "", "", $l[3]);
            else if ($l[0] == " ")
                $x = array(" gc", "difflc61", $l[1], $l[2], $l[3]);
            else if ($l[0] == "-")
                $x = array(" gd", "difflc61", $l[1], "", $l[3]);
            else
                $x = array(" gi", "difflc61", "", $l[2], $l[3]);

            $aln = $x[2] ? "a" . $x[2] : "";
            $bln = $x[3] ? "b" . $x[3] : "";

            $ak = $bk = "";
            if ($linenotes && $aln && isset($linenotes->$aln))
                $ak = ' id="L' . $aln . '_' . $fileid . '"';
            if ($bln)
                $bk = ' id="L' . $bln . '_' . $fileid . '"';

            if (!$x[2] && !$x[3])
                $x[2] = $x[3] = "...";

            echo '<tr class="diffl61', $x[0], '">',
                '<td class="difflna61"', $ak, '>', $x[2], '</td>',
                '<td class="difflnb61"', $bk, '>', $x[3], '</td>',
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
        if ($this->can_see_grades || $note[0]) {
            echo '<tr class="diffl61 gw">', /* NB script depends on this class */
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
        echo '<tr class="diffl61 gw iscomment61"',
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
        if ($this->user_can_see_grades)
            echo Ht::hidden("iscomment", 1);
        else
            echo Ht::checkbox("iscomment", 1), '&nbsp;', Ht::label("Show immediately");
        echo '</div></div></div></form></div></td></tr>';
    }
}
