<?php
// diff.php -- Peteramati diff page
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty()) {
    $Me->escape();
}

class Diff_Page {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var Qrequest
     * @readonly */
    public $qreq;
    /** @var Pset
     * @readonly */
    public $pset;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var Contact
     * @readonly */
    public $user1;
    /** @var PsetView
     * @readonly */
    public $info;
    /** @var PsetView
     * @readonly */
    public $info1;
    /** @var CommitRecord
     * @readonly */
    public $commit;
    /** @var CommitRecord
     * @readonly */
    public $commit1;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;

        // parse path request
        $path = $qreq->split_path(true);
        $npath = count($path);
        $pi = $nu = 0;
        if ($pi < $npath
            && ($up = CommitRecord::parse_userpart($path[$pi], true))) {
            $qreq->u = $qreq->u ?? $up;
            ++$pi;
            ++$nu;
        }
        if ($pi < $npath && $this->conf->pset_by_key($path[$pi])) {
            $qreq->pset = $qreq->pset ?? $path[$pi];
            ++$pi;
        } else {
            $pi = $npath;
        }
        if ($pi < $npath
            && ($up = CommitRecord::parse_userpart($path[$pi], true))
            && $nu === 0) {
            $qreq->u = $qreq->u ?? $up;
            ++$pi;
        }
        if ($pi < $npath
            && ($hp = CommitRecord::parse_hashpart($path[$pi], false))) {
            $qreq->commit = $qreq->commit ?? $hp;
            ++$pi;
        }
        if ($pi < $npath
            && ($up = CommitRecord::parse_userpart($path[$pi], true))) {
            $qreq->u1 = $qreq->u1 ?? $up;
            ++$pi;
        }
        if ($pi < $npath
            && ($hp = CommitRecord::parse_hashpart($path[$pi], false))) {
            $qreq->commit1 = $qreq->commit1 ?? $hp;
            ++$pi;
        }

        // parse pset part
        if (!isset($qreq->pset)
            || !($this->pset = $this->conf->pset_by_key($qreq->pset))) {
            $this->error_exit(404, "Pset not found");
        }
        if ($this->pset->gitless) {
            $this->error_exit(400, "Problem set does not support diff");
        }

        // parse user parts
        if (!isset($qreq->u) || $qreq->u === "") {
            $qreq->u = "me";
        }
        if (!isset($qreq->u1) || $qreq->u1 === "") {
            $qreq->u1 = $qreq->u;
        }
        if (!$this->viewer->isPC
            && (!$this->viewer->matches_key($qreq->u) || !$this->viewer->matches_key($qreq->u1))) {
            $this->error_exit(404, "User not found");
        }
        if ($qreq->u === "me") {
            $this->user = $this->viewer;
        } else {
            $this->user = $this->conf->user_by_whatever($qreq->u);
        }
        if (!$this->user) {
            $this->error_exit(404, "User not found");
        }
        if ($qreq->u1 === "me") {
            $this->user1 = $this->viewer;
        } else if ($qreq->u1 === $qreq->u) {
            $this->user1 = $this->user;
        } else {
            $this->user1 = $this->conf->user_by_whatever($qreq->u1);
        }
        if (!$this->user1) {
            $this->error_exit(404, "User not found");
        }

        // create pset views
        $this->info = PsetView::make($this->pset, $this->user, $this->viewer);
        if ($this->user->contactId === $this->user1->contactId) {
            $this->info1 = $this->info;
        } else {
            $this->info1 = PsetView::make($this->pset, $this->user1, $this->viewer);
        }
        if (!$this->info->repo || !$this->info1->repo) {
            $this->error_exit(404, "Repository not found");
        }

        // maybe redirect
        if (!$this->qreq->commit || !$this->qreq->commit1) {
            if (!$this->qreq->commit1) {
                $this->qreq->commit1 = $this->info1->hash();
            }
            if (!$this->qreq->commit) {
                $this->qreq->commit = $this->info->derived_handout_hash();
            }
            if ($this->qreq->commit && $this->qreq->commit1) {
                $this->conf->redirect_self($this->qreq);
            }
            $this->error_exit(400, "Commit missing");
        }

