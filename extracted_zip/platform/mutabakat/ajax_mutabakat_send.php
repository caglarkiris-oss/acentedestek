<?php
// /public_html/platform/mutabakat/ajax_mutabakat_send.php
// Main -> Mutabakat Gönder (SENT) + Period OPEN (kilit)
// CSRF zorunlu, yetki kontrolü zorunlu, mysqli prepared
// Kolon farklıysa degrade eder ama error_log'a yazar.

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_ajax_mutabakat_send_error.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../db.php';

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
}

/* ---- Helpers (fallback) ---- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function json_fail(int $code, string $msg, array $extra = []): void {
  http_response_code($code);
  echo json_encode(array_merge(['ok'=>false,'msg'=>$msg], $extra));
  exit;
}

function json_ok(array $data = []): void {
  echo json_encode(array_merge(['ok'=>true], $data));
  exit;
}

// CSRF (fallback)
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['_csrf'];
  }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): bool {
    $sess = (string)($_SESSION['_csrf'] ?? '');
    return $token && $sess && hash_equals($sess, $token);
  }
}

/* ---- DB ---- */
$conn = db();
if (!$conn) json_fail(500, 'DB yok');

mysqli_report(MYSQLI_REPORT_OFF);

/* ---- Schema check helpers ---- */
function table_exists(mysqli $conn, string $table): bool {
  $sql = "SHOW TABLES LIKE ?";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("s", $table);
  $st->execute();
  $res = $st->get_result();
  $ok = $res && $res->num_rows > 0;
  $st->close();
  return $ok;
}

function column_exists(mysqli $conn, string $table, string $col): bool {
  static $cache = [];
  $key = $table . '::' . $col;
  if (array_key_exists($key, $cache)) return $cache[$key];

  $cache[$key] = false;
  if (!table_exists($conn, $table)) return false;

  $sql = "SHOW COLUMNS FROM `$table` LIKE ?";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("s", $col);
  $st->execute();
  $res = $st->get_result();
  $cache[$key] = ($res && $res->num_rows > 0);
  $st->close();
  return $cache[$key];
}

function pick_first_existing_column(mysqli $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (column_exists($conn, $table, $c)) return $c;
  }
  return null;
}

/* ---- Input ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_fail(405, 'Method not allowed');

$csrf = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!csrf_verify($csrf)) json_fail(403, 'CSRF doğrulaması başarısız');

$mode  = strtoupper(trim((string)($_POST['mode'] ?? '')));
$period = trim((string)($_POST['period'] ?? ''));
$taliId = (int)($_POST['tali_id'] ?? 0);

if (!in_array($mode, ['TICKET','CSV'], true)) json_fail(422, 'Mode geçersiz');
if ($period === '' || !preg_match('/^\d{4}\-\d{2}$/', $period)) json_fail(422, 'Period geçersiz (YYYY-MM beklenir)');
if ($taliId <= 0) json_fail(422, 'Tali ID geçersiz');

/* ---- Session / Role ---- */
$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

if ($userId <= 0 || $agencyId <= 0) json_fail(401, 'Unauthorized');

// Main rol kontrolü (degrade: rol isimleri farklı olabilir)
$isMain = false;
$roleUpper = strtoupper($role);
if (strpos($roleUpper, 'ACENTE') !== false && strpos($roleUpper, 'TALI') === false) $isMain = true;
if ($roleUpper === 'SUPERADMIN') $isMain = true;

// İstersen burada SUPERADMIN için de child check'i bypass etme, yine kontrol et.
if (!$isMain) json_fail(403, 'Yetkisiz (Main acente gerekli)');

/* ---- Child tali doğrulaması ---- */
function is_child_tali(mysqli $conn, int $mainAgencyId, int $taliAgencyId): array {
  // return ['ok'=>bool,'why'=>string]
  // Olası tablolar/kolonlar: agencies(id,parent_agency_id) veya agencies(id,main_agency_id) vb.
  $agencyTable = 'agencies';
  if (!table_exists($conn, $agencyTable)) {
    error_log("[send] agencies table yok, child doğrulaması yapılamadı");
    return ['ok'=>false,'why'=>'Agencies tablosu yok'];
  }

  $parentCol = pick_first_existing_column($conn, $agencyTable, ['parent_agency_id','parent_id','main_agency_id','main_id','owner_agency_id']);
  if (!$parentCol) {
    error_log("[send] agencies parent/main kolon bulunamadı (parent_agency_id|parent_id|main_agency_id|main_id|owner_agency_id)");
    return ['ok'=>false,'why'=>'Hiyerarşi kolonu bulunamadı'];
  }

  $sql = "SELECT id FROM `$agencyTable` WHERE id=? AND `$parentCol`=?";
  $st = $conn->prepare($sql);
  if (!$st) {
    error_log("[send] prepare fail (child check): ".$conn->error);
    return ['ok'=>false,'why'=>'Prepare fail'];
  }
  $st->bind_param("ii", $taliAgencyId, $mainAgencyId);
  $st->execute();
  $res = $st->get_result();
  $ok = ($res && $res->num_rows > 0);
  $st->close();

  if (!$ok) return ['ok'=>false,'why'=>"Tali $taliAgencyId main $mainAgencyId child değil (kolon=$parentCol)"];
  return ['ok'=>true,'why'=>'ok'];
}

