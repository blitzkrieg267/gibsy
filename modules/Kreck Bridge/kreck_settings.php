<?php
/*
Kreck Bridge — Settings.

An in-app form for the module's own settings (scope 'Kreck Bridge'), so the
Moodle Endpoint URL and the export/payroll defaults can be edited in the UI
instead of the database. Reuses Gibbon's Form builder + SettingGateway; no core
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

$settingGateway = $container->get(SettingGateway::class);

// Fetch a Kreck Bridge setting row, tolerating a missing row (older installs).
$get = function (string $name) use ($settingGateway) {
    $row = $settingGateway->getSettingByScope('Kreck Bridge', $name, true);
    if (!is_array($row)) {
        $row = ['scope' => 'Kreck Bridge', 'name' => $name, 'nameDisplay' => $name, 'description' => '', 'value' => ''];
    }
    return $row;
};

$form = Form::create('kreckSettings', $session->get('absoluteURL') . '/modules/Kreck Bridge/kreck_settingsProcess.php');
$form->setFactory(DatabaseFormFactory::create($pdo));
$form->addHiddenValue('address', $session->get('address'));

// --- Moodle (Kreck LMS) connection -----------------------------------------
$form->addRow()->addHeading('Kreck LMS (Moodle) Connection', __('Kreck LMS (Moodle) Connection'));

$s = $get('moodleEndpoint');
$row = $form->addRow();
    $row->addLabel($s['name'], __('Moodle Endpoint URL'))
        ->description(__('Base URL of your Kreck LMS (Moodle), e.g. https://lms.yourschool.edu — no trailing slash. Used to build the "Open in KRECK LMS" links on the Kreck LMS Classes page.'));
    $row->addURL($s['name'])->setValue($s['value'])->maxLength(255);

// --- Export defaults (written into the Moodle manifest) ---------------------
$form->addRow()->addHeading('Export Defaults', __('Export Defaults'));

$s = $get('defaultPassword');
$row = $form->addRow();
    $row->addLabel($s['name'], __('Default Password'))
        ->description(__('Starter password written into the manifest for every created Moodle account, so logins work on first import.'));
    $row->addTextField($s['name'])->setValue($s['value'])->maxLength(50);

$s = $get('forcePasswordChange');
$row = $form->addRow();
    $row->addLabel($s['name'], __('Force Password Change'))
        ->description(__('If Yes, imported Moodle users must change their password at first login.'));
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
