--
-- Table structure for table `ActionLog`
--

DROP TABLE IF EXISTS `ActionLog`;
CREATE TABLE `ActionLog` (
  `logId` int(11) NOT NULL AUTO_INCREMENT,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) DEFAULT NULL,
  `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ipaddr` varchar(16) DEFAULT NULL,
  `action` text NOT NULL,
  PRIMARY KEY (`logId`),
  KEY `contactId` (`contactId`),
  KEY `paperId` (`paperId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `Branch`
--

DROP TABLE IF EXISTS `Branch`;
CREATE TABLE `Branch` (
  `branchid` int(11) NOT NULL AUTO_INCREMENT,
  `branch` varbinary(255) NOT NULL,
  PRIMARY KEY (`branchid`),
  UNIQUE KEY `branch` (`branch`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `Capability`
--

DROP TABLE IF EXISTS `Capability`;
CREATE TABLE `Capability` (
  `capabilityType` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `timeExpires` bigint(11) NOT NULL,
  `salt` varbinary(255) NOT NULL,
  `data` blob DEFAULT NULL,
  PRIMARY KEY (`salt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `CommitNotes`
--

DROP TABLE IF EXISTS `CommitNotes`;
CREATE TABLE `CommitNotes` (
  `pset` int(11) NOT NULL DEFAULT 0,
  `bhash` varbinary(32) NOT NULL,
  `notes` varbinary(32767) DEFAULT NULL,
  `haslinenotes` tinyint(1) NOT NULL DEFAULT 0,
  `repoid` int(11) NOT NULL DEFAULT 0,
  `notesversion` int(11) NOT NULL DEFAULT 1,
  `hasactiveflags` tinyint(1) NOT NULL DEFAULT 0,
  `hasflags` tinyint(1) NOT NULL DEFAULT 0,
  `notesOverflow` longblob DEFAULT NULL,
  `commitat` bigint(20) DEFAULT NULL,
  `xnotes` varbinary(1024) DEFAULT NULL,
  `xnotesOverflow` longblob DEFAULT NULL,
  `updateat` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pset`,`bhash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `ContactGrade`
--

DROP TABLE IF EXISTS `ContactGrade`;
CREATE TABLE `ContactGrade` (
  `cid` int(11) NOT NULL,
  `pset` int(11) NOT NULL,
  `gradercid` int(11) DEFAULT NULL,
  `notes` varbinary(32767) DEFAULT NULL,
  `hidegrade` tinyint(1) NOT NULL DEFAULT 0,
  `notesversion` int(11) NOT NULL DEFAULT 1,
  `hasactiveflags` tinyint(1) NOT NULL DEFAULT 0,
  `updateat` bigint(20) DEFAULT NULL,
  `updateby` int(11) DEFAULT NULL,
  `notesOverflow` longblob DEFAULT NULL,
  `studentupdateat` bigint(20) DEFAULT NULL,
  `xnotes` varbinary(1024) DEFAULT NULL,
  `xnotesOverflow` longblob DEFAULT NULL,
  `pinsnv` int(11) DEFAULT NULL,
  PRIMARY KEY (`cid`,`pset`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `ContactGradeHistory`
--

DROP TABLE IF EXISTS `ContactGradeHistory`;
CREATE TABLE `ContactGradeHistory` (
  `cid` int(11) NOT NULL,
  `pset` int(11) NOT NULL,
  `notesversion` int(11) NOT NULL,
  `updateat` bigint(20) NOT NULL,
  `antiupdate` varbinary(32767) DEFAULT NULL,
  `updateby` int(11) NOT NULL DEFAULT 0,
  `antiupdateOverflow` longblob DEFAULT NULL,
  `studentupdateat` bigint(20) DEFAULT NULL,
  `antiupdateby` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`cid`,`pset`,`notesversion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `ContactImage`
--

DROP TABLE IF EXISTS `ContactImage`;
CREATE TABLE `ContactImage` (
  `contactId` int(11) NOT NULL,
  `contactImageId` int(11) NOT NULL AUTO_INCREMENT,
  `mimetype` varbinary(128) DEFAULT NULL,
  `data` mediumblob DEFAULT NULL,
  PRIMARY KEY (`contactId`,`contactImageId`),
  UNIQUE KEY `contactImageId` (`contactImageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `ContactInfo`
--

DROP TABLE IF EXISTS `ContactInfo`;
CREATE TABLE `ContactInfo` (
  `contactId` int(11) NOT NULL AUTO_INCREMENT,
  `visits` int(11) NOT NULL DEFAULT 0,
  `firstName` varchar(60) NOT NULL DEFAULT '',
  `lastName` varchar(60) NOT NULL DEFAULT '',
  `email` varchar(120) NOT NULL,
  `huid` varchar(10) DEFAULT NULL,
  `preferredEmail` varchar(120) DEFAULT NULL,
  `affiliation` varchar(2048) NOT NULL DEFAULT '',
  `password` varbinary(2048) NOT NULL,
  `note` longtext DEFAULT NULL,
  `creationTime` bigint(11) NOT NULL DEFAULT 0,
  `lastLogin` bigint(11) NOT NULL DEFAULT 0,
  `defaultWatch` int(11) NOT NULL DEFAULT 2,
  `roles` tinyint(1) NOT NULL DEFAULT 0,
  `disabled` tinyint(1) NOT NULL DEFAULT 0,
  `contactTags` text DEFAULT NULL,
  `college` tinyint(1) NOT NULL DEFAULT 0,
  `extension` tinyint(1) NOT NULL DEFAULT 0,
  `dropped` int(11) NOT NULL DEFAULT 0,
  `passwordTime` bigint(11) NOT NULL DEFAULT 0,
  `anon_username` varbinary(40) DEFAULT NULL,
  `contactImageId` int(11) DEFAULT NULL,
  `github_username` varbinary(120) DEFAULT NULL,
  `passwordUseTime` bigint(11) NOT NULL DEFAULT 0,
  `updateTime` bigint(11) NOT NULL DEFAULT 0,
  `data` varbinary(32767) DEFAULT NULL,
  `studentYear` varbinary(4) DEFAULT NULL,
  `nickname` varchar(60) DEFAULT NULL,
  `gradeUpdateTime` bigint(11) NOT NULL DEFAULT 0,
  `last_runorder` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`contactId`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `anon_username` (`anon_username`),
  KEY `roles` (`roles`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `ContactLink`
--

DROP TABLE IF EXISTS `ContactLink`;
CREATE TABLE `ContactLink` (
  `cid` int(11) NOT NULL,
  `type` int(1) NOT NULL,
  `pset` int(1) NOT NULL DEFAULT 0,
  `link` int(11) NOT NULL,
  PRIMARY KEY (`cid`,`type`,`pset`,`link`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `ExecutionQueue`
--

DROP TABLE IF EXISTS `ExecutionQueue`;
CREATE TABLE `ExecutionQueue` (
  `queueid` int(11) NOT NULL AUTO_INCREMENT,
  `reqcid` int(11) NOT NULL,
  `runnername` varbinary(128) NOT NULL,
  `cid` int(11) NOT NULL,
  `repoid` int(11) NOT NULL,
  `psetid` int(11) NOT NULL,
  `bhash` varbinary(32) DEFAULT NULL,
  `queueclass` varchar(20) NOT NULL,
  `nconcurrent` int(11) DEFAULT NULL,
  `insertat` bigint(11) NOT NULL,
  `updateat` bigint(11) NOT NULL,
  `runat` bigint(11) NOT NULL,
  `status` int(1) NOT NULL,
  `lockfile` varbinary(1024) DEFAULT NULL,
  `runsettings` varbinary(8192) DEFAULT NULL,
  `runorder` bigint(20) NOT NULL DEFAULT 0,
  `chain` bigint(20) DEFAULT NULL,
  `flags` int(11) NOT NULL DEFAULT 0,
  `tags` varbinary(4096) DEFAULT NULL,
  `scheduleat` bigint(20) NOT NULL DEFAULT 0,
  `ensure` varbinary(8192) DEFAULT NULL,
  `eventsource` varbinary(1024) DEFAULT NULL,
  `ifneeded` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`queueid`),
  KEY `queueclass` (`queueclass`),
  KEY `runorder` (`runorder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `GroupSettings`
--

DROP TABLE IF EXISTS `GroupSettings`;
CREATE TABLE `GroupSettings` (
  `name` varbinary(256) NOT NULL,
  `value` int(11) NOT NULL,
  `data` varbinary(32767) DEFAULT NULL,
  `dataOverflow` longblob DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `MailLog`
--

DROP TABLE IF EXISTS `MailLog`;
CREATE TABLE `MailLog` (
  `mailId` int(11) NOT NULL AUTO_INCREMENT,
  `recipients` varchar(200) NOT NULL,
  `paperIds` text DEFAULT NULL,
  `cc` text DEFAULT NULL,
  `replyto` text DEFAULT NULL,
  `subject` text DEFAULT NULL,
  `emailBody` text DEFAULT NULL,
  PRIMARY KEY (`mailId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `Repository`
--

DROP TABLE IF EXISTS `Repository`;
CREATE TABLE `Repository` (
  `repoid` int(11) NOT NULL AUTO_INCREMENT,
  `url` varbinary(512) NOT NULL,
  `cacheid` varchar(20) NOT NULL,
  `open` int(11) NOT NULL,
  `opencheckat` bigint(11) NOT NULL DEFAULT 0,
  `snaphash` binary(40) DEFAULT NULL,
  `snapat` bigint(11) DEFAULT NULL,
  `snapcheckat` bigint(11) NOT NULL DEFAULT 0,
  `working` int(1) NOT NULL DEFAULT 1,
  `snapcommitat` bigint(11) NOT NULL DEFAULT 0,
  `snapcommitline` varchar(100) DEFAULT NULL,
  `notes` varbinary(32767) DEFAULT NULL,
  `heads` varbinary(8192) DEFAULT NULL,
  `infosnapat` bigint(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`repoid`),
  UNIQUE KEY `url` (`url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `RepositoryGrade`
--

DROP TABLE IF EXISTS `RepositoryGrade`;
CREATE TABLE `RepositoryGrade` (
  `repoid` int(11) NOT NULL,
  `branchid` int(11) NOT NULL DEFAULT 0,
  `pset` int(11) NOT NULL,
  `gradebhash` varbinary(32) DEFAULT NULL,
  `gradercid` int(11) DEFAULT NULL,
  `hidegrade` tinyint(1) NOT NULL DEFAULT 0,
  `placeholder` tinyint(1) NOT NULL DEFAULT 0,
  `placeholder_at` bigint(11) DEFAULT NULL,
  `commitat` bigint(11) DEFAULT NULL,
  `rpnotes` varbinary(16384) DEFAULT NULL,
  `rpnotesversion` int(11) NOT NULL DEFAULT 1,
  `emptydiff_at` bigint(11) DEFAULT NULL,
  `rpxnotes` varbinary(1024) DEFAULT NULL,
  `rpnotesOverflow` longblob DEFAULT NULL,
  `rpxnotesOverflow` longblob DEFAULT NULL,
  PRIMARY KEY (`repoid`,`branchid`,`pset`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



--
-- Table structure for table `Settings`
--

DROP TABLE IF EXISTS `Settings`;
CREATE TABLE `Settings` (
  `name` varbinary(256) NOT NULL,
  `value` int(11) NOT NULL,
  `data` varbinary(32767) DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;





insert into Settings (name, value) values ('allowPaperOption', 168);
delete from Settings where name='setupPhase';
insert into Settings (name, value) values ('setupPhase', 1);
-- collect PC conflicts from authors by default, but not collaborators
insert into Settings (name, value) values ('sub_pcconf', 1);
-- default chair-only tags
insert into Settings (name, value, data) values ('tag_chair', 1, 'accept reject pcpaper');
-- turn on SHA-1 calculation by default
insert into Settings (name, value) values ('sub_sha1', 1);
-- allow PC members to review any paper by default
insert into Settings (name, value) values ('pcrev_any', 1);
-- allow external reviewers to see the other reviews by default
insert into Settings (name, value) values ('extrev_view', 2);
