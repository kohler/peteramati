<?php
// commitlist.php -- Peteramati helper class representing commit lists
// Peteramati is Copyright (c) 2013-2025 Eddie Kohler
// See LICENSE for open-source distribution terms

class CommitList implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    /** @var ?Repository
     * @readonly */
    public $repo;
    /** @var array<string,CommitRecord> */
    public $commits = [];
    /** @var bool */
    public $merges_checked = false;
    /** @var bool */
    public $suspicious_directory = false;
    /** @var int */
    public $_listflags = 0;

    function __construct(?Repository $repo = null) {
        $this->repo = $repo;
    }

    function add(CommitRecord $c) {
        $this->commits[$c->hash] = $c;
    }

    function merge(CommitList $list) {
        $this->commits = $this->commits + $list->commits;
    }

    /** @return bool */
    function nonempty() {
        return !empty($this->commits);
    }

    function count() : int {
        return count($this->commits);
    }

    /** @param string $hash
     * @return bool */
    function contains($hash) {
        return isset($this->commits[$hash]);
    }

    /** @param int $i
     * @return ?CommitRecord */
    function at_index($i) {
        return (array_values($this->commits))[$i] ?? null;
    }

    /** @return ?CommitRecord */
    function latest() {
        return (array_values($this->commits))[0] ?? null;
    }


    function offsetExists($offset) : bool {
        return isset($this->commits[$offset]);
    }

    function offsetGet($offset) : ?CommitRecord {
        return $this->commits[$offset] ?? null;
    }

    function offsetSet($offset, $value) : void {
        throw new Error("bad CommitList::offsetSet");
    }

    function offsetUnset($offset) : void {
        throw new Error("bad CommitList::offsetUnset");
    }


    #[\ReturnTypeWillChange]
    /** @return Iterator<string,mixed> */
    function getIterator() {
        return new ArrayIterator($this->commits);
    }

    function jsonSerialize() : mixed {
        return $this->commits;
    }
}
