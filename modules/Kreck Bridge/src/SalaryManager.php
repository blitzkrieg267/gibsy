<?php
/*
Kreck Bridge — SalaryManager.

Staff-salary record-keeping that extends SMS's Finance module without
duplicating it. kreckSalary holds the RECURRING definition (amount + frequency
+ pay day + active window); kreckSalaryPayment holds one instance PER PERIOD,
generated from the active definitions. Proof-of-payment, receipts, payments and
notifications reuse SMS's own machinery (FileUploader/FileHandler,
gibbonPayment, report.php receipts, NotificationSender) — done at the call sites
that have the container/session. This class is pure DB logic (Connection only),
so it is shared by the web pages and the CLI reminder job.

No namespace (SMS modules are not PSR-4 autoloaded); required directly.
*/

use Gibbon\Contracts\Database\Connection;

class KreckSalaryManager
{
    /** @var \PDO The raw PDO handle (SMS's Connection wrapper lacks prepare()). */
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
                  AND s.approvalStatus = 'Approved'
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

    /** Resolve a staff member by gibbonPersonID, username, email, or exact name. */
    public function resolveStaff(string $token): ?int
    {
        $token = trim($token);
        if ($token === '') return null;
        $stmt = $this->db->prepare(
            "SELECT p.gibbonPersonID FROM gibbonPerson p
             JOIN gibbonRole r ON r.gibbonRoleID = p.gibbonRoleIDPrimary
             WHERE r.category='Staff' AND (p.gibbonPersonID=:t OR p.username=:t OR p.email=:t OR CONCAT(p.preferredName,' ',p.surname)=:t OR CONCAT(p.firstName,' ',p.surname)=:t)
             LIMIT 1"
        );
        $stmt->execute(['t' => $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (int) $row['gibbonPersonID'] : null;
    }

    /**
     * All active staff for the searchable pick list. Returns enough detail to
     * disambiguate people who share a name or have non-obvious usernames:
     * display name, username, email, job title and staff type.
     */
    public function listStaff(): array
    {
        $stmt = $this->db->query(
            "SELECT p.gibbonPersonID, p.username, p.title, p.preferredName, p.surname,
                    p.email, st.jobTitle, st.type AS staffType
             FROM gibbonPerson p
             JOIN gibbonRole r ON r.gibbonRoleID = p.gibbonRoleIDPrimary
             LEFT JOIN gibbonStaff st ON st.gibbonPersonID = p.gibbonPersonID
             WHERE r.category='Staff' AND p.status='Full'
             ORDER BY p.surname, p.preferredName"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Normalise a money value to a strict float with exactly 2 decimal places, or null if invalid. */
    public static function normaliseAmount($value): ?float
    {
        if ($value === null) return null;
        $raw = trim((string) $value);
        if ($raw === '') return null;
        // Accept digits, one decimal point and an optional leading sign only.
        $raw = str_replace([',', ' '], '', $raw);
        if (!is_numeric($raw)) return null;
        $amount = round((float) $raw, 2);
        return $amount > 0 ? $amount : null;
    }

    /**
     * Create a salary definition. It is ALWAYS created as approvalStatus
     * 'Pending': finance enters it, but it is not payroll-eligible until a
     * school administrator approves it (see approveSalary()). `category` and
     * `tags` let finance classify the entry for later management.
     */
    public function saveSalary(array $d, int $userID): int
    {
        $ins = $this->db->prepare(
            "INSERT INTO kreckSalary
               (gibbonPersonID, amount, currency, frequency, payDay, startDate, endDate, status, category, tags, approvalStatus, notes, gibbonPersonIDCreator, timestampCreator)
             VALUES (:pid, :amount, :currency, :frequency, :payDay, :startDate, :endDate, :status, :category, :tags, 'Pending', :notes, :creator, NOW())"
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
            'category'  => !empty($d['category']) ? trim((string) $d['category']) : null,
            'tags'      => !empty($d['tags']) ? $this->normaliseTags($d['tags']) : null,
            'notes'     => $d['notes'] ?? null,
            'creator'   => $userID ?: null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Currency catalogue (ISO code => [symbol, label]), mirroring the set SMS
     * offers (Gibbon\Forms\FormFactory::createSelectCurrency). Stored value is
     * the 3-letter ISO code, so the kreckSalary.currency VARCHAR(3) column fits
     * and the symbol is derived for display.
     */
    public static function currencyCatalog(): array
    {
        return [
            'BWP' => ['P', 'Botswana Pula (P)'], 'USD' => ['$', 'U.S. Dollar ($)'],
            'EUR' => ['€', 'Euro (€)'], 'GBP' => ['£', 'British Pound (£)'],
            'ZAR' => ['R', 'South African Rand (R)'], 'NAD' => ['N$', 'Namibian Dollar (N$)'],
            'ZMW' => ['ZK', 'Zambian Kwacha (ZK)'], 'ZWL' => ['$', 'Zimbabwean Dollar ($)'],
            'KES' => ['KSh', 'Kenyan Shilling (KSh)'], 'TZS' => ['TSh', 'Tanzania Shilling (TSh)'],
            'UGX' => ['USh', 'Uganda Shilling (USh)'], 'NGN' => ['₦', 'Nigerian Naira (₦)'],
            'GHS' => ['GH₵', 'Ghanaian Cedi (GH₵)'], 'MWK' => ['MK', 'Malawian Kwacha (MK)'],
            'MZN' => ['MT', 'Mozambique Metical (MT)'], 'XAF' => ['FCFA', 'Central African Francs (FCFA)'],
            'XOF' => ['FCFA', 'West African Francs (FCFA)'], 'EGP' => ['£', 'Egyptian Pound (£)'],
            'MAD' => ['MAD', 'Moroccan Dirham (MAD)'], 'AED' => ['د.إ', 'U.A.E. Dirham (د.إ)'],
            'SAR' => ['﷼', 'Saudi Riyal (﷼)'], 'INR' => ['₹', 'Indian Rupee (₹)'],
            'CNY' => ['¥', 'Chinese Renminbi (¥)'], 'JPY' => ['¥', 'Japanese Yen (¥)'],
            'AUD' => ['A$', 'Australian Dollar (A$)'], 'CAD' => ['C$', 'Canadian Dollar (C$)'],
            'NZD' => ['$', 'New Zealand Dollar ($)'], 'CHF' => ['CHF', 'Swiss Franc (CHF)'],
            'SGD' => ['$', 'Singapore Dollar ($)'], 'HKD' => ['$', 'Hong Kong Dollar ($)'],
            'BRL' => ['R$', 'Brazilian Real (R$)'],
        ];
    }

    /** Symbol for an ISO currency code (falls back to the code itself). */
    public static function currencySymbol(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        $cat = self::currencyCatalog();
        return isset($cat[$code]) ? $cat[$code][0] : ($code !== '' ? $code : '');
    }

    /** The 3-letter ISO code at the front of an SMS currency setting ("USD $" => "USD"). */
    public static function currencyCodeFromSetting($setting): string
    {
        $tok = strtok(trim((string) $setting), ' ');
        return $tok !== false ? strtoupper($tok) : '';
    }

    /** Normalise a comma/semicolon-separated tag string: trim, de-dupe, re-join. */
    public static function normaliseTags($tags): ?string
    {
        if (is_array($tags)) { $parts = $tags; }
        else { $parts = preg_split('/[,;]+/', (string) $tags); }
        $clean = [];
        foreach ($parts as $t) {
            $t = trim((string) $t);
            if ($t !== '' && !in_array($t, $clean, true)) { $clean[] = $t; }
        }
        return empty($clean) ? null : implode(', ', $clean);
    }

    /** One salary definition joined to the staff member (for review/approval). */
    public function getSalary($id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, p.title, p.preferredName, p.surname, p.username, p.email
             FROM kreckSalary s JOIN gibbonPerson p ON p.gibbonPersonID = s.gibbonPersonID
             WHERE s.kreckSalaryID = :id"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Salary definitions awaiting a school-admin decision (oldest first). */
    public function pendingSalaries(): array
    {
        $stmt = $this->db->query(
            "SELECT s.*, p.title, p.preferredName, p.surname, p.username,
                    c.title AS cTitle, c.preferredName AS cPreferredName, c.surname AS cSurname
             FROM kreckSalary s
             JOIN gibbonPerson p ON p.gibbonPersonID = s.gibbonPersonID
             LEFT JOIN gibbonPerson c ON c.gibbonPersonID = s.gibbonPersonIDCreator
             WHERE s.approvalStatus = 'Pending'
             ORDER BY s.timestampCreator ASC, s.kreckSalaryID ASC"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Recently decided (approved/rejected) definitions, for the approval log. */
    public function recentDecisions(int $limit = 12): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT s.*, p.title, p.preferredName, p.surname, p.username,
                    a.title AS aTitle, a.preferredName AS aPreferredName, a.surname AS aSurname
             FROM kreckSalary s
             JOIN gibbonPerson p ON p.gibbonPersonID = s.gibbonPersonID
             LEFT JOIN gibbonPerson a ON a.gibbonPersonID = s.gibbonPersonIDApprover
             WHERE s.approvalStatus IN ('Approved','Rejected')
             ORDER BY s.approvalTimestamp DESC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Record a school-admin decision on a pending salary definition. */
    public function decideSalary($id, string $decision, int $approverID, ?string $note): bool
    {
        if (!in_array($decision, ['Approved', 'Rejected'], true)) { return false; }
        // Only a currently-Pending row can be decided (idempotent + race-safe).
        $stmt = $this->db->prepare(
            "UPDATE kreckSalary
             SET approvalStatus = :decision,
                 gibbonPersonIDApprover = :approver,
                 approvalTimestamp = NOW(),
                 approvalNote = :note
             WHERE kreckSalaryID = :id AND approvalStatus = 'Pending'"
        );
        $stmt->execute([
            'decision' => $decision,
            'approver' => $approverID ?: null,
            'note'     => ($note !== null && $note !== '') ? $note : null,
            'id'       => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Distinct non-empty categories already in use, for the entry datalist. */
    public function salaryCategories(): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT category FROM kreckSalary WHERE category IS NOT NULL AND category <> '' ORDER BY category"
        )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        return $rows;
    }

    /** Count of pending definitions (for the approval-page menu badge/summary). */
    public function pendingCount(): int
    {
        return (int) ($this->db->query("SELECT COUNT(*) FROM kreckSalary WHERE approvalStatus='Pending'")->fetchColumn() ?: 0);
    }
}
