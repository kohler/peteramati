<?php
// codeexport.php -- Peteramati script for exporting student code, diffs,
// line notes, and grades to a directory tree
// HotCRP and Peteramati are Copyright (c) 2006-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}

class CodeExport_Batch {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var string */
    public $outdir;
    /** @var list<string> */
    public $usermatch = [];
    /** @var bool */
    public $verbose = false;

    /** @var ?list<DiffConfig> */
    private $files;
    /** @var ?list<GradeEntry> */
    private $grades;
    /** @var 'grading'|'latest' */
    private $commit = "grading";
    /** @var bool */
    private $anonymous = true;
    /** @var bool */
    private $line_notes = true;
    /** @var bool */
    private $leaderboard = true;

    /** @var array<int,string> */
    private $user_names = [];
    /** @var list<array<string,mixed>> */
    private $index = [];

    /** @param object $spec */
    function __construct(Pset $pset, $spec, string $outdir) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->outdir = rtrim($outdir, "/");
        $this->parse_spec($spec);
    }

    /** @param object $spec */
    private function parse_spec($spec) {
        if (isset($spec->files)) {
            $this->files = [];
            if (is_object($spec->files)) {
                foreach (get_object_vars($spec->files) as $k => $v) {
                    $this->files[] = DiffConfig::make_json($v, $k);
                }
            } else if (is_array($spec->files)) {
                foreach ($spec->files as $v) {
                    $this->files[] = DiffConfig::make_json($v);
                }
            } else {
                throw new CommandLineException("Spec `files` must be an object or array");
            }
            foreach ($this->files as $i => $dc) {
                $dc->subposition = $i;
            }
        }
        if (isset($spec->grades)) {
            if (!is_array($spec->grades)) {
                throw new CommandLineException("Spec `grades` must be an array");
            }
            $this->grades = [];
            foreach ($spec->grades as $key) {
                if (!is_string($key) || !($ge = $this->pset->gradelike_by_key($key))) {
                    throw new CommandLineException("Grade `{$key}` not found in pset `{$this->pset->key}`");
                }
                $this->grades[] = $ge;
            }
        }
        if (isset($spec->commit)) {
            if ($spec->commit !== "grading" && $spec->commit !== "latest") {
                throw new CommandLineException("Spec `commit` must be `grading` or `latest`");
            }
            $this->commit = $spec->commit;
        }
        foreach (["anonymous", "line_notes", "leaderboard"] as $k) {
            if (isset($spec->$k)) {
                if (!is_bool($spec->$k)) {
                    throw new CommandLineException("Spec `{$k}` must be a boolean");
                }
                $this->$k = $spec->$k;
            }
        }
    }

    /** @return bool */
    function test_user(Contact $user) {
        if (empty($this->usermatch)) {
            return true;
        }
        foreach ([$user->email, $user->github_username, $user->anon_username] as $s) {
            foreach ($this->usermatch as $m) {
                if ($s !== null && fnmatch("*{$m}*", $s))
                    return true;
            }
        }
        return false;
    }

    /** @param int $cid
     * @return ?string */
    private function user_name($cid) {
        if ($cid <= 0) {
            return null;
        }
        if (!isset($this->user_names[$cid])) {
            $u = $this->conf->user_by_id($cid);
            $name = $u ? trim("{$u->firstName} {$u->lastName}") : "";
            $this->user_names[$cid] = $name !== "" ? $name : ($u ? $u->email : "[user{$cid}]");
        }
        return $this->user_names[$cid];
    }

    /** @param string $file
     * @return ?DiffConfig */
    private function file_config($file) {
        return DiffConfig::make_combined($this->files, $file);
    }

    /** @param string $file
     * @return bool */
    private function file_selected($file, bool $anonymous) {
        $dc = $this->file_config($file);
        if (!$dc || ($dc->flags & DiffConfig::F_IGNORE) !== 0) {
            return false;
        }
        if ($anonymous) {
            $pdc = $this->pset->find_diffconfig($file);
            if ((($dc->flags | ($pdc ? $pdc->flags : 0)) & DiffConfig::F_HIDE_IF_ANONYMOUS) !== 0) {
                return false;
            }
        }
        return true;
    }

    /** @param string $file
     * @return string */
    private function relative_file($file) {
        $ds = $this->pset->directory_slash;
        return $ds !== "" && str_starts_with($file, $ds) ? substr($file, strlen($ds)) : $file;
    }

    /** @param string $path
     * @param string $content */
    private function write_file($path, $content) {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new CommandLineException("{$dir}: Cannot create directory");
        }
        if (file_put_contents($path, $content) === false) {
            throw new CommandLineException("{$path}: Cannot write file");
        }
    }

    /** @return array<string,mixed> */
    private function note_json(LineNote $note) {
        $authors = [];
        foreach ($note->users as $cid) {
            $authors[] = $this->user_name($cid);
        }
        return [
            "file" => $this->relative_file($note->file),
            "lineid" => $note->lineid,
            "side" => $note->lineid[0] === "a" ? "handout" : "student",
            "line" => (int) substr($note->lineid, 1),
            "iscomment" => (bool) $note->iscomment,
            "authors" => $authors,
            "text" => Ftext::parse($note->ftext)[1]
        ];
    }

    /** @param array<string,mixed> $nj
     * @return string */
    private function render_note($nj) {
        $hdr = "!!! NOTE {$nj["lineid"]}";
        if (!empty($nj["authors"])) {
            $hdr .= " [" . join(", ", $nj["authors"]) . "]";
        }
        $out = [];
        foreach (explode("\n", rtrim($nj["text"])) as $i => $line) {
            $out[] = ($i === 0 ? "{$hdr}: " : "!!!   ") . $line;
        }
        return join("\n", $out) . "\n";
    }

    /** @param array<string,LineNote> $notes
     * @param array<string,true> &$placed
     * @return string */
    private function render_diff(DiffInfo $di, $notes, &$placed) {
        $f = $this->relative_file($di->filename);
        $hunks = [];
        $hi = -1;
        foreach ($di as $e) {
            $isline = $e[0] === " " || $e[0] === "-" || $e[0] === "+";
            if ($e[0] === "@" || ($hi < 0 && $isline)) {
                $hunks[] = [];
                ++$hi;
            }
            if ($isline) {
                $hunks[$hi][] = $e;
            }
        }

        $out = "diff {$f}\n--- a/{$f}\n+++ b/{$f}\n";
        foreach ($hunks as $h) {
            if (empty($h)) {
                continue;
            }
            $na = $nb = 0;
            foreach ($h as $e) {
                $na += $e[0] !== "+" ? 1 : 0;
                $nb += $e[0] !== "-" ? 1 : 0;
            }
            $out .= "@@ -{$h[0][1]},{$na} +{$h[0][2]},{$nb} @@\n";
            foreach ($h as $e) {
                $out .= $e[0] . $e[3] . "\n";
                $lineids = [];
                if ($e[0] !== "+") {
                    $lineids[] = "a{$e[1]}";
                }
                if ($e[0] !== "-") {
                    $lineids[] = "b{$e[2]}";
                }
                foreach ($lineids as $lid) {
                    if (($note = $notes[$lid] ?? null) && !isset($placed[$lid])) {
                        $out .= $this->render_note($this->note_json($note));
                        $placed[$lid] = true;
                    }
                }
            }
        }
        return $out;
    }

    private function export_one(PsetView $info) {
        $u = preg_replace('/[^-\w.]+/', "", $info->user_linkpart() ?? "");
        if ($u === "") {
            $u = "user" . $info->user->contactId;
        }
        $udir = "{$this->outdir}/{$u}";
        $anonymous = $info->user->is_anonymous;

        $hc = $info->base_handout_commit();
        $commit = $info->commit();
        $lnorder = $this->line_notes ? $info->visible_line_notes() : $info->empty_line_notes();
        $dctx = $info->diff_context($hc, $commit, $lnorder, PsetView::DCTX_NO_LOCAL_DIFFCONFIG);
        $dctx->no_full = true;

        // select files
        $selected = null;
        if ($this->files !== null) {
            $selected = [];
            foreach ($info->repo->ls_files($dctx->repo_hashb(), $dctx->repo_directory()) as $rf) {
                $f = $dctx->repo_to_pset_file($rf);
                if ($this->file_selected($f, $anonymous)) {
                    $selected[] = $f;
                }
            }
            foreach ($selected as $f) {
                $dctx->add_required_file($f);
            }
        }

        // diffs, with line notes inline
        $diff = [];
        if ($selected === null || !empty($selected)) {
            if ($selected !== null) {
                $dctx->set_allowed_files($selected);
            }
            $diff = $info->diff($dctx);
        }
        $patch = "";
        $placed = [];
        $diff_files = $unloaded_files = [];
        foreach ($diff as $fn => $di) {
            if ($di->fileless()) {
                continue;
            } else if (!$di->loaded()) {
                $unloaded_files[] = $this->relative_file($fn);
                continue;
            }
            $fplaced = [];
            $patch .= $this->render_diff($di, $lnorder->file($fn), $fplaced);
            foreach ($fplaced as $lid => $x) {
                $placed["{$fn}\0{$lid}"] = true;
            }
            $diff_files[] = $this->relative_file($fn);
        }

        // line notes
        $notes = $unplaced = [];
        foreach ($lnorder->fileorder() as $fn => $x) {
            foreach ($lnorder->file($fn) as $lid => $note) {
                if ($note->is_empty()) {
                    continue;
                }
                $nj = $this->note_json($note);
                $notes[] = $nj;
                if (!isset($placed["{$fn}\0{$lid}"])) {
                    $unplaced[] = $nj;
                }
            }
        }
        if (!empty($unplaced)) {
            $patch .= "\n!!! NOTES NOT SHOWN ABOVE\n";
            foreach ($unplaced as $nj) {
                $patch .= "!!! {$nj["file"]}:\n" . $this->render_note($nj);
            }
        }
        $this->write_file("{$udir}/diff.patch", $patch);

        // full files
        $full_files = [];
        foreach ($selected ?? [] as $f) {
            $dc = $this->file_config($f);
            if (($dc->flags & DiffConfig::F_FULL) === 0) {
                continue;
            }
            $content = $info->repo->gitrun(["git", "show", $dctx->repo_hashb() . ":" . $dctx->pset_to_repo_file($f)]);
            if (strpos($content, "\0") !== false) {
                continue;
            }
            $rf = $this->relative_file($f);
            $this->write_file("{$udir}/files/{$rf}", $content);
            $full_files[] = $rf;
        }

        // grades
        $grades = [];
        foreach ($this->grades ?? $info->visible_grades(VF_TF) as $ge) {
            $grades[$ge->key] = $info->grade_value($ge);
        }

        // leaderboard
        $lb = [];
        if ($this->leaderboard && ($lbx = $info->leaderboard_jxnote())) {
            foreach (get_object_vars($lbx) as $k => $rec) {
                $lb[$k] = $rec->value ?? null;
            }
        }

        $grader = $this->user_name($info->gradercid());
        $j = [
            "user" => $u,
            "pset" => $this->pset->key,
            "hash" => $info->hash(),
            "grading_commit" => $info->is_grading_commit(),
            "commitat" => $commit->commitat,
            "handout_hash" => $hc->hash,
            "grader" => $grader,
            "grades" => (object) $grades,
            "leaderboard" => (object) $lb,
            "files" => [
                "diff" => $diff_files,
                "full" => $full_files,
                "collapsed" => $unloaded_files
            ],
            "line_notes" => $notes
        ];
        $this->write_file("{$udir}/info.json", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        $row = ["user" => $u, "hash" => $info->hash(), "grading_commit" => $info->is_grading_commit() ? "yes" : "", "grader" => $grader];
        foreach ($grades as $k => $v) {
            $row[$k] = $v;
        }
        foreach ($lb as $k => $v) {
            $row["lb.{$k}"] = $v;
        }
        $row["line_notes"] = count($notes);
        $this->index[] = $row;

        if ($this->verbose) {
            fwrite(STDERR, "{$u}: " . count($diff_files) . " diffs, " . count($full_files) . " full files, " . count($notes) . " notes\n");
        }
    }

    /** @return int */
    function run() {
        if (!is_dir($this->outdir) && !mkdir($this->outdir, 0777, true)) {
            throw new CommandLineException("{$this->outdir}: Cannot create directory");
        }

        $viewer = $this->conf->site_contact();
        $sset = new StudentSet($viewer, StudentSet::ENROLLED, [$this, "test_user"]);
        $sset->set_pset($this->pset, $this->anonymous);
        foreach ($sset as $info) {
            if (!$info->repo) {
                continue;
            }
            if ($this->commit === "latest") {
                $info->set_latest_nontrivial_commit($sset);
            } else {
                $info->set_grading_or_latest_nontrivial_commit($sset);
            }
            if (!$info->hash() || !$info->commit()) {
                if ($this->verbose) {
                    fwrite(STDERR, $info->user_linkpart() . ": No commit\n");
                }
                continue;
            }
            $this->export_one($info);
        }

        $header = [];
        foreach ($this->index as $row) {
            foreach ($row as $k => $v) {
                $header[$k] = true;
            }
        }
        $out = fopen("{$this->outdir}/index.csv", "wb");
        $csv = (new CsvGenerator)->set_stream($out)->select(array_keys($header));
        foreach ($this->index as $row) {
            $csv->add_row($row);
        }
        $csv->flush();
        fclose($out);
        return 0;
    }

    /** @return CodeExport_Batch */
    static function make_args(Conf $conf, $argv) {
        $arg = (new Getopt)->long(
            "p:,pset: Problem set",
            "s:,spec: Export spec JSON file",
            "o:,output: Output directory",
            "u[]+,user[]+ Match these users",
            "V,verbose",
            "help"
        )->helpopt("help")
         ->description("Usage: php batch/codeexport.php -p PSET -s SPEC.json -o OUTDIR [-u USERS...]

The spec is a JSON object with these optional keys:
  files        Object or array of `diffs`-style entries (see devel/psetsjson.md).
               A file is exported if an entry matches and the combined entry
               is not `ignore`. Changed files appear in `diff.patch`; files
               with `full` are also written to `files/`. Default: the pset's
               normal diff.
  grades       List of grade keys. Default: all grades visible to TFs.
  commit       `grading` (default) or `latest`.
  anonymous    Name students by anonymous username (default true).
  line_notes   Include line notes (default true).
  leaderboard  Include leaderboard metrics (default true).")
         ->otheropt(false)
         ->parse($argv);

        $pset_arg = $arg["p"] ?? "";
        $pset = $conf->pset_by_key($pset_arg);
        if (!$pset) {
            $pset_keys = array_values(array_map(function ($p) { return $p->key; }, $conf->psets()));
            throw (new CommandLineException($pset_arg === "" ? "`--pset` required" : "Pset `{$pset_arg}` not found"))->add_context("(Options are " . join(", ", $pset_keys) . ".)");
        }
        if (!isset($arg["o"])) {
            throw new CommandLineException("`--output` required");
        }
        $spec = (object) [];
        if (isset($arg["s"])) {
            $t = $arg["s"] === "-" ? stream_get_contents(STDIN) : @file_get_contents($arg["s"]);
            if ($t === false) {
                throw new CommandLineException("{$arg["s"]}: Cannot read spec");
            }
            $spec = json_decode($t);
            if (!is_object($spec)) {
                throw new CommandLineException("{$arg["s"]}: Spec must be a JSON object");
            }
        }
        try {
            $self = new CodeExport_Batch($pset, $spec, $arg["o"]);
        } catch (PsetConfigException $ex) {
            throw new CommandLineException("Spec: " . $ex->getMessage());
        }
        foreach ($arg["u"] ?? [] as $u) {
            $u = trim($u, ", ");
            if ($u !== "") {
                $self->usermatch[] = $u;
            }
        }
        $self->verbose = isset($arg["V"]);
        return $self;
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(CodeExport_Batch::make_args(Conf::$main, $argv)->run());
}
