<?php
// runqueue.php -- Peteramati script for progressing the execution queue
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    CommandLineException::$default_exit_status = 2;
}


class RunQueue_Batch {
    /** @var Conf */
    public $conf;
    /** @var 'list'|'list-chains'|'clean'|'once'|'complete'|'list-broken-chains'|'cancel-broken-chains'|'randomize'|'pause'|'resume' */
    public $mode;
    /** @var ?int */
    public $count;
    /** @var list<string> */
    public $words;
    /** @var bool */
    public $verbose = false;
    /** @var ?list<int> */
    public $list_status;
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
     * @param bool $neg
     * @return string */
    static function unparse_time($t, $neg = false) {
        $s = "@{$t}";
        if ($t && ($neg || $t <= Conf::$now)) {
            $delta = $neg ? $t - Conf::$now : Conf::$now - $t;
            $s .= " (" . unparse_interval($delta) . ")";
        }
        return $s;
    }

    function list() {
        if ($this->list_status !== null) {
            $qf = "status?a";
            $qstatus = $this->list_status;
        } else {
            $qf = "status<?";
            $qstatus = QueueItem::STATUS_CANCELLED;
        }
        $result = $this->conf->qe("select * from ExecutionQueue
                where {$qf}
                order by status>? desc, runorder asc, queueid asc"
                . ($this->count !== null ? " limit {$this->count}" : ""),
            $qstatus, QueueItem::STATUS_UNSCHEDULED);
        $n = 1;
        while (($qix = QueueItem::fetch($this->conf, $result))) {
            $s = $qix->status_text(true);
            if ($qix->unscheduled()) {
                $t = self::unparse_time($qix->insertat);
            } else if ($qix->scheduled()) {
                $t = self::unparse_time($qix->runorder, true);
            } else {
                $t = self::unparse_time($qix->runat);
            }
            $rest = $qix->chain ? " C{$qix->chain}" : "";
            if (isset($qix->tags)) {
                $rest .= " " . $qix->tags_text();
            }
            if ($qix->has_response() && $this->verbose) {
                $rest .= "\n     " . $qix->output_file();
            }
            fwrite(STDOUT, str_pad("{$n}. ", 5)
                   . "#{$qix->queueid}  "
                   . $qix->unparse_key() . " {$s} {$t}{$rest}\n");
            ++$n;
        }
        Dbl::free($result);
    }

    function list_chains() {
        if ($this->list_status !== null) {
            $qf = "status?a";
            $qstatus = $this->list_status;
        } else {
            $qf = "status<?";
            $qstatus = QueueItem::STATUS_CANCELLED;
        }
        $result = $this->conf->qe("select chain, status, tags, min(insertat) insertat, min(runorder) runorder, min(runat) runat, min(queueid) queueid, count(*) group_count from ExecutionQueue
                where chain is not null and {$qf}
                group by chain, status, tags",
            $qstatus);
        $chains = [];
        while (($ch = QueueItem::fetch($this->conf, $result))) {
            $chains[$ch->chain][] = $ch;
        }
        Dbl::free($result);
        usort($chains, function ($a, $b) {
            $cha = $a[0];
            $chb = $b[0];
            if (($cha->status > QueueItem::STATUS_UNSCHEDULED) !== ($chb->status > QueueItem::STATUS_UNSCHEDULED)) {
                return $cha->status > QueueItem::STATUS_UNSCHEDULED ? -1 : 1;
            } else if ($cha->runorder !== $chb->runorder) {
                return $cha->runorder <=> $chb->runorder;
            } else {
                return $cha->queueid <=> $chb->queueid;
            }
        });
        $n = 1;
        foreach ($chains as $chainlist) {
            usort($chainlist, function ($a, $b) {
                return $b->status() <=> $a->status();
            });
            $stati = [];
            $tags = null;
            foreach ($chainlist as $ch) {
                $stati[] = $ch->group_count . " " . $ch->status_text();
                $chtags = $ch->tags_text();
                $tags = $tags ?? $chtags;
                if ($tags !== $chtags) {
                    $tags = "#<varied>";
                }
            }
            $ch = $chainlist[0];
            fwrite(STDOUT, str_pad("{$n}. ", 5)
                   . "C{$ch->chain} " . self::unparse_time($ch->insertat)
                   . ($tags === "" ? "" : " {$tags}")
                   . ": " . join(", ", $stati)
                   . "\n");
            ++$n;
        }
    }

