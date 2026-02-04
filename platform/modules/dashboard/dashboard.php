<?php
// /platform/modules/dashboard/dashboard.php
// Enterprise Dashboard with Real-time KPIs

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

/* ✅ ROLE/SESSION normalize */
$role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
$myAgencyId = (int)($_SESSION['agency_id'] ?? 0);
$userName = (string)($_SESSION['name'] ?? '');

/* DB */
$conn = null;
if (isset($GLOBALS['conn']) && $GLOBALS['conn']) $conn = $GLOBALS['conn'];
if (!$conn && function_exists('db')) $conn = db();
if ($conn && method_exists($conn, 'set_charset')) {
  @$conn->set_charset((string)config('db.charset', 'utf8mb4'));
}

/* ===== KPI DATA FETCHING ===== */
$kpiData = [
    'open_tickets' => 0,
    'pending_tickets' => 0,
    'closed_tickets_month' => 0,
    'unmatched_mutabakat' => 0,
    'total_agencies' => 0,
    'active_users' => 0,
    'monthly_premium' => 0,
];

// Fetch real data if connection available
if ($conn && $myAgencyId > 0) {
    // Open Tickets
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM tickets WHERE (target_agency_id=? OR created_by_agency_id=?) AND status IN ('OPEN','open')");
    if ($st) {
        mysqli_stmt_bind_param($st, "ii", $myAgencyId, $myAgencyId);
        mysqli_stmt_execute($st);
        mysqli_stmt_bind_result($st, $cnt);
        mysqli_stmt_fetch($st);
        $kpiData['open_tickets'] = (int)$cnt;
        mysqli_stmt_close($st);
    }
    
    // Pending Tickets
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM tickets WHERE (target_agency_id=? OR created_by_agency_id=?) AND status IN ('PENDING','waiting_response')");
    if ($st) {
        mysqli_stmt_bind_param($st, "ii", $myAgencyId, $myAgencyId);
        mysqli_stmt_execute($st);
        mysqli_stmt_bind_result($st, $cnt);
        mysqli_stmt_fetch($st);
        $kpiData['pending_tickets'] = (int)$cnt;
        mysqli_stmt_close($st);
    }
    
    // Closed this month
    $monthStart = date('Y-m-01 00:00:00');
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM tickets WHERE (target_agency_id=? OR created_by_agency_id=?) AND status IN ('CLOSED','closed') AND updated_at >= ?");
    if ($st) {
        mysqli_stmt_bind_param($st, "iis", $myAgencyId, $myAgencyId, $monthStart);
        mysqli_stmt_execute($st);
        mysqli_stmt_bind_result($st, $cnt);
        mysqli_stmt_fetch($st);
        $kpiData['closed_tickets_month'] = (int)$cnt;
        mysqli_stmt_close($st);
    }
    
    // Unmatched Mutabakat - using mutabakat_v2_rows table
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM mutabakat_v2_rows WHERE ana_acente_id=? AND row_status='ESLESMEYEN'");
    if ($st) {
        mysqli_stmt_bind_param($st, "i", $myAgencyId);
        mysqli_stmt_execute($st);
        mysqli_stmt_bind_result($st, $cnt);
        mysqli_stmt_fetch($st);
        $kpiData['unmatched_mutabakat'] = (int)$cnt;
        mysqli_stmt_close($st);
    }
    
    // Total Agencies (sub-agencies for main agency)
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM agencies WHERE parent_id=? AND is_active=1");
    if ($st) {
        mysqli_stmt_bind_param($st, "i", $myAgencyId);
        mysqli_stmt_execute($st);
        mysqli_stmt_bind_result($st, $cnt);
        mysqli_stmt_fetch($st);
        $kpiData['total_agencies'] = (int)$cnt;
        mysqli_stmt_close($st);
    }
    
    // Active Users
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM users WHERE agency_id=? AND is_active=1");
    if ($st) {
        mysqli_stmt_bind_param($st, "i", $myAgencyId);
        mysqli_stmt_execute($st);
        mysqli_stmt_bind_result($st, $cnt);
        mysqli_stmt_fetch($st);
        $kpiData['active_users'] = (int)$cnt;
        mysqli_stmt_close($st);
    }
    
    // Monthly Premium Sum (from mutabakat_v2_rows)
    $st = @mysqli_prepare($conn, "SELECT COALESCE(SUM(brut_prim), 0) FROM mutabakat_v2_rows WHERE ana_acente_id=? AND created_at >= ?");
    if ($st) {
        mysqli_stmt_bind_param($st, "is", $myAgencyId, $monthStart);
        mysqli_stmt_execute($st);
        mysqli_stmt_bind_result($st, $sum);
        mysqli_stmt_fetch($st);
        $kpiData['monthly_premium'] = (float)$sum;
        mysqli_stmt_close($st);
    }
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

/* ✅ Rehber kartını kim görür? */
$canSeeDirectoryCard = $isMainAgencyContext && in_array($role, ['ACENTE_YETKILISI','PERSONEL'], true);

/* ACENTE_YETKILISI için tali acenteler */
$taliRows = [];
if ($role === 'ACENTE_YETKILISI' && $conn && $myAgencyId > 0) {
  $st = mysqli_prepare($conn, "
    SELECT id, name, is_active
    FROM agencies
    WHERE parent_id=?
    ORDER BY name ASC
    LIMIT 10
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

/* Recent Activity */
$recentActivity = [];
if ($conn && $myAgencyId > 0) {
    $st = @mysqli_prepare($conn, "
        SELECT id, subject, status, updated_at 
        FROM tickets 
        WHERE target_agency_id=? OR created_by_agency_id=?
        ORDER BY updated_at DESC 
        LIMIT 5
    ");
    if ($st) {
        mysqli_stmt_bind_param($st, "ii", $myAgencyId, $myAgencyId);
        mysqli_stmt_execute($st);
        mysqli_stmt_bind_result($st, $tid, $subj, $stat, $upd);
        while (mysqli_stmt_fetch($st)) {
            $recentActivity[] = [
                'id' => (int)$tid,
                'subject' => (string)$subj,
                'status' => (string)$stat,
                'updated_at' => (string)$upd
            ];
        }
        mysqli_stmt_close($st);
    }
}

require_once __DIR__ . '/../../layout/header.php';
?>

<!-- Dashboard Header -->
<div class="dashboard-header">
  <div>
    <h2 class="dashboard-title">Hoş Geldiniz<?= $userName ? ', ' . h($userName) : '' ?></h2>
    <p class="dashboard-subtitle"><?= date('d F Y, l') ?> · <?= h($role) ?></p>
  </div>
  <div class="dashboard-actions">
    <a href="<?= route_url('ticket-create') ?>" class="btn btn-primary">
      <i data-lucide="plus"></i>
      Yeni Ticket
    </a>
  </div>
</div>

<!-- KPI Cards -->
<div class="kpi-row">
  <!-- Open Tickets -->
  <div class="stat-card" data-testid="kpi-open-tickets">
    <div class="stat-card-icon">
      <i data-lucide="inbox"></i>
    </div>
    <div class="stat-card-label">Açık Ticket</div>
    <div class="stat-card-value"><?= number_format($kpiData['open_tickets']) ?></div>
  </div>
  
  <!-- Pending -->
  <div class="stat-card" data-testid="kpi-pending">
    <div class="stat-card-icon warning">
      <i data-lucide="clock"></i>
    </div>
    <div class="stat-card-label">Beklemede</div>
    <div class="stat-card-value"><?= number_format($kpiData['pending_tickets']) ?></div>
  </div>
  
  <!-- Unmatched Mutabakat -->
  <div class="stat-card" data-testid="kpi-unmatched">
    <div class="stat-card-icon danger">
      <i data-lucide="alert-triangle"></i>
    </div>
    <div class="stat-card-label">Eşleşmeyen</div>
    <div class="stat-card-value"><?= number_format($kpiData['unmatched_mutabakat']) ?></div>
  </div>
  
  <!-- Monthly Premium -->
  <div class="stat-card" data-testid="kpi-premium">
    <div class="stat-card-icon success">
      <i data-lucide="trending-up"></i>
    </div>
    <div class="stat-card-label">Bu Ay Prim</div>
    <div class="stat-card-value"><?= number_format($kpiData['monthly_premium'], 0, ',', '.') ?> ₺</div>
  </div>
  
  <!-- Active Agencies -->
  <div class="stat-card" data-testid="kpi-agencies">
    <div class="stat-card-icon">
      <i data-lucide="building-2"></i>
    </div>
    <div class="stat-card-label">Aktif Acente</div>
    <div class="stat-card-value"><?= number_format($kpiData['total_agencies']) ?></div>
  </div>
  
  <!-- Active Users -->
  <div class="stat-card" data-testid="kpi-users">
    <div class="stat-card-icon">
      <i data-lucide="users"></i>
    </div>
    <div class="stat-card-label">Kullanıcı</div>
    <div class="stat-card-value"><?= number_format($kpiData['active_users']) ?></div>
  </div>
</div>

<!-- Main Content Grid -->
<div class="grid" style="grid-template-columns: 1fr 1fr; gap: var(--sp-5);">
  
  <!-- Quick Actions -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Hızlı Erişim</h3>
        <p class="card-subtitle">Sık kullanılan işlemler</p>
      </div>
    </div>
    <div class="card-body">
      <div class="cards-grid" style="grid-template-columns: 1fr 1fr;">
        <?php if ($canSeeDirectoryCard): ?>
          <a class="card-link" href="<?= e(base_url('agency-directory.php')) ?>" data-testid="quick-link-directory">
            <div class="u-flex u-items-center u-gap-3 u-mb-2">
              <i data-lucide="book-open" style="color: var(--accent-600);"></i>
              <div class="t">Acente Rehberi</div>
            </div>
            <div class="d">Şirket bazlı acente bul, ticket aç</div>
          </a>
        <?php endif; ?>

        <a class="card-link" href="<?= e(base_url('tickets.php')) ?>" data-testid="quick-link-tickets">
          <div class="u-flex u-items-center u-gap-3 u-mb-2">
            <i data-lucide="ticket" style="color: var(--accent-600);"></i>
            <div class="t">Tickets</div>
          </div>
          <div class="d">Tüm talepleri görüntüle</div>
        </a>

        <a class="card-link" href="<?= e(base_url('mutabakat/havuz.php')) ?>" data-testid="quick-link-mutabakat">
          <div class="u-flex u-items-center u-gap-3 u-mb-2">
            <i data-lucide="git-compare" style="color: var(--accent-600);"></i>
            <div class="t">Mutabakat</div>
          </div>
          <div class="d">Havuz ve eşleştirme işlemleri</div>
        </a>

        <a class="card-link" href="<?= e(base_url('reports.php')) ?>" data-testid="quick-link-reports">
          <div class="u-flex u-items-center u-gap-3 u-mb-2">
            <i data-lucide="bar-chart-3" style="color: var(--accent-600);"></i>
            <div class="t">Raporlar</div>
          </div>
          <div class="d">Faturalama ve sistem özeti</div>
        </a>
      </div>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Son Aktiviteler</h3>
        <p class="card-subtitle">En son güncellenen ticketlar</p>
      </div>
      <a href="<?= route_url('tickets') ?>" class="btn btn-secondary btn-sm">
        Tümünü Gör
        <i data-lucide="arrow-right" class="icon-sm"></i>
      </a>
    </div>
    <div class="card-body" style="padding: 0;">
      <?php if (empty($recentActivity)): ?>
        <div class="empty-state">
          <i data-lucide="inbox" class="empty-state-icon"></i>
          <p>Henüz aktivite yok</p>
        </div>
      <?php else: ?>
        <div class="activity-list" style="padding: var(--sp-4) var(--sp-5);">
          <?php foreach ($recentActivity as $act): ?>
            <a href="<?= e(base_url('ticket.php?id=' . $act['id'])) ?>" class="activity-item" style="text-decoration: none;">
              <div class="activity-icon <?= strtolower($act['status']) === 'closed' ? '' : 'success' ?>">
                <i data-lucide="<?= strtolower($act['status']) === 'closed' ? 'check-circle' : 'message-circle' ?>"></i>
              </div>
              <div class="activity-content">
                <div class="activity-text"><?= h($act['subject'] ?: 'Ticket #' . $act['id']) ?></div>
                <div class="activity-time">
                  <span class="badge <?= strtolower($act['status']) === 'closed' ? '' : 'badge-success' ?>"><?= h($act['status']) ?></span>
                  · <?= date('d.m.Y H:i', strtotime($act['updated_at'])) ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($role === 'ACENTE_YETKILISI' && !empty($taliRows)): ?>
<!-- Tali Acenteler Section -->
<div class="card u-mt-5">
  <div class="card-header">
    <div>
      <h3 class="card-title">Tali Acenteler</h3>
      <p class="card-subtitle">Tali acentelerinize hızlıca ticket açın</p>
    </div>
    <a href="<?= e(base_url('agencies.php')) ?>" class="btn btn-secondary btn-sm">
      Tümünü Yönet
      <i data-lucide="settings" class="icon-sm"></i>
    </a>
  </div>
  <div class="card-body" style="padding: 0;">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Acente Adı</th>
            <th>Durum</th>
            <th style="text-align: right;">İşlem</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($taliRows as $tr): ?>
            <tr>
              <td>
                <div class="u-flex u-items-center u-gap-3">
                  <div class="avatar avatar-sm">
                    <?= strtoupper(substr($tr['name'], 0, 2)) ?>
                  </div>
                  <span class="u-font-semibold"><?= e($tr['name']) ?></span>
                </div>
              </td>
              <td>
                <?= $tr['is_active'] === 1
                  ? '<span class="badge badge-success">Aktif</span>'
                  : '<span class="badge badge-danger">Pasif</span>' ?>
              </td>
              <td style="text-align: right;">
                <?php if ($tr['is_active'] === 1): ?>
                  <a class="btn btn-primary btn-sm"
                     href="<?= e(base_url('ticket-create.php?mode=sub&to_agency_id='.$tr['id'])) ?>">
                    <i data-lucide="plus" class="icon-sm"></i>
                    Ticket Aç
                  </a>
                <?php else: ?>
                  <span class="badge">Pasif</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  // Refresh icons after page load
  if (typeof lucide !== 'undefined') {
    lucide.createIcons();
  }
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
