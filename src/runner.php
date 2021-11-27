<?php
// runner.php -- Peteramati runner state
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class RunnerState {
    /** @var Conf */
    public $conf;
    /** @var PsetView */
    public $info;
    /** @var Repository */
    public $repo;
    /** @var ?int */
    public $repoid;
    /** @var Pset */
    public $pset;
    /** @var RunnerConfig */
    public $runner;
    /** @var ?RunLogger */
    public $runlog;
    /** @var ?int */
    public $checkt;
    /** @var ?int */
    public $queueid;

    /** @param ?int $checkt */
    function __construct(PsetView $info, RunnerConfig $runner, $checkt = null) {
        $this->conf = $info->conf;
        $this->info = $info;
        $this->repo = $info->repo;
        $this->repoid = $this->repo ? $this->repo->repoid : null;
        $this->pset = $info->pset;
        $this->runner = $runner;
        assert(!$runner->command || $this->repoid);

        if ($this->repo && !$this->pset->gitless) {
            $this->runlog = new RunLogger($this->pset, $this->repo);
            $logdir = $this->runlog->log_dir();
            if (!is_dir($logdir)) {
                $old_umask = umask(0);
                if (!mkdir($logdir, 02770, true)) {
                    throw new RunnerException("Cannot create log directory");
                }
                umask($old_umask);
            }
        }

        if ($checkt) {
            $this->set_checkt($checkt);
        }
    }


    /** @param int|string $checkt
     * @return bool */
    function set_checkt($checkt) {
        if (is_int($checkt) && $checkt > 0) {
            $this->checkt = $checkt;
        } else if (ctype_digit($checkt)) {
            $this->checkt = intval($checkt);
        } else if (preg_match('/\.(\d+)\.log(?:\.lock|\.pid)?\z/', (string) $checkt, $m)) {
            $this->checkt = intval($m[1]);
        } else {
            $this->checkt = null;
        }
        return $this->checkt !== null;
    }

    /** @param int|string $queueid
     * @return bool */
    function set_queueid($queueid) {
        if (is_int($queueid) && $queueid > 0) {
            $this->queueid = $queueid;
        } else if (ctype_digit($queueid)) {
            $this->queueid = intval($queueid);
        } else {
            $this->queueid = null;
        }
        return $this->queueid !== null;
    }


    /** @return ?QueueItem */
    private function load_queue() {
        if ($this->queueid === null) {
            return null;
        }
        $result = $this->conf->qe("select q.*,
                count(fq.queueid) nahead,
                min(if(fq.runat>0,fq.runat," . Conf::$now . ")) as head_runat,
                min(fq.nconcurrent) as ahead_nconcurrent
            from ExecutionQueue q
            left join ExecutionQueue fq on (fq.queueclass=q.queueclass and fq.queueid<q.queueid)
            where q.queueid={$this->queueid} group by q.queueid");
        $queue = QueueItem::fetch($this->conf, $result);
        Dbl::free($result);
        if ($queue && $queue->repoid === $this->repoid) {
            return $queue;
        } else {
            return null;
        }
    }

    private function clean_queue($qconf) {
        assert($this->queueid !== null);
        $runtimeout = isset($qconf->runtimeout) ? $qconf->runtimeout : 300;
        $result = $this->conf->qe("select * from ExecutionQueue where queueclass=? and queueid<?", $this->runner->queue ?? "", $this->queueid);
        while (($row = QueueItem::fetch($this->conf, $result))) {
            // remove dead items from queue
            // - pidfile contains "0\n": child has exited, remove it
            // - pidfile specified but not there
            // - no pidfile & last update < 30sec ago
            // - running for more than 5min (configurable)
            if (($row->runat > 0
                 && $row->lockfile
                 && $this->runlog->active_job_at($row->lockfile) != $row->runat)
                || ($row->runat <= 0
                    && $row->updateat < Conf::$now - 30)
                || ($runtimeout
                    && $row->runat > 0
                    && $row->runat < Conf::$now - $runtimeout)) {
                $this->conf->qe("delete from ExecutionQueue where queueid=?", $row->queueid);
            }
        }
        Dbl::free($result);
    }

    /** @return ?QueueItem */
    function make_queue() {
        $qname = $this->runner->queue ?? "";

        if ($this->queueid === null) {
            $nconcurrent = null;
            if (isset($this->runner->nconcurrent)
                && $this->runner->nconcurrent > 0) {
                $nconcurrent = $this->runner->nconcurrent;
            }
            $runsettings = $this->info->commit_jnote("runsettings");
            $runsettingsj = $runsettings ? json_encode_db($runsettings) : null;
            assert($runsettingsj === null || strlen($runsettingsj) < 8000);
            $this->conf->qe("insert into ExecutionQueue set reqcid=?,
                    runnername=?, cid=?, psetid=?, repoid=?, bhash=?,
                    queueclass=?, nconcurrent=?,
                    insertat=?, updateat=?, runat=0, status=0,
                    runsettings=?",
                    $this->info->viewer->contactId,
                    $this->runner->name, $this->info->user->contactId, $this->pset->id,
                    $this->repoid, hex2bin($this->info->commit_hash()),
                    $qname, $nconcurrent,
                    Conf::$now, Conf::$now,
                    $runsettingsj);
            $this->queueid = $this->conf->dblink->insert_id;
        } else {
            $this->conf->qe("update ExecutionQueue set updateat=? where queueid=?",
                    Conf::$now, $this->queueid);
        }
        $queue = $this->load_queue();

        $qconf = null;
        if ($qname !== "" && isset($this->conf->config->_queues->{$qname})) {
            $qconf = $this->conf->config->_queues->{$qname};
        }
        if (!$qconf) {
            $qconf = (object) ["nconcurrent" => 1];
        }
        $nconcurrent = $qconf->nconcurrent ?? 1000;
        if ($this->runner->nconcurrent > 0
            && $this->runner->nconcurrent < $nconcurrent) {
            $nconcurrent = $this->runner->nconcurrent;
        }
        if (($queue->ahead_nconcurrent ?? 0) > 0
            && $queue->ahead_nconcurrent < $nconcurrent) {
            $nconcurrent = $queue->ahead_nconcurrent;
        }

        for ($tries = 0; $tries < 2; ++$tries) {
            // error_log($User->seascode_username . ": $Queue->queueid, $nconcurrent, $Queue->nahead, $Queue->ahead_nconcurrent");
            if ($nconcurrent > 0 && $queue->nahead >= $nconcurrent) {
                if ($tries) {
                    $queue->runnable = false;
                    return $queue;
                }
                $this->clean_queue($qconf);
                $queue = $this->load_queue();
            } else {
                break;
            }
        }

        // if we get here we can actually run
        $queue->runnable = true;
        return $queue;
    }


    function evaluate($answer) {
        if (isset($this->runner->require)) {
            if ($this->runner->require[0] === "/") {
                require_once($this->runner->require);
            } else {
                require_once(SiteLoader::$root . "/" . $this->runner->require);
            }
        }
        $answer->result = call_user_func($this->runner->eval, $this->info);
    }


    /** @param Qrequest $qreq
     * @return object */
    function check($qreq) {
        // recent or checkup
        if ($qreq->check === "recent") {
            $checkt = 0;
            $n = 0;
            $envts = $this->runner->environment_timestamp();
            foreach ($this->runlog->past_jobs() as $t) {
                if ($t > $envts
                    && ($s = $this->runlog->job_info($t))
                    && $s->runner === $this->runner->name
                    && $s->hash === $this->info->commit_hash()
                    && $this->runlog->active_job() !== $t) {
                    $checkt = $t;
                    break;
                } else if ($n >= 200) {
                    break;
                }
                ++$n;
            }
            if (!$checkt) {
                return (object) ["ok" => false, "run_empty" => true, "error" => "No logs yet", "error_html" => "No logs yet"];
            }
        } else {
            $checkt = cvtint($qreq->check);
            if ($checkt <= 0) {
                return (object) ["ok" => false, "error" => "Invalid “check” argument", "error_html" => "Invalid “check” argument"];
            }
        }
        $this->set_checkt($checkt);

        $offset = cvtint($qreq->offset, 0);
        $rct = $this->runlog->active_job();
        if (($rct == $this->checkt && ($qreq->stop ?? "") !== "" && $qreq->stop !== "0")
            || ($rct == $this->checkt && ($qreq->write ?? "") !== "")) {
            if (($qreq->write ?? "") !== "") {
                $this->runlog->job_write($this->checkt, $qreq->write);
            }
            if ($qreq->stop) {
                // "ESC Ctrl-C" is captured by pa-jail
                $this->runlog->job_write($this->checkt, "\x1b\x03");
            }
            $now = microtime(true);
            do {
                usleep(10);
                $answer = $this->runlog->job_response($this->runner, $this->checkt, $offset);
            } while ($qreq->stop
                     && ($rct = $this->runlog->active_job()) == $this->checkt
                     && microtime(true) - $now < 0.1);
        } else {
            $answer = $this->runlog->job_response($this->runner, $this->checkt, $offset);
        }

        if ($answer->status !== "working" && $this->queueid > 0) {
            $this->conf->qe("delete from ExecutionQueue where queueid=? and repoid=?", $this->queueid, $this->repoid);
        }
        if ($answer->status === "done") {
            $viewer = $this->info->viewer;
            if ($viewer->can_run($this->pset, $this->runner, $this->info->user)
                && $this->runner->eval) {
                $this->evaluate($answer);
            }
        }

        return $answer;
    }
}
