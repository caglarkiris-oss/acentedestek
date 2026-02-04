<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

ensure_session();

$appName = (string) config('app.name', 'Panel');

$msg   = '';
$err   = '';
$email = '';

/**
 * Rate limit (session bazlı)
 */
if (!isset($_SESSION['fp_tries'])) $_SESSION['fp_tries'] = 0;
if (!isset($_SESSION['fp_try_at'])) $_SESSION['fp_try_at'] = time();

function fp_rate_limited(): bool {
  $tries = (int)($_SESSION['fp_tries'] ?? 0);
  $at    = (int)($_SESSION['fp_try_at'] ?? time());
  // 10 dakikada 5 deneme
  if (time() - $at > 600) {
    $_SESSION['fp_tries'] = 0;
    $_SESSION['fp_try_at'] = time();
    return false;
  }
  return $tries >= 5;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (fp_rate_limited()) {
    $err = 'Çok fazla deneme. Lütfen 10 dakika sonra tekrar deneyin.';
  } else {

    // CSRF helpers.php’de var
    try {
      csrf_verify();
    } catch (Throwable $e) {
      $err = 'Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.';
    }

    $email = trim((string)($_POST['email'] ?? ''));

    if ($err === '') {
      if ($email === '') {
        $err = 'Email zorunludur.';
      } else {

        // Güvenlik: var/yok söyleme
        $msg = 'Eğer bu email sistemde kayıtlıysa, şifre yenileme bağlantısı gönderilecektir.';

        $conn = db();

        $uid = 0; $is_active = 0;

        $st = mysqli_prepare($conn, "SELECT id, is_active FROM users WHERE email=? LIMIT 1");
        if ($st) {
          mysqli_stmt_bind_param($st, "s", $email);
          mysqli_stmt_execute($st);
          mysqli_stmt_bind_result($st, $uid, $is_active);
          mysqli_stmt_fetch($st);
          mysqli_stmt_close($st);
        }

        if ((int)$uid > 0 && (int)$is_active === 1) {
          $token      = bin2hex(random_bytes(32));
          $token_hash = password_hash($token, PASSWORD_DEFAULT);
          $expires_at = date('Y-m-d H:i:s', time() + 3600);

          mysqli_query($conn, "UPDATE password_resets SET used_at=NOW() WHERE user_id=".(int)$uid." AND used_at IS NULL");

          $ins = mysqli_prepare($conn, "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)");
          if ($ins) {
            mysqli_stmt_bind_param($ins, "iss", $uid, $token_hash, $expires_at);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);

            $resetLink = base_url("reset-password.php?token=" . urlencode($token) . "&email=" . urlencode($email));

            // OUTLOOK THREAD kır: her mail benzersiz konu
            $subject = $appName . ' | Şifre Yenileme #' . date('His');

            // Kurumsal HTML (Outlook uyumlu)
            $safeApp  = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

            $preheader = 'Şifre yenileme bağlantınız (1 saat geçerli).';

            // Accent renkler (Outlook en stabil: düz renk)
            $accent = '#1D4ED8'; // kurumsal mavi
            $dark   = '#0F172A';
            $muted  = '#475569';
            $bg     = '#F6F8FC';

            $html = '
<!-- Preheader (inbox preview) -->
<div>
  '.$preheader.'
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr>
    <td align="center">

      <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0">

        <!-- Header bar (bariz değişiklik) -->
        <tr>
          <td>
            <div>
              '.$safeApp.'
            </div>
            <div>
              Şifre Yenileme Talebi
            </div>
          </td>
        </tr>

        <!-- Body card -->
        <tr>
          <td>

            <div>
              <h2>Şifrenizi yenileyin</h2>

              <p>
                Şifrenizi yenilemek için aşağıdaki butona tıklayın. Bu bağlantı <b>1 saat</b> geçerlidir.
              </p>

              <!-- Outlook-safe VML button -->
              <div>
                <!--[if mso]>
                <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                  href="'.$safeLink.'"
                  arcsize="18%" strokecolor="'.$accent.'" fillcolor="'.$accent.'">
                  <w:anchorlock/>
                  <center>
                    Şifremi Yenile
                  </center>
                </v:roundrect>
                <![endif]-->

                <!--[if !mso]><!-- -->
                <a href="'.$safeLink.'">
                  Şifremi Yenile
                </a>
                <!--<![endif]-->
              </div>

              <p>
                Buton çalışmazsa bu bağlantıyı kopyalayıp tarayıcıya yapıştırın:
              </p>
              <p>
                '.$safeLink.'
              </p>

              <div></div>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               >
                <tr>
                  <td>
                    Bu isteği siz başlatmadıysanız bu e-postayı yok sayabilirsiniz. Güvenliğiniz için bağlantıyı kimseyle paylaşmayın.
                  </td>
                </tr>
              </table>

              <div></div>

              <div>
                © '.date('Y').' '.$safeApp.'
              </div>
            </div>

          </td>
        </tr>

      </table>

    </td>
  </tr>
</table>
';

            // Mail gönder
            @send_mail($email, $subject, $html);
          }
        }
      }
    }

    if ($err !== '') {
      $_SESSION['fp_tries'] = (int)($_SESSION['fp_tries'] ?? 0) + 1;
    }
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> | Şifremi Unuttum</title>

  <link rel="stylesheet" href="<?= e(base_url('layout/app.css')) ?>">

  
</head>

<body>
  <main class="auth-wrap">
    <div class="auth-card">

      <div class="card">
        <div class="card-head">
          <h2 class="card-title">Şifremi Unuttum</h2>
          <div class="auth-sub">Email adresinizi yazın. Uygunsa size yenileme bağlantısı gönderilecek.</div>

          <?php if ($err): ?><div class="auth-err"><?= e($err) ?></div><?php endif; ?>
          <?php if ($msg): ?><div class="auth-msg"><?= e($msg) ?></div><?php endif; ?>
        </div>

        <div class="card-body">
          <form class="auth-form" method="post" action="">
            <?= csrf_field() ?>

            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($email) ?>">

            <button class="auth-btn" type="submit">Yenileme Bağlantısı Gönder</button>
          </form>

          <div class="auth-row">
            <a href="<?= e(route_url('login')) ?>">← Giriş sayfasına dön</a>
          </div>
        </div>
      </div>

      <div class="auth-foot">© <?= date('Y') ?> <?= e($appName) ?></div>

    </div>
  </main>
</body>
</html>
