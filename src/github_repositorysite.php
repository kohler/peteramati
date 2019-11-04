<?php
// github_repositorysite.php -- Peteramati GitHub Classroom repositories
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class GitHubResponse implements JsonSerializable {
    public $url;
    public $status = 509;
    public $status_text;
    public $headers = [];
    public $content;
    public $j;
    function __construct($url) {
        $this->url = $url;
    }
    function jsonSerialize() {
        return $this->j ? : ["status" => $this->status, "content" => $this->content];
    }
    function run_post(Conf $conf, $content_type, $content, $header = "") {
        if (is_array($content) || is_object($content)) {
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
        $header .= "User-Agent: kohler/peteramati\r\n"
            . "Content-Type: $content_type\r\n"
            . "Content-Length: " . strlen($content) . "\r\n";
        $htopt = [
            "timeout" => (float) $conf->validate_timeout,
            "ignore_errors" => true, "method" => "POST",
            "header" => $header, "content" => $content
        ];
        $context = stream_context_create(array("http" => $htopt));
        if (($stream = fopen($this->url, "r", false, $context))) {
            if (($metadata = stream_get_meta_data($stream))
                && ($w = get($metadata, "wrapper_data"))
                && is_array($w)) {
                if (preg_match(',\AHTTP/[\d.]+\s+(\d+)\s+(.+)\z,', $w[0], $m)) {
                    $this->status = (int) $m[1];
                    $this->status_text = $m[2];
                }
                for ($i = 1; $i != count($w); ++$i) {
                    if (preg_match(',\A(.*?):\s*(.*)\z,', $w[$i], $m))
                        $this->headers[strtolower($m[1])] = $m[2];
                }
            }
            $this->content = stream_get_contents($stream);
            if ($this->content !== false
                && (empty($this->headers) || str_starts_with($this->headers["content-type"], "application/json"))) {
                $this->j = json_decode($this->content);
            }
            fclose($stream);
        }
    }
}

class GitHub_RepositorySite extends RepositorySite {
    public $conf;
    public $base;
    public $siteclass = "github";
    function __construct($url, $base, Conf $conf) {
        $this->url = $url;
        $this->base = $base;
        $this->conf = $conf;
    }

    const MAINURL = "https://github.com/";
    static function make_url($url, Conf $conf) {
        $url = preg_replace('_\s*/\s*_', '/', $url);
        if (preg_match('_\A(?:github(?:\.com)?[:/])?/*([^/:@]+/[^/:@]+?)(?:\.git|)\z_i', $url, $m))
            return new GitHub_RepositorySite("git@github.com:" . $m[1], $m[1], $conf);
        if (preg_match('_\A(?:https?://|git://|ssh://(?:git@)?|git@|)github.com(?::/*|/+)([^/]+?/[^/]+?)(?:\.git|)\z_i', $url, $m))
            return new GitHub_RepositorySite("git@github.com:" . $m[1], $m[1], $conf);
        return null;
    }
    static function sniff_url($url) {
        if (preg_match('_\A(?:https?://|git://|ssh://(?:git@)?|git@|)github.com(?::/*|/+)(.*?)(?:\.git|)\z_i', $url, $m))
            return 2;
        else if (preg_match('_\A(?:github(?:\.com)?)(?::/*|/+)([^/:@]+/[^/:@]+?)(?:\.git|)\z_i', $url, $m))
            return 2;
        else if (preg_match('_\A/*([^/:@]+/[^/:@]+?)(?:\.git|)\z_i', $url, $m))
            return 1;
        return 0;
    }
    static function home_link($html) {
        return Ht::link($html, self::MAINURL);
    }

    static function graphql(Conf $conf, $post_data, $preencoded = false) {
        $response = new GitHubResponse("https://api.github.com/graphql");
        $token = $conf->opt("githubOAuthToken");
        if ($token && !$conf->opt("disableRemote")) {
            if (is_string($post_data) && !$preencoded) {
                $post_data = json_encode(["query" => $post_data]);
            }
            $response->run_post($conf, "application/json", $post_data, "Authorization: token $token\r\n");
        }
        return $response;
    }

    static function echo_username_form(Contact $user, $first) {
        global $Me;
        if (!$first && !$user->github_username)
            return;
        echo Ht::form(hoturl_post("index", array("set_username" => 1, "u" => $Me->user_linkpart($user), "reposite" => "github"))),
            '<div class="f-contain">';
        $notes = array();
        if (!$user->github_username)
            $notes[] = array(true, "Please enter your " . self::home_link("GitHub") . " username and click “Save.”");
        ContactView::echo_group(self::home_link("GitHub") . " username",
                                Ht::entry("username", $user->github_username)
                                . "  " . Ht::submit("Save"), $notes);
        echo "</div></form>";
    }
    static function save_username(Contact $user, $username) {
        global $Me;
        // does it contain odd characters?
        $username = trim((string) $username);
        if ($username == "") {
            if ($Me->privChair)
                return $user->change_username("github", null);
            return Conf::msg_error("Empty username.");
        }
        if (preg_match('_[@,;:~/\[\](){}\\<>&#=\\000-\\027]_', $username)) {
            return Conf::msg_error("The username “" . htmlspecialchars($username) . "” contains funny characters. Remove them.");
        }

        // is it in use?
        $x = $user->conf->fetch_value("select contactId from ContactInfo where github_username=?", $username);
        if ($x && $x != $user->contactId) {
            return Conf::msg_error("That username is already in use.");
        }

        // is it valid? XXX GitHub API
        $org = $user->conf->opt("githubOrganization");
        $staff_team = $user->conf->opt("githubStaffTeam");
        $gq = "{ user(login:" . json_encode($username) . ") { id";
        if ($org) {
            $gq .= ", organization(login:" . json_encode($org) . ") { id";
            if ($staff_team) {
                $gq .= ", team(slug:" . json_encode($staff_team) . ") {"
                    . " members(query:" . json_encode($username) . ") { nodes { login } } }";
            }
            $gq .= " }";
        }
        $gq .= " } }";
        $gql = self::graphql($user->conf, $gq);
        if ($gql->status !== 200
            || !$gql->j
            || !isset($gql->j->data)) {
            error_log(json_encode($gql));
            return Conf::msg_error("Error contacting the GitHub API. Maybe try again?");
        } else if (!isset($gql->j->data->user)) {
            return Conf::msg_error("That user doesn’t exist. Check your spelling and try again.");
        } else if (!isset($gql->j->data->user->organization)) {
            if ($user->conf->opt("githubRequireOrganizationMembership")) {
                return Conf::msg_error("That user isn’t a member of the " . Ht::link(htmlspecialchars($org) . " organization", self::MAINURL . urlencode($org)) . ", which manages the class. Follow the link to register with the class, or contact course staff.");
            }
        } else if ($staff_team
                   && $user->is_student()
                   && isset($gql->j->data->user->organization->team)
                   && isset($gql->j->data->user->organization->team->members)
                   && array_filter($gql->j->data->user->organization->team->members->nodes,
                                function ($node) use ($username) {
                                    return strcasecmp($username, $node->login) === 0;
                                })) {
            return Conf::msg_error("That user is a member of the course staff.");
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

    function https_url() {
        return "https://github.com/" . $this->base;
    }
    function ssh_url() {
        return "git@github.com:" . $this->base;
    }
    function git_url() {
        return "git://github.com/" . $this->base;
    }
    function friendly_url() {
        return $this->base ? : $this->url;
    }
    function owner_name() {
        if (preg_match('{\A([^/"\\\\]+)/([^/"\\\\]+)\z}', $this->base, $m))
            return [$m[1], $m[2]];
        else
            return false;
    }

    function message_defs(Contact $user) {
        $base = $user->is_anonymous ? "[anonymous]" : $this->base;
        return ["REPOURL" => "https://github.com/$base", "REPOGITURL" => "git@github.com:$base", "REPOBASE" => $base, "GITHUB" => 1];
    }
    function expand_message($name, Contact $user) {
        return Messages::$main->expand_html($name, $this->message_defs($user));
    }

    function validate_open(MessageSet $ms = null) {
        if (!($owner_name = $this->owner_name())) {
            return -1;
        }
        $gql = self::graphql($this->conf,
            "{ repository(owner:" . json_encode($owner_name[0])
            . ", name:" . json_encode($owner_name[1]) . ") { isPrivate } }");
        if ($gql->status !== 200
            || !$gql->j
            || !isset($gql->j->data)) {
            error_log(json_encode($gql));
            return -1;
        } else if ($gql->j->data->repository == null) {
            $ms && $ms->set_error_html("open", $this->expand_message("repo_nonexistent", $ms->user));
            return 1;
        } else if (!$gql->j->data->repository->isPrivate) {
            $ms && $ms->set_error_html("open", $this->expand_message("repo_toopublic", $ms->user));
            return 1;
        } else {
            return 0;
        }
    }
    function validate_working(MessageSet $ms = null) {
        $status = RepositorySite::run_remote_oauth($this->conf,
            $this->conf->opt("githubOAuthClientId"), $this->conf->opt("githubOAuthToken"),
            "ls-remote " . escapeshellarg($this->https_url()) . " 2>&1",
            $output);
        $answer = join("\n", $output);
        if ($status >= 124) { // timeout
            $status = -1;
        } else if (!preg_match('{\A[0-9a-f]{40,}\s+}', $answer)) {
            $ms && $ms->set_error_html("working", $this->expand_message("repo_unreadable", $ms->user));
            $status = 0;
        } else if (!preg_match('{^[0-9a-f]{40,}\s+refs/heads/master}m', $answer)) {
            $ms && $ms->set_error_html("working", $this->expand_message("repo_nomaster", $ms->user));
            $status = 0;
        } else {
            $status = 1;
        }
        return $status;
    }
    function gitfetch($repoid, $cacheid, $foreground) {
        global $ConfSitePATH;
        if (!($id = $this->conf->opt("githubOAuthClientId"))
            || !($token = $this->conf->opt("githubOAuthToken"))
            || !ctype_alnum($token)) {
            return false;
        }
        putenv("GIT_USERNAME=$id");
        putenv("GIT_PASSWORD=$token");
        shell_exec(escapeshellarg("$ConfSitePATH/src/gitfetch")
            . " $repoid $cacheid " . escapeshellarg($this->https_url())
            . " 1>&2 " . ($foreground ? "" : " &"));
        putenv("GIT_USERNAME");
        putenv("GIT_PASSWORD");
    }
    function validate_ownership_always() {
        return false;
    }
    function validate_ownership(Repository $repo, Contact $user, Contact $partner = null,
                                MessageSet $ms = null) {
        if (!$user->github_username
            || !($owner_name = $this->owner_name())) {
            return -1;
        }
        $gq = "{ repository(owner:" . json_encode($owner_name[0])
            . ", name:" . json_encode($owner_name[1]) . ") { "
            . " collaborators(query:" . json_encode($user->github_username)
            . ") { nodes { login } } } }";
        $gql = self::graphql($this->conf, $gq);
        if ($gql->status !== 200
            || !$gql->j
            || !isset($gql->j->data)) {
            error_log(json_encode($gql));
            return -1;
        } else if ($gql->j->data->repository == null) { // no such repository
            return -1;
        } else if (array_filter($gql->j->data->repository->collaborators->nodes,
                        function ($node) use ($user) {
                            return strcasecmp($node->login, $user->github_username) === 0;
                        })) {
            return 1;
        } else {
            return 0;
        }
    }
}
