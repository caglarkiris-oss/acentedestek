<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

$appName  = (string) config('app.name', 'Acentedestek');
$userName = (string)($_SESSION['name'] ?? '');
$userRole = (string)($_SESSION['role'] ?? '');

$userId   = (int)($_SESSION['user_id'] ?? 0);
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$conn = null;
if (function_exists('db')) {
  $conn = db();
}

/* =========================================================
   ✅ Hem dosya adı, hem path (klasörlü sayfalar için)
========================================================= */
$currentFile = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
$currentPath = (string)($_SERVER['PHP_SELF'] ?? '/platform/dashboard.php'); // örn: /platform/mutabakat/ticket.php

function platform_base(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? '';
  return $scheme . '://' . $host . '/platform/';
}
function asset_url(string $path): string {
  $path = ltrim($path, '/');
  return platform_base() . $path;
}
function page_url(string $file): string {
  $file = ltrim($file, '/');
  return platform_base() . $file;
}
function mutabakat_url(string $file): string {
  $file = ltrim($file, '/');
  return platform_base() . 'mutabakat/' . $file;
}

function is_active_file(string $file, string $currentFile): string {
  return $file === $currentFile ? 'is-active' : '';
}
function is_active_path(string $needlePath, string $currentPath): string {
  return (strpos($currentPath, $needlePath) !== false) ? 'is-active' : '';
}

function role_label(string $role): string {
  if ($role === 'SUPERADMIN') return 'Süper Admin';
  if ($role === 'ACENTE_YETKILISI') return 'Acente Yetkilisi';
  if ($role === 'TALI_ACENTE_YETKILISI') return 'Tali Acente Yetkilisi';
  if ($role === 'PERSONEL') return 'Personel';
  return $role;
}

/* =========================================================
   ✅ Tali mutabakat çalışma modu: ticket / csv
   - Öncelik: session -> agencies.mutabakat_mode -> users.mutabakat_mode -> ticket
========================================================= */
function safe_fetch_mode_from_db($conn, int $agencyId, int $userId): string {
  // default
  $mode = 'ticket';

  if (!$conn) return $mode;

  // 1) agencies.mutabakat_mode
  if ($agencyId > 0) {
    try {
      $sql = "SELECT mutabakat_mode FROM agencies WHERE id=? LIMIT 1";
      $st = $conn->prepare($sql);
      if ($st) {
        $st->bind_param("i", $agencyId);
        if ($st->execute()) {
          $res = $st->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $val = strtolower(trim((string)($row['mutabakat_mode'] ?? '')));
            if (in_array($val, ['ticket','csv'], true)) $mode = $val;
          }
        }
        $st->close();
      }
    } catch (\Throwable $e) {
      // sessiz
    }
  }

  // 2) users.mutabakat_mode (agency'den gelmediyse)
  if ($mode === 'ticket' && $userId > 0) {
    try {
      $sql = "SELECT mutabakat_mode FROM users WHERE id=? LIMIT 1";
      $st = $conn->prepare($sql);
      if ($st) {
        $st->bind_param("i", $userId);
        if ($st->execute()) {
          $res = $st->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $val = strtolower(trim((string)($row['mutabakat_mode'] ?? '')));
            if (in_array($val, ['ticket','csv'], true)) $mode = $val;
          }
        }
        $st->close();
      }
    } catch (\Throwable $e) {
      // sessiz
    }
  }

  return $mode;
}

/* =========================================================
   ✅ USER WORKMODE (ticket/csv) - Tali için agency.work_mode veya session override
   - Öncelik: session -> agencies.work_mode -> users.work_mode -> ticket
========================================================= */
function safe_fetch_workmode_from_db($conn, int $agencyId, int $userId): string {
  $mode = 'ticket';
  if (!$conn) return $mode;

  // agencies.work_mode
  if ($agencyId > 0) {
    try {
      $sql = "SELECT work_mode FROM agencies WHERE id=? LIMIT 1";
      $st = $conn->prepare($sql);
      if ($st) {
        $st->bind_param("i", $agencyId);
        if ($st->execute()) {
          $res = $st->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $val = strtolower(trim((string)($row['work_mode'] ?? '')));
            if (in_array($val, ['ticket','csv'], true)) $mode = $val;
          }
        }
        $st->close();
      }
    } catch (\Throwable $e) {}
  }

  // users.work_mode (agency'den gelmediyse)
  if ($mode === 'ticket' && $userId > 0) {
    try {
      $sql = "SELECT work_mode FROM users WHERE id=? LIMIT 1";
      $st = $conn->prepare($sql);
      if ($st) {
        $st->bind_param("i", $userId);
        if ($st->execute()) {
          $res = $st->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $val = strtolower(trim((string)($row['work_mode'] ?? '')));
            if (in_array($val, ['ticket','csv'], true)) $mode = $val;
          }
        }
        $st->close();
      }
    } catch (\Throwable $e) {}
  }

  return $mode;
}

