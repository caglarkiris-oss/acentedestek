<?php
// /public_html/platform/ticket.php

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
    header("Location: " . base_url("login.php"));
    exit;
  }
}

// DB
$conn = db();
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

// ROLE (SUPERADMIN yok)
$ALLOWED = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($userRole, $ALLOWED, true)) {
  http_response_code(403);
  exit("Bu sayfaya erişim yetkiniz yok.");
}

/* ✅ FIX: helpers.php içinde h() varsa fatal olmasın */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

/* ✅ PLATFORM URL FIX (rewrite + base_url kaymaları için) */
if (!function_exists('platform_base_url')) {
  function platform_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/platform/ticket.php');
    $dir    = rtrim(str_replace('\\','/', dirname($script)), '/');
    if ($dir === '' || $dir === '.') $dir = '/platform';
    if (stripos($dir, '/platform') === false) $dir = '/platform';

    return $scheme . '://' . $host . $dir . '/';
  }
}
if (!function_exists('platform_url')) {
  function platform_url(string $rel): string {
    return platform_base_url() . ltrim($rel, '/');
  }
}

if (!function_exists('status_tr')) {
  function status_tr($s) {
    switch ((string)$s) {
      case 'open': return 'Açık';
      case 'in_progress': return 'İşlemde';
      case 'waiting_response': return 'Yanıt Bekliyor';
      case 'closed': return 'Kapalı';
      default: return (string)$s;
    }
  }
}

/* ✅ Workflow label */
function workflow_tr(string $s): string {
  $map = [
    'ACILDI' => 'Açıldı',
    'POLICE_GIRISI_YAPILDI' => 'Teklif girişi yapıldı',
    'SATIS_YAPILDI' => 'Satış yapıldı',
    'SATIS_YAPILMADI' => 'Satış yapılamadı',
    'IPTAL' => 'İptal',
    'ZEYIL' => 'Zeyil',
  ];
  $s = strtoupper(trim((string)$s));
  return $map[$s] ?? ($s ?: '—');
}

