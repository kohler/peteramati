<?php
// json.php -- HotCRP JSON function replacements (if PHP JSON not available)
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

@define("JSON_ERROR_NONE", 0);
@define("JSON_ERROR_DEPTH", 1);
@define("JSON_ERROR_STATE_MISMATCH", 2);
@define("JSON_ERROR_CTRL_CHAR", 3);
@define("JSON_ERROR_SYNTAX", 4);
@define("JSON_ERROR_UTF8", 5);
@define("JSON_ERROR_EMPTY_KEY", 100);

@define("JSON_FORCE_OBJECT", 1);
@define("JSON_PRETTY_PRINT", 8);
@define("JSON_UNESCAPED_SLASHES", 16);
@define("JSON_UNESCAPED_UNICODE", 32);

define("JSON_HOTCRP", 1);

if (!interface_exists("JsonSerializable")) {
    interface JsonSerializable {
        public function jsonSerialize();
    }
}

class Json {
    static $string_map =
        array("\\" => "\\\\", "\"" => "\\\"", "/" => "\\/",
              "\000" => "\\u0000", "\001" => "\\u0001", "\002" => "\\u0002",
              "\003" => "\\u0003", "\004" => "\\u0004", "\005" => "\\u0005",
              "\006" => "\\u0006", "\007" => "\\u0007", "\010" => "\\b",
              "\011" => "\\t", "\012" => "\\n", "\013" => "\\u000B",
              "\014" => "\\f", "\015" => "\\r", "\016" => "\\u000E",
              "\017" => "\\u000F", "\020" => "\\u0010", "\021" => "\\u0011",
              "\022" => "\\u0012", "\023" => "\\u0013", "\024" => "\\u0014",
              "\025" => "\\u0015", "\026" => "\\u0016", "\027" => "\\u0017",
              "\030" => "\\u0018", "\031" => "\\u0019", "\032" => "\\u001A",
              "\033" => "\\u001B", "\034" => "\\u001C", "\035" => "\\u001D",
              "\036" => "\\u001E", "\037" => "\\u001F");

    static private $string_unmap =
        array("\\\"" => "\"", "\\\\" => "\\", "\\/" => "/",
              "\\b" => "\010", "\\t" => "\011", "\\n" => "\012",
              "\\f" => "\014", "\\r" => "\015");

    static private $error_type;
    static private $error_input;
    static private $error_position;
    static private $error_line;
    static private $error_description;

    private static function set_error(&$x, $etype, $desc = null) {
        if ($x !== null && !self::$error_type) {
            self::$error_type = $etype;
            $prefix = substr(self::$error_input, 0,
                             strlen(self::$error_input) - strlen($x));
            self::$error_position = strlen($prefix);
            self::$error_line = 1 + preg_match_all(',\r\n?|\n,s', $prefix);
            self::$error_description = $desc;
        }
        return ($x = null);
    }

    private static function landmark(&$x, $filename) {
        if ($x !== null) {
            $prefix = substr(self::$error_input, 0,
                             strlen(self::$error_input) - strlen($x));
            return $filename . ":" . (1 + preg_match_all(',\r\n?|\n,s', $prefix)) . ":" . strlen($prefix);
        } else
            return null;
    }


    static function decode_escape($e) {
        if (is_array($e))
            $e = $e[0];
        if ($e[1] === "u") {
            $v = intval(substr($e, 2), 16);
            if ($v < 0x80)
                return chr($v);
            else if ($v < 0x800)
                return chr(0xC0 + ($v >> 6)) . chr(0x80 + ($v & 0x3F));
            else if ($v < 0x10000)
                return chr(0xE0 + ($v >> 12)) . chr(0x80 + (($v >> 6) & 0x3F))
                    . chr(0x80 + ($v & 0x3F));
            else
                return chr(0xF0 + ($v >> 18)) . chr(0x80 + (($v >> 12) & 0x3F))
                    . chr(0x80 + (($v >> 6) & 0x3F)) . chr(0x80 + ($v & 0x3F));
        } else
            return self::$string_unmap[$e];
    }


