<?php
// /public_html/platform/mutabakat/csv.php
// ✅ TEK PARÇA (Main/Tali + Period + SEND + TEMPLATE)
// ✅ Özetler: mutabakat_rows'dan değil, CSV_DETAY satırlarından (mutabakat_tali_csv_files + mutabakat_tali_csv_rows) gelir
// ✅ "Mutabakat Gönderildi" butonu disabled olsa bile YEŞİL görünür (ticket ile aynı)
// ✅ EK: Durum metinleri: Gönderildi / Onay Bekliyor / Onay Verildi / İtiraz / Ödeme Yapıldı (payments + submissions)

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_mutabakat_csv_error.log');
error_reporting(E_ALL);

require_once __DIR__.'/../../auth.php';
require_once __DIR__.'/../../helpers.php';
require_once __DIR__.'/../../db.php';

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

$hasPeriodsTable = table_exists($conn, 'mutabakat_periods');
$hasTaliCsvFiles = table_exists($conn, 'mutabakat_tali_csv_files');
$hasTaliCsvRows  = table_exists($conn, 'mutabakat_tali_csv_rows');

$hasSubmissions  = table_exists($conn, 'mutabakat_submissions');
$hasPayments     = table_exists($conn, 'mutabakat_payments');

/* ==============================
   ✅ CSV TEMPLATE DOWNLOAD
============================== */
if (($_GET['download'] ?? '') === 'template') {
  $headers = ["T.C / V.N.","Sigortalı","Plaka","Şirket","Branş","Tip","Tanzim","Poliçe No","Brüt"];
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="mutabakat_template.csv"');
  header('Pragma: no-cache');
  header('Expires: 0');
  echo "\xEF\xBB\xBF"; // UTF-8 BOM
  $out = fopen('php://output', 'w');
  fputcsv($out, $headers, ';');
  fclose($out);
  exit;
}

/* ---------- tali ise main bul + work_mode ---------- */
$mainAgencyIdForTali = 0;
$meWorkMode = '';
if ($isTali) {
  try {
    $st0 = $conn->prepare("SELECT parent_id, work_mode FROM agencies WHERE id=? LIMIT 1");
    if ($st0) {
      $st0->bind_param("i", $agencyId);
      $st0->execute();
      $r = $st0->get_result()->fetch_assoc();
      $st0->close();
      $mainAgencyIdForTali = (int)($r['parent_id'] ?? 0);
      $meWorkMode = (string)($r['work_mode'] ?? '');
    }
  } catch(Throwable $e) {}
}

/* ---------- PERIOD ---------- */
$selectedPeriod = (string)($_GET['period'] ?? '');
if (!valid_period($selectedPeriod)) $selectedPeriod = this_month();

