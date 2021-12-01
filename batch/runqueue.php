<?php
// runqueue.php -- Peteramati script for reporting grading anomalies
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

$ConfSitePATH = preg_replace('/\/batch\/[^\/]+/', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "a", ["all"]);

class RunQueueBatch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $all;
    /** @var list<QueueItem> */
    public $running;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->all = isset($arg["a"]) || isset($arg["all"]);
    }

    function load() {
        try {
            $this->running = [];
            $qs = new QueueStatus;
            $result = $this->conf->qe("select * from ExecutionQueue where status>=0 order by runorder asc, queueid asc limit 100", $qi->runorder);
            while (($qix = QueueItem::fetch($info->conf, $result))) {
                if ($qix->status === 0 && $qs->nrunning >= $qs->nconcurrent) {
                    break;
                }
                $qix->substantiate($qs);
                if ($qix->status > 0) {
                    $this->running[] = $qix;
                }
            }
            Dbl::free($result);
        } catch (Exception $e) {
            fwrite(STDERR, $e->getMessage());
            exit(1);
        }        
    }

    function check() {
        $qs = new QueueStatus;
        foreach ($this->running as $qix) {
            $qix->substantiate($qs);
        }
        return $qs->nrunning >= $qs->nconcurrent;
    }

    function run() {
        $this->load();
        while (!empty($this->running)) {
            while ($this->check()) {
            }
            $this->load();
        }
        exit(0);
    }
}

(new RunQueueBatch($Conf, $arg))->run();
