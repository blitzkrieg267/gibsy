<?php
/*
Kreck Bridge — database change log, read by
modules/System Admin/module_manage_updateProcess.php on module update.
Each entry: $sql[$count][0] = fromVersion; $sql[$count][1] = statements (split on ;end).
*/

$count = 0;
$sql = [];

// (No upgrade steps yet; 0.1.00 is the initial release.)
// Template for future changes:
// ++$count;
// $sql[$count][0] = '0.1.00';
// $sql[$count][1] = "ALTER TABLE `kreckBridgeExport` ADD `note` TEXT NULL;end";
