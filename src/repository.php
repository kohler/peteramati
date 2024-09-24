<?php
// repository.php -- Peteramati helper class representing repositories
// Peteramati is Copyright (c) 2013-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class Repository {
    /** @var Conf */
    public $conf;

    /** @var int */
    public $repoid;
    /** @var string */
    public $url;
    /** @var RepositorySite */
    public $reposite;
    /** @var string */
    public $cacheid;
    /** @var int */
    public $rflags;
    /** @var string */
    public $repogid;
    /** @var -1|0|1 */
    public $open;
    /** @var int */
    public $opencheckat;
    /** @var ?non-empty-string */
    public $snaphash;
    /** @var ?int */
    public $snapat;
    /** @var int */
    public $snapcheckat;
    /** @var int */
    public $working;
    /** @var int */
    public $infosnapat;
    public $notes;
    /** @var ?string */
    public $heads;

    /** @var bool */
    public $is_handout = false;
    /** @var array<int,bool> */
    public $viewable_by = [];
    /** @var array<string,string> */
    public $_truncated_hashes = [];
    /** @var array<int,bool> */
    public $_truncated_psetdir = [];
    /** @var array<string,CommitRecord> */
    private $_commits = [];
    /** @var array<string,CommitList> */
    private $_commit_lists = [];
    /** @var ?list<string> */
    private $_branches;
    /** @var int */
    private $_heads_loaded = 0;
    /** @var int */
    private $_refresh_count = 0;
    /** @var ?string */
    private $_original_cacheid;

    /** @var list<array{string,string,list<string>,int}> */
    static private $_file_contents = [];
    /** @var bool */
    static public $verbose = false;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        if (isset($this->repoid)) {
            $this->db_load();
        }
    }

    private function db_load() {
        $this->repoid = (int) $this->repoid;
        $this->rflags = (int) $this->rflags;
        $this->open = (int) $this->open;
        $this->opencheckat = (int) $this->opencheckat;
        if ($this->snapat !== null) {
            $this->snapat = (int) $this->snapat;
        }
        $this->snapcheckat = (int) $this->snapcheckat;
        $this->working = (int) $this->working;
        $this->infosnapat = (int) $this->infosnapat;
        if ($this->notes !== null) {
            $this->notes = json_decode($this->notes, true);
        }
        $this->reposite = RepositorySite::make($this->url, $this->conf);
    }

    /** @return ?Repository */
    static function fetch($result, Conf $conf) {
        $repo = $result ? $result->fetch_object("Repository", [$conf]) : null;
        '@phan-var-force ?Repository $repo';
        if ($repo && !is_int($repo->repoid)) {
            $repo->conf = $conf;
            $repo->db_load();
        }
        return $repo;
    }

    /** @param string $url
     * @return ?Repository */
    static function find_or_create_url($url, Conf $conf) {
        $url = normalize_uri($url);
        $result = $conf->qe("select * from Repository where url=?", $url);
        $repo = Repository::fetch($result, $conf);
        Dbl::free($result);
        if ($repo) {
            return $repo;
        }

        // otherwise, pick a random repogid (try 24 times)
        for ($i = 0; $i < 24; ++$i) {
            if (!($repogid = self::random_repogid())
                || self::repogid_repodir_known($conf, $repogid)) {
                continue;
            }

            $result = $conf->qe("insert into Repository
                    set url=?, cacheid=?, repogid=?,
                    working=?, open=?, opencheckat=?",
                $url, substr($repogid, 0, 1), $repogid,
                time(), -1, 0);
            if ($result->affected_rows > 0) {
                return self::by_id($result->insert_id, $conf);
            }
        }

        return null;
    }


    /** @return ?string */
    static function random_repogid() {
        for ($i = 0; $i < 50; ++$i) {
            // compute random repoid: [0-9a-f][0-9a-z]{7}, but not all hex
            $s = random_alnum_chars(8, 36);
            // normalize first character to [0-9a-f]
            $c = ord($s[0]);
            if ($c >= 119 /* w */) {
                continue;
            }
            if ($c >= 103 /* g */) {
                $c = $c >= 113 /* q */ ? 97 + $c - 113 : 48 + $c - 103;
            }
            // check for hex
            $repogid = chr($c) . substr($s, 1);
            if (!ctype_xdigit($repogid)) {
                return $repogid;
            }
        }
        return null;
    }

    /** @param string $repogid
     * @return bool */
    static function repogid_repodir_known(Conf $conf, $repogid) {
        $repodir = self::repodir_at($conf, substr($repogid, 0, 1));
        return file_exists("{$repodir}/config")
            && (self::gitrun_subprocess($conf, ["git", "remote", "get-url", "repo{$repogid}"], $repodir))->ok;
    }


    /** param string $url
     * @return Repository */
    static function make_url($url, Conf $conf) {
        $repo = new Repository($conf);
        $repo->url = $url;
        $repo->db_load();
        return $repo;
    }

    /** @param int $repoid
     * @return ?Repository */
    static function by_id($repoid, Conf $conf) {
        $result = $conf->qe("select * from Repository where repoid=?", $repoid);
        $repo = Repository::fetch($result, $conf);
        Dbl::free($result);
        return $repo;
    }

    /** @return string */
    function https_url() {
        return $this->reposite->https_url();
    }

    /** @return string */
    function ssh_url() {
        return $this->reposite->ssh_url();
    }

    /** @return string */
    function friendly_url() {
        return $this->reposite->friendly_url();
    }

    /** @return string */
    function friendly_url_like(Repository $other) {
        if ($this->reposite->siteclass === $other->reposite->siteclass) {
            return $this->reposite->friendly_url();
        } else {
            return $this->url;
        }
    }

    /** @param string $branch
     * @param string $subdir
     * @return ?string */
    function https_branch_tree_url($branch, $subdir) {
        return $this->reposite->https_branch_tree_url($branch, $subdir);
    }

    /** @return list<string> */
    function credentialed_git_command() {
        return $this->reposite->credentialed_git_command();
    }

    /** @param string $name
     * @return ?string */
    function expand_message($name, Contact $user) {
        return Messages::$main->expand_html($name, $this->reposite->message_defs($user));
    }

    /** @param ?string $cacheid */
    function override_cacheid($cacheid) {
        $this->_original_cacheid = $this->_original_cacheid ?? $this->cacheid;
        $this->cacheid = $cacheid ?? $this->_original_cacheid;
    }

    const VALIDATE_TIMEOUT = 5;
    const VALIDATE_TOTAL_TIMEOUT = 15;
    static private $validate_time_used = 0;

    /** @param int|float $delta
     * @param bool $foreground */
    function refresh($delta, $foreground = false) {
        if ((!$this->snapcheckat || $this->snapcheckat + $delta <= Conf::$now)
            && !$this->conf->opt("disableRemote")) {
            $this->conf->qe("update Repository set snapcheckat=? where repoid=?", Conf::$now, $this->repoid);
            $this->snapcheckat = Conf::$now;
            if ($foreground) {
                set_time_limit(30);
            }
            $this->ensure_repodir();
            $this->reposite->gitfetch($this, $this->cacheid, $foreground);
            if ($foreground) {
                $this->_commits = $this->_commit_lists = [];
                $this->_branches = null;
                $this->_heads_loaded = 0;
                ++$this->_refresh_count;
            }
        } else {
            RepositorySite::disabled_remote_error($this->conf);
        }
    }

    /** @var array<string,-1|0|1> */
    static private $open_cache = [];

    /** @return -1|0|1 */
    function validate_open() {
        if (isset(self::$open_cache[$this->url])) {
            return self::$open_cache[$this->url];
        }
        if (self::$validate_time_used >= self::VALIDATE_TOTAL_TIMEOUT) {
            return -1;
        }
        $before = microtime(true);
        self::$open_cache[$this->url] = $r = $this->reposite->validate_open();
        self::$validate_time_used += microtime(true) - $before;
        return $r;
    }

    /** @return -1|0|1 */
    function check_open() {
        // Recheck repository openness after a day for closed repositories,
        // and after 30 seconds for open or failed-check repositories.
        if (Conf::$now - $this->opencheckat > ($this->open == 0 ? 86400 : 30)) {
            $open = $this->validate_open();
            if ($open !== $this->open || Conf::$now !== $this->opencheckat) {
                if ($this->repoid > 0)
                    $this->conf->qe("update Repository set `open`=?, opencheckat=? where repoid=?",
                        $open, Conf::$now, $this->repoid);
                $this->open = $open;
                $this->opencheckat = Conf::$now;
            }
        }
        return $this->open;
    }

    /** @var array<string,-1|0|1> */
    static private $working_cache = [];

    /** @return -1|0|1 */
    function validate_working(Contact $user, ?MessageSet $ms = null) {
        if (isset(self::$working_cache[$this->url])) {
            return self::$working_cache[$this->url];
        } else if (self::$validate_time_used >= self::VALIDATE_TOTAL_TIMEOUT) {
            return -1;
        }
        $before = microtime(true);
        self::$working_cache[$this->url] = $r = $this->reposite->validate_working($user, $ms);
        self::$validate_time_used += microtime(true) - $before;
        return $r;
    }

    /** @return bool */
    function check_working(Contact $user, ?MessageSet $ms = null) {
        $working = $this->working;
        if ($working === 0) {
            $working = $this->validate_working($user, $ms);
            if ($working > 0) {
                $this->working = Conf::$now;
                if ($this->repoid > 0) {
                    $this->conf->qe("update Repository set working=? where repoid=?", Conf::$now, $this->repoid);
                }
            }
        }
        if ($working < 0 && $ms && $user->isPC && !$ms->has_problem_at("working")) {
            $ms->warning_at("repo", $this->expand_message("repo_working_timeout", $user));
            $ms->warning_at("working");
        }
        if ($working === 0 && $ms && !$ms->has_problem_at("working")) {
            $ms->warning_at("repo", $this->expand_message("repo_unreadable", $user));
            $ms->warning_at("working");
        }
        return $working > 0;
    }

    /** @return -1|0|1 */
    function validate_ownership(Contact $user, ?Contact $partner = null, ?MessageSet $ms = null) {
        return $this->reposite->validate_ownership($this, $user, $partner, $ms);
    }

    /** @return -1|0|1 */
    function check_ownership(Contact $user, ?Contact $partner = null, ?MessageSet $ms = null) {
        $when = 0;
        $ownership = -1;
        $always = $this->reposite->validate_ownership_always();
        if ($this->notes && isset($this->notes["owner." . $user->contactId])) {
            list($when, $ownership) = $this->notes["owner." . $user->contactId];
        }
        if (Conf::$now - $when > ($ownership > 0 ? 86400 : 30) || $always) {
            $ownership = $this->validate_ownership($user, $partner, $ms);
            if (!$always) {
                Dbl::compare_exchange($user->conf->dblink,
                    "select notes from Repository where repoid=?", [$this->repoid],
                    function ($value) use ($user, $ownership) {
                        $value = json_decode($value ?? "[]", true) ? : [];
                        $value["owner." . $user->contactId] = [time(), $ownership];
                        $this->notes = $value;
                        return json_encode_db($value);
                    },
                    "update Repository set notes=?{desired} where notes?{expected}e and repoid=?", [$this->repoid]);
            }
        }
        if ($ownership === 0 && $ms && !$ms->has_problem_at("ownership")) {
            $ms->warning_at("repo", $this->expand_message("repo_notowner", $user));
            $ms->warning_at("ownership");
        }
        return $ownership;
    }

    /** @return bool */
    function truncated_psetdir(Pset $pset) {
        return $this->_truncated_psetdir[$pset->id] ?? false;
    }


    /** @param string $cacheid
     * @return string */
    static function repodir_at(Conf $conf, $cacheid) {
        return SiteLoader::$root . "/repo/repo" . $cacheid;
    }

    /** @param string $cacheid
     * @return string */
    static function ensure_repodir_at(Conf $conf, $cacheid) {
        $repodir = self::repodir_at($conf, $cacheid);
        if (!file_exists("{$repodir}/config")) {
            if (!mk_site_subdir($repodir, 02770)) {
                return "";
            }
            shell_exec("cd {$repodir} && git init --bare --shared -b main");
        }
        return $repodir;
    }

    /** @return string */
    function repodir() {
        return self::repodir_at($this->conf, $this->cacheid);
    }

    /** @return string */
    function ensure_repodir() {
        return self::ensure_repodir_at($this->conf, $this->cacheid);
    }


    function upgrade() {
        if (($this->rflags & 1) === 0
            && UpgradeRepository::upgrade_refs($this, $this->cacheid)) {
            $this->conf->qe("update Repository set rflags=rflags|1 where repoid=?", $this->repoid);
            $this->rflags |= 1;
        }
        if (($this->rflags & 2) === 0
            && UpgradeRepository::upgrade_runs($this)) {
            $this->conf->qe("update Repository set rflags=rflags|2 where repoid=?", $this->repoid);
            $this->rflags |= 2;
        }
    }


    /** @return string */
    function reponame() {
        if (($this->rflags & 1) !== 0) {
            return "repo{$this->repogid}";
        } else {
            return "repo{$this->repoid}";
        }
    }

    /** @param string $branch
     * @return string */
    function repobranchname($branch) {
        if (($this->rflags & 1) !== 0) {
            return "repo{$this->repogid}/{$branch}";
        } else {
            return "repo{$this->repoid}/{$branch}";
        }
    }


    static function verbose_log($text) {
        if (PHP_SAPI === "cli") {
            fwrite(STDERR, $text . "\n");
        } else {
            error_log($text);
        }
    }


    /** @param list<string> $command
     * @param string $cwd
     * @param array{firstline?:int,linecount?:int,stdin?:string} $args
     * @return Subprocess */
    static function gitrun_subprocess(Conf $conf, $command, $cwd, $args = []) {
        if ($command[0] === "git") {
            $command[0] = $conf->opt("gitCommand") ?? "git";
            array_splice($command, 1, 0, ["-c", "safe.directory=*"]); // XXX
        }
        if (self::$verbose) {
            self::verbose_log(json_encode($command) . " @{$cwd}");
        }
        return Subprocess::run($command, $cwd, $args);
    }

    /** @param list<string> $command
     * @param array{firstline?:int,linecount?:int,cwd?:?string} $args
     * @return Subprocess */
    function gitruninfo($command, $args = []) {
        $cwd = $args["cwd"] ?? $this->ensure_repodir();
        if (!$cwd) {
            throw new Error("Cannot safely create repository directory");
        }
        return self::gitrun_subprocess($this->conf, $command, $cwd, $args);
    }

    /** @param list<string> $command
     * @param array{firstline?:int,linecount?:int,cwd?:?string} $args
     * @return string */
    function gitrun($command, $args = []) {
        return $this->gitruninfo($command, $args)->stdout;
    }

    /** @param list<string> $command
     * @param array{firstline?:int,linecount?:int,cwd?:?string} $args
     * @return bool */
    function gitrunok($command, $args = []) {
        return $this->gitruninfo($command, $args)->ok;
    }

    /** @return list<string> */
    function branches() {
        if ($this->_branches === null) {
            preg_match_all('/ refs\/remotes\/' . preg_quote($this->reponame()) . '\/(.*)$/m',
                           $this->conf->repodir_refs($this->cacheid), $m);
            $this->_branches = $m[1] ?? [];
            if (($i = array_search("HEAD", $this->_branches)) !== false) {
                array_splice($this->_branches, $i, 1);
            }
        }
        return $this->_branches;
    }

    /** @param string $arg
     * @return ?string */
    private function rev_parse($arg) {
        $grr = $this->gitruninfo(["git", "rev-parse", "--verify", $arg]);
        if ($grr->ok && $grr->stdout) {
            return trim($grr->stdout);
        } else {
            return null;
        }
    }

    static private function set_directory(CommitRecord $cr, $s, $x, $end) {
        $x0 = $x;
        $mode = 0;
        $dir = null;
        while ($x !== $end) {
            $ch = $s[$x];
            if ($mode === 1 && ($ch === "\n" || $ch === "/") && $x > $x0) {
                $d = substr($s, $x0, $x - $x0);
                if ($dir === null) {
                    $dir = $d;
                } else if (is_string($dir)) {
                    if ($dir !== $d) {
                        $dir = [$dir, $d];
                    }
                } else if (!in_array($d, $dir)) {
                    $dir[] = $d;
                }
            }
            if ($ch === "/") {
                $mode = 2;
            } else if ($ch === "\n") {
                $mode = 0;
            } else if ($mode === 0) {
                $x0 = $x;
                $mode = 1;
            }
            ++$x;
        }
        $cr->directory = $dir;
    }

    /** @param string $branch */
    private function check_merges($branch) {
        $list = $this->commit_list(null, $branch);
        if ($list->merges_checked) {
            return;
        }
        $list->merges_checked = true;

        $s = $this->gitrun(["git", "log", "--cc", "--name-only", "--format=%x00%H", $this->repobranchname($branch)]);
        $p = 0;
        $l = strlen($s);
        $cr = $newcr = null;
        while ($p < $l && $s[$p] === "\0") {
            $p0 = $p + 1;
            if (($p = strpos($s, "\0", $p0)) === false) {
                $p = $l;
            }
            if (($nl1 = strpos($s, "\n", $p0)) !== false
                && $nl1 < $p) {
                $hash = substr($s, $p0, $nl1 - $p0);
                if (($cr = $this->_commits[$hash] ?? null)) {
                    while ($nl1 < $p && $s[$nl1] === "\n") {
                        ++$nl1;
                    }
                    if ($nl1 === $p) {
                        $cr->_flags |= CommitRecord::CRF_IS_TRIVIAL_MERGE;
                    }
                }
            }
        }
    }

    /** @param string $branch
     * @return list<string> */
    private function heads_on($branch) {
        $heads = explode(" ", $this->heads ?? "");
        $heads[0] = $this->repobranchname($branch);
        return $heads;
    }

    /** @return list<string> */
    private function extra_heads() {
        if ($this->heads !== null && strlen($this->heads) > 40) {
            $sp = strpos($this->heads, " ");
            return explode(" ", substr($this->heads, $sp + 1));
        } else {
            return [];
        }
    }

    /** @param string $key
     * @param string $head
     * @return CommitList */
    private function head_commit_list($key, $head) {
        // check cache
        if (isset($this->_commit_lists[$key])) {
            return $this->_commit_lists[$key];
        }
        $list = $this->_commit_lists[$key] = new CommitList;

        // read log
        $s = $this->gitrun(["git", "log", "-m", "--name-only", "--format=%x00%ct %H %s%n%P", $head]);
        if ($s === "" && !$this->_refresh_count) {
            $this->refresh(30, true);
            $s = $this->gitrun(["git", "log", "-m", "--name-only", "--format=%x00%ct %H %s%n%P", $head]);
        }

        // parse log
        $p = 0;
        $l = strlen($s);
        $cr = $newcr = null;
        while ($p < $l && $s[$p] === "\0") {
            $p0 = $p + 1;
            if (($p = strpos($s, "\0", $p0)) === false) {
                $p = $l;
            }
            if (($sp1 = strpos($s, " ", $p0)) !== false
                && ($sp2 = strpos($s, " ", $sp1 + 1)) !== false
                && ($nl1 = strpos($s, "\n", $sp2 + 1)) !== false
                && $nl1 < $p
                && ($nl2 = strpos($s, "\n", $nl1 + 1)) !== false
                && $nl2 < $p) {
                $time = substr($s, $p0, $sp1 - $p0);
                $hash = substr($s, $sp1 + 1, $sp2 - ($sp1 + 1));
                if (strlen($time) > 8
                    && strlen($hash) >= 40
                    && ctype_digit($time)
                    && ctype_xdigit($hash)) {
                    if ($cr && $cr->hash === $hash) {
                        // another branch of the merge commit
                    } else if (($cr = $this->_commits[$hash] ?? null)) {
                        // assume already completely populated
                        $newcr = null;
                        $list->commits[$hash] = $cr;
                    } else {
                        $subject = substr($s, $sp2 + 1, $nl1 - ($sp2 + 1));
                        /** @phan-suppress-next-line PhanTypeMismatchArgument */
                        $newcr = new CommitRecord((int) $time, $hash, $subject, $head);
                        $this->_commits[$hash] = $list->commits[$hash] = $cr = $newcr;
                        if (strpos(substr($s, $nl1 + 1, $nl2 - ($nl1 + 1)), " ") !== false) {
                            $cr->_flags |= CommitRecord::CRF_IS_MERGE;
                        }
                    }
                    if ($newcr) {
                        self::set_directory($cr, $s, $nl2, $p);
                    }
                }
            }
        }

        return $list;
    }

    /** @param string $branch
     * @return CommitList */
    private function expanded_head_commit_list($branch) {
        $key = ".x/{$branch}";
        if (isset($this->_commit_lists[$key])) {
            return $this->_commit_lists[$key];
        }
        $this->_commit_lists[$key] = $list = new CommitList;

        $list->merge($this->head_commit_list($branch, $this->repobranchname($branch)));
        foreach ($this->extra_heads() as $xhead) {
            $list->merge($this->head_commit_list(".h/{$xhead}", $xhead));
        }
        return $list;
    }

    /** @param ?Pset $pset
     * @param ?string $branch
     * @param bool $expanded
     * @return CommitList */
    function commit_list($pset, $branch, $expanded = false) {
        $branch = $branch ?? ($pset ? $pset->main_branch : $this->conf->default_main_branch);

        // simple cases first
        if (!$pset
            || $pset->directory_noslash === ""
            || ($this->_truncated_psetdir[$pset->psetid] ?? false)) {
            // simplest case: just this branch
            if (!$expanded || strlen($this->heads ?? "") <= 40) {
                return $this->head_commit_list($branch, $this->repobranchname($branch));
            }

            // expand branch with heads
            return $this->expanded_head_commit_list($branch);
        }

        // restrict to directory
        $dir = $pset->directory_noslash;
        $test_file = $pset->test_file;
        assert(strpos($dir, "/") === false);
        $key = ".d/{$dir}/" . ($expanded ? ".x/" : "") . $branch;
        if (isset($this->_commit_lists[$key])) {
            return $this->_commit_lists[$key];
        }
        $list = new CommitList;

        foreach ($this->commit_list(null, $branch, $expanded) as $cr) {
            if ($cr->__touches_directory($dir)) {
                $list->commits[$cr->hash] = $cr;
            } else if (empty($list->commits)
                       && $test_file !== null
                       && !$list->suspicious_directory
                       && $cr->__touches_directory($test_file)) {
                $list->suspicious_directory = true;
            }
        }

        if (empty($list->commits) && $list->suspicious_directory) {
            $this->_truncated_psetdir[$pset->psetid] = true;
            return $this->commit_list(null, $branch, $expanded);
        }

        $this->_commit_lists[$key] = $list;
        return $list;
    }

    /** @param ?Pset $pset
     * @param ?string $branch
     * @param bool $expanded
     * @return array<string,CommitRecord> */
    function commits($pset, $branch, $expanded = false) {
        return $this->commit_list($pset, $branch, $expanded)->commits;
    }

    /** @param ?string $branch
     * @return ?CommitRecord */
    function latest_commit(?Pset $pset = null, $branch = null) {
        return $this->commit_list($pset, $branch)->latest();
    }

    /** @param ?string $branch
     * @return ?CommitRecord */
    function latest_nontrivial_commit(?Pset $pset = null, $branch = null) {
        $branch = $branch ?? ($pset ? $pset->main_branch : $this->conf->default_main_branch);
        $checked_merges = false;

        foreach ($this->commit_list($pset, $branch) as $c) {
            if ($c->is_merge() && !$checked_merges) {
                $this->check_merges($branch);
                $checked_merges = true;
            }
            if (!$c->is_trivial_merge()) {
                return $c;
            }
        }

        return null;
    }

    /** @param string $hashpart
     * @param array<string,CommitRecord>|CommitList $commitlist
     * @return array{?CommitRecord,bool} */
    static function find_listed_commit($hashpart, $commitlist) {
        if (strlen($hashpart) < 5) {
            // require at least 5 characters
            return [null, true];
        }
        if (strlen($hashpart) === 40 || strlen($hashpart) === 64) {
            $commit = $commitlist[$hashpart] ?? null;
            return [$commit, $commit !== null];
        }
        $match = null;
        foreach ($commitlist as $h => $cx) {
            if (str_starts_with($h, $hashpart)) {
                if ($match !== null) {
                    return [null, true];
                } else {
                    $match = $cx;
                }
            }
        }
        return $match ? [$match, true] : [null, false];
    }

    /** @param string $hashpart
     * @param ?string $branch
     * @return ?CommitRecord */
    function connected_commit($hashpart, ?Pset $pset = null, $branch = null) {
        // ensure there's some commits
        if (empty($this->_commits)) {
            $this->commit_list($pset, $branch);
        }
        // exact commits don’t need access to full commit lists
        $exact = strlen($hashpart) >= 40;
        if (array_key_exists($hashpart, $this->_commits)) {
            return $this->_commits[$hashpart];
        }
        // branch heads
        if ($this->_heads_loaded === 0) {
            $this->_heads_loaded = 1;
            foreach ($this->branches() as $br) {
                $this->head_commit_list($br, $this->repobranchname($br));
            }
            if (array_key_exists($hashpart, $this->_commits)) {
                return $this->_commits[$hashpart];
            }
        }
        // extra heads (e.g., student does `git push -f`)
        if ($this->_heads_loaded === 1) {
            $this->_heads_loaded = 2;
            foreach ($this->extra_heads() as $x) {
                if (!isset($this->_commits[$x]))
                    $this->head_commit_list(".h/{$x}", $x);
            }
            if (array_key_exists($hashpart, $this->_commits)) {
                return $this->_commits[$hashpart];
            }
        }
        // snapshots
        if ($this->_heads_loaded === 2) {
            $this->_heads_loaded = 3;
            preg_match_all('/^([0-9a-f]+) refs\/tags\/' . preg_quote($this->reponame()) . '\.snap/m',
                           $this->conf->repodir_refs($this->cacheid), $m);
            foreach ($m[1] as $hash) {
                if (strlen($hash) >= 40 && !isset($this->_commits[$hash])) {
                    $this->head_commit_list(".h/{$hash}", $hash);
                }
            }
        }
        // nothing left to load
        if (!array_key_exists($hashpart, $this->_commits)) {
            list($this->_commits[$hashpart], $exact) = self::find_listed_commit($hashpart, $this->_commits);
        }
        return $this->_commits[$hashpart];
    }

    /** @param ?string $branch
     * @param ?int $limit
     * @return array<string,string> */
    function author_emails($branch = null, $limit = null) {
        $command = ["git", "log", "--format=%ae"];
        if ($limit) {
            $command[] = "-n{$limit}";
        }
        $argpos = count($command);
        $users = [];
        foreach ($this->heads_on($branch ?? $this->conf->default_main_branch) as $h) {
            $command[$argpos] = $h;
            $result = $this->gitrun($command);
            foreach (explode("\n", $result) as $line) {
                if ($line !== "")
                    $users[strtolower($line)] = $line;
            }
        }
        return $users;
    }

    /** @param string $tree
     * @param string ...$files
     * @return GitTreeListInfo */
    function ls_files_info($tree, ...$files) {
        $command = ["git", "ls-tree", "-r", "-z", $tree];
        foreach ($files as $f) {
            if ($f !== "") {
                $command[] = preg_replace('/\/+\z/', "", $f);
            }
        }
        $gr = $this->gitruninfo($command);
        $gtl = new GitTreeListInfo;
        $gtl->stderr = $gr->stderr;
        $gtl->status = $gr->status;
        $gtl->ok = $gr->ok;
        foreach (explode("\0", $gr->stdout) as $line) {
            if ($line !== ""
                && ($tab = strpos($line, "\t")) !== false
                && ($sp = strpos($line, " ")) !== false
                && substr_compare($line, " blob ", $sp, 6) === 0) {
                $gtl->modes[] = intval(substr($line, 0, $sp), 8);
                $gtl->names[] = substr($line, $sp + 6, $tab - $sp - 6);
                $gtl->paths[] = substr($line, $tab + 1);
            }
        }
        return $gtl;
    }

    /** @param string $tree
     * @param string ...$files
     * @return list<string> */
    function ls_files($tree, ...$files) {
        $gtl = $this->ls_files_info($tree, ...$files);
        return $gtl->ok ? $gtl->paths : [];
    }

    /** @return bool */
    function update_info() {
        if ($this->infosnapat >= $this->snapat) {
            return false;
        }
        $qstager = Dbl::make_multi_query_stager($this->conf->dblink, Dbl::F_ERROR);
        $result = $this->conf->qe("select * from RepositoryGrade where repoid=? and gradebhash is not null and (commitat is null or commitat=0)", $this->repoid);
        while (($rpi = RepositoryPsetInfo::fetch($result))) {
            $c = $this->connected_commit($rpi->gradehash);
            $at = $c ? $c->commitat : 0;
            $qstager("update RepositoryGrade set commitat=? where repoid=? and branchid=? and pset=? and gradebhash=?", $at, $rpi->repoid, $rpi->branchid, $rpi->pset, $rpi->gradebhash);
        }
        Dbl::free($result);
        $qstager("update Repository set infosnapat=greatest(infosnapat,?) where repoid=?",
                 $this->snapat, $this->repoid);
        $qstager(null);
        return true;
    }


    /** @return ?string */
    static private function _temp_repodir() {
        $root = SiteLoader::$root;
        $n = 0;
        while (true) {
            $rand = mt_rand(100000000, 999999999);
            $tmpdir = "{$root}/repo/tmprepo.{$rand}";
            if (@mkdir($tmpdir, 0770)) {
                break;
            } else if (++$n > 20) {
                error_log("Cannot create temporary repository directory");
                return null;
            }
        }
        chmod($tmpdir, 02770);
        register_shutdown_function("rm_rf_tempdir", $tmpdir);
        return Subprocess::runok(["git", "init", "-b", "main"], $tmpdir)
            ? $tmpdir : null;
    }

    /** @param string $hash
     * @return ?string */
    private function prepare_truncated_hash(Pset $pset, $hash) {
        $tmpdir = self::_temp_repodir();
        if (!$tmpdir) {
            return null;
        }

        $subdirs = [];
        $addcommand = ["git", "add"];
        $gtl = $this->ls_files_info($hash, $pset->directory_noslash);
        for ($i = 0; $i !== count($gtl->paths); ++$i) {
            $gr = $this->gitruninfo(["git", "cat-file", "blob", $gtl->names[$i]]);
            if (!$gr->ok) {
                return null;
            }

            $f = substr($gtl->paths[$i], strlen($pset->directory_slash));
            if (($slash = strrpos($f, "/")) !== false) {
                $subdir = substr($f, 0, $slash);
                if (!isset($subdirs[$subdir])
                    && !@mkdir("{$tmpdir}/{$subdir}", 0770, true)) {
                    return null;
                }
                $subdirs[$subdir] = true;
            }

            if ($gtl->modes[$i] === GitTreeListInfo::MODE_LINK) {
                $ok = @symlink($gr->stdout, "{$tmpdir}/{$f}");
            } else {
                $ok = @file_put_contents("{$tmpdir}/{$f}", $gr->stdout) === strlen($gr->stdout);
                if ($ok) {
                    chmod("{$tmpdir}/{$f}", $gtl->modes[$i] & 0777);
                }
            }
            if (!$ok) {
                return null;
            }

            $addcommand[] = $f;
        }

        if (!$this->gitrunok($addcommand, ["cwd" => $tmpdir])
            || !$this->gitrunok(["git", "commit", "-m", "Truncated version of {$hash} for pset {$pset->key}"], ["cwd" => $tmpdir])
            || !$this->gitrunok(["git", "push", "-f", $this->repodir(), "main:refs/tags/trunc{$pset->id}_{$hash}"], ["cwd" => $tmpdir])) {
            return null;
        }

        return $this->rev_parse("trunc{$pset->id}_{$hash}");
    }

    /** @param string $refname
     * @return ?string */
    function truncated_hash(Pset $pset, $refname) {
        $hash = $refname;
        if (!git_refname_is_full_hash($hash)) {
            $hash = $this->rev_parse($hash);
        }
        if ($hash === null) {
            return null;
        }
        if (!array_key_exists($hash, $this->_truncated_hashes)) {
            $this->_truncated_hashes[$hash] = $this->rev_parse("trunc{$pset->key}_{$hash}")
                ?? $this->prepare_truncated_hash($pset, $hash);
        }
        return $this->_truncated_hashes[$hash];
    }


    /** @param null|string|list<string>|array<string,true> $files
     * @return null|array<string,true> */
    static function fix_diff_files($files) {
        if ($files === null || empty($files)) {
            return null;
        } else if (is_associative_array($files)) {
            return $files;
        } else if (is_string($files)) {
            return [$files => true];
        } else {
            $xfiles = [];
            foreach ($files as $f) {
                $xfiles[$f] = true;
            }
            return $xfiles;
        }
    }

    /** @param array<string,DiffInfo> $diffargs
     * @param list<string> $diffoptions */
    private function parse_diff($diffargs, Pset $pset, $hasha, $hashb, $diffoptions) {
        $command = array_merge(["git", "diff"], $diffoptions, [$hasha, $hashb, "--"]);
        foreach ($diffargs as $fn => $dix) {
            $command[] = $fn;
        }
        $result = $this->gitrun($command);
        $alineno = $blineno = null;
        $di = null;
        '@phan-var-force ?DiffInfo $di';
        $pos = 0;
        $len = strlen($result);
        while (true) {
            if ($di && $di->truncated) {
                while ($pos < $len
                       && (($ch = $result[$pos]) === " " || $ch === "+" || $ch === "-")) {
                    $nlpos = strpos($result, "\n", $pos);
                    $pos = $nlpos === false ? $len : $nlpos + 1;
                }
            }
            if ($pos >= $len) {
                break;
            }
            $nlpos = strpos($result, "\n", $pos);
            $line = $nlpos === false ? substr($result, $pos) : substr($result, $pos, $nlpos - $pos);
            $pos = $nlpos === false ? $len : $nlpos + 1;
            if ($line == "") {
                /* do nothing */;
            } else if ($line[0] === " " && $di && $alineno) {
                $di->add(" ", $alineno, $blineno, substr($line, 1));
                ++$alineno;
                ++$blineno;
            } else if ($line[0] === "-" && $di && $alineno) {
                $di->add("-", $alineno, $blineno, substr($line, 1));
                ++$alineno;
            } else if ($line[0] === "+" && $di && $blineno) {
                $di->add("+", $alineno, $blineno, substr($line, 1));
                ++$blineno;
            } else if ($line[0] === "@" && $di && preg_match('/\A@@ -(\d+),\d+ \+(\d+)(?:|,\d+) @@/', $line, $m)) {
                $alineno = +$m[1];
                $blineno = +$m[2];
                $di->add("@", null, null, $line);
            } else if ($line[0] === "d" && preg_match('/\Adiff --git a\/(.*) b\/\1\z/', $line, $m)) {
                if ($di) {
                    $di->finish();
                }
                $di = $diffargs[$m[1]];
                $alineno = $blineno = null;
            } else if ($line[0] === "B" && $di && preg_match('/\ABinary files/', $line)) {
                $di->add("@", null, null, $line);
            } else if ($line[0] === "\\" && strpos($line, "No newline") !== false) {
                $di->set_ends_without_newline();
            } else {
                $alineno = $blineno = null;
            }
        }
        if ($di) {
            $di->finish();
        }
    }

    /** @return array<string,DiffInfo> */
    function diff(Pset $pset, CommitRecord $commita, CommitRecord $commitb, $options = null) {
        $options = (array) $options;
        $diffs = [];

        $repodir = $truncpfx = "";
        if ($pset->directory_noslash !== "") {
            if ($this->truncated_psetdir($pset)) {
                $truncpfx = $pset->directory_noslash . "/";
            } else {
                $repodir = $pset->directory_noslash . "/";
            }
        }

        $hasha = $commita->hash;
        if ($truncpfx
            && $commita->is_handout($pset)
            && !$commitb->is_handout($pset)) {
            $hasha = $this->truncated_hash($pset, $hasha);
        }
        $hashb = $commitb->hash;
        if ($truncpfx
            && $commitb->is_handout($pset)
            && !$commita->is_handout($pset)) {
            $hashb = $this->truncated_hash($pset, $hashb);
        }

        $ignore_diffconfig = $commita->is_handout($pset) && $commitb->is_handout($pset);
        $no_full = $options["no_full"] ?? false;
        $no_user_collapse = $options["no_user_collapse"] ?? false;
        $needfiles = self::fix_diff_files($options["needfiles"] ?? null);
        $onlyfiles = self::fix_diff_files($options["onlyfiles"] ?? null);
        $wdiff = !!($options["wdiff"] ?? false);

        // read "full" files
        if (!$ignore_diffconfig && !$no_full) {
            foreach ($pset->potential_diffconfig_full() as $fname) {
                if (!str_starts_with($fname, $pset->directory_slash)) {
                    $fname = $pset->directory_slash . $fname;
                }
                if ((!$onlyfiles || ($onlyfiles[$fname] ?? false))
                    && ($diffconfig = $pset->find_diffconfig($fname))
                    && $diffconfig->full) {
                    $command = ["git", "show", "{$hashb}:{$repodir}" . substr($fname, strlen($pset->directory_slash))];
                    $result = $this->gitrun($command);
                    $di = new DiffInfo($fname, $diffconfig);
                    $diffs[$di->filename] = $di;
                    foreach (explode("\n", $result) as $idx => $line) {
                        $di->add("+", 0, $idx + 1, $line);
                    }
                    $di->finish();
                }
            }
        }

        $command = ["git", "diff", "--name-only", $hasha, $hashb];
        if ($pset && !$truncpfx && $pset->directory_noslash) {
            array_push($command, "--", $pset->directory_noslash);
        }
        $result = $this->gitrun($command);

        $xdiffs = [];
        foreach (explode("\n", $result) as $line) {
            if ($line != "") {
                $file = $truncpfx . $line;
                $diffconfig = $pset->find_diffconfig($file);
                // skip files presented in their entirety
                if ($diffconfig
                    && !$ignore_diffconfig
                    && !$no_full
                    && $diffconfig->full
                    && ($diffs[$file] ?? null)) {
                    continue;
                }
                // skip files that aren't allowed
                if ($onlyfiles && !($onlyfiles[$truncpfx . $line] ?? false)) {
                    continue;
                }
                // create diff record
                $di = new DiffInfo($file, $diffconfig);
                $di->set_repoa($this, $pset, $hasha, $line, $commita->is_handout($pset));
                $di->set_wdiff($wdiff);
                // decide whether file is collapsed
                if ($no_user_collapse
                    && $diffconfig
                    && !$diffconfig->collapse_default) {
                    $di->set_collapse($diffconfig->collapse_default);
                } else if ($di->collapse
                           && $needfiles
                           && ($needfiles[$file] ?? false)) {
                    $di->set_collapse(null);
                }
                // store diff if collapsed, skip if ignored
                if ($diffconfig
                    && !$ignore_diffconfig
                    && ($diffconfig->ignore || $di->collapse)
                    && (!$needfiles || !($needfiles[$file] ?? false))) {
                    if (!$diffconfig->ignore) {
                        $di->finish_unloaded();
                        $diffs[$file] = $di;
                    }
                } else {
                    $xdiffs[] = $di;
                }
            }
        }

        // only handle 300 files, mark the rest boring
        usort($xdiffs, "DiffInfo::compare");
        if (count($xdiffs) > 300) {
            for ($i = 300; $i < count($xdiffs); ++$i) {
                $xdiffs[$i]->finish_unloaded();
                $diffs[$xdiffs[$i]->filename] = $xdiffs[$i];
            }
            $xdiffs = array_slice($xdiffs, 0, 300);
        }

        // actually read diffs
        if (!empty($xdiffs)) {
            $nd = count($xdiffs);
            $darg = [];
            for ($i = 0; $i < $nd; ++$i) {
                $darg[substr($xdiffs[$i]->filename, strlen($truncpfx))] = $xdiffs[$i];
                if (count($darg) >= 200 || $i == $nd - 1) {
                    $this->parse_diff($darg, $pset, $hasha, $hashb, $wdiff ? ["-w"] : []);
                    $darg = [];
                }
            }
            foreach ($xdiffs as $di) {
                if (!$di->is_empty())
                    $diffs[$di->filename] = $di;
            }
        }

        // ensure a diff for every landmarked file, even if empty
        if ($pset->has_grade_landmark) {
            foreach ($pset->grades() as $g) {
                $file = $g->landmark_file;
                if ($file && !isset($diffs[$file])) {
                    $diffs[$file] = $di = new DiffInfo($file, $pset->find_diffconfig($file));
                    $di->set_repoa($this, $pset, $hasha, substr($file, strlen($truncpfx)), $commitb->is_handout($pset));
                    $di->set_wdiff($wdiff);
                    $di->finish();
                }
            }
        }

        uasort($diffs, "DiffInfo::compare");
        return $diffs;
    }


    /** @return list<string> */
    function content_lines($hash, $filename) {
        foreach (self::$_file_contents as $x) {
            if ($x[0] === $hash && $x[1] === $filename) {
                ++$x[3];
                return $x[2];
            }
        }

        $n = count(self::$_file_contents);
        if ($n === 8) {
            $n = 0;
            for ($i = 1; $i < 8; ++$i) {
                if (self::$_file_contents[$i][3] < self::$_file_contents[$n][3])
                    $n = $i;
            }
        }

        $result = $this->gitrun(["git", "show", "{$hash}:{$filename}"]);
        self::$_file_contents[$n] = [$hash, $filename, explode("\n", $result), 1];
        return self::$_file_contents[$n][2];
    }


    /** @param string $branch
     * @return bool */
    static function validate_branch($branch) {
        return preg_match('/\A(?=[^^:~?*\\[\\\\\\000-\\040\\177]+\z)(?!@\z|.*@\{|[.\/]|.*\/[.\/]|.*[\.\/]\z|.*\.\.|.*\.lock\z|.*\.lock\/|refs\/|[0-9a-fA-F]{40}\z)/', $branch);
    }
}

class GitTreeListInfo {
    /** @var list<int> */
    public $modes = [];
    /** @var list<string> */
    public $names = [];
    /** @var list<string> */
    public $paths = [];
    /** @var string */
    public $stderr;
    /** @var int */
    public $status;
    /** @var bool */
    public $ok;

    const MODE_RW = 0100644;
    const MODE_RWX = 0100755;
    const MODE_LINK = 0120000;
}
