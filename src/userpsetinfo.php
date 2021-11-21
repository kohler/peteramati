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
    /** @var ?int */
    public $studentupdateat;
    /** @var int */
    public $notesversion = 0;
    /** @var ?string */
    public $notes;
    /** @var int */
    public $hidegrade = 0;
    /** @var int */
    public $hasactiveflags = 0;
    /** @var ?string */
    public $xnotes;

    /** @var ?object */
    private $jnotes;
    /** @var ?object */
    private $jxnotes;
    /** @var ?list<UserPsetHistory> */
    private $_history;
    /** @var ?int */
    private $_history_v0;
    /** @var ?UserPsetInfo */
    public $sset_next;
    /** @var bool */
    public $phantom = true;

    /** @param int $cid
     * @param int $pset */
    function __construct($cid, $pset) {
        $this->cid = $cid;
        $this->pset = $pset;
    }

    /** @return ?UserPsetInfo */
    static function fetch($result) {
        if (($x = $result->fetch_object())) {
            $upi = new UserPsetInfo((int) $x->cid, (int) $x->pset);
            $upi->merge($x);
            $upi->phantom = false;
            return $upi;
        } else {
            return null;
        }
    }

    function reload(Conf $conf) {
        $x = $conf->fetch_first_object("select * from ContactGrade where cid=? and pset=?",
            $this->cid, $this->pset);
        $this->merge($x ?? new UserPsetInfo($this->cid, $this->pset));
        $this->phantom = !$x;
    }

    /** @param object $x */
    private function merge($x) {
        assert($this->cid === (int) $x->cid && $this->pset === (int) $x->pset);
        $this->updateat = isset($x->updateat) ? (int) $x->updateat : null;
        $this->updateby = isset($x->updateby) ? (int) $x->updateby : null;
        $this->studentupdateat = isset($x->studentupdateat) ? (int) $x->studentupdateat : null;
        $this->gradercid = isset($x->gradercid) ? (int) $x->gradercid : null;
        $this->notesversion = (int) $x->notesversion;
        $this->notes = $x->notesOverflow ?? $x->notes;
        $this->hidegrade = (int) $x->hidegrade;
        $this->hasactiveflags = (int) $x->hasactiveflags;
        $this->xnotes = $x->xnotesOverflow ?? $x->xnotes;
        $this->jnotes = null;
        $this->jxnotes = null;
        $this->_history = null;
        $this->_history_v0 = null;
    }

    function materialize(Conf $conf) {
        if ($this->phantom) {
            $conf->qe("insert into ContactGrade set cid=?, pset=? on duplicate key update cid=cid",
                $this->cid, $this->pset);
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


    /** @param int $version */
    function jnotes_as_of($version, Conf $conf) {
        if ($version > $this->notesversion) {
            return null;
        }
        if ($this->_history === null || $this->_history_v0 < $version) {
            $v0 = $version - ($version & 7);
            $v1 = $this->_history_v0 ?? PHP_INT_MAX;
            $result = $conf->qe("select * from ContactGradeHistory where cid=? and pset=? and notesversion>=? and notesversion<? order by notesversion asc", $this->cid, $this->pset, $v0, $v1);
            $hs = array_fill(0, $this->notesversion - $v0 + 1, null);
            foreach ($this->_history ?? [] as $h) {
                if ($h)
                    $hs[$h->notesversion - $v0] = $h;
            }
            while (($h = UserPsetHistory::fetch($result))) {
                $hs[$h->notesversion - $v0] = $h;
            }
            $this->_history = $hs;
            $this->_history_v0 = $v0;
        }
        $h = $this->_history[$version - $this->_history_v0] ?? null;
        if ($h && !isset($h->computed_jnotes)) {
            $v1 = $version + 1;
            while ($v1 < $this->notesversion) {
                $hx = $this->_history[$v1 - $this->_history_v0] ?? null;
                if (!$hx) {
                    return null;
                } else if (isset($hx->computed_jnotes)) {
                    break;
                }
            }
            if ($v1 === $this->notesversion) {
                $jnotes = $this->jnotes();
            } else {
                $jnotes = $this->_history[$v1 - $this->_history_v0]->jnotes();
            }
            for (--$v1; $v1 >= $version; --$v1) {
                $jnotes = $this->_history[$v1 - $this->_history_v0]->apply_reverse($jnotes);
            }
            $h->computed_jnotes = $jnotes;
        }
        return $h ? $h->computed_jnotes : null;
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
}
