<?php
/*
Kreck Bridge — KreckLinkStore.

Stores and queries the return-leg data from the Kreck LMS (Moodle):
  - kreckLink : deep-links back into Moodle for each provisioned class
                (course) and its activities, so Gibbon can offer
                "Open in KRECK LMS" links.
  - kreckMark : a flat record of Moodle gradebook marks keyed by the shared
                studentID + course shortname, kept on the school record.

Links are stored RELATIVE; the Moodle base URL (gibbonSetting
'Kreck Bridge'.moodleEndpoint) is prepended at display time, so moving the
LMS never invalidates the stored data.

No namespace (Gibbon modules are not PSR-4 autoloaded); required directly.
Uses the raw PDO handle Gibbon exposes as $connection2, matching ManifestBuilder.
*/

class KreckLinkStore
{
    /** @var \PDO */
    private $db;

    public function __construct($connection2)
    {
        $this->db = $connection2;
    }

    private function rows(string $sql, array $bind = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Import a decoded links.moodle payload (from local/kreck/export_links.php).
     * Upserts one 'course' row per class and one 'activity' row per module.
     *
     * @param array $payload  Decoded JSON: {source, courses:[{shortname,moodle_courseid,relurl,activities:[...]}]}
     * @return array ['courses'=>int,'activities'=>int,'errors'=>string[]]
     */
    public function importMoodleLinks(array $payload): array
    {
        $courses = $payload['courses'] ?? [];
        if (!is_array($courses)) {
            return ['courses' => 0, 'activities' => 0, 'errors' => ['No "courses" array in file.']];
        }

        $upsert = $this->db->prepare(
            "INSERT INTO kreckLink
               (entityType, entityKey, subKey, moodleID, modName, moodleRelURL, label, timestampUpdated)
             VALUES (:entityType, :entityKey, :subKey, :moodleID, :modName, :moodleRelURL, :label, NOW())
             ON DUPLICATE KEY UPDATE
               moodleID=VALUES(moodleID), modName=VALUES(modName),
               label=VALUES(label), timestampUpdated=NOW()"
        );

        $cN = 0; $aN = 0; $errors = [];
        foreach ($courses as $c) {
            $shortname = trim((string) ($c['shortname'] ?? ''));
            if ($shortname === '') {
                $errors[] = 'Skipped a course with no shortname.';
                continue;
            }
            $courserel = (string) ($c['relurl'] ?? ('/course/view.php?id=' . (int) ($c['moodle_courseid'] ?? 0)));
            try {
                $upsert->execute([
                    ':entityType'  => 'course',
                    ':entityKey'   => $shortname,
                    ':subKey'      => null,
                    ':moodleID'    => (int) ($c['moodle_courseid'] ?? 0) ?: null,
                    ':modName'     => null,
                    ':moodleRelURL' => $courserel,
                    ':label'       => $shortname,
                ]);
                $cN++;
            } catch (\Throwable $e) {
                $errors[] = 'Course ' . $shortname . ': ' . $e->getMessage();
                continue;
            }

            foreach ((array) ($c['activities'] ?? []) as $a) {
                $cmid = (int) ($a['cmid'] ?? 0);
                $arel = (string) ($a['relurl'] ?? '');
                if ($arel === '') {
                    continue;
                }
                try {
                    $upsert->execute([
                        ':entityType'  => 'activity',
                        ':entityKey'   => $shortname,
                        ':subKey'      => (string) $cmid,
                        ':moodleID'    => $cmid ?: null,
                        ':modName'     => (string) ($a['modname'] ?? ''),
                        ':moodleRelURL' => $arel,
                        ':label'       => (string) ($a['name'] ?? ('Activity ' . $cmid)),
                    ]);
                    $aN++;
                } catch (\Throwable $e) {
                    $errors[] = 'Activity ' . $cmid . ' in ' . $shortname . ': ' . $e->getMessage();
                }
            }
        }

        return ['courses' => $cN, 'activities' => $aN, 'errors' => $errors];
    }

    /**
     * All stored LMS links grouped by class shortname, each with its activities.
     *
     * @return array<string,array{course:?array,activities:array}>
     */
    public function linksByClass(): array
    {
        $out = [];
        foreach ($this->rows("SELECT entityType, entityKey, subKey, moodleID, modName, moodleRelURL, label
                               FROM kreckLink
                               WHERE entityType IN ('course','activity')
                               ORDER BY entityKey, entityType DESC, moodleID") as $r) {
            $key = $r['entityKey'];
            if (!isset($out[$key])) {
                $out[$key] = ['course' => null, 'activities' => []];
            }
            if ($r['entityType'] === 'course') {
                $out[$key]['course'] = $r;
            } else {
                $out[$key]['activities'][] = $r;
            }
        }
        return $out;
    }

    /**
     * Import a marks payload — either decoded JSON {marks:[...]} or a list of
     * assoc rows (from a CSV). Upserts kreckMark on (entityKey,courseShortname,item).
     *
     * @param array $rows  list of ['studentid','courseshortname','item','grade','grademax','moodlecourseid']
     * @return array ['imported'=>int,'errors'=>string[]]
     */
    public function importMarks(array $rows): array
    {
        $upsert = $this->db->prepare(
            "INSERT INTO kreckMark
               (entityKey, courseShortname, item, grade, gradeMax, moodleCourseID, exportedAt, timestampUpdated)
             VALUES (:entityKey, :courseShortname, :item, :grade, :gradeMax, :moodleCourseID, :exportedAt, NOW())
             ON DUPLICATE KEY UPDATE
               grade=VALUES(grade), gradeMax=VALUES(gradeMax),
               moodleCourseID=VALUES(moodleCourseID), exportedAt=VALUES(exportedAt),
               timestampUpdated=NOW()"
        );

        $n = 0; $errors = [];
        foreach ($rows as $i => $r) {
            $studentid = trim((string) ($r['studentid'] ?? ''));
            $course = trim((string) ($r['courseshortname'] ?? ''));
            $item = trim((string) ($r['item'] ?? ''));
            if ($studentid === '' || $course === '' || $item === '') {
                $errors[] = 'Row ' . ($i + 1) . ': missing studentid/course/item.';
                continue;
            }
            try {
                $upsert->execute([
                    ':entityKey'       => $studentid,
                    ':courseShortname' => $course,
                    ':item'            => $item,
                    ':grade'           => (string) ($r['grade'] ?? ''),
                    ':gradeMax'        => isset($r['grademax']) && $r['grademax'] !== '' ? (string) $r['grademax'] : null,
                    ':moodleCourseID'  => isset($r['moodlecourseid']) && $r['moodlecourseid'] !== '' ? (int) $r['moodlecourseid'] : null,
                    ':exportedAt'      => isset($r['exportedat']) && $r['exportedat'] !== '' ? (string) $r['exportedat'] : null,
                ]);
                $n++;
            } catch (\Throwable $e) {
                $errors[] = 'Row ' . ($i + 1) . ': ' . $e->getMessage();
            }
        }
        return ['imported' => $n, 'errors' => $errors];
    }

    /**
     * Stored marks grouped by course shortname (for display).
     *
     * @param string|null $courseShortname  Filter to one class, or null for all.
     * @return array
     */
    public function marks(?string $courseShortname = null): array
    {
        if ($courseShortname !== null && $courseShortname !== '') {
            return $this->rows(
                "SELECT entityKey, courseShortname, item, grade, gradeMax, moodleCourseID, exportedAt
                 FROM kreckMark WHERE courseShortname = :c
                 ORDER BY entityKey, item", [':c' => $courseShortname]);
        }
        return $this->rows(
            "SELECT entityKey, courseShortname, item, grade, gradeMax, moodleCourseID, exportedAt
             FROM kreckMark ORDER BY courseShortname, entityKey, item");
    }

    /** Distinct course shortnames that have stored marks. */
    public function markCourses(): array
    {
        return array_column(
            $this->rows("SELECT DISTINCT courseShortname FROM kreckMark ORDER BY courseShortname"),
            'courseShortname');
    }
}
