<?php
/**
 * Kreck Bridge — KRECK Home panel.
 *
 * The KRECK Home content (hero, LMS connection status, live institution stats, the
 * passive parent-rule warning and quick actions) as a self-contained component that
 * RETURNS its HTML. It is rendered by:
 *   - kreck_home.php           (the module page, context 'page'), and
 *   - the main Home dashboard  (context 'dashboard', shown as the first ("KRECK") tab, ahead of the Planner tab, via the
 *     small marked patch in Gibbon's StaffDashboard — see src/CorePatch.php).
 *
 * It never echoes, never touches $page or global handlers, and is permission-gated by
 * the "KRECK Home" action, so it is safe to include from anywhere (a dashboard tab, an
 * AJAX fragment, a page). Read-only: no SIS records are changed here.
 */

require_once __DIR__ . '/Ui.php';
require_once __DIR__ . '/EventSync.php';

class KreckHomePanel
{
    /**
     * @param string $guid
     * @param \PDO  $connection2
     * @param mixed  $session   Gibbon session
     * @param array  $opts      context: 'page'|'dashboard'
     * @return string HTML ('' when the user may not see it)
     */
    public static function render($guid, $connection2, $session, array $opts = []): string
    {
        if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_home.php') == false) {
            return '';
        }
        $context = ($opts['context'] ?? 'page') === 'dashboard' ? 'dashboard' : 'page';
        // The Home dashboard has its own query string, so use a distinct year parameter there.
        $yearParam = $context === 'dashboard' ? 'kreckYear' : 'gibbonSchoolYearID';

    $absURL = rtrim((string) $session->get('absoluteURL'), '/');
    $link = static function (string $file) use ($absURL): string {
        return $absURL . '/index.php?q=/modules/Kreck Bridge/' . $file;
    };
    $setting = static function (string $scope, string $name) use ($connection2) {
        $stmt = $connection2->prepare('SELECT value FROM gibbonSetting WHERE scope=:scope AND name=:name');
        $stmt->execute(['scope' => $scope, 'name' => $name]);
        return (string) ($stmt->fetchColumn() ?: '');
    };
    $countOne = static function (string $sql, array $bind = []) use ($connection2): int {
        try {
            $stmt = $connection2->prepare($sql);
            $stmt->execute($bind);
            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            return 0;
        }
    };

    // School identity.
    $schoolName = $setting('System', 'organisationName') ?: ($setting('System', 'systemName') ?: 'KRECK');

    // School years for the picker; the current year is the default selection.
    $years = [];
    try {
        $years = $connection2->query("SELECT gibbonSchoolYearID, name, status FROM gibbonSchoolYear ORDER BY sequenceNumber")
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $years = [];
    }
    $currentYear = null;
    foreach ($years as $y) {
        if (($y['status'] ?? '') === 'Current') { $currentYear = $y; break; }
    }
    // Selected year: a valid ?gibbonSchoolYearID override, else the current year,
    // else the first year. Only known IDs are honoured (no arbitrary input).
    $requestedYearID = isset($_GET[$yearParam]) ? (string) $_GET[$yearParam] : '';
    $sy = null;
    foreach ($years as $y) {
        if ((string) $y['gibbonSchoolYearID'] === $requestedYearID && $requestedYearID !== '') { $sy = $y; break; }
    }
    if ($sy === null) { $sy = $currentYear ?: ($years[0] ?? null); }
    $syid = $sy['gibbonSchoolYearID'] ?? null;
    $syName = $sy['name'] ?? '—';
    $viewingCurrent = $currentYear !== null && $syid !== null && (string) $syid === (string) $currentYear['gibbonSchoolYearID'];

