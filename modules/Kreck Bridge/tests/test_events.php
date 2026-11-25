<?php
/**
 * SMS → LMS event mirror (src/EventSync.php).
 *
 * Exercises the collector (what each kind of SMS event becomes, who may see it), the ledger diff
 * (a repeat run sends nothing; an edit sends one update; a removal sends a delete; an outage is queued
 * for retry) and the audience/replacement rules — with a fake transport, so no LMS is needed.
 * The mapping onto Moodle's event model is covered by the LMS-side tests of local_kreck_sync_events.
 */

require_once __DIR__ . '/../src/EventSync.php';
require_once __DIR__ . '/../src/PlannerManager.php';

const KT_EVT_DATE = '2026-02-02';   // a Monday inside the dev school year

/** A transport that records what was sent and answers like the LMS would. */
function kt_evt_transport(array &$log, string &$mode) {
    return function ($settings, $fn, $params, $timeout) use (&$log, &$mode) {
        if ($mode === 'down') { return ['error' => 'LMS unreachable (test)']; }
        $events = json_decode($params['eventsjson'], true);
        $results = [];
        foreach ($events as $e) {
            $log[] = $e;
            $results[] = ['source_id' => $e['source_id'], 'result' => $e['op'] === 'delete' ? 'deleted' : 'created', 'message' => ''];
        }
        return ['status' => 'applied', 'results' => $results];
    };
}

function kt_evt_sync(): KreckEventSync {
    return new KreckEventSync(kt_pdo(), []);
}

/** Skip unless the window is inside the current school year. */
function kt_evt_need_year(KreckEventSync $sync): void {
    $c = $sync->collect(['from' => KT_EVT_DATE, 'to' => KT_EVT_DATE, 'kinds' => ['term']]);
    if (($c['from'] ?? null) !== KT_EVT_DATE) { kt_skip('test date is outside the current school year'); }
}

function kt_evt_lesson(int $classID, int $adminID, string $name, string $students = 'Y', string $parents = 'Y'): int {
    $mgr = new KreckPlannerManager(kt_conn());
    $id = (int) $mgr->createEntry([
        'gibbonCourseClassID' => $classID, 'date' => KT_EVT_DATE, 'timeStart' => '10:00:00', 'timeEnd' => '11:00:00',
        'name' => $name, 'summary' => 'kt', 'description' => 'kt', 'viewableStudents' => $students, 'viewableParents' => $parents,
    ], $adminID);
    kt_cleanup(function () use ($id) {
        kt_pdo()->prepare("DELETE FROM gibbonPlannerEntry WHERE gibbonPlannerEntryID = :id")->execute(['id' => $id]);
        kt_pdo()->prepare("DELETE FROM kreckEventLedger WHERE sourceID LIKE :p")->execute(['p' => 'gibbonPlannerEntry:' . $id . '%']);
    });
    return $id;
}

kt_test('events.lesson maps to a class course event (+ parents), teachers-only when hidden from students', function () {
    $ctx = kt_ctx();
    if (!$ctx['classID'] || !$ctx['adminID']) { kt_skip('no class/admin available'); }
    $sync = kt_evt_sync(); kt_evt_need_year($sync);
    $short = (new KreckManifestBuilder(kt_pdo(), []))->classCatalog()[$ctx['classID']]['short'] ?? null;
    kt_assert($short !== null, 'the class has an LMS shortname');

    $id = kt_evt_lesson($ctx['classID'], $ctx['adminID'], '[KT] Visible lesson', 'Y', 'Y');
    $c = $sync->collect(['from' => KT_EVT_DATE, 'to' => KT_EVT_DATE, 'kinds' => ['lesson']]);
    $e = $c['events']['gibbonPlannerEntry:' . $id] ?? null;
    kt_assert($e !== null, 'lesson collected');
    kt_eq('course', $e['scope'], 'students may see it → course event');
    kt_eq($short, $e['course'], 'on the class\'s LMS course');
    kt_eq('2026-02-02T10:00:00', $e['start'], 'start is the lesson time');
    kt_eq('Lesson', $e['label'], 'labelled');

    // Hidden from students: only the class teachers, never the course.
    kt_pdo()->exec("UPDATE gibbonPlannerEntry SET viewableStudents='N' WHERE gibbonPlannerEntryID=" . $id);
    $c = $sync->collect(['from' => KT_EVT_DATE, 'to' => KT_EVT_DATE, 'kinds' => ['lesson']]);
    $e = $c['events']['gibbonPlannerEntry:' . $id];
    kt_eq('user', $e['scope'], 'hidden from students → user scope');
    kt_assert(!empty($e['users']) && !isset($e['course']), 'listed teachers only, no course');
    $teachers = kt_pdo()->query("SELECT COUNT(*) FROM gibbonCourseClassPerson WHERE gibbonCourseClassID={$ctx['classID']} AND role IN ('Teacher','Assistant','Technician')")->fetchColumn();
    kt_assert(count($e['users']) >= 1 && count($e['users']) <= (int) $teachers + 1, 'at most the class staff (+ the creator)');

    // Parents hidden → no parents copy.
    kt_pdo()->exec("UPDATE gibbonPlannerEntry SET viewableParents='N' WHERE gibbonPlannerEntryID=" . $id);
    $c = $sync->collect(['from' => KT_EVT_DATE, 'to' => KT_EVT_DATE, 'kinds' => ['lesson']]);
    kt_assert(!isset($c['events']['gibbonPlannerEntry:' . $id . ':parents']), 'no parent copy when parents may not see it');
});

