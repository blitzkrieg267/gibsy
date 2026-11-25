<?php
require_once __DIR__ . '/src/Diagnostics.php';
require_once __DIR__ . '/src/Ui.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Institution Builder (guided in-app bulk entry).

The same data as the Excel institution workbook, built IN THE APP as a guided
sequence. Steps follow the exact import/dependency order (foundation → staff →
groups → teaching → students → finance), and each step explains WHAT it is and
HOW it relates to the rest of the system. Relationship fields suggest both live
SMS records and the values you entered in earlier steps (e.g. a Form Group's
Tutor suggests the Staff you just added). "Validate" dry-runs and "Build & import"
commits — both through the SAME engine as the workbook import (runWorkbook), so
the result is identical. No Gibbon core is changed.
*/

require_once __DIR__ . '/src/InstitutionImporter.php';

use Gibbon\Contracts\Database\Connection;

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_builder.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$importer = new KreckInstitutionImporter(
    $container->get(Connection::class),
    $container->get(\Gibbon\Data\PasswordPolicy::class),
    $container->get(\Gibbon\Domain\System\SettingGateway::class),
    __DIR__ . '/imports'
);
$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_builder.php';
$kreckAction = $_REQUEST['kreckAction'] ?? '';

// ---- Validate / Build: run the collected rows through the import engine. -----
$runResult = null;
if (in_array($kreckAction, ['validate', 'build'], true)) {
    $payload = json_decode($_POST['payloadJson'] ?? '{}', true) ?: [];
    $typeRows = [];
    foreach ($payload as $type => $rows) {
        if (!is_array($rows)) continue;
        $clean = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $nonEmpty = false;
            foreach ($r as $v) { if (trim((string) $v) !== '') { $nonEmpty = true; break; } }
            if ($nonEmpty) $clean[] = $r;
        }
        if (!empty($clean)) $typeRows[$type] = $clean;
    }
    if (empty($typeRows)) {
        $runResult = ['ok' => false, 'empty' => true];
    } else {
        $res = $importer->runWorkbook($typeRows, $kreckAction === 'build', (int) $session->get('gibbonPersonID'), 'sync');
        $runResult = $res + ['mode' => $kreckAction];
    }
}

// ---- Step plan (dependency order), curated intros + the key each step feeds. --
$labelByType = [];
foreach ($importer->typeRegistry() as $e) { $labelByType[$e['name']] = $e['label']; }

$plan = [
    ['type' => 'schoolYears',    'phase' => 'Foundation', 'key' => 'name',
     'intro' => 'The academic year(s) everything else belongs to. Almost every later step references a school year by its name (e.g. 2026-2027). Start here.'],
    ['type' => 'departments',    'phase' => 'Foundation', 'key' => 'name',
     'intro' => 'Learning areas / departments. Courses are grouped under a department, so add these before courses.'],
    ['type' => 'usersStaff',     'phase' => 'Staff',      'key' => 'username',
     'intro' => 'Staff accounts. Their usernames are reused later as Heads of Year, Form Group Tutors and class teachers — so staff must exist before groups and classes. Only a few fields are required; leave the rest blank if you don\'t need them.'],
    ['type' => 'yearGroups',     'phase' => 'Groups',     'key' => 'nameShort',
     'intro' => 'Year levels (e.g. Form 1). Each can have a Head of Year — pick a Staff username you added above.'],
    ['type' => 'formGroups',     'phase' => 'Groups',     'key' => 'nameShort',
     'intro' => 'Tutor / registration groups. Each Tutor is a Staff member (from the Staff step). "Next Form Group" is where this group rolls up to next year and can point to any group in this list — it is linked automatically once all groups exist.'],
    ['type' => 'courses',        'phase' => 'Teaching',   'key' => 'nameShort',
     'intro' => 'Subjects offered, grouped under a Department and tied to Year Group(s) you added above.'],
    ['type' => 'courseClasses',  'phase' => 'Teaching',   'key' => 'nameShort',
     'intro' => 'Specific class instances of a Course (e.g. F1-MAT-01). Each belongs to a Course from the previous step.'],
    ['type' => 'stg_weeklyTimetable', 'phase' => 'Teaching', 'key' => null,
     'intro' => 'The weekly lesson timetable — which Class meets on which day and period. Each row becomes a calendar lesson on every matching weekday across the whole school year, so lessons show on the SMS calendar. References the Courses and Classes above.'],
    ['type' => 'usersBasic',     'phase' => 'Students',   'key' => 'username',
     'intro' => 'Student accounts. The Student ID is the cross-system key that links a learner to the KRECK LMS, so give every student one.'],
    ['type' => 'studentEnrolment', 'phase' => 'Students', 'key' => null,
     'intro' => 'Places each Student into a Year Group and Form Group for the school year. References the students, year groups and form groups you added above.'],
    ['type' => 'courseClassEnrolment', 'phase' => 'Students', 'key' => null,
     'intro' => 'Enrols participants into classes — students as Student, teachers as Teacher. References the classes and people above.'],
    ['type' => 'kreck_feeCategories', 'phase' => 'Finance', 'key' => 'name',
     'intro' => 'Categories that fees fall under (Tuition, Admissions, …). Add these before the fees that use them.'],
    ['type' => 'kreck_fees',     'phase' => 'Finance',    'key' => null,
     'intro' => 'The actual fees, each under a Fee Category you added above, for a school year.'],
];

