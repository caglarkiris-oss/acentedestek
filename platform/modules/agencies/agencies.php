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

<style>
  .page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 24px;
  }
  
  .page-title {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.5px;
  }
  
  .page-subtitle {
    font-size: 14px;
    color: #64748b;
    margin-top: 6px;
  }
  
  .btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    box-shadow: 0 4px 15px rgba(30, 58, 138, 0.3);
    transition: all 0.2s;
  }
  
  .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(30, 58, 138, 0.4);
  }
  
  .btn-primary svg { width: 18px; height: 18px; }
  
  .alert {
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    font-weight: 500;
  }
  
  .alert svg { width: 20px; height: 20px; flex-shrink: 0; }
  
  .alert-success {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border: 1px solid #86efac;
    color: #166534;
  }
  
  .alert-error {
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    border: 1px solid #fecaca;
    color: #991b1b;
  }
  
  .card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  }
  
  .table-container {
    overflow-x: auto;
  }
  
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
  }
  
  thead {
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
  }
  
  th {
    padding: 14px 20px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #e2e8f0;
  }
  
  td {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
  }
  
  tr:hover td {
    background: #fafbfc;
  }
  
  tr:last-child td {
    border-bottom: none;
  }
  
  .agency-info {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  
  .agency-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
    font-weight: 700;
  }
  
  .agency-name {
    font-weight: 600;
    color: #0f172a;
  }
  
  .rate-form {
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }
  
  .rate-input {
    width: 70px;
    padding: 8px 12px;
    font-size: 14px;
    font-weight: 600;
    text-align: center;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    transition: all 0.2s;
  }
  
  .rate-input:focus {
    border-color: #3b82f6;
    background: white;
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
  }
  
  .rate-suffix {
    font-size: 14px;
    font-weight: 600;
    color: #64748b;
  }
  
  .rate-btn {
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 700;
    color: #2563eb;
    background: rgba(37, 99, 235, 0.1);
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
  }
  
  .rate-btn:hover {
    background: rgba(37, 99, 235, 0.2);
  }
  
  .badge {
    display: inline-flex;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 700;
    border-radius: 8px;
    text-transform: uppercase;
  }
  
  .badge-success {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
  }
  
  .badge-danger {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
  }
  
  .date-text {
    font-size: 13px;
    color: #64748b;
  }
  
  .btn-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 600;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
  }
  
  .btn-toggle svg { width: 16px; height: 16px; }
  
  .btn-toggle.danger {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
  }
  
  .btn-toggle.danger:hover {
    background: rgba(220, 38, 38, 0.2);
  }
  
  .btn-toggle.success {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
  }
  
  .btn-toggle.success:hover {
    background: rgba(22, 163, 74, 0.2);
  }
  
  .empty-state {
    text-align: center;
    padding: 60px 24px;
    color: #94a3b8;
  }
  
  .empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 16px;
    opacity: 0.4;
  }
</style>

<!-- Page Header -->
<div class="page-header">
  <div>
    <h1 class="page-title"><?= e($title) ?></h1>
    <p class="page-subtitle"><?= e($subtitle) ?></p>
  </div>
  <a href="<?= e($createUrl) ?>" class="btn-primary">
    <i data-lucide="plus"></i>
    <?= ($role === 'SUPERADMIN') ? 'Yeni Acente' : 'Yeni Tali Acente' ?>
  </a>
</div>

<?php if ($flash): ?>
  <div class="alert <?= ($flash['type']==='good' ? 'alert-success' : 'alert-error') ?>">
    <i data-lucide="<?= ($flash['type']==='good' ? 'check-circle' : 'alert-circle') ?>"></i>
    <?= e($flash['msg']) ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Acente</th>
          <th>Komisyon</th>
          <th>Durum</th>
          <th>Kayıt Tarihi</th>
          <th style="text-align: right;">İşlem</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="6">
              <div class="empty-state">
                <i data-lucide="building-2"></i>
                <p>Henüz kayıtlı acente yok</p>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><strong>#<?= $r['id'] ?></strong></td>
              <td>
                <div class="agency-info">
                  <div class="agency-avatar"><?= strtoupper(substr($r['name'], 0, 2)) ?></div>
                  <span class="agency-name"><?= e($r['name']) ?></span>
                </div>
              </td>
              <td>
                <form method="post" class="rate-form">
                  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="rate_id" value="<?= (int)$r['id'] ?>">
                  <input type="text" class="rate-input" name="commission_rate" 
                         value="<?= e(number_format((float)$r['commission_rate'], 2, ',', '')) ?>">
                  <span class="rate-suffix">%</span>
                  <button type="submit" class="rate-btn">Kaydet</button>
                </form>
              </td>
              <td>
                <span class="badge <?= $r['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                  <?= $r['is_active'] ? 'Aktif' : 'Pasif' ?>
                </span>
              </td>
              <td>
                <span class="date-text">
                  <?= $r['created_at'] ? date('d.m.Y H:i', strtotime($r['created_at'])) : '-' ?>
                </span>
              </td>
              <td style="text-align: right;">
                <form method="post" style="display: inline;">
                  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="toggle_id" value="<?= $r['id'] ?>">
                  <button type="submit" class="btn-toggle <?= $r['is_active'] ? 'danger' : 'success' ?>">
                    <i data-lucide="<?= $r['is_active'] ? 'x-circle' : 'check-circle' ?>"></i>
                    <?= $r['is_active'] ? 'Devre Dışı' : 'Aktifleştir' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>lucide.createIcons();</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
