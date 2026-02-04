<?php
// /platform/mutabakat/havuz.php
// Mutabakat V2 - Havuz ekrani (sekmeler + upload + eslestirme)

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_mutabakat_havuz_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

require_mutabakat_access();

$conn = db();
if (!$conn) { http_response_code(500); exit('DB yok'); }
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$agencyId = (int)($_SESSION['agency_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$isMain   = is_main_role();
$isTali   = is_tali_role();

if (!$agencyId) { http_response_code(403); exit('Agency yok'); }

// Main/Tali context
$mainAgencyId = $isMain ? $agencyId : get_main_agency_id($conn, $agencyId);
$taliAgencyId = $isTali ? $agencyId : 0;

// Tali workmode
$taliWorkMode = $isTali ? get_tali_work_mode($conn, $agencyId) : 'ticket';

/* =========================================================
   Tabs & yetki
========================================================= */
$tabsAll = [
    'havuz'      => ['label' => 'Havuz',         'main' => true,  'tali' => true],
    'eslesen'    => ['label' => 'Eslesen',       'main' => true,  'tali' => false],
    'eslesmeyen' => ['label' => 'Eslesmeyen',    'main' => true,  'tali' => false],
    'itiraz'     => ['label' => 'Itiraz',        'main' => true,  'tali' => true],
    'ana_csv'    => ['label' => 'Ana acente CSV','main' => true,  'tali' => false],
];

$requestedTab = strtolower(trim((string)($_GET['tab'] ?? 'havuz')));
$allowedTab = 'havuz';
if (isset($tabsAll[$requestedTab])) {
    $allowedTab = ($isMain && $tabsAll[$requestedTab]['main']) || ($isTali && $tabsAll[$requestedTab]['tali'])
        ? $requestedTab
        : 'havuz';
}

/* =========================================================
   Donem secimi
========================================================= */
$periodId = (int)($_GET['period_id'] ?? 0);
$periods  = [];

// Tum donemleri cek
$st = $conn->prepare("SELECT id, year, month, status FROM mutabakat_v2_periods WHERE ana_acente_id=? ORDER BY year DESC, month DESC");
if ($st) {
    $st->bind_param('i', $mainAgencyId);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($row = $res->fetch_assoc())) $periods[] = $row;
    $st->close();
}

// period_id yoksa: iceriginde bulundugumuz ayi otomatik sec (yoksa olustur)
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
                array_unshift($periods, ['id' => $periodId, 'year' => $cy, 'month' => $cm, 'status' => 'OPEN']);
            } elseif (!empty($periods)) {
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
   POST actions
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!$periodId) {
        $flashErr = 'Donem secmeden islem yapilamaz.';
    } else {

        /* ---------- Tali CSV Upload ---------- */
        if ($action === 'tali_csv_upload') {
            if (!$isTali || $taliWorkMode !== 'csv') {
                $flashErr = 'Yetkisiz islem.';
            } elseif (empty($_FILES['csv_file']['tmp_name'])) {
                $flashErr = 'CSV dosyasi secilmedi.';
            } else {
                $result = process_tali_csv_upload($conn, $_FILES['csv_file'], $periodId, $mainAgencyId, $taliAgencyId, $userId);
                if ($result['ok']) {
                    $flashOk = $result['message'];
                } else {
                    $flashErr = $result['message'];
                }
            }
        }

        /* ---------- Ana CSV Upload ---------- */
        if ($action === 'ana_csv_upload') {
            if (!$isMain) {
                $flashErr = 'Yetkisiz islem.';
            } elseif (empty($_FILES['csv_file']['tmp_name'])) {
                $flashErr = 'CSV dosyasi secilmedi.';
            } else {
                $result = process_ana_csv_upload($conn, $_FILES['csv_file'], $periodId, $mainAgencyId, $userId);
                if ($result['ok']) {
                    $flashOk = $result['message'];
                } else {
                    $flashErr = $result['message'];
                }
            }
        }

        /* ---------- Bulk save unmatched ---------- */
        
        /* ---------- Bulk save unmatched ---------- */
        if ($action === 'bulk_save_unmatched') {
            if (!$isMain) {
                $flashErr = 'Yetkisiz islem.';
            } else {
                $payload = (string)($_POST['payload'] ?? '[]');
                $items = json_decode($payload, true);
                if (!is_array($items)) $items = [];

                // Hard limits (performance + safety)
                if (count($items) > 500) {
                    $items = array_slice($items, 0, 500);
                }

                $saved = 0;
                $skipped = 0;

                foreach ($items as $it) {
                    $rowId = (int)($it['id'] ?? 0);
                    if ($rowId <= 0) { $skipped++; continue; }

                    $sigortali  = trim((string)($it['sigortali_adi'] ?? ''));
                    $tcVn       = trim((string)($it['tc_vn'] ?? ''));
                    $policyNoRaw = trim((string)($it['policy_no'] ?? ''));
                    $policyNo   = _policy_clean($policyNoRaw) ?? $policyNoRaw;
                    $policyNorm = _policy_norm($policyNo);
                    $netPrim    = _to_decimal((string)($it['net_prim'] ?? ''));

                    // Required fields: sigortali, tc_vn, policy_no, net_prim
                    if ($sigortali === '' || $tcVn === '' || trim((string)$policyNo) === '' || $netPrim === null) {
                        $skipped++;
                        continue;
                    }

                    $st = $conn->prepare("UPDATE mutabakat_v2_rows
                        SET sigortali_adi=?, tc_vn=?, policy_no=?, policy_no_norm=?, net_prim=?, updated_at=NOW()
                        WHERE id=? AND period_id=? AND ana_acente_id=? AND source_type='ANA_CSV' AND row_status='ESLESMEYEN'");
                    if ($st) {
                        $st->bind_param('ssssdiii', $sigortali, $tcVn, $policyNo, $policyNorm, $netPrim, $rowId, $periodId, $mainAgencyId);
                        if ($st->execute() && $st->affected_rows > 0) { $saved++; }
                        $st->close();
                    }
                }

                $flashOk = "Kaydedildi: {$saved} satir. Atlama: {$skipped}.";
            }
        }

if ($action === 'run_match') {
            if (!$isMain) {
                $flashErr = 'Yetkisiz islem.';
            } else {
                $result = run_matching($conn, $periodId, $mainAgencyId);
                if ($result['ok']) {
                    $flashOk = $result['message'];
                    $allowedTab = 'eslesen';
                } else {
                    $flashErr = $result['message'];
                }
            }
        }
    }
}

