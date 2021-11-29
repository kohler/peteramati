<?php
// commitpsetinfo.php -- Peteramati helper class representing commit/pset
// Peteramati is Copyright (c) 2013-2021 Eddie Kohler
// See LICENSE for open-source distribution terms

class CommitPsetInfo {
    /** @var int */
    public $pset;
    /** @var non-empty-string */
    public $bhash;
    /** @var int */
    public $repoid;
    /** @var ?int */
    public $commitat;
    /** @var int */
    public $notesversion = 0;
    /** @var ?string */
    public $notes;
    /** @var int */
    public $hasflags = 0;
    /** @var int */
    public $hasactiveflags = 0;
    /** @var int */
    public $haslinenotes = 0;
    /** @var ?string */
    public $xnotes;

    /** @var non-empty-string */
    public $hash;
    /** @var ?object */
    private $jnotes;
    /** @var ?object */
    private $jxnotes;
    /** @var ?CommitPsetInfo */
    public $sset_next;
    /** @var ?CommitPsetInfo */
    public $sset_repo_next;
    /** @var bool */
    public $phantom = true;

    /** @param int $pset
     * @param non-empty-string $hash
     * @param int $repoid */
    function __construct($pset, $hash, $repoid) {
        $this->pset = $pset;
        if (strlen($hash) === 40) {
            /** @phan-suppress-next-line PhanTypeMismatchProperty */
            $this->bhash = hex2bin($hash);
            $this->hash = $hash;
        } else {
            $this->bhash = $hash;
            /** @phan-suppress-next-line PhanTypeMismatchProperty */
            $this->hash = bin2hex($hash);
        }
        $this->repoid = $repoid;
    }

    /** @return ?CommitPsetInfo */
    static function fetch($result) {
        if (($x = $result->fetch_object())) {
            $cpi = new CommitPsetInfo((int) $x->pset, $x->bhash, (int) $x->repoid);
            $cpi->merge($x);
            $cpi->phantom = false;
            return $cpi;
        } else {
            return null;
        }
    }

    function reload(Conf $conf) {
        $x = $conf->fetch_first_object("select * from CommitNotes where pset=? and bhash=?",
            $this->pset, $this->bhash);
        $this->merge($x ?? new CommitPsetInfo($this->pset, $this->bhash, $this->repoid));
        $this->phantom = !$x;
    }

    /** @param object $x */
    private function merge($x) {
        assert($this->pset === (int) $x->pset && $this->bhash === $x->bhash);
        $this->repoid = (int) $x->repoid;
        $this->commitat = isset($x->commitat) ? (int) $x->commitat : null;
        $this->notesversion = (int) $x->notesversion;
        $this->notes = $x->notesOverflow ?? $x->notes;
        $this->hasflags = (int) $x->hasflags;
        $this->hasactiveflags = (int) $x->hasactiveflags;
        $this->haslinenotes = (int) $x->haslinenotes;
        $this->xnotes = $x->xnotesOverflow ?? $x->xnotes;
        $this->jnotes = null;
        $this->jxnotes = null;
    }

    function materialize(Conf $conf) {
        if ($this->phantom) {
            $conf->qe("insert into CommitNotes set pset=?, bhash=?, repoid=? on duplicate key update pset=pset",
                $this->pset, $this->bhash, $this->repoid);
            $this->reload($conf);
        }
    }


    /** @return ?object */
    function jnotes() {
        if ($this->jnotes === null && $this->notes !== null) {
            $this->jnotes = json_decode($this->notes);
        }
        return $this->jnotes;
    }

    /** @param string $key
     * @return mixed */
    function jnote($key) {
        $jn = $this->jnotes();
        return $jn ? $jn->$key ?? null : null;
    }

    /** @param ?string $notes
     * @param ?object $jnotes */
    function assign_notes($notes, $jnotes) {
        $this->notes = $notes;
        $this->jnotes = $jnotes;
        $this->notesversion = $this->phantom ? 1 : $this->notesversion + 1;
        $this->phantom = false;
    }


    /** @return ?object */
    function jxnotes() {
        if ($this->jxnotes === null && $this->xnotes !== null) {
            $this->jxnotes = json_decode($this->xnotes);
        }
        return $this->jxnotes;
    }

    /** @param string $key
     * @return mixed */
    function jxnote($key) {
        $jn = $this->jxnotes();
        return $jn ? $jn->$key ?? null : null;
    }

    /** @param ?string $xnotes
     * @param ?object $jxnotes */
    function assign_xnotes($xnotes, $jxnotes) {
        assert(!$this->phantom);
        $this->xnotes = $xnotes;
        $this->jxnotes = $jxnotes;
    }


    /** @return null|int|float */
    function grade_total(Pset $pset) {
        assert($pset->id === $this->pset);
        $t = null;
        if ($this->notes && ($jn = $this->jnotes())) {
            $g = $jn->grades ?? null;
            $ag = $jn->autogrades ?? null;
            if ($g || $ag) {
                foreach ($pset->grades as $ge) {
                    if (!$ge->no_total) {
                        if ($g && property_exists($g, $ge->key)) {
                            $v = $g->{$ge->key};
                        } else {
                            $v = $ag ? $ag->{$ge->key} ?? null : null;
                        }
                        if ($v !== null) {
                            $t = ($t ?? 0) + $v;
                        }
                    }
                }
            }
        }
        return $t;
    }
}
