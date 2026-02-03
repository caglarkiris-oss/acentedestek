<?php
// /platform/ajax-ticket-mutabakat-save.php
// Mutabakat V2 - Ticket mutabakat kaydi

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_mutabakat_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$debug = (bool) config('app.debug', false);

// Auth
ensure_session();
if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Yetkisiz']);
    exit;
}

$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$ALLOWED = ['ACENTE_YETKILISI', 'TALI_ACENTE_YETKILISI', 'PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) {
    echo json_encode(['ok' => false, 'error' => 'Yetkisiz']);
    exit;
}

/* ---------------- helpers ---------------- */
function clean($s): string { return trim((string)$s); }

function norm_money($v): string {
    $v = clean($v);
    if ($v === '') return '';
    $v = str_replace([' ', "\t", "\n", "\r"], '', $v);
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return $v;
}

function normalize_policy_no(string $s): string {
    $s = strtoupper(trim($s));
    if ($s === '') return '';
    $s = preg_replace('/[\s\-\_\/\.]+/u', '', $s);
    $s = preg_replace('/[^A-Z0-9]+/u', '', $s);
    return $s ?: '';
}

function find_or_create_period(mysqli $conn, int $anaAcenteId, string $tanzimTarihi): int {
    $ts = strtotime($tanzimTarihi);
    if (!$ts) return 0;
    $year  = (int)date('Y', $ts);
    $month = (int)date('m', $ts);

    $pid = 0;
    $q = $conn->prepare("SELECT id FROM mutabakat_v2_periods WHERE ana_acente_id=? AND year=? AND month=? LIMIT 1");
    if ($q) {
        $q->bind_param("iii", $anaAcenteId, $year, $month);
        $q->execute();
        $q->bind_result($pid);
        $q->fetch();
        $q->close();
    }
    if ($pid > 0) return (int)$pid;

    $ins = $conn->prepare("INSERT INTO mutabakat_v2_periods (ana_acente_id, year, month, status, created_at) VALUES (?, ?, ?, 'OPEN', NOW())");
    if (!$ins) return 0;
    $ins->bind_param("iii", $anaAcenteId, $year, $month);
    if (!$ins->execute()) { $ins->close(); return 0; }
    $ins->close();
    return (int)$conn->insert_id;
}

/* ---------------- INPUT ---------------- */
$ticketId        = (int)($_POST['ticket_id'] ?? 0);
$mutType         = strtoupper(clean($_POST['mutabakat_type'] ?? ''));
$tc_vn           = clean($_POST['tc_vn'] ?? '');
$sigortali_adi   = clean($_POST['sigortali_adi'] ?? '');
$tanzim_tarihi   = clean($_POST['tanzim_tarihi'] ?? '');
$sigorta_sirketi = clean($_POST['sigorta_sirketi'] ?? '');
$brans           = clean($_POST['brans'] ?? '');
$police_no       = clean($_POST['police_no'] ?? '');
$plaka           = clean($_POST['plaka'] ?? '');
$brut_prim_norm  = norm_money($_POST['brut_prim'] ?? '');

if ($ticketId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Gecersiz ticket']);
    exit;
}

