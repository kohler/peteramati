<?php
// queueitem.php -- Peteramati queue state
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class QueueItem {
    /** @var Conf */
    public $conf;

    /** @var int */
    public $queueid;
    /** @var int */
    public $reqcid;
    /** @var bool */
    public $deleted = false;

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
    /** @var ?string */
    public $runsettings;

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
    public $updateat;
    /** @var int */
    public $runat;
    /** @var int */
    public $status;
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

    const FLAG_UNWATCHED = 1;
    const FLAG_ENSURE = 2;
    const FLAG_ANONYMOUS = 4;


    function __construct(Conf $conf) {
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

        $this->runorder = (int) $this->runorder;
        $this->insertat = (int) $this->insertat;
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
                $this->_info = PsetView::make($p, $u, $u, $this->hash());
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

    /** @return bool */
    function irrelevant() {
        return $this->status <= 0
            && ($this->flags & self::FLAG_UNWATCHED) === 0
            && $this->updateat < Conf::$now - 180;
    }


    /** @param ?PsetView $info
     * @return ?QueueItem */
    static function fetch(Conf $conf, $result, $info = null) {
        $qi = $result ? $result->fetch_object("QueueItem", [$conf]) : null;
        if ($qi && !is_int($qi->queueid)) {
            $qi->db_load();
            $info && $qi->associate_info($info);
        }
        return $qi;
    }

    /** @param int $queueid
     * @param ?PsetView $info
     * @return ?QueueItem */
    static function by_id(Conf $conf, $queueid, $info = null) {
        $result = $conf->qe("select * from ExecutionQueue where queueid=?", $queueid);
        return self::fetch($conf, $result, $info);
    }

    /** @param int $chain
     * @return ?QueueItem */
    static function by_chain(Conf $conf, $chain) {
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
        $qi->reqcid = $info->viewer->contactId;
        $qi->cid = $info->user->contactId;
        $qi->psetid = $info->pset->id;
        $qi->repoid = $info->repo ? $info->repo->repoid : 0;
        $qi->bhash = $info->bhash();
        if ($qi->bhash
            && ($rs = $info->commit_jnote("runsettings"))) {
            $qi->runsettings = json_encode_db($rs);
        }
        $qi->flags = $info->user->is_anonymous ? self::FLAG_ANONYMOUS : 0;

        $qi->queueclass = "";
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
        }

        $qi->associate_info($info, $runner);
        return $qi;
    }

    /** @param PsetView $info
     * @param int $jobid
     * @return QueueItem */
    static function for_logged_jobid($info, $jobid) {
        $runlog = new RunLogger($info->pset, $info->repo);
        if (($j = $runlog->job_info($jobid))
            && is_string($j->runner ?? null)
            && is_string($j->pset ?? null)
            && ($j->pset === $info->pset->urlkey
                || $info->conf->pset_by_key($j->pset) === $info->pset)) {
            $qi = self::make_info($info);
            $qi->queueid = $j->queueid ?? null;
            $qi->runnername = $j->runner;
            $qi->runat = $j->timestamp ?? null;
            $qi->runsettings = isset($j->settings) ? (array) $j->settings : null;
            return $qi;
        } else {
            return null;
        }
    }

    /** @param PsetView $info
     * @param RunnerConfig $runner
     * @return QueueItem */
    static function for_complete_job($info, $runner) {
        if (($jobid = $info->complete_run($runner))) {
            $qi = QueueItem::for_logged_jobid($info, $jobid);
            $qi && $qi->associate_info($info, $runner);
            return $qi;
        } else {
            return null;
        }
    }

    /** @param int $delta
     * @return int */
    static function unscheduled_runorder($delta = 0) {
        return Conf::$now + $delta + 1000000000;
    }


    function enqueue() {
        assert(!$this->queueid);
        $this->insertat = $this->updateat = Conf::$now;
        $this->runat = 0;
        $this->runorder = $this->runorder ?? self::unscheduled_runorder();
        $this->status = -1;
        $this->conf->qe("insert into ExecutionQueue set reqcid=?, cid=?,
            runnername=?, psetid=?, repoid=?, bhash=?, runsettings=?,
            queueclass=?, nconcurrent=?, flags=?, chain=?,
            insertat=?, updateat=?,
            runat=?, runorder=?, status=?",
            $this->reqcid, $this->cid,
            $this->runnername, $this->psetid, $this->repoid, $this->bhash, $this->runsettings,
            $this->queueclass, $this->nconcurrent, $this->flags, $this->chain,
            $this->insertat, $this->updateat,
            $this->runat, $this->runorder, $this->status);
        $this->queueid = $this->conf->dblink->insert_id;
    }

    /** @param int $priority
     * @param ?int $userid */
    function schedule($priority, $userid = null) {
        assert(!!$this->queueid);
        if ($this->status === -1) {
            if (($userid ?? 0) > 0) {
                $this->conf->qe("update ExecutionQueue
                    set status=0, runorder=greatest(coalesce((select last_runorder from ContactInfo where contactId=?),0),?)+?
                    where queueid=? and status=-1",
                    $userid, Conf::$now, $priority, $this->queueid);
            } else {
                $this->conf->qe("update ExecutionQueue set status=0, runorder=? where queueid=? and status=-1",
                    Conf::$now + $priority, $this->queueid);
            }
            if (($row = $this->conf->fetch_first_row("select status, runorder from ExecutionQueue where queueid=?", $this->queueid))) {
                $this->status = (int) $row[0];
                $this->runorder = (int) $row[1];
                if (($userid ?? 0) > 0) {
                    $this->conf->qe("update ContactInfo set last_runorder=? where contactId=? and last_runorder<?",
                        $this->runorder, $userid, $this->runorder);
                }
            }
        }
    }

    function update() {
        assert(!!$this->queueid && !$this->deleted);
        $result = $this->conf->qe("update ExecutionQueue set updateat=? where queueid=? and updateat<?", Conf::$now, $this->queueid, Conf::$now);
        if ($result->affected_rows) {
            $this->updateat = Conf::$now;
        }
        Dbl::free($result);
    }

    /** @param QueueStatus $qs */
    function substantiate($qs) {
        assert(!!$this->queueid && !$this->deleted);
        assert(($this->status > 0) === ($this->runat > 0));
        $nconcurrent = ($this->nconcurrent ?? 0) <= 0 ? 100000 : $this->nconcurrent;
        if ($this->runat > 0) {
            // remove dead items from queue
            // - pidfile contains "0\n": child has exited, remove it
            // - pidfile specified but not there
            // XXX do not use run timeouts
            if ($this->lockfile
                && RunLogger::active_job_at($this->lockfile) !== $this->runat) {
                $this->delete(false);
                return;
            }
        } else if (($this->flags & self::FLAG_UNWATCHED) === 0
                   && $this->updateat < Conf::$now - 30) {
            if ($this->irrelevant()) {
                $this->delete(true);
                return;
            }
        } else if (($this->flags & self::FLAG_ENSURE) !== 0
                   && ($jobid = $this->info()->complete_run($this->runner()))) {
            $this->delete(false);
            $this->runat = $jobid;
            $this->status = 2;
            return;
        } else if ($qs->nrunning < min($nconcurrent, $qs->nconcurrent)) {
            $this->start_command();
        }
        ++$qs->nahead;
        if ($this->runat > 0) {
            ++$qs->nrunning;
        }
        if ($this->nconcurrent > 0) {
            $qs->nconcurrent = max(min($nconcurrent, $qs->nconcurrent), $qs->nrunning);
        }
    }

    /** @param bool $only_old */
    private function delete($only_old) {
        assert(!!$this->queueid && !$this->deleted);
        if ($only_old) {
            $result = $this->conf->qe("delete from ExecutionQueue where queueid=? and status=0 and updateat<?", $this->queueid, Conf::$now - 180);
        } else {
            $result = $this->conf->qe("delete from ExecutionQueue where queueid=?", $this->queueid);
        }
        if ($result->affected_rows) {
            $this->deleted = true;
            if ($this->chain) {
                $this->conf->qe("update ExecutionQueue set status=0, runorder=? where status=-1 and chain=? order by runorder asc, queueid asc limit 1",
                    Conf::$now, $this->chain);
            }
        }
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
        if (($rs = $this->runsettings ? json_decode($this->runsettings) : null)) {
            $rr->settings = (array) $rs;
        }
        return $rr;
    }


    function start_command() {
        assert($this->runat === 0 && $this->status === 0 && !!$this->queueid && !$this->deleted);

        $repo = $this->repo();
        $pset = $this->pset();
        $runner = $this->runner();
        if (!$repo || !$pset || !$runner) {
            throw new RunnerException("Bad queue item");
        }
        $info = $this->info();

        if (!chdir(SiteLoader::$root)) {
            throw new RunnerException("Can’t cd to main directory");
        }
        if (!is_executable("jail/pa-jail")) {
            throw new RunnerException("The pa-jail program has not been compiled");
        }

        $runlog = new RunLogger($pset, $repo);
        if (!$runlog->mkdirs()) {
            throw new RunnerException("Can’t create log directory");
        }
        if ($runlog->active_job()) {
            return false;
        }

        // collect user information
        if ($runner->username) {
            $username = $runner->username;
        } else if ($pset->run_username) {
            $username = $pset->run_username;
        } else {
            $username = "jail61user";
        }
        if (!preg_match('/\A\w+\z/', $username)) {
            throw new RunnerException("Bad run_username");
        }

        $pwnam = posix_getpwnam($username);
        $userhome = $pwnam ? $pwnam["dir"] : "/home/jail61";
        $userhome = preg_replace('/\/+\z/', '', $userhome);

        // collect directory information
        $this->_jaildir = preg_replace('/\/+\z/', '', $this->expand($pset->run_dirpattern));
        if (!$this->_jaildir) {
            throw new RunnerException("Bad run_dirpattern");
        }
        $this->_jailhomedir = "{$this->_jaildir}/" . preg_replace('/\A\/+/', '', $userhome);

        // create logfile and pidfile
        $this->runat = time();
        $logbase = $runlog->job_prefix($this->runat);
        $this->_logfile = "{$logbase}.log";
        $timingfile = "{$logbase}.log.time";
        $pidfile = $runlog->pid_file();
        file_put_contents($pidfile, "");
        $inputfifo = "{$logbase}.in";
        if (!posix_mkfifo($inputfifo, 0660)) {
            $inputfifo = null;
        }
        if ($runner->timed_replay) {
            touch($timingfile);
        }
        $this->_logstream = fopen($this->_logfile, "a");
        $this->bhash = $info->bhash(); // resolve blank hash

        $result = $this->conf->qe("update ExecutionQueue set runat=?, status=1, lockfile=?, bhash=? where queueid=? and status=0",
            $this->runat, $pidfile, $this->bhash, $this->queueid);
        if (!$result->affected_rows) {
            if (($row = $this->conf->fetch_first_row("select runat, status, lockfile from ExecutionQueue where queueid=?", $this->queueid))) {
                $this->runat = (int) $row[0];
                $this->status = (int) $row[1];
                $this->lockfile = $row[2];
            }
            return $this->status > 0;
        }
        $this->status = 1;
        $this->lockfile = $pidfile;
        $this->_runstatus = 1;
        register_shutdown_function([$this, "cleanup"]);

        // print json to first line
        $json = $this->response();
        fwrite($this->_logstream, "++ " . json_encode($json) . "\n");

        // create jail
        $this->remove_old_jails();
        if ($this->run_and_log("jail/pa-jail add " . escapeshellarg($this->_jaildir) . " " . escapeshellarg($username))) {
            throw new RunnerException("Can’t initialize jail");
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
            throw new RunnerException("Missing jail population configuration");
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
        if ($inputfifo) {
            $command .= " -i" . escapeshellarg($inputfifo);
        }
        $command .= " " . escapeshellarg($homedir)
            . " " . escapeshellarg($username)
            . " TERM=xterm-256color"
            . " " . escapeshellarg($this->expand($runner->command));
        $this->_runstatus = 0;
        $this->run_and_log($command);

        // save information about execution
        $this->info()->update_commit_notes(["run" => [$this->runner()->category => $this->runat]]);

        return true;
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
                throw new RunnerException("Can’t remove old jail");
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
            throw new RunnerException("Can’t initialize user repo in jail");
        }

        $root = SiteLoader::$root;
        $repodir = "{$root}/repo/repo{$repo->cacheid}";

        // need a branch to check out a specific commit
        $branch = "jailcheckout_" . Conf::$now;
        if ($this->run_and_log("cd " . escapeshellarg($repodir) . " && git branch $branch $hash")) {
            throw new RunnerException("Can’t create branch for checkout");
        }

        // make the checkout
        $status = $this->run_and_log("cd " . escapeshellarg($clonedir) . " && "
                                     . "if test ! -d .git; then git init --shared=group; fi && "
                                     . "git fetch --depth=1 -p " . escapeshellarg($repodir) . " $branch && "
                                     . "git reset --hard $hash");

        $this->run_and_log("cd " . escapeshellarg($repodir) . " && git branch -D $branch");

        if ($status) {
            throw new RunnerException("Can’t check out code into jail");
        }

        if ($this->run_and_log("cd " . escapeshellarg($clonedir) . " && rm -rf .git .gitcheckout")) {
            throw new RunnerException("Can’t clean up checkout in jail");
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
                throw new RunnerException("Can’t unpack overlay");
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
    function logged_response($offset = 0, $write = null, $stop = false) {
        $runlog = new RunLogger($this->pset(), $this->repo());
        if ((($write ?? "") !== "" || $stop)
            && $runlog->active_job() === $this->runat) {
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
                $rr = $runlog->job_response($this->runner(), $this->runat, $offset);
            } while ($stop
                     && $runlog->active_job() === $this->runat
                     && microtime(true) - $now < 0.1);
        } else {
            $rr = $runlog->job_response($this->runner(), $this->runat, $offset);
        }

        if ($rr->status !== "working" && $this->queueid > 0) {
            $this->delete(false);
        }

        if ($rr->status === "done"
            && $this->runner()->eval
            && ($info = $this->info())) {
            $rr->result = $info->runner_evaluate($this->runner(), $this->runat);
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
