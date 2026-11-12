<?php
/*
Kreck Bridge — Import Whole Institution (one workbook).

Download a multi-sheet KRECK template (one sheet per data type in dependency
order), fill it, and import the entire institution in a single action. All
sheets run inside ONE transaction via the native engine, so references across
sheets (e.g. a fee's category, a class's course) resolve, and a dry-run can
validate everything without persisting. Reuses Gibbon's import engine; no core edits.
*/

use Gibbon\Data\PasswordPolicy;
use Gibbon\Forms\PersonalDataFieldSettings;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Contracts\Database\Connection;

// ===== KRECK in-page diagnostics (precise report instead of a generic error) =====
// Shows how far the page got, what broke (message + file:line + trace), and which
// module files are present. Add &kreckDebug=1 to the URL for the full trace.
$kreckBaseOb = ob_get_level();
$kreckSteps = [];
$kstep = function ($label, $ok = true, $detail = '') use (&$kreckSteps) { $kreckSteps[] = [$ok, $label, $detail]; };
$kreckDiag = function (?\Throwable $e = null) use (&$kreckSteps) {
    $esc = fn ($s) => htmlspecialchars((string) $s);
    $files = ['kreck_import.php', 'kreck_import_institution.php', 'kreck_export.php', 'kreck_lms.php', 'kreck_marks.php',
        'kreck_import_links.php', 'kreck_settings.php', 'kreck_settingsProcess.php', 'manifest.php', 'version.php', 'CHANGEDB.php',
        'src/InstitutionImporter.php', 'src/ManifestBuilder.php', 'src/KreckLinkStore.php', 'src/SalaryManager.php',
        'imports/kreck_feeCategories.yml', 'imports/kreck_fees.yml'];
    $h = '<div style="max-width:960px;font-family:system-ui,-apple-system,sans-serif;margin-top:12px">';
    $h .= '<h3 style="color:#0e6b63;margin:0 0 10px">KRECK Import — diagnostics</h3>';
    $h .= '<ol style="line-height:1.7;padding-left:22px;margin:0 0 12px">';
    foreach ($kreckSteps as $s) {
        $h .= '<li>' . ($s[0] ? '&#9989;' : '&#10060;') . ' ' . $esc($s[1])
            . ($s[2] !== '' ? ' <span style="color:#64748b">&mdash; ' . $esc($s[2]) . '</span>' : '') . '</li>';
    }
    $h .= '</ol>';
    if ($e) {
        $h .= '<div style="background:#fff1f0;border:1px solid #ffa39e;border-radius:8px;padding:12px 14px">'
            . '<b style="color:#b42318">Stopped here: ' . $esc(get_class($e)) . '</b>'
            . '<div style="margin:6px 0;color:#7a271a;font-weight:600">' . $esc($e->getMessage()) . '</div>'
            . '<div style="color:#64748b;font-size:13px">' . $esc($e->getFile()) . ' : line ' . $e->getLine() . '</div>'
            . '<pre style="background:#fff;border:1px solid #eee;border-radius:6px;padding:8px;overflow:auto;font-size:12px;max-height:220px">'
            . $esc($e->getTraceAsString()) . '</pre></div>';
    }
    $h .= '<h4 style="margin:14px 0 6px">Module files on this server</h4>';
    $h .= '<ul style="columns:2;-webkit-columns:2;line-height:1.6;padding-left:18px;font-size:13px">';
    foreach ($files as $f) {
        $h .= '<li>' . (is_file(__DIR__ . '/' . $f) ? '&#9989;' : '&#10060; MISSING') . ' ' . $esc($f) . '</li>';
    }
    $h .= '</ul><p style="color:#64748b;font-size:13px">PHP ' . $esc(PHP_VERSION) . ' &middot; folder: ' . $esc(__DIR__) . '</p></div>';
    return $h;
};
$KRECK_DEBUG = isset($_REQUEST['kreckDebug']);

