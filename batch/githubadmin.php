<?php
// githubadmin.php -- Peteramati script for administering GitHub repositories
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(GitHubAdmin_Batch::make_args(Conf::$main, $argv)->run());
}


class GitHubAdmin_Batch {
    /** @var Conf */
    public $conf;
    /** @var 'add-team'|'remove-team' */
    public $subcommand;
    /** @var ?string */
    public $team;
    /** @var ?string */
    public $user;
    /** @var list<Repository> */
    public $repos = [];
    /** @var list<string> */
    public $usermatch = [];
    /** @var ?Pset */
    public $pset;
    /** @var 'ADMIN'|'NONE'|'READ'|'WRITE'|'TRIAGE'|'MAINTAIN' */
    public $role = "ADMIN";
    /** @var ?int */
    public $count;
    /** @var bool */
    public $verbose;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;

        $this->subcommand = $arg["_subcommand"] ?? "";
        $is_remove = str_starts_with($this->subcommand, "remove");
        if ($this->subcommand === "add-team" || $this->subcommand === "remove-team") {
            $this->team = $arg["_"][0] ?? "";
            if ($this->team === "") {
                throw new CommandLineException("team argument missing");
            }
        } else {
            throw new CommandLineException("unknown subcommand");
        }

        if (isset($arg["r"])) {
            if (isset($arg["u"])) {
                throw new CommandLineException("can't specify both -r and -u");
            }
            $result = $conf->ql("select * from Repository where repoid?a", $arg["r"]);
            while (($repo = Repository::fetch($result, $conf))) {
                if ($repo->reposite instanceof GitHub_RepositorySite)
                    $this->repos[] = $repo;
            }
            $result->close();
            if (count($arg["r"]) !== count($this->repos)) {
                throw new CommandLineException("some repos not found");
            }
        }

        foreach ($arg["u"] ?? [] as $u) {
            if (str_ends_with($u, ",")) {
                $u = substr($u, 0, -1);
            }
            if ($u !== "") {
                $this->usermatch[] = $u;
            }
        }
        if (isset($arg["p"])) {
            $this->pset = $conf->pset_by_key($arg["p"]);
            if (!$this->pset) {
                throw new CommandLineException("bad pset");
            }
        }

        if ($is_remove) {
            if (isset($arg["role"]) && $arg["role"] !== "NONE") {
                throw new CommandLineException("bad role");
            }
            $this->role = "NONE";
        } else if (isset($arg["role"])) {
            $this->role = $arg["role"];
            if (!in_array($this->role, ["ADMIN", "MAINTAIN", "NONE", "READ", "WRITE"])) {
                throw new CommandLineException("bad role");
            }
        }

