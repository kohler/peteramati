<?php
// navigation.php -- HotCRP navigation helper functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class NavigationState {
    // Base URL:    PROTOCOL://HOST[:PORT]/BASEPATH/
    // Site URL:    PROTOCOL://HOST[:PORT]/BASEPATH/[u/NNN/]
    // Current URL: PROTOCOL://HOST[:PORT]/SITEPATH/PAGE/PATH?QUERY
    /** @var string */
    public $protocol;           // "PROTOCOL://"
    /** @var string */
    public $host;               // "HOST"
    /** @var string */
    public $server;             // "PROTOCOL://HOST[:PORT]"
    /** @var string */
    public $site_path;          // "/SITEPATH/"; always ends in /
    /** @var string */
    public $site_path_relative; // "/SITEPATH/", "../"+, or ""
    /** @var string */
    public $base_path;          // "/BASEPATH/"; always ends in /; prefix of $site_path
    /** @var string */
    public $base_path_relative; // "/BASEPATH/", "../"+, or ""
    /** @var string */
    public $page;               // "PAGE" or "index" (.php suffix stripped)
    /** @var string */
    public $raw_page;           // "PAGE" or "", with .php suffix if given
    /** @var string */
    public $path;               // "/PATH" or ""
    /** @var string */
    public $shifted_path;
    /** @var string */
    public $query;              // "?QUERY" or ""
    /** @var string */
    public $php_suffix;
    /** @var string */
    public $request_uri;

    // server variables:
    //   required: SERVER_PORT, SCRIPT_FILENAME, SCRIPT_NAME, REQUEST_URI
    //   optional: HTTP_HOST, SERVER_NAME, HTTPS, REQUEST_SCHEME

    function __construct($server) {
        if (!$server) {
            return;
        }

        $http_host = $server["HTTP_HOST"] ?? null;
        $this->host = $http_host ?? $server["SERVER_NAME"] ?? null;
        if ((isset($server["HTTPS"])
             && $server["HTTPS"] !== ""
             && $server["HTTPS"] !== "off")
            || ($server["HTTP_X_FORWARDED_PROTO"] ?? null) === "https"
            || ($server["REQUEST_SCHEME"] ?? null) === "https") {
            $x = "https://";
            $xport = 443;
        } else {
            $x = "http://";
            $xport = 80;
        }
        $this->protocol = $x;
        $x .= $this->host ? : "localhost";
        if ($http_host === null // HTTP `Host` header should contain port
            && strpos($x, ":", 6) === false
            && ($port = $server["SERVER_PORT"])
            && $port != $xport) {
            $x .= ":" . $port;
        }
        $this->server = $x;
        $this->request_uri = $server["REQUEST_URI"];

        // NGINX and Apache in different modes supply all kinds of nonsense.

        // $sname: URL-decoded path by which server found this script;
        // derived from SCRIPT_NAME
        $sname = $server["SCRIPT_NAME"]; // URL-decoded
        $sfilename = $server["SCRIPT_FILENAME"];
        // Apache proxy in `ProxyFCGIBackendType GENERIC` mode
        // may append path_info to SCRIPT_NAME; must remove it
        if (isset($server["ORIG_SCRIPT_FILENAME"])
            && !isset($server["ORIG_SCRIPT_NAME"])) {
            $orig_sfilename = $server["ORIG_SCRIPT_FILENAME"];
            if (strlen($orig_sfilename) > strlen($sfilename)
                && str_starts_with($orig_sfilename, $sfilename)) {
                $spath = substr($orig_sfilename, strlen($sfilename));
                $nslash = substr_count($sname, "/") - substr_count($spath, "/");
                $pos = 0;
                while ($nslash > 0) {
                    $pos = strpos($sname, "/", $pos + 1);
                    --$nslash;
                }
                $sname = substr($sname, 0, $pos);
            }
        }

        // $this->base_path: URL-encoded, prefix of $this->request_uri
        // Derived from $sbpath, which might be URL-decoded
        $sbpath = $sname;
        $sfile = substr($sfilename, strrpos($sfilename, "/") + 1);
        $pos = strlen($sbpath) - strlen($sfile) - 1;
        if ($pos >= 0
            && $sbpath[$pos] === "/"
            && substr($sbpath, $pos + 1) === $sfile) {
            $sbpath = substr($sbpath, 0, $pos);
        }
        $sblen = strlen($sbpath);
        if (substr($this->request_uri, 0, $sblen) !== $sbpath) {
            $sblen = strpos($this->request_uri, "/");
            $nslash = substr_count($sbpath, "/");
            while ($nslash > 0) {
                $sblen = strpos($this->request_uri, "/", $pos + 1);
                --$nslash;
            }
        }
        $this->base_path = substr($this->request_uri, 0, $sblen + 1);

        // semi-URL-decode suffix of request URI, decoding safe characters [-A-Za-z0-9._,@~]
        // (This is generally done for us but just to be safe.)
        $uri_suffix = preg_replace_callback('/%(?:2[CDEcde]|3[0-9]|4[0-9A-Fa-f]|5[0-9AaFf]|6[1-9A-Fa-f]|7[0-9AEae])/', function ($m) {
            return urldecode($m[0]);
        }, substr($this->request_uri, $sblen + 1));

        // $this->query: query string
        if (($qpos = strpos($uri_suffix, "?")) === false) {
            $qpos = strlen($uri_suffix);
        }
        if (($hpos = strpos($uri_suffix, "#")) !== false && $hpos < $qpos) {
            $qpos = $hpos;
        }
        if ($qpos < strlen($uri_suffix)) {
            $this->query = substr($uri_suffix, $qpos);
            $uri_suffix = substr($uri_suffix, 0, $qpos);
        } else {
            $this->query = "";
        }
        // now $uri_suffix is everything after $this->base_path and before
        // $this->query_string

        // separate $this->page and $this->path
        if (($spos = strpos($uri_suffix, "/")) === false) {
            $spos = strlen($uri_suffix);
        }
        if ($spos === 0) {
            $this->raw_page = "";
            $this->page = "index";
            $this->path = "";
        } else {
            $this->raw_page = substr($uri_suffix, 0, $spos);
            $this->page = $this->raw_page;
            if (($plen = strlen($this->page)) > 4
                && substr($this->page, $plen - 4) === ".php") {
                $this->page = substr($this->page, 0, $plen - 4);
            }
            $this->path = substr($uri_suffix, $spos);
        }
        $this->shifted_path = "";

        // detect $site_path_relative
        $path_slash = substr_count($this->path, "/");
        if ($path_slash > 0) {
            $this->base_path_relative = str_repeat("../", $path_slash);
        } else if ($this->raw_page === "") {
            $this->base_path_relative = $this->base_path;
        } else {
            $this->base_path_relative = "";
        }

        // $this->site_path: initially $this->base_path
        $this->site_path = $this->base_path;
        $this->site_path_relative = $this->base_path_relative;

        if (isset($server["HOTCRP_PHP_SUFFIX"])) {
            $this->php_suffix = $server["HOTCRP_PHP_SUFFIX"];
        } else if (!function_exists("apache_get_modules")
                   || array_search("mod_rewrite", apache_get_modules()) !== false) {
            $this->php_suffix = "";
        } else {
            $this->php_suffix = ".php";
        }
    }

    /** @return string */
    function self() {
        return "{$this->server}{$this->site_path}{$this->raw_page}{$this->path}{$this->query}";
    }

    /** @param bool $downcase_host
     * @return string */
    function site_absolute($downcase_host = false) {
        $x = $downcase_host ? strtolower($this->server) : $this->server;
        return $x . $this->site_path;
    }

    /** @param bool $downcase_host
     * @return string */
    function base_absolute($downcase_host = false) {
        $x = $downcase_host ? strtolower($this->server) : $this->server;
        return $x . $this->base_path;
    }

    /** @param ?string $url
     * @return string */
    function siteurl($url = null) {
        $x = $this->site_path_relative;
        if (!$url) {
            return $x;
        } else if (substr($url, 0, 5) !== "index" || substr($url, 5, 1) === "/") {
            return $x . $url;
        } else {
            return ($x ? : $this->site_path) . substr($url, 5);
        }
    }

    /** @param ?string $url
     * @return string */
    function siteurl_path($url = null) {
        $x = $this->site_path;
        if (!$url) {
            return $x;
        } else if (substr($url, 0, 5) !== "index" || substr($url, 5, 1) === "/") {
            return $x . $url;
        } else {
            return $x . substr($url, 5);
        }
    }

    /** @param string $url
     * @return string */
    function set_siteurl($url) {
        if ($url !== "" && $url[strlen($url) - 1] !== "/") {
            $url .= "/";
        }
        return ($this->site_path_relative = $url);
    }

    /** @param string $page
     * @return string */
    function set_page($page) {
        $this->raw_page = $page;
        if (($pagelen = strlen($page)) > 4
            && substr($page, $pagelen - 4) === ".php") {
            $page = substr($page, 0, $pagelen - 4);
        }
        return ($this->page = $page);
    }

    /** @param string $path
     * @return string */
    function set_path($path) {
        return ($this->path = $path);
    }

    /** @param int $n
     * @param bool $decoded
     * @return ?string */
    function path_component($n, $decoded = false) {
        if ($this->path !== "") {
            $p = explode("/", substr($this->path, 1));
            if ($n + 1 < count($p)
                || ($n + 1 == count($p) && $p[$n] !== "")) {
                return $decoded ? urldecode($p[$n]) : $p[$n];
            }
        }
        return null;
    }

    /** @param int $n
     * @return string */
    function path_suffix($n) {
        if ($this->path !== "") {
            $p = 0;
            while ($n > 0 && ($p = strpos($this->path, "/", $p + 1))) {
                --$n;
            }
            if ($p !== false) {
                return substr($this->path, $p);
            }
        }
        return "";
    }

    /** @param int $n
     * @return ?string */
    function shift_path_components($n) {
        $nx = $n;
        $pos = 0;
        $path = $this->raw_page . $this->path;
        while ($n > 0 && $pos < strlen($path)) {
            if (($pos = strpos($path, "/", $pos)) !== false) {
                ++$pos;
                --$n;
            } else {
                $pos = strlen($path);
            }
        }
        if ($n > 0) {
            return null;
        }
        $this->site_path .= substr($path, 0, $pos);
        if (substr($this->site_path_relative, 0, 3) === "../") {
            $this->site_path_relative = substr($this->site_path_relative, 3 * $nx);
        } else {
            $this->site_path_relative = $this->site_path;
        }
        $this->shifted_path .= substr($path, 0, $pos);
        $spos = $pos;
        if ($pos < strlen($path) && ($spos = strpos($path, "/", $pos)) === false) {
            $spos = strlen($path);
        }
        if ($pos !== $spos) {
            $this->raw_page = $this->page = substr($path, $pos, $spos - $pos);
        } else {
            $this->raw_page = "";
            $this->page = "index";
        }
        // NB: str_ends_with is not available in this file in older PHPs
        if (($pagelen = strlen($this->page)) > 4
            && substr($this->page, $pagelen - 4) === ".php") {
            $this->page = substr($this->page, 0, $pagelen - 4);
        }
        $this->path = (string) substr($path, $spos);
        return $this->page;
    }

    /** @param string $url
     * @param ?string $siteref
     * @return string */
    function make_absolute($url, $siteref = null) {
        preg_match('/\A((?:https?:\/\/[^\/]+)?)(\/*)((?:\.\.\/)*)(.*)\z/i', $url, $m);
        if ($m[1] !== "") {
            return $url;
        } else if (strlen($m[2]) > 1) {
            return $this->protocol . substr($url, 2);
        } else if ($m[2] === "/") {
            return $this->server . $url;
        } else {
            if ($siteref === null) {
                $siteref = preg_replace('/\/[^\/]+\z/', "/",
                    substr($this->request_uri, 0, strlen($this->request_uri) - strlen($this->query)));
            }
            while ($m[3]) {
                $siteref = preg_replace('/\/[^\/]+\/\z/', "/", $siteref);
                $m[3] = substr($m[3], 3);
            }
            return "{$this->server}{$siteref}{$m[3]}{$m[4]}";
        }
    }

    /** @param bool $allow_http_if_localhost
     * @return void */
    function redirect_http_to_https($allow_http_if_localhost = false) {
        if ($this->protocol == "http://"
            && (!$allow_http_if_localhost
                || ($_SERVER["REMOTE_ADDR"] !== "127.0.0.1"
                    && $_SERVER["REMOTE_ADDR"] !== "::1"))) {
            Navigation::redirect_absolute("https://" . ($this->host ? : "localhost")
                . $this->siteurl_path("{$this->page}{$this->php_suffix}{$this->path}{$this->query}"));
        }
    }
}

