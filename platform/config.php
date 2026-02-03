<?php
// /platform/config.php
// Mutabakat V2 - Konfigürasyon

return [
    'app' => [
        'name'  => 'Mutabakat Sistemi',
        'env'   => 'development',
        'debug' => true,
    ],

    'url' => [
        'base' => '',
        'path' => '/platform',
    ],

    'brand' => [
        'logo' => '/platform/assets/logo.png',
    ],

    'security' => [
        'csrf_key'        => 'csrf_token',
        'cookie_secure'   => false,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],

    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'name'    => getenv('DB_NAME') ?: 'mutabakat_db',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        'port'    => (int)(getenv('DB_PORT') ?: 3306),
    ],
];
