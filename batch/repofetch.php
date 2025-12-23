<?php
// repofetch.php -- Peteramati script for fetching a remote repository
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(RepoFetch_Batch::make_args(Conf::$main, $argv)->run());
}


class RepoFetch_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $force = false;
    /** @var bool */
    public $verbose = false;
    /** @var ?string */
    private $cacheid;
    /** @var bool */
    public $done = false;
    /** @var bool */
    public $upgrade = false;
    /** @var ?Repository */
    private $repo;
    /** @var list<Repository> */
    private $repos = [];
    /** @var string */
    public $reponame = "<none>";

    function __construct(Conf $conf, ?Repository $repo = null) {
        $this->conf = $conf;
        if ($repo) {
            $this->repos[] = $repo;
        }
    }

    /** @return RepoFetch_Batch */
    static function make_refresh(Conf $conf, $force) {
        $self = new RepoFetch_Batch($conf);
        $result = $conf->qe("select * from Repository r
                where snapcheckat<?
                and exists (select * from ContactLink where type=? and link=r.repoid)
                order by snapcheckat asc limit 1",
            $force ? Conf::$now + 900 : Conf::$now - 900, LINK_REPO);
        $repo = Repository::fetch($result, $conf);
        Dbl::free($result);
        return new RepoFetch_Batch($conf, $repo);
    }

    /** @param int|string $key
     * @return ?RepoFetch_Batch */
    static function make_repo(Conf $conf, $key) {
        if (is_string($key) && str_starts_with($key, "repo")) {
            $key = substr($key, 4);
        }
        if (is_string($key) && ctype_digit($key)) {
            $key = intval($key);
        }
        if (is_int($key)) {
            $result = $conf->qe("select * from Repository where repoid=?", $key);
        } else {
            $result = $conf->qe("select * from Repository where repogid=?", $key);
        }
        $repo = Repository::fetch($result, $conf);
        Dbl::free($result);
        return $repo ? new RepoFetch_Batch($conf, $repo) : null;
    }

    /** @param string $user
     * @return ?RepoFetch_Batch */
    static function make_user(Conf $conf, $user) {
        $u = $conf->user_by_whatever($user);
        if (!$u) {
            return null;
        }
        $rf = new RepoFetch_Batch($conf, null);
        $result = $conf->qe("select * from Repository r
                where exists (select * from ContactLink where cid=? and type=? and link=r.repoid)",
            $u->contactId, LINK_REPO);
        while (($repo = Repository::fetch($result, $conf))) {
            $rf->repos[] = $repo;
        }
        return $rf;
    }


    /** @return string */
    private function remote_url() {
        return rtrim($this->repo->gitrun([
            "git", "config", "remote." . $this->repo->reponame() . ".url"
        ]));
    }

    /** @param list<string> $command
     * @return string */
    static function unparse_command($command) {
        for ($i = 0; $i + 1 < count($command); ) {
            if ($command[$i] === "-c" && str_starts_with($command[$i + 1], "credential.helper=")) {
                array_splice($command, $i, 2);
            } else {
                ++$i;
            }
        }
        return Subprocess::shell_quote_args($command);
    }

    /** @param list<string> $command
     * @return Subprocess */
    private function gitruninfo($command) {
        $gi = $this->repo->gitruninfo($command);
        if ($this->verbose || !$gi->ok) {
            $suffix = $gi->ok ? " → OK\n" : " → status {$gi->status}\n";
            if ($gi->stderr !== "") {
                $suffix .= $gi->stderr . (str_ends_with($gi->stderr, "\n") ? "" : "\n");
            }
            fwrite(STDERR, "* " . self::unparse_command($command) . $suffix);
        }
        return $gi;
    }

    /** @param list<string> $command
     * @return string */
    private function gitrun($command) {
        return $this->gitruninfo($command)->stdout;
    }

    /** @param list<string> $command
     * @return bool */
    private function gitrunok($command) {
        return $this->gitruninfo($command)->ok;
    }

    /** @param list<string> $command
     * @return list<string> */
    private function gitrun_hashes($cmd) {
        $text = $this->gitrun($cmd);
        $hashes = [];
        foreach (explode("\n", $text) as $line) {
            if ((strlen($line) === 40 || strlen($line) === 64)
                && ctype_xdigit($line)) {
                $hashes[] = $line;
            } else if ($line !== "") {
                throw new CommandLineException("`" . self::unparse_command($cmd) . "`: Unexpected output");
            }
        }
        return $hashes;
    }


    /** @param string $out
     * @return list<string> */
    private function fetched_branches($out) {
        if ($this->verbose) {
            fwrite(STDERR, preg_replace('/^(?!\z)/m', "    ", $out));
        }
        $branches = [];
        foreach (explode("\n", $out) as $line) {
            if (!str_starts_with($line, " ")) {
                continue;
            }
            if ((preg_match('/\A [ +]\s+[0-9a-f]+\.\.\.?[0-9a-f]+\s+(\S+)\s+->\s+(\S+)/', $line, $m)
                 || preg_match('/\A \*\s+\[new branch\]\s+(\S+)\s+->\s+(\S+)/', $line, $m))
                && $m[2] === "{$this->reponame}/{$m[1]}") {
                $branches[] = $m[1];
            } else {
                $this->report_error("? {$line}");
            }
        }
        return $branches;
    }

    /** @return list<string> */
    private function all_branches() {
        $text = $this->gitrun(["git", "for-each-ref", "--format=%(refname:short)",
                               "refs/remotes/{$this->reponame}"]);
        $prefix = $this->reponame . "/";
        $answers = [];
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, $prefix)) {
                $answers[] = substr($line, strlen($prefix));
            }
        }
        return $answers;
    }

    private function report_error($error) {
        $r = $this->repo ? $this->repo->reponame() . ": " : "";
        error_log($r . $error);
    }

    /** @param list<string> $hashes
     * @return ?string */
    static function first_hash($hashes) {
        if (!empty($hashes)
            && $hashes[0] !== "0000000000000000000000000000000000000000") {
            return $hashes[0];
        }
        return null;
    }

    /** @param array<string,mixed> $m */
    private function finish_verbose($ch, $m) {
        $a = ["snapcheckat" => Conf::$now];
        if ($this->repo->snaphash) {
            $a["snaphash"] = $this->repo->snaphash;
            $a["heads"] = explode(" ", $this->repo->heads);
        }
        fwrite(STDERR, "{$ch} {$this->reponame}: " . json_encode(array_merge($a, $m)) . "\n");
    }

    /** @param list<string> $branches */
    private function handle_fetch_changed($branches) {
        // match each tag to a hash
        $command = ["git", "rev-list", "--no-walk=unsorted"];
        foreach ($branches as $br) {
            $command[] = "{$this->reponame}/{$br}";
        }
        $branchheads = $this->gitrun_hashes($command);
        if (count($branchheads) !== count($branches)) {
            $this->report_error("`git rev-list --no-walk=unsorted` has " . count($branchheads) . " hashes, expected " . count($branches));
        }

        // eliminate already-found and redundant revisions
        $oldheads = explode(" ", $this->repo->heads ?? "");
        if (!empty($oldheads)) {
            $tail = ["--not", ...$oldheads];
            $newheads = $this->gitrun_hashes(["git", "rev-list", "--no-walk=unsorted", ...$branchheads, ...$tail]);
        } else {
            $newheads = $branchheads;
        }
        if (empty($newheads)) {
            $this->handle_fetch_unchanged();
            return;
        }

        // tag newly-found revisions
        $timestr = null;
        foreach ($newheads as $hash) {
            $timestr = $timestr ?? gmdate("Ymd\\THis", Conf::$now);
            $i = array_search($hash, $branchheads);
            $this->gitrun([
                "git", "tag", "{$this->reponame}.snap{$timestr}.{$branches[$i]}", $hash
            ]);
        }

        // find minimal independent set of heads (before tagging)
        $heads = $this->gitrun_hashes(["git", "rev-list", "--no-walk=unsorted", "--tags={$this->reponame}.snap*"]);
        if (count($heads) > 1) {
            $heads = $this->gitrun_hashes(["git", "merge-base", "--independent", ...$heads]);
        }

        // save changes
        $snaphash = self::first_hash($newheads);
        $this->conf->qe("update Repository set snapat=?, snaphash=?,
            heads=?, working=?, snapcheckat=?
            where repoid=?",
            Conf::$now, $snaphash ?? $this->repo->snaphash,
            join(" ", $heads), Conf::$now, Conf::$now,
            $this->repo->repoid);
        $this->repo->snaphash = $snaphash;
        $this->repo->heads = join(" ", $heads);
        if ($this->verbose) {
            $this->finish_verbose("+", ["changed" => true]);
        }
    }

    private function handle_fetch_unchanged() {
        $this->conf->qe("update Repository set snapat=coalesce(snapat,?),
            working=?, snapcheckat=?
            where repoid=?",
            Conf::$now, Conf::$now, Conf::$now,
            $this->repo->repoid);
        if ($this->verbose) {
            $this->finish_verbose("+", ["changed" => false]);
        }
    }

    private function handle_fetch_failed() {
        $this->conf->qe("update Repository set working=0, snapcheckat=? where repoid=?",
            Conf::$now, $this->repo->repoid);
        if ($this->verbose) {
            $this->finish_verbose("-", ["working" => false]);
        }
    }

    private function recheck_heads() {
        $all = $this->gitrun_hashes([
            "git", "rev-list", "--no-walk=unsorted", "--remotes={$this->reponame}/*",
            "--tags={$this->reponame}.snap*"
        ]);
        if (empty($all)) {
            $heads = [];
        } else {
            $heads = $this->gitrun_hashes([
                "git", "merge-base", "--independent", ...$all
            ]);
        }
        $snaphash = self::first_hash($heads);
        $this->conf->qe("update Repository set snaphash=?, heads=?
            where repoid=?",
            $snaphash, join(" ", $heads),
            $this->repo->repoid);
        if ($this->verbose) {
            $this->finish_verbose("=", ["recompute" => true]);
        }
    }

    /** @return int */
    private function run_repo(Repository $repo) {
        $this->repo = $repo;
        if ($this->upgrade) {
            $repo->upgrade();
        }
        if ($this->cacheid !== null) {
            $this->repo->override_cacheid($this->cacheid);
        }
        $repodir = $this->repo->ensure_repodir();
        if (!$repodir) {
            throw new CommandLineException("Cannot create repository");
        }
        if ($this->verbose) {
            fwrite(STDERR, "* repo{$this->repo->repoid}, {$repodir} {$this->repo->url}\n");
        }

        // configure remote
        $this->reponame = $this->repo->reponame();
        $want_repourl = $this->repo->https_url();
        $repourl = $this->remote_url();
        if ($repourl === ""
            && $this->gitrunok(["git", "remote", "add", $this->reponame, $want_repourl])) {
            $repourl = $this->remote_url();
        }
        if ($repourl !== ""
            && $repourl !== $want_repourl
            && $this->gitrunok(["git", "remote", "set-url", $this->reponame, $want_repourl])) {
            $repourl = $this->remote_url();
        }
        if ($repourl !== $want_repourl) {
            throw new CommandLineException($repourl ? "Cannot reconfigure repository ({$repourl} vs. {$want_repourl})" : "Cannot add repository");
        }

        // remove unwritable FETCH_HEAD
        if (file_exists("{$repodir}/FETCH_HEAD")
            && !is_writable("{$repodir}/FETCH_HEAD")) {
            unlink("{$repodir}/FETCH_HEAD");
        }

        // fetch
        $fetchcommand = $this->repo->credentialed_git_command();
        array_push($fetchcommand, "fetch", "--prune", $this->reponame);
        $fetch = $this->gitruninfo($fetchcommand);

        // update snapshots
        $ok = $fetch->ok;
        if (!$ok) {
            $this->handle_fetch_failed();
        } else if (($nbr = $this->fetched_branches($fetch->stdout . $fetch->stderr))) {
            $this->handle_fetch_changed($nbr);
        } else if (false && ($abr = $this->all_branches())) {
            $this->handle_fetch_changed($abr);
        } else {
            $this->handle_fetch_unchanged();
        }

        // force head list
        if ($this->force
            || ($this->repo->snaphash === null && $this->repo->snapat)) {
            $this->recheck_heads();
        }
        return 0;
    }

    /** @return int */
    function run() {
        foreach ($this->repos as $repo) {
            if (($status = $this->run_repo($repo)) !== 0) {
                return $status;
            }
        }
        return 0;
    }

    /** @return RepoFetch_Batch */
    static function make_args(Conf $conf, $argv) {
        $getopt = (new Getopt)->long(
            "r:,repo:,repository: =REPOID Fetch repository REPOID",
            "u:,user: =USER Fetch repositories for USER",
            "refresh Fetch least-recently-updated repository",
            "d:,cacheid: =CACHEID Use repodir CACHEID",
            "f,force Always update fetch heads",
            "upgrade Upgrade internal repository format",
            "V,verbose Be more verbose",
            "bg,background Run in the background",
            "help !"
        )->helpopt("help")
         ->description("Fetch a configured remote.
Usage: php batch/repofetch.php [-r REPOID | -u USER | --refresh]")
         ->maxarg(1);
        $arg = $getopt->parse($argv);
        $is_user = isset($arg["u"]) ? 1 : 0;
        $is_repo = isset($arg["r"]) ? 1 : 0;
        $is_refresh = isset($arg["refresh"]) ? 1 : 0;
        if ($is_user + $is_repo + $is_refresh === 0) {
            if (empty($arg["_"])) {
                $is_refresh = 1;
            } else if (count($arg["_"]) === 1
                       && ctype_digit($arg["_"][0])) {
                $is_repo = 1;
                $arg["r"] = $arg["_"][0];
            } else {
                throw new CommandLineException("Too many arguments", $getopt);
            }
        }
        if ($is_user + $is_repo + $is_refresh !== 1) {
            throw new CommandLineException("Mode conflict", $getopt);
        }
        if ($is_refresh) {
            $self = RepoFetch_Batch::make_refresh($conf, isset($arg["f"]));
        } else if ($is_repo) {
            $self = RepoFetch_Batch::make_repo($conf, $arg["r"]);
            if (!$self) {
                throw new CommandLineException("No such repository", $getopt);
            }
        } else {
            assert($is_user === 1);
            $self = RepoFetch_Batch::make_user($conf, $arg["u"]);
            if (!$self) {
                throw new CommandLineException("No such user", $getopt);
            }
        }
        $self->force = isset($arg["f"]);
        $self->verbose = isset($arg["V"]);
        $self->cacheid = $arg["d"] ?? null;
        $self->upgrade = isset($arg["upgrade"]);
        if (isset($arg["bg"]) && pcntl_fork() > 0) {
            exit(0);
        }
        return $self;
    }
}
