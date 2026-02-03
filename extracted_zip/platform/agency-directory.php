<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/* ---- AUTH ---- */
if (function_exists('require_login')) {
  require_login();
} else {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (!isset($_SESSION['user_id'])) {
    header("Location: " . base_url("login.php"));
    exit;
  }
}

/* ✅ normalize */
$userRole   = strtoupper(trim((string)($_SESSION['role'] ?? '')));
$myAgencyId = (int)($_SESSION['agency_id'] ?? 0);

/* ---- ROLE: sadece ana acente yetkilisi + ona bağlı personel ---- */
if (!in_array($userRole, ['ACENTE_YETKILISI','PERSONEL'], true)) {
  http_response_code(403);
  exit('Yetkisiz');
}

/* ---- DB ---- */
$conn = null;
if (isset($GLOBALS['conn']) && $GLOBALS['conn']) $conn = $GLOBALS['conn'];
if (!$conn && function_exists('db')) $conn = db();
if (!$conn) { http_response_code(500); exit('DB bağlantısı yok'); }

function table_exists(mysqli $conn, string $table): bool {
  $table = mysqli_real_escape_string($conn, $table);
  $res = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
  return $res && mysqli_num_rows($res) > 0;
}
function column_exists(mysqli $conn, string $table, string $col): bool {
  $table = mysqli_real_escape_string($conn, $table);
  $col   = mysqli_real_escape_string($conn, $col);
  $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
  return $res && mysqli_num_rows($res) > 0;
}

/* ---- Kullanıcının bağlı olduğu acente ANA mı? ---- */
if ($myAgencyId <= 0) { http_response_code(403); exit('Yetkisiz'); }

$myAgencyName = '';
$myParentId   = 0;

$st = mysqli_prepare($conn, "SELECT name, COALESCE(parent_id,0) AS parent_id FROM agencies WHERE id=? LIMIT 1");
mysqli_stmt_bind_param($st, "i", $myAgencyId);
mysqli_stmt_execute($st);
mysqli_stmt_bind_result($st, $aname, $pid);
mysqli_stmt_fetch($st);
mysqli_stmt_close($st);

$myAgencyName = (string)($aname ?? '');
$myParentId   = (int)($pid ?? 0);

/* ✅ TALİ ACENTE GÖREMEZ */
if ($myParentId > 0) {
  http_response_code(403);
  exit('Bu alan sadece ana acente içindir.');
}

/* ---- UI params ---- */
$tab = (string)($_GET['tab'] ?? 'main'); // main | sub
if (!in_array($tab, ['main','sub'], true)) $tab = 'main';

$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

/* ---- Şirket ilişkisi ---- */
$hasCompanies = table_exists($conn,'agency_companies')
  && table_exists($conn,'insurance_companies')
  && column_exists($conn,'agency_companies','company_id');

/* ---- Profil tablosu var mı? (telefon/mail/web/adres vs.) ---- */
$hasProfiles = table_exists($conn,'agency_profiles')
  && column_exists($conn,'agency_profiles','agency_id');

/* profil kolonlarını opsiyonel çekelim (kolon isimleri sende farklı olabilir) */
$profCols = [
  'title'     => column_exists($conn,'agency_profiles','title'),
  'phone'     => column_exists($conn,'agency_profiles','phone'),
  'email'     => column_exists($conn,'agency_profiles','email'),
  'website'   => column_exists($conn,'agency_profiles','website'),
  'city'      => column_exists($conn,'agency_profiles','city'),
  'district'  => column_exists($conn,'agency_profiles','district'),
  'address'   => column_exists($conn,'agency_profiles','address'),
];

function render_companies_compact($companies, int $max=3): string {
  $companies = (string)($companies ?? '');
  $arr = array_values(array_filter(array_map('trim', explode('||', $companies))));
  if (!$arr) return '<span class="small muted">Şirket bilgisi yok</span>';

  $shown  = array_slice($arr, 0, $max);
  $hidden = array_slice($arr, $max);
  $more   = max(0, count($hidden));

  $html = '<div class="chips" data-expanded="0">';
  foreach ($shown as $c) $html .= '<span class="chip">'.htmlspecialchars($c).'</span>';

  if ($more > 0) {
    foreach ($hidden as $c) $html .= '<span class="chip chip-hidden">'.htmlspecialchars($c).'</span>';
    $html .= '<button type="button" class="chip more" data-togglechips="1" aria-expanded="false">+'.(int)$more.'</button>';
  }
  $html .= '</div>';
  return $html;
}

