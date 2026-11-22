<?php
/**
 * Manifest parent emission (the SMS half of the LMS parent mirror).
 * Seeds one parent↔student family link, builds the manifest, and asserts the
 * parent appears in parents[] carrying that child's join key — the exact data
 * the LMS consumes to create the parent account and link it to the child.
 */

require_once __DIR__ . '/../src/ManifestBuilder.php';
require_once __DIR__ . '/../src/FamilyLink.php';

kt_test('manifest.parents emits a linked parent with the child join key', function () {
    $ctx = kt_ctx();
    if (!$ctx['existingAdultID']) { kt_skip('no existing adult to link'); }

    $pdo = kt_pdo();
    $fl  = new KreckFamilyLink($pdo, kt_container());

    // Pick an un-parented student straight from the method that defines the set.
    $candidates = array_map('strval', array_column($fl->studentsWithoutParent($ctx['yearID']), 'gibbonPersonID'));
    if (empty($candidates)) { kt_skip('no un-parented students in the current year'); }
    $studentID = null;
    foreach ($candidates as $pid) {
        if ($pid !== (string) $ctx['existingAdultID']) { $studentID = $pid; break; }
    }
    if ($studentID === null) { kt_skip('only candidate coincides with the adult'); }

    // The child's manifest join key (same rule as students[] / parents.children).
    $srow = $pdo->query("SELECT studentID FROM gibbonPerson WHERE gibbonPersonID = " . (int) $studentID)
        ->fetch(\PDO::FETCH_ASSOC);
    $joinKey = !empty($srow['studentID']) ? (string) $srow['studentID'] : ('GIB' . (int) $studentID);

    // Clean up only the family rows this test creates.
    kt_cleanup_new_rows([
        'gibbonFamily'             => 'gibbonFamilyID',
        'gibbonFamilyChild'        => 'gibbonFamilyChildID',
        'gibbonFamilyAdult'        => 'gibbonFamilyAdultID',
        'gibbonFamilyRelationship' => 'gibbonFamilyRelationshipID',
    ]);

    $res = $fl->linkExisting($studentID, $ctx['existingAdultID'], 'Guardian', 1);
    kt_assert(!empty($res['ok']), 'linkExisting succeeded: ' . ($res['error'] ?? ''));

    $m = (new KreckManifestBuilder($pdo, []))->build(['gibbonSchoolYearID' => $ctx['yearID']]);
    kt_assert(array_key_exists('parents', $m) && is_array($m['parents']), 'manifest carries a parents[] array');

    $want = 'gibbonPerson:' . (int) $ctx['existingAdultID'];
    $found = null;
    foreach ($m['parents'] as $p) {
        if (($p['source_id'] ?? '') === $want) { $found = $p; break; }
    }
    kt_assert($found !== null, 'the linked parent appears in parents[]');
    foreach (['source_id', 'username', 'firstname', 'lastname', 'email', 'children', 'contactpriority'] as $k) {
        kt_assert(array_key_exists($k, $found), "parent entry has '$k'");
    }
    kt_assert(in_array($joinKey, array_map('strval', $found['children']), true),
        "parent.children includes the child join key ($joinKey)");
});
