<?php

class LineNote implements JsonUpdatable {
    public $file;
    public $lineid;
    public $iscomment;
    public $note;
    public $users = [];
    public $version;

    function __construct($file, $lineid) {
        $this->file = $file;
        $this->lineid = $lineid;
    }

    static function make_json($file, $lineid, $x) {
        $ln = new LineNote($file, $lineid);
        if (is_object($x)) {
            $x_in = $x;
            $x = [];
            for ($i = 0; property_exists($x_in, $i); ++$i)
                $x[] = $x_in->$i;
        }
        if (is_int($x) || $x === null)
            $ln->version = $x;
        else if (is_string($x))
            $ln->note = $x;
        else if (is_array($x)) {
            $ln->iscomment = $x[0];
            $ln->note = $x[1];
            if (isset($x[2]) && is_int($x[2]))
                $ln->users[] = $x[2];
            else if (isset($x[2]) && is_array($x[2]))
                $ln->users = $x[2];
            if (isset($x[3]))
                $ln->version = $x[3];
        }
        return $ln;
    }
    function jsonIsReplacement() {
        return true;
    }
    function jsonSerialize() {
        if ((string) $this->note === "")
            return $this->version;
        else {
            $j = [$this->iscomment, $this->note,
                  count($this->users) === 1 ? $this->users[0] : $this->users];
            if ($this->version)
                $j[] = $this->version;
            return $j;
        }
    }
    function render_json($can_view_authors) {
        if (!$can_view_authors)
            return [$this->iscomment, $this->note];
        else
            return $this->jsonSerialize();
    }
}
