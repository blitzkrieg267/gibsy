<?php
require_once __DIR__ . '/src/Diagnostics.php';
require_once __DIR__ . '/src/Ui.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — approved marks report view and CSV export.
*/

require_once __DIR__ . '/src/AssessmentManager.php';

$actionPath = '/modules/Kreck Bridge/kreck_assessment_reports.php';
if (isActionAccessible($guid, $connection2, $actionPath) == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$manager = new KreckAssessmentManager($connection2);
$personID = (int) ($session->get('gibbonPersonID') ?? 0);
$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck%20Bridge/kreck_assessment_reports.php';
$years = $manager->schoolYears();
$currentYear = $manager->currentSchoolYear();
$yearID = (int) ($_GET['year'] ?? ($currentYear['gibbonSchoolYearID'] ?? 0));
$year = null;
foreach ($years as $candidate) {
    if ((int) $candidate['gibbonSchoolYearID'] === $yearID) {
        $year = $candidate;
        break;
    }
}
if (!$year && !empty($years)) {
    $year = $years[0];
    $yearID = (int) $year['gibbonSchoolYearID'];
}

$isReviewer = isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_assessment_review.php');
$period = (string) ($_GET['period'] ?? '');
if (!in_array($period, ['', 'Semester 1', 'Semester 2'], true)) {
    $period = '';
}
$classID = (int) ($_GET['class'] ?? 0);
$classOptions = $isReviewer
    ? $manager->classesForYear($yearID)
    : $manager->classesForTeacher($personID, $yearID, 'Semester 1');
$allowedClassIDs = $isReviewer ? [] : $manager->reportsForTeacher($personID, $yearID);
$error = '';
$rows = [];
if (!$isReviewer && empty($allowedClassIDs)) {
    $error = __('No assigned classes were found for this school year.');
} elseif (!$isReviewer && $classID > 0 && !in_array($classID, $allowedClassIDs, true)) {
    $error = __('You can only view reports for classes assigned to you.');
} else {
    $rows = $manager->approvedReportRows($yearID, $period, $classID > 0 ? $classID : null, $allowedClassIDs);
}

if (($_GET['download'] ?? '') === 'csv' && $error === '') {
    $safe = static function ($value): string {
        $value = (string) $value;
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    };
    $filename = 'kreck_approved_assessments_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($year['name'] ?? 'year')) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['School Year', 'Semester', 'Subject', 'Class', 'Student', 'Student ID', 'CA1 / 100', 'CA1 Weight %', 'CA2 / 100', 'CA2 Weight %', 'Final Exam / 100', 'Exam Weight %', 'Weighted Final / 100', 'Teacher Comment']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $safe($row['schoolYearName']),
            $safe($row['periodName']),
            $safe($row['courseName']),
            $safe($row['className']),
            $safe(trim($row['preferredName'] . ' ' . $row['surname'])),
            $safe($row['studentID'] ?? ''),
            $row['ca1Mark'],
            $row['ca1Weight'],
            $row['ca2Mark'],
            $row['ca2Weight'],
            $row['examMark'],
            $row['examWeight'],
            $row['finalMark'],
            $safe($row['comment'] ?? ''),
        ]);
    }
    fclose($output);
    exit;
}

$page->breadcrumbs
    ->add(__('Kreck Bridge'), 'kreck_export.php')
    ->add(__('Assessment Reports'));

