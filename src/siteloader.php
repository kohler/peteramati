<?php
// siteloader.php -- HotCRP autoloader
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SiteLoader {
    static $map = [
        "CapabilityManager" => "src/capability.php",
        "CommandLineException" => "lib/getopt.php",
        "CsvGenerator" => "lib/csv.php",
        "CsvParser" => "lib/csv.php",
        "Function_GradeFormula" => "src/gradeformula.php",
        "GitHubResponse" => "src/github_repositorysite.php",
        "LoginHelper" => "lib/login.php",
        "MessageItem" => "lib/messageset.php",
        "MimeText" => "lib/mailer.php",
        "Pset" => "src/psetconfig.php",
        "ZipDocument" => "lib/documenthelper.php",
        "Collator" => "lib/collatorshim.php"
    ];

    static $suffix_map = [
        "_api.php" => ["api_", "src"],
        "_gradeformula.php" => ["gf_", "src"],
        "_page.php" => ["p_", "src"]
    ];

    /** @var string */
    static public $root; // does not end in `/`

    static function set_root() {
        self::$root = __DIR__;
        while (self::$root !== ""
               && !file_exists(self::$root . "/src/init.php")) {
            self::$root = substr(self::$root, 0, strrpos(self::$root, "/"));
        }
        if (self::$root === "") {
            self::$root = "/var/www/html";
        }
        assert(strcspn(self::$root, "[]?*\\") == strlen(self::$root));
    }

    /** @param non-empty-string $suffix
     * @return string */
    static function find($suffix) {
        if ($suffix[0] === "/") {
            return self::$root . $suffix;
        } else {
            return self::$root . "/" . $suffix;
        }
    }

    // Set up conference options
    /** @param string $file
     * @param list<string> $includepath
     * @param bool $globby
     * @return list<string> */
    static private function expand_includes_once($file, $includepath, $globby) {
        foreach ($file[0] === "/" ? [""] : $includepath as $idir) {
            if ($file[0] === "/") {
                $try = $file;
            } else if (!$idir) {
                continue;
            } else if (str_ends_with($idir, "/")) {
                $try = "{$idir}{$file}";
            } else {
                $try = "{$idir}/{$file}";
            }
            if (!$globby && is_readable($try)) {
                return [$try];
            } else if ($globby && ($m = glob($try, GLOB_BRACE))) {
                return $m;
            } else if ($file[0] === "/") {
                return [];
            }
        }
        return [];
    }

    /** @param string $s
     * @param array<string,string> $expansions
     * @param int $pos
     * @param bool $useOpt
     * @return string */
    static function substitute($s, $expansions, $pos = 0, $useOpt = false) {
        global $Opt;
        while (($pos = strpos($s, '${', $pos)) !== false) {
            $rbrace = strpos($s, '}', $pos + 2);
            if ($rbrace === false
                || ($key = substr($s, $pos + 2, $rbrace - $pos - 2)) === "") {
                $pos += 2;
                continue;
            }
            if (array_key_exists($key, $expansions)) {
                $value = $expansions[$key];
            } else if ($useOpt && ($key === "confid" || $key === "confname")) {
                $value = $Opt["confid"] ?? $Opt["dbName"] ?? null;
            } else if ($useOpt && $key === "siteclass") {
                $value = $Opt["siteclass"] ?? null;
            } else {
                $pos += 2;
                continue;
            }
            if ($value === false || $value === null) {
                return "";
            }
            $s = substr($s, 0, $pos) . $value . substr($s, $rbrace + 1);
            $pos += strlen($value);
        }
        return $s;
    }

    /** @param ?string $root
     * @param string|list<string> $files
     * @param array<string,string> $expansions
     * @return list<string> */
    static function expand_includes($root, $files, $expansions = []) {
        global $Opt;

        if (empty($files)) {
            return [];
        } else if (!is_array($files)) {
            $files = [$files];
        }

        $root = $root ?? self::$root;
        $autoload = $expansions["autoload"] ?? 0;
        $includepath = null;

        $results = [];
        foreach (is_array($files) ? $files : [$files] as $f) {
            $f = (string) $f;
            if ($f !== "" && !$autoload && ($pos = strpos($f, '${')) !== false) {
                $f = self::substitute($f, $expansions, $pos, true);
            }
            if ($f === "") {
                continue;
            }

            $ignore_not_found = !$autoload && $f[0] === "?";
            $globby = false;
            if ($ignore_not_found) {
                $f = substr($f, 1);
            }
            if (!$autoload && strpbrk($f, "[]*?{}") !== false) {
                $ignore_not_found = $globby = true;
            }

            $f2 = null;
            $matches = [];
            if ($autoload && strpos($f, "/") === false) {
                if (($underscore = strrpos($f, "_"))
                    && ($pfxsubdir = SiteLoader::$suffix_map[substr($f, $underscore)] ?? null)) {
                    $f2 = $pfxsubdir[0] . substr($f, 0, $underscore) . ".php";
                    if (is_readable(($fx = "{$root}/{$pfxsubdir[1]}/{$f2}"))) {
                        return [$fx];
                    }
                }
                if (is_readable(($fx = "{$root}/lib/{$f}"))
                    || is_readable(($fx = "{$root}/src/{$f}"))) {
                    return [$fx];
                }
            } else if (!$globby
                       && !str_starts_with($f, "/")
                       && is_readable(($fx = "{$root}/{$f}"))) {
                $matches = [$fx];
            } else {
                $matches = self::expand_includes_once($f, [$root], $globby);
            }
            if (empty($matches) && $includepath === null) {
                global $Opt;
                $includepath = $Opt["includePath"] ?? $Opt["includepath"] ?? [];
            }
            if (empty($matches) && !empty($includepath)) {
                if ($f2 !== null) {
                    $matches = self::expand_includes_once($f2, $includepath, false);
                }
                if (empty($matches)) {
                    $matches = self::expand_includes_once($f, $includepath, $globby);
                }
            }
            if (empty($matches) && !$ignore_not_found) {
                $matches = [$f[0] === "/" ? $f : "{$root}/{$f}"];
            }
            $results = array_merge($results, $matches);
        }
        return $results;
    }

    /** @param ?string $root
     * @param null|string|list<string> $files */
    static function require_includes($root, $files) {
        foreach (self::expand_includes($root, $files) as $f) {
            require_once $f;
        }
    }

    /** @param string $file */
    static function read_options_file($file) {
        global $Opt;
        if ((@include $file) !== false) {
            $Opt["loaded"][] = $file;
        } else {
            $Opt["missing"][] = $file;
        }
    }

    static function read_main_options() {
        global $Opt;
        $Opt = $Opt ?? [];
        $file = defined("PETERAMATI_OPTIONS") ? PETERAMATI_OPTIONS : "conf/options.php";
        if (!str_starts_with($file, "/")) {
            $file = self::$root . "/{$file}";
        }
        self::read_options_file($file);
        if (empty($Opt["missing"]) && !empty($Opt["include"])) {
            self::read_included_options();
        }
    }

    /** @param ?string $root */
    static function read_included_options($root = null) {
        global $Opt;
        '@phan-var array<string,mixed> $Opt';
        if (is_string($Opt["include"])) {
            $Opt["include"] = [$Opt["include"]];
        }
        $root = $root ?? self::$root;
        for ($i = 0; $i !== count($Opt["include"]); ++$i) {
            foreach (self::expand_includes($root, $Opt["include"][$i]) as $f) {
                if (!in_array($f, $Opt["loaded"])) {
                    self::read_options_file($f);
                }
            }
        }
    }

    /** @param string $class_name */
    static function autoload($class_name) {
        $f = self::$map[$class_name] ?? strtolower($class_name) . ".php";
        foreach (self::expand_includes(self::$root, $f, ["autoload" => true]) as $fx) {
            require_once($fx);
        }
    }
}

SiteLoader::set_root();
spl_autoload_register("SiteLoader::autoload");
