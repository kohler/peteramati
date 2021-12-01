<?php
// runqueue.php -- Peteramati script for progressing the execution queue
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

$ConfSitePATH = preg_replace('/\/batch\/[^\/]+/', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

class RunQueueBatch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $all;
    /** @var bool */
    public $verbose = false;
    /** @var list<QueueItem> */
    public $running = [];

    /** @param bool $all */
    function __construct(Conf $conf, $all) {
        $this->conf = $conf;
        $this->all = $all;
    }

    function load() {
        $this->running = [];
        $qs = new QueueStatus;
        $result = $this->conf->qe("select * from ExecutionQueue where status>=0 order by runorder asc, queueid asc limit 100");
        while (($qix = QueueItem::fetch($this->conf, $result))) {
            if ($qix->status > 0 || $qs->nrunning < $qs->nconcurrent) {
                $old_status = $qix->status;
                $qix->substantiate($qs);
                if ($qix->status > 0) {
                    $this->running[] = $qix;
                }
                if ($this->verbose) {
                    $this->report($qix, $old_status);
                }
            }
        }
        Dbl::free($result);
    }

    function check() {
        $qs = new QueueStatus;
        foreach ($this->running as $qix) {
            $old_status = $qix->status;
            $qix->substantiate($qs);
            if ($this->verbose) {
                $this->report($qix, $old_status);
            }
        }
        return $qs->nrunning >= $qs->nconcurrent;
    }

    /** @param QueueItem $qi
     * @param int $old_status */
    function report($qi, $old_status) {
        $info = $qi->info();
        $id = "~{$info->user->username}/{$info->pset->urlkey}/" . $qi->hash() . "/{$qi->runnername}";
        if ($old_status > 0 && $qi->queueid <= 0) {
            fwrite(STDERR, "$id: completed\n");
        } else if ($old_status > 0) {
            fwrite(STDERR, "$id: running since {$qi->runat} (" . (Conf::$now - $qi->runat) . "s)\n");
        } else if ($qi->queueid <= 0) {
            fwrite(STDERR, "$id: removed\n");
        } else if ($qi->status > 0) {
            fwrite(STDERR, "$id: started at {$qi->runat}\n");
        } else if ($old_status === 0) {
            fwrite(STDERR, "$id: waiting for {$qi->runorder}\n");
        } else if ($old_status < 0 && $qi->status === 0) {
            fwrite(STDERR, "$id: scheduled\n");
        } else {
            fwrite(STDERR, "$id: delayed\n");
        }
    }

    function run() {
        $this->load();
        while (!empty($this->running)) {
            while ($this->check()) {
                sleep(5);
                Conf::set_current_time(time());
            }
            $this->load();
        }
    }

    /** @return RunQueueBatch */
    static function parse_args(Conf $conf, $argv) {
        $arg = getopt_rest($argv, "aV", ["all", "verbose"]);
        $rqb = new RunQueueBatch($conf, isset($arg["all"]) || isset($arg["a"]));
        if (isset($arg["verbose"]) || isset($arg["V"])) {
            $rqb->verbose = true;
        }
        return $rqb;
    }
}

try {
    RunQueueBatch::parse_args($Conf, $argv)->run();
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
