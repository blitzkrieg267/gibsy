<?php
/**
 * KRECK — comprehensive demo seeder (reusable engine).
 *
 * Seeds ONE of (nearly) everything so the whole system can be demonstrated:
 * 15 logins across every actor, a demo course/class slotted into the timetable,
 * enrolments, parent↔student family links, simulated attendance across the year,
 * finance invoices (paid / part-paid / outstanding), an activity enrolment, a
 * reviewed+approved KRECK assessment with grades, planner lessons, and payroll
 * (approved salaries + a paid payment each).
 *
 * The logic lives here so BOTH the CLI (cli/kreck_demo_seed.php) and the admin
 * page (kreck_demo_seed.php) can run it. It is CURATED (a handful of rows per
 * area) and IDEMPOTENT: seed() first wipes anything a previous run created
 * (demo.* accounts + rows tagged [KRECKDEMO]) and reseeds from clean.
 */

require_once __DIR__ . '/AssessmentManager.php';
require_once __DIR__ . '/SalaryManager.php';
require_once __DIR__ . '/PlannerManager.php';
require_once __DIR__ . '/FamilyLink.php';
require_once __DIR__ . '/ManifestBuilder.php';
require_once __DIR__ . '/Exporter.php';

use Gibbon\Contracts\Database\Connection;

class KreckDemoSeeder
{
    /** Shared demo password (meets the default Gibbon password policy). */
    const DEMO_PASSWORD = 'Demo@Kreck26';
    /** Tag written on demo data rows so they can be found and wiped. */
    const MARK = '[KRECKDEMO]';
    /** Prefix for every demo login username. */
    const USER_PREFIX = 'demo.';

    /** The 15 demo accounts: [key, username, first, surname, roleID, gender, description]. */
    const ACCOUNTS = [
        ['admin',     'demo.admin',     'Thandiwe', 'Admin',     '001', 'F', 'School Administrator'],
        ['principal', 'demo.principal', 'Kagiso',   'Principal', '001', 'M', 'Principal'],
        ['bursar',    'demo.bursar',    'Naledi',   'Bursar',    '001', 'F', 'Bursar / Finance Officer'],
        ['registrar', 'demo.registrar', 'Oarabile', 'Registrar', '001', 'M', 'Registrar / Admissions'],
        ['hod',       'demo.hod',       'Mpho',     'Molefe',    '002', 'F', 'Head of Department'],
        ['teacher1',  'demo.teacher1',  'Tebogo',   'Khan',      '002', 'M', 'Teacher'],
        ['teacher2',  'demo.teacher2',  'Lesego',   'Dube',      '002', 'F', 'Teacher'],
        ['support',   'demo.support',   'Boitumelo','Support',   '006', 'M', 'IT / Support Staff'],
        ['librarian', 'demo.librarian', 'Palesa',   'Books',     '006', 'F', 'Librarian'],
        ['student1',  'demo.student1',  'Amara',    'Mokoena',   '003', 'F', 'Student'],
        ['student2',  'demo.student2',  'Katlego',  'Sithole',   '003', 'M', 'Student'],
        ['student3',  'demo.student3',  'Zinhle',   'Ndlovu',    '003', 'F', 'Student'],
        ['parent1',   'demo.parent1',   'Grace',    'Mokoena',   '004', 'F', 'Parent of Amara'],
        ['parent2',   'demo.parent2',   'Sipho',    'Sithole',   '004', 'M', 'Parent of Katlego'],
        ['parent3',   'demo.parent3',   'Nomsa',    'Ndlovu',    '004', 'F', 'Parent of Zinhle'],
    ];

    /** @var \PDO */
    private $pdo;
    /** @var mixed DI container */
    private $container;
    /** @var Connection|null Gibbon Connection (for Salary/Planner managers) */
    private $conn;
    /** @var string[] accumulated progress log */
    private $log = [];

    public function __construct(\PDO $connection2, $container)
    {
        $this->pdo = $connection2;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->container = $container;
        try { $this->conn = $container->get(Connection::class); } catch (\Throwable $e) { $this->conn = null; }
    }

