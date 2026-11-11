<?php
/*
Kreck Bridge — Import Institution Data.

A KRECK-branded importer that reuses Gibbon's native import engine
(Gibbon\Data\Importer) underneath. Two ways in, as the client asked:
  (1) upload an Excel/CSV file (incl. legacy sheets), and
  (2) an inbuilt HTML table/form to fill data manually in the app.
Both validate (dry-run) with per-row errors and commit safely (dedup/sync).
*/

require_once __DIR__ . '/src/InstitutionImporter.php';

use Gibbon\Data\PasswordPolicy;
use Gibbon\Forms\PersonalDataFieldSettings;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Contracts\Database\Connection;

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_import.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$importer = new KreckInstitutionImporter(
    $container->get(Connection::class),
    $container->get(PasswordPolicy::class),
    $container->get(PersonalDataFieldSettings::class),
    $container->get(SettingGateway::class),
    __DIR__ . '/imports'
);

$registry = $importer->typeRegistry();
$type = $_REQUEST['type'] ?? 'kreck_feeCategories';
$kreckAction = $_REQUEST['kreckAction'] ?? '';
$mode = $_REQUEST['mode'] ?? 'sync';
$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_import.php';

$importType = $importer->loadType($type);
$cols = $importType ? $importer->describeFields($importType) : [];
$entry = $importer->registryEntry($type);
$typeLabel = $entry['label'] ?? $type;

// ---- Template download (blank CSV with this type's column labels) -----------
if ($kreckAction === 'template' && $importType) {
    $labels = array_map(fn($c) => $c['label'], $cols);
    $fname = 'kreck_template_' . preg_replace('/[^A-Za-z0-9]+/', '_', $type) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo implode(',', array_map(fn($l) => '"' . str_replace('"', '""', $l) . '"', $labels)) . "\r\n";
    exit;
}

// ---- Run (validate = dry-run, import = commit) ------------------------------
$result = null;
$rows = [];
$unmatched = [];
if (in_array($kreckAction, ['validate', 'import'], true) && $importType) {
    $live = ($kreckAction === 'import');
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $parsed = $importer->parseUploadedFile($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        $matched = $importer->matchToFields($importType, $parsed['headers'], $parsed['rows']);
        $rows = $matched['rows'];
        foreach ($matched['mapping'] as $h => $f) {
            if ($f === null && $h !== '') $unmatched[] = $h;
        }
    } else {
        $rows = json_decode($_POST['rowsJson'] ?? '[]', true) ?: [];
    }
    $result = $importer->run($type, $rows, $live, (int) $session->get('gibbonPersonID'), $mode);
}

$page->breadcrumbs->add(__('Import Institution Data'));