    private static function decode_part(&$x, $assoc, $depth, $options) {
        $x = ltrim($x);
        if ($x === "")
            return self::set_error($x, JSON_ERROR_SYNTAX);
        else if (substr($x, 0, 4) === "null") {
            $x = substr($x, 4);
            return null;
        } else if (substr($x, 0, 5) === "false") {
            $x = substr($x, 5);
            return false;
        } else if (substr($x, 0, 4) === "true") {
            $x = substr($x, 4);
            return true;
        } else if ($x[0] === "\"") {
            preg_match(',\A"((?:[^\\\\"\000-\037]|\\\\["\\\\/bfnrt]|\\\\u[0-9a-fA-F]{4})*)(.*)\z,s', $x, $m);
            if ($m[2][0] === "\"") {
                $x = substr($m[2], 1);
                return preg_replace_callback(',\\\\(?:["\\\\/bfnrt]|u[0-9a-fA-F]{4}),', 'Json::decode_escape', $m[1]);
            } else {
                $x = $m[2];
                return self::set_error($x, JSON_ERROR_SYNTAX);
            }
        } else if ($x[0] === "{") {
            if ($depth < 0)
                return self::set_error($x, JSON_ERROR_DEPTH);
            $arr = $assoc ? array() : (object) array();
            $x = substr($x, 1);
            $n = 0;
            while (1) {
                if (!is_string($x))
                    return self::set_error($x, JSON_ERROR_SYNTAX);
                $x = ltrim($x);
                if ($x[0] === "}") {
                    $x = substr($x, 1);
                    break;
                } else if ($n) {
                    if ($x[0] !== ",")
                        return self::set_error($x, JSON_ERROR_SYNTAX);
                    $x = substr($x, 1);
                }

                $k = self::decode_part($x, $assoc, $depth - 1, $options);
                if (!is_string($k) || !is_string($x))
                    return self::set_error($x, JSON_ERROR_SYNTAX);
                if ($k === "" && is_object($arr))
                    return self::set_error($x, JSON_ERROR_EMPTY_KEY);

                $x = ltrim($x);
                if ($x[0] !== ":")
                    return self::set_error($x, JSON_ERROR_SYNTAX);
                $x = substr($x, 1);
                $v = self::decode_part($x, $assoc, $depth - 1, $options);

                is_array($arr) ? ($arr[$k] = $v) : ($arr->{$k} = $v);
                ++$n;
            }
            return $arr;
        } else if ($x[0] === "[") {
            if ($depth < 0)
                return self::set_error($x, JSON_ERROR_DEPTH);
            $arr = array();
            $x = substr($x, 1);
            while (1) {
                if (!is_string($x))
                    return self::set_error($x, JSON_ERROR_SYNTAX);
                $x = ltrim($x);
                if ($x[0] === "]") {
                    $x = substr($x, 1);
                    break;
                } else if (count($arr)) {
                    if ($x[0] !== ",")
                        return self::set_error($x, JSON_ERROR_SYNTAX);
                    $x = substr($x, 1);
                }

                $v = self::decode_part($x, $assoc, $depth - 1, $options);
                $arr[] = $v;
            }
            return $arr;
        } else if (preg_match('/\A(-?(?:0|[1-9]\d*))((?:\.\d+)?(?:[Ee][-+]?\d+)?)(.*)\z/s', $x, $m)) {
            $x = $m[3];
            return $m[2] ? floatval($m[1] . $m[2]) : intval($m[1]);
        } else if ($x[0] === "]" || $x[0] === "}")
            return self::set_error($x, JSON_ERROR_STATE_MISMATCH);
        else if (ord($x[0]) < 32)
            return self::set_error($x, JSON_ERROR_CTRL_CHAR);
        else
            return self::set_error($x, JSON_ERROR_SYNTAX);
    }

    static function encode_escape($x) {
        if (is_array($x))
            $x = $x[0];
        return self::$string_map[$x];
    }

