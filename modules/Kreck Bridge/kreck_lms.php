<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Kreck LMS Classes.

Browse LMS classes and activities imported from the links file, and review
signed LMS change events. Links use the configured LMS base URL; change
events never modify SMS's SIS records automatically.
*/

require_once __DIR__ . '/src/KreckLinkStore.php';

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_lms.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$requiredTables = ['kreckLink', 'kreckSyncEvent'];
$tableCheck = $connection2->prepare(
    'SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = :tableName'
);
$missingTables = [];
foreach ($requiredTables as $tableName) {
    $tableCheck->execute(['tableName' => $tableName]);
    if ((int) $tableCheck->fetchColumn() === 0) {
        $missingTables[] = $tableName;
    }
}

if (!empty($missingTables)) {
    $exception = new \RuntimeException('Required tables are missing: ' . implode(', ', $missingTables));
    $reference = KreckBridgeDiagnostics::reportCaught($exception, 'LMS classes schema preflight');
    $page->addError(
        'KRECK LMS Classes cannot load because these tables are missing: '
        . implode(', ', $missingTables)
        . '. Go to System Admin > Manage Modules and update Kreck Bridge. Diagnostic reference: '
        . $reference
        . '.'
    );
    return;
}

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_lms.php';
$importURL = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_import_links.php';

$page->breadcrumbs->add(__('Kreck LMS Classes'));

$reviewMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['reviewEventID'])) {
    $eventId = (int) $_POST['reviewEventID'];
    $review = $connection2->prepare(
        "UPDATE kreckSyncEvent
         SET reviewStatus='Reviewed', gibbonPersonIDReviewer=:personID, reviewedAt=NOW()
         WHERE kreckSyncEventID=:eventID AND reviewStatus='Pending'"
    );
    $review->execute([
        'personID' => (int) ($session->get('gibbonPersonID') ?? 0),
        'eventID' => $eventId,
    ]);
    $reviewMessage = $review->rowCount() > 0
        ? __('Change marked as reviewed.')
        : __('That change was already reviewed or no longer exists.');
}

// LMS base URL for building absolute "Open in KRECK LMS" links.
$moodleBase = '';
$sres = $connection2->prepare("SELECT value FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='moodleEndpoint'");
$sres->execute();
$moodleBase = rtrim((string) ($sres->fetchColumn() ?: ''), '/');

$store = new KreckLinkStore($connection2);
$byClass = $store->linksByClass();
$eventQuery = $connection2->prepare(
    'SELECT kreckSyncEventID, eventType, entityType, entityID, action, payload, receivedAt, reviewStatus
     FROM kreckSyncEvent ORDER BY receivedAt DESC LIMIT 50'
);
$eventQuery->execute();
$syncEvents = $eventQuery->fetchAll(\PDO::FETCH_ASSOC) ?: [];
$pendingEvents = count(array_filter($syncEvents, static function ($event) {
    return $event['reviewStatus'] === 'Pending';
}));

/** Build an absolute LMS URL from a stored relative one. */
$abs = function (string $rel) use ($moodleBase) {
    if ($rel === '') return '';
    if (preg_match('#^https?://#i', $rel)) return $rel;
    return $moodleBase !== '' ? $moodleBase . $rel : $rel;
};

