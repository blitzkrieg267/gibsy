<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Staff Salaries (recurring definitions), finance entry.

Finance records a staff member's recurring salary through a short guided form
that starts with a SEARCHABLE staff picker (robust against long lists, shared
names and non-obvious usernames), then salary details with category + tags for
later management. Every entry is saved as approvalStatus 'Pending': it is NOT
payroll-eligible until a SCHOOL ADMINISTRATOR approves it on the Salary
Approvals page. Extends SMS's Finance module; no core edits. Sensitive →
finance/admin only.
*/

require_once __DIR__ . '/src/SalaryManager.php';
require_once __DIR__ . '/src/InstitutionImporter.php';

use Gibbon\Contracts\Database\Connection;
use Gibbon\Services\Format;

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_salary.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$manager = new KreckSalaryManager($container->get(Connection::class));
$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_salary.php';
$runURL = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_salary_run.php';
$approvalsURL = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_salary_approvals.php';
$canApprove = isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_salary_approvals.php');
$kreckAction = $_REQUEST['kreckAction'] ?? '';

// Column labels for the salary template / file-upload header matching.
$salaryCols = [
    'staff' => 'Staff', 'amount' => 'Amount', 'currency' => 'Currency', 'frequency' => 'Frequency', 'payDay' => 'Pay Day',
    'startDate' => 'Start Date', 'endDate' => 'End Date', 'category' => 'Category', 'tags' => 'Tags', 'notes' => 'Notes',
];

// Blank CSV template download for the salary file-upload path.
if ($kreckAction === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kreck_salary_template.csv"');
    echo implode(',', array_map(fn($l) => '"' . $l . '"', array_values($salaryCols))) . "\r\n";
    exit;
}

$saveResult = null;

// --- Single guided entry (the stepped form). Saved as Pending. --------------
if ($kreckAction === 'saveOne') {
    $pid = (int) ($_POST['gibbonPersonID'] ?? 0);
    $amount = KreckSalaryManager::normaliseAmount($_POST['amount'] ?? null);
    $startDate = trim((string) ($_POST['startDate'] ?? ''));
    $failed = [];
    $resolved = $pid > 0 ? $manager->resolveStaff((string) $pid) : null;
    if (!$resolved) {
        $failed[] = ['row' => 1, 'msg' => __('Please search for and select a valid staff member in step 1.')];
    }
    if ($amount === null) {
        $failed[] = ['row' => 1, 'msg' => __('Enter a positive amount to 2 decimal places.')];
    }
    if ($startDate === '') {
        $failed[] = ['row' => 1, 'msg' => __('A start date is required.')];
    }
    $created = 0;
    if (empty($failed)) {
        $manager->saveSalary([
            'gibbonPersonID' => $resolved,
            'amount'    => $amount,
            'frequency' => $_POST['frequency'] ?? 'Monthly',
            'payDay'    => $_POST['payDay'] ?? null,
            'startDate' => $startDate,
            'endDate'   => $_POST['endDate'] ?? null,
            'status'    => 'Active',
            'currency'  => $_POST['currency'] ?? null,
            'category'  => $_POST['category'] ?? null,
            'tags'      => $_POST['tags'] ?? null,
            'notes'     => $_POST['notes'] ?? null,
        ], (int) $session->get('gibbonPersonID'));
        $created = 1;
    }
    $saveResult = ['created' => $created, 'failed' => $failed, 'pending' => $created];
}

