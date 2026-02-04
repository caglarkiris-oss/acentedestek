<?php
// /public_html/platform/tickets.php

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__ . "/../../helpers.php";
require_once __DIR__ . "/../../db.php";
require_once __DIR__ . "/auth.php";

$debug = (bool) config('app.debug', false);

/* ✅ AUTH GUARD */
if (function_exists('require_login')) {
  require_login();
} else {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) {
    header("Location: " . route_url('login'));
    exit;
  }
}

$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$ALLOWED = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) {
  http_response_code(403);
  exit("Bu sayfaya erişim yetkiniz yok.");
}
// h() helpers.php içinde var. Burada tekrar tanımlamak fatal hataya sebep olur.

if (!function_exists('status_tr')) {
  function status_tr(string $s): string {
    $map = [
      'OPEN' => 'Açık',
      'CLOSED' => 'Kapalı',
      'PENDING' => 'Beklemede',
      'SATIS_YAPILDI' => 'Satış Yapıldı',
      'SATIS_YAPILMADI' => 'Satış Yapılmadı',
      'ISLEM_YAPILMADI' => 'İşlem Yapılmadı',
      'open' => 'Açık',
      'in_progress' => 'İşlemde',
      'waiting_response' => 'Yanıt Bekliyor',
      'closed' => 'Kapalı',
    ];
    return $map[$s] ?? $s;
  }
}

function request_tr(string $s): string {
  $s = trim($s);
  if ($s === '') return '-';
  $map = [
    'POLICE_TALEBI'       => 'Poliçe Talebi',
    'POLICE_İPTAL'        => 'Poliçe İptal',
    'POLICE_IPTAL'        => 'Poliçe İptal',
    'ZEYIL'               => 'Zeyil',
    'HASAR'               => 'Hasar',
    'BILGI'               => 'Bilgi',
    'TAHSILAT'            => 'Tahsilat',
    'MUTABAKAT'           => 'Mutabakat',
  ];
  if (isset($map[$s])) return $map[$s];
  $t = str_replace(['_','-'], ' ', strtolower($s));
  $t = mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
  return $t;
}

function fmt_dt(string $dt): string {
  $dt = trim($dt);
  if ($dt === '' || $dt === '0000-00-00 00:00:00') return '-';
  try {
    $d = new DateTime($dt);
    return $d->format('d.m.Y H:i');
  } catch (Throwable $e) {
    return $dt;
  }
}

$BRANCHES = [
  'trafik'       => 'Trafik (Zorunlu)',
  'kasko'        => 'Kasko',
  'dask'         => 'DASK',
  'konut'        => 'Konut',
  'isyeri'       => 'İşyeri',
  'saglik'       => 'Sağlık',
  'tss'          => 'TSS',
  'seyahat'      => 'Seyahat',
  'ferdi_kaza'   => 'Ferdi Kaza',
  'nakliyat'     => 'Nakliyat',
  'sorumluluk'   => 'Sorumluluk (İMM/Mali/Mesleki)',
  'muhendislik'  => 'Mühendislik',
  'hayat'        => 'Hayat',
  'bes'          => 'BES',
  'tarim'        => 'Tarım (TARSİM)',
  'tekne'        => 'Tekne/Yat',
];

function normalize_branch_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = str_replace(['İ','I','ı'], ['i','i','i'], $s);
  $s = mb_strtolower($s, 'UTF-8');
  $s = str_replace([' ', '-'], '_', $s);
  $s = preg_replace('~[^a-z0-9_]+~', '', $s);
  return $s ?: '';
}

function build_url(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null || $v === '') unset($q[$k]);
    else $q[$k] = $v;
  }
  $qs = http_build_query($q);
  // Pretty URL: /platform/tickets
  return route_url('tickets' . ($qs ? ('?' . $qs) : ''));
}

$box = (string)($_GET['box'] ?? 'in');
if ($box !== 'in' && $box !== 'out') $box = 'in';

$statusFilter = trim((string)($_GET['status'] ?? ''));
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$q            = trim((string)($_GET['q'] ?? ''));