$downloadURL = $self . '&year=' . $yearID . '&period=' . rawurlencode($period) . '&class=' . $classID . '&download=csv';
ob_start();
?>
<style>
.karp{max-width:1180px}.karp .panel{border:1px solid #dce7e6;border-radius:10px;padding:15px;margin:14px 0;background:#f8fbfa}
.karp .bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.karp input,.karp select{padding:7px;border:1px solid #b8c8d0;border-radius:5px}
.karp .kbtn{background:#0e817d;color:#fff;border:0;border-radius:6px;padding:8px 13px;font-weight:650;cursor:pointer;text-decoration:none;display:inline-block}
.karp .kbtn.secondary{background:#e7f2f1;color:#0e625e}.karp .muted{color:#64748b;font-size:13px}
.karp .scroll{overflow-x:auto}.karp table{border-collapse:collapse;width:100%;min-width:1050px;background:#fff}
.karp th,.karp td{padding:8px 9px;border-bottom:1px solid #e5ecef;text-align:left;vertical-align:top}.karp th{background:#eff5f5}
@media print{.karp .filters,.karp .actions{display:none!important}.karp .panel{border:0;padding:0}.karp table{min-width:0;font-size:10pt}.karp th,.karp td{padding:4px}}
</style>

<div class="karp">
    <p><?php echo __('Yearly report view of approved final marks. Only administrator-approved sheets appear. Each semester is listed separately; no annual average is calculated because the school has not supplied a cross-semester weighting policy.'); ?></p>
    <?php if ($error !== ''): ?><div class="warning" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form class="panel bar filters" method="get" action="<?php echo htmlspecialchars($self); ?>">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_assessment_reports.php">
        <label><?php echo __('School year'); ?>
            <select data-kpicker name="year" onchange="this.form.submit()">
                <?php foreach ($years as $schoolYear): ?>
                    <option value="<?php echo (int) $schoolYear['gibbonSchoolYearID']; ?>" <?php echo (int) $schoolYear['gibbonSchoolYearID'] === $yearID ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $schoolYear['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?php echo __('Semester'); ?>
            <select data-kpicker name="period" onchange="this.form.submit()">
                <option value=""><?php echo __('All semesters'); ?></option>
                <?php foreach (['Semester 1', 'Semester 2'] as $semester): ?>
                    <option value="<?php echo htmlspecialchars($semester); ?>" <?php echo $semester === $period ? 'selected' : ''; ?>><?php echo htmlspecialchars($semester); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?php echo __('Class / subject'); ?>
            <select data-kpicker name="class" onchange="this.form.submit()">
                <option value="0"><?php echo __('All assigned classes'); ?></option>
                <?php foreach ($classOptions as $class): ?>
                    <option value="<?php echo (int) $class['gibbonCourseClassID']; ?>" <?php echo (int) $class['gibbonCourseClassID'] === $classID ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($class['courseName'] . ' — ' . ($class['className'] ?: $class['classShort'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <div class="actions bar">
        <button class="kbtn secondary" type="button" onclick="window.print()"><?php echo __('Print report'); ?></button>
        <?php if ($error === ''): ?><a class="kbtn" href="<?php echo htmlspecialchars($downloadURL); ?>"><?php echo __('Download CSV'); ?></a><?php endif; ?>
        <span class="muted"><?php echo count($rows); ?> <?php echo __('approved student-subject-semester rows'); ?></span>
    </div>

    <?php if (empty($rows)): ?>
        <div class="panel"><?php echo __('No approved assessment marks match these filters. Draft and submitted results are intentionally excluded.'); ?></div>
    <?php else: ?>
        <div class="scroll"><table>
            <thead><tr>
                <th><?php echo __('Semester'); ?></th><th><?php echo __('Subject / class'); ?></th>
                <th><?php echo __('Student'); ?></th><th><?php echo __('Student ID'); ?></th>
                <th>CA1</th><th>CA2</th><th><?php echo __('Final exam'); ?></th>
                <th><?php echo __('Weighted final'); ?></th><th><?php echo __('Comment'); ?></th>
            </tr></thead>
            <tbody><?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['periodName']); ?></td>
                    <td><strong><?php echo htmlspecialchars($row['courseName']); ?></strong><br><?php echo htmlspecialchars($row['className']); ?></td>
                    <td><?php echo htmlspecialchars(trim($row['preferredName'] . ' ' . $row['surname'])); ?></td>
                    <td><?php echo htmlspecialchars((string) ($row['studentID'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string) ($row['ca1Mark'] ?? '—')); ?><br><span class="muted"><?php echo htmlspecialchars((string) $row['ca1Weight']); ?>%</span></td>
                    <td><?php echo htmlspecialchars((string) ($row['ca2Mark'] ?? '—')); ?><br><span class="muted"><?php echo htmlspecialchars((string) $row['ca2Weight']); ?>%</span></td>
                    <td><?php echo htmlspecialchars((string) ($row['examMark'] ?? '—')); ?><br><span class="muted"><?php echo htmlspecialchars((string) $row['examWeight']); ?>%</span></td>
                    <td><strong><?php echo htmlspecialchars(number_format((float) $row['finalMark'], 2)); ?></strong></td>
                    <td><?php echo nl2br(htmlspecialchars((string) ($row['comment'] ?? ''))); ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php
echo KreckUi::assets() . ob_get_clean();
