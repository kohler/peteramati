<?php
// psetview.php -- CS61-monster helper class for pset view
// Peteramati is Copyright (c) 2006-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class PsetView {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Pset
     * @readonly */
    public $pset;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var bool */
    public $pc_view;
    /** @var ?Repository
     * @readonly */
    public $repo;
    /** @var ?Contact */
    public $partner;
    /** @var ?string
     * @readonly */
    public $branch;
    /** @var ?int
     * @readonly */
    public $branchid;
    /** @var ?bool */
    private $partner_same;

    /** @var ?UserPsetInfo */
    private $_upi;
    /** @var ?UserPsetInfo */
    private $_vupi;
    /** @var null|int|true */
    private $_snv;
    /** @var ?RepositoryPsetInfo */
    private $_rpi;
    /** @var ?CommitPsetInfo */
    private $_cpi;
    /** @var ?string */
    private $_hash;
    /** @var false|null|CommitRecord */
    private $_derived_handout_commit = false;
    /** @var ?CommitRecord */
    private $_recent_connected_commit;
    /** @var bool */
    private $_is_sset = false;

    /** @var null|0|1|3|4|5|7 */
    private $_vf;
    /** @var null|0|4|5|6|7 */
    private $_gvf;
    /** @var null|0|4|5|6|7 */
    private $_gvf_scores;
    /** @var ?bool */
    private $_can_view_grade;
    /** @var list<0|4|5|6> */
    private $_grades_vf;
    /** @var int */
    private $_grades_suppressed = 0; // 1: set, 2: selecting, 4: some suppressed

    /** @var int */
    private $_gtime;
    /** @var bool */
    private $_has_formula = false;
    /** @var ?list<mixed> */
    private $_g;
    /** @var ?list<mixed> */
    private $_ag;
    /** @var ?array<int,true> */
    private $_has_fg;
    /** @var ?int */
    private $_gtottime;
    /** @var null|int|float */
    private $_gmaxtot;
    /** @var null|int|float */
    private $_gtot;
    /** @var null|int|float */
    private $_gtotne;
    /** @var null|int|float */
    private $_gutot;
    /** @var ?bool */
    private $_gallreq;

    /** @var bool */
    private $need_format = false;

    const ERROR_NOTRUN = 1;
    const ERROR_LOGMISSING = 2;
    public $last_runner_error;
    /** @var array<string,array<int,PsetViewLineWarnings>> */
    private $transferred_warnings;
    /** @var ?RunLogger */
    private $_run_logger;
    public $viewed_gradeentries = [];

    /** @var int */
    private $_diff_tabwidth;
    /** @var ?LineNotesOrder */
    private $_diff_lnorder;

    private function __construct(Pset $pset, Contact $user, Contact $viewer) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->user = $user;
        $this->viewer = $viewer;
        $this->pc_view = $viewer->isPC;
        assert($viewer->contactId === $user->contactId || $this->pc_view);
        if ($viewer->contactId === $user->contactId && $viewer !== $user) {
            error_log("Unexpectedly different Contact\n" . debug_string_backtrace());
        }
    }

    /** @param ?string $hash
     * @param bool $refresh
     * @return PsetView
     * @suppress PhanAccessReadOnlyProperty */
    static function make(Pset $pset, Contact $user, Contact $viewer,
                         $hash = null, $refresh = false) {
        $info = new PsetView($pset, $user, $viewer);
        $info->partner = $user->partner($pset->id);
        if (!$pset->gitless) {
            $info->repo = $user->repo($pset->id);
            $info->branchid = $user->branchid($pset);
            $info->branch = $info->conf->branch($info->branchid);
        }
        if ($hash !== "none" && $info->repo) {
            $info->set_hash($hash, $refresh);
        }
        return $info;
    }

    /** @param ?string $bhash
     * @return PsetView
     * @suppress PhanAccessReadOnlyProperty */
    static function make_from_set_at(StudentSet $sset, Contact $user, Pset $pset,
                                     $bhash = null) {
        $info = new PsetView($pset, $user, $sset->viewer);
        if (($pcid = $user->link(LINK_PARTNER, $pset->id))) {
            $info->partner = $user->partner($pset->id, $sset->user($pcid));
        }
        if (!$pset->gitless) {
            $info->repo = $user->repo($pset->id, $sset->repo_at($user, $pset));
        }
        $info->_upi = $sset->upi_for($user, $pset);
        $info->_rpi = $sset->rpi_for($user, $pset);
        if ($info->_rpi) {
            $info->branchid = $info->_rpi->branchid;
            $info->branch = $info->conf->branch($info->branchid);
            $info->_hash = $info->_rpi->gradehash;
            $bhash = $bhash ?? $info->_rpi->gradebhash;
        } else {
            $info->branchid = $user->branchid($pset);
            $info->branch = $info->conf->branch($info->branchid);
        }
        if ($bhash !== null) {
            $info->_cpi = $sset->cpi_for($pset, $bhash, $info->repo);
            $info->_hash = $info->_cpi->hash;
        }
        $info->_is_sset = true;
        return $info;
    }

    /** @return UserPsetInfo */
    private function upi() {
        if (!$this->_upi) {
            $this->_upi = new UserPsetInfo($this->user->contactId, $this->pset->id);
            $this->_upi->reload($this->conf);
        }
        return $this->_upi;
    }

    /** @return UserPsetInfo */
    private function vupi() {
        if (!$this->_vupi) {
            $upi = $this->upi();
            if ($this->_snv === true) {
                $snv = $upi->notesversion;
            } else {
                $snv = $this->_snv ?? $upi->pinsnv ?? $upi->notesversion;
            }
            $this->_vupi = $upi->version_at($snv, true, $this->conf);
        }
        return $this->_vupi;
    }

    /** @param null|int|true $snv */
    function set_answer_version($snv) {
        $this->_vupi = null;
        $this->_snv = $snv;
    }

    /** @return ?RepositoryPsetInfo */
    function rpi() {
        if (!$this->_rpi && $this->repo) {
            $this->_rpi = new RepositoryPsetInfo($this->repo->repoid, $this->branchid, $this->pset->id);
            $this->_rpi->reload($this->conf);
        }
        return $this->_rpi;
    }

    /** @return ?CommitPsetInfo */
    private function cpi() {
        if (!$this->_cpi && $this->repo && $this->_hash) {
            $this->_cpi = new CommitPsetInfo($this->pset->id, $this->_hash, $this->repo->repoid);
            $this->_cpi->reload($this->conf);
        }
        return $this->_cpi;
    }


    /** @param bool $refresh
     * @suppress PhanAccessReadOnlyProperty */
    function reload_repo($refresh = false) {
        $this->repo = $this->_rpi = $this->_cpi = $this->_hash = null;
        $this->_is_sset = $this->_derived_handout_commit = false;
        if (!$this->pset->gitless) {
            $this->repo = $this->user->repo($this->pset->id);
            $this->branchid = $this->user->branchid($this->pset);
            $this->branch = $this->conf->branch($this->branchid);
        }
        if ($this->repo) {
            $this->set_hash(null, $refresh);
        }
    }


    /** @return list<int> */
    function backpartners() {
        return array_values(array_unique($this->user->links(LINK_BACKPARTNER, $this->pset->id)));
    }

    /** @return bool */
    function partner_same() {
        if ($this->partner_same === null) {
            $bp = $this->backpartners();
            if ($this->partner) {
                $this->partner_same = count($bp) === 1 && $this->partner->contactId === $bp[0];
            } else {
                $this->partner_same = empty($bp);
            }
        }
        return $this->partner_same;
    }


    /** @return string */
    function branch() {
        return $this->branch;
    }


    /** @return CommitList */
    function commit_list() {
        if (!$this->repo) {
            return new CommitList;
        }
        return $this->repo->commit_list($this->pset, $this->branch, true);
    }

    /** @return ?CommitRecord */
    function connected_commit($hashpart) {
        if (!$this->repo) {
            return null;
        }
        if ($this->_recent_connected_commit
            && $this->_recent_connected_commit->hash === $hashpart) {
            return $this->_recent_connected_commit;
        }
        $c = $this->repo->connected_commit($hashpart, $this->pset, $this->branch);
        if ($c) {
            $this->_recent_connected_commit = $c;
        }
        return $c;
    }

    /** @return ?CommitRecord */
    function latest_commit() {
        if (!$this->repo) {
            return null;
        }
        return $this->repo->latest_commit($this->pset, $this->branch);
    }

    /** @return ?non-empty-string */
    function latest_hash() {
        $lc = $this->latest_commit();
        return $lc ? $lc->hash : null;
    }

    /** @return ?CommitRecord */
    function latest_nontrivial_commit() {
        return $this->repo ? $this->repo->latest_nontrivial_commit($this->pset, $this->branch) : null;
    }

    /** @return ?non-empty-string */
    function latest_nontrivial_hash() {
        $lc = $this->latest_nontrivial_commit();
        return $lc ? $lc->hash : null;
    }

    /** @return ?CommitRecord */
    function grading_commit() {
        $h = $this->grading_hash();
        return $h ? $this->connected_commit($h) : null;
    }

    /** @return ?non-empty-string */
    function grading_hash() {
        $rpi = $this->pset->gitless ? null : $this->rpi();
        return $rpi && $rpi->placeholder <= 0 ? $rpi->gradehash : null;
    }


    /** @param string $hashpart
     * @return ?CommitRecord */
    function find_commit($hashpart) {
        if ($hashpart === "handout" || $hashpart === "base") {
            return $this->base_handout_commit();
        } else if ($hashpart === "head" || $hashpart === "latest") {
            return $this->latest_commit();
        } else if ($hashpart === "grade" || $hashpart === "grading") {
            return $this->grading_commit();
        } else if ($hashpart) {
            list($cx, $definitive) = Repository::find_listed_commit($hashpart, $this->pset->handout_commits());
            if ($cx) {
                return $cx;
            } else if ($this->repo) {
                return $this->repo->connected_commit($hashpart, $this->pset, $this->branch);
            } else {
                return null;
            }
        }
    }


    /** @return ?non-empty-string */
    function hash() {
        return $this->_hash;
    }

    /** @return ?non-empty-string */
    function bhash() {
        return $this->_hash !== null ? hex2bin($this->_hash) : null;
    }

    /** @return non-empty-string */
    function commit_hash() {
        assert($this->_hash !== null);
        return $this->_hash;
    }

    /** @return ?CommitRecord */
    function commit() {
        assert($this->_hash !== null);
        return $this->_hash ? $this->connected_commit($this->_hash) : null;
    }

    /** @param ?string $hash
     * @param ?StudentSet $sset */
    function force_set_hash($hash, $sset = null) {
        assert($hash === null || (strlen($hash) === 40 && $this->repo));
        // assert(!$this->_is_sset || $sset); -- usually true but not always
        if ($this->_hash !== $hash) {
            $this->_hash = $hash;
            if ($sset && $hash !== null) {
                $this->_cpi = $sset->cpi_for($this->pset, hex2bin($hash), $this->repo);
            } else {
                $this->_cpi = null;
            }
            $this->_derived_handout_commit = false;
            $this->_gtime = $this->_gtottime = null;
            $this->_has_formula = false;
        }
    }

    function set_commit(CommitRecord $commit) {
        $this->force_set_hash($commit->hash);
    }

    function set_connected_commit(CommitRecord $commit) {
        $this->force_set_hash($commit->hash);
        $this->_recent_connected_commit = $commit;
    }

    /** @param ?StudentSet $sset */
    function set_latest_nontrivial_commit($sset = null) {
        if (($c = $this->latest_nontrivial_commit())) {
            $this->force_set_hash($c->hash, $sset);
        } else {
            $this->force_set_hash(null, $sset);
        }
    }

    /** @param ?StudentSet $sset */
    function set_grading_or_latest_nontrivial_commit($sset = null) {
        if (($hash = $this->grading_hash())) {
            $this->force_set_hash($hash, $sset);
        } else {
            $this->set_latest_nontrivial_commit($sset);
        }
    }

    /** @param ?string $hashpart
     * @param bool $refresh
     * @param ?StudentSet $sset
     * @return bool */
    function set_hash($hashpart, $refresh = false, $sset = null) {
        if (!$this->repo || $hashpart === "none") {
            $this->force_set_hash(null, $sset);
            return true;
        } else if ($hashpart === null || $hashpart === "") {
            $this->set_grading_or_latest_nontrivial_commit($sset);
            return $this->hash() !== null;
        } else if (($c = $this->find_commit($hashpart))) {
            $this->force_set_hash($c->hash, $sset);
            return true;
        } else if ($refresh) {
            $this->repo->refresh(10, true);
            return $this->set_hash($hashpart, false, $sset);
        } else {
            $this->force_set_hash(null, $sset);
            return false;
        }
    }


    /** @param ?string $q
     * @return ?SearchExpr */
    static function parse_commit_query($q) {
        $cq = trim($q ?? "");
        if ($cq === "" || $cq === "grading" || $cq === "grade") {
            return null;
        }
        $sp = new SearchParser($cq);
        return $sp->parse_expression();
    }

    private function _eval_before(SearchExpr $e) {
        if ($e->info === null) {
            try {
                $d = new DateTimeImmutable($e->text);
                $e->info = $d->getTimestamp();
            } catch (Exception $x) {
                $e->info = false;
            }
        }
        return $e->info !== false && ($this->commitat() ?? PHP_INT_MAX) < $e->info;
    }

    function _eval_expr(SearchExpr $e) {
        if ($e->kword !== null) {
            if ($e->kword === "before") {
                return $this->_eval_before($e);
            }
            return false;
        }
        if ($e->text === "latest" || $e->text === "head") {
            // assume traverse in reverse order
            return true;
        }
        if ($e->text === "handout") {
            return $this->is_handout_commit();
        }
        if ($e->text === "grading" || $e->text === "grade") {
            return $this->is_grading_commit();
        }
        return str_starts_with($this->hash(), $e->text);
    }

    /** @return bool */
    function select_commit(SearchExpr $expr) {
        $cl = $this->commit_list();
        foreach ($cl as $c) {
            $this->set_connected_commit($c);
            if ($expr->evaluate_simple([$this, "_eval_expr"])) {
                return true;
            }
        }
        return false;
    }


    /** @return bool */
    function is_handout_commit() {
        return $this->_hash !== null
            && $this->pset->handout_commits()->contains($this->_hash);
    }

    /** @return bool */
    function is_grading_commit() {
        assert(!$this->pset->gitless);
        return $this->_hash !== null
            && ($rpi = $this->rpi())
            && $rpi->placeholder <= 0
            && $rpi->gradehash === $this->_hash;
    }

    /** @return bool */
    function has_grading_commit() {
        return ($rpi = $this->rpi())
            && $rpi->placeholder <= 0;
    }

    /** @return bool */
    function is_do_not_grade() {
        return ($rpi = $this->rpi())
            && $rpi->placeholder === 2;
    }

    /** @return bool */
    function is_user_grading_commit() {
        return ($rpi = $this->rpi())
            && $rpi->placeholder === -1;
    }

    /** @return bool */
    function is_lateish_commit() {
        return $this->_hash
            && ($this->_hash === $this->latest_hash()
                || $this->_hash === $this->latest_nontrivial_hash());
    }

    /** @return ?int */
    function commitat() {
        if ($this->_hash === null) {
            return null;
        } else if ($this->_cpi !== null
                   && $this->_cpi->commitat !== null) {
            return $this->_cpi->commitat;
        } else if ($this->_rpi !== null
                   && $this->_rpi->commitat !== null
                   && $this->_rpi->gradehash === $this->_hash) {
            return $this->_rpi->commitat;
        } else {
            $c = $this->commit();
            return $c ? $c->commitat : null;
        }
    }

    /** @return bool */
    function can_have_grades() {
        return $this->pset->gitless_grades || $this->commit();
    }

    /** @return ?CommitRecord */
    function derived_handout_commit() {
        if ($this->_derived_handout_commit === false) {
            $this->_derived_handout_commit = null;
            $hbases = $this->pset->handout_commits();
            $seen_hash = !$this->_hash;
            foreach ($this->commit_list() as $c) {
                if ($c->hash === $this->_hash) {
                    $seen_hash = true;
                }
                if (isset($hbases[$c->hash])) {
                    $this->_derived_handout_commit = $c;
                    if ($seen_hash) {
                        break;
                    }
                }
            }
        }
        return $this->_derived_handout_commit;
    }

    /** @return ?non-empty-string */
    function derived_handout_hash() {
        $c = $this->derived_handout_commit();
        return $c ? $c->hash : null;
    }

    /** @return CommitRecord */
    function base_handout_commit() {
        if ($this->pset->handout_hash
            && ($c = $this->pset->handout_commit($this->pset->handout_hash))) {
            return $c;
        } else if (($c = $this->derived_handout_commit())) {
            return $c;
        } else if (($c = $this->pset->latest_handout_commit())) {
            return $c;
        } else {
            return new CommitRecord(0, "4b825dc642cb6eb9a060e54bf8d69288fbee4904", "", CommitRecord::HANDOUTHEAD);
        }
    }

    /** @param null|int|string $base
     * @return ?CommitRecord */
    function commit_in_base($base) {
        if ($base === null || $base === "handout") {
            return $this->base_handout_commit();
        }
        if ($base === "previous") {
            $prevp = $this->pset->predecessor();
        } else if (is_int($base)) {
            $prevp = $this->conf->pset_by_id($base);
        } else {
            $prevp = $this->conf->pset_by_key($base);
        }
        if (!$prevp
            || $this->user->link(LINK_REPO, $prevp->id) !== $this->repo->repoid) {
            return null;
        }
        $prevv = PsetView::make($prevp, $this->user, $this->viewer);
        return $prevv->grading_commit();
    }

    /** @return CommitRecord */
    function diff_base_commit() {
        $c = $this->base_handout_commit();
        if ($this->pset->diff_base !== null) {
            $c = $this->commit_in_base($this->pset->diff_base) ?? $c;
        }
        return $c;
    }

    /** @param ?callable(PsetView,?RepositoryPsetInfo):bool $updater
     * @return void */
    function update_placeholder($updater) {
        if ($this->pset->gitless_grades || !$this->repo) {
            return;
        }
        $rpi = $this->rpi();
        if ($rpi->placeholder <= 0
            || ($updater !== null && !call_user_func($updater, $this, $rpi))) {
            return;
        }
        $rpi->save_grading_commit($this->latest_commit(), $rpi->placeholder, RepositoryPsetInfo::UTYPE_PLACEHOLDER, $this->conf);
    }


    /** @return bool */
    function empty_diff_likely() {
        return $this->pset->gitless
            || $this->_hash === null
            || (($rpi = $this->rpi())
                && $rpi->gradehash === $this->_hash
                && $rpi->emptydiff_at
                && ($c = $this->pset->latest_handout_commit())
                && $rpi->emptydiff_at === $c->commitat);
    }


    /** @return ?object */
    function user_jnotes() {
        return $this->vupi()->jnotes();
    }

    /** @return ?object */
    function user_jxnotes() {
        return $this->vupi()->jxnotes();
    }

    /** @param non-empty-string $key */
    function user_jnote($key) {
        $un = $this->user_jnotes();
        return $un ? $un->$key ?? null : null;
    }

    /** @return ?object */
    function commit_jnotes() {
        assert(!$this->pset->gitless);
        $cpi = $this->cpi();
        return $cpi ? $cpi->jnotes() : null;
    }

    /** @return ?object */
    function commit_jxnotes() {
        assert(!$this->pset->gitless);
        $cpi = $this->cpi();
        return $cpi ? $cpi->jxnotes() : null;
    }

    /** @param non-empty-string $key */
    function commit_jnote($key) {
        $cn = $this->commit_jnotes();
        return $cn ? $cn->$key ?? null : null;
    }

    /** @return ?object */
    function repository_jnotes() {
        assert(!$this->pset->gitless);
        $rpi = $this->rpi();
        return $rpi ? $rpi->jrpnotes() : null;
    }

    /** @return ?object */
    function repository_jxnotes() {
        assert(!$this->pset->gitless);
        $rpi = $this->rpi();
        return $rpi ? $rpi->jrpxnotes() : null;
    }

    /** @param non-empty-string $key */
    function repository_jnote($key) {
        $rn = $this->repository_jnotes();
        return $rn ? $rn->$key ?? null : null;
    }


    /** @return ?object */
    private function current_jnotes() {
        if ($this->pset->gitless) {
            return $this->user_jnotes();
        } else {
            return $this->commit_jnotes();
        }
    }


    /** @return ?int */
    function notesversion() {
        if ($this->pset->gitless) {
            return $this->upi()->notesversion;
        } else {
            assert(!$this->pset->gitless_grades);
            $cpi = $this->cpi();
            return $cpi ? $cpi->notesversion : null;
        }
    }

    /** @return ?int */
    function answer_version() {
        if (!$this->pset->gitless) {
            return $this->notesversion();
        }
        return $this->vupi()->notesversion;
    }

    /** @return list<int>|Generator<int> */
    function answer_versions() {
        if (!$this->pset->gitless) {
            $nv = $this->notesversion();
            return $nv !== null ? [$nv] : [];
        }
        return $this->upi()->answer_versions($this->conf);
    }

    /** @return bool */
    function has_older_answers() {
        if (!$this->pset->grades_history || !$this->pset->gitless) {
            return false;
        }
        $vupi = $this->vupi();
        foreach ($this->upi()->answer_versions($this->conf, $vupi->notesversion - 1) as $nv) {
            return true;
        }
        return false;
    }

    /** @return bool */
    function has_newer_answers() {
        if (!$this->pset->grades_history
            || !$this->pset->gitless
            || $this->_snv === true) {
            return false;
        }
        $vupi = $this->vupi();
        foreach ($this->upi()->answer_versions($this->conf) as $nv) {
            return $nv > $vupi->notesversion;
        }
        return false;
    }

    /** @return bool */
    function has_pinned_answers() {
        return $this->pset->gitless
            && $this->upi()->pinsnv !== null;
    }

    /** @return ?int */
    function pinsnv() {
        if ($this->pset->gitless) {
            $upi = $this->upi();
            return $upi->pinsnv ?? $upi->notesversion;
        } else {
            return $this->notesversion();
        }
    }


    /** @param string $file
     * @param string $lineid
     * @return LineNote */
    function line_note($file, $lineid) {
        if ($this->pset->gitless_grades
            && str_starts_with($file, '/g/')) {
            $n1 = $this->user_jnotes();
        } else {
            $n1 = $this->commit_jnotes();
        }
        if ($n1
            && ($n2 = $n1->linenotes ?? null)
            && ($n3 = $n2->$file ?? null)
            && ($ln = $n3->$lineid ?? null)) {
            return LineNote::make_json($file, $lineid, $ln);
        } else {
            return new LineNote($file, $lineid);
        }
    }


    /** @return ?object */
    function grade_jnotes() {
        if ($this->pset->gitless_grades) {
            return $this->user_jnotes();
        } else {
            return $this->commit_jnotes();
        }
    }

    /** @return ?object */
    function grade_jxnotes() {
        if ($this->pset->gitless_grades) {
            return $this->user_jxnotes();
        } else {
            return $this->commit_jxnotes();
        }
    }

    /** @param non-empty-string $key
     * @return mixed */
    function grade_jnote($key) {
        $jn = $this->grade_jnotes();
        return $jn ? $jn->$key ?? null : null;
    }

    /** @param non-empty-string $key
     * @return mixed */
    function grade_jxnote($key) {
        $jn = $this->grade_jxnotes();
        return $jn ? $jn->$key ?? null : null;
    }


    /** @return list<mixed> */
    private function blank_values() {
        return array_fill(0, count($this->pset->grades), null);
    }

    private function ensure_grades() {
        if ($this->_gtime === $this->user->gradeUpdateTime) {
            return;
        }
        $this->_gtime = $this->user->gradeUpdateTime;
        $this->_ag = $this->_gtottime = null;
        $this->_has_formula = false;
        $jn = $this->grade_jnotes();
        if ($jn && ($ag = $jn->autogrades ?? null)) {
            $this->_ag = $this->blank_values();
            foreach ($this->pset->grades as $ge) {
                if (!$ge->is_formula() && isset($ag->{$ge->key})) {
                    $this->_ag[$ge->pcview_index] = $ag->{$ge->key};
                }
            }
        }
        $this->_g = $this->_ag;
        if ($jn && ($g = $jn->grades ?? null)) {
            $this->_g = $this->_g ?? $this->blank_values();
            foreach ($this->pset->grades as $ge) {
                if (!$ge->is_formula() && property_exists($g, $ge->key)) {
                    $this->_g[$ge->pcview_index] = $g->{$ge->key};
                }
            }
        }
    }

    private function ensure_formulas_from_jxnotes($g) {
        if (!$g) {
            return;
        }
        foreach ($this->pset->formula_grades() as $ge) {
            if (!property_exists($g, $ge->key)) {
                continue;
            }
            $v = $g->{$ge->key};
            if ($v !== null) {
                $this->_g[$ge->pcview_index] = $v;
            } else {
                $this->_has_fg[$ge->pcview_index] = true;
            }
        }
    }

    private function ensure_formulas() {
        if (!$this->pset->has_formula || $this->_has_formula) {
            return;
        }
        $this->_has_formula = true;
        $this->_g = $this->_g ?? $this->blank_values();
        $this->_has_fg = [];
        $jn = $this->grade_jxnotes();
        $t = max($this->user->gradeUpdateTime, $this->pset->config_mtime);
        //error_log("{$t} {$this->user->gradeUpdateTime} {$this->pset->config_mtime} {$jn->formula_at}");
        if ($jn && ($jn->formula_at ?? null) === $t) {
            $this->ensure_formulas_from_jxnotes($jn->formula ?? null);
            return;
        }
        $fs = [];
        foreach ($this->pset->formula_grades() as $ge) {
            $f = $ge->formula();
            $v = $f->evaluate($this->user, $this);
            if ($v !== null) {
                $this->_g[$ge->pcview_index] = $v;
            } else {
                $this->_has_fg[$ge->pcview_index] = true;
            }
            if ($f->cacheable) {
                $fs[$ge->key] = $v;
            }
        }
        if ($this->pset->gitless_grades || $this->_hash) {
            $this->update_grade_xnotes([
                "formula_at" => $t,
                "formula" => new JsonReplacement(empty($fs) ? null : $fs)
            ]);
        }
    }

    private function ensure_visible_total() {
        if ($this->_gtottime === $this->user->gradeUpdateTime) {
            return;
        }
        $this->ensure_grades();
        $this->_gtottime = $this->user->gradeUpdateTime;
        $this->_gtot = $this->_gtotne = $this->_gutot = $this->_gmaxtot = null;
        $this->_gallreq = true;
        $vf = $this->vf();
        foreach ($this->visible_grades(VF_TF) as $ge) {
            if ($ge->no_total) {
                continue;
            }
            $v = $this->_g[$ge->pcview_index] ?? null;
            $gvf = $this->_grades_vf[$ge->pcview_index];
            if ($v !== null
                && ($gvf & $vf) !== 0) {
                $this->_gtot = ($this->_gtot ?? 0) + $v;
                if (!$ge->is_extra) {
                    $this->_gtotne = ($this->_gtotne ?? 0) + $v;
                }
            }
            if ($v !== null
                && ($gvf & $vf & ~VF_TF) !== 0) {
                $this->_gutot = ($this->_gutot ?? 0) + $v;
            }
            if (!$ge->is_extra
                && $ge->max_visible
                && ($gvf & $vf) !== 0) {
                $this->_gmaxtot = ($this->_gmaxtot ?? 0) + $ge->max;
            }
            if ($v === null
                && !$ge->is_extra
                && ($ge->required
                    || ($ge->required === null && ($gvf & ~VF_TF) !== 0))) {
                // A required non-extra-credit score is missing
                $this->_gallreq = false;
            }
        }
        $this->_gtot = round_grade($this->_gtot);
        $this->_gutot = round_grade($this->_gutot);
        $this->_gtotne = round_grade($this->_gtotne);
    }

    /** @return null|int|float */
    function grade_max_total() {
        $this->ensure_visible_total();
        return $this->_gmaxtot;
    }

    /** @return null|int|float */
    function visible_total() {
        $this->ensure_visible_total();
        return $this->_gtot;
    }

    /** @return null|int|float */
    function user_visible_total() {
        $this->ensure_visible_total();
        return $this->_gutot;
    }

    /** @return null|int|float */
    function visible_total_noextra() {
        $this->ensure_visible_total();
        return $this->_gtotne;
    }

    /** @return bool */
    function user_visible_grading_complete() {
        $this->ensure_visible_total();
        return $this->_gallreq;
    }


    /** @param string|GradeEntry $ge
     * @return null|int|float|string */
    function grade_value($ge) {
        if (is_string($ge)) {
            $ge = $this->pset->gradelike_by_key($ge);
        }
        if (!$ge) {
            return null;
        }
        if ($ge->pcview_index === null) {
            if ($ge->gtype === GradeEntry::GTYPE_LATE_HOURS) {
                return $this->late_hours();
            } else if ($ge->gtype === GradeEntry::GTYPE_STUDENT_TIMESTAMP) {
                return $this->student_timestamp(true);
            }
            return null;
        }
        $this->ensure_grades();
        $gv = $this->_g[$ge->pcview_index] ?? null;
        if ($gv === null && $ge->is_formula()) {
            if (!$this->_has_formula) {
                $this->ensure_formulas();
                $gv = $this->_g[$ge->pcview_index] ?? null;
            }
            if ($gv === null && !isset($this->_has_fg[$ge->pcview_index])) {
                $gv = $ge->formula()->evaluate($this->user, $this);
                if ($gv !== null) {
                    $this->_g[$ge->pcview_index] = $gv;
                } else {
                    $this->_has_fg[$ge->pcview_index] = true;
                }
            }
        }
        return $gv;
    }

    /** @param string|GradeEntry $ge
     * @return null|int|float|string */
    function autograde_value($ge) {
        if (is_string($ge)) {
            $ge = $this->pset->gradelike_by_key($ge);
        }
        if (!$ge) {
            return null;
        } else if ($ge->pcview_index !== null && !$ge->is_formula()) {
            $this->ensure_grades();
            return $this->_ag !== null ? $this->_ag[$ge->pcview_index] : null;
        } else if ($ge->gtype === GradeEntry::GTYPE_LATE_HOURS) {
            $ld = $this->late_hours_data();
            return $ld ? $ld->autohours : null;
        } else {
            return null;
        }
    }


    private function clear_can_view_grade() {
        $this->_vf = null;
        $this->_can_view_grade = null;
        if (($this->_grades_suppressed & 2) === 0) {
            $this->_grades_vf = [];
            $this->_gvf = null;
            $this->_gvf_scores = null;
            $this->_grades_suppressed = 0;
        }
    }

    /** @param array $updates
     * @param ?bool $is_student */
    function update_user_notes($updates, $is_student = null) {
        // find original
        $upi = $this->upi();
        assert(!!$upi);
        $is_student = $is_student ?? !$this->viewer->isPC;
        assert(!$is_student || !$this->has_newer_answers());

        // compare-and-swap loop
        while (true) {
            // change notes
            $new_notes = json_update($upi ? $upi->jnotes() : null, $updates);
            CommitPsetInfo::clean_notes($new_notes);

            // update database
            $notes = json_encode_db($new_notes);
            $notesa = strlen($notes) > 32000 ? null : $notes;
            $notesb = strlen($notes) > 32000 ? $notes : null;
            $hasactiveflags = CommitPsetInfo::notes_hasactiveflags($new_notes);
            if ($upi->phantom()) {
                $result = Dbl::qx($this->conf->dblink, "insert ignore into ContactGrade
                    set cid=?, pset=?,
                    updateat=?, updateby=?, studentupdateat=?,
                    notes=?, notesOverflow=?, hasactiveflags=?",
                    $this->user->contactId, $this->pset->id,
                    Conf::$now, $this->viewer->contactId, $is_student ? Conf::$now : null,
                    $notesa, $notesb, $hasactiveflags);
            } else if ($upi->notes === $notes) {
                return;
            } else {
                $result = $this->conf->qe("update ContactGrade
                    set notesversion=?,
                    updateat=?, updateby=?, studentupdateat=?,
                    notes=?, notesOverflow=?, hasactiveflags=?
                    where cid=? and pset=? and notesversion=?",
                    $upi->notesversion + 1,
                    Conf::$now, $this->viewer->contactId, $is_student ? Conf::$now : $upi->studentupdateat,
                    $notesa, $notesb, $hasactiveflags,
                    $this->user->contactId, $this->pset->id, $upi->notesversion);
            }
            if ($result && $result->affected_rows) {
                break;
            }

            // reload record
            $upi->reload($this->conf);
        }

        if (!$upi->phantom() && $this->pset->grades_history) {
            $unotes = json_encode_db(json_antiupdate($upi->jnotes(), $updates));
            $unotesa = strlen($unotes) > 32000 ? null : $unotes;
            $unotesb = strlen($unotes) > 32000 ? $unotes : null;
            $this->conf->qe("insert into ContactGradeHistory set cid=?, pset=?, notesversion=?, updateat=?, updateby=?, studentupdateat=?, antiupdate=?, antiupdateOverflow=?, antiupdateby=?",
                $this->user->contactId, $this->pset->id,
                $upi->notesversion, $upi->updateat ?? 0, $upi->updateby ?? 0,
                $upi->studentupdateat, $unotesa, $unotesb, $this->viewer->contactId);
        }

        $upi->assign_notes($notes, $new_notes); // also updates `notesversion`
        $upi->updateat = Conf::$now;
        $upi->updateby = $this->viewer->contactId;
        $upi->hasactiveflags = $hasactiveflags;
        if ($is_student) {
            $upi->studentupdateat = Conf::$now;
            $this->_snv = true;
        }
        $this->_vupi = null;
        $this->clear_can_view_grade();
        if (isset($updates["grades"]) || isset($updates["autogrades"])) {
            $this->_gtime = null;
            $this->user->invalidate_grades($this->pset->id);
        }
    }

    /** @param array $updates */
    function update_user_xnotes($updates) {
        $upi = $this->upi();
        $upi->materialize($this->conf);
        $new_xnotes = json_update($upi->jxnotes(), $updates);
        $xnotes = json_encode_db($new_xnotes);
        $xnotesa = strlen($xnotes) > 1000 ? null : $xnotes;
        $xnotesb = strlen($xnotes) > 1000 ? $xnotes : null;
        $result = $this->conf->qe("update ContactGrade set xnotes=?, xnotesOverflow=? where cid=? and pset=? and notesversion=?",
            $xnotesa, $xnotesb, $upi->cid, $upi->pset, $upi->notesversion);
        Dbl::free($result);
        $upi->assign_xnotes($xnotes, $new_xnotes);
    }


    /** @param array $updates */
    function update_repository_notes($updates) {
        $rpi = $this->rpi();
        do {
            $notes = json_update($rpi->jrpnotes(), $updates);
        } while (!$rpi->save_rpnotes($notes, $this));
    }


    /** @param array $updates */
    function update_commit_notes($updates) {
        assert(strlen($this->_hash ?? "") === 40);
        $cpi = $this->cpi();
        do {
            $notes = json_update($cpi->jnotes(), $updates);
        } while (!$cpi->save_notes($notes, $this));

        if (isset($updates["grades"]) || isset($updates["autogrades"])) {
            $this->clear_can_view_grade();
            $this->_gtime = null;
            if ($this->grading_hash() === $this->_hash) {
                $this->user->invalidate_grades($this->pset->id);
            }
        }
    }

    /** @param array $updates */
    function update_commit_xnotes($updates) {
        if (!$this->_hash) {
            throw new Error("update_commit_xnotes with no hash");
        }
        $cpi = $this->cpi();
        $cpi->materialize($this->conf);
        $new_xnotes = json_update($cpi->jxnotes(), $updates);
        $xnotes = json_encode_db($new_xnotes);
        $xnotesa = strlen($xnotes) > 1000 ? null : $xnotes;
        $xnotesb = strlen($xnotes) > 1000 ? $xnotes : null;
        $result = $this->conf->qe("update CommitNotes set xnotes=?, xnotesOverflow=? where pset=? and bhash=? and notesversion=?",
            $xnotesa, $xnotesb, $cpi->pset, $cpi->bhash, $cpi->notesversion);
        Dbl::free($result);
        $cpi->assign_xnotes($xnotes, $new_xnotes);
    }


    /** @param array $updates */
    function update_grade_notes($updates) {
        if ($this->pset->gitless_grades) {
            $this->update_user_notes($updates);
        } else {
            $this->update_commit_notes($updates);
        }
        // NB automatically unsuppresses all grades
        if ($this->pset->grades_selection_function
            && ($this->_grades_suppressed & 2) === 0) {
            $this->_grades_vf = [];
            $this->_gvf = null;
            $this->_gvf_scores = null;
            $this->_grades_suppressed = 0;
        }
    }

    /** @param array $updates */
    function update_grade_xnotes($updates) {
        if ($this->pset->gitless_grades) {
            $this->update_user_xnotes($updates);
        } else {
            $this->update_commit_xnotes($updates);
        }
    }


    /** @return ?bool */
    function pinned_scores_visible() {
        $xpi = $this->pset->gitless ? $this->upi() : $this->rpi();
        if ($xpi && $xpi->hidegrade != 0) {
            return $xpi->hidegrade < 0;
        } else {
            return null;
        }
    }

    /** @return 0|1|3|4|5|7
     *
     * Returns flags indicating who can view grades on this view. Bitwise
     * or of:
     * * VF_TF: TFs can view
     * * VF_STUDENT_ALLOWED: Students can view TF-assigned grades
     * * VF_STUDENT_ANY: Students can view grades they entered (i.e., answers)
     * If VF_STUDENT_ALLOWED is set, then VF_STUDENT_ANY is also set. */
    private function vf() {
        if ($this->_vf === null) {
            $this->_vf = 0;
            if ($this->viewer->isPC && $this->viewer->can_view_pset($this->pset)) {
                $this->_vf |= VF_TF;
            }
            if (($this->user === $this->viewer || ($this->_vf & VF_TF) !== 0)
                && $this->pset->visible_student()
                && ($this->pset->gitless_grades
                    || ($this->repo && $this->user_can_view_repo_contents()))) {
                $xpi = $this->pset->gitless ? $this->upi() : $this->rpi();
                if ($xpi && $xpi->hidegrade != 0) {
                    $all = $xpi->hidegrade < 0;
                } else {
                    $all = $this->pset->scores_visible_student();
                }
                $this->_vf |= $all ? VF_STUDENT_ANY : VF_STUDENT_ALWAYS;
            }
        }
        return $this->_vf;
    }

    /** @return list<0|4|5|6> */
    private function grades_vf() {
        if ($this->_grades_suppressed === 0) {
            $this->_grades_suppressed = 3;
            $this->_grades_vf = $this->pset->grades_vf($this);
            if ($this->pset->grades_selection_function) {
                call_user_func($this->pset->grades_selection_function, $this);
            }
            $this->_grades_suppressed &= ~2;
        }
        return $this->_grades_vf;
    }

    /** @param string|GradeEntry $key */
    function suppress_grade($key) {
        if ($this->_grades_suppressed === 0) {
            $this->grades_vf(); // call the selection function
        }
        $ge = is_string($key) ? $this->pset->grades[$key] : $key;
        if ($ge->pcview_index !== null) {
            $this->_grades_vf[$ge->pcview_index] = 0;
            $this->_grades_suppressed |= 4;
            $this->_gvf = null;
            if (!$ge->answer && !$ge->no_total) {
                $this->_gvf_scores = null;
            }
        }
    }

    /** @param null|0|1|3|4|5|7 $vf
     * @return list<GradeEntry> */
    function visible_grades($vf = null) {
        if ($this->_grades_suppressed === 0) {
            $this->grades_vf(); // call the selection function
        }
        $vf = $vf ?? ($this->pc_view ? VF_TF : $this->vf());
        if (($this->_grades_suppressed & 4) === 0
            && $vf >= VF_TF) {
            return $this->pset->visible_grades(VF_TF);
        } else {
            $g = [];
            foreach ($this->pset->visible_grades(VF_TF) as $i => $ge) {
                if (($this->_grades_vf[$i] & $vf) !== 0) {
                    $g[] = $ge;
                }
            }
            return $g;
        }
    }

    private function set_gvf() {
        if ($this->_grades_suppressed === 0) {
            $this->grades_vf(); // call the selection function
        }
        $this->_gvf = $this->_gvf_scores = 0;
        foreach ($this->pset->visible_grades(VF_TF) as $i => $ge) {
            $this->_gvf |= $this->_grades_vf[$i];
            if (!$ge->answer && !$ge->no_total) {
                $this->_gvf_scores |= $this->_grades_vf[$i];
            }
        }
    }

    /** @param string $key
     * return ?GradeEntry */
    function gradelike_by_key($key) {
        $ge = $this->pset->gradelike_by_key($key);
        if (!$ge || $ge->pcview_index === null) {
            return null;
        }
        $f = $this->pc_view ? VF_TF : $this->vf();
        if ((($this->grades_vf())[$ge->pcview_index] & $f) !== 0) {
            return $ge;
        } else {
            return null;
        }
    }

    /** @return bool */
    function can_view_any_grade() {
        return ($this->vf() & (VF_TF | VF_STUDENT_ALLOWED)) !== 0;
    }

    /** @return bool */
    function can_view_some_grade() {
        if ($this->_can_view_grade === null) {
            if ($this->viewer->isPC && $this->viewer->can_view_pset($this->pset)) {
                $this->_can_view_grade = true;
            } else if (($vf = $this->vf()) === 0) {
                $this->_can_view_grade = false;
            } else {
                if ($this->_gvf === null) {
                    $this->set_gvf();
                }
                $this->_can_view_grade = ($vf & $this->_gvf) !== 0;
            }
        }
        return $this->_can_view_grade;
    }

    /** @return bool */
    function user_can_view_any_grade() {
        return ($this->vf() & VF_STUDENT_ALLOWED) !== 0;
    }

    /** @return bool */
    function user_can_view_some_grade() {
        if ($this->_gvf === null) {
            $this->set_gvf();
        }
        return ($this->vf() & $this->_gvf & ~VF_TF) !== 0;
    }

    /** @return bool */
    function can_view_some_score() {
        return $this->pc_view || $this->user_can_view_some_score();
    }

    /** @return bool */
    function user_can_view_some_score() { /* NB: only for scores in total */
        if ($this->_gvf_scores === null) {
            $this->set_gvf();
        }
        return ($this->vf() & $this->_gvf_scores & ~VF_TF) !== 0;
    }

    /** @return bool */
    function can_view_grade_statistics() {
        return $this->pc_view || $this->user_can_view_grade_statistics();
    }

    /** @return bool */
    function user_can_view_grade_statistics() {
        // also see GradeStatistics_API
        $gsv = $this->pset->grade_statistics_visible;
        return $gsv === 1
            || ($gsv === 2 && $this->user_can_view_any_grade())
            || ($gsv > 2 && $gsv <= Conf::$now);
    }

    /** @return bool */
    function can_view_grade_statistics_graph() {
        return $this->pc_view
            || ($this->pset->grade_cdf_cutoff < 1
                && $this->user_can_view_grade_statistics());
    }

    /** @return bool */
    function can_edit_grade() {
        return $this->can_view_some_grade()
            && ($this->pc_view
                || ($this->pset->answers_editable_student()
                    && !$this->has_pinned_answers()));
    }

    /** @return bool */
    function can_edit_scores() {
        return $this->can_view_some_grade() && $this->pc_view;
    }

    /** @param null|0|1|3|4|5|7 $vf
     * @return ?int */
    function timermark_timeout($vf) {
        if (!$this->pset->has_timermark) {
            return null;
        }
        $to = null;
        $vf = $vf ?? $this->vf();
        foreach ($this->visible_grades($vf) as $ge) {
            if ($ge->type === "timermark"
                && ($v0 = $this->grade_value($ge)) > 0) {
                $t0 = $ge->timeout;
                if ($ge->timeout_entry
                    && ($ge1 = $this->gradelike_by_key($ge->timeout_entry))
                    && ($t1 = $this->grade_value($ge1)) !== null) {
                    $t0 = $t1;
                }
                if ($t0 && ($to === null || $v0 + $t0 < $to)) {
                    $to = $v0 + $t0;
                }
            }
        }
        return $to;
    }


    /** @return bool */
    function can_view_repo_contents($cached = false) {
        return $this->repo && $this->viewer->can_view_repo_contents($this->repo, $this->branch, $cached);
    }

    /** @return bool */
    function user_can_view_repo_contents($cached = false) {
        return $this->repo && $this->user->can_view_repo_contents($this->repo, $this->branch, $cached);
    }

    /** @return bool */
    function can_view_note_authors() {
        return $this->pc_view;
    }

    /** @return \Generator<GradeEntry> */
    private function nonempty_visible_grades() {
        if ($this->can_view_some_grade()) {
            $this->ensure_grades();
            if ($this->_g !== null || $this->pset->has_formula) {
                foreach ($this->visible_grades() as $ge) {
                    $gv = $this->_g !== null ? $this->_g[$ge->pcview_index] : null;
                    if ($gv === null && $ge->is_formula()) {
                        $gv = $this->grade_value($ge);
                    }
                    if ($gv !== null) {
                        yield $ge;
                    }
                }
            }
        }
    }

    /** @return bool */
    function can_view_nonempty_grade() {
        foreach ($this->nonempty_visible_grades() as $ge) {
            return true;
        }
        return false;
    }

    /** @return bool */
    function can_view_nonempty_score() {
        foreach ($this->nonempty_visible_grades() as $ge) {
            if (!$ge->answer && !$ge->concealed) {
                return true;
            }
        }
        return false;
    }

    /** @return bool */
    function needs_answers()  {
        if ($this->pset->has_answers && $this->can_view_some_grade()) {
            foreach ($this->nonempty_visible_grades() as $ge) {
                if ($ge->answer) {
                    return false;
                }
            }
            return true;
        } else {
            return false;
        }
    }

    /** @return int */
    function gradercid() {
        if ($this->pset->gitless_grades) {
            return $this->upi()->gradercid;
        } else {
            $rpi = $this->rpi();
            if ((!$rpi || $this->_hash !== $rpi->gradehash)
                && ($cn = $this->commit_jnotes())
                && ($cn->gradercid ?? 0) > 0) {
                return $cn->gradercid;
            } else {
                return $rpi ? $rpi->gradercid ?? 0 : 0;
            }
        }
    }

    /** @return bool */
    function viewer_is_grader() {
        return $this->viewer->contactId > 0
            && $this->viewer->contactId === $this->gradercid();
    }


    /** @return null|int|float */
    function deadline() {
        if (!$this->user->extension && $this->pset->deadline_college) {
            return $this->pset->deadline_college;
        } else if ($this->user->extension && $this->pset->deadline_extension) {
            return $this->pset->deadline_extension;
        } else {
            return $this->pset->deadline;
        }
    }

    /** @param bool $force
     * @return ?int */
    function student_timestamp($force) {
        if ($this->pset->gitless) {
            return $this->vupi()->studentupdateat;
        } else if ($this->_hash
                   && ($rpi = $this->rpi())
                   && $rpi->gradehash === $this->_hash
                   && $rpi->commitat) {
            return $rpi->commitat;
        } else if ($this->_hash && ($ls = $this->commit())) {
            return $ls->commitat;
        } else {
            return null;
        }
    }

    /** @param ?int $deadline
     * @param ?int $ts
     * @return ?int */
    static private function auto_late_hours($deadline, $ts) {
        if (!$deadline || ($ts ?? 0) <= 1) {
            return null;
        } else if ($deadline < $ts) {
            return (int) ceil(($ts - $deadline) / 3600);
        } else {
            return 0;
        }
    }

    /** @return ?LateHoursData */
    function late_hours_data() {
        if (!($deadline = $this->deadline())) {
            return null;
        }

        $cn = $this->grade_jnotes();
        $ts = $cn ? $cn->timestamp ?? null : null;
        $ts = $ts ?? $this->student_timestamp(true);
        $autohours = self::auto_late_hours($deadline, $ts);

        $ld = new LateHoursData;
        if (isset($cn->late_hours)) {
            $ld->hours = $cn->late_hours;
            if ($autohours !== null && $cn->late_hours !== $autohours) {
                $ld->autohours = $autohours;
            }
        } else {
            $ld->hours = $autohours;
        }
        if ($ts) {
            $ld->timestamp = $ts;
        }
        if ($deadline) {
            $ld->deadline = $deadline;
        }
        return $ld->is_empty() ? null : $ld;
    }

    /** @return ?int */
    function late_hours() {
        $cn = $this->current_jnotes();
        if ($cn && isset($cn->late_hours)) {
            return $cn->late_hours;
        } else if (($lhd = $this->late_hours_data()) && isset($lhd->hours)) {
            return $lhd->hours;
        } else {
            return null;
        }
    }

    /** @return ?int */
    function fast_late_hours() {
        $cn = $this->current_jnotes();
        if ($cn && isset($cn->late_hours)) {
            return $cn->late_hours;
        } else if (($deadline = $this->deadline())) {
            $ts = $cn ? $cn->timestamp ?? null : null;
            $ts = $ts ?? $this->student_timestamp(false);
            return self::auto_late_hours($deadline, $ts);
        } else {
            return null;
        }
    }


    /** @param -1|0|1|2 $placeholder
     * @param 0|1|2 $utype */
    function change_grading_commit($placeholder, $utype) {
        assert(!$this->pset->gitless);
        $rpi = $this->rpi();
        $commit = $placeholder > 0 ? $this->latest_commit() : $this->commit();
        $old_gradehash = $rpi->gradehash;
        $rpi->save_grading_commit($commit, $placeholder, $utype, $this->conf);
        if ($rpi->gradehash !== $old_gradehash) {
            $this->clear_can_view_grade();
            $this->user->invalidate_grades($this->pset->id);
        }
    }

    /** @param Contact|int $grader */
    function change_grader($grader) {
        if (is_object($grader)) {
            $gcid = $grader->contactId ? : null;
        } else {
            $gcid = $grader ? : null;
        }
        if ($this->pset->gitless_grades) {
            $upi = $this->upi();
            if ($upi->gradercid !== $gcid) {
                $upi->materialize($this->conf);
                $this->conf->qe("update ContactGrade set gradercid=? where cid=? and pset=?",
                    $gcid, $upi->cid, $upi->pset);
                $upi->gradercid = $gcid;
            }
        } else if ($this->_hash !== null) {
            $rpi = $this->rpi();
            if ($rpi->placeholder === RepositoryPsetInfo::PL_NONE) {
                $this->change_grading_commit(RepositoryPsetInfo::PL_USER, RepositoryPsetInfo::UTYPE_ADMIN);
            }
            if ($rpi->gradehash === $this->_hash && $rpi->gradercid !== $gcid) {
                $this->conf->qe("update RepositoryGrade set gradercid=? where repoid=? and branchid=? and pset=? and gradebhash=?",
                    $gcid, $rpi->repoid, $rpi->branchid, $rpi->pset, $rpi->gradebhash);
                $rpi->gradercid = $gcid;
            }
            $this->update_commit_notes(["gradercid" => $gcid]);
        } else {
            throw new Error("change_grader with no hash");
        }
        $this->clear_can_view_grade();
    }

    /** @param ?bool $vso */
    function set_pinned_scores_visible($vso) {
        $hidegrade = $vso === null ? 0 : ($vso ? -1 : 1);
        if ($this->pset->gitless_grades) {
            $upi = $this->upi();
            if ($upi->hidegrade !== $hidegrade) {
                $upi->materialize($this->conf);
                $this->conf->qe("update ContactGrade set hidegrade=? where cid=? and pset=?",
                    $hidegrade, $upi->cid, $upi->pset);
                $upi->hidegrade = $hidegrade;
                $this->clear_can_view_grade();
            }
        } else if ($this->repo) {
            $rpi = $this->rpi();
            if ($rpi->hidegrade !== $hidegrade) {
                $rpi->materialize($this->conf);
                $this->conf->qe("update RepositoryGrade set hidegrade=? where repoid=? and branchid=? and pset=?",
                    $hidegrade, $rpi->repoid, $rpi->branchid, $rpi->pset);
                $rpi->hidegrade = $hidegrade;
                $this->clear_can_view_grade();
            }
        }
    }

    function set_pinned_answer_version() {
        assert(!!$this->pset->gitless);
        $snv = $this->answer_version();
        $nv = $this->notesversion();
        $this->conf->qe("update ContactGrade set pinsnv=?, xnotes=null, xnotesOverflow=null where cid=? and pset=?", $snv !== null && $snv < $nv ? $snv : null, $this->user->contactId, $this->pset->id);
    }


    /** @return RunLogger */
    function run_logger() {
        $this->_run_logger = $this->_run_logger ?? new RunLogger($this->pset, $this->repo);
        return $this->_run_logger;
    }

    /** @param int $jobid
     * @param mixed $jlist
     * @return int|list */
    static function add_joblist($jobid, $jlist) {
        if (is_array($jlist)) {
            $i = 0;
            while ($i !== count($jlist) && $jobid < $jlist[$i]) {
                ++$i;
            }
            array_splice($jlist, $i, 0, [$jobid]);
            return $jlist;
        } else if (is_int($jlist)) {
            return $jobid < $jlist ? [$jlist, $jobid] : [$jobid, $jlist];
        } else {
            return $jobid;
        }
    }

    /** @param string $runner_name
     * @param int $jobid */
    function add_recorded_job($runner_name, $jobid) {
        $cnotes = $this->commit_jnotes();
        if ($cnotes && isset($cnotes->run) && is_object($cnotes->run)) {
            $jlist = $cnotes->run->{$runner_name} ?? null;
        } else {
            $jlist = null;
        }
        $jlist = self::add_joblist($jobid, $jlist);
        $this->update_commit_notes(["run" => [$runner_name => $jlist]]);
    }

    function update_recorded_jobs() {
        if ($this->repo && ($h = $this->hash())) {
            $runlog = $this->run_logger();
            $runs = [];
            foreach ($runlog->past_jobs() as $jobid) {
                if (($rr = $runlog->job_response($jobid))
                    && $rr->hash === $h
                    && $rr->done) {
                    $runs[$rr->runner] = self::add_joblist($jobid, $runs[$rr->runner] ?? null);
                }
            }
            $this->update_commit_notes(["run" => $runs]);
        }
    }

    /** @param string $runner_name
     * @return list<int> */
    function recorded_jobs($runner_name) {
        $cnotes = $this->commit_jnotes();
        if ($cnotes && isset($cnotes->run) && is_object($cnotes->run)) {
            $r = $cnotes->run->{$runner_name} ?? null;
            if (is_int($r)) {
                return [$r];
            } else if (is_array($r)) {
                return $r;
            }
        }
        return [];
    }

    /** @param string $runner_name
     * @return int|false */
    function latest_recorded_job($runner_name) {
        $rjs = $this->recorded_jobs($runner_name);
        return $rjs[0] ?? false;
    }

    /** @param string $runner_name
     * @return string|false */
    function latest_recorded_job_output($runner_name) {
        if (($jobid = $this->latest_recorded_job($runner_name))) {
            $fn = $this->run_logger()->output_file($jobid);
            $s = @file_get_contents($fn);
            $this->last_runner_error = $s === false ? self::ERROR_LOGMISSING : 0;
            return $s;
        } else {
            $this->last_runner_error = self::ERROR_NOTRUN;
            return false;
        }
    }

    /** @param string $runner_name
     * @return string|false
     * @deprecated */
    function runner_output_for($runner_name) {
        return $this->latest_recorded_job_output($runner_name);
    }

    /** @param RunnerConfig $runner
     * @param int $jobid
     * @return mixed */
    function runner_evaluate(RunnerConfig $runner, $jobid) {
        SiteLoader::require_includes(null, $runner->require);
        return call_user_func($runner->evaluate_function, $this, $runner, $jobid);
    }


    private function reset_transferred_warnings() {
        $this->transferred_warnings = [];
    }

    /** @param ?string $file
     * @param ?int $line
     * @param string $text
     * @param float $priority */
    private function transfer_one_warning($file, $line, $text, $priority) {
        if ($file !== null && $text !== "") {
            if (!isset($this->transferred_warnings[$file])) {
                $this->transferred_warnings[$file] = [];
            }
            if (!($tw = $this->transferred_warnings[$file][$line] ?? null)) {
                $tw = $this->transferred_warnings[$file][$line] = new PsetViewLineWarnings($priority - 1);
            }
            if ($tw->priority < $priority) {
                $tw->priority = $priority;
                $tw->texts = [];
                $tw->expected = 0;
            }
            if ($tw->priority == $priority) {
                if ($tw->expected < count($tw->texts)
                    && $text === $tw->texts[$tw->expected]) {
                    ++$tw->expected;
                } else if ($text[0] !== " "
                           && ($idx = array_search($text, $tw->texts, true)) !== false)  {
                    $tw->expected = $idx + 1;
                } else {
                    $tw->texts[] = $text;
                    $tw->expected = count($tw->texts);
                }
            }
        }
    }

    private function transfer_warning_lines($lines, $prio) {
        $file = $line = null;
        $expect_context = false;
        $in_instantiation = 0;
        $text = "";
        $nlines = count($lines);
        for ($i = 0; $i !== $nlines; ++$i) {
            $s = $lines[$i];
            $sda = preg_replace('/\x1b\[[\d;]*m|\x1b\[\d*K/', '', $s);
            if (preg_match('/\A([^\s:]*):(\d+):(?:\d+:)?\s*(\S*)/', $sda, $m)) {
                $this_instantiation = strpos($sda, "required from") !== false;
                if ($file && $m[3] === "note:") {
                    if (strpos($sda, "in expansion of macro") !== false) {
                        $file = $m[1];
                        $line = (int) $m[2];
                    }
                } else {
                    if (!$in_instantiation) {
                        $this->transfer_one_warning($file, $line, $text, $prio);
                        $text = "";
                    }
                    if ($in_instantiation !== 2 || $this_instantiation) {
                        $file = $m[1];
                        $line = (int) $m[2];
                    }
                }
                $text .= $s . "\n";
                $expect_context = true;
                if ($in_instantiation !== 0 && $this_instantiation) {
                    $in_instantiation = 2;
                } else {
                    $in_instantiation = 0;
                }
            } else if (preg_match('/\A(?:\S|\s+[A-Z]+\s)/', $sda)) {
                if (str_starts_with($sda, "In file included")) {
                    $text .= $s . "\n";
                    while ($i + 1 < $nlines && str_starts_with($lines[$i + 1], " ")) {
                        ++$i;
                        $text .= $lines[$i] . "\n";
                    }
                    $in_instantiation = 1;
                } else if (strpos($sda, "In instantiation of")) {
                    if (!$in_instantiation) {
                        $this->transfer_one_warning($file, $line, $text, $prio);
                        $file = $line = null;
                        $text = "";
                    }
                    $text .= $s . "\n";
                    $in_instantiation = 1;
                } else if ($expect_context
                           && $i + 1 < $nlines
                           && strpos($lines[$i + 1], "^") !== false) {
                    $text .= "{$s}\n{$lines[$i+1]}\n";
                    ++$i;
                    $in_instantiation = 0;
                } else {
                    $this->transfer_one_warning($file, $line, $text, $prio);
                    $file = $line = null;
                    $text = "";
                    $in_instantiation = 0;
                }
                $expect_context = false;
            } else if ($file !== null) {
                $text .= $s . "\n";
                $expect_context = false;
            }
        }
        $this->transfer_one_warning($file, $line, $text, $prio);
    }

    private function transfer_warnings() {
        $this->reset_transferred_warnings();

        // collect warnings from runner output
        foreach ($this->pset->runners as $runner) {
            if ($runner->transfer_warnings
                && $this->viewer->can_view_transferred_warnings($this->pset, $runner, $this->user)
                && ($output = $this->latest_recorded_job_output($runner->name))) {
                $this->transfer_warning_lines(explode("\n", $output), $runner->transfer_warnings_priority ?? 0.0);
            }
        }
    }

    /** @param string $file
     * @return array<int,PsetViewLineWarnings> */
    function transferred_warnings_for($file) {
        if ($this->transferred_warnings === null) {
            $this->transfer_warnings();
        }
        if (isset($this->transferred_warnings[$file])) {
            return $this->transferred_warnings[$file];
        }
        $slash = strrpos($file, "/");
        if ($slash !== false
            && isset($this->transferred_warnings[substr($file, $slash + 1)])) {
            return $this->transferred_warnings[substr($file, $slash + 1)];
        } else {
            return [];
        }
    }


    /** @return string */
    function user_linkpart() {
        return $this->viewer->user_linkpart($this->user);
    }

    /** @param ?array<string,null|int|string> $args
     * @return array<string,null|int|string> */
    function hoturl_args($args = null) {
        $xargs = ["pset" => $this->pset->urlkey, "u" => $this->user_linkpart()];
        if ($this->_hash) {
            $xargs["commit"] = $this->commit_hash();
        }
        if ($this->pset->gitless
            && !isset($args["snv"])) {
            $snv = $this->answer_version();
            if ($snv !== $this->notesversion() || $this->has_pinned_answers()) {
                $xargs["snv"] = $snv;
            }
        }
        foreach ($args ?? [] as $k => $v) {
            $xargs[$k] = $v;
        }
        return $xargs;
    }

    /** @param string $base
     * @param ?array<string,null|int|string> $args
     * @return string */
    function hoturl($base, $args = null) {
        return $this->conf->hoturl($base, $this->hoturl_args($args));
    }

    /** @param string $commit_html
     * @param string $rest_html
     * @param string $hash
     * @return string */
    function commit_link($commit_html, $rest_html, $hash) {
        $url = $this->hoturl("pset", ["commit" => $hash]);
        if ($rest_html) {
            return "<a href=\"{$url}\" class=\"q xtrack\"><code class=\"link\">{$commit_html}</code>{$rest_html}</a>";
        } else {
            return "<a href=\"{$url}\" class=\"track\"><code>{$commit_html}</code></a>";
        }
    }


    const GXF_OVERRIDE_VIEW = 0x01;
    const GXF_ENTRIES = 0x02;
    const GXF_GRADES = 0x04;
    const GXF_FORMULAS = 0x08;
    const GXF_LATE_HOURS = 0x10;

    const GXFM_ALL = 0x1E;
    const GXFM_TFSLICE = 0x1D;

    /** @param int $flags
     * @param ?list<0|4|5|7> $fixed_values_vf
     * @return ?GradeExport */
    function grade_export($flags = 0, $fixed_values_vf = null) {
        $flags = $flags ? : self::GXFM_ALL;
        $override_view = ($flags & self::GXF_OVERRIDE_VIEW) !== 0;
        if (!$override_view && !$this->can_view_some_grade()) {
            return null;
        }

        $vf = $this->vf();
        if ($override_view) {
            $vf |= VF_TF;
        }
        $gexp = new GradeExport($this->pset, $vf, $this);
        $gexp->uid = $this->user->contactId;
        $gexp->user = $this->user_linkpart();
        if (($flags & self::GXF_ENTRIES) !== 0) {
            $gexp->export_entries();
        } else {
            $gexp->slice = true;
        }

        $gexp->set_grades_vf($this->export_grades_vf($vf));
        if ($fixed_values_vf !== null) {
            $gexp->set_fixed_values_vf($fixed_values_vf);
        }

        $this->ensure_grades();
        if ($this->_g !== null
            || $this->pset->gitless_grades
            || $this->is_grading_commit()) {
            $this->grade_export_grades($gexp);
            $this->grade_export_linenotes($gexp);
            $this->grade_export_updates($gexp);
        }
        if (($flags & self::GXF_FORMULAS) !== 0
            && $this->pset->has_formula) {
            $this->grade_export_formulas($gexp);
        }

        if (!$this->pset->gitless) {
            $gexp->commit = $this->hash();
            if (!$this->is_grading_commit()) {
                $gexp->grade_commit = $this->grading_hash();
            }
        }
        if (($flags & self::GXF_LATE_HOURS) !== 0) {
            $this->grade_export_late_hours($gexp);
        }
        if ($this->can_edit_scores()) {
            $gexp->scores_editable = true;
        }
        if ($this->pset->gitless_grades) {
            if ($this->pset->gitless) {
                $upi = $this->upi();
                $gexp->version = $upi->notesversion;
                if (!$gexp->scores_editable) {
                    $gexp->answers_editable = !$this->pset->frozen
                        && !$this->has_newer_answers()
                        && !$this->has_pinned_answers();
                }
            } else {
                $rpi = $this->rpi();
                if ($rpi) {
                    $gexp->version = $rpi->rpnotesversion;
                }
                if (!$gexp->scores_editable) {
                    $gexp->answers_editable = !$this->pset->frozen;
                }
            }
        }
        if (($ts = $this->student_timestamp(false))) {
            $gexp->student_timestamp = $ts;
        }
        if (($psv = $this->pinned_scores_visible()) !== null) {
            $gexp->scores_visible = $psv;
        } else if ($this->pset->scores_visible_student()) {
            $gexp->scores_visible = true;
        }
        // maybe hide extra-credits that are missing
        if ($gexp->vf < VF_TF) {
            $gexp->suppress_absent_extra_entries();
        }
        return $gexp;
    }

    private function export_grades_vf($uvf) {
        $gvf = $this->grades_vf();
        if (!$this->has_older_answers() || !$this->pset->has_visible_if()) {
            return $gvf;
        }
        $clone = null;
        foreach ($gvf as $i => &$vf) {
            if (($uvf & $vf) !== 0) {
                continue;
            }
            if (!$clone) {
                $clone = clone $this;
                $clone->_grades_suppressed = 0;
                $clone->clear_can_view_grade();
                $clone->_gtime = -1;
                $clone->set_answer_version($this->upi()->notesversion);
            }
            $vf |= $this->pset->grade_by_pcindex($i)->vf($clone);
        }
        return $gvf;
    }

    function grade_export_grades(GradeExport $gexp) {
        $this->ensure_grades();
        $vges = $gexp->value_entries();
        $g = [];
        foreach ($vges as $ge) {
            $gv = $this->_g[$ge->pcview_index] ?? null;
            $g[] = $gv !== false ? $gv : null;
        }
        if ($this->_ag !== null) {
            $ag = [];
            foreach ($vges as $ge) {
                $ag[] = $this->_ag[$ge->pcview_index];
            }
        } else {
            $ag = null;
        }
        $gexp->set_grades_and_autogrades($g, $ag);
    }

    function grade_export_updates(GradeExport $gexp) {
        if (!$this->has_older_answers()) {
            return;
        }
        $gexp->answer_version = $this->vupi()->notesversion;
        $jnn = $this->upi()->jnote("grades");
        $jno = $this->vupi()->jnote("grades");
        foreach ($gexp->value_entries() as $ge) {
            if ($ge->answer
                && ($jnn->{$ge->key} ?? null) !== ($jno->{$ge->key} ?? null)) {
                $gexp->grades_latest[$ge->key] = $jnn->{$ge->key} ?? null;
            }
        }
    }

    function grade_export_linenotes(GradeExport $gexp) {
        if ($this->pset->has_answers
            && ($this->pset->gitless_grades
                ? ($xln = $this->user_jnote("linenotes"))
                : $this->hash() && ($xln = $this->commit_jnote("linenotes")))) {
            $gexp->lnorder = $this->empty_line_notes();
            $gexp->lnorder->add_json_map($xln);
        }
    }

    function grade_export_formulas(GradeExport $gexp) {
        if ($this->pset->has_formula) {
            foreach ($gexp->value_entries() as $i => $ge) {
                if ($ge->is_formula()) {
                    $v = $this->_g !== null ? $this->_g[$ge->pcview_index] : null;
                    $v = $v ?? $this->grade_value($ge);
                    if ($v !== null) {
                        $gexp->set_grade($ge, $v);
                    }
                }
            }
        }
    }

    function grade_export_late_hours(GradeExport $gexp) {
        if (($lhd = $this->late_hours_data())) {
            if (isset($lhd->hours)) {
                $gexp->late_hours = $lhd->hours;
            }
            if (isset($lhd->autohours) && $lhd->autohours !== $lhd->hours) {
                $gexp->auto_late_hours = $lhd->autohours;
            }
        }
    }

    /** @param int $flags
     * @param ?list<string> $known_entries
     * @return ?array */
    function grade_json($flags = 0, $known_entries = null) {
        $gexp = $this->grade_export($flags);
        if ($known_entries) {
            $gexp->suppress_known_entries($known_entries);
        }
        return $gexp->jsonSerialize();
    }

    /** @return array */
    function info_json() {
        $r = ["pset" => $this->pset->urlkey, "uid" => $this->user->contactId, "user" => $this->user_linkpart()];
        if (!$this->pset->gitless && $this->hash()) {
            $r["commit"] = $this->hash();
        }
        if ($this->user_can_view_some_score()) { // any_score???
            $r["scores_visible"] = true;
        }
        return $r;
    }


    /** @return LineNotesOrder */
    function empty_line_notes() {
        return new LineNotesOrder($this->can_view_any_grade(), $this->can_view_note_authors());
    }

    /** @return LineNotesOrder */
    function visible_line_notes() {
        $ln = $this->empty_line_notes();
        if ($this->viewer->can_view_comments($this->pset)) {
            if ($this->hash() !== null) {
                $ln->add_json_map($this->commit_jnote("linenotes") ?? []);
            }
            if ($this->pset->has_answers
                && $this->pset->gitless_grades) {
                $ln->add_json_map($this->user_jnote("linenotes") ?? []);
            }
        }
        return $ln;
    }

    /** @param float $prio */
    private function _add_local_diffconfigs($diffs, $prio) {
        if (is_object($diffs)) {
            foreach (get_object_vars($diffs) as $k => $v) {
                $this->pset->add_local_diffconfig(new DiffConfig($v, $k, $prio));
            }
        } else if (is_array($diffs)) {
            foreach ($diffs as $v) {
                $this->pset->add_local_diffconfig(new DiffConfig($v, null, $prio));
            }
        }
    }

    const DCTX_NO_LOCAL_DIFFCONFIG = 1;

    /** @param 0|1 $flags
     * @return DiffContext */
    function diff_context(CommitRecord $commita, CommitRecord $commitb,
                          ?LineNotesOrder $lnorder, $flags = 0) {
        if (($flags & self::DCTX_NO_LOCAL_DIFFCONFIG) !== 0) {
            $this->pset->set_local_diffconfig_source(null);
        } else if ($this->pset->set_local_diffconfig_source($this)) {
            if (($tw = $this->commit_jnote("tabwidth"))) {
                $this->pset->add_local_diffconfig(new DiffConfig((object) ["tabwidth" => $tw], ".*", 101.0));
            }
            if (($diffs = $this->repository_jnote("diffs"))) {
                $this->_add_local_diffconfigs($diffs, 100.0);
            }
            if (($diffs = $this->commit_jnote("diffs"))) {
                $this->_add_local_diffconfigs($diffs, 101.0);
            }
        }

        $dctx = new DiffContext($this->repo, $this->pset, $commita, $commitb);
        if ($lnorder) {
            $dctx->lnorder = $lnorder;
            foreach ($lnorder->fileorder() as $fn => $x) {
                $dctx->add_required_file($fn);
            }
        }
        return $dctx;
    }

    /** @param array &$args */
    private function _prepare_diff_args(?LineNotesOrder $lnorder, &$args) {
        if ($args["no_local_diffconfig"] ?? false) {
            $this->pset->set_local_diffconfig_source(null);
        } else if ($this->pset->set_local_diffconfig_source($this)) {
            if (($tw = $this->commit_jnote("tabwidth"))) {
                $this->pset->add_local_diffconfig(new DiffConfig((object) ["tabwidth" => $tw], ".*", 101.0));
            }
            if (($diffs = $this->repository_jnote("diffs"))) {
                $this->_add_local_diffconfigs($diffs, 100.0);
            }
            if (($diffs = $this->commit_jnote("diffs"))) {
                $this->_add_local_diffconfigs($diffs, 101.0);
            }
        }

        assert(!isset($args["needfiles"]));
        if ($lnorder) {
            $args["needfiles"] = $lnorder->fileorder();
        }
    }

    /** @return array<string,DiffInfo> */
    private function _read_diff(DiffContext $dctx) {
        // both repos must be in the same directory; assume handout
        // is only potential problem
        if ($dctx->commita->is_handout($this->pset) !== $dctx->commitb->is_handout($this->pset)) {
            $this->conf->handout_repo($this->pset, $this->repo);
        }

        // obtain diff
        $diff = $this->repo->diff($dctx);

        // update `emptydiff_at`
        if ($dctx->only_files === null
            && ($rpi = $this->rpi())
            && $dctx->commitb->hash === $rpi->gradehash
            && $dctx->commita === $this->base_handout_commit()
            && ($lhc = $this->pset->latest_handout_commit())) {
            $eda = empty($diff) ? $lhc->commitat : null;
            if ($rpi->emptydiff_at !== $eda) {
                $this->conf->ql("update RepositoryGrade set emptydiff_at=? where repoid=? and branchid=? and pset=?", $eda, $rpi->repoid, $rpi->branchid, $rpi->pset);
                $rpi->emptydiff_at = $eda;
            }
        }

        return $diff;
    }

    /** @param array<string,DiffInfo> $diff
     * @return array<string,DiffInfo> */
    private function _complete_diff($diff, DiffContext $dctx) {
        // expand diff to include grade landmarks
        if ($this->pset->has_grade_landmark
            && $this->pc_view) {
            foreach ($this->pset->grades() as $g) {
                if ($g->landmark_file
                    && ($di = $diff[$g->landmark_file] ?? null)
                    && !$di->contains_linea($g->landmark_line)
                    && $di->is_handout_commit_a()) {
                    $di->expand_linea($g->landmark_line - 2, $g->landmark_line + 3);
                }
                if ($g->landmark_range_file
                    && ($di = $diff[$g->landmark_range_file] ?? null)
                    && $di->is_handout_commit_a()) {
                    $di->expand_linea($g->landmark_range_first, $g->landmark_range_last);
                }
            }
        }

        if ($dctx->lnorder) {
            // expand diff to include notes
            $ndiff = count($diff);

            foreach ($dctx->lnorder->fileorder() as $fn => $order) {
                $di = $diff[$fn] ?? null;
                if (!$di) {
                    if (!$dctx->file_allowed($fn)) {
                        continue;
                    }
                    $diffc = $this->pset->find_diffconfig($fn);
                    $diff[$fn] = $di = new DiffInfo($fn, $diffc, $dctx);
                    if ($diffc->fileless) {
                        // add fake file
                        foreach ($dctx->lnorder->file($fn) as $lineid => $note) {
                            $l = (int) substr($lineid, 1);
                            $di->add("Z", null, $l, "");
                        }
                        continue;
                    }
                }
                foreach ($dctx->lnorder->file($fn) as $lineid => $note) {
                    if (!$di->contains_lineid($lineid)) {
                        $l = (int) substr($lineid, 1);
                        $di->expand_line($lineid[0], $l - 2, $l + 3);
                    }
                }
            }

            // add diff to linenotes
            if (count($diff) !== $ndiff) {
                uasort($diff, "DiffInfo::compare");
            }
            $dctx->lnorder->set_diff($diff);
        }

        // restrict diff
        $ndiff = [];
        foreach ($diff as $fn => $diffi) {
            if ((!$diffi->hide_if_anonymous || !$this->user->is_anonymous)
                && (!$diffi->is_empty() || !$diffi->loaded)) {
                $ndiff[$fn] = $diffi;
            }
        }
        return $ndiff;
    }

    /** @return array<string,DiffInfo> */
    function diff(DiffContext $dctx) {
        assert($dctx->repo === $this->repo && $dctx->pset === $this->pset);
        $diff = $this->_read_diff($dctx);
        return $this->_complete_diff($diff, $dctx);
    }

    /** @return array<string,DiffInfo> */
    function base_diff(DiffContext $dctx) {
        assert($dctx->repo === $this->repo && $dctx->pset === $this->pset);
        if (!$this->pset->has_diff_base) {
            return $this->diff($dctx);
        }

        // collect bases that differ from pset-wide base
        $bases = $cbyhash = $fbyhash = [];
        foreach ($this->repo->ls_files($dctx->hashb) as $fn) {
            if (!$dctx->file_allowed($fn)) {
                continue;
            }
            $diffc = $this->pset->find_diffconfig($fn);
            $base = $diffc ? $diffc->base : null;
            if ($base === null
                || $base === $this->pset->diff_base) {
                continue;
            }
            if (array_key_exists($base, $bases)) {
                $c = $bases[$base];
            } else {
                $c = $this->commit_in_base($base);
                if ($c !== null && $c !== $dctx->commita) {
                    $cbyhash[$c->hash] = $c;
                } else {
                    $c = null;
                }
                $bases[$base] = $c;
            }
            if ($c !== null) {
                $fbyhash[$c->hash][] = $fn;
            }
        }

        // merge diff on pset-wide base with diffs on file bases
        $diff = $this->_read_diff($dctx);
        if (!empty($fbyhash)) {
            foreach ($fbyhash as $hash => $flist) {
                foreach ($flist as $fn) {
                    unset($diff[$fn]);
                }
                $dctx1 = clone $dctx;
                $dctx1->set_commita($cbyhash[$hash]);
                $dctx1->set_allowed_files($flist);
                $diff = array_merge($diff, $this->_read_diff($dctx1));
            }
            uasort($diff, "DiffInfo::compare");
        }
        return $this->_complete_diff($diff, $dctx);
    }

    private function diff_line_code($t) {
        while (($p = strpos($t, "\t")) !== false) {
            $t = substr($t, 0, $p)
                . str_repeat(" ", $this->_diff_tabwidth - ($p % $this->_diff_tabwidth))
                . substr($t, $p + 1);
        }
        return htmlspecialchars($t);
    }

    /** @param string $file
     * @return string */
    function rawfile($file) {
        if ($this->repo->truncated_psetdir($this->pset)
            && str_starts_with($file, $this->pset->directory_slash)) {
            return substr($file, strlen($this->pset->directory_slash));
        } else {
            return $file;
        }
    }

    /** @param string $file
     * @param array $args */
    function echo_file_diff($file, DiffInfo $dinfo, LineNotesOrder $lnorder, $args) {
        $this->_diff_tabwidth = $dinfo->tabwidth;
        $this->_diff_lnorder = $lnorder;
        $expand = ($args["expand"] ?? !$dinfo->collapse) && $dinfo->loaded;
        $only_content = !!($args["only_content"] ?? false);
        $no_heading = ($args["no_heading"] ?? false) || $only_content;
        $no_grades = ($args["only_diff"] ?? false) || $only_content;
        $hide_left = ($args["hide_left"] ?? false) && !$only_content && !$dinfo->removed;

        $tabid = "F" . html_id_encode($file);
        if ($this->conf->multiuser_page) {
            $tabid = "U" . html_id_encode($this->user_linkpart()) . "/{$tabid}";
        }
        $linenotes = $lnorder->file($file);
        if ($this->can_view_note_authors()) {
            $this->conf->stash_hotcrp_pc($this->viewer);
        }
        $lineanno = [];
        $has_grade_range = false;
        if ($this->pset->has_grade_landmark
            && $this->pc_view
            && !$this->is_handout_commit()
            && $dinfo->is_handout_commit_a()
            && !$no_grades) {
            $rangeg = [];
            foreach ($this->pset->grades() as $g) {
                if ($g->landmark_range_file === $file) {
                    $rangeg[] = $g;
                }
                if ($g->landmark_file === $file) {
                    $la = PsetViewLineAnno::ensure($lineanno, "a" . $g->landmark_line);
                    $la->grade_entries[] = $g;
                }
            }
            if (!empty($rangeg)) {
                uasort($rangeg, function ($a, $b) {
                    if ($a->landmark_range_first < $b->landmark_range_last) {
                        return -1;
                    } else {
                        return $a->landmark_range_first == $b->landmark_range_last ? 0 : 1;
                    }
                });
                for ($i = 0; $i !== count($rangeg); ) {
                    $first = $rangeg[$i]->landmark_range_first;
                    $last = $rangeg[$i]->landmark_range_last;
                    for ($j = $i + 1;
                         $j !== count($rangeg) && $rangeg[$j]->landmark_range_first < $last;
                         ++$j) {
                        $last = max($last, $rangeg[$j]->landmark_range_last);
                    }
                    $la1 = PsetViewLineAnno::ensure($lineanno, "a" . $first);
                    $la2 = PsetViewLineAnno::ensure($lineanno, "a" . ($last + 1));
                    foreach ($this->pset->grades() as $g) {
                        if ($g->landmark_range_file === $file
                            && $g->landmark_range_first >= $first
                            && $g->landmark_range_last <= $last) {
                            $la1->grade_first[] = $g;
                            $la2->grade_last[] = $g;
                        }
                    }
                    $i = $j;
                }
                $has_grade_range = true;
            }
        }
        if ($this->pset->has_transfer_warnings
            && !$this->is_handout_commit()) {
            foreach ($this->transferred_warnings_for($file) as $lineno => $tw) {
                $la = PsetViewLineAnno::ensure($lineanno, "b" . $lineno);
                $la->warnings = $tw->texts;
                if (!$only_content) {
                    $this->need_format = true;
                }
            }
        }

        if (!$no_heading) {
            echo '<div class="pa-dg pa-with-fixed">',
                // NB Javascript depend on `h3` followed by `span`s and then `a`
                '<h3 class="pa-fileref">';
            $a = "<a class=\"q ui pa-diff-unfold\" href=\"#{$tabid}\">";
            echo $a, foldarrow($expand && $dinfo->loaded);
            if ($args["diffcontext"] ?? false) {
                echo '</a><span class="pa-fileref-context">',
                    $args["diffcontext"], '</span>', $a;
            }
            echo htmlspecialchars($dinfo->title ? : $file), "</a>";
            $bts = [];
            $bts[] = '<button type="button" class="btn ui pa-diff-toggle-hide-left'
                . ($hide_left ? "" : " btn-primary")
                . ' need-tooltip" aria-label="Toggle diff view">±</button>';
            if (!$dinfo->removed && $dinfo->markdown_allowed) {
                $bts[] = '<button type="button" class="btn ui pa-diff-toggle-markdown need-tooltip'
                    . ($hide_left && $dinfo->markdown ? " btn-primary" : "")
                    . '" aria-label="Toggle Markdown"><span class="icon-markdown"></span></button>';
            }
            if (!$dinfo->fileless && !$dinfo->removed) {
                $bts[] = '<a href="' . $this->hoturl("raw", ["file" => $this->rawfile($file)]) . '" class="btn need-tooltip" aria-label="Download"><span class="icon-download"></span></a>';
            }
            if (!empty($bts)) {
                echo '<div class="hdr-actions btnbox no-print">', join("", $bts), '</div>';
            }
            echo '</h3>';
        }

        echo '<div id="', $tabid, '" class="pa-filediff pa-dg';
        if ($hide_left) {
            echo " pa-hide-left";
        }
        if ($dinfo->tabwidth !== 4) {
            echo " pa-tabwidth-", $dinfo->tabwidth;
        }
        if ($dinfo->wdiff) {
            echo " pa-wdiff";
        }
        if ($this->pc_view) {
            echo " uim pa-editablenotes live";
        }
        if ($this->viewer->email === "gtanzer@college.harvard.edu") {
            echo " garrett";
        }
        if (($this->vf() & VF_STUDENT_ALLOWED) === 0) {
            echo " pa-scores-hidden";
        }
        if (!$expand || !$dinfo->loaded) {
            echo " hidden";
        }
        if (!$dinfo->loaded) {
            echo " need-load";
        } else {
            $maxline = max(1000, $dinfo->max_lineno()) - 1;
            echo " pa-line-digits-", ceil(log10($maxline));
        }
        if ($dinfo->highlight) {
            echo " need-decorate need-highlight";
        } else if ($hide_left && $dinfo->markdown) {
            echo " need-decorate need-markdown";
        }

        if ($dinfo->language) {
            echo '" data-language="', htmlspecialchars($dinfo->language);
        }
        echo '">'; // end div#F_...
        if ($has_grade_range) {
            echo '<div class="pa-dg pa-with-sidebar"><div class="pa-sidebar">',
                '</div><div class="pa-dg">';
        }
        $curanno = new PsetViewAnnoState($file, $tabid);
        foreach ($dinfo as $l) {
            $this->echo_line_diff($l, $linenotes, $lineanno, $curanno, $dinfo);
        }
        if ($has_grade_range) {
            echo '</div></div>'; // end div.pa-dg div.pa-dg.pa-with-sidebar
        }
        if (preg_match('/\.(?:png|jpg|jpeg|gif)\z/i', $file)) {
            echo '<img src="', $this->hoturl("raw", ["file" => $this->rawfile($file)]), '" alt="', htmlspecialchars("[{$file}]"), '" loading="lazy" class="pa-dr ui-error js-hide-error">';
        }
        echo '</div>'; // end div.pa-filediff#F_...
        if (!$no_heading) {
            echo '</div>'; // end div.pa-dg.pa-with-fixed
        }
        echo "\n";
        if (!$only_content && $this->need_format) {
            echo "<script>\$pa.render_text_page()</script>\n";
            $this->need_format = false;
        }
        if (!$only_content && ($dinfo->highlight || ($hide_left && $dinfo->markdown))) {
            echo "<script>\$pa.decorate_diff_page()</script>\n";
        }
    }

    /** @param array{string,?int,?int,string,?int} $l
     * @param array<string,LineNote> $linenotes
     * @param DiffInfo $dinfo */
    private function echo_line_diff($l, $linenotes, $lineanno, $curanno, $dinfo) {
        if ($l[0] === "@") {
            $cl = " pa-gx ui";
            if (($r = $dinfo->current_expandmark())) {
                $cl .= "\" data-expandmark=\"$r";
            }
            $cx = strlen($l[3]) > 76 ? substr($l[3], 0, 76) . "..." : $l[3];
            $x = [$cl, "pa-dcx", "", "", $cx];
        } else if ($l[0] === " ") {
            $x = [" pa-gc", "pa-dd", $l[1], $l[2], $l[3]];
        } else if ($l[0] === "-") {
            $x = [" pa-gd", "pa-dd", $l[1], "", $l[3]];
        } else if ($l[0] === "+") {
            $x = [" pa-gi", "pa-dd", "", $l[2], $l[3]];
        } else {
            $x = [null, null, "", $l[2], $l[3]];
        }

        $aln = $x[2] ? "a" . $x[2] : "";
        $bln = $x[3] ? "b" . $x[3] : "";
        $ala = $aln && isset($lineanno[$aln]) ? $lineanno[$aln] : null;

        if ($ala && ($ala->grade_first || $ala->grade_last)) {
            $end_grade_range = $ala->grade_last && $curanno->grade_first;
            $start_grade_range = $ala->grade_first
                && (!$curanno->grade_first || $end_grade_range);
            if ($start_grade_range || $end_grade_range) {
                echo '</div></div>';
                $curanno->grade_first = null;
            }
            if ($start_grade_range) {
                $curanno->grade_first = $ala->grade_first;
                echo '<div class="pa-dg pa-with-sidebar pa-grade-range-block" data-pa-landmark="a', $x[2], '"><div class="pa-sidebar"><div class="pa-gradebox pa-ps">';
                foreach ($curanno->grade_first as $g) {
                    echo '<div class="need-pa-grade" data-pa-grade="', $g->key, '"';
                    if ($g->landmark_buttons) {
                        echo ' data-pa-landmark-buttons="', htmlspecialchars(json_encode_browser($g->landmark_buttons)), '"';
                    }
                    echo '></div>';
                    $this->viewed_gradeentries[$g->key] = true;
                }
                echo '</div></div><div class="pa-dg">';
            } else if ($end_grade_range) {
                echo '<div class="pa-dg pa-with-sidebar"><div class="pa-sidebar"></div><div class="pa-dg">';
            }
        }

        $ak = $bk = "";
        if ($linenotes && $aln && isset($linenotes[$aln])) {
            $ak = " id=\"L{$aln}{$curanno->diffid}\"";
        }
        if ($linenotes && $bln && isset($linenotes[$bln])) {
            $bk = " id=\"L{$bln}{$curanno->diffid}\"";
        }

        if (!$x[2] && !$x[3]) {
            $x[2] = $x[3] = "...";
        }
        if ($x[2]) {
            $ak .= " data-landmark=\"{$x[2]}\"";
        }
        if ($x[3]) {
            $bk .= " data-landmark=\"{$x[3]}\"";
        }

        $nx = null;
        if ($linenotes) {
            if ($bln && isset($linenotes[$bln])) {
                $nx = $linenotes[$bln];
            } else if ($aln && isset($linenotes[$aln])) {
                $nx = $linenotes[$aln];
            }
        }

        if ($x[0]) {
            $f = $l[4] ?? 0;
            echo '<div class="pa-dl', $x[0], '">',
                '<div class="pa-da"', $ak, '></div>',
                '<div class="pa-db"', $bk, '></div>',
                '<div class="', $x[1],
                ($f & DiffInfo::LINE_NONL ? ' pa-dnonl">' : '">'),
                $this->diff_line_code($x[4]),
                ($f & DiffInfo::LINE_NONL ? "</div></div>\n" : "\n</div></div>\n");
        }

        if ($bln && isset($lineanno[$bln]) && $lineanno[$bln]->warnings !== null) {
            echo '<div class="pa-dl pa-gn" data-landmark="', $bln, '"><div class="pa-warnbox"><div class="pa-warncontent need-format" data-format="2">', htmlspecialchars(join("", $lineanno[$bln]->warnings)), '</div></div></div>';
            $this->need_format = true;
        }

        if ($ala) {
            foreach ($ala->grade_entries ?? [] as $g) {
                echo '<div class="pa-dl pa-gn';
                if ($curanno->grade_first && in_array($g, $curanno->grade_first)) {
                    echo ' pa-no-sidebar';
                }
                echo '" data-landmark="', $aln, '"><div class="pa-graderow">',
                    '<div class="pa-gradebox need-pa-grade" data-pa-grade="', $g->key, '"';
                if ($g->landmark_file === $g->landmark_range_file
                    && $g->landmark_buttons) {
                    echo ' data-pa-landmark-buttons="', htmlspecialchars(json_encode_browser($g->landmark_buttons)), '"';
                }
                echo '></div></div></div>';
                $this->viewed_gradeentries[$g->key] = true;
            }
        }

        if ($nx) {
            $this->echo_linenote($nx);
        }
    }

    private function echo_linenote(LineNote $note) {
        echo '<div class="pa-dl pa-gw'; /* NB script depends on this class exactly */
        if ((string) $note->ftext === "") {
            echo ' hidden';
        }
        echo '" data-landmark="', $note->lineid,
            '" data-pa-note="', htmlspecialchars(json_encode_browser($note->render())),
            '"><div class="pa-notebox">';
        if ((string) $note->ftext === "") {
            echo '</div></div>'; // pa-notebox, pa-dl
            return;
        }
        echo '<div class="pa-notecontent">';
        $links = [];
        $nnote = $this->_diff_lnorder->get_next($note);
        if ($nnote) {
            $links[] = "<a href=\"#L{$nnote->lineid}F"
                . html_id_encode($nnote->file) . '">Next &gt;</a>';
        } else {
            $links[] = '<a href="#">Top</a>';
        }
        if (!empty($links)) {
            echo '<div class="pa-note-links">',
                join("&nbsp;&nbsp;&nbsp;", $links) , '</div>';
        }
        if ($this->can_view_note_authors() && !empty($note->users)) {
            $pcmembers = $this->conf->pc_members_and_admins();
            $autext = [];
            foreach ($note->users as $au) {
                if (($p = $pcmembers[$au] ?? null)) {
                    if ($p->nicknameAmbiguous) {
                        $autext[] = Text::name_html($p);
                    } else {
                        $autext[] = htmlspecialchars($p->nickname ? : $p->firstName);
                    }
                }
            }
            if (!empty($autext)) {
                echo '<div class="pa-note-author">[', join(", ", $autext), ']</div>';
            }
        }
        if (!$note->iscomment) {
            echo '<div class="pa-gradenote-marker"></div>';
        }
        echo '<div class="pa-dr pa-note', ($note->iscomment ? ' pa-commentnote' : ' pa-gradenote');
        if (str_starts_with($note->ftext, "<")) {
            echo ' need-format';
            $this->need_format = true;
        } else {
            echo ' format0';
        }
        echo '">', htmlspecialchars($note->ftext), '</div>';
        echo '</div></div></div>'; // pa-notecontent, pa-notebox, pa-dl
    }

    const SIDEBAR_GRADELIST = 1;
    const SIDEBAR_GRADELIST_LINKS = 2;
    const SIDEBAR_FILENAV = 4;
    /** @param int $flags
     * @param array<string,DiffInfo> $diff */
    static function print_sidebar_open($flags, $diff) {
        if ($flags === 0) {
            return;
        }
        echo '<div class="pa-dg pa-with-sidebar"><div class="pa-sidebar">';
        if (($flags & self::SIDEBAR_FILENAV) !== 0) {
            echo '<div class="pa-gradebox pa-filenavbox"><nav>',
                '<ul class="pa-filenav-list">';
            foreach ($diff as $file => $di) {
                echo '<li><a class="ui pa-filenav ulh" href="#F', html_id_encode($file), '">',
                    htmlspecialchars($file), '</a></li>';
            }
            echo '</ul></nav></div>';
        }
        if (($flags & self::SIDEBAR_GRADELIST) !== 0) {
            echo '<div class="pa-gradebox pa-ps need-pa-gradelist';
            if (($flags & self::SIDEBAR_GRADELIST_LINKS) !== 0) {
                echo ' want-psetinfo-links';
            }
            echo '"></div>';
        }
        echo '</div><div class="pa-dg">';
    }

    /** @param int $flags */
    static function print_sidebar_close($flags) {
        if ($flags !== 0) {
            echo '</div></div>';
        }
    }
}

class PsetViewLineWarnings {
    /** @var list<string> */
    public $texts = [];
    /** @var float */
    public $priority;
    /** @var int */
    public $expected = 0;

    function __construct($priority) {
        $this->priority = $priority;
    }
}

class PsetViewLineAnno {
    /** @var ?list<GradeEntry> */
    public $grade_entries;
    /** @var ?list<GradeEntry> */
    public $grade_first;
    /** @var ?list<GradeEntry> */
    public $grade_last;
    /** @var ?list<string> */
    public $warnings;

    /** @param array<string,PsetViewLineAnno> &$lineanno
     * @param string $lineid
     * @return PsetViewLineAnno */
    static function ensure(&$lineanno, $lineid) {
        if (!isset($lineanno[$lineid])) {
            $lineanno[$lineid] = new PsetViewLineAnno;
        }
        return $lineanno[$lineid];
    }
}

class PsetViewAnnoState {
    /** @var string */
    public $file;
    /** @var string */
    public $diffid;
    /** @var ?list<GradeEntry> */
    public $grade_first;

    /** @param string $file
     * @param string $diffid */
    function __construct($file, $diffid) {
        $this->file = $file;
        $this->diffid = $diffid;
    }
}
