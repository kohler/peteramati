<?php
// updategrade.php -- Peteramati script for applying a JSON update to grade notes
// HotCRP and Peteramati are Copyright (c) 2006-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}

class UpdateGrade_Batch {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var list<string> */
    public $usermatch = [];
    /** @var int */
    public $sset_flags = 0;
    /** @var array */
    public $updates;
    /** @var ?string */
    public $hash;
    /** @var bool */
    public $dry_run = false;
    /** @var bool */
    public $force = false;
    /** @var bool */
    public $max = false;
    /** @var bool */
    public $present = false;

    /** @param list<string> $usermatch
     * @param array $updates */
    function __construct(Pset $pset, $usermatch, $updates) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->updates = $updates;
        $umatch = [];
        foreach ($usermatch as $u) {
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
    }

    /** @param ?string $s
     * @return bool */
    function match($s) {
        foreach ($this->usermatch as $m) {
            if ($s !== null && fnmatch($m, $s))
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

    /** @return list<string> */
    private function unknown_grade_keys() {
        $bad = [];
        foreach (["grades", "autogrades"] as $field) {
            $x = $this->updates[$field] ?? null;
            foreach (is_object($x) ? get_object_vars($x) : [] as $k => $v) {
                if (!$this->pset->gradelike_by_key($k))
                    $bad[] = "{$field}.{$k}";
            }
        }
        return $bad;
    }

    /** @param string $arg */
    function add_grade_arg($arg) {
        $eq = strpos($arg, "=");
        if ($eq === false || $eq === 0) {
            throw new CommandLineException("`--grade` expects KEY=VALUE");
        }
        $k = substr($arg, 0, $eq);
        if (!($ge = $this->pset->gradelike_by_key_or_title($k))) {
            throw new CommandLineException("Grade `{$k}` not found");
        }
        $vs = trim(substr($arg, $eq + 1));
        if ($ge->type === "timermark" && $vs !== "" && $vs !== "0") {
            // web form timermarks always mean “now”; here, accept a time
            $v = ctype_digit($vs) ? (int) $vs : $this->conf->parse_time($vs);
            if ($v === false || $v === null) {
                $v = new GradeError("Invalid time");
            }
        } else {
            $v = $ge->parse_value($vs, true);
        }
        if ($v instanceof GradeError) {
            throw new CommandLineException("`{$ge->key}`: {$v->message}");
        }
        $this->updates["grades"] = $this->updates["grades"] ?? (object) [];
        $this->updates["grades"]->{$ge->key} = $v === false ? null : $v;
    }

    /** @param object $grades
     * @param ?object $old */
    private function apply_present($grades, $old) {
        foreach (get_object_vars($grades) as $k => $v) {
            if (($old->grades->$k ?? null) === null)
                unset($grades->$k);
        }
    }

    /** @param object $grades
     * @param ?object $old */
    private function apply_max($grades, $old) {
        foreach (get_object_vars($grades) as $k => $v) {
            $ov = $old->grades->$k ?? null;
            if ($ov === null || is_int($ov) || is_float($ov)) {
                if ($ov !== null && ($v === null || ((is_int($v) || is_float($v)) && $v <= $ov))) {
                    unset($grades->$k);
                }
            } else {
                throw new CommandLineException("`--max`: `{$k}` is not numeric");
            }
        }
    }

    /** @return int */
    function run() {
        if (!$this->force && ($bad = $this->unknown_grade_keys())) {
            throw new CommandLineException("Unknown grade entries " . join(", ", $bad) . " (use --force to apply anyway)");
        }

        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, $this->sset_flags, [$this, "test_user"]);
        $sset->set_pset($this->pset);
        $status = 0;
        foreach ($sset as $info) {
            $status = max($status, $this->run_one($info));
        }
        return $status;
    }

    /** @return int */
    private function run_one(PsetView $info) {
        if ($this->pset->gitless_grades) {
            $info->set_hash("none");
        } else if (!$info->set_hash($this->hash, $this->hash !== null)
                   || !$info->hash()) {
            fwrite(STDERR, "{$this->pset->key}/{$info->user->email}: no commit to grade\n");
            return 1;
        }

        $key = "{$this->pset->key}/{$info->user->email}";
        if ($info->hash()) {
            $key .= "/" . substr($info->hash(), 0, 12);
        }
        $old = $info->grade_jnotes();
        $updates = $this->updates;
        if (($this->present || $this->max)
            && is_object($updates["grades"] ?? null)) {
            // copy: filtering is per user
            $updates["grades"] = clone $updates["grades"];
            if ($this->present) {
                $this->apply_present($updates["grades"], $old);
            }
            if ($this->max) {
                $this->apply_max($updates["grades"], $old);
            }
        }
        $new = json_update($old, $updates);
        CommitPsetInfo::clean_notes($new);
        if (json_encode($old ?? (object) []) === json_encode($new ?? (object) [])) {
            fwrite(STDERR, "{$key}: no change\n");
            return 0;
        }

        fwrite(STDERR, "{$key}: " . ($this->dry_run ? "would update\n" : "updating\n"));
        fwrite(STDOUT, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        if (!$this->dry_run) {
            $info->update_grade_notes($updates);
        }
        return 0;
    }

    /** @return UpdateGrade_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p:,pset: =PSET Problem set",
            "u[]+,user[]+ =USER Match these users [all]",
            "commit:,c: =HASH Update grades for commit HASH [grading commit]",
            "dry-run,d Print result without saving",
            "g[],grade[] =KEY=VALUE Set grade KEY to VALUE",
            "max Only raise grades: skip updates that would lower a grade",
            "present Only modify grades that are already present",
            "force,f Allow updates to unknown grade entries",
            "help,h !"
        )->helpopt("help")
         ->description("Apply a JSON update to a user’s grade notes.
Usage: php batch/updategrade.php [-d] [--max] [--present] -p PSET [-u USER...] [-- JSON]
       php batch/updategrade.php [-d] [--max] [--present] -p PSET [-u USER...] < JSON
       php batch/updategrade.php [-d] [--max] [--present] -p PSET [-u USER...] -g KEY=VALUE...

JSON is merged into the user’s grade notes; null values delete keys.
Example: '{\"grades\":{\"q1ag\":2}}'

With --grade, timermark values may be `now`, a Unix timestamp, or a date.")
         ->interleave(true)
         ->maxarg(1)
         ->parse($argv);

        $pset_arg = $arg["p"] ?? "";
        if (!($pset = $conf->pset_by_key_or_title($pset_arg))) {
            $pset_keys = array_values(array_map(function ($p) { return $p->key; }, $conf->psets()));
            throw (new CommandLineException($pset_arg === "" ? "`--pset` required" : "Pset `{$pset_arg}` not found"))->add_context("(Options are " . join(", ", $pset_keys) . ".)");
        }
        if (isset($arg["_"][0]) || empty($arg["g"])) {
            $updates = json_decode($arg["_"][0] ?? stream_get_contents(STDIN));
            if (!is_object($updates)) {
                throw new CommandLineException("JSON update must be an object");
            }
        } else {
            $updates = (object) [];
        }

        // keep nested objects as objects so `{}` merges rather than replaces
        $self = new UpdateGrade_Batch($pset, $arg["u"] ?? [], get_object_vars($updates));
        foreach ($arg["g"] ?? [] as $g) {
            $self->add_grade_arg($g);
        }
        $self->hash = $arg["commit"] ?? null;
        $self->dry_run = isset($arg["dry-run"]);
        $self->force = isset($arg["force"]);
        $self->max = isset($arg["max"]);
        $self->present = isset($arg["present"]);
        return $self;
    }
}


if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(UpdateGrade_Batch::make_args(Conf::$main, $argv)->run());
}
