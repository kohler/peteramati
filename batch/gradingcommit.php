<?php
// gradingcommit.php -- Peteramati script for setting grading commits
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}

class GradingCommit_Batch {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var bool */
    public $quiet = false;
    /** @var bool */
    public $verbose = false;
    /** @var bool */
    public $force = false;
    /** @var bool */
    public $lock = false;
    /** @var bool */
    public $clear = false;
    /** @var bool */
    public $nograde = false;
    /** @var list<string> */
    public $usermatch = [];
    /** @var ?string */
    public $hash;
    /** @var ?SearchExpr */
    public $commitq;
    /** @var int */
    public $sset_flags;

    /** @param list<string> $usermatch */
    function __construct(Pset $pset, $usermatch = []) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
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
            if ($s !== null && fnmatch($m, $s))
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
     * @return string */
    private function unparse_hashless_key($info) {
        return "~{$info->user->username}/{$info->pset->urlkey}";
    }

    /** @param PsetView $info
     * @param ?string $hash
     * @return string */
    private function unparse_key($info, $hash = null) {
        $hash = $hash ?? $info->hash() ?? "none";
        return "~{$info->user->username}/{$info->pset->urlkey}/{$hash}";
    }

    /** @return int */
    function run() {
        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, $this->sset_flags, [$this, "test_user"]);
        $sset->set_pset($this->pset);

        $nadded = $nscheduled = 0;
        foreach ($sset as $info) {
            if (!$info->repo) {
                continue;
            }
            if (!$this->force && $info->rpi()->placeholder === 0) {
                continue;
            }
            if (($this->hash && !$info->set_hash($this->hash, true))
                || ($this->commitq && !$info->select_commit($this->commitq))) {
                if ($this->verbose) {
                    $key = $this->hash ? $this->unparse_key($info, $this->hash) : $this->unparse_hashless_key($info);
                    fwrite(STDERR, "{$key}: no such commit on {$info->branch}\n");
                }
                continue;
            }
            if ($this->clear || $this->nograde) {
                $placeholder = $this->nograde ? RepositoryPsetInfo::PL_DONOTGRADE : RepositoryPsetInfo::PL_NONE;
            } else if ($info->hash()) {
                $placeholder = $this->lock ? RepositoryPsetInfo::PL_LOCKED : RepositoryPsetInfo::PL_USER;
            } else {
                continue;
            }
            $info->change_grading_commit($placeholder, RepositoryPsetInfo::UTYPE_ADMIN);
            ++$nadded;
        }
        return $nadded > 0 ? 0 : 1;
    }

    /** @return GradingCommit_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p:,pset: Problem set",
            "u[]+,user[]+ Match these users",
            "H:,hash:,commit: Use this commit",
            "commit-query:,commitq: Use the commit matching this search",
            "clear Clear grading commit",
            "do-not-grade,no-grade Mark as do-not-grade",
            "f,force Override commit locks",
            "lock Set commit locks",
            "q,quiet",
            "V,verbose",
            "help"
        )->helpopt("help")
         ->description("Usage: php batch/gradingcommit.php -p PSET [-u USERS...] [--commitq C]")
         ->otheropt(false)
         ->parse($argv);

        if (($mode = $arg["_subcommand"] ?? null) === null) {
            $mode = isset($arg["p"]) || isset($arg["r"]) ? "add" : "list";
        }

        $pset_arg = $arg["p"] ?? "";
        $pset = $conf->pset_by_key($pset_arg);
        if (!$pset) {
            $pset_keys = array_values(array_map(function ($p) { return $p->key; }, $conf->psets()));
            throw (new CommandLineException($pset_arg === "" ? "`--pset` required" : "Pset `{$pset_arg}` not found"))->add_context("(Options are " . join(", ", $pset_keys) . ".)");
        }
        $self = new GradingCommit_Batch($pset, $arg["u"] ?? []);
        $self->quiet = isset($arg["q"]);
        $self->verbose = isset($arg["V"]);
        $self->force = isset($arg["f"]);
        $self->lock = isset($arg["lock"]);
        $self->clear = isset($arg["clear"]);
        $self->nograde = isset($arg["do-not-grade"]);
        if (isset($arg["H"])) {
            if ($arg["H"] === "" || $arg["H"] === "none") {
                $self->clear = true;
            } else if ($arg["H"] === "no-grade" || $arg["H"] === "nograde") {
                $self->nograde = true;
            } else if (($hp = CommitRecord::parse_hashpart($arg["H"]))) {
                $self->hash = $hp;
            } else {
                throw new CommandLineException("bad `--commit`");
            }
        }
        if (isset($arg["commit-query"])) {
            $self->commitq = PsetView::parse_commit_query($arg["commit-query"]);
        }
        return $self;
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(GradingCommit_Batch::make_args(Conf::$main, $argv)->run());
}
