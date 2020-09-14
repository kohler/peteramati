<?php
// helpers.php -- HotCRP non-class helper functions
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

function defappend(&$var, $str) {
    if (!isset($var))
        $var = "";
    $var .= $str;
}

function arrayappend(&$var, $value) {
    if (isset($var))
        $var[] = $value;
    else
        $var = array($value);
}

function mkarray($value) {
    if (is_array($value))
        return $value;
    else
        return array($value);
}

function &array_ensure(&$arr, $key, $val) {
    if (!isset($arr[$key]))
        $arr[$key] = $val;
    return $arr[$key];
}

function ago($t) {
    if ($t + 60 >= Conf::$now)
        return "less than a minute ago";
    else if ($t + 7200 >= Conf::$now)
        return plural((int)((Conf::$now - $t) / 60 + 0.5), "minute") . " ago";
    else if ($t + 259200 >= Conf::$now)
        return plural((int)((Conf::$now - $t) / 3600 + 0.5), "hour") . " ago";
    else
        return plural((int)((Conf::$now - $t) / 86400 + 0.5), "day") . " ago";
}

function parse_time($d, $reference = null) {
    global $Opt;
    if ($reference === null)
        $reference = Conf::$now;
    if (!isset($Opt["dateFormatTimezoneRemover"])
        && function_exists("timezone_abbreviations_list")) {
        $mytz = date_default_timezone_get();
        $x = array();
        foreach (timezone_abbreviations_list() as $tzname => $tzinfo) {
            foreach ($tzinfo as $tz)
                if ($tz["timezone_id"] == $mytz)
                    $x[] = preg_quote($tzname);
        }
        if (count($x) == 0)
            $x[] = preg_quote(date("T", $reference));
        $Opt["dateFormatTimezoneRemover"] =
            "/(?:\\s|\\A)(?:" . join("|", $x) . ")(?:\\s|\\z)/i";
    }
    if (@$Opt["dateFormatTimezoneRemover"])
        $d = preg_replace($Opt["dateFormatTimezoneRemover"], " ", $d);
    $d = preg_replace('/\butc([-+])/i', 'GMT$1', $d);
    return strtotime($d, $reference);
}


// string helpers

function cvtint($value, $default = -1) {
    $v = trim((string) $value);
    if (is_numeric($v)) {
        $ival = intval($v);
        if ($ival == floatval($v))
            return $ival;
    }
    return $default;
}

function cvtnum($value, $default = -1) {
    $v = trim((string) $value);
    if (is_numeric($v))
        return floatval($v);
    return $default;
}

function rcvtint(&$value, $default = -1) {
    return (isset($value) ? cvtint($value, $default) : $default);
}


interface JsonUpdatable extends JsonSerializable {
    public function jsonIsReplacement();
}

function json_update($j, $updates) {
    if (is_object($j)) {
        $j = get_object_vars($j);
    } else if (!is_array($j)) {
        $j = [];
    }
    if (is_object($updates)) {
        $is_replacement = $updates instanceof JsonUpdatable
            && $updates->jsonIsReplacement();
        if ($updates instanceof JsonSerializable)
            $updates = $updates->jsonSerialize();
        if ($is_replacement)
            return $updates;
        if (is_object($updates))
            $updates = get_object_vars($updates);
    }
    foreach ($updates as $k => $v) {
        if ($k === "") {
            global $Me;
            error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) . ", user $Me->email");
            continue;
        }
        if (is_object($v) || is_associative_array($v)) {
            $v = json_update(isset($j[$k]) ? $j[$k] : null, $v);
        }
        if ($v === null) {
            unset($j[$k]);
        } else {
            $j[$k] = $v;
        }
    }
    $n = count($j);
    if ($n == 0)
        return null;
    for ($i = 0; $i !== $n; ++$i)
        if (!isset($j[$i]) && !array_key_exists($i, $j))
            return (object) $j;
    ksort($j, SORT_NUMERIC);
    return array_values($j);
}


// web helpers

function hoturl_add_raw($url, $component) {
    if (($pos = strpos($url, "#")) !== false) {
        $component .= substr($url, $pos);
        $url = substr($url, 0, $pos);
    }
    return $url . (strpos($url, "?") === false ? "?" : "&") . $component;
}

function hoturl($page, $param = null) {
    global $Conf;
    return $Conf->hoturl($page, $param);
}

function hoturl_post($page, $param = null) {
    global $Conf;
    return $Conf->hoturl($page, $param, Conf::HOTURL_POST);
}