/* =========================================================
   ✅ Tali MODE -> artık "work_mode" üzerinden
========================================================= */
$taliMode = 'ticket';
if ($userRole === 'TALI_ACENTE_YETKILISI') {
  // session override (hem mutabakat_mode hem work_mode destek)
  $sessMode = strtolower(trim((string)($_SESSION['work_mode'] ?? ($_SESSION['mutabakat_mode'] ?? ''))));
  if (in_array($sessMode, ['ticket','csv'], true)) {
    $taliMode = $sessMode;
  } else {
    // work_mode esas
    $taliMode = safe_fetch_workmode_from_db($conn, $agencyId, $userId);
    // fallback: mutabakat_mode (eski kurgu)
    if (!in_array($taliMode, ['ticket','csv'], true)) {
      $taliMode = safe_fetch_mode_from_db($conn, $agencyId, $userId);
    }
  }
}

/* =========================================================
   ✅ Menü görünürlük yardımcıları
========================================================= */
function col_has_visible_items(array $col, string $role): bool {
  foreach (($col['items'] ?? []) as $it) {
    if (!empty($it['roles']) && !in_array($role, $it['roles'], true)) continue;
    if (isset($it['show']) && $it['show'] === false) continue;
    return true;
  }
  return false;
}
function filter_visible_cols(array $cols, string $role): array {
  $visible = [];
  foreach ($cols as $col) if (col_has_visible_items($col, $role)) $visible[] = $col;
  return $visible;
}

$profileHref  = page_url('agencies-profile.php');

/* =========================================================
   ✅ Mutabakat mega aktif mi? (PERSONEL zaten görmeyecek)
========================================================= */
$mutabakatMegaActive = '';
if ($userRole !== 'PERSONEL') {
  $mutabakatMegaActive = (
    strpos($currentPath, '/platform/mutabakat/') !== false
    || in_array($currentFile, ['odemeler.php'], true)
  ) ? 'is-active' : '';
}

/* =========================================================
   ✅ HEADER UNREAD (ilk render) - BLINK FIX
========================================================= */
$ticketUnread = 0;

/* =========================================================
   ✅ Mutabakat menü item'larını ROLE + WORKMODE'a göre üret
========================================================= */
$mutabakatItems = [
  [
    'label' => 'Havuz',
    'icon'  => 'bi-collection',
    'href'  => mutabakat_url('havuz.php'),
    'roles' => ['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI'],
  ],
  [
    'label' => 'Atama',
    'icon'  => 'bi-diagram-3',
    'href'  => mutabakat_url('atama.php'),
    'roles' => ['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI'],
  ],
  [
    'label' => 'Ödemeler',
    'icon'  => 'bi-cash-coin',
    'href'  => page_url('odemeler.php'),
    'roles' => ['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI'],
  ],
];

