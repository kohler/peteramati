<?php
// runresponse.php -- Peteramati class representing JSON responses
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class RunResponse implements JsonSerializable {
    /** @var ?bool */
    public $ok;
    /** @var ?bool */
    public $error;
    /** @var string */
    public $pset;
    /** @var string */
    public $runner;
    /** @var int */
    public $repoid;
    /** @var ?string */
    public $hash;
    /** @var ?array */
    public $settings;
    /** @var int */
    public $timestamp;
    /** @var ?int */
    public $queueid;
    /** @var ?bool */
    public $done;
    /** @var null|'done'|'working'|'old' */
    public $status;
    /** @var ?string */
    public $data;
    /** @var ?int */
    public $offset;
    /** @var ?bool */
    public $timed;
    /** @var ?string */
    public $time_data;
    /** @var ?float */
    public $time_factor;
    /** @var ?string */
    public $message;
    /** @var ?mixed */
    public $result;

    static function make_base(RunnerConfig $runner, Repository $repo, $jobid) {
        $rr = new RunResponse;
        $rr->ok = true;
        $rr->pset = $runner->pset->urlkey;
        $rr->runner = $runner->name;
        $rr->repoid = $repo->repoid;
        $rr->timestamp = $jobid;
        return $rr;
    }

    function jsonSerialize() {
        $a = [];
        foreach (get_object_vars($this) as $k => $v) {
            if ($v !== null)
                $a[$k] = $v;
        }
        return $a;
    }
}
