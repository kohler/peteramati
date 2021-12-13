<?php
// userpsethistory.php -- Peteramati helper class representing user/pset history
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class UserPsetHistory {
    /** @var int */
    public $cid;
    /** @var int */
    public $pset;
    /** @var ?int */
    public $updateat;
    /** @var ?int */
    public $updateby;
    /** @var ?int */
    public $studentupdateat;
    /** @var ?string */
    public $antiupdate;
    /** @var ?string */
    private $antiupdateOverflow;
    /** @var ?object */
    private $jantiupdate;
    /** @var int */
    public $notesversion;

    private function merge() {
        $this->cid = (int) $this->cid;
        $this->pset = (int) $this->pset;
        $this->notesversion = (int) $this->notesversion;
        $this->updateat = (int) $this->updateat;
        $this->updateby = (int) $this->updateby;
        $this->studentupdateat = (int) $this->studentupdateat;
        $this->antiupdate = $this->antiupdateOverflow ?? $this->antiupdate;
        $this->antiupdateOverflow = null;
    }

    /** @return ?UserPsetHistory */
    static function fetch($result) {
        $uph = $result->fetch_object("UserPsetHistory");
        if ($uph) {
            $uph->merge();
        }
        return $uph;
    }

    /** @return ?object */
    function jantiupdate() {
        if ($this->jantiupdate === null && $this->antiupdate !== null) {
            $this->jantiupdate = json_decode($this->antiupdate);
        }
        return $this->jantiupdate;
    }

    /** @param object $jnotes
     * @return object */
    function apply_revdelta($jnotes) {
        return json_update($jnotes, $this->jantiupdate());
    }
}
