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
    /** @var ?list<int> */
    public $schedule_qid;
    /** @var bool */
    public $list_broken_chains = false;
    /** @var bool */
    public $cancel_broken_chains = false;
    /** @var ?list<int> */
    public $cancel_chain;
    /** @var ?list<int> */
    public $cancel_qid;
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
        if ($t && $t <= Conf::$now) {
            $s .= " (" . unparse_interval(Conf::$now - $t) . ")";
        }
        return $s;
    }

    function query() {
        $result = $this->conf->qe("select * from ExecutionQueue
                where status<?
                order by runorder asc, queueid asc",
            QueueItem::STATUS_CANCELLED);
        $n = 1;
        while (($qix = QueueItem::fetch($this->conf, $result))) {
            $chain = $qix->chain ? " C{$qix->chain}" : "";
            if ($qix->unscheduled()) {
                $s = "waiting";
                $t = $qix->insertat;
            } else if ($qix->scheduled()) {
                $s = "scheduled";
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
        $result = $this->conf->qe("select * from ExecutionQueue
                where status>=? and status<?
                order by runorder asc, queueid asc",
            QueueItem::STATUS_SCHEDULED, QueueItem::STATUS_CANCELLED);
        $n = 1;
        while (($qix = QueueItem::fetch($this->conf, $result))) {
            if ($qix->working() || $qix->abandoned()) {
                $old_status = $qix->status();
                $qix->step($qs);
                if ($this->verbose && $qix->stopped()) {
                    $this->report($qix, $old_status);
                }
            }
        }
        Dbl::free($result);
    }

    function schedule() {
        $qs = new QueueStatus;
        $result = $this->conf->qe("select * from ExecutionQueue
                where status=? and queueid?a",
            QueueItem::STATUS_UNSCHEDULED, $this->schedule_qid);
        while (($qix = QueueItem::fetch($this->conf, $result))) {
            $qix->schedule(0);
        }
        Dbl::free($result);
    }

    function load() {
        $this->running = [];
        $this->any_completed = false;
        $qs = new QueueStatus;
        $result = $this->conf->qe("select * from ExecutionQueue
                where status>=? and status<?
                order by runorder asc, queueid asc
                limit 100",
            QueueItem::STATUS_SCHEDULED, QueueItem::STATUS_CANCELLED);
        while (($qix = QueueItem::fetch($this->conf, $result))) {
            if ($qix->working() || $qs->nrunning < $qs->nconcurrent) {
                $old_status = $qix->status();
                $qix->step($qs);
                if ($qix->working()) {
                    $this->running[] = $qix;
                }
                if ($this->verbose) {
                    $this->report($qix, $old_status);
                }
                $this->any_completed = $this->any_completed || $qix->stopped();
            }
        }
        Dbl::free($result);
        return !empty($this->running);
    }

    function check() {
        $qs = new QueueStatus;
        foreach ($this->running as $qix) {
            if (!$qix->stopped()) {
                $old_status = $qix->status();
                $qix->step($qs);
                if ($this->verbose) {
                    $this->report($qix, $old_status);
                }
                $this->any_completed = $this->any_completed || $qix->stopped();
            }
        }
        return $qs->nrunning >= $qs->nconcurrent;
    }

    function execute() {
        while ($this->load()
               || ($this->any_completed && $this->load())) {
            $this->any_completed = false;
            do {
                sleep(5);
                Conf::set_current_time(time());
            } while ($this->check());
            if (!$this->any_completed) {
                sleep(5);
                Conf::set_current_time(time());
            }
        }
    }

    /** @param QueueItem $qi
     * @param int $old_status */
    function report($qi, $old_status) {
        $id = $qi->unparse_key();
        $chain = $qi->chain ? " C{$qi->chain}" : "";
        if ($old_status > 0 && $qi->stopped()) {
            fwrite(STDERR, "$id: completed\n");
        } else if ($old_status > 0) {
            fwrite(STDERR, "$id: running " . self::unparse_time($qi->runat) . "{$chain}\n");
        } else if ($qi->stopped()) {
            fwrite(STDERR, "$id: removed\n");
        } else if ($qi->working()) {
            fwrite(STDERR, "$id: started " . self::unparse_time($qi->runat) . "{$chain}\n");
        } else if ($old_status === 0) {
            fwrite(STDERR, "$id: waiting " . self::unparse_time($qi->scheduleat) . "{$chain}\n");
        } else if ($qi->scheduled()) {
            fwrite(STDERR, "$id: scheduled " . self::unparse_time($qi->scheduleat) . "{$chain}\n");
        } else {
            fwrite(STDERR, "$id: delayed " . self::unparse_time($qi->insertat) . "{$chain}\n");
        }
    }

    /** @return list<int> */
    function broken_chains() {
        $result = $this->conf->qe("select chain, max(status)
                from ExecutionQueue
                where status<?
                group by chain",
            QueueItem::STATUS_CANCELLED);
        $chains = [];
        while (($row = $result->fetch_row())) {
            $chainid = intval($row[0]);
            $status = intval($row[1]);
            if ($status < QueueItem::STATUS_SCHEDULED) {
                $chains[] = $chainid;
            }
        }
        Dbl::free($result);
        return $chains;
    }

    function run() {
        if ($this->list_broken_chains) {
            foreach ($this->broken_chains() as $chain) {
                fwrite(STDOUT, "C{$chain}\n");
            }
            exit(0);
        }
        if (!empty($this->cancel_chain) || $this->cancel_broken_chains) {
            $cancel_chains = array_merge($this->cancel_chain ?? [], $this->broken_chains());
            $this->conf->qe("update ExecutionQueue
                    set status=?
                    where status<? and chain?a",
                QueueItem::STATUS_CANCELLED,
                QueueItem::STATUS_WORKING, $cancel_chains);
        }
        if (!empty($this->cancel_qid)) {
            $this->conf->qe("update ExecutionQueue
                    set status=?
                    where status<? and queueid?a",
                QueueItem::STATUS_CANCELLED,
                QueueItem::STATUS_WORKING, $this->cancel_qid);
        }
        if (!empty($this->schedule_qid)) {
            $this->schedule();
        }
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
            "schedule[] =QUEUEID Schedule QUEUEID",
            "cancel[] =QUEUEID Cancel QUEUEID (or C<CHAINID>)",
            "list-broken-chains List broken chains",
            "cancel-broken-chains Cancel all broken chains",
            "V,verbose",
            "help"
        )->helpopt("help")->description("php batch/runqueue.php")->parse($argv);
        $self = new RunQueueBatch($conf);
        if (isset($arg["q"]) || isset($arg["c"]) || isset($arg["1"])) {
            $self->is_execute = false;
        }
        if (isset($arg["q"])) {
            $self->is_query = true;
        }
        if (isset($arg["c"])) {
            $self->is_clean = true;
        }
        foreach ($arg["schedule"] ?? [] as $x) {
            if (preg_match('/\A[#]?(\d+)\z/', $x, $m)) {
                $self->schedule_qid[] = intval($m[1]);
            } else {
                throw new CommandLineException("bad `--schedule` argument");
            }
        }
        if (isset($arg["list-broken-chains"])) {
            $self->list_broken_chains = true;
        }
        if (isset($arg["cancel-broken-chains"])) {
            $self->cancel_broken_chains = true;
        }
        foreach ($arg["cancel"] ?? [] as $x) {
            if (preg_match('/\A[C#]?(\d+)\z/', $x, $m)) {
                if (str_starts_with($x, "C")) {
                    $self->cancel_chain[] = intval($m[1]);
                } else {
                    $self->cancel_qid[] = intval($m[1]);
                }
            } else {
                throw new CommandLineException("bad `--cancel` argument");
            }
        }
        if (isset($arg["1"])) {
            $self->is_execute1 = true;
        }
        if (isset($arg["x"])) {
            $self->is_execute = true;
        }
        if (isset($arg["V"])) {
            $self->verbose = true;
        }
        return $self;
    }
}

try {
    RunQueueBatch::parse_args($Conf, $argv)->run();
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
