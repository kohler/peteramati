<?php
// base.php -- HotCRP base helper functions
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.
/** @phan-file-suppress PhanRedefineFunction */

// type helpers

/** @param mixed $x
 * @return bool */
function is_number($x) {
    return is_int($x) || is_float($x);
}

/** @param mixed $x
 * @return bool */
function is_associative_array($x) {
    // this method is surprisingly fast
    return is_array($x) && array_values($x) !== $x;
}

/** @param mixed $x
 * @return bool */
function is_list($x) {
    return is_array($x) && array_values($x) === $x;
}

/** @param mixed $x
 * @return bool */
function is_int_list($x) {
    if (is_array($x) && array_values($x) === $x) {
        foreach ($x as $i) {
            if (!is_int($i))
                return false;
        }
        return true;
    } else {
        return false;
    }
}

/** @param mixed $x
 * @return bool */
function is_string_list($x) {
    if (is_array($x) && array_values($x) === $x) {
        foreach ($x as $i) {
            if (!is_string($i))
                return false;
        }
        return true;
    } else {
        return false;
    }
}


// string helpers

if (PHP_VERSION_ID < 80000) {
    /** @suppress PhanRedefineFunctionInternal */
    function str_contains($haystack, $needle) {
        return strpos($haystack, $needle) !== false;
    }
}

/** @param string $haystack
 * @param string $needle
 * @return bool */
function stri_starts_with($haystack, $needle) {
    $nl = strlen($needle);
    $hl = strlen($haystack);
    return $nl === 0 || ($hl >= $nl && substr_compare($haystack, $needle, 0, $nl, true) === 0);
}

/** @param string $haystack
 * @param string $needle
 * @return bool */
function stri_ends_with($haystack, $needle) {
    $nl = strlen($needle);
    $hl = strlen($haystack);
    return $nl === 0 || ($hl >= $nl && substr_compare($haystack, $needle, -$nl, $nl, true) === 0);
}

/** @param string $pattern
 * @param string $subject
 * @return int|false */
function preg_matchpos($pattern, $subject) {
    if (preg_match($pattern, $subject, $m, PREG_OFFSET_CAPTURE)) {
        return $m[0][1];
    } else {
        return false;
    }
}

/** @param string $text
 * @return string */
function cleannl($text) {
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
        $text = substr($text, 3);
    }
    if (strpos($text, "\r") !== false) {
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
    }
    return $text;
}

/** @param array $what
 * @param string $joinword
 * @return string */
function commajoin($what, $joinword = "and") {
    $what = array_values($what);
    $c = count($what);
    if ($c === 0) {
        return "";
    } else if ($c === 1) {
        return $what[0];
    } else if ($c === 2) {
        return "{$what[0]} {$joinword} {$what[1]}";
    } else {
        $last = array_pop($what);
        foreach ($what as &$w) {
            if (str_ends_with($w, "</span>")) {
                $w = substr($w, 0, -7) . ",</span>";
            } else {
                $w .= ",";
            }
        }
        return join(" ", $what) . " {$joinword} {$last}";
    }
}

function space_join(/* $str_or_array, ... */) {
    $t = "";
    foreach (func_get_args() as $arg) {
        if (is_array($arg)) {
            foreach ($arg as $x) {
                if ($x !== "" && $x !== false && $x !== null)
                    $t .= ($t === "" ? "" : " ") . $x;
            }
        } else if ($arg !== "" && $arg !== false && $arg !== null) {
            $t .= ($t === "" ? "" : " ") . $arg;
        }
    }
    return $t;
}

/** @param string $str
 * @return bool */
function is_usascii($str) {
    return !preg_match('/[\x80-\xFF]/', $str);
    // 2023: this is faster than iconv, iconv_strlen, or mb_check_encoding
}

/** @param string $str
 * @return bool */
function is_valid_utf8($str) {
    return !!preg_match('//u', $str);
}

