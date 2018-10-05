<?php
// psetconfig.php -- Peteramati configuration classes
// HotCRP and Peteramati are Copyright (c) 2006-2018 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class PsetConfigException extends Exception {
    public $path;

    function __construct($msg, $path) {
        $this->path = array();
        foreach (func_get_args() as $i => $x)
            if ($i && is_array($x))
                $this->path = array_merge($this->path, $x);
            else if ($i && $x !== false && $x !== null)
                $this->path[] = $x;
        parent::__construct($msg);
    }
}

class Pset {
    public $conf;
    public $id;
    public $psetid;
    public $psetkey;
    public $urlkey;

    public $title;
    public $group;

    public $disabled;
    public $ui_disabled;
    public $visible;
    public $frozen;
    public $partner;
    public $no_branch;
    public $anonymous;
    public $gitless;
    public $gitless_grades;
    public $partner_repo;
    public $hide_comments = false;

    public $handout_repo_url;
    public $handout_repo_branch;
    public $handout_hash;
    public $handout_warn_hash;
    public $repo_guess_patterns = array();
    public $repo_tarball_patterns = array();
    public $directory;
    public $directory_slash;
    public $directory_noslash;
    public $test_file;

    public $deadline;
    public $deadline_college;
    public $deadline_extension;

    public $has_grade_landmark = false;
    public $all_grades = array();
    public $grades;
    public $grades_visible;
    public $grades_visible_college;
    public $grades_visible_extension;
    public $grade_cdf_visible;
    public $grade_cdf_cutoff;
    public $separate_extension_grades;
    public $has_extra = false;
    public $max_total;
    private $_late_hours;

    public $all_runners = array();
    public $runners;
    public $run_username;
    public $run_dirpattern;
    public $run_overlay;
    public $run_skeletondir;
    public $run_jailfiles;
    public $run_binddir;
    public $run_timeout;
    public $has_transfer_warnings;
    public $has_xterm_js;

    public $diffs = [];
    public $ignore;
    private $_file_ignore_regex;
    private $_extra_diffs;
    private $_all_diffs;
    private $_file_diffinfo = [];

    const URLKEY_REGEX = '/\A[0-9A-Za-z][-0-9A-Za-z_.]*\z/';

