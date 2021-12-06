<?php
// updateschema.php -- Peteramati function for updating old schemata
// Peteramati is Copyright (c) 2006-2021 Eddie Kohler and others
// See LICENSE for open-source distribution terms

function _update_schema_haslinenotes($conf) {
    $conf->ql("lock tables CommitNotes write");
    $hashes = array(array(), array(), array(), array());
    $result = $conf->ql("select hash, notes from CommitNotes");
    while (($row = $result->fetch_row())) {
        $x = PsetView::notes_haslinenotes(json_decode($row[1]));
        $hashes[$x][] = $row[0];
    }
    foreach ($hashes as $x => $h) {
        if (count($h))
            $conf->ql("update CommitNotes set haslinenotes=$x where hash in ('" . join("','", $h) . "')");
    }
    $conf->ql("unlock tables");
    return true;
}

function _update_schema_pset_commitnotes(Conf $conf) {
    return $conf->ql_ok("drop table if exists CommitInfo")
        && $conf->ql_ok("alter table CommitNotes add `pset` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table CommitNotes drop key `hash`")
        && $conf->ql_ok("alter table CommitNotes add unique key `hashpset` (`hash`,`pset`)")
        && $conf->ql_ok("update CommitNotes, RepositoryGrade set CommitNotes.pset=RepositoryGrade.pset where CommitNotes.hash=RepositoryGrade.gradehash");
}

