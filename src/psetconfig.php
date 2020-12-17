<?php
// psetconfig.php -- Peteramati configuration classes
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class PsetConfigException extends Exception {
    /** @var list */
    public $path;
    /** @var ?string */
    public $key;

    function __construct($msg, ...$path) {
        $this->path = [];
        foreach ($path as $x) {
            if (is_array($x)) {
                $this->path = array_merge($this->path, $x);
            } else if ($x !== false && $x !== null) {
                $this->path[] = $x;
            }
        }
        parent::__construct($msg);
    }
}

class Pset {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int
     * @readonly */
    public $id;
    /** @var int
     * @readonly */
    public $psetid;
    /** @var string
     * @readonly */
    public $key;
    /** @var string
     * @readonly */
    public $urlkey;
    /** @var string
     * @readonly */
    public $nonnumeric_key;

    /** @var string
     * @readonly */
    public $title;
    /** @var ?string
     * @readonly */
    public $category;
    /** @var float
     * @readonly */
    public $weight;
    /** @var bool
     * @readonly */
    public $weight_default = false;
    /** @var float
     * @readonly */
    public $position;

    /** @var bool */
    public $disabled;
    /** @var bool */
    public $admin_disabled;
    /** @var bool */
    public $visible;
    /** @var int */
    public $visible_at;
    /** @var bool */
    public $frozen;
    /** @var bool */
    public $partner;
    /** @var bool */
    public $no_branch;
    /** @var bool */
    public $anonymous;
    /** @var bool */
    public $gitless;
    /** @var bool */
    public $gitless_grades;
    /** @var ?string */
    public $partner_repo;
    public $hide_comments = false;

    /** @var string */
    public $main_branch = "master";
    /** @var ?string */
    public $handout_repo_url;
    /** @var ?string */
    public $handout_branch;
    /** @var ?string */
    public $handout_hash;
    /** @var ?string */
    public $handout_warn_hash;
    /** @var ?bool */
    public $handout_warn_merge;
    public $repo_guess_patterns = [];
    public $directory;
    public $directory_slash;
    public $directory_noslash;
    public $test_file;

    public $deadline;
    public $deadline_college;
    public $deadline_extension;
    public $obscure_late_hours = false;

    /** @var array<string,GradeEntryConfig> */
    public $all_grades = [];
    /** @var array<string,GradeEntryConfig> */
    public $grades;
    /** @var list<int>
     * @readonly */
    public $grades_vf;
    /** @var bool */
    public $grades_visible;
    /** @var int */
    public $grades_visible_at;
    /** @var ?int */
    public $grades_total;
    /** @var bool */
    public $grades_history = false;
    /** @var ?string */
    public $grades_selection_function;
    /** @var int */
    public $grade_statistics_visible;
    /** @var ?float */
    public $grade_cdf_cutoff;
    /** @var bool */
    public $separate_extension_grades;
    /** @var bool */
    public $has_extra = false;
    /** @var bool */
    public $has_grade_landmark = false;
    /** @var bool */
    public $has_grade_landmark_range = false;
    /** @var bool */
    public $has_formula = false;
    /** @var bool */
    private $has_answers = false;
    private $_max_grade = [null, null, null, null];
    public $grade_script;
    /** @var GradeEntryConfig */
    private $_late_hours;

    /** @var array<string,DownloadEntryConfig> */
    public $downloads = [];

    /** @var array<string,RunnerConfig> */
    public $all_runners = [];
    /** @var array<string,RunnerConfig> */
    public $runners;
    /** @var ?string */
    public $run_username;
    /** @var ?string */
    public $run_dirpattern;
    /** @var ?list<RunOverlayConfig> */
    public $run_overlay;
    /** @var ?string */
    public $run_skeletondir;
    /** @var ?string */
    public $run_jailfiles;
    /** @var null|string|list<string> */
    public $run_jailmanifest;
    /** @var ?string */
    public $run_binddir;
    /** @var ?float */
    public $run_timeout;
    /** @var ?bool */
    public $run_xterm_js;
    /** @var bool */
    public $has_transfer_warnings;
    /** @var bool */
    public $has_xterm_js;

    /** @var list<DiffConfig> */
    public $diffs = [];
    public $ignore;
    private $_file_ignore_regex;
    /** @var ?list<DiffConfig> */
    private $_extra_diffs;
    /** @var ?list<DiffConfig> */
    private $_all_diffs;
    /** @var ?DiffConfig */
    private $_baseline_diff;
    /** @var array<string,DiffConfig> */
    private $_file_diffinfo = [];

    public $config_signature;
    public $config_mtime;

    const URLKEY_REGEX = '/\A[0-9A-Za-z][-0-9A-Za-z_.]*\z/';

