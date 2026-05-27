<?php
// diffinfo.php -- Peteramati class encapsulating diffs for a file
// HotCRP and Peteramati are Copyright (c) 2006-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

final class DiffInfo implements Iterator {
    /** @var string
     * @readonly */
    public $filename;
    /** @var int */
    private $_filenamepos;
    /** @var ?string */
    public $title;
    /** @var int */
    public $tabwidth = 4;
    /** @var bool */
    public $wdiff = false;
    /** @var ?string */
    public $language;
    /** @var float */
    private $order = 0.0;
    /** @var float */
    private $extension_order = 0.0;
    /** @var list<int|string|null> */
    private $_diff = [];
    /** @var int */
    private $_diffsz = 0;
    /** @var ?array<int,int> */
    private $_dflags;
    /** @var int */
    private $_flags = 0;
    /** @var int */
    private $_known_flags = 0;
    /** @var int */
    private $_max_lineno = 0;
    /** @var int */
    private $_widthcode = 0;
    /** @var int */
    private $_itpos = 0;
    /** @var DiffContext */
    private $_dctx;

    const MAXLINES = 8000;
    const MAXDIFFSZ = self::MAXLINES << 2;

    /** @param string $filename */
    function __construct($filename, ?DiffConfig $diffconfig, DiffContext $dctx) {
        $this->filename = $filename;
        $this->_filenamepos = (int) strrpos($filename, "/");
        $ismd = str_ends_with($filename, ".md");
        if ($diffconfig) {
            $this->title = $diffconfig->title;
            $this->_flags |= $diffconfig->flags;
            $this->_known_flags |= $diffconfig->known_flags;
            $this->order = (float) $diffconfig->order;
            $this->extension_order = (float) $diffconfig->extension_order;
            $this->language = $diffconfig->language;
            $this->tabwidth = $diffconfig->tabwidth ?? 4;
        }
        if ($ismd) {
            if (($this->_known_flags & DiffConfig::F_MARKDOWN) === 0) {
                $this->_flags |= DiffConfig::F_MARKDOWN;
            }
            if (($this->_known_flags & DiffConfig::F_MARKDOWN_ALLOWED) === 0) {
                $this->_flags |= DiffConfig::F_MARKDOWN_ALLOWED;
            }
        }
        $this->_dctx = $dctx;
        $this->wdiff = $dctx->wdiff;
    }

    /** @param bool $wdiff */
    function set_wdiff($wdiff) {
        $this->wdiff = $wdiff;
    }

    /** @param ?bool $collapse */
    function set_collapse($collapse) {
        $this->_known_flags &= ~DiffConfig::F_COLLAPSE;
        if ($collapse !== null) {
            $this->_known_flags |= DiffConfig::F_COLLAPSE;
        }
        $this->_flags &= ~DiffConfig::F_COLLAPSE;
        if ($collapse) {
            $this->_flags |= DiffConfig::F_COLLAPSE;
        }
    }

    function set_full_contentb(RepositoryFileContent $rfc) {
        assert(empty($this->_diff));
        foreach ($rfc->lines as $i => $line) {
            $this->add("+", 0, $i + 1, $line);
        }
        if (($rfc->flags & DiffConfig::LINE_NONL) !== 0) {
            $this->set_ends_without_newline();
        }
    }

    /** @return string */
    function repo_filename() {
        return $this->_dctx->pset_to_repo_file($this->filename);
    }

    /** @param 'a'|'b' $side
     * @return string */
    function repo_hash($side) {
        return $side === "a" ? $this->_dctx->repo_hasha() : $this->_dctx->repo_hashb();
    }

    /** @return list<null|string|int> $diff */
    function __diff() {
        return $this->_diff;
    }