/* ---- DATA ---- */
$rows = [];
$mode = ($tab==='main') ? 'main' : 'sub';

/* SELECT */
$select = "a.id, a.name, COALESCE(a.is_active,1) AS is_active";

/* companies */
if ($hasCompanies) $select .= ", GROUP_CONCAT(DISTINCT ic.name ORDER BY ic.name SEPARATOR '||') AS companies";

/* profiles */
if ($hasProfiles) {
  if ($profCols['title'])    $select .= ", ap.title AS p_title";
  if ($profCols['phone'])    $select .= ", ap.phone AS p_phone";
  if ($profCols['email'])    $select .= ", ap.email AS p_email";
  if ($profCols['website'])  $select .= ", ap.website AS p_website";
  if ($profCols['city'])     $select .= ", ap.city AS p_city";
  if ($profCols['district']) $select .= ", ap.district AS p_district";
  if ($profCols['address'])  $select .= ", ap.address AS p_address";
}

/* FROM */
$sql = "SELECT {$select} FROM agencies a ";

/* JOIN profiles */
if ($hasProfiles) {
  $sql .= " LEFT JOIN agency_profiles ap ON ap.agency_id=a.id ";
}

/* JOIN companies */
if ($hasCompanies) {
  $sql .= " LEFT JOIN agency_companies ac ON ac.agency_id=a.id
            LEFT JOIN insurance_companies ic ON ic.id=ac.company_id ";
}

/* WHERE */
if ($tab === 'main') {
  $sql .= " WHERE COALESCE(a.parent_id,0)=0 AND COALESCE(a.is_active,1)=1 ";
  if ($q !== '') $sql .= " AND a.name LIKE ? ";
} else {
  $sql .= " WHERE COALESCE(a.parent_id,0)=? ";
  if ($q !== '') $sql .= " AND a.name LIKE ? ";
}

/* GROUP */
if ($hasCompanies) $sql .= " GROUP BY a.id ";

/* ORDER */
$sql .= " ORDER BY a.name ASC ";

$st = mysqli_prepare($conn, $sql);
if (!$st) { http_response_code(500); exit('SQL prepare hata'); }

if ($tab === 'main') {
  if ($q !== '') mysqli_stmt_bind_param($st, "s", $qLike);
} else {
  if ($q !== '') mysqli_stmt_bind_param($st, "is", $myAgencyId, $qLike);
  else mysqli_stmt_bind_param($st, "i", $myAgencyId);
}

mysqli_stmt_execute($st);

/* ✅ bind_result dinamik değil; o yüzden mysqlnd gerektirmeyen küçük “fetch assoc” helper yapalım */
$result = mysqli_stmt_get_result($st);
if ($result) {
  while ($r = mysqli_fetch_assoc($result)) $rows[] = $r;
} else {
  // mysqlnd yoksa fallback: minimum alanlarla devam (sende mysqlnd genelde var ama garanti olsun)
  // Bu fallback’te sadece id,name,is_active gelir (profil/companies gelmeyebilir)
  mysqli_stmt_bind_result($st, $id, $name, $is_active);
  while (mysqli_stmt_fetch($st)) $rows[] = ['id'=>$id,'name'=>$name,'is_active'=>$is_active];
}
mysqli_stmt_close($st);

$page_title = "Acente Rehberi";
require_once __DIR__ . '/layout/header.php';
?>

