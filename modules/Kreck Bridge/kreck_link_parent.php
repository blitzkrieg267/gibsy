<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Quick "Link Parent to Student" action.

A KRECK-branded, streamlined instance of Gibbon's native family association:
link an EXISTING person as a student's parent/guardian, or CREATE a new parent
account (full Gibbon integrity — unique username, password policy, Parent role,
status log) and link them. Sets childDataAccess='Y' so the parent can access the
child's academics AND fees. All logic lives in src/FamilyLink.php (transactional).
*/

require_once __DIR__ . '/src/FamilyLink.php';

use Gibbon\Services\Format;

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_link_parent.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$fl = new KreckFamilyLink($connection2, $container);
$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_link_parent.php';
$noParentURL = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_students_noparent.php';

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['kreckAction'] ?? '') === 'link') {
    $studentID = (string) ($_POST['gibbonPersonIDStudent'] ?? '');
    $mode = ($_POST['mode'] ?? 'existing') === 'new' ? 'new' : 'existing';
    $relationship = trim((string) ($_POST['relationship'] ?? 'Guardian')) ?: 'Guardian';
    $contactPriority = (int) ($_POST['contactPriority'] ?? 1);
    if ($contactPriority < 1 || $contactPriority > 3) { $contactPriority = 1; }

    if ($studentID === '') {
        $result = ['ok' => false, 'error' => __('Please choose a student.')];
    } elseif ($mode === 'existing') {
        $username = trim((string) ($_POST['parentUsername'] ?? ''));
        $adultID = '';
        if ($username !== '') {
            $q = $connection2->prepare("SELECT gibbonPersonID FROM gibbonPerson WHERE username=:u");
            $q->execute(['u' => $username]);
            $adultID = (string) ($q->fetchColumn() ?: '');
        }
        if ($adultID === '') {
            $result = ['ok' => false, 'error' => sprintf(__('No person found with username "%s". Check the username, or use "Create a new parent".'), $username)];
        } else {
            $result = $fl->linkExisting($studentID, $adultID, $relationship, $contactPriority);
        }
    } else {
        $result = $fl->createParentAndLink($studentID, [
            'surname'            => $_POST['surname'] ?? '',
            'firstName'          => $_POST['firstName'] ?? '',
            'preferredName'      => $_POST['preferredName'] ?? '',
            'gender'             => $_POST['gender'] ?? 'Unspecified',
            'username'           => $_POST['newUsername'] ?? '',
            'password'           => $_POST['password'] ?? '',
            'email'              => $_POST['email'] ?? '',
            'phone1'             => $_POST['phone1'] ?? '',
            'passwordForceReset' => ($_POST['passwordForceReset'] ?? 'Y') === 'Y' ? 'Y' : 'N',
            'relationship'       => $relationship,
            'contactPriority'    => $contactPriority,
        ]);
    }
}

// Active students for the picker (prefill from ?gibbonPersonID or last POST).
$students = $connection2->query(
    "SELECT p.gibbonPersonID, p.surname, p.preferredName, p.username
     FROM gibbonPerson p
     WHERE p.status='Full' AND p.gibbonRoleIDPrimary=(SELECT gibbonRoleID FROM gibbonRole WHERE category='Student' ORDER BY gibbonRoleID LIMIT 1)
     ORDER BY p.surname, p.preferredName"
)->fetchAll(\PDO::FETCH_ASSOC);
$selectedStudent = (string) ($_POST['gibbonPersonIDStudent'] ?? ($_GET['gibbonPersonID'] ?? ''));

// Candidate existing adults (non-students) for the datalist convenience.
$adults = $connection2->query(
    "SELECT p.username, p.surname, p.preferredName
     FROM gibbonPerson p JOIN gibbonRole r ON r.gibbonRoleID=p.gibbonRoleIDPrimary
     WHERE p.status='Full' AND r.category<>'Student'
     ORDER BY p.surname, p.preferredName LIMIT 1000"
)->fetchAll(\PDO::FETCH_ASSOC);

$page->breadcrumbs->add(__('Link Parent to Student'));

