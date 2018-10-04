<?php
// diffinfo.php -- Peteramati class encapsulating diffs for a file
// HotCRP and Peteramati are Copyright (c) 2006-2018 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class DiffInfo implements Iterator {
    public $filename;
    public $binary = false;
    public $truncated = false;
    public $title;
    public $fileless = false;
    public $boring = false;
    private $_boring_set = false;
    public $hide_if_anonymous = false;
    public $position = 0.0;
    public $removed = false;
    public $loaded = true;
    private $_diff = [];
    private $_diffsz = 0;
    private $_dflags;
    private $_itpos;

    private $_repoa;
    private $_pset;
    private $_hasha;
    private $_filenamea;
    private $_hasha_hrepo;

    const MAXLINES = 8000;
    const MAXDIFFSZ = self::MAXLINES << 2;

    const LINE_NONL = 1;

    function __construct($filename, DiffConfig $diffconfig = null) {
        $this->filename = $filename;
        if ($diffconfig) {
            $this->title = $diffconfig->title;
            $this->fileless = !!$diffconfig->fileless;
            $this->boring = !!$diffconfig->boring;
            $this->_boring_set = $diffconfig->boring !== null;
            $this->hide_if_anonymous = !!$diffconfig->hide_if_anonymous;
            $this->position = (float) $diffconfig->position;
        }
    }

    function set_repoa(Repository $repoa, Pset $pset = null, $hasha, $filenamea,
                       $hasha_hrepo = null) {
        $this->_repoa = $repoa;
        $this->_pset = $pset;
        $this->_hasha = $hasha;
        $this->_filenamea = $filenamea;
        $this->_hasha_hrepo = $hasha_hrepo;
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

    function ends_without_newline() {
        $di = $this->_diffsz - 4;
        assert($di >= 0 && ($this->_diff[$di] === "-" || $this->_diff[$di] === "+"));
        if ($this->_dflags === null)
            $this->_dflags = [];
        if (!isset($this->_dflags[$di]))
            $this->_dflags[$di] = 0;
        $this->_dflags[$di] |= self::LINE_NONL;
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

    function finish_unloaded() {
        $this->finish();
        $this->loaded = false;
    }


    function is_empty() {
        return $this->_diffsz === 0;
    }

    function is_handout_commit_a() {
        if ($this->_hasha_hrepo === null) {
            $this->_hasha_hrepo = $this->_pset && $this->_pset->handout_commits($this->_hasha);
        }
        return $this->_hasha_hrepo;
    }

    function entry($i) {
        if ($i >= 0 && $i < ($this->_diffsz >> 2))
            return array_slice($this->_diff, $i << 2, 4);
        else
            return false;
    }

    private function linea_lower_bound($linea) {
        $l = 0;
        $r = $this->_diffsz;
        while ($l < $r) {
            $m = $l + ((($r - $l) >> 2) & ~3);
            while ($m + 4 < $r && $this->_diff[$m + 1] === null)
                $m += 4;
            $ln = $this->_diff[$m + 1];
            if ($ln === null || $ln >= $linea)
                $r = $m;
            else
                $l = $m + 4;
        }
        return $l;
    }

    function contains_linea($linea) {
        $l = $this->linea_lower_bound($linea);
        return $l < $this->_diffsz && $this->_diff[$l] !== "@";
    }

    private function fix_context($pos) {
        $lx = $rx = $pos;
        while ($lx >= 0 && $this->_diff[$lx] !== "@")
            $lx -= 4;
        $rx = max($rx, $lx + 4);
        while ($rx < $this->_diffsz && $this->_diff[$rx] !== "@")
            $rx += 4;
        if ($lx >= 0 && $rx < $this->_diffsz) {
            $lnal = $this->_diff[$lx + 5];
            $lnbl = $this->_diff[$lx + 6];
            $ch = $this->_diff[$rx - 4];
            $lnar = $this->_diff[$rx - 3] + ($ch === " " || $ch === "-" ? 1 : 0);
            $lnbr = $this->_diff[$rx - 2] + ($ch === " " || $ch === "+" ? 1 : 0);
            $this->_diff[$lx + 3] = preg_replace('/^@@[-+,\d ]*@@.*/', "@@ -{$lnal}," . ($lnar - $lnal) . " +{$lnbl}," . ($lnbr - $lnbl) . " @@", $this->_diff[$lx + 3]);
        }
    }

    function expand_linea($linea_lx, $linea_rx) {
        // look for left-hand edge of desired region
        $linea_lx = max(1, $linea_lx);
        $l = $this->linea_lower_bound($linea_lx);

        // advance to hole
        while ($l < $this->_diffsz && $this->_diff[$l] !== "@") {
            if ($this->_diff[$l + 1] === $linea_lx) {
                ++$linea_lx;
                if ($linea_lx === $linea_rx)
                    return true;
            }
            $l += 4;
        }

        // perform insert
        assert($l === $this->_diffsz || $this->_diff[$l] === "@");
        assert($l === 0 || $this->_diff[$l - 3] !== null);
        if (!$this->_repoa)
            return false;
        $lines = $this->_repoa->content_lines($this->_hasha, $this->_filenamea);
        if ($linea_lx > count($lines))
            return true;
        $linea_rx = min(count($lines), $linea_rx);

        $lx = $l;
        while ($lx >= 0 && $lx < $this->_diffsz && $this->_diff[$lx + 1] === null)
            $lx -= 4;
        $rx = $l;
        while ($rx < $this->_diffsz && $this->_diff[$rx + 1] === null)
            $rx += 4;

        $deltab = 0;
        if ($lx >= 0 && $lx < $this->_diffsz)
            $deltab = $this->_diff[$lx + 2] - $this->_diff[$lx + 1];

        $splice = [];
        if ($lx >= 0 && $lx < $this->_diffsz && $this->_diff[$lx + 1] >= $linea_lx - 1
            && $rx < $this->_diffsz && $this->_diff[$rx + 1] <= $linea_rx) {
            for ($i = $this->_diff[$lx + 1] + 1; $i < $this->_diff[$rx + 1]; ++$i)
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            array_splice($this->_diff, $lx + 4, $rx - $lx - 4, $splice);
            $this->fix_context($lx);
        } else if ($lx >= 0 && $lx < $this->_diffsz && $this->_diff[$lx + 1] >= $linea_lx - 1) {
            for ($i = $this->_diff[$lx + 1] + 1; $i < $linea_rx; ++$i)
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            array_splice($this->_diff, $lx + 4, 0, $splice);
            $this->fix_context($lx);
        } else if ($rx < $this->_diffsz && $this->_diff[$rx + 1] <= $linea_rx) {
            for ($i = $linea_lx; $i < $this->_diff[$rx + 1]; ++$i)
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            array_splice($this->_diff, $rx, 0, $splice);
            $this->fix_context($rx);
        } else {
            $linecount = $linea_rx - $linea_lx;
            array_push($splice, "@", null, null, "@@ -{$linea_lx},{$linecount} +" . ($linea_lx + $deltab) . ",{$linecount} @@");
            for ($i = $linea_lx; $i < $linea_rx; ++$i)
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            array_splice($this->_diff, $l, 0, $splice);
        }
        $this->_diffsz = count($this->_diff);
        return true;
    }

    function restrict_linea($linea_lx, $linea_rx) {
        $l = $this->linea_lower_bound($linea_lx);
        $r = $this->linea_lower_bound($linea_rx);
        while ($r < $this->_diffsz && $this->_diff[$r] === "+")
            $r += 4;
        $c = clone $this;
        if ($l < $this->_diffsz && $this->_diff[$l] !== "@") {
            $c->_diff = array_slice($this->_diff, $l - 4, $r - $l + 4);
            $c->_diffsz = $r - $l + 4;
            $c->_diff[0] = "@";
            $c->_diff[1] = $c->_diff[2] = null;
            $c->_diff[2] = "@@ @@";
            $c->fix_context(0);
        } else {
            $c->_diff = array_slice($this->_diff, $l, $r - $l);
            $c->_diffsz = $r - $l;
        }
        if ($r < $this->_diffsz) {
            array_push($c->_diff, "@", null, null, "");
            $c->_diffsz += 4;
            $c->fix_context($c->_diffsz - 4);
        }
        return $c;
    }


    static function compare($a, $b) {
        if ($a->position != $b->position)
            return $a->position < $b->position ? -1 : 1;
        else
            return strcmp($a->filename, $b->filename);
    }


    function current() {
        $x = array_slice($this->_diff, $this->_itpos, 4);
        if ($this->_dflags !== null && isset($this->_dflags[$this->_itpos]))
            $x[] = $this->_dflags[$this->_itpos];
        return $x;
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
