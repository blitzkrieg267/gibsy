<?php
require_once __DIR__ . '/src/Diagnostics.php';
require_once __DIR__ . '/src/Ui.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Import Whole Institution (one workbook).

Download a multi-sheet KRECK template (one sheet per data type in dependency
order), fill it, and import the entire institution in a single action. All
sheets run inside ONE transaction via the native engine, so references across
sheets (e.g. a fee's category, a class's course) resolve, and a dry-run can
validate everything without persisting. Reuses SMS's import engine; no core edits.
*/

require_once __DIR__ . '/src/InstitutionImporter.php';

use Gibbon\Data\PasswordPolicy;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Contracts\Database\Connection;

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_import_institution.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$importer = new KreckInstitutionImporter(
    $container->get(Connection::class),
    $container->get(PasswordPolicy::class),
    $container->get(SettingGateway::class),
    __DIR__ . '/imports'
);

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_import_institution.php';
$kreckAction = $_REQUEST['kreckAction'] ?? '';
$mode = $_REQUEST['mode'] ?? 'sync';
$progressDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kreck-bridge-import-progress';
$progressPath = static function (string $token) use ($progressDirectory): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new \InvalidArgumentException('The import progress token is invalid.');
    }
    return $progressDirectory . DIRECTORY_SEPARATOR . $token . '.json';
};
$ensureProgressDirectory = static function () use ($progressDirectory): void {
    if (!is_dir($progressDirectory) && !mkdir($progressDirectory, 0700, true) && !is_dir($progressDirectory)) {
        throw new \RuntimeException('Unable to create the private import progress directory.');
    }
    if (!chmod($progressDirectory, 0700)) {
        throw new \RuntimeException('Unable to restrict access to the private import progress directory.');
    }
    if (!is_writable($progressDirectory)) {
        throw new \RuntimeException('The private import progress directory is not writable.');
    }
};
$writeProgress = static function (string $token, array $state) use ($ensureProgressDirectory, $progressPath): void {
    $ensureProgressDirectory();
    $state['updatedAt'] = time();
    $json = json_encode($state, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new \RuntimeException('Unable to encode import progress: ' . json_last_error_msg());
    }
    $targetPath = $progressPath($token);
    $temporaryPath = tempnam(dirname($targetPath), 'kreck-progress-');
    if ($temporaryPath === false) {
        throw new \RuntimeException('Unable to create a temporary import progress file.');
    }
    if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
        if (!unlink($temporaryPath)) {
            error_log('[KRECK-IMPORT] Unable to remove a failed temporary progress file.');
        }
        throw new \RuntimeException('Unable to save import progress.');
    }
    if (!rename($temporaryPath, $targetPath)) {
        if (!unlink($temporaryPath)) {
            error_log('[KRECK-IMPORT] Unable to remove an uncommitted temporary progress file.');
        }
        throw new \RuntimeException('Unable to publish import progress.');
    }
};
$readProgress = static function (string $token) use ($progressPath): ?array {
    $path = $progressPath($token);
    if (!is_file($path)) {
        return null;
    }
    $json = file_get_contents($path);
    if ($json === false) {
        throw new \RuntimeException('Unable to read import progress.');
    }
    $state = json_decode($json, true);
    if (!is_array($state)) {
        throw new \RuntimeException('Import progress is unreadable: ' . json_last_error_msg());
    }
    return $state;
};

