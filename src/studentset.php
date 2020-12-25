<?php
// studentset.php -- Peteramati set of students
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class StudentSet implements Iterator, Countable {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var ?Pset */
    public $pset;
    /** @var int */
    private $_psetid;
    /** @var bool */
    private $_anonymous;
    /** @var array<int,Contact> */
    private $_u = [];
    /** @var list<Contact> */
    private $_ua;
    /** @var int */
    private $_upos;
    /** @var array<int,Repository> */
    private $_repo = [];
    /** @var array<int,RepositoryPsetInfo> */
    private $_rpi = [];
    /** @var array<string,CommitPsetInfo> */
    private $_cpi = [];
    /** @var array<int,UserPsetInfo> */
    private $_upi = [];
    /** @var array<int,int> */
    private $_flags = [];
    /** @var array<string,list<int>> */
    private $_rb_uids = [];
    /** @var ?array<int,CommitPsetInfo> */
    private $_cpi_by_repo;
    /** @var ?array<string,PsetView> */
    private $_infos;

    const NO_UPI = 1;

    const COLLEGE = 1;
    const EXTENSION = 2;
    const ENROLLED = 4;
    const DROPPED = 8;
    const ALL = 15;
    const ALL_ENROLLED = 7;

    /** @param int $flags */
    function __construct(Contact $viewer, $flags) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $cflags = $flags & (self::COLLEGE | self::EXTENSION);
        $eflags = $flags & (self::ENROLLED | self::DROPPED);
        if ($cflags !== 0 && $eflags !== 0) {
            $cwhere = $cflags === self::COLLEGE ? "college" : ($cflags === self::EXTENSION ? "extension" : "true");
            $ewhere = $eflags === self::ENROLLED ? "not dropped" : ($cflags === self::DROPPED ? "dropped" : "true");
            $result = $this->conf->qe("select *, coalesce((select group_concat(type, ' ', pset, ' ', link) from ContactLink where cid=ContactInfo.contactId),'') contactLinks from ContactInfo where ($cwhere) and ($ewhere)");
            while (($u = Contact::fetch($result, $this->conf))) {
                $this->_u[$u->contactId] = $u;
                $u->student_set = $this;
            }
            Dbl::free($result);
            $this->_ua = array_values($this->_u);
        } else {
            $this->_ua = [];
        }
    }

    static function make_singleton(Contact $viewer, Contact $user) {
        $ss = new StudentSet($viewer, 0);
        $ss->_u[$user->contactId] = $user;
        $ss->_ua[] = $user;
        $user->student_set = $ss;
        $ss->_infos = [];
        return $ss;
    }

    function add_info(PsetView $info) {
        assert(isset($this->_infos) && isset($this->_u[$info->user->contactId]));
        $this->_infos["{$info->user->contactId},{$info->pset->id}"] = $info;
        $this->_flags[$info->pset->id] = $this->_flags[$info->pset->id] ?? 0;
    }

    private function load_pset(Pset $pset) {
        assert($this->conf === $pset->conf);
        if (isset($this->_flags[$pset->id]) || isset($this->_infos)) {
            return;
        }

        $result = $this->conf->qe("select * from ContactGrade where pset=?", $pset->id);
        $any_upi = false;
        while (($upi = UserPsetInfo::fetch($result))) {
            $upi->sset_next = $this->_upi[$upi->cid] ?? null;
            $this->_upi[$upi->cid] = $upi;
            $any_upi = true;
        }
        Dbl::free($result);

        if (!$pset->gitless_grades) {
            $result = $this->conf->qe("select * from RepositoryGrade where pset=?", $pset->id);
            while (($rpi = RepositoryPsetInfo::fetch($result))) {
                $rpi->sset_next = $this->_rpi[$rpi->repoid] ?? null;
                $this->_rpi[$rpi->repoid] = $rpi;
            }
            Dbl::free($result);

            $want_cbr = isset($this->_cpi_by_repo);
            $result = $this->conf->qe("select * from CommitNotes where pset=?", $pset->id);
            while (($cpi = CommitPsetInfo::fetch($result))) {
                $cpi->sset_next = $this->_cpi[$cpi->bhash] ?? null;
                $this->_cpi[$cpi->bhash] = $cpi;
                if ($want_cbr) {
                    $cpi->sset_repo_next = $this->_cpi_by_repo[$cpi->repoid] ?? null;
                    $this->_cpi_by_repo[$cpi->repoid] = $cpi;
                }
            }
            Dbl::free($result);
        }

        if (!$pset->gitless) {
            $missing_repos = [];
            foreach ($this->_u as $u) {
                if (($repoid = $u->link(LINK_REPO, $pset->id))) {
                    if (!array_key_exists($repoid, $this->_repo)) {
                        $missing_repos[] = $repoid;
                    }
                    $bid = $u->branchid($pset);
                    $this->_rb_uids["$pset->id,$repoid,$bid"][] = $u->contactId;
                }
            }
            if (!empty($missing_repos)) {
                foreach ($missing_repos as $repoid) {
                    $this->_repo[$repoid] = null;
                }
                $result = $this->conf->qe("select * from Repository where repoid ?a", $missing_repos);
                while (($repo = Repository::fetch($result, $this->conf))) {
                    $this->_repo[$repo->repoid] = $repo;
                }
                Dbl::free($result);
            }
        }

        $this->_flags[$pset->id] = $any_upi ? 0 : self::NO_UPI;
    }

    /** @param ?bool $anonymous */
    function set_pset(Pset $pset, $anonymous = null) {
        assert($this->conf === $pset->conf);
        if ($anonymous === null) {
            $anonymous = $pset->anonymous;
        }
        if ($this->pset === $pset && $this->_anonymous === $anonymous) {
            return;
        }

        $this->load_pset($pset);
        $this->pset = $pset;
        $this->_psetid = $pset->id;
        if ($this->_anonymous !== $anonymous) {
            $this->_anonymous = $anonymous;
            foreach ($this->_u as $u) {
                $u->set_anonymous($anonymous);
            }
        }
        foreach ($this->_u as $u) {
            $u->visited = $u->incomplete = false;
        }
    }

    /** @return array<int,Contact> */
    function users() {
        return $this->_u;
    }

    /** @param int $cid
     * @return ?Contact */
    function user($cid) {
        return $this->_u[$cid] ?? null;
    }

    /** @param int $cid
     * @return ?PsetView */
    function info($cid) {
        return $this->pset ? $this->info_at($cid, $this->pset) : null;
    }

    /** @param int $cid
     * @return ?PsetView */
    function info_at($cid, Pset $pset) {
        $u = $this->user($cid);
        if ($u === null) {
            return null;
        } else if ($this->_infos !== null) {
            return $this->_infos["{$cid},{$pset->id}"] ?? null;
        } else {
            return PsetView::make_from_set_at($this, $u, $pset);
        }
    }

    /** @return list<PsetView> */
    function infos($cid) {
        assert($this->_infos !== null && isset($this->_u[$cid]));
        $infos = [];
        foreach ($this->_infos as $info) {
            if ($info->user->contactId === $cid)
                $infos[] = $info;
        }
        return $infos;
    }

    /** @return ?Repository */
    function repo_at(Contact $user, Pset $pset) {
        $this->load_pset($pset);
        $repoid = $user->link(LINK_REPO, $pset->id);
        return $repoid ? $this->_repo[$repoid] ?? null : null;
    }

    /** @return ?UserPsetInfo */
    function upi_for(Contact $user, Pset $pset) {
        $this->load_pset($pset);
        if (($this->_flags[$pset->id] ?? 0) & self::NO_UPI) {
            return null;
        } else {
            $upi = $this->_upi[$user->contactId] ?? null;
            while ($upi && $upi->pset !== $pset->id) {
                $upi = $upi->sset_next;
            }
            return $upi;
        }
    }

    /** @return ?RepositoryPsetInfo */
    function rpi_for(Contact $user, Pset $pset) {
        if (!$pset->gitless_grades) {
            $this->load_pset($pset);
            $repoid = $user->link(LINK_REPO, $pset->id);
            $branchid = $user->branchid($pset);
            $rpi = $this->_rpi[$repoid] ?? null;
            while ($rpi && ($rpi->pset !== $pset->id || $rpi->branchid !== $branchid)) {
                $rpi = $rpi->sset_next;
            }
            if ($rpi && !$rpi->joined_commitnotes) {
                $rpi->joined_commitnotes = true;
                if ($rpi->gradebhash
                    && ($cpi = $this->cpi_for($rpi->gradebhash, $pset))) {
                    $rpi->assign_notes($cpi->notes, $cpi->jnotes(), $cpi->notesversion);
                }
            }
            return $rpi;
        } else {
            return null;
        }
    }

    /** @return ?CommitPsetInfo */
    function cpi_for($bhash, Pset $pset) {
        $cpi = $this->_cpi[$bhash] ?? null;
        while ($cpi && $cpi->pset !== $pset->id) {
            $cpi = $cpi->sset_next;
        }
        return $cpi;
    }

    /** @return list<CommitPsetInfo> */
    function all_cpi_for(Contact $user, Pset $pset) {
        $cpis = [];
        if (!$pset->gitless_grades) {
            if (!isset($this->_cpi_by_repo)) {
                $this->_cpi_by_repo = [];
                foreach ($this->_cpi as $cpi) {
                    while ($cpi !== null) {
                        $cpi->sset_repo_next = $this->_cpi_by_repo[$cpi->repoid] ?? null;
                        $this->_cpi_by_repo[$cpi->repoid] = $cpi;
                        $cpi = $cpi->sset_next;
                    }
                }
            }

            $this->load_pset($pset);
            $repoid = $user->link(LINK_REPO, $pset->id);
            $cpi = $this->_cpi_by_repo[$repoid] ?? null;
            $any_nullts = false;
            while ($cpi) {
                if ($cpi->pset === $pset->id) {
                    $cpis[] = $cpi;
                    $any_nullts = $any_nullts || $cpi->commitat === null;
                }
                $cpi = $cpi->sset_repo_next;
            }

            if ($any_nullts) {
                $this->_update_cpi_commitat($cpis, $repoid);
            }
            usort($cpis, function ($a, $b) {
                if ($a->commitat < $b->commitat) {
                    return -1;
                } else if ($a->commitat > $b->commitat) {
                    return 0;
                } else {
                    return strcmp($a->hash, $b->hash);
                }
            });
        }
        return $cpis;
    }

    private function _update_cpi_commitat($cpis, $repoid) {
        if (($repo = $this->_repo[$repoid] ?? null)) {
            $mqe = Dbl::make_multi_qe_stager($this->conf->dblink);
            foreach ($cpis as $cpi) {
                if ($cpi->commitat === null
                    && ($c = $repo->connected_commit($cpi->hash))) {
                    $cpi->commitat = $c->commitat;
                    $mqe("update CommitNotes set commitat=? where pset=? and bhash=?",
                         [$cpi->commitat, $cpi->pset, $cpi->bhash]);
                }
            }
            $mqe(true);
        }
    }

    /** @return bool */
    function repo_sharing(Contact $user) {
        assert(!$this->pset->gitless);
        if (($repoid = $user->link(LINK_REPO, $this->_psetid))) {
            $branchid = $user->branchid($this->pset);
            $sharers = array_diff($this->_rb_uids["$this->_psetid,$repoid,$branchid"],
                                  [$user->contactId]);
            if (!empty($sharers)) {
                $sharers = array_diff($sharers, $user->links(LINK_PARTNER, $this->_psetid));
            }
            return !empty($sharers);
        } else {
            return false;
        }
    }


    /** @return PsetView */
    function current() {
        return PsetView::make_from_set_at($this, $this->_ua[$this->_upos], $this->pset);
    }

    /** @return int */
    function key() {
        return $this->_ua[$this->_upos]->contactId;
    }

    /** @return void */
    function next() {
        ++$this->_upos;
        while ($this->_upos < count($this->_ua)
               && ($u = $this->_ua[$this->_upos])
               && $this->pset
               && ($u->dropped || $u->isPC)) {
            if ($this->pset->gitless_grades) {
                if ($this->upi_for($u, $this->pset)) {
                    break;
                }
            } else {
                if ($u->link(LINK_REPO, $this->_psetid)) {
                    break;
                }
            }
            ++$this->_upos;
        }
    }

    /** @return void */
    function rewind() {
        assert($this->_infos === null);
        $this->_upos = -1;
        $this->next();
    }

    /** @return bool */
    function valid() {
        return $this->_upos < count($this->_ua);
    }


    /** @return int */
    function count() {
        return count($this->_ua);
    }


    static function json_basics(Contact $s, $anonymous) {
        $j = ["uid" => $s->contactId];
        if ($s->github_username) {
            $j["username"] = $s->github_username;
        } else if ($s->seascode_username) {
            $j["username"] = $s->seascode_username;
        } else {
            $j["username"] = $s->email ? : $s->huid;
        }
        if ($s->email) {
            $j["email"] = $s->email;
        }
        if ($anonymous) {
            $j["anon_username"] = $s->anon_username;
        }

        if ((string) $s->firstName !== "" && (string) $s->lastName === "") {
            $j["last"] = $s->firstName;
        } else {
            if ((string) $s->firstName !== "")
                $j["first"] = $s->firstName;
            if ((string) $s->lastName !== "")
                $j["last"] = $s->lastName;
        }
        if ($s->extension) {
            $j["x"] = true;
        }
        if ($s->dropped) {
            $j["dropped"] = true;
        }
        return $j;
    }
}