function fetch_user_created_at(mysqli $conn, int $userId): string {
  $candidates = ['created_at', 'createdAt', 'created_on', 'registered_at'];
  foreach ($candidates as $col) {
    $sql = "SELECT $col FROM users WHERE id=? LIMIT 1";
    $st = @$conn->prepare($sql);
    if ($st) {
      $val = null;
      $st->bind_param("i", $userId);
      $st->execute();
      $st->bind_result($val);
      $st->fetch();
      $st->close();
      if (is_string($val) && trim($val) !== '') return $val;
      return '1970-01-01 00:00:00';
    }
  }
  return '1970-01-01 00:00:00';
}
$userCreatedAt = fetch_user_created_at($conn, $userId);

/* ✅ PERSONEL branches scope (DB: user_branch_scopes) */
$personelAllowedBranches = [];
if ($userRole === 'PERSONEL') {
  $allowedKeys = array_keys($BRANCHES);

  $stb = $conn->prepare("SELECT branch_key FROM user_branch_scopes WHERE user_id=?");
  if ($stb) {
    $stb->bind_param("i", $userId);
    $stb->execute();
    $stb->bind_result($bk);

    $tmp = [];
    while ($stb->fetch()) {
      $k = normalize_branch_key((string)$bk);
      if ($k !== '') $tmp[] = $k;
    }
    $stb->close();

    $tmp = array_values(array_unique($tmp));
    $personelAllowedBranches = array_values(array_intersect($tmp, $allowedKeys));
  }
  // ✅ boşsa artık "hepsi" değil, none (liste/çip = 0)
}

$BRANCHES_UI = $BRANCHES;
if ($userRole === 'PERSONEL') {
  $BRANCHES_UI = [];
  foreach ($personelAllowedBranches as $k) {
    if (isset($BRANCHES[$k])) $BRANCHES_UI[$k] = $BRANCHES[$k];
  }
}

/* ---------------------------------------------------------
   UNREAD TAB COUNTERS (in/out)
--------------------------------------------------------- */
function unread_count_for_box(mysqli $conn, int $userId, string $userRole, int $agencyId, string $box, array $personelAllowedBranches, string $userCreatedAt): int {
  $statusFilter = trim((string)($_GET['status'] ?? ''));
  $branchFilter = trim((string)($_GET['branch'] ?? ''));
  $q            = trim((string)($_GET['q'] ?? ''));

  $where = [];
  $params = [];
  $types = "";

  if ($agencyId <= 0 || $userId <= 0) return 0;

  if ($box === 'in') {
    $where[] = "t.target_agency_id = ?";
    $params[] = $agencyId; $types .= "i";
  } else {
    $where[] = "t.created_by_agency_id = ?";
    $params[] = $agencyId; $types .= "i";
  }

  if ($userRole === 'PERSONEL') {
    if (!empty($personelAllowedBranches)) {
      if ($branchFilter !== '' && $branchFilter !== 'hepsi' && !in_array($branchFilter, $personelAllowedBranches, true)) {
        return 0;
      }
      $ph = implode(',', array_fill(0, count($personelAllowedBranches), '?'));
      $where[] = "t.branch IN ($ph)";
      foreach ($personelAllowedBranches as $b) { $params[] = $b; $types .= "s"; }
    } else {
      return 0;
    }
  }

  if ($statusFilter !== '' && $statusFilter !== 'hepsi') {
    $where[] = "t.status = ?";
    $params[] = $statusFilter; $types .= "s";
  }
  if ($branchFilter !== '' && $branchFilter !== 'hepsi') {
    $where[] = "t.branch = ?";
    $params[] = $branchFilter; $types .= "s";
  }
  if ($q !== '') {
    $where[] = "(t.subject LIKE ? OR t.description LIKE ?)";
    $like = "%".$q."%";
    $params[] = $like; $params[] = $like; $types .= "ss";
  }

  $whereSql = "WHERE " . (count($where) ? implode(" AND ", $where) : "1=1");

  $sqlC = "
    SELECT COUNT(*)
    FROM tickets t
    LEFT JOIN ticket_user_state tus
      ON tus.ticket_id = t.id AND tus.user_id = ?
    $whereSql
    AND COALESCE(t.updated_at, t.created_at) > GREATEST(COALESCE(tus.last_seen_at, '1970-01-01 00:00:00'), ?)
  ";

  $stc = $conn->prepare($sqlC);
  if (!$stc) return 0;

  $bindTypes2  = "is" . $types;
  $bindParams2 = array_merge([$userId, $userCreatedAt], $params);

  $tmp2 = [];
  $tmp2[] = $bindTypes2;
  for ($i=0; $i<count($bindParams2); $i++) $tmp2[] = &$bindParams2[$i];
  call_user_func_array([$stc, 'bind_param'], $tmp2);

  $cnt = 0;
  $stc->execute();
  $stc->bind_result($cnt);
  $stc->fetch();
  $stc->close();

  return (int)$cnt;
}

