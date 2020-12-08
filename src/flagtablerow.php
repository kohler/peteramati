<?php
// flagtablerow.php -- HotCRP helper class representing rows in the flag table
// Peteramati is Copyright (c) 2013-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

class FlagTableRow {
    /** @var CommitPsetInfo */
    public $cpi;
    /** @var string */
    public $flagid;
    /** @var object */
    public $flag;
    /** @var ?RepositoryPsetInfo */
    public $rpi;
    /** @var list<int> */
    public $repouids = [];

    /** @param string $flagid
     * @param object $flag */
    function __construct(CommitPsetInfo $cpi, $flagid, $flag) {
        $this->pset = $cpi->pset;
        $this->cpi = $cpi;
        $this->flagid = $flagid;
        $this->flag = $flag;
    }

    /** @return int */
    function pset() {
        return $this->cpi->pset;
    }
    /** @return string */
    function hash() {
        return $this->cpi->hash;
    }
    /** @return string */
    function bhash() {
        return $this->cpi->bhash;
    }
    /** @return int */
    function repoid() {
        return $this->cpi->repoid;
    }

    /** @param string $key
     * @return mixed */
    function jnote($key) {
        return $this->cpi->jnote($key);
    }
}
