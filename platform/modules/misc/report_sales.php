<?php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__.'/auth.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/db.php';

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) {
    header("Location: ".base_url("login.php"));
    exit;
  }
}

$conn = db();
if (!$conn) die("DB yok");

$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

if (!in_array($role, ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'], true)) {
  http_response_code(403);
  exit('Yetkisiz');
}

/* PERIOD (YYYY-MM) */
$period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');

$startDate = $period.'-01';
$endDate   = date('Y-m-t', strtotime($startDate)); // ayın son günü

/* SCOPE */
$where = ($role === 'ACENTE_YETKILISI')
  ? 'mr.main_agency_id = ?'
  : 'mr.assigned_agency_id = ?';

/* QUERY */
$sql = "
SELECT
  a.id   AS tali_id,
  a.name AS tali_name,

  SUM(CASE WHEN mr.txn_type='SATIS' THEN 1 ELSE 0 END) AS satis_adet,
  SUM(CASE WHEN mr.txn_type='SATIS' THEN COALESCE(mr.gross_premium,0) ELSE 0 END) AS satis_brut,

  SUM(CASE WHEN mr.txn_type='IPTAL' THEN 1 ELSE 0 END) AS iptal_adet,
  SUM(CASE WHEN mr.txn_type='IPTAL' THEN COALESCE(mr.gross_premium,0) ELSE 0 END) AS iptal_brut,

  SUM(CASE WHEN mr.txn_type='ZEYIL' THEN 1 ELSE 0 END) AS zeyil_adet,
  SUM(CASE WHEN mr.txn_type='ZEYIL' THEN COALESCE(mr.gross_premium,0) ELSE 0 END) AS zeyil_brut

FROM mutabakat_rows mr
JOIN agencies a ON a.id = mr.assigned_agency_id

WHERE $where
  AND mr.issue_date BETWEEN ? AND ?
  AND mr.txn_type IN ('SATIS','IPTAL','ZEYIL')

GROUP BY a.id, a.name
ORDER BY a.name ASC
";

$st = $conn->prepare($sql);
if (!$st) die("PREPARE HATA: ".$conn->error);

$st->bind_param("iss", $agencyId, $startDate, $endDate);
$st->execute();
$res = $st->get_result();

$rows = [];
$tot = ['satis'=>0,'iptal'=>0,'zeyil'=>0];

while ($r = $res->fetch_assoc()) {
  $net = ($r['satis_brut'] ?? 0)
       - abs($r['iptal_brut'] ?? 0)
       - abs($r['zeyil_brut'] ?? 0);

  $r['net'] = $net;
  $rows[] = $r;

  $tot['satis'] += (float)$r['satis_brut'];
  $tot['iptal'] += (float)$r['iptal_brut'];
  $tot['zeyil'] += (float)$r['zeyil_brut'];
}
$st->close();

$tot_net = $tot['satis'] - abs($tot['iptal']) - abs($tot['zeyil']);

require_once __DIR__ . '/../../layout/header.php';
?>

<section class="page">
<div class="card">
  <h2 class="card-title">Satış Raporu (Brüt)</h2>
  <div class="card-sub">
    Dönem: <b><?= htmlspecialchars($period) ?></b>
    • Tarih: <?= htmlspecialchars($startDate) ?> - <?= htmlspecialchars($endDate) ?>
  </div>

  <div class="hr"></div>

  <table class="table">
    <thead>
      <tr>
        <th>Tali</th>
        <th>Satış</th><th>Satış Brüt</th>
        <th>İptal</th><th>İptal Brüt</th>
        <th>Zeyil</th><th>Zeyil Brüt</th>
        <th>NET</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['tali_name']) ?></td>
        <td><?= (int)$r['satis_adet'] ?></td>
        <td><?= number_format((float)$r['satis_brut'],2,',','.') ?></td>
        <td><?= (int)$r['iptal_adet'] ?></td>
        <td><?= number_format((float)$r['iptal_brut'],2,',','.') ?></td>
        <td><?= (int)$r['zeyil_adet'] ?></td>
        <td><?= number_format((float)$r['zeyil_brut'],2,',','.') ?></td>
        <td><b><?= number_format((float)$r['net'],2,',','.') ?></b></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th>TOPLAM</th>
        <th colspan="2"><?= number_format((float)$tot['satis'],2,',','.') ?></th>
        <th colspan="2"><?= number_format((float)$tot['iptal'],2,',','.') ?></th>
        <th colspan="2"><?= number_format((float)$tot['zeyil'],2,',','.') ?></th>
        <th><?= number_format((float)$tot_net,2,',','.') ?></th>
      </tr>
    </tfoot>
  </table>

  <div>
    <a class="btn" href="<?= base_url('reports.php') ?>">← Raporlara Dön</a>
  </div>
</div>
</section>

</body>
</html>
