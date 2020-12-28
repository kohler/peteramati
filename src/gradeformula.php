<?php
// gradeformula.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

abstract class GradeFormula implements JsonSerializable {
    /** @var string */
    protected $_op;
    /** @var list<GradeFormula> */
    protected $_a;

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

    /** @param string $op
     * @param list<GradeFormula> $a */
    function __construct($op, $a) {
        $this->_op = $op;
        $this->_a = $a;
    }

    static function parse_prefix(Conf $conf, &$t, $minprec) {
        $t = ltrim($t);

        if ($t === "") {
            $e = null;
        } else if ($t[0] === "(") {
            $t = substr($t, 1);
            $e = self::parse_prefix($conf, $t, self::MIN_PRECEDENCE);
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
            $e = self::parse_prefix($conf, $t, self::UNARY_PRECEDENCE);
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
            $e = self::parse_prefix($conf, $t, self::UNARY_PRECEDENCE);
            if ($e !== null) {
                $e = new Unary_GradeFormula($m[1], $e);
            }
        } else if (preg_match('/\A(min|max)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            $e = self::parse_prefix($conf, $t, self::UNARY_PRECEDENCE);
            if ($e !== null) {
                $e = new MinMax_GradeFormula($m[1], $e);
            }
        } else if (preg_match('/\A(\w+)\s*\.\s*(\w+)(.*)\z/s', $t, $m)) {
            if (($pset = $conf->pset_by_key_or_title($m[1]))) {
                if ($m[2] === "total") {
                    $t = $m[3];
                    $e = new PsetTotal_GradeFormula($pset, false, false);
                } else if (($ge = $pset->gradelike_by_key($m[2]))) {
                    $t = $m[3];
                    $e = new GradeEntry_GradeFormula($pset, $ge);
                } else {
                    return null;
                }
            } else {
                return null;
            }
        } else if (preg_match('/\A(\w+)(.*)\z/s', $t, $m)) {
            $t = $m[2];
            $k = $m[1];
            $kbase = $k;
            $noextra = false;
            $raw = null;
            while (true) {
                if (str_ends_with($kbase, "_noextra")) {
                    $kbase = substr($kbase, 0, -8);
                    $noextra = true;
                } else if (str_ends_with($kbase, "_raw") && $raw === null) {
                    $kbase = substr($kbase, 0, -4);
                    $raw = true;
                } else if (str_ends_with($kbase, "_norm") && $raw === null) {
                    $kbase = substr($kbase, 0, -5);
                    $raw = false;
                } else {
                    break;
                }
            }
            if (($pset = $conf->pset_by_key_or_title($kbase))) {
                $noextra = $noextra && $pset->has_extra;
                $raw = $raw === null || $raw;
                $e = new PsetTotal_GradeFormula($pset, $noextra, $raw);
            } else if (($gf = $conf->formula_by_name($k))) {
                $e = $gf->formula();
            } else if ($conf->pset_category($kbase)) {
                $noextra = $noextra && $conf->pset_category_has_extra($kbase);
                $raw = $raw !== null && $raw;
                $e = new PsetCategoryTotal_GradeFormula($kbase, $noextra, $raw);
            } else {
                return null;
            }
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
                $e2 = self::parse_prefix($conf, $t, $op === "**" ? $prec : $prec + 1);
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
                        && ($e3 = self::parse_prefix($conf, $m[1], $prec))) {
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
    static function parse(Conf $conf, $s) {
        $sin = $s;
        if ($s !== null
            && ($f = self::parse_prefix($conf, $s, self::MIN_PRECEDENCE)) !== null
            && !($f instanceof Comma_GradeFormula)
            && trim($s) === "") {
            return $f;
        } else {
            return null;
        }
    }

    abstract function evaluate(Contact $student);

    function jsonSerialize() {
        $x = [$this->_op];
        foreach ($this->_a as $a) {
            $x[] = is_number($a) ? $a : $a->jsonSerialize();
        }
        return $x;
    }
}

class Unary_GradeFormula extends GradeFormula {
    function __construct($op, $e) {
        parent::__construct($op, [$e]);
    }
    function evaluate(Contact $student) {
        if (($v0 = $this->_a[0]->evaluate($student)) === null) {
            return null;
        }
        switch ($this->_op) {
        case "neg":
            return -$v0;
        case "log":
        case "log10":
            return log10($v0);
        case "ln":
            return log($v0);
        case "lg":
            return log($v0) / log(2);
        case "exp":
            return exp($v0);
        }
    }
}

class Bin_GradeFormula extends GradeFormula {
    function __construct($op, $e1, $e2) {
        parent::__construct($op, [$e1, $e2]);
    }
    function evaluate(Contact $student) {
        if (($v0 = $this->_a[0]->evaluate($student)) === null
            || ($v1 = $this->_a[1]->evaluate($student)) === null) {
            return null;
        }
        switch ($this->_op) {
        case "+":
            return $v0 + $v1;
        case "-":
            return $v0 - $v1;
        case "*":
            return $v0 * $v1;
        case "/":
            return $v0 / $v1;
        case "%":
            return $v0 % $v1;
        case "**":
            return $v0 ** $v1;
        }
    }
}

class Relation_GradeFormula extends GradeFormula {
    function __construct($op, $e1, $e2) {
        parent::__construct($op, [$e1, $e2]);
    }
    function evaluate(Contact $student) {
        $v0 = $this->_a[0]->evaluate($student);
        $v1 = $this->_a[1]->evaluate($student);
        switch ($this->_op) {
        case "==":
            return $v0 == $v1;
        case "!=":
            return $v0 != $v1;
        case "<":
            return $v0 < $v1;
        case "<=":
            return $v0 <= $v1;
        case ">":
            return $v0 > $v1;
        case ">=":
            return $v0 >= $v1;
        }
    }
}

class NullableBin_GradeFormula extends GradeFormula {
    function __construct($op, $e1, $e2) {
        parent::__construct($op, [$e1, $e2]);
    }
    function evaluate(Contact $student) {
        $v0 = $this->_a[0]->evaluate($student);
        $v1 = $this->_a[1]->evaluate($student);
        switch ($this->_op) {
        case "+?":
            if ($v0 === null && $v1 === null) {
                return null;
            } else {
                return (float) $v0 + (float) $v1;
            }
        case "??":
            return $v0 ?? $v1;
        case "&&":
            return $v0 ? $v1 : $v0;
        case "||":
            return $v0 ? : $v1;
        }
    }
}

class Ternary_GradeFormula extends GradeFormula {
    function __construct($ec, $et, $ef) {
        parent::__construct("?:", [$ec, $et, $ef]);
    }
    function evaluate(Contact $student) {
        $v0 = $this->_a[0]->evaluate($student);
        return $this->_a[$v0 ? 1 : 2]->evaluate($student);
    }
}

class Comma_GradeFormula extends GradeFormula {
    function __construct($el, $er) {
        parent::__construct(",", [$el, $er]);
    }
    function add_arg($e) {
        $this->_a[] = $e;
    }
    function args() {
        return $this->_a;
    }
    function evaluate(Contact $student) {
        return null;
    }
}

abstract class Function_GradeFormula extends GradeFormula {
    function __construct($op, $arge) {
        parent::__construct($op, $arge instanceof Comma_GradeFormula ? $arge->args() : [$arge]);
    }
}

class MinMax_GradeFormula extends Function_GradeFormula {
    function __construct($op, $arge) {
        parent::__construct($op, $arge);
    }
    function evaluate(Contact $student) {
        $cur = null;
        $ismax = $this->_op === "max";
        foreach ($this->_a as $e) {
            $v = $e->evaluate($student);
            if ($v !== null
                && ($cur === null
                    || ($ismax ? $cur < $v : $cur > $v))) {
                $cur = $v;
            }
        }
        return $cur;
    }
}

class Number_GradeFormula extends GradeFormula {
    /** @var float */
    private $v;

    function __construct($v)  {
        parent::__construct("n", []);
        $this->v = $v;
    }
    function evaluate(Contact $student) {
        return $this->v;
    }
    function jsonSerialize() {
        return $this->v;
    }
}

class GradeEntry_GradeFormula extends GradeFormula {
    /** @var Pset */
    private $pset;
    /** @var GradeEntryConfig */
    private $ge;

    function __construct($pset, $ge) {
        parent::__construct("g", []);
        $this->pset = $pset;
        $this->ge = $ge;
    }
    function evaluate(Contact $student) {
        return (float) $student->gcache_entry($this->pset, $this->ge);
    }
    function jsonSerialize() {
        return $this->pset->nonnumeric_key . "." . $this->ge->key;
    }
}

class PsetTotal_GradeFormula extends GradeFormula {
    /** @var Pset */
    private $pset;
    /** @var bool */
    private $noextra;
    /** @var bool */
    private $norm;

    function __construct($pset, $noextra, $norm) {
        parent::__construct("gpt", []);
        $this->pset = $pset;
        $this->noextra = $noextra;
        $this->norm = $norm;
    }
    function evaluate(Contact $student) {
        return $student->gcache_total($this->pset, $this->noextra, $this->norm);
    }
    function jsonSerialize() {
        return $this->pset->nonnumeric_key . ($this->noextra ? "_noextra" : "") . ($this->norm ? "" : "_norm");
    }
}

class PsetCategoryTotal_GradeFormula extends GradeFormula {
    /** @var string */
    private $category;
    /** @var bool */
    private $noextra;
    /** @var bool */
    private $norm;

    function __construct($category, $noextra, $norm) {
        parent::__construct("ggt", []);
        $this->category = $category;
        $this->noextra = $noextra;
        $this->norm = $norm;
    }
    function evaluate(Contact $student) {
        return $student->gcache_category_total($this->category, $this->noextra, $this->norm);
    }
    function jsonSerialize() {
        return $this->category . ($this->noextra ? "_noextra" : "") . ($this->norm ? "_raw" : "");
    }
}
