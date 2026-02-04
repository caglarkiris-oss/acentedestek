<?php
// /platform/layout/header.php
// Enterprise Design System - Premium Header with Collapsible Sidebar

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../helpers.php';

$appName  = (string) config('app.name', 'Acentedestek');
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
  
  <!-- Preconnect for performance -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  
  <!-- Inter Font -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  
  <!-- Lucide Icons -->
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  
  <!-- Design System CSS -->
  <link rel="stylesheet" href="<?= base_url('layout/app.css') ?>">
  
  <style>
    /* Ensure Inter is used */
    body, * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
  </style>
</head>
<body>
<div class="sidebar-overlay"></div>
<div class="app-shell" id="appShell">
  <!-- ===== SIDEBAR ===== -->
  <aside class="sidebar" aria-label="Ana Menü">
    <!-- Brand -->
    <div class="sidebar-brand">
      <a href="<?= route_url('dashboard') ?>" class="brand-link">
        <div class="brand-title"><?= h($appName) ?></div>
        <div class="brand-sub">Sigorta & Mutabakat SaaS</div>
      </a>
      <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menüyü Daralt/Genişlet">
        <i data-lucide="panel-left-close"></i>
      </button>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
      <a class="nav-item <?= $currentPage==='dashboard' ? 'is-active':'' ?>" href="<?= route_url('dashboard') ?>" data-tooltip="Dashboard">
        <span class="nav-icon"><i data-lucide="layout-dashboard"></i></span>
        <span>Dashboard</span>
      </a>
      
      <a class="nav-item <?= $currentPage==='tickets' ? 'is-active':'' ?>" href="<?= route_url('tickets') ?>" data-tooltip="Tickets">
        <span class="nav-icon"><i data-lucide="ticket"></i></span>
        <span>Tickets</span>
      </a>
      
      <a class="nav-item <?= in_array($currentPage, ['havuz','atama','itiraz','mutabakat'], true) ? 'is-active':'' ?>" href="<?= route_url('mutabakat/havuz') ?>" data-tooltip="Mutabakat">
        <span class="nav-icon"><i data-lucide="git-compare"></i></span>
        <span>Mutabakat</span>
      </a>
      
      <a class="nav-item <?= $currentPage==='agencies' ? 'is-active':'' ?>" href="<?= route_url('agencies') ?>" data-tooltip="Acenteler">
        <span class="nav-icon"><i data-lucide="building-2"></i></span>
        <span>Acenteler</span>
      </a>
      
      <a class="nav-item <?= $currentPage==='users' ? 'is-active':'' ?>" href="<?= route_url('users') ?>" data-tooltip="Kullanıcılar">
        <span class="nav-icon"><i data-lucide="users"></i></span>
        <span>Kullanıcılar</span>
      </a>
      
      <a class="nav-item <?= $currentPage==='reports' ? 'is-active':'' ?>" href="<?= route_url('reports') ?>" data-tooltip="Raporlar">
        <span class="nav-icon"><i data-lucide="bar-chart-3"></i></span>
        <span>Raporlar</span>
      </a>
      
      <a class="nav-item <?= $currentPage==='billing' ? 'is-active':'' ?>" href="<?= route_url('billing') ?>" data-tooltip="Faturalama">
        <span class="nav-icon"><i data-lucide="wallet"></i></span>
        <span>Faturalama</span>
      </a>
      
      <a class="nav-item <?= $currentPage==='logs' ? 'is-active':'' ?>" href="<?= route_url('logs') ?>" data-tooltip="Logs">
        <span class="nav-icon"><i data-lucide="scroll-text"></i></span>
        <span>Logs</span>
      </a>
    </nav>

    <!-- Footer -->
    <div class="sidebar-footer">
      <div class="sidebar-mini">
        <strong><?= $userName ? h($userName) : '—' ?></strong>
        <div><?= $userRole ? h($userRole) : 'Oturum' ?></div>
      </div>
    </div>
  </aside>

  <!-- ===== MAIN CONTENT ===== -->
  <div class="app-main">
    <!-- Topbar -->
    <header class="topbar">
      <div class="topbar-left u-flex u-items-center u-gap-4">
        <button class="icon-btn" type="button" data-sidebar-toggle aria-label="Menü">
          <i data-lucide="menu"></i>
        </button>
        <div>
          <h1 class="topbar-title"><?= h($pageTitle) ?></h1>
          <div class="topbar-meta"><?= h($userRole) ?><?= $userName ? ' · '.h($userName) : '' ?></div>
        </div>
      </div>

      <div class="topbar-right u-flex u-items-center u-gap-3">
        <!-- Notifications (placeholder) -->
        <button class="icon-btn" type="button" aria-label="Bildirimler">
          <i data-lucide="bell"></i>
        </button>
        
        <!-- User Dropdown -->
        <div class="dd">
          <button class="btn btn-ghost" type="button" data-dd-toggle>
            <i data-lucide="user"></i>
            <span class="u-hidden" style="display: none;">Hesap</span>
            <i data-lucide="chevron-down" class="icon-sm"></i>
          </button>
          <div class="dd-menu" role="menu">
            <a class="dd-item" href="<?= route_url('agency-profile') ?>">
              <i data-lucide="settings"></i>
              Profil / Ayarlar
            </a>
            <div class="dd-sep"></div>
            <a class="dd-item" href="<?= route_url('logout') ?>">
              <i data-lucide="log-out"></i>
              Çıkış
            </a>
          </div>
        </div>
      </div>
    </header>

    <!-- Page Content -->
    <main class="content">
      <div class="content-inner">
