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

    /** @var string */
    public $queueclass;
    /** @var ?int */
    public $nconcurrent;

    /** @var int */
    public $insertat;
    /** @var int */
    public $updateat;
    /** @var int */
    public $runat;
    /** @var int */
    public $autorun;
    /** @var int */
    public $status;
    /** @var ?string */
    public $lockfile;
    /** @var ?string */
    public $inputfifo;
    /** @var ?string */
    public $runsettings;

    // loaded from joins
    /** @var ?int */
    public $nahead;
    /** @var ?int */
    public $head_runat;
    /** @var ?int */
    public $ahead_nconcurrent;

    /** @var ?bool */
    public $runnable;

    // object links
    /** @var ?Repository */
    private $_repo;
    /** @var ?Pset */
    private $_pset;
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

        $this->insertat = (int) $this->insertat;
        $this->updateat = (int) $this->updateat;
        $this->runat = (int) $this->runat;
        $this->status = (int) $this->status;
        $this->autorun = (int) $this->autorun;

        if ($this->nahead !== null) {
            $this->nahead = (int) $this->nahead;
        }
        if ($this->head_runat !== null) {
            $this->head_runat = (int) $this->head_runat;
        }
        if ($this->ahead_nconcurrent !== null) {
            $this->ahead_nconcurrent = (int) $this->ahead_nconcurrent;
        }
    }

    /** @return ?QueueItem */
    static function fetch(Conf $conf, $result) {
        $qi = $result ? $result->fetch_object("QueueItem", [$conf]) : null;
        '@phan-var-force ?Repository $repo';
        if ($qi && !is_int($qi->queueid)) {
            $qi->db_load();
        }
        return $qi;
    }

    /** @param int $queueid
     * @return ?QueueItem */
    static function by_id($queueid, Conf $conf) {
        $result = $conf->qe("select * from QueueItem where queueid=?", $queueid);
        return self::fetch($conf, $result);
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
            if ($this->bhash !== null) {
                $hash = bin2hex($this->bhash);
                $x = str_replace('${COMMIT}', $hash, $x);
                $x = str_replace('${HASH}', $hash, $x);
            }
        }
        return $x;
    }


    /** @return ?Repository */
    function repo() {
        if (($this->_ocache & 1) === 0) {
            $this->_ocache |= 1;
            if ($this->repoid > 0) {
                $this->_repo = Repository::by_id($this->repoid, $this->conf);
            }
        }
        return $this->_repo;
    }

    /** @return ?Pset */
    function pset() {
        if (($this->_ocache & 2) === 0) {
            $this->_ocache |= 2;
            $this->_pset = $this->conf->pset_by_id($this->psetid);
        }
        return $this->_pset;
    }

    /** @return ?RunnerConfig */
    function runner() {
        if (($this->_ocache & 4) === 0) {
            $this->_ocache |= 4;
            if (($p = $this->pset())) {
                $this->_runner = $p->runners[$this->runnername] ?? null;
            }
        }
        return $this->_runner;
    }


    /** @return RunResponse */
    function response() {
        $rr = new RunResponse;
        $rr->repoid = $this->repoid;
        $rr->pset = $this->pset()->urlkey;
        if ($this->queueid) {
            $rr->queueid = $this->queueid;
        }
        if ($this->runat) {
            $rr->timestamp = $this->runat;
        }
        $rr->runner = $this->runnername;
        if ($this->bhash !== null) {
            $rr->hash = bin2hex($this->bhash);
        }
        if (($rs = $this->runsettings ? json_decode($this->runsettings) : null)) {
            $rr->settings = (array) $rs;
        }
        return $rr;
    }


    function start() {
        assert($this->runat === 0);

        $repo = $this->repo();
        $pset = $this->pset();
        $runner = $this->runner();
        if (!$repo || !$pset || !$runner) {
            throw new RunnerException("Bad queue item");
        }

        if (!chdir(SiteLoader::$root)) {
            throw new RunnerException("Can’t cd to main directory");
        } else if (!is_executable("jail/pa-jail")) {
            throw new RunnerException("The pa-jail program has not been compiled");
        }

        $runlog = new RunLogger($repo, $pset);
        if ($runlog->active_job()) {
            throw new RunnerException("Recent job still running");
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

        $info = posix_getpwnam($username);
        $userhome = $info ? $info["dir"] : "/home/jail61";
        $userhome = preg_replace('/\/+\z/', '', $userhome);

        // collect directory information
        $this->_jaildir = preg_replace('/\/+\z/', '', $this->expand($pset->run_dirpattern));
        if (!$this->_jaildir) {
            throw new RunnerException("Bad run_dirpattern");
        }
        $this->_jailhomedir = "{$this->_jaildir}/" . preg_replace('/\A\/+/', '', $userhome);

        // create logfile and pidfile
        $this->runat = time(); // XXX conflict
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
        if ($this->queueid) {
            Dbl::qe("update ExecutionQueue set runat=?, status=1, lockfile=?, inputfifo=? where queueid=?",
                    $this->runat, $pidfile, $inputfifo, $this->queueid);
        }
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
        return $this->run_and_log($command);
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
        $hash = bin2hex($this->bhash);

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

    function cleanup() {
        if ($this->_runstatus === 1) {
            $runlog = new RunLogger($this->repo(), $this->pset());
            unlink($runlog->pid_file());
            @unlink($runlog->job_prefix($this->runat) . ".in");
        }
    }
}
