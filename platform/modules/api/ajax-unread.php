<?php
// /platform/ajax-unread.php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

if (empty($_SESSION['user_id'])) {
  echo json_encode(['ok'=>true,'unread'=>0,'reason'=>'not_logged_in']);
  exit;
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

if ($role === 'SUPERADMIN' || $userId <= 0) {
  echo json_encode(['ok'=>true,'unread'=>0,'reason'=>'no_scope']);
  exit;
}

// DB bağlantısı
$conn = null;
if (isset($GLOBALS['conn']) && ($GLOBALS['conn'] instanceof mysqli)) $conn = $GLOBALS['conn'];
if (!$conn && isset($GLOBALS['mysqli']) && ($GLOBALS['mysqli'] instanceof mysqli)) $conn = $GLOBALS['mysqli'];
if (!$conn && function_exists('db')) {
  try { $tmp = db(); if ($tmp instanceof mysqli) $conn = $tmp; } catch(Throwable $e) {}
}
if (!$conn || !($conn instanceof mysqli)) {
  echo json_encode(['ok'=>false,'unread'=>0,'error'=>'db_conn_missing']);
  exit;
}

$conn->set_charset((string)config('db.charset', 'utf8mb4'));

/*
  ✅ UNREAD (YENİ MODEL):
  unread = COALESCE(t.updated_at,t.created_at) > ticket_user_state.last_seen_at

  Scope:
   - PERSONEL: t.created_by_user_id = userId
   - ACENTE/TALI: (t.created_by_agency_id = agencyId OR t.target_agency_id = agencyId)
*/

$unread = 0;

if ($role === 'PERSONEL') {

  $sql = "
    SELECT COUNT(*)
    FROM tickets t
    LEFT JOIN ticket_user_state s
      ON s.ticket_id = t.id AND s.user_id = ?
    WHERE t.created_by_user_id = ?
      AND COALESCE(t.updated_at, t.created_at) > COALESCE(s.last_seen_at, '1970-01-01 00:00:00')
  ";

  $st = $conn->prepare($sql);
  if (!$st) {
    echo json_encode(['ok'=>false,'unread'=>0,'error'=>'prepare_failed','detail'=>$conn->error]);
    exit;
  }
  $st->bind_param("ii", $userId, $userId);
  $st->execute();
  $st->bind_result($unread);
  $st->fetch();
  $st->close();

  echo json_encode(['ok'=>true,'unread'=>(int)$unread]);
  exit;
}

// ACENTE / TALI
if ($agencyId <= 0) {
  echo json_encode(['ok'=>true,'unread'=>0,'reason'=>'no_agency']);
  exit;
}

$sql = "
  SELECT COUNT(*)
  FROM tickets t
  LEFT JOIN ticket_user_state s
    ON s.ticket_id = t.id AND s.user_id = ?
  WHERE (t.created_by_agency_id = ? OR t.target_agency_id = ?)
    AND COALESCE(t.updated_at, t.created_at) > COALESCE(s.last_seen_at, '1970-01-01 00:00:00')
";

$st = $conn->prepare($sql);
if (!$st) {
  echo json_encode(['ok'=>false,'unread'=>0,'error'=>'prepare_failed','detail'=>$conn->error]);
  exit;
}
$st->bind_param("iii", $userId, $agencyId, $agencyId);
$st->execute();
$st->bind_result($unread);
$st->fetch();
$st->close();

echo json_encode(['ok'=>true,'unread'=>(int)$unread]);
exit;