    function __construct(Conf $conf, $pk, $p) {
        $this->conf = $conf;

        // pset id
        if (!isset($p->psetid) || !is_int($p->psetid) || $p->psetid <= 0)
            throw new PsetConfigException("`psetid` must be positive integer", "psetid");
        $this->id = $this->psetid = $p->psetid;

        // pset key
        if (ctype_digit($pk) && intval($pk) !== $p->psetid)
            throw new PsetConfigException("numeric pset key disagrees with `psetid`");
        else if (!preg_match(',\A[^_./&;#][^/&;#]*\z,', $pk))
            throw new PsetConfigException("pset key format error");
        $this->psetkey = $pk;

        // url keys
        $urlkey = get($p, "urlkey");
        if (is_string($urlkey) && preg_match(self::URLKEY_REGEX, $urlkey))
            $this->urlkey = $urlkey;
        else if (is_int($urlkey))
            $this->urlkey = (string) $urlkey;
        else if ($urlkey)
            throw new PsetConfigException("`urlkey` format error", "urlkey");
        else {
            $this->urlkey = (string) $this->psetid;
            if (preg_match(self::URLKEY_REGEX, $this->psetkey)
                && $this->psetkey !== "pset" . $this->psetid)
                $this->urlkey = $this->psetkey;
        }

        $this->title = self::cstr($p, "title");
        if ((string) $this->title === "")
            $this->title = $this->psetkey;
        $this->group = self::cstr($p, "group");

        $this->disabled = self::cbool($p, "disabled");
        if (($this->ui_disabled = self::cbool($p, "ui_disabled")))
            $this->disabled = true;
        $this->visible = self::cdate($p, "visible", "show_to_students");
        $this->frozen = self::cdate($p, "frozen", "freeze");
        $this->partner = self::cbool($p, "partner");
        $this->no_branch = self::cbool($p, "no_branch");
        $this->anonymous = self::cbool($p, "anonymous");
        $this->gitless = self::cbool($p, "gitless");
        $this->gitless_grades = self::cbool($p, "gitless_grades");
        if ($this->gitless_grades === null)
            $this->gitless_grades = $this->gitless;
        if (!$this->gitless_grades && $this->gitless)
            throw new PsetConfigException("`gitless` requires `gitless_grades`", "gitless_grades");
        $this->partner_repo = self::cstr($p, "partner_repo");
        if ((string) $this->partner_repo === "" || $this->partner_repo === "same")
            $this->partner_repo = null;
        else if ($this->partner_repo !== "different")
            throw new PsetConfigException("`partner_repo` should be \"same\" or \"different\"");
        $this->hide_comments = self::cbool($p, "hide_comments");

        // directory
        $this->handout_repo_url = self::cstr($p, "handout_repo_url");
        if (!$this->handout_repo_url && !$this->gitless)
            throw new PsetConfigException("`handout_repo_url` missing");
        $this->handout_repo_branch = self::cstr($p, "handout_repo_branch");
        $this->handout_hash = self::cstr($p, "handout_hash", "handout_commit_hash");
        $this->handout_warn_hash = self::cstr($p, "handout_warn_hash");
        $this->repo_guess_patterns = self::cstr_array($p, "repo_guess_patterns", "repo_transform_patterns");
        $this->repo_tarball_patterns = self::cstr_array($p, "repo_tarball_patterns");
        if (!property_exists($p, "repo_tarball_patterns"))
            $this->repo_tarball_patterns = array("^git@(.*?):(.*)\.git$",
                "https://\$1/\$2/archive-tarball/\${HASH}");
        $this->directory = $this->directory_slash = "";
        if (isset($p->directory) && is_string($p->directory))
            $this->directory = $p->directory;
        else if (isset($p->directory) && $p->directory !== false)
            throw new PsetConfigException("`directory` format error", "directory");
        $this->directory_slash = preg_replace(',([^/])/*\z,', '$1/', $this->directory);
        while (str_starts_with($this->directory_slash, "./"))
            $this->directory_slash = substr($this->directory_slash, 2);
        if (str_starts_with($this->directory_slash, "/"))
            $this->directory_slash = substr($this->directory_slash, 1);
        $this->directory_noslash = preg_replace(',/+\z,', '', $this->directory_slash);
        $this->test_file = self::cstr($p, "test_file");

        // deadlines
        $this->deadline = self::cdate($p, "deadline");
        $this->deadline_college = self::cdate($p, "deadline_college", "college_deadline");
        $this->deadline_extension = self::cdate($p, "deadline_extension", "college_extension");
        if (!$this->deadline) {
            $this->deadline = $this->deadline_college ? : $this->deadline_extension;
        }

        // grades
        $grades = get($p, "grades");
        if (is_array($grades) || is_object($grades)) {
            foreach ((array) $p->grades as $k => $v) {
                $g = new GradeEntryConfig(is_int($k) ? $k + 1 : $k, $v);
                if (get($this->all_grades, $g->key))
                    throw new PsetConfigException("grade `$g->key` reused", "grades", $k);
                $this->all_grades[$g->key] = $g;
                if ($g->landmark_file || $g->landmark_range_file)
                    $this->has_grade_landmark = true;
            }
        } else if ($grades)
            throw new PsetConfigException("`grades` format error`", "grades");
        if (get($p, "grade_order"))
            $this->grades = self::reorder_config("grade_order", $this->all_grades, $p->grade_order);
        else
            $this->grades = self::position_sort("grades", $this->all_grades);
        foreach ($this->grades as $g)
            if ($g->is_extra)
                $this->has_extra = true;
        $this->grades_visible = self::cdate($p, "grades_visible", "show_grades_to_students");
        $this->grades_visible_college = self::cdate($p, "grades_visible_college", "show_grades_to_college");
        if ($this->grades_visible_college === null)
            $this->grades_visible_college = $this->grades_visible;
        $this->grades_visible_extension = self::cdate($p, "grades_visible_extension", "show_grades_to_extension");
        if ($this->grades_visible_extension === null)
            $this->grades_visible_extension = $this->grades_visible;
        if (isset($p->grade_cdf_visible) && $p->grade_cdf_visible === "grades")
            $this->grade_cdf_visible = "grades";
        else
            $this->grade_cdf_visible = self::cdate($p, "grade_cdf_visible", "show_grade_cdf_to_students");
        $this->grade_cdf_cutoff = self::cnum($p, "grade_cdf_cutoff");
        $this->separate_extension_grades = self::cbool($p, "separate_extension_grades");

        if (($this->deadline || $this->deadline_college || $this->deadline_extension)
            && !self::cbool($p, "no_late_hours"))
            $this->_late_hours = GradeEntryConfig::make_late_hours();

        // runners
        $runners = get($p, "runners");
        $this->has_transfer_warnings = $this->has_xterm_js = false;
        if (is_array($runners) || is_object($runners)) {
            foreach ((array) $p->runners as $k => $v) {
                $r = new RunnerConfig(is_int($k) ? $k + 1 : $k, $v);
                if (get($this->all_runners, $r->name))
                    throw new PsetConfigException("runner `$r->name` reused", "runners", $k);
                $this->all_runners[$r->name] = $r;
                if ($r->transfer_warnings)
                    $this->has_transfer_warnings = true;
                if ($r->xterm_js)
                    $this->has_xterm_js = true;
            }
        } else if ($runners)
            throw new PsetConfigException("`runners` format error", "runners");
        if (get($p, "runner_order"))
            $this->runners = self::reorder_config("runner_order", $this->all_runners, $p->runner_order);
        else
            $this->runners = self::position_sort("runners", $this->all_runners);
        $this->run_username = self::cstr($p, "run_username");
        $this->run_dirpattern = self::cstr($p, "run_dirpattern");
        $this->run_overlay = self::cstr($p, "run_overlay");
        $this->run_skeletondir = self::cstr($p, "run_skeletondir");
        $this->run_jailfiles = self::cstr($p, "run_jailfiles");
        $this->run_timeout = self::cinterval($p, "run_timeout");
        if ($this->run_timeout === null) // default run_timeout is 10m
            $this->run_timeout = 600;
        $this->run_binddir = self::cstr($p, "run_binddir");

        // diffs
        $diffs = get($p, "diffs");
        if (is_array($diffs) || is_object($diffs)) {
            foreach (self::make_config_array($p->diffs) as $k => $v)
                $this->diffs[] = new DiffConfig($k, $v);
        } else if ($diffs)
            throw new PsetConfigException("`diffs` format error", "diffs");
        $ignore = get($p, "ignore");
        if (is_array($ignore))
            $this->ignore = self::cstr_array($p, "ignore");
        else if ($ignore)
            $this->ignore = self::cstr($p, "ignore");
    }

