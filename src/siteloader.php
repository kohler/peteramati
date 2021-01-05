<?php
// siteloader.php -- HotCRP autoloader
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class SiteLoader {
    static $map = [
        "CapabilityManager" => "src/capability.php",
        "CsvGenerator" => "lib/csv.php",
        "CsvParser" => "lib/csv.php",
        "Function_GradeFormula" => "src/gradeformula.php",
        "GitHubResponse" => "src/github_repositorysite.php",
        "LoginHelper" => "lib/login.php",
        "MimeText" => "lib/mailer.php",
        "Pset" => "src/psetconfig.php",
        "RunnerState" => "src/runner.php",
        "ZipDocument" => "lib/documenthelper.php"
    ];

    static $suffix_map = [
        "_api.php" => ["api_", "api"],
        "_gradeformula.php" => ["gf_", "gradeformulas"]
    ];

    /** @var string */
    static public $root;

    static function set_root() {
        global $ConfSitePATH;
        self::$root = substr(__FILE__, 0, strrpos(__FILE__, "/"));
        while (self::$root !== ""
               && !file_exists(self::$root . "/src/init.php")) {
            self::$root = substr(self::$root, 0, strrpos(self::$root, "/"));
        }
        if (self::$root === "") {
            self::$root = "/var/www/html";
        }
        $ConfSitePATH = self::$root;
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

    static private function expand_includes_once($file, $includepath, $globby) {
        foreach ($file[0] === "/" ? [""] : $includepath as $idir) {
            $try = $idir . $file;
            if (!$globby && is_readable($try)) {
                return [$try];
            } else if ($globby && ($m = glob($try, GLOB_BRACE))) {
                return $m;
            }
        }
        return [];
    }

    /** @param string|list<string> $files */
    static function expand_includes($files, $expansions = array()) {
        global $Opt;
        if (!is_array($files)) {
            $files = array($files);
        }
        $confname = $Opt["confid"] ?? $Opt["dbName"] ?? null;
        $expansions["confid"] = $expansions["confname"] = $confname;
        $expansions["siteclass"] = $Opt["siteclass"] ?? null;

        if (isset($expansions["autoload"]) && strpos($files[0], "/") === false) {
            $includepath = [self::$root . "/src/", self::$root . "/lib/"];
        } else {
            $includepath = [self::$root . "/"];
        }
        if (isset($Opt["includepath"]) && is_array($Opt["includepath"])) {
            foreach ($Opt["includepath"] as $i) {
                if ($i)
                    $includepath[] = str_ends_with($i, "/") ? $i : $i . "/";
            }
        }

        $results = array();
        foreach ($files as $f) {
            if (strpos((string) $f, '$') !== false) {
                foreach ($expansions as $k => $v) {
                    if ($v !== false && $v !== null) {
                        $f = preg_replace("/\\\$\\{{$k}\\}|\\\${$k}\\b/", $v, $f);
                    } else if (preg_match("/\\\$\\{{$k}\\}|\\\${$k}\\b/", $f)) {
                        $f = "";
                        break;
                    }
                }
            }
            if ((string) $f === "") {
                continue;
            }
            $matches = [];
            $ignore_not_found = $globby = false;
            if ($f[0] === "?") {
                $ignore_not_found = true;
                $f = substr($f, 1);
            }
            if (preg_match('/[\[\]\*\?\{\}]/', $f)) {
                $ignore_not_found = $globby = true;
            }
            $matches = self::expand_includes_once($f, $includepath, $globby);
            if (empty($matches)
                && isset($expansions["autoload"])
                && ($underscore = strpos($f, "_"))
                && ($f2 = SiteLoader::$suffix_map[substr($f, $underscore)] ?? null)) {
                $xincludepath = array_merge($f2[1] ? [self::$root."/src/{$f2[1]}/"] : [], $includepath);
                $matches = self::expand_includes_once($f2[0] . substr($f, 0, $underscore) . ".php", $xincludepath, $globby);
            }
            $results = array_merge($results, $matches);
            if (empty($matches) && !$ignore_not_found) {
                $results[] = $f[0] === "/" ? $f : $includepath[0] . $f;
            }
        }
        return $results;
    }

    static function autoloader($class_name) {
        $f = null;
        if (isset(self::$map[$class_name])) {
            $f = self::$map[$class_name];
        }
        if (!$f) {
            $f = strtolower($class_name) . ".php";
        }
        foreach (self::expand_includes($f, ["autoload" => true]) as $fx) {
            require_once($fx);
        }
    }
}

SiteLoader::set_root();
spl_autoload_register("SiteLoader::autoloader");