$unreadIn  = unread_count_for_box($conn, $userId, $userRole, $agencyId, 'in',  $personelAllowedBranches, $userCreatedAt);
$unreadOut = unread_count_for_box($conn, $userId, $userRole, $agencyId, 'out', $personelAllowedBranches, $userCreatedAt);

$boxUnread = ($box === 'in') ? (int)$unreadIn : (int)$unreadOut;

/* ---------------------------------------------------------
   ✅ BRANCH CHIP COUNTERS (DB + fallback)  (FIX: normalize!)
--------------------------------------------------------- */
$branchUnreadCountsDB = [];
$totalUnreadForBoxDB  = 0;

try {
  $where = [];
  $params = [];
  $types  = "";

  if ($agencyId <= 0 || $userId <= 0) {
    $where[] = "1=0";
  } else {
    if ($box === 'in') {
      $where[] = "t.target_agency_id = ?";
      $params[] = $agencyId; $types .= "i";
    } else {
      $where[] = "t.created_by_agency_id = ?";
      $params[] = $agencyId; $types .= "i";
    }

    if ($userRole === 'PERSONEL') {
      if (!empty($personelAllowedBranches)) {
        $ph = implode(',', array_fill(0, count($personelAllowedBranches), '?'));
        $where[] = "t.branch IN ($ph)";
        foreach ($personelAllowedBranches as $b) { $params[] = $b; $types .= "s"; }
      } else {
        $where[] = "1=0";
      }
    }
  }

  $whereSql = "WHERE " . (count($where) ? implode(" AND ", $where) : "1=1");

  $sqlChip = "
    SELECT t.branch, COUNT(*) AS cnt
    FROM tickets t
    LEFT JOIN ticket_user_state tus
      ON tus.ticket_id = t.id AND tus.user_id = ?
    $whereSql
    AND COALESCE(t.updated_at, t.created_at) >
        GREATEST(COALESCE(tus.last_seen_at, '1970-01-01 00:00:00'), ?)
    GROUP BY t.branch
  ";

  $stChip = $conn->prepare($sqlChip);
  if ($stChip) {
    $bindTypes = "is" . $types;
    $bindParams = array_merge([$userId, $userCreatedAt], $params);

    $tmpB = [];
    $tmpB[] = $bindTypes;
    for ($i=0; $i<count($bindParams); $i++) $tmpB[] = &$bindParams[$i];
    call_user_func_array([$stChip, 'bind_param'], $tmpB);

    $stChip->execute();
    $b=''; $cnt=0;
    $stChip->bind_result($b, $cnt);

    while ($stChip->fetch()) {
      $bkRaw = (string)$b;
      $bk = normalize_branch_key($bkRaw);
      $c  = (int)$cnt;

      if ($bk !== '' && $c > 0) {
        $branchUnreadCountsDB[$bk] = (int)($branchUnreadCountsDB[$bk] ?? 0) + $c;
        $totalUnreadForBoxDB += $c;
      }
    }
    $stChip->close();
  }
} catch (Throwable $e) {}

/* ---------------------------------------------------------
   LIST QUERY (filters + scope)
--------------------------------------------------------- */
$where = [];
$params = [];
$types  = "";

if ($agencyId <= 0) $where[] = "1=0";

