<?php
// runqueue.php -- Peteramati script for progressing the execution queue
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once(dirname(__DIR__) . "/src/init.php");

class RunQueueBatch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $is_query = false;
    /** @var bool */
    public $is_clean = false;
    /** @var bool */
    public $is_execute = false;
    /** @var bool */
    public $is_execute1 = false;
    /** @var bool */
    public $verbose = false;
    /** @var list<QueueItem> */
    private $running = [];
    /** @var bool */
    private $any_completed = false;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @param int $t
     * @return string */
    static function unparse_time($t) {
        $s = "@{$t}";
        if ($t && $t < Conf::$now) {
            $s .= " (" . unparse_interval(Conf::$now - $t) . ")";
        }
        return $s;
    }

    function query() {
        $result = $this->conf->qe("select * from ExecutionQueue order by runorder asc, queueid asc");
        $n = 1;
        while (($qix = QueueItem::fetch($this->conf, $result))) {
            $chain = $qix->chain ? " C{$qix->chain}" : "";
            if ($qix->status < 0) {
                $s = "delayed";
                $t = $qix->insertat;
            } else if ($qix->status === 0) {
                $s = "waiting";
                $t = $qix->scheduleat;
            } else {
                $s = "running";
                $t = $qix->runat;
            }
            fwrite(STDOUT, "{$n}. #{$qix->queueid} " . $qix->unparse_key() . " {$s} " . self::unparse_time($t) . "{$chain}\n");
            ++$n;
        }
        Dbl::free($result);
    }

    function clean() {
        $qs = new QueueStatus;
        $result = $this->conf->qe("select * from ExecutionQueue where status>=0 order by runorder asc, queueid asc");
        $n = 1;
        while (($qix = QueueItem::fetch($this->conf, $result))) {
            if ($qix->status > 0 || $qix->irrelevant()) {
                $old_status = $qix->status;
                $qix->substantiate($qs);
                if ($this->verbose && $qix->deleted) {
                    $this->report($qix, $old_status);
                }
            }
        }
        Dbl::free($result);
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
            if (!$qix->deleted) {
                $old_status = $qix->status;
                $qix->substantiate($qs);
                if ($this->verbose) {
                    $this->report($qix, $old_status);
                }
                $this->any_completed = $this->any_completed || $qix->deleted;
            }
        }
        return $qs->nrunning >= $qs->nconcurrent;
    }

    function execute() {
        $this->load();
        while (!empty($this->running)) {
            $this->any_completed = false;
            while ($this->check()) {
                sleep(5);
                Conf::set_current_time(time());
            }
            if (!$this->any_completed) {
                sleep(5);
                Conf::set_current_time(time());
            }
            $this->load();
        }
    }

    /** @param QueueItem $qi
     * @param int $old_status */
    function report($qi, $old_status) {
        $id = $qi->unparse_key();
        $chain = $qi->chain ? " C{$qi->chain}" : "";
        if ($old_status > 0 && $qi->deleted) {
            fwrite(STDERR, "$id: completed\n");
        } else if ($old_status > 0) {
            fwrite(STDERR, "$id: running " . self::unparse_time($qi->runat) . "{$chain}\n");
        } else if ($qi->deleted) {
            fwrite(STDERR, "$id: removed\n");
        } else if ($qi->status > 0) {
            fwrite(STDERR, "$id: started " . self::unparse_time($qi->runat) . "{$chain}\n");
        } else if ($old_status === 0) {
            fwrite(STDERR, "$id: waiting " . self::unparse_time($qi->scheduleat) . "{$chain}\n");
        } else if ($qi->status === 0) {
            fwrite(STDERR, "$id: scheduled " . self::unparse_time($qi->scheduleat) . "{$chain}\n");
        } else {
            fwrite(STDERR, "$id: delayed " . self::unparse_time($qi->insertat) . "{$chain}\n");
        }
    }

    function run() {
        if ($this->is_query) {
            $this->query();
        }
        if ($this->is_clean) {
            $this->clean();
        }
        if ($this->is_execute1) {
            $this->load();
        }
        if ($this->is_execute) {
            $this->execute();
        }
    }

    /** @return RunQueueBatch */
    static function parse_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "x,execute Execute queue to completion [default]",
            "1 Execute queue once",
            "q,query Print queue",
            "c,clean Clean queue",
            "V,verbose",
            "help"
        )->helpopt("help")->description("php batch/runqueue.php")->parse($argv);
        $rqb = new RunQueueBatch($conf);
        if (isset($arg["q"]) || isset($arg["c"]) || isset($arg["1"])) {
            $rqb->is_execute = false;
        }
        if (isset($arg["q"])) {
            $rqb->is_query = true;
        }
        if (isset($arg["c"])) {
            $rqb->is_clean = true;
        }
        if (isset($arg["1"])) {
            $rqb->is_execute1 = true;
        }
        if (isset($arg["x"])) {
            $rqb->is_execute = true;
        }
        if (isset($arg["V"])) {
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
