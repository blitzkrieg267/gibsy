<?php
/*
Kreck Bridge — Staff Salaries (recurring definitions).

Finance records each staff member's recurring salary (amount + frequency + pay
day + active window). Entry via the inbuilt HTML table (and file import shares
the same engine elsewhere). Extends Gibbon's Finance module; no core edits.
Access is finance/admin only (sensitive).
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
$kreckAction = $_REQUEST['kreckAction'] ?? '';

// Column labels for the salary template / file-upload header matching.
$salaryCols = [
    'staff' => 'Staff', 'amount' => 'Amount', 'frequency' => 'Frequency', 'payDay' => 'Pay Day',
    'startDate' => 'Start Date', 'endDate' => 'End Date', 'status' => 'Status', 'notes' => 'Notes',
];

// Blank CSV template download for the salary file-upload path.
if ($kreckAction === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kreck_salary_template.csv"');
    echo implode(',', array_map(fn($l) => '"' . $l . '"', array_values($salaryCols))) . "\r\n";
    exit;
}

$saveResult = null;
if ($kreckAction === 'save' || $kreckAction === 'saveFile') {
    $rows = [];
    if ($kreckAction === 'saveFile' && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        // Reuse the importer's file parser, then map headers (label or key) to salary fields.
        $imp = new KreckInstitutionImporter(
            $container->get(Connection::class),
            $container->get(\Gibbon\Data\PasswordPolicy::class),
            $container->get(\Gibbon\Forms\PersonalDataFieldSettings::class),
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
    } else {
        $rows = json_decode($_POST['rowsJson'] ?? '[]', true) ?: [];
    }
    $created = 0; $failed = [];
    foreach ($rows as $i => $r) {
        $token = trim($r['staff'] ?? '');
        if ($token === '' || empty($r['amount']) || empty($r['startDate'])) {
            $failed[] = ['row' => $i + 1, 'msg' => __('Staff, Amount and Start Date are required.')];
            continue;
        }
        $pid = $manager->resolveStaff($token);
        if (!$pid) {
            $failed[] = ['row' => $i + 1, 'msg' => sprintf(__('Staff not found: %s'), $token)];
            continue;
        }
        $manager->saveSalary([
            'gibbonPersonID' => $pid,
            'amount'    => $r['amount'],
            'frequency' => $r['frequency'] ?: 'Monthly',
            'payDay'    => $r['payDay'] ?: null,
            'startDate' => $r['startDate'],
            'endDate'   => $r['endDate'] ?? null,
            'status'    => $r['status'] ?: 'Active',
            'notes'     => $r['notes'] ?? null,
        ], (int) $session->get('gibbonPersonID'));
        $created++;
    }
    $saveResult = ['created' => $created, 'failed' => $failed];
}

$salaries = $manager->listSalaries();
$currency = $container->get(\Gibbon\Domain\System\SettingGateway::class)->getSettingByScope('System', 'currency');
$curSymbol = preg_replace('/\s*\[.*/', '', (string) $currency);

$page->breadcrumbs->add(__('Staff Salaries'));

