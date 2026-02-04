<?php
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';
$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
require_once __DIR__ . '/../../layout/header.php';

/* ✅ ROLE/SESSION normalize */
$role      = strtoupper(trim((string)($_SESSION['role'] ?? '')));
$myAgencyId = (int)($_SESSION['agency_id'] ?? 0);

/* DB */
$conn = null;
if (isset($GLOBALS['conn']) && $GLOBALS['conn']) $conn = $GLOBALS['conn'];
if (!$conn && function_exists('db')) $conn = db();
if ($conn && method_exists($conn, 'set_charset')) {
  @$conn->set_charset((string)config('db.charset', 'utf8mb4'));
}

/* ✅ ANA ACENTE mi? (parent_id = 0) */
$isMainAgencyContext = false;
if ($conn && $myAgencyId > 0) {
  $st = mysqli_prepare($conn, "SELECT COALESCE(parent_id,0) AS parent_id FROM agencies WHERE id=? LIMIT 1");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $myAgencyId);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $pid);
    mysqli_stmt_fetch($st);
    mysqli_stmt_close($st);
    $isMainAgencyContext = ((int)($pid ?? 0) === 0);
  }
}

/* ✅ Rehber (Acenteler) kartını kim görür?
   - Sadece ANA ACENTE bağlamında
   - Rol: ACENTE_YETKILISI veya PERSONEL
*/
$canSeeDirectoryCard = $isMainAgencyContext && in_array($role, ['ACENTE_YETKILISI','PERSONEL'], true);

/* ACENTE_YETKILISI için tali acenteler */
$taliRows = [];
if ($role === 'ACENTE_YETKILISI' && $conn && $myAgencyId > 0) {
  $st = mysqli_prepare($conn, "
    SELECT id, name, is_active
    FROM agencies
    WHERE parent_id=?
    ORDER BY name ASC
  ");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $myAgencyId);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $tid, $tname, $tact);
    while (mysqli_stmt_fetch($st)) {
      $taliRows[] = [
        'id' => (int)$tid,
        'name' => (string)$tname,
        'is_active' => (int)$tact
      ];
    }
    mysqli_stmt_close($st);
  }
}
?>

<section class="page">
  <section class="card">

    <h2 class="card-title">Dashboard</h2>
    <p class="card-sub">
     
    </p>

    <!-- QUICK LINKS -->
    <div class="cards-grid">

      <?php if ($canSeeDirectoryCard): ?>
        <a class="card-link" href="<?= e(base_url('agency-directory.php')) ?>">
          <div class="t">Acenteler</div>
          <div class="d">Acente rehberi / şirket bazlı bul / ticket aç</div>
        </a>
      <?php endif; ?>

      <a class="card-link" href="<?= e(base_url('users.php')) ?>">
        <div class="t">Kullanıcılar</div>
        <div class="d">Rol / acente / aktif-pasif</div>
      </a>

      <a class="card-link" href="<?= e(base_url('reports.php')) ?>">
        <div class="t">Raporlar</div>
        <div class="d">Faturalama / sistem özeti</div>
      </a>

    </div>

    <?php if ($role === 'ACENTE_YETKILISI'): ?>
      <div class="hr"></div>

      <div class="row">
        <div>
          <div>Tali Acenteler</div>
          <div class="small">Tali acentene ticket aç.</div>
        </div>
        <a class="btn" href="<?= e(base_url('agencies.php')) ?>">Tali Yönetimi</a>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Ad</th>
              <th>Durum</th>
              <th>İşlem</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($taliRows)): ?>
              <tr>
                <td colspan="3" class="small">Tali acente bulunamadı.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($taliRows as $tr): ?>
                <tr>
                  <td><?= e($tr['name']) ?></td>
                  <td>
                    <?= $tr['is_active'] === 1
                      ? '<span class="badge good">Aktif</span>'
                      : '<span class="badge bad">Pasif</span>' ?>
                  </td>
                  <td>
                    <?php if ($tr['is_active'] === 1): ?>
                      <a class="btn primary"
                         href="<?= e(base_url('ticket-create.php?mode=sub&to_agency_id='.$tr['id'])) ?>">
                        Ticket Aç
                      </a>
                    <?php else: ?>
                      <span class="small">Pasif</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="hr"></div>

    <div class="row">
      <div class="small">
        
      </div>
    </div>

  </section>
</section>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
