<?php
// /public_html/platform/ajax-ticket-mutabakat-save.php  (TEK PARÇA)
// ✅ Yeni model: mutabakat_v2_* tabloları kullanılır.
// ✅ Ticket kapanmaz (close başka endpoint).
// ✅ Mutabakat 1 kere kaydedilir (sonra kilitli).

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

header('Content-Type: application/json; charset=utf-8');

$debug = (bool) config('app.debug', false);

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'Yetkisiz']); exit; }
}

$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$ALLOWED = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) { echo json_encode(['ok'=>false,'error'=>'Yetkisiz']); exit; }

/* ---------------- helpers ---------------- */
function clean($s): string { return trim((string)$s); }

function norm_money($v): string {
  $v = clean($v);
  if ($v === '') return '';
  $v = str_replace([' ', "\t", "\n", "\r"], '', $v);
  // TR: 12.345,67 -> 12345.67
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

function ensure_ticket_mutabakat_columns(mysqli $conn): void {
  // bu kolonlar platformda mutabakatın 1 kez kaydedilmesi için kilit gibi kullanılıyor
  $need = [
    'mutabakat_saved_at' => "ALTER TABLE tickets ADD COLUMN mutabakat_saved_at DATETIME NULL",
    'mutabakat_type'     => "ALTER TABLE tickets ADD COLUMN mutabakat_type VARCHAR(10) NULL",
    'mutabakat_tanzim'   => "ALTER TABLE tickets ADD COLUMN mutabakat_tanzim DATE NULL",
    'policy_number'      => "ALTER TABLE tickets ADD COLUMN policy_number VARCHAR(120) NULL",
    'policy_no_norm'     => "ALTER TABLE tickets ADD COLUMN policy_no_norm VARCHAR(160) NULL",
    'sale_amount'        => "ALTER TABLE tickets ADD COLUMN sale_amount DECIMAL(14,2) NULL",
  ];

  foreach ($need as $col => $alterSql) {
    $chk = $conn->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME='tickets'
        AND COLUMN_NAME=?
    ");
    if (!$chk) continue;
    $chk->bind_param("s", $col);
    $chk->execute();
    $chk->bind_result($cnt);
    $chk->fetch();
    $chk->close();
    if ((int)$cnt === 0) { @$conn->query($alterSql); }
  }
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

  // yoksa oluştur
  $ins = $conn->prepare("
    INSERT INTO mutabakat_v2_periods (ana_acente_id, year, month, status, created_at)
    VALUES (?, ?, ?, 'OPEN', NOW())
  ");
  if (!$ins) return 0;
  $ins->bind_param("iii", $anaAcenteId, $year, $month);
  if (!$ins->execute()) { $ins->close(); return 0; }
  $ins->close();
  return (int)$conn->insert_id;
}

function resolve_row_status(mysqli $conn): string {
  // row_status NOT NULL olduğu için güvenli bir değer üret.
  // Eğer ENUM ise: HAVUZ varsa onu seç, yoksa ilk ENUM değerini seç.
  // Eğer VARCHAR vs ise: HAVUZ dön.
  $fallback = 'HAVUZ';

  $colType = '';
  $st = $conn->prepare("\n    SELECT COLUMN_TYPE\n    FROM information_schema.COLUMNS\n    WHERE TABLE_SCHEMA = DATABASE()\n      AND TABLE_NAME = 'mutabakat_v2_rows'\n      AND COLUMN_NAME = 'row_status'\n    LIMIT 1\n  ");
  if ($st) {
    $st->execute();
    $st->bind_result($colType);
    $st->fetch();
    $st->close();
  }

  if ($colType === '') return $fallback;

  $ct = strtolower($colType);
  if (str_starts_with($ct, 'enum(')) {
    // enum('A','B',...)
    preg_match_all("/'([^']*)'/", $colType, $m);
    $vals = $m[1] ?? [];
    if (!$vals) return $fallback;
    if (in_array($fallback, $vals, true)) return $fallback;
    return (string)$vals[0];
  }

  // varchar/text vs
  return $fallback;
}

/* ---------------- INPUT ---------------- */
$ticketId = (int)($_POST['ticket_id'] ?? 0);
$mutType  = strtoupper(clean($_POST['mutabakat_type'] ?? '')); // SATIS/IPTAL/ZEYIL

$tc_vn           = clean($_POST['tc_vn'] ?? '');
$sigortali_adi   = clean($_POST['sigortali_adi'] ?? '');
$tanzim_tarihi   = clean($_POST['tanzim_tarihi'] ?? ''); // YYYY-MM-DD
$sigorta_sirketi = clean($_POST['sigorta_sirketi'] ?? '');
$brans           = clean($_POST['brans'] ?? '');
$police_no       = clean($_POST['police_no'] ?? '');
$plaka           = clean($_POST['plaka'] ?? '');
$brut_prim_norm  = norm_money($_POST['brut_prim'] ?? '');

if ($ticketId <= 0) { echo json_encode(['ok'=>false,'error'=>'Geçersiz ticket']); exit; }
if (!in_array($mutType, ['SATIS','IPTAL','ZEYIL'], true)) { echo json_encode(['ok'=>false,'error'=>'Mutabakat tipi geçersiz']); exit; }

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanzim_tarihi)) {
  echo json_encode(['ok'=>false,'error'=>'Tanzim tarihi formatı hatalı (YYYY-MM-DD)']); exit;
}
if ($brut_prim_norm === '' || !is_numeric($brut_prim_norm)) { echo json_encode(['ok'=>false,'error'=>'Brüt prim geçersiz']); exit; }

