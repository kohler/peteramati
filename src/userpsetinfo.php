<?php
// userpsetinfo.php -- Peteramati helper class representing user/pset
// Peteramati is Copyright (c) 2013-2021 Eddie Kohler
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
    /** @var ?int */
    public $pinsnv;
    /** @var ?string */
    public $email;

    /** @var ?object */
    private $jnotes;
    /** @var ?object */
    private $jxnotes;
    /** @var ?list<UserPsetHistory> */
    private $_history;
    /** @var ?int */
    private $_history_v0;
    /** @var ?bool */
    private $_history_student;
    /** @var ?int */
    private $_history_width;
    /** @var ?UserPsetInfo */
    public $sset_next;
    /** @var bool */
    public $phantom = true;
    /** @var ?int */
    public $studentnotesversion;

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
        $this->notesversion = (int) $x->notesversion;
        $this->updateat = isset($x->updateat) ? (int) $x->updateat : null;
        $this->updateby = isset($x->updateby) ? (int) $x->updateby : null;
        $this->studentupdateat = isset($x->studentupdateat) ? (int) $x->studentupdateat : null;
        $this->gradercid = isset($x->gradercid) ? (int) $x->gradercid : null;
        $this->notes = $x->notesOverflow ?? $x->notes;
        $this->hidegrade = (int) $x->hidegrade;
        $this->hasactiveflags = (int) $x->hasactiveflags;
        $this->xnotes = $x->xnotesOverflow ?? $x->xnotes;
        $this->pinsnv = isset($x->pinsnv) ? (int) $x->pinsnv : null;
        if (isset($x->email)) {
            $this->email = $x->email;
        }
        $this->jnotes = null;
        $this->jxnotes = null;
        $this->_history = null;
        $this->_history_v0 = $this->notesversion;
        $this->_history_student = null;
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
        $this->_history_v0 = $this->_history_v0 ?? $this->notesversion;
        $this->phantom = false;
    }


    /** @param string $clause */
    private function load_history(Conf $conf, $clause) {
        $this->_history = $this->_history ?? [];
        while ($this->_history_v0 + count($this->_history) < $this->notesversion) {
            $this->_history[] = null;
        }
        $result = $conf->qe("select * from ContactGradeHistory where cid=? and pset=? and $clause and notesversion<? order by notesversion asc", $this->cid, $this->pset, $this->_history_v0);
        while (($h = UserPsetHistory::fetch($result))) {
            if ($h->notesversion < $this->_history_v0) {
                $this->_history = array_merge(
                    array_fill(0, $this->_history_v0 - $h->notesversion, null),
                    $this->_history);
                $this->_history_v0 = $h->notesversion;
            }
            $this->_history[$h->notesversion - $this->_history_v0] = $h;
        }
        Dbl::free($result);
    }

    /** @param int $version
     * @param ?bool $student_only
     * @return UserPsetHistory */
    function history_at($version, $student_only, Conf $conf) {
        assert(!$this->phantom);
        if (!$student_only && $this->_history_student) {
            $this->_history = [];
            $this->_history_v0 = $this->notesversion;
        }
        if ($this->_history_v0 > $version) {
            $this->_history_student = $student_only && $this->_history_student !== false;
            $this->_history_width = min(($this->_history_width ?? 8) * 2, 128);
            $vx = max($version - $this->_history_width, 0);
            if ($this->_history_student) {
                $this->load_history($conf, "notesversion>={$vx} and notesversion<{$this->_history_v0} and antiupdateby={$this->cid}");
            } else {
                $this->load_history($conf, "notesversion>={$vx} and notesversion<{$this->_history_v0}");
            }
        }
        if ($this->_history_v0 + count($this->_history) <= $version) {
            $this->load_history($conf, "notesversion>=" . ($this->_history_v0 + count($this->_history)));
        }
        if ($version >= $this->_history_v0) {
            return $this->_history[$version - $this->_history_v0] ?? null;
        } else {
            return null;
        }
    }

    /** @param ?int $version
     * @param ?bool $student_only
     * @return UserPsetInfo */
    function version_at($version, $student_only, Conf $conf) {
        assert(!$this->phantom);
        if (($version ?? $this->notesversion) >= $this->notesversion) {
            return $this;
        }
        $this->history_at($version, $student_only, $conf);
        $this->history_at($this->notesversion - 1, $student_only, $conf);
        assert($this->_history !== null);
        $vupi = new UserPsetInfo($this->cid, $this->pset);
        $vupi->updateat = $this->updateat;
        $vupi->studentupdateat = $this->studentupdateat;
        $vupi->notesversion = $this->notesversion;
        if ($student_only) {
            $vupi->studentnotesversion = $version;
        }
        $jnotes = $this->jnotes();
        for ($v1 = $this->notesversion - 1; $v1 >= $version; --$v1) {
            if ($v1 >= $this->_history_v0
                && ($h = $this->_history[$v1 - $this->_history_v0])
                && (!$student_only || $h->antiupdateby === $this->cid)) {
                $jnotes = $h->apply_revdelta($jnotes);
                $vupi->studentupdateat = $h->studentupdateat;
                if ($vupi->notesversion === $v1 + 1) {
                    $vupi->notesversion = $v1;
                    $vupi->updateat = $h->updateat;
                }
            }
        }
        $vupi->jnotes = $jnotes;
        $vupi->phantom = true;
        return $vupi;
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
