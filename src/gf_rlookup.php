<?php
// gf_rlookup.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2024 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class Rlookup_GradeFormula extends Function_GradeFormula {
    /** @var list<float> */
    public $_thresholds;

    function __construct() {
        parent::__construct("rlookup");
    }
    function complete(GradeFormulaCompiler $gfc, $p1, $p2) {
        if ($this->nargs() < 4 || $this->nargs() % 2 !== 0) {
            $gfc->error_at($p1, $p2, "<0>Expected at least 4 arguments");
            return false;
        }
        $this->_thresholds = [];
        for ($i = 2; $i < $this->nargs(); $i += 2) {
            $cv = $this->_a[$i]->constant_evaluate();
            if ($cv === null || (!is_int($cv->v) && !is_float($cv->v))) {
                $gfc->error_at($p1, $p2, "<0>Arguments 2, 4, etc. must be constant numbers");
                return false;
            }
            if (!empty($this->_thresholds) && $cv->v < $this->_thresholds[count($this->_thresholds) - 1]) {
                $gfc->error_at($p1, $p2, "<0>Argument {$i} must be greater than or equal to argument " . ($i - 2));
                return false;
            }
            $this->_thresholds[] = $cv->v;
        }
        $this->vtype = $this->_a[1]->vtype;
        return true;
    }
    function evaluate(Contact $user, ?PsetView $info) {
        $v0 = $this->_a[0]->evaluate($user, $info);
        if ($v0 === null) {
            return null;
        }
        $i = 0;
        while ($i < count($this->_thresholds) && $v0 >= $this->_thresholds[$i]) {
            ++$i;
        }
        return $this->_a[1 + ($i << 1)]->evaluate($user, $info);
    }
}
