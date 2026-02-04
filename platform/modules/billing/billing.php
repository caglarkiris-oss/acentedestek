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

<!-- Billing Page -->
<div class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">
        <i data-lucide="wallet" style="display: inline; width: 24px; height: 24px; vertical-align: middle; margin-right: 8px;"></i>
        Faturalama Raporu
      </h2>
      <p class="card-subtitle">
        <?= $onlyActive ? 'Sadece aktif kullanıcılar baz alınır.' : 'Tüm kullanıcılar baz alınır.' ?>
        • Kullanıcı başı ücret: <span class="badge badge-info"><?= htmlspecialchars(tl($pricePerUser)) ?> ₺</span>
      </p>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<div class="kpi-row u-mt-4">
  <div class="stat-card" data-testid="kpi-total-users">
    <div class="stat-card-icon">
      <i data-lucide="users"></i>
    </div>
    <div class="stat-card-label">Toplam Kullanıcı</div>
    <div class="stat-card-value"><?= number_format((int)$totalAllUsers) ?></div>
  </div>
  
  <div class="stat-card" data-testid="kpi-total-amount">
    <div class="stat-card-icon success">
      <i data-lucide="banknote"></i>
    </div>
    <div class="stat-card-label">Toplam Tutar</div>
    <div class="stat-card-value"><?= htmlspecialchars(tl($totalAllAmount)) ?> ₺</div>
  </div>
</div>

<!-- Data Table -->
<div class="card u-mt-4">
  <div class="card-header">
    <div>
      <h3 class="card-title">Ana Acente Bazında Detay</h3>
      <p class="card-subtitle"><?= count($rows) ?> ana acente listeleniyor</p>
    </div>
  </div>
  
  <div class="table-wrap" style="border: none; border-radius: 0;">
    <table class="table">
      <thead>
        <tr>
          <th>Ana Acente</th>
          <th style="text-align: center;">Tali Sayısı</th>
          <th style="text-align: center;">Ana User</th>
          <th style="text-align: center;">Tali User</th>
          <th style="text-align: center;">Toplam User</th>
          <th style="text-align: right;">Kullanıcı Ücreti</th>
          <th style="text-align: right;">Tutar</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="7" class="u-text-center u-text-muted" style="padding: var(--sp-8);">
              <i data-lucide="inbox" style="width: 32px; height: 32px; opacity: 0.4; display: block; margin: 0 auto var(--sp-3);"></i>
              Henüz kayıt yok.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <div class="u-flex u-items-center u-gap-3">
                  <div class="avatar avatar-sm"><?= strtoupper(substr($r['invoice_agency_name'], 0, 2)) ?></div>
                  <span class="u-font-semibold"><?= htmlspecialchars($r['invoice_agency_name']) ?></span>
                </div>
              </td>
              <td style="text-align: center;"><?= (int)$r['tali_acente_sayisi'] ?></td>
              <td style="text-align: center;"><?= (int)$r['ana_user_sayisi'] ?></td>
              <td style="text-align: center;"><?= (int)$r['tali_user_sayisi'] ?></td>
              <td style="text-align: center;"><span class="badge badge-info"><?= (int)$r['toplam_user'] ?></span></td>
              <td style="text-align: right;" class="u-text-muted"><?= htmlspecialchars(tl($r['price_per_user'])) ?> ₺</td>
              <td style="text-align: right;"><span class="u-font-bold"><?= htmlspecialchars(tl($r['amount'])) ?> ₺</span></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <?php if (!empty($rows)): ?>
        <tfoot>
          <tr style="background: var(--bg-hover); font-weight: var(--fw-bold);">
            <td colspan="4">Toplam</td>
            <td style="text-align: center;"><?= number_format((int)$totalAllUsers) ?></td>
            <td></td>
            <td style="text-align: right;"><?= htmlspecialchars(tl($totalAllAmount)) ?> ₺</td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<script>
  if (typeof lucide !== 'undefined') lucide.createIcons();
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
