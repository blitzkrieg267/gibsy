<?php
/**
 * Student↔parent family link (KRECK passive rule + quick link action).
 * Proves: a new parent is created with full Gibbon integrity and linked to a
 * student; integrity gates reject bad input WITHOUT creating orphans; linking
 * an existing person removes the student from the "no parent" warning list.
 *
 * All rows these tests create (gibbonPerson, gibbonFamily*, status log) are
 * removed afterwards by a snapshot cleanup that only deletes rows created after
 * the test began — nothing pre-existing is touched.
 */

require_once __DIR__ . '/../src/FamilyLink.php';

/** Tables a family test may create rows in, declared parent→child. */
const KT_FAMILY_TABLES = [
    'gibbonPerson'             => 'gibbonPersonID',
    'gibbonFamily'             => 'gibbonFamilyID',
    'gibbonFamilyChild'        => 'gibbonFamilyChildID',
    'gibbonFamilyAdult'        => 'gibbonFamilyAdultID',
    'gibbonFamilyRelationship' => 'gibbonFamilyRelationshipID',
    'gibbonPersonStatusLog'    => 'gibbonPersonStatusLogID',
];

function kt_family_link(): KreckFamilyLink {
    return new KreckFamilyLink(kt_pdo(), kt_container());
}

kt_test('family.createParentAndLink creates a valid parent and links the student', function () {
    $ctx = kt_ctx();
    if (!$ctx['studentNoParentID']) { kt_skip('no un-parented student to link'); }

    kt_cleanup_new_rows(KT_FAMILY_TABLES);
    $fl = kt_family_link();
    $uname = 'kt_p' . substr((string) mt_rand(10000, 99999), 0, 5);

    $res = $fl->createParentAndLink($ctx['studentNoParentID'], [
        'surname'   => 'Krecktest',
        'firstName' => 'Parent',
        'gender'    => 'Unspecified',
        'username'  => $uname,
        'password'  => 'Abcdef#9xy',
        'email'     => '',
    ]);
    kt_assert(!empty($res['ok']), 'createParentAndLink succeeded: ' . ($res['error'] ?? ''));

    $adultID = (int) $res['gibbonPersonIDAdult'];
    $person = kt_pdo()->query(
        "SELECT status, canLogin, gibbonRoleIDPrimary, passwordStrong, passwordStrongSalt, username
         FROM gibbonPerson WHERE gibbonPersonID = $adultID"
    )->fetch(\PDO::FETCH_ASSOC);
    kt_eq('Full', $person['status'], 'parent is Full status');
    kt_eq('Y', $person['canLogin'], 'parent can log in');
    kt_eq($fl->parentRoleID(), $person['gibbonRoleIDPrimary'], 'parent has the Parent role');
    kt_assert(strlen((string) $person['passwordStrong']) === 64, 'salted sha256 password hash stored');
    kt_assert(!empty($person['passwordStrongSalt']), 'password salt stored');

    // The link gives child-data access (academics + fees visibility).
    $cda = kt_pdo()->query(
        "SELECT fa.childDataAccess
         FROM gibbonFamilyAdult fa
         JOIN gibbonFamilyChild fc ON fc.gibbonFamilyID = fa.gibbonFamilyID
         WHERE fa.gibbonPersonID = $adultID AND fc.gibbonPersonID = {$ctx['studentNoParentID']}"
    )->fetchColumn();
    kt_eq('Y', $cda, 'childDataAccess granted (academics + fees)');
});

kt_test('family.createParentAndLink rejects bad input without creating orphans', function () {
    $ctx = kt_ctx();
    if (!$ctx['studentNoParentID']) { kt_skip('no un-parented student to link'); }

    kt_cleanup_new_rows(KT_FAMILY_TABLES);
    $fl = kt_family_link();
    $before = (int) kt_pdo()->query("SELECT COUNT(*) FROM gibbonPerson")->fetchColumn();

    // Missing required fields.
    $r1 = $fl->createParentAndLink($ctx['studentNoParentID'], ['surname' => '', 'firstName' => '', 'username' => '', 'password' => '']);
    kt_assert(empty($r1['ok']), 'missing fields rejected');

    // Weak password (fails the school policy).
    $r2 = $fl->createParentAndLink($ctx['studentNoParentID'], [
        'surname' => 'X', 'firstName' => 'Y', 'gender' => 'Unspecified',
        'username' => 'kt_weak' . mt_rand(100, 999), 'password' => 'abc',
    ]);
    kt_assert(empty($r2['ok']), 'weak password rejected');

    // Invalid username characters.
    $r3 = $fl->createParentAndLink($ctx['studentNoParentID'], [
        'surname' => 'X', 'firstName' => 'Y', 'gender' => 'Unspecified',
        'username' => 'bad name!', 'password' => 'Abcdef#9xy',
    ]);
    kt_assert(empty($r3['ok']), 'invalid username rejected');

    // Duplicate username: create one, then try the same name again.
    $uname = 'kt_dup' . mt_rand(1000, 9999);
    $ok = $fl->createParentAndLink($ctx['studentNoParentID'], [
        'surname' => 'Dup', 'firstName' => 'One', 'gender' => 'Unspecified',
        'username' => $uname, 'password' => 'Abcdef#9xy',
    ]);
    kt_assert(!empty($ok['ok']), 'first create with the username works');
    $dup = $fl->createParentAndLink($ctx['studentNoParentID'], [
        'surname' => 'Dup', 'firstName' => 'Two', 'gender' => 'Unspecified',
        'username' => $uname, 'password' => 'Abcdef#9xy',
    ]);
    kt_assert(empty($dup['ok']), 'duplicate username rejected');

    // Exactly one new person (the valid duplicate-seed) — the rejects made none.
    $after = (int) kt_pdo()->query("SELECT COUNT(*) FROM gibbonPerson")->fetchColumn();
    kt_eq($before + 1, $after, 'only the one valid create added a person (no orphans from rejects)');
});

kt_test('family.linkExisting removes the student from the "no parent" warning list', function () {
    $ctx = kt_ctx();
    if (!$ctx['existingAdultID']) { kt_skip('no existing adult to link'); }

    kt_cleanup_new_rows(KT_FAMILY_TABLES);
    $fl = kt_family_link();

    // Take the subject straight from the method under test, so the assertion is
    // self-consistent regardless of how the ctx helper defines "student".
    $before = array_map('strval', array_column($fl->studentsWithoutParent($ctx['yearID']), 'gibbonPersonID'));
    if (empty($before)) { kt_skip('no un-parented students in the current year'); }
    $studentID = null;
    foreach ($before as $pid) {
        if ($pid !== (string) $ctx['existingAdultID']) { $studentID = $pid; break; }
    }
    if ($studentID === null) { kt_skip('only candidate coincides with the adult'); }

    $res = $fl->linkExisting($studentID, $ctx['existingAdultID'], 'Guardian', 1);
    kt_assert(!empty($res['ok']), 'linkExisting succeeded: ' . ($res['error'] ?? ''));

    $after = array_map('strval', array_column($fl->studentsWithoutParent($ctx['yearID']), 'gibbonPersonID'));
    kt_assert(!in_array((string) $studentID, $after, true),
        'student drops off the no-parent list after linking');
});
