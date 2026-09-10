<?php
// github_repocreator.php -- Peteramati GitHub repository creation
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler
// See LICENSE for open-source distribution terms

// Creates a private repository for one student in the course organization,
// as GitHub Classroom used to. The student's own credentials are not
// involved: the repository is created by the course's GitHub App, then the
// student is added as a collaborator. Adding a collaborator who is not
// already an organization member leaves an invitation the student must
// accept; `$invitation_id` reports it.
//
// `run` is idempotent. A repository that already exists is adopted rather
// than recreated, and re-adding a collaborator who already has access does
// nothing, so a run that failed partway can simply be repeated.
class GitHub_RepoCreator {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var Contact */
    public $user;
    /** @var GitHubApp */
    private $app;
    /** @var string */
    public $organization;
    /** @var ?string */
    public $name;
    /** @var bool */
    public $created = false;
    /** @var ?int */
    public $invitation_id;
    /** @var ?Repository */
    public $repo;
    /** GraphQL node id of the GitHub repository, needed to grant team access.
     * @var ?string */
    public $repo_nodeid;

    const DEFAULT_PATTERN = "{pset}-{username}";
    /** @var string */
    const NAME_REGEX = '/\A[A-Za-z0-9][-A-Za-z0-9_.]{0,99}\z/';

    private function __construct(GitHubApp $app, Pset $pset, Contact $user, $organization) {
        $this->conf = $user->conf;
        $this->app = $app;
        $this->pset = $pset;
        $this->user = $user;
        $this->organization = $organization;
    }

    /** @return ?GitHub_RepoCreator */
    static function make(Conf $conf, Pset $pset, Contact $user) {
        if (!($app = $conf->github_app())
            || !($organization = $conf->opt("githubOrganization"))) {
            return null;
        }
        return new GitHub_RepoCreator($app, $pset, $user, $organization);
    }

    /** @return string */
    private function repo_path() {
        return "repos/" . urlencode($this->organization) . "/" . urlencode($this->name);
    }

    /** @param string $what
     * @return null */
    private function api_error(MessageSet $ms, GitHubResponse $ghr, $what) {
        error_log("GitHub error {$what} {$this->organization}/{$this->name}: " . json_encode($ghr));
        if ($ghr->response === null && $ghr->status >= 500) {
            // no HTTP response at all -- the request failed or was never
            // sent. Don't blame GitHub for what is usually a local problem.
            $ms->error_at("repo", "<0>Could not reach GitHub. Try again in a moment; if this persists, ask the course staff to check the server error log.");
            return null;
        }
        $detail = $ghr->response->message ?? null;
        $ms->error_at("repo", "<0>GitHub reported an error {$what} the repository"
            . ($detail ? ": {$detail}" : "."));
        return null;
    }

    /** @return ?string */
    private function repo_name(MessageSet $ms) {
        $pattern = $this->pset->github_repo_pattern
            ?? $this->conf->opt("githubRepoPattern")
            ?? self::DEFAULT_PATTERN;
        $name = str_replace(["{pset}", "{username}"],
            [$this->pset->urlkey, $this->user->github_username], $pattern);
        if (!preg_match(self::NAME_REGEX, $name)) {
            $ms->error_at("repo", "<0>“{$name}” is not a usable GitHub repository name");
            return null;
        }
        return $name;
    }

    /** @return bool */
    private function create_repository(MessageSet $ms) {
        $body = [
            "name" => $this->name,
            "private" => true,
            "description" => "{$this->pset->title}: {$this->user->github_username}"
        ];
        if (($template = $this->pset->github_template_repo)) {
            $body["owner"] = $this->organization;
            $ghr = $this->app->restapi("repos/{$template}/generate", "POST", $body);
        } else {
            // bare repository: no README, no initial commit, no branches
            $body["auto_init"] = false;
            $ghr = $this->app->restapi("orgs/" . urlencode($this->organization) . "/repos",
                "POST", $body);
        }
        if ($ghr->status !== 201) {
            $this->api_error($ms, $ghr, "creating");
            return false;
        }
        $this->repo_nodeid = $ghr->response->node_id ?? null;
        $this->created = true;
        return true;
    }

