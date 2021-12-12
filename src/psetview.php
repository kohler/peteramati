<?php
// psetview.php -- CS61-monster helper class for pset view
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler
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
    /** @var ?int */
    private $_vupi;
    /** @var ?RepositoryPsetInfo */
    private $_rpi;
    /** @var ?CommitPsetInfo */
    private $_cpi;
    /** @var ?string */
    private $_hash;
    /** @var false|null|CommitRecord */
    private $_derived_handout_commit = false;
    /** @var bool */
    private $_is_sset = false;

    /** @var ?bool */
    private $_can_view_grade;
    /** @var ?bool */
    private $_user_can_view_grade;
    /** @var ?bool */
    private $_user_can_view_score;
    /** @var list<int> */
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
    /** @var ?bool */
    private $_gallreq;

    /** @var bool */
    private $need_format = false;
    /** @var bool */
    private $added_diffinfo = false;

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

    static private $forced_commitat = 0;

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
     * @return PsetView
     * @suppress PhanAccessReadOnlyProperty */
    static function make(Pset $pset, Contact $user, Contact $viewer,
                         $hash = null) {
        $info = new PsetView($pset, $user, $viewer);
        $info->partner = $user->partner($pset->id);
        if (!$pset->gitless) {
            $info->repo = $user->repo($pset->id);
            $info->branchid = $user->branchid($pset);
            $info->branch = $info->conf->branch($info->branchid);
        }
        if ($hash !== "none") {
            $info->set_hash($hash);
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

    /** @return ?RepositoryPsetInfo */
    private function rpi() {
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


    /** @return array<string,CommitRecord> */
    function recent_commits() {
        if ($this->repo) {
            return $this->repo->commits($this->pset, $this->branch);
        } else {
            return [];
        }
    }

    /** @return ?CommitRecord */
    function connected_commit($hash) {
        if ($this->repo) {
            return $this->repo->connected_commit($hash, $this->pset, $this->branch);
        } else {
            return null;
        }
    }

    /** @return ?CommitRecord */
    function latest_commit() {
        return $this->repo ? $this->repo->latest_commit($this->pset, $this->branch) : null;
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
        $rpi = $this->pset->gitless_grades ? null : $this->rpi();
        return $rpi && !$rpi->placeholder ? $rpi->gradehash : null;
    }


    /** @param string $hashpart
     * @return ?CommitRecord */
    function find_commit($hashpart) {
        if ($hashpart === "handout") {
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
        if ($this->_hash === null) {
            error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) . " " . $this->viewer->email);
        }
        assert($this->_hash !== null);
        return $this->_hash ? $this->connected_commit($this->_hash) : null;
    }

    /** @param ?string $reqhash
     * @param ?StudentSet $sset */
    function force_set_hash($reqhash, $sset = null) {
        assert($reqhash === null || (strlen($reqhash) === 40 && $this->repo));
        assert(!$this->_is_sset || $sset);
        if ($this->_hash !== $reqhash) {
            $this->_hash = $reqhash;
            if ($sset && $reqhash !== null) {
                $this->_cpi = $sset->cpi_for($this->pset, $reqhash, $this->repo);
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

    /** @param ?StudentSet $sset */
    function set_latest_nontrivial_commit($sset = null) {
        if (($c = $this->latest_nontrivial_commit())) {
            $this->force_set_hash($c->hash, $sset);
        } else {
            $this->force_set_hash(null, $sset);
        }
    }

    function set_grading_or_latest_nontrivial_commit() {
        if (($hash = $this->grading_hash())) {
            $this->force_set_hash($hash);
        } else {
            $this->set_latest_nontrivial_commit();
        }
    }

    /** @param ?string $reqhash
     * @return void */
    function set_hash($reqhash) {
        if (!$this->repo || $reqhash === "none") {
            $this->force_set_hash(null);
        } else if ($reqhash === null || $reqhash === "") {
            $this->set_grading_or_latest_nontrivial_commit();
        } else if (($c = $this->repo->connected_commit($reqhash, $this->pset, $this->branch))) {
            $this->set_commit($c);
        } else {
            $this->force_set_hash(null);
        }
    }

    /** @return bool */
    function is_handout_commit() {
        return $this->_hash && $this->_hash === $this->derived_handout_hash();
    }

    /** @return bool */
    function is_grading_commit() {
        return $this->pset->gitless_grades
            || ($this->_hash !== null
                && ($rpi = $this->rpi())
                && !$rpi->placeholder
                && $rpi->gradehash === $this->_hash);
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
            foreach ($this->recent_commits() as $c) {
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


    /** @param ?callable(PsetView,?RepositoryPsetInfo):bool $updater
     * @return void */
    function update_placeholder($updater) {
        if (!$this->pset->gitless_grades && $this->repo) {
            $rpi = $this->rpi();
            if (($rpi->placeholder
                 || $rpi->gradebhash === null)
                && ($updater === null
                    || call_user_func($updater, $this, $rpi))) {
                $c = $this->latest_commit();
                $bh = $c ? hex2bin($c->hash) : null;
                if (($rpi->placeholder_at ?? 0) === 0
                    || $rpi->gradebhash !== $bh) {
                    $rpi->materialize($this->conf);
                    $this->conf->qe("update RepositoryGrade
                        set gradebhash=(if(placeholder=1,?,gradebhash)),
                        commitat=(if(placeholder=1,?,commitat)),
                        placeholder_at=?, emptydiff_at=null
                        where repoid=? and branchid=? and pset=?",
                        $bh, $c ? $c->commitat : null, Conf::$now,
                        $rpi->repoid, $rpi->branchid, $rpi->pset);
                    $rpi->reload($this->conf);
                }
            }
        }
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
        $upi = $this->_vupi ?? $this->upi();
        return $upi ? $upi->jnotes() : null;
    }

    /** @param ?int $notesversion
     * @param bool $student_only */
    function set_user_notesversion($notesversion, $student_only = false) {
        if (($upi = $this->upi()) && $notesversion < $upi->notesversion) {
            $this->_vupi = $upi->version_at($notesversion, $student_only, $this->conf);
        } else {
            $this->_vupi = null;
        }
    }

    /** @return ?object */
    function user_jxnotes() {
        $upi = $this->upi();
        return $upi ? $upi->jxnotes() : null;
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
        if ($this->_gtime !== $this->user->gradeUpdateTime) {
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
    }

    private function ensure_formulas() {
        if ($this->pset->has_formula && !$this->_has_formula) {
            $this->_has_formula = true;
            $this->_g = $this->_g ?? $this->blank_values();
            $this->_has_fg = [];
            $jn = $this->grade_jxnotes();
            $t = max($this->user->gradeUpdateTime, $this->pset->config_mtime);
            //error_log("{$t} {$this->user->gradeUpdateTime} {$this->pset->config_mtime} {$jn->formula_at}");
            if (!$jn || ($jn->formula_at ?? null) !== $t) {
                $fs = [];
                foreach ($this->pset->formula_grades() as $ge) {
                    $f = $ge->formula();
                    $v = $f->evaluate($this->user);
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
            } else if (($g = $jn->formula ?? null)) {
                foreach ($this->pset->formula_grades() as $ge) {
                    if (property_exists($g, $ge->key)) {
                        $v = $g->{$ge->key};
                        if ($v !== null) {
                            $this->_g[$ge->pcview_index] = $v;
                        } else {
                            $this->_has_fg[$ge->pcview_index] = true;
                        }
                    }
                }
            }
        }
    }

    private function ensure_visible_total() {
        if ($this->_gtottime !== $this->user->gradeUpdateTime) {
            $this->ensure_grades();
            $this->_gtottime = $this->user->gradeUpdateTime;
            $this->_gtot = $this->_gtotne = $this->_gmaxtot = null;
            $this->_gallreq = true;
            foreach ($this->visible_grades() as $ge) {
                if (!$ge->no_total) {
                    $v = $this->_g ? $this->_g[$ge->pcview_index] : null;
                    if ($v !== null) {
                        $this->_gtot = ($this->_gtot ?? 0) + $v;
                    }
                    if ($v !== null && !$ge->is_extra) {
                        $this->_gtotne = ($this->_gtotne ?? 0) + $v;
                    }
                    if ($v === null && $ge->required && !$ge->answer) {
                        $this->_gallreq = false;
                    } else if (!$ge->is_extra && $ge->max_visible) {
                        $this->_gmaxtot = ($this->_gmaxtot ?? 0) + $ge->max;
                    }
                }
                $this->_gtot = round_grade($this->_gtot);
                $this->_gtotne = round_grade($this->_gtotne);
            }
        }
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
    function visible_total_noextra() {
        $this->ensure_visible_total();
        return $this->_gtotne;
    }

    /** @return bool */
    function has_visible_required_scores() {
        $this->ensure_visible_total();
        return $this->_gallreq;
    }


    /** @param string|GradeEntryConfig $ge
     * @return null|int|float|string */
    function grade_value($ge) {
        if (is_string($ge)) {
            $ge = $this->pset->gradelike_by_key($ge);
        }
        if (!$ge || $ge->pcview_index === null) {
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
                $gv = $ge->formula()->evaluate($this->user);
                if ($gv !== null) {
                    $this->_g[$ge->pcview_index] = $gv;
                } else {
                    $this->_has_fg[$ge->pcview_index] = true;
                }
            }
        }
        return $gv;
    }

    /** @param string|GradeEntryConfig $ge
     * @return null|int|float|string */
    function autograde_value($ge) {
        if (is_string($ge)) {
            $ge = $this->pset->gradelike_by_key($ge);
        }
        $gv = null;
        if ($ge && $ge->pcview_index !== null && !$ge->is_formula()) {
            $this->ensure_grades();
            $gv = $this->_ag !== null ? $this->_ag[$ge->pcview_index] : null;
        }
        return $gv;
    }


    /** @param ?object $j */
    static private function clean_notes($j) {
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

    private function clear_can_view_grade() {
        $this->_can_view_grade = null;
        $this->_user_can_view_grade = null;
        $this->_user_can_view_score = null;
        if (($this->_grades_suppressed & 2) === 0) {
            $this->_grades_vf = [];
            $this->_grades_suppressed = 0;
        }
    }

    /** @param array $updates
     * @param ?bool $is_student */
    function update_user_notes($updates, $is_student = null) {
        // find original
        $upi = $this->upi();
        $is_student = $is_student ?? !$this->viewer->isPC;
        assert(!!$upi);
        assert($this->_vupi === null
               || (!$is_student && $this->_vupi->studentnotesversion !== null));

        // compare-and-swap loop
        while (true) {
            // change notes
            $new_notes = json_update($upi ? $upi->jnotes() : null, $updates);
            self::clean_notes($new_notes);

            // update database
            $notes = json_encode_db($new_notes);
            $notesa = strlen($notes) > 32000 ? null : $notes;
            $notesb = strlen($notes) > 32000 ? $notes : null;
            $hasactiveflags = self::notes_hasactiveflags($new_notes);
            if ($upi->phantom) {
                $result = Dbl::qx($this->conf->dblink, "insert ignore into ContactGrade
                    set cid=?, pset=?, updateat=?, updateby=?, studentupdateat=?,
                    notes=?, notesOverflow=?, hasactiveflags=?",
                    $this->user->contactId, $this->pset->id,
                    Conf::$now, $this->viewer->contactId,
                    $is_student ? Conf::$now : null,
                    $notesa, $notesb, $hasactiveflags);
            } else if ($upi->notes === $notes) {
                return;
            } else {
                $result = $this->conf->qe("update ContactGrade
                    set notesversion=?, updateat=?, updateby=?, studentupdateat=?,
                    notes=?, notesOverflow=?, hasactiveflags=?
                    where cid=? and pset=? and notesversion=?",
                    $upi->notesversion + 1, Conf::$now, $this->viewer->contactId,
                    $is_student ? Conf::$now : $upi->studentupdateat,
                    $notesa, $notesb, $hasactiveflags,
                    $this->user->contactId, $this->pset->id, $upi->notesversion);
            }
            if ($result && $result->affected_rows) {
                break;
            }

            // reload record
            $upi->reload($this->conf);
        }

        if (!$upi->phantom && $this->pset->grades_history) {
            $unotes = json_encode_db(json_antiupdate($upi->jnotes(), $updates));
            $unotesa = strlen($unotes) > 32000 ? null : $unotes;
            $unotesb = strlen($unotes) > 32000 ? $unotes : null;
            $this->conf->qe("insert into ContactGradeHistory set cid=?, pset=?, notesversion=?, updateat=?, updateby=?, studentupdateat=?, notes=?, notesOverflow=?",
                $this->user->contactId, $this->pset->id,
                $upi->notesversion, $upi->updateat ?? 0, $upi->updateby ?? 0,
                $upi->studentupdateat, $unotesa, $unotesb);
        }

        $upi->assign_notes($notes, $new_notes); // also updates `notesversion`
        $upi->updateat = Conf::$now;
        $upi->updateby = $this->viewer->contactId;
        $is_student && ($upi->studentupdateat = Conf::$now);
        $upi->hasactiveflags = $hasactiveflags;
        $this->clear_can_view_grade();
        if ($this->_vupi) {
            $this->_vupi = $upi->version_at($this->_vupi->studentnotesversion, true, $this->conf);
        }
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
        // find original
        $rpi = $this->rpi();
        assert(!!$rpi);

        // compare-and-swap loop
        while (true) {
            // change notes
            $new_notes = json_update($rpi->jrpnotes(), $updates);
            self::clean_notes($new_notes);

            // update database
            $notes = json_encode_db($new_notes);
            $notesa = strlen($notes) > 16000 ? null : $notes;
            $notesb = strlen($notes) > 16000 ? $notes : null;
            if ($rpi->phantom) {
                $result = Dbl::qe($this->conf->dblink, "insert ignore into RepositoryGrade set
                    repoid=?, branchid=?, pset=?,
                    placeholder=1, placeholder_at=?, rpnotes=?, rpnotesOverflow=?",
                    $rpi->repoid, $rpi->branchid, $rpi->pset,
                    Conf::$now, $notesa, $notesb);
            } else if ($rpi->rpnotes === $notes) {
                return;
            } else {
                $result = $this->conf->qe("update RepositoryGrade
                    set rpnotes=?, rpnotesOverflow=?, rpnotesversion=?
                    where repoid=? and branchid=? and pset=? and rpnotesversion=?",
                    $notesa, $notesb, $rpi->rpnotesversion + 1,
                    $rpi->repoid, $rpi->branchid, $rpi->pset, $rpi->rpnotesversion);
            }
            if ($result && $result->affected_rows) {
                break;
            }

            // reload record
            $rpi->reload($this->conf);
        }

        $rpi->assign_rpnotes($notes, $new_notes);
    }


    /** @param non-empty-string $hash
     * @param array $updates
     * @param ?bool $is_student */
    function update_commit_notes_at($hash, $updates, $is_student = null) {
        assert(strlen($hash) === 40);

        // find original
        if ($this->_hash === $hash) {
            $cpi = $this->cpi();
        } else {
            $cpi = $this->pset->cpi_at($hash) ?? new CommitPsetInfo($this->pset->id, $hash, $this->repo->repoid);
        }
        $old_notes = $cpi->jnotes();
        $commit = null;

        // compare-and-swap loop
        while (true) {
            // change notes
            $new_notes = json_update($old_notes, $updates);
            self::clean_notes($new_notes);

            // update database
            $notes = json_encode_db($new_notes);
            $haslinenotes = self::notes_haslinenotes($new_notes);
            $hasflags = self::notes_hasflags($new_notes);
            $hasactiveflags = self::notes_hasactiveflags($new_notes);
            if ($cpi->phantom) {
                $commit = $commit ?? $this->connected_commit($hash);
                $commitat = $commit ? $commit->commitat : null;
                $result = $this->conf->qe("insert ignore into CommitNotes set
                    pset=?, bhash=?, repoid=?, commitat=?,
                    notes=?, haslinenotes=?, hasflags=?, hasactiveflags=?,
                    notesversion=?, updateat=?",
                    $cpi->pset, $cpi->bhash, $this->repo->repoid, $commitat,
                    $notes, $haslinenotes, $hasflags, $hasactiveflags,
                    1, Conf::$now);
            } else if ($old_notes === $notes) {
                return;
            } else {
                $result = $this->conf->qe("update CommitNotes set
                    notes=?, haslinenotes=?, hasflags=?, hasactiveflags=?,
                    notesversion=?, updateat=?
                    where pset=? and bhash=? and notesversion=?",
                    $notes, $haslinenotes, $hasflags, $hasactiveflags,
                    $cpi->notesversion + 1, Conf::$now,
                    $cpi->pset, $cpi->bhash, $cpi->notesversion);
            }
            if ($result && $result->affected_rows) {
                break;
            }

            // reload record
            $cpi->reload($this->conf);
            $old_notes = $cpi->jnotes();
        }

        if ($this->_hash === $hash) {
            $cpi->assign_notes($notes, $new_notes);
            $cpi->hasflags = $hasflags;
            $cpi->hasactiveflags = $hasactiveflags;
            $cpi->haslinenotes = $haslinenotes;
        }
        if (isset($updates["grades"]) || isset($updates["autogrades"])) {
            $this->clear_can_view_grade();
            $this->_gtime = null;
            if ($this->grading_hash() === $hash) {
                $this->user->invalidate_grades($this->pset->id);
            }
        }
    }

    /** @param array $updates */
    function update_commit_notes($updates) {
        if (!$this->_hash) {
            throw new Error("update_commit_notes with no hash");
        }
        $this->update_commit_notes_at($this->_hash, $updates);
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


    /** @return list<int> */
    private function grades_vf() {
        if ($this->_grades_suppressed === 0) {
            $this->_grades_suppressed = 3;
            $this->_grades_vf = $this->pset->grades_vf($this->pinned_scores_visible());
            if ($this->pset->grades_selection_function) {
                call_user_func($this->pset->grades_selection_function, $this);
            }
            $this->_grades_suppressed &= ~2;
        }
        return $this->_grades_vf;
    }

    /** @param string $key */
    function suppress_grade($key) {
        if ($this->_grades_suppressed === 0) {
            $this->grades_vf(); // call the selection function
        }
        $ge = $this->pset->grades[$key];
        if ($ge->pcview_index !== null) {
            $this->_grades_vf[$ge->pcview_index] = 0;
            $this->_grades_suppressed |= 4;
        }
    }

    /** @return list<GradeEntryConfig> */
    function visible_grades($pc_view = null) {
        if ($this->_grades_suppressed === 0) {
            $this->grades_vf(); // call the selection function
        }
        if (($this->_grades_suppressed & 4) === 0
            && ($pc_view ?? $this->pc_view)) {
            return $this->pset->visible_grades(true);
        } else {
            $f = $pc_view ?? $this->pc_view ? 2 : 1;
            $g = [];
            foreach ($this->pset->visible_grades(true) as $i => $ge) {
                if ($this->_grades_vf[$i] & $f)
                    $g[] = $ge;
            }
            return $g;
        }
    }

    /** @param string $key
     * return ?GradeEntryConfig */
    function gradelike_by_key($key) {
        $ge = $this->pset->gradelike_by_key($key);
        if ($ge && $ge->pcview_index !== null) {
            $f = $this->pc_view ? 2 : 1;
            return ($this->grades_vf())[$ge->pcview_index] & $f ? $ge : null;
        } else {
            return null;
        }
    }

    /** @return bool */
    function can_view_grade() {
        if ($this->_can_view_grade === null) {
            if ($this->viewer->isPC && $this->viewer->can_view_pset($this->pset)) {
                $this->_can_view_grade = true;
            } else if ($this->user === $this->viewer) {
                $this->_can_view_grade = $this->user_can_view_grade();
            } else {
                $this->_can_view_grade = false;
            }
        }
        return $this->_can_view_grade;
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

    private function set_user_can_view_grade() {
        $this->_user_can_view_grade = $this->_user_can_view_score = false;
        if ($this->pset->student_can_view()
            && ($this->pset->gitless_grades
                || ($this->repo && $this->user_can_view_repo_contents()))) {
            if (($this->pinned_scores_visible() ?? $this->pset->student_scores_visible())
                || $this->pset->student_answers_editable()) {
                foreach ($this->visible_grades(false) as $ge) {
                    if ($ge->answer || $ge->concealed) {
                        $this->_user_can_view_grade = true;
                    } else {
                        $this->_user_can_view_grade = $this->_user_can_view_score = true;
                        return;
                    }
                }
            }
        }
    }

    /** @return bool */
    function user_can_view_grade() {
        if ($this->_user_can_view_grade === null) {
            $this->set_user_can_view_grade();
        }
        return $this->_user_can_view_grade;
    }

    /** @return bool */
    function can_view_score() {
        return $this->pc_view || $this->user_can_view_score();
    }

    /** @return bool */
    function user_can_view_score() {
        if ($this->_user_can_view_score === null) {
            $this->set_user_can_view_grade();
        }
        return $this->_user_can_view_score;
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
            || ($gsv === 2 && $this->user_can_view_score())
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
        return $this->can_view_grade()
            && ($this->pc_view || $this->pset->student_answers_editable());
    }

    /** @return bool */
    function can_edit_scores() {
        return $this->can_view_grade() && $this->pc_view;
    }


    /** @return bool */
    function can_view_repo_contents($cached = false) {
        return $this->viewer->can_view_repo_contents($this->repo, $this->branch, $cached);
    }

    /** @return bool */
    function user_can_view_repo_contents($cached = false) {
        return $this->user->can_view_repo_contents($this->repo, $this->branch, $cached);
    }

    /** @return bool */
    function can_view_note_authors() {
        return $this->pc_view;
    }

    /** @return \Generator<GradeEntryConfig> */
    private function nonempty_visible_grades() {
        if ($this->can_view_grade()) {
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
        if ($this->pset->has_answers && $this->can_view_grade()) {
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
            $upi = $this->upi();
            return $upi ? $upi->gradercid : 0;
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
    private function student_timestamp($force) {
        if ($this->pset->gitless) {
            $upi = $this->_vupi ?? $this->upi();
            return $upi ? $upi->studentupdateat : null;
        } else if ($this->_hash
                   && ($rpi = $this->rpi())
                   && $rpi->gradehash === $this->_hash) {
            if (!$rpi->commitat
                && ($force || self::$forced_commitat < 60)
                && $this->repo->update_info()) {
                ++self::$forced_commitat;
                $cr = $this->grading_commit();
                $rpi->commitat = $cr ? $cr->commitat : 1;
            }
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
            $bhash = hex2bin($this->_hash);
            $rpi = $this->rpi();
            if ($rpi->gradebhash === $bhash && ($rpi->gradercid !== $gcid || $rpi->placeholder)) {
                $rpi->materialize($this->conf);
                $this->conf->qe("update RepositoryGrade set gradercid=?, placeholder=0 where repoid=? and branchid=? and pset=? and gradebhash=?",
                    $gcid, $rpi->repoid, $rpi->branchid, $rpi->pset, $bhash);
                $rpi->gradercid = $gcid;
                $rpi->placeholder = 0;
            }
            $this->update_commit_notes(["gradercid" => $gcid]);
        } else {
            throw new Error("change_grader with no hash");
        }
        $this->clear_can_view_grade();
    }

    function mark_grading_commit() {
        if ($this->pset->gitless_grades) {
            $upi = $this->upi();
            if (!$upi->gradercid) {
                $upi->materialize($this->conf);
                $this->conf->qe("update ContactGrade set gradercid=? where cid=? and pset=? and gradercid is null",
                    $this->viewer->contactId, $this->user->contactId, $this->pset->id);
                $upi->gradercid = $this->viewer->contactId;
                $this->clear_can_view_grade();
                $this->user->invalidate_grades($this->pset->id);
            }
        } else if ($this->_hash !== null) {
            $bhash = $this->bhash();
            $cn = $this->commit_jnotes();
            $gcid = $cn ? $cn->gradercid ?? null : null;
            $commit = $this->connected_commit($this->_hash);
            $rpi = $this->rpi();
            if ($rpi->gradebhash !== $bhash || $rpi->placeholder) {
                $rpi->materialize($this->conf);
                $this->conf->qe("update RepositoryGrade set gradebhash=?, commitat=?, gradercid=?, placeholder=0, emptydiff_at=null where repoid=? and branchid=? and pset=?",
                    $bhash, $commit ? $commit->commitat : null, $gcid ?? $rpi->gradercid,
                    $rpi->repoid, $rpi->branchid, $rpi->pset);
                $rpi->gradebhash = $bhash;
                $rpi->commitat = $commit ? $commit->commitat : null;
                $rpi->gradercid = $gcid ?? $rpi->gradercid;
                $rpi->placeholder = 0;
                $rpi->emptydiff_at = null;
                $this->clear_can_view_grade();
                $this->user->invalidate_grades($this->pset->id);
            }
        } else {
            throw new Error("mark_grading_commit with no hash");
        }
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


    /** @return RunLogger */
    function run_logger() {
        $this->_run_logger = $this->_run_logger ?? new RunLogger($this->pset, $this->repo);
        return $this->_run_logger;
    }

    /** @param RunnerConfig $runner
     * @return int|false */
    function complete_job(RunnerConfig $runner) {
        return $this->run_logger()->complete_job($runner, $this->hash());
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
        $runner->require && SiteLoader::require_includes($runner->require);
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

    /** @param string $base
     * @param ?array<string,null|int|string> $args
     * @return string */
    function hoturl_post($base, $args = null) {
        return $this->conf->hoturl_post($base, $this->hoturl_args($args));
    }


    const GRADEJSON_SLICE = 1;
    const GRADEJSON_OVERRIDE_VIEW = 2;
    const GRADEJSON_NO_LATE_HOURS = 4;
    const GRADEJSON_NO_FORMULAS = 8;
    const GRADEJSON_RECURSE = 16;
    const GRADEJSON_NO_EDITABLE_ANSWERS = 32;

    /** @param int $flags
     * @return ?GradeExport */
    function grade_export($flags = 0) {
        $override_view = ($flags & self::GRADEJSON_OVERRIDE_VIEW) !== 0;
        if (!$override_view && !$this->can_view_grade()) {
            return null;
        }

        if ($flags & self::GRADEJSON_SLICE) {
            $gexp = new GradeExport($this->pset, true);
            $gexp->slice = true;
        } else {
            $gexp = new GradeExport($this->pset, $override_view || $this->pc_view);
            $gexp->set_exported_entries($this->grades_vf());
        }
        if ($this->pset->grades_selection_function) {
            $gexp->set_visible_grades($this->visible_grades());
        }
        $gexp->uid = $this->user->contactId;
        $gexp->user = $this->user_linkpart();

        $this->ensure_grades();
        if ($this->_g !== null || $this->is_grading_commit()) {
            $this->grade_export_grades($gexp);
            $this->grade_export_linenotes($gexp);
        }
        if (!($flags & self::GRADEJSON_NO_FORMULAS)
            && $this->pset->has_formula) {
            $this->grade_export_formulas($gexp);
        }

        if (!$this->pset->gitless_grades && !$this->is_grading_commit()) {
            $gexp->grading_hash = $this->grading_hash();
        }
        if (!($flags & self::GRADEJSON_NO_LATE_HOURS)) {
            $this->grade_export_late_hours($gexp);
        }
        if ($this->pset->gitless_grades
            && ($xpi = $this->pset->gitless ? $this->upi() : $this->rpi())) {
            $gexp->version = $xpi->notesversion;
        }
        if (($ts = $this->student_timestamp(false))) {
            $gexp->student_timestamp = $ts;
        }
        if ($this->user_can_view_score()) {
            $gexp->user_scores_visible = true;
        }
        if ($this->can_edit_scores()) {
            $gexp->scores_editable = true;
        } else {
            $gexp->answers_editable = !$this->pset->frozen;
        }
        // maybe hide extra-credits that are missing
        if (!$gexp->pc_view) {
            $gexp->suppress_absent_extra();
        }
        return $gexp;
    }

    function grade_export_grades(GradeExport $gexp) {
        $this->ensure_grades();
        $gexp->grades = [];
        $gexp->autogrades = $this->_ag !== null ? [] : null;
        foreach ($gexp->value_entries() as $ge) {
            $gexp->grades[] = $this->_g !== null ? $this->_g[$ge->pcview_index] : null;
            if ($this->_ag !== null) {
                $gexp->autogrades[] = $this->_ag[$ge->pcview_index];
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
                        $gexp->grades = $gexp->grades ?? $gexp->blank_values();
                        $gexp->grades[$i] = $v;
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
        $r = ["uid" => $this->user->contactId, "user" => $this->user_linkpart()];
        if ($this->user_can_view_score()) {
            $r["user_scores_visible"] = true;
        }
        return $r;
    }


    /** @return LineNotesOrder */
    function empty_line_notes() {
        return new LineNotesOrder($this->can_view_grade(), $this->can_view_note_authors());
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
    private function _add_diffconfigs($diffs, $prio) {
        if (is_object($diffs)) {
            foreach (get_object_vars($diffs) as $k => $v) {
                $this->pset->add_diffconfig(new DiffConfig($v, $k, $prio));
            }
        } else if (is_array($diffs)) {
            foreach ($diffs as $v) {
                $this->pset->add_diffconfig(new DiffConfig($v, null, $prio));
            }
        }
    }

    /** @return array<string,DiffInfo> */
    function diff(CommitRecord $commita, CommitRecord $commitb,
                  LineNotesOrder $lnorder = null, $args = []) {
        if (!$this->added_diffinfo) {
            if (($tw = $this->commit_jnote("tabwidth"))) {
                $this->pset->add_diffconfig(new DiffConfig((object) ["tabwidth" => $tw], ".*", 101.0));
            }
            if (($diffs = $this->repository_jnote("diffs"))) {
                $this->_add_diffconfigs($diffs, 100.0);
            }
            if (($diffs = $this->commit_jnote("diffs"))) {
                $this->_add_diffconfigs($diffs, 101.0);
            }
            $this->added_diffinfo = true;
        }
        // both repos must be in the same directory; assume handout
        // is only potential problem
        if ($this->pset->is_handout($commita) !== $this->pset->is_handout($commitb)) {
            $this->conf->handout_repo($this->pset, $this->repo);
        }

        // obtain diff
        assert(!isset($args["needfiles"]));
        if ($lnorder) {
            $args["needfiles"] = $lnorder->fileorder();
        }
        $diff = $this->repo->diff($this->pset, $commita, $commitb, $args);

        // update `emptydiff_at`
        if (empty($diff)
            && !isset($args["onlyfiles"])
            && ($rpi = $this->rpi())
            && $commitb->hash === $rpi->gradehash
            && $commita === $this->base_handout_commit()
            && ($lhc = $this->pset->latest_handout_commit())) {
            $this->conf->ql("update RepositoryGrade set emptydiff_at=? where repoid=? and branchid=? and pset=?", $lhc->commitat, $this->repo->repoid, $this->branchid, $this->pset->id);
        }

        // expand diff to include all grade landmarks
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

        if ($lnorder) {
            $onlyfiles = Repository::fix_diff_files($args["onlyfiles"] ?? null);
            foreach ($lnorder->fileorder() as $fn => $order) {
                if (isset($diff[$fn])) {
                    // expand diff to include notes
                    $di = $diff[$fn];
                    foreach ($lnorder->file($fn) as $lineid => $note) {
                        if (!$di->contains_lineid($lineid)) {
                            $l = (int) substr($lineid, 1);
                            $di->expand_line($lineid[0], $l - 2, $l + 3);
                        }
                    }
                } else {
                    // expand diff to include fake files
                    if (($diffc = $this->pset->find_diffconfig($fn))
                        && $diffc->fileless
                        && (!$onlyfiles || ($onlyfiles[$fn] ?? null))) {
                        $diff[$fn] = $diffi = new DiffInfo($fn, $diffc);
                        foreach ($lnorder->file($fn) as $note) {
                            $diffi->add("Z", null, (int) substr($note->lineid, 1), "");
                        }
                        uasort($diff, "DiffInfo::compare");
                    }
                }
            }

            // add diff to linenotes
            $lnorder->set_diff($diff);
        }

        return $diff;
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
        if (($dinfo->hide_if_anonymous && $this->user->is_anonymous)
            || ($dinfo->is_empty() && $dinfo->loaded)) {
            return;
        }

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
                // NB Javascript depend on `h3 > a:first-child[href=#FILEID]`
                '<h3 class="pa-fileref"><a class="q ui pa-diff-unfold" href="#', $tabid, '"><span class="foldarrow">',
                ($expand && $dinfo->loaded ? "&#x25BC;" : "&#x25B6;"),
                "</span>";
            if ($args["diffcontext"] ?? false) {
                echo '<span class="pa-fileref-context">', $args["diffcontext"], '</span>';
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
                echo '<div class="hdr-actions btnbox">', join("", $bts), '</div>';
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
        if (!$this->user_can_view_grade()) {
            echo " hidegrades";
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
            echo '</div></div>';
            return;
        }
        echo '<div class="pa-notecontent">';
        $links = array();
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
                    if ($p->nicknameAmbiguous)
                        $autext[] = Text::name_html($p);
                    else
                        $autext[] = htmlspecialchars($p->nickname ? : $p->firstName);
                }
            }
            if (!empty($autext)) {
                echo '<div class="pa-note-author">[', join(", ", $autext), ']</div>';
            }
        }
        echo '<div class="pa-dr pa-note', ($note->iscomment ? ' pa-commentnote' : ' pa-gradenote');
        if (str_starts_with($note->ftext, "<")) {
            echo ' need-format';
            $this->need_format = true;
        } else {
            echo ' format0';
        }
        echo '">', htmlspecialchars($note->ftext), '</div>';
        echo '</div></div></div>';
    }

    static function echo_pa_sidebar_gradelist() {
        echo '<div class="pa-dg pa-with-sidebar"><div class="pa-sidebar">',
            '<div class="pa-gradebox pa-ps need-pa-gradelist"></div>',
            '</div><div class="pa-dg">';
    }
    static function echo_close_pa_sidebar_gradelist() {
        echo '</div></div>';
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
    /** @var ?list<GradeEntryConfig> */
    public $grade_entries;
    /** @var ?list<GradeEntryConfig> */
    public $grade_first;
    /** @var ?list<GradeEntryConfig> */
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
    /** @var ?list<GradeEntryConfig> */
    public $grade_first;

    /** @param string $file
     * @param string $diffid */
    function __construct($file, $diffid) {
        $this->file = $file;
        $this->diffid = $diffid;
    }
}
