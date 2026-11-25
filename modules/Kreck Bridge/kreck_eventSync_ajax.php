<?php
/*
Kreck Bridge — event sync endpoint (AJAX, JSON).

Called by the KRECK Home panel:
  mode=auto  quietly top up the LMS calendar when the last run is older than 5 minutes (throttled)
  mode=now   run immediately ("Sync events now")
Needs the same permission as "Export to Kreck LMS".
*/

require_once __DIR__ . '/../../gibbon.php';
require_once __DIR__ . '/src/EventSync.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_export.php') == false) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'ran' => false, 'message' => 'Not allowed.']);
    return;
}

$settings = [];
foreach ($connection2->query("SELECT name, value FROM gibbonSetting WHERE scope='Kreck Bridge'")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    $settings[$r['name']] = $r['value'];
}
if (stripos((string) ($settings['moodleEndpoint'] ?? ''), 'https://') !== 0 || trim((string) ($settings['moodleToken'] ?? '')) === '') {
    echo json_encode(['ok' => false, 'ran' => false, 'message' => 'Set an HTTPS LMS endpoint and token in Kreck Settings to sync events.',
        'status' => KreckEventSync::status($connection2)]);
    return;
}

$mode = ($_POST['mode'] ?? 'auto') === 'now' ? 'now' : 'auto';
KreckEventSync::ensureTables($connection2);
if ($mode === 'auto') {
    $recent = $connection2->query("SELECT COUNT(*) FROM kreckEventRun WHERE started > (NOW() - INTERVAL 5 MINUTE)")->fetchColumn();
    if ((int) $recent > 0) {
        echo json_encode(['ok' => true, 'ran' => false, 'message' => 'Recently synced.', 'status' => KreckEventSync::status($connection2)]);
        return;
    }
}

@set_time_limit(120);
$sync = new KreckEventSync($connection2, $settings);
$result = $sync->run(['trigger' => $mode === 'now' ? 'manual' : 'page', 'maxBatches' => $mode === 'now' ? 20 : 5]);
echo json_encode(['ok' => $result['ok'], 'ran' => true, 'message' => $result['ok']
        ? sprintf('Synced: %d new, %d updated, %d removed.', $result['created'], $result['updated'], $result['deleted'])
        : ($result['error'] ?: 'The sync did not complete.'),
    'summary' => $result, 'status' => KreckEventSync::status($connection2)]);