    function __construct(Conf $conf, $pk, $p) {
        $this->conf = $conf;

        // pset id
        if (!isset($p->psetid) || !is_int($p->psetid) || $p->psetid <= 0) {
            throw new PsetConfigException("`psetid` must be positive integer", "psetid");
        }
        $this->id = $this->psetid = $p->psetid;

        // pset key
        if (!isset($p->key) && (string) $pk === "") {
            $pk = $p->psetid;
        } else if (isset($p->key) && $pk !== null && (string) $p->key !== (string) $pk) {
            throw new PsetConfigException("pset key disagrees with `key`", "key");
        } else if (isset($p->key)) {
            $pk = $p->key;
        }
        if (ctype_digit($pk) && intval($pk) !== $p->psetid) {
            throw new PsetConfigException("numeric pset key disagrees with `psetid`", "key");
        } else if (!preg_match('/\A[^_.\/&;#][^\/&;#]*\z/', $pk)) {
            throw new PsetConfigException("pset key format error", "key");
        } else if (preg_match('/(?:_rank|_noextra|_norm|_raw)\z/', $pk, $m)) {
            throw new PsetConfigException("pset key cannot end with `{$m[0]}`", "key");
        } else if (str_starts_with($pk, "pset")
                   && ctype_digit(substr($pk, 4))
                   && substr($pk, 4) != $p->psetid) {
            throw new PsetConfigException("pset key `psetNNN` requires that `NNN` is the psetid", "key");
        }
        $this->key = $pk;
        $this->nonnumeric_key = ctype_digit($pk) ? "pset" . $pk : $pk;

        // url keys
        $urlkey = $p->urlkey ?? null;
        if (is_string($urlkey) && preg_match(self::URLKEY_REGEX, $urlkey)) {
            $this->urlkey = $urlkey;
        } else if (is_int($urlkey)) {
            $this->urlkey = (string) $urlkey;
        } else if ($urlkey) {
            throw new PsetConfigException("`urlkey` format error", "urlkey");
        } else {
            $this->urlkey = (string) $this->psetid;
            if (preg_match(self::URLKEY_REGEX, $this->key)
                && $this->key !== "pset" . $this->psetid)
                $this->urlkey = $this->key;
        }

        $this->title = self::cstr($p, "title");
        if ((string) $this->title === "") {
            $this->title = $this->key;
        }
        $this->category = self::cstr($p, "category", "group");
        $this->weight = self::cnum($p, "weight", "group_weight");
        if ($this->weight === null) {
            $this->weight = 1.0;
            $this->weight_default = true;
        }
        $this->weight = (float) $this->weight;
        $this->position = (float) (Pset::cnum($p, "position") ?? 0.0);

        $this->disabled = self::cbool($p, "disabled");
        if (($this->admin_disabled = self::cbool($p, "admin_disabled", "ui_disabled"))) {
            $this->disabled = true;
        }
        $v = self::cdate($p, "visible", "show_to_students");
        $this->visible = $v === true || (is_int($v) && $v > 0 && $v <= Conf::$now);
        $this->visible_at = is_int($v) ? $v : 0;
        $this->frozen = self::cdate($p, "frozen", "freeze");
        $this->partner = self::cbool($p, "partner");
        $this->no_branch = self::cbool($p, "no_branch");
        $this->anonymous = self::cbool($p, "anonymous");
        $this->gitless = self::cbool($p, "gitless");
        $this->gitless_grades = self::cbool($p, "gitless_grades");
        if ($this->gitless_grades === null) {
            $this->gitless_grades = $this->gitless;
        }
        if (!$this->gitless_grades && $this->gitless) {
            throw new PsetConfigException("`gitless` requires `gitless_grades`", "gitless_grades");
        }
        $this->partner_repo = self::cstr($p, "partner_repo");
        if ((string) $this->partner_repo === "" || $this->partner_repo === "same") {
            $this->partner_repo = null;
        } else if ($this->partner_repo !== "different") {
            throw new PsetConfigException("`partner_repo` should be \"same\" or \"different\"", "partner_repo");
        }
        $this->hide_comments = self::cbool($p, "hide_comments");

        // directory
        $this->main_branch = self::cstr($p, "main_branch") ?? "master";
        $this->handout_repo_url = self::cstr($p, "handout_repo_url");
        if (!$this->handout_repo_url && !$this->gitless) {
            throw new PsetConfigException("`handout_repo_url` missing", "handout_repo_url");
        }
        $this->handout_branch = self::cstr($p, "handout_branch", "handout_repo_branch") ?? $this->main_branch;
        $this->handout_hash = self::cstr($p, "handout_hash", "handout_commit_hash");
        $this->handout_warn_hash = self::cstr($p, "handout_warn_hash");
        $this->handout_warn_merge = self::cbool($p, "handout_warn_merge");
        $this->repo_guess_patterns = self::cstr_list($p, "repo_guess_patterns", "repo_transform_patterns");
        $this->directory = $this->directory_slash = "";
        if (isset($p->directory) && is_string($p->directory)) {
            $this->directory = $p->directory;
        } else if (isset($p->directory) && $p->directory !== false) {
            throw new PsetConfigException("`directory` format error", "directory");
        }
        $this->directory_slash = preg_replace('/([^\/])\/*\z/', '$1/', $this->directory);
        while (str_starts_with($this->directory_slash, "./")) {
            $this->directory_slash = substr($this->directory_slash, 2);
        }
        if (str_starts_with($this->directory_slash, "/")) {
            $this->directory_slash = substr($this->directory_slash, 1);
        }
        $this->directory_noslash = preg_replace('/\/+\z/', '', $this->directory_slash);
        $this->test_file = self::cstr($p, "test_file");

        // deadlines
        $this->deadline = self::cdate($p, "deadline");
        $this->deadline_college = self::cdate($p, "deadline_college", "college_deadline");
        $this->deadline_extension = self::cdate($p, "deadline_extension", "college_extension");
        if (!$this->deadline) {
            $this->deadline = $this->deadline_college ? : $this->deadline_extension;
        }
        $this->obscure_late_hours = self::cbool($p, "obscure_late_hours");

        // grades
        $grades = $p->grades ?? null;
        if (is_array($grades) || is_object($grades)) {
            foreach ((array) $p->grades as $k => $v) {
                $g = new GradeEntryConfig(is_int($k) ? $k + 1 : $k, $v);
                if (isset($this->all_grades[$g->key])
                    || $g->key === "late_hours") {
                    throw new PsetConfigException("grade `$g->key` reused", "grades", $k);
                }
                $this->all_grades[$g->key] = $g;
                if ($g->landmark_file || $g->landmark_range_file) {
                    $this->has_grade_landmark = true;
                }
                if ($g->landmark_range_file) {
                    $this->has_grade_landmark_range = true;
                }
                if ($g->formula) {
                    $this->has_formula = true;
                }
                if ($g->is_extra) {
                    $this->has_extra = true;
                }
                if ($g->visible && $g->answer) {
                    $this->has_answers = true;
                }
            }
        } else if ($grades) {
            throw new PsetConfigException("`grades` format error`", "grades");
        }
        if ($p->grade_order ?? null) {
            $this->grades = self::reorder_config("grade_order", $this->all_grades, $p->grade_order);
        } else {
            $this->grades = self::position_sort("grades", $this->all_grades);
        }
        $this->grades_total = self::cnum($p, "grades_total");
        $this->grades_history = self::cbool($p, "grades_history") ?? false;
        $this->grades_selection_function = self::cstr($p, "grades_selection_function");
        $gv = self::cdate($p, "grades_visible", "show_grades_to_students");
        $this->grades_visible = $gv === true || (is_int($gv) && $gv > 0 && $gv <= Conf::$now);
        $this->grades_visible_at = is_int($gv) ? $gv : 0;
        $this->grades_vf = [];
        $vis1 = !$this->disabled && $this->visible;
        $vis2 = $vis1 && $this->grades_visible;
        foreach (array_values($this->grades) as $i => $ge) {
            $ge->pcview_index = $i;
            if ($ge->visible && $vis1 && ($vis2 || $ge->answer)) {
                $this->grades_vf[] = 3;
            } else {
                $this->grades_vf[] = 2;
            }
        }

        $gsv = self::cdate_or_grades($p, "grade_statistics_visible", "grade_cdf_visible");
        if ($gsv === true) {
            $this->grade_statistics_visible = 1;
        } else if (($gsv ?? "grades") === "grades") {
            $this->grade_statistics_visible = 2;
        } else if ($gsv === false || $gsv <= 0 || $gsv > 2) {
            $this->grade_statistics_visible = (int) $gsv;
        } else {
            throw new PsetConfigException("`grade_statistics_visible` format error", "grade_statistics_visible");
        }
        $this->grade_cdf_cutoff = self::cnum($p, "grade_cdf_cutoff");
        $this->separate_extension_grades = self::cbool($p, "separate_extension_grades");
        $this->grade_script = $p->grade_script ?? null;
        if (is_string($this->grade_script)) {
            $this->grade_script = [$this->grade_script];
        }

        if (($this->deadline || $this->deadline_college || $this->deadline_extension)
            && !self::cbool($p, "no_late_hours")) {
            $this->_late_hours = GradeEntryConfig::make_late_hours();
        }

        // downloads
        $downloads = $p->downloads ?? null;
        if (is_array($downloads) || is_object($downloads)) {
            foreach ((array) $downloads as $k => $v) {
                $g = new DownloadEntryConfig(is_int($k) ? $k + 1 : $k, $v);
                if ($this->downloads[$g->key] ?? null) {
                    throw new PsetConfigException("download `$g->key` reused", "downloads", $k);
                }
                $this->downloads[$g->key] = $g;
            }
        } else if ($downloads) {
            throw new PsetConfigException("`downloads` format error`", "downloads");
        }
        $this->downloads = self::position_sort("downloads", $this->downloads);

        // runners
        $runners = $p->runners ?? null;
        $default_runner = $p->default_runner ?? null;
        $this->has_transfer_warnings = $this->has_xterm_js = false;
        if (is_array($runners) || is_object($runners)) {
            foreach ((array) $p->runners as $k => $v) {
                $r = new RunnerConfig(is_int($k) ? $k + 1 : $k, $v, $default_runner);
                if (isset($this->all_runners[$r->name])) {
                    throw new PsetConfigException("runner `$r->name` reused", "runners", $k);
                }
                $this->all_runners[$r->name] = $r;
                if ($r->transfer_warnings) {
                    $this->has_transfer_warnings = true;
                }
                if ($r->xterm_js) {
                    $this->has_xterm_js = true;
                }
            }
        } else if ($runners) {
            throw new PsetConfigException("`runners` format error", "runners");
        }
        if ($p->runner_order ?? false) {
            $this->runners = self::reorder_config("runner_order", $this->all_runners, $p->runner_order);
        } else {
            $this->runners = self::position_sort("runners", $this->all_runners);
        }
        $this->run_dirpattern = self::cstr($p, "run_dirpattern");
        $this->run_username = self::cstr($p, "run_username");
        if (($overs = $p->run_overlay ?? null) !== null) {
            $this->run_overlay = [];
            foreach (is_array($overs) ? $overs : [$overs] as $k => $over) {
                $this->run_overlay[] = new RunOverlayConfig($k, $over);
            }
        }
        $this->run_jailfiles = self::cstr($p, "run_jailfiles");
        $this->run_jailmanifest = self::cstr_or_str_list($p, "run_jailmanifest");
        $this->run_xterm_js = self::cbool($p, "run_xterm_js");
        $this->run_timeout = self::cinterval($p, "run_timeout");
        if ($this->run_timeout === null) { // default run_timeout is 10m
            $this->run_timeout = 600;
        }
        $this->run_skeletondir = self::cstr($p, "run_skeletondir");
        $this->run_binddir = self::cstr($p, "run_binddir");

        // diffs
        $diffs = $p->diffs ?? null;
        if (is_object($diffs)) {
            foreach (get_object_vars($p->diffs) as $k => $v) {
                $this->diffs[] = new DiffConfig($v, $k);
            }
        } else if (is_array($diffs)) {
            foreach ($p->diffs as $i => $v) {
                $this->diffs[] = new DiffConfig($v);
            }
        } else if ($diffs) {
            throw new PsetConfigException("`diffs` format error", "diffs");
        }
        if (($ignore = $p->ignore ?? null)) {
            $this->ignore = self::cstr_or_str_list($p, "ignore");
        }

        $this->config_signature = self::cstr($p, "config_signature");
        $this->config_mtime = self::cint($p, "config_mtime");
    }