class Navigation {
    /** @var ?NavigationState */
    static private $s;

    static function analyze() {
        if (PHP_SAPI !== "cli") {
            self::$s = new NavigationState($_SERVER);
        } else {
            self::$s = new NavigationState(null);
        }
    }

    /** @return NavigationState */
    static function get() {
        return self::$s;
    }

    /** @return string */
    static function self() {
        return self::$s->self();
    }

    /** @return string */
    static function host() {
        return self::$s->host;
    }

    /** @param bool $downcase_host
     * @return string */
    static function site_absolute($downcase_host = false) {
        return self::$s->site_absolute($downcase_host);
    }

    /** @param bool $downcase_host
     * @return string */
    static function base_absolute($downcase_host = false) {
        return self::$s->base_absolute($downcase_host);
    }

    /** @return string */
    static function site_path() {
        return self::$s->site_path;
    }

    /** @return string */
    static function base_path() {
        return self::$s->base_path;
    }

    /** @param ?string $url
     * @return string */
    static function siteurl($url = null) {
        return self::$s->siteurl($url);
    }

    /** @param ?string $url
     * @return string */
    static function siteurl_path($url = null) {
        return self::$s->siteurl_path($url);
    }

    /** @param string $url
     * @return string */
    static function set_siteurl($url) {
        return self::$s->set_siteurl($url);
    }

