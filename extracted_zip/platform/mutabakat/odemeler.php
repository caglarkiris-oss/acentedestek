<?php
// /public_html/platform/mutabakat/odemeler.php
// ✅ Ödemeler (Main + Tali)
// Akış: PENDING (tali onayı bekler) -> APPROVED / REJECTED -> PAID (main ödemeyi işaretler)
// Özet: SATIŞ/ZEYİL/İPTAL adet+toplam + TOPLAM BRÜT + NET ÖDEME
// 🔥 TEK KAYNAK: mutabakat_csv_rows_cache (ticket/csv fark etmez)

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_odemeler_error.log');
error_reporting(E_ALL);

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../db.php';

if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) { header("Location: ".base_url("login.php")); exit; }
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$isMain = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
$isTali = ($role === 'TALI_ACENTE_YETKILISI');

if (!$isMain && !$isTali) { http_response_code(403); exit('Yetkisiz'); }

$conn = db();
if(!$conn){ http_response_code(500); exit('DB yok'); }
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function fmt_money($n){ return number_format((float)$n,2,',','.'); }
function stmt_or_log($conn, $sql){
  $st = $conn->prepare($sql);
  if(!$st){ error_log("PREPARE FAIL: ".$conn->error." SQL=".$sql); }
  return $st;
}

$flash = $_SESSION['odemeler_flash'] ?? null;
unset($_SESSION['odemeler_flash']);

/* =========================
   POST ACTIONS
========================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $do = (string)($_POST['do'] ?? '');

  // ✅ TALI: Onayla / İtiraz
  if ($do === 'tali_approve' || $do === 'tali_reject') {
    if (!$isTali) { http_response_code(403); exit('Yetkisiz işlem'); }
    $pid = (int)($_POST['payment_id'] ?? 0);
    if ($pid <= 0) { http_response_code(400); exit('Geçersiz'); }

    $note = trim((string)($_POST['note'] ?? ''));
    if (mb_strlen($note) > 250) $note = mb_substr($note, 0, 250);

    if ($do === 'tali_approve') {
      $sql = "UPDATE mutabakat_payments
              SET status='APPROVED', tali_note=?, approved_at=NOW(), approved_by_user_id=?
              WHERE id=? AND tali_agency_id=? AND status='PENDING'";
      $st = stmt_or_log($conn, $sql);
      $ok=false; $aff=0;
      if($st){
        $st->bind_param("siii", $note, $userId, $pid, $agencyId);
        $ok = (bool)$st->execute();
        $aff = (int)$st->affected_rows;
        if(!$ok) error_log("tali_approve exec fail: ".$st->error);
        $st->close();
      }

      $_SESSION['odemeler_flash'] = [
        'ok' => ($ok && $aff>0) ? 1 : 0,
        'msg' => ($ok && $aff>0) ? 'Talep onaylandı. Ana acente ödemeyi işaretleyebilir.' : 'İşlem yapılamadı (kayıt bulunamadı).',
        'ts' => time()
      ];
      header("Location: ".base_url("odemeler"));
      exit;

    } else { // tali_reject
      $sql = "UPDATE mutabakat_payments
              SET status='REJECTED', tali_note=?, rejected_at=NOW(), rejected_by_user_id=?
              WHERE id=? AND tali_agency_id=? AND status='PENDING'";
      $st = stmt_or_log($conn, $sql);
      $ok=false; $aff=0;
      if($st){
        $st->bind_param("siii", $note, $userId, $pid, $agencyId);
        $ok = (bool)$st->execute();
        $aff = (int)$st->affected_rows;
        if(!$ok) error_log("tali_reject exec fail: ".$st->error);
        $st->close();
      }

      $_SESSION['odemeler_flash'] = [
        'ok' => ($ok && $aff>0) ? 1 : 0,
        'msg' => ($ok && $aff>0) ? 'Talep itiraz edildi. Ana acente notu görecek.' : 'İşlem yapılamadı (kayıt bulunamadı).',
        'ts' => time()
      ];
      header("Location: ".base_url("odemeler"));
      exit;
    }
  }

  // ✅ MAIN: Ödendi işaretle (sadece APPROVED iken)
  if ($do === 'mark_paid') {
    if (!$isMain) { http_response_code(403); exit('Yetkisiz işlem'); }
    $pid = (int)($_POST['payment_id'] ?? 0);
    if ($pid <= 0) { http_response_code(400); exit('Geçersiz'); }

    $note = trim((string)($_POST['note'] ?? ''));
    if (mb_strlen($note) > 250) $note = mb_substr($note, 0, 250);

    $sql = "UPDATE mutabakat_payments
            SET status='PAID', main_note=?, paid_at=NOW(), paid_by_user_id=?
            WHERE id=? AND main_agency_id=? AND status='APPROVED'";
    $st = stmt_or_log($conn, $sql);
    $ok=false; $aff=0;
    if ($st) {
      $st->bind_param("siii", $note, $userId, $pid, $agencyId);
      $ok = (bool)$st->execute();
      $aff = (int)$st->affected_rows;
      if(!$ok) error_log("mark_paid exec fail: ".$st->error);
      $st->close();
    }

    $_SESSION['odemeler_flash'] = [
      'ok' => ($ok && $aff>0) ? 1 : 0,
      'msg' => ($ok && $aff>0) ? 'Ödeme “Ödendi” olarak işaretlendi.' : 'İşlem yapılamadı (onay bekliyor olabilir).',
      'ts' => time()
    ];
    header("Location: ".base_url("odemeler"));
    exit;
  }
}

/* =========================
   LIST (Main / Tali)
   + ÖZETLER: mutabakat_csv_rows_cache (TEK KAYNAK)
========================= */

