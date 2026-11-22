<?php
/**
 * KRECK — comprehensive demo seeder (CLI wrapper).
 *
 * Seeds 15 logins across every actor + one of (nearly) everything so the whole
 * system can be demonstrated. The logic lives in src/DemoSeeder.php so the same
 * engine backs both this CLI and the admin "Seed Demo Data" page in the module.
 *
 * Run from the SMS web root:
 *     php "modules/Kreck Bridge/cli/kreck_demo_seed.php"
 *
 * Idempotent: each run first wipes anything a previous run created, then reseeds.
 * It prints the 15 logins (all share one password) at the end.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require __DIR__ . '/../../../gibbon.php';

if (!function_exists('isCommandLineInterface') || !isCommandLineInterface()) {
    fwrite(STDERR, "The KRECK demo seeder runs on the command line only.\n");
    exit(2);
}

require_once __DIR__ . '/../src/DemoSeeder.php';

/** @var \PDO $connection2 */
$seeder = new KreckDemoSeeder($connection2, $container);
$result = $seeder->seed();

echo "KRECK demo seeder\n" . str_repeat('=', 66) . "\n";
foreach ($result['log'] as $line) { echo '  ' . $line . "\n"; }

echo "\n" . str_repeat('=', 66) . "\n";
echo "DEMO LOGINS   (password for all: " . $result['password'] . ")\n";
echo str_repeat('-', 66) . "\n";
$roleName = ['001' => 'Admin', '002' => 'Teacher', '003' => 'Student', '004' => 'Parent', '006' => 'Support'];
foreach ($result['accounts'] as $a) {
    [$key, $username, $first, $sur, $role, $gender, $desc] = $a;
    echo sprintf("  %-20s %-9s %s (%s %s)\n", $username, $roleName[$role] ?? $role, $desc, $first, $sur);
}
echo str_repeat('=', 66) . "\n";
echo "Done. Log in at the SMS, then run KRECK Export to sync this demo to the LMS.\n";

exit($result['ok'] ? 0 : 1);
