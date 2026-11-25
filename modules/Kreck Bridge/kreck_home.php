<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);
require_once __DIR__ . '/src/HomePanel.php';
require_once __DIR__ . '/src/CorePatch.php';

/*
Kreck Bridge — KRECK Home (module page).

The same panel that also appears on the main Home page, directly after the timetable
(see src/HomePanel.php and the managed patch in src/CorePatch.php). Kept as a page so the
menu entry, deep links and the "KRECK Home" permission keep working. Read-only for school
records. For administrators it also shows the state of the Home-page integration, applies it
automatically the first time it is seen, and offers a one-click re-apply after a Gibbon upgrade.
*/

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_home.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('KRECK Home'));

// ---- Home-page integration (administrators only) --------------------------------------------
$integrationHtml = '';
if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_settings.php')) {
    $note = null;
    $op = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['kreckCorePatch'] ?? '') : '';
    if ($op === 'apply' || $op === 'revert') {
        $r = $op === 'apply' ? KreckCorePatch::apply() : KreckCorePatch::revert();
        $note = [$r['ok'] ? 'ok' : 'warn', $r['message']];
    } else {
        // First sight (or after a Gibbon upgrade): apply automatically when it is safe to.
        $before = KreckCorePatch::status();
        KreckCorePatch::ensure();
        foreach (KreckCorePatch::status() as $id => $row) {
            if (($before[$id]['state'] ?? '') === 'pending' && $row['state'] === 'applied') {
                $note = ['ok', __('KRECK Home is now shown on the main Home page, after the timetable.')];
            }
        }
    }
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_home.php';
    foreach (KreckCorePatch::status() as $row) {
        $ok = $row['state'] === 'applied';
        $integrationHtml .= '<div style="max-width:1060px;margin:0 auto 14px;padding:10px 16px;border-radius:12px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;font-size:13px;'
            . ($ok ? 'background:#f1f6e6;border:1px solid #d9e3cf;color:#42571d' : 'background:#fff7ed;border:1px solid #fed7aa;color:#9a3412') . '">'
            . '<strong>' . $esc(__('Home page integration')) . ':</strong> '
            . '<span style="flex:1;min-width:220px">' . $esc($row['label']) . ' — '
            . ($ok ? $esc(__('active')) : $esc(__('not active')) . ($row['message'] ? ' (' . $esc($row['message']) . ')' : '')) . '</span>'
            . ($note ? '<span style="font-weight:700">' . $esc($note[1]) . '</span>' : '')
            . '<form method="post" action="' . $esc($self) . '" style="margin:0"><input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_home.php">'
            . '<button type="submit" name="kreckCorePatch" value="apply" style="background:#52701f;color:#fff;border:0;border-radius:8px;padding:6px 14px;font-weight:700;cursor:pointer">'
            . $esc($ok ? __('Re-check') : __('Apply now')) . '</button></form></div>';
    }
}

echo $integrationHtml . KreckHomePanel::render($guid, $connection2, $session, ['context' => 'page']);
