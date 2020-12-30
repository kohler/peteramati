<?php
// facefetch.php -- Peteramati script for fetching & storing faces
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

$ConfSitePATH = preg_replace('/\/batch\/[^\/]+/', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "n:", ["name:"]);

class GradeAnomalies {
    /** @var Conf */
    public $conf;
    /** @var StudentSet */
    public $sset;

    function __construct(Conf $conf, $flags) {
        $this->conf = $conf;
        $this->sset = new StudentSet($conf->site_contact(), $flags);
    }

    function run_pset(Pset $pset) {
        foreach ($this->sset->users() as $u) {
            $ginfo = $this->sset->info_for($u, $pset);
            $tot0 = $ginfo->grade_total();
            $ex0 = $tot0 - $ginfo->grade_total_noextra();
            $any_base = false;
            foreach ($this->sset->all_info_for($u, $pset) as $info) {
                $tot1 = $info->grade_total();
                $ex1 = $tot1 - $info->grade_total_noextra();
                if ($tot0 < $tot1 || $ex0 < $ex1) {
                    if (!$any_base) {
                        echo sprintf("\n%s %s\n%s %s %g+%g Grading commit\n",
                                     $pset->key, $u->email,
                                     strftime("%Y-%m-%dT%H:%M", $ginfo->commitat()),
                                     substr($ginfo->hash() ?? "--------", 0, 8), $tot0, $ex0);
                        $any_base = true;
                    }
                    echo sprintf("%s %s %g+%g %s commit\n",
                                 strftime("%Y-%m-%dT%H:%M", $info->commitat()),
                                 substr($info->hash() ?? "--------", 0, 8), $tot1, $ex1,
                                 $info->commitat() > $ginfo->commitat() ? "Later" : "Earlier");
                }
            }
        }
    }

    function run_all() {
        foreach ($this->conf->psets() as $pset) {
            if (!$pset->disabled && !$pset->gitless_grades) {
                $this->run_pset($pset);
            }
        }
    }
}

(new GradeAnomalies($Conf, StudentSet::ALL_ENROLLED))->run_all();
