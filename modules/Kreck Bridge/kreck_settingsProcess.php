<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Settings (process).

Saves the module's own settings via SMS's SettingGateway (no raw SQL), then
redirects back with the standard success/error banner.
*/

use Gibbon\Data\Validator;
use Gibbon\Domain\System\SettingGateway;

require_once __DIR__ . '/../../gibbon.php';

// Capture the credential fields from the RAW request BEFORE Gibbon's input
// sanitizer runs. A long random token / webhook secret can contain characters
// the sanitizer strips, which would otherwise silently blank the saved value
// (the skip-if-empty guard below then leaves the stored secret untouched, so the
// admin thinks they saved it but the row stays empty). We store these verbatim.
$rawCredentials = [
    'moodleToken'   => array_key_exists('moodleToken', $_POST) ? (string) $_POST['moodleToken'] : null,
    'webhookSecret' => array_key_exists('webhookSecret', $_POST) ? (string) $_POST['webhookSecret'] : null,
];

$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_settings.php';

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_settings.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

$settingGateway = $container->get(SettingGateway::class);

$names = ['moodleEndpoint', 'moodleToken', 'webhookSecret', 'defaultPassword', 'forcePasswordChange', 'schoolDomain', 'payrollOfficer'];
$settingDefinitions = [
    'moodleEndpoint' => ['LMS Endpoint URL', 'Base URL of the Kreck LMS.'],
    'moodleToken' => ['LMS Web Service Token', 'Restricted token for local_kreck_sync_manifest and local_kreck_ping.'],
    'webhookSecret' => ['Shared Webhook Secret', 'Shared HMAC secret for LMS-to-SMS webhook authentication.'],
    'defaultPassword' => ['Default Password', 'Starter password for accounts created by the manifest.'],
    'forcePasswordChange' => ['Force Password Change', 'Require imported accounts to change their password.'],
    'schoolDomain' => ['School Email Domain', 'Domain used when creating placeholder email addresses.'],
    'payrollOfficer' => ['Payroll Officer', 'Optional SMS person ID for payroll reminders.'],
];

$partialFail = false;
$saveError = '';
foreach (['moodleToken', 'webhookSecret'] as $credentialName) {
    if ($rawCredentials[$credentialName] === null) {
        $partialFail = true;
        $saveError = 'fields_not_submitted';
    }
}

foreach ($names as $name) {
    $isCredential = in_array($name, ['moodleToken', 'webhookSecret'], true);
    if ($isCredential) {
        // Use the raw, un-sanitized value so the stored secret matches exactly.
        if ($rawCredentials[$name] === null) {
            continue;
        }
        $value = trim((string) $rawCredentials[$name]);
    } else {
        if (!isset($_POST[$name])) {
            continue;
        }
        $value = trim((string) $_POST[$name]);
    }
    if ($isCredential && $value === '') {
        continue;
    }
    // Normalise the endpoint: strip a trailing slash so stored links join cleanly.
    if ($name === 'moodleEndpoint') {
        $value = rtrim($value, '/');
    }
    try {
        $existing = $settingGateway->getSettingByScope('Kreck Bridge', $name, true);
        if (is_array($existing) && isset($existing['name'])) {
            $updated = $settingGateway->updateSettingByScope('Kreck Bridge', $name, $value);
        } else {
            [$display, $description] = $settingDefinitions[$name];
            $insert = $connection2->prepare(
                'INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
                 VALUES (:scope, :name, :nameDisplay, :description, :value)'
            );
            $updated = $insert->execute([
                'scope' => 'Kreck Bridge',
                'name' => $name,
                'nameDisplay' => $display,
                'description' => $description,
                'value' => $value,
            ]);
        }
        if ($updated === false) {
            $partialFail = true;
            $saveError = 'not_persisted';
        }
    } catch (PDOException $exception) {
        error_log('[KRECK-SETTINGS] Setting write failed; SQLSTATE=' . $exception->getCode());
        $partialFail = true;
        $saveError = 'not_persisted';
    }
}

foreach (['moodleToken', 'webhookSecret'] as $credentialName) {
    if ($rawCredentials[$credentialName] === null) {
        continue;
    }
    $submitted = trim((string) $rawCredentials[$credentialName]);
    if ($submitted === '') {
        continue;
    }
    try {
        $verify = $connection2->prepare(
            'SELECT value FROM gibbonSetting WHERE scope=:scope AND name=:name'
        );
        $verify->execute(['scope' => 'Kreck Bridge', 'name' => $credentialName]);
        $stored = $verify->fetchColumn();
        $matches = is_string($stored) && hash_equals($submitted, $stored);
    } catch (PDOException $exception) {
        error_log('[KRECK-SETTINGS] Credential read-back failed; SQLSTATE=' . $exception->getCode());
        $matches = false;
    }
    if (!$matches) {
        $partialFail = true;
        $saveError = 'not_persisted';
    }
}

$URL .= $partialFail
    ? '&return=error2&kreckSettingsError=' . $saveError
    : '&return=success0&kreckSettingsSave=verified';
header("Location: {$URL}");
