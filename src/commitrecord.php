<?php
// commitrecord.php -- Peteramati helper class representing commits
// Peteramati is Copyright (c) 2013-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class CommitRecord implements JsonSerializable {
    /** @var int */
    public $commitat;
    /** @var non-empty-string */
    public $hash;
    /** @var string */
    public $subject;
    /** @var ?string */
    public $fromhead;
    /** @var null|string|list<string> */
    public $directory;
    /** @var int */
    public $_flags = 0;
    /** @var ?Pset */
    public $_is_handout_pset;

    const HANDOUTHEAD = "*handout*";
    const CRF_IS_HANDOUT = 1;
    const CRF_IS_MERGE = 2;
    const CRF_IS_TRIVIAL_MERGE = 4;

    /** @param int $commitat
     * @param non-empty-string $hash
     * @param string $subject
     * @param ?string $fromhead */
    function __construct($commitat, $hash, $subject, $fromhead = null) {
        $this->commitat = $commitat;
        $this->hash = $hash;
        $this->subject = $subject;
        $this->fromhead = $fromhead;
    }
    /** @param string $dir
     * @return bool */
    function touches_directory($dir) {
        if (str_ends_with($dir, "/")) {
            $dir = substr($dir, 0, -1);
        }
        if ($dir === "") {
            return true;
        } else if ($this->directory === null) {
            return false;
        } else if (is_string($this->directory)) {
            return $this->directory === $dir;
        } else {
            return in_array($dir, $this->directory);
        }
    }
    /** @return bool */
    function is_merge() {
        return ($this->_flags & self::CRF_IS_MERGE) !== 0;
    }
    /** @return bool */
    function is_trivial_merge() {
        return ($this->_flags & self::CRF_IS_TRIVIAL_MERGE) !== 0;
    }
    /** @param Pset $pset
     * @return bool */
    function is_handout($pset) {
        if ($this->_is_handout_pset !== $pset) {
            $this->_is_handout_pset = $pset;
            if ($pset->handout_commit($this->hash)) {
                $this->_flags |= self::CRF_IS_HANDOUT;
            } else {
                $this->_flags &= ~self::CRF_IS_HANDOUT;
            }
        }
        return ($this->_flags & self::CRF_IS_HANDOUT) !== 0;
    }
    function jsonSerialize(): string {
        return $this->hash;
    }
    /** @param ?string $hashpart
     * @return ?non-empty-string */
    static function canonicalize_hashpart($hashpart) {
        if ($hashpart === null || $hashpart === "") {
            return null;
        }
        $hashpart = strtolower($hashpart);
        // NB all special hashparts are not ctype_xdigit
        if ($hashpart === "handout" || $hashpart === "base") {
            return "handout";
        } else if ($hashpart === "latest" || $hashpart === "head") {
            return "latest";
        } else if ($hashpart === "grading" || $hashpart === "grade") {
            return "grading";
        } else if (strlen($hashpart) >= 5 && ctype_xdigit($hashpart)) {
            return $hashpart;
        } else {
            return null;
        }
    }
}
