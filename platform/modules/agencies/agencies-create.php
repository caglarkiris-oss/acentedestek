<?php
// /public_html/platform/agencies-create.php

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_agencies_create_error.log');
error_reporting(E_ALL);

require_once __DIR__.'/auth.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/db.php';

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) {
    header("Location: ".base_url("login.php")); exit;
  }
}

/* DB */
$conn = db();
if (!$conn) { http_response_code(500); exit('DB yok'); }

/* ROLE */
$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true)) {
  http_response_code(403); exit('Yetkisiz');
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

/* helpers */
function slugify($s){
  $map=['ç'=>'c','ğ'=>'g','ı'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','Ç'=>'c','Ğ'=>'g','İ'=>'i','Ö'=>'o','Ş'=>'s','Ü'=>'u'];
  $s=strtr($s,$map);
  $s=strtolower(trim($s));
  $s=preg_replace('/[^a-z0-9]+/','-',$s);
  return trim($s,'-');
}
function parse_rate($v): ?float {
  $v = str_replace(',', '.', trim((string)$v));
  if ($v==='' || !is_numeric($v)) return null;
  return (float)$v;
}

/* STATE */
$errors = [];
$old = [
  'agency_name' => '',
  'is_active' => '1',
  'work_mode' => 'ticket',
  'commission_rate' => '0',
  'admin_name' => '',
  'admin_email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $old['agency_name'] = trim($_POST['agency_name'] ?? '');
  $old['is_active']   = ($_POST['is_active'] ?? '1') === '0' ? '0' : '1';
  $old['work_mode']   = ($_POST['work_mode'] ?? 'ticket') === 'csv' ? 'csv' : 'ticket';
  $old['commission_rate'] = trim($_POST['commission_rate'] ?? '0');
  $old['admin_name']  = trim($_POST['admin_name'] ?? '');
  $old['admin_email'] = trim($_POST['admin_email'] ?? '');
  $adminPass          = trim($_POST['admin_password'] ?? '');

  if ($old['agency_name']==='') $errors[]='Acente adı zorunlu';
  if ($old['admin_name']==='')  $errors[]='Yetkili adı zorunlu';
  if (!filter_var($old['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[]='Email geçersiz';

  if (!preg_match('/^\d{4}$/', $adminPass)) {
    $errors[]='Geçici şifre 4 haneli olmalı';
  }

  $commissionRate = 0.00;
  if ($role === 'ACENTE_YETKILISI') {
    $parsed = parse_rate($old['commission_rate']);
    if ($parsed === null || $parsed < 0 || $parsed > 100) {
      $errors[]='Tali komisyon oranı 0–100 arasında olmalı';
    } else {
      $commissionRate = round($parsed,2);
    }
  }

  if (!$errors) {
    $st=$conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $st->bind_param("s",$old['admin_email']);
    $st->execute();
    if ($st->get_result()->num_rows>0) $errors[]='Bu email zaten kayıtlı';
    $st->close();
  }

  if (!$errors) {
    $parentId = ($role==='SUPERADMIN') ? 0 : $agencyId;
    $slugBase = slugify($old['agency_name']);
    $slug = $slugBase; $i=1;
    while (true) {
      $st=$conn->prepare("SELECT id FROM agencies WHERE slug=?");
      $st->bind_param("s",$slug);
      $st->execute();
      if ($st->get_result()->num_rows===0) { $st->close(); break; }
      $st->close();
      $slug = $slugBase.'-'.$i++;
    }

    $conn->begin_transaction();
    try {
      $st=$conn->prepare("
        INSERT INTO agencies
        (parent_id,name,slug,is_active,commission_rate,work_mode,created_at)
        VALUES (?,?,?,?,?,?,NOW())
      ");
      $isActiveInt=(int)$old['is_active'];
      $st->bind_param(
        "issids",
        $parentId,
        $old['agency_name'],
        $slug,
        $isActiveInt,
        $commissionRate,
        $old['work_mode']
      );
      $st->execute();
      $newAgencyId = $conn->insert_id;
      $st->close();

      $roleNew = ($role==='SUPERADMIN') ? 'ACENTE_YETKILISI' : 'TALI_ACENTE_YETKILISI';
      $hash = password_hash($adminPass,PASSWORD_DEFAULT);

      $st=$conn->prepare("
        INSERT INTO users
        (agency_id,name,email,password,must_change_password,role,is_active,created_at)
        VALUES (?,?,?,?,1,?,1,NOW())
      ");
      $st->bind_param(
        "issss",
        $newAgencyId,
        $old['admin_name'],
        $old['admin_email'],
        $hash,
        $roleNew
      );
      $st->execute();
      $st->close();

      $conn->commit();
      header("Location: agencies.php?created=1"); exit;

    } catch(Throwable $e){
      $conn->rollback();
      $errors[]='DB hata: '.$e->getMessage();
    }
  }
}

/* UI */
require_once __DIR__ . '/../../layout/header.php';
?>

<section class="page">
  <div class="card">
    <h2 class="card-title">Yeni Tali Acente Oluştur</h2>

    <?php if($errors): ?>
      <div class="alert err"><?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?></div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>

      <div class="form">
        <div class="field">
          <label>Acente Adı</label>
          <input class="input" name="agency_name" value="<?= htmlspecialchars($old['agency_name']) ?>">
        </div>

        <div class="field">
          <label>Çalışma Şekli</label>
          <select class="input" name="work_mode">
            <option value="ticket" <?= $old['work_mode']==='ticket'?'selected':'' ?>>Ticket ile çalışır</option>
            <option value="csv" <?= $old['work_mode']==='csv'?'selected':'' ?>>CSV yükler</option>
          </select>
        </div>

        <div class="field">
          <label>Durum</label>
          <select class="input" name="is_active">
            <option value="1" <?= $old['is_active']==='1'?'selected':'' ?>>Aktif</option>
            <option value="0" <?= $old['is_active']==='0'?'selected':'' ?>>Pasif</option>
          </select>
        </div>
      </div>

      <div class="form one">
        <div class="field">
          <label>Tali Komisyon Oranı (%)</label>
          <input class="input" name="commission_rate" value="<?= htmlspecialchars($old['commission_rate']) ?>">
        </div>
      </div>

      <div class="form">
        <div class="field">
          <label>Yetkili Ad Soyad</label>
          <input class="input" name="admin_name" value="<?= htmlspecialchars($old['admin_name']) ?>">
        </div>
        <div class="field">
          <label>Yetkili Email</label>
          <input class="input" name="admin_email" value="<?= htmlspecialchars($old['admin_email']) ?>">
        </div>
      </div>

      <div class="form one">
        <div class="field">
          <label>Geçici Şifre (4 hane)</label>
          <input class="input" name="admin_password" maxlength="4">
        </div>
      </div>

      <div class="actions">
        <button class="btn primary">Kaydet</button>
      </div>
    </form>
  </div>
</section>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
