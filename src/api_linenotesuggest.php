<?php
// api/api_linenotesuggest.php -- Peteramati API for line note suggestions
// HotCRP and Peteramati are Copyright (c) 2006-2025 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class LineNoteSuggest_API {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var Pset */
    private $pset;
    /** @var string */
    private $file;
    /** @var int */
    private $linea;
    /** @var int */
    private $neighborhood;
    /** @var int */
    private $my_neighborhood;
    /** @var bool */
    private $more = false;

    function __construct(Contact $user, Pset $pset) {
        $this->conf = $pset->conf;
        $this->user = $user;
        $this->pset = $pset;
    }

    /** @param list<LineNote> &$lns
     * @param array $xn
     * @param CommitPsetInfo|UserPsetInfo $xpi */
    private function add_linenotes(&$lns, $xn, $xpi) {
        foreach ((array) $xn as $lineid => $jnote) {
            if (is_int($jnote)
                || !($ln = LineNote::make_json($this->file, $lineid, $jnote))) {
                continue;
            }
            if ($this->neighborhood >= 0) {
                $n = in_array($this->user->contactId, $ln->users) ? $this->my_neighborhood : $this->neighborhood;
                if ($n >= 0
                    && (!($lna = $ln->linea()) || abs($this->linea - $lna) > $n)) {
                    $this->more = true;
                    continue;
                }
            }
            if ($xpi instanceof CommitPsetInfo) {
                $ln->cpi = $xpi;
            } else {
                $ln->upi = $xpi;
            }
            $lns[] = $ln;
        }
    }

    /** @return list<LineNote> */
    private function all_linenotes_near() {
        $lns = [];
        if ($this->pset->gitless_grades && str_starts_with($this->file, "/")) {
            $result = $this->conf->qe("select ContactGrade.*, ContactInfo.email from ContactGrade left join ContactInfo on (ContactInfo.contactId=ContactGrade.cid) where pset=? order by updateat desc, cid asc", $this->pset->id);
            while (($upi = UserPsetInfo::fetch($result))) {
                if (($linenotes = $upi->jnote("linenotes"))
                    && ($xn = $linenotes->{$this->file} ?? null)) {
                    $this->add_linenotes($lns, (array) $xn, $upi);
                }
            }
            Dbl::free($result);
        } else {
            $result = $this->conf->qe("select CommitNotes.*, Repository.url repourl from CommitNotes left join Repository on (Repository.repoid=CommitNotes.repoid) where pset=? and haslinenotes order by updateat desc, repoid asc, bhash asc", $this->pset->id);
            while (($cpi = CommitPsetInfo::fetch($result))) {
                if (($linenotes = $cpi->jnote("linenotes"))
                    && ($xn = $linenotes->{$this->file} ?? null)) {
                    $this->add_linenotes($lns, (array) $xn, $cpi);
                }
            }
            Dbl::free($result);
        }
        return $lns;
    }

    /** @param list<object> &$fln
     * @param object $rm */
    static function linenotesuggest_add(&$fln, $rm) {
        for ($i = 0; $i !== count($fln) && $fln[$i]->ftext !== $rm->ftext; ++$i) {
        }
        if ($i === count($fln)) {
            $fm = clone $rm;
            $fln[] = $fm;
            $fm->n = $fm->n ?? 1;
            return;
        }
        $fm = $fln[$i];
        $fm->n += ($rm->n ?? 0);
        if (isset($rm->linea)) {
            if (!isset($fm->linea)) {
                $fm->linea = $rm->linea;
            } else if (is_int($fm->linea)) {
                if ($rm->linea !== $fm->linea) {
                    $fm->linea = [min($fm->linea, $rm->linea), max($fm->linea, $rm->linea)];
                }
            } else {
                $fm->linea[0] = min($fm->linea[0], $rm->linea);
                $fm->linea[1] = max($fm->linea[1], $rm->linea);
            }
        }
        if (isset($rm->like)) {
            $fm->like = $rm->like;
        }
        if (isset($rm->dislike)) {
            $fm->dislike = $rm->dislike;
        }
    }

    static private function get_linea(Contact $viewer, $file, $lineid, APIData $api) {
        if (!$api->repo || !$api->hash) {
            return ["ok" => false, "error" => "Missing commit."];
        }
        $info = PsetView::make($api->pset, $api->user, $viewer);
        if (($err = $api->prepare_commit($info))) {
            return $err;
        }
        $dctx = new DiffContext($info->repo, $api->pset, $info->derived_handout_commit(), $info->commit());
        $dctx->add_allowed_file($file);
        $dctx->add_required_file($file);
        $diff = $info->repo->diff($dctx);
        if (!isset($diff[$file])) {
            return ["ok" => false, "error" => "No such file."];
        } else if (($linea = $diff[$file]->linea_for($lineid)) !== null) {
            return ["ok" => true, "linea" => $linea];
        }
        return ["ok" => false, "error" => "No such line."];
    }

    static function linenotesuggest(Contact $user, Qrequest $qreq, APIData $api) {
        // check arguments
        if (!$user->isPC) {
            return ["ok" => false, "error" => "Permission error."];
        }
        if (!isset($qreq->file)
            || (isset($qreq->linea) && !ctype_digit($qreq->linea))
            || (isset($qreq->line) && !preg_match('/\A[ab]\d+\z/', $qreq->line))
            || (isset($qreq->neighborhood) && stoi($qreq->neighborhood) === null)
            || (isset($qreq->my_neighborhood) && stoi($qreq->my_neighborhood) === null)) {
            return ["ok" => false, "error" => "Invalid request."];
        }

        $lns = new LineNoteSuggest_API($user, $api->pset);
        $lns->file = $qreq->file;

        if (isset($qreq->linea)) {
            $lns->linea = stoi($qreq->linea) ?? 0;
        } else if (isset($qreq->line) && $qreq->line[0] === "a") {
            $lns->linea = stoi(substr($qreq->linea, 1)) ?? 0;
        } else if (isset($qreq->line) && !str_starts_with($qreq->file, "/")) {
            $x = self::get_linea($user, $qreq->file, $qreq->line, $api);
            if (!$x["ok"]) {
                return $x;
            }
            $lns->linea = $x["linea"];
        } else {
            $lns->linea = 0;
        }

        $lns->neighborhood = stoi($qreq->neighborhood) ?? 5;
        $lns->my_neighborhood = stoi($qreq->my_neighborhood) ?? 20;
        if ($lns->my_neighborhood >= 0 && $lns->my_neighborhood < $lns->neighborhood) {
            $lns->my_neighborhood = $lns->neighborhood;
        }
        if ($lns->neighborhood < 0 || $lns->linea < 0) {
            $lns->neighborhood = $lns->my_neighborhood = -1;
        }

        $fln = [];
        foreach ($lns->all_linenotes_near() as $ln) {
            self::linenotesuggest_add($fln, (object) $ln->render_map());
        }

        $data = $user->conf->fetch_value("select data from GroupSettings where name=?", "linenotemarks.p{$api->pset->id}");
        foreach (json_decode($data ?? '[]') as $rm) {
            if ($rm->file !== $qreq->file) {
                continue;
            }
            if ($lns->neighborhood >= 0) {
                $n = isset($rm->like) && in_array($user->contactId, $rm->like, true)
                    ? $lns->my_neighborhood
                    : $lns->neighborhood;
                if ($n >= 0 && isset($rm->linea) && abs($lns->linea - $rm->linea) > $n) {
                    $lns->more = true;
                    continue;
                }
            }
            self::linenotesuggest_add($fln, $rm);
        }

        $answer = ["ok" => true, "linea" => $lns->linea, "neighborhood" => $lns->neighborhood, "notelist" => $fln];
        if ($lns->more) {
            $answer["more"] = true;
        }
        return $answer;
    }

    static private function markedlinenote_add_cid($arr, $cid) {
        if (array_search($cid, $arr) === false) {
            $arr[] = $cid;
        }
        return $arr;
    }

    static function linenotemark(Contact $user, Qrequest $qreq, APIData $api) {
        // check arguments
        if (!$user->isPC) {
            return ["ok" => false, "error" => "Permission error."];
        }
        if (!isset($qreq->file)
            || (isset($qreq->linea) && !ctype_digit($qreq->linea))
            || !isset($qreq->ftext)
            || ctype_space($qreq->ftext)
            || !isset($qreq->mark)
            || ($qreq->mark !== "like" && $qreq->mark !== "dislike" && $qreq->mark !== "none")) {
            return ["ok" => false, "error" => "Invalid arguments."];
        }
        $ftext = rtrim(cleannl($qreq->ftext));
        $file = $qreq->file;
        $linea = intval($qreq->linea ?? "0");
        $mark = $qreq->mark;
        $gsname = "linenotemarks.p{$api->pset->id}";
        for ($ntries = 0; $ntries !== 200; ++$ntries) {
            $row = $user->conf->fetch_first_row("select value, data from GroupSettings where name=?", $gsname);
            $value = $row ? intval($row[0]) : 0;
            $data = $row ? json_decode($row[1]) ?? [] : [];
            if (!$value) {
                $user->conf->qe("insert ignore into GroupSettings set name=?, value=?, data=?", $gsname, 0, '[]');
            }

            for ($i = 0; $i !== count($data); ++$i) {
                if ($data[$i]->ftext === $ftext
                    && $data[$i]->file === $file
                    && ($data[$i]->linea ?? 0) === $linea) {
                    break;
                }
            }
            if ($i === count($data)) {
                $data[] = (object) ["ftext" => $ftext, "file" => $file];
                if ($linea) {
                    $data[$i]->linea = $linea;
                }
            }
            $di = $data[$i];
            foreach (["like", "dislike"] as $xmark) {
                if ($mark === $xmark) {
                    $di->$xmark = self::markedlinenote_add_cid($di->$xmark ?? [], $user->contactId);
                } else if (isset($di->$xmark)
                           && ($j = array_search($user->contactId, $di->$xmark)) !== false) {
                    array_splice($di->$xmark, $j, 1);
                }
            }
            if (empty($di->like ?? null) && empty($di->dislike ?? null)) {
                array_splice($data, $i, 1);
            }

            $new_data = json_encode_db($data);
            if ($data === $new_data) {
                return ["ok" => true];
            }

            $result = $user->conf->qe("update GroupSettings set value=?, data=? where name=? and value=?",
                $value + 1, $new_data, $gsname, $value);
            if ($result->affected_rows > 0) {
                return ["ok" => true];
            }
        }
        throw new Error("compare_exchange failure");
    }
}
