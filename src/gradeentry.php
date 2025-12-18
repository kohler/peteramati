<?php
// gradeentry.php -- Peteramati grade entry configuration
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class GradeEntry {
    /** @var Pset
     * @readonly */
    public $pset;
    /** @var string
     * @readonly */
    public $key;
    /** @var string
     * @readonly */
    public $name;
    /** @var string
     * @readonly */
    public $title;
    /** @var string
     * @readonly */
    public $description;
    /** @var string
     * @readonly */
    public $label;
    /** @var string
     * @readonly */
    public $type;
    /** @var int
     * @readonly */
    public $gtype = 0;
    /** @var int
     * @readonly */
    public $vtype;
    /** @var bool
     * @readonly */
    public $type_tabular;
    /** @var bool
     * @readonly */
    public $type_numeric;
    /** @var bool
     * @readonly */
    public $removed;
    /** @var bool
     * @readonly */
    private $disabled;
    /** @var ?string
     * @readonly */
    private $disabled_if;
    /** @var ?GradeFormula */
    private $_disabled_if;
    /** @var bool
     * @readonly */
    public $answer;
    /** @var float|false
     * @readonly */
    public $order;
    /** @var ?bool
     * @readonly */
    private $visible;
    /** @var ?string
     * @readonly */
    private $visible_if;
    /** @var ?GradeFormula */
    private $_visible_if;
    /** @var ?string
     * @readonly */
    private $suppressed_if;
    /** @var ?GradeFormula */
    private $_suppressed_if;
    /** @var bool
     * @readonly */
    public $concealed;
    /** @var bool
     * @readonly */
    public $required;
    /** @var ?string */
    public $round;
    /** @var ?list<string> */
    public $options;
    /** @var ?string */
    public $formula;
    /** @var ?GradeFormula */
    private $_formula;
    /** @var null|int|float */
    public $max;
    /** @var bool */
    public $max_visible;
    /** @var bool */
    public $no_total;
    /** @var bool */
    public $is_extra;
    /** @var ?int */
    public $pcview_index;
    /** @var ?bool */
    public $collate;
    /** @var ?string */
    public $table_color;
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
    public $timeout_entry;
    /** @var ?string */
    public $allow_edit_function;
    /** @var ?string */
    public $account_edit_function;
    /** @var object */
    public $config;

    const GTYPE_FORMULA = 1;
    const GTYPE_LATE_HOURS = 2;
    const GTYPE_STUDENT_TIMESTAMP = 3;
    const GTYPE_PLACEHOLDER = 4;

    const VTNUMBER = 0;
    const VTBOOL = 1;
    const VTLETTER = 2;
    const VTTIME = 3;
    const VTDURATION = 4;

    static public $letter_map = [
        "A+" => 98, "A" => 95, "A-" => 92, "A–" => 92, "A−" => 92,
        "B+" => 88, "B" => 85, "B-" => 82, "B–" => 82, "B−" => 82,
        "C+" => 78, "C" => 75, "C-" => 72, "C–" => 72, "C−" => 72,
        "D+" => 68, "D" => 65, "D-" => 62, "D–" => 62, "D−" => 62,
        "E" => 50, "F" => 50
    ];

    /** @param string $name */
    function __construct($name, $g, Pset $pset) {
        $this->pset = $pset;
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
            || !preg_match('/\A(?![-_])(?:[@~:\$A-Za-z0-9_]|-(?![-_]))*+\z/', $this->key)
            || $this->key[0] === "_"
            || $this->key === "total"
            || $this->key === "late_hours"
            || $this->key === "auto_late_hours"
            || $this->key === "student_timestamp") {
            throw new PsetConfigException("grade entry key format error", $loc);
        }
        $this->name = $this->key;
        $this->title = Pset::cstr($loc, $g, "title");
        if ((string) $this->title === "") {
            $this->title = $this->key;
        }
        $this->description = Pset::cstr($loc, $g, "description", "edit_description");
        $this->label = Pset::cstr($loc, $g, "label");
        $this->removed = Pset::cbool($loc, $g, "removed");
        $this->disabled = Pset::cbool($loc, $g, "disabled");
        if (isset($g->disabled_if)) {
            if (is_bool($g->disabled_if)) {
                $this->disabled = $this->disabled || $g->disabled_if;
            } else {
                $this->disabled_if = Pset::cstr($loc, $g, "disabled_if");
            }
        }
        $o = Pset::cnum($loc, $g, "order", "position");
        if ($o === null && isset($g->priority)) {
            $o = -Pset::cnum($loc, $g, "priority");
        }
        $this->order = (float) ($o ?? 0.0);

        $allow_total = false;
        $type = null;
        if (isset($g->type)) {
            $type = Pset::cstr($loc, $g, "type");
        } else if (isset($g->formula) && is_string($g->formula)) {
            $type = "formula";
        }
        $ok = $this->set_type($type, $g);
        if (!$ok && ($gt = $pset->conf->grade_type($type))) {
            foreach (get_object_vars($gt) as $k => $v) {
                if ($k === "type") {
                    $type = $v;
                } else if (!property_exists($g, $k)) {
                    $g->$k = $v;
                }
            }
            $ok = $this->set_type($type, $g);
        }
        if (!$ok) {
            throw new PsetConfigException("unknown grade entry type", $loc);
        }

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

        if (!$this->allow_total()) {
            if (isset($g->no_total) && !$g->no_total) {
                throw new PsetConfigException("grade entry type {$this->type} cannot be in total", $loc);
            }
            $this->no_total = true;
        } else if (isset($g->no_total)) {
            $this->no_total = Pset::cbool($loc, $g, "no_total");
        } else if (isset($g->in_total)) {
            $this->no_total = !Pset::cbool($loc, $g, "in_total");
        }

        $this->max = Pset::cnum($loc, $g, "max");
        if ($this->type === "checkbox") {
            $this->max = $this->max ?? 1;
        } else if ($this->type === "checkboxes" || $this->type === "stars" || $this->type === "poops") {
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
        }
        if (isset($g->visible_if)) {
            $this->visible_if = Pset::cstr($loc, $g, "visible_if");
        }
        if (isset($g->suppressed_if)) {
            $this->suppressed_if = Pset::cstr($loc, $g, "suppressed_if");
        }
        $this->concealed = Pset::cbool($loc, $g, "concealed");
        $this->required = Pset::cbool($loc, $g, "required");
        if (isset($g->max_visible)) {
            $this->max_visible = Pset::cbool($loc, $g, "max_visible");
        } else if (isset($g->hide_max)) {
            $this->max_visible = !Pset::cbool($loc, $g, "hide_max"); // XXX
        } else {
            $this->max_visible = true;
        }
        $this->is_extra = Pset::cbool($loc, $g, "is_extra");
        $this->answer = Pset::cbool($loc, $g, "answer", "student");

        $this->collate = Pset::cbool($loc, $g, "collate");
        $this->table_color = Pset::cstr($loc, $g, "table_color");
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
                $this->collate = $this->collate ?? true;
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
        $this->timeout_entry = Pset::cstr($loc, $g, "timeout_entry");

        $this->allow_edit_function = Pset::cstr($loc, $g, "allow_edit_function");
        $this->account_edit_function = Pset::cstr($loc, $g, "account_edit_function");

        $this->config = $g;
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function set_type($type, $g) {
        if ($type === "number" || $type === "numeric" || $type === null) {
            $type = null;
            $this->type_tabular = $this->type_numeric = true;
        } else if ($type === "checkbox") {
            $this->type_tabular = $this->type_numeric = true;
            $this->vtype = self::VTBOOL;
        } else if ($type === "letter") {
            $this->type_tabular = $this->type_numeric = true;
            $this->vtype = self::VTLETTER;
        } else if (in_array($type, ["checkboxes", "stars", "poops"], true)) {
            $this->type_tabular = $this->type_numeric = true;
        } else if ($type === "timermark") {
            $this->type_tabular = $this->type_numeric = true;
            $this->vtype = self::VTTIME;
        } else if ($type === "duration") {
            $this->type_tabular = $this->type_numeric = true;
            $this->vtype = self::VTDURATION;
        } else if (in_array($type, ["text", "shorttext", "markdown", "section", "none"], true)) {
            $this->type_tabular = $this->type_numeric = false;
        } else if ($type === "select"
                   && isset($g->options)
                   && is_array($g->options)) {
            // XXX check components are strings all different
            $this->options = $g->options;
            $this->type_tabular = true;
            $this->type_numeric = false;
        } else if ($type === "formula"
                   && isset($g->formula)
                   && is_string($g->formula)) {
            $this->formula = $g->formula;
            $this->type_tabular = $this->type_numeric = true;
            $this->gtype = self::GTYPE_FORMULA;
        } else {
            return false;
        }
        $this->type = $type;
        return true;
    }

    private function allow_total() {
        return $this->type_numeric
            && !in_array($this->type, ["letter", "timermark", "formula"], true);
    }

    /** @param string $key
     * @param string $title
     * @param int $gtype
     * @return GradeEntry
     * @suppress PhanAccessReadOnlyProperty */
    static function make_special(Pset $pset, $key, $title, $gtype) {
        $ge = new GradeEntry("x_{$key}", (object) [
            "no_total" => true, "order" => PHP_INT_MAX, "title" => $title
        ], $pset);
        $ge->key = $key;
        $ge->gtype = $gtype;
        return $ge;
    }

    /** @return array{string,int,?int,?int} */
    static private function clean_landmark($g, $k) {
        if (!isset($g->$k)) {
            return null;
        }
        $x = $g->$k;
        if (is_string($x)
            && preg_match('/\A(.*?):(\d+)(:\d+|)(:\d+|)\z/', $x, $m)) {
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

    /** @param string $formula
     * @return GradeFormula */
    private function compile($formula) {
        $fc = new GradeFormulaCompiler($this->pset->conf);
        $gf = $fc->parse($formula, $this) ?? new Error_GradeFormula;
        if ($gf instanceof Error_GradeFormula) {
            error_log($fc->ms->full_feedback_text());
        }
        return $gf;
    }


    /** @param ?PsetView $info
     * @return 0|4|5|6 */
    function vf($info) {
        $visible = $this->visible;
        if ($info) {
            if ($this->suppressed_if !== null) {
                if ($this->_suppressed_if === null) {
                    $this->_suppressed_if = $this->compile($this->suppressed_if);
                }
                if ($this->_suppressed_if->evaluate($info->user, $info)) {
                    return 0;
                }
            }
            if ($visible !== false && $this->visible_if !== null) {
                if ($this->_visible_if === null) {
                    $this->_visible_if = $this->compile($this->visible_if);
                }
                $visible = !!$this->_visible_if->evaluate($info->user, $info);
            }
        }
        if ($visible === true
            || ($this->answer && $visible !== false)
            || $this->concealed) {
            return VF_TF | VF_STUDENT_ALWAYS;
        } else if ($visible === null) {
            return VF_TF | VF_STUDENT_ALLOWED;
        } else {
            return VF_TF;
        }
    }

    /** @return bool */
    function has_visible_if() {
        return $this->visible_if !== null || $this->suppressed_if !== null;
    }


    /** @return ?string */
    function text_title() {
        $t = $this->title;
        if (str_starts_with($t, "<1>")) {
            $t = substr($t, 3);
        }
        if (str_ends_with($t, ")") && preg_match('/\A(.*?)\s*\(\d+ points?\)\z/', $t, $m)) {
            $t = $m[1];
        }
        return $t;
    }

    /** @param string $v
     * @return false|string */
    static function parse_text_value($v) {
        $l = $d = strlen($v);
        if ($l > 0 && $v[$l - 1] === "\n") {
            --$l;
        }
        if ($l > 0 && $v[$l - 1] === "\r") {
            --$l;
        }
        if ($l === 0) {
            return false;
        } else if ($l === $d) {
            return $v;
        } else {
            return substr($v, 0, $l);
        }
    }

    /** @param string $v
     * @return false|string */
    static function parse_shorttext_value($v) {
        $v = rtrim($v);
        return $v === "" ? false : $v;
    }

    /** @param string $v
     * @param bool $isnew
     * @return false|int|GradeError */
    static function parse_timermark_value($v, $isnew) {
        $v = trim($v);
        if ($v === "" || $v === "0") {
            return false;
        } else if ($isnew || $v === "now") {
            return Conf::$now;
        } else if (ctype_digit($v)) {
            return (int) $v;
        }
        return new GradeError("Invalid timermark");
    }

    /** @param string $v
     * @return false|int|string|GradeError */
    function parse_select_value($v) {
        if ($v === "" || strcasecmp($v, "none") === 0) {
            return false;
        }
        $vstr = (string) $v;
        foreach ($this->options as $o) {
            if (is_string($o)) {
                if ($vstr === $o) {
                    return $o;
                }
            } else if (is_int($o)) {
                if ($vstr === (string) $o) {
                    return $o;
                }
            } else {
                if ($vstr === (string) $o->value
                    || (isset($o->title) && $vstr === (string) $o->title)) {
                    return $o->value;
                }
            }
        }
        return new GradeError;
    }

    /** @param string $v
     * @return null|false|int|float|GradeError */
    static function parse_letter_value($v) {
        $v = trim($v);
        if ($v === "") {
            return false;
        } else if (isset(self::$letter_map[strtoupper($v)])) {
            return self::$letter_map[strtoupper($v)];
        } else if (preg_match('/\A[-+]?\d+\z/', $v)) {
            return intval($v);
        } else if (preg_match('/\A[-+]?(?:\d+\.|\.\d)\d*\z/', $v)) {
            return floatval($v);
        } else if (strcasecmp($v, "none") === 0) {
            return false;
        } else if (strcasecmp($v, "auto") === 0) {
            return null;
        } else {
            return new GradeError("Invalid letter grade");
        }
    }

    /** @param null|int|float|string $v
     * @return null|false|int|float|GradeError */
    static function parse_numeric_value($v) {
        if ($v === null || is_int($v) || is_float($v)) {
            return $v;
        }
        $v = trim($v);
        if ($v === "") {
            return false;
        } else if (preg_match('/\A[-+]?\d+\z/', $v)) {
            return intval($v);
        } else if (preg_match('/\A[-+]?(?:\d+\.|\.\d)\d*\z/', $v)) {
            return floatval($v);
        } else if (strcasecmp($v, "none") === 0) {
            return false;
        } else if (strcasecmp($v, "auto") === 0) {
            return null;
        } else {
            return new GradeError("Number expected");
        }
    }

    /** @param null|int|float|string $v
     * @return null|false|int|float|GradeError */
    static function parse_duration_value($v) {
        if ($v === null || is_int($v) || is_float($v)) {
            return $v;
        }
        $v = trim($v);
        if ($v === "") {
            return false;
        } else if (preg_match('/\A[-+]?\d+\z/', $v)) {
            return intval($v);
        } else if (preg_match('/\A[-+]?(?:\d+\.|\.\d)\d*\z/', $v)) {
            return floatval($v);
        } else if (strcasecmp($v, "none") === 0) {
            return false;
        } else if (strcasecmp($v, "auto") === 0) {
            return null;
        } else {
            $d = 0;
            $lastmul = PHP_INT_MAX;
            $v = strtolower($v);
            while ($v !== "") {
                if (!preg_match('/\A(\d+\.?|\d*\.\d+)\s*([hdwms])\s*(?=[\d.]|\z)(.*)\z/', $v, $m)) {
                    return new GradeError("Invalid duration");
                }
                if ($m[2] === "s") {
                    $mul = 1;
                } else if ($m[2] === "m") {
                    $mul = 60;
                } else if ($m[2] === "h") {
                    $mul = 3600;
                } else if ($m[2] === "d") {
                    $mul = 86400;
                } else {
                    $mul = 86400 * 7;
                }
                if ($mul >= $lastmul) {
                    return new GradeError("Invalid duration");
                }
                $d += floatval($m[1]) * $mul;
                $v = $m[3];
                $lastmul = $mul;
            }
            $di = (int) $d;
            return (float) $di === $d ? $di : $d;
        }
    }

    /** @param bool $isnew */
    function parse_value($v, $isnew) {
        if ($this->type === "formula") {
            return new GradeError("Formula grades cannot be edited");
        }
        if ($v === null || is_int($v) || is_float($v)) {
            return $v;
        } else if (is_string($v)) {
            if ($this->type === "text"
                || $this->type === "markdown") {
                // do not frobulate old values -- preserve database version
                return $isnew ? self::parse_text_value($v) : $v;
            } else if ($this->type === "shorttext") {
                return $isnew ? self::parse_shorttext_value($v) : $v;
            } else if ($this->type === "timermark") {
                return self::parse_timermark_value($v, $isnew);
            } else if ($this->type === "select") {
                return $this->parse_select_value($v);
            } else if ($this->type === "letter") {
                return self::parse_letter_value($v);
            } else if ($this->type === "duration") {
                return self::parse_duration_value($v);
            } else {
                return self::parse_numeric_value($v);
            }
        }
        return new GradeError("Invalid grade");
    }

    function unparse_value($v) {
        if ($v === false) {
            $v = null;
        }
        if ($this->type === "letter"
            && $v !== null
            && ($k = array_search($v, self::$letter_map))) {
            return $k;
        } else {
            return $v;
        }
    }

    /** @return bool */
    function value_differs($v1, $v2) {
        if (!$this->type_numeric) {
            return (string) $v1 !== (string) $v2;
        }
        if ((int) $v1 === 0
            && (int) $v2 === 0
            && in_array($this->type, ["checkbox", "checkboxes", "stars", "poops", "timermark"])) {
            return false;
        }
        $v1 = $v1 ?? false;
        $v2 = $v2 ?? false;
        if ($v1 === false || $v2 === false) {
            return $v1 !== $v2;
        }
        return abs($v1 - $v2) >= 0.0001;
    }

    /** @param PsetView $info
     * @return bool */
    function disabled_at($info) {
        if ($this->disabled || !$this->disabled_if) {
            return $this->disabled;
        }
        if ($this->_disabled_if === null) {
            $this->_disabled_if = $this->compile($this->disabled_if);
        }
        return !!$this->_disabled_if->evaluate($info->user, $info);
    }

    /** @return true|GradeError */
    function allow_edit($newv, $oldv, $autov, PsetView $info) {
        if (!$this->value_differs($newv, $oldv)) {
            return true;
        } else if ($this->disabled_at($info)) {
            return new GradeError("Cannot modify");
        }
        if (!$info->pc_view) {
            if ($this->visible === false || !$this->answer) {
                return new GradeError("Cannot modify");
            } else if ($this->pset->frozen || $info->has_pinned_answers()) {
                return new GradeError("You can’t edit your answers further");
            } else if ($this->type === "timermark" && $oldv) {
                return new GradeError("Time already started");
            } else if ($info->has_newer_answers()) {
                return new GradeError("You can’t edit an older version of your answers");
            }
        }
        if (isset($this->allow_edit_function)) {
            return call_user_func($this->allow_edit_function, $this, $newv, $oldv, $autov, $info);
        }
        return true;
    }

    function account_edit($newv, $oldv, $autov, PsetView $info, &$updates) {
        if (isset($this->account_edit_function)) {
            $a = [$this, $newv, $oldv, $autov, $info, &$updates];
            call_user_func_array($this->account_edit_function, $a);
        }
    }

    /** @param PsetView $info
     * @return bool */
    function grader_entry_required($info) {
        return !$this->answer
            && !$this->disabled_at($info)
            && !$this->is_extra
            && !$this->no_total
            && $this->type_numeric
            && $this->type !== "checkbox"
            && $this->type !== "checkboxes"
            && $this->type !== "stars"
            && $this->type !== "poops";
    }

    /** @return bool */
    function is_formula() {
        return $this->formula !== null;
    }

    /** @return ?string */
    function formula_expression() {
        return $this->formula;
    }

    /** @return GradeFormula
     * @suppress PhanAccessReadOnlyProperty */
    function formula() {
        if ($this->_formula === null) {
            if ($this->formula !== null) {
                $this->_formula = $this->compile($this->formula);
                $this->vtype = $this->_formula->vtype;
            } else {
                $this->_formula = new GradeEntry_GradeFormula($this);
            }
        }
        return $this->_formula;
    }

    /** @param 0|1|3|4|5|7 $uvf
     * @param ?PsetView $info
     * @return array<string,mixed> */
    function json($uvf, $info) {
        $gej = ["key" => $this->key, "title" => $this->title];
        if ($this->type !== null) {
            if ($this->type === "select") {
                $gej["type"] = "select";
                $gej["options"] = $this->options;
            } else if ($this->formula !== null) {
                $f = $this->formula();
                if ($f->vtype === self::VTBOOL) {
                    $gej["type"] = "checkbox";
                } else if ($f->vtype === self::VTLETTER) {
                    $gej["type"] = "letter";
                } else if ($f->vtype === self::VTTIME) {
                    $gej["type"] = "time";
                } else if ($f->vtype === self::VTDURATION) {
                    $gej["type"] = "duration";
                } else {
                    $gej["type"] = "formula";
                }
                $gej["readonly"] = true;
            } else {
                $gej["type"] = $this->type;
            }
        }
        if ($this->label !== null) {
            $gej["label"] = $this->label;
        }
        if ($this->round) {
            $gej["round"] = $this->round;
        }
        if ($this->max && ($uvf >= VF_TF || $this->max_visible)) {
            $gej["max"] = $this->max;
        }
        if ($this->collate && $uvf >= VF_TF) {
            $gej["collate"] = true;
        }
        if ($this->table_color) {
            $gej["table_color"] = $this->table_color;
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
        if ($this->type === "timermark" && isset($this->timeout_entry)) {
            $gej["timeout_entry"] = $this->timeout_entry;
        }
        if ($this->landmark_file) {
            $gej["landmark"] = $this->landmark_file . ":" . $this->landmark_line;
        }
        if ($this->landmark_range_file) {
            $gej["landmark_range"] = $this->landmark_range_file . ":" . $this->landmark_range_first . ":" . $this->landmark_range_last;
        }
        if (($uvf & ~VF_TF) === 0) {
            $gej["visible"] = false;
        } else if (!$this->answer && ($uvf & VF_STUDENT_ALLOWED) === 0) {
            $gej["visible"] = true;
        }
        if ($info ? $this->disabled_at($info) : $this->disabled) {
            $gej["disabled"] = true;
        }
        if ($this->concealed) {
            $gej["concealed"] = true;
        }
        if ($this->required !== null) {
            $gej["required"] = $this->required;
        }
        if ($this->answer)  {
            $gej["answer"] = true;
        }
        if ($this->description) {
            $gej["description"] = $this->description;
        }
        return $gej;
    }
}

class GradeError {
    /** @var string */
    public $message;

    /** @param ?string $m */
    function __construct($m = null) {
        $this->message = $m ?? "Invalid grade";
    }
}