    /** @return int */
    static function compare(Pset $a, Pset $b) {
        if ($a->position != $b->position) {
            return $a->position < $b->position ? -1 : 1;
        }
        $adl = $a->deadline_college ? : $a->deadline;
        $bdl = $b->deadline_college ? : $b->deadline;
        if (!$adl != !$bdl) {
            return $adl ? -1 : 1;
        } else if ($adl != $bdl) {
            return $adl < $bdl ? -1 : 1;
        } else if (($cmp = strcasecmp($a->title, $b->title))) {
            return $cmp;
        } else {
            return $a->id < $b->id ? -1 : 1;
        }
    }

    /** @return int */
    static function compare_newest_first(Pset $a, Pset $b) {
        if ($a->position != $b->position) {
            return $a->position < $b->position ? -1 : 1;
        }
        $adl = $a->deadline_college ? : $a->deadline;
        $bdl = $b->deadline_college ? : $b->deadline;
        if (!$adl != !$bdl) {
            return $adl ? -1 : 1;
        } else if ($adl != $bdl) {
            return $adl < $bdl ? 1 : -1;
        } else if (($cmp = strcasecmp($a->title, $b->title))) {
            return $cmp;
        } else {
            return $a->id < $b->id ? -1 : 1;
        }
    }


    /** @return bool */
    function student_can_view() {
        return !$this->disabled && $this->visible;
    }

    /** @return bool */
    function student_can_view_grades() {
        return $this->student_can_view()
            && ($this->has_answers || $this->grades_visible);
    }

    /** @return bool */
    function student_can_edit_grades() {
        return $this->student_can_view()
            && $this->has_answers;
    }


    /** @return ?Repository */
    function handout_repo(Repository $inrepo = null) {
        return $this->conf->handout_repo($this, $inrepo);
    }

    /** @return array<string,CommitRecord> */
    function handout_commits() {
        return $this->conf->handout_commits($this);
    }

    /** @return ?CommitRecord */
    function handout_commit($hash) {
        return $this->conf->handout_commit($this, $hash);
    }

    /** @return ?array<string,CommitRecord> */
    function handout_commits_from($hash) {
        return $this->conf->handout_commits_from($this, $hash);
    }

    /** @return ?CommitRecord */
    function latest_handout_commit() {
        return $this->conf->latest_handout_commit($this);
    }

    /** @param CommitRecord $commit
     * @return bool */
    function is_handout($commit) {
        if ($commit->_is_handout_pset !== $this) {
            $commit->_is_handout_pset = $this;
            $commit->_is_handout = !!$this->handout_commit($commit->hash);
        }
        return $commit->_is_handout;
    }


    /** @return array<string,GradeEntryConfig> */
    function grades() {
        return $this->grades;
    }

    /** @return ?GradeEntryConfig */
    function gradelike_by_key($key) {
        if (isset($this->all_grades[$key])) {
            return $this->all_grades[$key];
        } else if ($key === "late_hours") {
            return $this->late_hours_entry();
        } else {
            return null;
        }
    }

    /** @return array<string,GradeEntryConfig> */
    function tabular_grades() {
        return array_filter($this->grades, function ($ge) {
            return $ge->type_tabular;
        });
    }

    /** @param bool $pcview
     * @return list<GradeEntryConfig> */
    function visible_grades($pcview) {
        if ($pcview) {
            return array_values($this->grades);
        } else if (!$this->disabled && $this->visible) {
            $g = [];
            foreach (array_values($this->grades) as $i => $ge) {
                if ($this->grades_vf[$i] & 2) {
                    $g[] = $ge;
                }
            }
            return $g;
        } else {
            return [];
        }
    }

    /** @return GradeEntryConfig */
    function late_hours_entry() {
        return $this->_late_hours;
    }

    /** @param bool $pcview
     * @param bool $include_extra
     * @return int|float */
    function max_grade($pcview, $include_extra = false) {
        $i = ($pcview ? 1 : 0) | ($include_extra ? 2 : 0);
        if (!isset($this->_max_grade[$i])) {
            $max = 0;
            foreach ($this->visible_grades($pcview) as $ge) {
                if ($ge->max
                    && !$ge->no_total
                    && ($pcview || $ge->max_visible)
                    && (!$ge->is_extra || $include_extra))
                    $max += $ge->max;
            }
            $this->_max_grade[$i] = $max;
        }
        return $this->_max_grade[$i];
    }

