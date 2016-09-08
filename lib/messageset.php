<?php

class MessageSet {
    public $user;
    public $errf;
    public $msgs;
    public $has_warnings;
    public $has_errors;
    public $allow_error = [];

    const INFO = 0;
    const WARNING = 1;
    const ERROR = 2;

    function __construct(Contact $user) {
        $this->user = $user;
        $this->clear();
    }
    function clear() {
        $this->errf = $this->msgs = [];
        $this->has_warnings = $this->has_errors = false;
    }

    function msg($field, $html, $status) {
        if ($field)
            $this->errf[$field] = max(get($this->errf, $field, 0), $status);
        if ($html)
            $this->msgs[] = [$field, $html, $status];
        if ($status == self::WARNING)
            $this->has_warnings = true;
        if ($status == self::ERROR && (!$field || !get($this->allow_error, $field)))
            $this->has_errors = true;
    }
    function set_error_html($field, $html) {
        $this->msg($field, $html, self::ERROR);
    }
    function set_warning_html($field, $html) {
        $this->msg($field, $html, self::WARNING);
    }
    function set_info_html($field, $html) {
        $this->msg($field, $html, self::INFO);
    }

    function messages($include_fields = false) {
        return $include_fields ? $this->msgs : array_map(function ($m) { return $m[1]; }, $this->msgs);
    }
    function messages_for($field, $include_fields = false) {
        $m = empty($this->msgs) ? [] : array_filter($this->msgs, function ($m) use ($field) { return $m[0] === $field; });
        return $include_fields || empty($m) ? $m : array_map(function ($m) { return $m[1]; }, $m);
    }

    function has_error() {
        return $this->has_errors;
    }
    function has_problem($field) {
        return get($this->errf, $field, 0) > 0;
    }
    function has_problems() {
        return $this->has_warnings || $this->has_errors;
    }
}