$rows = [];
if ($isMain) {
  $sql = "
    SELECT
      p.id,
      p.submission_id,
      p.main_agency_id,
      p.tali_agency_id,
      p.period,
      p.net_total,
      p.status,
      p.tali_note,
      p.main_note,
      p.created_at,
      p.approved_at,
      p.rejected_at,
      p.paid_at,
      a.name AS tali_name,

      COALESCE(x.sale_cnt,0)   AS sale_cnt,
      COALESCE(x.sale_sum,0)   AS sale_sum,
      COALESCE(x.zeyil_cnt,0)  AS zeyil_cnt,
      COALESCE(x.zeyil_sum,0)  AS zeyil_sum,
      COALESCE(x.iptal_cnt,0)  AS iptal_cnt,
      COALESCE(x.iptal_sum,0)  AS iptal_sum,
      COALESCE(x.gross_sum,0)  AS gross_sum

    FROM mutabakat_payments p
    LEFT JOIN agencies a ON a.id = p.tali_agency_id
    LEFT JOIN (
      SELECT
        main_agency_id, tali_agency_id, period,
        SUM(CASE WHEN tip='SATIS' THEN 1 ELSE 0 END) AS sale_cnt,
        SUM(CASE WHEN tip='SATIS' THEN COALESCE(net_komisyon,0) ELSE 0 END) AS sale_sum,

        SUM(CASE WHEN tip='ZEYIL' THEN 1 ELSE 0 END) AS zeyil_cnt,
        SUM(CASE WHEN tip='ZEYIL' THEN COALESCE(net_komisyon,0) ELSE 0 END) AS zeyil_sum,

        SUM(CASE WHEN tip='IPTAL' THEN 1 ELSE 0 END) AS iptal_cnt,
        SUM(CASE WHEN tip='IPTAL' THEN COALESCE(net_komisyon,0) ELSE 0 END) AS iptal_sum,

        /* Toplam Brüt: satış + zeyil */
        SUM(CASE WHEN tip IN ('SATIS','ZEYIL') THEN COALESCE(net_komisyon,0) ELSE 0 END) AS gross_sum
      FROM mutabakat_csv_rows_cache
      GROUP BY main_agency_id, tali_agency_id, period
    ) x ON x.main_agency_id=p.main_agency_id AND x.tali_agency_id=p.tali_agency_id AND x.period=p.period

    WHERE p.main_agency_id=?
    ORDER BY
      (p.status='PENDING') DESC,
      (p.status='APPROVED') DESC,
      p.created_at DESC
    LIMIT 500
  ";
  $st = stmt_or_log($conn, $sql);
  if ($st) {
    $st->bind_param("i", $agencyId);
    $st->execute();
    $res = $st->get_result();
    if($res){
      while($r = $res->fetch_assoc()){
        $rows[] = $r;
      }
    } else {
      $st->bind_result(
        $id,$submission_id,$main_agency_id,$tali_agency_id,$period,$net_total,$status,$tali_note,$main_note,
        $created_at,$approved_at,$rejected_at,$paid_at,$tali_name,
        $sale_cnt,$sale_sum,$zeyil_cnt,$zeyil_sum,$iptal_cnt,$iptal_sum,$gross_sum
      );
      while($st->fetch()){
        $rows[] = [
          'id'=>$id,'submission_id'=>$submission_id,'main_agency_id'=>$main_agency_id,'tali_agency_id'=>$tali_agency_id,
          'period'=>$period,'net_total'=>$net_total,'status'=>$status,'tali_note'=>$tali_note,'main_note'=>$main_note,
          'created_at'=>$created_at,'approved_at'=>$approved_at,'rejected_at'=>$rejected_at,'paid_at'=>$paid_at,'tali_name'=>$tali_name,
          'sale_cnt'=>$sale_cnt,'sale_sum'=>$sale_sum,'zeyil_cnt'=>$zeyil_cnt,'zeyil_sum'=>$zeyil_sum,
          'iptal_cnt'=>$iptal_cnt,'iptal_sum'=>$iptal_sum,'gross_sum'=>$gross_sum
        ];
      }
    }
    $st->close();
  }
} else {
  $sql = "
    SELECT
      p.id,
      p.submission_id,
      p.main_agency_id,
      p.tali_agency_id,
      p.period,
      p.net_total,
      p.status,
      p.tali_note,
      p.main_note,
      p.created_at,
      p.approved_at,
      p.rejected_at,
      p.paid_at,
      a.name AS main_name,

      COALESCE(x.sale_cnt,0)   AS sale_cnt,
      COALESCE(x.sale_sum,0)   AS sale_sum,
      COALESCE(x.zeyil_cnt,0)  AS zeyil_cnt,
      COALESCE(x.zeyil_sum,0)  AS zeyil_sum,
      COALESCE(x.iptal_cnt,0)  AS iptal_cnt,
      COALESCE(x.iptal_sum,0)  AS iptal_sum,
      COALESCE(x.gross_sum,0)  AS gross_sum

    FROM mutabakat_payments p
    LEFT JOIN agencies a ON a.id = p.main_agency_id
    LEFT JOIN (
      SELECT
        main_agency_id, tali_agency_id, period,
        SUM(CASE WHEN tip='SATIS' THEN 1 ELSE 0 END) AS sale_cnt,
        SUM(CASE WHEN tip='SATIS' THEN COALESCE(net_komisyon,0) ELSE 0 END) AS sale_sum,

        SUM(CASE WHEN tip='ZEYIL' THEN 1 ELSE 0 END) AS zeyil_cnt,
        SUM(CASE WHEN tip='ZEYIL' THEN COALESCE(net_komisyon,0) ELSE 0 END) AS zeyil_sum,

        SUM(CASE WHEN tip='IPTAL' THEN 1 ELSE 0 END) AS iptal_cnt,
        SUM(CASE WHEN tip='IPTAL' THEN COALESCE(net_komisyon,0) ELSE 0 END) AS iptal_sum,

        /* Toplam Brüt: satış + zeyil */
        SUM(CASE WHEN tip IN ('SATIS','ZEYIL') THEN COALESCE(net_komisyon,0) ELSE 0 END) AS gross_sum
      FROM mutabakat_csv_rows_cache
      GROUP BY main_agency_id, tali_agency_id, period
    ) x ON x.main_agency_id=p.main_agency_id AND x.tali_agency_id=p.tali_agency_id AND x.period=p.period

    WHERE p.tali_agency_id=?
    ORDER BY p.created_at DESC
    LIMIT 500
  ";
  $st = stmt_or_log($conn, $sql);
  if ($st) {
    $st->bind_param("i", $agencyId);
    $st->execute();
    $res = $st->get_result();
    if($res){
      while($r = $res->fetch_assoc()){
        $rows[] = $r;
      }
    } else {
      $st->bind_result(
        $id,$submission_id,$main_agency_id,$tali_agency_id,$period,$net_total,$status,$tali_note,$main_note,
        $created_at,$approved_at,$rejected_at,$paid_at,$main_name,
        $sale_cnt,$sale_sum,$zeyil_cnt,$zeyil_sum,$iptal_cnt,$iptal_sum,$gross_sum
      );
      while($st->fetch()){
        $rows[] = [
          'id'=>$id,'submission_id'=>$submission_id,'main_agency_id'=>$main_agency_id,'tali_agency_id'=>$tali_agency_id,
          'period'=>$period,'net_total'=>$net_total,'status'=>$status,'tali_note'=>$tali_note,'main_note'=>$main_note,
          'created_at'=>$created_at,'approved_at'=>$approved_at,'rejected_at'=>$rejected_at,'paid_at'=>$paid_at,'main_name'=>$main_name,
          'sale_cnt'=>$sale_cnt,'sale_sum'=>$sale_sum,'zeyil_cnt'=>$zeyil_cnt,'zeyil_sum'=>$zeyil_sum,
          'iptal_cnt'=>$iptal_cnt,'iptal_sum'=>$iptal_sum,'gross_sum'=>$gross_sum
        ];
      }
    }
    $st->close();
  }
}

