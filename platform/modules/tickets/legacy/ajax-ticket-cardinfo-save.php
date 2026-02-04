<?php
// /public_html/platform/ajax-ticket-cardinfo-save.php

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../auth.php';

header('Content-Type: application/json; charset=utf-8');

$debug = (bool) config('app.debug', false);

if (function_exists('require_login')) {
  require_login();
} else {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'Yetkisiz']); exit; }
}

$conn = db();
if (!$conn) { echo json_encode(['ok'=>false,'error'=>'DB bağlantısı yok']); exit; }
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$ALLOWED = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) { echo json_encode(['ok'=>false,'error'=>'Yetkisiz']); exit; }

function clean($s){ return trim((string)$s); }
function digits_only($s){ return preg_replace('/\D+/', '', (string)$s); }

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$holder   = clean($_POST['card_holder'] ?? '');
$numberIn = clean($_POST['card_number'] ?? '');
$mm       = clean($_POST['exp_month'] ?? '');
$yy       = clean($_POST['exp_year'] ?? '');
$cvvIn    = clean($_POST['cvv'] ?? '');

if ($ticketId <= 0) { echo json_encode(['ok'=>false,'error'=>'Geçersiz ticket']); exit; }
if ($holder === '' || $numberIn === '' || $mm === '' || $yy === '' || $cvvIn === '') {
  echo json_encode(['ok'=>false,'error'=>'Tüm kart alanları zorunlu']); exit;
}

/* ===== Sunucu-side validasyon ===== */
$digits = digits_only($numberIn);
if (strlen($digits) !== 16) { echo json_encode(['ok'=>false,'error'=>'Kart numarası 16 hane olmalı']); exit; }

// 1234 5678 ... formatına çevir
$number = trim(implode(' ', str_split($digits, 4)));

$mmN = (int)$mm;
if ($mmN < 1 || $mmN > 12) { echo json_encode(['ok'=>false,'error'=>'Son kullanım ayı hatalı']); exit; }

$yyDigits = digits_only($yy);
if (strlen($yyDigits) === 2) $yyDigits = '20' . $yyDigits;
if (strlen($yyDigits) !== 4) { echo json_encode(['ok'=>false,'error'=>'Son kullanım yılı hatalı']); exit; }

$cvv = digits_only($cvvIn);
if (strlen($cvv) < 3 || strlen($cvv) > 4) { echo json_encode(['ok'=>false,'error'=>'CVV 3-4 hane olmalı']); exit; }

try {
  // Ticket erişim + sadece açan taraf yazabilir + wf kontrol
  $st = $conn->prepare("
    SELECT id, status, workflow_status, created_by_agency_id, target_agency_id
    FROM tickets
    WHERE id=? AND (created_by_agency_id=? OR target_agency_id=?)
    LIMIT 1
  ");
  if(!$st) throw new Exception("Prepare fail: ".$conn->error);

  $st->bind_param("iii", $ticketId, $agencyId, $agencyId);
  $st->execute();
  $st->bind_result($id, $status, $wf, $createdByAgencyId, $targetAgencyId);

  if(!$st->fetch()){
    $st->close();
    echo json_encode(['ok'=>false,'error'=>'Ticket yok/erişim yok']);
    exit;
  }
  $st->close();

  if ((string)$status === 'closed') { echo json_encode(['ok'=>false,'error'=>'Ticket kapalı']); exit; }
  if ((int)$createdByAgencyId !== (int)$agencyId) { echo json_encode(['ok'=>false,'error'=>'Kart bilgisini sadece ticketi açan taraf girebilir.']); exit; }

  $WF = strtoupper(trim((string)$wf));

  // ✅ Kart kaydı artık "Teklif girişi yapıldı" adımında yapılacak (ve geriye uyum için iki adımı da kabul edelim)
  $WF_ALLOW = ['POLICE_GIRISI_YAPILDI', 'KART_BILGILERI_BEKLENIYOR'];
  if (!in_array($WF, $WF_ALLOW, true)) {
    echo json_encode(['ok'=>false,'error'=>'Bu adımda kart bilgisi girilemez.']);
    exit;
  }

  $conn->begin_transaction();

  // tablo alanlarına uygun değerler
  $mainAgencyId = (int)$targetAgencyId;      // ana acente
  $taliAgencyId = (int)$createdByAgencyId;   // ticketi açan taraf
  $last4 = substr($digits, -4);

  // ✅ Upsert
  $sql = "
    INSERT INTO ticket_card_info
      (ticket_id, main_agency_id, tali_agency_id, card_holder, cardholder_name, card_number, card_no_last4, exp_month, exp_year, cvv, created_by_user_id, created_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      main_agency_id=VALUES(main_agency_id),
      tali_agency_id=VALUES(tali_agency_id),
      card_holder=VALUES(card_holder),
      cardholder_name=VALUES(cardholder_name),
      card_number=VALUES(card_number),
      card_no_last4=VALUES(card_no_last4),
      exp_month=VALUES(exp_month),
      exp_year=VALUES(exp_year),
      cvv=VALUES(cvv),
      created_by_user_id=VALUES(created_by_user_id),
      updated_at=NOW()
  ";

  $ins = $conn->prepare($sql);
  if(!$ins) throw new Exception("Prepare fail card: ".$conn->error);

  $ins->bind_param(
    "iiisssssssi",
    $ticketId,
    $mainAgencyId,
    $taliAgencyId,
    $holder,
    $holder,
    $number,
    $last4,
    $mmN,
    $yyDigits,
    $cvv,
    $userId
  );

  if(!$ins->execute()){
    $err = $ins->error;
    $ins->close();
    throw new Exception("Execute fail: ".$err);
  }
  $ins->close();

  // ✅ Ticket'i güncelle (karşı taraf "yeni" görsün diye)
  $tu = $conn->prepare("UPDATE tickets SET updated_at=NOW() WHERE id=? LIMIT 1");
  if ($tu) {
    $tu->bind_param("i", $ticketId);
    $tu->execute();
    $tu->close();
  }

  // ✅ Gönderene "seen" (kendinde yeni gibi görünmesin)
  $seen = $conn->prepare("
    INSERT INTO ticket_user_state (ticket_id, user_id, last_seen_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_seen_at=NOW()
  ");
  if ($seen) {
    $seen->bind_param("ii", $ticketId, $userId);
    $seen->execute();
    $seen->close();
  }

  $conn->commit();

  echo json_encode(['ok'=>true]);
  exit;

} catch (Throwable $e) {
  if ($conn) { try { $conn->rollback(); } catch(Throwable $x){} }
  error_log("ajax-ticket-cardinfo-save.php EX: ".$e->getMessage());
  echo json_encode(['ok'=>false,'error'=> $debug ? $e->getMessage() : 'Sunucu hatası' ]);
  exit;
}
