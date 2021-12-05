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
    /** @var ?string */
    public $u;
    /** @var string */
    public $runner;
    /** @var int */
    public $repoid;
    /** @var ?string */
    public $hash;
    /** @var ?array */
    public $settings;
    /** @var ?list<string> */
    public $tags;
    /** @var int */
    public $timestamp;
    /** @var ?int */
    public $queueid;
    /** @var ?string */
    public $host;
    /** @var ?bool */
    public $done;
    /** @var null|'done'|'working'|'old' */
    public $status;
    /** @var ?string */
    public $data;
    /** @var ?int */
    public $offset;
    /** @var ?int */
    public $end_offset;
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

    /** @param ?Repository $repo
     * @return RunResponse */
    static function make(RunnerConfig $runner, $repo) {
        $rr = new RunResponse;
        $rr->ok = true;
        $rr->pset = $runner->pset->urlkey;
        $rr->runner = $runner->name;
        $rr->repoid = $repo ? $repo->repoid : 0;
        return $rr;
    }

    /** @param object $x
     * @return ?RunResponse */
    static function make_log($x) {
        if (is_string($x->pset ?? null) && is_string($x->runner ?? null)) {
            $rr = new RunResponse;
            $rr->pset = $x->pset;
            $rr->runner = $x->runner;
            $rr->repoid = is_int($x->repoid ?? null) ? $x->repoid : 0;
            if (is_string($x->hash ?? null)) {
                $rr->hash = $x->hash;
            }
            if (is_object($x->settings ?? null)) {
                $rr->settings = (array) $x->settings;
            }
            if (isset($x->tags)) {
                if (is_array($x->tags)) {
                    $rr->tags = $x->tags;
                } else if (is_string($x->tags)) {
                    $rr->tags = [$x->tags];
                }
            }
            if (is_int($x->timestamp ?? null)) {
                $rr->timestamp = $x->timestamp;
            }
            if (is_int($x->queueid ?? null)) {
                $rr->queueid = $x->queueid;
            }
            return $rr;
        } else {
            return null;
        }
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
