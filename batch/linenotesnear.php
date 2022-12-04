<?php
// linenotesnear.php -- Peteramati script for examining linenotes
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    date_default_timezone_set("GMT");
    exit(LineNotesNear_Batch::make_args(Conf::$main, $argv)->run());
}


class LineNotesNear_Batch {
    /** @var Pset */
    public $pset;
    /** @var string */
    public $file;
    /** @var ?int */
    public $linea;
    /** @var ?string */
    public $lineid;

    function __construct(Pset $pset) {
        $this->pset = $pset;
    }

    /** @return int */
    function run() {
        $upi = $this->pset->gitless_grades && str_starts_with($this->file, "/");
        $hdr = $upi ? ["email"] : ["repourl", "hash"];
        array_push($hdr, "file", "lineid", "linea", "ftext");
        $csv = (new CsvGenerator)->select($hdr, true);
        foreach (LineNote_API::all_linenotes_near($this->pset, $this->file, $this->linea,
                                                  $this->linea ? 5 : 0) as $ln) {
            if (!$this->lineid || $ln->lineid === $this->lineid) {
                $row = $upi ? [$ln->upi->email] : [$ln->cpi->repourl, $ln->cpi->hash()];
                array_push($row, $ln->file, $ln->lineid, $ln->linea, $ln->ftext);
                $csv->add_row($row);
            }
        }
        $csv->flush();
        return 0;
    }

    /** @return LineNotesNear_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p:,pset: Problem set",
            "f:,file: File",
            "l:,line: Line number or line ID",
            "a,all Return all notes",
            "help"
        )->helpopt("help")
         ->description("php batch/linenotesnear.php")
         ->parse($argv);
        if (!($arg["p"] ?? null) || !($pset = $conf->pset_by_key($arg["p"]))) {
            throw new CommandLineException("no such `--pset`");
        }
        $lnb = new LineNotesNear_Batch($pset);

        if (!isset($arg["f"]) && empty($arg["_"])) {
            throw new CommandLineException("missing file");
        } else if (count($arg["_"]) !== (isset($arg["f"]) ? 0 : 1)) {
            throw new CommandLineException("wrong number of arguments");
        }
        $lnb->file = isset($arg["f"]) ? $arg["f"] : $arg["_"][0];

        if (!isset($arg["l"])
            && preg_match('/\A(.*):([ab]?\d+)\z/', $lnb->file, $m)) {
            $lnb->file = $m[1];
            $arg["l"] = $m[2];
        }
        if (isset($arg["l"])) {
            if (ctype_digit($arg["l"])) {
                $lnb->linea = intval($arg["l"]);
            } else if (preg_match('/\A[ab]\d+\z/', $arg["l"])) {
                $lnb->lineid = $arg["l"];
            } else {
                throw new CommandLineException("bad `--line`");
            }
        }

        return $lnb;
    }
}