/* Period options */
$periodOptions = [];
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
        while ($row = $rs->fetch_assoc()) $periodOptions[] = (string)$row['period'];
        $st->close();
      }
    } else {
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
          while ($row = $rs->fetch_assoc()) $periodOptions[] = (string)$row['period'];
          $st->close();
        }
      }
    }
  } else {
    // periods table yoksa: CSV files tablosundan dönem çek
    if ($hasTaliCsvFiles) {
      if ($isMain) {
        $st = $conn->prepare("
          SELECT DISTINCT period
          FROM mutabakat_tali_csv_files
          WHERE main_agency_id=?
          ORDER BY period DESC
          LIMIT 24
        ");
        if ($st) {
          $st->bind_param("i", $agencyId);
          $st->execute();
          $rs = $st->get_result();
          while ($row = $rs->fetch_assoc()) $periodOptions[] = (string)$row['period'];
          $st->close();
        }
      } else {
        if ($mainAgencyIdForTali > 0) {
          $st = $conn->prepare("
            SELECT DISTINCT period
            FROM mutabakat_tali_csv_files
            WHERE main_agency_id=? AND tali_agency_id=?
            ORDER BY period DESC
            LIMIT 24
          ");
          if ($st) {
            $st->bind_param("ii", $mainAgencyIdForTali, $agencyId);
            $st->execute();
            $rs = $st->get_result();
            while ($row = $rs->fetch_assoc()) $periodOptions[] = (string)$row['period'];
            $st->close();
          }
        }
      }
    }
  }
} catch(Throwable $e) {}

if (empty($periodOptions)) $periodOptions = [$selectedPeriod];
if (!in_array($selectedPeriod, $periodOptions, true)) array_unshift($periodOptions, $selectedPeriod);

/* ---------- LIST DATA (Özet: active csv rows) ---------- */
$talis = [];
try {
  if (!$hasTaliCsvFiles || !$hasTaliCsvRows) {
    $talis = [];
  } else {
    if ($isMain) {
      $sql = "
        SELECT
          a.id, a.name, a.is_active, a.work_mode,
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
            f.tali_agency_id,
            SUM(CASE WHEN r.tip='SATIS' THEN 1 ELSE 0 END) AS c_satis,
            SUM(CASE WHEN r.tip='IPTAL' THEN 1 ELSE 0 END) AS c_iptal,
            SUM(CASE WHEN r.tip='ZEYIL' THEN 1 ELSE 0 END) AS c_zeyil,
            SUM(CASE WHEN r.tip='SATIS' THEN IFNULL(r.net_komisyon,0) ELSE 0 END) AS sum_satis,
            SUM(CASE WHEN r.tip='IPTAL' THEN IFNULL(r.net_komisyon,0) ELSE 0 END) AS sum_iptal,
            SUM(CASE WHEN r.tip='ZEYIL' THEN IFNULL(r.net_komisyon,0) ELSE 0 END) AS sum_zeyil,
            SUM(IFNULL(r.net_komisyon,0)) AS sum_total,
            MAX(IFNULL(r.tanzim, r.id)) AS last_row_at
          FROM mutabakat_tali_csv_files f
          LEFT JOIN mutabakat_tali_csv_rows r ON r.file_id=f.id
          WHERE f.main_agency_id=?
            AND f.period=?
            AND f.is_active=1
            AND f.deleted_at IS NULL
          GROUP BY f.tali_agency_id
        ) s ON s.tali_agency_id = a.id
        WHERE a.parent_id = ?
          AND a.work_mode='csv'
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
      if ($meWorkMode === 'csv' && $mainAgencyIdForTali > 0) {
        $sql = "
          SELECT
            a.id, a.name, a.is_active, a.work_mode,
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
              f.tali_agency_id,
              SUM(CASE WHEN r.tip='SATIS' THEN 1 ELSE 0 END) AS c_satis,
              SUM(CASE WHEN r.tip='IPTAL' THEN 1 ELSE 0 END) AS c_iptal,
              SUM(CASE WHEN r.tip='ZEYIL' THEN 1 ELSE 0 END) AS c_zeyil,
              SUM(CASE WHEN r.tip='SATIS' THEN IFNULL(r.net_komisyon,0) ELSE 0 END) AS sum_satis,
              SUM(CASE WHEN r.tip='IPTAL' THEN IFNULL(r.net_komisyon,0) ELSE 0 END) AS sum_iptal,
              SUM(CASE WHEN r.tip='ZEYIL' THEN IFNULL(r.net_komisyon,0) ELSE 0 END) AS sum_zeyil,
              SUM(IFNULL(r.net_komisyon,0)) AS sum_total,
              MAX(IFNULL(r.tanzim, r.id)) AS last_row_at
            FROM mutabakat_tali_csv_files f
            LEFT JOIN mutabakat_tali_csv_rows r ON r.file_id=f.id
            WHERE f.main_agency_id=?
              AND f.tali_agency_id=?
              AND f.period=?
              AND f.is_active=1
              AND f.deleted_at IS NULL
            GROUP BY f.tali_agency_id
          ) s ON s.tali_agency_id = a.id
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
} catch(Throwable $e) {
  error_log("mutabakat/csv.php list err: ".$e->getMessage());
}

/* ---------- STATUS MAP (OPEN/CLOSED) ---------- */
$statusMap = [];
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
  } catch(Throwable $e) {}
}

/* ---------- SUBMISSIONS + PAYMENTS MAP (durum yazıları için) ---------- */
$subMap = []; // [taliId => SENT|APPROVED|DISPUTED...]
$payMap = []; // [taliId => PENDING|APPROVED|REJECTED|PAID]

