<?php
// runenqueue.php -- Peteramati script for adding to the execution queue
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once(dirname(__DIR__) . "/src/init.php");

class RunEnqueueBatch {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var RunnerConfig */
    public $runner;
    /** @var bool */
    public $is_ensure = false;
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
        if (count($usermatch) === 1 && $usermatch[0] === "dropped") {
            $this->sset_flags |= StudentSet::DROPPED;
        } else {
            $this->sset_flags |= StudentSet::ENROLLED;
        }
        if (count($usermatch) === 1 && $usermatch[0] === "college") {
            $this->sset_flags |= StudentSet::COLLEGE;
        } else if (count($usermatch) === 1 && $usermatch[0] === "extension") {
            $this->sset_flags |= StudentSet::DCE;
        }
        if ($this->sset_flags === StudentSet::ENROLLED) {
            foreach ($usermatch as $s) {
                $this->usermatch[] = "*{$s}*";
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

    /** @return int */
    function run() {
        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, $this->sset_flags);
        $sset->set_pset($this->pset);
        $nu = 0;
        $chain = $this->chainid ?? QueueItem::new_chain();
        $chainstr = $this->chainid ? " C{$this->chainid}" : "";
        foreach ($sset as $info) {
            if (!empty($this->usermatch)
                && !$this->match($info->user->email)
                && !$this->match($info->user->github_username)
                && !$this->match($info->user->anon_username)) {
                continue;
            }
            if ($this->hash === "latest") {
                $info->set_latest_nontrivial_commit();
            } else if ($this->hash && $this->hash !== "grading") {
                if (($c = $info->connected_commit($this->hash))) {
                    $info->set_commit($c);
                } else {
                    continue;
                }
            }
            if (!$info->hash()) {
                continue;
            }
            $qi = QueueItem::make_info($info, $this->runner);
            $qi->chain = $chain > 0 ? $chain : null;
            $qi->runorder = QueueItem::unscheduled_runorder($nu * 10);
            $qi->flags |= QueueItem::FLAG_UNWATCHED
                | ($this->is_ensure ? QueueItem::FLAG_ENSURE : 0);
            $qi->tags = $this->tags;
            foreach ($this->runsettings ?? [] as $k => $v) {
                $qi->runsettings[$k] = $v;
            }
            if (!$this->is_ensure || !$qi->compatible_response()) {
                $qi->enqueue();
                if (!$qi->chain) {
                    $qi->schedule($nu);
                }
                if ($this->verbose) {
                    fwrite(STDERR, $qi->unparse_key() . ": create{$chainstr}\n");
                }
                ++$nu;
            }
        }
        if ($chain > 0
            && ($qi = QueueItem::by_chain($this->conf, $chain))
            && $qi->status < 0) {
            $qi->schedule(0);
        }
        return $nu;
    }

    /** @return RunEnqueueBatch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p:,pset: Problem set",
            "r:,runner:,run: Runner name",
            "e,ensure Run only if needed",
            "u[],user[] Match these users",
            "H:,hash:,commit: Use this commit",
            "c:,chain: Set chain ID",
            "t[],tag[] Add tag",
            "s[],setting[] Set NAME=VALUE",
            "V,verbose",
            "help"
        )->helpopt("help")->parse($argv);
        if (($arg["p"] ?? "") === "") {
            throw new Error("missing `--pset`");
        }
        $pset = $conf->pset_by_key($arg["p"]);
        if (!$pset) {
            throw new Error("no such pset");
        }
        if (($arg["r"] ?? "") === "") {
            throw new Error("missing `--runner`");
        }
        $runner = $pset->runners[$arg["r"]] ?? null;
        if (!$runner) {
            throw new Error("no such runner");
        }
        $reb = new RunEnqueueBatch($pset, $runner, $arg["u"] ?? []);
        if (isset($arg["e"])) {
            $reb->is_ensure = true;
        }
        if (isset($arg["V"])) {
            $reb->verbose = true;
        }
        if (isset($arg["c"])) {
            $chain = $arg["c"][0] === "C" ? substr($arg["c"], 1) : $arg["c"];
            if (ctype_digit($chain)
                && QueueItem::valid_chain(intval($chain))) {
                $reb->chainid = intval($chain);
            } else {
                throw new Error("bad `--chain`");
            }
        }
        if (isset($arg["t"])) {
            foreach ($arg["t"] as $tag) {
                if (!preg_match('/\A' . TAG_REGEX_NOTWIDDLE . '\z/', $tag)) {
                    throw new Error("bad `--tag`");
                }
                $reb->tags[] = $tag;
            }
        }
        if (isset($arg["s"])) {
            foreach ($arg["s"] as $setting) {
                if (!preg_match('/\A([A-Za-z][_A-Za-z0-9]*)=([-._A-Za-z0-9]*)\z/', $setting, $m)) {
                    throw new Error("bad `--setting`");
                }
                $reb->runsettings[$m[1]] = $m[2];
            }
        }
        if (isset($arg["H"])) {
            if ($arg["H"] === "" || !ctype_xdigit($arg["H"])) {
                throw new Error("bad `--commit`");
            }
            $reb->hash = strtolower($arg["H"]);
        }
        return $reb;
    }
}

try {
    if (RunEnqueueBatch::make_args($Conf, $argv)->run() > 0) {
        exit(0);
    } else {
        fwrite(STDERR, "nothing to do\n");
        exit(1);
    }
} catch (Error $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