ob_start();
?>
<style>
.kreck-lms { max-width:1000px; }
.kreck-lms .bar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
.kreck-lms .kbtn { background:#0e9f9a; color:#fff; border:0; border-radius:6px; padding:8px 14px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
.kreck-lms .kbtn.ghost { background:#eef5f4; color:#0e6b63; }
.kreck-lms .warn { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:14px; }
.kreck-lms .cls { border:1px solid #e2e8ee; border-radius:10px; margin-bottom:14px; overflow:hidden; }
.kreck-lms .cls > .hd { background:#f4f7f9; padding:10px 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
.kreck-lms .cls > .hd .nm { font-weight:700; color:#0f2233; }
.kreck-lms .acts { list-style:none; margin:0; padding:0; }
.kreck-lms .acts li { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:7px 14px; border-top:1px solid #eef2f5; }
.kreck-lms .acts li .nm { color:#33475b; font-size:14px; }
.kreck-lms .acts li .mod { color:#8aa0b2; font-size:11px; text-transform:uppercase; letter-spacing:.04em; margin-left:8px; }
.kreck-lms a.open { color:#0e6b63; font-weight:600; font-size:13px; text-decoration:none; white-space:nowrap; }
.kreck-lms a.open:hover { text-decoration:underline; }
.kreck-lms .empty { color:#64748b; }
.kreck-lms .sync-inbox { margin:20px 0 24px; padding:16px; border:1px solid #d5e5e3; border-radius:10px; background:#f7fbfa; }
.kreck-lms .sync-event { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:10px 0; border-top:1px solid #e4eeec; }
.kreck-lms .sync-event:first-of-type { border-top:0; }
.kreck-lms .sync-event .meta { color:#536879; font-size:13px; }
.kreck-lms .sync-event form { margin:0; }
</style>
<div class="kreck-lms">
    <?php if ($reviewMessage !== ''): ?>
        <div class="<?php echo strpos($reviewMessage, 'already') === false ? 'success' : 'warning'; ?>" role="status"><?php echo htmlspecialchars($reviewMessage); ?></div>
    <?php endif; ?>
    <section class="sync-inbox" aria-labelledby="sync-inbox-title">
        <h3 id="sync-inbox-title"><?php echo __('LMS change inbox'); ?> (<?php echo $pendingEvents; ?> <?php echo __('pending review'); ?>)</h3>
        <p><?php echo __('LMS changes are recorded here for review. They do not automatically modify SMS records.'); ?></p>
        <?php if (empty($syncEvents)): ?>
            <p class="empty"><?php echo __('No LMS change events have been received.'); ?></p>
        <?php else: ?>
            <?php foreach ($syncEvents as $event): ?>
                <div class="sync-event">
                    <div class="meta">
                        <strong><?php echo htmlspecialchars(ucfirst((string) $event['action']) . ' ' . (string) $event['entityType']); ?></strong>
                        #<?php echo htmlspecialchars((string) $event['entityID']); ?>
                        <?php if (!empty($event['eventType'])): ?>
                            <span>— <?php echo htmlspecialchars((string) $event['eventType']); ?></span>
                        <?php endif; ?>
                        <br><?php echo htmlspecialchars((string) $event['receivedAt']); ?>
                        <span> · <?php echo htmlspecialchars((string) $event['reviewStatus']); ?></span>
                    </div>
                    <?php if ($event['reviewStatus'] === 'Pending'): ?>
                        <form method="post" action="<?php echo htmlspecialchars($self); ?>">
                            <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_lms.php">
                            <input type="hidden" name="reviewEventID" value="<?php echo (int) $event['kreckSyncEventID']; ?>">
                            <button type="submit" class="kbtn ghost"><?php echo __('Mark reviewed'); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <div class="bar">
        <span class="emphasis small"><?php echo sprintf(__('%s classes from the Kreck LMS.'), count($byClass)); ?></span>
        <span style="flex:1"></span>
        <a class="kbtn" href="<?php echo htmlspecialchars($importURL); ?>"><?php echo __('Import / refresh LMS links'); ?></a>
    </div>

    <?php if ($moodleBase === '') { ?>
        <div class="warn"><?php echo __('No Kreck LMS base URL is set, so links below are shown relative. Set it in System Admin → Third Party / module settings: "Kreck Bridge" → LMS Endpoint URL, to make them click-through.'); ?></div>
    <?php } ?>

    <?php if (empty($byClass)) { ?>
        <p class="empty"><?php echo __('No LMS classes yet. Export the links file from LMS and import it here to get started.'); ?></p>
    <?php } else { ?>
        <?php foreach ($byClass as $shortname => $data) {
            $courseRel = $data['course']['moodleRelURL'] ?? '';
            $courseURL = $abs((string) $courseRel);
        ?>
            <div class="cls">
                <div class="hd">
                    <span class="nm"><?php echo htmlspecialchars($shortname); ?>
                        <span class="mod"><?php echo sprintf(__('%s activities'), count($data['activities'])); ?></span>
                    </span>
                    <?php if ($courseURL !== '') { ?>
                        <a class="open" href="<?php echo htmlspecialchars($courseURL); ?>" target="_blank" rel="noopener"><?php echo __('Open class in KRECK LMS ↗'); ?></a>
                    <?php } ?>
                </div>
                <?php if (!empty($data['activities'])) { ?>
                    <ul class="acts">
                        <?php foreach ($data['activities'] as $a) {
                            $aURL = $abs((string) $a['moodleRelURL']);
                        ?>
                            <li>
                                <span class="nm"><?php echo htmlspecialchars((string) $a['label']); ?><span class="mod"><?php echo htmlspecialchars((string) $a['modName']); ?></span></span>
                                <?php if ($aURL !== '') { ?>
                                    <a class="open" href="<?php echo htmlspecialchars($aURL); ?>" target="_blank" rel="noopener"><?php echo __('Open in KRECK LMS ↗'); ?></a>
                                <?php } ?>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>
        <?php } ?>
    <?php } ?>
</div>
<?php
echo ob_get_clean();
