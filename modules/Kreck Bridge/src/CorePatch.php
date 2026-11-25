<?php
/**
 * Kreck Bridge — managed core patches.
 *
 * Gibbon's Home dashboard only lets a module add a TAB beside the Planner tab; it has no hook
 * for "directly after the timetable". To place KRECK Home there we make one small, clearly
 * marked edit to src/UI/Dashboard/StaffDashboard.php. A Gibbon upgrade replaces that file and
 * drops the edit, so this class keeps the patch manageable:
 *
 *   status()  — applied | pending (can be applied) | drifted (Gibbon changed the surrounding code)
 *               | unwritable | missing (target file not found)
 *   apply()   — idempotent; backs the original up once; refuses to write code that does not parse
 *   revert()  — restores the original text
 *   ensure()  — apply when pending (used when an administrator opens a Kreck page)
 *
 * Every edit is wrapped in KRECK-PATCH markers and is defensive: a failure inside the patched
 * code can never break the dashboard (try/catch, and the hook file is optional).
 */
class KreckCorePatch
{
    const MARK = 'KRECK-PATCH';

    /** id => ['file' => path under the Gibbon root, 'edits' => [[search, replace], ...]] */
    public static function patches(): array
    {
        $block = "        // KRECK-PATCH-BEGIN home-panel — managed by the Kreck Bridge module (src/CorePatch.php); re-apply from KRECK Home after a Gibbon upgrade\n"
            . "        \$kreckHome = '';\n"
            . "        if (is_file(\$kreckHook = \$this->session->get('absolutePath').'/modules/Kreck Bridge/src/HomeHook.php')) {\n"
            . "            try { include_once \$kreckHook; \$kreckHome = kreck_home_hook(\$guid, \$connection2, \$this->session); } catch (\\Throwable \$e) { \$kreckHome = ''; }\n"
            . "        }\n"
            . "        // KRECK-PATCH-END home-panel\n\n";

        $tabsSearch = "        // TABS\n        \$tabs = [];\n";
        $plannerSearch = "        if (!empty(\$planner) || !empty(\$timetable)) {\n"
            . "            \$tabs['Planner'] = [\n"
            . "                'label'   => __('Planner'),\n"
            . "                'content' => \$planner.\$timetable,\n"
            . "                'icon'    => 'book-open',\n"
            . "            ];\n"
            . "        }\n";
        $plannerReplace = "        if (!empty(\$planner) || !empty(\$timetable)) {\n"
            . "            \$tabs['Planner'] = [\n"
            . "                'label'   => __('Planner'),\n"
            . "                'content' => \$planner.\$timetable.\$kreckHome, // KRECK-PATCH home-panel: Kreck Home directly after the timetable\n"
            . "                'icon'    => 'book-open',\n"
            . "            ];\n"
            . "        } elseif (\$kreckHome !== '') { // KRECK-PATCH home-panel: staff without Planner/Timetable still get Kreck Home\n"
            . "            \$tabs['KRECK'] = ['label' => 'KRECK', 'content' => \$kreckHome, 'icon' => 'home'];\n"
            . "        }\n";

        return [
            'home-panel' => [
                'label' => 'KRECK Home on the main Home page (after the timetable)',
                'file'  => 'src/UI/Dashboard/StaffDashboard.php',
                'edits' => [
                    [$tabsSearch, $block . $tabsSearch],
                    [$plannerSearch, $plannerReplace],
                ],
            ],
        ];
    }

    /** The Gibbon root (this file lives in modules/Kreck Bridge/src). */
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,array{id:string,label:string,file:string,state:string,message:string}> */
    public static function status(): array
    {
        $out = [];
        foreach (self::patches() as $id => $p) {
            $path = self::root() . '/' . $p['file'];
            $row = ['id' => $id, 'label' => $p['label'], 'file' => $p['file'], 'state' => 'missing', 'message' => ''];
            if (!is_file($path)) {
                $row['message'] = 'Target file not found.';
                $out[$id] = $row;
                continue;
            }
            $code = (string) file_get_contents($path);
            $applied = 0; $pending = 0;
            foreach ($p['edits'] as [$search, $replace]) {
                if (strpos($code, $replace) !== false) { $applied++; }
                elseif (substr_count($code, $search) === 1) { $pending++; }
            }
            $n = count($p['edits']);
            if ($applied === $n) {
                $row['state'] = 'applied';
            } elseif ($applied + $pending === $n) {
                $row['state'] = is_writable($path) ? 'pending' : 'unwritable';
                $row['message'] = $row['state'] === 'unwritable' ? 'The web server cannot write this file. Run the CLI as the file owner, or make it writable.' : '';
            } else {
                $row['state'] = 'drifted';
                $row['message'] = 'This Gibbon version changed the code around the patch point; it needs a manual review.';
            }
            $out[$id] = $row;
        }
        return $out;
    }

    /** @return array{ok:bool,message:string} */
    public static function apply(string $id = 'home-panel'): array
    {
        return self::transform($id, true);
    }

    /** @return array{ok:bool,message:string} */
    public static function revert(string $id = 'home-panel'): array
    {
        return self::transform($id, false);
    }

    /** Apply every patch that is pending. Returns the resulting status. */
    public static function ensure(): array
    {
        foreach (self::status() as $id => $row) {
            if ($row['state'] === 'pending') {
                self::apply($id);
            }
        }
        return self::status();
    }

    private static function transform(string $id, bool $apply): array
    {
        $patches = self::patches();
        if (!isset($patches[$id])) {
            return ['ok' => false, 'message' => 'Unknown patch.'];
        }
        $p = $patches[$id];
        $path = self::root() . '/' . $p['file'];
        if (!is_file($path)) {
            return ['ok' => false, 'message' => 'Target file not found.'];
        }
        if (!is_writable($path)) {
            return ['ok' => false, 'message' => 'The web server cannot write ' . $p['file'] . '.'];
        }
        $code = (string) file_get_contents($path);
        $orig = $code;
        foreach ($p['edits'] as [$search, $replace]) {
            $from = $apply ? $search : $replace;
            $to   = $apply ? $replace : $search;
            if ($apply && strpos($code, $replace) !== false) { continue; }          // already applied
            if (!$apply && strpos($code, $replace) === false) { continue; }         // already reverted
            if (substr_count($code, $from) !== 1) {
                return ['ok' => false, 'message' => 'Could not find the expected code in ' . $p['file'] . ' (Gibbon may have changed it).'];
            }
            $code = str_replace($from, $to, $code);
        }
        if ($code === $orig) {
            return ['ok' => true, 'message' => $apply ? 'Already applied.' : 'Already reverted.'];
        }
        // Never write PHP that does not parse.
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Refused: the patched file would not parse (' . $e->getMessage() . ').'];
        }
        $backup = $path . '.kreck-orig';
        if ($apply && !is_file($backup)) {
            @file_put_contents($backup, $orig);
        }
        $tmp = $path . '.kreck-tmp';
        if (file_put_contents($tmp, $code) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'Could not write ' . $p['file'] . '.'];
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        return ['ok' => true, 'message' => $apply ? 'Patch applied.' : 'Patch removed.'];
    }
}
