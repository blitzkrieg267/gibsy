<?php
/**
 * Kreck Bridge — SMS → LMS event mirror.
 *
 * Every dated thing that appears on the SMS timetable/calendar is collected into a list of
 * normalised events (one stable `source_id` each) and pushed to the LMS web service
 * `local_kreck_sync_events`, which creates / updates / removes the equivalent LMS calendar event.
 *
 *   collect()  reads the SMS: class periods, lessons, closures & timing changes, term dates, calendar
 *              events, activities, staff duty & cover, dated assessments.
 *   plan()     compares them with the LEDGER (kreckEventLedger: source_id → content hash + status), which
 *              is what makes this work without "updated" timestamps on Gibbon's own tables.
 *   run()      sends only what is new/changed (+ deletes for what vanished) in batches, then records the
 *              outcome per event. Safe to run as often as you like; a repeat run sends nothing.
 *
 * Audience → LMS mapping (the LMS applies it; Moodle cannot hide a course event from students only):
 *   whole class (students + teachers)          → scope course  (the class's LMS course)
 *   teachers only (lesson hidden from students) → scope user    (the class teachers)
 *   parents of a class                         → scope user    (their linked parents)
 *   whole school                               → scope site
 *   year group                                 → scope course  (every class of that year group)
 *   form group / activity / duty / cover       → scope user
 *
 * Only a rolling window is synchronised (default: 14 days back, 90 days ahead, inside the school year),
 * so the first run is bounded and every later run extends the horizon.
 */

require_once __DIR__ . '/ManifestBuilder.php';
require_once __DIR__ . '/Exporter.php';

class KreckEventSync
{
    const DAYS_BACK = 14;
    const DAYS_AHEAD = 90;
    const BATCH = 100;
    /** Re-send "skipped" events (LMS audience not there yet) at most this often. */
    const RETRY_SKIPPED_AFTER = 3600;

    /** @var \PDO */
    private $db;
    /** @var array module settings */
    private $settings;
    /** @var KreckManifestBuilder */
    private $builder;
    private $columnCache = [];
    /** @var callable|null Replaceable transport (tests / alternative channels): fn(array $settings, string $fn, array $params, int $timeout): array */
    private $transport = null;

    public function __construct($connection2, array $settings)
    {
        $this->db = $connection2;
        $this->settings = $settings;
        $this->builder = new KreckManifestBuilder($connection2, $settings);
    }

    /** Replace the HTTP transport (used by tests; default is KreckExporter::call over HTTPS). */
    public function setTransport(?callable $transport): void
    {
        $this->transport = $transport;
    }

    private function send(string $fn, array $params, int $timeout): array
    {
        return $this->transport
            ? (array) call_user_func($this->transport, $this->settings, $fn, $params, $timeout)
            : KreckExporter::call($this->settings, $fn, $params, $timeout);
    }

    // ------------------------------------------------------------------ setup

    /** Create the ledger/run tables if they are missing (idempotent; also created by CHANGEDB / manifest). */
    public static function ensureTables($db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS `kreckEventLedger` (
            `sourceID` VARCHAR(190) NOT NULL,
            `kind` VARCHAR(30) NOT NULL DEFAULT '',
            `eventDate` DATE NULL,
            `contentHash` CHAR(40) NOT NULL,
            `status` ENUM('Synced','Pending','Skipped','Failed') NOT NULL DEFAULT 'Pending',
            `message` VARCHAR(255) NULL,
            `timestampSynced` DATETIME NULL,
            `timestampUpdated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`sourceID`), KEY `eventDate` (`eventDate`), KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS `kreckEventRun` (
            `kreckEventRunID` INT UNSIGNED AUTO_INCREMENT,
            `started` DATETIME NOT NULL,
            `finished` DATETIME NULL,
            `trigger` VARCHAR(20) NOT NULL DEFAULT 'manual',
            `ok` ENUM('Y','N') NOT NULL DEFAULT 'N',
            `summary` TEXT NULL,
            PRIMARY KEY (`kreckEventRunID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    // ------------------------------------------------------------- small helpers

    private function rows(string $sql, array $bind = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!isset($this->columnCache[$key])) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
            $stmt->execute(['t' => $table, 'c' => $column]);
            $this->columnCache[$key] = (int) $stmt->fetchColumn() > 0;
        }
        return $this->columnCache[$key];
    }

    private function setting(string $scope, string $name, string $default = ''): string
    {
        $stmt = $this->db->prepare('SELECT value FROM gibbonSetting WHERE scope=:s AND name=:n');
        $stmt->execute(['s' => $scope, 'n' => $name]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string) $v;
    }

    /** "13:05:00" → "13:05" (an empty/invalid time → null). */
    private static function hm($t): ?string
    {
        $t = (string) $t;
        return preg_match('/^(\d{2}):(\d{2})/', $t, $m) ? $m[1] . ':' . $m[2] : null;
    }

    private static function stamp(string $date, ?string $time, string $fallback = '00:00'): string
    {
        return $date . 'T' . ($time ?: $fallback) . ':00';
    }

    private static function name(array $r): string
    {
        $pn = trim((string) ($r['preferredName'] ?? ''));
        $sn = trim((string) ($r['surname'] ?? ''));
        return trim($pn . ' ' . $sn);
    }

