<?php

class Messages {
    public $mtype = array();
    public $defs = array();

    static public $main;

    public function __construct() {
    }

    public function add($j, $type = null) {
        if (!$type)
            $type = $j->type;
        if (!($j->type ?? null)) {
            $j = clone $j;
            $j->type = $type;
        }
        if (!isset($this->mtype[$type]))
            $this->mtype[$type] = array();
        $this->mtype[$type][] = $j;
    }

    public function define($key, $value) {
        $this->defs[$key] = $value;
    }

    private function check($j, $defs) {
        $nrequire = 1;
        if ($j->require ?? null) {
            $reqs = is_array($j->require) ? $j->require : array($j->require);
            foreach ($reqs as $req)
                if (preg_match('/\A(!?)(\w+)\z/', $req, $m)) {
                    $exists = (array_key_exists($m[2], $defs) ? $defs[$m[2]] !== null :
                               isset($this->defs[$m[2]]));
                    if ($exists ? $m[1] === "!" : $m[1] === "")
                        return 0;
                    ++$nrequire;
                }
        }
        return $nrequire;
    }

    public function find($type, $kind, $defs = array()) {
        $reqj = null;
        $reqprio = -10000;
        $reqnrequire = 0;
        foreach ($this->mtype[$type] ?? [] as $j) {
            if (!isset($j->$kind)) {
                continue;
            }
            if (($nrequire = $this->check($j, $defs)) <= 0) {
                continue;
            }
            $prio = (float) ($reqj->priority ?? 0.0);
            if ($prio < $reqprio || ($prio == $reqprio && $nrequire < $reqnrequire))
                continue;
            $reqj = $j;
            $reqprio = $prio;
            $reqnrequire = $nrequire;
        }
        return $reqj;
    }

    /** @return ?string */
    public function expand($type, $kind, $defs = array()) {
        $reqj = $this->find($type, $kind, $defs);
        if ($reqj) {
            $t = "";
            $u = $reqj->$kind;
            while (preg_match('/\A(.*?)%(\w+)%(.*)\z/s', $u, $m)) {
                $t .= $m[1];
                if (array_key_exists($m[2], $defs)) {
                    $t .= (string) $defs[$m[2]];
                } else if ($this->defs[$m[2]] ?? null) {
                    $t .= $this->defs[$m[2]];
                }
                $u = $m[3];
            }
            return $t . $u;
        } else
            return null;
    }

    /** @param string $type
     * @return ?string */
    public function expand_text($type, $defs = array()) {
        return $this->expand($type, "text", $defs);
    }

    /** @param string $type
     * @return ?string */
    public function expand_html($type, $defs = array()) {
        return $this->expand($type, "html", $defs);
    }
}
