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
            foreach ($this->sset->all_cpi_for($u, $pset) as $cpi) {
                echo sprintf("%d %s %s\n", $cpi->commitat, $cpi->hash, $u->email);
            }
        }
    }

    function run_all() {
        foreach ($this->conf->psets() as $pset) {
            if (!$pset->gitless_grades) {
                $this->run_pset($pset);
            }
        }
    }
}

(new GradeAnomalies($Conf, StudentSet::ALL_ENROLLED))->run_all();
