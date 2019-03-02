<?php
// api/api_gradestatistics.php -- Peteramati API for grading
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Series {
    public $n;
    public $sum;
    public $sumsq;
    public $series;
    public $cdf;
    private $calculated;

    public function __construct() {
        $this->n = $this->sum = $this->sumsq = 0;
        $this->byg = $this->series = array();
        $this->calculated = false;
    }

    public function add($g) {
        $this->series[] = $g;
        $this->n += 1;
        $this->sum += $g;
        $this->sumsq += $g * $g;
        $this->calculated = false;
    }

    private function calculate() {
        sort($this->series);
        $this->cdf = array();
        $last = false;
        $subtotal = 0;
        $cdfi = 0;
        for ($i = 0; $i < count($this->series); ++$i) {
            if ($this->series[$i] !== $last) {
                $this->cdf[] = $last = $this->series[$i];
                $this->cdf[] = $i + 1;
                $cdfi += 2;
            } else
                $this->cdf[$cdfi - 1] = $i + 1;
        }
        $this->calculated = true;
    }

    public function summary() {
        if (!$this->calculated)
            $this->calculate();

        $r = (object) array("n" => $this->n, "cdf" => $this->cdf);
        if ($this->n != 0) {
            $r->mean = $this->sum / $this->n;

            $halfn = (int) ($this->n / 2);
            if ($this->n % 2 == 0)
                $r->median = ($this->series[$halfn-1] + $this->series[$halfn]) / 2.0;
            else
                $r->median = $this->series[$halfn];

            if ($this->n > 1)
                $r->stddev = sqrt(($this->sumsq - $this->sum * $this->sum / $this->n) / ($this->n - 1));
            else
                $r->stddev = 0;
        }

        return $r;
    }

    static public function truncate_summary_below($r, $cutoff) {
        /*$cx = $cutoff * $r->n;
        for ($i = 0; $i < count($r->cdf) && $r->cdf[$i+1] < $cx; $i += 2) {
        }
        if ($i !== 0) {
            $r->cdf = array_slice($r->cdf, $i);
            $r->cutoff = $cutoff;
        }*/
        $r->cutoff = $cutoff;
    }
}

class API_GradeStatistics {
    static function compute(Pset $pset) {
        global $Now;

        $series = new Series;
        $xseries = $noextra_series = $xnoextra_series = null;
        if ($pset->has_extra)
            $noextra_series = new Series;
        if ($pset->separate_extension_grades)
            $xseries = new Series;
        if ($xseries && $noextra_series)
            $xnoextra_series = new Series;
        $has_extra = $has_xextra = false;

        if (is_int($pset->grades_visible))
            $notdropped = "(not c.dropped or c.dropped<$pset->grades_visible)";
        else
            $notdropped = "not c.dropped";
        $q = "select cn.notes, c.extension from ContactInfo c\n";
        if ($pset->gitless_grades)
            $q .= "\t\tjoin ContactGrade cn on (cn.cid=c.contactId and cn.pset={$pset->id})";
        else
            $q .= "\t\tjoin ContactLink l on (l.cid=c.contactId and l.type=" . LINK_REPO . " and l.pset={$pset->id})
                join RepositoryGrade rg on (rg.repoid=l.link and rg.pset={$pset->id} and not rg.placeholder)
                join CommitNotes cn on (cn.pset=rg.pset and cn.bhash=rg.gradebhash)\n";
        $result = $pset->conf->qe_raw($q . " where $notdropped");
        while (($row = $result->fetch_row())) {
            if (($g = ContactView::pset_grade(json_decode($row[0]), $pset))) {
                $series->add($g->total);
                if ($xseries && $row[1])
                    $xseries->add($g->total);
                if ($noextra_series) {
                    $noextra_series->add($g->total_noextra);
                    if ($g->total_noextra != $g->total)
                        $has_extra = true;
                }
                if ($xnoextra_series && $row[1]) {
                        $xnoextra_series->add($g->total_noextra);
                    if ($g->total_noextra != $g->total)
                        $has_xextra = true;
                }
            }
        }
        Dbl::free($result);

        $r = $series->summary();
        if ($xseries && $xseries->n)
            $r->extension = $xseries->summary();
        if ($has_extra)
            $r->noextra = $noextra_series->summary();
        if ($has_xextra)
            $r->extension_noextra = $xnoextra_series->summary();

        $pgj = $pset->gradeinfo_json(false);
        if ($pgj && isset($pgj->maxgrades->total)) {
            $r->maxtotal = $pgj->maxgrades->total;
            if (isset($r->extension))
                $r->extension->maxtotal = $pgj->maxgrades->total;
            if (isset($r->noextra))
                $r->noextra->maxtotal = $pgj->maxgrades->total;
            if (isset($r->extension_noextra))
                $r->extension_noextra->maxtotal = $pgj->maxgrades->total;
        }

        $pset->conf->save_setting("__gradets.p" . $pset->id, $Now);
        $pset->conf->save_gsetting("__gradestat.p" . $pset->id, $Now, $r);
        return $r;
    }

    static function run(Contact $user, Qrequest $qreq, APIData $api) {
        global $Now;
        $pset = $api->pset;
        $gsv = $pset->grade_statistics_visible;
        if (!$user->isPC
            && !($gsv === true || (is_int($gsv) && $gsv <= $Now))) {
            $info = PsetView::make($api->pset, $api->user, $user);
            if (!$info->user_can_view_grade_statistics())
                return ["error" => "Grades are not visible now"];
        }

        $gradets = $pset->conf->setting("__gradets.p" . $pset->id);
        if ($gradets
            && $gradets >= $Now - 7200
            && isset($_SERVER["HTTP_IF_NONE_MATCH"])
            && $_SERVER["HTTP_IF_NONE_MATCH"] === "\"" . md5($pset->config_signature . "." . $gradets) . "\"") {
            header("HTTP/1.0 304 Not Modified");
            header("Cache-Control: max-age=30,must-revalidate,private");
            exit;
        }

        if (!$gradets
            || !($r = $pset->conf->gsetting_json("__gradestat.p" . $pset->id))) {
            $r = self::compute($pset);
            $gradets = $Now;
        }

        $r->ok = true;
        if (!$user->extension)
            unset($r->extension, $r->extension_noextra);
        if (!$user->isPC && $pset->grade_cdf_cutoff) {
            Series::truncate_summary_below($r, $pset->grade_cdf_cutoff);
            if (isset($r->extension))
                Series::truncate_summary_below($r->extension, $pset->grade_cdf_cutoff);
            if (isset($r->noextra))
                Series::truncate_summary_below($r->noextra, $pset->grade_cdf_cutoff);
            if (isset($r->extension_noextra))
                Series::truncate_summary_below($r->extension_noextra, $pset->grade_cdf_cutoff);
        }

        header("Cache-Control: max-age=30,must-revalidate,private");
        header("ETag: \"" . md5($pset->config_signature . "." . $gradets) . "\"");
        return $r;
    }
}