kt_test('events.ledger: repeat run sends nothing, edit sends one update, removal sends a delete, outage is retried', function () {
    $ctx = kt_ctx();
    if (!$ctx['classID'] || !$ctx['adminID']) { kt_skip('no class/admin available'); }
    $sync = kt_evt_sync(); kt_evt_need_year($sync);
    KreckEventSync::ensureTables(kt_pdo());
    $log = []; $mode = 'ok';
    $sync->setTransport(kt_evt_transport($log, $mode));
    kt_cleanup(function () { kt_pdo()->exec("DELETE FROM kreckEventRun WHERE `trigger`='test'"); });
    $win = ['from' => KT_EVT_DATE, 'to' => KT_EVT_DATE, 'kinds' => ['lesson'], 'trigger' => 'test'];

    $id = kt_evt_lesson($ctx['classID'], $ctx['adminID'], '[KT] Ledger lesson');
    $r = $sync->run($win);
    kt_assert($r['ok'] && $r['sent'] >= 1 && $r['created'] >= 1, 'first run sends the lesson');
    $n = count($log);
    $r = $sync->run($win);
    kt_eq(0, $r['sent'], 'second run sends nothing');
    kt_eq($n, count($log), 'nothing reached the transport');

    kt_pdo()->exec("UPDATE gibbonPlannerEntry SET name='[KT] Ledger lesson v2' WHERE gibbonPlannerEntryID=" . $id);
    $log = [];
    $r = $sync->run($win);
    $names = array_column($log, 'name');
    kt_assert($r['sent'] >= 1 && count(array_filter($names, fn($x) => strpos($x, 'v2') !== false)) >= 1, 'the edit is sent');

    kt_pdo()->exec("DELETE FROM gibbonPlannerEntry WHERE gibbonPlannerEntryID=" . $id);
    $log = [];
    $r = $sync->run($win);
    kt_assert($r['deleted'] >= 1 && in_array('delete', array_column($log, 'op'), true), 'the removal is sent as a delete');
    kt_eq(0, (int) kt_pdo()->query("SELECT COUNT(*) FROM kreckEventLedger WHERE sourceID LIKE 'gibbonPlannerEntry:{$id}%'")->fetchColumn(), 'ledger row removed');

    // Outage → Failed in the ledger → retried next run.
    $id2 = kt_evt_lesson($ctx['classID'], $ctx['adminID'], '[KT] Outage lesson');
    $mode = 'down';
    $r = $sync->run($win);
    kt_assert(!$r['ok'] && $r['failed'] >= 1, 'outage reported');
    kt_assert((int) kt_pdo()->query("SELECT COUNT(*) FROM kreckEventLedger WHERE status='Failed' AND sourceID LIKE 'gibbonPlannerEntry:{$id2}%'")->fetchColumn() >= 1, 'marked Failed');
    $mode = 'ok';
    $r = $sync->run($win);
    kt_assert($r['ok'] && $r['created'] >= 1, 'recovered on the next run');
});

