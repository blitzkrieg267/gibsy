<?php
require_once __DIR__ . '/src/Diagnostics.php';
KreckBridgeDiagnostics::register(__FILE__);

/*
 * Signed LMS-to-SMS webhook receiver.
 *
 * LMS change events are stored for staff review only; this endpoint never
 * writes to SMS's people, course, enrolment, or timetable records.
 */

require_once __DIR__ . '/../../gibbon.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(405, ['error' => 'POST required.']);
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > 65536) {
    $respond(413, ['error' => 'Webhook body is empty or exceeds the size limit.']);
}

$timestamp = (string) ($_SERVER['HTTP_X_KRECK_TIMESTAMP'] ?? '');
$signature = (string) ($_SERVER['HTTP_X_KRECK_SIGNATURE'] ?? '');
$headerEventId = (string) ($_SERVER['HTTP_X_KRECK_EVENT_ID'] ?? '');
if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
    $respond(401, ['error' => 'Webhook timestamp is invalid or expired.']);
}

$secretQuery = $connection2->prepare(
    "SELECT value FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='webhookSecret'"
);
$secretQuery->execute();
$secret = (string) ($secretQuery->fetchColumn() ?: '');
if ($secret === '') {
    $respond(503, ['error' => 'Webhook authentication is not configured.']);
}

$expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
if ($signature === '' || !hash_equals($expected, $signature)) {
    $respond(401, ['error' => 'Webhook signature is invalid.']);
}

$event = json_decode($raw, true);
if (!is_array($event) || json_last_error() !== JSON_ERROR_NONE
        || ($event['schema_version'] ?? '') !== '1.0'
        || ($event['source'] ?? '') !== 'moodle') {
    $respond(400, ['error' => 'Webhook payload is not valid JSON for the KRECK contract.']);
}

if (($event['event_type'] ?? '') === 'heartbeat') {
    if (!preg_match('/^[a-f0-9]{32}$/', (string) ($event['nonce'] ?? ''))) {
        $respond(400, ['error' => 'Heartbeat nonce is invalid.']);
    }
    // Upsert, not UPDATE-only: if the setting row was never seeded (older
    // installs), a plain UPDATE matches zero rows and silently records nothing,
    // leaving the SMS stuck on "LMS -> SMS: Not received yet" even though the
    // heartbeat authenticated. Create the row when it is missing.
    $receivedAt = time();
    $exists = $connection2->prepare(
        "SELECT 1 FROM gibbonSetting WHERE scope='Kreck Bridge' AND name='moodleHeartbeatAt'"
    );
    $exists->execute();
    if ($exists->fetchColumn()) {
        $connection2->prepare(
            "UPDATE gibbonSetting SET value=:value WHERE scope='Kreck Bridge' AND name='moodleHeartbeatAt'"
        )->execute(['value' => (string) $receivedAt]);
    } else {
        $connection2->prepare(
            "INSERT INTO gibbonSetting (scope,name,nameDisplay,description,value)
             VALUES ('Kreck Bridge','moodleHeartbeatAt','Last LMS-to-SMS Heartbeat','Timestamp of the last successful signed LMS connection check.',:value)"
        )->execute(['value' => (string) $receivedAt]);
    }
    $respond(200, [
        'status' => 'ok',
        'service' => 'kreck_bridge',
        'receivedAt' => $receivedAt,
    ]);
}

if (!preg_match('/^[a-f0-9]{32}$/', $headerEventId)
        || !hash_equals($headerEventId, (string) ($event['event_id'] ?? ''))
        || empty($event['event_type'])
        || !in_array($event['entity_type'] ?? '', ['course', 'user', 'activity', 'enrolment', 'grade'], true)
        || !isset($event['entity_id'])
        || !in_array($event['action'] ?? '', ['created', 'updated', 'deleted'], true)) {
    $respond(400, ['error' => 'Webhook event does not match the supported contract.']);
}

$insert = $connection2->prepare(
    "INSERT INTO kreckSyncEvent
        (eventID, eventType, entityType, entityID, action, payload, receivedAt, reviewStatus)
     VALUES
        (:eventID, :eventType, :entityType, :entityID, :action, :payload, NOW(), 'Pending')"
);
try {
    $insert->execute([
        'eventID' => $headerEventId,
        'eventType' => substr((string) $event['event_type'], 0, 80),
        'entityType' => substr((string) $event['entity_type'], 0, 32),
        'entityID' => substr((string) $event['entity_id'], 0, 64),
        'action' => substr((string) $event['action'], 0, 20),
        'payload' => $raw,
    ]);
} catch (\PDOException $exception) {
    if ((string) $exception->getCode() !== '23000') {
        throw $exception;
    }
    $existing = $connection2->prepare('SELECT kreckSyncEventID FROM kreckSyncEvent WHERE eventID=:eventID');
    $existing->execute(['eventID' => $headerEventId]);
    if (!$existing->fetchColumn()) {
        throw $exception;
    }
    $respond(200, ['status' => 'duplicate', 'event_id' => $headerEventId]);
}

$respond(202, ['status' => 'pending_review', 'event_id' => $headerEventId]);
