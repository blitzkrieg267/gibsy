<?php
/*
Kreck Bridge — Export to Kreck LMS.

Shows a readiness summary of the selected Gibbon school year, lets the admin
confirm/adjust, and downloads the JSON manifest for the Moodle (Kreck LMS)
local_kreck onboarding engine. Read-only against the Gibbon SIS.
*/

// ===== KRECK in-page diagnostics (precise report instead of a generic error) =====
$kreckBaseOb = ob_get_level();
$kreckSteps = [];
$kstep = function ($label, $ok = true, $detail = '') use (&$kreckSteps) { $kreckSteps[] = [$ok, $label, $detail]; };
$kreckDiag = function (?\Throwable $e = null) use (&$kreckSteps) {
    $esc = fn ($s) => htmlspecialchars((string) $s);
    $files = ['kreck_export.php', 'kreck_import.php', 'kreck_import_institution.php', 'kreck_lms.php', 'kreck_marks.php',
        'kreck_import_links.php', 'kreck_settings.php', 'kreck_settingsProcess.php', 'manifest.php', 'version.php', 'CHANGEDB.php',
        'src/ManifestBuilder.php', 'src/InstitutionImporter.php', 'src/KreckLinkStore.php', 'src/SalaryManager.php'];
    $h = '<div style="max-width:960px;font-family:system-ui,-apple-system,sans-serif;margin-top:12px">';
    $h .= '<h3 style="color:#0e6b63;margin:0 0 10px">KRECK Export — diagnostics</h3>';
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

    if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_export.php') == false) {
        $page->addError(__('You do not have access to this action.'));
        return;
    }
    $kstep('Permission check passed');

    $kreckMbFile = __DIR__ . '/src/ManifestBuilder.php';
    if (!is_file($kreckMbFile)) {
        throw new \RuntimeException('Required file missing: src/ManifestBuilder.php — upload the module\'s src/ folder next to this page.');
    }
    require_once $kreckMbFile;
    $kstep('ManifestBuilder loaded', true, 'src/ManifestBuilder.php');

    // Load module settings (scope = module name). $connection2 is a raw PDO handle.
    $settings = [];
    $sres = $connection2->prepare("SELECT name, value FROM gibbonSetting WHERE scope='Kreck Bridge'");
    $sres->execute();
    foreach ($sres->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $settings[$r['name']] = $r['value'];
    }

    $builder = new KreckManifestBuilder($connection2, $settings);
    $kstep('Manifest builder ready');

    // Default to the school year that actually has data (so a freshly imported
    // Upcoming year is picked up, not an empty Current year).
    $selectedYear = $_REQUEST['gibbonSchoolYearID'] ?? null;
    if ($selectedYear === null || $selectedYear === '') {
        $selectedYear = $builder->defaultYearId();
        $kstep('School year auto-selected (year with data)', true, (string) $selectedYear);
    }
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
$kstep('Readiness computed', true, ($readiness['schoolYear'] ?? '') . ': ' . (int) ($readiness['counts']['students'] ?? 0) . ' students');
$years = $builder->schoolYears();
$c = $readiness['counts'] ?? ['students' => 0, 'staff' => 0, 'formGroups' => 0, 'classes' => 0, 'enrol' => 0];

// Hint when the shown year is not the Current year (e.g. data was imported into
// an Upcoming year) so the admin understands which year is being exported.
$currentYear = $builder->schoolYear(null);
$showingNonCurrent = !empty($currentYear['gibbonSchoolYearID'])
    && (string) $currentYear['gibbonSchoolYearID'] !== (string) ($readiness['_syid'] ?? $selectedYear);

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_export.php';

ob_start();
?>
<div class="kreck-export" style="max-width:860px">
    <p><?php echo __('This builds a JSON manifest of the selected school year and downloads it for import into the Kreck LMS (Moodle) via its KRECK onboarding engine. The Gibbon data is read only — nothing here is changed.'); ?></p>

    <h3><?php echo __('Readiness'); ?> — <?php echo htmlspecialchars($readiness['schoolYear'] ?? ''); ?></h3>
    <?php if ($showingNonCurrent): ?>
        <div class="message" style="margin-bottom:12px;background:#eef5f4;border:1px solid #b7ddd8;border-radius:8px;padding:10px 14px">
            <?php echo sprintf(
                __('Showing <b>%1$s</b> because that is where your data is. Your current school year is <b>%2$s</b>. Use the School Year selector below to switch.'),
                htmlspecialchars($readiness['schoolYear'] ?? ''),
                htmlspecialchars($currentYear['name'] ?? '')
            ); ?>
        </div>
    <?php endif; ?>
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
        . 'KRECK could not finish building this page. The step-by-step report below shows exactly where it stopped.'
        . '</div>';
    echo $kreckDiag($kreckE);
    return;
}
