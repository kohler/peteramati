<?php
// api/api_repo.php -- Peteramati API for repositories
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class RepoLatestCommit_API implements JsonSerializable {
    /** @var string */
    public $pset;
    /** @var string */
    public $repoid;
    /** @var false|string */
    public $hash = false;
    /** @var ?string */
    public $subject;
    /** @var ?int */
    public $commitat;
    /** @var ?string */
    public $snaphash;
    /** @var ?int */
    public $snapcheckat;
    /** @var ?string */
    public $error;

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = [];
        foreach (get_object_vars($this) as $k => $v) {
            if ($v !== null)
                $j[$k] = $v;
        }
        return $j;
    }
}

class Repo_API {
    /** @return list<APIData> */
    static function expand_apis(APIData $api) {
        if ($api->pset) {
            return [$api];
        }
        $apis = [];
        foreach ($api->conf->psets() as $pset) {
            if (!$pset->gitless
                && !$pset->disabled
                && $api->user->can_view_pset($pset)) {
                $apix = new APIData($api->user, $pset);
                $apix->repo = $api->user->repo($pset->id);
                $apix->branch = $api->user->branch($pset);
                $apis[] = $apix;
            }
        }
        return $apis;
    }

    static function latestcommit(Contact $user, Qrequest $qreq, APIData $mapi) {
        if ($user->is_empty()) {
            return ["ok" => false, "error" => "Permission denied"];
        }

        $apis = self::expand_apis($mapi);
        $fresh = 30;
        if ($qreq->fresh !== null && ctype_digit($qreq->fresh)) {
            $fresh = max((int) $qreq->fresh, 10);
        }
        $sync = !!$qreq->sync;

        $has_refreshed = [];
        $commits = [];
        foreach ($apis as $api) {
            if ($api->repo && !isset($has_refreshed[$api->repo->repoid])) {
                $api->repo->refresh($fresh, $sync);
                $has_refreshed[$api->repo->repoid] = true;
            }
            $commits[] = self::latestcommit1($user, $api);
        }
        return ["ok" => true, "commits" => $commits];
    }

    static private function latestcommit1(Contact $user, APIData $api) {
        $repo = $api->repo;
        $ans = new RepoLatestCommit_API;
        $ans->pset = $api->pset->urlkey;
        if (!$repo) {
            $ans->error = "No repository configured";
        } else if (!$user->can_view_repo_contents($repo, $api->branch)) {
            $ans->error = "Unconfirmed repository";
        } else if (!($c = $repo->latest_commit($api->pset, $api->branch))) {
            $ans->repoid = "repo{$repo->repoid}";
            $ans->error = "No commits";
        } else {
            $ans->repoid = "repo{$repo->repoid}";
            $ans->hash = $c->hash;
            $ans->subject = $c->subject;
            $ans->commitat = $c->commitat;
            $ans->snaphash = $repo->snaphash;
            $ans->snapcheckat = $repo->snapcheckat;
        }
        return $ans;
    }

    static function branches(Contact $user, Qrequest $qreq, APIData $mapi) {
        if ($user->is_empty()) {
            return ["ok" => false, "error" => "Permission denied"];
        } else if (!preg_match('/\A(?:repo|)(\d+)\z/', $qreq->repoid ?? "", $m)) {
            return ["ok" => false, "error" => "Invalid request"];
        }

        $repo = Repository::by_id(intval($m[1]), $mapi->conf);
        if (!$repo) {
            return ["ok" => false, "error" => $user->isPC ? "Repository not found" : "Permission denied"];
        }

        $vbr = [];
        foreach ($repo->branches() as $br) {
            if ($user->can_view_repo_contents($repo, $br))
                $vbr[] = $br;
        }
        if (empty($vbr) && !$user->isPC) {
            return ["ok" => false, "error" => "Permission denied"];
        } else {
            return ["ok" => true, "branches" => $vbr];
        }
    }

    static function diffconfig(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->can_view_repo_contents($api->repo, $api->branch)
            || ($qreq->is_post() && !$qreq->valid_post())) {
            return ["ok" => false, "error" => "Permission denied"];
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
            return ["ok" => false, "error" => "Permission denied"];
        }
        if (!$qreq->file
            || (isset($qreq->fromline) && !ctype_digit($qreq->fromline))
            || (isset($qreq->linecount) && !ctype_digit($qreq->linecount))) {
            return ["ok" => false, "error" => "Invalid request."];
        }
        $repo = $api->repo;
        if ($api->commit->is_handout($api->pset)) {
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
        if ($x->ok && ($x->stdout !== "" || $x->stderr === "")) {
            $data = $x->stdout;
            if (is_valid_utf8($data)) {
                return ["ok" => true, "data" => $data];
            } else {
                return ["ok" => true, "data" => UnicodeHelper::utf8_replace_invalid($data), "invalid_utf8" => true];
            }
        } else if (strpos($x->stderr, "does not exist") !== false) {
            return ["ok" => false, "error" => "No such file."];
        } else {
            error_log(join(" ", $x->command) . ": {$x->status}: " . $x->stderr);
            return ["ok" => false, "error" => "Problem."];
        }
    }

    static function filediff(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->can_view_repo_contents($api->repo, $api->branch)) {
            return ["ok" => false, "error" => "Permission denied"];
        }
        if (!$qreq->file) {
            return ["ok" => false, "error" => "Invalid request"];
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
        $dctx = new DiffContext($info->repo, $api->pset, $base_commit, $info->commit());
        $dctx->add_allowed_file($qreq->file);
        $dctx->add_required_file($qreq->file);
        $dctx->wdiff = !!friendly_boolean($qreq->wdiff);
        $diff = $info->repo->diff($dctx);
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
