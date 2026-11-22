<?php
/*
Kreck Bridge — Family / parent linking helper.

Implements the KRECK rule "every student should be linked to at least one
parent" and the quick link action, by reusing Gibbon's NATIVE family model
(gibbonFamily / gibbonFamilyChild / gibbonFamilyAdult / gibbonFamilyRelationship)
and the native user-creation constraints (unique username, PasswordPolicy,
salted password, role + status, status log). No core edits; integrity-first:
creating a new parent goes through the same validation the native
user_manage_addProcess.php applies, and every multi-row write is wrapped in a
transaction so a failure never leaves a half-made account or an orphan family row.
*/

class KreckFamilyLink
{
    /** @var \PDO */
    private $db;
    /** @var object Gibbon DI container */
    private $container;

    public function __construct(\PDO $connection2, $container)
    {
        $this->db = $connection2;
        $this->container = $container;
    }

    /** The Gibbon role id for the Parent category (usually 004), resolved live. */
    public function parentRoleID(): string
    {
        $r = $this->db->query("SELECT gibbonRoleID FROM gibbonRole WHERE category='Parent' ORDER BY gibbonRoleID LIMIT 1")->fetchColumn();
        return (string) ($r ?: '004');
    }

    /**
     * Active students (role Student, status Full, enrolled in the year) that have
     * NO linked parent — i.e. not a gibbonFamilyChild in a family that has an adult.
     * The KRECK rule is PASSIVE: this only surfaces who needs attention.
     */
    public function studentsWithoutParent($gibbonSchoolYearID): array
    {
        $sql = "SELECT p.gibbonPersonID, p.surname, p.preferredName, p.username,
                       COALESCE(fg.name, '') AS formGroup
                FROM gibbonPerson p
                JOIN gibbonStudentEnrolment se
                  ON se.gibbonPersonID=p.gibbonPersonID AND se.gibbonSchoolYearID=:syid
                LEFT JOIN gibbonFormGroup fg ON fg.gibbonFormGroupID=se.gibbonFormGroupID
                WHERE p.status='Full'
                  AND p.gibbonRoleIDPrimary=(SELECT gibbonRoleID FROM gibbonRole WHERE category='Student' ORDER BY gibbonRoleID LIMIT 1)
                  AND NOT EXISTS (
                    SELECT 1 FROM gibbonFamilyChild fc
                    JOIN gibbonFamilyAdult fa ON fa.gibbonFamilyID=fc.gibbonFamilyID
                    WHERE fc.gibbonPersonID=p.gibbonPersonID
                  )
                ORDER BY p.surname, p.preferredName";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['syid' => $gibbonSchoolYearID]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** How many students currently have no linked parent (for the warning badge). */
    public function countStudentsWithoutParent($gibbonSchoolYearID): int
    {
        return count($this->studentsWithoutParent($gibbonSchoolYearID));
    }

    /** A person row (or null) — used to validate ids and read the surname. */
    public function person($gibbonPersonID): ?array
    {
        $s = $this->db->prepare("SELECT gibbonPersonID, title, surname, firstName, preferredName, gibbonRoleIDPrimary, status FROM gibbonPerson WHERE gibbonPersonID=:id");
        $s->execute(['id' => $gibbonPersonID]);
        $r = $s->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function usernameExists(string $username): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM gibbonPerson WHERE username=:u");
        $s->execute(['u' => $username]);
        return (bool) $s->fetchColumn();
    }

    /** The family id a student already belongs to (the first one), or null. */
    public function familyForStudent($studentID): ?string
    {
        $s = $this->db->prepare("SELECT gibbonFamilyID FROM gibbonFamilyChild WHERE gibbonPersonID=:id LIMIT 1");
        $s->execute(['id' => $studentID]);
        $r = $s->fetchColumn();
        return $r !== false ? (string) $r : null;
    }

    /** Return the student's family, creating one (and the child membership) if needed. */
    public function ensureFamilyForStudent($studentID): string
    {
        $existing = $this->familyForStudent($studentID);
        if ($existing !== null) {
            return $existing;
        }
        $student = $this->person($studentID);
        if (!$student) {
            throw new \RuntimeException('Student not found.');
        }
        $familyName = trim(($student['surname'] ?? 'Family') . ' Family');
        $ins = $this->db->prepare(
            "INSERT INTO gibbonFamily SET name=:name, status='Married', languageHomePrimary='', languageHomeSecondary='', nameAddress=:nameAddress, homeAddress='', homeAddressDistrict='', homeAddressCountry=''"
        );
        $ins->execute(['name' => $familyName, 'nameAddress' => $familyName]);
        $familyID = (string) $this->db->lastInsertId();
        // Add the student as a child of the new family (native gibbonFamilyChild shape).
        $this->db->prepare("INSERT INTO gibbonFamilyChild SET gibbonFamilyID=:fid, gibbonPersonID=:pid, comment=''")
            ->execute(['fid' => $familyID, 'pid' => $studentID]);
        return $familyID;
    }

    /** True if the person is already an adult or child of the family (no dup rows). */
    private function isInFamily($familyID, $personID): bool
    {
        $s = $this->db->prepare(
            "SELECT 1 FROM gibbonFamilyChild WHERE gibbonFamilyID=:fid AND gibbonPersonID=:pid
             UNION SELECT 1 FROM gibbonFamilyAdult WHERE gibbonFamilyID=:fid AND gibbonPersonID=:pid"
        );
        $s->execute(['fid' => $familyID, 'pid' => $personID]);
        return (bool) $s->fetchColumn();
    }

    /**
     * Add an adult (parent) to a family with child-data access, mirroring the
     * native addAdultProcess: contactPriority 1 implies all contact flags Y, and
     * a priority-1 adult demotes any existing priority-1 to keep exactly one.
     */
    public function addAdultToFamily($familyID, $adultID, string $childDataAccess = 'Y', int $contactPriority = 1, string $comment = ''): void
    {
        if ($this->isInFamily($familyID, $adultID)) {
            return; // already linked; nothing to do
        }
        if ($contactPriority === 1) {
            $contactCall = $contactSMS = $contactEmail = $contactMail = 'Y';
            // Keep a single priority-1 contact: push existing ones down (native behaviour).
            $this->db->prepare("UPDATE gibbonFamilyAdult SET contactPriority=contactPriority+1 WHERE contactPriority<3 AND gibbonFamilyID=:fid AND NOT gibbonPersonID=:pid")
                ->execute(['fid' => $familyID, 'pid' => $adultID]);
        } else {
            $contactCall = $contactSMS = $contactEmail = $contactMail = 'N';
        }
        $this->db->prepare(
            "INSERT INTO gibbonFamilyAdult SET gibbonFamilyID=:fid, gibbonPersonID=:pid, comment=:comment,
             childDataAccess=:cda, contactPriority=:cp, contactCall=:cc, contactSMS=:cs, contactEmail=:ce, contactMail=:cm"
        )->execute([
            'fid' => $familyID, 'pid' => $adultID, 'comment' => $comment,
            'cda' => ($childDataAccess === 'Y' ? 'Y' : 'N'), 'cp' => $contactPriority,
            'cc' => $contactCall, 'cs' => $contactSMS, 'ce' => $contactEmail, 'cm' => $contactMail,
        ]);
    }

    /**
     * Link an EXISTING person to a student as parent. Ensures the family, adds the
     * adult (childDataAccess=Y so they can see academics AND fees), and records the
     * relationship label. Transactional.
     */
    public function linkExisting($studentID, $adultID, string $relationship = 'Guardian', int $contactPriority = 1): array
    {
        if (!$this->person($studentID)) {
            return ['ok' => false, 'error' => 'Student not found.'];
        }
        if (!$this->person($adultID)) {
            return ['ok' => false, 'error' => 'Parent/guardian person not found.'];
        }
        if ((string) $studentID === (string) $adultID) {
            return ['ok' => false, 'error' => 'A student cannot be their own parent.'];
        }
        try {
            $this->db->beginTransaction();
            $familyID = $this->ensureFamilyForStudent($studentID);
            $this->addAdultToFamily($familyID, $adultID, 'Y', $contactPriority);
            $this->container->get(\Gibbon\Domain\User\FamilyAdultGateway::class)
                ->insertFamilyRelationship($familyID, $adultID, $studentID, $relationship);
            $this->db->commit();
            return ['ok' => true, 'gibbonFamilyID' => $familyID, 'gibbonPersonIDAdult' => (string) $adultID];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            KreckBridgeDiagnostics::reportCaught($e, 'link existing parent');
            return ['ok' => false, 'error' => 'Could not link the parent: ' . $e->getMessage()];
        }
    }

    /**
     * Create a NEW parent user with full Gibbon integrity, then link to the student.
     * Mirrors user_manage_addProcess: required fields, unique username, PasswordPolicy,
     * salted sha256 password, Parent role, Full status, status log. Transactional.
     *
     * @param array $fields surname, firstName, [preferredName], [officialName], [title],
     *   gender, username, password, [email], [phone1], [passwordForceReset=Y],
     *   [relationship=Guardian], [contactPriority=1]
     */
    public function createParentAndLink($studentID, array $fields): array
    {
        $surname = trim($fields['surname'] ?? '');
        $firstName = trim($fields['firstName'] ?? '');
        $username = trim($fields['username'] ?? '');
        $password = (string) ($fields['password'] ?? '');
        $gender = $fields['gender'] ?? 'Unspecified';
        $preferredName = trim($fields['preferredName'] ?? '') ?: $firstName;
        $officialName = trim($fields['officialName'] ?? '') ?: trim($firstName . ' ' . $surname);
        $title = $fields['title'] ?? '';
        $email = trim($fields['email'] ?? '');
        $phone1 = trim($fields['phone1'] ?? '');
        $forceReset = (($fields['passwordForceReset'] ?? 'Y') === 'Y') ? 'Y' : 'N';
        $relationship = trim($fields['relationship'] ?? 'Guardian') ?: 'Guardian';
        $contactPriority = (int) ($fields['contactPriority'] ?? 1);

        // --- Integrity gates (match the native process) --------------------------
        if ($surname === '' || $firstName === '' || $username === '' || $password === '') {
            return ['ok' => false, 'error' => 'Surname, first name, username and password are required.'];
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{3,20}$/', $username)) {
            return ['ok' => false, 'error' => 'Username must be 3–20 characters: letters, numbers, dot, underscore or hyphen.'];
        }
        if ($this->usernameExists($username)) {
            return ['ok' => false, 'error' => 'That username is already taken — choose another.'];
        }
        if (!$this->person($studentID)) {
            return ['ok' => false, 'error' => 'Student not found.'];
        }
        $policy = $this->container->get(\Gibbon\Data\PasswordPolicy::class);
        if (!$policy->validate($password)) {
            return ['ok' => false, 'error' => 'Password does not meet the school password policy.'];
        }

        $salt = function_exists('getSalt') ? getSalt() : bin2hex(random_bytes(18));
        $passwordStrong = hash('sha256', $salt . $password);
        $roleID = $this->parentRoleID();

        try {
            $this->db->beginTransaction();

            // Insert the parent (only the fields a parent needs; the rest take DB
            // defaults). Mirrors the native gibbonPerson column set for these fields.
            $this->db->prepare(
                "INSERT INTO gibbonPerson SET
                    title=:title, surname=:surname, firstName=:firstName, preferredName=:preferredName,
                    officialName=:officialName, gender=:gender, username=:username,
                    passwordStrong=:passwordStrong, passwordStrongSalt=:salt,
                    status='Full', canLogin='Y', passwordForceReset=:forceReset,
                    gibbonRoleIDPrimary=:role, gibbonRoleIDAll=:role,
                    email=:email, phone1=:phone1, dateStart=:dateStart"
            )->execute([
                'title' => $title, 'surname' => $surname, 'firstName' => $firstName,
                'preferredName' => $preferredName, 'officialName' => $officialName, 'gender' => $gender,
                'username' => $username, 'passwordStrong' => $passwordStrong, 'salt' => $salt,
                'forceReset' => $forceReset, 'role' => $roleID, 'email' => $email, 'phone1' => $phone1,
                'dateStart' => date('Y-m-d'),
            ]);
            $adultID = (string) $this->db->lastInsertId();

            // Status log (native parity).
            try {
                $this->container->get(\Gibbon\Domain\User\UserStatusLogGateway::class)->insert([
                    'gibbonPersonID' => $adultID, 'statusOld' => 'Full', 'statusNew' => 'Full',
                    'reason' => 'Created (KRECK quick parent link)',
                    'gibbonPersonIDModified' => $this->currentUserID(),
                ]);
            } catch (\Throwable $ignore) { /* log is best-effort */ }

            // Link to the student's family with child-data access.
            $familyID = $this->ensureFamilyForStudent($studentID);
            $this->addAdultToFamily($familyID, $adultID, 'Y', $contactPriority);
            $this->container->get(\Gibbon\Domain\User\FamilyAdultGateway::class)
                ->insertFamilyRelationship($familyID, $adultID, $studentID, $relationship);

            $this->db->commit();
            return ['ok' => true, 'gibbonPersonIDAdult' => $adultID, 'gibbonFamilyID' => $familyID, 'username' => $username];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            KreckBridgeDiagnostics::reportCaught($e, 'create parent and link');
            return ['ok' => false, 'error' => 'Could not create the parent account: ' . $e->getMessage()];
        }
    }

    private function currentUserID()
    {
        try {
            $session = $GLOBALS['session'] ?? null;
            return is_object($session) ? $session->get('gibbonPersonID') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
