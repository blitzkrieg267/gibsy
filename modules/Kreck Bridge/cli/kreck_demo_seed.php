<?php
/**
 * KRECK — comprehensive demo seeder (SMS / Gibbon side).
 *
 * Seeds ONE of (nearly) everything so the whole system can be demonstrated:
 *   - 15 known logins across every actor (admin, principal, bursar/finance,
 *     registrar, HOD, teachers, support, librarian, students, parents),
 *   - a demo course + class, slotted into the live timetable,
 *   - class enrolments (teachers + students) and parent↔student family links,
 *   - simulated attendance across the school year,
 *   - finance: invoices (paid, part-paid, outstanding) for tuition + transport,
 *   - an enrichment activity enrolment,
 *   - KRECK assessment: a reviewed+approved mark sheet with final grades,
 *   - KRECK planner lessons ("lessons done"),
 *   - KRECK payroll: salaries (approved) + a paid salary payment each.
 *
 * It is CURATED (a handful of rows per area), not bulk — enough to demo.
 * It is IDEMPOTENT: every run first wipes anything it previously created
 * (demo.* accounts + rows tagged [KRECKDEMO]) and reseeds from clean, so you
 * can re-run it any time.
 *
 * Run from the SMS web root:
 *     php "modules/Kreck Bridge/cli/kreck_demo_seed.php"
 *
 * At the end it prints the 15 logins (all share the demo password below).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require __DIR__ . '/../../../gibbon.php';

if (!function_exists('isCommandLineInterface') || !isCommandLineInterface()) {
    fwrite(STDERR, "The KRECK demo seeder runs on the command line only.\n");
    exit(2);
}

use Gibbon\Contracts\Database\Connection;

/** @var \PDO $connection2 */
$pdo = $connection2;
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$conn = $container->get(Connection::class);

require_once __DIR__ . '/../src/AssessmentManager.php';
require_once __DIR__ . '/../src/SalaryManager.php';
require_once __DIR__ . '/../src/PlannerManager.php';
require_once __DIR__ . '/../src/FamilyLink.php';

// ---- Config ---------------------------------------------------------------
const DEMO_PASSWORD = 'Demo@Kreck26';     // meets the default Gibbon password policy
const MARK = '[KRECKDEMO]';               // tag on every demo data row for cleanup
const USER_PREFIX = 'demo.';

$out = [];
function say($m) { global $out; echo $m . "\n"; $out[] = $m; }
function fail_phase($name, \Throwable $e) {
    say("  !! $name failed: " . $e->getMessage() . " @ " . basename($e->getFile()) . ':' . $e->getLine());
}

// ---- Helpers --------------------------------------------------------------
$one = function (string $sql, array $b = []) use ($pdo) {
    $s = $pdo->prepare($sql); $s->execute($b); $r = $s->fetch(\PDO::FETCH_ASSOC); return $r === false ? null : $r;
};
$val = function (string $sql, array $b = []) use ($pdo) {
    $s = $pdo->prepare($sql); $s->execute($b); return $s->fetchColumn();
};

$hashPassword = function (string $plain) {
    $salt = function_exists('getSalt') ? getSalt() : bin2hex(random_bytes(18));
    return [hash('sha256', $salt . $plain), $salt];
};

// ---- Context --------------------------------------------------------------
$sy = $one("SELECT gibbonSchoolYearID, name, firstDay, lastDay FROM gibbonSchoolYear WHERE status='Current' LIMIT 1");
if (!$sy) { say("No current school year — aborting."); exit(1); }
$syid = $sy['gibbonSchoolYearID'];
$firstDay = $sy['firstDay']; $lastDay = $sy['lastDay'];
say("KRECK demo seeder — school year {$sy['name']} ($firstDay … $lastDay)");
say(str_repeat('=', 66));