// --- Bulk file upload (unchanged transport; also saved as Pending). ---------
if ($kreckAction === 'saveFile') {
    $rows = [];
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $imp = new KreckInstitutionImporter(
            $container->get(Connection::class),
            $container->get(\Gibbon\Data\PasswordPolicy::class),
            $container->get(\Gibbon\Domain\System\SettingGateway::class),
            __DIR__ . '/imports'
        );
        $parsed = $imp->parseUploadedFile($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        $labelToKey = [];
        foreach ($salaryCols as $k => $label) { $labelToKey[strtolower($label)] = $k; $labelToKey[strtolower($k)] = $k; }
        foreach ($parsed['rows'] as $r) {
            $row = [];
            foreach ($r as $h => $v) { $key = $labelToKey[strtolower(trim($h))] ?? null; if ($key) $row[$key] = $v; }
            if (!empty($row)) $rows[] = $row;
        }
    }
    $created = 0; $failed = [];
    foreach ($rows as $i => $r) {
        $token = trim($r['staff'] ?? '');
        $amount = KreckSalaryManager::normaliseAmount($r['amount'] ?? null);
        if ($token === '' || $amount === null || empty($r['startDate'])) {
            $failed[] = ['row' => $i + 1, 'msg' => __('Each row needs a staff member, a positive amount (to 2 decimal places) and a start date.')];
            continue;
        }
        $pid = $manager->resolveStaff($token);
        if (!$pid) {
            $failed[] = ['row' => $i + 1, 'msg' => sprintf(__('Staff not found: %s'), $token)];
            continue;
        }
        $manager->saveSalary([
            'gibbonPersonID' => $pid, 'amount' => $amount,
            'frequency' => $r['frequency'] ?: 'Monthly', 'payDay' => $r['payDay'] ?: null,
            'startDate' => $r['startDate'], 'endDate' => $r['endDate'] ?? null, 'status' => 'Active',
            'currency' => $r['currency'] ?? null,
            'category' => $r['category'] ?? null, 'tags' => $r['tags'] ?? null, 'notes' => $r['notes'] ?? null,
        ], (int) $session->get('gibbonPersonID'));
        $created++;
    }
    $saveResult = ['created' => $created, 'failed' => $failed, 'pending' => $created];
}

$salaries = $manager->listSalaries();
$staffList = $manager->listStaff();
$pendingCount = $manager->pendingCount();

// Staff records for the searchable picker (name + username + email + job title).
$staffData = [];
foreach ($staffList as $st) {
    $name = Format::name($st['title'] ?? '', $st['preferredName'] ?? '', $st['surname'] ?? '', 'Staff', false, true);
    $staffData[] = [
        'id'   => (string) $st['gibbonPersonID'],
        'name' => $name,
        'user' => (string) ($st['username'] ?? ''),
        'mail' => (string) ($st['email'] ?? ''),
        'job'  => (string) ($st['jobTitle'] ?? ''),
    ];
}

// Category suggestions: common defaults merged with those already in use.
$commonCats = ['Teaching', 'Administration', 'Support', 'Management', 'Allowance', 'Stipend', 'Contract', 'Part-time'];
$categoryList = array_values(array_unique(array_merge($manager->salaryCategories(), $commonCats)));
sort($categoryList);

$currency = $container->get(\Gibbon\Domain\System\SettingGateway::class)->getSettingByScope('System', 'currency');
$defaultCurCode = KreckSalaryManager::currencyCodeFromSetting($currency) ?: 'USD';
$curCatalog = KreckSalaryManager::currencyCatalog();
// Make sure the school's own configured currency is always offered (and first).
if ($defaultCurCode !== '' && !isset($curCatalog[$defaultCurCode])) {
    $curCatalog = [$defaultCurCode => [$defaultCurCode, $defaultCurCode]] + $curCatalog;
}
$curSymbol = KreckSalaryManager::currencySymbol($defaultCurCode);
$rowSymbol = fn($code) => KreckSalaryManager::currencySymbol($code ?: $defaultCurCode);

$page->breadcrumbs->add(__('Staff Salaries'));

$approvalPill = function (string $s): string {
    $map = ['Pending' => 'ap-pending', 'Approved' => 'ap-approved', 'Rejected' => 'ap-rejected'];
    $cls = $map[$s] ?? 'ap-pending';
    return '<span class="pill ' . $cls . '">' . htmlspecialchars($s) . '</span>';
};

