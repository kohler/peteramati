<?php
// gf_time.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2024 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class Time_GradeFormula extends GradeFormula {
    private $timestamp;
    /** @param int $t */
    function __construct($t)  {
        parent::__construct("time", []);
        $this->timestamp = $t;
        $this->vtype = GradeEntry::VTTIME;
    }
    function evaluate(Contact $student, ?PsetView $info) {
        return $this->timestamp;
    }
    function jsonSerialize() {
        return "@{$this->timestamp}";
    }
}