    static function compare(Pset $a, Pset $b) {
        $adl = $a->deadline_college ? : $a->deadline;
        $bdl = $b->deadline_college ? : $b->deadline;
        if (!$adl != !$bdl)
            return $adl ? -1 : 1;
        else if ($adl != $bdl)
            return $adl < $bdl ? -1 : 1;
        else if (($cmp = strcasecmp($a->title, $b->title)))
            return $cmp;
        else
            return $a->id < $b->id ? -1 : 1;
    }


    function students($anonymous = null) {
        if ($anonymous === null)
            $anonymous = $this->anonymous;

        if ($this->conf->opt("restrictRepoView")) {
            $view = "exists (select * from ContactLink where cid=c.contactId and type=" . LINK_REPOVIEW . " and link=l.link)";
        } else {
            $view = "1";
        }
        $q = "select c.contactId, c.firstName, c.lastName, c.email,
    c.huid, c.github_username, c.seascode_username, c.anon_username, c.extension, c.disabled, c.dropped, c.roles, c.contactTags,
    group_concat(pl.link) pcid, group_concat(rpl.link) rpcid,
    r.repoid, r.cacheid, r.heads, r.url, r.open, r.working, r.lastpset, r.snapcheckat, $view repoviewable,
    rg.gradebhash, rg.gradercid, rg.placeholder, rg.placeholder_at
    from ContactInfo c
    left join ContactLink l on (l.cid=c.contactId and l.type=" . LINK_REPO . " and l.pset={$this->id})
    left join Repository r on (r.repoid=l.link)
    left join ContactLink pl on (pl.cid=c.contactId and pl.type=" . LINK_PARTNER . " and pl.pset={$this->id})
    left join ContactLink rpl on (rpl.cid=c.contactId and rpl.type=" . LINK_BACKPARTNER . " and rpl.pset={$this->id})
    left join RepositoryGrade rg on (rg.repoid=r.repoid and rg.branchid=0 and rg.pset={$this->id})
    where (c.roles&" . Contact::ROLE_PCLIKE . ")=0
    and (rg.repoid is not null or not c.dropped)
    group by c.contactId, l.link";

        $result = $this->conf->qe_raw($q);
        $students = array();
        while ($result && ($s = Contact::fetch($result))) {
            $s->set_anonymous($anonymous);
            $students[$s->contactId] = $s;
        }
        uasort($students, "Contact::compare");
        Dbl::free($result);
        return $students;
    }


