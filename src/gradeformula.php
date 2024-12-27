<?php
// gradeformula.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2024 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

abstract class GradeFormula implements JsonSerializable {
    /** @var string */
    protected $_op;
    /** @var list<GradeFormula> */
    protected $_a;
    /** @var int */
    public $vtype = 0;
    /** @var bool */
    public $cacheable = true;
    /** @var ?string */
    private $_canonid;
    /** @var ?array<int,mixed> */
    protected $_allv;

    const TOTAL_NOEXTRA = 1;
    const TOTAL_NORM = 2;
    const TOTAL_RAW = 4;

    static public $evaluation_stack = [];

    /** @param string $op
     * @param list<GradeFormula> $a */
    function __construct($op, $a) {
        $this->_op = $op;
        $this->_a = $a;
    }

    abstract function evaluate(Contact $student, ?PsetView $info);

    /** @return ?Constant_GradeFormula */
    function const_evaluate() {
        return null;
    }

    /** @param list<string> &$v */
    function export_grade_names(&$v) {
        foreach ($this->_a as $a) {
            $a->export_grade_names($v);
        }
    }

    /** @param list<Pset> &$psets */
    function export_psets(&$psets) {
        foreach ($this->_a as $a) {
            $a->export_psets($psets);
        }
    }

    /** @return array<int,mixed> */
    function compute_all(Conf $conf) {
        if ($this->_allv === null) {
            $this->_allv = [];
            foreach (StudentSet::make_all($conf)->users() as $u) {
                $this->_allv[$u->contactId] = $this->evaluate($u, null);
            }
        }
        return $this->_allv;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $x = [$this->_op];
        foreach ($this->_a as $a) {
            $x[] = is_number($a) ? $a : $a->jsonSerialize();
        }
        return $x;
    }

    /** @return string */
    function canonical_id() {
        if ($this->_canonid === null) {
            $this->_canonid = json_encode($this->jsonSerialize());
        }
        return $this->_canonid;
    }

    /** @return GradeFormula */
    function canonicalize(Conf $conf) {
        return $conf->canonical_formula($this);
    }
}

class Unary_GradeFormula extends GradeFormula {
    /** @param string $op
     * @param GradeFormula $e */
    function __construct($op, $e) {
        parent::__construct($op, [$e]);
    }
    private function vevaluate($v) {
        if ($v === null) {
            return null;
        }
        switch ($this->_op) {
        case "neg":
            return -$v;
        case "log":
        case "log10":
            return $v > 0 ? log10($v) : null;
        case "ln":
            return $v > 0 ? log($v) : null;
        case "lg":
            return $v > 0 ? log($v) / M_LN2 : null;
        case "exp":
            return exp($v);
        }
    }
    function evaluate(Contact $student, ?PsetView $info) {
        $v0 = $this->_a[0]->evaluate($student, $info);
        return $this->vevaluate($v0);
    }
    function constant_evaluate() {
        $cv = $this->_a[0]->constant_evaluate();
        return $cv !== null ? new Constant_GradeFormula($this->vevaluate($cv->v)) : null;
    }
}

class Not_GradeFormula extends GradeFormula {
    /** @param GradeFormula $e */
    function __construct($e) {
        parent::__construct("!", [$e]);
        $this->vtype = GradeEntry::VTBOOL;
    }
    function evaluate(Contact $student, ?PsetView $info) {
        $v0 = $this->_a[0]->evaluate($student, $info);
        return !$v0;
    }
    function constant_evaluate() {
        $cv = $this->_a[0]->constant_evaluate();
        return $cv !== null ? new Constant_GradeFormula(!$cv->v) : null;
    }
}

