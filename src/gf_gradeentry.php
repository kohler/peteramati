<?php
// gf_gradeentry.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2021 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class GradeEntry_GradeFormula extends GradeFormula {
    /** @var GradeEntry */
    private $ge;

    /** @param GradeEntry $ge */
    function __construct($ge) {
        parent::__construct("g", []);
        $this->ge = $ge;
        $this->vtype = $ge->vtype;
        assert(!$ge->formula);
    }
    function evaluate(Contact $student, ?PsetView $info) {
        if ($info && $info->pset === $this->ge->pset && $info->user === $student) {
            $v = $info->grade_value($this->ge);
        } else {
            $v = $student->gcache_entry($this->ge->pset, $this->ge);
        }
        return $v !== null ? (float) $v : null;
    }
    function export_grade_names(&$v) {
        $v[] = "{$this->ge->pset->id}.{$this->ge->key}";
    }
    function export_psets(&$psets) {
        if (!in_array($this->ge->pset, $psets)) {
            $psets[] = $this->ge->pset;
        }
    }
    function jsonSerialize(): string {
        return "{$this->ge->pset->nonnumeric_key}.{$this->ge->key}";
    }
}
