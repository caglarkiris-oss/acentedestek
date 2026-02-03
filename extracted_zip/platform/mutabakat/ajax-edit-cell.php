<?php
// /public_html/platform/mutabakat/ajax-edit-cell.php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_mutabakat_ajax_edit_cell_error.log');
error_reporting(E_ALL);

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../db.php';

function jexit($ok, $message='', $extra=[]){
  echo json_encode(array_merge(['ok'=>$ok, 'message'=>$message], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) jexit(false,'Yetkisiz');
}

$conn = db();
if (!$conn) jexit(false,'DB yok');

$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$isMain = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
if (!$isMain) jexit(false,'Yetkisiz');

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!$csrf || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
  jexit(false,'CSRF hatası');
}

$taliId = (int)($_POST['tali_id'] ?? 0);
$period = trim((string)($_POST['period'] ?? ''));
$rowId  = (int)($_POST['row_id'] ?? 0);
$field  = trim((string)($_POST['field'] ?? ''));
$value  = trim((string)($_POST['value'] ?? ''));

if ($taliId <= 0 || $rowId <= 0) jexit(false,'Parametre hatası');
if (!preg_match('/^\d{4}\-\d{2}$/', $period)) jexit(false,'Period hatalı');

$allowedFields = [
  'tc_vn'       => 'text',
  'sigortali'   => 'text',
  'plaka'       => 'text',
  'sirket'      => 'text',
  'brans'       => 'text',
  'tip'         => 'enum_tip',
  'tanzim'      => 'date',
  'police_no'   => 'text',
  'net_komisyon'=> 'money',
];
if (!isset($allowedFields[$field])) jexit(false,'Alan izinli değil');

# Tali + parent kontrol
$st = $conn->prepare("SELECT id,parent_id FROM agencies WHERE id=? LIMIT 1");
if (!$st) jexit(false,'Agencies sorgu yok');
$st->bind_param("i",$taliId);
$st->execute();
$ar = $st->get_result()->fetch_assoc();
$st->close();
if (!$ar) jexit(false,'Tali bulunamadı');
$mainOfTali = (int)($ar['parent_id'] ?? 0);

if ($role !== 'SUPERADMIN' && $mainOfTali !== $agencyId) {
  jexit(false,'Bu tali size bağlı değil');
}

$mainId = ($role === 'SUPERADMIN') ? $mainOfTali : $agencyId;

# Kilit kontrol (mutabakat_periods varsa ve OPEN ise düzenleme kapalı)
$isLocked = false;
try {
  $dbRow = $conn->query("SELECT DATABASE() AS db");
  $db = $dbRow ? (($dbRow->fetch_assoc()['db'] ?? '') ?: '') : '';
  $hasPeriods = false;
  if ($db) {
    $stT = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name='mutabakat_periods' LIMIT 1");
    if ($stT) {
      $stT->bind_param("s",$db);
      $stT->execute();
      $hasPeriods = (bool)$stT->get_result()->fetch_assoc();
      $stT->close();
    }
  }
  if ($hasPeriods) {
    $stL = $conn->prepare("
      SELECT status
      FROM mutabakat_periods
      WHERE main_agency_id=? AND assigned_agency_id=? AND period=?
      LIMIT 1
    ");
    if ($stL) {
      $stL->bind_param("iis", $mainId, $taliId, $period);
      $stL->execute();
      $lr = $stL->get_result()->fetch_assoc();
      $stL->close();
      $periodStatus = (string)($lr['status'] ?? '');
      $isLocked = ($periodStatus === 'OPEN');
    }
  }
} catch(Throwable $e){}

if ($isLocked) jexit(false,'Bu dönem kilitli, düzenlenemez.');

# Mevcut değeri çek
$stG = $conn->prepare("
  SELECT $field AS curval
  FROM mutabakat_csv_rows_cache
  WHERE id=? AND main_agency_id=? AND tali_agency_id=? AND period=?
  LIMIT 1
");
if (!$stG) jexit(false,'Sorgu yok');
$stG->bind_param("iiis", $rowId, $mainId, $taliId, $period);
$stG->execute();
$gr = $stG->get_result()->fetch_assoc();
$stG->close();
if (!$gr) jexit(false,'Kayıt bulunamadı');

$oldValue = (string)($gr['curval'] ?? '');

# Normalize & validate
$type = $allowedFields[$field];
$saveValue = $value;
$displayValue = $value;

if ($type === 'enum_tip') {
  $v = strtoupper(trim($value));
  $v = strtr($v, ['İ'=>'I','ı'=>'I','Ş'=>'S','ş'=>'S','Ğ'=>'G','ğ'=>'G','Ü'=>'U','ü'=>'U','Ö'=>'O','ö'=>'O','Ç'=>'C','ç'=>'C']);
  if (!in_array($v, ['SATIS','ZEYIL','IPTAL'], true)) {
    jexit(false,'Tip sadece SATIS / ZEYIL / IPTAL olmalı');
  }
  $saveValue = $v;
  $displayValue = $v;
} elseif ($type === 'money') {
  // "1.700,00" / "1700" / "-700,00" -> DB'ye düz sayı string
  $v = str_replace([' ','.'], ['', ''], $value);
  $v = str_replace([','], ['.'], $v);
  if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $v)) {
    jexit(false,'Net formatı hatalı');
  }
  $saveValue = $v;
  // display: TR format
  $displayValue = number_format((float)$v, 2, ',', '.');
} elseif ($type === 'date') {
  // Accept YYYY-MM-DD only (boş da olabilir)
  $v = trim($value);
  if ($v !== '' && !preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $v)) {
    jexit(false,'Tanzim formatı YYYY-MM-DD olmalı');
  }
  $saveValue = $v;
  $displayValue = $v;
} else {
  // text
  $saveValue = mb_substr($value, 0, 255, 'UTF-8');
  $displayValue = $saveValue;
}

# Değişiklik yoksa çık
if ((string)$saveValue === (string)$oldValue) {
  jexit(true,'OK', ['display'=>$displayValue]);
}

# Update
$sql = "UPDATE mutabakat_csv_rows_cache
        SET $field=?
        WHERE id=? AND main_agency_id=? AND tali_agency_id=? AND period=?
        LIMIT 1";
$stU = $conn->prepare($sql);
if (!$stU) jexit(false,'Update hazırlanamıyor');
$stU->bind_param("siiis", $saveValue, $rowId, $mainId, $taliId, $period);
$ok = $stU->execute();
$stU->close();
if (!$ok) jexit(false,'Update başarısız');

# Log (mutabakat_edit_logs)
try {
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $source = 'CACHE'; // istersen 'TICKET' gibi tutabilirsin
  $editSessionId = null;

  $stL = $conn->prepare("
    INSERT INTO mutabakat_edit_logs
      (row_id, user_id, user_name, agency_id, edit_session_id, source, ip, user_agent, field_name, old_value, new_value, edited_at)
    VALUES
      (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
  ");
  if ($stL) {
    $stL->bind_param("iiisssssss",
      $rowId,
      $userId,
      $agencyId,
      $editSessionId,
      $source,
      $ip,
      $ua,
      $field,
      $oldValue,
      (string)$saveValue
    );
    $stL->execute();
    $stL->close();
  }
} catch(Throwable $e){
  // log fail = hard fail değil
}

jexit(true,'OK', ['display'=>$displayValue]);