    function student_can_view() {
        global $Now;
        $dl = $this->visible;
        return !$this->disabled && $dl && ($dl === true || $dl <= $Now);
    }

    function student_can_view_grades($extension = null) {
        global $Now;
        if ($extension === null)
            $dl = $this->grades_visible;
        else if ($extension)
            $dl = $this->grades_visible_extension;
        else
            $dl = $this->grades_visible_college;
        return $this->student_can_view() && $dl && ($dl === true || $dl <= $Now);
    }


    function handout_repo(Repository $inrepo = null) {
        return $this->conf->handout_repo($this, $inrepo);
    }

    function handout_commits($hash = null) {
        return $this->conf->handout_commits($this, $hash);
    }

    function handout_commits_from($hash) {
        return $this->conf->handout_commits_from($this, $hash);
    }

    function latest_handout_commit() {
        return $this->conf->latest_handout_commit($this);
    }

    function grades() {
        return $this->grades;
    }

    function numeric_grades() {
        return array_filter($this->grades, function ($ge) {
            return $ge->type === null;
        });
    }

    function visible_grades($pcview) {
        return $pcview ? $this->grades : array_filter($this->grades, function ($ge) {
            return $ge->visible;
        });
    }

    function visible_grades_in_total($pcview) {
        return array_filter($this->grades, function ($ge) use ($pcview) {
            return ($pcview || $ge->visible) && !$ge->no_total;
        });
    }

    function late_hours_entry() {
        return $this->_late_hours;
    }

    function gradeinfo_json($pcview) {
        $max = [];
        $count = $maxtotal = 0;
        foreach ($this->visible_grades($pcview) as $ge) {
            ++$count;
            if ($ge->max && ($pcview || $ge->max_visible)) {
                $max[$ge->key] = $ge->max;
                if (!$ge->is_extra && !$ge->no_total)
                    $maxtotal += $ge->max;
            }
        }
        if ($maxtotal)
            $max["total"] = $maxtotal;
        return (object) ["nentries" => $count, "maxgrades" => (object) $max];
    }

    function gradeentry_json($pcview) {
        $ej = $order = [];
        $count = $maxtotal = 0;
        foreach ($this->visible_grades($pcview) as $ge) {
            $gej = ["title" => $ge->title, "pos" => $count];
            if ($ge->type !== null)
                $gej["type"] = $ge->type;
            if ($ge->max && ($pcview || $ge->max_visible)) {
                $gej["max"] = $ge->max;
                if (!$ge->is_extra && !$ge->no_total)
                    $maxtotal += $ge->max;
            }
            if (!$ge->no_total)
                $gej["in_total"] = true;
            if ($ge->is_extra)
                $gej["is_extra"] = true;
            if ($ge->landmark_file)
                $gej["landmark"] = $ge->landmark_file . ":" . $ge->landmark_line;
            $ej[$ge->key] = $gej;
            $order[] = $ge->key;
            ++$count;
        }
        $j = ["entries" => $ej, "order" => $order];
        if ($maxtotal)
            $j["maxtotal"] = $maxtotal;
        return $j;
    }


    function contact_grade_for($student) {
        assert(!!$this->gitless_grades);
        $cid = is_object($student) ? $student->contactId : $sstudent;
        $result = $this->conf->qe("select * from ContactGrade where cid=? and pset=?", $cid, $this->psetid);
        $cg = edb_orow($result);
        if ($cg && $cg->notes)
            $cg->notes = json_decode($cg->notes);
        Dbl::free($result);
        return $cg;
    }

    function commit_notes($bhash) {
        assert(!$this->gitless);
        $result = $this->conf->qe("select * from CommitNotes where pset=? and bhash=?", $this->psetid, strlen($bhash) === 40 ? hex2bin($bhash) : $bhash);
        $cn = edb_orow($result);
        if ($cn && $cn->notes)
            $cn->notes = json_decode($cn->notes);
        Dbl::free($result);
        return $cn;
    }


    static function basic_file_ignore_regex() {
        return '.*\.swp|.*~|#.*#|.*\.core|.*\.dSYM|.*\.o|core.*\z|.*\.backup|tags|tags\..*|typescript';
    }