    /** @param string $ch
     * @param ?int $linea
     * @param ?int $lineb
     * @param string $text */
    function add($ch, $linea, $lineb, $text) {
        if ($ch === "-") {
            $this->_flags |= DiffConfig::F_HAS_DELETION;
        } else if ($ch === "+") {
            $this->_flags |= DiffConfig::F_HAS_INSERTION;
        }
        if ($this->_flags & DiffConfig::F_TRUNCATED) {
            /* do nothing */
        } else if ($this->_diffsz === self::MAXDIFFSZ) {
            array_push($this->_diff, "+", $linea, $lineb, "*** OUTPUT TRUNCATED ***");
            $this->_flags |= DiffConfig::F_TRUNCATED | DiffConfig::F_GAP;
            $this->_diffsz += 4;
        } else {
            array_push($this->_diff, $ch, $linea, $lineb, $text);
            $this->_diffsz += 4;
            if ($ch === "@"
                && ($this->_diffsz > 4
                    || !preg_match('/\A@@ -[01],\d++ \+[01],/', $text))) {
                $this->_flags |= DiffConfig::F_GAP;
            }
            if ($linea !== null && $linea > $this->_max_lineno) {
                $this->_max_lineno = $linea;
            }
            if ($lineb !== null && $lineb > $this->_max_lineno) {
                $this->_max_lineno = $lineb;
            }
        }
    }

    function set_ends_without_newline() {
        $di = $this->_diffsz - 4;
        assert($di >= 0);
        if ($this->_dflags === null) {
            $this->_dflags = [];
        }
        if (!isset($this->_dflags[$di])) {
            $this->_dflags[$di] = 0;
        }
        $this->_dflags[$di] |= DiffConfig::LINE_NONL;
    }

    function finish() {
        $n = $this->_diffsz;
        if ($n === 4 && str_starts_with($this->_diff[3], "B")) {
            $this->_flags |= DiffConfig::F_BINARY;
        }
        if (($this->_flags & DiffConfig::F_BINARY) !== 0
            && ($this->_known_flags & DiffConfig::F_COLLAPSE) === 0) {
            $this->_flags |= DiffConfig::F_COLLAPSE;
        }
        if ($this->_max_lineno >= 10000) {
            $this->_widthcode = 0x55;
        } else if ($this->_max_lineno >= 1000) {
            $this->_widthcode = 0x44;
        } else {
            $this->_widthcode = 0x33;
        }
        if (($this->_flags & DiffConfig::F_BINARY) !== 0
            ? str_contains($this->_diff[3], " and /dev/null differ")
            : $n >= 4 && $this->_diff[$n - 2] === 0) {
            $this->_flags |= DiffConfig::F_FILE_DELETED;
            $this->_widthcode &= 0xF0;
        } else if (($this->_flags & DiffConfig::F_BINARY) !== 0
                   ? str_contains($this->_diff[3], "/dev/null and ")
                   : $n >= 4 && $this->_diff[$n - 3] === 0) {
            $this->_flags |= DiffConfig::F_FILE_INSERTED;
            $this->_widthcode &= 0x0F;
        }
        // add `@@` context line at end of diff to allow expanding file
        if ($n >= 16
            && $this->_diff[$n - 4] === ' '
            && $this->_diff[$n - 8] === ' '
            && $this->_diff[$n - 12] === ' ') {
            array_push($this->_diff, "@", null, null, "");
            $this->_diffsz += 4;
        }
    }

    function finish_unloaded() {
        $this->finish();
        $this->_flags |= DiffConfig::F_UNLOADED | DiffConfig::F_COLLAPSE;
    }


    /** @return bool */
    function is_empty() {
        return $this->_diffsz === 0;
    }

    /** @return bool */
    function ignore() {
        return ($this->_flags & DiffConfig::F_IGNORE) !== 0;
    }

    /** @return bool */
    function collapse() {
        return ($this->_flags & DiffConfig::F_COLLAPSE) !== 0;
    }

    /** @return bool */
    function truncated() {
        return ($this->_flags & DiffConfig::F_TRUNCATED) !== 0;
    }

    /** @return bool */
    function fileless() {
        return ($this->_flags & DiffConfig::F_FILELESS) !== 0;
    }

    /** @return bool */
    function file_deleted() {
        return ($this->_flags & DiffConfig::F_FILE_DELETED) !== 0;
    }

    /** @return bool */
    function file_inserted() {
        return ($this->_flags & DiffConfig::F_FILE_INSERTED) !== 0;
    }

