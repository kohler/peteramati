<?php
// api_leaderboard.php -- Peteramati API for leaderboards
// Peteramati is Copyright (c) 2006-2026 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Leaderboard_API {
    static function run(Contact $user, Qrequest $qreq, APIData $api) {
        $pset = $api->pset;
        if ($user->is_empty()) {
            return ["error" => "Permission error"];
        } else if (!$pset->has_leaderboard()) {
            return ["error" => "Pset has no leaderboard"];
        } else if (!$user->can_view_leaderboard($pset)) {
            return ["error" => "Permission error"];
        }
        $lb = new Leaderboard($pset, $user);
        $etag = $lb->etag();
        // always revalidate; the ETag makes that cheap
        header("Cache-Control: private,no-cache");
        header("ETag: {$etag}");
        if (($_SERVER["HTTP_IF_NONE_MATCH"] ?? null) === $etag) {
            header("HTTP/1.0 304 Not Modified");
            exit(0);
        }
        return $lb->json();
    }
}
