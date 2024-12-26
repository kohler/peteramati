<?php
// diffcontext.php -- Peteramati helper class for a multi-file diff
// Peteramati is Copyright (c) 2013-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class DiffContext {
    /** @var Repository */
    public $repo;
    /** @var Pset */
    public $pset;
    /** @var CommitRecord */
    public $commita;
    /** @var CommitRecord */
    public $commitb;
    /** @var string */
    public $hasha;
    /** @var string */
    public $hashb;
    /** @var string */
    public $repodir = "";
    /** @var string */
    public $truncpfx = "";
    /** @var bool */
    public $wdiff = false;
    /** @var bool */
    public $no_full = false;
    /** @var bool */
    public $no_user_collapse = false;
    /** @var ?array<string,true> */
    public $need_files;
    /** @var ?array<string,true> */
    public $only_files;
    /** @var ?LineNotesOrder */
    public $lnorder;


    function __construct(Repository $repo, Pset $pset,
                         CommitRecord $commita, CommitRecord $commitb) {
        $this->repo = $repo;
        $this->pset = $pset;
        if ($pset->directory_noslash !== "") {
            if ($repo->truncated_psetdir($pset)) {
                $this->truncpfx = $pset->directory_noslash . "/";
            } else {
                $this->repodir = $pset->directory_noslash . "/";
            }
        }
        $this->set_commita($commita);
        $this->set_commitb($commitb);
    }

    /** @param CommitRecord $cr
     * @return $this */
    function set_commita($cr) {
        $this->commita = $cr;
        $this->hasha = $cr->hash;
        if ($this->commita && $this->commitb && $this->truncpfx) {
            $this->set_truncated_hashes();
        }
        return $this;
    }

    /** @param CommitRecord $cr
     * @return $this */
    function set_commitb($cr) {
        $this->commitb = $cr;
        $this->hashb = $cr->hash;
        if ($this->commita && $this->commitb && $this->truncpfx) {
            $this->set_truncated_hashes();
        }
        return $this;
    }

    private function set_truncated_hashes() {
        $ha = $this->commita->is_handout($this->pset);
        $hb = $this->commitb->is_handout($this->pset);
        if ($ha && !$hb) {
            $this->hasha = $this->repo->truncated_hash($this->pset, $this->commita->hash);
        } else if (!$ha && $hb) {
            $this->hashb = $this->repo->truncated_hash($this->pset, $this->commitb->hash);
        }
    }

    /** @param null|string|list<string>|array<string,true> $files
     * @return $this */
    function set_required_files($files) {
        $this->need_files = Repository::fix_diff_files($files);
        return $this;
    }

    /** @param string $file
     * @return $this */
    function add_required_file($file) {
        $this->need_files[$file] = true;
        return $this;
    }

    /** @param null|string|list<string>|array<string,true> $files
     * @return $this */
    function set_allowed_files($files) {
        $this->only_files = Repository::fix_diff_files($files);
        return $this;
    }

    /** @param string $file
     * @return $this */
    function add_allowed_file($file) {
        $this->only_files[$file] = true;
        return $this;
    }

    /** @param string $file
     * @return bool */
    function file_allowed($file) {
        return $this->only_files === null || ($this->only_files[$file] ?? false);
    }

    /** @param string $file
     * @return bool */
    function file_required($file) {
        return $this->need_files !== null && ($this->need_files[$file] ?? false);
    }
}