    // Live institution stats (best-effort; any failure shows 0, never an error).
    $students = $syid ? $countOne(
        "SELECT COUNT(*) FROM gibbonPerson p
         JOIN gibbonStudentEnrolment se ON se.gibbonPersonID=p.gibbonPersonID AND se.gibbonSchoolYearID=:syid
         JOIN gibbonRole r ON r.gibbonRoleID=p.gibbonRoleIDPrimary AND r.category='Student'
         WHERE p.status='Full'", ['syid' => $syid]) : 0;
    $staff = $countOne(
        "SELECT COUNT(DISTINCT p.gibbonPersonID) FROM gibbonPerson p
         JOIN gibbonRole r ON r.gibbonRoleID=p.gibbonRoleIDPrimary AND r.category='Staff'
         WHERE p.status='Full'");
    $classes = $syid ? $countOne(
        "SELECT COUNT(*) FROM gibbonCourseClass cc
         JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID AND c.gibbonSchoolYearID=:syid", ['syid' => $syid]) : 0;

    // KRECK rule: active students not linked to any parent/guardian (passive warn).
    $noParent = $syid ? $countOne(
        "SELECT COUNT(*) FROM gibbonPerson p
         JOIN gibbonStudentEnrolment se ON se.gibbonPersonID=p.gibbonPersonID AND se.gibbonSchoolYearID=:syid
         WHERE p.status='Full'
           AND p.gibbonRoleIDPrimary=(SELECT gibbonRoleID FROM gibbonRole WHERE category='Student' ORDER BY gibbonRoleID LIMIT 1)
           AND NOT EXISTS (SELECT 1 FROM gibbonFamilyChild fc JOIN gibbonFamilyAdult fa ON fa.gibbonFamilyID=fc.gibbonFamilyID WHERE fc.gibbonPersonID=p.gibbonPersonID)",
        ['syid' => $syid]) : 0;
    $canSeeNoParent = isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_students_noparent.php');

    // KRECK LMS connection status (last successful heartbeat, either direction).
    $endpoint = $setting('Kreck Bridge', 'moodleEndpoint');
    $outHeartbeat = (int) ($setting('Kreck Bridge', 'gibbonHeartbeatAt') ?: 0);
    $inHeartbeat = (int) ($setting('Kreck Bridge', 'moodleHeartbeatAt') ?: 0);
    $lastBeat = max($outHeartbeat, $inHeartbeat);
    $connected = $endpoint !== '' && $lastBeat > 0 && (time() - $lastBeat) < 86400 * 7;
    $connLabel = $endpoint === ''
        ? __('Not configured')
        : ($lastBeat > 0 ? sprintf(__('Last checked %s'), date('j M Y, H:i', $lastBeat)) : __('Never checked'));

    // Quick actions (filtered to what this user may access).
    $actions = [
        ['kreck_planner_add.php', __('Add a Lesson'), __('Add a lesson or event straight to the calendar with a quick guided form — no approval needed.'), 'M8 2v4M16 2v4M3 10h18M5 6h14a2 2 0 012 2v11a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2M12 13v4M10 15h4'],
        ['kreck_export.php', __('Export to KRECK LMS'), __('Preview and sync this year to the KRECK LMS, or download the manifest / SMS links.'), 'M4 12h16M12 4l8 8-8 8'],
        ['kreck_import.php', __('Import Institution Data'), __('Seed the institution from a table or spreadsheet, reusing the SMS import engine.'), 'M12 20V4M4 12l8 8 8-8'],
        ['kreck_builder.php', __('Institution Builder'), __('Build the whole institution in the app, step by step in the right order, then validate and import.'), 'M4 6h6v6H4zM14 6h6v4h-6zM4 16h6v2H4zM14 14h6v4h-6z'],
        ['kreck_lms.php', __('KRECK LMS Classes'), __('Browse classes and activities imported from the KRECK LMS, and import SMS links.'), 'M4 6h16M4 12h16M4 18h10'],
        ['kreck_marks.php', __('KRECK LMS Marks'), __('Review the gradebook marks exported from the KRECK LMS onto the school record.'), 'M5 13l4 4L19 7'],
        ['kreck_salary.php', __('Staff Salaries'), __('Record recurring staff salary definitions that drive the payroll run.'), 'M12 1v22M17 5H9a3 3 0 000 6h6a3 3 0 010 6H6'],
        ['kreck_settings.php', __('KRECK Settings'), __('Set the KRECK LMS endpoint and token, and run the connection heartbeat.'), 'M10 3h4l1 3 3 1v4l-3 1-1 3h-4l-1-3-3-1V7l3-1z'],
    ];
    $visibleActions = [];
    foreach ($actions as $a) {
        if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/' . $a[0])) {
            $visibleActions[] = $a;
        }
    }


        // SMS → LMS calendar sync status (only for people who may run the export).
        $canSync = isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_export.php');
        $syncReady = stripos((string) $endpoint, 'https://') === 0 && trim($setting('Kreck Bridge', 'moodleToken')) !== '';
        $syncStatus = $canSync ? KreckEventSync::status($connection2) : null;
        $syncUrl = $absURL . '/modules/' . rawurlencode('Kreck Bridge') . '/kreck_eventSync_ajax.php';

        $yearOptions = [];
        foreach ($years as $y) {
            $yearOptions[(string) $y['gibbonSchoolYearID']] = (string) $y['name'] . (($y['status'] ?? '') === 'Current' ? ' (' . __('current') . ')' : '');
        }

        ob_start();
        ?>
<style>
.kreck-home{--kh-slate:#10150d;--kh-slate2:#10150d;--kh-teal:#52701f;--kh-teal2:#6a8a28;--kh-amber:#e6b765;--kh-ink:#10150d;--kh-muted:#5a6b7b;max-width:1060px;margin:0 auto;}
.kreck-home *{box-sizing:border-box;}
.kh-hero{position:relative;overflow:hidden;border-radius:22px;padding:34px 32px;color:#eaf2f0;
  background:radial-gradient(760px 420px at 100% -10%,rgba(196, 220, 82,.30),transparent 62%),
    radial-gradient(520px 360px at -5% 115%,rgba(230, 183, 101,.14),transparent 60%),var(--kh-slate);
  box-shadow:0 22px 52px rgba(8,16,26,.28);}
.kh-eyebrow{display:inline-flex;align-items:center;gap:.5rem;font-size:.72rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:#9fd8ce;margin:0;}
.kh-eyebrow::before{content:"";width:9px;height:9px;border-radius:50%;background:var(--kh-amber);box-shadow:0 0 14px rgba(230, 183, 101,.7);}
.kh-hero h1{margin:.5rem 0 0;font-size:clamp(1.9rem,4.4vw,3rem);font-weight:800;line-height:1.05;color:#fff;letter-spacing:-.02em;}
.kh-hero p.sub{margin:.7rem 0 0;max-width:60ch;color:#c2d2d8;font-size:clamp(1rem,2vw,1.12rem);}
.kh-cta{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.4rem;}
.kh-btn{display:inline-flex;align-items:center;gap:.45rem;font-weight:700;border-radius:999px;padding:.66rem 1.2rem;text-decoration:none;border:0;cursor:pointer;transition:transform .12s ease,background .12s ease;}
.kh-btn.primary{background:var(--kh-teal);color:#fff;}
.kh-btn.primary:hover{background:#3c5618;transform:translateY(-1px);color:#fff;}
.kh-btn.ghost{background:rgba(255,255,255,.08);color:#eaf2f0;border:1px solid rgba(255,255,255,.22);}
.kh-btn.ghost:hover{background:rgba(255,255,255,.15);color:#fff;}
.kh-pill{position:absolute;top:22px;right:24px;display:inline-flex;align-items:center;gap:.45rem;font-size:.76rem;font-weight:700;
  padding:.4rem .8rem;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2);color:#d7e4e2;}
.kh-dot{width:9px;height:9px;border-radius:50%;}
.kh-dot.on{background:#34d399;box-shadow:0 0 10px rgba(52,211,153,.8);}
.kh-dot.off{background:#e6b765;}
.kh-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:20px;}
@media(max-width:720px){.kh-stats{grid-template-columns:repeat(2,minmax(0,1fr));}.kh-pill{position:static;display:inline-flex;margin-top:14px;}}
.kh-stat{background:#fff;border:1px solid rgba(15,30,46,.08);border-radius:16px;padding:16px 18px;box-shadow:0 1px 2px rgba(15,30,46,.05),0 10px 26px rgba(15,30,46,.06);}
.kh-stat .v{display:block;font-size:clamp(1.5rem,3.2vw,2.1rem);font-weight:800;color:var(--kh-slate2);line-height:1.05;font-variant-numeric:tabular-nums;}
.kh-stat .l{display:block;margin-top:.35rem;font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--kh-muted);}
.kh-section-title{margin:28px 0 12px;font-size:1.1rem;font-weight:800;color:var(--kh-ink);letter-spacing:-.01em;}
.kh-actions{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;}
.kh-action{display:flex;gap:14px;align-items:flex-start;text-decoration:none;color:inherit;background:#fff;border:1px solid rgba(15,30,46,.09);
  border-radius:16px;padding:16px 16px;transition:transform .14s ease,box-shadow .14s ease,border-color .14s ease;}
.kh-action:hover{transform:translateY(-3px);border-color:rgba(196, 220, 82,.5);box-shadow:0 16px 36px rgba(15,30,46,.12);color:inherit;text-decoration:none;}
.kh-ico{flex:none;width:42px;height:42px;border-radius:12px;display:grid;place-items:center;color:#fff;
  background:linear-gradient(145deg,var(--kh-teal2),var(--kh-teal));box-shadow:inset 0 1px 0 rgba(255,255,255,.3);}
.kh-action h3{margin:0;font-size:.98rem;font-weight:800;color:var(--kh-slate2);}
.kh-action p{margin:.25rem 0 0;font-size:.82rem;line-height:1.45;color:var(--kh-muted);}
.kh-foot{margin:26px 2px 6px;color:var(--kh-muted);font-size:.8rem;}
.kh-year{margin-top:1.2rem;display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;}
.kh-year label{font-size:.7rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#9fd8ce;}
.kh-year .kp{width:auto;min-width:210px}
.kh-year .kp-ctl{min-height:34px;padding:.3rem .95rem;background:rgba(255,255,255,.1);color:#eaf2f0;border:1px solid rgba(255,255,255,.26);border-radius:999px;font-weight:700;font-size:.85rem}
.kh-year .kp-caret{border-color:#c2d2d8}
.kh-year .kp-ctl:focus,.kh-year .kp.kp-open .kp-ctl{border-color:var(--kh-teal2);box-shadow:0 0 0 3px rgba(106,138,40,.3)}
.kreck-home.kh-dash{max-width:none;margin:6px 0 0}
.kh-sync{margin:22px 0 0;padding:12px 16px;border-radius:14px;background:#fff;border:1px solid rgba(15,30,46,.09);display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:.86rem;color:var(--kh-muted)}
.kh-sync .kh-sync-dot{width:10px;height:10px;border-radius:50%;background:#e6b765;flex:none}
.kh-sync[data-state="ok"] .kh-sync-dot{background:#34d399;box-shadow:0 0 8px rgba(52,211,153,.7)}
.kh-sync[data-state="bad"] .kh-sync-dot{background:#ef4444}
.kh-sync strong{color:var(--kh-ink)}
.kh-sync .kh-sync-text{flex:1;min-width:220px}
.kh-sync button{background:var(--kh-teal);color:#fff;border:0;border-radius:999px;padding:.45rem 1rem;font-weight:700;font-size:.8rem;cursor:pointer}
.kh-sync button[disabled]{opacity:.55;cursor:progress}
</style>

<div class="kreck-home<?php echo $context === 'dashboard' ? ' kh-dash' : ''; ?>">
  <section class="kh-hero">
    <span class="kh-pill"><span class="kh-dot <?php echo $connected ? 'on' : 'off'; ?>"></span>
      <?php echo __('KRECK LMS') . ' · ' . htmlspecialchars($connLabel); ?></span>
    <p class="kh-eyebrow"><?php echo __('KRECK SMS'); ?></p>
    <h1><?php echo htmlspecialchars($schoolName); ?></h1>
    <p class="sub"><?php echo __('The school system of record for your institution — people, classes and finance in one place, connected to the KRECK LMS so learning and administration feel like one product.'); ?></p>
    <div class="kh-cta">
      <?php if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_export.php')) { ?>
        <a class="kh-btn primary" href="<?php echo htmlspecialchars($link('kreck_export.php')); ?>"><?php echo __('Export to KRECK LMS'); ?> &rarr;</a>
      <?php } ?>
      <?php if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_import.php')) { ?>
        <a class="kh-btn ghost" href="<?php echo htmlspecialchars($link('kreck_import.php')); ?>"><?php echo __('Import Institution Data'); ?></a>
      <?php } ?>
    </div>
    <?php if (count($years) > 1) { ?>
      <form method="get" action="" class="kh-year">
        <?php if ($context !== 'dashboard') { ?><input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_home.php"><?php } ?>
        <label for="kh-year-sel"><?php echo __('School year'); ?></label>
        <?php echo KreckUi::select($yearParam, $yearOptions, (string) $syid, ['id' => 'kh-year-sel', 'onchange' => 'this.form.submit()']); ?>
      </form>
    <?php } ?>
    <div class="kh-stats">
      <div class="kh-stat"><span class="v"><?php echo number_format($students); ?></span><span class="l"><?php echo __('Students'); ?></span></div>
      <div class="kh-stat"><span class="v"><?php echo number_format($staff); ?></span><span class="l"><?php echo __('Staff'); ?></span></div>
      <div class="kh-stat"><span class="v"><?php echo number_format($classes); ?></span><span class="l"><?php echo __('Classes'); ?></span></div>
      <div class="kh-stat"><span class="v" style="font-size:1.25rem"><?php echo htmlspecialchars($syName); ?></span><span class="l"><?php echo __('School Year'); ?></span></div>
    </div>

    <?php if ($noParent > 0) { ?>
      <div style="margin-top:16px;background:#fffbeb;border:1px solid #fde68a;border-radius:14px;padding:14px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;color:#92400e;">
        <span style="font-size:1.6rem;font-weight:800;line-height:1;color:#b45309;"><?php echo number_format($noParent); ?></span>
        <span style="flex:1;min-width:220px;font-size:14px;line-height:1.45;">
          <strong><?php echo __('students have no parent/guardian linked.'); ?></strong><br>
          <?php echo __('KRECK rule (passive): the system keeps working, but these students should have a parent linked so the parent can access academics and fees.'); ?>
        </span>
        <?php if ($canSeeNoParent) { ?>
          <a href="<?php echo htmlspecialchars($link('kreck_students_noparent.php')); ?>" style="background:#b45309;color:#fff;text-decoration:none;border-radius:9px;padding:9px 16px;font-weight:700;font-size:13px;white-space:nowrap;"><?php echo __('Review & link'); ?> &rarr;</a>
        <?php } ?>
      </div>
    <?php } ?>
  </section>

  <?php if (!empty($visibleActions)) { ?>
    <h2 class="kh-section-title"><?php echo __('Quick actions'); ?></h2>
    <div class="kh-actions">
      <?php foreach ($visibleActions as $a) { ?>
        <a class="kh-action" href="<?php echo htmlspecialchars($link($a[0])); ?>">
          <span class="kh-ico" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $a[3]; ?>"/></svg>
          </span>
          <span>
            <h3><?php echo htmlspecialchars($a[1]); ?></h3>
            <p><?php echo htmlspecialchars($a[2]); ?></p>
          </span>
        </a>
      <?php } ?>
    </div>
  <?php } ?>

  <?php if ($canSync) {
      $c = $syncStatus['counts'];
      $state = !$syncReady ? 'off' : (($c['Failed'] > 0) ? 'bad' : (($syncStatus['lastOk']) ? 'ok' : 'off')); ?>
    <div class="kh-sync" id="kh-sync" data-url="<?php echo htmlspecialchars($syncUrl); ?>" data-ready="<?php echo $syncReady ? '1' : '0'; ?>" data-state="<?php echo $state; ?>">
      <span class="kh-sync-dot"></span>
      <span class="kh-sync-text" id="kh-sync-text"><?php
        if (!$syncReady) {
            echo '<strong>' . __('SMS events → LMS calendar') . ':</strong> ' . __('off — set an HTTPS LMS endpoint and token in KRECK Settings.');
        } else {
            echo '<strong>' . __('SMS events → LMS calendar') . ':</strong> '
                . sprintf(__('%1$s synced · %2$s waiting for the LMS · %3$s failed'), number_format($c['Synced']), number_format($c['Skipped'] + $c['Pending']), number_format($c['Failed']))
                . ($syncStatus['lastOk'] ? ' · ' . sprintf(__('last sync %s'), htmlspecialchars(date('j M, H:i', strtotime($syncStatus['lastOk'])))) : ' · ' . __('not synced yet'));
        } ?></span>
      <?php if ($syncReady) { ?><button type="button" id="kh-sync-btn"><?php echo __('Sync events now'); ?></button><?php } ?>
    </div>
    <?php if ($syncReady) { ?>
    <script>
    (function(){
      var box=document.getElementById('kh-sync'); if(!box) return;
      var txt=document.getElementById('kh-sync-text'), btn=document.getElementById('kh-sync-btn'), url=box.getAttribute('data-url');
      function line(st,msg){
        var c=st&&st.counts?st.counts:null; var t='';
        if(c){ t=c.Synced+' synced · '+(c.Skipped+c.Pending)+' waiting for the LMS · '+c.Failed+' failed'; if(st.lastOk){ t+=' · last sync '+st.lastOk; } }
        if(msg){ t=(t?t+' — ':'')+msg; }
        txt.textContent=''; var b=document.createElement('strong'); b.textContent='<?php echo addslashes(__('SMS events → LMS calendar')); ?>: '; txt.appendChild(b); txt.appendChild(document.createTextNode(t));
        box.setAttribute('data-state', c&&c.Failed>0?'bad':'ok');
      }
      function call(mode){
        if(btn) btn.disabled=true;
        fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'mode='+mode})
          .then(function(r){return r.json();})
          .then(function(j){ if(j&&(j.ran||mode==='now')){ line(j.status,j.message); } })
          .catch(function(){})
          .then(function(){ if(btn) btn.disabled=false; });
      }
      if(btn) btn.addEventListener('click',function(){ call('now'); });
      setTimeout(function(){ call('auto'); },1500);   // quiet top-up (throttled to once every 5 minutes on the server)
    })();
    </script>
    <?php } ?>
  <?php } ?>

  <p class="kh-foot"><?php
    echo $viewingCurrent
        ? __('KRECK MicroLearn — SMS and LMS, one record. Figures reflect the current school year; nothing on this page changes your data.')
        : sprintf(__('KRECK MicroLearn — SMS and LMS, one record. Figures reflect %s; nothing on this page changes your data.'), htmlspecialchars((string) $syName));
  ?></p>
</div>
        <?php
        return KreckUi::assets() . ob_get_clean();
    }
}
