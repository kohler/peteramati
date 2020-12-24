<?php
// commitpsetinfo.php -- Peteramati helper class representing commit/pset
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class CommitPsetInfo {
    /** @var int */
    public $pset;
    /** @var non-empty-string */
    public $bhash;
    /** @var non-empty-string */
    public $hash;
    /** @var int */
    public $repoid;
    /** @var ?string */
    public $notes;
    /** @var ?string */
    private $notesOverflow;
    /** @var ?object */
    private $jnotes;
    /** @var int */
    public $notesversion;
    /** @var int */
    public $hasflags;
    /** @var int */
    public $hasactiveflags;
    /** @var int */
    public $haslinenotes;
    /** @var ?CommitPsetInfo */
    public $sset_next;

    private function merge() {
        $this->pset = (int) $this->pset;
        /** @phan-suppress-next-line PhanTypeMismatchProperty */
        $this->hash = bin2hex($this->bhash);
        $this->repoid = (int) $this->repoid;
        $this->notesversion = (int) $this->notesversion;
        $this->hasflags = (int) $this->hasflags;
        $this->hasactiveflags = (int) $this->hasactiveflags;
        $this->haslinenotes = (int) $this->haslinenotes;
        $this->notes = $this->notesOverflow ?? $this->notes;
        $this->notesOverflow = null;
    }

    /** @return ?CommitPsetInfo */
    static function fetch($result) {
        $rp = $result->fetch_object("CommitPsetInfo");
        if ($rp) {
            $rp->merge();
        }
        return $rp;
    }

    /** @param non-empty-string $hash
     * @return CommitPsetInfo */
    static function make_new(Pset $pset, Repository $repo, $hash) {
        assert(strlen($hash) === 40 || strlen($hash) === 20);
        $cpi = new CommitPsetInfo;
        $cpi->pset = $pset->id;
        if (strlen($hash) === 40) {
            /** @phan-suppress-next-line PhanTypeMismatchProperty */
            $cpi->bhash = hex2bin($hash);
            $cpi->hash = $hash;
        } else {
            $cpi->bhash = $hash;
            /** @phan-suppress-next-line PhanTypeMismatchProperty */
            $cpi->hash = bin2hex($hash);
        }
        $cpi->repoid = $repo->repoid;
        $cpi->notesversion = 0;
        $cpi->hasflags = 0;
        $cpi->hasactiveflags = 0;
        $cpi->haslinenotes = 0;
        return $cpi;
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
     * @param ?object $jnotes
     * @param int $notesversion */
    function assign_notes($notes, $jnotes, $notesversion) {
        $this->notes = $notes;
        $this->jnotes = $jnotes;
        $this->notesversion = $notesversion;
    }
}
