<?php
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

header('Content-Type: application/json; charset=utf-8');

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok'=>false,'error'=>'Yetkisiz']);
    exit;
  }
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$ALLOWED = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) {
  echo json_encode(['ok'=>false,'error'=>'Yetkisiz']);
  exit;
}

$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

/* INPUT */
$ticketId      = (int)($_POST['ticket_id'] ?? 0);
$message       = trim((string)($_POST['message'] ?? ''));
$redirectQ     = (string)($_POST['redirect'] ?? '');
$closeReasonIn = trim((string)($_POST['close_reason'] ?? ''));

if ($ticketId <= 0) { echo json_encode(['ok'=>false,'error'=>'Geçersiz ticket']); exit; }
if ($message === '') { echo json_encode(['ok'=>false,'error'=>'Mesaj boş olamaz']); exit; }

/* Ticket bilgisi + erişim */
$createdByUserId   = 0;
$createdByAgencyId = 0;
$targetAgencyId    = 0;
$status            = '';
$firstRespAt       = null;

$st = $conn->prepare("
  SELECT created_by_user_id, created_by_agency_id, target_agency_id, status, first_response_at
  FROM tickets
  WHERE id = ?
  LIMIT 1
");
$st->bind_param("i", $ticketId);
$st->execute();
$st->bind_result($createdByUserId, $createdByAgencyId, $targetAgencyId, $status, $firstRespAt);

if (!$st->fetch()) {
  $st->close();
  echo json_encode(['ok'=>false,'error'=>'Ticket bulunamadı']);
  exit;
}
$st->close();

$createdByUserId   = (int)$createdByUserId;
$createdByAgencyId = (int)$createdByAgencyId;
$targetAgencyId    = (int)$targetAgencyId;
$status            = (string)$status;
// $firstRespAt NULL veya datetime string gelebilir

/* ✅ FIX: PERSONEL dahil, erişim AGENCY bazlı olmalı */
if (!($createdByAgencyId === $agencyId || $targetAgencyId === $agencyId)) {
  echo json_encode(['ok'=>false,'error'=>'Erişim yok']);
  exit;
}

if ($status === 'closed') {
  echo json_encode(['ok'=>false,'error'=>'Ticket kapalı']);
  exit;
}

/* close_reason validasyon */
$allowedCloseReasons = ['policy_issued','policy_not_issued','none',''];
if (!in_array($closeReasonIn, $allowedCloseReasons, true)) {
  echo json_encode(['ok'=>false,'error'=>'Geçersiz poliçe durumu']);
  exit;
}

/* ✅ Sadece ANA ACENTE poliçe durumunu değiştirebilir */
$canSetPolicy = ((int)$agencyId === (int)$targetAgencyId);
if (!$canSetPolicy) {
  $closeReasonIn = '';
}

$conn->begin_transaction();

try {
  /* 1) Mesajı kaydet */
  $ms = $conn->prepare("
    INSERT INTO ticket_messages
      (ticket_id, sender_user_id, sender_agency_id, message, created_at)
    VALUES (?, ?, ?, ?, NOW())
  ");
  $ms->bind_param("iiis", $ticketId, $userId, $agencyId, $message);
  if (!$ms->execute()) throw new Exception("Mesaj eklenemedi");
  $ms->close();

  /* ✅ 1.5) FIRST RESPONSE (rapor için)
     - Ana acente (target agency) mesaj attıysa
     - ticket'ta first_response_at boşsa bir kere set et
  */
  $isFirstRespEmpty = (empty($firstRespAt) || $firstRespAt === '0000-00-00 00:00:00');
  if ($isFirstRespEmpty && (int)$agencyId === (int)$targetAgencyId) {
    $fr = $conn->prepare("
      UPDATE tickets
      SET first_response_at = NOW(), first_response_by = ?
      WHERE id = ? AND first_response_at IS NULL
      LIMIT 1
    ");
    $fr->bind_param("ii", $userId, $ticketId);
    if (!$fr->execute()) throw new Exception("First response set edilemedi");
    $fr->close();
  }

  /* 2) Ticket updated_at (+ close_reason varsa) */
  if ($closeReasonIn !== '') {
    $up = $conn->prepare("
      UPDATE tickets
      SET close_reason = ?, updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    $up->bind_param("si", $closeReasonIn, $ticketId);
  } else {
    $up = $conn->prepare("
      UPDATE tickets
      SET updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    $up->bind_param("i", $ticketId);
  }
  if (!$up->execute()) throw new Exception("Ticket güncellenemedi");
  $up->close();

  /* 3) Gönderen için seen */
  $seen = $conn->prepare("
    INSERT INTO ticket_user_state (ticket_id, user_id, last_seen_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_seen_at = NOW()
  ");
  $seen->bind_param("ii", $ticketId, $userId);
  if (!$seen->execute()) throw new Exception("Seen yazılamadı");
  $seen->close();

  $conn->commit();

  $redirectUrl = base_url('tickets.php');
  $out = ['ok'=>true];
  if ($redirectQ === '1') $out['redirect_url'] = $redirectUrl;
  echo json_encode($out);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  echo json_encode(['ok'=>false,'error'=>'İşlem hatası']);
  exit;
}
