<?php
/*
Kreck Bridge — SalaryManager.

Staff-salary record-keeping that extends Gibbon's Finance module without
duplicating it. kreckSalary holds the RECURRING definition (amount + frequency
+ pay day + active window); kreckSalaryPayment holds one instance PER PERIOD,
generated from the active definitions. Proof-of-payment, receipts, payments and
notifications reuse Gibbon's own machinery (FileUploader/FileHandler,
gibbonPayment, report.php receipts, NotificationSender) — done at the call sites
that have the container/session. This class is pure DB logic (Connection only),
so it is shared by the web pages and the CLI reminder job.

No namespace (Gibbon modules are not PSR-4 autoloaded); required directly.
*/

use Gibbon\Contracts\Database\Connection;

class KreckSalaryManager
{
    /** @var \PDO The raw PDO handle (Gibbon's Connection wrapper lacks prepare()). */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db->getConnection();
    }

    public static function currentPeriod(): string
    {
        return date('Y-m');
    }

    /** Human label for a 'YYYY-MM' period, e.g. "January 2026". */
    public static function periodLabel(string $period): string
    {
        $ts = strtotime($period . '-01');
        return $ts ? date('F Y', $ts) : $period;
    }

    private function periodBounds(string $period): array
    {
        $start = $period . '-01';
        $end = date('Y-m-t', strtotime($start));
        return [$start, $end];
    }

    private function dueDateFor(string $period, $payDay): string
    {
        [, $end] = $this->periodBounds($period);
        $lastDay = (int) date('t', strtotime($period . '-01'));
        $day = (int) $payDay;
        if ($day < 1 || $day > $lastDay) {
            return $end; // default to end of month
        }
        return date('Y-m-d', strtotime(sprintf('%s-%02d', $period, $day)));
    }

    /** Active salary definitions overlapping a period. */
    public function activeSalariesForPeriod(string $period): array
    {
        [$start, $end] = $this->periodBounds($period);
        $sql = "SELECT s.*, p.title, p.preferredName, p.surname, p.username, p.email
                FROM kreckSalary s
                JOIN gibbonPerson p ON p.gibbonPersonID = s.gibbonPersonID
                WHERE s.status = 'Active'
                  AND s.startDate <= :end
                  AND (s.endDate IS NULL OR s.endDate = '0000-00-00' OR s.endDate >= :start)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Generate (idempotently) the kreckSalaryPayment rows for a period from the
     * active salary definitions. The UNIQUE (kreckSalaryID, period) key makes
     * re-running safe — existing rows are left untouched.
     * @return array ['created'=>int, 'skipped'=>int]
     */
    public function generatePeriod(string $period, int $userID): array
    {
        $created = 0; $skipped = 0;
        foreach ($this->activeSalariesForPeriod($period) as $s) {
            $exists = $this->db->prepare("SELECT kreckSalaryPaymentID FROM kreckSalaryPayment WHERE kreckSalaryID=:sid AND period=:period");
            $exists->execute(['sid' => $s['kreckSalaryID'], 'period' => $period]);
            if ($exists->fetch()) { $skipped++; continue; }

            $ins = $this->db->prepare(
                "INSERT INTO kreckSalaryPayment
                   (kreckSalaryID, gibbonPersonID, period, dueDate, amount, status, gibbonPersonIDCreator, timestampCreator)
                 VALUES (:sid, :pid, :period, :dueDate, :amount, 'Pending', :creator, NOW())"
            );
            $ins->execute([
                'sid'     => $s['kreckSalaryID'],
                'pid'     => $s['gibbonPersonID'],
                'period'  => $period,
                'dueDate' => $this->dueDateFor($period, $s['payDay']),
                'amount'  => $s['amount'],
                'creator' => $userID ?: null,
            ]);
            $created++;
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    /** Payments for a period, joined to the staff member. */
    public function periodPayments(string $period): array
    {
        $sql = "SELECT sp.*, p.title, p.preferredName, p.surname, p.username, p.email
                FROM kreckSalaryPayment sp
                JOIN gibbonPerson p ON p.gibbonPersonID = sp.gibbonPersonID
                WHERE sp.period = :period
                ORDER BY p.surname, p.preferredName";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['period' => $period]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getPayment($id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT sp.*, p.title, p.preferredName, p.surname, p.username, p.email
             FROM kreckSalaryPayment sp JOIN gibbonPerson p ON p.gibbonPersonID = sp.gibbonPersonID
             WHERE sp.kreckSalaryPaymentID = :id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function setStatus($id, string $status): void
    {
        $stmt = $this->db->prepare("UPDATE kreckSalaryPayment SET status=:status WHERE kreckSalaryPaymentID=:id");
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /** Mark a payment paid (DB side only; file record + notify are done by the caller). */
    public function recordPayment($id, string $paymentDate, string $method, string $proofPath, $amount = null): void
    {
        $fields = "status='Paid', paymentDate=:paymentDate, paymentMethod=:method, proofOfPayment=:proof";
        $params = ['paymentDate' => $paymentDate, 'method' => $method, 'proof' => $proofPath, 'id' => $id];
        if ($amount !== null && $amount !== '') {
            $fields .= ", amount=:amount";
            $params['amount'] = $amount;
        }
        $stmt = $this->db->prepare("UPDATE kreckSalaryPayment SET $fields WHERE kreckSalaryPaymentID=:id");
        $stmt->execute($params);
    }

    /**
     * Payments needing a reminder: not yet Paid and due within $withinDays
     * (negative dueDate offset = overdue). Used by the CLI reminder job.
     */
    public function dueReminders(int $withinDays = 3): array
    {
        $cutoff = date('Y-m-d', strtotime("+$withinDays days"));
        $sql = "SELECT sp.*, p.title, p.preferredName, p.surname, p.username, p.email,
                       DATEDIFF(sp.dueDate, CURDATE()) AS daysUntilDue
                FROM kreckSalaryPayment sp
                JOIN gibbonPerson p ON p.gibbonPersonID = sp.gibbonPersonID
                WHERE sp.status IN ('Pending','Finalized')
                  AND sp.dueDate IS NOT NULL
                  AND sp.dueDate <= :cutoff
                ORDER BY sp.dueDate ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['cutoff' => $cutoff]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function bumpReminderCount($id): void
    {
        $this->db->prepare("UPDATE kreckSalaryPayment SET reminderCount = reminderCount + 1 WHERE kreckSalaryPaymentID=:id")
            ->execute(['id' => $id]);
    }

    /** All salary definitions, joined to staff, for the management table. */
    public function listSalaries(): array
    {
        $sql = "SELECT s.*, p.title, p.preferredName, p.surname, p.username
                FROM kreckSalary s JOIN gibbonPerson p ON p.gibbonPersonID = s.gibbonPersonID
                ORDER BY s.status, p.surname, p.preferredName";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Resolve a staff member by username, then studentID-style staff id, then exact name. */
    public function resolveStaff(string $token): ?int
    {
        $token = trim($token);
        if ($token === '') return null;
        $stmt = $this->db->prepare(
            "SELECT p.gibbonPersonID FROM gibbonPerson p
             JOIN gibbonRole r ON r.gibbonRoleID = p.gibbonRoleIDPrimary
             WHERE r.category='Staff' AND (p.username=:t OR p.email=:t OR CONCAT(p.preferredName,' ',p.surname)=:t OR CONCAT(p.firstName,' ',p.surname)=:t)
             LIMIT 1"
        );
        $stmt->execute(['t' => $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (int) $row['gibbonPersonID'] : null;
    }

    /** Upsert a salary definition (by staff + overlapping active window is caller's concern). */
    public function saveSalary(array $d, int $userID): int
    {
        $ins = $this->db->prepare(
            "INSERT INTO kreckSalary
               (gibbonPersonID, amount, currency, frequency, payDay, startDate, endDate, status, notes, gibbonPersonIDCreator, timestampCreator)
             VALUES (:pid, :amount, :currency, :frequency, :payDay, :startDate, :endDate, :status, :notes, :creator, NOW())"
        );
        $ins->execute([
            'pid'       => $d['gibbonPersonID'],
            'amount'    => $d['amount'],
            'currency'  => $d['currency'] ?? null,
            'frequency' => $d['frequency'] ?? 'Monthly',
            'payDay'    => $d['payDay'] ?? null,
            'startDate' => $d['startDate'],
            'endDate'   => !empty($d['endDate']) ? $d['endDate'] : null,
            'status'    => $d['status'] ?? 'Active',
            'notes'     => $d['notes'] ?? null,
            'creator'   => $userID ?: null,
        ]);
        return (int) $this->db->lastInsertId();
    }
}
