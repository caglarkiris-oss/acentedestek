<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_ajax_mutabakat_finalize_error.log');
error_reporting(E_ALL);

require_once __DIR__.'/../../auth.php';
require_once __DIR__.'/../../helpers.php';
require_once __DIR__.'/../../db.php';

$csrfEnforce = (bool) config('security.csrf_enforce', true);
if ($csrfEnforce && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
}

if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Login gerekli']); exit; }
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$isMain = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
if (!$isMain) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Yetkisiz']); exit; }

$conn = db();
if (!$conn) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>'DB yok']); exit; }
$conn->set_charset('utf8mb4');

function table_exists(mysqli $conn, string $table): bool {
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
}
function column_exists(mysqli $conn, string $table, string $col): bool {
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
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'message'=>'POST gerekli']);
  exit;
}


$taliId = (int)($_POST['tali_id'] ?? 0);
$period = trim((string)($_POST['period'] ?? ''));
if ($taliId <= 0 || !preg_match('/^\d{4}\-\d{2}$/', $period)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>'Geçersiz parametre']);
  exit;
}

/* Tali -> parent kontrol + mode */
$workMode = 'TICKET';
$mainOfTali = 0;
$stA = $conn->prepare("SELECT parent_id, work_mode FROM agencies WHERE id=? LIMIT 1");
if ($stA) {
  $stA->bind_param("i", $taliId);
  $stA->execute();
  $r = $stA->get_result()->fetch_assoc();
  $stA->close();
  if (!$r) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Tali bulunamadı']); exit; }
  $mainOfTali = (int)($r['parent_id'] ?? 0);
  $wm = strtoupper(trim((string)($r['work_mode'] ?? 'TICKET')));
  if (in_array($wm, ['TICKET','CSV'], true)) $workMode = $wm;
}
if ($mainOfTali !== $agencyId) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Bu tali size bağlı değil']); exit; }

$SUB_TABLE   = 'mutabakat_submissions';
$PAY_TABLE   = 'mutabakat_payments';
$CACHE_TABLE = 'mutabakat_csv_rows_cache';

if (!table_exists($conn, $SUB_TABLE)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'mutabakat_submissions tablosu yok']);
  exit;
}

$hasMainFinal = column_exists($conn, $SUB_TABLE, 'main_final');

