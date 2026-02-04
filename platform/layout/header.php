<?php
// /platform/layout/header.php
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
  <link rel="stylesheet" href="<?= base_url('layout/app.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../assets/icons/sprite.svg'; ?>
<div class="app-shell" id="appShell">
  <aside class="sidebar" aria-label="Ana Menü">
    <div class="sidebar-brand">
      <a href="<?= route_url('dashboard') ?>" class="brand-link">
        <div class="brand-title"><?= h($appName) ?></div>
        <div class="brand-sub">Sigorta & Mutabakat SaaS</div>
      </a>
      <button class="icon-btn" type="button" data-sidebar-toggle aria-label="Menü">
        <svg class="icon" aria-hidden="true"><use href="#i-menu"></use></svg>
      </button>
    </div>

    <nav class="sidebar-nav">
      <a class="nav-item <?= $currentPage==='dashboard' ? 'is-active':'' ?>" href="<?= route_url('dashboard') ?>">
        <span class="nav-icon"><svg class="icon" aria-hidden="true"><use href="#i-dashboard"></use></svg></span><span>Dashboard</span>
      </a>
      <a class="nav-item <?= $currentPage==='tickets' ? 'is-active':'' ?>" href="<?= route_url('tickets') ?>">
        <span class="nav-icon"><svg class="icon" aria-hidden="true"><use href="#i-ticket"></use></svg></span><span>Tickets</span>
      </a>
      <a class="nav-item <?= in_array($currentPage, ['havuz','atama','itiraz','mutabakat'], true) ? 'is-active':'' ?>" href="<?= route_url('mutabakat/havuz') ?>">
        <span class="nav-icon"><svg class="icon" aria-hidden="true"><use href="#i-swap"></use></svg></span><span>Mutabakat</span>
      </a>

      <a class="nav-item <?= $currentPage==='agencies' ? 'is-active':'' ?>" href="<?= route_url('agencies') ?>">
        <span class="nav-icon"><svg class="icon" aria-hidden="true"><use href="#i-building"></use></svg></span><span>Acenteler</span>
      </a>
      <a class="nav-item <?= $currentPage==='users' ? 'is-active':'' ?>" href="<?= route_url('users') ?>">
        <span class="nav-icon"><svg class="icon" aria-hidden="true"><use href="#i-users"></use></svg></span><span>Kullanıcılar</span>
      </a>
      <a class="nav-item <?= $currentPage==='reports' ? 'is-active':'' ?>" href="<?= route_url('reports') ?>">
        <span class="nav-icon"><svg class="icon" aria-hidden="true"><use href="#i-report"></use></svg></span><span>Raporlar</span>
      </a>
      <a class="nav-item <?= $currentPage==='billing' ? 'is-active':'' ?>" href="<?= route_url('billing') ?>">
        <span class="nav-icon"><svg class="icon" aria-hidden="true"><use href="#i-lira"></use></svg></span><span>Faturalama</span>
      </a>
      <a class="nav-item <?= $currentPage==='logs' ? 'is-active':'' ?>" href="<?= route_url('logs') ?>">
        <span class="nav-icon"><svg class="icon" aria-hidden="true"><use href="#i-log"></use></svg></span><span>Logs</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-mini">
        <div><strong><?= $userName ? h($userName) : '—' ?></strong></div>
        <div><?= $userRole ? h($userRole) : 'Oturum' ?></div>
      </div>
    </div>
  </aside>

  <div class="app-main">
    <header class="topbar">
      <div class="u-flex u-center u-gap-3">
        <button class="icon-btn" type="button" data-sidebar-toggle aria-label="Menü">
          <svg class="icon" aria-hidden="true"><use href="#i-menu"></use></svg>
        </button>
        <div class="u-col">
          <h1 class="topbar-title"><?= h($pageTitle) ?></h1>
          <div class="topbar-meta"><?= h($userRole) ?><?= $userName ? ' · '.h($userName) : '' ?></div>
        </div>
      </div>

      <div class="u-flex u-center u-gap-3">
        <div class="dd">
          <button class="btn btn-ghost btn-sm" type="button" data-dd-toggle>
            Hesap <svg class="icon icon-sm" aria-hidden="true"><use href="#i-chevron-down"></use></svg>
          </button>
          <div class="dd-menu" role="menu">
            <a class="dd-item" href="<?= route_url('agency-profile') ?>">Profil / Ayarlar</a>
            <div class="dd-sep"></div>
            <a class="dd-item" href="<?= route_url('logout') ?>">Çıkış</a>
          </div>
        </div>
      </div>
    </header>

    <main class="content">
      <div class="content-inner">
