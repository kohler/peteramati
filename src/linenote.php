<?php

class LineNote implements JsonUpdatable {
    public $file;
    public $lineid;
    public $iscomment = false;
    public $note;
    public $users = [];
    public $version;
    public $format;

    function __construct($file, $lineid) {
        $this->file = $file;
        $this->lineid = $lineid;
    }

    static function make_json($file, $lineid, $x) {
        $ln = new LineNote($file, $lineid);
        if (is_object($x) && property_exists($x, 0)) {
            $x_in = $x;
            $x = [];
            for ($i = 0; property_exists($x_in, $i); ++$i) {
                $x[] = $x_in->$i;
            }
        }
        if (is_int($x) || $x === null) {
            $ln->version = $x;
        } else if (is_string($x)) {
            $ln->note = $x;
        } else if (is_array($x)) {
            $ln->iscomment = $x[0];
            $ln->note = $x[1];
            if (isset($x[2]) && is_int($x[2])) {
                $ln->users[] = $x[2];
            } else if (isset($x[2]) && is_array($x[2])) {
                $ln->users = $x[2];
            }
            if (isset($x[3])) {
                $ln->version = $x[3];
            }
            if (isset($x[4]) && is_int($x[4])) {
                $ln->format = $x[4];
            }
        }
        return $ln;
    }

    function is_empty() {
        return (string) $this->note === "";
    }


    function render_line_link_html(Pset $pset = null) {
        $f = $this->file;
        if ($pset && str_starts_with($f, $pset->directory_slash)) {
            $f = substr($f, strlen($pset->directory_slash));
        }
        return '<a class="ui pa-goto pa-noteref" href="#L' . $this->lineid
            . '_' . html_id_encode($this->file) . '">' . htmlspecialchars($f)
            . ':' . substr($this->lineid, 1) . '</a>';
    }


    function jsonIsReplacement() {
        return true;
    }
    function jsonSerialize() {
        if ((string) $this->note === "") {
            return $this->version;
        } else {
            $j = [$this->iscomment, $this->note,
                  count($this->users) === 1 ? $this->users[0] : $this->users];
            if ($this->version || $this->format !== null) {
                $j[] = $this->version;
            }
            if ($this->format !== null) {
                $j[] = $this->format;
            }
            return $j;
        }
    }
    function render_json($can_view_authors) {
        if (!$can_view_authors) {
            $j = [$this->iscomment, $this->note];
            if ($this->format !== null) {
                array_push($j, null, null, $this->format);
            }
            return $j;
        } else {
            return $this->jsonSerialize();
        }
    }
}