/* Menü */
$menu = [
  ['type'=>'link','label'=>'Dashboard','icon'=>'bi-speedometer2','href'=>page_url('dashboard.php'),'file'=>'dashboard.php',
    'roles'=>['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL']],

  ['type'=>'link','label'=>'Raporlar','icon'=>'bi-bar-chart-line','href'=>page_url('reports.php'),'file'=>'reports.php',
    'roles'=>['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL']],

  // ✅ Mutabakat MEGA MENÜ (PERSONEL GÖRMEYECEK)
  [
    'type'  => 'mega',
    'label' => 'Mutabakat',
    'icon'  => 'bi-shuffle',
    'roles' => ['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI'],
    'cols'  => [
      [
        'title' => 'Mutabakat',
        'items' => $mutabakatItems,
      ],
    ],
  ],

  ['type'=>'mega','label'=>'Yönetim','icon'=>'bi-gear-wide-connected','roles'=>['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI'],'cols'=>[
    ['title'=>'Acenteler','items'=>[
      ['label'=>'Acenteler (Liste)','href'=>page_url('agencies.php'),'roles'=>['SUPERADMIN']],
      ['label'=>'Acente Oluştur','href'=>page_url('agencies-create.php'),'roles'=>['SUPERADMIN']],
      ['label'=>'Tali Acenteler','href'=>page_url('agencies.php'),'roles'=>['ACENTE_YETKILISI']],
      ['label'=>'Tali Acente Oluştur','href'=>page_url('agencies-create.php'),'roles'=>['ACENTE_YETKILISI']],
    ]],
    ['title'=>'Kullanıcılar','items'=>[
      ['label'=>'Kullanıcı Yönetimi','href'=>page_url('users.php'),'roles'=>['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI']],
      ['label'=>'Yeni Kullanıcı','href'=>page_url('users-create.php'),'roles'=>['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI']],
    ]],
    ['title'=>'Sistem','items'=>[
      ['label'=>'Güvenlik (yakında)','href'=>'#','roles'=>['SUPERADMIN']],
      ['label'=>'Loglar (yakında)','href'=>'#','roles'=>['SUPERADMIN']],
    ]],
  ]],
];

$themeHref = asset_url('layout/theme.css') . '?v=' . time();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8" />
<title><?= htmlspecialchars($appName) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars($themeHref) ?>">

<style>
.btn-ticket{ position: relative; }
.btn-badge{
  display:inline-flex; align-items:center; justify-content:center;
  min-width: 20px; height: 20px; padding: 0 6px;
  border-radius: 999px; font-size: 12px; font-weight: 700; line-height: 1;
  margin-left: 8px; background: #ef4444; color: #fff;
}
.ticket-dd{ position:relative; display:inline-block; }
.ticket-menu{
  position:absolute; right:0; top:calc(100% + 10px);
  min-width: 240px; background:#fff;
  border:1px solid rgba(15,23,42,.12);
  border-radius:14px; padding:8px;
  box-shadow: 0 14px 40px rgba(15,23,42,.14);
  display:none; z-index:9999;
}
.ticket-dd.open .ticket-menu{ display:block; }
.ticket-menu .pm-item{
  display:block; padding:10px 12px; border-radius:12px;
  text-decoration:none; color:inherit;
}
.ticket-menu .pm-item:hover{ background: rgba(15,23,42,.06); }
.ticket-menu .pm-sub{ display:block; font-size: 12px; opacity:.7; margin-top:2px; }
</style>
</head>
<body>

