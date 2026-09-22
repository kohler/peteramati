<?php
// leaderboard.php -- Peteramati leaderboards over runner-reported metrics
// Peteramati is Copyright (c) 2006-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class LeaderboardEntry {
    /** @var int */
    public $cid;
    /** @var float */
    public $value;
    /** @var int */
    public $at;
    /** @var ?string */
    public $hash;

    /** @param int $cid
     * @param float $value */
    function __construct($cid, $value) {
        $this->cid = $cid;
        $this->value = $value;
    }
}

class Leaderboard {
    /** @var Pset
     * @readonly */
    public $pset;
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** Unordered entries for each visible metric.
     * @var array<int|string,list<LeaderboardEntry>> */
    private $_entries = [];
    /** Users with entries, by contact ID: objects with `name` (pseudonym),
     * `user`, `anon_user`, and `display_name`.
     * @var array<int,object> */
    private $_users = [];
    /** @var ?string */
    private $_changeat;
    /** @var bool */
    private $_loaded = false;

    function __construct(Pset $pset, Contact $viewer) {
        $this->pset = $pset;
        $this->conf = $pset->conf;
        $this->viewer = $viewer;
    }


    // Recording

    /** Compute and store `$lbc`'s metrics from job `$jobid` of its runner.
     * A job ID is the time the run started, so it also orders the records.
     * @param int $jobid
     * @param ?list<string> $keys if set, store only these metrics
     * @param bool $force if true, replace stored metrics even if newer
     * @return bool true if any metric changed */
    static function record(PsetView $info, LeaderboardConfig $lbc, $jobid, $keys = null, $force = false) {
        if ($lbc->disabled) {
            return false;
        }
        $pfx = "{$info->pset->key}.{$lbc->name}";
        $mv = $info->leaderboard_values($lbc, $jobid);
        if ($mv === null || $mv === false) {
            return false;
        } else if (!is_array($mv) && !is_object($mv)) {
            error_log("{$pfx}: leaderboard function returned non-map");
            return false;
        }
        $when = $jobid > 0 ? $jobid : Conf::$now;
        $hash = ($bhash = $info->bhash()) === null ? null : bin2hex($bhash);
        $changes = [];
        foreach ((array) $mv as $key => $value) {
            $m = $lbc->metrics[$key] ?? null;
            if (!$m) {
                error_log("{$pfx}: unknown leaderboard metric `{$key}`");
            } else if ($keys !== null && !in_array($m->key, $keys, true)) {
                continue;
            } else if ($value === null) {
                $changes[$m->key] = null;
            } else if (is_int($value) || (is_float($value) && is_finite($value))) {
                $changes[$m->key] = (object) [
                    "value" => (float) $value,
                    "at" => $when,
                    "runner" => $lbc->runner->name,
                    "hash" => $hash
                ];
            } else {
                error_log("{$pfx}: leaderboard metric `{$key}` is not a finite number");
            }
        }
        if (empty($changes)
            || !$info->update_leaderboard_xnotes($changes, $force ? PHP_INT_MAX : $when)) {
            return false;
        }
        if ((string) $info->user->leaderboard_name === "") {
            $info->user->leaderboard_name = LeaderboardName::assign_one($info->conf, $info->user->contactId);
        }
        self::note_change($info->pset);
        return true;
    }

    /** Clear recorded metrics for `$pset`, or just those in `$keys`.
     * @param ?list<string> $keys
     * @return int number of students changed */
    static function clear(Pset $pset, $keys = null) {
        $keys = $keys ?? array_keys($pset->leaderboard);
        if (empty($keys)) {
            return 0;
        }
        $changes = [];
        foreach ($keys as $key) {
            $changes[$key] = null;
        }
        $sset = new StudentSet($pset->conf->root_user(), StudentSet::ALL);
        $sset->set_pset($pset);
        $n = 0;
        foreach ($sset as $info) {
            if ($info->leaderboard_jxnote()
                && $info->update_leaderboard_xnotes($changes, PHP_INT_MAX)) {
                ++$n;
            }
        }
        if ($n > 0) {
            self::note_change($pset);
        }
        return $n;
    }


    // Change tracking
    //
    // Setting `__leaderboardts.pN` records when `$pset`'s metrics, or the set
    // of students listed, last changed, with microsecond precision in its
    // data (`value` holds whole seconds for readability). Every change
    // advances it. The API's ETag includes it, so browsers can reuse
    // responses until something changes, and `shared_data()` memoizes the
    // leaderboard's data under it, so it's computed once per change rather
    // than once per viewer.