    // XXX not a full emulation of json_encode(); hopefully that won't matter
    // in the fullness of time
    static function encode($x, $options = 0) {
        if ($x instanceof JsonSerializable)
            $x = $x->jsonSerialize();
        if ($x === null)
            return "null";
        else if ($x === false)
            return "false";
        else if ($x === true)
            return "true";
        else if (is_int($x) || is_float($x))
            return (string) $x;
        else if (is_string($x)) {
            $pat = ($options & JSON_UNESCAPED_SLASHES) ? '/[\\"\000-\037]/' : ',[\\"/\000-\037],';
            return "\"" . preg_replace_callback($pat, "Json::encode_escape", $x) . "\"";
        } else if (is_object($x) || is_array($x)) {
            $as_object = null;
            $as_array = array();
            $nextkey = 0;
            foreach ($x as $k => $v) {
                if ((!is_int($k) && !is_string($k))
                    || ($v = self::encode($v, $options)) === null)
                    continue;
                if ($as_array !== null && $k !== $nextkey) {
                    $as_object = array();
                    foreach ($as_array as $kk => $vv)
                        $as_object[] = "\"$kk\":$vv";
                    $as_array = null;
                }
                if ($as_array === null)
                    $as_object[] = self::encode((string) $k) . ":" . $v;
                else {
                    $as_array[] = $v;
                    ++$nextkey;
                }
            }
            if ($as_array === null)
                return "{" . join(",", $as_object) . "}";
            else if (count($as_array) == 0)
                return (is_object($x) || ($options & JSON_FORCE_OBJECT)) ? "{}" : "[]";
            else
                return "[" . join(",", $as_array) . "]";
        } else
            return null;
    }

    static function decode($x, $assoc = false, $depth = 512, $options = 0) {
        self::$error_type = JSON_ERROR_NONE;
        self::$error_input = $x;
        $v = self::decode_part($x, $assoc, $depth, $options);
        if ($x !== null && $x !== false && !ctype_space($x))
            self::set_error($x, JSON_ERROR_SYNTAX, var_export($x, true));
        self::$error_input = null;
        return $x === null ? null : $v;
    }


    private static function decode_landmarks_part(&$x, $filename, $assoc, $depth) {
        $x = ltrim($x);
        if ($x === "")
            return self::set_error($x, JSON_ERROR_SYNTAX);
        else if (preg_match('/\A(?:null|false|true|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[Ee][-+]?\d+)?)(.*)\z/s', $x, $m)) {
            $l = self::landmark($x, $filename);
            $x = $m[1];
            return $l;
        } else if ($x[0] === "\"") {
            $l = self::landmark($x, $filename);
            preg_match(',\A"((?:[^\\\\"\000-\037]|\\\\["\\\\/bfnrt]|\\\\u[0-9a-fA-F]{4})*)(.*)\z,s', $x, $m);
            if ($m[2][0] === "\"") {
                $x = substr($m[2], 1);
                return $l;
            } else {
                $x = $m[2];
                return self::set_error($x, JSON_ERROR_SYNTAX);
            }
        } else if ($x[0] === "{") {
            $l = self::landmark($x, $filename);
            if ($depth < 0)
                return self::set_error($x, JSON_ERROR_DEPTH);
            $arr = $assoc ? array() : (object) array();
            $x = substr($x, 1);
            $n = 0;
            while (1) {
                if (!is_string($x))
                    return self::set_error($x, JSON_ERROR_SYNTAX);
                $x = ltrim($x);
                if ($x[0] === "}") {
                    $x = substr($x, 1);
                    break;
                } else if ($n) {
                    if ($x[0] !== ",")
                        return self::set_error($x, JSON_ERROR_SYNTAX);
                    $x = substr($x, 1);
                }

                $k = self::decode_part($x, $assoc, $depth - 1, 0);
                if (!is_string($k) || !is_string($x))
                    return self::set_error($x, JSON_ERROR_SYNTAX);
                if ($k === "" && is_object($arr))
                    return self::set_error($x, JSON_ERROR_EMPTY_KEY);

                $x = ltrim($x);
                if ($x[0] !== ":")
                    return self::set_error($x, JSON_ERROR_SYNTAX);
                $x = substr($x, 1);
                $v = self::decode_landmarks_part($x, $filename, $assoc, $depth - 1);

                is_array($arr) ? ($arr[$k] = $v) : ($arr->{$k} = $v);
                ++$n;
            }
            is_array($arr) ? ($arr["__LANDMARK__"] = $l) : ($arr->__LANDMARK__ = $l);
            return $arr;
        } else if ($x[0] === "[") {
            $l = self::landmark($x, $filename);
            if ($depth < 0)
                return self::set_error($x, JSON_ERROR_DEPTH);
            $arr = array();
            $x = substr($x, 1);
            while (1) {
                if (!is_string($x))
                    return self::set_error($x, JSON_ERROR_SYNTAX);
                $x = ltrim($x);
                if ($x[0] === "]") {
                    $x = substr($x, 1);
                    break;
                } else if (count($arr)) {
                    if ($x[0] !== ",")
                        return self::set_error($x, JSON_ERROR_SYNTAX);
                    $x = substr($x, 1);
                }

                $v = self::decode_landmarks_part($x, $filename, $assoc, $depth - 1);
                $arr[] = $v;
            }
            return $arr;
        } else if ($x[0] === "]" || $x[0] === "}")
            return self::set_error($x, JSON_ERROR_STATE_MISMATCH);
        else if (ord($x[0]) < 32)
            return self::set_error($x, JSON_ERROR_CTRL_CHAR);
        else
            return self::set_error($x, JSON_ERROR_SYNTAX);
    }

