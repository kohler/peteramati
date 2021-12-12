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
    public $notes;
    /** @var ?string */
    private $notesOverflow;
    /** @var ?object */
    private $jnotes;
    /** @var int */
    public $notesversion;

    private function merge() {
        $this->cid = (int) $this->cid;
        $this->pset = (int) $this->pset;
        $this->notesversion = (int) $this->notesversion;
        $this->updateat = (int) $this->updateat;
        $this->updateby = (int) $this->updateby;
        $this->studentupdateat = (int) $this->studentupdateat;
        $this->notes = $this->notesOverflow ?? $this->notes;
        $this->notesOverflow = null;
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
    function jrevdelta() {
        if ($this->jnotes === null && $this->notes !== null) {
            $this->jnotes = json_decode($this->notes);
        }
        return $this->jnotes;
    }

    /** @param object $jnotes
     * @return object */
    function apply_revdelta($jnotes) {
        return json_update($jnotes, $this->jrevdelta());
    }
}
