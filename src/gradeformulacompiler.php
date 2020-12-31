<?php
// gradeformulacompiler.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

require_once(SiteLoader::$root . "/src/gradeformula.php");

class GradeFormulaCompilerState {
    /** @var ?GradeEntryConfig */
    public $context;
    /** @var string */
    public $str;
    /** @var int */
    public $pos1;
    /** @var int */
    public $pos2;
    /** @var ?string */
    public $ident;

    /** @param string $str
     * @param ?GradeEntryConfig $context
     * @param ?string $ident */
    function __construct($str, $context, $ident) {
        $this->context = $context;
        $this->str = $str;
        $this->ident = $ident;
    }
}

class GradeFormulaCompiler {
    /** @var Conf */
    public $conf;
    /** @var list<string> */
    public $errors = [];
    /** @var list<string> */
    public $error_decor = [];
    /** @var list<?string> */
    public $error_ident = [];
    /** @var GradeFormulaCompilerState */
    private $state;

    static public $precedences = [
        "**" => 13,
        "*" => 11, "/" => 11, "%" => 11,
        "+" => 10, "-" => 10, "+?" => 10,
        "<" => 8, "<=" => 8, ">" => 8, ">=" => 8,
        "==" => 7, "!=" => 7,
        "&&" => 3,
        "||" => 2,
        "??" => 1,
        "?" => 0, ":" => 0,
        "," => -1
    ];
    const UNARY_PRECEDENCE = 12;
    const MIN_PRECEDENCE = -2;

    static public $total_gkeys = [
        "total" => 0, "total_noextra" => 1,
        "total_norm" => 2, "total_noextra_norm" => 3, "total_norm_noextra" => 3,
        "total_raw" => 4, "total_noextra_raw" => 5, "total_raw_noextra" => 5
    ];


    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @param int $pos1
     * @param int $pos2
     * @param string $msg */
    private function error_at($pos1, $pos2, $msg) {
        $this->errors[] = $msg;
        $this->error_decor[] = Ht::contextual_diagnostic($this->state->str, $pos1, $pos2, $msg);
        $this->error_ident[] = $this->state->ident;
    }

    /** @param string $suffix
     * @param string $msg */
    private function error_near($suffix, $msg) {
        $pos = strlen($this->state->str) - strlen($suffix);
        $this->error_at($pos, $pos, $msg);
    }

    /** @param string $suffix1
     * @param ?string $suffix2 */
    private function set_landmark_near($suffix1, $suffix2 = null) {
        $this->state->pos1 = strlen($this->state->str) - strlen($suffix1);
        if ($suffix2 !== null) {
            $this->state->pos2 = strlen($this->state->str) - strlen($suffix2);
        } else {
            $this->state->pos2 = $this->state->pos1;
        }
    }

    /** @return ?GradeFormula */
    private function parse_grade_entry(GradeEntryConfig $ge) {
        if ($ge->formula) {
            $e = $ge->formula();
            if (!$e) {
                $this->error_at($this->state->pos1, $this->state->pos2, "References invalid formula.");
            }
            return $e;
        } else {
            return new GradeEntry_GradeFormula($ge);
        }
    }

    /** @param string $pkey
     * @param string $gkey
     * @return ?GradeFormula */
    private function parse_grade_pair($pkey, $gkey) {
        if ($pkey === "self" && $this->state->context) {
            $pset = $this->state->context->pset;
        } else if ($pkey === "global") {
            return $this->parse_grade_word($gkey, true);
        } else {
            $pset = $this->conf->pset_by_key_or_title($pkey);
        }
        if ($pset) {
            if (($f = self::$total_gkeys[$gkey] ?? -1) >= 0) {
                return new PsetTotal_GradeFormula($pset, ($f & 1) !== 0, ($f & 2) !== 0);
            } else if (($ge = $pset->gradelike_by_key($gkey))) {
                return $this->parse_grade_entry($ge);
            } else {
                $this->error_at($this->state->pos2 - strlen($gkey), $this->state->pos2, "Undefined grade entry.");
                return null;
            }
        } else if ($this->conf->pset_category($pkey)
                   && ($f = self::$total_gkeys[$gkey] ?? -1) >= 0) {
            if (!$this->conf->pset_category_has_extra($pkey)) {
                $f &= ~1;
            }
            return new PsetCategoryTotal_GradeFormula($pkey, ($f & 1) !== 0, ($f & 4) !== 4);
        } else {
            $this->error_at($this->state->pos1, $this->state->pos1 + strlen($pkey), "Undefined problem set.");
            return null;
        }
    }

    /** @param string $gkey
     * @param bool $no_local
     * @return ?GradeFormula */
    private function parse_grade_word($gkey, $no_local) {
        if ($this->state->context
            && !$no_local
            && ($ge = $this->state->context->pset->gradelike_by_key($gkey))
            && $ge !== $this->state->context) {
            return $this->parse_grade_entry($ge);
        } else if (($gf = $this->conf->formula_by_name($gkey))) {
            $e = $gf->formula();
            if (!$e) {
                $this->error_at($this->state->pos1, $this->state->pos2, "References invalid formula.");
            }
            return $e;
        } else if (($pset = $this->conf->pset_by_key_or_title($gkey))) {
            return new PsetTotal_GradeFormula($pset, false, false);
        } else if ($this->conf->pset_category($gkey)) {
            return new PsetCategoryTotal_GradeFormula($gkey, false, true);
        } else {
            $this->error_at($this->state->pos1, $this->state->pos2, "Undefined problem set or category.");
            return null;
        }
    }

