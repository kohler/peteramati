<?php
// subprocess.php -- Peteramati helper class for subprocess running
// Peteramati is Copyright (c) 2013-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class Subprocess implements JsonSerializable {
    /** @var list<string> */
    public $command;
    /** @var string */
    public $cwd;
    /** @var string */
    public $stdout = "";
    /** @var string */
    public $stderr = "";
    /** @var int */
    public $status;
    /** @var bool */
    public $truncated;
    /** @var bool */
    public $ok;

    /** @var int */
    private $lineno = 1;
    /** @var int */
    private $linepos = 0;


    /** @param int $firstline
     * @param int $linecount
     * @return bool */
    private function handle_lines($firstline, $linecount) {
        $pos = $this->linepos;
        while ($this->lineno < $firstline
               || ($linecount >= 0 && $this->lineno < $firstline + $linecount)) {
            $next = strpos($this->stdout, "\n", $pos);
            if ($next === false) {
                $pos = strlen($this->stdout);
                break;
            }
            ++$this->lineno;
            $pos = $next + 1;
            if ($this->lineno === $firstline) {
                $this->stdout = substr($this->stdout, $pos);
                $pos = 0;
            }
            if ($linecount >= 0 && $this->lineno === $firstline + $linecount) {
                $this->stdout = substr($this->stdout, 0, $pos);
                $this->truncated = true;
                return true;
            }
        }
        if ($this->lineno < $firstline) {
            $this->stdout = "";
            $pos = 0;
        }
        $this->linepos = $pos;
        return false;
    }


    /** @param list<string> $command
     * @param string $cwd
     * @param array{firstline?:int,linecount?:int,stdin?:string,env?:array<string,string>} $args
     * @return Subprocess */
    static function run($command, $cwd, $args = []) {
        $firstline = max($args["firstline"] ?? 1, 1);
        $linecount = $args["linecount"] ?? -1;
        $lines = $firstline > 1 || $linecount >= 0;
        $stdin = $args["stdin"] ?? "";
        $stdinpos = 0;
        $stdinlen = strlen($stdin);

        $descriptors = [
            ["file", "/dev/null", "r"], ["pipe", "w"], ["pipe", "w"]
        ];
        if ($stdinpos !== $stdinlen) {
            $descriptors[0] = ["pipe", "r"];
        }
        $cmd = self::unparse_command($command);
        $env = $args["env"] ?? null;
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        if (!$proc) {
            error_log(self::unparse_command($command));
        }
        if ($stdinpos !== $stdinlen) {
            stream_set_blocking($pipes[0], false);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $sp = new Subprocess;
        $sp->command = $command;
        $sp->cwd = $cwd;
        $sp->truncated = false;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            if ($stdinpos !== $stdinlen) {
                $nw = fwrite($pipes[0], substr($stdin, $stdinpos));
                if ($nw !== false) {
                    $stdinpos += $nw;
                }
                if ($stdinpos === $stdinlen) {
                    fclose($pipes[0]);
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
            if (($lines && $sp->handle_lines($firstline, $linecount))
                || $x === false
                || $y === false) {
                break;
            }
            $r = [$pipes[1], $pipes[2]];
            $w = $e = [];
            if ($stdinpos !== $stdinlen) {
                $w[] = $pipes[0];
            }
            stream_select($r, $w, $e, 5);
        }

        if ($stdinpos !== $stdinlen) {
            fclose($pipes[0]);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $sp->status = proc_close($proc);
        $sp->ok = $sp->truncated || pcntl_wifexitedwith($sp->status, 0);
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

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return [
            "command" => $this->command,
            "stdout" => $this->stdout,
            "stderr" => $this->stderr,
            "status" => $this->status,
            "ok" => $this->ok
        ];
    }
}
