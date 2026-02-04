<?php
// /public_html/platform/agencies.php

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ✅ TR TIMEZONE */
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_agency_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

/* ---------- AUTH ---------- */
if (function_exists('require_login')) {
  require_login();
} else {
  if (!isset($_SESSION['user_id'])) {
    header("Location: " . base_url("login.php"));
    exit;
  }
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

if (!in_array($role, ['SUPERADMIN','ACENTE_YETKILISI'], true)) {
  http_response_code(403);
  exit('Yetkisiz');
}

/* ---------- DB ---------- */
$conn = null;
if (isset($GLOBALS['conn']) && $GLOBALS['conn']) $conn = $GLOBALS['conn'];
if (!$conn && function_exists('db')) $conn = db();
if (!$conn) { http_response_code(500); exit('DB bağlantısı yok'); }
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

/* agency_id fallback */
if ($role === 'ACENTE_YETKILISI' && $agencyId === 0 && $userId > 0) {
  $st = mysqli_prepare($conn, "SELECT agency_id FROM users WHERE id=? LIMIT 1");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $userId);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $tmpAgencyId);
    mysqli_stmt_fetch($st);
    mysqli_stmt_close($st);
    $agencyId = (int)($tmpAgencyId ?? 0);
    $_SESSION['agency_id'] = $agencyId;
  }
}

