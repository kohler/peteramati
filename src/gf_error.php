<?php
// gf_error.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2021 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class Error_GradeFormula extends GradeFormula {
    function __construct()  {
        parent::__construct("error", []);
        $this->cacheable = false;
    }
    function evaluate(Contact $student, ?PsetView $info) {
        return null;
    }
}
