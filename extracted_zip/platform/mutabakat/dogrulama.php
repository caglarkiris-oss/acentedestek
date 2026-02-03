<?php

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_mutabakat_dogrulama_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} elseif (function_exists('require_auth')) {
  require_auth();
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

$hasPeriods     = table_exists($conn, 'mutabakat_periods');
$hasSubmissions = table_exists($conn, 'mutabakat_submissions');
$hasPayments    = table_exists($conn, 'mutabakat_payments');

/* PERIOD */
$selectedPeriod = (string)($_GET['period'] ?? '');
if (!valid_period($selectedPeriod)) $selectedPeriod = this_month();

/* TEMPLATE DOWNLOAD  ✅ FIX: gerçek CSV + ; ayırıcı + fputcsv (tek satır başlık) */
if (($_GET['download_template'] ?? '') === '1') {
  $cols = ["T.C / V.N.","Sigortalı","Plaka","Şirket","Branş","Tip","Tanzim","Poliçe No","Net Komisyon"];

  $filename = "mutabakat_template_".$selectedPeriod.".csv";
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Pragma: no-cache');
  header('Expires: 0');

  $out = fopen('php://output', 'w');

  // Excel TR için BOM
  fwrite($out, "\xEF\xBB\xBF");

  // ✅ Başlıklar TEK SATIRDA, yan yana
  fputcsv($out, $cols, ';');

  fclose($out);
  exit;
}

/* Period options (son 24 ay basit) */
$periodOptions = [];
try {
  $dt = new DateTime(date('Y-m-01'));
  for ($i=0; $i<24; $i++){
    $periodOptions[] = $dt->format('Y-m');
    $dt->modify('-1 month');
  }
} catch(Throwable $e){
  $periodOptions = [$selectedPeriod];
}
if (!in_array($selectedPeriod, $periodOptions, true)) array_unshift($periodOptions, $selectedPeriod);

/* MAIN ID (tali için) */
$mainAgencyId = 0;
if ($isTali) {
  $st = $conn->prepare("SELECT parent_id FROM agencies WHERE id=? LIMIT 1");
  if ($st) {
    $st->bind_param("i", $agencyId);
    $st->execute();
    $mainAgencyId = (int)(($st->get_result()->fetch_assoc()['parent_id'] ?? 0));
    $st->close();
  }
}

/* LIST */
$items = [];
try {
  if ($isMain) {
    $sql = "
      SELECT
        a.id,
        a.name,
        a.is_active,
        a.work_mode,
        COALESCE(mp.status, '') AS status
      FROM agencies a
      LEFT JOIN mutabakat_periods mp
        ON mp.main_agency_id = ?
       AND mp.assigned_agency_id = a.id
       AND mp.period = ?
      WHERE a.parent_id = ?
        AND a.is_active = 1
      ORDER BY a.name ASC
    ";

    if (!$hasPeriods) {
      $sql = "
        SELECT a.id,a.name,a.is_active,a.work_mode,'' AS status
        FROM agencies a
        WHERE a.parent_id = ?
          AND a.is_active = 1
        ORDER BY a.name ASC
      ";
      $st = $conn->prepare($sql);
      if ($st) {
        $st->bind_param("i", $agencyId);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) $items[] = $r;
        $st->close();
      }
    } else {
      $st = $conn->prepare($sql);
      if ($st) {
        $st->bind_param("isi", $agencyId, $selectedPeriod, $agencyId);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) $items[] = $r;
        $st->close();
      }
    }
  } else {
    $sql = "
      SELECT
        a.id,
        a.name,
        a.is_active,
        a.work_mode,
        COALESCE(mp.status, '') AS status
      FROM agencies a
      LEFT JOIN mutabakat_periods mp
        ON mp.main_agency_id = ?
       AND mp.assigned_agency_id = a.id
       AND mp.period = ?
      WHERE a.id = ?
      LIMIT 1
    ";
    if (!$hasPeriods) {
      $sql = "SELECT a.id,a.name,a.is_active,a.work_mode,'' AS status FROM agencies a WHERE a.id=? LIMIT 1";
      $st = $conn->prepare($sql);
      if ($st) {
        $st->bind_param("i", $agencyId);
        $st->execute();
        if ($r = $st->get_result()->fetch_assoc()) $items[] = $r;
        $st->close();
      }
    } else {
      $st = $conn->prepare($sql);
      if ($st) {
        $st->bind_param("isi", $mainAgencyId, $selectedPeriod, $agencyId);
        $st->execute();
        if ($r = $st->get_result()->fetch_assoc()) $items[] = $r;
        $st->close();
      }
    }
  }
} catch(Throwable $e) {
  error_log("mutabakat/dogrulama list err: ".$e->getMessage());
}

