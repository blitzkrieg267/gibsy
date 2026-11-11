<?php
/*
Kreck Bridge — Export to Kreck LMS.

Shows a readiness summary of the selected Gibbon school year, lets the admin
confirm/adjust, and downloads the JSON manifest for the Moodle (Kreck LMS)
local_kreck onboarding engine. Read-only against the Gibbon SIS.
*/

require_once __DIR__ . '/src/ManifestBuilder.php';

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_export.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

// Load module settings (scope = module name). $connection2 is a raw PDO handle.
$settings = [];
$sres = $connection2->prepare("SELECT name, value FROM gibbonSetting WHERE scope='Kreck Bridge'");
$sres->execute();
foreach ($sres->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    $settings[$r['name']] = $r['value'];
}

$builder = new KreckManifestBuilder($connection2, $settings);

$selectedYear = $_REQUEST['gibbonSchoolYearID'] ?? null;
$limitClasses = isset($_REQUEST['limitClasses']) ? (int) $_REQUEST['limitClasses'] : 0;

// ---- Download branch: stream the manifest and exit --------------------------
if (($_GET['download'] ?? '') === 'json') {
    $manifest = $builder->build([
        'gibbonSchoolYearID' => $selectedYear,
        'limitClasses'       => $limitClasses,
    ]);
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $sy = $builder->schoolYear($selectedYear);
    $fname = 'kreck_manifest_' . KreckManifestBuilder::slug($sy['name'] ?? 'export') . '.json';

    // Optional: log the batch (best-effort; never block the download).
    try {
        $counts = [
            'students'   => count($manifest['students'] ?? []),
            'users'      => count($manifest['users'] ?? []),
            'cohorts'    => count($manifest['cohorts'] ?? []),
            'courses'    => count($manifest['courses'] ?? []),
            'enrolments' => count($manifest['enrolments'] ?? []),
        ];
        $ins = $connection2->prepare(
            "INSERT INTO kreckBridgeExport (gibbonSchoolYearID, gibbonPersonID, timestamp, counts, filename)
             VALUES (:syid, :pid, NOW(), :counts, :fname)"
        );
        $ins->execute([
            'syid'   => $sy['gibbonSchoolYearID'] ?? null,
            'pid'    => $session->get('gibbonPersonID') ?? null,
            'counts' => json_encode($counts),
            'fname'  => $fname,
        ]);
    } catch (\Throwable $e) {
        // ignore logging failure
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $json;
    exit;
}

// ---- Page branch: readiness + options --------------------------------------
$page->breadcrumbs->add(__('Export to Kreck LMS'));

$readiness = $builder->readiness($selectedYear);
$years = $builder->schoolYears();
$c = $readiness['counts'] ?? ['students' => 0, 'staff' => 0, 'formGroups' => 0, 'classes' => 0, 'enrol' => 0];

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_export.php';

ob_start();
?>
<div class="kreck-export" style="max-width:860px">
    <p><?php echo __('This builds a JSON manifest of the selected school year and downloads it for import into the Kreck LMS (Moodle) via its KRECK onboarding engine. The Gibbon data is read only — nothing here is changed.'); ?></p>

    <h3><?php echo __('Readiness'); ?> — <?php echo htmlspecialchars($readiness['schoolYear'] ?? ''); ?></h3>
    <table class="w-full colorOddEven" cellspacing="0" style="margin-bottom:14px">
        <tr><td><b><?php echo __('Students'); ?></b></td><td><?php echo (int)$c['students']; ?></td>
            <td><b><?php echo __('Staff'); ?></b></td><td><?php echo (int)$c['staff']; ?></td></tr>
        <tr><td><b><?php echo __('Form Groups → Cohorts'); ?></b></td><td><?php echo (int)$c['formGroups']; ?></td>
            <td><b><?php echo __('Classes → Courses'); ?></b></td><td><?php echo (int)$c['classes']; ?></td></tr>
        <tr><td><b><?php echo __('Enrolments'); ?></b></td><td colspan="3"><?php echo (int)$c['enrol']; ?></td></tr>
    </table>

    <?php if (!empty($readiness['warnings'])): ?>
        <div class="warning" style="margin-bottom:14px">
            <b><?php echo __('These gaps will be auto-filled with Moodle-usable defaults:'); ?></b>
            <ul style="margin:6px 0 0 18px">
                <?php foreach ($readiness['warnings'] as $w): ?>
                    <li><?php echo htmlspecialchars($w); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="success" style="margin-bottom:14px"><?php echo __('No data gaps detected — this year is ready to export.'); ?></div>
    <?php endif; ?>

    <form method="get" action="<?php echo htmlspecialchars($self); ?>" style="border-top:1px solid #ddd;padding-top:14px">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_export.php">
        <table class="smallIntBorder w-full" cellspacing="0">
            <tr class="break"><td colspan="2"><h4 style="margin:0"><?php echo __('Export options'); ?></h4></td></tr>
            <tr>
                <td style="width:38%"><b><?php echo __('School Year'); ?></b></td>
                <td>
                    <select name="gibbonSchoolYearID" onchange="this.form.download.value='';this.form.submit();">
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo $y['gibbonSchoolYearID']; ?>"
                                <?php echo ((string)$y['gibbonSchoolYearID'] === (string)($readiness['_syid'] ?? $selectedYear) || ($selectedYear === null && $y['status'] === 'Current')) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($y['name']) . ($y['status'] === 'Current' ? ' ('.__('Current').')' : ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="emphasis small" style="display:block"><?php echo __('Changing this refreshes the readiness summary.'); ?></span>
                </td>
            </tr>
            <tr>
                <td><b><?php echo __('Limit classes (testing)'); ?></b></td>
                <td>
                    <input type="number" name="limitClasses" min="0" value="<?php echo $limitClasses ?: ''; ?>" placeholder="<?php echo __('0 = all classes'); ?>" style="width:120px">
                    <span class="emphasis small" style="display:block"><?php echo __('Cap the number of courses for a quick dry-run. Leave blank/0 to export everything.'); ?></span>
                </td>
            </tr>
        </table>
        <div class="linkTop" style="margin-top:12px">
            <input type="hidden" name="download" value="json">
            <input type="submit" value="<?php echo __('Download Manifest (.json)'); ?>" class="button" style="font-weight:bold">
        </div>
    </form>
</div>
<?php
echo ob_get_clean();
