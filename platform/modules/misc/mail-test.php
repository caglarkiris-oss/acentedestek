<?php
// /public_html/platform/mail-test.php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../helpers.php';

// Buraya kendi mailini yaz (gmail olur)
$to = 'KENDI_MAILIN@gmail.com';

$subject = 'Acentedestek | SMTP Test';
$html = '<b>SMTP test</b><br>' . date('Y-m-d H:i:s');

echo "<pre>";

if (!function_exists('send_mail')) {
  echo "FAIL: send_mail() yok. helpers.php yanlış yükleniyor.\n";
  exit;
}

// config kontrol
$host = (string)config('mail.host','');
$user = (string)config('mail.username','');
$pass = (string)config('mail.password','');
$port = (string)config('mail.port','');

echo "CONFIG:\n";
echo "host: $host\nuser: $user\nport: $port\npass: " . ($pass ? "[VAR]" : "[YOK]") . "\n\n";

$ok = send_mail($to, $subject, $html);

echo $ok ? "RESULT: OK (mail gönderildi)\n" : "RESULT: FAIL (error_log'a yazıldı)\n";

echo "\nŞimdi şuraya bak:\n";
echo "1) /public_html/platform/error_log\n";
echo "2) /public_html/error_log\n"; // bazı hostinglerde buraya yazar
echo "</pre>";
