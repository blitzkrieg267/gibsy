# KRECK — Demo Seeder

A one-command way to turn a KRECK install into a **demo-ready system**: 15 logins
across every actor, and at least one of (nearly) everything — timetable, lessons,
attendance, grades, students, parents, activities, fees/invoices, and payroll —
already added, configured and completed.

## Run it

**Easiest — in the app (admin only):** log in as an Administrator and open
**KRECK Bridge → Seed Demo Data** (Bridge menu). Tick the confirmation box and
press **Seed demo data**. The page shows what it did and lists the 15 logins. A
**Remove demo data** button cleanly wipes it again. (The action is granted to the
Administrator role only.)

**Or from the command line**, from the SMS (Gibbon) web root:

```bash
php "modules/Kreck Bridge/cli/kreck_demo_seed.php"
```

Both routes run the **same** engine (`src/DemoSeeder.php`). It is **idempotent** —
every run first wipes whatever it previously created (the `demo.*` accounts and
rows tagged `[KRECKDEMO]`) and reseeds from clean, so you can re-run it any time.

## The 15 logins

All share one password: **`Demo@Kreck26`**

| Username | Role | Who |
|----------|------|-----|
| `demo.admin` | Administrator | School Administrator |
| `demo.principal` | Administrator | Principal |
| `demo.bursar` | Administrator | Bursar / Finance Officer |
| `demo.registrar` | Administrator | Registrar / Admissions |
| `demo.hod` | Teacher | Head of Department (dept Coordinator) |
| `demo.teacher1` | Teacher | Teacher (owns the demo class) |
| `demo.teacher2` | Teacher | Teacher (assistant on the demo class) |
| `demo.support` | Support Staff | IT / Support |
| `demo.librarian` | Support Staff | Librarian |
| `demo.student1` | Student | Amara Mokoena |
| `demo.student2` | Student | Katlego Sithole |
| `demo.student3` | Student | Zinhle Ndlovu |
| `demo.parent1` | Parent | Grace Mokoena → Amara |
| `demo.parent2` | Parent | Sipho Sithole → Katlego |
| `demo.parent3` | Parent | Nomsa Ndlovu → Zinhle |

## What it seeds (one of everything)

- **Accounts & roles** — the 15 above, all `Full` + can log in; staff appear in the
  staff directory; the HOD is a department Coordinator.
- **Course & class** — a "KRECK Demo Class" with the demo teachers of record and the
  3 demo students enrolled, **slotted into the live timetable** on a few lesson periods.
- **Families** — each demo parent linked to their child (`childDataAccess=Y`), so the
  parent sees the child's academics and fees.
- **Attendance** — simulated across the whole school year (weekly sample), a realistic
  mix of Present / Present-Late / Absent.
- **Finance** — three invoices (tuition + transport): one **Paid**, one **Issued**
  (outstanding), one **Paid – Partial**.
- **Activities** — two students enrolled in an enrichment activity.
- **Grades (KRECK assessment)** — a mark sheet prepared, marked, submitted and
  **Approved**, with final grades computed for every student.
- **Lessons (KRECK planner)** — three planner lessons on the demo class.
- **Payroll (KRECK salaries)** — four approved salaries, each with a **Paid** monthly
  payment.

## Auto-sync to the LMS

After seeding, the seeder **automatically runs Export** to push the demo to the LMS —
provided an **HTTPS** LMS endpoint and a web-service token are set in **Kreck Settings**.
This creates the demo students, parents, course and the **parent mirror** on Moodle (each
`demo.parent*` sees their child's KRECK summary + fee balance), reconciled by username with
the LMS-side demo. The `Export → LMS:` line in the run log shows the outcome; if the LMS
isn't configured (or the endpoint isn't HTTPS), the sync is skipped with a note and seeding
still succeeds — you can run **KRECK → Export** manually any time.

The LMS-only analytics (attendance %, streak, struggle topics) and the graded **Assignment**
come from the Moodle-side one-click **Seed LMS Demo** page (or `local/kreck/seed.php`).

## Cleaning up

Re-running the seeder re-wipes and reseeds. To remove the demo entirely without
reseeding, delete the `demo.*` users in User Admin (their dependent demo rows are
tagged `[KRECKDEMO]`).