    static private function _file_glob_to_regex($x, $prefix) {
        $x = str_replace(array('\*', '\?', '\[', '\]', '\-', '_'),
                         array('[^/]*', '[^/]', '[', ']', '-', '\_'),
                         preg_quote($x));
        if ($x === "")
            return "";
        else if (strpos($x, "/") === false) {
            if ($prefix)
                return '|\A' . preg_quote($prefix) . '/' . $x;
            else
                return '|' . $x;
        } else {
            if ($prefix)
                return '|\A' . preg_quote($prefix) . '/' . $x . '\z';
            else
                return '|\A' . $x . '\z';
        }
    }

    function file_ignore_regex() {
        global $Now;
        if (isset($this->_file_ignore_regex))
            return $this->_file_ignore_regex;
        $regex = self::basic_file_ignore_regex();
        if ($this->conf->setting("__gitignore_pset{$this->id}_at", 0) < $Now - 900) {
            $hrepo = $this->handout_repo();
            $result = "";
            if ($this->directory_slash !== "")
                $result .= $hrepo->gitrun("git show repo{$hrepo->repoid}/master:" . escapeshellarg($this->directory_slash) . ".gitignore 2>/dev/null");
            $result .= $hrepo->gitrun("git show repo{$hrepo->repoid}/master:.gitignore 2>/dev/null");
            $this->conf->save_setting("__gitignore_pset{$this->id}_at", $Now);
            $this->conf->save_setting("gitignore_pset{$this->id}", 1, $result);
        }
        if (($result = $this->conf->setting_data("gitignore_pset{$this->id}"))) {
            foreach (preg_split('/\s+/', $result) as $x)
                $regex .= self::_file_glob_to_regex($x, $this->directory_noslash);
        }
        if (($xarr = $this->ignore)) {
            if (!is_array($xarr))
                $xarr = preg_split('/\s+/', $xarr);
            foreach ($xarr as $x)
                $regex .= self::_file_glob_to_regex($x, false);
        }
        return ($this->_file_ignore_regex = $regex);
    }


    function all_diffconfig() {
        if ($this->_all_diffs === null) {
            $this->_all_diffs = $this->diffs;
            if (($regex = $this->file_ignore_regex()))
                $this->_all_diffs[] = new DiffConfig($regex, (object) array("ignore" => true, "match_priority" => -10));
            foreach ((array) $this->_extra_diffs as $d)
                $this->_all_diffs[] = $d;
        }
        return $this->_all_diffs;
    }

    function find_diffconfig($filename) {
        if (array_key_exists($filename, $this->_file_diffinfo))
            return $this->_file_diffinfo[$filename];
        $diffinfo = null;
        foreach ($this->all_diffconfig() as $d) {
            if (preg_match('{(?:\A|/)(?:' . $d->regex . ')(?:/|\z)}', $filename))
                $diffinfo = DiffConfig::combine($diffinfo, $d);
        }
        return ($this->_file_diffinfo[$filename] = $diffinfo);
    }

    function maybe_prefix_directory($files) {
        if (is_string($files)) {
            $files = $this->maybe_prefix_directory([$files]);
            return $files[0];
        } else if (!$this->directory_slash) {
            return $files;
        } else {
            $pfiles = [];
            foreach ($files as $f) {
                if (str_starts_with($f, $this->directory_slash)
                    || str_starts_with($f, "./")
                    || str_starts_with($f, "../"))
                    return $files;
                else
                    $pfiles[] = $this->directory_slash . $f;
            }
            return $pfiles;
        }
    }

    function add_diffconfig(DiffConfig $dc) {
        $this->_all_diffs = null;
        $this->_file_diffinfo = [];
        $this->_extra_diffs[] = $dc;
    }


    private static function ccheck($callable, $args) {
        $i = 0;
        $format = false;
        if (!is_object($args[$i])) {
            $format = $args[$i];
            ++$i;
        }
        $p = $args[$i];
        for (++$i; $i < count($args); ++$i) {
            $x = $args[$i];
            if (isset($p->$x)) {
                $v = call_user_func($callable, $p->$x);
                if (is_array($v) && $v[0])
                    return $v[1];
                else if (!is_array($v) && $v)
                    return $p->$x;
                else {
                    $errormsg = is_array($v) ? $v[1] : "format error";
                    throw new PsetConfigException("`$x` $errormsg", $format, $x);
                }
            }
        }
        return null;
    }

    static function cbool(/* ... */) {
        return self::ccheck("is_bool", func_get_args());
    }

    static function cint(/* ... */) {
        return self::ccheck("is_int", func_get_args());
    }

    static function cnum(/* ... */) {
        return self::ccheck("is_number", func_get_args());
    }