// Pick live anchors to attach demo data to (reused, never modified destructively).
$deptID     = $val("SELECT gibbonDepartmentID FROM gibbonDepartment ORDER BY gibbonDepartmentID LIMIT 1");
$formGroup  = $val("SELECT gibbonFormGroupID FROM gibbonFormGroup WHERE gibbonSchoolYearID=:y ORDER BY gibbonFormGroupID LIMIT 1", ['y' => $syid]);
$yearGroup  = $val("SELECT gibbonYearGroupID FROM gibbonYearGroup ORDER BY gibbonYearGroupID LIMIT 1");
$spaceID    = $val("SELECT gibbonSpaceID FROM gibbonSpace ORDER BY gibbonSpaceID LIMIT 1");
$activityID = $val("SELECT gibbonActivityID FROM gibbonActivity WHERE active='Y' AND gibbonSchoolYearID=:y ORDER BY gibbonActivityID LIMIT 1", ['y' => $syid]);
$ttID       = $val("SELECT gibbonTTID FROM gibbonTT WHERE gibbonSchoolYearID=:y AND active='Y' LIMIT 1", ['y' => $syid]);
$feeTuition = $one("SELECT gibbonFinanceFeeID, gibbonFinanceFeeCategoryID, fee, name FROM gibbonFinanceFee WHERE gibbonSchoolYearID=:y AND name LIKE '%Tuition%' ORDER BY gibbonFinanceFeeID LIMIT 1", ['y' => $syid])
           ?: $one("SELECT gibbonFinanceFeeID, gibbonFinanceFeeCategoryID, fee, name FROM gibbonFinanceFee WHERE gibbonSchoolYearID=:y ORDER BY gibbonFinanceFeeID LIMIT 1", ['y' => $syid]);
$feeTransport = $one("SELECT gibbonFinanceFeeID, gibbonFinanceFeeCategoryID, fee, name FROM gibbonFinanceFee WHERE gibbonSchoolYearID=:y AND name LIKE '%Transport%' ORDER BY gibbonFinanceFeeID LIMIT 1", ['y' => $syid]);

$adminActor = (int) $val("SELECT gibbonPersonID FROM gibbonPerson WHERE gibbonRoleIDPrimary='001' AND status='Full' ORDER BY gibbonPersonID LIMIT 1");