if ($box === 'in') {
  $where[] = "t.target_agency_id = ?";
  $params[] = $agencyId; $types .= "i";
} else {
  $where[] = "t.created_by_agency_id = ?";
  $params[] = $agencyId; $types .= "i";
}

if ($userRole === 'PERSONEL') {
  if (!empty($personelAllowedBranches)) {
    if ($branchFilter !== '' && $branchFilter !== 'hepsi' && !in_array($branchFilter, $personelAllowedBranches, true)) {
      $where[] = "1=0";
    } else {
      $ph = implode(',', array_fill(0, count($personelAllowedBranches), '?'));
      $where[] = "t.branch IN ($ph)";
      foreach ($personelAllowedBranches as $b) { $params[] = $b; $types .= "s"; }
    }
  } else {
    $where[] = "1=0";
  }
}

if ($statusFilter !== '' && $statusFilter !== 'hepsi') {
  $where[] = "t.status = ?";
  $params[] = $statusFilter; $types .= "s";
}
if ($branchFilter !== '' && $branchFilter !== 'hepsi') {
  $where[] = "t.branch = ?";
  $params[] = $branchFilter; $types .= "s";
}
if ($q !== '') {
  $where[] = "(t.subject LIKE ? OR t.description LIKE ?)";
  $like = "%".$q."%";
  $params[] = $like; $params[] = $like; $types .= "ss";
}

$whereSql = "WHERE " . (count($where) ? implode(" AND ", $where) : "1=1");
$LIMIT = 200;

$sql = "
  SELECT
    t.id, t.subject, t.branch, COALESCE(t.request_type,'') AS request_type, t.status, t.updated_at, t.created_at,
    COALESCE(tus.last_seen_at, '1970-01-01 00:00:00') AS last_seen_at,
    CASE
      WHEN COALESCE(t.updated_at, t.created_at) > GREATEST(COALESCE(tus.last_seen_at, '1970-01-01 00:00:00'), ?) THEN 1
      ELSE 0
    END AS is_unread,
    COALESCE(a_from.name,'') AS from_agency_name,
    COALESCE(a_to.name,'')   AS to_agency_name
  FROM tickets t
  LEFT JOIN ticket_user_state tus
    ON tus.ticket_id = t.id AND tus.user_id = ?
  LEFT JOIN agencies a_from ON a_from.id = t.created_by_agency_id
  LEFT JOIN agencies a_to   ON a_to.id   = t.target_agency_id
  $whereSql
  ORDER BY COALESCE(t.updated_at, t.created_at) DESC
  LIMIT $LIMIT
";

$rows = [];
$st = $conn->prepare($sql);
if (!$st) { http_response_code(500); exit("DB hata (liste)."); }

$bindTypes = "si" . $types;
$bindParams = array_merge([$userCreatedAt, $userId], $params);

$tmp = [];
$tmp[] = $bindTypes;
for ($i=0; $i<count($bindParams); $i++) $tmp[] = &$bindParams[$i];
call_user_func_array([$st, 'bind_param'], $tmp);

$st->execute();

$id=0; $subject=''; $branch=''; $requestType=''; $status=''; $updatedAt=''; $createdAt=''; $lastSeenAt=''; $isUnread=0;
$fromAgencyName=''; $toAgencyName='';
$st->bind_result($id,$subject,$branch,$requestType,$status,$updatedAt,$createdAt,$lastSeenAt,$isUnread,$fromAgencyName,$toAgencyName);

while ($st->fetch()) {
  $rows[] = [
    'id'=>(int)$id,
    'subject'=>(string)$subject,
    'branch'=>(string)$branch,
    'request_type'=>(string)$requestType,
    'status'=>(string)$status,
    'updated_at'=>(string)$updatedAt,
    'created_at'=>(string)$createdAt,
    'unread'=>((int)$isUnread === 1),
    'from_agency_name'=>(string)$fromAgencyName,
    'to_agency_name'=>(string)$toAgencyName,
  ];
}
$st->close();

