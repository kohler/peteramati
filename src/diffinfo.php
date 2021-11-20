<?php
// diffinfo.php -- Peteramati class encapsulating diffs for a file
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class DiffInfo implements Iterator {
    /** @var string */
    public $filename;
    /** @var bool */
    public $binary = false;
    /** @var bool */
    public $truncated = false;
    /** @var ?string */
    public $title;
    /** @var bool */
    public $fileless = false;
    /** @var bool */
    public $collapse = false;
    /** @var bool */
    private $_collapse_set = false;
    /** @var bool */
    public $markdown;
    /** @var bool */
    public $markdown_allowed;
    /** @var bool */
    public $highlight;
    /** @var bool */
    public $highlight_allowed;
    /** @var int */
    public $tabwidth = 4;
    /** @var ?string */
    public $language;
    /** @var bool */
    public $hide_if_anonymous = false;
    /** @var float */
    public $position = 0.0;
    /** @var bool */
    public $removed = false;
    /** @var bool */
    public $loaded = true;
    /** @var list<int|string|null> */
    private $_diff = [];
    /** @var int */
    private $_diffsz = 0;
    /** @var ?array<int,int> */
    private $_dflags;
    /** @var int */
    private $_itpos = 0;

    /** @var ?Repository */
    private $_repoa;
    /** @var ?Pset */
    private $_pset;
    /** @var ?string */
    private $_hasha;
    /** @var ?string */
    private $_filenamea;
    /** @var ?bool */
    private $_hasha_hrepo;

    const MAXLINES = 8000;
    const MAXDIFFSZ = self::MAXLINES << 2;

    const LINE_NONL = 1;

    /** @param string $filename */
    function __construct($filename, DiffConfig $diffconfig = null) {
        $this->filename = $filename;
        $ismd = str_ends_with($filename, ".md");
        if ($diffconfig) {
            $this->title = $diffconfig->title;
            $this->fileless = !!$diffconfig->fileless;
            $this->collapse = !!$diffconfig->collapse;
            $this->_collapse_set = isset($diffconfig->collapse);
            $this->hide_if_anonymous = !!$diffconfig->hide_if_anonymous;
            $this->position = (float) $diffconfig->position;
            $this->markdown = $diffconfig->markdown ?? $ismd;
            $this->markdown_allowed = $diffconfig->markdown_allowed ?? $ismd;
            $this->highlight = !!$diffconfig->highlight;
            $this->highlight_allowed = !!$diffconfig->highlight_allowed;
            $this->language = $diffconfig->language;
            $this->tabwidth = $diffconfig->tabwidth ?? 4;
        } else {
            $this->markdown = $this->markdown_allowed = $ismd;
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

    /** @param ?bool $collapse */
    function set_collapse($collapse) {
        $this->collapse = !!$collapse;
        $this->_collapse_set = isset($collapse);
    }

    /** @param string $ch
     * @param ?int $linea
     * @param ?int $lineb
     * @param string $text */
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

    function set_ends_without_newline() {
        $di = $this->_diffsz - 4;
        assert($di >= 0 && ($this->_diff[$di] === "-" || $this->_diff[$di] === "+"));
        if ($this->_dflags === null) {
            $this->_dflags = [];
        }
        if (!isset($this->_dflags[$di])) {
            $this->_dflags[$di] = 0;
        }
        $this->_dflags[$di] |= self::LINE_NONL;
    }

    function finish() {
        $n = $this->_diffsz;
        if ($n === 4 && str_starts_with($this->_diff[3], "B")) {
            $this->binary = true;
        }
        if ($this->binary && !$this->_collapse_set) {
            $this->collapse = true;
        }
        if ($this->binary
            ? preg_match('/ and \/dev\/null differ$/', $this->_diff[3])
            : $n >= 4 && $this->_diff[$n - 2] === 0) {
            $this->removed = true;
        }
        // add `@@` context line at end of diff to allow expanding file
        if ($n >= 16
            && $this->_diff[$n - 4] === ' '
            && $this->_diff[$n - 8] === ' '
            && $this->_diff[$n - 12] === ' ') {
            array_push($this->_diff, "@", null, null, "");
            $this->_diffsz += 4;
        }
    }

    function finish_unloaded() {
        $this->finish();
        $this->loaded = false;
        $this->collapse = true;
    }


    /** @return bool */
    function is_empty() {
        return $this->_diffsz === 0;
    }

    /** @return bool */
    function is_handout_commit_a() {
        if ($this->_hasha_hrepo === null) {
            $this->_hasha_hrepo = $this->_pset && $this->_pset->handout_commit($this->_hasha);
        }
        return $this->_hasha_hrepo;
    }

    /** @return int */
    function max_lineno() {
        $l = $this->_diffsz;
        $max = 0;
        $have = 0;
        while ($l !== 0 && $have !== 3) {
            $l -= 4;
            if ($this->_diff[$l + 1] !== null) {
                $max = max($max, $this->_diff[$l + 1]);
                $have |= 1;
            }
            if ($this->_diff[$l + 2] !== null) {
                $max = max($max, $this->_diff[$l + 2]);
                $have |= 2;
            }
        }
        return $max;
    }

    /** @param int $i
     * @return ?array{string,?int,?int,string} */
    function entry($i) {
        if ($i >= 0 && $i < ($this->_diffsz >> 2)) {
            return array_slice($this->_diff, $i << 2, 4);
        } else {
            return null;
        }
    }

    /** @param int $off
     * @param int $line
     * @return int */
    private function line_lower_bound($off, $line) {
        $l = 0;
        $r = $this->_diffsz;
        while ($l < $r) {
            $m0 = $m = $l + ((($r - $l) >> 2) & ~3);
            while ($m + 4 < $r && $this->_diff[$m + $off] === null) {
                $m += 4;
            }
            $ln = $this->_diff[$m + $off];
            if ($ln === null || $ln >= $line) {
                $r = $m0;
            } else {
                $l = $m + 4;
            }
        }
        return $l;
    }

    /** @param int $linea
     * @return bool */
    function contains_linea($linea) {
        $l = $this->line_lower_bound(1, $linea);
        return $l < $this->_diffsz && $this->_diff[$l] !== "@";
    }

    /** @param string $lineid
     * @return bool */
    function contains_lineid($lineid) {
        assert($lineid[0] === "a" || $lineid[0] === "b");
        $l = $this->line_lower_bound($lineid[0] === "a" ? 1 : 2, (int) substr($lineid, 1));
        return $l < $this->_diffsz && $this->_diff[$l] !== "@";
    }

    /** @param string $a
     * @param string $b
     * @return int */
    function compare_lineid($a, $b) {
        $oa = $a[0] === "a" ? 1 : 2;
        $ob = $b[0] === "a" ? 1 : 2;
        $na = (int) substr($a, 1);
        $nb = (int) substr($b, 1);
        if ($oa === $ob) {
            return $na - $nb;
        } else {
            $la = $this->line_lower_bound($oa, $na);
            $lb = $this->line_lower_bound($ob, $nb);
            if ($la !== $lb) {
                return $la < $lb ? -1 : 1;
            } else if ($la < $this->_diffsz) {
                $da = $na - $this->_diff[$oa];
                $db = $nb - $this->_diff[$ob];
                if ($da !== $db) {
                    return $da < $db ? -1 : 1;
                }
            }
            return $ob - $oa;
        }
    }

    private function fix_context($pos) {
        $lx = $rx = $pos;
        while ($lx >= 0 && $this->_diff[$lx] !== "@") {
            $lx -= 4;
        }
        $rx = max($rx, $lx + 4);
        while ($rx < $this->_diffsz && $this->_diff[$rx] !== "@") {
            $rx += 4;
        }
        if ($lx >= 0 && $rx < $this->_diffsz) {
            $lnal = $this->_diff[$lx + 5];
            $lnbl = $this->_diff[$lx + 6];
            $ch = $this->_diff[$rx - 4];
            $lnar = $this->_diff[$rx - 3] + ($ch === " " || $ch === "-" ? 1 : 0);
            $lnbr = $this->_diff[$rx - 2] + ($ch === " " || $ch === "+" ? 1 : 0);
            $this->_diff[$lx + 3] = preg_replace('/^@@[-+,\d ]*@@.*/', "@@ -{$lnal}," . ($lnar - $lnal) . " +{$lnbl}," . ($lnbr - $lnbl) . " @@", $this->_diff[$lx + 3]);
        }
    }

    /** @param int $linea_lx
     * @param int $linea_rx
     * @return bool */
    function expand_linea($linea_lx, $linea_rx) {
        return $this->expand_line(1, $linea_lx, $linea_rx);
    }

    /** @param int $lineb_lx
     * @param int $lineb_rx
     * @return bool */
    function expand_lineb($lineb_lx, $lineb_rx) {
        return $this->expand_line(2, $lineb_lx, $lineb_rx);
    }

    /** @param 'a'|'b'|1|2 $off
     * @param int $line_lx
     * @param int $line_rx
     * @return bool */
    function expand_line($off, $line_lx, $line_rx) {
        if ($off === "a" || $off === "b") {
            $off = ($off === "a" ? 1 : 2);
        }
        assert($off === 1 || $off === 2);

        // look for left-hand edge of desired region
        $line_lx = max(1, $line_lx);
        $l = $this->line_lower_bound($off, $line_lx);

        // advance to hole
        while ($l < $this->_diffsz && $this->_diff[$l] !== "@") {
            if ($this->_diff[$l + $off] === $line_lx) {
                ++$line_lx;
                if ($line_lx === $line_rx)
                    return true;
            }
            $l += 4;
        }
        assert($l === $this->_diffsz || $this->_diff[$l] === "@");
        assert($l === 0 || $this->_diff[$l - 3] !== null);

        // if we get here, we need to insert
        // XXX assert(we are returning context lines)
        if (!$this->_repoa) {
            return false;
        }

        // expand $l to [$lx, $rx]: need line numbers
        $lx = $rx = $l;
        while ($lx >= 0 && $lx < $this->_diffsz && $this->_diff[$lx + 1] === null) {
            $lx -= 4;
        }
        while ($rx < $this->_diffsz && $this->_diff[$rx + 1] === null) {
            $rx += 4;
        }

        // $deltab translates old numbers to new line numbers;
        // shift to old line numbers
        $deltab = 0;
        if ($lx >= 0 && $lx < $this->_diffsz) {
            $deltab = $this->_diff[$lx + 2] - $this->_diff[$lx + 1];
        }
        if ($off === 2) {
            $line_lx -= $deltab;
            $line_rx -= $deltab;
        }

        // load old content
        $lines = $this->_repoa->content_lines($this->_hasha, $this->_filenamea);
        if ($line_lx > count($lines)) {
            return true;
        }
        $line_rx = min(count($lines), $line_rx);

        $splice = [];
        if ($lx >= 0 && $lx < $this->_diffsz
            && $this->_diff[$lx + 1] >= $line_lx - 1
            && $rx < $this->_diffsz && $this->_diff[$rx + 1] <= $line_rx) {
            $line_rx = $this->_diff[$rx + 1];
            for ($i = $this->_diff[$lx + 1] + 1; $i < $line_rx; ++$i) {
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            }
            array_splice($this->_diff, $lx + 4, $rx - $lx - 4, $splice);
            $this->fix_context($lx);
        } else if ($lx >= 0 && $lx < $this->_diffsz
                   && $this->_diff[$lx + 1] >= $line_lx - 1) {
            for ($i = $this->_diff[$lx + 1] + 1; $i < $line_rx; ++$i) {
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            }
            array_splice($this->_diff, $lx + 4, 0, $splice);
            $this->fix_context($lx);
        } else if ($rx < $this->_diffsz && $this->_diff[$rx + 1] <= $line_rx) {
            $line_rx = $this->_diff[$rx + 1];
            for ($i = $line_lx; $i < $line_rx; ++$i) {
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            }
            array_splice($this->_diff, $rx, 0, $splice);
            $this->fix_context($rx);
        } else {
            $linecount = $line_rx - $line_lx;
            array_push($splice, "@", null, null, "@@ -{$line_lx},{$linecount} +" . ($line_lx + $deltab) . ",{$linecount} @@");
            for ($i = $line_lx; $i < $line_rx; ++$i) {
                array_push($splice, " ", $i, $i + $deltab, $lines[$i - 1]);
            }
            array_splice($this->_diff, $l, 0, $splice);
        }
        $this->_diffsz = count($this->_diff);

        // remove last context line if appropriate
        if ($line_rx === count($lines)
            && $this->_diffsz > 0 && $this->_diff[$this->_diffsz - 4] === "@") {
            $this->_diffsz -= 4;
            array_splice($this->_diff, $this->_diffsz, 4);
        }

        return true;
    }

    /** @param int $linea_lx
     * @param int $linea_rx
     * @return DiffInfo */
    function restrict_linea($linea_lx, $linea_rx) {
        $l = $this->line_lower_bound(1, $linea_lx);
        $r = $this->line_lower_bound(1, $linea_rx);
        while ($l < $r && $this->_diff[$l] === "+") {
            $l += 4;
        }
        while ($r < $this->_diffsz
               && ($this->_diff[$r] === "+"
                   || ($this->_diff[$r] === "-" && $this->_diff[$r + 1] <= $linea_rx))) {
            $r += 4;
        }
        $c = clone $this;
        if ($l > 0
            && $l < $this->_diffsz
            && $this->_diff[$l] !== "@") {
            $c->_diff = array_slice($this->_diff, $l - 4, $r - $l + 4);
            $c->_diffsz = $r - $l + 4;
            $c->_diff[0] = "@";
            $c->_diff[1] = $c->_diff[2] = null;
            $c->_diff[3] = "@@ @@";
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


    /** @param DiffInfo $a
     * @param DiffInfo $b
     * @return int */
    static function compare($a, $b) {
        if ($a->position != $b->position) {
            return $a->position < $b->position ? -1 : 1;
        } else {
            return strcmp($a->filename, $b->filename);
        }
    }


    /** @return array{string,?int,?int,string,?int} */
    function current() {
        $x = array_slice($this->_diff, $this->_itpos, 4);
        if ($this->_dflags !== null && isset($this->_dflags[$this->_itpos])) {
            $x[] = $this->_dflags[$this->_itpos];
        }
        return $x;
    }
    /** @return int */
    function key() {
        return $this->_itpos >> 2;
    }
    /** @return void */
    function next() {
        $this->_itpos += 4;
    }
    /** @return void */
    function rewind() {
        $this->_itpos = 0;
    }
    /** @return bool */
    function valid() {
        return $this->_itpos < $this->_diffsz;
    }
    /** @return string */
    function current_expandmark() {
        assert($this->_diff[$this->_itpos] === "@");
        if ($this->_itpos === 0
            && ($this->_diffsz === 4 || $this->_diff[4] !== " ")) {
            // fully deleted or inserted
            return "";
        } else if ($this->_itpos === 0) {
            $la = $lb = 1;
        } else {
            $la = $this->_diff[$this->_itpos - 3] + 1;
            $lb = $this->_diff[$this->_itpos - 2] + 1;
        }
        assert($la !== null && $lb !== null);
        if ($this->_itpos + 4 === $this->_diffsz) {
            return "a{$la}b{$lb}+";
        } else {
            $n = $this->_diff[$this->_itpos + 6] - $lb;
            return $n ? "a{$la}b{$lb}+$n" : "";
        }
    }
}
