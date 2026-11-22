# KRECK — End-to-end provisioning checklist

The automated suite (`run.php`) proves the **custom KRECK transactions** commit and roll back
correctly against a live Gibbon database. This checklist covers the **environment-level steps
around them** — clean install, theme, pairing, import — that are not unit-testable (they touch
two separate servers, web installers and admin UIs). Work top to bottom from two clean systems;
the right-hand column names the automated test that proves the data each step produces.

Legend: **SMS** = Gibbon (Kreck Bridge module). **LMS** = Moodle (`local_kreck` plugin).

| # | Step | Where / action | Expected result | Automated cover |
|---|------|----------------|-----------------|-----------------|
| 1 | Clean SMS | Fresh Gibbon install, admin created, a **Current** school year set | Login works; `gibbonSchoolYear.status='Current'` exists | — (prereq) |
| 2 | Clean LMS | Fresh Moodle install, admin created | Site admin reachable | — (prereq) |
| 3 | Install Bridge | SMS → System Admin → **Manage Modules** → install `kreck_bridge_gibbon_v0.5.28.zip` | Module **Kreck Bridge** active; `kreck*` tables created; KRECK Home reachable | `manifest.build` (reads the tables the install creates) |
| 4 | Install LMS plugin | LMS → Site admin → **Plugins → Install** `local_kreck_moodle_*.zip`; run upgrade | `local_kreck` installed; web services + capabilities present | — (LMS side) |
| 5 | Apply theme | SMS → the **Kreck** theme is set active; LMS → Kreck boost preset | KRECK branding on both; Home/logo open the welcome page | — (visual) |
| 6 | Pair the webhook | SMS **Kreck Settings** → set LMS endpoint + token; **Export** page auto-pairs the shared secret both ways | Diagnostics show SMS→LMS **and** LMS→SMS heartbeats received | — (integration, verified live) |
| 7 | Student↔parent rule | SMS **KRECK Home** shows the "students with no parent" count; **Students Without a Parent** lists them | Passive warning only; system still operates | `family.linkExisting`, `family.createParentAndLink` |
| 8 | Link / create a parent | SMS **Link Parent to Student** → link existing OR create new | Parent has Parent role, Full, can log in, `childDataAccess='Y'`; integrity gates reject bad input with no orphans | `family.createParentAndLink` (+ reject-without-orphans) |
| 9 | Import an institution | SMS **Export** → preview, then sync to the LMS | LMS users/cohorts/courses/enrolments created from the manifest | `manifest.build` (payload shape the import consumes) |
| 10 | Supplement after import | SMS add entries by hand where import left gaps (e.g. a parent link, a salary) | New rows persist and pass the same integrity checks | `family.*`, `salary.*` |
| 11 | Assessment forms | SMS **Assessments** → prepare a sheet, set a deadline, enter marks, submit; **Review** → approve | Deadline saves (no crash); submit locks; approve computes `finalMark`; review sheet opens in place (not page-bottom) | `assessment.setDeadline`, `assessment.full flow`, `assessment.review rejects bad input` |
| 12 | Salary / payroll forms | SMS **Salaries** → define a salary; **Approvals** → approve | Definition starts Pending; approve is one-shot/idempotent | `salary.saveSalary`, `salary.decideSalary` |
| 13 | Timetable lessons / activities | SMS **Add a Lesson** (planner) against a class | Lesson row created on the class, visible to students/parents; carried in the next export | `planner.createEntry` |
| 14 | Re-export | SMS **Export** → sync again | Lessons/activities + assignment plans appear on the LMS calendar | `manifest.build` (counts incl. scheduled sessions) |

## Running the automated suite

```bash
php "modules/Kreck Bridge/tests/run.php"
```

Expected: `10 passed, 0 failed`. Tests `kt_skip` only when prerequisite live data is genuinely
absent (e.g. no un-parented student). Every test cleans up the rows it creates, so the database
is left exactly as it was found — safe to run against a working install.

Requires: CLI PHP with the SMS's `config.php` reachable, and the database running.
