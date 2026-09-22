<?php
// leaderboard.php -- Peteramati script for maintaining leaderboard metrics
// Peteramati is Copyright (c) 2006-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    CommandLineException::$default_exit_status = 2;
}


class Leaderboard_Batch {
    /** @var Conf */
    public $conf;
    /** @var list<Pset> */
    public $psets;
    /** @var list<string> */
    public $usermatch;
    /** @var 'show'|'refresh'|'clear' */
    public $mode;
    /** @var list<string> */
    public $metrics = [];
    /** @var bool */
    public $verbose = false;

    /** @param list<Pset> $psets
     * @param list<string> $usermatch
     * @param string $mode */
    function __construct(Conf $conf, $psets, $usermatch, $mode) {
        $this->conf = $conf;
        $this->psets = $psets;
        $this->usermatch = $usermatch;
        $this->mode = $mode;
    }

    /** @param LeaderboardMetric $m
     * @return bool */
    private function want_metric($m) {
        return empty($this->metrics) || in_array($m->key, $this->metrics, true);
    }

    /** Return `$info`'s most recent completed run of `$runner` on the
     * user's branch. Several users can share a repository on different
     * branches, and run logs are per repository. Runs record their branch;
     * for older runs that don't, require the commit to be on the branch.
     * @return ?RunResponse */
    private function latest_branch_response(PsetView $info, RunnerConfig $runner) {
        $branch = $info->branch();
        $commits = null;
        // responses are newest first
        foreach ($info->run_logger()->completed_responses($runner) as $rr) {
            if ($rr->branch !== null) {
                if ($rr->branch === $branch) {
                    return $rr;
                }
            } else if ($rr->hash) {
                $commits = $commits ?? $info->repo->commits($info->pset, $branch, ".");
                if (isset($commits[$rr->hash])) {
                    return $rr;
                }
            }
        }
        return null;
    }

    /** Recompute metrics from each student's most recent completed run of
     * each leaderboard's runner on their branch.
     * @return int */
    function run_refresh() {
        $viewer = $this->conf->site_contact();
        $sset = StudentSet::make_globmatch($viewer, $this->usermatch);
        foreach ($this->psets as $pset) {
            // leaderboards to refresh, with the metric keys to store (null = all)
            $lbcs = [];
            foreach ($pset->leaderboards as $lbc) {
                $keys = [];
                foreach ($lbc->metrics as $m) {
                    if ($this->want_metric($m)) {
                        $keys[] = $m->key;
                    }
                }
                if (!$lbc->disabled && !empty($keys)) {
                    $lbcs[] = [$lbc, count($keys) === count($lbc->metrics) ? null : $keys];
                }
            }
            if (empty($lbcs)) {
                continue;
            }
            $sset->set_pset($pset);
            $nu = 0;
            foreach ($sset as $info) {
                if (!$info->repo) {
                    continue;
                }
                $changed = false;
                foreach ($lbcs as [$lbc, $keys]) {
                    $rr = $this->latest_branch_response($info, $lbc->runner);
                    if (!$rr
                        || !$rr->hash
                        || !$info->set_hash($rr->hash, false, $sset)) {
                        if ($rr && $this->verbose) {
                            fwrite(STDERR, "~{$info->user->username}/{$pset->urlkey}/{$lbc->name}: job {$rr->timestamp} commit " . ($rr->hash ?? "(none)") . " not found\n");
                        }
                        continue;
                    }
                    if (Leaderboard::record($info, $lbc, $rr->timestamp, $keys, true)) {
                        $changed = true;
                        if ($this->verbose) {
                            fwrite(STDERR, "~{$info->user->username}/{$pset->urlkey}/{$lbc->name}: recorded from {$lbc->runner->name} job {$rr->timestamp}\n");
                        }
                    }
                }
                $nu += $changed ? 1 : 0;
            }
            fwrite(STDERR, "{$pset->urlkey}: changed metrics for {$nu} " . plural_word($nu, "student")
                . ", leaderboard has " . $this->count_entries($pset) . "\n");
        }
        return 0;
    }

