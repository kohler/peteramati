<?php
// psetcategory.php -- Peteramati pset category class
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class PsetCategory {
    /** @var string
     * @readonly */
    public $name;
    /** @var list<Pset>
     * @readonly */
    public $psets = [];
    /** @var int
     * @readonly */
    public $catid;
    /** @var bool
     * @readonly */
    public $has_extra = false;
    /** @var array<int,float> */
    private $pset_weights;


    /** @param string $name
     * @param int $catid */
    function __construct($name, $catid) {
        $this->name = $name;
        $this->catid = $catid;
    }

    /** @param Pset $pset
     * @return ?float */
    function weight_factor($pset) {
        if ($this->pset_weights !== null) {
            return $this->pset_weights[$pset->id] ?? null;
        }
        $total = 0.0;
        $nnondefault = 0;
        foreach ($this->psets as $p) {
            if (($p->weight ?? 1.0) <= 0.0
                || $p->max_grade(VF_TF) <= 0.0) {
                continue;
            }
            $total += $p->weight ?? 1.0;
            $nnondefault += $p->weight === null;
        }
        $this->pset_weights = [];
        if ($nnondefault !== 0 && $nnondefault !== count($this->psets)) {
            return null;
        }
        foreach ($this->psets as $p) {
            $w = (float) ($p->weight ?? 1.0);
            if ($w > 0.0) {
                $this->pset_weights[$p->id] = $w / (float) $total;
            } else {
                $this->pset_weights[$p->id] = 0.0;
            }
        }
        return $this->pset_weights[$pset->id] ?? null;
    }

    /** @param list<Pset> $psets
     * @return array<string,PsetCategory>
     * @suppress PhanAccessReadOnlyProperty */
    static function make_map($psets) {
        $cats = [];
        $catid = 0;
        foreach ($psets as $p) {
            if ($p->disabled || ($p->category ?? "") === "") {
                continue;
            }
            $cat = $cats[$p->category] ?? null;
            if ($cat === null) {
                $cats[$p->category] = $cat = new PsetCategory($p->category, $catid);
                ++$catid;
            }
            $cat->psets[] = $p;
            if ($p->has_extra) {
                $cat->has_extra = true;
            }
        }
        uasort($cats, function ($a, $b) {
            return strnatcasecmp($a->name, $b->name);
        });
        return $cats;
    }
}
