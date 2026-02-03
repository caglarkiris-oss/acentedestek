<?php
// /public_html/platform/mutabakat/detay.php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

/* =======================
   ERROR LOG
======================= */
ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_mutabakat_detay_error.log');
error_reporting(E_ALL);

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../db.php';

if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) { header("Location: ".base_url("login.php")); exit; }
}

$conn = db();
if (!$conn) { http_response_code(500); exit('DB yok'); }

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function valid_period($p): bool { return (bool)preg_match('/^\d{4}\-\d{2}$/', (string)$p); }
function this_month(): string { return date('Y-m'); }

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

/* =======================
   AUTH (MAIN + TALI)
======================= */
$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$isMain = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
$isTali = in_array($role, ['TALI_ACENTE_YETKILISI','PERSONEL'], true);

$taliId = (int)($_GET['tali_id'] ?? 0);
$period = trim((string)($_GET['period'] ?? ''));

if ($taliId <= 0) { http_response_code(400); exit('tali_id gerekli'); }
if (!valid_period($period)) $period = this_month();

/*
  Kurallar:
  - Main: bağlı olduğu tali'ye bakabilir (parent kontrolü)
  - Tali: sadece kendi tali'sine bakabilir
*/
$taliName = '';
$mainOfTali = 0;
$workModeUp = 'TICKET';

$st = $conn->prepare("SELECT id,name,parent_id,work_mode FROM agencies WHERE id=? LIMIT 1");
if (!$st) { http_response_code(500); exit('Agencies sorgu yok'); }
$st->bind_param("i",$taliId);
$st->execute();
$r = $st->get_result()->fetch_assoc();
$st->close();
if (!$r) { http_response_code(404); exit('Tali bulunamadı'); }

$taliName   = (string)($r['name'] ?? '');
$mainOfTali = (int)($r['parent_id'] ?? 0);
$wm         = strtoupper(trim((string)($r['work_mode'] ?? 'TICKET')));
$workModeUp = in_array($wm, ['TICKET','CSV'], true) ? $wm : 'TICKET';

if ($isMain) {
  if ($role !== 'SUPERADMIN' && $mainOfTali !== $agencyId) {
    http_response_code(403); exit('Bu tali size bağlı değil');
  }
} elseif ($isTali) {
  if ($agencyId !== $taliId && $agencyId !== $mainOfTali) {
    http_response_code(403); exit('Yetkisiz');
  }
} else {
  http_response_code(403); exit('Yetkisiz');
}

/* =======================
   CSRF
======================= */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrfToken = (string)$_SESSION['csrf_token'];

/* =======================
   MODE
======================= */
$mode = $workModeUp; // TICKET veya CSV

/* =======================
   KİLİT (mutabakat_periods)
======================= */
$hasPeriodsTable = table_exists($conn, 'mutabakat_periods');
$periodStatus = '';
$isLocked = false;

