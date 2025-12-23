<?php
// gradereport.php -- Peteramati script for generating grade CSV
// HotCRP and Peteramati are Copyright (c) 2006-2025 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}

class GradeReport_Batch {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var bool */
    public $quiet = false;
    /** @var bool */
    public $verbose = false;
    /** @var list<string> */
    public $usermatch = [];
    /** @var ?SearchExpr */
    public $commitq;
    /** @var bool */
    public $all = false;
    /** @var list<GradeEntry> */
    public $ge = [];
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
        }
        return false;
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

    /** @return \Generator<?string> */
    function relevant_commits(PsetView $info, $bhashes) {
        if ($this->pset->gitless_grades) {
            yield null;
            return;
        }
        if (!$this->all && !$this->commitq) {
            $ghash = $info->grading_bhash();
            $bhashes = $ghash ? [$ghash] : [$bhashes[0]];
        }
        foreach ($bhashes as $bhash) {
            $hash = bin2hex($bhash);
            if (!$info->set_hash($hash)) {
                if ($this->verbose) {
                    fwrite(STDERR, $this->unparse_key($info, $hash) . ": Commit not found\n");
                }
                continue;
            }
            if ($this->commitq && !$info->test($this->commitq)) {
                if ($this->verbose) {
                    fwrite(STDERR, $this->unparse_key($info, $hash) . ": Commit does not match\n");
                }
                continue;
            }
            yield $hash;
            if (!$this->all) {
                return;
            }
        }
    }

    /** @return int */
    function run() {
        // list all possible commits
        $repo_bhashes = [];
        if (!$this->pset->gitless_grades) {
            $result = $this->conf->qe("select repoid, bhash from CommitNotes where pset=? order by repoid asc, commitat desc", $this->pset->id);
            while (($row = $result->fetch_row())) {
                $repo_bhashes[(int) $row[0]][] = $row[1];
            }
            $result->close();
        }

        if (empty($this->ge)) {
            $this->ge = iterator_to_array($this->pset->grades());
        }

        $header = ["email"];
        if (!$this->pset->gitless_grades) {
            $header[] = "hash";
            $header[] = "timestamp";
            $header[] = "grading";
        }
        foreach ($this->ge as $ge) {
            $header[] = $ge->key;
        }

        $csv = (new CsvGenerator)->set_stream(STDOUT)->select($header);

        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, $this->sset_flags, [$this, "test_user"]);
        $sset->set_pset($this->pset);

        foreach ($sset as $info) {
            $bhashes = $info->repo ? $repo_bhashes[$info->repo->repoid] ?? [] : [];
            if (!$this->pset->gitless_grades && empty($bhashes)) {
                continue;
            }
            foreach ($this->relevant_commits($info, $bhashes) as $hash) {
                $row = ["email" => $info->user->email];
                if (!$this->pset->gitless_grades) {
                    $row["hash"] = $hash;
                    $row["timestamp"] = $info->commit()->commitat;
                    $row["grading"] = $info->is_grading_commit() ? "yes" : "";
                }
                foreach ($info->visible_grades(VF_TF) as $ge) {
                    if (!in_array($ge, $this->ge, true)) {
                        continue;
                    }
                    $row[$ge->key] = $info->grade_value($ge);
                }
                $csv->add_row($row);
            }
        }

        $csv->flush();
        return 0;
    }

    /** @return GradeReport_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p:,pset: Problem set",
            "u[]+,user[]+ Match these users",
            "all,a Report all commits",
            "commit-query:,cq:,commitq: Use commits matching this search",
            "g[]+,grade[]+ Include these grades",
            "q,quiet",
            "V,verbose",
            "help"
        )->helpopt("help")
         ->description("Usage: php batch/gradereport.php -p PSET [-u USERS...] [--cq C]")
         ->otheropt(false)
         ->parse($argv);

        $pset_arg = $arg["p"] ?? "";
        $pset = $conf->pset_by_key($pset_arg);
        if (!$pset) {
            $pset_keys = array_values(array_map(function ($p) { return $p->key; }, $conf->psets()));
            throw (new CommandLineException($pset_arg === "" ? "`--pset` required" : "Pset `{$pset_arg}` not found"))->add_context("(Options are " . join(", ", $pset_keys) . ".)");
        }
        $self = new GradeReport_Batch($pset, $arg["u"] ?? []);
        $self->quiet = isset($arg["q"]);
        $self->verbose = isset($arg["V"]);
        $self->all = isset($arg["all"]);
        if (isset($arg["commit-query"])) {
            $self->commitq = PsetView::parse_commit_query($arg["commit-query"]);
        }
        foreach ($arg["g"] ?? [] as $gradename) {
            if (!($ge = $pset->gradelike_by_key($gradename))) {
                throw new CommandLineException("Grade `{$gradename}` not found in pset `{$pset->key}`");
            }
            $self->ge[] = $ge;
        }
        return $self;
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(GradeReport_Batch::make_args(Conf::$main, $argv)->run());
}
