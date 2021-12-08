<?php
// linenotesnear.php -- Peteramati script for examining linenotes
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once(dirname(__DIR__) . "/src/init.php");

class LineNotesNearBatch {
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

    function run() {
        $csv = (new CsvGenerator)->select(["repourl","hash","file","lineid","linea","ftext"], true);
        foreach (LineNote_API::all_linenotes_near($this->pset, $this->file, $this->linea,
                                                  $this->linea ? 5 : 0) as $cpiln) {
            list($cpi, $ln) = $cpiln;
            if (!$this->lineid || $ln->lineid === $this->lineid) {
                $csv->add_row([$cpi->repourl, $cpi->hash(), $ln->file, $ln->lineid, $ln->linea, $ln->ftext]);
            }
        }
        $csv->flush();
    }

    /** @return LineNotesNearBatch */
    static function parse_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p:,pset: Problem set",
            "f:,file: File",
            "l:,line: Line number or line ID",
            "a,all Return all notes",
            "help"
        )->helpopt("help")->description("php batch/linenotesnear.php")->parse($argv);
        if (!$arg["p"] || !($pset = $conf->pset_by_key($arg["p"]))) {
            throw new Error("no such `--pset`");
        }
        $lnb = new LineNotesNearBatch($pset);

        if (!isset($arg["f"]) && empty($arg["_"])) {
            throw new Error("missing file");
        } else if (count($arg["_"]) !== (isset($arg["f"]) ? 0 : 1)) {
            throw new Error("wrong number of arguments");
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
                throw new Error("bad `--line`");
            }
        }

        return $lnb;
    }
}

try {
    LineNotesNearBatch::parse_args($Conf, $argv)->run();
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
