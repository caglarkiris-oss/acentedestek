<?php
// /public_html/platform/mutabakat/ajax_ticket_row_update.php ✅ FIXED (normalize + no-op skip + correct log bind types)
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('log_errors','1');
error_reporting(E_ALL);

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../db.php';

header('Content-Type: application/json; charset=utf-8');

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'message'=>'Giriş gerekli'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

$conn = db();
if (!$conn) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'DB yok'], JSON_UNESCAPED_UNICODE);
  exit;
}

$role = (string)($_SESSION['role'] ?? '');
$isMain = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
if (!$isMain) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'message'=>'Yetkisiz'], JSON_UNESCAPED_UNICODE);
  exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>'Geçersiz istek'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* CSRF */
if (
  empty($_SESSION['csrf_token']) ||
  !hash_equals((string)$_SESSION['csrf_token'], (string)($body['csrf_token'] ?? ''))
) {
  http_response_code(419);
  echo json_encode(['ok'=>false,'message'=>'CSRF hatası'], JSON_UNESCAPED_UNICODE);
  exit;
}

$taliId  = (int)($body['tali_id'] ?? 0);
$changes = $body['changes'] ?? [];

/* ✅ edit session id (tek kaydet = tek batch) */
$editSessionId = (string)($body['edit_session_id'] ?? '');
if (!preg_match('/^[a-f0-9]{8}\-[a-f0-9]{4}\-[a-f0-9]{4}\-[a-f0-9]{4}\-[a-f0-9]{12}$/i', $editSessionId)) {
  $editSessionId = null; // db kolonu yoksa da sorun çıkarmasın
}

if ($taliId <= 0 || !is_array($changes) || !$changes) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>'Eksik parametre'], JSON_UNESCAPED_UNICODE);
  exit;
}

/*
  ✅ editable alanlar
  ❌ matched_ticket_id YOK
  ❌ match_status YOK
*/
$allowed = [
  'tc_vn'         => 's',
  'insured_name'  => 's',
  'plate'         => 's',
  'company_name'  => 's',
  'branch'        => 's',
  'txn_type'      => 's',
  'issue_date'    => 's',
  'policy_no_raw' => 's',
  'gross_premium' => 'd',
];

$allowedKeys = array_keys($allowed);

