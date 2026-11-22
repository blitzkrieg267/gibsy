<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Students without a parent (the KRECK rule, passively enforced).

Lists active students who are not linked to any parent/guardian via a Gibbon
family. The rule is PASSIVE: nothing is blocked; this page simply surfaces who
needs a parent linked, each row deep-linking to the quick "Link Parent" action.
*/

require_once __DIR__ . '/src/FamilyLink.php';

use Gibbon\Services\Format;

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_students_noparent.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$fl = new KreckFamilyLink($connection2, $container);

// Selected school year: ?gibbonSchoolYearID override, else the current year.
$currentYearID = $connection2->query("SELECT gibbonSchoolYearID FROM gibbonSchoolYear WHERE status='Current' LIMIT 1")->fetchColumn();
$syid = isset($_GET['gibbonSchoolYearID']) && $_GET['gibbonSchoolYearID'] !== '' ? (string) $_GET['gibbonSchoolYearID'] : (string) $currentYearID;

$students = $fl->studentsWithoutParent($syid);
$linkBase = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_link_parent.php';

$page->breadcrumbs->add(__('Students Without a Parent'));

ob_start();
?>
<style>
.knp{max-width:900px}
.knp .lead{background:#f6f8f0;border:1px solid #d9e3cf;color:#42571d;border-radius:10px;padding:12px 16px;margin:8px 0 16px;font-size:14px;line-height:1.5}
.knp .ok{background:#dcfce7;border-color:#bbf7d0;color:#166534}
.knp table{border-collapse:collapse;width:100%;background:#fff;border:1px solid #e2e8ee;border-radius:12px;overflow:hidden}
.knp th,.knp td{padding:9px 12px;border-bottom:1px solid #eef2f5;text-align:left;font-size:14px}
.knp th{background:#f4f7f9;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5a6b7b}
.knp .kbtn{background:#52701f;color:#fff;border:0;border-radius:8px;padding:6px 12px;font-weight:700;text-decoration:none;display:inline-block;font-size:13px}
.knp .kbtn:hover{background:#3c5618;color:#fff}
.knp .count{font-size:1.4rem;font-weight:800;color:#10150d}
</style>
<div class="knp">
    <?php if (empty($students)): ?>
        <div class="lead ok"><?php echo __('Every active student is linked to at least one parent/guardian. Nothing to do here.'); ?></div>
    <?php else: ?>
        <div class="lead">
            <span class="count"><?php echo count($students); ?></span>
            <?php echo __('active student(s) are not linked to a parent/guardian.'); ?>
            <?php echo __('This is the KRECK rule — the system keeps working, but each of these students should have a parent linked so the parent can access academics and fees.'); ?>
        </div>
        <table>
            <thead><tr>
                <th><?php echo __('Student'); ?></th>
                <th><?php echo __('Username'); ?></th>
                <th><?php echo __('Form Group'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars(Format::name('', $s['preferredName'], $s['surname'], 'Student', true)); ?></td>
                    <td><?php echo htmlspecialchars((string) $s['username']); ?></td>
                    <td><?php echo htmlspecialchars((string) $s['formGroup']); ?></td>
                    <td style="text-align:right">
                        <a class="kbtn" href="<?php echo htmlspecialchars($linkBase . '&gibbonPersonID=' . $s['gibbonPersonID']); ?>">
                            <?php echo __('Link a parent'); ?> &rarr;
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php
echo ob_get_clean();