/* fallback branch counts from current list (FIX: normalize!) */
$branchUnreadCountsFallback = [];
$totalUnreadFallback = 0;
foreach ($rows as $r) {
  if (empty($r['unread'])) continue;

  $bkRaw = (string)($r['branch'] ?? '');
  $bk = normalize_branch_key($bkRaw);

  if ($bk === '') continue;
  $branchUnreadCountsFallback[$bk] = (int)($branchUnreadCountsFallback[$bk] ?? 0) + 1;
  $totalUnreadFallback++;
}

/* final branch counts = max(DB, fallback) */
$branchUnreadCounts = $branchUnreadCountsDB;
foreach ($branchUnreadCountsFallback as $bk => $c) {
  $branchUnreadCounts[$bk] = max((int)($branchUnreadCounts[$bk] ?? 0), (int)$c);
}

/* "Tümü" sayacı = DAİMA box unread */
$totalUnreadForBox = (int)$boxUnread;


$pageTitle = 'Tickets';
$currentPage = 'tickets';
require_once __DIR__ . "/../../layout/header.php";
?>



<section class="page">
  <div class="tk-layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="tk-panel">
      <div class="tk-panel-h">
        <h2 class="tk-title">Ticket Kutusu</h2>
        <div class="tk-sub">Gelen / Giden talepler, canlı arama ve branş chipleri</div>
      </div>

      <div class="tk-body">
        <div class="tabbar">
          <a class="tabbtn <?= $box==='in'?'is-active':'' ?>" href="<?= h(build_url(['box'=>'in'])) ?>">
            Gelen
            <span id="jsInCount" class="tabcount"><?= (int)$unreadIn ?></span>
          </a>

          <a class="tabbtn <?= $box==='out'?'is-active':'' ?>" href="<?= h(build_url(['box'=>'out'])) ?>">
            Giden
            <span id="jsOutCount" class="tabcount"><?= (int)$unreadOut ?></span>
          </a>
        </div>

        <div class="fgrp">
          <label class="flabel" for="jsLiveSearch">Ara (canlı)</label>
          <input id="jsLiveSearch" class="finput" type="text"
                 placeholder="Konu / açıklama / gönderen / hedef"
                 value="<?= h($q) ?>">
        </div>

        <div class="fgrp">
          <label class="flabel" for="jsStatusSelect">Durum</label>
          <select id="jsStatusSelect" class="fselect">
            <option value="hepsi" <?= ($statusFilter===''||$statusFilter==='hepsi')?'selected':'' ?>>Tüm Durumlar</option>
            <option value="OPEN" <?= ($statusFilter==='OPEN')?'selected':'' ?>>Açık</option>
            <option value="CLOSED" <?= ($statusFilter==='CLOSED')?'selected':'' ?>>Kapalı</option>
            <option value="PENDING" <?= ($statusFilter==='PENDING')?'selected':'' ?>>Beklemede</option>
            <option value="SATIS_YAPILDI" <?= ($statusFilter==='SATIS_YAPILDI')?'selected':'' ?>>Satış Yapıldı</option>
            <option value="SATIS_YAPILMADI" <?= ($statusFilter==='SATIS_YAPILMADI')?'selected':'' ?>>Satış Yapılmadı</option>
            <option value="ISLEM_YAPILMADI" <?= ($statusFilter==='ISLEM_YAPILMADI')?'selected':'' ?>>İşlem Yapılmadı</option>
          </select>
        </div>

        <div class="btnrow">
          <a class="btnx primary" href="<?= h(build_url(['q'=>null,'status'=>$statusFilter?:null])) ?>" id="jsApplyLink">Uygula</a>
          <a class="btnx" href="<?= h(build_url(['q'=>null,'status'=>null,'branch'=>null])) ?>" id="jsClearAll">Temizle</a>
        </div>

        <div class="br-title">Branşlar</div>

        <div class="ticket-branches" id="jsBranchChips">
          <?php $allUnreadForThisBox = (int)$totalUnreadForBox; ?>
          <a class="branch-chip <?= ($branchFilter==='' || $branchFilter==='hepsi') ? 'is-active' : '' ?>"
             href="<?= h(build_url(['branch'=>null])) ?>">
            Tümü
            <?php if ($allUnreadForThisBox > 0): ?>
              <span class="chipcount"><?= (int)$allUnreadForThisBox ?></span>
            <?php endif; ?>
          </a>

          <?php foreach ($BRANCHES_UI as $key => $label): ?>
            <?php $bn = (int)($branchUnreadCounts[$key] ?? 0); ?>
            <a class="branch-chip <?= ($branchFilter===$key) ? 'is-active' : '' ?>"
               href="<?= h(build_url(['branch'=>$key])) ?>"
               title="<?= h($label) ?>">
              <?= h($label) ?>
              <?php if ($bn > 0): ?>
                <span class="chipcount"><?= (int)$bn ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>

      </div>
    </aside>

    <!-- ===== LIST ===== -->
    <section class="tk-panel">
      <div class="tk-panel-h">
        <div class="tk-list-h">
          <div>
            <h2 class="tk-title">Liste</h2>
            <div class="tk-sub">Gösterilen: <b id="jsShownCount"><?= count($rows) ?></b> (son <?= (int)$LIMIT ?>)</div>
          </div>
          <div class="tk-rightnote">Canlı arama/filtre sidebar’dan</div>
        </div>
      </div>

      <div id="jsTicketsList" class="tk-scope" data-box="<?= h($box) ?>">
        <?php foreach ($rows as $r): ?>
          <?php
            $who = '';
            if ($box === 'in') {
              $who = trim((string)($r['from_agency_name'] ?? ''));
              if ($who === '') $who = 'Gönderen';
            } else {
              $who = trim((string)($r['to_agency_name'] ?? ''));
              if ($who === '') $who = 'Hedef';
            }

            $talepTuruRaw = (string)($r['request_type'] ?? '');
            $talepTuru    = request_tr($talepTuruRaw);

            $branchKeyRaw = (string)($r['branch'] ?? '');
            $branchKey    = normalize_branch_key($branchKeyRaw);
            $branchLabel  = $BRANCHES[$branchKey] ?? ($branchKeyRaw ?: '-');

            $stRaw = (string)($r['status'] ?? '');
            $stNorm = strtolower($stRaw);
            $isClosed = ($stNorm === 'closed' || $stRaw === 'CLOSED');
            $statusPillClass = $isClosed ? 'status-closed' : 'status-open';

            $lastAtRaw = (string)($r['updated_at'] ?: $r['created_at']);
            $lastAt    = fmt_dt($lastAtRaw);

            $isUnread = !empty($r['unread']);

            $searchBlob = mb_strtolower(
              trim($who.' '.$branchLabel.' '.$talepTuru.' '.$stRaw.' '.$subject),
              'UTF-8'
            );
          ?>

          <a class="tkrow <?= $isUnread ? 'is-unread' : '' ?>"
             href="<?= h(base_url('ticket.php?id='.(int)$r['id'])) ?>"
             data-search="<?= h($searchBlob) ?>">
            <div class="tkdot" title="<?= $isUnread ? 'Okunmamış' : 'Okundu' ?>">
              <span class="tku"></span>
            </div>

            <div class="tkc tk-who">
              <div class="tkl"><?= ($box==='in' ? 'Gönderen' : 'Hedef') ?></div>
              <div class="tkv"><?= h($who) ?></div>
            </div>

            <div class="tkc tk-branch">
              <div class="tkl">Branş</div>
              <div class="tkv"><?= h($branchLabel) ?></div>
            </div>

            <div class="tkc tk-type">
              <div class="tkl">Talep</div>
              <div class="tktag" title="<?= h($talepTuru) ?>"><?= h($talepTuru) ?></div>
            </div>

            <div class="tkc tk-status">
              <div class="tkl">Durum</div>
              <div class="tkv">
                <span class="status-pill <?= $statusPillClass ?>"><?= h(status_tr($stRaw)) ?></span>
              </div>
            </div>

            <div class="tkc tk-date">
              <div class="tkl">Son</div>
              <div class="tkv"><?= h($lastAt) ?></div>
            </div>
          </a>
        <?php endforeach; ?>

        <?php if (empty($rows)): ?>
          <div>Kayıt yok.</div>
        <?php endif; ?>
      </div>
    </section>

  </div>
