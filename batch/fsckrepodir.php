<?php
// fsckrepodir.php -- Peteramati script for updating repository directories
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}


class FsckRepodir_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $dry_run = false;
    /** @var bool */
    public $verbose = false;

    /** @param list<Pset> $psets
     * @param list<string> $usermatch */
    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @param string $cacheid */
    function update_cachedir($cacheid) {
        $repodir = Repository::repodir_at($this->conf, $cacheid);
        if (!file_exists($repodir)) {
            return true;
        }

        $repodir = Repository::ensure_repodir_at($this->conf, $cacheid);
        $proc = Repository::gitrun_at($this->conf, ["git", "config", "--get", "core.sharedrepository"], $repodir);
        if (!$proc->ok) {
            return false;
        }
        $shared = trim($proc->stdout);
        if ($shared !== "1" && $shared !== "group") {
            return true; // XXX should check umask
        }

        $mode = stat($repodir)["mode"];
        if (($mode & 020) === 0) {
            chmod($repodir, $mode | 020);
        }

        $dirs = [""];
        while (!empty($dirs)) {
            $subdir = array_pop($dirs);
            $pfx = $subdir . "/";
            foreach (scandir($repodir . $subdir) ? : [] as $suffix) {
                if ($suffix === "." || $suffix === "..") {
                    continue;
                }
                $file = $pfx . $suffix;
                $path = $repodir . $file;
                $stat = stat($path);
                if ($stat === false) {
                    continue;
                }
                $mode = $stat["mode"];
                if (($mode & 020) === 0
                    && $file !== "/FETCH_HEAD") {
                    if ($this->verbose) {
                        fwrite(STDOUT, "+ chmod g+w {$repodir}{$file}\n");
                    }
                    if (!$this->dry_run) {
                        chmod($path, $mode | 020);
                    }
                }
                if (($mode & 0040000) !== 0 // directory
                    && (!str_starts_with($file, "/objects/")
                        || $file === "/objects/info")) {
                    $dirs[] = $file;
                }
            }
        }
        return true;
    }

    /** @return int */
    function run_or_warn() {
        $ok = true;
        foreach (str_split("0123456789abcdef") as $cacheid) {
            $ok = $this->update_cachedir($cacheid) && $ok;
        }
        return $ok ? 0 : 1;
    }

    /** @return FsckRepodir_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "dry-run,d Do not modify repository permissions",
            "V,verbose Be verbose",
            "help !"
        )->description("Fix permissions in peteramati repo directories.
Usage: php batch/fsckrepodir.php [OPTIONS]")
         ->helpopt("help")->parse($argv);
        $self = new FsckRepodir_Batch($conf);
        if (isset($arg["dry-run"])) {
            $self->dry_run = true;
        }
        if (isset($arg["V"])) {
            $self->verbose = true;
        }
        return $self;
    }
}


if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(FsckRepodir_Batch::make_args(Conf::$main, $argv)->run_or_warn());
}