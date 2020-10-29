<?php
// sourcemapper.php -- PHP class for handling JavaScript source maps
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class SourceLocation implements JsonSerializable {
    /** @var string */
    public $file;
    /** @var int */
    public $line;
    /** @var ?int */
    public $column;
    /** @var ?string */
    public $name;
    /** @var int */
    public $offset;
    function __construct($file, $line, $column, $name, $offset) {
        $this->file = $file;
        $this->line = $line;
        $this->column = $column;
        $this->name = $name;
        $this->offset = $offset;
    }
    function jsonSerialize() {
        return get_object_vars($this);
    }
}

class SourceMapper {
    /** @var string */
    private $file;
    /** @var list<string> */
    private $sources;
    /** @var list<string> */
    private $names;
    /** @var string */
    private $mappings;
    /** @var int */
    private $pos;
    /** @var list<int> */
    private $glineoff = [0, 0];
    /** @var list<int> */
    private $gcol = [];
    /** @var list<int> */
    private $sloc = [];
    /** @var list<?int> */
    private $nameidx = [];
    /** @var array{int,int,int,int,int} */
    private $c = [0, 0, 0, 0, 0];
    function __construct($x) {
        $this->filename = $x->file;
        $this->sources = $x->sources;
        $this->names = $x->names;
        $this->mappings = $x->mappings;
        $this->pos = 0;
    }
    private function scan($amount = 256) {
        $m = $this->mappings;
        $pos = $this->pos;
        $nl = count($this->glineoff) - 1;
        while ($pos !== strlen($m) && $amount > 0) {
            $x = [];
            $v = $off = $neg = 0;
            while ($pos !== strlen($m)
                   && ($ch = $m[$pos]) !== ","
                   && $ch !== ";") {
                $cz = ord($ch);
                if ($cz >= 65 && $cz <= 90) {
                    $b = $cz - 65;
                } else if ($cz >= 97 && $cz <= 122) {
                    $b = $cz - 71;
                } else if ($cz >= 48 && $cz <= 57) {
                    $b = $cz + 4;
                } else if ($cz === 43) {
                    $b = 62;
                } else if ($cz === 47) {
                    $b = 63;
                } else {
                    break;
                }
                if ($off === 0) {
                    $v = ($b & 30) >> 1;
                    $neg = $b & 1;
                    $off = 4;
                } else {
                    $v += ($b & 31) << $off;
                    $off += 5;
                }
                if (($b & 32) === 0) {
                    $x[] = $neg ? -$v : $v;
                    $v = $off = 0;
                }
                ++$pos;
            }
            $this->gcol[] = ($this->c[0] += ($x[0] ?? 0));
            $source = isset($x[1]) ? ($this->c[1] += $x[1]) : 0;
            $sline = isset($x[2]) ? ($this->c[2] += $x[2]) : 0;
            $scol = isset($x[3]) ? ($this->c[3] += $x[3]) : 0;
            $name = isset($x[4]) ? ($this->c[4] += $x[4]) : null;
            $this->spos[] = ($source << 56) | ($sline << 32) | $scol;
            $this->nameidx[] = $name;
            $this->glineoff[$nl] = count($this->spos);
            if ($pos !== strlen($m)) {
                if ($ch === ";") {
                    $this->glineoff[] = count($this->spos);
                    $this->c[0] = 0;
                }
                ++$pos;
            }
            --$amount;
        }
        $this->pos = $pos;
        return $pos !== strlen($m);
    }
    /** @param int $line
     * @param int $column
     * @return ?SourceLocation */
    function lookup($line, $column) {
        set_time_limit(10);
        while ($line > count($this->glineoff) - 2
               || ($line === count($this->glineoff) - 2
                   && ($this->glineoff[$line+1] === $this->glineoff[$line]
                       || $this->gcol[$this->glineoff[$line+1]-1] < $column))) {
            if (!$this->scan())
                break;
        }

        if ($line < count($this->glineoff) - 1) {
            $l = $this->glineoff[$line];
            $r = $rx = $this->glineoff[$line + 1];
        } else {
            $l = $r = $rx = 0;
        }
        while ($l < $r) {
            $m = $l + (($r - $l) >> 1);
            if ($this->gcol[$m] < $column) {
                $l = $m + 1;
            } else if ($this->gcol[$m] > $column) {
                $r = $m;
            } else {
                $l = $r = $m;
            }
        }
        return $l < $rx ? $this->get($l) : null;
    }
    /** @param int $offset
     * @return ?SourceLocation */
    function get($offset) {
        while ($offset >= count($this->gcol) && $this->scan()) {
        }
        if ($offset >= 0 && $offset < count($this->gcol)) {
            $spos = $this->spos[$offset];
            $name = isset($this->nameidx[$offset]) ? $this->names[$this->nameidx[$offset]] ?? null : null;
            return new SourceLocation($this->sources[($spos >> 56) & 255],
                ($spos >> 32) & 0xFFF, $spos & 0xFFFFFFFF, $name, $offset);
        } else {
            return null;
        }
    }
}
