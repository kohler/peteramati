<?php
// updateschema.php -- HotCRP function for updating old schemata
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function _update_schema_haslinenotes($Conf) {
    $Conf->ql("lock tables CommitNotes write");
    $hashes = array(array(), array(), array(), array());
    $result = $Conf->ql("select hash, notes from CommitNotes");
    while (($row = edb_row($result))) {
        $x = Contact::commit_info_haslinenotes(json_decode($row[1]));
        $hashes[$x][] = $row[0];
    }
    foreach ($hashes as $x => $h)
        if (count($h))
            $Conf->ql("update CommitNotes set haslinenotes=$x where hash in ('" . join("','", $h) . "')");
    $Conf->ql("unlock tables");
    return true;
}

function _update_schema_pset_commitnotes($Conf) {
    return $Conf->ql("drop table if exists CommitInfo")
        && $Conf->ql("alter table CommitNotes add `pset` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table CommitNotes drop key `hash`")
        && $Conf->ql("alter table CommitNotes add unique key `hashpset` (`hash`,`pset`)")
        && $Conf->ql("update CommitNotes, RepositoryGrade set CommitNotes.pset=RepositoryGrade.pset where CommitNotes.hash=RepositoryGrade.gradehash");
}

function update_schema_version($Conf, $n) {
    if ($Conf->ql("update Settings set value=$n where name='allowPaperOption'")) {
        $Conf->settings["allowPaperOption"] = $n;
        return true;
    } else
        return false;
}

