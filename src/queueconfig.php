<?php
// queueconfig.php -- Peteramati configuration classes
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class QueueConfig {
    /** @var string
     * @readonly */
    public $key = "";
    /** @var ?int */
    public $nconcurrent;

    /** @param string $name
     * @return QueueConfig
     * @suppress PhanAccessReadOnlyProperty */
    static function make_named($name, $g) {
        $loc = ["_queues", $name];
        if (!is_object($g)) {
            throw new PsetConfigException("queue format error", $loc);
        }
        $qc = new QueueConfig;
        $qc->key = $g->key ?? $name;
        if (!is_string($qc->key)
            || !preg_match('/\A[~.A-Za-z][-_~.A-Za-z0-9]*\z/', $qc->key)) {
            throw new PsetConfigException("queue key format error", $loc);
        }
        $qc->nconcurrent = Pset::cint($loc, $g, "nconcurrent");
        return $qc;
    }
}
