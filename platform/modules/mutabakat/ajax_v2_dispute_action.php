<?php
// /platform/modules/mutabakat/ajax_v2_dispute_action.php
// Mutabakat V2 - Itiraz aksiyonlari (A3 base -> Adim 6)
//
// actions:
//  - send_dispute   : row_status='ITIRAZ' + disputes upsert (status OPEN)
//  - save_note      : dispute description update
//  - toggle_status  : OPEN <-> RESOLVED (only main)

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_ajax_v2_dispute_action_error.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

function jfail(int $code, string $msg): void {
  http_response_code($code);
  echo json_encode(['ok'=>false,'msg'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function jok(array $data=[]): void {
  echo json_encode(array_merge(['ok'=>true], $data), JSON_UNESCAPED_UNICODE);
  exit;
}

if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) jfail(401, 'Unauthorized');
}

$csrf = (string)($_POST['csrf'] ?? '');
if (function_exists('csrf_verify')) {
  if (!csrf_verify($csrf)) jfail(403,'CSRF');
} else {
  $sess = (string)($_SESSION['_csrf'] ?? '');
  if (!$csrf || !$sess || !hash_equals($sess, $csrf)) jfail(403,'CSRF');
}

$conn = db();
if (!$conn) jfail(500,'DB yok');
$conn->set_charset((string)config('db.charset','utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$agencyId = (int)($_SESSION['agency_id'] ?? 0);
$isMain   = function_exists('is_main_role') ? is_main_role() : (($_SESSION['role'] ?? '') === 'main');
$isTali   = function_exists('is_tali_role') ? is_tali_role() : (($_SESSION['role'] ?? '') === 'tali');

if (!$agencyId) jfail(403,'Agency yok');

$action = strtolower(trim((string)($_POST['action'] ?? '')));
if (!in_array($action, ['send_dispute','save_note','toggle_status'], true)) jfail(422,'Gecersiz action');

// Resolve main agency id
$mainAgencyId = $isMain ? $agencyId : (function_exists('get_main_agency_id') ? (int)get_main_agency_id($conn, $agencyId) : 0);
if (!$mainAgencyId) $mainAgencyId = $agencyId;

// Tali workmode guard
$taliWorkMode = $isTali && function_exists('get_tali_work_mode') ? (string)get_tali_work_mode($conn, $agencyId) : 'ticket';

if ($isTali && $taliWorkMode !== 'csv') {
  // ticket workmode tali: itiraz aksiyonu yok
  jfail(403,'Yetkisiz (workmode)');
}

if ($action === 'toggle_status' && !$isMain) {
  jfail(403,'Yetkisiz');
}

if ($action === 'save_note') {
  $disputeId = (int)($_POST['dispute_id'] ?? 0);
  $note = trim((string)($_POST['note'] ?? ''));
  if ($disputeId <= 0) jfail(422,'dispute_id eksik');

  // Scope: main sees all in its period, tali only own raised_by_agency_id
  if ($isMain) {
    $st = $conn->prepare("UPDATE mutabakat_v2_disputes d
                          JOIN mutabakat_v2_rows r ON r.id=d.row_id
                          SET d.description=?, d.updated_at=NOW()
                          WHERE d.id=? AND r.ana_acente_id=?");
    if (!$st) jfail(500,'SQL');
    $st->bind_param('sii', $note, $disputeId, $mainAgencyId);
  } else {
    $st = $conn->prepare("UPDATE mutabakat_v2_disputes d
                          JOIN mutabakat_v2_rows r ON r.id=d.row_id
                          SET d.description=?, d.updated_at=NOW()
                          WHERE d.id=? AND r.ana_acente_id=? AND d.raised_by_agency_id=?");
    if (!$st) jfail(500,'SQL');
    $st->bind_param('siii', $note, $disputeId, $mainAgencyId, $agencyId);
  }
  $st->execute();
  $st->close();
  jok(['msg'=>'OK']);
}

if ($action === 'toggle_status') {
  $disputeId = (int)($_POST['dispute_id'] ?? 0);
  if ($disputeId <= 0) jfail(422,'dispute_id eksik');

  $st = $conn->prepare("SELECT d.status, d.row_id
                        FROM mutabakat_v2_disputes d
                        JOIN mutabakat_v2_rows r ON r.id=d.row_id
                        WHERE d.id=? AND r.ana_acente_id=? LIMIT 1");
  if (!$st) jfail(500,'SQL');
  $st->bind_param('ii', $disputeId, $mainAgencyId);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();
  if (!$row) jfail(404,'Itiraz bulunamadi');

  $cur = (string)($row['status'] ?? 'OPEN');
  $rowId = (int)($row['row_id'] ?? 0);
  $next = ($cur === 'OPEN') ? 'RESOLVED' : 'OPEN';

  $st = $conn->prepare("UPDATE mutabakat_v2_disputes SET status=?, updated_at=NOW() WHERE id=?");
  if (!$st) jfail(500,'SQL');
  $st->bind_param('si', $next, $disputeId);
  $st->execute();
  $st->close();

  // If resolved, keep row_status ITIRAZ? we can return it to HAVUZ on RESOLVED for UX
  if ($next !== 'OPEN' && $rowId > 0) {
    $st = $conn->prepare("UPDATE mutabakat_v2_rows SET row_status='HAVUZ', updated_at=NOW()
                          WHERE id=? AND period_id=period_id AND ana_acente_id=?");
    if ($st) {
      $st->bind_param('ii', $rowId, $mainAgencyId);
      $st->execute();
      $st->close();
    }
  }
  jok(['status'=>$next]);
}

if ($action === 'send_dispute') {
  $rowId = (int)($_POST['row_id'] ?? 0);
  $periodId = (int)($_POST['period_id'] ?? 0);
  $note = trim((string)($_POST['note'] ?? ''));
  if ($rowId <= 0 || $periodId <= 0) jfail(422,'Eksik parametre');

  // Row scope: main sees all rows in period; tali only its own rows
  if ($isMain) {
    $st = $conn->prepare("SELECT id FROM mutabakat_v2_rows
                          WHERE id=? AND period_id=? AND ana_acente_id=? LIMIT 1");
    if (!$st) jfail(500,'SQL');
    $st->bind_param('iii', $rowId, $periodId, $mainAgencyId);
  } else {
    $st = $conn->prepare("SELECT id FROM mutabakat_v2_rows
                          WHERE id=? AND period_id=? AND ana_acente_id=? AND tali_acente_id=? LIMIT 1");
    if (!$st) jfail(500,'SQL');
    $st->bind_param('iiii', $rowId, $periodId, $mainAgencyId, $agencyId);
  }
  $st->execute();
  $res = $st->get_result();
  $ok = $res && $res->num_rows > 0;
  $st->close();
  if (!$ok) jfail(404,'Satir bulunamadi');

  // Update row_status
  $st = $conn->prepare("UPDATE mutabakat_v2_rows SET row_status='ITIRAZ', updated_at=NOW()
                        WHERE id=? AND period_id=? AND ana_acente_id=?");
  if ($st) {
    $st->bind_param('iii', $rowId, $periodId, $mainAgencyId);
    $st->execute();
    $st->close();
  }

  // Upsert dispute for this row+period (OPEN)
  $st = $conn->prepare("SELECT id FROM mutabakat_v2_disputes WHERE period_id=? AND row_id=? AND status='OPEN' LIMIT 1");
  if (!$st) jfail(500,'SQL');
  $st->bind_param('ii', $periodId, $rowId);
  $st->execute();
  $res = $st->get_result();
  $existing = $res ? $res->fetch_assoc() : null;
  $st->close();

  if ($existing && !empty($existing['id'])) {
    $did = (int)$existing['id'];
    $st = $conn->prepare("UPDATE mutabakat_v2_disputes
                          SET description=?, updated_at=NOW()
                          WHERE id=?");
    if ($st) {
      $st->bind_param('si', $note, $did);
      $st->execute();
      $st->close();
    }
    jok(['dispute_id'=>$did]);
  } else {
    $dtype = 'ROW';
    $st = $conn->prepare("INSERT INTO mutabakat_v2_disputes
      (period_id,row_id,match_id,raised_by_agency_id,raised_by_user_id,dispute_type,description,status,created_at)
      VALUES (?,?,?,?,?,?,?,'OPEN',NOW())");
    if (!$st) jfail(500,'SQL');
    $matchId = null;
    $st->bind_param('iiiisss', $periodId, $rowId, $matchId, $agencyId, $userId, $dtype, $note);
    $st->execute();
    $did = (int)$conn->insert_id;
    $st->close();
    jok(['dispute_id'=>$did]);
  }
}

jfail(500,'Unhandled');
