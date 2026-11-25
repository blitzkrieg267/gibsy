<?php
/**
 * KRECK tab on the Home dashboard — the managed core patch (src/CorePatch.php).
 * Works on a throwaway copy of Gibbon's StaffDashboard.php, never on the live file.
 */

require_once __DIR__ . '/../src/CorePatch.php';

/** A temp Gibbon root holding a copy of the real dashboard file, returned with its pristine (unpatched) text. */
function kt_cp_root(): array {
    $root = sys_get_temp_dir() . '/kreck_cp_' . bin2hex(random_bytes(4));
    $dir = $root . '/src/UI/Dashboard';
    mkdir($dir, 0777, true);
    copy(KreckCorePatch::root() . '/src/UI/Dashboard/StaffDashboard.php', $dir . '/StaffDashboard.php');
    kt_cleanup(function () use ($root) { @unlink($root . '/src/UI/Dashboard/StaffDashboard.php'); @unlink($root . '/src/UI/Dashboard/StaffDashboard.php.kreck-orig');
        @rmdir($root . '/src/UI/Dashboard'); @rmdir($root . '/src/UI'); @rmdir($root . '/src'); @rmdir($root); });
    KreckCorePatch::revert('home-panel', $root);                       // whatever state the real file is in → pristine
    $pristine = file_get_contents($dir . '/StaffDashboard.php');
    return [$root, $dir . '/StaffDashboard.php', $pristine];
}

kt_test('corepatch.applies once, is idempotent, and reverts byte-for-byte', function () {
    [$root, $file, $pristine] = kt_cp_root();
    kt_assert(strpos($pristine, 'KRECK-PATCH') === false, 'starts unpatched');
    kt_eq('pending', KreckCorePatch::status($root)['home-panel']['state'], 'pending before');

    $r = KreckCorePatch::apply('home-panel', $root);
    kt_assert($r['ok'], 'apply succeeds: ' . $r['message']);
    kt_eq('applied', KreckCorePatch::status($root)['home-panel']['state'], 'applied after');
    $patched = file_get_contents($file);
    kt_assert(strpos($patched, "\$tabs['KRECK']") !== false, 'adds the KRECK tab');
    kt_assert(strpos($patched, "\$tabs['KRECK']") < strpos($patched, "\$tabs['Planner']"), 'the KRECK tab is added BEFORE the Planner tab');
    kt_assert(strpos($patched, "'icon'    => 'squares'") !== false, 'uses an icon Gibbon has');
    kt_eq(1, substr_count($patched, 'KRECK-PATCH-BEGIN home-panel'), 'one block');
    kt_assert(is_file($file . '.kreck-orig'), 'original backed up once');

    $again = KreckCorePatch::apply('home-panel', $root);
    kt_assert($again['ok'] && $again['message'] === 'Already applied.', 'second apply is a no-op');
    kt_eq($patched, file_get_contents($file), 'file unchanged by the second apply');

    $rev = KreckCorePatch::revert('home-panel', $root);
    kt_assert($rev['ok'], 'revert succeeds');
    kt_eq($pristine, file_get_contents($file), 'revert restores the original byte-for-byte');
});

kt_test('corepatch.upgrades the earlier "under the timetable" version in place', function () {
    [$root, $file, $pristine] = kt_cp_root();
    $patch = KreckCorePatch::patches()['home-panel'];
    // Build the 0.5.34-preview layout: the block + KRECK Home appended under the timetable.
    $legacy = $pristine;
    foreach ($patch['edits'] as $i => [$search, $replace, $olds]) {
        $legacy = str_replace($search, $olds ? $olds[0] : $replace, $legacy);
        if ($i === 0) { /* the computing block is shared */ }
    }
    file_put_contents($file, $legacy);
    kt_eq('outdated', KreckCorePatch::status($root)['home-panel']['state'], 'recognised as an older KRECK patch, not "drifted"');
    $st = KreckCorePatch::ensure($root);
    kt_eq('applied', $st['home-panel']['state'], 'ensure() upgrades it');
    $upgraded = file_get_contents($file);
    kt_assert(strpos($upgraded, 'directly after the timetable') === false, 'old wording gone');
    kt_assert(strpos($upgraded, "\$tabs['KRECK']") < strpos($upgraded, "\$tabs['Planner']"), 'now ahead of Planner');
    KreckCorePatch::revert('home-panel', $root);
    kt_eq($pristine, file_get_contents($file), 'and still reverts cleanly');
});

kt_test('corepatch.refuses to touch code Gibbon has changed (drifted)', function () {
    [$root, $file, $pristine] = kt_cp_root();
    $changed = str_replace("'icon'    => 'book-open',", "'icon'    => 'academic-cap',", $pristine);
    kt_assert($changed !== $pristine, 'test setup altered the anchor');
    file_put_contents($file, $changed);
    kt_eq('drifted', KreckCorePatch::status($root)['home-panel']['state'], 'reported as drifted');
    $r = KreckCorePatch::apply('home-panel', $root);
    kt_assert(!$r['ok'], 'apply refuses');
    kt_eq($changed, file_get_contents($file), 'the file is left exactly as it was');
});

kt_test('corepatch.the live Gibbon file parses and still has its tabs', function () {
    $code = file_get_contents(KreckCorePatch::root() . '/src/UI/Dashboard/StaffDashboard.php');
    token_get_all($code, TOKEN_PARSE);   // throws on a syntax error
    kt_assert(strpos($code, "\$tabs['Planner']") !== false && strpos($code, "\$tabs['Enrolment']") !== false, 'Planner and Enrolment tabs intact');
});
