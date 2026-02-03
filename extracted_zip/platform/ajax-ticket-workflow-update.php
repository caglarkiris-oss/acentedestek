<?php
// /public_html/platform/ajax-ticket-workflow-update.php  (TEK PARÇA)

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

header('Content-Type: application/json; charset=utf-8');

$debug = (bool) config('app.debug', false);

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'Yetkisiz']); exit; }
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$ALLOWED = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) {
  echo json_encode(['ok'=>false,'error'=>'Yetkisiz']);
  exit;
}

/* INPUT */
$ticketId = (int)($_POST['ticket_id'] ?? $_POST['id'] ?? $_POST['ticket'] ?? 0);
$newWf    = strtoupper(trim((string)($_POST['workflow_status'] ?? '')));

if ($ticketId <= 0) { echo json_encode(['ok'=>false,'error'=>'Geçersiz ticket']); exit; }

$allowedWF = ['POLICE_GIRISI_YAPILDI','SATIS_YAPILDI','SATIS_YAPILMADI','IPTAL','ZEYIL'];
if ($newWf === '' || !in_array($newWf, $allowedWF, true)) {
  echo json_encode(['ok'=>false,'error'=>'Geçersiz iş akışı']);
  exit;
}

/* Label */
function wf_label(string $s): string {
  $m = [
    'POLICE_GIRISI_YAPILDI' => 'Teklif girişi yapıldı',
    'SATIS_YAPILDI'         => 'Satış yapıldı',
    'SATIS_YAPILMADI'       => 'Satış yapılamadı',
    'IPTAL'                 => 'İptal',
    'ZEYIL'                 => 'Zeyil',
  ];
  $s = strtoupper(trim($s));
  return $m[$s] ?? $s;
}

$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

/* Ticket + erişim */
$createdByAgencyId = 0;
$targetAgencyId = 0;
$status = '';
$oldWf = '';

$st = $conn->prepare("
  SELECT created_by_agency_id, target_agency_id, status, workflow_status
  FROM tickets
  WHERE id = ?
  LIMIT 1
");
if (!$st) { echo json_encode(['ok'=>false,'error'=>'DB hata']); exit; }
$st->bind_param("i", $ticketId);
$st->execute();
$st->bind_result($createdByAgencyId, $targetAgencyId, $status, $oldWf);

if (!$st->fetch()) {
  $st->close();
  echo json_encode(['ok'=>false,'error'=>'Ticket bulunamadı']);
  exit;
}
$st->close();

$createdByAgencyId = (int)$createdByAgencyId;
$targetAgencyId    = (int)$targetAgencyId;
$status            = (string)$status;
$oldWf             = strtoupper(trim((string)$oldWf));

if ($status === 'closed') {
  echo json_encode(['ok'=>false,'error'=>'Ticket kapalı']);
  exit;
}

/* ✅ Sadece ANA ACENTE (target) güncelleyebilir */
if ($agencyId !== $targetAgencyId) {
  echo json_encode(['ok'=>false,'error'=>'İş akışını sadece ana acente güncelleyebilir']);
  exit;
}

/* No-op */
if ($oldWf === $newWf) {
  echo json_encode(['ok'=>true,'nochange'=>true,'workflow_status'=>$newWf,'workflow_label'=>wf_label($newWf)]);
  exit;
}

$conn->begin_transaction();

try {
  /* 1) Ticket workflow update */
  $up = $conn->prepare("
    UPDATE tickets
    SET workflow_status = ?, workflow_updated_at = NOW(), workflow_updated_by_user_id = ?, updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  if (!$up) throw new Exception("Prepare update failed");
  $up->bind_param("sii", $newWf, $userId, $ticketId);
  if (!$up->execute()) throw new Exception("Update failed");
  $up->close();

  /* 2) ✅ Sistem mesajı düş (eski davranış) */
  $sysMsg = "İş akışı güncellendi: " . wf_label($newWf);
  $ms = $conn->prepare("
    INSERT INTO ticket_messages (ticket_id, sender_user_id, sender_agency_id, message, created_at)
    VALUES (?, 0, 0, ?, NOW())
  ");
  if (!$ms) throw new Exception("Prepare message failed");
  $ms->bind_param("is", $ticketId, $sysMsg);
  if (!$ms->execute()) throw new Exception("Message insert failed");
  $ms->close();

  /* 3) Güncelleyen için seen */
  $seen = $conn->prepare("
    INSERT INTO ticket_user_state (ticket_id, user_id, last_seen_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_seen_at = NOW()
  ");
  if ($seen) {
    $seen->bind_param("ii", $ticketId, $userId);
    $seen->execute();
    $seen->close();
  }

  $conn->commit();

  echo json_encode([
    'ok' => true,
    'workflow_status' => $newWf,
    'workflow_label'  => wf_label($newWf),
    'message'         => $sysMsg
  ]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  error_log("ajax-ticket-workflow-update EX: ".$e->getMessage());
  echo json_encode(['ok'=>false,'error'=>$debug ? $e->getMessage() : 'İşlem hatası']);
  exit;
}
