<?php
// /public_html/platform/mutabakat/csv_detay.php  (MAIN + TALI aynı ekran)
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_mutabakat_csv_detay_error.log');
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

/* ROLE */
$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$isMain = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
$isTali = in_array($role, ['TALI_ACENTE_YETKILISI','PERSONEL'], true);

if (!$isMain && !$isTali) { http_response_code(403); exit('Yetkisiz'); }

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
function valid_period($p): bool { return (bool)preg_match('/^\d{4}\-\d{2}$/', (string)$p); }
function this_month(): string { return date('Y-m'); }
function add_days_dt(int $days): string { return date('Y-m-d H:i:s', time() + ($days * 86400)); }

function normalize_header(string $s): string {
  $s = mb_strtolower(trim($s), 'UTF-8');
  $s = str_replace(['İ','I'], ['i','i'], $s);
  $s = preg_replace('/\s+/', ' ', $s);
  $s = str_replace(['.',':','/','\\','-','_','(',')','[',']'], ' ', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  return trim($s);
}
function clean_tc_vn($s): string { $s = trim((string)$s); $s = preg_replace('/\s+/', '', $s); return $s; }
function clean_plaka($s): string { $s = mb_strtoupper(trim((string)$s), 'UTF-8'); $s = preg_replace('/\s+/', '', $s); return $s; }
function clean_police($s): string { $s = trim((string)$s); $s = preg_replace('/\s+/', '', $s); return $s; }

function parse_money($s): ?float {
  $s = trim((string)$s);
  if ($s === '') return null;
  $s = str_replace(['₺','TL',' '], '', $s);
  $s = str_replace('.', '', $s);
  $s = str_replace(',', '.', $s);
  if (!is_numeric($s)) return null;
  return (float)$s;
}
function parse_date_any($s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
  if (preg_match('/^(\d{4})\-(\d{2})\-(\d{2})$/', $s, $m)) return $s;
  return null;
}
function ensure_dir(string $dir): bool { if (is_dir($dir)) return true; return @mkdir($dir, 0775, true); }

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

/* ========= PARAMS ========= */
$selectedPeriod = (string)($_GET['period'] ?? '');
if (!valid_period($selectedPeriod)) $selectedPeriod = this_month();

// MAIN: tali seçebilir, TALI: kendi
$taliId = (int)($_GET['tali_id'] ?? 0);
if ($isTali) $taliId = $agencyId;
if ($taliId <= 0) { http_response_code(400); exit('tali_id gerekli'); }

/* ========= MAIN/TALI RELATION + WORK MODE CHECK ========= */
$mainId = 0;
$taliName = '';
try {
  $st = $conn->prepare("SELECT id, name, parent_id, work_mode FROM agencies WHERE id=? LIMIT 1");
  if (!$st) throw new Exception("prepare err: ".$conn->error);
  $st->bind_param("i", $taliId);
  $st->execute();
  $a = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$a) { http_response_code(404); exit('Tali bulunamadı'); }

  $taliName = (string)($a['name'] ?? ('Tali #'.$taliId));
  $mainId = (int)($a['parent_id'] ?? 0);
  $wm = strtolower((string)($a['work_mode'] ?? ''));

  if ($wm !== 'csv') { http_response_code(400); exit('Bu tali CSV modunda değil'); }

  if ($isMain) {
    if ($role !== 'SUPERADMIN') {
      if ($mainId !== $agencyId) { http_response_code(403); exit('Bu tali sizin acentenize bağlı değil'); }
    }
  } else {
    if ($mainId <= 0) { http_response_code(400); exit('Ana acente bulunamadı'); }
  }
} catch(Throwable $e) {
  error_log("csv_detay relation err: ".$e->getMessage());
  http_response_code(500);
  exit('Sunucu hatası');
}

/* ========= TABLE EXISTS CHECK ========= */
if (!table_exists($conn, 'mutabakat_tali_csv_files') || !table_exists($conn, 'mutabakat_tali_csv_rows')) {
  http_response_code(500);
  exit('DB tabloları yok: mutabakat_tali_csv_files / mutabakat_tali_csv_rows');
}

/* ========= KİLİT KONTROL (OPEN=kilit) ========= */
$hasPeriodsTable = table_exists($conn, 'mutabakat_periods');
$periodStatus = '';
$isLocked = false;

