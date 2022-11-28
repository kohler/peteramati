<?php
// runqueue.php -- Peteramati script for progressing the execution queue
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once(dirname(__DIR__) . "/src/init.php");

class RunQueueBatch {
    /** @var Conf */
    public $conf;
    /** @var 'list'|'clean'|'once'|'complete'|'list-broken-chains'|'cancel-broken-chains' */
    public $mode;
    /** @var ?int */
    public $count;
    /** @var list<string> */
    public $words;
    /** @var bool */
    public $verbose = false;
    /** @var list<QueueItem> */
    private $running = [];
    /** @var bool */
    private $any_completed = false;
    /** @var int */
    private $nreports = 0;

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

    function list() {
        $result = $this->conf->qe("select * from ExecutionQueue
                where status<?
                order by runorder asc, queueid asc"
                . ($this->count !== null ? " limit {$this->count}" : ""),
            QueueItem::STATUS_CANCELLED);
        $n = 1;
        while (($qix = QueueItem::fetch($this->conf, $result))) {
            $chain = $qix->chain ? " C{$qix->chain}" : "";
            $s = $qix->status_text(true);
            if ($qix->unscheduled()) {
                $t = $qix->insertat;
            } else if ($qix->scheduled()) {
                $t = $qix->scheduleat;
            } else {
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
                    $this->report(STDOUT, $qix, $old_status);
                }
            }
        }
        Dbl::free($result);
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

    /** @return array{q:list<int>,c:list<int>} */
    private function parse_words() {
        $q = $c = [];
        foreach ($this->words as $w) {
            if ($w === "broken" && $this->mode === "cancel") {
                $c = array_merge($c, $this->broken_chains());
                continue;
            }
            $f = substr($w, 0, 1);
            $n = substr($w, $f === "c" || $f === "C" || $f === "#" ? 1 : 0);
            if ($n === "" || !ctype_digit($n)) {
                throw new CommandLineException("`runqueue.php {$this->mode}` takes queue and chain IDs");
            }
            if ($f === "c" || $f === "C") {
                $c[] = intval($n);
            } else {
                $q[] = intval($n);
            }
        }
        return ["q" => $q, "c" => $c];
    }

    function schedule() {
        $qc = $this->parse_words();
        if (!empty($qc["c"])) {
            $result = $this->conf->qe("select * from ExecutionQueue
                where status<? and chain?a
                order by runorder asc, queueid asc",
                QueueItem::STATUS_CANCELLED, $qc["c"]);
            $sqi = [];
            while (($qix = QueueItem::fetch($this->conf, $result))) {
                if ($qix->status() >= QueueItem::STATUS_SCHEDULED) {
                    $sqi[$qix->chain] = null;
                } else if (!array_key_exists($qix->chain, $sqi)) {
                    $sqi[$qix->chain] = $qix;
                }
            }
            Dbl::free($result);
            foreach ($sqi as $qix) {
                if ($qix) {
                    $qc["q"][] = $qix->queueid;
                }
            }
        }
        if (!empty($qc["q"])) {
            $result = $this->conf->qe("select * from ExecutionQueue
                    where status<=? and queueid?a",
                QueueItem::STATUS_UNSCHEDULED, $qc["q"]);
            while (($qix = QueueItem::fetch($this->conf, $result))) {
                $qix->schedule(0);
            }
            Dbl::free($result);
        }
    }