$page_title = 'Ödemeler';
require_once __DIR__.'/../layout/header.php';
?>

<style>
/* Kart */
.pay-card{
  border:1px solid rgba(0,0,0,.06);
  background:#fff;
  border-radius:18px;
  padding:12px;
  margin-top:12px;
  box-shadow:0 10px 26px rgba(0,0,0,.05);
}
.pay-top{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center}

/* Status pill */
.pill{display:inline-flex;align-items:center;gap:8px;padding:5px 10px;border-radius:999px;border:1px solid rgba(0,0,0,.10);font-size:11.5px}
.pill.pending{background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.35)}
.pill.approved{background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.35)}
.pill.rejected{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.35)}
.pill.paid{background:rgba(59,130,246,.10);border-color:rgba(59,130,246,.35)}

/* KPI grid */
.grid5{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:10px}
@media(max-width:1100px){.grid5{grid-template-columns:repeat(2,1fr)}}
@media(max-width:520px){.grid5{grid-template-columns:1fr}}

/* KPI card (ticket gibi renkli) */
.kpi{
  border:1px solid rgba(0,0,0,.08);
  border-radius:14px;
  padding:10px 12px;
  min-height:58px;
  display:flex;
  flex-direction:column;
  justify-content:center;
}
.kpi small{
  display:block;
  font-size:11px;
  letter-spacing:.02em;
  opacity:.75;
  margin-bottom:4px;
}
.kpi .kpi-val{
  font-size:13px;
  font-weight:650; /* BOLD DEĞİL */
  line-height:1.15;
}
.kpi .kpi-val .sub{
  opacity:.78;
  margin-right:8px;
  font-weight:600;
}

