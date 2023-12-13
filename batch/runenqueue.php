<?php
// runenqueue.php -- Peteramati script for adding to the execution queue
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
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
    function run() {
        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, $this->sset_flags, [$this, "test_user"]);
        $sset->set_pset($this->pset);
        if (!$this->ordered) {
            $sset->shuffle();
        }
        $nu = 0;
        $chain = $this->chainid ?? QueueItem::new_chain();
        $chainstr = $chain ? " C{$chain}" : "";
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
            $qi->runorder = QueueItem::unscheduled_runorder($nu * 10);
            $qi->flags |= QueueItem::FLAG_UNWATCHED;
            if (!$this->eventsource) {
                $qi->flags |= QueueItem::FLAG_NOEVENTSOURCE;
            }
            $qi->ifneeded = $this->if_needed;
            $qi->tags = $this->tags;
            foreach ($this->runsettings ?? [] as $k => $v) {
                $qi->runsettings[$k] = $v;
            }
            if ($this->if_needed === 0
                || $qi->count_compatible_responses($this->verbose, $this->if_needed) < $this->if_needed) {
                $qi->enqueue();
                if (!$qi->chain) {
                    $qi->schedule($nu);
                }
                if ($this->verbose) {
                    fwrite(STDERR, "#{$qi->queueid} " . $qi->unparse_key() . ": create{$chainstr}\n");
                }
                ++$nu;
            }
        }
        if ($chain > 0
            && ($qi = QueueItem::by_chain($this->conf, $chain))
            && $qi->schedulable()) {
            $qi->schedule(0);
        }
        return $nu;
    }

    /** @return int */
    function run_or_warn() {
        $n = $this->run();
        if ($n === 0) {
            fwrite(STDERR, "nothing to do\n");
        }
        return $n > 0 ? 0 : 1;
    }

    /** @return RunEnqueue_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p:,pset: Problem set",
            "r:,runner:,run: Runner name",
            "e::,if-needed:: {n} =N Run only if needed",
            "ensure !",
            "u[]+,user[]+ Match these users",
            "H:,hash:,commit: Use this commit",
            "c:,chain: Set chain ID",
            "t[],tag[] Add tag",
            "s[],setting[] Set NAME=VALUE",
            "event-source,eventsource Listen for EventSource connections",
            "ordered Do not randomize user order",
            "V,verbose",
            "help"
        )->helpopt("help")->parse($argv);
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
        if (isset($arg["ordered"])) {
            $self->ordered = true;
        }
        if (isset($arg["V"])) {
            $self->verbose = true;
        }
        if (isset($arg["c"])) {
            $chain = $arg["c"][0] === "C" ? substr($arg["c"], 1) : $arg["c"];
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
    exit(RunEnqueue_Batch::make_args(Conf::$main, $argv)->run_or_warn());
}