    /** @param Contact $student
     * @return ?UserPsetInfo */
    function upi_for($student) {
        $result = $this->conf->qe("select * from ContactGrade
            where cid=? and pset=?",
            $student->contactId, $this->psetid);
        $upi = UserPsetInfo::fetch($result);
        Dbl::free($result);
        return $upi;
    }

    /** @param Repository $repo
     * @param int $branchid
     * @return ?RepositoryPsetInfo */
    function rpi_for($repo, $branchid) {
        $result = $this->conf->qe("select rg.*, cn.notes, cn.notesversion
            from RepositoryGrade rg
            left join CommitNotes cn on (cn.pset=rg.pset and cn.bhash=rg.gradebhash)
            where rg.repoid=? and rg.branchid=? and rg.pset=?",
            $repo->repoid, $branchid, $this->psetid);
        $rpi = RepositoryPsetInfo::fetch($result);
        Dbl::free($result);
        return $rpi;
    }

    /** @param non-empty-string $bhash
     * @return ?CommitPsetInfo */
    function cpi_at($bhash) {
        assert(!$this->gitless);
        $result = $this->conf->qe("select * from CommitNotes
            where pset=? and bhash=?",
            $this->psetid, strlen($bhash) === 40 ? hex2bin($bhash) : $bhash);
        $cpi = CommitPsetInfo::fetch($result);
        Dbl::free($result);
        return $cpi;
    }


    static function basic_file_ignore_regex() {
        return '.*\.swp|.*~|#.*#|.*\.core|.*\.dSYM|.*\.o|core.*\z|.*\.backup|tags|tags\..*|typescript';
    }

    static private function _file_glob_to_regex($x, $prefix) {
        $x = str_replace(array('\*', '\?', '\[', '\]', '\-', '_'),
                         array('[^/]*', '[^/]', '[', ']', '-', '\_'),
                         preg_quote($x));
        if ($x === "") {
            return "";
        } else if (strpos($x, "/") === false) {
            if ($prefix) {
                return '|\A' . preg_quote($prefix) . '/' . $x;
            } else {
                return '|' . $x;
            }
        } else {
            if ($prefix) {
                return '|\A' . preg_quote($prefix) . '/' . $x . '\z';
            } else {
                return '|\A' . $x . '\z';
            }
        }
    }

    function file_ignore_regex() {
        if (isset($this->_file_ignore_regex)) {
            return $this->_file_ignore_regex;
        }
        $regex = self::basic_file_ignore_regex();
        if ($this->conf->setting("__gitignore_pset{$this->id}_at", 0) < Conf::$now - 900) {
            $hrepo = $this->handout_repo();
            $result = "";
            if ($this->directory_slash !== "") {
                $result .= $hrepo->gitrun("git show repo{$hrepo->repoid}/master:" . escapeshellarg($this->directory_slash) . ".gitignore 2>/dev/null");
            }
            $result .= $hrepo->gitrun("git show repo{$hrepo->repoid}/master:.gitignore 2>/dev/null");
            $this->conf->save_setting("__gitignore_pset{$this->id}_at", Conf::$now);
            $this->conf->save_setting("gitignore_pset{$this->id}", 1, $result);
        }
        if (($result = $this->conf->setting_data("gitignore_pset{$this->id}"))) {
            foreach (preg_split('/\s+/', $result) as $x) {
                $regex .= self::_file_glob_to_regex($x, $this->directory_noslash);
            }
        }
        if (($xarr = $this->ignore)) {
            if (!is_array($xarr)) {
                $xarr = preg_split('/\s+/', $xarr);
            }
            foreach ($xarr as $x) {
                $regex .= self::_file_glob_to_regex($x, false);
            }
        }
        return ($this->_file_ignore_regex = $regex);
    }


    /** @return list<DiffConfig> */
    function all_diffconfig() {
        if ($this->_all_diffs === null) {
            $this->_all_diffs = $this->diffs;
            if (($regex = $this->file_ignore_regex())) {
                $this->_all_diffs[] = new DiffConfig((object) ["match" => $regex, "ignore" => true, "match_priority" => -10]);
            }
            foreach ($this->_extra_diffs ?? [] as $d) {
                $this->_all_diffs[] = $d;
            }
            foreach ($this->_all_diffs as $i => $d) {
                $d->subposition = $i;
            }
        }
        return $this->_all_diffs;
    }

    /** @param string $filename
     * @return ?DiffConfig */
    function find_diffconfig($filename) {
        if (!array_key_exists($filename, $this->_file_diffinfo)) {
            $diffinfo = null;
            foreach ($this->all_diffconfig() as $d) {
                if ($d->match === ".*"
                    || preg_match('{(?:\A|/)(?:' . $d->match . ')(?:/|\z)}', $filename)) {
                    $diffinfo = DiffConfig::combine($filename, $diffinfo, $d);
                }
            }
            $this->_file_diffinfo[$filename] = $diffinfo;
        }
        return $this->_file_diffinfo[$filename];
    }

    /** @param string $filename
     * @return DiffConfig */
    function baseline_diffconfig($filename) {
        $diffinfo = null;
        foreach ($this->diffs as $d) {
            if ($d->match === ".*"
                || preg_match('{(?:\A|/)(?:' . $d->match . ')(?:/|\z)}', $filename)) {
                $diffinfo = DiffConfig::combine($filename, $diffinfo, $d);
            }
        }
        return $diffinfo ?? new DiffConfig((object) [], $filename);
    }

    /** @return list<string> */
    function potential_diffconfig_full() {
        $fnames = [];
        foreach ($this->all_diffconfig() as $d) {
            if ($d->full
                && ($fname = $d->exact_filename()) !== null
                && !in_array($fname, $fnames))
                $fnames[] = $fname;
        }
        return $fnames;
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
                    || str_starts_with($f, "../")) {
                    return $files;
                } else {
                    $pfiles[] = $this->directory_slash . $f;
                }
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
        $ps = is_object($args[$i]) ? [$args[$i]] : $args[$i];
        $warn = false;
        for (++$i; $i < count($args); ++$i) {
            $x = $args[$i];
            foreach ($ps as $p) {
                if (($pv = $p->$x ?? null) !== null) {
                    $v = call_user_func($callable, $pv);
                    '@phan-var-force bool|array{bool,mixed} $v';
                    if (is_array($v) && $v[0]) {
                        return $v[1];
                    } else if ($v === true) {
                        return $pv;
                    } else {
                        $errormsg = is_array($v) ? $v[1] : "format error";
                        throw new PsetConfigException("`$x` $errormsg", $format, $x);
                    }
                }
            }
            $warn = $x;
        }
        return null;
    }

    /** @return ?bool */
    static function cbool(...$args) {
        return self::ccheck("is_bool", $args);
    }

    /** @return ?int */
    static function cint(...$args) {
        return self::ccheck("is_int", $args);
    }

    /** @return null|int|float */
    static function cnum(...$args) {
        return self::ccheck("is_number", $args);
    }

    /** @return ?string */
    static function cstr(...$args) {
        return self::ccheck("is_string", $args);
    }

    /** @return ?list<string> */
    static function cstr_list(...$args) {
        return self::ccheck("is_string_list", $args);
    }

    /** @return null|string|list<string> */
    static function cstr_or_str_list(...$args) {
        return self::ccheck("is_string_or_string_list", $args);
    }

    /** @return null|bool|int|float */
    static function cdate(...$args) {
        return self::ccheck("check_date", $args);
    }

    /** @return null|bool|int|float|'grades' */
    static function cdate_or_grades(...$args) {
        return self::ccheck("check_date_or_grades", $args);
    }

    /** @return null|int|float */
    static function cinterval(...$args) {
        return self::ccheck("check_interval", $args);
    }

    private static function reorder_config($what, $a, $order) {
        if (!is_array($order))
            throw new PsetConfigException("`$what` format error", $what);
        $b = array();
        foreach ($order as $name) {
            if (is_string($name)) {
                if (isset($a[$name]) && !isset($b[$name]))
                    $b[$name] = $a[$name];
                else if (isset($a[$name]))
                    throw new PsetConfigException("`$what` entry `$name` reused", $what);
                else
                    throw new PsetConfigException("`$what` entry `$name` unknown", $what);
            } else {
                throw new PsetConfigException("`$what` format error", $what);
            }
        }
        return $b;
    }

    private static function position_sort($what, $x) {
        $i = 0;
        $xp = [];
        foreach ($x as $k => $v) {
            $xp[$k] = [$v->position, $i];
            ++$i;
        }
        uasort($xp, function ($a, $b) {
            if ($a[0] != $b[0]) {
                return $a[0] < $b[0] ? -1 : 1;
            } else if ($a[1] != $b[1]) {
                return $a[1] < $b[1] ? -1 : 1;
            } else {
                return 0;
            }
        });
        $y = [];
        foreach (array_keys($xp) as $k) {
            $y[$k] = $x[$k];
        }
        return $y;
    }
}