ob_start();
?>
<style>
.ksal{max-width:1020px}
.ksal .kbtn{background:#52701f;color:#fff;border:0;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.ksal .kbtn:hover{background:#3c5618;color:#fff}
.ksal .kbtn.ghost{background:#f0f1e6;color:#42571d}
.ksal .kbtn[disabled]{opacity:.5;cursor:not-allowed}
.ksal table{border-collapse:collapse;width:100%}
.ksal table.list th,.ksal table.list td{padding:8px 10px;border-bottom:1px solid #eef2f5;text-align:left;font-size:14px;vertical-align:top}
.ksal table.list th{background:#f4f7f9;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#5a6b7b}
.ksal .pill{padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}
.ksal .ap-pending{background:#fef9c3;color:#854d0e}.ksal .ap-approved{background:#dcfce7;color:#166534}.ksal .ap-rejected{background:#fee2e2;color:#991b1b}
.ksal .tag{display:inline-block;background:#f0f1e6;color:#42571d;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;margin:1px 2px}
.ksal .note{background:#f6f8f0;border:1px solid #d9e3cf;color:#42571d;border-radius:10px;padding:10px 14px;margin:10px 0}
/* Wizard */
.kwiz{border:1px solid #e2e8ee;border-radius:14px;padding:18px 20px;margin-top:10px;background:#fff}
.kwiz .steps{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.kwiz .steps .s{flex:1;min-width:140px;display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;background:#f4f7f9;color:#5a6b7b;font-weight:700;font-size:13px}
.kwiz .steps .s .n{width:22px;height:22px;border-radius:50%;background:#cbd5e1;color:#fff;display:grid;place-items:center;font-size:12px}
.kwiz .steps .s.active{background:#52701f;color:#fff}.kwiz .steps .s.active .n{background:#fff;color:#52701f}
.kwiz .steps .s.done .n{background:#6a8a28;color:#fff}
.kwiz .panel{display:none}.kwiz .panel.active{display:block}
.kwiz label{display:block;font-weight:700;font-size:13px;color:#10150d;margin:10px 0 4px}
.kwiz input[type=text],.kwiz input[type=number],.kwiz input[type=date],.kwiz select,.kwiz textarea{width:100%;border:1px solid #d9e1e8;border-radius:8px;padding:9px 10px;font-size:14px}
.kwiz .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:620px){.kwiz .grid2{grid-template-columns:1fr}}
.kwiz .nav{display:flex;gap:8px;margin-top:16px;align-items:center}
.kwiz .req{color:#d64545}
/* Searchable combobox */
.kcombo{position:relative}
.kcombo-list{list-style:none;margin:4px 0 0;padding:4px;border:1px solid #d9e1e8;border-radius:8px;max-height:260px;overflow:auto;position:absolute;z-index:20;background:#fff;width:100%;box-shadow:0 12px 30px rgba(15,30,46,.14)}
.kcombo-list li{padding:8px 10px;border-radius:6px;cursor:pointer}
.kcombo-list li:hover,.kcombo-list li.hi{background:#f0f1e6}
.kcombo-list li .nm{font-weight:700;color:#10150d;font-size:14px}
.kcombo-list li .meta{font-size:12px;color:#5a6b7b}
.kcombo-list li .meta b{color:#42571d;font-weight:700}
.kcombo-chosen{margin-top:8px;padding:10px 12px;border:1px solid #d9e3cf;background:#f4f7ec;border-radius:10px;display:flex;align-items:center;gap:10px}
.kcombo-chosen .nm{font-weight:800;color:#10150d}.kcombo-chosen .meta{font-size:12px;color:#5a6b7b}
.kcombo-chosen .chg{margin-left:auto;color:#42571d;font-weight:700;cursor:pointer;text-decoration:underline}
.kcombo-empty{padding:10px;color:#5a6b7b;font-size:13px}
.kreview dt{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#5a6b7b;margin-top:8px}
.kreview dd{margin:2px 0 0;font-weight:700;color:#10150d}
</style>
<div class="ksal">
    <p><?php echo __('Record a staff member\'s recurring salary. Finance enters each salary here; it becomes payroll-eligible only after a school administrator approves it.'); ?>
       &nbsp;<a class="kbtn ghost" href="<?php echo htmlspecialchars($runURL); ?>"><?php echo __('Payroll run →'); ?></a>
       <?php if ($canApprove): ?><a class="kbtn ghost" href="<?php echo htmlspecialchars($approvalsURL); ?>"><?php echo __('Salary approvals'); ?><?php echo $pendingCount ? ' (' . (int) $pendingCount . ')' : ''; ?> →</a><?php endif; ?>
    </p>

    <?php if ($saveResult !== null): ?>
        <div class="<?php echo empty($saveResult['failed']) ? 'success' : 'warning'; ?>">
            <?php if (!empty($saveResult['created'])): ?>
                <?php echo sprintf(__('%d salary definition(s) submitted — now pending school-admin approval.'), $saveResult['created']); ?>
            <?php endif; ?>
            <?php foreach ($saveResult['failed'] as $f): ?><br><span class="err"><?php echo htmlspecialchars($f['msg']); ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($pendingCount > 0): ?>
        <div class="note"><?php echo sprintf(__('%d salary definition(s) are awaiting school-admin approval and are not yet on payroll.'), (int) $pendingCount);
            echo $canApprove ? ' <a href="' . htmlspecialchars($approvalsURL) . '">' . __('Review now →') . '</a>' : ''; ?></div>
    <?php endif; ?>

    <h3><?php echo __('Current salary definitions'); ?> (<?php echo count($salaries); ?>)</h3>
    <table class="list">
        <thead><tr><th><?php echo __('Staff'); ?></th><th><?php echo __('Amount'); ?></th><th><?php echo __('Frequency'); ?></th><th><?php echo __('Start'); ?></th><th><?php echo __('Category / Tags'); ?></th><th><?php echo __('Approval'); ?></th></tr></thead>
        <tbody>
        <?php if (empty($salaries)): ?>
            <tr><td colspan="6" class="emphasis"><?php echo __('No salary definitions yet — add one below.'); ?></td></tr>
        <?php else: foreach ($salaries as $s): ?>
            <tr>
                <td><?php echo htmlspecialchars(Format::name($s['title'] ?? '', $s['preferredName'], $s['surname'], 'Staff', false)); ?>
                    <span class="emphasis small">(<?php echo htmlspecialchars($s['username']); ?>)</span></td>
                <td><?php echo htmlspecialchars($rowSymbol($s['currency'] ?? '')); ?><?php echo number_format((float) $s['amount'], 2); ?>
                    <span class="emphasis small"><?php echo htmlspecialchars(strtoupper((string) ($s['currency'] ?? '')) ?: $defaultCurCode); ?></span></td>
                <td><?php echo htmlspecialchars($s['frequency']); ?></td>
                <td><?php echo htmlspecialchars((string) $s['startDate']); ?></td>
                <td><?php echo htmlspecialchars((string) ($s['category'] ?? '') ?: '—'); ?>
                    <?php if (!empty($s['tags'])): foreach (array_filter(array_map('trim', explode(',', $s['tags']))) as $t): ?><span class="tag"><?php echo htmlspecialchars($t); ?></span><?php endforeach; endif; ?></td>
                <td><?php echo $approvalPill((string) ($s['approvalStatus'] ?? 'Pending')); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <h3 style="margin-top:22px"><?php echo __('Add a salary'); ?></h3>
    <form method="post" action="<?php echo htmlspecialchars($self); ?>" id="salForm" class="kwiz">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_salary.php">
        <input type="hidden" name="kreckAction" value="saveOne">
        <input type="hidden" name="gibbonPersonID" id="gibbonPersonID">

        <div class="steps">
            <div class="s active" data-stepdot="1"><span class="n">1</span><?php echo __('Find staff'); ?></div>
            <div class="s" data-stepdot="2"><span class="n">2</span><?php echo __('Salary details'); ?></div>
            <div class="s" data-stepdot="3"><span class="n">3</span><?php echo __('Review & submit'); ?></div>
        </div>

        <!-- Step 1: searchable staff picker -->
        <div class="panel active" data-step="1">
            <label for="staffSearch"><?php echo __('Search staff'); ?> <span class="req">*</span></label>
            <div class="kcombo">
                <input type="text" id="staffSearch" autocomplete="off" placeholder="<?php echo __('Type a name, username, email or job title…'); ?>">
                <ul class="kcombo-list" id="staffResults" hidden></ul>
            </div>
            <div class="kcombo-chosen" id="staffChosen" hidden></div>
            <p class="emphasis small"><?php echo sprintf(__('%d active staff. Search matches name, username, email and job title, so people who share a name are easy to tell apart.'), count($staffData)); ?></p>
            <div class="nav">
                <span style="flex:1"></span>
                <button type="button" class="kbtn" data-next="2" id="toStep2" disabled><?php echo __('Next →'); ?></button>
            </div>
        </div>

        <!-- Step 2: salary details -->
        <div class="panel" data-step="2">
            <div class="grid2">
                <div>
                    <label><?php echo __('Amount'); ?> <span class="req">*</span> (2 d.p.)</label>
                    <input type="number" name="amount" id="amount" step="0.01" min="0.01" inputmode="decimal" placeholder="8500.00">
                </div>
                <div>
                    <label><?php echo __('Currency'); ?></label>
                    <select name="currency" id="currency">
                        <?php foreach ($curCatalog as $code => $info): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>"<?php echo $code === $defaultCurCode ? ' selected' : ''; ?>><?php echo htmlspecialchars($code . ' — ' . $info[1]); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo __('Frequency'); ?></label>
                    <select name="frequency" id="frequency"><option>Monthly</option><option>Fortnightly</option><option>Weekly</option><option>Termly</option><option>Annually</option><option>Once</option></select>
                </div>
                <div>
                    <label><?php echo __('Pay day of month'); ?> (1–28)</label>
                    <input type="number" name="payDay" id="payDay" min="1" max="28" placeholder="25">
                </div>
                <div>
                    <label><?php echo __('Start date'); ?> <span class="req">*</span></label>
                    <input type="date" name="startDate" id="startDate">
                </div>
                <div>
                    <label><?php echo __('End date'); ?> (<?php echo __('optional'); ?>)</label>
                    <input type="date" name="endDate" id="endDate">
                </div>
                <div>
                    <label><?php echo __('Category'); ?></label>
                    <input type="text" name="category" id="category" list="catList" placeholder="<?php echo __('e.g. Teaching, Administration…'); ?>">
                    <datalist id="catList"><?php foreach ($categoryList as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"><?php endforeach; ?></datalist>
                </div>
            </div>
            <label><?php echo __('Tags'); ?> (<?php echo __('comma-separated — for finance to filter and manage later'); ?>)</label>
            <input type="text" name="tags" id="tags" placeholder="<?php echo __('e.g. full-time, department-science, contract-2026'); ?>">
            <label><?php echo __('Notes'); ?></label>
            <textarea name="notes" id="notes" rows="2"></textarea>
            <div class="nav">
                <button type="button" class="kbtn ghost" data-back="1"><?php echo __('← Back'); ?></button>
                <span style="flex:1"></span>
                <button type="button" class="kbtn" data-next="3" id="toStep3"><?php echo __('Review →'); ?></button>
            </div>
        </div>

        <!-- Step 3: review + submit -->
        <div class="panel" data-step="3">
            <dl class="kreview grid2" id="reviewBox"></dl>
            <div class="note"><?php echo __('On submit this salary is saved as Pending. It appears on payroll only after a school administrator approves it.'); ?></div>
            <div class="nav">
                <button type="button" class="kbtn ghost" data-back="2"><?php echo __('← Back'); ?></button>
                <span style="flex:1"></span>
                <button type="submit" class="kbtn" id="submitBtn"><?php echo __('Submit for approval'); ?></button>
            </div>
        </div>
    </form>

    <h3 style="margin-top:22px"><?php echo __('…or upload a file'); ?></h3>
    <form method="post" action="<?php echo htmlspecialchars($self); ?>" enctype="multipart/form-data" style="padding:12px 14px;background:#f8fbfb;border:1px solid #e2e8ee;border-radius:8px">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_salary.php">
        <p class="emphasis small" style="margin-top:0"><?php echo __('Upload a .csv or .xlsx. Columns: Staff, Amount, Frequency, Pay Day, Start Date, End Date, Category, Tags, Notes. Uploaded rows are also saved as Pending.'); ?>
           <a href="<?php echo htmlspecialchars($self . '&kreckAction=template'); ?>"><?php echo __('Download blank template'); ?></a>.</p>
        <input type="file" name="file" accept=".csv,.xlsx,.xls,.ods" required>
        <button type="submit" class="kbtn" name="kreckAction" value="saveFile" style="margin-left:8px"><?php echo __('Upload salaries'); ?></button>
    </form>
</div>
<script>
(function(){
  var STAFF = <?php echo json_encode($staffData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  var CURMAP = <?php echo json_encode(array_map(fn($i) => $i[0], $curCatalog), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  function curCode(){ var el=document.getElementById('currency'); return el? el.value : '<?php echo $defaultCurCode; ?>'; }
  function curSym(){ var c=curCode(); return CURMAP[c]||c; }
  var form = document.getElementById('salForm');
  var hidId = document.getElementById('gibbonPersonID');
  var search = document.getElementById('staffSearch');
  var list = document.getElementById('staffResults');
  var chosen = document.getElementById('staffChosen');
  var toStep2 = document.getElementById('toStep2');
  var hi = -1, matches = [];

  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
  function hl(text, q){ if(!q) return esc(text); var i=String(text).toLowerCase().indexOf(q.toLowerCase()); if(i<0) return esc(text); return esc(String(text).slice(0,i))+'<b>'+esc(String(text).slice(i,i+q.length))+'</b>'+esc(String(text).slice(i+q.length)); }

  function render(q){
    q=q.trim();
    if(q===''){ list.hidden=true; matches=[]; return; }
    var ql=q.toLowerCase();
    matches = STAFF.filter(function(s){
      return (s.name+' '+s.user+' '+s.mail+' '+s.job).toLowerCase().indexOf(ql)>=0;
    }).slice(0,40);
    if(matches.length===0){ list.innerHTML='<li class="kcombo-empty"><?php echo __('No staff match that search.'); ?></li>'; list.hidden=false; return; }
    list.innerHTML = matches.map(function(s,idx){
      var meta=[]; if(s.user)meta.push('@'+hl(s.user,q)); if(s.job)meta.push(hl(s.job,q)); if(s.mail)meta.push(hl(s.mail,q));
      return '<li data-i="'+idx+'"><span class="nm">'+hl(s.name,q)+'</span><div class="meta">'+meta.join(' · ')+'</div></li>';
    }).join('');
    hi=-1; list.hidden=false;
  }
  function choose(s){
    hidId.value=s.id;
    var meta=[]; if(s.user)meta.push('@'+esc(s.user)); if(s.job)meta.push(esc(s.job)); if(s.mail)meta.push(esc(s.mail));
    chosen.innerHTML='<div><div class="nm">'+esc(s.name)+'</div><div class="meta">'+meta.join(' · ')+'</div></div><span class="chg" id="chgStaff"><?php echo __('Change'); ?></span>';
    chosen.hidden=false; search.hidden=true; list.hidden=true; toStep2.disabled=false;
    document.getElementById('chgStaff').addEventListener('click',function(){ chosen.hidden=true; search.hidden=false; search.value=''; search.focus(); hidId.value=''; toStep2.disabled=true; });
  }
  search.addEventListener('input',function(){ render(this.value); });
  search.addEventListener('keydown',function(e){
    if(list.hidden) return;
    var items=list.querySelectorAll('li[data-i]');
    if(e.key==='ArrowDown'){e.preventDefault();hi=Math.min(hi+1,items.length-1);}
    else if(e.key==='ArrowUp'){e.preventDefault();hi=Math.max(hi-1,0);}
    else if(e.key==='Enter'){ if(hi>=0&&matches[hi]){e.preventDefault();choose(matches[hi]);} return; }
    else return;
    items.forEach(function(el){el.classList.remove('hi');}); if(items[hi]){items[hi].classList.add('hi');items[hi].scrollIntoView({block:'nearest'});}
  });
  list.addEventListener('click',function(e){ var li=e.target.closest('li[data-i]'); if(li){ choose(matches[+li.dataset.i]); } });
  document.addEventListener('click',function(e){ if(!e.target.closest('.kcombo')) list.hidden=true; });

  // Step navigation
  function go(n){
    form.querySelectorAll('.panel').forEach(function(p){p.classList.toggle('active', p.dataset.step==String(n));});
    form.querySelectorAll('.steps .s').forEach(function(s){ var d=+s.dataset.stepdot; s.classList.toggle('active', d==n); s.classList.toggle('done', d<n); });
    if(n===3) buildReview();
  }
  form.addEventListener('click',function(e){
    var nx=e.target.closest('[data-next]'), bk=e.target.closest('[data-back]');
    if(nx){ if(nx.dataset.next==='2' && !hidId.value){ return; }
            if(nx.dataset.next==='3' && !validStep2()){ return; } go(+nx.dataset.next); }
    if(bk){ go(+bk.dataset.back); }
  });
  function validStep2(){
    var amt=document.getElementById('amount'), sd=document.getElementById('startDate');
    var ok=true;
    if(!(parseFloat(amt.value)>0)){ amt.focus(); amt.reportValidity&&amt.reportValidity(); ok=false; }
    else if(!sd.value){ sd.focus(); sd.reportValidity&&sd.reportValidity(); ok=false; }
    return ok;
  }
  function v(id){return (document.getElementById(id).value||'').trim();}
  function buildReview(){
    var s=STAFF.filter(function(x){return x.id===hidId.value;})[0]||{name:'—'};
    var rows=[
      ['<?php echo __('Staff'); ?>', esc(s.name)+(s.user?' <span class="meta">(@'+esc(s.user)+')</span>':'')],
      ['<?php echo __('Amount'); ?>', esc(curSym())+esc(parseFloat(v('amount')||'0').toFixed(2))+' <span class="meta">'+esc(curCode())+'</span>'],
      ['<?php echo __('Frequency'); ?>', esc(v('frequency'))],
      ['<?php echo __('Pay day'); ?>', esc(v('payDay')||'—')],
      ['<?php echo __('Start'); ?>', esc(v('startDate'))],
      ['<?php echo __('End'); ?>', esc(v('endDate')||'—')],
      ['<?php echo __('Category'); ?>', esc(v('category')||'—')],
      ['<?php echo __('Tags'); ?>', esc(v('tags')||'—')],
      ['<?php echo __('Notes'); ?>', esc(v('notes')||'—')]
    ];
    document.getElementById('reviewBox').innerHTML = rows.map(function(r){return '<div><dt>'+r[0]+'</dt><dd>'+r[1]+'</dd></div>';}).join('');
  }
  form.addEventListener('submit',function(e){ if(!hidId.value || !validStep2()){ e.preventDefault(); go(hidId.value?2:1); } });
})();
</script>
<?php
echo ob_get_clean();