if (function_exists("iconv")) {
    function windows_1252_to_utf8(string $str) {
        return iconv("Windows-1252", "UTF-8//IGNORE", $str);
    }
    function mac_os_roman_to_utf8(string $str) {
        return iconv("Mac", "UTF-8//IGNORE", $str);
    }
} else if (function_exists("mb_convert_encoding")) {
    function windows_1252_to_utf8(string $str) {
        return mb_convert_encoding($str, "UTF-8", "Windows-1252");
    }
}
if (!function_exists("windows_1252_to_utf8")) {
    function windows_1252_to_utf8(string $str) {
        return UnicodeHelper::windows_1252_to_utf8($str);
    }
}
if (!function_exists("mac_os_roman_to_utf8")) {
    function mac_os_roman_to_utf8(string $str) {
        return UnicodeHelper::mac_os_roman_to_utf8($str);
    }
}

/** @param string $str
 * @return string */
function convert_to_utf8($str) {
    if (str_starts_with($str, "\xEF\xBB\xBF")) {
        $str = substr($str, 3);
    }
    if (is_valid_utf8($str)) {
        return $str;
    }
    $pfx = substr($str, 0, 5000);
    if (substr_count($pfx, "\r") > 1.5 * substr_count($pfx, "\n")) {
        return mac_os_roman_to_utf8($str);
    } else {
        return windows_1252_to_utf8($str);
    }
}

/** @param string $str
 * @return string */
function simplify_whitespace($str) {
    // Replace invisible Unicode space-type characters with true spaces,
    // including control characters and DEL.
    return trim(preg_replace('/(?:[\x00-\x20\x7F]|\xC2[\x80-\xA0]|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)+/', " ", $str));
}

/** @param string $text
 * @param bool $all
 * @return int */
function tab_width($text, $all) {
    $len = 0;
    for ($i = 0; $i < strlen($text); ++$i) {
        if ($text[$i] === ' ') {
            ++$len;
        } else if ($text[$i] === '\t') {
            $len += 8 - ($len % 8);
        } else if (!$all) {
            break;
        } else {
            ++$len;
        }
    }
    return $len;
}

/** @param string $prefix
 * @param string $text
 * @param int|string $indent
 * @param ?int $width
 * @param bool $flowed
 * @return string */
function prefix_word_wrap($prefix, $text, $indent = 18, $width = 75, $flowed = false) {
    if (is_int($indent)) {
        $indentlen = $indent;
        $indent = str_repeat(" ", $indent);
    } else {
        $indentlen = strlen($indent);
    }
    $width = $width ?? 75;

    $out = $prefix;
    $wx = max($width - strlen($prefix), 0);
    $first = true;
    $itext = $text;

    while (($line = UnicodeHelper::utf8_line_break($text, $wx, $flowed)) !== false) {
        if ($first
            && $wx < $width - $indentlen
            && strlen($line) > $wx
            && strlen($line) < $width - $indentlen
            && $out !== ""
            && !ctype_space($out)
            && (!$flowed || strlen(rtrim($line)) > $wx)) {
            // `$prefix` too long for even one word: add a line break and restart
            $out = ($flowed ? $out : rtrim($out)) . "\n";
            $text = $itext;
            $wx = $width - $indentlen;
        } else if ($first) {
            // finish first line
            $out .= $line . "\n";
            $wx = $width - $indentlen;
        } else {
            $out .= $indent . preg_replace('/\A\pZ+/u', '', $line) . "\n";
        }
        $first = false;
    }

    if (!str_ends_with($out, "\n")) {
        $out .= "\n";
    }
    return $out;
}

/** @param string $text
 * @param int $totWidth
 * @param bool $multi_center */
function center_word_wrap($text, $totWidth = 75, $multi_center = false) {
    if (strlen($text) <= $totWidth && !preg_match('/[\200-\377]/', $text)) {
        return str_pad($text, (int) (($totWidth + strlen($text)) / 2), " ", STR_PAD_LEFT) . "\n";
    }
    $out = "";
    while (($line = UnicodeHelper::utf8_line_break($text, $totWidth)) !== false) {
        $linelen = UnicodeHelper::utf8_glyphlen($line);
        $out .= str_pad($line, (int) (($totWidth + $linelen) / 2), " ", STR_PAD_LEFT) . "\n";
    }
    return $out;
}