// relationship target table -> the step (and its key field) that produces it,
// so a relationship field can suggest values entered earlier in the builder.
$tableToStep = [
    'gibbonSchoolYear'         => ['schoolYears', 'name'],
    'gibbonDepartment'         => ['departments', 'name'],
    'gibbonPerson'             => ['usersStaff', 'username'],   // + usersBasic merged in JS
    'gibbonYearGroup'          => ['yearGroups', 'nameShort'],
    'gibbonFormGroup'          => ['formGroups', 'nameShort'],
    'gibbonCourse'             => ['courses', 'nameShort'],
    'gibbonCourseClass'        => ['courseClasses', 'nameShort'],
    'gibbonFinanceFeeCategory' => ['kreck_feeCategories', 'name'],
];

$weekdayOpts = [];
foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $d) {
    $weekdayOpts[] = ['value' => $d, 'label' => $d];
}
$weeklyCols = [
    ['field' => 'School Year', 'label' => 'School Year', 'required' => true, 'relTable' => 'gibbonSchoolYear', 'desc' => 'Which year this timetable is for.'],
    ['field' => 'Course Short Name', 'label' => 'Course', 'required' => true, 'relTable' => 'gibbonCourse', 'desc' => 'The course short name.'],
    ['field' => 'Class Short Name', 'label' => 'Class', 'required' => true, 'relTable' => 'gibbonCourseClass', 'desc' => 'The class short name that meets in this slot.'],
    ['field' => 'Day', 'label' => 'Day', 'required' => true, 'options' => $weekdayOpts, 'desc' => 'Weekday the lesson recurs on.'],
    ['field' => 'Start Time', 'label' => 'Start', 'required' => true, 'filter' => 'time', 'desc' => 'Lesson start (HH:MM).'],
    ['field' => 'End Time', 'label' => 'End', 'required' => true, 'filter' => 'time', 'desc' => 'Lesson end (HH:MM).'],
    ['field' => 'Room / Location', 'label' => 'Room', 'required' => false, 'desc' => 'Optional room/facility name.'],
    ['field' => 'Weekly Slot Source Ref', 'label' => 'Ref', 'required' => false, 'desc' => 'Optional unique reference for the slot.'],
];

