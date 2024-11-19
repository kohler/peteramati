<?php
// gf_namematch.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2021 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class NameMatch_GradeFormula extends GradeFormula {
    /** @var string */
    private $pattern;

    function __construct($pattern)  {
        parent::__construct("name", []);
        $this->pattern = $pattern;
    }
    function evaluate(Contact $student, ?PsetView $info) {
        if ($student->is_anonymous) {
            return stripos($student->anon_username, $this->pattern) !== false;
        }
        return stripos($student->firstName, $this->pattern) !== false
            || stripos($student->lastName, $this->pattern) !== false
            || stripos($student->email, $this->pattern) !== false
            || stripos($student->github_username ?? "", $this->pattern) !== false;
    }
    function jsonSerialize() {
        return "namematch:{$this->pattern}";
    }
}
