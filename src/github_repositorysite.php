<?php
// github_repositorysite.php -- Peteramati GitHub Classroom repositories
// Peteramati is Copyright (c) 2013-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class GitHubResponse implements JsonSerializable {
    /** @var string */
    public $url;
    /** @var int */
    public $status = 509;
    /** @var string */
    public $status_text;
    /** @var array<string,string> */
    public $headers = [];
    /** @var ?string */
    public $content;
    /** @var ?object */
    public $response;
    /** @var ?object */
    public $rdata;

    function __construct($url) {
        $this->url = $url;
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->response ?? ["status" => $this->status, "content" => $this->content];
    }
    /** Perform a REST request against api.github.com authorized by $token.
     * @param ?string $token
     * @param string $url
     * @param string $method
     * @param null|string|array|object $data
     * @return GitHubResponse */
    static function make_restapi(Conf $conf, $token, $url, $method, $data = null) {
        $response = new GitHubResponse("https://api.github.com/{$url}");
        if ($token && !$conf->opt("disableRemote")) {
            $response->run_request($conf, $method, "application/json", $data,
                "Authorization: Bearer {$token}\r\n"
                . "Accept: application/vnd.github+json\r\n"
                . "X-GitHub-Api-Version: 2022-11-28\r\n");
        }
        return $response;
    }

    /** @param string $method
     * @param ?string $content_type
     * @param null|string|array|object $content
     * @param string $header
     * @return $this */
    function run_request(Conf $conf, $method, $content_type, $content, $header = "") {
        if ($content === null) {
            $content = "";
        } else if (is_array($content) || is_object($content)) {
            if ($content_type === "application/x-www-form-urlencoded") {
                $content = (array) $content;
                $content = join("&", array_map(function ($k, $v) {
                    return urlencode($k) . "=" . urlencode($v);
                }, array_keys($content), array_values($content)));
            } else if ($content_type === "application/json") {
                $content = json_encode($content);
            } else {
                throw new Error();
            }
        }
        $header .= "User-Agent: kohler/peteramati\r\n";
        if ($content !== "") {
            $header .= "Content-Type: {$content_type}\r\n";
        }
        if ($content !== "" || $method !== "GET") {
            // GitHub wants an explicit length on bodyless PUT/POST/PATCH
            $header .= "Content-Length: " . strlen($content) . "\r\n";
        }
        $htopt = [
            "timeout" => (float) $conf->validate_timeout,
            "ignore_errors" => true, "method" => $method,
            "header" => $header, "content" => $content
        ];
        $context = stream_context_create(array("http" => $htopt));
        if (($stream = fopen($this->url, "r", false, $context))) {
            if (($metadata = stream_get_meta_data($stream))
                && ($w = $metadata["wrapper_data"] ?? null)
                && is_array($w)) {
                if (preg_match('/\AHTTP\/[\d.]+\s+(\d+)\s+(.+)\z/', $w[0], $m)) {
                    $this->status = (int) $m[1];
                    $this->status_text = $m[2];
                }
                for ($i = 1; $i != count($w); ++$i) {
                    if (preg_match('/\A(.*?):\s*(.*)\z/', $w[$i], $m))
                        $this->headers[strtolower($m[1])] = $m[2];
                }
            }
            $this->content = stream_get_contents($stream);
            if ($this->content !== false
                && $this->content !== ""
                && (empty($this->headers)
                    || str_starts_with($this->headers["content-type"] ?? "", "application/json"))
                && ($j = json_decode($this->content))
                && is_object($j)) {
                $this->response = $j;
                $rd = $j->data ?? null;
                if ($this->status === 200 && is_object($rd)) {
                    $this->rdata = $rd;
                }
            }
            fclose($stream);
        }
        return $this;
    }
}

class GitHub_RepositorySite extends RepositorySite {
    /** @var Conf */
    public $conf;
    /** @var ?string */
    public $base;

    /** @param string $url
     * @param ?string $base */
    function __construct($url, $base, Conf $conf) {
        $this->url = $url;
        $this->base = $base;
        $this->conf = $conf;
        $this->siteclass = "github";
    }

