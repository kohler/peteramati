<?php
// gradeformulacompiler.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2024 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

require_once(SiteLoader::$root . "/src/gradeformula.php");

class GradeFormulaCompilerState {
    /** @var string */
    public $str;
    /** @var int */
    public $pos1;
    /** @var int */
    public $pos2;
    /** @var ?string */
    public $ident;
    /** @var ?Pset */
    public $pset;
    /** @var ?GradeEntry */
    public $entry;

    /** @param string $str
     * @param ?string $ident */
    function __construct($str, $ident) {
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
        if (!$ge->is_formula()) {
            return new GradeEntry_GradeFormula($ge);
        }
        $e = $ge->formula();
        if (!$e || $e instanceof Error_GradeFormula) {
            $this->error_at($this->state->pos1, $this->state->pos2, "<0>References invalid formula");
        }
        return $e;
    }

    /** @param Pset $pset
     * @param string $gkey
     * @param ?GradeEntry $self
     * @return ?GradeFormula */
    private function parse_pset_grade($pset, $gkey, $self) {
        if (($f = self::$total_gkeys[$gkey] ?? -1) >= 0) {
            return new PsetTotal_GradeFormula($pset, $f & 3);
        } else if (($ge = $pset->gradelike_by_key_or_title($gkey)) && $ge !== $self) {
            return $this->parse_grade_entry($ge);
        } else {
            return null;
        }
    }

    /** @param string $pkey
     * @param string $gkey
     * @return ?GradeFormula */
    private function parse_grade_pair($pkey, $gkey) {
        if ($pkey === "self" && $this->state->pset) {
            $pset = $this->state->pset;
        } else if ($pkey === "global") {
            return $this->parse_grade_word($gkey, true);
        } else {
            $pset = $this->conf->pset_by_key_or_title($pkey);
        }
        if ($pset) {
            if (($gf = $this->parse_pset_grade($pset, $gkey, null))) {
                return $gf;
            }
            $this->error_at($this->state->pos2 - strlen($gkey), $this->state->pos2, "<0>Grade entry `{$gkey}` in `{$pset->title}` not found");
            return null;
        }
        if (($cat = $this->conf->category($pkey))
            && ($f = self::$total_gkeys[$gkey] ?? -1) >= 0) {
            if (!$cat->has_extra) {
                $f &= ~1;
            }
            return new CategoryTotal_GradeFormula($this->conf, $cat, $f);
        }
        $this->error_at($this->state->pos1, $this->state->pos1 + strlen($pkey), "<0>Problem set `{$pkey}` not found");
        return null;
    }

