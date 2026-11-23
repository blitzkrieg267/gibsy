<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
Kreck Bridge — Export to Kreck LMS.

Shows a readiness summary and LMS preflight impact, supports explicit
manifest synchronization, and retains JSON download as a fallback. It never
writes to SMS SIS records.
*/

require_once __DIR__ . '/src/ManifestBuilder.php';
require_once __DIR__ . '/src/Exporter.php';

if (isActionAccessible($guid, $connection2, '/modules/Kreck Bridge/kreck_export.php') == false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

// Load module settings (scope = module name). $connection2 is a raw PDO handle.
$settings = [];
$sres = $connection2->prepare("SELECT name, value FROM gibbonSetting WHERE scope='Kreck Bridge'");
$sres->execute();
foreach ($sres->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    $settings[$r['name']] = $r['value'];
}
$siteTimezone = trim((string) $session->get('timezone'));
if ($siteTimezone !== '') {
    $settings['siteTimezone'] = $siteTimezone;
}

$builder = new KreckManifestBuilder($connection2, $settings);

$selectedYear = $_REQUEST['gibbonSchoolYearID'] ?? null;
$limitClasses = isset($_REQUEST['limitClasses']) ? (int) $_REQUEST['limitClasses'] : 0;
$existingCoursePolicy = (string) ($_REQUEST['existingcoursepolicy'] ?? 'block');
if (!in_array($existingCoursePolicy, ['block', 'merge'], true)) {
    $existingCoursePolicy = 'block';
}
$syncResult = null;

/**
 * Submit a manifest for dry-run or provisioning and return LMS's result.
 *
 * @param array $manifest
 * @param array $settings
 * @param bool $dryrun
 * @return array
 */
$sendManifest = static function (array $manifest, array $settings, bool $dryrun, string $existingCoursePolicy): array {
    // The sync engine lives in src/Exporter.php so it can also run automatically
    // after the demo seeder; this page behaves exactly as before.
    return KreckExporter::sync($manifest, $settings, $dryrun, $existingCoursePolicy);
};

$safeUrl = static function (string $url): string {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return __('Invalid URL');
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . $port . ($parts['path'] ?? '');
};

$checkMoodleConnection = static function (array $settings) use ($connection2, $safeUrl): array {
    $token = trim((string) ($settings['moodleToken'] ?? ''));
    $moodleBase = rtrim((string) ($settings['moodleEndpoint'] ?? ''), '/');
    $urlparts = parse_url($moodleBase);
    $databaseIdentity = $connection2->query(
        'SELECT DATABASE() AS databaseName, @@hostname AS databaseHost'
    )->fetch(\PDO::FETCH_ASSOC);
    $debug = [
        'activeGibbonDatabase' => [
            'name' => (string) ($databaseIdentity['databaseName'] ?? 'unknown'),
            'server' => (string) ($databaseIdentity['databaseHost'] ?? 'unknown'),
        ],
        'configuration' => [
            'moodleEndpoint' => $safeUrl($moodleBase),
            'moodleToken' => $token === '' ? 'missing or empty' : 'set; value redacted; length ' . strlen($token),
            'httpsEndpoint' => $urlparts && ($urlparts['scheme'] ?? '') === 'https' && !empty($urlparts['host']),
        ],
        'steps' => [],
    ];

    $scheme = strtolower((string) ($urlparts['scheme'] ?? ''));
    $host = strtolower((string) ($urlparts['host'] ?? ''));
    // Loopback hosts may use http:// for local testing; everything else must be https://.
    $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    // Report each misconfiguration with its own specific, actionable reason.
    if ($moodleBase === '' || !$urlparts || $host === '') {
        $debug['steps'][] = [
            'step' => 'Validate KRECK LMS settings',
            'result' => 'failed',
            'detail' => 'No KRECK LMS base URL is configured.',
        ];
        return [
            'ok' => false,
            'message' => __('Set the KRECK LMS base URL in Kreck Settings first.'),
            'debug' => $debug,
        ];
    }
    if ($scheme !== 'https' && !$isLoopback) {
        $debug['steps'][] = [
            'step' => 'Validate KRECK LMS settings',
            'result' => 'failed',
            'detail' => 'The KRECK LMS URL must start with https:// (http:// is accepted only for localhost).',
        ];
        return [
            'ok' => false,
            'message' => __('The KRECK LMS URL must start with https:// — update it in Kreck Settings.'),
            'debug' => $debug,
        ];
    }
    if ($token === '') {
        $debug['steps'][] = [
            'step' => 'Validate KRECK LMS settings',
            'result' => 'failed',
            'detail' => 'No restricted web-service token is configured.',
        ];
        return [
            'ok' => false,
            'message' => __('Enter the KRECK LMS web-service token in Kreck Settings first.'),
            'debug' => $debug,
        ];
    }
    if (!function_exists('curl_init')) {
        $debug['steps'][] = ['step' => 'Initialize transport', 'result' => 'failed', 'detail' => 'PHP cURL is not installed.'];
        return [
            'ok' => false,
            'message' => __('PHP cURL is required to check the LMS connection.'),
            'debug' => $debug,
        ];
    }

    $endpoint = preg_match('#/webservice/rest/server\.php$#', $moodleBase)
        ? $moodleBase : $moodleBase . '/webservice/rest/server.php';
    $debug['configuration']['requestEndpoint'] = $safeUrl($endpoint);
    $curl = curl_init($endpoint);
    if ($curl === false) {
        $debug['steps'][] = ['step' => 'Initialize transport', 'result' => 'failed', 'detail' => 'curl_init returned false.'];
        return [
            'ok' => false,
            'message' => __('Could not initialize the HTTPS request to LMS.'),
            'debug' => $debug,
        ];
    }
    $configured = curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'wstoken' => $token,
            'wsfunction' => 'local_kreck_ping',
            'moodlewsrestformat' => 'json',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    if (!$configured) {
        $debug['steps'][] = ['step' => 'Configure request', 'result' => 'failed', 'detail' => 'curl_setopt_array returned false.'];
        curl_close($curl);
        return [
            'ok' => false,
            'message' => __('Could not configure the HTTPS request to LMS.'),
            'debug' => $debug,
        ];
    }

    $debug['steps'][] = [
        'step' => 'Prepare request',
        'result' => 'passed',
        'detail' => 'POST local_kreck_ping; TLS peer and hostname verification enabled; token value redacted.',
    ];
    $started = microtime(true);
    $body = curl_exec($curl);
    $curlInfo = curl_getinfo($curl);
    $httpStatus = (int) ($curlInfo['http_code'] ?? 0);
    $curlErrno = curl_errno($curl);
    $curlError = curl_error($curl);
    if ($token !== '') {
        $curlError = str_replace($token, '[REDACTED]', $curlError);
    }
    $curlError = str_replace($endpoint, $safeUrl($endpoint), $curlError);
    curl_close($curl);
    $durationMs = (int) round((microtime(true) - $started) * 1000);
    $debug['steps'][] = [
        'step' => 'HTTPS transport',
        'result' => $body === false ? 'failed' : 'completed',
        'httpStatus' => $httpStatus,
        'curlErrno' => $curlErrno,
        'curlError' => $curlError,
        'durationMs' => $durationMs,
        'dnsMs' => isset($curlInfo['namelookup_time']) ? (int) round($curlInfo['namelookup_time'] * 1000) : null,
        'connectMs' => isset($curlInfo['connect_time']) ? (int) round($curlInfo['connect_time'] * 1000) : null,
        'firstByteMs' => isset($curlInfo['starttransfer_time']) ? (int) round($curlInfo['starttransfer_time'] * 1000) : null,
    ];
    if ($body === false) {
        return [
            'ok' => false,
            'message' => __('LMS connection failed: ') . $curlError,
            'debug' => $debug,
        ];
    }

    $response = json_decode($body, true);
    $safeBody = $token === '' ? $body : str_replace($token, '[REDACTED]', $body);
    $debug['steps'][] = [
        'step' => 'LMS response',
        'result' => is_array($response) ? 'JSON received' : 'non-JSON response',
        'httpStatus' => $httpStatus,
        'excerpt' => substr(preg_replace('/\s+/', ' ', strip_tags($safeBody)), 0, 500),
    ];
    if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($response)
            || ($response['status'] ?? '') !== 'ok'
            || ($response['component'] ?? '') !== 'local_kreck') {
        // Map the LMS web-service error code to a specific, actionable reason.
        $errorcode = is_array($response) ? strtolower((string) ($response['errorcode'] ?? '')) : '';
        $rawmessage = is_array($response)
            ? (string) ($response['message'] ?? $response['exception'] ?? '')
            : '';
        $reasons = [
            'invalidtoken'          => __('The KRECK LMS rejected the token (invalid or expired). Recreate the restricted token on the LMS and paste it in Kreck Settings.'),
            'servicenotavailable'   => __('The KRECK LMS web service is not available for this token. Enable the KRECK service on the LMS and add this token user to it.'),
            'enablewsdescription'   => __('Web services are disabled on the KRECK LMS. Enable web services in LMS site administration.'),
            'webservicesnotenabled' => __('Web services are disabled on the KRECK LMS. Enable web services in LMS site administration.'),
            'restnotenabled'        => __('The REST protocol is disabled on the KRECK LMS. Enable the REST web-service protocol in LMS site administration.'),
            'accessexception'       => __('The token user lacks permission on the KRECK LMS. Grant the token user the local/kreck:manage capability.'),
            'nopermissions'         => __('The token user lacks the required capability (local/kreck:manage) on the KRECK LMS.'),
            'requirecorrectaccess'  => __('The token user is not allowed to call this function. Add it to the KRECK LMS service as an authorised user.'),
            'invalidparameter'      => __('The LMS received the request but rejected its shape. Confirm the endpoint is the LMS base URL (KRECK adds /webservice/rest/server.php).'),
        ];
        if (isset($reasons[$errorcode])) {
            $message = $reasons[$errorcode];
        } elseif ($errorcode !== '' && $rawmessage !== '') {
            $message = sprintf(__('KRECK LMS error (%1$s): %2$s'), $errorcode, $rawmessage);
        } elseif ($rawmessage !== '') {
            $message = $rawmessage;
        } elseif (!is_array($response)) {
            $message = ($httpStatus === 404)
                ? __('The KRECK LMS endpoint returned 404. Check the LMS base URL — KRECK appends /webservice/rest/server.php.')
                : __('The KRECK LMS returned a non-JSON response (HTTP %d). The URL may not point at the LMS web-service endpoint.');
            if (strpos($message, '%d') !== false) {
                $message = sprintf($message, $httpStatus);
            }
        } else {
            $message = __('The KRECK LMS returned an unexpected heartbeat response.');
        }
        if ($token !== '') {
            $message = str_replace($token, '[REDACTED]', $message);
        }
        return [
            'ok' => false,
            'message' => $message,
            'httpStatus' => $httpStatus,
            'debug' => $debug,
        ];
    }

    $checkedAt = time();
    // Upsert so the timestamp records even when the setting row was never seeded.
    $exists = $connection2->prepare("SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='gibbonHeartbeatAt'");
    $exists->execute();
    if ($exists->fetchColumn()) {
        $connection2->prepare("UPDATE gibbonSetting SET value=:value WHERE scope='Kreck Bridge' AND name='gibbonHeartbeatAt'")
            ->execute(['value' => (string) $checkedAt]);
    } else {
        $connection2->prepare(
            "INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value)
             VALUES ('Kreck Bridge','gibbonHeartbeatAt','Last SMS-to-LMS Heartbeat','Timestamp of the last successful authenticated LMS connection check.',:value)"
        )->execute(['value' => (string) $checkedAt]);
    }
    $incomingQuery = $connection2->prepare(
        "SELECT value FROM gibbonSetting
         WHERE scope='Kreck Bridge' AND name='moodleHeartbeatAt'"
    );
    $incomingQuery->execute();
    return [
        'ok' => true,
        'message' => __('LMS connection successful.') . ' ' . sprintf(__('LMS version: %s.'), (string) ($response['moodleversion'] ?? '')),
        'checkedAt' => $checkedAt,
        'moodleHeartbeatAt' => (int) ($incomingQuery->fetchColumn() ?: 0),
        'durationMs' => $durationMs,
        'debug' => $debug,
    ];
};

