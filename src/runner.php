<?php

class RunnerException extends Exception {
}

class RunnerState {
    public $info;
    public $repo;
    public $pset;
    public $runner;
    public $queue;

    public $logdir;

    public $checkt = null;
    private $logfile = null;
    private $lockfile = null;
    private $logstream;
    private $username;
    private $userhome;
    private $jaildir;
    private $jailhomedir;

    public $logged_checkts = array();
    public $running_checkts = array();

    public function __construct($info, $runner, $queue) {
        global $ConfSitePATH;

        $this->info = $info;
        $this->repo = $info->repo;
        $this->pset = $info->pset;
        $this->runner = $runner;
        $this->queue = $queue;

        $this->logdir = $ConfSitePATH . "/log/run" . $this->repo->cacheid
            . ".pset" . $this->pset->id;
        if (!is_dir($this->logdir)) {
            $old_umask = umask(0);
            if (!mkdir($this->logdir, 02770, true))
                throw new RunnerException("cannot create log directory");
            umask($old_umask);
        }

        $logfs = glob($this->logdir . "/repo" . $this->repo->repoid . ".pset" . $this->pset->id . ".*.log*");
        rsort($logfs);
        foreach ($logfs as $logf)
            if (preg_match(',\.(\d+)\.log((?:\.lock)?)\z,', $logf, $m)) {
                if ($m[2])
                    $this->running_checkts[] = intval($m[1]);
                else
                    $this->logged_checkts[] = intval($m[1]);
            }
    }