    function load() {
        $this->running = [];
        $this->any_completed = false;
        $this->nreports = 0;
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
                    $this->report(STDOUT, $qix, $old_status);
                }
                $this->any_completed = $this->any_completed || $qix->stopped();
            }
        }
        Dbl::free($result);
        if ($this->verbose && $this->nreports > 0) {
            fwrite(STDOUT, "\n");
        }
        return !empty($this->running);
    }

    function check() {
        $qs = new QueueStatus;
        $this->nreports = 0;
        foreach ($this->running as $qix) {
            if (!$qix->stopped()) {
                $old_status = $qix->status();
                $qix->step($qs);
                if ($this->verbose) {
                    $this->report(STDOUT, $qix, $old_status);
                }
                $this->any_completed = $this->any_completed || $qix->stopped();
            }
        }
        if ($this->verbose && $this->nreports > 0) {
            fwrite(STDOUT, "\n");
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

    function cancel() {
        $qc = $this->parse_words();
        $qf = $qv = [];
        if (!empty($qc["q"])) {
            $qf[] = "queueid?a";
            $qv[] = $qc["q"];
        }
        if (!empty($qc["c"])) {
            $qf[] = "chain?a";
            $qv[] = $qc["c"];
        }
        if (!empty($qf)) {
            $this->conf->qe("update ExecutionQueue
                    set status=?
                    where status<? and (" . join(" or ", $qf) . ")",
                QueueItem::STATUS_CANCELLED,
                QueueItem::STATUS_WORKING, ...$qv);
        }
    }

    function pause() {
        $qc = $this->parse_words();
        foreach ($qc["c"] as $chainid) {
            $this->conf->qe("update ExecutionQueue
                    set status=?
                    where status<? and chain=?
                    order by runorder asc, queueid asc limit 1",
                QueueItem::STATUS_PAUSED,
                QueueItem::STATUS_WORKING, $chainid);
        }
        if (!empty($qc["q"])) {
            $this->conf->qe("update ExecutionQueue
                    set status=?
                    where status<? and queueid?a",
                QueueItem::STATUS_PAUSED,
                QueueItem::STATUS_WORKING, $qc["q"]);
        }
    }

    /** @param resource $f
     * @param QueueItem $qi
     * @param int $old_status */
    function report($f, $qi, $old_status) {
        ++$this->nreports;
        $id = $qi->unparse_key();
        $chain = $qi->chain ? " C{$qi->chain}" : "";
        if ($old_status > 0 && $qi->stopped()) {
            fwrite($f, "$id: completed\n");
        } else if ($old_status > 0) {
            fwrite($f, "$id: running " . self::unparse_time($qi->runat) . "{$chain}\n");
        } else if ($qi->stopped()) {
            fwrite($f, "$id: removed\n");
        } else if ($qi->working()) {
            fwrite($f, "$id: started " . self::unparse_time($qi->runat) . "{$chain}\n");
        } else if ($old_status === 0) {
            fwrite($f, "$id: waiting " . self::unparse_time($qi->scheduleat) . "{$chain}\n");
        } else if ($qi->scheduled()) {
            fwrite($f, "$id: scheduled " . self::unparse_time($qi->scheduleat) . "{$chain}\n");
        } else {
            fwrite($f, "$id: delayed " . self::unparse_time($qi->insertat) . "{$chain}\n");
        }
    }

    function run() {
        if ($this->mode === "list") {
            $this->list();
        } else if ($this->mode === "clean") {
            $this->clean();
        } else if ($this->mode === "once") {
            $this->load();
        } else if ($this->mode === "complete") {
            $this->execute();
        } else if ($this->mode === "schedule") {
            $this->schedule();
        } else if ($this->mode === "cancel") {
            $this->cancel();
        } else if ($this->mode === "pause") {
            $this->pause();
        } else if ($this->mode === "list-broken-chains") {
            foreach ($this->broken_chains() as $chain) {
                fwrite(STDOUT, "C{$chain}\n");
            }
        } else if ($this->mode === "cancel-broken-chains") {
            $this->words = ["broken"];
            $this->cancel();
        }
    }

    /** @return RunQueueBatch */
    static function parse_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "q,query !",
            "n:,count: {n} =N Print at most N items",
            "x,execute !",
            "1 Execute !",
            "c,clean !",
            "schedule[] =QUEUEID Schedule QUEUEID",
            "cancel[] =QUEUEID Cancel QUEUEID (or C<CHAINID>)",
            "V,verbose",
            "help"
        )->helpopt("help")
         ->subcommand(
            "list Print queue (default)",
            "clean Clean queue",
            "once Execute queue once",
            "complete Execute queue to completion",
            "schedule Schedule jobs",
            "cancel Cancel jobs or chains",
            "pause Pause jobs or chains",
            "list-broken-chains List broken chains",
            "cancel-broken-chains Cancel all broken chains"
        )->description("php batch/runqueue.php SUBCOMMAND [OPTIONS]")
         ->parse($argv);

        $modeargs = [
            ["q", "list"], ["x", "complete"], ["1", "once"],
            ["c", "clean"]
        ];
        $self = new RunQueueBatch($conf);
        if (isset($arg["_subcommand"])) {
            $self->mode = $arg["_subcommand"];
        }
        foreach ($modeargs as $ma) {
            if (isset($arg[$ma[0]])) {
                $self->mode = $self->mode ?? $ma[1];
                if ($self->mode !== $ma[1]) {
                    throw new CommandLineException("`-{$ma[0]}` option conflicts with subcommand");
                }
            }
        }
        $self->words = $arg["_"];
        if ($self->mode === null) {
            $self->mode = "list";
        }
        if (isset($arg["V"])) {
            $self->verbose = true;
        }
        if (isset($arg["n"])) {
            $self->count = $arg["n"];
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
