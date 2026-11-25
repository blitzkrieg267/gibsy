<?php
/*
Kreck Bridge — ManifestBuilder.

Reads the SMS SIS (read-only) for one school year and produces the JSON
manifest consumed by the LMS-side local_kreck onboarding engine
(public/local/kreck/classes/onboarding/manifest.php). Every value is chosen so
the manifest imports cleanly into LMS and is immediately usable — missing
data is filled with meaningful defaults, never blanks/NA.

Mapping (SMS -> manifest):
  gibbonPerson (role Student)            -> students[]  (username = studentID)
  gibbonPerson (category Staff)          -> users[]     (staff accounts)
  gibbonFormGroup                        -> cohorts[]   (idnumber = FG-<nameShort>)
  gibbonCourseClass (of gibbonCourse)    -> courses[]   (one LMS course per class)
  SMS timetable rows/dates             -> courses[].schedule
  KRECK LMS assignment plans               -> courses[].assignments
  gibbonCourseClassPerson                -> enrolments[]

No namespace (SMS modules are not PSR-4 autoloaded); required directly.
*/

class KreckManifestBuilder
{
    /** @var \PDO The raw PDO handle SMS exposes as $connection2. */
    private $db;
    /** @var array scope='Kreck Bridge' settings: defaultPassword, forcePasswordChange, schoolDomain */
    private $settings;

    public function __construct($connection2, array $settings)
    {
        $this->db = $connection2;
        $this->settings = $settings;
    }

    private function setting(string $key, string $default = ''): string
    {
        return isset($this->settings[$key]) && $this->settings[$key] !== ''
            ? (string) $this->settings[$key] : $default;
    }

