<?php

class RunnerException extends Exception {
}

class RunnerState {
    public $info;
    public $repo;
    public $pset;
    public $runner;

    public $logdir;

    public $checkt = null;
    private $logfile = null;
    private $timingfile = null;
    private $lockfile = null;
    private $inputfifo = null;
    private $logstream;
    private $username;
    private $userhome;
    private $jaildir;
    private $jailhomedir;

    private $_logged_checkts;
    private $_running_checkts;

    function __construct(PsetView $info, RunnerConfig $runner, $checkt = null) {
        global $ConfSitePATH;
        $this->info = $info;
        $this->repo = $info->repo;
        $this->pset = $info->pset;
        $this->runner = $runner;

        $this->logdir = $ConfSitePATH . "/log/run" . $this->repo->cacheid
            . ".pset" . $this->pset->id;
        if (!is_dir($this->logdir)) {
            $old_umask = umask(0);
            if (!mkdir($this->logdir, 02770, true))
                throw new RunnerException("Cannot create log directory");
            umask($old_umask);
        }

        if ($checkt)
            $this->set_checkt($checkt);
    }

    function running_checkts() {
        if ($this->_running_checkts === null) {
            $logfs = glob($this->logdir . "/repo" . $this->repo->repoid
                          . ".pset" . $this->pset->id . ".*.log.pid");
            $this->_running_checkts = [];
            foreach ($logfs as $f) {
                $rp = strlen($f);
                $lp = strrpos($f, ".", -9);
                $this->_running_checkts[] = intval(substr($f, $lp, $rp - $lp));
            }
            rsort($this->_running_checkts);
        }
        return $this->_running_checkts;
    }

    function logged_checkts() {
        if ($this->_logged_checkts === null) {
            $logfs = glob($this->logdir . "/repo" . $this->repo->repoid
                          . ".pset" . $this->pset->id . ".*.log");
            $this->_logged_checkts = [];
            foreach ($logfs as $f) {
                $rp = strlen($f);
                $lp = strrpos($f, ".", -5);
                $this->_logged_checkts[] = intval(substr($f, $lp, $rp - $lp));
            }
            rsort($this->_logged_checkts);
        }
        return $this->_logged_checkts;
    }


    function expand($x) {
        global $Conf;
        if (strpos($x, '${') !== false) {
            $x = str_replace('${REPOID}', $this->repo->repoid, $x);
            $x = str_replace('${PSET}', $this->pset->id, $x);
            $x = str_replace('${CONFDIR}', "conf/", $x);
            $x = str_replace('${SRCDIR}', "src/", $x);
            $x = str_replace('${HOSTTYPE}', $Conf->opt("hostType", ""), $x);
            $x = str_replace('${COMMIT}', $this->info->commit_hash(), $x);
            $x = str_replace('${HASH}', $this->info->commit_hash(), $x);
        }
        return $x;
    }

    private function run_and_log($command, $bg = false) {
        fwrite($this->logstream, "++ $command\n");
        system("($command) </dev/null >>" . escapeshellarg($this->logfile) . " 2>&1" . ($bg ? " &" : ""), $status);
        return $status;
    }


    function set_checkt($checkt) {
        if (is_int($checkt) && $checkt > 0)
            $this->checkt = $checkt;
        else if (ctype_digit($checkt))
            $this->checkt = intval($checkt);
        else if (preg_match(',\.(\d+)\.log(?:\.lock|\.pid)?\z,', $checkt, $m))
            $this->checkt = intval($m[1]);
        else
            $this->checkt = null;
        return $this->checkt !== null;
    }

    function generic_json() {
        return (object) [
            "ok" => true, "repoid" => $this->repo->repoid,
            "pset" => $this->pset->urlkey, "timestamp" => $this->checkt
        ];
    }

    function status_json($json = null) {
        global $Now;
        if (!$json)
            $json = (object) [];
        $logfn = $this->info->runner_logfile($this->checkt);
        $lockfn = $logfn . ".pid";
        $pid_data = @file_get_contents($lockfn);
        if (ctype_digit(trim($pid_data))
            && !posix_kill(trim($pid_data), 0)
            && posix_get_last_error() == 3 /* ESRCH */)
            $pid_data = "dead\n";
        if ($pid_data === false || $pid_data === "0\n") {
            $json->done = true;
            $json->status = "done";
        } else if ($pid_data === "" || ctype_digit(trim($pid_data))) {
            $json->done = false;
            $json->status = "working";
            if ($Now - $this->checkt > 600)
                $json->status = "old";
        } else {
            $json->done = true;
            $json->status = "dead";
        }
        if ($json->done && $pid_data !== false) {
            unlink($lockfn);
            unlink($logfn . ".in");
        }
        return $json;
    }

    function parse_timevals($time) {
        $elems = explode("\n", $time);
        function parse_elem($elem) {
            $parts = explode(" ", $elem);
            return array(
                "time" => intval($parts[0]),
                "offset" => intval($parts[1])
            );
        }

        return array_map("parse_elem", $elems);
    }

