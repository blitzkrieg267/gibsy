<?php
/*
Kreck Bridge — InstitutionImporter.

A thin orchestrator over SMS's native import engine (Gibbon\Data\Importer +
Gibbon\Data\ImportType). It does NOT re-implement validation, dedup, FK
resolution or file reading — it reuses SMS's engine and simply drives it from
either (a) an uploaded Excel/CSV file or (b) the KRECK in-app HTML table/form.

Both paths converge on run(): rows keyed by field machine-name are turned into an
in-memory CSV, fed to the real Importer, validated/dry-run or committed, and the
per-row logs + stats returned for the KRECK UI to render.

No namespace (SMS modules are not PSR-4 autoloaded); required directly.
*/

use Gibbon\Data\Importer;
use Gibbon\Data\ImportType;
use Gibbon\Data\PasswordPolicy;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Contracts\Database\Connection;
use Symfony\Component\Yaml\Yaml;

class KreckInstitutionImporter
{
    private $db;
    private $passwordPolicy;
    private $settingGateway;
    private $moduleImportDir;

    public function __construct(
        Connection $db,
        PasswordPolicy $passwordPolicy,
        SettingGateway $settingGateway,
        string $moduleImportDir
    ) {
        $this->db = $db;
        $this->passwordPolicy = $passwordPolicy;
        $this->settingGateway = $settingGateway;
        $this->moduleImportDir = rtrim($moduleImportDir, '/');
    }

    /**
     * The institution-seeding types KRECK exposes, in dependency order. KRECK
     * fee types ship in the module; the rest reuse SMS's built-in YAML types.
     */
    public function typeRegistry(): array
    {
        return [
            ['name' => 'schoolYears',      'label' => 'School Years',                 'group' => 'Structure', 'source' => 'native'],
            ['name' => 'departments',      'label' => 'Departments / Learning Areas', 'group' => 'Structure', 'source' => 'native'],
            // Staff are imported BEFORE year/form groups so that their heads of
            // year and tutors (staff usernames) resolve. Students stay later.
            ['name' => 'usersStaff',       'label' => 'Staff',                        'group' => 'People',    'source' => 'native'],
            ['name' => 'yearGroups',       'label' => 'Year Groups',                  'group' => 'Structure', 'source' => 'native'],
            ['name' => 'formGroups',       'label' => 'Form Groups',                  'group' => 'Structure', 'source' => 'native'],
            ['name' => 'schoolYearTerms',  'label' => 'School Year Terms',            'group' => 'Structure', 'source' => 'native'],
            ['name' => 'courses',          'label' => 'Courses',                      'group' => 'Teaching',  'source' => 'native'],
            ['name' => 'courseClasses',    'label' => 'Classes',                      'group' => 'Teaching',  'source' => 'native'],
            ['name' => 'spaces',           'label' => 'Spaces / Rooms',               'group' => 'Teaching',  'source' => 'native'],
            ['name' => 'timetableColumns', 'label' => 'Timetable Columns',            'group' => 'Teaching',  'source' => 'native'],
            ['name' => 'timetableColumnRows', 'label' => 'Timetable Periods',         'group' => 'Teaching',  'source' => 'native'],
            ['name' => 'timetableDays',    'label' => 'Timetable Days',               'group' => 'Teaching',  'source' => 'native'],
            ['name' => 'timetableDaysToDates', 'label' => 'Timetable Dates',          'group' => 'Teaching',  'source' => 'native'],
            ['name' => 'timetableClasses', 'label' => 'Class Timetable',               'group' => 'Teaching',  'source' => 'native'],
            ['name' => 'kreck_lmsAssignmentPlans', 'label' => 'LMS Assignment Plans', 'group' => 'Teaching',  'source' => 'module'],
            ['name' => 'usersBasic',       'label' => 'Students / Users',             'group' => 'People',    'source' => 'native'],
            ['name' => 'studentEnrolment', 'label' => 'Student Enrolment',            'group' => 'People',    'source' => 'native'],
            ['name' => 'courseClassEnrolment', 'label' => 'Class Enrolment',          'group' => 'People',    'source' => 'native'],
            ['name' => 'kreck_feeCategories', 'label' => 'Fee Categories',            'group' => 'Finance',   'source' => 'module'],
            ['name' => 'kreck_fees',          'label' => 'Fees',                      'group' => 'Finance',   'source' => 'module'],
        ];
    }

    public function registryEntry(string $name): ?array
    {
        foreach ($this->typeRegistry() as $e) {
            if ($e['name'] === $name) return $e;
        }
        return null;
    }

    /** Load an ImportType: KRECK module YAML first, else SMS's built-in. */
    public function loadType(string $name): ?ImportType
    {
        $modulePath = $this->moduleImportDir . '/' . $name . '.yml';
        if (is_file($modulePath)) {
            $data = Yaml::parse(file_get_contents($modulePath));
            return new ImportType($data, $this->passwordPolicy, $this->db, true);
        }
        // Native SMS import type. We replicate ImportType::loadImportType() here
        // instead of calling it, because its signature differs across SMS
        // versions: v31 requires a Gibbon\Forms\PersonalDataFieldSettings 4th
        // argument that does NOT exist before v31 — hard-referencing that class
        // throws "Class not found" on v30. Building the ImportType directly (its
        // matching constructor parameter is optional) works on both v30 and v31.
        $path = null;
        foreach ([
            ImportType::getCustomImportTypeDir($this->settingGateway),
            ImportType::getImportTypeDir($this->settingGateway),
        ] as $dir) {
            $candidate = rtrim((string) $dir, '/') . '/' . $name . '.yml';
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }
        if ($path === null) {
            return null;
        }
        $fileData = Yaml::parse(file_get_contents($path));

        // Supply PersonalDataFieldSettings only when this SMS version ships it
        // (v31+); on older versions the extra null argument is simply ignored.
        $personalDataFields = null;
        $pdfClass = 'Gibbon\\Forms\\PersonalDataFieldSettings';
        if (class_exists($pdfClass)) {
            $personalDataFields = new $pdfClass($this->settingGateway);
        }

        return new ImportType($fileData, $this->passwordPolicy, $this->db, true, $personalDataFields);
    }

    /**
     * Describe a type's fields for the KRECK HTML table/form: the columns the
     * user fills (data fields), with required/unique hints. Auto fields
     * (creator, generated timestamps, linked) are excluded from the UI.
     */
    public function describeFields(ImportType $importType, bool $withOptions = false): array
    {
        $cols = [];
        foreach ($importType->getAllFields() as $f) {
            if ($this->isAutoField($importType, $f)) {
                continue;
            }
            $rel = $importType->getField($f, 'relationship', []);
            $args = $importType->getField($f, 'args', []);
            $col = [
                'field'    => $f,
                'label'    => $importType->getField($f, 'name', $f),
                'desc'     => $importType->getField($f, 'desc', ''),
                'required' => $importType->isFieldRequired($f),
                'unique'   => $importType->isFieldUniqueKey($f),
                'filter'   => is_array($args) ? (string) ($args['filter'] ?? '') : '',
                // Relationship target table + the column it resolves on (if any),
                // so the in-app builder can suggest values entered in an earlier
                // step that feeds it (e.g. a Tutor matches a Staff username).
                'relTable' => is_array($rel) ? ($rel['table'] ?? null) : null,
                'relField' => is_array($rel) ? ($rel['field'] ?? null) : null,
            ];
            // For the in-app manual grid only: resolve a pick-list for fields
            // that reference an existing record (relationship) or a fixed set
            // (yes/no). Free-text fields (names, short names, notes, amounts)
            // return null and stay as text inputs. Bulk/workbook callers pass
            // $withOptions=false so they are unaffected.
            if ($withOptions) {
                $options = $this->fieldOptions($importType, $f);
                if ($options !== null) {
                    $col['options'] = $options;
                }
            }
            $cols[] = $col;
        }
        return $cols;
    }

    /**
     * Build a pick-list for one field, driven by LIVE SMS data, so the user
     * never has to know the CSV/DB structure. Returns a list of
     * ['value' => <what the importer matches on>, 'label' => <human label>],
     * or null when the field is genuinely free-text. The value is exactly the
     * human-readable key the native import resolves to an FK (e.g. a school
     * year name, a department name, a username), so the committed row is valid.
     * Fails soft: any lookup error is logged to diagnostics and the field
     * degrades to a text input rather than blocking the import.
     */
    private function fieldOptions(ImportType $importType, string $field): ?array
    {
        try {
            $args = $importType->getField($field, 'args', []);
            $filter = is_array($args) ? (string) ($args['filter'] ?? '') : '';

            // Fixed yes/no set.
            if ($filter === 'yesno') {
                return [
                    ['value' => 'Y', 'label' => 'Yes'],
                    ['value' => 'N', 'label' => 'No'],
                ];
            }

            // FK relationship -> list the existing records' match values.
            $rel = $importType->getField($field, 'relationship', '');
            if (is_array($rel) && !empty($rel['table']) && !empty($rel['field'])) {
                return $this->relationshipOptions((string) $rel['table'], (string) $rel['field']);
            }

            // School-year filter without an explicit relationship block.
            if ($filter === 'schoolyear') {
                return $this->relationshipOptions('gibbonSchoolYear', 'name');
            }

            return null;
        } catch (\Throwable $e) {
            if (class_exists('KreckBridgeDiagnostics', false)) {
                KreckBridgeDiagnostics::reportCaught($e, "build option list for field '$field'");
            }
            return null;
        }
    }

