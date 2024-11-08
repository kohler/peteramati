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
    /** @var 0|1|2 */
    private $phantom_type;

    /** @param int $cid
     * @param int $pset */
    function __construct($cid, $pset) {
        $this->cid = $cid;
        $this->pset = $pset;
        $this->phantom_type = 1;
    }

    /** @return ?UserPsetInfo */
    static function fetch($result) {
        $x = $result->fetch_object();
        if (!$x) {
            return null;
        }
        $upi = new UserPsetInfo((int) $x->cid, (int) $x->pset);
        $upi->merge($x);
        $upi->phantom_type = 0;
        return $upi;
    }

    function reload(Conf $conf) {
        assert($this->phantom_type !== 2);
        $x = $conf->fetch_first_object("select * from ContactGrade where cid=? and pset=?",
            $this->cid, $this->pset);
        $this->merge($x ?? new UserPsetInfo($this->cid, $this->pset));
        $this->phantom_type = $x ? 0 : 1;
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
        $this->_history_student = null;
    }

    function materialize(Conf $conf) {
        assert($this->phantom_type !== 2);
        if ($this->phantom_type === 1) {
            $conf->qe("insert into ContactGrade set cid=?, pset=? on duplicate key update cid=cid",
                $this->cid, $this->pset);
            $this->reload($conf);
        }
    }

    /** @return bool */
    function phantom() {
        return $this->phantom_type === 1;
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
        assert($this->phantom_type !== 2);
        $this->notes = $notes;
        $this->jnotes = $jnotes;
        $this->notesversion = $this->phantom_type === 1 ? 1 : $this->notesversion + 1;
        $this->phantom_type = 0;
    }


    /** @param int $version
     * @param ?bool $student_only
     * @return ?UserPsetHistory */
    function history_at($version, $student_only, Conf $conf) {
        assert($this->phantom_type !== 2);
        if ($version < 0
            || $version >= $this->notesversion
            || $this->phantom_type === 1) {
            return null;
        }
        if ($this->_history === null
            || (!$student_only && $this->_history_student)) {
            $this->_history = [];
            $this->_history_v0 = $this->notesversion;
            $this->_history_student = $student_only;
        }
        $dv = $version - $this->_history_v0;
        if ($dv >= 0 && $dv < count($this->_history)) {
            return $this->_history[$dv];
        }
        if ($dv < 0) {
            $this->_history_width = min(($this->_history_width ?? 2) * 2, 128);
            $v0 = max($version - $this->_history_width, 0);
            $v1 = $this->_history_v0;
            $nulls = array_fill(0, $v1 - $v0, null);
            $this->_history = array_merge($nulls, $this->_history);
            $this->_history_v0 = $v0;
        } else {
            $v0 = $this->_history_v0 + count($this->_history);
            $v1 = $this->notesversion;
            $nulls = array_fill(0, $v1 - $v0, null);
            array_push($this->_history, ...$nulls);
        }
        $qtail = $this->_history_student ? " and antiupdateby={$this->cid}" : "";
        $result = $conf->qe("select * from ContactGradeHistory where cid=? and pset=? and notesversion>=? and notesversion<?{$qtail} order by notesversion asc", $this->cid, $this->pset, $v0, $v1);
        while (($h = UserPsetHistory::fetch($result))) {
            $this->_history[$h->notesversion - $this->_history_v0] = $h;
        }
        Dbl::free($result);
        return $this->_history[$version - $this->_history_v0];
    }

    /** @param ?int $version
     * @param bool $student_only
     * @return UserPsetInfo */
    function version_at($version, $student_only, Conf $conf) {
        assert($this->phantom_type !== 2);
        if (($version ?? $this->notesversion) >= $this->notesversion
            || $this->phantom_type === 0) {
            return $this;
        }
        $this->history_at(max($version - 1, 0), $student_only, $conf);
        assert($this->_history !== null);
        $vupi = new UserPsetInfo($this->cid, $this->pset);
        $vupi->updateat = $this->updateat;
        $vupi->studentupdateat = $this->studentupdateat;
        $vupi->notesversion = $this->notesversion;
        $jnotes = $this->jnotes();
        $pset = $student_only ? $conf->pset_by_id($this->pset) : null;
        for ($v1 = $this->notesversion - 1; $v1 >= $version; --$v1) {
            if (($h = $this->history_at($v1, $student_only, $conf))
                && (!$student_only || $h->antiupdateby === $this->cid)) {
                $jnotes = $h->apply_revdelta($jnotes, $pset);
                $vupi->studentupdateat = $h->studentupdateat;
                $vupi->notesversion = $v1;
            }
        }
        $vupi->jnotes = $jnotes;
        $vupi->phantom_type = 2;
        return $vupi;
    }

    /** @param ?int $v1
     * @return Generator<int> */
    function answer_versions(Conf $conf, $v1 = null) {
        assert($this->phantom_type !== 2);
        if ($this->phantom_type === 2) {
            error_log(debug_string_backtrace());
        }
        $v1 = $v1 ?? $this->notesversion;
        if ($v1 >= $this->notesversion
            && $this->updateby === $this->cid) {
            yield $this->notesversion;
        }
        $v1 = min($v1, $this->notesversion - 1);
        while ($v1 >= 0) {
            if (($h = $this->history_at($v1, true, $conf))
                && $h->antiupdateby === $this->cid) {
                yield $v1;
            }
            --$v1;
        }
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
        assert($this->phantom_type === 0);
        $this->xnotes = $xnotes;
        $this->jxnotes = $jxnotes;
    }
}
