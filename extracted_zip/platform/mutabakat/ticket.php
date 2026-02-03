<?php
// /public_html/platform/mutabakat/ticket.php
// ✅ TEK PARÇA (Main + Tali uyumlu) + Period + UI (Gönder/Kapalı-Açık) + AJAX SEND
// ✅ OPEN ise buton PASİF + "Mutabakat Gönderildi" butonu disabled olsa bile YEŞİL (csv.php ile aynı dil)
// ✅ EK: Durum metinleri: Gönderildi / Kapandı / Onay / Ödeme (mutabakat_payments + submissions + periods)

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_mutabakat_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../db.php';

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) { header("Location: ".base_url("login.php")); exit; }
}

$conn = db();
if (!$conn) { http_response_code(500); exit('DB yok'); }

$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$isMain = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
$isTali = ($role === 'TALI_ACENTE_YETKILISI');
if (!$isMain && !$isTali) { http_response_code(403); exit('Yetkisiz'); }

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function money_tr($n): string {
  if ($n === null || $n === '') return '0,00';
  return number_format((float)$n, 2, ',', '.');
}
function signed_money_tr($n): string {
  $f = (float)$n;
  $sign = ($f > 0) ? '+' : (($f < 0) ? '-' : '');
  return $sign . money_tr(abs($f));
}
function valid_period($p): bool { return (bool)preg_match('/^\d{4}\-\d{2}$/', (string)$p); }
function this_month(): string { return date('Y-m'); }

/* ---------- SAFE DB HELPERS ---------- */
function table_exists(mysqli $conn, string $table): bool {
  try {
    $dbRow = $conn->query("SELECT DATABASE() AS db");
    $db = $dbRow ? (($dbRow->fetch_assoc()['db'] ?? '') ?: '') : '';
    if (!$db) return false;
    $st = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=? LIMIT 1");
    if (!$st) return false;
    $st->bind_param("ss", $db, $table);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_assoc();
    $st->close();
    return $ok;
  } catch(Throwable $e) { return false; }
}
function column_exists(mysqli $conn, string $table, string $col): bool {
  try {
    $dbRow = $conn->query("SELECT DATABASE() AS db");
    $db = $dbRow ? (($dbRow->fetch_assoc()['db'] ?? '') ?: '') : '';
    if (!$db) return false;
    $st = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=? LIMIT 1");
    if (!$st) return false;
    $st->bind_param("sss", $db, $table, $col);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_assoc();
    $st->close();
    return $ok;
  } catch(Throwable $e) { return false; }
}

$hasPeriodsTable = table_exists($conn, 'mutabakat_periods');
$hasRowsPeriod   = column_exists($conn, 'mutabakat_rows', 'period');

$hasSubmissions  = table_exists($conn, 'mutabakat_submissions');
$hasPayments     = table_exists($conn, 'mutabakat_payments');

/* ---------- PERIOD ---------- */
$selectedPeriod = (string)($_GET['period'] ?? '');
if (!valid_period($selectedPeriod)) $selectedPeriod = this_month();

/* Period options + tali için main bul */
$periodOptions = [];
$mainAgencyIdForTali = 0;