    /** @return string */
    private function count_entries(Pset $pset) {
        $lb = new Leaderboard($pset, $this->conf->root_user());
        $x = [];
        foreach ($pset->leaderboard as $key => $m) {
            $x[] = "{$key} " . count($lb->entries($key));
        }
        $nu = count($lb->users());
        return "{$nu} " . plural_word($nu, "student")
            . (empty($x) ? "" : " (" . join(", ", $x) . ")");
    }

    /** @return int */
    function run_clear() {
        $n = 0;
        foreach ($this->psets as $pset) {
            $keys = [];
            foreach ($pset->leaderboard as $m) {
                if ($this->want_metric($m)) {
                    $keys[] = $m->key;
                }
            }
            $n += Leaderboard::clear($pset, $keys);
        }
        fwrite(STDERR, "Cleared metrics for {$n} student" . ($n === 1 ? "" : "s") . "\n");
        return 0;
    }

    /** @return int */
    function run_show() {
        $viewer = $this->conf->root_user();
        foreach ($this->psets as $pset) {
            $lb = new Leaderboard($pset, $viewer);
            foreach ($pset->leaderboard as $m) {
                if (!$this->want_metric($m)) {
                    continue;
                }
                // best first; ties share a rank
                $entries = $lb->entries($m->key);
                $sign = $m->best_is_min ? 1 : -1;
                $users = $lb->users();
                usort($entries, function ($a, $b) use ($sign, $users) {
                    return $sign * ($a->value <=> $b->value)
                        ?: strcmp($users[$a->cid]->name, $users[$b->cid]->name);
                });
                fwrite(STDOUT, "{$pset->urlkey}.{$m->key} ({$m->title}, best is "
                    . ($m->best_is_min ? "min" : "max") . "): "
                    . count($entries) . " entries\n");
                $rank = 0;
                foreach ($entries as $i => $e) {
                    if ($i === 0 || $e->value != $entries[$i - 1]->value) {
                        $rank = $i + 1;
                    }
                    $u = $users[$e->cid] ?? null;
                    $name = $u ? $u->name : "?";
                    $who = $u ? $u->user : "#{$e->cid}";
                    fwrite(STDOUT, sprintf("  %4d  %-28s %14s  %s\n",
                        $rank, $name, $m->unparse_value($e->value) . ($m->unit === null ? "" : " {$m->unit}"), $who));
                }
            }
        }
        return 0;
    }

    /** @return int */
    function run() {
        if ($this->mode === "refresh") {
            return $this->run_refresh();
        } else if ($this->mode === "clear") {
            return $this->run_clear();
        }
        return $this->run_show();
    }

    /** @return Leaderboard_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p[],pset[] Problem set",
            "u[],user[] Match these users",
            "m[],metric[] Restrict to these metrics",
            "V,verbose",
            "help"
        )->helpopt("help")
         ->subcommand(
            "show Print recorded leaderboard metrics",
            "refresh Recompute metrics from recorded runs",
            "clear Delete recorded metrics"
        )->description("Maintain peteramati leaderboard metrics.
Usage: php batch/leaderboard.php [show|refresh|clear] [-p PSET]...")
         ->parse($argv);

        $psets = [];
        foreach ($arg["p"] ?? [] as $pkey) {
            if (!($pset = $conf->pset_by_key($pkey))) {
                throw new CommandLineException("no such pset");
            } else if (!$pset->has_leaderboard()) {
                throw new CommandLineException("pset {$pkey} has no leaderboard");
            }
            $psets[] = $pset;
        }
        if (empty($psets)) {
            foreach ($conf->psets() as $pset) {
                if (!$pset->disabled && $pset->has_leaderboard()) {
                    $psets[] = $pset;
                }
            }
        }
        if (empty($psets)) {
            throw new CommandLineException("no psets configure a leaderboard");
        }

        $self = new Leaderboard_Batch($conf, $psets, $arg["u"] ?? [],
            $arg["_subcommand"] ?? "show");
        if (isset($arg["m"])) {
            $self->metrics = $arg["m"];
        }
        if (isset($arg["V"])) {
            $self->verbose = true;
        }
        return $self;
    }
}


if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(Leaderboard_Batch::make_args(Conf::$main, $argv)->run());
}
