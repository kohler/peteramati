<?php
// api/api_repo.php -- Peteramati API for repositories
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class API_Repo {
    static function latestcommit(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$api->repo) {
            return ["hash" => false];
        }
        $api->repo->refresh(30);
        $c = $api->repo->latest_commit($api->pset, $api->branch);
        if (!$c) {
            return ["hash" => false];
        } else if (!$user->can_view_repo_contents($api->repo, $api->branch)) {
            return ["hash" => false, "error" => "Unconfirmed repository."];
        } else {
            $j = clone $c;
            unset($j->fromhead);
            $j->snaphash = $api->repo->snaphash;
            $j->snapcheckat = $api->repo->snapcheckat;
            return $j;
        }
    }

    static function blob(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->can_view_repo_contents($api->repo, $api->branch))
            return ["ok" => false, "error" => "Permission error."];
        if (!$qreq->file
            || (isset($qreq->fromline) && !ctype_digit($qreq->fromline))
            || (isset($qreq->linecount) && !ctype_digit($qreq->linecount)))
            return ["ok" => false, "error" => "Invalid request."];
        $repo = $api->repo;
        if ($api->commit->from_handout())
            $repo = $api->pset->handout_repo($api->repo);
        $command = "git show {$api->hash}:" . escapeshellarg($qreq->file);
        if ($qreq->fromline && intval($qreq->fromline) > 1)
            $command .= " | tail -n +" . intval($qreq->fromline);
        if ($qreq->linecount)
            $command .= " | head -n " . intval($qreq->linecount);
        $x = $api->repo->gitrun($command, true);
        if (!$x->status && ($x->stdout !== "" || $x->stderr === "")) {
            $data = $x->stdout;
            if (is_valid_utf8($data))
                return ["ok" => true, "data" => $data];
            else
                return ["ok" => true, "data" => UnicodeHelper::utf8_replace_invalid($data), "invalid_utf8" => true];
        } else if (strpos($x->stderr, "does not exist") !== false)
            return ["ok" => false, "error" => "No such file."];
        else {
            error_log("$command: $x->stderr");
            return ["ok" => false, "error" => "Problem."];
        }
    }

    static function filediff(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->can_view_repo_contents($api->repo, $api->branch))
            return ["ok" => false, "error" => "Permission error."];
        if (!$qreq->file)
            return ["ok" => false, "error" => "Invalid request."];
        $base_hash = $qreq->base_hash;
        if ($base_hash && !($base_commit = $user->conf->check_api_hash($base_hash, $api)))
            return ["ok" => false, "error" => "Disconnected commit."];
        $info = PsetView::make($api->pset, $api->user, $user);
        $info->set_commit($api->commit);
        $lnorder = $info->viewable_line_notes();
        $diff = $info->repo->diff($api->pset, $base_hash, $info->commit_hash(), array("needfiles" => [$qreq->file], "onlyfiles" => [$qreq->file]));
        if (empty($diff))
            return ["ok" => false, "error" => "No diff."];
        ob_start();
        foreach ($diff as $file => $dinfo) {
            $info->echo_file_diff($file, $dinfo, $lnorder, ["open" => true, "only_content" => true]);
        }
        $content = ob_get_contents();
        ob_end_clean();
        return ["ok" => true, "content_html" => $content];
    }

    static function user_repositories(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->isPC)
            return ["ok" => false, "error" => "Permission error."];
        if (!($organization = $user->conf->opt("githubOrganization")))
            return ["ok" => false, "error" => "No GitHub organization."];
        if (!$api->user->github_username)
            return ["ok" => false, "error" => "No GitHub username."];
        $cursor = null;
        $repos = [];
        for ($i = 0; $i < 10; ++$i) {
            $gql = GitHub_RepositorySite::graphql($user->conf,
                "{ user(login:" . json_encode($api->user->github_username) . ")"
                . "{ repositories(first:100, affiliations:[ORGANIZATION_MEMBER]"
                . ($cursor ? ", after:" . json_encode($cursor) : "")
                . ") { nodes { name, owner { login }}, pageInfo { hasNextPage, endCursor }} }}");
            if ($gql->status !== 200
                || !$gql->j
                || !isset($gql->j->data)) {
                error_log(json_encode($gql));
                return ["ok" => false, "error" => "GitHub API error."];
            }
            foreach ($gql->j->data->user->repositories->nodes as $n) {
                if ($n->owner->login === $organization)
                    $repos[] = ["name" => "$organization/{$n->name}", "url" => "https://github.com/" . urlencode($organization) . "/" . urlencode($n->name)];
            }
            usort($repos, function ($a, $b) { return strnatcmp($a["name"], $b["name"]); });
            $pageinfo = $gql->j->data->user->repositories->pageInfo;
            if (!$pageinfo->hasNextPage)
                break;
            $cursor = $pageinfo->endCursor;
        }
        return ["ok" => true, "repositories" => $repos];
    }
}