    private static function csv(?string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $list)), 'strlen'));
    }

    /** Dates between two Y-m-d dates inclusive. */
    private static function dates(string $from, string $to): array
    {
        $out = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $out[] = date('Y-m-d', $t);
        }
        return $out;
    }

    // ---------------------------------------------------------------- reference data

    /** Current school year row (or the one asked for). */
    private function year($syid = null): ?array
    {
        return $this->builder->schoolYear($syid);
    }

    /** @return array<int,array> class catalogue with LMS shortnames (ManifestBuilder rule). */
    private function classes(int $syid): array
    {
        return $this->builder->classCatalog($syid);
    }

    /**
     * Per-class people (usernames the LMS knows): students + teachers (Teacher/Assistant/Technician).
     * @return array{students: array<int,string[]>, teachers: array<int,string[]>, teacherNames: array<int,string[]>}
     */
    private function classPeople(): array
    {
        $rows = $this->rows(
            "SELECT cp.gibbonCourseClassID AS ccid, cp.role, p.gibbonPersonID, p.username, p.studentID, p.preferredName, p.surname
             FROM gibbonCourseClassPerson cp
             JOIN gibbonPerson p ON p.gibbonPersonID = cp.gibbonPersonID AND p.status = 'Full'
             WHERE cp.role IN ('Student','Teacher','Assistant','Technician') AND (cp.dateUnenrolled IS NULL OR cp.dateUnenrolled >= CURDATE())"
        );
        $students = []; $teachers = []; $teacherNames = [];
        foreach ($rows as $r) {
            $ccid = (int) $r['ccid'];
            if ($r['role'] === 'Student') {
                $students[$ccid][] = strtolower($this->builder->studentUsername($r));
            } else {
                $teachers[$ccid][] = strtolower($this->builder->staffUsername($r));
                if ($r['role'] === 'Teacher') {
                    $teacherNames[$ccid][] = self::name($r);
                }
            }
        }
        return ['students' => $students, 'teachers' => $teachers, 'teacherNames' => $teacherNames];
    }

    /** Parent usernames (childDataAccess='Y') keyed by child gibbonPersonID. */
    private function parentsByChild(): array
    {
        $rows = $this->rows(
            "SELECT fc.gibbonPersonID AS childPID, a.gibbonPersonID AS adultID, a.username
             FROM gibbonFamilyChild fc
             JOIN gibbonFamilyAdult fa ON fa.gibbonFamilyID = fc.gibbonFamilyID AND fa.childDataAccess = 'Y'
             JOIN gibbonPerson a ON a.gibbonPersonID = fa.gibbonPersonID AND a.status = 'Full'"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['childPID']][] = strtolower($this->builder->parentUsername($r));
        }
        return $out;
    }

    /** Parent usernames per class: the parents of the class's students. */
    private function classParents(): array
    {
        $byChild = $this->parentsByChild();
        $rows = $this->rows(
            "SELECT cp.gibbonCourseClassID AS ccid, cp.gibbonPersonID AS pid
             FROM gibbonCourseClassPerson cp JOIN gibbonPerson p ON p.gibbonPersonID = cp.gibbonPersonID AND p.status = 'Full'
             WHERE cp.role = 'Student'"
        );
        $out = [];
        foreach ($rows as $r) {
            foreach ($byChild[(int) $r['pid']] ?? [] as $u) {
                $out[(int) $r['ccid']][$u] = true;
            }
        }
        return array_map('array_keys', $out);
    }

    /** All usernames by audience group, for site-wide calendar events. */
    private function everyone(int $syid): array
    {
        $staff = array_map(fn($r) => strtolower($this->builder->staffUsername($r)), $this->rows(
            "SELECT p.gibbonPersonID, p.username FROM gibbonPerson p JOIN gibbonRole r ON r.gibbonRoleID = p.gibbonRoleIDPrimary AND r.category='Staff' WHERE p.status='Full'"));
        $studentRows = $this->rows(
            "SELECT p.gibbonPersonID, p.studentID FROM gibbonPerson p
             JOIN gibbonStudentEnrolment se ON se.gibbonPersonID = p.gibbonPersonID AND se.gibbonSchoolYearID = :sy WHERE p.status='Full'", ['sy' => $syid]);
        $students = array_map(fn($r) => strtolower($this->builder->studentUsername($r)), $studentRows);
        $byChild = $this->parentsByChild();
        $parents = [];
        foreach ($studentRows as $r) {
            foreach ($byChild[(int) $r['gibbonPersonID']] ?? [] as $u) { $parents[$u] = true; }
        }
        return ['staff' => array_values(array_unique($staff)), 'students' => array_values(array_unique($students)), 'parents' => array_keys($parents)];
    }

    // ---------------------------------------------------------------- collection

    /**
     * Collect every event in the window.
     *
     * @param array $opts syid, from, to (Y-m-d), kinds (list of kinds to include; default all)
     * @return array ['events' => [source_id => event], 'from', 'to', 'syid', 'timezone']
     */
    public function collect(array $opts = []): array
    {
        $sy = $this->year($opts['syid'] ?? null);
        if (!$sy) {
            return ['events' => [], 'from' => null, 'to' => null, 'syid' => null, 'timezone' => 'UTC'];
        }
        $syid = (int) $sy['gibbonSchoolYearID'];
        $tz = $this->setting('System', 'timezone', 'UTC');

        $yearFirst = substr((string) $sy['firstDay'], 0, 10);
        $yearLast = substr((string) $sy['lastDay'], 0, 10);
        $from = $opts['from'] ?? date('Y-m-d', strtotime('-' . self::DAYS_BACK . ' days'));
        $to = $opts['to'] ?? date('Y-m-d', strtotime('+' . self::DAYS_AHEAD . ' days'));
        if ($yearFirst && $from < $yearFirst) { $from = $yearFirst; }
        if ($yearLast && $to > $yearLast) { $to = $yearLast; }
        if ($from > $to) {
            return ['events' => [], 'from' => $from, 'to' => $to, 'syid' => $syid, 'timezone' => $tz];
        }
        $kinds = $opts['kinds'] ?? ['class', 'lesson', 'special', 'term', 'calendar', 'activity', 'duty', 'cover', 'assessment'];

        $ctx = [
            'syid' => $syid, 'from' => $from, 'to' => $to, 'tz' => $tz,
            'classes' => $this->classes($syid),
        ];
        $ctx += $this->classPeople();
        $ctx['classParents'] = $this->classParents();
        $ctx['terms'] = $this->rows("SELECT gibbonSchoolYearTermID, name, nameShort, firstDay, lastDay FROM gibbonSchoolYearTerm WHERE gibbonSchoolYearID = :s ORDER BY sequenceNumber", ['s' => $syid]);
        $ctx['special'] = $this->specialDays($ctx);

        $events = [];
        $add = function (array $list) use (&$events) {
            foreach ($list as $e) { $events[$e['source_id']] = $e; }
        };
        // Lessons first: a planner lesson replaces the timetable period it was created for.
        $covered = [];
        if (in_array('lesson', $kinds, true)) { $add($this->lessons($ctx, $covered)); }
        if (in_array('class', $kinds, true))  { $add($this->classPeriods($ctx, $covered)); }
        if (in_array('special', $kinds, true))   { $add($this->specialEvents($ctx)); }
        if (in_array('term', $kinds, true))      { $add($this->termEvents($ctx)); }
        if (in_array('calendar', $kinds, true))  { $add($this->calendarEvents($ctx)); }
        if (in_array('activity', $kinds, true))  { $add($this->activityEvents($ctx)); }
        if (in_array('duty', $kinds, true))      { $add($this->dutyEvents($ctx)); }
        if (in_array('cover', $kinds, true))     { $add($this->coverEvents($ctx)); }
        if (in_array('assessment', $kinds, true)) { $add($this->assessmentEvents($ctx)); }

        return ['events' => $events, 'from' => $from, 'to' => $to, 'syid' => $syid, 'timezone' => $tz, 'kinds' => $kinds];
    }

    /** Special days in the window with the classes they cancel. */
    private function specialDays(array $ctx): array
    {
        $rows = $this->rows(
            "SELECT * FROM gibbonSchoolYearSpecialDay WHERE date BETWEEN :f AND :t ORDER BY date", ['f' => $ctx['from'], 't' => $ctx['to']]);
        $out = [];
        foreach ($rows as $r) {
            $groups = self::csv($r['gibbonYearGroupIDList'] ?? '');
            $classes = [];   // classes this day applies to
            if (empty($r['context'])) {
                $classes = array_keys($ctx['classes']);
            } elseif ($r['context'] === 'Year Group') {
                foreach ($ctx['classes'] as $ccid => $c) {
                    if (array_intersect($c['yearGroups'], $groups)) { $classes[] = $ccid; }
                }
            }
            $r['_classes'] = $classes;
            $out[] = $r;
        }
        return $out;
    }

    private function url(string $path): string
    {
        return $path;
    }

    /** Build the course/user events for a class event, honouring student/parent visibility. */
    private function classAudience(array $ctx, int $ccid, array $base, bool $studentsVisible, bool $parentsVisible, array $extraTeachers = []): array
    {
        $out = [];
        $short = $ctx['classes'][$ccid]['short'] ?? null;
        if ($short === null) {
            return [];
        }
        if ($studentsVisible) {
            $out[] = $base + ['scope' => 'course', 'course' => $short];
        } else {
            $teachers = array_values(array_unique(array_merge($ctx['teachers'][$ccid] ?? [], $extraTeachers)));
            if ($teachers) {
                $out[] = $base + ['scope' => 'user', 'users' => $teachers];
            }
        }
        $parents = $parentsVisible ? ($ctx['classParents'][$ccid] ?? []) : [];
        if ($parents) {
            $copy = $base;
            $copy['source_id'] .= ':parents';
            $copy['description'] = trim("Your child's class: " . $ctx['classes'][$ccid]['course'] . "\n" . ($base['description'] ?? ''));
            $out[] = $copy + ['scope' => 'user', 'users' => $parents];
        }
        return $out;
    }

    // ----- class periods --------------------------------------------------------

    private function classPeriods(array $ctx, array $covered): array
    {
        $rows = $this->rows(
            "SELECT rc.gibbonTTDayRowClassID AS rcid, rc.gibbonCourseClassID AS ccid, dd.date, d.name AS dayName,
                    per.name AS periodName, per.timeStart, per.timeEnd,
                    COALESCE(sp2.name, sp.name) AS room
             FROM gibbonTTDayRowClass rc
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = rc.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID AND c.gibbonSchoolYearID = :cy
             JOIN gibbonTTDay d ON d.gibbonTTDayID = rc.gibbonTTDayID
             JOIN gibbonTT tt ON tt.gibbonTTID = d.gibbonTTID AND tt.gibbonSchoolYearID = :ty
             JOIN gibbonTTDayDate dd ON dd.gibbonTTDayID = d.gibbonTTDayID
             JOIN gibbonTTColumnRow per ON per.gibbonTTColumnRowID = rc.gibbonTTColumnRowID
             LEFT JOIN gibbonSpace sp ON sp.gibbonSpaceID = rc.gibbonSpaceID
             LEFT JOIN gibbonTTSpaceChange sc ON sc.gibbonTTDayRowClassID = rc.gibbonTTDayRowClassID AND sc.date = dd.date
             LEFT JOIN gibbonSpace sp2 ON sp2.gibbonSpaceID = sc.gibbonSpaceID
             WHERE dd.date BETWEEN :f AND :t
             ORDER BY dd.date, per.timeStart",
            ['cy' => $ctx['syid'], 'ty' => $ctx['syid'], 'f' => $ctx['from'], 't' => $ctx['to']]
        );
        $out = [];
        foreach ($rows as $r) {
            $ccid = (int) $r['ccid'];
            $date = $r['date'];
            if (isset($covered[(int) $r['rcid'] . ':' . $date]) || $this->classCancelled($ctx, $ccid, $date)
                || $this->lessonOverlaps($covered, $ccid, $date, self::hm($r['timeStart']), self::hm($r['timeEnd']))) {
                continue;
            }
            $cls = $ctx['classes'][$ccid] ?? null;
            if (!$cls) { continue; }
            $teachers = $ctx['teacherNames'][$ccid] ?? [];
            $out[] = [
                'source_id'   => 'gibbonTTDayRowClass:' . (int) $r['rcid'] . ':' . $date,
                'kind'        => 'class', 'label' => 'Class',
                'name'        => $cls['course'] . ($cls['class'] ? ' · ' . $cls['class'] : '') . ' — ' . $r['periodName'],
                'description' => trim($r['dayName'] . ' · ' . self::hm($r['timeStart']) . '–' . self::hm($r['timeEnd'])
                                . ($r['room'] ? "\nRoom: " . $r['room'] : '') . ($teachers ? "\nTeacher: " . implode(', ', $teachers) : '')),
                'start'       => self::stamp($date, self::hm($r['timeStart'])),
                'end'         => self::stamp($date, self::hm($r['timeEnd'])),
                'location'    => (string) $r['room'],
                'scope'       => 'course', 'course' => $cls['short'], 'adopt' => true,
                'url'         => '/index.php?q=/modules/Planner/planner.php&viewBy=class&gibbonCourseClassID=' . $ccid . '&date=' . $date,
                '_date'       => $date,
            ];
        }
        return $out;
    }

    /** Does a planner lesson of this class overlap the period [start, end) on this date? */
    private function lessonOverlaps(array $covered, int $ccid, string $date, ?string $start, ?string $end): bool
    {
        if (!$start || !$end) { return false; }
        foreach ($covered['span:' . $ccid . ':' . $date] ?? [] as [$ls, $le]) {
            if ($ls < $end && $le > $start) { return true; }
        }
        return false;
    }

    /** True when a school closure / off-timetable day cancels classes for this class on this date. */
    private function classCancelled(array $ctx, int $ccid, string $date): bool
    {
        foreach ($ctx['special'] as $sd) {
            if ($sd['date'] === $date && ($sd['cancelClasses'] ?? 'N') === 'Y' && in_array($ccid, $sd['_classes'], true)) {
                return true;
            }
        }
        return false;
    }

    // ----- planner lessons ------------------------------------------------------

    private function lessons(array $ctx, array &$covered): array
    {
        $space = $this->hasColumn('gibbonPlannerEntry', 'gibbonSpaceID');
        $slot = $this->hasColumn('gibbonPlannerEntry', 'gibbonTTDayRowClassID');
        $rows = $this->rows(
            "SELECT pe.gibbonPlannerEntryID AS id, pe.gibbonCourseClassID AS ccid, pe.date, pe.timeStart, pe.timeEnd, pe.name, pe.summary,
                    pe.description, pe.viewableStudents, pe.viewableParents, pe.gibbonPersonIDCreator AS creator,
                    " . ($slot ? 'pe.gibbonTTDayRowClassID' : 'NULL') . " AS rcid,
                    " . ($space ? 'sp.name' : 'NULL') . " AS room, cr.username AS creatorUsername, cr.gibbonPersonID AS creatorPID
             FROM gibbonPlannerEntry pe
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = pe.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID AND c.gibbonSchoolYearID = :sy
             " . ($space ? 'LEFT JOIN gibbonSpace sp ON sp.gibbonSpaceID = pe.gibbonSpaceID' : '') . "
             LEFT JOIN gibbonPerson cr ON cr.gibbonPersonID = pe.gibbonPersonIDCreator
             WHERE pe.date BETWEEN :f AND :t",
            ['sy' => $ctx['syid'], 'f' => $ctx['from'], 't' => $ctx['to']]
        );
        $out = [];
        foreach ($rows as $r) {
            $ccid = (int) $r['ccid'];
            $date = $r['date'];
            if ($this->classCancelled($ctx, $ccid, $date)) { continue; }
            if (!empty($r['rcid'])) { $covered[(int) $r['rcid'] . ':' . $date] = true; }
            // Older Gibbon schemas have no timetable-slot link on a lesson, so also remember WHEN it runs:
            // a lesson replaces any period of the same class that overlaps it on that date.
            if (self::hm($r['timeStart']) && self::hm($r['timeEnd'])) {
                $covered['span:' . $ccid . ':' . $date][] = [self::hm($r['timeStart']), self::hm($r['timeEnd'])];
            }
            $cls = $ctx['classes'][$ccid] ?? null;
            if (!$cls) { continue; }
            $desc = trim(strip_tags(html_entity_decode((string) $r['summary'] . "\n" . (string) $r['description'])));
            $teachers = $ctx['teacherNames'][$ccid] ?? [];
            $base = [
                'source_id'   => 'gibbonPlannerEntry:' . (int) $r['id'],
                'kind'        => 'lesson', 'label' => 'Lesson',
                'name'        => $cls['course'] . ($cls['class'] ? ' · ' . $cls['class'] : '') . ' — ' . $r['name'],
                'description' => trim($desc . ($teachers ? "\nTeacher: " . implode(', ', $teachers) : '')),
                'start'       => self::stamp($date, self::hm($r['timeStart'])),
                'end'         => self::stamp($date, self::hm($r['timeEnd']), self::hm($r['timeStart']) ?: '00:00'),
                'location'    => (string) ($r['room'] ?? ''),
                'url'         => '/index.php?q=/modules/Planner/planner_view_full.php&gibbonPlannerEntryID=' . (int) $r['id'] . '&viewBy=class&gibbonCourseClassID=' . $ccid,
                '_date'       => $date,
            ];
            $extra = $r['creatorUsername'] ? [strtolower($this->builder->staffUsername(['username' => $r['creatorUsername'], 'gibbonPersonID' => $r['creatorPID']]))] : [];
            foreach ($this->classAudience($ctx, $ccid, $base, $r['viewableStudents'] === 'Y', $r['viewableParents'] === 'Y', $extra) as $e) {
                $out[] = $e;
            }
        }
        return $out;
    }

    // ----- closures, timing changes, off-timetable days ---------------------------

    private function specialEvents(array $ctx): array
    {
        $labels = ['School Closure' => 'School closure', 'Timing Change' => 'Timing change', 'Off Timetable' => 'Off timetable'];
        $out = [];
        foreach ($ctx['special'] as $sd) {
            $label = $labels[$sd['type']] ?? 'School day';
            $date = $sd['date'];
            $base = [
                'source_id'   => 'gibbonSchoolYearSpecialDay:' . (int) $sd['gibbonSchoolYearSpecialDayID'],
                'kind'        => 'special', 'label' => $label,
                'name'        => $label . ' — ' . $sd['name'],
                'description' => (string) ($sd['description'] ?? ''),
                '_date'       => $date,
            ];
            $start = self::hm($sd['schoolStart'] ?? null); $end = self::hm($sd['schoolEnd'] ?? null);
            if ($sd['type'] === 'Timing Change' && $start && $end) {
                $base += ['start' => self::stamp($date, $start), 'end' => self::stamp($date, $end), 'all_day' => false];
            } else {
                $base += ['start' => $date, 'end' => $date, 'all_day' => true];
            }
            if (empty($sd['context'])) {
                $out[] = $base + ['scope' => 'site'];
            } elseif ($sd['context'] === 'Year Group') {
                $shorts = [];
                foreach ($sd['_classes'] as $ccid) { $shorts[] = $ctx['classes'][$ccid]['short']; }
                if ($shorts) { $out[] = $base + ['scope' => 'course', 'courses' => $shorts]; }
            } else { // Form Group → the students, their parents and the tutors
                $users = $this->formGroupAudience($ctx, self::csv($sd['gibbonFormGroupIDList'] ?? ''));
                if ($users) { $out[] = $base + ['scope' => 'user', 'users' => $users]; }
            }
        }
        return $out;
    }

    /** Students, their parents and the tutors of the given form groups (usernames). */
    private function formGroupAudience(array $ctx, array $formGroupIDs): array
    {
        if (!$formGroupIDs) { return []; }
        $in = implode(',', array_fill(0, count($formGroupIDs), '?'));
        $stmt = $this->db->prepare(
            "SELECT p.gibbonPersonID, p.studentID FROM gibbonStudentEnrolment se JOIN gibbonPerson p ON p.gibbonPersonID = se.gibbonPersonID AND p.status='Full'
             WHERE se.gibbonSchoolYearID = ? AND se.gibbonFormGroupID IN ($in)");
        $stmt->execute(array_merge([$ctx['syid']], $formGroupIDs));
        $users = [];
        $byChild = $this->parentsByChild();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $users[] = strtolower($this->builder->studentUsername($r));
            foreach ($byChild[(int) $r['gibbonPersonID']] ?? [] as $u) { $users[] = $u; }
        }
        $stmt = $this->db->prepare(
            "SELECT p.gibbonPersonID, p.username FROM gibbonFormGroup fg
             JOIN gibbonPerson p ON p.gibbonPersonID IN (fg.gibbonPersonIDTutor, fg.gibbonPersonIDTutor2, fg.gibbonPersonIDTutor3) AND p.status='Full'
             WHERE fg.gibbonFormGroupID IN ($in)");
        $stmt->execute($formGroupIDs);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $users[] = strtolower($this->builder->staffUsername($r));
        }
        return array_values(array_unique($users));
    }

    // ----- term dates -----------------------------------------------------------

    private function termEvents(array $ctx): array
    {
        $out = [];
        foreach ($ctx['terms'] as $t) {
            foreach (['firstDay' => 'begins', 'lastDay' => 'ends'] as $col => $verb) {
                $date = substr((string) $t[$col], 0, 10);
                if (!$date || $date === '0000-00-00') { continue; }
                $out[] = [
                    'source_id' => 'gibbonSchoolYearTerm:' . (int) $t['gibbonSchoolYearTermID'] . ':' . ($col === 'firstDay' ? 'first' : 'last'),
                    'kind' => 'term', 'label' => 'Term', 'name' => $t['name'] . ' ' . $verb,
                    'description' => '', 'start' => $date, 'end' => $date, 'all_day' => true, 'scope' => 'site',
                    '_date' => $date,
                ];
            }
        }
        return $out;
    }

    // ----- core calendar events -----------------------------------------------------

    private function calendarEvents(array $ctx): array
    {
        $rows = $this->rows(
            "SELECT ce.*, cal.name AS calName, cal.public, cal.viewableStaff, cal.viewableStudents, cal.viewableParents, cal.viewableParticipants,
                    sp.name AS spaceName
             FROM gibbonCalendarEvent ce
             JOIN gibbonCalendar cal ON cal.gibbonCalendarID = ce.gibbonCalendarID AND cal.gibbonSchoolYearID = :sy
             LEFT JOIN gibbonSpace sp ON sp.gibbonSpaceID = ce.gibbonSpaceID
             WHERE ce.dateEnd >= :f AND ce.dateStart <= :t",
            ['sy' => $ctx['syid'], 'f' => $ctx['from'], 't' => $ctx['to']]
        );
        if (!$rows) { return []; }
        $everyone = $this->everyone($ctx['syid']);
        $out = [];
        foreach ($rows as $r) {
            $allDay = $r['allDay'] === 'Y' || !self::hm($r['timeStart']);
            $location = trim((string) ($r['locationDetail'] ?: $r['spaceName']));
            $base = [
                'source_id'   => 'gibbonCalendarEvent:' . (int) $r['gibbonCalendarEventID'],
                'kind'        => 'calendar', 'label' => (string) $r['calName'],
                'name'        => (string) $r['name'],
                'description' => trim(strip_tags(html_entity_decode((string) $r['description']))),
                'location'    => $location,
                'status'      => strtolower((string) $r['status']),
                '_date'       => $r['dateStart'],
            ];
            if ($allDay) {
                $base += ['start' => $r['dateStart'], 'end' => $r['dateEnd'], 'all_day' => true];
            } else {
                $base += ['start' => self::stamp($r['dateStart'], self::hm($r['timeStart'])),
                          'end' => self::stamp($r['dateEnd'], self::hm($r['timeEnd']), self::hm($r['timeStart']))];
            }
            $whole = $r['public'] === 'Y' || ($r['viewableStaff'] === 'Y' && $r['viewableStudents'] === 'Y' && $r['viewableParents'] === 'Y');
            if ($whole) {
                $out[] = $base + ['scope' => 'site'];
                continue;
            }
            $users = [];
            if ($r['viewableStaff'] === 'Y')    { $users = array_merge($users, $everyone['staff']); }
            if ($r['viewableStudents'] === 'Y') { $users = array_merge($users, $everyone['students']); }
            if ($r['viewableParents'] === 'Y')  { $users = array_merge($users, $everyone['parents']); }
            if ($r['viewableParticipants'] === 'Y') {
                foreach ($this->rows("SELECT p.gibbonPersonID, p.username, p.studentID, r.category FROM gibbonCalendarEventPerson ep
                        JOIN gibbonPerson p ON p.gibbonPersonID = ep.gibbonPersonID AND p.status='Full'
                        LEFT JOIN gibbonRole r ON r.gibbonRoleID = p.gibbonRoleIDPrimary WHERE ep.gibbonCalendarEventID = :id",
                        ['id' => $r['gibbonCalendarEventID']]) as $p) {
                    $users[] = strtolower(($p['category'] ?? '') === 'Student' ? $this->builder->studentUsername($p) : $this->builder->staffUsername($p));
                }
            }
            $users = array_values(array_unique($users));
            if ($users) { $out[] = $base + ['scope' => 'user', 'users' => $users]; }
            else { $out[] = $base + ['scope' => 'user', 'users' => [], 'status' => 'cancelled']; }   // nobody may see it → ensure none exists
        }
        return $out;
    }

    // ----- activities -------------------------------------------------------------

    private function activityEvents(array $ctx): array
    {
        $acts = $this->rows(
            "SELECT a.gibbonActivityID AS id, a.name, a.programStart, a.programEnd, a.gibbonYearGroupIDList,
                    s.gibbonActivitySlotID AS slotID, s.timeStart, s.timeEnd, s.locationExternal, dw.name AS dayName, sp.name AS room
             FROM gibbonActivity a
             JOIN gibbonActivitySlot s ON s.gibbonActivityID = a.gibbonActivityID
             JOIN gibbonDaysOfWeek dw ON dw.gibbonDaysOfWeekID = s.gibbonDaysOfWeekID
             LEFT JOIN gibbonSpace sp ON sp.gibbonSpaceID = s.gibbonSpaceID
             WHERE a.gibbonSchoolYearID = :sy AND a.active = 'Y' AND a.programStart IS NOT NULL AND a.programEnd IS NOT NULL
               AND a.programEnd >= :f AND a.programStart <= :t",
            ['sy' => $ctx['syid'], 'f' => $ctx['from'], 't' => $ctx['to']]
        );
        if (!$acts) { return []; }
        $people = [];
        foreach ($this->rows(
            "SELECT ast.gibbonActivityID AS aid, p.gibbonPersonID, p.studentID, p.username, 'S' AS kind FROM gibbonActivityStudent ast
             JOIN gibbonPerson p ON p.gibbonPersonID = ast.gibbonPersonID AND p.status='Full' WHERE ast.status = 'Accepted'
             UNION ALL
             SELECT st.gibbonActivityID, p.gibbonPersonID, p.studentID, p.username, 'T' FROM gibbonActivityStaff st
             JOIN gibbonPerson p ON p.gibbonPersonID = st.gibbonPersonID AND p.status='Full'") as $r) {
            $people[(int) $r['aid']][] = strtolower($r['kind'] === 'S' ? $this->builder->studentUsername($r) : $this->builder->staffUsername($r));
        }
        $out = [];
        foreach ($acts as $a) {
            $users = array_values(array_unique($people[(int) $a['id']] ?? []));
            if (!$users) { continue; }
            $groups = self::csv($a['gibbonYearGroupIDList']);
            $from = max($ctx['from'], (string) $a['programStart']);
            $to = min($ctx['to'], (string) $a['programEnd']);
            foreach (self::dates($from, $to) as $date) {
                if (date('l', strtotime($date)) !== $a['dayName'] || !$this->isTermDay($ctx, $date)) { continue; }
                if ($this->specialCancels($ctx, $date, 'cancelActivities', $groups)) { continue; }
                $out[] = [
                    'source_id' => 'gibbonActivity:' . (int) $a['id'] . ':' . (int) $a['slotID'] . ':' . $date,
                    'kind' => 'activity', 'label' => 'Activity', 'name' => (string) $a['name'],
                    'description' => '', 'location' => (string) ($a['room'] ?: $a['locationExternal']),
                    'start' => self::stamp($date, self::hm($a['timeStart'])), 'end' => self::stamp($date, self::hm($a['timeEnd'])),
                    'scope' => 'user', 'users' => $users, '_date' => $date,
                ];
            }
        }
        return $out;
    }

    private function isTermDay(array $ctx, string $date): bool
    {
        foreach ($ctx['terms'] as $t) {
            if ($date >= substr((string) $t['firstDay'], 0, 10) && $date <= substr((string) $t['lastDay'], 0, 10)) { return true; }
        }
        return empty($ctx['terms']);
    }

    /** Does a special day cancel this kind of thing (cancelActivities / cancelDuty) on this date? */
    private function specialCancels(array $ctx, string $date, string $flag, array $yearGroups = []): bool
    {
        foreach ($ctx['special'] as $sd) {
            if ($sd['date'] !== $date || ($sd[$flag] ?? 'N') !== 'Y') { continue; }
            if (empty($sd['context'])) { return true; }
            if ($sd['context'] === 'Year Group' && (!$yearGroups || array_intersect($yearGroups, self::csv($sd['gibbonYearGroupIDList'] ?? '')))) { return true; }
        }
        return false;
    }

    // ----- staff duty & cover --------------------------------------------------------

    private function dutyEvents(array $ctx): array
    {
        $rows = $this->rows(
            "SELECT dp.gibbonStaffDutyPersonID AS id, d.name, d.timeStart, d.timeEnd, dw.name AS dayName, p.gibbonPersonID, p.username
             FROM gibbonStaffDutyPerson dp
             JOIN gibbonStaffDuty d ON d.gibbonStaffDutyID = dp.gibbonStaffDutyID
             JOIN gibbonDaysOfWeek dw ON dw.gibbonDaysOfWeekID = dp.gibbonDaysOfWeekID AND dw.schoolDay = 'Y'
             JOIN gibbonPerson p ON p.gibbonPersonID = dp.gibbonPersonID AND p.status = 'Full'");
        $out = [];
        foreach ($rows as $r) {
            $user = strtolower($this->builder->staffUsername($r));
            foreach (self::dates($ctx['from'], $ctx['to']) as $date) {
                if (date('l', strtotime($date)) !== $r['dayName'] || !$this->isTermDay($ctx, $date) || $this->specialCancels($ctx, $date, 'cancelDuty')) { continue; }
                $out[] = [
                    'source_id' => 'gibbonStaffDuty:' . (int) $r['id'] . ':' . $date,
                    'kind' => 'duty', 'label' => 'Duty', 'name' => 'Duty — ' . $r['name'], 'description' => '',
                    'start' => self::stamp($date, self::hm($r['timeStart'])), 'end' => self::stamp($date, self::hm($r['timeEnd'])),
                    'scope' => 'user', 'users' => [$user], '_date' => $date,
                ];
            }
        }
        return $out;
    }

    private function coverEvents(array $ctx): array
    {
        $rows = $this->rows(
            "SELECT cd.gibbonStaffCoverageDateID AS id, cd.date, cd.allDay, cd.timeStart, cd.timeEnd, cd.foreignTable, cd.foreignTableID,
                    p.gibbonPersonID, p.username
             FROM gibbonStaffCoverageDate cd
             JOIN gibbonStaffCoverage sc ON sc.gibbonStaffCoverageID = cd.gibbonStaffCoverageID AND sc.status = 'Accepted'
             JOIN gibbonPerson p ON p.gibbonPersonID = sc.gibbonPersonIDCoverage AND p.status = 'Full'
             WHERE cd.date BETWEEN :f AND :t", ['f' => $ctx['from'], 't' => $ctx['to']]);
        $out = [];
        foreach ($rows as $r) {
            $name = 'Cover'; $start = self::hm($r['timeStart']); $end = self::hm($r['timeEnd']); $room = '';
            if ($r['foreignTable'] === 'gibbonTTDayRowClass' && $r['foreignTableID']) {
                $x = $this->rows("SELECT rc.gibbonCourseClassID AS ccid, per.name AS pname, per.timeStart, per.timeEnd, sp.name AS room
                        FROM gibbonTTDayRowClass rc JOIN gibbonTTColumnRow per ON per.gibbonTTColumnRowID = rc.gibbonTTColumnRowID
                        LEFT JOIN gibbonSpace sp ON sp.gibbonSpaceID = rc.gibbonSpaceID WHERE rc.gibbonTTDayRowClassID = :id", ['id' => $r['foreignTableID']]);
                if ($x) {
                    $cls = $ctx['classes'][(int) $x[0]['ccid']] ?? null;
                    $name = 'Cover — ' . ($cls ? $cls['course'] . ($cls['class'] ? ' · ' . $cls['class'] : '') . ' · ' : '') . $x[0]['pname'];
                    $start = $start ?: self::hm($x[0]['timeStart']); $end = $end ?: self::hm($x[0]['timeEnd']); $room = (string) $x[0]['room'];
                }
            }
            $event = ['source_id' => 'gibbonStaffCoverageDate:' . (int) $r['id'], 'kind' => 'cover', 'label' => 'Cover', 'name' => $name,
                'description' => '', 'location' => $room, 'scope' => 'user', 'users' => [strtolower($this->builder->staffUsername($r))], '_date' => $r['date']];
            if ($r['allDay'] === 'Y' || !$start) { $event += ['start' => $r['date'], 'end' => $r['date'], 'all_day' => true]; }
            else { $event += ['start' => self::stamp($r['date'], $start), 'end' => self::stamp($r['date'], $end ?: $start)]; }
            $out[] = $event;
        }
        return $out;
    }

    // ----- dated assessments ---------------------------------------------------------

    private function assessmentEvents(array $ctx): array
    {
        $rows = $this->rows(
            "SELECT mc.gibbonMarkbookColumnID AS id, mc.gibbonCourseClassID AS ccid, mc.name, mc.description, mc.date, mc.type,
                    mc.viewableStudents, mc.viewableParents
             FROM gibbonMarkbookColumn mc
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = mc.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID AND c.gibbonSchoolYearID = :sy
             WHERE mc.date BETWEEN :f AND :t", ['sy' => $ctx['syid'], 'f' => $ctx['from'], 't' => $ctx['to']]);
        $out = [];
        foreach ($rows as $r) {
            $ccid = (int) $r['ccid'];
            $cls = $ctx['classes'][$ccid] ?? null;
            if (!$cls) { continue; }
            $base = [
                'source_id' => 'gibbonMarkbookColumn:' . (int) $r['id'], 'kind' => 'assessment', 'label' => 'Assessment',
                'name' => $cls['course'] . ($cls['class'] ? ' · ' . $cls['class'] : '') . ' — ' . $r['name'],
                'description' => trim(strip_tags(html_entity_decode((string) $r['description']))) . ($r['type'] ? "\nType: " . $r['type'] : ''),
                'start' => $r['date'], 'end' => $r['date'], 'all_day' => true, '_date' => $r['date'],
            ];
            foreach ($this->classAudience($ctx, $ccid, $base, $r['viewableStudents'] === 'Y', $r['viewableParents'] === 'Y') as $e) { $out[] = $e; }
        }
        return $out;
    }

    // ------------------------------------------------------------------ ledger + push

    /** Content hash of an event (everything the LMS sees; the private _date helper is excluded). */
    public static function hash(array $event): string
    {
        $copy = $event;
        unset($copy['_date'], $copy['op']);
        ksort($copy);
        return sha1(json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Compare collected events with the ledger.
     *
     * @return array ['upsert' => [source_id => event], 'delete' => [source_id => kind], 'unchanged' => int]
     */
    public function plan(array $collected, bool $force = false): array
    {
        self::ensureTables($this->db);
        $from = $collected['from']; $to = $collected['to'];
        $ledger = [];
        foreach ($this->rows("SELECT sourceID, kind, eventDate, contentHash, status, UNIX_TIMESTAMP(timestampSynced) AS syncedAt FROM kreckEventLedger") as $r) {
            $ledger[$r['sourceID']] = $r;
        }
        $upsert = []; $unchanged = 0;
        foreach ($collected['events'] as $sid => $e) {
            $row = $ledger[$sid] ?? null;
            $hash = self::hash($e);
            $stale = $row && in_array($row['status'], ['Pending', 'Failed'], true);
            $skippedDue = $row && $row['status'] === 'Skipped' && (time() - (int) $row['syncedAt']) > self::RETRY_SKIPPED_AFTER;
            if ($force || !$row || $row['contentHash'] !== $hash || $stale || $skippedDue) {
                $upsert[$sid] = $e;
            } else {
                $unchanged++;
            }
        }
        // Gone from the SMS (inside the window we looked at) → remove from the LMS.
        $delete = [];
        $kinds = $collected['kinds'] ?? null;
        foreach ($ledger as $sid => $row) {
            if (isset($collected['events'][$sid])) { continue; }
            if ($kinds !== null && !in_array($row['kind'], $kinds, true)) { continue; }   // not looked at this time
            $d = $row['eventDate'];
            $inWindow = $d === null ? false : ($d >= $from && $d <= $to);
            if ($inWindow || ($d === null && $row['kind'] === 'term')) {
                $delete[$sid] = $row['kind'];
            }
        }
        return ['upsert' => $upsert, 'delete' => $delete, 'unchanged' => $unchanged];
    }

    /** Wire format of an event: drop private helpers, force op. */
    private static function wire(array $event, string $op = 'upsert'): array
    {
        unset($event['_date']);
        $event['op'] = $op;
        return $event;
    }

    /**
     * Collect, diff and push.
     *
     * @param array $opts trigger ('manual'|'cron'|'page'|'save'), force, dryrun, kinds, from, to, syid, maxBatches
     * @return array summary (ok, error, sent, created, updated, unchanged, deleted, skipped, failed, window)
     */
    public function run(array $opts = []): array
    {
        self::ensureTables($this->db);
        $summary = ['ok' => false, 'error' => '', 'trigger' => $opts['trigger'] ?? 'manual', 'collected' => 0, 'sent' => 0,
            'created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'skipped' => 0, 'failed' => 0, 'window' => ''];

        $lock = $this->db->query("SELECT GET_LOCK('kreck_event_sync', 0)")->fetchColumn();
        if (!$lock) {
            $summary['error'] = 'Another event sync is already running.';
            return $summary;
        }
        $runID = null;
        try {
            if (empty($opts['dryrun'])) {
                $this->db->prepare("INSERT INTO kreckEventRun (started, `trigger`) VALUES (NOW(), :t)")->execute(['t' => $summary['trigger']]);
                $runID = (int) $this->db->lastInsertId();
            }
            $collected = $this->collect($opts);
            $summary['collected'] = count($collected['events']);
            $summary['window'] = $collected['from'] . ' → ' . $collected['to'];
            $plan = $this->plan($collected, !empty($opts['force']));
            $summary['unchanged'] = $plan['unchanged'];
            $summary['planned_upsert'] = count($plan['upsert']);
            $summary['planned_delete'] = count($plan['delete']);

            if (!empty($opts['dryrun'])) {
                $summary['ok'] = true;
                return $summary;
            }

            $queue = [];
            foreach ($plan['upsert'] as $sid => $e) { $queue[] = [$sid, self::wire($e), $e]; }
            foreach ($plan['delete'] as $sid => $kind) { $queue[] = [$sid, ['source_id' => $sid, 'op' => 'delete'], null]; }
            $maxBatches = (int) ($opts['maxBatches'] ?? 0);

            foreach (array_chunk($queue, self::BATCH) as $i => $chunk) {
                if ($maxBatches && $i >= $maxBatches) { break; }
                $resp = $this->send('local_kreck_sync_events', [
                    'eventsjson' => json_encode(array_column($chunk, 1), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'timezone'   => $collected['timezone'],
                    'dryrun'     => 0,
                ], 180);
                if (!empty($resp['error']) || !isset($resp['results'])) {
                    $summary['error'] = (string) ($resp['error'] ?? 'Unexpected response from the LMS.');
                    $this->markFailed($chunk, $summary['error']);
                    $summary['failed'] += count($chunk);
                    break;
                }
                $byId = [];
                foreach ($resp['results'] as $r) { $byId[$r['source_id']] = $r; }
                foreach ($chunk as [$sid, $wire, $event]) {
                    $res = $byId[$sid] ?? ['result' => 'error', 'message' => 'No result returned.'];
                    $this->record($sid, $wire['op'], $event, $res, $summary);
                }
                $summary['sent'] += count($chunk);
            }
            $summary['ok'] = $summary['error'] === '';
        } catch (\Throwable $e) {
            $summary['error'] = $e->getMessage();
        } finally {
            if ($runID) {
                $this->db->prepare("UPDATE kreckEventRun SET finished = NOW(), ok = :ok, summary = :s WHERE kreckEventRunID = :id")
                    ->execute(['ok' => $summary['ok'] ? 'Y' : 'N', 's' => json_encode($summary), 'id' => $runID]);
                $this->db->exec("DELETE FROM kreckEventRun WHERE kreckEventRunID < " . max(0, $runID - 50));
            }
            $this->db->query("SELECT RELEASE_LOCK('kreck_event_sync')");
        }
        return $summary;
    }

    /** Record one event's outcome in the ledger and the summary. */
    private function record(string $sid, string $op, ?array $event, array $res, array &$summary): void
    {
        $result = (string) ($res['result'] ?? 'error');
        $message = mb_substr((string) ($res['message'] ?? ''), 0, 250);
        if ($op === 'delete') {
            if ($result !== 'error') {
                $this->db->prepare("DELETE FROM kreckEventLedger WHERE sourceID = :s")->execute(['s' => $sid]);
                $summary['deleted']++;
            } else {
                $summary['failed']++;
            }
            return;
        }
        $status = in_array($result, ['created', 'updated', 'unchanged', 'deleted'], true) ? 'Synced' : ($result === 'skipped' ? 'Skipped' : 'Failed');
        if (in_array($result, ['created', 'updated', 'unchanged', 'skipped'], true)) { $summary[$result]++; }
        if ($result === 'deleted') { $summary['deleted']++; }
        if ($status === 'Failed') { $summary['failed']++; }
        $this->db->prepare(
            "INSERT INTO kreckEventLedger (sourceID, kind, eventDate, contentHash, status, message, timestampSynced)
             VALUES (:s, :k, :d, :h, :st, :m, NOW())
             ON DUPLICATE KEY UPDATE kind = VALUES(kind), eventDate = VALUES(eventDate), contentHash = VALUES(contentHash),
                                     status = VALUES(status), message = VALUES(message), timestampSynced = VALUES(timestampSynced)"
        )->execute(['s' => $sid, 'k' => (string) ($event['kind'] ?? ''), 'd' => $event['_date'] ?? null, 'h' => self::hash($event ?? []), 'st' => $status, 'm' => $message ?: null]);
    }

    /** A whole batch failed (network/auth): keep the ledger honest so the next run retries it. */
    private function markFailed(array $chunk, string $message): void
    {
        foreach ($chunk as [$sid, $wire, $event]) {
            if ($event === null) { continue; }
            $this->db->prepare(
                "INSERT INTO kreckEventLedger (sourceID, kind, eventDate, contentHash, status, message) VALUES (:s,:k,:d,:h,'Failed',:m)
                 ON DUPLICATE KEY UPDATE status='Failed', message=VALUES(message)"
            )->execute(['s' => $sid, 'k' => (string) ($event['kind'] ?? ''), 'd' => $event['_date'] ?? null, 'h' => self::hash($event), 'm' => mb_substr($message, 0, 250)]);
        }
    }

    // ------------------------------------------------------------------ status for the UI

    /** Ledger counts + last runs, for the Kreck Home status line. */
    public static function status($db): array
    {
        self::ensureTables($db);
        $counts = ['Synced' => 0, 'Pending' => 0, 'Skipped' => 0, 'Failed' => 0];
        foreach ($db->query("SELECT status, COUNT(*) n FROM kreckEventLedger GROUP BY status")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $last = $db->query("SELECT started, finished, `trigger`, ok, summary FROM kreckEventRun ORDER BY kreckEventRunID DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC) ?: null;
        $lastOk = $db->query("SELECT finished FROM kreckEventRun WHERE ok='Y' ORDER BY kreckEventRunID DESC LIMIT 1")->fetchColumn() ?: null;
        return ['counts' => $counts, 'last' => $last, 'lastOk' => $lastOk];
    }
}
