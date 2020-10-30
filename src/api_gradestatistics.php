<?php
// api/api_gradestatistics.php -- Peteramati API for grading
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Series {
    /** @var int */
    public $n = 0;
    /** @var float */
    public $sum = 0.0;
    /** @var float */
    public $sumsq = 0.0;
    /** @var array<int,int|float> */
    public $series = [];
    /** @var list<int|float> */
    public $cdf;
    /** @var bool */
    private $calculated = false;
    /** @var null|int|float */
    private $median;

    /** @param int $cid
     * @param int|float $g */
    function add($cid, $g) {
        $this->series[$cid] = $g;
        $this->n += 1;
        $this->sum += $g;
        $this->sumsq += $g * $g;
        $this->calculated = false;
    }

    function calculate() {
        if (!$this->calculated) {
            asort($this->series);
            $this->cdf = [];
            $this->median = null;
            $lastg = false;
            $subtotal = 0;
            $i = $cdfi = 0;
            $halfn = (int) ($this->n / 2);
            foreach ($this->series as $g) {
                if ($i === $halfn) {
                    if ($this->n % 2 === 0) {
                        $this->median = ($lastg + $g) / 2.0;
                    } else {
                        $this->median = $g;
                    }
                }

                ++$i;
                if ($i === 1 || $g !== $lastg) {
                    $this->cdf[] = $lastg = $g;
                    $this->cdf[] = $i;
                    $cdfi += 2;
                } else {
                    $this->cdf[$cdfi - 1] = $i;
                }
            }
            $this->calculated = true;
        }
    }

    function summary($pcview = false) {
        $this->calculate();
        $r = (object) ["n" => $this->n, "cdf" => $this->cdf];
        if ($pcview) {
            $r->cdfu = array_keys($this->series);
        }
        if ($this->n != 0) {
            $r->mean = $this->mean();
            $r->median = $this->median;
            $r->stddev = $this->stddev();
        }
        return $r;
    }

    /** @return ?float */
    function mean() {
        return $this->n ? $this->sum / $this->n : null;
    }

    /** @return null|int|float */
    function median() {
        $this->calculate();
        return $this->median;
    }

    /** @return ?float */
    function stddev() {
        if ($this->n > 1) {
            return sqrt(($this->sumsq - $this->sum * $this->sum / $this->n) / ($this->n - 1));
        } else {
            return $this->n ? 0.0 : false;
        }
    }

    static function truncate_summary_below($r, $cutoff) {
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
    static function compute(Pset $pset, $pcview) {
        $series = new Series;
        $xseries = $noextra_series = $xnoextra_series = null;
        if ($pset->has_extra) {
            $noextra_series = new Series;
        }
        if ($pset->separate_extension_grades) {
            $xseries = new Series;
        }
        if ($xseries && $noextra_series) {
            $xnoextra_series = new Series;
        }
        $has_extra = $has_xextra = false;

        if ($pset->grades_visible_at) {
            $notdropped = "(not c.dropped or c.dropped<$pset->grades_visible_at)";
        } else {
            $notdropped = "not c.dropped";
        }
        $q = "select c.contactId, cn.notes, c.extension from ContactInfo c\n";
        if ($pset->gitless_grades) {
            $q .= "\t\tjoin ContactGrade cn on (cn.cid=c.contactId and cn.pset={$pset->id})";
        } else {
            $q .= "\t\tjoin ContactLink l on (l.cid=c.contactId and l.type=" . LINK_REPO . " and l.pset={$pset->id})
                join RepositoryGrade rg on (rg.repoid=l.link and rg.pset={$pset->id} and not rg.placeholder)
                join CommitNotes cn on (cn.pset=rg.pset and cn.bhash=rg.gradebhash)\n";
        }
        $result = $pset->conf->qe_raw($q . " where $notdropped");
        while (($row = $result->fetch_row())) {
            if (($g = ContactView::pset_grade(json_decode($row[1]), $pset))) {
                $cid = +$row[0];
                $series->add($cid, $g->total);
                if ($xseries && $row[2]) {
                    $xseries->add($cid, $g->total);
                }
                if ($noextra_series) {
                    $noextra_series->add($cid, $g->total_noextra);
                    if ($g->total_noextra != $g->total) {
                        $has_extra = true;
                    }
                }
                if ($xnoextra_series && $row[2]) {
                    $xnoextra_series->add($cid, $g->total_noextra);
                    if ($g->total_noextra != $g->total) {
                        $has_xextra = true;
                    }
                }
            }
        }
        Dbl::free($result);

        $r = (object) ["pset" => $pset->urlkey, "psetid" => $pset->id,
                       "all" => $series->summary($pcview)];
        if ($xseries && $xseries->n) {
            $r->extension = $xseries->summary($pcview);
        }
        if ($has_extra) {
            $r->noextra = $noextra_series->summary($pcview);
        }
        if ($has_xextra) {
            $r->extension_noextra = $xnoextra_series->summary($pcview);
        }

        $pgj = $pset->gradeinfo_json($pcview);
        if ($pgj && isset($pgj->maxgrades->total)) {
            $r->maxtotal = $r->all->maxtotal = $pgj->maxgrades->total;
            if (isset($r->extension)) {
                $r->extension->maxtotal = $pgj->maxgrades->total;
            }
            if (isset($r->noextra)) {
                $r->noextra->maxtotal = $pgj->maxgrades->total;
            }
            if (isset($r->extension_noextra)) {
                $r->extension_noextra->maxtotal = $pgj->maxgrades->total;
            }
        }

        $ge = $pset->visible_grades_in_total($pcview);
        if (count($ge) === 1) {
            reset($ge);
            $r->entry = current($ge)->json($pcview);
        }

        return $r;
    }

    static private function etag(Pset $pset, Contact $user, $ts) {
        $mid = $user->isPC ? "-pc-" : ".";
        return "\"" . md5("{$pset->config_signature}{$mid}{$ts}") . "\"";
    }

    static function run(Contact $user, Qrequest $qreq, APIData $api) {
        $pset = $api->pset;
        $gsv = $pset->grade_statistics_visible;
        if (!$user->isPC && $gsv !== 1) {
            $info = PsetView::make($api->pset, $api->user, $user);
            if (!$info->user_can_view_grade_statistics()) {
                return ["error" => "Grades are not visible now"];
            }
        }

        $suffix = ($user->isPC ? ".pp" : ".p") . $pset->id;
        $gradets = $pset->conf->setting("__gradets$suffix");
        if ($gradets < @filemtime(__FILE__)) {
            $gradets = 0;
        }
        if ($gradets
            && $gradets >= Conf::$now - 7200
            && isset($_SERVER["HTTP_IF_NONE_MATCH"])
            && $_SERVER["HTTP_IF_NONE_MATCH"] === self::etag($pset, $user, $gradets)) {
            header("HTTP/1.0 304 Not Modified");
            header("Cache-Control: max-age=30,must-revalidate,private");
            exit;
        }

        if (!$gradets
            || !($r = $pset->conf->gsetting_json("__gradestat$suffix"))
            || ($user->isPC && isset($r->all->xcdf))) {
            $r = self::compute($pset, $user->isPC);
            $gradets = Conf::$now;
            $pset->conf->save_setting("__gradets$suffix", Conf::$now);
            $pset->conf->save_gsetting("__gradestat$suffix", Conf::$now, $r);
        }

        $r->ok = true;
        if (!$user->isPC && !$user->extension) {
            unset($r->extension, $r->extension_noextra);
        }
        if (!$user->isPC && $pset->grade_cdf_cutoff) {
            $r->cutoff = $pset->grade_cdf_cutoff;
            Series::truncate_summary_below($r->all, $pset->grade_cdf_cutoff);
            if (isset($r->extension)) {
                Series::truncate_summary_below($r->extension, $pset->grade_cdf_cutoff);
            }
            if (isset($r->noextra)) {
                Series::truncate_summary_below($r->noextra, $pset->grade_cdf_cutoff);
            }
            if (isset($r->extension_noextra)) {
                Series::truncate_summary_below($r->extension_noextra, $pset->grade_cdf_cutoff);
            }
        }

        header("Cache-Control: max-age=30,must-revalidate,private");
        header("ETag: " . self::etag($pset, $user, $gradets));
        return $r;
    }
}
