<?php
// queueitem.php -- Peteramati execution queue entry
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class QueueItem {
    /** @var Conf */
    public $conf;
    /** @var ?QueueState */
    public $qstate;

    /** @var int */
    public $queueid;
    /** @var int */
    public $reqcid;

    /** @var string */
    public $runnername;
    /** @var int */
    public $cid;
    /** @var int */
    public $psetid;
    /** @var int */
    public $repoid;
    /** @var ?string */
    public $bhash;
    /** @var ?array */
    public $runsettings;
    /** @var ?list<string> */
    public $tags;
    /** @var ?list */
    public $ensure;
    /** @var int */
    public $ifneeded;

    /** @var string */
    public $queueclass;
    /** @var int */
    public $flags;
    /** @var ?int */
    public $chain;

    /** @var ?int */
    public $runorder;
    /** @var int */
    public $runstride;
    /** @var int */
    public $insertat;
    /** @var int */
    public $scheduleat;
    /** @var int */
    public $updateat;
    /** @var int */
    public $runat;
    /** @var int */
    private $status;
    /** @var ?string */
    public $lockfile;
    /** @var ?string */
    public $eventsource;

    // not in normal objects
    /** @var ?int */
    public $group_count;

    // object links
    /** @var ?Pset */
    private $_pset;
    /** @var ?Contact */
    private $_user;
    /** @var ?Repository */
    private $_repo;
    /** @var ?PsetView */
    private $_info;
    /** @var ?RunnerConfig */
    private $_runner;
    /** @var int */
    private $_ocache = 0;
    /** @var ?int */
    private $_evaluate_at;
    /** @var mixed */
    private $_evaluate;

    // running a command (`start` and helpers)
    /** @var string */
    private $_jaildir;
    /** @var string */
    private $_jailhomedir;
    /** @var string */
    private $_logfile;
    /** @var resource */
    private $_logstream;
    /** @var ?int */
    private $_runstatus;
    /** @var array<resource> */
    private $_runpipes;
    /** @var ?int */
    public $foreground_command_status;
    /** @var ?string */
    public $last_error;

    const FLAG_UNWATCHED = 1;
    const FLAG_FOREGROUND = 2;
    const FLAG_ANONYMOUS = 4;
    const FLAG_NOEVENTSOURCE = 8;
    const FLAG_FOREGROUND_VERBOSE = 16;

    const STATUS_PAUSED = -2;
    const STATUS_UNSCHEDULED = -1;
    const STATUS_SCHEDULED = 0;
    const STATUS_WORKING = 1;
    const STATUS_CANCELLED = 2;
    const STATUS_DONE = 3;
    const STATUS_EVALUATED = 4;


    private function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    private function db_load() {
        $this->queueid = (int) $this->queueid;
        $this->reqcid = (int) $this->reqcid;

        $this->cid = (int) $this->cid;
        $this->psetid = (int) $this->psetid;
        $this->repoid = (int) $this->repoid;

        $this->flags = (int) $this->flags;
        if ($this->chain !== null) {
            $this->chain = (int) $this->chain;
        }
        if ($this->runsettings !== null) {
            /** @phan-suppress-next-line PhanTypeMismatchArgumentInternal */
            $j = json_decode($this->runsettings);
            $this->runsettings = is_object($j) ? (array) $j : null;
        }
        $this->ifneeded = (int) $this->ifneeded;
        if ($this->tags !== null) {
            $ts = [];
            /** @phan-suppress-next-line PhanTypeMismatchArgumentInternal */
            foreach (explode(" ", $this->tags) as $t) {
                if ($t !== "")
                    $ts[] = $t;
            }
            $this->tags = empty($ts) ? null : $ts;
        }
        if ($this->ensure !== null) {
            /** @phan-suppress-next-line PhanTypeMismatchArgument */
            $this->set_ensure($this->ensure);
        }

        $this->runorder = (int) $this->runorder;
        $this->runstride = (int) $this->runstride;
        $this->insertat = (int) $this->insertat;
        $this->scheduleat = (int) $this->scheduleat;
        $this->updateat = (int) $this->updateat;
        $this->runat = (int) $this->runat;
        $this->status = (int) $this->status;

        if (isset($this->group_count)) {
            $this->group_count = (int) $this->group_count;
        }
    }


    /** @return ?Pset */
    function pset() {
        if (($this->_ocache & 1) === 0) {
            $this->_ocache |= 1;
            $this->_pset = $this->conf->pset_by_id($this->psetid);
        }
        return $this->_pset;
    }

    /** @return ?Contact */
    function user() {
        if (($this->_ocache & 16) === 0) {
            $this->_ocache |= 16;
            $this->_user = $this->conf->user_by_id($this->cid);
            $this->_user->set_anonymous(($this->flags & self::FLAG_ANONYMOUS) !== 0);
        }
        return $this->_user;
    }

    /** @return ?Contact */
    function requester() {
        if ($this->cid === $this->reqcid) {
            return $this->user();
        } else if ($this->reqcid <= 0) {
            return $this->conf->root_user();
        } else {
            return $this->conf->user_by_id($this->reqcid);
        }
    }

    /** @return ?Repository */
    function repo() {
        if (($this->_ocache & 2) === 0) {
            $this->_ocache |= 2;
            if ($this->repoid > 0) {
                $this->_repo = Repository::by_id($this->repoid, $this->conf);
            }
        }
        return $this->_repo;
    }

    /** @return ?PsetView */
    function info() {
        if (($this->_ocache & 4) === 0) {
            $this->_ocache |= 4;
            if (($p = $this->pset())
                && ($u = $this->user())
                && ($v = $this->requester())) {
                $this->_info = PsetView::make($p, $u, $v, $this->hash(), true);
            }
        }
        return $this->_info;
    }

    /** @return ?string */
    function hash() {
        return $this->bhash !== null ? bin2hex($this->bhash) : null;
    }

    /** @return ?RunLogger */
    function run_logger() {
        if ($this->_info) {
            return $this->_info->run_logger();
        } else if (($pset = $this->pset()) && ($repo = $this->repo())) {
            return new RunLogger($pset, $repo);
        } else {
            return null;
        }
    }

    /** @return ?RunnerConfig */
    function runner() {
        if (($this->_ocache & 8) === 0) {
            $this->_ocache |= 8;
            if (($p = $this->pset())) {
                $this->_runner = $p->runners[$this->runnername] ?? null;
            }
        }
        return $this->_runner;
    }

    /** @return string */
    function unparse_key() {
        $pset = $this->pset();
        $uname = $this->conf->cached_username_by_id($this->cid, ($this->flags & self::FLAG_ANONYMOUS) !== 0);
        $hash = $this->hash();
        return "~{$uname}/{$pset->urlkey}/{$hash}/{$this->runnername}";
    }

    /** @param ?string $s */
    private function set_ensure($s) {
        $j = $s !== null ? json_decode($s) : null;
        $this->ensure = is_array($j) ? $j : null;
    }

    /** @return int */
    function status() {
        return $this->status;
    }

    /** @return ?string */
    function output_file() {
        if ($this->status >= self::STATUS_WORKING
            && ($rl = $this->run_logger())) {
            return $rl->output_file($this->runat);
        } else {
            return null;
        }
    }

    /** @param bool $verbose
     * @return string */
    function status_text($verbose = false) {
        switch ($this->status) {
        case self::STATUS_PAUSED:
            return "paused";
        case self::STATUS_UNSCHEDULED:
            return "unscheduled" . ($verbose && $this->abandoned() ? " abandoned" : "");
        case self::STATUS_SCHEDULED:
            return "scheduled" . ($verbose && $this->abandoned() ? " abandoned" : "");
        case self::STATUS_WORKING:
            return "working" . ($verbose && $this->working_complete() ? " complete": "");
        case self::STATUS_CANCELLED:
            return "cancelled";
        case self::STATUS_DONE:
            return $verbose ? "done unevaluated" : "done";
        case self::STATUS_EVALUATED:
            return "done";
        default:
            return "unknown status {$this->status}";
        }
    }

    /** @return string */
    function tags_text() {
        if ($this->tags === null) {
            return "";
        } else if (count($this->tags) === 1) {
            return "#" . $this->tags[0];
        } else {
            return "#" . join(" #", $this->tags);
        }
    }

    /** @return bool */
    function unscheduled() {
        return $this->status <= self::STATUS_UNSCHEDULED;
    }

    /** @return bool */
    function schedulable() {
        return $this->status === self::STATUS_UNSCHEDULED;
    }

    /** @return bool */
    function scheduled() {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /** @return bool */
    function working() {
        return $this->status === self::STATUS_WORKING;
    }

    /** @return bool */
    function working_complete() {
        return $this->status === self::STATUS_WORKING
            && $this->lockfile
            && RunLogger::active_job_at($this->lockfile) !== $this->runat;
    }

    /** @return bool */
    function has_response() {
        return $this->status >= self::STATUS_WORKING;
    }

    /** @return bool */
    function abandoned() {
        return ($this->status === self::STATUS_UNSCHEDULED
                || $this->status === self::STATUS_SCHEDULED)
            && ($this->flags & (self::FLAG_UNWATCHED | self::FLAG_FOREGROUND)) === 0
            && $this->updateat < Conf::$now - 180;
    }

    /** @return bool */
    function cancelled() {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** @return bool */
    function stopped() {
        return $this->status >= self::STATUS_CANCELLED;
    }

    /** @return int */
    function nconcurrent() {
        return $this->conf->queue($this->queueclass)->nconcurrent;
    }

    /** @return mixed */
    private function evaluate() {
        assert($this->status >= self::STATUS_DONE || $this->queueid === 0);
        if ($this->_evaluate_at === null) {
            $this->_evaluate_at = $this->runat;
            if (($runner = $this->runner())
                && $runner->evaluate_function
                && ($info = $this->info())) {
                $this->_evaluate = $info->runner_evaluate($runner, $this->runat);
            }
        }
        if ($this->status === self::STATUS_DONE) {
            $this->swap_status(self::STATUS_EVALUATED);
        }
        return $this->_evaluate;
    }


    /** @param ?PsetView $info
     * @return ?QueueItem */
    static function fetch(Conf $conf, $result, $info = null) {
        $qi = $result ? $result->fetch_object("QueueItem", [$conf]) : null;
        if ($qi) {
            $qi->db_load();
            $info && $qi->associate_info($info);
        }
        return $qi;
    }

    /** @param int $queueid
     * @param ?PsetView $info
     * @return ?QueueItem */
    static function by_id(Conf $conf, $queueid, $info = null) {
        $conf->clean_queue();
        $result = $conf->qe("select * from ExecutionQueue where queueid=?", $queueid);
        return self::fetch($conf, $result, $info);
    }

    /** @param int $chain
     * @return ?QueueItem */
    static function by_chain(Conf $conf, $chain) {
        $conf->clean_queue();
        $result = $conf->qe("select * from ExecutionQueue
                where chain=? and status<?
                order by runorder asc, queueid asc limit 1",
            $chain, self::STATUS_CANCELLED);
        return self::fetch($conf, $result, null);
    }

    /** @return int */
    static function new_chain() {
        // make sure JS can represent chain as int
        return random_int(1, min(PHP_INT_MAX, (1 << 52) - 1));
    }

    /** @param int $chain
     * @return bool */
    static function valid_chain($chain) {
        return $chain >= 0 && $chain <= PHP_INT_MAX && $chain <= (1 << 52) - 1;
    }

    /** @param PsetView $info
     * @param ?RunnerConfig $runner
     * @return QueueItem */
    static function make_info($info, $runner = null) {
        $qi = new QueueItem($info->conf);
        $qi->queueid = 0;
        $qi->reqcid = $info->viewer->contactId;
        $qi->cid = $info->user->contactId;
        $qi->psetid = $info->pset->id;
        $qi->repoid = $info->repo ? $info->repo->repoid : 0;
        $qi->bhash = $info->bhash();
        if ($qi->bhash) {
            $qi->runsettings = (array) $info->commit_jnote("runsettings");
        }
        $qi->flags = $info->user->is_anonymous ? self::FLAG_ANONYMOUS : 0;
        $qi->ifneeded = 0;
        $qi->runat = 0;

        $qi->queueclass = "";
        $qi->status = self::STATUS_UNSCHEDULED;
        if ($runner) {
            $qi->runnername = $runner->name;
            $qi->queueclass = $runner->queue ?? "";
            $qi->ensure = $runner->ensure;
        }

        $qi->associate_info($info, $runner);
        return $qi;
    }

    /** @param PsetView $info
     * @param RunResponse $rr
     * @return QueueItem */
    static function for_run_response($info, $rr) {
        assert($rr->pset === $info->pset->urlkey
               || $info->conf->pset_by_key($rr->pset) === $info->pset);
        $qi = self::make_info($info);
        $qi->queueid = $rr->queueid;
        $qi->runnername = $rr->runner;
        $qi->runat = $rr->timestamp;
        $qi->runsettings = $rr->settings;
        $qi->tags = $rr->tags;
        $qi->status = $rr->done ? self::STATUS_DONE : self::STATUS_WORKING;
        $qi->eventsource = $rr->eventsource;
        return $qi;
    }

    /** @param PsetView $info
     * @param RunnerConfig $runner
     * @return QueueItem */
    static function for_complete_job($info, $runner) {
        foreach ($info->run_logger()->completed_responses($runner, $info->hash()) as $rr) {
            $qi = self::for_run_response($info, $rr);
            $qi->associate_info($info, $runner);
            return $qi;
        }
        return null;
    }

    /** @return int */
    static function unscheduled_runorder() {
        return Conf::$now + 1000000000;
    }


    /** @param RunResponse $rr
     * @param bool $verbose
     * @return bool */
    private function is_compatible($rr, $verbose = false) {
        // assumes it’s in completed_responses()
        foreach ($this->tags ?? [] as $t) {
            if (!$rr->has_tag($t)) {
                if ($verbose) {
                    error_log("{$rr->timestamp}: incompatible, lacks tag {$t}");
                }
                return false;
            }
        }
        if ((!empty($this->runsettings) || !empty($rr->settings))
            && json_encode_db($this->runsettings) !== json_encode_db($rr->settings)) {
            if ($verbose) {
                error_log("{$rr->timestamp}: incompatible settings, " . json_encode_db($rr->settings) . " vs. " . json_encode_db($this->runsettings));
            }
            return false;
        }
        return true;
    }

    /** @return ?RunResponse */
    function compatible_response() {
        $info = $this->info();
        $runner = $this->runner();
        if ($info && $runner) {
            foreach ($info->run_logger()->completed_responses($runner, $info->hash()) as $rr) {
                if ($this->is_compatible($rr)) {
                    return $rr;
                }
            }
        }
        return null;
    }

    /** @param bool $verbose
     * @param ?int $max
     * @return int */
    function count_compatible_responses($verbose = false, $max = null) {
        $max = $max ?? PHP_INT_MAX;
        $info = $this->info();
        $runner = $this->runner();
        if (!$info || !$runner || $max <= 0) {
            return 0;
        }
        $n = 0;
        foreach ($info->run_logger()->completed_responses($runner, $info->hash()) as $rr) {
            if ($this->is_compatible($rr, $verbose)) {
                ++$n;
                if ($n === $max) {
                    return $n;
                }
            }
        }
        return $n;
    }

    function enqueue() {
        assert($this->queueid === 0 && ($this->flags & self::FLAG_FOREGROUND) === 0);
        $this->conf->clean_queue();
        $this->insertat = $this->updateat = Conf::$now;
        $this->runat = 0;
        $this->runorder = $this->runorder ?? self::unscheduled_runorder();
        $this->status = self::STATUS_UNSCHEDULED;
        $this->conf->qe("insert into ExecutionQueue set reqcid=?, cid=?,
                runnername=?, psetid=?, repoid=?,
                bhash=?, runsettings=?, tags=?,
                queueclass=?, flags=?, chain=?,
                insertat=?, updateat=?,
                runat=?, runorder=?, status=?,
                ensure=?, ifneeded=?",
            $this->reqcid, $this->cid,
            $this->runnername, $this->psetid, $this->repoid,
            $this->bhash, $this->runsettings ? json_encode_db($this->runsettings) : null,
            $this->tags ? " " . join(" ", $this->tags) . " " : null,
            $this->queueclass, $this->flags, $this->chain,
            $this->insertat, $this->updateat,
            $this->runat, $this->runorder, $this->status,
            $this->ensure ? json_encode_db($this->ensure) : null, $this->ifneeded);
        $this->queueid = $this->conf->dblink->insert_id;
    }

    /** @param int $priority
     * @param ?int $userid */
    function schedule($priority, $userid = null) {
        if ($this->queueid === 0) {
            $this->enqueue();
        }

        if ($this->status === self::STATUS_PAUSED
            || $this->status === self::STATUS_UNSCHEDULED) {
            if (($userid ?? 0) > 0) {
                $this->conf->qe("update ExecutionQueue
                        set status=?, scheduleat=?,
                        runorder=greatest(coalesce((select last_runorder+1 from ContactInfo where contactId=?),0),?)+?+runstride
                        where queueid=? and status=?",
                    self::STATUS_SCHEDULED, Conf::$now,
                    $userid, Conf::$now, $priority,
                    $this->queueid, $this->status);
            } else {
                $this->conf->qe("update ExecutionQueue
                        set status=?, scheduleat=?, runorder=?+runstride
                        where queueid=? and status=?",
                    self::STATUS_SCHEDULED, Conf::$now,
                    Conf::$now + $priority,
                    $this->queueid, $this->status);
            }
            if (($row = $this->conf->fetch_first_row("select status, scheduleat, runorder, runstride from ExecutionQueue where queueid=?", $this->queueid))) {
                $this->status = (int) $row[0];
                $this->scheduleat = (int) $row[1];
                $this->runorder = (int) $row[2];
                $this->runstride = (int) $row[3];
                if (($userid ?? 0) > 0) {
                    $last_runorder = $this->runorder - $this->runstride - $priority;
                    $this->conf->qe("update ContactInfo
                            set last_runorder=?
                            where contactId=? and last_runorder<?",
                        $last_runorder, $userid, $last_runorder);
                }
            }
        }
    }

    function update() {
        assert($this->queueid !== 0);
        $result = $this->conf->qe("update ExecutionQueue
                set updateat=?
                where queueid=? and updateat<?",
            Conf::$now, $this->queueid, Conf::$now);
        if ($result->affected_rows) {
            $this->updateat = Conf::$now;
        }
        Dbl::free($result);
    }

    /** @return ?string */
    private function eventsource_dir() {
        $esdir = $this->conf->opt("run_eventsourcedir");
        if (!$esdir) {
            return null;
        }
        if ($esdir[0] !== "/") {
            $esdir = SiteLoader::$root . "/{$esdir}";
        }
        if (!str_ends_with($esdir, "/")) {
            $esdir .= "/";
        }
        return $esdir;
    }

    private function update_from_database() {
        if (($row = $this->conf->fetch_first_row("select status, runat, lockfile, eventsource, ensure
                from ExecutionQueue where queueid=?", $this->queueid))) {
            $this->status = (int) $row[0];
            $this->runat = (int) $row[1];
            $this->lockfile = $row[2];
            $this->eventsource = $row[3];
            $this->set_ensure($row[4]);
        }
    }

    /** @param int $new_status
     * @param array{runat?:int,lockfile?:string,eventsource?:string,maxupdate?:int} $fields
     * @return bool */
    private function swap_status($new_status, $fields = []) {
        $old_status = $this->status;
        $new_runat = array_key_exists("runat", $fields) ? $fields["runat"] : $this->runat;
        if ($this->queueid !== 0) {
            $new_lockfile = array_key_exists("lockfile", $fields) ? $fields["lockfile"] : $this->lockfile;
            $new_eventsource = array_key_exists("eventsource", $fields) ? $fields["eventsource"] : $this->eventsource;
            $ewhere = "";
            $qv = [];
            if (array_key_exists("maxupdate", $fields)) {
                $ewhere = " and updateat<?";
                $qv[] = $fields["maxupdate"];
            }
            $result = $this->conf->qe("update ExecutionQueue
                    set status=?, runat=?, lockfile=?, bhash=?, eventsource=?
                    where queueid=? and status=?{$ewhere}",
                $new_status, $new_runat, $new_lockfile, $this->bhash, $new_eventsource,
                $this->queueid, $this->status, ...$qv);
            $changed = $result->affected_rows;
            Dbl::free($result);
            if ($changed) {
                $this->status = $new_status;
                $this->runat = $new_runat;
                $this->lockfile = $new_lockfile;
                $this->eventsource = $new_eventsource;
            } else {
                $this->update_from_database();
            }
        } else {
            $changed = $this->status !== $new_status;
            if ($changed) {
                $this->status = $new_status;
                $this->runat = $new_runat;
            }
        }
        if ($changed
            && $old_status < self::STATUS_EVALUATED
            && $this->status >= self::STATUS_DONE) {
            // always evaluate at least once
            $this->evaluate();
        }
        if ($changed && $this->status >= self::STATUS_CANCELLED) {
            if ($this->eventsource && ($esdir = $this->eventsource_dir())) {
                @unlink("{$esdir}{$this->eventsource}");
            }
            if ($this->queueid !== 0 && $this->chain) {
                if (self::step_chain($this->conf, $this->chain)
                    && $this->qstate) {
                    $this->qstate->bump_chain($this->conf, $this->chain);
                }
            }
        }
        return !!$changed;
    }

    /** @param int $chain
     * @return bool */
    static function step_chain(Conf $conf, $chain) {
        $result = $conf->qe("update ExecutionQueue
                set runorder=if(status=?,?+runstride,runorder),
                    scheduleat=if(status=?,?,scheduleat),
                    status=if(status=?,?,status)
                where status>=? and status<? and chain=?
                order by status>? desc, runorder asc, queueid asc limit 1",
            self::STATUS_UNSCHEDULED, Conf::$now,
            self::STATUS_UNSCHEDULED, Conf::$now,
            self::STATUS_UNSCHEDULED, self::STATUS_SCHEDULED,
            self::STATUS_PAUSED, self::STATUS_CANCELLED, $chain,
            self::STATUS_UNSCHEDULED);
        return $result->affected_rows > 0;
    }

    function cancel() {
        $this->swap_status(self::STATUS_CANCELLED);
    }

    /** @param QueueState $qs
     * @return bool */
    function step($qs) {
        assert(($this->runat > 0) === ($this->status > 0));

        // cancelled & completed: step does nothing
        if ($this->status >= self::STATUS_CANCELLED) {
            return true;
        }

        // cancel abandoned jobs
        if ($this->abandoned()
            && $this->swap_status(self::STATUS_CANCELLED, ["maxupdate" => Conf::$now - 180])) {
            return true;
        }

        // do nothing for unscheduled jobs
        // XXX should kickstart broken chain
        if ($this->status === self::STATUS_UNSCHEDULED) {
            if (($this->flags & self::FLAG_FOREGROUND) === 0) {
                return true;
            } else {
                $this->status = self::STATUS_SCHEDULED;
            }
        }

        // on ifneeded, check for compatible response
        if ($this->status === self::STATUS_SCHEDULED
            && $this->ifneeded !== 0
            && ($rr = $this->compatible_response())
            && $this->count_compatible_responses(false, $this->ifneeded) >= $this->ifneeded) {
            $this->swap_status(self::STATUS_EVALUATED, ["runat" => $rr->timestamp]);
            if ($this->status >= self::STATUS_CANCELLED) {
                return true;
            }
        }

        // if not working, check if can start
        $nc = $this->nconcurrent();
        $ncx = $nc <= 0 ? 1000000 : $nc;
        if ($this->status === self::STATUS_SCHEDULED
            && $qs->nrunning < min($ncx, $qs->nconcurrent)) {
            try {
                // do not start_command if no command
                $this->start_command();
            } catch (Exception $e) {
                $this->last_error = $e->getMessage();
                $this->swap_status(self::STATUS_CANCELLED);
                return false;
            }
        }

        // if working, check for completion
        if ($this->status === self::STATUS_WORKING
            && $this->lockfile
            && RunLogger::active_job_at($this->lockfile) !== $this->runat) {
            // XXX this does not use run timeouts
            $this->swap_status(self::STATUS_EVALUATED);
        }

        if ($this->status === self::STATUS_SCHEDULED
            || $this->status === self::STATUS_WORKING) {
            // update queue
            if ($this->runat > 0) {
                ++$qs->nrunning;
            }
            if ($nc > 0) {
                $qs->nconcurrent = max(min($nc, $qs->nconcurrent), $qs->nrunning);
            }
        }
        return true;
    }


    /** @param string $x
     * @return string */
    function expand($x) {
        if (strpos($x, '${') === false) {
            return $x;
        }
        return preg_replace_callback('/\$\{[A-Z]+\}/', function ($m) {
            if ($m[0] === '${REPOGID}') {
                $repo = $this->repo();
                if (!$repo) {
                    return $m[0];
                }
                return $repo->repogid ? : (string) $this->repoid;
            } else if ($m[0] === '${PSET}') {
                return (string) $this->psetid;
            } else if ($m[0] === '${REPOID}') {
                return (string) $this->repoid;
            } else if ($m[0] === '${HOSTTYPE}') {
                return $this->conf->opt("hostType") ?? "";
            } else if ($m[0] === '${COMMIT}' || $m[0] === '${HASH}') {
                return $this->hash() ?? $m[0];
            } else if ($m[0] === '${CONFDIR}') {
                return "conf/";
            } else if ($m[0] === '${SRCDIR}') {
                return "src/";
            } else {
                return $m[0];
            }
        }, $x);
    }

    /** @param ?PsetView $info
     * @param ?RunnerConfig $runner */
    function associate_info($info, $runner = null) {
        if ($info && $info->pset->id === $this->psetid) {
            $this->_pset = $info->pset;
            $this->_ocache |= 1;
        }
        if ($info && $info->user->contactId === $this->cid) {
            $this->_user = $info->user;
            $this->_ocache |= 16;
        }
        if ($info && $info->repo && $info->repo->repoid === $this->repoid) {
            $this->_repo = $info->repo;
            $this->_ocache |= 2;
        }
        if ($info && $this->_pset === $info->pset && $this->_repo === $info->repo) {
            $this->_info = $info;
            $this->_ocache |= 4;
        }
        if ($runner && $runner->name === $this->runnername) {
            $this->_runner = $runner;
            $this->_ocache |= 8;
        }
    }


    /** @param ?string $esid */
    private function log_identifier($esid) {
        $rr = RunResponse::make_info($this->runner(), $this->info());
        $rr->settings = $this->runsettings;
        $rr->tags = $this->tags;
        if ($this->runat) {
            $rr->timestamp = $this->runat;
        }
        if ($this->queueid) {
            $rr->queueid = $this->queueid;
        }
        if ($esid !== null) {
            $rr->eventsource = $esid;
        }
        if (($hostname = gethostname()) !== false) {
            $rr->host = gethostbyname($hostname);
        }
        fwrite($this->_logstream, "++ " . json_encode($rr) . "\n");
    }


    private function start_command() {
        assert($this->runat === 0 && $this->status === self::STATUS_SCHEDULED);

        $repo = $this->repo();
        $pset = $this->pset();
        $runner = $this->runner();
        if (!$repo) {
            throw new RunnerException("No repository");
        } else if (!$pset) {
            throw new RunnerException("Bad queue item pset");
        } else if (!$runner) {
            throw new RunnerException("Bad queue item runner");
        }

        // if no command, skip right to evaluation
        if (!$runner->command) {
            $this->swap_status(self::STATUS_EVALUATED, ["runat" => time()]);
            return;
        }

        // otherwise must be enqueued or foreground
        $foreground = ($this->flags & self::FLAG_FOREGROUND) !== 0;
        assert($this->queueid !== 0 || $foreground);

        if (!chdir(SiteLoader::$root)) {
            throw new RunnerException("Can’t cd to main directory");
        }
        if (!is_executable("jail/pa-jail")) {
            throw new RunnerException("The pa-jail program has not been compiled");
        }

        $info = $this->info();
        $runlog = $info->run_logger();
        if (!$runlog->mkdirs()) {
            throw new RunnerException("Can’t create log directory");
        }

        $runlog->invalidate_active_job();
        if ($runlog->active_job()) {
            return;
        }
        $runlog->invalidate_active_job();

        // collect user information
        if ($runner->username) {
            $username = $runner->username;
        } else if ($pset->run_username) {
            $username = $pset->run_username;
        } else {
            $username = "jail61user";
        }
        if (!preg_match('/\A\w+\z/', $username)) {
            throw new RunnerException("Bad run_username");
        }

        $pwnam = posix_getpwnam($username);
        $userhome = $pwnam ? $pwnam["dir"] : "/home/jail61";
        $userhome = preg_replace('/\/+\z/', '', $userhome);

        // collect directory information
        $this->_jaildir = preg_replace('/\/+\z/', '', $this->expand($pset->run_dirpattern));
        if (!$this->_jaildir) {
            throw new RunnerException("Bad run_dirpattern");
        }
        $this->_jailhomedir = "{$this->_jaildir}/" . preg_replace('/\A\/+/', '', $userhome);

        // create logfile and pidfile
        $runat = time();
        $logbase = $runlog->job_prefix($runat);
        $pidfile = $runlog->pid_file();
        $pidresult = @file_put_contents($pidfile, "{$runat}\n");
        if ($pidresult === false) {
            throw new RunnerException("Can’t create pidfile");
        }
        $inputfifo = $timingfile = null;
        if (!$foreground) {
            if (posix_mkfifo("{$logbase}.in", 0660)) {
                $inputfifo = "{$logbase}.in";
            }
            if ($runner->timed_replay && touch("{$logbase}.log.time")) {
                $timingfile = "{$logbase}.log.time";
            }
            $this->_logfile = "{$logbase}.log";
            $logstream = @fopen($this->_logfile, "a");
        } else if (($this->flags & self::FLAG_FOREGROUND_VERBOSE) !== 0) {
            $logstream = fopen("php://stderr", "w"); // `STDERR` not available in php-fpm
        } else {
            $logstream = fopen("/dev/null", "w");
        }
        if (!$logstream) {
            @unlink($pidfile);
            throw new RunnerException("Can’t create log file");
        }
        $this->_logstream = $logstream;
        $this->bhash = $info->bhash(); // resolve blank hash

        // maybe register eventsource
        $esfile = $esid = null;
        if (($this->flags & self::FLAG_NOEVENTSOURCE) === 0
            && ($esdir = $this->eventsource_dir())
            && !$foreground) {
            $esid = bin2hex(random_bytes(16));
            $esfile = "{$esdir}{$esid}";
            if (file_exists($esfile)) {
                $esfile = $esid = null;
            }
        }

        if (!$this->swap_status(self::STATUS_WORKING, ["runat" => $runat, "lockfile" => $pidfile, "eventsource" => $esid])) {
            return;
        }
        $this->_runstatus = 1;
        register_shutdown_function([$this, "cleanup"]);

        // print json to first line
        $this->log_identifier($esid);

        // create jail
        $this->remove_old_jails();
        if ($this->run_and_log(["jail/pa-jail", "add", $this->_jaildir, $username])) {
            throw new RunnerException("Can’t initialize jail");
        }

        // check out code
        $this->checkout_code();

        // save commit settings
        $this->add_run_settings($this->runsettings ?? []);

        // actually run
        $cmdarg = [
            "jail/pa-jail", "run", "-p{$pidfile}",
            "-P{$this->runat} \$\$" . ($inputfifo ? " -i" : "")
        ];
        if ($timingfile) {
            $cmdarg[] = "-t{$timingfile}";
        }
        if ($esfile) {
            $cmdarg[] = "--event-source={$esfile}";
        }

        $skeletondir = $pset->run_skeletondir ? : $this->conf->opt("run_skeletondir");
        $binddir = $pset->run_binddir ? : $this->conf->opt("run_binddir");
        if ($skeletondir && $binddir && !is_dir("{$skeletondir}/proc")) {
            $binddir = false;
        }
        $jfiles = $runner->jailfiles();

        if ($skeletondir && $binddir) {
            $binddir = preg_replace('/\/+\z/', '', $binddir);
            $contents = "/ <- {$skeletondir} [bind-ro";
            if ($jfiles
                && !preg_match('/[\s\];]/', $jfiles)
                && ($jhash = hash_file("sha256", $jfiles)) !== false) {
                $contents .= " $jhash $jfiles";
            }
            $contents .= "]\n{$userhome} <- {$this->_jailhomedir} [bind]";
            $cmdarg[] = "-u{$this->_jailhomedir}";
            $cmdarg[] = "-F{$contents}";
            $homedir = $binddir;
        } else if ($jfiles) {
            $cmdarg[] = "-h";
            $cmdarg[] = "-f{$jfiles}";
            if ($skeletondir) {
                $cmdarg[] = "-S{$skeletondir}";
            }
            $homedir = $this->_jaildir;
        } else {
            throw new RunnerException("Missing jail population configuration");
        }

        $jmanifest = $runner->jailmanifest();
        if ($jmanifest) {
            $cmdarg[] = "-F" . join("\n", $jmanifest);
        }

        if (!$foreground) {
            if (($to = $runner->timeout ?? $pset->run_timeout) > 0) {
                $cmdarg[] = "-T{$to}";
            }
            if (($to = $runner->idle_timeout ?? $pset->run_idle_timeout) > 0) {
                $cmdarg[] = "-I{$to}";
            }
            if (($runner->rows ?? 0) > 0 || ($runner->columns ?? 0) > 0) {
                $rows = ($runner->rows ?? 0) > 0 ? $runner->rows : 25;
                $cols = ($runner->columns ?? 0) > 0 ? $runner->columns : 80;
                $cmdarg[] = "--size={$cols}x{$rows}";
            }
            if ($inputfifo) {
                $cmdarg[] = "-i{$inputfifo}";
            }
        } else {
            $cmdarg[] = "--fg";
        }

        $cmdarg[] = $homedir;
        $cmdarg[] = $username;
        $cmdarg[] = "TERM=xterm-256color";
        $cmdarg[] = $this->expand($runner->command);
        $this->_runstatus = 2;
        $s = $this->run_and_log($cmdarg, null, ["main" => true]);

        // save information about execution
        if ($foreground) {
            $this->foreground_command_status = $s;
        } else {
            $this->info()->add_recorded_job($runner->name, $this->runat);
        }
        fclose($this->_logstream);
        $this->_logstream = null;
    }

    /** @param list<string> $cmdarg
     * @param ?string $cwd
     * @param array{main?:bool,stdin?:mixed,stdout?:mixed} $opts
     * @return resource */
    private function run_and_log_proc($cmdarg, $cwd = null, $opts = []) {
        $main_command = $opts["main"] ?? false;
        $env = $cmdarg[0] === "git" ? Repository::git_env_vars() : null;
        $cmdarg = $this->conf->fix_command($cmdarg);
        fwrite($this->_logstream, "++ " . Subprocess::unparse_command($cmdarg) . ($main_command ? "\n\n" : "\n"));
        fflush($this->_logstream);

        $cmd = PHP_VERSION_ID >= 70400 ? $cmdarg : Subprocess::unparse_command($cmdarg);
        $redirects = [];
        if (isset($opts["stdin"])) {
            $redirects[0] = $opts["stdin"];
        } else if (!$main_command
                   || ($this->flags & self::FLAG_FOREGROUND) === 0) {
            $redirects[0] = ["file", "/dev/null", "r"];
        }
        if (isset($opts["stdout"])) {
            $redirects[1] = $opts["stdout"];
            $redirects[2] = ["file", $this->_logfile, "a"];
        } else if (($this->flags & self::FLAG_FOREGROUND) === 0) {
            $redirects[1] = ["file", $this->_logfile, "a"];
            $redirects[2] = PHP_VERSION_ID >= 70400 ? ["redirect", 1] : ["file", $this->_logfile, "a"];
        } else if (PHP_VERSION_ID >= 70400
                   && ($this->flags & self::FLAG_FOREGROUND_VERBOSE) !== 0) {
            $redirects[1] = ["redirect", 2];
        }
        $this->_runpipes = [];
        return proc_open($cmd, $redirects, $this->_runpipes, $cwd, $env);
    }

    /** @param list<string> $cmdarg
     * @param ?string $cwd
     * @param array{main?:bool,stdin?:mixed,stdout?:mixed} $opts
     * @return int */
    private function run_and_log($cmdarg, $cwd = null, $opts = []) {
        $proc = $this->run_and_log_proc($cmdarg, $cwd, $opts);
        return proc_close($proc);
    }

    private function remove_old_jails() {
        $newdirpfx = $this->_jaildir . "~." . gmdate("Ymd\\THis", Conf::$now);
        $tries = 0;
        while (is_dir($this->_jaildir)) {
            if ($tries > 10) {
                throw new RunnerException("Can’t remove old jail");
            } else if ($tries > 0) {
                usleep(100000 * (1 << min($tries, 4)));
                Conf::set_current_time(time());
            }

            $newdir = $newdirpfx . ($tries ? ".{$tries}" : "");
            if ($this->run_and_log(["jail/pa-jail", "mv", $this->_jaildir, $newdir])) {
                throw new RunnerException("Can’t remove old jail");
            }

            $this->run_and_log(["jail/pa-jail", "rm", "--bg", $newdir]);
            clearstatcache(false, $this->_jaildir);
            ++$tries;
        }
    }

    private function checkout_code() {
        $pset = $this->pset();
        $repo = $this->repo();
        $repodir = $repo->repodir();
        $runner = $this->runner();
        assert($repo !== null && $pset !== null && $runner !== null && $this->bhash !== null);
        $hash = $this->hash();

        $checkoutdir = $clonedir = $this->_jailhomedir . "/repo";
        if ($repo->truncated_psetdir($pset)
            && $pset->directory_noslash !== "") {
            $clonedir .= "/{$pset->directory_noslash}";
        }

        fwrite($this->_logstream, "++ mkdir {$checkoutdir}\n");
        if (!mkdir($clonedir, 0777, true)) {
            throw new RunnerException("Can’t initialize user repo in jail");
        }

        // make the checkout
        $quiet = [];
        if (($this->flags & (self::FLAG_FOREGROUND | self::FLAG_FOREGROUND_VERBOSE)) === self::FLAG_FOREGROUND) {
            $quiet[] = "-q";
        }
        $status = 0;
        if (!is_dir("{$clonedir}/.git")) {
            $status = $this->run_and_log(["git", "init", "--shared=group", "-b", "main", ...$quiet], $clonedir);
        }
        if ($status === 0) {
            // Create a pipe between `git pack-objects`, in the source repo,
            // and `git unpack-objects`, in the clone.
            $packproc = $this->run_and_log_proc(["git", "pack-objects", "--delta-base-offset", "--revs", "--stdout", "-q"],
                                                $repodir, ["stdin" => ["pipe", "r"], "stdout" => ["pipe", "w"]]);
            $packpipes = $this->_runpipes;
            fwrite($packpipes[0], "{$hash}\n");
            fclose($packpipes[0]);

            $unpackproc = $this->run_and_log_proc(["git", "unpack-objects", "-q"], $clonedir, ["stdin" => $packpipes[1]]);
            fclose($packpipes[1]);
            $status = proc_close($unpackproc);
            $status1 = proc_close($packproc);
        }
        if ($status === 0) {
            $args = array_merge($quiet, [$hash]);
            $status = $this->run_and_log(["git", "reset", "--hard", ...$args], $clonedir);
        }

        if ($status !== 0) {
            throw new RunnerException("Can’t check out code into jail");
        }

        if ($this->run_and_log(["rm", "-rf", ".git", ".gitcheckout"], $clonedir)) {
            throw new RunnerException("Can’t clean up checkout in jail");
        }

        // create overlay
        if (($overlay = $runner->overlay())) {
            $this->checkout_overlay($checkoutdir, $overlay);
        }
    }

    /** @param string $checkoutdir
     * @param list<RunOverlayConfig> $overlayfiles */
    function checkout_overlay($checkoutdir, $overlayfiles) {
        foreach ($overlayfiles as $ro) {
            $path = $ro->absolute_path();
            if (preg_match('/(?:\.tar|\.tar\.[gx]z|\.t[bgx]z|\.tar\.bz2)\z/i', $ro->file)) {
                $cmdarg = ["tar", "-xf", $path];
                foreach ($ro->exclude ?? [] as $xf) {
                    $cmdarg[] = "--exclude";
                    $cmdarg[] = $xf;
                }
                $x = $this->run_and_log($cmdarg, $checkoutdir);
            } else {
                fwrite($this->_logstream, "++ cp " . escapeshellarg($path) . " " . escapeshellarg($checkoutdir) . "\n");
                $rslash = strrpos($path, "/");
                $x = !copy($path, $checkoutdir . substr($path, $rslash));
            }
            if ($x) {
                throw new RunnerException("Can’t unpack overlay");
            }
        }

        $checkout_instructions = @file_get_contents($checkoutdir . "/.gitcheckout");
        if ($checkout_instructions) {
            foreach (explode("\n", $checkout_instructions) as $text) {
                if (substr($text, 0, 3) === "rm:") {
                    $this->run_and_log(["rm", "-rf", substr($text, 3)], $checkoutdir);
                }
            }
        }
    }

    private function add_run_settings($s) {
        $mk = $sh = [];
        foreach ((array) $s as $k => $v) {
            if (preg_match('/\A[A-Za-z_][A-Za-z_0-9]*\z/', $k)
                && !preg_match('/\A(?:PATH|MAKE|HOME|SHELL|POSIXLY_CORRECT|TMPDIR|LANG|USER|LOGNAME|SSH.*|PS\d|HISTFILE|LD_LIBRARY_PATH|HOST|HOSTNAME|TERM|TERMCAP|EDITOR|PAGER|MANPATH)\z/', $k)) {
                if (preg_match('/\A[-A-Za-z0-9_:\/ .,]*\z/', $v)) {
                    $mk[] = "{$k} = {$v}\n";
                }
                $sh[] = "{$k}=" . escapeshellarg($v) . "\n";
            }
        }
        file_put_contents("{$this->_jailhomedir}/config.mk", join("", $mk));
        file_put_contents("{$this->_jailhomedir}/config.sh", join("", $sh));
    }


    /** @param int $offset
     * @param ?string $write
     * @param bool $stop
     * @return RunResponse */
    private function command_response($offset, $write, $stop) {
        $runlog = $this->run_logger();
        if ((($write ?? "") === "" && !$stop)
            || $runlog->active_job() !== $this->runat) {
            return $runlog->job_response($this->runat, $offset);
        }

        $usleep = 0;
        if (($write ?? "") !== "") {
            $runlog->job_write($this->runat, $write);
            $usleep = 10;
        }
        if ($stop) {
            // "ESC Ctrl-C" is captured by pa-jail
            $runlog->job_write($this->runat, "\x1b\x03");
            $usleep = 10;
        }
        $now = microtime(true);
        do {
            if ($usleep > 0) {
                usleep($usleep);
            }
            $runlog->invalidate_active_job();
            $rr = $runlog->job_response($this->runat, $offset);
            $usleep = 10;
        } while ($stop
                 && !$rr->done
                 && microtime(true) - $now < 0.1);
        return $rr;
    }

    /** @param int $offset
     * @param ?string $write
     * @param bool $stop
     * @return RunResponse */
    function full_response($offset = 0, $write = null, $stop = false) {
        $runner = $this->runner();
        if ($runner->command) {
            $rr = $this->command_response($offset, $write, $stop);
        } else {
            $this->runat = $this->runat ? : time();
            $rr = RunResponse::make_info($runner, $this->info());
            $rr->timestamp = $this->runat;
            $rr->done = true;
            $rr->status = "done";
        }

        if ($rr->partial) {
            $rr->status = "partial";
        } else if ($rr->done) {
            $rr->status = "done";
        } else if (Conf::$now - $this->runat <= 600) {
            $rr->status = "working";
        } else {
            $rr->status = "old";
        }

        if ($rr->done
            && $this->status < self::STATUS_CANCELLED) {
            $this->swap_status(self::STATUS_EVALUATED);
        }

        if ($rr->done
            && $this->runner()->evaluate_function) {
            $rr->result = $this->evaluate();
        }

        return $rr;
    }

    function cleanup() {
        if ($this->_runstatus === 1) {
            $runlog = $this->run_logger();
            unlink($runlog->pid_file());
            @unlink($runlog->job_prefix($this->runat) . ".in");
        }
        if ($this->_logstream) {
            fclose($this->_logstream);
            $this->_logstream = null;
        }
    }
}