$steps = [];
foreach ($plan as $p) {
    if ($p['type'] === 'stg_weeklyTimetable') {
        $steps[] = ['type' => 'stg_weeklyTimetable', 'label' => __('Weekly Timetable'), 'phase' => $p['phase'],
            'intro' => $p['intro'], 'key' => null, 'cols' => $weeklyCols];
        continue;
    }
    $it = $importer->loadType($p['type']);
    if (!$it) continue;
    $steps[] = [
        'type'  => $p['type'],
        'label' => $labelByType[$p['type']] ?? $p['type'],
        'phase' => $p['phase'],
        'intro' => $p['intro'],
        'key'   => $p['key'],
        'cols'  => $importer->describeFields($it, true),
    ];
}

$page->breadcrumbs->add(__('Institution Builder'));

ob_start();
?>
<style>
.kbuild{max-width:1180px}
.kbuild .kbtn{background:#52701f;color:#fff;border:0;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.kbuild .kbtn:hover{background:#3c5618;color:#fff}.kbuild .kbtn.ghost{background:#f0f1e6;color:#42571d}.kbuild .kbtn.amber{background:#e6b765}.kbuild .kbtn.amber:hover{background:#d97f06}.kbuild .kbtn[disabled]{opacity:.5;cursor:not-allowed}
.kbuild .layout{display:grid;grid-template-columns:230px 1fr;gap:18px;margin-top:10px}
@media(max-width:820px){.kbuild .layout{grid-template-columns:1fr}}
.krail{border:1px solid #e2e8ee;border-radius:12px;padding:10px;background:#fff;align-self:start;position:sticky;top:10px}
.krail .ph{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#42571d;font-weight:800;margin:10px 8px 4px}
.krail .st{display:flex;align-items:center;gap:8px;padding:7px 9px;border-radius:8px;cursor:pointer;font-size:13px;color:#10150d}
.krail .st:hover{background:#f4f7f9}.krail .st.active{background:#52701f;color:#fff;font-weight:700}
.krail .st .n{width:20px;height:20px;border-radius:50%;background:#cbd5e1;color:#fff;display:grid;place-items:center;font-size:11px;flex:none}
.krail .st.active .n{background:#fff;color:#52701f}.krail .st.filled .n{background:#6a8a28;color:#fff}
.kpanel{border:1px solid #e2e8ee;border-radius:14px;padding:18px 20px;background:#fff;min-height:320px}
.kpanel h3{margin:0 0 2px;font-size:1.25rem;color:#10150d}
.kpanel .intro{background:#f6f8f0;border:1px solid #d9e3cf;color:#42571d;border-radius:10px;padding:10px 14px;margin:10px 0 14px;font-size:14px;line-height:1.5}
.kpanel .phase{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#42571d;font-weight:800}
.krecords{display:flex;flex-direction:column;gap:12px}
.krecord{border:1px solid #e2e8ee;border-radius:10px;background:#fcfdfb;padding:12px 14px}
.krecord .rhead{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:10px}
.krecord .rtitle{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#42571d}
/* Fields flow into as many aligned rows ("sections") as fit the width — never a
   sideways-scrolling strip, never overlapping. */
.kfields{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px 14px}
.kfield{min-width:0;display:flex;flex-direction:column}
.kfield>label{font-size:12px;font-weight:700;color:#10150d;margin-bottom:4px;line-height:1.25}
.kfield .req{color:#d64545}
.kfield .hint{display:block;font-weight:400;color:#5a6b7b;font-size:11px;line-height:1.3;margin-top:1px}
.kfield input,.kfield select{width:100%;box-sizing:border-box;border:1px solid #d9e1e8;border-radius:6px;padding:7px 8px;background:#fff;font-size:13px}
.kfield input:focus,.kfield select:focus{outline:2px solid #c4dc52;outline-offset:0;border-color:#52701f}
.row-del{color:#d64545;cursor:pointer;font-weight:700;font-size:18px;line-height:1;padding:2px 7px;border-radius:6px;flex:none}
.row-del:hover{background:#fee2e2}
.knav{display:flex;gap:8px;margin-top:14px;align-items:center;flex-wrap:wrap}
.kcount{color:#5a6b7b;font-size:13px}
.kresult{border:1px solid #e2e8ee;border-radius:12px;padding:14px 16px;margin-bottom:12px;background:#fff}
.kresult table{border-collapse:collapse;width:100%}
.kresult th,.kresult td{padding:6px 10px;border-bottom:1px solid #eef2f5;text-align:left;font-size:13px}
.kresult .ok{color:#166534;font-weight:700}.kresult .bad{color:#b91c1c;font-weight:700}
.pill{padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700}
.pill.ok{background:#dcfce7;color:#166534}.pill.bad{background:#fee2e2;color:#991b1b}
</style>
<div class="kbuild">
    <p><?php echo __('Build your institution step by step — the same data as the Excel workbook, in the right order, with each step explaining how it fits together. Add rows, move through the steps, then validate and build.'); ?></p>

    <?php if ($runResult !== null): ?>
        <div class="kresult">
            <?php if (!empty($runResult['empty'])): ?>
                <span class="bad"><?php echo __('Nothing to import yet — add some rows first.'); ?></span>
            <?php else:
                $committed = !empty($runResult['committed']);
                $errs = (int) ($runResult['totalErrors'] ?? 0); ?>
                <p><strong><?php
                    if ($committed) echo __('Built and imported successfully.');
                    elseif ($runResult['mode'] === 'build') echo sprintf(__('Not imported — %d issue(s) to fix first (nothing was changed).'), $errs);
                    else echo ($errs === 0 ? __('Validation passed — ready to build.') : sprintf(__('Validation found %d issue(s).'), $errs));
                ?></strong></p>
                <table>
                    <thead><tr><th><?php echo __('Step'); ?></th><th><?php echo __('Rows'); ?></th><th><?php echo __('Result'); ?></th><th><?php echo __('Issues'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach (($runResult['perType'] ?? []) as $tName => $r):
                        if (!is_array($r)) continue;
                        $re = $r['errors'] ?? []; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($labelByType[$tName] ?? $tName); ?></td>
                            <td><?php echo (int) ($r['rowCount'] ?? count($re)); ?></td>
                            <td><span class="pill <?php echo empty($re) ? 'ok' : 'bad'; ?>"><?php echo empty($re) ? __('OK') : __('Errors'); ?></span></td>
                            <td><?php foreach (array_slice($re, 0, 3) as $e) echo htmlspecialchars(($e['info'] ?? '') . (isset($e['row']) ? ' (row ' . $e['row'] . ')' : '')) . '<br>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo htmlspecialchars($self); ?>" id="buildForm">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_builder.php">
        <input type="hidden" name="payloadJson" id="payloadJson">
        <input type="hidden" name="kreckAction" id="kreckAction" value="validate">
        <div class="layout">
            <div class="krail" id="rail"></div>
            <div>
                <div class="kpanel" id="panel"></div>
                <div class="knav">
                    <button type="button" class="kbtn ghost" id="prevBtn">← <?php echo __('Back'); ?></button>
                    <span class="kcount" id="stepCount"></span>
                    <span style="flex:1"></span>
                    <button type="button" class="kbtn ghost" id="nextBtn"><?php echo __('Next'); ?> →</button>
                    <button type="submit" class="kbtn" id="validateBtn" data-action="validate"><?php echo __('Validate (dry-run)'); ?></button>
                    <button type="submit" class="kbtn amber" id="buildBtn" data-action="build"><?php echo __('Build & import'); ?></button>
                </div>
            </div>
        </div>
    </form>
</div>
<script>
(function(){
  var STEPS = <?php echo json_encode($steps, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  var TABLE2STEP = <?php echo json_encode($tableToStep, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  var cur = 0;
  // state[type] = array of row objects {field:value}
  var state = {};
  STEPS.forEach(function(s){ state[s.type] = [blankRow(s)]; });

  function blankRow(s){ var r={}; s.cols.forEach(function(c){ r[c.field]=''; }); return r; }
  function esc(v){ var d=document.createElement('div'); d.textContent=v==null?'':String(v); return d.innerHTML; }

  // Collect current DOM grid (for the visible step) back into state.
  function syncFromDom(){
    var s=STEPS[cur]; var container=document.getElementById('records'); if(!container) return;
    var rows=[];
    container.querySelectorAll('.krecord').forEach(function(card){
      var row={};
      card.querySelectorAll('[data-field]').forEach(function(inp){ row[inp.getAttribute('data-field')]=inp.value; });
      rows.push(row);
    });
    state[s.type]=rows.length?rows:[blankRow(s)];
  }

  // Values entered in an earlier step that a relationship field can suggest.
  function suggestionsFor(relTable){
    var out={};
    function pull(type,key){ (state[type]||[]).forEach(function(r){ var v=(r[key]||'').trim(); if(v) out[v]=1; }); }
    var m=TABLE2STEP[relTable]; if(m){ pull(m[0],m[1]); }
    if(relTable==='gibbonPerson'){ pull('usersBasic','username'); }  // students too
    return Object.keys(out);
  }

  // Set a field's value; for a picker <select> keep a typed value that is not in its list by adding it as an option.
  function setVal(inp,v){
    if(inp.tagName==='SELECT' && v!=='' && ![].some.call(inp.options,function(o){return o.value===v;})){
      var o=document.createElement('option'); o.value=v; o.textContent=v; inp.appendChild(o);
    }
    inp.value=v;
  }

  function cellInput(c){
    var df = 'data-field="'+esc(c.field)+'"';
    // Fixed option set (yes/no, weekday) -> select.
    if (c.options && c.options.length && !c.relTable){
      return '<select data-kpicker '+df+'><option value=""></option>'+c.options.map(function(o){return '<option value="'+esc(o.value)+'">'+esc(o.label)+'</option>';}).join('')+'</select>';
    }
    // Date / time inputs.
    if (c.filter==='date' || c.filter==='schoolyeardate') return '<input type="date" '+df+'>';
    if (c.filter==='time') return '<input type="time" '+df+'>';
    // Relationship / pick-list -> datalist (live options + earlier-step values).
    if (c.relTable || (c.options && c.options.length)){
      var opts={};
      (c.options||[]).forEach(function(o){ opts[o.value]=o.label||o.value; });
      suggestionsFor(c.relTable).forEach(function(v){ if(!(v in opts)) opts[v]=v+' — (added in this builder)'; });
      // Searchable dropdown over the live options + values added in earlier steps; new values can still be typed in.
      return '<select data-kpicker data-kpicker-create '+df+'><option value=""></option>'+Object.keys(opts).map(function(v){return '<option value="'+esc(v)+'">'+esc(opts[v])+'</option>';}).join('')+'</select>';
    }
    return '<input type="text" '+df+'>';
  }

  function renderPanel(){
    var s=STEPS[cur];
    var records=(state[s.type]||[]).map(function(r,i){ return recordHtml(s,r,i); }).join('');
    document.getElementById('panel').innerHTML =
      '<div class="phase">'+esc(s.phase)+'</div>'+
      '<h3>'+esc(s.label)+'</h3>'+
      '<div class="intro">'+esc(s.intro)+'</div>'+
      '<div class="krecords" id="records">'+records+'</div>'+
      '<div class="knav"><button type="button" class="kbtn ghost" id="addRow">+ '+'<?php echo __('Add row'); ?>'+'</button>'+
      '<span class="kcount" id="rowCount"></span></div>';
    // restore values
    var container=document.getElementById('records');
    container.querySelectorAll('.krecord').forEach(function(card,i){
      var r=(state[s.type]||[])[i]||{};
      card.querySelectorAll('[data-field]').forEach(function(inp){ var v=r[inp.getAttribute('data-field')]; if(v!=null) setVal(inp,v); });
    });
    document.getElementById('addRow').addEventListener('click',function(){ syncFromDom(); state[s.type].push(blankRow(s)); renderPanel(); });
    container.addEventListener('click',function(e){ if(e.target.classList.contains('row-del')){ syncFromDom(); var card=e.target.closest('.krecord'); var idx=[].indexOf.call(this.children,card); if(state[s.type].length>1){ state[s.type].splice(idx,1);} else { state[s.type]=[blankRow(s)]; } renderPanel(); }});
    updateCounts();
  }
  function recordHtml(s,r,idx){
    var fields=s.cols.map(function(c){
      var hint=(c.desc?('<span class="hint">'+esc(c.desc)+'</span>'):'');
      return '<div class="kfield"><label>'+esc(c.label)+(c.required?' <span class="req">*</span>':'')+hint+'</label>'+cellInput(c)+'</div>';
    }).join('');
    return '<div class="krecord">'+
      '<div class="rhead"><span class="rtitle"><?php echo __('Record'); ?> '+(idx+1)+'</span>'+
      '<span class="row-del" title="<?php echo __('Remove'); ?>">&times;</span></div>'+
      '<div class="kfields">'+fields+'</div></div>';
  }
  function updateCounts(){
    var s=STEPS[cur];
    var filled=(state[s.type]||[]).filter(function(r){ return Object.keys(r).some(function(k){return (r[k]||'').trim()!=='';}); }).length;
    var rc=document.getElementById('rowCount'); if(rc) rc.textContent=filled+' '+'<?php echo __('row(s) with data'); ?>';
    document.getElementById('stepCount').textContent='<?php echo __('Step'); ?> '+(cur+1)+' / '+STEPS.length;
  }

  function renderRail(){
    var phases=[]; STEPS.forEach(function(s,i){ if(!phases.length||phases[phases.length-1].name!==s.phase) phases.push({name:s.phase,items:[]}); phases[phases.length-1].items.push(i); });
    document.getElementById('rail').innerHTML = phases.map(function(ph){
      return '<div class="ph">'+esc(ph.name)+'</div>'+ph.items.map(function(i){
        var s=STEPS[i]; var filled=(state[s.type]||[]).some(function(r){ return Object.keys(r).some(function(k){return (r[k]||'').trim()!=='';}); });
        return '<div class="st'+(i===cur?' active':'')+(filled?' filled':'')+'" data-i="'+i+'"><span class="n">'+(i+1)+'</span>'+esc(s.label)+'</div>';
      }).join('');
    }).join('');
    document.getElementById('rail').querySelectorAll('.st').forEach(function(el){ el.addEventListener('click',function(){ syncFromDom(); cur=+el.dataset.i; renderPanel(); renderRail(); }); });
  }

  document.getElementById('prevBtn').addEventListener('click',function(){ syncFromDom(); if(cur>0){cur--;renderPanel();renderRail();} });
  document.getElementById('nextBtn').addEventListener('click',function(){ syncFromDom(); if(cur<STEPS.length-1){cur++;renderPanel();renderRail();} });
  document.getElementById('buildForm').addEventListener('submit',function(e){
    syncFromDom();
    var btn=document.activeElement; var action=(btn&&btn.dataset&&btn.dataset.action)?btn.dataset.action:'validate';
    document.getElementById('kreckAction').value=action;
    // strip empty rows per type
    var payload={};
    Object.keys(state).forEach(function(t){ var rows=(state[t]||[]).filter(function(r){return Object.keys(r).some(function(k){return (r[k]||'').trim()!=='';});}); if(rows.length) payload[t]=rows; });
    if(Object.keys(payload).length===0){ /* allow empty -> server shows message */ }
    if(action==='build' && !confirm('<?php echo __('Build and import all entered data into SMS now?'); ?>')){ e.preventDefault(); return; }
    document.getElementById('payloadJson').value=JSON.stringify(payload);
  });

  renderPanel(); renderRail();
})();
</script>
<?php
echo KreckUi::assets() . ob_get_clean();
