<?php
// repository.php -- Peteramati superclass representing repository sites
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class RepositorySite {
    public $url;
    static public $sitemap = ["github" => "GitHub_RepositorySite", "harvardseas" => "HarvardSEAS_RepositorySite"];

    static function is_primary(Repository $repo = null) {
        return $repo === null
            || $repo->reposite->siteclass === $repo->conf->repository_site_classes()[0];
    }
    static function site_classes(Conf $conf) {
        return array_map(function ($abbr) {
            return RepositorySite::$sitemap[$abbr];
        }, $conf->repository_site_classes());
    }
    static function make($url, Conf $conf) {
        foreach (self::site_classes($conf) as $k) {
            if (($s = $k::make_url($url, $conf)))
                return $s;
        }
        return new Bad_RepositorySite($url);
    }
    static function make_https_url($url, Conf $conf) {
        if (preg_match('_\A(?:https?://|git://|ssh://(?:git@)?|git@|)([^/:]+(?::\d+)?)(?::/*|/+)(.*?)(?:\.git|)\z_i', $url, $m))
            return "https://" . $m[1] . "/" . $m[2];
        return $url;
    }
    static function echo_username_forms(Contact $user) {
        foreach (self::site_classes($user->conf) as $i => $k)
            $k::echo_username_form($user, $i == 0);
    }

    function friendly_siteclass() {
        return $this->siteclass;
    }

    function https_url() {
        return $this->url;
    }
    function ssh_url() {
        return $this->url;
    }
    function git_url() {
        return $this->url;
    }
    function friendly_url() {
        return $this->url;
    }

    function message_defs(Contact $user) {
        return ["REPOGITURL" => null, "REPOBASE" => null];
    }

    function validate_open(MessageSet $ms = null) {
        return -1;
    }
    function validate_working(MessageSet $ms = null) {
        return -1;
    }
    function validate_ownership_always() {
        return true;
    }
    function validate_ownership(Repository $repo, Contact $user, Contact $partner = null,
                                MessageSet $ms = null) {
        return -1;
    }

    static private function chair_error($error) {
        global $Me;
        if ($Me && $Me->privChair) {
            $Me->conf->errorMsg($s);
        }
        return -1;
    }
    static function run_remote_oauth(Conf $conf, $clientid, $token,
                                     $gitcommand, &$output) {
        global $ConfSitePATH, $Me;
        if ($conf->opt("disableRemote")) {
            if (is_string($conf->opt("disableRemote"))) {
                self::chair_error(htmlspecialchars($conf->opt("disableRemote")));
            }
            return -1;
        }
        if (!$clientid || !$token) {
            return self::chair_error("Missing OAuth client ID and/or token.");
        } else if (!ctype_alnum($token)) {
            return $this->chair_error("Bad OAuth token.");
        }
        putenv("GIT_USERNAME=$clientid");
        putenv("GIT_PASSWORD=$token");
        $command = "$ConfSitePATH/jail/pa-timeout " . $conf->validate_timeout
            . " git -c credential.helper= -c " . escapeshellarg("credential.helper=!f() { echo username=\$GIT_USERNAME; echo password=\$GIT_PASSWORD; }; f")
            . " " . $gitcommand;
        exec($command, $output, $status);
        putenv("GIT_USERNAME");
        putenv("GIT_PASSWORD");
        return $status;
    }
}

class Bad_RepositorySite extends RepositorySite {
    public $siteclass = "unknown";
    function __construct($url) {
        $this->url = $url;
    }
}
