<?php
// gf_year.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2024 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class Year_GradeFormula extends GradeFormula {
    /** @var list<int|string> */
    private $years = [];

    function __construct($text)  {
        parent::__construct("year", []);
        $this->vtype = GradeEntry::VTBOOL;
        if (preg_match('/\A(?:(?:\d+(?:-|–|—)\d+|[a-zA-Z\d]+)(?=,\w|\z),?)+\z/', $text)) {
            $text = strtoupper($text);
            preg_match_all('/(\w+)(?:-|–|—)?(\w*)/', $text, $m, PREG_SET_ORDER);
            foreach ($m as $mx) {
                if ($mx[2]) {
                    $this->years[] = (int) $mx[1];
                    $this->years[] = (int) $mx[2];
                } else if (ctype_digit($mx[1])) {
                    $this->years[] = (int) $mx[1];
                    $this->years[] = (int) $mx[1];
                } else {
                    $this->years[] = $mx[1];
                    $this->years[] = null;
                }
            }
        }
    }
    function evaluate(Contact $student, ?PsetView $info) {
        $yval = $student->studentYear;
        $ynum = ctype_digit($yval) ? (int) $yval : null;
        for ($i = 0; $i !== count($this->years); $i += 2) {
            if ($this->years[$i + 1] === null
                ? $yval === $this->years[$i]
                : $ynum !== null && $ynum >= $this->years[$i] && $ynum <= $this->years[$i + 1]) {
                return true;
            }
        }
        return false;
    }
    function jsonSerialize() {
        $x = [];
        for ($i = 0; $i !== count($this->years); $i += 2) {
            if ($this->years[$i + 1] === null || $this->years[$i] === $this->years[$i + 1]) {
                $x[] = $this->years[$i];
            } else {
                $x[] = $this->years[$i] . "-" . $this->years[$i + 1];
            }
        }
        return "year:" . join(",", $x);
    }
}
