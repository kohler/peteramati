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
  UNIQUE KEY `logId` (`logId`),
  KEY `contactId` (`contactId`),
  KEY `paperId` (`paperId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `Capability`
--

DROP TABLE IF EXISTS `Capability`;
CREATE TABLE `Capability` (
  `capabilityId` int(11) NOT NULL AUTO_INCREMENT,
  `capabilityType` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `timeExpires` int(11) NOT NULL,
  `salt` varbinary(255) NOT NULL,
  `data` blob,
  PRIMARY KEY (`capabilityId`),
  UNIQUE KEY `capabilityId` (`capabilityId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `CapabilityMap`
--

DROP TABLE IF EXISTS `CapabilityMap`;
CREATE TABLE `CapabilityMap` (
  `capabilityValue` varbinary(255) NOT NULL,
  `capabilityId` int(11) NOT NULL,
  `timeExpires` int(11) NOT NULL,
  PRIMARY KEY (`capabilityValue`),
  UNIQUE KEY `capabilityValue` (`capabilityValue`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `Chair`
--

DROP TABLE IF EXISTS `Chair`;
CREATE TABLE `Chair` (
  `contactId` int(11) NOT NULL,
  UNIQUE KEY `contactId` (`contactId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `ChairAssistant`
--

DROP TABLE IF EXISTS `ChairAssistant`;
CREATE TABLE `ChairAssistant` (
  `contactId` int(11) NOT NULL,
  UNIQUE KEY `contactId` (`contactId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `CommitInfo`
--

DROP TABLE IF EXISTS `CommitInfo`;
CREATE TABLE `CommitInfo` (
  `commitid` int(11) NOT NULL AUTO_INCREMENT,
  `repoid` int(11) NOT NULL,
  `sha1` varbinary(20) NOT NULL,
  `timestamp` int(11) NOT NULL,
  PRIMARY KEY (`commitid`),
  UNIQUE KEY `commitid` (`commitid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `CommitNotes`
--

DROP TABLE IF EXISTS `CommitNotes`;
CREATE TABLE `CommitNotes` (
  `hash` binary(40) NOT NULL,
  `notes` varbinary(32767) DEFAULT NULL,
  `haslinenotes` tinyint(1) NOT NULL DEFAULT '0',
  `pset` int(11) NOT NULL DEFAULT '0',
  `repoid` int(11) NOT NULL DEFAULT '0',
  `nrepo` int(11) NOT NULL DEFAULT '0',
  UNIQUE KEY `hashpset` (`hash`,`pset`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



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
  UNIQUE KEY `cidpset` (`cid`,`pset`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



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
  `creationTime` int(11) NOT NULL DEFAULT '0',
  `lastLogin` int(11) NOT NULL DEFAULT '0',
  `defaultWatch` int(11) NOT NULL DEFAULT '2',
  `roles` tinyint(1) NOT NULL DEFAULT '0',
  `disabled` tinyint(1) NOT NULL DEFAULT '0',
  `contactTags` text,
  `college` tinyint(1) NOT NULL DEFAULT '0',
  `extension` tinyint(1) NOT NULL DEFAULT '0',
  `dropped` int(11) NOT NULL DEFAULT '0',
  `passwordTime` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`contactId`),
  UNIQUE KEY `contactId` (`contactId`),
  UNIQUE KEY `contactIdRoles` (`contactId`,`roles`),
  UNIQUE KEY `email` (`email`),
  KEY `fullName` (`lastName`,`firstName`,`email`),
  KEY `seascode_username` (`seascode_username`),
  FULLTEXT KEY `name` (`lastName`,`firstName`,`email`),
  FULLTEXT KEY `affiliation` (`affiliation`),
  FULLTEXT KEY `email_3` (`email`),
  FULLTEXT KEY `firstName_2` (`firstName`),
  FULLTEXT KEY `lastName` (`lastName`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `ContactLink`
--

DROP TABLE IF EXISTS `ContactLink`;
CREATE TABLE `ContactLink` (
  `cid` int(11) NOT NULL,
  `type` int(1) NOT NULL,
  `pset` int(1) NOT NULL DEFAULT '0',
  `link` int(11) NOT NULL,
  KEY `cid_type` (`cid`,`type`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `ExecutionQueue`
--

DROP TABLE IF EXISTS `ExecutionQueue`;
CREATE TABLE `ExecutionQueue` (
  `queueid` int(11) NOT NULL AUTO_INCREMENT,
  `queueclass` varchar(20) NOT NULL,
  `repoid` int(11) NOT NULL,
  `insertat` int(11) NOT NULL,
  `updateat` int(11) NOT NULL,
  `runat` int(11) NOT NULL,
  `status` int(1) NOT NULL,
  `lockfile` varchar(1024) DEFAULT NULL,
  `nconcurrent` int(11) DEFAULT NULL,
  `autorun` tinyint(1) NOT NULL DEFAULT '0',
  `psetid` int(11) DEFAULT NULL,
  `runnername` varbinary(128) DEFAULT NULL,
  `hash` binary(40) DEFAULT NULL,
  PRIMARY KEY (`queueid`),
  KEY `queueclass` (`queueclass`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `Formula`
--

DROP TABLE IF EXISTS `Formula`;
CREATE TABLE `Formula` (
  `formulaId` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `heading` varchar(200) NOT NULL DEFAULT '',
  `headingTitle` text NOT NULL,
  `expression` text NOT NULL,
  `authorView` tinyint(1) NOT NULL DEFAULT '1',
  `createdBy` int(11) NOT NULL DEFAULT '0',
  `timeModified` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`formulaId`),
  UNIQUE KEY `formulaId` (`formulaId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



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
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `OptionType`
--

DROP TABLE IF EXISTS `OptionType`;
CREATE TABLE `OptionType` (
  `optionId` int(11) NOT NULL AUTO_INCREMENT,
  `optionName` varchar(200) NOT NULL,
  `description` text,
  `type` tinyint(1) NOT NULL DEFAULT '0',
  `pcView` tinyint(1) NOT NULL DEFAULT '1',
  `optionValues` text NOT NULL,
  `sortOrder` tinyint(1) NOT NULL DEFAULT '0',
  `displayType` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`optionId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `PCMember`
--

DROP TABLE IF EXISTS `PCMember`;
CREATE TABLE `PCMember` (
  `contactId` int(11) NOT NULL,
  UNIQUE KEY `contactId` (`contactId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperConflict`
--

DROP TABLE IF EXISTS `PaperConflict`;
CREATE TABLE `PaperConflict` (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `conflictType` tinyint(1) NOT NULL DEFAULT '0',
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  UNIQUE KEY `contactPaperConflict` (`contactId`,`paperId`,`conflictType`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `PaperStorage`
--

DROP TABLE IF EXISTS `PaperStorage`;
CREATE TABLE `PaperStorage` (
  `paperStorageId` int(11) NOT NULL AUTO_INCREMENT,
  `paperId` int(11) NOT NULL,
  `timestamp` int(11) NOT NULL,
  `mimetype` varchar(80) NOT NULL DEFAULT '',
  `paper` longblob,
  `compression` tinyint(1) NOT NULL DEFAULT '0',
  `sha1` varbinary(20) NOT NULL DEFAULT '',
  `documentType` int(3) NOT NULL DEFAULT '0',
  `filename` varchar(255) DEFAULT NULL,
  `infoJson` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`paperStorageId`),
  UNIQUE KEY `paperStorageId` (`paperStorageId`),
  KEY `paperId` (`paperId`),
  KEY `mimetype` (`mimetype`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `PsetGrade`
--

DROP TABLE IF EXISTS `PsetGrade`;
CREATE TABLE `PsetGrade` (
  `commitid` int(11) NOT NULL,
  `pset` int(1) NOT NULL,
  `autograde` int(11) DEFAULT NULL,
  KEY `commitidPset` (`commitid`,`pset`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `Repository`
--

DROP TABLE IF EXISTS `Repository`;
CREATE TABLE `Repository` (
  `repoid` int(11) NOT NULL AUTO_INCREMENT,
  `url` varchar(255) NOT NULL,
  `cacheid` varchar(20) NOT NULL,
  `open` int(11) NOT NULL,
  `opencheckat` int(11) NOT NULL DEFAULT '0',
  `snaphash` binary(40) DEFAULT NULL,
  `snapat` int(11) DEFAULT NULL,
  `snapcheckat` int(11) NOT NULL DEFAULT '0',
  `lastpset` int(11) NOT NULL DEFAULT '0',
  `working` int(1) NOT NULL DEFAULT '1',
  `snapcommitat` int(11) NOT NULL DEFAULT '0',
  `snapcommitline` varchar(100) DEFAULT NULL,
  `notes` varbinary(32767) DEFAULT NULL,
  `heads` varbinary(8192) DEFAULT NULL,
  PRIMARY KEY (`repoid`),
  UNIQUE KEY `repoid` (`repoid`),
  UNIQUE KEY `url` (`url`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



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
  UNIQUE KEY `repopset` (`repoid`,`pset`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `RepositoryGradeRequest`
--

DROP TABLE IF EXISTS `RepositoryGradeRequest`;
CREATE TABLE `RepositoryGradeRequest` (
  `repoid` int(11) NOT NULL,
  `pset` int(11) NOT NULL,
  `hash` binary(40) DEFAULT NULL,
  `requested_at` int(11) NOT NULL,
  UNIQUE KEY `repopsethash` (`repoid`,`pset`,`hash`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



--
-- Table structure for table `Settings`
--

DROP TABLE IF EXISTS `Settings`;
CREATE TABLE `Settings` (
  `name` char(40) NOT NULL,
  `value` int(11) NOT NULL,
  `data` varbinary(32767) DEFAULT NULL,
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;




insert into Settings (name, value) values ('allowPaperOption', 85);
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

insert into PaperStorage set paperStorageId=1, paperId=0, timestamp=0, mimetype='text/plain', paper='' on duplicate key update paper='';


delete from Settings where name='revform_update';
insert into Settings set name='revform_update', value=unix_timestamp(current_timestamp);
