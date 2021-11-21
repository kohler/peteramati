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
    /** @var ?int */
    public $commitat;
    /** @var ?int */
    public $gradercid;
    /** @var int */
    public $hidegrade = 0;
    /** @var int */
    public $placeholder = 1;
    /** @var ?int */
    public $placeholder_at;
    /** @var ?int */
    public $emptydiff_at;
    /** @var ?int */
    public $rpnotesversion;
    /** @var ?string */
    public $rpnotes;
    /** @var ?string */
    public $rpxnotes;

    /** @var ?non-empty-string */
    public $gradehash;
    /** @var ?object */
    private $jrpnotes;
    /** @var ?object */
    private $jrpxnotes;
    /** @var ?RepositoryPsetInfo */
    public $sset_next;
    /** @var bool */
    public $phantom = true;

    /** @param int $repoid
     * @param int $branchid
     * @param int $pset */
    function __construct($repoid, $branchid, $pset) {
        $this->repoid = $repoid;
        $this->branchid = $branchid;
        $this->pset = $pset;
    }

    /** @return ?RepositoryPsetInfo */
    static function fetch($result) {
        if (($x = $result->fetch_object())) {
            $rpi = new RepositoryPsetInfo((int) $x->repoid, (int) $x->branchid, (int) $x->pset);
            $rpi->merge($x);
            $rpi->phantom = false;
            return $rpi;
        } else {
            return null;
        }
    }

    function reload(Conf $conf) {
        $x = $conf->fetch_first_object("select * from RepositoryGrade where repoid=? and branchid=? and pset=?",
            $this->repoid, $this->branchid, $this->pset);
        $this->merge($x ?? new RepositoryPsetInfo($this->repoid, $this->branchid, $this->pset));
        $this->phantom = !$x;
    }

    /** @param object $x */
    private function merge($x) {
        assert($this->repoid === (int) $x->repoid
               && $this->branchid === (int) $x->branchid
               && $this->pset === (int) $x->pset);
        $this->gradebhash = $x->gradebhash;
        $this->commitat = isset($x->commitat) ? (int) $x->commitat : null;
        $this->gradercid = isset($x->gradercid) ? (int) $x->gradercid : null;
        $this->hidegrade = (int) $x->hidegrade;
        $this->placeholder = (int) $x->placeholder;
        $this->placeholder_at = isset($x->placeholder_at) ? (int) $x->placeholder_at : null;
        $this->emptydiff_at = isset($x->emptydiff_at) ? (int) $x->emptydiff_at : null;
        $this->rpnotesversion = isset($x->rpnotesversion) ? (int) $x->rpnotesversion : null;
        $this->rpnotes = $x->rpnotesOverflow ?? $x->rpnotes;
        $this->rpxnotes = $x->rpxnotesOverflow ?? $x->rpxnotes;
        $this->gradehash = isset($x->gradebhash) ? bin2hex($x->gradebhash) : null;
        $this->jrpnotes = null;
        $this->jrpxnotes = null;
    }

    function materialize(Conf $conf) {
        if ($this->phantom) {
            $conf->qe("insert into RepositoryGrade set repoid=?, branchid=?, pset=?, placeholder=1 on duplicate key update repoid=repoid",
                $this->repoid, $this->branchid, $this->pset);
            $this->reload($conf);
        }
    }


    /** @return ?object */
    function jrpnotes() {
        if ($this->jrpnotes === null && $this->rpnotes !== null) {
            $this->jrpnotes = json_decode($this->rpnotes);
        }
        return $this->jrpnotes;
    }

    /** @param ?string $rpnotes
     * @param ?object $jrpnotes */
    function assign_rpnotes($rpnotes, $jrpnotes) {
        $this->rpnotes = $rpnotes;
        $this->jrpnotes = $jrpnotes;
        $this->rpnotesversion = $this->phantom ? 1 : $this->rpnotesversion + 1;
        $this->phantom = false;
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
        assert(!$this->phantom);
        $this->rpxnotes = $rpxnotes;
        $this->jrpxnotes = $jrpxnotes;
    }
}
