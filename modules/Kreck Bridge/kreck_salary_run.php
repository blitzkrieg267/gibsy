<?php
/*
Kreck Bridge — Payroll run (per period).

Generate the period's salary payments from the active recurring definitions,
finalize them, and mark each paid by uploading a proof of payment. The proof is
stored via Gibbon's file system, a gibbonPayment ledger row is written, a receipt
is available, and the staff member is notified — all reusing Gibbon's own
machinery (FileUploader/FileHandler, gibbonPayment, NotificationSender).
Modelled on modules/Finance/expenseRequest_manage_reimburseProcess.php.
*/

require_once __DIR__ . '/src/SalaryManager.php';

use Gibbon\Contracts\Database\Connection;
use Gibbon\Filesystem\FileHandler;
use Gibbon\Comms\NotificationSender;
use Gibbon\Comms\NotificationEvent;
use Gibbon\Domain\System\NotificationGateway;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Services\Format;

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_salary_run.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$manager = new KreckSalaryManager($container->get(Connection::class));
$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_salary_run.php';
$kreckAction = $_REQUEST['kreckAction'] ?? '';
$period = preg_match('/^\d{4}-\d{2}$/', $_REQUEST['period'] ?? '') ? $_REQUEST['period'] : KreckSalaryManager::currentPeriod();
$notice = null; $error = null;

$currency = $container->get(SettingGateway::class)->getSettingByScope('System', 'currency');
$curSymbol = preg_replace('/\s*\[.*/', '', (string) $currency);

// ---- Generate period --------------------------------------------------------
if ($kreckAction === 'generate') {
    $g = $manager->generatePeriod($period, (int) $session->get('gibbonPersonID'));
    $notice = sprintf(__('Generated %1$d payment(s); %2$d already existed.'), $g['created'], $g['skipped']);
}

// ---- Finalize ---------------------------------------------------------------
if ($kreckAction === 'finalize' && !empty($_GET['id'])) {
    $manager->setStatus($_GET['id'], 'Finalized');
    $notice = __('Marked as finalized.');
}

// ---- Mark paid (proof upload + ledger + notify) -----------------------------
if ($kreckAction === 'markPaid' && !empty($_POST['kreckSalaryPaymentID'])) {
    $id = $_POST['kreckSalaryPaymentID'];
    $pay = $manager->getPayment($id);
    if (!$pay) {
        $error = __('Payment not found.');
    } else {
        $paymentDate = !empty($_POST['paymentDate']) ? Format::dateConvert($_POST['paymentDate']) : date('Y-m-d');
        $method = $_POST['paymentMethod'] ?? 'Bank Transfer';
        $attachment = '';

        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $fileUploader = new \Gibbon\FileUploader($container->get(Connection::class), $session);
            $title = 'Salary ' . $pay['surname'] . ' ' . $period;
            $attachment = $fileUploader->uploadFromPost($_FILES['file'], $title);
            if (!empty($attachment)) {
                // File tracking (gibbonFile/gibbonFilePointer) is best-effort — the
                // proof path is also stored on the salary row regardless.
                try {
                    $meta = $fileUploader->getFileMetaData($attachment);
                    $container->get(FileHandler::class)->recordFileUpload($meta, 'kreckSalaryPayment', $id, 'proofOfPayment');
                } catch (\Throwable $e) { /* file registry optional */ }
            }
        }

        // Record payment on the salary row.
        $manager->recordPayment($id, $paymentDate, $method, $attachment, $_POST['amount'] ?? null);

        // Write a gibbonPayment ledger entry (reuse Gibbon's polymorphic payment table).
        try {
            $ins = $connection2->prepare(
                "INSERT INTO gibbonPayment (foreignTable, foreignTableID, gibbonPersonID, type, status, amount, gateway, timestamp)
                 VALUES ('kreckSalaryPayment', :fid, :pid, 'Salary', 'Complete', :amount, 'Manual', NOW())"
            );
            $ins->execute(['fid' => $id, 'pid' => $pay['gibbonPersonID'], 'amount' => $_POST['amount'] ?? $pay['amount']]);
        } catch (\Throwable $e) { /* ledger is best-effort */ }

        // Notify the staff member (in-app + email), reusing Gibbon's notification system.
        try {
            $receiptLink = '/index.php?q=/modules/Kreck Bridge/kreck_salary_receipt.php&kreckSalaryPaymentID=' . $id;
            $text = sprintf(__('Your salary for %1$s (%2$s%3$s) has been paid. View your receipt.'),
                KreckSalaryManager::periodLabel($period), $curSymbol, number_format((float) ($_POST['amount'] ?? $pay['amount']), 2));
            $sender = $container->get(NotificationSender::class);
            $sender->addNotification((int) $pay['gibbonPersonID'], $text, 'Kreck Bridge', $receiptLink);
            $event = new NotificationEvent('Kreck Bridge', 'Salary Paid');
            if ($event->getEventDetails($container->get(NotificationGateway::class), 'active') == 'Y') {
                $event->pushNotifications($container->get(NotificationGateway::class), $sender);
            }
            $sender->sendNotifications();
        } catch (\Throwable $e) { /* notification best-effort */ }

        $notice = sprintf(__('Marked paid and notified %s.'), Format::name($pay['title'] ?? '', $pay['preferredName'], $pay['surname'], 'Staff', false));
    }
}