if ($hasPeriodsTable) {
  try {
    $st = $conn->prepare("
      SELECT status
      FROM mutabakat_periods
      WHERE main_agency_id=? AND assigned_agency_id=? AND period=?
      LIMIT 1
    ");
    if ($st) {
      $st->bind_param("iis", $mainId, $taliId, $selectedPeriod);
      $st->execute();
      $r = $st->get_result()->fetch_assoc();
      $st->close();
      $periodStatus = (string)($r['status'] ?? '');
      $isLocked = ($periodStatus === 'OPEN');
    }
  } catch(Throwable $e) {}
}

/* ========= CLEANUP (90g) ========= */
try {
  $now = date('Y-m-d H:i:s');

  $stDelRows = $conn->prepare("DELETE FROM mutabakat_tali_csv_rows WHERE expires_at < ?");
  if ($stDelRows) { $stDelRows->bind_param("s", $now); $stDelRows->execute(); $stDelRows->close(); }

  $stExp = $conn->prepare("
    SELECT id, file_path
    FROM mutabakat_tali_csv_files
    WHERE expires_at < ? AND deleted_at IS NULL
  ");
  if ($stExp) {
    $stExp->bind_param("s", $now);
    $stExp->execute();
    $rs = $stExp->get_result();
    $toExpire = [];
    while ($r = $rs->fetch_assoc()) $toExpire[] = $r;
    $stExp->close();

    foreach ($toExpire as $f) {
      $fid = (int)($f['id'] ?? 0);
      $rel = (string)($f['file_path'] ?? '');
      if ($fid > 0) {
        if ($rel) {
          $abs = realpath(__DIR__.'/..') . '/' . ltrim($rel, '/');
          if ($abs && is_file($abs)) @unlink($abs);
        }
        $stUp = $conn->prepare("UPDATE mutabakat_tali_csv_files SET is_active=0, deleted_at=? WHERE id=? LIMIT 1");
        if ($stUp) { $stUp->bind_param("si", $now, $fid); $stUp->execute(); $stUp->close(); }
      }
    }
  }
} catch(Throwable $e) {
  error_log("csv_detay cleanup err: ".$e->getMessage());
}

/* ========= TEMPLATE DOWNLOAD ========= */
if (($_GET['download'] ?? '') === 'template') {
  $headers = ["T.C / V.N.","Sigortalı","Plaka","Şirket","Branş","Tip","Tanzim","Poliçe No","Brüt"];
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="mutabakat_template.csv"');
  header('Pragma: no-cache');
  header('Expires: 0');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, $headers, ';');
  fclose($out);
  exit;
}

/* ========= ACTIVE FILE FETCH ========= */
$activeFile = null;
$activeRows = [];
$flash = '';

function get_active_file(mysqli $conn, int $mainId, int $taliId, string $period): ?array {
  try {
    $st = $conn->prepare("
      SELECT id
      FROM mutabakat_tali_csv_files
      WHERE main_agency_id=? AND tali_agency_id=? AND period=? AND is_active=1 AND deleted_at IS NULL
      ORDER BY id DESC
    ");
    if (!$st) return null;
    $st->bind_param("iis", $mainId, $taliId, $period);
    $st->execute();
    $rs = $st->get_result();
    $ids = [];
    while ($r = $rs->fetch_assoc()) $ids[] = (int)$r['id'];
    $st->close();

    if (empty($ids)) return null;

    $keep = $ids[0];
    if (count($ids) > 1) {
      $others = array_slice($ids, 1);
      $in = implode(',', array_fill(0, count($others), '?'));
      $types = str_repeat('i', count($others));
      $sql = "UPDATE mutabakat_tali_csv_files SET is_active=0 WHERE id IN ($in)";
      $st2 = $conn->prepare($sql);
      if ($st2) { $st2->bind_param($types, ...$others); $st2->execute(); $st2->close(); }
    }

    $st3 = $conn->prepare("
      SELECT id, period, main_agency_id, tali_agency_id, file_path, original_name, file_size, uploaded_at
      FROM mutabakat_tali_csv_files
      WHERE id=? LIMIT 1
    ");
    if (!$st3) return null;
    $st3->bind_param("i", $keep);
    $st3->execute();
    $row = $st3->get_result()->fetch_assoc();
    $st3->close();
    return $row ?: null;
  } catch(Throwable $e) {
    error_log("csv_detay get_active_file err: ".$e->getMessage());
    return null;
  }
}

/* ========= DOWNLOAD ACTIVE FILE ========= */
if (($_GET['download'] ?? '') === 'active') {
  $af = get_active_file($conn, $mainId, $taliId, $selectedPeriod);
  if (!$af) { http_response_code(404); exit('Aktif dosya yok'); }
  $rel = (string)($af['file_path'] ?? '');
  if (!$rel) { http_response_code(404); exit('Dosya yolu yok'); }

  $base = realpath(__DIR__.'/..');
  $abs = $base . '/' . ltrim($rel, '/');
  if (!$abs || !is_file($abs)) { http_response_code(404); exit('Dosya bulunamadı'); }

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.preg_replace('/[^a-zA-Z0-9\-\._]/','_', (string)$af['original_name']).'"');
  header('Content-Length: '.(string)filesize($abs));
  readfile($abs);
  exit;
}

/* ========= DELETE ACTIVE FILE (POST) ========= */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete_active') {
  $csrfPost = (string)($_POST['csrf_token'] ?? '');

  if ($isLocked) {
    $flash = 'Bu dönem mutabakat gönderildi (Açık). Dosya silinemez. Yeni döneme geç.';
  } elseif (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrfPost)) {
    $flash = 'CSRF hatası';
  } else {
    try {
      $af = get_active_file($conn, $mainId, $taliId, $selectedPeriod);
      if (!$af) throw new Exception('Aktif dosya yok');

      $fid = (int)($af['id'] ?? 0);
      $rel = (string)($af['file_path'] ?? '');
      $now = date('Y-m-d H:i:s');

      $conn->begin_transaction();

      $stDel = $conn->prepare("DELETE FROM mutabakat_tali_csv_rows WHERE file_id=?");
      if (!$stDel) throw new Exception("Prepare failed: ".$conn->error);
      $stDel->bind_param("i", $fid);
      $stDel->execute();
      $stDel->close();

      $stUp = $conn->prepare("UPDATE mutabakat_tali_csv_files SET is_active=0, deleted_at=? WHERE id=? LIMIT 1");
      if (!$stUp) throw new Exception("Prepare failed: ".$conn->error);
      $stUp->bind_param("si", $now, $fid);
      $stUp->execute();
      $stUp->close();

      $conn->commit();

      if ($rel) {
        $base = realpath(__DIR__.'/..');
        $abs = $base . '/' . ltrim($rel, '/');
        if ($abs && is_file($abs)) @unlink($abs);
      }

      header("Location: ".base_url("mutabakat/csv_detay.php?tali_id=".$taliId."&period=".$selectedPeriod."&deleted=1"));
      exit;

    } catch(Throwable $e) {
      try { $conn->rollback(); } catch(Throwable $e2) {}
      error_log("csv_detay delete err: ".$e->getMessage());
      $flash = 'Silme işlemi başarısız';
    }
  }
}

