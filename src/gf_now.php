<?php
// gf_now.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2024 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class Now_GradeFormula extends GradeFormula {
    function __construct()  {
        parent::__construct("null", []);
        $this->vtype = GradeEntry::VTTIME;
    }
    function evaluate(Contact $student, ?PsetView $info) {
        return Conf::$now;
    }
    function jsonSerialize() {
        return "now";
    }
}
