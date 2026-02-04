<?php
// /public_html/platform/users.php  ✅ FULL FIX (SCOPE + UI)

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_users_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (!isset($_SESSION['user_id'])) {
    header("Location: " . base_url("login.php"));
    exit;
  }
}

/* DB */
$conn = null;
if (isset($GLOBALS['conn']) && $GLOBALS['conn']) $conn = $GLOBALS['conn'];
if (!$conn && function_exists('db')) $conn = db();
if (!$conn) { http_response_code(500); exit("DB bağlantısı bulunamadı."); }

$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

/* Yetkiler */
$allowed = ['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($role, $allowed, true)) {
  http_response_code(403);
  exit("Bu sayfaya erişim yetkiniz yok.");
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

/* agency fallback */
if ($agencyId <= 0 && $userId > 0) {
  $st = $conn->prepare("SELECT agency_id FROM users WHERE id=? LIMIT 1");
  $st->bind_param("i", $userId);
  $st->execute();
  $st->bind_result($aid);
  $st->fetch();
  $st->close();
  $agencyId = (int)($aid ?? 0);
  $_SESSION['agency_id'] = $agencyId;
}

/* Rol isimleri */
$ROLE_MAP = [
  'SUPERADMIN'            => 'Süper Admin',
  'ACENTE_YETKILISI'      => 'Acente Yetkilisi',
  'TALI_ACENTE_YETKILISI' => 'Tali Acente Yetkilisi',
  'PERSONEL'              => 'Personel',
];

/* Ana acente tespiti */
$mainAgencyId = 0;
if ($agencyId > 0) {
  $st = $conn->prepare("SELECT COALESCE(parent_id,0) FROM agencies WHERE id=?");
  $st->bind_param("i", $agencyId);
  $st->execute();
  $st->bind_result($pid);
  $st->fetch();
  $st->close();
  $mainAgencyId = ($pid > 0) ? (int)$pid : $agencyId;
}

/* Scope */
$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($role === 'ACENTE_YETKILISI') {
  $where .= " AND (u.agency_id = ? OR u.agency_id IN (SELECT id FROM agencies WHERE parent_id = ?)) ";
  $params[] = $mainAgencyId;
  $params[] = $mainAgencyId;
  $types .= "ii";
} elseif ($role === 'TALI_ACENTE_YETKILISI' || $role === 'PERSONEL') {
  $where .= " AND u.agency_id = ? ";
  $params[] = $agencyId;
  $types .= "i";
}

/* DATA */
$sql = "
SELECT
  u.id, u.name, u.email, u.role, u.is_active, u.created_at,
  a.name AS agency_name,
  a.parent_id,
  pa.name AS parent_agency_name
FROM users u
LEFT JOIN agencies a  ON a.id = u.agency_id
LEFT JOIN agencies pa ON pa.id = a.parent_id
$where
ORDER BY u.id DESC
";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$page_title = "Kullanıcılar";
$pageTitle = 'Kullanıcılar';
$currentPage = 'users';
require_once __DIR__ . '/../../layout/header.php';

function tr_dt($dt){
  if (!$dt || $dt === '0000-00-00 00:00:00') return '-';
  return date('d.m.Y H:i', strtotime($dt));
}
?>



<!-- Page Content -->
<div class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Kullanıcılar</h2>
      <p class="card-subtitle">Sistem kullanıcılarını yönetin</p>
    </div>
    <?php if (in_array($role,['SUPERADMIN','ACENTE_YETKILISI'],true)): ?>
      <a class="btn btn-primary" href="<?= base_url('users-create.php') ?>" data-testid="create-user-btn">
        <i data-lucide="user-plus"></i>
        Yeni Kullanıcı
      </a>
    <?php endif; ?>
  </div>

  <!-- Filters -->
  <div style="padding: var(--sp-4) var(--sp-5); border-bottom: 1px solid var(--border); background: var(--bg-page);">
    <div class="u-flex u-gap-3 u-items-center">
      <div class="u-flex-1" style="max-width: 320px; position: relative;">
        <i data-lucide="search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: var(--text-muted);"></i>
        <input id="q" class="form-control" placeholder="İsim, email veya rol ara..." style="padding-left: 40px;">
      </div>
      <select id="fRole" class="form-control" style="width: 220px;">
        <option value="">Rol: Tümü</option>
        <option value="ACENTE_YETKILISI">Acente Yetkilisi</option>
        <option value="TALI_ACENTE_YETKILISI">Tali Acente Yetkilisi</option>
        <option value="PERSONEL">Personel</option>
      </select>
    </div>
  </div>

  <div class="table-wrap" style="border: none; border-radius: 0;">
    <table class="table" id="tbl">
      <thead>
        <tr>
          <th>ID</th>
          <th>Kullanıcı</th>
          <th>Rol</th>
          <th>Acente</th>
          <th>Bağlılık</th>
          <th>Durum</th>
          <th>Kayıt Tarihi</th>
          <th style="text-align: right;">İşlem</th>
        </tr>
</thead>
<tbody>
<?php foreach($rows as $r):
  $baglilik =
    $r['role']==='ACENTE_YETKILISI' ? 'Ana acente yetkilisi' :
    ($r['role']==='TALI_ACENTE_YETKILISI' ? 'Tali acente yetkilisi' :
    ($r['parent_id'] ? 'Tali acente personeli' : 'Ana acente personeli'));
?>
<tr class="rowx"
  data-hay="<?= strtolower($r['id'].' '.$r['name'].' '.$r['email'].' '.$baglilik) ?>"
  data-role="<?= $r['role'] ?>">
<td><?= $r['id'] ?></td>
<td><?= htmlspecialchars($r['name']) ?></td>
<td><?= htmlspecialchars($r['email']) ?></td>
<td><span class="badge info"><?= $ROLE_MAP[$r['role']] ?></span></td>
<td><?= htmlspecialchars($r['agency_name']) ?></td>
<td><span class="chip-muted"><?= $baglilik ?></span></td>
<td><?= $r['is_active']?'<span class="badge good">Aktif</span>':'<span class="badge bad">Pasif</span>' ?></td>
<td><?= tr_dt($r['created_at']) ?></td>
<td>
<?php if($r['id']!==$userId && $role!=='PERSONEL'): ?>
<form method="post" action="<?= base_url('users-toggle.php') ?>">
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <input type="hidden" name="id" value="<?= $r['id'] ?>">
  <input type="hidden" name="to" value="<?= $r['is_active']?0:1 ?>">
  <button class="btn sm <?= $r['is_active']?'danger':'success' ?>">
    <?= $r['is_active']?'Devre Dışı Bırak':'Aktifleştir' ?>
  </button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</section>
</section>

<script>
(()=> {
  const q=document.getElementById('q'), r=document.getElementById('fRole');
  const rows=[...document.querySelectorAll('.rowx')];
  function f(){
    const qv=(q.value||'').toLowerCase(), rv=r.value;
    rows.forEach(tr=>{
      const okQ=tr.dataset.hay.includes(qv);
      const okR=!rv||tr.dataset.role===rv;
      tr.style.display=(okQ&&okR)?'':'none';
    });
  }
  q.oninput=r.onchange=f; f();
})();
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