$child = is_child_tali($conn, $agencyId, $taliId);
if (!$child['ok']) json_fail(403, 'Bu tali acente size bağlı değil', ['detail'=>$child['why']]);

/* ---- Tables ---- */
$subTable = 'mutabakat_submissions';
$perTable = 'mutabakat_periods';

if (!table_exists($conn, $subTable)) json_fail(500, 'mutabakat_submissions tablosu yok');
if (!table_exists($conn, $perTable)) {
  // Period tablosu yoksa: sadece submissions yapıp uyar.
  error_log("[send] mutabakat_periods yok, OPEN güncellenemedi");
}

/* ---- Columns mapping (submissions) ---- */
$col_mode   = pick_first_existing_column($conn, $subTable, ['mode']);
$col_period = pick_first_existing_column($conn, $subTable, ['period','period_key']);
$col_main   = pick_first_existing_column($conn, $subTable, ['main_agency_id','main_id','agency_id']);
$col_tali   = pick_first_existing_column($conn, $subTable, ['tali_agency_id','tali_id','child_agency_id']);
$col_status = pick_first_existing_column($conn, $subTable, ['status']);
$col_sentAt = pick_first_existing_column($conn, $subTable, ['sent_at']);
$col_resent = pick_first_existing_column($conn, $subTable, ['resent_count']);
$col_sentBy = pick_first_existing_column($conn, $subTable, ['sent_by','sent_user_id','user_id_sent']);

$missing = [];
foreach (['mode'=>$col_mode,'period'=>$col_period,'main'=>$col_main,'tali'=>$col_tali,'status'=>$col_status] as $k=>$v) {
  if (!$v) $missing[] = $k;
}
if ($missing) {
  error_log("[send] submissions zorunlu kolon eksik: ".implode(',', $missing));
  json_fail(500, 'Submissions şema uyumsuz (zorunlu kolon eksik)', ['missing'=>$missing]);
}

/* ---- Columns mapping (periods) ---- */
$per_main   = table_exists($conn,$perTable) ? pick_first_existing_column($conn, $perTable, ['main_agency_id','main_id','agency_id']) : null;
$per_tali   = table_exists($conn,$perTable) ? pick_first_existing_column($conn, $perTable, ['tali_agency_id','tali_id','child_agency_id']) : null;
$per_period = table_exists($conn,$perTable) ? pick_first_existing_column($conn, $perTable, ['period','period_key']) : null;
$per_status = table_exists($conn,$perTable) ? pick_first_existing_column($conn, $perTable, ['status']) : null;
$per_mode   = table_exists($conn,$perTable) ? pick_first_existing_column($conn, $perTable, ['mode']) : null; // bazı şemalarda period mode'a bağlı olabilir

/* ---- Transaction ---- */
$conn->begin_transaction();