if ($hasPeriodsTable) {
  try {
    $mainIdForLock = $isMain ? ($role === 'SUPERADMIN' ? $mainOfTali : $agencyId) : $mainOfTali;

    $stL = $conn->prepare("
      SELECT status
      FROM mutabakat_periods
      WHERE main_agency_id=? AND assigned_agency_id=? AND period=?
      LIMIT 1
    ");
    if ($stL) {
      $stL->bind_param("iis", $mainIdForLock, $taliId, $period);
      $stL->execute();
      $lr = $stL->get_result()->fetch_assoc();
      $stL->close();
      $periodStatus = (string)($lr['status'] ?? '');
      $isLocked = ($periodStatus === 'OPEN');
    }
  } catch(Throwable $e){}
}

/* =======================
   SUBMISSION STATUS (görüntü)
======================= */
$subStatus = '';
$subSentAt = '';

try {
  $st = $conn->prepare("
    SELECT status, sent_at
    FROM mutabakat_submissions
    WHERE main_agency_id=? AND tali_agency_id=? AND period=? AND mode=?
    ORDER BY id DESC
    LIMIT 1
  ");
  if ($st) {
    $mainIdForSub = $isMain ? ($role === 'SUPERADMIN' ? $mainOfTali : $agencyId) : $mainOfTali;
    $st->bind_param("iiss", $mainIdForSub, $taliId, $period, $mode);
    $st->execute();
    $rr = $st->get_result()->fetch_assoc();
    $st->close();
    if ($rr) {
      $subStatus = strtoupper(trim((string)($rr['status'] ?? '')));
      $subSentAt = (string)($rr['sent_at'] ?? '');
    }
  }
} catch(Throwable $e){}

$subLabel = 'Gönderilmedi';
$subCls   = 's-pill s-muted';
if ($subStatus === 'SENT')     { $subLabel = 'Gönderildi'; $subCls='s-pill s-sent'; }
if ($subStatus === 'APPROVED') { $subLabel = 'Onaylandı';  $subCls='s-pill s-ok'; }
if ($subStatus === 'DISPUTED') { $subLabel = 'İtiraz';     $subCls='s-pill s-warn'; }

/* =======================
   PAYMENT STATUS (görüntü)
======================= */
$payStatus = '';
try {
  $mainIdForPay = $isMain ? ($role === 'SUPERADMIN' ? $mainOfTali : $agencyId) : $mainOfTali;

  $stP = $conn->prepare("
    SELECT status
    FROM mutabakat_payments
    WHERE main_agency_id=? AND tali_agency_id=? AND period=?
    ORDER BY id DESC
    LIMIT 1
  ");
  if ($stP) {
    $stP->bind_param("iis", $mainIdForPay, $taliId, $period);
    $stP->execute();
    $pr = $stP->get_result()->fetch_assoc();
    $stP->close();
    if ($pr) $payStatus = strtoupper(trim((string)($pr['status'] ?? '')));
  }
} catch(Throwable $e){}

$payLabel = '';
$payCls   = '';
if ($payStatus === 'PENDING') {
  $payLabel = '⏳ Onay Bekliyor';
  $payCls = 'paypill pending';
} elseif ($payStatus === 'APPROVED') {
  $payLabel = '✅ Onaylandı';
  $payCls = 'paypill approved';
} elseif ($payStatus === 'REJECTED') {
  $payLabel = '⛔ İtiraz';
  $payCls = 'paypill rejected';
} elseif ($payStatus === 'PAID') {
  $payLabel = '💙 Ödendi';
  $payCls = 'paypill paid';
}

/* =======================
   ROWS (MODE AWARE)
   - CSV mode: mutabakat_csv_rows_cache
   - TICKET mode: mutabakat_rows
======================= */
$rows = [];
$fileId = 0;

$mainIdForRows = $isMain ? ($role === 'SUPERADMIN' ? $mainOfTali : $agencyId) : $mainOfTali;

function pick_col_expr(mysqli $conn, string $table, array $cands, string $fallbackExpr): string {
  foreach ($cands as $c) {
    if (column_exists($conn, $table, $c)) return "`{$c}`";
  }
  return $fallbackExpr;
}

try {
  if ($mode === 'CSV') {
    // CSV -> active file + cache rows
    $stF = $conn->prepare("
      SELECT id
      FROM mutabakat_main_csv_files
      WHERE main_agency_id=? AND tali_id=? AND period=? AND is_active=1
      ORDER BY id DESC
      LIMIT 1
    ");
    if ($stF) {
      $stF->bind_param("iis", $mainIdForRows, $taliId, $period);
      $stF->execute();
      $rf = $stF->get_result()->fetch_assoc();
      $stF->close();
      $fileId = (int)($rf['id'] ?? 0);
    }

    if ($fileId > 0) {
      $st = $conn->prepare("
        SELECT
          id,
          tc_vn,
          sigortali,
          plaka,
          sirket,
          brans,
          tip,
          tanzim,
          police_no,
          net_komisyon,
          match_status,
          match_reason
        FROM mutabakat_csv_rows_cache
        WHERE file_id=? AND main_agency_id=? AND tali_agency_id=? AND period=?
        ORDER BY id ASC
        LIMIT 5000
      ");
      if ($st) {
        $st->bind_param("iiis", $fileId, $mainIdForRows, $taliId, $period);
        $st->execute();
        $res = $st->get_result();
        while($row = $res->fetch_assoc()) $rows[] = $row;
        $st->close();
      }
    }

  } else {
    // TICKET -> mutabakat_rows
    $TBL = 'mutabakat_rows';
    if (table_exists($conn, $TBL)) {

      // where columns (farklı isimlere tolerans)
      $colMain   = pick_col_expr($conn, $TBL, ['main_agency_id','main_id'], "NULL");
      $colTali   = pick_col_expr($conn, $TBL, ['assigned_agency_id','tali_agency_id','assigned_id'], "NULL");
      $colPeriod = pick_col_expr($conn, $TBL, ['period'], "NULL");
      $colCA     = pick_col_expr($conn, $TBL, ['created_at','createdAt','created'], "NOW()");

      // select columns (farklı isimlere tolerans)
      $c_tc   = pick_col_expr($conn, $TBL, ['tc_vn','tc','tcvn','identity_no'], "''");
      $c_sig  = pick_col_expr($conn, $TBL, ['sigortali','insured_name','insured','customer_name','name'], "''");
      $c_plk  = pick_col_expr($conn, $TBL, ['plate','plaka'], "''");
      $c_sir  = pick_col_expr($conn, $TBL, ['sirket','company','company_name','insurer','insurer_name'], "''");
      $c_br   = pick_col_expr($conn, $TBL, ['brans','branch','branch_name','line'], "''");
      $c_tip  = pick_col_expr($conn, $TBL, ['tip','txn_type','type'], "''");
      $c_tan  = pick_col_expr($conn, $TBL, ['tanzim','issue_date','issued_at'], "NULL");
      $c_pol  = pick_col_expr($conn, $TBL, ['police_no','policy_no','policy_no_raw','policy_no_norm'], "''");
      $c_net  = pick_col_expr($conn, $TBL, ['net_komisyon','net_commission','commission_net','net','net_amount'], "'0'");
      $c_ms   = pick_col_expr($conn, $TBL, ['match_status'], "'MATCHED'"); // yoksa varsayılan
      $c_mr   = pick_col_expr($conn, $TBL, ['match_reason'], "''");

      // period filter: period kolonu yoksa created_at'ten filtrele
      $wherePeriodSql = "";
      if ($colPeriod !== "NULL") {
        $wherePeriodSql = " AND {$colPeriod} = ? ";
      } else {
        $wherePeriodSql = " AND DATE_FORMAT({$colCA}, '%Y-%m') = ? ";
      }

      // main/tali columns yoksa yine de kırmayalım -> ancak bu durumda filtre işe yaramaz; gene de koşalım
      $sql = "
        SELECT
          id,
          {$c_tc}  AS tc_vn,
          {$c_sig} AS sigortali,
          {$c_plk} AS plaka,
          {$c_sir} AS sirket,
          {$c_br}  AS brans,
          {$c_tip} AS tip,
          {$c_tan} AS tanzim,
          {$c_pol} AS police_no,
          {$c_net} AS net_komisyon,
          {$c_ms}  AS match_status,
          {$c_mr}  AS match_reason
        FROM {$TBL}
        WHERE 1=1
      ";

      if ($colMain !== "NULL") $sql .= " AND {$colMain} = ? ";
      if ($colTali !== "NULL") $sql .= " AND {$colTali} = ? ";
      $sql .= $wherePeriodSql;
      $sql .= " ORDER BY {$colCA} ASC, id ASC LIMIT 5000 ";

      $st = $conn->prepare($sql);
      if ($st) {
        // bind params (dinamik)
        $types = "";
        $params = [];

        if ($colMain !== "NULL") { $types .= "i"; $params[] = $mainIdForRows; }
        if ($colTali !== "NULL") { $types .= "i"; $params[] = $taliId; }
        $types .= "s"; $params[] = $period;

        // mysqli bind_param için referans dizisi
        $bind = [];
        $bind[] = $types;
        foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
        call_user_func_array([$st, 'bind_param'], $bind);

        $st->execute();
        $res = $st->get_result();
        while($row = $res->fetch_assoc()) $rows[] = $row;
        $st->close();
      }
    }
  }

} catch(Throwable $e){
  error_log("rows err: ".$e->getMessage());
}

/* =======================
   VISIBILITY RULE
======================= */
$canSeeTable = true;
if ($isTali) {
  $canSeeTable = in_array($subStatus, ['SENT','APPROVED','DISPUTED'], true);
}

/* =======================
   MAIN SEND BUTTON STATE
   - CSV: cache varsa gönder
   - TICKET: mutabakat_rows varsa gönder
======================= */
$sendDisabled = false;
$sendHint = '';

if (!$isMain) {
  $sendDisabled = true;
} else {
  if ($isLocked) {
    $sendDisabled = true;
    $sendHint = 'Bu dönem mutabakat gönderildi (kilitli).';
  } else {
    if (empty($rows)) {
      $sendDisabled = true;
      $sendHint = ($mode === 'CSV')
        ? 'Bu dönem için aktif CSV/cache kaydı yok.'
        : 'Bu dönem için ticket satırı yok.';
    }
  }
}

/* =======================
   INLINE EDIT PERMISSIONS
   - CSV: fileId>0 ve satır var
   - TICKET: satır var (fileId gerekmez)
======================= */
$canInlineEdit = false;
if ($isMain && !($hasPeriodsTable && $isLocked) && $canSeeTable) {
  if ($mode === 'CSV') $canInlineEdit = ($fileId > 0 && !empty($rows));
  else $canInlineEdit = (!empty($rows));
}

require_once __DIR__.'/../layout/header.php';
?>

<style>
/* Layout */
.wrap{max-width:1200px;margin:16px auto 40px;padding:0 18px;}
.card2{padding:16px;border:1px solid rgba(15,23,42,.10);border-radius:18px;background:#fff;}
.head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.title{font-size:20px;font-weight:900;margin:0;}
.sub{margin-top:6px;opacity:.8;font-size:13px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.right{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid rgba(15,23,42,.12);background:#fff;font-weight:700;font-size:13px;text-decoration:none;color:inherit;cursor:pointer;}
.btn:hover{background:#f8fafc;}
.btn-green{background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.25);color:#065f46;}
.btn-green:hover{background:rgba(16,185,129,.14);}
.btn:disabled{opacity:.55;cursor:not-allowed;}
.hr{margin:14px 0;border:none;border-top:1px solid rgba(15,23,42,.10);}

/* Status pill */
.s-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;border:1px solid rgba(15,23,42,.12);font-size:12px;background:#fff;}
.s-muted{background:#f8fafc;border-color:rgba(15,23,42,.10);opacity:.9}
.s-sent{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35);color:#92400e;}
.s-ok{background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.30);color:#065f46;}
.s-warn{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.30);color:#7f1d1d;}
.s-lock{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.25);color:#7f1d1d;}
.s-open{background:#f8fafc;border-color:rgba(15,23,42,.10);opacity:.9}

/* Payment pill */
.paypill{display:inline-flex;align-items:center;gap:7px;padding:5px 10px;border-radius:999px;border:1px solid rgba(15,23,42,.12);font-size:12px;background:#fff;}
.paypill.pending{background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.30);color:#92400e;}
.paypill.approved{background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.30);color:#065f46;}
.paypill.rejected{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.30);color:#7f1d1d;}
.paypill.paid{background:rgba(59,130,246,.10);border-color:rgba(59,130,246,.30);color:#1e3a8a;}

/* Table */
.table-wrap{overflow:auto;border:1px solid rgba(15,23,42,.10);border-radius:16px;}
table{width:100%;border-collapse:separate;border-spacing:0;background:#fff;}
th,td{padding:10px;font-size:13px;border-bottom:1px solid #eef2f7;white-space:nowrap;font-weight:400;vertical-align:middle;}
thead th{background:#fbfcfe;font-weight:700;position:sticky;top:0;z-index:1;}
tbody tr:hover td{background:#fafcff}
td.money{text-align:right}
td.center{text-align:center}
thead th{ text-align:left; }
thead th.center{ text-align:center; }
thead th.money{ text-align:right; }

/* Kopyalama */
th.copyable{cursor:pointer;position:relative;user-select:none}
th.copyable::after{
  content:"Kopyala";
  position:absolute;
  bottom:-26px; left:10px;
  font-size:11px;
  padding:5px 10px;
  border-radius:999px;
  background:#0b1220;
  color:#fff;
  opacity:0;
  transition:.12s;
  pointer-events:none;
}
th.copyable:hover::after{opacity:1}

/* Badge */
.badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;font-size:11px;font-weight:700;padding:5px 10px;line-height:1;}
.badge-source{background:#f8fafc;border:1px solid #e2e8f0;color:#0f172a;}
.cell-center{display:flex;justify-content:center;align-items:center;}
.badge-txn{width:72px;padding:5px 0;border:1px solid transparent;}
.badge-txn.SATIS{background:#ecfdf3;border-color:#a7f3d0;color:#065f46;}
.badge-txn.ZEYIL{background:#fffbeb;border-color:#fde68a;color:#92400e;}
.badge-txn.IPTAL{background:#fff1f2;border-color:#fecdd3;color:#9f1239;}
.badge-txn.OTHER{background:#f8fafc;border-color:#e2e8f0;color:#0f172a;}
.badge-match.ok{background:#ecfdf3;border:1px solid #a7f3d0;color:#065f46;}
.badge-match.no{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;}
.badge-match.warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);color:#92400e;}

/* Empty state */
.empty{
  padding:18px;border:1px dashed rgba(15,23,42,.18);border-radius:16px;
  background:linear-gradient(180deg, rgba(15,23,42,.02), rgba(15,23,42,.00));
  font-size:13px;opacity:.9;
}

/* INLINE EDIT */
.editable{
  position:relative;
  outline:none;
  border-radius:10px;
}
.edit-mode .editable{
  box-shadow: inset 0 0 0 1px rgba(59,130,246,.18);
  background: rgba(59,130,246,.05);
  cursor:text;
}
.edit-mode .editable:hover{
  box-shadow: inset 0 0 0 1px rgba(59,130,246,.28);
}
.editing{
  box-shadow: inset 0 0 0 2px rgba(16,185,129,.35) !important;
  background: rgba(16,185,129,.06) !important;
}
.edit-error{
  box-shadow: inset 0 0 0 2px rgba(239,68,68,.35) !important;
  background: rgba(239,68,68,.06) !important;
}
.toast{
  position:fixed;
  right:16px;
  bottom:16px;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid rgba(15,23,42,.12);
  background:#fff;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  font-size:13px;
  opacity:0;
  transform:translateY(8px);
  transition:.15s;
  z-index:9999;
}
.toast.show{opacity:1;transform:translateY(0);}
</style>

<main class="page">
  <div class="wrap">
    <div class="card2">
      <div class="head">
        <div>
          <h1 class="title">Mutabakat Detay (<?= $isMain ? 'Main' : 'Tali' ?> Görünüm)</h1>
          <div class="sub">
            <?= h($taliName) ?> — <?= h($period) ?> (<?= h($mode) ?>)
            <span class="<?= h($subCls) ?>" id="subStatusPill"><?= h($subLabel) ?></span>

            <?php if (!empty($payLabel)): ?>
              <span class="<?= h($payCls) ?>" id="payStatusPill"><?= h($payLabel) ?></span>
            <?php endif; ?>

            <?php if ($hasPeriodsTable): ?>
              <?php if ($isLocked): ?>
                <span class="s-pill s-lock" id="lockPill">Mutabakat Gönderildi • Kilitli</span>
              <?php else: ?>
                <span class="s-pill s-open" id="lockPill">Mutabakat Kapalı</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="right">
          <?php if ($canInlineEdit): ?>
            <button class="btn" type="button" id="btnToggleEdit">Düzenleme Modu: Kapalı</button>
          <?php endif; ?>

          <?php if ($isMain): ?>
            <?php if ($hasPeriodsTable && $isLocked): ?>
              <button class="btn btn-green" type="button" disabled>Mutabakat Gönderildi</button>
            <?php else: ?>
              <button class="btn btn-green" type="button" id="btnSendPeriodTicket" <?= $sendDisabled ? 'disabled' : '' ?>>
                Mutabakat Gönder
              </button>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($isMain && $sendHint): ?>
        <div style="margin-top:10px;opacity:.75;font-size:12px;"><?= h($sendHint) ?></div>
      <?php endif; ?>

      <hr class="hr">

      <?php if (!$canSeeTable): ?>
        <div class="empty">
          Bu dönem için mutabakat henüz <strong>gönderilmedi</strong>.
          Ana acente gönderdiğinde burada detaylar görünecek.
        </div>
      <?php elseif (empty($rows)): ?>
        <div class="empty">
          Bu dönem için <strong>kayıt yok</strong>.
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table id="mutabakatTable">
            <thead>
              <tr>
                <th class="copyable" data-col="0">ID</th>
                <th class="copyable center" data-col="1">Kaynak</th>
                <th class="copyable" data-col="2">TC / VN</th>
                <th class="copyable" data-col="3">Sigortalı</th>
                <th class="copyable" data-col="4">Plaka</th>
                <th class="copyable" data-col="5">Şirket</th>
                <th class="copyable" data-col="6">Branş</th>
                <th class="copyable center" data-col="7">Tip</th>
                <th class="copyable" data-col="8">Tanzim</th>
                <th class="copyable" data-col="9">Poliçe No</th>
                <th class="copyable money" data-col="10">Net</th>
                <th class="copyable center" data-col="11">Eşleşme</th>
              </tr>
            </thead>

            <tbody>
            <?php foreach($rows as $rr):
              $txnRaw = strtoupper(trim((string)($rr['tip'] ?? '')));
              $txn = in_array($txnRaw, ['SATIS','ZEYIL','IPTAL'], true) ? $txnRaw : 'OTHER';

              $ms = strtoupper(trim((string)($rr['match_status'] ?? '')));
              $badgeText = 'Eşleşmedi';
              $badgeCls  = 'no';
              if ($ms === 'MATCHED') { $badgeText = 'Eşleşti'; $badgeCls='ok'; }
              elseif ($ms === 'CHECK') { $badgeText = 'Kontrol'; $badgeCls='warn'; }

              $netVal = (string)($rr['net_komisyon'] ?? '0');
              $netNum = (float)str_replace([',',' '], ['.',''], $netVal);
            ?>
              <tr data-row-id="<?= (int)$rr['id'] ?>">
                <td><?= (int)$rr['id'] ?></td>
                <td class="center"><span class="badge badge-source"><?= h($mode) ?></span></td>

                <td class="editable" data-field="tc_vn"><?= h((string)($rr['tc_vn'] ?? '')) ?></td>
                <td class="editable" data-field="sigortali"><?= h((string)($rr['sigortali'] ?? '')) ?></td>
                <td class="editable" data-field="plaka"><?= h((string)($rr['plaka'] ?? '')) ?></td>
                <td class="editable" data-field="sirket"><?= h((string)($rr['sirket'] ?? '')) ?></td>
                <td class="editable" data-field="brans"><?= h((string)($rr['brans'] ?? '')) ?></td>

                <td class="editable center" data-field="tip">
                  <div class="cell-center">
                    <span class="badge badge-txn <?= h($txn) ?>">
                      <?= h($txn === 'OTHER' ? (string)($rr['tip'] ?? '—') : $txn) ?>
                    </span>
                  </div>
                </td>

                <td class="editable" data-field="tanzim"><?= h((string)($rr['tanzim'] ?? '')) ?></td>
                <td class="editable" data-field="police_no"><?= h((string)($rr['police_no'] ?? '')) ?></td>
                <td class="editable money" data-field="net_komisyon"><?= number_format($netNum, 2, ',', '.') ?></td>

                <td class="center">
                  <span class="badge badge-match <?= h($badgeCls) ?>">
                    <?= h($badgeText) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <div class="toast" id="toast"></div>
</main>

<script>
(function(){
  const canInlineEdit = <?= $canInlineEdit ? 'true' : 'false' ?>;
  const csrf = '<?= h($csrfToken) ?>';
  const taliId = <?= (int)$taliId ?>;
  const period = '<?= h($period) ?>';

  const table = document.getElementById('mutabakatTable');
  const btnEdit = document.getElementById('btnToggleEdit');
  const toast = document.getElementById('toast');

  function showToast(msg){
    if(!toast) return;
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(()=>toast.classList.remove('show'), 1500);
  }

  function normalizeText(s){
    return (s || '').replace(/\u00a0/g,' ').replace(/\s+\n/g,'\n').trim();
  }

  // COPY
  function getColumnText(colIndex){
    if (!table) return '';
    const rows = table.querySelectorAll('tbody tr');
    const values = [];
    rows.forEach(tr=>{
      const tds = tr.querySelectorAll('td');
      if (!tds || tds.length <= colIndex) return;
      if (tds.length === 1) return;
      const cell = tds[colIndex];
      let text = '';
      const badge = cell.querySelector('.badge');
      if (badge) text = badge.textContent;
      else text = cell.textContent;
      text = normalizeText(text);
      if (text !== '') values.push(text);
    });
    return values.join('\n');
  }
  async function copyToClipboard(text){
    if (!text) return false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch(e){}
    try{
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      ta.style.top = '-9999px';
      document.body.appendChild(ta);
      ta.focus(); ta.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return ok;
    } catch(e){
      return false;
    }
  }
  if (table){
    const ths = table.querySelectorAll('thead th.copyable');
    ths.forEach(th=>{
      th.addEventListener('click', async ()=>{
        const col = parseInt(th.getAttribute('data-col') || '0', 10);
        const text = getColumnText(col);
        await copyToClipboard(text);
        showToast('Kopyalandı');
      });
    });
  }

  // INLINE EDIT TOGGLE
  let editMode = false;
  function setEditMode(on){
    editMode = !!on;
    document.body.classList.toggle('edit-mode', editMode);
    if(btnEdit) btnEdit.textContent = 'Düzenleme Modu: ' + (editMode ? 'Açık' : 'Kapalı');

    if(!table) return;
    table.querySelectorAll('td.editable').forEach(td=>{
      td.setAttribute('contenteditable', editMode ? 'true' : 'false');
      td.setAttribute('spellcheck', 'false');
      if(editMode){
        if(!td.dataset.orig) td.dataset.orig = normalizeText(td.textContent);
      } else {
        td.classList.remove('editing','edit-error');
      }
    });
  }

  if (canInlineEdit && btnEdit){
    btnEdit.addEventListener('click', ()=> setEditMode(!editMode));
  }

  // SAVE CELL
  async function saveCell(tr, td){
    const rowId = parseInt(tr.getAttribute('data-row-id') || '0', 10);
    const field = td.getAttribute('data-field') || '';
    if(!rowId || !field) return;

    let newValue = normalizeText(td.textContent);
    const oldValue = (td.dataset.orig ?? '');

    if(newValue === oldValue) return;

    td.classList.add('editing');
    td.classList.remove('edit-error');

    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('tali_id', String(taliId));
    fd.append('period', period);
    fd.append('row_id', String(rowId));
    fd.append('field', field);
    fd.append('value', newValue);

    try{
      const res = await fetch('<?= h(base_url("mutabakat/ajax-edit-cell.php")) ?>', {
        method:'POST',
        credentials:'same-origin',
        body: fd
      });
      const js = await res.json().catch(()=>null);

      if(!res.ok || !js || js.ok !== true){
        td.classList.remove('editing');
        td.classList.add('edit-error');
        showToast((js && js.message) ? js.message : 'Kayıt hatası');
        td.textContent = oldValue;
        return;
      }

      if(typeof js.display !== 'undefined'){
        td.textContent = js.display;
      }
      td.dataset.orig = normalizeText(td.textContent);
      td.classList.remove('editing');
      showToast('Kaydedildi');

      if(field === 'tip'){
        setTimeout(()=>location.reload(), 250);
      }

    } catch(e){
      td.classList.remove('editing');
      td.classList.add('edit-error');
      showToast('İstek atılamadı');
      td.textContent = oldValue;
    }
  }

  if(table){
    table.addEventListener('keydown', (ev)=>{
      if(!editMode) return;
      const td = ev.target && ev.target.closest ? ev.target.closest('td.editable') : null;
      if(!td) return;

      if(ev.key === 'Enter'){
        ev.preventDefault();
        const tr = td.closest('tr');
        if(tr) saveCell(tr, td);
        td.blur();
      } else if(ev.key === 'Escape'){
        ev.preventDefault();
        const oldValue = (td.dataset.orig ?? '');
        td.textContent = oldValue;
        td.blur();
      }
    });

    table.addEventListener('blur', (ev)=>{
      if(!editMode) return;
      const td = ev.target && ev.target.closest ? ev.target.closest('td.editable') : null;
      if(!td) return;
      const tr = td.closest('tr');
      if(tr) saveCell(tr, td);
    }, true);
  }

  // SEND PERIOD
  const btnSend = document.getElementById('btnSendPeriodTicket');
  if(btnSend){
    btnSend.addEventListener('click', async ()=>{
      if(btnSend.disabled) return;

      const fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('tali_id', String(taliId));
      fd.append('period', period);

      const old = btnSend.textContent;
      btnSend.disabled = true;
      btnSend.textContent = 'Gönderiliyor...';

      try{
        const res = await fetch('<?= h(base_url("mutabakat/ajax-send-period.php")) ?>', {
          method:'POST',
          credentials:'same-origin',
          body: fd
        });
        const js = await res.json().catch(()=>null);

        if(!res.ok || !js || js.ok !== true){
          alert((js && js.message) ? js.message : 'Gönderme hatası');
          btnSend.disabled = false;
          btnSend.textContent = old;
          return;
        }
        location.reload();
      } catch(e){
        alert('İstek atılamadı');
        btnSend.disabled = false;
        btnSend.textContent = old;
      }
    });
  }

  setEditMode(false);
})();
</script>

<?php require_once __DIR__.'/../layout/footer.php'; ?>