<header class="topbar">
  <div class="container topbar-inner">
    <div class="brand">
      <span class="brand-dot"></span>
      <span class="brand-name"><?= htmlspecialchars($appName) ?></span>
    </div>

    <div class="right top-actions">
      <?php if (($userRole ?? '') !== 'SUPERADMIN'): ?>
        <div class="ticket-dd" data-ticketdd>
          <button class="btn primary btn-ticket" type="button" aria-haspopup="menu" aria-expanded="false">
            Ticket Kutusu
            <span class="btn-badge" data-ticketbadge style="display:none;"></span>
            <span class="caret" style="margin-left:8px;">▾</span>
          </button>

          <div class="ticket-menu" role="menu">
            <a class="pm-item" role="menuitem" href="<?= htmlspecialchars(page_url('ticket-create.php')) ?>">
              Ticket Aç
              <span class="pm-sub">Hedef seç ve yeni talep oluştur</span>
            </a>

            <a class="pm-item" role="menuitem" href="<?= htmlspecialchars(page_url('tickets.php')) ?>">
              Ticketlar
              <span class="pm-sub">Gelen / Gönderilen / filtrele</span>
            </a>
          </div>
        </div>
      <?php endif; ?>

      <div class="profile-dd" data-profile>
        <button class="avatar-btn" type="button" aria-haspopup="menu" aria-expanded="false" title="Profil">
          <span class="avatar" aria-hidden="true"></span>
          <span class="sr-only">Profil menüsü</span>
        </button>

        <div class="profile-menu" role="menu">
          <div class="pm-list">
            <a class="pm-item" role="menuitem" href="<?= htmlspecialchars($profileHref) ?>">
              Profil
              <span class="pm-sub">Bilgilerimi düzenle</span>
            </a>

            <a class="pm-item danger" role="menuitem" href="<?= htmlspecialchars(page_url('logout.php')) ?>">
              Çıkış
              <span class="pm-sub">Oturumu kapat</span>
            </a>
          </div>
        </div>
      </div>

    </div>
  </div>

  <div class="navrow">
    <div class="container navrow-inner">
      <nav class="nav">
        <?php foreach ($menu as $item): ?>
          <?php if (!empty($item['roles']) && !in_array($userRole, $item['roles'], true)) continue; ?>

          <?php if (($item['type'] ?? '') === 'link'): ?>
            <a class="nav-link <?= is_active_file($item['file'], $currentFile) ?>" href="<?= htmlspecialchars($item['href']) ?>">
              <?php if (!empty($item['icon'])): ?><i class="bi <?= htmlspecialchars($item['icon']) ?>"></i><?php endif; ?>
              <?= htmlspecialchars($item['label']) ?>
            </a>
          <?php else: ?>
            <?php
              $colsAll = (array)($item['cols'] ?? []);
              $colsVisible = filter_visible_cols($colsAll, $userRole);
              if (count($colsVisible) === 0) continue;

              $extraActive = '';
              if (($item['label'] ?? '') === 'Mutabakat') $extraActive = $mutabakatMegaActive;
            ?>
            <div class="mega" data-mega>
              <button class="nav-link mega-btn <?= htmlspecialchars($extraActive) ?>" type="button" aria-haspopup="true" aria-expanded="false">
                <?php if (!empty($item['icon'])): ?><i class="bi <?= htmlspecialchars($item['icon']) ?>"></i><?php endif; ?>
                <?= htmlspecialchars($item['label']) ?> <span class="caret">▾</span>
              </button>

              <div class="mega-panel" role="menu">
                <div class="mega-grid">
                  <?php foreach ($colsVisible as $col): ?>
                    <div class="mega-col">
                      <h4><?= htmlspecialchars($col['title'] ?? '') ?></h4>
                      <?php foreach (($col['items'] ?? []) as $it): ?>
                        <?php if (!empty($it['roles']) && !in_array($userRole, $it['roles'], true)) continue; ?>
                        <?php if (isset($it['show']) && $it['show'] === false) continue; ?>
                        <a href="<?= htmlspecialchars($it['href']) ?>"><?= !empty($it['icon']) ? '<i class="bi '.htmlspecialchars($it['icon']).'"></i> ' : '' ?><?= htmlspecialchars($it['label']) ?></a>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>

      <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;">
        <span class="chip">
          <strong><?= htmlspecialchars($userName) ?></strong>
          <small>(<?= htmlspecialchars(role_label($userRole)) ?>)</small>
        </span>
      </div>
    </div>
  </div>
</header>