/* ---------- CSRF ---------- */
if (empty($_SESSION['_csrf'])) {
  $_SESSION['_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['_csrf'];

/* ---------- helpers ---------- */
function parse_rate($v): ?float {
  $v = trim((string)$v);
  if ($v === '') return null;
  $v = str_replace(',', '.', $v);
  if (!is_numeric($v)) return null;
  return (float)$v;
}

$flash = null;

/* ---------- TOGGLE ACTIVE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
  $toggleId = (int)($_POST['toggle_id'] ?? 0);
  $token    = (string)($_POST['_csrf'] ?? '');

  if (!hash_equals($csrf, $token)) {
    $flash = ['type'=>'bad', 'msg'=>'Güvenlik doğrulaması başarısız (CSRF).'];
  } elseif ($toggleId <= 0) {
    $flash = ['type'=>'bad', 'msg'=>'Geçersiz kayıt.'];
  } else {
    if ($role === 'SUPERADMIN') {
      $st = mysqli_prepare($conn, "
        UPDATE agencies
        SET is_active = CASE WHEN COALESCE(is_active,1)=1 THEN 0 ELSE 1 END
        WHERE id=? LIMIT 1
      ");
      if ($st) {
        mysqli_stmt_bind_param($st, "i", $toggleId);
        $ok = mysqli_stmt_execute($st);
        $errNo = (int)mysqli_stmt_errno($st);
        mysqli_stmt_close($st);

        $flash = ($ok && $errNo === 0)
          ? ['type'=>'good','msg'=>'Durum güncellendi.']
          : ['type'=>'bad','msg'=>'DB hatası (toggle).'];
      } else {
        $flash = ['type'=>'bad','msg'=>'DB hatası (toggle).'];
      }
    } else {
      $st = mysqli_prepare($conn, "
        UPDATE agencies
        SET is_active = CASE WHEN COALESCE(is_active,1)=1 THEN 0 ELSE 1 END
        WHERE id=? AND COALESCE(parent_id,0)=?
        LIMIT 1
      ");
      if ($st) {
        mysqli_stmt_bind_param($st, "ii", $toggleId, $agencyId);
        $ok = mysqli_stmt_execute($st);
        $errNo = (int)mysqli_stmt_errno($st);
        $aff = (int)mysqli_stmt_affected_rows($st);
        mysqli_stmt_close($st);

        // ✅ aff=0 olsa bile (aynı state olabilir) hata sayma
        if ($ok && $errNo === 0) {
          $flash = ['type'=>'good','msg'=> ($aff>0 ? 'Durum güncellendi.' : 'Durum zaten aynıydı.') ];
        } else {
          $flash = ['type'=>'bad','msg'=>'Kayıt bulunamadı veya yetkin yok.'];
        }
      } else {
        $flash = ['type'=>'bad','msg'=>'DB hatası (toggle).'];
      }
    }
  }
}

/* ---------- UPDATE COMMISSION RATE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rate_id'])) {
  $rateId = (int)($_POST['rate_id'] ?? 0);
  $token  = (string)($_POST['_csrf'] ?? '');
  $rateIn = (string)($_POST['commission_rate'] ?? '');

  if (!hash_equals($csrf, $token)) {
    $flash = ['type'=>'bad', 'msg'=>'Güvenlik doğrulaması başarısız (CSRF).'];
  } elseif ($rateId <= 0) {
    $flash = ['type'=>'bad', 'msg'=>'Geçersiz kayıt.'];
  } else {
    $rate = parse_rate($rateIn);
    if ($rate === null || $rate < 0 || $rate > 100) {
      $flash = ['type'=>'bad', 'msg'=>'Komisyon oranı 0-100 arası olmalı. (Örn: 12,5)'];
    } else {
      $rate = round($rate, 2);

      if ($role === 'SUPERADMIN') {
        $st = mysqli_prepare($conn, "UPDATE agencies SET commission_rate=? WHERE id=? LIMIT 1");
        if ($st) {
          mysqli_stmt_bind_param($st, "di", $rate, $rateId);
          $ok = mysqli_stmt_execute($st);
          $errNo = (int)mysqli_stmt_errno($st);
          $aff = (int)mysqli_stmt_affected_rows($st);
          mysqli_stmt_close($st);

          if ($ok && $errNo === 0) {
            $flash = ['type'=>'good','msg'=> ($aff>0 ? 'Komisyon güncellendi.' : 'Komisyon zaten aynıydı.') ];
          } else {
            $flash = ['type'=>'bad','msg'=>'DB hatası (rate).'];
          }
        } else {
          $flash = ['type'=>'bad','msg'=>'DB hatası (rate).'];
        }
      } else {
        $st = mysqli_prepare($conn, "UPDATE agencies SET commission_rate=? WHERE id=? AND COALESCE(parent_id,0)=? LIMIT 1");
        if ($st) {
          mysqli_stmt_bind_param($st, "dii", $rate, $rateId, $agencyId);
          $ok = mysqli_stmt_execute($st);
          $errNo = (int)mysqli_stmt_errno($st);
          $aff = (int)mysqli_stmt_affected_rows($st);
          mysqli_stmt_close($st);

          // ✅ KRİTİK FIX: aff=0 ise “yetkin yok” DEME
          if ($ok && $errNo === 0) {
            $flash = ['type'=>'good','msg'=> ($aff>0 ? 'Komisyon güncellendi.' : 'Komisyon zaten aynıydı (kayıtlı).') ];
          } else {
            $flash = ['type'=>'bad','msg'=>'Yetkin yok veya kayıt bulunamadı.'];
          }
        } else {
          $flash = ['type'=>'bad','msg'=>'DB hatası (rate).'];
        }
      }
    }
  }
}

/* ---------- DATA ---------- */
$rows = [];

if ($role === 'SUPERADMIN') {
  $sql = "
    SELECT id, COALESCE(parent_id,0) AS parent_id, name,
           COALESCE(is_active,1) AS is_active, created_at,
           COALESCE(commission_rate,0) AS commission_rate
    FROM agencies
    ORDER BY created_at DESC, id DESC
  ";
  $st = mysqli_prepare($conn, $sql);
} else {
  $sql = "
    SELECT id, COALESCE(parent_id,0) AS parent_id, name,
           COALESCE(is_active,1) AS is_active, created_at,
           COALESCE(commission_rate,0) AS commission_rate
    FROM agencies
    WHERE COALESCE(parent_id,0)=?
    ORDER BY created_at DESC, id DESC
  ";
  $st = mysqli_prepare($conn, $sql);
  if ($st) mysqli_stmt_bind_param($st, "i", $agencyId);
}

if (!$st) {
  error_log("agencies.php prepare failed: " . mysqli_error($conn));
  http_response_code(500);
  exit("DB hatası.");
}

mysqli_stmt_execute($st);
mysqli_stmt_bind_result($st, $id, $parent_id, $name, $is_active, $created_at, $commission_rate);

while (mysqli_stmt_fetch($st)) {
  $rows[] = [
    'id' => (int)$id,
    'parent_id' => (int)$parent_id,
    'name' => (string)$name,
    'is_active' => (int)$is_active,
    'created_at' => (string)$created_at,
    'commission_rate' => (float)$commission_rate,
  ];
}
mysqli_stmt_close($st);

/* ---------- UI ---------- */
$page_title = ($role === 'SUPERADMIN') ? 'Acenteler' : 'Tali Acenteler';
$pageTitle = 'Acenteler';
$currentPage = 'agencies';
require_once __DIR__ . '/../../layout/header.php';

$title     = $page_title;
$subtitle  = ($role === 'SUPERADMIN')
  ? 'Tüm acenteleri görürsün.'
  : 'Sadece kendi acentene bağlı tali acenteleri görürsün.';
$createUrl = base_url('agencies-create.php');
?>

<section class="page">
  <section class="card">



<div class="u-flex u-between u-center u-gap-3">
  <div>
    <h2><?= e($title) ?></h2>
    <div class="small"><?= e($subtitle) ?></div>
  </div>

  <a href="<?= e($createUrl) ?>" class="btn primary">
    + <?= ($role === 'SUPERADMIN') ? 'Yeni Acente' : 'Yeni Tali Acente' ?>
  </a>
</div>

<?php if ($flash): ?>
  <div class="alert <?= ($flash['type']==='good' ? 'ok' : 'err') ?>">
    <?= e($flash['msg']) ?>
  </div>
<?php endif; ?>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Ad</th>
            <th>Komisyon (%)</th>
            <th>Durum</th>
            <th>Oluşturma</th>
            <th>İşlem</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= $r['id'] ?></td>
            <td><?= e($r['name']) ?></td>

            <td>
              <form method="post" class="rateBox">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="rate_id" value="<?= (int)$r['id'] ?>">
                <input class="rateInput" name="commission_rate" inputmode="decimal"
                       value="<?= e(number_format((float)$r['commission_rate'], 2, ',', '')) ?>"
                       aria-label="Komisyon oranı">
                <span class="rateSuffix">%</span>
                <button class="rateBtn" type="submit">Kaydet</button>
              </form>
            </td>

            <td>
              <?= $r['is_active']
                ? '<span class="badge good">Aktif</span>'
                : '<span class="badge bad">Pasif</span>' ?>
            </td>
            <td class="small">
              <?= $r['created_at']
                ? date('d.m.Y H:i', strtotime($r['created_at']))
                : '-' ?>
            </td>
            <td>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="toggle_id" value="<?= $r['id'] ?>">
                <button class="btn sm <?= $r['is_active'] ? 'danger' : 'success' ?>">
                  <?= $r['is_active'] ? 'Devre Dışı Bırak' : 'Aktifleştir' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($rows)): ?>
          <tr><td colspan="6" class="small">Kayıt yok.</td></tr>
        <?php endif; ?>

        </tbody>
      </table>
    </div>

  </section>
</section>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