    /** @return string */
    static function page() {
        return self::$s->page;
    }

    /** @return string */
    static function path() {
        return self::$s->path;
    }

    /** @param int $n
     * @param bool $decoded
     * @return ?string */
    static function path_component($n, $decoded = false) {
        return self::$s->path_component($n, $decoded);
    }

    /** @param int $n
     * @return string */
    static function path_suffix($n) {
        return self::$s->path_suffix($n);
    }

    /** @param int $n
     * @return ?string */
    static function shift_path_components($n) {
        return self::$s->shift_path_components($n);
    }

    /** @return string */
    static function shifted_path() {
        return self::$s->shifted_path;
    }

    /** @param string $page
     * @return string */
    static function set_page($page) {
        return self::$s->set_page($page);
    }

    /** @param string $path
     * @return string */
    static function set_path($path) {
        return (self::$s->path = $path);
    }

    /** @return string */
    static function php_suffix() {
        return self::$s->php_suffix;
    }

    /** @param string $url
     * @param ?string $siteref
     * @return string */
    static function make_absolute($url, $siteref = null) {
        return self::$s->make_absolute($url, $siteref);
    }

    /** @param string $url
     * @return void */
    static function redirect_absolute($url) {
        // Might have an HTML-encoded URL; decode at least &amp;.
        $url = str_replace("&amp;", "&", $url);
        header("Location: $url");
        echo "<!DOCTYPE html>
<html lang=\"en\"><head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />
<title>Redirection</title>
<script>location=", json_encode($url), ";</script></head>
<body>
<p>You should be redirected <a href=\"", htmlspecialchars($url), "\">to here</a>.</p>
</body></html>\n";
        exit();
    }
}

Navigation::analyze();
