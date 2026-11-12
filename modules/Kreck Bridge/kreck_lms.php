<?php
/*
Kreck Bridge — Kreck LMS Classes.

Browse the classes (and their activities) that live in the Kreck LMS (Moodle),
as imported from the links file. Each class and activity is an "Open in KRECK
LMS" deep-link, built from the stored relative URL + the Moodle base URL
(gibbonSetting 'Kreck Bridge'.moodleEndpoint). This is the SMS-side view of the
return leg; the pipeline always starts at Gibbon.
*/

require_once __DIR__ . '/src/KreckLinkStore.php';

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_lms.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_lms.php';
$importURL = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_import_links.php';

$page->breadcrumbs->add(__('Kreck LMS Classes'));

// Moodle base URL for building absolute "Open in KRECK LMS" links.
$moodleBase = '';
$sres = $connection2->prepare("SELECT value FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='moodleEndpoint'");
$sres->execute();
$moodleBase = rtrim((string) ($sres->fetchColumn() ?: ''), '/');

$store = new KreckLinkStore($connection2);
$byClass = $store->linksByClass();

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
</style>
<div class="kreck-lms">
    <div class="bar">
        <span class="emphasis small"><?php echo sprintf(__('%s classes from the Kreck LMS.'), count($byClass)); ?></span>
        <span style="flex:1"></span>
        <a class="kbtn" href="<?php echo htmlspecialchars($importURL); ?>"><?php echo __('Import / refresh LMS links'); ?></a>
    </div>

    <?php if ($moodleBase === '') { ?>
        <div class="warn"><?php echo __('No Kreck LMS (Moodle) base URL is set, so links below are shown relative. Set it in System Admin → Third Party / module settings: "Kreck Bridge" → Moodle Endpoint URL, to make them click-through.'); ?></div>
    <?php } ?>

    <?php if (empty($byClass)) { ?>
        <p class="empty"><?php echo __('No LMS classes yet. Export the links file from Moodle and import it here to get started.'); ?></p>
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