ob_start();
?>
<style>
.klp{max-width:720px}
.klp .lead{color:#42571d;font-size:14px;margin:4px 0 14px}
.klp .card{border:1px solid #e2e8ee;border-radius:14px;padding:18px 20px;background:#fff;margin:12px 0}
.klp label{display:block;font-weight:700;color:#10150d;font-size:13px;margin:10px 0 4px}
.klp .hint{font-weight:400;color:#5a6b7b;font-size:12px}
.klp input,.klp select{width:100%;box-sizing:border-box;border:1px solid #d9e1e8;border-radius:8px;padding:8px 10px;font-size:14px;background:#fff}
.klp .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:620px){.klp .row{grid-template-columns:1fr}}
.klp .kbtn{background:#52701f;color:#fff;border:0;border-radius:9px;padding:10px 18px;font-weight:700;cursor:pointer;font-size:14px}
.klp .kbtn:hover{background:#3c5618}
.klp .ghost{background:#f0f1e6;color:#42571d;text-decoration:none;display:inline-block;padding:9px 14px;border-radius:9px;font-weight:700}
.klp .modes{display:flex;gap:8px;margin:6px 0 2px}
.klp .modes label{display:flex;align-items:center;gap:6px;margin:0;border:1px solid #d9e1e8;border-radius:999px;padding:7px 14px;cursor:pointer;font-weight:700;background:#f8fbf4}
.klp .modes input{width:auto}
.klp .success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px 16px;margin-bottom:12px;font-weight:600}
.klp .warning{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;border-radius:10px;padding:12px 16px;margin-bottom:12px;font-weight:600}
</style>
<div class="klp">
    <p class="lead"><?php echo __('Associate a parent/guardian with a student using Gibbon families. The parent gains access to the child\'s academics and fees. A student may have more than one parent.'); ?>
        &nbsp;<a class="ghost" href="<?php echo htmlspecialchars($noParentURL); ?>"><?php echo __('← Students without a parent'); ?></a></p>

    <?php if ($result !== null): ?>
        <?php if (!empty($result['ok'])): ?>
            <div class="success"><?php echo __('Parent linked successfully.');
                if (!empty($result['username'])) echo ' ' . sprintf(__('New parent account "%s" was created and linked.'), htmlspecialchars($result['username'])); ?>
                &nbsp;<a href="<?php echo htmlspecialchars($noParentURL); ?>"><?php echo __('Back to the list'); ?></a></div>
        <?php else: ?>
            <div class="warning"><?php echo htmlspecialchars((string) ($result['error'] ?? __('Could not link the parent.'))); ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="<?php echo htmlspecialchars($self); ?>">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_link_parent.php">
        <input type="hidden" name="kreckAction" value="link">
        <div class="card">
            <label><?php echo __('Student'); ?> <span class="hint"><?php echo __('(the child)'); ?></span></label>
            <select name="gibbonPersonIDStudent" required>
                <option value=""><?php echo __('Select a student…'); ?></option>
                <?php foreach ($students as $s): ?>
                    <option value="<?php echo $s['gibbonPersonID']; ?>" <?php echo ((string) $s['gibbonPersonID'] === $selectedStudent ? 'selected' : ''); ?>>
                        <?php echo htmlspecialchars(Format::name('', $s['preferredName'], $s['surname'], 'Student', true) . ' (' . $s['username'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="row">
                <div>
                    <label><?php echo __('Relationship'); ?></label>
                    <select name="relationship">
                        <?php foreach (['Mother','Father','Guardian','Grandparent','Other'] as $rel): ?>
                            <option value="<?php echo $rel; ?>" <?php echo $rel === 'Guardian' ? 'selected' : ''; ?>><?php echo __($rel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo __('Contact priority'); ?> <span class="hint"><?php echo __('(1 = primary contact)'); ?></span></label>
                    <select name="contactPriority">
                        <option value="1" selected>1</option><option value="2">2</option><option value="3">3</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="modes">
                <label><input type="radio" name="mode" value="existing" checked onclick="document.getElementById('klp-existing').style.display='block';document.getElementById('klp-new').style.display='none';"> <?php echo __('Link an existing person'); ?></label>
                <label><input type="radio" name="mode" value="new" onclick="document.getElementById('klp-existing').style.display='none';document.getElementById('klp-new').style.display='block';"> <?php echo __('Create a new parent'); ?></label>
            </div>

            <div id="klp-existing">
                <label><?php echo __('Parent/guardian username'); ?></label>
                <input type="text" name="parentUsername" list="klp-adults" autocomplete="off" placeholder="<?php echo __('Start typing a name or username…'); ?>">
                <datalist id="klp-adults">
                    <?php foreach ($adults as $a): ?>
                        <option value="<?php echo htmlspecialchars($a['username']); ?>"><?php echo htmlspecialchars(Format::name('', $a['preferredName'], $a['surname'], 'Parent', true)); ?></option>
                    <?php endforeach; ?>
                </datalist>
                <p class="hint"><?php echo __('Enter the username of a person who already exists in the system.'); ?></p>
            </div>

            <div id="klp-new" style="display:none">
                <div class="row">
                    <div><label><?php echo __('First name'); ?> *</label><input type="text" name="firstName"></div>
                    <div><label><?php echo __('Surname'); ?> *</label><input type="text" name="surname"></div>
                </div>
                <div class="row">
                    <div><label><?php echo __('Preferred name'); ?></label><input type="text" name="preferredName" placeholder="<?php echo __('Defaults to first name'); ?>"></div>
                    <div><label><?php echo __('Gender'); ?></label>
                        <select name="gender"><option value="Unspecified"><?php echo __('Unspecified'); ?></option><option value="F"><?php echo __('Female'); ?></option><option value="M"><?php echo __('Male'); ?></option><option value="Other"><?php echo __('Other'); ?></option></select>
                    </div>
                </div>
                <div class="row">
                    <div><label><?php echo __('Username'); ?> * <span class="hint"><?php echo __('(login, must be unique)'); ?></span></label><input type="text" name="newUsername" autocomplete="off"></div>
                    <div><label><?php echo __('Password'); ?> * <span class="hint"><?php echo __('(meets school policy)'); ?></span></label><input type="password" name="password" autocomplete="new-password"></div>
                </div>
                <div class="row">
                    <div><label><?php echo __('Email'); ?></label><input type="email" name="email"></div>
                    <div><label><?php echo __('Phone'); ?></label><input type="text" name="phone1"></div>
                </div>
                <label style="font-weight:400;display:flex;align-items:center;gap:8px;margin-top:10px">
                    <input type="checkbox" name="passwordForceReset" value="Y" checked style="width:auto"> <?php echo __('Require the parent to change their password at first login'); ?>
                </label>
            </div>
        </div>

        <button type="submit" class="kbtn"><?php echo __('Link parent'); ?></button>
    </form>
</div>
<?php
echo ob_get_clean();