<style>
  .tabs{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 14px}
  .tab{padding:10px 12px;border:1px solid var(--stroke);border-radius:12px;background:var(--card2);text-decoration:none;color:var(--text);font-weight:750}
  .tab.is-active{background:rgba(29,78,216,.10);border-color:rgba(29,78,216,.25)}

  .toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:8px 0 14px}
  .search{display:flex;gap:10px;align-items:center;flex:1;min-width:260px}
  .search input{width:100%}

  .filterbar{display:flex;gap:10px;align-items:center;flex:1;min-width:260px}
  .filterbar .input{width:100%}

  .grid{display:grid;grid-template-columns:repeat(3, minmax(0, 1fr));gap:12px}
  @media(max-width:1100px){.grid{grid-template-columns:repeat(2, minmax(0, 1fr));}}
  @media(max-width:720px){.grid{grid-template-columns:1fr;}}

  .agency-card{
    border:1px solid var(--stroke);
    background:var(--card);
    border-radius:16px;
    padding:14px;
    display:flex;
    flex-direction:column;
    gap:10px;
    position:relative;
    padding-bottom:58px;
    min-height: 168px;
  }
  .title{font-weight:900;font-size:15px}
  .meta{display:flex;flex-direction:column;gap:6px}
  .chips{display:flex;flex-wrap:wrap;gap:6px;max-width:100%}
  .chip{
    font-size:12px;line-height:1;
    padding:6px 10px;
    border:1px solid var(--stroke);
    border-radius:999px;
    background:var(--card2);
    white-space:nowrap;
  }
  .chip-hidden{display:none}
  .chips.is-open .chip-hidden{display:inline-flex}
  .chip.more{cursor:pointer;background:#eef2ff;border-color:#c7d2fe;font-weight:800}

  .info-line{font-size:12px;opacity:.78;display:flex;gap:10px;flex-wrap:wrap}
  .info-line span{white-space:nowrap}

  .card-footer-left{position:absolute;left:14px;bottom:14px;font-size:12px;opacity:.75}
  .ticket-btn{position:absolute;right:14px;bottom:14px}

  .muted{opacity:.75}
  .empty-filter{
    display:none;
    border:1px dashed var(--stroke);
    background:var(--card2);
    border-radius:14px;
    padding:14px;
    opacity:.85;
    margin-top:10px;
  }
</style>

<main class="page">
  <section class="card">
    <div class="row" style="align-items:flex-start;">
      <div>
        <h2 class="card-title" style="margin-bottom:6px;">Acente Rehberi</h2>
        <div class="card-sub">
          Ana acente kullanıcıları için acente rehberi.
          (Senin acenten: <b><?= htmlspecialchars($myAgencyName ?: '-') ?></b>)
        </div>
      </div>
      <a class="btn" href="<?= htmlspecialchars(base_url('dashboard.php')) ?>">Dashboard</a>
    </div>

    <div class="tabs">
      <a class="tab <?= $tab==='main'?'is-active':'' ?>" href="<?= htmlspecialchars(base_url('agency-directory.php?tab=main&q='.urlencode($q))) ?>">Ana Acenteler</a>
      <a class="tab <?= $tab==='sub'?'is-active':'' ?>" href="<?= htmlspecialchars(base_url('agency-directory.php?tab=sub&q='.urlencode($q))) ?>">Tali Acentelerim</a>
    </div>

    <div class="toolbar">
      <!-- ✅ Ara butonu kaldırıldı (artık live filter var) -->
      <div class="search">
        <input id="nameFilter" class="input" type="text" value="<?= htmlspecialchars($q) ?>" placeholder="Acente adı ara..." autocomplete="off">
      </div>

      <div class="filterbar">
        <input id="companyFilter" class="input" type="text" placeholder="Şirket filtrele (örn: allianz, axa, ageSA)..." autocomplete="off">
        <button id="clearCompanyFilter" class="btn" type="button">Temizle</button>
      </div>

      <div class="small muted">
        <span id="rowCount"><?= count($rows) ?></span> kayıt
      </div>
    </div>

    <div class="hr"></div>

    <div id="emptyFilter" class="empty-filter small">
      Filtreye uyan kayıt bulunamadı.
    </div>

    <?php if (empty($rows)): ?>
      <div class="small muted">Kayıt bulunamadı.</div>
    <?php else: ?>
      <div class="grid" id="agencyGrid">
        <?php foreach ($rows as $r): ?>
          <?php
            $id   = (int)($r['id'] ?? 0);
            $name = (string)($r['name'] ?? '');
            $isActive = (int)($r['is_active'] ?? 1);

            $companies = $hasCompanies ? (string)($r['companies'] ?? '') : '';

            // ✅ profil alanları (varsa)
            $pTitle   = (string)($r['p_title'] ?? '');
            $pPhone   = (string)($r['p_phone'] ?? '');
            $pEmail   = (string)($r['p_email'] ?? '');
            $pWeb     = (string)($r['p_website'] ?? '');
            $pCity    = (string)($r['p_city'] ?? '');
            $pDist    = (string)($r['p_district'] ?? '');
            $pAddress = (string)($r['p_address'] ?? '');

            $ticketUrl = base_url('ticket-create.php?mode='.$mode.'&to_agency_id='.$id);

            $nameFlat = mb_strtolower(trim($name), 'UTF-8');
            $insFlat  = mb_strtolower(trim(str_replace('||', '|', $companies)), 'UTF-8');
          ?>

          <div class="agency-card"
               data-name="<?= htmlspecialchars($nameFlat) ?>"
               data-insurers="<?= htmlspecialchars($insFlat) ?>">

            <div class="title">
              <?= htmlspecialchars($name) ?>
              <?php if ($tab==='sub' && $isActive !== 1): ?>
                <span class="badge bad" style="margin-left:8px;">Pasif</span>
              <?php endif; ?>
            </div>

            <div class="meta">
              <?= $hasCompanies ? render_companies_compact($companies, 3) : '<span class="small muted">Şirket bilgisi yok</span>' ?>

              <div class="info-line">
                <?php if ($pTitle !== ''): ?><span><b>Ünvan:</b> <?= htmlspecialchars($pTitle) ?></span><?php endif; ?>
                <?php if ($pPhone !== ''): ?><span><b>☎</b> <?= htmlspecialchars($pPhone) ?></span><?php endif; ?>
                <?php if ($pEmail !== ''): ?><span><b>✉</b> <?= htmlspecialchars($pEmail) ?></span><?php endif; ?>
                <?php if ($pWeb !== ''): ?><span><b>🌐</b> <?= htmlspecialchars($pWeb) ?></span><?php endif; ?>
                <?php if ($pCity !== '' || $pDist !== ''): ?>
                  <span><b>📍</b> <?= htmlspecialchars(trim($pCity.' '.$pDist)) ?></span>
                <?php endif; ?>
              </div>

              <?php if ($pTitle==='' && $pPhone==='' && $pEmail==='' && $pWeb==='' && $pCity==='' && $pDist==='' && $pAddress===''): ?>
                <div class="small muted">Profil bilgisi yok</div>
              <?php endif; ?>
            </div>

            <div class="card-footer-left">
              <?= $tab==='main' ? 'Ana acente' : 'Tali acente' ?>
            </div>

            <?php if ($tab==='sub' && $isActive !== 1): ?>
              <span class="small muted ticket-btn">Ticket kapalı</span>
            <?php else: ?>
              <a class="btn primary ticket-btn" href="<?= htmlspecialchars($ticketUrl) ?>">Ticket Aç</a>
            <?php endif; ?>

          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>

<script>
(function(){
  const grid = document.getElementById('agencyGrid');
  if (!grid) return;

  const nameInput    = document.getElementById('nameFilter');
  const companyInput = document.getElementById('companyFilter');
  const clearBtn     = document.getElementById('clearCompanyFilter');
  const rowCountEl   = document.getElementById('rowCount');
  const emptyEl      = document.getElementById('emptyFilter');

  function norm(s){ return (s || '').toString().trim().toLocaleLowerCase('tr-TR'); }

  function applyFilters(){
    const qName = norm(nameInput ? nameInput.value : '');
    const qIns  = norm(companyInput ? companyInput.value : '');

    const cards = Array.from(grid.querySelectorAll('.agency-card'));
    let visible = 0;

    cards.forEach(card => {
      const n   = norm(card.getAttribute('data-name') || '');
      const ins = norm(card.getAttribute('data-insurers') || '');

      const okName = (qName === '') ? true : (n.indexOf(qName) !== -1);
      const okIns  = (qIns  === '') ? true : (ins.indexOf(qIns) !== -1);

      const ok = okName && okIns;
      card.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });

    if (rowCountEl) rowCountEl.textContent = String(visible);
    if (emptyEl) emptyEl.style.display = (visible === 0) ? 'block' : 'none';
  }

  let t=null;
  function schedule(){ clearTimeout(t); t=setTimeout(applyFilters, 120); }

  if (nameInput) nameInput.addEventListener('input', schedule);
  if (companyInput) companyInput.addEventListener('input', schedule);

  if (clearBtn) clearBtn.addEventListener('click', function(){
    if (companyInput) companyInput.value = '';
    schedule();
    if (companyInput) companyInput.focus();
  });

  grid.addEventListener('click', function(ev){
    const btn = ev.target && ev.target.closest && ev.target.closest('button[data-togglechips="1"]');
    if (!btn) return;
    const chips = btn.closest('.chips');
    if (!chips) return;

    const open = chips.classList.toggle('is-open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (open) { btn.dataset.prev = btn.textContent; btn.textContent = 'Kapat'; }
    else { btn.textContent = btn.dataset.prev || btn.textContent; }
  });

  applyFilters();
})();
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