function file_uploaded(&$var) {
    global $Conf;
    if (!isset($var) || ($var['error'] != UPLOAD_ERR_OK && !$Conf))
        return false;
    switch ($var['error']) {
    case UPLOAD_ERR_OK:
        return is_uploaded_file($var['tmp_name'])
            || (PHP_SAPI === "cli" && get($var, "tmp_name_safe"));
    case UPLOAD_ERR_NO_FILE:
        return false;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        $Conf->errorMsg("You tried to upload a file that’s too big for our system to accept.  The maximum size is " . ini_get("upload_max_filesize") . "B.");
        return false;
    case UPLOAD_ERR_PARTIAL:
        $Conf->errorMsg("You appear to have interrupted the upload process; I am not storing that file.");
        return false;
    default:
        $Conf->errorMsg("Internal upload error " . $var['error'] . "!");
        return false;
    }
}

function redirectSelf($param = []) {
    global $Conf;
    $Conf->self_redirect(null, $param);
}

class JsonResult {
    public $status;
    public $content;
    public $has_messages = false;

    function __construct($values = null) {
        if (is_int($values)) {
            $this->status = $values;
            if (func_num_args() === 2) {
                $values = func_get_arg(1);
            } else {
                $values = null;
            }
        }
        if ($values === true || $values === false) {
            $this->content = ["ok" => $values];
        } else if ($values === null) {
            $this->content = [];
        } else if (is_object($values)) {
            assert(!($values instanceof JsonResult));
            $this->content = (array) $values;
        } else if (is_string($values)) {
            if ($this->status && $this->status > 299) {
                $this->content = ["ok" => false, "error" => $values];
            } else {
                $this->content = ["ok" => true, "response" => $values];
            }
        } else {
            $this->content = $values;
        }
    }
    static function make($json, Contact $user = null, $arg2 = null) {
        if (is_int($json)) {
            $json = new JsonResult($json, $arg2);
        } else if (!is_object($json) || !($json instanceof JsonResult)) {
            $json = new JsonResult($json);
        }
        if (!$json->has_messages && $user) {
            $json->take_messages($user);
        }
        return $json;
    }
    function take_messages(Contact $user, $div = false) {
        if (session_id() !== ""
            && ($msgs = $user->session("msgs", []))) {
            $user->save_session("msgs", null);
            $t = "";
            foreach ($msgs as $msg) {
                if (($msg[1] === "merror" || $msg[1] === "xmerror")
                    && !isset($this->content["error"])) {
                    $this->content["error"] = $msg[0];
                }
                if ($div) {
                    $t .= Ht::msg($msg[0], $msg[1]);
                } else {
                    $t .= "<span class=\"$msg[1]\">$msg[0]</span>";
                }
            }
            if ($t !== "") {
                $this->content["response"] = $t . get_s($this->content, "response");
            }
            $this->has_messages = true;
        }
    }
    function export_errors() {
        if (isset($this->content["error"])) {
            Conf::msg_error($this->content["error"]);
        }
        if (isset($this->content["errf"])) {
            foreach ($this->content["errf"] as $f => $x)
                Ht::error_at($f);
        }
    }
}

class JsonResultException extends Exception {
    public $result;
    static public $capturing = false;
    function __construct($j) {
        $this->result = $j;
    }
}

function json_exit($json, $arg2 = null) {
    global $Me, $Qreq;
    $json = JsonResult::make($json, $Me ? : null, $arg2);
    if (JsonResultException::$capturing) {
        throw new JsonResultException($json);
    } else {
        if ($Qreq && $Qreq->post_ok()) {
            if ($json->status) {
                http_response_code($json->status);
            }
            header("Access-Control-Allow-Origin: *");
        } else if ($json->status) {
            // Don’t set status on unvalidated requests, since that can leak
            // information (e.g. via <link prefetch onerror>).
            if (!isset($json->content["ok"])) {
                $json->content["ok"] = $json->status <= 299;
            }
            if (!isset($json->content["status"])) {
                $json->content["status"] = $json->status;
            }
            if ($Qreq->post && !$Qreq->post_ok()) {
                $json->content["postvalue"] = post_value(true);
            }
        }
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode_browser($json->content);
        exit;
    }
}

function foldbutton($foldtype, $title, $foldnum = 0) {
    $showtitle = ($title ? " title='" . htmlspecialchars("Show $title") . "'" : "");
    $hidetitle = ($title ? " title='" . htmlspecialchars("Hide $title") . "'" : "");
    $foldclass = ($foldnum ? $foldnum : "");
    $foldnum = ($foldnum ? ",$foldnum" : "");
    return "<a href=\"javascript:void fold('$foldtype',0$foldnum)\" class='foldbutton fn$foldclass'$showtitle>+</a><a href=\"javascript:void fold('$foldtype',1$foldnum)\" class='foldbutton fx$foldclass'$hidetitle>&minus;</a>";
}

