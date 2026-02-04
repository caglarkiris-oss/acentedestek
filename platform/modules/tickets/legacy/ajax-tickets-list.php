<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../auth.php';

header('Content-Type: application/json; charset=utf-8');

$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$ALLOWED = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Yetkisiz']);
  exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

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

/** ✅ Talep türü kolonunu DB'de otomatik bul (varsa) */
function detect_request_type_column(mysqli $conn): string {
  $candidates = ['request_type','talep_turu','talep_tipi','talep_type','type','ticket_type'];

  $in = "'" . implode("','", array_map(function($x){
    return str_replace("'", "''", $x);
  }, $candidates)) . "'";

  $sql = "
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tickets'
      AND COLUMN_NAME IN ($in)
    LIMIT 1
  ";
  $res = $conn->query($sql);
  if ($res && ($row = $res->fetch_assoc())) {
    $col = (string)($row['COLUMN_NAME'] ?? '');
    return preg_match('/^[a-zA-Z0-9_]+$/', $col) ? $col : '';
  }
  return '';
}
$REQUEST_TYPE_COL = detect_request_type_column($conn);

$box = (string)($_GET['box'] ?? 'in');
if ($box !== 'in' && $box !== 'out') $box = 'in';

$statusFilter = trim((string)($_GET['status'] ?? ''));
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$q            = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
$types  = "";

/* Scope */
if ($userRole === 'PERSONEL') {
  $where[] = "t.created_by_user_id = ?";
  $params[] = $userId; $types .= "i";
} else {
  if ($agencyId <= 0) { $where[] = "1=0"; }
  if ($box === 'in') {
    $where[] = "t.target_agency_id = ?";
    $params[] = $agencyId; $types .= "i";
  } else {
    $where[] = "t.created_by_agency_id = ?";
    $params[] = $agencyId; $types .= "i";
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

/** ✅ Talep türü expr (kolon varsa onu çek, yoksa boş string) */
$requestTypeSelect = "'' AS request_type";
if ($REQUEST_TYPE_COL !== '') {
  $requestTypeSelect = "COALESCE(t.`{$REQUEST_TYPE_COL}`,'') AS request_type";
}

$sql = "
  SELECT
    t.id, t.subject, t.branch, {$requestTypeSelect}, t.status, t.updated_at, t.created_at,
    CASE
      WHEN COALESCE(t.updated_at, t.created_at) > COALESCE(tus.last_seen_at, '1970-01-01 00:00:00') THEN 1
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

$st = $conn->prepare($sql);
if (!$st) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB hata (liste)']);
  exit;
}

$bindTypes = "i" . $types;
$bindParams = array_merge([$userId], $params);

$tmp = [];
$tmp[] = $bindTypes;
for ($i=0; $i<count($bindParams); $i++) $tmp[] = &$bindParams[$i];
call_user_func_array([$st, 'bind_param'], $tmp);

$st->execute();

$id=0; $subject=''; $branch=''; $requestType=''; $status=''; $updatedAt=''; $createdAt=''; $isUnread=0;
$fromAgencyName=''; $toAgencyName='';
$st->bind_result($id,$subject,$branch,$requestType,$status,$updatedAt,$createdAt,$isUnread,$fromAgencyName,$toAgencyName);

$rows = [];
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

ob_start();

if (!empty($rows)) {
  foreach ($rows as $r) {
    $title = '';
    if ($box === 'in') {
      $title = trim((string)($r['from_agency_name'] ?? ''));
      if ($title === '') $title = 'Gönderen acente';
    } else {
      $title = trim((string)($r['to_agency_name'] ?? ''));
      if ($title === '') $title = 'Hedef acente';
    }

    $talepTuru = trim((string)($r['request_type'] ?? ''));
    if ($talepTuru === '') $talepTuru = '-';
    ?>
    <a class="card-link" href="<?= h(base_url('ticket.php?id='.(int)$r['id'])) ?>">
      <div class="ticket-head">
        <div>
          <div class="ticket-title"><?= h($title) ?></div>
          <div class="ticket-subject"><b>Konu:</b> <?= h($r['subject'] ?: ('Ticket #'.$r['id'])) ?></div>
        </div>

        <?php if ($r['unread']): ?>
          <span class="unread-flag" title="Okunmamış mesaj">🚩</span>
        <?php endif; ?>
      </div>

      <div class="d">
        <b>Branş:</b> <b><?= h($r['branch'] ?: '-') ?></b>
        &nbsp; • &nbsp; <b>Talep Türü:</b> <b><?= h($talepTuru) ?></b>
        &nbsp; • &nbsp; <b>Durum:</b> <b><?= h(status_tr($r['status'])) ?></b>
        &nbsp; • &nbsp; <b>Son güncelleme:</b> <?= h($r['updated_at'] ?: $r['created_at']) ?>
      </div>
    </a>
    <?php
  }
} else {
  ?>
  <div class="card-sub">Kayıt yok.</div>
  <?php
}

$html = ob_get_clean();

echo json_encode([
  'ok'    => true,
  'shown' => count($rows),
  'html'  => $html,
]);
