<?php
// psetconfig.php -- Peteramati configuration classes
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
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
    public $removed;
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
    /** @var bool */
    public $hide_comments = false;

    /** @var string */
    public $main_branch;
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

    /** @var null|int|float */
    public $deadline;
    /** @var null|int|float */
    public $deadline_college;
    /** @var null|int|float */
    public $deadline_extension;
    /** @var bool */
    public $obscure_late_hours = false;

    /** @var array<string,GradeEntry> */
    public $all_grades = [];
    /** @var array<string,GradeEntry>
     * @readonly */
    public $grades = [];
    /** @var int
     * @readonly */
    public $ngrades;
    /** @var list<4|5|6>
     * @readonly */
    private $_grades_vf = [];
    /** @var bool */
    public $scores_visible;
    /** @var int */
    public $scores_visible_at;
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
    public $has_grade_collate = false;
    /** @var bool */
    public $has_grade_landmark = false;
    /** @var bool */
    public $has_grade_landmark_range = false;
    /** @var bool */
    public $has_formula = false;
    /** @var bool */
    public $has_assigned = false;
    /** @var bool */
    public $has_answers = false;
    /** @var bool */
    public $has_timermark = false;
    /** @var ?bool */
    private $_has_uncacheable_formula;
    /** @var ?array<null|int|float> */
    private $_max_grade;
    public $grade_script;
    /** @var GradeEntry */
    private $_late_hours;
    /** @var ?GradeEntry */
    private $_student_timestamp;
    /** @var ?GradeEntry */
    private $_placeholder_entry;

    /** @var array<string,DownloadEntryConfig> */
    public $downloads = [];

    public $reports = [];

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
    public $run_binddir;
    /** @var ?string */
    public $run_jailfiles;
    /** @var null|string|list<string> */
    public $run_jailmanifest;
    /** @var ?bool */
    public $run_xterm_js;
    /** @var ?float */
    public $run_timeout;
    /** @var ?float */
    public $run_idle_timeout;
    /** @var bool */
    public $has_transfer_warnings;
    /** @var bool */
    public $has_xterm_js;

    /** @var null|int|string */
    public $diff_base;
    /** @var list<DiffConfig> */
    public $diffs = [];
    public $ignore;
    private $_file_ignore_regex;
    /** @var int */
    private $_baseline_diffconfig_count;
    /** @var ?PsetView */
    private $_local_diffconfig_source;
    /** @var ?list<DiffConfig> */
    private $_all_diffs;
    /** @var ?DiffConfig */
    private $_baseline_diff;
    /** @var array<string,DiffConfig> */
    private $_file_diffinfo = [];

    /** @var array<string,object> */
    public $api = [];

    public $config_signature;
    public $config_mtime;

    const URLKEY_REGEX = '/\A[0-9A-Za-z][-0-9A-Za-z_.]*\z/';

    function __construct(Conf $conf, $pk, $p) {
        $this->conf = $conf;

        // obsolete components
        Pset::check_obsolete($conf, $p, "pset", true, [
            "group_weight" => "weight", "ui_disabled" => "admin_disabled",
            "show_to_students" => "visible", "freeze" => "frozen",
            "handout_repo_branch" => "handout_branch",
            "handout_commit_hash" => "handout_hash",
            "repo_transform_patterns" => "repo_guess_patterns",
            "college_deadline" => "deadline_college",
            "extension_deadline" => "deadline_extension",
            "show_grades_to_students" => "scores_visible",
            "grade_cdf_visible" => "grade_statistics_visible"
        ]);

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
        $this->weight = self::cnum($p, "weight");
        if ($this->weight === null) {
            $this->weight = 1.0;
            $this->weight_default = true;
        }
        $this->weight = (float) $this->weight;
        $this->position = (float) (Pset::cnum($p, "position") ?? 0.0);

        $this->disabled = self::cbool($p, "disabled");
        if (($this->removed = self::cbool($p, "removed", "admin_disabled"))) {
            $this->disabled = true;
        }
        $v = self::cbool_or_date($p, "visible");
        $this->visible = $v === true || (is_int($v) && $v > 0 && $v <= Conf::$now);
        $this->visible_at = is_int($v) ? $v : 0;
        $this->frozen = self::cbool_or_date($p, "frozen");
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
        $main_branch = self::cstr($p, "main_branch");
        $handout_branch = self::cstr($p, "handout_branch");
        $main_branch = $main_branch ?? ($handout_branch === "main" ? "main" : "master");
        $this->main_branch = $main_branch;
        $this->handout_repo_url = self::cstr($p, "handout_repo_url");
        if (!$this->handout_repo_url && !$this->gitless) {
            throw new PsetConfigException("`handout_repo_url` missing", "handout_repo_url");
        }
        $this->handout_branch = $handout_branch ?? $main_branch;
        $this->handout_hash = self::cstr($p, "handout_hash");
        $this->handout_warn_hash = self::cstr($p, "handout_warn_hash");
        $this->handout_warn_merge = self::cbool($p, "handout_warn_merge");
        $this->repo_guess_patterns = self::cstr_list($p, "repo_guess_patterns");
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
        $this->deadline_college = self::cdate($p, "deadline_college");
        $this->deadline_extension = self::cdate($p, "deadline_extension");
        if (!$this->deadline) {
            $this->deadline = $this->deadline_college ? : $this->deadline_extension;
        }
        $this->obscure_late_hours = self::cbool($p, "obscure_late_hours");

        // grades
        $grades = $p->grades ?? null;
        if (is_array($grades) || is_object($grades)) {
            foreach ((array) $p->grades as $k => $v) {
                $g = new GradeEntry(is_int($k) ? $k + 1 : $k, $v, $this);
                if (isset($this->all_grades[$g->key])
                    || $g->key === "late_hours") {
                    throw new PsetConfigException("grade `$g->key` reused", "grades", $k);
                }
                $this->all_grades[$g->key] = $g;
                if ($g->removed) {
                    continue;
                }
                $this->grades[$g->key] = $g;
                if ($g->collate) {
                    $this->has_grade_collate = true;
                }
                if ($g->landmark_file || $g->landmark_range_file) {
                    $this->has_grade_landmark = true;
                }
                if ($g->landmark_range_file) {
                    $this->has_grade_landmark_range = true;
                }
                if ($g->formula !== null) {
                    $this->has_formula = true;
                }
                if ($g->is_extra) {
                    $this->has_extra = true;
                }
                if ($g->type === "timermark"
                    && (isset($g->timeout) || isset($g->timeout_entry))) {
                    $this->has_timermark = true;
                }
            }
        } else if ($grades) {
            throw new PsetConfigException("`grades` format error`", "grades");
        }
        if ($p->grade_order ?? null) {
            $this->grades = self::reorder_config("grade_order", $this->all_grades, $p->grade_order);
        } else {
            $this->grades = self::position_sort("grades", $this->grades ?? []);
        }
        $this->ngrades = count($this->grades);
        $this->grades_history = self::cbool($p, "grades_history") ?? false;
        $this->grades_selection_function = self::cstr($p, "grades_selection_function");
        $gv = self::cbool_or_date($p, "scores_visible", "grades_visible");
        $this->scores_visible = $gv === true || (is_int($gv) && $gv > 0 && $gv <= Conf::$now);
        $this->scores_visible_at = is_int($gv) ? $gv : 0;
        $vf_mask = $this->visible_student() ? ~0 : ~VF_STUDENT_ANY;
        foreach (array_values($this->grades) as $i => $ge) {
            $ge->pcview_index = $i;
            $vf = $ge->vf() & $vf_mask;
            $this->_grades_vf[] = $vf;
            if (($vf & VF_STUDENT_ANY) !== 0) {
                if ($ge->answer) {
                    $this->has_answers = true;
                } else if ($ge->formula === null) {
                    $this->has_assigned = true;
                }
            }
        }

        $gsv = self::cbool_or_grades_or_date($p, "grade_statistics_visible");
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
            $this->_late_hours = GradeEntry::make_special($this, "late_hours", "late hours", GradeEntry::GTYPE_LATE_HOURS);
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

        // reports
        $this->reports = $p->reports ?? [];

        // runners
        $runners = $p->runners ?? null;
        $default_runner = $p->default_runner ?? null;
        $this->has_transfer_warnings = $this->has_xterm_js = false;
        if (is_array($runners) || is_object($runners)) {
            foreach ((array) $runners as $k => $v) {
                $r = new RunnerConfig(is_int($k) ? $k + 1 : $k, $v, $default_runner, $this);
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
                $this->run_overlay[] = new RunOverlayConfig($k, $over, $this);
            }
        }
        $this->run_jailfiles = self::cstr($p, "run_jailfiles");
        $this->run_jailmanifest = self::cstr_or_str_list($p, "run_jailmanifest");
        $this->run_xterm_js = self::cbool($p, "run_xterm_js");
        $this->run_timeout = self::cinterval($p, "run_timeout") ?? 600 /* 10m default */;
        $this->run_idle_timeout = self::cinterval($p, "run_idle_timeout") ?? 180 /* 3m default */;
        $this->run_skeletondir = self::cstr($p, "run_skeletondir");
        $this->run_binddir = self::cstr($p, "run_binddir");

        // diffs
        if (isset($p->diff_base) && $p->diff_base !== "handout") {
            if (is_int($p->diff_base)) {
                $this->diff_base = $p->diff_base;
            } else {
                $this->diff_base = self::cstr($p, "diff_base");
            }
        }
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

        // api
        $api = $p->api ?? null;
        if (is_array($api) || is_object($api)) {
            foreach ((array) $api as $k => $v) {
                if (!is_object($v)
                    || (is_int($k) && !is_string($v->name ?? null))
                    || (is_string($k) && isset($v->name) && $k !== $v->name)) {
                    throw new PsetConfigException("api name error", "api", $k);
                }
                $name = is_int($k) ? $v->name : $k;
                if (isset($this->api[$name])) {
                    throw new PsetConfigException("api `$name` reused", "api", $k);
                }
                $this->api[$name] = $v;
            }
        } else if ($api) {
            throw new PsetConfigException("`api` format error", "api");
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
    function visible_student() {
        return !$this->disabled && $this->visible;
    }

    /** @return bool */
    function scores_visible_student() {
        return $this->visible_student()
            && $this->scores_visible;
    }

    /** @return bool */
    function grades_visible_student() {
        return $this->visible_student()
            && ($this->has_answers || $this->scores_visible);
    }

    /** @return bool */
    function answers_editable_student() {
        return $this->visible_student()
            && $this->has_answers
            && !$this->frozen;
    }


    /** @return 0|1|3 */
    function default_student_vf() {
        if ($this->visible_student()) {
            return $this->scores_visible ? VF_STUDENT_ANY : VF_STUDENT_ALWAYS;
        } else {
            return 0;
        }
    }

    /** @return list<4|5|6> */
    function grades_vf() {
        return $this->_grades_vf;
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


    /** @return iterable<GradeEntry> */
    function grades() {
        return $this->grades;
    }

    /** @param int $i
     * @return ?GradeEntry */
    function grade_by_pcindex($i) {
        return (array_values($this->grades))[$i] ?? null;
    }

    /** @param string $key
     * @return ?GradeEntry */
    function gradelike_by_key($key) {
        if (isset($this->all_grades[$key])) {
            return $this->all_grades[$key];
        } else if ($key === "late_hours") {
            return $this->late_hours_entry();
        } else if ($key === "student_timestamp") {
            return $this->student_timestamp_entry();
        } else {
            return null;
        }
    }

    private function grades_by_key_list_one($gvs, $i, &$ges, $expand_section) {
        $ge = $gvs[$i];
        ++$i;
        $ges[] = $ge;
        if ($expand_section && $ge->type === "section") {
            while ($i < count($gvs) && $gvs[$i]->type !== "section") {
                $ges[] = $gvs[$i];
                ++$i;
            }
        }
        return $i;
    }

    /** @param string $keys
     * @param bool $expand_section
     * @return list<GradeEntry> */
    function grades_by_key_list($keys, $expand_section) {
        $ges = [];
        $keys = simplify_whitespace(str_replace(",", " ", $keys));
        if ($keys !== "") {
            $gvs = array_values($this->grades);
            foreach (explode(" ", $keys) as $kstr) {
                $ge1 = $ge2 = $this->grades[$kstr] ?? null;
                if (!$ge1 && ($pos = strpos($kstr, "-")) !== false) {
                    if ($pos === 0) {
                        $ge1 = $gvs[0];
                        $ge2 = $this->grades[substr($kstr, 1)];
                    } else if ($pos === strlen($kstr) - 1) {
                        $ge1 = $this->grades[substr($kstr, 0, $pos)];
                        $ge2 = $gvs[count($gvs) - 1];
                    } else {
                        $ge1 = $this->grades[substr($kstr, 0, $pos)];
                        $ge2 = $this->grades[substr($kstr, $pos + 1)];
                    }
                } else if (!$ge1 && strcspn($kstr, "[?*") !== strlen($kstr)) {
                    for ($i = 0; $i !== count($gvs); ) {
                        if (fnmatch($kstr, $gvs[$i]->key, FNM_NOESCAPE)) {
                            $i = $this->grades_by_key_list_one($gvs, $i, $ges, $expand_section);
                        } else {
                            ++$i;
                        }
                    }
                    continue;
                }
                if (!$ge1 || !$ge2) {
                    continue;
                }
                $n1 = $ge1->pcview_index;
                $n2 = $ge2->pcview_index;
                $delta = $n1 <= $n2 ? 1 : -1;
                while ($delta < 0 ? $n1 >= $n2 : $n1 <= $n2) {
                    $i = $this->grades_by_key_list_one($gvs, $n1, $ges, $expand_section);
                    $n1 = $delta > 0 ? $i : $n1 - 1;
                }
            }
        }
        return $ges;
    }

    /** @return iterable<GradeEntry> */
    function tabular_grades() {
        foreach ($this->grades as $ge) {
            if ($ge->type_tabular)
                yield $ge;
        }
    }

    /** @return iterable<GradeEntry> */
    function formula_grades() {
        if ($this->has_formula) {
            foreach ($this->grades as $ge) {
                if ($ge->formula !== null)
                    yield $ge;
            }
        }
    }

    /** @return bool */
    function has_uncacheable_formula() {
        if ($this->_has_uncacheable_formula === null) {
            $this->_has_uncacheable_formula = false;
            foreach ($this->formula_grades() as $ge) {
                if (!$ge->formula()->cacheable) {
                    $this->_has_uncacheable_formula = true;
                    break;
                }
            }
        }
        return $this->_has_uncacheable_formula;
    }

    /** @param 0|1|3|4|5|7 $vf
     * @return list<GradeEntry> */
    function visible_grades($vf) {
        if ($vf >= VF_TF) {
            return array_values($this->grades);
        } else if ($this->visible_student()) {
            $g = [];
            foreach (array_values($this->grades) as $i => $ge) {
                if (($this->_grades_vf[$i] & $vf) !== 0)
                    $g[] = $ge;
            }
            return $g;
        } else {
            return [];
        }
    }

    /** @return GradeEntry */
    function late_hours_entry() {
        return $this->_late_hours;
    }

    /** @return GradeEntry
     * @suppress PhanAccessReadOnlyProperty */
    function student_timestamp_entry() {
        if (!$this->_student_timestamp) {
            $this->_student_timestamp = GradeEntry::make_special($this, "student_timestamp", "timestamp", GradeEntry::GTYPE_STUDENT_TIMESTAMP);
            $this->_student_timestamp->vtype = GradeEntry::VTTIME;
        }
        return $this->_student_timestamp;
    }

    /** @return GradeEntry
     * @suppress PhanAccessReadOnlyProperty */
    function placeholder_entry() {
        if (!$this->_placeholder_entry) {
            $this->_placeholder_entry = GradeEntry::make_special($this, "____placeholder____", "checkbox", GradeEntry::GTYPE_PLACEHOLDER);
        }
        return $this->_placeholder_entry;
    }


    /** @param 1|4 $vf
     * @return int|float */
    function max_grade($vf) {
        if (!isset($this->_max_grade[$vf])) {
            $max = 0;
            foreach ($this->visible_grades($vf) as $ge) {
                if ($ge->max && !$ge->no_total && !$ge->is_extra)
                    $max += $ge->max;
            }
            $this->_max_grade[$vf] = $max;
        }
        return $this->_max_grade[$vf];
    }


    /** @param Contact $student
     * @return ?UserPsetInfo */
    function upi_for($student) {
        $result = $this->conf->qe("select * from ContactGrade where cid=? and pset=?",
            $student->contactId, $this->psetid);
        $upi = UserPsetInfo::fetch($result);
        Dbl::free($result);
        return $upi;
    }

    /** @param Repository $repo
     * @param int $branchid
     * @return ?RepositoryPsetInfo */
    function rpi_for($repo, $branchid) {
        $result = $this->conf->qe("select * from RepositoryGrade where repoid=? and branchid=? and pset=?",
            $repo->repoid, $branchid, $this->psetid);
        $rpi = RepositoryPsetInfo::fetch($result);
        Dbl::free($result);
        return $rpi;
    }

    /** @param non-empty-string $bhash
     * @return ?CommitPsetInfo */
    function cpi_at($bhash) {
        assert(!$this->gitless);
        $result = $this->conf->qe("select * from CommitNotes where pset=? and bhash=?",
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
        if (($this->conf->setting("__gitignore_pset{$this->id}_at") ?? 0) < Conf::$now - 900) {
            $hrepo = $this->handout_repo();
            $result = "";
            $branch = $this->handout_branch;
            if ($this->directory_slash !== "") {
                $result .= $hrepo->gitrun(["git", "show", $hrepo->repobranchname($branch) . ":{$this->directory_slash}.gitignore"]);
            }
            $result .= $hrepo->gitrun(["git", "show", $hrepo->repobranchname($branch) . ":.gitignore"]);
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


    private function seal_diffconfig() {
        if ($this->_all_diffs === null) {
            $this->_all_diffs = $this->diffs;
            if (($regex = $this->file_ignore_regex())) {
                $this->_all_diffs[] = new DiffConfig((object) ["match" => $regex, "ignore" => true, "priority" => -10]);
            }
            foreach ($this->_all_diffs as $i => $di) {
                $di->subposition = $i;
            }
            $this->_baseline_diffconfig_count = count($this->_all_diffs);
        }
    }

    /** @return list<DiffConfig> */
    function all_diffconfig() {
        if ($this->_all_diffs === null) {
            $this->seal_diffconfig();
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

    /** @param list<string> $files
     * @return list<string> */
    function maybe_prefix_directory($files) {
        if (!$this->directory_slash) {
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

    /** @return ?PsetView */
    function local_diffconfig_source() {
        return $this->_local_diffconfig_source;
    }

    /** @param ?PsetView $lds
     * @return bool */
    function set_local_diffconfig_source($lds) {
        if ($lds === $this->_local_diffconfig_source) {
            return false;
        }
        $this->seal_diffconfig();
        $this->_file_diffinfo = [];
        array_splice($this->_all_diffs, $this->_baseline_diffconfig_count);
        $this->_local_diffconfig_source = $lds;
        return true;
    }

    function add_local_diffconfig(DiffConfig $dc) {
        $this->seal_diffconfig();
        $this->_file_diffinfo = [];
        $this->_all_diffs[] = $dc;
        $dc->subposition = count($this->_all_diffs) - 1;
    }


    /** @param string $key
     * @return ?RunnerConfig */
    function runner_by_key($key) {
        if (($pos = strpos($key, ".")) !== false) {
            $key = substr($key, 0, $pos);
        }
        foreach ($this->runners as $runner) {
            if ($runner->name === $key)
                return $runner;
        }
        return null;
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

    /** @param object $x
     * @param string ...$keys
     * @return ?string */
    static function ccomponent($x, ...$keys) {
        foreach ($keys as $k) {
            if (property_exists($x, $k))
                return $k;
        }
        return null;
    }

    /** @param ?Conf $conf
     * @param object $x
     * @param bool $err
     * @param array<string,string> $arr */
    static function check_obsolete($conf, $x, $loc, $err, $arr) {
        $err = $err && (!$conf || !$conf->opt("allowObsoleteConfig"));
        foreach ($arr as $k1 => $k2) {
            if (property_exists($x, $k1)) {
                if ($err) {
                    $loca = is_array($loc) ? $loc : [$loc];
                    $locb = $loca[0] === "pset" ? array_slice($loca, 1) : $loca;
                    $locb[] = $k1;
                    throw new PsetConfigException("obsolete {$loca[0]} component `{$k1}`, use `{$k2}`", $locb);
                } else if (!property_exists($x, $k2)) {
                    $x->{$k2} = $x->{$k1};
                }
            }
        }
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

    /** @return null|int|float */
    static function cdate(...$args) {
        return self::ccheck("check_date", $args);
    }

    /** @return null|bool|int|float */
    static function cbool_or_date(...$args) {
        return self::ccheck("check_bool_or_date", $args);
    }

    /** @return null|bool|int|float|'grades' */
    static function cbool_or_grades_or_date(...$args) {
        return self::ccheck("check_bool_or_grades_or_date", $args);
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
                if (isset($a[$name]) && !isset($b[$name])) {
                    $b[$name] = $a[$name];
                } else if (isset($a[$name])) {
                    throw new PsetConfigException("`$what` entry `$name` reused", $what);
                } else {
                    throw new PsetConfigException("`$what` entry `$name` unknown", $what);
                }
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
    /** @var string
     * @readonly */
    public $key;
    /** @var string
     * @readonly */
    public $title;
    /** @var string
     * @readonly */
    public $file;
    /** @var string
     * @readonly */
    public $filename;
    /** @var bool
     * @readonly */
    public $timed;
    /** @var ?float
     * @readonly */
    public $timeout;
    /** @var ?float
     * @readonly */
    public $position;
    /** @var null|bool|int|'grades'
     * @readonly */
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
        $this->visible = Pset::cbool_or_date($loc, $g, "visible");
        $this->timeout = Pset::cinterval($loc, $g, "timeout");
    }
}

class RunnerException extends Exception {
}

class RunnerConfig {
    /** @var Pset
     * @readonly */
    public $pset;
    /** @var string
     * @readonly */
    public $name;
    /** @var string
     * @readonly */
    public $title;
    /** @var string
     * @readonly */
    public $display_title;
    /** @var ?bool */
    public $disabled;
    /** @var ?bool */
    public $visible;
    /** @var ?bool */
    public $display_visible;
    /** @var ?float */
    public $position;
    /** @var ?string */
    public $username;
    /** @var ?list<RunOverlayConfig> */
    public $overlay;
    /** @var ?float */
    public $timeout;
    /** @var ?float */
    public $idle_timeout;
    /** @var ?string */
    public $queue;
    /** @var ?int */
    public $rerun_timestamp;
    /** @var ?bool */
    public $xterm_js;
    /** @var int */
    public $rows;
    /** @var int */
    public $columns;
    /** @var int */
    public $font_size;
    /** @var null|bool|'grades' */
    public $transfer_warnings;
    /** @var ?float */
    public $transfer_warnings_priority;
    /** @var ?string */
    public $require;
    /** @var ?list<string> */
    public $ensure;
    /** @var ?string */
    public $command;
    /** @var ?string */
    public $evaluate_function;
    /** @var ?string */
    public $display_function;
    /** @var null|bool|float */
    public $timed_replay;
    /** @var ?string */
    public $timed_replay_start;

    function __construct($name, $r, $defr, Pset $pset) {
        $loc = ["runners", $name];
        if (!is_object($r)) {
            throw new PsetConfigException("runner format error", $loc);
        }
        $rs = $defr ? [$r, $defr] : $r;

        // obsolete components
        Pset::check_obsolete($pset->conf, $r, $loc, true, [
            "output_title" => "display_title",
            "show_to_students" => "visible",
            "output_visible" => "output_visible",
            "show_output_to_students" => "output_visible",
            "show_results_to_students" => "display_visible"
        ]);

        $this->pset = $pset;
        $this->name = isset($r->name) ? $r->name : $name;
        if (!is_string($this->name) || !preg_match('/\A[A-Za-z](?:[0-9A-Za-z_]|-(?![-_]))*+\z/', $this->name)) {
            throw new PsetConfigException("runner name format error", $loc);
        }
        if (isset($r->category) || ($defr && isset($defr->category))) {
            throw new PsetConfigException("runner has category");
        }

        $this->title = Pset::cstr($loc, $r, "title") ?? $this->name;
        $this->display_title = Pset::cstr($loc, $r, "display_title") ?? "{$this->title} output";
        $this->disabled = Pset::cbool($loc, $rs, "disabled");
        $this->visible = Pset::cbool_or_grades_or_date($loc, $rs, "visible");
        $this->display_visible = Pset::cbool_or_grades_or_date($loc, $rs, "display_visible");
        $this->timeout = Pset::cinterval($loc, $rs, "timeout", "run_timeout");
        $this->idle_timeout = Pset::cinterval($loc, $rs, "idle_timeout");
        if (isset($r->rerun_timestamp)) {
            if ($r->rerun_timestamp === false) {
                $this->rerun_timestamp = 0;
            } else if ($r->rerun_timestamp !== true) {
                $this->rerun_timestamp = Pset::cdate($loc, $rs, "rerun_timestamp");
            }
        }

        $this->xterm_js = Pset::cbool($loc, $rs, "xterm_js");
        $this->rows = Pset::cint($loc, $rs, "rows") ?? 0;
        $this->columns = Pset::cint($loc, $rs, "columns") ?? 0;
        $this->font_size = Pset::cint($loc, $rs, "font_size") ?? 0;
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
        $this->ensure = Pset::cstr_or_str_list($loc, $rs, "ensure");
        if (is_string($this->ensure)) {
            $this->ensure = [$this->ensure];
        }
        $this->evaluate_function = Pset::cstr($loc, $rs, "evaluate_function", "eval");
        $this->display_function = Pset::cstr($loc, $rs, "display_function", "output_function");
        $this->queue = Pset::cstr($loc, $rs, "queue");
        if (($nc = Pset::cint($loc, $rs, "nconcurrent")) !== null) {
            $this->queue = ($this->queue ?? "") . "#{$nc}";
        }
        $this->position = Pset::cnum($loc, $rs, "position");
        if ($this->position === null && isset($r->priority)) {
            $this->position = -Pset::cnum($loc, $r, "priority");
        }
        if (($overs = $r->overlay ?? null) !== null) {
            $this->overlay = [];
            foreach (is_array($overs) ? $overs : [$overs] as $k => $over) {
                $this->overlay[] = new RunOverlayConfig($loc, $over, $pset);
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
        $this->timed_replay_start = Pset::cstr($loc, $rs, "timed_replay_start");
    }

    /** @param string $x
     * @param Pset $pset
     * @return string */
    static function expand($x, $pset) {
        if (strpos($x, '${') !== false) {
            $x = str_replace('${PSET}', (string) $pset->id, $x);
            $x = str_replace('${CONFDIR}', "conf/", $x);
            $x = str_replace('${SRCDIR}', "src/", $x);
            $x = str_replace('${HOSTTYPE}', $pset->conf->opt("hostType") ?? "", $x);
        }
        return $x;
    }

    /** @return ?string */
    function jailfiles() {
        $f = $this->pset->run_jailfiles ?? $this->pset->conf->opt("run_jailfiles");
        return $f ? self::expand($f, $this->pset) : null;
    }

    /** @return list<string> */
    function jailmanifest() {
        $f = $this->pset->run_jailmanifest;
        if ($f === null || $f === "" || $f === []) {
            return [];
        }
        if (is_string($f)) {
            $f = preg_split('/\r\n|\r|\n/', $f);
        }
        for ($i = 0; $i !== count($f); ) {
            if ($f[$i] === "") {
                array_splice($f, $i, 1);
            } else {
                ++$i;
            }
        }
        return $f;
    }

    /** @return list<RunOverlayConfig> */
    function overlay() {
        return $this->overlay ?? $this->pset->run_overlay ?? [];
    }

    /** @return int */
    function rerun_timestamp() {
        if ($this->rerun_timestamp === null) {
            $this->rerun_timestamp = 0;
            if (($f = $this->jailfiles())) {
                $this->rerun_timestamp = max($this->rerun_timestamp, (int) @filemtime($f));
            }
            foreach ($this->overlay() as $r) {
                $this->rerun_timestamp = max($this->rerun_timestamp, (int) @filemtime($r->file));
            }
        }
        return $this->rerun_timestamp;
    }

    /** @return string */
    function div_attributes(Pset $pset) {
        $t = " id=\"pa-run-{$this->name}\"";
        if ($this->xterm_js ?? $pset->run_xterm_js) {
            $t .= " data-pa-xterm-js=\"true\"";
        }
        if ($this->rows > 0) {
            $t .= " data-pa-rows=\"{$this->rows}\"";
        }
        if ($this->columns > 0) {
            $t .= " data-pa-columns=\"{$this->columns}\"";
        }
        if ($this->font_size > 0) {
            $t .= " data-pa-font-size=\"{$this->font_size}\"";
        }
        if ($this->timed_replay_start) {
            $t .= " data-pa-start=\"" . htmlspecialchars($this->timed_replay_start) . "\"";
        }
        return $t;
    }
}

class RunOverlayConfig {
    /** @var Pset
     * @readonly */
    public $pset;
    /** @var string
     * @readonly */
    public $file;
    /** @var ?list<string>
     * @readonly */
    public $exclude;

    /** @param Pset $pset */
    function __construct($name, $r, $pset) {
        $loc = ["runner overlay", $name];
        $this->pset = $pset;
        if (is_string($r)) {
            $this->file = $r;
        } else if (is_object($r)) {
            $this->file = Pset::cstr($loc, $r, "file") ?? null;
            if (($x = Pset::cstr_or_str_list($loc, $r, "exclude"))) {
                $this->exclude = is_string($x) ? [$x] : $x;
            }
        }
        if ($this->file === null || $this->file === "") {
            throw new PsetConfigException("runner overlay file format error", $loc);
        }
    }

    /** @return string */
    function absolute_path() {
        $f = $this->file;
        if (strpos($f, '${') !== null) {
            $f = RunnerConfig::expand($f, $this->pset);
        }
        if ($f[0] !== '/') {
            $f = SiteLoader::$root . "/" . $f;
        }
        return $f;
    }
}

class DiffConfig {
    /** @var string
     * @readonly */
    public $match;
    /** @var float
     * @readonly */
    public $priority;
    /** @var float
     * @readonly */
    public $priority_default;
    /** @var string
     * @readonly */
    public $title;
    /** @var float
     * @readonly */
    public $position;
    /** @var int */
    public $subposition = 0;
    /** @var bool */
    public $fileless;
    /** @var bool */
    public $full;
    /** @var bool */
    public $collate;
    /** @var bool */
    public $ignore;
    /** @var ?bool */
    public $collapse;
    /** @var ?bool */
    public $collapse_default;
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
     * @param ?float $priority */
    function __construct($d, $match = null, $priority = 0.0) {
        if (!is_object($d)) {
            throw new PsetConfigException("diff format error", ["diffs", $match]);
        }

        // obsolete components
        Pset::check_obsolete(null, $d, ["diffs", $match], true, [
            "boring" => "collapse", "gradeable" => "gradable"
        ]);

        $this->match = $d->match ?? $d->regex ?? $match;
        if (!is_string($this->match) || $this->match === "") {
            throw new PsetConfigException("`match` diff format error", ["diffs", $match]);
        }
        $loc = ["diffs", $this->match];
        $this->title = Pset::cstr($loc, $d, "title");
        $p = (float) (Pset::cnum($loc, $d, "priority", "match_priority") ?? $priority ?? 0.0);
        $this->priority = $p;
        $this->priority_default = $p >= 100.0 ? -INF : $p;
        $this->position = Pset::cnum($loc, $d, "position");
        $this->fileless = Pset::cbool($loc, $d, "fileless");
        $this->full = Pset::cbool($loc, $d, "full");
        $this->collate = Pset::cbool($loc, $d, "collate");
        $this->ignore = Pset::cbool($loc, $d, "ignore");
        $this->collapse = Pset::cbool($loc, $d, "collapse", "boring");
        $this->collapse_default = $p >= 100.0 ? null : $this->collapse;
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
     * @return ?DiffConfig
     * @suppress PhanAccessReadOnlyProperty */
    static function combine($filename, DiffConfig $a = null, DiffConfig $b = null) {
        if (!$a && !$b) {
            return null;
        } else if (!$a || !$b) {
            return $a ?? $b;
        } else {
            if ($a->priority > $b->priority
                || ($a->priority == $b->priority
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
            if ($b->priority_default > -INF
                && ($b->priority_default > $a->priority_default
                    || ($b->priority_default == $a->priority_default
                        && $b->subposition > $a->subposition))) {
                $a->priority_default = $b->priority_default;
                $a->collapse_default = $b->collapse_default ?? $a->collapse_default;
            }
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

class FormulaConfig {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ?string */
    public $name;
    /** @var int */
    public $subposition;
    /** @var ?string */
    public $title;
    /** @var ?string */
    public $description;
    /** @var ?float */
    public $home_position;
    /** @var ?bool */
    public $visible;
    /** @var ?bool */
    public $nonzero;
    /** @var string */
    public $formula;
    /** @var ?GradeFormula */
    private $_formula;
    /** @var object */
    public $config;

    function __construct(Conf $conf, $name, $fj, $subposition = 0) {
        $this->conf = $conf;
        $loc = ["formulas", $name];
        if (!is_object($fj)) {
            throw new PsetConfigException("formula format error", $loc);
        }
        if (is_string($name) && !ctype_digit($name)) {
            $this->name = $name;
        }
        if (isset($fj->name)) {
            $this->name = $fj->name;
        }
        if (isset($this->name)
            && (!is_string($this->name)
                // no spaces, no commas, no plusses
                || !preg_match('/\A[@A-Za-z_][@A-Za-z0-9_]+\z/', $this->name)
                || $this->name[0] === "_"
                || $this->name === "total")) {
            throw new PsetConfigException("formula name format error", $loc);
        }
        $this->title = Pset::cstr($loc, $fj, "title");
        $this->description = Pset::cstr($loc, $fj, "description");
        if (isset($fj->visible)) {
            $this->visible = Pset::cbool($loc, $fj, "visible");
        } else if (isset($fj->hidden)) {
            $this->visible = !Pset::cbool($loc, $fj, "hidden");
        }
        $this->nonzero = Pset::cbool($loc, $fj, "nonzero");
        $this->home_position = Pset::cnum($loc, $fj, "home_position");
        $this->subposition = $subposition;
        $this->formula = Pset::cstr($loc, $fj, "formula");
        $this->config = $fj;
    }

    /** @return ?string */
    function formula_expression() {
        return $this->formula;
    }

    /** @return GradeFormula */
    function formula() {
        if ($this->_formula === null) {
            $fc = new GradeFormulaCompiler($this->conf);
            $this->_formula = $fc->parse($this->formula, null) ?? new Error_GradeFormula;
        }
        return $this->_formula;
    }
}


function is_string_or_string_list($x) {
    return is_string($x) || is_string_list($x);
}

function check_date($x) {
    if (is_int($x)) {
        return true;
    } else if (is_string($x) && ($d = Conf::$main->parse_time($x))) {
        return [true, $d];
    } else if (is_string($x)) {
        return [false, "date parse error"];
    } else {
        return false;
    }
}

function check_bool_or_date($x) {
    return is_bool($x) ? true : check_date($x);
}

function check_bool_or_grades_or_date($x) {
    return is_bool($x) || $x === "grades" ? true : check_date($x);
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
