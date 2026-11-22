<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Seed Demo Data (admin only).

A friendly, one-click wrapper around the KRECK demo seeder: populates 15 logins
across every actor and one of (nearly) everything — timetable, lessons,
attendance, grades, students, parents, activities, fees/invoices and payroll —
so the whole system can be demonstrated. Idempotent: seeding first clears any
previous demo data and reseeds. A "Remove demo data" action wipes it cleanly.

All logic lives in src/DemoSeeder.php (shared with cli/kreck_demo_seed.php).
*/

require_once __DIR__ . '/src/DemoSeeder.php';

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_demo_seed.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_demo_seed.php';

$seedResult = null;   // ['ok','log','accounts','password'] after a seed run
$wipeCount  = null;   // int after a remove run
$runError   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['kreckAction'] ?? '';
    try {
        $seeder = new KreckDemoSeeder($connection2, $container);
        if ($action === 'seed' && ($_POST['confirm'] ?? '') === 'Y') {
            $seedResult = $seeder->seed();
        } elseif ($action === 'wipe') {
            $wipeCount = $seeder->wipe();
        } elseif ($action === 'seed') {
            $runError = __('Please tick the confirmation box before seeding.');
        }
    } catch (\Throwable $e) {
        KreckBridgeDiagnostics::reportCaught($e, 'demo seed');
        $runError = __('The demo seeder hit an error:') . ' ' . $e->getMessage();
    }
}

// Current demo footprint (so the admin sees whether demo data is present).
$demoCount = (int) $connection2->query("SELECT COUNT(*) FROM gibbonPerson WHERE username LIKE 'demo.%'")->fetchColumn();

$roleName = ['001' => 'Admin', '002' => 'Teacher', '003' => 'Student', '004' => 'Parent', '006' => 'Support'];
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

