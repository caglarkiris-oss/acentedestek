<?php
// /platform/login.php
// Mutabakat V2 - Test login sayfasi

require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

ensure_session();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfEnforce = (bool) config('security.csrf_enforce', true);
    if ($csrfEnforce) {
        csrf_verify();
    }
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = (string)($_POST['role'] ?? 'ACENTE_YETKILISI');
    $allowedRoles = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'ACENTE_YETKILISI';
    }
    $agency_id = (int)($_POST['agency_id'] ?? 1);

    // Test login - gercek sistemde users tablosundan dogrulama yapilir
    if ($email !== '' && $password !== '') {
        session_regenerate_id(true);
        $_SESSION['user_id'] = 1;
        $_SESSION['agency_id'] = $agency_id;
        $_SESSION['role'] = $role;
        $_SESSION['name'] = $email;
        $_SESSION['email'] = $email;

	    	redirect(route_url('mutabakat/havuz'));
    } else {
        $error = 'Email ve sifre giriniz.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Giris - Mutabakat Sistemi</title>
  
  <link rel="stylesheet" href="<?= base_url('layout/app.css') ?>">
</head>
<body>
  <div class="login-card">
    <h1 class="login-title">Mutabakat Sistemi</h1>
    
    <?php if ($error): ?>
      <div class="error-msg"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" placeholder="ornek@email.com" required>
      </div>

      <div class="form-group">
        <label class="form-label">Sifre</label>
        <input type="password" name="password" class="form-control" placeholder="********" required>
      </div>

      <div class="form-group">
        <label class="form-label">Rol (Test)</label>
        <select name="role" class="form-control">
          <option value="ACENTE_YETKILISI">ACENTE_YETKILISI (Ana Acente)</option>
          <option value="TALI_ACENTE_YETKILISI">TALI_ACENTE_YETKILISI (Tali Acente)</option>
          <option value="PERSONEL">PERSONEL</option>
          <option value="SUPERADMIN">SUPERADMIN</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Agency ID (Test)</label>
        <input type="number" name="agency_id" class="form-control" value="1" min="1">
      </div>

      <button type="submit" class="btn-login">Giris Yap</button>
    </form>

    <p class="hint">Test amaciyla herhangi bir email/sifre girebilirsiniz.</p>
  </div>
</body>
</html>
