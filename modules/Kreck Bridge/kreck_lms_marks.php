<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Pull Marks from LMS (live).

The smarter marks importer: instead of exporting a file from the LMS and
uploading it here, this asks the LMS directly — "give me the marks for student X"
— over the KRECK web-service channel, and intelligently displays the FULL
gradebook the LMS holds (every course, every assignment/quiz/manual item, the
course total, with grade, %, letter, feedback and status). Optionally saves the
pulled figures to the school record (kreckMark), reusing the import store.
*/

require_once __DIR__ . '/src/Exporter.php';
require_once __DIR__ . '/src/KreckLinkStore.php';

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_lms_marks.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

// Module settings (scope = module name) — needs moodleEndpoint (https) + moodleToken.
$settings = [];
$sres = $connection2->prepare("SELECT name, value FROM gibbonSetting WHERE scope='Kreck Bridge'");
$sres->execute();
foreach ($sres->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    $settings[$r['name']] = $r['value'];
}

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_lms_marks.php';

/** Compute the LMS join key for a student the same way the manifest does. */
$joinKeyFor = function (string $studentID, int $pid): string {
    return $studentID !== '' ? $studentID : ('GIB' . $pid);
};

$selectedPID = (int) ($_REQUEST['gibbonPersonID'] ?? 0);
$result = null;      // decoded WS response
$error = null;
$saved = null;
$student = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['kreckAction'] ?? ''), ['pull', 'save'], true)) {
    $selectedPID = (int) ($_POST['gibbonPersonID'] ?? 0);
    if ($selectedPID <= 0) {
        $error = __('Please choose a student.');
    } else {
        $student = $connection2->prepare("SELECT gibbonPersonID, title, surname, preferredName, firstName, studentID, username FROM gibbonPerson WHERE gibbonPersonID=:pid");
        $student->execute(['pid' => $selectedPID]);
        $student = $student->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$student) {
            $error = __('Student not found.');
        } else {
            $joinKey = $joinKeyFor(trim((string) $student['studentID']), (int) $student['gibbonPersonID']);
            $result = KreckExporter::call($settings, 'local_kreck_get_student_marks', ['studentid' => $joinKey], 60);
            if (!empty($result['error'])) {
                $error = $result['error'];
                $result = null;
            } elseif (($_POST['kreckAction'] ?? '') === 'save' && !empty($result['found'])) {
                // Map the pulled gradebook to the import-store row shape and persist.
                $rows = [];
                foreach (($result['courses'] ?? []) as $c) {
                    $items = $c['items'] ?? [];
                    if (!empty($c['coursetotal'])) {
                        $ct = $c['coursetotal'];
                        $ct['name'] = __('Course total');
                        $items[] = $ct;
                    }
                    foreach ($items as $it) {
                        if (empty($it['graded'])) { continue; }
                        $rows[] = [
                            'studentid'      => $joinKey,
                            'courseshortname' => $c['shortname'] ?? '',
                            'item'           => $it['name'] ?? '',
                            'grade'          => (string) ($it['grade'] ?? $it['display'] ?? ''),
                            'grademax'       => (string) ($it['grademax'] ?? ''),
                            'moodlecourseid' => (int) ($c['courseid'] ?? 0),
                            'exportedat'     => date('Y-m-d H:i:s', (int) ($result['generated'] ?? time())),
                        ];
                    }
                }
                if (!empty($rows)) {
                    $store = new KreckLinkStore($connection2);
                    $saved = $store->importMarks($rows);
                }
            }
        }
    }
}

// Student picker list (active students this year), ordered by name.
$syid = $connection2->query("SELECT gibbonSchoolYearID FROM gibbonSchoolYear WHERE status='Current' LIMIT 1")->fetchColumn();
$studentsList = $connection2->prepare(
    "SELECT p.gibbonPersonID, p.surname, p.preferredName, p.studentID
     FROM gibbonPerson p
     JOIN gibbonStudentEnrolment se ON se.gibbonPersonID=p.gibbonPersonID AND se.gibbonSchoolYearID=:syid
     WHERE p.status='Full' AND p.gibbonRoleIDPrimary=(SELECT gibbonRoleID FROM gibbonRole WHERE category='Student' ORDER BY gibbonRoleID LIMIT 1)
     ORDER BY p.surname, p.preferredName"
);
$studentsList->execute(['syid' => $syid]);
$studentsList = $studentsList->fetchAll(\PDO::FETCH_ASSOC);

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$lmsConfigured = (stripos((string) ($settings['moodleEndpoint'] ?? ''), 'https://') === 0) && trim((string) ($settings['moodleToken'] ?? '')) !== '';

