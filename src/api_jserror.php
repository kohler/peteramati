<?php
// api/api_repo.php -- Peteramati API for Javascript errors
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class JSError_API {
    static function resolve_sourcemap(&$sourcemaps, $s, $noname) {
        $sx = "";
        while (preg_match('/\S+\/scripts\/([^:?\s]*)\??((?:mtime=\d+)?)[^\s:]*:(\d+):(\d+)/', $s, $m, PREG_OFFSET_CAPTURE)) {
            $sx .= substr($s, 0, $m[0][1]);
            $fn = $m[1][0];
            if (($mt = @filemtime(SiteLoader::$root . "/scripts/{$fn}")) !== false
                && ($m[2][0] === "" || substr($m[2][0], 6) == $mt)
                && file_exists(SiteLoader::$root . "/scripts/{$fn}.map")) {
                if (!isset($sourcemaps[$fn])) {
                    $t = json_decode(file_get_contents(SiteLoader::$root . "/scripts/{$fn}.map"));
                    $sourcemaps[$fn] = new SourceMapper($t ?? (object) ["file" => "scripts/{$fn}", "sources" => [], "names" => [], "mappings" => ""]);
                }
                if (($map = $sourcemaps[$fn]->lookup((int) $m[3][0] - 1, (int) $m[4][0] - 1))) {
                    $f = str_starts_with($map->file, "../") ? substr($map->file, 3) : $map->file;
                    $l = $map->line + 1;
                    $lt = $map->column ? "{$l}:" . ($map->column + 1) : "$l";
                    if ($map->name !== null && !$noname) {
                        $sx .= "{$map->name}@{$f}:{$lt}";
                    } else {
                        $sx .= "{$f}:{$lt}";
                    }
                } else {
                    $sx .= $m[0][0];
                }
            } else {
                $sx .= $m[0][0];
            }
            $s = substr($s, $m[0][1] + strlen($m[0][0]));
        }
        return $sx . $s;
    }
    static function jserror(Contact $user, Qrequest $qreq, APIData $api) {
        $errormsg = trim((string) $qreq->error);
        if ($errormsg === ""
            || (isset($_SERVER["HTTP_USER_AGENT"])
                && preg_match('/MSIE [78]|MetaSr/', $_SERVER["HTTP_USER_AGENT"]))
            || preg_match('/(?:moz|safari|chrome)-extension/', $errormsg . ($qreq->stack ?? ""))) {
            return new JsonResult(true);
        }
        $url = $qreq->url ?? "";
        if (preg_match(',[/=]((?:script|jquery)[^/&;]*[.]js),', $url, $m)) {
            $url = $m[1];
        }
        if (($n = $qreq->lineno)) {
            $url .= ":" . $n;
        }
        if (($n = $qreq->colno)) {
            $url .= ":" . $n;
        }
        $sourcemaps = [];
        if ($url !== "") {
            $url = self::resolve_sourcemap($sourcemaps, $url, true) . ": ";
        }
        $suffix = "";
        if ($user->email) {
            $suffix .= ", user " . $user->email;
        }
        if (isset($_SERVER["REMOTE_ADDR"])) {
            $suffix .= ", host " . $_SERVER["REMOTE_ADDR"];
        }
        error_log("JS error: $url$errormsg$suffix");
        if (($stacktext = $qreq->stack)) {
            $stack = array();
            foreach (explode("\n", $stacktext) as $line) {
                $line = trim($line);
                if ($line === "" || $line === $errormsg || "Uncaught $line" === $errormsg) {
                    continue;
                }
                if (preg_match('/\Aat (.*?) \((\S+)\)/', $line, $m)) {
                    $line = $m[1] . "@" . $m[2];
                } else if (substr($line, 0, 1) === "@") {
                    $line = substr($line, 1);
                } else if (substr($line, 0, 3) === "at ") {
                    $line = substr($line, 3);
                }
                $stack[] = self::resolve_sourcemap($sourcemaps, $line, false);
            }
            error_log("JS error: {$url}via " . join(" ", $stack));
        }
        json_exit(["ok" => true]);
    }
}
