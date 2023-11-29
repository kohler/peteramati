<?php
// run.php -- Peteramati script for running a runner in the foreground
// HotCRP and Peteramati are Copyright (c) 2006-2023 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}


class Run_Batch {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var RunnerConfig */
    public $runner;
    /** @var bool */
    public $verbose = false;
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
    function run_or_warn() {
        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, $this->sset_flags, [$this, "test_user"]);
        $sset->set_pset($this->pset);
        $nsset = count($sset);

        $infos = [];
        foreach ($sset as $info) {
            if (!$info->repo) {
                continue;
            }
            if ($this->hash && !$info->set_hash($this->hash, true)) {
                if ($nsset === 1 || $this->verbose) {
                    fwrite(STDERR, $this->unparse_key($info, $this->hash) . ": no such commit on {$info->branch}\n");
                }
                continue;
            }
            if (!$info->hash()) {
                continue;
            }
            $infos[] = $info;
        }

        if (count($infos) === 0) {
            fwrite(STDERR, "No matching users\n");
            return 1;
        } else if (count($infos) > 1) {
            fwrite(STDERR, count($infos) . " matching users, be more specific\n");
            foreach ($infos as $info) {
                fwrite(STDERR, "- " . $this->unparse_key($info, $this->hash) . "\n");
            }
            return 1;
        }

        $qi = QueueItem::make_info($infos[0], $this->runner);
        $qi->flags |= QueueItem::FLAG_FOREGROUND
            | QueueItem::FLAG_NOEVENTSOURCE
            | ($this->verbose ? QueueItem::FLAG_FOREGROUND_VERBOSE : 0);
        foreach ($this->runsettings ?? [] as $k => $v) {
            $qi->runsettings[$k] = $v;
        }
        $qi->step(new QueueState);
        if ($qi->foreground_command_status !== null
            && $qi->foreground_command_status >= 0) {
            return $qi->foreground_command_status;
        } else {
            return 127;
        }
    }

    /** @return Run_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p:,pset: Problem set",
            "r:,runner:,run: Runner name",
            "u[]+,user[]+ Match these users",
            "H:,hash:,commit: Use this commit",
            "s[],setting[] Set NAME=VALUE",
            "V,verbose",
            "help"
        )->helpopt("help")->parse($argv);
        if (($arg["p"] ?? "") === "") {
            throw new CommandLineException("missing `--pset`");
        }
        $pset = $conf->pset_by_key($arg["p"]);
        if (!$pset) {
            throw new CommandLineException("no such pset");
        }
        if (($arg["r"] ?? "") === "") {
            throw new CommandLineException("missing `--runner`");
        }
        $runner = $pset->runners[$arg["r"]] ?? null;
        if (!$runner) {
            throw new CommandLineException("no such runner");
        }
        $self = new Run_Batch($pset, $runner, $arg["u"] ?? []);
        if (isset($arg["V"])) {
            $self->verbose = true;
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
        return $self;
    }
}


if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(Run_Batch::make_args(Conf::$main, $argv)->run_or_warn());
}