class DownloadEntryConfig {
    /** @var string */
    public $key;
    /** @var string */
    public $title;
    /** @var string */
    public $file;
    /** @var string */
    public $filename;
    /** @var bool */
    public $timed;
    public $timeout;
    /** @var ?float */
    public $position;
    /** @var null|bool|int|'grades' */
    public $visible;

    function __construct($name, $g) {
        $loc = ["downloads", $name];
        if (!is_object($g)) {
            throw new PsetConfigException("download entry format error", $loc);
        }
        $this->key = $name;
        if (isset($g->key)) {
            $this->key = $g->key;
        } else if (isset($g->name)) {
            $this->key = $g->name;
        }
        if (!is_string($this->key)
            || !preg_match('/\A[-@~:\$A-Za-z0-9_]+\z/', $this->key)
            || $this->key[0] === "_") {
            throw new PsetConfigException("download entry key format error", $loc);
        }
        $this->title = Pset::cstr($loc, $g, "title");
        if ((string) $this->title === "") {
            $this->title = $this->key;
        }
        if (!isset($g->file)
            || !is_string($g->file)) {
            throw new PsetConfigException("download entry file format error", $loc);
        }
        $this->file = $g->file;
        $this->filename = Pset::cstr($loc, $g, "filename");
        if ((string) $this->filename === "") {
            $this->filename = $this->key;
        }
        $this->timed = Pset::cbool($loc, $g, "timed");
        $this->position = Pset::cnum($loc, $g, "position");
        $this->visible = Pset::cdate($loc, $g, "visible");
        $this->timeout = Pset::cinterval($loc, $g, "timeout");
    }
}

class GradeEntryConfig {
    /** @var string */
    public $key;
    /** @var string */
    public $name;
    /** @var string */
    public $title;
    /** @var string */
    public $description;
    /** @var string */
    public $type;
    /** @var bool */
    public $type_tabular;
    /** @var bool */
    public $type_numeric;
    /** @var ?string */
    public $round;
    /** @var ?list<string> */
    public $options;
    /** @var ?string */
    public $formula;
    /** @var null|false|GradeFormula */
    private $_formula = false;
    /** @var null|int|float */
    public $max;
    /** @var bool
     * @readonly */
    public $answer;
    /** @var bool
     * @readonly */
    public $visible;
    /** @var bool */
    private $_visible_defaulted = false;
    /** @var bool */
    public $max_visible;
    /** @var bool */
    private $_max_visible_defaulted = false;
    /** @var bool */
    public $no_total;
    /** @var bool */
    public $is_extra;
    /** @var float */
    public $position;
    /** @var ?int */
    public $pcview_index;
    /** @var ?string */
    public $landmark_file;
    /** @var ?int */
    public $landmark_line;
    /** @var ?string */
    public $landmark_range_file;
    /** @var ?int */
    public $landmark_range_first;
    /** @var ?int */
    public $landmark_range_last;
    public $landmark_buttons;
    /** @var ?int */
    public $timeout;
    /** @var ?string */
    private $_last_error;
    /** @var object */
    public $config;

    static public $letter_map = [
        "A+" => 98, "A" => 95, "A-" => 92, "A–" => 92, "A−" => 92,
        "B+" => 88, "B" => 85, "B-" => 82, "B–" => 82, "B−" => 82,
        "C+" => 78, "C" => 75, "C-" => 72, "C–" => 72, "C−" => 72,
        "D+" => 68, "D" => 65, "D-" => 62, "D–" => 62, "D−" => 62,
        "E" => 50, "F" => 50
    ];

