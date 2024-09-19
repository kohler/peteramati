<?php
// commitlist.php -- Peteramati helper class representing commit lists
// Peteramati is Copyright (c) 2013-2024 Eddie Kohler
// See LICENSE for open-source distribution terms

class CommitList implements JsonSerializable, IteratorAggregate, Countable {
    /** @var array<string,CommitRecord> */
    public $commits = [];
    /** @var bool */
    public $merges_checked = false;
    /** @var bool */
    public $suspicious_directory = false;

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

    /** @param int $i
     * @return ?CommitRecord */
    function at_index($i) {
        return (array_values($this->commits))[$i] ?? null;
    }

    /** @return ?CommitRecord */
    function latest() {
        return (array_values($this->commits))[0] ?? null;
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
