<?php
// api/api_linenote.php -- Peteramati API for grading
// HotCRP and Peteramati are Copyright (c) 2006-2021 Eddie Kohler and others
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
            || (isset($apply->aline) && !ctype_digit($apply->aline))) {
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
        $note->ftext = rtrim(cleannl($apply->ftext ?? $apply->note ?? $apply->text ?? ""));
        $note->version = intval($note->version) + 1;
        if (isset($apply->aline)) {
            $note->aline = intval($apply->aline);
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


    /** @param string $file
     * @param int $aline
     * @param int $neighborhood
     * @param int $start
     * @return \Generator<array{CommitPsetInfo,LineNote}> */
    static function linenotesnear_iterator(Pset $pset, $file, $aline, $neighborhood, $start = 0) {
        $end = $start;
        while ($start === $end) {
            $result = $pset->conf->qe("select CommitNotes.*, Repository.url repourl from CommitNotes left join Repository on (Repository.repoid=CommitNotes.repoid) where pset=? and haslinenotes order by updateat desc, repoid asc, bhash asc limit $start,20", $pset->id);
            $end = $start + 20;
            while (($cpi = CommitPsetInfo::fetch($result))) {
                if (($linenotes = $cpi->jnote("linenotes"))
                    && ($xn = $linenotes->{$file} ?? null)) {
                    $fln = [];
                    foreach ((array) $xn as $lineid => $jnote) {
                        if (!is_int($jnote)
                            && ($ln = LineNote::make_json($file, $lineid, $jnote))
                            && (!$aline
                                || (($lna = $ln->aline()) && abs($aline - $lna) <= $neighborhood))) {
                            $fln[] = $ln;
                        }
                    }
                    usort($fln, function ($a, $b) {
                        $aa = $a->aline();
                        $ba = $a->aline();
                        if ($aa && $ba && $aa !== $ba) {
                            return $aa <=> $ba;
                        } else {
                            return strnatcmp($a->lineid, $b->lineid);
                        }
                    });
                    foreach ($fln as $ln) {
                        yield [$cpi, $ln];
                    }
                }
                ++$start;
            }
            Dbl::free($result);
        }
    }

    static function linenotesnear(Contact $user, Qrequest $qreq, APIData $api) {
    }
}
