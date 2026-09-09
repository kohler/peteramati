<?php
// github_studentsetup.php -- Peteramati student-driven repository setup
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler
// See LICENSE for open-source distribution terms

// Student-driven counterpart to `batch/githubadmin.php create-repo`. The
// student clicks a button, authorizes the course's GitHub App, and returns
// with a user access token that proves which GitHub account is theirs. The
// app then creates the repository and invites them, and the student's own
// token accepts the invitation.
//
// This uses the GitHub App throughout: an app runs the same OAuth web flow as
// an OAuth app, from the same endpoints, so the separate OAuth app is not
// involved. A user token carries no scopes -- what it may do follows from the
// app's permissions -- and both endpoints this flow needs, `GET /user` and
// `PATCH /user/repository_invitations/{id}`, are available to user tokens.
//
// The OAuth step exists for the identity proof. The username form records
// whatever a student types, so a typo -- or a lie -- silently creates a
// repository for the wrong account; a token cannot be typed wrong.
//
// Every step is idempotent, so a student who reloads, or who gets a timeout
// halfway through, can simply click the button again: `GitHub_RepoCreator`
// adopts an existing repository rather than creating a second one, GitHub
// returns the pending invitation again rather than making another, and
// accepting an already-accepted invitation is harmless.
class GitHub_StudentSetup {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Pset */
    public $pset;
    /** @var string */
    private $token;
    /** @var ?Repository */
    public $repo;
    /** @var bool */
    public $created = false;
    /** @var bool */
    public $accepted = false;

    /** @param string $token */
    function __construct(Contact $user, Pset $pset, $token) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->pset = $pset;
        $this->token = $token;
    }

    /** Problem sets that share the student's single course repository, newest
     * first. Peteramati links repositories per problem set, but a course that
     * names repositories without `{pset}` gives each student one repository
     * for the term; link it to every problem set the student can see, and
     * `Contact::forward_pset_links` carries it to problem sets added later.
     * @return list<Pset> */
    static function target_psets(Conf $conf, Contact $user) {
        $psets = [];
        foreach ($conf->psets_newest_first() as $pset) {
            if (!$pset->gitless
                && !$pset->disabled
                && $user->can_view_pset($pset)) {
                $psets[] = $pset;
            }
        }
        return $psets;
    }

    /** True if this installation creates student repositories itself. When it
     * does, GitHub usernames come from a verified sign-in rather than from
     * the username form, which is then read-only for students.
     * @return bool */
    static function configured(Conf $conf) {
        return ($app = $conf->github_app())
            && $app->has_user_auth()
            && !!$conf->opt("githubOrganization");
    }

    /** True if $user could usefully click “Set up my repository”.
     * @return bool */
    static function available(Conf $conf, Contact $user) {
        if (!self::configured($conf)
            || !$user->has_account_here()) {
            return false;
        }
        foreach (self::target_psets($conf, $user) as $pset) {
            if (!$user->repo($pset->id)) {
                return true;
            }
        }
        return false;
    }

    /** The GitHub account backing $this->token.
     * @return ?object */
    private function identity(MessageSet $ms) {
        $ghr = GitHubResponse::make_restapi($this->conf, $this->token, "user", "GET");
        if ($ghr->status !== 200
            || !isset($ghr->response->login)
            || !isset($ghr->response->id)) {
            error_log("GitHub error reading authorized user: " . json_encode($ghr));
            $ms->error_at("repo", "<0>GitHub did not report which account you authorized. Please try again.");
            return null;
        }
        return $ghr->response;
    }

    /** @return bool */
    private function save_identity(MessageSet $ms, $identity) {
        $login = $identity->login;
        if (($cid = $this->user->github_username_conflict($login))) {
            error_log("GitHub username {$login} claimed by contact {$cid}, refused for {$this->user->email}");
            $ms->error_at("repo", "<0>The GitHub account “{$login}” is already recorded for another user on this site. Ask the course staff to sort this out.");
            return false;
        }
        $this->user->set_github_identity($login, (int) $identity->id);
        return true;
    }

    /** Accept the collaborator invitation, using the student's own token --
     * the course's App cannot accept on their behalf.
     * @param int $invitation_id
     * @return bool */
    private function accept_invitation(MessageSet $ms, $invitation_id) {
        $ghr = GitHubResponse::make_restapi($this->conf, $this->token,
            "user/repository_invitations/{$invitation_id}", "PATCH");
        if ($ghr->status === 204 || $ghr->status === 200) {
            $this->accepted = true;
            return true;
        }
        // 404 means it is already accepted, or was accepted in another tab
        if ($ghr->status === 404) {
            return true;
        }
        error_log("GitHub error accepting invitation {$invitation_id} for {$this->user->email}: " . json_encode($ghr));
        $ms->warning_at("repo", "<0>Your repository was created, but the invitation to it could not be accepted automatically. Check your GitHub notifications for the invitation and accept it there.");
        return false;
    }

    /** Run the whole setup. Returns the repository, or null on error, with
     * messages in $ms.
     * @return ?Repository */
    function run(MessageSet $ms) {
        if (!($identity = $this->identity($ms))
            || !$this->save_identity($ms, $identity)) {
            return null;
        }

        if (!($creator = GitHub_RepoCreator::make($this->conf, $this->pset, $this->user))) {
            $ms->error_at("repo", "<0>This course is not configured to create repositories.");
            return null;
        }
        if (!($repo = $creator->run($ms))) {
            return null;
        }
        $this->repo = $repo;
        $this->created = $creator->created;

        // link the one repository to every problem set that shares it
        foreach (self::target_psets($this->conf, $this->user) as $pset) {
            if (!$this->user->repo($pset->id)) {
                $this->user->set_repo($pset, $repo);
            }
        }

        if ($creator->invitation_id !== null) {
            $this->accept_invitation($ms, $creator->invitation_id);
        } else {
            // 204 from the collaborator call: access already effective
            $this->accepted = true;
        }

        $creator->grant_staff_team($ms);
        return $repo;
    }
}