try {
  $mainIdForMaps = $isMain ? $agencyId : (int)$mainAgencyIdForTali;

  // submissions (mode=CSV)
  if ($hasSubmissions && $mainIdForMaps > 0) {
    $sqlS = "
      SELECT s.tali_agency_id, UPPER(TRIM(s.status)) AS st
      FROM mutabakat_submissions s
      INNER JOIN (
        SELECT tali_agency_id, MAX(id) AS mid
        FROM mutabakat_submissions
        WHERE main_agency_id=? AND period=? AND mode='CSV'
        GROUP BY tali_agency_id
      ) x ON x.tali_agency_id = s.tali_agency_id AND x.mid = s.id
      WHERE s.main_agency_id=? AND s.period=? AND s.mode='CSV'
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
  error_log("mutabakat/csv.php maps err: ".$e->getMessage());
}

require_once __DIR__ . '/../../layout/header.php';
?>


<section class="page">
  <div class="muta-wrap">
    <div class="card">
      <div class="muta-head">
        <div>
          <h1 class="muta-title">Mutabakat CSV</h1>
          <div class="meta">
            <span class="pill muted">Dönem: <?= h($selectedPeriod) ?></span>
            <?php if (!$hasPeriodsTable): ?>
              <span class="pill muted">Not: mutabakat_periods yok (gönder pasif)</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="head-right">
          <form method="get" action="<?= h(base_url('mutabakat/csv.php')) ?>">
            <select class="select" name="period" onchange="this.form.submit()">
              <?php foreach ($periodOptions as $p): ?>
                <option value="<?= h($p) ?>" <?= ($p === $selectedPeriod) ? 'selected' : '' ?>><?= h($p) ?></option>
              <?php endforeach; ?>
            </select>
          </form>

          <a class="btn primary" href="<?= h(base_url('mutabakat/csv.php?download=template&period='.$selectedPeriod)) ?>">CSV Template İndir</a>

          <?php if ($isTali): ?>
            <span class="pill muted">Tali ID: <?= (int)$agencyId ?></span>
          <?php endif; ?>
        </div>
      </div>

      <hr>

      <?php if (empty($talis)): ?>
        <div class="empty">
          <div>CSV verisi bulunamadı.</div>
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
              $detailHref = base_url('mutabakat/csv_detay.php?tali_id='.$tid.'&period='.$selectedPeriod);

              $stt = (string)($statusMap[$tid] ?? '');
              if ($stt === 'OPEN') {
                $pillHtml = '<span class="pill ok" id="status-pill-'.$tid.'">Mutabakat Açık • '.h($selectedPeriod).'</span>';
              } else {
                $pillHtml = '<span class="pill muted" id="status-pill-'.$tid.'">Mutabakat Kapalı • '.h($selectedPeriod).'</span>';
              }

              $canSendBtn = ($hasPeriodsTable && ( $isMain || ($isTali && $tid === $agencyId) ));
              $isOpen = ($hasPeriodsTable && $stt === 'OPEN');

              // ✅ submissions/payments durumuna göre sağ buton
              $subSt = strtoupper(trim((string)($subMap[$tid] ?? '')));
              $paySt = strtoupper(trim((string)($payMap[$tid] ?? '')));

              $btnText  = $isOpen ? 'Mutabakat Gönderildi' : 'Mutabakat Gönder';
              $btnClass = 'btn btn-success js-send-btn';
              $btnDisabled = $isOpen ? true : false;

              // payment varsa öncelik payment
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
                $btnDisabled = true;
              } else {
                // payment yoksa submission/OPEN
                if ($isOpen || in_array($subSt, ['SENT','APPROVED','DISPUTED'], true)) {
                  $btnText = 'Mutabakat Gönderildi';
                  $btnClass = 'btn btn-success is-sent';
                  $btnDisabled = true;
                }
              }

              $sendClickable = ($canSendBtn && !$btnDisabled);
              if ($sendClickable) {
                $btnClass = 'btn btn-success js-send-btn';
              }
            ?>
            <div class="mrow">
              <div class="mrow-top">
                <div>
                  <div class="name"><?= h($tname) ?></div>
                  <div class="meta">
                    <span class="pill muted">ID: <?= $tid ?></span>
                    <span class="pill muted"><?= $active ? 'Aktif' : 'Pasif' ?></span>
                    <?= $pillHtml ?>
                    <span class="pill muted">Son kayıt: <?= $lastAt ? h($lastAt) : '—' ?></span>
                  </div>
                </div>

                <div class="u-flex u-center u-gap-3">
                  <?php if ($canSendBtn): ?>
                    <button
                      class="<?= h($btnClass) ?>"
                      type="button"
                      data-tali="<?= (int)$tid ?>"
                      <?= $btnDisabled ? 'disabled' : '' ?>
                    ><?= h($btnText) ?></button>
                  <?php endif; ?>

                  <a class="btn primary" href="<?= h($detailHref) ?>">Detay</a>
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
</section>

<script>
async function sendMutabakatCsv(taliId, btnEl){
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
      alert((data && data.message) ? data.message : (txt || 'Hata'));
      if(btnEl){ btnEl.disabled = false; btnEl.textContent = oldText; }
      return;
    }

    const pill = document.getElementById('status-pill-' + String(taliId));
    if(pill){
      pill.classList.remove('muted','bad');
      pill.classList.add('ok');
      pill.textContent = 'Mutabakat Açık • ' + period;
    }

    // gönderince ilk etap: Gönderildi (payment süreci sonra)
    if(btnEl){
      btnEl.textContent = 'Mutabakat Gönderildi';
      btnEl.classList.add('is-sent');
      btnEl.classList.remove('btn-wait','btn-reject');
      btnEl.classList.add('btn-success');
      btnEl.disabled = true;
    }

  } catch(e){
    alert("Sunucu hatası (500/Network)");
    if(btnEl){ btnEl.disabled = false; btnEl.textContent = oldText; }
  }
}

document.querySelectorAll('.js-send-btn').forEach(btn=>{
  if(btn.disabled) return;
  btn.addEventListener('click', ()=> sendMutabakatCsv(btn.dataset.tali, btn));
});
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