    /**
     * Fetch distinct match values from a related table for a dropdown. $fieldSpec
     * may be "field" or "username|email" (the native engine accepts the first);
     * we list by the first token. People get a friendly "Surname, Name (login)"
     * label. Table/column names come from trusted import YAML but are sanitised
     * defensively. Returns null if nothing is found (field stays free-text).
     */
    private function relationshipOptions(string $table, string $fieldSpec): ?array
    {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $matchField = preg_replace('/[^A-Za-z0-9_]/', '', explode('|', $fieldSpec)[0]);
        if ($table === '' || $matchField === '') {
            return null;
        }
        $pdo = $this->db->getConnection();

        if ($table === 'gibbonPerson') {
            $sql = "SELECT $matchField AS v,
                        TRIM(BOTH ', ' FROM CONCAT(COALESCE(surname,''), ', ', COALESCE(preferredName,''))) AS n
                    FROM gibbonPerson
                    WHERE $matchField IS NOT NULL AND $matchField <> ''
                    ORDER BY surname, preferredName LIMIT 2000";
        } else {
            $sql = "SELECT DISTINCT $matchField AS v, $matchField AS n
                    FROM $table
                    WHERE $matchField IS NOT NULL AND $matchField <> ''
                    ORDER BY $matchField LIMIT 2000";
        }

        $stmt = $pdo->query($sql);
        $opts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $v = (string) $r['v'];
            if ($v === '') {
                continue;
            }
            $n = trim((string) ($r['n'] ?? ''));
            $opts[] = [
                'value' => $v,
                'label' => ($n !== '' && $n !== $v) ? ($n . ' (' . $v . ')') : $v,
            ];
        }
        return $opts ?: null;
    }

    /** Resolve a gibbonPersonID to its username (for creator fields). */
    private function personUsername(int $personID): string
    {
        if ($personID <= 0) {
            return '';
        }
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("SELECT username FROM gibbonPerson WHERE gibbonPersonID = :id");
            $stmt->execute([':id' => $personID]);
            return (string) ($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            if (class_exists('KreckBridgeDiagnostics', false)) {
                KreckBridgeDiagnostics::reportCaught($e, 'resolve import creator username');
            }
            return '';
        }
    }

    /** Fields KRECK fills automatically (not shown in the manual UI). */
    private function isAutoField(ImportType $importType, string $field): bool
    {
        if ($importType->getField($field, 'function', '') !== '') return true;        // e.g. timestamp
        if ($importType->getField($field, 'linked', '') !== '') return true;          // linked copy
        if (preg_match('/(Creator|IDUpdate|Updater)$/', $field)) return true;         // audit person fields
        return false;
    }

    /**
     * Run an import for one type. $rows are assoc arrays keyed by field
     * machine-name. $live=false → dry-run (validate only). Returns results.
     */
    public function run(string $typeName, array $rows, bool $live, int $sessionUserID, string $mode = 'sync'): array
    {
        $importType = $this->loadType($typeName);
        if (!$importType) {
            return ['ok' => false, 'error' => "Unknown import type: $typeName", 'logs' => [], 'stats' => []];
        }
        if (empty($rows)) {
            return ['ok' => false, 'error' => 'No rows to import.', 'logs' => [], 'stats' => []];
        }
        if ($typeName === 'kreck_lmsAssignmentPlans') {
            $errors = $this->validateAssignmentPlans($rows);
            if (!empty($errors)) {
                return [
                    'ok' => false,
                    'live' => $live,
                    'type' => $typeName,
                    'rowCount' => count($rows),
                    'errors' => $errors,
                    'warnings' => [],
                    'logs' => $errors,
                    'stats' => [],
                ];
            }
        }

        // Synthesise a CSV from the data fields present, so we can reuse the
        // engine's row machinery unchanged.
        $dataFields = [];
        foreach ($importType->getAllFields() as $f) {
            if (!$this->isAutoField($importType, $f)) {
                $dataFields[] = $f;
            }
        }
        $csv = $this->rowsToCsv($dataFields, $rows);

        $importer = new Importer($this->db);
        $importer->mode = $mode; // 'sync' (update+insert), 'insert', or 'update'
        $importer->readCSVString($csv);

        // Creator fields may resolve by username|email (relationship) or store a
        // raw gibbonPersonID. Resolve the session user's username once so either
        // shape of creator column gets a value the engine can accept.
        $creatorUsername = $this->personUsername($sessionUserID);

        // Build columnOrder (indexed by field iteration count) + custom values.
        $columnOrder = [];
        $customValues = [];
        $headerIndex = array_flip($dataFields); // field => CSV column index
        $fc = 0;
        foreach ($importType->getAllFields() as $f) {
            if (isset($headerIndex[$f])) {
                $columnOrder[$fc] = $headerIndex[$f];
            } elseif ($importType->getField($f, 'function', '') !== '') {
                $columnOrder[$fc] = Importer::COLUMN_DATA_FUNCTION;
            } elseif (preg_match('/(Creator|IDUpdate|Updater)$/', $f)) {
                $columnOrder[$fc] = Importer::COLUMN_DATA_CUSTOM;
                // If the field resolves people by username|email, inject the
                // username; otherwise inject the raw gibbonPersonID.
                $hasRel = $importType->getField($f, 'relationship', '') !== '';
                $customValues[$fc] = ($hasRel && $creatorUsername !== '')
                    ? $creatorUsername : (string) $sessionUserID;
            } else {
                $columnOrder[$fc] = Importer::COLUMN_DATA_SKIP;
            }
            $fc++;
        }

        $importer->buildTableData($importType, $columnOrder, $customValues);
        $ok = $importer->importIntoDatabase($importType, $live);
        $logs = $importer->getLogs();

        $errors = array_values(array_filter($logs, fn($l) => ($l['type'] ?? '') === 'error'));
        $warnings = array_values(array_filter($logs, fn($l) => ($l['type'] ?? '') === 'warning'));

        return [
            'ok'       => $ok && empty($errors),
            'live'     => $live,
            'type'     => $typeName,
            'rowCount' => count($rows),
            'errors'   => $errors,
            'warnings' => $warnings,
            'logs'     => $logs,
        ];
    }

    /**
     * Enforce date-pair and ordering rules that cannot be expressed by the
     * native row-level ImportType filters.
     *
     * @param array $rows
     * @return array
     */
    private function validateAssignmentPlans(array $rows): array
    {
        $errors = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $addError = static function (string $field, string $message) use (&$errors, $rowNumber): void {
                $errors[] = ['type' => 'error', 'row' => $rowNumber, 'field_name' => $field, 'info' => $message];
            };

            if ((int) ($row['sectionNumber'] ?? -1) < 0) {
                $addError('LMS Section Number', 'Enter zero or a positive section number.');
            }
            if (!is_numeric($row['gradeMax'] ?? null) || (float) $row['gradeMax'] <= 0) {
                $addError('Maximum Grade', 'Enter a number greater than zero.');
            }

            $times = [];
            foreach ([
                ['openDate', 'openTime', 'Opens Date/Time', false],
                ['dueDate', 'dueTime', 'Due Date/Time', true],
                ['cutoffDate', 'cutoffTime', 'Cutoff Date/Time', false],
            ] as [$dateField, $timeField, $label, $required]) {
                $date = trim((string) ($row[$dateField] ?? ''));
                $time = trim((string) ($row[$timeField] ?? ''));
                if ($date === '' && $time === '' && !$required) {
                    $times[$dateField] = null;
                    continue;
                }
                if ($date === '' || $time === '') {
                    $addError($label, 'Enter both the date and time, or leave both blank.');
                    $times[$dateField] = null;
                    continue;
                }
                $parsed = $this->parseWorkbookDateTime($date, $time);
                if ($parsed === null) {
                    $addError($label, 'Use a valid YYYY-MM-DD date and 24-hour HH:MM time.');
                    $times[$dateField] = null;
                    continue;
                }
                $times[$dateField] = $parsed;
            }

            if ($times['openDate'] !== null && $times['dueDate'] !== null && $times['openDate'] >= $times['dueDate']) {
                $addError('Opens Date/Time', 'The assignment must open before its due date/time.');
            }
            if ($times['cutoffDate'] !== null && $times['dueDate'] !== null && $times['cutoffDate'] < $times['dueDate']) {
                $addError('Cutoff Date/Time', 'The cutoff cannot be before the due date/time.');
            }
        }
        return $errors;
    }

    /** Parse strict workbook date/time values to a comparable timestamp. */
    private function parseWorkbookDateTime(string $date, string $time): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)) {
            return null;
        }
        $normalisedTime = strlen($time) === 5 ? $time . ':00' : $time;
        $datetime = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $date . ' ' . $normalisedTime,
            new \DateTimeZone('UTC')
        );
        $parseErrors = \DateTimeImmutable::getLastErrors();
        if (!$datetime || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
                || $datetime->format('Y-m-d H:i:s') !== $date . ' ' . $normalisedTime) {
            return null;
        }
        return $datetime->getTimestamp();
    }

    /**
     * Parse an uploaded Excel/CSV/ODS file into [headers, rows(assoc by header)].
     * Reuses PhpSpreadsheet (bundled) for spreadsheets; native CSV parsing otherwise.
     */
    public function parseUploadedFile(string $filePath, string $originalName = ''): array
    {
        $ext = strtolower(pathinfo($originalName ?: $filePath, PATHINFO_EXTENSION));
        $data = [];
        if (in_array($ext, ['xlsx', 'xls', 'ods'])) {
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath)->getActiveSheet();
            $data = $sheet->toArray(null, true, true, false);
        } else {
            foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $data[] = str_getcsv($line);
            }
        }
        $headers = array_map(fn($h) => trim((string) $h), array_shift($data) ?: []);
        $rows = [];
        foreach ($data as $r) {
            if (count(array_filter($r, fn($v) => $v !== '' && $v !== null)) === 0) continue; // blank row
            $row = [];
            foreach ($headers as $i => $h) {
                if ($h === '') continue;
                $row[$h] = $r[$i] ?? '';
            }
            $rows[] = $row;
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Map header-keyed rows to field-keyed rows by matching each header to a
     * field machine-name or human label (case-insensitive). Unmatched headers
     * are dropped. Returns ['rows' => [...], 'mapping' => header=>field|null].
     */
    public function matchToFields(ImportType $importType, array $headers, array $rows): array
    {
        $byName = [];
        $byLabel = [];
        foreach ($importType->getAllFields() as $f) {
            $byName[strtolower($f)] = $f;
            $byLabel[strtolower($importType->getField($f, 'name', $f))] = $f;
        }
        $mapping = [];
        foreach ($headers as $h) {
            $k = strtolower(trim($h));
            $mapping[$h] = $byName[$k] ?? $byLabel[$k] ?? null;
        }
        $out = [];
        foreach ($rows as $r) {
            $fr = [];
            foreach ($r as $h => $v) {
                if (!empty($mapping[$h])) $fr[$mapping[$h]] = $v;
            }
            $out[] = $fr;
        }
        return ['rows' => $out, 'mapping' => $mapping];
    }

    /** Excel-safe, stable sheet title for a type label (used writing + reading). */
    public function sheetTitle(string $label): string
    {
        return substr(preg_replace('#[:\\\\/?*\[\]]#', '-', $label), 0, 31);
    }

    /** Generate a blank multi-sheet institution workbook (one sheet per type). */
    public function generateWorkbookFile(string $path): void
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $first = true;
        foreach ($this->typeRegistry() as $e) {
            $it = $this->loadType($e['name']);
            if (!$it) continue;
            $cols = $this->describeFields($it);
            $sheet = $first ? $ss->getActiveSheet() : $ss->createSheet();
            $first = false;
            $sheet->setTitle($this->sheetTitle($e['label']));
            $col = 1;
            foreach ($cols as $c) {
                // Header = exact field label (so re-upload matches); required marked by bold.
                $cell = $sheet->getCellByColumnAndRow($col, 1);
                $cell->setValue($c['label']);
                if ($c['desc'] !== '') {
                    $cell->getComment()->getText()->createTextRun($c['desc']);
                }
                if ($c['required']) {
                    $sheet->getStyleByColumnAndRow($col, 1)->getFont()->setBold(true);
                }
                $format = $this->excelNumberFormat($e['name'], $c['field']);
                if ($format !== null) {
                    $sheet->getStyleByColumnAndRow($col, 2, $col, 1000)
                        ->getNumberFormat()->setFormatCode($format);
                }
                $col++;
            }
            $sheet->freezePane('A2');
            $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
            $sheet->getDefaultColumnDimension()->setWidth(20);
        }
        $guide = $ss->createSheet();
        $guide->setTitle('Read Me');
        $guide->fromArray([
            ['KRECK Institution Intake Workbook'],
            ['Keep the generated sheet names and header row unchanged. Leave unused sheets empty.'],
            ['Enter dates as YYYY-MM-DD and times in 24-hour HH:MM format. The SMS site timezone is used when sending schedules to LMS.'],
            ['STG Lesson Timetable rows are mapped to dated SMS timetable sessions. The importer uses the active timetable assigned to each class year group; if none exists for the school year, it creates one. Native SMS timetable sheets still require their referenced timetable, days, and periods to exist.'],
            ['Import order is built into this workbook: school year, institution structure, classes, spaces and timetable, assignment plans, people, enrolments, and fees.'],
            ['LMS Assignment Plans are optional. Rows in STG Assignments are mapped to KRECK LMS Assignment Plans for existing classes. Due date and time are required; opening and cutoff date-times are optional but must be supplied as complete pairs.'],
            ['Use the class timetable sheets to map SMS timetable periods and dates to classes. Class timetable rows require the referenced class, timetable day, and period to exist.'],
            ['Validate the complete workbook in SMS before committing it.'],
        ], null, 'A1');
        $guide->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $guide->getColumnDimension('A')->setWidth(115);
        $guide->getStyle('A1:A8')->getAlignment()->setWrapText(true);
        $guide->getRowDimension(1)->setRowHeight(26);
        for ($row = 2; $row <= 8; $row++) {
            $guide->getRowDimension($row)->setRowHeight(36);
        }
        $ss->setActiveSheetIndex(0);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($path);
    }

    /** Excel display formats must round-trip through PhpSpreadsheet as dates/times. */
    private function excelNumberFormat(string $typeName, string $field): ?string
    {
        if ($typeName === 'schoolYears' && in_array($field, ['firstDay', 'lastDay'], true)) {
            return 'yyyy-mm-dd';
        }
        if ($typeName === 'schoolYearTerms' && in_array($field, ['firstDay', 'lastDay'], true)) {
            return 'yyyy-mm-dd';
        }
        if ($typeName === 'timetableColumnRows' && in_array($field, ['timeStart', 'timeEnd'], true)) {
            return 'hh:mm';
        }
        if ($typeName === 'timetableDaysToDates' && $field === 'date') {
            return 'yyyy-mm-dd';
        }
        if ($typeName === 'kreck_lmsAssignmentPlans') {
            if (in_array($field, ['openDate', 'dueDate', 'cutoffDate'], true)) {
                return 'yyyy-mm-dd';
            }
            if (in_array($field, ['openTime', 'dueTime', 'cutoffTime'], true)) {
                return 'hh:mm';
            }
            if ($field === 'gradeMax') {
                return '0.00';
            }
            if ($field === 'sectionNumber') {
                return '0';
            }
        }
        return null;
    }

    /** Parse an uploaded multi-sheet workbook into [typeName => field-keyed rows], in dependency order. */
    public function parseWorkbookSheets(string $filePath): array
    {
        $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $titleToType = [];
        foreach ($this->typeRegistry() as $e) {
            $titleToType[strtolower($this->sheetTitle($e['label']))] = $e['name'];
        }
        $stagingSheetTitles = [
            'stg lesson timetable' => 'stg_lessonTimetable',
            'stg weekly timetable' => 'stg_weeklyTimetable',
            'stg assignments' => 'stg_assignments',
        ];
        $found = [];
        foreach ($ss->getWorksheetIterator() as $sheet) {
            $sheetTitle = strtolower(trim($sheet->getTitle()));
            $typeName = $titleToType[$sheetTitle] ?? $stagingSheetTitles[$sheetTitle] ?? null;
            if (!$typeName) continue;
            $data = $sheet->toArray(null, true, true, false);
            $headers = array_map(fn($h) => trim((string) $h), array_shift($data) ?: []);
            $rows = [];
            foreach ($data as $r) {
                if (count(array_filter($r, fn($v) => $v !== '' && $v !== null)) === 0) continue;
                $row = [];
                foreach ($headers as $i => $h) {
                    if ($h === '') continue;
                    $row[$h] = $r[$i] ?? '';
                }
                $rows[] = $row;
            }
            if (empty($rows)) continue;
            if (strpos($typeName, 'stg_') === 0) {
                $found[$typeName] = $rows;
            } else {
                $it = $this->loadType($typeName);
                $matched = $this->matchToFields($it, $headers, $rows);
                $found[$typeName] = $matched['rows'];
            }
        }
        // Return in registry (dependency) order.
        $ordered = [];
        foreach ($this->typeRegistry() as $e) {
            if (isset($found[$e['name']])) $ordered[$e['name']] = $found[$e['name']];
        }
        foreach (['stg_lessonTimetable', 'stg_weeklyTimetable', 'stg_assignments'] as $stagingType) {
            if (isset($found[$stagingType])) $ordered[$stagingType] = $found[$stagingType];
        }
        return $ordered;
    }

    private function stagingError(int $row, string $field, string $message): array
    {
        return ['type' => 'error', 'row' => $row, 'field_name' => $field, 'info' => $message];
    }

    private function stagingResult(string $type, array $rows, bool $live, array $errors = [], array $warnings = []): array
    {
        return [
            'ok' => empty($errors),
            'live' => $live,
            'type' => $type,
            'rowCount' => count($rows),
            'errors' => $errors,
            'warnings' => $warnings,
            'logs' => array_merge($errors, $warnings),
            'stats' => [],
        ];
    }

    private function normaliseStagingDate($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $value = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $date->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }

    private function normaliseStagingTime($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }
        $value = trim((string) $value);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
            return null;
        }
        return substr($value, 0, 5);
    }

    private function timetableForStagingYear(string $schoolYear, array $yearGroupIDs): array
    {
        $pdo = $this->db->getConnection();
        $yearQuery = $pdo->prepare(
            'SELECT gibbonSchoolYearID, firstDay, lastDay
             FROM gibbonSchoolYear WHERE name=:name'
        );
        $yearQuery->execute(['name' => $schoolYear]);
        $year = $yearQuery->fetch(\PDO::FETCH_ASSOC);
        if (!$year) {
            throw new \RuntimeException('School year "' . $schoolYear . '" does not exist in SMS. Import or create it before importing staged lessons.');
        }

        $timetableQuery = $pdo->prepare(
            "SELECT gibbonTTID, name, nameShort, gibbonYearGroupIDList
             FROM gibbonTT
             WHERE gibbonSchoolYearID=:schoolYearID AND active='Y'
             ORDER BY gibbonTTID"
        );
        $timetableQuery->execute(['schoolYearID' => $year['gibbonSchoolYearID']]);
        $timetables = $timetableQuery->fetchAll(\PDO::FETCH_ASSOC);

        $requiredGroups = array_fill_keys(array_map('strval', $yearGroupIDs), true);
        $matches = array_values(array_filter($timetables, static function ($timetable) use ($requiredGroups) {
            $availableGroups = array_fill_keys(
                array_filter(array_map('trim', explode(',', (string) $timetable['gibbonYearGroupIDList']))),
                true
            );
            return !empty($requiredGroups) && empty(array_diff_key($requiredGroups, $availableGroups));
        }));
        if (count($matches) === 1) {
            return [$year, $matches[0]];
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(
                'Multiple active timetables cover the staged year groups for "' . $schoolYear
                . '". Leave exactly one active timetable assigned to each staged year group.'
            );
        }
        if (empty($requiredGroups) && count($timetables) === 1) {
            return [$year, $timetables[0]];
        }
        foreach ($timetables as $timetable) {
            $availableGroups = array_fill_keys(
                array_filter(array_map('trim', explode(',', (string) $timetable['gibbonYearGroupIDList']))),
                true
            );
            if (!empty(array_intersect_key($requiredGroups, $availableGroups))) {
                throw new \RuntimeException(
                    'No single active timetable covers all staged year groups for "' . $schoolYear
                    . '", and at least one group is already assigned elsewhere. Correct the SMS timetable year-group assignments before importing.'
                );
            }
        }
        if (empty($requiredGroups) && !empty($timetables)) {
            throw new \RuntimeException(
                'The staged classes have no year-group mapping and multiple active timetables exist for "'
                . $schoolYear . '".'
            );
        }

        $groups = implode(',', array_map('strval', $yearGroupIDs));
        $baseName = 'KRECK Lessons ' . $schoolYear;
        if (strlen($baseName) > 26) {
            throw new \RuntimeException('School year "' . $schoolYear . '" is too long to create a valid SMS timetable name.');
        }
        $nameExists = $pdo->prepare(
            'SELECT COUNT(*) FROM gibbonTT
             WHERE gibbonSchoolYearID=:schoolYearID AND (name=:name OR nameShort=:nameShort)'
        );
        for ($suffix = 1; $suffix <= 999; ++$suffix) {
            $candidateName = $suffix === 1 ? $baseName : $baseName . ' ' . $suffix;
            $candidateShort = 'T' . str_pad((string) $suffix, 3, '0', STR_PAD_LEFT);
            $nameExists->execute([
                'schoolYearID' => $year['gibbonSchoolYearID'],
                'name' => $candidateName,
                'nameShort' => $candidateShort,
            ]);
            if ((int) $nameExists->fetchColumn() === 0) {
                $name = $candidateName;
                break;
            }
        }
        if (!isset($name)) {
            throw new \RuntimeException('No unique four-character timetable short name is available for school year "' . $schoolYear . '".');
        }

        $nameShort = $candidateShort;
        $insert = $pdo->prepare(
            "INSERT INTO gibbonTT
                (gibbonSchoolYearID, name, nameShort, nameShortDisplay, active, gibbonYearGroupIDList)
             VALUES (:schoolYearID, :name, :nameShort, :nameShortDisplay, 'Y', :yearGroups)"
        );
        $insert->execute([
            'schoolYearID' => $year['gibbonSchoolYearID'],
            'name' => $name,
            'nameShort' => $nameShort,
            'nameShortDisplay' => 'KRECK',
            'yearGroups' => $groups,
        ]);
        return [$year, [
            'gibbonTTID' => (int) $pdo->lastInsertId(),
            'name' => $name,
            'nameShort' => $nameShort,
            'gibbonYearGroupIDList' => $groups,
        ]];
    }

    private function buildStagingTimetableImports(array $rows): array
    {
        $errors = [];
        $warnings = [];
        $records = [];
        $sourceRefs = [];
        $overlapIndex = [];
        $classes = [];

        $pdo = $this->db->getConnection();
        $classQuery = $pdo->query(
            'SELECT sy.name AS schoolYear, c.nameShort AS courseShort, cc.nameShort AS classShort,
                    c.gibbonYearGroupIDList
             FROM gibbonCourseClass cc
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             JOIN gibbonSchoolYear sy ON sy.gibbonSchoolYearID=c.gibbonSchoolYearID'
        );
        foreach ($classQuery->fetchAll(\PDO::FETCH_ASSOC) as $class) {
            $key = $class['schoolYear'] . '|' . $class['courseShort'] . '|' . $class['classShort'];
            $classes[$key] = $class;
        }

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $status = strtolower(trim((string) ($row['Status'] ?? '')));
            if ($status !== '' && !in_array($status, ['planned', 'scheduled'], true)) {
                $errors[] = $this->stagingError($rowNumber, 'Status', 'Only Planned or Scheduled lesson rows can be imported.');
                continue;
            }

            $schoolYear = trim((string) ($row['School Year'] ?? ''));
            $courseShort = trim((string) ($row['Course Short Name'] ?? ''));
            $classShort = trim((string) ($row['Class Short Name'] ?? ''));
            $sourceRef = trim((string) ($row['Lesson Source Ref'] ?? ''));
            $date = $this->normaliseStagingDate($row['Lesson Date'] ?? '');
            $start = $this->normaliseStagingTime($row['Start Time'] ?? '');
            $end = $this->normaliseStagingTime($row['End Time'] ?? '');
            foreach ([
                'School Year' => $schoolYear,
                'Course Short Name' => $courseShort,
                'Class Short Name' => $classShort,
                'Lesson Source Ref' => $sourceRef,
            ] as $field => $value) {
                if ($value === '') {
                    $errors[] = $this->stagingError($rowNumber, $field, 'This field is required to map the lesson to a SMS class.');
                }
            }
            if ($date === null) {
                $errors[] = $this->stagingError($rowNumber, 'Lesson Date', 'Enter a valid Excel date displayed as YYYY-MM-DD.');
            }
            if ($start === null || $end === null) {
                $errors[] = $this->stagingError($rowNumber, 'Start Time / End Time', 'Enter valid Excel times displayed as HH:MM.');
            } elseif ($start >= $end) {
                $errors[] = $this->stagingError($rowNumber, 'Start Time / End Time', 'The lesson end time must be after its start time.');
            }
            if ($schoolYear === '' || $courseShort === '' || $classShort === '' || $sourceRef === '' || $date === null || $start === null || $end === null || $start >= $end) {
                continue;
            }

            $sourceKey = $schoolYear . '|' . $sourceRef;
            if (isset($sourceRefs[$sourceKey])) {
                $errors[] = $this->stagingError($rowNumber, 'Lesson Source Ref', 'This source reference is duplicated in the staged timetable.');
            }
            $sourceRefs[$sourceKey] = true;

            $classKey = $schoolYear . '|' . $courseShort . '|' . $classShort;
            if (!isset($classes[$classKey])) {
                $errors[] = $this->stagingError($rowNumber, 'Class Short Name', 'The course/class pair does not exist in SMS for this school year.');
                continue;
            }

            $startMinutes = ((int) substr($start, 0, 2) * 60) + (int) substr($start, 3, 2);
            $endMinutes = ((int) substr($end, 0, 2) * 60) + (int) substr($end, 3, 2);
            $resources = [
                'class:' . $classKey,
                'teacher:' . strtolower(trim((string) ($row['Teacher Username'] ?? ''))),
                'room:' . strtolower(trim((string) ($row['Room / Location'] ?? ''))),
            ];
            foreach ($resources as $resource) {
                if (substr($resource, -1) === ':') continue;
                $resourceKey = $date . '|' . $resource;
                foreach ($overlapIndex[$resourceKey] ?? [] as [$otherStart, $otherEnd, $otherRow]) {
                    if ($startMinutes < $otherEnd && $endMinutes > $otherStart) {
                        $errors[] = $this->stagingError($rowNumber, 'Start Time / End Time', 'This lesson overlaps row ' . $otherRow . ' for the same class, teacher, or room.');
                        break;
                    }
                }
                $overlapIndex[$resourceKey][] = [$startMinutes, $endMinutes, $rowNumber];
            }

            $courseYearGroups = $classes[$classKey]['gibbonYearGroupIDList'];
            $records[] = [
                'row' => $rowNumber,
                'schoolYear' => $schoolYear,
                'courseShort' => $courseShort,
                'classShort' => $classShort,
                'sourceRef' => $sourceRef,
                'date' => $date,
                'start' => $start,
                'end' => $end,
                'room' => trim((string) ($row['Room / Location'] ?? '')),
                'yearGroupIDs' => array_filter(array_map('trim', explode(',', (string) $courseYearGroups))),
            ];
        }

        if (!empty($errors)) {
            return ['rows' => [], 'errors' => $errors, 'warnings' => []];
        }

        $yearGroups = [];
        foreach ($records as $record) {
            foreach ($record['yearGroupIDs'] as $groupID) {
                $yearGroups[$record['schoolYear']][(string) $groupID] = (string) $groupID;
            }
        }
        $timetables = [];
        $activeCounts = [];
        $activeCountQuery = $pdo->prepare(
            "SELECT COUNT(*) FROM gibbonTT tt
             JOIN gibbonSchoolYear sy ON sy.gibbonSchoolYearID=tt.gibbonSchoolYearID
             WHERE sy.name=:schoolYear AND tt.active='Y'"
        );
        foreach (array_unique(array_column($records, 'schoolYear')) as $schoolYear) {
            $activeCountQuery->execute(['schoolYear' => $schoolYear]);
            $activeCounts[$schoolYear] = (int) $activeCountQuery->fetchColumn();
            if ($activeCounts[$schoolYear] === 0) {
                $key = $schoolYear . '|all';
                [$year, $timetable] = $this->timetableForStagingYear(
                    $schoolYear,
                    array_values($yearGroups[$schoolYear] ?? [])
                );
                $timetables[$key] = ['year' => $year, 'timetable' => $timetable];
            }
        }
        foreach ($records as &$record) {
            $groups = array_values(array_unique(array_map('strval', $record['yearGroupIDs'])));
            sort($groups, SORT_STRING);
            if ($activeCounts[$record['schoolYear']] === 0) {
                $record['timetableKey'] = $record['schoolYear'] . '|all';
                continue;
            }
            $signature = implode(',', $groups);
            $key = $record['schoolYear'] . '|' . ($signature === '' ? 'none' : $signature);
            if (!isset($timetables[$key])) {
                [$year, $timetable] = $this->timetableForStagingYear($record['schoolYear'], $groups);
                $timetables[$key] = ['year' => $year, 'timetable' => $timetable];
            }
            $record['timetableKey'] = $key;
        }
        unset($record);
        foreach ($records as $record) {
            $year = $timetables[$record['timetableKey']]['year'];
            if ($record['date'] < $year['firstDay'] || $record['date'] > $year['lastDay']) {
                $errors[] = $this->stagingError($record['row'], 'Lesson Date', 'The lesson date is outside the configured SMS school year.');
            }
        }
        if (!empty($errors)) {
            return ['rows' => [], 'errors' => $errors, 'warnings' => []];
        }

        $spaceNames = [];
        foreach ($pdo->query('SELECT name FROM gibbonSpace')->fetchAll(\PDO::FETCH_COLUMN) as $spaceName) {
            $spaceNames[(string) $spaceName] = true;
        }
        $generated = [
            'timetableColumns' => [],
            'timetableColumnRows' => [],
            'timetableDays' => [],
            'timetableDaysToDates' => [],
            'timetableClasses' => [],
        ];
        $columnData = [];
        $periodData = [];
        $dayData = [];
        $dayDates = [];
        $classData = [];
        $unmappedRooms = [];
        foreach (array_unique(array_column($records, 'schoolYear')) as $schoolYear) {
            $columnName = 'KRECK Lessons ' . $schoolYear;
            $columnShort = 'C' . strtoupper(substr(hash('sha256', $schoolYear), 0, 3));
            $columnQuery = $pdo->prepare(
                'SELECT gibbonTTColumnID, name, nameShort FROM gibbonTTColumn WHERE nameShort=:nameShort OR name=:name'
            );
            $columnQuery->execute(['nameShort' => $columnShort, 'name' => $columnName]);
            $existingColumn = $columnQuery->fetch(\PDO::FETCH_ASSOC);
            if ($existingColumn && ($existingColumn['name'] !== $columnName || $existingColumn['nameShort'] !== $columnShort)) {
                $errors[] = $this->stagingError(2, 'School Year', 'A timetable column name conflicts with the generated KRECK column. Rename the conflicting SMS column and retry.');
                break;
            }
            $columnData[$schoolYear] = ['name' => $columnName, 'nameShort' => $columnShort];
        }
        if (!empty($errors)) {
            return ['rows' => [], 'errors' => $errors, 'warnings' => []];
        }

        $periodKeys = [];
        $dayIdentities = [];
        foreach ($records as $record) {
            $periodKey = $record['schoolYear'] . '|' . $record['start'] . '|' . $record['end'];
            $periodKeys[$periodKey] = [
                'schoolYear' => $record['schoolYear'],
                'start' => $record['start'],
                'end' => $record['end'],
            ];
            $timetable = $timetables[$record['timetableKey']]['timetable'];
            $dayKey = $record['schoolYear'] . '|' . $timetable['gibbonTTID'] . '|' . $record['date'];
            $dayIdentities[$dayKey] = [
                'schoolYear' => $record['schoolYear'],
                'timetableID' => $timetable['gibbonTTID'],
                'timetableNameShort' => $timetable['nameShort'],
                'columnShort' => $columnData[$record['schoolYear']]['nameShort'],
                'date' => $record['date'],
            ];
        }
        ksort($periodKeys);
        $periodShortByKey = [];
        $periodNumber = 0;
        foreach ($periodKeys as $periodKey => $period) {
            ++$periodNumber;
            if ($periodNumber > 999) {
                return ['rows' => [], 'errors' => [$this->stagingError(2, 'Start Time / End Time', 'The staged timetable has more unique periods than SMS can name with four-character short names.')], 'warnings' => []];
            }
            $periodShortByKey[$periodKey] = 'P' . str_pad((string) $periodNumber, 3, '0', STR_PAD_LEFT);
            $periodData[$periodKey] = [
                'Timetable Column' => $columnData[$period['schoolYear']]['nameShort'],
                'Name' => substr($period['start'], 0, 5) . '-' . substr($period['end'], 0, 5),
                'Short Name' => $periodShortByKey[$periodKey],
                'Start Time' => $period['start'],
                'End Time' => $period['end'],
                'Type' => 'Lesson',
            ];
        }
        ksort($dayIdentities);
        $existingDayRows = $pdo->query(
            'SELECT day.gibbonTTID, day.name, day.nameShort, columnTable.nameShort AS columnShort
             FROM gibbonTTDay day
             JOIN gibbonTTColumn columnTable ON columnTable.gibbonTTColumnID=day.gibbonTTColumnID'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $usedDayShortNames = [];
        foreach ($existingDayRows as $existingDayRow) {
            $usedDayShortNames[$existingDayRow['nameShort']][] = $existingDayRow;
        }
        $dayShortByKey = [];
        $nextDayNumber = 1;
        foreach ($dayIdentities as $dayKey => $identity) {
            $dayName = $identity['date'];
            $matchingExisting = null;
            foreach ($usedDayShortNames as $shortName => $existingRows) {
                foreach ($existingRows as $existingDayRow) {
                    if ((string) $existingDayRow['gibbonTTID'] === (string) $identity['timetableID']
                            && $existingDayRow['name'] === $dayName
                            && $existingDayRow['columnShort'] === $identity['columnShort']
                            && count($existingRows) === 1) {
                        $matchingExisting = $shortName;
                        break 2;
                    }
                }
            }
            if ($matchingExisting !== null) {
                $dayShortByKey[$dayKey] = $matchingExisting;
                continue;
            }
            do {
                if ($nextDayNumber > 999) {
                    return ['rows' => [], 'errors' => [$this->stagingError(2, 'Lesson Date', 'The staged timetable has more unique dates than SMS can name with four-character short names.')], 'warnings' => []];
                }
                $candidate = 'D' . str_pad((string) $nextDayNumber++, 3, '0', STR_PAD_LEFT);
            } while (isset($usedDayShortNames[$candidate]));
            $dayShortByKey[$dayKey] = $candidate;
            $usedDayShortNames[$candidate] = [[
                'gibbonTTID' => $identity['timetableID'],
                'name' => $dayName,
                'nameShort' => $candidate,
                'columnShort' => $identity['columnShort'],
            ]];
        }
        $dayTimetables = [];
        foreach ($records as $record) {
            $schoolYear = $record['schoolYear'];
            $timetable = $timetables[$record['timetableKey']]['timetable'];
            $columnShort = $columnData[$schoolYear]['nameShort'];

            $periodKey = $schoolYear . '|' . $record['start'] . '|' . $record['end'];
            $periodShort = $periodShortByKey[$periodKey];

            $dayKey = $schoolYear . '|' . $timetable['gibbonTTID'] . '|' . $record['date'];
            $dayShort = $dayShortByKey[$dayKey];
            $dayName = $record['date'];
            $dayTimetables[$dayKey] = $timetable;
            $dayDates[$dayKey] = $record['date'];
            if (!isset($dayData[$dayKey])) {
                $dayData[$dayKey] = [
                    'School Year' => $schoolYear,
                    'Timetable' => $timetable['nameShort'],
                    'Timetable Column' => $columnShort,
                    'Name' => $dayName,
                    'Short Name' => $dayShort,
                    'Header Background Colour' => '0E9F9A',
                    'Header Font Colour' => 'FFFFFF',
                ];
            }
            $classRow = [
                'School Year' => $schoolYear,
                'Timetable' => $timetable['nameShort'],
                'Timetable Column' => $columnShort,
                'Course' => $record['courseShort'],
                'Class' => $record['classShort'],
                'Timetable Day' => $dayShort,
                'Column Row' => $periodShort,
                'Facility' => isset($spaceNames[$record['room']]) ? $record['room'] : '',
            ];
            $classData[$record['row']] = $classRow;
            if ($record['room'] !== '' && !isset($spaceNames[$record['room']])) {
                $unmappedRooms[$record['room']] = true;
            }
        }

        if (!empty($errors)) {
            return ['rows' => [], 'errors' => $errors, 'warnings' => []];
        }

        foreach ($columnData as $column) {
            $columnQuery = $pdo->prepare('SELECT gibbonTTColumnID FROM gibbonTTColumn WHERE nameShort=:nameShort');
            $columnQuery->execute(['nameShort' => $column['nameShort']]);
            if (!$columnQuery->fetchColumn()) {
                $generated['timetableColumns'][] = [
                    'Name' => $column['name'],
                    'Short Name' => $column['nameShort'],
                ];
            }
        }
        foreach ($periodData as $period) {
            $columnQuery = $pdo->prepare('SELECT gibbonTTColumnID FROM gibbonTTColumn WHERE nameShort=:nameShort');
            $columnQuery->execute(['nameShort' => $period['Timetable Column']]);
            $columnID = $columnQuery->fetchColumn();
            if ($columnID) {
                $existingPeriodQuery = $pdo->prepare(
                    'SELECT timeStart, timeEnd, type FROM gibbonTTColumnRow
                     WHERE gibbonTTColumnID=:columnID AND nameShort=:nameShort'
                );
                $existingPeriodQuery->execute(['columnID' => $columnID, 'nameShort' => $period['Short Name']]);
                $existingPeriod = $existingPeriodQuery->fetch(\PDO::FETCH_ASSOC);
                if ($existingPeriod) {
                    if (substr((string) $existingPeriod['timeStart'], 0, 5) !== substr($period['Start Time'], 0, 5)
                            || substr((string) $existingPeriod['timeEnd'], 0, 5) !== substr($period['End Time'], 0, 5)
                            || $existingPeriod['type'] !== 'Lesson') {
                        $errors[] = $this->stagingError(2, 'Start Time / End Time', 'A generated timetable period conflicts with an existing period in the KRECK column.');
                    }
                    continue;
                }
            }
            $generated['timetableColumnRows'][] = $period;
        }

        foreach ($dayData as $schoolYearAndDay => $day) {
            $timetable = $dayTimetables[$schoolYearAndDay] ?? null;
            if ($timetable === null) {
                $errors[] = $this->stagingError(2, 'Timetable', 'The generated SMS timetable could not be resolved.');
                continue;
            }
            $dayQuery = $pdo->prepare(
                'SELECT gibbonTTDayID, gibbonTTColumnID, name FROM gibbonTTDay
                 WHERE gibbonTTID=:timetableID AND nameShort=:nameShort'
            );
            $dayQuery->execute(['timetableID' => $timetable['gibbonTTID'], 'nameShort' => $day['Short Name']]);
            $existingDay = $dayQuery->fetch(\PDO::FETCH_ASSOC);
            if ($existingDay) {
                $columnQuery = $pdo->prepare('SELECT nameShort FROM gibbonTTColumn WHERE gibbonTTColumnID=:columnID');
                $columnQuery->execute(['columnID' => $existingDay['gibbonTTColumnID']]);
                $existingColumnShort = $columnQuery->fetchColumn();
                if ($existingDay['name'] !== $day['Name'] || $existingColumnShort !== $day['Timetable Column']) {
                    $errors[] = $this->stagingError(2, 'Lesson Date', 'A generated timetable day conflicts with an existing day in SMS.');
                    continue;
                }
            } else {
                $generated['timetableDays'][] = $day;
            }

            $date = $dayDates[$schoolYearAndDay] ?? '';
            $dayID = $existingDay['gibbonTTDayID'] ?? null;
            $dateExists = false;
            if ($dayID) {
                $dateQuery = $pdo->prepare('SELECT COUNT(*) FROM gibbonTTDayDate WHERE gibbonTTDayID=:dayID AND date=:date');
                $dateQuery->execute(['dayID' => $dayID, 'date' => $date]);
                $dateExists = (int) $dateQuery->fetchColumn() > 0;
            }
            if (!$dateExists) {
                $generated['timetableDaysToDates'][$timetable['gibbonTTID'] . '|' . $date] = [
                    'Timetable' => $timetable['nameShort'],
                    'Day' => $day['Short Name'],
                    'Date' => $date,
                ];
            }
        }

        if (!empty($errors)) {
            return ['rows' => [], 'errors' => $errors, 'warnings' => []];
        }
        $generated['timetableDaysToDates'] = array_values($generated['timetableDaysToDates']);
        $generated['timetableClasses'] = array_values($classData);

        if (!empty($unmappedRooms)) {
            $unmappedRoomNames = array_keys($unmappedRooms);
            $roomSummary = implode(', ', array_slice($unmappedRoomNames, 0, 5));
            if (count($unmappedRoomNames) > 5) {
                $roomSummary .= ', and ' . (count($unmappedRoomNames) - 5) . ' more';
            }
            $warnings[] = [
                'type' => 'warning',
                'field_name' => 'Room / Location',
                'info' => count($unmappedRoomNames) . ' staged location(s) do not match SMS Facilities and were left blank in the timetable: '
                    . $roomSummary,
            ];
        }
        if (array_filter($rows, static fn($row) => trim((string) ($row['Teacher Username'] ?? '')) !== '')) {
            $warnings[] = [
                'type' => 'warning',
                'field_name' => 'Teacher Username',
                'info' => 'SMS timetable sessions do not store a per-session teacher. Teacher usernames in this sheet were not used to change class staffing.',
            ];
        }
        $dates = array_values(array_unique(array_values($dayDates)));
        sort($dates);
        return [
            'rows' => $generated,
            'errors' => [],
            'warnings' => $warnings,
            'summary' => [
                'lessonSessions' => count($classData),
                'uniqueDates' => count($dates),
                'firstDate' => $dates[0] ?? '',
                'lastDate' => $dates[count($dates) - 1] ?? '',
                'uniquePeriods' => count($periodData),
            ],
        ];
    }

    /**
     * Build native timetable-import rows from a WEEKLY staging sheet
     * ("STG Weekly Timetable": Day-of-week + Period, not dated lessons).
     *
     * Unlike buildStagingTimetableImports() (which treats each unique DATE as a
     * timetable day), this maps the recurring weekly pattern onto SMS's native
     * weekly timetable: one timetable day per weekday, one class row per weekly
     * slot, and gibbonTTDayDate expanded so each weekday day falls on every
     * matching date in the school year. That is what makes the imported lessons
     * actually appear on the SMS calendar/timetable for the whole term.
     *
     * Returns the same shape as buildStagingTimetableImports().
     */
    private function buildStagingWeeklyTimetableImports(array $rows): array
    {
        $pdo = $this->db->getConnection();
        $errors = [];
        $warnings = [];
        $weekdayNums = ['monday' => 1, 'mon' => 1, 'tuesday' => 2, 'tue' => 2, 'wednesday' => 3, 'wed' => 3,
            'thursday' => 4, 'thu' => 4, 'friday' => 5, 'fri' => 5, 'saturday' => 6, 'sat' => 6, 'sunday' => 7, 'sun' => 7];
        $weekdayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        $classes = [];
        $classQuery = $pdo->query(
            'SELECT sy.name AS schoolYear, c.nameShort AS courseShort, cc.nameShort AS classShort, c.gibbonYearGroupIDList
             FROM gibbonCourseClass cc
             JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
             JOIN gibbonSchoolYear sy ON sy.gibbonSchoolYearID=c.gibbonSchoolYearID'
        );
        foreach ($classQuery->fetchAll(\PDO::FETCH_ASSOC) as $class) {
            $classes[$class['schoolYear'] . '|' . $class['courseShort'] . '|' . $class['classShort']] = $class;
        }

        $records = [];
        $sourceRefs = [];
        $overlap = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $status = strtolower(trim((string) ($row['Status'] ?? '')));
            if ($status !== '' && !in_array($status, ['planned', 'scheduled'], true)) {
                $errors[] = $this->stagingError($rowNumber, 'Status', 'Only Planned or Scheduled slots can be imported.');
                continue;
            }
            $schoolYear = trim((string) ($row['School Year'] ?? ''));
            $courseShort = trim((string) ($row['Course Short Name'] ?? ''));
            $classShort = trim((string) ($row['Class Short Name'] ?? ''));
            $sourceRef = trim((string) ($row['Weekly Slot Source Ref'] ?? ($row['Lesson Source Ref'] ?? '')));
            $dayName = strtolower(trim((string) ($row['Day'] ?? '')));
            $weekdayNum = $weekdayNums[$dayName] ?? null;
            $start = $this->normaliseStagingTime($row['Start Time'] ?? '');
            $end = $this->normaliseStagingTime($row['End Time'] ?? '');
            foreach (['School Year' => $schoolYear, 'Course Short Name' => $courseShort, 'Class Short Name' => $classShort] as $field => $value) {
                if ($value === '') {
                    $errors[] = $this->stagingError($rowNumber, $field, 'This field is required to map the slot to a SMS class.');
                }
            }
            if ($weekdayNum === null) {
                $errors[] = $this->stagingError($rowNumber, 'Day', 'Enter a weekday name (Monday–Sunday).');
            }
            if ($start === null || $end === null) {
                $errors[] = $this->stagingError($rowNumber, 'Start Time / End Time', 'Enter valid Excel times displayed as HH:MM.');
            } elseif ($start >= $end) {
                $errors[] = $this->stagingError($rowNumber, 'Start Time / End Time', 'The end time must be after the start time.');
            }
            if ($schoolYear === '' || $courseShort === '' || $classShort === '' || $weekdayNum === null || $start === null || $end === null || $start >= $end) {
                continue;
            }
            $classKey = $schoolYear . '|' . $courseShort . '|' . $classShort;
            if (!isset($classes[$classKey])) {
                $errors[] = $this->stagingError($rowNumber, 'Class Short Name', 'The course/class pair does not exist in SMS for this school year.');
                continue;
            }
            if ($sourceRef !== '') {
                $sk = $schoolYear . '|' . $sourceRef;
                if (isset($sourceRefs[$sk])) {
                    $errors[] = $this->stagingError($rowNumber, 'Weekly Slot Source Ref', 'This source reference is duplicated in the staged timetable.');
                }
                $sourceRefs[$sk] = true;
            }
            $sMin = ((int) substr($start, 0, 2) * 60) + (int) substr($start, 3, 2);
            $eMin = ((int) substr($end, 0, 2) * 60) + (int) substr($end, 3, 2);
            foreach (['class:' . $classKey, 'room:' . strtolower(trim((string) ($row['Room / Location'] ?? '')))] as $res) {
                if (substr($res, -1) === ':') continue;
                $rk = $schoolYear . '|' . $weekdayNum . '|' . $res;
                foreach ($overlap[$rk] ?? [] as [$os, $oe, $orow]) {
                    if ($sMin < $oe && $eMin > $os) {
                        $errors[] = $this->stagingError($rowNumber, 'Start Time / End Time', 'This slot overlaps row ' . $orow . ' for the same class or room on ' . $weekdayNames[$weekdayNum] . '.');
                        break;
                    }
                }
                $overlap[$rk][] = [$sMin, $eMin, $rowNumber];
            }
            $records[] = [
                'row' => $rowNumber, 'schoolYear' => $schoolYear, 'courseShort' => $courseShort, 'classShort' => $classShort,
                'weekday' => $weekdayNum, 'start' => $start, 'end' => $end, 'room' => trim((string) ($row['Room / Location'] ?? '')),
                'yearGroupIDs' => array_filter(array_map('trim', explode(',', (string) $classes[$classKey]['gibbonYearGroupIDList']))),
            ];
        }
        if (!empty($errors)) {
            return ['rows' => [], 'errors' => $errors, 'warnings' => []];
        }

        $yearGroups = [];
        foreach ($records as $r) {
            foreach ($r['yearGroupIDs'] as $g) { $yearGroups[$r['schoolYear']][(string) $g] = (string) $g; }
        }
        $timetables = [];
        $years = [];
        foreach (array_unique(array_column($records, 'schoolYear')) as $sy) {
            [$year, $tt] = $this->timetableForStagingYear($sy, array_values($yearGroups[$sy] ?? []));
            $timetables[$sy] = $tt;
            $years[$sy] = $year;
        }

        $spaceNames = [];
        foreach ($pdo->query('SELECT name FROM gibbonSpace')->fetchAll(\PDO::FETCH_COLUMN) as $sp) {
            $spaceNames[(string) $sp] = true;
        }

        $generated = ['timetableColumns' => [], 'timetableColumnRows' => [], 'timetableDays' => [], 'timetableClasses' => []];
        $columnData = [];
        $periodShortByKey = [];
        $dayShortByKey = [];
        $dayDates = [];   // [timetable nameShort, day nameShort, date] inserted directly into gibbonTTDayDate
        $unmappedRooms = [];

        foreach (array_unique(array_column($records, 'schoolYear')) as $sy) {
            $columnName = 'KRECK Weekly ' . $sy;
            $columnShort = 'W' . strtoupper(substr(hash('sha256', $sy), 0, 3));
            $q = $pdo->prepare('SELECT name, nameShort FROM gibbonTTColumn WHERE nameShort=:ns OR name=:n');
            $q->execute(['ns' => $columnShort, 'n' => $columnName]);
            $ex = $q->fetch(\PDO::FETCH_ASSOC);
            if ($ex && ($ex['name'] !== $columnName || $ex['nameShort'] !== $columnShort)) {
                $errors[] = $this->stagingError(2, 'School Year', 'A timetable column conflicts with the generated KRECK Weekly column. Rename it and retry.');
                break;
            }
            $columnData[$sy] = ['name' => $columnName, 'nameShort' => $columnShort];
            if (!$ex) {
                $generated['timetableColumns'][] = ['Name' => $columnName, 'Short Name' => $columnShort];
            }
        }
        if (!empty($errors)) {
            return ['rows' => [], 'errors' => $errors, 'warnings' => []];
        }

        $periodIdx = 1;
        foreach ($records as $r) {
            $pk = $r['schoolYear'] . '|' . $r['start'] . '|' . $r['end'];
            if (isset($periodShortByKey[$pk])) continue;
            $short = 'PW' . str_pad((string) $periodIdx++, 2, '0', STR_PAD_LEFT);
            $periodShortByKey[$pk] = $short;
            $generated['timetableColumnRows'][] = [
                'School Year' => $r['schoolYear'], 'Timetable Column' => $columnData[$r['schoolYear']]['nameShort'],
                'Name' => substr($r['start'], 0, 5) . '-' . substr($r['end'], 0, 5), 'Short Name' => $short,
                'Start Time' => $r['start'], 'End Time' => $r['end'], 'Type' => 'Lesson',
            ];
        }

        foreach ($records as $r) {
            $dk = $r['schoolYear'] . '|' . $r['weekday'];
            if (isset($dayShortByKey[$dk])) continue;
            $short = 'KW' . $r['weekday'] . strtoupper(substr(hash('sha256', $r['schoolYear']), 0, 1));
            $dayShortByKey[$dk] = $short;
            $tt = $timetables[$r['schoolYear']];
            $generated['timetableDays'][] = [
                'School Year' => $r['schoolYear'], 'Timetable' => $tt['nameShort'], 'Timetable Column' => $columnData[$r['schoolYear']]['nameShort'],
                'Name' => $weekdayNames[$r['weekday']], 'Short Name' => $short,
                'Header Background Colour' => '0E9F9A', 'Header Font Colour' => 'FFFFFF',
            ];
            $year = $years[$r['schoolYear']];
            $d = new \DateTime($year['firstDay']);
            $last = new \DateTime($year['lastDay']);
            while ($d <= $last) {
                if ((int) $d->format('N') === $r['weekday']) {
                    // The native "Tie Days to Dates" ImportType is a keyless
                    // compound type that does not persist through the CSV import
                    // path, so these are inserted directly into gibbonTTDayDate
                    // (see applyWeeklyDayDates) once the days exist.
                    $dayDates[] = ['timetable' => $tt['nameShort'], 'day' => $short, 'date' => $d->format('Y-m-d')];
                }
                $d->modify('+1 day');
            }
        }

        foreach ($records as $r) {
            $tt = $timetables[$r['schoolYear']];
            $facility = isset($spaceNames[$r['room']]) ? $r['room'] : '';
            if ($r['room'] !== '' && !isset($spaceNames[$r['room']])) {
                $unmappedRooms[$r['room']] = true;
            }
            $generated['timetableClasses'][] = [
                'School Year' => $r['schoolYear'], 'Timetable' => $tt['nameShort'], 'Timetable Column' => $columnData[$r['schoolYear']]['nameShort'],
                'Course' => $r['courseShort'], 'Class' => $r['classShort'],
                'Timetable Day' => $dayShortByKey[$r['schoolYear'] . '|' . $r['weekday']],
                'Column Row' => $periodShortByKey[$r['schoolYear'] . '|' . $r['start'] . '|' . $r['end']],
                'Facility' => $facility,
            ];
        }

        if (!empty($unmappedRooms)) {
            $names = array_keys($unmappedRooms);
            $summary = implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ', and ' . (count($names) - 5) . ' more' : '');
            $warnings[] = ['type' => 'warning', 'field_name' => 'Room / Location',
                'info' => count($names) . ' staged location(s) do not match SMS Facilities and were left blank: ' . $summary];
        }
        return [
            'rows' => $generated, 'dayDates' => $dayDates, 'errors' => [], 'warnings' => $warnings,
            'summary' => [
                'weeklySlots' => count($records), 'weekdays' => count($dayShortByKey),
                'datedSessions' => count($dayDates), 'uniquePeriods' => count($periodShortByKey),
            ],
        ];
    }

    /**
     * Insert the weekday→date rows directly into gibbonTTDayDate (bypassing the
     * keyless native "Tie Days to Dates" import, which does not persist through
     * the CSV path). Resolves each day by (timetable nameShort, day nameShort),
     * and is idempotent — existing (day, date) pairs are left untouched.
     *
     * @param array $dayDates list of ['timetable'=>, 'day'=>, 'date'=>]
     * @return int number of gibbonTTDayDate rows created
     */
    private function applyWeeklyDayDates(array $dayDates): int
    {
        if (empty($dayDates)) return 0;
        $pdo = $this->db->getConnection();
        $resolveDay = $pdo->prepare(
            'SELECT d.gibbonTTDayID FROM gibbonTTDay d
             JOIN gibbonTT tt ON tt.gibbonTTID=d.gibbonTTID
             WHERE tt.nameShort=:tt AND d.nameShort=:day'
        );
        $exists = $pdo->prepare('SELECT COUNT(*) FROM gibbonTTDayDate WHERE gibbonTTDayID=:d AND date=:date');
        $insert = $pdo->prepare('INSERT INTO gibbonTTDayDate (gibbonTTDayID, date) VALUES (:d, :date)');
        $created = 0;
        $dayIdCache = [];
        foreach ($dayDates as $dd) {
            $key = $dd['timetable'] . '|' . $dd['day'];
            if (!array_key_exists($key, $dayIdCache)) {
                $resolveDay->execute(['tt' => $dd['timetable'], 'day' => $dd['day']]);
                $dayIdCache[$key] = $resolveDay->fetchColumn() ?: null;
            }
            $dayID = $dayIdCache[$key];
            if (!$dayID) continue;
            $exists->execute(['d' => $dayID, 'date' => $dd['date']]);
            if ((int) $exists->fetchColumn() === 0) {
                $insert->execute(['d' => $dayID, 'date' => $dd['date']]);
                $created++;
            }
        }
        return $created;
    }

    private function buildStagingAssignmentImports(array $rows): array
    {
        $mapped = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $status = strtolower(trim((string) ($row['Status'] ?? 'scheduled')));
            if (in_array($status, ['cancelled', 'canceled', 'inactive'], true)) {
                $active = 'N';
            } elseif (in_array($status, ['', 'scheduled', 'planned', 'active'], true)) {
                $active = 'Y';
            } else {
                $errors[] = $this->stagingError($rowNumber, 'Status', 'Use Scheduled, Planned, Active, Inactive, or Cancelled.');
                continue;
            }
            $mapped[] = [
                'School Year' => $row['School Year'] ?? '',
                'Course Short Name' => $row['Course Short Name'] ?? '',
                'Class Short Name' => $row['Class Short Name'] ?? '',
                'Plan Reference' => $row['Assignment Source Ref'] ?? '',
                'LMS Section Number' => 1,
                'Assignment Name' => $row['Title'] ?? '',
                'Assignment Description' => $row['Description'] ?? '',
                'Opens Date' => $row['Open Date'] ?? '',
                'Opens Time' => $row['Open Time'] ?? '',
                'Due Date' => $row['Due Date'] ?? '',
                'Due Time' => $row['Due Time'] ?? '',
                'Cutoff Date' => $row['Cutoff Date'] ?? '',
                'Cutoff Time' => $row['Cutoff Time'] ?? '',
                'Maximum Grade' => $row['Maximum Grade'] ?? '',
                'Active' => $active,
            ];
        }
        if (!empty($errors)) {
            return ['rows' => [], 'errors' => $errors];
        }
        $importType = $this->loadType('kreck_lmsAssignmentPlans');
        $headers = array_keys($mapped[0] ?? []);
        $matched = $this->matchToFields($importType, $headers, $mapped);
        return ['rows' => $matched['rows'], 'errors' => []];
    }

    /**
     * Import a whole institution atomically: run every type live inside ONE
     * transaction (so FK lookups resolve against rows inserted by earlier
     * sheets), then commit only if $commit AND there were zero errors —
     * otherwise roll back. A dry-run ($commit=false) always rolls back, which
     * is how validation can resolve cross-sheet references without persisting.
     */
    public function runWorkbook(
        array $typeRows,
        bool $commit,
        int $userID,
        string $mode = 'sync',
        ?callable $onProgress = null
    ): array
    {
        $perType = [];
        $totalErrors = 0;
        $registry = $this->typeRegistry();
        $stagingTimetable = $typeRows['stg_lessonTimetable'] ?? [];
        $stagingWeekly = $typeRows['stg_weeklyTimetable'] ?? [];
        $stagingAssignments = $typeRows['stg_assignments'] ?? [];
        unset($typeRows['stg_lessonTimetable'], $typeRows['stg_weeklyTimetable'], $typeRows['stg_assignments']);
        $this->db->beginTransaction();
        try {
            $timetableImports = null;
            $assignmentImports = null;
            $weeklyDayDates = [];
            if ($onProgress !== null) {
                $onProgress(10, 'Preparing workbook validation...');
            }
            foreach ($registry as $index => $entry) {
                $typeName = $entry['name'];
                $percent = 10 + (int) floor(80 * $index / max(1, count($registry)));
                if ($onProgress !== null) {
                    $rowCount = count($typeRows[$typeName] ?? []);
                    $onProgress($percent, 'Processing ' . $entry['label'] . ' (' . $rowCount . ' rows)...');
                }
                if ($typeName === 'timetableColumns' && !empty($stagingTimetable)) {
                    $timetableImports = $this->buildStagingTimetableImports($stagingTimetable);
                    $perType['stg_lessonTimetable'] = $this->stagingResult(
                        'stg_lessonTimetable',
                        $stagingTimetable,
                        $commit,
                        $timetableImports['errors'],
                        $timetableImports['warnings']
                    );
                    $perType['stg_lessonTimetable']['stats'] = $timetableImports['summary'] ?? [];
                    $totalErrors += count($timetableImports['errors']);
                    foreach ($timetableImports['rows'] as $generatedType => $generatedRows) {
                        if (!empty($generatedRows)) {
                            $importType = $this->loadType($generatedType);
                            $generatedHeaders = array_keys($generatedRows[0]);
                            $matched = $this->matchToFields($importType, $generatedHeaders, $generatedRows);
                            $typeRows[$generatedType] = array_merge($typeRows[$generatedType] ?? [], $matched['rows']);
                        }
                    }
                }
                if ($typeName === 'timetableColumns' && !empty($stagingWeekly)) {
                    $weeklyImports = $this->buildStagingWeeklyTimetableImports($stagingWeekly);
                    $weeklyDayDates = $weeklyImports['dayDates'] ?? [];
                    $perType['stg_weeklyTimetable'] = $this->stagingResult(
                        'stg_weeklyTimetable',
                        $stagingWeekly,
                        $commit,
                        $weeklyImports['errors'],
                        $weeklyImports['warnings']
                    );
                    $perType['stg_weeklyTimetable']['stats'] = $weeklyImports['summary'] ?? [];
                    $totalErrors += count($weeklyImports['errors']);
                    foreach ($weeklyImports['rows'] as $generatedType => $generatedRows) {
                        if (!empty($generatedRows)) {
                            $importType = $this->loadType($generatedType);
                            $generatedHeaders = array_keys($generatedRows[0]);
                            $matched = $this->matchToFields($importType, $generatedHeaders, $generatedRows);
                            $typeRows[$generatedType] = array_merge($typeRows[$generatedType] ?? [], $matched['rows']);
                        }
                    }
                }
                if ($typeName === 'kreck_lmsAssignmentPlans' && !empty($stagingAssignments)) {
                    $assignmentImports = $this->buildStagingAssignmentImports($stagingAssignments);
                    $perType['stg_assignments'] = $this->stagingResult(
                        'stg_assignments',
                        $stagingAssignments,
                        $commit,
                        $assignmentImports['errors']
                    );
                    $totalErrors += count($assignmentImports['errors']);
                    if (!empty($assignmentImports['rows'])) {
                        $typeRows[$typeName] = array_merge($typeRows[$typeName] ?? [], $assignmentImports['rows']);
                    }
                }
                if (empty($typeRows[$typeName])) continue;
                if ($typeName === 'formGroups') {
                    // Two-pass: "Next Form Group" is a forward reference (a group
                    // often points to one defined later in the sheet). Create all
                    // form groups first WITHOUT that link, then a second pass sets
                    // the links now that every group exists. The second pass is the
                    // authoritative result.
                    $firstPass = array_map(static function ($r) {
                        unset($r['gibbonFormGroupIDNext']);
                        return $r;
                    }, $typeRows[$typeName]);
                    $this->run('formGroups', $firstPass, true, $userID, $mode);
                    $res = $this->run('formGroups', $typeRows[$typeName], true, $userID, $mode);
                } else {
                    $res = $this->run($typeName, $typeRows[$typeName], true, $userID, $mode);
                }
                $perType[$typeName] = $res;
                $totalErrors += count($res['errors'] ?? []);
                if ($onProgress !== null) {
                    $nextPercent = min(95, 10 + (int) floor(80 * ($index + 1) / max(1, count($registry))));
                    $onProgress(
                        $nextPercent,
                        'Finished ' . $entry['label'] . '.'
                    );
                }
            }
            // Tie the generated weekday timetable days to their term dates
            // (direct gibbonTTDayDate insert; the native import cannot). Runs
            // inside the transaction, so a dry-run's inserts roll back too.
            if (!empty($weeklyDayDates)) {
                $createdDates = $this->applyWeeklyDayDates($weeklyDayDates);
                if (isset($perType['stg_weeklyTimetable']['stats'])) {
                    $perType['stg_weeklyTimetable']['stats']['datedSessionsCreated'] = $createdDates;
                }
            }
            if ($onProgress !== null) {
                $onProgress(95, $commit && $totalErrors === 0
                    ? 'Committing validated workbook...'
                    : 'Finalizing dry-run validation.');
            }
        } catch (\Throwable $e) {
            if ($this->db->getConnection()->inTransaction()) $this->db->rollBack();
            $reference = class_exists('KreckBridgeDiagnostics', false)
                ? KreckBridgeDiagnostics::reportCaught($e, 'import institution workbook')
                : null;
            $error = $e->getMessage();
            if ($reference !== null) {
                $error .= ' (KRECK diagnostic reference ' . $reference . ')';
            }
            if ($onProgress !== null) {
                $onProgress(100, 'Import stopped: ' . $error);
            }
            return ['ok' => false, 'committed' => false, 'error' => $error, 'perType' => $perType, 'totalErrors' => $totalErrors + 1];
        }
        $doCommit = $commit && $totalErrors === 0;
        if ($doCommit) $this->db->commit();
        else $this->db->rollBack();
        return ['ok' => $totalErrors === 0, 'committed' => $doCommit, 'perType' => $perType, 'totalErrors' => $totalErrors];
    }

    private function rowsToCsv(array $fields, array $rows): string
    {
        $esc = function ($v) {
            $v = (string) ($v ?? '');
            if (preg_match('/[",\n\r]/', $v)) {
                $v = '"' . str_replace('"', '""', $v) . '"';
            }
            return $v;
        };
        $out = implode(',', array_map($esc, $fields)) . "\n";
        foreach ($rows as $row) {
            $line = [];
            foreach ($fields as $f) {
                $line[] = $esc($row[$f] ?? '');
            }
            $out .= implode(',', $line) . "\n";
        }
        return $out;
    }
}