    public function expand($x) {
        global $Opt;
        if (strpos($x, '${') !== false) {
            $x = str_replace('${REPOID}', $this->repo->repoid, $x);
            $x = str_replace('${PSET}', $this->pset->id, $x);
            $x = str_replace('${CONFDIR}', "conf/", $x);
            $x = str_replace('${SRCDIR}', "src/", $x);
            $x = str_replace('${HOSTTYPE}', defval($Opt, "hostType", ""), $x);
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


    public function is_recent_job_running() {
        foreach ($this->running_checkts as $checkt)
            if (($lstatus = ContactView::runner_status_json($this->info, $checkt))
                && $lstatus->status == "working")
                return true;
        return false;
    }

    public function start() {
        global $ConfSitePATH, $Opt;
        assert($this->checkt === null && $this->logfile === null);

        // collect user information
        $this->username = "jail61user";
        if ($this->runner->username)
            $this->username = $this->runner->username;
        else if ($this->pset->run_username)
            $this->username = $this->pset->run_username;
        if (!preg_match('/\A\w+\z/', $this->username))
            throw new RunnerException("bad run_username");

        $info = posix_getpwnam($this->username);
        $this->userhome = $info ? $info["dir"] : "/home/jail61";
        $this->userhome = preg_replace(',/+\z,', '', $this->userhome);

        $this->jaildir = preg_replace(',/+\z,', '', $this->expand($this->pset->run_dirpattern));
        if (!$this->jaildir)
            throw new RunnerException("bad run_dirpattern");

        $this->jailhomedir = $this->jaildir . "/" . $this->userhome;

        // create logfile and lockfile
        $this->checkt = time();
        $this->logfile = ContactView::runner_logfile($this->info, $this->checkt);
        $this->lockfile = $this->logfile . ".pid";
        file_put_contents($this->lockfile, "");
        $this->logstream = fopen($this->logfile, "a");
        if ($this->queue)
            Dbl::qe("update ExecutionQueue set runat=?, status=1, lockfile=? where queueid=?",
                    $this->checkt, $this->lockfile, $this->queue->queueid);
        register_shutdown_function(array($this, "cleanup"));

        if (!chdir($ConfSitePATH))
            throw new RunnerException("can't cd to main directory");
        if (!is_executable("jail/pa-jail"))
            throw new RunnerException("the pa-jail program has not been compiled");

        // create jail
        $this->remove_old_jails();
        if ($this->run_and_log("jail/pa-jail init " . escapeshellarg($this->jaildir) . " " . escapeshellarg($this->username)))
            throw new RunnerException("can't initialize jail");

        // check out code
        $this->checkout_code();

        // save commit settings
        if (($runsettings = $this->info->commit_info("runsettings")))
            $this->add_run_settings($runsettings);

        // actually run
        $command = "echo; jail/pa-jail run -t"
            . " -p" . escapeshellarg($this->lockfile)
            . " -f" . escapeshellarg($this->expand($this->pset->run_jailfiles));
        if ($this->pset->run_skeletondir)
            $command .= " -S" . escapeshellarg($this->pset->run_skeletondir);
        else if (isset($Opt["run_jailskeleton"]))
            $command .= " -S" . escapeshellarg($Opt["run_jailskeleton"]);
        if ($this->runner->timeout > 0)
            $command .= " -T" . $this->runner->timeout;
        else if ($this->pset->run_timeout > 0)
            $command .= " -T" . $this->pset->run_timeout;
        $command .= " " . escapeshellarg($this->jaildir)
            . " " . escapeshellarg($this->username)
            . " " . escapeshellarg($this->runner->command);
        $this->lockfile = null; /* now owned by command */
        return $this->run_and_log($command, true);
    }

    private function remove_old_jails() {
        global $Now;
        while (is_dir($this->jaildir)) {
            $Now = time();
            $newdir = $this->jaildir . "~." . gmstrftime("%Y%m%dT%H%M%S", $Now);
            if ($this->run_and_log("jail/pa-jail mv " . escapeshellarg($this->jaildir) . " " . escapeshellarg($newdir)))
                throw new RunnerException("can't remove old jail");

            $this->run_and_log("jail/pa-jail rm " . escapeshellarg($newdir), true);
            clearstatcache(false, $this->jaildir);
        }
    }

    private function checkout_code() {
        global $ConfSitePATH, $Now;

        $checkoutdir = $this->jailhomedir . "/repo";
        if (isset($this->repo->truncated_psetdir)
            && defval($this->repo->truncated_psetdir, $this->pset->id)
            && $this->pset->directory_noslash !== "")
            $checkoutdir .= "/" . $this->pset->directory_noslash;

        if (!mkdir($checkoutdir, 0777, true))
            throw new RunnerException("can't initialize user repo in jail");

        $repodir = $ConfSitePATH . "/repo/repo" . $this->repo->cacheid;

        // need a branch to check out a specific commit
        $branch = "jailcheckout_$Now";
        if ($this->run_and_log("cd " . escapeshellarg($repodir) . " && git branch $branch " . $this->info->commit_hash()))
            throw new RunnerException("can't create branch for checkout");

        // make the checkout
        $status = $this->run_and_log("cd " . escapeshellarg($checkoutdir) . " && "
                                     . "if test ! -d .git; then git init --shared=group; fi && "
                                     . "git fetch " . escapeshellarg($repodir) . " $branch && "
                                     . "git reset --hard " . $this->info->commit_hash());

        $this->run_and_log("cd " . escapeshellarg($repodir) . " && git branch -D $branch");

        if ($status)
            throw new RunnerException("can't check out code into jail");

        if ($this->run_and_log("cd " . escapeshellarg($checkoutdir) . " && rm -rf .git .gitcheckout"))
            throw new RunnerException("can't clean up checkout in jail");

        // create overlay
        if (isset($this->pset->run_overlay) && $this->pset->run_overlay != "")
            $this->checkout_overlay($checkoutdir, $this->pset->run_overlay);
    }

    public function checkout_overlay($checkoutdir, $overlayfile) {
        global $ConfSitePATH;

        if ($overlayfile[0] != "/")
            $overlayfile = $ConfSitePATH . "/" . $overlayfile;
        if ($this->run_and_log("cd " . escapeshellarg($checkoutdir) . " && tar -xf " . escapeshellarg($overlayfile)))
            throw new RunnerException("can't unpack overlay");

        $checkout_instructions = @file_get_contents($checkoutdir . "/.gitcheckout");
        if ($checkout_instructions)
            foreach (explode("\n", $checkout_instructions) as $text)
                if (substr($text, 0, 3) === "rm:")
                    $this->run_and_log("cd " . escapeshellarg($checkoutdir) . " && rm -rf " . escapeshellarg(substr($text, 3)));
    }

    private function add_run_settings($s) {
        $x = array();
        foreach ((array) $s as $k => $v)
            $s[] = "$k = $v\n";
        file_put_contents($this->jailhomedir . "/config.mk", join("", $s), true);
    }


    public function cleanup() {
        if ($this->lockfile)
            unlink($this->lockfile);
    }
}