    /** @param string $gkey
     * @param bool $no_local
     * @return ?GradeFormula */
    private function parse_grade_word($gkey, $no_local) {
        if ($this->state->pset
            && !$no_local
            && ($gf = $this->parse_pset_grade($this->state->pset, $gkey, $this->state->entry))) {
            return $gf;
        }
        if (($gf = $this->conf->formula_by_name($gkey))) {
            $e = $gf->formula();
            if (!$e || $e instanceof Error_GradeFormula) {
                $this->error_at($this->state->pos1, $this->state->pos2, "<0>References invalid formula");
            }
            return $e;
        }
        $tf = 0;
        while (strpos($gkey, "_") !== false) {
            if (str_ends_with($gkey, "_noextra")) {
                $tf |= GradeFormula::TOTAL_NOEXTRA;
                $gkey = substr($gkey, 0, -8);
            } else if (str_ends_with($gkey, "_norm")) {
                $tf |= GradeFormula::TOTAL_NORM;
                $gkey = substr($gkey, 0, -5);
            } else if (str_ends_with($gkey, "_raw")) {
                $tf |= GradeFormula::TOTAL_RAW;
                $gkey = substr($gkey, 0, -4);
            } else {
                break;
            }
        }
        if (($pset = $this->conf->pset_by_key_or_title($gkey))) {
            return new PsetTotal_GradeFormula($pset, $tf);
        } else if (($cat = $this->conf->category($gkey))) {
            return new CategoryTotal_GradeFormula($this->conf, $cat, $tf);
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
     * @return array{?GradeFormula,int} */
    private function parse_arguments(Function_GradeFormula $fe, $p) {
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

        if ($fe->complete($this, $p0, $p)) {
            return [$fe, $p];
        } else {
            return [null, $p];
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
        } else if (preg_match('/\G(?:\d+\.?\d*|\.\d+)(?!\w)/s', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            $e = new Constant_GradeFormula((float) $m[0]);
        } else if (preg_match('/\G(?:[A-D](?![-+\w])|[A-D][-+]|[A-D]–|F(?!\w))/s', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            $e = new Constant_GradeFormula(GradeEntry::parse_letter_value($m[0]), GradeEntry::VTLETTER);
        } else if (preg_match('/\G(?:pi|π|m_pi)\b/si', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            $e = new Constant_GradeFormula((float) M_PI);
        } else if (preg_match('/\Gnull\b/si', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            $e = new Constant_GradeFormula(null);
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
        } else if (preg_match('/\Grlookup\b/s', $s, $m, 0, $p)) {
            list($e, $p) = $this->parse_arguments(new Rlookup_GradeFormula, $p + strlen($m[0]));
        } else if (preg_match('/\Grank\b/s', $s, $m, 0, $p)) {
            list($e, $p) = $this->parse_arguments(new Rank_GradeFormula, $p + strlen($m[0]));
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
            if (preg_match('/\G(?:\+\??|-|\*\*?|\/|%|\?\??|\|\||\&\&|\^\^|==?|<=?|>=?|!=|≠|≤|≥|:)/s', $s, $m, 0, $p)) {
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
                if (in_array($op, ["+?", "??", "||", "&&", "^^"])) {
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
     * @param null|GradeEntry|Pset $context
     * @param ?string $ident
     * @return ?GradeFormula */
    function parse($s, $context = null, $ident = null) {
        if ($s === null) {
            return null;
        }
        $oldstate = $this->state;
        $this->state = new GradeFormulaCompilerState($s, $ident);
        if ($context instanceof Pset) {
            $this->state->pset = $context;
        } else if ($context instanceof GradeEntry) {
            $this->state->pset = $context->pset;
            $this->state->entry = $context;
        }
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

    /** @param ?string $s
     * @param ?Pset $context
     * @return GradeFormula */
    function parse_search($s, $context = null) {
        $sex = (new SearchParser($s))->parse_expression();
        $oldstate = $this->state;
        $this->state = new GradeFormulaCompilerState($s, null);
        $this->state->pset = $context;
        $answer = $sex ? $this->_compile_search($sex, $context) : new Constant_GradeFormula(true);
        $this->state = $oldstate;
        return $answer;
    }

    /** @param SearchExpr $sex
     * @param ?Pset $context
     * @return GradeFormula */
    private function _compile_search($sex, $context) {
        if (!$sex->op) {
            return $this->_compile_search_keyword($sex, $context);
        } else if (($sex->op->flags & SearchOperator::F_NOT) !== 0) {
            if (!$sex->child[0]) {
                return new Constant_GradeFormula(true);
            }
            $gf = $this->_compile_search($sex->child[0], $context);
            return new Not_GradeFormula($gf);
        }
        if (($sex->op->flags & SearchOperator::F_AND) !== 0) {
            $op = "&&";
        } else if (($sex->op->flags & SearchOperator::F_OR) !== 0) {
            $op = "||";
        } else if (($sex->op->flags & SearchOperator::F_XOR) !== 0) {
            $op = "^^";
        } else {
            throw new ErrorException("unknown operator");
        }
        $gf = null;
        foreach ($sex->child as $ch) {
            $chgf = $this->_compile_search($ch, $context);
            $gf = $gf ? new NullableBin_GradeFormula($op, $gf, $chgf) : $chgf;
        }
        return $gf ?? new Constant_GradeFormula(null);
    }

    /** @param SearchExpr $sex
     * @param ?Pset $context
     * @return GradeFormula */
    private function _compile_search_keyword($sex, $context) {
        $kw = $sex->kword;
        $text = $sex->text;
        if (!$kw
            && preg_match('/\A([a-zA-Z][-_.a-zA-Z0-9]*)((?:<=?|>=?|==?|!=?|≤|≥|≠)[^<>=!]*)\z/', $text, $m)) {
            $kw = $m[1];
            $text = $m[2];
        }
        if (!$kw) {
            return $this->_compile_search_name($text);
        }
        if ($kw === "year") {
            return new Year_GradeFormula($text);
        } else if ($kw === "is") {
            return new Is_GradeFormula($text);
        }
        if (($dot = strpos($kw, ".")) !== false) {
            $gf = $this->parse_grade_pair(substr($kw, 0, $dot), substr($kw, $dot + 1));
        } else {
            $gf = $this->parse_grade_word($kw, false);
        }
        if (!$gf) {
            return new Constant_GradeFormula(false);
        }
        if (preg_match('/\A(<=?|>=?|==?|!=?|≤|≥|≠)\s*(.+)\z/', $text, $m)) {
            $op = $m[1];
            $v = $m[2];
        } else {
            $op = "=";
            $v = $text;
        }
        if (preg_match('/\A(?:[-+]?)(?:\d+\.?\d*|\.\d+)\z/', $v)) {
            return new Relation_GradeFormula($op, $gf, new Constant_GradeFormula(floatval($v)));
        }
        return new Constant_GradeFormula(null);
    }

    /** @param string $text
     * @return GradeFormula */
    private function _compile_search_name($text) {
        $text = SearchParser::unquote($text);
        if ($text === "" || $text === "*" || $text === "ANY" || $text === "ALL") {
            return new Constant_GradeFormula(true);
        }
        return new NameMatch_GradeFormula($text);
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
            $name = $f->name ?? "\$g{$i}";
            $this->parse($f->formula_expression(), null, "global.{$name}");
        }
    }
}
