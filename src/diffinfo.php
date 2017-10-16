<?php
// diffinfo.php -- Peteramati class encapsulating diffs for a file
// HotCRP and Peteramati are Copyright (c) 2006-2017 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class DiffInfo implements Iterator {
    public $filename;
    public $binary = false;
    public $truncated = false;
    public $boring = false;
    private $_boring_set = false;
    public $hide_if_anonymous = false;
    public $priority = 0.0;
    public $removed = false;
    public $_diff = [];
    public $_diffsz = 0;
    private $_itpos;

    private $_repoa;
    private $_pset;
    private $_hasha;
    private $_filenamea;

    const MAXLINES = 16384;
    const MAXDIFFSZ = self::MAXLINES << 2;

    function __construct($filename, DiffConfig $diffconfig = null) {
        $this->filename = $filename;
        if ($diffconfig) {
            $this->boring = !!$diffconfig->boring;
            $this->_boring_set = $diffconfig->boring !== null;
            $this->hide_if_anonymous = !!$diffconfig->hide_if_anonymous;
            $this->priority = (float) $diffconfig->priority;
        }
    }

    function set_repoa(Repository $repoa, Pset $pset = null, $hasha, $filenamea) {
        $this->_repoa = $repoa;
        $this->_pset = $pset;
        $this->_hasha = $hasha;
        $this->_filenamea = $filenamea;
    }

    function add($ch, $linea, $lineb, $text) {
        if ($this->truncated) {
            /* do nothing */
        } else if ($this->_diffsz === self::MAXDIFFSZ) {
            array_push($this->_diff, "+", $linea, $lineb, "*** OUTPUT TRUNCATED ***");
            $this->truncated = true;
            $this->_diffsz += 4;
        } else {
            array_push($this->_diff, $ch, $linea, $lineb, $text);
            $this->_diffsz += 4;
        }
    }

    function finish() {
        if ($this->_diffsz === 4 && str_starts_with($this->_diff[3], "B"))
            $this->binary = true;
        if ($this->binary && !$this->_boring_set)
            $this->boring = true;
        if ($this->binary
            ? preg_match('_ and /dev/null differ$_', $this->_diff[3])
            : $this->_diffsz && $this->_diff[$this->_diffsz - 2] === 0)
            $this->removed = true;
    }


    function entry($i) {
        if ($i >= 0 && $i < ($this->_diffsz >> 2))
            return array_slice($this->_diff, $i << 2, 4);
        else
            return false;
    }

    function expand_linea($linea) {
        if (!$this->_repoa)
            return false;

        // binary search to find insertion position
        $l = 0;
        $r = $this->_diffsz;
        while ($l < $r) {
            $m = $l + ((($r - $l) >> 2) & ~3);
            while ($m + 4 < $r && $this->_diff[$m + 1] === null)
                $m += 4;
            $ln = $this->_diff[$m + 1];
            if ($ln === null || $ln > $linea)
                $r = $m;
            else if ($ln === $linea) {
                if ($this->_diff[$m] !== "@")
                    return true;
                $r = $m;
            } else
                $l = $m + 4;
        }

        // perform insert
        assert($l === $this->_diffsz || $this->_diff[$l] === "@");
        assert($l === 0 || $this->_diff[$l - 3] !== null);
        $lines = $this->_repoa->content_lines($this->_hasha, $this->_filenamea);
        if ($linea > count($lines))
            return false;
        $linea_lx = max(1, $linea - 2);
        $linea_rx = min(count($lines), $linea + 3);

        $lx = $l;
        while ($lx >= 0 && $this->_diff[$lx + 1] === null)
            $lx -= 4;
        $rx = $l;
        while ($rx < $this->_diffsz && $this->_diff[$rx + 1] === null)
            $rx += 4;

        $deltab = 0;
        if ($lx >= 0)
            $deltab = $this->_diff[$lx + 2] - $this->_diff[$lx + 1];

        $splice = [];
        if ($lx >= 0 && $this->_diff[$lx + 1] >= $linea_lx
            && $rx < $this->_diffsz && $this->_diff[$rx + 1] < $linea_rx) {
            for ($i = $this->_diff[$lx + 1] + 1; $i < $this->_diff[$rx + 1]; ++$i)
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            array_splice($this->_diff, $lx + 4, $rx - $lx - 4, $splice);
            $fix = $lx;
        } else if ($lx >= 0 && $this->_diff[$lx + 1] >= $linea_lx) {
            for ($i = $this->_diff[$lx + 1] + 1; $i < $linea_rx; ++$i)
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            array_splice($this->_diff, $lx + 4, 0, $splice);
            $fix = $lx;
        } else if ($rx < $this->_diffsz && $this->_diff[$rx + 1] < $linea_rx) {
            for ($i = $linea_lx; $i < $this->_diff[$rx + 1]; ++$i)
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            array_splice($this->_diff, $rx, 0, $splice);
            $fix = $rx;
        } else {
            $linecount = $linea_rx - $linea_lx;
            array_push($splice, "@", null, null, "@@ -{$linea_lx},{$linecount} +" . ($linea_lx + $deltab) . ",{$linecount} @@");
            for ($i = $linea_lx; $i < $linea_rx; ++$i)
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            array_splice($this->_diff, $l, 0, $splice);
            $fix = null;
        }
        $this->_diffsz = count($this->_diff);

        if ($fix) {
            $lx = $rx = $fix;
            while ($lx >= 0 && $this->_diff[$lx] !== "@")
                $lx -= 4;
            while ($rx < $this->_diffsz && $this->_diff[$rx] !== "@")
                $rx += 4;
            if ($lx >= 0 && $rx < $this->_diffsz) {
                $lnal = $this->_diff[$lx + 5];
                $lnbl = $this->_diff[$lx + 6];
                $ch = $this->_diff[$rx - 4];
                $lnar = $this->_diff[$rx - 3] + ($ch === " " || $ch === "-" ? 1 : 0);
                $lnbr = $this->_diff[$rx - 2] + ($ch === " " || $ch === "+" ? 1 : 0);
                $this->_diff[$lx + 3] = preg_replace('/^@@[-+,\d ]*@@/', "@@ -{$lnal}," . ($lnar - $lnal) . " +{$lnbl}," . ($lnbr - $lnbl) . " @@", $this->_diff[$lx + 3]);
            }
        }

        return true;
    }


    static function compare($a, $b) {
        if ($a->priority != $b->priority)
            return $a->priority < $b->priority ? 1 : -1;
        else
            return strcmp($a->filename, $b->filename);
    }


    function current() {
        return array_slice($this->_diff, $this->_itpos, 4);
    }
    function key() {
        return $this->_itpos >> 2;
    }
    function next() {
        $this->_itpos += 4;
    }
    function rewind() {
        $this->_itpos = 0;
    }
    function valid() {
        return $this->_itpos < $this->_diffsz;
    }
}