    function __construct($name, $g) {
        $loc = ["grades", $name];
        if (!is_object($g)) {
            throw new PsetConfigException("grade entry format error", $loc);
        }
        $this->key = $name;
        if (isset($g->key)) {
            $this->key = $g->key;
        } else if (isset($g->name)) {
            $this->key = $g->name;
        }
        if (!is_string($this->key)
            // no spaces, no commas, no plusses
            || !preg_match('/\A[-@~:\$A-Za-z0-9_]+\z/', $this->key)
            || $this->key[0] === "_"
            || $this->key === "total"
            || $this->key === "late_hours"
            || $this->key === "auto_late_hours") {
            throw new PsetConfigException("grade entry key format error", $loc);
        }
        $this->name = $this->key;
        $this->title = Pset::cstr($loc, $g, "title");
        if ((string) $this->title === "") {
            $this->title = $this->key;
        }
        $this->description = Pset::cstr($loc, $g, "description", "edit_description");

        $allow_total = false;
        $type = null;
        if (isset($g->type)) {
            $type = Pset::cstr($loc, $g, "type");
            if ($type === "number") {
                $type = null;
                $this->type_tabular = $this->type_numeric = $allow_total = true;
            } else if (in_array($type, ["checkbox", "checkboxes", "stars"], true)) {
                $this->type_tabular = $this->type_numeric = $allow_total = true;
            } else if (in_array($type, ["letter", "timermark"], true)) {
                $this->type_tabular = $this->type_numeric = true;
                $allow_total = false;
            } else if (in_array($type, ["text", "shorttext", "markdown", "section"], true)) {
                $this->type_tabular = $this->type_numeric = $allow_total = false;
            } else if ($type === "select"
                       && isset($g->options)
                       && is_array($g->options)) {
                // XXX check components are strings all different
                $this->options = $g->options;
                $this->type_tabular = true;
                $this->type_numeric = $allow_total = false;
            } else if ($type === "formula"
                       && isset($g->formula)
                       && is_string($g->formula)) {
                $this->formula = $g->formula;
                $this->type_tabular = $this->type_numeric = true;
                $allow_total = false;
            } else {
                throw new PsetConfigException("unknown grade entry type", $loc);
            }
        } else {
            $this->type_tabular = $this->type_numeric = $allow_total = true;
        }
        $this->type = $type;

        if ($this->type === null && isset($g->round)) {
            $round = Pset::cstr($loc, $g, "round");
            if ($round === "none") {
                $round = null;
            } else if ($round === "up" || $round === "down" || $round === "round") {
                // nada
            } else {
                throw new PsetConfigException("unknown grade entry round", $loc);
            }
            $this->round = $round;
        }

        if (!$allow_total) {
            if (isset($g->no_total) && !$g->no_total) {
                throw new PsetConfigException("grade entry type {$this->type} cannot be in total", $loc);
            }
            $this->no_total = true;
        } else {
            $this->no_total = Pset::cbool($loc, $g, "no_total");
        }

        $this->max = Pset::cnum($loc, $g, "max");
        if ($this->type === "checkbox") {
            $this->max = $this->max ?? 1;
        } else if ($this->type === "checkboxes" || $this->type === "stars") {
            $this->max = $this->max ?? 1;
            if ($this->max != (int) $this->max
                || $this->max < 1
                || $this->max > 10) {
                throw new PsetConfigException("{$this->type} grade entry requires max 1–10", $loc);
            }
        } else if ($this->type === "letter") {
            $this->max = $this->max ?? 100;
            if ((float) $this->max !== 100.0) {
                throw new PsetConfigException("letter grade entry requires max 100", $loc);
            }
        }
        if (isset($g->visible)) {
            $this->visible = Pset::cbool($loc, $g, "visible");
        } else if (isset($g->hidden)) {
            $this->visible = !Pset::cbool($loc, $g, "hidden");
        } else if (isset($g->hide)) {
            $this->visible = !Pset::cbool($loc, $g, "hide"); // XXX
        } else {
            $this->visible = $this->_visible_defaulted = true;
        }
        if (isset($g->max_visible)) {
            $this->max_visible = Pset::cbool($loc, $g, "max_visible");
        } else if (isset($g->hide_max)) {
            $this->max_visible = !Pset::cbool($loc, $g, "hide_max"); // XXX
        } else {
            $this->max_visible = $this->_max_visible_defaulted = true;
        }
        $this->is_extra = Pset::cbool($loc, $g, "is_extra");
        $this->answer = Pset::cbool($loc, $g, "answer", "student");

        $this->position = Pset::cnum($loc, $g, "position");
        if ($this->position === null && isset($g->priority)) {
            $this->position = -Pset::cnum($loc, $g, "priority");
        }

        $lm = self::clean_landmark($g, "landmark");
        $lmr = self::clean_landmark($g, "landmark_range");
        if ($lm === null && $lmr !== null) {
            $lm = $lmr;
        } else if ($lmr === null && $lm !== null && count($lm) > 2) {
            $lmr = $lm;
        }
        if ($lm !== null) {
            if (is_array($lm)
                && count($lm) >= 2
                && count($lm) <= 4
                && is_string($lm[0])
                && is_int($lm[1])
                && (count($lm) < 3 || is_int($lm[2]))
                && (count($lm) < 4 || is_int($lm[3]))) {
                $this->landmark_file = $lm[0];
                $this->landmark_line = $lm[count($lm) === 4 ? 2 : 1];
            } else {
                throw new PsetConfigException("grade entry `landmark` format error", $loc);
            }
        }
        if ($lmr !== null) {
            if (is_array($lmr)
                && count($lmr) >= 3
                && count($lmr) <= 4
                && is_string($lmr[0])
                && is_int($lmr[1])
                && is_int($lmr[2])
                && (count($lmr) < 4 || is_int($lmr[3]))) {
                $this->landmark_range_file = $lmr[0];
                $this->landmark_range_first = $lmr[1];
                $this->landmark_range_last = $lmr[count($lmr) - 1];
            }
            if ($this->landmark_range_file === null
                || $this->landmark_range_first > $this->landmark_range_last) {
                throw new PsetConfigException("grade entry `landmark_range` format error", $loc);
            }
        }
        if (isset($g->landmark_buttons)
            && is_array($g->landmark_buttons)) {
            $this->landmark_buttons = [];
            foreach ($g->landmark_buttons as $lb) {
                if (is_string($lb)
                    || (is_object($lb) && isset($lb->title)))
                    $this->landmark_buttons[] = $lb;
            }
        }

        $this->timeout = Pset::cinterval($loc, $g, "timeout");

        $this->config = $g;
    }

    static function make_late_hours() {
        $ge = new GradeEntryConfig("x_late_hours", (object) ["no_total" => true, "position" => PHP_INT_MAX, "title" => "late hours"]);
        $ge->key = "late_hours";
        return $ge;
    }

    /** @return array{string,int,?int,?int} */
    static private function clean_landmark($g, $k) {
        if (!isset($g->$k)) {
            return null;
        }
        $x = $g->$k;
        if (is_string($x)
            && preg_match('{\A(.*?):(\d+)(:\d+|)(:\d+|)\z}', $x, $m)) {
            $x = [$m[1], intval($m[2])];
            if ($m[3] !== "") {
                $x[] = intval(substr($m[3], 1));
            }
            if ($m[4] !== "") {
                $x[] = intval(substr($m[4], 1));
            }
        }
        return $x;
    }


    /** @param bool $isnew */
    function parse_value($v, $isnew) {
        if ($this->type === "formula") {
            $this->_last_error = "Formula grades cannot be edited.";
            return false;
        }
        if ($v === null || is_int($v) || is_float($v)) {
            return $v;
        } else if (is_string($v)) {
            if (in_array($this->type, ["text", "shorttext", "markdown"])) {
                if (!$isnew) {
                    // do not frobulate old values -- preserve database version
                    return $v;
                } else if ($this->type === "shorttext") {
                    return rtrim($v);
                } else {
                    $l = $d = strlen($v);
                    if ($l > 0 && $v[$l - 1] === "\n") {
                        --$l;
                    }
                    if ($l > 0 && $v[$l - 1] === "\r") {
                        --$l;
                    }
                    return $l === $d ? $v : substr($v, 0, $l);
                }
            } else if ($this->type === "timermark") {
                $v = trim($v);
                if ($v === "" || $v === "0") {
                    return null;
                } else if ($isnew || $v === "now") {
                    return Conf::$now;
                } else if (ctype_digit($v)) {
                    return (int) $v;
                } else {
                    $this->_last_error = "Invalid timermark.";
                    return false;
                }
            } else if ($this->type === "select") {
                if ($v === "" || strcasecmp($v, "none") === 0) {
                    return null;
                } else if (in_array((string) $v, $this->options)) {
                    return $v;
                } else {
                    $this->_last_error = "Invalid grade.";
                    return false;
                }
            }
            $v = trim($v);
            if ($v === "") {
                return null;
            } else if (preg_match('/\A[-+]?\d+\z/', $v)) {
                return intval($v);
            } else if (preg_match('/\A[-+]?(?:\d+\.|\.\d)\d*\z/', $v)) {
                return floatval($v);
            }
            if ($this->type === "letter"
                && isset(self::$letter_map[strtoupper($v)])) {
                return self::$letter_map[strtoupper($v)];
            } else if ($this->type === "letter") {
                $this->_last_error = "Letter grade expected.";
                return false;
            }
        }
        $this->_last_error = $this->type === null ? "Number expected." : "Invalid grade.";
        return false;
    }

