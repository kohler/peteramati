<?php
// runresponse.php -- Peteramati class representing JSON responses
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class RunResponse implements JsonSerializable {
    /** @var ?bool */
    public $ok;
    /** @var ?bool */
    public $error;
    /** @var int */
    public $repoid;
    /** @var string */
    public $pset;
    /** @var ?string */
    public $hash;
    /** @var string */
    public $runner;
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

    function jsonSerialize() {
        $a = [];
        foreach (get_object_vars($this) as $k => $v) {
            if ($v !== null)
                $a[$k] = $v;
        }
        return $a;
    }
}
