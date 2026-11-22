<?php
/**
 * KRECK Bridge — custom-flow test harness (bootstrap + mini framework).
 *
 * These tests exercise the KRECK *custom entries, flows and forms* end-to-end
 * against the live Gibbon database — the assessment review sheet, teacher
 * salaries/approvals, the lesson planner, and the student↔parent family link —
 * so that every transaction we designed is proven to actually commit (and to
 * roll back when it must). They are regression tests for real bugs that reached
 * the user, e.g. the PDO::rowCount() crash when saving an assessment deadline.
 *
 * Run:  php "modules/Kreck Bridge/tests/run.php"
 *
 * Each test creates its own throwaway data, asserts the transaction landed,
 * and cleans up after itself (LIFO), so running the suite leaves the database
 * exactly as it found it. A test that can't find prerequisite data SKIPs
 * rather than failing.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

if (defined('KRECK_TEST_BOOTSTRAPPED')) {
    return;
}
define('KRECK_TEST_BOOTSTRAPPED', true);

// --- Bootstrap Gibbon (gives $container, $pdo, $connection2, functions) ------
require __DIR__ . '/../../../gibbon.php';

if (!function_exists('isCommandLineInterface') || !isCommandLineInterface()) {
    fwrite(STDERR, "The KRECK test harness runs on the command line only.\n");
    exit(2);
}

use Gibbon\Contracts\Database\Connection;

// Make the connections reachable to the test files without relying on
// gibbon.php's variable scope leaking into included files.
$GLOBALS['kt_connection2'] = $connection2;          // raw PDO
$GLOBALS['kt_container']   = $container;             // DI container
$GLOBALS['kt_connection']  = $container->get(Connection::class); // Gibbon Connection

// --- Tiny test framework -----------------------------------------------------
$GLOBALS['KT'] = [
    'tests'   => [],   // [ [name, fn], ... ]
    'cleanup' => [],   // cleanups for the currently-running test (LIFO)
    'pass'    => 0,
    'fail'    => 0,
    'skip'    => 0,
    'failed'  => [],
];

/** PDO for the AssessmentManager / FamilyLink (raw \PDO). */
function kt_pdo(): \PDO { return $GLOBALS['kt_connection2']; }
/** Gibbon Connection for SalaryManager / PlannerManager. */
function kt_conn(): Connection { return $GLOBALS['kt_connection']; }
function kt_container() { return $GLOBALS['kt_container']; }

function kt_test(string $name, callable $fn): void {
    $GLOBALS['KT']['tests'][] = [$name, $fn];
}

/** Register a cleanup for the current test (run LIFO, errors swallowed). */
function kt_cleanup(callable $fn): void {
    $GLOBALS['KT']['cleanup'][] = $fn;
}

/** Thrown to mark a test skipped (missing prerequisites). */
class KtSkip extends \Exception {}
function kt_skip(string $why): void { throw new KtSkip($why); }

/** Thrown on a failed assertion. */
class KtFail extends \Exception {}