/** @param string $text */
function count_words($text) {
    return preg_match_all('/[^-\s.,;:<>!?*_~`#|]\S*/', $text);
}

/** @param string $s
 * @param int $flags
 * @return string */
function glob_to_regex($s, $flags = 0) {
    $t = "";
    while (preg_match('/\A(.*?)([-.\\\\+*?\\[^\\]$(){}=!<>|:#\\/])([\s\S]*)\z/', $s, $m)) {
        $t .= $m[1];
        if ($m[2] === "\\") {
            if ($m[3] === "") {
                $t .= "\\\\";
            } else {
                $t .= "\\" . $m[3][0];
                $m[3] = (string) substr($m[3], 1);
            }
        } else if ($m[2] === "*") {
            $t .= ".*";
        } else if ($m[2] === "?") {
            $t .= ".";
        } else if ($m[2] === "["
                   && ($pos = strpos($m[3], "]")) !== false
                   && $pos > 0) {
            $x = substr($m[3], 0, $pos);
            $m[3] = (string) substr($m[3], $pos + 1);
            if ($x[0] === "!") {
                $t .= "[^" . (string) substr($x, 1) . "]";
            } else {
                $t .= "[{$x}]";
            }
        } else if ($m[2] === "{"
                   && ($pos = strpos($m[3], "}")) !== false
                   && ($flags & GLOB_BRACE) !== 0) {
            $x = substr($m[3], 0, $pos);
            $m[3] = (string) substr($m[3], $pos + 1);
            $sep = "(?:";
            while ($x !== "") {
                $comma = strpos($x, ",");
                $pos = $comma === false ? strlen($x) : $comma;
                $t .= $sep . substr($x, 0, $pos);
                $sep = "|";
                $x = $comma === false ? "" : (string) substr($x, $comma + 1);
            }
            if ($sep !== "(?:") {
                $t .= ")";
            }
        } else {
            $t .= "\\" . $m[2];
        }
        $s = $m[3];
    }
    return $t . $s;
}

/** @param mixed $x
 * @return ?bool */
