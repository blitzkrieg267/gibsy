<?php
/**
 * KRECK Bridge — custom-flow test runner.
 *
 *   php "modules/Kreck Bridge/tests/run.php"
 *
 * Bootstraps Gibbon, loads every test_*.php in this directory, runs each test
 * in isolation (its own cleanup stack, run LIFO afterwards), and prints a
 * PASS / FAIL / SKIP summary. Exit code is non-zero if any test failed, so it
 * can gate a release.
 */

require __DIR__ . '/bootstrap.php';

// Load all test files (each calls kt_test(...) to register its cases).
foreach (glob(__DIR__ . '/test_*.php') as $file) {
    require $file;
}

$KT = &$GLOBALS['KT'];

fwrite(STDOUT, "\nKRECK custom-flow tests — " . count($KT['tests']) . " cases\n");
fwrite(STDOUT, str_repeat('─', 64) . "\n");

foreach ($KT['tests'] as [$name, $fn]) {
    $KT['cleanup'] = [];
    try {
        $fn();
        $KT['pass']++;
        fwrite(STDOUT, "  \033[32mPASS\033[0m  $name\n");
    } catch (KtSkip $s) {
        $KT['skip']++;
        fwrite(STDOUT, "  \033[33mSKIP\033[0m  $name  (" . $s->getMessage() . ")\n");
    } catch (\Throwable $e) {
        $KT['fail']++;
        $KT['failed'][] = $name;
        fwrite(STDOUT, "  \033[31mFAIL\033[0m  $name\n");
        fwrite(STDOUT, "        " . $e->getMessage() . "\n");
        fwrite(STDOUT, "        @ " . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    } finally {
        // Clean up whatever this test created, newest first; never fail the run.
        foreach (array_reverse($KT['cleanup']) as $c) {
            try { $c(); } catch (\Throwable $ce) {
                fwrite(STDOUT, "        (cleanup warning: " . $ce->getMessage() . ")\n");
            }
        }
    }
}

fwrite(STDOUT, str_repeat('─', 64) . "\n");
fwrite(STDOUT, sprintf("  %d passed, %d failed, %d skipped\n", $KT['pass'], $KT['fail'], $KT['skip']));
if ($KT['fail'] > 0) {
    fwrite(STDOUT, "  Failed: " . implode(', ', $KT['failed']) . "\n");
}
fwrite(STDOUT, "\n");

exit($KT['fail'] > 0 ? 1 : 0);
