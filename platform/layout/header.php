<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle ?? 'Mutabakat Sistemi') ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    
    :root {
      --bg: #f8fafc;
      --card-bg: rgba(255,255,255,.95);
      --border: rgba(15,23,42,.10);
      --text: #0f172a;
      --text-muted: rgba(15,23,42,.65);
      --primary: #3b82f6;
      --primary-light: rgba(59,130,246,.12);
      --success: #16a34a;
      --success-light: rgba(22,163,74,.12);
      --danger: #dc2626;
      --danger-light: rgba(220,38,38,.08);
      --warning: #f59e0b;
      --warning-light: rgba(245,158,11,.12);
      --radius: 12px;
      --radius-lg: 18px;
      --shadow: 0 14px 40px rgba(2,6,23,.08);
      --shadow-sm: 0 10px 26px rgba(2,6,23,.06);
    }
    
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      font-size: 14px;
      line-height: 1.5;
      color: var(--text);
      background: var(--bg);
    }
    
    a { color: var(--primary); text-decoration: none; }
    a:hover { text-decoration: underline; }
    
    .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 24px 16px;
    }
    
    .card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow);
      padding: 16px;
    }
    
    .card-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }
    
    .card-title {
      font-size: 18px;
      font-weight: 800;
      margin: 0;
    }
    
    .card-subtitle {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 4px;
    }
    
    /* Alerts */
    .alert {
      padding: 10px 12px;
      border-radius: var(--radius);
      font-size: 13px;
      margin-bottom: 12px;
    }
    .alert-success {
      background: #ecfeff;
      border: 1px solid rgba(6,182,212,.25);
      color: #155e75;
    }
    .alert-error {
      background: #fff1f2;
      border: 1px solid rgba(244,63,94,.25);
      color: #9f1239;
    }
    .alert-warning {
      background: var(--warning-light);
      border: 1px solid rgba(245,158,11,.25);
      color: #92400e;
    }
    
    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: var(--radius);
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: all .15s ease;
    }
    .btn:hover { background: rgba(15,23,42,.04); text-decoration: none; }
    
    .btn-primary {
      background: var(--text);
      color: #fff;
      border-color: var(--text);
    }
    .btn-primary:hover { background: #1e293b; }
    
    .btn-success {
      background: var(--success-light);
      color: #065f46;
      border-color: rgba(16,185,129,.35);
    }
    .btn-success:hover { background: rgba(16,185,129,.18); }
    
    .btn-danger {
      background: var(--danger-light);
      color: #9f1239;
      border-color: rgba(244,63,94,.25);
    }
    .btn-danger:hover { background: rgba(220,38,38,.12); }
    
    /* Forms */
    .form-control {
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      font-size: 13px;
      font-family: inherit;
      background: #fff;
      color: var(--text);
      transition: all .15s ease;
    }
    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(59,130,246,.12);
    }
    
    select.form-control {
      cursor: pointer;
    }
    
    /* Tabs */
    .tabs {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }
    .tab {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.75);
      font-size: 13px;
      font-weight: 700;
      color: var(--text);
      text-decoration: none;
      transition: all .15s ease;
    }
    .tab:hover { background: rgba(15,23,42,.04); text-decoration: none; }
    .tab.active {
      background: var(--primary-light);
      border-color: rgba(59,130,246,.25);
    }
    .tab .badge {
      background: var(--text);
      color: #fff;
      padding: 2px 6px;
      border-radius: 999px;
      font-size: 11px;
    }
    
    /* Tables */
    .table-wrapper {
      overflow-x: auto;
      margin: 0 -16px;
      padding: 0 16px;
    }
    
    table.excel-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      font-size: 12.5px;
    }
    
    table.excel-table thead th {
      position: sticky;
      top: 0;
      background: rgba(15,23,42,.04);
      border-bottom: 1px solid var(--border);
      padding: 10px 10px;
      text-align: left;
      font-weight: 700;
      cursor: pointer;
      user-select: none;
      white-space: nowrap;
    }
    
    table.excel-table tbody td {
      padding: 10px 10px;
      border-bottom: 1px solid rgba(15,23,42,.06);
      vertical-align: top;
    }
    
    table.excel-table tbody tr:nth-child(even) { background: rgba(2,6,23,.02); }
    table.excel-table tbody tr:hover { background: rgba(59,130,246,.06); }
    
    /* Money */
    .money { font-weight: 700; font-variant-numeric: tabular-nums; }
    .money.pos { color: var(--success); }
    .money.neg { color: var(--danger); }
    .money.zero { color: var(--text-muted); }
    
    /* Status badges */
    .badge-status {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .badge-havuz { background: rgba(59,130,246,.12); color: #1d4ed8; }
    .badge-eslesen { background: rgba(16,185,129,.12); color: #065f46; }
    .badge-eslesmeyen { background: rgba(239,68,68,.12); color: #9f1239; }
    .badge-itiraz { background: rgba(245,158,11,.12); color: #92400e; }
    
    /* Editable input */
    .cell-input {
      width: 100%;
      border: 1px solid transparent;
      background: transparent;
      padding: 6px 8px;
      border-radius: 10px;
      font: inherit;
      color: inherit;
      outline: none;
    }
    .cell-input:focus {
      background: rgba(255,255,255,.9);
      border-color: rgba(59,130,246,.35);
      box-shadow: 0 0 0 3px rgba(59,130,246,.12);
    }
    .cell-input-right { text-align: right; }
    
    /* Toast */
    .toast {
      position: fixed;
      right: 18px;
      bottom: 18px;
      background: rgba(15,23,42,.92);
      color: #fff;
      padding: 10px 12px;
      border-radius: var(--radius);
      font-size: 12.5px;
      z-index: 9999;
      opacity: 0;
      transform: translateY(6px);
      transition: all .18s ease;
      pointer-events: none;
      max-width: 320px;
    }
    .toast.show { opacity: 1; transform: translateY(0); }
    
    /* Header Nav */
    .header-nav {
      background: var(--card-bg);
      border-bottom: 1px solid var(--border);
      padding: 12px 16px;
      margin-bottom: 24px;
    }
    .header-nav .nav-inner {
      max-width: 1400px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    .header-nav .brand {
      font-size: 16px;
      font-weight: 800;
      color: var(--text);
    }
    .header-nav .nav-links {
      display: flex;
      gap: 16px;
      align-items: center;
    }
    .header-nav .nav-link {
      font-size: 13px;
      font-weight: 600;
      color: var(--text-muted);
    }
    .header-nav .nav-link:hover { color: var(--text); }
    .header-nav .nav-link.active { color: var(--primary); }
    .header-nav .user-info {
      font-size: 12px;
      color: var(--text-muted);
    }
    
    /* Unmatched row highlight */
    .unmatched-row {
      background: var(--danger-light) !important;
    }
    .unmatched-row[data-dirty="1"] {
      outline: 2px solid rgba(239,68,68,.22);
      outline-offset: -2px;
    }
    
    /* Upload box */
    .upload-box {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 14px;
      box-shadow: var(--shadow-sm);
    }
    .upload-box-title {
      font-weight: 800;
      margin-bottom: 8px;
    }
    .upload-box-hint {
      font-size: 12px;
      color: var(--text-muted);
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .container { padding: 16px 12px; }
      .card { padding: 12px; }
      .card-header { flex-direction: column; }
      table.excel-table { font-size: 11px; }
      table.excel-table thead th,
      table.excel-table tbody td { padding: 8px 6px; }
    }
  </style>
</head>
<body>
  <div class="header-nav">
    <div class="nav-inner">
      <a href="<?= base_url('mutabakat/havuz.php') ?>" class="brand">Mutabakat V2</a>
      <div class="nav-links">
        <a href="<?= base_url('mutabakat/havuz.php') ?>" class="nav-link <?= ($currentPage ?? '') === 'havuz' ? 'active' : '' ?>">Havuz</a>
        <a href="<?= base_url('mutabakat/atama.php') ?>" class="nav-link <?= ($currentPage ?? '') === 'atama' ? 'active' : '' ?>">Atama</a>
      </div>
      <div class="user-info">
        <?php if (!empty($_SESSION['name'])): ?>
          <?= h($_SESSION['name']) ?> (<?= h($_SESSION['role'] ?? '') ?>)
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div id="toast" class="toast"></div>
  
  <script>
  function showToast(msg, duration) {
    var el = document.getElementById('toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(function(){ el.classList.remove('show'); }, duration || 2000);
  }
  </script>