    static function cstr(/* ... */) {
        return self::ccheck("is_string", func_get_args());
    }

    static function cstr_array(/* ... */) {
        return self::ccheck("is_string_array", func_get_args());
    }

    static function cdate(/* ... */) {
        return self::ccheck("check_date", func_get_args());
    }

    static function cdate_or_grades(/* ... */) {
        return self::ccheck("check_date_or_grades", func_get_args());
    }

    static function cinterval(/* ... */) {
        return self::ccheck("check_interval", func_get_args());
    }

    private static function make_config_array($x) {
        if (is_array($x)) {
            $y = array();
            foreach ($x as $i => $v)
                $y[$i + 1] = $v;
            return $y;
        } else
            return get_object_vars($x);
    }

    private static function reorder_config($what, $a, $order) {
        if (!is_array($order))
            throw new PsetConfigException("`$what` format error", $what);
        $b = array();
        foreach ($order as $name)
            if (is_string($name)) {
                if (isset($a[$name]) && !isset($b[$name]))
                    $b[$name] = $a[$name];
                else if (isset($a[$name]))
                    throw new PsetConfigException("`$what` entry `$name` reused", $what);
                else
                    throw new PsetConfigException("`$what` entry `$name` unknown", $what);
            } else
                throw new PsetConfigException("`$what` format error", $what);
        return $b;
    }

    private static function position_sort($what, $a) {
        $i = 0;
        $b = array();
        foreach ($a as $k => $v) {
            $b[$k] = array($v->position, $i);
            ++$i;
        }
        uasort($b, function ($a, $b) {
                if ($a[0] != $b[0])
                    return $a[0] < $b[0] ? -1 : 1;
                else if ($a[1] != $b[1])
                    return $a[1] < $b[1] ? -1 : 1;
                else
                    return 0;
            });
        foreach ($b as $k => &$v)
            $v = $a[$k];
        return $b;
    }
}


class GradeEntryConfig {
    public $key;
    public $name;
    public $title;
    public $type;
    public $max;
    public $visible;
    private $_visible_defaulted = false;
    public $max_visible;
    private $_max_visible_defaulted = false;
    public $no_total;
    public $is_extra;
    public $position;
    public $landmark_file;
    public $landmark_line;
    public $landmark_range_file;
    public $landmark_range_first;
    public $landmark_range_last;

    function __construct($name, $g) {
        $loc = array("grades", $name);
        if (!is_object($g))
            throw new PsetConfigException("grade entry format error", $loc);
        $this->key = $name;
        if (isset($g->key))
            $this->key = $g->key;
        else if (isset($g->name))
            $this->key = $g->name;
        if (!is_string($this->key)
            || !preg_match('/\A[-@~:\$A-Za-z0-9_]+\z/', $this->key)
            || $this->key[0] === "_"
            || $this->key === "total"
            || $this->key === "late_hours"
            || $this->key === "auto_late_hours")
            throw new PsetConfigException("grade entry key format error", $loc);
        $this->name = $this->key;
        $this->title = Pset::cstr($loc, $g, "title");
        if ((string) $this->title === "")
            $this->title = $this->key;

        $type = null;
        if (isset($g->type)) {
            $type = Pset::cstr($loc, $g, "type");
            if ($type !== "number" && $type !== "text")
                throw new PsetConfigException("unknown grade entry type", $loc);
            if ($type === "number")
                $type = null;
        }
        $this->type = $type;

        if ($this->type === "text") {
            $this->no_total = true;
        } else {
            $this->max = Pset::cnum($loc, $g, "max");
            if (isset($g->visible))
                $this->visible = Pset::cbool($loc, $g, "visible");
            else if (isset($g->hide))
                $this->visible = !Pset::cbool($loc, $g, "hide");
            else
                $this->visible = $this->_visible_defaulted = true;
            if (isset($g->max_visible))
                $this->max_visible = Pset::cbool($loc, $g, "max_visible");
            else if (isset($g->hide_max))
                $this->max_visible = !Pset::cbool($loc, $g, "hide_max");
            else
                $this->max_visible = $this->_max_visible_defaulted = true;
            $this->no_total = Pset::cbool($loc, $g, "no_total");
            $this->is_extra = Pset::cbool($loc, $g, "is_extra");
        }

        $this->position = Pset::cnum($loc, $g, "position");
        if ($this->position === null && isset($g->priority))
            $this->position = -Pset::cnum($loc, $g, "priority");
        if (isset($g->landmark)) {
            if (is_string($g->landmark)
                && preg_match('/\A(.*):(\d+)\z/', $g->landmark, $m)) {
                $this->landmark_file = $m[1];
                $this->landmark_line = intval($m[2]);
            } else if (is_array($g->landmark)
                       && count($g->landmark) === 2
                       && is_string($g->landmark[0])
                       && is_int($g->landmark[1])) {
                $this->landmark_file = $g->landmark[0];
                $this->landmark_line = $g->landmark[1];
            } else
                throw new PsetConfigException("grade entry `landmark` format error", $loc);
        }
        if (isset($g->landmark_range)) {
            if (is_string($g->landmark_range)
                && preg_match('/\A(.*):(\d+):(\d+)\z/', $g->landmark, $m)) {
                $this->landmark_range_file = $m[1];
                $this->landmark_range_first = intval($m[2]);
                $this->landmark_range_last = intval($m[3]);
            } else if (is_array($g->landmark_range)
                       && count($g->landmark_range) === 3
                       && is_string($g->landmark_range[0])
                       && is_int($g->landmark_range[1])
                       && is_int($g->landmark_range[2])) {
                $this->landmark_range_file = $g->landmark_range[0];
                $this->landmark_range_first = $g->landmark_range[1];
                $this->landmark_range_last = $g->landmark_range[2];
            }
            if ($this->landmark_range_file === null
                || $this->landmark_range_first > $this->landmark_range_last)
                throw new PsetConfigException("grade entry `landmark_range` format error", $loc);
        }
    }

