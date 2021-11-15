<?php
// linenotesorder.php -- Peteramati helper class for line notes
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

class LineNotesOrder {
    /** @var array<string,array<string,LineNote>> */
    private $ln = [];
    /** @var list<LineNote> */
    private $lnseq = [];
    /** @var bool */
    private $sorted = false;
    /** @var array<string,int> */
    private $fileorder = [];
    /** @var ?array<string,DiffInfo> */
    private $diff = [];
    /** @var bool */
    public $has_linenotes_in_diff = false;
    /** @var bool
     * @readonly */
    public $view_grades;
    /** @var bool
     * @readonly */
    public $view_authors;

    /** @param bool $view_grades
     * @param bool $view_authors */
    function __construct($view_grades, $view_authors) {
        $this->view_grades = $view_grades;
        $this->view_authors = $view_authors;
    }

    /** @param object|array $json */
    function add_json_map($json) {
        $this->sorted = false;
        foreach ($json as $file => $notelist) {
            $fln = &$this->ln[$file];
            $empty = empty($fln);
            foreach ($notelist as $lineid => $note) {
                if ((is_string($note) && $note !== "")
                    || (is_array($note)
                        && ($note[0] || $this->view_grades)
                        && ((string) $note[1] !== "" || $this->view_authors))
                    || (is_int($note) && $this->view_authors)) {
                    assert(!isset($fln[$lineid]));
                    $note = LineNote::make_json($file, $lineid, $note);
                    $note->view_authors = $this->view_authors;
                    $fln[$lineid] = $note;
                    $this->lnseq[] = $note;
                    if ($empty) {
                        $this->fileorder[$file] = count($this->fileorder) + 1;
                        $empty = false;
                    }
                }
            }
        }
    }

    /** @return bool */
    function is_empty() {
        return empty($this->lnseq);
    }
    /** @return array<string,int> */
    function fileorder() {
        return $this->fileorder;
    }
    /** @param string $file
     * @return bool */
    function file_has_notes($file) {
        return isset($this->fileorder[$file]);
    }
    /** @param string $file
     * @return array<string,LineNote> */
    function file($file) {
        return $this->ln[$file] ?? [];
    }

    /** @param array<string,DiffInfo> $diff */
    function set_diff($diff) {
        $this->diff = $diff;
        $this->sorted = false;
        $this->has_linenotes_in_diff = false;

        foreach ($this->diff as $file => $di) {
            if ($this->file_has_notes($file)) {
                $this->has_linenotes_in_diff = true;
                break;
            }
        }

        $old_fileorder = $this->fileorder;
        $this->fileorder = [];
        foreach ($this->diff as $file => $di) {
            $this->fileorder[$file] = count($this->fileorder) + 1;
        }
        foreach ($old_fileorder as $file => $x) {
            // Normally every file with notes will be present
            // already, but just in case---for example, the
            // handout repo got corrupted...
            if (!isset($this->fileorder[$file]))
                $this->fileorder[$file] = count($this->fileorder) + 1;
        }
    }
    private function ensure_sorted() {
        if (!$this->sorted) {
            usort($this->lnseq, [$this, "compare"]);
            foreach ($this->lnseq as $i => $note) {
                $note->seqpos = $i;
            }
        }
    }
    /** @return list<LineNote> */
    function seq() {
        $this->ensure_sorted();
        return $this->lnseq;
    }
    /** @param LineNote $note
     * @return ?LineNote */
    function get_next($note) {
        $this->ensure_sorted();
        $seq = $note->seqpos;
        while ($seq !== null && $seq !== count($this->lnseq) - 1) {
            ++$seq;
            if (!$this->lnseq[$seq]->is_empty()) {
                return $this->lnseq[$seq];
            }
        }
        return null;
    }
    /** @param LineNote $note
     * @return ?LineNote */
    function get_prev($note) {
        $this->ensure_sorted();
        $seq = $note->seqpos;
        while ($seq !== null && $seq !== 0) {
            --$seq;
            if (!$this->lnseq[$seq]->is_empty()) {
                return $this->lnseq[$seq];
            }
        }
        return null;
    }
    /** @param LineNote $a
     * @param LineNote $b
     * @return int */
    function compare($a, $b) {
        if ($a->file != $b->file) {
            return $this->fileorder[$a->file] - $this->fileorder[$b->file];
        } else if (!$this->diff || !isset($this->diff[$a->file])) {
            return strcmp($a->lineid, $b->lineid);
        } else if ($a->lineid[0] === $b->lineid[0]) {
            return (int) substr($a->lineid, 1) - (int) substr($b->lineid, 1);
        } else {
            return $this->diff[$a->file]->compare_lineid($a->lineid, $b->lineid);
        }
    }
}
