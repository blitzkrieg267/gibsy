<?php
/**
 * Lesson planner (timetable lesson / activity) custom entry.
 * Gibbon has no native course/lesson authoring UI we use here — KRECK adds the
 * lesson via gibbonPlannerEntry. Proves createEntry() inserts a lesson against
 * a real class and tolerates schemas with/without the gibbonTTDayRowClassID slot.
 */

require_once __DIR__ . '/../src/PlannerManager.php';

kt_test('planner.createEntry adds a timetable lesson', function () {
    $ctx = kt_ctx();
    if (!$ctx['classID'] || !$ctx['adminID']) { kt_skip('no class/admin available'); }

    $mgr = new KreckPlannerManager(kt_conn());
    $id = $mgr->createEntry([
        'gibbonCourseClassID' => $ctx['classID'],
        'date'      => date('Y-m-d'),
        'timeStart' => '09:00:00',
        'timeEnd'   => '10:00:00',
        'name'      => 'KRECK test lesson',
        'summary'   => 'harness-created lesson',
        'description' => 'Full description for the KRECK planner regression test.',
        'viewableParents'  => 'Y',
        'viewableStudents' => 'Y',
    ], $ctx['adminID']);
    kt_cleanup(function () use ($id) {
        kt_pdo()->prepare("DELETE FROM gibbonPlannerEntry WHERE gibbonPlannerEntryID = :id")
            ->execute(['id' => (int) $id]);
    });

    kt_assert($id > 0, 'createEntry returns a new planner-entry id');
    $row = kt_pdo()->query(
        "SELECT name, gibbonCourseClassID, viewableStudents FROM gibbonPlannerEntry WHERE gibbonPlannerEntryID=" . (int) $id
    )->fetch(\PDO::FETCH_ASSOC);
    kt_eq('KRECK test lesson', $row['name'], 'lesson name stored (truncated to 50)');
    kt_eq($ctx['classID'], (int) $row['gibbonCourseClassID'], 'lesson attached to the class');
    kt_eq('Y', $row['viewableStudents'], 'student visibility set');
});