// =====================================================================
// PHASE 0 — wipe any previous demo data (idempotency)
// =====================================================================
try {
    // Collect previously-created demo person ids.
    $ids = $pdo->query("SELECT gibbonPersonID FROM gibbonPerson WHERE username LIKE '" . USER_PREFIX . "%'")->fetchAll(\PDO::FETCH_COLUMN);
    $demoClassIDs = $pdo->query("SELECT gibbonCourseClassID FROM gibbonCourseClass WHERE nameShort='KD-A'")->fetchAll(\PDO::FETCH_COLUMN);
    $demoCourseIDs = $pdo->query("SELECT gibbonCourseID FROM gibbonCourse WHERE nameShort='KRECKDEMO'")->fetchAll(\PDO::FETCH_COLUMN);

    $pdo->beginTransaction();
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        // KRECK tables
        $pdo->exec("DELETE FROM kreckSalaryPayment WHERE gibbonPersonID IN ($in)");
        $pdo->exec("DELETE FROM kreckSalary WHERE gibbonPersonID IN ($in)");
        $pdo->exec("DELETE FROM kreckAssessmentMark WHERE gibbonPersonIDStudent IN ($in)");
        // Attendance / finance / activities / enrolment / family for demo people
        $pdo->exec("DELETE FROM gibbonAttendanceLogPerson WHERE gibbonPersonID IN ($in)");
        $pdo->exec("DELETE FROM gibbonAttendanceLogCourseClass WHERE gibbonPersonIDTaker IN ($in)");
        $pdo->exec("DELETE FROM gibbonFinanceInvoice WHERE gibbonFinanceInvoiceeID IN (SELECT gibbonFinanceInvoiceeID FROM gibbonFinanceInvoicee WHERE gibbonPersonID IN ($in))");
        $pdo->exec("DELETE FROM gibbonFinanceInvoicee WHERE gibbonPersonID IN ($in)");
        $pdo->exec("DELETE FROM gibbonActivityStudent WHERE gibbonPersonID IN ($in)");
        $pdo->exec("DELETE FROM gibbonCourseClassPerson WHERE gibbonPersonID IN ($in)");
        $pdo->exec("DELETE FROM gibbonStudentEnrolment WHERE gibbonPersonID IN ($in)");
        $pdo->exec("DELETE FROM gibbonDepartmentStaff WHERE gibbonPersonID IN ($in)");
        $pdo->exec("DELETE FROM gibbonStaff WHERE gibbonPersonID IN ($in)");
        // Family rows for demo adults/children
        $pdo->exec("DELETE FROM gibbonFamilyRelationship WHERE gibbonPersonID1 IN ($in) OR gibbonPersonID2 IN ($in)");
        $pdo->exec("DELETE FROM gibbonFamilyAdult WHERE gibbonPersonID IN ($in)");
        $pdo->exec("DELETE FROM gibbonFamilyChild WHERE gibbonPersonID IN ($in)");
        $pdo->exec("DELETE FROM gibbonFamily WHERE name LIKE 'Demo %Family' OR name LIKE '%" . MARK . "%'");
        $pdo->exec("DELETE FROM gibbonPersonStatusLog WHERE gibbonPersonID IN ($in)");
    }
    // Demo class/course + their timetable slots, assessments, planner (by demo class)
    if ($demoClassIDs) {
        $cin = implode(',', array_map('intval', $demoClassIDs));
        $akids = $pdo->query("SELECT kreckAssessmentID FROM kreckAssessment WHERE gibbonCourseClassID IN ($cin)")->fetchAll(\PDO::FETCH_COLUMN);
        if ($akids) { $ak = implode(',', array_map('intval', $akids));
            foreach (['kreckAssessmentMark','kreckAssessmentAudit','kreckAssessmentNotification'] as $t) $pdo->exec("DELETE FROM $t WHERE kreckAssessmentID IN ($ak)");
            $pdo->exec("DELETE FROM kreckAssessment WHERE kreckAssessmentID IN ($ak)");
        }
        $pdo->exec("DELETE FROM gibbonPlannerEntry WHERE gibbonCourseClassID IN ($cin)");
        $pdo->exec("DELETE FROM gibbonTTDayRowClass WHERE gibbonCourseClassID IN ($cin)");
        $pdo->exec("DELETE FROM gibbonCourseClassPerson WHERE gibbonCourseClassID IN ($cin)");
        $pdo->exec("DELETE FROM gibbonCourseClass WHERE gibbonCourseClassID IN ($cin)");
    }
    if ($demoCourseIDs) { $pdo->exec("DELETE FROM gibbonCourse WHERE gibbonCourseID IN (" . implode(',', array_map('intval', $demoCourseIDs)) . ")"); }
    if ($ids) { $pdo->exec("DELETE FROM gibbonPerson WHERE gibbonPersonID IN (" . implode(',', array_map('intval', $ids)) . ")"); }
    $pdo->commit();
    say("Phase 0: wiped previous demo data (" . count($ids) . " accounts).");
} catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); fail_phase('wipe', $e); }

// =====================================================================
// PHASE 1 — the 15 demo accounts
// =====================================================================
[$pwHash, $pwSalt] = $hashPassword(DEMO_PASSWORD);