    function clean() {
        $qs = new QueueState;
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

    /** @param bool $is_resume
     * @return 0|1 */
    function schedule($is_resume) {
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
        $nsched = 0;
        if (!empty($qc["q"])) {
            $result = $this->conf->qe("select * from ExecutionQueue
                    where status<=? and queueid?a",
                QueueItem::STATUS_UNSCHEDULED, $qc["q"]);
            while (($qix = QueueItem::fetch($this->conf, $result))) {
                $old_status = $qix->status();
                if (!$is_resume || $old_status === QueueItem::STATUS_PAUSED) {
                    $qix->schedule(0);
                    if ($this->verbose) {
                        $this->report(STDOUT, $qix, $old_status);
                    }
                    ++$nsched;
                }
            }
            Dbl::free($result);
        }
        return $nsched > 0 ? 0 : 1;
    }

    function load() {
        $this->running = [];
        $this->any_completed = false;
        $this->nreports = 0;
        $result = $this->conf->qe("select * from ExecutionQueue
                where status>=? and status<?
                order by runorder asc, queueid asc limit ?",
            QueueItem::STATUS_SCHEDULED, QueueItem::STATUS_CANCELLED, 200);
        $qs = QueueState::fetch_list($this->conf, $result);
        while (($qix = $qs->shift())) {
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
        if ($this->verbose && $this->nreports > 0) {
            fwrite(STDOUT, "\n");
        }
        return !empty($this->running);
    }

    function check() {
        $qs = new QueueState;
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

    /** @return 0|1 */
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
        if (empty($qf)) {
            return 1;
        }
        $result = $this->conf->qe("update ExecutionQueue
                set status=?
                where status<? and (" . join(" or ", $qf) . ")",
            QueueItem::STATUS_CANCELLED,
            QueueItem::STATUS_WORKING, ...$qv);
        return $result->affected_rows > 0 ? 0 : 1;
    }

    /** @return 0|1 */
    function pause() {
        $qc = $this->parse_words();
        $any = false;
        foreach ($qc["c"] as $chainid) {
            $result = $this->conf->qe("update ExecutionQueue
                    set status=?
                    where status<? and chain=?
                    order by runorder asc, queueid asc limit 1",
                QueueItem::STATUS_PAUSED,
                QueueItem::STATUS_WORKING, $chainid);
            $any = $any || $result->affected_rows > 0;
        }
        if (!empty($qc["q"])) {
            $result = $this->conf->qe("update ExecutionQueue
                    set status=?
                    where status<? and queueid?a",
                QueueItem::STATUS_PAUSED,
                QueueItem::STATUS_WORKING, $qc["q"]);
            $any = $any || $result->affected_rows > 0;
        }
        return $any ? 0 : 1;
    }

    function randomize() {
        $qc = $this->parse_words();
        $qf = $qv = [];
        if (!empty($qc["c"])) {
            $qf[] = "chain?a";
            $qv[] = $qc["c"];
        }
        if (!empty($qc["q"])) {
            $qf[] = "queueid?a";
            $qv[] = $qc["q"];
        }
        if (empty($qf)) {
            $qf[] = "true";
        }

        foreach ([QueueItem::STATUS_SCHEDULED, QueueItem::STATUS_UNSCHEDULED] as $status) {
            $result = $this->conf->qe("select * from ExecutionQueue
                    where status=? and (" . join(" or ", $qf) . ")",
                $status, ...$qv);
            $qids = $qros = [];
            while (($qi = QueueItem::fetch($this->conf, $result))) {
                $qids[] = $qi->queueid;
                $qros[] = $qi->runorder;
            }
            Dbl::free($result);

            // Fisher-Yates shuffle
            for ($i = 0; $i < count($qids) - 1; ++$i) {
                $j = mt_rand($i, count($qids) - 1);
                if ($i !== $j) {
                    $result = $this->conf->qe("update ExecutionQueue q1, ExecutionQueue q2
                            set q1.runorder=?, q2.runorder=?
                            where q1.queueid=? and q1.runorder=? and q1.status=?
                            and q2.queueid=? and q2.runorder=? and q2.status=?",
                        $qros[$j], $qros[$i],
                        $qids[$i], $qros[$i], $status,
                        $qids[$j], $qros[$j], $status);
                    if ($result->affected_rows) {
                        $tmp = $qros[$i];
                        $qros[$i] = $qros[$j];
                        $qros[$j] = $tmp;
                    }
                }
            }
        }
    }

    /** @param resource $f
     * @param QueueItem $qi
     * @param int $old_status */
    function report($f, $qi, $old_status) {
        ++$this->nreports;
        $id = "#{$qi->queueid} " . $qi->unparse_key();
        $chain = $qi->chain ? " C{$qi->chain}" : "";
        if ($old_status > 0 && $qi->stopped()) {
            fwrite($f, "$id: completed\n");
        } else if ($old_status > 0) {
            fwrite($f, "$id: running " . self::unparse_time($qi->runat) . "{$chain}\n");
        } else if ($qi->stopped()) {
            fwrite($f, "$id: removed\n");
        } else if ($qi->working()) {
            fwrite($f, "$id: started " . self::unparse_time($qi->runat) . "{$chain}\n");
        } else if ($qi->scheduled()) {
            fwrite($f, "$id: scheduled " . self::unparse_time($qi->runorder, true) . "{$chain}\n");
        } else {
            fwrite($f, "$id: delayed " . self::unparse_time($qi->insertat) . "{$chain}\n");
        }
    }

    /** @return int */
    function run() {
        if ($this->mode === "list") {
            $this->list();
        } else if ($this->mode === "list-chains") {
            $this->list_chains();
        } else if ($this->mode === "clean") {
            $this->clean();
        } else if ($this->mode === "once") {
            $this->load();
        } else if ($this->mode === "complete") {
            $this->execute();
        } else if ($this->mode === "schedule") {
            return $this->schedule(false);
        } else if ($this->mode === "cancel") {
            return $this->cancel();
        } else if ($this->mode === "pause") {
            return $this->pause();
        } else if ($this->mode === "resume") {
            return $this->schedule(true);
        } else if ($this->mode === "randomize") {
            $this->randomize();
        } else if ($this->mode === "list-broken-chains") {
            foreach ($this->broken_chains() as $chain) {
                fwrite(STDOUT, "C{$chain}\n");
            }
        } else if ($this->mode === "cancel-broken-chains") {
            $this->words = ["broken"];
            $this->cancel();
        }
        return 0;
    }

    /** @return RunQueue_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "q,query !",
            "n:,count: {n} =N Print at most N items",
            "w,working Print only working items",
            "x,execute !",
            "1 ! Execute",
            "c,clean !",
            "schedule[] =QUEUEID ! Schedule QUEUEID",
            "cancel[] =QUEUEID ! Cancel QUEUEID (or C<CHAINID>)",
            "V,verbose",
            "help"
        )->helpopt("help")
         ->subcommand(
            "list Print queue (default)",
            "list-chains Print summary of chains",
            "clean Clean queue",
            "once Execute queue once",
            "complete Execute queue to completion",
            "schedule Schedule jobs or chains",
            "cancel Cancel jobs or chains",
            "pause Pause jobs or chains",
            "resume Resume jobs or chains",
            "randomize Randomize order of unscheduled jobs",
            "list-broken-chains List broken chains",
            "cancel-broken-chains Cancel all broken chains"
        )->description("php batch/runqueue.php SUBCOMMAND [OPTIONS]")
         ->otheropt(false)
         ->parse($argv);

        $modeargs = [
            ["q", "list"], ["x", "complete"], ["1", "once"],
            ["c", "clean"]
        ];
        $self = new RunQueue_Batch($conf);
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
        if (isset($arg["w"])) {
            $self->list_status[] = QueueItem::STATUS_WORKING;
        }
        return $self;
    }
}


if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(RunQueue_Batch::make_args(Conf::$main, $argv)->run());
}