/* =========================================================
   CSV Upload Functions
========================================================= */
function process_tali_csv_upload(mysqli $conn, array $file, int $periodId, int $mainAgencyId, int $taliAgencyId, int $userId): array {
    $tmp = $file['tmp_name'];
    $origName = (string)($file['name'] ?? 'tali.csv');
    // Deterministic hash for idempotent uploads
    $fileHash = @hash_file('sha1', $tmp) ?: null;

    $conn->begin_transaction();
    try {
        // Idempotency: same file (hash) for same period/agency/source should not create duplicates
        if (!empty($fileHash)) {
            $srcTypeTmp = 'TALI_CSV';
            $stC = $conn->prepare("SELECT id, total_rows, ok_rows, error_rows FROM mutabakat_v2_import_batches WHERE period_id=? AND ana_acente_id=? AND tali_acente_id=? AND source_type=? AND file_hash=? LIMIT 1");
            $stC->bind_param('iiiss', $periodId, $mainAgencyId, $taliAgencyId, $srcTypeTmp, $fileHash);
            $stC->execute();
            $stC->store_result();
            $prevId = $prevTotal = $prevOk = $prevErr = 0;
            $stC->bind_result($prevId, $prevTotal, $prevOk, $prevErr);
            if ($stC->num_rows > 0 && $stC->fetch()) {
                $stC->close();
                $conn->rollback();
                return ['ok' => true, 'message' => "Bu dosya daha once yuklenmis. Batch #{$prevId} | Toplam: {$prevTotal}, Basarili: {$prevOk}, Hatali: {$prevErr}"];
            }
            $stC->close();
        }

        // Batch olustur
        $st = $conn->prepare("INSERT INTO mutabakat_v2_import_batches (period_id, ana_acente_id, tali_acente_id, source_type, filename, file_hash, total_rows, ok_rows, error_rows, created_by, created_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
        $srcType = 'TALI_CSV';
        $total = 0; $ok = 0; $err = 0;
        $st->bind_param('iiisssiiii', $periodId, $mainAgencyId, $taliAgencyId, $srcType, $origName, $fileHash, $total, $ok, $err, $userId);
        $st->execute();
        $batchId = (int)$st->insert_id;
        $st->close();

        $fh = fopen($tmp, 'r');
        if (!$fh) throw new Exception('Dosya okunamadi.');

        $firstLine = fgets($fh);
        if ($firstLine === false) throw new Exception('Bos dosya.');
        $delim = _guess_delim($firstLine);
        rewind($fh);

        $headerRaw = fgetcsv($fh, 0, $delim);
        if (!$headerRaw) throw new Exception('Baslik satiri okunamadi.');
        $headerRaw = _csv_fix_row($headerRaw, $delim);
        
        $headers_mapped = [];
        $headers_set = [];
        foreach ($headerRaw as $i => $h) {
            $k = _norm_header((string)$h);
            $headers_mapped[$i] = $k;
            $headers_set[$k] = true;
        }

        // Zorunlu baslıklar
        $need = ['tc_vn', 'sigortali', 'plaka', 'sirket', 'brans', 'tip', 'tanzim', 'police_no', 'brut_prim'];
        // tanzim yerine tanzim_tarihi de kabul et
        if (!isset($headers_set['tanzim']) && isset($headers_set['tanzim_tarihi'])) {
            $headers_set['tanzim'] = true;
            foreach ($headers_mapped as $i => $k) {
                if ($k === 'tanzim_tarihi') $headers_mapped[$i] = 'tanzim';
            }
        }
        
        $missing = [];
        foreach ($need as $n) {
            if (!isset($headers_set[$n])) $missing[] = $n;
        }

        if ($missing) {
            @error_log('[MUTABAKAT][TALI_CSV] headerRaw=' . json_encode($headerRaw, JSON_UNESCAPED_UNICODE));
            @error_log('[MUTABAKAT][TALI_CSV] headersMapped=' . json_encode(array_values($headers_mapped), JSON_UNESCAPED_UNICODE));
            throw new Exception('Eksik kolonlar: ' . implode(', ', $missing));
        }

        // Index lookup
        $idx = [];
        foreach ($headers_mapped as $i => $k) {
            if (!isset($idx[$k]) && $k !== '') $idx[$k] = $i;
        }

        $rowNo = 1;
        while (($row = fgetcsv($fh, 0, $delim)) !== false) {
            $rawLine = is_array($row) ? implode($delim, $row) : (string)$row;
            $row = _csv_fix_row($row, $delim);
            $rowNo++;
            if (count(array_filter($row, fn($x) => trim((string)$x) !== '')) === 0) continue;
            $total++;

            $tc_vn     = trim((string)($row[$idx['tc_vn']] ?? ''));
            $sigortali = trim((string)($row[$idx['sigortali']] ?? ''));
            $plaka     = trim((string)($row[$idx['plaka']] ?? ''));
            $sirket    = trim((string)($row[$idx['sirket']] ?? ''));
            $brans     = trim((string)($row[$idx['brans']] ?? ''));
            $tipRaw    = trim((string)($row[$idx['tip']] ?? ''));
            $tanzim    = _parse_date_any((string)($row[$idx['tanzim']] ?? ''));
            $police    = _policy_clean(trim((string)($row[$idx['police_no']] ?? '')));
            $brutStr   = _to_decimal((string)($row[$idx['brut_prim']] ?? ''));

            if ($police === '' || $police === null || $brutStr === null) {
                $err++;
                $code = 'VALIDATION';
                $msg = 'Police No / Brut Prim zorunlu.';
                $stE = $conn->prepare("INSERT INTO mutabakat_v2_import_errors (batch_id, row_no, error_code, error_message, raw_line, created_at) VALUES (?,?,?,?,?,NOW())");
                $rawLineJson = json_encode($row, JSON_UNESCAPED_UNICODE);
                $stE->bind_param('iisss', $batchId, $rowNo, $code, $msg, $rawLineJson);
                $stE->execute();
                $stE->close();
                continue;
            }

            $txnType = _map_tali_txn_type($tipRaw, $brutStr);
            $policyNorm = _policy_norm($police);
            $rowStatus = 'HAVUZ';
            $currency = 'TRY';
            $locked = 0;

            $stR = $conn->prepare("INSERT INTO mutabakat_v2_rows
                (period_id, ana_acente_id, tali_acente_id, tc_vn, source_type, import_batch_id, policy_no, policy_no_norm, txn_type, sigortali_adi, tanzim_tarihi, sigorta_sirketi, brans, plaka, brut_prim, currency, row_status, locked, created_by, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
                ON DUPLICATE KEY UPDATE
                    tc_vn=VALUES(tc_vn),
                    import_batch_id=VALUES(import_batch_id),
                    sigortali_adi=VALUES(sigortali_adi),
                    tanzim_tarihi=VALUES(tanzim_tarihi),
                    sigorta_sirketi=VALUES(sigorta_sirketi),
                    brans=VALUES(brans),
                    plaka=VALUES(plaka),
                    brut_prim=VALUES(brut_prim),
                    currency=VALUES(currency),
                    updated_at=NOW()");

            $stR->bind_param(
                'iiississsssssssssii',
                $periodId, $mainAgencyId, $taliAgencyId, $tc_vn, $srcType,
                $batchId, $police, $policyNorm, $txnType,
                $sigortali, $tanzim, $sirket, $brans, $plaka, $brutStr,
                $currency, $rowStatus, $locked, $userId
            );

            if (!$stR->execute()) {
                $errMsg = $stR->error ?: 'DB insert failed';
                error_log('TALI_CSV insert error: ' . $errMsg . ' | raw=' . $rawLine);
                $err++;
                $stR->close();
                $code = 'DB_INSERT';
                $stE = $conn->prepare("INSERT INTO mutabakat_v2_import_errors (batch_id, row_no, error_code, error_message, raw_line, created_at) VALUES (?,?,?,?,?,NOW())");
                $stE->bind_param('iisss', $batchId, $rowNo, $code, $errMsg, $rawLine);
                $stE->execute();
                $stE->close();
                continue;
            }
            $stR->close();
            $ok++;
        }
        fclose($fh);

        // Batch sayaclarını guncelle
        $stU = $conn->prepare("UPDATE mutabakat_v2_import_batches SET total_rows=?, ok_rows=?, error_rows=? WHERE id=?");
        $stU->bind_param('iiii', $total, $ok, $err, $batchId);
        $stU->execute();
        $stU->close();

        $conn->commit();
        return ['ok' => true, 'message' => "Tali CSV yuklendi. Toplam: {$total}, Basarili: {$ok}, Hatali: {$err}"];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => "Tali CSV yukleme hatasi: " . $e->getMessage()];
    }
}

function process_ana_csv_upload(mysqli $conn, array $file, int $periodId, int $mainAgencyId, int $userId): array {
    $tmp = $file['tmp_name'];
    $origName = (string)($file['name'] ?? 'ana.csv');
    // Deterministic hash for idempotent uploads
    $fileHash = @hash_file('sha1', $tmp) ?: null;

    $conn->begin_transaction();
    try {
        // Idempotency: same file (hash) for same period/main agency should not create duplicates
        if (!empty($fileHash)) {
            $srcTypeTmp = 'ANA_CSV';
            $tali0tmp = 0;
            $stC = $conn->prepare("SELECT id, total_rows, ok_rows, error_rows FROM mutabakat_v2_import_batches WHERE period_id=? AND ana_acente_id=? AND tali_acente_id=? AND source_type=? AND file_hash=? LIMIT 1");
            $stC->bind_param('iiiss', $periodId, $mainAgencyId, $tali0tmp, $srcTypeTmp, $fileHash);
            $stC->execute();
            $stC->store_result();
            $prevId = $prevTotal = $prevOk = $prevErr = 0;
            $stC->bind_result($prevId, $prevTotal, $prevOk, $prevErr);
            if ($stC->num_rows > 0 && $stC->fetch()) {
                $stC->close();
                $conn->rollback();
                return ['ok' => true, 'message' => "Bu dosya daha once yuklenmis. Batch #{$prevId} | Toplam: {$prevTotal}, Basarili: {$prevOk}, Hatali: {$prevErr}"];
            }
            $stC->close();
        }

        $st = $conn->prepare("INSERT INTO mutabakat_v2_import_batches (period_id, ana_acente_id, tali_acente_id, source_type, filename, file_hash, total_rows, ok_rows, error_rows, created_by, created_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
        $srcType = 'ANA_CSV';
        $tali0 = 0;
        $total = 0; $ok = 0; $err = 0;
        $st->bind_param('iiisssiiii', $periodId, $mainAgencyId, $tali0, $srcType, $origName, $fileHash, $total, $ok, $err, $userId);
        $st->execute();
        $batchId = (int)$st->insert_id;
        $st->close();

        $fh = fopen($tmp, 'r');
        if (!$fh) throw new Exception('Dosya okunamadi.');

        $firstLine = fgets($fh);
        if ($firstLine === false) throw new Exception('Bos dosya.');
        $delim = _guess_delim($firstLine);
        rewind($fh);

        $headerRaw = fgetcsv($fh, 0, $delim);
        if (!$headerRaw) throw new Exception('Baslik satiri okunamadi.');
        $headerRaw = _csv_fix_row($headerRaw, $delim);
        
        $headers_mapped = [];
        $headers_set = [];
        foreach ($headerRaw as $i => $h) {
            $k = _norm_header((string)$h);
            $headers_mapped[$i] = $k;
            $headers_set[$k] = true;
        }

        $need = ['tanzim_tarihi', 'bitis_tarihi', 'sigortali', 'sig_kimlik_no', 'sirket', 'urun', 'zeyil_turu', 'police_no', 'plaka', 'brut_prim', 'net_prim', 'komisyon_tutari', 'araci_kom_payi'];
        $missing = [];
        foreach ($need as $n) {
            if (!isset($headers_set[$n])) $missing[] = $n;
        }

        if ($missing) {
            @error_log('[MUTABAKAT][ANA_CSV] headerRaw=' . json_encode($headerRaw, JSON_UNESCAPED_UNICODE));
            @error_log('[MUTABAKAT][ANA_CSV] headersMapped=' . json_encode(array_values($headers_mapped), JSON_UNESCAPED_UNICODE));
            throw new Exception('Eksik kolonlar: ' . implode(', ', $missing));
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
            if (count(array_filter($row, fn($x) => trim((string)$x) !== '')) === 0) continue;
            $total++;

            $tanzim    = _parse_date_any((string)($row[$idx['tanzim_tarihi']] ?? ''));
            $bitis     = _parse_date_any((string)($row[$idx['bitis_tarihi']] ?? ''));
            $sigortali = trim((string)($row[$idx['sigortali']] ?? ''));
            $sigNo     = trim((string)($row[$idx['sig_kimlik_no']] ?? ''));
            $sirket    = trim((string)($row[$idx['sirket']] ?? ''));
            $urun      = trim((string)($row[$idx['urun']] ?? ''));
            $zeyil     = trim((string)($row[$idx['zeyil_turu']] ?? ''));
            $police    = _policy_clean(trim((string)($row[$idx['police_no']] ?? '')));
            $plaka     = trim((string)($row[$idx['plaka']] ?? ''));
            $brutStr   = _to_decimal((string)($row[$idx['brut_prim']] ?? ''));
            $netStr    = _to_decimal((string)($row[$idx['net_prim']] ?? ''));
            $komStr    = _to_decimal((string)($row[$idx['komisyon_tutari']] ?? ''));
            $araciStr  = _to_decimal((string)($row[$idx['araci_kom_payi']] ?? ''));

            if ($police === '' || $police === null || $brutStr === null) {
                $err++;
                $code = 'VALIDATION';
                $msg = 'Police No / Brut Prim zorunlu.';
                $rawLineJson = json_encode($row, JSON_UNESCAPED_UNICODE);
                $stE = $conn->prepare("INSERT INTO mutabakat_v2_import_errors (batch_id, row_no, error_code, error_message, raw_line, created_at) VALUES (?,?,?,?,?,NOW())");
                $stE->bind_param('iisss', $batchId, $rowNo, $code, $msg, $rawLineJson);
                $stE->execute();
                $stE->close();
                continue;
            }

            $txnType = _map_ana_txn_type($zeyil);
            $policyNorm = _policy_norm($police);
            $rowStatus = 'HAVUZ';
            $currency = 'TRY';
            $locked = 0;

            $stR = $conn->prepare("INSERT INTO mutabakat_v2_rows
                (period_id, ana_acente_id, tali_acente_id, source_type, import_batch_id, policy_no, policy_no_norm, txn_type, zeyil_turu, sigortali_adi, sig_kimlik_no, tanzim_tarihi, bitis_tarihi, sigorta_sirketi, urun, plaka, brut_prim, net_prim, komisyon_tutari, araci_kom_payi, currency, row_status, locked, created_by, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
                ON DUPLICATE KEY UPDATE
                    import_batch_id=VALUES(import_batch_id),
                    txn_type=VALUES(txn_type),
                    zeyil_turu=VALUES(zeyil_turu),
                    sigortali_adi=VALUES(sigortali_adi),
                    sig_kimlik_no=VALUES(sig_kimlik_no),
                    tanzim_tarihi=VALUES(tanzim_tarihi),
                    bitis_tarihi=VALUES(bitis_tarihi),
                    sigorta_sirketi=VALUES(sigorta_sirketi),
                    urun=VALUES(urun),
                    plaka=VALUES(plaka),
                    brut_prim=VALUES(brut_prim),
                    net_prim=VALUES(net_prim),
                    komisyon_tutari=VALUES(komisyon_tutari),
                    araci_kom_payi=VALUES(araci_kom_payi),
                    currency=VALUES(currency),
                    updated_at=NOW()");

            $stR->bind_param(
                'iiisisssssssssssssssssii',
                $periodId, $mainAgencyId, $tali0, $srcType, $batchId, $police, $policyNorm, $txnType, $zeyil,
                $sigortali, $sigNo, $tanzim, $bitis, $sirket, $urun, $plaka,
                $brutStr, $netStr, $komStr, $araciStr, $currency, $rowStatus, $locked, $userId
            );

            if (!$stR->execute()) {
                $errMsg = $stR->error ?: 'DB insert failed';
                error_log('ANA_CSV insert error: ' . $errMsg . ' | raw=' . $rawLine);
                $err++;
                $stR->close();
                $code = 'DB_INSERT';
                $stE = $conn->prepare("INSERT INTO mutabakat_v2_import_errors (batch_id, row_no, error_code, error_message, raw_line, created_at) VALUES (?,?,?,?,?,NOW())");
                $stE->bind_param('iisss', $batchId, $rowNo, $code, $errMsg, $rawLine);
                $stE->execute();
                $stE->close();
                continue;
            }
            $stR->close();
            $ok++;
        }
        fclose($fh);

        $stU = $conn->prepare("UPDATE mutabakat_v2_import_batches SET total_rows=?, ok_rows=?, error_rows=? WHERE id=?");
        $stU->bind_param('iiii', $total, $ok, $err, $batchId);
        $stU->execute();
        $stU->close();

        $conn->commit();
        return ['ok' => true, 'message' => "Ana acente CSV yuklendi. Toplam: {$total}, Basarili: {$ok}, Hatali: {$err}"];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => "Ana CSV yukleme hatasi: " . $e->getMessage()];
    }
}

function run_matching(mysqli $conn, int $periodId, int $mainAgencyId): array {
    $conn->begin_transaction();
    try {
        // Ana satirlar
        $anaRows = [];
        $st = $conn->prepare("SELECT id, policy_no, policy_no_norm, tc_vn, sig_kimlik_no, sigortali_adi FROM mutabakat_v2_rows WHERE period_id=? AND ana_acente_id=? AND source_type='ANA_CSV'");
        $st->bind_param('ii', $periodId, $mainAgencyId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) $anaRows[] = $r;
        $st->close();

        // Havuz satirlari (TALI_CSV + TICKET) - policy_no_norm ile lookup
        $pool = [];
        $st = $conn->prepare("SELECT id, policy_no, policy_no_norm, tc_vn, sig_kimlik_no, sigortali_adi FROM mutabakat_v2_rows WHERE period_id=? AND ana_acente_id=? AND source_type IN ('TICKET','TALI_CSV') AND row_status='HAVUZ'");
        $st->bind_param('ii', $periodId, $mainAgencyId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $pnorm = (string)($r['policy_no_norm'] ?? '');
            if ($pnorm === '') $pnorm = _policy_norm($r['policy_no']);
            if ($pnorm === '') continue;
            if (!isset($pool[$pnorm])) $pool[$pnorm] = [];
            $pool[$pnorm][] = $r; // keep full row for tc/name comparison
        }
        $st->close();

        $matched = 0;
        $unmatched = 0;

        foreach ($anaRows as $ar) {
            $anaId = (int)$ar['id'];
            $pnorm = (string)($ar['policy_no_norm'] ?? '');
            if ($pnorm === '') $pnorm = _policy_norm($ar['policy_no']);
            
            $taliId = 0;

            if ($pnorm !== '' && isset($pool[$pnorm]) && !empty($pool[$pnorm])) {
                $taliRow = array_shift($pool[$pnorm]);
                $taliId = (int)($taliRow['id'] ?? 0);

                // --- Extra validation: policy matched, now validate TC and Name ---
                $anaTc = trim((string)($ar['sig_kimlik_no'] ?? ''));
                if ($anaTc === '') $anaTc = trim((string)($ar['tc_vn'] ?? ''));
                $taliTc = trim((string)($taliRow['tc_vn'] ?? ''));
                if ($taliTc === '') $taliTc = trim((string)($taliRow['sig_kimlik_no'] ?? ''));

                $anaName = trim((string)($ar['sigortali_adi'] ?? ''));
                $taliName = trim((string)($taliRow['sigortali_adi'] ?? ''));

                $norm_tr = function(string $s): string {
                    $s = trim($s);
                    $map = ['ç'=>'c','Ç'=>'C','ğ'=>'g','Ğ'=>'G','ı'=>'i','İ'=>'I','ö'=>'o','Ö'=>'O','ş'=>'s','Ş'=>'S','ü'=>'u','Ü'=>'U'];
                    $s = strtr($s, $map);
                    $s = preg_replace('/\s+/u', ' ', $s);
                    return mb_strtoupper($s, 'UTF-8');
                };
                $norm_tc = function(string $s) use ($norm_tr): string {
                    $s = $norm_tr($s);
                    $s = preg_replace('/[^A-Z0-9]/', '', $s);
                    return $s;
                };

                $tcOk = ($norm_tc($anaTc) !== '' && $norm_tc($anaTc) === $norm_tc($taliTc));
                $nameOk = ($norm_tr($anaName) !== '' && $norm_tr($anaName) === $norm_tr($taliName));

                if ($tcOk && $nameOk) {
                    // ✅ Full match
                    $status = 'MATCHED';
                    $mismatch = '';
                    $stM = $conn->prepare("INSERT INTO mutabakat_v2_matches (period_id, policy_no, tali_row_id, ana_row_id, status, mismatch_summary, created_at)
                                           VALUES (?,?,?,?,?,?,NOW())
                                           ON DUPLICATE KEY UPDATE status=VALUES(status), mismatch_summary=VALUES(mismatch_summary)");
                    $stM->bind_param('isiiss', $periodId, $pnorm, $taliId, $anaId, $status, $mismatch);
                    $stM->execute();
                    $stM->close();

                    $stU = $conn->prepare("UPDATE mutabakat_v2_rows SET row_status='ESLESEN', updated_at=NOW() WHERE id IN (?,?)");
                    $stU->bind_param('ii', $taliId, $anaId);
                    $stU->execute();
                    $stU->close();

                    $matched++;
                } else {
                    // ❌ Policy matched but TC/Name mismatch -> keep in ESLESMEYEN and mark mismatch columns
                    $status = 'MISMATCH';
                    $cols = [];
                    if (!$tcOk) $cols[] = 'tc';
                    if (!$nameOk) $cols[] = 'name';
                    $mismatch = implode(',', $cols);

                    $stM = $conn->prepare("INSERT INTO mutabakat_v2_matches (period_id, policy_no, tali_row_id, ana_row_id, status, mismatch_summary, created_at)
                                           VALUES (?,?,?,?,?,?,NOW())
                                           ON DUPLICATE KEY UPDATE status=VALUES(status), mismatch_summary=VALUES(mismatch_summary)");
                    $stM->bind_param('isiiss', $periodId, $pnorm, $taliId, $anaId, $status, $mismatch);
                    $stM->execute();
                    $stM->close();

                    $stU = $conn->prepare("UPDATE mutabakat_v2_rows SET row_status='ESLESMEYEN', updated_at=NOW() WHERE id=?");
                    $stU->bind_param('i', $anaId);
                    $stU->execute();
                    $stU->close();

                    $unmatched++;
                }
            } else {
                // Eslesmeyen
                $stU = $conn->prepare("UPDATE mutabakat_v2_rows SET row_status='ESLESMEYEN', updated_at=NOW() WHERE id=?");
                $stU->bind_param('i', $anaId);
                $stU->execute();
                $stU->close();
                $unmatched++;
            }
        }

        $conn->commit();
        return ['ok' => true, 'message' => "Eslestirme tamamlandi. Eslesen: {$matched}, Eslesmeyen: {$unmatched}"];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => "Eslestirme hatasi: " . $e->getMessage()];
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
            $st = $conn->prepare("SELECT id, tali_acente_id, tc_vn, sigortali_adi, plaka, sigorta_sirketi, brans, txn_type, tanzim_tarihi, policy_no, brut_prim, source_type
                                  FROM mutabakat_v2_rows
                                  WHERE period_id=? AND ana_acente_id=? AND source_type IN ('TICKET','TALI_CSV') AND row_status='HAVUZ'
                                  ORDER BY id DESC LIMIT 500");
            $st->bind_param('ii', $periodId, $mainAgencyId);
        } else {
            $st = $conn->prepare("SELECT id, tali_acente_id, tc_vn, sigortali_adi, plaka, sigorta_sirketi, brans, txn_type, tanzim_tarihi, policy_no, brut_prim, source_type
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
                                    m.id AS match_id,
                                    t.id AS tali_row_id,
                                    t.sigortali_adi AS tali_sigortali,
                                    COALESCE(t.tc_vn, t.sig_kimlik_no) AS tali_tc_vn,
                                    t.plaka AS tali_plaka,
                                    t.sigorta_sirketi AS tali_sirket,
                                    t.brans AS tali_brans,
                                    t.txn_type AS tali_tip,
                                    t.tanzim_tarihi AS tali_tanzim,
                                    t.brut_prim AS tali_brut,
                                    m.policy_no,
                                    a.araci_kom_payi,
                                    a.id AS ana_row_id
                              FROM mutabakat_v2_matches m
                              JOIN mutabakat_v2_rows t ON t.id=m.tali_row_id
                              JOIN mutabakat_v2_rows a ON a.id=m.ana_row_id
                              WHERE m.period_id=? AND m.status='MATCHED'
                              ORDER BY m.id DESC LIMIT 500");
        $st->bind_param('ii', $periodId, $mainAgencyId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) $matches[] = $r;
        $st->close();
    }

    if ($allowedTab === 'eslesmeyen' && $isMain) {
        $st = $conn->prepare("SELECT r.id, r.sigortali_adi, r.tc_vn, r.sig_kimlik_no, r.policy_no, r.net_prim, r.row_status, mm.mismatch_summary
                              FROM mutabakat_v2_rows r
                              LEFT JOIN mutabakat_v2_matches mm
                                ON mm.period_id = r.period_id AND mm.ana_row_id = r.id AND mm.status='MISMATCH'
                              WHERE period_id=? AND ana_acente_id=? AND source_type='ANA_CSV' AND row_status='ESLESMEYEN'
                              ORDER BY id DESC LIMIT 500");
        $st->bind_param('ii', $periodId, $mainAgencyId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        $st->close();
    }

    if ($allowedTab === 'itiraz') {
        // Itiraz satirlari
        $st = $conn->prepare("SELECT r.id, r.sigortali_adi, r.tc_vn, r.policy_no, r.brut_prim, r.source_type, d.description, d.status AS dispute_status
                              FROM mutabakat_v2_rows r
                              LEFT JOIN mutabakat_v2_disputes d ON d.row_id = r.id
                              WHERE r.period_id=? AND r.ana_acente_id=? AND r.row_status='ITIRAZ'
                              ORDER BY r.id DESC LIMIT 500");
        if ($st) {
            $st->bind_param('ii', $periodId, $mainAgencyId);
            $st->execute();
            $res = $st->get_result();
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $st->close();
        }
    }
}

// Tab counts
$tabCounts = ['havuz' => 0, 'eslesen' => 0, 'eslesmeyen' => 0, 'itiraz' => 0, 'ana_csv' => 0];
if ($periodId && $isMain) {
    // Havuz count
    $st = $conn->prepare("SELECT COUNT(*) FROM mutabakat_v2_rows WHERE period_id=? AND ana_acente_id=? AND source_type IN ('TICKET','TALI_CSV') AND row_status='HAVUZ'");
    $st->bind_param('ii', $periodId, $mainAgencyId);
    $st->execute();
    $st->bind_result($tabCounts['havuz']);
    $st->fetch();
    $st->close();

    // Eslesen count
    $st = $conn->prepare("SELECT COUNT(*)
                     FROM mutabakat_v2_matches m
                     JOIN mutabakat_v2_rows a ON a.id = m.ana_row_id
                     WHERE m.period_id=? AND m.status='MATCHED' AND a.ana_acente_id=?");
    $st->bind_param('ii', $periodId, $mainAgencyId);
    $st->execute();
    $st->bind_result($tabCounts['eslesen']);
    $st->fetch();
    $st->close();

    // Eslesmeyen count
    $st = $conn->prepare("SELECT COUNT(*) FROM mutabakat_v2_rows WHERE period_id=? AND ana_acente_id=? AND source_type='ANA_CSV' AND row_status='ESLESMEYEN'");
    $st->bind_param('ii', $periodId, $mainAgencyId);
    $st->execute();
    $st->bind_result($tabCounts['eslesmeyen']);
    $st->fetch();
    $st->close();

    
    // Itiraz count
    $st = $conn->prepare("SELECT COUNT(*) FROM mutabakat_v2_rows WHERE period_id=? AND ana_acente_id=? AND row_status='ITIRAZ'");
    $st->bind_param('ii', $periodId, $mainAgencyId);
    $st->execute();
    $st->bind_result($tabCounts['itiraz']);
    $st->fetch();
    $st->close();

// Ana CSV count
    $st = $conn->prepare("SELECT COUNT(*) FROM mutabakat_v2_rows WHERE period_id=? AND ana_acente_id=? AND source_type='ANA_CSV'");
    $st->bind_param('ii', $periodId, $mainAgencyId);
    $st->execute();
    $st->bind_result($tabCounts['ana_csv']);
    $st->fetch();
    $st->close();
}

$pageTitle = 'Mutabakat - Havuz';
$currentPage = 'havuz';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="container">
  <!-- Baslik ve Donem Secimi -->
  <div class="card">
    <div class="card-header">
      <div>
        <h1 class="card-title">Mutabakat - Havuz</h1>
        <div class="card-subtitle"><?= $isMain ? 'Ana acente gorunumu' : 'Tali acente gorunumu' ?></div>
      </div>

      <form method="get" class="form-inline">
        <input type="hidden" name="tab" value="<?= h($allowedTab) ?>">
        <label class="u-text-sm u-text-muted">Donem</label>
        <select name="period_id" onchange="this.form.submit()" class="form-control">
          <?php if (empty($periods)): ?>
            <option value="0">Donem yok</option>
          <?php else: ?>
            <?php foreach ($periods as $p):
              $pid = (int)$p['id'];
              $ym  = sprintf('%04d-%02d', (int)$p['year'], (int)$p['month']);
              $stt = (string)($p['status'] ?? '');
            ?>
              <option value="<?= $pid ?>" <?= $pid === $periodId ? 'selected' : '' ?>><?= h($ym) ?><?= $stt ? ' - ' . h($stt) : '' ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </form>
    </div>

    <?php if ($flashErr): ?>
      <div class="alert alert-error"><?= h($flashErr) ?></div>
    <?php endif; ?>
    <?php if ($flashOk): ?>
      <div class="alert alert-success"><?= h($flashOk) ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
      <?php foreach ($tabsAll as $k => $meta):
        $can = ($isMain && $meta['main']) || ($isTali && $meta['tali']);
        if (!$can) continue;
        $active = $allowedTab === $k;
        $count = $tabCounts[$k] ?? 0;
      ?>
        <a href="?tab=<?= h($k) ?>&period_id=<?= (int)$periodId ?>" class="tab <?= $active ? 'active' : '' ?>">
          <?= h($meta['label']) ?>
          <?php if ($count > 0): ?>
            <span class="badge"><?= $count ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Upload / Actions -->
  <?php if ($allowedTab === 'havuz' && $isTali && $taliWorkMode === 'csv'): ?>
    <div class="upload-box">
      <div class="upload-box-title">Tali CSV Yukle (workmode: csv)</div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="tali_csv_upload">
        <input type="file" name="csv_file" accept=".csv,text/csv" required class="form-control">
        <button type="submit" class="btn btn-primary">Yukle</button>
      </form>
      <div class="upload-box-hint">Basliklar: T.C/V.N., Sigortali, Plaka, Sirket, Brans, Tip, Tanzim, Police No, Brut Prim</div>
    </div>
  <?php endif; ?>

  <?php if ($allowedTab === 'ana_csv' && $isMain): ?>
    <div class="upload-box">
      <div>
        <div class="upload-box-title">Ana acente CSV Yukle</div>
        <div class="upload-box-hint">Yuklenen kayitlar sadece bu sekmede gorunur. Eslestir'e basinca Eslesen/Eslesmeyen'e ayrilir.</div>
      </div>
      <div>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="ana_csv_upload">
          <input type="file" name="csv_file" accept=".csv,text/csv" required class="form-control">
          <button type="submit" class="btn btn-primary">Yukle</button>
        </form>
        <form method="post">
          <input type="hidden" name="action" value="run_match">
          <button type="submit" class="btn btn-success">Eslestir</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- Tables -->
  <div class="card">
    <?php if ($allowedTab === 'eslesen' && $isMain): ?>
      <div>Eslesen Kayitlar</div>
      <div class="table-wrapper">
        <table class="excel-table copyable-table">
          <thead>
            <tr>
              <th>Police No</th>
              <th>Tali ID</th>
              <th>T.C/V.N</th>
              <th>Sigortali</th>
              <th>Plaka</th>
              <th>Sirket</th>
              <th>Brans</th>
              <th>Tip</th>
              <th>Tanzim</th>
              <th>Brut Prim</th>
              <th>Araci Pay</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($matches)): ?>
              <tr><td colspan="11">Kayit yok.</td></tr>
            <?php else: ?>
              <?php foreach ($matches as $m): ?>
                <tr>
                  <td><?= h($m['policy_no'] ?? '') ?></td>
                  <td><?= h($m['tali_row_id'] ?? '') ?></td>
                  <td><?= h($m['tali_tc_vn'] ?? '') ?></td>
                  <td><?= h($m['tali_sigortali'] ?? '') ?></td>
                  <td><?= h($m['tali_plaka'] ?? '') ?></td>
                  <td><?= h($m['tali_sirket'] ?? '') ?></td>
                  <td><?= h($m['tali_brans'] ?? '') ?></td>
                  <td><?= h($m['tali_tip'] ?? '') ?></td>
                  <td><?= h($m['tali_tanzim'] ?? '') ?></td>
                  <td><?= _money_span($m['tali_brut'] ?? '') ?></td>
                  <td><?= _money_span($m['araci_kom_payi'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($allowedTab === 'eslesmeyen' && $isMain): ?>
      
      <div class="card u-mt-3">
        <div class="card-header">
          <div>
            <div class="card-title">Eşleşmeyen Kayıtlar</div>
            <div class="card-subtitle">Ana acente bu alanı düzenleyebilir. Kaydet → ardından Eşleştir ile toplu eşleştirme tekrar çalışır.</div>
          </div>
          <div class="u-flex u-center u-gap-2">
            <form id="bulkSaveForm" method="post">
              <input type="hidden" name="action" value="bulk_save_unmatched">
              <input type="hidden" name="payload" id="bulkPayload" value="">
              <button type="button" id="btnSaveAll" class="btn btn-primary" disabled>Kaydet</button>
            </form>
            <form method="post">
              <input type="hidden" name="action" value="run_match">
              <button type="submit" class="btn btn-secondary">Eşleştir</button>
            </form>
          </div>
        </div>
        <div class="card-body">
          <div class="u-text-sm u-text-muted" id="dirtyInfo">Değişiklik yok</div>

          <div class="table-wrapper u-mt-3">

        <table class="excel-table copyable-table">
          <thead>
            <tr>
              <th>Sigortali</th>
              <th>T.C/V.N</th>
              <th>Police No</th>
              <th>Net Prim</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="4">Kayit yok.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $mm = (string)($r['mismatch_summary'] ?? '');
                  $mmTc = (stripos($mm,'tc') !== false);
                  $mmName = (stripos($mm,'name') !== false);

                  $vName = (string)($r['sigortali_adi'] ?? '');
                  $vTc   = (string)($r['tc_vn'] ?? ($r['sig_kimlik_no'] ?? ''));
                  $vPol  = (string)($r['policy_no'] ?? '');
                  $vNet  = (string)($r['net_prim'] ?? '');

                  $reqName = (trim($vName) === '');
                  $reqTc   = (trim($vTc) === '');
                  $reqPol  = (trim($vPol) === '');
                  $reqNet  = (trim($vNet) === '');
                ?>
                <tr class="unmatched-row" data-rowid="<?= h($r['id'] ?? '') ?>">
                  <td><input class="cell-input unmatched-input <?= $reqName ? 'mismatch' : '' ?> <?= $mmName ? 'mismatch-soft' : '' ?>" data-field="sigortali_adi" value="<?= h($vName) ?>" /><?php if($reqName): ?><span class="mismatch-badge req">Zorunlu</span><?php endif; ?><?php if($mmName): ?><span class="mismatch-badge name">İsim uyuşmuyor</span><?php endif; ?></td>
                  <td><input class="cell-input unmatched-input <?= $reqTc ? 'mismatch' : '' ?> <?= $mmTc ? 'mismatch-soft' : '' ?>" data-field="tc_vn" value="<?= h($vTc) ?>" /><?php if($reqTc): ?><span class="mismatch-badge req">Zorunlu</span><?php endif; ?><?php if($mmTc): ?><span class="mismatch-badge tc">TC/VN uyuşmuyor</span><?php endif; ?></td>
                  <td><input class="cell-input unmatched-input <?= $reqPol ? 'mismatch' : '' ?>" data-field="policy_no" value="<?= h($vPol) ?>" /><?php if($reqPol): ?><span class="mismatch-badge req">Zorunlu</span><?php endif; ?></td>
                  <td><input class="cell-input cell-input-right unmatched-input <?= $reqNet ? 'mismatch' : '' ?>" data-field="net_prim" value="<?= h($vNet) ?>" /><?php if($reqNet): ?><span class="mismatch-badge req">Zorunlu</span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
        </div>
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
            info.textContent = 'Degisiklik: ' + c + ' satir';
          } else {
            btnSave.disabled = true;
            btnSave.style.opacity = '.6';
            info.textContent = 'Degisiklik yok';
          }
        }

        qsa('.unmatched-input').forEach(function(el){
          el.addEventListener('input', function(){
            var tr = el.closest('tr.unmatched-row');
            if (!tr) return;
            tr.dataset.dirty = "1";
            updateState();
          });
        });

        btnSave.addEventListener('click', function(){
          var rows = qsa('tr.unmatched-row[data-dirty="1"]');

          // Client-side required validation
          var requiredFields = ['sigortali_adi','tc_vn','policy_no','net_prim'];
          var firstBad = null;
          rows.forEach(function(tr){
            requiredFields.forEach(function(f){
              var inp = qs('.unmatched-input[data-field="'+f+'"]', tr);
              if (!inp) return;
              var v = (inp.value || '').trim();
              if (v === '') {
                inp.classList.add('mismatch');
                if (!firstBad) firstBad = inp;
              }
            });
          });
          if (firstBad) {
            try {
              if (typeof window.showToast === 'function') window.showToast('error','Zorunlu alanlar bos olamaz.');
              else alert('Zorunlu alanlar bos olamaz.');
            } catch(_e) {}
            firstBad.focus();
            return;
          }

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

    <?php else: ?>
      <div>
        <?php if ($allowedTab === 'havuz'): ?>Havuz<?php endif; ?>
        <?php if ($allowedTab === 'ana_csv'): ?>Ana acente CSV<?php endif; ?>
        <?php if ($allowedTab === 'itiraz'): ?>Itiraz<?php endif; ?>
      </div>

      <div class="table-wrapper">
        <table class="excel-table copyable-table">
          <thead>
            <?php if ($allowedTab === 'havuz'): ?>
              <tr>
                <th>ID</th>
                <th>Kaynak</th>
                <th>T.C / V.N.</th>
                <th>Sigortali</th>
                <th>Plaka</th>
                <th>Sirket</th>
                <th>Brans</th>
                <th>Tip</th>
                <th>Tanzim</th>
                <th>Police No</th>
                <th>Brut Prim</th>
              </tr>
            <?php elseif ($allowedTab === 'ana_csv'): ?>
              <tr>
                <th>ID</th>
                <th>Sigortali</th>
                <th>Sig. Kimlik No</th>
                <th>Sirket</th>
                <th>Urun</th>
                <th>Zeyil Turu</th>
                <th>Tanzim Tarihi</th>
                <th>Police No</th>
                <th>Plaka</th>
                <th>Brut</th>
                <th>Net</th>
                <th>Komisyon</th>
                <th>Araci Pay</th>
                <th>Durum</th>
                <th>Aksiyon</th>
              </tr>
            <?php elseif ($allowedTab === 'itiraz'): ?>
              <tr>
                <th>ID</th>
                <th>Kaynak</th>
                <th>Sigortali</th>
                <th>T.C/V.N</th>
                <th>Police No</th>
                <th>Brut Prim</th>
                <th>Itiraz Aciklama</th>
                <th>Durum</th>
                <th>Aksiyon</th>
              </tr>
            <?php endif; ?>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="15">Kayit yok.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php if ($allowedTab === 'havuz'): ?>
                  <tr>
                    <td><?= h($r['id'] ?? '') ?></td>
                    <td><span class="badge-status badge-havuz"><?= h($r['source_type'] ?? '') ?></span></td>
                    <td><?= h($r['tc_vn'] ?? '') ?></td>
                    <td><?= h($r['sigortali_adi'] ?? '') ?></td>
                    <td><?= h($r['plaka'] ?? '') ?></td>
                    <td><?= h($r['sigorta_sirketi'] ?? '') ?></td>
                    <td><?= h($r['brans'] ?? '') ?></td>
                    <td><?= h($r['txn_type'] ?? '') ?></td>
                    <td><?= h($r['tanzim_tarihi'] ?? '') ?></td>
                    <td><?= h($r['policy_no'] ?? '') ?></td>
                    <td><?= _money_span($r['brut_prim'] ?? '') ?></td>
                  </tr>
                <?php elseif ($allowedTab === 'ana_csv'): ?>
                  <tr>
                    <td><?= h($r['id'] ?? '') ?></td>
                    <td><?= h($r['sigortali_adi'] ?? '') ?></td>
                    <td><?= h($r['sig_kimlik_no'] ?? '') ?></td>
                    <td><?= h($r['sigorta_sirketi'] ?? '') ?></td>
                    <td><?= h($r['urun'] ?? '') ?></td>
                    <td><?= h($r['zeyil_turu'] ?? '') ?></td>
                    <td><?= h($r['tanzim_tarihi'] ?? '') ?></td>
                    <td><?= h($r['policy_no'] ?? '') ?></td>
                    <td><?= h($r['plaka'] ?? '') ?></td>
                    <td><?= _money_span($r['brut_prim'] ?? '') ?></td>
                    <td><?= _money_span($r['net_prim'] ?? '') ?></td>
                    <td><?= _money_span($r['komisyon_tutari'] ?? '') ?></td>
                    <td><?= _money_span($r['araci_kom_payi'] ?? '') ?></td>
                    <td>
                      <?php
                        $statusClass = 'badge-havuz';
                        if (($r['row_status'] ?? '') === 'ESLESEN') $statusClass = 'badge-eslesen';
                        elseif (($r['row_status'] ?? '') === 'ESLESMEYEN') $statusClass = 'badge-eslesmeyen';
                      ?>
                      <span class="badge-status <?= $statusClass ?>"><?= h($r['row_status'] ?? '') ?></span>
                    </td>
                  </tr>
                <?php elseif ($allowedTab === 'itiraz'): ?>
                  <tr>
                    <td><?= h($r['id'] ?? '') ?></td>
                    <td><?= h($r['source_type'] ?? '') ?></td>
                    <td><?= h($r['sigortali_adi'] ?? '') ?></td>
                    <td><?= h($r['tc_vn'] ?? '') ?></td>
                    <td><?= h($r['policy_no'] ?? '') ?></td>
                    <td><?= _money_span($r['brut_prim'] ?? '') ?></td>
                    <td>
                      <textarea class="cell-textarea dispute-note" data-rowid="<?= (int)($r['id'] ?? 0) ?>"><?= h($r['description'] ?? '') ?></textarea>
                    </td>
                    <td><span class="badge-status badge-itiraz" data-rowid="<?= (int)($r['id'] ?? 0) ?>"><?= h($r['dispute_status'] ?? 'OPEN') ?></span></td>
                    <td class="row-actions">
                      <button type="button" class="btn btn-primary btn-xs js-dispute-save" data-rowid="<?= (int)($r['id'] ?? 0) ?>">Kaydet</button>
                      <button type="button" class="btn btn-secondary btn-xs js-dispute-toggle" data-rowid="<?= (int)($r['id'] ?? 0) ?>">Aç/Kapat</button>
                    </td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>


<?php if ($allowedTab === 'itiraz'): ?>
<script>
(function(){
  function toast(type,msg){
    try{ if (typeof window.showToast==='function') return window.showToast(type,msg); }catch(e){}
    alert(msg);
  }
  var csrf = <?= json_encode(csrf_token()) ?>;
  var periodId = <?= (int)$periodId ?>;

  function post(action, rowId, note){
    var fd = new FormData();
    fd.append('action', action);
    fd.append('row_id', String(rowId||''));
    fd.append('period_id', String(periodId||''));
    fd.append('csrf', csrf);
    if (note != null) fd.append('note', note);
    return fetch('ajax_v2_dispute_action.php', {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, j:j};});});
  }

  document.querySelectorAll('.js-dispute-save').forEach(function(btn){
    btn.addEventListener('click', function(){
      var rowId = btn.getAttribute('data-rowid');
      var ta = document.querySelector('.dispute-note[data-rowid="'+rowId+'"]');
      var note = ta ? ta.value : '';
      post('save_note', rowId, note).then(function(res){
        if (!res.ok || !res.j || !res.j.ok) throw new Error((res.j && res.j.msg) ? res.j.msg : 'Kaydedilemedi');
        toast('success','Kaydedildi');
      }).catch(function(e){
        toast('error', e.message || 'Hata');
      });
    });
  });

  document.querySelectorAll('.js-dispute-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var rowId = btn.getAttribute('data-rowid');
      post('toggle_status', rowId, '').then(function(res){
        if (!res.ok || !res.j || !res.j.ok) throw new Error((res.j && res.j.msg) ? res.j.msg : 'Degistirilemedi');
        toast('success','Guncellendi');
        window.location.reload();
      }).catch(function(e){
        toast('error', e.message || 'Hata');
      });
    });
  });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