    function full_json($offset = null) {
        if (!$this->checkt)
            return false;
        $json = $this->generic_json();
        $this->status_json($json);
        if ($offset !== null) {
            $logfn = $this->info->runner_logfile($this->checkt);
            $timefn = $logfn . ".time";
            $data = @file_get_contents($logfn, false, null, max($offset, 0));
            if ($data === false)
                return (object) ["error" => true, "message" => "No such log"];
            // Fix up $data if it is not valid UTF-8.
            if (!is_valid_utf8($data)) {
                $data = UnicodeHelper::utf8_truncate_invalid($data);
                if (!is_valid_utf8($data))
                    $data = UnicodeHelper::utf8_replace_invalid($data);
            }
            if ($json->done) {
                // Get time data, if it exists
                $time = @file_get_contents($timefn);
                if ($time !== false)
                    $json->time_data = $this->parse_timevals($time);
            }
            $json->data = $data;
            $json->offset = max($offset, 0);
            $json->lastoffset = $json->offset + strlen($data);
        }
        return $json;
    }

    function write($data) {
        global $ConfSitePATH;
        if (!$this->checkt)
            return false;
        $logfn = $this->info->runner_logfile($this->checkt);
        $proc = proc_open("$ConfSitePATH/jail/pa-writefifo " . escapeshellarg($logfn . ".in"),
                          [["pipe", "r"]], $pipes);
        if ($pipes[0]) {
            fwrite($pipes[0], $data);
            fclose($pipes[0]);
        }
        if ($proc)
            proc_close($proc);
        return true;
    }


    function is_recent_job_running() {
        $save_checkt = $this->checkt;
        $answer = false;
        foreach ($this->running_checkts() as $checkt) {
            $this->checkt = $checkt;
            $lstatus = $this->status_json();
            if ($lstatus && $lstatus->status === "working") {
                $answer = true;
                break;
            }
        }
        $this->checkt = $save_checkt;
        return $answer;
    }

    function start($queue) {
        global $ConfSitePATH, $Conf;
        assert($this->checkt === null && $this->logfile === null);

        // collect user information
        $this->username = "jail61user";
        if ($this->runner->username)
            $this->username = $this->runner->username;
        else if ($this->pset->run_username)
            $this->username = $this->pset->run_username;
        if (!preg_match('/\A\w+\z/', $this->username))
            throw new RunnerException("Bad run_username");

        $info = posix_getpwnam($this->username);
        $this->userhome = $info ? $info["dir"] : "/home/jail61";
        $this->userhome = preg_replace(',/+\z,', '', $this->userhome);

        $this->jaildir = preg_replace(',/+\z,', '', $this->expand($this->pset->run_dirpattern));
        if (!$this->jaildir)
            throw new RunnerException("Bad run_dirpattern");

        $this->jailhomedir = $this->jaildir . "/" . preg_replace(',\A/+,', '', $this->userhome);

        if (!chdir($ConfSitePATH))
            throw new RunnerException("Can’t cd to main directory");
        if (!is_executable("jail/pa-jail"))
            throw new RunnerException("The pa-jail program has not been compiled");

        // create logfile and lockfile
        $this->checkt = time();
        $this->logfile = $this->info->runner_logfile($this->checkt);
        $this->timingfile = $this->logfile . ".time";
        $this->lockfile = $this->logfile . ".pid";
        file_put_contents($this->lockfile, "");
        $this->inputfifo = $this->logfile . ".in";
        if (!posix_mkfifo($this->inputfifo, 0660))
            $this->inputfifo = null;
        $this->logstream = fopen($this->logfile, "a");
        if ($queue)
            Dbl::qe("update ExecutionQueue set runat=?, status=1, lockfile=?, inputfifo=? where queueid=?",
                    $this->checkt, $this->lockfile, $this->inputfifo, $queue->queueid);
        register_shutdown_function(array($this, "cleanup"));

        // create jail
        $this->remove_old_jails();
        if ($this->run_and_log("jail/pa-jail add " . escapeshellarg($this->jaildir) . " " . escapeshellarg($this->username)))
            throw new RunnerException("Can’t initialize jail");

        // check out code
        $this->checkout_code();

        // save commit settings
        if (($runsettings = $this->info->commit_info("runsettings")))
            $this->add_run_settings($runsettings);

        // actually run
        $command = "echo; jail/pa-jail run"
            . " -t" . escapeshellarg($this->timingfile)
            . " -p" . escapeshellarg($this->lockfile);
        $skeletondir = $this->pset->run_skeletondir ? : $Conf->opt("run_skeletondir");
        $binddir = $this->pset->run_binddir ? : $Conf->opt("run_binddir");
        if ($skeletondir && $binddir && !is_dir("$skeletondir/proc"))
            $binddir = false;
        if ($skeletondir && $binddir) {
            $binddir = preg_replace(',/+\z,', '', $binddir);
            $contents = "/ <- " . $skeletondir . " [bind-ro]\n"
                . $this->userhome . " <- " . $this->jailhomedir . " [bind]\n";
            $command .= " -u" . escapeshellarg($this->jailhomedir)
                . " -F" . escapeshellarg($contents);
            $homedir = $binddir;
        } else {
            $command .= " -h -f" . escapeshellarg($this->expand($this->pset->run_jailfiles));
            if ($skeletondir)
                $command .= " -S" . escapeshellarg($skeletondir);
            $homedir = $this->jaildir;
        }
        if ($this->runner->timeout > 0)
            $command .= " -T" . $this->runner->timeout;
        else if ($this->runner->timeout == null && $this->pset->run_timeout > 0)
            $command .= " -T" . $this->pset->run_timeout;
        if ($this->inputfifo)
            $command .= " -i" . escapeshellarg($this->inputfifo);
        $command .= " " . escapeshellarg($homedir)
            . " " . escapeshellarg($this->username)
            . " TERM=xterm-256color"
            . " " . escapeshellarg($this->expand($this->runner->command));
        $this->lockfile = null; /* now owned by command */
        return $this->run_and_log($command);
    }

