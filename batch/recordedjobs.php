<?php
// recordedjobs.php -- Peteramati script for updating database information
// HotCRP and Peteramati are Copyright (c) 2006-2025 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}


class RecordedJobs_Batch {
    /** @var Conf */
    public $conf;
    /** @var list<Pset> */
    public $psets = [];
    /** @var list<string> */
    public $usermatch = [];
    /** @var list<string> */
    public $runners = [];
    /** @var bool */
    public $all_runners = true;
    /** @var list<string> */
    public $tags = [];
    /** @var ?string */
    public $hash;
    /** @var 'list'|'update' */
    public $mode;
    /** @var bool */
    public $verbose = false;
    /** @var bool */
    public $quiet = false;

    /** @param list<Pset> $psets
     * @param list<string> $usermatch
     * @param string $mode */
    function __construct(Conf $conf, $psets, $usermatch, $mode) {
        $this->conf = $conf;
        $this->psets = $psets;
        $this->usermatch = $usermatch;
        $this->mode = $mode;
    }

    /** @param PsetView $info
     * @param ?string $hash
     * @return string */
    private function unparse_key($info, $hash = null) {
        $hash = $hash ?? $info->hash() ?? "none";
        return "~{$info->user->username}/{$info->pset->urlkey}/{$hash}";
    }

    /** @return int */
    function run_update() {
        $viewer = $this->conf->site_contact();
        $sset = StudentSet::make_globmatch($viewer, $this->usermatch);
        $nu = 0;
        foreach ($this->psets as $pset) {
            $sset->set_pset($pset);
            foreach ($sset as $info) {
                if (!$info->repo) {
                    continue;
                }
                if ($this->hash === null) {
                    $hashes = [];
                    foreach ($info->run_logger()->completed_responses() as $rr) {
                        $hashes[] = $rr->hash;
                    }
                    $hashes = array_unique($hashes);
                } else {
                    $hashes = [$this->hash];
                }
                foreach ($hashes as $hash) {
                    if ($info->set_hash($hash, true, $sset)) {
                        $info->update_recorded_jobs();
                        if ($this->verbose) {
                            fwrite(STDERR, $this->unparse_key($info) . ": jobs " . json_encode($info->commit_jnote("run") ?? (object) []) . "\n");
                        }
                        ++$nu;
                    } else if ($this->verbose) {
                        fwrite(STDERR, $this->unparse_key($info, $hash) . ": no such commit on {$info->branch}\n");
                    }
                }
            }
        }
        if ($nu > 0) {
            return 0;
        }
        fwrite(STDERR, "Nothing to do\n");
        return 1;
    }

    /** @return int */
    function run_list() {
        $viewer = $this->conf->site_contact();
        $sset = StudentSet::make_globmatch($viewer, $this->usermatch);
        $m = [];
        foreach ($this->psets as $pset) {
            $sset->set_pset($pset);
            $runners = $this->all_runners ? array_keys($pset->runners) : $this->runners;
            foreach ($sset as $info) {
                $rl = $info->run_logger();
                foreach ($runners as $r) {
                    foreach ($info->recorded_jobs($r) as $rt) {
                        $rr = $rl->job_response($rt);
                        if ($this->tags) {
                            $has = false;
                            foreach ($rr->tags ?? [] as $t) {
                                if (in_array($t, $this->tags)) {
                                    $has = true;
                                }
                            }
                            if (!$has) {
                                continue;
                            }
                        }
                        $t = $rl->output_file($rt);
                        if (!$this->quiet) {
                            $tx = ["-p{$pset->key}", "-r{$rr->runner}"];
                            foreach ($rr->tags ?? [] as $tag) {
                                $tx[] = "#{$tag}";
                            }
                            if (!empty($tx)) {
                                $t = $t . "   " . join(" ", $tx);
                            }
                        }
                        $m[] = "{$rt}|{$t}\n";
                    }
                }
            }
        }
        rsort($m, SORT_NATURAL);
        foreach ($m as &$l) {
            $l = substr($l, strpos($l, "|") + 1);
        }
        fwrite(STDOUT, join("", $m));
        return 0;
    }

    /** @return int */
    function run() {
        if ($this->mode === "update") {
            return $this->run_update();
        }
        return $this->run_list();
    }

    /** @return RecordedJobs_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p[],pset[] Problem set",
            "u[],user[] Match these users",
            "r[],runner[],run[] Match these runners",
            "t[],tag[] Match these tags",
            "H:,hash:,commit: Use this commit",
            "q,quiet !list Do not print runner",
            "V,verbose",
            "help"
        )->helpopt("help")
         ->subcommand(
            "list Print recorded jobs",
            "update Update recorded jobs"
        )->parse($argv);

        $psets = [];
        foreach ($arg["p"] ?? [] as $pkey) {
            if (($pset = $conf->pset_by_key($pkey))
                && !$pset->gitless) {
                $psets[] = $pset;
            } else if ($pset) {
                throw new CommandLineException("pset {$pkey} has no jobs");
            } else {
                throw new CommandLineException("no such pset");
            }
        }
        if (empty($psets)) {
            foreach ($conf->psets() as $pset) {
                if (!$pset->disabled && !$pset->gitless)
                    $psets[] = $pset;
            }
        }

        if (($mode = $arg["_subcommand"] ?? null) === null) {
            $mode = "list";
        }

        $self = new RecordedJobs_Batch($conf, $psets, $arg["u"] ?? [], $mode);
        if (isset($arg["V"])) {
            $self->verbose = true;
        }
        if (isset($arg["H"])) {
            if (!($hp = CommitRecord::parse_hashpart($arg["H"]))) {
                throw new CommandLineException("bad `--commit`");
            }
            $self->hash = $hp;
        }
        if (isset($arg["r"])) {
            $self->runners = $arg["r"];
            $self->all_runners = false;
        }
        if (isset($arg["t"])) {
            $self->tags = $arg["t"];
        }
        if (isset($arg["q"])) {
            $self->quiet = true;
        }

        return $self;
    }
}


if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(RecordedJobs_Batch::make_args(Conf::$main, $argv)->run());
}
