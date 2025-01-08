<?php
// repositorysite.php -- Peteramati superclass representing repository sites
// Peteramati is Copyright (c) 2013-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class RepositorySite {
    /** @var string */
    public $url;
    /** @var string */
    public $siteclass;
    static public $sitemap = ["github" => "GitHub_RepositorySite"];

    /** @return bool */
    static function is_primary(?Repository $repo = null) {
        return $repo === null
            || $repo->reposite->siteclass === $repo->conf->repository_site_classes()[0];
    }
    /** @return list<string> */
    static function site_classes(Conf $conf) {
        return array_map(function ($abbr) {
            return RepositorySite::$sitemap[$abbr];
        }, $conf->repository_site_classes());
    }
    /** @param string $url
     * @return RepositorySite */
    static function make($url, Conf $conf) {
        foreach (self::site_classes($conf) as $k) {
            if (($s = $k::make_url($url, $conf)))
                return $s;
        }
        return new Bad_RepositorySite($url);
    }
    /** @param string $url
     * @return string */
    static function make_https_url($url, Conf $conf) {
        if (preg_match('/\A(?:https?:\/\/|git:\/\/|ssh:\/\/(?:git@)?|git@|)([^\/:]+(?::\d+)?)(?::\/*|\/+)(.*?)(?:\.git|)\z/i', $url, $m)) {
            return "https://" . $m[1] . "/" . $m[2];
        }
        return $url;
    }
    static function echo_username_forms(Contact $user) {
        foreach (self::site_classes($user->conf) as $i => $k) {
            $k::echo_username_form($user, $i == 0);
        }
    }

    function friendly_siteclass() {
        return $this->siteclass;
    }

    /** @return string */
    function https_url() {
        return $this->url;
    }
    /** @return string */
    function ssh_url() {
        return $this->url;
    }
    /** @return string */
    function git_url() {
        return $this->url;
    }
    /** @return string */
    function friendly_url() {
        return $this->url;
    }
    /** @param string $branch
     * @param string $subdir
     * @return ?string */
    function https_branch_tree_url($branch, $subdir) {
        return null;
    }
    /** @return list<string> */
    function credentialed_git_command() {
        return ["git"];
    }

    /** @return list<FmtArg> */
    function message_defs(Contact $user) {
        return [new FmtArg("repourl", $this->url, 0), new FmtArg("repobase", $this->friendly_url(), 0)];
    }

    /** @param Repository $repo
     * @param string $cacheid
     * @param bool $foreground
     * @return bool */
    function gitfetch($repo, $cacheid, $foreground) {
        return false;
    }
    /** @return -1|0|1 */
    function validate_open() {
        return -1;
    }
    /** @return -1|0|1 */
    function validate_working(Contact $user, ?MessageSet $ms = null) {
        return -1;
    }
    /** @return bool */
    function validate_ownership_always() {
        return true;
    }
    /** @return -1|0|1 */
    function validate_ownership(Repository $repo, Contact $user, ?Contact $partner = null,
                                ?MessageSet $ms = null) {
        return -1;
    }

    /** @return -1 */
    static private function chair_error($error) {
        global $Me;
        if ($Me && $Me->privChair) {
            $Me->conf->error_msg($error);
        }
        return -1;
    }
    /** @return 0|-1 */
    static function disabled_remote_error(Conf $conf) {
        $dr = $conf->opt("disableRemote") ?? 0;
        if (!$dr) {
            return 0;
        }
        if (is_string($dr)) {
            self::chair_error("<0>{$dr}");
        }
        return -1;
    }
    /** @param string $clientid
     * @param string $token
     * @param string $gitcommand
     * @param list<string> &$output
     * @return int */
    static function run_remote_oauth(Conf $conf, $clientid, $token,
                                     $gitcommand, &$output) {
        $output = [];
        if (self::disabled_remote_error($conf) < 0) {
            return -1;
        } else if (!$clientid || !$token || $token === Conf::INVALID_TOKEN) {
            return self::chair_error("Missing OAuth client ID and/or token.");
        }
        putenv("GIT_USERNAME={$clientid}");
        putenv("GIT_PASSWORD={$token}");
        $command = SiteLoader::$root . "/jail/pa-timeout " . $conf->validate_timeout
            . " git -c credential.helper= -c " . escapeshellarg("credential.helper=!f() { echo username=\$GIT_USERNAME; echo password=\$GIT_PASSWORD; }; f")
            . " " . $gitcommand;
        exec($command, $output, $status);
        putenv("GIT_USERNAME");
        putenv("GIT_PASSWORD");
        return $status;
    }
}

class Bad_RepositorySite extends RepositorySite {
    function __construct($url) {
        $this->url = $url;
        $this->siteclass = "__unknown__";
    }
}