$brutVal = (float)$brut_prim_norm;

// plaka normalize: boşsa null
$plakaDb = ($plaka === '') ? null : $plaka;

try {
  // 1) Ticket bilgisi
  ensure_ticket_mutabakat_columns($conn);

  $st = $conn->prepare("
    SELECT id, status, workflow_status, branch, created_by_agency_id, target_agency_id, mutabakat_saved_at
    FROM tickets
    WHERE id=? LIMIT 1
  ");
  if(!$st) throw new Exception("Prepare fail tickets: ".$conn->error);
  $st->bind_param("i", $ticketId);
  $st->execute();
  $st->bind_result($tid, $status, $wf, $ticketBranch, $createdByAgencyId, $targetAgencyId, $mutabakatSavedAt);
  if(!$st->fetch()){
    $st->close();
    echo json_encode(['ok'=>false,'error'=>'Ticket yok']);
    exit;
  }
  $st->close();

  if ((string)$status === 'closed') { echo json_encode(['ok'=>false,'error'=>'Ticket kapalı']); exit; }

  // ✅ Mutabakatı ticketi açan taraf (tali) kaydeder
  if ((int)$createdByAgencyId !== (int)$agencyId) {
    echo json_encode(['ok'=>false,'error'=>'Mutabakatı sadece ticketi açan taraf kaydedebilir.']);
    exit;
  }

  // ✅ 1 kere kaydet (kilit)
  if (!empty($mutabakatSavedAt)) {
    // UI doldursun diye mevcut satırı döndür
    $found = null;
    $q = $conn->prepare("
      SELECT id, policy_no, tanzim_tarihi, brut_prim, txn_type, sigorta_sirketi, brans, tc_vn, sigortali_adi, plaka
      FROM mutabakat_v2_rows
      WHERE ticket_id=? AND source_type='TICKET'
      LIMIT 1
    ");
    if ($q) {
      $q->bind_param("i", $ticketId);
      $q->execute();
      $q->bind_result($rid,$praw,$idate,$gp,$tt,$cname,$br,$tc,$insn,$pl);
      if ($q->fetch()) {
        $found = [
          'id'=>$rid,
          'policy_no'=>$praw,
          'tanzim_tarihi'=>$idate,
          'brut_prim'=>$gp,
          'mutabakat_type'=>$tt,
          'sigorta_sirketi'=>$cname,
          'brans'=>$br,
          'tc_vn'=>$tc,
          'sigortali_adi'=>$insn,
          'plaka'=>$pl,
        ];
      }
      $q->close();
    }
    echo json_encode(['ok'=>true,'already_saved'=>true,'locked'=>true,'data'=>$found]);
    exit;
  }

  // 2) workflow -> expected type kontrol
  $WF = strtoupper(trim((string)$wf));
  $expected = ($WF === 'SATIS_YAPILDI') ? 'SATIS' : (($WF === 'IPTAL') ? 'IPTAL' : (($WF === 'ZEYIL') ? 'ZEYIL' : ''));
  if ($expected === '') { echo json_encode(['ok'=>false,'error'=>'Bu iş akışında mutabakat kaydedilemez.']); exit; }
  if ($mutType !== $expected) { echo json_encode(['ok'=>false,'error'=>"Mutabakat tipi uyuşmuyor. Beklenen: $expected"]); exit; }

  // model: MAIN=target, TALI=createdBy
  $mainAgencyId = (int)$targetAgencyId;
  $taliAgencyId = (int)$createdByAgencyId;

  $policyNorm = normalize_policy_no($police_no);
  if ($policyNorm === '') $policyNorm = $police_no;

  // period
  $periodId = find_or_create_period($conn, $mainAgencyId, $tanzim_tarihi);
  if ($periodId <= 0) throw new Exception("Period bulunamadı/oluşturulamadı");

  $rowStatus = resolve_row_status($conn); // row_status NOT NULL olabilir; güvenli değer üret
  $currency  = 'TRY';

  $conn->begin_transaction();

  // 3) mutabakat_v2_rows insert (ticket kaydı)
  $sql = "
    INSERT INTO mutabakat_v2_rows
      (period_id, ana_acente_id, tali_acente_id,
       tc_vn, source_type, ticket_id, import_batch_id,
       policy_no, txn_type, zeyil_turu,
       sigortali_adi, sig_kimlik_no,
       tanzim_tarihi, bitis_tarihi,
       sigorta_sirketi, brans, urun,
       plaka, brut_prim, net_prim, net_komisyon,
       komisyon_tutari, araci_kom_payi,
       currency, row_status, locked, created_by, created_at, updated_at)
    VALUES
      (?, ?, ?,
       ?, 'TICKET', ?, NULL,
       ?, ?, NULL,
       ?, NULL,
       ?, NULL,
       ?, ?, NULL,
       ?, ?, NULL, NULL,
       NULL, NULL,
       ?, ?, 0, ?, NOW(), NOW())
  ";

  $ins = $conn->prepare($sql);
  if(!$ins) throw new Exception("Prepare mutabakat_v2_rows fail: ".$conn->error);

  $ins->bind_param(
    "iiisisssssssdssi",
    $periodId,
    $mainAgencyId,
    $taliAgencyId,
    $tc_vn,
    $ticketId,
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

  if(!$ins->execute()){

    $err = $ins->error;
    $ins->close();
    throw new Exception("Execute mutabakat_v2_rows fail: ".$err);
  }
  $ins->close();

  // 4) tickets özet alanlarını güncelle + kilitleme zamanı
  $saleAmount = $brutVal; // SATIS pozitif zorunlu, IPTAL/ZEYIL +/- serbest

  $upT = $conn->prepare("
    UPDATE tickets
    SET
      policy_number=?,
      policy_no_norm=?,
      sale_amount=?,
      mutabakat_saved_at=NOW(),
      mutabakat_type=?,
      mutabakat_tanzim=?,
      updated_at=NOW()
    WHERE id=? LIMIT 1
  ");
  if (!$upT) throw new Exception("Prepare tickets update fail: ".$conn->error);
  $upT->bind_param("ssdssi", $police_no, $policyNorm, $saleAmount, $mutType, $tanzim_tarihi, $ticketId);
  if (!$upT->execute()) {
    $err = $upT->error;
    $upT->close();
    throw new Exception("Execute tickets update fail: ".$err);
  }
  $upT->close();

  $conn->commit();

  echo json_encode(['ok'=>true,'saved'=>true,'period_id'=>$periodId]);
  exit;

} catch (Throwable $e) {
  if ($conn && $conn->errno === 0) {
    // ignore
  }
  if ($conn && $conn->in_transaction) {
    @$conn->rollback();
  }
  if ($debug) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  } else {
    echo json_encode(['ok'=>false,'error'=>'İşlem sırasında hata oluştu']);
  }
  exit;
}