    static function make_late_hours() {
        $ge = new GradeEntryConfig("x_late_hours", (object) ["no_total" => true, "position" => PHP_INT_MAX, "title" => "late hours"]);
        $ge->key = "late_hours";
        return $ge;
    }


    function parse_value($v) {
        if ($v === null || is_int($v) || is_float($v)) {
            return $v;
        } else if (is_string($v)) {
            if ($this->type === "text") {
                return rtrim($v);
            }
            $v = trim($v);
            if ($v === "") {
                return null;
            } else if (preg_match('_\A\+?\d+\z_', $v)) {
                return intval($v);
            } else if (preg_match('_\A\+?(?:\d+\.|\.\d)\d*\z_', $v)) {
                return floatval($v);
            }
        }
        return false;
    }

    function value_differs($v1, $v2) {
        if ($v1 === null || $v2 === null || $this->type === "text")
            return $v1 !== $v2;
        else
            return abs($v1 - $v2) >= 0.0001;
    }
}

class RunnerConfig {
    public $name;
    public $category;
    public $title;
    public $output_title;
    public $disabled;
    public $visible;
    public $output_visible;
    public $position;
    public $command;
    public $username;
    public $overlay;
    public $timeout;
    public $queue;
    public $nconcurrent;
    public $xterm_js;
    public $transfer_warnings;
    public $transfer_warnings_priority;
    public $require;
    public $eval;
    public $timed_replay;

    function __construct($name, $r) {
        $loc = array("runners", $name);
        if (!is_object($r))
            throw new PsetConfigException("runner format error", $loc);
        $this->name = isset($r->name) ? $r->name : $name;
        if (!is_string($this->name) || !preg_match(',\A[A-Za-z][0-9A-Za-z_]*\z,', $this->name))
            throw new PsetConfigException("runner name format error", $loc);
        $this->category = isset($r->category) ? $r->category : $this->name;
        if (!is_string($this->category) || !preg_match(',\A[0-9A-Za-z_]+\z,', $this->category))
            throw new PsetConfigException("runner category format error", $loc);
        $this->title = Pset::cstr($loc, $r, "title", "text");
        if ($this->title === null)
            $this->title = $this->name;
        $this->output_title = Pset::cstr($loc, $r, "output_title", "output_text");
        if ($this->output_title === null)
            $this->output_title = $this->title . " output";
        $this->disabled = Pset::cbool($loc, $r, "disabled");
        $this->visible = Pset::cbool($loc, $r, "visible", "show_to_students");
        $this->output_visible = Pset::cdate_or_grades($loc, $r, "output_visible", "show_output_to_students", "show_results_to_students");
        $this->timeout = Pset::cinterval($loc, $r, "timeout", "run_timeout");
        $this->xterm_js = Pset::cbool($loc, $r, "xterm_js");
        if (isset($r->transfer_warnings) && $r->transfer_warnings === "grades")
            $this->transfer_warnings = "grades";
        else
            $this->transfer_warnings = Pset::cbool($loc, $r, "transfer_warnings");
        $this->transfer_warnings_priority = Pset::cnum($loc, $r, "transfer_warnings_priority");
        $this->command = Pset::cstr($loc, $r, "command");
        $this->username = Pset::cstr($loc, $r, "username", "run_username");
        $this->require = Pset::cstr($loc, $r, "require", "load");
        $this->eval = Pset::cstr($loc, $r, "eval");
        $this->queue = Pset::cstr($loc, $r, "queue");
        $this->nconcurrent = Pset::cint($loc, $r, "nconcurrent");
        $this->position = Pset::cnum($loc, $r, "position");
        if ($this->position === null && isset($r->priority))
            $this->position = -Pset::cnum($loc, $r, "priority");
        $this->overlay = Pset::cstr($loc, $r, "overlay");
        $this->timed_replay = Pset::cbool($loc, $r, "timed_replay");
        if ($this->timed_replay === null)
            $this->timed_replay = false;
    }
    function category_argument() {
        return $this->category === $this->name ? null : $this->category;
    }
}

