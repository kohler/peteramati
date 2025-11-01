<?php
// studentset.php -- Peteramati set of students
// HotCRP and Peteramati are Copyright (c) 2006-2025 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class StudentSet implements ArrayAccess, Iterator, Countable {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var ?Pset
     * @readonly */
    public $pset;
    /** @var int
     * @readonly */
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

    const COLLEGE = 1;
    const DCE = 2;
    const ENROLLED = 4;
    const DROPPED = 8;
    const TF = 16;
    const ALL = 15;
    const ALL_ENROLLED = 7;

    static private $all_set = null;

    /** @param int $flags
     * @param ?callable(Contact):bool $filter */
    function __construct(Contact $viewer, $flags, $filter = null) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        if ($flags !== 0) {
            $w = [];
            $cf = $flags & (self::COLLEGE | self::DCE);
            if ($cf === self::COLLEGE) {
                $w[] = "college";
            } else if ($cf === self::DCE) {
                $w[] = "extension";
            }
            $ef = $flags & (self::ENROLLED | self::DROPPED);
            if ($ef === self::ENROLLED) {
                $w[] = "not dropped";
            } else if ($ef === self::DROPPED) {
                $w[] = "dropped";
            }
            if (($flags & self::TF) === 0) {
                $w[] = "roles=0";
            } else if ($flags === self::TF) {
                $w[] = "roles!=0 and (roles&1)!=0";
            }
            $result = $this->conf->qe("select *, coalesce((select group_concat(type, ' ', pset, ' ', link) from ContactLink where cid=ContactInfo.contactId),'') contactLinks from ContactInfo where " . join(" and ", $w));
            while (($u = Contact::fetch($result, $this->conf))) {
                if (!$filter || $filter($u)) {
                    $this->_u[$u->contactId] = $u;
                    $u->student_set = $this;
                }
            }
            Dbl::free($result);
            $this->_ua = array_values($this->_u);
        } else {
            $this->_ua = [];
        }
        if ($flags === self::ALL && $viewer->isPC && !self::$all_set) {
            self::$all_set = $this;
        }
    }

    /** @return StudentSet */
    static function make_empty_for(Contact $user, Contact $viewer) {
        $ss = new StudentSet($viewer, 0);
        $ss->_u[$user->contactId] = $user;
        $ss->_ua[] = $user;
        $user->student_set = $ss;
        $ss->_infos = [];
        return $ss;
    }

    /** @param list<Contact> $users
     * @return StudentSet */
    static function make_for($users, Contact $viewer) {
        $ss = new StudentSet($viewer, 0);
        foreach ($users as $u) {
            $ss->_u[$u->contactId] = $u;
            $ss->_ua[] = $u;
            $u->student_set = $ss;
        }
        return $ss;
    }

    /** @return StudentSet */
    static function make_all(Conf $conf) {
        return self::$all_set ?? new StudentSet($conf->site_contact(), self::ALL);
    }

    /** @param list<string> $matchers
     * @return StudentSet */
    static function make_globmatch(Contact $viewer, $matchers) {
        $flags = self::ENROLLED;
        if (count($matchers) === 1) {
            if ($matchers[0] === "dropped") {
                $flags = self::DROPPED;
            } else if ($matchers[0] === "college") {
                $flags = self::ENROLLED | self::COLLEGE;
            } else if ($matchers[0] === "extension") {
                $flags = self::ENROLLED | self::DCE;
            }
        }
        if (empty($matchers) || $flags !== self::ENROLLED) {
            return new StudentSet($viewer, $flags);
        }
        foreach ($matchers as &$m) {
            $m = "*{$m}*";
        }
        unset($m);
        return new StudentSet($viewer, $flags, function ($u) use ($matchers) {
            foreach ($matchers as $m) {
                if (fnmatch($m, $u->email)
                    || fnmatch($m, $u->github_username)) {
                    $u->set_anonymous(false);
                    return true;
                } else if (fnmatch($m, $u->anon_username)) {
                    $u->set_anonymous(true);
                    return true;
                }
            }
            return false;
        });
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

        if (count($this->_u) > 40) {
            $result = $this->conf->qe("select * from ContactGrade where pset=?", $pset->id);
        } else {
            $result = $this->conf->qe("select * from ContactGrade where pset=? and cid?a", $pset->id, array_keys($this->_u));
        }
        $any_upi = false;
        while (($upi = UserPsetInfo::fetch($result))) {
            $upi->sset_next = $this->_upi[$upi->cid] ?? null;
            $this->_upi[$upi->cid] = $upi;
            $any_upi = true;
        }
        Dbl::free($result);

        if (!$pset->gitless) {
            $missing_repos = [];
            foreach ($this->_u as $u) {
                if (($repoid = $u->link(LINK_REPO, $pset->id))) {
                    if (!array_key_exists($repoid, $this->_repo)) {
                        $missing_repos[] = $repoid;
                    }
                    $bid = $u->branchid($pset);
                    $this->_rb_uids["{$pset->id},{$repoid},{$bid}"][] = $u->contactId;
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

            if (count($this->_repo) > 40) {
                $result = $this->conf->qe("select * from RepositoryGrade where pset=?", $pset->id);
            } else {
                $result = $this->conf->qe("select * from RepositoryGrade where pset=? and repoid?a", $pset->id, array_keys($this->_repo));
            }
            while (($rpi = RepositoryPsetInfo::fetch($result))) {
                $rpi->sset_next = $this->_rpi[$rpi->repoid] ?? null;
                $this->_rpi[$rpi->repoid] = $rpi;
            }
            Dbl::free($result);

            $want_cbr = isset($this->_cpi_by_repo);
            if (count($this->_repo) > 40) {
                $result = $this->conf->qe("select * from CommitNotes where pset=?", $pset->id);
            } else {
                $result = $this->conf->qe("select * from CommitNotes where pset=? and repoid?a", $pset->id, array_keys($this->_repo));
            }
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

        $this->_flags[$pset->id] = 0;
    }

    /** @param ?bool $anonymous */
    function set_pset(Pset $pset, $anonymous = null) {
        assert($this->conf === $pset->conf);
        $anonymous = $anonymous ?? $pset->anonymous;
        if ($this->pset === $pset && $this->_anonymous === $anonymous) {
            return;
        }

        $this->load_pset($pset);
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->pset = $pset;
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->_psetid = $pset->id;
        $this->set_anonymous($anonymous);
        foreach ($this->_u as $u) {
            $u->visited = $u->incomplete = false;
        }
    }

    /** @param ?bool $anonymous */
    function set_anonymous($anonymous = null) {
        if ($this->_anonymous !== $anonymous) {
            $this->_anonymous = $anonymous;
            foreach ($this->_u as $u) {
                $u->set_anonymous($anonymous);
            }
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
        }
        return PsetView::make_from_set_at($this, $u, $pset);
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

    /** @return ?PsetView */
    function info_for(Contact $user, Pset $pset) {
        if ($this->_infos !== null) {
            return $this->_infos["{$user->contactId},{$pset->id}"] ?? null;
        }
        return PsetView::make_from_set_at($this, $user, $pset);
    }

    function offsetExists($offset): bool {
        return isset($this->_u[$offset])
            && $this->pset
            && $this->info(intval($offset));
    }

    /** @return ?PsetView */
    function offsetGet($offset): ?object {
        return $this->info(intval($offset));
    }

    function offsetSet($offset, $value): void {
        throw new Error;
    }

    function offsetUnset($offset): void {
        throw new Error;
    }


    /** @return ?Repository */
    function repo_at(Contact $user, Pset $pset) {
        $this->load_pset($pset);
        $repoid = $user->link(LINK_REPO, $pset->id);
        return $repoid ? $this->_repo[$repoid] ?? null : null;
    }

    /** @return UserPsetInfo */
    function upi_for(Contact $user, Pset $pset) {
        $this->load_pset($pset);
        $upi = $this->_upi[$user->contactId] ?? null;
        while ($upi && $upi->pset !== $pset->id) {
            $upi = $upi->sset_next;
        }
        if (!$upi) {
            $upi = new UserPsetInfo($user->contactId, $pset->id);
            $upi->sset_next = $this->_upi[$user->contactId] ?? null;
            $this->_upi[$user->contactId] = $upi;
        }
        return $upi;
    }

    /** @return ?RepositoryPsetInfo */
    function rpi_for(Contact $user, Pset $pset) {
        if (!$pset->gitless_grades) {
            $this->load_pset($pset);
            if (($repoid = $user->link(LINK_REPO, $pset->id))) {
                $branchid = $user->branchid($pset);
                $rpi = $this->_rpi[$repoid] ?? null;
                while ($rpi && ($rpi->pset !== $pset->id || $rpi->branchid !== $branchid)) {
                    $rpi = $rpi->sset_next;
                }
                if (!$rpi) {
                    $rpi = new RepositoryPsetInfo($repoid, $branchid, $pset->id);
                    $rpi->sset_next = $this->_rpi[$repoid] ?? null;
                    $this->_rpi[$repoid] = $rpi;
                }
                return $rpi;
            }
        }
        return null;
    }

    /** @param non-empty-string $bhash
     * @return CommitPsetInfo */
    function cpi_for(Pset $pset, $bhash, Repository $repo) {
        $cpi = $this->_cpi[$bhash] ?? null;
        while ($cpi && $cpi->pset !== $pset->id) {
            $cpi = $cpi->sset_next;
        }
        if (!$cpi) {
            $cpi = new CommitPsetInfo($pset->id, $bhash, $repo->repoid);
            $cpi->sset_next = $this->_cpi[$bhash] ?? null;
            $this->_cpi[$bhash] = $cpi;
        }
        return $cpi;
    }

    /** @return list<CommitPsetInfo> */
    function all_cpi_for(Contact $user, Pset $pset) {
        if ($pset->gitless) {
            return [];
        }
        $cpis = [];
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
        return $cpis;
    }

    private function _update_cpi_commitat($cpis, $repoid) {
        $repo = $this->_repo[$repoid] ?? null;
        if (!$repo) {
            return;
        }
        $mqe = Dbl::make_multi_qe_stager($this->conf->dblink);
        foreach ($cpis as $cpi) {
            if ($cpi->commitat === null
                && ($c = $repo->connected_commit($cpi->hash))) {
                $cpi->commitat = $c->commitat;
                $mqe("update CommitNotes set commitat=? where pset=? and bhash=?",
                     $cpi->commitat, $cpi->pset, $cpi->bhash);
            }
        }
        $mqe(null);
    }

    /** @return \Generator<PsetView> */
    function all_info_for(Contact $user, Pset $pset) {
        foreach ($this->all_cpi_for($user, $pset) as $cpi) {
            yield PsetView::make_from_set_at($this, $user, $pset, $cpi->bhash);
        }
    }

    /** @return bool */
    function repo_sharing(Contact $user) {
        assert(!$this->pset->gitless);
        $repoid = $user->link(LINK_REPO, $this->_psetid);
        if (!$repoid) {
            return false;
        }
        $branchid = $user->branchid($this->pset);
        $sharers = array_diff($this->_rb_uids["{$this->_psetid},{$repoid},{$branchid}"],
                              [$user->contactId]);
        if (!empty($sharers)) {
            $sharers = array_diff($sharers, $user->links(LINK_PARTNER, $this->_psetid));
        }
        return !empty($sharers);
    }


    /** @return PsetView */
    function current(): object {
        return PsetView::make_from_set_at($this, $this->_ua[$this->_upos], $this->pset);
    }

    function key(): int {
        return $this->_ua[$this->_upos]->contactId;
    }

    function next(): void {
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

    function rewind(): void {
        assert($this->_infos === null);
        $this->_upos = -1;
        $this->next();
    }

    function valid(): bool {
        return $this->_upos < count($this->_ua);
    }


    function count(): int {
        return count($this->_ua);
    }

    /** @return void */
    function shuffle() {
        shuffle($this->_ua);
    }


    static function json_basics(Contact $s, $anonymous) {
        $j = ["uid" => $s->contactId];
        if ($s->github_username) {
            $j["user"] = $s->github_username;
        } else {
            $j["user"] = $s->email;
        }
        if ($s->email) {
            $j["email"] = $s->email;
        }
        if ($anonymous) {
            $j["anon_user"] = $s->anon_username;
        }

        if ((string) $s->firstName !== "" && (string) $s->lastName === "") {
            $j["last"] = $s->firstName;
        } else {
            if ((string) $s->firstName !== "")
                $j["first"] = $s->firstName;
            if ((string) $s->lastName !== "")
                $j["last"] = $s->lastName;
        }
        if ($s->studentYear) {
            $j["year"] = ctype_digit($s->studentYear) ? (int) $s->studentYear : $s->studentYear;
        }
        if ($s->extension) {
            $j["x"] = true;
        }
        if ($s->dropped) {
            $j["dropped"] = true;
        }
        if ($s->contactImageId) {
            $j["imageid"] = $s->contactImageId;
        }
        return $j;
    }
}
