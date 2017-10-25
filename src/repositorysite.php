<?php
// repository.php -- Peteramati superclass representing repository sites
// Peteramati is Copyright (c) 2013-2016 Eddie Kohler
// See LICENSE for open-source distribution terms

class RepositorySite {
    public $url;
    static public $sitemap = ["github" => "GitHub_RepositorySite", "harvardseas" => "HarvardSEAS_RepositorySite"];
    static private $gitssh_config_checked = 0;

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
    static function make_web_url($url, Conf $conf) {
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

    function web_url() {
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

    static function run_ls_remote(Conf $conf, $url, &$output) {
        global $ConfSitePATH, $Me;
        $output = [];
        if ($conf->opt("disableRemote") || self::$gitssh_config_checked < 0)
            return -1;
        if (self::$gitssh_config_checked == 0) {
            $config = getenv("GITSSH_CONFIG");
            if (!$config || !is_readable($config)) {
                if ($Me && $Me->privChair)
                    $conf->errorMsg("The <code>gitssh_config</code> file isn’t configured, so I can’t access remote repositories.");
                return (self::$gitssh_config_checked = -1);
            }
            if (!is_executable("$ConfSitePATH/jail/pa-timeout")) {
                if ($Me && $Me->privChair)
                    $conf->errorMsg("The <code>jail/pa-timeout</code> program hasn’t been built, so I can’t access remote repositories. Run <code>cd DIR/jail; make</code>.");
                return (self::$gitssh_config_checked = -1);
            }
            self::$gitssh_config_checked = 1;
        }
        $command = "GIT_SSH=" . escapeshellarg("$ConfSitePATH/src/gitssh")
            . " $ConfSitePATH/jail/pa-timeout " . $conf->validate_timeout
            . " git ls-remote " . escapeshellarg($url) . " 2>&1";
        exec($command, $output, $status);
        if ($status >= 124) // timeout or pa-timeout error
            return -1;
        else if (!empty($output) && preg_match(',\A[0-9a-f]{40}\s+,', $output[0]))
            return 1;
        else
            return 0;
    }
}

class Bad_RepositorySite extends RepositorySite {
    public $siteclass = "unknown";
    function __construct($url) {
        $this->url = $url;
    }
}