try {
  if ($hasPeriodsTable) {
    if ($isMain) {
      $st = $conn->prepare("
        SELECT DISTINCT period
        FROM mutabakat_periods
        WHERE main_agency_id=?
        ORDER BY period DESC
        LIMIT 24
      ");
      if ($st) {
        $st->bind_param("i", $agencyId);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) $periodOptions[] = (string)$r['period'];
        $st->close();
      }
    } else {
      $st0 = $conn->prepare("SELECT parent_id FROM agencies WHERE id=? LIMIT 1");
      if ($st0) {
        $st0->bind_param("i", $agencyId);
        $st0->execute();
        $mainAgencyIdForTali = (int)(($st0->get_result()->fetch_assoc()['parent_id'] ?? 0));
        $st0->close();

        if ($mainAgencyIdForTali > 0) {
          $st = $conn->prepare("
            SELECT DISTINCT period
            FROM mutabakat_periods
            WHERE main_agency_id=? AND assigned_agency_id=?
            ORDER BY period DESC
            LIMIT 24
          ");
          if ($st) {
            $st->bind_param("ii", $mainAgencyIdForTali, $agencyId);
            $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) $periodOptions[] = (string)$r['period'];
            $st->close();
          }
        }
      }
    }
  } else {
    if ($isMain) {
      $st = $conn->prepare("
        SELECT DISTINCT DATE_FORMAT(COALESCE(updated_at, created_at), '%Y-%m') AS p
        FROM mutabakat_rows
        WHERE source='TICKET' AND main_agency_id=?
        ORDER BY p DESC
        LIMIT 24
      ");
      if ($st) {
        $st->bind_param("i", $agencyId);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) $periodOptions[] = (string)$r['p'];
        $st->close();
      }
    } else {
      $st0 = $conn->prepare("SELECT parent_id FROM agencies WHERE id=? LIMIT 1");
      if ($st0) {
        $st0->bind_param("i", $agencyId);
        $st0->execute();
        $mainAgencyIdForTali = (int)(($st0->get_result()->fetch_assoc()['parent_id'] ?? 0));
        $st0->close();

        if ($mainAgencyIdForTali > 0) {
          $st = $conn->prepare("
            SELECT DISTINCT DATE_FORMAT(COALESCE(updated_at, created_at), '%Y-%m') AS p
            FROM mutabakat_rows
            WHERE source='TICKET' AND main_agency_id=? AND assigned_agency_id=?
            ORDER BY p DESC
            LIMIT 24
          ");
          if ($st) {
            $st->bind_param("ii", $mainAgencyIdForTali, $agencyId);
            $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) $periodOptions[] = (string)$r['p'];
            $st->close();
          }
        }
      }
    }
  }
} catch(Throwable $e){}

if (empty($periodOptions)) $periodOptions = [$selectedPeriod];
if (!in_array($selectedPeriod, $periodOptions, true)) array_unshift($periodOptions, $selectedPeriod);

/* ---------- LIST DATA ---------- */
$talis = [];

