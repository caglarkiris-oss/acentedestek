<?php
// /public_html/platform/mutabakat/ajax-edit-tali-csv-cell.php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_ajax_edit_tali_csv_cell_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

function out($ok, $msg='', $extra=[]){
  echo json_encode(array_merge(['ok'=>$ok, 'message'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) out(false,'Yetkisiz');
}

$conn = db();
if (!$conn) out(false,'DB yok');

$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$isMain = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
if (!$isMain) out(false,'Yetkisiz'); // sadece MAIN düzenler

// CSRF
$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) out(false,'CSRF hatası');

$taliId = (int)($_POST['tali_id'] ?? 0);
$period = (string)($_POST['period'] ?? '');
$rowId  = (int)($_POST['row_id'] ?? 0);
$field  = trim((string)($_POST['field'] ?? ''));
$value  = (string)($_POST['value'] ?? '');

if ($taliId <= 0 || $rowId <= 0) out(false,'Parametre hatası');
if (!preg_match('/^\d{4}\-\d{2}$/', $period)) out(false,'Dönem hatası');

// allowed fields
$allowed = [
  'tc_vn'        => 'string',
  'sigortali'    => 'string',
  'plaka'        => 'string',
  'sirket'       => 'string',
  'brans'        => 'string',
  'tip'          => 'tip',
  'tanzim'       => 'date',
  'police_no'    => 'police',
  'net_komisyon' => 'money',
];
if (!isset($allowed[$field])) out(false,'Bu alan düzenlenemez');

function clean_tc_vn($s){ $s = trim((string)$s); $s = preg_replace('/\s+/', '', $s); return $s; }
function clean_plaka($s){ $s = mb_strtoupper(trim((string)$s), 'UTF-8'); $s = preg_replace('/\s+/', '', $s); return $s; }
function clean_police($s){ $s = trim((string)$s); $s = preg_replace('/\s+/', '', $s); return $s; }

function parse_money($s){
  $s = trim((string)$s);
  if ($s === '') return null;
  $s = str_replace(['₺','TL',' '], '', $s);
  $s = str_replace('.', '', $s);
  $s = str_replace(',', '.', $s);
  if (!is_numeric($s)) return null;
  return (float)$s;
}
function parse_date_any($s){
  $s = trim((string)$s);
  if ($s === '') return null;
  if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
  if (preg_match('/^(\d{4})\-(\d{2})\-(\d{2})$/', $s, $m)) return $s;
  return null;
}
function normalize_tip($tipRaw){
  $t = mb_strtoupper(trim((string)$tipRaw), 'UTF-8');
  $t = str_replace(['İ','I','Ş','Ğ','Ü','Ö','Ç','Â','Ê','Û'], ['I','I','S','G','U','O','C','A','E','U'], $t);
  $t = preg_replace('/\s+/', ' ', $t);
  if ($t === '') return '';
  if (strpos($t, 'SAT') === 0) return 'SATIS';
  if (strpos($t, 'ZEY') === 0) return 'ZEYIL';
  if (strpos($t, 'IPT') === 0) return 'IPTAL';
  if (strpos($t, 'IPTA') === 0) return 'IPTAL';
  return $t;
}

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

if (!table_exists($conn,'mutabakat_periods')) {
  out(false,'mutabakat_periods yok');
}

// kilit kontrol (OPEN=kilit)
try {
  $stLock = $conn->prepare("
    SELECT status
    FROM mutabakat_periods
    WHERE main_agency_id=? AND assigned_agency_id=? AND period=?
    LIMIT 1
  ");
  if ($stLock) {
    $stLock->bind_param("iis", $agencyId, $taliId, $period);
    $stLock->execute();
    $r = $stLock->get_result()->fetch_assoc();
    $stLock->close();
    $status = (string)($r['status'] ?? '');
    if ($status === 'OPEN') out(false,'Kilit açık, düzenlenemez');
  }
} catch(Throwable $e) {}

// tali ilişki kontrol (superadmin değilse)
if ($role !== 'SUPERADMIN') {
  try{
    $st = $conn->prepare("SELECT parent_id FROM agencies WHERE id=? LIMIT 1");
    if (!$st) out(false,'Sunucu hatası');
    $st->bind_param("i", $taliId);
    $st->execute();
    $a = $st->get_result()->fetch_assoc();
    $st->close();
    $parent = (int)($a['parent_id'] ?? 0);
    if ($parent !== $agencyId) out(false,'Bu tali size bağlı değil');
  } catch(Throwable $e){
    out(false,'Sunucu hatası');
  }
}

// aktif dosyayı bul (sadece aktif file içindeki satırlar düzenlensin)
try{
  $st = $conn->prepare("
    SELECT id
    FROM mutabakat_tali_csv_files
    WHERE main_agency_id=? AND tali_agency_id=? AND period=? AND is_active=1 AND deleted_at IS NULL
    ORDER BY id DESC
    LIMIT 1
  ");
  if(!$st) out(false,'Aktif dosya sorgu hatası');
  $st->bind_param("iis", $agencyId, $taliId, $period);
  $st->execute();
  $af = $st->get_result()->fetch_assoc();
  $st->close();
  $fileId = (int)($af['id'] ?? 0);
  if ($fileId <= 0) out(false,'Aktif dosya yok');
} catch(Throwable $e){
  out(false,'Sunucu hatası');
}

// normalizasyon
$finalValue = null;
$type = $allowed[$field];

if ($type === 'string') {
  $v = trim($value);
  if ($field === 'tc_vn') $v = clean_tc_vn($v);
  if ($field === 'plaka') $v = clean_plaka($v);
  $v = mb_substr($v, 0, 190, 'UTF-8');
  $finalValue = $v;
}
elseif ($type === 'police') {
  $v = clean_police($value);
  $v = mb_substr($v, 0, 80, 'UTF-8');
  $finalValue = $v;
}
elseif ($type === 'money') {
  $m = parse_money($value);
  if ($m === null && trim($value) !== '') out(false,'Brüt formatı hatalı');
  $finalValue = $m; // float or null
}
elseif ($type === 'date') {
  $d = parse_date_any($value);
  if ($d === null && trim($value) !== '') out(false,'Tarih formatı hatalı (YYYY-MM-DD veya DD.MM.YYYY)');
  $finalValue = $d; // string or null
}
elseif ($type === 'tip') {
  $t = normalize_tip($value);
  $t = mb_substr($t, 0, 30, 'UTF-8');
  $finalValue = $t;
} else {
  out(false,'Alan tipi hatası');
}

// update
try {
  // row gerçekten bu file’a mı ait?
  $stChk = $conn->prepare("
    SELECT id
    FROM mutabakat_tali_csv_rows
    WHERE id=? AND file_id=? AND main_agency_id=? AND tali_agency_id=? AND period=?
    LIMIT 1
  ");
  if (!$stChk) out(false,'Kontrol hatası');
  $stChk->bind_param("iiiis", $rowId, $fileId, $agencyId, $taliId, $period);
  $stChk->execute();
  $okRow = (bool)$stChk->get_result()->fetch_assoc();
  $stChk->close();
  if (!$okRow) out(false,'Satır bulunamadı');

  // dynamic sql güvenli: field allowlist ile geldi
  $sql = "UPDATE mutabakat_tali_csv_rows SET {$field}=? WHERE id=? LIMIT 1";
  $stUp = $conn->prepare($sql);
  if (!$stUp) out(false,'Update prepare hatası');

  if ($type === 'money') {
    if ($finalValue === null) {
      $null = null;
      $stUp->bind_param("si", $null, $rowId);
    } else {
      $stUp->bind_param("di", $finalValue, $rowId);
    }
  } else {
    // date/string/police/tip -> string/null
    $v = $finalValue;
    $stUp->bind_param("si", $v, $rowId);
  }

  $stUp->execute();
  $stUp->close();

  // response’ta UI’ye basılacak değer
  out(true,'OK', ['value' => $finalValue]);

} catch(Throwable $e){
  error_log("ajax-edit-tali-csv-cell err: ".$e->getMessage());
  out(false,'Sunucu hatası');
}
