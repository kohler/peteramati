<?php
// repository.php -- Peteramati helper class representing repositories
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
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
    public $open;
    /** @var int */
    public $opencheckat;
    /** @var ?non-empty-string */
    public $snaphash;
    /** @var ?int */
    public $snapat;
    /** @var int */
    public $snapcheckat;
    public $working;
    /** @var int */
    public $snapcommitat;
    /** @var ?string */
    public $snapcommitline;
    /** @var int */
    public $analyzedsnapat;
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
    /** @var array<string,array<string,CommitRecord>> */
    private $_commit_lists = [];
    /** @var array<string,bool> */
    private $_commit_lists_cc = [];

    /** @var list<array{string,string,list<string>,int}> */
    static private $_file_contents = [];

    function __construct(Conf $conf = null) {
        global $Conf;
        $conf = $conf ?? $Conf;
        $this->conf = $conf;
        if (isset($this->repoid)) {
            $this->db_load();
        }
    }

    private function db_load() {
        $this->repoid = (int) $this->repoid;
        $this->open = (int) $this->open;
        $this->opencheckat = (int) $this->opencheckat;
        if ($this->snapat !== null) {
            $this->snapat = (int) $this->snapat;
        }
        $this->snapcheckat = (int) $this->snapcheckat;
        $this->working = (int) $this->working;
        $this->snapcommitat = (int) $this->snapcommitat;
        $this->analyzedsnapat = (int) $this->analyzedsnapat;
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
        $repo_hash = substr(sha1($url), 10, 1);
        $now = time();
        $result = $conf->qe("insert into Repository set url=?, cacheid=?, working=?, open=?, opencheckat=?", $url, $repo_hash, $now, -1, 0);
        return $result ? self::by_id($conf->dblink->insert_id, $conf) : null;
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

    function expand_message($name, Contact $user) {
        return Messages::$main->expand_html($name, $this->reposite->message_defs($user));
    }

    const VALIDATE_TIMEOUT = 5;
    const VALIDATE_TOTAL_TIMEOUT = 15;
    static private $validate_time_used = 0;

    function refresh($delta, $foreground = false) {
        if ((!$this->snapcheckat || $this->snapcheckat + $delta <= Conf::$now)
            && !$this->conf->opt("disableRemote")) {
            $this->conf->qe("update Repository set snapcheckat=? where repoid=?", Conf::$now, $this->repoid);
            $this->snapcheckat = Conf::$now;
            if ($foreground) {
                set_time_limit(30);
            }
            $this->ensure_repodir();
            $this->reposite->gitfetch($this->repoid, $this->cacheid, $foreground);
            if ($foreground) {
                $this->_commits = $this->_commit_lists = $this->_commit_lists_cc = [];
            }
        } else {
            RepositorySite::disabled_remote_error($this->conf);
        }
    }

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
        // Recheck repository openness after a day for closed repositories,
        // and after 30 seconds for open or failed-check repositories.
        if (Conf::$now - $this->opencheckat > ($this->open == 0 ? 86400 : 30)) {
            $open = $this->validate_open($ms);
            if ($open != $this->open || Conf::$now != $this->opencheckat) {
                if ($this->repoid > 0)
                    $this->conf->qe("update Repository set `open`=?, opencheckat=? where repoid=?",
                        $open, Conf::$now, $this->repoid);
                $this->open = $open;
                $this->opencheckat = Conf::$now;
            }
        }
        if ($this->open < 0 && $ms && $ms->user->isPC && !$ms->has_problem()) {
            $ms->warning_at("open", Messages::$main->expand_html("repo_toopublic_timeout", $this->reposite->message_defs($ms->user)));
        }
        if ($this->open > 0 && $ms && !$ms->has_problem()) {
            $ms->error_at("open", Messages::$main->expand_html("repo_toopublic", $this->reposite->message_defs($ms->user)));
        }
        return $this->open;
    }

    static private $working_cache = [];
    function validate_working(MessageSet $ms = null) {
        if (isset(self::$working_cache[$this->url])) {
            return self::$working_cache[$this->url];
        } else if (self::$validate_time_used >= self::VALIDATE_TOTAL_TIMEOUT) {
            return -1;
        }
        $before = microtime(true);
        self::$working_cache[$this->url] = $r = $this->reposite->validate_working($ms);
        self::$validate_time_used += microtime(true) - $before;
        return $r;
    }
    function check_working(MessageSet $ms = null) {
        $working = $this->working;
        if ($working == 0) {
            $working = $this->validate_working($ms);
            if ($working > 0) {
                $this->working = Conf::$now;
                if ($this->repoid > 0)
                    $this->conf->qe("update Repository set working=? where repoid=?", Conf::$now, $this->repoid);
            }
        }
        if ($working < 0 && $ms && $ms->user->isPC && !$ms->has_problem_at("working")) {
            $ms->warning_at("working", Messages::$main->expand_html("repo_working_timeout", $this->reposite->message_defs($ms->user)));
        }
        if ($working == 0 && $ms && !$ms->has_problem_at("working")) {
            $ms->error_at("working", Messages::$main->expand_html("repo_unreadable", $this->reposite->message_defs($ms->user)));
        }
        return $working > 0;
    }

    function validate_ownership(Contact $user, Contact $partner = null, MessageSet $ms = null) {
        return $this->reposite->validate_ownership($this, $user, $partner, $ms);
    }
    function check_ownership(Contact $user, Contact $partner = null, MessageSet $ms = null) {
        list($when, $ownership) = [0, -1];
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
                        $value = json_decode($value, true) ? : [];
                        $value["owner." . $user->contactId] = [time(), $ownership];
                        $this->notes = $value;
                        return json_encode_db($value);
                    },
                    "update Repository set notes=?{desired} where notes?{expected}e and repoid=?", [$this->repoid]);
            }
        }
        if ($ownership == 0 && $ms && !$ms->has_problem_at("ownership")) {
            $ms->warning_at("ownership", $this->expand_message("repo_notowner", $ms->user));
        }
        return $ownership;
    }

    /** @return bool */
    function truncated_psetdir(Pset $pset) {
        return $this->_truncated_psetdir[$pset->id] ?? false;
    }


    /** @return string */
    function ensure_repodir() {
        $subdir = "/repo/repo{$this->cacheid}";
        $repodir = SiteLoader::$root . $subdir;
        if (!file_exists("{$repodir}/.git/config")) {
            if (!mk_site_subdir($subdir, 02770)) {
                return "";
            }
            shell_exec("cd $repodir && git init --shared -b main");
        }
        return $repodir;
    }

    function gitrun($command, $want_stderr = false) {
        $command = str_replace("%REPO%", "repo" . $this->repoid, $command);
        if (!($repodir = $this->ensure_repodir())) {
            throw new Error("cannot safely create repository directory");
        }
        $descriptors = [["file", "/dev/null", "r"], ["pipe", "w"], ["pipe", "w"]];
        $proc = proc_open($command, $descriptors, $pipes, $repodir);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = "";
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $x = fread($pipes[1], 32768);
            $y = fread($pipes[2], 32768);
            $stdout .= $x;
            $stderr .= $y;
            if ($x === false || $y === false) {
                break;
            }
            $r = [$pipes[1], $pipes[2]];
            $w = $e = [];
            stream_select($r, $w, $e, 5);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        if (!$want_stderr) {
            return $stdout;
        } else {
            return (object) ["stdout" => $stdout, "stderr" => $stderr, "status" => $status];
        }
    }

    /** @param string $arg
     * @return ?string */
    private function rev_parse($arg) {
        $x = $this->gitrun("git rev-parse --verify " . escapeshellarg($arg), true);
        if ($x->status == 0 && $x->stdout) {
            return trim($x->stdout);
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

    /** @param array<string,CommitRecord> &$list
     * @param string $head */
    private function load_commits_from_head(&$list, $head) {
        $s = $this->gitrun("git log -m --name-only --format='%x00%ct %H %s%n%P' " . escapeshellarg($head));
        if ($s === "") {
            $this->refresh(30, true);
            $s = $this->gitrun("git log -m --name-only --format='%x00%ct %H %s%n%P' " . escapeshellarg($head));
        }
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
                        $list[$hash] = $cr;
                    } else {
                        $subject = substr($s, $sp2 + 1, $nl1 - ($sp2 + 1));
                        /** @phan-suppress-next-line PhanTypeMismatchArgument */
                        $newcr = new CommitRecord((int) $time, $hash, $subject, $head);
                        $this->_commits[$hash] = $cr = $list[$hash] = $newcr;
                        $cr->_is_merge = strpos(substr($s, $nl1 + 1, $nl2 - ($nl1 + 1)), " ") !== false;
                    }
                    if ($newcr) {
                        self::set_directory($cr, $s, $nl2, $p);
                    }
                }
            }
        }
    }

    /** @param array<string,CommitRecord> &$list
     * @param string $head */
    private function load_trivial_merges_from_head(&$list, $head) {
        $s = $this->gitrun("git log --cc --name-only --format='%x00%H' " . escapeshellarg($head));
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
                    $cr->_is_trivial_merge = $nl1 === $p;
                }
            }
        }
    }

    /** @param string $branch
     * @return list<string> */
    private function heads_on($branch) {
        $heads = explode(" ", $this->heads ?? "");
        $heads[0] = "%REPO%/$branch";
        return $heads;
    }

    /** @param ?string $branch
     * @return array<string,CommitRecord> */
    function commits(Pset $pset = null, $branch = null) {
        $branch = $branch ?? ($pset ? $pset->main_branch : $this->conf->default_main_branch);

        if (isset($this->_commit_lists[$branch])) {
            $list = $this->_commit_lists[$branch];
        } else {
            // XXX should gitrun once, not multiple times
            $list = [];
            foreach ($this->heads_on($branch) as $h) {
                $this->load_commits_from_head($list, $h);
            }
            $this->_commit_lists[$branch] = $list;
        }

        if ($pset
            && $pset->directory_noslash !== ""
            && !($this->_truncated_psetdir[$pset->psetid] ?? false)) {
            $dir = $pset->directory_noslash;
            assert(strpos($dir, "/") === false);
        } else {
            $dir = "";
        }

        if ($dir !== "" && !empty($list)) {
            $key = "$dir//$branch";
            if (isset($this->_commit_lists[$key])) {
                $list = $this->_commit_lists[$key];
            } else {
                $xlist = [];
                foreach ($list as $cr) {
                    if ($cr->directory === $dir
                        || (is_array($cr->directory) && in_array($dir, $cr->directory))) {
                        $xlist[$cr->hash] = $cr;
                    }
                }
                if (empty($xlist)
                    && isset($pset->test_file)
                    && ($this->_truncated_psetdir[$pset->psetid] =
                        !!$this->ls_files("%REPO%/$branch", $pset->test_file))) {
                    $xlist = $list;
                }
                $list = $this->_commit_lists[$key] = $xlist;
            }
        }

        return $list;
    }

    /** @param ?string $branch
     * @return ?CommitRecord */
    function latest_commit(Pset $pset = null, $branch = null) {
        foreach ($this->commits($pset, $branch) as $c) {
            return $c;
        }
        return null;
    }

    /** @param ?string $branch
     * @return ?CommitRecord */
    function latest_nontrivial_commit(Pset $pset = null, $branch = null) {
        $branch = $branch ?? ($pset ? $pset->main_branch : $this->conf->default_main_branch);
        $trivial_merges_known = !!($this->_commit_lists_cc[$branch] ?? false);

        foreach ($this->commits($pset, $branch) as $c) {
            if ($c->_is_merge && !$trivial_merges_known) {
                $cx = $this->commits(null, $branch);
                foreach ($this->heads_on($branch) as $h) {
                    $this->load_trivial_merges_from_head($cx, $h);
                }
                $trivial_merges_known = $this->_commit_lists_cc[$branch] = true;
            }
            if (!$c->_is_trivial_merge) {
                return $c;
            }
        }

        return null;
    }

    /** @param string $hashpart
     * @param array<string,CommitRecord> $commitlist
     * @return array{?CommitRecord,bool} */
    static function find_listed_commit($hashpart, $commitlist) {
        if (strlen($hashpart) < 5) {
            // require at least 5 characters
            return [null, true];
        } else if (strlen($hashpart) === 40) {
            return [$commitlist[$hashpart] ?? null, true];
        } else {
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
    }

    /** @param string $hashpart
     * @param ?string $branch
     * @return ?CommitRecord */
    function connected_commit($hashpart, Pset $pset = null, $branch = null) {
        if (empty($this->_commits)) {
            // load some commits first
            $this->commits($pset, $branch);
        }
        list($cx, $definitive) = self::find_listed_commit($hashpart, $this->_commits);
        if ($definitive) {
            return $cx;
        } else if (!isset($this->_commit_lists[$this->conf->default_main_branch])) {
            // check if all commits are loaded
            $this->commits();
            return $this->connected_commit($hashpart);
        } else {
            // check snapshots
            return $this->find_snapshot($hashpart);
        }
    }

    function author_emails($branch = null, $limit = null) {
        $limit = $limit ? " -n$limit" : "";
        $users = [];
        foreach ($this->heads_on($branch ?? $this->conf->default_main_branch) as $h) {
            $result = $this->gitrun("git log$limit --format=%ae " . escapeshellarg($h));
            foreach (explode("\n", $result) as $line) {
                if ($line !== "")
                    $users[strtolower($line)] = $line;
            }
        }
        return $users;
    }

    function ls_files($tree, $files = []) {
        if (is_string($files)) {
            $files = array($files);
        }
        $suffix = "";
        foreach ($files as $f) {
            $suffix .= " " . escapeshellarg(preg_replace(',/+\z,', '', $f));
        }
        $result = $this->gitrun("git ls-tree -r --name-only " . escapeshellarg($tree) . $suffix);
        $x = explode("\n", $result);
        if (!empty($x) && $x[count($x) - 1] == "") {
            array_pop($x);
        }
        return $x;
    }


    function analyze_snapshots() {
        if ($this->snapat <= $this->analyzedsnapat) {
            return;
        }
        $timematch = " ";
        if ($this->analyzedsnapat) {
            $timematch = gmdate("Ymd.His", $this->analyzedsnapat);
        }
        $qv = [];
        $analyzed_snaptime = 0;
        foreach (glob(SiteLoader::$root . "/repo/repo$this->cacheid/.git/refs/tags/repo{$this->repoid}.snap*") as $snapfile) {
            $time = substr($snapfile, strrpos($snapfile, ".snap") + 5);
            if (strcmp($time, $timematch) <= 0
                || !preg_match('/\A(\d\d\d\d)(\d\d)(\d\d)\.(\d\d)(\d\d)(\d\d)\z/', $time, $m)) {
                continue;
            }
            $snaptime = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
            $analyzed_snaptime = max($snaptime, $analyzed_snaptime);
            $head = file_get_contents($snapfile);
            $result = $this->gitrun("git log -n20000 --simplify-merges --format=%H " . escapeshellarg($head));
            foreach (explode("\n", $result) as $line) {
                if (strlen($line) == 40
                    && (!isset($qv[$line]) || $qv[$line][2] > $snaptime))
                    $qv[$line] = [$this->repoid, hex2bin($line), $snaptime];
            }
        }
        if (!empty($qv)) {
            $this->conf->qe("insert into RepositoryCommitSnapshot (repoid, bhash, snapshot) values ?v on duplicate key update snapshot=least(snapshot,values(snapshot))", $qv);
        }
        if ($analyzed_snaptime) {
            $this->conf->qe("update Repository set analyzedsnapat=greatest(analyzedsnapat,?) where repoid=?", $analyzed_snaptime, $this->repoid);
        }
    }

    function find_snapshot($hash) {
        if (array_key_exists($hash, $this->_commits)) {
            return $this->_commits[$hash];
        }
        $this->analyze_snapshots();
        $bhash = hex2bin(substr($hash, 0, strlen($hash) & ~1));
        if (strlen($bhash) == 20) {
            $result = $this->conf->qe("select * from RepositoryCommitSnapshot where repoid=? and bhash=?", $this->repoid, $bhash);
        } else {
            $result = $this->conf->qe("select * from RepositoryCommitSnapshot where repoid=? and left(bhash,?)=?", $this->repoid, strlen($bhash), $bhash);
        }
        $match = null;
        while ($result && ($row = $result->fetch_object())) {
            $h = bin2hex($row->bhash);
            if (str_starts_with($h, $hash)) {
                if ($match) {
                    $match = false;
                    break;
                }
                $match = $row;
            }
        }
        Dbl::free($result);
        if ($match) {
            $list = [];
            $this->load_commits_from_head($list, "repo{$this->repoid}.snap" . gmdate("Ymd.His", $match->snapshot));
        }
        if (!array_key_exists($hash, $this->_commits)) {
            $this->_commits[$hash] = null;
        }
        return $this->_commits[$hash];
    }

    function update_info() {
        if ($this->infosnapat < $this->snapat) {
            $qstager = Dbl::make_multi_query_stager($this->conf->dblink, Dbl::F_ERROR);
            $result = $this->conf->qe("select * from RepositoryGrade where repoid=? and gradebhash is not null and (commitat is null or commitat=0)", $this->repoid);
            while (($rpi = RepositoryPsetInfo::fetch($result))) {
                $c = $this->connected_commit(bin2hex($rpi->gradebhash));
                $at = $c ? $c->commitat : 0;
                $qstager("update RepositoryGrade set commitat=? where repoid=? and branchid=? and pset=? and gradebhash=?", $at, $rpi->repoid, $rpi->branchid, $rpi->pset, $rpi->gradebhash);
            }
            Dbl::free($result);
            $qstager("update Repository set infosnapat=greatest(infosnapat,?) where repoid=?",
                     [$this->snapat, $this->repoid]);
            $qstager(true);
            return true;
        } else {
            return false;
        }
    }


    private function _temp_repo_clone() {
        assert(isset($this->repoid) && isset($this->cacheid));
        $suffix = "";
        $suffixn = 0;
        while (true) {
            $d = SiteLoader::$root . "/repo/tmprepo." . Conf::$now . $suffix;
            if (mkdir($d, 0770)) {
                break;
            }
            ++$suffixn;
            $suffix = "_" . $suffixn;
        }
        chmod($d, 02770);
        $answer = shell_exec("cd $d && git init -b main >/dev/null && git remote add origin " . SiteLoader::$root . "/repo/repo{$this->cacheid} >/dev/null && echo yes");
        return ($answer === "yes\n" ? $d : null);
    }

    /** @param string $hash
     * @return ?string */
    private function prepare_truncated_hash(Pset $pset, $hash) {
        $pset_files = $this->ls_files($hash, $pset->directory_noslash);
        foreach ($pset_files as &$f) {
            $f = escapeshellarg(substr($f, strlen($pset->directory_slash)));
        }
        unset($f);

        if (!($trepo = $this->_temp_repo_clone())) {
            return null;
        }
        $psetdir_arg = escapeshellarg($pset->directory_slash);
        $trepo_arg = escapeshellarg($trepo);
        foreach ($pset_files as $f) {
            $this->gitrun("mkdir -p \"`dirname {$trepo_arg}/{$f}`\" && git show {$hash}:{$psetdir_arg}{$f} > {$trepo_arg}/{$f}");
        }

        shell_exec("cd $trepo_arg && git add " . join(" ", $pset_files) . " && git commit -m 'Truncated version of $hash'");
        shell_exec("cd $trepo_arg && git push -f origin master:refs/tags/truncated_$hash");
        shell_exec("rm -rf $trepo");
        return $this->rev_parse("truncated_{$hash}");
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
            $truncated_hash = $this->rev_parse("truncated_{$hash}");
            if (!$truncated_hash) {
                $truncated_hash = $this->prepare_truncated_hash($pset, $hash);
            }
            $this->_truncated_hashes[$hash] = $truncated_hash;
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
     * @param string $diffoptions */
    private function parse_diff($diffargs, Pset $pset, $hasha_arg, $hashb_arg, $diffoptions = "") {
        $command = "git diff{$diffoptions} {$hasha_arg} {$hashb_arg} --";
        foreach ($diffargs as $fn => $dix) {
            $command .= " " . escapeshellarg(quotemeta($fn));
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
            $psetdir = escapeshellarg($pset->directory_noslash);
            if ($this->truncated_psetdir($pset)) {
                $truncpfx = $pset->directory_noslash . "/";
            } else {
                $repodir = $psetdir . "/";
            }
        } else {
            $psetdir = null;
        }

        $hasha = $commita->hash;
        if ($truncpfx
            && $pset->is_handout($commita)
            && !$pset->is_handout($commitb)) {
            $hasha = $this->truncated_hash($pset, $hasha);
        }
        $hasha_arg = escapeshellarg($hasha);
        $hashb = $commitb->hash;
        if ($truncpfx
            && $pset->is_handout($commitb)
            && !$pset->is_handout($commita)) {
            $hashb = $this->truncated_hash($pset, $hashb);
        }
        $hashb_arg = escapeshellarg($hashb);

        $ignore_diffconfig = $pset->is_handout($commita) && $pset->is_handout($commitb);
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
                    $result = $this->gitrun("git show {$hashb_arg}:{$repodir}" . escapeshellarg(substr($fname, strlen($pset->directory_slash))));
                    $di = new DiffInfo($fname, $diffconfig);
                    $diffs[$di->filename] = $di;
                    foreach (explode("\n", $result) as $idx => $line) {
                        $di->add("+", 0, $idx + 1, $line);
                    }
                    $di->finish();
                }
            }
        }

        $command = "git diff --name-only {$hasha_arg} {$hashb_arg}";
        if ($pset && !$truncpfx && $pset->directory_noslash) {
            $command .= " -- " . escapeshellarg($pset->directory_noslash);
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
                $di->set_repoa($this, $pset, $hasha, $line, $pset->is_handout($commita));
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
                    $this->parse_diff($darg, $pset, $hasha_arg, $hashb_arg, $wdiff ? " -w" : "");
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
                    $di->set_repoa($this, $pset, $hasha, substr($file, strlen($truncpfx)), $pset->is_handout($commita));
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

        $result = $this->gitrun("git show " . escapeshellarg("{$hash}:{$filename}"));
        self::$_file_contents[$n] = [$hash, $filename, explode("\n", $result), 1];
        return self::$_file_contents[$n][2];
    }


    /** @param string $branch
     * @return bool */
    static function validate_branch($branch) {
        return preg_match('/\A(?=[^^:~?*\\[\\\\\\000-\\040\\177]+\z)(?!@\z|.*@\{|[.\/]|.*\/[.\/]|.*[\.\/]\z|.*\.\.|.*\.lock\z|.*\.lock\/)/', $branch);
    }
}