    /** @return bool */
    function loaded() {
        return ($this->_flags & DiffConfig::F_UNLOADED) === 0;
    }

    /** @return bool */
    function highlight() {
        return ($this->_flags & DiffConfig::F_HIGHLIGHT) !== 0;
    }

    /** @return bool */
    function markdown() {
        return ($this->_flags & DiffConfig::F_MARKDOWN) !== 0;
    }

    /** @return bool */
    function markdown_allowed() {
        return ($this->_flags & DiffConfig::F_MARKDOWN_ALLOWED) !== 0;
    }

    /** @return bool */
    function hide_if_anonymous() {
        return ($this->_flags & DiffConfig::F_HIDE_IF_ANONYMOUS) !== 0;
    }

    /** @return bool */
    function has_deletion() {
        return ($this->_flags & DiffConfig::F_HAS_DELETION) !== 0;
    }

    /** @return bool */
    function has_insertion() {
        return ($this->_flags & DiffConfig::F_HAS_INSERTION) !== 0;
    }

    /** @return bool */
    function commita_is_handout() {
        return $this->_dctx->commita_is_handout();
    }

    /** @return int */
    function max_lineno() {
        return $this->_max_lineno;
    }

    /** @return int */
    function widthcode() {
        return $this->_widthcode;
    }

    /** @param int $i
     * @return ?array{string,?int,?int,string} */
    function entry($i) {
        if ($i >= 0 && $i < ($this->_diffsz >> 2)) {
            return array_slice($this->_diff, $i << 2, 4);
        }
        return null;
    }

    /** @param int $off
     * @param int $line
     * @return int */
    private function line_lower_bound($off, $line) {
        $l = 0;
        $r = $this->_diffsz;
        while ($l < $r) {
            $m0 = $m = $l + ((($r - $l) >> 2) & ~3);
            while ($m + 4 < $r && $this->_diff[$m + $off] === null) {
                $m += 4;
            }
            $ln = $this->_diff[$m + $off];
            if ($ln === null || $ln >= $line) {
                $r = $m0;
            } else {
                $l = $m + 4;
            }
        }
        return $l;
    }

    /** @param int $linea
     * @return bool */
    function contains_linea($linea) {
        $l = $this->line_lower_bound(1, $linea);
        return $l < $this->_diffsz && $this->_diff[$l] !== "@";
    }

    /** @param string $lineid
     * @return ?int */
    function linea_for($lineid) {
        if ($lineid[0] === "a") {
            return (int) substr($lineid, 1);
        } else if ($lineid[0] === "b") {
            $lineb = (int) substr($lineid, 1);
            if (($l = $this->line_lower_bound(2, $lineb)) < $this->_diffsz) {
                return $this->_diff[$l + 1] + ($lineb - $this->_diff[$l + 2]);
            }
        }
        return null;
    }

    /** @param string $lineid
     * @return bool */
    function contains_lineid($lineid) {
        assert($lineid[0] === "a" || $lineid[0] === "b");
        $l = $this->line_lower_bound($lineid[0] === "a" ? 1 : 2, (int) substr($lineid, 1));
        return $l < $this->_diffsz && $this->_diff[$l] !== "@";
    }

    /** @param string $a
     * @param string $b
     * @return int */
    function compare_lineid($a, $b) {
        $oa = $a[0] === "a" ? 1 : 2;
        $ob = $b[0] === "a" ? 1 : 2;
        $na = (int) substr($a, 1);
        $nb = (int) substr($b, 1);
        if ($oa === $ob) {
            return $na - $nb;
        } else {
            $la = $this->line_lower_bound($oa, $na);
            $lb = $this->line_lower_bound($ob, $nb);
            if ($la !== $lb) {
                return $la < $lb ? -1 : 1;
            } else if ($la < $this->_diffsz) {
                $da = $na - $this->_diff[$oa];
                $db = $nb - $this->_diff[$ob];
                if ($da !== $db) {
                    return $da < $db ? -1 : 1;
                }
            }
            return $ob - $oa;
        }
    }

