<?php
// forwardgrade.php -- Peteramati script for updating database information
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}

class ForwardGrade_Batch {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var GradeEntry */
    public $ge;
    /** @var list<string> */
    public $usermatch = [];
    /** @var bool */
    public $verbose = false;
    /** @var bool */
    public $dry_run = false;
    /** @var bool */
    public $nonzero = false;

    /** @param GradeEntry $ge
     * @param list<string> $usermatch
     * @param string $mode */
    function __construct(Conf $conf, $ge, $usermatch) {
        $this->conf = $conf;
        $this->pset = $ge->pset;
        $this->ge = $ge;
        $this->usermatch = $usermatch;
    }

    /** @param PsetView $info
     * @param ?string $hash
     * @return string */
    private function unparse_key($info, $hash = null) {
        $hash = $hash ?? $info->hash() ?? "none";
        return "~{$info->user->username}/{$info->pset->urlkey}/{$hash}";
    }

    /** @return int */
    function run() {
        $viewer = $this->conf->site_contact();
        $sset = StudentSet::make_globmatch($viewer, $this->usermatch);
        $sset->set_pset($this->pset);
        foreach ($sset as $info) {
            if (!$info->is_grading_commit()) {
                if ($this->verbose) {
                    fwrite(STDERR, $this->unparse_key($info) . ": not grading commit\n");
                }
                continue;
            }
            $oldgv = $info->grade_value($this->ge);
            if ($oldgv !== null && (!$this->nonzero || $oldgv > 0)) {
                if ($this->verbose) {
                    fwrite(STDERR, $this->unparse_key($info) . ": keeping {$oldgv}\n");
                }
                continue;
            }
            $commitat = $info->commitat();
            $newgv = null;
            foreach ($sset->all_cpi_for($info->user, $info->pset) as $cpi) {
                if ($cpi->commitat < $commitat
                    && ($gv = $cpi->grade_value($this->ge)) !== null) {
                    $newgv = $gv;
                    if ($this->verbose) {
                        fwrite(STDERR, $this->unparse_key($info, $cpi->hash()) . ": value {$gv}\n");
                    }
                }
            }
            if ($newgv === null || $newgv === $oldgv) {
                continue;
            }
            if ($this->verbose) {
                fwrite(STDERR, $this->unparse_key($info) . " := {$newgv}\n");
            }
            if (!$this->dry_run) {
                $info->update_grade_notes(["grades" => [$this->ge->key => $newgv]]);
            }
        }
        return 0;
    }

    /** @return ForwardGrade_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "u[],user[] Match these users",
            "nonzero",
            "V,verbose",
            "dry-run,d",
            "help"
        )->helpopt("help")
         ->minarg(1)
         ->maxarg(1)
         ->parse($argv);

        $garg = $arg["_"][0];
        $dot = strpos($garg, ".");
        if ($dot === false
            || !($pset = $conf->pset_by_key_or_title(substr($garg, 0, $dot)))) {
            throw new CommandLineException("Pset not found");
        }
        if (!($ge = $pset->gradelike_by_key_or_title(substr($garg, $dot + 1)))) {
            throw new CommandLineException("Grade not found");
        }

        $self = new ForwardGrade_Batch($conf, $ge, $arg["u"] ?? []);
        $self->verbose = isset($arg["V"]);
        $self->dry_run = isset($arg["dry-run"]);
        $self->nonzero = isset($arg["nonzero"]);
        return $self;
    }
}


if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(ForwardGrade_Batch::make_args(Conf::$main, $argv)->run());
}
