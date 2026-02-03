<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

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
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">
  '.$preheader.'
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;padding:0;background:'.$bg.';">
  <tr>
    <td align="center" style="padding:28px 12px;">

      <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;width:100%;">

        <!-- Header bar (bariz değişiklik) -->
        <tr>
          <td style="background:'.$accent.';padding:14px 18px;border-radius:16px 16px 0 0;">
            <div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.2;font-weight:800;color:#ffffff;">
              '.$safeApp.'
            </div>
            <div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.4;color:rgba(255,255,255,.92);margin-top:4px;">
              Şifre Yenileme Talebi
            </div>
          </td>
        </tr>

        <!-- Body card -->
        <tr>
          <td style="background:#ffffff;border:1px solid rgba(15,23,42,.10);border-top:0;border-radius:0 0 16px 16px;padding:18px 18px 20px 18px;">

            <div style="font-family:Arial,Helvetica,sans-serif;color:'.$dark.';">
              <h2 style="margin:0 0 10px 0;font-size:18px;line-height:1.25;">Şifrenizi yenileyin</h2>

              <p style="margin:0 0 14px 0;color:'.$muted.';font-size:14px;line-height:1.65;">
                Şifrenizi yenilemek için aşağıdaki butona tıklayın. Bu bağlantı <b>1 saat</b> geçerlidir.
              </p>

              <!-- Outlook-safe VML button -->
              <div style="margin:16px 0 10px 0;">
                <!--[if mso]>
                <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                  href="'.$safeLink.'"
                  style="height:44px;v-text-anchor:middle;width:220px;" arcsize="18%" strokecolor="'.$accent.'" fillcolor="'.$accent.'">
                  <w:anchorlock/>
                  <center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;">
                    Şifremi Yenile
                  </center>
                </v:roundrect>
                <![endif]-->

                <!--[if !mso]><!-- -->
                <a href="'.$safeLink.'" style="display:inline-block;background:'.$accent.';color:#ffffff;text-decoration:none;
                  padding:12px 18px;border-radius:10px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;">
                  Şifremi Yenile
                </a>
                <!--<![endif]-->
              </div>

              <p style="margin:0;color:#64748b;font-size:12px;line-height:1.6;">
                Buton çalışmazsa bu bağlantıyı kopyalayıp tarayıcıya yapıştırın:
              </p>
              <p style="margin:6px 0 0 0;color:'.$dark.';font-size:12px;line-height:1.6;word-break:break-all;">
                '.$safeLink.'
              </p>

              <div style="height:14px;"></div>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                style="background:rgba(15,23,42,.04);border:1px solid rgba(15,23,42,.08);border-radius:12px;">
                <tr>
                  <td style="padding:10px 12px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:'.$muted.';">
                    Bu isteği siz başlatmadıysanız bu e-postayı yok sayabilirsiniz. Güvenliğiniz için bağlantıyı kimseyle paylaşmayın.
                  </td>
                </tr>
              </table>

              <div style="height:14px;"></div>

              <div style="text-align:center;color:#94a3b8;font-size:12px;font-family:Arial,Helvetica,sans-serif;">
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
    .auth-row{display:flex;justify-content:flex-start;margin-top:12px}
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
            <a href="<?= e(base_url('login.php')) ?>">← Giriş sayfasına dön</a>
          </div>
        </div>
      </div>

      <div class="auth-foot">© <?= date('Y') ?> <?= e($appName) ?></div>

    </div>
  </main>
</body>
</html>
