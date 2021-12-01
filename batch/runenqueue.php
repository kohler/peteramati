<?php
// runenqueue.php -- Peteramati script for adding to the execution queue
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

$ConfSitePATH = preg_replace('/\/batch\/[^\/]+/', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "p:r:", ["pset:", "run:", "runner:"]);

class RunEnqueueBatch {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var RunnerConfig */
    public $runner;
    /** @var bool */
    public $is_ensure = false;
    /** @var bool */
    public $verbose = false;
    /** @var int */
    public $sset_flags;
    /** @var ?string */
    public $usermatch;

    function __construct(Pset $pset, RunnerConfig $runner, $usermatch = null) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->runner = $runner;
        $this->sset_flags = 0;
        if ($usermatch === "dropped") {
            $this->sset_flags |= StudentSet::DROPPED;
        } else {
            $this->sset_flags |= StudentSet::ENROLLED;
        }
        if ($usermatch === "college") {
            $this->sset_flags |= StudentSet::COLLEGE;
        } else if ($usermatch === "extension") {
            $this->sset_flags |= StudentSet::DCE;
        }
        if ($this->sset_flags === StudentSet::ENROLLED) {
            $this->usermatch = $usermatch;
        }
    }

    function check() {
        $qs = new QueueStatus;
        foreach ($this->running as $qix) {
            $qix->substantiate($qs);
        }
        return $qs->nrunning >= $qs->nconcurrent;
    }

    /** @return int */
    function run() {
        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, $this->sset_flags);
        $sset->set_pset($this->pset);
        $nu = 0;
        $chain = QueueItem::new_chain();
        $usermatch = $this->usermatch ? "*{$this->usermatch}*" : null;
        foreach ($sset as $info) {
            if ($info->is_grading_commit()
                && ($usermatch === null
                    || fnmatch($usermatch, $info->user->email)
                    || fnmatch($usermatch, $info->user->github_username)
                    || fnmatch($usermatch, $info->user->anon_username))) {
                $qi = QueueItem::make_info($info, $this->runner);
                $qi->chain = $chain;
                $qi->runorder = QueueItem::unscheduled_runorder($nu * 10);
                $qi->flags = QueueItem::FLAG_UNWATCHED | ($this->is_ensure ? QueueItem::FLAG_ENSURE : 0);
                $qi->enqueue();
                if ($nu === 0) {
                    $qi->schedule(0);
                }
                if ($this->verbose) {
                    fwrite(STDERR, "~{$info->user->username}/{$this->pset->urlkey}/" . $info->hash() . "/{$this->runner->name}: schedule\n");
                }
                ++$nu;
            }
        }
        return $nu;
    }

    /** @return RunEnqueueBatch */
    static function make_args(Conf $conf, $argv) {
        $arg = getopt_rest($argv, "p:r:u:eV", ["pset:", "run:", "runner:", "user:", "ensure", "verbose"]);
        $psetkey = $arg["pset"] ?? $arg["p"] ?? "";
        if ($psetkey === "") {
            throw new Error("missing `--pset`");
        }
        $pset = $conf->pset_by_key($psetkey);
        if (!$pset) {
            throw new Error("no such pset");
        }
        $runnerkey = $arg["runner"] ?? $arg["run"] ?? $arg["r"] ?? "";
        if ($runnerkey === "") {
            throw new Error("missing `--runner`");
        }
        $runner = $pset->runners[$runnerkey] ?? null;
        if (!$runner) {
            throw new Error("no such runner");
        }
        $reb = new RunEnqueueBatch($pset, $runner, $arg["user"] ?? $arg["u"] ?? null);
        if (isset($arg["ensure"]) || isset($arg["e"])) {
            $reb->is_ensure = true;
        }
        if (isset($arg["verbose"]) || isset($arg["V"])) {
            $reb->verbose = true;
        }
        return $reb;
    }
}

try {
    if (RunEnqueueBatch::make_args($Conf, $argv)->run() > 0) {
        exit(0);
    } else {
        fwrite(STDERR, "nothing to do\n");
        exit(1);
    }
} catch (Error $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
