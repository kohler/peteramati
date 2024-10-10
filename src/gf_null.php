<?php
// gf_null.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2021 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class Null_GradeFormula extends GradeFormula {
    function __construct()  {
        parent::__construct("null", []);
    }
    function evaluate(Contact $student, ?PsetView $info) {
        return null;
    }
    function jsonSerialize() {
        return null;
    }
}