/* ========= TIP NORMALIZE ========= */
function normalize_tip($tipRaw): string {
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

/* ========= UPLOAD (POST) ========= */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'upload_csv') {
  $csrfPost = (string)($_POST['csrf_token'] ?? '');

  if ($isLocked) {
    $flash = 'Bu dönem mutabakat gönderildi (Açık). Dosya yüklenemez. Yeni döneme geç.';
  } elseif (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrfPost)) {
    $flash = 'CSRF hatası';
  } else {
    $periodPost = (string)($_POST['period'] ?? $selectedPeriod);
    if (!valid_period($periodPost)) $periodPost = $selectedPeriod;

    if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
      $flash = 'Dosya seçilmedi';
    } else {
      $tmp  = (string)$_FILES['csv_file']['tmp_name'];
      $name = (string)$_FILES['csv_file']['name'];
      $size = (int)($_FILES['csv_file']['size'] ?? 0);

      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if ($ext !== 'csv') {
        $flash = 'Sadece CSV yükleyebilirsin';
      } elseif ($size <= 0) {
        $flash = 'Dosya boş';
      } elseif ($size > 15 * 1024 * 1024) {
        $flash = 'Dosya çok büyük (max 15MB)';
      } else {
        $storedFile = '';
        try {
          $safeName = preg_replace('/[^a-zA-Z0-9\-\._]/', '_', $name);
          if (!$safeName) $safeName = 'mutabakat.csv';

          $basePlatform = realpath(__DIR__.'/..');
          if (!$basePlatform) throw new Exception("Platform base yok");

          $relDir = "uploads/mutabakat/tali/{$mainId}/{$taliId}/{$periodPost}";
          $absDir = $basePlatform . '/' . $relDir;

          if (!ensure_dir($absDir)) throw new Exception("Klasör oluşturulamadı: ".$absDir);

          $stamp = date('Ymd_His');
          $storedFile = $absDir . "/{$stamp}_" . $safeName;
          $relPath    = $relDir . "/{$stamp}_" . $safeName;

          if (!@move_uploaded_file($tmp, $storedFile)) throw new Exception("Dosya taşınamadı");

          $checksum = @hash_file('sha256', $storedFile) ?: null;
          $expires  = add_days_dt(90);
          $now      = date('Y-m-d H:i:s');

          $oldActive = get_active_file($conn, $mainId, $taliId, $periodPost);
          $oldFileId = $oldActive ? (int)$oldActive['id'] : 0;

          $conn->begin_transaction();

          $stIns = $conn->prepare("
            INSERT INTO mutabakat_tali_csv_files
              (period, main_agency_id, tali_agency_id, file_path, original_name, file_size, checksum, uploaded_by, uploaded_at, is_active, expires_at)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
          ");
          if (!$stIns) throw new Exception("Prepare failed: ".$conn->error);

          $stIns->bind_param(
            "siissisiss",
            $periodPost,
            $mainId,
            $taliId,
            $relPath,
            $name,
            $size,
            $checksum,
            $userId,
            $now,
            $expires
          );
          $stIns->execute();
          $newFileId = (int)$stIns->insert_id;
          $stIns->close();

          $stDeact = $conn->prepare("
            UPDATE mutabakat_tali_csv_files
            SET is_active=0
            WHERE main_agency_id=? AND tali_agency_id=? AND period=? AND id<>? AND deleted_at IS NULL
          ");
          if (!$stDeact) throw new Exception("Prepare failed: ".$conn->error);
          $stDeact->bind_param("iisi", $mainId, $taliId, $periodPost, $newFileId);
          $stDeact->execute();
          $stDeact->close();

          if ($oldFileId > 0) {
            $stOld = $conn->prepare("DELETE FROM mutabakat_tali_csv_rows WHERE file_id=?");
            if ($stOld) { $stOld->bind_param("i", $oldFileId); $stOld->execute(); $stOld->close(); }
          }

          $fh = fopen($storedFile, 'r');
          if (!$fh) throw new Exception("CSV açılamadı");

          $firstLine = fgets($fh);
          if ($firstLine === false) { fclose($fh); throw new Exception("CSV okunamadı"); }
          $delim = (substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';
          rewind($fh);

          $header = fgetcsv($fh, 0, $delim);
          if (!$header || count($header) < 2) { fclose($fh); throw new Exception("CSV header yok/bozuk"); }

          $map = [];
          foreach ($header as $idx => $hraw) {
            $k = normalize_header((string)$hraw);
            if (in_array($k, ['t c v n','tc vn','tc v n','tc vn no','t c v n no'], true)) $map['tc_vn'] = $idx;
            elseif (in_array($k, ['sigortali','sigortalı'], true)) $map['sigortali'] = $idx;
            elseif ($k === 'plaka') $map['plaka'] = $idx;
            elseif ($k === 'sirket' || $k === 'şirket') $map['sirket'] = $idx;
            elseif ($k === 'brans' || $k === 'branş') $map['brans'] = $idx;
            elseif ($k === 'tip') $map['tip'] = $idx;
            elseif ($k === 'tanzim') $map['tanzim'] = $idx;
            elseif (in_array($k, ['police no','poliçe no','police','poliçe'], true)) $map['police_no'] = $idx;
            elseif (in_array($k, ['brut','brüt','gross','gross premium','gross_premium'], true)) $map['net_komisyon'] = $idx;
          }

          $fallback = [
            'tc_vn' => 0, 'sigortali'=>1, 'plaka'=>2, 'sirket'=>3, 'brans'=>4,
            'tip'=>5, 'tanzim'=>6, 'police_no'=>7, 'net_komisyon'=>8
          ];
          foreach ($fallback as $k => $idx) {
            if (!isset($map[$k]) && isset($header[$idx])) $map[$k] = $idx;
          }

          $stRow = $conn->prepare("
            INSERT INTO mutabakat_tali_csv_rows
              (file_id, main_agency_id, tali_agency_id, period, tc_vn, sigortali, plaka, sirket, brans, tip, tanzim, police_no, net_komisyon, expires_at)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          ");
          if (!$stRow) { fclose($fh); throw new Exception("Prepare failed: ".$conn->error); }

          $rowCount = 0;
          while (($row = fgetcsv($fh, 0, $delim)) !== false) {
            $joined = implode('', array_map('trim', $row));
            if ($joined === '') continue;

            $tc   = clean_tc_vn($row[$map['tc_vn']] ?? '');
            $sig  = trim((string)($row[$map['sigortali']] ?? ''));
            $plk  = clean_plaka($row[$map['plaka']] ?? '');
            $sir  = trim((string)($row[$map['sirket']] ?? ''));
            $brn  = trim((string)($row[$map['brans']] ?? ''));
            $tipR = trim((string)($row[$map['tip']] ?? ''));
            $tip  = normalize_tip($tipR);
            $tzn  = parse_date_any($row[$map['tanzim']] ?? '');
            $pol  = clean_police($row[$map['police_no']] ?? '');
            $kom  = parse_money($row[$map['net_komisyon']] ?? '');

            if ($pol === '' && ($tc === '' && $plk === '')) continue;

            $stRow->bind_param(
              "iiisssssssssds",
              $newFileId,
              $mainId,
              $taliId,
              $periodPost,
              $tc,
              $sig,
              $plk,
              $sir,
              $brn,
              $tip,
              $tzn,
              $pol,
              $kom,
              $expires
            );
            $stRow->execute();
            $rowCount++;
          }
          fclose($fh);
          $stRow->close();

          $conn->commit();

          header("Location: ".base_url("mutabakat/csv_detay.php?tali_id=".$taliId."&period=".$periodPost."&ok=1&rows=".$rowCount));
          exit;

        } catch(Throwable $e) {
          try { $conn->rollback(); } catch(Throwable $e2) {}
          error_log("csv_detay upload err: ".$e->getMessage());
          if (!empty($storedFile) && is_file($storedFile)) @unlink($storedFile);
          $flash = 'Yükleme başarısız (loglara bak)';
        }
      }
    }
  }
}

/* ========= LOAD ACTIVE FILE + ROWS ========= */
$activeFile = get_active_file($conn, $mainId, $taliId, $selectedPeriod);
if ($activeFile) {
  try {
    $fid = (int)$activeFile['id'];

    $sql = "
      SELECT
        r.id, r.tc_vn, r.sigortali, r.plaka, r.sirket, r.brans, r.tip, r.tanzim, r.police_no, r.net_komisyon,
        c.match_status AS cache_match_status,
        c.match_reason AS cache_match_reason
      FROM mutabakat_tali_csv_rows r
      LEFT JOIN mutabakat_csv_rows_cache c
        ON c.main_agency_id = r.main_agency_id
       AND c.tali_agency_id = r.tali_agency_id
       AND c.period = r.period
       AND c.police_no IS NOT NULL AND c.police_no <> ''
       AND REPLACE(REPLACE(LOWER(c.police_no),' ',''),'\t','') = REPLACE(REPLACE(LOWER(r.police_no),' ',''),'\t','')
      WHERE r.file_id=?
      ORDER BY r.id ASC
      LIMIT 2000
    ";

    $st = $conn->prepare($sql);
    if ($st) {
      $st->bind_param("i", $fid);
      $st->execute();
      $rs = $st->get_result();
      while ($r = $rs->fetch_assoc()) $activeRows[] = $r;
      $st->close();
    }
  } catch(Throwable $e) {
    error_log("csv_detay rows load err: ".$e->getMessage());
  }
}

$canUploadBase = $isMain || ($isTali && $taliId === $agencyId);
$canUpload = ($canUploadBase && !$isLocked);

/* ✅ Edit yetkisi: sadece MAIN (SUPERADMIN/ACENTE_YETKILISI) + kilit kapalı */
$canEdit = ($isMain && !$isLocked);

$okFlag = (int)($_GET['ok'] ?? 0);
$deletedFlag = (int)($_GET['deleted'] ?? 0);
$rowsInserted = (int)($_GET['rows'] ?? 0);

require_once __DIR__ . '/../../layout/header.php';
?>



<section class="page">
  <div class="muta-wrap">
    <div class="cardX">

      <!-- ✅ DÜZELTİLDİ: Başlık 2 satır, toolbar sağ üstte tek satır -->
      <div class="mhead">

        <div class="mhead-top">
          <div class="mhead-title">
            <h1 class="mtitle">Mutabakat CSV Detay</h1>
            <div class="msub"><?= h($taliName) ?> — Dönem: <?= h($selectedPeriod) ?></div>
          </div>

          <div class="mhead-toolbar">
            <form method="get" action="<?= h(base_url('mutabakat/csv_detay.php')) ?>">
              <input type="hidden" name="tali_id" value="<?= (int)$taliId ?>">
              <select class="select" name="period" onchange="this.form.submit()">
                <?php
                  $opts = [];
                  for ($i=0;$i<12;$i++){
                    $opts[] = date('Y-m', strtotime(date('Y-m-01').' -'.$i.' month'));
                  }
                  if (!in_array($selectedPeriod, $opts, true)) array_unshift($opts, $selectedPeriod);
                  foreach ($opts as $p):
                ?>
                  <option value="<?= h($p) ?>" <?= ($p === $selectedPeriod) ? 'selected' : '' ?>><?= h($p) ?></option>
                <?php endforeach; ?>
              </select>
            </form>

            <a class="btn btn-blue" href="<?= h(base_url('mutabakat/csv_detay.php?tali_id='.$taliId.'&period='.$selectedPeriod.'&download=template')) ?>">CSV indir</a>

            <?php if ($activeFile): ?>
              <a class="btn btn-green" href="<?= h(base_url('mutabakat/csv_detay.php?tali_id='.$taliId.'&period='.$selectedPeriod.'&download=active')) ?>">Aktif CSV indir</a>
            <?php endif; ?>

            <?php if ($hasPeriodsTable): ?>
              <?php if ($isLocked): ?>
                <button class="btn btn-green" type="button" disabled>Mutabakat Gönderildi</button>
              <?php else: ?>
                <button class="btn btn-green" type="button" id="btnSendPeriodCsv">Mutabakat Gönder</button>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($canEdit): ?>
              <button class="btn btn-blue" type="button" id="btnToggleEdit" <?= $activeFile ? '' : 'disabled' ?>>Düzenle</button>
            <?php endif; ?>
          </div>
        </div>

        <div class="mhead-bottom">
          <div class="chips">
            <span class="pill muted">Tali ID: <?= (int)$taliId ?></span>
            <span class="pill muted">Main ID: <?= (int)$mainId ?></span>

            <?php if ($activeFile && !empty($activeFile['original_name'])): ?>
              <span class="pill ok">Aktif: <?= h((string)$activeFile['original_name']) ?></span>
            <?php else: ?>
              <span class="pill muted">Aktif Dosya: Yok</span>
            <?php endif; ?>

            <?php if ($hasPeriodsTable): ?>
              <?php if ($isLocked): ?>
                <span class="pill bad">Kilit: Açık</span>
              <?php else: ?>
                <span class="pill muted">Kilit: Kapalı</span>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($canEdit): ?>
              <span class="pill edit" id="editModePill">Düzenleme: Kapalı</span>
            <?php endif; ?>
          </div>
        </div>

      </div>

      <div class="toolbar">
        <div class="tleft">
          <a class="btn" href="<?= h(base_url('mutabakat/csv.php?period='.$selectedPeriod)) ?>">← Geri</a>
        </div>

        <div class="tright">

          <?php if ($canUploadBase && $isLocked): ?>
            <div class="alert warn">
              Bu dönem <b>Mutabakat Gönderildi</b>. Dosya yükleme/silme kilitli. Yeni döneme geçip yükleyebilirsin.
            </div>
          <?php endif; ?>

          <?php if ($canUploadBase): ?>
            <form id="uploadForm" method="post" enctype="multipart/form-data">
              <input type="hidden" name="action" value="upload_csv">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="period" value="<?= h($selectedPeriod) ?>">
              <input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" <?= $isLocked ? 'disabled' : '' ?>>

              <div class="drop" id="dropzone" title="CSV sürükle-bırak veya tıkla seç">
                <div>
                  <strong>CSV sürükle bırak veya tıkla</strong>
                  <span class="muted" id="fileLabel">Mevcut CSV</span>
                </div>
                <span class="tag">.csv</span>
                <button class="btn btn-blue" type="button" id="pickBtn" <?= $isLocked ? 'disabled' : '' ?>>Dosya Seç</button>
                <button class="btn btn-blue" type="submit" id="uploadBtn" disabled <?= $isLocked ? 'disabled' : '' ?>>Yükle</button>
              </div>
            </form>

            <?php if ($activeFile): ?>
              <form method="post"
                    onsubmit="return <?= $isLocked ? 'false' : 'confirm(\'Aktif CSV silinsin mi? Bu işlem geri alınamaz.\')' ?>;">
                <input type="hidden" name="action" value="delete_active">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <button class="btn btn-danger" type="submit" <?= $isLocked ? 'disabled' : '' ?>>CSV Sil</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>

        </div>
      </div>

      <hr class="hr">

      <?php if ($flash): ?>
        <div class="alert bad"><?= h($flash) ?></div>
      <?php elseif ($deletedFlag): ?>
        <div class="alert ok">CSV silindi.</div>
      <?php elseif ($okFlag): ?>
        <div class="alert ok">CSV yüklendi. (<?= (int)$rowsInserted ?> satır)</div>
      <?php endif; ?>

      <?php if (!$activeFile): ?>
        <div class="alert">
          Bu dönem için aktif CSV yok. <?= ($canUploadBase && !$isLocked) ? 'Yukarıdan dosya yükleyebilirsin.' : '' ?>
        </div>
      <?php endif; ?>

      <div class="table-wrap">
        <table id="csvDetailTable">
          <thead>
            <tr>
              <th class="copyable" data-col="0">ID</th>
              <th class="copyable" data-col="1">Kaynak</th>
              <th class="copyable" data-col="2">TC / VN</th>
              <th class="copyable" data-col="3">Sigortalı</th>
              <th class="copyable" data-col="4">Plaka</th>
              <th class="copyable" data-col="5">Şirket</th>
              <th class="copyable" data-col="6">Branş</th>
              <th class="copyable" data-col="7">Tip</th>
              <th class="copyable" data-col="8">Tanzim</th>
              <th class="copyable" data-col="9">Poliçe No</th>
              <th class="copyable" data-col="10">Brüt</th>
              <th class="copyable" data-col="11">Eşleşme</th>
            </tr>
          </thead>

          <tbody>
          <?php if (empty($activeRows)): ?>
            <tr><td colspan="12">Kayıt yok</td></tr>
          <?php else: ?>
            <?php foreach ($activeRows as $r): ?>
              <?php
                $txn = strtoupper(trim((string)($r['tip'] ?? '')));
                if (!in_array($txn, ['SATIS','ZEYIL','IPTAL'], true)) $txn = 'OTHER';

                $tanzim = (string)($r['tanzim'] ?? '');
                $pol    = (string)($r['police_no'] ?? '');
                $money  = $r['net_komisyon'];

                $ms = strtoupper(trim((string)($r['cache_match_status'] ?? '')));
                $badgeText = 'Eşleşmedi';
                $badgeCls  = 'no';
                if ($ms === 'MATCHED') { $badgeText = 'Eşleşti'; $badgeCls = 'ok'; }
                elseif ($ms === 'CHECK') { $badgeText = 'Kontrol'; $badgeCls='warn'; }

                $rid = (int)($r['id'] ?? 0);
              ?>
              <tr>
                <td><?= $rid ?></td>
                <td class="center"><span class="badge badge-source">CSV</span></td>

                <td data-row-id="<?= $rid ?>" data-field="tc_vn"><?= h((string)($r['tc_vn'] ?? '')) ?></td>
                <td data-row-id="<?= $rid ?>" data-field="sigortali"><?= h((string)($r['sigortali'] ?? '')) ?></td>
                <td data-row-id="<?= $rid ?>" data-field="plaka"><?= h((string)($r['plaka'] ?? '')) ?></td>
                <td data-row-id="<?= $rid ?>" data-field="sirket"><?= h((string)($r['sirket'] ?? '')) ?></td>
                <td data-row-id="<?= $rid ?>" data-field="brans"><?= h((string)($r['brans'] ?? '')) ?></td>

                <td data-row-id="<?= $rid ?>" data-field="tip">
                  <div class="cell-center">
                    <span class="badge badge-txn <?= h($txn) ?>">
                      <?= h($txn === 'OTHER' ? (string)($r['tip'] ?? '—') : $txn) ?>
                    </span>
                  </div>
                </td>

                <td data-row-id="<?= $rid ?>" data-field="tanzim"><?= h($tanzim) ?></td>
                <td data-row-id="<?= $rid ?>" data-field="police_no"><?= h($pol) ?></td>

                <td class="money" data-row-id="<?= $rid ?>" data-field="net_komisyon">
                  <?php
                    if ($money === null || $money === '') 
                    else echo number_format((float)$money, 2, ',', '.');
                  ?>
                </td>

                <td class="center">
                  <span class="badge badge-match <?= h($badgeCls) ?>"><?= h($badgeText) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>

  <div id="toast" class="toast">
    <div id="toastTitle">Kopyalandı</div>
    <div id="toastSub" class="sub"></div>
  </div>
</section>

<script>
(function(){
  // --- Mutabakat Gönder (OPEN) ---
  const sendBtn = document.getElementById('btnSendPeriodCsv');
  if(sendBtn){
    sendBtn.addEventListener('click', async ()=>{
      if(sendBtn.disabled) return;

      const fd = new FormData();
      fd.append('csrf_token', '<?= h($csrf) ?>');
      fd.append('tali_id', '<?= (int)$taliId ?>');
      fd.append('period', '<?= h($selectedPeriod) ?>');

      const old = sendBtn.textContent;
      sendBtn.disabled = true;
      sendBtn.textContent = 'Gönderiliyor...';

      try{
        const res = await fetch('<?= h(base_url("mutabakat/ajax-send-period-csv.php")) ?>', {
          method:'POST',
          credentials:'same-origin',
          body: fd
        });
        const js = await res.json().catch(()=>null);

        if(!res.ok || !js || js.ok !== true){
          alert((js && js.message) ? js.message : 'Gönderme hatası');
          sendBtn.disabled = false;
          sendBtn.textContent = old;
          return;
        }
        location.reload();
      } catch(e){
        alert('İstek atılamadı: ' + (e && e.message ? e.message : 'Hata'));
        sendBtn.disabled = false;
        sendBtn.textContent = old;
      }
    });
  }

  // --- Upload dropzone ---
  const dz = document.getElementById('dropzone');
  const fi = document.getElementById('csv_file');
  const pick = document.getElementById('pickBtn');
  const uploadBtn = document.getElementById('uploadBtn');
  const label = document.getElementById('fileLabel');

  if(dz && fi && pick && uploadBtn && label){
    function setFileName(){
      const f = fi.files && fi.files[0] ? fi.files[0] : null;
      if(!f){
        uploadBtn.disabled = true;
        label.textContent = "Mevcut CSV";
        return;
      }
      label.textContent = f.name;
      uploadBtn.disabled = false;
    }

    pick.addEventListener('click', ()=> fi.click());
    fi.addEventListener('change', setFileName);

    ['dragenter','dragover'].forEach(ev=>{
      dz.addEventListener(ev, (e)=>{
        e.preventDefault(); e.stopPropagation();
        dz.classList.add('is-drag');
      });
    });
    ['dragleave','drop'].forEach(ev=>{
      dz.addEventListener(ev, (e)=>{
        e.preventDefault(); e.stopPropagation();
        dz.classList.remove('is-drag');
      });
    });
    dz.addEventListener('drop', (e)=>{
      if(!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
      fi.files = e.dataTransfer.files;
      setFileName();
    });
    dz.addEventListener('click', (e)=>{
      const t = e.target;
      if(t && (t.id === 'pickBtn' || t.id === 'uploadBtn')) return;
      fi.click();
    });

    setFileName();
  }

  // --- Copy column + toast ---
  const table = document.getElementById('csvDetailTable');
  const toast = document.getElementById('toast');
  const toastTitle = document.getElementById('toastTitle');
  const toastSub = document.getElementById('toastSub');
  let toastTimer = null;

  function showToast(title, sub){
    if(!toast || !toastTitle || !toastSub) return;
    toastTitle.textContent = title || 'Kopyalandı';
    toastSub.textContent = sub || '';
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(()=> toast.classList.remove('show'), 1600);
  }

  function normalizeText(s){
    return (s || '').replace(/\u00a0/g,' ').replace(/\s+\n/g,'\n').trim();
  }

  function getColumnText(colIndex){
    if(!table) return '';
    const rows = table.querySelectorAll('tbody tr');
    const values = [];
    rows.forEach(tr=>{
      const tds = tr.querySelectorAll('td');
      if (!tds || tds.length <= colIndex) return;
      if (tds.length === 1) return;
      const cell = tds[colIndex];
      let text = '';
      const badge = cell.querySelector('.badge');
      if (badge) text = badge.textContent;
      else text = cell.textContent;
      text = normalizeText(text);
      if (text !== '') values.push(text);
    });
    return values.join('\n');
  }

  async function copyToClipboard(text){
    if (!text) return false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch(e){}
    try{
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      ta.style.top = '-9999px';
      document.body.appendChild(ta);
      ta.focus(); ta.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return ok;
    } catch(e){
      return false;
    }
  }

  // ✅ Edit Mode (persist) + hücreleri JS ile editable yap
  const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
  const btnToggle = document.getElementById('btnToggleEdit');
  const pill = document.getElementById('editModePill');

  const editableCells = document.querySelectorAll('td[data-row-id][data-field]');
  const STORAGE_KEY = 'muta_csv_edit_mode';

  function applyEditableState(on){
    editableCells.forEach(td=>{
      td.classList.toggle('editable', on);
    });
  }

  function setEditMode(on, persist=true){
    const v = !!on;
    window.__CSV_EDIT_MODE__ = v;

    if(pill){
      pill.textContent = v ? 'Düzenleme: Açık' : 'Düzenleme: Kapalı';
      pill.classList.toggle('edit-on', v);
    }
    if(btnToggle){
      btnToggle.textContent = v ? 'Düzenlemeyi Kapat' : 'Düzenle';
    }

    applyEditableState(v);

    if(persist){
      try{ localStorage.setItem(STORAGE_KEY, v ? '1' : '0'); }catch(e){}
    }
  }

  let initial = false;
  if(canEdit){
    try{
      const s = localStorage.getItem(STORAGE_KEY);
      if(s === '1') initial = true;
      if(s === '0') initial = false;
    }catch(e){}
  }
  setEditMode(initial, false);

  if(btnToggle && canEdit){
    btnToggle.addEventListener('click', ()=>{
      setEditMode(!window.__CSV_EDIT_MODE__);
    });
  }

  // Edit mod açıksa kolon kopyalama TIKLAMASI devre dışı
  if(table){
    const ths = table.querySelectorAll('thead th.copyable');
    ths.forEach(th=>{
      th.addEventListener('click', async (e)=>{
        if(window.__CSV_EDIT_MODE__ === true){
          e.preventDefault();
          return;
        }
        const col = parseInt(th.getAttribute('data-col') || '0', 10);
        const headerName = normalizeText(th.textContent);
        const text = getColumnText(col);
        const ok = await copyToClipboard(text);
        if (ok) showToast('Kolon kopyalandı', `${headerName} (${text ? text.split('\n').length : 0} satır)`);
        else showToast('Kopyalanamadı', 'Tarayıcı izin vermedi.');
      });
    });
  }

  async function saveCell(rowId, field, value){
    const fd = new FormData();
    fd.append('csrf_token', '<?= h($csrf) ?>');
    fd.append('tali_id', '<?= (int)$taliId ?>');
    fd.append('period', '<?= h($selectedPeriod) ?>');
    fd.append('row_id', String(rowId));
    fd.append('field', String(field));
    fd.append('value', String(value));

    const res = await fetch('<?= h(base_url("mutabakat/ajax-edit-tali-csv-cell.php")) ?>', {
      method:'POST',
      credentials:'same-origin',
      body: fd
    });
    const js = await res.json().catch(()=>null);
    if(!res.ok || !js || js.ok !== true){
      throw new Error((js && js.message) ? js.message : 'Sunucu hatası');
    }
    return js;
  }

  function formatMoneyTR(v){
    const s = (v ?? '').toString().trim();
    if(s === '') return '';
    let x = s.replace(/\s+/g,'').replace(/\./g,'').replace(',', '.');
    const n = Number(x);
    if(!isFinite(n)) return s;
    return n.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
  }

  function normTipClass(raw){
    const t = (raw || '').toString().trim().toUpperCase();
    if(t.startsWith('SAT')) return 'SATIS';
    if(t.startsWith('ZEY')) return 'ZEYIL';
    if(t.startsWith('IPT')) return 'IPTAL';
    if(t === '') return 'OTHER';
    return 'OTHER';
  }

  function renderTipCell(td, val){
    const cls = normTipClass(val);
    const label = (cls === 'OTHER') ? (val || '—') : cls;
    td.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'cell-center';
    const sp = document.createElement('span');
    sp.className = 'badge badge-txn ' + cls;
    sp.textContent = label;
    wrap.appendChild(sp);
    td.appendChild(wrap);
  }

  if(table){
    table.addEventListener('click', async (e)=>{
      if(!canEdit) return;
      if(window.__CSV_EDIT_MODE__ !== true) return;

      const td = e.target && e.target.closest ? e.target.closest('td.editable') : null;
      if(!td) return;

      const rowId = parseInt(td.getAttribute('data-row-id') || '0', 10);
      const field = (td.getAttribute('data-field') || '').trim();
      if(!rowId || !field) return;

      if(td.querySelector('input')) return;

      const oldText = (td.textContent || '').trim();

      const inp = document.createElement('input');
      inp.type = 'text';
      inp.className = 'edit-input';
      inp.value = oldText;

      td.innerHTML = '';
      td.appendChild(inp);
      inp.focus();
      inp.select();

      const finish = async (commit)=>{
        const newVal = (inp.value || '').trim();
        td.innerHTML = '';
        if(!commit){
          if(field === 'tip') renderTipCell(td, oldText);
          else td.textContent = oldText;
          return;
        }
        try{
          await saveCell(rowId, field, newVal);

          if(field === 'net_komisyon'){
            td.textContent = formatMoneyTR(newVal);
          } else if(field === 'tip'){
            renderTipCell(td, newVal);
          } else {
            td.textContent = newVal;
          }

          showToast('Kaydedildi', `${field} güncellendi`);
        } catch(err){
          if(field === 'tip') renderTipCell(td, oldText);
          else td.textContent = oldText;
          alert(err && err.message ? err.message : 'Sunucu hatası');
        }
      };

      inp.addEventListener('keydown', (ev)=>{
        if(ev.key === 'Enter'){ ev.preventDefault(); finish(true); }
        if(ev.key === 'Escape'){ ev.preventDefault(); finish(false); }
      });
      inp.addEventListener('blur', ()=> finish(true));
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
