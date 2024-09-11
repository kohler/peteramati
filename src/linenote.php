<?php
// linenote.php -- CS61-monster class representing a line note
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

class LineNote implements JsonIsReplacement, JsonSerializable {
    /** @var string */
    public $file;
    /** @var string */
    public $lineid;
    /** @var bool */
    public $iscomment = false;
    /** @var ?string */
    public $ftext;
    /** @var ?int */
    public $version;
    /** @var ?int */
    public $linea;
    /** @var list<int> */
    public $users = [];

    // set by LineNotesOrder
    /** @var ?int */
    public $seqpos;
    /** @var ?bool */
    public $view_authors;

    // set externally
    /** @var ?CommitPsetInfo */
    public $cpi;
    /** @var ?UserPsetInfo */
    public $upi;

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
            $ln->ftext = $x;
        } else if (is_array($x)) {
            $ln->iscomment = $x[0];
            $ln->ftext = $x[1];
            $x2 = $x[2] ?? null;
            if (is_int($x2)) {
                $ln->users[] = $x2;
            } else if (is_array($x2)) {
                $ln->users = $x2;
            }
            if (isset($x[3])) {
                $ln->version = $x[3];
            }
            $x4 = $x[4] ?? null; // NB INCOMPAT NOTE: some old notes store format here
            if (is_int($x4)) {
                $ln->linea = $x4;
            }
        }
        return $ln;
    }

    /** @return bool */
    function is_empty() {
        return (string) $this->ftext === "";
    }

    /** @return int */
    function linea() {
        if (str_starts_with($this->lineid, "a")) {
            return intval(substr($this->lineid, 1));
        } else {
            return (int) $this->linea;
        }
    }


    /** @return string */
    function render_line_link_html(?Pset $pset = null) {
        $f = $this->file;
        if ($pset && str_starts_with($f, $pset->directory_slash)) {
            $f = substr($f, strlen($pset->directory_slash));
        }
        $fileid = html_id_encode($this->file); // XXX only works on single-user pages
        $f = htmlspecialchars($f);
        $l = substr($this->lineid, 1);
        return "<a class=\"pa-noteref\" href=\"#L{$this->lineid}F{$fileid}\">{$f}:{$l}</a>";
    }


    /** @return int|non-empty-list */
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        if ((string) $this->ftext === "") {
            return $this->version;
        } else {
            $u = count($this->users) === 1 ? $this->users[0] : $this->users;
            if ($this->linea) {
                return [$this->iscomment, $this->ftext, $u, $this->version, $this->linea];
            } else if ($this->version) {
                return [$this->iscomment, $this->ftext, $u, $this->version];
            } else {
                return [$this->iscomment, $this->ftext, $u];
            }
        }
    }

    /** @return null|int|non-empty-list */
    function render() {
        if ($this->view_authors === false) {
            if ((string) $this->ftext === "") {
                return null;
            } else {
                return [$this->iscomment, $this->ftext];
            }
        } else if ((string) $this->ftext === "") {
            return $this->version;
        } else {
            $u = count($this->users) === 1 ? $this->users[0] : $this->users;
            return [$this->iscomment, $this->ftext, $u, $this->version];
        }
    }

    /** @return array */
    function render_map() {
        $j = [
            "file" => $this->file,
            "lineid" => $this->lineid,
            "ftext" => $this->ftext,
            "version" => $this->version
        ];
        if ($this->iscomment) {
            $j["iscomment"] = true;
        }
        if ($this->linea) {
            $j["linea"] = $this->linea;
        }
        return $j;
    }
}