    private function fix_context($pos) {
        $lx = $rx = $pos;
        while ($lx >= 0 && $this->_diff[$lx] !== "@") {
            $lx -= 4;
        }
        $rx = max($rx, $lx + 4);
        while ($rx < $this->_diffsz && $this->_diff[$rx] !== "@") {
            $rx += 4;
        }
        if ($lx >= 0 && $rx < $this->_diffsz) {
            $lnal = $this->_diff[$lx + 5];
            $lnbl = $this->_diff[$lx + 6];
            $ch = $this->_diff[$rx - 4];
            $lnar = $this->_diff[$rx - 3] + ($ch === " " || $ch === "-" ? 1 : 0);
            $lnbr = $this->_diff[$rx - 2] + ($ch === " " || $ch === "+" ? 1 : 0);
            $this->_diff[$lx + 3] = preg_replace('/^@@[-+,\d ]*@@.*/', "@@ -{$lnal}," . ($lnar - $lnal) . " +{$lnbl}," . ($lnbr - $lnbl) . " @@", $this->_diff[$lx + 3]);
        }
    }

    /** @param int $linea_lx
     * @param int $linea_rx
     * @return bool */
    function expand_linea($linea_lx, $linea_rx) {
        return $this->expand_line(1, $linea_lx, $linea_rx);
    }

    /** @param int $lineb_lx
     * @param int $lineb_rx
     * @return bool */
    function expand_lineb($lineb_lx, $lineb_rx) {
        return $this->expand_line(2, $lineb_lx, $lineb_rx);
    }

    /** @param 'a'|'b'|1|2 $off
     * @param int $line_lx
     * @param int $line_rx
     * @return bool */
    function expand_line($off, $line_lx, $line_rx) {
        if ($off === "a" || $off === "b") {
            $off = ($off === "a" ? 1 : 2);
        }
        assert($off === 1 || $off === 2);

        // look for left-hand edge of desired region
        $line_lx = max(1, $line_lx);
        $l = $this->line_lower_bound($off, $line_lx);

        // advance to hole
        while ($l < $this->_diffsz && $this->_diff[$l] !== "@") {
            if ($this->_diff[$l + $off] === $line_lx) {
                ++$line_lx;
                if ($line_lx === $line_rx)
                    return true;
            }
            $l += 4;
        }
        assert($l === $this->_diffsz || $this->_diff[$l] === "@");
        assert($l === 0 || $this->_diff[$l - 3] !== null);

        // if we get here, we need to insert
        // XXX assert(we are returning context lines)
        if (!$this->_dctx->repo) {
            return false;
        }

        // expand $l to [$lx, $rx]: need line numbers
        $lx = $rx = $l;
        while ($lx >= 0 && $lx < $this->_diffsz && $this->_diff[$lx + 1] === null) {
            $lx -= 4;
        }
        while ($rx < $this->_diffsz && $this->_diff[$rx + 1] === null) {
            $rx += 4;
        }

        // $deltab translates old numbers to new line numbers;
        // shift to old line numbers
        $deltab = 0;
        if ($lx >= 0 && $lx < $this->_diffsz) {
            $deltab = $this->_diff[$lx + 2] - $this->_diff[$lx + 1];
        } else if ($lx === $this->_diffsz && $this->_diffsz > 0) {
            assert($this->_diff[$lx - 3] !== null);
            $deltab = $this->_diff[$lx - 2] - $this->_diff[$lx - 3];
        }
        if ($off === 2) {
            $line_lx -= $deltab;
            $line_rx -= $deltab;
        }

        // load old content
        $rfc = $this->_dctx->repo->file_content($this->_dctx->repo_hasha(), $this->repo_filename());
        if ($line_lx > count($rfc->lines)) {
            return true;
        }
        $line_rx = min(count($rfc->lines), $line_rx);

        $splice = [];
        if ($lx >= 0 && $lx < $this->_diffsz
            && $this->_diff[$lx + 1] >= $line_lx - 1
            && $rx < $this->_diffsz && $this->_diff[$rx + 1] <= $line_rx) {
            $line_rx = $this->_diff[$rx + 1];
            for ($i = $this->_diff[$lx + 1] + 1; $i < $line_rx; ++$i) {
                array_push($splice, " ", $i, $i + $deltab, $rfc->lines[$i - 1]);
            }
            array_splice($this->_diff, $lx + 4, $rx - $lx - 4, $splice);
            $this->fix_context($lx);
        } else if ($lx >= 0 && $lx < $this->_diffsz
                   && $this->_diff[$lx + 1] >= $line_lx - 1) {
            for ($i = $this->_diff[$lx + 1] + 1; $i < $line_rx; ++$i) {
                array_push($splice, " ", $i, $i + $deltab, $rfc->lines[$i - 1]);
            }
            array_splice($this->_diff, $lx + 4, 0, $splice);
            $this->fix_context($lx);
        } else if ($rx < $this->_diffsz && $this->_diff[$rx + 1] <= $line_rx) {
            $line_rx = $this->_diff[$rx + 1];
            for ($i = $line_lx; $i < $line_rx; ++$i) {
                array_push($splice, " ", $i, $i + $deltab, $rfc->lines[$i - 1]);
            }
            array_splice($this->_diff, $rx, 0, $splice);
            $this->fix_context($rx);
        } else {
            $linecount = $line_rx - $line_lx;
            array_push($splice, "@", null, null, "@@ -{$line_lx},{$linecount} +" . ($line_lx + $deltab) . ",{$linecount} @@");
            for ($i = $line_lx; $i < $line_rx; ++$i) {
                array_push($splice, " ", $i, $i + $deltab, $rfc->lines[$i - 1]);
            }
            array_splice($this->_diff, $l, 0, $splice);
        }
        $this->_diffsz = count($this->_diff);

        // add or remove last context line if appropriate
        if ($line_rx === count($rfc->lines)) {
            if ($this->_diffsz > 0
                && $this->_diff[$this->_diffsz - 4] === "@") {
                $this->_diffsz -= 4;
                array_splice($this->_diff, $this->_diffsz, 4);
            }
            if (($rfc->flags & DiffConfig::LINE_NONL) !== 0) {
                $this->set_ends_without_newline();
            }
        } else {
            if ($this->_diffsz === 0
                || $this->_diff[$this->_diffsz - 4] !== "@") {
                array_push($this->_diff, "@", null, null, "");
                $this->_diffsz += 4;
            }
        }

        return true;
    }

