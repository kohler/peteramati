<?php
// search.php -- Peteramati script for searching students
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}


class Search_Batch {
    /** @var Conf */
    public $conf;
    /** @var string */
    public $q;
    /** @var ?Pset */
    public $pset;
    /** @var bool */
    public $dry_run = false;
    /** @var bool */
    public $verbose = false;
    /** @var list<string|GradeFormula> */
    public $columns = [];
    /** @var CsvGenerator */
    public $csvg;

    /** @param array<string,mixed> $arg */
    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->q = join(" ", $arg["_"]);
        if (isset($arg["dry-run"])) {
            $this->dry_run = true;
        }
        if (isset($arg["V"])) {
            $this->verbose = true;
        }
        if (isset($arg["p"])) {
            $this->pset = $this->conf->pset_by_key_or_title($arg["p"]);
            if (!$this->pset) {
                throw new CommandLineException("Pset `{$arg["p"]}` not found");
            }
        }
        $gfc = new GradeFormulaCompiler($conf);
        $hdr = [];
        foreach ($arg["c"] ?? ["email"] as $c) {
            if ($c === "first" || $c === "last" || $c === "email" || $c === "year") {
                $hdr[] = $c;
                $this->columns[] = $c;
            } else if (($gf = $gfc->parse($c, $this->pset))) {
                $hdr[] = $c;
                $this->columns[] = $gf;
            } else {
                fwrite(STDERR, $gfc->ms->full_feedback_text());
                throw new CommandLineException("Column `{$c}` not found");
            }
        }
        $this->csvg = (new CsvGenerator())->set_header($hdr)->set_stream(STDOUT);
    }

    /** @return int */
    function run_or_warn() {
        $gfc = new GradeFormulaCompiler($this->conf);
        $gf = $gfc->parse_search($this->q, $this->pset);
        $sset = StudentSet::make_all($this->conf);
        if ($this->pset) {
            $sset->set_pset($this->pset);
        } else {
            $psets = [];
            $gf->export_psets($psets);
            if (count($psets) === 1) {
                $sset->set_pset($psets[0]);
            }
        }
        $any = false;
        foreach ($sset->users() as $u) {
            $info = $sset->info($u->contactId);
            if (!$gf->evaluate($u, $info)) {
                continue;
            }
            $x = [];
            foreach ($this->columns as $c) {
                if ($c === "first") {
                    $x[] = $u->firstName;
                } else if ($c === "last") {
                    $x[] = $u->lastName;
                } else if ($c === "email") {
                    $x[] = $u->email;
                } else if ($c === "year") {
                    $x[] = $u->studentYear;
                } else if ($c instanceof GradeFormula) {
                    $x[] = $c->evaluate($u, $info);
                } else {
                    $x[] = "";
                }
            }
            $this->csvg->add_row($x);
            $any = true;
        }
        $this->csvg->flush();
        return $any ? 0 : 1;
    }

    /** @return Search_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "dry-run,d Do not modify repository permissions",
            "V,verbose Be verbose",
            "p:,pset: Set problem set context",
            "c[],column[] Set output columns",
            "help !"
        )->description("Fix permissions in peteramati repo directories.
Usage: php batch/search.php [OPTIONS]")
         ->helpopt("help")->parse($argv);
        $self = new Search_Batch($conf, $arg);
        return $self;
    }
}


if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(Search_Batch::make_args(Conf::$main, $argv)->run_or_warn());
}
