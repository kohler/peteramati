<?php
// queueitem.php -- Peteramati queue state
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class QueueItem {
    /** @var Conf */
    public $conf;

    /** @var int */
    public $queueid;
    /** @var int */
    public $reqcid;

    /** @var string */
    public $runnername;
    /** @var int */
    public $cid;
    /** @var int */
    public $psetid;
    /** @var int */
    public $repoid;
    /** @var ?string */
    public $bhash;
    /** @var ?array */
    public $runsettings;
    /** @var ?list<string> */
    public $tags;
    /** @var ?list */
    public $ensure;

    /** @var string */
    public $queueclass;
    /** @var ?int */
    public $nconcurrent;
    /** @var int */
    public $flags;
    /** @var ?int */
    public $chain;

    /** @var int */
    public $runorder;
    /** @var int */
    public $insertat;
    /** @var int */
    public $scheduleat;
    /** @var int */
    public $updateat;
    /** @var int */
    public $runat;
    /** @var int */
    private $status;
    /** @var ?string */
    public $lockfile;

    // object links
    /** @var ?Pset */
    private $_pset;
    /** @var ?Contact */
    private $_user;
    /** @var ?Repository */
    private $_repo;
    /** @var ?PsetView */
    private $_info;
    /** @var ?RunnerConfig */
    private $_runner;
    /** @var int */
    private $_ocache = 0;
    /** @var ?int */
    private $_evaluate_at;
    /** @var mixed */
    private $_evaluate;

    // running a command (`start` and helpers)
    /** @var string */
    private $_jaildir;
    /** @var string */
    private $_jailhomedir;
    /** @var string */
    private $_logfile;
    /** @var resource */
    private $_logstream;
    /** @var ?int */
    private $_runstatus;
    /** @var ?string */
    public $last_error;

    const FLAG_UNWATCHED = 1;
    const FLAG_ENSURE = 2;
    const FLAG_ANONYMOUS = 4;

    const STATUS_UNSCHEDULED = -1;
    const STATUS_SCHEDULED = 0;
    const STATUS_WORKING = 1;
    const STATUS_CANCELLED = 2;
    const STATUS_DONE = 3;
    const STATUS_EVALUATED = 4;


    private function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    private function db_load() {
        $this->queueid = (int) $this->queueid;
        $this->reqcid = (int) $this->reqcid;

        $this->cid = (int) $this->cid;
        $this->psetid = (int) $this->psetid;
        $this->repoid = (int) $this->repoid;

        if ($this->nconcurrent !== null) {
            $this->nconcurrent = (int) $this->nconcurrent;
        }
        $this->flags = (int) $this->flags;
        if ($this->chain !== null) {
            $this->chain = (int) $this->chain;
        }
        if ($this->runsettings !== null) {
            /** @phan-suppress-next-line PhanTypeMismatchArgumentInternal */
            $j = json_decode($this->runsettings);
            $this->runsettings = is_object($j) ? (array) $j : null;
        }
        if ($this->tags !== null) {
            $ts = [];
            /** @phan-suppress-next-line PhanTypeMismatchArgumentInternal */
            foreach (explode(" ", $this->tags) as $t) {
                if ($t !== "")
                    $ts[] = $t;
            }
            $this->tags = empty($ts) ? null : $ts;
        }
        if ($this->ensure !== null) {
            /** @phan-suppress-next-line PhanTypeMismatchArgument */
            $this->set_ensure($this->ensure);
        }

        $this->runorder = (int) $this->runorder;
        $this->insertat = (int) $this->insertat;
        $this->scheduleat = (int) $this->scheduleat;
        $this->updateat = (int) $this->updateat;
        $this->runat = (int) $this->runat;
        $this->status = (int) $this->status;
    }


    /** @return ?Pset */
    function pset() {
        if (($this->_ocache & 1) === 0) {
            $this->_ocache |= 1;
            $this->_pset = $this->conf->pset_by_id($this->psetid);
        }
        return $this->_pset;
    }

    /** @return ?Contact */
    function user() {
        if (($this->_ocache & 16) === 0) {
            $this->_ocache |= 16;
            $this->_user = $this->conf->user_by_id($this->cid);
            $this->_user->set_anonymous(($this->flags & self::FLAG_ANONYMOUS) !== 0);
        }
        return $this->_user;
    }

    /** @return ?Repository */
    function repo() {
        if (($this->_ocache & 2) === 0) {
            $this->_ocache |= 2;
            if ($this->repoid > 0) {
                $this->_repo = Repository::by_id($this->repoid, $this->conf);
            }
        }
        return $this->_repo;
    }

    /** @return ?PsetView */
    function info() {
        if (($this->_ocache & 4) === 0) {
            $this->_ocache |= 4;
            if (($p = $this->pset()) && ($u = $this->user())) {
                $this->_info = PsetView::make($p, $u, $u, $this->hash(), true);
            }
        }
        return $this->_info;
    }

    /** @return ?string */
    function hash() {
        return $this->bhash !== null ? bin2hex($this->bhash) : null;
    }

    /** @return ?RunnerConfig */
    function runner() {
        if (($this->_ocache & 8) === 0) {
            $this->_ocache |= 8;
            if (($p = $this->pset())) {
                $this->_runner = $p->runners[$this->runnername] ?? null;
            }
        }
        return $this->_runner;
    }

    /** @return string */
    function unparse_key() {
        $pset = $this->pset();
        $uname = $this->conf->cached_username_by_id($this->cid, ($this->flags & self::FLAG_ANONYMOUS) !== 0);
        $hash = $this->hash();
        return "~{$uname}/{$pset->urlkey}/{$hash}/{$this->runnername}";
    }

    /** @param ?string $s */
    private function set_ensure($s) {
        $j = $s !== null ? json_decode($s) : null;
        $this->ensure = is_array($j) ? $j : null;
    }

    /** @return int */
    function status() {
        return $this->status;
    }

    /** @return bool */
    function unscheduled() {
        return $this->status === self::STATUS_UNSCHEDULED;
    }

    /** @return bool */
    function scheduled() {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /** @return bool */
    function working() {
        return $this->status === self::STATUS_WORKING;
    }

    /** @return bool */
    function has_response() {
        return $this->status >= self::STATUS_WORKING;
    }

    /** @return bool */
    function abandoned() {
        return ($this->status === self::STATUS_UNSCHEDULED
                || $this->status === self::STATUS_SCHEDULED)
            && ($this->flags & self::FLAG_UNWATCHED) === 0
            && $this->updateat < Conf::$now - 180;
    }

    /** @return bool */
    function stopped() {
        return $this->status >= self::STATUS_CANCELLED;
    }

    /** @return mixed */
    private function evaluate() {
        assert($this->status >= self::STATUS_DONE);
        if ($this->_evaluate_at === null) {
            $this->_evaluate_at = $this->runat;
            if (($runner = $this->runner())
                && $runner->evaluate_function
                && ($info = $this->info())) {
                $this->_evaluate = $info->runner_evaluate($runner, $this->runat);
            }
        }
        if ($this->status === self::STATUS_DONE) {
            $this->swap_status(self::STATUS_EVALUATED);
        }
        return $this->_evaluate;
    }


    /** @param ?PsetView $info
     * @return ?QueueItem */
    static function fetch(Conf $conf, $result, $info = null) {
        $qi = $result ? $result->fetch_object("QueueItem", [$conf]) : null;
        if ($qi) {
            $qi->db_load();
            $info && $qi->associate_info($info);
        }
        return $qi;
    }

    /** @param int $queueid
     * @param ?PsetView $info
     * @return ?QueueItem */
    static function by_id(Conf $conf, $queueid, $info = null) {
        $conf->clean_queue();
        $result = $conf->qe("select * from ExecutionQueue where queueid=?", $queueid);
        return self::fetch($conf, $result, $info);
    }

    /** @param int $chain
     * @return ?QueueItem */
    static function by_chain(Conf $conf, $chain) {
        $conf->clean_queue();
        $result = $conf->qe("select * from ExecutionQueue where chain=? order by runorder asc, queueid asc limit 1", $chain);
        return self::fetch($conf, $result, null);
    }

    /** @return int */
    static function new_chain() {
        // make sure JS can represent chain as int
        return random_int(1, min(PHP_INT_MAX, (1 << 52) - 1));
    }

    /** @param int $chain
     * @return bool */
    static function valid_chain($chain) {
        return $chain >= 0 && $chain <= PHP_INT_MAX && $chain <= (1 << 52) - 1;
    }

    /** @param PsetView $info
     * @param ?RunnerConfig $runner
     * @return QueueItem */
    static function make_info($info, $runner = null) {
        $qi = new QueueItem($info->conf);
        $qi->queueid = 0;
        $qi->reqcid = $info->viewer->contactId;
        $qi->cid = $info->user->contactId;
        $qi->psetid = $info->pset->id;
        $qi->repoid = $info->repo ? $info->repo->repoid : 0;
        $qi->bhash = $info->bhash();
        if ($qi->bhash) {
            $qi->runsettings = (array) $info->commit_jnote("runsettings");
        }
        $qi->flags = $info->user->is_anonymous ? self::FLAG_ANONYMOUS : 0;

        $qi->queueclass = "";
        $qi->status = self::STATUS_UNSCHEDULED;
        if ($runner) {
            $qi->runnername = $runner->name;
            $qi->nconcurrent = 0;
            if ($runner->nconcurrent !== null) {
                $qi->nconcurrent = $runner->nconcurrent;
            } else {
                $qname = $runner->queue ?? "default";
                $qname = $qname !== "" ? $qname : "default";
                $qc = $info->conf->config->_queues->{$qname} ?? null;
                if (is_object($qc) && is_int($qc->nconcurrent ?? null)) {
                    $qi->nconcurrent = $qc->nconcurrent;
                }
            }
            if ($qi->nconcurrent <= 0) {
                $qi->nconcurrent = 10000;
            }
            $qi->ensure = $runner->ensure;
        }

        $qi->associate_info($info, $runner);
        return $qi;
    }

    /** @param PsetView $info
     * @param RunResponse $rr
     * @return QueueItem */
    static function for_run_response($info, $rr) {
        assert($rr->pset === $info->pset->urlkey
               || $info->conf->pset_by_key($rr->pset) === $info->pset);
        $qi = self::make_info($info);
        $qi->queueid = $rr->queueid;
        $qi->runnername = $rr->runner;
        $qi->runat = $rr->timestamp;
        $qi->runsettings = $rr->settings;
        $qi->tags = $rr->tags;
        return $qi;
    }

    /** @param PsetView $info
     * @param RunnerConfig $runner
     * @return QueueItem */
    static function for_complete_job($info, $runner) {
        foreach ($info->run_logger()->completed_responses($runner, $info->hash()) as $rr) {
            $qi = self::for_run_response($info, $rr);
            $qi->associate_info($info, $runner);
            return $qi;
        }
        return null;
    }

    /** @param int $delta
     * @return int */
    static function unscheduled_runorder($delta = 0) {
        return Conf::$now + $delta + 1000000000;
    }


    /** @return ?RunResponse */
    function compatible_response() {
        $info = $this->info();
        $runner = $this->runner();
        if ($info && $runner && !$this->runsettings) {
            foreach ($info->run_logger()->completed_responses($runner, $info->hash()) as $rr) {
                foreach ($this->tags as $t) {
                    if (!$rr->has_tag($t))
                        continue 2;
                }
                if (!$rr->settings) {
                    return $rr;
                }
            }
        }
        return null;
    }

    function enqueue() {
        assert($this->queueid === 0);
        $this->conf->clean_queue();
        $this->insertat = $this->updateat = Conf::$now;
        $this->runat = 0;
        $this->runorder = $this->runorder ?? self::unscheduled_runorder();
        $this->status = self::STATUS_UNSCHEDULED;
        $this->conf->qe("insert into ExecutionQueue set reqcid=?, cid=?,
            runnername=?, psetid=?, repoid=?,
            bhash=?, runsettings=?, tags=?,
            queueclass=?, nconcurrent=?, flags=?, chain=?,
            insertat=?, updateat=?,
            runat=?, runorder=?, status=?,
            ensure=?",
            $this->reqcid, $this->cid,
            $this->runnername, $this->psetid, $this->repoid,
            $this->bhash, $this->runsettings ? json_encode_db($this->runsettings) : null,
            $this->tags ? " " . join(" ", $this->tags) . " " : null,
            $this->queueclass, $this->nconcurrent, $this->flags, $this->chain,
            $this->insertat, $this->updateat,
            $this->runat, $this->runorder, $this->status,
            $this->ensure ? json_encode_db($this->ensure) : null);
        $this->queueid = $this->conf->dblink->insert_id;
    }

    /** @param int $priority
     * @param ?int $userid */
    function schedule($priority, $userid = null) {
        if ($this->queueid === 0) {
            $this->enqueue();
        }

        if ($this->status === self::STATUS_UNSCHEDULED) {
            if (($userid ?? 0) > 0) {
                $this->conf->qe("update ExecutionQueue
                        set status=?, scheduleat=?,
                        runorder=greatest(coalesce((select last_runorder from ContactInfo where contactId=?),0),?)+?
                        where queueid=? and status=?",
                    self::STATUS_SCHEDULED, Conf::$now,
                    $userid, Conf::$now, $priority,
                    $this->queueid, self::STATUS_UNSCHEDULED);
            } else {
                $this->conf->qe("update ExecutionQueue
                        set status=?, runorder=?
                        where queueid=? and status=?",
                    self::STATUS_SCHEDULED, Conf::$now + $priority,
                    $this->queueid, self::STATUS_UNSCHEDULED);
            }
            if (($row = $this->conf->fetch_first_row("select status, scheduleat, runorder from ExecutionQueue where queueid=?", $this->queueid))) {
                $this->status = (int) $row[0];
                $this->scheduleat = (int) $row[1];
                $this->runorder = (int) $row[2];
                if (($userid ?? 0) > 0) {
                    $this->conf->qe("update ContactInfo set last_runorder=? where contactId=? and last_runorder<?",
                        $this->runorder, $userid, $this->runorder);
                }
            }
        }
    }

    function update() {
        assert($this->queueid !== 0);
        $result = $this->conf->qe("update ExecutionQueue set updateat=? where queueid=? and updateat<?", Conf::$now, $this->queueid, Conf::$now);
        if ($result->affected_rows) {
            $this->updateat = Conf::$now;
        }
        Dbl::free($result);
    }

    private function update_from_database() {
        if (($row = $this->conf->fetch_first_row("select status, runat, lockfile, ensure
                from ExecutionQueue where queueid=?", $this->queueid))) {
            $this->status = (int) $row[0];
            $this->runat = (int) $row[1];
            $this->lockfile = $row[2];
            $this->set_ensure($row[3]);
        }
    }

    /** @param int $new_status
     * @param array{runat?:int,lockfile?:string} $fields
     * @return bool */
    private function swap_status($new_status, $fields = []) {
        $new_runat = $fields["runat"] ?? $this->runat;
        if ($this->queueid !== 0) {
            $new_lockfile = $fields["lockfile"] ?? $this->lockfile;
            $result = $this->conf->qe("update ExecutionQueue
                    set status=?, runat=?, lockfile=?, bhash=?
                    where queueid=? and status=?",
                $new_status, $new_runat, $new_lockfile, $this->bhash,
                $this->queueid, $this->status);
            $changed = $result->affected_rows;
            Dbl::free($result);
            if ($changed) {
                $this->status = $new_status;
                $this->runat = $new_runat;
                $this->lockfile = $new_lockfile;
            } else {
                $this->update_from_database();
            }
        } else {
            assert(!isset($fields["lockfile"]));
            $changed = $this->status !== $new_status;
            if ($changed) {
                $this->status = $new_status;
                $this->runat = $new_runat;
            }
        }
        if ($changed && $this->status >= self::STATUS_DONE) {
            if ($this->status === self::STATUS_DONE) {
                // always evaluate at least once
                $this->evaluate();
            }
            if ($this->queueid !== 0 && $this->chain) {
                // free next element in chain
                $this->conf->qe("update ExecutionQueue set status=?, runorder=?
                        where status=? and chain=? and not exists (select * from ExecutionQueue where status>=? and status<? and chain=?)
                        order by runorder asc, queueid asc limit 1",
                    self::STATUS_SCHEDULED, Conf::$now,
                    self::STATUS_UNSCHEDULED, $this->chain,
                    self::STATUS_SCHEDULED, self::STATUS_CANCELLED, $this->chain);
            }
        }
        return !!$changed;
    }

    function cancel() {
        $this->swap_status(self::STATUS_CANCELLED);
    }

    /** @param QueueStatus $qs
     * @return bool */
    function step($qs) {
        assert($this->queueid !== 0);
        assert(($this->runat > 0) === ($this->status > 0));

        // cancelled & completed: step does nothing
        if ($this->status >= self::STATUS_CANCELLED) {
            return true;
        }

        // cancel abandoned jobs
        if ($this->abandoned()) {
            $result = $this->conf->qe("update ExecutionQueue set status=?
                    where queueid=? and status=? and updateat<?",
                self::STATUS_CANCELLED,
                $this->queueid, $this->status, Conf::$now - 180);
            $changed = $result->affected_rows;
            Dbl::free($result);
            if ($changed) {
                $this->status = self::STATUS_CANCELLED;
                return true;
            }
        }

        // do nothing for unscheduled jobs
        // XXX should kickstart broken chain
        if ($this->status === self::STATUS_UNSCHEDULED) {
            return true;
        }

        // if ensure, check for compatible response
        if ($this->status === self::STATUS_SCHEDULED
            && ($this->flags & self::FLAG_ENSURE) !== 0
            && ($rr = $this->compatible_response())) {
            $this->swap_status(self::STATUS_DONE, ["runat" => $rr->timestamp]);
            if ($this->status >= self::STATUS_CANCELLED) {
                return true;
            }
        }

        // if not working, check if can start
        $nconcurrent = ($this->nconcurrent ?? 0) <= 0 ? 100000 : $this->nconcurrent;
        if ($this->status === self::STATUS_SCHEDULED
            && $qs->nrunning < min($nconcurrent, $qs->nconcurrent)) {
            try {
                // do not start_command if no command
                $this->start_command();
            } catch (Exception $e) {
                $this->last_error = $e->getMessage();
                $result = $this->conf->qe("update ExecutionQueue set status=? where queueid=?", self::STATUS_CANCELLED, $this->queueid);
                $this->status = self::STATUS_CANCELLED;
                return false;
            }
        }

        // if working, check for completion
        if ($this->status === self::STATUS_WORKING
            && $this->lockfile
            && RunLogger::active_job_at($this->lockfile) !== $this->runat) {
            // XXX this does not use run timeouts
            $this->swap_status(self::STATUS_EVALUATED);
        }

        if ($this->status === self::STATUS_SCHEDULED
            || $this->status === self::STATUS_WORKING) {
            // update queue
            ++$qs->nahead;
            if ($this->runat > 0) {
                ++$qs->nrunning;
            }
            if ($this->nconcurrent > 0) {
                $qs->nconcurrent = max(min($nconcurrent, $qs->nconcurrent), $qs->nrunning);
            }
        }
        return true;
    }


    /** @param string $x
     * @return string */
    function expand($x) {
        if (strpos($x, '${') !== false) {
            $x = str_replace('${REPOID}', (string) $this->repoid, $x);
            $x = str_replace('${PSET}', (string) $this->psetid, $x);
            $x = str_replace('${CONFDIR}', "conf/", $x);
            $x = str_replace('${SRCDIR}', "src/", $x);
            $x = str_replace('${HOSTTYPE}', $this->conf->opt("hostType") ?? "", $x);
            if (($hash = $this->hash()) !== null) {
                $x = str_replace('${COMMIT}', $hash, $x);
                $x = str_replace('${HASH}', $hash, $x);
            }
        }
        return $x;
    }

    /** @param ?PsetView $info
     * @param ?RunnerConfig $runner */
    function associate_info($info, $runner = null) {
        if ($info && $info->pset->id === $this->psetid) {
            $this->_pset = $info->pset;
            $this->_ocache |= 1;
        }
        if ($info && $info->user->contactId === $this->cid) {
            $this->_user = $info->user;
            $this->_ocache |= 16;
        }
        if ($info && $info->repo && $info->repo->repoid === $this->repoid) {
            $this->_repo = $info->repo;
            $this->_ocache |= 2;
        }
        if ($info && $this->_pset === $info->pset && $this->_repo === $info->repo) {
            $this->_info = $info;
            $this->_ocache |= 4;
        }
        if ($runner && $runner->name === $this->runnername) {
            $this->_runner = $runner;
            $this->_ocache |= 8;
        }
    }


    /** @return RunResponse */
    function response() {
        $rr = new RunResponse;
        $rr->pset = $this->pset()->urlkey;
        $rr->repoid = $this->repoid;
        if ($this->queueid) {
            $rr->queueid = $this->queueid;
        }
        if ($this->runat) {
            $rr->timestamp = $this->runat;
        }
        $rr->runner = $this->runnername;
        if ($this->bhash !== null) {
            $rr->hash = $this->hash();
        }
        $rr->settings = $this->runsettings;
        $rr->tags = $this->tags;
        return $rr;
    }


    private function start_command() {
        assert($this->runat === 0 && $this->status === self::STATUS_SCHEDULED);

        $repo = $this->repo();
        $pset = $this->pset();
        $runner = $this->runner();
        if (!$repo) {
            throw new RunnerException("No repository.");
        } else if (!$pset) {
            throw new RunnerException("Bad queue item pset.");
        } else if (!$runner) {
            throw new RunnerException("Bad queue item runner.");
        }

        // if no command, skip right to evaluation
        if (!$runner->command) {
            $this->swap_status(self::STATUS_EVALUATED, ["runat" => time()]);
            return;
        }

        // otherwise must be enqueued
        assert($this->queueid !== 0);

        if (!chdir(SiteLoader::$root)) {
            throw new RunnerException("Can’t cd to main directory.");
        }
        if (!is_executable("jail/pa-jail")) {
            throw new RunnerException("The pa-jail program has not been compiled.");
        }

        $info = $this->info();
        $runlog = $info->run_logger();
        if (!$runlog->mkdirs()) {
            throw new RunnerException("Can’t create log directory.");
        }

        $runlog->invalidate_active_job();
        if ($runlog->active_job()) {
            return;
        }
        $runlog->invalidate_active_job();

        // collect user information
        if ($runner->username) {
            $username = $runner->username;
        } else if ($pset->run_username) {
            $username = $pset->run_username;
        } else {
            $username = "jail61user";
        }
        if (!preg_match('/\A\w+\z/', $username)) {
            throw new RunnerException("Bad run_username.");
        }

        $pwnam = posix_getpwnam($username);
        $userhome = $pwnam ? $pwnam["dir"] : "/home/jail61";
        $userhome = preg_replace('/\/+\z/', '', $userhome);

        // collect directory information
        $this->_jaildir = preg_replace('/\/+\z/', '', $this->expand($pset->run_dirpattern));
        if (!$this->_jaildir) {
            throw new RunnerException("Bad run_dirpattern.");
        }
        $this->_jailhomedir = "{$this->_jaildir}/" . preg_replace('/\A\/+/', '', $userhome);

        // create logfile and pidfile
        $runat = time();
        $logbase = $runlog->job_prefix($runat);
        $this->_logfile = "{$logbase}.log";
        $timingfile = "{$logbase}.log.time";
        $pidfile = $runlog->pid_file();
        file_put_contents($pidfile, "{$runat}\n");
        $inputfifo = "{$logbase}.in";
        if (!posix_mkfifo($inputfifo, 0660)) {
            $inputfifo = null;
        }
        if ($runner->timed_replay) {
            touch($timingfile);
        }
        $this->_logstream = fopen($this->_logfile, "a");
        $this->bhash = $info->bhash(); // resolve blank hash

        if (!$this->swap_status(self::STATUS_WORKING, ["runat" => $runat, "lockfile" => $pidfile])) {
            return;
        }
        $this->_runstatus = 1;
        register_shutdown_function([$this, "cleanup"]);

        // print json to first line
        $json = $this->response();
        if (($hostname = gethostname()) !== false) {
            $json->host = gethostbyname($hostname);
        }
        fwrite($this->_logstream, "++ " . json_encode($json) . "\n");

        // create jail
        $this->remove_old_jails();
        if ($this->run_and_log("jail/pa-jail add " . escapeshellarg($this->_jaildir) . " " . escapeshellarg($username))) {
            throw new RunnerException("Can’t initialize jail.");
        }

        // check out code
        $this->checkout_code();

        // save commit settings
        $this->add_run_settings($json->settings ?? []);

        // actually run
        $command = "echo; jail/pa-jail run"
            . " -p" . escapeshellarg($pidfile)
            . " -P'{$this->runat} $$"
            . ($inputfifo ? " -i" : "") . "'";
        if ($runner->timed_replay) {
            $command .= " -t" . escapeshellarg($timingfile);
        }

        $skeletondir = $pset->run_skeletondir ? : $this->conf->opt("run_skeletondir");
        $binddir = $pset->run_binddir ? : $this->conf->opt("run_binddir");
        if ($skeletondir && $binddir && !is_dir("$skeletondir/proc")) {
            $binddir = false;
        }
        $jfiles = $runner->jailfiles();

        if ($skeletondir && $binddir) {
            $binddir = preg_replace('/\/+\z/', '', $binddir);
            $contents = "/ <- {$skeletondir} [bind-ro";
            if ($jfiles
                && !preg_match('/[\s\];]/', $jfiles)
                && ($jhash = hash_file("sha256", $jfiles)) !== false) {
                $contents .= " $jhash $jfiles";
            }
            $contents .= "]\n{$userhome} <- {$this->_jailhomedir} [bind]";
            $command .= " -u" . escapeshellarg($this->_jailhomedir)
                . " -F" . escapeshellarg($contents);
            $homedir = $binddir;
        } else if ($jfiles) {
            $command .= " -h -f" . escapeshellarg($jfiles);
            if ($skeletondir) {
                $command .= " -S" . escapeshellarg($skeletondir);
            }
            $homedir = $this->_jaildir;
        } else {
            throw new RunnerException("Missing jail population configuration.");
        }

        $jmanifest = $runner->jailmanifest();
        if ($jmanifest) {
            $command .= " -F" . escapeshellarg(join("\n", $jmanifest));
        }

        if (($to = $runner->timeout ?? $pset->run_timeout) > 0) {
            $command .= " -T{$to}";
        }
        if (($to = $runner->idle_timeout ?? $pset->run_idle_timeout) > 0) {
            $command .= " -I{$to}";
        }
        if (($runner->rows ?? 0) > 0 || ($runner->columns ?? 0) > 0) {
            $rows = ($runner->rows ?? 0) > 0 ? $runner->rows : 25;
            $cols = ($runner->columns ?? 0) > 0 ? $runner->columns : 80;
            $command .= " --size={$cols}x{$rows}";
        }
        if ($inputfifo) {
            $command .= " -i" . escapeshellarg($inputfifo);
        }
        $command .= " " . escapeshellarg($homedir)
            . " " . escapeshellarg($username)
            . " TERM=xterm-256color"
            . " " . escapeshellarg($this->expand($runner->command));
        $this->_runstatus = 2;
        $this->run_and_log($command);

        // save information about execution
        $this->info()->add_recorded_job($runner->name, $this->runat);
    }

    /** @param string $command
     * @param bool $bg
     * @return int */
    private function run_and_log($command, $bg = false) {
        fwrite($this->_logstream, "++ $command\n");
        system("($command) </dev/null >>" . escapeshellarg($this->_logfile) . " 2>&1" . ($bg ? " &" : ""), $status);
        return $status;
    }

    private function remove_old_jails() {
        while (is_dir($this->_jaildir)) {
            Conf::set_current_time(time());

            $newdir = $this->_jaildir . "~." . gmdate("Ymd\\THis", Conf::$now);
            if ($this->run_and_log("jail/pa-jail mv " . escapeshellarg($this->_jaildir) . " " . escapeshellarg($newdir))) {
                throw new RunnerException("Can’t remove old jail.");
            }

            $this->run_and_log("jail/pa-jail rm " . escapeshellarg($newdir), true);
            clearstatcache(false, $this->_jaildir);
        }
    }

    private function checkout_code() {
        $repo = $this->repo();
        $pset = $this->pset();
        $runner = $this->runner();
        assert($repo !== null && $pset !== null && $runner !== null && $this->bhash !== null);
        $hash = $this->hash();

        $checkoutdir = $clonedir = $this->_jailhomedir . "/repo";
        if ($repo->truncated_psetdir($pset)
            && $pset->directory_noslash !== "") {
            $clonedir .= "/{$pset->directory_noslash}";
        }

        fwrite($this->_logstream, "++ mkdir $checkoutdir\n");
        if (!mkdir($clonedir, 0777, true)) {
            throw new RunnerException("Can’t initialize user repo in jail.");
        }

        $root = SiteLoader::$root;
        $repodir = "{$root}/repo/repo{$repo->cacheid}";

        // need a branch to check out a specific commit
        $branch = "jailcheckout_" . Conf::$now;
        if ($this->run_and_log("cd " . escapeshellarg($repodir) . " && git branch $branch $hash")) {
            throw new RunnerException("Can’t create branch for checkout.");
        }

        // make the checkout
        $status = $this->run_and_log("cd " . escapeshellarg($clonedir) . " && "
                                     . "if test ! -d .git; then git init --shared=group -b main; fi && "
                                     . "git fetch --depth=1 -p " . escapeshellarg($repodir) . " $branch && "
                                     . "git reset --hard $hash");

        $this->run_and_log("cd " . escapeshellarg($repodir) . " && git branch -D $branch");

        if ($status) {
            throw new RunnerException("Can’t check out code into jail.");
        }

        if ($this->run_and_log("cd " . escapeshellarg($clonedir) . " && rm -rf .git .gitcheckout")) {
            throw new RunnerException("Can’t clean up checkout in jail.");
        }

        // create overlay
        if (($overlay = $runner->overlay())) {
            $this->checkout_overlay($checkoutdir, $overlay);
        }
    }

    /** @param string $checkoutdir
     * @param list<RunOverlayConfig> $overlayfiles */
    function checkout_overlay($checkoutdir, $overlayfiles) {
        foreach ($overlayfiles as $ro) {
            $path = $ro->absolute_path();
            if (preg_match('/(?:\.tar|\.tar\.[gx]z|\.t[bgx]z|\.tar\.bz2)\z/i', $ro->file)) {
                $c = "cd " . escapeshellarg($checkoutdir) . " && tar -xf " . escapeshellarg($path);
                foreach ($ro->exclude ?? [] as $xf) {
                    $c .= " --exclude " . escapeshellarg($xf);
                }
                $x = $this->run_and_log($c);
            } else {
                fwrite($this->_logstream, "++ cp " . escapeshellarg($path) . " " . escapeshellarg($checkoutdir) . "\n");
                $rslash = strrpos($path, "/");
                $x = !copy($path, $checkoutdir . substr($path, $rslash));
            }
            if ($x) {
                throw new RunnerException("Can’t unpack overlay.");
            }
        }

        $checkout_instructions = @file_get_contents($checkoutdir . "/.gitcheckout");
        if ($checkout_instructions) {
            foreach (explode("\n", $checkout_instructions) as $text) {
                if (substr($text, 0, 3) === "rm:") {
                    $this->run_and_log("cd " . escapeshellarg($checkoutdir) . " && rm -rf " . escapeshellarg(substr($text, 3)));
                }
            }
        }
    }

    private function add_run_settings($s) {
        $mk = $sh = [];
        foreach ((array) $s as $k => $v) {
            if (preg_match('/\A[A-Za-z_][A-Za-z_0-9]*\z/', $k)
                && !preg_match('/\A(?:PATH|MAKE|HOME|SHELL|POSIXLY_CORRECT|TMPDIR|LANG|USER|LOGNAME|SSH.*|PS\d|HISTFILE|LD_LIBRARY_PATH|HOST|HOSTNAME|TERM|TERMCAP|EDITOR|PAGER|MANPATH)\z/', $k)) {
                if (preg_match('/\A[-A-Za-z0-9_:\/ .,]*\z/', $v)) {
                    $mk[] = "$k = $v\n";
                }
                $sh[] = "$k=" . escapeshellarg($v) . "\n";
            }
        }
        file_put_contents("{$this->_jailhomedir}/config.mk", join("", $mk));
        file_put_contents("{$this->_jailhomedir}/config.sh", join("", $sh));
    }


    /** @param int $offset
     * @param ?string $write
     * @param bool $stop
     * @return RunResponse */
    private function command_response($offset, $write, $stop) {
        if ($this->_info) {
            $runlog = $this->_info->run_logger();
        } else {
            $runlog = new RunLogger($this->pset(), $this->repo());
        }

        if ((($write ?? "") === "" && !$stop)
            || $runlog->active_job() !== $this->runat) {
            return $runlog->job_response($this->runat, $offset);
        }

        if (($write ?? "") !== "") {
            $runlog->job_write($this->runat, $write);
        }
        if ($stop) {
            // "ESC Ctrl-C" is captured by pa-jail
            $runlog->job_write($this->runat, "\x1b\x03");
        }
        $now = microtime(true);
        do {
            usleep(10);
            $runlog->invalidate_active_job();
            $rr = $runlog->job_response($this->runat, $offset);
        } while ($stop
                 && !$rr->done
                 && microtime(true) - $now < 0.1);
        return $rr;
    }

    /** @param int $offset
     * @param ?string $write
     * @param bool $stop
     * @return RunResponse */
    function full_response($offset = 0, $write = null, $stop = false) {
        $runner = $this->runner();
        if ($runner->command) {
            $rr = $this->command_response(0, null, $stop);
        } else {
            $this->runat = $this->runat ? : time();
            $rr = RunResponse::make($runner, $this->repo());
            $rr->timestamp = $this->runat;
            $rr->done = true;
            $rr->status = "done";
        }

        if ($rr->partial) {
            $rr->status = "partial";
        } else if ($rr->done) {
            $rr->status = "done";
        } else if (Conf::$now - $this->runat <= 600) {
            $rr->status = "working";
        } else {
            $rr->status = "old";
        }

        if ($rr->done
            && $this->queueid > 0
            && $this->status < self::STATUS_CANCELLED) {
            $this->swap_status(self::STATUS_EVALUATED);
        }

        if ($rr->done
            && $this->status >= self::STATUS_DONE
            && $this->runner()->evaluate_function) {
            $rr->result = $this->evaluate();
        }

        return $rr;
    }

    function cleanup() {
        if ($this->_runstatus === 1) {
            $runlog = new RunLogger($this->pset(), $this->repo());
            unlink($runlog->pid_file());
            @unlink($runlog->job_prefix($this->runat) . ".in");
        }
    }
}

class QueueStatus {
    /** @var int */
    public $nrunning = 0;
    /** @var int */
    public $nahead = 0;
    /** @var int */
    public $nconcurrent = 100000;
}
