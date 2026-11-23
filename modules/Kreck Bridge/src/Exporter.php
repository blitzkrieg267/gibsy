<?php
/**
 * Kreck Bridge — LMS sync (Export) engine.
 *
 * Submits a KRECK manifest to the LMS's local_kreck_sync_manifest web service.
 * Extracted from kreck_export.php so the same sync can run from the Export page
 * AND automatically after the demo seeder (so one click stands up the SMS demo
 * and pushes it to the LMS). Self-contained: settings + manifest in, result out.
 */

class KreckExporter
{
    /**
     * Submit a manifest for dry-run or provisioning and return the LMS result.
     *
     * @param array  $manifest             The manifest from KreckManifestBuilder::build().
     * @param array  $settings             Module settings (needs moodleEndpoint https + moodleToken).
     * @param bool   $dryrun               True = preview only (no changes on the LMS).
     * @param string $existingCoursePolicy 'block' or 'merge'.
     * @return array ['error'=>string] on failure, else the decoded response (+ 'httpStatus').
     */
    public static function sync(array $manifest, array $settings, bool $dryrun, string $existingCoursePolicy): array
    {
        $token = trim((string) ($settings['moodleToken'] ?? ''));
        $moodleBase = rtrim((string) ($settings['moodleEndpoint'] ?? ''), '/');
        $urlparts = parse_url($moodleBase);

        if ($token === '' || !$urlparts || ($urlparts['scheme'] ?? '') !== 'https' || empty($urlparts['host'])) {
            return ['error' => __('Configure an HTTPS LMS endpoint and restricted web-service token before syncing.')];
        } elseif (isset($manifest['_error'])) {
            return ['error' => (string) $manifest['_error']];
        } elseif (!function_exists('curl_init')) {
            return ['error' => __('PHP cURL is required for live LMS synchronization.')];
        }

        $stableManifest = $manifest;
        unset($stableManifest['batch_metadata']['generated'], $stableManifest['batch_metadata']['manifest_id']);
        $stableJson = json_encode($stableManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($stableJson === false || $manifestJson === false) {
            return ['error' => __('The manifest could not be encoded as JSON.')];
        }

        $requestId = hash('sha256', $existingCoursePolicy === 'merge'
            ? $stableJson . '|existingcoursepolicy=merge'
            : $stableJson);
        $endpoint = preg_match('#/webservice/rest/server\.php$#', $moodleBase)
            ? $moodleBase : $moodleBase . '/webservice/rest/server.php';
        $curl = curl_init($endpoint);
        if ($curl === false) {
            return ['error' => __('Could not initialize the HTTPS request to LMS.')];
        }
        $configured = curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'wstoken' => $token,
                'wsfunction' => 'local_kreck_sync_manifest',
                'moodlewsrestformat' => 'json',
                'requestid' => $requestId,
                'manifestjson' => $manifestJson,
                'dryrun' => $dryrun ? 1 : 0,
                'existingcoursepolicy' => $existingCoursePolicy,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        if (!$configured) {
            curl_close($curl);
            return ['error' => __('Could not configure the HTTPS request to LMS.')];
        }

        $body = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            return ['error' => __('LMS request failed: ') . $curlError];
        }

        $response = json_decode($body, true);
        if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($response)) {
            return ['error' => __('LMS returned an invalid response.')];
        }
        if (!empty($response['exception'])) {
            return ['error' => (string) ($response['message'] ?? $response['exception'])];
        }

        $response['httpStatus'] = $httpStatus;
        return $response;
    }
}
