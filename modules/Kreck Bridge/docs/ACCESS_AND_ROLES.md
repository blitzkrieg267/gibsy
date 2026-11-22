# KRECK — Access & Roles (Staff / Student / Parent)

KRECK has three actors. Access is enforced by the host platforms' own role/permission
systems (Gibbon roles on the SMS, Moodle capabilities on the LMS) — KRECK does not invent a
parallel permission engine; it aligns to them. This is the uniform reference for what each
actor can do.

## The actors

| Actor | SMS (Gibbon) | LMS (Moodle) |
|-------|--------------|--------------|
| **Staff** | role category `Staff` (Administrator, Teacher, Registrar, Support…) | `teacher` / `manager`; KRECK capability `local/kreck:manage` on sync accounts |
| **Student** | role category `Student` (004… the `Student` category) | `student`; KRECK capability `local/kreck:view` (own record) |
| **Parent** | role category `Parent` (004) — linked to a child via a **gibbonFamily** | a parent user with the **KRECK Parent** role (`kreckparent`) assigned in each child's user context |

## What each actor can access

| Area | Staff | Student | Parent |
|------|:-----:|:-------:|:------:|
| KRECK Home / institution stats | ✅ manage | — | — |
| Export / Import / Builder / Settings | ✅ | — | — |
| **Link Parent to Student** (new) | ✅ | — | — |
| **Students Without a Parent** (new) | ✅ | — | — |
| Add a Lesson (planner) | ✅ | — | — |
| Salaries / Payroll / Approvals | ✅ (finance/admin) | — | — |
| Own academic record (profile, timetable, reports) | ✅ all | ✅ own | ✅ **their children** |
| **Fees / invoices** | ✅ manage | ✅ own | ✅ **their children** (Finance "View Invoices_myChildren") |
| LMS learner summary (attendance, fees, streak, topics) | ✅ via `:manage` | ✅ own via `:view` | ✅ **their children** (via `:viewchild` in the child's user context) |

## The KRECK student↔parent rule (passive)

- **Rule:** every active student should be linked to at least one parent/guardian via a
  Gibbon **family** (the student is a `gibbonFamilyChild` whose family has a
  `gibbonFamilyAdult`). The free-text "guardian contact" does **not** satisfy the rule.
- **Passive:** the system keeps operating normally; it only **warns**. The count shows on
  **KRECK Home** and the **Students Without a Parent** page lists who needs attention.
- **Quick link:** **Link Parent to Student** associates a parent in one step — link an
  existing person, or **create a new Parent account** with full Gibbon integrity (unique
  username, school password policy, Parent role, Full status, status log — all in one DB
  transaction). It sets `childDataAccess='Y'` so the parent immediately gains academic **and**
  fee visibility of that child.

## How parent access works (holistic: academics + fees)

A linked parent (role `Parent`, `childDataAccess='Y'`) sees their child's data through
Gibbon's **native** mechanisms — nothing bespoke:

- **Academics:** the Parent role can view the linked child's profile, timetable, attendance
  and reports (gated by the family relationship).
- **Fees:** the Parent role already holds the Finance action **"View Invoices_myChildren"**,
  so the parent sees (and can be the payer/primary contact for) the child's invoices. KRECK's
  install step defensively re-grants this to the Parent role so it is never missing.
- **Contact priority** `1` marks the primary-contact parent (finance reminders target them).

## LMS parent mirror (live)

The Moodle side mirrors the SMS family link:

- **Manifest:** the SMS export carries a `parents[]` collection — each parent with their
  `children[]` (student join keys) and contact priority — emitted only for family adults with
  `childDataAccess='Y'`.
- **Provisioning:** `local_kreck` creates the parent user (same password policy as every
  provisioned account), then assigns the **KRECK Parent** role (`kreckparent`, user-context
  assignable) in each linked child's user context, and records the link in
  `local_kreck_parentlink` so re-sync is idempotent.
- **Access:** a parent sees each child's KRECK summary + fee balance through a **"My children"**
  nav item (`children.php` → `child_summary.php`), gated on the new `local/kreck:viewchild`
  capability **in the child's user context** — so a parent sees only their own children, nothing
  else. The learner-summary web service honours the same parent-of-child check.
