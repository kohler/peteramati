<?php
// api/api_repoconfig.php -- Peteramati API for repository configuration
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class RepoConfig_API {
    static function repo(Contact $viewer, Qrequest $qreq, APIData $api) {
        $user = $api->user;
        if (!$viewer->has_account_here()
            || (!$viewer->isPC && $user !== $viewer)) {
            return (new MessageItem(null, "<0>Permission denied", 2))->make_json();
        } else if ($api->pset->gitless) {
            return (new MessageItem(null, "<0>Problem set does not use git", 2))->make_json();
        }
        if ($qreq->valid_post()) {
            if (!$qreq->repo) {
                return (new MessageItem(null, "<0>Invalid request", 2))->make_json();
                return ["ok" => false, "error" => "Invalid request"];
            } else if (!$viewer->can_set_repo($api->pset, $user)) {
                return (new MessageItem(null, "<0>Permission denied", 2))->make_json();
            } else if (($em = self::post_repo($viewer, $api->pset, $api->user, $qreq->repo))) {
                return $em;
            }
        }
        $repo = $user->repo($api->pset);
        return [
            "ok" => true,
            "repoid" => $repo ? "repo{$repo->repoid}" : null,
            "url" => $repo ? $repo->friendly_url() : null
        ];
    }

    static private function post_repo(Contact $viewer, Pset $pset, Contact $user, $repo) {
        $repo_url = trim($repo);
        if ($repo_url === "") {
            $user->set_repo($pset, null);
            return null;
        }

        // expand repo url, check for odd characters
        if (($rgp = $pset->repo_guess_patterns) !== null) {
            for ($i = 0; $i + 1 < count($rgp); $i += 2) {
                $x = preg_replace('`' . str_replace("`", "\\`", $rgp[$i]) . '`s',
                                  $rgp[$i + 1], $repo_url, -1, $nreplace);
                if ($x !== null && $nreplace) {
                    $repo_url = $x;
                    break;
                }
            }
        }
        if (preg_match('/[,;\[\](){}\\<>&#=\\000-\\027]/', $repo_url)) {
            return (new MessageItem("repo", "<0>Invalid characters in repository name", 2))->make_json();
        }

        // enumerate interested repository classes
        $try_classes = [];
        foreach (RepositorySite::site_classes($user->conf) as $sitek) {
            $sniff = $sitek::sniff_url($repo_url);
            if ($sniff == 2) {
                $try_classes = [$sitek];
                break;
            } else if ($sniff) {
                $try_classes[] = $sitek;
            }
        }
        if (empty($try_classes)) {
            return (new MessageItem("repo", "<0>Invalid repository URL {$repo_url}", 2))->make_json();
        }

        // check repository classes
        $ms = new MessageSet;
        foreach ($try_classes as $sitek) {
            $reposite = $sitek::make_url($repo_url, $user->conf);
            if ($reposite && $reposite->validate_working($user, $ms) > 0) {
                $repo = Repository::find_or_create_url($reposite->url, $user->conf);
                if ($repo) {
                    $repo->check_open();
                }
                $user->set_repo($pset, $repo);
                return null;
            }
        }

        // if !working, complain
        if (!$ms->has_problem()) {
            return (new MessageItem("repo", "<0>Repository inaccessible", 2))->make_json();
        }
        return ["ok" => false, "message_list" => $ms->message_list()];
    }

    static function branch(Contact $viewer, Qrequest $qreq, APIData $api) {
        $user = $api->user;
        if (!$viewer->has_account_here()
            || (!$viewer->isPC && $user !== $viewer)) {
            return (new MessageItem(null, "<0>Permission denied", 2))->make_json();
        } else if ($api->pset->gitless || $api->pset->no_branch) {
            return (new MessageItem(null, "<0>Problem set does not use git branches", 2))->make_json();
        }
        if ($qreq->valid_post()) {
            if (!$qreq->branch) {
                return (new MessageItem(null, "<0>Invalid request", 2))->make_json();
                return ["ok" => false, "error" => "Invalid request"];
            } else if (!$viewer->can_set_repo($api->pset, $user)) {
                return (new MessageItem(null, "<0>Permission denied", 2))->make_json();
            } else if (($em = self::post_branch($viewer, $api->pset, $api->user, $qreq->branch))) {
                return $em;
            }
        }
        return [
            "ok" => true,
            "branch" => $user->branch($api->pset)
        ];
    }

    static private function post_branch(Contact $viewer, Pset $pset, Contact $user, $branch) {
        $branch = trim($branch);
        if ($branch === "") {
            $branch = $pset->main_branch;
        }
        if (!Repository::validate_branch($branch)) {
            return (new MessageItem("branch", "<0>Invalid characters in branch name", 2))->make_json();
        }
        $branchid = $user->conf->ensure_branch($branch);
        if ($branchid === null
            || ($branchid === 0
                && ($pset->main_branch === "master"
                    || $user->repo($pset->id)))) {
            $user->clear_links(LINK_BRANCH, $pset->id);
        } else {
            $user->set_link(LINK_BRANCH, $pset->id, $branchid);
        }
        return null;
    }

    static function user_repositories(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$user->isPC) {
            return ["ok" => false, "error" => "Permission denied"];
        }
        if (!($organization = $user->conf->opt("githubOrganization"))) {
            return ["ok" => false, "error" => "GitHub access not configured"];
        }
        if (!$api->user->github_username) {
            return ["ok" => false, "error" => "GitHub username not found"];
        }
        $cursor = null;
        $repos = [];
        for ($i = 0; $i < 10; ++$i) {
            $gql = GitHub_RepositorySite::graphql($user->conf,
                "{ user(login:" . json_encode($api->user->github_username) . ")"
                . "{ repositories(first:100, affiliations:[ORGANIZATION_MEMBER]"
                . ($cursor ? ", after:" . json_encode($cursor) : "")
                . ") { nodes { name, owner { login }}, pageInfo { hasNextPage, endCursor }} }}");
            if (!$gql->rdata) {
                error_log(json_encode($gql));
                return ["ok" => false, "error" => "GitHub API error."];
            }
            foreach ($gql->rdata->user->repositories->nodes as $n) {
                if ($n->owner->login === $organization)
                    $repos[] = ["name" => "$organization/{$n->name}", "url" => "https://github.com/" . urlencode($organization) . "/" . urlencode($n->name)];
            }
            usort($repos, function ($a, $b) { return strnatcmp($a["name"], $b["name"]); });
            $pageinfo = $gql->rdata->user->repositories->pageInfo;
            if (!$pageinfo->hasNextPage)
                break;
            $cursor = $pageinfo->endCursor;
        }
        return ["ok" => true, "repositories" => $repos];
    }
}
