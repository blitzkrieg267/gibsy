<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — teacher entry for SMS-owned semester assessment marks.
*/

require_once __DIR__ . '/src/AssessmentManager.php';

$actionPath = '/modules/Kreck Bridge/kreck_assessments.php';
if (isActionAccessible($guid, $connection2, $actionPath) == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$manager = new KreckAssessmentManager($connection2);
$personID = (int) ($session->get('gibbonPersonID') ?? 0);
$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck%20Bridge/kreck_assessments.php';
$years = $manager->schoolYears();
$currentYear = $manager->currentSchoolYear();
$yearID = (int) ($_POST['year'] ?? $_GET['year'] ?? ($currentYear['gibbonSchoolYearID'] ?? 0));
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

$period = (string) ($_POST['period'] ?? $_GET['period'] ?? 'Semester 1');
if (!in_array($period, ['Semester 1', 'Semester 2'], true)) {
    $period = 'Semester 1';
}
$classes = $yearID > 0 ? $manager->classesForTeacher($personID, $yearID, $period) : [];
$classID = (int) ($_POST['class'] ?? $_GET['class'] ?? 0);
$class = null;
foreach ($classes as $candidate) {
    if ((int) $candidate['gibbonCourseClassID'] === $classID) {
        $class = $candidate;
        break;
    }
}
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['assessmentAction'] ?? '', ['save', 'submit'], true)) {
    if (!$class) {
        $error = __('Choose one of your assigned classes before entering marks.');
    } else {
        $assessmentID = 0;
        try {
            $assessmentID = $manager->saveDraft(
                $classID,
                $yearID,
                $period,
                [
                    'ca1' => $_POST['ca1Weight'] ?? null,
                    'ca2' => $_POST['ca2Weight'] ?? null,
                    'exam' => $_POST['examWeight'] ?? null,
                ],
                is_array($_POST['marks'] ?? null) ? $_POST['marks'] : [],
                $personID
            );
            $message = __('Draft marks saved.');
        } catch (\InvalidArgumentException $exception) {
            $error = __($exception->getMessage());
        }
        if ($assessmentID > 0 && ($_POST['assessmentAction'] ?? '') === 'submit') {
            try {
                $eventKey = $manager->submit($classID, $yearID, $period, $personID);
                $delivery = $manager->dispatchNotifications($container->get(\Gibbon\Comms\NotificationSender::class), $eventKey);
                $message = $delivery['queued'] === 0
                    ? __('Marks submitted, but no reviewer is configured to receive alerts. Ask the administrator to grant the Review Assessment Marks action to the principal or administrator role.')
                    : ($delivery['failed'] > 0
                        ? __('Marks submitted. Some reviewer alerts are queued for retry.')
                        : __('Marks submitted to the principal or administrator for review.'));
            } catch (\InvalidArgumentException $exception) {
                $error = __($exception->getMessage());
                $message = __('Draft was saved, but it has not been submitted.');
            }
        }
        $sheet = $manager->sheetForClass($classID, $yearID, $period);
    }
}

$page->breadcrumbs
    ->add(__('Kreck Bridge'), 'kreck_export.php')
    ->add(__('Assessment Mark Entry'));

$sheet = $class ? $manager->sheetForClass($classID, $yearID, $period) : null;
$editable = !$sheet || in_array($sheet['status'], ['Draft', 'RevisionRequested'], true);
$weights = [
    'ca1' => $sheet ? (float) $sheet['ca1Weight'] : 25.0,
    'ca2' => $sheet ? (float) $sheet['ca2Weight'] : 25.0,
    'exam' => $sheet ? (float) $sheet['examWeight'] : 50.0,
];
$marks = $class ? $manager->markRows((int) ($sheet['kreckAssessmentID'] ?? 0), $classID) : [];
if ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['ca1' => 'ca1Weight', 'ca2' => 'ca2Weight', 'exam' => 'examWeight'] as $component => $field) {
        if (isset($_POST[$field]) && is_numeric($_POST[$field])) {
            $weights[$component] = (float) $_POST[$field];
        }
    }
    foreach ($marks as &$markRow) {
        $studentID = (int) $markRow['gibbonPersonID'];
        $posted = $_POST['marks'][$studentID] ?? $_POST['marks'][(string) $studentID] ?? null;
        if (is_array($posted)) {
            foreach (['ca1Mark', 'ca2Mark', 'examMark', 'comment'] as $field) {
                if (array_key_exists($field, $posted)) {
                    $markRow[$field] = $posted[$field];
                }
            }
        }
    }
    unset($markRow);
}
$reportLink = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck%20Bridge/kreck_assessment_reports.php&year=' . $yearID;
$reviewLink = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck%20Bridge/kreck_assessment_review.php';
$canReview = isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_assessment_review.php');