    /** @return string */
    function parse_value_error() {
        return $this->_last_error;
    }

    /** @return bool */
    function value_differs($v1, $v2) {
        if (in_array($this->type, ["checkbox", "checkboxes", "stars", "timermark"])
            && (int) $v1 === 0
            && (int) $v2 === 0) {
            return false;
        } else if ($v1 === null
                   || $v2 === null
                   || !$this->type_numeric) {
            return $v1 !== $v2;
        } else {
            return abs($v1 - $v2) >= 0.0001;
        }
    }

    /** @return bool */
    function allow_edit($newv, $oldv, $autov, PsetView $info) {
        if (!$this->value_differs($newv, $oldv)) {
            return true;
        } else if (!$info->pc_view && (!$this->visible || !$this->answer)) {
            $this->_last_error = "Cannot modify grade.";
            return false;
        } else if ($this->type === "timermark" && $oldv && !$info->pc_view) {
            $this->_last_error = "Time already started.";
            return false;
        } else {
            return true;
        }
    }

    /** @return bool */
    function student_can_edit() {
        return $this->visible && $this->answer;
    }

    /** @return ?GradeFormula */
    function formula(Conf $conf) {
        if ($this->_formula === false) {
            $this->_formula = null;
            if ($this->formula
                && ($f = GradeFormula::parse($conf, $this->formula))) {
                $this->_formula = $f;
            }
        }
        return $this->_formula;
    }

    /** @param ?object $gx
     * @param ?object $agx
     * @param ?object $fgx
     * @return null|int|float */
    function extract_value($gx, $agx, $fgx, &$av) {
        $key = $this->key;
        $av = $agx ? $agx->$key ?? null : null;
        if ($this->formula && property_exists($fgx, $key)) {
            return $fgx->$key;
        } else if ($gx && property_exists($gx, $key)) {
            $gv = $gx->$key;
            return $gv !== false ? $gv : null;
        } else {
            return $av;
        }
    }

    function json($pcview, $pos = null) {
        $gej = ["key" => $this->key, "title" => $this->title];
        if ($pos !== null) {
            $gej["pos"] = $pos;
        }
        if ($this->type !== null) {
            $gej["type"] = $this->type;
            if ($this->type === "select") {
                $gej["options"] = $this->options;
            }
        }
        if ($this->round) {
            $gej["round"] = $this->round;
        }
        if ($this->max && ($pcview || $this->max_visible)) {
            $gej["max"] = $this->max;
        }
        if (!$this->no_total) {
            $gej["in_total"] = true;
        }
        if ($this->is_extra) {
            $gej["is_extra"] = true;
        }
        if ($this->type === "timermark" && isset($this->timeout)) {
            $gej["timeout"] = $this->timeout;
        }
        if ($this->landmark_file) {
            $gej["landmark"] = $this->landmark_file . ":" . $this->landmark_line;
        }
        if ($this->landmark_range_file) {
            $gej["landmark_range"] = $this->landmark_range_file . ":" . $this->landmark_range_first . ":" . $this->landmark_range_last;
        }
        if (!$this->visible) {
            $gej["visible"] = false;
        }
        if ($this->answer)  {
            $gej["answer"] = true;
        }
        if (($pcview || $this->answer) && $this->description) {
            $gej["description"] = $this->description;
        }
        return $gej;
    }
}

class RunnerConfig {
    /** @var string */
    public $name;
    /** @var string */
    public $category;
    /** @var string */
    public $title;
    /** @var string */
    public $output_title;
    /** @var ?bool */
    public $disabled;
    /** @var ?bool */
    public $visible;
    /** @var ?bool */
    public $output_visible;
    /** @var ?float */
    public $position;
    /** @var ?string */
    public $command;
    /** @var ?string */
    public $username;
    /** @var ?list<RunOverlayConfig> */
    public $overlay;
    /** @var ?float */
    public $timeout;
    /** @var ?string */
    public $queue;
    /** @var ?int */
    public $nconcurrent;
    /** @var ?bool */
    public $xterm_js;
    /** @var ?bool */
    public $transfer_warnings;
    /** @var ?float */
    public $transfer_warnings_priority;
    /** @var ?string */
    public $require;
    /** @var ?string */
    public $eval;
    /** @var null|bool|float */
    public $timed_replay;

    function __construct($name, $r, $defr) {
        $loc = ["runners", $name];
        if (!is_object($r)) {
            throw new PsetConfigException("runner format error", $loc);
        }
        $rs = $defr ? [$r, $defr] : $r;

        $this->name = isset($r->name) ? $r->name : $name;
        if (!is_string($this->name) || !preg_match(',\A[A-Za-z][0-9A-Za-z_]*\z,', $this->name)) {
            throw new PsetConfigException("runner name format error", $loc);
        }

        if (isset($r->category)) {
            $this->category = $r->category;
        } else if ($defr && isset($defr->category)) {
            $this->category = $defr->category;
        } else {
            $this->category = $this->name;
        }
        if (!is_string($this->category) || !preg_match(',\A[0-9A-Za-z_]+\z,', $this->category)) {
            throw new PsetConfigException("runner category format error", $loc);
        }

        $this->title = Pset::cstr($loc, $r, "title") ?? $this->name;
        $this->output_title = Pset::cstr($loc, $r, "output_title") ?? "{$this->title} output";
        $this->disabled = Pset::cbool($loc, $rs, "disabled");
        $this->visible = Pset::cdate_or_grades($loc, $rs, "visible", "show_to_students");
        $this->output_visible = Pset::cdate_or_grades($loc, $rs, "output_visible", "show_output_to_students", "show_results_to_students");
        $this->timeout = Pset::cinterval($loc, $rs, "timeout", "run_timeout");
        $this->xterm_js = Pset::cbool($loc, $rs, "xterm_js");
        if (isset($r->transfer_warnings)
            ? $r->transfer_warnings === "grades"
            : $defr && isset($defr->transfer_warnings) && $defr->transfer_warnings === "grades") {
            $this->transfer_warnings = "grades";
        } else {
            $this->transfer_warnings = Pset::cbool($loc, $rs, "transfer_warnings");
        }
        $this->transfer_warnings_priority = Pset::cnum($loc, $rs, "transfer_warnings_priority");
        $this->command = Pset::cstr($loc, $rs, "command");
        $this->username = Pset::cstr($loc, $rs, "username", "run_username");
        $this->require = Pset::cstr($loc, $rs, "require", "load");
        $this->eval = Pset::cstr($loc, $rs, "eval");
        $this->queue = Pset::cstr($loc, $rs, "queue");
        $this->nconcurrent = Pset::cint($loc, $rs, "nconcurrent");
        $this->position = Pset::cnum($loc, $rs, "position");
        if ($this->position === null && isset($r->priority)) {
            $this->position = -Pset::cnum($loc, $r, "priority");
        }
        if (($overs = $r->overlay ?? null) !== null) {
            $this->overlay = [];
            foreach (is_array($overs) ? $overs : [$overs] as $k => $over) {
                $this->overlay[] = new RunOverlayConfig($loc, $over);
            }
        }
        if (isset($r->timed_replay)
            ? is_number($r->timed_replay)
            : $defr && isset($defr->timed_replay) && is_number($defr->timed_replay)) {
            $n = isset($r->timed_replay) ? $r->timed_replay : $defr->timed_replay;
            $this->timed_replay = $n > 0 ? (float) $n : false;
        } else {
            $this->timed_replay = !!Pset::cbool($loc, $rs, "timed_replay");
        }
    }
    /** @return ?string */
    function category_argument() {
        return $this->category === $this->name ? null : $this->category;
    }
}

