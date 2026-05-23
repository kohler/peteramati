<?php
// minihighlightsummary.php -- server-side multi-line lexer-state scanner
// Peteramati is Copyright (c) 2006-2026 Eddie Kohler
// See LICENSE for open-source distribution terms

// Mirrors the *multi-line state* subset of scriptsrc/minihighlight.js: it tracks
// only the constructs that can cross line boundaries -- block comments and raw,
// triple, template, and backslash-continued strings -- and ignores everything
// that stays within a line (keywords, numbers, single-line strings, etc.).
//
// `MinihighlightSummary::summary($lang, $lines)` returns a compact list of the
// lines where the entry state changes, so the client highlighter can be
// re-seeded inside hidden diff context. A `MinihighlightState` (k, c, f) is
// exactly minihighlight's `top`.
//
// MUST stay in sync with scriptsrc/minihighlight.js. The agreement test
// scriptsrc/test/minihighlightstate.test.js compares this against minihighlight
// itself over a corpus and fails on drift. These classes have no dependencies
// so that test can run them directly with `php`.

class MinihighlightState {
    /** @var string  "bc" (block comment) or "s" (string) */
    public $k;
    /** @var string  close delimiter */
    public $c;
    /** @var int  MinihighlightSummary::SF_* flags */
    public $f;

    /** @param string $k
     * @param string $c
     * @param int $f */
    function __construct($k, $c, $f) {
        $this->k = $k;
        $this->c = $c;
        $this->f = $f;
    }

    /** @param ?MinihighlightState $a
     * @param ?MinihighlightState $b
     * @return bool */
    static function equals($a, $b) {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return $a->k === $b->k && $a->c === $b->c && $a->f === $b->f;
    }
}

class MinihighlightStringSpec {
    /** @var string */
    public $o;
    /** @var string */
    public $c;
    /** @var int */
    public $f;

    function __construct($o, $c, $f) {
        $this->o = $o;
        $this->c = $c;
        $this->f = $f;
    }
}

class MinihighlightSpec {
    /** @var list<MinihighlightStringSpec> */
    public $lc = [];
    /** @var list<MinihighlightStringSpec> */
    public $bc = [];
    /** @var list<MinihighlightStringSpec> */
    public $strings = [];
    /** @var bool */
    public $cpp_raw = false;
    /** @var list<int> */
    public $table;

    function complete() {
        $this->table = array_fill(0, 128, 0);
        foreach ($this->lc as $s) {
            $this->table[ord($s->o[0])] |= MinihighlightSummary::CHF_LC;
        }
        foreach ($this->bc as $s) {
            $this->table[ord($s->o[0])] |= MinihighlightSummary::CHF_BC;
        }
        foreach ($this->strings as $s) {
            $this->table[ord($s->o[0])] |= MinihighlightSummary::CHF_STRING;
        }
        if ($this->cpp_raw) {
            foreach (["u", "U", "L", "R"] as $ch) {
                $this->table[ord($ch)] |= MinihighlightSummary::CHF_CPP_RAW;
            }
        }
        $this->table[10 /* \n */] |= MinihighlightSummary::CHF_NL;
    }
}

class MinihighlightSummary {
    // Format/algorithm version of `summary()` output. Bump whenever the
    // transition-list format or scanning behavior changes, to invalidate any
    // cached summaries (see PsetView::diff_hlsummary).
    const SUMMARY_VERSION = 1;

    const SF_ESCAPE = 1;
    const SF_MULTILINE = 2;

    const CHF_LC = 1;
    const CHF_BC = 2;
    const CHF_STRING = 4;
    const CHF_CPP_RAW = 8;
    const CHF_NL = 16;

    /** @var array<string,?array> */
    static private $specs = [
        "cpp" => null, "c" => null, "javascript" => null, "typescript" => null,
        "python" => null, "shell" => null, "make" => null
    ];

    static private $aliases = [
        "c++" => "cpp", "cc" => "cpp", "cxx" => "cpp", "hpp" => "cpp",
        "hh" => "cpp", "h++" => "cpp", "h" => "c",
        "js" => "javascript", "jsx" => "javascript", "mjs" => "javascript",
        "cjs" => "javascript", "ts" => "typescript", "tsx" => "typescript",
        "py" => "python", "py3" => "python",
        "sh" => "shell", "bash" => "shell", "zsh" => "shell",
        "makefile" => "make", "mk" => "make"
    ];

