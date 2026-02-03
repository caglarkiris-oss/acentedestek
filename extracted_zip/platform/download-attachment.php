<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) { http_response_code(403); exit("Yetkisiz"); }
}

$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit("Geçersiz dosya"); }

$conn = db();
$conn->set_charset((string)config('db.charset','utf8mb4'));

function norm_path(string $p): string {
  $p = trim($p);
  if ($p === '') return '';
  $p = str_replace('\\','/',$p);
  return rtrim($p, '/');
}

/* ticket_files + ticket scope */
$ticketId=0; $orig=''; $stored=''; $rel=''; $mime=''; $size=0; $cA=0; $tA=0;

$st = $conn->prepare("
  SELECT tf.ticket_id, tf.original_name, tf.stored_name, tf.storage_rel_path, tf.mime_type, tf.file_size,
         t.created_by_agency_id, t.target_agency_id
  FROM ticket_files tf
  JOIN tickets t ON t.id = tf.ticket_id
  WHERE tf.id = ?
  LIMIT 1
");
if (!$st) { http_response_code(500); exit("DB prepare hatası"); }

$st->bind_param("i", $id);
$st->execute();
$st->bind_result($ticketId, $orig, $stored, $rel, $mime, $size, $cA, $tA);
$found = $st->fetch();
$st->close();

if (!$found) { http_response_code(404); exit("Dosya bulunamadı"); }

$cA = (int)$cA; $tA = (int)$tA;
if (!($cA === $agencyId || $tA === $agencyId)) { http_response_code(403); exit("Erişim yok"); }

/* Güvenli path parçaları */
$relDir   = trim((string)$rel, "/\\");   // tickets/2025/12/19/ticket_XX/
$relDir   = trim($relDir, "/");
$storedFn = basename((string)$stored);
if ($storedFn === '') { http_response_code(404); exit("Dosya adı yok"); }

/* Upload ile aynı aday root'lar: config -> /public_html/storage -> /platform/storage */
$cfgRoot = norm_path((string)config('paths.storage_root', ''));
$candidates = array_values(array_filter([
  $cfgRoot,
  norm_path(__DIR__ . "/../storage"),
  norm_path(__DIR__ . "/storage"),
], fn($v)=>$v !== ''));

/* Dosyayı hangi root'ta bulursak onu kullan */
$fullPath = '';
foreach ($candidates as $root) {
  $p = $root . "/" . $relDir . "/" . $storedFn;
  $p = str_replace('\\','/',$p);
  $p = preg_replace('~/+~','/',$p);
  if (is_file($p)) { $fullPath = $p; break; }
}

if ($fullPath === '') { http_response_code(404); exit("Dosya diskte yok"); }

/* Output temizle */
if (function_exists('ob_get_level')) {
  while (ob_get_level() > 0) { @ob_end_clean(); }
}

/* Dosya adı güvenli */
$outName = (string)($orig ?: $storedFn);
$outName = trim($outName);
$outName = basename($outName);
$outName = str_replace(["\r","\n"], '', $outName);
if ($outName === '') $outName = $storedFn;

$mime = (string)($mime ?: "application/octet-stream");

/* UTF-8 filename* */
$asciiFallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $outName);
if (!$asciiFallback) $asciiFallback = "download";
$filenameStar = rawurlencode($outName);

header('X-Content-Type-Options: nosniff');
header('Content-Type: '.$mime);
header('Content-Length: '.(string)filesize($fullPath));
header('Content-Disposition: attachment; filename="'.$asciiFallback.'"; filename*=UTF-8\'\''.$filenameStar);
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$fp = fopen($fullPath, 'rb');
if ($fp === false) { http_response_code(500); exit("Dosya açılamadı"); }
while (!feof($fp)) { echo fread($fp, 1024 * 1024); }
fclose($fp);
exit;