ob_start();
?>
<style>
.kds{max-width:900px;}
.kds h2{font-family:Georgia,"Times New Roman",Times,serif;color:#0f2233;margin:0 0 .3rem;border-bottom:3px solid #0e9f9a;padding-bottom:8px;}
.kds .kds-lead{color:#334155;font-size:15px;line-height:1.6;margin:14px 0;}
.kds .kds-card{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #0e9f9a;border-radius:12px;padding:1rem 1.2rem;margin:1rem 0;box-shadow:0 1px 3px rgba(15,30,46,.06);}
.kds .kds-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.5rem 1.4rem;margin:.4rem 0;color:#475569;font-size:14px;}
.kds .kds-grid li{list-style:none;padding:.15rem 0;}
.kds .kds-grid li::before{content:"✓";color:#0e9f9a;font-weight:700;margin-right:.5rem;}
.kds form{display:inline;}
.kds .kbtn{display:inline-block;background:#0e9f9a;color:#fff;border:none;text-decoration:none;border-radius:8px;padding:.6rem 1.1rem;font-weight:600;font-size:14px;cursor:pointer;}
.kds .kbtn.kbtn-danger{background:#b91c1c;}
.kds .kbtn:disabled{opacity:.5;cursor:not-allowed;}
.kds .kds-note{color:#92400e;background:#fef3c7;border-radius:8px;padding:.6rem .9rem;font-size:13px;margin:.6rem 0;}
.kds .kds-ok{color:#166534;background:#dcfce7;border-radius:8px;padding:.6rem .9rem;font-weight:600;margin:.6rem 0;}
.kds .kds-err{color:#991b1b;background:#fee2e2;border-radius:8px;padding:.6rem .9rem;font-weight:600;margin:.6rem 0;}
.kds .kds-log{background:#0f1b14;color:#d7f0e4;border-radius:10px;padding:.9rem 1.1rem;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.7;overflow:auto;}
.kds table{border-collapse:collapse;width:100%;margin-top:.5rem;font-size:13.5px;}
.kds th,.kds td{text-align:left;padding:.4rem .6rem;border-bottom:1px solid #eef2f6;}
.kds th{color:#0e6b63;text-transform:uppercase;letter-spacing:.03em;font-size:11.5px;}
.kds code{background:#f1f5f9;border-radius:5px;padding:.1rem .4rem;font-weight:700;color:#0f2233;}
.kds label.kds-confirm{display:block;margin:.6rem 0;color:#334155;font-size:14px;}
</style>
<div class="kds">
  <h2><?php echo $e(__('Seed Demo Data')); ?></h2>
  <p class="kds-lead">
    <?php echo $e(__('Populate this installation with a complete, ready-to-demo dataset: 15 logins across every actor, plus at least one of (nearly) everything — timetable, lessons, attendance, grades, students, parents, activities, fees/invoices and payroll.')); ?>
  </p>

  <div class="kds-card">
    <strong><?php echo $e(__('This run adds:')); ?></strong>
    <ul class="kds-grid">
      <li><?php echo $e(__('15 logins (admin, principal, finance, registrar, HOD, teachers, support, students, parents)')); ?></li>
      <li><?php echo $e(__('A demo class slotted into the timetable')); ?></li>
      <li><?php echo $e(__('Parent↔student family links')); ?></li>
      <li><?php echo $e(__('Simulated attendance across the year')); ?></li>
      <li><?php echo $e(__('Invoices: paid, part-paid & outstanding')); ?></li>
      <li><?php echo $e(__('An enrichment activity enrolment')); ?></li>
      <li><?php echo $e(__('An approved KRECK assessment with grades')); ?></li>
      <li><?php echo $e(__('Planner lessons + payroll (salaries & payments)')); ?></li>
    </ul>
    <p class="kds-note">
      <?php echo $e(__('Seeding is idempotent — it first clears any previous demo data (the demo.* accounts) and reseeds. It only adds clearly-marked demo records; it never changes your real data.')); ?>
    </p>
    <?php if ($demoCount > 0) { ?>
      <p style="color:#475569;font-size:13px;margin:.3rem 0;"><?php echo sprintf($e(__('Demo data is currently present (%d demo accounts).')), $demoCount); ?></p>
    <?php } ?>

    <form method="post" action="<?php echo $e($self); ?>">
      <input type="hidden" name="kreckAction" value="seed">
      <label class="kds-confirm">
        <input type="checkbox" name="confirm" value="Y" required>
        <?php echo $e(__('I understand this adds demo accounts and data to this installation.')); ?>
      </label>
      <button type="submit" class="kbtn"><?php echo $e($demoCount > 0 ? __('Re-seed demo data') : __('Seed demo data')); ?></button>
    </form>
    <?php if ($demoCount > 0) { ?>
      <form method="post" action="<?php echo $e($self); ?>" onsubmit="return confirm('<?php echo $e(__('Remove all demo data (the demo.* accounts and their records)?')); ?>');">
        <input type="hidden" name="kreckAction" value="wipe">
        <button type="submit" class="kbtn kbtn-danger"><?php echo $e(__('Remove demo data')); ?></button>
      </form>
    <?php } ?>
  </div>

  <?php if ($runError) { ?>
    <p class="kds-err"><?php echo $e($runError); ?></p>
  <?php } ?>

  <?php if ($wipeCount !== null) { ?>
    <p class="kds-ok"><?php echo sprintf($e(__('Removed demo data (%d accounts and their records).')), $wipeCount); ?></p>
  <?php } ?>

  <?php if ($seedResult !== null) { ?>
    <?php if (!empty($seedResult['ok'])) { ?>
      <p class="kds-ok"><?php echo $e(__('Demo data seeded. The 15 logins below all use the same password.')); ?></p>
    <?php } else { ?>
      <p class="kds-err"><?php echo $e(__('Seeding did not complete — see the log below.')); ?></p>
    <?php } ?>

    <div class="kds-card">
      <strong><?php echo $e(__('What happened')); ?></strong>
      <div class="kds-log"><?php foreach ($seedResult['log'] as $line) { echo $e($line) . "\n"; } ?></div>
    </div>

    <div class="kds-card">
      <strong><?php echo $e(__('Demo logins')); ?></strong>
      — <?php echo $e(__('password for all:')); ?> <code><?php echo $e($seedResult['password']); ?></code>
      <table>
        <thead><tr><th><?php echo $e(__('Username')); ?></th><th><?php echo $e(__('Role')); ?></th><th><?php echo $e(__('Who')); ?></th></tr></thead>
        <tbody>
        <?php foreach ($seedResult['accounts'] as $a) {
            [$key, $username, $first, $sur, $role, $gender, $desc] = $a; ?>
          <tr>
            <td><code><?php echo $e($username); ?></code></td>
            <td><?php echo $e($roleName[$role] ?? $role); ?></td>
            <td><?php echo $e($desc . ' — ' . $first . ' ' . $sur); ?></td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
      <p class="kds-note" style="margin-top:.8rem;">
        <?php echo $e(__('To demo the LMS side too, run KRECK → Export to sync this demo to Moodle (creates the parent mirror on the LMS).')); ?>
      </p>
    </div>
  <?php } ?>
</div>
<?php
echo ob_get_clean();
