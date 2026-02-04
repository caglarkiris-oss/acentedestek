<?php
// Enterprise Dashboard with Charts & Real KPIs
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
    'closed_month' => 0,
    'unmatched' => 0,
    'matched' => 0,
    'monthly_premium' => 0,
    'agencies' => 0,
    'users' => 0,
];

// Chart Data
$chartData = [
    'monthly_premiums' => [],
    'ticket_status' => ['open' => 0, 'pending' => 0, 'closed' => 0],
    'mutabakat_status' => ['eslesen' => 0, 'eslesmeyen' => 0, 'itiraz' => 0],
];

if ($conn && $myAgencyId > 0) {
    // Open Tickets
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM tickets WHERE (target_agency_id=? OR created_by_agency_id=?) AND status IN ('OPEN','open')");
    if ($st) { mysqli_stmt_bind_param($st, "ii", $myAgencyId, $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['open_tickets'] = (int)$c; $chartData['ticket_status']['open'] = (int)$c; mysqli_stmt_close($st); }
    
    // Pending
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM tickets WHERE (target_agency_id=? OR created_by_agency_id=?) AND status IN ('PENDING','waiting_response')");
    if ($st) { mysqli_stmt_bind_param($st, "ii", $myAgencyId, $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['pending'] = (int)$c; $chartData['ticket_status']['pending'] = (int)$c; mysqli_stmt_close($st); }
    
    // Closed this month
    $monthStart = date('Y-m-01 00:00:00');
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM tickets WHERE (target_agency_id=? OR created_by_agency_id=?) AND status IN ('CLOSED','closed') AND updated_at >= ?");
    if ($st) { mysqli_stmt_bind_param($st, "iis", $myAgencyId, $myAgencyId, $monthStart); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['closed_month'] = (int)$c; $chartData['ticket_status']['closed'] = (int)$c; mysqli_stmt_close($st); }
    
    // Unmatched Mutabakat
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM mutabakat_v2_rows WHERE ana_acente_id=? AND row_status='ESLESMEYEN'");
    if ($st) { mysqli_stmt_bind_param($st, "i", $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['unmatched'] = (int)$c; $chartData['mutabakat_status']['eslesmeyen'] = (int)$c; mysqli_stmt_close($st); }
    
    // Matched Mutabakat
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM mutabakat_v2_rows WHERE ana_acente_id=? AND row_status='ESLESEN'");
    if ($st) { mysqli_stmt_bind_param($st, "i", $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['matched'] = (int)$c; $chartData['mutabakat_status']['eslesen'] = (int)$c; mysqli_stmt_close($st); }
    
    // Itiraz
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM mutabakat_v2_rows WHERE ana_acente_id=? AND row_status='ITIRAZ'");
    if ($st) { mysqli_stmt_bind_param($st, "i", $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $chartData['mutabakat_status']['itiraz'] = (int)$c; mysqli_stmt_close($st); }
    
    // Agencies
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM agencies WHERE parent_id=? AND is_active=1");
    if ($st) { mysqli_stmt_bind_param($st, "i", $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['agencies'] = (int)$c; mysqli_stmt_close($st); }
    
    // Users
    $st = @mysqli_prepare($conn, "SELECT COUNT(*) FROM users WHERE agency_id=? AND is_active=1");
    if ($st) { mysqli_stmt_bind_param($st, "i", $myAgencyId); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $c); mysqli_stmt_fetch($st); $kpi['users'] = (int)$c; mysqli_stmt_close($st); }
    
    // Monthly Premium (current)
    $st = @mysqli_prepare($conn, "SELECT COALESCE(SUM(brut_prim), 0) FROM mutabakat_v2_rows WHERE ana_acente_id=? AND created_at >= ?");
    if ($st) { mysqli_stmt_bind_param($st, "is", $myAgencyId, $monthStart); mysqli_stmt_execute($st); mysqli_stmt_bind_result($st, $s); mysqli_stmt_fetch($st); $kpi['monthly_premium'] = (float)$s; mysqli_stmt_close($st); }
    
    // Last 6 months premium data for chart
    for ($i = 5; $i >= 0; $i--) {
        $mStart = date('Y-m-01 00:00:00', strtotime("-$i months"));
        $mEnd = date('Y-m-t 23:59:59', strtotime("-$i months"));
        $mLabel = date('M Y', strtotime("-$i months"));
        
        $st = @mysqli_prepare($conn, "SELECT COALESCE(SUM(brut_prim), 0) FROM mutabakat_v2_rows WHERE ana_acente_id=? AND created_at >= ? AND created_at <= ?");
        if ($st) {
            mysqli_stmt_bind_param($st, "iss", $myAgencyId, $mStart, $mEnd);
            mysqli_stmt_execute($st);
            mysqli_stmt_bind_result($st, $sum);
            mysqli_stmt_fetch($st);
            $chartData['monthly_premiums'][] = ['month' => $mLabel, 'value' => (float)$sum];
            mysqli_stmt_close($st);
        } else {
            $chartData['monthly_premiums'][] = ['month' => $mLabel, 'value' => 0];
        }
    }
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

// Calculate match rate
$totalMutabakat = $chartData['mutabakat_status']['eslesen'] + $chartData['mutabakat_status']['eslesmeyen'] + $chartData['mutabakat_status']['itiraz'];
$matchRate = $totalMutabakat > 0 ? round(($chartData['mutabakat_status']['eslesen'] / $totalMutabakat) * 100) : 0;

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
  
  .header-actions {
    display: flex;
    gap: 12px;
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
  
  .btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: white;
    color: #475569;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
  }
  
  .btn-secondary:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
  }
  
  .btn-secondary svg { width: 18px; height: 18px; }
  
  /* KPI Grid */
  .kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 32px;
  }
  
  @media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 600px) { .kpi-grid { grid-template-columns: 1fr; } }
  
  .kpi-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
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
    background: var(--kpi-color, linear-gradient(90deg, #2563eb, #3b82f6));
    opacity: 0;
    transition: opacity 0.3s;
  }
  
  .kpi-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.1);
  }
  
  .kpi-card:hover::before {
    opacity: 1;
  }
  
  .kpi-card.blue { --kpi-color: linear-gradient(90deg, #2563eb, #3b82f6); }
  .kpi-card.orange { --kpi-color: linear-gradient(90deg, #f59e0b, #fbbf24); }
  .kpi-card.red { --kpi-color: linear-gradient(90deg, #dc2626, #ef4444); }
  .kpi-card.green { --kpi-color: linear-gradient(90deg, #16a34a, #22c55e); }
  .kpi-card.purple { --kpi-color: linear-gradient(90deg, #7c3aed, #8b5cf6); }
  
  .kpi-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 16px;
  }
  
  .kpi-icon {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  
  .kpi-icon.blue { background: rgba(37, 99, 235, 0.1); color: #2563eb; }
  .kpi-icon.orange { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
  .kpi-icon.red { background: rgba(220, 38, 38, 0.1); color: #dc2626; }
  .kpi-icon.green { background: rgba(22, 163, 74, 0.1); color: #16a34a; }
  .kpi-icon.purple { background: rgba(124, 58, 237, 0.1); color: #7c3aed; }
  
  .kpi-icon svg { width: 26px; height: 26px; }
  
  .kpi-trend {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
  }
  
  .kpi-trend.up { background: rgba(22, 163, 74, 0.1); color: #16a34a; }
  .kpi-trend.down { background: rgba(220, 38, 38, 0.1); color: #dc2626; }
  .kpi-trend.neutral { background: rgba(100, 116, 139, 0.1); color: #64748b; }
  
  .kpi-trend svg { width: 14px; height: 14px; }
  
  .kpi-label {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  
  .kpi-value {
    font-size: 36px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -1px;
    line-height: 1;
  }
  
  .kpi-value small {
    font-size: 18px;
    font-weight: 600;
    color: #64748b;
    margin-left: 4px;
  }
  
  /* Animated counter */
  .counter {
    display: inline-block;
  }
  
  /* Charts Section */
  .charts-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-bottom: 32px;
  }
  
  @media (max-width: 1000px) { .charts-grid { grid-template-columns: 1fr; } }
  
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
  
  .chart-container {
    position: relative;
    height: 280px;
  }
  
  .chart-container.small {
    height: 220px;
  }
  
  /* Stats Grid */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-top: 20px;
  }
  
  .stat-item {
    text-align: center;
    padding: 16px;
    background: #f8fafc;
    border-radius: 12px;
  }
  
  .stat-value {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
  }
  
  .stat-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    margin-top: 4px;
    text-transform: uppercase;
  }
  
  .stat-item.green .stat-value { color: #16a34a; }
  .stat-item.red .stat-value { color: #dc2626; }
  .stat-item.orange .stat-value { color: #f59e0b; }
  
  /* Progress Ring */
  .progress-ring-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  
  .progress-ring {
    position: relative;
    width: 180px;
    height: 180px;
  }
  
  .progress-ring svg {
    transform: rotate(-90deg);
  }
  
  .progress-ring-bg {
    fill: none;
    stroke: #e2e8f0;
    stroke-width: 12;
  }
  
  .progress-ring-fill {
    fill: none;
    stroke: url(#gradient);
    stroke-width: 12;
    stroke-linecap: round;
    stroke-dasharray: 440;
    stroke-dashoffset: 440;
    transition: stroke-dashoffset 1.5s ease-out;
  }
  
  .progress-ring-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
  }
  
  .progress-ring-value {
    font-size: 42px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
  }
  
  .progress-ring-label {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    margin-top: 8px;
  }
  
  /* Bottom Grid */
  .bottom-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
  }
  
  @media (max-width: 900px) { .bottom-grid { grid-template-columns: 1fr; } }
  
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
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
  }
  
  .quick-link-icon svg { width: 24px; height: 24px; }
  
  .quick-link-text h4 {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
  }
  
  .quick-link-text p {
    font-size: 13px;
    color: #64748b;
  }
  
  /* Activity List */
  .activity-list { padding: 0; }
  
  .activity-item {
    display: flex;
    gap: 16px;
    padding: 16px 24px;
    border-bottom: 1px solid #f1f5f9;
    text-decoration: none;
    transition: background 0.15s;
  }
  
  .activity-item:hover { background: #f8fafc; }
  .activity-item:last-child { border-bottom: none; }
  
  .activity-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  
  .activity-icon.open { background: rgba(22, 163, 74, 0.1); color: #16a34a; }
  .activity-icon.closed { background: rgba(100, 116, 139, 0.1); color: #64748b; }
  
  .activity-icon svg { width: 22px; height: 22px; }
  
  .activity-content { flex: 1; min-width: 0; }
  
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
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 700;
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
  
  .btn-sm {
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
  
  .btn-sm:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
  }
  
  .btn-sm svg { width: 16px; height: 16px; }
  
  /* Welcome Banner */
  .welcome-banner {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    border-radius: 20px;
    padding: 32px;
    margin-bottom: 32px;
    position: relative;
    overflow: hidden;
    color: white;
  }
  
  .welcome-banner::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
  }
  
  .welcome-banner::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: 10%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    border-radius: 50%;
  }
  
  .welcome-content {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  
  .welcome-text h1 {
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 8px;
  }
  
  .welcome-text p {
    font-size: 15px;
    opacity: 0.9;
  }
  
  .welcome-stats {
    display: flex;
    gap: 32px;
  }
  
  .welcome-stat {
    text-align: center;
  }
  
  .welcome-stat-value {
    font-size: 32px;
    font-weight: 800;
  }
  
  .welcome-stat-label {
    font-size: 13px;
    opacity: 0.8;
    margin-top: 4px;
  }
  
  @media (max-width: 768px) {
    .welcome-content { flex-direction: column; gap: 24px; text-align: center; }
    .welcome-stats { justify-content: center; }
  }
</style>

<!-- Welcome Banner -->
<div class="welcome-banner">
  <div class="welcome-content">
    <div class="welcome-text">
      <h1>Hoş Geldiniz<?= $userName ? ', ' . h(explode('@', $userName)[0]) : '' ?>! 👋</h1>
      <p><?= date('d F Y, l') ?> · <?= h($role) ?></p>
    </div>
    <div class="welcome-stats">
      <div class="welcome-stat">
        <div class="welcome-stat-value"><?= $matchRate ?>%</div>
        <div class="welcome-stat-label">Eşleşme Oranı</div>
      </div>
      <div class="welcome-stat">
        <div class="welcome-stat-value"><?= number_format($kpi['monthly_premium'] / 1000, 0) ?>K</div>
        <div class="welcome-stat-label">Bu Ay Prim (₺)</div>
      </div>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card blue">
    <div class="kpi-header">
      <div class="kpi-icon blue">
        <i data-lucide="inbox"></i>
      </div>
      <span class="kpi-trend up">
        <i data-lucide="trending-up"></i>
        Aktif
      </span>
    </div>
    <div class="kpi-label">Açık Ticket</div>
    <div class="kpi-value"><span class="counter" data-target="<?= $kpi['open_tickets'] ?>">0</span></div>
  </div>
  
  <div class="kpi-card orange">
    <div class="kpi-header">
      <div class="kpi-icon orange">
        <i data-lucide="clock"></i>
      </div>
      <span class="kpi-trend neutral">
        <i data-lucide="minus"></i>
        Bekliyor
      </span>
    </div>
    <div class="kpi-label">Beklemede</div>
    <div class="kpi-value"><span class="counter" data-target="<?= $kpi['pending'] ?>">0</span></div>
  </div>
  
  <div class="kpi-card red">
    <div class="kpi-header">
      <div class="kpi-icon red">
        <i data-lucide="alert-triangle"></i>
      </div>
      <span class="kpi-trend down">
        <i data-lucide="alert-circle"></i>
        Dikkat
      </span>
    </div>
    <div class="kpi-label">Eşleşmeyen</div>
    <div class="kpi-value"><span class="counter" data-target="<?= $kpi['unmatched'] ?>">0</span></div>
  </div>
  
  <div class="kpi-card green">
    <div class="kpi-header">
      <div class="kpi-icon green">
        <i data-lucide="trending-up"></i>
      </div>
      <span class="kpi-trend up">
        <i data-lucide="arrow-up"></i>
        +12%
      </span>
    </div>
    <div class="kpi-label">Bu Ay Prim</div>
    <div class="kpi-value"><span class="counter" data-target="<?= round($kpi['monthly_premium']) ?>">0</span><small>₺</small></div>
  </div>
</div>

<!-- Charts Section -->
<div class="charts-grid">
  <!-- Premium Trend Chart -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">
          <i data-lucide="line-chart"></i>
          Prim Trendi
        </h3>
        <p class="card-subtitle">Son 6 aylık brüt prim gelişimi</p>
      </div>
      <a href="<?= route_url('reports') ?>" class="btn-sm">
        Detaylı Rapor
        <i data-lucide="arrow-right"></i>
      </a>
    </div>
    <div class="card-body">
      <div class="chart-container">
        <canvas id="premiumChart"></canvas>
      </div>
    </div>
  </div>
  
  <!-- Mutabakat Status -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">
          <i data-lucide="pie-chart"></i>
          Mutabakat Durumu
        </h3>
        <p class="card-subtitle">Eşleşme oranları</p>
      </div>
    </div>
    <div class="card-body">
      <div class="progress-ring-container">
        <div class="progress-ring">
          <svg width="180" height="180">
            <defs>
              <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" style="stop-color:#16a34a" />
                <stop offset="100%" style="stop-color:#22c55e" />
              </linearGradient>
            </defs>
            <circle class="progress-ring-bg" cx="90" cy="90" r="70"></circle>
            <circle class="progress-ring-fill" cx="90" cy="90" r="70" id="progressCircle"></circle>
          </svg>
          <div class="progress-ring-text">
            <div class="progress-ring-value"><?= $matchRate ?>%</div>
            <div class="progress-ring-label">Eşleşme</div>
          </div>
        </div>
      </div>
      <div class="stats-grid">
        <div class="stat-item green">
          <div class="stat-value"><?= number_format($chartData['mutabakat_status']['eslesen']) ?></div>
          <div class="stat-label">Eşleşen</div>
        </div>
        <div class="stat-item red">
          <div class="stat-value"><?= number_format($chartData['mutabakat_status']['eslesmeyen']) ?></div>
          <div class="stat-label">Eşleşmeyen</div>
        </div>
        <div class="stat-item orange">
          <div class="stat-value"><?= number_format($chartData['mutabakat_status']['itiraz']) ?></div>
          <div class="stat-label">İtiraz</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bottom Section -->
<div class="bottom-grid">
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
        <a href="<?= route_url('ticket-create') ?>" class="quick-link">
          <div class="quick-link-icon">
            <i data-lucide="plus-circle"></i>
          </div>
          <div class="quick-link-text">
            <h4>Yeni Ticket</h4>
            <p>Destek talebi oluştur</p>
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
        <a href="<?= route_url('billing') ?>" class="quick-link">
          <div class="quick-link-icon">
            <i data-lucide="wallet"></i>
          </div>
          <div class="quick-link-text">
            <h4>Faturalama</h4>
            <p>Ödeme ve raporlar</p>
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
      <a href="<?= route_url('tickets') ?>" class="btn-sm">
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

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
  // Initialize Lucide icons
  lucide.createIcons();
  
  // Animated counters
  document.querySelectorAll('.counter').forEach(counter => {
    const target = parseInt(counter.dataset.target) || 0;
    const duration = 1500;
    const start = 0;
    const startTime = performance.now();
    
    function updateCounter(currentTime) {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);
      const easeOut = 1 - Math.pow(1 - progress, 3);
      const current = Math.floor(start + (target - start) * easeOut);
      
      counter.textContent = current.toLocaleString('tr-TR');
      
      if (progress < 1) {
        requestAnimationFrame(updateCounter);
      }
    }
    
    requestAnimationFrame(updateCounter);
  });
  
  // Progress ring animation
  setTimeout(() => {
    const circle = document.getElementById('progressCircle');
    if (circle) {
      const percent = <?= $matchRate ?>;
      const circumference = 2 * Math.PI * 70;
      const offset = circumference - (percent / 100) * circumference;
      circle.style.strokeDashoffset = offset;
    }
  }, 300);
  
  // Premium Chart
  const premiumData = <?= json_encode($chartData['monthly_premiums']) ?>;
  
  const ctx = document.getElementById('premiumChart');
  if (ctx) {
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: premiumData.map(d => d.month),
        datasets: [{
          label: 'Brüt Prim (₺)',
          data: premiumData.map(d => d.value),
          borderColor: '#2563eb',
          backgroundColor: 'rgba(37, 99, 235, 0.1)',
          fill: true,
          tension: 0.4,
          borderWidth: 3,
          pointBackgroundColor: '#2563eb',
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          pointRadius: 6,
          pointHoverRadius: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            backgroundColor: '#0f172a',
            titleColor: '#fff',
            bodyColor: '#fff',
            padding: 12,
            cornerRadius: 8,
            displayColors: false,
            callbacks: {
              label: function(context) {
                return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(context.raw);
              }
            }
          }
        },
        scales: {
          x: {
            grid: {
              display: false
            },
            ticks: {
              font: {
                family: 'Inter',
                weight: 600
              },
              color: '#64748b'
            }
          },
          y: {
            beginAtZero: true,
            grid: {
              color: '#f1f5f9'
            },
            ticks: {
              font: {
                family: 'Inter',
                weight: 600
              },
              color: '#64748b',
              callback: function(value) {
                return (value / 1000) + 'K';
              }
            }
          }
        },
        interaction: {
          intersect: false,
          mode: 'index'
        }
      }
    });
  }
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
