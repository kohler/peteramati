<?php
// runlogger.php -- Peteramati class managing run logs
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class RunLogger {
    /** @var Repository */
    public $repo;
    /** @var Pset */
    public $pset;

    function __construct(Repository $repo, Pset $pset) {
        $this->repo = $repo;
        $this->pset = $pset;
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
        return $this->active_job_at($this->pid_file());
    }

    /** @return list<int> */
    function past_jobs() {
        $a = [];
        foreach (glob($this->log_file(".*.log")) as $f) {
            $rp = strlen($f);
            $lp = strrpos($f, ".", -5);
            $t = substr($f, $lp + 1, $rp - $lp - 1);
            if (ctype_digit($t)) {
                $a[] = intval($t);
            }
        }
        rsort($a);
        return $a;
    }

    /** @param int $jobid
     * @return ?object */
    function job_info($jobid) {
        if (($t = @file_get_contents($this->output_file($jobid), false, null, 0, 4096))
            && str_starts_with($t, "++ {")
            && ($pos = strpos($t, "\n"))
            && ($j = json_decode(substr($t, 3, $pos - 3)))
            && is_object($j)) {
            return $j;
        } else {
            return null;
        }
    }
}