        $this->count = $arg["n"] ?? null;
        $this->verbose = isset($arg["V"]);
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
        return empty($this->usermatch)
            || $this->match($user->email)
            || $this->match($user->github_username)
            || $this->match($user->anon_username);
    }

    private function add_user_repositories() {
        $viewer = $this->conf->site_contact();
        $sset_flags = isset($arg["u"]) ? StudentSet::ALL : StudentSet::ALL_ENROLLED;
        $sset = new StudentSet($viewer, $sset_flags, [$this, "test_user"]);

        $psets = [];
        if ($this->pset) {
            $psets[] = $this->pset;
        } else {
            foreach ($this->conf->psets_newest_first() as $pset) {
                if (!$pset->gitless && !$pset->disabled)
                    $psets[] = $pset;
            }
        }

        $repoids = [];
        foreach ($psets as $pset) {
            $sset->set_pset($pset);
            foreach ($sset as $info) {
                if ($info->repo
                    && !isset($repoids[$info->repo->repoid])
                    && $info->repo->reposite instanceof GitHub_RepositorySite) {
                    $this->repos[] = $info->repo;
                    $repoids[$info->repo->repoid] = true;
                }
            }
        }
    }

    /** @param int|Repository $repo
     * @return GitHub_RepositorySite
     * @suppress PhanTypeMismatchReturnSuperType */
    private function reposite($repo) {
        if (is_int($repo)) {
            $repo = $this->repos[$repo];
        }
        return $repo->reposite;
    }

    private function run_collaborator() {
        if (empty($this->repos)) {
            $this->add_user_repositories();
        }
        if ($this->count !== null && count($this->repos) > $this->count) {
            $this->repos = array_slice($this->repos, 0, $this->count);
        }
        if (empty($this->repos)) {
            return 0;
        }

        // first look up id
        if ($this->team) {
            $ghr = GitHub_RepositorySite::graphql($this->conf,
                'query { organization(login: ' . json_encode($this->reposite(0)->organization()) . ') { team(slug: ' . json_encode($this->team) . ') { id } } }');
            $teamid = $ghr->rdata->organization->team->id ?? null;
        } else {
            $ghr = GitHub_RepositorySite::graphql($this->conf,
                'query { user(login: ' . json_encode($this->user) . ') { id } }');
            $teamid = $ghr->rdata->user->id ?? null;
        }
        if ($teamid === null) {
            throw new CommandLineException("bad response");
        }

        // then look up repository ids
        $repoids = [];
        for ($i = 0; $i !== count($this->repos); $i = $iend) {
            $iend = min($i + 100, count($this->repos));
            $q = [];
            for ($j = $i; $j !== $iend; ++$j) {
                $q[] = "repo:" . $this->reposite($j)->base;
            }
            $ghr = GitHub_RepositorySite::graphql($this->conf,
                'query { search(query: ' . json_encode(join(" ", $q)) . ' type: REPOSITORY first: 100) { nodes { ... on Repository { nameWithOwner id } } } }');
            if ($ghr->rdata === null) {
                throw new CommandLineException("bad response");
            }
            foreach ($ghr->rdata->search->nodes ?? [] as $x) {
                $repoids[$x->nameWithOwner] = $x->id;
            }
        }

        // then perform modifications
        $qs = [];
        foreach ($this->repos as $i => $repo) {
            $reposite = $this->reposite($repo);
            $id = $repoids[$reposite->base] ?? null;
            if (!isset($repoids[$reposite->base])) {
                fwrite(STDERR, "{$reposite->base}: cannot look up id\n");
                continue;
            } else if ($this->verbose) {
                fwrite(STDOUT, "{$reposite->base}: {$id}\n");
            }
            $qs[] = "update{$i}: updateTeamsRepository(input: {repositoryId:" . json_encode($id) . ',permission:' . $this->role . ',teamIds:[' . json_encode($teamid) . ']}) { clientMutationId }';
        }
        for ($i = 0; $i !== count($qs); $i = $iend) {
            $iend = min($i + 1, count($qs));
            $ghr = GitHub_RepositorySite::graphql($this->conf,
                'mutation { ' . join(" ", array_slice($qs, $i, $iend - $i)) . '}');
            fwrite(STDERR, json_encode($ghr) . "\n");
        }

        return 0;
    }

    function run() {
        return $this->run_collaborator();
    }

    /** @return GitHubAdmin_Batch */
    static function make_args(Conf $conf, $argv) {
        $getopt = (new Getopt)->long(
            "r[]+,repo[]+,repository[]+ {n} =REPOID Fetch repository REPOID",
            "u[]+,user[]+ =USER Fetch repositories for USER",
            "p:,pset: =PSET Choose repository pset",
            "role: Set team role",
            "n:,count: {n} =COUNT Max number of repositories",
            "V,verbose",
            "help !"
        )->helpopt("help")
         ->subcommand(
            "add-team Add team collaborator",
            "remove-team Remove team collaborator",
        )->description("Administer GitHub remotes.
Usage: php batch/githubadmin.php [-r REPOID | -u USER | -p PSET] [SUBCOMMAND]")
         ->maxarg(1);
        $arg = $getopt->parse($argv);
        return new GitHubAdmin_Batch($conf, $arg);
    }
}