function become_user_link($link, $text = "user") {
    global $Conf;
    if (is_object($link) && $link->seascode_username)
        $link = $link->seascode_username;
    else if (is_object($link))
        $link = $link->email;
    return "<a class=\"actas\" href=\"" . $Conf->selfurl(null, ["actas" => $link]) . "\">"
        . $Conf->cacheableImage("viewas.png", "[Become user]", "Act as " . htmlspecialchars($text)) . "</a>";
}

function highlightMatch($match, $text, &$n = null) {
    if ($match == "") {
        $n = 0;
        return $text;
    }
    if ($match[0] != "{")
        $match = "{(" . $match . ")}i";
    return preg_replace($match, "<span class='match'>\$1</span>", $text, -1, $n);
}

function decorateNumber($n) {
    if ($n < 0)
        return "&minus;" . (-$n);
    else if ($n > 0)
        return $n;
    else
        return 0;
}


function rm_rf_tempdir($tempdir) {
    assert(substr($tempdir, 0, 1) === "/");
    exec("/bin/rm -rf " . escapeshellarg($tempdir));
}

function clean_tempdirs() {
    $dir = null;
    if (function_exists("sys_get_temp_dir"))
        $dir = sys_get_temp_dir();
    if (!$dir)
        $dir = "/tmp";
    while (substr($dir, -1) == "/")
        $dir = substr($dir, 0, -1);
    $dirh = opendir($dir);
    $now = time();
    while (($fname = readdir($dirh)) !== false)
        if (preg_match('/\Ahotcrptmp\d+\z/', $fname)
            && is_dir("$dir/$fname")
            && ($mtime = @filemtime("$dir/$fname")) !== false
            && $mtime < $now - 1800)
            rm_rf_tempdir("$dir/$fname");
    closedir($dirh);
}

function tempdir($mode = 0700) {
    $dir = null;
    if (function_exists("sys_get_temp_dir"))
        $dir = sys_get_temp_dir();
    if (!$dir)
        $dir = "/tmp";
    while (substr($dir, -1) == "/")
        $dir = substr($dir, 0, -1);
    for ($i = 0; $i < 100; $i++) {
        $path = $dir . "/hotcrptmp" . mt_rand(0, 9999999);
        if (mkdir($path, $mode)) {
            register_shutdown_function("rm_rf_tempdir", $path);
            return $path;
        }
    }
    return false;
}


// text helpers
function commajoin($what, $joinword = "and") {
    $what = array_values($what);
    $c = count($what);
    if ($c == 0)
        return "";
    else if ($c == 1)
        return $what[0];
    else if ($c == 2)
        return $what[0] . " " . $joinword . " " . $what[1];
    else
        return join(", ", array_slice($what, 0, -1)) . ", " . $joinword . " " . $what[count($what) - 1];
}

function prefix_commajoin($what, $prefix, $joinword = "and") {
    return commajoin(array_map(function ($x) use ($prefix) {
        return $prefix . $x;
    }, $what), $joinword);
}

function numrangejoin($range) {
    $a = [];
    $format = null;
    $intval = $plen = 0;
    $first = $last = "0";
    foreach ($range as $current) {
        if ($format !== null
            && sprintf($format, $intval + 1) === (string) $current) {
            ++$intval;
            $last = $current;
            continue;
        } else {
            if ($format !== null) {
                $a[] = $first === $last ? $first : $first . "–" . substr($last, $plen);
            }
            if ($current !== "" && ctype_digit($current)) {
                $format = "%0" . strlen($current) . "d";
                $plen = 0;
                $first = $last = $current;
                $intval = intval($current);
            } else if (preg_match('/\A(\D*)(\d+)\z/', $current, $m)) {
                $format = str_replace("%", "%%", $m[1]) . "%0" . strlen($m[2]) . "d";
                $plen = strlen($m[1]);
                $first = $last = $current;
                $intval = intval($m[2]);
            } else {
                $format = null;
                $a[] = $current;
            }
        }
    }
    if ($format !== null && $first === $last) {
        $a[] = $first;
    } else if ($format !== null) {
        $a[] = $first . "–" . substr($last, $plen);
    }
    return commajoin($a);
}

function pluralx($n, $what) {
    if (is_array($n)) {
        $n = count($n);
    }
    return $n === 1 ? $what : pluralize($what);
}

