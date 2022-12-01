<?php
// queuestate.php -- Peteramati execution queue state
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class QueueState {
    /** @var int */
    public $nrunning = 0;
    /** @var int */
    public $nconcurrent = 100000;
    /** @var list<QueueItem> */
    public $items = [];
    /** @var int */
    public $head = 0;

    /** @param Conf $conf
     * @param Dbl_Result|\mysqli_result $result
     * @return QueueState */
    static function fetch_list($conf, $result) {
        $qs = new QueueState;
        while (($qi = QueueItem::fetch($conf, $result))) {
            $qs->items[] = $qi;
        }
        Dbl::free($result);
        return $qs;
    }

    /** @return ?QueueItem */
    function shift() {
        if ($this->head < count($this->items)) {
            $qi = $this->items[$this->head];
            $this->items[$this->head] = null;
            ++$this->head;
            if ($this->head > 200) {
                array_splice($this->items, 0, $this->head - 1, []);
                $this->head = 1;
            }
            $qi->qstate = $this;
            return $qi;
        } else {
            return null;
        }
    }

    /** @param Conf $conf
     * @param int $chain */
    function bump_chain($conf, $chain) {
        if (($nqi = QueueItem::by_chain($conf, $chain))) {
            $i = $this->head;
            while ($i < count($this->items)) {
                $xqi = $this->items[$i];
                if ($xqi->runorder > $nqi->runorder
                    || ($xqi->runorder === $nqi->runorder && $xqi->queueid > $nqi->queueid)) {
                    break;
                }
                ++$i;
            }
            if ($this->head > 0 && $i < count($this->items) - $i) {
                for ($j = $this->head; $j !== $i; ++$j) {
                    $this->items[$j - 1] = $this->items[$j];
                }
                $this->items[$i - 1] = $nqi;
                --$this->head;
            } else {
                array_splice($this->items, $i, 0, [$nqi]);
            }
        }
    }
}