class Bin_GradeFormula extends GradeFormula {
    /** @param string $op
     * @param GradeFormula $e1
     * @param GradeFormula $e2 */
    function __construct($op, $e1, $e2) {
        parent::__construct($op, [$e1, $e2]);
        if ($e1->vtype === GradeEntry::VTTIME || $e2->vtype === GradeEntry::VTTIME) {
            if ($op === "-") {
                $this->vtype = GradeEntry::VTDURATION;
            } else {
                $this->vtype = GradeEntry::VTTIME;
            }
        } else if ($e1->vtype === GradeEntry::VTDURATION || $e2->vtype === GradeEntry::VTDURATION) {
            $this->vtype = GradeEntry::VTDURATION;
        }
    }
    function vevaluate($v0, $v1) {
        if ($v0 === null || $v1 === null) {
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
            return $v1 != 0 ? $v0 / $v1 : null;
        case "%":
            return $v1 != 0 ? $v0 % $v1 : null;
        case "**":
            return $v0 ** $v1;
        }
    }
    function evaluate(Contact $student, ?PsetView $info) {
        if (($v0 = $this->_a[0]->evaluate($student, $info)) === null) {
            return null;
        }
        $v1 = $this->_a[1]->evaluate($student, $info);
        return $this->vevaluate($v0, $v1);
    }
    function constant_evaluate() {
        $cv0 = $this->_a[0]->constant_evaluate();
        $cv1 = $this->_a[1]->constant_evaluate();
        if ($cv0 === null || $cv1 === null) {
            return null;
        }
        return new Constant_GradeFormula($cv0->v, $cv1->v);
    }
}

class Relation_GradeFormula extends GradeFormula {
    /** @param string $op
     * @return ?string */
    static function canonical_relation($op) {
        if ($op === "==" || $op === "=") {
            return "==";
        } else if ($op === "!=" || $op === "≠" || $op === "!") {
            return "!=";
        } else if ($op === "≤") {
            return "<=";
        } else if ($op === "≥") {
            return ">=";
        } else if ($op === "<" || $op === "<=" || $op === ">" || $op === ">=") {
            return $op;
        } else {
            return null;
        }
    }

    /** @param string $op
     * @param GradeFormula $e1
     * @param GradeFormula $e2 */
    function __construct($op, $e1, $e2) {
        parent::__construct(self::canonical_relation($op), [$e1, $e2]);
        $this->vtype = GradeEntry::VTBOOL;
    }
    function vevaluate($v0, $v1) {
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
    function evaluate(Contact $student, ?PsetView $info) {
        $v0 = $this->_a[0]->evaluate($student, $info);
        $v1 = $this->_a[1]->evaluate($student, $info);
        return $this->vevaluate($v0, $v1);
    }
    function constant_evaluate() {
        $cv0 = $this->_a[0]->constant_evaluate();
        $cv1 = $this->_a[1]->constant_evaluate();
        if ($cv0 === null || $cv1 === null) {
            return null;
        }
        return new Constant_GradeFormula($this->vevaluate($cv0->v, $cv1->v));
    }
}

class NullableBin_GradeFormula extends GradeFormula {
    /** @param string $op
     * @param GradeFormula $e1
     * @param GradeFormula $e2 */
    function __construct($op, $e1, $e2) {
        parent::__construct($op, [$e1, $e2]);
        if ($op !== "+?" && $e1->vtype === $e2->vtype) {
            $this->vtype = $e1->vtype;
        } else if ($op === "&&") {
            $this->vtype = $e2->vtype;
        }
    }
    function vevaluate($v0, $v1) {
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
        case "^^":
            if ($v0 === null && $v1 === null) {
                return null;
            }
            return !$v0 !== !$v1;
        }
    }
    function evaluate(Contact $student, ?PsetView $info) {
        $v0 = $this->_a[0]->evaluate($student, $info);
        $v1 = $this->_a[1]->evaluate($student, $info);
        return $this->vevaluate($v0, $v1);
    }
    function constant_evaluate() {
        $cv0 = $this->_a[0]->constant_evaluate();
        $cv1 = $this->_a[1]->constant_evaluate();
        if ($cv0 === null || $cv1 === null) {
            return null;
        }
        return new Constant_GradeFormula($this->vevaluate($cv0->v, $cv1->v));
    }
}

class Ternary_GradeFormula extends GradeFormula {
    /** @param GradeFormula $ec
     * @param GradeFormula $et
     * @param GradeFormula $ef */
    function __construct($ec, $et, $ef) {
        parent::__construct("?:", [$ec, $et, $ef]);
        if ($et->vtype === $ef->vtype) {
            $this->vtype = $et->vtype;
        }
    }
    function evaluate(Contact $student, ?PsetView $info) {
        $v0 = $this->_a[0]->evaluate($student, $info);
        return $this->_a[$v0 ? 1 : 2]->evaluate($student, $info);
    }
    function constant_evaluate() {
        $cv0 = $this->_a[0]->constant_evaluate();
        if ($cv0 === null) {
            return null;
        }
        return $this->_a[$cv0->v ? 1 : 2]->constant_evaluate();
    }
}

