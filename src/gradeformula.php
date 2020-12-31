<?php
// gradeformula.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

abstract class GradeFormula implements JsonSerializable {
    /** @var string */
    protected $_op;
    /** @var list<GradeFormula> */
    protected $_a;

    static public $evaluation_stack = [];

    /** @param string $op
     * @param list<GradeFormula> $a */
    function __construct($op, $a) {
        $this->_op = $op;
        $this->_a = $a;
    }

    abstract function evaluate(Contact $student);

    function evaluate_global(Contact $student, $config = null) {
        if ($config && $config instanceof GradeEntryConfig) {
            $name = "{$config->pset->nonnumeric_key}.{$config->key}";
        } else if ($config && $config instanceof FormulaConfig) {
            $name = "{$config->name}";
        } else {
            $name = "[{$this->_op}]";
        }
        array_push(self::$evaluation_stack, $name);
        $v = $this->evaluate($student);
        array_pop(self::$evaluation_stack);
        return $v;
    }

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
    /** @var int */
    public $oppos;
    function __construct($el, $er, $oppos) {
        parent::__construct(",", [$el, $er]);
        $this->oppos = $oppos;
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
    /** @var GradeEntryConfig */
    private $ge;

    /** @param GradeEntryConfig $ge */
    function __construct($ge) {
        parent::__construct("g", []);
        $this->ge = $ge;
        assert(!$ge->formula);
    }
    function evaluate(Contact $student) {
        $v = $student->gcache_entry($this->ge->pset, $this->ge);
        return $v !== null ? (float) $v : null;
    }
    function jsonSerialize() {
        return $this->ge->pset->nonnumeric_key . "." . $this->ge->key;
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
    function jsonSerialize() {
        return $this->pset->nonnumeric_key . ".total" . ($this->noextra ? "_noextra" : "") . ($this->norm ? "_norm" : "");
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
        return $this->category . ".total" . ($this->noextra ? "_noextra" : "") . ($this->norm ? "" : "_raw");
    }
}