    /** @param ?string $lang
     * @return ?string */
    static function canon($lang) {
        if ((string) $lang === "") {
            return null;
        }
        $l = strtolower($lang);
        return self::$aliases[$l] ?? $l;
    }

    /** @return ?MinihighlightSpec */
    static private function spec($lang) {
        $lang = self::canon($lang);
        if ($lang === null || !array_key_exists($lang, self::$specs)) {
            return null;
        }
        if (isset(self::$specs[$lang])) {
            return self::$specs[$lang];
        }
        $sp = self::$specs[$lang] = new MinihighlightSpec;
        if ($lang === "c" || $lang === "cpp" || $lang === "javascript" || $lang === "typescript") {
            $sp->lc[] = new MinihighlightStringSpec("//", "", 0);
            $sp->bc[] = new MinihighlightStringSpec("/*", "*/", self::SF_MULTILINE);
        } else if ($lang === "python" || $lang === "shell" || $lang === "make") {
            $sp->lc[] = new MinihighlightStringSpec("#", "", 0);
        }
        if ($lang === "python") {
            $sp->strings[] = new MinihighlightStringSpec("\"\"\"", "\"\"\"", self::SF_ESCAPE | self::SF_MULTILINE);
            $sp->strings[] = new MinihighlightStringSpec("'''", "'''", self::SF_ESCAPE | self::SF_MULTILINE);
        }
        if ($lang === "c" || $lang === "cpp" || $lang === "javascript" || $lang === "typescript" || $lang === "python") {
            $sp->strings[] = new MinihighlightStringSpec("\"", "\"", self::SF_ESCAPE);
            $sp->strings[] = new MinihighlightStringSpec("'", "'", self::SF_ESCAPE);
        }
        if ($lang === "javascript" || $lang === "typescript") {
            $sp->strings[] = new MinihighlightStringSpec("`", "`", self::SF_ESCAPE | self::SF_MULTILINE);
        } else if ($lang === "c" || $lang === "cpp") {
            foreach (["L\"", "u\"", "U\"", "u8\"", "L'", "u'", "U'", "u8'"] as $q) {
                $cq = $q[strlen($q) - 1];
                $sp->strings[] = new MinihighlightStringSpec($q, $cq, self::SF_ESCAPE);
            }
        } else if ($lang === "shell" || $lang === "make") {
            $sp->strings[] = new MinihighlightStringSpec("\"", "\"", self::SF_ESCAPE);
            $sp->strings[] = new MinihighlightStringSpec("'", "'", 0);
        }
        $sp->cpp_raw = $lang === "cpp";
        $sp->complete();
        return $sp;
    }

    /** @return bool */
    static function supports($lang) {
        return self::spec($lang) !== null;
    }

    /** Scan from `$pos` for the (unescaped) `$close` delimiter.
     * @return array{int,?MinihighlightState} [end, state] */
    static private function consume($text, $pos, $len, $st) {
        $closech = $st->c[0];
        $cl = strlen($st->c);
        while ($pos < $len) {
            $ch = $text[$pos];
            if ($ch === "\\" && ($st->f & self::SF_ESCAPE)) {
                ++$pos;
                $nch = $pos < $len ? $text[$pos] : "\n";
                if ($nch === "\n") {
                    return [$pos, $st];
                } else if ($nch === "\r" && ($pos + 1 === $len || $text[$pos + 1] === "\n")) {
                    return [$pos + 1, $st];
                }
                ++$pos;
            } else if ($ch === $closech
                       && $pos + $cl <= $len
                       && substr_compare($text, $st->c, $pos, $cl) === 0) {
                return [$pos + $cl, null];
            } else if ($ch === "\n") {
                break;
            } else {
                ++$pos;
            }
        }
        return [$pos, ($st->f & self::SF_MULTILINE) ? $st : null];
    }

    /** @param list<MinihighlightStringSpec> $pairs
     * @return ?MinihighlightStringSpec */
    static private function find_at($pairs, $text, $pos) {
        foreach ($pairs as $p) {
            if (substr_compare($text, $p->o, $pos, strlen($p->o)) === 0) {
                return $p;
            }
        }
        return null;
    }

