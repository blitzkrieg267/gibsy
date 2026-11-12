<?php
/*
Kreck Bridge — Kreck LMS Marks.

Import the Moodle gradebook export (local/kreck/export_marks.php, JSON or CSV)
into kreckMark, keyed by the shared studentID + course shortname, and display
the stored marks per class. Gibbon is the record-keeper; marks flow one way,
LMS → SMS, at semester end (or any time). Re-importing updates in place.
*/

require_once __DIR__ . '/src/KreckLinkStore.php';

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_marks.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_marks.php';
$kreckAction = $_REQUEST['kreckAction'] ?? '';
$filterCourse = $_GET['course'] ?? '';

$page->breadcrumbs->add(__('Kreck LMS Marks'));

$store = new KreckLinkStore($connection2);

/** Parse an uploaded marks file (JSON {marks:[...]} or CSV) into assoc rows. */
$parseMarks = function (string $tmp, string $name): array {
    $raw = file_get_contents($tmp);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'json' || (ltrim((string) $raw)[0] ?? '') === '{') {
        $data = json_decode((string) $raw, true);
        if (is_array($data) && isset($data['marks']) && is_array($data['marks'])) {
            return $data['marks'];
        }
        if (is_array($data) && array_is_list($data)) {
            return $data;
        }
        return [];
    }
    // CSV: first row = headers (lowercased, spaces stripped).
    $rows = [];
    $fh = fopen($tmp, 'r');
    $headers = null;
    while (($line = fgetcsv($fh)) !== false) {
        if ($headers === null) {
            $headers = array_map(fn($h) => strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $h)), $line);
            continue;
        }
        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = $line[$i] ?? '';
        }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
};

$result = null;
$parseError = '';
if ($kreckAction === 'import' && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $rows = $parseMarks($_FILES['file']['tmp_name'], $_FILES['file']['name']);
    if (empty($rows)) {
        $parseError = __('No mark rows were found in the uploaded file.');
    } else {
        $result = $store->importMarks($rows);
    }
}

$courses = $store->markCourses();
$marks = $store->marks($filterCourse !== '' ? $filterCourse : null);

ob_start();
?>
<style>
.kreck-mk { max-width:1000px; }
.kreck-mk .card { background:#eef5f4; border-radius:8px; padding:14px 16px; margin-bottom:16px; }
.kreck-mk .kbtn { background:#0e9f9a; color:#fff; border:0; border-radius:6px; padding:8px 14px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
.kreck-mk .kbtn.ghost { background:#eef5f4; color:#0e6b63; }
.kreck-mk input[type=file] { padding:6px; border:1px solid #d9e1e8; border-radius:6px; background:#fff; }
.kreck-mk .ok { color:#157f3d; font-weight:700; }
.kreck-mk .err { color:#b42318; }
.kreck-mk ul.errs { color:#b42318; font-size:13px; }
.kreck-mk table.grid { border-collapse:collapse; width:100%; margin-top:8px; }
.kreck-mk table.grid th { background:#f4f7f9; padding:7px 10px; font-size:12px; text-align:left; border-bottom:2px solid #e2e8ee; }
.kreck-mk table.grid td { padding:6px 10px; border-bottom:1px solid #eef2f5; font-size:14px; }
.kreck-mk .filter { margin-bottom:10px; }
.kreck-mk .muted { color:#64748b; }
</style>
<div class="kreck-mk">
    <p><?php echo __('Import the gradebook export from the Kreck LMS (Moodle): its admin area → KRECK → "Export marks to KRECK SMS" → Download (JSON or CSV). Each learner\'s marks are matched to their school record by student ID and kept here. Re-importing refreshes the figures.'); ?></p>

    <?php if ($parseError !== '') { ?><p class="err"><?php echo htmlspecialchars($parseError); ?></p><?php } ?>

    <?php if ($result !== null) { ?>
        <div class="card">
            <p class="ok"><?php echo sprintf(__('Imported %s mark rows.'), (int) $result['imported']); ?></p>
            <?php if (!empty($result['errors'])) { ?>
                <ul class="errs"><?php foreach (array_slice($result['errors'], 0, 20) as $e) { echo '<li>' . htmlspecialchars($e) . '</li>'; } ?></ul>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="card">
        <form method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars($self . '&kreckAction=import'); ?>">
            <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_marks.php">
            <input type="hidden" name="kreckAction" value="import">
            <b><?php echo __('Marks file (JSON or CSV)'); ?>:</b>
            <input type="file" name="file" accept=".json,.csv,application/json,text/csv" required>
            &nbsp;<button class="kbtn" type="submit"><?php echo __('Import marks'); ?></button>
        </form>
    </div>

    <?php if (!empty($courses)) { ?>
        <form method="get" action="<?php echo htmlspecialchars($self); ?>" class="filter">
            <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_marks.php">
            <label><b><?php echo __('Class'); ?>:</b>
                <select name="course" onchange="this.form.submit()">
                    <option value=""><?php echo __('All classes'); ?></option>
                    <?php foreach ($courses as $c) {
                        $sel = $c === $filterCourse ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($c) . '" ' . $sel . '>' . htmlspecialchars($c) . '</option>';
                    } ?>
                </select>
            </label>
        </form>
    <?php } ?>

    <?php if (empty($marks)) { ?>
        <p class="muted"><?php echo __('No marks stored yet. Import a gradebook export above.'); ?></p>
    <?php } else { ?>
        <p class="emphasis small"><?php echo sprintf(__('%s mark rows.'), count($marks)); ?></p>
        <table class="grid">
            <thead><tr>
                <th><?php echo __('Student ID'); ?></th>
                <th><?php echo __('Class'); ?></th>
                <th><?php echo __('Item'); ?></th>
                <th><?php echo __('Grade'); ?></th>
                <th><?php echo __('Out of'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($marks as $m) { ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) $m['entityKey']); ?></td>
                    <td><?php echo htmlspecialchars((string) $m['courseShortname']); ?></td>
                    <td><?php echo htmlspecialchars((string) $m['item']); ?></td>
                    <td><?php echo htmlspecialchars((string) $m['grade']); ?></td>
                    <td><?php echo htmlspecialchars((string) ($m['gradeMax'] ?? '')); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</div>
<?php
echo ob_get_clean();