if ($kreckAction === 'progress') {
    $progressToken = trim((string) ($_REQUEST['progressToken'] ?? ''));
    $state = $readProgress($progressToken);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $response = $state === null
        ? ['state' => 'pending', 'percent' => 0, 'message' => __('Starting import...')]
        : [
            'state' => $state['state'] ?? 'running',
            'percent' => (int) ($state['percent'] ?? 0),
            'message' => (string) ($state['message'] ?? ''),
            'error' => (string) ($state['error'] ?? ''),
        ];
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

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

$resultToken = trim((string) ($_GET['progressResult'] ?? ''));
if ($resultToken !== '') {
    $state = $readProgress($resultToken);
    if (($state['state'] ?? '') === 'done' && isset($state['result'])) {
        $result = $state['result'];
        $parsedCounts = $state['parsedCounts'] ?? [];
    } elseif (($state['state'] ?? '') === 'error') {
        $result = ['ok' => false, 'committed' => false, 'error' => $state['error'] ?? __('Import failed.'), 'perType' => [], 'totalErrors' => 1];
    }
    $resultPath = $progressPath($resultToken);
    if (is_file($resultPath) && !unlink($resultPath)) {
        error_log('[KRECK-IMPORT] Unable to remove completed progress file: ' . basename($resultPath));
    }
}

if (in_array($kreckAction, ['validate', 'import'], true)
    && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $isAjaxImport = ($_SERVER['HTTP_X_KRECK_IMPORT'] ?? '') === '1';
    $progressToken = trim((string) ($_POST['progressToken'] ?? ''));
    $importUserID = (int) $session->get('gibbonPersonID');
    $progressCallback = null;
    if ($isAjaxImport) {
        $writeProgress($progressToken, [
            'state' => 'running',
            'percent' => 2,
            'message' => __('Reading workbook...'),
        ]);
        $progressCallback = static function (int $percent, string $message) use ($writeProgress, $progressToken): void {
            $writeProgress($progressToken, [
                'state' => 'running',
                'percent' => max(0, min(99, $percent)),
                'message' => $message,
            ]);
        };
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
    }

    try {
        $timeLimitDisabled = function_exists('set_time_limit') && set_time_limit(0);
        if (!$timeLimitDisabled && function_exists('ini_set')) {
            $timeLimitDisabled = ini_set('max_execution_time', '0') !== false;
        }
        if (!$timeLimitDisabled) {
            throw new \RuntimeException('PHP could not disable the import execution timeout. Ask the server administrator to allow long-running imports.');
        }

        $parsed = $importer->parseWorkbookSheets($_FILES['file']['tmp_name']);
        $parsedCounts = array_map('count', $parsed);
        if ($isAjaxImport) {
            $progressCallback(8, __('Workbook loaded. Validating its sheets...'));
        }
        $result = $importer->runWorkbook(
            $parsed,
            $kreckAction === 'import',
            $importUserID,
            $mode,
            $progressCallback
        );
    } catch (\Throwable $e) {
        if (!$isAjaxImport) {
            throw $e;
        }
        $reference = KreckBridgeDiagnostics::reportCaught($e, 'import institution workbook request');
        $message = $e->getMessage() . ' (KRECK diagnostic reference ' . $reference . ')';
        $writeProgress($progressToken, [
            'state' => 'error',
            'percent' => 100,
            'message' => __('Import failed.'),
            'error' => $message,
        ]);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if ($isAjaxImport) {
        $storedResult = $result;
        foreach ($storedResult['perType'] ?? [] as &$typeResult) {
            $typeResult['errorCount'] = count($typeResult['errors'] ?? []);
            $typeResult['warningCount'] = count($typeResult['warnings'] ?? []);
            $typeResult['errors'] = array_slice($typeResult['errors'] ?? [], 0, 10);
            $typeResult['warnings'] = array_slice($typeResult['warnings'] ?? [], 0, 10);
            $typeResult['logs'] = array_slice($typeResult['logs'] ?? [], 0, 10);
        }
        unset($typeResult);
        $writeProgress($progressToken, [
            'state' => 'done',
            'percent' => 100,
            'message' => $result['committed'] ? __('Import committed.') : __('Validation complete.'),
            'result' => $storedResult,
            'parsedCounts' => $parsedCounts,
        ]);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => true], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

$page->breadcrumbs
    ->add(__('Import Institution Data'), 'kreck_import.php')
    ->add(__('Whole Institution'));

$formProgressToken = bin2hex(random_bytes(16));
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
.kreck-wb .progress-panel { margin-top:12px; padding:12px; border:1px solid #cfe5e2; border-radius:8px; background:#f4fbfa; }
.kreck-wb .progress-panel progress { display:block; width:100%; height:18px; margin:8px 0; accent-color:#0e9f9a; }
</style>
<div class="kreck-wb" style="max-width:1000px">
    <p><?php echo __('The institution hands over its data once. Download the KRECK workbook, fill one sheet per data type, and import it all in a single pass. Everything runs in one transaction, so references between sheets resolve and a dry-run validates the whole file without saving. Progress is reported while sheets are processed.'); ?></p>

    <div class="steps">
        <div class="step">
            <h4><?php echo __('Download the institution template'); ?></h4>
            <p class="emphasis small"><?php echo __('A multi-sheet Excel workbook with school-year dates, courses/classes, SMS timetable setup rows, optional LMS assignment plans, people, enrolments and fees. Bold headers are required. Staged lesson timetable and assignment sheets are mapped into SMS schedules and KRECK LMS assignment plans during import.'); ?></p>
            <a class="kbtn ghost" href="<?php echo htmlspecialchars($self . '&kreckAction=template'); ?>"><?php echo __('Download KRECK workbook (.xlsx)'); ?></a>
        </div>
        <div class="step">
            <h4><?php echo __('Fill it in'); ?></h4>
            <p class="emphasis small"><?php echo __('Enter dates as YYYY-MM-DD and times in 24-hour HH:MM. Staged lessons are converted into dated SMS timetable sessions; if no active timetable exists for a school year, the importer creates one. Staged assignments are imported as active KRECK LMS plans unless marked inactive or cancelled. Rooms are linked only when they match an existing SMS Facility. Leave unused sheets empty.'); ?></p>
        </div>
        <div class="step">
            <h4><?php echo __('Validate, then import'); ?></h4>
            <form class="kreck-import-form" method="post" action="<?php echo htmlspecialchars($self); ?>"
                  data-progress-url="<?php echo htmlspecialchars($self . '&kreckAction=progress'); ?>"
                  enctype="multipart/form-data">
                <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_import_institution.php">
                <input type="hidden" name="progressToken" value="<?php echo htmlspecialchars($formProgressToken); ?>">
                <input type="file" name="file" accept=".xlsx,.xls,.ods" required>
                <label style="margin-left:10px"><?php echo __('Mode'); ?>:
                    <select data-kpicker name="mode">
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
            <div class="progress-panel" id="kreck-import-progress" hidden role="status" aria-live="polite">
                <strong><?php echo __('Import progress'); ?></strong>
                <progress id="kreck-import-progress-bar" max="100" value="0" aria-label="<?php echo __('Import progress'); ?>"></progress>
                <div id="kreck-import-progress-message"><?php echo __('Waiting to start...'); ?></div>
            </div>
            <p class="emphasis small"><?php echo __('The PHP execution limit is disabled during import. Web server or proxy request limits may still need adjustment for unusually large workbooks.'); ?></p>
        </div>
    </div>

    <?php if ($result !== null): ?>
        <h3><?php echo $result['committed'] ? __('Institution imported') : __('Validation (dry-run)'); ?></h3>
        <?php if (!empty($result['error'])): ?>
            <div class="error"><?php echo htmlspecialchars($result['error']); ?></div>
        <?php elseif (empty($parsedCounts)): ?>
            <div class="warning"><?php echo __('No recognised sheets found. Use the KRECK template so sheet names match the data types.'); ?></div>
        <?php else: ?>
            <p>
                <?php echo __('Total errors'); ?>: <b class="<?php echo $result['totalErrors'] === 0 ? 'ok' : 'err'; ?>"><?php echo (int) $result['totalErrors']; ?></b>
                &nbsp;|&nbsp; <?php echo __('Status'); ?>:
                <?php if ($result['committed']): ?><b class="ok"><?php echo __('Committed'); ?></b>
                <?php elseif ($result['ok']): ?><b class="ok"><?php echo __('Valid — ready to import'); ?></b>
                <?php else: ?><b class="err"><?php echo __('Rolled back (fix errors below)'); ?></b><?php endif; ?>
            </p>
            <?php $scheduleSummary = $result['perType']['stg_lessonTimetable']['stats'] ?? []; ?>
            <?php if (!empty($scheduleSummary)): ?>
                <p class="success">
                    <?php
                    echo htmlspecialchars(sprintf(
                        __('Schedule preview: %d lesson sessions, %d distinct dates, and %d time periods'),
                        (int) ($scheduleSummary['lessonSessions'] ?? 0),
                        (int) ($scheduleSummary['uniqueDates'] ?? 0),
                        (int) ($scheduleSummary['uniquePeriods'] ?? 0)
                    ));
                    if (!empty($scheduleSummary['firstDate'])) {
                        echo ' (' . htmlspecialchars($scheduleSummary['firstDate']);
                        if ($scheduleSummary['lastDate'] !== $scheduleSummary['firstDate']) {
                            echo ' to ' . htmlspecialchars($scheduleSummary['lastDate']);
                        }
                        echo ')';
                    }
                    ?>
                </p>
            <?php endif; ?>
            <table class="grid">
                <thead><tr><th><?php echo __('Sheet / Type'); ?></th><th><?php echo __('Rows'); ?></th><th><?php echo __('Errors'); ?></th><th><?php echo __('Warnings'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($result['perType'] as $typeName => $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($labelByType[$typeName] ?? $typeName); ?></td>
                        <td><?php echo (int) $r['rowCount']; ?></td>
                        <td class="<?php echo empty($r['errors']) ? 'ok' : 'err'; ?>"><?php echo (int) ($r['errorCount'] ?? count($r['errors'])); ?></td>
                        <td><?php echo (int) ($r['warningCount'] ?? count($r['warnings'])); ?></td>
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
<script>
(function () {
    const form = document.querySelector('.kreck-import-form');
    const panel = document.getElementById('kreck-import-progress');
    const bar = document.getElementById('kreck-import-progress-bar');
    const message = document.getElementById('kreck-import-progress-message');
    if (!form || !panel || !bar || !message || !window.fetch) return;

    form.addEventListener('submit', function (event) {
        const submitter = event.submitter;
        if (!submitter) return;
        event.preventDefault();

        const token = form.querySelector('[name="progressToken"]').value;
        const data = new FormData(form);
        data.set('kreckAction', submitter.value);
        panel.hidden = false;
        submitter.disabled = true;
        form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
        message.textContent = 'Uploading workbook...';

        const progressUrl = form.dataset.progressUrl + '&progressToken=' + encodeURIComponent(token);
        const resultUrl = form.action + '&progressResult=' + encodeURIComponent(token);

        fetch(form.action, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {'X-KRECK-Import': '1'}
        }).catch(function () {
            message.textContent = 'The import connection was interrupted. Checking whether the server is still processing...';
        });

        function poll() {
            fetch(progressUrl, {credentials: 'same-origin', cache: 'no-store'})
                .then((response) => {
                    if (!response.ok) throw new Error('Progress request failed.');
                    return response.json();
                })
                .then((state) => {
                    bar.value = Math.max(0, Math.min(100, Number(state.percent) || 0));
                    message.textContent = state.message || 'Processing workbook...';
                    if (state.state === 'done') {
                        window.location.assign(resultUrl);
                        return;
                    }
                    if (state.state === 'error') {
                        window.location.assign(resultUrl);
                        return;
                    }
                    window.setTimeout(poll, 800);
                })
                .catch(function () {
                    message.textContent = 'Waiting for import progress...';
                    window.setTimeout(poll, 1500);
                });
        }
        poll();
    });
})();
</script>
<?php
echo KreckUi::assets() . ob_get_clean();
