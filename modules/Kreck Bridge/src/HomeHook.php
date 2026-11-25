<?php
/**
 * Kreck Bridge — entry point called from the main Home dashboard.
 *
 * Gibbon's StaffDashboard calls kreck_home_hook() through the small marked patch
 * managed by KreckCorePatch (see src/CorePatch.php). It returns the KRECK Home panel
 * as an HTML string (or '' when the user may not see it); it never echoes.
 */

if (!function_exists('kreck_home_hook')) {
    /**
     * @param string $guid
     * @param \PDO   $connection2
     * @param mixed  $session
     * @return string
     */
    function kreck_home_hook($guid, $connection2, $session): string
    {
        require_once __DIR__ . '/HomePanel.php';
        return KreckHomePanel::render($guid, $connection2, $session, ['context' => 'dashboard']);
    }
}