try {
    $kstep('Page loaded and running', true, basename(__FILE__));

    if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_import_institution.php') == false) {
        $page->addError(__('You do not have access to this action. An administrator must grant your role the "Import Institution Data" permission.'));
        return;
    }
    $kstep('Permission check passed');

    $kreckImpFile = __DIR__ . '/src/InstitutionImporter.php';
    if (!is_file($kreckImpFile)) {
        throw new \RuntimeException('Required file missing: src/InstitutionImporter.php — upload the module\'s src/ folder next to this page.');
    }
    require_once $kreckImpFile;
    $kstep('Importer class loaded', true, 'src/InstitutionImporter.php');

    // Resolve PersonalDataFieldSettings robustly across Gibbon versions: v31 has
    // it in the container; v30 does not, so build it from SettingGateway (its only
    // dependency), and fall back to null if the class is absent.
    $kreckSettingGateway = $container->get(SettingGateway::class);
    try {
        $kreckPdfs = $container->get(PersonalDataFieldSettings::class);
        $kstep('Personal-data field settings resolved', true, 'from container');
    } catch (\Throwable $pdfsEx) {
        if (class_exists(PersonalDataFieldSettings::class)) {
            $kreckPdfs = new PersonalDataFieldSettings($kreckSettingGateway);
            $kstep('Personal-data field settings resolved', true, 'built from SettingGateway (v30 fallback)');
        } else {
            $kreckPdfs = null;
            $kstep('Personal-data field settings not available — continuing without', true);
        }
    }

    $importer = new KreckInstitutionImporter(
        $container->get(Connection::class),
        $container->get(PasswordPolicy::class),
        $kreckPdfs,
        $kreckSettingGateway,
        __DIR__ . '/imports'
    );
    $kstep('Importer ready (database + Gibbon services resolved)');

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_import_institution.php';
$kreckAction = $_REQUEST['kreckAction'] ?? '';
$mode = $_REQUEST['mode'] ?? 'sync';

