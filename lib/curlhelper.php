<?php
// curlhelper.php -- Peteramati helper for following a web request
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

libxml_use_internal_errors(true);

class CurlHelper {
    /** @var CurlHandle */
    public $curlh;
    /** @var string|false */
    private $cookiefile;
    /** @var bool */
    private $temp_cookiefile;
    /** @var ?resource */
    private $headerf;
    /** @var ?resource */
    private $bodyf;

    /** @var ?int */
    public $status_code;
    /** @var string */
    public $header_string;
    /** @var string */
    public $location;
    /** @var string */
    public $full_content_type;
    /** @var string */
    public $content_type;
    /** @var string */
    public $content_encoding;

    /** @var string */
    public $encoded_content_string;
    /** @var string */
    public $content_string;
    /** @var ?DOMDocument */
    public $content_dom;
    /** @var ?array */
    public $content_dom_errors;
    /** @var ?mixed */
    public $content_json;

    /** @var ?string */
    public $next_url;
    /** @var null|'GET'|'POST' */
    private $next_method;
    /** @var ?array */
    private $next_parameters;
    /** @var bool */
    public $next_www_form_encoded = false;
    public $next_origin;
    public $next_referer;
    private $next_headers = [];

    /** @var bool */
    static public $verbose = false;

    /** @param ?string $cookiefile */
    function __construct($cookiefile = null) {
        $this->temp_cookiefile = !$cookiefile;
        $this->cookiefile = $cookiefile ? : tempnam("/tmp", "hotcrp_cookiejar");
        if ($this->temp_cookiefile || !file_exists($this->cookiefile)) {
            @file_put_contents($this->cookiefile, "");
            @chmod($this->cookiefile, 0770 & ~umask());
        }
        if (!file_exists($this->cookiefile) || !is_writable($this->cookiefile)) {
            error_log("$this->cookiefile: Not writable");
            exit(1);
        }

        $this->curlh = curl_init();
        curl_setopt($this->curlh, CURLOPT_COOKIEFILE, $this->cookiefile);
        curl_setopt($this->curlh, CURLOPT_COOKIEJAR, $this->cookiefile);
        curl_setopt($this->curlh, CURLOPT_USERAGENT, "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Safari/605.1.15");
        curl_setopt($this->curlh, CURLOPT_AUTOREFERER, true);
        curl_setopt($this->curlh, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->curlh, CURLOPT_MAXREDIRS, 40);
        curl_setopt($this->curlh, CURLOPT_SAFE_UPLOAD, true);
        curl_setopt($this->curlh, CURLOPT_ENCODING, "");
    }

    private function init_files() {
        $this->headerf = $this->headerf ? : fopen("php://temp", "r+");
        rewind($this->headerf);
        ftruncate($this->headerf, 0);
        $this->bodyf = $this->bodyf ? : fopen("php://temp", "r+");
        rewind($this->bodyf);
        ftruncate($this->bodyf, 0);
    }

    /** @return ?string */
    function location_host() {
        $x = parse_url($this->location);
        return $x["host"] ?? null;
    }

    /** @return string */
    function location_query() {
        $x = parse_url($this->location);
        return isset($x["query"]) ? "?" . $x["query"] : "";
    }

    /** @param int|float $timeout_sec */
    function set_timeout($timeout_sec) {
        curl_setopt($this->curlh, CURLOPT_TIMEOUT, $timeout_sec);
    }

    /** @param ?string $user
     * @param ?string $pwd */
    function set_user_password($user = null, $pwd = null) {
        if ((string) $user !== "") {
            $user .= (string) $pwd === "" ? "" : ":" . $pwd;
        }
        curl_setopt($this->curlh, CURLOPT_USERPWD, (string) $user);
    }

    /** @param string $url
     * @param string $method
     * @param array $parameters */
    function set_next($url, $method = "GET", $parameters = []) {
        $this->next_url = $url;
        $this->next_method = $method;
        $this->next_parameters = $parameters;
    }

