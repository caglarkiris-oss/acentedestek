<?php
// /platform/mutabakat/havuz.php
// Mutabakat V2 - Havuz ekrani (sekmeler + upload + eslestirme)

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_mutabakat_havuz_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

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
        if ($action === 'bulk_save_unmatched') {
            if (!$isMain) {
                $flashErr = 'Yetkisiz islem.';
            } else {
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
                    $policyNorm = _policy_norm($policyNo);
                    $netPrim = _to_decimal((string)($it['net_prim'] ?? ''));

                    $st = $conn->prepare("UPDATE mutabakat_v2_rows
                                         SET sigortali_adi = ?, tc_vn = ?, policy_no = ?, policy_no_norm = ?, net_prim = ?, updated_at = NOW()
                                         WHERE id = ? AND period_id = ? AND ana_acente_id = ? AND source_type='ANA_CSV'");
                    if ($st) {
                        $sigVal = $sigortali === '' ? null : $sigortali;
                        $tcVal = $tcVn === '' ? null : $tcVn;
                        $polVal = $policyNo === '' ? null : $policyNo;
                        $polNormVal = $policyNorm === '' ? null : $policyNorm;
                        $st->bind_param('sssssiis', $sigVal, $tcVal, $polVal, $polNormVal, $netPrim, $rowId, $periodId, $mainAgencyId);
                        if ($st->execute() && $st->affected_rows > 0) {
                            $saved++;
                        }
                        $st->close();
                    }
                }

                $flashOk = $saved > 0 ? ("Kaydedildi: {$saved} satir") : "Kaydedilecek degisiklik yok.";
            }
        }

        /* ---------- Run Match ---------- */
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
    $fileHash = @sha1_file($tmp) ?: null;

    $conn->begin_transaction();
    try {
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
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");

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
    $fileHash = @sha1_file($tmp) ?: null;

    $conn->begin_transaction();
    try {
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
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");

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
        $st = $conn->prepare("SELECT id, policy_no, policy_no_norm FROM mutabakat_v2_rows WHERE period_id=? AND ana_acente_id=? AND source_type='ANA_CSV'");
        $st->bind_param('ii', $periodId, $mainAgencyId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) $anaRows[] = $r;
        $st->close();

        // Havuz satirlari (TALI_CSV + TICKET) - policy_no_norm ile lookup
        $pool = [];
        $st = $conn->prepare("SELECT id, policy_no, policy_no_norm FROM mutabakat_v2_rows WHERE period_id=? AND ana_acente_id=? AND source_type IN ('TICKET','TALI_CSV') AND row_status='HAVUZ'");
        $st->bind_param('ii', $periodId, $mainAgencyId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $pnorm = (string)($r['policy_no_norm'] ?? '');
            if ($pnorm === '') $pnorm = _policy_norm($r['policy_no']);
            if ($pnorm === '') continue;
            if (!isset($pool[$pnorm])) $pool[$pnorm] = [];
            $pool[$pnorm][] = (int)$r['id'];
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
                $taliId = array_shift($pool[$pnorm]);

                // Match kaydi olustur
                $status = 'MATCHED';
                $mismatch = '';
                $stM = $conn->prepare("INSERT INTO mutabakat_v2_matches (period_id, policy_no, tali_row_id, ana_row_id, status, mismatch_summary, created_at)
                                       VALUES (?,?,?,?,?,?,NOW())
                                       ON DUPLICATE KEY UPDATE status=VALUES(status), mismatch_summary=VALUES(mismatch_summary)");
                $stM->bind_param('isiiss', $periodId, $pnorm, $taliId, $anaId, $status, $mismatch);
                $stM->execute();
                $stM->close();

                // row_status guncelle
                $stU = $conn->prepare("UPDATE mutabakat_v2_rows SET row_status='ESLESEN', updated_at=NOW() WHERE id IN (?,?)");
                $stU->bind_param('ii', $taliId, $anaId);
                $stU->execute();
                $stU->close();

                $matched++;
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
        $st->bind_param('i', $periodId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) $matches[] = $r;
        $st->close();
    }

    if ($allowedTab === 'eslesmeyen' && $isMain) {
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
    $st = $conn->prepare("SELECT COUNT(*) FROM mutabakat_v2_matches WHERE period_id=? AND status='MATCHED'");
    $st->bind_param('i', $periodId);
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
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container">
  <!-- Baslik ve Donem Secimi -->
  <div class="card">
    <div class="card-header">
      <div>
        <h1 class="card-title">Mutabakat - Havuz</h1>
        <div class="card-subtitle"><?= $isMain ? 'Ana acente gorunumu' : 'Tali acente gorunumu' ?></div>
      </div>

      <form method="get" style="display:flex; align-items:center; gap:10px;">
        <input type="hidden" name="tab" value="<?= h($allowedTab) ?>">
        <label style="font-size:13px; opacity:.75;">Donem</label>
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
    <div class="upload-box" style="margin-top:14px;">
      <div class="upload-box-title">Tali CSV Yukle (workmode: csv)</div>
      <form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="action" value="tali_csv_upload">
        <input type="file" name="csv_file" accept=".csv,text/csv" required class="form-control">
        <button type="submit" class="btn btn-primary">Yukle</button>
      </form>
      <div class="upload-box-hint" style="margin-top:8px;">Basliklar: T.C/V.N., Sigortali, Plaka, Sirket, Brans, Tip, Tanzim, Police No, Brut Prim</div>
    </div>
  <?php endif; ?>

  <?php if ($allowedTab === 'ana_csv' && $isMain): ?>
    <div class="upload-box" style="margin-top:14px; display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
      <div>
        <div class="upload-box-title">Ana acente CSV Yukle</div>
        <div class="upload-box-hint">Yuklenen kayitlar sadece bu sekmede gorunur. Eslestir'e basinca Eslesen/Eslesmeyen'e ayrilir.</div>
      </div>
      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
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
  <div class="card" style="margin-top:14px;">
    <?php if ($allowedTab === 'eslesen' && $isMain): ?>
      <div style="font-weight:900; margin-bottom:12px;">Eslesen Kayitlar</div>
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
              <th style="text-align:right;">Brut Prim</th>
              <th style="text-align:right;">Araci Pay</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($matches)): ?>
              <tr><td colspan="11" style="opacity:.7;">Kayit yok.</td></tr>
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
                  <td style="text-align:right;"><?= _money_span($m['tali_brut'] ?? '') ?></td>
                  <td style="text-align:right;"><?= _money_span($m['araci_kom_payi'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($allowedTab === 'eslesmeyen' && $isMain): ?>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
        <div style="font-weight:900;">Eslesmeyen Kayitlar</div>
        <div style="display:flex; gap:10px; align-items:center;">
          <form id="bulkSaveForm" method="post" style="margin:0;">
            <input type="hidden" name="action" value="bulk_save_unmatched">
            <input type="hidden" name="payload" id="bulkPayload" value="">
            <button type="button" id="btnSaveAll" class="btn btn-primary" disabled>Kaydet</button>
          </form>
          <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="run_match">
            <button type="submit" class="btn btn-success">Eslestir</button>
          </form>
          <div id="dirtyInfo" style="font-size:12px; opacity:.75;">Degisiklik yok</div>
        </div>
      </div>

      <div class="table-wrapper">
        <table class="excel-table copyable-table">
          <thead>
            <tr>
              <th>Sigortali</th>
              <th>T.C/V.N</th>
              <th>Police No</th>
              <th style="text-align:right;">Net Prim</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="4" style="opacity:.7;">Kayit yok.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <tr class="unmatched-row" data-rowid="<?= h($r['id'] ?? '') ?>">
                  <td><input class="cell-input unmatched-input" data-field="sigortali_adi" value="<?= h($r['sigortali_adi'] ?? '') ?>" /></td>
                  <td><input class="cell-input unmatched-input" data-field="tc_vn" value="<?= h($r['tc_vn'] ?? ($r['sig_kimlik_no'] ?? '')) ?>" /></td>
                  <td><input class="cell-input unmatched-input" data-field="policy_no" value="<?= h($r['policy_no'] ?? '') ?>" /></td>
                  <td><input class="cell-input cell-input-right unmatched-input" data-field="net_prim" value="<?= h($r['net_prim'] ?? '') ?>" /></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
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
      <div style="font-weight:900; margin-bottom:12px;">
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
                <th style="text-align:right;">Brut Prim</th>
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
                <th style="text-align:right;">Brut</th>
                <th style="text-align:right;">Net</th>
                <th style="text-align:right;">Komisyon</th>
                <th style="text-align:right;">Araci Pay</th>
                <th>Durum</th>
              </tr>
            <?php elseif ($allowedTab === 'itiraz'): ?>
              <tr>
                <th>ID</th>
                <th>Kaynak</th>
                <th>Sigortali</th>
                <th>T.C/V.N</th>
                <th>Police No</th>
                <th style="text-align:right;">Brut Prim</th>
                <th>Itiraz Aciklama</th>
                <th>Durum</th>
              </tr>
            <?php endif; ?>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="15" style="opacity:.7;">Kayit yok.</td></tr>
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
                    <td style="text-align:right;"><?= _money_span($r['brut_prim'] ?? '') ?></td>
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
                    <td style="text-align:right;"><?= _money_span($r['brut_prim'] ?? '') ?></td>
                    <td style="text-align:right;"><?= _money_span($r['net_prim'] ?? '') ?></td>
                    <td style="text-align:right;"><?= _money_span($r['komisyon_tutari'] ?? '') ?></td>
                    <td style="text-align:right;"><?= _money_span($r['araci_kom_payi'] ?? '') ?></td>
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
                    <td style="text-align:right;"><?= _money_span($r['brut_prim'] ?? '') ?></td>
                    <td><?= h($r['description'] ?? '-') ?></td>
                    <td><span class="badge-status badge-itiraz"><?= h($r['dispute_status'] ?? 'OPEN') ?></span></td>
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

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
