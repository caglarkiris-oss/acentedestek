<?php
// Enterprise Header - Premium Collapsible Sidebar
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../helpers.php';

$appName = (string) config('app.name', 'Acentedestek');
$pageTitle = (string)($pageTitle ?? 'Panel');
$currentPage = (string)($currentPage ?? '');
$userName = (string)($_SESSION['name'] ?? '');
$userRole = (string)($_SESSION['role'] ?? '');
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?> · <?= h($appName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    :root {
      --sidebar-width: 280px;
      --sidebar-collapsed: 80px;
      --topbar-height: 70px;
      --navy-900: #0f172a;
      --navy-800: #1e293b;
      --navy-700: #334155;
      --blue-600: #2563eb;
      --blue-500: #3b82f6;
      --gray-50: #f8fafc;
      --gray-100: #f1f5f9;
      --gray-200: #e2e8f0;
      --gray-300: #cbd5e1;
      --gray-400: #94a3b8;
      --gray-500: #64748b;
      --gray-600: #475569;
      --gray-900: #0f172a;
      --success: #16a34a;
      --warning: #f59e0b;
      --danger: #dc2626;
    }
    
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      font-size: 14px;
      line-height: 1.5;
      color: var(--gray-900);
      background: var(--gray-50);
      min-height: 100vh;
    }

    /* ===== APP LAYOUT ===== */
    .app {
      display: flex;
      min-height: 100vh;
    }

    /* ===== SIDEBAR ===== */
    .sidebar {
      position: fixed;
      left: 0;
      top: 0;
      width: var(--sidebar-width);
      height: 100vh;
      background: linear-gradient(180deg, var(--navy-900) 0%, #0c1222 100%);
      display: flex;
      flex-direction: column;
      z-index: 100;
      transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 4px 0 30px rgba(0, 0, 0, 0.15);
    }
    
    .sidebar.collapsed {
      width: var(--sidebar-collapsed);
    }

    .sidebar-header {
      padding: 24px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      display: flex;
      align-items: center;
      justify-content: space-between;
      min-height: 80px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 14px;
      overflow: hidden;
      transition: opacity 0.2s;
    }
    
    .sidebar.collapsed .brand { opacity: 0; width: 0; }

    .brand-icon {
      width: 42px;
      height: 42px;
      background: linear-gradient(135deg, var(--blue-600) 0%, var(--blue-500) 100%);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    
    .brand-icon svg { width: 22px; height: 22px; color: white; }

    .brand-text h1 {
      font-size: 17px;
      font-weight: 800;
      color: white;
      letter-spacing: -0.3px;
      white-space: nowrap;
    }

    .brand-text span {
      font-size: 11px;
      color: rgba(255, 255, 255, 0.5);
      font-weight: 500;
      white-space: nowrap;
    }

    .sidebar-toggle {
      width: 38px;
      height: 38px;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: rgba(255, 255, 255, 0.7);
      cursor: pointer;
      transition: all 0.2s;
      flex-shrink: 0;
    }
    
    .sidebar-toggle:hover {
      background: rgba(255, 255, 255, 0.1);
      color: white;
    }
    
    .sidebar-toggle svg { width: 18px; height: 18px; }

    /* Navigation */
    .sidebar-nav {
      flex: 1;
      padding: 16px 12px;
      overflow-y: auto;
      overflow-x: hidden;
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 12px 14px;
      margin-bottom: 4px;
      border-radius: 12px;
      color: rgba(255, 255, 255, 0.6);
      text-decoration: none;
      font-size: 14px;
      font-weight: 500;
      transition: all 0.2s;
      position: relative;
      white-space: nowrap;
    }

    .nav-item:hover {
      background: rgba(255, 255, 255, 0.06);
      color: white;
    }

    .nav-item.active {
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.2) 0%, rgba(37, 99, 235, 0.1) 100%);
      color: white;
    }

    .nav-item.active::before {
      content: '';
      position: absolute;
      left: 0;
      top: 50%;
      transform: translateY(-50%);
      width: 4px;
      height: 24px;
      background: var(--blue-500);
      border-radius: 0 4px 4px 0;
    }

    .nav-icon {
      width: 22px;
      height: 22px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    
    .nav-icon svg { width: 20px; height: 20px; }

    .nav-text {
      transition: opacity 0.2s, width 0.2s;
    }
    
    .sidebar.collapsed .nav-text {
      opacity: 0;
      width: 0;
      overflow: hidden;
    }
    
    .sidebar.collapsed .nav-item {
      justify-content: center;
      padding: 14px;
    }

    /* Tooltip for collapsed */
    .sidebar.collapsed .nav-item::after {
      content: attr(data-title);
      position: absolute;
      left: calc(100% + 12px);
      top: 50%;
      transform: translateY(-50%);
      padding: 8px 14px;
      background: var(--navy-900);
      color: white;
      font-size: 13px;
      font-weight: 600;
      border-radius: 8px;
      white-space: nowrap;
      opacity: 0;
      visibility: hidden;
      transition: all 0.2s;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
      z-index: 200;
    }
    
    .sidebar.collapsed .nav-item:hover::after {
      opacity: 1;
      visibility: visible;
    }

    /* Sidebar Footer */
    .sidebar-footer {
      padding: 16px;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
    }

    .user-box {
      padding: 14px;
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(255, 255, 255, 0.06);
      border-radius: 12px;
      transition: opacity 0.2s;
    }
    
    .sidebar.collapsed .user-box { opacity: 0; height: 0; padding: 0; border: none; overflow: hidden; }

    .user-name {
      font-size: 13px;
      font-weight: 600;
      color: white;
      margin-bottom: 2px;
    }

    .user-role {
      font-size: 11px;
      color: rgba(255, 255, 255, 0.5);
    }

    /* ===== MAIN CONTENT ===== */
    .main {
      flex: 1;
      margin-left: var(--sidebar-width);
      display: flex;
      flex-direction: column;
      min-width: 0;
      transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .sidebar.collapsed ~ .main {
      margin-left: var(--sidebar-collapsed);
    }

    /* Topbar */
    .topbar {
      position: sticky;
      top: 0;
      z-index: 50;
      height: var(--topbar-height);
      background: rgba(255, 255, 255, 0.9);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--gray-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 32px;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 20px;
    }

    .topbar-title {
      font-size: 20px;
      font-weight: 700;
      color: var(--gray-900);
      letter-spacing: -0.3px;
    }

    .topbar-meta {
      font-size: 13px;
      color: var(--gray-500);
      font-weight: 500;
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .topbar-btn {
      width: 42px;
      height: 42px;
      background: white;
      border: 1px solid var(--gray-200);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--gray-600);
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .topbar-btn:hover {
      background: var(--gray-50);
      border-color: var(--gray-300);
      color: var(--gray-900);
    }
    
    .topbar-btn svg { width: 20px; height: 20px; }

    .user-menu {
      position: relative;
    }

    .user-menu-btn {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 14px;
      background: white;
      border: 1px solid var(--gray-200);
      border-radius: 12px;
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .user-menu-btn:hover {
      background: var(--gray-50);
      border-color: var(--gray-300);
    }

    .user-avatar {
      width: 34px;
      height: 34px;
      background: linear-gradient(135deg, var(--blue-600), var(--blue-500));
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 13px;
      font-weight: 700;
    }

    .user-menu-btn svg { width: 16px; height: 16px; color: var(--gray-400); }

    .user-dropdown {
      position: absolute;
      top: calc(100% + 8px);
      right: 0;
      width: 220px;
      background: white;
      border: 1px solid var(--gray-200);
      border-radius: 14px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
      padding: 8px;
      display: none;
      z-index: 100;
    }
    
    .user-menu.open .user-dropdown { display: block; }

    .dropdown-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      border-radius: 10px;
      font-size: 14px;
      font-weight: 500;
      color: var(--gray-700);
      text-decoration: none;
      transition: all 0.15s;
    }
    
    .dropdown-item:hover {
      background: var(--gray-50);
      color: var(--gray-900);
    }
    
    .dropdown-item svg { width: 18px; height: 18px; }

    .dropdown-divider {
      height: 1px;
      background: var(--gray-100);
      margin: 6px 0;
    }

    /* Page Content */
    .page-content {
      flex: 1;
      padding: 32px;
      background: var(--gray-50);
    }

    .page-inner {
      max-width: 1400px;
      margin: 0 auto;
    }

    /* Mobile */
    .mobile-toggle {
      display: none;
    }

    @media (max-width: 1024px) {
      .sidebar {
        transform: translateX(-100%);
        width: 280px !important;
      }
      
      .sidebar.mobile-open {
        transform: translateX(0);
      }
      
      .main {
        margin-left: 0 !important;
      }
      
      .mobile-toggle {
        display: flex;
      }
      
      .page-content {
        padding: 20px;
      }
      
      .topbar {
        padding: 0 20px;
      }
    }
  </style>
</head>
<body>
<div class="app">
  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="brand">
        <div class="brand-icon">
          <i data-lucide="shield-check"></i>
        </div>
        <div class="brand-text">
          <h1><?= h($appName) ?></h1>
          <span>Sigorta & Mutabakat</span>
        </div>
      </div>
      <button class="sidebar-toggle" onclick="toggleSidebar()">
        <i data-lucide="panel-left-close"></i>
      </button>
    </div>

    <nav class="sidebar-nav">
      <a href="<?= route_url('dashboard') ?>" class="nav-item <?= $currentPage==='dashboard'?'active':'' ?>" data-title="Dashboard">
        <span class="nav-icon"><i data-lucide="layout-dashboard"></i></span>
        <span class="nav-text">Dashboard</span>
      </a>
      <a href="<?= route_url('tickets') ?>" class="nav-item <?= $currentPage==='tickets'?'active':'' ?>" data-title="Tickets">
        <span class="nav-icon"><i data-lucide="ticket"></i></span>
        <span class="nav-text">Tickets</span>
      </a>
      <a href="<?= route_url('mutabakat/havuz') ?>" class="nav-item <?= in_array($currentPage,['havuz','mutabakat'])?'active':'' ?>" data-title="Mutabakat">
        <span class="nav-icon"><i data-lucide="git-compare"></i></span>
        <span class="nav-text">Mutabakat</span>
      </a>
      <a href="<?= route_url('agencies') ?>" class="nav-item <?= $currentPage==='agencies'?'active':'' ?>" data-title="Acenteler">
        <span class="nav-icon"><i data-lucide="building-2"></i></span>
        <span class="nav-text">Acenteler</span>
      </a>
      <a href="<?= route_url('users') ?>" class="nav-item <?= $currentPage==='users'?'active':'' ?>" data-title="Kullanıcılar">
        <span class="nav-icon"><i data-lucide="users"></i></span>
        <span class="nav-text">Kullanıcılar</span>
      </a>
      <a href="<?= route_url('reports') ?>" class="nav-item <?= $currentPage==='reports'?'active':'' ?>" data-title="Raporlar">
        <span class="nav-icon"><i data-lucide="bar-chart-3"></i></span>
        <span class="nav-text">Raporlar</span>
      </a>
      <a href="<?= route_url('billing') ?>" class="nav-item <?= $currentPage==='billing'?'active':'' ?>" data-title="Faturalama">
        <span class="nav-icon"><i data-lucide="wallet"></i></span>
        <span class="nav-text">Faturalama</span>
      </a>
      <a href="<?= route_url('logs') ?>" class="nav-item <?= $currentPage==='logs'?'active':'' ?>" data-title="Logs">
        <span class="nav-icon"><i data-lucide="scroll-text"></i></span>
        <span class="nav-text">Logs</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="user-box">
        <div class="user-name"><?= $userName ? h($userName) : 'Kullanıcı' ?></div>
        <div class="user-role"><?= $userRole ? h($userRole) : 'Rol' ?></div>
      </div>
    </div>
  </aside>

  <!-- Main -->
  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <button class="topbar-btn mobile-toggle" onclick="toggleMobileSidebar()">
          <i data-lucide="menu"></i>
        </button>
        <div>
          <h1 class="topbar-title"><?= h($pageTitle) ?></h1>
          <div class="topbar-meta"><?= $userRole ? h($userRole) : '' ?><?= $userName ? ' · '.h($userName) : '' ?></div>
        </div>
      </div>
      <div class="topbar-right">
        <!-- Dark Mode Toggle -->
        <button class="topbar-btn" onclick="toggleDarkMode()" title="Karanlık/Aydınlık Mod">
          <i data-lucide="moon" id="darkModeIcon"></i>
        </button>
        
        <!-- Notifications -->
        <div class="notification-wrapper">
          <button class="topbar-btn" onclick="toggleNotifications()" id="notifBtn">
            <i data-lucide="bell"></i>
            <span class="notification-badge" id="notifBadge">3</span>
          </button>
          <div class="notification-dropdown" id="notifDropdown">
            <div class="notif-header">
              <span>Bildirimler</span>
              <button onclick="clearAllNotifs()">Tümünü Temizle</button>
            </div>
            <div class="notif-list">
              <div class="notif-item unread">
                <div class="notif-icon green"><i data-lucide="check-circle"></i></div>
                <div class="notif-content">
                  <div class="notif-title">Yeni ticket yanıtlandı</div>
                  <div class="notif-time">2 dakika önce</div>
                </div>
              </div>
              <div class="notif-item unread">
                <div class="notif-icon orange"><i data-lucide="alert-triangle"></i></div>
                <div class="notif-content">
                  <div class="notif-title">5 eşleşmeyen kayıt var</div>
                  <div class="notif-time">1 saat önce</div>
                </div>
              </div>
              <div class="notif-item">
                <div class="notif-icon blue"><i data-lucide="file-text"></i></div>
                <div class="notif-content">
                  <div class="notif-title">Aylık rapor hazır</div>
                  <div class="notif-time">Dün</div>
                </div>
              </div>
            </div>
            <a href="#" class="notif-footer">Tüm bildirimleri gör</a>
          </div>
        </div>
        
        <!-- User Menu -->
        <div class="user-menu" id="userMenu">
          <button class="user-menu-btn" onclick="toggleUserMenu()">
            <div class="user-avatar"><?= $userName ? strtoupper(substr($userName,0,2)) : 'U' ?></div>
            <i data-lucide="chevron-down"></i>
          </button>
          <div class="user-dropdown">
            <a href="<?= route_url('agency-profile') ?>" class="dropdown-item">
              <i data-lucide="settings"></i>
              Profil Ayarları
            </a>
            <div class="dropdown-divider"></div>
            <a href="<?= route_url('logout') ?>" class="dropdown-item">
              <i data-lucide="log-out"></i>
              Çıkış Yap
            </a>
          </div>
        </div>
      </div>
    </header>

    <main class="page-content">
      <div class="page-inner">
