<?php
// /public_html/platform/mutabakat/ajax_mutabakat_dispute.php
// Tali -> DISPUTED (+ note varsa)
// CSRF zorunlu, tali yalnızca kendi submission'ına itiraz eder.

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_ajax_mutabakat_dispute_error.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__.'/../../auth.php';
require_once __DIR__.'/../../helpers.php';
require_once __DIR__.'/../../db.php';

if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
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

// CSRF fallback
if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): bool {
    $sess = (string)($_SESSION['_csrf'] ?? '');
    return $token && $sess && hash_equals($sess, $token);
  }
}

$conn = db();
if (!$conn) json_fail(500,'DB yok');

function table_exists(mysqli $conn, string $table): bool {
  $sql = "SHOW TABLES LIKE ?";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("s",$table);
  $st->execute();
  $res = $st->get_result();
  $ok = $res && $res->num_rows>0;
  $st->close();
  return $ok;
}
function column_exists(mysqli $conn, string $table, string $col): bool {
  static $cache=[];
  $key=$table.'::'.$col;
  if (array_key_exists($key,$cache)) return $cache[$key];
  $cache[$key]=false;
  if (!table_exists($conn,$table)) return false;
  $sql="SHOW COLUMNS FROM `$table` LIKE ?";
  $st=$conn->prepare($sql);
  if(!$st) return false;
  $st->bind_param("s",$col);
  $st->execute();
  $res=$st->get_result();
  $cache[$key]=($res && $res->num_rows>0);
  $st->close();
  return $cache[$key];
}
function pick_first_existing_column(mysqli $conn, string $table, array $cands): ?string {
  foreach($cands as $c) if(column_exists($conn,$table,$c)) return $c;
  return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_fail(405,'Method not allowed');

$csrf = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!csrf_verify($csrf)) json_fail(403,'CSRF doğrulaması başarısız');

$mode   = strtoupper(trim((string)($_POST['mode'] ?? '')));
$period = trim((string)($_POST['period'] ?? ''));
$mainId = (int)($_POST['main_id'] ?? 0);
$note   = trim((string)($_POST['note'] ?? ''));

if (!in_array($mode,['TICKET','CSV'],true)) json_fail(422,'Mode geçersiz');
if ($period === '' || !preg_match('/^\d{4}\-\d{2}$/',$period)) json_fail(422,'Period geçersiz');
if ($mainId <= 0) json_fail(422,'Main ID geçersiz');

$userId = (int)($_SESSION['user_id'] ?? 0);
$taliId = (int)($_SESSION['agency_id'] ?? 0);
if ($userId<=0 || $taliId<=0) json_fail(401,'Unauthorized');

$subTable='mutabakat_submissions';
if (!table_exists($conn,$subTable)) json_fail(500,'mutabakat_submissions yok');

$col_mode   = pick_first_existing_column($conn,$subTable,['mode']);
$col_period = pick_first_existing_column($conn,$subTable,['period','period_key']);
$col_main   = pick_first_existing_column($conn,$subTable,['main_agency_id','main_id','agency_id']);
$col_tali   = pick_first_existing_column($conn,$subTable,['tali_agency_id','tali_id','child_agency_id']);
$col_status = pick_first_existing_column($conn,$subTable,['status']);

$col_disAt  = pick_first_existing_column($conn,$subTable,['disputed_at']);
$col_disBy  = pick_first_existing_column($conn,$subTable,['disputed_by','disputed_user_id']);
$col_note   = pick_first_existing_column($conn,$subTable,['note','dispute_note','notes','message']);

if (!$col_mode || !$col_period || !$col_main || !$col_tali || !$col_status) {
  error_log("[dispute] zorunlu kolon eksik");
  json_fail(500,'Submissions şema uyumsuz');
}

$set = "`$col_status`='DISPUTED'";
$types = "";
$params = [];

if ($col_disAt) $set .= ", `$col_disAt`=NOW()";
if ($col_disBy) { $set .= ", `$col_disBy`=?"; $types .= "i"; $params[] = $userId; }
if ($col_note)  { $set .= ", `$col_note`=?"; $types .= "s"; $params[] = $note; }
else {
  if ($note !== '') error_log("[dispute] note alanı yok, note kaydedilemedi");
}

$sql = "UPDATE `$subTable` SET $set
        WHERE `$col_mode`=? AND `$col_period`=? AND `$col_main`=? AND `$col_tali`=?
          AND `$col_status` IN ('SENT','DISPUTED','APPROVED')
        LIMIT 1";

$st = $conn->prepare($sql);
if (!$st) {
  error_log("[dispute] prepare fail: ".$conn->error);
  json_fail(500,'Hata');
}

// bind params: (optional fields...) + where fields
$types .= "ssii";
$params[] = $mode;
$params[] = $period;
$params[] = $mainId;
$params[] = $taliId;

$st->bind_param($types, ...$params);

if (!$st->execute()) {
  error_log("[dispute] exec fail: ".$st->error);
  $st->close();
  json_fail(500,'Hata');
}
$affected = $st->affected_rows;
$st->close();

if ($affected <= 0) json_fail(404,'Submission bulunamadı ya da yetkisiz');

json_ok(['msg'=>'İtiraz kaydedildi','status'=>'DISPUTED']);
