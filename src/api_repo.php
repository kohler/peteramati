<?php
// api/api_repo.php -- Peteramati API for repositories
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Repo_API {
    static function latestcommit(Contact $user, Qrequest $qreq, APIData $api) {
        if ($user->is_empty()) {
            return ["ok" => false, "error" => "Permission denied"];
        }

        if (!$api->pset) {
            $apis = [];
            foreach ($user->conf->psets() as $pset) {
                if (!$pset->gitless && !$pset->disabled && $user->can_view_pset($pset)) {
                    $apix = new APIData($api->user, $pset);
                    $apix->repo = $api->user->repo($pset->id);
                    $apix->branch = $api->user->branch($pset);
                    $apis[] = $apix;
                }
            }
        } else {
            $apis = [$api];
        }

        $fresh = 30;
        if ($qreq->fresh !== null && ctype_digit($qreq->fresh)) {
            $fresh = max((int) $qreq->fresh, 10);
        }

        $repofreshes = [];
        $commits = [];
        foreach ($apis as $apix) {
            $commits[] = self::latestcommit1($user, $apix, $fresh, !!$qreq->sync, $repofreshes);
        }
        return ["ok" => true, "commits" => $commits];
    }

    static private function latestcommit1(Contact $user, APIData $api, $fresh, $sync, &$repofreshes) {
        $pset = $api->pset;
        $repo = $api->repo;
        if (!$repo) {
            return ["pset" => $pset->urlkey, "commit" => false, "hash" => false, "error" => "No repository configured."];
        } else {
            if (!isset($repofreshes[$repo->url])) {
                $repo->refresh($fresh, $sync);
                $repofreshes[$repo->url] = true;
            }
            $c = $repo->latest_commit($pset, $api->branch);
            if (!$c) {
                return ["pset" => $pset->urlkey, "commit" => false, "hash" => false, "error" => "No commits."];
            } else if (!$user->can_view_repo_contents($repo, $api->branch)) {
                return ["pset" => $pset->urlkey, "commit" => false, "hash" => false, "error" => "Unconfirmed repository."];
            } else {
                return [
                    "pset" => $pset->urlkey,
                    "commit" => $c->hash,
                    "hash" => $c->hash,
                    "subject" => $c->subject,
                    "commitat" => $c->commitat,
                    "snaphash" => $repo->snaphash,
                    "snapcheckat" => $repo->snapcheckat
                ];
            }
        }
    }

    static function diffconfig(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->can_view_repo_contents($api->repo, $api->branch)
            || ($qreq->is_post() && !$qreq->valid_post())) {
            return ["ok" => false, "error" => "Permission error."];
        }
        $info = PsetView::make($api->pset, $api->user, $user);
        if (($err = $api->prepare_commit($info))) {
            return $err;
        }
        if ($qreq->is_post()) {
            $file = str_replace("\\*", ".*", $qreq->file ?? "*");
            $baseline = $api->pset->baseline_diffconfig($file);
            $diff = [];
            if (isset($qreq->markdown)) {
                if ($qreq->markdown === "") {
                    $diff["markdown"] = null;
                } else if (($b = friendly_boolean($qreq->markdown)) !== null) {
                    $diff["markdown"] = $b;
                } else {
                    return ["ok" => false, "error" => "Bad `markdown`."];
                }
            }
            if (isset($qreq->collapse)) {
                if ($qreq->collapse === "") {
                    $diff["collapse"] = null;
                } else if (($b = friendly_boolean($qreq->collapse)) !== null) {
                    $diff["collapse"] = $b;
                } else {
                    return ["ok" => false, "error" => "Bad `collapse`."];
                }
            }
            if (isset($qreq->tabwidth)) {
                if ($qreq->tabwidth === "" || $qreq->tabwidth === "0") {
                    $diff["tabwidth"] = null;
                } else if (($i = cvtint($qreq->tabwidth)) > 0 && $i < 16) {
                    $diff["tabwidth"] = $i;
                } else {
                    return ["ok" => false, "error" => "Bad `tabwidth`."];
                }
            }
            if (!empty($diff)) {
                $gr = $info->grading_commit();
                if (!$gr || $gr->commitat === $api->commit->commitat) {
                    $info->update_repository_notes(["diffs" => [$file => $diff]]);
                    $info->update_commit_notes(["diffs" => [$file => array_fill_keys(array_keys($diff), null)]]);
                } else {
                    $info->update_commit_notes(["diffs" => [$file => $diff]]);
                }
            }
        }
        return ["ok" => true];
    }

    static function blob(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->can_view_repo_contents($api->repo, $api->branch)) {
            return ["ok" => false, "error" => "Permission error."];
        }
        if (!$qreq->file
            || (isset($qreq->fromline) && !ctype_digit($qreq->fromline))
            || (isset($qreq->linecount) && !ctype_digit($qreq->linecount))) {
            return ["ok" => false, "error" => "Invalid request."];
        }
        $repo = $api->repo;
        if ($api->pset->is_handout($api->commit)) {
            $repo = $api->pset->handout_repo($api->repo);
        }
        $args = [];
        if ($qreq->fromline && intval($qreq->fromline) > 1) {
            $args["firstline"] = intval($qreq->fromline);
        }
        if ($qreq->linecount) {
            $args["linecount"] = intval($qreq->linecount);
        }
        $x = $api->repo->gitruninfo(["git", "show", "{$api->hash}:{$qreq->file}"], $args);
        if (!$x->status && ($x->stdout !== "" || $x->stderr === "")) {
            $data = $x->stdout;
            if (is_valid_utf8($data)) {
                return ["ok" => true, "data" => $data];
            } else {
                return ["ok" => true, "data" => UnicodeHelper::utf8_replace_invalid($data), "invalid_utf8" => true];
            }
        } else if (strpos($x->stderr, "does not exist") !== false) {
            return ["ok" => false, "error" => "No such file."];
        } else {
            error_log(join(" ", $x->command) . ": " . $x->stderr);
            return ["ok" => false, "error" => "Problem."];
        }
    }

    static function filediff(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->can_view_repo_contents($api->repo, $api->branch)) {
            return ["ok" => false, "error" => "Permission error."];
        }
        if (!$qreq->file) {
            return ["ok" => false, "error" => "Invalid request."];
        }
        $info = PsetView::make($api->pset, $api->user, $user);
        if (($qreq->base_commit ?? $qreq->base_hash ?? "") === "") {
            $base_commit = $info->base_handout_commit();
        } else {
            $base_commit = $user->conf->check_api_hash($qreq->base_commit ?? $qreq->base_hash, $api);
        }
        if (!$base_commit) {
            return ["ok" => false, "error" => "Disconnected commit."];
        }
        $info->set_commit($api->commit);
        $lnorder = $info->visible_line_notes();
        $diff = $info->repo->diff($api->pset, $base_commit, $info->commit(), ["needfiles" => [$qreq->file], "onlyfiles" => [$qreq->file], "wdiff" => !!$qreq->wdiff]);
        if (empty($diff)) {
            return ["ok" => false, "error" => "No diff."];
        }
        ob_start();
        foreach ($diff as $file => $dinfo) {
            $info->echo_file_diff($file, $dinfo, $lnorder, ["expand" => true, "only_content" => true]);
        }
        $content = ob_get_contents();
        ob_end_clean();
        return ["ok" => true, "content_html" => $content];
    }
}