    private function remove_old_jails() {
        global $Now;
        while (is_dir($this->jaildir)) {
            $Now = time();
            $newdir = $this->jaildir . "~." . gmstrftime("%Y%m%dT%H%M%S", $Now);
            if ($this->run_and_log("jail/pa-jail mv " . escapeshellarg($this->jaildir) . " " . escapeshellarg($newdir)))
                throw new RunnerException("Can’t remove old jail");

            $this->run_and_log("jail/pa-jail rm " . escapeshellarg($newdir), true);
            clearstatcache(false, $this->jaildir);
        }
    }

    private function checkout_code() {
        global $ConfSitePATH, $Now;

        $checkoutdir = $clonedir = $this->jailhomedir . "/repo";
        if ($this->repo->truncated_psetdir($this->pset)
            && $this->pset->directory_noslash !== "")
            $clonedir .= "/" . $this->pset->directory_noslash;

        fwrite($this->logstream, "++ mkdir $checkoutdir\n");
        if (!mkdir($clonedir, 0777, true))
            throw new RunnerException("Can’t initialize user repo in jail");

        $repodir = $ConfSitePATH . "/repo/repo" . $this->repo->cacheid;

        // need a branch to check out a specific commit
        $branch = "jailcheckout_$Now";
        if ($this->run_and_log("cd " . escapeshellarg($repodir) . " && git branch $branch " . $this->info->commit_hash()))
            throw new RunnerException("Can’t create branch for checkout");

        // make the checkout
        $status = $this->run_and_log("cd " . escapeshellarg($clonedir) . " && "
                                     . "if test ! -d .git; then git init --shared=group; fi && "
                                     . "git fetch " . escapeshellarg($repodir) . " $branch && "
                                     . "git reset --hard " . $this->info->commit_hash());

        $this->run_and_log("cd " . escapeshellarg($repodir) . " && git branch -D $branch");

        if ($status)
            throw new RunnerException("Can’t check out code into jail");

        if ($this->run_and_log("cd " . escapeshellarg($clonedir) . " && rm -rf .git .gitcheckout"))
            throw new RunnerException("Can’t clean up checkout in jail");

        // create overlay
        $overlay = $this->runner->overlay;
        if (!isset($overlay))
            $overlay = $this->pset->run_overlay;
        if ((string) $overlay !== "")
            $this->checkout_overlay($checkoutdir, $overlay);
    }

    function checkout_overlay($checkoutdir, $overlayfile) {
        global $ConfSitePATH;

        if ($overlayfile[0] != "/")
            $overlayfile = $ConfSitePATH . "/" . $overlayfile;
        $overlayfile = $this->expand($overlayfile);
        if ($this->run_and_log("cd " . escapeshellarg($checkoutdir) . " && tar -xf " . escapeshellarg($overlayfile)))
            throw new RunnerException("Can’t unpack overlay");

        $checkout_instructions = @file_get_contents($checkoutdir . "/.gitcheckout");
        if ($checkout_instructions)
            foreach (explode("\n", $checkout_instructions) as $text)
                if (substr($text, 0, 3) === "rm:")
                    $this->run_and_log("cd " . escapeshellarg($checkoutdir) . " && rm -rf " . escapeshellarg(substr($text, 3)));
    }

    private function add_run_settings($s) {
        $x = array();
        foreach ((array) $s as $k => $v)
            $x[] = "$k = $v\n";
        file_put_contents($this->jailhomedir . "/config.mk", join("", $x), true);
    }


    function cleanup() {
        if ($this->lockfile)
            unlink($this->lockfile);
        if ($this->lockfile && $this->inputfifo)
            unlink($this->inputfifo);
    }
}
