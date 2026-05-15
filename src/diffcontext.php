<?php
// diffcontext.php -- Peteramati helper class for a multi-file diff
// Peteramati is Copyright (c) 2013-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class DiffContext {
    /** @var Repository
     * @readonly */
    public $repo;
    /** @var Pset
     * @readonly */
    public $pset;
    /** @var CommitRecord
     * @readonly */
    public $commita;
    /** @var CommitRecord
     * @readonly */
    public $commitb;
    /** @var string
     * @readonly */
    private $directory;
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
    /** @var int */
    private $_flags = 0;

    const F_BARE_DIRECTORY = 1;
    const F_HANDOUTA = 2;
    const F_HANDOUTB = 4;
    const F_UNDIRECTORIED = 8;
    const FM_BARE_HANDOUT = 7;
    const FM_BARE_HANDOUTA = 3;
    const FM_BARE_HANDOUTB = 5;

    function __construct(PsetView $info, CommitRecord $commita, CommitRecord $commitb) {
        $this->repo = $info->repo;
        $this->pset = $info->pset;
        $this->directory = $info->directory;
        if ($this->directory !== ""
            && $this->repo->bare_directory($this->pset)) {
            $this->_flags |= self::F_BARE_DIRECTORY;
        }
        $this->set_commita($commita);
        $this->set_commitb($commitb);
    }

    /** @param CommitRecord $cr
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_commita($cr) {
        $this->commita = $cr;
        $this->_set_flags(self::F_HANDOUTA, $cr->is_handout($this->pset) ? self::F_HANDOUTA : 0);
        return $this;
    }

    /** @param CommitRecord $cr
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_commitb($cr) {
        $this->commitb = $cr;
        $this->_set_flags(self::F_HANDOUTB, $cr->is_handout($this->pset) ? self::F_HANDOUTB : 0);
        return $this;
    }

    private function _set_flags($clearf, $setf) {
        $this->_flags = ($this->_flags & ~$clearf & ~self::F_UNDIRECTORIED) | $setf;
        if (($this->_flags & self::FM_BARE_HANDOUT) === self::FM_BARE_HANDOUTA
            || ($this->_flags & self::FM_BARE_HANDOUT) === self::FM_BARE_HANDOUTB) {
            $this->_flags |= self::F_UNDIRECTORIED;
        }
    }

    /** @return string */
    function repo_hasha() {
        if (($this->_flags & self::FM_BARE_HANDOUT) === self::FM_BARE_HANDOUTA) {
            return $this->repo->undirectoried_hash($this->pset, $this->commita->hash);
        }
        return $this->commita->hash;
    }

    /** @return string */
    function repo_hashb() {
        if (($this->_flags & self::FM_BARE_HANDOUT) === self::FM_BARE_HANDOUTB) {
            return $this->repo->undirectoried_hash($this->pset, $this->commitb->hash);
        }
        return $this->commitb->hash;
    }

    /** @return string */
    function repo_directory() {
        if ($this->_flags & self::F_UNDIRECTORIED) {
            return "";
        }
        return $this->directory;
    }

    /** @return string */
    function pset_to_repo_file($file) {
        if ($this->_flags & self::F_UNDIRECTORIED) {
            return substr($file, strlen($this->pset->directory_slash));
        }
        return $file;
    }

    /** @return string */
    function repo_to_pset_file($file) {
        if ($this->_flags & self::F_UNDIRECTORIED) {
            return $this->pset->directory_slash . $file;
        }
        return $file;
    }

    /** @return bool */
    function undirectoried() {
        return ($this->_flags & self::F_UNDIRECTORIED) !== 0;
    }

    /** @return bool */
    function commita_is_handout() {
        return ($this->_flags & self::F_HANDOUTA) !== 0;
    }

    /** @return bool */
    function commitb_is_handout() {
        return ($this->_flags & self::F_HANDOUTB) !== 0;
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
