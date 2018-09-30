<?php
// linenotesorder.php -- Peteramati helper class for linenotes
// Peteramati is Copyright (c) 2006-2018 Eddie Kohler
// See LICENSE for open-source distribution terms

class LinenotesOrder {
    private $diff = null;
    private $fileorder = [];
    private $ln;
    private $lnseq = [];
    private $lnorder = null;
    private $totalorder = null;
    public $has_linenotes_in_diff = false;

    function __construct($linenotes, $seegradenotes) {
        $this->ln = $linenotes ? : [];
        foreach ($this->ln as $file => $notelist) {
            if (!isset($this->fileorder[$file]))
                $this->fileorder[$file] = count($this->fileorder);
            foreach ($notelist as $line => $note) {
                if ((is_string($note) && $note !== "")
                    || (is_array($note) && ($note[0] || $seegradenotes)
                        && (string) $note[1] !== ""))
                    $this->lnseq[] = array($file, $line, is_array($note) && $note[0]);
            }
        }
    }
    function note_files() {
        return $this->fileorder;
    }
    function set_diff($diff) {
        $this->diff = $diff;
        $this->has_linenotes_in_diff = false;
        foreach ($this->diff as $file => $di) {
            if (get($this->ln, $file)) {
                $this->has_linenotes_in_diff = true;
                break;
            }
        }

        $old_fileorder = $this->fileorder;
        $this->fileorder = [];
        foreach ($this->diff as $file => $di)
            $this->fileorder[$file] = count($this->fileorder);
        foreach ($old_fileorder as $file => $x) {
            // Normally every file with notes will be present
            // already, but just in case---for example, the
            // handout repo got corrupted...
            if (!isset($this->fileorder[$file]))
                $this->fileorder[$file] = count($this->fileorder);
        }
        $this->lnorder = null;
    }
    private function ensure_lnorder() {
        if ($this->lnorder === null) {
            $this->totalorder = [];
            usort($this->lnseq, [$this, "compare"]);
            foreach ($this->lnseq as $i => $fl)
                $this->lnorder[$fl[1] . "_" . $fl[0]] = $i;
        }
    }
    function file($file) {
        return get($this->ln, $file);
    }
    function seq() {
        $this->ensure_lnorder();
        return $this->lnseq;
    }
    function get_next($file, $lineid) {
        $this->ensure_lnorder();
        $seq = $this->lnorder[$lineid . "_" . $file];
        if ($seq === null || $seq == count($this->lnseq) - 1)
            return array(null, null);
        else
            return $this->lnseq[$seq + 1];
    }
    function get_prev($file, $lineid) {
        $this->ensure_lnorder();
        $seq = $this->lnorder[$lineid . "_" . $file];
        if ($seq === null || $seq == 0)
            return array(null, null);
        else
            return $this->lnseq[$seq - 1];
    }
    function compare($a, $b) {
        if ($a[0] != $b[0])
            return $this->fileorder[$a[0]] - $this->fileorder[$b[0]];
        if (!$this->diff || !get($this->diff, $a[0]))
            return strcmp($a[1], $b[1]);
        if ($a[1][0] == $b[1][0])
            return (int) substr($a[1], 1) - (int) substr($b[1], 1);
        $to = get($this->totalorder, $a[0]);
        if (!$to) {
            $to = array();
            $n = 0;
            foreach ($this->diff[$a[0]] as $l) {
                if ($l[0] === "+" || $l[0] === " ")
                    $to["b" . $l[2]] = ++$n;
                if ($l[0] === "-" || $l[0] === " ")
                    $to["a" . $l[1]] = ++$n;
            }
            $this->totalorder[$a[0]] = $to;
        }
        if (!isset($to[$a[1]]) || !isset($to[$b[1]]))
            error_log(json_encode($a) . " / " . json_encode($b) . " / " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        return $to[$a[1]] - $to[$b[1]];
    }
}
