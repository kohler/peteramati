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
    /** @var list<QueueItem> */
    public $running = [];

    /** @param bool $all */
    function __construct(Conf $conf, $all) {
        $this->conf = $conf;
        $this->all = $all;
    }

    function load() {
        try {
            $this->running = [];
            $qs = new QueueStatus;
            $result = $this->conf->qe("select * from ExecutionQueue where status>=0 order by runorder asc, queueid asc limit 100", $qi->runorder);
            while (($qix = QueueItem::fetch($info->conf, $result))) {
                if ($qix->status > 0 || $qs->nrunning < $qs->nconcurrent) {
                    $qix->substantiate($qs);
                    if ($qix->status > 0) {
                        $this->running[] = $qix;
                    }
                }
            }
            Dbl::free($result);
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
                sleep(5);
            }
            $this->load();
        }
    }

    /** @return RunQueueBatch */
    static function parse_args(Conf $conf, $argv) {
        $arg = getopt_rest($argv, "a", ["all"]);
        return new RunQueueBatch($conf, isset($arg["all"]) || isset($arg["a"]));
    }
}

try {
    RunQueueBatch::parse_args($Conf, $argv)->run();
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