/* 1) submission upsert (status truncate fix: status’a sadece SENT basıyoruz) */
try {
  $conn->begin_transaction();

  $subId = 0;
  $stFind = $conn->prepare("SELECT id FROM {$SUB_TABLE} WHERE main_agency_id=? AND tali_agency_id=? AND period=? AND mode=? ORDER BY id DESC LIMIT 1");
  if ($stFind) {
    $stFind->bind_param("iiss", $agencyId, $taliId, $period, $workMode);
    $stFind->execute();
    $r = $stFind->get_result()->fetch_assoc();
    $stFind->close();
    $subId = (int)($r['id'] ?? 0);
  }

  if ($subId > 0) {
    if ($hasMainFinal) {
      $stUp = $conn->prepare("UPDATE {$SUB_TABLE} SET main_final=1 WHERE id=? AND main_agency_id=? LIMIT 1");
      if (!$stUp) throw new Exception('submission update prepare: '.$conn->error);
      $stUp->bind_param("ii", $subId, $agencyId);
      if (!$stUp->execute()) throw new Exception('submission update exec: '.$stUp->error);
      $stUp->close();
    }
  } else {
    // status alanı ENUM ise OPEN/FINALIZED basınca truncate yiyordu -> burada SENT basıyoruz.
    if ($hasMainFinal) {
      $stIns = $conn->prepare("INSERT INTO {$SUB_TABLE} (period, mode, status, main_agency_id, tali_agency_id, main_final, created_at) VALUES (?, ?, 'SENT', ?, ?, 1, NOW())");
      if (!$stIns) throw new Exception('submission insert prepare: '.$conn->error);
      $stIns->bind_param("ssii", $period, $workMode, $agencyId, $taliId);
      if (!$stIns->execute()) throw new Exception('submission insert exec: '.$stIns->error);
      $subId = (int)$stIns->insert_id;
      $stIns->close();
    } else {
      $stIns = $conn->prepare("INSERT INTO {$SUB_TABLE} (period, mode, status, main_agency_id, tali_agency_id, created_at) VALUES (?, ?, 'SENT', ?, ?, NOW())");
      if (!$stIns) throw new Exception('submission insert prepare: '.$conn->error);
      $stIns->bind_param("ssii", $period, $workMode, $agencyId, $taliId);
      if (!$stIns->execute()) throw new Exception('submission insert exec: '.$stIns->error);
      $subId = (int)$stIns->insert_id;
      $stIns->close();
    }
  }

  /* 2) payment kaydı (tablo varsa) */
  if (table_exists($conn, $PAY_TABLE)) {
    $hasSubmissionId = column_exists($conn, $PAY_TABLE, 'submission_id');
    $hasNetTotal     = column_exists($conn, $PAY_TABLE, 'net_total');
    $hasPeriodCol    = column_exists($conn, $PAY_TABLE, 'period');
    $hasStatusCol    = column_exists($conn, $PAY_TABLE, 'status');
    $hasCreatedAt    = column_exists($conn, $PAY_TABLE, 'created_at');

    // ödeme zaten varsa tekrar basma
    $exists = false;
    if ($hasSubmissionId) {
      $stE = $conn->prepare("SELECT id FROM {$PAY_TABLE} WHERE submission_id=? LIMIT 1");
      if ($stE) {
        $stE->bind_param("i", $subId);
        $stE->execute();
        $exists = (bool)$stE->get_result()->fetch_assoc();
        $stE->close();
      }
    } else {
      $stE = $conn->prepare("SELECT id FROM {$PAY_TABLE} WHERE main_agency_id=? AND tali_agency_id=? AND period=? LIMIT 1");
      if ($stE) {
        $stE->bind_param("iis", $agencyId, $taliId, $period);
        $stE->execute();
        $exists = (bool)$stE->get_result()->fetch_assoc();
        $stE->close();
      }
    }

    if (!$exists && table_exists($conn, $CACHE_TABLE) && $hasNetTotal) {
      // net_total hesapla (MATCHED + CHECK)
      $netTotal = 0.0;
      $stSum = $conn->prepare("
        SELECT COALESCE(SUM(
          CASE
            WHEN match_status IN ('MATCHED','CHECK') THEN
              CAST(REPLACE(REPLACE(net_komisyon,' ','') ,',','.') AS DECIMAL(18,2))
            ELSE 0
          END
        ),0) AS s
        FROM {$CACHE_TABLE}
        WHERE main_agency_id=? AND tali_agency_id=? AND period=?
      ");
      if ($stSum) {
        $stSum->bind_param("iis", $agencyId, $taliId, $period);
        $stSum->execute();
        $rS = $stSum->get_result()->fetch_assoc();
        $netTotal = (float)($rS['s'] ?? 0);
        $stSum->close();
      }

      // insert - kolonlara göre uyumlu
      $cols = [];
      $vals = [];
      $types = "";
      $bind = [];

      if ($hasSubmissionId) { $cols[]='submission_id'; $vals[]='?'; $types.='i'; $bind[]=$subId; }
      $cols[]='main_agency_id'; $vals[]='?'; $types.='i'; $bind[]=$agencyId;
      $cols[]='tali_agency_id'; $vals[]='?'; $types.='i'; $bind[]=$taliId;

      if ($hasPeriodCol) { $cols[]='period'; $vals[]='?'; $types.='s'; $bind[]=$period; }
      if ($hasNetTotal)  { $cols[]='net_total'; $vals[]='?'; $types.='d'; $bind[]=$netTotal; }
      if ($hasStatusCol) { $cols[]='status'; $vals[]="'PENDING'"; }
      if ($hasCreatedAt) { $cols[]='created_at'; $vals[]='NOW()'; }

      $sqlIns = "INSERT INTO {$PAY_TABLE} (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
      $stP = $conn->prepare($sqlIns);
      if (!$stP) throw new Exception('payment insert prepare: '.$conn->error.' SQL='.$sqlIns);

      // bind only ? params
      if ($types !== '') {
        $refs = [];
        $refs[] = & $types;
        for ($i=0;$i<count($bind);$i++) { $refs[] = & $bind[$i]; }
        call_user_func_array([$stP,'bind_param'], $refs);
      }

      if (!$stP->execute()) throw new Exception('payment insert exec: '.$stP->error);
      $stP->close();
    }
  }

  $conn->commit();
  echo json_encode(['ok'=>true,'message'=>'Mutabakat tamamlandı. Ödeme kaydı oluşturuldu (varsa).']);
  exit;

} catch(Throwable $e) {
  $conn->rollback();
  error_log("finalize err: ".$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'Tamamlama hatası: '.$e->getMessage()]);
  exit;
}
