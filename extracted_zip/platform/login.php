<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

ensure_session();

// Zaten girişliyse (force gerekiyorsa force'a yolla)
if (!empty($_SESSION['user_id'])) {
  $must = (int)($_SESSION['must_change_password'] ?? 0);
  if ($must === 1) {
    header('Location: ' . base_url('force-password-change.php'));
    exit;
  }
  header('Location: ' . base_url('dashboard.php'));
  exit;
}

$appName = (string) config('app.name', 'App');

$error = '';
$email = '';

$ALLOWED_ROLES = ['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];

function is_password_hash(string $s): bool {
  return (strpos($s, '$2y$') === 0 || strpos($s, '$2a$') === 0 || strpos($s, '$argon2') === 0);
}

/* =========================
   IP TESPİTİ
========================= */
function client_ip(): string {
  $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'];
  foreach ($keys as $k) {
    $v = (string)($_SERVER[$k] ?? '');
    if (!$v) continue;
    if ($k === 'HTTP_X_FORWARDED_FOR') {
      $parts = array_map('trim', explode(',', $v));
      $v = $parts[0] ?? '';
    }
    if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
  }
  return '0.0.0.0';
}

/* =========================
   DOSYA BAZLI KİLİT (DB YOK)
   - 3 yanlış => 5 dk kilit
   - IP + EMAIL bazlı
========================= */
function rl_dir(): string {
  $dir = __DIR__ . '/storage/rl';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  return $dir;
}
function rl_norm_email(string $email): string {
  $email = strtolower(trim($email));
  if ($email === '') return '__generic__';
  return $email;
}
function rl_key(string $ip, string $email): string {
  $email = rl_norm_email($email);
  return hash('sha256', $ip . '|' . $email);
}
function rl_file(string $ip, string $email): string {
  return rtrim(rl_dir(), '/\\') . '/' . rl_key($ip, $email) . '.json';
}
function rl_load(string $ip, string $email): array {
  $file = rl_file($ip, $email);
  if (!is_file($file)) {
    return ['tries' => 0, 'lock_until' => 0];
  }
  $raw = (string)@file_get_contents($file);
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];
  $data['tries'] = (int)($data['tries'] ?? 0);
  $data['lock_until'] = (int)($data['lock_until'] ?? 0);

  if ($data['lock_until'] > 0 && time() >= $data['lock_until']) {
    $data['tries'] = 0;
    $data['lock_until'] = 0;
    @unlink($file);
  }
  return $data;
}
function rl_save(string $ip, string $email, array $data): void {
  $file = rl_file($ip, $email);
  @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function rl_clear(string $ip, string $email): void {
  $file = rl_file($ip, $email);
  if (is_file($file)) @unlink($file);
}
function rl_is_locked(array $data): bool {
  return (int)($data['lock_until'] ?? 0) > time();
}
function rl_register_fail(string $ip, string $email, array $data): array {
  $data['tries'] = (int)($data['tries'] ?? 0) + 1;
  if ($data['tries'] >= 3) {
    $data['lock_until'] = time() + 300;
  }
  rl_save($ip, $email, $data);
  return $data;
}

/* =========================
   LOGIN AKIŞI
========================= */
$ip = client_ip();

$rl_generic = rl_load($ip, '__generic__');
if (rl_is_locked($rl_generic)) {
  $mins = (int)ceil((($rl_generic['lock_until'] ?? time()) - time()) / 60);
  if ($mins < 1) $mins = 1;
  $error = "Çok fazla deneme yapıldı. Lütfen {$mins} dakika sonra tekrar deneyin.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
  csrf_verify();

  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  $rl = rl_load($ip, $email);

  if (rl_is_locked($rl)) {
    $mins = (int)ceil((($rl['lock_until'] ?? time()) - time()) / 60);
    if ($mins < 1) $mins = 1;
    $error = "Çok fazla deneme yapıldı. Lütfen {$mins} dakika sonra tekrar deneyin.";
  } else {

    if ($email === '' || $pass === '') {
      $error = 'Email veya şifre hatalı.';
      $rl = rl_register_fail($ip, $email, $rl);
    } else {
      $conn = db();

      // ✅ must_change_password eklendi
      $stmt = mysqli_prepare($conn, "SELECT id, agency_id, name, email, password, role, is_active, COALESCE(must_change_password,0) AS must_change_password FROM users WHERE email=? LIMIT 1");
      if (!$stmt) {
        $error = 'Sistem hatası.';
      } else {
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $id, $agency_id, $name, $uemail, $stored, $roleRaw, $is_active, $mustChange);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if (!$found || (int)$is_active !== 1) {
          $error = 'Email veya şifre hatalı.';
          $rl = rl_register_fail($ip, $email, $rl);
        } else {
          $stored = (string)$stored;
          $ok = false;

          if (is_password_hash($stored)) {
            $ok = password_verify($pass, $stored);
          } else {
            $ok = ($pass === $stored);
            if ($ok) {
              $newHash = password_hash($pass, PASSWORD_DEFAULT);
              $up = mysqli_prepare($conn, "UPDATE users SET password=? WHERE id=? LIMIT 1");
              if ($up) {
                mysqli_stmt_bind_param($up, "si", $newHash, $id);
                mysqli_stmt_execute($up);
                mysqli_stmt_close($up);
              }
            }
          }

          if (!$ok) {
            $error = 'Email veya şifre hatalı.';
            $rl = rl_register_fail($ip, $email, $rl);
          } else {
            rl_clear($ip, $email);
            rl_clear($ip, '__generic__');

            $role = strtoupper(trim((string)$roleRaw));
            if (!in_array($role, $ALLOWED_ROLES, true)) $role = 'PERSONEL';

            session_regenerate_id(true);
            $_SESSION['user_id']   = (int)$id;
            $_SESSION['agency_id'] = (int)$agency_id;
            $_SESSION['name']      = (string)$name;
            $_SESSION['email']     = (string)$uemail;
            $_SESSION['role']      = $role;

            // ✅ burada session’a yazıyoruz
            $_SESSION['must_change_password'] = (int)$mustChange;

            // ✅ login olur olmaz force'a yönlendir
            if ((int)$mustChange === 1) {
              header('Location: ' . base_url('force-password-change.php'));
              exit;
            }

            header('Location: ' . base_url('dashboard.php'));
            exit;
          }
        }
      }
    }

    $rl2 = rl_load($ip, $email);
    if (rl_is_locked($rl2)) {
      $mins = (int)ceil((($rl2['lock_until'] ?? time()) - time()) / 60);
      if ($mins < 1) $mins = 1;
      $error = "Çok fazla deneme yapıldı. Lütfen {$mins} dakika sonra tekrar deneyin.";
    }
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> | Giriş</title>
  <link rel="stylesheet" href="<?= e(base_url('layout/theme.css')) ?>">
  <style>
    body{min-height:100vh}
    .auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
    .auth-card{width:100%;max-width:420px}
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
    .auth-err{
      margin-top:12px;padding:10px 12px;border-radius:12px;font-size:13px;border:1px solid;
      background: rgba(220,38,38,.06); border-color: rgba(220,38,38,.25); color:#991b1b
    }
    .auth-row{display:flex;justify-content:flex-end;margin-top:10px}
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
          <h2 class="card-title"><?= e($appName) ?> Giriş</h2>
          <div class="auth-sub">Devam etmek için giriş yapın</div>
          <?php if ($error): ?><div class="auth-err"><?= e($error) ?></div><?php endif; ?>
        </div>

        <div class="card-body">
          <form class="auth-form" method="post" action="">
            <?= csrf_field() ?>

            <label for="email">Email</label>
            <input id="email" name="email" type="text" value="<?= e($email) ?>" autocomplete="username" required>

            <label for="password">Şifre</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <div class="auth-row">
              <a href="<?= e(base_url('forgot-password.php')) ?>">Şifremi unuttum</a>
            </div>

            <button class="auth-btn" type="submit">Giriş Yap</button>
          </form>
        </div>
      </div>

      <div class="auth-foot">© <?= date('Y') ?> <?= e($appName) ?></div>
    </div>
  </main>
</body>
</html>
