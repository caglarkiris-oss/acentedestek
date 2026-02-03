<?php
// /public_html/platform/helpers.php

/* =========================
   CONFIG
========================= */
function config(string $key = null, $default = null) {
    static $cfg = null;

    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        $loaded = file_exists($path) ? require $path : [];
        $cfg = is_array($loaded) ? $loaded : [];
    }

    if ($key === null || $key === '') return $cfg;

    $segments = explode('.', $key);
    $value = $cfg;

    foreach ($segments as $seg) {
        if (is_array($value) && array_key_exists($seg, $value)) {
            $value = $value[$seg];
        } else {
            return $default;
        }
    }
    return $value;
}

/* =========================
   SESSION
========================= */
function ensure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $httponly = (bool) config('security.cookie_httponly', true);
    $samesite = (string) config('security.cookie_samesite', 'Lax');

    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);

    $secureCfg = (bool) config('security.cookie_secure', true);
    $secure    = $isHttps ? $secureCfg : false;

    // Header basıldıysa session cookie ayarı yapamayız; yine de session başlatmayı dene
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ]);
    }

    session_start();
}

/* =========================
   URL
========================= */
function base_url(string $path = ''): string {
    $base = rtrim((string)config('url.base', ''), '/');
    $root = '/' . trim((string)config('url.path', ''), '/'); // /platform

    $p = trim($path);

    // boşsa kök
    if ($p === '' || $p === '/') return $base . $root . '/';

    // tam URL verilirse dokunma
    if (preg_match('#^https?://#i', $p)) return $p;

    // baştaki slash normalize
    if ($p[0] !== '/') $p = '/' . $p;

    // /platform ile gelirse bir daha ekleme
    if ($root !== '/' && (strpos($p, $root . '/') === 0 || $p === $root)) {
        return $base . $p;
    }

    return $base . $root . $p;
}

/* =========================
   ESCAPE (XSS)
========================= */
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* =========================
   REDIRECT (yardımcı)
========================= */
function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}

/* =========================
   AUTH
========================= */
function auth_role(): string {
    return (string)($_SESSION['role'] ?? '');
}

function require_roles(array $roles): void {
    ensure_session();
    $r = auth_role();
    if ($r === '' || !in_array($r, $roles, true)) {
        http_response_code(403);
        echo "Bu sayfaya erişim yetkiniz yok.";
        exit;
    }
}

function require_login(): void {
    ensure_session();
    if (empty($_SESSION['user_id'])) {
        redirect(base_url("login.php"));
    }
}

function require_auth(): void { require_login(); }

/* =========================
   CSRF
========================= */
function csrf_token(): string {
    ensure_session();
    $key = (string) config('security.csrf_key', 'csrf_token');
    if (empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}

function csrf_field(): string {
    $key = (string) config('security.csrf_key', 'csrf_token');
    return '<input type="hidden" name="'.$key.'" value="'.e(csrf_token()).'">';
}

function csrf_verify(): void {
    ensure_session();
    $key  = (string) config('security.csrf_key', 'csrf_token');
    $sent = (string)($_POST[$key] ?? '');
    $sess = (string)($_SESSION[$key] ?? '');
    if ($sent === '' || $sess === '' || !hash_equals($sess, $sent)) {
        http_response_code(419);
        exit('CSRF doğrulaması başarısız.');
    }
}

/* =========================
   MYSQLI STMT FETCH (mysqlnd bağımsız)
========================= */
function stmt_fetch_one(mysqli_stmt $stmt): ?array {
    $meta = $stmt->result_metadata();
    if (!$meta) return null;

    $fields = [];
    while ($f = $meta->fetch_field()) $fields[] = $f->name;
    $meta->free();

    $row = [];
    $bind = [];
    foreach ($fields as $name) {
        $row[$name] = null;
        $bind[] = &$row[$name];
    }

    call_user_func_array([$stmt, 'bind_result'], $bind);

    if ($stmt->fetch()) {
        $out = [];
        foreach ($row as $k => $v) $out[$k] = $v;
        return $out;
    }
    return null;
}

function stmt_fetch_all(mysqli_stmt $stmt): array {
    $meta = $stmt->result_metadata();
    if (!$meta) return [];

    $fields = [];
    while ($f = $meta->fetch_field()) $fields[] = $f->name;
    $meta->free();

    $row = [];
    $bind = [];
    foreach ($fields as $name) {
        $row[$name] = null;
        $bind[] = &$row[$name];
    }

    call_user_func_array([$stmt, 'bind_result'], $bind);

    $rows = [];
    while ($stmt->fetch()) {
        $out = [];
        foreach ($row as $k => $v) $out[$k] = $v;
        $rows[] = $out;
    }
    return $rows;
}

/* =========================
   MAIL (SMTP) - PHPMailer
   (şimdilik dokunmuyoruz)
========================= */
function send_mail(string $to, string $subject, string $html): bool
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        $ex = __DIR__ . '/phpmailer/Exception.php';
        $sm = __DIR__ . '/phpmailer/SMTP.php';
        $ph = __DIR__ . '/phpmailer/PHPMailer.php';
        if (!file_exists($ex) || !file_exists($sm) || !file_exists($ph)) {
            error_log("MAIL: PHPMailer dosyaları bulunamadı.");
            return false;
        }
        require_once $ex; require_once $sm; require_once $ph;
    }

    $host = (string) config('mail.host', '');
    $port = (int)    config('mail.port', 465);
    $user = (string) config('mail.username', '');
    $pass = (string) config('mail.password', '');
    $enc  = strtolower((string) config('mail.encryption', 'ssl'));
    $from = (string) config('mail.from', $user);
    $fromName = (string) config('mail.from_name', (string)config('app.name', 'App'));

    if ($host === '' || $user === '' || $pass === '') return false;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host     = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;

        $mail->SMTPSecure = ($enc === 'tls')
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;

        $mail->Port = $port;

        $mail->CharSet  = 'UTF-8';
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags($html);

        return (bool)$mail->send();
    } catch (\Throwable $e) {
        error_log("MAIL EXCEPTION: " . $e->getMessage());
        return false;
    }
}

/* =========================
   HEADER BADGE (TEMP)
========================= */
if (!function_exists('ticket_badge_count')) {
    function ticket_badge_count($conn, int $userId, string $role, int $agencyId): int {
        return 0;
    }
}
