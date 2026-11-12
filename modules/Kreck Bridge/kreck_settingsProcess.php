<?php
/*
Kreck Bridge — Settings (process).

Saves the module's own settings via Gibbon's SettingGateway (no raw SQL), then
redirects back with the standard success/error banner.
*/

use Gibbon\Data\Validator;
use Gibbon\Domain\System\SettingGateway;

require_once __DIR__ . '/../../gibbon.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_settings.php';

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_settings.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

$settingGateway = $container->get(SettingGateway::class);

$names = ['moodleEndpoint', 'defaultPassword', 'forcePasswordChange', 'schoolDomain', 'payrollOfficer'];

$partialFail = false;
foreach ($names as $name) {
    if (!isset($_POST[$name])) {
        continue;
    }
    $value = trim((string) $_POST[$name]);
    // Normalise the endpoint: strip a trailing slash so stored links join cleanly.
    if ($name === 'moodleEndpoint') {
        $value = rtrim($value, '/');
    }
    $updated = $settingGateway->updateSettingByScope('Kreck Bridge', $name, $value);
    $partialFail = $partialFail || ($updated === false);
}

$URL .= $partialFail ? '&return=error2' : '&return=success0';
header("Location: {$URL}");
