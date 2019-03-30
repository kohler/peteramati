<?php
// studentset.php -- Peteramati set of students
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class StudentSet implements Iterator, Countable {
	public $conf;
	public $viewer;
	public $pset;
	private $_psetid;
	private $_anonymous;
	private $_u = [];
	private $_ua;
	private $_upos;
	private $_repo = [];
	private $_rg = [];
	private $_cn = [];
	private $_cg = [];
	private $_rb_uids = [];

    const COLLEGE = 1;
    const EXTENSION = 2;
    const ENROLLED = 4;
    const DROPPED = 8;

	function __construct(Contact $viewer, $flags = 0) {
		$this->conf = $viewer->conf;
		$this->viewer = $viewer;
        $ce = [];
        if ($flags & self::COLLEGE) {
            $ce[] = "college";
        }
        if ($flags & self::EXTENSION) {
            $ce[] = "extension";
        }
        $ce = $ce ? join(" or ", $ce) : "college or extension";
        $ed = [];
        if ($flags & self::ENROLLED) {
            $ed[] = "not dropped";
        }
        if ($flags & self::DROPPED) {
            $ed[] = "dropped";
        }
        $ed = $ed ? join(" or ", $ed) : "true";
		$result = $this->conf->qe("select *, (select group_concat(type, ' ', pset, ' ', link) from ContactLink where cid=ContactInfo.contactId) contactLinks from ContactInfo where ($ce) and ($ed)");
		while (($u = Contact::fetch($result, $this->conf))) {
			$this->_u[$u->contactId] = $u;
        }
		$this->_ua = array_values($this->_u);
		Dbl::free($result);
	}

	function set_pset(Pset $pset, $anonymous = null) {
		assert($this->conf === $pset->conf);
        if ($anonymous === null)
            $anonymous = $pset->anonymous;
        if ($this->pset === $pset && $this->_anonymous === $anonymous)
        	return;

        $this->pset = $pset;
        $this->_psetid = $pset->id;
        $this->_anonymous = $anonymous;
        foreach ($this->_u as $u)
        	$u->set_anonymous($anonymous);
        $this->_rg = $this->_cg = $this->_cn = $this->_rb_uids = [];

        if ($pset->gitless_grades) {
        	$result = $this->conf->qe("select * from ContactGrade where pset=?", $pset->id);
        	while ($result && ($cg = $result->fetch_object()))
        		$this->_cg[(int) $cg->cid] = $cg;
        	Dbl::free($result);
        } else {
        	$result = $this->conf->qe("select * from RepositoryGrade where pset=?", $pset->id);
        	while ($result && ($rg = $result->fetch_object()))
        		$this->_rg["$rg->repoid,$rg->branchid"] = $rg;
        	Dbl::free($result);

        	$result = $this->conf->qe("select * from CommitNotes where pset=?", $pset->id);
        	while ($result && ($cn = $result->fetch_object()))
        		$this->_cn[$cn->bhash] = $cn;
        	Dbl::free($result);
        }

        if (!$pset->gitless) {
        	$missing_repos = [];
        	foreach ($this->_u as $u) {
        		if (($repoid = $u->link(LINK_REPO, $pset->id))) {
        			if (!array_key_exists($repoid, $this->_repo))
	        			$missing_repos[] = $repoid;
	        		$bid = $u->branchid($pset);
	        		$this->_rb_uids["$repoid,$bid"][] = $u->contactId;
				}
        	}
        	if (!empty($missing_repos)) {
        		foreach ($missing_repos as $repoid)
        			$this->_repo[$repoid] = null;
        		$result = $this->conf->qe("select * from Repository where repoid ?a", $missing_repos);
        		while (($repo = Repository::fetch($result, $this->conf)))
        			$this->_repo[$repo->repoid] = $repo;
        		Dbl::free($result);
        	}
        }

        foreach ($this->_u as $u) {
        	$u->visited = $u->incomplete = false;
        }
	}

    function users() {
        return $this->_u;
    }

	function user($cid) {
		return get($this->_u, $cid);
	}

	function info($cid) {
		$u = $this->user($cid);
		return $u ? PsetView::make_from_set($this, $u) : null;
	}

	function repo(Contact $user) {
		$repoid = $user->link(LINK_REPO, $this->_psetid);
		return $repoid ? get($this->_repo, $repoid) : null;
	}

	function contact_grade(Contact $user) {
		if ($this->pset->gitless_grades)
			return get($this->_cg, $user->contactId);
		else
			return null;
	}

	function repo_grade_with_notes(Contact $user) {
		if (!$this->pset->gitless_grades) {
			$repoid = $user->link(LINK_REPO, $this->_psetid);
			$branchid = $user->branchid($this->pset);
			$rg = get($this->_rg, "$repoid,$branchid");
			if ($rg && !property_exists($rg, "bhash")) {
				$cn = $rg->gradebhash ? get($this->_cn, $rg->gradebhash) : null;
				$rg->bhash = $cn ? $cn->bhash : null;
				$rg->notes = $cn ? $cn->notes : null;
				$rg->notesversion = $cn ? $cn->notesversion : null;
			}
			return $rg;
		} else
			return null;
	}

	function repo_sharing(Contact $user) {
		assert(!$this->pset->gitless);
		if (($repoid = $user->link(LINK_REPO, $this->_psetid))) {
			$branchid = $user->branchid($this->pset);
			$sharers = array_diff($this->_rb_uids["$repoid,$branchid"], [$user->contactId]);
			if (!empty($sharers))
				$sharers = array_diff($sharers, $user->links(LINK_PARTNER, $this->_psetid));
			return !empty($sharers);
		} else
			return false;
	}


	function current() {
		return PsetView::make_from_set($this, $this->_ua[$this->_upos]);
	}
	function key() {
		return $this->_ua[$this->_upos]->contactId;
	}
	function next() {
		++$this->_upos;
		while ($this->_upos < count($this->_ua)
			   && $this->_ua[$this->_upos]->dropped
               && $this->pset) {
            if ($this->pset->gitless_grades) {
				if (get($this->_cg, $this->_ua[$this->_upos]->contactId))
					break;
			} else {
				if ($this->_ua[$this->_upos]->link(LINK_REPO, $this->_psetid))
					break;
			}
			++$this->_upos;
		}
	}
	function rewind() {
		$this->_upos = -1;
		$this->next();
	}
	function valid() {
		return $this->_upos < count($this->_ua);
	}


    function count() {
        return count($this->_ua);
    }


    static function json_basics(Contact $s, $anonymous) {
        $j = ["uid" => $s->contactId];
        if ($s->github_username)
            $j["username"] = $s->github_username;
        else if ($s->seascode_username)
            $j["username"] = $s->seascode_username;
        else
            $j["username"] = $s->email ? : $s->huid;
        if ($s->email)
            $j["email"] = $s->email;
        if ($anonymous)
            $j["anon_username"] = $s->anon_username;

        if ((string) $s->firstName !== "" && (string) $s->lastName === "")
            $j["last"] = $s->firstName;
        else {
            if ((string) $s->firstName !== "")
                $j["first"] = $s->firstName;
            if ((string) $s->lastName !== "")
                $j["last"] = $s->lastName;
        }
        if ($s->extension)
            $j["x"] = true;
        if ($s->dropped)
            $j["dropped"] = true;
        return $j;
    }
}
