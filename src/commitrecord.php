<?php
// commitrecord.php -- Peteramati helper class representing commits
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
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
    /** @var ?bool */
    public $_is_handout;
    /** @var ?Pset */
    public $_is_handout_pset;
    /** @var ?bool */
    public $_is_merge;
    /** @var ?bool */
    public $_is_trivial_merge;
    const HANDOUTHEAD = "*handout*";
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
    function jsonSerialize() {
        return $this->hash;
    }
}