$accounts = [
    // key           username           first      surname       role  gender
    ['admin',     USER_PREFIX.'admin',     'Thandiwe','Admin',     '001','F','School Administrator'],
    ['principal', USER_PREFIX.'principal', 'Kagiso',  'Principal', '001','M','Principal'],
    ['bursar',    USER_PREFIX.'bursar',    'Naledi',  'Bursar',    '001','F','Bursar / Finance Officer'],
    ['registrar', USER_PREFIX.'registrar', 'Oarabile','Registrar', '001','M','Registrar / Admissions'],
    ['hod',       USER_PREFIX.'hod',       'Mpho',    'Molefe',    '002','F','Head of Department'],
    ['teacher1',  USER_PREFIX.'teacher1',  'Tebogo',  'Khan',      '002','M','Teacher'],
    ['teacher2',  USER_PREFIX.'teacher2',  'Lesego',  'Dube',      '002','F','Teacher'],
    ['support',   USER_PREFIX.'support',   'Boitumelo','Support',  '006','M','IT / Support Staff'],
    ['librarian', USER_PREFIX.'librarian', 'Palesa',  'Books',     '006','F','Librarian'],
    ['student1',  USER_PREFIX.'student1',  'Amara',   'Mokoena',   '003','F','Student'],
    ['student2',  USER_PREFIX.'student2',  'Katlego', 'Sithole',   '003','M','Student'],
    ['student3',  USER_PREFIX.'student3',  'Zinhle',  'Ndlovu',    '003','F','Student'],
    ['parent1',   USER_PREFIX.'parent1',   'Grace',   'Mokoena',   '004','F','Parent of Amara'],
    ['parent2',   USER_PREFIX.'parent2',   'Sipho',   'Sithole',   '004','M','Parent of Katlego'],
    ['parent3',   USER_PREFIX.'parent3',   'Nomsa',   'Ndlovu',    '004','F','Parent of Zinhle'],
];
$P = []; // key => gibbonPersonID
try {
    $ins = $pdo->prepare(
        "INSERT INTO gibbonPerson SET
           title='', surname=:surname, firstName=:firstName, preferredName=:firstName,
           officialName=:official, gender=:gender, username=:username,
           passwordStrong=:pw, passwordStrongSalt=:salt,
           status='Full', canLogin='Y', passwordForceReset='N',
           gibbonRoleIDPrimary=:role, gibbonRoleIDAll=:role,
           email=:email, dateStart=:today"
    );
    foreach ($accounts as $a) {
        [$key, $username, $first, $sur, $role, $gender, $desc] = $a;
        $ins->execute([
            'surname' => $sur, 'firstName' => $first, 'official' => "$first $sur",
            'gender' => $gender, 'username' => $username, 'pw' => $pwHash, 'salt' => $pwSalt,
            'role' => $role, 'email' => $username . '@demo.kreck.local', 'today' => date('Y-m-d'),
        ]);
        $P[$key] = (int) $pdo->lastInsertId();
    }
    say("Phase 1: created " . count($P) . " demo accounts (password: " . DEMO_PASSWORD . ").");
} catch (\Throwable $e) { fail_phase('accounts', $e); }

