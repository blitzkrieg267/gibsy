<?php
/*
Kreck Bridge — database change log, read by
modules/System Admin/module_manage_updateProcess.php on module update.
Each entry: $sql[$count][0] = fromVersion; $sql[$count][1] = statements (split on ;end).
Entries whose version is > the installed version and <= the code version are run.
*/

$count = 0;
$sql = [];

// 0.1.00 -> 0.2.00: register the "Import Institution Data" action + grant Admin.
++$count;
$sql[$count][0] = '0.1.00';
$sql[$count][1] = "
INSERT INTO gibbonAction
 (gibbonModuleID, name, precedence, category, description, URLList, entryURL, entrySidebar, menuShow,
  defaultPermissionAdmin, defaultPermissionTeacher, defaultPermissionStudent, defaultPermissionParent, defaultPermissionSupport,
  categoryPermissionStaff, categoryPermissionStudent, categoryPermissionParent, categoryPermissionOther)
 VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),
  'Import Institution Data', 1, 'Bridge',
  'Seed institution data into SMS via an in-app table or an Excel/CSV upload.',
  'kreck_import.php,kreck_import_institution.php', 'kreck_import.php', 'Y', 'Y', 'Y','N','N','N','N', 'Y','N','N','N');end
INSERT INTO gibbonPermission (gibbonRoleID, gibbonActionID)
 VALUES ('001', (SELECT gibbonActionID FROM gibbonAction
   WHERE name='Import Institution Data'
   AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
";

// 0.2.00 -> 0.3.00: staff-salary record-keeping (tables, actions, setting, events).
++$count;
$sql[$count][0] = '0.2.00';
$sql[$count][1] = "
CREATE TABLE `kreckSalary` (
  `kreckSalaryID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NOT NULL,
  `gibbonSchoolYearID` INT(3) UNSIGNED ZEROFILL NULL,
  `amount` DECIMAL(13,2) NOT NULL, `currency` VARCHAR(3) NULL,
  `frequency` ENUM('Monthly','Fortnightly','Weekly','Termly','Annually','Once') NOT NULL DEFAULT 'Monthly',
  `payDay` INT(2) NULL, `startDate` DATE NOT NULL, `endDate` DATE NULL,
  `status` ENUM('Active','Suspended','Ended') NOT NULL DEFAULT 'Active', `notes` TEXT NULL,
  `gibbonPersonIDCreator` INT(10) UNSIGNED ZEROFILL NULL, `timestampCreator` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`kreckSalaryID`), KEY `gibbonPersonID` (`gibbonPersonID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
CREATE TABLE `kreckSalaryPayment` (
  `kreckSalaryPaymentID` INT(14) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `kreckSalaryID` INT(12) UNSIGNED ZEROFILL NOT NULL, `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NOT NULL,
  `period` VARCHAR(7) NOT NULL, `dueDate` DATE NULL, `amount` DECIMAL(13,2) NOT NULL,
  `status` ENUM('Pending','Finalized','Paid') NOT NULL DEFAULT 'Pending',
  `paymentDate` DATE NULL, `paymentMethod` ENUM('Cash','Cheque','Bank Transfer','Mobile Money','Other') NULL,
  `proofOfPayment` VARCHAR(255) NULL, `reminderCount` INT(2) NOT NULL DEFAULT 0, `notes` TEXT NULL,
  `gibbonPersonIDCreator` INT(10) UNSIGNED ZEROFILL NULL, `timestampCreator` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`kreckSalaryPaymentID`), UNIQUE KEY `salary_period` (`kreckSalaryID`,`period`),
  KEY `status` (`status`), KEY `gibbonPersonID` (`gibbonPersonID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
 VALUES ('Kreck Bridge','payrollOfficer','Payroll Officer','Optional gibbonPersonID nudged by the salary reminder CLI about due/overdue payments.','');end
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Manage Staff Salaries',0,'Payroll','Record recurring staff salary definitions.','kreck_salary.php','kreck_salary.php','Y','Y','Y','N','N','N','N','Y','N','N','N');end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID) VALUES ('001',(SELECT gibbonActionID FROM gibbonAction WHERE name='Manage Staff Salaries' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Payroll Run',1,'Payroll','Generate, finalize and mark salaries paid for a period.','kreck_salary_run.php,kreck_salary_receipt.php','kreck_salary_run.php','Y','Y','Y','N','N','N','N','Y','N','N','N');end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID) VALUES ('001',(SELECT gibbonActionID FROM gibbonAction WHERE name='Payroll Run' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
INSERT INTO gibbonNotificationEvent (event,moduleName,actionName,type,scopes,active) VALUES ('Salary Paid','Kreck Bridge','Payroll Run','Additional','gibbonPersonID','Y');end
INSERT INTO gibbonNotificationEvent (event,moduleName,actionName,type,scopes,active) VALUES ('Salary Payment Due','Kreck Bridge','Payroll Run','Additional','gibbonPersonID','Y');end
";

// 0.3.00 -> 0.4.00: KRECK branding — point site logo + system name at KRECK
// (only if still the SMS defaults, so a school's own branding is preserved).
++$count;
$sql[$count][0] = '0.3.00';
$sql[$count][1] = "
UPDATE gibbonSetting SET value='themes/Kreck/img/logo.png' WHERE scope='System' AND name='organisationLogo' AND (value LIKE '%Default/img/logo%' OR value='' OR value IS NULL);end
UPDATE gibbonSetting SET value='KRECK' WHERE scope='System' AND name='systemName' AND value='SMS';end
";

// 0.4.00 -> 0.5.00: LMS->SMS return leg — deep-link + marks tables, view/import actions.
++$count;
$sql[$count][0] = '0.4.00';
$sql[$count][1] = "
CREATE TABLE `kreckLink` (
  `kreckLinkID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `entityType` ENUM('course','activity','user') NOT NULL,
  `entityKey` VARCHAR(255) NOT NULL, `subKey` VARCHAR(255) NULL,
  `moodleID` INT(12) UNSIGNED NULL, `modName` VARCHAR(50) NULL,
  `moodleRelURL` VARCHAR(500) NOT NULL, `label` VARCHAR(255) NULL,
  `timestampUpdated` DATETIME NULL,
  PRIMARY KEY (`kreckLinkID`), UNIQUE KEY `entity_url` (`entityType`,`entityKey`,`moodleRelURL`),
  KEY `entityKey` (`entityKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
CREATE TABLE `kreckMark` (
  `kreckMarkID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `entityKey` VARCHAR(255) NOT NULL, `courseShortname` VARCHAR(255) NOT NULL,
  `item` VARCHAR(255) NOT NULL, `grade` VARCHAR(50) NULL, `gradeMax` VARCHAR(50) NULL,
  `moodleCourseID` INT(12) UNSIGNED NULL, `exportedAt` VARCHAR(40) NULL, `timestampUpdated` DATETIME NULL,
  PRIMARY KEY (`kreckMarkID`), UNIQUE KEY `student_item` (`entityKey`,`courseShortname`,`item`),
  KEY `courseShortname` (`courseShortname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Kreck LMS Classes',0,'Bridge','Import the Kreck LMS links file and browse each class and its activities as Open in KRECK LMS links.','kreck_lms.php,kreck_import_links.php','kreck_lms.php','Y','Y','Y','Y','N','N','N','Y','N','N','N');end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID) VALUES ('001',(SELECT gibbonActionID FROM gibbonAction WHERE name='Kreck LMS Classes' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Kreck LMS Marks',1,'Bridge','Import the Kreck LMS gradebook export and keep each learner marks on their school record.','kreck_marks.php','kreck_marks.php','Y','Y','Y','Y','N','N','N','Y','N','N','N');end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID) VALUES ('001',(SELECT gibbonActionID FROM gibbonAction WHERE name='Kreck LMS Marks' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
";

// 0.5.00 -> 0.5.01: in-app Settings page (edit LMS Endpoint URL + defaults
// without touching the DB). Also back-fill any setting rows missing on older
// installs, so the Settings form always has them.
++$count;
$sql[$count][0] = '0.5.00';
$sql[$count][1] = "
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Kreck Settings',0,'Bridge','Edit the Kreck Bridge settings in-app: LMS Endpoint URL, export defaults and payroll officer.','kreck_settings.php,kreck_settingsProcess.php','kreck_settings.php','Y','Y','Y','N','N','N','N','Y','N','N','N');end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID) VALUES ('001',(SELECT gibbonActionID FROM gibbonAction WHERE name='Kreck Settings' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','moodleEndpoint','LMS Endpoint URL','Base URL of the Kreck LMS.','' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='moodleEndpoint');end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','defaultPassword','Default Password','Per-install RANDOM starter password for created LMS accounts (change-on-first-login is enforced). Rotate it anytime in Kreck Settings.', CONCAT('Kr', UPPER(SUBSTRING(SHA2(CONCAT(RAND(),UUID(),NOW(6)),256),1,6)), SUBSTRING(SHA2(CONCAT(UUID(),RAND()),256),7,8), '#9a') FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='defaultPassword');end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','forcePasswordChange','Force Password Change','If Y, imported LMS users must change their password at first login.','Y' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='forcePasswordChange');end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','schoolDomain','School Email Domain','Used to build placeholder emails when a person has no email.','school.invalid' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='schoolDomain');end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','payrollOfficer','Payroll Officer','Optional gibbonPersonID nudged by the salary reminder about due/overdue payments.','' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='payrollOfficer');end
";

// 0.5.01 -> 0.5.02: live LMS manifest sync and reviewed LMS change inbox.
++ $count;
$sql[$count][0] = '0.5.01';
$sql[$count][1] = "
CREATE TABLE `kreckSyncEvent` (
  `kreckSyncEventID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `eventID` VARCHAR(64) NOT NULL,
  `eventType` VARCHAR(80) NOT NULL,
  `entityType` VARCHAR(32) NOT NULL,
  `entityID` VARCHAR(64) NOT NULL,
  `action` VARCHAR(20) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `receivedAt` DATETIME NOT NULL,
  `reviewStatus` ENUM('Pending','Reviewed') NOT NULL DEFAULT 'Pending',
  `gibbonPersonIDReviewer` INT(10) UNSIGNED ZEROFILL NULL,
  `reviewedAt` DATETIME NULL,
  PRIMARY KEY (`kreckSyncEventID`),
  UNIQUE KEY `eventID` (`eventID`),
  KEY `reviewStatus_receivedAt` (`reviewStatus`,`receivedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','moodleToken','LMS Web Service Token','Restricted LMS token for local_kreck_sync_manifest; keep secret.','' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='moodleToken');end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','webhookSecret','Shared Webhook Secret','Shared HMAC secret used to authenticate LMS change notifications.','' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='webhookSecret');end
UPDATE gibbonAction SET description='Preview LMS impact, synchronize the school-year manifest, or download its JSON form.' WHERE name='Export to Kreck LMS' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge');end
UPDATE gibbonAction SET description='Edit the LMS endpoint/token, shared webhook secret, export defaults and payroll officer.' WHERE name='Kreck Settings' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge');end
UPDATE gibbonAction SET description='Review LMS change events and browse classes and activities as Open in KRECK LMS deep-links.' WHERE name='Kreck LMS Classes' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge');end
";

// 0.5.02 -> 0.5.03: SMS-owned weighted semester assessments, audit, and notifications.
++ $count;
$sql[$count][0] = '0.5.02';
$sql[$count][1] = "
CREATE TABLE `kreckAssessment` (
  `kreckAssessmentID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `gibbonCourseClassID` INT(12) UNSIGNED ZEROFILL NOT NULL,
  `gibbonSchoolYearID` INT(3) UNSIGNED ZEROFILL NOT NULL,
  `periodName` VARCHAR(40) NOT NULL,
  `ca1Weight` DECIMAL(5,2) NOT NULL DEFAULT 25.00,
  `ca2Weight` DECIMAL(5,2) NOT NULL DEFAULT 25.00,
  `examWeight` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  `dueDate` DATE NULL,
  `status` ENUM('Draft','Submitted','RevisionRequested','Approved') NOT NULL DEFAULT 'Draft',
  `gibbonPersonIDCreator` INT(10) UNSIGNED ZEROFILL NULL,
  `gibbonPersonIDSubmitted` INT(10) UNSIGNED ZEROFILL NULL,
  `submittedAt` DATETIME NULL,
  `gibbonPersonIDReviewer` INT(10) UNSIGNED ZEROFILL NULL,
  `reviewedAt` DATETIME NULL,
  `reviewerComment` TEXT NULL,
  `timestampCreated` DATETIME NOT NULL,
  `timestampUpdated` DATETIME NOT NULL,
  PRIMARY KEY (`kreckAssessmentID`),
  UNIQUE KEY `class_year_period` (`gibbonCourseClassID`,`gibbonSchoolYearID`,`periodName`),
  KEY `year_status` (`gibbonSchoolYearID`,`status`),
  KEY `due_status` (`dueDate`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
CREATE TABLE `kreckAssessmentMark` (
  `kreckAssessmentMarkID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `kreckAssessmentID` INT(12) UNSIGNED ZEROFILL NOT NULL,
  `gibbonPersonIDStudent` INT(10) UNSIGNED ZEROFILL NOT NULL,
  `ca1Mark` DECIMAL(5,2) NULL, `ca1Source` ENUM('Manual','LMS') NOT NULL DEFAULT 'Manual', `ca1SourceRef` VARCHAR(255) NULL,
  `ca2Mark` DECIMAL(5,2) NULL, `ca2Source` ENUM('Manual','LMS') NOT NULL DEFAULT 'Manual', `ca2SourceRef` VARCHAR(255) NULL,
  `examMark` DECIMAL(5,2) NULL, `examSource` ENUM('Manual','LMS') NOT NULL DEFAULT 'Manual', `examSourceRef` VARCHAR(255) NULL,
  `finalMark` DECIMAL(5,2) NULL, `comment` TEXT NULL,
  `gibbonPersonIDLastEdit` INT(10) UNSIGNED ZEROFILL NULL, `timestampUpdated` DATETIME NOT NULL,
  PRIMARY KEY (`kreckAssessmentMarkID`),
  UNIQUE KEY `assessment_student` (`kreckAssessmentID`,`gibbonPersonIDStudent`),
  KEY `student_assessment` (`gibbonPersonIDStudent`,`kreckAssessmentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
CREATE TABLE `kreckAssessmentAudit` (
  `kreckAssessmentAuditID` INT(14) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `kreckAssessmentID` INT(12) UNSIGNED ZEROFILL NOT NULL,
  `gibbonPersonIDStudent` INT(10) UNSIGNED ZEROFILL NULL,
  `action` VARCHAR(32) NOT NULL, `beforeSnapshot` LONGTEXT NULL, `afterSnapshot` LONGTEXT NULL,
  `gibbonPersonIDActor` INT(10) UNSIGNED ZEROFILL NULL, `timestampCreated` DATETIME NOT NULL,
  PRIMARY KEY (`kreckAssessmentAuditID`),
  KEY `assessment_time` (`kreckAssessmentID`,`timestampCreated`),
  KEY `student_time` (`gibbonPersonIDStudent`,`timestampCreated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
CREATE TABLE `kreckAssessmentNotification` (
  `kreckAssessmentNotificationID` INT(14) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `eventKey` VARCHAR(100) NOT NULL, `kreckAssessmentID` INT(12) UNSIGNED ZEROFILL NOT NULL,
  `gibbonPersonIDRecipient` INT(10) UNSIGNED ZEROFILL NOT NULL,
  `notificationType` VARCHAR(32) NOT NULL, `message` TEXT NOT NULL, `actionLink` VARCHAR(500) NOT NULL,
  `status` ENUM('Pending','Sent','Failed','Cancelled') NOT NULL DEFAULT 'Pending',
  `attempts` INT(4) NOT NULL DEFAULT 0, `nextAttempt` DATETIME NOT NULL, `lastError` TEXT NULL,
  `timestampCreated` DATETIME NOT NULL, `timestampSent` DATETIME NULL,
  PRIMARY KEY (`kreckAssessmentNotificationID`),
  UNIQUE KEY `event_recipient` (`eventKey`,`gibbonPersonIDRecipient`),
  KEY `delivery_queue` (`status`,`nextAttempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Assessment Mark Entry',0,'Assessment','Enter and submit CA1, CA2 and final exam marks for assigned classes.','kreck_assessments.php','kreck_assessments.php','Y','Y','Y','Y','N','N','N','Y','N','N','N');end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 VALUES ('001',(SELECT gibbonActionID FROM gibbonAction WHERE name='Assessment Mark Entry' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 SELECT gibbonRoleID,(SELECT gibbonActionID FROM gibbonAction WHERE name='Assessment Mark Entry' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'))
 FROM gibbonRole WHERE category='Staff' AND name='Teacher';end
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Review Assessment Marks',1,'Assessment','Review submitted class mark sheets, approve results or request corrections.','kreck_assessment_review.php','kreck_assessment_review.php','Y','Y','Y','N','N','N','N','Y','N','N','N');end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 VALUES ('001',(SELECT gibbonActionID FROM gibbonAction WHERE name='Review Assessment Marks' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Assessment Reports',2,'Assessment','View and export approved semester marks for yearly reporting.','kreck_assessment_reports.php','kreck_assessment_reports.php','Y','Y','Y','Y','N','N','N','Y','N','N','N');end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 VALUES ('001',(SELECT gibbonActionID FROM gibbonAction WHERE name='Assessment Reports' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 SELECT gibbonRoleID,(SELECT gibbonActionID FROM gibbonAction WHERE name='Assessment Reports' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'))
 FROM gibbonRole WHERE category='Staff' AND name='Teacher';end
UPDATE gibbonAction SET description='Import raw LMS activity marks. Enter and approve official semester finals under Assessment Mark Entry.' WHERE name='Kreck LMS Marks' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge');end
";

// 0.5.03 -> 0.5.04: importable LMS assignment plans and scheduling metadata.
++ $count;
$sql[$count][0] = '0.5.03';
$sql[$count][1] = "
CREATE TABLE `kreckLmsAssignmentPlan` (
  `kreckLmsAssignmentPlanID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `gibbonSchoolYearID` INT(3) UNSIGNED ZEROFILL NOT NULL,
  `gibbonCourseID` INT(8) UNSIGNED ZEROFILL NOT NULL,
  `gibbonCourseClassID` INT(8) UNSIGNED ZEROFILL NOT NULL,
  `sourceRef` VARCHAR(100) NOT NULL,
  `sectionNumber` INT(4) UNSIGNED NOT NULL DEFAULT 1,
  `activityName` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `openDate` DATE NULL,
  `openTime` TIME NULL,
  `dueDate` DATE NOT NULL,
  `dueTime` TIME NOT NULL,
  `cutoffDate` DATE NULL,
  `cutoffTime` TIME NULL,
  `gradeMax` DECIMAL(10,2) NOT NULL DEFAULT 100.00,
  `active` ENUM('Y','N') NOT NULL DEFAULT 'Y',
  `timestampCreated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampUpdated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kreckLmsAssignmentPlanID`),
  UNIQUE KEY `class_reference` (`gibbonCourseClassID`,`sourceRef`),
  KEY `year_active` (`gibbonSchoolYearID`,`active`),
  KEY `class_due` (`gibbonCourseClassID`,`dueDate`,`dueTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
";

// 0.5.04 -> 0.5.05: repair missing assignment-plan tables and align the
// institution importer with the SMS 30 ImportType API. No other schema changes.
++ $count;
$sql[$count][0] = '0.5.04';
$sql[$count][1] = "
CREATE TABLE IF NOT EXISTS `kreckLmsAssignmentPlan` (
  `kreckLmsAssignmentPlanID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `gibbonSchoolYearID` INT(3) UNSIGNED ZEROFILL NOT NULL,
  `gibbonCourseID` INT(8) UNSIGNED ZEROFILL NOT NULL,
  `gibbonCourseClassID` INT(8) UNSIGNED ZEROFILL NOT NULL,
  `sourceRef` VARCHAR(100) NOT NULL,
  `sectionNumber` INT(4) UNSIGNED NOT NULL DEFAULT 1,
  `activityName` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `openDate` DATE NULL,
  `openTime` TIME NULL,
  `dueDate` DATE NOT NULL,
  `dueTime` TIME NOT NULL,
  `cutoffDate` DATE NULL,
  `cutoffTime` TIME NULL,
  `gradeMax` DECIMAL(10,2) NOT NULL DEFAULT 100.00,
  `active` ENUM('Y','N') NOT NULL DEFAULT 'Y',
  `timestampCreated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampUpdated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kreckLmsAssignmentPlanID`),
  UNIQUE KEY `class_reference` (`gibbonCourseClassID`,`sourceRef`),
  KEY `year_active` (`gibbonSchoolYearID`,`active`),
  KEY `class_due` (`gibbonCourseClassID`,`dueDate`,`dueTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
";

// 0.5.07 -> 0.5.08: ensure LMS classes page tables exist.
// SMS runs entries whose version is newer than the installed version.
++ $count;
$sql[$count][0] = '0.5.08';
$sql[$count][1] = "
CREATE TABLE IF NOT EXISTS `kreckLink` (
  `kreckLinkID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `entityType` ENUM('course','activity','user') NOT NULL,
  `entityKey` VARCHAR(255) NOT NULL,
  `subKey` VARCHAR(255) NULL,
  `moodleID` INT(12) UNSIGNED NULL,
  `modName` VARCHAR(50) NULL,
  `moodleRelURL` VARCHAR(500) NOT NULL,
  `label` VARCHAR(255) NULL,
  `timestampUpdated` DATETIME NULL,
  PRIMARY KEY (`kreckLinkID`),
  UNIQUE KEY `entity_url` (`entityType`,`entityKey`,`moodleRelURL`),
  KEY `entityKey` (`entityKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
CREATE TABLE IF NOT EXISTS `kreckSyncEvent` (
  `kreckSyncEventID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
  `eventID` VARCHAR(64) NOT NULL,
  `eventType` VARCHAR(80) NOT NULL,
  `entityType` VARCHAR(32) NOT NULL,
  `entityID` VARCHAR(64) NOT NULL,
  `action` VARCHAR(20) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `receivedAt` DATETIME NOT NULL,
  `reviewStatus` ENUM('Pending','Reviewed') NOT NULL DEFAULT 'Pending',
  `gibbonPersonIDReviewer` INT(10) UNSIGNED ZEROFILL NULL,
  `reviewedAt` DATETIME NULL,
  PRIMARY KEY (`kreckSyncEventID`),
  UNIQUE KEY `eventID` (`eventID`),
  KEY `reviewStatus_receivedAt` (`reviewStatus`,`receivedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
";

// 0.5.12 -> 0.5.13: bidirectional connection heartbeat timestamps.
++ $count;
$sql[$count][0] = '0.5.12';
$sql[$count][1] = "
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value)
 SELECT 'Kreck Bridge','gibbonHeartbeatAt','Last SMS-to-LMS Heartbeat','Timestamp of the last successful authenticated LMS connection check.','0'
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='gibbonHeartbeatAt');end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value)
 SELECT 'Kreck Bridge','moodleHeartbeatAt','Last LMS-to-SMS Heartbeat','Timestamp of the last successful signed LMS connection check.','0'
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='moodleHeartbeatAt');end
UPDATE gibbonSetting SET description='Restricted LMS token for local_kreck_sync_manifest and local_kreck_ping; keep secret.'
 WHERE scope='Kreck Bridge' AND name='moodleToken';end
";

// -> 0.5.17: new "KRECK Home" landing action for the module.
// Step tag = the version that INTRODUCES the change: the updater
// (module_manage_updateProcess.php) runs a step when step[0] > versionDB and
// step[0] <= versionCode, so tagging it 0.5.17 reaches every install below
// 0.5.17 (0.5.16 included) and never re-runs once an install is at 0.5.17.
++ $count;
$sql[$count][0] = '0.5.17';
$sql[$count][1] = "
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'KRECK Home',1,'Bridge','A modern KRECK home page: live institution stats, KRECK LMS connection status and quick actions into the bridge.','kreck_home.php','kreck_home.php','Y','Y','Y','Y','N','N','Y','Y','N','N','N'
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonAction WHERE name='KRECK Home' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'));end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 SELECT r.gibbonRoleID,(SELECT gibbonActionID FROM gibbonAction WHERE name='KRECK Home' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'))
 FROM gibbonRole r WHERE r.category='Staff'
 AND NOT EXISTS (SELECT 1 FROM gibbonPermission p WHERE p.gibbonRoleID=r.gibbonRoleID AND p.gibbonActionID=(SELECT gibbonActionID FROM gibbonAction WHERE name='KRECK Home' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
";

// -> 0.5.18: modernise the GLOBAL login / welcome page to the KRECK look.
// The visual styling ships in the Kreck THEME (css/main.css + js/common.js,
// which tag <body class='kreck-login'>). This step wires the two site-level
// settings that make the update actually take effect, each guarded so a
// school's own choices are never overwritten (same philosophy as the 0.3.00
// branding step):
//   1. Replace the stock welcome blurb with a KRECK one — only if it is still
//      the SMS default (or empty).
//   2. If the site is still on the stock 'Default' theme (no custom theme
//      chosen), switch the active theme to 'Kreck' so the modern login/skin
//      applies. The two guarded UPDATEs never leave the site with no active
//      theme, and do nothing when a custom theme is active or Kreck is absent.
++ $count;
$sql[$count][0] = '0.5.18';
$sql[$count][1] = "
UPDATE gibbonSetting SET value='Welcome to <strong>KRECK MicroLearn</strong> — your school''s management system, connected to the KRECK learning platform. Sign in to manage people, classes and finance in one place.'
 WHERE scope='System' AND name='indexText' AND (value LIKE 'Welcome to Gibbon%' OR value='' OR value IS NULL);end
UPDATE gibbonTheme SET active='Y' WHERE name='Kreck'
 AND (SELECT c FROM (SELECT COUNT(*) c FROM gibbonTheme WHERE active='Y' AND name='Default') d)=1
 AND (SELECT c FROM (SELECT COUNT(*) c FROM gibbonTheme WHERE active='Y' AND name<>'Default') o)=0;end
UPDATE gibbonTheme SET active='N' WHERE name='Default'
 AND (SELECT c FROM (SELECT COUNT(*) c FROM gibbonTheme WHERE active='Y' AND name='Kreck') k)=1;end
";

// -> 0.5.19: salary approval workflow + categorisation.
// Finance enters salaries (Manage Staff Salaries, Staff-wide); a salary is only
// payroll-eligible once a SCHOOL ADMINISTRATOR approves it on the new 'Salary
// Approvals' page (granted to the built-in Administrator role only). Adds the
// approval + category/tag columns to kreckSalary (ADD COLUMN IF NOT EXISTS, so
// re-running is safe) and registers the admin-only action. The approvalStatus
// column is ADDED with DEFAULT 'Approved' purely to grandfather pre-workflow
// rows in one atomic, idempotent step (so this update never retroactively
// freezes payroll for salaries that were already live). All new entries are
// inserted with an explicit 'Pending' by saveSalary(), so the column default is
// never relied upon afterwards; fresh installs create the column DEFAULT
// 'Pending' (manifest.php), where there are no legacy rows to grandfather.
++ $count;
$sql[$count][0] = '0.5.19';
$sql[$count][1] = "
ALTER TABLE kreckSalary ADD COLUMN IF NOT EXISTS approvalStatus ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Approved' AFTER status;end
ALTER TABLE kreckSalary ADD COLUMN IF NOT EXISTS category VARCHAR(90) NULL AFTER approvalStatus;end
ALTER TABLE kreckSalary ADD COLUMN IF NOT EXISTS tags VARCHAR(255) NULL AFTER category;end
ALTER TABLE kreckSalary ADD COLUMN IF NOT EXISTS gibbonPersonIDApprover INT(10) UNSIGNED ZEROFILL NULL AFTER tags;end
ALTER TABLE kreckSalary ADD COLUMN IF NOT EXISTS approvalTimestamp TIMESTAMP NULL DEFAULT NULL AFTER gibbonPersonIDApprover;end
ALTER TABLE kreckSalary ADD COLUMN IF NOT EXISTS approvalNote TEXT NULL AFTER approvalTimestamp;end
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Salary Approvals',2,'Payroll','Review salary definitions entered by finance and approve or reject them. A salary is only payroll-eligible once a school administrator approves it.','kreck_salary_approvals.php','kreck_salary_approvals.php','Y','Y','Y','N','N','N','N','N','N','N','N'
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonAction WHERE name='Salary Approvals' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'));end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 SELECT '001',(SELECT gibbonActionID FROM gibbonAction WHERE name='Salary Approvals' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'))
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonPermission p JOIN gibbonAction a ON a.gibbonActionID=p.gibbonActionID WHERE a.name='Salary Approvals' AND p.gibbonRoleID='001');end
";

// -> 0.5.20: KRECK "Add a lesson" stepped planner form. Adds the action and
// grants it to Staff-category roles (teachers + admins add lessons). Idempotent.
++ $count;
$sql[$count][0] = '0.5.20';
$sql[$count][1] = "
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'KRECK Add Lesson',0,'Learn','A guided, modern way to add a lesson or event to the calendar — pick the class, date and time, and it is saved to the planner (no approval).','kreck_planner_add.php','kreck_planner_add.php','Y','Y','Y','Y','N','N','N','Y','N','N','N'
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonAction WHERE name='KRECK Add Lesson' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'));end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 SELECT r.gibbonRoleID,(SELECT gibbonActionID FROM gibbonAction WHERE name='KRECK Add Lesson' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'))
 FROM gibbonRole r WHERE r.category='Staff'
 AND NOT EXISTS (SELECT 1 FROM gibbonPermission p WHERE p.gibbonRoleID=r.gibbonRoleID AND p.gibbonActionID=(SELECT gibbonActionID FROM gibbonAction WHERE name='KRECK Add Lesson' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
";

// -> 0.5.21: KRECK Institution Builder (guided in-app bulk entry). Adds the
// action, granted to Staff-category roles. Idempotent.
++ $count;
$sql[$count][0] = '0.5.21';
$sql[$count][1] = "
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Institution Builder',2,'Bridge','Build the whole institution in the app, step by step, in the right order — each step explains what it is and how it relates — then validate and import.','kreck_builder.php','kreck_builder.php','Y','Y','Y','N','N','N','N','Y','N','N','N'
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonAction WHERE name='Institution Builder' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'));end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 SELECT r.gibbonRoleID,(SELECT gibbonActionID FROM gibbonAction WHERE name='Institution Builder' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'))
 FROM gibbonRole r WHERE r.category='Staff'
 AND NOT EXISTS (SELECT 1 FROM gibbonPermission p WHERE p.gibbonRoleID=r.gibbonRoleID AND p.gibbonActionID=(SELECT gibbonActionID FROM gibbonAction WHERE name='Institution Builder' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
";

// -> 0.5.22: KRECK security hardening. Installing/updating KRECK now hardens the
// host SMS's own security posture (OWASP A05/A07): it rotates the previously
// shipped, publicly-known default password off its constant to a per-install
// random value (first-login change stays enforced), and strengthens the host
// password policy. Every statement only ever TIGHTENS and is idempotent, so a
// re-run or a stricter existing policy is never weakened.
++ $count;
$sql[$count][0] = '0.5.22';
$sql[$count][1] = "
UPDATE gibbonSetting SET value = CONCAT('Kr', UPPER(SUBSTRING(SHA2(CONCAT(RAND(),UUID(),NOW(6)),256),1,6)), SUBSTRING(SHA2(CONCAT(UUID(),RAND()),256),7,8), '#9a')
 WHERE scope='Kreck Bridge' AND name='defaultPassword' AND value='Kreck#2026';end
UPDATE gibbonSetting SET value='Y' WHERE scope='Kreck Bridge' AND name='forcePasswordChange' AND value<>'Y';end
UPDATE gibbonSetting SET value='Y' WHERE scope='System' AND name='passwordPolicyAlpha' AND value<>'Y';end
UPDATE gibbonSetting SET value='Y' WHERE scope='System' AND name='passwordPolicyNumeric' AND value<>'Y';end
UPDATE gibbonSetting SET value='Y' WHERE scope='System' AND name='passwordPolicyNonAlphaNumeric' AND value<>'Y';end
UPDATE gibbonSetting SET value='8' WHERE scope='System' AND name='passwordPolicyMinLength' AND CAST(value AS UNSIGNED) < 8;end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value)
 SELECT 'Kreck Bridge','securityHardenedAt','Security Hardened At','When installing/updating KRECK last applied host security hardening (default-password rotation + password policy). Shown on the Security Posture page.', NOW()
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='securityHardenedAt');end
UPDATE gibbonSetting SET value=NOW() WHERE scope='Kreck Bridge' AND name='securityHardenedAt';end
";

// -> 0.5.25: ensure the Shared Webhook Secret is never blank, so the LMS->SMS
// webhook can authenticate. Fills a per-install random 64-char hex value ONLY
// when the secret is empty; it never overwrites an existing secret. Hex has no
// whitespace and survives input sanitisation. The SMS then pushes this secret to
// the LMS from the connection check, so both sides share it with no manual copy.
++ $count;
$sql[$count][0] = '0.5.25';
$sql[$count][1] = "
UPDATE gibbonSetting SET value = SHA2(CONCAT(RAND(), UUID(), NOW(6)), 256)
 WHERE scope='Kreck Bridge' AND name='webhookSecret' AND (value='' OR value IS NULL);end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value)
 SELECT 'Kreck Bridge','webhookSecret','Shared Webhook Secret','Shared HMAC secret for LMS-to-SMS webhook authentication (auto-generated; pushed to the LMS on connection check).', SHA2(CONCAT(RAND(), UUID(), NOW(6)), 256)
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='webhookSecret');end
";

// -> 0.5.27: KRECK student↔parent rule. Registers the "Link Parent to Student"
// quick action and the "Students Without a Parent" review page (Staff-category),
// and defensively ensures the Parent role can view their children's invoices
// (Finance "View Invoices_myChildren") so a linked parent gets academics AND
// fees. All idempotent. The rule itself is passive (warn only) and lives in code.
++ $count;
$sql[$count][0] = '0.5.27';
$sql[$count][1] = "
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Students Without a Parent',1,'People','Review active students who are not linked to a parent/guardian (the KRECK rule, passively enforced) and link one.','kreck_students_noparent.php','kreck_students_noparent.php','Y','Y','Y','Y','N','N','N','Y','N','N','N'
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonAction WHERE name='Students Without a Parent' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'));end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 SELECT r.gibbonRoleID,(SELECT gibbonActionID FROM gibbonAction WHERE name='Students Without a Parent' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'))
 FROM gibbonRole r WHERE r.category='Staff'
 AND NOT EXISTS (SELECT 1 FROM gibbonPermission p WHERE p.gibbonRoleID=r.gibbonRoleID AND p.gibbonActionID=(SELECT gibbonActionID FROM gibbonAction WHERE name='Students Without a Parent' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Link Parent to Student',0,'People','Quickly associate a parent/guardian with a student — link an existing person or create a new parent account — reusing Gibbon families so the parent can access academics and fees.','kreck_link_parent.php','kreck_link_parent.php','Y','Y','Y','Y','N','N','N','Y','N','N','N'
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonAction WHERE name='Link Parent to Student' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'));end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 SELECT r.gibbonRoleID,(SELECT gibbonActionID FROM gibbonAction WHERE name='Link Parent to Student' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'))
 FROM gibbonRole r WHERE r.category='Staff'
 AND NOT EXISTS (SELECT 1 FROM gibbonPermission p WHERE p.gibbonRoleID=r.gibbonRoleID AND p.gibbonActionID=(SELECT gibbonActionID FROM gibbonAction WHERE name='Link Parent to Student' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 SELECT r.gibbonRoleID, a.gibbonActionID
 FROM gibbonRole r
 JOIN gibbonAction a ON a.name='View Invoices_myChildren' AND a.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Finance')
 WHERE r.category='Parent'
 AND NOT EXISTS (SELECT 1 FROM gibbonPermission p WHERE p.gibbonRoleID=r.gibbonRoleID AND p.gibbonActionID=a.gibbonActionID);end
";

// -> 0.5.31: register the admin-only "Seed Demo Data" page (a friendly, one-click
// wrapper around the demo seeder). Granted to the Administrator role only. Idempotent.
++ $count;
$sql[$count][0] = '0.5.31';
$sql[$count][1] = "
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Seed Demo Data',0,'Bridge','Populate this installation with a complete demo dataset (15 logins across every actor, plus timetable, lessons, attendance, grades, parents, activities, fees/invoices and payroll). Idempotent; administrators only.','kreck_demo_seed.php','kreck_demo_seed.php','Y','Y','Y','N','N','N','N','Y','N','N','N'
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonAction WHERE name='Seed Demo Data' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'));end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID)
 SELECT r.gibbonRoleID,(SELECT gibbonActionID FROM gibbonAction WHERE name='Seed Demo Data' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'))
 FROM gibbonRole r WHERE r.name='Administrator'
 AND NOT EXISTS (SELECT 1 FROM gibbonPermission p WHERE p.gibbonRoleID=r.gibbonRoleID AND p.gibbonActionID=(SELECT gibbonActionID FROM gibbonAction WHERE name='Seed Demo Data' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
";