$payments = $manager->periodPayments($period);
$page->breadcrumbs
    ->add(__('Staff Salaries'), 'kreck_salary.php')
    ->add(__('Payroll Run'));

// Build the list of selectable periods (last 6 months + next month).
$periods = [];
for ($i = -6; $i <= 1; $i++) $periods[date('Y-m', strtotime("$i month"))] = KreckSalaryManager::periodLabel(date('Y-m', strtotime("$i month")));

$markForm = $_GET['markForm'] ?? '';
$markPay = $markForm ? $manager->getPayment($markForm) : null;

ob_start();
?>
<style>
.krun .kbtn{background:#0e9f9a;color:#fff;border:0;border-radius:6px;padding:7px 13px;font-weight:600;cursor:pointer;text-decoration:none;font-size:13px}
.krun .kbtn.amber{background:#f59e0b}.krun .kbtn.ghost{background:#eef5f4;color:#0e6b63}
.krun table{border-collapse:collapse;width:100%}
.krun table.list th,.krun table.list td{padding:7px 10px;border-bottom:1px solid #eef2f5;text-align:left;font-size:14px}
.krun table.list th{background:#f4f7f9}
.krun .pill{padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700}
.krun .Pending{background:#fef9c3;color:#854d0e}.krun .Finalized{background:#dbeafe;color:#1e40af}.krun .Paid{background:#dcfce7;color:#166534}
.krun .overdue{color:#b42318;font-weight:700}
.krun .markbox{border:1px solid #e2e8ee;border-radius:8px;padding:14px;margin:10px 0;background:#f8fbfb}
</style>
<div class="krun" style="max-width:1000px">
    <?php if ($notice): ?><div class="success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="get" action="<?php echo htmlspecialchars($self); ?>" style="margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_salary_run.php">
        <label><b><?php echo __('Period'); ?>:</b>
            <select name="period" onchange="this.form.submit()">
                <?php foreach ($periods as $pk => $pl): ?><option value="<?php echo $pk; ?>" <?php echo $pk === $period ? 'selected' : ''; ?>><?php echo htmlspecialchars($pl); ?></option><?php endforeach; ?>
            </select>
        </label>
        <a class="kbtn" href="<?php echo htmlspecialchars($self . '&period=' . $period . '&kreckAction=generate'); ?>"><?php echo __('Generate this period'); ?></a>
        <span class="emphasis small"><?php echo __('Creates pending payments from active salaries (safe to re-run).'); ?></span>
    </form>

    <?php if ($markPay): ?>
        <div class="markbox">
            <h3 style="margin-top:0"><?php echo __('Mark paid'); ?>: <?php echo htmlspecialchars(Format::name($markPay['title'] ?? '', $markPay['preferredName'], $markPay['surname'], 'Staff', false)); ?> — <?php echo htmlspecialchars(KreckSalaryManager::periodLabel($period)); ?></h3>
            <form method="post" action="<?php echo htmlspecialchars($self . '&period=' . $period); ?>" enctype="multipart/form-data">
                <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_salary_run.php">
                <input type="hidden" name="kreckSalaryPaymentID" value="<?php echo htmlspecialchars($markForm); ?>">
                <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
                    <label><?php echo __('Amount'); ?><br><input name="amount" type="number" step="0.01" value="<?php echo htmlspecialchars($markPay['amount']); ?>"></label>
                    <label><?php echo __('Payment Date'); ?><br><input name="paymentDate" type="date" value="<?php echo date('Y-m-d'); ?>"></label>
                    <label><?php echo __('Method'); ?><br><select name="paymentMethod"><option>Bank Transfer</option><option>Cash</option><option>Cheque</option><option>Mobile Money</option><option>Other</option></select></label>
                    <label><?php echo __('Proof of payment'); ?><br><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png"></label>
                    <button type="submit" class="kbtn amber" name="kreckAction" value="markPaid"><?php echo __('Confirm paid + notify'); ?></button>
                    <a class="kbtn ghost" href="<?php echo htmlspecialchars($self . '&period=' . $period); ?>"><?php echo __('Cancel'); ?></a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <h3><?php echo htmlspecialchars(KreckSalaryManager::periodLabel($period)); ?> — <?php echo count($payments); ?> <?php echo __('payment(s)'); ?></h3>
    <table class="list">
        <thead><tr><th><?php echo __('Staff'); ?></th><th><?php echo __('Amount'); ?></th><th><?php echo __('Due'); ?></th><th><?php echo __('Status'); ?></th><th><?php echo __('Actions'); ?></th></tr></thead>
        <tbody>
        <?php if (empty($payments)): ?>
            <tr><td colspan="5" class="emphasis"><?php echo __('No payments for this period yet — press “Generate this period”.'); ?></td></tr>
        <?php else: foreach ($payments as $p):
            $overdue = ($p['status'] !== 'Paid' && !empty($p['dueDate']) && $p['dueDate'] < date('Y-m-d')); ?>
            <tr>
                <td><?php echo htmlspecialchars(Format::name($p['title'] ?? '', $p['preferredName'], $p['surname'], 'Staff', false)); ?></td>
                <td><?php echo htmlspecialchars($curSymbol); ?><?php echo number_format((float) $p['amount'], 2); ?></td>
                <td class="<?php echo $overdue ? 'overdue' : ''; ?>"><?php echo htmlspecialchars((string) $p['dueDate']); ?><?php echo $overdue ? ' ⚠' : ''; ?></td>
                <td><span class="pill <?php echo htmlspecialchars($p['status']); ?>"><?php echo htmlspecialchars($p['status']); ?></span></td>
                <td>
                    <?php if ($p['status'] !== 'Paid'): ?>
                        <?php if ($p['status'] === 'Pending'): ?><a class="kbtn ghost" href="<?php echo htmlspecialchars($self . '&period=' . $period . '&kreckAction=finalize&id=' . $p['kreckSalaryPaymentID']); ?>"><?php echo __('Finalize'); ?></a><?php endif; ?>
                        <a class="kbtn amber" href="<?php echo htmlspecialchars($self . '&period=' . $period . '&markForm=' . $p['kreckSalaryPaymentID']); ?>"><?php echo __('Mark paid'); ?></a>
                    <?php else: ?>
                        <a class="kbtn ghost" target="_blank" href="<?php echo htmlspecialchars($session->get('absoluteURL') . '/report.php?q=/modules/Kreck Bridge/kreck_salary_receipt.php&kreckSalaryPaymentID=' . $p['kreckSalaryPaymentID']); ?>"><?php echo __('Receipt'); ?></a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php
echo ob_get_clean();
