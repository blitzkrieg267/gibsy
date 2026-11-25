<?php
/*
Kreck Bridge — a SMS "Additional" module that turns this SMS SIS into the
KRECK SMS and exports its data in the exact JSON manifest the LMS-side
local_kreck onboarding engine consumes. No SMS core is modified; all behaviour
lives in this module (and the companion Kreck theme).

@package   Kreck Bridge
@license   GNU GPL v3 or later
*/

// This file is read by modules/System Admin/module_manage_installProcess.php.
// It must define the variables below (installer reads them verbatim).

$name        = 'Kreck Bridge';
$description = 'KRECK SMS↔LMS bridge: synchronizes institution manifests, manages teacher-entered final marks and approvals, records LMS change notifications, and supports LMS links and marks.';
$entryURL    = 'kreck_home.php';
$type        = 'Additional';   // Installer rejects anything that is not 'Additional'.
$category    = 'Other';
$version     = '0.5.34';        // gibbonModule.version is varchar(8).
$author      = 'KRECK MicroLearn';
$url         = 'https://krecke.example';

// --- Module settings (INSERT INTO gibbonSetting) ----------------------------
// Read back by the export engine. scope must equal the module name.
$gibbonSetting = [];
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'defaultPassword', 'Default Password', 'Per-install RANDOM starter password for every created LMS account (change-on-first-login is enforced), so logins are usable on first import without shipping a known constant. Rotate it anytime in Kreck Settings.', CONCAT('Kr', UPPER(SUBSTRING(SHA2(CONCAT(RAND(),UUID(),NOW(6)),256),1,6)), SUBSTRING(SHA2(CONCAT(UUID(),RAND()),256),7,8), '#9a'));";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'forcePasswordChange', 'Force Password Change', 'If Y, imported LMS users must change their password at first login.', 'Y');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'schoolDomain', 'School Email Domain', 'Used to build deterministic placeholder emails (e.g. <studentID>@students.<domain>) when a person has no email, so the manifest still imports.', 'school.invalid');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'moodleEndpoint', 'LMS Endpoint URL', 'Base URL of the Kreck LMS, used for API sync and deep links.', '');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'moodleToken', 'LMS Web Service Token', 'Restricted LMS token for local_kreck_sync_manifest and local_kreck_ping; keep secret.', '');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'webhookSecret', 'Shared Webhook Secret', 'Shared HMAC secret used to authenticate LMS change notifications.', '');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'gibbonHeartbeatAt', 'Last SMS-to-LMS Heartbeat', 'Timestamp of the last successful authenticated LMS connection check.', '0');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'moodleHeartbeatAt', 'Last LMS-to-SMS Heartbeat', 'Timestamp of the last successful signed LMS connection check.', '0');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'payrollOfficer', 'Payroll Officer', 'Optional. gibbonPersonID of the staff member nudged by the salary reminder CLI about due/overdue payments. Finance staff can also subscribe to the \"Salary Payment Due\" notification event.', '');";

// --- Tables this module owns (CREATE TABLE) ---------------------------------
// A small log of export batches. Keyed independently of SMS core tables.
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
// SMS -> LMS event mirror: what has been sent (content hash per event) + recent runs.
$moduleTables[] = "CREATE TABLE `kreckEventLedger` (
    `sourceID` VARCHAR(190) NOT NULL,
    `kind` VARCHAR(30) NOT NULL DEFAULT '',
    `eventDate` DATE NULL,
    `contentHash` CHAR(40) NOT NULL,
    `status` ENUM('Synced','Pending','Skipped','Failed') NOT NULL DEFAULT 'Pending',
    `message` VARCHAR(255) NULL,
    `timestampSynced` DATETIME NULL,
    `timestampUpdated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`sourceID`), KEY `eventDate` (`eventDate`), KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$moduleTables[] = "CREATE TABLE `kreckEventRun` (
    `kreckEventRunID` INT UNSIGNED AUTO_INCREMENT,
    `started` DATETIME NOT NULL,
    `finished` DATETIME NULL,
    `trigger` VARCHAR(20) NOT NULL DEFAULT 'manual',
    `ok` ENUM('Y','N') NOT NULL DEFAULT 'N',
    `summary` TEXT NULL,
    PRIMARY KEY (`kreckEventRunID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