    /** Version of `json()`’s format, part of the API’s ETag. Increment it
     * when the format changes, or browsers may keep using stale responses. */
    const JSON_VERSION = 6;
    /** Version of the shared data format (see `shared_data()`). */
    const DATA_VERSION = 1;

    /** The time `$pset`'s leaderboard metrics last changed, as a decimal
     * string with microsecond precision.
     * @return string */
    static function change_time(Pset $pset) {
        $row = $pset->conf->fetch_first_row("select value, data from Settings where name=?",
            "__leaderboardts.p{$pset->id}");
        return $row ? $row[1] ?? (string) $row[0] : "0";
    }

    /** Record that `$pset`'s leaderboard metrics, or the set of students
     * listed, changed now. */
    static function note_change(Pset $pset) {
        $t = microtime(true);
        // the new time is at least 1µs after the old, so it changes even if
        // the clock steps backward
        $pset->conf->qe("insert into Settings set name=?, value=?, data=?
                on duplicate key update
                data=cast(greatest(coalesce(cast(data as decimal(20,6)) + 0.000001, 0), cast(values(data) as decimal(20,6))) as char),
                value=floor(cast(data as decimal(20,6)))",
            "__leaderboardts.p{$pset->id}", (int) $t, sprintf("%.6f", $t));
    }

    /** Record a change to every leaderboard, e.g. when enrollment changes. */
    static function note_change_all(Conf $conf) {
        foreach ($conf->psets() as $pset) {
            if ($pset->has_leaderboard()) {
                self::note_change($pset);
            }
        }
    }


    // Querying

    /** The metrics this viewer may see.
     * @return array<int|string,LeaderboardMetric> */
    function metrics() {
        $ms = [];
        foreach ($this->pset->leaderboard as $key => $m) {
            if ($this->viewer->can_view_leaderboard_metric($this->pset, $m)) {
                $ms[$key] = $m;
            }
        }
        return $ms;
    }

    /** The time `$pset`'s metrics last changed, read once per object so the
     * ETag and the data agree.
     * @return string */
    private function changeat() {
        $this->_changeat = $this->_changeat ?? self::change_time($this->pset);
        return $this->_changeat;
    }

    /** The data every viewer's leaderboard is built from: each listed
     * student's identity and complete leaderboard notes, keyed by contact
     * ID. It's shared in GroupSettings `__leaderboardc.pN`, tagged with the
     * change time it reflects. The change time always advances, and is read
     * before computing, so data with a matching tag is exactly current.
     * @param string $changeat
     * @return object */
    static private function shared_data(Pset $pset, $changeat) {
        $conf = $pset->conf;
        $name = "__leaderboardc.p{$pset->id}";
        $row = $conf->fetch_first_row("select coalesce(dataOverflow, data) from GroupSettings where name=?", $name);
        $data = $row ? json_decode($row[0] ?? "null") : null;
        if (is_object($data)
            && ($data->v ?? null) === self::DATA_VERSION
            && ($data->changeat ?? null) === $changeat) {
            return $data;
        }
        $data = (object) [
            "v" => self::DATA_VERSION,
            "changeat" => $changeat,
            "users" => (object) self::compute($pset)
        ];
        $conf->save_gsetting($name, (int) $changeat, $data);
        return $data;
    }

    /** Compute shared leaderboard data: every enrolled student (not course
     * staff or dropped) with recorded metrics.
     * @return array<int,object> */
    static private function compute(Pset $pset) {
        $conf = $pset->conf;

        // recorded metrics
        $lbs = [];
        $result = $conf->qe("select cid, coalesce(xnotesOverflow, xnotes) from ContactGrade
                where pset=? and coalesce(xnotesOverflow, xnotes) like '%\"leaderboard\"%'",
            $pset->id);
        while (($row = $result->fetch_row())) {
            $jx = json_decode($row[1]);
            if (is_object($jx)
                && is_object($jx->leaderboard ?? null)
                && !empty(get_object_vars($jx->leaderboard))) {
                $lbs[(int) $row[0]] = $jx->leaderboard;
            }
        }
        Dbl::free($result);
        if (empty($lbs)) {
            return [];
        }

        // enrolled students among them (like `StudentSet::ALL_ENROLLED`)
        $users = [];
        $result = $conf->qe("select * from ContactInfo
                where contactId?a and roles=0 and not dropped",
            array_keys($lbs));
        while (($u = Contact::fetch($result, $conf))) {
            $users[$u->contactId] = $u;
        }
        Dbl::free($result);

        // pseudonyms are normally assigned when metrics are recorded; leave
        // out anyone who still lacks one, rather than reveal who they are
        $unnamed = [];
        foreach ($users as $cid => $u) {
            if ((string) $u->leaderboard_name === "") {
                $unnamed[] = $cid;
            }
        }
        if (!empty($unnamed)) {
            $names = LeaderboardName::assign($conf, $unnamed);
            foreach ($unnamed as $cid) {
                $users[$cid]->leaderboard_name = $names[$cid] ?? null;
            }
        }

        $data = [];
        foreach ($users as $cid => $u) {
            if ((string) $u->leaderboard_name !== "") {
                $data[$cid] = (object) [
                    "name" => $u->leaderboard_name,
                    "user" => $u->username ? : $u->email,
                    "anon_user" => $u->anon_username,
                    "display_name" => Text::name_text($u),
                    "lb" => $lbs[$cid]
                ];
            }
        }
        return $data;
    }

