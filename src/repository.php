<?php
// repository.php -- Peteramati helper class representing repositories
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
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

    static private $_file_contents = [];

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
        $this->working = (int) $this->working;
        $this->snapcommitat = (int) $this->snapcommitat;
        $this->analyzedsnapat = (int) $this->analyzedsnapat;
        if ($this->notes !== null)
            $this->notes = json_decode($this->notes, true);
        $this->reposite = RepositorySite::make($this->url, $this->conf);
    }

    static function fetch($result, Conf $conf) {
        $repo = $result ? $result->fetch_object("Repository", [$conf]) : null;
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

        $hash = strtolower($hash);
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
            $this->conf->qe("insert into RepositoryCommitSnapshot (repoid, bhash, snapshot) values ?v on duplicate key update snapshot=least(snapshot,values(snapshot))", $qv);
        if ($analyzed_snaptime)
            $this->conf->qe("update Repository set analyzedsnapat=greatest(analyzedsnapat,?) where repoid=?", $analyzed_snaptime, $this->repoid);
    }

    function find_snapshot($hash) {
        if (array_key_exists($hash, $this->_commits))
            return $this->_commits[$hash];
        $this->analyze_snapshots();
        $bhash = hex2bin(substr($hash, 0, strlen($hash) & ~1));
        if (strlen($bhash) == 20)
            $result = $this->conf->qe("select * from RepositoryCommitSnapshot where repoid=? and bhash=?", $this->repoid, $bhash);
        else
            $result = $this->conf->qe("select * from RepositoryCommitSnapshot where repoid=? and left(bhash,?)=?", $this->repoid, strlen($bhash), $bhash);
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


    static private function fix_diff_files($files) {
        if ($files === null || empty($files))
            return null;
        else if (is_associative_array($files))
            return $files;
        else if (is_string($files))
            return [$files => true];
        else {
            $xfiles = [];
            foreach ($files as $f)
                $xfiles[$f] = true;
            return $xfiles;
        }
    }

    private function parse_diff($diffargs, Pset $pset, $hasha_arg, $hashb_arg, $options) {
        $command = "git diff";
        if (get($options, "wdiff"))
            $command .= " -w";
        $command .= " {$hasha_arg} {$hashb_arg} --";
        foreach ($diffargs as $fn => $di)
            $command .= " " . escapeshellarg(quotemeta($fn));
        $result = $this->gitrun($command);
        $alineno = $blineno = null;
        $di = null;
        $pos = 0;
        $len = strlen($result);
        while (1) {
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
            } else if ($line[0] === "@" && $di && preg_match('_\A@@ -(\d+),\d+ \+(\d+)(?:|,\d+) @@_', $line, $m)) {
                $alineno = +$m[1];
                $blineno = +$m[2];
                $di->add("@", null, null, $line);
            } else if ($line[0] === "d" && preg_match('_\Adiff --git a/(.*) b/\1\z_', $line, $m)) {
                if ($di) {
                    $di->finish();
                }
                $di = $diffargs[$m[1]];
                $alineno = $blineno = null;
            } else if ($line[0] === "B" && $di && preg_match('_\ABinary files_', $line)) {
                $di->add("@", null, null, $line);
            } else if ($line[0] === "\\" && strpos($line, "No newline") !== false) {
                $di->ends_without_newline();
            } else {
                $alineno = $blineno = null;
            }
        }
        if ($di)
            $di->finish();
    }

    function diff(Pset $pset, $hasha, $hashb, $options = null) {
        $options = (array) $options;
        assert($pset); // code remains for `!$pset`; maybe revive it?
        $diffs = [];

        $repodir = $truncpfx = "";
        if ($pset && $pset->directory_noslash !== "") {
            $psetdir = escapeshellarg($pset->directory_noslash);
            if ($this->truncated_psetdir($pset))
                $truncpfx = $pset->directory_noslash . "/";
            else
                $repodir = $psetdir . "/";
        } else
            $psetdir = null;

        if (!$hasha) {
            $hrepo = $pset->handout_repo($this);
            if ($pset->handout_hash
                && ($hc = $pset->handout_commits($pset->handout_hash)))
                $hasha = $hc->hash;
            else if (isset($pset->handout_branch))
                $hasha = "repo{$hrepo->repoid}/" . $pset->handout_branch;
            else
                $hasha = "repo{$hrepo->repoid}/master";
            $options["hasha_hrepo"] = true;
        }
        if ($truncpfx && get($options, "hasha_hrepo") && !get($options, "hashb_hrepo"))
            $hasha = $this->truncated_hash($pset, $hasha);

        $hasha_arg = escapeshellarg($hasha);
        $hashb_arg = escapeshellarg($hashb);

        $hasha_hrepo = get($options, "hasha_hrepo");
        $ignore_diffconfig = $hasha_hrepo && get($options, "hashb_hrepo");
        $no_full = get($options, "no_full");
        $needfiles = self::fix_diff_files(get($options, "needfiles"));
        $onlyfiles = self::fix_diff_files(get($options, "onlyfiles"));

        // read "full" files
        foreach ($pset->all_diffconfig() as $diffconfig) {
            if (!$ignore_diffconfig
                && !$no_full
                && $diffconfig->full
                && ($fname = $diffconfig->exact_filename()) !== false
                && (!$onlyfiles || get($onlyfiles, $pset->directory_slash . $fname))) {
                $result = $this->gitrun("git show {$hashb_arg}:{$repodir}" . escapeshellarg($fname));
                $di = new DiffInfo("{$pset->directory_slash}{$fname}", $diffconfig);
                $diffs[$di->filename] = $di;
                foreach (explode("\n", $result) as $idx => $line) {
                    $di->add("+", 0, $idx + 1, $line);
                }
                $di->finish();
            }
        }

        $command = "git diff --name-only {$hasha_arg} {$hashb_arg}";
        if ($pset && !$truncpfx && $pset->directory_noslash)
            $command .= " -- " . escapeshellarg($pset->directory_noslash);
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
                    && get($diffs, $file))
                    continue;
                // skip files that aren't allowed
                if ($onlyfiles
                    && !get($onlyfiles, $truncpfx . $line))
                    continue;
                // skip ignored files, unless user requested them
                $di = new DiffInfo($file, $diffconfig);
                $di->set_repoa($this, $pset, $hasha, $line, $hasha_hrepo);
                if ($diffconfig
                    && !$ignore_diffconfig
                    && ($diffconfig->ignore || $diffconfig->boring)
                    && (!$needfiles || !get($needfiles, $file))) {
                    if (!$diffconfig->ignore) {
                        $di->finish_unloaded();
                        $diffs[$file] = $di;
                    }
                } else
                    $xdiffs[] = $di;
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
                    $this->parse_diff($darg, $pset, $hasha_arg, $hashb_arg, $options);
                    $darg = [];
                }
            }
            foreach ($xdiffs as $di)
                if (!$di->is_empty())
                    $diffs[$di->filename] = $di;
        }

        // ensure a diff for every landmarked file, even if empty
        if ($pset->has_grade_landmark) {
            foreach ($pset->grades() as $g) {
                $file = $g->landmark_file;
                if ($file && !isset($diffs[$file])) {
                    $diffs[$file] = $di = new DiffInfo($file, $pset->find_diffconfig($file));
                    $di->set_repoa($this, $pset, $hasha, substr($file, strlen($truncpfx)), $hasha_hrepo);
                    $di->finish();
                }
            }
        }

        uasort($diffs, "DiffInfo::compare");
        return $diffs;
    }


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
}
