<?php
// gradeformula.php -- Peteramati grade formulas
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

class GradeFormula {
    private $_op;
    private $_a;

    function __construct($op, $a) {
        $this->_op = $op;
        $this->_a = $a;
    }

    static function parse($conf, &$t, $minprec) {
        $t = ltrim($t);

        if ($t === "") {
            $e = null;
        } else if ($t[0] === "(") {
            $t = substr($t, 1);
            $e = self::parse($conf, $t, 0);
            if ($e !== null) {
                $t = ltrim($t);
                if ($t === "" || $t[0] !== ")") {
                    return $e;
                }
                $t = substr($t, 1);
            }
        } else if ($t[0] === "-" || $t[0] === "+") {
            $op = $t[0];
            $t = substr($t, 1);
            $e = self::parse($conf, $t, 12);
            if ($e !== null && $op === "-") {
                $e = new GradeFormula("neg", [$e]);
            }
        } else if (preg_match('{\A(\d+\.?\d*|\.\d+)(.*)\z}s', $t, $m)) {
            $t = $m[2];
            $e = new GradeFormula("n", (float) $m[1]);
        } else if (preg_match('{\A(?:pi|π|m_pi)(.*)\z}si', $t, $m)) {
            $t = $m[2];
            $e = new GradeFormula("n", (float) M_PI);
        } else if (preg_match('{\A(log10|log|ln|lg|exp)\b(.*)\z}s', $t, $m)) {
            $t = $m[2];
            $e = self::parse($conf, $t, 12);
            if ($e !== null) {
                $e = new GradeFormula($m[1], [$e]);
            }
        } else if (preg_match('{\A(\w+)\s*\.\s*(\w+)(.*)\z}s', $t, $m)) {
            if (($pset = $conf->pset_by_key_or_title($m[1]))
                && ($ge = $pset->gradelike_by_key($m[2]))) {
                $e = new GradeFormula("g", [$pset, $ge]);
            } else {
                return null;
            }
        } else if (preg_match('{\A(\w+)(.*)\z}s', $t, $m)) {
            $t = $m[2];
            $k = $m[1];
            $kbase = $k;
            $noextra = false;
            $raw = null;
            while (true) {
                if (str_ends_with($kbase, "_noextra")) {
                    $kbase = substr($kbase, 0, -8);
                    $noextra = true;
                } else if (str_ends_with($kbase, "_raw") && $raw === null) {
                    $kbase = substr($kbase, 0, -4);
                    $raw = true;
                } else if (str_ends_with($kbase, "_norm") && $raw === null) {
                    $kbase = substr($kbase, 0, -5);
                    $raw = false;
                } else {
                    break;
                }
            }
            if (($pset = $conf->pset_by_key_or_title($kbase))) {
                $noextra = $noextra && $pset->has_extra;
                $raw = $raw === null || $raw;
                $e = new GradeFormula("gpt", [$pset, $noextra, $raw]);
            } else if ($conf->pset_group($kbase)) {
                $noextra = $noextra && $conf->pset_group_has_extra($kbase);
                $raw = $raw !== null && $raw;
                $e = new GradeFormula("ggt", [$kbase, $noextra, $raw]);
            } else {
                return null;
            }
        } else {
            $e = null;
        }

        if ($e === null) {
            return null;
        }

        while (true) {
            $t = ltrim($t);
            if (preg_match('{\A(\+|-|\*\*?|/|%)(.*)\z}s', $t, $m)) {
                $op = $m[1];
                if ($op === "**") {
                    $prec = 13;
                } else if ($op === "*" || $op === "/" || $op === "%") {
                    $prec = 11;
                } else {
                    $prec = 10;
                }
                if ($prec < $minprec) {
                    return $e;
                }
                $t = $m[2];
                $e2 = self::parse($conf, $t, $op === "**" ? $prec : $prec + 1);
                if ($e === null) {
                    return null;
                }
                $e = new GradeFormula($op, [$e, $e2]);
            } else {
                return $e;
            }
        }
    }

    function evaluate(Contact $student) {
        // error_log("{$this->_op} {$student->contactId} " . json_encode($this->_a));
        $x = $this->xevaluate($student);
        // error_log("= $x");
        return $x;
    }

    private function xevaluate(Contact $student) {
        switch ($this->_op) {
        case "n":
            return $this->_a;
        case "g":
            return (float) $student->gcache_entry($this->_a[0], $this->_a[1]);
        case "gpt":
            return $student->gcache_total($this->_a[0], $this->_a[1], $this->_a[2]);
        case "ggt":
            return $student->gcache_group_total($this->_a[0], $this->_a[1], $this->_a[2]);
        }

        $vs = [];
        foreach ($this->_a as $e) {
            $v = $e->evaluate($student);
            if ($v === null) {
                return null;
            }
            $vs[] = $v;
        }
        switch ($this->_op) {
        case "+":
            return $vs[0] + $vs[1];
        case "-":
            return $vs[0] - $vs[1];
        case "*":
            return $vs[0] * $vs[1];
        case "/":
            return $vs[0] / $vs[1];
        case "%":
            return $vs[0] % $vs[1];
        case "**":
            return $vs[0] ** $vs[1];
        case "neg":
            return -$vs[0];
        case "log":
        case "log10":
            return log10($vs[0]);
        case "ln":
            return log($vs[0]);
        case "lg":
            return log($vs[0]) / log(2);
        case "exp":
            return exp($vs[0]);
        }
    }
}
