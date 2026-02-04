<?php
// /public_html/platform/mutabakat/ajax-send-period-csv.php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_mutabakat_ajax_send_csv_error.log');
error_reporting(E_ALL);

require_once __DIR__.'/../../auth.php';
require_once __DIR__.'/../../helpers.php';
require_once __DIR__.'/../../db.php';

$csrfEnforce = (bool) config('security.csrf_enforce', true);
if ($csrfEnforce && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
}
/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'message'=>'Giriş gerekli'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

$conn = db();
if (!$conn) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'DB yok'], JSON_UNESCAPED_UNICODE);
  exit;
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$isMain = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
$isTali = ($role === 'TALI_ACENTE_YETKILISI');

if (!$isMain && !$isTali) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'message'=>'Yetkisiz'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'message'=>'Method'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* CSRF */

$period = (string)($_POST['period'] ?? '');
$taliId = (int)($_POST['tali_id'] ?? 0);

if (!preg_match('/^\d{4}\-\d{2}$/', $period)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>'Geçersiz dönem'], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($taliId <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>'Geçersiz tali'], JSON_UNESCAPED_UNICODE);
  exit;
}

/*
  ✅ KURAL:
  - Main: child olan tali için OPEN yapabilir (SUPERADMIN hariç child kontrol)
  - Tali: sadece kendi için OPEN yapabilir
*/

$mainAgencyId = 0;

try {
  if ($isMain) {
    if ($role !== 'SUPERADMIN') {
      $st = $conn->prepare("SELECT id FROM agencies WHERE id=? AND parent_id=? LIMIT 1");
      if (!$st) throw new Exception($conn->error);
      $st->bind_param("ii", $taliId, $agencyId);
      $st->execute();
      $okChild = (bool)$st->get_result()->fetch_assoc();
      $st->close();

      if (!$okChild) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'message'=>'Bu tali size bağlı değil'], JSON_UNESCAPED_UNICODE);
        exit;
      }
      $mainAgencyId = $agencyId;
    } else {
      // SUPERADMIN: mainAgencyId'yi talinin parent'ından alalım (mantıklı kayıt)
      $st = $conn->prepare("SELECT parent_id FROM agencies WHERE id=? LIMIT 1");
      if (!$st) throw new Exception($conn->error);
      $st->bind_param("i", $taliId);
      $st->execute();
      $r = $st->get_result()->fetch_assoc();
      $st->close();
      $mainAgencyId = (int)($r['parent_id'] ?? 0);
      if ($mainAgencyId <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'message'=>'Ana acente bulunamadı (parent_id boş)'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
  } else {
    // Tali: sadece kendisi için
    if ($taliId !== $agencyId) {
      http_response_code(403);
      echo json_encode(['ok'=>false,'message'=>'Sadece kendi mutabakatını gönderebilirsin'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $st = $conn->prepare("SELECT parent_id FROM agencies WHERE id=? LIMIT 1");
    if (!$st) throw new Exception($conn->error);
    $st->bind_param("i", $agencyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $mainAgencyId = (int)($row['parent_id'] ?? 0);
    if ($mainAgencyId <= 0) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'message'=>'Ana acente bulunamadı'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  // ✅ Upsert: OPEN
  $st0 = $conn->prepare("
    SELECT id
    FROM mutabakat_periods
    WHERE main_agency_id=? AND assigned_agency_id=? AND period=?
    LIMIT 1
  ");
  if (!$st0) throw new Exception($conn->error);
  $st0->bind_param("iis", $mainAgencyId, $taliId, $period);
  $st0->execute();
  $ex = $st0->get_result()->fetch_assoc();
  $st0->close();

  if ($ex) {
    $pid = (int)$ex['id'];
    $stU = $conn->prepare("
      UPDATE mutabakat_periods
      SET status='OPEN',
          closed_at=NULL,
          closed_by=NULL
      WHERE id=?
      LIMIT 1
    ");
    if (!$stU) throw new Exception($conn->error);
    $stU->bind_param("i", $pid);
    $stU->execute();
    $stU->close();
  } else {
    $stI = $conn->prepare("
      INSERT INTO mutabakat_periods (main_agency_id, assigned_agency_id, period, status, closed_at, closed_by, created_at)
      VALUES (?, ?, ?, 'OPEN', NULL, NULL, NOW())
    ");
    if (!$stI) throw new Exception($conn->error);
    $stI->bind_param("iis", $mainAgencyId, $taliId, $period);
    $stI->execute();
    $stI->close();
  }

  echo json_encode([
    'ok' => true,
    'status' => 'OPEN',
    'period' => $period,
    'tali_id' => $taliId,
    'main_agency_id' => $mainAgencyId
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log("ajax-send-period-csv.php err: ".$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'Sunucu hatası'], JSON_UNESCAPED_UNICODE);
}
