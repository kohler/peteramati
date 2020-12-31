<?php
// gradeformulacompiler.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

require_once(SiteLoader::$root . "/src/gradeformula.php");

class GradeFormulaCompiler {
    /** @var Conf */
    public $conf;
    /** @var ?GradeEntryConfig */
    public $context;
    /** @var list<string> */
    public $errors = [];

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


    function __construct(Conf $conf, GradeEntryConfig $context = null) {
        $this->conf = $conf;
        $this->context = $context;
    }

    /** @return ?GradeFormula */
    private function parse_grade_entry(GradeEntryConfig $ge) {
        if ($ge->formula) {
            return $ge->formula();
        } else {
            return new GradeEntry_GradeFormula($ge);
        }
    }

    /** @param string $pkey
     * @param string $gkey
     * @return ?GradeFormula */
    private function parse_grade_pair($pkey, $gkey) {
        if ($pkey === "self" && $this->context) {
            $pset = $this->context->pset;
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
                return null;
            }
        } else if ($this->conf->pset_category($pkey)
                   && ($f = self::$total_gkeys[$gkey] ?? -1) >= 0) {
            if (!$this->conf->pset_category_has_extra($pkey)) {
                $f &= ~1;
            }
            return new PsetCategoryTotal_GradeFormula($pkey, ($f & 1) !== 0, ($f & 4) !== 4);
        } else {
            return null;
        }
    }

    /** @param string $gkey
     * @param bool $no_local
     * @return ?GradeFormula */
    private function parse_grade_word($gkey, $no_local) {
        if ($this->context
            && !$no_local
            && ($ge = $this->context->pset->gradelike_by_key($gkey))
            && $ge !== $this->context) {
            return $this->parse_grade_entry($ge);
        } else if (($gf = $this->conf->formula_by_name($gkey))) {
            return $gf->formula();
        } else if (($pset = $this->conf->pset_by_key_or_title($gkey))) {
            return new PsetTotal_GradeFormula($pset, false, false);
        } else if ($this->conf->pset_category($gkey)) {
            return new PsetCategoryTotal_GradeFormula($gkey, false, true);
        } else {
            return null;
        }
    }

    /** @param string &$t
     * @param int $minprec
     * @param ?GradeEntryConfig $context
     * @return ?GradeFormula */
    private function parse_prefix(&$t, $minprec) {
        $t = ltrim($t);

        if ($t === "") {
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
            $t = $m[3];
            $e = $this->parse_grade_pair($m[1], $m[2]);
        } else if (preg_match('/\A(\w+)(.*)\z/s', $t, $m)) {
            $t = $m[2];
            $e = $this->parse_grade_word($m[1], false);
        } else {
            $e = null;
        }

        if ($e === null) {
            return null;
        }

        while (true) {
            $t = ltrim($t);
            if (preg_match('/\A(\+\??|-|\*\*?|\/|%|\?\??|\|\||\&\&|==|<=?|>=?|!=|,|:)(.*)\z/s', $t, $m)) {
                $op = $m[1];
                $prec = self::$precedences[$op];
                if ($prec < $minprec) {
                    return $e;
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
                        $e = new Comma_GradeFormula($e, $e2);
                    }
                } else if ($op === "?") {
                    if (preg_match('/\A\s*:(.*)\z/s', $t, $m)
                        && ($e3 = $this->parse_prefix($m[1], $prec))) {
                        $t = $m[1];
                        $e = new Ternary_GradeFormula($e, $e2, $e3);
                    } else {
                        return null;
                    }
                } else if ($op === ":") {
                    return null;
                } else {
                    $e = new Bin_GradeFormula($op, $e, $e2);
                }
            } else {
                return $e;
            }
        }
    }

    /** @param ?string $s
     * @return ?GradeFormula */
    function parse($s) {
        $sin = $s;
        if ($s !== null
            && ($f = $this->parse_prefix($s, self::MIN_PRECEDENCE)) !== null
            && !($f instanceof Comma_GradeFormula)
            && trim($s) === "") {
            return $f;
        } else {
            error_log("failure parsing formula $sin at $s");
            return null;
        }
    }
}
