<?php
// sessionlist.php -- Peteramati helper class for lists carried across pageloads
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class SessionList {
    /** @var string */
    public $listid;
    /** @var ?string */
    public $cur;
    /** @var ?string */
    public $prev;
    /** @var ?string */
    public $next;

    /** @param string $listid */
    function __construct($listid) {
        $this->listid = $listid;
    }

    /** @return ?SessionList */
    static function find(Contact $user, Qrequest $qreq) {
        $found = [];
        foreach ($_COOKIE as $k => $v) {
            if (str_starts_with($k, "hotlist-info-"))
                $found[] = $k;
        }
        rsort($found, SORT_NATURAL);
        $unmatched = null;
        foreach ($found as $k) {
            if (($list = self::decode($_COOKIE[$k]))) {
                if ($list->matches_page($qreq)) {
                    return $list;
                } else if (!$list->cur) {
                    $unmatched = $list;
                }
            }
        }
        return $unmatched;
    }

    /** @return ?SessionList */
    static private function decode($s) {
        $sp = strpos($s, " ");
        $l = new SessionList($sp > 0 ? substr($s, 0, $sp) : $s);
        if ($sp > 0 && ($x = json_decode(substr($s, $sp + 1)))) {
            $l->cur = $x->cur ?? null;
            $l->prev = $x->prev ?? null;
            $l->next = $x->next ?? null;
        }
        return $l;
    }

    /** @return bool */
    function matches_page(Qrequest $qreq) {
        if ($this->cur !== null
            && $qreq->page() === "pset"
            && $qreq->u
            && $qreq->pset) {
            $base = "~" . urlencode($qreq->u) . "/pset/" . $qreq->pset;
            return $this->cur === $base
                || ($qreq->commit && $this->cur === "{$base}/{$qreq->commit}");
        } else {
            return false;
        }
    }
}
