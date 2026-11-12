<?php
/*
Kreck Bridge — a Gibbon "Additional" module that turns this Gibbon SIS into the
KRECK SMS and exports its data in the exact JSON manifest the Moodle-side
local_kreck onboarding engine consumes. No Gibbon core is modified; all behaviour
lives in this module (and the companion Kreck theme).

@package   Kreck Bridge
@license   GNU GPL v3 or later
*/

// This file is read by modules/System Admin/module_manage_installProcess.php.
// It must define the variables below (installer reads them verbatim).

$name        = 'Kreck Bridge';
$description = 'KRECK SMS↔LMS bridge: exports Gibbon students, staff, cohorts, courses and enrolments as a JSON manifest for the Moodle (Kreck LMS) local_kreck onboarding engine. Applies Moodle-usable defaults so every export imports cleanly.';
$entryURL    = 'kreck_export.php';
$type        = 'Additional';   // Installer rejects anything that is not 'Additional'.
$category    = 'Other';
$version     = '0.5.01';        // gibbonModule.version is varchar(8).
$author      = 'KRECK MicroLearn';
$url         = 'https://krecke.example';

// --- Module settings (INSERT INTO gibbonSetting) ----------------------------
// Read back by the export engine. scope must equal the module name.
$gibbonSetting = [];
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'defaultPassword', 'Default Password', 'Starter password written into the manifest for every created Moodle account, so logins are usable on first import.', 'Kreck#2026');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'forcePasswordChange', 'Force Password Change', 'If Y, imported Moodle users must change their password at first login.', 'Y');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'schoolDomain', 'School Email Domain', 'Used to build deterministic placeholder emails (e.g. <studentID>@students.<domain>) when a person has no email, so the manifest still imports.', 'school.invalid');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'moodleEndpoint', 'Moodle Endpoint URL', 'Optional. Base URL of the Kreck LMS (Moodle). Reserved for the future live-API leg; not used by the file export.', '');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'payrollOfficer', 'Payroll Officer', 'Optional. gibbonPersonID of the staff member nudged by the salary reminder CLI about due/overdue payments. Finance staff can also subscribe to the \"Salary Payment Due\" notification event.', '');";

