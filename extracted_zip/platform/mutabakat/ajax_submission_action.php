<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('log_errors','1');
error_reporting(E_ALL);

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../db.php';

header('Content-Type: application/json; charset=utf-8');
require_login();

$conn = db();
if (!$conn) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>'DB yok'], JSON_UNESCAPED_UNICODE); exit; }

$role     = (string)($_SESSION['role'] ?? '');
$userId   = (int)($_SESSION['user_id'] ?? 0);
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$isTali = in_array($role, ['TALI_ACENTE_YETKILISI','PERSONEL'], true);
if (!$isTali) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Yetkisiz'], JSON_UNESCAPED_UNICODE); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($body['action'] ?? '');
$period = (string)($body['period'] ?? '');
$mode   = strtoupper(trim((string)($body['mode'] ?? 'TICKET')));
$note   = trim((string)($body['note'] ?? ''));

if (!$period) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'period yok'], JSON_UNESCAPED_UNICODE); exit; }
if (!in_array($mode, ['TICKET','CSV'], true)) $mode = 'TICKET';

$taliId = $agencyId;

/* main bul */
$mainAgencyId = 0;
$st = $conn->prepare("SELECT main_agency_id FROM agency_relations WHERE tali_agency_id=? LIMIT 1");
$st->bind_param("i",$taliId);
$st->execute();
$st->bind_result($mainAgencyId);
$st->fetch();
$st->close();

if ($mainAgencyId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Main yok'], JSON_UNESCAPED_UNICODE); exit; }

/* sadece SENT iken aksiyon */
$st = $conn->prepare("SELECT id, status FROM mutabakat_submissions WHERE main_agency_id=? AND tali_agency_id=? AND period=? AND mode=? ORDER BY id DESC LIMIT 1");
$st->bind_param("iiss",$mainAgencyId,$taliId,$period,$mode);
$st->execute();
$res = $st->get_result();
$sub = $res->fetch_assoc();
$st->close();

if (!$sub) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Submission yok'], JSON_UNESCAPED_UNICODE); exit; }
if ((string)$sub['status'] !== 'SENT') { http_response_code(409); echo json_encode(['ok'=>false,'message'=>'Bu mutabakat SENT değil'], JSON_UNESCAPED_UNICODE); exit; }

$subId = (int)$sub['id'];

if ($action === 'approve') {
  $st = $conn->prepare("UPDATE mutabakat_submissions SET status='APPROVED', approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id=?");
  $st->bind_param("ii",$userId,$subId);
  $st->execute();
  $st->close();

  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'dispute') {
  if (mb_strlen($note) < 3) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Not yaz'], JSON_UNESCAPED_UNICODE); exit; }
  $st = $conn->prepare("UPDATE mutabakat_submissions SET status='DISPUTED', dispute_by=?, dispute_at=NOW(), dispute_note=?, updated_at=NOW() WHERE id=?");
  $st->bind_param("isi",$userId,$note,$subId);
  $st->execute();
  $st->close();

  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE); exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'message'=>'action geçersiz'], JSON_UNESCAPED_UNICODE);
