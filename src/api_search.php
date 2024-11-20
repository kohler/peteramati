<?php
// api/api_search.php -- Peteramati API for search
// HotCRP and Peteramati are Copyright (c) 2006-2024 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class Search_API {
    static function search(Contact $viewer, Qrequest $qreq, APIData $api) {
        if (!$viewer->isPC) {
            return ["ok" => false, "error" => "Permission error"];
        } else if (!isset($qreq->q)) {
            return ["ok" => false, "error" => "Missing parameter"];
        }
        $sset = StudentSet::make_all($viewer->conf);
        $anonymous = friendly_boolean($qreq->anonymous);
        if ($api->pset) {
            $sset->set_pset($api->pset, $anonymous);
        } else if ($anonymous !== null) {
            $sset->set_anonymous($anonymous);
        }
        $gfc = new GradeFormulaCompiler($viewer->conf);
        $gf = $gfc->parse_search($qreq->q, $api->pset);
        $a = [];
        foreach ($sset->users() as $u) {
            $info = $sset->info($u->contactId);
            if ($gf->evaluate($u, $info)) {
                $a[] = $u->contactId;
            }
        }
        return ["ok" => true, "uids" => $a];
    }
}