    /** @param DOMElement $form
     * @return Generator<DOMElement> */
    static function typed_form_inputs($form) {
        $es = $form->getElementsByTagName("input");
        for ($i = 0; $i !== $es->length; ++$i) {
            $e = $es->item($i);
            '@phan-var-force DOMElement $e';
            yield $e;
        }
        $es = $form->getElementsByTagName("select");
        for ($i = 0; $i !== $es->length; ++$i) {
            $e = $es->item($i);
            '@phan-var-force DOMElement $e';
            if ($e->hasAttribute("multiple")) {
                $e->setAttribute("type", "select-multiple");
            } else {
                $e->setAttribute("type", "select");
            }
            yield $e;
        }
        $es = $form->getElementsByTagName("textarea");
        for ($i = 0; $i !== $es->length; ++$i) {
            $e = $es->item($i);
            '@phan-var-force DOMElement $e';
            $e->setAttribute("type", "textarea");
            $e->setAttribute("value", $e->textContent);
            yield $e;
        }
        $es = $form->getElementsByTagName("button");
        for ($i = 0; $i !== $es->length; ++$i) {
            $e = $es->item($i);
            '@phan-var-force DOMElement $e';
            $attr = strtolower($e->getAttribute("type") ?? "");
            if ($attr !== "submit" && $attr !== "reset" && $attr !== "button" && $attr !== "menu") {
                $e->setAttribute("type", "submit");
            }
            yield $e;
        }
    }

    /** @param DOMElement $form
     * @return array */
    static function form_parameters($form) {
        $param = [];
        foreach (self::typed_form_inputs($form) as $input) {
            $name = $input->getAttribute("name");
            if (!$name) {
                continue;
            }
            $type = $input->getAttribute("type");
            $value = null;
            if ($type === "textarea") {
                $value = $input->textContent;
            } else if ($type === "select") {
                foreach ($input->getElementsByTagName("option") as $opt) {
                    if ($opt->hasAttribute("selected"))
                        $value = $opt->getAttribute("value");
                }
            } else if ($type === "checkbox" || $type === "radio") {
                if ($input->hasAttribute("checked"))
                    $value = $input->getAttribute("value");
            } else if ($type !== "button" && $type !== "file"
                       && $type !== "image" && $type !== "reset"
                       && $type !== "reset") {
                $value = $input->getAttribute("value");
            }
            if ($value !== null) {
                $param[$name] = $value;
            }
        }
        return $param;
    }

    /** @param DOMElement $form
     * @param array $parameters */
    function set_next_form_parameters($form, $parameters) {
        $this->next_url = $this->resolve($form->getAttribute("action"));
        $this->next_method = strtoupper($form->getAttribute("method"));
        if ($this->next_method !== "POST" && $this->next_method !== "GET") {
            $this->next_method = "GET";
        }
        $this->next_parameters = $parameters;
    }

    /** @param DOMElement $form */
    function set_next_form($form) {
        $this->set_next_form_parameters($form, self::form_parameters($form));
    }

    /** @param string|array $name
     * @param ?string $value */
    function set_next_param($name, $value = null) {
        if (func_num_args() == 1 && is_array($name)) {
            foreach ($name as $n => $v) {
                if ($v !== null) {
                    $this->next_parameters[$n] = $v;
                } else {
                    unset($this->next_parameters[$n]);
                }
            }
        } else if ($value !== null) {
            $this->next_parameters[$name] = $value;
        } else {
            unset($this->next_parameters[$name]);
        }
    }

    /** @param array $param
     * @return string */
    static function www_form_encode($param) {
        $x = [];
        foreach ($param as $k => $v) {
            $x[] = urlencode($k) . "=" . urlencode($v);
        }
        return join("&", $x);
    }

    /** @param string $name
     * @param ?string $value */
    function set_next_header($name, $value) {
        $lname = strtolower($name);
        if ((string) $value === "") {
            unset($this->next_headers[$lname]);
        } else {
            $this->next_headers[$lname] = "$name: $value";
        }
    }

    /** @return string */
    private function full_next_url() {
        $url = $this->next_url;
        if (!empty($this->next_parameters)
            && $this->next_method === "GET") {
            $sep = strpos($url, "?") === false ? "?" : "&";
            $url .= $sep . self::www_form_encode($this->next_parameters);
        }
        return $url;
    }

    /** @return list<string> */
    private function full_next_headers() {
        $headers = $this->next_headers;
        if ($this->next_origin && !isset($headers["origin"])) {
            $headers["origin"] = "Origin: $this->next_origin";
        }
        if ($this->next_referer && !isset($headers["referer"])) {
            $headers["referer"] = "Referer: $this->next_referer";
        }
        ksort($headers);
        return array_values($headers);
    }

    /** @return string */
    private function full_next_body() {
        if ($this->next_parameters !== null
            && $this->next_method !== "GET") {
            return self::www_form_encode($this->next_parameters);
        } else {
            return "";
        }
    }

    /** @return string */
    function unparse_next_request() {
        return $this->next_method . " " . $this->full_next_url() . "\n"
            . join("\n", $this->full_next_headers());
    }

