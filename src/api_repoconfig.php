<?php
// api/api_repoconfig.php -- Peteramati API for repository configuration
// HotCRP and Peteramati are Copyright (c) 2006-2022 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class RepoConfig_API {
    static function repo(Contact $viewer, Qrequest $qreq, APIData $api) {
        $user = $api->user;
        if (!$viewer->has_account_here()
            || (!$viewer->isPC && $user !== $viewer)) {
            return MessageItem::error("<0>Permission denied")->make_json();
        } else if ($api->pset->gitless) {
            return MessageItem::error("<0>Problem set does not use git")->make_json();
        }
        if ($qreq->valid_post()) {
            if (!$qreq->repo) {
                return MessageItem::error("<0>Invalid request")->make_json();
            } else if (!$viewer->can_set_repo($api->pset, $user)) {
                return MessageItem::error("<0>Permission denied")->make_json();
            }
            $ms = new MessageSet;
            if (($commit = self::prepare_repo($viewer, $api->pset, $api->user, $qreq->repo, $ms)) === null) {
                return ["ok" => false, "message_list" => $ms->message_list()];
            }
            $commit();
        }
        $repo = $user->repo($api->pset);
        return [
            "ok" => true,
            "repoid" => $repo ? "repo{$repo->repoid}" : null,
            "url" => $repo ? $repo->friendly_url() : null
        ];
    }

    /** @return ?Closure */
    static private function prepare_repo(Contact $viewer, Pset $pset, Contact $user, $repo, MessageSet $ms) {
        $repo_url = trim($repo);
        if ($repo_url === "") {
            return function () use ($user, $pset) {
                $user->set_repo($pset, null);
            };
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
            $ms->error_at("repo", "<0>Invalid characters in repository name");
            return null;
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
            $ms->error_at("repo", "<0>Invalid repository URL {$repo_url}");
            return null;
        }

        // check repository classes
        $nmsg = $ms->message_count();
        foreach ($try_classes as $sitek) {
            $reposite = $sitek::make_url($repo_url, $user->conf);
            if ($reposite && $reposite->validate_working($user, $ms) > 0) {
                $repo = Repository::find_or_create_url($reposite->url, $user->conf);
                if ($repo) {
                    $repo->check_open();
                }
                return function () use ($user, $pset, $repo) {
                    $user->set_repo($pset, $repo);
                };
            }
        }

        // if !working, complain
        if ($ms->message_count() === $nmsg) {
            $ms->error_at("repo", "<0>Repository inaccessible");
        }
        return null;
    }

    static function branch(Contact $viewer, Qrequest $qreq, APIData $api) {
        $user = $api->user;
        if (!$viewer->has_account_here()
            || (!$viewer->isPC && $user !== $viewer)) {
            return MessageItem::error("<0>Permission denied")->make_json();
        } else if ($api->pset->gitless || $api->pset->no_branch) {
            return MessageItem::error("<0>Problem set does not use git branches")->make_json();
        }
        if ($qreq->valid_post()) {
            if (!isset($qreq->branch)) {
                return MessageItem::error("<0>Invalid request")->make_json();
            } else if (!$viewer->can_set_repo($api->pset, $user)) {
                return MessageItem::error("<0>Permission denied")->make_json();
            }
            $ms = new MessageSet;
            if (($commit = self::prepare_branch($viewer, $api->pset, $api->user, $qreq->branch, $ms)) === null) {
                return ["ok" => false, "message_list" => $ms->message_list()];
            }
            $commit();
        }
        return [
            "ok" => true,
            "branch" => $user->branch($api->pset)
        ];
    }

    /** @return ?Closure */
    static private function prepare_branch(Contact $viewer, Pset $pset, Contact $user, $branch, MessageSet $ms) {
        $branch = trim($branch);
        if ($branch === "") {
            $branch = $pset->main_branch;
        }
        if (!Repository::validate_branch($branch)) {
            $ms->error_at("branch", "<0>Invalid characters in branch name");
            return null;
        }
        return function () use ($user, $pset, $branch) {
            $branchid = $user->conf->ensure_branch($branch);
            if ($branchid === null
                || ($branchid === 0
                    && ($pset->main_branch === "master"
                        || $user->repo($pset->id)))) {
                $user->clear_links(LINK_BRANCH, $pset->id);
            } else {
                $user->set_link(LINK_BRANCH, $pset->id, $branchid);
            }
        };
    }

    static function directory(Contact $viewer, Qrequest $qreq, APIData $api) {
        $user = $api->user;
        if (!$viewer->has_account_here()
            || (!$viewer->isPC && $user !== $viewer)) {
            return MessageItem::error("<0>Permission denied")->make_json();
        } else if ($api->pset->gitless) {
            return MessageItem::error("<0>Problem set does not use git")->make_json();
        }
        if ($qreq->valid_post()) {
            if (!isset($qreq->directory)) {
                return MessageItem::error("<0>Invalid request")->make_json();
            } else if (!$viewer->can_set_repo($api->pset, $user)) {
                return MessageItem::error("<0>Permission denied")->make_json();
            }
            $ms = new MessageSet;
            if (($commit = self::prepare_directory($viewer, $api->pset, $api->user, $qreq->directory, $ms)) === null) {
                return ["ok" => false, "message_list" => $ms->message_list()];
            }
            $commit();
        }
        return [
            "ok" => true,
            "directory" => $user->pset_directory($api->pset)
        ];
    }

    /** @return ?Closure */
    static private function prepare_directory(Contact $viewer, Pset $pset, Contact $user, $directory, MessageSet $ms) {
        $directory = trim($directory);
        while (str_ends_with($directory, "/")) {
            $directory = substr($directory, 0, -1);
        }
        while (str_starts_with($directory, "./")) {
            $directory = substr($directory, 2);
        }
        if (preg_match('/(?:\A|\/)(?:\.\.|\.git)(?:\/|\z)|[\\000-\\037\\177]|\\A\/|\/\//i', $directory)
            || !is_valid_utf8($directory)) {
            $ms->error_at("directory", "<0>Invalid characters in directory name");
            return null;
        }
        return function () use ($user, $pset, $directory) {
            if ($directory === ""
                || $directory === $pset->directory_noslash) {
                $user->clear_links(LINK_DIRECTORY, $pset->id);
            }
            $user->set_link(LINK_DIRECTORY, $pset->id, $user->conf->ensure_branch($directory));
        };
    }

    static function repoconfig(Contact $viewer, Qrequest $qreq, APIData $api) {
        $user = $api->user;
        if (!$viewer->has_account_here()
            || (!$viewer->isPC && $user !== $viewer)) {
            return MessageItem::error("<0>Permission denied")->make_json();
        } else if ($api->pset->gitless) {
            return MessageItem::error("<0>Problem set does not use git")->make_json();
        }
        if ($qreq->valid_post()) {
            if (!$viewer->can_set_repo($api->pset, $user)) {
                return MessageItem::error("<0>Permission denied")->make_json();
            }
            // phase 1: validate every submitted field, collecting commits
            $ms = new MessageSet;
            $ok = true;
            $commits = [];
            if (isset($qreq->repo)) {
                if (($c = self::prepare_repo($viewer, $api->pset, $user, $qreq->repo, $ms)) !== null) {
                    $commits[] = $c;
                } else {
                    $ok = false;
                }
            }
            if (!$api->pset->no_branch && isset($qreq->branch)) {
                if (($c = self::prepare_branch($viewer, $api->pset, $user, $qreq->branch, $ms)) !== null) {
                    $commits[] = $c;
                } else {
                    $ok = false;
                }
            }
            if ($api->pset->allow_directory_override && isset($qreq->directory)) {
                if (($c = self::prepare_directory($viewer, $api->pset, $user, $qreq->directory, $ms)) !== null) {
                    $commits[] = $c;
                } else {
                    $ok = false;
                }
            }
            // phase 2: commit only if every field validated
            if (!$ok) {
                return ["ok" => false, "message_list" => $ms->message_list()];
            }
            foreach ($commits as $c) {
                $c();
            }
        }
        $repo = $user->repo($api->pset);
        return [
            "ok" => true,
            "repoid" => $repo ? "repo{$repo->repoid}" : null,
            "url" => $repo ? $repo->friendly_url() : null,
            "branch" => $user->branch($api->pset),
            "directory" => $user->pset_directory($api->pset)
        ];
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