function kt_assert($cond, string $msg): void {
    if (!$cond) { throw new KtFail('assertion failed: ' . $msg); }
}
function kt_eq($expected, $actual, string $msg): void {
    if ($expected != $actual) {
        throw new KtFail($msg . ' — expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}
/** Assert the callable throws (any Throwable). */
function kt_throws(callable $fn, string $msg): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        return;
    }
    throw new KtFail('expected an exception but none was thrown: ' . $msg);
}

// --- Shared live context (queried once, cached) ------------------------------
function kt_ctx(): array {
    static $ctx = null;
    if ($ctx !== null) { return $ctx; }
    $pdo = kt_pdo();

    $fetch = function (string $sql) use ($pdo) {
        $s = $pdo->query($sql);
        return $s ? $s->fetch(\PDO::FETCH_ASSOC) : null;
    };

    $year = $fetch("SELECT gibbonSchoolYearID FROM gibbonSchoolYear WHERE status='Current' LIMIT 1");
    $ctx = ['yearID' => $year ? (int) $year['gibbonSchoolYearID'] : 0];

    // A class in the current year that has >=1 student AND a teacher — the full
    // assessment flow needs both. Prefer one with no existing KRECK sheet so the
    // test starts clean.
    $class = $fetch(
        "SELECT cc.gibbonCourseClassID
         FROM gibbonCourseClass cc
         JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
         WHERE c.gibbonSchoolYearID = {$ctx['yearID']}
           AND EXISTS (SELECT 1 FROM gibbonCourseClassPerson s
                       WHERE s.gibbonCourseClassID = cc.gibbonCourseClassID AND s.role='Student')
           AND EXISTS (SELECT 1 FROM gibbonCourseClassPerson t
                       WHERE t.gibbonCourseClassID = cc.gibbonCourseClassID AND t.role LIKE 'Teacher%')
           AND NOT EXISTS (SELECT 1 FROM kreckAssessment k
                       WHERE k.gibbonCourseClassID = cc.gibbonCourseClassID)
         ORDER BY cc.gibbonCourseClassID LIMIT 1"
    );
    $ctx['classID'] = $class ? (int) $class['gibbonCourseClassID'] : 0;

    if ($ctx['classID']) {
        $teacher = $fetch(
            "SELECT gibbonPersonID FROM gibbonCourseClassPerson
             WHERE gibbonCourseClassID = {$ctx['classID']} AND role LIKE 'Teacher%' LIMIT 1"
        );
        $ctx['teacherID'] = $teacher ? (int) $teacher['gibbonPersonID'] : 0;
    } else {
        $ctx['teacherID'] = 0;
    }

    // An administrator to act as the reviewer / approver.
    $admin = $fetch(
        "SELECT gibbonPersonID FROM gibbonPerson
         WHERE gibbonRoleIDPrimary='001' AND status='Full' LIMIT 1"
    );
    $ctx['adminID'] = $admin ? (int) $admin['gibbonPersonID'] : 0;

    // A staff member to attach a salary to.
    $staff = $fetch(
        "SELECT gibbonPersonID FROM gibbonPerson
         WHERE gibbonRoleIDPrimary IN ('001','002') AND status='Full' LIMIT 1"
    );
    $ctx['staffID'] = $staff ? (int) $staff['gibbonPersonID'] : 0;

    // A student with no parent/guardian linked (for the family tests). May be 0.
    $student = $fetch(
        "SELECT p.gibbonPersonID
         FROM gibbonPerson p
         JOIN gibbonStudentEnrolment se ON se.gibbonPersonID = p.gibbonPersonID
            AND se.gibbonSchoolYearID = {$ctx['yearID']}
         WHERE p.status='Full' AND p.gibbonRoleIDPrimary='003'
           AND NOT EXISTS (
             SELECT 1 FROM gibbonFamilyChild fc
             JOIN gibbonFamilyAdult fa ON fa.gibbonFamilyID = fc.gibbonFamilyID
             WHERE fc.gibbonPersonID = p.gibbonPersonID)
         ORDER BY p.gibbonPersonID LIMIT 1"
    );
    $ctx['studentNoParentID'] = $student ? (int) $student['gibbonPersonID'] : 0;

    // Any existing person we can reuse as an "existing parent" for linkExisting
    // (an adult who is not the student). Prefer an actual Parent-role person.
    $adult = $fetch(
        "SELECT gibbonPersonID FROM gibbonPerson
         WHERE status='Full' AND gibbonRoleIDPrimary='004' LIMIT 1"
    );
    $ctx['existingAdultID'] = $adult ? (int) $adult['gibbonPersonID'] : $ctx['adminID'];

    return $ctx;
}

/**
 * Snapshot MAX(pk) for a set of tables now, and register a cleanup that deletes
 * every row created after this point (pk > snapshot). Safe: it never touches a
 * row that already existed. Used by the family tests, which create real
 * gibbonPerson / gibbonFamily* rows.
 *
 * @param array<string,string> $tables  table => primary-key column
 */
function kt_cleanup_new_rows(array $tables): void {
    $pdo = kt_pdo();
    $marks = [];
    foreach ($tables as $table => $pk) {
        $marks[$table] = [$pk, (int) $pdo->query("SELECT COALESCE(MAX($pk),0) FROM $table")->fetchColumn()];
    }
    kt_cleanup(function () use ($pdo, $marks) {
        // Delete children before parents: reverse the declared order.
        foreach (array_reverse($marks, true) as $table => [$pk, $max]) {
            $pdo->prepare("DELETE FROM $table WHERE $pk > :max")->execute(['max' => $max]);
        }
    });
}
