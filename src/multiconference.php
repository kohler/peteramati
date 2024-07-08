<?php
// multiconference.php -- HotCRP multiconference installations
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Multiconference {
    /** @var ?array<string,mixed> */
    static private $original_opt;
    /** @var array<string,?Conf> */
    static private $conf_cache;

    /** @param ?string $confid */
    static function init($confid = null) {
        global $Opt, $argv;
        assert(self::$original_opt === null);
        self::$original_opt = $Opt;

        $confid = $confid ?? $Opt["confid"] ?? null;
        if ($confid === null && PHP_SAPI === "cli") {
            for ($i = 1; $i != count($argv); ++$i) {
                if ($argv[$i] === "-n" || $argv[$i] === "--name") {
                    if (isset($argv[$i + 1]))
                        $confid = $argv[$i + 1];
                    break;
                } else if (substr($argv[$i], 0, 2) === "-n") {
                    $confid = substr($argv[$i], 2);
                    break;
                } else if (substr($argv[$i], 0, 7) === "--name=") {
                    $confid = substr($argv[$i], 7);
                    break;
                } else if ($argv[$i] === "--") {
                    break;
                }
            }
        } else if ($confid === null && PHP_SAPI !== "cli") {
            $base = Navigation::base_absolute(true);
            if (($multis = $Opt["multiconferenceAnalyzer"] ?? null)) {
                foreach (is_array($multis) ? $multis : [$multis] as $multi) {
                    list($match, $replace) = explode(" ", $multi);
                    if (preg_match("`\\A{$match}`", $base, $m)) {
                        $confid = $replace;
                        for ($i = 1; $i < count($m); ++$i) {
                            $confid = str_replace("\${$i}", $m[$i], $confid);
                        }
                        break;
                    }
                }
            } else if (preg_match('/\/([^\/]+)\/\z/', $base, $m)) {
                $confid = $m[1];
            }
        }

        if (!$confid) {
            $Opt["confid"] = "__nonexistent__";
        } else if (preg_match('/\A[a-zA-Z0-9_][-a-zA-Z0-9_.]*\z/', $confid)) {
            $Opt["confid"] = $confid;
        } else {
            $Opt["__original_confid"] = $confid;
            $Opt["confid"] = "__invalid__";
        }

        self::assign_confid($Opt, $confid);
    }

    /** @param array<string,mixed> &$opt
     * @param string $confid */
    static function assign_confid(&$opt, $confid) {
        foreach (["dbName", "dbUser", "dbPassword", "dsn"] as $k) {
            if (isset($opt[$k]) && is_string($opt[$k]))
                $opt[$k] = str_replace('${confid}', $confid, $opt[$k]);
        }
        if (!($opt["dbName"] ?? null) && !($opt["dsn"] ?? null)) {
            $opt["dbName"] = $confid;
        }
        $opt["confid"] = $confid;
    }

    /** @param ?string $root
     * @param string $confid
     * @return ?Conf */
    static function get_conf($root, $confid) {
        if (self::$conf_cache === null) {
            self::$conf_cache = [];
            if (Conf::$main && ($xconfid = Conf::$main->opt("confid"))) {
                self::$conf_cache[SiteLoader::$root . "\0{$xconfid}"] = Conf::$main;
            }
        }
        $root = $root ?? SiteLoader::$root;
        $key = "{$root}\0{$confid}";
        if (!array_key_exists($key, self::$conf_cache)) {
            self::$conf_cache[$key] = self::load_conf($root, $confid);
        }
        return self::$conf_cache[$key];
    }

    /** @param ?string $root
     * @param string $confid
     * @return ?Conf */
    static function load_conf($root, $confid) {
        global $Opt;
        $save_opt = $Opt;
        '@phan-var array<string,mixed> $save_opt';
        $root = $root ?? SiteLoader::$root;
        if ($root === SiteLoader::$root && self::$original_opt !== null) {
            $Opt = self::$original_opt;
        } else {
            $Opt = [];
            SiteLoader::read_options_file("{$root}/conf/options.php");
        }
        self::assign_confid($Opt, $confid);
        if ($Opt["include"] ?? null) {
            SiteLoader::read_included_options();
        }
        $newconf = ($Opt["missing"] ?? null) ? null : new Conf($Opt, true);
        $Opt = $save_opt;
        return $newconf;
    }

    /** @deprecated */
    static function get_confid($confid) {
        return self::get_conf(null, $confid);
    }

    /** @deprecated */
    static function load_confid($confid) {
        return self::load_conf(null, $confid);
    }

    static function fail_message($errors) {
        global $Opt;
        $maintenance = $Opt["maintenance"] ?? null;

        if ($maintenance) {
            $errors = ["The site is down for maintenance. " . (is_string($maintenance) ? $maintenance : "Please check back later.")];
        } else {
            $errors = is_string($errors) ? [$errors] : $errors;
        }

        if (PHP_SAPI === "cli") {
            fwrite(STDERR, join("\n", $errors) . "\n");
            exit(1);
        }

        $qreq = Qrequest::$main_request ?? Qrequest::make_minimal();
        if (!$qreq->conf) {
            $qreq->set_conf(Conf::$main ?? new Conf($Opt, false));
        }

        if ($qreq->page() === "api" || ($_GET["ajax"] ?? null)) {
            $ctype = ($_GET["text"] ?? null) ? "text/plain" : "application/json";
            header("HTTP/1.1 404 Not Found");
            header("Content-Type: $ctype; charset=utf-8");
            if ($maintenance) {
                echo "{\"error\":\"maintenance\"}\n";
            } else {
                echo "{\"error\":\"unconfigured installation\"}\n";
            }
        } else {
            Contact::set_main_user(null);
            header("HTTP/1.1 404 Not Found");
            $qreq->conf()->header("HotCRP Error", "", ["action_bar" => false]);
            foreach ($errors as $i => &$e) {
                $e = ($i ? "<div class=\"hint\">" : "<p>") . htmlspecialchars($e) . ($i ? "</div>" : "</p>");
            }
            echo join("", $errors);
            $qreq->conf()->footer();
        }
        exit;
    }

    /** @return string */
    static private function nonexistence_error() {
        if (PHP_SAPI === "cli") {
            return "This is a multiconference installation. Use `-n CONFID` to specify a conference.";
        } else {
            return "Conference not specified.";
        }
    }

    static function fail_bad_options() {
        global $Opt;
        if (isset($Opt["multiconferenceFailureCallback"])) {
            call_user_func($Opt["multiconferenceFailureCallback"], "options");
        }
        $errors = [];
        $confid = $Opt["confid"] ?? null;
        $multiconference = $Opt["multiconference"] ?? null;
        if ($multiconference && $confid === "__nonexistent__") {
            $errors[] = self::nonexistence_error();
        } else if ($multiconference) {
            $errors[] = "The “{$confid}” conference does not exist. Check your URL to make sure you spelled it correctly.";
        } else if (!($Opt["loaded"] ?? false)) {
            $errors[] = "HotCRP has been installed, but not yet configured. You must run `lib/createdb.sh` to create a database for your conference. See `README.md` for further guidance.";
        } else {
            $errors[] = "HotCRP was unable to load. A system administrator must fix this problem.";
        }
        if (!($Opt["loaded"] ?? false) && defined("HOTCRP_OPTIONS")) {
            $errors[] = "Error: Unable to load options file `" . HOTCRP_OPTIONS . "`";
        } else if (!($Opt["loaded"] ?? false)) {
            $errors[] = "Error: Unable to load options file";
        }
        if (isset($Opt["missing"]) && $Opt["missing"]) {
            $missing = array_filter($Opt["missing"], function ($x) {
                return strpos($x, "__nonexistent__") === false;
            });
            if (!empty($missing)) {
                $errors[] = "Error: Unable to load options from " . commajoin($missing);
            }
        }
        self::fail_message($errors);
    }

    static function fail_bad_database() {
        global $Opt;
        if (isset($Opt["multiconferenceFailureCallback"])) {
            call_user_func($Opt["multiconferenceFailureCallback"], "database");
        }
        $errors = [];
        $confid = $Opt["confid"] ?? null;
        $multiconference = $Opt["multiconference"] ?? null;
        if ($multiconference && $confid === "__nonexistent__") {
            $errors[] = self::nonexistence_error();
        } else if ($multiconference) {
            $errors[] = "The “{$confid}” conference does not exist. Check your URL to make sure you spelled it correctly.";
        } else {
            $errors[] = "Peteramati was unable to load. A system administrator must fix this problem.";
            if (defined("HOTCRP_TESTHARNESS")) {
                $errors[] = "You may need to run `lib/createdb.sh -c test/options.php` to create the database.";
            }
            if (($cp = Dbl::parse_connection_params($Opt))) {
                error_log("Unable to connect to database " . $cp->sanitized_dsn());
            } else {
                error_log("Unable to connect to database");
            }
        }
        self::fail_message($errors);
    }

    /** @param Throwable $ex
     * @suppress PhanUndeclaredProperty */
    static function batch_exception_handler($ex) {
        global $argv;
        $s = $ex->getMessage();
        if (defined("HOTCRP_TESTHARNESS")) {
            $s = $ex->getFile() . ":" . $ex->getLine() . ": " . $s;
        }
        if (strpos($s, ":") === false) {
            $script = $argv[0] ?? "";
            if (($slash = strrpos($script, "/")) !== false) {
                if (($slash === 5 && str_starts_with($script, "batch"))
                    || ($slash > 5 && substr_compare($script, "/batch", $slash - 6, 6) === 0)) {
                    $slash -= 6;
                }
                $script = substr($script, $slash + 1);
            }
            if ($script !== "") {
                $s = "{$script}: {$s}";
            }
        }
        if (substr($s, -1) !== "\n") {
            $s = "{$s}\n";
        }
        if (property_exists($ex, "context")
            && is_array($ex->context)) {
            foreach ($ex->context as $c) {
                $i = 0;
                while ($i !== strlen($c) && $c[$i] === " ") {
                    ++$i;
                }
                $s .= prefix_word_wrap(str_repeat(" ", $i + 2), trim($c), 2);
            }
        }
        if (property_exists($ex, "getopt")
            && $ex->getopt instanceof Getopt) {
            $s .= $ex->getopt->short_usage();
        }
        if (defined("HOTCRP_TESTHARNESS") || $ex instanceof Error) {
            $s .= debug_string_backtrace($ex) . "\n";
        }
        fwrite(STDERR, $s);
        if (property_exists($ex, "exitStatus")
            && is_int($ex->exitStatus)) {
            exit($ex->exitStatus);
        } else {
            exit(3);
        }
    }
}
