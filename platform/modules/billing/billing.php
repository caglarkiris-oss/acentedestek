<?php
// /public_html/platform/billing.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

/* Login */
if (function_exists('require_login')) {
  require_login();
} elseif (function_exists('require_auth')) {
  require_auth();
} else {
  if (!isset($_SESSION['user_id'])) {
    header("Location: " . base_url("login.php"));
    exit;
  }
}

if (!isset($conn) || !$conn) {
  http_response_code(500);
  exit("DB bağlantısı bulunamadı.");
}

/* Sadece SUPERADMIN */
$role = (string)($_SESSION['role'] ?? '');
if ($role !== 'SUPERADMIN') {
  http_response_code(403);
  exit("Bu sayfaya erişim yetkiniz yok.");
}

/**
 * parent_id bazen NULL bazen 0 olabiliyor.
 * Bu sayfa her iki durumu da desteklesin diye root filtreyi esnek yazıyoruz.
 */
$pricePerUser = (float) config('billing.price_per_user', 49); // TL
$onlyActive   = (int) (config('billing.only_active_users', 1) ? 1 : 0); // 1 ise sadece aktif user say

// root filtre (NULL veya 0)
$rootWhere = " (root.parent_id IS NULL OR root.parent_id = 0) ";

// user join koşulu
$userJoinExtra = $onlyActive ? " AND u.is_active = 1 " : "";

// Faturalama raporu (ana acente bazında)
$sql = "
SELECT
  root.id   AS invoice_agency_id,
  root.name AS invoice_agency_name,

  -- tali acente sayısı
  (
    SELECT COUNT(*)
    FROM agencies child
    WHERE child.parent_id = root.id
  ) AS tali_acente_sayisi,

  -- ana acentenin user sayısı
  SUM(CASE WHEN a.id = root.id THEN 1 ELSE 0 END) AS ana_user_sayisi,

  -- talilerin user sayısı
  SUM(CASE WHEN a.parent_id = root.id THEN 1 ELSE 0 END) AS tali_user_sayisi,

  -- toplam user
  COUNT(u.id) AS toplam_user

FROM agencies root
LEFT JOIN agencies a
  ON (a.id = root.id OR a.parent_id = root.id)
LEFT JOIN users u
  ON u.agency_id = a.id
  $userJoinExtra
WHERE $rootWhere
GROUP BY root.id
ORDER BY root.name ASC
";

$rows = [];
$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  exit("DB prepare hatası: " . $conn->error);
}

$stmt->execute();

// mysqlnd yoksa bind_result ile al
$stmt->bind_result(
  $invoice_agency_id,
  $invoice_agency_name,
  $tali_acente_sayisi,
  $ana_user_sayisi,
  $tali_user_sayisi,
  $toplam_user
);

$totalAllUsers = 0;
$totalAllAmount = 0.0;

while ($stmt->fetch()) {
  $u = (int)$toplam_user;
  $amount = $u * $pricePerUser;

  $rows[] = [
    'invoice_agency_id'   => (int)$invoice_agency_id,
    'invoice_agency_name' => (string)$invoice_agency_name,
    'tali_acente_sayisi'  => (int)$tali_acente_sayisi,
    'ana_user_sayisi'     => (int)$ana_user_sayisi,
    'tali_user_sayisi'    => (int)$tali_user_sayisi,
    'toplam_user'         => $u,
    'price_per_user'      => $pricePerUser,
    'amount'              => $amount,
  ];

  $totalAllUsers += $u;
  $totalAllAmount += $amount;
}
$stmt->close();

$pageTitle = 'Faturalama';
$currentPage = 'billing';
require_once __DIR__ . '/../../layout/header.php';

function tl($n): string {
  // basit TL formatı (binlik ayraç)
  return number_format((float)$n, 2, ',', '.');
}
?>


<main class="page-wrap">
  <div class="panel">
    <h2 class="h">Faturalama Raporu (Ana Acente Bazında)</h2>
    <div class="muted">
      <?= $onlyActive ? 'Sadece aktif kullanıcılar baz alınır.' : 'Tüm kullanıcılar baz alınır.' ?>
      • Kullanıcı başı ücret: <span class="badge"><?= htmlspecialchars(tl($pricePerUser)) ?> TL</span>
    </div>

    <div class="kpi">
      <div class="box">
        <small>Toplam Kullanıcı</small>
        <b><?= (int)$totalAllUsers ?></b>
      </div>
      <div class="box">
        <small>Toplam Tutar</small>
        <b><?= htmlspecialchars(tl($totalAllAmount)) ?> TL</b>
      </div>
    </div>
  </div>

  <div class="panel">
    <table class="table">
      <thead>
        <tr>
          <th>Ana Acente</th>
          <th>Tali Sayısı</th>
          <th>Ana User</th>
          <th>Tali User</th>
          <th>Toplam User</th>
          <th>Kullanıcı Ücreti</th>
          <th>Tutar</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7">Kayıt yok.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['invoice_agency_name']) ?></td>
              <td><?= (int)$r['tali_acente_sayisi'] ?></td>
              <td><?= (int)$r['ana_user_sayisi'] ?></td>
              <td><?= (int)$r['tali_user_sayisi'] ?></td>
              <td><span class="badge"><?= (int)$r['toplam_user'] ?></span></td>
              <td><?= htmlspecialchars(tl($r['price_per_user'])) ?> TL</td>
              <td><b><?= htmlspecialchars(tl($r['amount'])) ?> TL</b></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

</body>
</html>