class DiffConfig {
    public $regex;
    public $match_priority;
    public $title;
    public $position;
    public $fileless;
    public $full;
    public $ignore;
    public $boring;
    public $gradable;
    public $hide_if_anonymous;

    function __construct($regex, $d) {
        $loc = array("diffs", $regex);
        if (!is_object($d))
            throw new PsetConfigException("diff format error", $loc);
        $this->regex = isset($d->regex) ? $d->regex : $regex;
        if (!is_string($this->regex) || $this->regex === "")
            throw new PsetConfigException("`regex` diff format error", $loc);
        $this->title = Pset::cstr($loc, $d, "title");
        $this->match_priority = (float) Pset::cint($loc, $d, "match_priority");
        $this->position = Pset::cnum($loc, $d, "position");
        if ($this->position === null && isset($d->priority))
            $this->position = -Pset::cnum($loc, $d, "priority");
        $this->fileless = Pset::cbool($loc, $d, "fileless");
        $this->full = Pset::cbool($loc, $d, "full");
        $this->ignore = Pset::cbool($loc, $d, "ignore");
        $this->boring = Pset::cbool($loc, $d, "boring");
        $this->gradable = Pset::cbool($loc, $d, "gradable", "gradeable");
        $this->hide_if_anonymous = Pset::cbool($loc, $d, "hide_if_anonymous");
    }

    static function combine(DiffConfig $a = null, DiffConfig $b = null) {
        if (!$a && !$b)
            return false;
        if (!$a || !$b)
            return $a ? : $b;
        if ($a->match_priority > $b->match_priority) {
            $x = clone $a;
            $y = $b;
        } else {
            $x = clone $b;
            $y = $a;
        }
        if ($x->title === null)
            $x->title = $y->title;
        if ($x->position === null)
            $x->position = $y->position;
        if ($x->fileless === null)
            $x->fileless = $y->fileless;
        if ($x->full === null)
            $x->full = $y->full;
        if ($x->ignore === null)
            $x->ignore = $y->ignore;
        if ($x->boring === null)
            $x->boring = $y->boring;
        return $x;
    }


    function exact_filename() {
        $unquoted = preg_replace(",\\\\(.),", '$1', $this->regex);
        if (preg_quote($unquoted) == $this->regex)
            return $unquoted;
        else
            return false;
    }
}


function is_number($x) {
    return is_int($x) || is_float($x);
}

function is_string_array($x) {
    return is_array($x)
        && array_reduce($x, function ($carry, $item) {
               return $carry && is_string($item);
           }, true);
}

function check_date($x) {
    if (is_bool($x) || is_int($x))
        return true;
    else if (is_string($x) && ($d = parse_time($x)))
        return array(true, $d);
    else if (is_string($x))
        return array(false, "date parse error");
    else
        return false;
}

function check_date_or_grades($x) {
    if ($x === "grades")
        return array(true, $x);
    else
        return check_date($x);
}

function check_interval($x) {
    if (is_int($x) || is_float($x))
        return true;
    else if (is_string($x)
             && preg_match(',\A(\d+(?:\.\d*)?|\.\d+)(?:$|\s*)([smhd]?)\z,', strtolower($x), $m)) {
        $mult = array("" => 1, "s" => 1, "m" => 60, "h" => 3600, "d" => 86400);
        return array(true, $m[1] * $mult[$m[2]]);
    } else
        return false;
}
