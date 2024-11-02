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

    // placeholder values:
    // 2: user does not want grade; gradebhash is latest
    // 1: user has not marked grading commit; gradebhash is latest
    // 0: admin has locked grading commit
    // -1: user has requested grade

    const PL_DONOTGRADE = 2;
    const PL_NONE = 1;
    const PL_LOCKED = 0;
    const PL_USER = -1;

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


    /** @param ?object $notes
     * @return bool */
    function save_rpnotes($notes, PsetView $info) {
        CommitPsetInfo::clean_notes($notes);
        $notestr = json_encode_db($notes);
        if ($notestr === "null" || $notestr === "{}") {
            $notestr = $notestra = $notestrb = null;
        } else {
            $notestra = strlen($notestr) > 16000 ? null : $notestr;
            $notestrb = strlen($notestr) > 16000 ? $notestr : null;
        }

        $rpnotesversion = $this->phantom ? 1 : $this->rpnotesversion + 1;

        if ($this->phantom) {
            $result = $info->conf->qe("insert into RepositoryGrade set
                repoid=?, branchid=?, pset=?,
                placeholder=1, placeholder_at=?,
                rpnotes=?, rpnotesOverflow=?, rpnotesversion=?
                on duplicate key update repoid=repoid",
                $this->repoid, $this->branchid, $this->pset,
                Conf::$now,
                $notestra, $notestrb, $rpnotesversion);
        } else if ($notestr !== $this->rpnotes) {
            $result = $info->conf->qe("update RepositoryGrade
                set rpnotes=?, rpnotesOverflow=?, rpnotesversion=?
                where repoid=? and branchid=? and pset=? and rpnotesversion=?",
                $notestra, $notestrb, $rpnotesversion,
                $this->repoid, $this->branchid, $this->pset, $this->rpnotesversion);
        } else {
            $result = Dbl_Result::make_affected_rows(1);
        }

        $ok = $result->affected_rows > 0;
        $result->close();
        if (!$ok) {
            $this->reload($info->conf);
            return false;
        }

        if ($this->phantom) {
            $this->phantom = false;
            $this->placeholder = 1;
            $this->placeholder_at = Conf::$now;
        }
        $this->rpnotes = $notestr;
        $this->jrpnotes = $notes;
        $this->rpnotesversion = $rpnotesversion;
        return true;
    }

    const UTYPE_ADMIN = 0;
    const UTYPE_USER = 1;
    const UTYPE_PLACEHOLDER = 2;

    /** @param null|-1|0|1|2 $placeholder
     * @param 0|1|2 $utype */
    function save_grading_commit(?CommitRecord $commit, $placeholder, $utype, Conf $conf) {
        assert($commit || $placeholder > 0);
        $bhash = $commit ? hex2bin($commit->hash) : null;
        if (!$commit && $placeholder === self::PL_NONE && $this->phantom) {
            return;
        }

        $this->materialize($conf);
        if ($utype === self::UTYPE_ADMIN) {
            $conf->qe("update RepositoryGrade
                set gradebhash=?, commitat=?,
                placeholder=?, placeholder_at=?,
                emptydiff_at=(if(gradebhash!=?,null,emptydiff_at))
                where repoid=? and branchid=? and pset=?",
                $bhash, $commit ? $commit->commitat : null,
                $placeholder, Conf::$now,
                $bhash,
                $this->repoid, $this->branchid, $this->pset);
            if ($bhash !== $this->gradebhash) {
                $this->emptydiff_at = null;
            }
            $this->gradebhash = $bhash;
            $this->gradehash = $commit ? $commit->hash : null;
            $this->placeholder = $placeholder;
            $this->placeholder_at = Conf::$now;
        } else {
            if ($utype === self::UTYPE_USER) {
                $pmatch = $psetmatch = "placeholder!=0";
            } else {
                $pmatch = "placeholder>0";
                $psetmatch = "0";
            }
            $conf->qe("update RepositoryGrade
                set gradebhash=(if({$pmatch},?,gradebhash)),
                commitat=(if({$pmatch},?,commitat)),
                placeholder=(if({$psetmatch},?,placeholder)), placeholder_at=?,
                emptydiff_at=(if({$pmatch} and gradebhash!=?,null,emptydiff_at))
                where repoid=? and branchid=? and pset=?",
                $bhash,
                $commit ? $commit->commitat : null,
                $placeholder, Conf::$now,
                $bhash,
                $this->repoid, $this->branchid, $this->pset);
            $this->reload($conf);
        }
    }
}