function _update_schema_regrade_flags(Conf $conf) {
    $result = $conf->ql_ok("select rgr.*, u.cid from RepositoryGradeRequest rgr
        left join (select link, pset, min(cid) cid
                   from ContactLink where type=" . LINK_REPO . " group by link, pset) u on (u.link=rgr.repoid)");
    while (($row = $result->fetch_object())) {
        if ($row->cid && ($u = $conf->user_by_id($row->cid))
            && ($pset = $conf->pset_by_id($row->pset))) {
            $info = PsetView::make($pset, $u, $u);
            $info->force_set_hash($row->hash);
            $update = ["flags" => ["t" . $row->requested_at => ["uid" => $u->contactId]]];
            $info->update_commit_notes($update);
        }
    }
    Dbl::free($result);
    return true;
}

function _update_schema_hasflags(Conf $conf) {
    if (!$conf->ql_ok("alter table CommitNotes add `hasflags` tinyint(1) NOT NULL DEFAULT '0'")) {
        return false;
    }
    $result = $conf->ql("select * from CommitNotes");
    $queries = [];
    $qv = [];
    while (($row = $result->fetch_object())) {
        if ($row->notes && ($n = json_decode($row->notes)) && isset($n->flags) && count((array) $n->flags)) {
            $queries[] = "update CommitNotes set hasflags=1 where hash=? and pset=?";
            array_push($qv, $row->hash, $row->pset);
        }
    }
    Dbl::free($result);
    $mresult = Dbl::multi_ql_apply(join(";", $queries), $qv);
    while ($mresult->next()) {
    }
    return true;
}

function _update_schema_linenotes(Conf $conf) {
    $result = $conf->ql("select * from CommitNotes where notes like '%linenotes%'");
    $queries = [];
    $qv = [];
    while (($row = $result->fetch_object())) {
        if (($n = json_decode($row->notes)) && isset($n->linenotes)) {
            foreach ($n->linenotes as $file => $linemap) {
                foreach ($linemap as $lineid => &$note) {
                    $note = LineNote::make_json($file, $lineid, $note);
                }
                unset($note);
            }
            $queries[] = "update CommitNotes set notes=? where hash=? and pset=?";
            array_push($qv, json_encode($n), $row->hash, $row->pset);
        }
    }
    Dbl::free($result);
    $mresult = Dbl::multi_ql_apply(join(";", $queries), $qv);
    while ($mresult->next()) {
    }
    return true;
}

function update_schema_branches(Conf $conf) {
    $result = $conf->ql("select distinct data from ContactLink where type=" . LINK_BRANCH);
    while (($row = $result->fetch_row())) {
        if ($row[0] !== "master")
            $conf->ql("insert into Branch set branch=? on duplicate key update branch=branch", $row[0]);
    }
    Dbl::free($result);

    $result = $conf->ql("select * from Branch");
    $map = ["master" => 0];
    while (($row = $result->fetch_object())) {
        $map[$row->branch] = $row->branchid;
    }
    Dbl::free($result);

    foreach ($map as $b => $n) {
        $conf->ql("update ContactLink set link=? where type=" . LINK_BRANCH . " and data=?", $n, $b);
    }
    $conf->ql("update ContactLink set link=0 where type=" . LINK_BRANCH . " and data is null");
    return true;
}

function update_schema_branched_repo_grade(Conf $conf) {
    // branches
    $result = $conf->ql("select branchid, branch from Branch");
    $branches = [];
    while ($result && ($row = $result->fetch_row())) {
        $branches[(int) $row[0]] = $row[1];
    }
    Dbl::free($result);

    // people with branches
    $result = $conf->ql("select c1.pset, c1.link, c2.link from ContactLink c1 join ContactLink c2 on (c1.cid=c2.cid and c1.pset=c2.pset) where c1.type=" . LINK_REPO . " and c2.type=" . LINK_BRANCH);
    $qstager = Dbl::make_multi_ql_stager($conf->dblink);
    $repos = [];
    while ($result && ($row = $result->fetch_row())) {
        $pset = (int) $row[0];
        $repoid = (int) $row[1];
        $branchid = (int) $row[2];
        $branch = $branchid ? $branches[$branchid] : "master";
        if (!isset($repos["$pset,$repoid"])) {
            $qstager("update RepositoryGrade set branchid=? where repoid=? and pset=?", [$branchid, $repoid, $pset]);
            $repos["$pset,$repoid"] = $branch;
        } else {
            error_log("RepositoryGrade[$pset,$repoid] conflict: branch " . $branch . " vs. " . $repos["$pset,$repoid"]);
        }
    }
    $qstager(true);
    Dbl::free($result);
    return true;
}

function update_schema_known_branches(Conf $conf) {
    // branches
    $result = $conf->ql("select branchid, branch from Branch");
    $branches = $rbranches = [];
    $max_branchid = 0;
    while ($result && ($row = $result->fetch_row())) {
        $branchid = (int) $row[0];
        $branches[$branchid] = $row[1];
        $rbranches[$row[1]] = $branchid;
        $max_branchid = max($branchid, $max_branchid);
    }
    Dbl::free($result);

    // `main` must have branchid 1
    if (($branches[1] ?? "main") !== "main") {
        $conf->qe("delete from Branch where branchid=1");
        $result = $conf->qe("insert into Branch set branch=?", $branches[1]);
        $branchid = $result->insert_id;
        $conf->qe("update ContactLink set link={$branchid} where type=" . LINK_BRANCH . " and link=1");
        $conf->qe("update Branch set branchid={$branchid} where branchid=1");
        $conf->qe("update RepositoryGrade set branchid={$branchid} where branchid=1");
    }

    // `master` must have branchid 0
    if (($rbranches["master"] ?? 0) !== 0) {
        $conf->qe("update ContactLink set link=0 where type=" . LINK_BRANCH . " and link=" . $rbranches["master"]);
        $conf->qe("update Branch set branchid=0 where branchid=" . $rbranches["master"]);
        $conf->qe("update RepositoryGrade set branchid=-1 where branchid=" . $rbranches["master"]);
    }

    if (($rbranches["main"] ?? 1) !== 1) {
        $conf->qe("update ContactLink set link=1 where type=" . LINK_BRANCH . " and link=" . $rbranches["main"]);
        $conf->qe("update Branch set branchid=1 where branchid=" . $rbranches["main"]);
        $conf->qe("update RepositoryGrade set branchid=1 where branchid=" . $rbranches["main"]);
    }

    $conf->clear_branch_map();
    return true;
}

function update_schema_known_branch_links(Conf $conf) {
    foreach ($conf->psets() as $pset) {
        if (!$pset->gitless && $pset->main_branch !== "master") {
            $result = $conf->qe("select rc.cid from ContactLink rc
                left join ContactLink bc on (bc.cid=rc.cid and bc.type=" . LINK_BRANCH . " and bc.pset=rc.pset)
                where rc.type=" . LINK_REPO . " and rc.pset={$pset->id} and bc.pset is null");
            $branchid = $conf->ensure_branch($pset->main_branch);
            $qv = [];
            while (($row = $result->fetch_row())) {
                $qv[] = [+$row[0], LINK_BRANCH, $pset->id, $branchid];
            }
            Dbl::free($result);
            if (!empty($qv)) {
                $conf->qe("insert into ContactLink (cid,type,pset,link) values ?v", $qv);

                $conf->qe("delete from RepositoryGrade where branchid=? and pset=? and placeholder=1", $branchid, $pset->id);
                $conf->qe("update RepositoryGrade set branchid=? where branchid=0 and pset=?", $branchid, $pset->id);
            }
        }
    }
    $conf->qe("update RepositoryGrade set branchid=0 where branchid=-1");
    return true;
}

function update_schema_drop_keys_if_exist($conf, $table, $key) {
    $indexes = Dbl::fetch_first_columns($conf->dblink, "select distinct index_name from information_schema.statistics where table_schema=database() and `table_name`='$table'");
    $drops = [];
    foreach (is_array($key) ? $key : [$key] as $k) {
        if (in_array($k, $indexes))
            $drops[] = ($k === "PRIMARY" ? "drop primary key" : "drop key `$k`");
    }
    if (count($drops)) {
        return $conf->ql_ok("alter table `$table` " . join(", ", $drops));
    } else {
        return true;
    }
}

function update_schema_studentupdateat($conf) {
    $all = [];
    $result = $conf->qe("select * from ContactGrade");
    while (($row = UserPsetInfo::fetch($result))) {
        $all[$row->pset][$row->cid][] = $row;
    }
    Dbl::free($result);

    $result = $conf->qe("select * from ContactGradeHistory");
    while (($row = UserPsetHistory::fetch($result))) {
        $all[$row->pset][$row->cid][] = $row;
    }
    Dbl::free($result);

    $mqe = Dbl::make_multi_query_stager($conf->dblink, Dbl::F_LOG);
    foreach ($all as $pset => &$bycid) {
        foreach ($bycid as $cid => &$items) {
            $n = count($items);
            usort($items, function ($a, $b) {
                return $a->notesversion - $b->notesversion;
            });
            $studentupdateat = null;
            for ($i = 0; $i !== $n; ++$i) {
                if ($items[$i]->updateby == $cid) {
                    $studentupdateat = $items[$i]->updateat;
                }
                if ($items[$i]->studentupdateat !== $studentupdateat) {
                    $table = $items[$i] instanceof UserPsetInfo ? "ContactGrade" : "ContactGradeHistory";
                    $mqe("update $table set studentupdateat=? where cid=? and pset=? and notesversion=?", [$studentupdateat, $cid, $pset, $items[$i]->notesversion]);
                }
            }
        }
        unset($items);
    }
    $mqe(true);
    return true;
}

function updateSchema($conf) {
    global $OK;
    // avoid error message about timezone, set to $Opt
    // (which might be overridden by database values later)
    if (function_exists("date_default_timezone_set") && $conf->opt("timezone")) {
        date_default_timezone_set($conf->opt("timezone"));
    }
    while (($result = $conf->ql("insert into Settings set name='__schema_lock', value=1 on duplicate key update value=1"))
           && $result->affected_rows == 0) {
        time_nanosleep(0, 200000000);
    }
    $conf->update_schema_version(null);

    error_log($conf->dbname . ": updating schema from version " . $conf->sversion);

    if ($conf->sversion == 58
        && $conf->ql_ok("alter table ContactInfo add `college` tinyint(1) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table ContactInfo add `extension` tinyint(1) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(59);
    }
    if ($conf->sversion == 59
        && $conf->ql_ok("alter table Repository add `lastpset` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("update Repository join ContactLink on (ContactLink.type=" . LINK_REPO . " and ContactLink.link=Repository.repoid) set Repository.lastpset=greatest(Repository.lastpset,ContactLink.pset)")) {
        $conf->update_schema_version(60);
    }
    if ($conf->sversion == 60
        && $conf->ql_ok("alter table Repository add `working` int(1) NOT NULL DEFAULT '1'")) {
        $conf->update_schema_version(61);
    }
    if ($conf->sversion == 61
        && $conf->ql_ok("alter table ContactInfo add `dropped` tinyint(1) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(62);
    }
    if ($conf->sversion == 62
        && $conf->ql_ok("alter table ContactInfo drop column `dropped`")
        && $conf->ql_ok("alter table ContactInfo add `dropped` int(11) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(63);
    }
    if ($conf->sversion == 63
        && $conf->ql_ok("alter table Repository add `snapcommitat` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table Repository add `snapcommitline` varchar(100)")) {
        $conf->update_schema_version(64);
    }
    if ($conf->sversion == 64
        && $conf->ql_ok("drop table if exists `CommitNotes`")
        && $conf->ql_ok("alter table `Repository` modify `snaphash` binary(40)")
        && $conf->ql_ok("create table `CommitNotes` ( `hash` binary(40) NOT NULL, `notes` BLOB NOT NULL, UNIQUE KEY `hash` (`hash`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8")) {
        $conf->update_schema_version(65);
    }
    if ($conf->sversion == 65
        && $conf->ql_ok("drop table if exists `RepositoryGrade`")
        && $conf->ql_ok("create table `RepositoryGrade` ( `repoid` int(11) NOT NULL, `pset` int(11) NOT NULL, `gradehash` binary(40), `gradercid` int(11), UNIQUE KEY `repopset` (`repoid`,`pset`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8")) {
        $conf->update_schema_version(66);
    }
    if ($conf->sversion == 66
        && $conf->ql_ok("alter table RepositoryGrade add `hidegrade` tinyint(1) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(67);
    }
    if ($conf->sversion == 67
        && $conf->ql_ok("drop table if exists `ExecutionQueue`")
        && $conf->ql_ok("create table `ExecutionQueue` ( `queueid` int(11) NOT NULL AUTO_INCREMENT, `queueclass` varchar(20) NOT NULL, `repoid` int(11) NOT NULL, `insertat` int(11) NOT NULL, `updateat` int(11) NOT NULL, `runat` int(11) NOT NULL, `status` int(1) NOT NULL, `lockfile` varchar(1024), PRIMARY KEY (`queueid`), KEY `queueclass` (`queueclass`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8")) {
        $conf->update_schema_version(68);
    }
    if ($conf->sversion == 68
        && $conf->ql_ok("alter table Repository add `notes` VARBINARY(32767)")
        && $conf->ql_ok("alter table CommitNotes modify `notes` VARBINARY(32767)")) {
        $conf->update_schema_version(69);
    }
    if ($conf->sversion == 69
        && $conf->ql_ok("alter table CommitNotes add `haslinenotes` tinyint(1) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("update CommitNotes set haslinenotes=1 where notes like '%\"linenotes\":{\"%'")) {
        $conf->update_schema_version(70);
    }
    if ($conf->sversion == 70
        && $conf->ql_ok("alter table ExecutionQueue add `nconcurrent` int(11)")) {
        $conf->update_schema_version(71);
    }
    if ($conf->sversion == 71
        && _update_schema_haslinenotes($conf)) {
        $conf->update_schema_version(72);
    }
    if ($conf->sversion == 72
        && _update_schema_pset_commitnotes($conf)) {
        $conf->update_schema_version(73);
    }
    if ($conf->sversion == 73
        && $conf->ql_ok("drop table if exists `ContactGrade`")
        && $conf->ql_ok("create table `ContactGrade` ( `cid` int(11) NOT NULL, `pset` int(11) NOT NULL, `gradercid` int(11), `notes` varbinary(32767), UNIQUE KEY `cidpset` (`cid`,`pset`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8")) {
        $conf->update_schema_version(74);
    }
    if ($conf->sversion == 74
        && $conf->ql_ok("alter table ContactGrade add `hidegrade` tinyint(1) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(75);
    }
    if ($conf->sversion == 75
        && $conf->ql_ok("alter table Settings modify `data` varbinary(32767) DEFAULT NULL")) {
        $conf->update_schema_version(76);
    }
    if ($conf->sversion == 76
        && $conf->ql_ok("alter table Repository add `otherheads` varbinary(8192) DEFAULT NULL")) {
        $conf->update_schema_version(77);
    }
    if ($conf->sversion == 77
        && $conf->ql_ok("alter table Repository change `otherheads` `heads` varbinary(8192) DEFAULT NULL")) {
        $conf->update_schema_version(78);
    }
    if ($conf->sversion == 78
        && $conf->ql_ok("alter table CommitNotes add `repoid` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table CommitNotes add `nrepo` int(11) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(79);
    }
    if ($conf->sversion == 79
        && $conf->ql_ok("alter table ContactInfo add `passwordTime` int(11) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(80);
    }
    if ($conf->sversion == 80
        && $conf->ql_ok("alter table ExecutionQueue add `autorun` tinyint(1) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(81);
    }
    if ($conf->sversion == 81
        && $conf->ql_ok("alter table ExecutionQueue add `psetid` int(11)")
        && $conf->ql_ok("alter table ExecutionQueue add `runnername` varbinary(128)")) {
        $conf->update_schema_version(82);
    }
    if ($conf->sversion == 82
        && $conf->ql_ok("alter table ExecutionQueue add `hash` binary(40)")) {
        $conf->update_schema_version(83);
    }
    if ($conf->sversion == 83
        && $conf->ql_ok("create table `RepositoryGradeRequest` ( `repoid` int(11) NOT NULL, `pset` int(11) NOT NULL, `hash` binary(40) DEFAULT NULL, `requested_at` int(11) NOT NULL, UNIQUE KEY `repopsethash` (`repoid`,`pset`,`hash`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8")) {
        $conf->update_schema_version(84);
    }
    if ($conf->sversion == 84
        && $conf->ql_ok("drop table if exists Partner")) {
        $conf->update_schema_version(85);
    }
    if ($conf->sversion == 85
        && $conf->ql_ok("drop table if exists Chair")
        && $conf->ql_ok("drop table if exists ChairAssistant")
        && $conf->ql_ok("drop table if exists PCMember")) {
        $conf->update_schema_version(86);
    }
    if ($conf->sversion == 86
        && $conf->ql_ok("alter table RepositoryGrade add `placeholder` tinyint(1) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table RepositoryGrade add `placeholder_at` int(11) DEFAULT NULL")) {
        $conf->update_schema_version(87);
    }
    if ($conf->sversion == 87
        && $conf->ql_ok("alter table `ContactInfo` add `anon_username` varbinary(40) DEFAULT NULL")
        && $conf->ql_ok("alter table `ContactInfo` add unique key `anon_username` (`anon_username`)")) {
        $conf->update_schema_version(88);
    }
    if ($conf->sversion == 88
        && $conf->ql_ok("create table `ContactImage` ( `contactImageId` int(11) NOT NULL AUTO_INCREMENT, `contactId` int(11) NOT NULL, `mimetype` varbinary(128) DEFAULT NULL, `data` varbinary(32768) DEFAULT NULL, PRIMARY KEY (`contactImageId`), KEY `contactId` (`contactId`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8")
        && $conf->ql_ok("alter table `ContactInfo` add `contactImageId` int(11) DEFAULT NULL")) {
        $conf->update_schema_version(89);
    }
    if ($conf->sversion == 89
        && $conf->ql_ok("alter table ContactImage change `data` `data` mediumblob DEFAULT NULL")) {
        $conf->update_schema_version(90);
    }
    if ($conf->sversion == 90
        && $conf->ql_ok("alter table ExecutionQueue add `inputfifo` varchar(1024) DEFAULT NULL")) {
        $conf->update_schema_version(91);
    }
    if ($conf->sversion == 91
        && update_schema_drop_keys_if_exist($conf, "CommitNotes", ["hashpset", "PRIMARY"])
        && $conf->ql_ok("alter table CommitNotes add primary key (`hash`,`pset`)")
        && update_schema_drop_keys_if_exist($conf, "ContactGrade", ["cidpset", "PRIMARY"])
        && $conf->ql_ok("alter table ContactGrade add primary key (`cid`,`pset`)")
        && $conf->ql_ok("alter table ActionLog ENGINE=InnoDB")
        && $conf->ql_ok("alter table Capability ENGINE=InnoDB")
        && $conf->ql_ok("alter table CapabilityMap ENGINE=InnoDB")
        && $conf->ql_ok("alter table CommitNotes ENGINE=InnoDB")
        && $conf->ql_ok("alter table ContactGrade ENGINE=InnoDB")
        && update_schema_drop_keys_if_exist($conf, "ContactInfo", ["name", "affiliation", "email_3", "firstName_2", "lastName"])
        && $conf->ql_ok("alter table ContactInfo ENGINE=InnoDB")
        && $conf->ql_ok("alter table ContactLink ENGINE=InnoDB")
        && $conf->ql_ok("alter table ExecutionQueue ENGINE=InnoDB")
        && $conf->ql_ok("alter table Formula ENGINE=InnoDB")
        && $conf->ql_ok("alter table MailLog ENGINE=InnoDB")
        && $conf->ql_ok("alter table OptionType ENGINE=InnoDB")
        && $conf->ql_ok("alter table PaperConflict ENGINE=InnoDB")
        && $conf->ql_ok("alter table PaperStorage ENGINE=InnoDB")
        && $conf->ql_ok("alter table PsetGrade ENGINE=InnoDB")
        && $conf->ql_ok("alter table Repository ENGINE=InnoDB")
        && $conf->ql_ok("alter table RepositoryGrade ENGINE=InnoDB")
        && $conf->ql_ok("alter table RepositoryGradeRequest ENGINE=InnoDB")
        && $conf->ql_ok("alter table Settings ENGINE=InnoDB")) {
        $conf->update_schema_version(92);
    }
    if ($conf->sversion == 92
        && $conf->ql_ok("alter table ContactInfo add `github_username` varbinary(120) DEFAULT NULL")) {
        $conf->update_schema_version(93);
    }
    if ($conf->sversion == 93
        && $conf->ql_ok("alter table ContactInfo add `passwordUseTime` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table ContactInfo add `updateTime` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("update ContactInfo set passwordUseTime=lastLogin where passwordUseTime=0")) {
        $conf->update_schema_version(94);
    }
    if ($conf->sversion == 94
        && $conf->ql_ok("alter table ContactInfo add `data` varbinary(32767) DEFAULT NULL")
        && $conf->ql_ok("alter table ContactInfo add `studentYear` varbinary(4) DEFAULT NULL")) {
        $conf->update_schema_version(95);
    }
    if ($conf->sversion == 95
        && $conf->ql_ok("create table `RepositoryCommitSnapshot` ( `repoid` int(11) NOT NULL, `hash` binary(40) NOT NULL, `snapshot` bigint(11) NOT NULL, PRIMARY KEY (`repoid`,`hash`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8")) {
        $conf->update_schema_version(96);
    }
    if ($conf->sversion == 96
        && $conf->ql_ok("alter table Repository add `analyzedsnapat` bigint(11) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(97);
    }
    if ($conf->sversion == 97
        && $conf->ql_ok("alter table RepositoryCommitSnapshot change `hash` `hash` varbinary(32) NOT NULL")) {
        $conf->update_schema_version(98);
    }
    if ($conf->sversion == 98
        && $conf->ql_ok("alter table Capability change `capabilityId` `capabilityId` int(11) NOT NULL")
        && update_schema_drop_keys_if_exist($conf, "Capability", ["capabilityId", "PRIMARY"])
        && $conf->ql_ok("alter table Capability add primary key (`salt`)")
        && $conf->ql_ok("alter table Capability drop column `capabilityId`")) {
        $conf->update_schema_version(99);
    }
    if ($conf->sversion == 99
        && $conf->ql_ok("drop table if exists `CapabilityMap`")) {
        $conf->update_schema_version(100);
    }
    if ($conf->sversion == 100
        && $conf->ql_ok("alter table ContactImage add unique key `contactImageId` (`contactImageId`)")
        && update_schema_drop_keys_if_exist($conf, "ContactImage", ["contactId", "PRIMARY"])
        && $conf->ql_ok("alter table ContactImage add primary key (`contactId`,`contactImageId`)")) {
        $conf->update_schema_version(101);
    }
    if ($conf->sversion == 101
        && $conf->ql_ok("drop table if exists `PaperStorage`")
        && $conf->ql_ok("drop table if exists `PsetGrade`")) {
        $conf->update_schema_version(102);
    }
    if ($conf->sversion == 102
        && update_schema_drop_keys_if_exist($conf, "RepositoryGrade", ["repopset"])
        && $conf->ql_ok("alter table RepositoryGrade add primary key (`repoid`,`pset`)")) {
        $conf->update_schema_version(103);
    }
    if ($conf->sversion == 103
        && $conf->ql_ok("alter table Settings change `name` `name` varbinary(256) NOT NULL")
        && update_schema_drop_keys_if_exist($conf, "Settings", ["name"])
        && $conf->ql_ok("alter table Settings add primary key (`name`)")) {
        $conf->update_schema_version(104);
    }
    if ($conf->sversion == 104
        && $conf->ql_ok("alter table CommitNotes drop `nrepo`")) {
        $conf->update_schema_version(105);
    }
    if ($conf->sversion == 105
        && $conf->ql_ok("alter table ContactGrade add `notesversion` int(11) NOT NULL DEFAULT 1")
        && $conf->ql_ok("alter table CommitNotes add `notesversion` int(11) NOT NULL DEFAULT 1")) {
        $conf->update_schema_version(106);
    }
    if ($conf->sversion == 106
        && $conf->ql_ok("alter table ContactGrade add `hasactiveflags` tinyint(1) NOT NULL DEFAULT 0")
        && $conf->ql_ok("alter table CommitNotes add `hasactiveflags` tinyint(1) NOT NULL DEFAULT 0")) {
        $conf->update_schema_version(107);
    }
    if ($conf->sversion == 107
        && $conf->psets()
        && _update_schema_regrade_flags($conf)) {
        $conf->update_schema_version(108);
    }
    if ($conf->sversion == 108
        && _update_schema_hasflags($conf)) {
        $conf->update_schema_version(109);
    }
    if ($conf->sversion == 109
        && _update_schema_linenotes($conf)) {
        $conf->update_schema_version(110);
    }
    if ($conf->sversion == 110
        && $conf->ql_ok("alter table CommitInfo ENGINE=InnoDB")) {
        $conf->update_schema_version(111);
    }
    if ($conf->sversion == 111
        && $conf->ql_ok("drop table if exists `Formula`")
        && $conf->ql_ok("drop table if exists `OptionType`")
        && $conf->ql_ok("drop table if exists `PaperConflict`")) {
        $conf->update_schema_version(112);
    }
    if ($conf->sversion == 112
        && $conf->ql_ok("alter table ContactLink add `data` varbinary(8192) DEFAULT NULL")) {
        $conf->update_schema_version(113);
    }
    if ($conf->sversion == 113
        && $conf->ql_ok("alter table ContactInfo add `nickname` varchar(60) DEFAULT NULL")) {
        $conf->update_schema_version(114);
    }
    if ($conf->sversion == 114
        && $conf->ql_ok("alter table CommitInfo change `sha1` `bhash` varbinary(32) NOT NULL")
        && $conf->ql_ok("alter table CommitNotes add `bhash` varbinary(32) DEFAULT NULL")
        && $conf->ql_ok("update CommitNotes set bhash=unhex(hash)")
        && $conf->ql_ok("alter table CommitNotes change `bhash` `bhash` varbinary(32) NOT NULL")) {
        $conf->update_schema_version(115);
    }
    if ($conf->sversion == 115
        && $conf->ql_ok("drop table if exists CommitInfo")
        && $conf->ql_ok("alter table RepositoryCommitSnapshot change `hash` `bhash` varbinary(32) NOT NULL")) {
        $conf->update_schema_version(116);
    }
    if ($conf->sversion == 116
        && update_schema_drop_keys_if_exist($conf, "CommitNotes", ["PRIMARY"])
        && $conf->ql_ok("alter table CommitNotes add primary key (`pset`,`bhash`)")
        && $conf->ql_ok("alter table CommitNotes drop `hash`")) {
        $conf->update_schema_version(117);
    }
    if ($conf->sversion == 117
        && $conf->ql_ok("alter table Capability change `timeExpires` `timeExpires` bigint(11) NOT NULL")
        && $conf->ql_ok("alter table ContactInfo change `creationTime` `creationtime` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table ContactInfo change `lastLogin` `lastLogin` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table ContactInfo change `passwordTime` `passwordTime` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table ContactInfo change `passwordUseTime` `passwordUseTime` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table ContactInfo change `updateTime` `updateTime` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table ExecutionQueue change `insertat` `insertat` bigint(11) NOT NULL")
        && $conf->ql_ok("alter table ExecutionQueue change `updateat` `updateat` bigint(11) NOT NULL")
        && $conf->ql_ok("alter table ExecutionQueue change `runat` `runat` bigint(11) NOT NULL")
        && $conf->ql_ok("alter table Repository change `opencheckat` `opencheckat` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table Repository change `snapat` `snapat` bigint(11) DEFAULT NULL")
        && $conf->ql_ok("alter table Repository change `snapcheckat` `snapcheckat` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table Repository change `snapcommitat` `snapcommitat` bigint(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table RepositoryGrade change `placeholder_at` `placeholder_at` bigint(11) DEFAULT NULL")
        && $conf->ql_ok("alter table RepositoryGradeRequest change `requested_at` `requested_at` bigint(11) NOT NULL")) {
        $conf->update_schema_version(118);
    }
    if ($conf->sversion == 118
        && $conf->ql_ok("alter table ExecutionQueue change `hash` `bhash` varbinary(32) NOT NULL")) {
        $conf->update_schema_version(119);
    }
    if ($conf->sversion == 119
        && $conf->ql_ok("alter table ContactInfo change `creationtime` `creationTime` bigint(11) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(120);
    }
    if ($conf->sversion == 120
        && $conf->ql_ok("alter table ActionLog drop key `logId`")
        && $conf->ql_ok("alter table ContactInfo drop key `contactId`")
        && $conf->ql_ok("alter table ContactInfo drop key `contactIdRoles`")
        && $conf->ql_ok("alter table ContactInfo add key `roles` (`roles`)")
        && $conf->ql_ok("alter table ContactInfo drop key `fullName`")
        && $conf->ql_ok("alter table Repository drop key `repoid`")) {
        $conf->update_schema_version(121);
    }
    if ($conf->sversion == 121
        && $conf->ql_ok("drop table if exists RepositoryGradeRequest")) {
        $conf->update_schema_version(122);
    }
    if ($conf->sversion == 122
        && $conf->ql_ok("drop table if exists Branch")
        && $conf->ql_ok("drop table if exists ContactUlink")
        && $conf->ql_ok("create table `Branch` ( `branchid` int(11) NOT NULL AUTO_INCREMENT, `branch` varbinary(255) NOT NULL, PRIMARY KEY (`branchid`), UNIQUE KEY `branch` (`branch`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8")
        && $conf->ql_ok("alter table RepositoryGrade add `branchid` int(11) NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table RepositoryGrade drop primary key")
        && $conf->ql_ok("alter table RepositoryGrade add primary key (`repoid`,`branchid`,`pset`)")) {
        $conf->update_schema_version(123);
    }
    if ($conf->sversion == 123
        && update_schema_branches($conf)) {
        $conf->update_schema_version(124);
    }
    if ($conf->sversion == 124
        && $conf->ql_ok("alter table ContactLink drop `data`")) {
        $conf->update_schema_version(125);
    }
    if ($conf->sversion == 125
        && $conf->ql_ok("alter table ContactLink add primary key (`cid`,`type`,`pset`,`link`)")
        && $conf->ql_ok("alter table ContactLink drop key `cid_type`")) {
        $conf->update_schema_version(126);
    }
    if ($conf->sversion == 126
        && $conf->ql_ok("alter table RepositoryGrade add `gradebhash` varbinary(32) DEFAULT NULL")
        && $conf->ql_ok("update RepositoryGrade set gradebhash=unhex(gradehash)")) {
        $conf->update_schema_version(127);
    }
    if ($conf->sversion == 127
        && $conf->ql_ok("alter table RepositoryGrade drop `gradehash`")
        && update_schema_branched_repo_grade($conf)) {
        $conf->update_schema_version(128);
    }
    if ($conf->sversion == 128
        && $conf->ql_ok("alter table Repository drop `lastpset`")) {
        $conf->update_schema_version(129);
    }
    if ($conf->sversion == 129
        && $conf->ql_ok("drop table if exists GroupSettings")
        && $conf->ql_ok("create table GroupSettings ( `name` varbinary(256) NOT NULL, `value` int(11) NOT NULL, `data` varbinary(32767) DEFAULT NULL, `dataOverflow` longblob, PRIMARY KEY (`name`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8")) {
        $conf->update_schema_version(130);
    }
    if ($conf->sversion == 130
        && $conf->ql_ok("delete from Settings where name like 'gradejson_%'")) {
        $conf->update_schema_version(131);
    }
    if ($conf->sversion == 131
        && $conf->ql_ok("alter table ContactInfo add `gradeUpdateTime` bigint(11) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(132);
    }
    if ($conf->sversion === 132
        && $conf->ql_ok("alter table RepositoryGrade add `commitat` bigint(11) DEFAULT NULL")) {
        $conf->update_schema_version(135);
    }
    if ($conf->sversion === 135
        && $conf->ql_ok("alter table Repository add `infosnapat` bigint(11) NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(136);
    }
    if ($conf->sversion === 136
        && $conf->ql_ok("alter table RepositoryGrade add `rpnotes` varbinary(16384) DEFAULT NULL")
        && $conf->ql_ok("alter table RepositoryGrade add `rpnotesversion` int(11) NOT NULL DEFAULT '1'")) {
        $conf->update_schema_version(137);
    }
    if ($conf->sversion === 137
        && update_schema_known_branches($conf)) {
        $conf->update_schema_version(138);
    }
    if ($conf->sversion === 138
        && $conf->psets()
        && update_schema_known_branch_links($conf)) {
        $conf->update_schema_version(139);
    }
    if ($conf->sversion === 139
        && $conf->ql_ok("alter table RepositoryGrade add `emptydiff_at` bigint(11) DEFAULT NULL")) {
        $conf->update_schema_version(140);
    }
    if ($conf->sversion === 140
        && $conf->ql_ok("drop table if exists `ContactGradeHistory`")
        && $conf->ql_ok("create table `ContactGradeHistory` (
            `cid` int NOT NULL,
            `pset` int NOT NULL,
            `notesversion` int NOT NULL,
            `updateat` bigint NOT NULL,
            `notes` varbinary(32767) DEFAULT NULL,
            PRIMARY KEY (`cid`,`pset`,`notesversion`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8")
        && $conf->ql_ok("alter table `ContactGrade` add `updateat` bigint DEFAULT NULL")) {
        $conf->update_schema_version(141);
    }
    if ($conf->sversion === 141
        && $conf->ql_ok("alter table `ContactGrade` add `updateby` int DEFAULT NULL")
        && $conf->ql_ok("alter table `ContactGradeHistory` add `updateby` int NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(142);
    }
    if ($conf->sversion === 142
        && $conf->ql_ok("alter table `ContactGrade` add `notesOverflow` longblob DEFAULT NULL")
        && $conf->ql_ok("alter table `CommitNotes` add `notesOverflow` longblob DEFAULT NULL")
        && $conf->ql_ok("alter table `ContactGradeHistory` add `notesOverflow` longblob DEFAULT NULL")) {
        $conf->update_schema_version(143);
    }
    if ($conf->sversion === 143
        && $conf->ql_ok("alter table `CommitNotes` add `commitat` bigint DEFAULT NULL")) {
        $conf->update_schema_version(144);
    }
    if ($conf->sversion === 144
        && $conf->ql_ok("alter table `ContactGrade` add `studentupdateat` bigint DEFAULT NULL")
        && $conf->ql_ok("alter table `ContactGradeHistory` add `studentupdateat` bigint DEFAULT NULL")
        && update_schema_studentupdateat($conf)) {
        $conf->update_schema_version(145);
    }
    if ($conf->sversion === 145
        && $conf->ql_ok("alter table `ContactGrade` add `xnotes` varbinary(1024) DEFAULT NULL")
        && $conf->ql_ok("alter table `RepositoryGrade` add `rpxnotes` varbinary(1024) DEFAULT NULL")
        && $conf->ql_ok("alter table `CommitNotes` add `xnotes` varbinary(1024) DEFAULT NULL")
        && $conf->ql_ok("alter table `ContactGrade` add `xnotesOverflow` longblob DEFAULT NULL")
        && $conf->ql_ok("alter table `RepositoryGrade` add `rpnotesOverflow` longblob DEFAULT NULL")
        && $conf->ql_ok("alter table `RepositoryGrade` add `rpxnotesOverflow` longblob DEFAULT NULL")
        && $conf->ql_ok("alter table `CommitNotes` add `xnotesOverflow` longblob DEFAULT NULL")) {
        $conf->update_schema_version(146);
    }
    if ($conf->sversion === 146
        && $conf->ql_ok("alter table `ExecutionQueue` change `bhash` `bhash` varbinary(32) DEFAULT NULL")
        && $conf->ql_ok("alter table `ExecutionQueue` add `reqcid` int NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table `ExecutionQueue` add `cid` int NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table `ExecutionQueue` change `reqcid` `reqcid` int NOT NULL")
        && $conf->ql_ok("alter table `ExecutionQueue` change `cid` `cid` int")) {
        $conf->update_schema_version(147);
    }
    if ($conf->sversion === 147
        && $conf->ql_ok("alter table `ExecutionQueue` change `runnername` `runnername` varbinary(128) NOT NULL")) {
        $conf->update_schema_version(148);
    }
    if ($conf->sversion === 148
        && $conf->ql_ok("alter table `ExecutionQueue` change `repoid` `repoid` int DEFAULT NULL")) {
        $conf->update_schema_version(149);
    }
    if ($conf->sversion === 149
        && $conf->ql_ok("alter table `ExecutionQueue` change `psetid` `psetid` int NOT NULL")) {
        $conf->update_schema_version(150);
    }
    if ($conf->sversion === 150
        && $conf->ql_ok("alter table `Repository` change `url` `url` varbinary(512) NOT NULL")
        && $conf->ql_ok("alter table `ActionLog` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `Branch` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `Capability` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `CommitNotes` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `ContactGrade` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `ContactGradeHistory` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `ContactImage` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `ContactInfo` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `ContactLink` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `ExecutionQueue` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `GroupSettings` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `MailLog` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `Repository` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `RepositoryCommitSnapshot` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `RepositoryGrade` convert to character set utf8mb4")
        && $conf->ql_ok("alter table `Settings` convert to character set utf8mb4")) {
        $conf->update_schema_version(151);
    }
    if ($conf->sversion === 151
        && $conf->ql_ok("update ExecutionQueue set cid=0 where cid is null")
        && $conf->ql_ok("alter table `ExecutionQueue` change `cid` `cid` int NOT NULL")) {
        $conf->update_schema_version(152);
    }
    if ($conf->sversion === 152
        && $conf->ql_ok("alter table `ExecutionQueue` add `runsettings` varbinary(8192) DEFAULT NULL")) {
        $conf->update_schema_version(153);
    }
    if ($conf->sversion === 153
        && $conf->ql_ok("alter table `ExecutionQueue` drop `inputfifo`")
        && $conf->ql_ok("alter table `ExecutionQueue` add `runorder` bigint NOT NULL DEFAULT '0'")
        && $conf->ql_ok("alter table `ExecutionQueue` add `chain` bigint DEFAULT NULL")
        && $conf->ql_ok("alter table `ExecutionQueue` drop `autorun`")
        && $conf->ql_ok("alter table `ExecutionQueue` add `flags` int NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(154);
    }
    if ($conf->sversion === 154
        && $conf->ql_ok("alter table `ContactInfo` add `last_runorder` bigint NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(155);
    }
    if ($conf->sversion === 155
        && $conf->ql_ok("update ExecutionQueue set runorder=? where status<0", Conf::$now + 1000000000)
        && $conf->ql_ok("alter table `ExecutionQueue` add key `runorder` (`runorder`)")) {
        $conf->update_schema_version(156);
    }
    if ($conf->sversion === 156
        && $conf->ql_ok("alter table ExecutionQueue add `tags` varbinary(4096) DEFAULT NULL")) {
        $conf->update_schema_version(157);
    }
    if ($conf->sversion === 157
        && $conf->ql_ok("alter table `ExecutionQueue` add `scheduleat` bigint NOT NULL DEFAULT '0'")) {
        $conf->update_schema_version(158);
    }
    if ($conf->sversion === 158
        && $conf->ql_ok("alter table `ContactInfo` drop key `seascode_username`")
        && $conf->ql_ok("alter table `ContactInfo` drop `seascode_username`")) {
        $conf->update_schema_version(159);
    }

    $conf->ql_ok("delete from Settings where name='__schema_lock'");
}