// Ensure the SMS has a Shared Webhook Secret, generating a strong one if blank.
// Returns the secret. The SMS is the source of truth; it is pushed to the LMS by
// $pairWebhook so both sides share the same value with no manual copying.
$ensureWebhookSecret = function () use ($connection2, &$settings) {
    $secret = trim((string) ($settings['webhookSecret'] ?? ''));
    if ($secret !== '') {
        return $secret;
    }
    $secret = bin2hex(random_bytes(32)); // 64 hex chars; no whitespace, sanitiser-safe.
    try {
        $upd = $connection2->prepare("UPDATE gibbonSetting SET value=:v WHERE scope='Kreck Bridge' AND name='webhookSecret'");
        $upd->execute(['v' => $secret]);
        if ($upd->rowCount() === 0) {
            $ins = $connection2->prepare(
                "INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value)
                 VALUES ('Kreck Bridge','webhookSecret','Shared Webhook Secret','Shared HMAC secret for LMS-to-SMS webhook authentication.',:v)"
            );
            $ins->execute(['v' => $secret]);
        }
        $settings['webhookSecret'] = $secret;
    } catch (\Throwable $e) {
        KreckBridgeDiagnostics::reportCaught($e, 'generate webhook secret');
    }
    return $secret;
};

// Push this SMS's webhook secret (and its own webhook URL) to the LMS over the
// authenticated token channel, so the LMS signs LMS->SMS calls with a matching
// secret. The secret and token are redacted from any surfaced text.
$pairWebhook = function (array $settings) use ($ensureWebhookSecret, $session) {
    $token = trim((string) ($settings['moodleToken'] ?? ''));
    $moodleBase = rtrim((string) ($settings['moodleEndpoint'] ?? ''), '/');
    $urlparts = parse_url($moodleBase);
    if ($token === '' || !$urlparts || ($urlparts['scheme'] ?? '') !== 'https' || empty($urlparts['host'])) {
        return ['ok' => false, 'message' => __('Configure an HTTPS LMS endpoint and web-service token before pairing.')];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => __('PHP cURL is required to pair the webhook secret with the LMS.')];
    }
    $secret = $ensureWebhookSecret();
    if ($secret === '') {
        return ['ok' => false, 'message' => __('Could not establish a webhook secret on the SMS.')];
    }
    $smsWebhookUrl = rtrim((string) $session->get('absoluteURL'), '/') . '/modules/' . rawurlencode('Kreck Bridge') . '/kreck_webhook.php';
    $endpoint = preg_match('#/webservice/rest/server\.php$#', $moodleBase) ? $moodleBase : $moodleBase . '/webservice/rest/server.php';
    $redact = function ($s) use ($secret, $token) {
        $s = (string) $s;
        if ($secret !== '') { $s = str_replace($secret, '[REDACTED]', $s); }
        if ($token !== '') { $s = str_replace($token, '[REDACTED]', $s); }
        return $s;
    };
    $curl = curl_init($endpoint);
    if ($curl === false) {
        return ['ok' => false, 'message' => __('Could not initialise the pairing request to LMS.')];
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'wstoken' => $token,
            'wsfunction' => 'local_kreck_set_webhook_secret',
            'moodlewsrestformat' => 'json',
            'secret' => $secret,
            'webhookurl' => $smsWebhookUrl,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if ($body === false) {
        return ['ok' => false, 'message' => __('Pairing request failed: ') . $redact($curlError)];
    }
    $response = json_decode($body, true);
    if ($httpStatus >= 200 && $httpStatus < 300 && is_array($response) && ($response['status'] ?? '') === 'ok') {
        return [
            'ok' => true,
            'message' => __('the LMS now shares this SMS\'s webhook secret, so Moodle-to-Gibbon checks can authenticate.'),
            'urlset' => !empty($response['urlset']),
        ];
    }
    $errorcode = is_array($response) ? strtolower((string) ($response['errorcode'] ?? '')) : '';
    $rawmessage = is_array($response) ? (string) ($response['message'] ?? $response['exception'] ?? '') : '';
    $message = $rawmessage !== ''
        ? sprintf(__('the LMS rejected pairing (%1$s): %2$s'), $errorcode ?: (string) $httpStatus, $rawmessage)
        : sprintf(__('the LMS rejected pairing (HTTP %d).'), $httpStatus);
    return ['ok' => false, 'message' => $redact($message)];
};

