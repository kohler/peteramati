<?php
// report.php -- Peteramati report page
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
global $Conf, $Qreq, $Me;
ContactView::set_path_request($Qreq, ["/p"], $Conf);
if ($Me->is_empty() || !$Me->isPC) {
    $Me->escape();
}

class Report_Page {
    /** @var Conf */
    public $conf;
    /** @var Pset */
    public $pset;
    /** @var Qrequest */
    public $qreq;
    /** @var Contact */
    public $viewer;
    /** @var string */
    public $report;
    /** @var string */
    public $filename;
    /** @var list<string> */
    public $fields;
    /** @var bool */
    public $want_history = false;

    function __construct(Pset $pset, Qrequest $qreq, Contact $viewer) {
        $this->conf = $viewer->conf;
        $this->pset = $pset;
        $this->qreq = $qreq;
        $this->viewer = $viewer;
        $this->report = $qreq->report ?? "default";
        foreach ($this->pset->reports as $report) {
            if ($report->key === $this->report) {
                $this->filename = $report->filename ?? $report->key;
                $this->fields = $report->fields;
                break;
            }
        }
        if (!$this->fields) {
            if ($this->report === "git" && !$pset->gitless) {
                $this->filename = "git";
                $this->fields = ["last", "first", "email", "user", "huid", "year", "repo", "hash", "isgrade", "commitat"];
            } else if ($this->report === "githistory" && !$pset->gitless) {
                $this->filename = "githistory";
                $this->fields = ["user", "repo", "commitat", "hash", "isgrade", "subject"];
                $this->want_history = true;
            } else if ($this->report === "default") {
                $this->filename = "grades";
                $this->fields = ["last", "first", "email", "user", "huid", "year", "total", "late_hours"];
                foreach ($this->pset->tabular_grades() as $ge) {
                    $this->fields[] = $ge->key;
                }
            } else if ($this->report === "nameemail") {
                $this->filename = "nameemail";
                $this->fields = ["email", "last", "first"];
            }
        }
    }

    private function add_row(PsetView $info, CsvGenerator $csv, $fobj) {
        $x = [];
        foreach ($fobj as $f) {
            if ($f instanceof GradeEntry) {
                if (($v = $info->grade_value($f)) !== null) {
                    $x[$f->key] = $f->unparse_value($v);
                }
            } else if (is_array($f)) {
                $x[$f[0]] = $f[1]->evaluate($info->user, $info);
            } else if ($f === "first") {
                if (!$info->user->is_anonymous) {
                    $x[$f] = $info->user->firstName;
                }
            } else if ($f === "last") {
                if (!$info->user->is_anonymous) {
                    $x[$f] = $info->user->lastName;
                }
            } else if ($f === "email") {
                if (!$info->user->is_anonymous) {
                    $x[$f] = $info->user->email;
                }
            } else if ($f === "huid") {
                $x[$f] = $info->user->huid;
            } else if ($f === "user") {
                if ($info->user->is_anonymous) {
                    $x[$f] = $info->user->anon_username;
                } else {
                    $x[$f] = $info->user->email;
                }
            } else if ($f === "year") {
                $x[$f] = $info->user->studentYear;
            } else if ($f === "repo") {
                if ($info->repo) {
                    $x[$f] = $info->repo->url;
                }
            } else if ($f === "hash") {
                $x[$f] = $info->hash();
            } else if ($f === "isgrade") {
                if ($info->is_grading_commit()) {
                    $x[$f] = "yes";
                } else if ($info->is_do_not_grade() || $info->has_grading_commit()) {
                    $x[$f] = "no";
                }
            } else if ($f === "commitat") {
                $t = $info->commitat();
                $x[$f] = $t ? date("Y-m-d\\TH:i:s", $t) : "";
            } else if ($f === "subject") {
                $c = $info->commit();
                $x[$f] = $c ? $c->subject : null;
            }
        }
        $csv->add_row($x);
    }

    function run() {
        $this->conf->set_multiuser_page();
        if (!$this->report) {
            http_response_code(404);
            $this->conf->header($this->pset->title . " &gt; Report", "home");
            $this->conf->error("Report not found.");
            $this->conf->footer();
            exit;
        }

        if (isset($this->qreq->anonymous)) {
            $anonymous = (bool) $this->qreq->anonymous;
        } else {
            $anonymous = $this->pset->anonymous;
        }

        if (trim((string) $this->qreq->users) === "") {
            $sset = new StudentSet($this->viewer, StudentSet::ALL);
        } else {
            $us = [];
            foreach (explode(" ", $this->qreq->users) as $user) {
                if ($user === "") {
                    continue;
                } else if (ctype_digit($user) && strlen($user) < 8) {
                    if (($u = $this->conf->user_by_id(intval($user)))) {
                        $u->set_anonymous($anonymous);
                    }
                } else {
                    $u = $this->conf->user_by_whatever($user);
                }
                if ($u) {
                    $us[] = $u;
                }
            }
            $sset = StudentSet::make_for($us, $this->viewer);
        }
        $sset->set_pset($this->pset);
        $csv = new CsvGenerator;
        $csv->select($this->fields);

        $fobj = [];
        $gfc = new GradeFormulaCompiler($this->conf);
        foreach ($this->fields as $ft) {
            if (in_array($ft, ["first", "last", "email", "user", "year", "huid", "repo", "hash", "commitat", "isgrade", "subject"])) {
                $fobj[] = $ft;
            } else if (($ge = $this->pset->gradelike_by_key($ft))) {
                $fobj[] = $ge;
            } else if (($fm = $gfc->parse($ft, $this->pset->placeholder_entry()))) {
                $fobj[] = [$ft, $fm];
            }
        }

        foreach ($sset as $info) {
            if ($this->want_history) {
                $ch = $info->commit_list();
                foreach ($ch as $c) {
                    $info->set_commit($c);
                    $this->add_row($info, $csv, $fobj);
                }
            } else {
                $this->add_row($info, $csv, $fobj);
            }
        }

        $csv->set_filename("{$this->pset->nonnumeric_key}-{$this->filename}.csv");
        $csv->emit();
        exit;
    }
}

(new Report_Page(ContactView::find_pset_redirect($Qreq->pset, $Me), $Qreq, $Me))->run();
