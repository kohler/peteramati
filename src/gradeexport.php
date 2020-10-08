<?php
// gradeexport.php -- Peteramati class for JSON-compatible grade entry export
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class GradeExport implements JsonSerializable {
    /** @var Pset */
    public $pset;
    /** @var bool */
    public $pc_view;
    /** @var bool */
    public $include_entries = true;
    /** @var ?int */
    public $uid;
    /** @var ?list<float> */
    public $grades;
    /** @var ?list<float> */
    public $autogrades;
    /** @var null|int|float */
    public $total;
    /** @var null|int|float */
    public $total_noextra;
    /** @var ?string */
    public $grading_hash;
    /** @var null|int */
    public $late_hours;
    /** @var null|int */
    public $auto_late_hours;
    /** @var ?bool */
    public $editable;
    /** @var ?list<bool> */
    private $stripped;

    /** @param bool $pc_view */
    function __construct(Pset $pset, $pc_view) {
        $this->pset = $pset;
        $this->pc_view = $pc_view;
    }

    function strip_absent_extra() {
        $gi = 0;
        $si = [];
        foreach ($this->pset->visible_grades($this->pc_view) as $ge) {
            if ($ge->is_extra
                && !$ge->max_visible
                && ($this->grades[$gi] ?? null) === null) {
                $si[] = $gi;
            }
            ++$gi;
        }
        if (!empty($si)) {
            $this->stripped = array_fill(0, $gi, false);
            foreach ($si as $gi) {
                $this->stripped[$gi] = true;
            }
        }
    }

    /** @return array */
    function jsonSerialize() {
        $r = [];
        if (isset($this->uid)) {
            $r["uid"] = $this->uid;
            if ($this->grades !== null) {
                $g = $this->grades;
                if ($this->stripped !== null) {
                    foreach ($this->stripped as $si => $s) {
                        if ($s)
                            $g[$si] = null;
                    }
                    for ($gi = count($g) - 1; $gi >= 0 && $g[$gi] === null; --$gi) {
                        array_pop($g);
                    }
                }
                $r["grades"] = $this->grades;
            }
            if ($this->autogrades !== null) {
                $r["autogrades"] = $this->autogrades;
            }
            if ($this->total !== null) {
                $r["total"] = $this->total;
            }
            if ($this->total_noextra !== null) {
                $r["total_noextra"] = $this->total_noextra;
            }
            if ($this->grading_hash !== null) {
                $r["grading_hash"] = $this->grading_hash;
            }
            if ($this->late_hours !== null) {
                $r["late_hours"] = $this->late_hours;
            }
            if ($this->auto_late_hours !== null) {
                $r["auto_late_hours"] = $this->auto_late_hours;
            }
            if ($this->editable !== null) {
                $r["editable"] = $this->editable;
            }
        }
        if ($this->include_entries) {
            $entries = $order = [];
            $gi = $maxtotal = 0;
            foreach ($this->pset->visible_grades($this->pc_view) as $ge) {
                if ($this->stripped === null || !$this->stripped[$gi]) {
                    $entries[$ge->key] = $ge->json($this->pc_view, $gi);
                    $order[] = $ge->key;
                } else {
                    $order[] = null;
                }
                if ($ge->max
                    && !$ge->is_extra
                    && !$ge->no_total
                    && ($this->pc_view || $ge->max_visible)) {
                    $maxtotal += $ge->max;
                }
                ++$gi;
            }
            if ($this->stripped !== null) {
                for ($gi = count($order) - 1; $gi >= 0 && $order[$gi] === null; --$gi) {
                    array_pop($order);
                }
            }
            $r["entries"] = $entries;
            $r["order"] = $order;
            if ($maxtotal > 0) {
                $r["maxtotal"] = $maxtotal;
            }
        }
        return $r;
    }
}