    const MAINURL = "https://github.com/";
    static function make_url($url, Conf $conf) {
        $url = preg_replace('/\s*\/\s*/', '/', $url);
        if (preg_match('/\A(?:github(?:\.com|)[:\/])?\/*([^\/:@]+\/[^\/:@]+?)(?:\.git|)\z/i', $url, $m)) {
            return new GitHub_RepositorySite("git@github.com:" . $m[1], $m[1], $conf);
        } else if (preg_match('/\A(?:https?:\/\/|git:\/\/|ssh:\/\/(?:git@|)|git@|)github\.com(?::\/*|\/+)([^\/]+?\/[^\/]+?)(?:\.git|)\z/i', $url, $m)) {
            return new GitHub_RepositorySite("git@github.com:" . $m[1], $m[1], $conf);
        } else {
            return null;
        }
    }
    static function sniff_url($url) {
        if (preg_match('/\A(?:https?:\/\/|git:\/\/|ssh:\/\/(?:git@)?|git@|)github.com(?::\/*|\/+)(.*?)(?:\.git|)\z/i', $url, $m)) {
            return 2;
        } else if (preg_match('/\A(?:github(?:\.com)?)(?::\/*|\/+)([^\/:@]+\/[^\/:@]+?)(?:\.git|)\z/i', $url, $m)) {
            return 2;
        } else if (preg_match('/\A\/*([^\/:@]+\/[^\/:@]+?)(?:\.git|)\z/i', $url, $m)) {
            return 1;
        }
        return 0;
    }
    static function home_link($html) {
        return Ht::link($html, self::MAINURL);
    }

    /** Course-wide GitHub API access. Prefers the GitHub App, as
     * `credentialed_git_command` does; the OAuth token is the fallback for
     * installations that have no app.
     * @return GitHubResponse */
    static function graphql(Conf $conf, $post_data, $preencoded = false) {
        if (($app = $conf->github_app())) {
            return $app->graphql($post_data, $preencoded);
        }
        $response = new GitHubResponse("https://api.github.com/graphql");
        $token = $conf->opt("githubOAuthToken");
        if ($token && !$conf->opt("disableRemote")) {
            if (is_string($post_data) && !$preencoded) {
                $post_data = ["query" => $post_data];
            }
            if (!is_string($post_data)) {
                $post_data = json_encode($post_data);
            }
            $response->run_request($conf, "POST", "application/json", $post_data, "Authorization: Bearer {$token}\r\n");
        }
        return $response;
    }

    /** @param string $url
     * @param string $method
     * @param null|string|array|object $data
     * @return GitHubResponse */
    static function restapi(Conf $conf, $url, $method, $data = null) {
        if (($app = $conf->github_app())) {
            return $app->restapi($url, $method, $data);
        }
        return GitHubResponse::make_restapi($conf, $conf->opt("githubOAuthToken"), $url, $method, $data);
    }

    static function echo_username_form(Contact $user, $first) {
        global $Me;
        if (!$first && !$user->github_username)
            return;
        // Where the course creates repositories itself, the username is
        // whatever account the student proved they own by signing in to
        // GitHub; typing one would defeat that. Show it, don't offer it.
        // Staff keep the form, since they are the ones who must fix a
        // student who authorized the wrong account.
        if (GitHub_StudentSetup::configured($user->conf) && !$Me->privChair) {
            if ($user->github_username) {
                ContactView::echo_group(self::home_link("GitHub") . " username",
                                        htmlspecialchars($user->github_username));
            }
            return;
        }
        echo $user->conf->hotform("=index", ["set_username" => 1, "u" => $Me->user_linkpart($user), "reposite" => "github"]),
            '<div class="f-contain">';
        $notes = array();
        if (!$user->github_username)
            $notes[] = array(true, "Please enter your " . self::home_link("GitHub") . " username and click “Save.”");
        ContactView::echo_group(self::home_link("GitHub") . " username",
                                Ht::entry("username", $user->github_username)
                                . "  " . Ht::submit("Save"), $notes);
        echo "</div></form>";
    }