    /** Match a C++ raw-string opener at `$pos`. Returns `[bodystart, MinihighlightState]` or null. */
    static private function cpp_raw_at($line, $pos, $len) {
        $p = $pos;
        if ($p + 1 < $len && $line[$p] === "u" && $line[$p + 1] === "8") {
            $p += 2;
        } else if ($p < $len && ($line[$p] === "u" || $line[$p] === "U" || $line[$p] === "L")) {
            $p += 1;
        }
        if ($p + 1 >= $len || $line[$p] !== "R" || $line[$p + 1] !== "\"") {
            return null;
        }
        $p += 2;
        $d = $p;
        while ($d < $len && $d - $p < 16) {
            $ch = $line[$d];
            if ($ch === "(" || $ch === ")" || $ch === "\\" || $ch === " " || $ch === "\t") {
                break;
            }
            ++$d;
        }
        if ($d >= $len || $line[$d] !== "(") {
            return null;
        }
        return [$d + 1, new MinihighlightState("s", ")" . substr($line, $p, $d - $p) . "\"", self::SF_MULTILINE)];
    }

    /** Compute the entry-state transition list for a file.
     * @param ?string $lang
     * @param string|list<string> $input  full file content (line endings \r\n,
     *   \r, or \n), or a list of lines (each treated as a separate line)
     * @return ?list  null if unsupported; else a transition list. Each element
     *   marks a 1-indexed line whose entry state changes:
     *     - bare int `lineno`     -> entry state returns to none (null);
     *     - `[lineno, "bc"]`      -> block comment (close and flags implied);
     *     - `[lineno, k, c, f]`   -> state with kind k, close c, flags f (k is "s").
     *   The entry state of any line is given by the last element at or before it. */
    static function summary($lang, $input) {
        $sp = self::spec($lang);
        if ($sp === null) {
            return null;
        }
        $texts = is_string($input) ? [$input] : $input;
        $table = $sp->table;
        $textindex = -1;
        $text = "";
        $len = 0;
        $pos = 0;
        $lineno = 1;            // line being scanned
        $entry = null;          // entry state recorded for line $lineno
        $state = null;          // running lexer state == entry state of line $lineno
        $out = [];

        while (true) {
            // move to next string
            if ($pos === $len) {
                ++$textindex;
                if ($textindex === count($texts)) {
                    break;
                }
                $text = $texts[$textindex];
                $pos = 0;
                $len = strlen($text);
            }
            // entry state of line $lineno is $state; record any change
            if (!MinihighlightState::equals($state, $entry)) {
                if ($state === null) {
                    $out[] = $lineno;
                } else if ($state->k === "bc" && count($sp->bc) === 1) {
                    $out[] = [$lineno, "bc"];
                } else {
                    $out[] = [$lineno, $state->k, $state->c, $state->f];
                }
                $entry = $state;
            }
            // scan to the end of the line
            while ($pos < $len) {
                $c = ord($text[$pos]);
                $f = $c < 128 ? $table[$c] : 0;
                if ($f & self::CHF_NL) {
                    ++$pos;
                    break;
                } else if ($state !== null) {
                    list($pos, $state) = self::consume($text, $pos, $len, $state);
                } else if ($f === 0) {
                    ++$pos;
                } else if (($f & self::CHF_LC)
                           && self::find_at($sp->lc, $text, $pos) !== null) {
                    // line comment: rest of the line
                    $nl = strpos($text, "\n", $pos);
                    $pos = $nl === false ? $len : $nl;
                } else if (($f & self::CHF_BC)
                           && ($bc = self::find_at($sp->bc, $text, $pos)) !== null) {
                    // block comment
                    list($pos, $state) = self::consume($text, $pos + strlen($bc->o), $len,
                        new MinihighlightState("bc", $bc->c, $bc->f));
                } else if (($f & self::CHF_STRING)
                           && ($sd = self::find_at($sp->strings, $text, $pos)) !== null) {
                    // string
                    list($pos, $state) = self::consume($text, $pos + strlen($sd->o), $len,
                        new MinihighlightState("s", $sd->c, $sd->f));
                } else if (($f & self::CHF_CPP_RAW)
                           && ($raw = self::cpp_raw_at($text, $pos, $len)) !== null) {
                    // C++ raw string `(?:u|u8|U|L)?R"--delim--( ... )--delim--"`
                    list($pos, $state) = self::consume($text, $raw[0], $len, $raw[1]);
                } else {
                    // a flagged character that opened nothing (e.g. `/` division)
                    ++$pos;
                }
            }
            ++$lineno;
        }
        return $out;
    }
}