ob_start();
?>
<style>
.ksal .kbtn{background:#0e9f9a;color:#fff;border:0;border-radius:6px;padding:8px 14px;font-weight:600;cursor:pointer;text-decoration:none}
.ksal .kbtn.ghost{background:#eef5f4;color:#0e6b63}
.ksal table{border-collapse:collapse;width:100%}
.ksal table.list th,.ksal table.list td{padding:7px 10px;border-bottom:1px solid #eef2f5;text-align:left;font-size:14px}
.ksal table.list th{background:#f4f7f9}
.ksal .gridwrap{overflow-x:auto;border:1px solid #e2e8ee;border-radius:8px;margin-top:8px}
.ksal table.grid th{background:#f4f7f9;padding:6px 8px;font-size:12px;text-align:left;white-space:nowrap;border-bottom:1px solid #e2e8ee}
.ksal table.grid td{padding:2px;border-bottom:1px solid #eef2f5}
.ksal table.grid input,.ksal table.grid select{width:100%;min-width:110px;border:1px solid #d9e1e8;border-radius:4px;padding:5px 6px}
.ksal .pill{padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700}
.ksal .Active{background:#dcfce7;color:#166534}.ksal .Suspended{background:#fef9c3;color:#854d0e}.ksal .Ended{background:#f1f5f9;color:#475569}
.ksal .row-del{color:#d64545;cursor:pointer;font-weight:700;padding:0 8px}
</style>
<div class="ksal" style="max-width:1000px">
    <p><?php echo __('Record each staff member\'s recurring salary. These definitions drive the monthly payroll run, where finance generates, finalizes and marks salaries paid.'); ?>
       &nbsp;<a class="kbtn" href="<?php echo htmlspecialchars($runURL); ?>"><?php echo __('Go to payroll run →'); ?></a></p>

    <?php if ($saveResult !== null): ?>
        <div class="<?php echo empty($saveResult['failed']) ? 'success' : 'warning'; ?>">
            <?php echo sprintf(__('%d salary definition(s) saved.'), $saveResult['created']); ?>
            <?php foreach ($saveResult['failed'] as $f): ?><br><span class="err">Row <?php echo $f['row']; ?>: <?php echo htmlspecialchars($f['msg']); ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h3><?php echo __('Current salary definitions'); ?> (<?php echo count($salaries); ?>)</h3>
    <table class="list">
        <thead><tr><th><?php echo __('Staff'); ?></th><th><?php echo __('Amount'); ?></th><th><?php echo __('Frequency'); ?></th><th><?php echo __('Pay Day'); ?></th><th><?php echo __('Start'); ?></th><th><?php echo __('End'); ?></th><th><?php echo __('Status'); ?></th></tr></thead>
        <tbody>
        <?php if (empty($salaries)): ?>
            <tr><td colspan="7" class="emphasis"><?php echo __('No salary definitions yet — add some below.'); ?></td></tr>
        <?php else: foreach ($salaries as $s): ?>
            <tr>
                <td><?php echo htmlspecialchars(Format::name($s['title'] ?? '', $s['preferredName'], $s['surname'], 'Staff', false)); ?> <span class="emphasis small">(<?php echo htmlspecialchars($s['username']); ?>)</span></td>
                <td><?php echo htmlspecialchars($curSymbol); ?><?php echo number_format((float) $s['amount'], 2); ?></td>
                <td><?php echo htmlspecialchars($s['frequency']); ?></td>
                <td><?php echo htmlspecialchars((string) $s['payDay']); ?></td>
                <td><?php echo htmlspecialchars((string) $s['startDate']); ?></td>
                <td><?php echo htmlspecialchars($s['endDate'] ?: '—'); ?></td>
                <td><span class="pill <?php echo htmlspecialchars($s['status']); ?>"><?php echo htmlspecialchars($s['status']); ?></span></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <h3 style="margin-top:22px"><?php echo __('Add salary definitions'); ?></h3>
    <form method="post" action="<?php echo htmlspecialchars($self); ?>" id="salForm">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_salary.php">
        <input type="hidden" name="rowsJson" id="rowsJson">
        <div class="gridwrap"><table class="grid" id="grid"><thead><tr>
            <th><?php echo __('Staff'); ?> (username/name)<span style="color:#d64545">*</span></th>
            <th><?php echo __('Amount'); ?><span style="color:#d64545">*</span></th>
            <th><?php echo __('Frequency'); ?></th>
            <th><?php echo __('Pay Day'); ?> (1-28)</th>
            <th><?php echo __('Start Date'); ?><span style="color:#d64545">*</span></th>
            <th><?php echo __('End Date'); ?></th>
            <th><?php echo __('Status'); ?></th>
            <th><?php echo __('Notes'); ?></th>
            <th></th>
        </tr></thead><tbody id="gridBody">
            <tr>
                <td><input data-field="staff" placeholder="e.g. 119 or Nathan Schultz"></td>
                <td><input data-field="amount" type="number" step="0.01" placeholder="8500.00"></td>
                <td><select data-field="frequency"><option>Monthly</option><option>Fortnightly</option><option>Weekly</option><option>Termly</option><option>Annually</option><option>Once</option></select></td>
                <td><input data-field="payDay" type="number" min="1" max="28" placeholder="25"></td>
                <td><input data-field="startDate" type="date"></td>
                <td><input data-field="endDate" type="date"></td>
                <td><select data-field="status"><option>Active</option><option>Suspended</option><option>Ended</option></select></td>
                <td><input data-field="notes"></td>
                <td><span class="row-del">&times;</span></td>
            </tr>
        </tbody></table></div>
        <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
            <button type="button" class="kbtn ghost" id="addRow">+ <?php echo __('Add row'); ?></button>
            <span style="flex:1"></span>
            <button type="submit" class="kbtn" name="kreckAction" value="save"><?php echo __('Save salaries'); ?></button>
        </div>
    </form>

    <h3 style="margin-top:22px"><?php echo __('…or upload a file'); ?></h3>
    <form method="post" action="<?php echo htmlspecialchars($self); ?>" enctype="multipart/form-data" style="padding:12px 14px;background:#f8fbfb;border:1px solid #e2e8ee;border-radius:8px">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_salary.php">
        <p class="emphasis small" style="margin-top:0"><?php echo __('Upload a .csv or .xlsx of salary definitions. Columns: Staff, Amount, Frequency, Pay Day, Start Date, End Date, Status, Notes.'); ?>
           <a href="<?php echo htmlspecialchars($self . '&kreckAction=template'); ?>"><?php echo __('Download blank template'); ?></a>.</p>
        <input type="file" name="file" accept=".csv,.xlsx,.xls,.ods" required>
        <button type="submit" class="kbtn" name="kreckAction" value="saveFile" style="margin-left:8px"><?php echo __('Upload salaries'); ?></button>
    </form>
</div>
<script>
(function(){
  var body=document.getElementById('gridBody');
  function template(){return body.querySelector('tr').innerHTML;}
  var tpl=template();
  document.getElementById('addRow').addEventListener('click',function(){var tr=document.createElement('tr');tr.innerHTML=tpl;body.appendChild(tr);});
  body.addEventListener('click',function(e){if(e.target.classList.contains('row-del')){var rows=body.querySelectorAll('tr');if(rows.length>1)e.target.closest('tr').remove();else e.target.closest('tr').querySelectorAll('input').forEach(i=>i.value='');}});
  document.getElementById('salForm').addEventListener('submit',function(){
    var out=[];body.querySelectorAll('tr').forEach(function(tr){var row={},any=false;tr.querySelectorAll('[data-field]').forEach(function(inp){row[inp.dataset.field]=inp.value;if(inp.dataset.field==='staff'&&inp.value.trim()!=='')any=true;});if(any)out.push(row);});
    document.getElementById('rowsJson').value=JSON.stringify(out);
  });
})();
</script>
<?php
echo ob_get_clean();
