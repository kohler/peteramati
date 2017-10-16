<?php
// repository.php -- Peteramati helper class representing repositories
// Peteramati is Copyright (c) 2013-2016 Eddie Kohler
// See LICENSE for open-source distribution terms

class RepositoryCommitInfo {
    public $commitat;
    public $hash;
    public $subject;
    public $fromhead;
    const HANDOUTHEAD = "*handout*";
    function __construct($commitat, $hash, $subject, $fromhead = null) {
        $this->commitat = $commitat;
        $this->hash = $hash;
        $this->subject = $subject;
        $this->fromhead = $fromhead;
    }
    function from_handout() {
        return $this->fromhead === self::HANDOUTHEAD;
    }
}

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
    public $analyzedsnapat;
    public $notes;
    public $heads;

    public $is_handout = false;
    public $viewable_by = [];
    public $_truncated_hashes = [];
    public $_truncated_psetdir = [];
    private $_commits = [];
    private $_commit_lists = [];

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
        $this->analyzedsnapat = (int) $this->analyzedsnapat;
        if ($this->notes !== null)
            $this->notes = json_decode($this->notes, true);
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
    function ssh_url() {
        return $this->reposite->ssh_url();
    }
    function friendly_url() {
        return $this->reposite->friendly_url();
    }
    function friendly_url_like(Repository $other) {
        if ($this->reposite->siteclass === $other->reposite->siteclass)
            return $this->reposite->friendly_url();
        return $this->url;
    }

    function expand_message($name, Contact $user) {
        return Messages::$main->expand_html($name, $this->reposite->message_defs($user));
    }

    const VALIDATE_TIMEOUT = 5;
    const VALIDATE_TOTAL_TIMEOUT = 15;
    static private $validate_time_used = 0;

    function refresh($delta, $foreground = false) {
        global $ConfSitePATH, $Now;
        if ((!$this->snapcheckat || $this->snapcheckat + $delta <= $Now)
            && !$this->conf->opt("disableGitfetch")
            && !$this->conf->opt("disableRemote")) {
            $this->conf->qe("update Repository set snapcheckat=? where repoid=?", $Now, $this->repoid);
            $this->snapcheckat = $Now;
            if ($foreground)
                set_time_limit(30);
            // see also handout_repo
            $command = "$ConfSitePATH/src/gitfetch $this->repoid $this->cacheid " . escapeshellarg($this->ssh_url()) . " 1>&2" . ($foreground ? "" : " &");
            shell_exec($command);
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
        global $Now;
        // Recheck repository openness after a day for closed repositories,
        // and after 30 seconds for open or failed-check repositories.
        if ($Now - $this->opencheckat > ($this->open == 0 ? 86400 : 30)) {
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
        if ($working < 0 && $ms && $ms->user->isPC && !$ms->has_problem("working"))
            $ms->set_warning_html("working", Messages::$main->expand_html("repo_working_timeout", $this->reposite->message_defs($ms->user)));
        if ($working == 0 && $ms && !$ms->has_problem("working"))
            $ms->set_error_html("working", Messages::$main->expand_html("repo_unreadable", $this->reposite->message_defs($ms->user)));
        return $working > 0;
    }

    function validate_ownership(Contact $user, Contact $partner = null, MessageSet $ms = null) {
        return $this->reposite->validate_ownership($this, $user, $partner, $ms);
    }
    function check_ownership(Contact $user, Contact $partner = null, MessageSet $ms = null) {
        global $Now;
        list($when, $ownership) = [0, -1];
        $always = $this->reposite->validate_ownership_always();
        if ($this->notes && isset($this->notes["owner." . $user->contactId]))
            list($when, $ownership) = $this->notes["owner." . $user->contactId];
        if ($Now - $when > ($ownership > 0 ? 86400 : 30) || $always) {
            $ownership = $this->validate_ownership($user, $partner, $ms);
            if (!$always)
                Dbl::compare_and_swap($user->conf->dblink,
                    "select notes from Repository where repoid=?", [$this->repoid],
                    function ($value) use ($user, $ownership) {
                        $value = json_decode($value, true) ? : [];
                        $value["owner." . $user->contactId] = [time(), $ownership];
                        $this->notes = $value;
                        return json_encode($value);
                    },
                    "update Repository set notes=?{desired} where notes?{expected}e and repoid=?", [$this->repoid]);
        }
        if ($ownership == 0 && $ms && !$ms->has_problem("ownership"))
            $ms->set_warning_html("ownership", $this->expand_message("repo_notowner", $ms->user));

        return $ownership;
    }

    function truncated_psetdir(Pset $pset) {
        return get($this->_truncated_psetdir, $pset->id);
    }


    function gitrun($command, $want_stderr = false) {
        global $ConfSitePATH;
        $command = str_replace("%REPO%", "repo" . $this->repoid, $command);
        $repodir = "$ConfSitePATH/repo/repo$this->cacheid";
        if (!file_exists("$repodir/.git/config")) {
            is_dir($repodir) || mkdir($repodir, 0770);
            shell_exec("cd $repodir && git init --shared");
        }
        if (!$want_stderr)
            return shell_exec("cd $repodir && $command");
        else {
            $descriptors = [["file", "/dev/null", "r"], ["pipe", "w"], ["pipe", "w"]];
            $proc = proc_open($command, $descriptors, $pipes, $repodir);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            $status = proc_close($proc);
            return (object) ["stdout" => $stdout, "stderr" => $stderr, "status" => $status];
        }
    }

    function rev_parse($arg) {
        $x = $this->gitrun("git rev-parse --verify " . escapeshellarg($arg), true);
        if ($x->status == 0 && $x->stdout)
            return trim($x->stdout);
        else
            return false;
    }

    private function load_commits_from_head(&$list, $head, $directory) {
        $dirarg = "";
        if ((string) $directory !== "")
            $dirarg = " -- " . escapeshellarg($directory);
        //$limitarg = $limit ? " -n$limit" : "";
        $limitarg = "";
        $result = $this->gitrun("git log$limitarg --simplify-merges --format='%ct %H %s' " . escapeshellarg($head) . $dirarg);
        preg_match_all(',^(\S+)\s+(\S+)\s+(.*)$,m', $result, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            if (!isset($this->_commits[$m[2]]))
                $this->_commits[$m[2]] = new RepositoryCommitInfo((int) $m[1], $m[2], $m[3], $head);
            if (!isset($list[$m[2]]))
                $list[$m[2]] = $this->_commits[$m[2]];
        }
    }

    function commits(Pset $pset = null, $branch = null) {
        $dir = "";
        if ($pset && $pset->directory_noslash !== ""
            && !get($this->_truncated_psetdir, $pset->psetid))
            $dir = $pset->directory_noslash;
        $branch = $branch ? : "master";

        $key = "$dir/$branch";
        if (isset($this->_commit_lists[$key]))
            return $this->_commit_lists[$key];

        // XXX should gitrun once, not multiple times
        $list = [];
        $heads = explode(" ", $this->heads);
        $heads[0] = "%REPO%/$branch";
        foreach ($heads as $h)
            $this->load_commits_from_head($list, $h, $dir);

        if (!$list && $dir !== "" && $pset && isset($pset->test_file)
            && !isset($this->_truncated_psetdir[$pset->psetid])) {
            $this->_truncated_psetdir[$pset->psetid] =
                !!$this->ls_files("%REPO%/$branch", $pset->test_file);
            if ($this->_truncated_psetdir[$pset->psetid])
                return $this->commits(null, $branch);
        }

        $this->_commit_lists[$key] = $list;
        return $list;
    }

    function latest_commit(Pset $pset = null, $branch = null) {
        $c = $this->commits($pset, $branch);
        reset($c);
        return current($c);
    }

    function connected_commit($hash, Pset $pset = null, $branch = null) {
        if (empty($this->_commits))
            // load some commits first
            $this->commits($pset, $branch);

        if (strlen($hash) === 40) {
            if (array_key_exists($hash, $this->_commits))
                return $this->_commits[$hash];
        } else {
            $matches = [];
            foreach ($this->_commits as $h => $cx)
                if (str_starts_with($h, $hash))
                    $matches[] = $cx;
            if (count($matches) == 1)
                return $matches[0];
            else if (!empty($matches))
                return null;
        }

        // not found yet
        // check if all commits are loaded
        if (!isset($this->_commit_lists["/master"])) {
            $this->commits();
            return $this->connected_commit($hash);
        }

        // check snapshots
        return $this->find_snapshot($hash);
    }

    function author_emails($pset = null, $branch = null, $limit = null) {
        $dir = "";
        if (is_object($pset) && $pset->directory_noslash !== "")
            $dir = " -- " . escapeshellarg($pset->directory_noslash);
        else if (is_string($pset) && $pset !== "")
            $dir = " -- " . escapeshellarg($pset);
        $limit = $limit ? " -n$limit" : "";
        $users = [];
        $heads = explode(" ", $this->heads);
        $heads[0] = "%REPO%/" . ($branch ? : "master");
        foreach ($heads as $h) {
            $result = $this->gitrun("git log$limit --simplify-merges --format=%ae " . escapeshellarg($h) . $dir);
            foreach (explode("\n", $result) as $line)
                if ($line !== "")
                    $users[strtolower($line)] = $line;
        }
        return $users;
    }

    function ls_files($tree, $files = []) {
        $suffix = "";
        if (is_string($files))
            $files = array($files);
        foreach ($files as $f)
            $suffix .= " " . escapeshellarg(preg_replace(',/+\z,', '', $f));
        $result = $this->gitrun("git ls-tree -r --name-only " . escapeshellarg($tree) . $suffix);
        $x = explode("\n", $result);
        if (!empty($x) && $x[count($x) - 1] == "")
            array_pop($x);
        return $x;
    }


    function analyze_snapshots() {
        global $ConfSitePATH;
        if ($this->snapat <= $this->analyzedsnapat)
            return;
        $timematch = " ";
        if ($this->analyzedsnapat)
            $timematch = gmstrftime("%Y%m%d.%H%M%S", $this->analyzedsnapat);
        $qv = [];
        $analyzed_snaptime = 0;
        foreach (glob("$ConfSitePATH/repo/repo$this->cacheid/.git/refs/tags/repo{$this->repoid}.snap*") as $snapfile) {
            $time = substr($snapfile, strrpos($snapfile, ".snap") + 5);
            if (strcmp($time, $timematch) <= 0
                || !preg_match('/\A(\d\d\d\d)(\d\d)(\d\d)\.(\d\d)(\d\d)(\d\d)\z/', $time, $m))
                continue;
            $snaptime = gmmktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
            $analyzed_snaptime = max($snaptime, $analyzed_snaptime);
            $head = file_get_contents($snapfile);
            $result = $this->gitrun("git log -n20000 --simplify-merges --format=%H " . escapeshellarg($head));
            foreach (explode("\n", $result) as $line)
                if (strlen($line) == 40
                    && (!isset($qv[$line]) || $qv[$line][2] > $snaptime))
                    $qv[$line] = [$this->repoid, hex2bin($line), $snaptime];
        }
        if (!empty($qv))
            $this->conf->qe("insert into RepositoryCommitSnapshot (repoid, hash, snapshot) values ?v on duplicate key update snapshot=least(snapshot,values(snapshot))", $qv);
        if ($analyzed_snaptime)
            $this->conf->qe("update Repository set analyzedsnapat=greatest(analyzedsnapat,?) where repoid=?", $analyzed_snaptime, $this->repoid);
    }

    function find_snapshot($hash) {
        if (array_key_exists($hash, $this->_commits))
            return $this->_commits[$hash];
        $this->analyze_snapshots();
        $bhash = hex2bin(substr($hash, 0, strlen($hash) & ~1));
        if (strlen($bhash) == 20)
            $result = $this->conf->qe("select * from RepositoryCommitSnapshot where repoid=? and hash=?", $this->repoid, $bhash);
        else
            $result = $this->conf->qe("select * from RepositoryCommitSnapshot where repoid=? and left(hash,?)=?", $this->repoid, strlen($bhash), $bhash);
        $match = null;
        while ($result && ($row = $result->fetch_object())) {
            $h = bin2hex($row->hash);
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
            $this->load_commits_from_head($list, "repo{$this->repoid}.snap" . gmstrftime("%Y%m%d.%H%M%S", $match->snapshot), "");
        }
        if (!array_key_exists($hash, $this->_commits))
            $this->_commits[$hash] = null;
        return $this->_commits[$hash];
    }


    private function _temp_repo_clone() {
        global $Now, $ConfSitePATH;
        assert(isset($this->repoid) && isset($this->cacheid));
        $suffix = "";
        while (1) {
            $d = "$ConfSitePATH/repo/tmprepo.$Now$suffix";
            if (mkdir($d, 0770))
                break;
            $suffix = $suffix ? "_" . substr($suffix, 1) + 1 : "_1";
        }
        $answer = shell_exec("cd $d && git init >/dev/null && git remote add origin $ConfSitePATH/repo/repo{$this->cacheid} >/dev/null && echo yes");
        return ($answer == "yes\n" ? $d : null);
    }

    private function prepare_truncated_hash(Pset $pset, $hash) {
        $pset_files = $this->ls_files($hash, $pset->directory_noslash);
        foreach ($pset_files as &$f)
            $f = escapeshellarg(substr($f, strlen($pset->directory_slash)));
        unset($f);

        if (!($trepo = $this->_temp_repo_clone()))
            return false;
        $psetdir_arg = escapeshellarg($pset->directory_slash);
        $trepo_arg = escapeshellarg($trepo);
        foreach ($pset_files as $f)
            $this->gitrun("mkdir -p \"`dirname {$trepo_arg}/{$f}`\" && git show {$hash}:{$psetdir_arg}{$f} > {$trepo_arg}/{$f}");

        shell_exec("cd $trepo_arg && git add " . join(" ", $pset_files) . " && git commit -m 'Truncated version of $hash'");
        shell_exec("cd $trepo_arg && git push -f origin master:refs/tags/truncated_$hash");
        shell_exec("rm -rf $trepo");
        return $this->rev_parse("truncated_{$hash}");
    }

    function truncated_hash(Pset $pset, $refname) {
        $hash = $refname;
        if (!git_refname_is_full_hash($hash))
            $hash = $this->rev_parse($hash);
        if ($hash === false)
            return false;
        if (array_key_exists($hash, $this->_truncated_hashes))
            return $this->_truncated_hashes[$hash];
        $truncated_hash = $this->rev_parse("truncated_{$hash}");
        if (!$truncated_hash)
            $truncated_hash = $this->prepare_truncated_hash($pset, $hash);
        return ($this->_truncated_hashes[$hash] = $truncated_hash);
    }


    static private function save_repo_diff(&$diff_files, $fname, &$diff, $diffinfo, $blineno) {
        $x = new DiffInfo($fname, $diff, $diffinfo, $blineno);
        $diff_files[$fname] = $x;
    }

    static function repo_diff_compare($a, $b) {
        list($ap, $bp) = array((float) $a->priority, (float) $b->priority);
        if ($ap != $bp)
            return $ap < $bp ? 1 : -1;
        return strcmp($a->filename, $b->filename);
    }

    function diff(Pset $pset, $hasha, $hashb, $options = null) {
        $options = $options ? : array();
        $diff_files = array();
        assert($pset); // code remains for `!$pset`; maybe revive it?

        $psetdir = $pset ? escapeshellarg($pset->directory_noslash) : null;
        if ($pset && $this->truncated_psetdir($pset)) {
            $repodir = "";
            $truncpfx = $pset->directory_noslash . "/";
        } else {
            $repodir = $psetdir . "/"; // Some gits don't do `git show HASH:./FILE`!
            $truncpfx = "";
        }

        if (!$hasha) {
            $hrepo = $pset->handout_repo($this);
            if ($pset && isset($pset->gradebranch))
                $hasha = "repo{$hrepo->repoid}/" . $pset->gradebranch;
            else if ($pset && isset($pset->handout_repo_branch))
                $hasha = "repo{$hrepo->repoid}/" . $pset->handout_repo_branch;
            else
                $hasha = "repo{$hrepo->repoid}/master";
            $options["hasha_hrepo"] = true;
        }
        if ($truncpfx && get($options, "hasha_hrepo") && !get($options, "hashb_hrepo"))
            $hasha = $this->truncated_hash($pset, $hasha);

        $hasha_arg = escapeshellarg($hasha);
        $hashb_arg = escapeshellarg($hashb);

        $ignore_diffinfo = get($options, "hasha_hrepo") && get($options, "hashb_hrepo");
        $no_full = get($options, "no_full");

        // read "full" files
        foreach ($pset->all_diffs() as $diffinfo) {
            if (!$ignore_diffinfo
                && !$no_full
                && $diffinfo->full
                && ($fname = $diffinfo->exact_filename()) !== false) {
                $result = $this->gitrun("git show {$hashb_arg}:{$repodir}" . escapeshellarg($fname));
                $fdiff = array();
                foreach (explode("\n", $result) as $idx => $line)
                    $fdiff[] = array("+", 0, $idx + 1, $line);
                self::save_repo_diff($diff_files, "{$pset->directory_slash}$fname", $fdiff, $diffinfo, count($fdiff) ? 1 : 0);
            }
        }

        $command = "git diff --name-only {$hasha_arg} {$hashb_arg}";
        if ($pset && !$truncpfx)
            $command .= " -- " . escapeshellarg($pset->directory_noslash);
        $result = $this->gitrun($command);

        $files_arg = array();
        foreach (explode("\n", $result) as $line)
            if ($line != "") {
                $diffinfo = $pset->find_diffinfo($truncpfx . $line);
                // skip files presented in their entirety
                if ($diffinfo && !$ignore_diffinfo && get($diffinfo, "full"))
                    continue;
                // skip ignored files, unless user requested them
                if ($diffinfo && !$ignore_diffinfo && get($diffinfo, "ignore")
                    && (!get($options, "needfiles")
                        || !get($options["needfiles"], $truncpfx . $line)))
                    continue;
                $files_arg[] = escapeshellarg(quotemeta($line));
            }

        if (!empty($files_arg)) {
            $command = "git diff";
            if (get($options, "wdiff"))
                $command .= " -w";
            $command .= " {$hasha_arg} {$hashb_arg} -- " . join(" ", $files_arg);
            $result = $this->gitrun($command);
            $file = null;
            $alineno = $blineno = null;
            $fdiff = null;
            $pos = 0;
            $len = strlen($result);
            while (1) {
                if (count($fdiff) > DiffInfo::MAXLINES) {
                    while ($pos < $len
                           && (($ch = $result[$pos]) === " " || $ch === "+" || $ch === "-")) {
                        $nlpos = strpos($result, "\n", $pos);
                        $pos = $nlpos === false ? $len : $nlpos + 1;
                    }
                }
                if ($pos >= $len)
                    break;
                $nlpos = strpos($result, "\n", $pos);
                $line = $nlpos === false ? substr($result, $pos) : substr($result, $pos, $nlpos - $pos);
                $pos = $nlpos === false ? $len : $nlpos + 1;
                if ($line == "")
                    /* do nothing */;
                else if ($line[0] == " " && $file && $alineno) {
                    $fdiff[] = array(" ", $alineno, $blineno, substr($line, 1));
                    ++$alineno;
                    ++$blineno;
                } else if ($line[0] == "-" && $file && $alineno) {
                    $fdiff[] = array("-", $alineno, $blineno, substr($line, 1));
                    ++$alineno;
                } else if ($line[0] == "+" && $file && $blineno) {
                    $fdiff[] = array("+", $alineno, $blineno, substr($line, 1));
                    ++$blineno;
                } else if ($line[0] == "@" && $file && preg_match('_\A@@ -(\d+),\d+ \+(\d+),\d+ @@_', $line, $m)) {
                    $fdiff[] = array("@", null, null, $line);
                    $alineno = +$m[1];
                    $blineno = +$m[2];
                } else if ($line[0] == "d" && preg_match('_\Adiff --git a/(.*) b/\1\z_', $line, $m)) {
                    if ($fdiff)
                        self::save_repo_diff($diff_files, $file, $fdiff, $diffinfo, $blineno);
                    $file = $truncpfx . $m[1];
                    $diffinfo = $pset->find_diffinfo($file);
                    $fdiff = array();
                    $alineno = $blineno = null;
                } else if ($line[0] == "B" && $file && preg_match('_\ABinary files_', $line)) {
                    $fdiff[] = array("@", null, null, $line);
                } else
                    $alineno = $blineno = null;
            }
            if ($fdiff)
                self::save_repo_diff($diff_files, $file, $fdiff, $diffinfo, $blineno);
        }

        uasort($diff_files, "Repository::repo_diff_compare");
        return $diff_files;
    }
}
