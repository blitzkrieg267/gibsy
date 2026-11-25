<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Salary Approvals (school-admin approval queue).

The approval side of the salary workflow. Finance enters salaries on
kreck_salary.php as 'Pending'; a SCHOOL ADMINISTRATOR reviews them here and
approves or rejects each one. Only approved salaries are payroll-eligible
(KreckSalaryManager::activeSalariesForPeriod() filters on approvalStatus).

This action is granted to the built-in Administrator role only (see manifest.php
$actionRows + CHANGEDB 0.5.19), so access is controlled by Gibbon's own
permission system — no invented roles. No core edits.
*/

require_once __DIR__ . '/src/SalaryManager.php';

use Gibbon\Contracts\Database\Connection;
use Gibbon\Services\Format;

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_salary_approvals.php') == false) {
    $page->addError(__('You do not have access to this action. Salary approval is restricted to school administrators.'));
    return;
}

$manager = new KreckSalaryManager($container->get(Connection::class));
$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_salary_approvals.php';
$salaryURL = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_salary.php';
$kreckAction = $_REQUEST['kreckAction'] ?? '';

$decisionResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($kreckAction, ['approve', 'reject'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    $decision = $kreckAction === 'approve' ? 'Approved' : 'Rejected';
    $ok = $id > 0 ? $manager->decideSalary($id, $decision, (int) $session->get('gibbonPersonID'), $note) : false;
    $decisionResult = ['ok' => $ok, 'decision' => $decision, 'id' => $id];
}

$pending = $manager->pendingSalaries();
$recent = $manager->recentDecisions(12);
// BWP POLICY: a salary with no recorded currency is BWP — never the school-wide setting, never USD.
$defaultCurCode = KreckSalaryManager::DEFAULT_CURRENCY;
$rowSymbol = fn($code) => KreckSalaryManager::currencySymbol($code ?: $defaultCurCode);

$page->breadcrumbs->add(__('Salary Approvals'));

$staffName = function (array $r, string $prefix = ''): string {
    return trim(Format::name(
        $r[$prefix . 'title'] ?? ($r[$prefix . 'Title'] ?? ''),
        $r[$prefix . 'preferredName'] ?? ($r[$prefix . 'PreferredName'] ?? ''),
        $r[$prefix . 'surname'] ?? ($r[$prefix . 'Surname'] ?? ''),
        'Staff', false
    ));
};

ob_start();
?>
<style>
.kapp{max-width:1020px}
.kapp .kbtn{border:0;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;font-size:13px}
.kapp .kbtn.approve{background:#16a34a;color:#fff}.kapp .kbtn.approve:hover{background:#15803d}
.kapp .kbtn.reject{background:#fff;color:#b91c1c;border:1px solid #fca5a5}.kapp .kbtn.reject:hover{background:#fef2f2}
.kapp .kbtn.ghost{background:#f0f1e6;color:#42571d}
.kapp .card{border:1px solid #e2e8ee;border-radius:14px;padding:16px 18px;margin:12px 0;background:#fff;box-shadow:0 1px 2px rgba(15,30,46,.04)}
.kapp .card .top{display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap}
.kapp .amt{font-size:1.5rem;font-weight:800;color:#10150d;font-variant-numeric:tabular-nums}
.kapp .who{font-weight:800;color:#10150d;font-size:1.05rem}
.kapp .meta{color:#5a6b7b;font-size:13px;margin-top:2px}
.kapp dl.facts{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:12px 0 0}
@media(max-width:700px){.kapp dl.facts{grid-template-columns:repeat(2,1fr)}}
.kapp dl.facts dt{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5a6b7b}
.kapp dl.facts dd{margin:2px 0 0;font-weight:700;color:#10150d;font-size:14px}
.kapp .tag{display:inline-block;background:#f0f1e6;color:#42571d;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;margin:1px 2px}
.kapp .actions{display:flex;gap:10px;align-items:center;margin-top:14px;flex-wrap:wrap}
.kapp .actions input[type=text]{flex:1;min-width:180px;border:1px solid #d9e1e8;border-radius:8px;padding:8px 10px;font-size:13px}
.kapp .pill{padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700}
.kapp .ap-approved{background:#dcfce7;color:#166534}.kapp .ap-rejected{background:#fee2e2;color:#991b1b}
.kapp table{border-collapse:collapse;width:100%}
.kapp table.log th,.kapp table.log td{padding:7px 10px;border-bottom:1px solid #eef2f5;text-align:left;font-size:13px}
.kapp table.log th{background:#f4f7f9;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5a6b7b}
.kapp .empty{padding:22px;text-align:center;color:#5a6b7b;background:#f8fbfb;border:1px dashed #cfe0dc;border-radius:12px}
</style>
<div class="kapp">
    <p><?php echo __('Review salaries entered by finance. A salary joins payroll only once you approve it here.'); ?>
       &nbsp;<a class="kbtn ghost" href="<?php echo htmlspecialchars($salaryURL); ?>"><?php echo __('← Back to staff salaries'); ?></a></p>

    <?php if ($decisionResult !== null): ?>
        <div class="<?php echo $decisionResult['ok'] ? 'success' : 'warning'; ?>">
            <?php if ($decisionResult['ok']): ?>
                <?php echo $decisionResult['decision'] === 'Approved'
                    ? __('Salary approved — it is now payroll-eligible.')
                    : __('Salary rejected — it will not be placed on payroll.'); ?>
            <?php else: ?>
                <?php echo __('No change was made (the item may have already been decided).'); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h3><?php echo __('Awaiting approval'); ?> (<?php echo count($pending); ?>)</h3>
    <?php if (empty($pending)): ?>
        <div class="empty"><?php echo __('Nothing is awaiting approval. New salaries entered by finance will appear here.'); ?></div>
    <?php else: foreach ($pending as $s):
        $enteredBy = $staffName($s, 'c');
        ?>
        <div class="card">
            <div class="top">
                <div style="flex:1">
                    <div class="who"><?php echo htmlspecialchars($staffName($s)); ?>
                        <span class="meta" style="font-weight:600">(<?php echo htmlspecialchars($s['username']); ?>)</span></div>
                    <div class="meta"><?php echo sprintf(__('Entered by %1$s on %2$s'),
                        htmlspecialchars($enteredBy ?: __('finance')),
                        htmlspecialchars((string) ($s['timestampCreator'] ?? ''))); ?></div>
                </div>
                <div class="amt"><?php echo htmlspecialchars($rowSymbol($s['currency'] ?? '')); ?><?php echo number_format((float) $s['amount'], 2); ?> <span class="meta" style="font-size:13px;font-weight:600"><?php echo htmlspecialchars(strtoupper((string) ($s['currency'] ?? '')) ?: $defaultCurCode); ?></span>
                    <span class="meta" style="font-size:13px;font-weight:600">/ <?php echo htmlspecialchars($s['frequency']); ?></span></div>
            </div>
            <dl class="facts">
                <div><dt><?php echo __('Pay day'); ?></dt><dd><?php echo htmlspecialchars((string) ($s['payDay'] ?? '') ?: '—'); ?></dd></div>
                <div><dt><?php echo __('Start'); ?></dt><dd><?php echo htmlspecialchars((string) $s['startDate']); ?></dd></div>
                <div><dt><?php echo __('End'); ?></dt><dd><?php echo htmlspecialchars($s['endDate'] ?: '—'); ?></dd></div>
                <div><dt><?php echo __('Category'); ?></dt><dd><?php echo htmlspecialchars((string) ($s['category'] ?? '') ?: '—'); ?></dd></div>
            </dl>
            <?php if (!empty($s['tags']) || !empty($s['notes'])): ?>
                <div style="margin-top:10px">
                    <?php foreach (array_filter(array_map('trim', explode(',', (string) ($s['tags'] ?? '')))) as $t): ?><span class="tag"><?php echo htmlspecialchars($t); ?></span><?php endforeach; ?>
                    <?php if (!empty($s['notes'])): ?><div class="meta" style="margin-top:6px"><?php echo nl2br(htmlspecialchars((string) $s['notes'])); ?></div><?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo htmlspecialchars($self); ?>" class="actions">
                <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_salary_approvals.php">
                <input type="hidden" name="id" value="<?php echo (int) $s['kreckSalaryID']; ?>">
                <input type="text" name="note" placeholder="<?php echo __('Optional note / reason…'); ?>" maxlength="500">
                <button type="submit" name="kreckAction" value="approve" class="kbtn approve"><?php echo __('Approve'); ?></button>
                <button type="submit" name="kreckAction" value="reject" class="kbtn reject"><?php echo __('Reject'); ?></button>
            </form>
        </div>
    <?php endforeach; endif; ?>

    <h3 style="margin-top:24px"><?php echo __('Recent decisions'); ?></h3>
    <?php if (empty($recent)): ?>
        <div class="empty"><?php echo __('No decisions yet.'); ?></div>
    <?php else: ?>
        <table class="log">
            <thead><tr><th><?php echo __('Staff'); ?></th><th><?php echo __('Amount'); ?></th><th><?php echo __('Decision'); ?></th><th><?php echo __('By'); ?></th><th><?php echo __('When'); ?></th><th><?php echo __('Note'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($staffName($r)); ?> <span class="emphasis small">(<?php echo htmlspecialchars($r['username']); ?>)</span></td>
                    <td><?php echo htmlspecialchars($rowSymbol($r['currency'] ?? '')); ?><?php echo number_format((float) $r['amount'], 2); ?> <span class="emphasis small"><?php echo htmlspecialchars(strtoupper((string) ($r['currency'] ?? '')) ?: $defaultCurCode); ?></span></td>
                    <td><span class="pill <?php echo $r['approvalStatus'] === 'Approved' ? 'ap-approved' : 'ap-rejected'; ?>"><?php echo htmlspecialchars($r['approvalStatus']); ?></span></td>
                    <td><?php echo htmlspecialchars($staffName($r, 'a') ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($r['approvalTimestamp'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string) ($r['approvalNote'] ?? '') ?: '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php
echo ob_get_clean();
