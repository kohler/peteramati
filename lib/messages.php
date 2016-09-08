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
        if (!get($j, "type")) {
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
        if (get($j, "require")) {
            $reqs = is_array($j->require) ? $j->require : array($j->require);
            foreach ($reqs as $req)
                if (preg_match('/\A(!?)(\w+)\z/', $req, $m)) {
                    $exists = (array_key_exists($m[2], $defs) ? $defs[$m[2]] !== null :
                               get($this->defs, $m[2]) !== null);
                    if ($exists ? $m[1] === "!" : $m[1] === "")
                        return false;
                }
        }
        return true;
    }

    public function find($type, $kind, $defs = array()) {
        $reqj = null;
        foreach (get($this->mtype, $type) ? : array() as $j)
            if ((!$reqj || (float) get($reqj, "priority") <= (float) get($j, "priority"))
                && $this->check($j, $defs)
                && get($j, $kind) !== null)
                $reqj = $j;
        return $reqj;
    }

    public function expand($type, $kind, $defs = array()) {
        $reqj = $this->find($type, $kind, $defs);
        if ($reqj) {
            $t = "";
            $u = $reqj->$kind;
            while (preg_match('/\A(.*?)%(\w+)%(.*)\z/s', $u, $m)) {
                $t .= $m[1];
                if (array_key_exists($m[2], $defs))
                    $t .= (string) $defs[$m[2]];
                else if (get($this->defs, $m[2]))
                    $t .= $this->defs[$m[2]];
                $u = $m[3];
            }
            return $t . $u;
        } else
            return false;
    }

    public function expand_text($type, $defs = array()) {
        return $this->expand($type, "text", $defs);
    }

    public function expand_html($type, $defs = array()) {
        return $this->expand($type, "html", $defs);
    }
}
