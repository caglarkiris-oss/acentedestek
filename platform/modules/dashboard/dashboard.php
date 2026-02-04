<?php
// Enterprise Dashboard with Real KPIs
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

$role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
$myAgencyId = (int)($_SESSION['agency_id'] ?? 0);
$userName = (string)($_SESSION['name'] ?? '');

$conn = null;
if (isset($GLOBALS['conn']) && $GLOBALS['conn']) $conn = $GLOBALS['conn'];
if (!$conn && function_exists('db')) $conn = db();

// KPI Data
$kpi = [
    'open_tickets' => 0,
    'pending' => 0,
    'unmatched' => 0,
    'monthly_premium' => 0,
    'agencies' => 0,
    'users' => 0,
];

if ($conn && $myAgencyId > 0) {
    // Open Tickets
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM tickets WHERE (target_agency_id=? OR created_by_agency_id=?) AND status IN ('OPEN','open')");
    if ($st) { mysqli_stmt_bind_param($st, "ii", $myAgencyId, $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['open_tickets'] = (int)$c; mysqli_stmt_close($st); }
    
    // Pending
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM tickets WHERE (target_agency_id=? OR created_by_agency_id=?) AND status IN ('PENDING','waiting_response')");
    if ($st) { mysqli_stmt_bind_param($st, "ii", $myAgencyId, $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['pending'] = (int)$c; mysqli_stmt_close($st); }
    
    // Unmatched
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM mutabakat_v2_rows WHERE ana_acente_id=? AND row_status='ESLESMEYEN'");
    if ($st) { mysqli_stmt_bind_param($st, "i", $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['unmatched'] = (int)$c; mysqli_stmt_close($st); }
    
    // Agencies
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM agencies WHERE parent_id=? AND is_active=1");
    if ($st) { mysqli_stmt_bind_param($st, "i", $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['agencies'] = (int)$c; mysqli_stmt_close($st); }
    
    // Users
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM users WHERE agency_id=? AND is_active=1");
    if ($st) { mysqli_stmt_bind_param($st, "i", $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['users'] = (int)$c; mysqli_stmt_close($st); }
    
    // Monthly Premium
    $monthStart = date('Y-m-01 00:00:00');
    $st = @mysqli_prepare($conn, "SELECT COALESCE(SUM(brut_prim), 0) FROM mutabakat_v2_rows WHERE ana_acente_id=? AND created_at >= ?");
    if ($st) { mysqli_stmt_bind_param($st, "is", $myAgencyId, $monthStart); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $s); mysqli_stmt_fetch($st); $kpi['monthly_premium'] = (float)$s; mysqli_stmt_close($st); }
}

// Recent Activity
$recentActivity = [];
if ($conn && $myAgencyId > 0) {
    $st = @mysqli_prepare($conn, "SELECT id, subject, status, updated_at FROM tickets WHERE target_agency_id=? OR created_by_agency_id=? ORDER BY updated_at DESC LIMIT 5");
    if ($st) {
        mysqli_stmt_bind_param($st, "ii", $myAgencyId, $myAgencyId);
        mysqli_stmt_execute($st);
        mysqli_stmt_bind_result($st, $tid, $subj, $stat, $upd);
        while (mysqli_stmt_fetch($st)) {
            $recentActivity[] = ['id' => (int)$tid, 'subject' => (string)$subj, 'status' => (string)$stat, 'updated_at' => (string)$upd];
        }
        mysqli_stmt_close($st);
    }
}

require_once __DIR__ . '/../../layout/header.php';
?>

<style>
  .dashboard-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 32px;
  }
  
  .dashboard-title {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.5px;
  }
  
  .dashboard-subtitle {
    font-size: 14px;
    color: #64748b;
    margin-top: 6px;
    font-weight: 500;
  }
  
  .btn-new {
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
  
  .btn-new:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(30, 58, 138, 0.4);
  }
  
  .btn-new svg { width: 18px; height: 18px; }
  
  /* KPI Grid */
  .kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
  }
  
  .kpi-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 24px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s;
  }
  
  .kpi-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #2563eb, #3b82f6);
    opacity: 0;
    transition: opacity 0.3s;
  }
  
  .kpi-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
  }
  
  .kpi-card:hover::before {
    opacity: 1;
  }
  
  .kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
  }
  
  .kpi-icon.blue { background: rgba(37, 99, 235, 0.1); color: #2563eb; }
  .kpi-icon.orange { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
  .kpi-icon.red { background: rgba(220, 38, 38, 0.1); color: #dc2626; }
  .kpi-icon.green { background: rgba(22, 163, 74, 0.1); color: #16a34a; }
  .kpi-icon.purple { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; }
  
  .kpi-icon svg { width: 24px; height: 24px; }
  
  .kpi-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
  }
  
  .kpi-value {
    font-size: 32px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -1px;
    line-height: 1;
  }
  
  .kpi-value small {
    font-size: 16px;
    font-weight: 600;
    color: #64748b;
    margin-left: 4px;
  }
  
  /* Cards Grid */
  .cards-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
  }
  
  @media (max-width: 900px) {
    .cards-grid { grid-template-columns: 1fr; }
  }
  
  .card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  }
  
  .card-header {
    padding: 20px 24px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(180deg, white 0%, #fafbfc 100%);
  }
  
  .card-title {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  
  .card-title svg { width: 20px; height: 20px; color: #64748b; }
  
  .card-subtitle {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px;
  }
  
  .card-body {
    padding: 24px;
  }
  
  .card-body.no-padding {
    padding: 0;
  }
  
  /* Quick Links */
  .quick-links {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
  }
  
  .quick-link {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    text-decoration: none;
    transition: all 0.2s;
  }
  
  .quick-link:hover {
    background: white;
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.12);
  }
  
  .quick-link-icon {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
  }
  
  .quick-link-icon svg { width: 22px; height: 22px; }
  
  .quick-link-text h4 {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
  }
  
  .quick-link-text p {
    font-size: 12px;
    color: #64748b;
  }
  
  /* Activity List */
  .activity-list {
    padding: 0;
  }
  
  .activity-item {
    display: flex;
    gap: 16px;
    padding: 16px 24px;
    border-bottom: 1px solid #f1f5f9;
    text-decoration: none;
    transition: background 0.15s;
  }
  
  .activity-item:hover {
    background: #f8fafc;
  }
  
  .activity-item:last-child {
    border-bottom: none;
  }
  
  .activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  
  .activity-icon.open { background: rgba(22, 163, 74, 0.1); color: #16a34a; }
  .activity-icon.closed { background: rgba(100, 116, 139, 0.1); color: #64748b; }
  
  .activity-icon svg { width: 20px; height: 20px; }
  
  .activity-content {
    flex: 1;
    min-width: 0;
  }
  
  .activity-title {
    font-size: 14px;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  
  .activity-meta {
    font-size: 12px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  
  .activity-badge {
    display: inline-flex;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 6px;
    text-transform: uppercase;
  }
  
  .activity-badge.open { background: rgba(22, 163, 74, 0.1); color: #16a34a; }
  .activity-badge.closed { background: rgba(100, 116, 139, 0.1); color: #64748b; }
  
  .empty-state {
    text-align: center;
    padding: 48px 24px;
    color: #94a3b8;
  }
  
  .empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
  }
  
  .btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    text-decoration: none;
    transition: all 0.2s;
  }
  
  .btn-secondary:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
  }
  
  .btn-secondary svg { width: 16px; height: 16px; }
</style>

<!-- Dashboard Header -->
<div class="dashboard-header">
  <div>
    <h1 class="dashboard-title">Hoş Geldiniz<?= $userName ? ', ' . h(explode('@', $userName)[0]) : '' ?></h1>
    <p class="dashboard-subtitle"><?= strftime('%d %B %Y, %A', time()) ?> · <?= h($role) ?></p>
  </div>
  <a href="<?= route_url('ticket-create') ?>" class="btn-new">
    <i data-lucide="plus"></i>
    Yeni Ticket
  </a>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon blue">
      <i data-lucide="inbox"></i>
    </div>
    <div class="kpi-label">Açık Ticket</div>
    <div class="kpi-value"><?= number_format($kpi['open_tickets']) ?></div>
  </div>
  
  <div class="kpi-card">
    <div class="kpi-icon orange">
      <i data-lucide="clock"></i>
    </div>
    <div class="kpi-label">Beklemede</div>
    <div class="kpi-value"><?= number_format($kpi['pending']) ?></div>
  </div>
  
  <div class="kpi-card">
    <div class="kpi-icon red">
      <i data-lucide="alert-triangle"></i>
    </div>
    <div class="kpi-label">Eşleşmeyen</div>
    <div class="kpi-value"><?= number_format($kpi['unmatched']) ?></div>
  </div>
  
  <div class="kpi-card">
    <div class="kpi-icon green">
      <i data-lucide="trending-up"></i>
    </div>
    <div class="kpi-label">Bu Ay Prim</div>
    <div class="kpi-value"><?= number_format($kpi['monthly_premium'], 0, ',', '.') ?><small>₺</small></div>
  </div>
  
  <div class="kpi-card">
    <div class="kpi-icon purple">
      <i data-lucide="building-2"></i>
    </div>
    <div class="kpi-label">Aktif Acente</div>
    <div class="kpi-value"><?= number_format($kpi['agencies']) ?></div>
  </div>
  
  <div class="kpi-card">
    <div class="kpi-icon blue">
      <i data-lucide="users"></i>
    </div>
    <div class="kpi-label">Kullanıcı</div>
    <div class="kpi-value"><?= number_format($kpi['users']) ?></div>
  </div>
</div>

<!-- Content Cards -->
<div class="cards-grid">
  <!-- Quick Links -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">
          <i data-lucide="zap"></i>
          Hızlı Erişim
        </h3>
        <p class="card-subtitle">Sık kullanılan işlemler</p>
      </div>
    </div>
    <div class="card-body">
      <div class="quick-links">
        <a href="<?= route_url('tickets') ?>" class="quick-link">
          <div class="quick-link-icon">
            <i data-lucide="ticket"></i>
          </div>
          <div class="quick-link-text">
            <h4>Tickets</h4>
            <p>Tüm talepleri görüntüle</p>
          </div>
        </a>
        <a href="<?= route_url('mutabakat/havuz') ?>" class="quick-link">
          <div class="quick-link-icon">
            <i data-lucide="git-compare"></i>
          </div>
          <div class="quick-link-text">
            <h4>Mutabakat</h4>
            <p>Havuz ve eşleştirme</p>
          </div>
        </a>
        <a href="<?= route_url('agencies') ?>" class="quick-link">
          <div class="quick-link-icon">
            <i data-lucide="building-2"></i>
          </div>
          <div class="quick-link-text">
            <h4>Acenteler</h4>
            <p>Acente yönetimi</p>
          </div>
        </a>
        <a href="<?= route_url('reports') ?>" class="quick-link">
          <div class="quick-link-icon">
            <i data-lucide="bar-chart-3"></i>
          </div>
          <div class="quick-link-text">
            <h4>Raporlar</h4>
            <p>Analiz ve özet</p>
          </div>
        </a>
      </div>
    </div>
  </div>
  
  <!-- Recent Activity -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">
          <i data-lucide="activity"></i>
          Son Aktiviteler
        </h3>
        <p class="card-subtitle">En son güncellenen ticketlar</p>
      </div>
      <a href="<?= route_url('tickets') ?>" class="btn-secondary">
        Tümü
        <i data-lucide="arrow-right"></i>
      </a>
    </div>
    <div class="card-body no-padding">
      <?php if (empty($recentActivity)): ?>
        <div class="empty-state">
          <i data-lucide="inbox"></i>
          <p>Henüz aktivite yok</p>
        </div>
      <?php else: ?>
        <div class="activity-list">
          <?php foreach ($recentActivity as $act): 
            $isOpen = !in_array(strtolower($act['status']), ['closed', 'kapali']);
          ?>
            <a href="<?= base_url('ticket.php?id=' . $act['id']) ?>" class="activity-item">
              <div class="activity-icon <?= $isOpen ? 'open' : 'closed' ?>">
                <i data-lucide="<?= $isOpen ? 'message-circle' : 'check-circle' ?>"></i>
              </div>
              <div class="activity-content">
                <div class="activity-title"><?= h($act['subject'] ?: 'Ticket #' . $act['id']) ?></div>
                <div class="activity-meta">
                  <span class="activity-badge <?= $isOpen ? 'open' : 'closed' ?>"><?= h($act['status']) ?></span>
                  <span><?= date('d.m.Y H:i', strtotime($act['updated_at'])) ?></span>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  lucide.createIcons();
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
