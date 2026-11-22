<?php
/*
Kreck Bridge — Diagnostics.

Makes every KRECK page fail loudly and clearly instead of dying with a blank
page or SMS's generic "the Gibbons have escaped" message. When anything
breaks it shows, on the page, WHERE and WHY: the exception/fatal class, the
message, the file and line, a short trace and a reference id — to logged-in
staff (the only people who can reach these admin pages). Anonymous visitors see
only the reference. Every event is also written to the PHP error log and, best
effort, to <module>/kreck-diagnostics.log so a reference can be looked up later.

Covers all three failure shapes:
  - thrown exceptions a page catches itself      -> reportCaught()
  - uncaught exceptions / Errors                 -> handleException()
  - true fatals (memory, undefined symbol, etc.) -> handleShutdown()
*/

final class KreckBridgeDiagnostics
{
    private static $requestID;
    private static $page;
    private static $moduleDir;
    private static $previousErrorHandler;
    private static $previousExceptionHandler;
    private static $registered = false;
    private static $reported = false;

    public static function register(string $file): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        self::$requestID = bin2hex(random_bytes(8));
        self::$page = basename($file);
        self::$moduleDir = dirname($file);
        self::$previousErrorHandler = set_error_handler([self::class, 'handleError']);
        self::$previousExceptionHandler = set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        if ($severity & (E_WARNING | E_USER_WARNING | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
            self::writeLog('php_warning', [
                'message' => $message,
                'file' => $file,
                'line' => $line,
            ]);
        }

        if (is_callable(self::$previousErrorHandler)) {
            return (bool) call_user_func(self::$previousErrorHandler, $severity, $message, $file, $line);
        }

        return false;
    }

    public static function handleException(\Throwable $exception): void
    {
        self::$reported = true;
        $reference = self::reportCaught($exception, 'uncaught');

        // Best effort: queue the summary into SMS's own error area, in case the
        // themed page is still being rendered when this fires.
        $page = $GLOBALS['page'] ?? null;
        if (is_object($page) && method_exists($page, 'addError')) {
            $page->addError(self::summaryLine('uncaught', $exception->getMessage(),
                get_class($exception), $exception->getFile(), (int) $exception->getLine(), $reference));
        }

        // Hand off to a previously registered handler if there is one.
        if (is_callable(self::$previousExceptionHandler)) {
            call_user_func(self::$previousExceptionHandler, $exception);
            return;
        }

        // Otherwise render our own clear panel (never a blank 500).
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo self::panelHtml('uncaught', get_class($exception), $exception->getMessage(),
            $exception->getFile(), (int) $exception->getLine(),
            self::traceLines($exception->getTrace()), $reference);
    }

    /**
     * Fatal errors (out of memory, max execution time, a genuine E_ERROR such as
     * an undefined function at call time) are not catchable by try/catch or the
     * exception handler — only here, at shutdown, via error_get_last().
     */
    public static function handleShutdown(): void
    {
        $last = error_get_last();
        if ($last === null || self::$reported) {
            return;
        }

        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (!((int) $last['type'] & $fatal)) {
            return;
        }

        self::$reported = true;
        self::writeLog('fatal', [
            'type' => $last['type'],
            'message' => $last['message'],
            'file' => $last['file'],
            'line' => $last['line'],
        ]);

        if (!headers_sent()) {
            http_response_code(500);
        }
        echo self::panelHtml('fatal error', 'PHP fatal error', (string) $last['message'],
            (string) $last['file'], (int) $last['line'], [], self::$requestID);
    }

    public static function reportCaught(\Throwable $exception, string $stage): string
    {
        $trace = [];
        foreach (array_slice($exception->getTrace(), 0, 20) as $frame) {
            $trace[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
            ];
        }

        self::writeLog('exception', [
            'stage' => $stage,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $trace,
        ]);

        return self::$requestID ?? 'n/a';
    }

    /**
     * A one-line, human summary suitable for SMS's $page->addError(). Staff
     * get the where+why; anonymous visitors get only the reference.
     */
    public static function summaryLine(string $stage, string $message, string $class, string $file, int $line, string $reference): string
    {
        if (!self::viewerIsStaff()) {
            return 'KRECK encountered an error (' . self::$page . '). Reference ' . $reference . '.';
        }

        return 'KRECK — ' . self::$page . ' / ' . $stage . ': ' . $class
            . ': ' . $message . ' at ' . self::shortPath($file) . ':' . $line
            . ' · reference ' . $reference;
    }