    private function say(string $m): void { $this->log[] = $m; }
    private function failPhase(string $name, \Throwable $e): void {
        $this->say("!! $name failed: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    private function one(string $sql, array $b = []) {
        $s = $this->pdo->prepare($sql); $s->execute($b); $r = $s->fetch(\PDO::FETCH_ASSOC); return $r === false ? null : $r;
    }
    private function val(string $sql, array $b = []) {
        $s = $this->pdo->prepare($sql); $s->execute($b); return $s->fetchColumn();
    }
    private function hashPassword(string $plain): array {
        $salt = function_exists('getSalt') ? getSalt() : bin2hex(random_bytes(18));
        return [hash('sha256', $salt . $plain), $salt];
    }

    /**
     * Remove everything a previous demo run created. Returns the number of demo
     * accounts removed. Safe to call when there is nothing to remove.
     */
    public function wipe(): int
    {
        $pdo = $this->pdo;
        $ids = $pdo->query("SELECT gibbonPersonID FROM gibbonPerson WHERE username LIKE '" . self::USER_PREFIX . "%'")->fetchAll(\PDO::FETCH_COLUMN);
        $demoClassIDs = $pdo->query("SELECT gibbonCourseClassID FROM gibbonCourseClass WHERE nameShort='KD-A'")->fetchAll(\PDO::FETCH_COLUMN);
        $demoCourseIDs = $pdo->query("SELECT gibbonCourseID FROM gibbonCourse WHERE nameShort='KRECKDEMO'")->fetchAll(\PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        try {
            if ($ids) {
                $in = implode(',', array_map('intval', $ids));
                $pdo->exec("DELETE FROM kreckSalaryPayment WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM kreckSalary WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM kreckAssessmentMark WHERE gibbonPersonIDStudent IN ($in)");
                $pdo->exec("DELETE FROM gibbonAttendanceLogPerson WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM gibbonAttendanceLogCourseClass WHERE gibbonPersonIDTaker IN ($in)");
                $pdo->exec("DELETE FROM gibbonFinanceInvoice WHERE gibbonFinanceInvoiceeID IN (SELECT gibbonFinanceInvoiceeID FROM gibbonFinanceInvoicee WHERE gibbonPersonID IN ($in))");
                $pdo->exec("DELETE FROM gibbonFinanceInvoicee WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM gibbonActivityStudent WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM gibbonCourseClassPerson WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM gibbonStudentEnrolment WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM gibbonDepartmentStaff WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM gibbonStaff WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM gibbonFamilyRelationship WHERE gibbonPersonID1 IN ($in) OR gibbonPersonID2 IN ($in)");
                $pdo->exec("DELETE FROM gibbonFamilyAdult WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM gibbonFamilyChild WHERE gibbonPersonID IN ($in)");
                $pdo->exec("DELETE FROM gibbonFamily WHERE name LIKE 'Demo %Family' OR name LIKE '%" . self::MARK . "%'");
                $pdo->exec("DELETE FROM gibbonPersonStatusLog WHERE gibbonPersonID IN ($in)");
            }
            if ($demoClassIDs) {
                $cin = implode(',', array_map('intval', $demoClassIDs));
                $akids = $pdo->query("SELECT kreckAssessmentID FROM kreckAssessment WHERE gibbonCourseClassID IN ($cin)")->fetchAll(\PDO::FETCH_COLUMN);
                if ($akids) {
                    $ak = implode(',', array_map('intval', $akids));
                    foreach (['kreckAssessmentMark', 'kreckAssessmentAudit', 'kreckAssessmentNotification'] as $t) {
                        $pdo->exec("DELETE FROM $t WHERE kreckAssessmentID IN ($ak)");
                    }
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
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return count($ids);
    }

    /**
     * Seed the full demo. Returns:
     *   ['ok'=>bool, 'log'=>string[], 'accounts'=>array, 'password'=>string]
     */
    public function seed(): array
    {
        $pdo = $this->pdo;
        $this->log = [];

        $sy = $this->one("SELECT gibbonSchoolYearID, name, firstDay, lastDay FROM gibbonSchoolYear WHERE status='Current' LIMIT 1");
        if (!$sy) {
            return ['ok' => false, 'log' => ['No current school year — cannot seed.'], 'accounts' => self::ACCOUNTS, 'password' => self::DEMO_PASSWORD];
        }
        $syid = $sy['gibbonSchoolYearID']; $firstDay = $sy['firstDay']; $lastDay = $sy['lastDay'];
        $this->say("School year {$sy['name']} ($firstDay … $lastDay)");

        // Live anchors (reused, never destructively modified).
        $deptID     = $this->val("SELECT gibbonDepartmentID FROM gibbonDepartment ORDER BY gibbonDepartmentID LIMIT 1");
        $formGroup  = $this->val("SELECT gibbonFormGroupID FROM gibbonFormGroup WHERE gibbonSchoolYearID=:y ORDER BY gibbonFormGroupID LIMIT 1", ['y' => $syid]);
        $yearGroup  = $this->val("SELECT gibbonYearGroupID FROM gibbonYearGroup ORDER BY gibbonYearGroupID LIMIT 1");
        $spaceID    = $this->val("SELECT gibbonSpaceID FROM gibbonSpace ORDER BY gibbonSpaceID LIMIT 1");
        $activityID = $this->val("SELECT gibbonActivityID FROM gibbonActivity WHERE active='Y' AND gibbonSchoolYearID=:y ORDER BY gibbonActivityID LIMIT 1", ['y' => $syid]);
        $ttID       = $this->val("SELECT gibbonTTID FROM gibbonTT WHERE gibbonSchoolYearID=:y AND active='Y' LIMIT 1", ['y' => $syid]);
        $feeTuition = $this->one("SELECT gibbonFinanceFeeID, gibbonFinanceFeeCategoryID, fee, name FROM gibbonFinanceFee WHERE gibbonSchoolYearID=:y AND name LIKE '%Tuition%' ORDER BY gibbonFinanceFeeID LIMIT 1", ['y' => $syid])
                   ?: $this->one("SELECT gibbonFinanceFeeID, gibbonFinanceFeeCategoryID, fee, name FROM gibbonFinanceFee WHERE gibbonSchoolYearID=:y ORDER BY gibbonFinanceFeeID LIMIT 1", ['y' => $syid]);
        $feeTransport = $this->one("SELECT gibbonFinanceFeeID, gibbonFinanceFeeCategoryID, fee, name FROM gibbonFinanceFee WHERE gibbonSchoolYearID=:y AND name LIKE '%Transport%' ORDER BY gibbonFinanceFeeID LIMIT 1", ['y' => $syid]);
        $adminActor = (int) $this->val("SELECT gibbonPersonID FROM gibbonPerson WHERE gibbonRoleIDPrimary='001' AND status='Full' ORDER BY gibbonPersonID LIMIT 1");

        // PHASE 0 — wipe previous demo data.
        try { $n = $this->wipe(); $this->say("Reset: cleared previous demo data ($n accounts)."); }
        catch (\Throwable $e) { $this->failPhase('wipe', $e); }

        // PHASE 1 — the 15 accounts.
        [$pwHash, $pwSalt] = $this->hashPassword(self::DEMO_PASSWORD);
        $P = [];
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
            foreach (self::ACCOUNTS as $a) {
                [$key, $username, $first, $sur, $role, $gender] = $a;
                $ins->execute([
                    'surname' => $sur, 'firstName' => $first, 'official' => "$first $sur",
                    'gender' => $gender, 'username' => $username, 'pw' => $pwHash, 'salt' => $pwSalt,
                    'role' => $role, 'email' => $username . '@demo.kreck.local', 'today' => date('Y-m-d'),
                ]);
                $P[$key] = (int) $pdo->lastInsertId();
            }
            $this->say('Accounts: created ' . count($P) . ' demo logins.');
        } catch (\Throwable $e) { $this->failPhase('accounts', $e); }

        // PHASE 1b — staff directory + HOD coordinator.
        try {
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
                $staffIns->execute(['pid' => $P[$k], 'type' => $m[0], 'ini' => 'KD' . str_pad((string) $si, 2, '0', STR_PAD_LEFT), 'job' => $m[1]]);
            }
            if (isset($P['hod']) && $deptID) {
                $pdo->prepare("INSERT INTO gibbonDepartmentStaff SET gibbonDepartmentID=:d, gibbonPersonID=:p, role='Coordinator'")
                    ->execute(['d' => $deptID, 'p' => $P['hod']]);
            }
            $this->say('Staff: directory entries + HOD department coordinator set.');
        } catch (\Throwable $e) { $this->failPhase('staff', $e); }

        // PHASE 2 — demo course + class + enrolments + timetable slots.
        $demoClassID = null;
        try {
            $pdo->prepare("INSERT INTO gibbonCourse SET gibbonSchoolYearID=:y, gibbonDepartmentID=:d, name='KRECK Demo Course', nameShort='KRECKDEMO', description=:desc, map='N', gibbonYearGroupIDList=:yg, orderBy=0")
                ->execute(['y' => $syid, 'd' => $deptID, 'desc' => 'KRECK demonstration course. ' . self::MARK, 'yg' => $yearGroup]);
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
                $pdo->prepare("INSERT INTO gibbonStudentEnrolment SET gibbonPersonID=:p, gibbonSchoolYearID=:y, gibbonYearGroupID=:yg, gibbonFormGroupID=:fg, rollOrder=0")
                    ->execute(['p' => $P[$sk], 'y' => $syid, 'yg' => $yearGroup, 'fg' => $formGroup]);
            }
            $this->say('Course/class: demo class created with 3 teachers-of-record + 3 students enrolled.');

            if ($ttID) {
                $days = $pdo->query("SELECT gibbonTTDayID, gibbonTTColumnID FROM gibbonTTDay WHERE gibbonTTID='$ttID' ORDER BY gibbonTTDayID LIMIT 3")->fetchAll(\PDO::FETCH_ASSOC);
                $slotIns = $pdo->prepare("INSERT INTO gibbonTTDayRowClass SET gibbonTTColumnRowID=:row, gibbonTTDayID=:day, gibbonCourseClassID=:cc, gibbonSpaceID=:space");
                $slots = 0;
                foreach ($days as $d) {
                    $row = $this->val("SELECT gibbonTTColumnRowID FROM gibbonTTColumnRow WHERE gibbonTTColumnID=:col AND type='Lesson' ORDER BY timeStart LIMIT 1", ['col' => $d['gibbonTTColumnID']]);
                    if ($row) { $slotIns->execute(['row' => $row, 'day' => $d['gibbonTTDayID'], 'cc' => $demoClassID, 'space' => $spaceID ?: null]); $slots++; }
                }
                $this->say("Timetable: demo class slotted into $slots lesson periods.");
            }
        } catch (\Throwable $e) { $this->failPhase('class/timetable', $e); }

        // PHASE 3 — parent ↔ student family links.
        try {
            $fl = new KreckFamilyLink($pdo, $this->container);
            $linked = 0;
            foreach ([['parent1', 'student1'], ['parent2', 'student2'], ['parent3', 'student3']] as [$pk, $sk]) {
                $res = $fl->linkExisting($P[$sk], $P[$pk], 'Mother', 1);
                if (!empty($res['ok'])) $linked++;
            }
            $this->say("Families: linked $linked parent↔student families (childDataAccess=Y).");
        } catch (\Throwable $e) { $this->failPhase('family links', $e); }

        // PHASE 4 — simulated attendance across the year.
        try {
            $codePresent = $this->val("SELECT gibbonAttendanceCodeID FROM gibbonAttendanceCode WHERE name='Present' LIMIT 1");
            $codeLate    = $this->val("SELECT gibbonAttendanceCodeID FROM gibbonAttendanceCode WHERE name LIKE 'Present - Late%' LIMIT 1") ?: $codePresent;
            $codeAbsent  = $this->val("SELECT gibbonAttendanceCodeID FROM gibbonAttendanceCode WHERE name='Absent' LIMIT 1") ?: $codePresent;
            $logIns = $pdo->prepare(
                "INSERT INTO gibbonAttendanceLogPerson SET
                   gibbonAttendanceCodeID=:code, gibbonPersonID=:p, direction='In', type=:type, reason='',
                   context='Class', comment=:mark, date=:date, gibbonPersonIDTaker=:taker,
                   gibbonCourseClassID=:cc, timestampTaken=NOW()"
            );
            $start = new \DateTime($firstDay); $end = new \DateTime($lastDay);
            $today = new \DateTime(date('Y-m-d')); if ($end > $today) $end = $today;
            $rows = 0; $i = 0;
            for ($d = clone $start; $d <= $end; $d->modify('+5 days')) {
                if ((int) $d->format('N') >= 6) continue;
                foreach (['student1', 'student2', 'student3'] as $sk) {
                    $i++;
                    $code = $codePresent; $type = 'Present';
                    if ($i % 11 === 0) { $code = $codeLate; $type = 'Present - Late'; }
                    elseif ($i % 17 === 0) { $code = $codeAbsent; $type = 'Absent'; }
                    $logIns->execute([
                        'code' => $code, 'p' => $P[$sk], 'type' => $type, 'mark' => self::MARK,
                        'date' => $d->format('Y-m-d'), 'taker' => $P['teacher1'], 'cc' => $demoClassID,
                    ]);
                    $rows++;
                }
            }
            $this->say("Attendance: $rows records across the year (present / late / absent mix).");
        } catch (\Throwable $e) { $this->failPhase('attendance', $e); }

        // PHASE 5 — finance invoices.
        try {
            $catList = trim(($feeTuition['gibbonFinanceFeeCategoryID'] ?? '') . ',' . ($feeTransport['gibbonFinanceFeeCategoryID'] ?? ''), ',');
            $tuition = (float) ($feeTuition['fee'] ?? 2500); $transport = (float) ($feeTransport['fee'] ?? 600);
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
                ['student1', 'Paid', $total, date('Y-m-d', strtotime($firstDay))],
                ['student2', 'Issued', null, null],
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
                    'notes' => 'Demo invoice: ' . ($feeTuition['name'] ?? 'Tuition') . ' + ' . ($feeTransport['name'] ?? 'Transport') . " (total $total). " . self::MARK,
                    'key' => 'KRECKDEMO-' . $sk . '-' . substr(md5(uniqid()), 0, 8), 'creator' => $P['bursar'] ?? $adminActor,
                ]);
                $made++;
            }
            $this->say("Finance: $made invoices (Paid / Outstanding / Part-paid) — tuition + transport.");
        } catch (\Throwable $e) { $this->failPhase('finance', $e); }

