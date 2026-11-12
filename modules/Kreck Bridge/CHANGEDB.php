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
  'Seed institution data into Gibbon via an in-app table or an Excel/CSV upload.',
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
// (only if still the Gibbon defaults, so a school's own branding is preserved).
++$count;
$sql[$count][0] = '0.3.00';
$sql[$count][1] = "
UPDATE gibbonSetting SET value='themes/Kreck/img/logo.png' WHERE scope='System' AND name='organisationLogo' AND (value LIKE '%Default/img/logo%' OR value='' OR value IS NULL);end
UPDATE gibbonSetting SET value='KRECK' WHERE scope='System' AND name='systemName' AND value='Gibbon';end
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

// 0.5.00 -> 0.5.01: in-app Settings page (edit Moodle Endpoint URL + defaults
// without touching the DB). Also back-fill any setting rows missing on older
// installs, so the Settings form always has them.
++$count;
$sql[$count][0] = '0.5.00';
$sql[$count][1] = "
INSERT INTO gibbonAction (gibbonModuleID,name,precedence,category,description,URLList,entryURL,entrySidebar,menuShow,defaultPermissionAdmin,defaultPermissionTeacher,defaultPermissionStudent,defaultPermissionParent,defaultPermissionSupport,categoryPermissionStaff,categoryPermissionStudent,categoryPermissionParent,categoryPermissionOther)
 VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge'),'Kreck Settings',0,'Bridge','Edit the Kreck Bridge settings in-app: Moodle Endpoint URL, export defaults and payroll officer.','kreck_settings.php,kreck_settingsProcess.php','kreck_settings.php','Y','Y','Y','N','N','N','N','Y','N','N','N');end
INSERT INTO gibbonPermission (gibbonRoleID,gibbonActionID) VALUES ('001',(SELECT gibbonActionID FROM gibbonAction WHERE name='Kreck Settings' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')));end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','moodleEndpoint','Moodle Endpoint URL','Base URL of the Kreck LMS (Moodle).','' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='moodleEndpoint');end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','defaultPassword','Default Password','Starter password written into the manifest for created Moodle accounts.','Kreck#2026' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='defaultPassword');end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','forcePasswordChange','Force Password Change','If Y, imported Moodle users must change their password at first login.','Y' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='forcePasswordChange');end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','schoolDomain','School Email Domain','Used to build placeholder emails when a person has no email.','school.invalid' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='schoolDomain');end
INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value) SELECT 'Kreck Bridge','payrollOfficer','Payroll Officer','Optional gibbonPersonID nudged by the salary reminder about due/overdue payments.','' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='payrollOfficer');end
";
