<?php
// gf_is.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2024 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class Is_GradeFormula extends GradeFormula {
    /** @var 'x'|'college'|'dropped'|'false' */
    private $what;

    function __construct($text)  {
        parent::__construct("is", []);
        $this->vtype = GradeEntry::VTBOOL;
        if ($text === "x" || $text === "X") {
            $this->what = "x";
        } else if ($text === "college" || $text === "dropped") {
            $this->what = $text;
        } else {
            $this->what = "false";
        }
    }
    function evaluate(Contact $student, ?PsetView $info) {
        if ($this->what === "x") {
            return !!$student->extension;
        } else if ($this->what === "college") {
            return !$student->extension;
        } else if ($this->what === "dropped") {
            return !!$student->dropped;
        } else {
            return null;
        }
    }
    function jsonSerialize() {
        return "is:{$this->what}";
    }
}
