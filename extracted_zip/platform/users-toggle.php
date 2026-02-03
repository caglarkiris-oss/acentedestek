

<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/* Login */
if (function_exists('require_login')) {
  require_login();
} elseif (function_exists('require_auth')) {
  require_auth();
} else {
  if (!isset($_SESSION['user_id'])) {
    header("Location: " . base_url("login.php"));
    exit;
  }
}

if (!isset($conn) || !$conn) {
  http_response_code(500);
  exit("DB bağlantısı bulunamadı.");
}

$role = (string)($_SESSION['role'] ?? '');
$allowed = ['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI'];
if (!in_array($role, $allowed, true)) {
  http_response_code(403);
  exit("Bu işlem için yetkiniz yok.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit("Method not allowed.");
}

/* CSRF */
$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  http_response_code(419);
  exit("Oturum doğrulama hatası.");
}

$currentUserId   = (int)($_SESSION['user_id'] ?? 0);
$currentAgencyId = (int)($_SESSION['agency_id'] ?? 0);

$id = (int)($_POST['id'] ?? 0);
$to = (int)($_POST['to'] ?? -1);
if ($id <= 0 || !in_array($to, [0,1], true)) {
  http_response_code(400);
  exit("Geçersiz istek.");
}

// kendini pasife çekme
if ($id === $currentUserId) {
  http_response_code(400);
  exit("Kendi hesabınızın durumunu değiştiremezsiniz.");
}

/* target user çek */
$st = $conn->prepare("SELECT id, agency_id FROM users WHERE id=? LIMIT 1");
if (!$st) { http_response_code(500); exit("Prepare hata: ".$conn->error); }
$st->bind_param("i", $id);
$st->execute();
$st->bind_result($uid, $uAgencyId);
$found = $st->fetch();
$st->close();

if (!$found) {
  http_response_code(404);
  exit("Kullanıcı bulunamadı.");
}

$uAgencyId = (int)$uAgencyId;

/* Scope check */
$okScope = false;

if ($role === 'SUPERADMIN') {
  $okScope = true;
} elseif ($role === 'TALI_ACENTE_YETKILISI') {
  // sadece kendi agency
  $okScope = ($uAgencyId === $currentAgencyId);
} elseif ($role === 'ACENTE_YETKILISI') {
  // kendi agency ya da kendi talileri
  if ($uAgencyId === $currentAgencyId) {
    $okScope = true;
  } else {
    $st2 = $conn->prepare("SELECT id FROM agencies WHERE id=? AND parent_id=? LIMIT 1");
    if (!$st2) { http_response_code(500); exit("Prepare hata: ".$conn->error); }
    $st2->bind_param("ii", $uAgencyId, $currentAgencyId);
    $st2->execute();
    $st2->bind_result($aid);
    $okScope = (bool)$st2->fetch();
    $st2->close();
  }
}

if (!$okScope) {
  http_response_code(403);
  exit("Bu kullanıcı üzerinde işlem yapamazsınız.");
}

/* update */
$up = $conn->prepare("UPDATE users SET is_active=? WHERE id=? LIMIT 1");
if (!$up) { http_response_code(500); exit("Prepare hata: ".$conn->error); }
$up->bind_param("ii", $to, $id);
$up->execute();
$up->close();

header("Location: " . base_url("users.php?toggled=1"));
exit;
