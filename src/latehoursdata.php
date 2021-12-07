<?php
// latehoursdata.php -- CS61-monster helper class for late hours
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

class LateHoursData implements JsonSerializable {
    /** @var ?int */
    public $hours;
    /** @var ?int */
    public $autohours;
    /** @var ?int */
    public $timestamp;
    /** @var ?int */
    public $deadline;

    /** @return bool */
    function is_empty() {
        return !isset($this->hours) && !isset($this->autohours)
            && !isset($this->timestamp) && !isset($this->deadline);
    }

    function jsonSerialize(): array {
        $j = [];
        if (isset($this->hours)) {
            $j["hours"] = $this->hours;
        }
        if (isset($this->autohours) && $this->autohours !== $this->hours) {
            $j["autohours"] = $this->autohours;
        }
        if (isset($this->timestamp)) {
            $j["timestamp"] = $this->timestamp;
        }
        if (isset($this->deadline)) {
            $j["deadline"] = $this->deadline;
        }
        return $j;
    }
}