/* meta */
$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = (string)($_SESSION['user_name'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

/* helper: gross normalize (TR -> float) */
$normalizeGross = function($v): float {
  $v = (string)$v;
  // 17.000,00 -> 17000,00
  $v = str_replace(['.',' '], '', $v);
  // 17000,00 -> 17000.00
  $v = str_replace(',', '.', $v);
  return (float)$v;
};

$conn->begin_transaction();

try {

  $changedFieldsTotal = 0;

  foreach ($changes as $ch) {

    $rowId  = (int)($ch['id'] ?? 0);
    $fields = $ch['fields'] ?? [];

    if ($rowId <= 0 || !is_array($fields) || !$fields) continue;

    /* row bu tali’ye mi ait */
    $chk = $conn->prepare("
      SELECT id
      FROM mutabakat_rows
      WHERE id=? AND assigned_agency_id=? AND source='TICKET'
      LIMIT 1
    ");
    $chk->bind_param("ii", $rowId, $taliId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
      $chk->close();
      throw new Exception('Satır bulunamadı / yetkisiz');
    }
    $chk->close();

    /* ✅ Bu satırın mevcut değerlerini tek seferde çek */
    $selectCols = implode(',', array_map(fn($c)=>"`{$c}`", $allowedKeys));
    $oldRow = [];
    $oldQ = $conn->prepare("SELECT {$selectCols} FROM mutabakat_rows WHERE id=? LIMIT 1");
    $oldQ->bind_param("i", $rowId);
    $oldQ->execute();
    $resOld = $oldQ->get_result();
    $oldRow = $resOld ? ($resOld->fetch_assoc() ?: []) : [];
    $oldQ->close();

    $setParts = [];
    $types = '';
    $vals  = [];

    foreach ($fields as $k => $v) {
      if (!isset($allowed[$k])) continue;

      $oldVal = $oldRow[$k] ?? '';

      /* normalize + compare */
      if ($k === 'gross_premium') {
        $newNum = $normalizeGross($v);
        $oldNum = (float)$oldVal;

        // ✅ 2 hane stabil kıyas
        $newNum = round($newNum, 2);
        $oldNum = round($oldNum, 2);

        if ($oldNum === $newNum) {
          continue; // ✅ değişmediyse: ne update ne log
        }

        // DB'ye float/decimal gidecek değer
        $v = $newNum;

      } else {
        $v = trim((string)$v);
        if (trim((string)$oldVal) === $v) continue;
      }

      /* validasyon */
      if ($k === 'issue_date' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) {
        throw new Exception('Tanzim tarihi hatalı (YYYY-MM-DD)');
      }

      if ($k === 'txn_type') {
        $v = strtoupper((string)$v);
        if (!in_array($v, ['SATIS','ZEYIL','IPTAL'], true)) {
          throw new Exception('Tip geçersiz');
        }
      }

      /* ✅ LOG (sadece değiştiyse) */
      $logged = false;

      // log stringleri (gross için 2 hane standard)
      if ($k === 'gross_premium') {
        $oldStr = number_format(round((float)$oldVal, 2), 2, '.', '');
        $newStr = number_format((float)$v, 2, '.', '');
      } else {
        $oldStr = (string)$oldVal;
        $newStr = (string)$v;
      }

      // 1) Geniş insert (kolonlar varsa)
      if ($editSessionId !== null) {
        $log = $conn->prepare("
          INSERT INTO mutabakat_edit_logs
          (row_id, user_id, user_name, agency_id, edit_session_id, source, field_name, old_value, new_value, ip, user_agent, edited_at)
          VALUES (?,?,?,?,?,'TICKET',?,?,?,?,?,NOW())
        ");
        if ($log) {
          // ✅ doğru tip dizilimi: i i s i s s s s s s  (10 param)
          $log->bind_param(
            "iisissssss",
            $rowId,
            $userId,
            $userName,
            $agencyId,
            $editSessionId,
            $k,
            $oldStr,
            $newStr,
            $ip,
            $ua
          );
          $log->execute();
          $log->close();
          $logged = true;
        }
      }

      // 2) Basit insert (mevcut kolonlarınla uyumlu)
      if (!$logged) {
        $log2 = $conn->prepare("
          INSERT INTO mutabakat_edit_logs
          (row_id, user_id, user_name, agency_id, field_name, old_value, new_value)
          VALUES (?,?,?,?,?,?,?)
        ");
        if (!$log2) throw new Exception('LOG prepare hatası');

        $log2->bind_param(
          "iiissss",
          $rowId,
          $userId,
          $userName,
          $agencyId,
          $k,
          $oldStr,
          $newStr
        );
        $log2->execute();
        $log2->close();
      }

      /* update set */
      $setParts[] = "`{$k}`=?";
      $types .= $allowed[$k];
      $vals[] = $v;
      $changedFieldsTotal++;
    }

    if (!$setParts) continue;

    $sql = "UPDATE mutabakat_rows SET ".implode(', ', $setParts)." WHERE id=? LIMIT 1";
    $types .= 'i';
    $vals[] = $rowId;

    $st = $conn->prepare($sql);
    if (!$st) throw new Exception('UPDATE prepare hatası');

    $bind = [$types];
    foreach ($vals as $i=>$val) $bind[] = &$vals[$i];
    call_user_func_array([$st,'bind_param'],$bind);
    $st->execute();
    $st->close();
  }

  $conn->commit();
  echo json_encode(['ok'=>true, 'changed_fields'=>$changedFieldsTotal], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
  $conn->rollback();
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