kt_test('events.a lesson replaces the timetable period it overlaps; a closure cancels classes', function () {
    $ctx = kt_ctx();
    if (!$ctx['adminID']) { kt_skip('no admin'); }
    $sync = kt_evt_sync(); kt_evt_need_year($sync);
    // any class with a timetabled period on the test date
    $row = kt_pdo()->query("SELECT rc.gibbonCourseClassID ccid, per.timeStart, per.timeEnd FROM gibbonTTDayRowClass rc
        JOIN gibbonTTDay d ON d.gibbonTTDayID=rc.gibbonTTDayID JOIN gibbonTTDayDate dd ON dd.gibbonTTDayID=d.gibbonTTDayID AND dd.date='" . KT_EVT_DATE . "'
        JOIN gibbonTTColumnRow per ON per.gibbonTTColumnRowID=rc.gibbonTTColumnRowID LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if (!$row) { kt_skip('no timetabled class on the test date'); }
    $ccid = (int) $row['ccid'];
    $win = ['from' => KT_EVT_DATE, 'to' => KT_EVT_DATE];
    $periodsOf = function (array $c) use ($ccid) {
        return array_filter($c['events'], fn($e) => $e['kind'] === 'class' && strpos($e['url'], 'gibbonCourseClassID=' . $ccid . '&') !== false);
    };
    $before = count($periodsOf($sync->collect($win + ['kinds' => ['class']])));
    kt_assert($before >= 1, 'the period is collected');

    $mgr = new KreckPlannerManager(kt_conn());
    $id = (int) $mgr->createEntry(['gibbonCourseClassID' => $ccid, 'date' => KT_EVT_DATE, 'timeStart' => $row['timeStart'], 'timeEnd' => $row['timeEnd'],
        'name' => '[KT] Replaces period', 'viewableStudents' => 'Y', 'viewableParents' => 'N'], $ctx['adminID']);
    kt_cleanup(fn() => kt_pdo()->exec("DELETE FROM gibbonPlannerEntry WHERE gibbonPlannerEntryID=" . $id));
    $after = count($periodsOf($sync->collect($win + ['kinds' => ['lesson', 'class']])));
    kt_assert($after < $before, 'the overlapping lesson replaces that period');

    // Whole-school closure: one site event, and no class events at all that day.
    $term = kt_pdo()->query("SELECT gibbonSchoolYearTermID FROM gibbonSchoolYearTerm WHERE '" . KT_EVT_DATE . "' BETWEEN firstDay AND lastDay LIMIT 1")->fetchColumn();
    if (!$term) { kt_skip('no term covers the test date'); }
    kt_pdo()->exec("INSERT INTO gibbonSchoolYearSpecialDay (gibbonSchoolYearTermID, type, name, description, date, context, cancelActivities, cancelDuty, cancelBookings, cancelClasses)
        VALUES ($term, 'School Closure', 'KT closure', 'kt', '" . KT_EVT_DATE . "', NULL, 'Y','Y','Y','Y')");
    $sdid = (int) kt_pdo()->lastInsertId();
    kt_cleanup(fn() => kt_pdo()->exec("DELETE FROM gibbonSchoolYearSpecialDay WHERE gibbonSchoolYearSpecialDayID=" . $sdid));
    $c = $sync->collect($win + ['kinds' => ['special', 'class', 'lesson']]);
    $e = $c['events']['gibbonSchoolYearSpecialDay:' . $sdid] ?? null;
    kt_assert($e && $e['scope'] === 'site' && !empty($e['all_day']), 'closure is an all-day site event');
    kt_eq('School closure — KT closure', $e['name'], 'labelled by type');
    kt_eq(0, count(array_filter($c['events'], fn($x) => $x['kind'] === 'class' || $x['kind'] === 'lesson')), 'no class or lesson events on a closure day');
});

kt_test('events.a year-group closure fans out to that year group\'s class courses only', function () {
    $ctx = kt_ctx();
    $sync = kt_evt_sync(); kt_evt_need_year($sync);
    $cat = (new KreckManifestBuilder(kt_pdo(), []))->classCatalog();
    $with = null; foreach ($cat as $ccid => $c) { if (!empty($c['yearGroups'])) { $with = [$ccid, $c]; break; } }
    if (!$with) { kt_skip('no class with a year group'); }
    $term = kt_pdo()->query("SELECT gibbonSchoolYearTermID FROM gibbonSchoolYearTerm WHERE '" . KT_EVT_DATE . "' BETWEEN firstDay AND lastDay LIMIT 1")->fetchColumn();
    if (!$term) { kt_skip('no term covers the test date'); }
    $yg = $with[1]['yearGroups'][0];
    kt_pdo()->exec("INSERT INTO gibbonSchoolYearSpecialDay (gibbonSchoolYearTermID, type, name, description, date, context, gibbonYearGroupIDList, cancelActivities, cancelDuty, cancelBookings, cancelClasses)
        VALUES ($term, 'Off Timetable', 'KT trip', 'kt', '" . KT_EVT_DATE . "', 'Year Group', '$yg', 'N','N','N','N')");
    $sdid = (int) kt_pdo()->lastInsertId();
    kt_cleanup(fn() => kt_pdo()->exec("DELETE FROM gibbonSchoolYearSpecialDay WHERE gibbonSchoolYearSpecialDayID=" . $sdid));
    $c = $sync->collect(['from' => KT_EVT_DATE, 'to' => KT_EVT_DATE, 'kinds' => ['special']]);
    $e = $c['events']['gibbonSchoolYearSpecialDay:' . $sdid] ?? null;
    kt_assert($e && $e['scope'] === 'course', 'year-group scope → course events');
    kt_assert(in_array($with[1]['short'], $e['courses'], true), 'includes a class of that year group');
    foreach ($e['courses'] as $short) {
        $cls = array_filter($cat, fn($x) => $x['short'] === $short); $cls = reset($cls);
        kt_assert(in_array($yg, $cls['yearGroups'], true), "$short belongs to year group $yg");
    }
});

kt_test('events.calendar events: public → site, restricted → named audience, cancelled → removed', function () {
    $sync = kt_evt_sync(); kt_evt_need_year($sync);
    $syid = kt_ctx()['yearID'];
    try {
        kt_pdo()->exec("INSERT INTO gibbonCalendar (gibbonSchoolYearID, name, description, summary, color, public, viewableStaff, viewableStudents, viewableParents, viewableOther, viewableParticipants, editableStaff, sequenceNumber)
            VALUES ($syid, 'KT calendar', '', '', '#000000', 'Y', 'Y', 'Y', 'Y', 'N', 'Y', 'Y', 99)");
    } catch (\Throwable $e) { kt_skip('cannot create a calendar here: ' . $e->getMessage()); }
    $calID = (int) kt_pdo()->lastInsertId();
    kt_cleanup(function () use ($calID) {
        kt_pdo()->exec("DELETE FROM gibbonCalendarEvent WHERE gibbonCalendarID=$calID");
        kt_pdo()->exec("DELETE FROM gibbonCalendar WHERE gibbonCalendarID=$calID");
    });
    try {
        kt_pdo()->exec("INSERT INTO gibbonCalendarEvent (gibbonCalendarID, name, description, status, allDay, dateStart, dateEnd, timeStart, timeEnd, locationType)
            VALUES ($calID, 'KT assembly', 'kt', 'Confirmed', 'N', '" . KT_EVT_DATE . "', '" . KT_EVT_DATE . "', '09:00:00', '10:00:00', 'Internal')");
    } catch (\Throwable $e) { kt_skip('cannot create a calendar event here: ' . $e->getMessage()); }
    $evID = (int) kt_pdo()->lastInsertId();
    $win = ['from' => KT_EVT_DATE, 'to' => KT_EVT_DATE, 'kinds' => ['calendar']];
    $e = $sync->collect($win)['events']['gibbonCalendarEvent:' . $evID] ?? null;
    kt_assert($e && $e['scope'] === 'site', 'public calendar → site event');
    kt_eq('KT calendar', $e['label'], 'labelled with the calendar name');

    kt_pdo()->exec("UPDATE gibbonCalendar SET public='N', viewableStudents='N', viewableParents='N', viewableParticipants='N' WHERE gibbonCalendarID=$calID");
    $e = $sync->collect($win)['events']['gibbonCalendarEvent:' . $evID];
    kt_assert($e['scope'] === 'user' && count($e['users']) >= 1, 'staff-only calendar → user scope listing staff');

    kt_pdo()->exec("UPDATE gibbonCalendarEvent SET status='Cancelled' WHERE gibbonCalendarEventID=$evID");
    $e = $sync->collect($win)['events']['gibbonCalendarEvent:' . $evID];
    kt_eq('cancelled', $e['status'], 'cancelled in the SMS → cancelled for the LMS (which removes it)');
});

kt_test('events.staff duty recurs weekly on its weekday only', function () {
    $sync = kt_evt_sync(); kt_evt_need_year($sync);
    $ctx = kt_ctx();
    if (!$ctx['teacherID']) { kt_skip('no staff member'); }
    $monday = (int) kt_pdo()->query("SELECT gibbonDaysOfWeekID FROM gibbonDaysOfWeek WHERE name='Monday'")->fetchColumn();
    if (!$monday) { kt_skip('no Monday row'); }
    try {
        kt_pdo()->exec("INSERT INTO gibbonStaffDuty (gibbonDaysOfWeekIDList, name, nameShort, type, timeStart, timeEnd, sequenceNumber) VALUES ('$monday', 'KT duty', 'KTD', 'Duty', '07:45:00', '08:15:00', 99)");
    } catch (\Throwable $e) { kt_skip('cannot create a duty here: ' . $e->getMessage()); }
    $dutyID = (int) kt_pdo()->lastInsertId();
    kt_pdo()->exec("INSERT INTO gibbonStaffDutyPerson (gibbonStaffDutyID, gibbonDaysOfWeekID, gibbonPersonID) VALUES ($dutyID, $monday, {$ctx['teacherID']})");
    kt_cleanup(function () use ($dutyID) {
        kt_pdo()->exec("DELETE FROM gibbonStaffDutyPerson WHERE gibbonStaffDutyID=$dutyID");
        kt_pdo()->exec("DELETE FROM gibbonStaffDuty WHERE gibbonStaffDutyID=$dutyID");
    });
    $c = $sync->collect(['from' => '2026-02-02', 'to' => '2026-02-08', 'kinds' => ['duty']]);
    $mine = array_filter($c['events'], fn($e) => $e['name'] === 'Duty — KT duty');
    kt_eq(1, count($mine), 'one duty in the week (the Monday)');
    $e = reset($mine);
    kt_eq('2026-02-02T07:45:00', $e['start'], 'on the Monday at the duty time');
    kt_eq('user', $e['scope'], 'visible to that staff member only');
    kt_eq(1, count($e['users']), 'exactly one person');
});

kt_test('events.only the requested kinds are ever deleted from the ledger', function () {
    $sync = kt_evt_sync(); kt_evt_need_year($sync);
    KreckEventSync::ensureTables(kt_pdo());
    kt_pdo()->exec("INSERT INTO kreckEventLedger (sourceID, kind, eventDate, contentHash, status) VALUES ('kt:class-row', 'class', '" . KT_EVT_DATE . "', 'x', 'Synced'), ('kt:lesson-row', 'lesson', '" . KT_EVT_DATE . "', 'x', 'Synced')");
    kt_cleanup(fn() => kt_pdo()->exec("DELETE FROM kreckEventLedger WHERE sourceID LIKE 'kt:%'"));
    $c = $sync->collect(['from' => KT_EVT_DATE, 'to' => KT_EVT_DATE, 'kinds' => ['lesson']]);
    $plan = $sync->plan($c);
    kt_assert(isset($plan['delete']['kt:lesson-row']), 'a vanished lesson is scheduled for deletion');
    kt_assert(!isset($plan['delete']['kt:class-row']), 'a class row is left alone when only lessons were examined');
});

kt_test('events.content hash ignores private helpers and changes with anything the LMS sees', function () {
    $e = ['source_id' => 'x', 'kind' => 'lesson', 'name' => 'A', 'start' => '2026-02-02T10:00:00', 'scope' => 'site', '_date' => '2026-02-02'];
    kt_eq(KreckEventSync::hash($e), KreckEventSync::hash($e + ['op' => 'upsert']), 'op is not part of the content');
    $f = $e; $f['_date'] = '2099-01-01';
    kt_eq(KreckEventSync::hash($e), KreckEventSync::hash($f), 'private _date is ignored');
    $g = $e; $g['name'] = 'B';
    kt_assert(KreckEventSync::hash($e) !== KreckEventSync::hash($g), 'a changed name changes the hash');
});
