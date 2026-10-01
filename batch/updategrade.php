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
    /** @var Contact */
    public $user;
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

    /** @param array $updates */
    function __construct(Pset $pset, Contact $user, $updates) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->user = $user;
        $this->updates = $updates;
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

    /** @param ?object $old */
    private function apply_max($old) {
        $grades = $this->updates["grades"] ?? null;
        if (!is_object($grades)) {
            return;
        }
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
        $info = PsetView::make($this->pset, $this->user, $viewer, $this->hash ?? "none");
        if (!$this->pset->gitless_grades) {
            if ($this->hash === null) {
                $info->set_hash(null);
            }
            if (!$info->hash()) {
                throw new CommandLineException("{$this->user->email}: no commit to grade");
            }
        }

        $key = "{$this->pset->key}/{$this->user->email}";
        if ($info->hash()) {
            $key .= "/" . substr($info->hash(), 0, 12);
        }
        $old = $info->grade_jnotes();
        if ($this->max) {
            $this->apply_max($old);
        }
        $new = json_update($old, $this->updates);
        CommitPsetInfo::clean_notes($new);
        if (json_encode($old ?? (object) []) === json_encode($new)) {
            fwrite(STDERR, "{$key}: no change\n");
            return 0;
        }

        fwrite(STDERR, "{$key}: " . ($this->dry_run ? "would update\n" : "updating\n"));
        fwrite(STDOUT, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        if (!$this->dry_run) {
            $info->update_grade_notes($this->updates);
        }
        return 0;
    }

    /** @return UpdateGrade_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "commit:,c: =HASH Update grades for commit HASH [grading commit]",
            "dry-run,d Print result without saving",
            "g[],grade[] =KEY=VALUE Set grade KEY to VALUE",
            "max Only raise grades: skip updates that would lower a grade",
            "force,f Allow updates to unknown grade entries",
            "help,h !"
        )->helpopt("help")
         ->description("Apply a JSON update to a user’s grade notes.
Usage: php batch/updategrade.php [-d] [--max] PSET USER JSON
       php batch/updategrade.php [-d] [--max] PSET USER < JSON
       php batch/updategrade.php [-d] [--max] PSET USER -g KEY=VALUE...

JSON is merged into the user’s grade notes; null values delete keys.
Example: '{\"grades\":{\"q1ag\":2}}'

With --grade, timermark values may be `now`, a Unix timestamp, or a date.")
         ->interleave(true)
         ->minarg(2)
         ->maxarg(3)
         ->parse($argv);

        if (!($pset = $conf->pset_by_key_or_title($arg["_"][0]))) {
            throw new CommandLineException("Pset `{$arg["_"][0]}` not found");
        }
        if (!($user = $conf->user_by_whatever($arg["_"][1]))) {
            throw new CommandLineException("User `{$arg["_"][1]}` not found");
        }
        if (isset($arg["_"][2]) || empty($arg["g"])) {
            $updates = json_decode($arg["_"][2] ?? stream_get_contents(STDIN));
            if (!is_object($updates)) {
                throw new CommandLineException("JSON update must be an object");
            }
        } else {
            $updates = (object) [];
        }

        // keep nested objects as objects so `{}` merges rather than replaces
        $self = new UpdateGrade_Batch($pset, $user, get_object_vars($updates));
        foreach ($arg["g"] ?? [] as $g) {
            $self->add_grade_arg($g);
        }
        $self->hash = $arg["commit"] ?? null;
        $self->dry_run = isset($arg["dry-run"]);
        $self->force = isset($arg["force"]);
        $self->max = isset($arg["max"]);
        return $self;
    }
}


if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(UpdateGrade_Batch::make_args(Conf::$main, $argv)->run());
}
