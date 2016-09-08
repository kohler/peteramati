<?php
// repository.php -- Peteramati helper class representing repositories
// Peteramati is Copyright (c) 2013-2016 Eddie Kohler
// See LICENSE for open-source distribution terms

class Repository {
    public $conf;

    public $repoid;
    public $url;
    public $reposite;
    public $cacheid;
    public $open;
    public $opencheckat;
    public $snaphash;
    public $snapat;
    public $snapcheckat;
    public $lastpset;
    public $working;
    public $snapcommitat;
    public $snapcommitline;
    public $notes;
    public $heads;

    public $is_handout = false;
    public $viewable_by = [];
    public $_truncated_psetdir = [];

    function __construct(Conf $conf = null) {
        global $Conf;
        $conf = $conf ? : $Conf;
        $this->conf = $conf;
        if (isset($this->repoid))
            $this->db_load();
    }

    private function db_load() {
        $this->repoid = (int) $this->repoid;
        $this->open = (int) $this->open;
        $this->opencheckat = (int) $this->opencheckat;
        if ($this->snapat !== null)
            $this->snapat = (int) $this->snapat;
        $this->snapcheckat = (int) $this->snapcheckat;
        $this->lastpset = (int) $this->lastpset;
        $this->working = (int) $this->working;
        $this->snapcommitat = (int) $this->snapcommitat;
        $this->reposite = RepositorySite::make($this->url, $this->conf);
    }

    static function fetch($result, Conf $conf) {
        $repo = $result ? $result->fetch_object("Repository", []) : null;
        if ($repo && !is_int($repo->repoid)) {
            $repo->conf = $conf;
            $repo->db_load();
        }
        return $repo;
    }

    static function find_or_create_url($url, Conf $conf) {
        $result = $conf->qe("select * from Repository where url=?", $url);
        $repo = Repository::fetch($result, $conf);
        Dbl::free($result);
        if ($repo)
            return $repo;
        $repo_hash = substr(sha1($url), 10, 1);
        $now = time();
        $result = $conf->qe("insert into Repository set url=?, cacheid=?, working=?, open=?, opencheckat=?", $url, $repo_hash, $now, -1, 0);
        if (!$result)
            return false;
        return self::find_id($conf->dblink->insert_id, $conf);
    }

    static function make_url($url, Conf $conf) {
        $repo = new Repository($conf);
        $repo->url = $url;
        $repo->db_load();
        return $repo;
    }

    static function find_id($repoid, Conf $conf) {
        $result = $conf->qe("select * from Repository where repoid=?", $repoid);
        $repo = Repository::fetch($result, $conf);
        Dbl::free($result);
        return $repo;
    }

    function web_url() {
        return $this->reposite->web_url();
    }
    function friendly_url() {
        return $this->reposite->friendly_url();
    }
    function friendly_url_like(Repository $other) {
        if ($this->reposite->siteclass === $other->reposite->siteclass)
            return $this->reposite->friendly_url();
        return $this->url;
    }

    const VALIDATE_TIMEOUT = 5;
    const VALIDATE_TOTAL_TIMEOUT = 15;
    static private $validate_time_used = 0;

    static private $open_cache = [];
    function validate_open(MessageSet $ms = null) {
        if (isset(self::$open_cache[$this->url]))
            return self::$open_cache[$this->url];
        if (self::$validate_time_used >= self::VALIDATE_TOTAL_TIMEOUT)
            return -1;
        $before = microtime(true);
        self::$open_cache[$this->url] = $r = $this->reposite->validate_open();
        self::$validate_time_used += microtime(true) - $before;
        return $r;
    }
    function check_open(MessageSet $ms = null) {
        global $Now;
        // Recheck repository openness after a day for closed repositories,
        // and after 30 seconds for open or failed-check repositories.
        if ($this->open == 0 && $Now - $this->opencheckat <= 86400)
            return 0;
        if ($this->open != 0 && $Now - $this->opencheckat > 30) {
            $open = $this->validate_open($ms);
            if ($open != $this->open || $Now != $this->opencheckat) {
                if ($this->repoid > 0)
                    $this->conf->qe("update Repository set `open`=?, opencheckat=? where repoid=?",
                        $open, $Now, $this->repoid);
                $this->open = $open;
                $this->opencheckat = $Now;
            }
        }
        if ($this->open < 0 && $ms && $ms->user->isPC && !$ms->has_problems())
            $ms->set_warning_html("open", Messages::$main->expand_html("repo_toopublic_timeout", $this->reposite->message_defs($ms->user)));
        if ($this->open > 0 && $ms && !$ms->has_problems())
            $ms->set_error_html("open", Messages::$main->expand_html("repo_toopublic", $this->reposite->message_defs($ms->user)));
        return $this->open;
    }

    static private $working_cache = [];
    function validate_working(MessageSet $ms = null) {
        if (isset(self::$working_cache[$this->url]))
            return self::$working_cache[$this->url];
        if (self::$validate_time_used >= self::VALIDATE_TOTAL_TIMEOUT)
            return -1;
        $before = microtime(true);
        self::$working_cache[$this->url] = $r = $this->reposite->validate_working($ms);
        self::$validate_time_used += microtime(true) - $before;
        return $r;
    }
    function check_working(MessageSet $ms = null) {
        global $Now;
        $working = $this->working;
        if ($working == 0) {
            $working = $this->validate_working($ms);
            if ($working > 0) {
                $this->working = $Now;
                if ($this->repoid > 0)
                    $this->conf->qe("update Repository set working=? where repoid=?", $Now, $this->repoid);
            }
        }
        if ($working < 0 && $ms && $ms->user->isPC && !$ms->has_problems())
            $ms->set_warning_html("working", Messages::$main->expand_html("repo_working_timeout", $this->reposite->message_defs($ms->user)));
        if ($working == 0 && $ms && !$ms->has_problems())
            $ms->set_error_html("working", Messages::$main->expand_html("repo_unreadable", $this->reposite->message_defs($ms->user)));
        return $working > 0;
    }

    function check_ownership(Contact $user, Contact $partner = null, MessageSet $ms = null) {
        return $this->reposite->validate_ownership($user, $partner, $ms);
    }

    function truncated_psetdir(Pset $pset) {
        return get($this->_truncated_psetdir, $pset->id);
    }
}
