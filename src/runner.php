<?php
// runner.php -- Peteramati runner state
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class RunnerException extends Exception {
}

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
    /** @var ?string */
    public $logdir;

    /** @var ?int */
    public $checkt;
    /** @var ?int */
    public $queueid;
    /** @var string */
    private $basefile;
    /** @var string */
    private $logfile;
    /** @var ?string */
    private $timingfile;
    /** @var ?string */
    private $pidfile;
    /** @var ?string */
    private $inputfifo;
    private $logstream;
    /** @var string */
    private $username;
    /** @var string */
    private $userhome;
    /** @var string */
    private $jaildir;
    /** @var string */
    private $jailhomedir;

    /** @var ?list<int> */
    private $_logged_checkts;
    /** @var ?list<RunOverlayConfig> */
    private $_overlay;

    function __construct(PsetView $info, RunnerConfig $runner, $checkt = null) {
        $this->conf = $info->conf;
        $this->info = $info;
        $this->repo = $info->repo;
        $this->repoid = $this->repo ? $this->repo->repoid : null;
        $this->pset = $info->pset;
        $this->runner = $runner;
        assert(!$runner->command || $this->repoid);

        if (!$this->pset->gitless) {
            $root = SiteLoader::$root;
            $this->logdir = "{$root}/log/run{$this->repo->cacheid}.pset{$this->pset->id}";
            if (!is_dir($this->logdir)) {
                $old_umask = umask(0);
                if (!mkdir($this->logdir, 02770, true)) {
                    throw new RunnerException("Cannot create log directory");
                }
                umask($old_umask);
            }
        }

        if ($checkt) {
            $this->set_checkt($checkt);
        }
    }

    /** @param string $x
     * @return string */
    function expand($x) {
        if (strpos($x, '${') !== false) {
            $x = str_replace('${REPOID}', $this->repoid, $x);
            $x = str_replace('${PSET}', (string) $this->pset->id, $x);
            $x = str_replace('${CONFDIR}', "conf/", $x);
            $x = str_replace('${SRCDIR}', "src/", $x);
            $x = str_replace('${HOSTTYPE}', $this->conf->opt("hostType") ?? "", $x);
            $x = str_replace('${COMMIT}', $this->info->commit_hash(), $x);
            $x = str_replace('${HASH}', $this->info->commit_hash(), $x);
        }
        return $x;
    }


    /** @return list<int> */
    function logged_checkts() {
        if ($this->_logged_checkts === null) {
            $logfs = glob("{$this->logdir}/repo{$this->repoid}.pset{$this->pset->id}.*.log");
            $this->_logged_checkts = [];
            foreach ($logfs as $f) {
                $rp = strlen($f);
                $lp = strrpos($f, ".", -5);
                $this->_logged_checkts[] = intval(substr($f, $lp + 1, $rp - $lp - 1));
            }
            rsort($this->_logged_checkts);
        }
        return $this->_logged_checkts;
    }

    function job_status($checkt, $answer = null) {
        if (!$checkt) {
            return false;
        }
        if (!$answer) {
            $answer = (object) [];
        }
        if ($this->running_checkt() != $checkt) {
            $answer->done = true;
            $answer->status = "done";
        } else {
            $answer->done = false;
            $answer->status = "working";
            if (Conf::$now - $checkt > 600) {
                $answer->status = "old";
            }
        }
        return $answer;
    }

    function job_info($checkt, $answer = null) {
        if (($answer = $this->job_status($checkt, $answer))) {
            $logfn = $this->info->runner_logfile($checkt);
            $t = @file_get_contents($logfn, false, null, 0, 4096);
            if ($t
                && str_starts_with($t, "++ {")
                && ($pos = strpos($t, "\n"))
                && ($j = json_decode(substr($t, 3, $pos - 3)))) {
                foreach ((array) $j as $k => $v) {
                    if (!isset($answer->$k))
                        $answer->$k = $v;
                }
            }
        }
        return $answer;
    }

    /** @return false|int */
    function running_checkt($fn = null) {
        $fn = $fn ?? $this->info->runner_pidfile();
        if (($f = @fopen($fn, "r"))) {
            $s = stream_get_contents($f);
            $checkt = false;
            if (($sp = strpos($s, " ")) > 0
                && ctype_digit(substr($s, 0, $sp))) {
                $checkt = (int) substr($s, 0, $sp);
            }
            if (flock($f, LOCK_SH | LOCK_NB)) {
                if ($checkt
                    && strpos($s, "-i")
                    && str_ends_with($fn, ".pid")) {
                    @unlink(substr($fn, 0, -4) . ".{$checkt}.in");
                }
                unlink($fn);
                flock($f, LOCK_UN);
                $result = false;
            } else {
                $result = $checkt ? : 1;
            }
            fclose($f);
            return $result;
        } else {
            return false;
        }
    }

    /** @return ?string */
    private function jailfiles() {
        $f = $this->pset->run_jailfiles ? : $this->conf->opt("run_jailfiles");
        return $f ? $this->expand($f) : null;
    }

    /** @return list<string> */
    private function jailmanifest() {
        $f = $this->pset->run_jailmanifest;
        if ($f === null || $f === "" || $f === []) {
            return [];
        }
        if (is_string($f)) {
            $f = preg_split('/\r\n|\r|\n/', $f);
        }
        for ($i = 0; $i !== count($f); ) {
            if ($f[$i] === "") {
                array_splice($f, $i, 1);
            } else {
                ++$i;
            }
        }
        return $f;
    }

    /** @return list<RunOverlayConfig> */
    private function overlay() {
        if ($this->_overlay === null) {
            $this->_overlay = [];
            foreach ($this->runner->overlay ?? $this->pset->run_overlay ?? [] as $r) {
                $r = clone $r;
                if ($r->file[0] !== "/") {
                    $r->file = SiteLoader::$root . "/" . $r->file;
                }
                $r->file = $this->expand($r->file);
                $this->_overlay[] = $r;
            }
        }
        return $this->_overlay;
    }

    /** @return int */
    function environment_timestamp() {
        $t = 0;
        if (($f = $this->jailfiles())) {
            $t = max($t, (int) @filemtime($f));
        }
        foreach ($this->overlay() as $r) {
            $t = max($t, (int) @filemtime($r->file));
        }
        return $t;
    }


    private function run_and_log($command, $bg = false) {
        fwrite($this->logstream, "++ $command\n");
        system("($command) </dev/null >>" . escapeshellarg($this->logfile) . " 2>&1" . ($bg ? " &" : ""), $status);
        return $status;
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

    function generic_json() {
        return (object) [
            "ok" => true, "repoid" => $this->repoid,
            "pset" => $this->pset->urlkey, "timestamp" => $this->checkt
        ];
    }

    function status_json($answer = null) {
        return $this->job_status($this->checkt, $answer);
    }

    function full_json($offset = null) {
        if (!$this->checkt) {
            return false;
        }
        $json = $this->generic_json();
        $this->status_json($json);
        if ($offset !== null) {
            $logbase = $this->info->runner_logbase($this->checkt);
            $data = @file_get_contents("{$logbase}.log", false, null, max($offset, 0));
            if ($data === false) {
                return (object) ["error" => true, "message" => "No such log"];
            }
            // Fix up $data if it is not valid UTF-8.
            if (!is_valid_utf8($data)) {
                $data = UnicodeHelper::utf8_truncate_invalid($data);
                if (!is_valid_utf8($data)) {
                    $data = UnicodeHelper::utf8_replace_invalid($data);
                }
            }
            // Get time data, if it exists
            if ($this->runner->timed_replay) {
                $json->timed = true;
            }
            if ($json->done
                && $offset <= 0
                && $this->runner->timed_replay
                && ($time = @file_get_contents("{$logbase}.log.time")) !== false) {
                $json->time_data = $time;
                if ($this->runner->timed_replay !== true) {
                    $json->time_factor = $this->runner->timed_replay;
                }
            }
            $json->data = $data;
            $json->offset = max($offset, 0);
        }
        return $json;
    }

    function write($data) {
        if (!$this->checkt) {
            return false;
        }
        $logbase = $this->info->runner_logbase($this->checkt);
        $proc = proc_open(SiteLoader::$root . "/jail/pa-writefifo " . escapeshellarg("{$logbase}.in"),
                          [["pipe", "r"]], $pipes);
        if ($pipes[0]) {
            fwrite($pipes[0], $data);
            fclose($pipes[0]);
        }
        if ($proc) {
            proc_close($proc);
        }
        return true;
    }


    function start($queue) {
        assert($this->checkt === null && $this->logfile === null);

        // collect user information
        if ($this->runner->username) {
            $this->username = $this->runner->username;
        } else if ($this->pset->run_username) {
            $this->username = $this->pset->run_username;
        } else {
            $this->username = "jail61user";
        }
        if (!preg_match('/\A\w+\z/', $this->username)) {
            throw new RunnerException("Bad run_username");
        }

        $info = posix_getpwnam($this->username);
        $this->userhome = $info ? $info["dir"] : "/home/jail61";
        $this->userhome = preg_replace('/\/+\z/', '', $this->userhome);

        $this->jaildir = preg_replace('/\/+\z/', '', $this->expand($this->pset->run_dirpattern));
        if (!$this->jaildir) {
            throw new RunnerException("Bad run_dirpattern");
        }
        $this->jailhomedir = $this->jaildir . "/" . preg_replace('/\A\/+/', '', $this->userhome);

        if (!chdir(SiteLoader::$root)) {
            throw new RunnerException("Can’t cd to main directory");
        } else if (!is_executable("jail/pa-jail")) {
            throw new RunnerException("The pa-jail program has not been compiled");
        } else if ($this->running_checkt()) {
            throw new RunnerException("Recent job still running");
        }

        // create logfile and pidfile
        $this->checkt = time();
        $logbase = $this->info->runner_logbase($this->checkt);
        $this->logfile = "{$logbase}.log";
        $this->timingfile = "{$logbase}.log.time";
        $this->pidfile = $this->info->runner_pidfile();
        file_put_contents($this->pidfile, "");
        $this->inputfifo = "{$logbase}.in";
        if (!posix_mkfifo($this->inputfifo, 0660)) {
            $this->inputfifo = null;
        }
        if ($this->runner->timed_replay) {
            touch($this->timingfile);
        }
        $this->logstream = fopen($this->logfile, "a");
        if ($queue) {
            Dbl::qe("update ExecutionQueue set runat=?, status=1, lockfile=?, inputfifo=? where queueid=?",
                    $this->checkt, $this->pidfile, $this->inputfifo, $queue->queueid);
        }
        register_shutdown_function(array($this, "cleanup"));

        // print json to first line
        $runsettings = $this->info->commit_jnote("runsettings");
        $json = (object) [
            "repoid" => $this->repoid, "pset" => $this->pset->urlkey,
            "timestamp" => $this->checkt, "hash" => $this->info->commit_hash(),
            "runner" => $this->runner->name
        ];
        if ($runsettings) {
            $json->settings = [];
            foreach ($runsettings as $k => $v) {
                $json->settings[$k] = $v;
            }
        }
        fwrite($this->logstream, "++ " . json_encode($json) . "\n");

        // create jail
        $this->remove_old_jails();
        if ($this->run_and_log("jail/pa-jail add " . escapeshellarg($this->jaildir) . " " . escapeshellarg($this->username))) {
            throw new RunnerException("Can’t initialize jail");
        }

        // check out code
        $this->checkout_code();

        // save commit settings
        $this->add_run_settings($this->info->commit_jnote("runsettings"));

        // actually run
        $command = "echo; jail/pa-jail run"
            . " -p" . escapeshellarg($this->pidfile)
            . " -P'{$this->checkt} $$"
            . ($this->inputfifo ? " -i" : "") . "'";
        if ($this->runner->timed_replay) {
            $command .= " -t" . escapeshellarg($this->timingfile);
        }

        $skeletondir = $this->pset->run_skeletondir ? : $this->conf->opt("run_skeletondir");
        $binddir = $this->pset->run_binddir ? : $this->conf->opt("run_binddir");
        if ($skeletondir && $binddir && !is_dir("$skeletondir/proc")) {
            $binddir = false;
        }
        $jfiles = $this->jailfiles();

        if ($skeletondir && $binddir) {
            $binddir = preg_replace('/\/+\z/', '', $binddir);
            $contents = "/ <- " . $skeletondir . " [bind-ro";
            if ($jfiles
                && !preg_match('/[\s\];]/', $jfiles)
                && ($jhash = hash_file("sha256", $jfiles)) !== false) {
                $contents .= " $jhash $jfiles";
            }
            $contents .= "]\n"
                . $this->userhome . " <- " . $this->jailhomedir . " [bind]";
            $command .= " -u" . escapeshellarg($this->jailhomedir)
                . " -F" . escapeshellarg($contents);
            $homedir = $binddir;
        } else if ($jfiles) {
            $command .= " -h -f" . escapeshellarg($jfiles);
            if ($skeletondir) {
                $command .= " -S" . escapeshellarg($skeletondir);
            }
            $homedir = $this->jaildir;
        } else {
            throw new RunnerException("Missing jail population configuration");
        }

        $jmanifest = $this->jailmanifest();
        if ($jmanifest) {
            $command .= " -F" . escapeshellarg(join("\n", $jmanifest));
        }

        if (($to = $this->runner->timeout ?? $this->pset->run_timeout) > 0) {
            $command .= " -T" . $to;
        }
        if (($to = $this->runner->idle_timeout ?? $this->pset->run_idle_timeout) > 0) {
            $command .= " -I" . $to;
        }
        if ($this->inputfifo) {
            $command .= " -i" . escapeshellarg($this->inputfifo);
        }
        $command .= " " . escapeshellarg($homedir)
            . " " . escapeshellarg($this->username)
            . " TERM=xterm-256color"
            . " " . escapeshellarg($this->expand($this->runner->command));
        $this->pidfile = null; /* now owned by command */
        return $this->run_and_log($command);
    }

    private function remove_old_jails() {
        while (is_dir($this->jaildir)) {
            Conf::set_current_time(time());

            $newdir = $this->jaildir . "~." . gmdate("Ymd\\THis", Conf::$now);
            if ($this->run_and_log("jail/pa-jail mv " . escapeshellarg($this->jaildir) . " " . escapeshellarg($newdir))) {
                throw new RunnerException("Can’t remove old jail");
            }

            $this->run_and_log("jail/pa-jail rm " . escapeshellarg($newdir), true);
            clearstatcache(false, $this->jaildir);
        }
    }

    private function checkout_code() {
        $checkoutdir = $clonedir = $this->jailhomedir . "/repo";
        if ($this->repo->truncated_psetdir($this->pset)
            && $this->pset->directory_noslash !== "") {
            $clonedir .= "/{$this->pset->directory_noslash}";
        }

        fwrite($this->logstream, "++ mkdir $checkoutdir\n");
        if (!mkdir($clonedir, 0777, true)) {
            throw new RunnerException("Can’t initialize user repo in jail");
        }

        $root = SiteLoader::$root;
        $repodir = "{$root}/repo/repo{$this->repo->cacheid}";

        // need a branch to check out a specific commit
        $branch = "jailcheckout_" . Conf::$now;
        if ($this->run_and_log("cd " . escapeshellarg($repodir) . " && git branch $branch " . $this->info->commit_hash())) {
            throw new RunnerException("Can’t create branch for checkout");
        }

        // make the checkout
        $status = $this->run_and_log("cd " . escapeshellarg($clonedir) . " && "
                                     . "if test ! -d .git; then git init --shared=group; fi && "
                                     . "git fetch --depth=1 -p " . escapeshellarg($repodir) . " $branch && "
                                     . "git reset --hard " . $this->info->commit_hash());

        $this->run_and_log("cd " . escapeshellarg($repodir) . " && git branch -D $branch");

        if ($status) {
            throw new RunnerException("Can’t check out code into jail");
        }

        if ($this->run_and_log("cd " . escapeshellarg($clonedir) . " && rm -rf .git .gitcheckout")) {
            throw new RunnerException("Can’t clean up checkout in jail");
        }

        // create overlay
        if (($overlay = $this->overlay())) {
            $this->checkout_overlay($checkoutdir, $overlay);
        }
    }

    /** @param list<RunOverlayConfig> $overlayfiles */
    function checkout_overlay($checkoutdir, $overlayfiles) {
        foreach ($overlayfiles as $ro) {
            if (preg_match('/(?:\.tar|\.tar\.[gx]z|\.t[bgx]z|\.tar\.bz2)\z/i', $ro->file)) {
                $c = "cd " . escapeshellarg($checkoutdir) . " && tar -xf " . escapeshellarg($ro->file);
                foreach ($ro->exclude ?? [] as $xf) {
                    $c .= " --exclude " . escapeshellarg($xf);
                }
                $x = $this->run_and_log($c);
            } else {
                fwrite($this->logstream, "++ cp " . escapeshellarg($ro->file) . " " . escapeshellarg($checkoutdir) . "\n");
                $rslash = strrpos($ro->file, "/");
                $x = !copy($ro->file, $checkoutdir . substr($ro->file, $rslash));
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
            if (preg_match('{\A[A-Za-z_][A-Za-z_0-9]*\z}', $k)
                && !preg_match('{\A(?:PATH|MAKE|HOME|SHELL|POSIXLY_CORRECT|TMPDIR|LANG|USER|LOGNAME|SSH.*|PS\d|HISTFILE|LD_LIBRARY_PATH|HOST|HOSTNAME|TERM|TERMCAP|EDITOR|PAGER|MANPATH)\z}', $k)) {
                if (preg_match('{\A[-A-Za-z0-9_:/ .,]*\z}', $v)) {
                    $mk[] = "$k = $v\n";
                }
                $sh[] = "$k=" . escapeshellarg($v) . "\n";
            }
        }
        file_put_contents($this->jailhomedir . "/config.mk", join("", $mk));
        file_put_contents($this->jailhomedir . "/config.sh", join("", $sh));
    }


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
        $queue = $result->fetch_object();
        Dbl::free($result);
        if ($queue && $queue->repoid == $this->repoid) {
            return $queue;
        } else {
            return null;
        }
    }

    private function clean_queue($qconf) {
        assert($this->queueid !== null);
        $runtimeout = isset($qconf->runtimeout) ? $qconf->runtimeout : 300;
        $result = $this->conf->qe("select * from ExecutionQueue where queueclass=? and queueid<?", $this->runner->queue, $this->queueid);
        while (($row = $result->fetch_object())) {
            // remove dead items from queue
            // - pidfile contains "0\n": child has exited, remove it
            // - pidfile specified but not there
            // - no pidfile & last update < 30sec ago
            // - running for more than 5min (configurable)
            if (($row->runat > 0
                 && $row->lockfile
                 && $this->running_checkt($row->lockfile) != $row->runat)
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

    function make_queue() {
        if (!isset($this->runner->queue)) {
            return null;
        }

        if ($this->queueid === null) {
            $nconcurrent = null;
            if (isset($this->runner->nconcurrent)
                && $this->runner->nconcurrent > 0) {
                $nconcurrent = $this->runner->nconcurrent;
            }
            $this->conf->qe("insert into ExecutionQueue set queueclass=?, repoid=?, insertat=?, updateat=?, runat=0, status=0, nconcurrent=?, psetid=?, runnername=?, bhash=?",
                    $this->runner->queue, $this->repoid,
                    Conf::$now, Conf::$now, $nconcurrent,
                    $this->pset->id, $this->runner->name,
                    hex2bin($this->info->commit_hash()));
            $this->queueid = $this->conf->dblink->insert_id;
        } else {
            $this->conf->qe("update ExecutionQueue set updateat=? where queueid=?",
                    Conf::$now, $this->queueid);
        }
        $queue = $this->load_queue();

        $qconf = $this->conf->config->_queues->{$this->runner->queue} ?? null;
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


    function check($qreq) {
        // recent or checkup
        if ($qreq->check === "recent") {
            $checkt = 0;
            $n = 0;
            $envts = $this->environment_timestamp();
            foreach ($this->logged_checkts() as $t) {
                if ($t > $envts
                    && ($s = $this->job_info($t))
                    && $s->done
                    && $s->runner === $this->runner->name
                    && $s->hash === $this->info->commit_hash()) {
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
        $rct = $this->running_checkt();
        if (($rct == $this->checkt && ($qreq->stop ?? "") !== "" && $qreq->stop !== "0")
            || ($rct == $this->checkt && ($qreq->write ?? "") !== "")) {
            if (($qreq->write ?? "") !== "") {
                $this->write($qreq->write);
            }
            if ($qreq->stop) {
                // "ESC Ctrl-C" is captured by pa-jail
                $this->write("\x1b\x03");
            }
            $now = microtime(true);
            do {
                usleep(10);
                $answer = $this->full_json($offset);
            } while ($qreq->stop
                     && ($rct = $this->running_checkt()) == $this->checkt
                     && microtime(true) - $now < 0.1);
        } else {
            $answer = $this->full_json($offset);
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


    function cleanup() {
        if ($this->pidfile) {
            unlink($this->pidfile);
        }
        if ($this->pidfile && $this->inputfifo) {
            unlink($this->inputfifo);
        }
    }
}
