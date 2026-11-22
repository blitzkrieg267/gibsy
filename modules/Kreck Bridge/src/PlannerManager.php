<?php
/*
Kreck Bridge — PlannerManager.

Thin DB helper for the KRECK "Add a lesson" stepped form. Writes a normal
gibbonPlannerEntry (the same record Gibbon's own Planner/calendar reads), so a
KRECK-created lesson shows up on the SMS calendar exactly like a native one.
No Gibbon core is changed; this only inserts the standard record and resolves a
class's timetable slot for a date so the time can be prefilled.

No namespace (SMS modules are not PSR-4 autoloaded); required directly.
*/

use Gibbon\Contracts\Database\Connection;

class KreckPlannerManager
{
    /** @var \PDO */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db->getConnection();
    }

    /**
     * Classes for the searchable picker. For a teacher, only the classes they
     * teach in the current year; for broader roles, every class in the year.
     * Returns id, course/class short names and the course name.
     */
    public function listClasses(string $gibbonSchoolYearID, ?int $gibbonPersonID, bool $allClasses): array
    {
        if ($allClasses || !$gibbonPersonID) {
            $sql = "SELECT cc.gibbonCourseClassID AS id, c.nameShort AS course, cc.nameShort AS class, c.name AS courseName
                    FROM gibbonCourseClass cc
                    JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
                    WHERE c.gibbonSchoolYearID=:syid
                    ORDER BY c.nameShort, cc.nameShort";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['syid' => $gibbonSchoolYearID]);
        } else {
            $sql = "SELECT cc.gibbonCourseClassID AS id, c.nameShort AS course, cc.nameShort AS class, c.name AS courseName
                    FROM gibbonCourseClassPerson ccp
                    JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=ccp.gibbonCourseClassID
                    JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
                    WHERE c.gibbonSchoolYearID=:syid AND ccp.gibbonPersonID=:pid AND ccp.role LIKE 'Teacher%'
                    ORDER BY c.nameShort, cc.nameShort";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['syid' => $gibbonSchoolYearID, 'pid' => $gibbonPersonID]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** True if the class exists in the given school year (validate the picked id). */
    public function classInYear(int $gibbonCourseClassID, string $gibbonSchoolYearID): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM gibbonCourseClass cc JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             WHERE cc.gibbonCourseClassID=:id AND c.gibbonSchoolYearID=:syid"
        );
        $stmt->execute(['id' => $gibbonCourseClassID, 'syid' => $gibbonSchoolYearID]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * The class's timetable slot on a given date (if any), for prefilling the
     * lesson time and linking the entry to its timetable row. Returns
     * ['gibbonTTDayRowClassID','timeStart','timeEnd'] or null.
     */
    public function classSlotForDate(int $gibbonCourseClassID, string $date): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT drc.gibbonTTDayRowClassID, cr.timeStart, cr.timeEnd
             FROM gibbonTTDayDate dd
             JOIN gibbonTTDay d ON d.gibbonTTDayID=dd.gibbonTTDayID
             JOIN gibbonTTDayRowClass drc ON drc.gibbonTTDayID=d.gibbonTTDayID AND drc.gibbonCourseClassID=:ccid
             JOIN gibbonTTColumnRow cr ON cr.gibbonTTColumnRowID=drc.gibbonTTColumnRowID
             WHERE dd.date=:date
             ORDER BY cr.timeStart LIMIT 1"
        );
        $stmt->execute(['ccid' => $gibbonCourseClassID, 'date' => $date]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Display label for one class. */
    public function classLabel(int $gibbonCourseClassID): string
    {
        $stmt = $this->db->prepare(
            "SELECT CONCAT(c.nameShort,'.',cc.nameShort,' — ',c.name) FROM gibbonCourseClass cc
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID WHERE cc.gibbonCourseClassID=:id"
        );
        $stmt->execute(['id' => $gibbonCourseClassID]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /**
     * Create a standard gibbonPlannerEntry (a calendar lesson/event). Every
     * NOT-NULL column without a DB default is supplied; homework is off by
     * default. Returns the new gibbonPlannerEntryID.
     */
    public function createEntry(array $d, int $userID): int
    {
        // gibbonTTDayRowClassID (links the lesson to its timetable slot) only
        // exists on newer SMS schemas — include it only when the column is
        // present, so this works across supported SMS versions.
        $slot = (!empty($d['gibbonTTDayRowClassID'])) ? (int) $d['gibbonTTDayRowClassID'] : null;
        $hasSlotColumn = $this->columnExists('gibbonPlannerEntry', 'gibbonTTDayRowClassID');
        $slotClause = $hasSlotColumn ? 'gibbonTTDayRowClassID=:ttrc,' : '';
        $sql = "INSERT INTO gibbonPlannerEntry SET
                  gibbonCourseClassID=:ccid, date=:date, timeStart=:ts, timeEnd=:te,
                  {$slotClause}
                  name=:name, summary=:summary, description=:description, teachersNotes='',
                  homework='N', homeworkDetails='', homeworkSubmission='N', homeworkSubmissionType='',
                  homeworkCrowdAssess='N',
                  homeworkCrowdAssessOtherTeachersRead='N', homeworkCrowdAssessOtherParentsRead='N',
                  homeworkCrowdAssessClassmatesParentsRead='N', homeworkCrowdAssessSubmitterParentsRead='N',
                  homeworkCrowdAssessOtherStudentsRead='N', homeworkCrowdAssessClassmatesRead='N',
                  viewableParents=:vp, viewableStudents=:vs,
                  gibbonPersonIDCreator=:creator, gibbonPersonIDLastEdit=:creator";
        $params = [
            'ccid'        => $d['gibbonCourseClassID'],
            'date'        => $d['date'],
            'ts'          => $d['timeStart'],
            'te'          => $d['timeEnd'],
            'name'        => mb_substr((string) $d['name'], 0, 50),
            'summary'     => mb_substr((string) ($d['summary'] ?? ''), 0, 255),
            'description' => (string) ($d['description'] ?? ''),
            'vp'          => ($d['viewableParents'] ?? 'Y') === 'N' ? 'N' : 'Y',
            'vs'          => ($d['viewableStudents'] ?? 'Y') === 'N' ? 'N' : 'Y',
            'creator'     => $userID ?: null,
        ];
        if ($hasSlotColumn) {
            $params['ttrc'] = $slot;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $this->db->lastInsertId();
    }

    /** Whether a column exists on a table (for cross-version schema safety). */
    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c"
            );
            $stmt->execute(['t' => $table, 'c' => $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