function updateSchema($Conf) {
    global $Opt, $OK;
    error_log("Note: updating schema from version " . $Conf->settings["allowPaperOption"]);

    if ($Conf->settings["allowPaperOption"] == 58
	&& $Conf->ql("alter table ContactInfo add `college` tinyint(1) NOT NULL DEFAULT '0'")
	&& $Conf->ql("alter table ContactInfo add `extension` tinyint(1) NOT NULL DEFAULT '0'"))
	update_schema_version($Conf, 59);
    if ($Conf->settings["allowPaperOption"] == 59
        && $Conf->ql("alter table Repository add `lastpset` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("update Repository join ContactLink on (ContactLink.type=" . LINK_REPO . " and ContactLink.link=Repository.repoid) set Repository.lastpset=greatest(Repository.lastpset,ContactLink.pset)"))
	update_schema_version($Conf, 60);
    if ($Conf->settings["allowPaperOption"] == 60
        && $Conf->ql("alter table Repository add `working` int(1) NOT NULL DEFAULT '1'"))
        update_schema_version($Conf, 61);
    if ($Conf->settings["allowPaperOption"] == 61
        && $Conf->ql("alter table ContactInfo add `dropped` tinyint(1) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 62);
    if ($Conf->settings["allowPaperOption"] == 62
        && $Conf->ql("alter table ContactInfo drop column `dropped`")
        && $Conf->ql("alter table ContactInfo add `dropped` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 63);
    if ($Conf->settings["allowPaperOption"] == 63
        && $Conf->ql("alter table Repository add `snapcommitat` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table Repository add `snapcommitline` varchar(100)"))
        update_schema_version($Conf, 64);
    if ($Conf->settings["allowPaperOption"] == 64
        && $Conf->ql("drop table if exists `CommitNotes`")
        && $Conf->ql("alter table `Repository` modify `snaphash` binary(40)")
        && $Conf->ql("create table `CommitNotes` ( `hash` binary(40) NOT NULL, `notes` BLOB NOT NULL, UNIQUE KEY `hash` (`hash`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 65);
    if ($Conf->settings["allowPaperOption"] == 65
        && $Conf->ql("drop table if exists `RepositoryGrade`")
        && $Conf->ql("create table `RepositoryGrade` ( `repoid` int(11) NOT NULL, `pset` int(11) NOT NULL, `gradehash` binary(40), `gradercid` int(11), UNIQUE KEY `repopset` (`repoid`,`pset`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 66);
    if ($Conf->settings["allowPaperOption"] == 66
        && $Conf->ql("alter table RepositoryGrade add `hidegrade` tinyint(1) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 67);
    if ($Conf->settings["allowPaperOption"] == 67
        && $Conf->ql("drop table if exists `ExecutionQueue`")
        && $Conf->ql("create table `ExecutionQueue` ( `queueid` int(11) NOT NULL AUTO_INCREMENT, `queueclass` varchar(20) NOT NULL, `repoid` int(11) NOT NULL, `insertat` int(11) NOT NULL, `updateat` int(11) NOT NULL, `runat` int(11) NOT NULL, `status` int(1) NOT NULL, `lockfile` varchar(1024), PRIMARY KEY (`queueid`), KEY `queueclass` (`queueclass`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 68);
    if ($Conf->settings["allowPaperOption"] == 68
        && $Conf->ql("alter table Repository add `notes` VARBINARY(32767)")
        && $Conf->ql("alter table CommitNotes modify `notes` VARBINARY(32767)"))
        update_schema_version($Conf, 69);
    if ($Conf->settings["allowPaperOption"] == 69
        && $Conf->ql("alter table CommitNotes add `haslinenotes` tinyint(1) NOT NULL DEFAULT '0'")
        && $Conf->ql("update CommitNotes set haslinenotes=1 where notes like '%\"linenotes\":{\"%'"))
        update_schema_version($Conf, 70);
    if ($Conf->settings["allowPaperOption"] == 70
        && $Conf->ql("alter table ExecutionQueue add `nconcurrent` int(11)"))
        update_schema_version($Conf, 71);
    if ($Conf->settings["allowPaperOption"] == 71
        && _update_schema_haslinenotes($Conf))
        update_schema_version($Conf, 72);
    if ($Conf->settings["allowPaperOption"] == 72
        && _update_schema_pset_commitnotes($Conf))
        update_schema_version($Conf, 73);
    if ($Conf->settings["allowPaperOption"] == 73
        && $Conf->ql("drop table if exists `ContactGrade`")
        && $Conf->ql("create table `ContactGrade` ( `cid` int(11) NOT NULL, `pset` int(11) NOT NULL, `gradercid` int(11), `notes` varbinary(32767), UNIQUE KEY `cidpset` (`cid`,`pset`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 74);
    if ($Conf->settings["allowPaperOption"] == 74
        && $Conf->ql("alter table ContactGrade add `hidegrade` tinyint(1) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 75);
    if ($Conf->settings["allowPaperOption"] == 75
        && $Conf->ql("alter table Settings modify `data` varbinary(32767) DEFAULT NULL"))
        update_schema_version($Conf, 76);
    if ($Conf->settings["allowPaperOption"] == 76
        && $Conf->ql("alter table Repository add `otherheads` varbinary(8192) DEFAULT NULL"))
        update_schema_version($Conf, 77);
    if ($Conf->settings["allowPaperOption"] == 77
        && $Conf->ql("alter table Repository change `otherheads` `heads` varbinary(8192) DEFAULT NULL"))
        update_schema_version($Conf, 78);
    if ($Conf->settings["allowPaperOption"] == 78
        && $Conf->ql("alter table CommitNotes add `repoid` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table CommitNotes add `nrepo` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 79);
    if ($Conf->settings["allowPaperOption"] == 79
        && $Conf->ql("alter table ContactInfo add `passwordTime` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 80);
    if ($Conf->settings["allowPaperOption"] == 80
        && $Conf->ql("alter table ExecutionQueue add `autorun` tinyint(1) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 81);
    if ($Conf->settings["allowPaperOption"] == 81
        && $Conf->ql("alter table ExecutionQueue add `psetid` int(11)")
        && $Conf->ql("alter table ExecutionQueue add `runnername` varbinary(128)"))
        update_schema_version($Conf, 82);
    if ($Conf->settings["allowPaperOption"] == 82
        && $Conf->ql("alter table ExecutionQueue add `hash` binary(40)"))
        update_schema_version($Conf, 83);
    if ($Conf->settings["allowPaperOption"] == 83
        && $Conf->ql("create table `RepositoryGradeRequest` ( `repoid` int(11) NOT NULL, `pset` int(11) NOT NULL, `hash` binary(40) DEFAULT NULL, `requested_at` int(11) NOT NULL, UNIQUE KEY `repopsethash` (`repoid`,`pset`,`hash`) ) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 84);
    if ($Conf->settings["allowPaperOption"] == 84
        && $Conf->ql("drop table if exists Partner"))
        update_schema_version($Conf, 85);
    if ($Conf->settings["allowPaperOption"] == 85
        && $Conf->ql("drop table if exists Chair")
        && $Conf->ql("drop table if exists ChairAssistant")
        && $Conf->ql("drop table if exists PCMember"))
        update_schema_version($Conf, 86);
    if ($Conf->settings["allowPaperOption"] == 86
        && $Conf->ql("alter table RepositoryGrade add `placeholder` tinyint(1) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table RepositoryGrade add `placeholder_at` int(11) DEFAULT NULL"))
        update_schema_version($Conf, 87);
    if ($Conf->settings["allowPaperOption"] == 87
        && $Conf->ql("alter table `ContactInfo` add `anon_username` varbinary(40) DEFAULT NULL"))
        && $Conf->ql("alter table `ContactInfo` add unique key `anon_username` (`anon_username`)")
        update_schema_version($Conf, 88);
}
