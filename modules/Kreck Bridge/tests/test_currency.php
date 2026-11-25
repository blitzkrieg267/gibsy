<?php
/**
 * BWP policy: Botswana Pula is the default currency everywhere — never USD, never blank.
 */

require_once __DIR__ . '/../src/SalaryManager.php';

kt_test('currency.BWP is the default and unknown codes are rejected, not guessed', function () {
    kt_eq('BWP', KreckSalaryManager::DEFAULT_CURRENCY, 'the default constant');
    kt_eq('BWP', KreckSalaryManager::normaliseCurrency(''), 'blank → BWP');
    kt_eq('BWP', KreckSalaryManager::normaliseCurrency(null), 'null → BWP');
    kt_eq('BWP', KreckSalaryManager::normaliseCurrency('  bwp '), 'case/space tolerant');
    kt_eq('BWP', KreckSalaryManager::normaliseCurrency('BWP P'), 'an SMS-style "BWP P" setting value');
    kt_eq('ZAR', KreckSalaryManager::normaliseCurrency('zar'), 'other known currencies stay selectable');
    kt_eq(null, KreckSalaryManager::normaliseCurrency('XXX'), 'unknown → null so it can be reported');
    kt_eq('BWP', KreckSalaryManager::currencyCodeFromSetting(''), 'empty school setting → BWP');
    kt_eq('BWP', KreckSalaryManager::currencyCodeFromSetting(null), 'missing school setting → BWP');
    kt_eq('BWP', KreckSalaryManager::currencyCodeFromSetting('BWP P'), 'BWP P → BWP');
    kt_eq('P', KreckSalaryManager::currencySymbol(''), 'blank code shows the Pula symbol');
    kt_eq('BWP', array_key_first(KreckSalaryManager::currencyCatalog()), 'BWP is first in every currency list');
});

kt_test('currency.pay frequency, pay day and category are validated', function () {
    kt_eq('Monthly', KreckSalaryManager::normaliseFrequency(''), 'blank frequency → Monthly');
    kt_eq('Fortnightly', KreckSalaryManager::normaliseFrequency('fortnightly'), 'case-insensitive');
    kt_eq(null, KreckSalaryManager::normaliseFrequency('Daily'), 'unknown frequency → null');
    kt_eq(null, KreckSalaryManager::normalisePayDay(''), 'blank pay day → none');
    kt_eq(25, KreckSalaryManager::normalisePayDay('25'), 'valid day');
    kt_eq(-1, KreckSalaryManager::normalisePayDay('29'), 'day above 28 is invalid');
    kt_eq(-1, KreckSalaryManager::normalisePayDay('0'), 'day 0 is invalid');
    kt_eq(-1, KreckSalaryManager::normalisePayDay('2x'), 'non-numeric is invalid');
    kt_eq(90, mb_strlen(KreckSalaryManager::normaliseCategory(str_repeat('é', 120))), 'category capped at the column width');
    kt_eq(null, KreckSalaryManager::normaliseCategory('   '), 'blank category → none');
});

kt_test('currency.a salary saved with no currency is stored as BWP, with EUR as an explicit exception', function () {
    $ctx = kt_ctx();
    if (!$ctx['staffID'] || !$ctx['adminID']) { kt_skip('no staff/admin available'); }
    $mgr = new KreckSalaryManager(kt_conn());
    $ids = [];
    foreach ([[null, 'BWP'], ['', 'BWP'], ['garbage', 'BWP'], ['EUR', 'EUR']] as [$in, $expect]) {
        $id = $mgr->saveSalary(['gibbonPersonID' => $ctx['staffID'], 'amount' => 100.00, 'currency' => $in, 'startDate' => date('Y-m-d'),
            'category' => 'kt-currency', 'frequency' => 'Daily', 'payDay' => 99], $ctx['adminID']);
        $ids[] = $id;
        $row = kt_pdo()->query("SELECT currency, frequency, payDay FROM kreckSalary WHERE kreckSalaryID=" . (int) $id)->fetch(\PDO::FETCH_ASSOC);
        kt_eq($expect, $row['currency'], 'currency ' . var_export($in, true) . ' is stored as ' . $expect);
        kt_eq('Monthly', $row['frequency'], 'an invalid frequency falls back to Monthly');
        kt_assert($row['payDay'] === null, 'an invalid pay day is not stored');
    }
    kt_cleanup(function () use ($ids) { foreach ($ids as $id) { kt_pdo()->exec("DELETE FROM kreckSalary WHERE kreckSalaryID=" . (int) $id); } });
    $default = kt_pdo()->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kreckSalary' AND COLUMN_NAME='currency'")->fetchColumn();
    kt_assert(trim((string) $default, "'") === 'BWP', 'the database column default is BWP');
});

kt_test('currency.guard: no USD fallback anywhere in the SMS module or the LMS plugin', function () {
    $roots = [dirname(__DIR__)];
    $lms = '/home/user/moodle_v1/public/local/kreck';
    if (is_dir($lms)) { $roots[] = $lms; }
    $bad = [];
    foreach ($roots as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = $f->getPathname();
            if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false || strpos($path, '/lang/') !== false) { continue; }
            foreach (file($path) as $n => $line) {
                // A USD *fallback/default* (?: 'USD', ?? 'USD', = 'USD', default 'USD'). Fine: the catalogue entry 'USD' => [...] and
                // the migration that REPLACES Gibbon's factory default ('USD $') with BWP.
                if (preg_match("/(\?\?|\?:|=|default'\s*=>)\s*'USD( \\$)?'/", $line) && strpos($line, "'USD' =>") === false && stripos($line, 'UPDATE gibbonSetting') === false) {
                    $bad[] = str_replace('/home/user/', '', $path) . ':' . ($n + 1);
                }
            }
        }
    }
    kt_assert(!$bad, 'USD fallback found at ' . implode(', ', $bad));
});