try {
  $periodWhere = $hasRowsPeriod
    ? "mr.period = ?"
    : "DATE_FORMAT(COALESCE(mr.updated_at, mr.created_at), '%Y-%m') = ?";

  if ($isMain) {
    $sql = "
      SELECT
        a.id, a.name, a.slug, a.is_active, a.commission_rate, a.work_mode,
        COALESCE(s.c_satis, 0) AS c_satis,
        COALESCE(s.c_iptal, 0) AS c_iptal,
        COALESCE(s.c_zeyil, 0) AS c_zeyil,
        COALESCE(s.sum_satis, 0) AS sum_satis,
        COALESCE(s.sum_iptal, 0) AS sum_iptal,
        COALESCE(s.sum_zeyil, 0) AS sum_zeyil,
        COALESCE(s.sum_total, 0) AS sum_total,
        COALESCE(s.last_row_at, '') AS last_row_at
      FROM agencies a
      LEFT JOIN (
        SELECT
          mr.assigned_agency_id,
          SUM(CASE WHEN mr.txn_type='SATIS' THEN 1 ELSE 0 END) AS c_satis,
          SUM(CASE WHEN mr.txn_type='IPTAL' THEN 1 ELSE 0 END) AS c_iptal,
          SUM(CASE WHEN mr.txn_type='ZEYIL' THEN 1 ELSE 0 END) AS c_zeyil,
          SUM(CASE WHEN mr.txn_type='SATIS' THEN mr.gross_premium ELSE 0 END) AS sum_satis,
          SUM(CASE WHEN mr.txn_type='IPTAL' THEN mr.gross_premium ELSE 0 END) AS sum_iptal,
          SUM(CASE WHEN mr.txn_type='ZEYIL' THEN mr.gross_premium ELSE 0 END) AS sum_zeyil,
          SUM(mr.gross_premium) AS sum_total,
          MAX(COALESCE(mr.updated_at, mr.created_at)) AS last_row_at
        FROM mutabakat_rows mr
        WHERE mr.source='TICKET'
          AND mr.main_agency_id=?
          AND ($periodWhere)
        GROUP BY mr.assigned_agency_id
      ) s ON s.assigned_agency_id = a.id
      WHERE a.parent_id = ?
        AND a.work_mode = 'ticket'
      ORDER BY a.name ASC
    ";
    $st = $conn->prepare($sql);
    if (!$st) throw new Exception("Prepare failed: ".$conn->error);
    $st->bind_param("isi", $agencyId, $selectedPeriod, $agencyId);
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) $talis[] = $row;
    $st->close();
  } else {
    $st0 = $conn->prepare("SELECT id, parent_id, name, slug, is_active, commission_rate, work_mode FROM agencies WHERE id=? LIMIT 1");
    if (!$st0) throw new Exception("Prepare failed: ".$conn->error);
    $st0->bind_param("i", $agencyId);
    $st0->execute();
    $me = $st0->get_result()->fetch_assoc();
    $st0->close();

    if ($me) {
      $mainAgencyIdForTali = (int)($me['parent_id'] ?? 0);

      if ((string)($me['work_mode'] ?? '') === 'ticket') {
        $sql = "
          SELECT
            a.id, a.name, a.slug, a.is_active, a.commission_rate, a.work_mode,
            COALESCE(s.c_satis, 0) AS c_satis,
            COALESCE(s.c_iptal, 0) AS c_iptal,
            COALESCE(s.c_zeyil, 0) AS c_zeyil,
            COALESCE(s.sum_satis, 0) AS sum_satis,
            COALESCE(s.sum_iptal, 0) AS sum_iptal,
            COALESCE(s.sum_zeyil, 0) AS sum_zeyil,
            COALESCE(s.sum_total, 0) AS sum_total,
            COALESCE(s.last_row_at, '') AS last_row_at
          FROM agencies a
          LEFT JOIN (
            SELECT
              mr.assigned_agency_id,
              SUM(CASE WHEN mr.txn_type='SATIS' THEN 1 ELSE 0 END) AS c_satis,
              SUM(CASE WHEN mr.txn_type='IPTAL' THEN 1 ELSE 0 END) AS c_iptal,
              SUM(CASE WHEN mr.txn_type='ZEYIL' THEN 1 ELSE 0 END) AS c_zeyil,
              SUM(CASE WHEN mr.txn_type='SATIS' THEN mr.gross_premium ELSE 0 END) AS sum_satis,
              SUM(CASE WHEN mr.txn_type='IPTAL' THEN mr.gross_premium ELSE 0 END) AS sum_iptal,
              SUM(CASE WHEN mr.txn_type='ZEYIL' THEN mr.gross_premium ELSE 0 END) AS sum_zeyil,
              SUM(mr.gross_premium) AS sum_total,
              MAX(COALESCE(mr.updated_at, mr.created_at)) AS last_row_at
            FROM mutabakat_rows mr
            WHERE mr.source='TICKET'
              AND mr.main_agency_id=?
              AND mr.assigned_agency_id=?
              AND ($periodWhere)
            GROUP BY mr.assigned_agency_id
          ) s ON s.assigned_agency_id = a.id
          WHERE a.id = ?
          LIMIT 1
        ";
        $st = $conn->prepare($sql);
        if (!$st) throw new Exception("Prepare failed: ".$conn->error);
        $st->bind_param("iisi", $mainAgencyIdForTali, $agencyId, $selectedPeriod, $agencyId);
        $st->execute();
        $rs = $st->get_result();
        while ($row = $rs->fetch_assoc()) $talis[] = $row;
        $st->close();
      }
    }
  }
} catch (Throwable $e) {
  error_log("mutabakat/ticket.php list err: ".$e->getMessage());
}

