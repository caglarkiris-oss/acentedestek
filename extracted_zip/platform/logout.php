<?php
// /public_html/platform/logout.php

// Olası output buffer varsa temizle
if (ob_get_length()) {
    ob_clean();
}

require_once __DIR__ . '/helpers.php';

ensure_session();

// Session temizle
$_SESSION = [];

// Cookie sil
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'] ?? '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => $params['secure'] ?? false,
        'httponly' => $params['httponly'] ?? true,
        'samesite' => (string)config('security.cookie_samesite', 'Lax'),
    ]);
}

// Session kapat
session_destroy();

// 🔥 GARANTİLİ REDIRECT
header('Location: ' . base_url('login.php'), true, 303);
exit;