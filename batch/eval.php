<?php
// eval.php -- Peteramati script for evaluating formulae
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}


class Eval_Batch {
    /** @var Conf */
    public $conf;
    /** @var ?Pset */
    public $pset;
    /** @var bool */
    public $verbose = false;
    /** @var int */
    public $sset_flags;
    /** @var list<string> */
    public $usermatch = [];
    /** @var ?string */
    public $hash;
    /** @var list<GradeFormula> */
    public $formulas = [];
    /** @var list<string> */
    public $fstr = [];


    function __construct(Conf $conf, ?Pset $pset, $arg) {
        $this->conf = $conf;
        $this->pset = $pset;
        $this->sset_flags = 0;
        $umatch = [];
        foreach ($arg["u"] ?? [] as $u) {
            while (str_ends_with($u, ",")) {
                $u = substr($u, 0, -1);
            }
            if ($u !== "") {
                $umatch[] = $u;
            }
        }
        if (count($umatch) === 1 && $umatch[0] === "dropped") {
            $this->sset_flags |= StudentSet::DROPPED;
        } else {
            $this->sset_flags |= StudentSet::ENROLLED;
        }
        if (count($umatch) === 1 && $umatch[0] === "college") {
            $this->sset_flags |= StudentSet::COLLEGE;
        } else if (count($umatch) === 1 && $umatch[0] === "extension") {
            $this->sset_flags |= StudentSet::DCE;
        }
        if ($this->sset_flags === StudentSet::ENROLLED) {
            foreach ($umatch as $s) {
                if (str_starts_with($s, "[anon")) {
                    $this->usermatch[] = preg_replace('/([\[\]])/', '\\\\$1', $s . "*");
                } else {
                    $this->usermatch[] = "*{$s}*";
                }
            }
        }

        $gfc = new GradeFormulaCompiler($this->conf);
        foreach ($arg["_"] as $fstr) {
            if (($f = $gfc->parse($fstr, $this->pset))) {
                $this->formulas[] = $f;
                $this->fstr[] = $fstr;
            }
        }
        if ($gfc->ms->has_message()) {
            fwrite(STDERR, $gfc->ms->full_feedback_text());
        }
        if (empty($this->formulas)) {
            throw new CommandLineException("Nothing to do");
        }
    }

    /** @param string $s
     * @return bool */
    function match($s) {
        if ($s === null) {
            return false;
        }
        foreach ($this->usermatch as $m) {
            if (fnmatch($m, $s))
                return true;
        }
        return empty($this->usermatch);
    }

    /** @return bool */
    function test_user(Contact $user) {
        if (empty($this->usermatch)) {
            $user->set_anonymous($this->pset->anonymous);
            return true;
        } else if ($this->match($user->email)
                   || $this->match($user->github_username)) {
            $user->set_anonymous(false);
            return true;
        } else if ($this->match($user->anon_username)) {
            $user->set_anonymous(true);
            return true;
        } else {
            return false;
        }
    }

    /** @param PsetView $info
     * @param ?string $hash
     * @return string */
    private function unparse_key($info, $hash = null) {
        $hash = $hash ?? $info->hash() ?? "none";
        return "~{$info->user->username}/{$info->pset->urlkey}/{$hash}";
    }

    /** @return int */
    function run_or_warn() {
        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, $this->sset_flags, [$this, "test_user"]);
        if ($this->pset) {
            $sset->set_pset($this->pset);
        }
        $nsset = count($sset);

        $csvg = new CsvGenerator;
        $csvg->set_header(["first", "last", "email", "user", ...$this->fstr]);

        $infos = [];
        foreach ($sset->users() as $user) {
            $info = $sset->info($user->contactId);
            if ($this->hash && (!$info || !$info->repo || !$info->set_hash($this->hash, true))) {
                if ($nsset === 1 || $this->verbose) {
                    fwrite(STDERR, $this->unparse_key($info, $this->hash) . ": no such commit on {$info->branch}\n");
                }
                continue;
            }
            if ($user->is_anonymous) {
                $x = ["", "", "", $user->anon_username];
            } else {
                $x = [$user->firstName, $user->lastName, $user->email, $user->github_username];
            }
            foreach ($this->formulas as $f) {
                $x[] = $f->evaluate($user, $info);
            }
            $csvg->add_row($x);
        }

        if ($csvg->is_empty()) {
            fwrite(STDERR, "No matching users\n");
            return 1;
        }
        $csvg->unparse_to_stream(STDOUT);
        return 0;
    }

    /** @return Eval_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p:,pset: Problem set",
            "u[],user[] Match these users",
            "H:,hash:,commit: Use this commit",
            "V,verbose",
            "help"
        )->helpopt("help")
         ->minarg(1)
         ->parse($argv);
        if (($arg["p"] ?? "") === "") {
            $pset = null;
        } else {
            $pset = $conf->pset_by_key($arg["p"]);
            if (!$pset) {
                throw new CommandLineException("no such pset");
            }
        }
        $self = new Eval_Batch($conf, $pset, $arg);
        if (isset($arg["V"])) {
            $self->verbose = true;
        }
        if (isset($arg["H"])) {
            if (!($hp = CommitRecord::parse_hashpart($arg["H"]))) {
                throw new CommandLineException("bad `--commit`");
            }
            $self->hash = $hp;
        }
        return $self;
    }
}


if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(Eval_Batch::make_args(Conf::$main, $argv)->run_or_warn());
}
