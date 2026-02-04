<?php
// /public_html/platform/config.php
// NOTE: Secrets should be provided via environment variables when possible.
// Fallback values below keep the current installation working.

if (!function_exists('env_str')) {
function env_str(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return (string)$v;
}
}
if (!function_exists('env_int')) {
function env_int(string $key, ?int $default = null): ?int {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return (int)$v;
}
}
if (!function_exists('env_bool')) {
function env_bool(string $key, bool $default = false): bool {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    $v = strtolower(trim((string)$v));
    return in_array($v, ['1','true','yes','on'], true);
}
}

return [
'app' => [
        'name'  => 'Acentedestek',
        'env'   => env_str('APP_ENV', 'prod'),
        'debug' => env_bool('APP_DEBUG', true),
    ],

    /* =========================
       URL / PATH
    ========================= */
    'url' => [
        'base' => env_str('APP_URL', 'https://acentedestek.com'),
        'path' => '/platform',
    ],

    'brand' => [
        'logo' => '/platform/assets/logo.png',
    ],

    'security' => [
        /* CSRF */
        'csrf_key' => 'csrf_token',
        // Enforce CSRF on write endpoints (can be disabled via env for emergency rollback)
        'csrf_enforce' => env_bool('CSRF_ENFORCE', true),

        /* Cookie */
        'cookie_secure'   => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',

        /* Cloudflare Turnstile (opsiyonel) */
        'turnstile_sitekey' => 'BURAYA_SITE_KEY_YAZ',
        'turnstile_secret'  => 'BURAYA_SECRET_KEY_YAZ',
    ],

    /* =========================
       DATABASE (CANLI)
       ⚠️ SADECE PASS'I SEN YAZ
    ========================= */
    'db' => [
        'host'    => env_str('DB_HOST', 'localhost'),
        'name'    => env_str('DB_NAME', 'acentede_acentedestekdb'),
        'user'    => env_str('DB_USER', 'acentede_acentedestek'),
        'pass'    => env_str('DB_PASS', 'test123'),
        'charset' => 'utf8mb4',
        'port'    => env_int('DB_PORT', 3306),
    ],

    /* =========================
       MAIL (DEĞİŞMEDİ)
    ========================= */
    'mail' => [
        'host'       => env_str('MAIL_HOST', 'mail.dijicenter.com'),
        'port'       => env_int('MAIL_PORT', 465),
        'username'   => env_str('MAIL_USERNAME', 'info@dijicenter.com'),
        'password'   => env_str('MAIL_PASSWORD', 'UtXjyc4V59[7~B5i'),
        'encryption' => env_str('MAIL_ENCRYPTION', 'ssl'),
        'from'       => env_str('MAIL_FROM', 'info@dijicenter.com'),
        'from_name'  => env_str('MAIL_FROM_NAME', 'Acentedestek'),
    ],

    'features' => [
        'force_https' => true,
    ],
];
