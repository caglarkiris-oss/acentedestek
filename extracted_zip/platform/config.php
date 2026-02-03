<?php
// /public_html/platform/config.php
return [
    'app' => [
        'name'  => 'Acentedestek',
        'env'   => 'prod',
        'debug' => true,
    ],

    /* =========================
       URL / PATH
    ========================= */
    'url' => [
        'base' => 'https://acentedestek.com',
        'path' => '/platform',
    ],

    'brand' => [
        'logo' => '/platform/assets/logo.png',
    ],

    'security' => [
        /* CSRF */
        'csrf_key' => 'csrf_token',

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
        'host'    => 'localhost',
        'name'    => 'acentede_acentedestekdb',
        'user'    => 'acentede_acentedestek',
        'pass'    => '){iw$RGuT4gpL&LN',
        'charset' => 'utf8mb4',
        'port'    => 3306,
    ],

    /* =========================
       MAIL (DEĞİŞMEDİ)
    ========================= */
    'mail' => [
        'host'       => 'mail.dijicenter.com',
        'port'       => 465,
        'username'   => 'info@dijicenter.com',
        'password'   => 'UtXjyc4V59[7~B5i',
        'encryption' => 'ssl',
        'from'       => 'info@dijicenter.com',
        'from_name'  => 'Acentedestek',
    ],

    'features' => [
        'force_https' => true,
    ],
];
