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

<section class="page">
  <div class="card">
    
    <h2 class="card-title">Raporlar</h2>

    <div class="hr"></div>

    <p>
      Bu bölüm üzerinde aktif olarak çalışıyoruz.
      <br><br>
      Mutabakat, ödeme ve performans raporları
      <b>2026 yılının ilk çeyreğinde</b> kademeli olarak hizmete sunulacaktır.
    </p>

  </div>
</section>

</body>
</html>