    /** @param int $linea_lx
     * @param int $linea_rx
     * @return DiffInfo */
    function restrict_linea($linea_lx, $linea_rx) {
        $l = $this->line_lower_bound(1, $linea_lx);
        $r = $this->line_lower_bound(1, $linea_rx);
        while ($l < $r && $this->_diff[$l] === "+") {
            $l += 4;
        }
        while ($r < $this->_diffsz
               && ($this->_diff[$r] === "+"
                   || ($this->_diff[$r] === "-" && $this->_diff[$r + 1] <= $linea_rx))) {
            $r += 4;
        }
        $c = clone $this;
        if ($l > 0
            && $l < $this->_diffsz
            && $this->_diff[$l] !== "@") {
            $c->_diff = array_slice($this->_diff, $l - 4, $r - $l + 4);
            $c->_diffsz = $r - $l + 4;
            $c->_diff[0] = "@";
            $c->_diff[1] = $c->_diff[2] = null;
            $c->_diff[3] = "@@ @@";
            $c->fix_context(0);
        } else {
            $c->_diff = array_slice($this->_diff, $l, $r - $l);
            $c->_diffsz = $r - $l;
        }
        if ($r < $this->_diffsz) {
            array_push($c->_diff, "@", null, null, "");
            $c->_diffsz += 4;
            $c->fix_context($c->_diffsz - 4);
        }
        return $c;
    }


    /** @return float */
    private function diffconfig_order($pfxlen) {
        if ($pfxlen === strlen($this->filename)) {
            return $this->order;
        } else if (($dc = $this->_dctx->pset->find_diffconfig(substr($this->filename, 0, $pfxlen)))) {
            return (float) $dc->order;
        }
        return 0.0;
    }