$manifestAction = $_POST['manifest_action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $manifestAction === 'heartbeat') {
    $result = $checkMoodleConnection($settings);
    if (!empty($result['ok'])) {
        // On a healthy token connection, also pair the webhook secret so the
        // reverse (LMS->SMS) direction authenticates. Fold the outcome into the
        // visible message so the existing UI shows it with no JS change.
        $pair = $pairWebhook($settings);
        $result['pairing'] = $pair;
        // Pairing asks the LMS to send a confirming heartbeat back, which updates
        // 'moodleHeartbeatAt' on this SMS synchronously. Re-read it so the reverse
        // (LMS -> SMS) "received" line lights up from this one click.
        if (!empty($pair['ok'])) {
            try {
                $reread = $connection2->prepare("SELECT value FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='moodleHeartbeatAt'");
                $reread->execute();
                $result['moodleHeartbeatAt'] = (int) ($reread->fetchColumn() ?: 0);
            } catch (\Throwable $e) {
                // Leave the earlier value; non-fatal.
            }
        }
        $result['message'] = $result['message'] . ' '
            . ($pair['ok']
                ? __('Webhook pairing complete —') . ' ' . $pair['message']
                : __('Webhook pairing did not complete —') . ' ' . $pair['message']);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($manifestAction, ['preview', 'sync'], true)) {
    $selectedYear = $_POST['gibbonSchoolYearID'] ?? null;
    $limitClasses = isset($_POST['limitClasses']) ? (int) $_POST['limitClasses'] : 0;
    $existingCoursePolicy = (string) ($_POST['existingcoursepolicy'] ?? 'block');
    if (!in_array($existingCoursePolicy, ['block', 'merge'], true)) {
        $existingCoursePolicy = 'block';
    }
    $manifest = $builder->build([
        'gibbonSchoolYearID' => $selectedYear,
        'limitClasses' => $limitClasses,
    ]);
    $syncResult = $sendManifest($manifest, $settings, $manifestAction === 'preview', $existingCoursePolicy);
    $syncResult['action'] = $manifestAction;
}

// ---- Download branch: SMS links file (for the LMS "Import SMS links" page) --
if (($_GET['download'] ?? '') === 'links') {
    $manifest = $builder->build([
        'gibbonSchoolYearID' => $selectedYear,
        'limitClasses'       => $limitClasses,
    ]);
    $sy = $builder->schoolYear($selectedYear);
    $links = $manifest['links'] ?? ['courses' => [], 'students' => []];
    $payload = [
        'source'     => 'gibbon',
        'generated'  => date('c'),
        'schoolyear' => (string) ($sy['name'] ?? ''),
        'courses'    => $links['courses'] ?? [],
        'students'   => $links['students'] ?? [],
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $fname = 'kreck_sms_links_' . KreckManifestBuilder::slug($sy['name'] ?? 'export') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $json;
    exit;
}

// ---- Download branch: stream the manifest and exit --------------------------
if (($_GET['download'] ?? '') === 'json') {
    $manifest = $builder->build([
        'gibbonSchoolYearID' => $selectedYear,
        'limitClasses'       => $limitClasses,
    ]);
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $sy = $builder->schoolYear($selectedYear);
    $fname = 'kreck_manifest_' . KreckManifestBuilder::slug($sy['name'] ?? 'export') . '.json';

    // Optional: log the batch (best-effort; never block the download).
    try {
        $counts = [
            'students'   => count($manifest['students'] ?? []),
            'users'      => count($manifest['users'] ?? []),
            'cohorts'    => count($manifest['cohorts'] ?? []),
            'courses'    => count($manifest['courses'] ?? []),
            'enrolments' => count($manifest['enrolments'] ?? []),
        ];
        $ins = $connection2->prepare(
            "INSERT INTO kreckBridgeExport (gibbonSchoolYearID, gibbonPersonID, timestamp, counts, filename)
             VALUES (:syid, :pid, NOW(), :counts, :fname)"
        );
        $ins->execute([
            'syid'   => $sy['gibbonSchoolYearID'] ?? null,
            'pid'    => $session->get('gibbonPersonID') ?? null,
            'counts' => json_encode($counts),
            'fname'  => $fname,
        ]);
    } catch (\Throwable $e) {
        KreckBridgeDiagnostics::reportCaught($e, 'record export batch');
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $json;
    exit;
}

// ---- Page branch: readiness + options --------------------------------------
$page->breadcrumbs->add(__('Export to Kreck LMS'));

$readiness = $builder->readiness($selectedYear);
$years = $builder->schoolYears();
$c = $readiness['counts'] ?? [
    'students' => 0, 'staff' => 0, 'formGroups' => 0, 'classes' => 0, 'enrol' => 0,
    'scheduledSessions' => 0, 'assignmentPlans' => 0,
];

$self = $session->get('absoluteURL') . '/index.php?q=/modules/Kreck Bridge/kreck_export.php';

ob_start();
?>
<div class="kreck-export" style="max-width:860px">
    <p><?php echo __('Review the selected school-year mapping, then either download the manifest or send it to LMS. Existing matching courses can be merged without deleting or replacing existing LMS content.'); ?></p>

    <?php
    $gibbonHeartbeatAt = (int) ($settings['gibbonHeartbeatAt'] ?? 0);
    $moodleHeartbeatAt = (int) ($settings['moodleHeartbeatAt'] ?? 0);
    ?>
    <section style="border:1px solid #d5e5e3;border-radius:8px;padding:12px 16px;margin:12px 0 18px;background:#f7fbfa" aria-labelledby="kreck-connection-heading">
        <h3 id="kreck-connection-heading"><?php echo __('SMS ↔ LMS Connection'); ?></h3>
        <p id="kreck-gibbon-to-moodle" role="status" aria-live="polite">
            <?php echo __('SMS → LMS last successful check: '); ?>
            <?php echo $gibbonHeartbeatAt > 0 ? htmlspecialchars(date('Y-m-d H:i:s', $gibbonHeartbeatAt)) : __('Not checked yet'); ?>
        </p>
        <p id="kreck-moodle-to-gibbon" role="status" aria-live="polite">
            <?php echo __('LMS → SMS last successful check: '); ?>
            <?php echo $moodleHeartbeatAt > 0 ? htmlspecialchars(date('Y-m-d H:i:s', $moodleHeartbeatAt)) : __('Not received yet'); ?>
        </p>
        <button type="button" class="button" id="kreck-check-connection"><?php echo __('Check connection now'); ?></button>
        <span id="kreck-connection-result" role="status" aria-live="polite"></span>
        <details style="margin-top:8px">
            <summary><?php echo __('Safe connection diagnostics (credentials redacted)'); ?></summary>
            <pre id="kreck-connection-debug" style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo __('No connection check has run yet.'); ?></pre>
        </details>
        <div class="emphasis small"><?php echo __('Status refreshes every 60 seconds while this page is open.'); ?></div>
    </section>

    <div id="kreck-manifest-progress" role="status" aria-live="polite" hidden
         style="border:1px solid #b7d7d2;border-radius:8px;padding:14px;margin:12px 0;background:#f1faf8">
        <progress style="width:100%"></progress>
        <strong id="kreck-manifest-progress-title"><?php echo __('Working with LMS...'); ?></strong>
        <div id="kreck-manifest-progress-detail"><?php echo __('Please keep this page open while the server prepares and sends the request.'); ?></div>
    </div>

    <?php if ($syncResult !== null): ?>
        <?php if (!empty($syncResult['error'])): ?>
            <div class="warning" role="alert" style="margin-bottom:14px"><?php echo htmlspecialchars((string) $syncResult['error']); ?></div>
        <?php else: ?>
            <div class="<?php echo ($syncResult['status'] ?? '') === 'conflict' ? 'warning' : 'success'; ?>" role="status" style="margin-bottom:14px">
                <b><?php echo htmlspecialchars((string) ($syncResult['message'] ?? ($syncResult['status'] ?? 'Sync response'))); ?></b>
                <?php if (!empty($syncResult['batchid'])): ?>
                    <div><?php echo __('LMS batch'); ?>: <?php echo htmlspecialchars((string) $syncResult['batchid']); ?></div>
                <?php endif; ?>
                <?php if (!empty($syncResult['errors']) && is_array($syncResult['errors'])): ?>
                    <ul><?php foreach ($syncResult['errors'] as $error): ?><li><?php echo htmlspecialchars((string) $error); ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
                <?php if (!empty($syncResult['warnings']) && is_array($syncResult['warnings'])): ?>
                    <ul><?php foreach ($syncResult['warnings'] as $warning): ?><li><?php echo htmlspecialchars((string) $warning); ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>
            <?php
            $impactPlan = json_decode((string) ($syncResult['plan'] ?? ''), true);
            if (!is_array($impactPlan)) {
                $impactPlan = [];
            }
            $impactLabels = [
                'categories_create' => __('Categories to create'),
                'categories_exist' => __('Existing categories'),
                'users_create' => __('Staff accounts to create'),
                'users_exist' => __('Existing staff accounts'),
                'users_auto_create' => __('Teacher accounts to create'),
                'cohorts_create' => __('Cohorts to create'),
                'cohorts_exist' => __('Existing cohorts'),
                'courses' => __('New courses to create'),
                'courses_existing' => $existingCoursePolicy === 'merge'
                    ? __('Existing courses to merge')
                    : __('Conflicting existing courses'),
                'modules_total' => __('Course activities to create'),
                'modules_existing' => __('Missing activities to add to existing courses'),
                'calendar_events' => __('Timetable calendar events to create'),
                'calendar_events_existing' => __('Timetable sessions checked for existing courses'),
                'students_create' => __('Student accounts to create'),
                'students_exist' => __('Existing student accounts'),
                'enrolments' => __('Enrolments to apply'),
            ];
            $plannedAccounts = (int) ($impactPlan['users_create'] ?? 0)
                + (int) ($impactPlan['users_auto_create'] ?? 0)
                + (int) ($impactPlan['students_create'] ?? 0);
            $plannedCohorts = (int) ($impactPlan['cohorts_create'] ?? 0);
            $plannedCourses = (int) ($impactPlan['courses'] ?? 0);
            $plannedExistingCourses = (int) ($impactPlan['courses_existing'] ?? 0);
            $plannedEnrolments = (int) ($impactPlan['enrolments'] ?? 0);
            $confirmImpactMessage = sprintf(
                __('LMS preflight indicates %d new accounts, %d new cohorts, %d new courses, %d existing courses to merge and %d enrolments. Continue?'),
                $plannedAccounts,
                $plannedCohorts,
                $plannedCourses,
                $plannedExistingCourses,
                $plannedEnrolments
            );
            ?>
            <?php if (is_array($impactPlan)): ?>
                <h4><?php echo __('LMS preflight impact'); ?></h4>
                <table class="w-full colorOddEven" cellspacing="0" style="margin-bottom:14px">
                    <?php foreach ($impactLabels as $key => $label): ?>
                        <?php $value = $impactPlan[$key] ?? 0; ?>
                        <tr>
                            <td><b><?php echo htmlspecialchars($label); ?></b></td>
                            <td><?php echo is_array($value) ? count($value) : (int) $value; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
            <?php if (($syncResult['status'] ?? '') === 'validated'): ?>
                <form method="post" action="<?php echo htmlspecialchars($self); ?>" style="margin:12px 0 18px" data-kreck-progress="<?php echo htmlspecialchars(__('Preparing the manifest and synchronizing with LMS...')); ?>">
                    <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_export.php">
                    <input type="hidden" name="manifest_action" value="sync">
                    <input type="hidden" name="gibbonSchoolYearID" value="<?php echo htmlspecialchars((string) ($readiness['_syid'] ?? $selectedYear ?? '')); ?>">
                    <input type="hidden" name="limitClasses" value="<?php echo (int) $limitClasses; ?>">
                    <input type="hidden" name="existingcoursepolicy" value="<?php echo htmlspecialchars($existingCoursePolicy); ?>">
                    <input type="submit" value="<?php echo __('Confirm and sync this manifest'); ?>" class="button"
                        onclick="return confirm('<?php echo htmlspecialchars($confirmImpactMessage, ENT_QUOTES); ?>');">
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <h3><?php echo __('Readiness'); ?> — <?php echo htmlspecialchars($readiness['schoolYear'] ?? ''); ?></h3>
    <table class="w-full colorOddEven" cellspacing="0" style="margin-bottom:14px">
        <tr><td><b><?php echo __('Students'); ?></b></td><td><?php echo (int)$c['students']; ?></td>
            <td><b><?php echo __('Staff'); ?></b></td><td><?php echo (int)$c['staff']; ?></td></tr>
        <tr><td><b><?php echo __('Form Groups → Cohorts'); ?></b></td><td><?php echo (int)$c['formGroups']; ?></td>
            <td><b><?php echo __('Classes → Courses'); ?></b></td><td><?php echo (int)$c['classes']; ?></td></tr>
        <tr><td><b><?php echo __('Enrolments'); ?></b></td><td><?php echo (int)$c['enrol']; ?></td>
            <td><b><?php echo __('Dated timetable sessions'); ?></b></td><td><?php echo (int)$c['scheduledSessions']; ?></td></tr>
        <tr><td><b><?php echo __('Active LMS assignment plans'); ?></b></td><td colspan="3"><?php echo (int)$c['assignmentPlans']; ?></td></tr>
    </table>

    <?php if (!empty($readiness['warnings'])): ?>
        <div class="warning" style="margin-bottom:14px">
            <b><?php echo __('Data-quality notes:'); ?></b>
            <ul style="margin:6px 0 0 18px">
                <?php foreach ($readiness['warnings'] as $w): ?>
                    <li><?php echo htmlspecialchars($w); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="success" style="margin-bottom:14px"><?php echo __('No data gaps detected — this year is ready to export.'); ?></div>
    <?php endif; ?>

    <form method="get" action="<?php echo htmlspecialchars($self); ?>" style="border-top:1px solid #ddd;padding-top:14px">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_export.php">
        <table class="smallIntBorder w-full" cellspacing="0">
            <tr class="break"><td colspan="2"><h4 style="margin:0"><?php echo __('Export options'); ?></h4></td></tr>
            <tr>
                <td style="width:38%"><b><?php echo __('School Year'); ?></b></td>
                <td>
                    <select name="gibbonSchoolYearID" onchange="this.form.download.value='';this.form.submit();">
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo $y['gibbonSchoolYearID']; ?>"
                                <?php echo ((string)$y['gibbonSchoolYearID'] === (string)($readiness['_syid'] ?? $selectedYear) || ($selectedYear === null && $y['status'] === 'Current')) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($y['name']) . ($y['status'] === 'Current' ? ' ('.__('Current').')' : ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="emphasis small" style="display:block"><?php echo __('Changing this refreshes the readiness summary.'); ?></span>
                </td>
            </tr>
            <tr>
                <td><b><?php echo __('Limit classes (testing)'); ?></b></td>
                <td>
                    <input type="number" name="limitClasses" min="0" value="<?php echo $limitClasses ?: ''; ?>" placeholder="<?php echo __('0 = all classes'); ?>" style="width:120px">
                    <span class="emphasis small" style="display:block"><?php echo __('Cap the number of courses for a quick dry-run. Leave blank/0 to export everything.'); ?></span>
                </td>
            </tr>
        </table>
        <div class="linkTop" style="margin-top:12px">
            <input type="hidden" name="download" value="json">
            <input type="submit" value="<?php echo __('Download Manifest (.json)'); ?>" class="button" style="font-weight:bold">
            <a class="button" style="margin-left:8px" href="<?php echo htmlspecialchars($self . '&download=links&gibbonSchoolYearID=' . urlencode((string) ($selectedYear ?? ''))); ?>"><?php echo __('Download SMS links (.json)'); ?></a>
            <span class="emphasis small" style="display:block;margin-top:6px"><?php echo __('The SMS links file is imported on the LMS under KRECK → "Import SMS links into KRECK LMS".'); ?></span>
        </div>
    </form>

    <form method="post" action="<?php echo htmlspecialchars($self); ?>" style="border-top:1px solid #ddd;padding-top:14px;margin-top:14px" data-kreck-progress="<?php echo htmlspecialchars(__('Preparing the manifest and requesting LMS preflight...')); ?>">
        <input type="hidden" name="q" value="/modules/Kreck Bridge/kreck_export.php">
        <input type="hidden" name="manifest_action" value="preview">
        <input type="hidden" name="gibbonSchoolYearID" value="<?php echo htmlspecialchars((string) ($readiness['_syid'] ?? $selectedYear ?? '')); ?>">
        <input type="hidden" name="limitClasses" value="<?php echo (int) $limitClasses; ?>">
        <p>
            <label for="existingcoursepolicy"><b><?php echo __('Existing LMS courses'); ?></b></label><br>
            <select id="existingcoursepolicy" name="existingcoursepolicy">
                <option value="block" <?php echo $existingCoursePolicy === 'block' ? 'selected' : ''; ?>><?php echo __('Stop if a course shortname already exists'); ?></option>
                <option value="merge" <?php echo $existingCoursePolicy === 'merge' ? 'selected' : ''; ?>><?php echo __('Merge into matching courses without deleting existing content'); ?></option>
            </select>
            <span class="emphasis small" style="display:block"><?php echo __('Merge matches exact course shortnames, preserves existing course settings and matching activities, adds missing activities and timetable events, and adds missing enrolments. Existing activities are never edited or deleted.'); ?></span>
        </p>
        <p><?php echo __('Preview the LMS create/merge counts and warnings first. You can then explicitly confirm synchronization. LMS revalidates at commit time.'); ?></p>
        <input type="submit" value="<?php echo __('Preview LMS impact'); ?>" class="button">
    </form>
</div>
<script>
(function () {
    var statusNode = document.getElementById('kreck-connection-result');
    var debugNode = document.getElementById('kreck-connection-debug');
    var checkButton = document.getElementById('kreck-check-connection');
    var gibbonToMoodle = document.getElementById('kreck-gibbon-to-moodle');
    var moodleToGibbon = document.getElementById('kreck-moodle-to-gibbon');
    var progressBox = document.getElementById('kreck-manifest-progress');
    var progressTitle = document.getElementById('kreck-manifest-progress-title');
    var progressDetail = document.getElementById('kreck-manifest-progress-detail');
    if (!statusNode || !checkButton) return;

    var checking = false;
    var labels = {
        checking: <?php echo json_encode(__('Checking SMS → LMS...'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        gibbonToMoodle: <?php echo json_encode(__('SMS → LMS last successful check: '), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        moodleToGibbon: <?php echo json_encode(__('LMS → SMS last successful check: '), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        failed: <?php echo json_encode(__('SMS → LMS check failed: '), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        timeout: <?php echo json_encode(__('Browser timed out waiting for the SMS server.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        elapsed: <?php echo json_encode(__('Elapsed seconds: '), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        working: <?php echo json_encode(__('Working with LMS...'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        elapsed: <?php echo json_encode(__('The server is preparing or processing the request. Elapsed time: '), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        indeterminate: <?php echo json_encode(__('The bar is indeterminate because LMS returns a result only when processing finishes.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
    };
    var heartbeat = function () {
        if (checking) return;
        checking = true;
        checkButton.disabled = true;
        statusNode.textContent = labels.checking;
        var startedAt = Date.now();
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timeout = controller ? window.setTimeout(function () { controller.abort(); }, 20000) : null;
        var elapsed = window.setInterval(function () {
            statusNode.textContent = labels.checking + ' ' + labels.elapsed
                + Math.floor((Date.now() - startedAt) / 1000);
        }, 1000);
        var renderDebug = function (debug) {
            if (debugNode) debugNode.textContent = JSON.stringify(debug || {}, null, 2);
        };
        var body = new FormData();
        body.append('q', '/modules/Kreck Bridge/kreck_export.php');
        body.append('manifest_action', 'heartbeat');
        if (!controller) {
            statusNode.textContent = labels.failed + 'AbortController is not supported by this browser.';
            statusNode.className = 'warning';
            renderDebug({stage: 'browser', result: 'failed', detail: 'AbortController is unavailable.'});
            checking = false;
            checkButton.disabled = false;
            window.clearInterval(elapsed);
            return;
        }
        fetch(<?php echo json_encode($self, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            signal: controller.signal
        }).then(function (response) {
            return response.text().then(function (text) {
                var result;
                try {
                    result = JSON.parse(text);
                } catch (error) {
                    var invalid = new Error('HTTP ' + response.status + '; non-JSON SMS response.');
                    invalid.debug = {stage: 'SMS page response', httpStatus: response.status, contentType: response.headers.get('Content-Type')};
                    throw invalid;
                }
                if (result.debug) renderDebug(result.debug);
                if (!response.ok) {
                    var failed = new Error('HTTP ' + response.status + ': ' + (result.message || result.error || 'SMS request failed.'));
                    failed.debug = result.debug || {stage: 'SMS page response', httpStatus: response.status};
                    throw failed;
                }
                return result;
            });
        }).then(function (result) {
            if (result.debug) renderDebug(result.debug);
            if (!result.ok) {
                statusNode.textContent = labels.failed + (result.message || 'LMS did not confirm the heartbeat.');
                statusNode.className = 'warning';
                return;
            }
            statusNode.textContent = result.message + ' (' + result.durationMs + ' ms)';
            statusNode.className = 'success';
            gibbonToMoodle.textContent = labels.gibbonToMoodle + new Date(result.checkedAt * 1000).toLocaleString();
            if (moodleToGibbon && result.moodleHeartbeatAt) {
                moodleToGibbon.textContent = labels.moodleToGibbon + new Date(result.moodleHeartbeatAt * 1000).toLocaleString();
            }
        }).catch(function (error) {
            statusNode.textContent = labels.failed + (error.name === 'AbortError' ? labels.timeout : error.message);
            statusNode.className = 'warning';
            renderDebug(error.debug || {
                stage: 'Browser request to SMS page',
                result: 'failed',
                errorName: error.name,
                error: error.message,
                elapsedMs: Date.now() - startedAt
            });
        }).finally(function () {
            if (timeout !== null) window.clearTimeout(timeout);
            window.clearInterval(elapsed);
            checking = false;
            checkButton.disabled = false;
        });
    };
    checkButton.addEventListener('click', heartbeat);
    heartbeat();
    window.setInterval(heartbeat, 60000);

    document.querySelectorAll('form[data-kreck-progress]').forEach(function (form) {
        form.addEventListener('submit', function () {
            if (!progressBox) return;
            progressBox.hidden = false;
            progressTitle.textContent = form.getAttribute('data-kreck-progress') || labels.working;
            var startedAt = Date.now();
            var updateElapsed = function () {
                var elapsed = Math.floor((Date.now() - startedAt) / 1000);
                progressDetail.textContent = labels.elapsed + elapsed + 's. ' + labels.indeterminate;
            };
            updateElapsed();
            window.setInterval(updateElapsed, 1000);
        });
    });
})();
</script>
<?php
echo ob_get_clean();