    private function rows(string $sql, array $bind = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function one(string $sql, array $bind = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public static function slug(string $v): string
    {
        $v = preg_replace('/[^A-Za-z0-9]+/', '-', $v);
        return trim($v, '-');
    }

    private function schoolSlug(): string
    {
        return self::slug($this->setting('schoolDomain', 'school.invalid')) ?: 'school-invalid';
    }

    /** The current (or requested) school year. */
    public function schoolYear($gibbonSchoolYearID = null): ?array
    {
        if (!empty($gibbonSchoolYearID)) {
            return $this->one("SELECT gibbonSchoolYearID, name, firstDay, lastDay FROM gibbonSchoolYear WHERE gibbonSchoolYearID=:id", ['id' => $gibbonSchoolYearID]);
        }
        return $this->one("SELECT gibbonSchoolYearID, name, firstDay, lastDay FROM gibbonSchoolYear WHERE status='Current' LIMIT 1");
    }

    public function schoolYears(): array
    {
        return $this->rows("SELECT gibbonSchoolYearID, name, status FROM gibbonSchoolYear ORDER BY sequenceNumber DESC");
    }

    private function roleName(string $gibbonRole): string
    {
        switch ($gibbonRole) {
            case 'Teacher':   return 'editingteacher';
            case 'Assistant': return 'teacher';
            case 'Technician':return 'teacher';
            case 'Student':   return 'student';
            default:          return 'student';
        }
    }

    /** Compact year tag for shortnames, e.g. "2025-2026" -> "2526". */
    public function syTag(string $syName): string
    {
        if (preg_match_all('/\d{2}(\d{2})/', $syName, $m) && !empty($m[1])) {
            return implode('', $m[1]);
        }
        return self::slug($syName);
    }

    private function studentEmail(array $s): string
    {
        if (!empty($s['email'])) return $s['email'];
        return strtolower(self::slug($this->studentUsername($s))) . '@students.' . $this->schoolSlug();
    }

    private function staffEmail(array $u): string
    {
        if (!empty($u['email'])) return $u['email'];
        $id = !empty($u['username']) ? $u['username'] : ('staff' . $u['gibbonPersonID']);
        return strtolower(self::slug($id)) . '@staff.' . $this->schoolSlug();
    }

    public function studentUsername(array $s): string
    {
        // studentID is the designated cross-system join key. When the SMS
        // record has no studentID (as in the demo data), fall back to a stable,
        // reversible token built from the SMS personID (GIB<id>) so the
        // return grade-leg can still resolve the person.
        return !empty($s['studentID']) ? $s['studentID'] : ('GIB' . (int) $s['gibbonPersonID']);
    }

    public function staffUsername(array $u): string
    {
        return !empty($u['username']) ? $u['username'] : ('staff' . $u['gibbonPersonID']);
    }

    /** Deterministic username for a parent/guardian row (keyed on adultID). */
    public function parentUsername(array $p): string
    {
        return !empty($p['username']) ? $p['username'] : ('parent' . (int) $p['adultID']);
    }

    private function parentEmail(array $p): string
    {
        if (!empty($p['email'])) return $p['email'];
        $id = !empty($p['username']) ? $p['username'] : ('parent' . (int) $p['adultID']);
        return strtolower(self::slug($id)) . '@parents.' . $this->schoolSlug();
    }

    private function cohortIdnumber(?string $formGroupShort, ?string $yearGroup, string $syShort): string
    {
        if (!empty($formGroupShort)) return 'FG-' . self::slug($formGroupShort);
        if (!empty($yearGroup))      return 'YG-' . self::slug($yearGroup);
        return 'Unassigned-' . $syShort;
    }

    /**
     * Build the full manifest array for one school year.
     * @param array $opts ['gibbonSchoolYearID' => id, 'limitClasses' => int|null]
     */
    public function build(array $opts = []): array
    {
        $sy = $this->schoolYear($opts['gibbonSchoolYearID'] ?? null);
        if (!$sy) {
            return ['_error' => 'No school year found.'];
        }
        $syid    = $sy['gibbonSchoolYearID'];
        $syName  = $sy['name'];
        $syShort = self::slug($syName);
        $syTag   = $this->syTag($syName);
        $firstDay = !empty($sy['firstDay']) ? $sy['firstDay'] : date('Y-m-d');
        $lastDay = !empty($sy['lastDay']) ? $sy['lastDay'] : null;
        $limitClasses = isset($opts['limitClasses']) && (int)$opts['limitClasses'] > 0 ? (int)$opts['limitClasses'] : null;

        // ---- Students (role 003), with form/year group for the year ---------
        $studentRows = $this->rows(
            "SELECT p.gibbonPersonID, p.username, p.studentID, p.firstName, p.preferredName, p.surname, p.email,
                    fg.name AS formGroup, fg.nameShort AS formGroupShort, yg.name AS yearGroup
             FROM gibbonPerson p
             JOIN gibbonStudentEnrolment se
                  ON se.gibbonPersonID = p.gibbonPersonID AND se.gibbonSchoolYearID = :syid
             LEFT JOIN gibbonFormGroup fg ON fg.gibbonFormGroupID = se.gibbonFormGroupID
             LEFT JOIN gibbonYearGroup yg ON yg.gibbonYearGroupID = se.gibbonYearGroupID
             WHERE p.gibbonRoleIDPrimary = '003' AND p.status = 'Full'
             ORDER BY p.surname, p.firstName",
            ['syid' => $syid]
        );

        $students = [];
        $studentUserById = []; // gibbonPersonID => manifest username (for enrolments)
        $studentLinks = [];    // studentid => deep-link back to the SMS student profile
        foreach ($studentRows as $s) {
            $raw = $this->studentUsername($s);          // original case -> join key
            $username = strtolower($raw);               // LMS requires lowercase usernames
            $studentUserById[$s['gibbonPersonID']] = $username;
            $pid = (int) $s['gibbonPersonID'];
            $studentLinks[$raw] = [
                'gibbonPersonID' => $pid,
                'url' => '/index.php?q=/modules/Students/student_view_details.php&gibbonPersonID=' . $pid,
            ];
            $students[] = [
                'source_id' => 'gibbonPerson:' . $pid,
                'username'  => $username,
                'studentid' => $raw, // the cross-system join key (case preserved)
                'firstname' => $s['preferredName'] ?: $s['firstName'] ?: 'Student',
                'lastname'  => $s['surname'] ?: 'Learner',
                'email'     => $this->studentEmail($s),
                'cohort'    => $this->cohortIdnumber($s['formGroupShort'] ?? null, $s['yearGroup'] ?? null, $syShort),
            ];
        }

        // ---- Staff (category Staff) -> users[] ------------------------------
        $staffRows = $this->rows(
            "SELECT DISTINCT p.gibbonPersonID, p.username, p.firstName, p.preferredName, p.surname, p.email
             FROM gibbonPerson p
             JOIN gibbonRole r ON r.gibbonRoleID = p.gibbonRoleIDPrimary
             WHERE r.category = 'Staff' AND p.status = 'Full'
             ORDER BY p.surname, p.firstName"
        );
        $users = [];
        $staffUserById = [];
        foreach ($staffRows as $u) {
            $username = strtolower($this->staffUsername($u)); // LMS requires lowercase
            $staffUserById[$u['gibbonPersonID']] = $username;
            $users[] = [
                'source_id' => 'gibbonPerson:' . (int) $u['gibbonPersonID'],
                'username'  => $username,
                'firstname' => $u['preferredName'] ?: $u['firstName'] ?: 'Staff',
                'lastname'  => $u['surname'] ?: 'Member',
                'email'     => $this->staffEmail($u),
            ];
        }

        // ---- Parents (family adults with child-data access) -> parents[] ----
        // The LMS "parent mirror" creates these accounts and assigns a parent
        // role in each linked child's user context so the parent can see that
        // child's KRECK summary + fee balance. Only parents of an exported
        // student (role 003, Full, enrolled this year) are emitted, and only
        // when the family link grants childDataAccess='Y' (academics + fees),
        // mirroring the SMS-side rule. children[] carries the student join keys
        // (the same `studentid` the LMS stores), so the LMS resolves each child
        // to its Moodle user without guessing.
        $parentRows = $this->rows(
            "SELECT a.gibbonPersonID AS adultID, a.username, a.firstName, a.preferredName, a.surname, a.email,
                    fa.contactPriority,
                    c.gibbonPersonID AS childPID, c.studentID AS childStudentID
             FROM gibbonFamilyChild fc
             JOIN gibbonFamilyAdult fa
                  ON fa.gibbonFamilyID = fc.gibbonFamilyID AND fa.childDataAccess = 'Y'
             JOIN gibbonPerson a ON a.gibbonPersonID = fa.gibbonPersonID AND a.status = 'Full'
             JOIN gibbonPerson c ON c.gibbonPersonID = fc.gibbonPersonID
             JOIN gibbonStudentEnrolment se
                  ON se.gibbonPersonID = c.gibbonPersonID AND se.gibbonSchoolYearID = :syid
             WHERE c.gibbonRoleIDPrimary = '003' AND c.status = 'Full'
             ORDER BY a.surname, a.firstName, c.surname",
            ['syid' => $syid]
        );
        $parentsByAdult = [];
        foreach ($parentRows as $r) {
            $aid = (int) $r['adultID'];
            if (!isset($parentsByAdult[$aid])) {
                $parentsByAdult[$aid] = [
                    'source_id'       => 'gibbonPerson:' . $aid,
                    'username'        => strtolower($this->parentUsername($r)),
                    'firstname'       => $r['preferredName'] ?: $r['firstName'] ?: 'Parent',
                    'lastname'        => $r['surname'] ?: 'Guardian',
                    'email'           => $this->parentEmail($r),
                    'contactpriority' => 9,
                    'children'        => [],
                    '_seen'           => [],
                ];
            }
            // Child join key: the student's manifest studentid (same rule as students[]).
            $joinKey = !empty($r['childStudentID']) ? $r['childStudentID'] : ('GIB' . (int) $r['childPID']);
            if (!isset($parentsByAdult[$aid]['_seen'][$joinKey])) {
                $parentsByAdult[$aid]['_seen'][$joinKey] = true;
                $parentsByAdult[$aid]['children'][] = (string) $joinKey;
            }
            // Keep the strongest (lowest) contact priority seen for this parent.
            $cp = (int) ($r['contactPriority'] ?? 0);
            if ($cp > 0 && $cp < $parentsByAdult[$aid]['contactpriority']) {
                $parentsByAdult[$aid]['contactpriority'] = $cp;
            }
        }
        $parents = [];
        foreach ($parentsByAdult as $p) {
            unset($p['_seen']);
            if ($p['contactpriority'] === 9) { $p['contactpriority'] = 1; }
            if (!empty($p['children'])) {
                $parents[] = $p;
            }
        }

        // ---- Form groups -> cohorts[] ---------------------------------------
        $fgRows = $this->rows(
            "SELECT gibbonFormGroupID, name, nameShort FROM gibbonFormGroup WHERE gibbonSchoolYearID = :syid ORDER BY name",
            ['syid' => $syid]
        );
        $cohorts = [];
        $cohortSeen = [];
        $cohortSourceIds = [];
        foreach ($fgRows as $fg) {
            $idn = 'FG-' . self::slug($fg['nameShort'] ?: $fg['name']);
            if (isset($cohortSeen[$idn])) continue;
            $cohortSeen[$idn] = true;
            $cohortSourceIds[$idn] = 'gibbonFormGroup:' . (int) $fg['gibbonFormGroupID'];
            $cohorts[] = [
                'name' => $fg['name'],
                'idnumber' => $idn,
                'source_id' => $cohortSourceIds[$idn],
            ];
        }
        // Ensure any cohort referenced by a student exists (incl. the Unassigned fallback).
        foreach ($students as $s) {
            $idn = $s['cohort'];
            if (!isset($cohortSeen[$idn])) {
                $cohortSeen[$idn] = true;
                $cohortSourceIds[$idn] = 'derived-cohort:' . $idn;
                $cohorts[] = [
                    'name' => str_replace(['FG-', 'YG-'], ['Form ', 'Year '], $idn),
                    'idnumber' => $idn,
                    'source_id' => $cohortSourceIds[$idn],
                ];
            }
        }

        // ---- Courses: one LMS course per gibbonCourseClass ---------------
        $classSql =
            "SELECT cc.gibbonCourseClassID, cc.name AS className, cc.nameShort AS classShort,
                    c.name AS courseName, c.nameShort AS courseShort,
                    d.gibbonDepartmentID AS deptID, d.name AS deptName
             FROM gibbonCourseClass cc
             JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID AND c.gibbonSchoolYearID = :syid
             LEFT JOIN gibbonDepartment d ON d.gibbonDepartmentID = c.gibbonDepartmentID
             ORDER BY c.name, cc.name";
        if ($limitClasses) $classSql .= " LIMIT " . $limitClasses;
        $classRows = $this->rows($classSql, ['syid' => $syid]);

        $scheduleByClass = [];
        if ($lastDay !== null && !empty($classRows)) {
            $scheduleRows = $this->rows(
                "SELECT rowClass.gibbonCourseClassID, dayDate.date, day.name AS dayName,
                        period.name AS periodName, period.timeStart, period.timeEnd, space.name AS room
                 FROM gibbonTTDayRowClass rowClass
                 JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = rowClass.gibbonCourseClassID
                 JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID AND c.gibbonSchoolYearID = :courseYear
                 JOIN gibbonTTDay day ON day.gibbonTTDayID = rowClass.gibbonTTDayID
                 JOIN gibbonTT timetable ON timetable.gibbonTTID = day.gibbonTTID AND timetable.gibbonSchoolYearID = :tableYear
                 JOIN gibbonTTDayDate dayDate ON dayDate.gibbonTTDayID = day.gibbonTTDayID
                 JOIN gibbonTTColumnRow period ON period.gibbonTTColumnRowID = rowClass.gibbonTTColumnRowID
                 LEFT JOIN gibbonSpace space ON space.gibbonSpaceID = rowClass.gibbonSpaceID
                 WHERE dayDate.date BETWEEN :firstDay AND :lastDay
                 ORDER BY dayDate.date, period.timeStart, cc.name, period.name",
                ['courseYear' => $syid, 'tableYear' => $syid, 'firstDay' => $firstDay, 'lastDay' => $lastDay]
            );
            foreach ($scheduleRows as $schedule) {
                $scheduleByClass[(int) $schedule['gibbonCourseClassID']][] = [
                    'date' => $schedule['date'],
                    'day' => $schedule['dayName'],
                    'period' => $schedule['periodName'],
                    'start_time' => $schedule['timeStart'],
                    'end_time' => $schedule['timeEnd'],
                    'room' => $schedule['room'] ?: '',
                ];
            }
        }

        $assignmentsByClass = [];
        if (!empty($classRows)) {
            $assignmentRows = $this->rows(
                "SELECT plan.gibbonCourseClassID, plan.sourceRef, plan.sectionNumber, plan.activityName,
                        plan.description, plan.openDate, plan.openTime, plan.dueDate, plan.dueTime,
                        plan.cutoffDate, plan.cutoffTime, plan.gradeMax
                 FROM kreckLmsAssignmentPlan plan
                 JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = plan.gibbonCourseClassID
                 JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
                 WHERE c.gibbonSchoolYearID = :courseYear AND plan.gibbonSchoolYearID = :planYear AND plan.active = 'Y'
                 ORDER BY plan.gibbonCourseClassID, plan.sectionNumber, plan.activityName",
                ['courseYear' => $syid, 'planYear' => $syid]
            );
            foreach ($assignmentRows as $assignment) {
                $classId = (int) $assignment['gibbonCourseClassID'];
                $description = trim((string) ($assignment['description'] ?? ''));
                $assignmentsByClass[$classId][] = [
                    'source_ref' => (string) $assignment['sourceRef'],
                    'section_num' => (int) $assignment['sectionNumber'],
                    'type' => 'assign',
                    'name' => (string) $assignment['activityName'],
                    'intro' => $description === '' ? '' : nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                    'open_date' => $assignment['openDate'],
                    'open_time' => $assignment['openTime'],
                    'due_date' => $assignment['dueDate'],
                    'due_time' => $assignment['dueTime'],
                    'cutoff_date' => $assignment['cutoffDate'],
                    'cutoff_time' => $assignment['cutoffTime'],
                    'grade' => (float) $assignment['gradeMax'],
                ];
            }
        }

        $courses = [];
        $courseShortByClassId = [];
        $shortSeen = [];
        $courseLinks = [];      // shortname => deep-link back to the SMS class (Planner view)
        $categorySeen = [];
        $categories = [];
        foreach ($classRows as $c) {
            $dept = $c['deptName'] ?: 'General';
            $categoryPath = $syName . ' / ' . $dept;
            if (!isset($categorySeen[$categoryPath])) {
                $categorySeen[$categoryPath] = true;
                $categories[] = [
                    'name' => $dept,
                    'category_path' => $categoryPath,
                    'source_id' => 'gibbonSchoolYear:' . (int) $syid . ':department:' . (int) ($c['deptID'] ?? 0),
                ];
            }
            // shortname from the meaningful course code + class section, year-tagged.
            $short = $this->classShortname($c, $syTag, $shortSeen);
            $courseShortByClassId[$c['gibbonCourseClassID']] = $short;
            $ccid = (int) $c['gibbonCourseClassID'];
            $courseLinks[$short] = [
                'gibbonCourseClassID' => $ccid,
                'url' => '/index.php?q=/modules/Planner/planner.php&viewBy=class&gibbonCourseClassID=' . $ccid,
            ];

            // Readable full name; class sections are usually bare numbers.
            $classLabel = ($c['className'] !== '' && $c['className'] !== null)
                ? (is_numeric($c['className']) ? 'Class ' . $c['className'] : $c['className'])
                : '';
            $courses[] = [
                'source_id'     => 'gibbonCourseClass:' . $ccid,
                'fullname'      => trim(($c['courseName'] ?: 'Course') . ($classLabel ? ' · ' . $classLabel : '')),
                'shortname'     => $short,
                'category_path' => $categoryPath,
                'numsections'   => 12,
                'startdate'     => $firstDay,
                'enddate'       => $lastDay,
                'schedule'      => $scheduleByClass[$ccid] ?? [],
                'assignments'   => $assignmentsByClass[$ccid] ?? [],
                'summary'       => 'Imported from KRECK SMS for ' . $syName . '.',
            ];
        }
        unset($courseShortByClassId['__shorts']);

        // ---- Enrolments: class memberships ----------------------------------
        $enrolRows = $this->rows(
            "SELECT ccp.gibbonCourseClassID, ccp.gibbonPersonID, ccp.role
             FROM gibbonCourseClassPerson ccp
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = ccp.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID AND c.gibbonSchoolYearID = :syid
             WHERE ccp.role IN ('Student','Teacher','Assistant','Technician')",
            ['syid' => $syid]
        );
        $enrolments = [];
        foreach ($enrolRows as $e) {
            $classId = $e['gibbonCourseClassID'];
            if (!isset($courseShortByClassId[$classId])) continue; // class not in exported set (limit)
            $pid = $e['gibbonPersonID'];
            $isStudent = ($e['role'] === 'Student');
            if ($isStudent && isset($studentUserById[$pid])) {
                $user = $studentUserById[$pid];
            } elseif (!$isStudent && isset($staffUserById[$pid])) {
                $user = $staffUserById[$pid];
            } else {
                continue; // person not in exported students/staff (e.g. inactive)
            }
            $enrolments[] = [
                'source_id' => 'gibbonCourseClassPerson:' . $classId . ':' . $pid . ':' . $e['role'],
                'user'   => $user,
                'course' => $courseShortByClassId[$classId],
                'role'   => $this->roleName($e['role']),
            ];
        }

        // ---- Assemble manifest ---------------------------------------------
        $manifest = [
            'schema_version' => '1.0',
            'batch_metadata' => [
                'version'     => '1.0',
                'manifest_id' => bin2hex(random_bytes(16)),
                'environment' => 'gibbon-export',
                'source'      => 'Kreck Bridge',
                'schoolyear'  => $syName,
                'generated'   => date('c'),
                'timezone'    => $this->setting('siteTimezone', date_default_timezone_get()),
                'notes'       => 'Generated by the Kreck Bridge module from the SMS SIS. Timetable and active assignment plans are included when configured.',
            ],
            'defaultpassword'     => ($this->setting('defaultPassword', '') ?: ('Kr' . strtoupper(bin2hex(random_bytes(3))) . bin2hex(random_bytes(4)) . '#9a')),
            'forcepasswordchange' => strtoupper($this->setting('forcePasswordChange', 'Y')) === 'Y',
            'categories'          => $categories,
            'users'               => $users,
            'cohorts'             => $cohorts,
            'students'            => $students,
            'parents'             => $parents,
            'courses'             => $courses,
            'enrolments'          => $enrolments,
            // Relative SMS (KRECK SMS) deep-links, consumed by the LMS plugin
            // so it can place "View in KRECK SMS" links. Prefix with the SMS base URL.
            'links'               => [
                'base_hint' => 'gibbon',
                'courses'   => $courseLinks,
                'students'  => $studentLinks,
            ],
        ];

        return $manifest;
    }

    /** Readiness summary (counts + data-quality warnings) for the wizard. */
    /**
     * The LMS course shortname for a class row (the single rule used by the manifest AND by the event
     * mirror, so an event always finds the course the manifest created).
     *
     * @param array $c row with courseShort, courseName, classShort, className, gibbonCourseClassID
     * @param string $syTag year tag, e.g. "2526"
     * @param array $seen shortnames already issued (by reference) — guarantees uniqueness
     */
    public function classShortname(array $c, string $syTag, array &$seen): string
    {
        $courseCode = $c['courseShort'] ?: self::slug($c['courseName']);
        $classCode  = ($c['classShort'] !== '' && $c['classShort'] !== null) ? $c['classShort']
                    : (($c['className'] !== '' && $c['className'] !== null) ? $c['className'] : $c['gibbonCourseClassID']);
        $short = strtoupper(self::slug($syTag . '-' . $courseCode . '-' . $classCode));
        // Guarantee global-ish uniqueness of shortname.
        if (isset($seen[$short])) {
            $short .= '-' . (int) $c['gibbonCourseClassID'];
        }
        $seen[$short] = true;
        return $short;
    }

    /**
     * Every class of a school year with its LMS shortname, in the same order and with the same
     * collision handling as build(): [gibbonCourseClassID => ['short', 'course', 'class', 'courseID', 'yearGroups']].
     */
    public function classCatalog($gibbonSchoolYearID = null): array
    {
        $sy = $this->schoolYear($gibbonSchoolYearID);
        if (!$sy) {
            return [];
        }
        $syid = $sy['gibbonSchoolYearID'];
        $syTag = $this->syTag((string) $sy['name']);
        $rows = $this->rows(
            "SELECT cc.gibbonCourseClassID, cc.name AS className, cc.nameShort AS classShort,
                    c.gibbonCourseID, c.name AS courseName, c.nameShort AS courseShort, c.gibbonYearGroupIDList
             FROM gibbonCourseClass cc
             JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID AND c.gibbonSchoolYearID = :syid
             LEFT JOIN gibbonDepartment d ON d.gibbonDepartmentID = c.gibbonDepartmentID
             ORDER BY c.name, cc.name",
            ['syid' => $syid]
        );
        $seen = [];
        $out = [];
        foreach ($rows as $c) {
            $classLabel = ($c['className'] !== '' && $c['className'] !== null)
                ? (is_numeric($c['className']) ? 'Class ' . $c['className'] : $c['className']) : '';
            $out[(int) $c['gibbonCourseClassID']] = [
                'short'      => $this->classShortname($c, $syTag, $seen),
                'course'     => (string) ($c['courseName'] ?: 'Course'),
                'class'      => $classLabel,
                'courseID'   => (int) $c['gibbonCourseID'],
                'yearGroups' => array_values(array_filter(array_map('trim', explode(',', (string) $c['gibbonYearGroupIDList'])))),
            ];
        }
        return $out;
    }

    public function readiness($gibbonSchoolYearID = null): array
    {
        $sy = $this->schoolYear($gibbonSchoolYearID);
        if (!$sy) return ['error' => 'No school year found.'];
        $syid = $sy['gibbonSchoolYearID'];

        $students = (int) ($this->one("SELECT COUNT(*) c FROM gibbonPerson p JOIN gibbonStudentEnrolment se ON se.gibbonPersonID=p.gibbonPersonID AND se.gibbonSchoolYearID=:syid WHERE p.gibbonRoleIDPrimary='003' AND p.status='Full'", ['syid' => $syid])['c'] ?? 0);
        $staff    = (int) ($this->one("SELECT COUNT(DISTINCT p.gibbonPersonID) c FROM gibbonPerson p JOIN gibbonRole r ON r.gibbonRoleID=p.gibbonRoleIDPrimary WHERE r.category='Staff' AND p.status='Full'")['c'] ?? 0);
        $formGroups = (int) ($this->one("SELECT COUNT(*) c FROM gibbonFormGroup WHERE gibbonSchoolYearID=:syid", ['syid' => $syid])['c'] ?? 0);
        $classes  = (int) ($this->one("SELECT COUNT(*) c FROM gibbonCourseClass cc JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID AND c.gibbonSchoolYearID=:syid", ['syid' => $syid])['c'] ?? 0);
        $enrol    = (int) ($this->one("SELECT COUNT(*) c FROM gibbonCourseClassPerson ccp JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=ccp.gibbonCourseClassID JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID AND c.gibbonSchoolYearID=:syid", ['syid' => $syid])['c'] ?? 0);
        $scheduledSessions = (int) ($this->one(
            "SELECT COUNT(*) c
             FROM gibbonTTDayRowClass rowClass
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=rowClass.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID AND c.gibbonSchoolYearID=:courseYear
             JOIN gibbonTTDay day ON day.gibbonTTDayID=rowClass.gibbonTTDayID
             JOIN gibbonTT timetable ON timetable.gibbonTTID=day.gibbonTTID AND timetable.gibbonSchoolYearID=:tableYear
             JOIN gibbonTTDayDate dayDate ON dayDate.gibbonTTDayID=day.gibbonTTDayID
             WHERE dayDate.date BETWEEN :firstDay AND :lastDay",
            ['courseYear' => $syid, 'tableYear' => $syid, 'firstDay' => $sy['firstDay'], 'lastDay' => $sy['lastDay']]
        )['c'] ?? 0);
        $assignmentPlans = (int) ($this->one(
            "SELECT COUNT(*) c FROM kreckLmsAssignmentPlan plan
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=plan.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID AND c.gibbonSchoolYearID=:courseYear
             WHERE plan.gibbonSchoolYearID=:planYear AND plan.active='Y'",
            ['courseYear' => $syid, 'planYear' => $syid]
        )['c'] ?? 0);

        $studentsNoEmail = (int) ($this->one("SELECT COUNT(*) c FROM gibbonPerson p JOIN gibbonStudentEnrolment se ON se.gibbonPersonID=p.gibbonPersonID AND se.gibbonSchoolYearID=:syid WHERE p.gibbonRoleIDPrimary='003' AND p.status='Full' AND (p.email IS NULL OR p.email='')", ['syid' => $syid])['c'] ?? 0);
        $studentsNoForm  = (int) ($this->one("SELECT COUNT(*) c FROM gibbonPerson p JOIN gibbonStudentEnrolment se ON se.gibbonPersonID=p.gibbonPersonID AND se.gibbonSchoolYearID=:syid WHERE p.gibbonRoleIDPrimary='003' AND p.status='Full' AND (se.gibbonFormGroupID IS NULL OR se.gibbonFormGroupID=0)", ['syid' => $syid])['c'] ?? 0);

        $warnings = [];
        if ($studentsNoEmail > 0) $warnings[] = sprintf('%d student(s) have no email — a deterministic placeholder (…@students.%s) will be generated so import still works.', $studentsNoEmail, $this->schoolSlug());
        if ($studentsNoForm > 0)  $warnings[] = sprintf('%d student(s) have no form group — they will be placed in the "Unassigned" cohort.', $studentsNoForm);
        if ($classes === 0)       $warnings[] = 'This school year has no course classes to export.';
        if ($scheduledSessions === 0) $warnings[] = 'No dated timetable sessions are configured for this school year; LMS class calendar events will not be created.';

        return [
            'schoolYear' => $sy['name'],
            'counts' => compact('students', 'staff', 'formGroups', 'classes', 'enrol', 'scheduledSessions', 'assignmentPlans'),
            'warnings' => $warnings,
        ];
    }
}
