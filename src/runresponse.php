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
    /** @var string */
    public $repogid;
    /** @var ?string */
    public $url;
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
    public $eventsource;
    /** @var ?string */
    public $host;
    /** @var ?bool */
    public $done;
    /** @var ?bool */
    public $partial;
    /** @var null|'done'|'partial'|'working'|'old' */
    public $status;
    /** @var ?int */
    public $offset;
    /** @var ?int */
    public $end_offset;
    /** @var ?int */
    public $size;
    /** @var ?string */
    public $data;
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
    /** @var ?string */
    public $log_file;

    /** @return RunResponse */
    static function make_info(RunnerConfig $runner, PsetView $info) {
        $rr = new RunResponse;
        $rr->ok = true;
        $rr->pset = $runner->pset->urlkey;
        $rr->runner = $runner->name;
        $rr->repoid = $info->repo->repoid;
        $rr->repogid = $info->repo->repogid;
        $rr->url = $info->repo->url;
        $rr->hash = $info->hash();
        return $rr;
    }

    /** @param object $x
     * @return ?RunResponse */
    static function make_log($x) {
        if (!is_string($x->pset ?? null) || !is_string($x->runner ?? null)) {
            return null;
        }
        $rr = new RunResponse;
        $rr->pset = $x->pset;
        $rr->runner = $x->runner;
        $rr->repoid = is_int($x->repoid ?? null) ? $x->repoid : 0;
        if (is_string($x->repogid ?? null)) {
            $rr->repogid = $x->repogid;
        }
        if (is_string($x->url ?? null)) {
            $rr->url = $x->url;
        }
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
        if (is_string($x->eventsource ?? null)) {
            $rr->eventsource = $x->eventsource;
        }
        return $rr;
    }

    /** @param string $tag
     * @return bool */
    function has_tag($tag) {
        if ($this->tags) {
            foreach ($this->tags as $t) {
                if (strcasecmp($t, $tag) === 0)
                    return true;
            }
        }
        return false;
    }

    /** @return string */
    function landmark() {
        return $this->log_file ?? "@{$this->timestamp}";
    }

    function jsonSerialize(): array {
        $a = [];
        foreach (get_object_vars($this) as $k => $v) {
            if ($v !== null)
                $a[$k] = $v;
        }
        return $a;
    }
}