    function go() {
        assert(!!$this->next_url);
        curl_setopt($this->curlh, CURLOPT_HTTPHEADER, $this->full_next_headers());
        $this->init_files();
        curl_setopt($this->curlh, CURLOPT_WRITEHEADER, $this->headerf);
        curl_setopt($this->curlh, CURLOPT_FILE, $this->bodyf);
        if ($this->next_method === "POST") {
            curl_setopt($this->curlh, CURLOPT_POST, true);
        } else {
            curl_setopt($this->curlh, CURLOPT_HTTPGET, true);
        }

        $url = $this->full_next_url();
        if ($this->next_parameters !== null && $this->next_method !== "GET") {
            if ($this->next_www_form_encoded) {
                curl_setopt($this->curlh, CURLOPT_POSTFIELDS, self::www_form_encode($this->next_parameters));
            } else {
                curl_setopt($this->curlh, CURLOPT_POSTFIELDS, $this->next_parameters);
            }
        }

        curl_setopt($this->curlh, CURLOPT_URL, $url);
        if (self::$verbose) {
            error_log("===================================================================");
            curl_setopt($this->curlh, CURLINFO_HEADER_OUT, true);
        }
        curl_exec($this->curlh);
        if (self::$verbose) {
            $pout = "";
            if (!empty($this->next_parameters) && $this->next_method !== "GET") {
                if ($this->next_www_form_encoded) {
                    $pout = self::www_form_encode($this->next_parameters);
                } else {
                    $pout = json_encode($this->next_parameters);
                }
            }
            error_log(curl_getinfo($this->curlh, CURLINFO_HEADER_OUT) . $pout);
        }

        rewind($this->headerf);
        $this->status_code = curl_getinfo($this->curlh, CURLINFO_HTTP_CODE);
        $this->header_string = stream_get_contents($this->headerf);
        $this->location = $url;
        if (preg_match_all('/^Location:\s*(\S+)/mi', $this->header_string, $m)) {
            for ($i = 0; $i !== count($m[1]); ++$i) {
                $this->location = $this->resolve($m[1][$i]);
            }
        }
        $this->full_content_type = "application/octet-stream";
        if (preg_match_all('/^Content-type:([^\r\n]*)/mi', $this->header_string, $m)) {
            $this->full_content_type = trim($m[1][count($m[1]) - 1]);
        }
        $this->content_type = $this->full_content_type;
        if (preg_match('/\A\s*([^\s;]+)/', $this->full_content_type, $m)) {
            $this->content_type = $m[1];
        }
        $this->content_encoding = null;
        if (preg_match_all('/^Content-encoding:([^\r\n]*)/mi', $this->header_string, $m)) {
            $this->content_encoding = trim(join(", ", $m[1]));
        }

        rewind($this->bodyf);
        $this->content_string = $this->encoded_content_string = stream_get_contents($this->bodyf);

        if (self::$verbose) {
            error_log("> > > > > > > > > > > > > > > > > > > > > > > > > > > > > > > > > >");
            error_log($this->header_string);
            error_log($this->content_string);
        }

        $this->content_dom = $this->content_dom_errors = null;
        if (preg_match('/\A(?:text\/html|(?:text|application)\/xml)/i', $this->content_type)) {
            $this->load_content_dom();
        }
        $this->content_json = null;
        if (preg_match('/\A(?:application\/json|text\/json)\z/i', $this->content_type)) {
            $this->content_json = json_decode($this->content_string);
        }

        $this->next_url = null;
        $this->next_method = "GET";
        $this->next_parameters = [];
        $this->next_referer = $this->next_origin = $this->location;
        if (preg_match('{^(https?)://([^/:]+)((?::\d+)?)}i', $this->next_origin, $m)) {
            $this->next_origin = $m[1] . "://" . strtolower($m[2]);
            if ($m[3] && $m[3] !== ($m[1] === "https" ? ":443" : ":80")) {
                $this->next_origin .= $m[3];
            }
        }
        $this->next_headers = [];
    }

