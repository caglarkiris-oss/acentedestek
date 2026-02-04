<?php
// /public_html/platform/db.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    $host    = (string) config('db.host', '127.0.0.1');
    $name    = (string) config('db.name', '');
    $user    = (string) config('db.user', '');
    $pass    = (string) config('db.pass', '');
    $port    = (int)    config('db.port', 3306);
    $charset = (string) config('db.charset', 'utf8mb4');

    // ✅ cPanel'de "localhost" socket demektir; socket yolu uyuşmayınca "No such file..." verir.
    // TCP ile bağlanmak için otomatik 127.0.0.1'e çeviriyoruz.
    $h = trim($host);
    if ($h === '' || strtolower($h) === 'localhost') {
        $h = '127.0.0.1';
    }

    mysqli_report(MYSQLI_REPORT_OFF);

    // ✅ Procedural connect -> mysqli objesi döner (başarılıysa)
    $conn = mysqli_connect($h, $user, $pass, $name, $port);

    if (!$conn) {
        http_response_code(500);

        $err = mysqli_connect_error();
        error_log("DB CONNECT FAIL host={$h} port={$port} db={$name} user={$user} err={$err}");

        if (config('app.debug', false)) {
            echo 'DB bağlantı hatası: ' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8');
        } else {
            echo 'Sistem geçici olarak kullanılamıyor.';
        }
        exit;
    }

    if (!mysqli_set_charset($conn, $charset)) {
        error_log("DB CHARSET FAIL charset={$charset} err=" . mysqli_error($conn));
    }

    // Güvenli varsayılanlar
    mysqli_query($conn, "SET sql_mode = 'STRICT_TRANS_TABLES'");
    mysqli_query($conn, "SET time_zone = '+03:00'");

    return $conn;
}

$GLOBALS['conn'] = db();
