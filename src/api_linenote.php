<?php
// api/api_linenote.php -- Peteramati API for line notes
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class LineNote_API {
    /** @param PsetView $info
     * @param Contact $user
     * @return array{ok:false,error:string}|array{ok:true,note:LineNote} */
    static private function apply_linenote($info, $user, $apply) {
        // check filename and line number
        if (!isset($apply->file)
            || !isset($apply->line)
            || !$apply->file
            || strlen($apply->line) < 2
            || ($apply->line[0] !== "a" && $apply->line[0] !== "b")
            || !ctype_digit(substr($apply->line, 1))
            || (isset($apply->linea) && !ctype_digit($apply->linea))) {
            return ["ok" => false, "error" => "Invalid request."];
        }

        // check permissions and filename
        if (!$info->pc_view) {
            return ["ok" => false, "error" => "Permission error."];
        } else if (str_starts_with($apply->file, "/g/")) {
            if (!($ge = $info->pset->grades[substr($apply->file, 3)])
                || !$ge->answer) {
                return ["ok" => false, "error" => "No such grade."];
            }
        } else if (str_starts_with($apply->file, "/")) {
            return ["ok" => false, "error" => "Invalid request."];
        } else if (!$info->repo) {
            return ["ok" => false, "error" => "Missing repository."];
        } else if ($info->hash() === null) {
            return ["ok" => false, "error" => "Missing commit."];
        } else if ($info->is_handout_commit()) {
            return ["ok" => false, "error" => "Refusing to leave note on handout commit."];
        }

        // find or create note
        $note = $info->line_note($apply->file, $apply->line);
        if (isset($apply->oldversion)
            && $apply->oldversion != +$note->version) {
            return ["ok" => false, "error" => "Edit conflict, you need to reload."];
        }

        // modify note
        if (array_search($user->contactId, $note->users) === false) {
            $note->users[] = $user->contactId;
        }
        $note->iscomment = isset($apply->iscomment) && $apply->iscomment;

        // XXX apply->note, apply->text obsolete
        $t = rtrim(cleannl($apply->ftext ?? $apply->note ?? $apply->text ?? ""));
        // format-only, such as `<1>`, becomes empty string
        $len = strlen($t);
        if ($len >= 3 && $len <= 10 && $t[0] === "<" && $t[$len - 1] === ">"
            && ctype_digit(substr($t, 1, $len - 2))) {
            $t = "";
        }
        $note->ftext = $t;

        $note->version = intval($note->version) + 1;
        if (isset($apply->linea)) {
            $note->linea = intval($apply->linea);
        }
        return ["ok" => true, "note" => $note];
    }

    static function linenote(Contact $user, Qrequest $qreq, APIData $api) {
        if ($qreq->line && ctype_digit($qreq->line)) {
            $qreq->line = "b" . $qreq->line;
        }

        // set up info, repo, commit
        $info = PsetView::make($api->pset, $api->user, $user);
        assert($api->repo === null || $api->repo === $info->repo);
        $api->repo = $info->repo;
        assert($info->repo !== null || $api->commit === null);
        if ($info->repo
            && $api->hash
            && !$api->commit
            && !($api->commit = $info->conf->check_api_hash($api->hash, $api))) {
            return ["ok" => false, "error" => "Disconnected commit."];
        }
        if ($api->commit) {
            $info->set_commit($api->commit);
        } else if (!$api->pset->has_answers) {
            return ["ok" => false, "error" => "Missing commit."];
        }

        // apply line notes
        if ($qreq->method() === "POST") {
            $ans = self::apply_linenote($info, $user, $qreq);
            if (!$ans["ok"]) {
                return $ans;
            }
            $ln = $ans["note"];
            if ($info->pset->gitless_grades
                && str_starts_with($ln->file, "/")) {
                $info->update_user_notes(["linenotes" => [$ln->file => [$ln->lineid => $ln]]]);
            } else {
                $info->update_commit_notes(["linenotes" => [$ln->file => [$ln->lineid => $ln]]]);
            }
        }

        if (!$user->can_view_comments($api->pset, $info)) {
            return ["ok" => false, "error" => "Permission error."];
        }

        $notes = [];
        $lnorder = $info->visible_line_notes();
        foreach ($lnorder->fileorder() as $file => $order) {
            if (!$qreq->file || $file === $qreq->file) {
                foreach ($lnorder->file($file) as $lineid => $note) {
                    if ((!$qreq->line || $lineid === $qreq->line)
                        && ($j = $note->render()))
                        $notes[$file][$lineid] = $j;
                }
            }
        }
        return ["ok" => true, "linenotes" => $notes];
    }


    /** @param list<LineNote> &$lns
     * @param array $xn
     * @param string $file
     * @param int $linea
     * @param ?int $neighborhood
     * @param CommitPsetInfo|UserPsetInfo $xpi */
    static private function add_linenotes(&$lns, $xn, $file, $linea, $neighborhood, $xpi) {
        foreach ((array) $xn as $lineid => $jnote) {
            if (!is_int($jnote)
                && ($ln = LineNote::make_json($file, $lineid, $jnote))
                && (!$linea
                    || $neighborhood === null
                    || (($lna = $ln->linea()) && abs($linea - $lna) <= $neighborhood))) {
                if ($xpi instanceof CommitPsetInfo) {
                    $ln->cpi = $xpi;
                } else {
                    $ln->upi = $xpi;
                }
                $lns[] = $ln;
            }
        }
    }

    /** @param string $file
     * @param int $linea
     * @param ?int $neighborhood
     * @return list<LineNote> */
    static function all_linenotes_near(Pset $pset, $file, $linea, $neighborhood = null) {
        $fln = [];
        if ($pset->gitless_grades && str_starts_with($file, "/")) {
            $result = $pset->conf->qe("select ContactGrade.*, ContactInfo.email from ContactGrade left join ContactInfo on (ContactInfo.contactId=ContactGrade.cid) where pset=? order by updateat desc, cid asc", $pset->id);
            while (($upi = UserPsetInfo::fetch($result))) {
                if (($linenotes = $upi->jnote("linenotes"))
                    && ($xn = $linenotes->{$file} ?? null)) {
                    self::add_linenotes($fln, (array) $xn, $file, $linea, $neighborhood, $upi);
                }
            }
            Dbl::free($result);
        } else {
            $result = $pset->conf->qe("select CommitNotes.*, Repository.url repourl from CommitNotes left join Repository on (Repository.repoid=CommitNotes.repoid) where pset=? and haslinenotes order by updateat desc, repoid asc, bhash asc", $pset->id);
            while (($cpi = CommitPsetInfo::fetch($result))) {
                if (($linenotes = $cpi->jnote("linenotes"))
                    && ($xn = $linenotes->{$file} ?? null)) {
                    self::add_linenotes($fln, (array) $xn, $file, $linea, $neighborhood, $cpi);
                }
            }
            Dbl::free($result);
        }

        usort($fln, function ($a, $b) {
            $aa = $a->linea();
            $ba = $b->linea();
            if ($aa && $ba && $aa !== $ba) {
                return $aa <=> $ba;
            } else {
                return strnatcmp($a->lineid, $b->lineid);
            }
        });
        return $fln;
    }

    /** @param list<object> &$fln
     * @param object $rm
     * @param int $linea */
    static function linenotesuggest_add(&$fln, $rm, $linea) {
        for ($i = 0; $i !== count($fln) && $fln[$i]->ftext !== $rm->ftext; ++$i) {
        }
        if ($i === count($fln)) {
            $fln[] = clone $rm;
        } else {
            $fln[$i]->n += ($rm->n ?? 0);
            if ($linea && abs($linea - $fln[$i]->linea) > abs($linea - $rm->linea)) {
                $fln[$i]->linea = $rm->linea;
            }
            if (isset($rm->like)) {
                $fln[$i]->like = $rm->like;
            }
            if (isset($rm->dislike)) {
                $fln[$i]->dislike = $rm->dislike;
            }
        }
    }

    static private function get_linea(Contact $viewer, $file, $lineid, APIData $api) {
        if (!$api->repo || !$api->hash) {
            return ["ok" => false, "error" => "Missing commit.X{$api->hash}"];
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
        } else {
            return ["ok" => false, "error" => "No such line."];
        }
    }

    static function linenotesuggest(Contact $user, Qrequest $qreq, APIData $api) {
        // check arguments
        if (!$user->isPC) {
            return ["ok" => false, "error" => "Permission error."];
        }
        if (!isset($qreq->file)
            || (isset($qreq->linea) && !ctype_digit($qreq->linea))
            || (isset($qreq->line) && !preg_match('/\A[ab]\d+\z/', $qreq->line))
            || (isset($qreq->neighborhood) && !ctype_digit($qreq->neighborhood))) {
            return ["ok" => false, "error" => "Invalid request."];
        }
        $fln = [];
        if (isset($qreq->linea)) {
            $linea = intval($qreq->linea);
        } else if (isset($qreq->line) && $qreq->line[0] === "a") {
            $linea = intval(substr($qreq->linea, 1));
        } else if (isset($qreq->line) && !str_starts_with($qreq->file, "/")) {
            $x = self::get_linea($user, $qreq->file, $qreq->line, $api);
            if (!$x["ok"]) {
                return $x;
            }
            $linea = $x["linea"];
        } else {
            $linea = 0;
        }
        $neighborhood = isset($qreq->neighborhood) ? intval($qreq->neighborhood) : 5;
        foreach (self::all_linenotes_near($api->pset, $qreq->file, $linea, $neighborhood) as $ln) {
            $rm = (object) $ln->render_map();
            $rm->n = 1;
            self::linenotesuggest_add($fln, $rm, $linea);
        }

        $data = $user->conf->fetch_value("select data from GroupSettings where name=?", "linenotemarks.p{$api->pset->id}");
        foreach (json_decode($data ?? '[]') as $rm) {
            if ($rm->file === $qreq->file
                && (!$linea || !isset($rm->linea) || abs($linea - $rm->linea) <= $neighborhood)) {
                self::linenotesuggest_add($fln, $rm, $linea);
            }
        }

        return ["ok" => true, "linea" => $linea, "notelist" => $fln];
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