</section>

<script>
(function(){
  const inEl   = document.getElementById('jsInCount');
  const outEl  = document.getElementById('jsOutCount');
  const shown  = document.getElementById('jsShownCount');
  const search = document.getElementById('jsLiveSearch');
  const status = document.getElementById('jsStatusSelect');
  const list   = document.getElementById('jsTicketsList');

  function setCount(el, n){
    if(!el) return;
    n = Number(n||0) || 0;
    if(n > 0){
      el.textContent = String(n);
      el.style.display = '';
    }else{
      el.style.display = 'none';
    }
  }

  function liveFilter(){
    if(!list) return;
    const q = (search && search.value ? search.value : '').trim().toLowerCase();
    const rows = Array.from(list.querySelectorAll('.tkrow'));
    let visible = 0;

    rows.forEach(r=>{
      const blob = (r.getAttribute('data-search') || '').toLowerCase();
      const ok = (!q) || blob.includes(q);
      r.style.display = ok ? '' : 'none';
      if(ok) visible++;
    });

    if(shown) shown.textContent = String(visible);
  }

  let tmr = null;
  if(search){
    search.addEventListener('input', ()=>{
      clearTimeout(tmr);
      tmr = setTimeout(liveFilter, 120);
    });
  }
  liveFilter();

  function setUrlParam(key, val){
    const u = new URL(window.location.href);
    if(!val || val === 'hepsi') u.searchParams.delete(key);
    else u.searchParams.set(key, val);
    return u.toString();
  }
  if(status){
    status.addEventListener('change', ()=>{
      const v = status.value || '';
      window.location.href = setUrlParam('status', v);
    });
  }

  let gotValidEvent = false;

  window.addEventListener('ticket:counters', function(e){
    const d = (e && e.detail) ? e.detail : {};
    const hasIO = Object.prototype.hasOwnProperty.call(d,'in') || Object.prototype.hasOwnProperty.call(d,'out');
    if (hasIO) gotValidEvent = true;

    if (hasIO){
      setCount(inEl,  Number(d.in  || 0));
      setCount(outEl, Number(d.out || 0));
    }

    try{
      const badge = document.querySelector('[data-ticketbadge]');
      if(badge){
        const total = (Number(d.in||0)+Number(d.out||0))|0;
        badge.textContent = String(total);
        badge.style.display = total>0 ? 'inline-flex' : 'none';
      }
    }catch(_){}
  });

  const urlPhp   = "<?= function_exists('page_url') ? h(page_url('ajax-ticket-counters.php')) : h(base_url('ajax-ticket-counters.php')) ?>";
  const urlNoExt = "<?= function_exists('page_url') ? h(page_url('ajax-ticket-counters')) : h(base_url('ajax-ticket-counters')) ?>";

  async function fetchJson(u){
    const res = await fetch(u + (u.includes('?') ? '&' : '?') + 't=' + Date.now(), {
      credentials: "same-origin",
      cache: "no-store",
      headers:{ "Accept":"application/json" }
    });
    return await res.json();
  }

  async function poll(){
    try{
      let data = null;
      try { data = await fetchJson(urlPhp); }
      catch(_) { data = await fetchJson(urlNoExt); }

      if(!data || !data.ok) return;

      setCount(inEl,  Number(data.in  || 0));
      setCount(outEl, Number(data.out || 0));

      try{
        const badge = document.querySelector('[data-ticketbadge]');
        if(badge){
          const total = (Number(data.in||0)+Number(data.out||0))|0;
          badge.textContent = String(total);
          badge.style.display = total>0 ? 'inline-flex' : 'none';
        }
      }catch(_){}
    }catch(e){}
  }

  setTimeout(function(){
    if(!gotValidEvent) poll();
    setInterval(poll, 5000);
  }, 600);

  document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) poll(); });
  window.addEventListener('focus', poll);

})();
</script>

<?php require_once __DIR__ . "/../../layout/footer.php"; ?>
