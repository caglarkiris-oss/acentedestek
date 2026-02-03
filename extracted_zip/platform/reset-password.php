<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

ensure_session();

$appName = (string) config('app.name', 'Panel');

$token = trim((string)($_GET['token'] ?? ''));
$email = trim((string)($_GET['email'] ?? ''));

$err = '';
$msg = '';

/* CSRF uyumu */
$has_csrf_helpers = function_exists('csrf_field') && function_exists('csrf_verify');
if (!$has_csrf_helpers && empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

/* Token doğrulama */
function find_reset(mysqli $conn, string $email, string $token): ?array {
  $uid = 0; $is_active = 0;
  $st = mysqli_prepare($conn, "SELECT id, is_active FROM users WHERE email=? LIMIT 1");
  if (!$st) return null;

  mysqli_stmt_bind_param($st, "s", $email);
  mysqli_stmt_execute($st);
  mysqli_stmt_bind_result($st, $uid, $is_active);
  mysqli_stmt_fetch($st);
  mysqli_stmt_close($st);

  if ((int)$uid <= 0 || (int)$is_active !== 1) return null;

  $rid = 0; $token_hash = ''; $expires_at = ''; $used_at = null;

  $q = mysqli_prepare($conn, "
    SELECT id, token_hash, expires_at, used_at
    FROM password_resets
    WHERE user_id=?
    ORDER BY id DESC
    LIMIT 10
  ");
  if (!$q) return null;

  mysqli_stmt_bind_param($q, "i", $uid);
  mysqli_stmt_execute($q);
  mysqli_stmt_bind_result($q, $rid, $token_hash, $expires_at, $used_at);

  $found = null;
  while (mysqli_stmt_fetch($q)) {
    if ($used_at !== null) continue;
    if (strtotime((string)$expires_at) < time()) continue;
    if (password_verify($token, (string)$token_hash)) {
      $found = ['reset_id' => (int)$rid, 'user_id' => (int)$uid];
      break;
    }
  }
  mysqli_stmt_close($q);

  return $found;
}

/* ✅ Şifre Politikası (server-side) */
function validate_password_policy(string $pw): ?string {
  $pw = trim($pw);

  if (mb_strlen($pw) < 8) {
    return 'Şifre en az 8 karakter olmalı.';
  }
  if (!preg_match('/[A-Z]/', $pw)) {
    return 'Şifre en az 1 büyük harf içermeli.';
  }
  if (!preg_match('/[a-z]/', $pw)) {
    return 'Şifre en az 1 küçük harf içermeli.';
  }
  if (!preg_match('/[0-9]/', $pw)) {
    return 'Şifre en az 1 rakam içermeli.';
  }

  return null; // OK
}

/* İlk yüklemede link kontrolü */
if ($token === '' || $email === '') {
  $err = 'Geçersiz bağlantı.';
}

/* Form post */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {

  // CSRF
  if ($has_csrf_helpers) {
    try { csrf_verify(); }
    catch (Throwable $e) { $err = 'Güvenlik doğrulaması başarısız.'; }
  } else {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!$csrf || !hash_equals($_SESSION['csrf_token'], $csrf)) {
      $err = 'Güvenlik doğrulaması başarısız.';
    }
  }

  $new1 = (string)($_POST['password'] ?? '');
  $new2 = (string)($_POST['password2'] ?? '');

  if ($err === '') {

    $policyError = validate_password_policy($new1);

    if ($policyError) {
      $err = $policyError;
    } elseif ($new1 !== $new2) {
      $err = 'Şifreler aynı değil.';
    } else {
      $conn = db();
      $info = find_reset($conn, $email, $token);

      if (!$info) {
        $err = 'Geçersiz bağlantı.';
      } else {
        $hash = password_hash($new1, PASSWORD_DEFAULT);

        $up = mysqli_prepare($conn, "UPDATE users SET password=? WHERE id=? LIMIT 1");
        if (!$up) {
          $err = 'Sistem hatası.';
        } else {
          mysqli_stmt_bind_param($up, "si", $hash, $info['user_id']);
          mysqli_stmt_execute($up);
          mysqli_stmt_close($up);

          $mk = mysqli_prepare($conn, "UPDATE password_resets SET used_at=NOW() WHERE id=? LIMIT 1");
          if ($mk) {
            mysqli_stmt_bind_param($mk, "i", $info['reset_id']);
            mysqli_stmt_execute($mk);
            mysqli_stmt_close($mk);
          }

          $msg = 'Şifreniz güncellendi. Giriş sayfasına yönlendiriliyorsunuz...';
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> | Şifre Yenile</title>

  <link rel="stylesheet" href="<?= e(base_url('layout/theme.css')) ?>">

  <style>
    body{min-height:100vh}
    .auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
    .auth-card{width:100%;max-width:460px}
    .auth-sub{color:var(--muted,#64748b);font-size:13px;margin-top:6px}
    .auth-form label{display:block;font-size:12px;font-weight:700;margin:12px 0 6px}
    .auth-form input{
      width:100%;height:42px;padding:10px 12px;
      border:1px solid var(--stroke, rgba(15,23,42,.10));
      border-radius:12px;font-size:14px;outline:none;background:#fff;
    }
    .auth-form input:focus{box-shadow:0 0 0 4px rgba(29,78,216,.10);border-color:rgba(29,78,216,.35)}
    .auth-btn{
      width:100%;height:42px;border:0;border-radius:12px;
      background: linear-gradient(90deg, var(--accent,#1D4ED8), var(--accent2,#0EA5E9));
      color:#fff;font-weight:800;cursor:pointer;margin-top:14px
    }
    .auth-msg,.auth-err{
      margin-top:12px;padding:10px 12px;border-radius:12px;font-size:13px;border:1px solid;
    }
    .auth-msg{background: rgba(22,163,74,.06); border-color: rgba(22,163,74,.25); color:#065f46}
    .auth-err{background: rgba(220,38,38,.06); border-color: rgba(220,38,38,.25); color:#991b1b}
    .auth-row{margin-top:12px}
    .auth-row a{font-size:12px;color:var(--muted,#475569);text-decoration:none}
    .auth-row a:hover{text-decoration:underline}
    .auth-foot{margin-top:14px;text-align:center;color:var(--muted2,#64748b);font-size:12px}
  </style>
</head>

<body>
  <main class="auth-wrap">
    <div class="auth-card">

      <div class="card">
        <div class="card-head">
          <h2 class="card-title">Şifre Yenile</h2>
          <div class="auth-sub">Yeni şifrenizi belirleyin.</div>

          <?php if ($err): ?><div class="auth-err"><?= e($err) ?></div><?php endif; ?>
          <?php if ($msg): ?><div class="auth-msg"><?= e($msg) ?></div><?php endif; ?>
        </div>

        <div class="card-body">
          <?php if (!$msg && !$err): ?>
          <form class="auth-form" method="post">
            <?php if ($has_csrf_helpers): ?>
              <?= csrf_field() ?>
            <?php else: ?>
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_token']) ?>">
            <?php endif; ?>

            <label>Yeni Şifre</label>
            <input name="password" type="password" autocomplete="new-password" minlength="8" required>

            <label>Yeni Şifre (Tekrar)</label>
            <input name="password2" type="password" autocomplete="new-password" minlength="8" required>

            <button class="auth-btn" type="submit">Şifreyi Güncelle</button>
          </form>
          <?php endif; ?>

          <div class="auth-row">
            <a href="<?= e(base_url('login.php')) ?>">← Giriş sayfasına dön</a>
          </div>
        </div>
      </div>

      <div class="auth-foot">© <?= date('Y') ?> <?= e($appName) ?></div>

    </div>
  </main>

<?php if ($msg): ?>
<script>
  setTimeout(function () {
    window.location.href = "<?= e(base_url('login.php')) ?>";
  }, 2500);
</script>
<?php endif; ?>

</body>
</html>
