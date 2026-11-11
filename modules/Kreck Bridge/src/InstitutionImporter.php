<?php
/*
Kreck Bridge — InstitutionImporter.

A thin orchestrator over Gibbon's native import engine (Gibbon\Data\Importer +
Gibbon\Data\ImportType). It does NOT re-implement validation, dedup, FK
resolution or file reading — it reuses Gibbon's engine and simply drives it from
either (a) an uploaded Excel/CSV file or (b) the KRECK in-app HTML table/form.

Both paths converge on run(): rows keyed by field machine-name are turned into an
in-memory CSV, fed to the real Importer, validated/dry-run or committed, and the
per-row logs + stats returned for the KRECK UI to render.

No namespace (Gibbon modules are not PSR-4 autoloaded); required directly.
*/

use Gibbon\Data\Importer;
use Gibbon\Data\ImportType;
use Gibbon\Data\PasswordPolicy;
use Gibbon\Forms\PersonalDataFieldSettings;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Contracts\Database\Connection;
use Symfony\Component\Yaml\Yaml;

class KreckInstitutionImporter
{
    private $db;
    private $passwordPolicy;
    private $personalDataFields;
    private $settingGateway;
    private $moduleImportDir;

    public function __construct(
        Connection $db,
        PasswordPolicy $passwordPolicy,
        PersonalDataFieldSettings $personalDataFields,
        SettingGateway $settingGateway,
        string $moduleImportDir
    ) {
        $this->db = $db;
        $this->passwordPolicy = $passwordPolicy;
        $this->personalDataFields = $personalDataFields;
        $this->settingGateway = $settingGateway;
        $this->moduleImportDir = rtrim($moduleImportDir, '/');
    }

    /**
     * The institution-seeding types KRECK exposes, in dependency order. KRECK
     * fee types ship in the module; the rest reuse Gibbon's built-in YAML types.
     */
    public function typeRegistry(): array
    {
        return [
            ['name' => 'departments',      'label' => 'Departments / Learning Areas', 'group' => 'Structure', 'source' => 'native'],
            ['name' => 'yearGroups',       'label' => 'Year Groups',                  'group' => 'Structure', 'source' => 'native'],
            ['name' => 'formGroups',       'label' => 'Form Groups',                  'group' => 'Structure', 'source' => 'native'],
            ['name' => 'courses',          'label' => 'Courses',                      'group' => 'Teaching',  'source' => 'native'],
            ['name' => 'courseClasses',    'label' => 'Classes',                      'group' => 'Teaching',  'source' => 'native'],
            ['name' => 'usersStaff',       'label' => 'Staff',                        'group' => 'People',    'source' => 'native'],
            ['name' => 'usersBasic',       'label' => 'Students / Users',             'group' => 'People',    'source' => 'native'],
            ['name' => 'studentEnrolment', 'label' => 'Student Enrolment',            'group' => 'People',    'source' => 'native'],
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

    /** Load an ImportType: KRECK module YAML first, else Gibbon's built-in. */
    public function loadType(string $name): ?ImportType
    {
        $modulePath = $this->moduleImportDir . '/' . $name . '.yml';
        if (is_file($modulePath)) {
            $data = Yaml::parse(file_get_contents($modulePath));
            return new ImportType($data, $this->passwordPolicy, $this->db, true, $this->personalDataFields);
        }
        return ImportType::loadImportType(
            $name,
            $this->settingGateway,
            $this->passwordPolicy,
            $this->personalDataFields,
            $this->db
        );
    }

    /**
     * Describe a type's fields for the KRECK HTML table/form: the columns the
     * user fills (data fields), with required/unique hints. Auto fields
     * (creator, generated timestamps, linked) are excluded from the UI.
     */
    public function describeFields(ImportType $importType): array
    {
        $cols = [];
        foreach ($importType->getAllFields() as $f) {
            if ($this->isAutoField($importType, $f)) {
                continue;
            }
            $cols[] = [
                'field'    => $f,
                'label'    => $importType->getField($f, 'name', $f),
                'desc'     => $importType->getField($f, 'desc', ''),
                'required' => $importType->isFieldRequired($f),
                'unique'   => $importType->isFieldUniqueKey($f),
            ];
        }
        return $cols;
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
                $customValues[$fc] = (string) $sessionUserID; // creator = current finance/admin user
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