function pluralize($what) {
    if ($what === "this") {
        return "these";
    } else if ($what === "has") {
        return "have";
    } else if ($what === "is") {
        return "are";
    } else if (str_ends_with($what, ")")
               && preg_match('/\A(.*?)(\s*\([^)]*\))\z/', $what, $m)) {
        return pluralize($m[1]) . $m[2];
    } else if (preg_match('/\A.*?(?:s|sh|ch|[bcdfgjklmnpqrstvxz]y)\z/', $what)) {
        if (substr($what, -1) === "y") {
            return substr($what, 0, -1) . "ies";
        } else {
            return $what . "es";
        }
    } else {
        return $what . "s";
    }
}

function plural($n, $what) {
    return (is_array($n) ? count($n) : $n) . ' ' . pluralx($n, $what);
}

function ordinal($n) {
    $x = $n;
    if ($x > 100) {
        $x = $x % 100;
    }
    if ($x > 20) {
        $x = $x % 10;
    }
    return $n . ($x < 1 || $x > 3 ? "th" : ($x == 1 ? "st" : ($x == 2 ? "nd" : "rd")));
}

function tabLength($text, $all) {
    $len = 0;
    for ($i = 0; $i < strlen($text); $i++) {
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

/** @param string $varname */
function ini_get_bytes($varname, $value = null) {
    $val = trim($value !== null ? $value : ini_get($varname));
    $last = strlen($val) ? strtolower($val[strlen($val) - 1]) : ".";
    /** @phan-suppress-next-line PhanParamSuspiciousOrder */
    return (int) ceil(floatval($val) * (1 << (+strpos(".kmg", $last) * 10)));
}


function hotcrp_random_password($length = 14) {
    $bytes = random_bytes($length + 10);
    if ($bytes === false) {
        $bytes = "";
        while (strlen($bytes) < $length)
            $bytes .= sha1(opt("conferenceKey") . pack("V", mt_rand()));
    }

    $l = "a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w trcrbrfrthdrchphwrstspswprslcl2 3 4 5 6 7 8 9 - @ _ + = ";
    $pw = "";
    $nvow = 0;
    for ($i = 0;
         $i < strlen($bytes) &&
             strlen($pw) < $length + max(0, ($nvow - 3) / 3);
         ++$i) {
        $x = ord($bytes[$i]) % (strlen($l) / 2);
        if ($x < 30)
            ++$nvow;
        $pw .= rtrim(substr($l, 2 * $x, 2));
    }
    return $pw;
}


function encode_token($x, $format = "") {
    $s = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $t = "";
    if (is_int($x))
        $format = "V";
    if ($format)
        $x = pack($format, $x);
    $i = 0;
    $have = 0;
    $n = 0;
    while ($have > 0 || $i < strlen($x)) {
        if ($have < 5 && $i < strlen($x)) {
            $n += ord($x[$i]) << $have;
            $have += 8;
            ++$i;
        }
        $t .= $s[$n & 31];
        $n >>= 5;
        $have -= 5;
    }
    if ($format == "V")
        return preg_replace('/(\AA|[^A])A*\z/', '$1', $t);
    else
        return $t;
}

function decode_token($x, $format = "") {
    $map = "//HIJKLMNO///////01234567/89:;</=>?@ABCDEFG";
    $t = "";
    $n = $have = 0;
    $x = trim(strtoupper($x));
    for ($i = 0; $i < strlen($x); ++$i) {
        $o = ord($x[$i]);
        if ($o >= 48 && $o <= 90 && ($out = ord($map[$o - 48])) >= 48)
            $o = $out - 48;
        else if ($o == 46 /*.*/ || $o == 34 /*"*/)
            continue;
        else
            return false;
        $n += $o << $have;
        $have += 5;
        while ($have >= 8 || ($n && $i == strlen($x) - 1)) {
            $t .= chr($n & 255);
            $n >>= 8;
            $have -= 8;
        }
    }
    if ($format == "V") {
        $x = unpack("Vx", $t . "\x00\x00\x00\x00\x00\x00\x00");
        return $x["x"];
    } else if ($format)
        return unpack($format, $t);
    else
        return $t;
}

function git_refname_is_full_hash($refname) {
    return strlen($refname) === 40
        && ctype_xdigit($refname)
        && strtolower($refname) === $refname;
}

function git_commit_in_list($list, $hash) {
    // XXX strtolower
    if ((string) $hash === "") {
        return false;
    } else if (strlen($hash) === 40 || strlen($hash) === 64) {
        return get($list, $hash);
    }
    $cx = null;
    foreach ($list as $h => $commit) {
        if (str_starts_with($h, $hash)) {
            if ($cx) {
                return false;
            }
            $cx = $commit;
        }
    }
    return $cx;
}

function round_grade($g) {
    return round($g * 1000) / 1000;
}
