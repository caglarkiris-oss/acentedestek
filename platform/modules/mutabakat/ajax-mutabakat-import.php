<?php
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../db.php";
require_once __DIR__ . "/../../auth.php";
require_once __DIR__ . "/../../helpers.php";

/* AUTH */
if (function_exists('require_login')) require_login();
if ($_SESSION['role'] === 'PERSONEL') {
  http_response_code(403);
  exit('Yetkisiz');
}

$conn = db();
$conn->set_charset('utf8mb4');

$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$csrfEnforce = (bool) config('security.csrf_enforce', true);
if ($csrfEnforce && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
}

/* helpers */
function normalize_money($v): float {
  $v = str_replace(['₺',' ','.'], '', (string)$v);
  $v = str_replace(',', '.', $v);
  return (float)$v;
}

function ym_to_period(string $ym): int {
  if (!preg_match('~^\d{4}-\d{2}$~', $ym)) return (int)date('Ym');
  [$y,$m] = explode('-', $ym);
  return ((int)$y * 100) + (int)$m;
}

/* input */
$ym = $_POST['ym'] ?? date('Y-m');
$period = ym_to_period($ym);

if (empty($_FILES['file']['tmp_name'])) {
  http_response_code(400);
  exit('Dosya yok');
}

$f = fopen($_FILES['file']['tmp_name'], 'r');
if (!$f) {
  http_response_code(500);
  exit('Dosya açılamadı');
}

/* header */
$header = fgetcsv($f, 0, ',');
$map = array_flip($header);

/* zorunlu kolonlar */
$required = [
  'ticket_id','tali_agency_id','type','sigortali_adi',
  'tanzim_tarihi','sigorta_sirketi','brans',
  'police_no','plaka','brut_prim'
];

foreach ($required as $r) {
  if (!isset($map[$r])) {
    http_response_code(400);
    exit("Eksik kolon: $r");
  }
}

$inserted = 0;
$skipped  = 0;

$sql = "
INSERT INTO mutabakat_entries
(period_yyyymm,ticket_id,main_agency_id,tali_agency_id,type,
 sigortali_adi,tanzim_tarihi,sigorta_sirketi,brans,
 police_no,plaka,brut_prim,created_at,updated_at)
SELECT ?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM mutabakat_entries
  WHERE period_yyyymm=? AND ticket_id=?
)
";

$st = $conn->prepare($sql);
if (!$st) {
  http_response_code(500);
  exit($conn->error);
}

while (($row = fgetcsv($f, 0, ',')) !== false) {

  $ticketId = (int)$row[$map['ticket_id']];
  if ($ticketId <= 0) { $skipped++; continue; }

  $taliId   = (int)$row[$map['tali_agency_id']];
  $type     = $row[$map['type']];
  $sig      = $row[$map['sigortali_adi']];
  $tarih    = $row[$map['tanzim_tarihi']];
  $sirket   = $row[$map['sigorta_sirketi']];
  $brans    = $row[$map['brans']];
  $police   = $row[$map['police_no']];
  $plaka    = $row[$map['plaka']];
  $brut     = normalize_money($row[$map['brut_prim']]);

  $st->bind_param(
    "iiiisssssssdii",
    $period,
    $ticketId,
    $agencyId,
    $taliId,
    $type,
    $sig,
    $tarih,
    $sirket,
    $brans,
    $police,
    $plaka,
    $brut,
    $period,
    $ticketId
  );

  $st->execute();
  if ($st->affected_rows > 0) $inserted++;
  else $skipped++;
}

fclose($f);

echo json_encode([
  'ok' => true,
  'period' => $period,
  'inserted' => $inserted,
  'skipped' => $skipped
]);
exit;
