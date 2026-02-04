<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

ensure_session();

// Login zorunlu
if (empty($_SESSION['user_id'])) {
  header('Location: ' . route_url('login'));
  exit;
}

// Zorunlu değilse dashboard
if ((int)($_SESSION['must_change_password'] ?? 0) !== 1) {
  header('Location: ' . base_url('dashboard.php'));
  exit;
}

$appName = (string) config('app.name', 'App');
$userId  = (int)($_SESSION['user_id'] ?? 0);
$email   = (string)($_SESSION['email'] ?? '');

$error = '';
$ok = '';

/**
 * Güçlü şifre kontrolü
 * - min 8 karakter
 * - 1 büyük, 1 küçük, 1 rakam, 1 özel
 * - yaygın şifreler engel
 * - email local part içeriyorsa engel (opsiyonel)
 */
function password_policy_check(string $pw, string $userEmail = ''): string {
  $pw = (string)$pw;

  if (strlen($pw) < 8) return 'Şifre en az 8 karakter olmalı.';
  if (!preg_match('/[a-z]/', $pw)) return 'Şifre en az 1 küçük harf içermeli.';
  if (!preg_match('/[A-Z]/', $pw)) return 'Şifre en az 1 büyük harf içermeli.';
  if (!preg_match('/[0-9]/', $pw)) return 'Şifre en az 1 rakam içermeli.';
  if (!preg_match('/[^a-zA-Z0-9]/', $pw)) return 'Şifre en az 1 özel karakter içermeli. (Örn: !@#?)';

  $lower = strtolower($pw);

  // yaygın şifreler (kısa ama etkili liste)
  $common = [
    '123456','1234567','12345678','123456789','1234567890',
    '12345','1234','111111','000000','qwerty','qwerty123',
    'password','passw0rd','admin','administrator',
    'iloveyou','welcome','letmein','abcdef','asdfgh'
  ];
  if (in_array($lower, $common, true)) return 'Bu şifre çok yaygın. Lütfen daha güçlü bir şifre seçin.';

  // email local part geçmesin (opsiyonel ama iyi)
  $userEmail = strtolower(trim($userEmail));
  if ($userEmail !== '' && strpos($userEmail, '@') !== false) {
    $local = explode('@', $userEmail)[0] ?? '';
    $local = trim($local);
    if ($local !== '' && strlen($local) >= 3 && str_contains($lower, $local)) {
      return 'Şifre, email adresinizin bir parçasını içeremez.';
    }
  }

  return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $p1 = (string)($_POST['new_password'] ?? '');
  $p2 = (string)($_POST['new_password2'] ?? '');

  if ($p1 === '' || $p2 === '') {
    $error = 'Lütfen yeni şifreyi girin.';
  } elseif ($p1 !== $p2) {
    $error = 'Şifreler uyuşmuyor.';
  } else {
    $policyError = password_policy_check($p1, $email);
    if ($policyError) {
      $error = $policyError;
    }
  }

  if ($error === '') {
    $conn = db();

    // Güvenlik: gerçekten bu kullanıcı must_change_password=1 mi? DB’den kontrol
    $stmt = mysqli_prepare($conn, "SELECT COALESCE(must_change_password,0) FROM users WHERE id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $must);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) {
      $error = 'Kullanıcı bulunamadı.';
    } elseif ((int)$must !== 1) {
      // Bayrak kapanmışsa dashboard'a
      $_SESSION['must_change_password'] = 0;
      header('Location: ' . base_url('dashboard.php'));
      exit;
    } else {
      $hash = password_hash($p1, PASSWORD_DEFAULT);

      $up = mysqli_prepare($conn, "UPDATE users SET password=?, must_change_password=0, password_changed_at=NOW() WHERE id=? LIMIT 1");
      mysqli_stmt_bind_param($up, "si", $hash, $userId);
      mysqli_stmt_execute($up);
      mysqli_stmt_close($up);

      // Session güncelle + session id yenile
      $_SESSION['must_change_password'] = 0;
      session_regenerate_id(true);

      $ok = 'Şifreniz güncellendi. Yönlendiriliyorsunuz...';
      header("Refresh: 1; url=" . base_url('dashboard.php'));
    }
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> | Şifre Belirle</title>
  <link rel="stylesheet" href="<?= e(base_url('layout/app.css')) ?>">
  
</head>
<body>
  <main class="auth-wrap">
    <div class="auth-card">
      <div class="card">
        <div class="card-head">
          <h2 class="card-title">İlk giriş: Yeni şifre belirleyin</h2>
          <div class="sub">Güvenlik için ilk girişte şifre değişikliği zorunludur.</div>

          <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="msg ok"><?= e($ok) ?></div><?php endif; ?>
        </div>

        <div class="card-body">
          <form class="form" method="post" action="">
            <?= csrf_field() ?>

            <label for="new_password">Yeni şifre</label>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>

            <label for="new_password2">Yeni şifre (tekrar)</label>
            <input id="new_password2" name="new_password2" type="password" autocomplete="new-password" required>

            <button class="btn" type="submit">Şifreyi Kaydet</button>

            <div class="hint">
              Kurallar: <b>min 10</b> karakter, <b>1 büyük</b>, <b>1 küçük</b>, <b>1 rakam</b>, <b>1 özel</b> karakter.
            </div>
          </form>
        </div>
      </div>
      <div>
        © <?= date('Y') ?> <?= e($appName) ?>
      </div>
    </div>
  </main>
</body>
</html>
