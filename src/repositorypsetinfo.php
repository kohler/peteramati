<?php
// repositorypsetinfo.php -- Peteramati helper class representing repository/pset
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class RepositoryPsetInfo {
    /** @var int */
    public $repoid;
    /** @var int */
    public $branchid;
    /** @var int */
    public $pset;
    /** @var ?non-empty-string */
    public $gradebhash;
    /** @var ?non-empty-string */
    public $gradehash;
    /** @var ?int */
    public $commitat;
    /** @var ?int */
    public $gradercid;
    /** @var int */
    public $hidegrade;
    /** @var int */
    public $placeholder;
    /** @var ?int */
    public $placeholder_at;

    // joined from CommitNotes
    /** @var ?string */
    public $notes;
    /** @var ?object */
    private $jnotes;
    /** @var ?int */
    public $notesversion;
    /** @var ?bool */
    public $joined_commitnotes;

    private function merge() {
        $this->repoid = (int) $this->repoid;
        $this->branchid = (int) $this->branchid;
        $this->pset = (int) $this->pset;
        if (isset($this->gradebhash)) {
            /** @phan-suppress-next-line PhanTypeMismatchProperty */
            $this->gradehash = bin2hex($this->gradebhash);
        }
        if (isset($this->commitat)) {
            $this->commitat = (int) $this->commitat;
        }
        if (isset($this->gradercid)) {
            $this->gradercid = (int) $this->gradercid;
        }
        $this->hidegrade = (int) $this->hidegrade;
        $this->placeholder = (int) $this->placeholder;
        if (isset($this->placeholder_at)) {
            $this->placeholder_at = (int) $this->placeholder_at;
        }

        if (isset($this->notesversion)) {
            $this->notesversion = (int) $this->notesversion;
        }
    }

    /** @return ?RepositoryPsetInfo */
    static function fetch($result) {
        $rp = $result->fetch_object("RepositoryPsetInfo");
        if ($rp) {
            $rp->merge();
        }
        return $rp;
    }

    /** @return ?object */
    function jnotes() {
        if ($this->jnotes === null && $this->notes !== null) {
            $this->jnotes = json_decode($this->notes);
        }
        return $this->jnotes;
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