ob_start();
?>
<style>
.klm{max-width:980px;}
.klm h2{font-family:Georgia,"Times New Roman",Times,serif;color:#0f2233;margin:0 0 .3rem;border-bottom:3px solid #0e9f9a;padding-bottom:8px;}
.klm .klm-lead{color:#334155;font-size:15px;line-height:1.6;margin:12px 0;}
.klm .klm-card{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #0e9f9a;border-radius:12px;padding:1rem 1.2rem;margin:1rem 0;box-shadow:0 1px 3px rgba(15,30,46,.06);}
.klm select,.klm .kbtn{font-size:14px;}
.klm select{padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;min-width:320px;max-width:100%;}
.klm .kbtn{display:inline-block;background:#0e9f9a;color:#fff;border:none;text-decoration:none;border-radius:8px;padding:.6rem 1.1rem;font-weight:600;cursor:pointer;margin-left:.4rem;}
.klm .kbtn.ghost{background:#334155;}
.klm .klm-err{color:#991b1b;background:#fee2e2;border-radius:8px;padding:.6rem .9rem;font-weight:600;margin:.6rem 0;}
.klm .klm-ok{color:#166534;background:#dcfce7;border-radius:8px;padding:.6rem .9rem;font-weight:600;margin:.6rem 0;}
.klm .klm-note{color:#92400e;background:#fef3c7;border-radius:8px;padding:.6rem .9rem;font-size:13px;margin:.6rem 0;}
.klm .klm-stat{display:inline-block;background:#0f1b14;color:#d7f0e4;border-radius:8px;padding:.35rem .7rem;margin:.2rem .4rem .2rem 0;font-size:13px;}
.klm .klm-course{margin:1rem 0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;}
.klm .klm-course h3{margin:0;background:#0f2233;color:#fff;padding:.7rem 1rem;font-size:1rem;display:flex;justify-content:space-between;align-items:center;}
.klm .klm-course h3 .tot{background:#0e9f9a;border-radius:999px;padding:.1rem .7rem;font-size:.85rem;}
.klm table{border-collapse:collapse;width:100%;font-size:13.5px;}
.klm th,.klm td{text-align:left;padding:.45rem .7rem;border-bottom:1px solid #eef2f6;vertical-align:top;}
.klm th{color:#0e6b63;text-transform:uppercase;letter-spacing:.03em;font-size:11px;background:#f8fafc;}
.klm .badge{display:inline-block;border-radius:999px;padding:.05rem .5rem;font-size:11px;font-weight:600;background:#e2e8f0;color:#334155;}
.klm .badge.assign{background:#dbeafe;color:#1e40af;} .klm .badge.quiz{background:#fef3c7;color:#92400e;}
.klm .badge.manual{background:#ede9fe;color:#6d28d9;} .klm .badge.total{background:#dcfce7;color:#166534;}
.klm .pct{font-weight:700;color:#0f2233;}
.klm .muted{color:#94a3b8;}
</style>
<div class="klm">
  <h2><?php echo $e(__('Pull Marks from LMS')); ?></h2>
  <p class="klm-lead">
    <?php echo $e(__('Ask the LMS directly for a learner\'s marks — no export/upload needed. KRECK pulls the full gradebook the LMS holds (every course, assignment, quiz and the course total) and shows it here. You can save the figures to the school record.')); ?>
  </p>

  <?php if (!$lmsConfigured) { ?>
    <p class="klm-note"><?php echo $e(__('The live pull needs an HTTPS LMS endpoint and a web-service token — set them in Kreck Settings. (You can still use the file import on the Marks page.)')); ?></p>
  <?php } ?>

  <div class="klm-card">
    <form method="post" action="<?php echo $e($self); ?>" style="display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;">
      <input type="hidden" name="kreckAction" value="pull">
      <label for="gibbonPersonID" style="font-weight:600;color:#0f2233;"><?php echo $e(__('Student')); ?>:</label>
      <select name="gibbonPersonID" id="gibbonPersonID" required>
        <option value=""><?php echo $e(__('— choose a student —')); ?></option>
        <?php foreach ($studentsList as $s) {
            $label = $s['surname'] . ', ' . $s['preferredName'] . ($s['studentID'] !== '' ? ' (' . $s['studentID'] . ')' : '');
            $sel = ((int) $s['gibbonPersonID'] === $selectedPID) ? ' selected' : ''; ?>
          <option value="<?php echo (int) $s['gibbonPersonID']; ?>"<?php echo $sel; ?>><?php echo $e($label); ?></option>
        <?php } ?>
      </select>
      <button type="submit" class="kbtn"<?php echo $lmsConfigured ? '' : ' disabled'; ?>><?php echo $e(__('Pull marks')); ?></button>
    </form>
  </div>

  <?php if ($error) { ?>
    <p class="klm-err"><?php echo $e($error); ?></p>
  <?php } ?>

  <?php if ($saved !== null) { ?>
    <p class="klm-ok"><?php echo sprintf($e(__('Saved %d mark rows to the school record.')), (int) ($saved['imported'] ?? 0)); ?></p>
  <?php } ?>

  <?php if ($result !== null && empty($result['found'])) { ?>
    <div class="klm-card"><?php echo $e(__('This learner has no marks on the LMS yet — they may not be enrolled there, or nothing has been graded. Nothing to pull.')); ?></div>
  <?php } ?>

  <?php if ($result !== null && !empty($result['found'])) { ?>
    <div class="klm-card">
      <strong style="font-size:1.1rem;color:#0f2233;"><?php echo $e($result['fullname']); ?></strong>
      <div style="margin-top:.5rem;">
        <span class="klm-stat"><?php echo (int) $result['coursecount']; ?> <?php echo $e(__('courses')); ?></span>
        <span class="klm-stat"><?php echo (int) $result['gradedcount']; ?> / <?php echo (int) $result['itemcount']; ?> <?php echo $e(__('items graded')); ?></span>
        <?php if ($result['averagepct'] !== null) { ?><span class="klm-stat"><?php echo $e(__('avg')); ?> <?php echo $e($result['averagepct']); ?>%</span><?php } ?>
        <span class="muted" style="font-size:12px;">&nbsp;<?php echo $e(__('pulled')); ?> <?php echo $e(date('Y-m-d H:i', (int) $result['generated'])); ?></span>
      </div>

      <?php foreach ($result['courses'] as $c) {
          $ct = $c['coursetotal'] ?? null; ?>
        <div class="klm-course">
          <h3>
            <span><?php echo $e($c['fullname']); ?> <span class="muted" style="font-weight:400;">(<?php echo $e($c['shortname']); ?>)</span></span>
            <?php if ($ct && !empty($ct['graded'])) { ?><span class="tot"><?php echo $e(__('Total')); ?>: <?php echo $e($ct['display']); ?><?php echo $ct['percentage'] !== null ? ' · ' . $e($ct['percentage']) . '%' : ''; ?></span><?php } ?>
          </h3>
          <table>
            <thead><tr>
              <th><?php echo $e(__('Item')); ?></th><th><?php echo $e(__('Type')); ?></th>
              <th><?php echo $e(__('Grade')); ?></th><th><?php echo $e(__('%')); ?></th>
              <th><?php echo $e(__('Letter')); ?></th><th><?php echo $e(__('Feedback')); ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($c['items'])) { ?>
              <tr><td colspan="6" class="muted"><?php echo $e(__('No gradebook items.')); ?></td></tr>
            <?php } foreach ($c['items'] as $it) {
                $mod = $it['module'] !== '' ? $it['module'] : $it['itemtype'];
                $badge = in_array($mod, ['assign', 'quiz', 'manual'], true) ? $mod : 'manual'; ?>
              <tr>
                <td><?php echo $e($it['name']); ?><?php echo !empty($it['hidden']) ? ' <span class="muted">('.$e(__('hidden')).')</span>' : ''; ?></td>
                <td><span class="badge <?php echo $e($badge); ?>"><?php echo $e($mod); ?></span></td>
                <td><?php echo !empty($it['graded']) ? $e($it['display']) . ' <span class="muted">/ ' . $e($it['grademax']) . '</span>' : '<span class="muted">—</span>'; ?></td>
                <td class="pct"><?php echo $it['percentage'] !== null ? $e($it['percentage']) . '%' : '<span class="muted">—</span>'; ?></td>
                <td><?php echo $it['letter'] !== '' ? $e($it['letter']) : '<span class="muted">—</span>'; ?></td>
                <td><?php echo $it['feedback'] !== '' ? $e($it['feedback']) : '<span class="muted">—</span>'; ?></td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
        </div>
      <?php } ?>

      <form method="post" action="<?php echo $e($self); ?>" style="margin-top:.6rem;">
        <input type="hidden" name="kreckAction" value="save">
        <input type="hidden" name="gibbonPersonID" value="<?php echo (int) $selectedPID; ?>">
        <button type="submit" class="kbtn ghost"><?php echo $e(__('Save these marks to the school record')); ?></button>
      </form>
    </div>
  <?php } ?>
</div>
<?php
echo ob_get_clean();
