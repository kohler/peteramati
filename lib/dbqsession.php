<?php
// dbqsession.php -- HotCRP session handler wrapping database sessions (file backup)
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class DbQsession extends Qsession {
    const EMPTY_LIFETIME = 43200;  // 1/2 day
    const SHORT_LIFETIME = 172800; // 2 days
    const SLOP = 3600;             // update unchanged session once an hour

    /** @var ?string */
    private $_sdir;
    /** @var false|resource */
    private $_sfd = false;
    /** @var array<string,mixed> */
    private $_sv = [];
    /** @var bool */
    private $_sw = false;
    /** @var ?int */
    private $_expt;

    /** @var false|null|\mysqli */
    static private $_sdb = false;

    /** @return ?\mysqli */
    static function sdb() {
        if (self::$_sdb === false) {
            self::$_sdb = Conf::main_contactdb() ?? Dbl::$default_dblink;
        }
        return self::$_sdb;
    }

    /** @return int */
    private function lifetime() {
        global $Opt;
        if (isset($this->_sv["u"])) {
            return $Opt["sessionLifetime"] ?? 604800;
        }
        foreach ($this->_sv as $k => $v) {
            if (!in_array($k, ["expt", "v", "addrs", "login_bounce", "smsg", "deletedat"]))
                return self::SHORT_LIFETIME;
        }
        return self::EMPTY_LIFETIME;
    }

    /** @return non-empty-string */
    static function random_sid() {
        return random_alnum_chars(32, 32);
    }

    /** @return string */
    private function sdir() {
        if ($this->_sdir === null) {
            $this->_sdir = ini_get("session.save_path");
            if (!$this->_sdir) {
                $this->_sdir = sys_get_temp_dir();
            }
            if (!is_dir($this->_sdir)) {
                mkdir($this->_sdir, 0777);
            }
            if (!str_ends_with($this->_sdir, "/")) {
                $this->_sdir .= "/";
            }
        }
        return $this->_sdir;
    }


    function __construct() {
        session_set_save_handler(new FakeSessionHandler, true);
        ini_set("session.use_cookies", "0");
        register_shutdown_function([$this, "commit"]);
    }

    function start($sid) {
        global $Opt;
        assert(!$this->_sfd);
        if ($sid === null
            || strlen($sid) < 20
            || strlen($sid) > 128
            || !ctype_alnum($sid)) {
            $sid = $this->new_sid();
        }
        $path = $this->sdir() . "sess_" . $sid;
        $this->_sfd = @fopen($path, "c+e");
        clearstatcache(true, $path);
        flock($this->_sfd, LOCK_EX);

        if ($Opt["sessionIgnoreFiles"] ?? false) {
            $s = "";
        } else {
            $s = stream_get_contents($this->_sfd);
        }

        // read from database if not found
        if ($s === ""
            && ($sdb = self::sdb())
            && ($sdo = Dbl::fetch_first_object($sdb, "select * from SessionData where sid=?", $sid))
            && ($expt = (int) $sdo->expires_at) > Conf::$now) {
            $this->_expt = $expt;
            $s = $sdo->dataOverflow ?? $sdo->data ?? "";
            rewind($this->_sfd);
            fwrite($this->_sfd, "expt|i:{$expt};{$s}");
            $sdo = null;
        }

        session_id($sid);
        session_start();
        session_decode($s);
        $this->_sv = $_SESSION;
        $_SESSION = [];
        $this->_expt = $this->_expt ?? $this->_sv["expt"] ?? Conf::$now + $this->lifetime();
        return $sid;
    }

    function new_sid() {
        $sdb = self::sdb();
        $n = 0;
        //error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        while (true) {
            $sid = self::random_sid();
            //error_log("trying $sid");
            $path = $this->sdir() . "sess_" . $sid;
            if (file_exists($path)) {
                clearstatcache(true, $path);
                $ok = false;
            } else if ($sdb) {
                $result = Dbl::qe($sdb, "insert into SessionData set sid=?, updated_at=?, expires_at=?", $sid, Conf::$now, Conf::$now + 300);
                $ok = $result->errno === 0 && $result->affected_rows > 0;
                Dbl::free($result);
            } else {
                $ok = true;
            }
            //error_log("$sid $n " . json_encode($ok));
            if ($ok || ++$n >= 10) {
                return $sid;
            }
        }
    }

    function commit() {
        if (!$this->sopen) {
            return;
        }
        $lt = $this->lifetime();
        if ($this->_expt - $lt <= Conf::$now || rand(0, 127) === 0) {
            $this->_expt = Conf::$now + $lt + self::SLOP;
            $this->_sw = true;
        }
        if ($this->_sw) {
            unset($this->_sv["expt"]);
            $_SESSION = $this->_sv;
            $s = session_encode();
            $_SESSION = [];
            rewind($this->_sfd);
            ftruncate($this->_sfd, 0);
            fwrite($this->_sfd, "expt|i:{$this->_expt};{$s}");
            if (($sdb = self::sdb())) {
                if (strlen($s) < 32000) {
                    $sdata1 = $s;
                    $sdatao = null;
                } else {
                    $sdata1 = null;
                    $sdatao = $s;
                }
                $result = Dbl::qe($sdb, "update SessionData
                    set updated_at=?, expires_at=?, `data`=?, dataOverflow=?
                    where sid=?",
                    Conf::$now, $this->_expt, $sdata1, $sdatao, $this->sid);
                Dbl::free($result);
            }
        }
        fclose($this->_sfd);
        $this->sopen = false;
        $this->_sfd = false;
        $this->_sv = [];
        $this->_sw = false;
        $this->_expt = null;
        session_abort();
    }

    function refresh() {
        global $Opt;
        $params = session_get_cookie_params();
        $lt = $this->lifetime();
        if ($lt > 0) {
            $params["expires"] = Conf::$now + $lt;
        }
        unset($params["lifetime"]);
        hotcrp_setcookie(session_name(), $this->sid, $params);
    }


    function all() {
        return $this->_sv;
    }

    function clear() {
        assert($this->sopen);
        $this->_sv = [];
        $this->_sw = true;
    }

    function has($key) {
        return isset($this->_sv[$key]);
    }

    function get($key) {
        return $this->_sv[$key] ?? null;
    }

    function set($key, $value) {
        assert($this->sopen);
        $this->_sv[$key] = $value;
        $this->_sw = true;
    }

    function unset($key) {
        assert($this->sopen);
        unset($this->_sv[$key]);
        $this->_sw = true;
    }

    function has2($key1, $key2) {
        return isset($this->_sv[$key1][$key2]);
    }

    function get2($key1, $key2) {
        return $this->_sv[$key1][$key2] ?? null;
    }

    function set2($key1, $key2, $value) {
        assert($this->sopen);
        $this->_sv[$key1][$key2] = $value;
        $this->_sw = true;
    }

    function unset2($key1, $key2) {
        assert($this->sopen);
        if (isset($this->_sv[$key1][$key2])) {
            unset($this->_sv[$key1][$key2]);
            if (empty($this->_sv[$key1])) {
                unset($this->_sv[$key1]);
            }
            $this->_sw = true;
        }
    }
}
