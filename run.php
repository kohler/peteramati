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
    public $is_ensure;

    /** @param string $err
     * @return array */
    static function error($err = null) {
        return ["ok" => false, "error" => htmlspecialchars($err), "error_text" => $err];
    }

    static function quit($err = null, $js = null) {
        json_exit(["ok" => false, "error" => htmlspecialchars($err), "error_text" => $err] + ($js ?? []));
    }

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->user = $this->viewer = $viewer;
        $this->qreq = $qreq;
        if ($qreq->u !== null
            && !($this->user = ContactView::prepare_user($qreq->u, $viewer))) {
            json_exit(["ok" => false]);
        }
        assert($this->user === $this->viewer || $this->viewer->isPC);
        $this->pset = ContactView::find_pset_redirect($qreq->pset, $viewer);

        foreach ($this->pset->runners as $r) {
            if ($qreq->run === $r->name) {
                $this->runner = $r;
                $this->is_ensure = !!$qreq->ensure;
                break;
            } else if ($qreq->run === "{$r->name}.ensure") {
                $this->runner = $r;
                $this->is_ensure = true;
            }
        }
        if (!$this->runner
            || (!$this->viewer->isPC && !$this->runner->visible)) {
            self::quit("No such command.");
        }
    }

    static function go(Contact $user, Qrequest $qreq) {
        $rreq = new RunRequest($user, $qreq);
        if ($qreq->runmany) {
            $rreq->runmany();
        } else {
            json_exit($rreq->run());
        }
    }

    /** @param bool $many
     * @return ?string */
    private function check_view($many) {
        if (!$this->viewer->can_view_run($this->pset, $this->runner, $many ? null : $this->user)) {
            if (!$this->viewer->isPC && !$this->runner->visible) {
                return "No such command.";
            } else if ($this->runner->disabled) {
                return "Command disabled.";
            } else {
                return "Can’t run command now.";
            }
        } else {
            return null;
        }
    }

    function run() {
        $qreq = $this->qreq;
        if ($qreq->run === null || !$qreq->valid_post()) {
            return self::error("Permission error.");
        } else if (($err = $this->check_view(false))) {
            return self::error($err);
        }

        $info = PsetView::make($this->pset, $this->user, $this->viewer, $qreq->newcommit ?? $qreq->commit);
        if ($qreq->queueid === "") {
            unset($qreq->queueid);
        } else if (isset($qreq->queueid) && !ctype_digit($qreq->queueid)) {
            return self::error("Bad queueid.");
        }
        if ($qreq->check === "") {
            unset($qreq->check);
        } else if (isset($qreq->check) && !ctype_digit($qreq->check)) {
            return self::error("Bad check timestamp.");
        }

        // can we run this?
        if (isset($qreq->check) && !$this->runner->evaluate_function
            ? !$this->viewer->can_view_run($this->pset, $this->runner, $this->user)
            : !$this->viewer->can_run($this->pset, $this->runner, $this->user)) {
            return self::error("You can’t run that command.");
        }
        if ($this->runner->command
            && $info->repo
            && !$info->can_view_repo_contents()) {
            return self::error("You can’t view this repository.");
        }

        // load queue item
        $qi = null;
        if (isset($qreq->queueid) && isset($qreq->check)) {
            $qi = QueueItem::by_id($this->conf, intval($qreq->queueid), $info);
        }
        if (!$qi && isset($qreq->check)) {
            if (!($qi = QueueItem::for_logged_job($info, intval($qreq->check)))) {
                return self::error("Unknown check timestamp {$qreq->check}.");
            }
        }
        if (!$qi && isset($qreq->queueid)) {
            if (!($qi = QueueItem::by_id($this->conf, intval($qreq->queueid), $info))) {
                return self::error("Unknown queueid {$qreq->queueid}.");
            }
        }
        if (!$qi && $this->is_ensure) {
            $qi = QueueItem::for_complete_job($info, $this->runner);
        }

        // complain if unrunnable
        if ($this->runner->command
            && (!$info->repo || !$info->commit())) {
            $qi && $qi->delete(false);
            if (!$info->repo) {
                return self::error("No repository.");
            } else if ($qreq->newcommit ?? $qreq->commit) {
                return self::error("Commit " . ($qreq->newcommit ?? $qreq->commit) . " isn’t connected to this repository.");
            } else {
                return self::error("No commits in repository.");
            }
        }

        // check with existing queue item
        if ($qi) {
            if ($qi->psetid !== $info->pset->id
                || $qi->repoid !== ($info->repo ? $info->repo->repoid : 0)
                || $qi->runnername !== $this->runner->name) {
                return self::error("Wrong runner.");
            }
            if (!$qi->substantiate(new QueueStatus)) {
                return self::error($qi->last_error);
            } else if ($qi->runat) {
                return $qi->full_response(cvtint($qreq->offset, 0), $qreq->write ?? "", !!$qreq->stop);
            }
        }

        // maybe evaluate
        if (!$this->runner->command) {
            assert(!!$this->runner->evaluate_function);
            $rr = RunResponse::make($this->runner, $info->repo);
            $rr->timestamp = time();
            $rr->done = true;
            $rr->status = "done";
            $rr->result = $info->runner_evaluate($this->runner, $rr->timestamp);
            return $rr;
        }

        // otherwise check runnability and enqueue
        if (!$qi) {
            if (!$this->pset->run_dirpattern) {
                return self::error("Configuration error (run_dirpattern).");
            } else if (!$this->pset->run_jailfiles) {
                return self::error("Configuration error (run_jailfiles).");
            } else if (!$info->repo || !$info->pset) {
                return self::error("Nothing to do.");
            }
            $qi = QueueItem::make_info($info, $this->runner);
            $qi->flags |= ($this->viewer->privChair ? QueueItem::FLAG_UNWATCHED : 0)
                | ($this->is_ensure ? QueueItem::FLAG_ENSURE : 0);
            $qi->enqueue();
            $qi->schedule(100);
        } else {
            $qi->update();
        }
        assert($qi->status === 0 && $qi->runat === 0 && $qi->runorder > 0);
        session_write_close();

        // process queue
        $qs = new QueueStatus;
        $result = $info->conf->qe("select * from ExecutionQueue where status>=0 and runorder<=? order by runorder asc, queueid asc limit 100", $qi->runorder);
        while (($qix = QueueItem::fetch($info->conf, $result))) {
            $qix = $qix->queueid === $qi->queueid ? $qi : $qix;
            if (!$qix->substantiate($qs)) {
                if ($qix === $qi) {
                    return self::error($qix->last_error);
                } else {
                    error_log($qix->unparse_key() . ": " . $qix->last_error);
                }
            } else if ($qix === $qi && $qi->status > 0) {
                return $qi->full_response();
            }
        }
        Dbl::free($result);
        return [
            "queueid" => $qi->queueid,
            "onqueue" => true,
            "nahead" => $qs->nahead
        ];
    }

    function runmany() {
        if (!$this->viewer->isPC) {
            self::quit("Command reserved for TFs.");
        } else if (($err = $this->check_view(true))) {
            self::quit($err);
        } else if (isset($this->qreq->chain) && ctype_digit($this->qreq->chain)) {
            $t = $this->pset->title;
            if ($this->is_ensure) {
                $t .= " Ensure";
            }
            $t .= " {$this->runner->title}";
            $this->conf->header(htmlspecialchars($t), "home");

            echo '<h2 id="pa-runmany-who"></h2>',
                Ht::form($this->conf->hoturl("=run"), ["id" => "pa-runmany-form"]),
                '<div class="f-contain">',
                Ht::hidden("u", ""),
                Ht::hidden("pset", $this->pset->urlkey);
            if ($this->is_ensure) {
                echo Ht::hidden("ensure", 1);
            }
            echo Ht::hidden("run", $this->runner->name, ["id" => "pa-runmany"]),
                '</div></form>';

            echo '<div id="run-', $this->runner->name, '">',
                '<div class="pa-run pa-run-short" id="pa-run-', $this->runner->name, '">',
                '<pre class="pa-runpre"></pre></div>',
                '</div>';

            Ht::stash_script("\$pa.runmany({$this->qreq->chain})");
            echo "<hr class=\"c\">\n";
            $this->conf->footer();
        } else if (!$this->qreq->valid_post()) {
            self::quit("Session out of date.");
        } else {
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
            $nu = 0;
            $chain = QueueItem::new_chain();
            foreach ($users as $uname) {
                if (($u = $this->conf->user_by_whatever($uname))) {
                    $info = PsetView::make($this->pset, $u, $this->viewer);
                    if (!$this->runner->command || $info->repo) {
                        $qi = QueueItem::make_info($info, $this->runner);
                        $qi->chain = $chain;
                        $qi->runorder = QueueItem::unscheduled_runorder($nu * 10);
                        $qi->flags |= QueueItem::FLAG_UNWATCHED
                            | ($this->is_ensure ? QueueItem::FLAG_ENSURE : 0);
                        $qi->enqueue();
                        ++$nu;
                    }
                }
            }
            Navigation::redirect($this->conf->hoturl("run", ["pset" => $this->pset->urlkey, "run" => $this->runner->name, "runmany" => 1, "chain" => $chain], Conf::HOTURL_RAW));
        }
    }
}


ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/p", "/p/h", "/p/u/h"));
RunRequest::go($Me, $Qreq);
