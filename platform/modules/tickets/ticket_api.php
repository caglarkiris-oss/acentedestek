<?php
// /platform/api/ticket.php
// Unified Ticket API (Step: ajax sadeleştirme)
//
// Backward compatible: existing ajax-ticket-*.php can proxy into this file.

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../_ticket_api_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: application/json; charset=utf-8');

$debug = (bool) config('app.debug', false);

function json_out(array $arr, int $code = 200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

ensure_session();
if (empty($_SESSION['user_id'])) {
  json_out(['ok' => false, 'error' => 'Yetkisiz'], 401);
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$ALLOWED = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) {
  json_out(['ok' => false, 'error' => 'Yetkisiz'], 403);
}

$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

// Action: close|reply (POST), kept simple for now
$action = (string)($_REQUEST['action'] ?? '');
$action = strtolower(trim($action));

// CSRF enforcement toggle
$csrfEnforce = (bool) config('security.csrf_enforce', true);

// Write actions require CSRF
$WRITE_ACTIONS = ['close','reply','upload','workflow_update','cardinfo_save','mutabakat_save'];

// =========================================================
// Legacy action bridge (incremental consolidation)
// - Eski endpointler wrapper'a döndükçe burada çalışmaya devam eder.
// - İleride bu action'lar tamamen bu dosya içine taşınacak.
// =========================================================
$legacyMap = [
  'counters'        => 'ajax-ticket-counters.php',
  'list'            => 'ajax-tickets-list.php',
  'workflow_update' => 'ajax-ticket-workflow-update.php',
  'cardinfo_save'   => 'ajax-ticket-cardinfo-save.php',
  'mutabakat_save'  => 'ajax-ticket-mutabakat-save.php',
];

if (isset($legacyMap[$action])) {
  if ($csrfEnforce && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, $WRITE_ACTIONS, true)) {
    csrf_verify();
  }
  // include legacy script from /platform/modules/tickets/legacy
  require_once __DIR__ . '/legacy/' . $legacyMap[$action];
  exit;
}

/* =========================================================
   Upload helpers (migrated from ajax-ticket-upload.php)
========================================================= */
function norm_path(string $p): string {
  $p = trim($p);
  if ($p === '') return '';
  $p = str_replace('\\','/',$p);
  return rtrim($p, '/');
}

function ensure_dir(string $dir): bool {
  if ($dir === '') return false;
  if (is_dir($dir)) return true;
  return @mkdir($dir, 0755, true) || is_dir($dir);
}

// CSRF (write actions)
if ($csrfEnforce && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, $WRITE_ACTIONS, true)) {
  csrf_verify();
}