    /** Give the course staff team admin access, as `batch/githubadmin.php`
     * does in bulk. Failure here is reported but not fatal: the student's
     * repository exists and they can use it, and `githubadmin.php add-team`
     * can grant staff access later.
     * @return bool */
    function grant_staff_team(MessageSet $ms) {
        $team = $this->conf->opt("githubStaffTeam");
        if (!$team) {
            return true;
        } else if ($this->repo_nodeid === null) {
            $ms->warning_at("repo", "<0>Could not grant course staff access to the repository (no repository id)");
            return false;
        }
        $ghr = $this->app->graphql("query { organization(login: "
            . json_encode($this->organization) . ") { team(slug: "
            . json_encode($team) . ") { id } } }");
        if (($teamid = $ghr->rdata->organization->team->id ?? null) === null) {
            error_log("GitHub error looking up team {$this->organization}/{$team}: " . json_encode($ghr));
            $ms->warning_at("repo", "<0>Could not look up the course staff team on GitHub");
            return false;
        }
        $ghr = $this->app->graphql("mutation { updateTeamsRepository(input: {repositoryId: "
            . json_encode($this->repo_nodeid) . ", permission: ADMIN, teamIds: ["
            . json_encode($teamid) . "]}) { clientMutationId } }");
        if ($ghr->rdata === null) {
            error_log("GitHub error granting {$team} access to {$this->organization}/{$this->name}: " . json_encode($ghr));
            $ms->warning_at("repo", "<0>Could not grant course staff access to the repository");
            return false;
        }
        return true;
    }

    /** @return bool */
    private function add_collaborator(MessageSet $ms) {
        $ghr = $this->app->restapi($this->repo_path() . "/collaborators/"
                . urlencode($this->user->github_username),
            "PUT", ["permission" => "push"]);
        if ($ghr->status === 201) {
            // not an organization member, so GitHub made an invitation
            $this->invitation_id = $ghr->response->id ?? null;
            return true;
        } else if ($ghr->status === 204) {
            // already a collaborator, or covered by organization membership
            return true;
        }
        $this->api_error($ms, $ghr, "granting access to");
        return false;
    }

    /** Create the student's repository if it does not exist, grant access,
     * and link it to the problem set.
     * @return ?Repository */
    function run(MessageSet $ms) {
        if ($this->pset->gitless) {
            $ms->error_at("repo", "<0>Problem set does not use git");
            return null;
        } else if (!$this->user->github_username) {
            $ms->error_at("repo", "<0>This user has no GitHub username");
            return null;
        } else if (($this->name = $this->repo_name($ms)) === null) {
            return null;
        } else if (!$this->app->installation_token()) {
            // e.g. the web server cannot read `githubAppKeyFile`. Every API
            // call below would come back with no response at all, which reads
            // like a GitHub outage; say what is actually wrong instead.
            $ms->error_at("repo", "<0>This course’s GitHub App credentials are not working. Ask the course staff to check the server error log.");
            return null;
        }

        $ghr = $this->app->restapi($this->repo_path(), "GET");
        if ($ghr->status === 404) {
            if (!$this->create_repository($ms)) {
                return null;
            }
        } else if ($ghr->status !== 200) {
            return $this->api_error($ms, $ghr, "looking up");
        } else {
            $this->repo_nodeid = $ghr->response->node_id ?? null;
        }

        if (!$this->add_collaborator($ms)) {
            return null;
        }

        $reposite = GitHub_RepositorySite::make_url("{$this->organization}/{$this->name}", $this->conf);
        if (!$reposite
            || !($repo = Repository::find_or_create_url($reposite->url, $this->conf))) {
            $ms->error_at("repo", "<0>Cannot record repository {$this->organization}/{$this->name}");
            return null;
        }
        $this->user->set_repo($this->pset, $repo);
        // The course made this repository for this student and granted them
        // access, so their ownership of it is established. Record that, or
        // `Repository::check_ownership` would make them prove it the way a
        // student who supplied their own repository must -- by pushing a
        // commit authored from their course email address.
        $this->user->add_link(LINK_REPOVIEW, 0, $repo->repoid);
        return ($this->repo = $repo);
    }
}