    static function decode_landmarks($x, $filename, $assoc = false, $depth = 512) {
        self::$error_type = JSON_ERROR_NONE;
        self::$error_input = $x;
        $v = self::decode_landmarks_part($x, $filename, $assoc, $depth);
        if ($x !== null && $x !== false && !ctype_space($x))
            self::set_error($x, JSON_ERROR_SYNTAX, var_export($x, true));
        self::$error_input = null;
        return $x === null ? null : $v;
    }


    static function last_error() {
        return self::$error_type;
    }

    static function last_error_msg() {
        static $errors =
            array(JSON_ERROR_NONE => null,
                  JSON_ERROR_DEPTH => "Maximum stack depth exceeded",
                  JSON_ERROR_STATE_MISMATCH => "Underflow or the modes mismatch",
                  JSON_ERROR_CTRL_CHAR => "Unexpected control character found",
                  JSON_ERROR_SYNTAX => "Syntax error, malformed JSON",
                  JSON_ERROR_UTF8 => "Malformed UTF-8 characters, possibly incorrectly encoded",
                  JSON_ERROR_EMPTY_KEY => "Empty keys are not supported");
        $error = self::last_error();
        $error = array_key_exists($error, $errors) ? $errors[$error] : "Unknown error ({$error})";
        if ($error) {
            $error .= " at character " . self::$error_position;
            if (self::$error_line != 1)
                $error .= ", line " . self::$error_line;
            if (self::$error_description)
                $error .= " [" . self::$error_description . "]";
        }
        return $error;
    }
}

if (!function_exists("json_encode")) {
    function json_encode($x, $options = 0) {
        return Json::encode($x, $options);
    }
}
if (!function_exists("json_decode")) {
    function json_decode($x, $assoc = false, $depth = 512, $options = 0) {
        return Json::decode($x, $assoc, $depth, $options);
    }
    function json_last_error() {
        return Json::last_error();
    }
    function json_last_error_msg() {
        return Json::last_error_msg();
    }
}
if (!function_exists("json_last_error")) {
    function json_last_error() {
        return false;
    }
}
if (!function_exists("json_last_error_msg")) {
    function json_last_error_msg() {
        return false;
    }
}