/* Renkler (ticket ekranına benzer) */
.kpi.sale  { background:rgba(16,185,129,.10); border-color:rgba(16,185,129,.28); }
.kpi.zeyil { background:rgba(245,158,11,.10); border-color:rgba(245,158,11,.28); }
.kpi.iptal { background:rgba(239,68,68,.10); border-color:rgba(239,68,68,.28); }
.kpi.gross { background:rgba(59,130,246,.10); border-color:rgba(59,130,246,.28); }
.kpi.net   { background:rgba(99,102,241,.08); border-color:rgba(99,102,241,.25); }

.flash{margin:10px 0;padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.08)}
.flash.ok{background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.25)}
.flash.no{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.25)}

.note{width:min(520px,100%);border:1px solid rgba(0,0,0,.15);border-radius:12px;padding:10px}
.btnx{padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.12);background:#fff;font-weight:800;cursor:pointer}
.btnx.ok{border-color:rgba(16,185,129,.35);background:rgba(16,185,129,.12)}
.btnx.danger{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)}
.actions{margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.meta{font-size:11.5px;opacity:.65}
</style>

<main class="page">
<section class="card">

<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
  <h2 style="margin:0">Ödemeler</h2>
  <a class="btn sm" href="<?=base_url('mutabakat')?>">← Mutabakata dön</a>
</div>

<?php if($flash && is_array($flash)): ?>
  <div class="flash <?=((int)($flash['ok'] ?? 0)===1)?'ok':'no'?>">
    <?=h((string)($flash['msg'] ?? ''))?>
  </div>
<?php endif; ?>

<?php if(empty($rows)): ?>
  <div style="margin-top:14px;opacity:.75;">Henüz ödeme kaydı yok.</div>
<?php else: ?>
  <?php foreach($rows as $r): ?>
    <?php
      $status = (string)($r['status'] ?? 'PENDING');

      if($status==='PAID'){
        $pill = '<span class="pill paid">💙 Ödendi</span>';
      } elseif($status==='APPROVED'){
        $pill = '<span class="pill approved">✅ Onaylandı</span>';
      } elseif($status==='REJECTED'){
        $pill = '<span class="pill rejected">⛔ İtiraz</span>';
      } else {
        $pill = '<span class="pill pending">⏳ Tali Onayı Bekliyor</span>';
      }

      $titleParty = $isMain
        ? ('Tali: '.h($r['tali_name'] ?? ('#'.(int)$r['tali_agency_id'])))
        : ('Ana: '.h($r['main_name'] ?? ('#'.(int)$r['main_agency_id'])));

      $saleCnt   = (int)($r['sale_cnt'] ?? 0);
      $zeyilCnt  = (int)($r['zeyil_cnt'] ?? 0);
      $iptalCnt  = (int)($r['iptal_cnt'] ?? 0);

      $saleSum   = (float)($r['sale_sum'] ?? 0);
      $zeyilSum  = (float)($r['zeyil_sum'] ?? 0);
      $iptalSum  = (float)($r['iptal_sum'] ?? 0);

      $grossSum  = (float)($r['gross_sum'] ?? 0);
      $netTotal  = (float)($r['net_total'] ?? 0);
    ?>

    <div class="pay-card">
      <div class="pay-top">
        <div style="font-weight:800;">
          Talep #<?= (int)($r['submission_id'] ?? 0) ?>
          <span style="font-weight:600;opacity:.75;margin-left:8px;"><?= $titleParty ?></span>
          <span style="font-weight:600;opacity:.55;margin-left:8px;">Dönem: <?=h($r['period'] ?? '-')?></span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <?= $pill ?>
          <span class="meta">Oluşturma: <?=h(date('d.m.Y H:i', strtotime($r['created_at'] ?? 'now')))?></span>
          <?php if($status==='APPROVED' && !empty($r['approved_at'])): ?>
            <span class="meta">Onay: <?=h(date('d.m.Y H:i', strtotime($r['approved_at'])))?></span>
          <?php endif; ?>
          <?php if($status==='PAID' && !empty($r['paid_at'])): ?>
            <span class="meta">Ödeme: <?=h(date('d.m.Y H:i', strtotime($r['paid_at'])))?></span>
          <?php endif; ?>
        </div>
      </div>

      <!-- ÖZET (ticket gibi renkli + bold yok) -->
      <div class="grid5">
        <div class="kpi sale">
          <small>SATIŞ</small>
          <div class="kpi-val"><span class="sub"><?= $saleCnt ?> adet</span><?= fmt_money($saleSum) ?></div>
        </div>
        <div class="kpi zeyil">
          <small>ZEYİL</small>
          <div class="kpi-val"><span class="sub"><?= $zeyilCnt ?> adet</span><?= fmt_money($zeyilSum) ?></div>
        </div>
        <div class="kpi iptal">
          <small>İPTAL</small>
          <div class="kpi-val"><span class="sub"><?= $iptalCnt ?> adet</span><?= fmt_money($iptalSum) ?></div>
        </div>
        <div class="kpi gross">
          <small>TOPLAM BRÜT</small>
          <div class="kpi-val"><?= fmt_money($grossSum) ?></div>
        </div>
        <div class="kpi net">
          <small>NET ÖDEME</small>
          <div class="kpi-val"><?= fmt_money($netTotal) ?></div>
        </div>
      </div>

      <!-- Notlar -->
      <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
        <div class="kpi" style="flex:1;min-width:200px;background:rgba(0,0,0,.015);">
          <small>Tali Notu</small>
          <div class="kpi-val" style="font-size:12.5px;opacity:.9;"><?= h($r['tali_note'] ?? '-') ?></div>
        </div>
        <div class="kpi" style="flex:1;min-width:200px;background:rgba(0,0,0,.015);">
          <small>Ana Notu</small>
          <div class="kpi-val" style="font-size:12.5px;opacity:.9;"><?= h($r['main_note'] ?? '-') ?></div>
        </div>
      </div>

      <!-- AKSİYONLAR -->
      <?php if($isTali && $status==='PENDING'): ?>
        <form method="post" action="<?=base_url('odemeler')?>" class="actions">
          <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
          <input class="note" type="text" name="note" placeholder="Not (opsiyonel). İtirazda mutlaka yaz..." />
          <button class="btnx ok" type="submit" name="do" value="tali_approve">Onayla</button>
          <button class="btnx danger" type="submit" name="do" value="tali_reject">İtiraz Et</button>
        </form>
      <?php endif; ?>

      <?php if($isMain && $status==='APPROVED'): ?>
        <form method="post" action="<?=base_url('odemeler')?>" class="actions">
          <input type="hidden" name="do" value="mark_paid">
          <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
          <input class="note" type="text" name="note" placeholder="Ödeme notu (opsiyonel)..." />
          <button class="btnx ok" type="submit">Ödendi İşaretle</button>
        </form>
      <?php endif; ?>

      <?php if($isMain && $status==='PENDING'): ?>
        <div class="meta" style="margin-top:10px;">
          Tali onayı bekleniyor. (Tali “Onayla” demeden ödeme işaretlenemez.)
        </div>
      <?php endif; ?>

      <?php if($isMain && $status==='REJECTED'): ?>
        <div class="meta" style="margin-top:10px;">
          Tali itiraz etti. Gerekirse mutabakat detayında düzeltip tekrar tamamlayabilirsiniz.
        </div>
      <?php endif; ?>

    </div>
  <?php endforeach; ?>
<?php endif; ?>

</section>
</main>

<?php require_once __DIR__.'/../layout/footer.php'; ?>