    /** @param string $username
     * @return bool */
    static function save_username(Contact $user, $username) {
        global $Me;
        // enforce the read-only rule above against a hand-built POST
        if (GitHub_StudentSetup::configured($user->conf) && !$Me->privChair) {
            $user->conf->error_msg("<0>Use “Set up my repository” to connect your GitHub account.");
            return false;
        }
        $username = trim((string) $username);

        // empty?
        if ($username === "") {
            if ($Me->privChair) {
                return $user->change_username("github", null);
            } else {
                $user->conf->error_msg("Empty username.");
                return false;
            }
        }

        // weird?
        if (preg_match('_[@,;:~/\[\](){}\\<>&#=\\000-\\027]_', $username)) {
            $user->conf->error_msg("<0>The username “{$username}” contains funny characters. Remove them.");
            return false;
        }

        // in use?
        $x = $user->conf->fetch_value("select contactId from ContactInfo where github_username=?", $username);
        if ($x && $x != $user->contactId) {
            $user->conf->error_msg("<0>That username is already in use.");
            return false;
        }

        // is it valid? XXX GitHub API
        // Ask the organization about the user, not the user about the
        // organization: `user { organization }` resolves to null under a
        // GitHub App installation token, which would silently skip the staff
        // checks below. Both forms work under an OAuth token.
        $org = $user->conf->opt("githubOrganization");
        $staff_team = $user->conf->opt("githubStaffTeam");
        $gq = "{ user(login:" . json_encode($username) . ") { id }";
        if ($org && $staff_team) {
            $gq .= " organization(login:" . json_encode($org) . ") {"
                . " team(slug:" . json_encode($staff_team) . ") {"
                . " members(query:" . json_encode($username) . ") { nodes { login } } } }";
        }
        $gq .= " }";
        $gql = self::graphql($user->conf, $gq);
        if (!$gql->rdata) {
            error_log(json_encode($gql). "!!!!");
            $user->conf->error_msg("<0>Error contacting the GitHub API. Maybe try again?");
            return false;
        } else if (!isset($gql->rdata->user)) {
            $user->conf->error_msg("<0>That user doesn’t exist. Check your spelling and try again.");
            return false;
        }
        if ($org && $user->conf->opt("githubRequireOrganizationMembership")) {
            // 204 member, 404 not a member; REST answers this for an app token
            $ghr = self::restapi($user->conf, "orgs/" . urlencode($org)
                . "/members/" . urlencode($username), "GET");
            if ($ghr->status !== 204) {
                $user->conf->error_msg("<5>That user isn’t a member of the " . Ht::link(htmlspecialchars($org) . " organization", self::MAINURL . urlencode($org)) . ", which manages the class. Follow the link to register with the class, or contact course staff.");
                return false;
            }
        }
        if ($staff_team
            && $user->is_student()
            && ($nodes = $gql->rdata->organization->team->members->nodes ?? null)
            && array_filter($nodes, function ($node) use ($username) {
                    return strcasecmp($username, $node->login) === 0;
                })) {
            $user->conf->error_msg("<0>That user is a member of the course staff.");
            return false;
        }

        return $user->change_username("github", $username);
    }

    function friendly_siteclass() {
        return "GitHub";
    }
    static function global_friendly_siteclass() {
        return "GitHub";
    }
    static function global_friendly_siteurl() {
        return "https://github.com/";
    }

    /** @return string */
    function https_url() {
        return "https://github.com/{$this->base}";
    }
    /** @return string */
    function ssh_url() {
        return "git@github.com:{$this->base}";
    }
    /** @return string */
    function git_url() {
        return "git://github.com/{$this->base}";
    }
    /** @return string */
    function friendly_url() {
        return $this->base ? : $this->url;
    }
    /** @return ?string */
    function organization() {
        $slash = strpos($this->base, "/");
        return $slash !== false && $slash !== 0 ? substr($this->base, 0, $slash) : null;
    }
    /** @param string $branch
     * @param string $subdir
     * @return ?string */
    function https_branch_tree_url($branch, $subdir) {
        if (!Repository::validate_branch($branch)
            || strpos($branch, "/") !== false) {
            return null;
        }
        if ($subdir !== "") {
            $subdir = str_replace("%2F", "/", rawurlencode($subdir)) . "/";
        }
        return "https://github.com/{$this->base}/tree/" . rawurlencode($branch) . "/{$subdir}";
    }
    /** Site-wide OAuth token, used when no GitHub App is configured.
     * @return ?array{string,string} */
    private function oauth_credentials() {
        if (($id = $this->conf->opt("githubOAuthClientId"))
            && ($token = $this->conf->opt("githubOAuthToken"))
            && $token !== Conf::INVALID_TOKEN) {
            return [$id, $token];
        }
        return null;
    }

    /** @return bool */
    function has_credentials() {
        return $this->conf->github_app() !== null
            || $this->oauth_credentials() !== null;
    }

    /** @return list<string> */
    function credentialed_git_command() {
        // installation tokens authenticate git as the literal user
        // `x-access-token`; they expire hourly, so fetch one per command
        if (($app = $this->conf->github_app())
            && ($token = $app->installation_token())) {
            $username = "x-access-token";
        } else if (($cred = $this->oauth_credentials())) {
            list($username, $token) = $cred;
        } else {
            return ["false"];
        }
        return [
            "git", "-c", "credential.helper=",
            "-c", "credential.helper=!f () { echo username={$username}; echo password={$token}; }; f"
        ];
    }
    function owner_name() {
        if (preg_match('{\A([^/"\\\\]+)/([^/"\\\\]+)\z}', $this->base, $m)) {
            return [$m[1], $m[2]];
        }
        return false;
    }

