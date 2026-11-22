<?php
/*
Kreck Bridge — daily assessment deadline reminders and notification retries.

Run once daily from the system scheduler:
php "/path/to/gibbon/modules/Kreck Bridge/cli/kreck_assessmentReminder.php"
*/

require __DIR__ . '/../../../gibbon.php';
require_once __DIR__ . '/../src/AssessmentManager.php';

use Gibbon\Comms\NotificationSender;

if (!isCommandLineInterface()) {
    http_response_code(403);
    echo __('This script can only be run from the command line.');
    return;
}

$manager = new KreckAssessmentManager($connection2);
$manager->enqueueDeadlineReminders();
$sender = $container->get(NotificationSender::class);

$totals = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'emailFailed' => 0];
do {
    $result = $manager->dispatchNotifications($sender);
    foreach ($totals as $key => $value) {
        $totals[$key] += (int) ($result[$key] ?? 0);
    }
} while ($result['queued'] >= 50 && $result['failed'] === 0);

printf(
    "Assessment notifications: %d processed, %d sent, %d failed, %d email delivery failure(s).\n",
    $totals['queued'],
    $totals['sent'],
    $totals['failed'],
    $totals['emailFailed']
);
if ($totals['failed'] > 0) {
    exit(1);
}
