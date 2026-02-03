<?php
// /platform/ajax-ticket-close.php
// Mutabakat V2 - Ticket kapatma (mutabakat kontrolu ile)

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_close_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$debug = (bool) config('app.debug', false);

function json_out($arr, int $code = 200): void {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

ensure_session();
if (empty($_SESSION['user_id'])) {
    json_out(['ok' => false, 'error' => 'Yetkisiz'], 401);
}

$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$ALLOWED = ['ACENTE_YETKILISI', 'TALI_ACENTE_YETKILISI', 'PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) {
    json_out(['ok' => false, 'error' => 'Yetkisiz'], 403);
}

function clean_str($s): string { return trim((string)$s); }

/* INPUT */
$ticketId = (int)($_POST['ticket_id'] ?? $_POST['id'] ?? $_POST['ticket'] ?? 0);
if ($ticketId <= 0) {
    json_out(['ok' => false, 'error' => 'Gecersiz ticket']);
}

$messageRaw = $_POST['message'] ?? $_POST['reply_message'] ?? $_POST['msg'] ?? $_POST['text'] ?? '';
$messageRaw = clean_str($messageRaw);
$hasMessage = ($messageRaw !== '' && $messageRaw !== '-');
$message    = $messageRaw;

try {
    // Ticket tablosu var mi kontrol et
    $tableCheck = $conn->query("SHOW TABLES LIKE 'tickets'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        json_out(['ok' => false, 'error' => 'Tickets tablosu bulunamadi']);
    }

    /* Ticket kontrol */
    $st = $conn->prepare("SELECT id, created_by_agency_id, target_agency_id, status, workflow_status
                          FROM tickets WHERE id=? LIMIT 1");
    if (!$st) throw new Exception("Prepare fail tickets: " . $conn->error);

    $st->bind_param("i", $ticketId);
    $st->execute();
    $st->bind_result($tid, $createdByAgencyId, $targetAgencyId, $status, $wf);
    if (!$st->fetch()) {
        $st->close();
        json_out(['ok' => false, 'error' => 'Ticket bulunamadi']);
    }
    $st->close();

    $createdByAgencyId = (int)$createdByAgencyId;
    $targetAgencyId    = (int)$targetAgencyId;
    $status            = (string)$status;
    $WF                = strtoupper(trim((string)$wf));

    if ($createdByAgencyId !== $agencyId) {
        json_out(['ok' => false, 'error' => 'Ticketi sadece acan acente kapatabilir.']);
    }
    if ($status === 'closed') {
        json_out(['ok' => false, 'error' => 'Ticket zaten kapali.']);
    }

    /* Mutabakat zorunlulugu: SATIS/IPTAL/ZEYIL akislarinda mutabakat kaydi aranir */
    $requiresMutabakat = in_array($WF, ['SATIS_YAPILDI', 'IPTAL', 'ZEYIL'], true);
    if ($requiresMutabakat) {
        $expectedType = ($WF === 'SATIS_YAPILDI') ? 'SATIS' : (($WF === 'IPTAL') ? 'IPTAL' : 'ZEYIL');
        $found = false;

        // V2 kontrol: mutabakat_v2_rows
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
                        json_out(['ok' => false, 'error' => 'Mutabakat tipi is akisi ile uyumsuz.']);
                    }
                }
                $chk2->close();
            }
        }

        if (!$found) {
            json_out(['ok' => false, 'error' => 'Mutabakat kaydi olmadan ticket kapanamazz.']);
        }
    }

    /* TRANSACTION */
    $conn->begin_transaction();

    try {
        // Mesaj ekle (varsa ve ticket_messages tablosu varsa)
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

        // Ticket kapat
        $up = $conn->prepare("UPDATE tickets SET status='closed', updated_at=NOW(), closed_at=NOW(), closed_by_user_id=? WHERE id=? LIMIT 1");
        if (!$up) throw new Exception("Prepare fail close: " . $conn->error);
        $up->bind_param("ii", $userId, $ticketId);
        if (!$up->execute()) throw new Exception("Close update fail: " . $up->error);

        $affected = (int)$up->affected_rows;
        $up->close();
        if ($affected <= 0) {
            throw new Exception("Ticket kapanmadi (UPDATE affected_rows=0).");
        }

        // ticket_user_state guncelle (varsa)
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
        json_out(['ok' => true, 'redirect_url' => base_url('tickets.php')]);
    }

    json_out(['ok' => true]);

} catch (Throwable $e) {
    try { @$conn->rollback(); } catch(Throwable $x) {}
    error_log("ajax-ticket-close.php EX: " . $e->getMessage());
    json_out(['ok' => false, 'error' => $debug ? $e->getMessage() : 'Sunucu hatasi'], 500);
}