    function message_defs(Contact $user) {
        $base = $user->is_anonymous ? "[anonymous]" : $this->base;
        return [
            new FmtArg("repourl", "https://github.com/{$base}", 0),
            new FmtArg("repobase", $base, 0),
            new FmtArg("repotype", "github", 0)
        ];
    }
    /** @param string $name
     * @return ?string */
    function expand_message($name, Contact $user) {
        return $this->conf->_($name, ...$this->message_defs($user));
    }

    function gitfetch($repo, $cacheid, $foreground) {
        // don't mint a token here; `repofetch.php` does that if it runs
        if (!$this->has_credentials()) {
            return false;
        }
        $arg = $foreground ? [] : ["--bg"];
        $php = $this->conf->opt("phpCommand") ?? "php";
        $command = [$php, "batch/repofetch.php", "-r", $repo->repoid, "-d", $cacheid, ...$arg];
        $sp = Subprocess::run($command, SiteLoader::$root);
        if (!$sp->ok) {
            error_log("`php batch/repofetch.php -r {$repo->repoid}` failed: {$sp->status}, {$sp->stderr}");
        }
        return true;
    }

    /** @return -1|0|1 */
    function validate_open() {
        if (!($owner_name = $this->owner_name())) {
            return -1;
        }
        $gql = self::graphql($this->conf,
            "{ repository(owner:" . json_encode($owner_name[0])
            . ", name:" . json_encode($owner_name[1]) . ") { isPrivate } }");
        if (!$gql->rdata) {
            error_log(json_encode($gql) . "VALO");
            return -1;
        } else if ($gql->rdata->repository == null) {
            return 1;
        } else if (!$gql->rdata->repository->isPrivate) {
            return 1;
        }
        return 0;
    }

    /** @return -1|0|1 */
    function validate_working(Contact $user, ?MessageSet $ms = null) {
        if ($this->conf->opt("disableRemote")) {
            RepositorySite::disabled_remote_error($this->conf);
            return -1;
        }
        $lscommand = $this->credentialed_git_command();
        array_push($lscommand, "ls-remote", $this->https_url());
        $subp = Repository::gitrun_at($this->conf, $lscommand, "/tmp");
        if ($subp->status >= 124) { // timeout
            return -1;
        }
        if (!preg_match('/\A[0-9a-f]{40,}\s+/', $subp->stdout)) {
            // A repository the course just created has no commits, so
            // `ls-remote` prints nothing; it's still ok
            if ($subp->status === 0 && trim($subp->stdout) === "") {
                return 1;
            }
            if ($ms) {
                $ms->error_at("repo", $this->expand_message("repo_unreadable", $user));
                $ms->error_at("working");
            }
            return 0;
        }
        if ($ms && !preg_match('/^[0-9a-f]{40,}\s+refs\/heads\/(?:' . $this->conf->default_main_branch . '|master|main)/m', $subp->stdout)) {
            $ms->warning_at("repo", $this->expand_message("repo_nomain", $user));
        }
        return 1;
    }

    function validate_ownership_always() {
        return false;
    }

    /** @return -1|0|1 */
    function validate_ownership(Repository $repo, Contact $user, ?Contact $partner = null,
                                ?MessageSet $ms = null) {
        if (!$user->github_username
            || !($owner_name = $this->owner_name())) {
            return -1;
        }
        $gq = "{ repository(owner:" . json_encode($owner_name[0])
            . ", name:" . json_encode($owner_name[1]) . ") { "
            . " collaborators(query:" . json_encode($user->github_username)
            . ") { nodes { login } } } }";
        $gql = self::graphql($this->conf, $gq);
        if (!$gql->rdata) {
            error_log(json_encode($gql) . "!?");
            return -1;
        } else if ($gql->rdata->repository === null) { // no such repository
            return -1;
        } else if ($gql->rdata->repository->collaborators === null) {
            // no permission to view collaborators
            return 0;
        } else if (array_filter($gql->rdata->repository->collaborators->nodes,
                        function ($node) use ($user) {
                            return strcasecmp($node->login, $user->github_username) === 0;
                        })) {
            return 1;
        }
        return 0;
    }
}
