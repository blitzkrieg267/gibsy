<?php
/**
 * Teacher salary definition + approval flow (KRECK payroll custom entries).
 * Proves saveSalary() inserts a Pending definition and decideSalary() approves
 * it exactly once (idempotent / race-safe), and rejects a bad decision verb.
 */

require_once __DIR__ . '/../src/SalaryManager.php';

function kt_delete_salary(int $salaryID): void {
    if ($salaryID <= 0) { return; }
    $pdo = kt_pdo();
    $pdo->prepare("DELETE FROM kreckSalaryPayment WHERE kreckSalaryID = :id")->execute(['id' => $salaryID]);
    $pdo->prepare("DELETE FROM kreckSalary WHERE kreckSalaryID = :id")->execute(['id' => $salaryID]);
}

kt_test('salary.saveSalary creates a Pending definition', function () {
    $ctx = kt_ctx();
    if (!$ctx['staffID'] || !$ctx['adminID']) { kt_skip('no staff/admin available'); }

    $mgr = new KreckSalaryManager(kt_conn());
    $id = $mgr->saveSalary([
        'gibbonPersonID' => $ctx['staffID'],
        'amount'    => 12345.67,
        'currency'  => 'BWP',
        'frequency' => 'Monthly',
        'payDay'    => 25,
        'startDate' => date('Y-m-d'),
        'status'    => 'Active',
        'category'  => 'kreck-test',
        'notes'     => 'kreck harness',
    ], $ctx['adminID']);
    kt_cleanup(fn () => kt_delete_salary((int) $id));
    kt_assert($id > 0, 'saveSalary returns a new id');

    $row = kt_pdo()->query("SELECT amount, approvalStatus FROM kreckSalary WHERE kreckSalaryID = " . (int) $id)
        ->fetch(\PDO::FETCH_ASSOC);
    kt_eq('Pending', $row['approvalStatus'], 'new definition starts Pending');
    kt_assert(abs((float) $row['amount'] - 12345.67) < 0.001, 'amount stored');
});

kt_test('salary.decideSalary approves once, is idempotent, and rejects bad verbs', function () {
    $ctx = kt_ctx();
    if (!$ctx['staffID'] || !$ctx['adminID']) { kt_skip('no staff/admin available'); }

    $mgr = new KreckSalaryManager(kt_conn());
    $id = $mgr->saveSalary([
        'gibbonPersonID' => $ctx['staffID'],
        'amount'    => 999.00,
        'currency'  => 'BWP',
        'startDate' => date('Y-m-d'),
    ], $ctx['adminID']);
    kt_cleanup(fn () => kt_delete_salary((int) $id));

    // Bad verb → false, nothing changes.
    kt_eq(false, $mgr->decideSalary($id, 'Maybe', $ctx['adminID'], null), 'invalid decision returns false');
    kt_eq('Pending', kt_pdo()->query("SELECT approvalStatus FROM kreckSalary WHERE kreckSalaryID=" . (int) $id)->fetchColumn(),
        'still Pending after bad verb');

    // First approve → true.
    kt_eq(true, $mgr->decideSalary($id, 'Approved', $ctx['adminID'], 'ok (kreck-test)'), 'first approve succeeds');
    kt_eq('Approved', kt_pdo()->query("SELECT approvalStatus FROM kreckSalary WHERE kreckSalaryID=" . (int) $id)->fetchColumn(),
        'status is Approved');

    // Second approve → false (already decided; only a Pending row can be decided).
    kt_eq(false, $mgr->decideSalary($id, 'Approved', $ctx['adminID'], null), 'second approve is a no-op');
});
