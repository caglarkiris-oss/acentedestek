<?php
// /platform/core/error.php
// Centralized error + exception logging for the whole app.
// Safe to include multiple times.

declare(strict_types=1);

if (!function_exists('app_error_log_path')) {
  function app_error_log_path(): string {
    // Keep logs inside /platform/logs by default
    $base = __DIR__ . '/../logs';
    if (!is_dir($base)) { @mkdir($base, 0755, true); }
    return $base . '/error.log';
  }
}

if (!function_exists('app_log_line')) {
  function app_log_line(string $level, string $message, array $context = []): void {
    $ts = date('Y-m-d H:i:s');
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // session context (if any)
    $uid = $_SESSION['user_id'] ?? null;
    $aid = $_SESSION['agency_id'] ?? null;
    $role = $_SESSION['role'] ?? null;

    $ctx = array_merge([
      'uri' => $uri,
      'ip'  => $ip,
      'ua'  => $ua,
      'user_id' => $uid,
      'agency_id' => $aid,
      'role' => $role,
    ], $context);

    // compact json context
    $ctxJson = '';
    try { $ctxJson = json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    catch (\Throwable $e) { $ctxJson = '{"ctx":"json_failed"}'; }

    $line = sprintf("[%s] [%s] %s | %s\n", $ts, $level, $message, $ctxJson);

    $path = app_error_log_path();
    $ok = @error_log($line, 3, $path);

    // Fallback to PHP error log if file write fails
    if (!$ok) { @error_log($line); }
  }
}

if (!function_exists('init_error_logging')) {
  function init_error_logging(): void {
    static $inited = false;
    if ($inited) return;
    $inited = true;

    // Convert PHP errors into log lines (keep native behavior too)
    set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
      // respect @ operator
      if (!(error_reporting() & $errno)) { return false; }

      $map = [
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_STRICT => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
      ];
      $level = $map[$errno] ?? ('E_' . (string)$errno);

      app_log_line($level, $errstr, [
        'file' => $errfile,
        'line' => $errline,
      ]);

      // return false to allow PHP's internal handler as well (keeps existing behavior)
      return false;
    });

    set_exception_handler(function(\Throwable $e) {
      app_log_line('EXCEPTION', $e->getMessage(), [
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => substr($e->getTraceAsString(), 0, 8000),
      ]);

      // fail safe: show generic error in production, but do not expose details
      if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
      }
      echo "Bir hata oluştu. (500)\n";
      exit;
    });

    register_shutdown_function(function() {
      $err = error_get_last();
      if (!$err) return;

      $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
      if (in_array($err['type'] ?? 0, $fatalTypes, true)) {
        app_log_line('FATAL', (string)($err['message'] ?? 'fatal'), [
          'file' => $err['file'] ?? '',
          'line' => $err['line'] ?? 0,
          'type' => $err['type'] ?? 0,
        ]);
      }
    });
  }
}