    /** @param string &$t
     * @param int $minprec
     * @return ?GradeFormula */
    private function parse_prefix(&$t, $minprec) {
        $t = ltrim($t);

        if ($t === "") {
            $this->error_near($t, "Expression missing.");
            $e = null;
        } else if ($t[0] === "(") {
            $t = substr($t, 1);
            $e = $this->parse_prefix($t, self::MIN_PRECEDENCE);
            if ($e !== null) {
                $t = ltrim($t);
                if ($t === "" || $t[0] !== ")") {
                    return $e;
                }
                $t = substr($t, 1);
            }
        } else if ($t[0] === "-" || $t[0] === "+") {
            $op = $t[0];
            $t = substr($t, 1);
            $e = $this->parse_prefix($t, self::UNARY_PRECEDENCE);
            if ($e !== null && $op === "-") {
                $e = new Unary_GradeFormula("neg", $e);
            }
        } else if (preg_match('/\A(\d+\.?\d*|\.\d+)(.*)\z/s', $t, $m)) {
            $t = $m[2];
            $e = new Number_GradeFormula((float) $m[1]);
        } else if (preg_match('/\A(?:pi|π|m_pi)\b(.*)\z/si', $t, $m)) {
            $t = $m[1];
            $e = new Number_GradeFormula((float) M_PI);
        } else if (preg_match('/\A(log10|log|ln|lg|exp)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            $e = $this->parse_prefix($t, self::UNARY_PRECEDENCE);
            if ($e !== null) {
                $e = new Unary_GradeFormula($m[1], $e);
            }
        } else if (preg_match('/\A(min|max)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            $e = $this->parse_prefix($t, self::UNARY_PRECEDENCE);
            if ($e !== null) {
                $e = new MinMax_GradeFormula($m[1], $e);
            }
        } else if (preg_match('/\A(\w+)\s*\.\s*(\w+)(.*)\z/s', $t, $m)) {
            $this->set_landmark_near($t, $m[3]);
            $t = $m[3];
            $e = $this->parse_grade_pair($m[1], $m[2]);
        } else if (preg_match('/\A(\w+)(.*)\z/s', $t, $m)) {
            $this->set_landmark_near($t, $m[2]);
            $t = $m[2];
            $e = $this->parse_grade_word($m[1], false);
        } else {
            $this->error_near($t, "Syntax error.");
            $e = null;
        }

        if ($e === null) {
            return null;
        }

        while (true) {
            $t = ltrim($t);
            if (preg_match('/\A(\+\??|-|\*\*?|\/|%|\?\??|\|\||\&\&|==|<=?|>=?|!=|,|:)(.*)\z/s', $t, $m)) {
                $op = $m[1];
                $oppos = strlen($this->state->str) - strlen($t);
                $prec = self::$precedences[$op];
                if ($prec < $minprec) {
                    return $e;
                } else if ($op === ":") {
                    $this->error_near($t, "Syntax error.");
                    return null;
                }
                $t = $m[2];
                $e2 = $this->parse_prefix($t, $op === "**" ? $prec : $prec + 1);
                if ($e2 === null) {
                    return null;
                }
                if (in_array($op, ["+?", "??", "||", "&&"])) {
                    $e = new NullableBin_GradeFormula($op, $e, $e2);
                } else if (in_array($op, ["<", "==", ">", "<=", ">=", "!="])) {
                    $e = new Relation_GradeFormula($op, $e, $e2);
                } else if ($op === ",") {
                    if ($e instanceof Comma_GradeFormula) {
                        $e->add_arg($e2);
                    } else {
                        $e = new Comma_GradeFormula($e, $e2, $oppos);
                    }
                } else if ($op === "?") {
                    if (!preg_match('/\A\s*:(.*)\z/s', $t, $m)) {
                        $this->error_near($t, "Missing “:”.");
                        return null;
                    }
                    $e3 = $this->parse_prefix($m[1], $prec);
                    if (!$e3) {
                        return null;
                    }
                    $t = $m[1];
                    $e = new Ternary_GradeFormula($e, $e2, $e3);
                } else {
                    $e = new Bin_GradeFormula($op, $e, $e2);
                }
            } else {
                return $e;
            }
        }
    }

    /** @param ?string $s
     * @param ?GradeEntryConfig $context
     * @param ?string $ident
     * @return ?GradeFormula */
    function parse($s, GradeEntryConfig $context = null, $ident = null) {
        if ($s === null) {
            return null;
        }
        $oldstate = $this->state;
        $this->state = new GradeFormulaCompilerState($s, $context, $ident);
        $e = $this->parse_prefix($s, self::MIN_PRECEDENCE);
        if ($e === null) {
            // skip
        } else if (trim($s) !== "") {
            $this->error_near($s, "Syntax error.");
            $e = null;
        } else if ($e instanceof Comma_GradeFormula) {
            $this->error_at($e->oppos, $e->oppos, "Syntax error.");
            $e = null;
        }
        $this->state = $oldstate;
        return $e;
    }

    function check_all() {
        foreach ($this->conf->psets() as $pset) {
            if ($pset->has_formula) {
                foreach ($pset->grades() as $ge) {
                    if ($ge->formula) {
                        $this->parse($ge->formula, $ge, "{$pset->nonnumeric_key}.{$ge->key}");
                    }
                }
            }
        }
        foreach ($this->conf->formulas() as $f) {
            $this->parse($f->formula, null, "global.{$f->name}");
        }
    }
}
