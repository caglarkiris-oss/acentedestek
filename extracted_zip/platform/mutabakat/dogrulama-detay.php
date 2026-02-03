<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_mutabakat_dogrulama_detay_error.log');
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

/* ✅ SADECE ANA ACENTE */
$isMain = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
if (!$isMain) { http_response_code(403); exit('Yetkisiz (Sadece Ana Acente)'); }

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function valid_period($p): bool { return (bool)preg_match('/^\d{4}\-\d{2}$/', (string)$p); }
function this_month(): string { return date('Y-m'); }
function strip_bom(string $s): string { return preg_replace('/^\xEF\xBB\xBF/', '', $s); }

function detect_delimiter(string $line): string {
  $cands = [';'=>substr_count($line,';'), ','=>substr_count($line,','), "\t"=>substr_count($line,"\t")];
  arsort($cands);
  $best = array_key_first($cands);
  if (!$best || ($cands[$best] ?? 0) === 0) return ';';
  return $best;
}
function normalize_header(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  $s = mb_strtolower($s, 'UTF-8');
  $map = ['ı'=>'i','İ'=>'i','ş'=>'s','Ş'=>'s','ğ'=>'g','Ğ'=>'g','ü'=>'u','Ü'=>'u','ö'=>'o','Ö'=>'o','ç'=>'c','Ç'=>'c'];
  return strtr($s, $map);
}
function norm_key(?string $s): string {
  $s = (string)$s;
  $s = trim($s);
  $s = preg_replace('/\s+/', '', $s);
  $s = mb_strtolower($s, 'UTF-8');
  return $s;
}
function normalize_plate(?string $s): string {
  $s = (string)$s;
  $s = mb_strtoupper($s, 'UTF-8');
  $s = preg_replace('/\s+/', '', $s);
  $map = ['İ'=>'I','ı'=>'I','ş'=>'S','ğ'=>'G','ü'=>'U','ö'=>'O','ç'=>'C'];
  $s = strtr($s, $map);
  return $s;
}
function to_mysql_date(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
  if (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $s)) return $s;
  $ts = strtotime($s);
  if ($ts !== false) return date('Y-m-d', $ts);
  return null;
}
function resolve_abs_from_stored_path(string $storedPath): string {
  $p = trim($storedPath);
  $p = ltrim($p, '/');
  if (stripos($p, 'platform/') === 0) $p = substr($p, strlen('platform/'));
  return dirname(__DIR__) . '/' . $p;
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
function column_exists(mysqli $conn, string $table, string $col): bool {
  try {
    $dbRow = $conn->query("SELECT DATABASE() AS db");
    $db = $dbRow ? (($dbRow->fetch_assoc()['db'] ?? '') ?: '') : '';
    if (!$db) return false;
    $st = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=? LIMIT 1");
    if (!$st) return false;
    $st->bind_param("sss", $db, $table, $col);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_assoc();
    $st->close();
    return $ok;
  } catch(Throwable $e) { return false; }
}

/* -------------------------
   INPUTS
------------------------- */
$taliId = (int)($_GET['tali_id'] ?? 0);
$period = trim((string)($_GET['period'] ?? ''));
if ($taliId <= 0) { http_response_code(400); exit('tali_id gerekli'); }
if (!valid_period($period)) $period = this_month();

/* ✅ Tali validasyonu */
$taliName = 'Tali';
$mainOfTali = 0;
$workModeUp = 'TICKET';

try {
  $st = $conn->prepare("SELECT id,name,parent_id,work_mode FROM agencies WHERE id=? LIMIT 1");
  if ($st) {
    $st->bind_param("i", $taliId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$r) { http_response_code(404); exit('Tali bulunamadı'); }
    $taliName   = (string)($r['name'] ?? 'Tali');
    $mainOfTali = (int)($r['parent_id'] ?? 0);
    $wm         = strtoupper(trim((string)($r['work_mode'] ?? 'TICKET')));
    if (in_array($wm, ['TICKET','CSV'], true)) $workModeUp = $wm;
  }
} catch(Throwable $e){}

if ($mainOfTali !== $agencyId) { http_response_code(403); exit('Bu tali size bağlı değil'); }

/* -------------------------
   HEADERS (CSV Template)
------------------------- */
$TEMPLATE_HEADERS = ["T.C / V.N.","Sigortalı","Plaka","Şirket","Branş","Tip","Tanzim","Poliçe No","Net Komisyon"];

/* -------------------------
   CSRF
------------------------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrfToken = (string)$_SESSION['csrf_token'];

/* -------------------------
   TABLES
------------------------- */
$FILES_TABLE       = 'mutabakat_main_csv_files';
$CACHE_TABLE       = 'mutabakat_csv_rows_cache';
$TICKET_ROWS_TABLE = 'mutabakat_rows';
$TALI_FILES_TABLE  = 'mutabakat_tali_csv_files';
$TALI_ROWS_TABLE   = 'mutabakat_tali_csv_rows';
$SUB_TABLE         = 'mutabakat_submissions';

$hasFilesTable     = table_exists($conn, $FILES_TABLE);
$hasCacheTable     = table_exists($conn, $CACHE_TABLE);
$hasTicketRows     = table_exists($conn, $TICKET_ROWS_TABLE);
$hasTaliFilesTable = table_exists($conn, $TALI_FILES_TABLE);
$hasTaliRowsTable  = table_exists($conn, $TALI_ROWS_TABLE);

$hasSubTable       = table_exists($conn, $SUB_TABLE);
$hasMainFinalCol   = $hasSubTable ? column_exists($conn, $SUB_TABLE, 'main_final') : false;

/* -------------------------
   Flash
------------------------- */
$flashOk  = (string)($_SESSION['flash_ok'] ?? '');
$flashErr = (string)($_SESSION['flash_err'] ?? '');
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

/* -------------------------
   CSV reader
------------------------- */
function read_csv_all_rows(string $absPath, array $TEMPLATE_HEADERS, string &$err, int $maxRows = 20000): array {
  $rows = [];
  $err = '';
  if (!is_file($absPath)) { $err = 'CSV dosyası bulunamadı: '.$absPath; return []; }

  $fh = fopen($absPath, 'rb');
  if (!$fh) { $err = 'CSV açılamadı'; return []; }

  $firstLine = fgets($fh);
  if ($firstLine === false) { fclose($fh); $err='Dosya boş'; return []; }
  $firstLine = strip_bom($firstLine);
  $delim = detect_delimiter($firstLine);

  rewind($fh);
  $headers = fgetcsv($fh, 0, $delim);
  if (!$headers || count($headers) < 3) { fclose($fh); $err='Başlık okunamadı'; return []; }

  $normIncoming = array_map(fn($x)=>normalize_header(strip_bom((string)$x)), $headers);
  $normTemplate = array_map(fn($x)=>normalize_header((string)$x), $TEMPLATE_HEADERS);

  $incomingSlice = array_slice($normIncoming, 0, count($normTemplate));
  if ($incomingSlice !== $normTemplate) {
    fclose($fh);
    $err='Template başlıkları uyuşmuyor.';
    return [];
  }

  $count = 0;
  while (($row = fgetcsv($fh, 0, $delim)) !== false) {
    $allEmpty = true;
    foreach ($row as $cell) { if (trim((string)$cell) !== '') { $allEmpty = false; break; } }
    if ($allEmpty) continue;

    $row = array_slice($row, 0, count($TEMPLATE_HEADERS));
    $row = array_pad($row, count($TEMPLATE_HEADERS), '');
    $rows[] = $row;

    $count++;
    if ($count >= $maxRows) break;
  }

  fclose($fh);
  return $rows;
}

/* -------------------------
   Tek aktif dosya kuralı (MAIN)
------------------------- */
function enforce_single_active(mysqli $conn, string $FILES_TABLE, int $mainId, int $taliId, string $period): void {
  try {
    $st = $conn->prepare("
      SELECT id
      FROM {$FILES_TABLE}
      WHERE main_agency_id=? AND tali_id=? AND period=? AND is_active=1
      ORDER BY id DESC
    ");
    if (!$st) return;
    $st->bind_param("iis", $mainId, $taliId, $period);
    $st->execute();
    $rs = $st->get_result();
    $ids = [];
    while ($r = $rs->fetch_assoc()) $ids[] = (int)$r['id'];
    $st->close();

    if (count($ids) <= 1) return;
    $keep = $ids[0];

    foreach ($ids as $id) {
      if ($id === $keep) continue;
      $stU = $conn->prepare("UPDATE {$FILES_TABLE} SET is_active=0, deleted_at=COALESCE(deleted_at,NOW()) WHERE id=? LIMIT 1");
      if ($stU) { $stU->bind_param("i", $id); $stU->execute(); $stU->close(); }
    }
  } catch(Throwable $e) { error_log("enforce_single_active err: ".$e->getMessage()); }
}
if ($hasFilesTable) enforce_single_active($conn, $FILES_TABLE, $agencyId, $taliId, $period);

/* -------------------------
   Aktif CSV (MAIN upload)
------------------------- */
$currentFile = null;
$hasCsvForPeriod = false;
$currentFileId = 0;

if ($hasFilesTable) {
  try {
    $st = $conn->prepare("
      SELECT id, stored_path, original_name, created_at, expires_at
      FROM {$FILES_TABLE}
      WHERE main_agency_id=? AND tali_id=? AND period=? AND is_active=1
      ORDER BY id DESC LIMIT 1
    ");
    if ($st) {
      $st->bind_param("iis", $agencyId, $taliId, $period);
      $st->execute();
      $currentFile = $st->get_result()->fetch_assoc() ?: null;
      $st->close();
    }
  } catch(Throwable $e) {}
}
if ($currentFile && !empty($currentFile['stored_path'])) {
  $hasCsvForPeriod = true;
  $currentFileId = (int)($currentFile['id'] ?? 0);
}

/* -------------------------
   URLs
------------------------- */
$selfUrl     = base_url('mutabakat/dogrulama-detay.php?tali_id='.$taliId.'&period='.$period);
$backUrl     = base_url('mutabakat/dogrulama.php?period='.$period);
$downloadUrl = $hasCsvForPeriod ? base_url('mutabakat/dogrulama-detay.php?tali_id='.$taliId.'&period='.$period.'&download=1') : '';

/* -------------------------
   Download
------------------------- */
if (($_GET['download'] ?? '') === '1') {
  if (!$currentFile || empty($currentFile['stored_path'])) { http_response_code(404); exit('Dosya yok'); }
  $abs = resolve_abs_from_stored_path((string)$currentFile['stored_path']);
  if (!is_file($abs)) { http_response_code(404); exit('Dosya bulunamadı'); }
  $orig = (string)($currentFile['original_name'] ?? ('mutabakat_'.$taliId.'_'.$period.'.csv'));
  $orig = preg_replace('/[\r\n]+/', '', $orig);

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$orig.'"');
  header('Pragma: no-cache');
  header('Expires: 0');
  readfile($abs);
  exit;
}

/* -------------------------
   GET: Cache satırları
------------------------- */
$cacheRows = [];
if ($hasCacheTable && $currentFileId > 0) {
  try {
    $st = $conn->prepare("
      SELECT id, tc_vn, sigortali, plaka, sirket, brans, tip, tanzim, police_no, net_komisyon, match_status, match_reason
      FROM {$CACHE_TABLE}
      WHERE file_id=? AND main_agency_id=? AND tali_agency_id=? AND period=?
      ORDER BY id ASC
      LIMIT 5000
    ");
    if ($st) {
      $st->bind_param("iiis", $currentFileId, $agencyId, $taliId, $period);
      $st->execute();
      $rs = $st->get_result();
      while ($r = $rs->fetch_assoc()) $cacheRows[] = $r;
      $st->close();
    }
  } catch(Throwable $e) {}
}

/* -------------------------
   ACTIONS (POST)
------------------------- */
$action = (string)($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    $_SESSION['flash_err'] = 'CSRF hatası';
    header("Location: ".$selfUrl);
    exit;
  }

  /* ✅ UPLOAD CSV (MAIN) -> FILES_TABLE + CACHE_TABLE */
  if ($action === 'upload_csv') {
    if (!$hasFilesTable) { $_SESSION['flash_err'] = 'Dosya tablosu yok: '.$FILES_TABLE; header("Location: ".$selfUrl); exit; }
    if (!$hasCacheTable) { $_SESSION['flash_err'] = 'Cache tablosu yok: '.$CACHE_TABLE; header("Location: ".$selfUrl); exit; }

    if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
      $_SESSION['flash_err'] = 'Dosya bulunamadı';
      header("Location: ".$selfUrl); exit;
    }

    $f = $_FILES['csv_file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $_SESSION['flash_err'] = 'Dosya yükleme hatası';
      header("Location: ".$selfUrl); exit;
    }

    $origName = (string)($f['name'] ?? 'mutabakat.csv');
    $tmpPath  = (string)($f['tmp_name'] ?? '');
    $size     = (int)($f['size'] ?? 0);
    $mime     = (string)($f['type'] ?? 'text/csv');

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
      $_SESSION['flash_err'] = 'Lütfen Excel’den CSV (UTF-8) olarak kaydedip yükle (.csv).';
      header("Location: ".$selfUrl); exit;
    }

    $subDir = 'uploads/mutabakat/main/'.date('Ym').'/';
    $absDir = dirname(__DIR__).'/'.$subDir;
    if (!is_dir($absDir)) { @mkdir($absDir, 0775, true); }
    if (!is_dir($absDir)) {
      $_SESSION['flash_err'] = 'Upload klasörü oluşturulamadı';
      header("Location: ".$selfUrl); exit;
    }

    $safeOrig = preg_replace('/[^a-zA-Z0-9_\.\-]+/','_', $origName);
    $storedName = 'tali_'.$taliId.'_'.$period.'_'.date('His').'_'.bin2hex(random_bytes(4)).'_'.$safeOrig;
    $absFile = $absDir.$storedName;

    if (!move_uploaded_file($tmpPath, $absFile)) {
      $_SESSION['flash_err'] = 'Dosya taşınamadı';
      header("Location: ".$selfUrl); exit;
    }

    $storedPath = '/platform/'.$subDir.$storedName;

    $readErr = '';
    $rows = read_csv_all_rows($absFile, $TEMPLATE_HEADERS, $readErr, 20000);
    if ($readErr !== '') {
      @unlink($absFile);
      $_SESSION['flash_err'] = 'CSV okunamadı: '.$readErr;
      header("Location: ".$selfUrl); exit;
    }
    if (empty($rows)) {
      @unlink($absFile);
      $_SESSION['flash_err'] = 'CSV içinde satır bulunamadı';
      header("Location: ".$selfUrl); exit;
    }

    try {
      $conn->begin_transaction();

      $stOff = $conn->prepare("UPDATE {$FILES_TABLE} SET is_active=0, deleted_at=COALESCE(deleted_at, NOW()) WHERE main_agency_id=? AND tali_id=? AND period=? AND is_active=1");
      if ($stOff) {
        $stOff->bind_param("iis", $agencyId, $taliId, $period);
        $stOff->execute();
        $stOff->close();
      }

      $stIns = $conn->prepare("
        INSERT INTO {$FILES_TABLE}
          (main_agency_id, tali_id, period, is_active, original_name, stored_name, stored_path, file_size, mime_type, uploaded_by, created_at, expires_at)
        VALUES
          (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY))
      ");
      if (!$stIns) throw new Exception('Files insert prepare hata: '.$conn->error);

      $stIns->bind_param("iissssisi",
        $agencyId, $taliId, $period,
        $origName, $storedName, $storedPath,
        $size, $mime, $userId
      );
      if (!$stIns->execute()) throw new Exception('Files insert hata: '.$stIns->error);
      $fileId = (int)$stIns->insert_id;
      $stIns->close();

      $stDel = $conn->prepare("DELETE FROM {$CACHE_TABLE} WHERE main_agency_id=? AND tali_agency_id=? AND period=?");
      if ($stDel) {
        $stDel->bind_param("iis", $agencyId, $taliId, $period);
        $stDel->execute();
        $stDel->close();
      }

      $stRow = $conn->prepare("
        INSERT INTO {$CACHE_TABLE}
          (file_id, main_agency_id, tali_agency_id, period,
           tc_vn, sigortali, plaka, sirket, brans, tip, tanzim, police_no, net_komisyon,
           match_status, match_reason, matched_ticket_id,
           created_at, expires_at)
        VALUES
          (?, ?, ?, ?,
           ?, ?, ?, ?, ?, ?, ?, ?, ?,
           NULL, NULL, NULL,
           NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY))
      ");
      if (!$stRow) throw new Exception('Cache insert prepare hata: '.$conn->error);

      $insCount = 0;
      foreach ($rows as $row) {
        $tc   = (string)($row[0] ?? '');
        $sig  = (string)($row[1] ?? '');
        $plk  = (string)($row[2] ?? '');
        $sir  = (string)($row[3] ?? '');
        $br   = (string)($row[4] ?? '');
        $tip  = (string)($row[5] ?? '');
        $tanRaw = (string)($row[6] ?? '');
        $tan    = to_mysql_date($tanRaw) ?? null;
        $pol  = (string)($row[7] ?? '');
        $net  = (string)($row[8] ?? '');

        $all = trim($tc.$sig.$plk.$sir.$br.$tip.$tan.$pol.$net);
        if ($all === '') continue;

        $stRow->bind_param(
          "iiissssssssss",
          $fileId, $agencyId, $taliId, $period,
          $tc, $sig, $plk, $sir, $br, $tip, $tan, $pol, $net
        );
        if (!$stRow->execute()) throw new Exception('Cache insert hata: '.$stRow->error);
        $insCount++;
        if ($insCount >= 20000) break;
      }
      $stRow->close();

      $conn->commit();

      // ✅ Teknik detay yok (cache vs. demiyoruz)
      $_SESSION['flash_ok'] = "CSV yüklendi. Satır: {$insCount}";
      header("Location: ".$selfUrl);
      exit;

    } catch(Throwable $e) {
      $conn->rollback();
      error_log("upload_csv err: ".$e->getMessage());
      $_SESSION['flash_err'] = 'CSV yükleme hatası';
      header("Location: ".$selfUrl);
      exit;
    }
  }

  /* ✅ DELETE CSV (MAIN) */
  if ($action === 'delete_csv') {
    if (!$hasFilesTable) { $_SESSION['flash_err'] = 'Dosya tablosu yok: '.$FILES_TABLE; header("Location: ".$selfUrl); exit; }
    if (!$hasCacheTable) { $_SESSION['flash_err'] = 'Cache tablosu yok: '.$CACHE_TABLE; header("Location: ".$selfUrl); exit; }
    if (!$hasCsvForPeriod || $currentFileId <= 0) { $_SESSION['flash_err'] = 'Silinecek aktif CSV yok'; header("Location: ".$selfUrl); exit; }

    try {
      $conn->begin_transaction();

      $stU = $conn->prepare("UPDATE {$FILES_TABLE} SET is_active=0, deleted_at=NOW() WHERE id=? AND main_agency_id=? AND tali_id=? LIMIT 1");
      if ($stU) {
        $stU->bind_param("iii", $currentFileId, $agencyId, $taliId);
        $stU->execute();
        $stU->close();
      }

      $stD = $conn->prepare("DELETE FROM {$CACHE_TABLE} WHERE file_id=? AND main_agency_id=? AND tali_agency_id=? AND period=?");
      if ($stD) {
        $stD->bind_param("iiis", $currentFileId, $agencyId, $taliId, $period);
        $stD->execute();
        $stD->close();
      }

      $conn->commit();

      // ✅ Kullanıcıya sadece sonuç
      $_SESSION['flash_ok'] = 'CSV silindi.';
      header("Location: ".$selfUrl);
      exit;

    } catch(Throwable $e) {
      $conn->rollback();
      error_log("delete_csv err: ".$e->getMessage());
      $_SESSION['flash_err'] = 'CSV silme hatası';
      header("Location: ".$selfUrl);
      exit;
    }
  }

  /* ✅ MATCH */
  if ($action === 'match') {
    if (!$hasCacheTable) { $_SESSION['flash_err'] = 'Cache tablosu yok: '.$CACHE_TABLE; header("Location: ".$selfUrl); exit; }
    if (!$hasCsvForPeriod || $currentFileId <= 0) { $_SESSION['flash_err'] = 'Önce CSV yüklemelisin.'; header("Location: ".$selfUrl); exit; }

    if ($workModeUp !== 'TICKET' && $workModeUp !== 'CSV') {
      $_SESSION['flash_err'] = 'Bu tali için work_mode geçersiz.';
      header("Location: ".$selfUrl);
      exit;
    }

    if ($workModeUp === 'TICKET' && !$hasTicketRows) {
      $_SESSION['flash_err'] = 'Ticket kaynağı bulunamadı';
      header("Location: ".$selfUrl);
      exit;
    }

    if ($workModeUp === 'CSV' && (!$hasTaliFilesTable || !$hasTaliRowsTable)) {
      $_SESSION['flash_err'] = 'CSV kaynağı bulunamadı';
      header("Location: ".$selfUrl);
      exit;
    }

    try {
      $srcByPolicy  = [];
      $srcByTcPlate = [];

      $sourceTypeLabel = ($workModeUp === 'TICKET') ? 'TICKET' : 'CSV';
      $taliActiveFileId = 0;

      if ($workModeUp === 'CSV') {
        $stF = $conn->prepare("
          SELECT id
          FROM {$TALI_FILES_TABLE}
          WHERE tali_agency_id=? AND period=? AND is_active=1
          ORDER BY uploaded_at DESC, id DESC
          LIMIT 1
        ");
        if ($stF) {
          $stF->bind_param("is", $taliId, $period);
          $stF->execute();
          $rF = $stF->get_result()->fetch_assoc();
          $stF->close();
          $taliActiveFileId = (int)($rF['id'] ?? 0);
        }
        if ($taliActiveFileId <= 0) {
          $_SESSION['flash_err'] = 'Tali tarafında bu dönem için aktif CSV yok.';
          header("Location: ".$selfUrl);
          exit;
        }
      }

      if ($workModeUp === 'TICKET') {
        $stT = $conn->prepare("
          SELECT id, policy_no_norm, policy_no_raw, tc_vn, plate
          FROM {$TICKET_ROWS_TABLE}
          WHERE main_agency_id=? AND assigned_agency_id=? AND period=?
        ");
        if (!$stT) { throw new Exception('Ticket sorgu prepare hata: '.$conn->error); }
        $stT->bind_param("iis", $agencyId, $taliId, $period);
        $stT->execute();
        $rs = $stT->get_result();
        while ($r = $rs->fetch_assoc()) {
          $sid   = (int)($r['id'] ?? 0);
          $p1    = norm_key((string)($r['policy_no_norm'] ?? ''));
          $p2    = norm_key((string)($r['policy_no_raw'] ?? ''));
          $tc    = norm_key((string)($r['tc_vn'] ?? ''));
          $plate = normalize_plate((string)($r['plate'] ?? ''));

          if ($p1 !== '') $srcByPolicy[$p1][] = $sid;
          if ($p2 !== '' && $p2 !== $p1) $srcByPolicy[$p2][] = $sid;
          if ($tc !== '' && $plate !== '') $srcByTcPlate[$tc.'|'.$plate][] = $sid;
        }
        $stT->close();
      } else {
        $stS = $conn->prepare("
          SELECT id, police_no, tc_vn, plaka
          FROM {$TALI_ROWS_TABLE}
          WHERE tali_agency_id=? AND period=? AND file_id=?
        ");
        if (!$stS) { throw new Exception('CSV kaynak sorgu prepare hata: '.$conn->error); }
        $stS->bind_param("isi", $taliId, $period, $taliActiveFileId);
        $stS->execute();
        $rs = $stS->get_result();
        while ($r = $rs->fetch_assoc()) {
          $sid   = (int)($r['id'] ?? 0);
          $p     = norm_key((string)($r['police_no'] ?? ''));
          $tc    = norm_key((string)($r['tc_vn'] ?? ''));
          $plate = normalize_plate((string)($r['plaka'] ?? ''));

          if ($p !== '') $srcByPolicy[$p][] = $sid;
          if ($tc !== '' && $plate !== '') $srcByTcPlate[$tc.'|'.$plate][] = $sid;
        }
        $stS->close();
      }

      $stR = $conn->prepare("
        UPDATE {$CACHE_TABLE}
        SET match_status='NOT_FOUND', match_reason='Eşleşmedi', matched_ticket_id=NULL
        WHERE file_id=? AND main_agency_id=? AND tali_agency_id=? AND period=?
      ");
      if ($stR) {
        $stR->bind_param("iiis", $currentFileId, $agencyId, $taliId, $period);
        $stR->execute();
        $stR->close();
      }

      $cache = [];
      $stC = $conn->prepare("
        SELECT id, police_no, tc_vn, plaka
        FROM {$CACHE_TABLE}
        WHERE file_id=? AND main_agency_id=? AND tali_agency_id=? AND period=?
        ORDER BY id ASC
        LIMIT 20000
      ");
      if ($stC) {
        $stC->bind_param("iiis", $currentFileId, $agencyId, $taliId, $period);
        $stC->execute();
        $rs2 = $stC->get_result();
        while ($r = $rs2->fetch_assoc()) $cache[] = $r;
        $stC->close();
      }

      $stU = $conn->prepare("
        UPDATE {$CACHE_TABLE}
        SET match_status=?, match_reason=?, matched_ticket_id=?
        WHERE id=? LIMIT 1
      ");
      if (!$stU) { throw new Exception('Cache update prepare hata: '.$conn->error); }

      $matched=0; $check=0; $notFound=0;

      foreach ($cache as $row) {
        $cid = (int)($row['id'] ?? 0);

        $policy = norm_key((string)($row['police_no'] ?? ''));
        $tc     = norm_key((string)($row['tc_vn'] ?? ''));
        $plate  = normalize_plate((string)($row['plaka'] ?? ''));

        $status = 'NOT_FOUND';
        $reason = 'Eşleşmedi';
        $mid    = 0;

        if ($policy !== '' && isset($srcByPolicy[$policy])) {
          $ids = $srcByPolicy[$policy];
          $mid = (int)$ids[0];
          $status = 'MATCHED';
          $reason = (count($ids) > 1) ? 'Poliçe no birden fazla kayıtta var (kontrol)' : '';
        } else {
          if ($tc !== '' && $plate !== '') {
            $k = $tc.'|'.$plate;
            if (isset($srcByTcPlate[$k])) {
              $ids = $srcByTcPlate[$k];
              $mid = (int)$ids[0];
              $status = 'CHECK';
              $reason = 'Poliçe no eşleşmedi; TC+Plaka eşleşti (kontrol gerekli)';
            } else {
              $status = 'NOT_FOUND';
              $reason = 'Eşleşmedi';
            }
          } else {
            $status = 'NOT_FOUND';
            $reason = 'Eşleşmedi';
          }
        }

        $stU->bind_param("ssii", $status, $reason, $mid, $cid);
        $stU->execute();

        if ($status === 'MATCHED') $matched++;
        elseif ($status === 'CHECK') $check++;
        else $notFound++;
      }

      $stU->close();

      $_SESSION['flash_ok'] = "Eşleştirme tamamlandı. Eşleşti: {$matched} / Kontrol: {$check} / Eşleşmedi: {$notFound}";
      header("Location: ".$selfUrl);
      exit;

    } catch(Throwable $e) {
      error_log("match err: ".$e->getMessage());
      $_SESSION['flash_err'] = 'Eşleştirme hatası';
      header("Location: ".$selfUrl);
      exit;
    }
  }
}

/* -------------------------
   SUB: sadece OPEN/TAMAMLANDI
------------------------- */
$subMainFinal = 0;
if ($hasSubTable) {
  try {
    $st = $conn->prepare("
      SELECT ".($hasMainFinalCol ? "main_final" : "0 AS main_final")."
      FROM {$SUB_TABLE}
      WHERE main_agency_id=? AND tali_agency_id=? AND period=? AND mode=?
      ORDER BY id DESC LIMIT 1
    ");
    if ($st) {
      $st->bind_param("iiss", $agencyId, $taliId, $period, $workModeUp);
      $st->execute();
      $r = $st->get_result()->fetch_assoc();
      $st->close();
      if ($r) $subMainFinal = (int)($r['main_final'] ?? 0);
    }
  } catch(Throwable $e){}
}

$subLabel = ($subMainFinal === 1) ? 'Tamamlandı • Ödemeler Açıldı' : 'Açık';
$subCls   = ($subMainFinal === 1) ? 's-pill s-ok' : 's-pill s-muted';

$finalizeVisible  = ($subMainFinal === 0);
$finalizeDisabled = ($subMainFinal === 1);

require_once __DIR__ . '/../layout/header.php';
?>
<style>
  .wrap{max-width:1400px;margin:16px auto 40px;padding:0 18px;}
  .card2{padding:16px;border:1px solid rgba(15,23,42,.10);border-radius:18px;background:#fff;}
  .head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;}
  .title{font-size:20px;font-weight:800;margin:0;}
  .sub{margin-top:6px;opacity:.8;font-size:13px;}
  .right{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;}
  .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid rgba(15,23,42,.10);background:#f8fafc;font-size:12px;}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid rgba(15,23,42,.12);background:#fff;font-weight:600;font-size:13px;text-decoration:none;color:inherit;cursor:pointer;}
  .btn:hover{background:#f8fafc;}
  .btn-blue{background: rgba(37,99,235,.10);border-color: rgba(37,99,235,.28);color:#1e3a8a;}
  .btn-blue:hover{ background: rgba(37,99,235,.14); }
  .btn-danger{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.28);color:#7f1d1d;}
  .btn-danger:hover{background:rgba(239,68,68,.14);}
  .btn-green{background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.25);color:#065f46;}
  .btn-green:hover{background:rgba(16,185,129,.14);}
  .btn:disabled{opacity:.55;cursor:not-allowed}
  .hr{margin:14px 0;border:none;border-top:1px solid rgba(15,23,42,.10);}

  .alert{padding:10px 12px;border-radius:14px;border:1px solid rgba(15,23,42,.12);background:#f8fafc;font-size:13px;}
  .alert.ok{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#065f46;}
  .alert.bad{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.25);color:#7f1d1d;}

  .dz{width:360px; max-width:62vw;border-radius:16px;border:1px dashed rgba(59,130,246,.45);
    background: linear-gradient(180deg, rgba(59,130,246,.08), rgba(15,23,42,.01));
    padding:12px;display:flex;align-items:center;justify-content:space-between;gap:10px;transition:.18s ease;cursor:pointer;}
  .dz:hover{ border-color: rgba(59,130,246,.70); transform: translateY(-1px); }
  .dz.drag{ border-color: rgba(16,185,129,.75); background: rgba(16,185,129,.08); }
  .dz .dz-left{display:flex;flex-direction:column;gap:2px;min-width:0}
  .dz .dz-title{font-weight:800;font-size:12px;color:#0f172a}
  .dz .dz-sub{font-size:12px;opacity:.8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px}
  .dz .dz-tag{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid rgba(15,23,42,.12);background:#fff;font-size:12px}

  table{width:100%;border-collapse:separate;border-spacing:0;margin-top:12px;background:#fff;}
  th,td{font-size:13px;padding:10px 10px;border-bottom:1px solid rgba(15,23,42,.08);vertical-align:top;white-space:nowrap;}
  th{position:sticky;top:0;background:#fff;z-index:1;text-align:left;border-bottom:1px solid rgba(15,23,42,.12);}
  .tablewrap{overflow:auto;border:1px solid rgba(15,23,42,.10);border-radius:16px;}
  .rightText{text-align:right;}
  th.center, td.center{ text-align:center !important; }

  .badge-tip{display:inline-flex;align-items:center;justify-content:center;padding:5px 10px;border-radius:999px;font-size:11px;font-weight:600;border:1px solid transparent;line-height:1;min-width:58px;}
  .badge-tip.SATIS{background:#ecfdf3;border-color:#a7f3d0;color:#065f46;}
  .badge-tip.ZEYIL{background:#fffbeb;border-color:#fde68a;color:#92400e;}
  .badge-tip.IPTAL{background:#fff1f2;border-color:#fecdd3;color:#9f1239;}
  .badge-tip.OTHER{background:#f8fafc;border-color:#e2e8f0;color:#0f172a;}

  .m-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;border:1px solid rgba(15,23,42,.12);font-size:12px;background:#fff}
  .m-ok{ border-color: rgba(16,185,129,.35); background: rgba(16,185,129,.10); }
  .m-bad{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); }
  .m-warn{ border-color: rgba(245,158,11,.45); background: rgba(245,158,11,.12); color:#92400e; }
  .cell-warn{ background: rgba(245,158,11,.10); }

  .modal-backdrop{position:fixed; inset:0;background: rgba(2,6,23,.45);display:none;align-items:center;justify-content:center;z-index:9999;}
  .modal{width:560px;max-width:92vw;background:#fff;border-radius:18px;border:1px solid rgba(15,23,42,.12);box-shadow:0 20px 50px rgba(2,6,23,.25);padding:16px;}
  .modal h3{margin:0;font-size:16px;font-weight:900}
  .modal p{margin:10px 0 0;opacity:.8;font-size:13px;line-height:1.45}
  .modal .m-actions{margin-top:14px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}

  .s-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid rgba(15,23,42,.12);font-size:12px;background:#fff;}
  .s-muted{background:#f8fafc;border-color:rgba(15,23,42,.10);opacity:.9}
  .s-ok{background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.30);color:#065f46;}

  .toast{display:none;margin-top:10px;padding:10px 12px;border-radius:14px;border:1px solid rgba(15,23,42,.12);background:#f8fafc;font-size:13px;}
  .toast.ok{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#065f46;}
  .toast.bad{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.25);color:#7f1d1d;}
</style>

<main class="page">
  <div class="wrap">
    <div class="card2">
      <div class="head">
        <div>
          <h1 class="title">Mutabakat Doğrulama Detay</h1>
          <div class="sub"><?= h($taliName) ?> — Dönem: <?= h($period) ?></div>
          <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <span class="pill">Tali ID: <?= (int)$taliId ?></span>
            <span class="pill">Main ID: <?= (int)$agencyId ?></span>
            <span class="pill">Mod: <?= h($workModeUp ?: '-') ?></span>
            <span class="pill">Aktif Dosya: <?= $hasCsvForPeriod ? h((string)($currentFile['original_name'] ?? 'Mevcut CSV')) : 'Yok' ?></span>
            <span class="<?= h($subCls) ?>" id="subStatusPill"><?= h($subLabel) ?></span>
          </div>
        </div>

        <div class="right">
          <a class="btn" href="<?= h($backUrl) ?>">← Geri</a>

          <?php if ($hasCsvForPeriod): ?>
            <a class="btn btn-blue" href="<?= h($downloadUrl) ?>">CSV İndir</a>

            <form method="post" action="<?= h($selfUrl) ?>" style="display:inline-flex;">
              <input type="hidden" name="action" value="match">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <button class="btn btn-green" type="submit">Eşleştir</button>
            </form>

            <button class="btn btn-danger" type="button" id="btnDeleteCsv">CSV Sil</button>
          <?php endif; ?>

          <button class="btn btn-blue" type="button" id="btnFinalize" <?= $finalizeDisabled ? 'disabled' : '' ?> style="<?= $finalizeVisible ? '' : 'display:none' ?>">
            Mutabakat Tamamla
          </button>

          <form id="uploadForm" method="post" enctype="multipart/form-data" action="<?= h($selfUrl) ?>" style="display:inline-flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="action" value="upload_csv">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input id="csvFile" type="file" name="csv_file" accept=".csv,text/csv" style="display:none">

            <div class="dz" id="dropzone" role="button" tabindex="0" aria-label="CSV yükle">
              <div class="dz-left">
                <div class="dz-title">CSV sürükle bırak veya tıkla</div>
                <div class="dz-sub" id="fileName"><?= $hasCsvForPeriod ? h((string)($currentFile['original_name'] ?? 'Mevcut CSV')) : 'Dosya seçilmedi' ?></div>
              </div>
              <div style="display:flex;gap:8px;align-items:center;flex-shrink:0">
                <span class="dz-tag">.csv</span>
                <span class="btn" style="pointer-events:none">Dosya Seç</span>
              </div>
            </div>

            <button class="btn btn-blue" type="submit" id="btnUpload">Yükle</button>
          </form>
        </div>
      </div>

      <div class="toast" id="toast"
        data-flash-ok="<?= h($flashOk) ?>"
        data-flash-err="<?= h($flashErr) ?>"
      ></div>

      <hr class="hr">

      <?php if ($flashErr): ?>
        <div class="alert bad" style="margin-top:10px;"><?= h($flashErr) ?></div>
      <?php elseif ($flashOk): ?>
        <div class="alert ok" style="margin-top:10px;"><?= h($flashOk) ?></div>
      <?php endif; ?>

      <?php if($subMainFinal===1): ?>
        <div class="alert ok" style="margin-top:10px;">Mutabakat tamamlandı. Ödemeler açıldı.</div>
      <?php endif; ?>

      <div class="tablewrap" style="margin-top:12px;">
        <table>
          <thead>
            <tr>
              <?php foreach ($TEMPLATE_HEADERS as $th): ?>
                <?php $thClass = (normalize_header($th) === normalize_header('Tip')) ? 'center' : ''; ?>
                <th class="<?= h($thClass) ?>"><?= h($th) ?></th>
              <?php endforeach; ?>
              <th>Eşleşme</th>
              <th>Neden</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($cacheRows)): ?>
              <tr><td colspan="<?= count($TEMPLATE_HEADERS)+2 ?>" style="padding:16px;opacity:.75">Henüz veri yok.</td></tr>
            <?php else: ?>
              <?php foreach ($cacheRows as $r): ?>
                <?php
                  $ms = strtoupper(trim((string)($r['match_status'] ?? '')));
                  $reason = trim((string)($r['match_reason'] ?? ''));

                  $label = 'Eşleşmedi';
                  $cls   = 'm-pill m-bad';
                  if ($ms === 'MATCHED') { $label = 'Eşleşti'; $cls = 'm-pill m-ok'; }
                  elseif ($ms === 'CHECK') { $label = 'Kontrol'; $cls = 'm-pill m-warn'; }
                  if ($label === 'Eşleşmedi' && $reason === '') $reason = 'Eşleşmedi';

                  $tipRaw = (string)($r['tip'] ?? '');
                  $tipKey = strtoupper(trim($tipRaw));
                  if (!in_array($tipKey, ['SATIS','ZEYIL','IPTAL'], true)) $tipKey = 'OTHER';

                  $warnPolicyCell = ($ms === 'CHECK') ? 'cell-warn' : '';
                ?>
                <tr>
                  <td><?= h((string)($r['tc_vn'] ?? '')) ?></td>
                  <td><?= h((string)($r['sigortali'] ?? '')) ?></td>
                  <td><?= h((string)($r['plaka'] ?? '')) ?></td>
                  <td><?= h((string)($r['sirket'] ?? '')) ?></td>
                  <td><?= h((string)($r['brans'] ?? '')) ?></td>
                  <td class="center"><span class="badge-tip <?= h($tipKey) ?>"><?= h($tipKey==='OTHER' ? ($tipRaw?:'—') : $tipKey) ?></span></td>
                  <td><?= h((string)($r['tanzim'] ?? '')) ?></td>
                  <td class="<?= h($warnPolicyCell) ?>"><?= h((string)($r['police_no'] ?? '')) ?></td>
                  <td class="rightText"><?= h((string)($r['net_komisyon'] ?? '')) ?></td>
                  <td><span class="<?= h($cls) ?>"><?= h($label) ?></span></td>
                  <td><?= h($reason) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:10px;opacity:.75;font-size:12px;">
        Not: Eşleştirme butonuna basınca eşleşmeyenler her zaman <strong>Eşleşmedi</strong> olarak işaretlenir (NULL bırakılmaz).
      </div>
    </div>
  </div>

  <!-- DELETE MODAL -->
  <div class="modal-backdrop" id="modalBackdrop">
    <div class="modal" role="dialog" aria-modal="true">
      <h3>CSV silinsin mi?</h3>
      <p>Bu işlem geri alınamaz. Bu tali için <strong><?= h($period) ?></strong> dönemindeki CSV kaldırılacak.</p>
      <div class="m-actions">
        <button class="btn" type="button" id="btnModalCancel">Vazgeç</button>
        <form method="post" action="<?= h($selfUrl) ?>" style="display:inline">
          <input type="hidden" name="action" value="delete_csv">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
          <button class="btn btn-danger" type="submit">Evet, Sil</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ✅ GENERIC CONFIRM / ALERT MODAL (tarayıcı confirm/alert yerine) -->
  <div class="modal-backdrop" id="uiBackdrop">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="uiTitle">
      <h3 id="uiTitle">Onay</h3>
      <p id="uiText">Devam edilsin mi?</p>
      <div class="m-actions" id="uiActions">
        <button class="btn" type="button" id="uiCancel">Vazgeç</button>
        <button class="btn btn-blue" type="button" id="uiOk">Tamam</button>
      </div>
    </div>
  </div>
</main>

<script>
(function(){
  const dz = document.getElementById('dropzone');
  const fi = document.getElementById('csvFile');
  const fn = document.getElementById('fileName');
  const form = document.getElementById('uploadForm');
  const HAS_EXISTING = <?= $hasCsvForPeriod ? 'true' : 'false' ?>;

  /* Toast */
  const toast = document.getElementById('toast');
  function showToast(msg, ok){
    if(!toast || !msg) return;
    toast.className = 'toast ' + (ok ? 'ok' : 'bad');
    toast.textContent = msg || '';
    toast.style.display = 'block';
    setTimeout(()=>{ toast.style.display='none'; }, 3500);
  }

  // Sayfa açılır açılmaz flash'leri toast olarak bas
  if(toast){
    const fOk  = toast.getAttribute('data-flash-ok') || '';
    const fErr = toast.getAttribute('data-flash-err') || '';
    if(fErr) showToast(fErr, false);
    else if(fOk) showToast(fOk, true);
  }

  /* ✅ UI Modal (alert/confirm yerine) */
  const uiBack = document.getElementById('uiBackdrop');
  const uiTitle = document.getElementById('uiTitle');
  const uiText = document.getElementById('uiText');
  const uiCancel = document.getElementById('uiCancel');
  const uiOk = document.getElementById('uiOk');

  function uiOpen(){ if(uiBack) uiBack.style.display='flex'; }
  function uiClose(){ if(uiBack) uiBack.style.display='none'; }

  function uiConfirm(title, text, okText='Devam Et', cancelText='Vazgeç'){
    return new Promise((resolve)=>{
      if(!uiBack) return resolve(false);
      uiTitle.textContent = title || 'Onay';
      uiText.textContent  = text || '';
      uiOk.textContent = okText;
      uiCancel.textContent = cancelText;
      uiCancel.style.display = 'inline-flex';

      const cleanup = ()=>{
        uiOk.removeEventListener('click', onOk);
        uiCancel.removeEventListener('click', onCancel);
        uiBack.removeEventListener('click', onBack);
        document.removeEventListener('keydown', onEsc);
      };
      const onOk = ()=>{ cleanup(); uiClose(); resolve(true); };
      const onCancel = ()=>{ cleanup(); uiClose(); resolve(false); };
      const onBack = (e)=>{ if(e.target===uiBack) onCancel(); };
      const onEsc = (e)=>{ if(e.key==='Escape') onCancel(); };

      uiOk.addEventListener('click', onOk);
      uiCancel.addEventListener('click', onCancel);
      uiBack.addEventListener('click', onBack);
      document.addEventListener('keydown', onEsc);

      uiOpen();
    });
  }

  function uiAlert(title, text, okText='Tamam'){
    return new Promise((resolve)=>{
      if(!uiBack) return resolve(true);
      uiTitle.textContent = title || 'Bilgi';
      uiText.textContent  = text || '';
      uiOk.textContent = okText;
      uiCancel.style.display = 'none';

      const cleanup = ()=>{
        uiOk.removeEventListener('click', onOk);
        uiBack.removeEventListener('click', onBack);
        document.removeEventListener('keydown', onEsc);
      };
      const onOk = ()=>{ cleanup(); uiClose(); resolve(true); };
      const onBack = (e)=>{ if(e.target===uiBack) onOk(); };
      const onEsc = (e)=>{ if(e.key==='Escape') onOk(); };

      uiOk.addEventListener('click', onOk);
      uiBack.addEventListener('click', onBack);
      document.addEventListener('keydown', onEsc);

      uiOpen();
    });
  }

  /* Dropzone */
  function setName(file){ if(fn) fn.textContent = file ? file.name : 'Dosya seçilmedi'; }

  if(dz && fi){
    dz.addEventListener('click', ()=>fi.click());
    dz.addEventListener('keydown', (e)=>{ if(e.key==='Enter' || e.key===' ') fi.click(); });
    fi.addEventListener('change', ()=>{ setName(fi.files && fi.files[0] ? fi.files[0] : null); });

    ['dragenter','dragover'].forEach(ev=>{
      dz.addEventListener(ev, (e)=>{ e.preventDefault(); e.stopPropagation(); dz.classList.add('drag'); });
    });
    ['dragleave','drop'].forEach(ev=>{
      dz.addEventListener(ev, (e)=>{ e.preventDefault(); e.stopPropagation(); dz.classList.remove('drag'); });
    });
    dz.addEventListener('drop', (e)=>{
      const files = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files : null;
      if(!files || !files.length) return;
      fi.files = files;
      setName(files[0]);
    });
  }

  /* ✅ Upload: alert/confirm yok -> UI modal */
  if(form && fi){
    form.addEventListener('submit', (e)=>{
      e.preventDefault();

      (async ()=>{
        if(!fi.files || !fi.files.length){
          await uiAlert('Dosya seçilmedi', 'Lütfen bir CSV seç.');
          return;
        }
        if(HAS_EXISTING){
          const ok = await uiConfirm(
            'Yeni CSV yüklensin mi?',
            'Bu dönem için zaten bir CSV var. Yeni CSV yüklersen eskisi pasife düşer.',
            'Devam Et',
            'Vazgeç'
          );
          if(!ok) return;
        }
        form.submit();
      })();
    });
  }

  /* Delete modal (zaten custom) */
  const btnDel = document.getElementById('btnDeleteCsv');
  const back = document.getElementById('modalBackdrop');
  const btnCancel = document.getElementById('btnModalCancel');
  function openModal(){ if(back) back.style.display='flex'; }
  function closeModal(){ if(back) back.style.display='none'; }
  if(btnDel && back){
    btnDel.addEventListener('click', openModal);
    back.addEventListener('click', (e)=>{ if(e.target === back) closeModal(); });
  }
  if(btnCancel) btnCancel.addEventListener('click', closeModal);
  document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape') closeModal(); });

  /* Status pill */
  const pill = document.getElementById('subStatusPill');
  function setStatusPill(label, cls){
    if(!pill) return;
    pill.className = cls;
    pill.textContent = label;
  }

  /* ✅ Mutabakat Tamamla: confirm yok -> UI modal */
  const btnFinalize = document.getElementById('btnFinalize');
  if(btnFinalize){
    btnFinalize.addEventListener('click', async ()=>{
      if(btnFinalize.disabled) return;

      const ok = await uiConfirm(
        'Mutabakat tamamla',
        'Mutabakat tamamlanınca bu dönem için ödemeler ekranı açılır. Devam edilsin mi?',
        'Evet, Tamamla',
        'Vazgeç'
      );
      if(!ok) return;

      btnFinalize.disabled = true;
      const oldText = btnFinalize.textContent;
      btnFinalize.textContent = 'Tamamlanıyor...';

      try{
        const fd = new FormData();
        fd.append('csrf_token', '<?= h($csrfToken) ?>');
        fd.append('tali_id', '<?= (int)$taliId ?>');
        fd.append('period', '<?= h($period) ?>');

        const res = await fetch('<?= h(base_url("mutabakat/ajax_mutabakat_finalize.php")) ?>', {
          method: 'POST',
          credentials: 'same-origin',
          body: fd
        });

        const js = await res.json().catch(()=>null);
        if(!res.ok || !js || js.ok !== true){
          showToast((js && js.message) ? js.message : 'Tamamlama hatası', false);
          btnFinalize.disabled = false;
          btnFinalize.textContent = oldText;
          return;
        }

        setStatusPill('Tamamlandı • Ödemeler Açıldı', 's-pill s-ok');
        showToast(js.message || 'Mutabakat tamamlandı.', true);

        btnFinalize.style.display = 'none';

      } catch(e){
        showToast('İstek atılamadı', false);
        btnFinalize.disabled = false;
        btnFinalize.textContent = oldText;
      }
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
