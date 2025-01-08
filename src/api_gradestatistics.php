<?php
// api/api_gradestatistics.php -- Peteramati API for grading
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
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

    private function calculate() {
        if (!$this->calculated) {
            asort($this->series);
            $this->cdf = [];
            $this->median = null;
            $lastg = 0.0;
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

    /** @param bool $pcview
     * @return object */
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
            return $this->n ? 0.0 : null;
        }
    }

    static function truncate_summary_below($r, $cutoff) {
        $cx = $cutoff * $r->n;
        for ($i = 0; $i < count($r->cdf) && $r->cdf[$i+1] < $cx; $i += 2) {
        }
        if ($i !== 0) {
            $r->cdf = array_slice($r->cdf, $i);
            $r->cutoff = $cutoff;
        }
    }
}

class GradeStatistics_API {
    /** @param bool $pcview */
    static private function compute(Pset $pset, ?GradeEntry $ge, $pcview) {
        $vf = ($pcview ? VF_TF : 0) | VF_STUDENT_ANY;

        $series = new Series;
        $xseries = $noextra_series = $xnoextra_series = null;
        if (!$ge && $pset->has_extra) {
            $noextra_series = new Series;
        }
        if ($pset->separate_extension_grades) {
            $xseries = new Series;
        }
        if ($xseries && $noextra_series) {
            $xnoextra_series = new Series;
        }
        $has_extra = $has_xextra = false;
        $sva = $pset->scores_visible_at;

        $sset = new StudentSet($pset->conf->root_user(), StudentSet::ALL);
        $sset->set_pset($pset);
        $total = $noextra = null;

        foreach ($sset as $info) {
            if ($info->user->dropped
                && (!$sva || $info->user->dropped < $sva)) {
                continue;
            }
            if ($ge) {
                $total = $info->grade_value($ge);
            } else {
                $total = $info->visible_total();
            }
            if ($total === null) {
                continue;
            }
            $series->add($info->user->contactId, $total);
            if ($xseries && $info->user->extension) {
                $xseries->add($info->user->contactId, $total);
            }
            if (!$noextra_series
                || ($noextra = $info->visible_total_noextra()) === null) {
                continue;
            }
            $noextra_series->add($info->user->contactId, $noextra);
            $has_extra = $has_extra || ($total != $noextra);
            if ($xnoextra_series && $info->user->extension) {
                $xnoextra_series->add($info->user->contactId, $noextra);
                $has_xextra = $has_xextra || ($total != $noextra);
            }
        }

        $r = (object) [
            "pset" => $pset->urlkey,
            "psetid" => $pset->id,
            "series" => ["all" => $series->summary($pcview)]
        ];
        if ($xseries && $xseries->n) {
            $r->series["extension"] = $xseries->summary($pcview);
        }
        if ($has_extra) {
            $r->series["noextra"] = $noextra_series->summary($pcview);
        }
        if ($has_xextra) {
            $r->series["extension_noextra"] = $xnoextra_series->summary($pcview);
        }

        $maxtotal = 0;
        if ($ge) {
            $nge = 1;
            $lastge = $ge;
            if ($pcview || $ge->max_visible) {
                $maxtotal = $ge->max;
            } else {
                $maxtotal = 0;
            }
        } else {
            $nge = 0;
            $lastge = null;
            foreach ($pset->visible_grades($vf) as $ge) {
                if ($ge->no_total) {
                    continue;
                }
                ++$nge;
                $lastge = $ge;
                if ($ge->max
                    && ($pcview || $ge->max_visible)
                    && !$ge->is_extra) {
                    $maxtotal += $ge->max;
                }
            }
        }
        if ($maxtotal !== 0) {
            $r->maxtotal = $maxtotal;
            foreach ($r->series as $s) {
                $s->maxtotal = $maxtotal;
            }
        }
        if ($nge === 1) {
            $r->entry = $lastge->json($vf, null);
        }

        return $r;
    }

    static private function etag(Pset $pset, Contact $user, $ts) {
        $pfx = $user->isPC ? "t1-" : ($user->extension ? "x1-" : "c1-");
        return "\"" . md5("{$pfx}{$pset->config_signature}-{$ts}") . "\"";
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

        $ge = null;
        if ($qreq->grade) {
            $ge = $pset->gradelike_by_key($qreq->grade);
            if (!$user->isPC) {
                return ["error" => "Permission error"];
            } else if (!$ge) {
                return ["error" => "No such grade"];
            }
        }

        $psuffix = $gsuffix = ($user->isPC ? ".pp" : ".p") . $pset->id;
        if ($ge) {
            $gsuffix .= ".g{$ge->key}";
        }
        $gradets = $pset->conf->setting("__gradets{$psuffix}") ?? 0;
        if ($gradets < @filemtime(__FILE__)
            || $gradets < $pset->config_mtime) {
            $gradets = 0;
        }
        if ($gradets
            && $gradets >= Conf::$now - 7200
            && isset($_SERVER["HTTP_IF_NONE_MATCH"])
            && $_SERVER["HTTP_IF_NONE_MATCH"] === self::etag($pset, $user, $gradets)) {
            header("HTTP/1.0 304 Not Modified");
            header("Cache-Control: max-age=30,must-revalidate,private");
            exit(0);
        }

        if ($gradets
            && ($r = $pset->conf->gsetting_json("__gradestat{$gsuffix}"))) {
            if (isset($r->series)) {
                $r->series = (array) $r->series;
            }
        } else {
            $r = self::compute($pset, $ge, $user->isPC);
            $gradets = Conf::$now;
            $pset->conf->save_setting("__gradets{$psuffix}", Conf::$now);
            $pset->conf->save_gsetting("__gradestat{$gsuffix}", Conf::$now, $r);
        }

        $r->ok = true;
        if (!$user->isPC && !$user->extension) {
            unset($r->series["extension"], $r->series["extension_noextra"]);
        }
        if (!$user->isPC && $pset->grade_cdf_cutoff) {
            $r->cutoff = $pset->grade_cdf_cutoff;
            foreach ($r->series as $s) {
                Series::truncate_summary_below($s, $pset->grade_cdf_cutoff);
            }
        }

        header("Cache-Control: max-age=30,must-revalidate,private");
        header("ETag: " . self::etag($pset, $user, $gradets));
        return $r;
    }
}
