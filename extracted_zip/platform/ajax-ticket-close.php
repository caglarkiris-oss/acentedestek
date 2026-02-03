<?php
// /public_html/platform/ajax-ticket-close.php  (TEK PARÇA - Mutabakat V2 uyumlu + JSON garantili)

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/db.php";

header('Content-Type: application/json; charset=utf-8');

$debug = (bool) (function_exists('config') ? config('app.debug', false) : false);

// JSON helper (hiçbir zaman HTML dönmeyelim)
function json_out($arr, int $code = 200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

// Basit auth: redirect üretmesin
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id'])) { json_out(['ok'=>false,'error'=>'Yetkisiz'], 401); }

$conn = db();
$conn->set_charset((string)(function_exists('config') ? config('db.charset', 'utf8mb4') : 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$ALLOWED = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) {
  json_out(['ok'=>false,'error'=>'Yetkisiz'], 403);
}

function clean_str($s): string { return trim((string)$s); }

/* INPUT */
$ticketId = (int)($_POST['ticket_id'] ?? $_POST['id'] ?? $_POST['ticket'] ?? 0);
if ($ticketId <= 0) { json_out(['ok'=>false,'error'=>'Geçersiz ticket']); }

/**
 * Mesaj zorunlu değil.
 * UI bazen message yerine farklı key'lerle yollayabiliyor => hepsini topla.
 */
$messageRaw = $_POST['message'] ?? $_POST['reply_message'] ?? $_POST['msg'] ?? $_POST['text'] ?? '';
$messageRaw = clean_str($messageRaw);
$hasMessage = ($messageRaw !== '' && $messageRaw !== '-');
$message    = $messageRaw;

try {
  /* Ticket kontrol + sadece açan AGENCY kapatır + workflow + target_agency_id oku */
  $st = $conn->prepare("
    SELECT id, created_by_agency_id, target_agency_id, status, workflow_status
    FROM tickets
    WHERE id=?
    LIMIT 1
  ");
  if (!$st) throw new Exception("Prepare fail tickets: ".$conn->error);

  $st->bind_param("i", $ticketId);
  $st->execute();
  $st->bind_result($tid, $createdByAgencyId, $targetAgencyId, $status, $wf);
  if (!$st->fetch()) {
    $st->close();
    json_out(['ok'=>false,'error'=>'Ticket bulunamadı']);
  }
  $st->close();

  $createdByAgencyId = (int)$createdByAgencyId;
  $targetAgencyId    = (int)$targetAgencyId;
  $status            = (string)$status;
  $WF                = strtoupper(trim((string)$wf));

  if ($createdByAgencyId !== $agencyId) {
    json_out(['ok'=>false,'error'=>'Ticketi sadece açan acente kapatabilir.']);
  }
  if ($status === 'closed') {
    json_out(['ok'=>false,'error'=>'Ticket zaten kapalı.']);
  }

  /* ✅ Mutabakat zorunluluğu: SATIS/IPTAL/ZEYIL akışlarında mutabakat kaydı aranır */
  $requiresMutabakat = in_array($WF, ['SATIS_YAPILDI','IPTAL','ZEYIL'], true);
  if ($requiresMutabakat) {
    $expectedType = ($WF === 'SATIS_YAPILDI') ? 'SATIS' : (($WF === 'IPTAL') ? 'IPTAL' : 'ZEYIL');

    $found = false;

    // 1) Önce V2 kontrol et: mutabakat_v2_rows (source_type='TICKET' veya 'ticket')
    $t2 = $conn->query("SHOW TABLES LIKE 'mutabakat_v2_rows'");
    if ($t2 && $t2->num_rows > 0) {
      $chk2 = $conn->prepare("
        SELECT id, txn_type
        FROM mutabakat_v2_rows
        WHERE ticket_id = ?
          AND (UPPER(source_type) = 'TICKET')
        ORDER BY id DESC
        LIMIT 1
      ");
      if ($chk2) {
        $chk2->bind_param("i", $ticketId);
        $chk2->execute();
        $chk2->bind_result($mid2, $mtype2);
        if ($chk2->fetch()) {
          $mtype2 = strtoupper(trim((string)$mtype2));
          if ($mtype2 === $expectedType) {
            $found = true;
          } else {
            // kayıt var ama tipi uyuşmuyor
            $chk2->close();
            json_out(['ok'=>false,'error'=>'Mutabakat tipi iş akışı ile uyumsuz. (V2)']);
          }
        }
        $chk2->close();
      }
    }

    // 2) Bulunamadıysa legacy kontrol (geriye dönük)
    if (!$found) {
      $t = $conn->query("SHOW TABLES LIKE 'mutabakat_rows'");
      if ($t && $t->num_rows > 0) {
        $chk = $conn->prepare("
          SELECT id, txn_type
          FROM mutabakat_rows
          WHERE matched_ticket_id = ?
            AND source = 'TICKET'
          LIMIT 1
        ");
        if (!$chk) throw new Exception("Prepare mutabakat_rows check fail: ".$conn->error);

        $chk->bind_param("i", $ticketId);
        $chk->execute();
        $chk->bind_result($mid, $mtype);

        if ($chk->fetch()) {
          $mtype = strtoupper(trim((string)$mtype));
          if ($mtype !== $expectedType) {
            $chk->close();
            json_out(['ok'=>false,'error'=>'Mutabakat tipi iş akışı ile uyumsuz. (Legacy)']);
          }
          $found = true;
        }
        $chk->close();
      }
    }

    if (!$found) {
      json_out(['ok'=>false,'error'=>'Mutabakat kaydı olmadan ticket kapanamaz. (Kayıt bulunamadı)']);
    }
  }

  /* TRANSACTION */
  $conn->begin_transaction();

  try {
    // 1) Mesaj ekle (varsa)
    if ($hasMessage) {
      $insMsg = $conn->prepare("
        INSERT INTO ticket_messages (ticket_id, sender_user_id, sender_agency_id, message, created_at)
        VALUES (?, ?, ?, ?, NOW())
      ");
      if (!$insMsg) throw new Exception("Prepare fail ticket_messages: ".$conn->error);
      $insMsg->bind_param("iiis", $ticketId, $userId, $agencyId, $message);
      if (!$insMsg->execute()) throw new Exception("Insert msg fail: ".$insMsg->error);
      $insMsg->close();
    }

    // 2) Ticket kapat
    $up = $conn->prepare("
      UPDATE tickets
      SET status='closed',
          updated_at=NOW(),
          closed_at=NOW(),
          closed_by_user_id=?
      WHERE id=?
      LIMIT 1
    ");
    if (!$up) throw new Exception("Prepare fail close: ".$conn->error);
    $up->bind_param("ii", $userId, $ticketId);
    if (!$up->execute()) throw new Exception("Close update fail: ".$up->error);

    $affected = (int)$up->affected_rows;
    $up->close();
    if ($affected <= 0) {
      throw new Exception("Ticket kapanmadı (UPDATE affected_rows=0).");
    }

    // 3) Kapanınca iki tarafın da state’ini NOW yap
    $clear = $conn->prepare("
      INSERT INTO ticket_user_state (ticket_id, user_id, last_seen_at)
      SELECT ?, u.id, NOW()
      FROM users u
      WHERE u.agency_id IN (?, ?)
      ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)
    ");
    if ($clear) {
      $a1 = $createdByAgencyId;
      $a2 = $targetAgencyId;
      $clear->bind_param("iii", $ticketId, $a1, $a2);
      $clear->execute();
      $clear->close();
    }

    $conn->commit();

  } catch (Throwable $e2) {
    $conn->rollback();
    throw $e2;
  }

  $redirect = (string)($_POST['redirect'] ?? '');
  if ($redirect === '1') {
    json_out(['ok'=>true,'redirect_url'=> base_url('tickets.php')]);
  }

  json_out(['ok'=>true]);

} catch (Throwable $e) {
  if ($conn) { try { $conn->rollback(); } catch(Throwable $x){} }
  error_log("ajax-ticket-close.php EX: ".$e->getMessage());
  json_out(['ok'=>false,'error'=> $debug ? $e->getMessage() : 'Sunucu hatası' ], 500);
}
