<?php
/**
 * Assessment review flow — the KRECK custom "mark sheet" transactions.
 *
 * Covers the exact bugs the user hit:
 *   • setDeadline() crashed with "Call to undefined method PDO::rowCount()".
 *   • the full prepare → set deadline → draft marks → submit → approve chain,
 *     proving every transaction commits and the final marks are computed.
 */

require_once __DIR__ . '/../src/AssessmentManager.php';

/** Delete a KRECK assessment sheet and all its child rows (cleanup, FK order). */
function kt_delete_assessment(int $assessmentID): void {
    if ($assessmentID <= 0) { return; }
    $pdo = kt_pdo();
    foreach (['kreckAssessmentMark', 'kreckAssessmentAudit', 'kreckAssessmentNotification'] as $child) {
        $pdo->prepare("DELETE FROM $child WHERE kreckAssessmentID = :id")->execute(['id' => $assessmentID]);
    }
    $pdo->prepare("DELETE FROM kreckAssessment WHERE kreckAssessmentID = :id")->execute(['id' => $assessmentID]);
}

/* ---------------------------------------------------------------------------
 * REGRESSION: saving a deadline must not crash and must persist the date.
 * (Was: Error: Call to undefined method PDO::rowCount() at AssessmentManager:616)
 * ------------------------------------------------------------------------- */
kt_test('assessment.setDeadline persists the date (regression: PDO::rowCount)', function () {
    $ctx = kt_ctx();
    if (!$ctx['classID'] || !$ctx['adminID']) { kt_skip('no clean class/admin available'); }

    $am = new KreckAssessmentManager(kt_pdo());
    $assessmentID = $am->prepareForReviewer($ctx['classID'], $ctx['yearID'], 'Semester 1', $ctx['adminID']);
    kt_cleanup(fn () => kt_delete_assessment($assessmentID));
    kt_assert($assessmentID > 0, 'prepareForReviewer returns a new sheet id');

    // The call that used to throw:
    $am->setDeadline($assessmentID, $ctx['adminID'], '2026-12-15');
    $sheet = $am->sheet($assessmentID);
    kt_eq('2026-12-15', $sheet['dueDate'], 'deadline saved on first set');

    // Change it again — exercises the rowCount()===1 guard a second time.
    $am->setDeadline($assessmentID, $ctx['adminID'], '2027-01-10');
    $sheet = $am->sheet($assessmentID);
    kt_eq('2027-01-10', $sheet['dueDate'], 'deadline updated on change');
});

/* ---------------------------------------------------------------------------
 * FULL FLOW: prepare → draft every student's marks → submit → approve.
 * Proves the whole custom assessment pipeline commits and computes finalMark.
 * ------------------------------------------------------------------------- */
kt_test('assessment.full flow prepare→draft→submit→approve', function () {
    $ctx = kt_ctx();
    if (!$ctx['classID'] || !$ctx['adminID'] || !$ctx['teacherID']) {
        kt_skip('no clean class with a teacher available');
    }

    $pdo = kt_pdo();
    $am  = new KreckAssessmentManager($pdo);

    $assessmentID = $am->prepareForReviewer($ctx['classID'], $ctx['yearID'], 'Semester 1', $ctx['adminID']);
    kt_cleanup(fn () => kt_delete_assessment($assessmentID));

    // Build a complete mark set for every active student in the class.
    $roster = $am->studentsForClass($ctx['classID']);
    kt_assert(count($roster) >= 1, 'class has students');
    $posted = [];
    foreach ($roster as $r) {
        $posted[(int) $r['gibbonPersonID']] = [
            'ca1Mark' => 70, 'ca2Mark' => 80, 'examMark' => 60, 'comment' => 'kreck-test',
        ];
    }
    $weights = ['ca1' => 20, 'ca2' => 20, 'exam' => 60];

    // Draft (teacher acts). Then submit, then approve (reviewer acts).
    $am->saveDraft($ctx['classID'], $ctx['yearID'], 'Semester 1', $weights, $posted, $ctx['teacherID']);
    $am->submit($ctx['classID'], $ctx['yearID'], 'Semester 1', $ctx['teacherID']);
    kt_eq('Submitted', $am->sheet($assessmentID)['status'], 'sheet is Submitted after submit()');

    $am->review($assessmentID, $ctx['adminID'], 'approve', 'Looks good (kreck-test)');
    kt_eq('Approved', $am->sheet($assessmentID)['status'], 'sheet is Approved after review()');

    // finalMark computed for every student: 70*.2 + 80*.2 + 60*.6 = 66
    $final = $pdo->prepare(
        "SELECT finalMark FROM kreckAssessmentMark WHERE kreckAssessmentID = :id"
    );
    $final->execute(['id' => $assessmentID]);
    $marks = $final->fetchAll(\PDO::FETCH_COLUMN);
    kt_eq(count($roster), count($marks), 'a final mark exists for every student');
    foreach ($marks as $m) {
        kt_assert($m !== null && abs((float) $m - 66.0) < 0.01, 'final mark computed (66.0), got ' . var_export($m, true));
    }
});

/* ---------------------------------------------------------------------------
 * GUARDS: approving a non-submitted sheet, and an invalid decision, are rejected.
 * ------------------------------------------------------------------------- */
kt_test('assessment.review rejects bad input (cannot approve a draft; bad decision)', function () {
    $ctx = kt_ctx();
    if (!$ctx['classID'] || !$ctx['adminID']) { kt_skip('no clean class/admin available'); }

    $am = new KreckAssessmentManager(kt_pdo());
    $assessmentID = $am->prepareForReviewer($ctx['classID'], $ctx['yearID'], 'Semester 1', $ctx['adminID']);
    kt_cleanup(fn () => kt_delete_assessment($assessmentID));

    // Fresh sheet is Draft — approving it must throw.
    kt_throws(fn () => $am->review($assessmentID, $ctx['adminID'], 'approve', ''),
        'approving a Draft sheet');
    // Invalid decision verb must throw.
    kt_throws(fn () => $am->review($assessmentID, $ctx['adminID'], 'banana', 'x'),
        'invalid decision');
    // Invalid period at prepare time must throw.
    kt_throws(fn () => $am->prepareForReviewer($ctx['classID'], $ctx['yearID'], 'Term 9', $ctx['adminID']),
        'invalid period');
});