<script>
(function(){
  const megas = Array.from(document.querySelectorAll('[data-mega]'));
  const profiles = Array.from(document.querySelectorAll('[data-profile]'));
  const ticketdds = Array.from(document.querySelectorAll('[data-ticketdd]'));
  let closeTimer = null;

  function closeAllMega(){
    megas.forEach(m => {
      m.classList.remove('open');
      const btn = m.querySelector('.mega-btn');
      if (btn) btn.setAttribute('aria-expanded','false');
    });
  }
  function scheduleClose(){ clearTimeout(closeTimer); closeTimer = setTimeout(closeAllMega, 160); }
  function cancelClose(){ clearTimeout(closeTimer); }

  megas.forEach(mega => {
    const btn = mega.querySelector('.mega-btn');
    const panel = mega.querySelector('.mega-panel');
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      const willOpen = !mega.classList.contains('open');
      closeAllMega();
      if (willOpen) { mega.classList.add('open'); btn.setAttribute('aria-expanded','true'); }
    });
    mega.addEventListener('mouseenter', cancelClose);
    mega.addEventListener('mouseleave', scheduleClose);
    if (panel){
      panel.addEventListener('mouseenter', cancelClose);
      panel.addEventListener('mouseleave', scheduleClose);
      panel.addEventListener('click', (e)=> e.stopPropagation());
    }
  });

  function closeAllProfile(){
    profiles.forEach(p=>{
      p.classList.remove('open');
      const b = p.querySelector('button.avatar-btn');
      if (b) b.setAttribute('aria-expanded','false');
    });
  }
  profiles.forEach(p=>{
    const btn = p.querySelector('button.avatar-btn');
    const menu = p.querySelector('.profile-menu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      const willOpen = !p.classList.contains('open');
      closeAllProfile();
      if (willOpen) { p.classList.add('open'); btn.setAttribute('aria-expanded','true'); }
    });
    menu.addEventListener('click', (e)=> e.stopPropagation());
  });

  function closeAllTickets(){
    ticketdds.forEach(t=>{
      t.classList.remove('open');
      const b = t.querySelector('button.btn-ticket');
      if (b) b.setAttribute('aria-expanded','false');
    });
  }
  ticketdds.forEach(t=>{
    const btn = t.querySelector('button.btn-ticket');
    const menu = t.querySelector('.ticket-menu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      const willOpen = !t.classList.contains('open');
      closeAllTickets(); closeAllMega(); closeAllProfile();
      if (willOpen) { t.classList.add('open'); btn.setAttribute('aria-expanded','true'); }
    });
    menu.addEventListener('click', (e)=> e.stopPropagation());
  });

  document.addEventListener('click', function(){
    closeAllMega(); closeAllProfile(); closeAllTickets();
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') { closeAllMega(); closeAllProfile(); closeAllTickets(); }
  });

  /* =====================================================
     🔔 TICKET COUNTER + SEKME TITLE + HIZLI YAKALA (BURST)
     - document.hidden kontrolü kaldırıldı (arka planda da günceller)
     - ilk 3 sn içinde birkaç kez poll (yenilemeden “hemen” gelsin)
  ===================================================== */

  if (window.ADS_DISABLE_GLOBAL_UNREAD_POLL) return;

  const badge = document.querySelector('[data-ticketbadge]');
  const canPoll = !!badge;
  const urlPhp   = "<?= htmlspecialchars(page_url('ajax-ticket-counters.php')) ?>";
  const urlNoExt = "<?= htmlspecialchars(page_url('ajax-ticket-counters')) ?>";

  let lastSig = null;
  let inFlight = false;

  // ✅ Base title'ı DOM'dan değil PHP'den sabitle
  const BASE_TITLE = <?= json_encode($appName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  // Title’ı başka script bozarsa geri toplamak için
  let lastTotalForTitle = 0;

  function setBadge(n){
    if (!badge) return;
    const val = parseInt(n || 0, 10) || 0;
    if (val > 0){
      badge.textContent = String(val);
      badge.style.display = 'inline-flex';
    } else {
      badge.textContent = '';
      badge.style.display = 'none';
    }
  }

  function setTabTitle(total){
    lastTotalForTitle = parseInt(total || 0, 10) || 0;
    if (lastTotalForTitle > 0){
      document.title = `(${lastTotalForTitle}) ${BASE_TITLE}`;
    } else {
      document.title = BASE_TITLE;
    }
  }

  async function fetchJson(u){
    const res = await fetch(u + (u.includes('?') ? '&' : '?') + 't=' + Date.now(), {
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Accept": "application/json" }
    });
    return await res.json();
  }

  async function poll(){
    if (!canPoll) return;
    if (inFlight) return;
    inFlight = true;

    try{
      let data = null;
      try { data = await fetchJson(urlPhp); }
      catch(_) { data = await fetchJson(urlNoExt); }

      if (!data || data.ok !== true) return;

      const detail = {
        total: parseInt(data.total||0,10)||0,
        in:    parseInt(data.in||0,10)||0,
        out:   parseInt(data.out||0,10)||0
      };

      setBadge(detail.total);
      setTabTitle(detail.total);

      const sig = detail.total + "|" + detail.in + "|" + detail.out;
      if (lastSig !== sig){
        lastSig = sig;
        window.dispatchEvent(new CustomEvent("ticket:counters", { detail }));
      }
    } catch(e){
      // sessiz
    } finally {
      inFlight = false;
    }
  }

  // ✅ Enforce: başka yer title'ı değiştirirse 1 sn içinde geri al
  setInterval(function(){
    const expected = (lastTotalForTitle > 0) ? `(${lastTotalForTitle}) ${BASE_TITLE}` : BASE_TITLE;
    if (document.title !== expected) document.title = expected;
  }, 1000);

  // ✅ İlk açılışta hızlı yakala (yenilemeden “hemen” gelsin)
  poll();
  setTimeout(poll, 600);
  setTimeout(poll, 1500);
  setTimeout(poll, 3000);

  // ✅ Normal periyot
  setInterval(poll, 5000);
  document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) poll(); });
  window.addEventListener('focus', poll);

})();
</script>