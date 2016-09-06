<?php
// diffinfo.php -- Peteramati class encapsulating diffs for a file
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class DiffInfo {
    public $filename;
    public $binary = false;
    public $truncated = false;
    public $boring = false;
    public $priority = 0;
    public $removed = false;
    public $diff;

    const MAXLINES = 16384;

    public function __construct($fname, &$diff, $diffinfo, $blineno) {
        $this->filename = $fname;
        if (count($diff) > self::MAXLINES) {
            $diff[self::MAXLINES][0] = "+";
            $diff[self::MAXLINES][3] = "*** OUTPUT TRUNCATED ***";
            // array_slice($diff, 0, self::REPO_DIFF_MAXLEN + 1) -- not needed in current organization
            $this->truncated = true;
        }
        if (count($diff) == 1 && substr($diff[0][3], 0, 1) === "B")
            $this->binary = true;
        if ($diffinfo && $diffinfo->boring)
            $this->boring = $diffinfo->boring;
        else if ((!$diffinfo || $diffinfo->boring === null)
                 && $this->binary)
            $this->boring = true;
        if ($diffinfo && $diffinfo->priority)
            $this->priority = $diffinfo->priority;
        if ($blineno === 0
            || ($this->binary
                && preg_match('_ and /dev/null differ$_', $diff[0][3])))
            $this->removed = true;
        $this->diff = $diff;
        $diff = null;
    }
}