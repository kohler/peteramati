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
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



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
  `data` blob,
  PRIMARY KEY (`salt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `CommitNotes`
--

DROP TABLE IF EXISTS `CommitNotes`;
CREATE TABLE `CommitNotes` (
  `bhash` varbinary(32) NOT NULL,
  `pset` int(11) NOT NULL DEFAULT '0',
  `notes` varbinary(32767) DEFAULT NULL,
  `haslinenotes` tinyint(1) NOT NULL DEFAULT '0',
  `repoid` int(11) NOT NULL DEFAULT '0',
  `notesversion` int(11) NOT NULL DEFAULT '1',
  `hasactiveflags` tinyint(1) NOT NULL DEFAULT '0',
  `hasflags` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`pset`,`bhash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `ContactGrade`
--

DROP TABLE IF EXISTS `ContactGrade`;
CREATE TABLE `ContactGrade` (
  `cid` int(11) NOT NULL,
  `pset` int(11) NOT NULL,
  `gradercid` int(11) DEFAULT NULL,
  `notes` varbinary(32767) DEFAULT NULL,
  `hidegrade` tinyint(1) NOT NULL DEFAULT '0',
  `notesversion` int(11) NOT NULL DEFAULT '1',
  `hasactiveflags` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`cid`,`pset`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `ContactImage`
--

DROP TABLE IF EXISTS `ContactImage`;
CREATE TABLE `ContactImage` (
  `contactId` int(11) NOT NULL,
  `contactImageId` int(11) NOT NULL AUTO_INCREMENT,
  `mimetype` varbinary(128) DEFAULT NULL,
  `data` mediumblob,
  PRIMARY KEY (`contactId`,`contactImageId`),
  UNIQUE KEY `contactImageId` (`contactImageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `ContactInfo`
--

DROP TABLE IF EXISTS `ContactInfo`;
CREATE TABLE `ContactInfo` (
  `contactId` int(11) NOT NULL AUTO_INCREMENT,
  `visits` int(11) NOT NULL DEFAULT '0',
  `firstName` varchar(60) NOT NULL DEFAULT '',
  `lastName` varchar(60) NOT NULL DEFAULT '',
  `email` varchar(120) NOT NULL,
  `huid` varchar(10) DEFAULT NULL,
  `seascode_username` varchar(120) DEFAULT NULL,
  `preferredEmail` varchar(120) DEFAULT NULL,
  `affiliation` varchar(2048) NOT NULL DEFAULT '',
  `password` varbinary(2048) NOT NULL,
  `note` mediumtext,
  `creationTime` bigint(11) NOT NULL DEFAULT '0',
  `lastLogin` bigint(11) NOT NULL DEFAULT '0',
  `defaultWatch` int(11) NOT NULL DEFAULT '2',
  `roles` tinyint(1) NOT NULL DEFAULT '0',
  `disabled` tinyint(1) NOT NULL DEFAULT '0',
  `contactTags` text,
  `college` tinyint(1) NOT NULL DEFAULT '0',
  `extension` tinyint(1) NOT NULL DEFAULT '0',
  `dropped` int(11) NOT NULL DEFAULT '0',
  `passwordTime` bigint(11) NOT NULL DEFAULT '0',
  `anon_username` varbinary(40) DEFAULT NULL,
  `contactImageId` int(11) DEFAULT NULL,
  `github_username` varbinary(120) DEFAULT NULL,
  `passwordUseTime` bigint(11) NOT NULL DEFAULT '0',
  `updateTime` bigint(11) NOT NULL DEFAULT '0',
  `data` varbinary(32767) DEFAULT NULL,
  `studentYear` varbinary(4) DEFAULT NULL,
  `nickname` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`contactId`),
  UNIQUE KEY `email` (`email`),
  KEY `roles` (`roles`),
  UNIQUE KEY `anon_username` (`anon_username`),
  KEY `seascode_username` (`seascode_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `ContactLink`
--

DROP TABLE IF EXISTS `ContactLink`;
CREATE TABLE `ContactLink` (
  `cid` int(11) NOT NULL,
  `type` int(1) NOT NULL,
  `pset` int(1) NOT NULL DEFAULT '0',
  `link` int(11) NOT NULL,
  `data` varbinary(8192) DEFAULT NULL,
  KEY `cid_type` (`cid`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `ExecutionQueue`
--

DROP TABLE IF EXISTS `ExecutionQueue`;
CREATE TABLE `ExecutionQueue` (
  `queueid` int(11) NOT NULL AUTO_INCREMENT,
  `queueclass` varchar(20) NOT NULL,
  `repoid` int(11) NOT NULL,
  `insertat` bigint(11) NOT NULL,
  `updateat` bigint(11) NOT NULL,
  `runat` bigint(11) NOT NULL,
  `status` int(1) NOT NULL,
  `lockfile` varchar(1024) DEFAULT NULL,
  `nconcurrent` int(11) DEFAULT NULL,
  `autorun` tinyint(1) NOT NULL DEFAULT '0',
  `psetid` int(11) DEFAULT NULL,
  `runnername` varbinary(128) DEFAULT NULL,
  `bhash` varbinary(32) NOT NULL,
  `inputfifo` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`queueid`),
  KEY `queueclass` (`queueclass`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `MailLog`
--

DROP TABLE IF EXISTS `MailLog`;
CREATE TABLE `MailLog` (
  `mailId` int(11) NOT NULL AUTO_INCREMENT,
  `recipients` varchar(200) NOT NULL,
  `paperIds` text,
  `cc` text,
  `replyto` text,
  `subject` text,
  `emailBody` text,
  PRIMARY KEY (`mailId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `Repository`
--

DROP TABLE IF EXISTS `Repository`;
CREATE TABLE `Repository` (
  `repoid` int(11) NOT NULL AUTO_INCREMENT,
  `url` varchar(255) NOT NULL,
  `cacheid` varchar(20) NOT NULL,
  `open` int(11) NOT NULL,
  `opencheckat` bigint(11) NOT NULL DEFAULT '0',
  `snaphash` binary(40) DEFAULT NULL,
  `snapat` bigint(11) DEFAULT NULL,
  `snapcheckat` bigint(11) NOT NULL DEFAULT '0',
  `lastpset` int(11) NOT NULL DEFAULT '0',
  `working` int(1) NOT NULL DEFAULT '1',
  `snapcommitat` bigint(11) NOT NULL DEFAULT '0',
  `snapcommitline` varchar(100) DEFAULT NULL,
  `notes` varbinary(32767) DEFAULT NULL,
  `heads` varbinary(8192) DEFAULT NULL,
  `analyzedsnapat` bigint(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`repoid`),
  UNIQUE KEY `url` (`url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `RepositoryCommitSnapshot`
--

DROP TABLE IF EXISTS `RepositoryCommitSnapshot`;
CREATE TABLE `RepositoryCommitSnapshot` (
  `repoid` int(11) NOT NULL,
  `bhash` varbinary(32) NOT NULL,
  `snapshot` bigint(11) NOT NULL,
  PRIMARY KEY (`repoid`,`bhash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `RepositoryGrade`
--

DROP TABLE IF EXISTS `RepositoryGrade`;
CREATE TABLE `RepositoryGrade` (
  `repoid` int(11) NOT NULL,
  `pset` int(11) NOT NULL,
  `gradehash` binary(40) DEFAULT NULL,
  `gradercid` int(11) DEFAULT NULL,
  `hidegrade` tinyint(1) NOT NULL DEFAULT '0',
  `placeholder` tinyint(1) NOT NULL DEFAULT '0',
  `placeholder_at` bigint(11) DEFAULT NULL,
  PRIMARY KEY (`repoid`,`pset`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `RepositoryGradeRequest`
--

DROP TABLE IF EXISTS `RepositoryGradeRequest`;
CREATE TABLE `RepositoryGradeRequest` (
  `repoid` int(11) NOT NULL,
  `pset` int(11) NOT NULL,
  `hash` binary(40) DEFAULT NULL,
  `requested_at` bigint(11) NOT NULL,
  UNIQUE KEY `repopsethash` (`repoid`,`pset`,`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



--
-- Table structure for table `Settings`
--

DROP TABLE IF EXISTS `Settings`;
CREATE TABLE `Settings` (
  `name` varbinary(256) NOT NULL,
  `value` int(11) NOT NULL,
  `data` varbinary(32767) DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;




insert into Settings (name, value) values ('allowPaperOption', 121);
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