try {
  // 1) submissions: var mı?
  $selectCols = "id";
  if ($col_resent) $selectCols .= ", `$col_resent`";

  $sqlSel = "SELECT $selectCols FROM `$subTable`
             WHERE `$col_mode`=? AND `$col_period`=? AND `$col_main`=? AND `$col_tali`=?
             LIMIT 1";
  $stSel = $conn->prepare($sqlSel);
  if (!$stSel) throw new Exception("prepare sel fail: ".$conn->error);

  $stSel->bind_param("ssii", $mode, $period, $agencyId, $taliId);
  $stSel->execute();
  $resSel = $stSel->get_result();
  $existing = $resSel ? $resSel->fetch_assoc() : null;
  $stSel->close();

  $nowSql = "NOW()";
  $newResent = null;

  if ($existing && isset($existing['id'])) {
    $id = (int)$existing['id'];

    // resent_count: varsa +1
    if ($col_resent) {
      $cur = isset($existing[$col_resent]) ? (int)$existing[$col_resent] : 0;
      $newResent = $cur + 1;
    }

    $sets = [];
    $types = "";
    $params = [];

    $sets[] = "`$col_status`='SENT'";
    if ($col_sentAt) $sets[] = "`$col_sentAt`=$nowSql";
    if ($col_sentBy) { $sets[] = "`$col_sentBy`=?"; $types .= "i"; $params[] = $userId; }
    if ($col_resent) { $sets[] = "`$col_resent`=?"; $types .= "i"; $params[] = $newResent; }

    if (!$sets) throw new Exception("update set empty");

    $sqlUp = "UPDATE `$subTable` SET ".implode(", ", $sets)." WHERE id=? LIMIT 1";
    $stUp = $conn->prepare($sqlUp);
    if (!$stUp) throw new Exception("prepare up fail: ".$conn->error);

    $types2 = $types . "i";
    $params[] = $id;

    $stUp->bind_param($types2, ...$params);
    if (!$stUp->execute()) throw new Exception("execute up fail: ".$stUp->error);
    $stUp->close();

  } else {
    // insert
    $cols = ["`$col_mode`","`$col_period`","`$col_main`","`$col_tali`","`$col_status`"];
    $vals = ["?","?","?","?","'SENT'"];
    $types = "ssii";
    $params = [$mode, $period, $agencyId, $taliId];

    if ($col_sentAt) { $cols[] = "`$col_sentAt`"; $vals[] = "NOW()"; }
    if ($col_sentBy) { $cols[] = "`$col_sentBy`"; $vals[] = "?"; $types .= "i"; $params[] = $userId; }
    if ($col_resent) { $cols[] = "`$col_resent`"; $vals[] = "0"; }

    $sqlIns = "INSERT INTO `$subTable` (".implode(",", $cols).") VALUES (".implode(",", $vals).")";
    $stIns = $conn->prepare($sqlIns);
    if (!$stIns) throw new Exception("prepare ins fail: ".$conn->error);
    $stIns->bind_param($types, ...$params);
    if (!$stIns->execute()) throw new Exception("execute ins fail: ".$stIns->error);
    $stIns->close();
  }

  // 2) periods: OPEN yap (kilit)
  $periodUpdated = false;
  if (table_exists($conn, $perTable) && $per_main && $per_tali && $per_period && $per_status) {

    // period tablosunda mode kolonu varsa onu da şart koşalım
    if ($per_mode) {
      $sqlPerSel = "SELECT id FROM `$perTable` WHERE `$per_period`=? AND `$per_main`=? AND `$per_tali`=? AND `$per_mode`=? LIMIT 1";
      $st = $conn->prepare($sqlPerSel);
      if (!$st) throw new Exception("prepare period sel fail: ".$conn->error);
      $st->bind_param("siis", $period, $agencyId, $taliId, $mode);
    } else {
      $sqlPerSel = "SELECT id FROM `$perTable` WHERE `$per_period`=? AND `$per_main`=? AND `$per_tali`=? LIMIT 1";
      $st = $conn->prepare($sqlPerSel);
      if (!$st) throw new Exception("prepare period sel fail: ".$conn->error);
      $st->bind_param("sii", $period, $agencyId, $taliId);
    }
    $st->execute();
    $rs = $st->get_result();
    $row = $rs ? $rs->fetch_assoc() : null;
    $st->close();

    if ($row && isset($row['id'])) {
      $pid = (int)$row['id'];
      $sqlPerUp = "UPDATE `$perTable` SET `$per_status`='OPEN' WHERE id=? LIMIT 1";
      $st2 = $conn->prepare($sqlPerUp);
      if (!$st2) throw new Exception("prepare period up fail: ".$conn->error);
      $st2->bind_param("i", $pid);
      if (!$st2->execute()) throw new Exception("execute period up fail: ".$st2->error);
      $st2->close();
      $periodUpdated = true;
    } else {
      // insert minimal
      $cols = ["`$per_period`","`$per_main`","`$per_tali`","`$per_status`"];
      $vals = ["?","?","?","'OPEN'"];
      $types = "sii";
      $params = [$period, $agencyId, $taliId];

      if ($per_mode) { $cols[]="`$per_mode`"; $vals[]="?"; $types.="s"; $params[]=$mode; }

      $sqlPerIns = "INSERT INTO `$perTable` (".implode(",", $cols).") VALUES (".implode(",", $vals).")";
      $st3 = $conn->prepare($sqlPerIns);
      if (!$st3) throw new Exception("prepare period ins fail: ".$conn->error);
      $st3->bind_param($types, ...$params);
      if (!$st3->execute()) throw new Exception("execute period ins fail: ".$st3->error);
      $st3->close();
      $periodUpdated = true;
    }
  } else {
    if (!table_exists($conn,$perTable)) {
      error_log("[send] period table yok -> OPEN set atlanıyor");
    } else {
      error_log("[send] period OPEN set için gerekli kolonlar yok (main/tali/period/status) -> atlandı");
    }
  }

  $conn->commit();

  json_ok([
    'msg' => 'Mutabakat gönderildi',
    'status' => 'SENT',
    'period_opened' => $periodUpdated,
  ]);

} catch (Throwable $e) {
  $conn->rollback();
  error_log("[send] EX: ".$e->getMessage());
  json_fail(500, 'Gönderim sırasında hata oluştu');
}
