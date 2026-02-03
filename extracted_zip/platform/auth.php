<?php
// /public_html/platform/auth.php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

ensure_session();

/** login kontrolü */
if (empty($_SESSION['user_id'])) {
    header('Location: ' . base_url('login.php'));
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    session_unset();
    session_destroy();
    header('Location: ' . base_url('login.php'));
    exit;
}

/**
 * ✅ Kullanıcı aktiflik kontrolü (GÜVENLİ):
 * - users tablosunda is_active alanı YOKSA asla sistemi bozmaz.
 * - varsa 0 ise logout eder.
 */
function ensure_user_active(int $userId): void {
    $conn = db();

    // Kolon var mı kontrol et
    $colExists = false;
    $chk = mysqli_prepare($conn, "SHOW COLUMNS FROM users LIKE 'is_active'");
    if ($chk) {
        mysqli_stmt_execute($chk);
        $r = stmt_fetch_one($chk);
        mysqli_stmt_close($chk);
        $colExists = (bool)$r;
    }

    // kolon yoksa sessiz geç
    if (!$colExists) return;

    // kolon varsa kontrol et
    $st = mysqli_prepare($conn, "SELECT is_active FROM users WHERE id=? LIMIT 1");
    if (!$st) return;

    mysqli_stmt_bind_param($st, "i", $userId);
    mysqli_stmt_execute($st);
    $row = stmt_fetch_one($st);
    mysqli_stmt_close($st);

    if (!$row || (int)($row['is_active'] ?? 1) !== 1) {
        session_unset();
        session_destroy();
        header('Location: ' . base_url('login.php') . '?disabled=1');
        exit;
    }
}

/**
 * ✅ İlk girişte şifre değiştirme zorunluluğu
 * - Session’da 1 görünse bile DB’den doğrular (güvenlik)
 * - Zorunluysa, sadece force-password-change.php ve logout.php erişilebilir
 */
function ensure_password_changed_if_required(int $userId): void {
    $current = basename((string)($_SERVER['PHP_SELF'] ?? ''));

    // Bu sayfalara her durumda izin ver (aksi döngü olur)
    $allowed = [
        'force-password-change.php',
        'logout.php',
    ];

    // Bu iki sayfa harici her şeyde kontrol uygulayacağız
    if (in_array($current, $allowed, true)) return;

    $conn = db();

    // Kolon var mı? (migration eksikse sistemi bozmayalım)
    $colExists = false;
    $chk = mysqli_prepare($conn, "SHOW COLUMNS FROM users LIKE 'must_change_password'");
    if ($chk) {
        mysqli_stmt_execute($chk);
        $r = stmt_fetch_one($chk);
        mysqli_stmt_close($chk);
        $colExists = (bool)$r;
    }
    if (!$colExists) return; // kolon yoksa geç (eski sistem)

    // DB’den kesin doğrula
    $st = mysqli_prepare($conn, "SELECT COALESCE(must_change_password,0) AS must_change_password FROM users WHERE id=? LIMIT 1");
    if (!$st) return;

    mysqli_stmt_bind_param($st, "i", $userId);
    mysqli_stmt_execute($st);
    $row = stmt_fetch_one($st);
    mysqli_stmt_close($st);

    $must = (int)($row['must_change_password'] ?? 0);

    // session’ı da güncel tut
    $_SESSION['must_change_password'] = $must;

    if ($must === 1) {
        header('Location: ' . base_url('force-password-change.php'));
        exit;
    }
}

/** Kontroller */
ensure_user_active($userId);
ensure_password_changed_if_required($userId);