// Staff directory rows + HOD department coordinator role.
try {
    // initials is a UNIQUE column, so derive a distinct value per demo staffer.
    $staffIns = $pdo->prepare("INSERT INTO gibbonStaff SET gibbonPersonID=:pid, type=:type, initials=:ini, jobTitle=:job,
        countryOfOrigin='', qualifications='', biography='', biographicalGrouping='', biographicalGroupingPriority=0");
    $staffMap = [
        'admin' => ['Ancillary', 'School Administrator'], 'principal' => ['Teaching', 'Principal'],
        'bursar' => ['Ancillary', 'Bursar'], 'registrar' => ['Ancillary', 'Registrar'],
        'hod' => ['Teaching', 'Head of Department'], 'teacher1' => ['Teaching', 'Teacher'],
        'teacher2' => ['Teaching', 'Teacher'], 'support' => ['Support', 'IT Support'],
        'librarian' => ['Support', 'Librarian'],
    ];
    $si = 0;
    foreach ($staffMap as $k => $m) {
        if (!isset($P[$k])) continue;
        $si++;
        $initials = 'KD' . str_pad((string) $si, 2, '0', STR_PAD_LEFT); // KD01..KD09, unique
        $staffIns->execute(['pid' => $P[$k], 'type' => $m[0], 'ini' => $initials, 'job' => $m[1]]);
    }
    if (isset($P['hod']) && $deptID) {
        $pdo->prepare("INSERT INTO gibbonDepartmentStaff SET gibbonDepartmentID=:d, gibbonPersonID=:p, role='Coordinator'")
            ->execute(['d' => $deptID, 'p' => $P['hod']]);
    }
    say("Phase 1b: staff directory + HOD department coordinator set.");
} catch (\Throwable $e) { fail_phase('staff', $e); }

// =====================================================================
// PHASE 2 — demo course + class, enrolments, timetable slot
// =====================================================================
$demoClassID = null;
try {
    $pdo->prepare("INSERT INTO gibbonCourse SET gibbonSchoolYearID=:y, gibbonDepartmentID=:d, name='KRECK Demo Course', nameShort='KRECKDEMO', description=:desc, map='N', gibbonYearGroupIDList=:yg, orderBy=0")
        ->execute(['y' => $syid, 'd' => $deptID, 'desc' => 'KRECK demonstration course. ' . MARK, 'yg' => $yearGroup]);
    $courseID = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO gibbonCourseClass SET gibbonCourseID=:c, name='KRECK Demo Class', nameShort='KD-A', reportable='Y', attendance='Y'")
        ->execute(['c' => $courseID]);
    $demoClassID = (int) $pdo->lastInsertId();

    $cp = $pdo->prepare("INSERT INTO gibbonCourseClassPerson SET gibbonCourseClassID=:cc, gibbonPersonID=:p, role=:role, dateEnrolled=:today, reportable='Y'");
    $cp->execute(['cc' => $demoClassID, 'p' => $P['teacher1'], 'role' => 'Teacher', 'today' => date('Y-m-d')]);
    $cp->execute(['cc' => $demoClassID, 'p' => $P['teacher2'], 'role' => 'Assistant', 'today' => date('Y-m-d')]);
    $cp->execute(['cc' => $demoClassID, 'p' => $P['hod'], 'role' => 'Teacher', 'today' => date('Y-m-d')]);
    foreach (['student1', 'student2', 'student3'] as $sk) {
        $cp->execute(['cc' => $demoClassID, 'p' => $P[$sk], 'role' => 'Student', 'today' => date('Y-m-d')]);
        // Year enrolment so they are "active students this year".
        $pdo->prepare("INSERT INTO gibbonStudentEnrolment SET gibbonPersonID=:p, gibbonSchoolYearID=:y, gibbonYearGroupID=:yg, gibbonFormGroupID=:fg, rollOrder=0")
            ->execute(['p' => $P[$sk], 'y' => $syid, 'yg' => $yearGroup, 'fg' => $formGroup]);
    }
    say("Phase 2: demo course + class (id $demoClassID) with 3 teachers-of-record + 3 students.");

    // Timetable slots: put the demo class on a few lesson periods so it shows on the TT.
    if ($ttID) {
        $days = $pdo->query("SELECT gibbonTTDayID, gibbonTTColumnID FROM gibbonTTDay WHERE gibbonTTID='$ttID' ORDER BY gibbonTTDayID LIMIT 3")->fetchAll(\PDO::FETCH_ASSOC);
        $slotIns = $pdo->prepare("INSERT INTO gibbonTTDayRowClass SET gibbonTTColumnRowID=:row, gibbonTTDayID=:day, gibbonCourseClassID=:cc, gibbonSpaceID=:space");
        $slots = 0;
        foreach ($days as $d) {
            $row = $val("SELECT gibbonTTColumnRowID FROM gibbonTTColumnRow WHERE gibbonTTColumnID=:col AND type='Lesson' ORDER BY timeStart LIMIT 1", ['col' => $d['gibbonTTColumnID']]);
            if ($row) { $slotIns->execute(['row' => $row, 'day' => $d['gibbonTTDayID'], 'cc' => $demoClassID, 'space' => $spaceID ?: null]); $slots++; }
        }
        say("Phase 2b: demo class slotted into $slots timetable periods.");
    }
} catch (\Throwable $e) { fail_phase('class/timetable', $e); }

// =====================================================================
// PHASE 3 — parent ↔ student family links
// =====================================================================
try {
    $fl = new KreckFamilyLink($pdo, $container);
    $pairs = [['parent1', 'student1'], ['parent2', 'student2'], ['parent3', 'student3']];
    $linked = 0;
    foreach ($pairs as [$pk, $sk]) {
        $res = $fl->linkExisting($P[$sk], $P[$pk], 'Mother', 1);
        if (!empty($res['ok'])) $linked++;
    }
    say("Phase 3: linked $linked parent↔student families (childDataAccess=Y).");
} catch (\Throwable $e) { fail_phase('family links', $e); }

// =====================================================================
// PHASE 4 — simulated attendance across the year
// =====================================================================
try {
    $codePresent = $val("SELECT gibbonAttendanceCodeID FROM gibbonAttendanceCode WHERE name='Present' LIMIT 1");
    $codeLate    = $val("SELECT gibbonAttendanceCodeID FROM gibbonAttendanceCode WHERE name LIKE 'Present - Late%' LIMIT 1") ?: $codePresent;
    $codeAbsent  = $val("SELECT gibbonAttendanceCodeID FROM gibbonAttendanceCode WHERE name='Absent' LIMIT 1") ?: $codePresent;

    $logIns = $pdo->prepare(
        "INSERT INTO gibbonAttendanceLogPerson SET
           gibbonAttendanceCodeID=:code, gibbonPersonID=:p, direction='In', type=:type, reason='',
           context='Class', comment=:mark, date=:date, gibbonPersonIDTaker=:taker,
           gibbonCourseClassID=:cc, timestampTaken=NOW()"
    );
    $start = new DateTime($firstDay); $end = new DateTime($lastDay);
    $today = new DateTime(date('Y-m-d')); if ($end > $today) $end = $today;
    $rows = 0; $i = 0;
    for ($d = clone $start; $d <= $end; $d->modify('+5 days')) { // ~ weekly sample
        if ((int) $d->format('N') >= 6) continue; // skip weekends
        foreach (['student1', 'student2', 'student3'] as $n => $sk) {
            $i++;
            // mostly present; occasional late / absent for realism
            $code = $codePresent; $type = 'Present';
            if ($i % 11 === 0) { $code = $codeLate; $type = 'Present - Late'; }
            elseif ($i % 17 === 0) { $code = $codeAbsent; $type = 'Absent'; }
            $logIns->execute([
                'code' => $code, 'p' => $P[$sk], 'type' => $type, 'mark' => MARK,
                'date' => $d->format('Y-m-d'), 'taker' => $P['teacher1'], 'cc' => $demoClassID,
            ]);
            $rows++;
        }
    }
    say("Phase 4: $rows attendance records across the year (present / late / absent mix).");
} catch (\Throwable $e) { fail_phase('attendance', $e); }

// =====================================================================
// PHASE 5 — finance: invoices (paid, part-paid, outstanding)
// =====================================================================
try {
    $catList = trim(($feeTuition['gibbonFinanceFeeCategoryID'] ?? '') . ',' . ($feeTransport['gibbonFinanceFeeCategoryID'] ?? ''), ',');
    $tuition = (float) ($feeTuition['fee'] ?? 2500);
    $transport = (float) ($feeTransport['fee'] ?? 600);
    $total = $tuition + $transport;

    $invoiceeIns = $pdo->prepare("INSERT INTO gibbonFinanceInvoicee SET gibbonPersonID=:p, invoiceTo='Family', gibbonFinanceFeeCategoryIDList=:cats");
    $invIns = $pdo->prepare(
        "INSERT INTO gibbonFinanceInvoice SET
           gibbonSchoolYearID=:y, gibbonFinanceInvoiceeID=:iid, invoiceTo='Family',
           billingScheduleType='Ad Hoc', status=:status, gibbonFinanceFeeCategoryIDList=:cats,
           invoiceIssueDate=:issue, invoiceDueDate=:due, paidDate=:paiddate, paidAmount=:paidamt,
           notes=:notes, `key`=:key, gibbonPersonIDCreator=:creator, timestampCreator=NOW()"
    );
    $scenarios = [
        ['student1', 'Paid',         $total, date('Y-m-d', strtotime($firstDay))],
        ['student2', 'Issued',       null,   null],
        ['student3', 'Paid - Partial', round($total / 2, 2), date('Y-m-d', strtotime($firstDay . ' +20 days'))],
    ];
    $made = 0;
    foreach ($scenarios as [$sk, $status, $paidAmt, $paidDate]) {
        $invoiceeIns->execute(['p' => $P[$sk], 'cats' => $catList]);
        $iid = (int) $pdo->lastInsertId();
        $invIns->execute([
            'y' => $syid, 'iid' => $iid, 'status' => $status, 'cats' => $catList,
            'issue' => date('Y-m-d', strtotime($firstDay)), 'due' => date('Y-m-d', strtotime($firstDay . ' +30 days')),
            'paiddate' => $paidDate, 'paidamt' => $paidAmt,
            'notes' => "Demo invoice: {$feeTuition['name']} + " . ($feeTransport['name'] ?? 'Transport') . " (total $total). " . MARK,
            'key' => 'KRECKDEMO-' . $sk . '-' . substr(md5(uniqid()), 0, 8), 'creator' => $P['bursar'] ?? $adminActor,
        ]);
        $made++;
    }
    say("Phase 5: $made finance invoices (Paid / Outstanding / Part-paid) — tuition + transport.");
} catch (\Throwable $e) { fail_phase('finance', $e); }

// =====================================================================
// PHASE 6 — activity enrolment
// =====================================================================
try {
    if ($activityID) {
        $actIns = $pdo->prepare("INSERT INTO gibbonActivityStudent SET gibbonActivityID=:a, gibbonPersonID=:p, status='Accepted', timestamp=NOW()");
        foreach (['student1', 'student2'] as $sk) $actIns->execute(['a' => $activityID, 'p' => $P[$sk]]);
        $an = $val("SELECT name FROM gibbonActivity WHERE gibbonActivityID=:a", ['a' => $activityID]);
        say("Phase 6: enrolled 2 students into activity \"" . trim($an) . "\".");
    } else { say("Phase 6: no active activity found to enrol into (skipped)."); }
} catch (\Throwable $e) { fail_phase('activities', $e); }

// =====================================================================
// PHASE 7 — KRECK assessment: prepare → marks → submit → approve
// =====================================================================
try {
    $am = new KreckAssessmentManager($pdo);
    $assessmentID = $am->prepareForReviewer($demoClassID, (int) $syid, 'Semester 1', $P['hod']);
    $am->setDeadline($assessmentID, $P['hod'], date('Y-m-d', strtotime($lastDay . ' -30 days')));
    $roster = $am->studentsForClass($demoClassID);
    $marks = []; $i = 0;
    foreach ($roster as $r) {
        $i++;
        $marks[(int) $r['gibbonPersonID']] = [
            'ca1Mark' => 60 + ($i * 7) % 35, 'ca2Mark' => 55 + ($i * 11) % 40,
            'examMark' => 50 + ($i * 13) % 45, 'comment' => 'Demo mark. ' . MARK,
        ];
    }
    $am->saveDraft($demoClassID, (int) $syid, 'Semester 1', ['ca1' => 20, 'ca2' => 20, 'exam' => 60], $marks, $P['teacher1']);
    $am->submit($demoClassID, (int) $syid, 'Semester 1', $P['teacher1']);
    $am->review($assessmentID, $P['hod'], 'approve', 'Approved for demo. ' . MARK);
    say("Phase 7: KRECK assessment sheet prepared, marked, submitted & APPROVED (" . count($roster) . " students graded).");
} catch (\Throwable $e) { fail_phase('assessment', $e); }

// =====================================================================
// PHASE 8 — KRECK planner lessons ("lessons done")
// =====================================================================
try {
    $pm = new KreckPlannerManager($conn);
    $lessons = [
        ['Introduction & Baseline', '-40 days', '08:00:00', '09:00:00'],
        ['Core Concepts Workshop',  '-26 days', '09:00:00', '10:00:00'],
        ['Practical & Assessment Prep', '-12 days', '10:00:00', '11:00:00'],
    ];
    $made = 0;
    foreach ($lessons as [$name, $when, $ts, $te]) {
        $pm->createEntry([
            'gibbonCourseClassID' => $demoClassID, 'date' => date('Y-m-d', strtotime($lastDay . ' ' . $when)),
            'timeStart' => $ts, 'timeEnd' => $te, 'name' => $name,
            'summary' => 'Demo lesson. ' . MARK, 'description' => 'KRECK demo planner lesson: ' . $name . '. ' . MARK,
            'viewableParents' => 'Y', 'viewableStudents' => 'Y',
        ], $P['teacher1']);
        $made++;
    }
    say("Phase 8: $made planner lessons added to the demo class.");
} catch (\Throwable $e) { fail_phase('planner', $e); }

// =====================================================================
// PHASE 9 — KRECK payroll: salaries + a paid payment each
// =====================================================================
try {
    $sm = new KreckSalaryManager($conn);
    $payIns = $pdo->prepare(
        "INSERT INTO kreckSalaryPayment SET kreckSalaryID=:sid, gibbonPersonID=:pid, period=:period,
           dueDate=:due, amount=:amount, status='Paid', paymentDate=:paid, paymentMethod='Bank Transfer',
           notes=:notes, gibbonPersonIDCreator=:creator, timestampCreator=NOW()"
    );
    $payroll = [['hod', 18500.00], ['teacher1', 14250.00], ['teacher2', 13800.00], ['support', 9200.00]];
    $made = 0;
    foreach ($payroll as [$k, $amount]) {
        $sid = $sm->saveSalary([
            'gibbonPersonID' => $P[$k], 'amount' => $amount, 'currency' => 'BWP', 'frequency' => 'Monthly',
            'payDay' => 25, 'startDate' => date('Y-m-d', strtotime($firstDay)), 'status' => 'Active',
            'category' => 'Teaching', 'notes' => 'Demo salary. ' . MARK,
        ], $P['bursar'] ?? $adminActor);
        $sm->decideSalary($sid, 'Approved', $P['principal'] ?? $adminActor, 'Approved for demo. ' . MARK);
        // One finalized+paid payment for the current month.
        $payIns->execute([
            'sid' => $sid, 'pid' => $P[$k], 'period' => date('Y-m', strtotime($lastDay . ' -20 days')),
            'due' => date('Y-m-25', strtotime($lastDay . ' -20 days')), 'amount' => $amount,
            'paid' => date('Y-m-24', strtotime($lastDay . ' -20 days')),
            'notes' => 'Demo payroll run. ' . MARK, 'creator' => $P['bursar'] ?? $adminActor,
        ]);
        $made++;
    }
    say("Phase 9: $made salaries approved + a paid payment each (payroll).");
} catch (\Throwable $e) { fail_phase('payroll', $e); }

// =====================================================================
// Summary — the 15 logins
// =====================================================================
say("");
say(str_repeat('=', 66));
say("DEMO LOGINS   (password for all: " . DEMO_PASSWORD . ")");
say(str_repeat('-', 66));
say(sprintf("  %-20s %-10s %s", 'USERNAME', 'ROLE', 'WHO'));
$roleName = ['001' => 'Admin', '002' => 'Teacher', '003' => 'Student', '004' => 'Parent', '006' => 'Support'];
foreach ($accounts as $a) {
    [$key, $username, $first, $sur, $role, $gender, $desc] = $a;
    say(sprintf("  %-20s %-10s %s (%s %s)", $username, $roleName[$role] ?? $role, $desc, $first, $sur));
}
say(str_repeat('=', 66));
say("Done. Log in at the SMS, then run KRECK Export to sync this demo to the LMS.");
