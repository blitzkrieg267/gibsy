<?php
/*
Kreck Bridge — ManifestBuilder.

Reads the Gibbon SIS (read-only) for one school year and produces the JSON
manifest consumed by the Moodle-side local_kreck onboarding engine
(public/local/kreck/classes/onboarding/manifest.php). Every value is chosen so
the manifest imports cleanly into Moodle and is immediately usable — missing
data is filled with meaningful defaults, never blanks/NA.

Mapping (Gibbon -> manifest):
  gibbonPerson (role Student)            -> students[]  (username = studentID)
  gibbonPerson (category Staff)          -> users[]     (staff accounts)
  gibbonFormGroup                        -> cohorts[]   (idnumber = FG-<nameShort>)
  gibbonCourseClass (of gibbonCourse)    -> courses[]   (one LMS course per class)
  gibbonCourseClassPerson                -> enrolments[]

No namespace (Gibbon modules are not PSR-4 autoloaded); required directly.
*/

class KreckManifestBuilder
{
    /** @var \PDO The raw PDO handle Gibbon exposes as $connection2. */
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
    private function syTag(string $syName): string
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

    private function studentUsername(array $s): string
    {
        // studentID is the designated cross-system join key. When the Gibbon
        // record has no studentID (as in the demo data), fall back to a stable,
        // reversible token built from the Gibbon personID (GIB<id>) so the
        // return grade-leg can still resolve the person.
        return !empty($s['studentID']) ? $s['studentID'] : ('GIB' . (int) $s['gibbonPersonID']);
    }

    private function staffUsername(array $u): string
    {
        return !empty($u['username']) ? $u['username'] : ('staff' . $u['gibbonPersonID']);
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
        $studentLinks = [];    // studentid => deep-link back to the Gibbon student profile
        foreach ($studentRows as $s) {
            $raw = $this->studentUsername($s);          // original case -> join key
            $username = strtolower($raw);               // Moodle requires lowercase usernames
            $studentUserById[$s['gibbonPersonID']] = $username;
            $pid = (int) $s['gibbonPersonID'];
            $studentLinks[$raw] = [
                'gibbonPersonID' => $pid,
                'url' => '/index.php?q=/modules/Students/student_view_details.php&gibbonPersonID=' . $pid,
            ];
            $students[] = [
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
            $username = strtolower($this->staffUsername($u)); // Moodle requires lowercase
            $staffUserById[$u['gibbonPersonID']] = $username;
            $users[] = [
                'username'  => $username,
                'firstname' => $u['preferredName'] ?: $u['firstName'] ?: 'Staff',
                'lastname'  => $u['surname'] ?: 'Member',
                'email'     => $this->staffEmail($u),
            ];
        }

        // ---- Form groups -> cohorts[] ---------------------------------------
        $fgRows = $this->rows(
            "SELECT name, nameShort FROM gibbonFormGroup WHERE gibbonSchoolYearID = :syid ORDER BY name",
            ['syid' => $syid]
        );
        $cohorts = [];
        $cohortSeen = [];
        foreach ($fgRows as $fg) {
            $idn = 'FG-' . self::slug($fg['nameShort'] ?: $fg['name']);
            if (isset($cohortSeen[$idn])) continue;
            $cohortSeen[$idn] = true;
            $cohorts[] = ['name' => $fg['name'], 'idnumber' => $idn];
        }
        // Ensure any cohort referenced by a student exists (incl. the Unassigned fallback).
        foreach ($students as $s) {
            $idn = $s['cohort'];
            if (!isset($cohortSeen[$idn])) {
                $cohortSeen[$idn] = true;
                $cohorts[] = ['name' => str_replace(['FG-', 'YG-'], ['Form ', 'Year '], $idn), 'idnumber' => $idn];
            }
        }

        // ---- Courses: one Moodle course per gibbonCourseClass ---------------
        $classSql =
            "SELECT cc.gibbonCourseClassID, cc.name AS className, cc.nameShort AS classShort,
                    c.name AS courseName, c.nameShort AS courseShort,
                    d.name AS deptName
             FROM gibbonCourseClass cc
             JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID AND c.gibbonSchoolYearID = :syid
             LEFT JOIN gibbonDepartment d ON d.gibbonDepartmentID = c.gibbonDepartmentID
             ORDER BY c.name, cc.name";
        if ($limitClasses) $classSql .= " LIMIT " . $limitClasses;
        $classRows = $this->rows($classSql, ['syid' => $syid]);

        $courses = [];
        $courseShortByClassId = [];
        $courseLinks = [];      // shortname => deep-link back to the Gibbon class (Planner view)
        $categorySeen = [];
        $categories = [];
        foreach ($classRows as $c) {
            $dept = $c['deptName'] ?: 'General';
            $categoryPath = $syName . ' / ' . $dept;
            if (!isset($categorySeen[$categoryPath])) {
                $categorySeen[$categoryPath] = true;
                $categories[] = ['name' => $dept, 'category_path' => $categoryPath];
            }
            // shortname from the meaningful course code + class section, year-tagged.
            $courseCode = $c['courseShort'] ?: self::slug($c['courseName']);
            $classCode  = ($c['classShort'] !== '' && $c['classShort'] !== null) ? $c['classShort']
                        : (($c['className'] !== '' && $c['className'] !== null) ? $c['className'] : $c['gibbonCourseClassID']);
            $short = strtoupper(self::slug($syTag . '-' . $courseCode . '-' . $classCode));
            // Guarantee global-ish uniqueness of shortname.
            if (isset($courseShortByClassId['__shorts'][$short])) {
                $short .= '-' . (int) $c['gibbonCourseClassID'];
            }
            $courseShortByClassId['__shorts'][$short] = true;
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
                'fullname'      => trim(($c['courseName'] ?: 'Course') . ($classLabel ? ' · ' . $classLabel : '')),
                'shortname'     => $short,
                'category_path' => $categoryPath,
                'numsections'   => 12,
                'startdate'     => $firstDay,
                'summary'       => 'Imported from KRECK SMS (Gibbon) for ' . $syName . '.',
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
                'user'   => $user,
                'course' => $courseShortByClassId[$classId],
                'role'   => $this->roleName($e['role']),
            ];
        }

        // ---- Assemble manifest ---------------------------------------------
        $manifest = [
            'batch_metadata' => [
                'version'     => '1.0',
                'environment' => 'gibbon-export',
                'source'      => 'Kreck Bridge (Gibbon)',
                'schoolyear'  => $syName,
                'generated'   => date('c'),
                'notes'       => 'Generated by the Kreck Bridge module from the Gibbon SIS. Defaults applied for Moodle import.',
            ],
            'defaultpassword'     => $this->setting('defaultPassword', 'Kreck#2026'),
            'forcepasswordchange' => strtoupper($this->setting('forcePasswordChange', 'Y')) === 'Y',
            'categories'          => $categories,
            'users'               => $users,
            'cohorts'             => $cohorts,
            'students'            => $students,
            'courses'             => $courses,
            'enrolments'          => $enrolments,
            // Relative Gibbon (KRECK SMS) deep-links, consumed by the Moodle plugin
            // so it can place "View in KRECK SMS" links. Prefix with the Gibbon base URL.
            'links'               => [
                'base_hint' => 'gibbon',
                'courses'   => $courseLinks,
                'students'  => $studentLinks,
            ],
        ];

        return $manifest;
    }