ob_start();
?>
<style>
.kae{max-width:1180px}.kae .panel{border:1px solid #dce7e6;border-radius:10px;padding:16px;margin:14px 0;background:#f8fbfa}
.kae .bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.kae .classes{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}
.kae .class-card{display:block;color:inherit;text-decoration:none;border:1px solid #dce7e6;border-radius:9px;padding:14px;background:#fff}
.kae .class-card:hover,.kae .class-card:focus{border-color:#0e9f9a;box-shadow:0 3px 14px #0e6b631a}
.kae .class-card strong{display:block;color:#0f2233;font-size:16px}.kae .muted{color:#64748b;font-size:13px}
.kae .kbtn{background:#0e817d;color:#fff;border:0;border-radius:6px;padding:8px 14px;font-weight:650;cursor:pointer;text-decoration:none;display:inline-block}
.kae .kbtn.secondary{background:#e7f2f1;color:#0e625e}.kae .weights{display:flex;gap:12px;flex-wrap:wrap}
.kae .weights label{display:flex;gap:6px;align-items:center}.kae input[type=number]{width:90px}.kae input,.kae textarea,.kae select{padding:7px;border:1px solid #b8c8d0;border-radius:5px}
.kae .table-wrap{overflow-x:auto}.kae table{border-collapse:collapse;width:100%;min-width:860px;background:#fff}
.kae th,.kae td{border-bottom:1px solid #e6ecef;padding:8px;text-align:left;vertical-align:middle}.kae th{background:#eff5f5;color:#304454}
.kae td.student{min-width:180px}.kae td.comment{min-width:230px}.kae textarea{width:100%;min-height:44px;resize:vertical}
.kae .status{font-weight:700}.kae .status-Approved{color:#167345}.kae .status-Submitted{color:#9a5d00}.kae .status-RevisionRequested{color:#b42318}
.kae .revision{border-left:4px solid #e3a23b;padding:10px 12px;background:#fff8e9;margin:12px 0}
@media(max-width:640px){.kae .panel{padding:12px}.kae .bar>*{max-width:100%}}
</style>

<div class="kae">
    <p><?php echo __('Enter final semester marks for your assigned classes. Each mark is a percentage from 0 to 100. Drafts remain editable; submitted sheets are locked until a reviewer returns them.'); ?></p>
    <?php if ($message !== ''): ?><div class="success" role="status"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="warning" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="panel">
        <form class="bar" method="get" action="<?php echo htmlspecialchars($self); ?>">
            <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_assessments.php">
            <label><?php echo __('School year'); ?>
                <select name="year" onchange="this.form.submit()">
                    <?php foreach ($years as $schoolYear): ?>
                        <option value="<?php echo (int) $schoolYear['gibbonSchoolYearID']; ?>" <?php echo (int) $schoolYear['gibbonSchoolYearID'] === $yearID ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $schoolYear['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php echo __('Semester'); ?>
                <select name="period" onchange="this.form.submit()">
                    <?php foreach (['Semester 1', 'Semester 2'] as $semester): ?>
                        <option value="<?php echo htmlspecialchars($semester); ?>" <?php echo $semester === $period ? 'selected' : ''; ?>><?php echo htmlspecialchars($semester); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <input type="hidden" name="class" value="<?php echo $classID; ?>">
            <a class="kbtn secondary" href="<?php echo htmlspecialchars($reportLink); ?>"><?php echo __('Approved reports'); ?></a>
            <?php if ($canReview): ?><a class="kbtn secondary" href="<?php echo htmlspecialchars($reviewLink); ?>"><?php echo __('Reviewer inbox'); ?></a><?php endif; ?>
        </form>
    </div>

    <h2><?php echo __('Your assigned classes'); ?></h2>
    <?php if (empty($classes)): ?>
        <div class="panel"><?php echo __('No classes assigned to you were found for this school year. Ask the administrator to check class-teacher assignments in SMS.'); ?></div>
    <?php else: ?>
        <div class="classes">
            <?php foreach ($classes as $classRow):
                $url = $self . '&year=' . $yearID . '&period=' . rawurlencode($period) . '&class=' . (int) $classRow['gibbonCourseClassID'];
                $status = (string) ($classRow['assessmentStatus'] ?? 'Not started');
            ?>
                <a class="class-card" href="<?php echo htmlspecialchars($url); ?>">
                    <strong><?php echo htmlspecialchars((string) $classRow['courseName']); ?></strong>
                    <span><?php echo htmlspecialchars((string) ($classRow['className'] ?: $classRow['classShort'])); ?></span>
                    <div class="muted"><?php echo (int) $classRow['studentCount']; ?> <?php echo __('students'); ?> · <?php echo htmlspecialchars($period); ?></div>
                    <div class="status status-<?php echo htmlspecialchars(str_replace(' ', '', $status)); ?>"><?php echo htmlspecialchars($status); ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($class): ?>
        <section class="panel" aria-labelledby="mark-sheet-title">
            <div class="bar">
                <div>
                    <h2 id="mark-sheet-title"><?php echo htmlspecialchars((string) $class['courseName']); ?> — <?php echo htmlspecialchars((string) ($class['className'] ?: $class['classShort'])); ?></h2>
                    <div class="muted"><?php echo htmlspecialchars((string) $year['name']); ?> · <?php echo htmlspecialchars($period); ?> · <?php echo count($marks); ?> <?php echo __('active students'); ?></div>
                </div>
                <?php if ($sheet): ?>
                    <span class="status status-<?php echo htmlspecialchars($sheet['status']); ?>"><?php echo htmlspecialchars(__($sheet['status'])); ?></span>
                <?php else: ?>
                    <span class="status"><?php echo __('Draft — not started'); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($sheet && $sheet['status'] === 'RevisionRequested' && !empty($sheet['reviewerComment'])): ?>
                <div class="revision"><strong><?php echo __('Reviewer feedback'); ?>:</strong> <?php echo nl2br(htmlspecialchars((string) $sheet['reviewerComment'])); ?></div>
            <?php endif; ?>
            <?php if ($sheet && !empty($sheet['dueDate'])): ?>
                <p class="muted"><?php echo __('Submission deadline'); ?>: <strong><?php echo htmlspecialchars((string) $sheet['dueDate']); ?></strong></p>
            <?php endif; ?>

            <?php if ($editable): ?>
                <form method="post" action="<?php echo htmlspecialchars($self); ?>" id="kreck-assessment-form">
                    <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_assessments.php">
                    <input type="hidden" name="year" value="<?php echo $yearID; ?>">
                    <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
                    <input type="hidden" name="class" value="<?php echo $classID; ?>">
                    <div class="panel">
                        <strong><?php echo __('Assessment weights'); ?></strong>
                        <p class="muted"><?php echo __('Weights must total 100%. You may change them while the sheet is a draft or has been returned for revision. Approved reports remain locked.'); ?></p>
                        <div class="weights">
                            <label>CA1 <input class="weight" type="number" min="0" max="100" step="0.01" name="ca1Weight" value="<?php echo htmlspecialchars((string) $weights['ca1']); ?>">%</label>
                            <label>CA2 <input class="weight" type="number" min="0" max="100" step="0.01" name="ca2Weight" value="<?php echo htmlspecialchars((string) $weights['ca2']); ?>">%</label>
                            <label><?php echo __('Final exam'); ?> <input class="weight" type="number" min="0" max="100" step="0.01" name="examWeight" value="<?php echo htmlspecialchars((string) $weights['exam']); ?>">%</label>
                            <strong><?php echo __('Total'); ?>: <output id="weight-total">100%</output></strong>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr>
                                <th><?php echo __('Student'); ?></th><th>CA1 / 100</th><th>CA2 / 100</th>
                                <th><?php echo __('Final exam / 100'); ?></th><th><?php echo __('Weighted total'); ?></th><th><?php echo __('Optional comment'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($marks as $row): ?>
                                <tr>
                                    <td class="student">
                                        <strong><?php echo htmlspecialchars(trim($row['preferredName'] . ' ' . $row['surname'])); ?></strong>
                                        <div class="muted"><?php echo htmlspecialchars((string) ($row['studentID'] ?? '')); ?></div>
                                    </td>
                                    <td><input class="score" type="number" min="0" max="100" step="0.01" name="marks[<?php echo (int) $row['gibbonPersonID']; ?>][ca1Mark]" value="<?php echo htmlspecialchars((string) ($row['ca1Mark'] ?? '')); ?>" aria-label="CA1 mark for <?php echo htmlspecialchars(trim($row['preferredName'] . ' ' . $row['surname'])); ?>"></td>
                                    <td><input class="score" type="number" min="0" max="100" step="0.01" name="marks[<?php echo (int) $row['gibbonPersonID']; ?>][ca2Mark]" value="<?php echo htmlspecialchars((string) ($row['ca2Mark'] ?? '')); ?>" aria-label="CA2 mark for <?php echo htmlspecialchars(trim($row['preferredName'] . ' ' . $row['surname'])); ?>"></td>
                                    <td><input class="score" type="number" min="0" max="100" step="0.01" name="marks[<?php echo (int) $row['gibbonPersonID']; ?>][examMark]" value="<?php echo htmlspecialchars((string) ($row['examMark'] ?? '')); ?>" aria-label="Final exam mark for <?php echo htmlspecialchars(trim($row['preferredName'] . ' ' . $row['surname'])); ?>"></td>
                                    <td class="weighted"><?php echo $row['finalMark'] === null ? '—' : htmlspecialchars(number_format((float) $row['finalMark'], 2)); ?></td>
                                    <td class="comment"><textarea name="marks[<?php echo (int) $row['gibbonPersonID']; ?>][comment]" maxlength="8000" aria-label="Optional comment for <?php echo htmlspecialchars(trim($row['preferredName'] . ' ' . $row['surname'])); ?>"><?php echo htmlspecialchars((string) ($row['comment'] ?? '')); ?></textarea></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="muted"><?php echo __('Save keeps an incomplete sheet as a draft. Submit is available only when every active student has all three marks.'); ?></p>
                    <div class="bar">
                        <button class="kbtn secondary" type="submit" name="assessmentAction" value="save"><?php echo __('Save draft'); ?></button>
                        <button class="kbtn" id="submit-assessment" type="submit" name="assessmentAction" value="submit"><?php echo __('Submit for review'); ?></button>
                    </div>
                </form>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th><?php echo __('Student'); ?></th><th>CA1</th><th>CA2</th><th><?php echo __('Final exam'); ?></th><th><?php echo __('Weighted total'); ?></th><th><?php echo __('Comment'); ?></th></tr></thead>
                        <tbody><?php foreach ($marks as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(trim($row['preferredName'] . ' ' . $row['surname'])); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['ca1Mark'] ?? '—')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['ca2Mark'] ?? '—')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['examMark'] ?? '—')); ?></td>
                                <td><?php echo $row['finalMark'] === null ? '—' : htmlspecialchars(number_format((float) $row['finalMark'], 2)); ?></td>
                                <td><?php echo nl2br(htmlspecialchars((string) ($row['comment'] ?? ''))); ?></td>
                            </tr>
                        <?php endforeach; ?></tbody>
                    </table>
                </div>
                <p class="muted"><?php echo __('This sheet is locked. Contact an authorized principal or administrator if corrections are required.'); ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<script>
(() => {
    const form = document.getElementById('kreck-assessment-form');
    if (!form) return;
    const weights = [...form.querySelectorAll('.weight')];
    const total = document.getElementById('weight-total');
    const submit = document.getElementById('submit-assessment');
    const update = () => {
        const sum = weights.reduce((n, input) => n + (Number(input.value) || 0), 0);
        total.value = `${sum.toFixed(2)}%`;
        total.textContent = `${sum.toFixed(2)}%`;
        submit.disabled = Math.abs(sum - 100) > 0.001;
        form.querySelectorAll('tbody tr').forEach(row => {
            const scores = [...row.querySelectorAll('.score')].map(input => input.value === '' ? null : Number(input.value));
            const result = row.querySelector('.weighted');
            result.textContent = scores.every(Number.isFinite)
                ? ((scores[0] * Number(weights[0].value) + scores[1] * Number(weights[1].value) + scores[2] * Number(weights[2].value)) / 100).toFixed(2)
                : '—';
        });
    };
    form.addEventListener('input', update);
    update();
})();
</script>
<?php
echo ob_get_clean();