/* ✅ Branch label map */
$BRANCH_LABELS = [
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

/* ✅ Field label map */
$FIELD_LABELS = [
  'insured_name'   => 'Sigortalı Ad Soyad / Ünvan',
  'plate'          => 'Plaka',
  'tc'             => 'T.C. Kimlik No / Vergi No',
  'birth_date'     => 'Doğum Tarihi',
  'license_serial' => 'Ruhsat Seri No',
  'model_year'     => 'Model Yılı',
  'brand_code'     => 'Marka',
  'model_code'     => 'Model',
  'body_type'      => 'Kasa Tipi',
  'usage_type'     => 'Kullanım Türü',
  'address'        => 'Adres',
  'uavt'           => 'UAVT No',
  'building_year'  => 'Bina Yapım Yılı',
  'square_m2'      => 'Brüt m²',
  'floor'          => 'Kat',
  'has_dask'       => 'DASK var mı?',
  'company_title'  => 'İşyeri Ünvanı',
  'tax_no'         => 'Vergi No',
  'has_alarm'      => 'Alarm var mı?',
  'birth_year'     => 'Doğum Yılı',
  'gender'         => 'Cinsiyet',
  'coverage'       => 'Teminat',
  'trip_days'      => 'Seyahat Süresi',
  'region'         => 'Bölge',
  'occupation'     => 'Meslek Grubu',
  'cargo_type'     => 'Yük Tipi',
  'transport'      => 'Taşıma Şekli',
  'route'          => 'Güzergah',
  'liability_type' => 'Sorumluluk Türü',
  'limit'          => 'Limit',
  'eng_type'       => 'Ürün',
  'project_year'   => 'Proje Yılı',
  'phone'          => 'Telefon',
  'note'           => 'Ek Not',
];

function format_bytes($bytes): string {
  $bytes = (float)$bytes;
  if ($bytes <= 0) return "0 B";
  $units = ['B','KB','MB','GB','TB'];
  $i = 0;
  while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
  return rtrim(rtrim(number_format($bytes, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
}

function get_ticket_id(): int {
  $id = (int)($_GET['id'] ?? $_GET['ticket_id'] ?? 0);
  if ($id > 0) return $id;

  $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
  if (preg_match('~(?:ticket\.php/|/ticket/)(\d+)~', $uri, $m)) return (int)$m[1];
  if (preg_match('~(\d+)(?:\D*)$~', $uri, $m2)) return (int)$m2[1];
  return 0;
}

/** ✅ User bazlı "gördü" yaz */
function mark_ticket_seen(mysqli $conn, int $ticketId, int $userId): void {
  if ($ticketId <= 0 || $userId <= 0) return;

  $sql = "
    INSERT INTO ticket_user_state (ticket_id, user_id, last_seen_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_seen_at = NOW()
  ";
  $st = $conn->prepare($sql);
  if ($st) {
    $st->bind_param("ii", $ticketId, $userId);
    $st->execute();
    $st->close();
  }
}

/* ✅ ticket_extras oku */
function load_ticket_extras(mysqli $conn, int $ticketId): array {
  $out = ['branch_key'=>'','fields'=>[],'companies'=>[]];

  $st = $conn->prepare("SELECT branch_key, fields_json, companies_json FROM ticket_extras WHERE ticket_id=? LIMIT 1");
  if (!$st) return $out;

  $st->bind_param("i", $ticketId);
  $st->execute();
  $st->bind_result($bk, $fj, $cj);

  if ($st->fetch()) {
    $out['branch_key'] = (string)$bk;

    $f = [];
    $c = [];
    if (is_string($fj) && $fj !== '') {
      $tmp = json_decode($fj, true);
      if (is_array($tmp)) $f = $tmp;
    }
    if (is_string($cj) && $cj !== '') {
      $tmp2 = json_decode($cj, true);
      if (is_array($tmp2)) $c = $tmp2;
    }

    $out['fields'] = is_array($f) ? $f : [];
    $out['companies'] = is_array($c) ? array_values($c) : [];
  }

  $st->close();
  return $out;
}

/* ✅ Companies: ID -> Name map */
function company_id_to_name_map(mysqli $conn, array $ids): array {
  $ids = array_values(array_unique(array_filter(array_map(function($x){
    if (is_int($x)) return $x;
    if (is_string($x) && ctype_digit($x)) return (int)$x;
    return null;
  }, $ids), fn($v)=>$v !== null && $v > 0)));

  if (empty($ids)) return [];

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));
  $sql = "SELECT id, name FROM insurance_companies WHERE id IN ($placeholders)";
  $st = $conn->prepare($sql);
  if (!$st) return [];

  $st->bind_param($types, ...$ids);
  $st->execute();
  $st->bind_result($cid, $cname);

  $map = [];
  while ($st->fetch()) $map[(int)$cid] = (string)$cname;
  $st->close();
  return $map;
}

/* ✅ Target agency'nin çalıştığı şirket id seti */
function agency_company_set(mysqli $conn, int $agencyId): array {
  if ($agencyId <= 0) return [];
  $set = [];

  $st = $conn->prepare("SELECT company_id FROM agency_companies WHERE agency_id=?");
  if (!$st) return $set;

  $st->bind_param("i", $agencyId);
  $st->execute();
  $st->bind_result($cid);
  while ($st->fetch()) $set[(int)$cid] = true;
  $st->close();

  return $set;
}

/* ✅ birth_day/month/year -> birth_date */
function normalize_fields_for_display(array $fields): array {
  $day   = isset($fields['birth_day']) ? trim((string)$fields['birth_day']) : '';
  $month = isset($fields['birth_month']) ? trim((string)$fields['birth_month']) : '';
  $year  = isset($fields['birth_year']) ? trim((string)$fields['birth_year']) : '';

  if ($day !== '' || $month !== '' || $year !== '') {
    $dd = ($day !== '') ? str_pad($day, 2, '0', STR_PAD_LEFT) : '??';
    $mm = ($month !== '') ? str_pad($month, 2, '0', STR_PAD_LEFT) : '??';
    $yy = ($year !== '') ? $year : '????';
    $fields['birth_date'] = $dd . '/' . $mm . '/' . $yy;
    unset($fields['birth_day'], $fields['birth_month'], $fields['birth_year']);
  }

  if (isset($fields['gender'])) {
    $g = (string)$fields['gender'];
    if ($g === 'kadin') $fields['gender'] = 'Kadın';
    elseif ($g === 'erkek') $fields['gender'] = 'Erkek';
    elseif ($g === 'diger') $fields['gender'] = 'Diğer';
  }
  if (isset($fields['has_dask'])) {
    $v = (string)$fields['has_dask'];
    if ($v === 'evet') $fields['has_dask'] = 'Evet';
    if ($v === 'hayir') $fields['has_dask'] = 'Hayır';
  }

  return $fields;
}

/* ✅ Kart bilgisi oku */
function load_ticket_card_info(mysqli $conn, int $ticketId): array {
  $out = [
    'card_holder' => '',
    'card_number' => '',
    'exp_month'   => '',
    'exp_year'    => '',
    'cvv'         => '',
    'updated_at'  => '',
  ];
  if ($ticketId <= 0) return $out;

  $st = $conn->prepare("
    SELECT card_holder, card_number, exp_month, exp_year, cvv, updated_at
    FROM ticket_card_info
    WHERE ticket_id=?
    LIMIT 1
  ");
  if (!$st) return $out;

  $st->bind_param("i", $ticketId);
  $st->execute();
  $st->bind_result($h1, $n, $m, $y, $c, $u);
  if ($st->fetch()) {
    $out['card_holder'] = (string)$h1;
    $out['card_number'] = (string)$n;
    $out['exp_month']   = (string)$m;
    $out['exp_year']    = (string)$y;
    $out['cvv']         = (string)$c;
    $out['updated_at']  = (string)$u;
  }
  $st->close();
  return $out;
}

/* =============== */
$ticketId = get_ticket_id();
if ($ticketId <= 0) {
  http_response_code(400);
  exit("Geçersiz ticket id.");
}

try {
  // ✅ Ticket çek
  $sql = "
    SELECT id, created_by_user_id, created_by_agency_id, target_agency_id,
           subject, description, branch, status, created_at, updated_at,
           workflow_status, workflow_updated_at, workflow_updated_by_user_id
    FROM tickets
    WHERE id = ?
      AND (created_by_agency_id = ? OR target_agency_id = ?)
    LIMIT 1
  ";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
  $stmt->bind_param("iii", $ticketId, $agencyId, $agencyId);

  if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

  $id=0; $createdByUserId=0; $createdByAgencyId=0; $targetAgencyId=0;
  $subject=''; $description=''; $branch=''; $status=''; $createdAt=''; $updatedAt='';
  $workflowStatus = 'ACILDI'; $workflowUpdatedAt = null; $workflowUpdatedBy = null;

  $stmt->bind_result(
    $id, $createdByUserId, $createdByAgencyId, $targetAgencyId,
    $subject, $description, $branch, $status, $createdAt, $updatedAt,
    $workflowStatus, $workflowUpdatedAt, $workflowUpdatedBy
  );

  if (!$stmt->fetch()) {
    http_response_code(404);
    exit("Ticket bulunamadı veya erişim yok.");
  }
  $stmt->close();

  // ✅ Ticket’a girdin -> "seen"
  mark_ticket_seen($conn, (int)$id, $userId);

  // ✅ Extras
  $extras = load_ticket_extras($conn, (int)$id);
  $extraFields = normalize_fields_for_display((array)($extras['fields'] ?? []));
  $extraCompanies = (array)($extras['companies'] ?? []);

  $companyIdList = [];
  $companyNameListLegacy = [];
  foreach ($extraCompanies as $c) {
    if (is_int($c) || (is_string($c) && ctype_digit($c))) $companyIdList[] = (int)$c;
    else {
      $s = trim((string)$c);
      if ($s !== '') $companyNameListLegacy[] = $s;
    }
  }

  $companyMap = company_id_to_name_map($conn, $companyIdList);
  $targetAgencyCompanySet = agency_company_set($conn, (int)$targetAgencyId);

  // ✅ Messages (son 200)
  $messages = [];
  $ms = $conn->prepare("
    SELECT sender_user_id, sender_agency_id, message, created_at
    FROM ticket_messages
    WHERE ticket_id = ?
    ORDER BY id DESC
    LIMIT 200
  ");
  if ($ms) {
    $ms->bind_param("i", $ticketId);
    $ms->execute();
    $ms->bind_result($mUser, $mAgency, $mText, $mAt);
    while ($ms->fetch()) {
      $messages[] = [
        'user_id' => (int)$mUser,
        'agency_id' => (int)$mAgency,
        'message' => (string)$mText,
        'created_at' => (string)$mAt,
      ];
    }
    $ms->close();
    $messages = array_reverse($messages);
  }

  /* ✅ Attachments (son 80) - FIX: ticket_files */
  $attachments = [];
  $as = $conn->prepare("
    SELECT
      tf.id,
      tf.original_name,
      tf.file_size,
      tf.created_at,
      COALESCE(u.agency_id, 0) AS uploader_agency_id
    FROM ticket_files tf
    LEFT JOIN users u ON u.id = tf.uploaded_by
    WHERE tf.ticket_id = ?
    ORDER BY tf.id DESC
    LIMIT 80
  ");
  if ($as) {
    $as->bind_param("i", $ticketId);
    $as->execute();
    $as->bind_result($aid, $oname, $fsize, $cat, $uAgency);
    while ($as->fetch()) {
      $attachments[] = [
        'id' => (int)$aid,
        'name' => (string)$oname,
        'size' => (int)$fsize,
        'created_at' => (string)$cat,
        'uploader_agency_id' => (int)$uAgency,
      ];
    }
    $as->close();
  }

} catch (Throwable $e) {
  http_response_code(500);
  error_log("ticket.php EX: ".$e->getMessage());
  echo $debug ? "<pre>ticket.php HATA:\n" . h($e->getMessage()) . "</pre>" : "Geçici bir hata oluştu.";
  exit;
}

$isClosed = ((string)$status === 'closed');

// ✅ Mutabakat prefill (ticket_extras -> mutabakat form)
$pref_tc_vn   = trim((string)($extraFields['tc'] ?? $extraFields['tax_no'] ?? ''));
$pref_plaka   = trim((string)($extraFields['plate'] ?? ''));
$pref_sigorta = trim((string)($extraFields['insured_name'] ?? $extraFields['company_title'] ?? ''));
$pref_brans   = (string)($BRANCH_LABELS[$branch] ?? ($branch ?: ''));

// Şirket: ticket'te 1 tane seçiliyse otomatik doldur
$pref_sirket = '';
if (!empty($companyIdList) && count($companyIdList) === 1) {
  $onlyId = (int)$companyIdList[0];
  $pref_sirket = (string)($companyMap[$onlyId] ?? '');
}

/** ✅ Ticket’i kim açtıysa onun AGENCY’si kapatır */
$canClose = ((int)$createdByAgencyId === (int)$agencyId);

/** ✅ Ana acente tarafı (target agency) */
$isAnaAcenteSide = ((int)$targetAgencyId === (int)$agencyId);

/** ✅ İş akışı ve kurallar */
$wf = strtoupper(trim((string)$workflowStatus));

$WF_CAN_CLOSE   = ['SATIS_YAPILDI','SATIS_YAPILMADI','IPTAL','ZEYIL'];
$WF_NEEDS_MUTA  = ['SATIS_YAPILDI','IPTAL','ZEYIL'];

$showMutabakat = (!$isClosed && $canClose && in_array($wf, $WF_NEEDS_MUTA, true));
$showCloseBtn  = (!$isClosed && $canClose && in_array($wf, $WF_CAN_CLOSE, true));

/* ✅ Kart panel (sol) sadece TEKLIF GIRISI YAPILDI iken */
$WF_CARD = 'POLICE_GIRISI_YAPILDI';
$showCardPanel = (!$isClosed && $wf === $WF_CARD && ($canClose || $isAnaAcenteSide));
$cardEditable  = (!$isClosed && $wf === $WF_CARD && $canClose);
$cardInfo = $showCardPanel ? load_ticket_card_info($conn, (int)$id) : [
  'card_holder'=>'','card_number'=>'','exp_month'=>'','exp_year'=>'','cvv'=>'','updated_at'=>''
];

$appName = (string) config('app.name', 'App');


$pageTitle = 'Ticket Detay';
$currentPage = 'tickets';
require_once __DIR__ . "/../../layout/header.php";
?>


<section class="page">
  <div class="ticket-layout
    <?= $showMutabakat ? 'has-muta' : 'no-muta' ?>
    <?= $showCardPanel ? 'has-card' : 'no-card' ?>
  ">

    <!-- SOL: Kart Paneli -->
    <?php if ($showCardPanel): ?>
      <?php
        $mm = (string)($cardInfo['exp_month'] ?? '');
        $yy = (string)($cardInfo['exp_year'] ?? '');
        $yy2 = ($yy !== '' && strlen($yy) >= 2) ? substr($yy, -2) : '';
        $expText = ($mm && $yy2) ? (str_pad($mm,2,'0',STR_PAD_LEFT).'/'.$yy2) : '';
        $hasCard = trim((string)($cardInfo['card_number'] ?? '')) !== '';
        $canCopy = ((!$cardEditable) && $hasCard);
      ?>

      <aside class="cardbox" id="jsCardBox">
        <div class="ttl">
          <span>💳 Kart Bilgileri</span>
          <?php if (!empty($cardInfo['updated_at'])): ?>
            <span class="card-sub">Son: <?= h($cardInfo['updated_at']) ?></span>
          <?php endif; ?>
        </div>

        <div class="ccard" id="ccard">
          <div class="ccard-top">
            <div class="ccard-brand"><?= h($appName) ?></div>
            <div class="ccard-chip" aria-hidden="true"></div>
          </div>

          <div class="ccard-number" id="pv_number">
            <?= $hasCard ? h((string)$cardInfo['card_number']) : '•••• •••• •••• ••••' ?>
          </div>

          <div class="ccard-row">
            <div>
              <div class="ccard-label">KART SAHİBİ</div>
              <div class="ccard-value" id="pv_name">
                <?= $hasCard ? h((string)$cardInfo['card_holder']) : '—' ?>
              </div>
            </div>
            <div>
              <div class="ccard-label">SKT</div>
              <div class="ccard-value" id="pv_exp"><?= $expText ? h($expText) : '—' ?></div>
            </div>
          </div>

          <?php if ($canCopy): ?>
            <div class="ccard-actions">
              <button type="button" class="copybtn" id="cp_number">Numara Kopyala</button>
              <button type="button" class="copybtn" id="cp_exp">SKT Kopyala</button>
              <button type="button" class="copybtn" id="cp_cvv">CVV Kopyala</button>
            </div>
            <div class="ccard-note" id="pv_hint">Kopyalama: tek tık</div>
          <?php endif; ?>
        </div>

        <?php if ($cardEditable): ?>
          <div class="grid">
            <div class="row">
              <label>Kart Sahibi</label>
              <input id="c_holder" class="input" value="<?= h($cardInfo['card_holder'] ?? '') ?>" placeholder="Ad Soyad">
            </div>

            <div class="row">
              <label>Kart Numarası (16 hane)</label>
              <input id="c_number" class="input" inputmode="numeric"
                     value="<?= h($cardInfo['card_number'] ?? '') ?>" placeholder="1234 5678 9012 3456" maxlength="19">
            </div>

            <div class="row">
              <label>Son Kullanım (MM/YY)</label>
              <input id="c_exp" class="input" inputmode="numeric"
                     value="<?= h($expText) ?>" placeholder="03/30" maxlength="5">
            </div>

            <div class="row">
              <label>CVV</label>
              <input id="c_cvv" class="input" inputmode="numeric"
                     value="<?= h($cardInfo['cvv'] ?? '') ?>" placeholder="123" maxlength="4">
            </div>
          </div>

          <div class="actions">
            <button type="button" id="jsCardSave" class="btn-sm">Kaydet</button>
            <span id="jsCardInfo" class="card-sub"></span>
          </div>
        <?php endif; ?>

        <div class="card-sub">
          Not: Bu panel sadece <b>Teklif girişi yapıldı</b> durumunda görünür.
        </div>
      </aside>
    <?php endif; ?>

    <!-- ORTA: ticket -->
    <div class="card">
      <div class="card-title">Ticket #<?= (int)$id ?></div>

      <div class="card-sub">
        Durum: <b id="jsStatusText"><?= h(status_tr($status)) ?></b>
        &nbsp; • &nbsp;
        Branş: <b><?= h($BRANCH_LABELS[$branch] ?? ($branch ?: '-')) ?></b>
        &nbsp; • &nbsp;
        Oluşturma: <?= h($createdAt ?: '-') ?>
        &nbsp; • &nbsp;
        İş Akışı: <b id="jsWfTextInline"><?= h(workflow_tr((string)$workflowStatus)) ?></b>
      </div>

      <!-- Workflow -->
      <div class="wfbar">
        <span class="wf-badge">İş Akışı: <span id="jsWfText"><?= h(workflow_tr((string)$workflowStatus)) ?></span></span>

        <?php if (!$isClosed && $isAnaAcenteSide): ?>
          <select id="jsWfSel" class="input">
            <option value="">İş akışı güncelle (ana acente)</option>
            <option value="POLICE_GIRISI_YAPILDI">Teklif girişi yapıldı</option>
            <option value="SATIS_YAPILDI">Satış yapıldı</option>
            <option value="SATIS_YAPILMADI">Satış yapılamadı</option>
            <option value="IPTAL">İptal</option>
            <option value="ZEYIL">Zeyil</option>
          </select>
          <button id="jsWfBtn" class="btn-sm" type="button">Güncelle</button>
          <span id="jsWfInfo" class="card-sub"></span>
        <?php endif; ?>
      </div>

      <hr>

      <div>
        <div><b>Konu:</b> <?= h($subject ?: '-') ?></div>
        <div><b>Açılış Mesajı:</b><br><?= nl2br(h($description ?: '')) ?></div>
      </div>

      <?php if (!empty($extraFields) || !empty($extraCompanies)): ?>
        <div class="ticket-extra-box">
          <div class="ticket-extra-title">Teklif Bilgileri</div>

          <?php if (!empty($extraFields)): ?>
            <div class="ticket-kv">
              <?php foreach ($extraFields as $k => $v): ?>
                <?php
                  $key = (string)$k;
                  $val = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
                ?>
                <div class="ticket-k"><?= h($FIELD_LABELS[$key] ?? $key) ?></div>
                <div class="ticket-v"><?= h($val) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (!empty($extraCompanies)): ?>
            <div class="ticket-extra-title">Seçilen Şirketler</div>
            <div class="ticket-chips">
              <?php foreach ($companyIdList as $cid):
                $name = $companyMap[$cid] ?? ("#" . $cid);
                $isOk = isset($targetAgencyCompanySet[$cid]);
              ?>
                <span class="ticket-chip" title="<?= $isOk ? '' : 'Bu şirket ana acentede kayıtlı değil' ?>">
                  <?= !$isOk ? '<span class="warn">⚠️</span>' : '' ?>
                  <?= h($name) ?>
                </span>
              <?php endforeach; ?>

              <?php foreach ($companyNameListLegacy as $nm): ?>
                <span class="ticket-chip"><?= h($nm) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <hr>

      <div class="card-sub"><b>Mesaj Geçmişi</b></div>
      <div id="jsThread">
        <?php
          $messagesFiltered = [];
          foreach ($messages as $mmx) {
            $t = trim((string)($mmx['message'] ?? ''));
            if ($t === '' || $t === '-') continue;
            $messagesFiltered[] = $mmx;
          }
        ?>
        <?php if (empty($messagesFiltered)): ?>
          <div class="card-sub">Henüz yanıt yok.</div>
        <?php else: ?>
          <?php foreach ($messagesFiltered as $m): ?>
            <div class="card">
              <div class="card-sub">
                <span>
                  <?php
                    $mUserId = (int)($m['user_id'] ?? 0);
                    $label = "<b>Karşı taraf</b>";
                    if ($mUserId === 0) $label = "<b>Sistem</b>";
                    else if ($mUserId === (int)$userId) $label = "<b>Sen</b>";
                    echo $label;
                  ?>
                  <?php if ($mUserId !== 0): ?>
                    <span>(A:<?= (int)$m['agency_id'] ?>)</span>
                  <?php endif; ?>
                </span>
                <span><?= h($m['created_at']) ?></span>
              </div>
              <div><?= nl2br(h($m['message'])) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <hr>

      <div class="card-sub"><b>Dosyalar</b></div>

      <?php if (!$isClosed): ?>
        <div>
          <input id="jsFile" type="file" class="input">
          <button type="button" id="jsUpload" class="btn-sm">Dosya Yükle</button>
          <span id="jsUploadInfo" class="card-sub"></span>
        </div>
        <div class="card-sub">
          Desteklenen: PDF, Excel, Word, PNG/JPG, TXT, ZIP/RAR. Max 10MB.
        </div>
      <?php else: ?>
        <div class="card-sub">Ticket kapalı olduğu için yeni dosya yüklenemez.</div>
      <?php endif; ?>

      <div class="att-wrap" id="jsAttWrap">
        <?php if (empty($attachments)): ?>
          <div class="card-sub">Henüz dosya yok.</div>
        <?php else: ?>
          <?php foreach ($attachments as $a): ?>
            <div class="att-row">
              <div>
                <div class="att-name"><?= h($a['name']) ?></div>
                <div class="att-meta">
                  <?= h(format_bytes($a['size'])) ?> • <?= h($a['created_at']) ?> • Yükleyen A:<?= (int)$a['uploader_agency_id'] ?>
                </div>
              </div>
              <div class="att-actions">
                <a class="btn-sm" href="<?= h(platform_url('download-attachment.php?id=' . (int)$a['id'])) ?>">İndir</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <hr>

      <?php if ($isClosed): ?>
        <div class="card-sub"><b>Bu ticket kapalı.</b> Yanıt verilemez.</div>
      <?php else: ?>
        <div class="card-sub"><b>Yanıtla</b></div>

        <div>
          <textarea id="jsReply" class="input" rows="5" placeholder="Yanıtını yaz..."></textarea>

          <div>
            <button type="button" id="jsSend" class="btn">Gönder</button>

            <?php if ($showCloseBtn): ?>
              <button type="button" id="jsSendClose" class="btn">
                Gönder & Kapat
              </button>
            <?php else: ?>
              <span class="card-sub">
                <?php if (!$canClose): ?>
                  Ticketi sadece açan acente kapatabilir.
                <?php else: ?>
                  Ticketi kapatmak için iş akışını şu durumlardan biri yapın: <b>Satış yapıldı / Satış yapılamadı / İptal / Zeyil</b>.
                <?php endif; ?>
              </span>
            <?php endif; ?>

            <span id="jsInfo" class="card-sub"></span>
          </div>
        </div>

        <script>
          window.addEventListener('DOMContentLoaded', function(){

            const TICKET_ID = <?= (int)$id ?>;

            const URL_REPLY    = <?= json_encode(platform_url('ajax-ticket-reply.php')) ?>;
            const URL_CLOSE    = <?= json_encode(platform_url('ajax-ticket-close.php')) ?>;
            const URL_WORKFLOW = <?= json_encode(platform_url('ajax-ticket-workflow-update.php')) ?>;
            const URL_UPLOAD   = <?= json_encode(platform_url('ajax-ticket-upload.php')) ?>;
            const URL_MUTA     = <?= json_encode(platform_url('ajax-ticket-mutabakat-save.php')) ?>;
            const URL_CARD     = <?= json_encode(platform_url('ajax-ticket-cardinfo-save.php')) ?>;
            const URL_TICKETS  = <?= json_encode(platform_url('tickets.php')) ?>;

            // ✅ FIX: Ticket'in gerçek branşı (DB'den gelen branch)
            const TICKET_BRANCH = <?= json_encode((string)$branch) ?>;
            const PLATE_REQUIRED_BRANCHES = ['trafik','kasko']; // ✅ sadece bunlarda plaka zorunlu

            const btnSend  = document.getElementById('jsSend');
            const btnClose = document.getElementById('jsSendClose');
            const txt = document.getElementById('jsReply');
            const info= document.getElementById('jsInfo');
            const thread = document.getElementById('jsThread');

            const fileInput = document.getElementById('jsFile');
            const btnUpload = document.getElementById('jsUpload');
            const uploadInfo = document.getElementById('jsUploadInfo');

            const wfSel  = document.getElementById('jsWfSel');
            const wfBtn  = document.getElementById('jsWfBtn');
            const wfText = document.getElementById('jsWfText');
            const wfTextInline = document.getElementById('jsWfTextInline');
            const wfInfo = document.getElementById('jsWfInfo');

            const cardSave = document.getElementById('jsCardSave');
            const cardInfo = document.getElementById('jsCardInfo');
            const c_holder = document.getElementById('c_holder');
            const c_number = document.getElementById('c_number');
            const c_exp    = document.getElementById('c_exp');
            const c_cvv    = document.getElementById('c_cvv');

            const pv_number = document.getElementById('pv_number');
            const pv_name   = document.getElementById('pv_name');
            const pv_exp    = document.getElementById('pv_exp');
            const cp_number = document.getElementById('cp_number');
            const cp_exp    = document.getElementById('cp_exp');
            const cp_cvv    = document.getElementById('cp_cvv');
            const pv_hint   = document.getElementById('pv_hint');

            const btnMutaSave = document.getElementById('jsMutaSave');
            const mutaInfo = document.getElementById('jsMutaInfo');

            const tc_vn = document.getElementById('m_tc_vn');
            const sigortali = document.getElementById('m_sigortali');
            const tanzim = document.getElementById('m_tanzim');
            const sirket = document.getElementById('m_sirket');
            const brans = document.getElementById('m_brans');
            const police = document.getElementById('m_police');
            const plaka = document.getElementById('m_plaka');
            const brut = document.getElementById('m_brut');

            // ✅ UX: plaka label'ını opsiyonel/zorunlu göster
            try{
              const plateLabel = document.getElementById('lbl_plaka');
              const plateIsReq = PLATE_REQUIRED_BRANCHES.includes(String(TICKET_BRANCH||'').toLowerCase());
              if(plateLabel){
                plateLabel.textContent = plateIsReq ? 'Plaka' : 'Plaka (Opsiyonel)';
              }
            }catch(_){}

            const WF = "<?= h($wf) ?>";
            const WF_CAN_CLOSE = ['SATIS_YAPILDI','SATIS_YAPILMADI','IPTAL','ZEYIL'];
            const WF_NEEDS_MUTA = ['SATIS_YAPILDI','IPTAL','ZEYIL'];

            let MUTABAKAT_SAVED = false;

            function esc(s){
              return String(s)
                .replace(/&/g,"&amp;")
                .replace(/</g,"&lt;")
                .replace(/>/g,"&gt;")
                .replace(/"/g,"&quot;")
                .replace(/'/g,"&#039;");
            }
            function nl2br(s){ return esc(s).replace(/\n/g, "<br>"); }

            function workflowLabel(v){
              const m = {
                'ACILDI':'Açıldı',
                'POLICE_GIRISI_YAPILDI':'Teklif girişi yapıldı',
                'SATIS_YAPILDI':'Satış yapıldı',
                'SATIS_YAPILMADI':'Satış yapılamadı',
                'IPTAL':'İptal',
                'ZEYIL':'Zeyil'
              };
              return m[v] || v || '—';
            }

            async function postJSON(url, fd){
              const res = await fetch(url, { method:"POST", body:fd, credentials:"same-origin" });
              const txt = await res.text();
              try { return JSON.parse(txt); }
              catch(e){ console.error("JSON değil:", url, txt); return { ok:false, error:"Sunucu JSON dönmedi." }; }
            }

            function appendSelfMessage(message){
              const box = document.createElement('div');
              box.className = 'card';
              box.style.padding = '12px';
              box.style.background = '#F8FAFF';
              box.innerHTML =
                `<div class="card-sub">
                   <span><b>Sen</b> <span>(A:<?= (int)$agencyId ?>)</span></span>
                   <span>az önce</span>
                 </div>
                 <div>${nl2br(message)}</div>`;
              if(thread && thread.firstElementChild && thread.firstElementChild.classList.contains('card-sub')){
                thread.innerHTML = '';
              }
              if(thread) thread.appendChild(box);
            }

            function onlyDigits(s){ return String(s||'').replace(/\D+/g,''); }
            function formatCardNumber(d){
              d = onlyDigits(d).slice(0,16);
              return d.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
            }
            function formatExp(v){
              v = onlyDigits(v).slice(0,4);
              if(v.length <= 2) return v;
              return v.slice(0,2) + '/' + v.slice(2,4);
            }
            function parseExp(mmYY){
              const d = onlyDigits(mmYY);
              const mm = d.slice(0,2);
              const yy = d.slice(2,4);
              return { mm, yy };
            }
            function copyText(t){
              if(!t) return;
              navigator.clipboard.writeText(t).then(()=>{
                if(pv_hint) pv_hint.textContent = "Kopyalandı ✓";
                setTimeout(()=>{ if(pv_hint) pv_hint.textContent = "Kopyalama: tek tık"; }, 900);
              }).catch(()=>{});
            }

            if(c_number){
              c_number.addEventListener('input', ()=>{
                const f = formatCardNumber(c_number.value);
                c_number.value = f;
                if(pv_number) pv_number.textContent = f || '•••• •••• •••• ••••';
              });
            }
            if(c_holder){
              c_holder.addEventListener('input', ()=>{
                if(pv_name) pv_name.textContent = (c_holder.value||'').trim() || '—';
              });
            }
            if(c_exp){
              c_exp.addEventListener('input', ()=>{
                const f = formatExp(c_exp.value);
                c_exp.value = f;
                if(pv_exp) pv_exp.textContent = f || '—';
              });
            }
            if(c_cvv){
              c_cvv.addEventListener('input', ()=>{
                c_cvv.value = onlyDigits(c_cvv.value).slice(0,4);
              });
            }

            const COPY_CARD_NUMBER = <?= json_encode((string)($cardInfo['card_number'] ?? '')) ?>;
            const COPY_CARD_EXP    = <?= json_encode((string)(($cardInfo['exp_month'] ?? '') && ($cardInfo['exp_year'] ?? '') ? (str_pad((string)$cardInfo['exp_month'],2,'0',STR_PAD_LEFT).'/'.substr((string)$cardInfo['exp_year'],-2)) : '')) ?>;
            const COPY_CARD_CVV    = <?= json_encode((string)($cardInfo['cvv'] ?? '')) ?>;

            if(cp_number) cp_number.addEventListener('click', ()=> copyText(COPY_CARD_NUMBER));
            if(cp_exp)    cp_exp.addEventListener('click', ()=> copyText(COPY_CARD_EXP));
            if(cp_cvv)    cp_cvv.addEventListener('click', ()=> copyText(COPY_CARD_CVV));

            // Kart kaydet
            if (cardSave && c_holder && c_number && c_exp && c_cvv) {
              cardSave.addEventListener('click', async function(e){
                e.preventDefault();
                e.stopPropagation();

                const holder = (c_holder.value || '').trim();
                const number = formatCardNumber(c_number.value);
                const exp    = (c_exp.value || '').trim();
                const cvv    = onlyDigits(c_cvv.value).trim();

                const nd = onlyDigits(number);
                if(!holder || nd.length !== 16 || !exp || exp.length !== 5 || !cvv){
                  if(cardInfo) cardInfo.textContent = "Alanları kontrol et (16 hane / MM/YY / CVV).";
                  return;
                }

                const p = parseExp(exp);
                const mm = p.mm;
                const yy = p.yy;
                const mmN = Number(mm);
                if(!(mmN >= 1 && mmN <= 12) || yy.length !== 2){
                  if(cardInfo) cardInfo.textContent = "Son kullanım MM/YY olmalı (örn 03/30).";
                  return;
                }

                cardSave.disabled = true;
                if(cardInfo) cardInfo.textContent = "Kaydediliyor...";

                const fd = new FormData();
                fd.append('ticket_id', String(TICKET_ID));
                fd.append('card_holder', holder);
                fd.append('card_number', number);
                fd.append('exp_month', mm);
                fd.append('exp_year', "20" + yy);
                fd.append('cvv', cvv);

                const data = await postJSON(URL_CARD, fd);
                if(!data || !data.ok){
                  if(cardInfo) cardInfo.textContent = (data && data.error) ? data.error : "Kaydetme başarısız.";
                  cardSave.disabled = false;
                  return;
                }

                if(cardInfo) cardInfo.textContent = "Kaydedildi.";
                setTimeout(()=>window.location.reload(), 250);
              }, true);
            }

            // Workflow update
            if (wfBtn && wfSel) {
              wfBtn.addEventListener('click', async function(){
                const v = (wfSel.value || '').trim();
                if(!v){ if(wfInfo) wfInfo.textContent = "Durum seç."; return; }

                wfBtn.disabled = true;
                wfSel.disabled = true;
                if(wfInfo) wfInfo.textContent = "Güncelleniyor...";

                const fd = new FormData();
                fd.append('ticket_id', String(TICKET_ID));
                fd.append('id', String(TICKET_ID));
                fd.append('ticket', String(TICKET_ID));
                fd.append('workflow_status', v);

                const data = await postJSON(URL_WORKFLOW, fd);
                if(!data || !data.ok){
                  if(wfInfo) wfInfo.textContent = (data && data.error) ? data.error : "Güncelleme başarısız.";
                  wfBtn.disabled = false;
                  wfSel.disabled = false;
                  return;
                }
                const lbl = workflowLabel(v);
                if (wfText) wfText.textContent = lbl;
                if (wfTextInline) wfTextInline.textContent = lbl;

                if(wfInfo) wfInfo.textContent = "Güncellendi.";
                MUTABAKAT_SAVED = false;
                setTimeout(()=>{ window.location.reload(); }, 250);
              });
            }

            function expectedMutTypeFromWF(wf){
              wf = String(wf || '').toUpperCase().trim();
              if (wf === 'SATIS_YAPILDI') return 'SATIS';
              if (wf === 'IPTAL') return 'IPTAL';
              if (wf === 'ZEYIL') return 'ZEYIL';
              return '';
            }
            function getMutType(){
              return expectedMutTypeFromWF(WF);
            }

            function normalizeGross(v){
              v = String(v || '').trim();
              if(!v) return '';
              v = v.replace(/\s+/g,'');
              v = v.replace(/\./g,'').replace(/,/g,'.');
              return v;
            }

            // ✅ FIX: Plaka sadece trafik/kasko için zorunlu
            function isPlateRequiredByBranch(){
              const b = String(TICKET_BRANCH || '').toLowerCase().trim();
              return PLATE_REQUIRED_BRANCHES.includes(b);
            }

            function validateMutabakat(){
              if (!WF_NEEDS_MUTA.includes(WF)) return '';
              const els = {
                tc_vvn: document.getElementById('m_tc_vn'),
                sig:   document.getElementById('m_sigortali'),
                tan:   document.getElementById('m_tanzim'),
                sir:   document.getElementById('m_sirket'),
                bra:   document.getElementById('m_brans'),
                pol:   document.getElementById('m_police'),
                pla:   document.getElementById('m_plaka'),
                bru:   document.getElementById('m_brut')
              };
              for (const k in els) { if (!els[k]) return 'Mutabakat alanları eksik (UI).'; }

              const mt = getMutType();
              if(!mt) return "Bu iş akışında mutabakat kaydedilemez.";

              if(!els.tc_vvn.value.trim()) return "T.C./V.N. zorunlu.";
              if(!els.sig.value.trim()) return "Sigortalı zorunlu.";
              if(!els.tan.value.trim()) return "Tanzim tarihi zorunlu.";
              if(!els.sir.value.trim()) return "Sigorta şirketi zorunlu.";
              if(!els.bra.value.trim()) return "Branş zorunlu.";
              if(!els.pol.value.trim()) return "Poliçe no zorunlu.";

              // ✅ sadece trafik/kasko ise plaka zorunlu
              if (isPlateRequiredByBranch()) {
                if(!els.pla.value.trim()) return "Plaka zorunlu.";
              }

              const g = normalizeGross(els.bru.value);
              if(g === '') return "Brüt prim zorunlu.";
              if(isNaN(Number(g))) return "Brüt prim sayı olmalı.";

              const n = Number(g);
              if (n === 0) return "Brüt prim 0 olamaz.";
              if (WF === 'SATIS_YAPILDI' && n <= 0) return "Satış için brüt prim pozitif olmalı.";

              return '';
            }

            async function saveMutabakat(){
              if(!btnMutaSave || !mutaInfo) return false;

              const err = validateMutabakat();
              if(err){ mutaInfo.textContent = err; MUTABAKAT_SAVED = false; return false; }

              btnMutaSave.disabled = true;
              mutaInfo.textContent = "Kaydediliyor...";

              const fd = new FormData();
              fd.append('ticket_id', String(TICKET_ID));
              fd.append('mutabakat_type', getMutType());
              fd.append('tc_vn', tc_vn.value.trim());
              fd.append('sigortali_adi', sigortali.value.trim());
              fd.append('tanzim_tarihi', tanzim.value.trim());
              fd.append('sigorta_sirketi', sirket.value.trim());
              fd.append('brans', brans.value.trim());
              fd.append('police_no', police.value.trim());
              // ✅ plaka opsiyonel olabilir, boş da gönderebilir
              fd.append('plaka', plaka.value.trim());
              fd.append('brut_prim', normalizeGross(brut.value));

              const data = await postJSON(URL_MUTA, fd);
              if(!data || !data.ok){
                mutaInfo.textContent = (data && data.error) ? data.error : "Kaydetme başarısız.";
                btnMutaSave.disabled = false;
                MUTABAKAT_SAVED = false;
                return false;
              }

              MUTABAKAT_SAVED = true;
              mutaInfo.textContent = "Kaydedildi. Artık ticket kapatılabilir.";
              btnMutaSave.disabled = false;
              return true;
            }

            if(btnMutaSave){
              btnMutaSave.addEventListener('click', function(){
                saveMutabakat();
              });
            }

            // Upload handler
            if (btnUpload && fileInput) {
              btnUpload.addEventListener('click', async function(){
                if (!fileInput.files || !fileInput.files[0]) {
                  if(uploadInfo) uploadInfo.textContent = "Dosya seç.";
                  return;
                }
                const f = fileInput.files[0];

                btnUpload.disabled = true;
                if(uploadInfo) uploadInfo.textContent = "Yükleniyor...";

                const fd = new FormData();
                fd.append('ticket_id', String(TICKET_ID));
                fd.append('file', f);

                const data = await postJSON(URL_UPLOAD, fd);
                if(!data || !data.ok){
                  if(uploadInfo) uploadInfo.textContent = (data && data.error) ? data.error : "Yükleme başarısız.";
                  btnUpload.disabled = false;
                  return;
                }
                if(uploadInfo) uploadInfo.textContent = "Yüklendi.";
                setTimeout(()=>{ window.location.reload(); }, 350);
              });
            }

            // SEND MESSAGE
            if(btnSend){
              btnSend.addEventListener('click', async function(){
                const message = (txt.value || '').trim();
                if(!message){ if(info) info.textContent = "Mesaj boş olamaz."; return; }

                btnSend.disabled = true;
                if(btnClose) btnClose.disabled = true;
                if(info) info.textContent = "Gönderiliyor...";

                const fd = new FormData();
                fd.append('ticket_id', String(TICKET_ID));
                fd.append('message', message);
                fd.append('redirect', '1');

                const data = await postJSON(URL_REPLY, fd);
                if (data && data.redirect_url) { window.location.href = data.redirect_url; return; }
                if(!data || !data.ok){
                  if(info) info.textContent = (data && data.error) ? data.error : "Hata oluştu.";
                  btnSend.disabled = false;
                  if(btnClose) btnClose.disabled = false;
                  return;
                }
                appendSelfMessage(message);
                if(txt) txt.value = "";
                if(info) info.textContent = "Gönderildi.";
                btnSend.disabled = false;
                if(btnClose) btnClose.disabled = false;
              });
            }

            // SEND + CLOSE
            if(btnClose){
              btnClose.addEventListener('click', async function(){
                const message = (txt.value || '').trim();

                if (!WF_CAN_CLOSE.includes(WF)) {
                  if(info) info.textContent = "Ticketi kapatmak için iş akışını Satış yapıldı / Satış yapılamadı / İptal / Zeyil yap.";
                  return;
                }

                if (WF_NEEDS_MUTA.includes(WF)) {
                  const err = validateMutabakat();
                  if(err){ if(info) info.textContent = err; return; }
                  if(!MUTABAKAT_SAVED){
                    if(info) info.textContent = "Önce mutabakatı Kaydet, sonra kapat.";
                    return;
                  }
                }

                if(btnSend) btnSend.disabled = true;
                btnClose.disabled = true;
                if(info) info.textContent = "Kapatılıyor...";

                const fd = new FormData();
                fd.append('ticket_id', String(TICKET_ID));
                fd.append('redirect', '1');
                if (message) fd.append('message', message);

                const data = await postJSON(URL_CLOSE, fd);
                if (data && data.redirect_url) { window.location.href = data.redirect_url; return; }
                if(!data || !data.ok){
                  if(info) info.textContent = (data && data.error) ? data.error : "Hata oluştu.";
                  if(btnSend) btnSend.disabled = false;
                  btnClose.disabled = false;
                  return;
                }
                window.location.href = URL_TICKETS;
              });
            }

          });
        </script>
      <?php endif; ?>
    </div>

    <!-- SAĞ: Mutabakat -->
    <?php if ($showMutabakat): ?>
      <aside class="muta">
        <div class="ttl">Mutabakat</div>
        <div class="sub">
          İş akışı: <b><?= h(workflow_tr($wf)) ?></b>
        </div>

        <?php
          $active = ($wf === 'SATIS_YAPILDI') ? 'SATIS' : (($wf === 'IPTAL') ? 'IPTAL' : (($wf === 'ZEYIL') ? 'ZEYIL' : ''));
        ?>
        <div class="typechips">
          <span class="chip <?= $active==='SATIS'?'is-active':'' ?>">Satış</span>
          <span class="chip <?= $active==='IPTAL'?'is-active':'' ?>">İptal</span>
          <span class="chip <?= $active==='ZEYIL'?'is-active':'' ?>">Zeyil</span>
        </div>

        <div class="row"><label>T.C. / V.N.</label><input id="m_tc_vn" class="input" placeholder="11 haneli TC / Vergi No" value="<?= h($pref_tc_vn) ?>"></div>
        <div class="row"><label>Sigortalı Adı Soyadı / Ünvanı</label><input id="m_sigortali" class="input" placeholder="Sigortalı" value="<?= h($pref_sigorta) ?>"></div>
        <div class="row"><label>Tanzim Tarihi</label><input id="m_tanzim" type="date" class="input"></div>
        <div class="row"><label>Sigorta Şirketi</label><input id="m_sirket" class="input" placeholder="Şirket adı" value="<?= h($pref_sirket) ?>"></div>
        <div class="row"><label>Branş</label><input id="m_brans" class="input" placeholder="Branş" value="<?= h($pref_brans) ?>"></div>
        <div class="row"><label>Poliçe No</label><input id="m_police" class="input" placeholder="Poliçe no"></div>
        <div class="row"><label>Brüt Prim</label><input id="m_brut" class="input" inputmode="decimal" placeholder="Örn: 12.345,67 (İptal/Zeyil + veya - olabilir)"></div>
        <div class="row">
          <label id="lbl_plaka">Plaka</label>
          <input id="m_plaka" class="input" placeholder="34ABC123" value="<?= h($pref_plaka) ?>">
        </div>

        <div class="savebar">
          <button type="button" id="jsMutaSave" class="btn-sm">Kaydet</button>
          <span id="jsMutaInfo" class="saveinfo"></span>
        </div>
      </aside>
    <?php endif; ?>

  </div>
</section>

<script>
  if (typeof lucide !== 'undefined') lucide.createIcons();
</script>

<?php require_once __DIR__ . "/../../layout/footer.php"; ?>
