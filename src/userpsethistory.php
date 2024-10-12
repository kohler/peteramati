<?php
// userpsethistory.php -- Peteramati helper class representing user/pset history
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class UserPsetHistory {
    /** @var int */
    public $cid;
    /** @var int */
    public $pset;
    /** @var int */
    public $notesversion;
    /** @var int */
    public $antiupdateby;
    /** @var int */
    public $updateat;
    /** @var int */
    public $updateby;
    /** @var ?int */
    public $studentupdateat;
    /** @var ?string */
    public $antiupdate;
    /** @var ?string */
    private $antiupdateOverflow;
    /** @var ?object */
    private $jantiupdate;

    private function merge() {
        $this->cid = (int) $this->cid;
        $this->pset = (int) $this->pset;
        $this->notesversion = (int) $this->notesversion;
        $this->antiupdateby = (int) $this->antiupdateby;
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
     * @param ?Pset $answers_only_pset
     * @return object */
    function apply_revdelta($jnotes, $answers_only_pset) {
        $ja = $this->jantiupdate();
        $jnotes1 = json_update($jnotes, $ja);
        if (!$answers_only_pset || !isset($ja->grades)) {
            return $jnotes1;
        }
        $grades = $jnotes->grades ?? (object) [];
        $grades1 = $jnotes1->grades ?? (object) [];
        foreach ($ja->grades as $k => $v) {
            if (!($ge = $answers_only_pset->gradelike_by_key($k))
                || !$ge->answer) {
                if (property_exists($grades, $k)) {
                    $grades1->$k = $grades->$k;
                } else {
                    unset($grades1->$k);
                }
            }
        }
        return $jnotes1;
    }
}
