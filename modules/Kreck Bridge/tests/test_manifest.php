<?php
/**
 * Institution sync manifest (what Import consumes on the LMS side).
 * build() is read-only — it assembles the export payload. This proves the
 * payload is well-formed and the required sections are present, so an import
 * downstream has the shape it expects.
 */

require_once __DIR__ . '/../src/ManifestBuilder.php';

kt_test('manifest.build assembles a well-formed sync payload', function () {
    $ctx = kt_ctx();
    if (!$ctx['yearID']) { kt_skip('no current school year'); }

    $builder = new KreckManifestBuilder(kt_pdo(), []);
    $m = $builder->build(['gibbonSchoolYearID' => $ctx['yearID'], 'limitClasses' => 0]);

    kt_assert(empty($m['_error']), 'build has no _error: ' . ($m['_error'] ?? ''));
    kt_eq('1.0', $m['schema_version'] ?? null, 'schema_version is 1.0');
    kt_assert(!empty($m['batch_metadata']['manifest_id']), 'manifest has a generated id');

    foreach (['categories', 'users', 'cohorts', 'students', 'courses', 'enrolments'] as $section) {
        kt_assert(array_key_exists($section, $m) && is_array($m[$section]), "section '$section' is present and an array");
    }
    kt_assert(isset($m['links']['students']) && isset($m['links']['courses']), 'SMS deep-links present');

    // Every student row carries the cross-system join keys the LMS import needs.
    if (!empty($m['students'])) {
        $first = $m['students'][0];
        foreach (['source_id', 'username', 'studentid', 'firstname', 'lastname', 'email'] as $k) {
            kt_assert(array_key_exists($k, $first), "student row has '$k'");
        }
    }
});
