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

    private function report_error($error) {
        $r = $this->repo ? $this->repo->reponame() . ": " : "";
        error_log($r . $error);
    }

    const FRH_ONCE = 1;

    /** @param list<string> $heads
     * @param list<string> $eliminate
     * @param int $flags
     * @return list<string> */
    private function filter_redundant_heads($heads, $eliminate, $flags) {
        $newheads = [];
        while (!empty($heads)) {
            $gi = $this->gitruninfo([
                "git", "rev-list", ...$heads, "--not", ...$eliminate
            ]);
            if (!$gi->ok) {
                $this->report_error("`git rev-list " . join(" ", $heads) . " --not " . join(" ", $eliminate) . "`: {$gi->stderr}");
            }
            $nosearch = str_starts_with($heads[0], "--no-walk");
            $foundheads = [];
            foreach (explode("\n", $gi->stdout) as $hash) {
                if ($hash === "") {
                    // never include
                } else if ($nosearch) {
                    $foundheads[] = $hash;
                } else if (($i = array_search($hash, $heads)) !== false) {
                    $foundheads[] = $hash;
                    if (count($heads) === 1) {
                        break;
                    }
                    array_splice($heads, $i, 1);
                }
            }
            if (empty($foundheads)) {
                break;
            } else if (($flags & self::FRH_ONCE) !== 0) {
                $newheads = $foundheads;
                break;
            }
            $latesthash = array_shift($foundheads);
            $newheads[] = $latesthash;
            $heads = $foundheads;
            $eliminate = [$latesthash];
        }
        return $newheads;
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

    /** @param list<string> $branches */
    private function handle_fetch_changed($branches) {
        // match each tag to a hash
        $command = ["git", "rev-list", "--no-walk=unsorted"];
        foreach ($branches as $br) {
            $command[] = "{$this->reponame}/{$br}";
        }
        $branchhashes = explode("\n", rtrim($this->gitrun($command)));
        if (count($branchhashes) !== count($branches)) {
            $this->report_error("`git rev-list --no-walk=unsorted` has " . count($branchhashes) . " hashes, expected " . count($branches));
        }

        // eliminate already-found and redundant revisions
        $newhashes = $this->filter_redundant_heads($branchhashes, ["--tags={$this->reponame}.snap*"], 0);
        if (empty($newhashes)) {
            return;
        }

        // tag newly-found revisions
        $timestr = null;
        foreach ($newhashes as $hash) {
            $timestr = $timestr ?? gmdate("Ymd\\THis", Conf::$now);
            $i = array_search($hash, $branchhashes);
            $this->gitrun([
                "git", "tag", "{$this->reponame}.snap{$timestr}.{$branches[$i]}", $hash
            ]);
        }

        // eliminate now-redundant heads
        $oldheads = explode(" ", $this->repo->heads ?? "");
        if (count($oldheads) > 1) {
            array_shift($oldheads);
            $relevant_oldheads = $this->filter_redundant_heads($oldheads, $newhashes, self::FRH_ONCE);
            array_push($newhashes, ...$relevant_oldheads);
        }

        // save changes
        $snaphash = self::first_hash($newhashes);
        $this->conf->qe("update Repository set snapat=?, snaphash=?,
            heads=?, working=?, snapcheckat=?
            where repoid=?",
            Conf::$now, $snaphash ?? $this->repo->snaphash,
            join(" ", $newhashes), Conf::$now, Conf::$now,
            $this->repo->repoid);
        if ($this->verbose) {
            fwrite(STDERR, "+ {$this->reponame}: " . json_encode([
                "snapcheckat" => Conf::$now, "snaphash" => $snaphash,
                "heads" => $newhashes
            ]) . "\n");
        }
    }

    private function handle_fetch_unchanged() {
        $this->conf->qe("update Repository set snapat=coalesce(snapat,?),
            working=?, snapcheckat=?
            where repoid=?",
            Conf::$now, Conf::$now, Conf::$now,
            $this->repo->repoid);
        if ($this->verbose) {
            fwrite(STDERR, "+ " . $this->repo->reponame() . ": " . json_encode([
                "snapcheckat" => Conf::$now, "unchanged" => true
            ]) . "\n");
        }
    }

    private function handle_fetch_failed() {
        $this->conf->qe("update Repository set working=0, snapcheckat=? where repoid=?",
            Conf::$now, $this->repo->repoid);
        if ($this->verbose) {
            fwrite(STDERR, "- " . $this->repo->reponame() . ": " . json_encode([
                "snapcheckat" => Conf::$now, "working" => false
            ]) . "\n");
        }
    }

    private function recheck_heads() {
        $newhashes = $this->filter_redundant_heads(["--no-walk", "--remotes={$this->reponame}/*", "--tags={$this->reponame}.snap*"], [], 0);
        $snaphash = self::first_hash($newhashes);
        $this->conf->qe("update Repository set snaphash=?, heads=?
            where repoid=?",
            $snaphash, join(" ", $newhashes),
            $this->repo->repoid);
        if ($this->verbose) {
            fwrite(STDERR, "= {$this->reponame} force: " . json_encode([
                "snaphash" => $snaphash, "heads" => $newhashes
            ]) . "\n");
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