    private function load_content_dom() {
        $b = ltrim($this->content_string);
        // Harvard sucks
        if (substr($b, 0, 9) === "<!DOCTYPE") {
            $rbrack = strpos($b, ">");
            $doctype = array("", substr($b, 0, $rbrack));
            while (preg_match(',\A([^"]+\s*|"[^"]+"\s*)(.*)\z,', $doctype[1], $m)) {
                $doctype[0] .= $m[1];
                $doctype[1] = $m[2];
            }
            if ($doctype[1]) {
                $doctype[0] .= rtrim($doctype[1]) . '"';
            }
            $b = $doctype[0] . substr($b, $rbrack);
        }
        if (trim($b) !== "") {
            $this->content_dom = new DOMDocument;
            if (substr($b, 0, 5) === "<?xml") {
                $this->content_dom->loadXML($b);
            } else {
                $this->content_dom->loadHTML($b);
            }
            $this->content_dom_errors = libxml_get_errors();
        }
    }

    /** @param string $url
     * @return string */
    function resolve($url) {
        $urlp = parse_url($url);
        if (isset($urlp["scheme"])) {
            return $url;
        }
        $locp = parse_url($this->location);
        assert(isset($locp["scheme"]) && isset($locp["host"]));
        if (isset($urlp["host"])) {
            return $locp["scheme"] . ":" . $url;
        }
        $lochost = $locp["scheme"] . "://";
        if (isset($locp["user"]) && isset($locp["pass"])) {
            $lochost .= urlencode($locp["user"]) . ":" . urlencode($locp["pass"]) . "@";
        } else if (isset($locp["user"])) {
            $lochost .= urlencode($locp["user"]) . "@";
        }
        $lochost .= $locp["host"];
        if (isset($locp["port"])) {
            $lochost .= ":" . $locp["port"];
        }
        if ($url === "") {
            $lochost .= $locp["path"] ?? "";
            if (isset($locp["query"])) {
                $lochost .= "?" . $locp["query"];
            }
            return $lochost;
        } else if ($url[0] === "/") {
            return $lochost . $url;
        } else {
            $lochost .= preg_replace('/\/+[^\/]+\z/', "/", $locp["path"] ?? "");
            while (substr($url, 0, 3) === "../") {
                $lochost = preg_replace('/\/+[^\/]+\/+\z/', "/", $lochost);
                $url = substr($url, 3);
            }
            return $lochost . $url;
        }
    }

    function cleanup() {
        if ($this->curlh) {
            curl_close($this->curlh);
            $this->curlh = null;
        }
        if ($this->temp_cookiefile) {
            unlink($this->cookiefile);
            $this->cookiefile = $this->temp_cookiefile = false;
        }
    }

    /** @return object */
    function save_state() {
        return (object) ["location" => $this->location, "referer" => $this->next_referer, "origin" => $this->next_origin];
    }

    /** @param object $state */
    function restore_state($state) {
        $this->location = $state->location ?? null;
        $this->next_referer = $state->referer ?? null;
        $this->next_origin = $state->origin ?? null;
    }

    function next_cookie($name = null) {
        $locp = parse_url($this->next_url);
        $rscheme = $locp["scheme"] ?? "";
        $rhost = $locp["host"] ?? "";
        $rhostlen = strlen($rhost);
        $rpath = $locp["path"] ?? "";
        if ($rpath !== "" && preg_match('{\A(/.*?)/[^/]*\z}', $rpath, $m)) {
            $rpath = $m[1];
        } else {
            $rpath = "/";
        }
        $now = 0;
        $result = [];
        foreach (curl_getinfo($this->curlh, CURLINFO_COOKIELIST) as $cookiestr) {
            list($chost, $ctailmatch, $cpath, $csecure, $cexpires, $cname, $cvalue) = explode("\t", $cookiestr);
            if ($name !== null && $cname !== $name) {
                continue;
            }
            if ($csecure === "TRUE" && $rscheme !== "https") {
                continue;
            }
            $chttponly = str_starts_with($chost, "#HttpOnly_");
            if ($chttponly) {
                $chost = substr($chost, 10);
            }
            $chostlen = strlen($chost);
            if (strcasecmp($rhost, $chost) != 0
                && ($rhostlen <= $chostlen
                    || strcasecmp(substr($rhost, $rhostlen - $chostlen), $chost) != 0
                    || $rhost[$rhostlen - $chostlen - 1] != ".")) {
                continue;
            }
            if (strcasecmp($cpath, $rpath) != 0
                && (!str_starts_with($cpath, $rpath)
                    || (!str_ends_with($rpath, "/") && $cpath[strlen($rpath)] !== "/"))) {
                continue;
            }
            if ($cexpires) {
                $now = $now ? : time();
                if ($cexpires < $now) {
                    continue;
                }
            }
            if ($cname === $name) {
                return $cvalue;
            } else {
                $result[$cname] = $cvalue;
            }
        }
        return $result;
    }
}
