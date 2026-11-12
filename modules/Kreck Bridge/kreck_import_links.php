<?php
/*
Kreck Bridge — Import Kreck LMS Links.

Upload the links.moodle JSON produced by the Moodle side
(local/kreck/export_links.php) and store it in kreckLink, so each class and its
activities can be opened directly in the Kreck LMS from Gibbon. Relative URLs
are stored; the Moodle base URL (gibbonSetting 'Kreck Bridge'.moodleEndpoint) is
prepended at display time.
*/

require_once __DIR__ . '/src/KreckLinkStore.php';

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_import_links.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_import_links.php';
$lmsURL = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_lms.php';
$kreckAction = $_REQUEST['kreckAction'] ?? '';

$page->breadcrumbs
    ->add(__('Kreck LMS Classes'), 'kreck_lms.php')
    ->add(__('Import LMS Links'));

$store = new KreckLinkStore($connection2);

$result = null;
$parseError = '';
if ($kreckAction === 'import' && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $raw = file_get_contents($_FILES['file']['tmp_name']);
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
        $parseError = __('The uploaded file is not valid JSON.');
    } elseif (($payload['source'] ?? '') !== 'moodle' && !isset($payload['courses'])) {
        $parseError = __('This does not look like a Kreck LMS links file (missing "courses").');
    } else {
        $result = $store->importMoodleLinks($payload);
    }
}

ob_start();
?>
<style>
.kreck-lms { max-width:900px; }
.kreck-lms .card { background:#eef5f4; border-radius:8px; padding:14px 16px; margin-bottom:16px; }
.kreck-lms .kbtn { background:#0e9f9a; color:#fff; border:0; border-radius:6px; padding:8px 14px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
.kreck-lms .kbtn.ghost { background:#eef5f4; color:#0e6b63; }
.kreck-lms input[type=file] { padding:6px; border:1px solid #d9e1e8; border-radius:6px; background:#fff; }
.kreck-lms .ok { color:#157f3d; font-weight:700; }
.kreck-lms .err { color:#b42318; }
.kreck-lms ul.errs { color:#b42318; font-size:13px; }
</style>
<div class="kreck-lms">
    <p><?php echo __('Upload the links file exported from the Kreck LMS (Moodle): its admin area → KRECK → "Export LMS links to KRECK SMS" → Download. It lists every class this school provisioned and the activities inside them, so you can open them directly in the LMS from here.'); ?></p>

    <?php if ($parseError !== '') { ?>
        <p class="err"><?php echo htmlspecialchars($parseError); ?></p>
    <?php } ?>

    <?php if ($result !== null) { ?>
        <div class="card">
            <p class="ok"><?php echo sprintf(__('Imported %1$s classes and %2$s activities.'), (int) $result['courses'], (int) $result['activities']); ?></p>
            <?php if (!empty($result['errors'])) { ?>
                <ul class="errs">
                    <?php foreach (array_slice($result['errors'], 0, 20) as $e) { echo '<li>' . htmlspecialchars($e) . '</li>'; } ?>
                </ul>
            <?php } ?>
            <a class="kbtn" href="<?php echo htmlspecialchars($lmsURL); ?>"><?php echo __('View Kreck LMS classes →'); ?></a>
        </div>
    <?php } ?>

    <div class="card">
        <form method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars($self . '&kreckAction=import'); ?>">
            <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_import_links.php">
            <input type="hidden" name="kreckAction" value="import">
            <p><b><?php echo __('Links file (JSON)'); ?>:</b></p>
            <input type="file" name="file" accept="application/json,.json" required>
            &nbsp;<button class="kbtn" type="submit"><?php echo __('Import links'); ?></button>
            <a class="kbtn ghost" style="margin-left:8px" href="<?php echo htmlspecialchars($lmsURL); ?>"><?php echo __('Back to classes'); ?></a>
        </form>
    </div>
</div>
<?php
echo ob_get_clean();
