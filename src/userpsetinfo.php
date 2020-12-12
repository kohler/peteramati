<?php
// userpsetinfo.php -- Peteramati helper class representing user/pset
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class UserPsetInfo {
    /** @var int */
    public $cid;
    /** @var int */
    public $pset;
    /** @var ?int */
    public $gradercid;
    /** @var ?int */
    public $updateat;
    /** @var ?int */
    public $updateby;
    /** @var int */
    public $hidegrade;
    /** @var ?string */
    public $notes;
    /** @var ?object */
    private $jnotes;
    /** @var int */
    public $notesversion;
    /** @var int */
    public $hasactiveflags;

    private function merge() {
        $this->cid = (int) $this->cid;
        $this->pset = (int) $this->pset;
        $this->notesversion = (int) $this->notesversion;
        if (isset($this->updateat)) {
            $this->updateat = (int) $this->updateat;
        }
        if (isset($this->gradercid)) {
            $this->gradercid = (int) $this->gradercid;
        }
        $this->hidegrade = (int) $this->hidegrade;
        $this->hasactiveflags = (int) $this->hasactiveflags;
    }

    /** @return ?UserPsetInfo */
    static function fetch($result) {
        $upi = $result->fetch_object("UserPsetInfo");
        if ($upi) {
            $upi->merge();
        }
        return $upi;
    }

    /** @return UserPsetInfo */
    static function make_new(Pset $pset, Contact $user) {
        $upi = new UserPsetInfo;
        $upi->cid = $user->contactId;
        $upi->pset = $pset->id;
        $upi->notesversion = 0;
        $upi->hidegrade = 0;
        $upi->hasactiveflags = 0;
        return $upi;
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
