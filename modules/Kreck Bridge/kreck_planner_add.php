<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Add a Lesson (KRECK stepped planner form).

A modern, guided way to add a lesson/event to the SMS calendar that works within
Gibbon's existing planner: it writes a standard gibbonPlannerEntry (so it shows
on the calendar exactly like a native lesson). No approval step. Reachable two
ways, both landing here:
  1. From the calendar — the KRECK theme injects an "Add lesson" button on the
     Planner page that deep-links here with the date (and class) prefilled.
  2. The conventional Gibbon "Add Lesson Plan" flow is unchanged and still works.

Step 1 picks the class (searchable) + date; step 2 the lesson details (time
prefilled from the class's timetable slot when available); step 3 reviews and
saves. No Gibbon core is changed.
*/

require_once __DIR__ . '/src/PlannerManager.php';

use Gibbon\Contracts\Database\Connection;

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_planner_add.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$manager = new KreckPlannerManager($container->get(Connection::class));
$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_planner_add.php';
$schoolYearID = $session->get('gibbonSchoolYearID');
$personID = (int) $session->get('gibbonPersonID');
$isAdmin = (string) $session->get('gibbonRoleIDCurrentCategory') === 'Staff' && (string) $session->get('gibbonRoleIDCurrent') === '001';
$kreckAction = $_REQUEST['kreckAction'] ?? '';

$saveResult = null;
if ($kreckAction === 'save') {
    $ccid = (int) ($_POST['gibbonCourseClassID'] ?? 0);
    $date = trim((string) ($_POST['date'] ?? ''));
    $timeStart = trim((string) ($_POST['timeStart'] ?? ''));
    $timeEnd = trim((string) ($_POST['timeEnd'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $failed = [];
    if (!$ccid || !$manager->classInYear($ccid, $schoolYearID)) {
        $failed[] = __('Please search for and select a class in step 1.');
    }
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $failed[] = __('Please choose a valid date.');
    }
    if ($timeStart === '' || $timeEnd === '' || $timeStart >= $timeEnd) {
        $failed[] = __('Enter a start and end time, with the end after the start.');
    }
    if ($name === '') {
        $failed[] = __('Give the lesson a name.');
    }
    if (empty($failed)) {
        $slot = $manager->classSlotForDate($ccid, $date);
        $newID = $manager->createEntry([
            'gibbonCourseClassID'  => $ccid,
            'date'                 => $date,
            'timeStart'            => $timeStart,
            'timeEnd'              => $timeEnd,
            'gibbonTTDayRowClassID' => $slot['gibbonTTDayRowClassID'] ?? null,
            'name'                 => $name,
            'summary'              => $_POST['summary'] ?? '',
            'description'          => $_POST['description'] ?? '',
            'viewableStudents'     => $_POST['viewableStudents'] ?? 'Y',
            'viewableParents'      => $_POST['viewableParents'] ?? 'Y',
        ], $personID);
        $viewURL = $session->get('absoluteURL') . '/index.php?q=/modules/Planner/planner_view_full.php&gibbonPlannerEntryID=' . $newID
            . '&viewBy=class&gibbonCourseClassID=' . $ccid;
        $saveResult = ['ok' => true, 'id' => $newID, 'viewURL' => $viewURL, 'label' => $manager->classLabel($ccid), 'date' => $date, 'name' => $name];
    } else {
        $saveResult = ['ok' => false, 'failed' => $failed];
    }
}

$classes = $manager->listClasses($schoolYearID, $personID, $isAdmin);
$classData = [];
foreach ($classes as $c) {
    $classData[] = [
        'id'    => (string) $c['id'],
        'code'  => $c['course'] . '.' . $c['class'],
        'name'  => (string) $c['courseName'],
    ];
}

// Prefills (calendar entry point deep-links with these).
$preClass = (int) ($_GET['gibbonCourseClassID'] ?? 0);
$preDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? $_GET['date'] : '';
$preStart = $preEnd = '';
$preLabel = '';
if ($preClass && $manager->classInYear($preClass, $schoolYearID)) {
    $preLabel = $manager->classLabel($preClass);
    if ($preDate !== '') {
        $slot = $manager->classSlotForDate($preClass, $preDate);
        if ($slot) { $preStart = substr((string) $slot['timeStart'], 0, 5); $preEnd = substr((string) $slot['timeEnd'], 0, 5); }
    }
} else {
    $preClass = 0;
}

$page->breadcrumbs->add(__('Add a Lesson'));

ob_start();
?>
<style>
.kpl{max-width:860px}
.kpl .kbtn{background:#52701f;color:#fff;border:0;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.kpl .kbtn:hover{background:#3c5618;color:#fff}.kpl .kbtn.ghost{background:#f0f1e6;color:#42571d}.kpl .kbtn[disabled]{opacity:.5;cursor:not-allowed}
.kwiz{border:1px solid #e2e8ee;border-radius:14px;padding:18px 20px;margin-top:10px;background:#fff}
.kwiz .steps{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.kwiz .steps .s{flex:1;min-width:150px;display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;background:#f4f7f9;color:#5a6b7b;font-weight:700;font-size:13px}
.kwiz .steps .s .n{width:22px;height:22px;border-radius:50%;background:#cbd5e1;color:#fff;display:grid;place-items:center;font-size:12px}
.kwiz .steps .s.active{background:#52701f;color:#fff}.kwiz .steps .s.active .n{background:#fff;color:#52701f}.kwiz .steps .s.done .n{background:#6a8a28;color:#fff}
.kwiz .panel{display:none}.kwiz .panel.active{display:block}
.kwiz label{display:block;font-weight:700;font-size:13px;color:#10150d;margin:10px 0 4px}
.kwiz input[type=text],.kwiz input[type=date],.kwiz input[type=time],.kwiz select,.kwiz textarea{width:100%;border:1px solid #d9e1e8;border-radius:8px;padding:9px 10px;font-size:14px}
.kwiz .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:620px){.kwiz .grid2{grid-template-columns:1fr}}
.kwiz .nav{display:flex;gap:8px;margin-top:16px;align-items:center}.kwiz .req{color:#d64545}
.kcombo{position:relative}
.kcombo-list{list-style:none;margin:4px 0 0;padding:4px;border:1px solid #d9e1e8;border-radius:8px;max-height:260px;overflow:auto;position:absolute;z-index:20;background:#fff;width:100%;box-shadow:0 12px 30px rgba(15,30,46,.14)}
.kcombo-list li{padding:8px 10px;border-radius:6px;cursor:pointer}.kcombo-list li:hover,.kcombo-list li.hi{background:#f0f1e6}
.kcombo-list li .nm{font-weight:700;color:#10150d;font-size:14px}.kcombo-list li .meta{font-size:12px;color:#5a6b7b}.kcombo-list li b{color:#42571d}
.kcombo-chosen{margin-top:8px;padding:10px 12px;border:1px solid #d9e3cf;background:#f4f7ec;border-radius:10px;display:flex;align-items:center;gap:10px}
.kcombo-chosen .nm{font-weight:800;color:#10150d}.kcombo-chosen .chg{margin-left:auto;color:#42571d;font-weight:700;cursor:pointer;text-decoration:underline}
.kreview dt{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#5a6b7b;margin-top:8px}.kreview dd{margin:2px 0 0;font-weight:700;color:#10150d}
.note{background:#f6f8f0;border:1px solid #d9e3cf;color:#42571d;border-radius:10px;padding:10px 14px;margin:10px 0}
</style>
<div class="kpl">
    <p><?php echo __('Add a lesson or event to the calendar. It is saved straight to the planner and appears on the calendar for the class — no approval needed.'); ?></p>

    <?php if ($saveResult !== null && $saveResult['ok']): ?>
        <div class="success">
            <?php echo sprintf(__('Lesson “%1$s” added to %2$s on %3$s.'), htmlspecialchars($saveResult['name']), htmlspecialchars($saveResult['label']), htmlspecialchars($saveResult['date'])); ?>
            &nbsp;<a class="kbtn ghost" href="<?php echo htmlspecialchars($saveResult['viewURL']); ?>"><?php echo __('View on calendar →'); ?></a>
            &nbsp;<a class="kbtn ghost" href="<?php echo htmlspecialchars($self); ?>"><?php echo __('Add another'); ?></a>
        </div>
    <?php elseif ($saveResult !== null): ?>
        <div class="warning"><?php foreach ($saveResult['failed'] as $f): ?><?php echo htmlspecialchars($f); ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($saveResult === null || !$saveResult['ok']): ?>
    <form method="post" action="<?php echo htmlspecialchars($self); ?>" id="plForm" class="kwiz">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_planner_add.php">
        <input type="hidden" name="kreckAction" value="save">
        <input type="hidden" name="gibbonCourseClassID" id="gibbonCourseClassID" value="<?php echo $preClass ?: ''; ?>">
        <div class="steps">
            <div class="s active" data-stepdot="1"><span class="n">1</span><?php echo __('Class & date'); ?></div>
            <div class="s" data-stepdot="2"><span class="n">2</span><?php echo __('Lesson details'); ?></div>
            <div class="s" data-stepdot="3"><span class="n">3</span><?php echo __('Review & save'); ?></div>
        </div>

        <div class="panel active" data-step="1">
            <label for="classSearch"><?php echo __('Class'); ?> <span class="req">*</span></label>
            <div class="kcombo">
                <input type="text" id="classSearch" autocomplete="off" placeholder="<?php echo __('Search by course or class name…'); ?>"<?php echo $preClass ? ' hidden' : ''; ?>>
                <ul class="kcombo-list" id="classResults" hidden></ul>
            </div>
            <div class="kcombo-chosen" id="classChosen"<?php echo $preClass ? '' : ' hidden'; ?>><?php if ($preClass): ?><div><span class="nm"><?php echo htmlspecialchars($preLabel); ?></span></div><span class="chg" id="chgClass"><?php echo __('Change'); ?></span><?php endif; ?></div>
            <label for="date"><?php echo __('Date'); ?> <span class="req">*</span></label>
            <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($preDate); ?>">
            <div class="nav"><span style="flex:1"></span><button type="button" class="kbtn" data-next="2" id="toStep2"<?php echo $preClass ? '' : ' disabled'; ?>><?php echo __('Next →'); ?></button></div>
        </div>

        <div class="panel" data-step="2">
            <label><?php echo __('Lesson name'); ?> <span class="req">*</span></label>
            <input type="text" name="name" id="name" maxlength="50" placeholder="<?php echo __('e.g. Algebra — simultaneous equations'); ?>">
            <div class="grid2">
                <div><label><?php echo __('Start time'); ?> <span class="req">*</span></label><input type="time" name="timeStart" id="timeStart" value="<?php echo htmlspecialchars($preStart); ?>"></div>
                <div><label><?php echo __('End time'); ?> <span class="req">*</span></label><input type="time" name="timeEnd" id="timeEnd" value="<?php echo htmlspecialchars($preEnd); ?>"></div>
            </div>
            <?php if ($preStart !== ''): ?><p class="note" style="margin-top:6px"><?php echo __('Times prefilled from this class\'s timetable slot for the chosen date — adjust if needed.'); ?></p><?php endif; ?>
            <label><?php echo __('Summary'); ?></label>
            <input type="text" name="summary" id="summary" maxlength="255" placeholder="<?php echo __('One-line summary (optional)'); ?>">
            <label><?php echo __('Description / plan'); ?></label>
            <textarea name="description" id="description" rows="3"></textarea>
            <div class="grid2">
                <div><label><?php echo __('Visible to students'); ?></label><select name="viewableStudents" id="viewableStudents"><option value="Y"><?php echo __('Yes'); ?></option><option value="N"><?php echo __('No'); ?></option></select></div>
                <div><label><?php echo __('Visible to parents'); ?></label><select name="viewableParents" id="viewableParents"><option value="Y"><?php echo __('Yes'); ?></option><option value="N"><?php echo __('No'); ?></option></select></div>
            </div>
            <div class="nav"><button type="button" class="kbtn ghost" data-back="1"><?php echo __('← Back'); ?></button><span style="flex:1"></span><button type="button" class="kbtn" data-next="3" id="toStep3"><?php echo __('Review →'); ?></button></div>
        </div>

        <div class="panel" data-step="3">
            <dl class="kreview grid2" id="reviewBox"></dl>
            <div class="note"><?php echo __('On save this lesson is added to the calendar for the class. It is visible immediately (no approval).'); ?></div>
            <div class="nav"><button type="button" class="kbtn ghost" data-back="2"><?php echo __('← Back'); ?></button><span style="flex:1"></span><button type="submit" class="kbtn" id="submitBtn"><?php echo __('Add to calendar'); ?></button></div>
        </div>
    </form>
    <?php endif; ?>
</div>
<script>
(function(){
  var CLASSES = <?php echo json_encode($classData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  var form = document.getElementById('plForm'); if(!form) return;
  var hid = document.getElementById('gibbonCourseClassID');
  var search = document.getElementById('classSearch'), list = document.getElementById('classResults'), chosen = document.getElementById('classChosen');
  var toStep2 = document.getElementById('toStep2'), hi=-1, matches=[];
  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
  function hl(t,q){if(!q)return esc(t);var i=String(t).toLowerCase().indexOf(q.toLowerCase());if(i<0)return esc(t);return esc(String(t).slice(0,i))+'<b>'+esc(String(t).slice(i,i+q.length))+'</b>'+esc(String(t).slice(i+q.length));}
  function render(q){q=q.trim();if(q===''){list.hidden=true;matches=[];return;}var ql=q.toLowerCase();
    matches=CLASSES.filter(function(c){return (c.code+' '+c.name).toLowerCase().indexOf(ql)>=0;}).slice(0,40);
    if(!matches.length){list.innerHTML='<li class="meta" style="padding:10px;color:#5a6b7b"><?php echo __('No classes match.'); ?></li>';list.hidden=false;return;}
    list.innerHTML=matches.map(function(c,i){return '<li data-i="'+i+'"><span class="nm">'+hl(c.code,q)+'</span><div class="meta">'+hl(c.name,q)+'</div></li>';}).join('');hi=-1;list.hidden=false;}
  function choose(c){hid.value=c.id;chosen.innerHTML='<div><span class="nm">'+esc(c.code)+'</span> <span class="meta">'+esc(c.name)+'</span></div><span class="chg" id="chgClass"><?php echo __('Change'); ?></span>';chosen.hidden=false;search.hidden=true;list.hidden=true;toStep2.disabled=false;
    document.getElementById('chgClass').addEventListener('click',function(){chosen.hidden=true;search.hidden=false;search.value='';search.focus();hid.value='';toStep2.disabled=true;});}
  if(search){search.addEventListener('input',function(){render(this.value);});
    search.addEventListener('keydown',function(e){if(list.hidden)return;var items=list.querySelectorAll('li[data-i]');
      if(e.key==='ArrowDown'){e.preventDefault();hi=Math.min(hi+1,items.length-1);}else if(e.key==='ArrowUp'){e.preventDefault();hi=Math.max(hi-1,0);}
      else if(e.key==='Enter'){if(hi>=0&&matches[hi]){e.preventDefault();choose(matches[hi]);}return;}else return;
      items.forEach(function(el){el.classList.remove('hi');});if(items[hi]){items[hi].classList.add('hi');items[hi].scrollIntoView({block:'nearest'});}});
    list.addEventListener('click',function(e){var li=e.target.closest('li[data-i]');if(li)choose(matches[+li.dataset.i]);});
    document.addEventListener('click',function(e){if(!e.target.closest('.kcombo'))list.hidden=true;});}
  var chg0=document.getElementById('chgClass'); if(chg0){chg0.addEventListener('click',function(){chosen.hidden=true;if(search){search.hidden=false;search.value='';search.focus();}hid.value='';toStep2.disabled=true;});}
  function go(n){form.querySelectorAll('.panel').forEach(function(p){p.classList.toggle('active',p.dataset.step==String(n));});
    form.querySelectorAll('.steps .s').forEach(function(s){var d=+s.dataset.stepdot;s.classList.toggle('active',d==n);s.classList.toggle('done',d<n);});if(n===3)review();}
  function v(id){var el=document.getElementById(id);return el?(el.value||'').trim():'';}
  function valid2(){var n=document.getElementById('name'),ts=document.getElementById('timeStart'),te=document.getElementById('timeEnd');
    if(!n.value.trim()){n.focus();return false;} if(!ts.value){ts.focus();return false;} if(!te.value||te.value<=ts.value){te.focus();return false;} return true;}
  form.addEventListener('click',function(e){var nx=e.target.closest('[data-next]'),bk=e.target.closest('[data-back]');
    if(nx){if(nx.dataset.next==='2'&&(!hid.value||!v('date')))return;if(nx.dataset.next==='3'&&!valid2())return;go(+nx.dataset.next);}
    if(bk)go(+bk.dataset.back);});
  function review(){var c=CLASSES.filter(function(x){return x.id===hid.value;})[0]||{code:'—',name:''};
    var rows=[['<?php echo __('Class'); ?>',esc(c.code)+' <span class="meta">'+esc(c.name)+'</span>'],['<?php echo __('Date'); ?>',esc(v('date'))],['<?php echo __('Time'); ?>',esc(v('timeStart'))+'–'+esc(v('timeEnd'))],['<?php echo __('Lesson'); ?>',esc(v('name'))],['<?php echo __('Summary'); ?>',esc(v('summary')||'—')],['<?php echo __('Visible to'); ?>',(document.getElementById('viewableStudents').value==='Y'?'<?php echo __('Students'); ?> ':'')+(document.getElementById('viewableParents').value==='Y'?'<?php echo __('Parents'); ?>':'')||'<?php echo __('Staff only'); ?>']];
    document.getElementById('reviewBox').innerHTML=rows.map(function(r){return '<div><dt>'+r[0]+'</dt><dd>'+r[1]+'</dd></div>';}).join('');}
  form.addEventListener('submit',function(e){if(!hid.value||!v('date')||!valid2()){e.preventDefault();go(hid.value&&v('date')?2:1);}});
})();
</script>
<?php
echo ob_get_clean();
