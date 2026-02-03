<?php
// /platform/login.php
// Mutabakat V2 - Test login sayfasi

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

ensure_session();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'ACENTE_YETKILISI';
    $agency_id = (int)($_POST['agency_id'] ?? 1);

    // Test login - gercek sistemde users tablosundan dogrulama yapilir
    if ($email !== '' && $password !== '') {
        $_SESSION['user_id'] = 1;
        $_SESSION['agency_id'] = $agency_id;
        $_SESSION['role'] = $role;
        $_SESSION['name'] = $email;
        $_SESSION['email'] = $email;

        redirect(base_url('mutabakat/havuz.php'));
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
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
      font-size: 14px;
      background: #f8fafc;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .login-card {
      background: #fff;
      border: 1px solid rgba(15,23,42,.10);
      border-radius: 18px;
      box-shadow: 0 14px 40px rgba(2,6,23,.08);
      padding: 32px;
      width: 100%;
      max-width: 400px;
    }
    .login-title {
      font-size: 20px;
      font-weight: 800;
      margin: 0 0 24px 0;
      text-align: center;
    }
    .form-group {
      margin-bottom: 16px;
    }
    .form-label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 6px;
      color: #334155;
    }
    .form-control {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid rgba(15,23,42,.12);
      border-radius: 12px;
      font-size: 14px;
      font-family: inherit;
      transition: all .15s ease;
    }
    .form-control:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59,130,246,.12);
    }
    .btn-login {
      width: 100%;
      padding: 14px;
      border: none;
      border-radius: 12px;
      background: #0f172a;
      color: #fff;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      transition: all .15s ease;
    }
    .btn-login:hover {
      background: #1e293b;
    }
    .error-msg {
      background: #fff1f2;
      border: 1px solid rgba(244,63,94,.25);
      color: #9f1239;
      padding: 10px 12px;
      border-radius: 10px;
      font-size: 13px;
      margin-bottom: 16px;
    }
    .hint {
      font-size: 12px;
      color: #64748b;
      margin-top: 16px;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="login-card">
    <h1 class="login-title">Mutabakat Sistemi</h1>
    
    <?php if ($error): ?>
      <div class="error-msg"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
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