if (!in_array($mutType, ['SATIS', 'IPTAL', 'ZEYIL'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Mutabakat tipi gecersiz']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanzim_tarihi)) {
    echo json_encode(['ok' => false, 'error' => 'Tanzim tarihi formati hatali (YYYY-MM-DD)']);
    exit;
}

if ($brut_prim_norm === '' || !is_numeric($brut_prim_norm)) {
    echo json_encode(['ok' => false, 'error' => 'Brut prim gecersiz']);
    exit;
}

$brutVal = (float)$brut_prim_norm;
$plakaDb = ($plaka === '') ? null : $plaka;

try {
    // Ticket bilgisi cek (tickets tablosu mevcutsa)
    $ticketExists = false;
    $createdByAgencyId = 0;
    $targetAgencyId = 0;
    $ticketStatus = '';
    $workflowStatus = '';
    $mutabakatSavedAt = null;

    $tableCheck = $conn->query("SHOW TABLES LIKE 'tickets'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $st = $conn->prepare("SELECT id, status, workflow_status, created_by_agency_id, target_agency_id, mutabakat_saved_at
                              FROM tickets WHERE id=? LIMIT 1");
        if ($st) {
            $st->bind_param("i", $ticketId);
            $st->execute();
            $st->bind_result($tid, $ticketStatus, $workflowStatus, $createdByAgencyId, $targetAgencyId, $mutabakatSavedAt);
            if ($st->fetch()) {
                $ticketExists = true;
            }
            $st->close();
        }
    }

    if (!$ticketExists) {
        // Ticket tablosu yoksa veya ticket bulunamadiysa, dogrudan kayit olustur
        // Bu durumda agency bilgilerini session'dan al
        $createdByAgencyId = $agencyId;
        $targetAgencyId = get_main_agency_id($conn, $agencyId);
    }

    if ($ticketExists && (string)$ticketStatus === 'closed') {
        echo json_encode(['ok' => false, 'error' => 'Ticket kapali']);
        exit;
    }

    // Mutabakati ticketi acan taraf (tali) kaydeder
    if ($ticketExists && (int)$createdByAgencyId !== (int)$agencyId) {
        echo json_encode(['ok' => false, 'error' => 'Mutabakati sadece ticketi acan taraf kaydedebilir.']);
        exit;
    }

    // 1 kere kaydet (kilit)
    if (!empty($mutabakatSavedAt)) {
        $found = null;
        $q = $conn->prepare("SELECT id, policy_no, tanzim_tarihi, brut_prim, txn_type, sigorta_sirketi, brans, tc_vn, sigortali_adi, plaka
                             FROM mutabakat_v2_rows WHERE ticket_id=? AND source_type='TICKET' LIMIT 1");
        if ($q) {
            $q->bind_param("i", $ticketId);
            $q->execute();
            $res = $q->get_result();
            if ($res && ($r = $res->fetch_assoc())) {
                $found = $r;
            }
            $q->close();
        }
        echo json_encode(['ok' => true, 'already_saved' => true, 'locked' => true, 'data' => $found]);
        exit;
    }

    // workflow -> expected type kontrol
    if ($ticketExists) {
        $WF = strtoupper(trim((string)$workflowStatus));
        $expected = '';
        if ($WF === 'SATIS_YAPILDI') $expected = 'SATIS';
        elseif ($WF === 'IPTAL') $expected = 'IPTAL';
        elseif ($WF === 'ZEYIL') $expected = 'ZEYIL';

        if ($expected !== '' && $mutType !== $expected) {
            echo json_encode(['ok' => false, 'error' => "Mutabakat tipi uyusmuyor. Beklenen: $expected"]);
            exit;
        }
    }

    // MAIN = target, TALI = createdBy
    $mainAgencyId = (int)$targetAgencyId;
    $taliAgencyId = (int)$createdByAgencyId;

    if ($mainAgencyId <= 0) {
        $mainAgencyId = get_main_agency_id($conn, $taliAgencyId);
    }

    $policyNorm = normalize_policy_no($police_no);
    if ($policyNorm === '') $policyNorm = _policy_clean($police_no) ?? $police_no;

    // Period
    $periodId = find_or_create_period($conn, $mainAgencyId, $tanzim_tarihi);
    if ($periodId <= 0) {
        throw new Exception("Period bulunamadi/olusturulamadi");
    }

    $rowStatus = 'HAVUZ';
    $currency = 'TRY';

    $conn->begin_transaction();

    // mutabakat_v2_rows insert (ticket kaydi)
    $sql = "INSERT INTO mutabakat_v2_rows
        (period_id, ana_acente_id, tali_acente_id, tc_vn, source_type, ticket_id, import_batch_id,
         policy_no, policy_no_norm, txn_type, sigortali_adi, tanzim_tarihi, sigorta_sirketi, brans, plaka,
         brut_prim, currency, row_status, locked, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'TICKET', ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())";

    $ins = $conn->prepare($sql);
    if (!$ins) throw new Exception("Prepare mutabakat_v2_rows fail: " . $conn->error);

    $ins->bind_param(
        "iiisissssssssdssi",
        $periodId,
        $mainAgencyId,
        $taliAgencyId,
        $tc_vn,
        $ticketId,
        $policyNorm,
        $policyNorm,
        $mutType,
        $sigortali_adi,
        $tanzim_tarihi,
        $sigorta_sirketi,
        $brans,
        $plakaDb,
        $brutVal,
        $currency,
        $rowStatus,
        $userId
    );

    if (!$ins->execute()) {
        $err = $ins->error;
        $ins->close();
        throw new Exception("Execute mutabakat_v2_rows fail: " . $err);
    }
    $ins->close();

    // tickets tablosu varsa guncelle
    if ($ticketExists) {
        $saleAmount = $brutVal;
        $upT = $conn->prepare("UPDATE tickets SET
            policy_number=?, policy_no_norm=?, sale_amount=?,
            mutabakat_saved_at=NOW(), mutabakat_type=?, mutabakat_tanzim=?, updated_at=NOW()
            WHERE id=? LIMIT 1");
        if ($upT) {
            $upT->bind_param("ssdssi", $police_no, $policyNorm, $saleAmount, $mutType, $tanzim_tarihi, $ticketId);
            $upT->execute();
            $upT->close();
        }
    }

    $conn->commit();

    echo json_encode(['ok' => true, 'saved' => true, 'period_id' => $periodId]);
    exit;

} catch (Throwable $e) {
    if ($conn && $conn->errno === 0) {
        // ignore
    }
    try { @$conn->rollback(); } catch (Throwable $x) {}

    error_log("ajax-ticket-mutabakat-save.php EX: " . $e->getMessage());

    if ($debug) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Islem sirasinda hata olustu']);
    }
    exit;
}
