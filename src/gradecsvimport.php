<?php
// gradecsvimport.php -- Peteramati helper for importing grades from CSV
// Peteramati is Copyright (c) 2013-2026 Eddie Kohler
// See LICENSE for open-source distribution terms

class GradeCsvImport {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Pset
     * @readonly */
    public $pset;
    /** @var bool
     * @readonly */
    public $autogrades;

    /** Columns that identify a user, in order of preference. */
    const USER_COLUMNS = ["email", "github_username", "huid", "username", "name"];

    /** @param bool $autogrades */
    function __construct(Pset $pset, $autogrades) {
        $this->conf = $pset->conf;
        $this->pset = $pset;
        $this->autogrades = $autogrades;
    }

    /** @param list<string> $header
     * @return list<string> */
    function unknown_columns($header) {
        $unknown = [];
        foreach ($header as $h) {
            if ($h !== ""
                && $h !== "timestamp"
                && !in_array($h, self::USER_COLUMNS, true)
                && !$this->pset->gradelike_by_key($h)) {
                $unknown[] = $h;
            }
        }
        return $unknown;
    }

    /** Return the user identified by `$row`, and the identifying text.
     * The text is `""` if the row identifies no one.
     * @param CsvRow|array<string,string> $row
     * @return array{?Contact,string} */
    function find_user($row) {
        if (($who = $row["email"] ?? "") !== "" && $who !== "-") {
            return [$this->conf->user_by_email($who), $who];
        } else if (($who = $row["github_username"] ?? "") !== "" && $who !== "-") {
            return [$this->conf->user_by_whatever($who, Conf::USERNAME_GITHUB), $who];
        } else if (($who = $row["huid"] ?? "") !== "" && $who !== "-") {
            return [$this->conf->user_by_whatever($who, Conf::USERNAME_HUID), $who];
        } else if (($who = $row["username"] ?? "") !== "" && $who !== "-") {
            // `[anonNNN]` usernames match anonymous usernames
            return [$this->conf->user_by_whatever($who, Conf::USERNAME_USERNAME), $who];
        } else if (($who = $row["name"] ?? "") !== "") {
            list($first, $last) = Text::split_name($who);
            $user = $this->conf->user_by_query("firstName like '?s%' and lastName=?", [$first, $last]);
            if ($user
                && $user->firstName != $first
                && !str_starts_with($user->firstName, "{$first} ")) {
                $user = null;
            }
            return [$user, $who];
        }
        return [null, ""];
    }

    /** Return the grade-notes update for `$row`, and any errors.
     * Grade columns are named by grade key. An empty cell clears that grade.
     * @param CsvRow|array<string,string> $row
     * @return array{array<string,mixed>,list<string>} */
    function row_updates($row) {
        $grades = (object) [];
        $errors = [];
        foreach ($this->pset->grades() as $ge) {
            if (!isset($row[$ge->key])) {
                continue;
            }
            $v = $ge->parse_value($row[$ge->key], !$this->autogrades);
            if ($v instanceof GradeError) {
                $errors[] = "{$ge->key}: {$v->message}";
            } else {
                $grades->{$ge->key} = $v === false ? null : $v;
            }
        }
        $updates = [];
        if (!empty(get_object_vars($grades))) {
            $updates[$this->autogrades ? "autogrades" : "grades"] = $grades;
        }
        if (isset($row["timestamp"]) && is_numeric($row["timestamp"])) {
            $timestamp = intval($row["timestamp"]);
            if ($timestamp >= 1400000000) {
                $updates["timestamp"] = $timestamp;
            } else if ($timestamp <= 0) {
                $updates["timestamp"] = null;
            }
        }
        return [$updates, $errors];
    }

    /** Apply `$row`’s grades to `$info`’s current commit. Return any errors;
     * valid grades are applied even if other cells have errors.
     * @param CsvRow|array<string,string> $row
     * @return list<string> */
    function apply(PsetView $info, $row) {
        if ($info->is_handout_commit()
            && !$info->pset->gitless_grades) {
            return ["this is a handout commit"];
        }
        list($updates, $errors) = $this->row_updates($row);
        if (!empty($updates)) {
            $info->update_grade_notes($updates);
        }
        return $errors;
    }
}
