<?php
/*
Kreck Bridge — SMS-owned semester assessment workflow.

The legacy kreckMark table remains the raw LMS grade import. This manager
owns teacher-entered final components, review state, audit history and alerts.
*/

class KreckAssessmentManager
{
    private $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    private function rows(string $sql, array $bind = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function one(string $sql, array $bind = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function schoolYears(): array
    {
        return $this->rows(
            "SELECT gibbonSchoolYearID, name, status
             FROM gibbonSchoolYear ORDER BY sequenceNumber DESC"
        );
    }

    public function currentSchoolYear(): ?array
    {
        return $this->one(
            "SELECT gibbonSchoolYearID, name, status
             FROM gibbonSchoolYear WHERE status='Current' LIMIT 1"
        );
    }

    public function classesForTeacher(int $personID, int $yearID, string $period): array
    {
        return $this->rows(
            "SELECT cc.gibbonCourseClassID, cc.name AS className, cc.nameShort AS classShort,
                    c.name AS courseName, c.nameShort AS courseShort,
                    a.kreckAssessmentID, a.status AS assessmentStatus, a.dueDate,
                    (SELECT COUNT(*) FROM gibbonCourseClassPerson s
                     JOIN gibbonPerson sp ON sp.gibbonPersonID=s.gibbonPersonID AND sp.status='Full'
                     WHERE s.gibbonCourseClassID=cc.gibbonCourseClassID AND s.role='Student') AS studentCount
             FROM gibbonCourseClassPerson t
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=t.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             LEFT JOIN kreckAssessment a
               ON a.gibbonCourseClassID=cc.gibbonCourseClassID
              AND a.gibbonSchoolYearID=c.gibbonSchoolYearID AND a.periodName=:period
             WHERE t.gibbonPersonID=:personID AND t.role='Teacher'
               AND c.gibbonSchoolYearID=:yearID
             ORDER BY c.name, cc.name",
            ['personID' => $personID, 'yearID' => $yearID, 'period' => $period]
        );
    }

    public function classesForYear(int $yearID): array
    {
        return $this->rows(
            "SELECT cc.gibbonCourseClassID, cc.name AS className, cc.nameShort AS classShort,
                    c.name AS courseName, c.nameShort AS courseShort
             FROM gibbonCourseClass cc
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             WHERE c.gibbonSchoolYearID=:yearID
             ORDER BY c.name, cc.name",
            ['yearID' => $yearID]
        );
    }

    public function teacherHasClass(int $personID, int $classID, int $yearID): bool
    {
        return $this->one(
            "SELECT cc.gibbonCourseClassID
             FROM gibbonCourseClassPerson ccp
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=ccp.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             WHERE ccp.gibbonPersonID=:personID AND ccp.gibbonCourseClassID=:classID
               AND ccp.role='Teacher' AND c.gibbonSchoolYearID=:yearID
             LIMIT 1",
            ['personID' => $personID, 'classID' => $classID, 'yearID' => $yearID]
        ) !== null;
    }

    public function classDetails(int $classID, int $yearID): ?array
    {
        return $this->one(
            "SELECT cc.gibbonCourseClassID, cc.name AS className, cc.nameShort AS classShort,
                    c.gibbonSchoolYearID, c.name AS courseName, c.nameShort AS courseShort
             FROM gibbonCourseClass cc
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             WHERE cc.gibbonCourseClassID=:classID AND c.gibbonSchoolYearID=:yearID
             LIMIT 1",
            ['classID' => $classID, 'yearID' => $yearID]
        );
    }

    public function studentsForClass(int $classID): array
    {
        return $this->rows(
            "SELECT p.gibbonPersonID, p.studentID, p.title, p.preferredName, p.surname
             FROM gibbonCourseClassPerson ccp
             JOIN gibbonPerson p ON p.gibbonPersonID=ccp.gibbonPersonID
             WHERE ccp.gibbonCourseClassID=:classID AND ccp.role='Student'
               AND p.status='Full'
             ORDER BY p.surname, p.preferredName, p.gibbonPersonID",
            ['classID' => $classID]
        );
    }

    public function sheetForClass(int $classID, int $yearID, string $period): ?array
    {
        return $this->one(
            "SELECT * FROM kreckAssessment
             WHERE gibbonCourseClassID=:classID AND gibbonSchoolYearID=:yearID AND periodName=:period
             LIMIT 1",
            ['classID' => $classID, 'yearID' => $yearID, 'period' => $period]
        );
    }

    public function sheet(int $assessmentID): ?array
    {
        return $this->one(
            "SELECT a.*, cc.name AS className, cc.nameShort AS classShort,
                    c.name AS courseName, c.nameShort AS courseShort, c.gibbonSchoolYearID,
                    sy.name AS schoolYearName
             FROM kreckAssessment a
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=a.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             JOIN gibbonSchoolYear sy ON sy.gibbonSchoolYearID=a.gibbonSchoolYearID
             WHERE a.kreckAssessmentID=:assessmentID
             LIMIT 1",
            ['assessmentID' => $assessmentID]
        );
    }

    public function prepareForReviewer(int $classID, int $yearID, string $period, int $actorID): int
    {
        if (!in_array($period, ['Semester 1', 'Semester 2'], true)) {
            throw new \InvalidArgumentException('Choose Semester 1 or Semester 2.');
        }
        $this->db->beginTransaction();
        try {
            $sheet = $this->createSheet($classID, $yearID, $period, $actorID);
            $this->db->commit();
            return (int) $sheet['kreckAssessmentID'];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function markRows(int $assessmentID, int $classID): array
    {
        return $this->rows(
            "SELECT p.gibbonPersonID, p.studentID, p.title, p.preferredName, p.surname,
                    m.ca1Mark, m.ca2Mark, m.examMark, m.finalMark, m.comment
             FROM gibbonCourseClassPerson ccp
             JOIN gibbonPerson p ON p.gibbonPersonID=ccp.gibbonPersonID
             LEFT JOIN kreckAssessmentMark m
               ON m.kreckAssessmentID=:assessmentID AND m.gibbonPersonIDStudent=p.gibbonPersonID
             WHERE ccp.gibbonCourseClassID=:classID AND ccp.role='Student' AND p.status='Full'
             ORDER BY p.surname, p.preferredName, p.gibbonPersonID",
            ['assessmentID' => $assessmentID, 'classID' => $classID]
        );
    }

    private function recordAudit(
        int $assessmentID,
        ?int $studentID,
        string $action,
        int $actorID,
        ?array $before,
        ?array $after
    ): void {
        $beforeJSON = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $afterJSON = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (($before !== null && $beforeJSON === false) || ($after !== null && $afterJSON === false)) {
            throw new \RuntimeException('Could not serialize assessment audit details.');
        }
        $stmt = $this->db->prepare(
            "INSERT INTO kreckAssessmentAudit
               (kreckAssessmentID, gibbonPersonIDStudent, action, beforeSnapshot, afterSnapshot, gibbonPersonIDActor, timestampCreated)
             VALUES (:assessmentID, :studentID, :action, :beforeSnapshot, :afterSnapshot, :actorID, NOW())"
        );
        $stmt->execute([
            'assessmentID' => $assessmentID,
            'studentID' => $studentID,
            'action' => $action,
            'beforeSnapshot' => $beforeJSON,
            'afterSnapshot' => $afterJSON,
            'actorID' => $actorID ?: null,
        ]);
    }

    private function cancelPendingReminders(int $assessmentID, string $reason): void
    {
        $this->db->prepare(
            "UPDATE kreckAssessmentNotification
             SET status='Cancelled', lastError=:reason
             WHERE kreckAssessmentID=:assessmentID
               AND notificationType='DeadlineReminder' AND status='Pending'"
        )->execute([
            'assessmentID' => $assessmentID,
            'reason' => $reason,
        ]);
    }

    private function createSheet(int $classID, int $yearID, string $period, int $actorID): array
    {
        $existing = $this->sheetForClass($classID, $yearID, $period);
        if ($existing) {
            return $existing;
        }
        $details = $this->classDetails($classID, $yearID);
        if (!$details) {
            throw new \InvalidArgumentException('The selected class does not belong to the selected school year.');
        }
        $insert = $this->db->prepare(
            "INSERT INTO kreckAssessment
               (gibbonCourseClassID, gibbonSchoolYearID, periodName, gibbonPersonIDCreator, timestampCreated, timestampUpdated)
             VALUES (:classID, :yearID, :period, :actorID, NOW(), NOW())
             ON DUPLICATE KEY UPDATE kreckAssessmentID=LAST_INSERT_ID(kreckAssessmentID)"
        );
        $insert->execute([
            'classID' => $classID,
            'yearID' => $yearID,
            'period' => $period,
            'actorID' => $actorID ?: null,
        ]);
        $assessmentID = (int) $this->db->lastInsertId();
        $sheet = $this->sheet($assessmentID);
        if (!$sheet) {
            throw new \RuntimeException('The assessment sheet could not be created.');
        }
        if ($insert->rowCount() === 1) {
            $this->recordAudit($assessmentID, null, 'Created', $actorID, null, [
                'classID' => $classID,
                'schoolYearID' => $yearID,
                'period' => $period,
                'weights' => ['ca1' => 25, 'ca2' => 25, 'exam' => 50],
            ]);
        }
        return $sheet;
    }

    private function validateWeights(array $weights): array
    {
        $clean = [];
        foreach (['ca1', 'ca2', 'exam'] as $component) {
            $value = $weights[$component] ?? null;
            if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                throw new \InvalidArgumentException('Each assessment weight must be a number from 0 to 100.');
            }
            $clean[$component] = round((float) $value, 2);
        }
        if (abs(array_sum($clean) - 100.0) > 0.001) {
            throw new \InvalidArgumentException('CA1, CA2 and exam weights must total exactly 100%.');
        }
        return $clean;
    }

    private function validateMark($value, string $label): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw new \InvalidArgumentException($label . ' must be a number from 0 to 100.');
        }
        return round((float) $value, 2);
    }

    private function editableSheet(int $classID, int $yearID, string $period, int $actorID): array
    {
        if (!$this->teacherHasClass($actorID, $classID, $yearID)) {
            throw new \InvalidArgumentException('You can only enter marks for a class assigned to you.');
        }
        if (!in_array($period, ['Semester 1', 'Semester 2'], true)) {
            throw new \InvalidArgumentException('Choose Semester 1 or Semester 2.');
        }
        $sheet = $this->sheetForClass($classID, $yearID, $period);
        if (!$sheet) {
            return [];
        }
        if (!in_array($sheet['status'], ['Draft', 'RevisionRequested'], true)) {
            throw new \InvalidArgumentException('This mark sheet is submitted or approved and is locked for editing.');
        }
        return $sheet;
    }

    public function saveDraft(
        int $classID,
        int $yearID,
        string $period,
        array $weights,
        array $postedMarks,
        int $actorID
    ): int {
        $weights = $this->validateWeights($weights);
        $this->editableSheet($classID, $yearID, $period, $actorID);
        $roster = $this->studentsForClass($classID);
        $allowedStudents = array_fill_keys(array_map('intval', array_column($roster, 'gibbonPersonID')), true);
        foreach ($postedMarks as $studentID => $row) {
            if (!isset($allowedStudents[(int) $studentID]) || !is_array($row)) {
                throw new \InvalidArgumentException('The submitted mark sheet includes a student outside this class roster.');
            }
        }

        $this->db->beginTransaction();
        try {
            $sheet = $this->createSheet($classID, $yearID, $period, $actorID);
            $sheet = $this->one(
                "SELECT * FROM kreckAssessment WHERE kreckAssessmentID=:assessmentID FOR UPDATE",
                ['assessmentID' => $sheet['kreckAssessmentID']]
            );
            if (!$sheet) {
                throw new \RuntimeException('The assessment sheet could not be locked for editing.');
            }
            if (!in_array($sheet['status'], ['Draft', 'RevisionRequested'], true)) {
                throw new \InvalidArgumentException('This mark sheet has already been submitted and is locked.');
            }
            $oldWeights = [
                'ca1' => (float) $sheet['ca1Weight'],
                'ca2' => (float) $sheet['ca2Weight'],
                'exam' => (float) $sheet['examWeight'],
            ];
            if ($oldWeights !== $weights) {
                $this->db->prepare(
                    "UPDATE kreckAssessment
                     SET ca1Weight=:ca1, ca2Weight=:ca2, examWeight=:exam, timestampUpdated=NOW()
                     WHERE kreckAssessmentID=:assessmentID"
                )->execute([
                    'ca1' => $weights['ca1'], 'ca2' => $weights['ca2'], 'exam' => $weights['exam'],
                    'assessmentID' => $sheet['kreckAssessmentID'],
                ]);
                $this->recordAudit((int) $sheet['kreckAssessmentID'], null, 'WeightsChanged', $actorID, $oldWeights, $weights);
            }

            $upsert = $this->db->prepare(
                "INSERT INTO kreckAssessmentMark
                   (kreckAssessmentID, gibbonPersonIDStudent, ca1Mark, ca1Source, ca1SourceRef,
                    ca2Mark, ca2Source, ca2SourceRef, examMark, examSource, examSourceRef,
                    finalMark, comment, gibbonPersonIDLastEdit, timestampUpdated)
                 VALUES
                   (:assessmentID, :studentID, :ca1, 'Manual', NULL, :ca2, 'Manual', NULL,
                    :exam, 'Manual', NULL, :finalMark, :comment, :actorID, NOW())
                 ON DUPLICATE KEY UPDATE
                   ca1Mark=VALUES(ca1Mark), ca1Source='Manual', ca1SourceRef=NULL,
                   ca2Mark=VALUES(ca2Mark), ca2Source='Manual', ca2SourceRef=NULL,
                   examMark=VALUES(examMark), examSource='Manual', examSourceRef=NULL,
                   finalMark=VALUES(finalMark), comment=VALUES(comment),
                   gibbonPersonIDLastEdit=VALUES(gibbonPersonIDLastEdit), timestampUpdated=NOW()"
            );

            foreach ($roster as $student) {
                $studentID = (int) $student['gibbonPersonID'];
                $row = $postedMarks[$studentID] ?? $postedMarks[(string) $studentID] ?? [];
                $ca1 = $this->validateMark($row['ca1Mark'] ?? null, 'CA1');
                $ca2 = $this->validateMark($row['ca2Mark'] ?? null, 'CA2');
                $exam = $this->validateMark($row['examMark'] ?? null, 'Final exam');
                $comment = trim((string) ($row['comment'] ?? ''));
                if (strlen($comment) > 8000) {
                    throw new \InvalidArgumentException('Student comments must be 8,000 characters or fewer.');
                }
                $before = $this->one(
                    "SELECT ca1Mark, ca2Mark, examMark, finalMark, comment
                     FROM kreckAssessmentMark
                     WHERE kreckAssessmentID=:assessmentID AND gibbonPersonIDStudent=:studentID",
                    ['assessmentID' => $sheet['kreckAssessmentID'], 'studentID' => $studentID]
                );
                $final = $ca1 !== null && $ca2 !== null && $exam !== null
                    ? round(($ca1 * $weights['ca1'] + $ca2 * $weights['ca2'] + $exam * $weights['exam']) / 100, 2)
                    : null;
                $after = [
                    'ca1Mark' => $ca1, 'ca2Mark' => $ca2, 'examMark' => $exam,
                    'finalMark' => $final, 'comment' => $comment,
                ];
                $beforeNormalized = $before ? [
                    'ca1Mark' => $before['ca1Mark'] === null ? null : (float) $before['ca1Mark'],
                    'ca2Mark' => $before['ca2Mark'] === null ? null : (float) $before['ca2Mark'],
                    'examMark' => $before['examMark'] === null ? null : (float) $before['examMark'],
                    'finalMark' => $before['finalMark'] === null ? null : (float) $before['finalMark'],
                    'comment' => (string) ($before['comment'] ?? ''),
                ] : null;
                if ($beforeNormalized === null && $ca1 === null && $ca2 === null && $exam === null && $comment === '') {
                    continue;
                }
                if ($beforeNormalized === $after) {
                    continue;
                }
                $upsert->execute([
                    'assessmentID' => $sheet['kreckAssessmentID'],
                    'studentID' => $studentID,
                    'ca1' => $ca1,
                    'ca2' => $ca2,
                    'exam' => $exam,
                    'finalMark' => $final,
                    'comment' => $comment !== '' ? $comment : null,
                    'actorID' => $actorID ?: null,
                ]);
                $this->recordAudit((int) $sheet['kreckAssessmentID'], $studentID, 'MarksSaved', $actorID, $beforeNormalized, $after);
            }
            $this->db->prepare("UPDATE kreckAssessment SET timestampUpdated=NOW() WHERE kreckAssessmentID=:id")
                ->execute(['id' => $sheet['kreckAssessmentID']]);
            $this->db->commit();
            return (int) $sheet['kreckAssessmentID'];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function submit(int $classID, int $yearID, string $period, int $actorID): string
    {
        if (!$this->teacherHasClass($actorID, $classID, $yearID)) {
            throw new \InvalidArgumentException('You can only submit marks for a class assigned to you.');
        }
        if (!in_array($period, ['Semester 1', 'Semester 2'], true)) {
            throw new \InvalidArgumentException('Choose Semester 1 or Semester 2.');
        }

        $this->db->beginTransaction();
        try {
            $sheet = $this->one(
                "SELECT * FROM kreckAssessment
                 WHERE gibbonCourseClassID=:classID AND gibbonSchoolYearID=:yearID AND periodName=:period
                 FOR UPDATE",
                ['classID' => $classID, 'yearID' => $yearID, 'period' => $period]
            );
            if (!$sheet || !in_array($sheet['status'], ['Draft', 'RevisionRequested'], true)) {
                throw new \InvalidArgumentException('This mark sheet is not available for submission.');
            }
            $weights = $this->validateWeights([
                'ca1' => $sheet['ca1Weight'], 'ca2' => $sheet['ca2Weight'], 'exam' => $sheet['examWeight'],
            ]);
            $roster = $this->studentsForClass($classID);
            if (empty($roster)) {
                throw new \InvalidArgumentException('This class has no active students to submit.');
            }
            $marks = $this->markRows((int) $sheet['kreckAssessmentID'], $classID);
            $missing = [];
            foreach ($marks as $row) {
                if ($row['ca1Mark'] === null || $row['ca2Mark'] === null || $row['examMark'] === null) {
                    $missing[] = trim($row['preferredName'] . ' ' . $row['surname']);
                }
            }
            if (count($marks) !== count($roster) || !empty($missing)) {
                throw new \InvalidArgumentException(
                    'Enter CA1, CA2 and final exam marks for every active student before submitting. Incomplete: '
                    . implode(', ', array_slice($missing, 0, 8))
                    . (count($missing) > 8 ? '…' : '')
                );
            }
            $submitStmt = $this->db->prepare(
                "UPDATE kreckAssessment
                 SET status='Submitted', gibbonPersonIDSubmitted=:actorID, submittedAt=NOW(),
                     gibbonPersonIDReviewer=NULL, reviewedAt=NULL, reviewerComment=NULL, timestampUpdated=NOW()
                 WHERE kreckAssessmentID=:id AND status IN ('Draft','RevisionRequested')"
            );
            $submitStmt->execute(['actorID' => $actorID, 'id' => $sheet['kreckAssessmentID']]);
            if ($submitStmt->rowCount() !== 1) {
                throw new \InvalidArgumentException('The mark sheet changed while you were submitting it. Reload and try again.');
            }
            $this->cancelPendingReminders((int) $sheet['kreckAssessmentID'], 'Submission received; deadline reminders stopped.');
            $this->recordAudit((int) $sheet['kreckAssessmentID'], null, 'Submitted', $actorID, [
                'status' => $sheet['status'],
            ], ['status' => 'Submitted', 'weights' => $weights, 'studentCount' => count($roster)]);
            $eventKey = $this->queueReviewNotifications($sheet, 'Submitted', 'A class assessment mark sheet is ready for review.');
            $this->db->commit();
            return $eventKey;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function review(int $assessmentID, int $actorID, string $decision, string $comment): string
    {
        if (!in_array($decision, ['approve', 'revise'], true)) {
            throw new \InvalidArgumentException('Choose approve or request revisions.');
        }
        $sheet = $this->sheet($assessmentID);
        if (!$sheet) {
            throw new \InvalidArgumentException('Assessment sheet not found.');
        }
        $previousStatus = (string) $sheet['status'];
        if ($decision === 'approve' && $previousStatus !== 'Submitted') {
            throw new \InvalidArgumentException('Only a submitted assessment can be approved.');
        }
        if ($decision === 'revise' && !in_array($previousStatus, ['Submitted', 'Approved'], true)) {
            throw new \InvalidArgumentException('Only submitted or approved results can be returned for correction.');
        }
        $comment = trim($comment);
        if ($decision === 'revise' && $comment === '') {
            throw new \InvalidArgumentException('Add a reason so the teacher knows what needs correction.');
        }
        if (strlen($comment) > 8000) {
            throw new \InvalidArgumentException('Review comments must be 8,000 characters or fewer.');
        }
        if ($decision === 'approve') {
            $weights = $this->validateWeights([
                'ca1' => $sheet['ca1Weight'], 'ca2' => $sheet['ca2Weight'], 'exam' => $sheet['examWeight'],
            ]);
            $roster = $this->studentsForClass((int) $sheet['gibbonCourseClassID']);
            $marks = $this->markRows($assessmentID, (int) $sheet['gibbonCourseClassID']);
            if (empty($roster) || count($marks) !== count($roster)) {
                throw new \InvalidArgumentException('The class roster changed after submission. Return the sheet for correction before approving.');
            }
            foreach ($marks as $row) {
                if ($row['ca1Mark'] === null || $row['ca2Mark'] === null || $row['examMark'] === null) {
                    throw new \InvalidArgumentException('The current class roster has incomplete marks. Return the sheet for correction before approving.');
                }
                $expected = round(
                    ((float) $row['ca1Mark'] * $weights['ca1']
                        + (float) $row['ca2Mark'] * $weights['ca2']
                        + (float) $row['examMark'] * $weights['exam']) / 100,
                    2
                );
                if ($row['finalMark'] === null || abs($expected - (float) $row['finalMark']) > 0.01) {
                    throw new \InvalidArgumentException('A weighted final mark is out of date. Return the sheet for correction before approving.');
                }
            }
        }

        $newStatus = $decision === 'approve' ? 'Approved' : 'RevisionRequested';
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare(
                "UPDATE kreckAssessment
                 SET status=:status, gibbonPersonIDReviewer=:actorID, reviewedAt=NOW(),
                     reviewerComment=:comment, timestampUpdated=NOW()
                 WHERE kreckAssessmentID=:id AND status=:previousStatus"
            );
            $update->execute([
                'status' => $newStatus,
                'actorID' => $actorID,
                'comment' => $comment !== '' ? $comment : null,
                'id' => $assessmentID,
                'previousStatus' => $previousStatus,
            ]);
            if ($update->rowCount() !== 1) {
                throw new \InvalidArgumentException('The assessment changed during review. Reload and try again.');
            }
            if ($decision === 'approve') {
                $this->cancelPendingReminders($assessmentID, 'Assessment approved; deadline reminders stopped.');
            }
            $action = $decision === 'approve' ? 'Approved' : 'RevisionRequested';
            $this->recordAudit($assessmentID, null, $action, $actorID, [
                'status' => $previousStatus,
                'reviewerComment' => $sheet['reviewerComment'],
            ], ['status' => $newStatus, 'reviewerComment' => $comment]);
            $eventKey = $this->queueTeacherNotifications(
                $sheet,
                $action,
                $decision === 'approve'
                    ? 'Your assessment results have been approved and locked for reporting.'
                    : 'Your assessment results were returned for correction. Review the principal’s comment.'
            );
            $this->db->commit();
            return $eventKey;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function setDeadline(int $assessmentID, int $actorID, string $date): void
    {
        $sheet = $this->sheet($assessmentID);
        if (!$sheet) {
            throw new \InvalidArgumentException('Assessment sheet not found.');
        }
        if ($sheet['status'] === 'Approved') {
            throw new \InvalidArgumentException('Approved results are locked; a deadline cannot be changed.');
        }
        $date = trim($date);
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Enter the deadline as a valid calendar date.');
        }
        if ($date !== '' && date('Y-m-d', strtotime($date)) !== $date) {
            throw new \InvalidArgumentException('Enter a valid calendar date.');
        }
        $before = $sheet['dueDate'];
        $newValue = $date !== '' ? $date : null;
        if ($before === $newValue) {
            return;
        }
        $this->db->beginTransaction();
        try {
            $deadlineStmt = $this->db->prepare(
                "UPDATE kreckAssessment
                 SET dueDate=:dueDate, timestampUpdated=NOW()
                 WHERE kreckAssessmentID=:id AND status!='Approved'"
            );
            $deadlineStmt->execute(['dueDate' => $newValue, 'id' => $assessmentID]);
            if ($deadlineStmt->rowCount() !== 1) {
                throw new \InvalidArgumentException('The assessment was approved while the deadline was being changed. Reload and try again.');
            }
            $this->cancelPendingReminders($assessmentID, 'Assessment deadline changed; this reminder was superseded.');
            $this->recordAudit($assessmentID, null, 'DeadlineChanged', $actorID, ['dueDate' => $before], ['dueDate' => $newValue]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function queueReviewNotifications(array $sheet, string $action, string $message): string
    {
        $recipients = $this->rows(
            "SELECT DISTINCT p.gibbonPersonID
             FROM gibbonAction a
             JOIN gibbonPermission pm ON pm.gibbonActionID=a.gibbonActionID
             JOIN gibbonRole r ON r.gibbonRoleID=pm.gibbonRoleID
             JOIN gibbonPerson p ON p.gibbonRoleIDPrimary=r.gibbonRoleID
             WHERE a.name='Review Assessment Marks'
               AND a.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Kreck Bridge')
               AND p.status='Full'"
        );
        return $this->queueNotificationRows($sheet, $action, $message, array_column($recipients, 'gibbonPersonID'));
    }

    private function queueTeacherNotifications(array $sheet, string $action, string $message): string
    {
        $recipients = $this->rows(
            "SELECT DISTINCT p.gibbonPersonID
             FROM gibbonCourseClassPerson ccp
             JOIN gibbonPerson p ON p.gibbonPersonID=ccp.gibbonPersonID
             WHERE ccp.gibbonCourseClassID=:classID AND ccp.role='Teacher' AND p.status='Full'",
            ['classID' => $sheet['gibbonCourseClassID']]
        );
        return $this->queueNotificationRows($sheet, $action, $message, array_column($recipients, 'gibbonPersonID'));
    }

    private function queueNotificationRows(
        array $sheet,
        string $type,
        string $message,
        array $recipientIDs,
        ?string $dedupeToken = null
    ): string
    {
        $eventKey = $dedupeToken ?? ('assessment:' . $sheet['kreckAssessmentID'] . ':' . strtolower($type) . ':' . bin2hex(random_bytes(8)));
        $link = '/index.php?q=/modules/Kreck%20Bridge/kreck_assessments.php&class='
            . (int) $sheet['gibbonCourseClassID']
            . '&year=' . (int) $sheet['gibbonSchoolYearID']
            . '&period=' . rawurlencode((string) $sheet['periodName']);
        $stmt = $this->db->prepare(
            "INSERT INTO kreckAssessmentNotification
               (eventKey, kreckAssessmentID, gibbonPersonIDRecipient, notificationType, message,
                actionLink, status, attempts, nextAttempt, timestampCreated)
             VALUES (:eventKey, :assessmentID, :recipientID, :type, :message, :link,
                     'Pending', 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE kreckAssessmentNotificationID=LAST_INSERT_ID(kreckAssessmentNotificationID)"
        );
        foreach (array_unique(array_map('intval', $recipientIDs)) as $recipientID) {
            if ($recipientID > 0) {
                $stmt->execute([
                    'eventKey' => $eventKey,
                    'assessmentID' => $sheet['kreckAssessmentID'],
                    'recipientID' => $recipientID,
                    'type' => $type,
                    'message' => $message,
                    'link' => $link,
                ]);
            }
        }
        return $eventKey;
    }

    public function enqueueDeadlineReminders(): array
    {
        $sheets = $this->rows(
            "SELECT a.*, cc.name AS className, c.name AS courseName,
                    DATEDIFF(a.dueDate, CURDATE()) AS daysRemaining
             FROM kreckAssessment a
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=a.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             WHERE a.status IN ('Draft','RevisionRequested')
               AND a.dueDate IS NOT NULL AND a.dueDate <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
        );
        $eventKeys = [];
        foreach ($sheets as $sheet) {
            $days = (int) $sheet['daysRemaining'];
            if ($days > 0 && !in_array($days, [1, 3, 7], true)) {
                continue;
            }
            if ($days < 0 && abs($days) % 7 !== 0) {
                continue;
            }
            $reminderSlot = $days < 0
                ? 'overdue_' . abs($days)
                : ($days === 0 ? 'due_today' : 'due_in_' . $days);
            $message = $days < 0
                ? 'Assessment marks are overdue for ' . $sheet['courseName'] . ' — ' . $sheet['className'] . '.'
                : ($days === 0
                    ? 'Assessment marks are due today for ' . $sheet['courseName'] . ' — ' . $sheet['className'] . '.'
                    : 'Assessment marks are due in ' . $days . ' day(s) for ' . $sheet['courseName'] . ' — ' . $sheet['className'] . '.');
            $teachers = $this->rows(
                "SELECT DISTINCT ccp.gibbonPersonID
                 FROM gibbonCourseClassPerson ccp
                 JOIN gibbonPerson p ON p.gibbonPersonID=ccp.gibbonPersonID
                 WHERE ccp.gibbonCourseClassID=:classID AND ccp.role='Teacher' AND p.status='Full'",
                ['classID' => $sheet['gibbonCourseClassID']]
            );
            $eventKeys[] = $this->queueNotificationRows(
                $sheet,
                'DeadlineReminder',
                $message,
                array_column($teachers, 'gibbonPersonID'),
                'assessment:' . $sheet['kreckAssessmentID'] . ':reminder:' . $sheet['dueDate'] . ':' . $reminderSlot
            );
        }
        return array_values(array_unique($eventKeys));
    }

    public function dispatchNotifications($sender, ?string $eventKey = null): array
    {
        $sql = "SELECT * FROM kreckAssessmentNotification
                WHERE status='Pending' AND nextAttempt<=NOW()";
        $params = [];
        if ($eventKey !== null) {
            $sql .= " AND eventKey=:eventKey";
            $params['eventKey'] = $eventKey;
        }
        $sql .= " ORDER BY kreckAssessmentNotificationID LIMIT 50";
        $notifications = $this->rows($sql, $params);
        if (empty($notifications)) {
            return ['queued' => 0, 'sent' => 0, 'failed' => 0, 'emailFailed' => 0];
        }

        try {
            foreach ($notifications as $notification) {
                $sender->addNotification(
                    (int) $notification['gibbonPersonIDRecipient'],
                    (string) $notification['message'],
                    'Kreck Bridge',
                    (string) $notification['actionLink']
                );
            }
            $report = $sender->sendNotifications();
        } catch (\Throwable $exception) {
            $failed = $this->db->prepare(
                "UPDATE kreckAssessmentNotification
                 SET status=IF(attempts>=4,'Failed','Pending'),
                     attempts=attempts+1,
                     nextAttempt=DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                     lastError=:error
                 WHERE kreckAssessmentNotificationID=:id AND status='Pending'"
            );
            foreach ($notifications as $notification) {
                $failed->execute([
                    'error' => substr($exception->getMessage(), 0, 2000),
                    'id' => $notification['kreckAssessmentNotificationID'],
                ]);
            }
            return ['queued' => count($notifications), 'sent' => 0, 'failed' => count($notifications), 'emailFailed' => 0];
        }

        $emailFailed = (int) ($report['emailFailed'] ?? 0);
        $deliveryNote = $emailFailed > 0
            ? 'SMS reports ' . $emailFailed . ' email delivery failure(s); in-system notifications were submitted.'
            : null;
        $sent = $this->db->prepare(
            "UPDATE kreckAssessmentNotification
             SET status='Sent', attempts=attempts+1, timestampSent=NOW(), lastError=:note
             WHERE kreckAssessmentNotificationID=:id AND status='Pending'"
        );
        foreach ($notifications as $notification) {
            $sent->execute(['note' => $deliveryNote, 'id' => $notification['kreckAssessmentNotificationID']]);
        }
        return ['queued' => count($notifications), 'sent' => count($notifications), 'failed' => 0, 'emailFailed' => $emailFailed];
    }

    public function reviewQueue(int $yearID): array
    {
        return $this->rows(
            "SELECT a.*, cc.name AS className, cc.nameShort AS classShort,
                    c.name AS courseName, c.nameShort AS courseShort,
                    (SELECT COUNT(*) FROM gibbonCourseClassPerson s
                     WHERE s.gibbonCourseClassID=a.gibbonCourseClassID AND s.role='Student') AS studentCount
             FROM kreckAssessment a
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=a.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             WHERE a.gibbonSchoolYearID=:yearID AND a.status IN ('Submitted','Approved','RevisionRequested')
             ORDER BY FIELD(a.status,'Submitted','RevisionRequested','Approved'), c.name, cc.name, a.periodName",
            ['yearID' => $yearID]
        );
    }

    public function assessmentDashboard(int $yearID): array
    {
        return $this->rows(
            "SELECT cc.gibbonCourseClassID, cc.name AS className, cc.nameShort AS classShort,
                    c.name AS courseName, c.nameShort AS courseShort, periods.periodName,
                    a.kreckAssessmentID, a.status, a.dueDate, a.submittedAt, a.reviewedAt,
                    a.ca1Weight, a.ca2Weight, a.examWeight, a.reviewerComment,
                    (SELECT COUNT(*) FROM gibbonCourseClassPerson s
                     JOIN gibbonPerson sp ON sp.gibbonPersonID=s.gibbonPersonID AND sp.status='Full'
                     WHERE s.gibbonCourseClassID=cc.gibbonCourseClassID AND s.role='Student') AS studentCount,
                    (SELECT COUNT(*) FROM kreckAssessmentMark m
                     JOIN gibbonCourseClassPerson scp
                       ON scp.gibbonCourseClassID=cc.gibbonCourseClassID
                      AND scp.gibbonPersonID=m.gibbonPersonIDStudent AND scp.role='Student'
                     JOIN gibbonPerson sp
                       ON sp.gibbonPersonID=m.gibbonPersonIDStudent AND sp.status='Full'
                     WHERE m.kreckAssessmentID=a.kreckAssessmentID AND m.ca1Mark IS NOT NULL
                       AND m.ca2Mark IS NOT NULL AND m.examMark IS NOT NULL) AS completeCount,
                    (SELECT GROUP_CONCAT(DISTINCT CONCAT(tp.preferredName,' ',tp.surname) ORDER BY tp.surname SEPARATOR ', ')
                     FROM gibbonCourseClassPerson t
                     JOIN gibbonPerson tp ON tp.gibbonPersonID=t.gibbonPersonID AND tp.status='Full'
                     WHERE t.gibbonCourseClassID=cc.gibbonCourseClassID AND t.role='Teacher') AS teachers
             FROM gibbonCourseClass cc
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             CROSS JOIN (SELECT 'Semester 1' AS periodName UNION ALL SELECT 'Semester 2') periods
             LEFT JOIN kreckAssessment a
               ON a.gibbonCourseClassID=cc.gibbonCourseClassID
              AND a.gibbonSchoolYearID=c.gibbonSchoolYearID AND a.periodName=periods.periodName
             WHERE c.gibbonSchoolYearID=:yearID
             ORDER BY c.name, cc.name, periods.periodName",
            ['yearID' => $yearID]
        );
    }

    public function auditRows(int $assessmentID): array
    {
        return $this->rows(
            "SELECT h.*, p.preferredName, p.surname
             FROM kreckAssessmentAudit h
             LEFT JOIN gibbonPerson p ON p.gibbonPersonID=h.gibbonPersonIDActor
             WHERE h.kreckAssessmentID=:id
             ORDER BY h.timestampCreated DESC, h.kreckAssessmentAuditID DESC
             LIMIT 200",
            ['id' => $assessmentID]
        );
    }

    public function notificationRows(int $assessmentID): array
    {
        return $this->rows(
            "SELECT n.*, p.preferredName, p.surname
             FROM kreckAssessmentNotification n
             LEFT JOIN gibbonPerson p ON p.gibbonPersonID=n.gibbonPersonIDRecipient
             WHERE n.kreckAssessmentID=:id
             ORDER BY n.timestampCreated DESC, n.kreckAssessmentNotificationID DESC
             LIMIT 100",
            ['id' => $assessmentID]
        );
    }

    public function approvedReportRows(int $yearID, ?string $period, ?int $classID, array $allowedClassIDs = []): array
    {
        $where = ['a.gibbonSchoolYearID=:yearID', "a.status='Approved'"];
        $bind = ['yearID' => $yearID];
        if ($period !== null && $period !== '') {
            $where[] = 'a.periodName=:period';
            $bind['period'] = $period;
        }
        if ($classID !== null && $classID > 0) {
            $where[] = 'a.gibbonCourseClassID=:classID';
            $bind['classID'] = $classID;
        }
        if (!empty($allowedClassIDs)) {
            $placeholders = [];
            foreach (array_values($allowedClassIDs) as $index => $id) {
                $key = 'class' . $index;
                $placeholders[] = ':' . $key;
                $bind[$key] = (int) $id;
            }
            $where[] = 'a.gibbonCourseClassID IN (' . implode(',', $placeholders) . ')';
        }
        return $this->rows(
            "SELECT sy.name AS schoolYearName, a.periodName, a.ca1Weight, a.ca2Weight, a.examWeight,
                    cc.gibbonCourseClassID, cc.name AS className, c.name AS courseName,
                    p.gibbonPersonID, p.studentID, p.title, p.preferredName, p.surname,
                    m.ca1Mark, m.ca2Mark, m.examMark, m.finalMark, m.comment
             FROM kreckAssessment a
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=a.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             JOIN gibbonSchoolYear sy ON sy.gibbonSchoolYearID=a.gibbonSchoolYearID
             JOIN kreckAssessmentMark m ON m.kreckAssessmentID=a.kreckAssessmentID
             JOIN gibbonPerson p ON p.gibbonPersonID=m.gibbonPersonIDStudent
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.name, cc.name, a.periodName, p.surname, p.preferredName",
            $bind
        );
    }

    public function reportsForTeacher(int $personID, int $yearID): array
    {
        $rows = $this->rows(
            "SELECT DISTINCT cc.gibbonCourseClassID
             FROM gibbonCourseClassPerson ccp
             JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=ccp.gibbonCourseClassID
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             WHERE ccp.gibbonPersonID=:personID AND ccp.role='Teacher' AND c.gibbonSchoolYearID=:yearID",
            ['personID' => $personID, 'yearID' => $yearID]
        );
        return array_map('intval', array_column($rows, 'gibbonCourseClassID'));
    }
}
