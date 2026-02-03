<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

if (function_exists('require_login')) require_login();
$conn = db();
$conn->set_charset('utf8mb4');

$ym = $_GET['ym'] ?? date('Y-m');
$taliId = isset($_GET['tali_id']) ? (int)$_GET['tali_id'] : 0;
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

[$y,$m] = explode('-', $ym);
$period = ((int)$y * 100) + (int)$m;

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="mutabakat_'.$period.'.csv"');

$out = fopen('php://output', 'w');

fputcsv($out, [
  'period_yyyymm','ticket_id','main_agency_id','tali_agency_id',
  'type','tc_vn','sigortali_adi','tanzim_tarihi',
  'sigorta_sirketi','brans','police_no','plaka','brut_prim'
]);

$sql = "
  SELECT period_yyyymm,ticket_id,main_agency_id,tali_agency_id,
         type,tc_vn,sigortali_adi,tanzim_tarihi,
         sigorta_sirketi,brans,police_no,plaka,brut_prim
  FROM mutabakat_entries
  WHERE period_yyyymm=? AND main_agency_id=?
";

if ($taliId>0) $sql.=" AND tali_agency_id=".$taliId;

$st = $conn->prepare($sql);
$st->bind_param("ii", $period, $agencyId);
$st->execute();
$res = $st->get_result();

while ($r = $res->fetch_assoc()) {
  fputcsv($out, $r);
}

fclose($out);
exit;
