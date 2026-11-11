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
$version     = '0.1.00';        // gibbonModule.version is varchar(8).
$author      = 'KRECK MicroLearn';
$url         = 'https://krecke.example';

// --- Module settings (INSERT INTO gibbonSetting) ----------------------------
// Read back by the export engine. scope must equal the module name.
$gibbonSetting = [];
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'defaultPassword', 'Default Password', 'Starter password written into the manifest for every created Moodle account, so logins are usable on first import.', 'Kreck#2026');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'forcePasswordChange', 'Force Password Change', 'If Y, imported Moodle users must change their password at first login.', 'Y');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'schoolDomain', 'School Email Domain', 'Used to build deterministic placeholder emails (e.g. <studentID>@students.<domain>) when a person has no email, so the manifest still imports.', 'school.invalid');";
$gibbonSetting[] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('Kreck Bridge', 'moodleEndpoint', 'Moodle Endpoint URL', 'Optional. Base URL of the Kreck LMS (Moodle). Reserved for the future live-API leg; not used by the file export.', '');";

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

// No notification hooks for v1.
$hooks = [];