    /** Readiness summary (counts + data-quality warnings) for the wizard. */
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

        $studentsNoEmail = (int) ($this->one("SELECT COUNT(*) c FROM gibbonPerson p JOIN gibbonStudentEnrolment se ON se.gibbonPersonID=p.gibbonPersonID AND se.gibbonSchoolYearID=:syid WHERE p.gibbonRoleIDPrimary='003' AND p.status='Full' AND (p.email IS NULL OR p.email='')", ['syid' => $syid])['c'] ?? 0);
        $studentsNoForm  = (int) ($this->one("SELECT COUNT(*) c FROM gibbonPerson p JOIN gibbonStudentEnrolment se ON se.gibbonPersonID=p.gibbonPersonID AND se.gibbonSchoolYearID=:syid WHERE p.gibbonRoleIDPrimary='003' AND p.status='Full' AND (se.gibbonFormGroupID IS NULL OR se.gibbonFormGroupID=0)", ['syid' => $syid])['c'] ?? 0);

        $warnings = [];
        if ($studentsNoEmail > 0) $warnings[] = sprintf('%d student(s) have no email — a deterministic placeholder (…@students.%s) will be generated so import still works.', $studentsNoEmail, $this->schoolSlug());
        if ($studentsNoForm > 0)  $warnings[] = sprintf('%d student(s) have no form group — they will be placed in the "Unassigned" cohort.', $studentsNoForm);
        if ($classes === 0)       $warnings[] = 'This school year has no course classes to export.';

        return [
            'schoolYear' => $sy['name'],
            'counts' => compact('students', 'staff', 'formGroups', 'classes', 'enrol'),
            'warnings' => $warnings,
        ];
    }
}
