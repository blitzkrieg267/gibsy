# KRECK — Demo Seeder

A one-command way to turn a KRECK install into a **demo-ready system**: 15 logins
across every actor, and at least one of (nearly) everything — timetable, lessons,
attendance, grades, students, parents, activities, fees/invoices, and payroll —
already added, configured and completed.

## Run it

From the SMS (Gibbon) web root, on the command line:

```bash
php "modules/Kreck Bridge/cli/kreck_demo_seed.php"
```

It prints the 15 logins at the end. It is **idempotent** — every run first wipes
whatever it previously created (the `demo.*` accounts and rows tagged
`[KRECKDEMO]`) and reseeds from clean, so you can re-run it any time.

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

## Demoing the LMS (Moodle) side too

The seeder populates the **SMS**. To show the **LMS**, log in to the SMS and run
**KRECK → Export** to sync this demo to Moodle — that creates the demo students,
parents, course and the **parent mirror** (each `demo.parent*` sees their child's
KRECK summary + fee balance on the LMS). The LMS learner analytics (attendance %,
streak, struggle topics) can be topped up with the Moodle-side `local/kreck/seed.php`.

## Cleaning up

Re-running the seeder re-wipes and reseeds. To remove the demo entirely without
reseeding, delete the `demo.*` users in User Admin (their dependent demo rows are
tagged `[KRECKDEMO]`).
