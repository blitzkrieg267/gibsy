<?php
/*
Kreck Bridge — Salary reminder + recurrence CLI (module-contained).

Run on a schedule (cron) to keep payroll steady:
  1. With --generate, auto-generate the current period's salary payments from the
     active recurring definitions (idempotent), so recurrence is hands-off.
  2. Remind finance of overdue / due-soon payments via Gibbon's notification
     system (in-app bell + email), bumping each payment's reminder count.

Server shell (classic cron):
    php "/path/to/gibbon/modules/Kreck Bridge/cli/kreck_salaryReminder.php" --generate
Web-cron (shared hosting), using the System Admin remote CLI key:
    https://your-gibbon/modules/Kreck%20Bridge/cli/kreck_salaryReminder.php?remoteCLIKey=KEY&generate=Y

Reuses Gibbon core only; no custom mail transport or scheduler.
*/

require __DIR__ . '/../../../gibbon.php';          // modules/Kreck Bridge/cli -> gibbon root
require_once __DIR__ . '/../src/SalaryManager.php';

use Gibbon\Contracts\Database\Connection;
use Gibbon\Comms\NotificationEvent;
use Gibbon\Comms\NotificationSender;
use Gibbon\Domain\System\NotificationGateway;
use Gibbon\Domain\System\SettingGateway;

$settingGateway = $container->get(SettingGateway::class);
$remoteCLIKey = $settingGateway->getSettingByScope('System Admin', 'remoteCLIKey');
$remoteCLIKeyInput = $_GET['remoteCLIKey'] ?? null;

if (!(isCommandLineInterface() || ($remoteCLIKey != '' && $remoteCLIKey == $remoteCLIKeyInput))) {
    print __('This script cannot be run from a browser, only via CLI.');
    return;
}

$manager = new KreckSalaryManager($container->get(Connection::class));
$doGenerate = (isset($_GET['generate']) && $_GET['generate'] === 'Y') || in_array('--generate', $argv ?? [], true);
$withinDays = 3;

// 1. Recurrence: generate this period's payments (idempotent).
if ($doGenerate) {
    $g = $manager->generatePeriod(KreckSalaryManager::currentPeriod(), 0);
    echo sprintf("Generated %d payment(s) for %s (%d already existed).\n", $g['created'], KreckSalaryManager::currentPeriod(), $g['skipped']);
}

// 2. Reminders for overdue / due-soon payments.
$due = $manager->dueReminders($withinDays);
$overdue = array_filter($due, fn($r) => (int) $r['daysUntilDue'] < 0);
$soon = array_filter($due, fn($r) => (int) $r['daysUntilDue'] >= 0);

$notificationGateway = $container->get(NotificationGateway::class);
$notificationSender = $container->get(NotificationSender::class);

if (!empty($due)) {
    $summary = sprintf(
        __('Payroll reminder: %1$d salary payment(s) overdue and %2$d due within %3$d days. Review the payroll run.'),
        count($overdue), count($soon), $withinDays
    );
    $link = '/index.php?q=/modules/Kreck Bridge/kreck_salary_run.php';

    $officer = $settingGateway->getSettingByScope('Kreck Bridge', 'payrollOfficer');
    if (!empty($officer)) {
        $notificationSender->addNotification((int) $officer, $summary, 'Kreck Bridge', $link);
    }

    $event = new NotificationEvent('Kreck Bridge', 'Salary Payment Due');
    if ($event->getEventDetails($notificationGateway, 'active') == 'Y') {
        $event->setNotificationText($summary);
        $event->setActionLink($link);
        $event->pushNotifications($notificationGateway, $notificationSender);
    }

    foreach ($due as $r) {
        $manager->bumpReminderCount($r['kreckSalaryPaymentID']);
    }
}

$report = $notificationSender->sendNotifications();
echo sprintf(
    "Reminders: %d overdue, %d due-soon. Sent %s notifications (%s emails sent, %s failed).\n",
    count($overdue), count($soon),
    $report['count'] ?? 0, $report['emailSent'] ?? 0, $report['emailFailed'] ?? 0
);
