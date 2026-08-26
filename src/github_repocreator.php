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
            $ghr = $this->app->restapi("orgs/" . urlencode($this->organization) . "/repos",
                "POST", $body);
        }
        if ($ghr->status !== 201) {
            $this->api_error($ms, $ghr, "creating");
            return false;
        }
        $this->created = true;
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
        }

        $ghr = $this->app->restapi($this->repo_path(), "GET");
        if ($ghr->status === 404) {
            if (!$this->create_repository($ms)) {
                return null;
            }
        } else if ($ghr->status !== 200) {
            return $this->api_error($ms, $ghr, "looking up");
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
        return ($this->repo = $repo);
    }
}