abstract class Function_GradeFormula extends GradeFormula {
    function __construct($op) {
        parent::__construct($op, []);
    }
    /** @param GradeFormula $e */
    function add_arg($e) {
        $this->_a[] = $e;
    }
    /** @return int */
    function nargs() {
        return count($this->_a);
    }
    /** @return bool */
    function complete(GradeFormulaCompiler $gfc, $p1, $p2) {
        return true;
    }
}

class MinMax_GradeFormula extends Function_GradeFormula {
    function __construct($op) {
        parent::__construct($op);
    }
    function evaluate(Contact $student, ?PsetView $info) {
        $cur = null;
        $ismax = $this->_op === "max";
        foreach ($this->_a as $e) {
            $v = $e->evaluate($student, $info);
            if ($v !== null
                && ($cur === null
                    || ($ismax ? $cur < $v : $cur > $v))) {
                $cur = $v;
            }
        }
        return $cur;
    }
}

class Constant_GradeFormula extends GradeFormula {
    /** @var null|int|float|bool
     * @readonly */
    public $v;

    function __construct($v, $format = null)  {
        parent::__construct("constant", []);
        $this->v = $v;
        $this->vtype = $format ?? (is_bool($v) ? GradeEntry::VTBOOL : GradeEntry::VTNUMBER);
    }
    function evaluate(Contact $student, ?PsetView $info) {
        return $this->v;
    }
    function constant_evaluate() {
        return $this;
    }
    function jsonSerialize() {
        return $this->v;
    }
}

class PsetTotal_GradeFormula extends GradeFormula {
    /** @var Pset */
    private $pset;
    /** @var bool */
    private $noextra;
    /** @var bool */
    private $norm;

    /** @param Pset $pset
     * @param 0|1|2|3|4|5|6|7 $flags */
    function __construct($pset, $flags = 0) {
        parent::__construct("gpt", []);
        $this->pset = $pset;
        $this->noextra = ($flags & GradeFormula::TOTAL_NOEXTRA) !== 0;
        $this->norm = ($flags & GradeFormula::TOTAL_NORM) !== 0;
    }
    function evaluate(Contact $student, ?PsetView $info) {
        return $student->gcache_total($this->pset, $this->noextra, $this->norm);
    }
    function export_grade_names(&$v) {
        $v[] = "{$this->pset->id}.total" . ($this->noextra ? "_noextra" : "");
    }
    function export_psets(&$psets) {
        if (!in_array($this->pset, $psets)) {
            $psets[] = $this->pset;
        }
    }
    function jsonSerialize(): string {
        return $this->pset->nonnumeric_key . ".total" . ($this->noextra ? "_noextra" : "") . ($this->norm ? "_norm" : "");
    }
}

class CategoryTotal_GradeFormula extends GradeFormula {
    /** @var Conf */
    private $conf;
    /** @var PsetCategory */
    private $category;
    /** @var bool */
    private $noextra;
    /** @var bool */
    private $norm;

    /** @param PsetCategory $category
     * @param 0|1|2|3|4|5|6|7 $flags */
    function __construct(Conf $conf, $category, $flags) {
        parent::__construct("ggt", []);
        $this->conf = $conf;
        $this->category = $category;
        $this->noextra = ($flags & GradeFormula::TOTAL_NOEXTRA) !== 0;
        $this->norm = ($flags & GradeFormula::TOTAL_RAW) === 0;
    }
    function evaluate(Contact $student, ?PsetView $info) {
        return $student->gcache_category_total($this->category, $this->noextra, $this->norm);
    }
    function export_grade_names(&$v) {
        foreach ($this->category->psets as $pset) {
            $v[] = "{$pset->id}.total" . ($this->noextra ? "_noextra" : "");
        }
    }
    function export_psets(&$psets) {
        foreach ($this->category->psets as $pset) {
            if (!in_array($pset, $psets)) {
                $psets[] = $pset;
            }
        }
    }
    function jsonSerialize(): string {
        return $this->category->name . ".total" . ($this->noextra ? "_noextra" : "") . ($this->norm ? "" : "_raw");
    }
}