class RunOverlayConfig {
    /** @var string */
    public $file;
    /** @var ?list<string> */
    public $exclude;

    function __construct($name, $r) {
        $loc = ["runner overlay", $name];
        if (is_string($r)) {
            $this->file = $r;
        } else if (is_object($r)) {
            $this->file = Pset::cstr($loc, $r, "file") ?? null;
            if ($this->file === null || $this->file === "") {
                throw new PsetConfigException("runner overlay file format error", $loc);
            }
            if (($x = Pset::cstr_or_str_list($loc, $r, "exclude"))) {
                $this->exclude = is_string($x) ? [$x] : $x;
            }
        }
    }
}

class DiffConfig {
    /** @var string */
    public $match;
    /** @var float */
    public $match_priority;
    /** @var int */
    public $subposition = 0;
    /** @var string */
    public $title;
    /** @var float */
    public $position;
    /** @var bool */
    public $fileless;
    /** @var bool */
    public $full;
    /** @var bool */
    public $collate;
    /** @var bool */
    public $ignore;
    /** @var bool */
    public $collapse;
    /** @var bool */
    public $gradable;
    /** @var bool */
    public $hide_if_anonymous;
    /** @var ?bool */
    public $markdown;
    /** @var ?bool */
    public $markdown_allowed;
    /** @var ?bool */
    public $highlight;
    /** @var ?bool */
    public $highlight_allowed;
    /** @var ?string */
    public $language;
    /** @var ?int */
    public $tabwidth;
    /** @var bool */
    public $nonshared = false;

    /** @param object $d
     * @param ?string $match
     * @param ?float $match_priority */
    function __construct($d, $match = null, $match_priority = 0.0) {
        if (!is_object($d)) {
            throw new PsetConfigException("diff format error", ["diffs", $match]);
        }
        $this->match = $d->match ?? $d->regex ?? $match;
        if (!is_string($this->match) || $this->match === "") {
            throw new PsetConfigException("`match` diff format error", ["diffs", $match]);
        }
        $loc = ["diffs", $this->match];
        $this->title = Pset::cstr($loc, $d, "title");
        if (isset($d->match_priority)) {
            $this->match_priority = (float) Pset::cnum($loc, $d, "match_priority");
        } else {
            $this->match_priority = $match_priority;
        }
        $this->position = Pset::cnum($loc, $d, "position");
        if ($this->position === null && isset($d->priority)) {
            $this->position = -Pset::cnum($loc, $d, "priority");
        }
        $this->fileless = Pset::cbool($loc, $d, "fileless");
        $this->full = Pset::cbool($loc, $d, "full");
        $this->collate = Pset::cbool($loc, $d, "collate");
        $this->ignore = Pset::cbool($loc, $d, "ignore");
        $this->collapse = Pset::cbool($loc, $d, "collapse", "boring");
        $this->gradable = Pset::cbool($loc, $d, "gradable", "gradeable");
        $this->hide_if_anonymous = Pset::cbool($loc, $d, "hide_if_anonymous");
        $this->markdown = Pset::cbool($loc, $d, "markdown");
        $this->markdown_allowed = Pset::cbool($loc, $d, "markdown_allowed");
        if ($this->markdown && $this->markdown_allowed === null) {
            $this->markdown_allowed = true;
        }
        $this->highlight = Pset::cbool($loc, $d, "highlight");
        $this->highlight_allowed = Pset::cbool($loc, $d, "highlight_allowed");
        if ($this->highlight && $this->highlight_allowed === null) {
            $this->highlight_allowed = true;
        }
        $this->language = Pset::cstr($loc, $d, "language");
        $this->tabwidth = Pset::cint($loc, $d, "tabwidth");
    }

    /** @param string $filename
     * @return ?DiffConfig */
    static function combine($filename, DiffConfig $a = null, DiffConfig $b = null) {
        if (!$a && !$b) {
            return null;
        } else if (!$a || !$b) {
            return $a ?? $b;
        } else {
            if ($a->match_priority > $b->match_priority
                || ($a->match_priority == $b->match_priority
                    && $a->subposition > $b->subposition)) {
                $tmp = $b;
                $b = $a;
                $a = $tmp;
            }
            if (!$a->nonshared) {
                $a = clone $a;
                $a->match = preg_quote($filename);
                $a->nonshared = true;
            }
            $a->title = $b->title ?? $a->title;
            $a->position = $b->position ?? $a->position;
            $a->fileless = $b->fileless ?? $a->fileless;
            $a->full = $b->full ?? $a->full;
            $a->collate = $b->collate ?? $a->collate;
            $a->ignore = $b->ignore ?? $a->ignore;
            $a->collapse = $b->collapse ?? $a->collapse;
            $a->gradable = $b->gradable ?? $a->gradable;
            $a->hide_if_anonymous = $b->hide_if_anonymous ?? $a->hide_if_anonymous;
            $a->markdown = $b->markdown ?? $a->markdown;
            $a->markdown_allowed = $b->markdown_allowed ?? $a->markdown_allowed;
            $a->highlight = $b->highlight ?? $a->highlight;
            $a->highlight_allowed = $b->highlight_allowed ?? $a->highlight_allowed;
            $a->language = $b->language ?? $a->language;
            $a->tabwidth = $b->tabwidth ?? $a->tabwidth;
            return $a;
        }
    }

    /** @return ?string */
    function exact_filename() {
        $ok = true;
        // XXX should allow \n, \f, \a, \e, \000, etc.
        $unquoted = preg_replace_callback(',(\\\\[^0-9a-zA-Z]?|[$^.\\[\\]|()?*+{}]),',
            function ($m) use (&$ok) {
                if ($m[1][0] === "\\" && strlen($m[1]) > 1) {
                    return substr($m[1], 1);
                } else {
                    $ok = false;
                }
            }, $this->match);
        return $ok ? $unquoted : null;
    }
}


function is_string_or_string_list($x) {
    return is_string($x) || is_string_list($x);
}

function check_date($x) {
    if (is_bool($x) || is_int($x)) {
        return true;
    } else if (is_string($x) && ($d = Conf::$main->parse_time($x))) {
        return [true, $d];
    } else if (is_string($x)) {
        return [false, "date parse error"];
    } else {
        return false;
    }
}

function check_date_or_grades($x) {
    if ($x === "grades") {
        return true;
    } else {
        return check_date($x);
    }
}

function check_interval($x) {
    if (is_int($x) || is_float($x)) {
        return true;
    } else if (is_string($x)
               && preg_match('/\A(\d+(?:\.\d*)?|\.\d+)(?:$|\s*)([smhd]?)\z/', strtolower($x), $m)) {
        $mult = ["" => 1, "s" => 1, "m" => 60, "h" => 3600, "d" => 86400];
        return [true, floatval($m[1]) * $mult[$m[2]]];
    } else {
        return false;
    }
}
