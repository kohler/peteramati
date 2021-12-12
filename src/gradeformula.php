<?php
// gradeformula.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2021 Eddie Kohler and Regents of the UC
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

    static public $evaluation_stack = [];

    /** @param string $op
     * @param list<GradeFormula> $a */
    function __construct($op, $a) {
        $this->_op = $op;
        $this->_a = $a;
    }

    abstract function evaluate(Contact $student);

    /** @param list<string> &$v */
    function export_grade_names(&$v) {
        foreach ($this->_a as $a) {
            $a->export_grade_names($v);
        }
    }

    /** @return array<int,mixed> */
    function compute_all(Conf $conf) {
        if ($this->_allv === null) {
            $this->_allv = [];
            foreach (StudentSet::make_all($conf)->users() as $u) {
                $this->_allv[$u->contactId] = $this->evaluate($u);
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
    function evaluate(Contact $student) {
        if (($v0 = $this->_a[0]->evaluate($student)) === null) {
            return null;
        }
        switch ($this->_op) {
        case "neg":
            return -$v0;
        case "log":
        case "log10":
            return $v0 > 0 ? log10($v0) : null;
        case "ln":
            return $v0 > 0 ? log($v0) : null;
        case "lg":
            return $v0 > 0 ? log($v0) / log(2) : null;
        case "exp":
            return exp($v0);
        }
    }
}

class Not_GradeFormula extends GradeFormula {
    /** @param GradeFormula $e */
    function __construct($e) {
        parent::__construct("!", [$e]);
        $this->vtype = GradeEntry::VTBOOL;
    }
    function evaluate(Contact $student) {
        $v0 = $this->_a[0]->evaluate($student);
        return !$v0;
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
        }
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
            return $v1 != 0 ? $v0 / $v1 : null;
        case "%":
            return $v1 != 0 ? $v0 % $v1 : null;
        case "**":
            return $v0 ** $v1;
        }
    }
}

class Relation_GradeFormula extends GradeFormula {
    /** @param string $op
     * @param GradeFormula $e1
     * @param GradeFormula $e2 */
    function __construct($op, $e1, $e2) {
        parent::__construct($op, [$e1, $e2]);
        $this->vtype = GradeEntry::VTBOOL;
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
    /** @param GradeFormula $ec
     * @param GradeFormula $et
     * @param GradeFormula $ef */
    function __construct($ec, $et, $ef) {
        parent::__construct("?:", [$ec, $et, $ef]);
        if ($et->vtype === $ef->vtype) {
            $this->vtype = $et->vtype;
        }
    }
    function evaluate(Contact $student) {
        $v0 = $this->_a[0]->evaluate($student);
        return $this->_a[$v0 ? 1 : 2]->evaluate($student);
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
}

class MinMax_GradeFormula extends Function_GradeFormula {
    function __construct($op) {
        parent::__construct($op);
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
    function jsonSerialize(): float {
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
     * @param bool $noextra
     * @param bool $norm */
    function __construct($pset, $noextra, $norm) {
        parent::__construct("gpt", []);
        $this->pset = $pset;
        $this->noextra = $noextra;
        $this->norm = $norm;
    }
    function evaluate(Contact $student) {
        return $student->gcache_total($this->pset, $this->noextra, $this->norm);
    }
    function export_grade_names(&$v) {
        $v[] = "{$this->pset->id}.total" . ($this->noextra ? "_noextra" : "");
    }
    function jsonSerialize(): string {
        return $this->pset->nonnumeric_key . ".total" . ($this->noextra ? "_noextra" : "") . ($this->norm ? "_norm" : "");
    }
}

class CategoryTotal_GradeFormula extends GradeFormula {
    /** @var Conf */
    private $conf;
    /** @var string */
    private $category;
    /** @var bool */
    private $noextra;
    /** @var bool */
    private $norm;

    function __construct(Conf $conf, $category, $noextra, $norm) {
        parent::__construct("ggt", []);
        $this->conf = $conf;
        $this->category = $category;
        $this->noextra = $noextra;
        $this->norm = $norm;
    }
    function evaluate(Contact $student) {
        return $student->gcache_category_total($this->category, $this->noextra, $this->norm);
    }
    function export_grade_names(&$v) {
        foreach ($this->conf->pset_category($this->category) as $pset) {
            $v[] = "{$pset->id}.total" . ($this->noextra ? "_noextra" : "");
        }
    }
    function jsonSerialize(): string {
        return $this->category . ".total" . ($this->noextra ? "_noextra" : "") . ($this->norm ? "" : "_raw");
    }
}
