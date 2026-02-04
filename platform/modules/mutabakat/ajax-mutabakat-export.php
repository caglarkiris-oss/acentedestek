<?php
// /platform/ajax-mutabakat-export.php
// Mutabakat V2 - CSV export

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_mutabakat_access();

$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$agencyId = (int)($_SESSION['agency_id'] ?? 0);
$isMain   = is_main_role();

if (!$agencyId) {
    http_response_code(403);
    exit('Yetkisiz');
}

$mainAgencyId = $isMain ? $agencyId : get_main_agency_id($conn, $agencyId);

$periodId = (int)($_GET['period_id'] ?? 0);
$exportType = $_GET['type'] ?? 'all';

if (!$periodId) {
    http_response_code(400);
    exit('Donem secilmedi');
}

// Query
$query = "SELECT id, source_type, tali_acente_id, tc_vn, sigortali_adi, sig_kimlik_no, policy_no, txn_type, zeyil_turu,
                 tanzim_tarihi, bitis_tarihi, sigorta_sirketi, brans, urun, plaka,
                 brut_prim, net_prim, komisyon_tutari, araci_kom_payi, currency, row_status
          FROM mutabakat_v2_rows
          WHERE period_id = ? AND ana_acente_id = ?";

$params = [$periodId, $mainAgencyId];
$types = 'ii';

switch ($exportType) {
    case 'havuz':
        $query .= " AND row_status = 'HAVUZ'";
        break;
    case 'eslesen':
        $query .= " AND row_status = 'ESLESEN'";
        break;
    case 'eslesmeyen':
        $query .= " AND row_status = 'ESLESMEYEN'";
        break;
    case 'ana_csv':
        $query .= " AND source_type = 'ANA_CSV'";
        break;
    case 'tali_csv':
        $query .= " AND source_type = 'TALI_CSV'";
        break;
}

$query .= " ORDER BY id DESC";

$st = $conn->prepare($query);
$st->bind_param($types, ...$params);
$st->execute();
$result = $st->get_result();

$rows = [];
while ($result && ($row = $result->fetch_assoc())) {
    $rows[] = $row;
}
$st->close();

// CSV output
$filename = "mutabakat_export_{$periodId}_{$exportType}_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM for Excel UTF-8
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Headers
$headers = ['ID', 'Kaynak', 'Tali ID', 'T.C/V.N', 'Sigortali Adi', 'Kimlik No', 'Police No', 'Islem Tipi', 'Zeyil Turu',
            'Tanzim Tarihi', 'Bitis Tarihi', 'Sigorta Sirketi', 'Brans', 'Urun', 'Plaka',
            'Brut Prim', 'Net Prim', 'Komisyon', 'Araci Payi', 'Para Birimi', 'Durum'];
fputcsv($output, $headers, ';');

foreach ($rows as $r) {
    fputcsv($output, [
        $r['id'],
        $r['source_type'],
        $r['tali_acente_id'],
        $r['tc_vn'],
        $r['sigortali_adi'],
        $r['sig_kimlik_no'],
        $r['policy_no'],
        $r['txn_type'],
        $r['zeyil_turu'],
        $r['tanzim_tarihi'],
        $r['bitis_tarihi'],
        $r['sigorta_sirketi'],
        $r['brans'],
        $r['urun'],
        $r['plaka'],
        str_replace('.', ',', $r['brut_prim'] ?? ''),
        str_replace('.', ',', $r['net_prim'] ?? ''),
        str_replace('.', ',', $r['komisyon_tutari'] ?? ''),
        str_replace('.', ',', $r['araci_kom_payi'] ?? ''),
        $r['currency'],
        $r['row_status']
    ], ';');
}

fclose($output);
exit;