ob_start();
?>
<style>
.kreck-imp .tabs { margin:0 0 14px; border-bottom:2px solid #e2e8ee; }
.kreck-imp .tab { display:inline-block; padding:8px 16px; cursor:pointer; font-weight:600; color:#48596b; }
.kreck-imp .tab.active { border-bottom:3px solid #0e9f9a; color:#0f2233; }
.kreck-imp .pane { display:none; } .kreck-imp .pane.active { display:block; }
.kreck-imp .gridwrap { overflow-x:auto; border:1px solid #e2e8ee; border-radius:8px; }
.kreck-imp table.grid { border-collapse:collapse; width:100%; }
.kreck-imp table.grid th { background:#f4f7f9; padding:6px 8px; font-size:12px; text-align:left; white-space:nowrap; border-bottom:1px solid #e2e8ee; }
.kreck-imp table.grid th .req { color:#d64545; }
.kreck-imp table.grid td { padding:2px; border-bottom:1px solid #eef2f5; }
.kreck-imp table.grid input { width:100%; min-width:120px; border:1px solid #d9e1e8; border-radius:4px; padding:5px 6px; }
.kreck-imp .row-del { color:#d64545; cursor:pointer; font-weight:700; padding:0 8px; }
.kreck-imp .kbtn { background:#0e9f9a; color:#fff; border:0; border-radius:6px; padding:8px 14px; font-weight:600; cursor:pointer; }
.kreck-imp .kbtn.ghost { background:#eef5f4; color:#0e6b63; }
.kreck-imp .kbtn.amber { background:#f59e0b; }
.kreck-imp .results { margin-top:16px; }
.kreck-imp .err { color:#b42318; }
.kreck-imp .ok { color:#157f3d; font-weight:700; }
</style>
<div class="kreck-imp" style="max-width:1000px">
    <p><?php echo __('Seed the institution into Gibbon. Fill the table in-app, or upload an Excel/CSV file. KRECK validates every row (dry-run), shows errors, and commits safely — re-running will not duplicate existing records.'); ?></p>
    <div style="margin:0 0 14px; padding:10px 14px; background:#eef5f4; border-radius:8px; display:flex; align-items:center; gap:10px; flex-wrap:wrap">
        <b><?php echo __('Have everything at once?'); ?></b>
        <span class="emphasis small" style="flex:1"><?php echo __('Import the whole institution from a single multi-sheet workbook (all types, one atomic pass).'); ?></span>
        <a class="kbtn" style="text-decoration:none" href="<?php echo htmlspecialchars($session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_import_institution.php'); ?>"><?php echo __('Whole-institution import →'); ?></a>
    </div>

    <form method="get" action="<?php echo htmlspecialchars($self); ?>" style="margin-bottom:10px">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_import.php">
        <label><b><?php echo __('Data type'); ?>:</b>
            <select name="type" onchange="this.form.submit()">
                <?php
                $grp = '';
                foreach ($registry as $e) {
                    if ($e['group'] !== $grp) {
                        if ($grp !== '') echo '</optgroup>';
                        echo '<optgroup label="' . htmlspecialchars($e['group']) . '">';
                        $grp = $e['group'];
                    }
                    $sel = $e['name'] === $type ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($e['name']) . '" ' . $sel . '>' . htmlspecialchars($e['label']) . '</option>';
                }
                if ($grp !== '') echo '</optgroup>';
                ?>
            </select>
        </label>
        &nbsp; <span class="emphasis small"><?php echo count($cols); ?> <?php echo __('columns'); ?></span>
        &nbsp; <a class="kbtn ghost" href="<?php echo htmlspecialchars($self . '&type=' . urlencode($type) . '&kreckAction=template'); ?>"><?php echo __('Download blank template'); ?></a>
    </form>

    <div class="tabs">
        <span class="tab active" data-pane="manual"><?php echo __('Manual entry'); ?></span>
        <span class="tab" data-pane="file"><?php echo __('Upload file'); ?></span>
    </div>

    <!-- Manual entry pane: inbuilt HTML table -->
    <div class="pane active" id="pane-manual">
        <form method="post" action="<?php echo htmlspecialchars($self . '&type=' . urlencode($type)); ?>" id="manualForm">
            <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_import.php">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
            <input type="hidden" name="mode" value="sync">
            <input type="hidden" name="rowsJson" id="rowsJson">
            <div class="gridwrap">
                <table class="grid" id="grid">
                    <thead><tr>
                        <?php foreach ($cols as $c): ?>
                            <th title="<?php echo htmlspecialchars($c['desc']); ?>">
                                <?php echo htmlspecialchars($c['label']); ?><?php echo $c['required'] ? '<span class="req">*</span>' : ''; ?>
                            </th>
                        <?php endforeach; ?>
                        <th></th>
                    </tr></thead>
                    <tbody id="gridBody">
                        <?php
                        $seed = (!empty($rows)) ? $rows : [[]];
                        foreach ($seed as $r):
                        ?>
                            <tr>
                                <?php foreach ($cols as $c): ?>
                                    <td><input data-field="<?php echo htmlspecialchars($c['field']); ?>" value="<?php echo htmlspecialchars($r[$c['field']] ?? ''); ?>"></td>
                                <?php endforeach; ?>
                                <td><span class="row-del" title="<?php echo __('Remove'); ?>">&times;</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px; display:flex; gap:8px; align-items:center">
                <button type="button" class="kbtn ghost" id="addRow">+ <?php echo __('Add row'); ?></button>
                <span style="flex:1"></span>
                <button type="submit" class="kbtn" name="kreckAction" value="validate"><?php echo __('Validate (dry-run)'); ?></button>
                <button type="submit" class="kbtn amber" name="kreckAction" value="import" onclick="return confirm('<?php echo __('Commit these rows to Gibbon?'); ?>')"><?php echo __('Import'); ?></button>
            </div>
        </form>
    </div>

    <!-- File upload pane -->
    <div class="pane" id="pane-file">
        <form method="post" action="<?php echo htmlspecialchars($self . '&type=' . urlencode($type)); ?>" enctype="multipart/form-data">
            <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_import.php">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
            <p><?php echo __('Upload .csv, .xlsx, .xls or .ods. Column headers are matched to fields by name (case-insensitive). Use the blank template above for a guaranteed match.'); ?></p>
            <input type="file" name="file" accept=".csv,.xlsx,.xls,.ods" required>
            <label style="margin-left:10px"><?php echo __('Mode'); ?>:
                <select name="mode">
                    <option value="sync"><?php echo __('Update &amp; Insert'); ?></option>
                    <option value="insert"><?php echo __('Insert only'); ?></option>
                    <option value="update"><?php echo __('Update only'); ?></option>
                </select>
            </label>
            <div style="margin-top:10px; display:flex; gap:8px">
                <button type="submit" class="kbtn" name="kreckAction" value="validate"><?php echo __('Validate (dry-run)'); ?></button>
                <button type="submit" class="kbtn amber" name="kreckAction" value="import"><?php echo __('Import'); ?></button>
            </div>
        </form>
    </div>

    <?php if ($result !== null): ?>
        <div class="results">
            <h3><?php echo $result['live'] ? __('Import result') : __('Validation (dry-run)'); ?> — <?php echo htmlspecialchars($typeLabel); ?></h3>
            <?php if (!empty($unmatched)): ?>
                <div class="warning"><?php echo __('Ignored columns (no matching field):'); ?> <?php echo htmlspecialchars(implode(', ', $unmatched)); ?></div>
            <?php endif; ?>
            <p>
                <?php echo __('Rows processed'); ?>: <b><?php echo (int) $result['rowCount']; ?></b> &nbsp;|&nbsp;
                <?php echo __('Errors'); ?>: <b class="<?php echo empty($result['errors']) ? 'ok' : 'err'; ?>"><?php echo count($result['errors']); ?></b> &nbsp;|&nbsp;
                <?php echo __('Warnings'); ?>: <b><?php echo count($result['warnings']); ?></b>
            </p>
            <?php if ($result['ok'] && !$result['live']): ?>
                <div class="success"><?php echo __('All rows valid. Press Import to commit.'); ?></div>
            <?php elseif ($result['ok'] && $result['live']): ?>
                <div class="success"><?php echo __('Import committed successfully.'); ?></div>
            <?php endif; ?>
            <?php if (!empty($result['errors']) || !empty($result['warnings'])): ?>
                <div class="gridwrap" style="margin-top:8px"><table class="grid">
                    <thead><tr><th><?php echo __('Row'); ?></th><th><?php echo __('Field'); ?></th><th><?php echo __('Message'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach (array_merge($result['errors'], $result['warnings']) as $l): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($l['row'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($l['field_name'] ?? '')); ?></td>
                            <td class="<?php echo ($l['type'] ?? '') === 'error' ? 'err' : ''; ?>"><?php echo htmlspecialchars((string) ($l['info'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var cols = <?php echo json_encode(array_map(fn($c) => $c['field'], $cols)); ?>;
    // Tabs
    document.querySelectorAll('.kreck-imp .tab').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.kreck-imp .tab').forEach(x => x.classList.remove('active'));
            document.querySelectorAll('.kreck-imp .pane').forEach(x => x.classList.remove('active'));
            t.classList.add('active');
            document.getElementById('pane-' + t.dataset.pane).classList.add('active');
        });
    });
    // Add row
    var body = document.getElementById('gridBody');
    document.getElementById('addRow').addEventListener('click', function () {
        var tr = document.createElement('tr');
        var html = '';
        cols.forEach(function (f) { html += '<td><input data-field="' + f + '"></td>'; });
        html += '<td><span class="row-del" title="remove">&times;</span></td>';
        tr.innerHTML = html;
        body.appendChild(tr);
    });
    // Remove row (event delegation)
    body.addEventListener('click', function (e) {
        if (e.target.classList.contains('row-del')) {
            var rows = body.querySelectorAll('tr');
            if (rows.length > 1) e.target.closest('tr').remove();
            else e.target.closest('tr').querySelectorAll('input').forEach(i => i.value = '');
        }
    });
    // Serialize on submit
    document.getElementById('manualForm').addEventListener('submit', function () {
        var out = [];
        body.querySelectorAll('tr').forEach(function (tr) {
            var row = {}, any = false;
            tr.querySelectorAll('input[data-field]').forEach(function (inp) {
                row[inp.dataset.field] = inp.value;
                if (inp.value.trim() !== '') any = true;
            });
            if (any) out.push(row);
        });
        document.getElementById('rowsJson').value = JSON.stringify(out);
    });
})();
</script>
<?php
echo ob_get_clean();
