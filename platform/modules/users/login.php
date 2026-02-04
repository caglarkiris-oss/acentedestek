<?php
// Enterprise Login - Premium Banking/Insurance Style
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

ensure_session();

$error = '';
$appName = (string) config('app.name', 'Acentedestek');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfEnforce = (bool) config('security.csrf_enforce', true);
    if ($csrfEnforce) {
        csrf_verify();
    }
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = (string)($_POST['role'] ?? 'ACENTE_YETKILISI');
    $allowedRoles = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL','SUPERADMIN'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'ACENTE_YETKILISI';
    }
    $agency_id = (int)($_POST['agency_id'] ?? 1);

    if ($email !== '' && $password !== '') {
        session_regenerate_id(true);
        $_SESSION['user_id'] = 1;
        $_SESSION['agency_id'] = $agency_id;
        $_SESSION['role'] = $role;
        $_SESSION['name'] = $email;
        $_SESSION['email'] = $email;
        redirect(route_url('mutabakat/havuz'));
    } else {
        $error = 'Email ve şifre giriniz.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Giriş - <?= h($appName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #0f172a 100%);
      padding: 20px;
      position: relative;
      overflow: hidden;
    }
    
    body::before {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(circle at 30% 30%, rgba(59, 130, 246, 0.15) 0%, transparent 50%),
                  radial-gradient(circle at 70% 70%, rgba(37, 99, 235, 0.1) 0%, transparent 50%);
      animation: pulse 15s ease-in-out infinite;
    }
    
    @keyframes pulse {
      0%, 100% { transform: scale(1) rotate(0deg); }
      50% { transform: scale(1.1) rotate(5deg); }
    }
    
    .login-wrapper {
      position: relative;
      z-index: 1;
      width: 100%;
      max-width: 440px;
    }
    
    .login-card {
      background: rgba(255, 255, 255, 0.98);
      border-radius: 24px;
      box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.1);
      padding: 48px 40px;
      backdrop-filter: blur(20px);
    }
    
    .login-header {
      text-align: center;
      margin-bottom: 36px;
    }
    
    .login-logo {
      width: 72px;
      height: 72px;
      background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);
      border-radius: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      box-shadow: 0 10px 40px rgba(30, 58, 138, 0.4);
    }
    
    .login-logo svg {
      width: 36px;
      height: 36px;
      color: white;
    }
    
    .login-title {
      font-size: 26px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -0.5px;
      margin-bottom: 8px;
    }
    
    .login-subtitle {
      font-size: 14px;
      color: #64748b;
      font-weight: 500;
    }
    
    .error-box {
      background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
      border: 1px solid #fecaca;
      border-radius: 12px;
      padding: 14px 16px;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      gap: 12px;
      color: #991b1b;
      font-size: 14px;
      font-weight: 500;
    }
    
    .error-box svg {
      width: 20px;
      height: 20px;
      flex-shrink: 0;
    }
    
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: #334155;
      margin-bottom: 8px;
      letter-spacing: 0.3px;
    }
    
    .form-input {
      width: 100%;
      padding: 14px 16px;
      font-family: inherit;
      font-size: 15px;
      color: #0f172a;
      background: #f8fafc;
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      outline: none;
      transition: all 0.2s ease;
    }
    
    .form-input:hover {
      border-color: #cbd5e1;
    }
    
    .form-input:focus {
      border-color: #3b82f6;
      background: white;
      box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }
    
    .form-input::placeholder {
      color: #94a3b8;
    }
    
    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    
    .btn-login {
      width: 100%;
      padding: 16px 24px;
      font-family: inherit;
      font-size: 15px;
      font-weight: 700;
      color: white;
      background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);
      border: none;
      border-radius: 12px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      transition: all 0.2s ease;
      box-shadow: 0 4px 20px rgba(30, 58, 138, 0.3);
      margin-top: 8px;
    }
    
    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 30px rgba(30, 58, 138, 0.4);
    }
    
    .btn-login:active {
      transform: translateY(0);
    }
    
    .btn-login svg {
      width: 20px;
      height: 20px;
    }
    
    .login-footer {
      margin-top: 28px;
      text-align: center;
    }
    
    .login-hint {
      background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
      border: 1px solid #bae6fd;
      border-radius: 10px;
      padding: 12px 16px;
      font-size: 12px;
      color: #0369a1;
      font-weight: 500;
    }
    
    .login-links {
      margin-top: 20px;
      display: flex;
      justify-content: center;
      gap: 24px;
    }
    
    .login-links a {
      font-size: 13px;
      color: #64748b;
      text-decoration: none;
      font-weight: 500;
      transition: color 0.2s;
    }
    
    .login-links a:hover {
      color: #1e3a8a;
    }
    
    @media (max-width: 480px) {
      .login-card { padding: 36px 24px; }
      .form-row { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="login-wrapper">
    <div class="login-card">
      <div class="login-header">
        <div class="login-logo">
          <i data-lucide="shield-check"></i>
        </div>
        <h1 class="login-title"><?= h($appName) ?></h1>
        <p class="login-subtitle">Sigorta & Mutabakat Yönetim Platformu</p>
      </div>

      <?php if ($error): ?>
        <div class="error-box">
          <i data-lucide="alert-circle"></i>
          <span><?= h($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="post">
        <?= csrf_field() ?>
        
        <div class="form-group">
          <label class="form-label">Email Adresi</label>
          <input type="email" name="email" class="form-input" placeholder="ornek@sirket.com" required autocomplete="email">
        </div>

        <div class="form-group">
          <label class="form-label">Şifre</label>
          <input type="password" name="password" class="form-input" placeholder="••••••••" required autocomplete="current-password">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Rol (Test)</label>
            <select name="role" class="form-input">
              <option value="ACENTE_YETKILISI">Ana Acente</option>
              <option value="TALI_ACENTE_YETKILISI">Tali Acente</option>
              <option value="PERSONEL">Personel</option>
              <option value="SUPERADMIN">Superadmin</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Agency ID</label>
            <input type="number" name="agency_id" class="form-input" value="1" min="1">
          </div>
        </div>

        <button type="submit" class="btn-login">
          <i data-lucide="log-in"></i>
          Giriş Yap
        </button>
      </form>

      <div class="login-footer">
        <div class="login-hint">
          Test ortamı: Herhangi bir email/şifre ile giriş yapabilirsiniz
        </div>
        <div class="login-links">
          <a href="#">Şifremi Unuttum</a>
          <a href="#">Yardım Merkezi</a>
        </div>
      </div>
    </div>
  </div>
  <script>
    // Initialize Lucide icons after DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
      lucide.createIcons();
    });
  </script>
</body>
</html>