    /** Load entries for the visible metrics. */
    private function load() {
        if ($this->_loaded) {
            return;
        }
        $this->_loaded = true;
        $ms = $this->metrics();
        foreach ($ms as $key => $m) {
            $this->_entries[$key] = [];
        }
        if (empty($ms)) {
            return;
        }
        $data = self::shared_data($this->pset, $this->changeat());
        foreach ($data->users as $cidstr => $ur) {
            $cid = (int) $cidstr;
            foreach ($ms as $key => $m) {
                $rec = $ur->lb->{$key} ?? null;
                if (is_object($rec) && is_number($rec->value ?? null)) {
                    $e = new LeaderboardEntry($cid, (float) $rec->value);
                    $e->at = (int) ($rec->at ?? 0);
                    $e->hash = $rec->hash ?? null;
                    $this->_entries[$key][] = $e;
                    $this->_users[$cid] = $ur;
                }
            }
        }
    }

    /** Entries for `$metric_key`, unordered.
     * @param int|string $metric_key
     * @return list<LeaderboardEntry> */
    function entries($metric_key) {
        $this->load();
        return $this->_entries[$metric_key] ?? [];
    }

    /** Users with entries, by contact ID: objects with `name` (pseudonym),
     * `user`, `anon_user`, and `display_name`.
     * @return array<int,object> */
    function users() {
        $this->load();
        return $this->_users;
    }

    /** An ETag for this viewer's JSON, computed without loading entries.
     * @return string */
    function etag() {
        $mj = [];
        foreach ($this->metrics() as $m) {
            $mj[] = $m->json();
        }
        $x = [self::JSON_VERSION, $this->viewer->contactId, $this->viewer->isPC,
              $this->pset->anonymous, $this->changeat(), $mj];
        return "\"lb" . md5(json_encode($x)) . "\"";
    }

    /** JSON for the leaderboard API: each visible metric's entries,
     * unordered and unranked (the browser ranks them). Student identity is
     * always the leaderboard pseudonym, never the contact ID; course staff
     * additionally get the real user, so they can click through to a
     * student's pset page.
     * @return array<string,mixed> */
    function json() {
        $this->load();
        $pcview = $this->viewer->isPC;
        $anon = $this->pset->anonymous;

        // sort by pseudonym, so the order reveals nothing about identity
        // (database order roughly follows account creation)
        $users = $this->_users;
        uasort($users, function ($a, $b) {
            return strcmp($a->name, $b->name);
        });
        $uj = [];
        foreach ($users as $cid => $u) {
            $name = $u->name;
            $j = ["name" => $name];
            if ($pcview) {
                $j["user"] = $anon ? $u->anon_user : $u->user;
                $j["display_name"] = $anon ? null : $u->display_name;
            }
            if ($cid === $this->viewer->contactId) {
                $j["is_viewer"] = true;
            }
            $uj[$name] = $j;
        }

        $mj = [];
        foreach ($this->metrics() as $key => $m) {
            $ej = [];
            foreach ($this->_entries[$key] as $e) {
                $x = ["name" => $this->_users[$e->cid]->name,
                      "value" => $e->value, "text" => $m->unparse_value($e->value)];
                if ($pcview && $e->hash !== null) {
                    $x["commit"] = $e->hash;
                }
                if ($pcview && $e->at) {
                    $x["at"] = $e->at;
                }
                $ej[] = $x;
            }
            usort($ej, function ($a, $b) {
                return strcmp($a["name"], $b["name"]);
            });
            $mj[] = $m->json() + ["entries" => $ej];
        }

        return [
            "ok" => true,
            "pset" => $this->pset->urlkey,
            "users" => (object) $uj,
            "metrics" => $mj
        ];
    }
}
