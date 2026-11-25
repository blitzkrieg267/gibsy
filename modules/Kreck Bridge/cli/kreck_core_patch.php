<?php
/**
 * Kreck Bridge — manage the core patch that puts KRECK Home on the main Home page.
 *
 *   php "modules/Kreck Bridge/cli/kreck_core_patch.php" status
 *   php "modules/Kreck Bridge/cli/kreck_core_patch.php" apply     (also run this after a Gibbon upgrade)
 *   php "modules/Kreck Bridge/cli/kreck_core_patch.php" revert
 *
 * Needs no database: it only edits src/UI/Dashboard/StaffDashboard.php under the Gibbon root.
 */
if (PHP_SAPI !== 'cli') { exit("Run from the command line.\n"); }
require_once __DIR__ . '/../src/CorePatch.php';

$cmd = $argv[1] ?? 'status';
if ($cmd === 'apply')  { $r = KreckCorePatch::apply();  echo ($r['ok'] ? 'OK: ' : 'FAILED: ') . $r['message'] . "\n"; }
if ($cmd === 'revert') { $r = KreckCorePatch::revert(); echo ($r['ok'] ? 'OK: ' : 'FAILED: ') . $r['message'] . "\n"; }
foreach (KreckCorePatch::status() as $row) {
    echo sprintf("%-12s %-10s %s%s\n", $row['id'], $row['state'], $row['file'], $row['message'] ? ' — ' . $row['message'] : '');
}
exit(isset($r) && !$r['ok'] ? 1 : 0);