// --- Tables this module owns (CREATE TABLE) ---------------------------------
// A small log of export batches. Keyed independently of Gibbon core tables.
$moduleTables = [];
$moduleTables[] = "CREATE TABLE `kreckBridgeExport` (
    `kreckBridgeExportID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
    `gibbonSchoolYearID` INT(3) UNSIGNED ZEROFILL NULL,
    `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NULL,
    `timestamp` DATETIME NULL,
    `counts` TEXT NULL,
    `filename` VARCHAR(255) NULL,
    PRIMARY KEY (`kreckBridgeExportID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
// Staff-salary record-keeping (recurring definition + per-period payment).
$moduleTables[] = "CREATE TABLE `kreckSalary` (
    `kreckSalaryID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
    `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonSchoolYearID` INT(3) UNSIGNED ZEROFILL NULL,
    `amount` DECIMAL(13,2) NOT NULL,
    `currency` VARCHAR(3) NULL,
    `frequency` ENUM('Monthly','Fortnightly','Weekly','Termly','Annually','Once') NOT NULL DEFAULT 'Monthly',
    `payDay` INT(2) NULL,
    `startDate` DATE NOT NULL,
    `endDate` DATE NULL,
    `status` ENUM('Active','Suspended','Ended') NOT NULL DEFAULT 'Active',
    `notes` TEXT NULL,
    `gibbonPersonIDCreator` INT(10) UNSIGNED ZEROFILL NULL,
    `timestampCreator` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`kreckSalaryID`), KEY `gibbonPersonID` (`gibbonPersonID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$moduleTables[] = "CREATE TABLE `kreckSalaryPayment` (
    `kreckSalaryPaymentID` INT(14) UNSIGNED ZEROFILL AUTO_INCREMENT,
    `kreckSalaryID` INT(12) UNSIGNED ZEROFILL NOT NULL,
    `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `period` VARCHAR(7) NOT NULL,
    `dueDate` DATE NULL,
    `amount` DECIMAL(13,2) NOT NULL,
    `status` ENUM('Pending','Finalized','Paid') NOT NULL DEFAULT 'Pending',
    `paymentDate` DATE NULL,
    `paymentMethod` ENUM('Cash','Cheque','Bank Transfer','Mobile Money','Other') NULL,
    `proofOfPayment` VARCHAR(255) NULL,
    `reminderCount` INT(2) NOT NULL DEFAULT 0,
    `notes` TEXT NULL,
    `gibbonPersonIDCreator` INT(10) UNSIGNED ZEROFILL NULL,
    `timestampCreator` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`kreckSalaryPaymentID`),
    UNIQUE KEY `salary_period` (`kreckSalaryID`,`period`),
    KEY `status` (`status`), KEY `gibbonPersonID` (`gibbonPersonID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
// Return-leg deep-links from the Kreck LMS (Moodle): one row per class (course)
// and per activity, stored relative to the Moodle base URL.
$moduleTables[] = "CREATE TABLE `kreckLink` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
// Flat record of Moodle gradebook marks, kept on the school record.
$moduleTables[] = "CREATE TABLE `kreckMark` (
    `kreckMarkID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
    `entityKey` VARCHAR(255) NOT NULL,
    `courseShortname` VARCHAR(255) NOT NULL,
    `item` VARCHAR(255) NOT NULL,
    `grade` VARCHAR(50) NULL,
    `gradeMax` VARCHAR(50) NULL,
    `moodleCourseID` INT(12) UNSIGNED NULL,
    `exportedAt` VARCHAR(40) NULL,
    `timestampUpdated` DATETIME NULL,
    PRIMARY KEY (`kreckMarkID`),
    UNIQUE KEY `student_item` (`entityKey`,`courseShortname`,`item`),
    KEY `courseShortname` (`courseShortname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

// --- Actions (INSERT INTO gibbonAction; permissions granted per flag) --------
$actionRows = [];
$actionRows[] = [
    'name'                      => 'Export to Kreck LMS',
    'precedence'                => 0,
    'category'                  => 'Bridge',
    'description'               => 'Build and download the JSON manifest of this school year for the Moodle (Kreck LMS) onboarding engine.',
    'URLList'                   => 'kreck_export.php',
    'entryURL'                  => 'kreck_export.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];
$actionRows[] = [
    'name'                      => 'Import Institution Data',
    'precedence'                => 1,
    'category'                  => 'Bridge',
    'description'               => 'Seed institution data into Gibbon via an in-app table or an Excel/CSV upload, reusing Gibbon\'s import engine (validation, preview, dedup).',
    'URLList'                   => 'kreck_import.php,kreck_import_institution.php',
    'entryURL'                  => 'kreck_import.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Manage Staff Salaries',
    'precedence'                => 0,
    'category'                  => 'Payroll',
    'description'               => 'Record recurring staff salary definitions (amount, frequency, pay day, active window).',
    'URLList'                   => 'kreck_salary.php',
    'entryURL'                  => 'kreck_salary.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];
$actionRows[] = [
    'name'                      => 'Payroll Run',
    'precedence'                => 1,
    'category'                  => 'Payroll',
    'description'               => 'Generate a period\'s salary payments, finalize them, and mark them paid with a proof of payment (receipt + staff notification).',
    'URLList'                   => 'kreck_salary_run.php,kreck_salary_receipt.php',
    'entryURL'                  => 'kreck_salary_run.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Kreck Settings',
    'precedence'                => 0,
    'category'                  => 'Bridge',
    'description'               => 'Edit the Kreck Bridge settings in-app: the Moodle Endpoint URL, export defaults and payroll officer.',
    'URLList'                   => 'kreck_settings.php,kreck_settingsProcess.php',
    'entryURL'                  => 'kreck_settings.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];
$actionRows[] = [
    'name'                      => 'Kreck LMS Classes',
    'precedence'                => 0,
    'category'                  => 'Bridge',
    'description'               => 'Import the Kreck LMS (Moodle) links file and browse each class and its activities as "Open in KRECK LMS" deep-links.',
    'URLList'                   => 'kreck_lms.php,kreck_import_links.php',
    'entryURL'                  => 'kreck_lms.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'Y',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];
$actionRows[] = [
    'name'                      => 'Kreck LMS Marks',
    'precedence'                => 1,
    'category'                  => 'Bridge',
    'description'               => 'Import the Kreck LMS (Moodle) gradebook export and keep each learner\'s marks on their school record.',
    'URLList'                   => 'kreck_marks.php',
    'entryURL'                  => 'kreck_marks.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'Y',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

// Notification events (in-app bell + email) for payroll. Finance staff subscribe
// via System Admin → Notification Settings; the mark-paid flow and reminder CLI raise them.
$hooks = [];
$hooks[] = "INSERT INTO gibbonNotificationEvent (event, moduleName, actionName, type, scopes, active) VALUES ('Salary Paid', 'Kreck Bridge', 'Payroll Run', 'Additional', 'gibbonPersonID', 'Y');";
$hooks[] = "INSERT INTO gibbonNotificationEvent (event, moduleName, actionName, type, scopes, active) VALUES ('Salary Payment Due', 'Kreck Bridge', 'Payroll Run', 'Additional', 'gibbonPersonID', 'Y');";

// KRECK branding: point the site logo + system name at KRECK, but only if still
// the Gibbon defaults — never clobber a school's own logo/name. (Rebrand is
// otherwise theme-driven: themes/Kreck css/js/img.)
$hooks[] = "UPDATE gibbonSetting SET value='themes/Kreck/img/logo.png' WHERE scope='System' AND name='organisationLogo' AND (value LIKE '%Default/img/logo%' OR value='' OR value IS NULL);";
$hooks[] = "UPDATE gibbonSetting SET value='KRECK' WHERE scope='System' AND name='systemName' AND value='Gibbon';";
