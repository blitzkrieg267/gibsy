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
