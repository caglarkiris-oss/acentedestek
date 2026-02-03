<?php
// /public_html/platform/mutabakat/atama.php
// ✅ Mutabakat V2 - Atama ekranı (şimdilik iskelet)

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_mutabakat_atama_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) { header('Location: '.base_url('login.php')); exit; }
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI'], true)) {
  http_response_code(403);
  exit('Yetkisiz');
}

require_once __DIR__ . '/../layout/header.php';
?>

<div class="container" style="max-width: 1200px; margin: 24px auto; padding: 0 16px;">
  <div class="card" style="border-radius:16px; padding:18px;">
    <h1 style="margin:0; font-size:20px;">Mutabakat • Atama</h1>
    <p style="margin:10px 0 0; opacity:.75;">Bu sayfa bir sonraki adımda, <code>mutabakat_v2_assignments</code> ve <code>mutabakat_v2_assignment_rows</code> tablolarına göre sıfırdan kurgulanacak.</p>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