        // find commits
        $this->commit = $this->info->find_commit($qreq->commit);
        if (!$this->commit) {
            $repoid = $this->user->contactId === $this->viewer->contactId ? "your" : $this->viewer->user_linkpart($this->user) . "’s";
            $this->error_exit(400, "Commit " . htmlspecialchars($qreq->commit) . " is not connected to " . htmlspecialchars($repoid) . " repository");
        }
        $this->commit1 = $this->info1->find_commit($qreq->commit1);
        if (!$this->commit1) {
            $repoid = $this->user1->contactId === $this->viewer->contactId ? "your" : $this->viewer->user_linkpart($this->user1) . "’s";
            $this->error_exit(400, "Commit " . htmlspecialchars($qreq->commit1) . " is not connected to " . htmlspecialchars($repoid) . " repository");
        }
    }

    static function go(Contact $viewer, Qrequest $qreq) {
        $dp = new Diff_Page($viewer, $qreq);
        $dp->render();
    }

    private function error_exit($code, $msg) {
        http_response_code($code);
        if ($this->pset) {
            $t = htmlspecialchars($this->pset->title) . " diff";
        } else if ($this->qreq->pset) {
            $t = htmlspecialchars($this->qreq->pset) . " diff";
        } else {
            $t = "Diff";
        }
        $this->conf->header("<span class=\"pset-title\">{$t}</span>", "home");
        $this->conf->error_msg($msg);
        $this->conf->footer();
        exit(0);
    }

    private function _transfer_commit() {
        if ($this->info1->repo->resolve_commit($this->commit->hash)) {
            return;
        }
        // Create a pipe between `git pack-objects`, in the source repo,
        // and `git unpack-objects`, in the clone.
        $cmd = $this->conf->fix_command(["git", "pack-objects", "--delta-base-offset", "--revs", "--stdout", "-q"]);
        $packproc = proc_open($cmd,
            [0 => ["pipe", "r"], 1 => ["pipe", "w"]],
            $packpipes,
            $this->info->repo->repodir(),
            Repository::git_env_vars());
        fwrite($packpipes[0], "{$this->commit->hash}\n");
        fclose($packpipes[0]);

        $cmd = $this->conf->fix_command(["git", "unpack-objects", "-q"]);
        $unpackproc = proc_open($cmd,
            [0 => $packpipes[1], 1 => ["file", "/dev/null", "w"]],
            $unpackpipes,
            $this->info1->repo->repodir(),
            Repository::git_env_vars());
        fclose($packpipes[1]);
        $status = proc_close($unpackproc);
        $status1 = proc_close($packproc);
    }

    function render() {
        $this->info1->set_hash($this->commit1->hash);
        if ($this->info !== $this->info1
            && $this->info->repo->cacheid !== $this->info1->repo->cacheid) {
            $this->_transfer_commit();
        }

        if ($this->commit->hash === $this->info->grading_hash()) {
            $this->commit->subject .= "  G⃝"; // space, nbsp
        }
        if ($this->commit1->hash === $this->info1->grading_hash()) {
            $this->commit1->subject .= "  G⃝"; // space, nbsp
        }

        $this->conf->header('<span class="pset-title">' . htmlspecialchars($this->pset->title) . ' diff</span>', "home");
        ContactView::echo_heading($this->user, $this->viewer);

        $infoj = $this->info1->info_json();
        $infoj["base_commit"] = $this->commit->hash;
        $infoj["base_handout"] = $this->commit->is_handout($this->pset);
        echo '<div class="pa-psetinfo" data-pa-pset="', htmlspecialchars($this->pset->urlkey),
            '" data-pa-base-commit="', htmlspecialchars($this->commit->hash),
            '" data-pa-commit="', htmlspecialchars($this->commit1->hash),
            '" data-pa-gradeinfo=\'', json_escape_browser_sqattr($infoj), '\'';
        if ($this->pset->directory) {
            echo ' data-pa-directory="', htmlspecialchars($this->pset->directory_slash), '"';
        }
        if ($this->user1->extension) {
            echo ' data-pa-user-extension="yes"';
        }
        echo '>';

        echo "<table class=\"mb-4\"><tr><td style=\"line-height:110%\">",
            "<div class=\"pa-dl pa-gdsamp\" style=\"padding:2px 5px\"><big>",
            $this->info->commit_link(substr($this->commit->hash, 0, 7), " " . htmlspecialchars($this->commit->subject), $this->commit->hash),
            "</big></div><div class=\"pa-dl pa-gisamp\" style=\"padding:2px 5px\"><big>",
            $this->info1->commit_link(substr($this->commit1->hash, 0, 7), " " . htmlspecialchars($this->commit1->subject), $this->commit1->hash),
            "</big></div></td></tr></table>";

        // collect diff and sort line notes
        $lnorder = $this->commit1->is_handout($this->pset) ? $this->info1->empty_line_notes() : $this->info1->visible_line_notes();

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

        $dctx = $this->info1->diff_context($this->commit, $this->commit1, $lnorder);
        $dctx->no_full = !$this->commit->is_handout($this->pset)
            || $this->commit1->is_handout($this->pset);
        $dctx->no_user_collapse = true;
        $diff = $this->info1->diff($dctx);
        if ($diff) {
            echo '<div class="pa-diffset">';
            // diff and line notes
            foreach ($diff as $file => $dinfo) {
                $this->info1->echo_file_diff($file, $dinfo, $lnorder, ["only_diff" => true]);
            }
            echo '</div>';
        }

        Ht::stash_script('$(window).on("beforeunload",$pa.beforeunload)');
        echo "</div><hr class=\"c\" />\n";
        $this->conf->footer();
    }
}

Diff_Page::go($Me, $Qreq);
