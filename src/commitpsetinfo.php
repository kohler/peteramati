<?php
// commitpsetinfo.php -- Peteramati helper class representing commit/pset
// Peteramati is Copyright (c) 2013-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class CommitPsetInfo {
    /** @var int */
    public $pset;
    /** @var non-empty-string */
    public $bhash;
    /** @var int */
    public $repoid;
    /** @var ?int */
    public $commitat;
    /** @var int */
    public $notesversion = 0;
    /** @var ?string */
    public $notes;
    /** @var int */
    public $hasflags = 0;
    /** @var int */
    public $hasactiveflags = 0;
    /** @var int */
    public $haslinenotes = 0;
    /** @var ?string */
    public $xnotes;
    /** @var int */
    public $cnflags = 0;
    /** @var int */
    public $updateat = 0;
    /** @var ?string */
    public $repourl;

    /** @var non-empty-string */
    public $hash;
    /** @var ?object */
    private $jnotes;
    /** @var ?object */
    private $jxnotes;
    /** @var ?CommitPsetInfo */
    public $sset_next;
    /** @var ?CommitPsetInfo */
    public $sset_repo_next;
    /** @var bool */
    public $phantom = true;

    const CNF_GRADE = 1;
    const CNF_NOGRADE = 2;

    /** @param int $pset
     * @param non-empty-string $hash
     * @param int $repoid */
    function __construct($pset, $hash, $repoid) {
        $this->pset = $pset;
        if (strlen($hash) === 40) {
            /** @phan-suppress-next-line PhanTypeMismatchProperty */
            $this->bhash = hex2bin($hash);
            $this->hash = $hash;
        } else {
            $this->bhash = $hash;
            /** @phan-suppress-next-line PhanTypeMismatchProperty */
            $this->hash = bin2hex($hash);
        }
        $this->repoid = $repoid;
    }

    /** @return ?CommitPsetInfo */
    static function fetch($result) {
        if (($x = $result->fetch_object())) {
            $cpi = new CommitPsetInfo((int) $x->pset, $x->bhash, (int) $x->repoid);
            $cpi->merge($x);
            $cpi->phantom = false;
            return $cpi;
        } else {
            return null;
        }
    }

    function reload(Conf $conf) {
        $x = $conf->fetch_first_object("select * from CommitNotes where pset=? and bhash=?",
            $this->pset, $this->bhash);
        $this->merge($x ?? new CommitPsetInfo($this->pset, $this->bhash, $this->repoid));
        $this->phantom = !$x;
    }

    /** @param object $x */
    private function merge($x) {
        assert($this->pset === (int) $x->pset && $this->bhash === $x->bhash);
        $this->repoid = (int) $x->repoid;
        $this->commitat = isset($x->commitat) ? (int) $x->commitat : null;
        $this->notesversion = (int) $x->notesversion;
        $this->notes = $x->notesOverflow ?? $x->notes;
        $this->hasflags = (int) $x->hasflags;
        $this->hasactiveflags = (int) $x->hasactiveflags;
        $this->haslinenotes = (int) $x->haslinenotes;
        $this->cnflags = (int) $x->cnflags;
        $this->updateat = (int) $x->updateat;
        $this->xnotes = $x->xnotesOverflow ?? $x->xnotes;
        $this->jnotes = null;
        $this->jxnotes = null;
        if (isset($x->repourl)) {
            $this->repourl = $x->repourl;
        }
    }

    function materialize(Conf $conf) {
        if ($this->phantom) {
            $conf->qe("insert into CommitNotes set pset=?, bhash=?, repoid=? on duplicate key update pset=pset",
                $this->pset, $this->bhash, $this->repoid);
            $this->reload($conf);
        }
    }


    /** @return non-empty-string */
    function hash() {
        return bin2hex($this->bhash);
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

    /** @return null|'grade'|'nograde' */
    function gradeflag() {
        if (($this->cnflags & self::CNF_GRADE) !== 0) {
            return "grade";
        } else if (($this->cnflags & self::CNF_NOGRADE) !== 0) {
            return "nograde";
        } else {
            return null;
        }
    }


    /** @return null|int|float */
    function grade_total(Pset $pset) {
        assert($pset->id === $this->pset);
        $t = null;
        if ($this->notes && ($jn = $this->jnotes())) {
            $g = $jn->grades ?? null;
            $ag = $jn->autogrades ?? null;
            if ($g || $ag) {
                foreach ($pset->grades as $ge) {
                    if (!$ge->no_total) {
                        if ($g && property_exists($g, $ge->key)) {
                            $v = $g->{$ge->key};
                        } else {
                            $v = $ag ? $ag->{$ge->key} ?? null : null;
                        }
                        if ($v !== null) {
                            $t = ($t ?? 0) + $v;
                        }
                    }
                }
                $t = round_grade($t);
            }
        }
        return $t;
    }


    /** @param ?object $j */
    static function clean_notes($j) {
        if (is_object($j)
            && isset($j->grades)
            && is_object($j->grades)
            && isset($j->autogrades)
            && is_object($j->autogrades)) {
            foreach ($j->autogrades as $k => $v) {
                if (($j->grades->$k ?? null) === $v)
                    unset($j->grades->$k);
            }
            if (!count(get_object_vars($j->grades))) {
                unset($j->grades);
            }
        }
        if (is_object($j)) {
            unset($j->formula);
            unset($j->formula_at);
        }
    }

    /** @param ?object $j
     * @return int */
    static function notes_haslinenotes($j) {
        $x = 0;
        if ($j && isset($j->linenotes)) {
            foreach ($j->linenotes as $fn => $fnn) {
                foreach ($fnn as $ln => $n) {
                    $x |= (is_array($n) && $n[0] ? HASNOTES_COMMENT : HASNOTES_GRADE);
                }
            }
        }
        return $x;
    }

    /** @param ?object $j
     * @return int */
    static function notes_hasflags($j) {
        return $j && isset($j->flags) && count((array) $j->flags) ? 1 : 0;
    }

    /** @param ?object $j
     * @return int */
    static function notes_hasactiveflags($j) {
        if ($j && isset($j->flags)) {
            foreach ($j->flags as $f) {
                if (!($f->resolved ?? false))
                    return 1;
            }
        }
        return 0;
    }


    /** @param ?object $notes
     * @return bool */
    function save_notes($notes, PsetView $info) {
        self::clean_notes($notes);
        $notestr = json_encode_db($notes);
        if ($notestr === "null" || $notestr === "{}") {
            $notestr = $notestra = $notestrb = null;
        } else {
            $notestra = strlen($notestr) > 32700 ? null : $notestr;
            $notestrb = strlen($notestr) > 32700 ? $notestr : null;
        }
        $haslinenotes = self::notes_haslinenotes($notes);
        $hasflags = self::notes_hasflags($notes);
        $hasactiveflags = self::notes_hasactiveflags($notes);
        $notesversion = $this->phantom ? 1 : $this->notesversion + 1;

        if ($this->phantom
            && $this->commitat === null
            && ($commit = $info->connected_commit($this->bhash))) {
            $this->commitat = $commit->commitat;
        }

        if ($this->phantom) {
            $result = $info->conf->qe("insert into CommitNotes set
                pset=?, bhash=?, repoid=?, commitat=?,
                notes=?, notesOverflow=?,
                haslinenotes=?, hasflags=?, hasactiveflags=?,
                notesversion=?, updateat=?
                on duplicate key update repoid=repoid",
                $this->pset, $this->bhash, $this->repoid, $this->commitat,
                $notestra, $notestrb,
                $haslinenotes, $hasflags, $hasactiveflags,
                $notesversion, Conf::$now);
        } else if ($notestr !== $this->notes) {
            $result = $info->conf->qe("update CommitNotes set
                notes=?, notesOverflow=?,
                haslinenotes=?, hasflags=?, hasactiveflags=?,
                notesversion=?, updateat=?
                where pset=? and bhash=? and notesversion=?",
                $notestra, $notestrb,
                $haslinenotes, $hasflags, $hasactiveflags,
                $notesversion, Conf::$now,
                $this->pset, $this->bhash, $this->notesversion);
        } else {
            $result = Dbl_Result::make_affected_rows(1);
        }

        $ok = $result->affected_rows > 0;
        $result->close();
        if (!$ok) {
            $this->reload($info->conf);
            return false;
        }

        $this->phantom = false;
        $this->notes = $notestr;
        $this->jnotes = $notes;
        $this->hasflags = $hasflags;
        $this->hasactiveflags = $hasactiveflags;
        $this->haslinenotes = $haslinenotes;
        $this->notesversion = $notesversion;
        $this->updateat = Conf::$now;
        return true;
    }

    /** @param int $cnflags
     * @return bool */
    function update_cnflags($cnflags, PsetView $info) {
        if ($cnflags === $this->cnflags) {
            return true;
        }

        if ($this->phantom
            && $this->commitat === null
            && ($commit = $info->connected_commit($this->bhash))) {
            $this->commitat = $commit->commitat;
        }

        if ($this->phantom) {
            $result = $info->conf->qe("insert into CommitNotes set
                pset=?, bhash=?, repoid=?, commitat=?,
                cnflags=?,
                notesversion=?, updateat=?
                on duplicate key update repoid=repoid",
                $this->pset, $this->bhash, $this->repoid, $this->commitat,
                $cnflags,
                1, Conf::$now);
            $ok = $result && $result->affected_rows;
            $result->close();
        } else {
            $result = $info->conf->qe("update CommitNotes set
                cnflags=?
                where pset=? and bhash=? and notesversion=?",
                $cnflags,
                $this->pset, $this->bhash, $this->notesversion);
            $ok = $result && $result->affected_rows;
            $result->close();
        }

        if (!$ok) {
            $this->reload($info->conf);
            return false;
        }

        if ($this->phantom) {
            $this->phantom = false;
            $this->notesversion = 1;
            $this->updateat = Conf::$now;
        }
        $this->cnflags = $cnflags;
        return true;
    }
}
