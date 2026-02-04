<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';

/* Login */
if (function_exists('require_login')) {
  require_login();
} else {
  if (!isset($_SESSION['user_id'])) {
    header("Location: " . base_url("login.php"));
    exit;
  }
}

$pageTitle = 'Raporlar';
$currentPage = 'reports';
require_once __DIR__ . '/../../layout/header.php';
?>

<!-- Reports Page -->
<div class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">
        <i data-lucide="bar-chart-3" style="display: inline; width: 24px; height: 24px; vertical-align: middle; margin-right: 8px;"></i>
        Raporlar
      </h2>
      <p class="card-subtitle">Mutabakat, ödeme ve performans raporları</p>
    </div>
  </div>

  <div class="card-body" style="text-align: center; padding: var(--sp-8);">
    <div style="max-width: 400px; margin: 0 auto;">
      <div style="width: 80px; height: 80px; background: var(--info-light); border-radius: var(--radius-xl); display: flex; align-items: center; justify-content: center; margin: 0 auto var(--sp-5);">
        <i data-lucide="construction" style="width: 40px; height: 40px; color: var(--info);"></i>
      </div>
      
      <h3 style="font-size: var(--fs-xl); font-weight: var(--fw-bold); margin-bottom: var(--sp-3);">Yakında Geliyor</h3>
      
      <p class="u-text-muted" style="margin-bottom: var(--sp-5);">
        Bu bölüm üzerinde aktif olarak çalışıyoruz.<br><br>
        Mutabakat, ödeme ve performans raporları
        <strong>2026 yılının ilk çeyreğinde</strong> kademeli olarak hizmete sunulacaktır.
      </p>

      <div class="u-flex u-gap-3 u-justify-center">
        <a href="<?= route_url('dashboard') ?>" class="btn btn-primary">
          <i data-lucide="home"></i>
          Dashboard'a Dön
        </a>
        <a href="<?= route_url('mutabakat/havuz') ?>" class="btn btn-secondary">
          <i data-lucide="git-compare"></i>
          Mutabakat
        </a>
      </div>
    </div>
  </div>
</div>

<script>
  if (typeof lucide !== 'undefined') lucide.createIcons();
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
