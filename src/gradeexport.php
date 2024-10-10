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
    /** @var ?PsetView */
    private $info;
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
    /** @var ?list<mixed>
     * @readonly */
    public $grades;
    /** @var ?list<mixed>
     * @readonly */
    public $autogrades;
    /** @var ?array */
    public $grades_latest;
    /** @var null|int|float */
    public $total;
    /** @var null|int|float */
    public $total_noextra;
    /** @var bool */
    private $has_total = false;
    /** @var ?string */
    private $total_type;
    /** @var ?string */
    public $grade_commit;
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
    public $scores_visible;
    /** @var ?bool */
    public $scores_editable;
    /** @var ?bool */
    public $answers_editable;
    /** @var ?LineNotesOrder */
    public $lnorder;
    /** @var ?list<bool> */
    private $_known_entries;
    /** @var list<0|4|5|6> */
    private $_grades_vf;
    /** @var ?list<0|4|5|6> */
    private $_fixed_values_vf;
    /** @var ?list<GradeEntry> */
    private $_value_entries;
    /** @var list<int> */
    private $_value_indexes;
    /** @var bool */
    private $_export_entries = false;

    /** @param null|1|3|4|5|7 $vf
     * @param ?PsetView $info */
    function __construct(Pset $pset, $vf = null, $info = null) {
        $this->pset = $pset;
        $this->info = $info;
        $this->vf = $vf ?? (VF_TF | $pset->default_student_vf());
        $this->_grades_vf = $pset->grades_vf($info);
    }

    /** @return $this */
    function export_entries() {
        assert(!$this->_export_entries);
        $this->_export_entries = true;
        return $this;
    }

    /** @param list<0|4|5|6> $grades_vf
     * @return $this */
    function set_grades_vf($grades_vf) {
        assert(count($grades_vf) === $this->pset->ngrades);
        $this->_grades_vf = $grades_vf;
        return $this;
    }

    /** @param list<0|4|5|6> $values_vf
     * @return $this */
    function set_fixed_values_vf($values_vf) {
        assert(count($values_vf) === $this->pset->ngrades);
        assert($this->vf >= VF_TF);
        $this->_fixed_values_vf = $values_vf;
        return $this;
    }

    /** @return list<GradeEntry> */
    function value_entries() {
        if ($this->_value_entries === null) {
            $this->_value_entries = $this->_value_indexes = [];
            foreach ($this->_fixed_values_vf ?? $this->_grades_vf as $i => $vf) {
                if (($vf & $this->vf) !== 0) {
                    $this->_value_entries[] = $this->pset->grade_by_pcindex($i);
                    $this->_value_indexes[] = count($this->_value_entries) - 1;
                } else {
                    $this->_value_indexes[] = -1;
                }
            }
        }
        return $this->_value_entries;
    }

    /** @return list<mixed> */
    function blank_values() {
        return array_fill(0, count($this->value_entries()), null);
    }

    /** @param list<mixed> $grades
     * @param ?list<mixed> $autogrades
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_grades_and_autogrades($grades, $autogrades) {
        assert($this->_value_entries !== null);
        assert(count($grades) === count($this->_value_entries));
        assert($autogrades === null || count($autogrades) === count($this->_value_entries));
        $this->grades = $grades;
        $this->autogrades = $autogrades;
        return $this;
    }

    /** @param GradeEntry $ge
     * @param mixed $v
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_grade($ge, $v) {
        if ($this->grades === null) {
            $this->grades = $this->blank_values();
        }
        if (($i = $this->_value_indexes[$ge->pcview_index]) >= 0) {
            $this->grades[$i] = $v;
        }
        return $this;
    }

    /** @param GradeEntry $ge
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function suppress_entry($ge) {
        if (($this->_grades_vf[$ge->pcview_index] & $this->vf) === 0) {
            return $this;
        }
        $this->_grades_vf[$ge->pcview_index] = 0;
        if ($this->_value_entries === null
            || ($vi = $this->_value_indexes[$ge->pcview_index]) < 0) {
            return $this;
        }
        if ($this->_fixed_values_vf === null) {
            array_splice($this->_value_entries, $vi, 1);
            if ($this->grades !== null) {
                array_splice($this->grades, $vi, 1);
            }
            if ($this->autogrades !== null) {
                array_splice($this->autogrades, $vi, 1);
            }
            $this->_value_indexes[$ge->pcview_index] = -1;
            while ($vi !== count($this->_value_entries)) {
                $this->_value_indexes[$this->_value_entries[$vi]->pcview_index] -= 1;
                ++$vi;
            }
        } else {
            if ($this->grades !== null) {
                $this->grades[$vi] = null;
            }
            if ($this->autogrades !== null) {
                $this->autogrades[$vi] = null;
            }
        }
        return $this;
    }

    function suppress_absent_extra_entries() {
        $ges = $this->value_entries();
        for ($i = count($ges) - 1; $i >= 0; --$i) {
            if ($ges[$i]->is_extra
                && ($this->grades[$i] ?? 0) == 0) {
                $this->suppress_entry($ges[$i]);
            }
        }
    }

    /** @param list<string> $known_entries */
    function suppress_known_entries($known_entries) {
        $this->_known_entries = $this->_known_entries ?? array_fill(0, $this->pset->ngrades, false);
        foreach ($known_entries as $key) {
            if (($ge = $this->pset->grades[$key] ?? null))
                $this->_known_entries[$ge->pcview_index] = true;
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
            $this->total_type = null;
            if ($any && $this->_fixed_values_vf !== null) {
                foreach ($this->pset->visible_grades(VF_STUDENT_ANY) as $ge) {
                    if (!$ge->no_total
                        && ($this->_fixed_values_vf[$ge->pcview_index] & $this->vf) === 0) {
                        $this->total_type = "subset";
                        break;
                    }
                }
            }
            if ($any && $this->vf < VF_TF && $this->vf !== VF_STUDENT_ANY) {
                foreach ($this->pset->visible_grades(VF_STUDENT_ANY) as $ge) {
                    if (!$ge->no_total
                        && ($this->_grades_vf[$ge->pcview_index] & $this->vf) === 0) {
                        $this->total_type = "hidden";
                        break;
                    }
                }
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
        $r["pset"] = $this->pset->urlkey;
        if (isset($this->uid)) {
            $r["uid"] = $this->uid;
            $r["user"] = $this->user;
        }
        if ($this->scores_visible !== null) {
            $r["scores_visible"] = $this->scores_visible;
        }
        if ($this->scores_editable !== null) {
            $r["scores_editable"] = $this->scores_editable;
        }
        if ($this->answers_editable !== null) {
            $r["answers_editable"] = $this->answers_editable;
        }
        if (isset($this->uid)) {
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
            if (!empty($this->grades_latest)) {
                $r["grades_latest"] = $this->grades_latest;
            }
            if (!$this->has_total) {
                $this->total();
            }
            if ($this->total_type) {
                $r["total_type"] = $this->total_type;
            }
            if ($this->total !== null) {
                $r["total"] = $this->total;
            }
            if ($this->total_noextra !== null) {
                $r["total_noextra"] = $this->total_noextra;
            }
            if ($this->grade_commit !== null) {
                $r["grade_commit"] = $this->grade_commit;
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
            if ($this->pset->grades_history) {
                $r["history"] = true;
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
        assert(!$this->_export_entries || !$this->slice);
        if ($this->_export_entries) {
            $entries = [];
            foreach ($this->_grades_vf as $i => $vf) {
                if (($vf & $this->vf) !== 0
                    && ($this->_known_entries === null
                        || $this->_known_entries[$i] === false)) {
                    $ge = $this->pset->grade_by_pcindex($i);
                    $entries[$ge->key] = $ge->json($vf & $this->vf, $this->info);
                }
            }
            $r["entries"] = empty($entries) ? (object) [] : $entries;
        }
        if ($this->_export_entries
            || (($this->grades || $this->autogrades) && $this->_fixed_values_vf === null)) {
            $order = [];
            foreach ($this->_grades_vf as $i => $vf) {
                if (($vf & $this->vf) !== 0) {
                    $ge = $this->pset->grade_by_pcindex($i);
                    $order[] = $ge->key;
                }
            }
            $r["order"] = $order;
        }
        if ($this->_fixed_values_vf !== null) {
            $order = [];
            foreach ($this->_fixed_values_vf as $i => $vf) {
                if (($vf & $this->vf) !== 0) {
                    $ge = $this->pset->grade_by_pcindex($i);
                    $order[] = $ge->key;
                }
            }
            $r["fixed_value_order"] = $order;
        }
        return $r;
    }
}
