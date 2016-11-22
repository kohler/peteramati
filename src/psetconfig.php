<?php
// psetconfig.php -- Peteramati configuration classes
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class PsetConfigException extends Exception {
    public $path;

    public function __construct($msg, $path) {
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
    public $visible;
    public $frozen;
    public $partner;
    public $anonymous;
    public $gitless;
    public $gitless_grades;
    public $hide_comments = false;

    public $handout_repo_url;
    public $handout_repo_branch;
    public $repo_guess_patterns = array();
    public $repo_tarball_patterns = array();
    public $directory;
    public $directory_slash;
    public $directory_noslash;
    public $test_file;

    public $deadline;
    public $deadline_college;
    public $deadline_extension;

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

    public $all_runners = array();
    public $runners;
    public $run_username;
    public $run_dirpattern;
    public $run_overlay;
    public $run_skeletondir;
    public $run_jailfiles;
    public $run_binddir;
    public $run_timeout;

    public $diffs = array();
    public $ignore;

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
        $this->visible = self::cdate($p, "visible", "show_to_students");
        $this->frozen = self::cdate($p, "frozen", "freeze");
        $this->partner = self::cbool($p, "partner");
        $this->anonymous = self::cbool($p, "anonymous");
        $this->gitless = self::cbool($p, "gitless");
        $this->gitless_grades = self::cbool($p, "gitless_grades");
        if ($this->gitless_grades === null)
            $this->gitless_grades = $this->gitless;
        if (!$this->gitless_grades && $this->gitless)
            throw new PsetConfigException("`gitless` requires `gitless_grades`", "gitless_grades");
        $this->hide_comments = self::cbool($p, "hide_comments");

        // directory
        $this->handout_repo_url = self::cstr($p, "handout_repo_url");
        if (!$this->handout_repo_url && !$this->gitless)
            throw new PsetConfigException("`handout_repo_url` missing");
        $this->handout_repo_branch = self::cstr($p, "handout_repo_branch");
        $this->repo_guess_patterns = self::cstr_array($p, "repo_guess_patterns", "repo_transform_patterns");
        $this->repo_tarball_patterns = self::cstr_array($p, "repo_tarball_patterns");
        if (!property_exists($p, "repo_tarball_patterns"))
            $this->repo_tarball_patterns = array("^git@(.*?):(.*)\.git$",
                "https://\$1/\$2/archive-tarball/\${HASH}");
        $this->directory = "";
        if (isset($p->directory) && is_string($p->directory))
            $this->directory = $p->directory;
        else if (isset($p->directory) && $p->directory !== false)
            throw new PsetConfigException("`directory` format error", "directory");
        $this->directory_slash = preg_replace(',([^/])/*\z,', '$1/', $this->directory);
        $this->directory_noslash = preg_replace(',/+\z,', '', $this->directory_slash);
        $this->test_file = self::cstr($p, "test_file");

        // deadlines
        $this->deadline = self::cdate($p, "deadline");
        $this->deadline_college = self::cdate($p, "deadline_college", "college_deadline");
        $this->deadline_extension = self::cdate($p, "deadline_extension", "college_extension");

        // grades
        $grades = get($p, "grades");
        if (is_array($grades) || is_object($grades)) {
            foreach ((array) $p->grades as $k => $v) {
                $g = new GradeEntryConfig(is_int($k) ? $k + 1 : $k, $v);
                if (get($this->all_grades, $g->name))
                    throw new PsetConfigException("grade `$g->name` reused", "grades", $k);
                $this->all_grades[$g->name] = $g;
            }
        } else if ($grades)
            throw new PsetConfigException("`grades` format error`", "grades");
        if (get($p, "grade_order"))
            $this->grades = self::reorder_config("grade_order", $this->all_grades, $p->grade_order);
        else
            $this->grades = self::priority_sort("grades", $this->all_grades);
        foreach ($this->grades as $g)
            if ($g->is_extra)
                $this->has_extra = true;
        $this->grades_visible = self::cdate($p, "grades_visible", "show_grades_to_students");
        $this->grades_visible_college = self::cdate($p, "grades_visible_college", "show_grades_to_college");
        if ($this->grades_visible && $this->grades_visible_college === null)
            $this->grades_visible_college = $this->grades_visible;
        $this->grades_visible_extension = self::cdate($p, "grades_visible_extension", "show_grades_to_extension");
        if ($this->grades_visible && $this->grades_visible_extension === null)
            $this->grades_visible_extension = $this->grades_visible;
        $this->grade_cdf_visible = self::cdate($p, "grade_cdf_visible", "show_grade_cdf_to_students");
        if ($this->grade_cdf_visible === null)
            $this->grade_cdf_visible = $this->grades_visible;
        if (!$this->grades_visible_college && !$this->grades_visible_extension)
            $this->grade_cdf_visible = false;
        $this->grade_cdf_cutoff = self::cnum($p, "grade_cdf_cutoff");
        $this->separate_extension_grades = self::cbool($p, "separate_extension_grades");

        // runners
        $runners = get($p, "runners");
        if (is_array($runners) || is_object($runners)) {
            foreach ((array) $p->runners as $k => $v) {
                $r = new RunnerConfig(is_int($k) ? $k + 1 : $k, $v);
                if (get($this->all_runners, $r->name))
                    throw new PsetConfigException("runner `$r->name` reused", "runners", $k);
                $this->all_runners[$r->name] = $r;
            }
        } else if ($runners)
            throw new PsetConfigException("`runners` format error", "runners");
        if (get($p, "runner_order"))
            $this->runners = self::reorder_config("runner_order", $this->all_runners, $p->runner_order);
        else
            $this->runners = self::priority_sort("runners", $this->all_runners);
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


    function handout_repo(Repository $inrepo = null) {
        return $this->conf->handout_repo($this, $inrepo);
    }

    function handout_commits() {
        return $this->conf->handout_commits($this);
    }

    function gradeinfo_json($pcview) {
        $max = (object) [];
        $count = $maxtotal = 0;
        foreach ($this->grades as $ge)
            if (!$ge->hide || $pcview) {
                $key = $ge->name;
                ++$count;
                if ($ge->max && ($pcview || !$ge->hide_max)) {
                    $max->$key = $ge->max;
                    if (!$ge->is_extra && !$ge->no_total)
                        $maxtotal += $ge->max;
                }
            }
        if ($maxtotal)
            $max->total = $maxtotal;
        return (object) ["nentries" => $count, "maxgrades" => $max];
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

    function commit_notes($hash) {
        assert(!$this->gitless);
        $result = $this->conf->qe("select * from CommitNotes where hash=? and pset=?", $hash, $this->psetid);
        $cn = edb_orow($result);
        if ($cn && $cn->notes)
            $cn->notes = json_decode($cn->notes);
        Dbl::free($result);
        return $cn;
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

    public static function cbool(/* ... */) {
        return self::ccheck("is_bool", func_get_args());
    }

    public static function cint(/* ... */) {
        return self::ccheck("is_int", func_get_args());
    }

    public static function cnum(/* ... */) {
        return self::ccheck("is_number", func_get_args());
    }

    public static function cstr(/* ... */) {
        return self::ccheck("is_string", func_get_args());
    }

    public static function cstr_array(/* ... */) {
        return self::ccheck("is_string_array", func_get_args());
    }

    public static function cdate(/* ... */) {
        return self::ccheck("check_date", func_get_args());
    }

    public static function cdate_or_grades(/* ... */) {
        return self::ccheck("check_date_or_grades", func_get_args());
    }

    public static function cinterval(/* ... */) {
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

    private static function priority_sort($what, $a) {
        $i = 0;
        $b = array();
        foreach ($a as $k => $v) {
            $b[$k] = array($v->priority, $i);
            ++$i;
        }
        uasort($b, function ($a, $b) {
                if ($a[0] != $b[0])
                    return $a[0] > $b[0] ? -1 : 1;
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
    public $name;
    public $title;
    public $max;
    public $hide;
    public $hide_max;
    public $no_total;
    public $is_extra;
    public $priority;

    public function __construct($name, $g) {
        $loc = array("grades", $name);
        if (!is_object($g))
            throw new PsetConfigException("grade entry format error", $loc);
        $this->name = isset($g->name) ? $g->name : $name;
        if (!is_string($this->name) || $this->name === "")
            throw new PsetConfigException("grade entry name format error", $loc);
        $this->title = Pset::cstr($loc, $g, "title");
        $this->max = Pset::cnum($loc, $g, "max");
        $this->hide = Pset::cbool($loc, $g, "hide");
        $this->hide_max = Pset::cbool($loc, $g, "hide_max");
        $this->no_total = Pset::cbool($loc, $g, "no_total");
        $this->is_extra = Pset::cbool($loc, $g, "is_extra");
        $this->priority = Pset::cnum($loc, $g, "priority");
    }
}

class RunnerConfig {
    public $name;
    public $runclass;
    public $title;
    public $disabled;
    public $visible;
    public $output_visible;
    public $command;
    public $username;
    public $load;
    public $eval;
    public $queue;
    public $nconcurrent;
    public $priority;

    public function __construct($name, $r) {
        $loc = array("runners", $name);
        if (!is_object($r))
            throw new PsetConfigException("runner format error", $loc);
        $this->name = isset($r->name) ? $r->name : $name;
        if (!is_string($this->name) || !preg_match(',\A[0-9A-Za-z_]+\z,', $this->name))
            throw new PsetConfigException("runner name format error", $loc);
        $this->runclass = isset($r->runclass) ? $r->runclass : $this->name;
        if (!is_string($this->runclass) || !preg_match(',\A[0-9A-Za-z_]+\z,', $this->runclass))
            throw new PsetConfigException("runner runclass format error", $loc);
        $this->title = Pset::cstr($loc, $r, "title", "text");
        if ($this->title === null)
            $this->title = $this->name;
        $this->output_title = Pset::cstr($loc, $r, "output_title", "output_text");
        if ($this->output_title === null)
            $this->output_title = $this->title . " output";
        $this->disabled = Pset::cbool($loc, $r, "disabled");
        $this->visible = Pset::cbool($loc, $r, "visible", "show_to_students");
        $this->output_visible = Pset::cdate_or_grades($loc, $r, "output_visible", "show_output_to_students", "show_results_to_students");
        $this->command = Pset::cstr($loc, $r, "command");
        $this->username = Pset::cstr($loc, $r, "username", "run_username");
        $this->timeout = Pset::cinterval($loc, $r, "timeout", "run_timeout");
        $this->load = Pset::cstr($loc, $r, "load");
        $this->eval = Pset::cstr($loc, $r, "eval");
        $this->queue = Pset::cstr($loc, $r, "queue");
        $this->nconcurrent = Pset::cint($loc, $r, "nconcurrent");
        $this->priority = Pset::cnum($loc, $r, "priority");
    }
    function runclass_argument() {
        return $this->runclass === $this->name ? null : $this->runclass;
    }
}

class DiffConfig {
    public $regex;
    public $match_priority;
    public $priority;
    public $full;
    public $ignore;
    public $boring;

    public function __construct($regex, $d) {
        $loc = array("diffs", $regex);
        if (!is_object($d))
            throw new PsetConfigException("diff format error", $loc);
        $this->regex = isset($d->regex) ? $d->regex : $regex;
        if (!is_string($this->regex) || $this->regex === "")
            throw new PsetConfigException("`regex` diff format error", $loc);
        $this->match_priority = (float) Pset::cint($loc, $d, "match_priority");
        $this->priority = Pset::cnum($loc, $d, "priority");
        $this->full = Pset::cbool($loc, $d, "full");
        $this->ignore = Pset::cbool($loc, $d, "ignore");
        $this->boring = Pset::cbool($loc, $d, "boring");
    }

    static public function combine($a, $b) {
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
        if ($x->priority === null)
            $x->priority = $y->priority;
        if ($x->full === null)
            $x->full = $y->full;
        if ($x->ignore === null)
            $x->ignore = $y->ignore;
        if ($x->boring === null)
            $x->boring = $y->boring;
        return $x;
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