function friendly_boolean($x) {
    if (is_bool($x)) {
        return $x;
    } else if (is_string($x) || is_int($x)) {
        // 0, false, off, no: false; 1, true, on, yes: true
        return filter_var($x, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    } else {
        return null;
    }
}

/** @param ?string $varname
 * @param null|string|int $value
 * @return int */
function ini_get_bytes($varname, $value = null) {
    $value = $value ?? trim(ini_get($varname));
    if (is_string($value)) {
        $len = strlen($value);
        $last = $len > 0 ? strtolower($value[$len - 1]) : ".";
        /** @phan-suppress-next-line PhanParamSuspiciousOrder */
        $value = floatval($value) * (1 << (+strpos(".kmg", $last) * 10));
    }
    return (int) ceil($value);
}


interface Abbreviator {
    public function abbreviations_for($name, $data);
}


// email and MIME helpers

/** @param string $email
 * @return bool */
function validate_email($email) {
    // Allow @_.com email addresses.  Simpler than RFC822 validation.
    return preg_match('/\A[-!#$%&\'*+.\/0-9=?A-Z^_`a-z{|}~]+@(?:_\.|(?:[-0-9A-Za-z]+\.)+)[0-9A-Za-z]+\z/', $email);
}

/** @param string $s
 * @param int $pos
 * @return ?string */
function validate_email_at($s, $pos) {
    // Allow @_.com email addresses.  Simpler than RFC822 validation.
    if (preg_match('/\G[-!#$%&\'*+.\/0-9=?A-Z^_`a-z{|}~]+@(?:_\.|(?:[-0-9A-Za-z]+\.)+)[0-9A-Za-z]+(?=\z|[-,.;:()\[\]{}\s]|–|—)/', $s, $m, 0, $pos)) {
        return $m[0];
    } else {
        return null;
    }
}

/** @param string $word
 * @return string */
function mime_quote_string($word) {
    return '"' . preg_replace('/(?=[\x00-\x1F\\"])/', '\\', $word) . '"';
}

/** @param string $word
 * @return string */
function mime_token_quote($word) {
    if (preg_match('/\A[^][\x00-\x20\x80-\xFF()<>@,;:\\"\/?=]+\z/', $word)) {
        return $word;
    } else {
        return mime_quote_string($word);
    }
}

/** @param string $words
 * @return string */
function rfc2822_words_quote($words) {
    // NB: Do not allow `'` in an unquoted <phrase>; Proofpoint can add quotes
    // to names containing `'`, which invalidates a DKIM signature.
    if (preg_match('/\A[-A-Za-z0-9!#$%&*+\/=?^_`{|}~ \t]*\z/', $words)) {
        return $words;
    } else {
        return mime_quote_string($words);
    }
}


// encoders and decoders

/** @param string $text
 * @return string */
function html_id_encode($text) {
    $x = preg_split('/([^-a-zA-Z0-9_.\/])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i < count($x); $i += 2) {
        $x[$i] = sprintf("@%02X", ord($x[$i]));
    }
    return join("", $x);
}

/** @param string $text
 * @return string */
function html_id_decode($text) {
    $x = preg_split('/(@[0-9A-Fa-f][0-9A-Fa-f])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i < count($x); $i += 2) {
        $x[$i] = chr(hexdec(substr($x[$i], 1)));
    }
    return join("", $x);
}

/** @param string $text
 * @return string */
function base64url_encode($text) {
    return rtrim(str_replace(["+", "/"], ["-", "_"], base64_encode($text)), "=");
}

/** @param string $text
 * @return string */
function base64url_decode($text) {
    return base64_decode(str_replace(["-", "_"], ["+", "/"], $text));
}

/** @param string $text
 * @return bool */
function is_base64url_string($text) {
    return preg_match('/\A[-_A-Za-z0-9]*\z/', $text);
}

/** @param int $length
 * @param int $base
 * @return string */
function random_alnum_chars($length, $base = 36) {
    assert(is_int($base) && $base >= 2 && $base <= 36);
    $alphabet = "0123456789abcdefghijklmnopqrstuvwxyz";
    $chperw = (int) floor(32 * M_LN2 / log($base));
    $w = $wbound = $base ** $chperw;
    $expand = 0x100000000 / $wbound;
    $i = 0;
    $words = [];
    $s = "";
    while (strlen($s) !== $length) {
        if ($w < $wbound && $i < $chperw) {
            $s .= $alphabet[$w % $base];
            $w = (int) ($w / $base);
            ++$i;
        } else {
            if (empty($words)) {
                $wantch = (int) ceil(ceil(($length - strlen($s)) / $chperw) * $expand);
                $words = array_values(unpack("L*", random_bytes($wantch * 4)));
            }
            $w = array_pop($words);
            $i = 0;
        }
    }
    return $s;
}


/** @param string $uri
 * @return string */
function normalize_uri($uri) {
    if (strpos($uri, "%") !== false) {
        $uri = preg_replace_callback('/%[0-9A-Fa-f][0-9A-Fa-f]/', function ($m) {
            $ch = intval(substr($m[0], 1), 16);
            if ($ch === 0x2D
                || $ch === 0x2E
                || ($ch >= 0x30 && $ch <= 0x39)
                || ($ch >= 0x41 && $ch <= 0x5A)
                || $ch >= 0x5F
                || ($ch >= 0x61 && $ch <= 0x7A)
                || $ch === 0x7E) {
                return chr($ch);
            } else {
                return strtoupper($m[0]);
            }
        }, $uri);
    }
    $x = parse_url($uri);
    if ($x === false) {
        return "";
    }
    $t = "";
    if (isset($x["scheme"])) {
        $t = strtolower($x["scheme"]) . ":";
    }
    if (isset($x["host"])) {
        $t .= "//";
        if (isset($x["user"])) {
            $t .= $x["user"];
            if (isset($x["password"])) {
                $t .= ":" . $x["password"];
            }
            $t .= "@";
        }
        $t .= strtolower($x["host"]);
        if (isset($x["port"])
            && ($x["port"] !== "80" || strcasecmp($x["scheme"] ?? "", "http") !== 0)
            && ($x["port"] !== "443" || strcasecmp($x["scheme"] ?? "", "https") !== 0)) {
            $t .= ":" . $x["port"];
        }
    }
    if (isset($x["path"])) {
        $t .= $x["path"];
    }
    if (isset($x["query"])) {
        $t .= "?" . $x["query"];
    }
    if (isset($x["fragment"])) {
        $t .= "#" . $x["fragment"];
    }
    return $t;
}


// JSON encoding helpers

if (defined("JSON_UNESCAPED_LINE_TERMINATORS")) {
    // JSON_UNESCAPED_UNICODE is only safe to send to the browser if
    // JSON_UNESCAPED_LINE_TERMINATORS is defined.
    /** @return string */
    function json_encode_browser($x, $flags = 0) {
        return json_encode($x, $flags | JSON_UNESCAPED_UNICODE);
    }
} else {
    /** @return string */
    function json_encode_browser($x, $flags = 0) {
        return json_encode($x, $flags);
    }
}

/** @return string */
function json_encode_db($x, $flags = 0) {
    return json_encode($x, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** @return string */
function json_escape_browser_sqattr($x, $flags = 0) {
    $s = json_encode($x, $flags);
    return str_replace(["&", "'"], ["&amp;", "&#39;"], $s);
}


// array and object helpers

/** @param string $needle
 * @param list<string> $haystack
 * @return int */
function str_list_lower_bound($needle, $haystack) {
    $l = 0;
    $r = count($haystack);
    while ($l < $r) {
        $m = $l + (($r - $l) >> 1);
        $cmp = strcmp($needle, $haystack[$m]);
        if ($cmp <= 0) {
            $r = $m;
        } else {
            $l = $m + 1;
        }
    }
    return $l;
}

/** @param int|float $needle
 * @param list<int|float> $haystack
 * @return int */
function num_list_lower_bound($needle, $haystack) {
    $l = 0;
    $r = count($haystack);
    while ($l < $r) {
        $m = $l + (($r - $l) >> 1);
        if ($needle <= $haystack[$m]) {
            $r = $m;
        } else {
            $l = $m + 1;
        }
    }
    return $l;
}

/** @param mixed $a */
function array_to_object_recursive($a) {
    if (is_array($a) && is_associative_array($a)) {
        $o = (object) [];
        foreach ($a as $k => $v) {
            if ($k !== "")
                $o->$k = array_to_object_recursive($v);
        }
        return $o;
    } else {
        return $a;
    }
}

function object_replace($a, $b) {
    foreach (is_object($b) ? get_object_vars($b) : $b as $k => $v) {
        if ($v === null) {
            unset($a->$k);
        } else {
            $a->$k = $v;
        }
    }
}

const OBJECT_REPLACE_NO_RECURSE = "norecurse\000";

/** @param object $a
 * @param array|object $b
 * @return void */
function object_replace_recursive($a, $b) {
    $ba = is_object($b) ? get_object_vars($b) : $b;
    if ($ba[OBJECT_REPLACE_NO_RECURSE] ?? null) {
        foreach (array_keys(get_object_vars($a)) as $ak) {
            unset($a->$ak);
        }
    }
    foreach ($ba as $k => $v) {
        if (is_object($v) || is_associative_array($v)) {
            if (!is_object($a->$k ?? null)) {
                $a->$k = (object) [];
            }
            object_replace_recursive($a->$k, $v);
        } else if ($v !== null && $k !== OBJECT_REPLACE_NO_RECURSE) {
            $a->$k = $v;
        } else {
            unset($a->$k);
        }
    }
}

function object_merge_recursive($a, $b) {
    foreach (get_object_vars($b) as $k => $v) {
        if (!property_exists($a, $k)) {
            if (is_object($v)) {
                $x = $a->$k = (object) [];
                object_merge_recursive($x, $v);
            } else {
                $a->$k = $v;
            }
        } else if (is_object($a->$k) && is_object($v)) {
            object_merge_recursive($a->$k, $v);
        }
    }
}

function json_object_replace($j, $updates, $nullable = false) {
    if ($j === null) {
        $j = (object) [];
    } else if (is_array($j)) {
        $j = (object) $j;
    }
    object_replace($j, $updates);
    if ($nullable) {
        $x = get_object_vars($j);
        if (empty($x)) {
            $j = null;
        }
    }
    return $j;
}


// debug helpers

function caller_landmark($position = 1, $skipfunction_re = null) {
    if (is_string($position)) {
        $skipfunction_re = $position;
        $position = 1;
    }
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $fname = null;
    for (++$position; isset($trace[$position]); ++$position) {
        $fname = $trace[$position]["class"] ?? "";
        $fname .= ($fname ? "::" : "") . $trace[$position]["function"];
        if ((!$skipfunction_re || !preg_match($skipfunction_re, $fname))
            && ($fname !== "call_user_func" || ($trace[$position - 1]["file"] ?? false))) {
            break;
        }
    }
    $t = "";
    if ($position > 0 && ($pi = $trace[$position - 1]) && isset($pi["file"])) {
        $t = $pi["file"] . ":" . $pi["line"];
    }
    if ($fname) {
        $t .= ($t ? ":" : "") . $fname;
    }
    return $t ? : "<unknown>";
}

function assert_callback() {
    trigger_error("Assertion backtrace: " . json_encode(array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 2)), E_USER_WARNING);
}
//assert_options(ASSERT_CALLBACK, "assert_callback");

/** @param ?Throwable $ex
 * @return string */
function debug_string_backtrace($ex = null) {
    $s = ($ex ?? new Exception)->getTraceAsString();
    if (!$ex) {
        $s = substr($s, strpos($s, "\n") + 1);
        $s = preg_replace_callback('/^\#(\d+)/m', function ($m) {
            return "#" . ($m[1] - 1);
        }, $s);
    }
    if (SiteLoader::$root) {
        $s = str_replace(SiteLoader::$root, "[" . (Conf::$main ? Conf::$main->dbname : "Peteramati") . "]", $s);
    }
    return $s;
}


// pcntl helpers

if (function_exists("pcntl_wifexited") && pcntl_wifexited(0) !== null) {
    /** @param int $status
     * @param int $exitstatus
     * @return bool */
    function pcntl_wifexitedwith($status, $exitstatus = 0) {
        return pcntl_wifexited($status) && pcntl_wexitstatus($status) === $exitstatus;
    }
} else {
    /** @param int $status
     * @param int $exitstatus
     * @return bool */
    function pcntl_wifexitedwith($status, $exitstatus = 0) {
        return ($status & 0xff7f) === ($exitstatus << 8);
    }
}


/** @return Exception */
function error_get_last_as_exception($prefix) {
    $msg = preg_replace('/.*: /', "", error_get_last()["message"]);
    return new ErrorException($prefix . $msg);
}

/** @return string */
function file_get_contents_throw($filename) {
    $s = @file_get_contents($filename);
    if ($s === false) {
        throw error_get_last_as_exception("{$filename}: ");
    }
    return $s;
}


// setcookie helper

if (PHP_VERSION_ID >= 70300) {
    function hotcrp_setcookie($name, $value = "", $options = []) {
        return setcookie($name, $value, $options);
    }
} else {
    function hotcrp_setcookie($name, $value = "", $options = []) {
        return setcookie($name, $value, $options["expires"] ?? 0,
                         $options["path"] ?? "", $options["domain"] ?? "",
                         $options["secure"] ?? false, $options["httponly"] ?? false);
    }
}
