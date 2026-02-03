<?php
// /public_html/platform/mutabakat/havuz.php
// ✅ Mutabakat V2 - Havuz ekranı (sekme + upload + eşleştirme)

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_mutabakat_havuz_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) { header('Location: '.base_url('login.php')); exit; }
}

$conn = db();
if(!$conn){ http_response_code(500); exit('DB yok'); }
$conn->set_charset((string)config('db.charset','utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$agencyId = (int)($_SESSION['agency_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$isMain   = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
$isTali   = in_array($role, ['TALI_ACENTE_YETKILISI','PERSONEL'], true) && !$isMain;

if (!$agencyId) { http_response_code(403); exit('Agency yok'); }

/* =========================================================
   Yetki / Agency context
========================================================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function col_exists(mysqli $conn, string $table, string $col): bool {
  $dbName = $conn->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
  if ($dbName === '') return false;
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st = $conn->prepare($sql);
  if(!$st) return false;
  $st->bind_param('sss', $dbName, $table, $col);
  $st->execute();
  $st->store_result();
  $ok = $st->num_rows > 0;
  $st->close();
  return $ok;
}

function get_main_agency_id(mysqli $conn, int $taliAgencyId): int {
  // agencies veya agency_relations üzerinde parent/main alanı farklı isimlerle gelebilir
  // 1) agency_relations
  if (col_exists($conn, 'agency_relations', 'child_agency_id') && col_exists($conn, 'agency_relations', 'parent_agency_id')) {
    $st = $conn->prepare("SELECT parent_agency_id FROM agency_relations WHERE child_agency_id=? LIMIT 1");
    if ($st) {
      $st->bind_param('i', $taliAgencyId);
      $st->execute();
      $res = $st->get_result();
      if ($res && ($row = $res->fetch_assoc())) {
        $pid = (int)($row['parent_agency_id'] ?? 0);
        $st->close();
        if ($pid > 0) return $pid;
      }
      $st->close();
    }
  }

  // 2) agencies tablosu aday kolonlar
  $candidates = ['parent_id','main_agency_id','ana_acente_id','main_id'];
  foreach ($candidates as $col) {
    if (!col_exists($conn, 'agencies', $col)) continue;
    $sql = "SELECT $col AS pid FROM agencies WHERE id=? LIMIT 1";
    $st = $conn->prepare($sql);
    if(!$st) continue;
    $st->bind_param('i', $taliAgencyId);
    $st->execute();
    $res = $st->get_result();
    if($res && ($r = $res->fetch_assoc())) {
      $pid = (int)($r['pid'] ?? 0);
      $st->close();
      if ($pid > 0) return $pid;
    }
    $st->close();
  }
  return $taliAgencyId;
}

$isMain   = in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true);
$isTali   = in_array($role, ['TALI_ACENTE_YETKILISI','PERSONEL'], true) && !$isMain;

if (!$agencyId) { http_response_code(403); exit('Agency yok'); }

// main/tali context
$mainAgencyId = $isMain ? $agencyId : get_main_agency_id($conn, $agencyId);
$taliAgencyId = $isTali ? $agencyId : 0;

// tali workmode (ticket/csv)
$taliWorkMode = 'ticket';
if ($isTali && col_exists($conn, 'agencies', 'work_mode')) {
  $st = $conn->prepare("SELECT work_mode FROM agencies WHERE id=? LIMIT 1");
  if ($st) {
    $st->bind_param('i', $agencyId);
    $st->execute();
    $res = $st->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
      $v = strtolower(trim((string)($row['work_mode'] ?? 'ticket')));
      if (in_array($v, ['ticket','csv'], true)) $taliWorkMode = $v;
    }
    $st->close();
  }
}

/* =========================================================
   Tabs & yetki
========================================================= */

$tabsAll = [
  'havuz'      => ['label'=>'Havuz',       'main'=>true, 'tali'=>true],
  'eslesen'    => ['label'=>'Eşleşen',     'main'=>true, 'tali'=>false],
  'eslesmeyen' => ['label'=>'Eşleşmeyen',  'main'=>true, 'tali'=>false],
  'itiraz'     => ['label'=>'İtiraz',      'main'=>true, 'tali'=>true],
  'ana_csv'    => ['label'=>'Ana acente csv','main'=>true,'tali'=>false],
];

$requestedTab = strtolower(trim((string)($_GET['tab'] ?? 'havuz')));
$allowedTab = 'havuz';
if (isset($tabsAll[$requestedTab])) {
  $allowedTab = ($isMain && $tabsAll[$requestedTab]['main']) || ($isTali && $tabsAll[$requestedTab]['tali'])
    ? $requestedTab
    : 'havuz';
}

/* =========================================================
   Dönem seçimi
========================================================= */
$periodId = (int)($_GET['period_id'] ?? 0);
$periods  = [];

// 1) Tüm dönemleri çek
$st = $conn->prepare("SELECT id, year, month, status FROM mutabakat_v2_periods WHERE ana_acente_id=? ORDER BY year DESC, month DESC");
if ($st) {
  $st->bind_param('i', $mainAgencyId);
  $st->execute();
  $res = $st->get_result();
  while ($res && ($row = $res->fetch_assoc())) $periods[] = $row;
  $st->close();
}

// 2) period_id yoksa: içinde bulunduğumuz ayı otomatik seç (yoksa oluştur)
if (!$periodId) {
  $cy = (int)date('Y');
  $cm = (int)date('n');
  $found = 0;
  foreach ($periods as $p) {
    if ((int)$p['year'] === $cy && (int)$p['month'] === $cm) { $found = (int)$p['id']; break; }
  }
  if ($found) {
    $periodId = $found;
  } else {
    $ins = $conn->prepare("INSERT INTO mutabakat_v2_periods (ana_acente_id, year, month, status, created_at) VALUES (?, ?, ?, 'OPEN', NOW())");
    if ($ins) {
      $ins->bind_param('iii', $mainAgencyId, $cy, $cm);
      $ok = $ins->execute();
      $ins->close();
      if ($ok) {
        $periodId = (int)$conn->insert_id;
        // listeyi güncelle
        array_unshift($periods, ['id'=>$periodId, 'year'=>$cy, 'month'=>$cm, 'status'=>'OPEN']);
      } elseif (!empty($periods)) {
        // insert başarısızsa fallback: en güncel kayıt
        $periodId = (int)$periods[0]['id'];
      }
    } elseif (!empty($periods)) {
      $periodId = (int)$periods[0]['id'];
    }
  }
}

$flashErr = '';
$flashOk  = '';

/* =========================================================
   CSV helpers
========================================================= */
function _guess_delim(string $line): string {
  $candidates = [";","\t",",","|"];
  $best = ";";
  $bestScore = -1;
  foreach ($candidates as $d) {
    $score = substr_count($line, $d);
    if ($score > $bestScore) { $bestScore = $score; $best = $d; }
  }
  return $best;
}

// If CSV is parsed with wrong delimiter and returns a single cell containing separators,
// split it safely (also trims UTF-8 BOM/whitespace).
function _csv_fix_row(array $row, string $delim): array {
  if (count($row) === 1) {
    $cell = (string)($row[0] ?? '');
    // if the single cell contains an alternate delimiter, split by the most likely one
    $cands = [';','\t',',','|'];
    // prefer current delimiter first
    $cands = array_values(array_unique(array_merge([$delim], $cands)));
    foreach ($cands as $d) {
      if ($d !== '' && substr_count($cell, $d) >= 1) {
        $parts = explode($d, $cell);
        if (count($parts) > 1) return $parts;
      }
    }
  }
  return $row;
}


function _ensure_utf8(string $s): string {
  // If string is not valid UTF-8, try common Turkish encodings.
  if (!preg_match('//u', $s)) {
    if (function_exists('mb_convert_encoding')) {
      $s = @mb_convert_encoding($s, 'UTF-8', 'UTF-8, Windows-1254, ISO-8859-9, ISO-8859-1');
    } elseif (function_exists('iconv')) {
      $s = @iconv('Windows-1254', 'UTF-8//IGNORE', $s);
    }
  }
  return $s;
}

function _norm_header(string $h): string {
  $h = _ensure_utf8($h);
  $h = trim($h);
  // remove UTF-8 BOM if present
  $h = str_replace(["\xEF\xBB\xBF"], '', $h);
  // normalize whitespace + common suffixes
  $h = mb_strtolower($h, 'UTF-8');
  $h = str_replace(["\t", "\r", "\n", "\xC2\xA0"], ' ', $h); // includes NBSP
  $h = preg_replace('/\s+/u', ' ', $h);
  $h = trim($h, " \"'\t\r\n");
  // drop currency / unit hints often seen in exports
  $h = str_replace(['(tl)','(try)','(₺)','₺','(tl )','( try )'], '', $h);
  $h = trim($h);

  // basic Turkish -> ASCII fold for more tolerant matching (e.g. "brüt" vs "brut")
  $fold = strtr($h, [
    'ç'=>'c','ğ'=>'g','ı'=>'i','i̇'=>'i','ö'=>'o','ş'=>'s','ü'=>'u',
    'â'=>'a','î'=>'i','û'=>'u'
  ]);
  $fold = preg_replace('/\s+/u', ' ', trim($fold));

  $map = [
    // Tali CSV
    't.c / v.n.'=>'tc_vn', 't.c / v.n'=>'tc_vn', 'tc / vn'=>'tc_vn', 't.c'=>'tc_vn', 'v.n'=>'tc_vn', 'tc'=>'tc_vn', 'vn'=>'tc_vn',
    'sigortalı'=>'sigortali', 'sigortali'=>'sigortali',
    'plaka'=>'plaka',
    'şirket'=>'sirket', 'sirket'=>'sirket', 'sigorta şirketi'=>'sirket', 'sigorta sirketi'=>'sirket',
    'branş'=>'brans', 'brans'=>'brans',
    'tip'=>'tip', 'txn type'=>'tip', 'type'=>'tip',
    'tanzim'=>'tanzim', 'tanzim tarihi'=>'tanzim_tarihi',
    'poliçe no'=>'police_no', 'police no'=>'police_no', 'poliçe'=>'police_no', 'police'=>'police_no', 'policy no'=>'police_no',
    'brüt prim'=>'brut_prim', 'brut prim'=>'brut_prim', 'brutprim'=>'brut_prim', 'brütprim'=>'brut_prim',

    // Ana CSV
    'bitiş tarihi'=>'bitis_tarihi','bitis tarihi'=>'bitis_tarihi',
    'sig. kimlik no'=>'sig_kimlik_no','sig kimlik no'=>'sig_kimlik_no','sigortalı kimlik no'=>'sig_kimlik_no','sig kimlik'=>'sig_kimlik_no',
    'ürün'=>'urun','urun'=>'urun',
    'zeyil türü'=>'zeyil_turu','zeyil turu'=>'zeyil_turu',
    'net prim'=>'net_prim','netprim'=>'net_prim',
    'komisyon tutarı'=>'komisyon_tutari','komisyon tutari'=>'komisyon_tutari','komisyon'=>'komisyon_tutari',
    'aracı kom. payı'=>'araci_kom_payi','araci kom payi'=>'araci_kom_payi','araci komisyon payi'=>'araci_kom_payi',

    // misc
    'id'=>'id',
  ];

  // try exact, then folded fallback
  if (isset($map[$h])) return $map[$h];
  if (isset($map[$fold])) return $map[$fold];

  // heuristic fallback: strip punctuation and match common tokens
  $clean = preg_replace('/[^a-z0-9]+/',' ', $fold);
  $clean = trim(preg_replace('/\s+/', ' ', $clean));
  if (strpos($clean, 'brut') !== false && strpos($clean, 'prim') !== false) return 'brut_prim';
  if (strpos($clean, 'net') !== false && strpos($clean, 'prim') !== false) return 'net_prim';
  if (strpos($clean, 'komisyon') !== false && (strpos($clean, 'tutar') !== false || strpos($clean, 'tutari') !== false)) return 'komisyon_tutari';
  if (strpos($clean, 'araci') !== false && strpos($clean, 'pay') !== false) return 'araci_kom_payi';
  if ((strpos($clean, 'police') !== false || strpos($clean, 'polic') !== false) && strpos($clean, 'no') !== false) return 'police_no';
  if (strpos($clean, 'tanzim') !== false) return 'tanzim_tarihi';
  if (strpos($clean, 'bitis') !== false) return 'bitis_tarihi';
  if (strpos($clean, 'kimlik') !== false && strpos($clean, 'no') !== false) return 'sig_kimlik_no';
  if (strpos($clean, 'sigortali') !== false) return 'sigortali';

  return $h;
}

function _parse_date_any(?string $s): ?string {
  if ($s === null) return null;
  $s = trim($s);
  if ($s === '') return null;

  // 2026-02-01
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

  // 01.02.2026 / 01/02/2026
  if (preg_match('/^(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{4})$/', $s, $m)) {
    $d = str_pad($m[1],2,'0',STR_PAD_LEFT);
    $mo = str_pad($m[2],2,'0',STR_PAD_LEFT);
    $y = $m[3];
    return "{$y}-{$mo}-{$d}";
  }

  $ts = strtotime($s);
  if ($ts) return date('Y-m-d', $ts);
  return null;
}

function _to_decimal(?string $s): ?string {
  if ($s === null) return null;
  $s = trim($s);
  if ($s === '') return null;
  // 1.234,56 -> 1234.56
  $s = str_replace([' ', '₺', 'TRY'], '', $s);
  if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
    // assume dot thousands, comma decimal
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else {
    $s = str_replace(',', '.', $s);
  }
  if (!is_numeric($s)) return null;
  return number_format((float)$s, 2, '.', '');
}

function _sci_to_int_string(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = str_replace(' ', '', $s);
  $s = str_replace(',', '.', $s); // TR decimal comma -> dot
  $s = strtoupper($s);

  // Match like 1.07101E+12 or 1E+14
  if (!preg_match('/^([0-9]+)(?:\.([0-9]+))?E([+\-]?[0-9]+)$/', $s, $m)) {
    return $s;
  }
  $intPart = $m[1];
  $fracPart = $m[2] ?? '';
  $exp = (int)$m[3];

  // Build mantissa digits
  $digits = $intPart . $fracPart;
  // Remove leading zeros (keep at least one)
  $digits = ltrim($digits, '0');
  if ($digits === '') $digits = '0';

  // Decimal position was after intPart (length of intPart)
  $decPos = strlen($intPart);

  // New decimal position after applying exponent
  $newDecPos = $decPos + $exp;

  if ($newDecPos <= 0) {
    // 0.xxx -> becomes 0 when forcing int string
    return '0';
  }

  if ($newDecPos >= strlen($digits)) {
    // Pad zeros to the right
    return $digits . str_repeat('0', $newDecPos - strlen($digits));
  }

  // Decimal point would be inside digits; we drop fractional part for integer-like ids
  return substr($digits, 0, $newDecPos);
}

function _policy_clean(?string $s): ?string {
  if ($s === null) return null;
  $s = trim($s);
  if ($s === '') return null;

  // Convert scientific notation if present
  if (stripos($s, 'E+') !== false || stripos($s, 'E-') !== false) {
    $s2 = _sci_to_int_string($s);
    $s = $s2;
  }

  $s = trim($s);

  // If it contains any letter, keep as compact uppercase token
  if (preg_match('/[A-Za-z]/', $s)) {
    $s = strtoupper($s);
    $s = preg_replace('/\s+/', '', $s);
    return $s;
  }

  // Otherwise keep digits only
  $digits = preg_replace('/\D+/', '', $s);
  return $digits === '' ? null : $digits;
}

function _policy_norm(?string $s): string {
  $s2 = _policy_clean($s);
  if ($s2 === null) return '';
  // Normalize numeric IDs by stripping leading zeros
  if (ctype_digit($s2)) {
    $t = ltrim($s2, '0');
    return $t === '' ? '0' : $t;
  }
  return $s2;
}



/* =========================================================
   UI format helpers (TR)
========================================================= */
function _fmt_tr_money($v): string {
  if ($v === null || $v === '') return '';
  $n = (float)$v;
  return number_format($n, 2, ',', '.');
}
function _money_span($v): string {
  if ($v === null || $v === '') return '';
  $n = (float)$v;
  $cls = ($n < 0) ? 'neg' : (($n > 0) ? 'pos' : 'zero');
  $sign = ($n > 0) ? '+' : '';
  return '<span class="money '.$cls.'">'.$sign.number_format($n, 2, ',', '.').'</span>';
}

/* =========================================================
   POST actions:
   - tali_csv_upload
   - ana_csv_upload
   - run_match
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if (!$periodId) {
    $flashErr = 'Dönem seçmeden işlem yapılamaz.';
  } else {

    /* ---------- Tali CSV Upload (sadece tali + workmode csv) ---------- */
    if ($action === 'tali_csv_upload') {
      if (!$isTali || $taliWorkMode !== 'csv') {
        $flashErr = 'Yetkisiz işlem.';
      } elseif (empty($_FILES['csv_file']['tmp_name'])) {
        $flashErr = 'CSV dosyası seçilmedi.';
      } else {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $origName = (string)($_FILES['csv_file']['name'] ?? 'tali.csv');
        $fileHash = @sha1_file($tmp) ?: null;

        $conn->begin_transaction();
        try {
          // batch
          $st = $conn->prepare("INSERT INTO mutabakat_v2_import_batches (period_id, ana_acente_id, tali_acente_id, source_type, filename, file_hash, total_rows, ok_rows, error_rows, created_by, created_at)
                                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
          // DB source_type standard: TICKET | TALI_CSV | ANA_CSV
          $srcType = 'TALI_CSV';
          $total = 0; $ok=0; $err=0;
          $st->bind_param('iiisssiiii', $periodId, $mainAgencyId, $taliAgencyId, $srcType, $origName, $fileHash, $total, $ok, $err, $userId);
          $st->execute();
          $batchId = (int)$st->insert_id;
          $st->close();

          $fh = fopen($tmp, 'r');
          if (!$fh) throw new Exception('Dosya okunamadı.');

          $firstLine = fgets($fh);
          if ($firstLine === false) throw new Exception('Boş dosya.');
          $delim = _guess_delim($firstLine);
          // rewind
          rewind($fh);

          $headerRaw = fgetcsv($fh, 0, $delim);
          if (!$headerRaw) throw new Exception('Başlık satırı okunamadı.');
          $headerRaw = _csv_fix_row($headerRaw, $delim);
          $headers_mapped = [];
          $headers_set = [];
          foreach ($headerRaw as $i => $h) {
            $k = _norm_header((string)$h);
            $headers_mapped[$i] = $k;
            $headers_set[$k] = true;
          }

          // expected headers (canonical keys)
          $need = ['tc_vn','sigortali','plaka','sirket','brans','tip','tanzim','police_no','brut_prim'];
          $missing = [];
          foreach ($need as $n) if (!isset($headers_set[$n])) $missing[] = $n;

          // debug to server error log (only when missing)
          if ($missing) {
            @error_log('[MUTABAKAT][TALI_CSV] headerRaw='.json_encode($headerRaw, JSON_UNESCAPED_UNICODE));
            @error_log('[MUTABAKAT][TALI_CSV] headersMapped='.json_encode(array_values($headers_mapped), JSON_UNESCAPED_UNICODE));
            throw new Exception('Eksik kolonlar: '.implode(', ', $missing));
          }

          // build index lookup: canonical key -> column index (first occurrence)
          $idx = [];
          foreach ($headers_mapped as $i => $k) {
            if (!isset($idx[$k]) && $k !== '') $idx[$k] = $i;
          }
$rowNo = 1;
          while (($row = fgetcsv($fh, 0, $delim)) !== false) {
            $rawLine = is_array($row) ? implode($delim, $row) : (string)$row;
            $row = _csv_fix_row($row, $delim);
            $rowNo++;
            if (count(array_filter($row, fn($x)=>trim((string)$x)!=='')) === 0) continue;
            $total++;

            $tc_vn  = trim((string)($row[$idx['tc_vn']] ?? ''));
            $sigortali = trim((string)($row[$idx['sigortali']] ?? ''));
            $plaka  = trim((string)($row[$idx['plaka']] ?? ''));
            $sirket = trim((string)($row[$idx['sirket']] ?? ''));
            $brans  = trim((string)($row[$idx['brans']] ?? ''));
            $tip    = strtoupper(trim((string)($row[$idx['tip']] ?? '')));
            $tanzim = _parse_date_any((string)($row[$idx['tanzim']] ?? ''));
            $police = trim((string)($row[$idx['police_no']] ?? ''));
            $police = _policy_clean($police) ?? '';
            $police = _policy_clean($police) ?? '';
            $police = _policy_clean($police) ?? '';
            $police = _policy_clean($police) ?? '';
            $police = _policy_clean($police) ?? '';
            $police = _policy_clean($police) ?? '';
            $brut   = _to_decimal((string)($row[$idx['brut_prim']] ?? ''));

            if ($police === '' || !$tanzim || !$brut) {
              $err++;
              $code='VALIDATION';
              $msg='Poliçe No / Tanzim / Brüt Prim zorunlu.';
              $rawLine = json_encode($row, JSON_UNESCAPED_UNICODE);
              $stE=$conn->prepare("INSERT INTO mutabakat_v2_import_errors (batch_id, row_no, error_code, error_message, raw_line, created_at)
                                   VALUES (?,?,?,?,?,NOW())");
              $stE->bind_param('iisss', $batchId, $rowNo, $code, $msg, $rawLine);
              $stE->execute(); $stE->close();
              continue;
            }

            // insert row
            $rowStatus = 'HAVUZ';
            $src = 'TALI_CSV';
            $locked = 0;
            $stR = $conn->prepare("INSERT INTO mutabakat_v2_rows
              (period_id, ana_acente_id, tali_acente_id, tc_vn, source_type, import_batch_id, policy_no, txn_type, sigortali_adi, tanzim_tarihi, sigorta_sirketi, brans, plaka, brut_prim, currency, row_status, locked, created_by, created_at, updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?, NOW(), NOW())
              ON DUPLICATE KEY UPDATE updated_at=NOW()
            ");

            $currency = 'TRY';
            $stR->bind_param(
              'iiississssssssssii',
              $periodId, $mainAgencyId, $taliAgencyId, $tc_vn, $src,
              $batchId, $police, $tip,
              $sigortali, $tanzim, $sirket, $brans, $plaka, $brut,
              $currency, $rowStatus, $locked, $userId
            );
            if(!$stR->execute()) {
              // Count as error and log why
              $errMsg = $stR->error ?: 'DB insert failed';
              // NOTE: this is Tali CSV insert path
              error_log('TALI_CSV insert error: '.$errMsg.' | raw='.$rawLine);
              $err++;
              $stR->close();
              // also store into import_errors
              $code = 'DB_INSERT';
              $msg = $errMsg;
              $stE = $conn->prepare("INSERT INTO mutabakat_v2_import_errors (batch_id, row_no, error_code, error_message, raw_line, created_at)
                                   VALUES (?,?,?,?,?,NOW())");
              $stE->bind_param('iisss', $batchId, $rowNo, $code, $msg, $rawLine);
              $stE->execute(); $stE->close();
              continue;
            }
            $stR->close();
            $ok++;
          }
          fclose($fh);

          // update batch counts
          $stU = $conn->prepare("UPDATE mutabakat_v2_import_batches SET total_rows=?, ok_rows=?, error_rows=? WHERE id=?");
          $stU->bind_param('iiii', $total, $ok, $err, $batchId);
          $stU->execute(); $stU->close();

          $conn->commit();
          $flashOk = "Tali CSV yüklendi. Toplam: {$total}, Başarılı: {$ok}, Hatalı: {$err}";
        } catch (Throwable $e) {
          $conn->rollback();
          $flashErr = "Tali CSV yükleme hatası: ".$e->getMessage();
        }
      }
    }

    /* ---------- Ana CSV Upload (sadece ana) ---------- */
    if ($action === 'ana_csv_upload') {
      if (!$isMain) {
        $flashErr = 'Yetkisiz işlem.';
      } elseif (empty($_FILES['csv_file']['tmp_name'])) {
        $flashErr = 'CSV dosyası seçilmedi.';
      } else {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $origName = (string)($_FILES['csv_file']['name'] ?? 'ana.csv');
        $fileHash = @sha1_file($tmp) ?: null;

        $conn->begin_transaction();
        try {
          $st = $conn->prepare("INSERT INTO mutabakat_v2_import_batches (period_id, ana_acente_id, tali_acente_id, source_type, filename, file_hash, total_rows, ok_rows, error_rows, created_by, created_at)
                                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
          $srcType = 'ANA_CSV';
          $tali0 = 0;
          $total = 0; $ok=0; $err=0;
          $st->bind_param('iiisssiiii', $periodId, $mainAgencyId, $tali0, $srcType, $origName, $fileHash, $total, $ok, $err, $userId);
          $st->execute();
          $batchId = (int)$st->insert_id;
          $st->close();

          $fh = fopen($tmp, 'r');
          if (!$fh) throw new Exception('Dosya okunamadı.');

          $firstLine = fgets($fh);
          if ($firstLine === false) throw new Exception('Boş dosya.');
          $delim = _guess_delim($firstLine);
          rewind($fh);

          $headerRaw = fgetcsv($fh, 0, $delim);
          if (!$headerRaw) throw new Exception('Başlık satırı okunamadı.');
          $headerRaw = _csv_fix_row($headerRaw, $delim);
          $headers_mapped = [];
          $headers_set = [];
          foreach ($headerRaw as $i => $h) {
            $k = _norm_header((string)$h);
            $headers_mapped[$i] = $k;
            $headers_set[$k] = true;
          }

          $need = ['tanzim_tarihi','bitis_tarihi','sigortali','sig_kimlik_no','sirket','urun','zeyil_turu','police_no','plaka','brut_prim','net_prim','komisyon_tutari','araci_kom_payi'];
          $missing = [];
          foreach ($need as $n) if (!isset($headers_set[$n])) $missing[] = $n;
          if ($missing) {
            @error_log('[MUTABAKAT][ANA_CSV] headerRaw='.json_encode($headerRaw, JSON_UNESCAPED_UNICODE));
            @error_log('[MUTABAKAT][ANA_CSV] headersMapped='.json_encode(array_values($headers_mapped), JSON_UNESCAPED_UNICODE));
            throw new Exception('Eksik kolonlar: '.implode(', ', $missing));
          }

          $idx = [];
          foreach ($headers_mapped as $i => $k) {
            if (!isset($idx[$k]) && $k !== '') $idx[$k] = $i;
          }

$rowNo = 1;
          while (($row = fgetcsv($fh, 0, $delim)) !== false) {
            $rawLine = is_array($row) ? implode($delim, $row) : (string)$row;
            $row = _csv_fix_row($row, $delim);
            $rowNo++;
            if (count(array_filter($row, fn($x)=>trim((string)$x)!=='')) === 0) continue;
            $total++;

            $tanzim = _parse_date_any((string)($row[$idx['tanzim_tarihi']] ?? ''));
            $bitis  = _parse_date_any((string)($row[$idx['bitis_tarihi']] ?? ''));
            $sigortali = trim((string)($row[$idx['sigortali']] ?? ''));
            $sigNo = trim((string)($row[$idx['sig_kimlik_no']] ?? ''));
            $sirket = trim((string)($row[$idx['sirket']] ?? ''));
            $urun   = trim((string)($row[$idx['urun']] ?? ''));
            $zeyil  = trim((string)($row[$idx['zeyil_turu']] ?? ''));
            $police = trim((string)($row[$idx['police_no']] ?? ''));
            $police = _policy_clean($police) ?? '';
            $police = _policy_clean($police) ?? '';
            $police = _policy_clean($police) ?? '';
            $police = _policy_clean($police) ?? '';
            $police = _policy_clean($police) ?? '';
            $police = _policy_clean($police) ?? '';
            $plaka  = trim((string)($row[$idx['plaka']] ?? ''));
            $brut   = _to_decimal((string)($row[$idx['brut_prim']] ?? ''));
            $net    = _to_decimal((string)($row[$idx['net_prim']] ?? ''));
            $kom    = _to_decimal((string)($row[$idx['komisyon_tutari']] ?? ''));
            $araci  = _to_decimal((string)($row[$idx['araci_kom_payi']] ?? ''));

            if ($police === '' || !$tanzim || !$brut) {
              $err++;
              $code='VALIDATION';
              $msg='Poliçe No / Tanzim Tarihi / Brüt Prim zorunlu.';
              $rawLine = json_encode($row, JSON_UNESCAPED_UNICODE);
              $stE=$conn->prepare("INSERT INTO mutabakat_v2_import_errors (batch_id, row_no, error_code, error_message, raw_line, created_at)
                                   VALUES (?,?,?,?,?,NOW())");
              $stE->bind_param('iisss', $batchId, $rowNo, $code, $msg, $rawLine);
              $stE->execute(); $stE->close();
              continue;
            }

            $rowStatus = 'HAVUZ'; // Ana CSV kayıtları bu sekmede görünür; diğer sekmelerde source_type ile filtrelenir.
            $src = 'ANA_CSV';
            $locked = 0;

            // txn_type DB'de genelde ENUM/SATIS-ZEYIL-IPTAL gibi sınırlı. Ana CSV'de bu bilgi "Zeyil Türü" kolonu
            // üzerinden (İptal/İade/Tahakkuk vs) gelebiliyor. DB truncation yaşamamak için normalize ediyoruz.
            $zeyilNorm = mb_strtolower(trim((string)$zeyil), 'UTF-8');
            if ($zeyilNorm === '') {
              $txnType = 'SATIS';
            } elseif (strpos($zeyilNorm, 'iptal') !== false || strpos($zeyilNorm, 'iade') !== false) {
              $txnType = 'IPTAL';
            } elseif (strpos($zeyilNorm, 'zeyil') !== false) {
              $txnType = 'ZEYIL';
            } else {
              // Tahakkuk vb. satış gibi değerlensin
              $txnType = 'SATIS';
            }
            $currency = 'TRY';
            $tali0 = 0;

            $stR = $conn->prepare("INSERT INTO mutabakat_v2_rows
              (period_id, ana_acente_id, tali_acente_id, source_type, import_batch_id, policy_no, txn_type, zeyil_turu, sigortali_adi, sig_kimlik_no, tanzim_tarihi, bitis_tarihi, sigorta_sirketi, urun, plaka, brut_prim, net_prim, komisyon_tutari, araci_kom_payi, currency, row_status, locked, created_by, created_at, updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
              ON DUPLICATE KEY UPDATE updated_at=NOW()
            ");

            $stR->bind_param(
              'iiisissssssssssssssssii',
              $periodId, $mainAgencyId, $tali0, $src, $batchId, $police, $txnType, $zeyil,
              $sigortali, $sigNo, $tanzim, $bitis, $sirket, $urun, $plaka,
              $brut, $net, $kom, $araci, $currency, $rowStatus, $locked, $userId
            );
            if(!$stR->execute()) {
              // Count as error and log why
              $errMsg = $stR->error ?: 'DB insert failed';
              error_log('ANA_CSV insert error: '.$errMsg.' | raw='.$rawLine);
              $err++;
              $stR->close();
              // also store into import_errors
              $code = 'DB_INSERT';
              $msg = $errMsg;
              $stE = $conn->prepare("INSERT INTO mutabakat_v2_import_errors (batch_id, row_no, error_code, error_message, raw_line, created_at)
                                   VALUES (?,?,?,?,?,NOW())");
              $stE->bind_param('iisss', $batchId, $rowNo, $code, $msg, $rawLine);
              $stE->execute(); $stE->close();
              continue;
            }
            $stR->close();
            $ok++;
          }
          fclose($fh);

          $stU = $conn->prepare("UPDATE mutabakat_v2_import_batches SET total_rows=?, ok_rows=?, error_rows=? WHERE id=?");
          $stU->bind_param('iiii', $total, $ok, $err, $batchId);
          $stU->execute(); $stU->close();

          $conn->commit();
          $flashOk = "Ana acente CSV yüklendi. Toplam: {$total}, Başarılı: {$ok}, Hatalı: {$err}";
        } catch (Throwable $e) {
          $conn->rollback();
          $flashErr = "Ana CSV yükleme hatası: ".$e->getMessage();
        }
      }
    }

    /* ---------- Eşleştir (sadece ana) ---------- */
    
/** ================================
 * Update unmatched ANA_CSV row then re-run match
 * ================================ */

/** ================================
 * Bulk update unmatched ANA_CSV rows (no matching)
 * payload: JSON array [{id,sigortali_adi,tc_vn,policy_no,net_prim}]
 * ================================ */
if ($action === 'bulk_save_unmatched') {
  if (!$isMain) { $flashErr = 'Yetkisiz işlem.'; }
  $payload = (string)($_POST['payload'] ?? '[]');
  $items = json_decode($payload, true);
  if (!is_array($items)) $items = [];

  $saved = 0;
  foreach ($items as $it) {
    $rowId = (int)($it['id'] ?? 0);
    if ($rowId <= 0) continue;

    $sigortali = trim((string)($it['sigortali_adi'] ?? ''));
    $tcVn = trim((string)($it['tc_vn'] ?? ''));
    $policyNoRaw = trim((string)($it['policy_no'] ?? ''));
    $policyNo = _policy_clean($policyNoRaw) ?? $policyNoRaw;
    $netPrim = _to_decimal((string)($it['net_prim'] ?? ''));

    try {
      $st = $pdo->prepare("UPDATE mutabakat_v2_rows
                           SET sigortali_adi = :sig, tc_vn = :tc, policy_no = :pol, net_prim = :net, updated_at = NOW()
                           WHERE id = :id AND period_id = :pid AND ana_acente_id = :aid AND source_type='ANA_CSV'");
      $st->execute([
        ':sig' => $sigortali === '' ? null : $sigortali,
        ':tc'  => $tcVn === '' ? null : $tcVn,
        ':pol' => $policyNo === '' ? null : $policyNo,
        ':net' => $netPrim,
        ':id'  => $rowId,
        ':pid' => $periodId,
        ':aid' => $anaAcenteId,
      ]);
      $saved++;
    } catch (Throwable $e) {
      // ignore single row failure, keep going
      error_log('bulk_save_unmatched error row_id='.$rowId.' msg='.$e->getMessage());
    }
  }

  $flashOk = $saved > 0 ? ("Kaydedildi: {$saved} satır") : "Kaydedilecek değişiklik yok.";
  header('Location: '.base_url('platform/mutabakat/havuz.php?tab=eslesmeyen&period_id='.$periodId));
  exit;
}

if ($action === 'update_unmatched') {
  $rowId = (int)($_POST['row_id'] ?? 0);
  $sigortali = trim((string)($_POST['sigortali_adi'] ?? ''));
  $tcVn = trim((string)($_POST['tc_vn'] ?? ''));
  $policyNoRaw = trim((string)($_POST['policy_no'] ?? ''));
  $policyNo = _policy_clean($policyNoRaw) ?? $policyNoRaw;
  $netPrim = _to_decimal((string)($_POST['net_prim'] ?? ''));

  if ($rowId > 0) {
    $st = $pdo->prepare("UPDATE mutabakat_v2_rows
                         SET sigortali_adi = :sig, tc_vn = :tc, policy_no = :pol, net_prim = :net, updated_at = NOW()
                         WHERE id = :id AND period_id = :pid AND ana_acente_id = :aid AND source_type='ANA_CSV'");
    $st->execute([
      ':sig' => $sigortali === '' ? null : $sigortali,
      ':tc'  => $tcVn === '' ? null : $tcVn,
      ':pol' => $policyNo === '' ? null : $policyNo,
      ':net' => $netPrim,
      ':id'  => $rowId,
      ':pid' => $periodId,
      ':aid' => $anaAcenteId,
    ]);
  }

  // After update, run match and return to the same tab
  $afterMatchRedirectUrl = base_url('platform/mutabakat/havuz.php?tab=eslesmeyen&period_id=' . $periodId);
  $action = 'run_match';
}

if ($action === 'run_match') {
      if (!$isMain) {
        $flashErr = 'Yetkisiz işlem.';
      } else {
        $conn->begin_transaction();
        try {
          // ana rows
          $anaRows = [];
          $st = $conn->prepare("SELECT id, policy_no FROM mutabakat_v2_rows WHERE period_id=? AND ana_acente_id=? AND source_type='ANA_CSV'");
          $st->bind_param('ii', $periodId, $mainAgencyId);
          $st->execute();
          $res = $st->get_result();
          while ($res && ($r = $res->fetch_assoc())) $anaRows[] = $r;
          $st->close();

          // build lookup for tali+ticket by policy_no
          $pool = [];
          $st = $conn->prepare("SELECT id, policy_no FROM mutabakat_v2_rows WHERE period_id=? AND ana_acente_id=? AND source_type IN ('TICKET','TALI_CSV')");
          $st->bind_param('ii', $periodId, $mainAgencyId);
          $st->execute();
          $res = $st->get_result();
          while ($res && ($r = $res->fetch_assoc())) {
            $p = (string)$r['policy_no'];
            $k = _policy_norm($p);
            if ($k==='') continue;
            if (!isset($pool[$k])) $pool[$k] = [];
            $pool[$k][] = (int)$r['id'];
          }
          $st->close();

          $matched = 0; $unmatched = 0;

          foreach ($anaRows as $ar) {
            $anaId = (int)$ar['id'];
            $pno = (string)$ar['policy_no'];
            $pkey = _policy_norm($pno);
            if ($pkey==='') $pkey = $pno;
            $taliId = 0;

            if ($pkey !== '' && isset($pool[$pkey]) && !empty($pool[$pkey])) {
              $taliId = array_shift($pool[$pkey]);

              // upsert match
              $status = 'MATCHED';
              $mismatch = '';
              $stM = $conn->prepare("INSERT INTO mutabakat_v2_matches (period_id, policy_no, tali_row_id, ana_row_id, status, mismatch_summary, created_at)
                                     VALUES (?,?,?,?,?,?,NOW())
                                     ON DUPLICATE KEY UPDATE status=VALUES(status), mismatch_summary=VALUES(mismatch_summary)");
              $matchPno = $pkey;
              $stM->bind_param('isiiis', $periodId, $matchPno, $taliId, $anaId, $status, $mismatch);
              $stM->execute(); $stM->close();

              // update row_status
              $stU = $conn->prepare("UPDATE mutabakat_v2_rows SET row_status='ESLESEN', updated_at=NOW() WHERE id IN (?,?)");
              $stU->bind_param('ii', $taliId, $anaId);
              $stU->execute(); $stU->close();

              $matched++;
            } else {
              // no match
              $stU = $conn->prepare("UPDATE mutabakat_v2_rows SET row_status='ESLESMEYEN', updated_at=NOW() WHERE id=?");
              $stU->bind_param('i', $anaId);
              $stU->execute(); $stU->close();
              $unmatched++;
            }
          }

          $conn->commit();
          $flashOk = "Eşleştirme tamamlandı. Eşleşen: {$matched}, Eşleşmeyen: {$unmatched}";
          // switch to eslesen tab
          $allowedTab = 'eslesen';
        } catch (Throwable $e) {
          $conn->rollback();
          $flashErr = "Eşleştirme hatası: ".$e->getMessage();
        }
      }
    }
  }
}

/* =========================================================
   Data fetch for table view
========================================================= */
$rows = [];
$matches = [];

if ($periodId) {
  if ($allowedTab === 'havuz') {
    if ($isMain) {
      $st = $conn->prepare("SELECT id, tali_acente_id, tc_vn, sigortali_adi, plaka, sigorta_sirketi, brans, txn_type, tanzim_tarihi, policy_no, brut_prim
                            FROM mutabakat_v2_rows
                            WHERE period_id=? AND ana_acente_id=? AND source_type IN ('TICKET','TALI_CSV') AND row_status='HAVUZ'
                            ORDER BY id DESC LIMIT 500");
      $st->bind_param('ii', $periodId, $mainAgencyId);
    } else {
      $st = $conn->prepare("SELECT id, tali_acente_id, tc_vn, sigortali_adi, plaka, sigorta_sirketi, brans, txn_type, tanzim_tarihi, policy_no, brut_prim
                            FROM mutabakat_v2_rows
                            WHERE period_id=? AND ana_acente_id=? AND tali_acente_id=? AND source_type IN ('TICKET','TALI_CSV') AND row_status='HAVUZ'
                            ORDER BY id DESC LIMIT 500");
      $st->bind_param('iii', $periodId, $mainAgencyId, $agencyId);
    }
    if ($st) {
      $st->execute();
      $res = $st->get_result();
      while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
      $st->close();
    }
  }

  if ($allowedTab === 'ana_csv' && $isMain) {
    $st = $conn->prepare("SELECT id, policy_no, sigortali_adi, sig_kimlik_no, sigorta_sirketi, urun, zeyil_turu, tanzim_tarihi, plaka, brut_prim, net_prim, komisyon_tutari, araci_kom_payi, row_status
                          FROM mutabakat_v2_rows
                          WHERE period_id=? AND ana_acente_id=? AND source_type='ANA_CSV'
                          ORDER BY id DESC LIMIT 500");
    $st->bind_param('ii', $periodId, $mainAgencyId);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
    $st->close();
  }

  if ($allowedTab === 'eslesen' && $isMain) {
    $st = $conn->prepare("SELECT 
                                 t.id AS tali_row_id,
                                 t.sigortali_adi AS tali_sigortali,
                                 COALESCE(t.tc_vn, t.sig_kimlik_no) AS tali_tc_vn,
                                 m.policy_no,
                                 a.araci_kom_payi AS araci_kom_payi,
                                 m.ana_row_id
                          FROM mutabakat_v2_matches m
                          JOIN mutabakat_v2_rows t ON t.id=m.tali_row_id
                          JOIN mutabakat_v2_rows a ON a.id=m.ana_row_id
                          WHERE m.period_id=? AND m.status='MATCHED'
                          ORDER BY m.id DESC LIMIT 500");
    $st->bind_param('i', $periodId);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) $matches[] = $r;
    $st->close();
  }

  if ($allowedTab === 'eslesmeyen' && $isMain) {
    // show ana rows that are marked as ESLESMEYEN
    $st = $conn->prepare("SELECT id, sigortali_adi, tc_vn, sig_kimlik_no, policy_no, net_prim, row_status
                          FROM mutabakat_v2_rows
                          WHERE period_id=? AND ana_acente_id=? AND source_type='ANA_CSV' AND row_status='ESLESMEYEN'
                          ORDER BY id DESC LIMIT 500");
    $st->bind_param('ii', $periodId, $mainAgencyId);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
    $st->close();
  }

  if ($allowedTab === 'itiraz') {
    // şimdilik placeholder: ileride match/dispute bağlanacak
    $rows = [];
  }
}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
  /* Excel-like table */
  table.excel-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
  table.excel-table thead th {
    position: sticky; top: 0;
    background: rgba(15,23,42,.06);
    border-bottom: 1px solid rgba(15,23,42,.12);
    padding:10px 10px;
    text-align:left;
    font-weight:700;
    cursor:pointer;
    user-select:none;
    white-space:nowrap;
  }
  table.excel-table tbody td{
    padding:10px 10px;
    border-bottom:1px solid rgba(15,23,42,.06);
    vertical-align:top;
  }
  table.excel-table tbody tr:nth-child(even){ background: rgba(2,6,23,.02); }
  table.excel-table tbody tr:hover{ background: rgba(59,130,246,.06); }
  .money.pos{ color:#16a34a; font-weight:700; }
  .money.neg{ color:#dc2626; font-weight:700; }
  .money.zero{ color:rgba(15,23,42,.65); font-weight:600; }
  .copy-toast{
    position: fixed; right: 18px; bottom: 18px;
    background: rgba(15,23,42,.92); color:#fff;
    padding:10px 12px; border-radius:12px;
    font-size:12.5px; z-index: 9999;
    opacity:0; transform: translateY(6px);
    transition: all .18s ease;
    pointer-events:none;
    max-width: 320px;
  }
  .copy-toast.show{ opacity:1; transform: translateY(0); }

  .cell-input{
    width:100%;
    border:1px solid transparent;
    background:transparent;
    padding:6px 8px;
    border-radius:10px;
    font:inherit;
    color:inherit;
    outline:none;
  }
  .cell-input:focus{
    background:rgba(255,255,255,.9);
    border-color:rgba(59,130,246,.35);
    box-shadow:0 0 0 3px rgba(59,130,246,.12);
  }
  .cell-input-right{ text-align:right; }

</style>
<div id="copyToast" class="copy-toast"></div>

<script>
(function(){
  function toast(msg){
    var el = document.getElementById('copyToast');
    if(!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(function(){ el.classList.remove('show'); }, 1600);
  }
  function fallbackCopy(text){
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly','');
    ta.style.position='fixed';
    ta.style.left='-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch(e){}
    document.body.removeChild(ta);
  }
  function copyText(text){
    if(navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(text).then(function(){}, function(){ fallbackCopy(text); });
    } else {
      fallbackCopy(text);
    }
  }

  // Click header to copy that column's values
  document.addEventListener('click', function(e){
    var th = e.target.closest('th');
    if(!th) return;

    var table = th.closest('table.copyable-table');
    if(!table) return;

    var tr = th.parentElement;
    var ths = Array.prototype.slice.call(tr.children);
    var colIndex = ths.indexOf(th);
    if(colIndex < 0) return;

    var rows = table.querySelectorAll('tbody tr');
    var values = [];
    rows.forEach(function(r){
      var cells = r.children;
      if(!cells || colIndex >= cells.length) return;
      var cell = cells[colIndex];
      var inp = cell.querySelector('input,textarea,select');
      var v = '';
      if(inp){ v = (inp.value || '').trim(); } else { v = (cell.innerText || '').trim(); }
      if(v !== '') values.push(v);
    });

    if(values.length === 0){
      toast('Kopyalanacak veri yok.');
      return;
    }
    copyText(values.join('\n'));
    toast('“' + (th.innerText||'Kolon') + '” sütunu kopyalandı ('+values.length+').');
  });
})();
</script>

<div
<div style="max-width:1200px; margin:24px auto; padding:0 16px;">
  <div style="background:linear-gradient(180deg, rgba(255,255,255,.9), rgba(255,255,255,.72)); border:1px solid rgba(15,23,42,.10); border-radius:18px; box-shadow:0 14px 40px rgba(2,6,23,.08); padding:16px;">
    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div>
        <div style="font-size:18px; font-weight:800;">Mutabakat • Havuz</div>
        <div style="font-size:13px; opacity:.75; margin-top:4px;"><?= $isMain ? 'Ana acente görünümü' : 'Tali acente görünümü' ?></div>
      </div>

      <form method="get" style="display:flex; align-items:center; gap:10px;">
        <input type="hidden" name="tab" value="<?= h($allowedTab) ?>">
        <label style="font-size:13px; opacity:.75;">Dönem</label>
        <select name="period_id" onchange="this.form.submit()" style="padding:8px 10px; border-radius:10px; border:1px solid rgba(15,23,42,.14);">
          <?php if (empty($periods)): ?>
            <option value="0">Dönem yok</option>
          <?php else: ?>
            <?php foreach ($periods as $p):
              $pid = (int)$p['id'];
              $ym  = sprintf('%04d-%02d', (int)$p['year'], (int)$p['month']);
              $stt = (string)($p['status'] ?? '');
            ?>
              <option value="<?= $pid ?>" <?= $pid===$periodId ? 'selected' : '' ?>><?= h($ym) ?><?= $stt ? ' • '.h($stt) : '' ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </form>
    </div>

    <?php if ($flashErr): ?>
      <div style="margin-top:12px; padding:10px 12px; border-radius:12px; background:#fff1f2; border:1px solid rgba(244,63,94,.25); color:#9f1239; font-size:13px;"><?= h($flashErr) ?></div>
    <?php endif; ?>
    <?php if ($flashOk): ?>
      <div style="margin-top:12px; padding:10px 12px; border-radius:12px; background:#ecfeff; border:1px solid rgba(6,182,212,.25); color:#155e75; font-size:13px;"><?= h($flashOk) ?></div>
    <?php endif; ?>

    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px;">
      <?php foreach ($tabsAll as $k => $meta):
        $can = ($isMain && $meta['main']) || ($isTali && $meta['tali']);
        if (!$can) continue;
        $active = $allowedTab === $k;
      ?>
        <a href="?tab=<?= h($k) ?>&period_id=<?= (int)$periodId ?>"
           style="display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; border:1px solid rgba(15,23,42,.12); background:<?= $active ? 'rgba(59,130,246,.10)' : 'rgba(255,255,255,.75)' ?>; font-size:13px; font-weight:700; color:#0f172a; text-decoration:none;">
          <?= h($meta['label']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Upload / Actions -->
  <div style="margin-top:14px;">
    <?php if ($allowedTab === 'havuz' && $isTali && $taliWorkMode === 'csv'): ?>
      <div style="background:rgba(255,255,255,.9); border:1px solid rgba(15,23,42,.10); border-radius:16px; padding:14px; box-shadow:0 10px 26px rgba(2,6,23,.06);">
        <div style="font-weight:800; margin-bottom:8px;">Tali CSV Yükle (workmode: csv)</div>
        <form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <input type="hidden" name="action" value="tali_csv_upload">
          <input type="file" name="csv_file" accept=".csv,text/csv" required>
          <button type="submit" style="padding:10px 14px; border-radius:12px; border:1px solid rgba(15,23,42,.12); background:#0f172a; color:#fff; font-weight:800;">Yükle</button>
          <div style="font-size:12px; opacity:.7;">Başlıklar: T.C/V.N., Sigortalı, Plaka, Şirket, Branş, Tip, Tanzim, Poliçe No, Brüt Prim</div>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($allowedTab === 'ana_csv' && $isMain): ?>
      <div style="background:rgba(255,255,255,.9); border:1px solid rgba(15,23,42,.10); border-radius:16px; padding:14px; box-shadow:0 10px 26px rgba(2,6,23,.06); display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
        <div>
          <div style="font-weight:800; margin-bottom:6px;">Ana acente CSV Yükle</div>
          <div style="font-size:12px; opacity:.7;">Yüklenen kayıtlar sadece bu sekmede görünür. Eşleştir’e basınca Eşleşen/Eşleşmeyen’e ayrılır.</div>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="action" value="ana_csv_upload">
            <input type="file" name="csv_file" accept=".csv,text/csv" required>
            <button type="submit" style="padding:10px 14px; border-radius:12px; border:1px solid rgba(15,23,42,.12); background:#0f172a; color:#fff; font-weight:800;">Yükle</button>
          </form>
          <form method="post">
            <input type="hidden" name="action" value="run_match">
            <button type="submit" style="padding:10px 14px; border-radius:12px; border:1px solid rgba(59,130,246,.35); background:rgba(59,130,246,.12); color:#1d4ed8; font-weight:900;">Eşleştir</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Tables -->
  <div style="margin-top:14px; background:rgba(255,255,255,.92); border:1px solid rgba(15,23,42,.10); border-radius:18px; box-shadow:0 14px 40px rgba(2,6,23,.08); overflow:hidden;">
    <?php if ($allowedTab === 'eslesen' && $isMain): ?>
      <div style="padding:12px 14px; font-weight:900;">Eşleşen Kayıtlar</div>
      <div style="overflow:auto;">
        <table class="excel-table copyable-table" style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead style="background:rgba(15,23,42,.04);">
            <tr>
              <th style="text-align:left; padding:10px 10px;">Poliçe No</th>
              <th style="text-align:left; padding:10px 10px;">Tali Id</th>
              <th style="text-align:left; padding:10px 10px;">T.C/V.N</th>
              <th style="text-align:left; padding:10px 10px;">Sigortalı</th>
              <th style="text-align:left; padding:10px 10px;">Plaka</th>
              <th style="text-align:left; padding:10px 10px;">Şirket</th>
              <th style="text-align:left; padding:10px 10px;">Branş</th>
              <th style="text-align:left; padding:10px 10px;">Tip</th>
              <th style="text-align:left; padding:10px 10px;">Tanzim</th>
              <th style="text-align:right; padding:10px 10px;">Brüt Prim</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($matches)): ?>
              <tr><td colspan="10" style="padding:14px; opacity:.7;">Kayıt yok.</td></tr>
            <?php else: ?>
              <?php foreach ($matches as $m): ?>
                <tr>
                  <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($m['policy_no'] ?? '') ?></td>
                  <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($m['tali_acente_id'] ?? '') ?></td>
                  <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($m['tc_vn'] ?? '') ?></td>
                  <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($m['tali_sigortali'] ?? '') ?></td>
                  <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($m['tali_plaka'] ?? '') ?></td>
                  <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($m['tali_sirket'] ?? '') ?></td>
                  <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($m['tali_brans'] ?? '') ?></td>
                  <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($m['tali_tip'] ?? '') ?></td>
                  <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($m['tali_tanzim'] ?? '') ?></td>
                  <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06); text-align:right;"><?= h($m['tali_brut'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php else: ?>
      <div style="padding:12px 14px; font-weight:900;">
        <?php if ($allowedTab === 'havuz'): ?>Havuz<?php endif; ?>
        <?php if ($allowedTab === 'ana_csv'): ?>Ana acente CSV<?php endif; ?>
        <?php if ($allowedTab === 'eslesmeyen'): ?>Eşleşmeyen<?php endif; ?>
        <?php if ($allowedTab === 'itiraz'): ?>İtiraz (yakında)<?php endif; ?>
      </div>

      <?php if ($allowedTab === 'eslesmeyen' && $isMain): ?>
        <div style="padding:0 14px 12px 14px; display:flex; gap:10px; align-items:center;">
          <form id="bulkSaveForm" method="post" style="margin:0;">
            <input type="hidden" name="action" value="bulk_save_unmatched">
            <input type="hidden" name="payload" id="bulkPayload" value="">
            <button type="button" id="btnSaveAll" style="padding:10px 12px; border-radius:12px; border:1px solid rgba(2,6,23,.12); background:#0f172a; color:#fff; font-weight:900; cursor:pointer;" disabled>Kaydet</button>
          </form>

          <form id="matchForm" method="post" style="margin:0;">
            <input type="hidden" name="action" value="run_match">
            <button type="submit" id="btnMatchAll" style="padding:10px 12px; border-radius:12px; border:1px solid rgba(16,185,129,.35); background:rgba(16,185,129,.12); color:#065f46; font-weight:900; cursor:pointer;">Eşleştir</button>
          </form>

          <div id="dirtyInfo" style="font-size:12px; opacity:.75; margin-left:auto;">Değişiklik yok</div>
        </div>

        <script>
        (function(){
          function qs(sel, root){ return (root||document).querySelector(sel); }
          function qsa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

          var btnSave = qs('#btnSaveAll');
          var info = qs('#dirtyInfo');
          var payloadEl = qs('#bulkPayload');

          function countDirty(){
            return qsa('tr.unmatched-row[data-dirty="1"]').length;
          }

          function updateState(){
            var c = countDirty();
            if (c > 0) {
              btnSave.disabled = false;
              btnSave.style.opacity = '1';
              info.textContent = 'Değişiklik: ' + c + ' satır';
            } else {
              btnSave.disabled = true;
              btnSave.style.opacity = '.6';
              info.textContent = 'Değişiklik yok';
            }
          }

          qsa('.unmatched-input').forEach(function(el){
            el.addEventListener('input', function(){
              var tr = el.closest('tr.unmatched-row');
              if (!tr) return;
              tr.dataset.dirty = "1";
              tr.style.outline = '2px solid rgba(239,68,68,.22)';
              tr.style.outlineOffset = '-2px';
              updateState();
            });
          });

          btnSave.addEventListener('click', function(){
            var rows = qsa('tr.unmatched-row[data-dirty="1"]');
            var items = rows.map(function(tr){
              var id = tr.getAttribute('data-rowid') || '';
              var obj = { id: id };
              qsa('.unmatched-input', tr).forEach(function(inp){
                var k = inp.getAttribute('data-field');
                obj[k] = inp.value;
              });
              return obj;
            });
            payloadEl.value = JSON.stringify(items);
            qs('#bulkSaveForm').submit();
          });

          updateState();
        })();
        </script>
      <?php endif; ?>
      <div style="overflow:auto;">
        <table class="excel-table copyable-table" style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead style="background:rgba(15,23,42,.04);">
            <?php if ($allowedTab === 'havuz'): ?>
              <tr>
                <th style="text-align:left; padding:10px 10px;">Id</th>
                <th style="text-align:left; padding:10px 10px;">T.C / V.N.</th>
                <th style="text-align:left; padding:10px 10px;">Sigortalı</th>
                <th style="text-align:left; padding:10px 10px;">Plaka</th>
                <th style="text-align:left; padding:10px 10px;">Şirket</th>
                <th style="text-align:left; padding:10px 10px;">Branş</th>
                <th style="text-align:left; padding:10px 10px;">Tip</th>
                <th style="text-align:left; padding:10px 10px;">Tanzim</th>
                <th style="text-align:left; padding:10px 10px;">Poliçe No</th>
                <th style="text-align:right; padding:10px 10px;">Brüt Prim</th>
              </tr>
            <?php elseif ($allowedTab === 'ana_csv'): ?>
              <tr>
                <th style="text-align:left; padding:10px 10px;">Id</th>
                                <th style="text-align:left; padding:10px 10px;">Sigortalı</th>
                <th style="text-align:left; padding:10px 10px;">Sig. Kimlik No</th>
                <th style="text-align:left; padding:10px 10px;">Şirket</th>
                <th style="text-align:left; padding:10px 10px;">Ürün</th>
                <th style="text-align:left; padding:10px 10px;">Zeyil Türü</th>
                <th style="text-align:left; padding:10px 10px;">Tanzim Tarihi</th>
                <th style="text-align:left; padding:10px 10px;">Poliçe No</th>
                <th style="text-align:left; padding:10px 10px;">Plaka</th>
                <th style="text-align:right; padding:10px 10px;">Brüt</th>
                <th style="text-align:right; padding:10px 10px;">Net</th>
                <th style="text-align:right; padding:10px 10px;">Komisyon</th>
                <th style="text-align:right; padding:10px 10px;">Aracı Pay</th>
                <th style="text-align:left; padding:10px 10px;">Durum</th>
              </tr>                  <?php elseif ($allowedTab === 'eslesmeyen'): ?>
              <tr>
                <th style="text-align:left; padding:10px 10px;">Sigortalı</th>
                <th style="text-align:left; padding:10px 10px;">T.C/V.N</th>
                <th style="text-align:left; padding:10px 10px;">Poliçe No</th>
                <th style="text-align:right; padding:10px 10px;">Net Prim</th>
              </tr>
            <?php endif; ?>
          </thead>
          <tbody>
            <?php if ($allowedTab === 'itiraz'): ?>
              <tr><td style="padding:14px; opacity:.7;">İtiraz akışı bir sonraki adımda.</td></tr>
            <?php else: ?>
              <?php if (empty($rows)): ?>
                <tr><td colspan="<?= ($allowedTab==='eslesmeyen'?4:15) ?>" style="padding:14px; opacity:.7;">Kayıt yok.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <?php if ($allowedTab === 'havuz'): ?>
                    <tr>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['tali_acente_id'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['tc_vn'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['sigortali_adi'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['plaka'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['sigorta_sirketi'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['brans'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['txn_type'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['tanzim_tarihi'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['policy_no'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06); text-align:right;"><?= _money_span($r['brut_prim'] ?? '') ?></td>
                    </tr>
                  <?php elseif ($allowedTab === 'ana_csv'): ?>
                    <tr>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['id'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['sigortali_adi'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['sig_kimlik_no'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['sigorta_sirketi'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['urun'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['zeyil_turu'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['tanzim_tarihi'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['policy_no'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['plaka'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06); text-align:right;"><?= _money_span($r['brut_prim'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06); text-align:right;"><?= _money_span($r['net_prim'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06); text-align:right;"><?= _money_span($r['komisyon_tutari'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06); text-align:right;"><?= _money_span($r['araci_kom_payi'] ?? '') ?></td>
                      <td style="padding:10px 10px; border-bottom:1px solid rgba(15,23,42,.06);"><?= h($r['row_status'] ?? '') ?></td>
                    </tr>                  <?php elseif ($allowedTab === 'eslesmeyen'): ?>
                    <tr class="unmatched-row" data-rowid="<?= h($r['id'] ?? '') ?>" style="background:rgba(239,68,68,.08);">
                      <td style="padding:8px 10px; border-bottom:1px solid rgba(15,23,42,.06);">
                        <input class="cell-input unmatched-input" data-field="sigortali_adi" value="<?= h($r['sigortali_adi'] ?? '') ?>" />
                      </td>
                      <td style="padding:8px 10px; border-bottom:1px solid rgba(15,23,42,.06);">
                        <input class="cell-input unmatched-input" data-field="tc_vn" value="<?= h($r['tc_vn'] ?? ($r['sig_kimlik_no'] ?? '')) ?>" />
                      </td>
                      <td style="padding:8px 10px; border-bottom:1px solid rgba(15,23,42,.06);">
                        <input class="cell-input unmatched-input" data-field="policy_no" value="<?= h($r['policy_no'] ?? '') ?>" />
                      </td>
                      <td style="padding:8px 10px; border-bottom:1px solid rgba(15,23,42,.06); text-align:right;">
                        <input class="cell-input cell-input-right unmatched-input" data-field="net_prim" value="<?= h($r['net_prim'] ?? '') ?>" />
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endif; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>