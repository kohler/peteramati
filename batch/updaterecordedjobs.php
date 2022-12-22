<?php
// updaterecordedjobs.php -- Peteramati script for updating database information
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}


class UpdateRecordedJobs_Batch {
    /** @var Conf */
    public $conf;
    /** @var list<Pset> */
    public $psets = [];
    /** @var list<string> */
    public $usermatch = [];
    /** @var ?string */
    public $hash;
    /** @var bool */
    public $verbose = false;
    /** @var int */
    public $sset_flags;

    /** @param list<Pset> $psets
     * @param list<string> $usermatch */
    function __construct(Conf $conf, $psets, $usermatch = []) {
        $this->conf = $conf;
        $this->psets = $psets;
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

    /** @return bool */
    function test_user(Contact $user) {
        if (empty($this->usermatch)) {
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
        return "~{$info->user->username}/{$info->pset->urlkey}/{$hash}";
    }

    /** @return int */
    function run() {
        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, $this->sset_flags, [$this, "test_user"]);
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
        return $nu;
    }

    /** @return int */
    function run_or_warn() {
        $n = $this->run();
        if ($n === 0) {
            fwrite(STDERR, "Nothing to do\n");
        }
        return $n > 0 ? 0 : 1;
    }

    /** @return UpdateRecordedJobs_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p[],pset[] Problem set",
            "u[],user[] Match these users",
            "H:,hash:,commit: Use this commit",
            "V,verbose",
            "help"
        )->helpopt("help")->parse($argv);
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
        $self = new UpdateRecordedJobs_Batch($conf, $psets, $arg["u"] ?? []);
        if (isset($arg["V"])) {
            $self->verbose = true;
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
    exit(UpdateRecordedJobs_Batch::make_args(Conf::$main, $argv)->run_or_warn());
}
