# KRECK — SMS → LMS event mirror

Anything dated on the SMS timetable or calendar appears as an equivalent event in the LMS calendar,
with the right audience and a label for what it is. The SMS stays the system of record: LMS users
cannot edit or delete a mirrored event; change it in the SMS and the LMS follows.

## What is mirrored

| SMS | LMS event | Who sees it | Label |
|-----|-----------|-------------|-------|
| Timetable period (per date, room changes applied) | course event, class course | the class (students + teachers) | Class |
| Planner lesson (incl. **Add a Lesson**) | course event | students + teachers if visible to students, otherwise the class teachers only | Lesson |
| …same lesson, visible to parents | one personal event per linked parent | the parents of the class's students | Lesson |
| School closure / timing change / off-timetable day | site event (whole school) · course events (year group) · personal events (form group: students, parents, tutors) | whole school / that year group / that form group | School closure · Timing change · Off timetable |
| Term start / end | site all-day events | everyone | Term |
| Calendar events (Confirmed / Tentative; Cancelled removes it) | site event if the calendar is public or open to staff + students + parents, otherwise personal events for the viewable groups and participants | per the calendar's visibility | the calendar's name |
| Activities (each weekly session in the programme window) | personal events | enrolled students + activity staff | Activity |
| Staff duty (weekly, school days only) | personal event | that staff member | Duty |
| Staff cover (accepted) | personal event | the covering staff member | Cover |
| Dated assessments (markbook columns) | course event, same visibility rules as a lesson | as a lesson | Assessment |

A lesson that overlaps a timetable period of the same class **replaces** that period (one event, not two).
A closure that cancels classes removes that day's class periods and lessons. Not mirrored: messenger posts,
library loans, facility bookings, external Google/Microsoft feeds.

Why "personal events" for narrower audiences: Moodle cannot hide a course event from students only
(a hidden event is hidden from teachers too) and has no event scoped to a year group, so the SMS lists exactly
who may see it and the LMS creates an event for each.

## When it runs

* **Immediately** when a lesson is added with *Add a Lesson*.
* **Every 5 minutes** from cron (`cli/kreck_eventSync.php`) — catches edits made on any Gibbon page,
  because Gibbon core has no hook for them.
* **Quietly** whenever an administrator opens the Home page or KRECK Home (at most once per 5 minutes).
* **On demand**: *Sync events now* on KRECK Home.

Only a rolling window is synchronised — 14 days back to 90 days ahead, inside the school year — so the first
run is bounded and each later run extends the horizon. A run sends only what changed (a ledger of content
hashes in `kreckEventLedger`); a repeat run sends nothing. Needs an HTTPS LMS endpoint and token in
KRECK Settings; the LMS courses must exist (run the manifest *Export* first) — events for a course the LMS
does not have yet wait and are retried automatically.

## Operating it

```bash
php "modules/Kreck Bridge/cli/kreck_eventSync.php"              # sync now
php "modules/Kreck Bridge/cli/kreck_eventSync.php" --dry-run    # show what would be sent
php "modules/Kreck Bridge/cli/kreck_eventSync.php" --force      # re-send the whole window
```

Cron (every 5 minutes): minute field `0,5,10,15,20,25,30,35,40,45,50,55`, command
`php "/path/to/gibbon/modules/Kreck Bridge/cli/kreck_eventSync.php"`.

## LMS side

`local_kreck_sync_events` (needs `local/kreck:manage`) takes a JSON array of events and creates, updates or removes
calendar events owned by `local_kreck`, identified by `uuid = kreck:<source_id>[@c:<course>|@u:<username>]`.
It is idempotent, supports a dry run, and adopts events the older timetable export created (same class and time)
instead of duplicating them. All-day events are anchored to the LMS site timezone so they show on the right date.

## Home page integration

KRECK Home appears on the main Home page directly after the timetable. Gibbon offers modules only a tab beside the
Planner, so this uses one small marked edit to `src/UI/Dashboard/StaffDashboard.php`
(`KRECK-PATCH` markers; managed by `src/CorePatch.php`). A Gibbon upgrade replaces that file: open **KRECK Home**
as an administrator (it re-applies automatically) or run `php "modules/Kreck Bridge/cli/kreck_core_patch.php" apply`.
