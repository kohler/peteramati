<?php
// gradeformulacompiler.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2021 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

require_once(SiteLoader::$root . "/src/gradeformula.php");

class GradeFormulaCompilerState {
    /** @var ?GradeEntry */
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
     * @param ?GradeEntry $context
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
    /** @var MessageSet */
    public $ms;
    /** @var GradeFormulaCompilerState */
    private $state;

    /** @readonly */
    static public $precedences = [
        "**" => 13,
        "*" => 11, "/" => 11, "%" => 11,
        "+" => 10, "-" => 10, "+?" => 10,
        "<" => 8, "<=" => 8, ">" => 8, ">=" => 8,
        "==" => 7, "!=" => 7,
        "&&" => 3,
        "||" => 2,
        "??" => 1,
        "?" => 0, ":" => 0
    ];
    const UNARY_PRECEDENCE = 12;
    const MIN_PRECEDENCE = -1;

    /** @readonly */
    static public $synonyms = [
        "=" => "==", "≠" => "!=", "≤" => "<=", "≥" => ">="
    ];

    static public $total_gkeys = [
        "total" => 0, "total_noextra" => 1,
        "total_norm" => 2, "total_noextra_norm" => 3, "total_norm_noextra" => 3,
        "total_raw" => 4, "total_noextra_raw" => 5, "total_raw_noextra" => 5
    ];


    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->ms = new MessageSet;
    }

    /** @param int $pos1
     * @param int $pos2
     * @param string $msg */
    private function error_at($pos1, $pos2, $msg) {
        $mi = $this->ms->error_at(null, "{$msg} in formula");
        $mi->landmark = $this->state->ident;
        $mi->pos1 = $pos1;
        $mi->pos2 = $pos2;
        $mi->context = $this->state->str;
    }

    /** @param int $pos
     * @param string $msg */
    private function error_near($pos, $msg) {
        $this->error_at($pos, $pos, $msg);
    }

    /** @return ?GradeFormula */
    private function parse_grade_entry(GradeEntry $ge) {
        if ($ge->is_formula()) {
            $e = $ge->formula();
            if (!$e || $e instanceof Error_GradeFormula) {
                $this->error_at($this->state->pos1, $this->state->pos2, "<0>References invalid formula");
            }
            return $e;
        } else {
            return new GradeEntry_GradeFormula($ge);
        }
    }

    /** @param Pset $pset
     * @param string $gkey
     * @param ?GradeEntry $context
     * @return ?GradeFormula */
    private function parse_pset_grade($pset, $gkey, $context) {
        if (($f = self::$total_gkeys[$gkey] ?? -1) >= 0) {
            return new PsetTotal_GradeFormula($pset, ($f & 1) !== 0, ($f & 2) !== 0);
        } else if (($ge = $pset->gradelike_by_key($gkey))
                   && $ge !== $context) {
            return $this->parse_grade_entry($ge);
        } else {
            return null;
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
            if (($gf = $this->parse_pset_grade($pset, $gkey, null))) {
                return $gf;
            } else {
                $this->error_at($this->state->pos2 - strlen($gkey), $this->state->pos2, "<0>Undefined grade entry");
                return null;
            }
        } else if ($this->conf->pset_category($pkey)
                   && ($f = self::$total_gkeys[$gkey] ?? -1) >= 0) {
            if (!$this->conf->pset_category_has_extra($pkey)) {
                $f &= ~1;
            }
            return new CategoryTotal_GradeFormula($this->conf, $pkey, ($f & 1) !== 0, ($f & 4) !== 4);
        } else {
            $this->error_at($this->state->pos1, $this->state->pos1 + strlen($pkey), "<0>Undefined problem set");
            return null;
        }
    }

    /** @param string $gkey
     * @param bool $no_local
     * @return ?GradeFormula */
    private function parse_grade_word($gkey, $no_local) {
        if ($this->state->context
            && !$no_local
            && ($gf = $this->parse_pset_grade($this->state->context->pset, $gkey, $this->state->context))) {
            return $gf;
        } else if (($gf = $this->conf->formula_by_name($gkey))) {
            $e = $gf->formula();
            if (!$e || $e instanceof Error_GradeFormula) {
                $this->error_at($this->state->pos1, $this->state->pos2, "<0>References invalid formula");
            }
            return $e;
        } else if (($pset = $this->conf->pset_by_key_or_title($gkey))) {
            return new PsetTotal_GradeFormula($pset, false, false);
        } else if ($this->conf->pset_category($gkey)) {
            return new CategoryTotal_GradeFormula($this->conf, $gkey, false, true);
        } else {
            $this->error_at($this->state->pos1, $this->state->pos2, "<0>Undefined problem set or category");
            return null;
        }
    }

    /** @param int $p
     * @return int */
    private function skip_space($p) {
        $s = $this->state->str;
        $l = strlen($s);
        while ($p < $l && ctype_space($s[$p])) {
            ++$p;
        }
        return $p;
    }

    /** @param int $p
     * @param ?int $min
     * @param ?int $max
     * @return array{?GradeFormula,int} */
    private function parse_arguments(Function_GradeFormula $fe, $p, $min = null, $max = null) {
        $s = $this->state->str;
        $p0 = $p = $this->skip_space($p);

        if ($p === strlen($s)) {
            $this->error_near($p, "<0>Expression missing");
            return [null, $p];
        } else if ($s[$p] === "(") {
            $p = $this->skip_space($p + 1);
            if ($p === strlen($s) || $s[$p] !== ")") {
                while (true) {
                    list($e, $p) = $this->parse_prefix($p, self::MIN_PRECEDENCE);
                    if ($e === null) {
                        return [null, $p];
                    }
                    $fe->add_arg($e);
                    $p = $this->skip_space($p);
                    if ($p === strlen($s) || $s[$p] !== ",") {
                        break;
                    }
                    ++$p;
                }
            }
            if ($p === strlen($s) || $s[$p] !== ")") {
                $this->error_near($p, "<0>Missing “)”");
                return [null, $p];
            }
            ++$p;
        } else {
            list($e, $p) = $this->parse_prefix($p, self::UNARY_PRECEDENCE);
            if (!$e) {
                return [null, $p];
            }
            $fe->add_arg($e);
        }

        if ($min !== null && $fe->nargs() < $min) {
            $this->error_at($p0, $p, "<0>Too few arguments");
            return [null, $p];
        } else if ($max !== null && $fe->nargs() > $max) {
            $this->error_at($p0, $p, "<0>Too many arguments");
            return [null, $p];
        } else {
            return [$fe, $p];
        }
    }

    /** @param int $p
     * @param int $minprec
     * @return array{?GradeFormula,int} */
    private function parse_prefix($p, $minprec) {
        $s = $this->state->str;
        $p = $this->skip_space($p);

        if ($p === strlen($s)) {
            $this->error_near($p, "<0>Expression missing");
            $e = null;
        } else if ($s[$p] === "(") {
            list($e, $p) = $this->parse_prefix($p + 1, self::MIN_PRECEDENCE);
            if ($e !== null) {
                $p = $this->skip_space($p);
                if ($p !== strlen($s) && $s[$p] === ")") {
                    ++$p;
                } else {
                    $this->error_near($p, "<0>Missing “)”");
                    $e = null;
                }
            }
        } else if ($s[$p] === "+") {
            list($e, $p) = $this->parse_prefix($p + 1, self::UNARY_PRECEDENCE);
        } else if ($s[$p] === "-") {
            list($e, $p) = $this->parse_prefix($p + 1, self::UNARY_PRECEDENCE);
            $e = $e ? new Unary_GradeFormula("neg", $e) : null;
        } else if ($s[$p] === "!") {
            list($e, $p) = $this->parse_prefix($p + 1, self::UNARY_PRECEDENCE);
            $e = $e ? new Not_GradeFormula($e) : null;
        } else if (preg_match('/\G(?:\d+\.?\d*|\.\d+)/s', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            $e = new Constant_GradeFormula((float) $m[0]);
        } else if (preg_match('/\G(?:pi|π|m_pi)\b/si', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            $e = new Constant_GradeFormula((float) M_PI);
        } else if (preg_match('/\Gnull\b/si', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            $e = new Null_GradeFormula;
        } else if (preg_match('/\G(?:false|true)\b/si', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            $e = new Constant_GradeFormula($m[0] === "true");
        } else if (preg_match('/\Gnow\b/si', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            $e = new Now_GradeFormula;
        } else if (preg_match('/\G(?:log10|log|ln|lg|exp)\b/s', $s, $m, 0, $p)) {
            list($e, $p) = $this->parse_prefix($p + strlen($m[0]), self::UNARY_PRECEDENCE);
            if ($e !== null) {
                $e = new Unary_GradeFormula($m[0], $e);
            }
        } else if (preg_match('/\G(?:min|max)\b/s', $s, $m, 0, $p)) {
            list($e, $p) = $this->parse_arguments(new MinMax_GradeFormula($m[0]), $p + strlen($m[0]));
        } else if (preg_match('/\Grank\b/s', $s, $m, 0, $p)) {
            list($e, $p) = $this->parse_arguments(new Rank_GradeFormula, $p + strlen($m[0]), 1, 1);
            $e = $e ? $e->canonicalize($this->conf) : null;
        } else if (preg_match('/\G@[2-9]\d\d\d(?:[-\/]\d\d?[-\/]\d\d?|\d{4})(?:[-T:]\d\d?:?\d\d?(?::\d\d|)[-+:A-Z0-9]*|)\b/s', $s, $m, 0, $p)) {
            if (ctype_digit(substr($m[0], 1)) && strlen($m[0]) >= 10) {
                $e = new Time_GradeFormula(intval(substr($m[0], 1)));
            } else if (($t = date_create_immutable(substr($m[0], 1)))) {
                $e = new Time_GradeFormula($t->getTimestamp());
            } else {
                $this->error_near($p, "<0>Syntax error");
                $e = null;
            }
            $p += strlen($m[0]);
        } else if (preg_match('/\G(\w+)\s*\.\s*(\w+)/s', $s, $m, 0, $p)) {
            $this->state->pos1 = $p;
            $p = $this->state->pos2 = $p + strlen($m[0]);
            $e = $this->parse_grade_pair($m[1], $m[2]);
        } else if (preg_match('/\G\w+/s', $s, $m, 0, $p)) {
            $this->state->pos1 = $p;
            $p = $this->state->pos2 = $p + strlen($m[0]);
            $e = $this->parse_grade_word($m[0], false);
        } else {
            $this->error_near($p, "<0>Syntax error");
            $e = null;
        }

        if ($e === null) {
            return [null, $p];
        }

        while (true) {
            $p = $this->skip_space($p);
            if (preg_match('/\G(?:\+\??|-|\*\*?|\/|%|\?\??|\|\||\&\&|==?|<=?|>=?|!=|≠|≤|≥|:)/s', $s, $m, 0, $p)) {
                $op = self::$synonyms[$m[0]] ?? $m[0];
                $prec = self::$precedences[$op];
                if ($prec < $minprec) {
                    return [$e, $p];
                } else if ($op === ":") {
                    $this->error_near($p, "<0>Syntax error");
                    return [null, $p];
                }
                list($e2, $p) = $this->parse_prefix($p + strlen($m[0]), $op === "**" ? $prec : $prec + 1);
                if ($e2 === null) {
                    return [null, $p];
                }
                if (in_array($op, ["+?", "??", "||", "&&"])) {
                    $e = new NullableBin_GradeFormula($op, $e, $e2);
                } else if (in_array($op, ["<", "=", "==", ">", "<=", ">=", "!=", "≠", "≤", "≥"])) {
                    $e = new Relation_GradeFormula($op, $e, $e2);
                } else if ($op === "?") {
                    if (!preg_match('/\G\s*:/s', $s, $m, 0, $p)) {
                        $this->error_near($p, "<0>Missing “:”");
                        return [null, $p];
                    }
                    list($e3, $p) = $this->parse_prefix($p + strlen($m[0]), $prec);
                    if (!$e3) {
                        return [null, $p];
                    }
                    $e = new Ternary_GradeFormula($e, $e2, $e3);
                } else {
                    $e = new Bin_GradeFormula($op, $e, $e2);
                }
            } else {
                return [$e, $p];
            }
        }
    }

    /** @param ?string $s
     * @param ?GradeEntry $context
     * @param ?string $ident
     * @return ?GradeFormula */
    function parse($s, ?GradeEntry $context = null, $ident = null) {
        if ($s === null) {
            return null;
        }
        $oldstate = $this->state;
        $this->state = new GradeFormulaCompilerState($s, $context, $ident);
        list($e, $p) = $this->parse_prefix(0, self::MIN_PRECEDENCE);
        if ($e === null) {
            // skip
        } else if ($this->skip_space($p) !== strlen($s)) {
            $this->error_near($p, "<0>Syntax error");
            $e = null;
        }
        $this->state = $oldstate;
        return $e;
    }

    function check_all() {
        foreach ($this->conf->psets() as $pset) {
            if (!$pset->disabled && $pset->has_formula) {
                foreach ($pset->grades() as $ge) {
                    if ($ge->is_formula()) {
                        $this->parse($ge->formula_expression(), $ge, "{$pset->nonnumeric_key}.{$ge->key}");
                    }
                }
            }
        }
        foreach ($this->conf->global_formulas() as $i => $f) {
            $name = $f->name ?? "\$g$i";
            $this->parse($f->formula_expression(), null, "global.{$name}");
        }
    }
}
