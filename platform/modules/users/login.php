<?php
// /platform/modules/users/login.php
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
    $allowedRoles = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'ACENTE_YETKILISI';
    }
    $agency_id = (int)($_POST['agency_id'] ?? 1);

    // Test login - gerçek sistemde users tablosundan doğrulama yapılır
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
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  
  <!-- Lucide Icons -->
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    
    :root {
      --primary-900: #0f172a;
      --primary-800: #1e293b;
      --primary-700: #334155;
      --accent-600: #2563eb;
      --accent-500: #3b82f6;
      --bg-page: #f6f8fb;
      --bg-card: #ffffff;
      --border: #e5e7eb;
      --text-primary: #0f172a;
      --text-muted: #64748b;
      --danger: #dc2626;
      --danger-light: rgba(220, 38, 38, 0.08);
      --danger-border: rgba(220, 38, 38, 0.2);
      --shadow-xl: 0 20px 25px rgba(15, 23, 42, 0.10), 0 10px 10px rgba(15, 23, 42, 0.04);
      --shadow-2xl: 0 25px 50px rgba(15, 23, 42, 0.15);
      --radius-lg: 12px;
      --radius-xl: 16px;
      --radius-2xl: 20px;
    }
    
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      font-size: 14px;
      line-height: 1.5;
      color: var(--text-primary);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: 
        linear-gradient(135deg, rgba(15, 23, 42, 0.04) 0%, transparent 50%),
        linear-gradient(225deg, rgba(37, 99, 235, 0.06) 0%, transparent 50%),
        radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.08) 0%, transparent 60%),
        var(--bg-page);
    }
    
    .login-container {
      width: 100%;
      max-width: 440px;
    }
    
    .login-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius-2xl);
      box-shadow: var(--shadow-2xl);
      padding: 40px;
      position: relative;
      overflow: hidden;
    }
    
    .login-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--primary-900) 0%, var(--accent-600) 100%);
    }
    
    .login-logo {
      width: 64px;
      height: 64px;
      margin: 0 auto 24px;
      background: linear-gradient(135deg, var(--primary-800) 0%, var(--primary-900) 100%);
      border-radius: var(--radius-xl);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      box-shadow: 0 10px 25px rgba(15, 23, 42, 0.2);
    }
    
    .login-logo i {
      width: 32px;
      height: 32px;
    }
    
    .login-title {
      font-size: 24px;
      font-weight: 700;
      text-align: center;
      color: var(--text-primary);
      margin-bottom: 8px;
      letter-spacing: -0.02em;
    }
    
    .login-subtitle {
      font-size: 14px;
      color: var(--text-muted);
      text-align: center;
      margin-bottom: 32px;
    }
    
    .error-msg {
      padding: 12px 16px;
      background: var(--danger-light);
      border: 1px solid var(--danger-border);
      border-radius: var(--radius-lg);
      color: #991b1b;
      font-size: 13px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .error-msg i {
      width: 18px;
      height: 18px;
      flex-shrink: 0;
    }
    
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: var(--primary-700);
      margin-bottom: 8px;
    }
    
    .form-control {
      width: 100%;
      padding: 12px 16px;
      font-family: inherit;
      font-size: 14px;
      color: var(--text-primary);
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      outline: none;
      transition: all 0.15s ease;
    }
    
    .form-control:hover {
      border-color: #cbd5e1;
    }
    
    .form-control:focus {
      border-color: var(--accent-500);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }
    
    .form-control::placeholder {
      color: #94a3b8;
    }
    
    select.form-control {
      cursor: pointer;
      padding-right: 40px;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 12px center;
      background-size: 18px;
      -webkit-appearance: none;
      -moz-appearance: none;
      appearance: none;
    }
    
    .btn-login {
      width: 100%;
      padding: 14px 20px;
      font-family: inherit;
      font-size: 15px;
      font-weight: 600;
      color: #fff;
      background: linear-gradient(180deg, var(--primary-800) 0%, var(--primary-900) 100%);
      border: none;
      border-radius: var(--radius-lg);
      cursor: pointer;
      transition: all 0.15s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      margin-top: 8px;
    }
    
    .btn-login:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 25px rgba(15, 23, 42, 0.2);
    }
    
    .btn-login:active {
      transform: translateY(0);
    }
    
    .btn-login i {
      width: 18px;
      height: 18px;
    }
    
    .login-footer {
      margin-top: 24px;
      text-align: center;
    }
    
    .hint {
      font-size: 12px;
      color: var(--text-muted);
      padding: 12px 16px;
      background: rgba(15, 23, 42, 0.03);
      border-radius: var(--radius-lg);
      border: 1px dashed var(--border);
    }
    
    .login-links {
      margin-top: 16px;
      display: flex;
      justify-content: center;
      gap: 20px;
    }
    
    .login-links a {
      font-size: 13px;
      color: var(--text-muted);
      text-decoration: none;
      transition: color 0.15s ease;
    }
    
    .login-links a:hover {
      color: var(--accent-600);
    }
    
    /* Two column row for test fields */
    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    
    @media (max-width: 480px) {
      .login-card {
        padding: 32px 24px;
      }
      
      .form-row {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="login-card">
      <!-- Logo -->
      <div class="login-logo">
        <i data-lucide="shield-check"></i>
      </div>
      
      <!-- Title -->
      <h1 class="login-title"><?= h($appName) ?></h1>
      <p class="login-subtitle">Sigorta & Mutabakat Yönetim Sistemi</p>
      
      <!-- Error Message -->
      <?php if ($error): ?>
        <div class="error-msg">
          <i data-lucide="alert-circle"></i>
          <?= h($error) ?>
        </div>
      <?php endif; ?>

      <!-- Login Form -->
      <form method="post">
        <?= csrf_field() ?>
        
        <div class="form-group">
          <label class="form-label">Email Adresi</label>
          <input type="email" name="email" class="form-control" placeholder="ornek@sirket.com" required autocomplete="email">
        </div>

        <div class="form-group">
          <label class="form-label">Şifre</label>
          <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
        </div>

        <!-- Test Fields (can be removed in production) -->
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Rol (Test)</label>
            <select name="role" class="form-control">
              <option value="ACENTE_YETKILISI">Ana Acente</option>
              <option value="TALI_ACENTE_YETKILISI">Tali Acente</option>
              <option value="PERSONEL">Personel</option>
              <option value="SUPERADMIN">Superadmin</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Agency ID (Test)</label>
            <input type="number" name="agency_id" class="form-control" value="1" min="1">
          </div>
        </div>

        <button type="submit" class="btn-login">
          <i data-lucide="log-in"></i>
          Giriş Yap
        </button>
      </form>

      <!-- Footer -->
      <div class="login-footer">
        <p class="hint">Test ortamı: Herhangi bir email/şifre kombinasyonu ile giriş yapabilirsiniz.</p>
        
        <div class="login-links">
          <a href="#">Şifremi Unuttum</a>
          <a href="#">Yardım</a>
        </div>
      </div>
    </div>
  </div>

  <script>
    lucide.createIcons();
  </script>
</body>
</html>
