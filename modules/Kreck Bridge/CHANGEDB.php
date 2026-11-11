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
