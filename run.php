<?php
// run.php -- Peteramati runner page
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty()) {
    $Me->escape();
}

class RunRequest {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var Pset */
    public $pset;
    /** @var RunnerConfig */
    public $runner;
    /** @var bool */
    public $if_needed;
    /** @var ?PsetView */
    private $info;

    /** @param string $err
     * @return array */
    static function error($err = null) {
        return ["ok" => false, "error" => $err];
    }

    /** @param ?string $err
     * @param ?array<string,mixed> $js
     * @return never */
    static function quit($err = null, $js = null) {
        json_exit(["ok" => false, "error" => $err] + ($js ?? []));
    }

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->user = $this->viewer = $viewer;
        $this->qreq = $qreq;
        if ($qreq->u !== null
            && !($this->user = ContactView::prepare_user($qreq, $viewer))) {
            json_exit(["ok" => false]);
        }
        assert($this->user === $this->viewer || $this->viewer->isPC);
        $this->pset = ContactView::find_pset_redirect($qreq->pset, $viewer);
        $this->runner = $this->pset->runner_by_key($qreq->run);
        if (!$this->runner
            || (!$this->viewer->isPC && !$this->runner->visible)) {
            self::quit("No such command");
        }

        $this->if_needed = str_ends_with($qreq->run, ".ifneeded") || $qreq->ifneeded;
    }

    static function go(Contact $user, Qrequest $qreq) {
        $rreq = new RunRequest($user, $qreq);
        if (friendly_boolean($qreq->runmany)) {
            $rreq->runmany();
        } else if (friendly_boolean($qreq->download)) {
            $rreq->download();
        } else {
            json_exit($rreq->run());
        }
    }

    /** @param bool $many
     * @return ?string */
    private function check_view($many) {
        if (!$this->viewer->can_view_run($this->pset, $this->runner, $many ? null : $this->user)) {
            if (!$this->viewer->isPC && !$this->runner->visible) {
                return "No such command";
            } else if ($this->runner->disabled) {
                return "Command disabled";
            } else {
                return "Can’t run command now";
            }
        } else {
            return null;
        }
    }

    /** @param ?string $s
     * @param string $param
     * @return ?int */
    static private function check_int_param($s, $param) {
        if ($s === null || $s === "") {
            return null;
        } else if (ctype_digit($s)) {
            return intval($s);
        }
        self::quit("Invalid {$param} parameter");
    }

    function run() {
        $qreq = $this->qreq;
        if ($qreq->run === null || !$qreq->valid_post()) {
            self::quit("Permission error");
        } else if (($err = $this->check_view(false))) {
            self::quit($err);
        }

        // parse parameters
        $queueid = self::check_int_param($qreq->queueid, "queueid");
        $check = self::check_int_param($qreq->check, "check");
        $offset = self::check_int_param($qreq->offset, "offset");
        $info = PsetView::make($this->pset, $this->user, $this->viewer, $qreq->newcommit ?? $qreq->commit);

        // can we run this?
        if ($check !== null && !$this->runner->evaluate_function
            ? !$this->viewer->can_view_run($this->pset, $this->runner, $this->user)
            : !$this->viewer->can_run($this->pset, $this->runner, $this->user)) {
            return self::error("You can’t run that command");
        }
        if ($this->runner->command
            && $info->repo
            && !$info->can_view_repo_contents()) {
            return self::error("You can’t view this repository");
        }

        // load queue item
        $qi = null;
        if ($queueid !== null && $check !== null) {
            $qi = QueueItem::by_id($this->conf, $queueid, $info);
        }
        if (!$qi && $check !== null) {
            $rr = $info->run_logger()->job_response($check);
            if (!$rr
                || ($rr->pset !== $info->pset->urlkey
                    && $this->conf->pset_by_key($rr->pset) !== $info->pset)
                || $rr->runner !== $this->runner->name) {
                return self::error("Unknown check timestamp {$check}");
            }
            $qi = QueueItem::for_run_response($info, $rr);
        }
        if (!$qi && $queueid !== null) {
            if (!($qi = QueueItem::by_id($this->conf, $queueid, $info))) {
                return self::error("Unknown queueid {$queueid}");
            }
        }
        if (!$qi && $this->if_needed) {
            $qi = QueueItem::for_complete_job($info, $this->runner);
        }

        // complain if unrunnable
        if ($this->runner->command
            && (!$info->repo || !$info->commit())) {
            if ($qi) {
                $qi->cancel();
            }
            if (!$info->repo) {
                return self::error("No repository");
            } else if ($qreq->newcommit ?? $qreq->commit) {
                return self::error("Commit " . ($qreq->newcommit ?? $qreq->commit) . " isn’t connected to this repository");
            } else {
                return self::error("No commits in repository");
            }
        }

        // check with existing queue item
        if ($qi) {
            if ($qi->psetid !== $info->pset->id
                || $qi->repoid !== ($info->repo ? $info->repo->repoid : 0)
                || $qi->runnername !== $this->runner->name) {
                return self::error("Wrong runner");
            }
            if ($qi->has_response()) {
                return $qi->full_response($offset ?? 0, $qreq->write ?? "", !!$qreq->stop);
            }
        }

        // maybe evaluate
        if (!$this->runner->command) {
            $qi = $qi ?? QueueItem::make_info($info, $this->runner);
            $qi->step(new QueueState);
            return $qi->full_response();
        }

        // otherwise check runnability and enqueue
        if (!$qi) {
            if (!$this->pset->run_dirpattern) {
                return self::error("Configuration error (run_dirpattern)");
            } else if (!$this->pset->run_jailfiles) {
                return self::error("Configuration error (run_jailfiles)");
            } else if (!$info->repo || !$info->pset) {
                return self::error("Nothing to do");
            }
            $qi = QueueItem::make_info($info, $this->runner);
            if ($this->viewer->privChair) {
                $qi->flags |= QueueItem::FLAG_UNWATCHED;
            }
            if ($this->if_needed) {
                $qi->ifneeded = 1;
            }
            $qi->schedule(100, $this->viewer->contactId);
        } else {
            $qi->update();
        }
        assert($qi->scheduled() && $qi->runat === 0 && $qi->runorder > 0);
        session_write_close();

        // process queue
        $qs = new QueueState;
        $result = $info->conf->qe("select * from ExecutionQueue
                where (status>=? and status<? and runorder<=?) or queueid=?
                order by runorder asc, queueid asc limit 500",
            QueueItem::STATUS_SCHEDULED, QueueItem::STATUS_CANCELLED,
            $qi->runorder, $qi->queueid);
        $qs = QueueState::fetch_list($info->conf, $result);
        $nahead = 0;
        $is_ahead = true;
        while (($qix = $qs->shift())) {
            if ($qix->queueid === $qi->queueid) {
                $qix = $qi;
                $is_ahead = false;
            } else if ($is_ahead) {
                ++$nahead;
            }
            if (!$qix->step($qs)) {
                if ($qix === $qi) {
                    return self::error($qix->last_error);
                } else {
                    error_log($qix->unparse_key() . ": " . $qix->last_error);
                }
            } else if ($qix === $qi && $qi->has_response()) {
                return $qi->full_response();
            }
        }
        return [
            "queueid" => $qi->queueid,
            "onqueue" => true,
            "nahead" => $nahead
        ];
    }

    function runmany_chain() {
        if ($this->pset->has_xterm_js) {
            $this->conf->add_stylesheet("stylesheets/xterm.css");
            $this->conf->add_javascript("scripts/xterm.js");
        }

        $t = $this->pset->title;
        if ($this->if_needed) {
            $t .= " (if needed)";
        }
        $t .= " {$this->runner->title}";
        $this->conf->header(htmlspecialchars($t), "home");

        echo '<h2 id="pa-runmany-who"></h2>',
            Ht::form($this->conf->hoturl("=run"), ["id" => "pa-runmany-form"]),
            '<div class="f-contain">',
            Ht::hidden("u", ""),
            Ht::hidden("pset", $this->pset->urlkey),
            Ht::hidden("commit", ""),
            Ht::hidden("jobs", "", ["disabled" => 1]);
        if ($this->if_needed) {
            echo Ht::hidden("ifneeded", 1);
        }
        echo Ht::hidden("run", $this->runner->name, ["id" => "pa-runmany"]),
            '</div></form>';

        echo '<div id="run-', $this->runner->name, '">',
            '<div class="pa-run pa-run-short"',
            $this->runner->div_attributes($this->pset), '>',
            '<pre class="pa-runpre"></pre></div>',
            '</div>';

        if (($chain = QueueItem::parse_chain($this->qreq->chain)) !== false) {
            Ht::stash_script("\$pa.runmany({$chain})");
        } else {
            $this->conf->error_msg("Invalid chain");
        }

        echo "<hr class=\"c\">\n";
        $this->conf->footer();
        exit(0);
    }

    function runmany() {
        if (!$this->viewer->isPC) {
            self::quit("Command reserved for TFs");
        } else if (($err = $this->check_view(true))) {
            self::quit($err);
        }

        if (($this->qreq->chain ?? "") !== "") {
            return $this->runmany_chain();
        }

        if (!$this->qreq->valid_post()) {
            self::quit("Session out of date");
        }

        $users = [];
        foreach ($this->qreq as $k => $v) {
            if (substr($k, 0, 2) === "s:"
                && $v
                && ($uname = urldecode(substr($k, 2)))) {
                $users[] = $uname;
            }
        }
        if (empty($users) && ($this->qreq->slist ?? $this->qreq->users)) {
            $users = preg_split('/\s+/', $this->qreq->slist ?? $this->qreq->users, -1, PREG_SPLIT_NO_EMPTY);
        }
        $runorder = QueueItem::unscheduled_runorder();
        $chain = QueueItem::new_chain();
        $commitq = PsetView::parse_commit_query($this->qreq->commitq);
        foreach ($users as $uname) {
            if (!($u = $this->conf->user_by_whatever($uname))) {
                continue;
            }
            $info = PsetView::make($this->pset, $u, $this->viewer);
            if ($this->runner->command && !$info->repo) {
                continue;
            }
            if ($commitq && !$info->select_commit($commitq)) {
                continue;
            }
            $qi = QueueItem::make_info($info, $this->runner);
            $qi->chain = $chain;
            $qi->runorder = $runorder;
            $qi->flags |= QueueItem::FLAG_UNWATCHED;
            if ($this->if_needed) {
                $qi->ifneeded = 1;
            }
            $qi->enqueue();
            $runorder += 10;
        }
        $this->conf->redirect_hoturl("run", ["pset" => $this->pset->urlkey, "run" => $this->runner->name, "runmany" => 1, "chain" => $chain]);
    }

    function download() {
        $qreq = $this->qreq;
        if ($qreq->run === null || !$qreq->valid_token()) {
            return self::error("Permission error");
        } else if (($err = $this->check_view(true))) {
            return self::error($err);
        } else if (!$qreq->jobs
                   || !($jobs = json_decode($qreq->jobs))
                   || !is_array($jobs)) {
            return self::error("Bad `jobs` parameter");
        }

        $boundary = "--peteramati-" . base64url_encode(random_bytes(24));

        $mf = fopen("php://temp", "w+b");
        foreach ($jobs as $i => $run) {
            if (!is_object($run)
                || !is_string($run->u ?? null)
                || (!is_string($run->pset ?? null) && !is_int($run->pset ?? null))
                || !is_string($run->run ?? null)
                || !is_int($run->timestamp ?? null)) {
                return self::error("Bad `jobs[{$i}]` parameter");
            }
            $pset = $this->conf->pset_by_key($run->pset);
            if (!$pset) {
                return self::error("Bad `jobs[{$i}].pset` parameter");
            }
            $user = ContactView::find_user($run->u, $this->viewer, $pset->anonymous);
            if (!$user) {
                return self::error("No such user `jobs[{$i}].u` ({$run->u})");
            }
            $runner = $pset->runner_by_key($run->run);
            if (!$runner) {
                return self::error("No such runner `jobs[{$i}].run` ({$run->run})");
            }
            if (!$this->viewer->can_view_run($pset, $runner, $user)) {
                fwrite($mf, "{$boundary}\n" . json_encode($run) . ": Cannot view\n");
                continue;
            }
            $repo = $user->repo($pset->id);
            $rr = (new RunLogger($pset, $repo))->job_response($run->timestamp, 0, 100 << 20);
            if (!$rr) {
                fwrite($mf, "{$boundary}\n" . json_encode($run) . ": No such job\n");
            } else {
                fwrite($mf, "{$boundary}\n" . json_encode($run) . "\n");
                fwrite($mf, $rr->data);
            }
            $rr = null;
        }
        fwrite($mf, "{$boundary}--\n");

        header("Content-Type: text/plain");
        header("Content-Disposition: attachment; filename=" . mime_quote_string($this->conf->download_prefix . $pset->urlkey . "-" . $this->runner->name . ".out"));
        rewind($mf);
        fpassthru($mf);
        fclose($mf);
    }
}


ContactView::set_path_request($Qreq, ["/@", "/@/p", "/@/p/h", "/p", "/p/h", "/p/u/h"], $Conf);
RunRequest::go($Me, $Qreq);