/* =========================
   ✅ DURUM ZİNCİRİ MAP'leri
   - submissions/payments varsa: listede OPEN/CLOSED yerine bunları göster
========================= */
$subMap = []; // [taliId => SENT|APPROVED|DISPUTED...]
$payMap = []; // [taliId => PENDING|APPROVED|REJECTED|PAID]
try {
  $mainIdForMaps = $isMain ? $agencyId : (int)$mainAgencyId;
  if ($mainIdForMaps > 0) {

    // submissions (en son kayıt)
    if ($hasSubmissions) {
      $sqlS = "
        SELECT s.tali_agency_id, UPPER(TRIM(s.status)) AS st
        FROM mutabakat_submissions s
        INNER JOIN (
          SELECT tali_agency_id, MAX(id) AS mid
          FROM mutabakat_submissions
          WHERE main_agency_id=? AND period=?
          GROUP BY tali_agency_id
        ) x ON x.tali_agency_id=s.tali_agency_id AND x.mid=s.id
        WHERE s.main_agency_id=? AND s.period=?
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

    // payments (en son kayıt)
    if ($hasPayments) {
      $sqlP = "
        SELECT p.tali_agency_id, UPPER(TRIM(p.status)) AS st
        FROM mutabakat_payments p
        INNER JOIN (
          SELECT tali_agency_id, MAX(id) AS mid
          FROM mutabakat_payments
          WHERE main_agency_id=? AND period=?
          GROUP BY tali_agency_id
        ) x ON x.tali_agency_id=p.tali_agency_id AND x.mid=p.id
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
  }
} catch(Throwable $e){
  error_log("mutabakat/dogrulama maps err: ".$e->getMessage());
}

require_once __DIR__ . '/../layout/header.php';

/* Link builder */
function build_url_with_period(string $path, string $period, int $taliId): string {
  return base_url($path.'?tali_id='.$taliId.'&period='.$period);
}

?>
<style>
  /* sadece sayfa içi küçük dokunuşlar (theme bozmayalım) */
  .muta-wrap{max-width:1200px;margin:0 auto;}
  .muta-top{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;}
  .muta-title{margin:0;font-size:20px;font-weight:650;}
  .muta-sub{margin-top:6px;color:var(--muted);font-size:13px;}
  .muta-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
  .muta-tools .field{min-width:240px}
  .muta-list{margin-top:12px;display:flex;flex-direction:column;gap:12px;}
  .muta-row{
    border-radius: var(--r20);
    padding:14px;
    border:1px solid rgba(15,23,42,.10);
    background: linear-gradient(180deg, #FFFFFF, rgba(15,23,42,.01));
    box-shadow: 0 10px 18px rgba(2,6,23,.06);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
  }
  .muta-left{min-width:260px}
  .muta-name{font-weight:800;font-size:14px;margin:0}
  .muta-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
  .muta-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
  .muta-empty{padding:14px;border-radius:16px;border:1px dashed rgba(15,23,42,.18);background:#fff;color:var(--muted);}
  .hide{display:none!important}

  /* =========================
     MUTABAKAT – TYPO NORMALIZE
     (Bold / Bolt kapat)
  ========================= */
  .muta-wrap, .muta-wrap *{ font-weight: 400; }
  .muta-title, .card-title{ font-weight: 500; }
  .muta-name{ font-weight: 500; }
  .badge, .muta-meta .badge{ font-weight: 500; }
  .btn{ font-weight: 500; }
  .btn.primary{ font-weight: 500; }
  .card-sub, .help, .small{ font-weight: 400; }
</style>

<main class="page">
  <div class="muta-wrap">
    <div class="card">
      <div class="muta-top">
        <div>
          <h2 class="card-title" style="margin:0">Mutabakat Doğrulama</h2>
          <div class="card-sub" style="margin-top:6px">
            Dönem:
            <span class="badge info"><?= h($selectedPeriod) ?></span>
            <?php if (!$hasPeriods): ?>
              <span class="badge warn">mutabakat_periods yok (durum: Bekliyor)</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="muta-tools">
          <div class="field" style="min-width:260px">
            <label class="label">Dönem (YYYY-MM)</label>
            <form method="get" action="<?= h(base_url('mutabakat/dogrulama.php')) ?>">
              <select class="input" name="period" onchange="this.form.submit()">
                <?php foreach ($periodOptions as $p): ?>
                  <option value="<?= h($p) ?>" <?= ($p===$selectedPeriod?'selected':'') ?>><?= h($p) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>

          <div class="field" style="min-width:320px">
            <label class="label">Tali Ara</label>
            <input class="input" id="live_q" type="text" placeholder="Tali acente adı">
          </div>

          <div class="actions" style="margin-top:18px;justify-content:flex-start">
            <a class="btn" href="<?= h(base_url('mutabakat/dogrulama.php?period='.$selectedPeriod)) ?>">Sıfırla</a>
            <a class="btn primary" href="<?= h(base_url('mutabakat/dogrulama.php?period='.$selectedPeriod.'&download_template=1')) ?>">
              Template İndir
            </a>
          </div>
        </div>
      </div>

      <div class="hr"></div>

      <?php if (empty($items)): ?>
        <div class="muta-empty">Aktif tali bulunamadı.</div>
      <?php else: ?>
        <div class="muta-list" id="muta_list">
          <?php foreach ($items as $it): ?>
            <?php
              $tid = (int)($it['id'] ?? 0);
              $name = (string)($it['name'] ?? ('Tali #'.$tid));
              $work = (string)($it['work_mode'] ?? '');
              $status = (string)($it['status'] ?? '');

              // ✅ Yeni zincir: payment > submission > periods (OPEN/CLOSED) > Bekliyor
              $subSt = strtoupper(trim((string)($subMap[$tid] ?? '')));
              $paySt = strtoupper(trim((string)($payMap[$tid] ?? '')));

              $statusLabel = 'Mutabakat Onayı: Bekliyor';
              $badgeStat = 'info';

              if ($paySt === 'PAID') {
                $statusLabel = 'Ödeme Yapıldı • '.$selectedPeriod;
                $badgeStat = 'good';
              } elseif ($paySt === 'APPROVED') {
                $statusLabel = 'Onay Verildi • '.$selectedPeriod;
                $badgeStat = 'good';
              } elseif ($paySt === 'REJECTED') {
                $statusLabel = 'İtiraz Edildi • '.$selectedPeriod;
                $badgeStat = 'bad';
              } elseif ($paySt === 'PENDING') {
                $statusLabel = 'Onay Bekliyor • '.$selectedPeriod;
                $badgeStat = 'warn';
              } elseif (in_array($subSt, ['SENT','APPROVED','DISPUTED'], true)) {
                // payment yok ama gönderim var
                $statusLabel = 'Gönderildi • '.$selectedPeriod;
                $badgeStat = 'warn';
                if ($subSt === 'APPROVED') { $statusLabel = 'Onay Verildi • '.$selectedPeriod; $badgeStat='good'; }
                if ($subSt === 'DISPUTED') { $statusLabel = 'İtiraz Edildi • '.$selectedPeriod; $badgeStat='bad'; }
              } else {
                // fallback periods
                if ($status === 'OPEN') { $statusLabel = 'Mutabakat Gönderildi • '.$selectedPeriod; $badgeStat = 'good'; }
                else if ($status === 'CLOSED') { $statusLabel = 'Mutabakat Kapalı • '.$selectedPeriod; $badgeStat = 'bad'; }
              }

              $modeLabel = ($work === 'csv') ? 'Çalışma Modu: CSV' : 'Çalışma Modu: Ticket';

              // ✅ BUTON METNİ KALSIN, AMA HER ZAMAN AYNI SAYFAYA GİTSİN
              if ($work === 'csv') $actionText = 'CSV Yükle / Karşılaştır';
              else $actionText = 'Ticket / Karşılaştır';

              // ✅ her work_mode -> dogrulama-detay.php
              $href = build_url_with_period('mutabakat/dogrulama-detay.php', $selectedPeriod, $tid);

              $badgeMode = ($work === 'csv') ? 'warn' : 'info';
            ?>
            <div class="muta-row js-row" data-name="<?= h(mb_strtolower($name,'UTF-8')) ?>">
              <div class="muta-left">
                <p class="muta-name"><?= h($name) ?></p>
                <div class="muta-meta">
                  <span class="badge"><?= 'ID: '.(int)$tid ?></span>
                  <span class="badge <?= h($badgeMode) ?>"><?= h($modeLabel) ?></span>
                  <span class="badge <?= h($badgeStat) ?>"><?= h($statusLabel) ?></span>
                </div>
              </div>

              <div class="muta-actions">
                <a class="btn primary" href="<?= h($href) ?>"><?= h($actionText) ?></a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        </div>
      <?php endif; ?>

    </div>
  </div>
</main>

<script>
(function(){
  const q = document.getElementById('live_q');
  const rows = Array.from(document.querySelectorAll('.js-row'));
  if(!q || !rows.length) return;

  function norm(s){
    return (s || '')
      .toLowerCase()
      .trim();
  }

  function apply(){
    const val = norm(q.value);
    rows.forEach(r=>{
      const name = (r.getAttribute('data-name') || '');
      const ok = !val || name.includes(val);
      r.classList.toggle('hide', !ok);
    });
  }

  let t = null;
  q.addEventListener('input', function(){
    if(t) clearTimeout(t);
    t = setTimeout(apply, 280);
  });

  apply();
})();
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
