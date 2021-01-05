<?php
// gf_rank.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2021 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class Rank_GradeFormula extends Function_GradeFormula {
    function __construct() {
        parent::__construct("rank");
        $this->cacheable = false;
    }
    function evaluate(Contact $user) {
        $allv = $this->_allv ?? $this->compute_all($user->conf);
        return $allv[$user->contactId];
    }
    function compute_all(Conf $conf) {
        if ($this->_allv === null) {
            $this->_allv = [];
            $xv = [];
            $av = $this->_a[0]->compute_all($conf);
            foreach (StudentSet::make_all($conf)->users() as $u) {
                $uid = $u->contactId;
                if (!$u->dropped && ($v = $av[$uid]) !== null) {
                    $xv[$uid] = -$v;
                } else {
                    $this->_allv[$uid] = null;
                }
            }
            if (!empty($xv)) {
                asort($xv);
                $xv_list = array_values($xv);
                $diff = min(0.001, ($xv_list[count($xv) - 1] - $xv_list[0]) / 1000);
                $lastv = null;
                $rank = $nextrank = 1;
                foreach ($xv as $uid => $v) {
                    if ($lastv === null || $v - $lastv >= $diff) {
                        $rank = $nextrank;
                    }
                    $lastv = $v;
                    ++$nextrank;
                    $this->_allv[$uid] = $rank;
                }
            }
        }
        return $this->_allv;
    }
}