// Staff-salary record-keeping (recurring definition + per-period payment).
$moduleTables[] = "CREATE TABLE `kreckSalary` (
    `kreckSalaryID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
    `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonSchoolYearID` INT(3) UNSIGNED ZEROFILL NULL,
    `amount` DECIMAL(13,2) NOT NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'BWP',
    `frequency` ENUM('Monthly','Fortnightly','Weekly','Termly','Annually','Once') NOT NULL DEFAULT 'Monthly',
    `payDay` INT(2) NULL,
    `startDate` DATE NOT NULL,
    `endDate` DATE NULL,
    `status` ENUM('Active','Suspended','Ended') NOT NULL DEFAULT 'Active',
    `approvalStatus` ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `category` VARCHAR(90) NULL,
    `tags` VARCHAR(255) NULL,
    `gibbonPersonIDApprover` INT(10) UNSIGNED ZEROFILL NULL,
    `approvalTimestamp` TIMESTAMP NULL DEFAULT NULL,
    `approvalNote` TEXT NULL,
    `notes` TEXT NULL,
    `gibbonPersonIDCreator` INT(10) UNSIGNED ZEROFILL NULL,
    `timestampCreator` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`kreckSalaryID`), KEY `gibbonPersonID` (`gibbonPersonID`), KEY `approvalStatus` (`approvalStatus`)
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
// Return-leg deep-links from the Kreck LMS: one row per class (course)
// and per activity, stored relative to the LMS base URL.
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
// Flat record of LMS gradebook marks, kept on the school record.
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
// LMS-originated changes are recorded for staff review; SMS data is never
// changed automatically by webhook delivery.
$moduleTables[] = "CREATE TABLE `kreckSyncEvent` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
// SMS-owned semester assessment sheets; legacy LMS imports remain in kreckMark.
$moduleTables[] = "CREATE TABLE `kreckAssessment` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$moduleTables[] = "CREATE TABLE `kreckAssessmentMark` (
    `kreckAssessmentMarkID` INT(12) UNSIGNED ZEROFILL AUTO_INCREMENT,
    `kreckAssessmentID` INT(12) UNSIGNED ZEROFILL NOT NULL,
    `gibbonPersonIDStudent` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `ca1Mark` DECIMAL(5,2) NULL,
    `ca1Source` ENUM('Manual','LMS') NOT NULL DEFAULT 'Manual',
    `ca1SourceRef` VARCHAR(255) NULL,
    `ca2Mark` DECIMAL(5,2) NULL,
    `ca2Source` ENUM('Manual','LMS') NOT NULL DEFAULT 'Manual',
    `ca2SourceRef` VARCHAR(255) NULL,
    `examMark` DECIMAL(5,2) NULL,
    `examSource` ENUM('Manual','LMS') NOT NULL DEFAULT 'Manual',
    `examSourceRef` VARCHAR(255) NULL,
    `finalMark` DECIMAL(5,2) NULL,
    `comment` TEXT NULL,
    `gibbonPersonIDLastEdit` INT(10) UNSIGNED ZEROFILL NULL,
    `timestampUpdated` DATETIME NOT NULL,
    PRIMARY KEY (`kreckAssessmentMarkID`),
    UNIQUE KEY `assessment_student` (`kreckAssessmentID`,`gibbonPersonIDStudent`),
    KEY `student_assessment` (`gibbonPersonIDStudent`,`kreckAssessmentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$moduleTables[] = "CREATE TABLE `kreckAssessmentAudit` (
    `kreckAssessmentAuditID` INT(14) UNSIGNED ZEROFILL AUTO_INCREMENT,
    `kreckAssessmentID` INT(12) UNSIGNED ZEROFILL NOT NULL,
    `gibbonPersonIDStudent` INT(10) UNSIGNED ZEROFILL NULL,
    `action` VARCHAR(32) NOT NULL,
    `beforeSnapshot` LONGTEXT NULL,
    `afterSnapshot` LONGTEXT NULL,
    `gibbonPersonIDActor` INT(10) UNSIGNED ZEROFILL NULL,
    `timestampCreated` DATETIME NOT NULL,
    PRIMARY KEY (`kreckAssessmentAuditID`),
    KEY `assessment_time` (`kreckAssessmentID`,`timestampCreated`),
    KEY `student_time` (`gibbonPersonIDStudent`,`timestampCreated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$moduleTables[] = "CREATE TABLE `kreckAssessmentNotification` (
    `kreckAssessmentNotificationID` INT(14) UNSIGNED ZEROFILL AUTO_INCREMENT,
    `eventKey` VARCHAR(100) NOT NULL,
    `kreckAssessmentID` INT(12) UNSIGNED ZEROFILL NOT NULL,
    `gibbonPersonIDRecipient` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `notificationType` VARCHAR(32) NOT NULL,
    `message` TEXT NOT NULL,
    `actionLink` VARCHAR(500) NOT NULL,
    `status` ENUM('Pending','Sent','Failed','Cancelled') NOT NULL DEFAULT 'Pending',
    `attempts` INT(4) NOT NULL DEFAULT 0,
    `nextAttempt` DATETIME NOT NULL,
    `lastError` TEXT NULL,
    `timestampCreated` DATETIME NOT NULL,
    `timestampSent` DATETIME NULL,
    PRIMARY KEY (`kreckAssessmentNotificationID`),
    UNIQUE KEY `event_recipient` (`eventKey`,`gibbonPersonIDRecipient`),
    KEY `delivery_queue` (`status`,`nextAttempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$moduleTables[] = "CREATE TABLE `kreckLmsAssignmentPlan` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

// --- Actions (INSERT INTO gibbonAction; permissions granted per flag) --------
$actionRows = [];
$actionRows[] = [
    'name'                      => 'KRECK Home',
    'precedence'                => 1,
    'category'                  => 'Bridge',
    'description'               => 'A modern KRECK home page: live institution stats, KRECK LMS connection status and quick actions into the bridge.',
    'URLList'                   => 'kreck_home.php',
    'entryURL'                  => 'kreck_home.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'Y',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'Y',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];
$actionRows[] = [
    'name'                      => 'Export to Kreck LMS',
    'precedence'                => 0,
    'category'                  => 'Bridge',
    'description'               => 'Preview the mapped LMS impact, synchronize the school-year manifest, or download its JSON form.',
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
    'description'               => 'Seed institution data into SMS via an in-app table or an Excel/CSV upload, reusing the SMS import engine (validation, preview, dedup).',
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
    // Guided in-app "fill the workbook" builder: the same data as the Excel
    // import, entered step by step in dependency order, committed through the
    // same engine (runWorkbook).
    'name'                      => 'Institution Builder',
    'precedence'                => 2,
    'category'                  => 'Bridge',
    'description'               => 'Build the whole institution in the app, step by step, in the right order — each step explains what it is and how it relates — then validate and import.',
    'URLList'                   => 'kreck_builder.php',
    'entryURL'                  => 'kreck_builder.php',
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
    // Live "probe" import: ask the LMS for a learner's full gradebook on demand
    // (no export/upload), display it, and optionally save it to the record.
    'name'                      => 'Pull Marks from LMS',
    'precedence'                => 3,
    'category'                  => 'Bridge',
    'description'               => 'Ask the connected LMS directly for a learner\'s complete gradebook — every course, assignment, quiz and the course total, with grade, %, letter and feedback — and optionally save the figures to the school record.',
    'URLList'                   => 'kreck_lms_marks.php',
    'entryURL'                  => 'kreck_lms_marks.php',
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
    // Admin-only one-click demo seeder: 15 logins + one-of-everything.
    'name'                      => 'Seed Demo Data',
    'precedence'                => 0,
    'category'                  => 'Bridge',
    'description'               => 'Populate this installation with a complete demo dataset (15 logins across every actor, plus timetable, lessons, attendance, grades, parents, activities, fees/invoices and payroll). Idempotent; administrators only.',
    'URLList'                   => 'kreck_demo_seed.php',
    'entryURL'                  => 'kreck_demo_seed.php',
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
    // KRECK rule: review students not linked to a parent/guardian (passive warn).
    'name'                      => 'Students Without a Parent',
    'precedence'                => 1,
    'category'                  => 'People',
    'description'               => 'Review active students who are not linked to a parent/guardian (the KRECK rule, passively enforced) and link one.',
    'URLList'                   => 'kreck_students_noparent.php',
    'entryURL'                  => 'kreck_students_noparent.php',
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
    // Quick "Link Parent to Student" — link existing person or create a new parent.
    'name'                      => 'Link Parent to Student',
    'precedence'                => 0,
    'category'                  => 'People',
    'description'               => 'Quickly associate a parent/guardian with a student — link an existing person or create a new parent account — reusing Gibbon families so the parent can access academics and fees.',
    'URLList'                   => 'kreck_link_parent.php',
    'entryURL'                  => 'kreck_link_parent.php',
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
    // School-admin approval queue. Finance enters salaries (Manage Staff
    // Salaries, Staff-wide); only a SCHOOL ADMINISTRATOR may approve them here,
    // so this action is granted to the built-in Administrator role ONLY
    // (categoryPermissionStaff='N' keeps it off the general staff grant).
    'name'                      => 'Salary Approvals',
    'precedence'                => 2,
    'category'                  => 'Payroll',
    'description'               => 'Review salary definitions entered by finance and approve or reject them. A salary is only payroll-eligible once a school administrator approves it.',
    'URLList'                   => 'kreck_salary_approvals.php',
    'entryURL'                  => 'kreck_salary_approvals.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'N',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Kreck Settings',
    'precedence'                => 0,
    'category'                  => 'Bridge',
    'description'               => 'Edit the LMS endpoint/token, shared webhook secret, export defaults and payroll officer.',
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
    'description'               => 'Review LMS change events and browse classes and activities as "Open in KRECK LMS" deep-links.',
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
    'description'               => 'Import raw LMS activity marks. Enter and approve official semester finals under Assessment Mark Entry.',
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
$actionRows[] = [
    'name'                      => 'Assessment Mark Entry',
    'precedence'                => 0,
    'category'                  => 'Assessment',
    'description'               => 'Enter and submit CA1, CA2 and final exam marks for assigned classes.',
    'URLList'                   => 'kreck_assessments.php',
    'entryURL'                  => 'kreck_assessments.php',
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
    'name'                      => 'Review Assessment Marks',
    'precedence'                => 1,
    'category'                  => 'Assessment',
    'description'               => 'Review submitted class mark sheets, approve results or request corrections.',
    'URLList'                   => 'kreck_assessment_review.php',
    'entryURL'                  => 'kreck_assessment_review.php',
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
    'name'                      => 'Assessment Reports',
    'precedence'                => 2,
    'category'                  => 'Assessment',
    'description'               => 'View and export approved semester marks for yearly reporting.',
    'URLList'                   => 'kreck_assessment_reports.php',
    'entryURL'                  => 'kreck_assessment_reports.php',
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
    // KRECK stepped "Add a lesson" form. Writes a standard gibbonPlannerEntry so
    // the lesson shows on the SMS calendar like a native one. Teaching staff.
    'name'                      => 'KRECK Add Lesson',
    'precedence'                => 0,
    'category'                  => 'Learn',
    'description'               => 'A guided, modern way to add a lesson or event to the calendar — pick the class, date and time, and it is saved to the planner (no approval).',
    'URLList'                   => 'kreck_planner_add.php',
    'entryURL'                  => 'kreck_planner_add.php',
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
// the SMS defaults — never clobber a school's own logo/name. (Rebrand is
// otherwise theme-driven: themes/Kreck css/js/img.)
$hooks[] = "UPDATE gibbonSetting SET value='themes/Kreck/img/logo.png' WHERE scope='System' AND name='organisationLogo' AND (value LIKE '%Default/img/logo%' OR value='' OR value IS NULL);";
$hooks[] = "UPDATE gibbonSetting SET value='KRECK' WHERE scope='System' AND name='systemName' AND value='SMS';";
// BWP is the product-wide default currency: set the school currency to Botswana Pula while it is still the stock default.
$hooks[] = "UPDATE gibbonSetting SET value='BWP P' WHERE scope='System' AND name='currency' AND (value IS NULL OR TRIM(value)='' OR value='USD $');";
