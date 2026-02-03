<?php
// ajax-ticket-upload.php  (TEK PARÇA - ticket_files uyumlu)

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

header('Content-Type: application/json; charset=utf-8');

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok'=>false,'error'=>'Yetkisiz']);
    exit;
  }
}

$userId    = (int)($_SESSION['user_id'] ?? 0);
$ticketId  = (int)($_POST['ticket_id'] ?? 0);
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : null;

if ($ticketId <= 0) { echo json_encode(['ok'=>false,'error'=>'Geçersiz ticket']); exit; }
if (empty($_FILES['file'])) { echo json_encode(['ok'=>false,'error'=>'Dosya yok']); exit; }

$file = $_FILES['file'];
if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Yükleme hatası']);
  exit;
}

/* LIMIT: 10 MB */
$maxBytes = 10 * 1024 * 1024;
if ((int)$file['size'] > $maxBytes) {
  echo json_encode(['ok'=>false,'error'=>'Dosya 10MB büyük olamaz']);
  exit;
}

/* EXT whitelist */
$allowedExt = ['pdf','xls','xlsx','doc','docx','png','jpg','jpeg','webp','txt','zip','rar'];

$origName = trim((string)($file['name'] ?? ''));
$origName = basename($origName);

$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if ($ext === '' || !in_array($ext, $allowedExt, true)) {
  echo json_encode(['ok'=>false,'error'=>'Dosya türü desteklenmiyor']);
  exit;
}

/* MIME (best-effort) */
$mime = (string)($file['type'] ?? '');
if (function_exists('finfo_open')) {
  $fi = finfo_open(FILEINFO_MIME_TYPE);
  if ($fi) {
    $det = finfo_file($fi, (string)$file['tmp_name']);
    if (is_string($det) && $det !== '') $mime = $det;
    finfo_close($fi);
  }
}

/* DB */
$conn = db();
$conn->set_charset((string)config('db.charset','utf8mb4'));

/* Ticket var mı? */
$chk = $conn->prepare("SELECT id FROM tickets WHERE id=? LIMIT 1");
$chk->bind_param("i", $ticketId);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
  $chk->close();
  echo json_encode(['ok'=>false,'error'=>'Ticket bulunamadı']);
  exit;
}
$chk->close();

/* ---------------- STORAGE RESOLUTION (FALLBACK’Lİ) ---------------- */

function norm_path(string $p): string {
  $p = trim($p);
  if ($p === '') return '';
  $p = str_replace('\\','/',$p);
  return rtrim($p, '/');
}

function ensure_dir(string $dir): bool {
  if ($dir === '') return false;
  if (is_dir($dir)) return true;
  return @mkdir($dir, 0755, true) || is_dir($dir);
}

$cfgRoot = (string)config('paths.storage_root', '');
$cfgRoot = norm_path($cfgRoot);

// Aday root’lar: config -> /public_html/storage -> /platform/storage
$candidates = array_values(array_filter([
  $cfgRoot,
  norm_path(__DIR__ . "/../storage"),   // ✅ önerilen: /public_html/storage
  norm_path(__DIR__ . "/storage"),      // fallback: /public_html/platform/storage
], fn($v)=>$v !== ''));

$storageRoot = '';
$why = [];

foreach ($candidates as $cand) {
  error_log("UPLOAD try storageRoot=" . $cand);

  if (!ensure_dir($cand)) {
    $why[] = "mkdir_fail:" . $cand;
    $last = error_get_last();
    error_log("UPLOAD mkdir FAIL for " . $cand . " last=" . json_encode($last, JSON_UNESCAPED_UNICODE));
    continue;
  }
  if (!is_writable($cand)) {
    $why[] = "not_writable:" . $cand;
    error_log("UPLOAD NOT WRITABLE: " . $cand);
    continue;
  }

  $storageRoot = $cand;
  break;
}

if ($storageRoot === '') {
  error_log("UPLOAD storageRoot RESOLVE FAIL reasons=" . json_encode($why, JSON_UNESCAPED_UNICODE));
  echo json_encode(['ok'=>false,'error'=>'Storage dizini oluşturulamadı']);
  exit;
}

/* Hedef klasör */
$y = date('Y'); $m = date('m'); $d = date('d');
$storageRelDir = "tickets/$y/$m/$d/ticket_$ticketId";
$targetDir     = $storageRoot . "/" . $storageRelDir;

error_log("UPLOAD resolved storageRoot=" . $storageRoot);
error_log("UPLOAD targetDir=" . $targetDir);

if (!ensure_dir($targetDir)) {
  $last = error_get_last();
  error_log("UPLOAD mkdir targetDir FAIL: " . $targetDir . " last=" . json_encode($last, JSON_UNESCAPED_UNICODE));
  echo json_encode(['ok'=>false,'error'=>'Storage dizini oluşturulamadı']);
  exit;
}
if (!is_writable($targetDir)) {
  error_log("UPLOAD targetDir NOT WRITABLE: " . $targetDir);
  echo json_encode(['ok'=>false,'error'=>'Storage dizini yazılabilir değil']);
  exit;
}

/* Random stored name */
try { $rand = bin2hex(random_bytes(8)); }
catch (Throwable $e) { $rand = uniqid('', true); }

$storedName = "f_" . $rand . "." . $ext;
$targetPath = $targetDir . "/" . $storedName;

/* Move */
if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
  $last = error_get_last();
  error_log("UPLOAD move_uploaded_file FAIL: " . $targetPath . " last=" . json_encode($last, JSON_UNESCAPED_UNICODE));
  echo json_encode(['ok'=>false,'error'=>'Dosya taşınamadı']);
  exit;
}

/* DB insert */
$fileSize = (int)($file['size'] ?? 0);

$stmt = $conn->prepare("
  INSERT INTO ticket_files
    (ticket_id, message_id, uploaded_by, original_name, stored_name, mime_type, file_size, storage_rel_path)
  VALUES
    (?,?,?,?,?,?,?,?)
");
if (!$stmt) {
  @unlink($targetPath);
  echo json_encode(['ok'=>false,'error'=>'DB hazırlama hatası']);
  exit;
}

$mid = $messageId;
$storageRelPath = $storageRelDir . "/";

$stmt->bind_param(
  "iiisssis",
  $ticketId,
  $mid,
  $userId,
  $origName,
  $storedName,
  $mime,
  $fileSize,
  $storageRelPath
);

if (!$stmt->execute()) {
  @unlink($targetPath);
  $stmt->close();
  echo json_encode(['ok'=>false,'error'=>'DB kayıt hatası']);
  exit;
}

$insertId = (int)$stmt->insert_id;
$stmt->close();

echo json_encode([
  'ok' => true,
  'file' => [
    'id' => $insertId,
    'ticket_id' => $ticketId,
    'message_id' => $messageId,
    'original_name' => $origName,
    'stored_name' => $storedName,
    'mime_type' => $mime,
    'file_size' => $fileSize,
    'storage_rel_path' => $storageRelPath
  ]
]);