// ---- Template download (multi-sheet .xlsx) ---------------------------------
if ($kreckAction === 'template') {
    $tmp = tempnam(sys_get_temp_dir(), 'kreckwb') . '.xlsx';
    $importer->generateWorkbookFile($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="kreck_institution_template.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ---- Validate / Import whole workbook --------------------------------------
$result = null;
$parsedCounts = [];
$labelByType = [];
foreach ($importer->typeRegistry() as $e) $labelByType[$e['name']] = $e['label'];

if (in_array($kreckAction, ['validate', 'import'], true)
    && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $kstep('Reading uploaded workbook', true, (string) ($_FILES['file']['name'] ?? ''));
    $parsed = $importer->parseWorkbookSheets($_FILES['file']['tmp_name']);
    $parsedCounts = array_map('count', $parsed);
    $kstep('Workbook parsed', true, count($parsed) . ' recognised sheet(s)');
    $result = $importer->runWorkbook($parsed, $kreckAction === 'import', (int) $session->get('gibbonPersonID'), $mode);
    $kstep($kreckAction === 'import' ? 'Import run finished' : 'Dry-run finished', true, 'total errors: ' . (int) $result['totalErrors']);
}

$page->breadcrumbs
    ->add(__('Import Institution Data'), 'kreck_import.php')
    ->add(__('Whole Institution'));

ob_start();
?>
<style>
.kreck-wb .kbtn { background:#0e9f9a; color:#fff; border:0; border-radius:6px; padding:9px 16px; font-weight:600; cursor:pointer; }
.kreck-wb .kbtn.amber { background:#f59e0b; } .kreck-wb .kbtn.ghost { background:#eef5f4; color:#0e6b63; }
.kreck-wb .steps { counter-reset:step; margin:14px 0; }
.kreck-wb .step { padding:12px 14px; border:1px solid #e2e8ee; border-radius:8px; margin-bottom:10px; }
.kreck-wb .step h4 { margin:0 0 6px; } .kreck-wb .step h4::before { counter-increment:step; content:counter(step)". "; color:#0e9f9a; font-weight:800; }
.kreck-wb table.grid { border-collapse:collapse; width:100%; margin-top:8px; }
.kreck-wb table.grid th, .kreck-wb table.grid td { padding:6px 10px; border-bottom:1px solid #eef2f5; text-align:left; font-size:14px; }
.kreck-wb table.grid th { background:#f4f7f9; }
.kreck-wb .err { color:#b42318; } .kreck-wb .ok { color:#157f3d; font-weight:700; }
</style>
<div class="kreck-wb" style="max-width:1000px">
    <p><?php echo __('The institution hands over its data once. Download the KRECK workbook, fill one sheet per data type, and import it all in a single pass. Everything runs in one transaction, so references between sheets resolve and a dry-run validates the whole file without saving.'); ?></p>

    <div class="steps">
        <div class="step">
            <h4><?php echo __('Download the institution template'); ?></h4>
            <p class="emphasis small"><?php echo __('A multi-sheet Excel workbook with one sheet per type, in dependency order (Departments → Year/Form Groups → Courses → Classes → Staff → Students → Enrolment → Fees). Bold headers are required.'); ?></p>
            <a class="kbtn ghost" href="<?php echo htmlspecialchars($self . '&kreckAction=template'); ?>"><?php echo __('Download KRECK workbook (.xlsx)'); ?></a>
        </div>
        <div class="step">
            <h4><?php echo __('Fill it in'); ?></h4>
            <p class="emphasis small"><?php echo __('Enter the institution data under the matching sheet/columns. Leave sheets you are not using empty. You can also fill individual types in-app on the main Import page.'); ?></p>
        </div>
        <div class="step">
            <h4><?php echo __('Validate, then import'); ?></h4>
            <form method="post" action="<?php echo htmlspecialchars($self); ?>" enctype="multipart/form-data">
                <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_import_institution.php">
                <input type="file" name="file" accept=".xlsx,.xls,.ods" required>
                <label style="margin-left:10px"><?php echo __('Mode'); ?>:
                    <select name="mode">
                        <option value="sync"><?php echo __('Update &amp; Insert'); ?></option>
                        <option value="insert"><?php echo __('Insert only'); ?></option>
                        <option value="update"><?php echo __('Update only'); ?></option>
                    </select>
                </label>
                <div style="margin-top:10px; display:flex; gap:8px">
                    <button type="submit" class="kbtn" name="kreckAction" value="validate"><?php echo __('Validate whole workbook (dry-run)'); ?></button>
                    <button type="submit" class="kbtn amber" name="kreckAction" value="import" onclick="return confirm('<?php echo __('Import the whole institution now?'); ?>')"><?php echo __('Import institution'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($result !== null): ?>
        <h3><?php echo $result['committed'] ? __('Institution imported') : __('Validation (dry-run)'); ?></h3>
        <?php if (empty($parsedCounts)): ?>
            <div class="warning"><?php echo __('No recognised sheets found. Use the KRECK template so sheet names match the data types.'); ?></div>
        <?php else: ?>
            <p>
                <?php echo __('Total errors'); ?>: <b class="<?php echo $result['totalErrors'] === 0 ? 'ok' : 'err'; ?>"><?php echo (int) $result['totalErrors']; ?></b>
                &nbsp;|&nbsp; <?php echo __('Status'); ?>:
                <?php if ($result['committed']): ?><b class="ok"><?php echo __('Committed'); ?></b>
                <?php elseif ($result['ok']): ?><b class="ok"><?php echo __('Valid — ready to import'); ?></b>
                <?php else: ?><b class="err"><?php echo __('Rolled back (fix errors below)'); ?></b><?php endif; ?>
            </p>
            <table class="grid">
                <thead><tr><th><?php echo __('Sheet / Type'); ?></th><th><?php echo __('Rows'); ?></th><th><?php echo __('Errors'); ?></th><th><?php echo __('Warnings'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($result['perType'] as $typeName => $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($labelByType[$typeName] ?? $typeName); ?></td>
                        <td><?php echo (int) $r['rowCount']; ?></td>
                        <td class="<?php echo empty($r['errors']) ? 'ok' : 'err'; ?>"><?php echo count($r['errors']); ?></td>
                        <td><?php echo count($r['warnings']); ?></td>
                    </tr>
                    <?php foreach (array_slice($r['errors'], 0, 10) as $e): ?>
                        <tr><td></td><td class="err">row <?php echo htmlspecialchars((string) ($e['row'] ?? '')); ?></td>
                            <td colspan="2" class="err"><?php echo htmlspecialchars(($e['field_name'] ?? '') . ': ' . ($e['info'] ?? '')); ?></td></tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($result['ok'] && !$result['committed']): ?>
                <p class="success" style="margin-top:10px"><?php echo __('Everything validated. Press “Import institution” to commit.'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
    $kstep('Page rendered');
    echo ob_get_clean();
    if ($KRECK_DEBUG) {
        echo $kreckDiag(null);
    }
} catch (\Throwable $kreckE) {
    while (ob_get_level() > $kreckBaseOb) {
        ob_end_clean();
    }
    echo '<div class="error" style="margin-bottom:10px">'
        . 'KRECK could not finish loading this page. The step-by-step report below shows exactly where it stopped.'
        . '</div>';
    echo $kreckDiag($kreckE);
    return;
}
