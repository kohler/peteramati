<?php

class LineNote implements JsonUpdatable {
    /** @var string */
    public $file;
    /** @var string */
    public $lineid;
    /** @var bool */
    public $iscomment = false;
    /** @var ?string */
    public $text;
    /** @var ?int */
    public $version;
    /** @var ?int */
    public $format;
    public $users = [];

    /** @param string $file
     * @param string $lineid */
    function __construct($file, $lineid) {
        $this->file = $file;
        $this->lineid = $lineid;
    }

    static function make_json($file, $lineid, $x) {
        $ln = new LineNote($file, $lineid);
        if (is_object($x) && property_exists($x, "0")) {
            $x_in = $x;
            $x = [];
            for ($i = 0; property_exists($x_in, (string) $i); ++$i) {
                $x[] = $x_in->{(string) $i};
            }
        }
        if (is_int($x) || $x === null) {
            $ln->version = $x;
        } else if (is_string($x)) {
            $ln->text = $x;
        } else if (is_array($x)) {
            $ln->iscomment = $x[0];
            $ln->text = $x[1];
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

    /** @return bool */
    function is_empty() {
        return (string) $this->text === "";
    }


    /** @return string */
    function render_line_link_html(Pset $pset = null) {
        $f = $this->file;
        if ($pset && str_starts_with($f, $pset->directory_slash)) {
            $f = substr($f, strlen($pset->directory_slash));
        }
        $fileid = html_id_encode($this->file); // XXX only works on single-user pages
        $f = htmlspecialchars($f);
        $l = substr($this->lineid, 1);
        return "<a class=\"pa-noteref\" href=\"#L{$this->lineid}F{$fileid}\">{$f}:{$l}</a>";
    }


    function jsonIsReplacement() {
        return true;
    }

    function jsonSerialize() {
        if ((string) $this->text === "") {
            return $this->version;
        } else {
            $j = [$this->iscomment, $this->text,
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
            $j = [$this->iscomment, $this->text];
            if ($this->format !== null) {
                array_push($j, null, null, $this->format);
            }
            return $j;
        } else {
            return $this->jsonSerialize();
        }
    }
}