    // ------------------------------------------------------------------ internals

    private static function viewerIsStaff(): bool
    {
        $session = $GLOBALS['session'] ?? null;
        if (!is_object($session) || !method_exists($session, 'get')) {
            return false;
        }
        $personID = $session->get('gibbonPersonID');
        return is_numeric($personID) && (int) $personID > 0;
    }

    private static function shortPath(string $file): string
    {
        // Trim everything above the SMS web root so paths stay readable.
        $marker = DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR;
        $pos = strpos($file, $marker);
        return $pos !== false ? substr($file, $pos + 1) : $file;
    }

    private static function traceLines(array $frames): array
    {
        $out = [];
        foreach (array_slice($frames, 0, 12) as $frame) {
            $fn = $frame['function'] ?? '';
            $where = isset($frame['file']) ? self::shortPath((string) $frame['file']) . ':' . ($frame['line'] ?? '?') : '[internal]';
            $out[] = $fn . '  —  ' . $where;
        }
        return $out;
    }

    private static function panelHtml(string $stage, string $class, string $message, string $file, int $line, array $trace, string $reference): string
    {
        $e = static function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };

        $wrap = 'max-width:960px;margin:14px auto;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
            . 'border:1px solid #ffa39e;border-radius:10px;background:#fff6f5;color:#2b2b2b;overflow:hidden;';
        $head = 'background:#b42318;color:#fff;padding:10px 16px;font-weight:700;font-size:15px;';
        $body = 'padding:14px 16px;font-size:14px;line-height:1.5;';

        if (!self::viewerIsStaff()) {
            return '<div style="' . $wrap . '"><div style="' . $head . '">KRECK page error</div>'
                . '<div style="' . $body . '">This page could not be completed. Please tell your administrator and quote reference <b>'
                . $e($reference) . '</b>.</div></div>';
        }

        $rows = '<div><b>Where it stopped:</b> ' . $e($stage) . ' &middot; page <code>' . $e(self::$page) . '</code></div>'
            . '<div style="margin-top:6px"><b>' . $e($class) . ':</b> ' . $e($message) . '</div>'
            . '<div style="margin-top:6px"><b>Location:</b> <code>' . $e(self::shortPath($file)) . ':' . (int) $line . '</code></div>';

        if (!empty($trace)) {
            $rows .= '<div style="margin-top:10px"><b>Call path:</b><pre style="margin:6px 0 0;padding:10px;background:#fff;border:1px solid #f0ccc8;'
                . 'border-radius:6px;overflow:auto;font-size:12px;line-height:1.45;max-height:260px">';
            foreach ($trace as $t) {
                $rows .= $e($t) . "\n";
            }
            $rows .= '</pre></div>';
        }

        $rows .= '<div style="margin-top:10px;color:#6b6b6b;font-size:12px">Reference <b>' . $e($reference)
            . '</b> &middot; PHP ' . $e(PHP_VERSION) . ' &middot; logged to the server error log'
            . (self::$moduleDir ? ' and <code>' . $e(basename((string) self::$moduleDir)) . '/kreck-diagnostics.log</code>' : '') . '.</div>';

        return '<div style="' . $wrap . '"><div style="' . $head . '">KRECK could not finish this page &mdash; here is exactly where and why</div>'
            . '<div style="' . $body . '">' . $rows . '</div></div>';
    }

    private static function writeLog(string $event, array $details): void
    {
        $session = $GLOBALS['session'] ?? null;
        $personID = is_object($session) && method_exists($session, 'get')
            ? $session->get('gibbonPersonID')
            : null;

        $entry = array_merge([
            'request_id' => self::$requestID,
            'page' => self::$page,
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
            'person_id' => is_numeric($personID) ? (int) $personID : null,
            'time' => date('c'),
            'event' => $event,
        ], $details);

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $line = '[KRECK-DIAGNOSTIC] ' . ($json === false ? 'Unable to encode diagnostic event.' : $json);

        error_log($line);

        // Best effort: also keep a findable file next to the module, so a
        // reference can be looked up without server error-log access.
        if (self::$moduleDir) {
            $file = self::$moduleDir . DIRECTORY_SEPARATOR . 'kreck-diagnostics.log';
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
