<?php
require_once __DIR__ . '/src/Diagnostics.php';
require_once __DIR__ . '/src/Ui.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — principal/administrator assessment review and audit.
*/

require_once __DIR__ . '/src/AssessmentManager.php';

$actionPath = '/modules/Kreck Bridge/kreck_assessment_review.php';
if (isActionAccessible($guid, $connection2, $actionPath) == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$manager = new KreckAssessmentManager($connection2);
$personID = (int) ($session->get('gibbonPersonID') ?? 0);
$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck%20Bridge/kreck_assessment_review.php';
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

$notice = '';
$error = '';
$assessmentID = (int) ($_POST['assessmentID'] ?? $_GET['sheet'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['reviewAction'])) {
    try {
        if ($_POST['reviewAction'] === 'setup') {
            $assessmentID = $manager->prepareForReviewer(
                (int) ($_POST['classID'] ?? 0),
                $yearID,
                (string) ($_POST['period'] ?? ''),
                $personID
            );
            $manager->setDeadline($assessmentID, $personID, (string) ($_POST['dueDate'] ?? ''));
            $notice = __('Assessment sheet and deadline prepared for the assigned teacher.');
        } elseif ($_POST['reviewAction'] === 'deadline') {
            $manager->setDeadline($assessmentID, $personID, (string) ($_POST['dueDate'] ?? ''));
            $notice = __('Deadline saved.');
        } elseif (in_array($_POST['reviewAction'], ['approve', 'revise'], true)) {
            $eventKey = $manager->review(
                $assessmentID,
                $personID,
                (string) $_POST['reviewAction'],
                (string) ($_POST['reviewComment'] ?? '')
            );
            $delivery = $manager->dispatchNotifications($container->get(\Gibbon\Comms\NotificationSender::class), $eventKey);
            $notice = $delivery['queued'] === 0
                ? __('Review decision saved, but no assigned teacher was found to notify.')
                : ($delivery['failed'] > 0
                    ? __('Review decision saved. Teacher notifications are queued for retry.')
                    : ($_POST['reviewAction'] === 'approve'
                        ? __('Approved. Results are now locked for reporting.')
                        : __('Returned to the teacher for correction.')));
        }
    } catch (\InvalidArgumentException $exception) {
        $error = __($exception->getMessage());
    }
}

$queue = $yearID > 0 ? $manager->assessmentDashboard($yearID) : [];
$selectClassID = (int) ($_POST['classID'] ?? $_GET['class'] ?? 0);
$selectPeriod = (string) ($_POST['period'] ?? $_GET['period'] ?? '');
$selected = null;
foreach ($queue as $candidate) {
    if (($assessmentID > 0 && (int) ($candidate['kreckAssessmentID'] ?? 0) === $assessmentID)
        || ($assessmentID === 0 && $selectClassID > 0
            && (int) $candidate['gibbonCourseClassID'] === $selectClassID
            && $candidate['periodName'] === $selectPeriod)) {
        $selected = $candidate;
        break;
    }
}
$page->breadcrumbs
    ->add(__('Kreck Bridge'), 'kreck_export.php')
    ->add(__('Review Assessment Marks'));

$marks = $selected
    ? $manager->markRows((int) ($selected['kreckAssessmentID'] ?? 0), (int) $selected['gibbonCourseClassID'])
    : [];
$audit = $selected && !empty($selected['kreckAssessmentID']) ? $manager->auditRows((int) $selected['kreckAssessmentID']) : [];
$notifications = $selected && !empty($selected['kreckAssessmentID']) ? $manager->notificationRows((int) $selected['kreckAssessmentID']) : [];
$reportsURL = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck%20Bridge/kreck_assessment_reports.php&year=' . $yearID;
$hasSheet = $selected && !empty($selected['kreckAssessmentID']);

ob_start();
?>
<style>
.kar{max-width:1180px}.kar .panel{border:1px solid #dce7e6;border-radius:10px;padding:15px;margin:14px 0;background:#f8fbfa}
.kar .bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.kar .list{border-collapse:collapse;width:100%;margin-top:12px;background:#fff}
.kar .list th,.kar .list td,.kar .marks th,.kar .marks td{padding:8px 10px;border-bottom:1px solid #e5ecef;text-align:left;vertical-align:top}
.kar .list th,.kar .marks th{background:#eff5f5}.kar .kbtn{background:#0e817d;color:#fff;border:0;border-radius:6px;padding:8px 13px;font-weight:650;cursor:pointer}
.kar .kbtn.secondary{background:#e7f2f1;color:#0e625e}.kar input,.kar select,.kar textarea{padding:7px;border:1px solid #b8c8d0;border-radius:5px}
.kar textarea{width:100%;min-height:75px}.kar .muted{color:#64748b;font-size:13px}.kar .status{font-weight:700}
.kar .audit{max-height:360px;overflow:auto}.kar .audit-row{padding:9px 0;border-bottom:1px solid #e6ecef}
.kar .audit-row pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f3f6f7;padding:8px;border-radius:5px}
@media(max-width:700px){.kar .scroll{overflow-x:auto}.kar .list,.kar .marks{min-width:800px}}
</style>

<div class="kar">
    <p><?php echo __('Review class mark sheets submitted by teachers. Approval locks the calculated semester result for reporting; requesting revisions reopens the sheet and retains its full audit history.'); ?></p>
    <?php if ($notice !== ''): ?><div class="success" role="status"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="warning" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="panel">
        <form class="bar" method="get" action="<?php echo htmlspecialchars($self); ?>">
            <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_assessment_review.php">
            <label><?php echo __('School year'); ?>
                <select data-kpicker name="year" onchange="this.form.submit()">
                    <?php foreach ($years as $schoolYear): ?>
                        <option value="<?php echo (int) $schoolYear['gibbonSchoolYearID']; ?>" <?php echo (int) $schoolYear['gibbonSchoolYearID'] === $yearID ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $schoolYear['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <a class="kbtn secondary" href="<?php echo htmlspecialchars($reportsURL); ?>"><?php echo __('Approved reports'); ?></a>
        </form>
    </div>

    <section class="panel">
        <h2><?php echo __('Class assessment progress'); ?></h2>
        <?php if (empty($queue)): ?>
            <p class="muted"><?php echo __('No classes were found for this school year.'); ?></p>
        <?php else: ?>
            <div class="scroll"><table class="list">
                <thead><tr><th><?php echo __('Class / subject'); ?></th><th><?php echo __('Assigned teachers'); ?></th><th><?php echo __('Semester'); ?></th><th><?php echo __('Students complete'); ?></th><th><?php echo __('Deadline'); ?></th><th><?php echo __('Status'); ?></th><th></th></tr></thead>
                <tbody><?php foreach ($queue as $sheetRow):
                    $url = !empty($sheetRow['kreckAssessmentID'])
                        ? $self . '&year=' . $yearID . '&sheet=' . (int) $sheetRow['kreckAssessmentID']
                        : $self . '&year=' . $yearID . '&class=' . (int) $sheetRow['gibbonCourseClassID'] . '&period=' . rawurlencode($sheetRow['periodName']);
                    // Jump to the opened sheet (it renders below the class list, so a
                    // fragment keeps the click anchored to the sheet instead of the page top).
                    $url .= '#kreck-sheet';
                    $status = (string) ($sheetRow['status'] ?? 'Not started');
                ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string) $sheetRow['courseName']); ?></strong><br><?php echo htmlspecialchars((string) ($sheetRow['className'] ?: $sheetRow['classShort'])); ?></td>
                        <td><?php echo htmlspecialchars((string) ($sheetRow['teachers'] ?: __('No teacher assigned'))); ?></td>
                        <td><?php echo htmlspecialchars((string) $sheetRow['periodName']); ?></td>
                        <td><?php echo (int) ($sheetRow['completeCount'] ?? 0); ?> / <?php echo (int) $sheetRow['studentCount']; ?></td>
                        <td><?php echo htmlspecialchars((string) ($sheetRow['dueDate'] ?: '—')); ?></td>
                        <td class="status"><?php echo htmlspecialchars(__($status)); ?></td>
                        <td><a href="<?php echo htmlspecialchars($url); ?>"><?php echo __('Open sheet'); ?></a></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php if ($selected): ?>
        <section class="panel" id="kreck-sheet">
            <h2><?php echo htmlspecialchars((string) $selected['courseName']); ?> — <?php echo htmlspecialchars((string) ($selected['className'] ?: $selected['classShort'])); ?> · <?php echo htmlspecialchars((string) $selected['periodName']); ?></h2>
            <p class="muted">
                <?php echo htmlspecialchars((string) $year['name']); ?> ·
                CA1 <?php echo htmlspecialchars((string) ($selected['ca1Weight'] ?? '25.00')); ?>% ·
                CA2 <?php echo htmlspecialchars((string) ($selected['ca2Weight'] ?? '25.00')); ?>% ·
                <?php echo __('Final exam'); ?> <?php echo htmlspecialchars((string) ($selected['examWeight'] ?? '50.00')); ?>% ·
                <?php echo htmlspecialchars((string) ($selected['status'] ?? 'Not started')); ?>
            </p>
            <?php if (!empty($selected['reviewerComment'])): ?>
                <div class="panel"><strong><?php echo __('Latest review comment'); ?>:</strong><br><?php echo nl2br(htmlspecialchars((string) $selected['reviewerComment'])); ?></div>
            <?php endif; ?>

            <div class="scroll"><table class="marks">
                <thead><tr><th><?php echo __('Student'); ?></th><th><?php echo __('Student ID'); ?></th><th>CA1</th><th>CA2</th><th><?php echo __('Final exam'); ?></th><th><?php echo __('Weighted total'); ?></th><th><?php echo __('Teacher comment'); ?></th></tr></thead>
                <tbody><?php foreach ($marks as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(trim($row['preferredName'] . ' ' . $row['surname'])); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['studentID'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['ca1Mark'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['ca2Mark'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['examMark'] ?? '—')); ?></td>
                        <td><?php echo $row['finalMark'] === null ? '—' : htmlspecialchars(number_format((float) $row['finalMark'], 2)); ?></td>
                        <td><?php echo nl2br(htmlspecialchars((string) ($row['comment'] ?? ''))); ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>

            <?php if (!$hasSheet): ?>
                <form method="post" action="<?php echo htmlspecialchars($self); ?>#kreck-sheet" class="panel">
                    <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_assessment_review.php">
                    <input type="hidden" name="year" value="<?php echo $yearID; ?>">
                    <input type="hidden" name="classID" value="<?php echo (int) $selected['gibbonCourseClassID']; ?>">
                    <input type="hidden" name="period" value="<?php echo htmlspecialchars($selected['periodName']); ?>">
                    <p><?php echo __('No sheet exists yet. Set a deadline now to prepare this class and semester for teacher entry.'); ?></p>
                    <label><?php echo __('Submission deadline'); ?>
                        <input type="date" name="dueDate">
                    </label>
                    <button class="kbtn secondary" type="submit" name="reviewAction" value="setup"><?php echo __('Prepare assessment sheet'); ?></button>
                </form>
            <?php elseif ($selected['status'] === 'Submitted'): ?>
                <form method="post" action="<?php echo htmlspecialchars($self); ?>#kreck-sheet" class="panel">
                    <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_assessment_review.php">
                    <input type="hidden" name="year" value="<?php echo $yearID; ?>">
                    <input type="hidden" name="assessmentID" value="<?php echo (int) $selected['kreckAssessmentID']; ?>">
                    <label for="reviewComment"><?php echo __('Reviewer comment'); ?> <span class="muted">(<?php echo __('required when requesting a correction'); ?>)</span></label>
                    <textarea id="reviewComment" name="reviewComment" maxlength="8000"><?php echo htmlspecialchars((string) ($selected['reviewerComment'] ?? '')); ?></textarea>
                    <div class="bar">
                        <button class="kbtn" type="submit" name="reviewAction" value="approve"><?php echo __('Approve and lock for reporting'); ?></button>
                        <button class="kbtn secondary" type="submit" name="reviewAction" value="revise"><?php echo __('Request corrections'); ?></button>
                    </div>
                </form>
            <?php elseif ($selected['status'] === 'Approved'): ?>
                <form method="post" action="<?php echo htmlspecialchars($self); ?>#kreck-sheet" class="panel" onsubmit="return confirm('<?php echo htmlspecialchars(__('This will unlock the approved sheet and remove it from reports until it is reviewed again.'), ENT_QUOTES); ?>');">
                    <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_assessment_review.php">
                    <input type="hidden" name="year" value="<?php echo $yearID; ?>">
                    <input type="hidden" name="assessmentID" value="<?php echo (int) $selected['kreckAssessmentID']; ?>">
                    <label for="unlockComment"><?php echo __('Reason to unlock for correction'); ?></label>
                    <textarea id="unlockComment" name="reviewComment" maxlength="8000" required></textarea>
                    <button class="kbtn secondary" type="submit" name="reviewAction" value="revise"><?php echo __('Unlock and return for correction'); ?></button>
                </form>
            <?php endif; ?>

            <?php if ($hasSheet && $selected['status'] !== 'Approved'): ?>
                <form method="post" action="<?php echo htmlspecialchars($self); ?>#kreck-sheet" class="bar">
                    <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_assessment_review.php">
                    <input type="hidden" name="year" value="<?php echo $yearID; ?>">
                    <input type="hidden" name="assessmentID" value="<?php echo (int) $selected['kreckAssessmentID']; ?>">
                    <label><?php echo __('Submission deadline'); ?>
                        <input type="date" name="dueDate" value="<?php echo htmlspecialchars((string) ($selected['dueDate'] ?? '')); ?>">
                    </label>
                    <button class="kbtn secondary" type="submit" name="reviewAction" value="deadline"><?php echo __('Save deadline'); ?></button>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($hasSheet): ?>
        <details class="panel">
            <summary><?php echo __('Audit history'); ?> (<?php echo count($audit); ?>)</summary>
            <div class="audit"><?php foreach ($audit as $entry):
                $details = [];
                if (!empty($entry['beforeSnapshot'])) {
                    $details['before'] = json_decode((string) $entry['beforeSnapshot'], true) ?? $entry['beforeSnapshot'];
                }
                if (!empty($entry['afterSnapshot'])) {
                    $details['after'] = json_decode((string) $entry['afterSnapshot'], true) ?? $entry['afterSnapshot'];
                }
            ?>
                <div class="audit-row">
                    <strong><?php echo htmlspecialchars((string) $entry['action']); ?></strong> ·
                    <?php echo htmlspecialchars(trim(($entry['preferredName'] ?? '') . ' ' . ($entry['surname'] ?? '')) ?: __('System')); ?> ·
                    <?php echo htmlspecialchars((string) $entry['timestampCreated']); ?>
                    <?php if (!empty($entry['gibbonPersonIDStudent'])): ?> · <?php echo __('Student'); ?> #<?php echo (int) $entry['gibbonPersonIDStudent']; ?><?php endif; ?>
                    <?php if (!empty($details)): ?><pre><?php echo htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre><?php endif; ?>
                </div>
            <?php endforeach; ?></div>
        </details>

        <details class="panel">
            <summary><?php echo __('Notification delivery log'); ?> (<?php echo count($notifications); ?>)</summary>
            <?php foreach ($notifications as $notification): ?>
                <div class="audit-row">
                    <strong><?php echo htmlspecialchars((string) $notification['notificationType']); ?></strong> ·
                    <?php echo htmlspecialchars(trim(($notification['preferredName'] ?? '') . ' ' . ($notification['surname'] ?? ''))); ?> ·
                    <?php echo htmlspecialchars((string) $notification['status']); ?> ·
                    <?php echo (int) $notification['attempts']; ?> <?php echo __('attempt(s)'); ?>
                    <?php if (!empty($notification['lastError'])): ?><div class="muted"><?php echo htmlspecialchars((string) $notification['lastError']); ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </details>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
echo KreckUi::assets() . ob_get_clean();