        // PHASE 6 — activity enrolment.
        try {
            if ($activityID) {
                $actIns = $pdo->prepare("INSERT INTO gibbonActivityStudent SET gibbonActivityID=:a, gibbonPersonID=:p, status='Accepted', timestamp=NOW()");
                foreach (['student1', 'student2'] as $sk) $actIns->execute(['a' => $activityID, 'p' => $P[$sk]]);
                $an = $this->val("SELECT name FROM gibbonActivity WHERE gibbonActivityID=:a", ['a' => $activityID]);
                $this->say('Activities: enrolled 2 students into "' . trim((string) $an) . '".');
            } else { $this->say('Activities: no active activity found (skipped).'); }
        } catch (\Throwable $e) { $this->failPhase('activities', $e); }

        // PHASE 7 — KRECK assessment: prepare → marks → submit → approve.
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
                    'examMark' => 50 + ($i * 13) % 45, 'comment' => 'Demo mark. ' . self::MARK,
                ];
            }
            $am->saveDraft($demoClassID, (int) $syid, 'Semester 1', ['ca1' => 20, 'ca2' => 20, 'exam' => 60], $marks, $P['teacher1']);
            $am->submit($demoClassID, (int) $syid, 'Semester 1', $P['teacher1']);
            $am->review($assessmentID, $P['hod'], 'approve', 'Approved for demo. ' . self::MARK);
            $this->say('Grades: KRECK mark sheet prepared, marked, submitted & APPROVED (' . count($roster) . ' students graded).');
        } catch (\Throwable $e) { $this->failPhase('assessment', $e); }

        // PHASE 8 — KRECK planner lessons.
        try {
            if ($this->conn) {
                $pm = new KreckPlannerManager($this->conn);
                $lessons = [
                    ['Introduction & Baseline', '-40 days', '08:00:00', '09:00:00'],
                    ['Core Concepts Workshop', '-26 days', '09:00:00', '10:00:00'],
                    ['Practical & Assessment Prep', '-12 days', '10:00:00', '11:00:00'],
                ];
                $made = 0;
                foreach ($lessons as [$name, $when, $ts, $te]) {
                    $pm->createEntry([
                        'gibbonCourseClassID' => $demoClassID, 'date' => date('Y-m-d', strtotime($lastDay . ' ' . $when)),
                        'timeStart' => $ts, 'timeEnd' => $te, 'name' => $name,
                        'summary' => 'Demo lesson. ' . self::MARK, 'description' => 'KRECK demo planner lesson: ' . $name . '. ' . self::MARK,
                        'viewableParents' => 'Y', 'viewableStudents' => 'Y',
                    ], $P['teacher1']);
                    $made++;
                }
                $this->say("Lessons: $made planner lessons added to the demo class.");
            }
        } catch (\Throwable $e) { $this->failPhase('planner', $e); }

        // PHASE 9 — KRECK payroll.
        try {
            if ($this->conn) {
                $sm = new KreckSalaryManager($this->conn);
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
                        'category' => 'Teaching', 'notes' => 'Demo salary. ' . self::MARK,
                    ], $P['bursar'] ?? $adminActor);
                    $sm->decideSalary($sid, 'Approved', $P['principal'] ?? $adminActor, 'Approved for demo. ' . self::MARK);
                    $payIns->execute([
                        'sid' => $sid, 'pid' => $P[$k], 'period' => date('Y-m', strtotime($lastDay . ' -20 days')),
                        'due' => date('Y-m-25', strtotime($lastDay . ' -20 days')), 'amount' => $amount,
                        'paid' => date('Y-m-24', strtotime($lastDay . ' -20 days')),
                        'notes' => 'Demo payroll run. ' . self::MARK, 'creator' => $P['bursar'] ?? $adminActor,
                    ]);
                    $made++;
                }
                $this->say("Payroll: $made salaries approved + a paid payment each.");
            }
        } catch (\Throwable $e) { $this->failPhase('payroll', $e); }

        // PHASE 10 — auto-sync (Export) to the LMS, so one action stands up the
        // SMS demo AND pushes it to Moodle (demo users/course + parent mirror,
        // reconciled by username). Gated on the LMS being configured; a failure
        // never breaks the seed.
        try {
            $settings = [];
            foreach ($pdo->query("SELECT name, value FROM gibbonSetting WHERE scope='Kreck Bridge'") as $r) {
                $settings[$r['name']] = $r['value'];
            }
            $endpoint = trim((string) ($settings['moodleEndpoint'] ?? ''));
            $token = trim((string) ($settings['moodleToken'] ?? ''));
            if ($endpoint === '' || $token === '' || stripos($endpoint, 'https://') !== 0) {
                $this->say('Export → LMS: skipped — set an HTTPS LMS endpoint + web-service token in Kreck Settings, then use Export (or re-seed) to sync.');
            } else {
                $manifest = (new KreckManifestBuilder($pdo, $settings))->build(['gibbonSchoolYearID' => (int) $syid]);
                $res = KreckExporter::sync($manifest, $settings, false, 'merge');
                if (!empty($res['error'])) {
                    $this->say('Export → LMS: sync error — ' . $res['error']);
                } else {
                    $c = $res['counts'] ?? ($res['created'] ?? []);
                    $summary = is_array($c) ? implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($c), array_values($c))) : '';
                    $this->say('Export → LMS: synced' . ($summary !== '' ? " ($summary)" : ' (manifest accepted)') . '.');
                    // Timetable periods, lessons, closures and term dates → the LMS calendar (rolling window).
                    require_once __DIR__ . '/EventSync.php';
                    $ev = (new KreckEventSync($pdo, $settings))->run(['trigger' => 'seed', 'maxBatches' => 10]);
                    $this->say($ev['ok']
                        ? sprintf('Events → LMS calendar: %d new, %d updated, %d removed (%s).', $ev['created'], $ev['updated'], $ev['deleted'], $ev['window'] ?: 'no events in the window')
                        : 'Events → LMS calendar: ' . ($ev['error'] ?: 'did not complete') . ' — the scheduled sync will retry.');
                }
            }
        } catch (\Throwable $e) { $this->failPhase('export sync', $e); }

        return ['ok' => true, 'log' => $this->log, 'accounts' => self::ACCOUNTS, 'password' => self::DEMO_PASSWORD];
    }
}
