<?php
// githubapp.php -- Peteramati GitHub App authentication
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler
// See LICENSE for open-source distribution terms

// A GitHub App installed on the course organization. Unlike the site-wide
// OAuth token in `opt.githubOAuthToken`, this credential belongs to the course
// rather than to whoever authorized it, and its tokens expire on their own.
class GitHubApp {
    /** @var Conf */
    public $conf;
    /** @var string */
    private $appid;
    /** @var string */
    private $installid;
    /** @var ?string */
    private $keysrc;
    /** @var ?string */
    private $clientid;
    /** @var ?string */
    private $clientsecret;
    /** @var bool */
    private $keyfile;
    /** @var ?string */
    private $token;
    /** @var int */
    private $token_expires;

    /** Installation tokens last an hour; renew this long before expiry. */
    const RENEW_BEFORE = 300;
    /** GitHub rejects app JWTs that last more than 10 minutes. */
    const JWT_LIFETIME = 540;
    const TOKEN_SETTING = "__github_installation_token";

    private function __construct(Conf $conf, $appid, $installid, $keysrc, $keyfile) {
        $this->conf = $conf;
        $this->appid = $appid;
        $this->installid = $installid;
        $this->keysrc = $keysrc;
        $this->keyfile = $keyfile;
        $this->clientid = $conf->opt("githubAppClientId");
        $this->clientsecret = $conf->opt("githubAppClientSecret");
    }

    /** True if the app can also authorize individual users. A GitHub App runs
     * the same OAuth web flow as an OAuth app, from the same endpoints, but
     * its user tokens carry no scopes: what they may do follows from the
     * app's own permissions.
     * @return bool */
    function has_user_auth() {
        return !!$this->clientid && !!$this->clientsecret;
    }

    /** @return ?string */
    function client_id() {
        return $this->clientid;
    }

    /** @return ?string */
    function client_secret() {
        return $this->clientsecret;
    }

    /** @return ?GitHubApp */
    static function make(Conf $conf) {
        $appid = $conf->opt("githubAppId");
        $installid = $conf->opt("githubAppInstallationId");
        if (!$appid || !$installid) {
            return null;
        }
        if (($key = $conf->opt("githubAppKey"))) {
            return new GitHubApp($conf, $appid, $installid, $key, false);
        } else if (($keyfile = $conf->opt("githubAppKeyFile"))) {
            return new GitHubApp($conf, $appid, $installid, $keyfile, true);
        }
        return null;
    }

    /** @return ?string */
    private function key() {
        if (!$this->keyfile) {
            return $this->keysrc;
        }
        $fn = $this->keysrc;
        if ($fn[0] !== "/") {
            $fn = SiteLoader::$root . "/{$fn}";
        }
        if (($key = @file_get_contents($fn)) === false) {
            error_log("{$fn}: cannot read githubAppKeyFile");
            return null;
        }
        return $key;
    }

    /** @return ?string */
    function jwt() {
        if (($key = $this->key()) === null) {
            return null;
        }
        $jwt = JWTParser::make_rsa((object) [
            // back-date `iat`: GitHub rejects JWTs issued in its future
            "iat" => Conf::$now - 60,
            "exp" => Conf::$now + self::JWT_LIFETIME,
            "iss" => $this->appid
        ], $key);
        if ($jwt === null) {
            error_log("cannot sign GitHub App JWT (bad githubAppKey?)");
        }
        return $jwt;
    }

    /** @param string $token
     * @param int $expires
     * @return string */
    private function set_token($token, $expires) {
        $this->token = $token;
        $this->token_expires = $expires;
        return $token;
    }

    /** @return ?string */
    function installation_token() {
        // instance cache, then the copy shared with other processes
        if ($this->token !== null
            && $this->token_expires > Conf::$now + self::RENEW_BEFORE) {
            return $this->token;
        }
        $expires = $this->conf->setting(self::TOKEN_SETTING) ?? 0;
        if ($expires > Conf::$now + self::RENEW_BEFORE
            && ($token = $this->conf->setting_data(self::TOKEN_SETTING))) {
            return $this->set_token($token, $expires);
        }
        if (!($jwt = $this->jwt())) {
            return null;
        }
        $ghr = GitHubResponse::make_restapi($this->conf, $jwt,
            "app/installations/" . urlencode($this->installid) . "/access_tokens", "POST");
        if ($ghr->status !== 201
            || !isset($ghr->response->token)
            || !isset($ghr->response->expires_at)) {
            error_log("GitHub App installation token request failed: " . json_encode($ghr));
            return null;
        }
        $expires = (int) strtotime($ghr->response->expires_at);
        $this->conf->save_setting(self::TOKEN_SETTING, $expires, $ghr->response->token);
        return $this->set_token($ghr->response->token, $expires);
    }

    /** @param string $url
     * @param string $method
     * @param null|string|array|object $data
     * @return GitHubResponse */
    function restapi($url, $method, $data = null) {
        return GitHubResponse::make_restapi($this->conf, $this->installation_token(),
            $url, $method, $data);
    }

    /** @param string|array|object $post_data
     * @param bool $preencoded
     * @return GitHubResponse */
    function graphql($post_data, $preencoded = false) {
        $response = new GitHubResponse("https://api.github.com/graphql");
        if (!$this->conf->opt("disableRemote")
            && ($token = $this->installation_token())) {
            if (is_string($post_data) && !$preencoded) {
                $post_data = ["query" => $post_data];
            }
            $response->run_request($this->conf, "POST", "application/json", $post_data,
                "Authorization: Bearer {$token}\r\n");
        }
        return $response;
    }
}