    /** @param DiffInfo $a
     * @param DiffInfo $b
     * @return int */
    static function compare($a, $b) {
        // if files are in different directories, compare the shallowest different component
        if ($a->_filenamepos !== $b->_filenamepos
            || substr_compare($a->filename, $b->filename, 0, $a->_filenamepos) !== 0) {
            $pfxlen = 0;
            while (($slash = strpos($a->filename, "/", $pfxlen)) !== false
                   && substr_compare($a->filename, $b->filename, 0, $slash + 1) === 0) {
                $pfxlen = $slash + 1;
            }
            $slasha = strlpos($a->filename, "/", $pfxlen);
            $aorder = $a->diffconfig_order($slasha);
            $slashb = strlpos($b->filename, "/", $pfxlen);
            $border = $b->diffconfig_order($slashb);
            if ($aorder != $border) {
                return $aorder < $border ? -1 : 1;
            }
            return strcmp(substr($a->filename, 0, $slasha), substr($b->filename, 0, $slashb));
        }

        // otherwise, files are in the same directory; compare order and extension order
        if ($a->order != $b->order) {
            return $a->order < $b->order ? -1 : 1;
        }
        if ($a->extension_order != $b->extension_order
            && ($adot = strrpos($a->filename, ".", $a->_filenamepos)) !== false
            && substr_compare($a->filename, $b->filename, 0, $adot + 1) === 0
            && strpos($b->filename, ".", $adot + 1) === false) {
            return $a->extension_order < $b->extension_order ? -1 : 1;
        }
        return strcmp($a->filename, $b->filename);
    }


    /** @return array{string,?int,?int,string,?int} */
    function current(): array {
        $x = array_slice($this->_diff, $this->_itpos, 4);
        if ($this->_dflags !== null && isset($this->_dflags[$this->_itpos])) {
            $x[] = $this->_dflags[$this->_itpos];
        }
        return $x;
    }
    /** @return int */
    function key(): int {
        return $this->_itpos >> 2;
    }
    /** @return void */
    function next(): void {
        $this->_itpos += 4;
    }
    /** @return void */
    function rewind(): void {
        $this->_itpos = 0;
    }
    /** @return bool */
    function valid(): bool {
        return $this->_itpos < $this->_diffsz;
    }
    /** @return string */
    function current_expandmark() {
        assert($this->_diff[$this->_itpos] === "@");
        if ($this->_itpos === 0
            && ($this->_diffsz === 4 || $this->_diff[4] !== " ")) {
            // fully deleted or inserted
            return "";
        } else if ($this->_itpos === 0) {
            $la = $lb = 1;
        } else {
            $la = $this->_diff[$this->_itpos - 3] + 1;
            $lb = $this->_diff[$this->_itpos - 2] + 1;
        }
        assert($la !== null && $lb !== null);
        if ($this->_itpos + 4 === $this->_diffsz) {
            return "a{$la}b{$lb}+";
        } else {
            $n = $this->_diff[$this->_itpos + 6] - $lb;
            return $n ? "a{$la}b{$lb}+$n" : "";
        }
    }


    /** @return bool */
    function need_hlsummary() {
        return $this->language
            && ($this->_flags & DiffConfig::F_UNLOADED) === 0
            && ($this->_flags & DiffConfig::F_HIGHLIGHT) !== 0
            && ($this->_flags & DiffConfig::F_GAP) !== 0
            && $this->_dctx->repo
            && MinihighlightSummary::supports($this->language);
    }

    /** Commit hash that defines the content of diff side `$side`. Identifies the
     * CommitNotes row under which an `hlsummary($side)` result may be cached.
     * @param 'a'|'b' $side
     * @return non-empty-string */
    function hlsummary_commit_hash($side) {
        return $side === "a" ? $this->_dctx->commita->hash : $this->_dctx->commitb->hash;
    }

    /** Multi-line highlight-state summaries for re-seeding the client
     * highlighter inside hidden context, one per diff side.
     * @param 'a'|'b' $side
     * @return ?list A list of context, if necessary */
    function hlsummary($side) {
        $rf = $this->_dctx->repo->file_content($this->repo_hash($side), $this->repo_filename());
        if (!$rf || empty($rf->lines)) {
            return null;
        }
        $sum = MinihighlightSummary::summary($this->language, $rf->lines);
        return empty($sum) ? null : $sum;
    }
}
