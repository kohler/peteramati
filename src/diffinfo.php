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
