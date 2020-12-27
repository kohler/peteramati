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
    /** @var ?string */
    public $rpnotes;
    /** @var ?string */
    private $rpnotesOverflow;
    /** @var ?object */
    private $jrpnotes;
    /** @var ?string */
    public $rpxnotes;
    /** @var ?string */
    private $rpxnotesOverflow;
    /** @var ?object */
    private $jrpxnotes;
    /** @var ?int */
    public $rpnotesversion;
    /** @var ?int */
    public $emptydiff_at;

    /** @var ?RepositoryPsetInfo */
    public $sset_next;

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
        $this->rpnotes = $this->rpnotesOverflow ?? $this->rpnotes;
        $this->rpnotesOverflow = null;
        if (isset($this->rpnotesversion)) {
            $this->rpnotesversion = (int) $this->rpnotesversion;
        }
        $this->rpxnotes = $this->rpxnotesOverflow ?? $this->rpxnotes;
        $this->rpxnotesOverflow = null;
        if (isset($this->emptydiff_at)) {
            $this->emptydiff_at = (int) $this->emptydiff_at;
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
    function jrpnotes() {
        if ($this->jrpnotes === null && $this->rpnotes !== null) {
            $this->jrpnotes = json_decode($this->rpnotes);
        }
        return $this->jrpnotes;
    }

    /** @param ?string $rpnotes
     * @param ?object $jrpnotes
     * @param int $rpnotesversion */
    function assign_rpnotes($rpnotes, $jrpnotes, $rpnotesversion) {
        $this->rpnotes = $rpnotes;
        $this->jrpnotes = $jrpnotes;
        $this->rpnotesversion = $rpnotesversion;
    }


    /** @return ?object */
    function jrpxnotes() {
        if ($this->jrpxnotes === null && $this->rpxnotes !== null) {
            $this->jrpxnotes = json_decode($this->rpxnotes);
        }
        return $this->jrpxnotes;
    }

    /** @param ?string $rpxnotes
     * @param ?object $jrpxnotes */
    function assign_rpxnotes($rpxnotes, $jrpxnotes) {
        $this->rpxnotes = $rpxnotes;
        $this->jrpxnotes = $jrpxnotes;
    }
}
