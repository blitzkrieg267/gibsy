<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Settings.

An in-app form for the module's own settings (scope 'Kreck Bridge'), so the
LMS Endpoint URL and the export/payroll defaults can be edited in the UI
instead of the database. Reuses SMS's Form builder + SettingGateway; no core
edits, no raw SQL.
*/

use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Domain\System\SettingGateway;

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_settings.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Kreck Settings'));

$saveError = $_GET['kreckSettingsError'] ?? '';
if ($saveError === 'fields_not_submitted') {
    $page->addError(__('The save handler did not receive the LMS token and shared secret fields. Reload Kreck Settings after updating the module, then submit again.'));
} else if ($saveError === 'not_persisted') {
    $page->addError(__('SMS received a credential but could not confirm it in gibbonSetting. Check database write access and confirm this is the database used by this SMS site. The credential was not displayed.'));
}

$settingGateway = $container->get(SettingGateway::class);

// Fetch a Kreck Bridge setting row, tolerating a missing row (older installs).
$get = function (string $name) use ($settingGateway) {
    $row = $settingGateway->getSettingByScope('Kreck Bridge', $name, true);
    $exists = is_array($row) && !empty($row['name']);
    if (!$exists) {
        $row = ['scope' => 'Kreck Bridge', 'name' => $name, 'nameDisplay' => $name, 'description' => '', 'value' => ''];
    }
    $row['_kreckRowExists'] = $exists;
    return $row;
};

$form = Form::create('kreckSettings', $session->get('absoluteURL') . '/modules/Kreck Bridge/kreck_settingsProcess.php');
$form->setFactory(DatabaseFormFactory::create($pdo));
$form->addHiddenValue('address', $session->get('address'));

// --- LMS (Kreck LMS) connection -----------------------------------------
$form->addRow()->addHeading('Kreck LMS Connection', __('Kreck LMS Connection'));

$s = $get('moodleEndpoint');
$row = $form->addRow();
    $row->addLabel($s['name'], __('LMS Endpoint URL'))
        ->description(__('Base URL of your Kreck LMS, e.g. https://lms.yourschool.edu — no trailing slash. Used for web-service sync and LMS links.'));
    $row->addURL($s['name'])->setValue($s['value'])->maxLength(255);

$s = $get('moodleToken');
$tokenStatus = trim((string) ($s['value'] ?? '')) === ''
    ? __('Not configured. Enter the token and save.')
    : __('Configured. Saved value is hidden; leave blank to keep it.');
$row = $form->addRow();
    $row->addLabel($s['name'], __('LMS Web Service Token'))
        ->description(__('Restricted LMS token with access to local_kreck_sync_manifest and local_kreck_ping.') . ' ' . $tokenStatus);
    $row->addTextField($s['name'])->setValue('')->maxLength(255);

$s = $get('webhookSecret');
$secretStatus = trim((string) ($s['value'] ?? '')) === ''
    ? __('Not configured. Enter the shared secret and save.')
    : __('Configured. Saved value is hidden; leave blank to keep it.');
$row = $form->addRow();
    $row->addLabel($s['name'], __('Shared Webhook Secret'))
        ->description(__('Long random secret shared with LMS for HMAC-signed change notifications and LMS-to-SMS connection checks.') . ' ' . $secretStatus);
    $row->addTextField($s['name'])->setValue('')->maxLength(255);

// --- Export defaults (written into the LMS manifest) ---------------------
$form->addRow()->addHeading('Export Defaults', __('Export Defaults'));

$s = $get('defaultPassword');
$row = $form->addRow();
    $row->addLabel($s['name'], __('Default Password'))
        ->description(__('Starter password written into the manifest for every created LMS account, so logins work on first import.'));
    $row->addTextField($s['name'])->setValue($s['value'])->maxLength(50);

$s = $get('forcePasswordChange');
$row = $form->addRow();
    $row->addLabel($s['name'], __('Force Password Change'))
        ->description(__('If Yes, imported LMS users must change their password at first login.'));
    $row->addYesNo($s['name'])->selected($s['value'] ?: 'Y');

$s = $get('schoolDomain');
$row = $form->addRow();
    $row->addLabel($s['name'], __('School Email Domain'))
        ->description(__('Used to build placeholder emails (e.g. <studentID>@students.<domain>) when a person has no email, so the manifest still imports.'));
    $row->addTextField($s['name'])->setValue($s['value'])->maxLength(255);

// --- Payroll ----------------------------------------------------------------
$form->addRow()->addHeading('Payroll', __('Payroll'));

$s = $get('payrollOfficer');
$row = $form->addRow();
    $row->addLabel($s['name'], __('Payroll Officer'))
        ->description(__('Optional. gibbonPersonID of the staff member nudged by the salary reminder about due/overdue payments.'));
    $row->addTextField($s['name'])->setValue($s['value'])->maxLength(12);

$row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();

echo $form->getOutput();

    $dbInfo = $connection2->query('SELECT DATABASE() AS databaseName, @@hostname AS databaseHost')->fetch(PDO::FETCH_ASSOC);
    $moduleVersionQuery = $connection2->prepare('SELECT version FROM gibbonModule WHERE name=:name');
    $moduleVersionQuery->execute(['name' => 'Kreck Bridge']);
    $moduleVersion = $moduleVersionQuery->fetchColumn();
    $tokenDiagnostic = $get('moodleToken');
    $secretDiagnostic = $get('webhookSecret');
    $settingState = static function (array $setting): string {
        if (empty($setting['_kreckRowExists'])) {
            return __('Missing row');
        }
        $value = trim((string) ($setting['value'] ?? ''));
        return $value === '' ? __('Row is empty') : sprintf(__('Set (%s characters)'), strlen($value));
    };
    $saveVerified = ($_GET['kreckSettingsSave'] ?? '') === 'verified';
    $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

    echo '<section class="small emphasis" style="margin-top:16px;padding:12px" aria-labelledby="kreck-settings-debug">';
    echo '<h3 id="kreck-settings-debug">' . $escape(__('Connection and save diagnostics')) . '</h3>';
    echo '<dl>';
    echo '<dt>' . $escape(__('Active SMS database')) . '</dt><dd>' . $escape($dbInfo['databaseName'] ?? 'unknown') . '</dd>';
    echo '<dt>' . $escape(__('Database server')) . '</dt><dd>' . $escape($dbInfo['databaseHost'] ?? 'unknown') . '</dd>';
    echo '<dt>' . $escape(__('SMS site URL')) . '</dt><dd>' . $escape($session->get('absoluteURL')) . '</dd>';
    echo '<dt>' . $escape(__('Kreck Bridge module version')) . '</dt><dd>' . $escape($moduleVersion ?: 'not installed') . '</dd>';
    echo '<dt>' . $escape(__('LMS token setting row')) . '</dt><dd>' . $escape($settingState($tokenDiagnostic)) . '</dd>';
    echo '<dt>' . $escape(__('Shared webhook secret setting row')) . '</dt><dd>' . $escape($settingState($secretDiagnostic)) . '</dd>';
    echo '<dt>' . $escape(__('Webhook receiver URL on this installation')) . '</dt><dd>'
        . $escape(rtrim((string) $session->get('absoluteURL'), '/') . '/modules/Kreck%20Bridge/kreck_webhook.php') . '</dd>';
    if ($saveVerified) {
        echo '<dt>' . $escape(__('Last credential save')) . '</dt><dd>' . $escape(__('Save completed; current row state is shown above. Values are not displayed.')) . '</dd>';
    }
    echo '</dl></section>';
