<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');

$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);
$userId   = (int)($_SESSION['user_id'] ?? 0);

if ($role === 'SUPERADMIN' || $agencyId <= 0 || $userId <= 0) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'unread'=>0], JSON_UNESCAPED_UNICODE);
  exit;
}

$conn = $GLOBALS['conn'] ?? (function_exists('db') ? db() : null);
if (!$conn) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'unread'=>0], JSON_UNESCAPED_UNICODE);
  exit;
}

$unread = 0;

$sqlUnread = "
  SELECT COUNT(*) AS c
  FROM tickets t
  LEFT JOIN (
    SELECT ticket_id, MAX(created_at) AS last_msg_at
    FROM ticket_messages
    GROUP BY ticket_id
  ) lm ON lm.ticket_id = t.id
  LEFT JOIN ticket_reads tr
    ON tr.ticket_id = t.id AND tr.reader_user_id = ?
  WHERE
    (t.target_agency_id = ? OR t.created_by_agency_id = ?)
    AND COALESCE(lm.last_msg_at, t.created_at) > COALESCE(tr.last_read_at, '1970-01-01 00:00:00')
";

$st = @mysqli_prepare($conn, $sqlUnread);
if ($st) {
  @mysqli_stmt_bind_param($st, "iii", $userId, $agencyId, $agencyId);
  if (@mysqli_stmt_execute($st)) {
    @mysqli_stmt_bind_result($st, $unread);
    @mysqli_stmt_fetch($st);
  }
  @mysqli_stmt_close($st);
}

echo json_encode(['ok'=>true,'unread'=>(int)$unread], JSON_UNESCAPED_UNICODE);
