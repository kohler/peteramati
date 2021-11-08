<?php
// queueitem.php -- Peteramati queue state
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class QueueItem {
    /** @var Conf */
    public $conf;

    /** @var int */
    public $queueid;
    /** @var int */
    public $reqcid;

    /** @var string */
    public $runnername;
    /** @var int */
    public $cid;
    /** @var int */
    public $psetid;
    /** @var int */
    public $repoid;
    /** @var ?string */
    public $bhash;

    /** @var string */
    public $queueclass;
    /** @var ?int */
    public $nconcurrent;

    /** @var int */
    public $insertat;
    /** @var int */
    public $updateat;
    /** @var int */
    public $runat;
    /** @var int */
    public $autorun;
    /** @var int */
    public $status;
    /** @var ?string */
    public $lockfile;
    /** @var ?string */
    public $inputfifo;

    // loaded from joins
    /** @var ?int */
    public $nahead;
    /** @var ?int */
    public $head_runat;
    /** @var ?int */
    public $ahead_nconcurrent;

    /** @var ?bool */
    public $runnable;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    private function db_load() {
        $this->queueid = (int) $this->queueid;
        $this->reqcid = (int) $this->reqcid;

        $this->cid = (int) $this->cid;
        $this->psetid = (int) $this->psetid;
        $this->repoid = (int) $this->repoid;

        if ($this->nconcurrent !== null) {
            $this->nconcurrent = (int) $this->nconcurrent;
        }

        $this->insertat = (int) $this->insertat;
        $this->updateat = (int) $this->updateat;
        $this->runat = (int) $this->runat;
        $this->status = (int) $this->status;
        $this->autorun = (int) $this->autorun;

        if ($this->nahead !== null) {
            $this->nahead = (int) $this->nahead;
        }
        if ($this->head_runat !== null) {
            $this->head_runat = (int) $this->head_runat;
        }
        if ($this->ahead_nconcurrent !== null) {
            $this->ahead_nconcurrent = (int) $this->ahead_nconcurrent;
        }
    }

    /** @return ?QueueItem */
    static function fetch(Conf $conf, $result) {
        $qi = $result ? $result->fetch_object("QueueItem", [$conf]) : null;
        '@phan-var-force ?Repository $repo';
        if ($qi && !is_int($qi->queueid)) {
            $qi->db_load();
        }
        return $qi;
    }
}
