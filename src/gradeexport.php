<?php
// gradeexport.php -- Peteramati class for JSON-compatible grade entry export
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class GradeExport implements JsonSerializable {
    /** @var Pset
     * @readonly */
    public $pset;
    /** @var 1|4
     * @readonly */
    public $vf;
    /** @var bool */
    public $slice = false;
    /** @var bool */
    public $value_slice = false;
    /** @var ?int */
    public $uid;
    /** @var ?string */
    public $user;
    /** @var ?string */
    public $commit;
    /** @var ?string */
    public $base_commit;
    /** @var ?bool */
    public $base_handout;
    /** @var ?list<mixed> */
    public $grades;
    /** @var ?list<mixed> */
    public $autogrades;
    /** @var ?array */
    public $student_grade_updates;
    /** @var null|int|float */
    public $total;
    /** @var null|int|float */
    public $total_noextra;
    /** @var bool */
    private $has_total = false;
    /** @var ?string */
    public $grading_hash;
    /** @var ?int */
    public $version;
    /** @var ?int */
    public $answer_version;
    /** @var ?int */
    public $student_timestamp;
    /** @var null|int */
    public $late_hours;
    /** @var null|int */
    public $auto_late_hours;
    /** @var ?bool */
    public $scores_visible_student;
    /** @var ?bool */
    public $scores_editable;
    /** @var ?bool */
    public $answers_editable;
    /** @var ?LineNotesOrder */
    public $lnorder;
    /** @var ?list<GradeEntry> */
    private $visible_entries;
    /** @var ?list<int> */
    private $known_entries;
    /** @var ?list<0|4|5|6> */
    private $export_grades_vf;
    /** @var bool */
    private $export_entries = false;

    /** @param 1|4 $vf */
    function __construct(Pset $pset, $vf) {
        $this->pset = $pset;
        $this->vf = $vf;
    }

    /** @param iterable<GradeEntry> $vges */
    function set_visible_grades($vges) {
        assert(!isset($this->grades));
        /** @phan-suppress-next-line PhanTypeMismatchArgumentInternal */
        $this->visible_entries = is_list($vges) ? $vges : iterator_to_array($vges, false);
        $this->has_total = false;
    }

    /** @param iterable<GradeEntry> $vges */
    function set_exported_values($vges) {
        assert($this->vf >= VF_TF && $this->visible_entries === null);
        $this->set_visible_grades($vges);
        $this->value_slice = true;
    }

    /** @param ?list<0|4|5|6> $export_grades_vf */
    function set_exported_entries($export_grades_vf) {
        $this->export_entries = true;
        $this->export_grades_vf = $export_grades_vf;
    }

    /** @return list<GradeEntry> */
    function visible_entries() {
        if ($this->value_slice || $this->visible_entries === null) {
            return $this->pset->visible_grades($this->vf);
        } else {
            return $this->visible_entries;
        }
    }

    /** @return list<GradeEntry> */
    function value_entries() {
        return $this->visible_entries ?? $this->pset->visible_grades($this->vf);
    }

    /** @return list<mixed> */
    function blank_values() {
        return array_fill(0, count($this->value_entries()), null);
    }

    function suppress_absent_extra() {
        $ges = $this->value_entries();
        $nges = count($ges);
        for ($i = 0; $i !== count($ges); ) {
            if ($ges[$i]->is_extra
                && ($this->grades[$i] ?? 0) == 0) {
                array_splice($ges, $i, 1);
                array_splice($this->grades, $i, 1);
                if ($this->autogrades !== null) {
                    array_splice($this->autogrades, $i, 1);
                }
            } else {
                ++$i;
            }
        }
        if ($i !== $nges) {
            $this->visible_entries = $ges;
        }
    }

    /** @param list<string> $known_entries */
    function suppress_known_entries($known_entries) {
        $this->known_entries = $this->known_entries ?? array_fill(0, count($this->pset->grades), false);
        foreach ($known_entries as $i => $key) {
            if (($ge = $this->pset->grades[$key]))
                $this->known_entries[$ge->pcview_index] = $i;
        }
    }

    /** @return null|int|float */
    function total() {
        if (!$this->has_total) {
            $t = $tnx = 0;
            $any = false;
            foreach ($this->value_entries() as $i => $ge) {
                if (!$ge->no_total
                    && ($gv = $this->grades[$i] ?? null) !== null) {
                    $t += $gv;
                    if (!$ge->is_extra) {
                        $tnx += $gv;
                    }
                    $any = true;
                }
            }
            if ($any) {
                $this->total = round_grade($t);
                $this->total_noextra = round_grade($tnx);
            } else {
                $this->total = $this->total_noextra = null;
            }
            $this->has_total = true;
        }
        return $this->total;
    }

    /** @return null|int|float */
    function total_noextra() {
        if (!$this->has_total) {
            $this->total();
        }
        return $this->total_noextra;
    }

    /** @return array */
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $r = [];
        if (isset($this->uid)) {
            $r["pset"] = $this->pset->urlkey;
            $r["uid"] = $this->uid;
            $r["user"] = $this->user;
            if ($this->commit !== null) {
                $r["commit"] = $this->commit;
            }
            if ($this->base_commit !== null) {
                $r["base_commit"] = $this->base_commit;
                if ($this->base_handout !== null) {
                    $r["base_handout"] = $this->base_handout;
                }
            }
            if ($this->grades !== null) {
                $r["grades"] = $this->grades;
            } else if ($this->vf >= VF_TF && empty($this->autogrades)) {
                $r["grades"] = [];
            }
            if ($this->vf >= VF_TF && !empty($this->autogrades)) {
                $r["autogrades"] = $this->autogrades;
            }
            if (!empty($this->student_grade_updates)) {
                $r["student_grade_updates"] = $this->student_grade_updates;
            }
            if (!$this->has_total) {
                $this->total();
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
            if ($this->student_timestamp) {
                $r["student_timestamp"] = $this->student_timestamp;
            }
            if ($this->version !== null) {
                $r["version"] = $this->version;
            }
            if ($this->answer_version !== null) {
                $r["answer_version"] = $this->answer_version;
            }
            if ($this->scores_visible_student !== null) {
                $r["scores_visible_student"] = $this->scores_visible_student;
            }
            if ($this->scores_editable !== null) {
                $r["scores_editable"] = $this->scores_editable;
            }
            if ($this->answers_editable !== null) {
                $r["answers_editable"] = $this->answers_editable;
            }
            if ($this->lnorder !== null) {
                foreach ($this->value_entries() as $ge) {
                    foreach ($this->lnorder->file("/g/{$ge->key}") as $lineid => $note) {
                        if (($j = $note->render()))
                            $r["linenotes"]["/g/{$ge->key}"][$lineid] = $j;
                    }
                }
            }
        }
        assert(!$this->export_entries || !$this->slice);
        if ($this->export_entries) {
            $entries = [];
            $grades_vf = $this->export_grades_vf ?? $this->pset->grades_vf();
            foreach ($this->visible_entries() as $ge) {
                if ($this->known_entries === null
                    || $this->known_entries[$ge->pcview_index] === false)
                    $entries[$ge->key] = $ge->json($this->vf & $grades_vf[$ge->pcview_index]);
            }
            $r["entries"] = empty($entries) ? (object) [] : $entries;
        }
        if ($this->export_entries || $this->visible_entries !== null) {
            $order = [];
            foreach ($this->visible_entries() as $ge) {
                $order[] = $ge->key;
            }
            $r["order"] = $order;
            if ($this->value_slice) {
                $r["value_order"] = [];
                foreach ($this->value_entries() as $ge) {
                    $r["value_order"][] = $ge->key;
                }
            }
            if ($this->pset->grades_history) {
                $r["history"] = true;
            }
        }
        return $r;
    }
}