try {
  // ensure tickets table exists
  $tableCheck = $conn->query("SHOW TABLES LIKE 'tickets'");
  if (!$tableCheck || $tableCheck->num_rows === 0) {
    json_out(['ok' => false, 'error' => 'Tickets tablosu bulunamadi'], 500);
  }

  if ($action === 'close') {
    $ticketId = (int)($_POST['ticket_id'] ?? $_POST['id'] ?? $_POST['ticket'] ?? 0);
    if ($ticketId <= 0) json_out(['ok'=>false,'error'=>'Gecersiz ticket'], 422);

    $message = trim((string)($_POST['message'] ?? $_POST['reply_message'] ?? $_POST['msg'] ?? $_POST['text'] ?? ''));
    $hasMessage = ($message !== '' && $message !== '-');

    // Fetch ticket
    $st = $conn->prepare("SELECT id, created_by_agency_id, target_agency_id, status, workflow_status
                          FROM tickets WHERE id=? LIMIT 1");
    if (!$st) throw new Exception("Prepare fail tickets: " . $conn->error);
    $st->bind_param("i", $ticketId);
    $st->execute();
    $st->bind_result($tid, $createdByAgencyId, $targetAgencyId, $status, $wf);
    if (!$st->fetch()) {
      $st->close();
      json_out(['ok'=>false,'error'=>'Ticket bulunamadi'], 404);
    }
    $st->close();

    $createdByAgencyId = (int)$createdByAgencyId;
    $targetAgencyId    = (int)$targetAgencyId;
    $status            = (string)$status;
    $WF                = strtoupper(trim((string)$wf));

    if ($createdByAgencyId !== $agencyId) {
      json_out(['ok'=>false,'error'=>'Ticketi sadece acan acente kapatabilir.'], 403);
    }
    if ($status === 'closed') {
      json_out(['ok'=>false,'error'=>'Ticket zaten kapali.'], 409);
    }

    // Mutabakat required check (reuse logic)
    $requiresMutabakat = in_array($WF, ['SATIS_YAPILDI', 'IPTAL', 'ZEYIL'], true);
    if ($requiresMutabakat) {
      $expectedType = ($WF === 'SATIS_YAPILDI') ? 'SATIS' : (($WF === 'IPTAL') ? 'IPTAL' : 'ZEYIL');
      $found = false;

      $t2 = $conn->query("SHOW TABLES LIKE 'mutabakat_v2_rows'");
      if ($t2 && $t2->num_rows > 0) {
        $chk2 = $conn->prepare("SELECT id, txn_type FROM mutabakat_v2_rows
                                WHERE ticket_id = ? AND UPPER(source_type) = 'TICKET'
                                ORDER BY id DESC LIMIT 1");
        if ($chk2) {
          $chk2->bind_param("i", $ticketId);
          $chk2->execute();
          $chk2->bind_result($mid2, $mtype2);
          if ($chk2->fetch()) {
            $mtype2 = strtoupper(trim((string)$mtype2));
            if ($mtype2 === $expectedType) {
              $found = true;
            } else {
              $chk2->close();
              json_out(['ok'=>false,'error'=>'Mutabakat tipi is akisi ile uyumsuz.'], 422);
            }
          }
          $chk2->close();
        }
      }

      if (!$found) {
        json_out(['ok'=>false,'error'=>'Mutabakat kaydi olmadan ticket kapanamaz.'], 422);
      }
    }

    $conn->begin_transaction();
    try {
      if ($hasMessage) {
        $msgTableCheck = $conn->query("SHOW TABLES LIKE 'ticket_messages'");
        if ($msgTableCheck && $msgTableCheck->num_rows > 0) {
          $insMsg = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_user_id, sender_agency_id, message, created_at)
                                    VALUES (?, ?, ?, ?, NOW())");
          if ($insMsg) {
            $insMsg->bind_param("iiis", $ticketId, $userId, $agencyId, $message);
            $insMsg->execute();
            $insMsg->close();
          }
        }
      }

      $up = $conn->prepare("UPDATE tickets SET status='closed', updated_at=NOW(), closed_at=NOW(), closed_by_user_id=? WHERE id=? LIMIT 1");
      if (!$up) throw new Exception("Prepare fail close: " . $conn->error);
      $up->bind_param("ii", $userId, $ticketId);
      if (!$up->execute()) throw new Exception("Close update fail: " . $up->error);
      $affected = (int)$up->affected_rows;
      $up->close();
      if ($affected <= 0) throw new Exception("Ticket kapanmadi (affected_rows=0).");

      // update states (optional)
      $stateTableCheck = $conn->query("SHOW TABLES LIKE 'ticket_user_state'");
      if ($stateTableCheck && $stateTableCheck->num_rows > 0) {
        $clear = $conn->prepare("INSERT INTO ticket_user_state (ticket_id, user_id, last_seen_at)
                                 SELECT ?, u.id, NOW() FROM users u WHERE u.agency_id IN (?, ?)
                                 ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)");
        if ($clear) {
          $clear->bind_param("iii", $ticketId, $createdByAgencyId, $targetAgencyId);
          $clear->execute();
          $clear->close();
        }
      }

      $conn->commit();
    } catch (Throwable $e2) {
      $conn->rollback();
      throw $e2;
    }

    $redirect = (string)($_POST['redirect'] ?? '');
    if ($redirect === '1') {
      json_out(['ok'=>true,'redirect_url'=>route_url('tickets')]);
    }
    json_out(['ok'=>true]);
  }

  if ($action === 'reply') {
    $ticketId      = (int)($_POST['ticket_id'] ?? 0);
    $message       = trim((string)($_POST['message'] ?? ''));
    $redirectQ     = (string)($_POST['redirect'] ?? '');
    $closeReasonIn = trim((string)($_POST['close_reason'] ?? ''));

    if ($ticketId <= 0) json_out(['ok'=>false,'error'=>'Geçersiz ticket'], 422);
    if ($message === '') json_out(['ok'=>false,'error'=>'Mesaj boş olamaz'], 422);

    $createdByUserId   = 0;
    $createdByAgencyId = 0;
    $targetAgencyId    = 0;
    $status            = '';
    $firstRespAt       = null;

    $st = $conn->prepare("SELECT created_by_user_id, created_by_agency_id, target_agency_id, status, first_response_at
                          FROM tickets WHERE id = ? LIMIT 1");
    $st->bind_param("i", $ticketId);
    $st->execute();
    $st->bind_result($createdByUserId, $createdByAgencyId, $targetAgencyId, $status, $firstRespAt);

    if (!$st->fetch()) {
      $st->close();
      json_out(['ok'=>false,'error'=>'Ticket bulunamadı'], 404);
    }
    $st->close();

    $createdByAgencyId = (int)$createdByAgencyId;
    $targetAgencyId    = (int)$targetAgencyId;
    $status            = (string)$status;

    if (!($createdByAgencyId === $agencyId || $targetAgencyId === $agencyId)) {
      json_out(['ok'=>false,'error'=>'Erişim yok'], 403);
    }
    if ($status === 'closed') {
      json_out(['ok'=>false,'error'=>'Ticket kapalı'], 409);
    }

    $allowedCloseReasons = ['policy_issued','policy_not_issued','none',''];
    if (!in_array($closeReasonIn, $allowedCloseReasons, true)) {
      json_out(['ok'=>false,'error'=>'Geçersiz poliçe durumu'], 422);
    }

    // Only target agency can set policy state
    if ($agencyId !== $targetAgencyId) $closeReasonIn = '';

    $conn->begin_transaction();
    try {
      $ms = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_user_id, sender_agency_id, message, created_at)
                            VALUES (?, ?, ?, ?, NOW())");
      $ms->bind_param("iiis", $ticketId, $userId, $agencyId, $message);
      if (!$ms->execute()) throw new Exception("Mesaj eklenemedi");
      $ms->close();

      $isFirstRespEmpty = (empty($firstRespAt) || $firstRespAt === '0000-00-00 00:00:00');
      if ($isFirstRespEmpty && $agencyId === $targetAgencyId) {
        $fr = $conn->prepare("UPDATE tickets SET first_response_at = NOW(), first_response_by = ?
                              WHERE id = ? AND first_response_at IS NULL LIMIT 1");
        $fr->bind_param("ii", $userId, $ticketId);
        if (!$fr->execute()) throw new Exception("First response set edilemedi");
        $fr->close();
      }

      if ($closeReasonIn !== '') {
        $up = $conn->prepare("UPDATE tickets SET close_reason = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        $up->bind_param("si", $closeReasonIn, $ticketId);
      } else {
        $up = $conn->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ? LIMIT 1");
        $up->bind_param("i", $ticketId);
      }
      if (!$up->execute()) throw new Exception("Ticket güncellenemedi");
      $up->close();

      $seen = $conn->prepare("INSERT INTO ticket_user_state (ticket_id, user_id, last_seen_at)
                              VALUES (?, ?, NOW())
                              ON DUPLICATE KEY UPDATE last_seen_at = NOW()");
      $seen->bind_param("ii", $ticketId, $userId);
      if (!$seen->execute()) throw new Exception("Seen yazılamadı");
      $seen->close();

      $conn->commit();
    } catch (Throwable $e2) {
      $conn->rollback();
      throw $e2;
    }

    $out = ['ok'=>true];
    if ($redirectQ === '1') $out['redirect_url'] = route_url('tickets');
    json_out($out);
  }

  if ($action === 'upload') {
    // File upload for ticket_files (migrated from ajax-ticket-upload.php)
    $ticketId  = (int)($_POST['ticket_id'] ?? 0);
    $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : null;

    if ($ticketId <= 0) json_out(['ok'=>false,'error'=>'Geçersiz ticket'], 422);
    if (empty($_FILES['file'])) json_out(['ok'=>false,'error'=>'Dosya yok'], 422);

    $file = $_FILES['file'];
    if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
      json_out(['ok'=>false,'error'=>'Yükleme hatası'], 422);
    }

    // LIMIT: 10 MB
    $maxBytes = 10 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxBytes) {
      json_out(['ok'=>false,'error'=>'Dosya 10MB büyük olamaz'], 422);
    }

    // EXT whitelist
    $allowedExt = ['pdf','xls','xlsx','doc','docx','png','jpg','jpeg','webp','txt','zip','rar'];
    $origName = trim((string)($file['name'] ?? ''));
    $origName = basename($origName);
    $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
      json_out(['ok'=>false,'error'=>'Dosya türü desteklenmiyor'], 422);
    }

    // MIME (best-effort)
    $mime = (string)($file['type'] ?? '');
    if (function_exists('finfo_open')) {
      $fi = finfo_open(FILEINFO_MIME_TYPE);
      if ($fi) {
        $det = finfo_file($fi, (string)$file['tmp_name']);
        if (is_string($det) && $det !== '') $mime = $det;
        finfo_close($fi);
      }
    }

    // Ticket var mı?
    $chk = $conn->prepare("SELECT id FROM tickets WHERE id=? LIMIT 1");
    if (!$chk) throw new Exception('Prepare fail ticket chk: ' . $conn->error);
    $chk->bind_param('i', $ticketId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
      $chk->close();
      json_out(['ok'=>false,'error'=>'Ticket bulunamadı'], 404);
    }
    $chk->close();

    // ticket_files tablosu kontrol
    $tf = $conn->query("SHOW TABLES LIKE 'ticket_files'");
    if (!$tf || $tf->num_rows === 0) {
      json_out(['ok'=>false,'error'=>'ticket_files tablosu bulunamadı'], 500);
    }

    // STORAGE RESOLUTION (fallback’li)
    $cfgRoot = norm_path((string)config('paths.storage_root', ''));
    $candidates = array_values(array_filter([
      $cfgRoot,
      norm_path(__DIR__ . '/../../storage'), // önerilen: /public_html/storage
      norm_path(__DIR__ . '/../storage'),    // fallback: /public_html/platform/storage
    ], fn($v) => $v !== ''));

    $storageRoot = '';
    foreach ($candidates as $cand) {
      if (!ensure_dir($cand)) continue;
      if (!is_writable($cand)) continue;
      $storageRoot = $cand;
      break;
    }
    if ($storageRoot === '') {
      json_out(['ok'=>false,'error'=>'Storage dizini oluşturulamadı'], 500);
    }

    $y = date('Y'); $m = date('m'); $d = date('d');
    $storageRelDir = "tickets/$y/$m/$d/ticket_$ticketId";
    $targetDir     = $storageRoot . '/' . $storageRelDir;
    if (!ensure_dir($targetDir) || !is_writable($targetDir)) {
      json_out(['ok'=>false,'error'=>'Storage dizini yazılabilir değil'], 500);
    }

    try { $rand = bin2hex(random_bytes(8)); }
    catch (Throwable $e2) { $rand = uniqid('', true); }

    $storedName = 'f_' . $rand . '.' . $ext;
    $targetPath = $targetDir . '/' . $storedName;
    if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
      json_out(['ok'=>false,'error'=>'Dosya taşınamadı'], 500);
    }

    $fileSize = (int)($file['size'] ?? 0);
    $storageRelPath = $storageRelDir . '/';

    $stmt = $conn->prepare("INSERT INTO ticket_files (ticket_id, message_id, uploaded_by, original_name, stored_name, mime_type, file_size, storage_rel_path)
                            VALUES (?,?,?,?,?,?,?,?)");
    if (!$stmt) {
      @unlink($targetPath);
      throw new Exception('DB hazırlama hatası: ' . $conn->error);
    }
    $mid = (int)($messageId ?? 0);
    if ($messageId === null) $mid = 0;
    $stmt->bind_param('iiisssis', $ticketId, $mid, $userId, $origName, $storedName, $mime, $fileSize, $storageRelPath);
    if (!$stmt->execute()) {
      @unlink($targetPath);
      $stmt->close();
      throw new Exception('DB kayıt hatası: ' . $stmt->error);
    }
    $insertId = (int)$stmt->insert_id;
    $stmt->close();

    json_out([
      'ok' => true,
      'file' => [
        'id' => $insertId,
        'ticket_id' => $ticketId,
        'message_id' => $messageId,
        'original_name' => $origName,
        'stored_name' => $storedName,
        'mime_type' => $mime,
        'file_size' => $fileSize,
        'storage_rel_path' => $storageRelPath
      ]
    ]);
  }

  json_out(['ok'=>false,'error'=>'Geçersiz işlem'], 400);

} catch (Throwable $e) {
  error_log('ticket_api EX: ' . $e->getMessage());
  json_out(['ok'=>false,'error'=>$debug ? $e->getMessage() : 'Sunucu hatası'], 500);
}
