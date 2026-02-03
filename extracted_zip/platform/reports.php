<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

/* Login */
if (function_exists('require_login')) {
  require_login();
} else {
  if (!isset($_SESSION['user_id'])) {
    header("Location: " . base_url("login.php"));
    exit;
  }
}

require_once __DIR__ . '/layout/header.php';
?>

<main class="page">
  <div class="card" style="max-width:720px;margin:40px auto;text-align:center">
    
    <h2 class="card-title">Raporlar</h2>

    <div class="hr"></div>

    <p style="font-size:15px;line-height:1.6;color:#475569">
      Bu bölüm üzerinde aktif olarak çalışıyoruz.
      <br><br>
      Mutabakat, ödeme ve performans raporları
      <b>2026 yılının ilk çeyreğinde</b> kademeli olarak hizmete sunulacaktır.
    </p>

  </div>
</main>

</body>
</html>
