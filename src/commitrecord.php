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
        }
        return in_array($dir, $this->directory);
    }
    /** @param string $dir_noslash
     * @return bool */
    function __touches_directory($dir_noslash) {
        return $this->directory === $dir_noslash
            || (is_array($this->directory) && in_array($dir_noslash, $this->directory));
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

    /** @param ?string $s
     * @return ?non-empty-string */
    static function parse_hashpart($s, $full = false) {
        if ($s === null || $s === "") {
            return null;
        }
        $s = strtolower($s);
        // NB all special hashparts are not ctype_xdigit
        if (($full
             ? strlen($s) === 40 || strlen($s) === 64
             : strlen($s) >= 5)
            && ctype_xdigit($s)) {
            return $s;
        } else if ($s === "handout" || $s === "base") {
            return "handout";
        } else if ($s === "latest" || $s === "head") {
            return "latest";
        } else if ($s === "grading" || $s === "grade") {
            return "grading";
        }
        return null;
    }

    /** @param ?string $s
     * @return ?non-empty-string */
    static function parse_userpart($s, $explicit = false) {
        if ($s === null || $s === "") {
            return null;
        } else if (strlen($s) > 1 && ($s[0] === "@" || $s[0] === "~")) {
            return substr($s, 1);
        } else if (!$explicit) {
            return $s;
        }
        return null;
    }
}