/* ---------- STATUS MAP (periods) ---------- */
$statusMap = []; // [taliId => 'OPEN'|'CLOSED']
if ($hasPeriodsTable) {
  try {
    if ($isMain) {
      $st = $conn->prepare("
        SELECT assigned_agency_id, status
        FROM mutabakat_periods
        WHERE main_agency_id=? AND period=?
      ");
      if ($st) {
        $st->bind_param("is", $agencyId, $selectedPeriod);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) $statusMap[(int)$r['assigned_agency_id']] = (string)$r['status'];
        $st->close();
      }
    } else {
      if ($mainAgencyIdForTali > 0) {
        $st = $conn->prepare("
          SELECT status
          FROM mutabakat_periods
          WHERE main_agency_id=? AND assigned_agency_id=? AND period=?
          LIMIT 1
        ");
        if ($st) {
          $st->bind_param("iis", $mainAgencyIdForTali, $agencyId, $selectedPeriod);
          $st->execute();
          $rs = $st->get_result();
          if ($r = $rs->fetch_assoc()) $statusMap[$agencyId] = (string)$r['status'];
          $st->close();
        }
      }
    }
  } catch(Throwable $e){}
}

/* ---------- SUBMISSIONS + PAYMENTS MAP (durum yazıları için) ---------- */
$subMap = []; // [taliId => 'SENT'|'APPROVED'|'DISPUTED'...]
$payMap = []; // [taliId => 'PENDING'|'APPROVED'|'REJECTED'|'PAID']

try {
  $mainIdForMaps = $isMain ? $agencyId : (int)$mainAgencyIdForTali;

  // submissions
  if ($hasSubmissions && $mainIdForMaps > 0) {
    $sqlS = "
      SELECT s.tali_agency_id, UPPER(TRIM(s.status)) AS st
      FROM mutabakat_submissions s
      INNER JOIN (
        SELECT tali_agency_id, MAX(id) AS mid
        FROM mutabakat_submissions
        WHERE main_agency_id=? AND period=? AND mode='TICKET'
        GROUP BY tali_agency_id
      ) x ON x.tali_agency_id = s.tali_agency_id AND x.mid = s.id
      WHERE s.main_agency_id=? AND s.period=? AND s.mode='TICKET'
    ";
    $st = $conn->prepare($sqlS);
    if ($st) {
      $st->bind_param("isis", $mainIdForMaps, $selectedPeriod, $mainIdForMaps, $selectedPeriod);
      $st->execute();
      $rs = $st->get_result();
      while ($r = $rs->fetch_assoc()) $subMap[(int)$r['tali_agency_id']] = (string)($r['st'] ?? '');
      $st->close();
    }
  }

  // payments
  if ($hasPayments && $mainIdForMaps > 0) {
    $sqlP = "
      SELECT p.tali_agency_id, UPPER(TRIM(p.status)) AS st
      FROM mutabakat_payments p
      INNER JOIN (
        SELECT tali_agency_id, MAX(id) AS mid
        FROM mutabakat_payments
        WHERE main_agency_id=? AND period=?
        GROUP BY tali_agency_id
      ) x ON x.tali_agency_id = p.tali_agency_id AND x.mid = p.id
      WHERE p.main_agency_id=? AND p.period=?
    ";
    $st = $conn->prepare($sqlP);
    if ($st) {
      $st->bind_param("isis", $mainIdForMaps, $selectedPeriod, $mainIdForMaps, $selectedPeriod);
      $st->execute();
      $rs = $st->get_result();
      while ($r = $rs->fetch_assoc()) $payMap[(int)$r['tali_agency_id']] = (string)($r['st'] ?? '');
      $st->close();
    }
  }

} catch(Throwable $e) {
  error_log("mutabakat/ticket.php maps err: ".$e->getMessage());
}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
  .muta-wrap{max-width:1100px;margin:16px auto 40px;padding:0 18px;}
  .muta-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;}
  .muta-title{font-size:18px;font-weight:400;margin:0;}
  .muta-grid{display:grid;gap:12px;margin-top:14px;}
  .mrow{padding:14px;border:1px solid rgba(15,23,42,.10);border-radius:16px;background:#fff;}
  .mrow-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
  .name{font-weight:700;font-size:14px;}
  .meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;}
  .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid rgba(15,23,42,.10);background:#f8fafc;font-size:12px;font-weight:400;}
  .pill.muted{opacity:.75;font-weight:400;}
  .pill.ok{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.22);color:#065f46;}
  .pill.bad{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.22);color:#7f1d1d;}

  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid rgba(15,23,42,.12);background:#fff;font-weight:400;font-size:13px;text-decoration:none;color:inherit;cursor:pointer;}
  .btn:hover{background:#f8fafc;}
  .btn[disabled]{opacity:.55;cursor:not-allowed;}

  .btn-blue{background: rgba(37,99,235,.10);border-color: rgba(37,99,235,.28);color:#1e3a8a;}
  .btn-blue:hover{ background: rgba(37,99,235,.14); }

  /* ✅ Yeşil Gönder (csv ile aynı) */
  .btn-success{
    background: rgba(16,185,129,.15) !important;
    border-color: rgba(16,185,129,.45) !important;
    color:#065f46 !important;
  }
  .btn-success:hover{ background: rgba(16,185,129,.22) !important; }

  /* ✅ Disabled olsa bile YEŞİL kalsın */
  .btn-success.is-sent,
  .btn-success.is-approved,
  .btn-success.is-paid,
  .btn-success[disabled]{
    background: rgba(16,185,129,.15) !important;
    border-color: rgba(16,185,129,.45) !important;
    color:#065f46 !important;
    opacity:1 !important;
    cursor:not-allowed;
  }

  /* ✅ Onay bekliyor (amber) */
  .btn-wait{
    background: rgba(245,158,11,.14) !important;
    border-color: rgba(245,158,11,.40) !important;
    color:#92400e !important;
  }
  .btn-wait:hover{ background: rgba(245,158,11,.18) !important; }
  .btn-wait[disabled]{opacity:1 !important;}

  /* ✅ İtiraz (kırmızı) */
  .btn-reject{
    background: rgba(239,68,68,.12) !important;
    border-color: rgba(239,68,68,.35) !important;
    color:#7f1d1d !important;
  }
  .btn-reject:hover{ background: rgba(239,68,68,.16) !important; }
  .btn-reject[disabled]{opacity:1 !important;}

  .sumline{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px;}
  .chip{display:inline-flex;align-items:center;justify-content:space-between;gap:10px;min-width:220px;padding:10px 12px;border-radius:14px;border:1px solid rgba(15,23,42,.10);background:#fff;}
  .chip .k{font-size:12px;font-weight:400;opacity:.9;letter-spacing:.2px;}
  .chip .v{font-size:13px;font-weight:400;}
  .chip.green{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#065f46;}
  .chip.red{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.25);color:#7f1d1d;}
  .chip.yellow{background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.28);color:#78350f;}
  .chip.total{background:rgba(37,99,235,.08);border-color:rgba(37,99,235,.22);color:#1e3a8a;min-width:260px;}
  .top-tools{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;justify-content:flex-end;}
  .select{height:34px;border-radius:12px;border:1px solid rgba(15,23,42,.12);padding:0 10px;background:#fff;font-weight:400;font-size:13px;}

  .ui-toast-wrap{position:fixed;right:18px;top:18px;z-index:99999;display:flex;flex-direction:column;gap:10px;}
  .ui-toast{min-width:280px;max-width:420px;background:rgba(255,255,255,.92);border:1px solid rgba(15,23,42,.12);border-radius:14px;box-shadow:0 18px 45px rgba(15,23,42,.18);padding:12px 12px;backdrop-filter: blur(8px);}
  .ui-toast .t{font-weight:600;font-size:13px;margin:0;color:#0f172a;}
  .ui-toast .m{font-size:12px;margin-top:6px;opacity:.85;line-height:1.35;}
  .ui-toast .x{float:right;border:none;background:transparent;cursor:pointer;font-size:16px;opacity:.6}
  .ui-toast .x:hover{opacity:1}
  .ui-toast.ok{border-color:rgba(16,185,129,.22)}
  .ui-toast.err{border-color:rgba(239,68,68,.22)}
</style>

<main class="page">
  <div class="muta-wrap">
    <div class="card">
      <div class="muta-head">
        <div>
          <h1 class="muta-title">Mutabakat Ticket</h1>
          <div class="meta" style="margin-top:10px;">
            <span class="pill muted">Dönem: <?= h($selectedPeriod) ?></span>
            <?php if (!$hasPeriodsTable): ?>
              <span class="pill muted">Not: mutabakat_periods yok (gönder pasif)</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="top-tools">
          <form method="get" action="<?= h(base_url('mutabakat/ticket.php')) ?>">
            <select class="select" name="period" onchange="this.form.submit()">
              <?php foreach ($periodOptions as $p): ?>
                <option value="<?= h($p) ?>" <?= ($p === $selectedPeriod) ? 'selected' : '' ?>><?= h($p) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      </div>

      <hr style="margin:14px 0; border:none; border-top:1px solid rgba(15,23,42,.10);">

      <?php if (empty($talis)): ?>
        <div style="border:1px dashed rgba(15,23,42,.20);background:#fff;border-radius:16px;padding:14px;">
          <div><?= $isMain ? 'Ticket ile çalışan tali bulunamadı.' : 'Ticket verisi bulunamadı.' ?></div>
        </div>
      <?php else: ?>
        <div class="muta-grid">
          <?php foreach ($talis as $t): ?>
            <?php
              $tid   = (int)($t['id'] ?? 0);
              $tname = (string)($t['name'] ?? ('Tali #'.$tid));
              $active = (int)($t['is_active'] ?? 1);

              $cS = (int)($t['c_satis'] ?? 0);
              $cI = (int)($t['c_iptal'] ?? 0);
              $cZ = (int)($t['c_zeyil'] ?? 0);

              $sumS = (float)($t['sum_satis'] ?? 0);
              $sumI = (float)($t['sum_iptal'] ?? 0);
              $sumZ = (float)($t['sum_zeyil'] ?? 0);
              $sumT = (float)($t['sum_total'] ?? 0);

              $lastAt = (string)($t['last_row_at'] ?? '');
              $detailHref = base_url('mutabakat/detay.php?tali_id='.$tid.'&period='.$selectedPeriod);

              // ✅ Period status pill (Açık/Kapalı)
              $stt = (string)($statusMap[$tid] ?? '');
              if ($stt === 'OPEN') {
                $pillHtml = '<span class="pill ok" id="status-pill-'.$tid.'">Mutabakat Açık • '.h($selectedPeriod).'</span>';
              } else {
                $pillHtml = '<span class="pill muted" id="status-pill-'.$tid.'">Mutabakat Kapalı • '.h($selectedPeriod).'</span>';
              }

              // ✅ Bu butonun gösterimi (gönder aksiyonu) aynı kalsın:
              $isOpen = ($hasPeriodsTable && $stt === 'OPEN');
              $canSendBtn = ($hasPeriodsTable && ($isMain || ($isTali && $tid === $agencyId)));

              // ✅ EK: submissions/payments durumuna göre sağ buton yazısı
              $subSt = strtoupper(trim((string)($subMap[$tid] ?? '')));
              $paySt = strtoupper(trim((string)($payMap[$tid] ?? '')));

              // Varsayılan
              $btnText  = $isOpen ? 'Mutabakat Gönderildi' : 'Mutabakat Gönder';
              $btnClass = 'btn btn-success js-send-btn';
              $btnDisabled = $isOpen ? true : false;

              // Eğer ödeme tarafı varsa, onu önceliklendir
              if ($paySt === 'PAID') {
                $btnText = 'Ödeme Yapıldı';
                $btnClass = 'btn btn-success is-paid';
                $btnDisabled = true;
              } elseif ($paySt === 'APPROVED') {
                $btnText = 'Onay Verildi';
                $btnClass = 'btn btn-success is-approved';
                $btnDisabled = true;
              } elseif ($paySt === 'REJECTED') {
                $btnText = 'İtiraz Edildi';
                $btnClass = 'btn btn-reject';
                $btnDisabled = true;
              } elseif ($paySt === 'PENDING') {
                $btnText = 'Onay Bekliyor';
                $btnClass = 'btn btn-wait';
                $btnDisabled = true; // tali onayı beklerken burada “gönder” tekrar basılmasın
              } else {
                // Payment yoksa: gönderildiyse/sub sent ise göster
                if ($isOpen || $subSt === 'SENT' || $subSt === 'APPROVED' || $subSt === 'DISPUTED') {
                  $btnText = 'Mutabakat Gönderildi';
                  $btnClass = 'btn btn-success is-sent';
                  $btnDisabled = true;
                }
              }

              // ✅ “gönder” butonu gerçekten basılabilir olsun istiyorsak:
              // Yalnızca: periods var + main/tali yetkili + OPEN değil + payment yok/boş (yukarıda disabled değil)
              $sendClickable = ($canSendBtn && !$btnDisabled);

              // Eğer clickable ise js hook lazım
              if ($sendClickable) {
                // js-send-btn sınıfı kalsın
                $btnClass = 'btn btn-success js-send-btn';
              }
            ?>
            <div class="mrow">
              <div class="mrow-top">
                <div>
                  <div class="name"><?= h($tname) ?></div>
                  <div class="meta">
                    <span class="pill muted">ID: <?= (int)$tid ?></span>
                    <span class="pill muted"><?= $active ? 'Aktif' : 'Pasif' ?></span>
                    <?= $pillHtml ?>
                    <span class="pill muted">Son kayıt: <?= $lastAt ? h($lastAt) : '—' ?></span>
                  </div>
                </div>

                <div style="display:flex;gap:10px;align-items:center;">
                  <?php if ($canSendBtn): ?>
                    <button
                      class="<?= h($btnClass) ?>"
                      type="button"
                      data-tali="<?= (int)$tid ?>"
                      <?= $btnDisabled ? 'disabled' : '' ?>
                    ><?= h($btnText) ?></button>
                  <?php endif; ?>

                  <a class="btn btn-blue" href="<?= h($detailHref) ?>">Detay</a>
                </div>
              </div>

              <div class="sumline">
                <div class="chip green"><div><div class="k">SATIŞ</div><div class="v"><?= $cS ?> adet <?= signed_money_tr($sumS) ?></div></div></div>
                <div class="chip yellow"><div><div class="k">ZEYİL</div><div class="v"><?= $cZ ?> adet <?= signed_money_tr($sumZ) ?></div></div></div>
                <div class="chip red"><div><div class="k">İPTAL</div><div class="v"><?= $cI ?> adet <?= signed_money_tr($sumI) ?></div></div></div>
                <div class="chip total"><div><div class="k">TOPLAM BRÜT</div><div class="v"><?= signed_money_tr($sumT) ?></div></div></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <input type="hidden" id="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" id="selected_period" value="<?= h($selectedPeriod) ?>">
    </div>
  </div>
</main>

<div class="ui-toast-wrap" id="ui_toasts"></div>

<script>
function uiToast(type, title, message, ms=2400){
  const wrap = document.getElementById('ui_toasts');
  if(!wrap) return;

  const el = document.createElement('div');
  el.className = 'ui-toast ' + (type || '');
  el.innerHTML = `
    <button class="x" type="button">×</button>
    <div class="t"></div>
    <div class="m"></div>
  `;
  el.querySelector('.t').textContent = title || '';
  el.querySelector('.m').textContent = message || '';
  el.querySelector('.x').onclick = ()=> el.remove();

  wrap.appendChild(el);
  setTimeout(()=>{ try{ el.remove(); }catch(e){} }, ms);
}

async function sendMutabakatFor(taliId, btnEl){
  const csrf   = document.getElementById('csrf_token')?.value || '';
  const period = document.getElementById('selected_period')?.value || '';

  const fd = new FormData();
  fd.append('csrf_token', csrf);
  fd.append('period', period);
  fd.append('tali_id', String(taliId || '0'));

  const oldText = btnEl ? btnEl.textContent : '';
  if(btnEl){ btnEl.disabled = true; btnEl.textContent = 'Gönderiliyor...'; }

  try{
    const res = await fetch("<?= h(base_url('mutabakat/ajax-send-period.php')) ?>", {
      method: "POST",
      credentials: "same-origin",
      body: fd
    });

    const txt = await res.text();
    let data = null; try{ data = JSON.parse(txt); }catch(e){}

    if(!data || data.ok !== true){
      uiToast('err','İşlem başarısız', (data && data.message) ? data.message : (txt || 'Hata'));
      if(btnEl){ btnEl.disabled = false; btnEl.textContent = oldText; }
      return;
    }

    // ✅ Period pill OPEN'a döner (iş akışı aynı)
    const pill = document.getElementById('status-pill-' + String(taliId));
    if(pill){
      pill.classList.remove('muted','bad');
      pill.classList.add('ok');
      pill.textContent = 'Mutabakat Açık • ' + period;
    }

    // ✅ Buton: Gönderildi (bu noktada payment henüz oluşmayabilir)
    if(btnEl){
      btnEl.textContent = 'Mutabakat Gönderildi';
      btnEl.classList.add('is-sent');
      btnEl.classList.remove('btn-wait','btn-reject');
      btnEl.classList.add('btn-success');
      btnEl.disabled = true;
    }

    uiToast('ok','Gönderildi','Mutabakat Açık yapıldı.');
  } catch(e){
    uiToast('err','Sunucu hatası','İstek başarısız (500/Network)');
    if(btnEl){ btnEl.disabled = false; btnEl.textContent = oldText; }
  }
}

// ✅ SADECE tıklanabilir olanlara bağla
document.querySelectorAll('.js-send-btn').forEach(btn=>{
  if(btn.disabled) return;
  btn.addEventListener('click', ()=> sendMutabakatFor(btn.dataset.tali, btn));
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
