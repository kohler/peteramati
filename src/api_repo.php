<?php

class API_Repo {
    static function latestcommit(Contact $user, Qrequest $qreq, APIData $api) {
        if (!$api->repo || !($c = $api->repo->latest_commit($api->pset)))
            return ["hash" => false];
        else if (!$user->can_view_repo_contents($api->repo))
            return ["hash" => false, "error" => "Unconfirmed repository"];
        else {
            $j = clone $c;
            unset($j->fromhead);
            $j->snaphash = $api->repo->snaphash;
        }
        return $j;
    }
}
