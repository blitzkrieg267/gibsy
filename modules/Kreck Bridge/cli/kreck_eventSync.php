<?php
/*
Kreck Bridge — SMS → LMS event sync CLI (module-contained).

Pushes new / changed / removed SMS events (class periods, lessons, closures, term dates, calendar events,
activities, duty, cover, assessments) to the LMS calendar. Idempotent — run it as often as you like; a run
with nothing to do sends nothing. A rolling window (14 days back, 90 days ahead) is synchronised.

Server shell (classic cron, every 5 minutes — minute field "every 5"):
    0,5,10,15,20,25,30,35,40,45,50,55 * * * * php "/path/to/gibbon/modules/Kreck Bridge/cli/kreck_eventSync.php"
Options:  --force  re-send everything in the window      --dry-run  show what would be sent
Web-cron (shared hosting), using the System Admin remote CLI key:
    https://your-gibbon/modules/Kreck%20Bridge/cli/kreck_eventSync.php?remoteCLIKey=KEY

Needs an HTTPS LMS endpoint and token in Kreck Settings.
*/

require __DIR__ . '/../../../gibbon.php';          // modules/Kreck Bridge/cli -> gibbon root
require_once __DIR__ . '/../src/EventSync.php';

use Gibbon\Domain\System\SettingGateway;

$settingGateway = $container->get(SettingGateway::class);
$remoteCLIKey = $settingGateway->getSettingByScope('System Admin', 'remoteCLIKey');
$remoteCLIKeyInput = $_GET['remoteCLIKey'] ?? null;

if (!(isCommandLineInterface() || ($remoteCLIKey != '' && $remoteCLIKey == $remoteCLIKeyInput))) {
    print __('This script cannot be run from a browser, only via CLI.');
    return;
}

$settings = [];
foreach ($connection2->query("SELECT name, value FROM gibbonSetting WHERE scope='Kreck Bridge'")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    $settings[$r['name']] = $r['value'];
}

$args = $argv ?? [];
$sync = new KreckEventSync($connection2, $settings);
$result = $sync->run([
    'trigger' => 'cron',
    'force'   => in_array('--force', $args, true) || (($_GET['force'] ?? '') === 'Y'),
    'dryrun'  => in_array('--dry-run', $args, true),
]);

echo sprintf(
    "Event sync %s — window %s: collected %d, sent %d (created %d, updated %d, unchanged %d, deleted %d, skipped %d, failed %d)%s\n",
    $result['ok'] ? 'OK' : 'FAILED', $result['window'], $result['collected'], $result['sent'], $result['created'],
    $result['updated'], $result['unchanged'], $result['deleted'], $result['skipped'], $result['failed'],
    $result['error'] ? ' — ' . $result['error'] : ''
);
exit($result['ok'] ? 0 : 1);
