<?php
// runlogger.php -- Peteramati class managing run logs
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class RunLogger {
    /** @var Pset */
    public $pset;
    /** @var Repository */
    public $repo;
    /** @var null|int|false */
    private $_active_job;

    function __construct(Pset $pset, Repository $repo) {
        $this->pset = $pset;
        $this->repo = $repo;
    }

    /** @return bool */
    function mkdirs() {
        $logdir = $this->log_dir();
        return is_dir($logdir)
            || mk_site_subdir(substr($logdir, strlen(SiteLoader::$root)), 02770);
    }

    /** @return string */
    function log_dir() {
        $root = SiteLoader::$root;
        return "{$root}/log/run{$this->repo->cacheid}.pset{$this->pset->id}";
    }

    /** @param string $suffix
     * @return string */
    function log_file($suffix) {
        $root = SiteLoader::$root;
        return "{$root}/log/run{$this->repo->cacheid}.pset{$this->pset->id}/repo{$this->repo->repoid}.pset{$this->pset->id}{$suffix}";
    }

    // log_file(".pid")           PID of active runner
    // log_file(".{$T}.log")      output of runner with runat=jobid=$T
    // log_file(".{$T}.log.time") timing information for output
    // log_file(".{$T}.in")       FIFO for communication

    /** @return string */
    function pid_file() {
        return $this->log_file(".pid");
    }

    /** @param int $jobid
     * @return string */
    function job_prefix($jobid) {
        return $this->log_file(".{$jobid}");
    }

    /** @param int $jobid
     * @return string */
    function output_file($jobid) {
        return $this->log_file(".{$jobid}.log");
    }

    /** @param string $fn
     * @return int|false */
    static function active_job_at($fn) {
        if (($f = @fopen($fn, "r"))) {
            $s = stream_get_contents($f);
            $runat = false;
            if (($sp = strpos($s, " ")) > 0) {
                $w = substr($s, 0, $sp);
                if (ctype_digit($w)) {
                    $runat = (int) $w;
                }
            }
            if (flock($f, LOCK_SH | LOCK_NB)) {
                if ($runat
                    && strpos($s, "-i") !== false
                    && str_ends_with($fn, ".pid")) {
                    @unlink(substr($fn, 0, -4) . ".{$runat}.in");
                }
                unlink($fn);
                flock($f, LOCK_UN);
                $result = false;
            } else {
                $result = $runat ? : 1;
            }
            fclose($f);
            return $result;
        } else {
            return false;
        }
    }

    /** @return int|false */
    function active_job() {
        $this->_active_job = $this->_active_job ?? self::active_job_at($this->pid_file());
        return $this->_active_job;
    }

    function invalidate_active_job() {
        $this->_active_job = null;
    }

    /** @return list<int> */
    function past_jobs() {
        $a = [];
        foreach (glob($this->log_file(".*.log")) as $f) {
            $rp = strlen($f) - 4;
            $lp = strrpos($f, ".", -5);
            $t = substr($f, $lp + 1, $rp - $lp - 1);
            if (ctype_digit($t)) {
                $a[] = intval($t);
            }
        }
        rsort($a);
        return $a;
    }

    /** @param ?string $hash
     * @return int|false */
    function complete_job(RunnerConfig $runner, $hash = null) {
        $n = 0;
        $envts = $runner->environment_timestamp();
        foreach ($this->past_jobs() as $t) {
            if ($t > $envts
                && ($rr = $this->job_response($t))
                && $rr->runner === $runner->name
                && ($hash === null || $rr->hash === $hash)
                && $rr->done) {
                return $t;
            } else if ($n >= 200) {
                break;
            }
            ++$n;
        }
        return false;
    }

    /** @param int $jobid
     * @param string $data */
    function job_write($jobid, $data) {
        $logbase = $this->job_prefix($jobid);
        $proc = proc_open(SiteLoader::$root . "/jail/pa-writefifo " . escapeshellarg("{$logbase}.in"),
                          [["pipe", "r"]], $pipes);
        if ($pipes[0]) {
            fwrite($pipes[0], $data);
            fclose($pipes[0]);
        }
        if ($proc) {
            proc_close($proc);
        }
    }

    /** @param int $jobid
     * @param ?int $offset
     * @return ?RunResponse */
    function job_response($jobid, $offset = null) {
        $logbase = $this->job_prefix($jobid);
        $logfile = "{$logbase}.log";
        if (($shortread = $offset === null || $offset > 32768)) {
            $s = @file_get_contents($logfile, false, null, 0, 8192);
        } else {
            $s = @file_get_contents($logfile, false, null, 0);
        }

        if ($s === false
            || !str_starts_with($s, "++ {")
            || ($pos = strpos($s, "\n")) === false
            || !($j = json_decode(substr($s, 3, $pos - 3)))) {
            $rr = new RunResponse;
            $rr->ok = false;
            $rr->pset = $this->pset->urlkey;
            $rr->repoid = $this->repo ? $this->repo->repoid : 0;
            $rr->error = true;
            $rr->message = $s === false ? "Log missing." : "Log corrupted.";
            return $rr;
        }

        $rr = RunResponse::make_log($j);
        $rr->ok = true;
        $rr->timestamp = $jobid;
        $rr->done = $this->active_job() !== $jobid;

        if ($offset !== null) {
            if ($shortread) {
                $s = @file_get_contents($logfile, false, null, max($offset, 0));
            } else {
                $s = substr($s, max($offset, 0));
            }
            if ($s === false) {
                $s = "";
            }

            // Fix up $data if it is not valid UTF-8.
            if (!is_valid_utf8($s)) {
                $s = UnicodeHelper::utf8_truncate_invalid($s);
                if (!is_valid_utf8($s)) {
                    $s = UnicodeHelper::utf8_replace_invalid($s);
                }
            }

            $rr->data = $s;
            $rr->offset = max($offset, 0);
            $rr->end_offset = $rr->offset + strlen($s);

            // Get time data, if it exists
            $runner = $this->pset->all_runners[$rr->runner];
            if ($runner && $runner->timed_replay) {
                $rr->timed = true;
                if ($offset <= 0
                    && $rr->done
                    && ($time = @file_get_contents("{$logbase}.log.time")) !== false) {
                    $rr->time_data = $time;
                    if ($runner->timed_replay !== true) {
                        $rr->time_factor = $runner->timed_replay;
                    }
                }
            }
        }

        return $rr;
    }
}
