<?php
// /platform/mutabakat/ajax-edit-cell.php
// Mutabakat V2 - Tek hucre inline edit (ANA_CSV)

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: application/json; charset=utf-8');

$csrfEnforce = (bool) config('security.csrf_enforce', true);
if ($csrfEnforce && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
}

require_mutabakat_access();

$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$agencyId = (int)($_SESSION['agency_id'] ?? 0);
$isMain   = is_main_role();

if (!$isMain) {
    echo json_encode(['ok' => false, 'error' => 'Yetkisiz']);
    exit;
}

$mainAgencyId = $agencyId;

$rowId = (int)($_POST['row_id'] ?? 0);
$field = trim($_POST['field'] ?? '');
$value = $_POST['value'] ?? '';

if ($rowId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Gecersiz satir']);
    exit;
}

// Izin verilen alanlar
$allowedFields = ['sigortali_adi', 'tc_vn', 'sig_kimlik_no', 'policy_no', 'net_prim', 'plaka', 'sigorta_sirketi', 'brans', 'urun'];

if (!in_array($field, $allowedFields, true)) {
    echo json_encode(['ok' => false, 'error' => 'Gecersiz alan']);
    exit;
}

// Deger temizleme
$value = trim((string)$value);
if ($value === '') $value = null;

// policy_no icin normalize
if ($field === 'policy_no' && $value !== null) {
    $value = _policy_clean($value);
    // policy_no_norm da guncelle
    $policyNorm = _policy_norm($value);
}

// net_prim icin decimal
if ($field === 'net_prim' && $value !== null) {
    $value = _to_decimal($value);
}


// Satır sahiplik kontrolü (ana acente izolasyonu)
$stChk = $conn->prepare("SELECT id FROM mutabakat_v2_rows WHERE id=? AND ana_acente_id=? LIMIT 1");
if (!$stChk) {
    echo json_encode(['ok'=>false,'error'=>'Kontrol hazirlanamadi']);
    exit;
}
$stChk->bind_param('ii', $rowId, $mainAgencyId);
$stChk->execute();
$found = (bool)$stChk->get_result()->fetch_assoc();
$stChk->close();
if (!$found) {
    echo json_encode(['ok'=>false,'error'=>'Satır bulunamadı']);
    exit;
}

try {
    // Satirin bu kullaniciya ait oldugunu kontrol et
    $st = $conn->prepare("SELECT id FROM mutabakat_v2_rows WHERE id=? AND ana_acente_id=? AND source_type='ANA_CSV' LIMIT 1");
    $st->bind_param('ii', $rowId, $mainAgencyId);
    $st->execute();
    $st->store_result();
    if ($st->num_rows === 0) {
        $st->close();
        echo json_encode(['ok' => false, 'error' => 'Satir bulunamadi']);
        exit;
    }
    $st->close();

    // Guncelle
    if ($field === 'policy_no') {
        $st = $conn->prepare("UPDATE mutabakat_v2_rows SET policy_no=?, policy_no_norm=?, updated_at=NOW() WHERE id=? AND ana_acente_id=?");
        $st->bind_param('ssii', $value, $policyNorm, $rowId, $mainAgencyId);
    } else {
        $st = $conn->prepare("UPDATE mutabakat_v2_rows SET $field=?, updated_at=NOW() WHERE id=? AND ana_acente_id=?");
        $st->bind_param('sii', $value, $rowId, $mainAgencyId);
    }

    if (!$st->execute()) {
        throw new Exception($st->error);
    }
    $st->close();

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
