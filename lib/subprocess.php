<?php
// subprocess.php -- Peteramati helper class for subprocess running
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class Subprocess {
    /** @var list<string> */
    public $command;
    /** @var string */
    public $stdout = "";
    /** @var string */
    public $stderr = "";
    /** @var int */
    public $status;
    /** @var bool */
    public $ok;

    /** @var int */
    private $linepos = 1;
    /** @var int */
    private $linecount = 0;
    /** @var int */
    private $linecountpos = 0;


    /** @param int $firstline
     * @param int $linecount
     * @return bool */
    private function handle_lines($firstline, $linecount) {
        if ($firstline > 0 && $this->linepos < $firstline) {
            $pos = 0;
            while ($this->linepos < $firstline) {
                $next = strpos($this->stdout, "\n", $pos);
                if ($next === false) {
                    $pos = strlen($this->stdout);
                    break;
                }
                ++$this->linepos;
                $pos = $next + 1;
            }
            $this->stdout = substr($this->stdout, $pos);
        }
        if ($linecount >= 0 && $firstline <= $this->linepos) {
            $pos = $this->linecountpos;
            while ($this->linecount < $linecount) {
                $next = strpos($this->stdout, "\n", $pos);
                if ($next === false) {
                    $pos = strlen($this->stdout);
                    break;
                }
                ++$this->linecount;
                $pos = $next + 1;
            }
            $this->linecountpos = $pos;
        }
        return $linecount >= 0 && $this->linecount === $linecount;
    }

    /** @param int $firstline
     * @param int $linecount */
    private function finish_lines($firstline, $linecount) {
        $this->handle_lines($firstline, $linecount);
        if ($linecount >= 0) {
            $this->stdout = substr($this->stdout, 0, $this->linecountpos);
        }
    }


    /** @param list<string> $command
     * @param string $cwd
     * @param array{firstline?:int,linecount?:int,stdin?:string} $args
     * @return Subprocess */
    static function run($command, $cwd, $args = []) {
        $firstline = $args["firstline"] ?? 0;
        $linecount = $args["linecount"] ?? -1;
        $lines = $firstline > 0 || $linecount >= 0;
        $stdin = $args["stdin"] ?? null;
        $stdinpos = 0;

        $descriptors = [
            ["file", "/dev/null", "r"], ["pipe", "w"], ["pipe", "w"]
        ];
        if ($stdin !== null) {
            $descriptors[0] = ["pipe", "r"];
        }
        $cmd = PHP_VERSION_ID >= 70400 ? $command : self::unparse_command($command);
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if ($stdin !== null) {
            stream_set_blocking($pipes[0], false);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $sp = new Subprocess;
        $sp->command = $command;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            if ($stdin !== null) {
                $nw = fwrite($pipes[0], substr($stdin, $stdinpos));
                if ($nw !== false) {
                    $stdinpos += $nw;
                }
                if ($stdinpos === strlen($stdin)) {
                    fclose($pipes[0]);
                    $stdin = null;
                }
            }
            $x = fread($pipes[1], 32768);
            if ($x !== false) {
                $sp->stdout .= $x;
            }
            $y = fread($pipes[2], 32768);
            if ($y !== false) {
                $sp->stderr .= $y;
            }
            if ($x === false
                || $y === false
                || ($lines && $sp->handle_lines($firstline, $linecount))) {
                break;
            }
            $r = [$pipes[1], $pipes[2]];
            $w = $e = [];
            if ($stdin !== null) {
                $w[] = $pipes[0];
            }
            stream_select($r, $w, $e, 5);
        }

        if ($stdin !== null) {
            fclose($pipes[0]);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $sp->status = proc_close($proc);
        $sp->ok = pcntl_wifexitedwith($sp->status, 0);
        if ($lines) {
            $sp->finish_lines($firstline, $linecount);
        }
        return $sp;
    }

    /** @param list<string> $command
     * @param string $cwd */
    static function runok($command, $cwd) {
        return self::run($command, $cwd)->ok;
    }


    /** @param list<string> $command
     * @return string */
    static function unparse_command($command) {
        $s = [];
        foreach ($command as $w) {
            $s[] = preg_match('/\A[-_.,:=+~\/a-zA-Z0-9]+\z/', $w) ? $w : escapeshellarg($w);
        }
        return join(" ", $s);
    }
}
