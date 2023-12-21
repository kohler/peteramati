<?php
// runqueue.php -- Peteramati script for progressing the execution queue
// HotCRP and Peteramati are Copyright (c) 2006-2023 Eddie Kohler and others
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
    public $all = false;
    /** @var bool */
    public $quiet = false;
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

    function __construct(Conf $conf, $mode) {
        $this->conf = $conf;
        $this->mode = $mode;
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
     * @return int */
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
        return $nsched;
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

    /** @return int */
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
        if ($this->all) {
            $qf[] = "true";
        }
        if (empty($qf)) {
            return 0;
        }
        $result = $this->conf->qe("update ExecutionQueue
                set status=?
                where status<? and (" . join(" or ", $qf) . ")",
            QueueItem::STATUS_CANCELLED,
            QueueItem::STATUS_WORKING, ...$qv);
        return $result->affected_rows;
    }

    /** @return int */
    function pause() {
        $n = 0;
        $qc = $this->parse_words();
        foreach ($qc["c"] as $chainid) {
            $result = $this->conf->qe("update ExecutionQueue
                    set status=?
                    where status<? and chain=?
                    order by runorder asc, queueid asc limit 1",
                QueueItem::STATUS_PAUSED,
                QueueItem::STATUS_WORKING, $chainid);
            $n += $result->affected_rows;
        }
        if (!empty($qc["q"])) {
            $result = $this->conf->qe("update ExecutionQueue
                    set status=?
                    where status<? and queueid?a",
                QueueItem::STATUS_PAUSED,
                QueueItem::STATUS_WORKING, $qc["q"]);
            $n += $result->affected_rows;
        }
        return $n;
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
        if ($qi->cancelled()) {
            fwrite($f, "{$id}: cancelled\n");
        } else if ($qi->stopped()) {
            fwrite($f, "{$id}: completed\n");
        } else if ($old_status > 0) {
            fwrite($f, "{$id}: running " . self::unparse_time($qi->runat) . "{$chain}\n");
        } else if ($qi->working()) {
            fwrite($f, "{$id}: started " . self::unparse_time($qi->runat) . "{$chain}\n");
        } else if ($qi->scheduled()) {
            fwrite($f, "{$id}: scheduled " . self::unparse_time($qi->runorder, true) . "{$chain}\n");
        } else {
            fwrite($f, "{$id}: delayed " . self::unparse_time($qi->insertat) . "{$chain}\n");
        }
    }

    /** @param int $n
     * @return 0|1 */
    private function report_no_work($n) {
        if ($n === 0 && !$this->quiet) {
            fwrite(STDERR, "batch/runqueue.php {$this->mode}: Nothing to do\n");
            return 1;
        } else {
            return 0;
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
            return $this->report_no_work($this->schedule(false));
        } else if ($this->mode === "cancel") {
            return $this->report_no_work($this->cancel());
        } else if ($this->mode === "pause") {
            return $this->report_no_work($this->pause());
        } else if ($this->mode === "resume") {
            return $this->report_no_work($this->schedule(true));
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

    /** @var list<string>
     * @readonly */
    static public $add_options = [
        "p:,pset: !add Problem set",
        "r:,runner:,run: !add Runner name",
        "e::,if-needed:: {n} =N !add Run only if needed",
        "ensure !",
        "u[]+,user[]+ !add Match these users",
        "H:,hash:,commit: !add Use this commit",
        "C:,c:,chain: !add Set chain ID",
        "at-end,last !add Schedule after all previous jobs",
        "t[],tag[] !add Add tag",
        "s[],setting[] !add Set NAME=VALUE",
        "event-source,eventsource !add Listen for EventSource connections",
        "ordered !add Do not randomize user order"
    ];

    /** @return RunQueue_Batch|RunEnqueue_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "n:,count: {n} =N !list Print at most N items",
            "w,working !list Print only working items",
            "all !cancel Cancel all jobs",
            self::$add_options,
            "q,quiet",
            "V,verbose",
            "help"
        )->helpopt("help")
         ->subcommand(
            "list Print queue (default)",
            "add Add jobs to queue (`add --help` for more)",
            "once Execute queue once",
            "complete Execute queue to completion",
            "clean Clean queue",
            "list-chains Print summary of chains",
            "schedule Schedule jobs or chains",
            "cancel Cancel jobs or chains",
            "pause Pause jobs or chains",
            "resume Resume jobs or chains",
            "randomize Randomize order of unscheduled jobs",
            "list-broken-chains List broken chains",
            "cancel-broken-chains Cancel all broken chains"
        )->description("Usage: php batch/runqueue.php list [-n N] [--working]
       php batch/runqueue.php add -p PSET -r RUNNER [-u USERS...] [-e]
       php batch/runqueue.php once
       php batch/runqueue.php complete")
         ->otheropt(false)
         ->parse($argv);

        if (($mode = $arg["_subcommand"] ?? null) === null) {
            $mode = isset($arg["p"]) || isset($arg["r"]) ? "add" : "list";
        }

        if ($mode === "add") {
            return RunEnqueue_Batch::make_parsed_args($conf, $arg);
        }

        $self = new RunQueue_Batch($conf, $mode);
        $self->words = $arg["_"];
        $self->all = isset($arg["all"]);
        $self->quiet = isset($arg["q"]);
        $self->verbose = isset($arg["V"]);
        if (isset($arg["n"])) {
            $self->count = $arg["n"];
        }
        if (isset($arg["w"])) {
            $self->list_status[] = QueueItem::STATUS_WORKING;
        }
        return $self;
    }
}

class RunEnqueue_Batch {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var RunnerConfig */
    public $runner;
    /** @var int */
    public $if_needed = 0;
    /** @var bool */
    public $ordered = false;
    /** @var bool */
    public $at_end = false;
    /** @var bool */
    public $quiet = false;
    /** @var bool */
    public $verbose = false;
    /** @var ?int */
    public $chainid;
    /** @var ?list<string> */
    public $tags;
    /** @var ?array<string,string> */
    public $runsettings;
    /** @var int */
    public $sset_flags;
    /** @var bool */
    public $eventsource = false;
    /** @var list<string> */
    public $usermatch = [];
    /** @var ?string */
    public $hash;

    /** @param list<string> $usermatch */
    function __construct(Pset $pset, RunnerConfig $runner, $usermatch = []) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->runner = $runner;
        $this->sset_flags = 0;
        $umatch = [];
        foreach ($usermatch as $u) {
            while (str_ends_with($u, ",")) {
                $u = substr($u, 0, -1);
            }
            if ($u !== "") {
                $umatch[] = $u;
            }
        }
        if (count($umatch) === 1 && $umatch[0] === "dropped") {
            $this->sset_flags |= StudentSet::DROPPED;
        } else {
            $this->sset_flags |= StudentSet::ENROLLED;
        }
        if (count($umatch) === 1 && $umatch[0] === "college") {
            $this->sset_flags |= StudentSet::COLLEGE;
        } else if (count($umatch) === 1 && $umatch[0] === "extension") {
            $this->sset_flags |= StudentSet::DCE;
        }
        if ($this->sset_flags === StudentSet::ENROLLED) {
            foreach ($umatch as $s) {
                if (str_starts_with($s, "[anon")) {
                    $this->usermatch[] = preg_replace('/([\[\]])/', '\\\\$1', $s . "*");
                } else {
                    $this->usermatch[] = "*{$s}*";
                }
            }
        }
    }

    /** @param string $s
     * @return bool */
    function match($s) {
        foreach ($this->usermatch as $m) {
            if (fnmatch($m, $s))
                return true;
        }
        return empty($this->usermatch);
    }

    /** @return bool */
    function test_user(Contact $user) {
        if (empty($this->usermatch)) {
            $user->set_anonymous($this->pset->anonymous);
            return true;
        } else if ($this->match($user->email)
                   || $this->match($user->github_username)) {
            $user->set_anonymous(false);
            return true;
        } else if ($this->match($user->anon_username)) {
            $user->set_anonymous(true);
            return true;
        } else {
            return false;
        }
    }

    /** @param PsetView $info
     * @param ?string $hash
     * @return string */
    private function unparse_key($info, $hash = null) {
        $hash = $hash ?? $info->hash() ?? "none";
        return "~{$info->user->username}/{$info->pset->urlkey}/{$hash}/{$this->runner->name}";
    }

    /** @return int */
    function run_add() {
        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, $this->sset_flags, [$this, "test_user"]);
        $sset->set_pset($this->pset);
        if (!$this->ordered) {
            $sset->shuffle();
        }
        $chain = $this->chainid ?? QueueItem::new_chain();
        $chainstr = $chain ? " C{$chain}" : "";

        $runorder = QueueItem::unscheduled_runorder();
        if ($this->at_end || $this->chainid) {
            $where = ["status<?"];
            $qv = [$runorder, QueueItem::STATUS_CANCELLED];
            if (!$this->at_end) {
                $where[] = "chain=?";
                $qv[] = $this->chainid;
            }
            $runorder = $this->conf->fetch_ivalue("select greatest(coalesce(max(runorder)+10,0),?) from ExecutionQueue where " . join(" and ", $where), ...$qv);
        }

        $nadded = $nscheduled = 0;
        foreach ($sset as $info) {
            if (!$info->repo) {
                continue;
            }
            if ($this->hash && !$info->set_hash($this->hash, true)) {
                if ($this->verbose) {
                    fwrite(STDERR, $this->unparse_key($info, $this->hash) . ": no such commit on {$info->branch}{$chainstr}\n");
                }
                continue;
            }
            if (!$info->hash()) {
                continue;
            }
            $qi = QueueItem::make_info($info, $this->runner);
            $qi->chain = $chain > 0 ? $chain : null;
            $qi->runorder = $runorder;
            $qi->flags |= QueueItem::FLAG_UNWATCHED;
            if (!$this->eventsource) {
                $qi->flags |= QueueItem::FLAG_NOEVENTSOURCE;
            }
            $qi->ifneeded = $this->if_needed;
            $qi->tags = $this->tags;
            foreach ($this->runsettings ?? [] as $k => $v) {
                $qi->runsettings[$k] = $v;
            }
            if ($this->if_needed > 0
                && $qi->count_compatible_responses($this->verbose, $this->if_needed) >= $this->if_needed) {
                continue;
            }
            $qi->enqueue();
            if (!$qi->chain) {
                $qi->schedule($nscheduled * 10);
                ++$nscheduled;
            }
            if ($this->verbose) {
                fwrite(STDERR, "#{$qi->queueid} " . $qi->unparse_key() . ": create{$chainstr}\n");
            }
            $runorder += 10;
            ++$nadded;
        }
        if ($chain > 0
            && ($qi = QueueItem::by_chain($this->conf, $chain))
            && $qi->schedulable()) {
            $qi->schedule(0);
        }
        return $nadded;
    }

    /** @return int */
    function run() {
        $n = $this->run_add();
        if ($n === 0 && !$this->quiet) {
            fwrite(STDERR, "batch/runqueue.php add: nothing to do\n");
            return 1;
        } else {
            return 0;
        }
    }

    /** @return RunEnqueue_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            array_map(function ($s) { return str_replace(" !add", "", $s); }, RunQueue_Batch::$add_options),
            "q,quiet",
            "V,verbose",
            "help"
        )->helpopt("help")
         ->description("Usage: php batch/runqueue.php add -p PSET -r RUNNER [-u USER...]")
         ->parse($argv);
        return self::make_parsed_args($conf, $arg);
    }

    /** @return RunEnqueue_Batch */
    static function make_parsed_args(Conf $conf, $arg) {
        $pset_arg = $arg["p"] ?? "";
        $pset = $conf->pset_by_key($pset_arg);
        if (!$pset) {
            $pset_keys = array_values(array_map(function ($p) { return $p->key; }, $conf->psets()));
            throw (new CommandLineException($pset_arg === "" ? "`--pset` required" : "Pset `{$pset_arg}` not found"))->add_context("(Options are " . join(", ", $pset_keys) . ".)");
        }
        $runner_arg = $arg["r"] ?? "";
        $runner = $pset->runner_by_key($runner_arg);
        if (!$runner) {
            $runners = array_keys($pset->runners);
            sort($runners);
            throw (new CommandLineException($runner_arg === "" ? "`--runner` required" : "Runner `{$runner_arg}` not found"))->add_context("(Options are " . join(", ", $runners) . ".)");
        }
        $self = new RunEnqueue_Batch($pset, $runner, $arg["u"] ?? []);
        if (isset($arg["e"])) {
            if ($arg["e"] === false) {
                $self->if_needed = 1;
            } else {
                $self->if_needed = $arg["e"];
            }
        } else if (isset($arg["ensure"])) {
            $self->if_needed = 1;
        }
        $self->ordered = isset($arg["ordered"]);
        $self->at_end = isset($arg["at-end"]);
        $self->quiet = isset($arg["q"]);
        $self->verbose = isset($arg["V"]);
        if (isset($arg["C"])) {
            $chain = $arg["C"][0] === "C" ? substr($arg["C"], 1) : $arg["C"];
            if (ctype_digit($chain)
                && QueueItem::valid_chain(intval($chain))) {
                $self->chainid = intval($chain);
            } else {
                throw new CommandLineException("bad `--chain`");
            }
        }
        if (isset($arg["t"])) {
            foreach ($arg["t"] as $tag) {
                if (!preg_match('/\A' . TAG_REGEX_NOTWIDDLE . '\z/', $tag)) {
                    throw new CommandLineException("bad `--tag`");
                }
                $self->tags[] = $tag;
            }
        }
        if (isset($arg["s"])) {
            foreach ($arg["s"] as $setting) {
                if (!preg_match('/\A([A-Za-z][_A-Za-z0-9]*)=([-._A-Za-z0-9]*)\z/', $setting, $m)) {
                    throw new CommandLineException("bad `--setting`");
                }
                $self->runsettings[$m[1]] = $m[2];
            }
        }
        if (isset($arg["H"])) {
            if (!($hp = CommitRecord::canonicalize_hashpart($arg["H"]))) {
                throw new CommandLineException("bad `--commit`");
            }
            $self->hash = $hp;
        }
        if (isset($arg["event-source"])) {
            $self->eventsource = true;
        }
        return $self;
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(RunQueue_Batch::make_args(Conf::$main, $argv)->run());
}
