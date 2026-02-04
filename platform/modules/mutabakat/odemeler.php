<?php
// /platform/mutabakat/odemeler.php
// Mutabakat V2 - Odemeler
// Akis:
// - Atama olusur (status=BEKLEMEDE)
// - Tali onaylar (status=ONAYLANDI) / reddeder (REDDEDILDI) / itiraz (ITIRAZDA)
// - Ana acente ONAYLANDI atamalari "ODENDI" olarak isaretler

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_mutabakat_odemeler_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

require_mutabakat_access();

$conn = db();
if (!$conn) { http_response_code(500); exit('DB yok'); }
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$agencyId = (int)($_SESSION['agency_id'] ?? 0);
$isMain   = is_main_role();
$isTali   = is_tali_role();

if (!$agencyId) { http_response_code(403); exit('Agency yok'); }

$mainAgencyId = $isMain ? $agencyId : get_main_agency_id($conn, $agencyId);
$taliAgencyId = $isTali ? $agencyId : 0;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_try($n): string { return number_format((float)$n, 2, ',', '.'); }

/* =========================================================
   Period select (havuz.php ile uyumlu)
========================================================= */
$periodId = (int)($_GET['period_id'] ?? 0);
$periods = [];

$st = $conn->prepare("SELECT id, year, month, status FROM mutabakat_v2_periods WHERE ana_acente_id=? ORDER BY year DESC, month DESC");
$st->bind_param('i', $mainAgencyId);
$st->execute();
$res = $st->get_result();
while ($res && ($row = $res->fetch_assoc())) { $periods[] = $row; }
$st->close();

if (!$periodId) {
  $cy = (int)date('Y');
  $cm = (int)date('n');
  $found = 0;
  foreach ($periods as $p) {
    if ((int)$p['year'] === $cy && (int)$p['month'] === $cm) { $found = (int)$p['id']; break; }
  }
  if ($found) {
    $periodId = $found;
  } else {
    $ins = $conn->prepare("INSERT INTO mutabakat_v2_periods (ana_acente_id, year, month, status, created_at) VALUES (?, ?, ?, 'OPEN', NOW())");
    $ins->bind_param('iii', $mainAgencyId, $cy, $cm);
    if ($ins->execute()) {
      $periodId = (int)$conn->insert_id;
      array_unshift($periods, ['id' => $periodId, 'year' => $cy, 'month' => $cm, 'status' => 'OPEN']);
    } elseif (!empty($periods)) {
      $periodId = (int)$periods[0]['id'];
    }
    $ins->close();
  }
}

/* =========================================================
   POST actions
========================================================= */
$flashOk = '';
$flashErr = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  // MAIN: mark paid
  if ($action === 'mark_paid' && $isMain) {
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $note = trim((string)($_POST['paid_note'] ?? ''));
    if (mb_strlen($note) > 500) { $note = mb_substr($note, 0, 500); }

    if ($assignmentId <= 0) {
      $flashErr = 'Gecersiz atama.';
    } else {
      $conn->begin_transaction();
      try {
        // Yetki + durum kontrolu
        $st = $conn->prepare("SELECT id, status FROM mutabakat_v2_assignments WHERE id=? AND period_id=? AND status='ONAYLANDI' LIMIT 1");
        $st->bind_param('ii', $assignmentId, $periodId);
        $st->execute();
        $st->store_result();
        if ($st->num_rows === 0) {
          $st->close();
          throw new Exception('Atama bulunamadi veya ONAYLANDI degil.');
        }
        $st->close();

        // ODENDI isaretle
        $noteDb = $note === '' ? null : $note;
        $st = $conn->prepare("UPDATE mutabakat_v2_assignments SET status='ODENDI', paid_by=?, paid_at=NOW(), paid_note=?, updated_at=NOW() WHERE id=?");
        $st->bind_param('isi', $userId, $noteDb, $assignmentId);
        $st->execute();
        $st->close();

        $conn->commit();
        $flashOk = 'Odeme ODENDI olarak isaretlendi.';
      } catch (Throwable $e) {
        $conn->rollback();
        $flashErr = 'Odeme hatasi: ' . $e->getMessage();
      }
    }
  }

  // MAIN: unpay (geri al)
  if ($action === 'unmark_paid' && $isMain) {
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    if ($assignmentId > 0) {
      $st = $conn->prepare("UPDATE mutabakat_v2_assignments SET status='ONAYLANDI', paid_by=NULL, paid_at=NULL, paid_note=NULL, updated_at=NOW() WHERE id=? AND period_id=? AND status='ODENDI'");
      $st->bind_param('ii', $assignmentId, $periodId);
      $st->execute();
      $aff = (int)$st->affected_rows;
      $st->close();
      $flashOk = $aff > 0 ? 'Odeme geri alindi (ONAYLANDI).': 'Islem yapilamadi.';
    }
  }
}

/* =========================================================
   Data
========================================================= */

// Tali listesi (Main icin)
$taliList = [];
if ($isMain) {
  $st = $conn->prepare("SELECT id, name FROM agencies WHERE parent_id=? AND is_active=1 ORDER BY name ASC");
  $st->bind_param('i', $mainAgencyId);
  $st->execute();
  $res = $st->get_result();
  while ($res && ($r = $res->fetch_assoc())) { $taliList[] = $r; }
  $st->close();
}

// Atamalar listesi
$rows = [];
if ($periodId) {
  if ($isMain) {
    $sql = "
      SELECT a.id, a.period_id, a.tali_acente_id, ag.name AS tali_name,
             a.status, a.summary_satis, a.summary_iptal, a.summary_zeyil, a.summary_hakedis,
             a.assigned_at, a.approved_at, a.paid_at, a.paid_note
      FROM mutabakat_v2_assignments a
      LEFT JOIN agencies ag ON ag.id=a.tali_acente_id
      WHERE a.period_id=?
      ORDER BY
        (a.status='ONAYLANDI') DESC,
        (a.status='ODENDI') DESC,
        a.assigned_at DESC
      LIMIT 500
    ";
    $st = $conn->prepare($sql);
    $st->bind_param('i', $periodId);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) { $rows[] = $r; }
    $st->close();
  } else {
    $sql = "
      SELECT a.id, a.period_id, a.tali_acente_id,
             a.status, a.summary_satis, a.summary_iptal, a.summary_zeyil, a.summary_hakedis,
             a.assigned_at, a.approved_at, a.paid_at, a.paid_note,
             a.tali_notes, a.rejection_reason
      FROM mutabakat_v2_assignments a
      WHERE a.period_id=? AND a.tali_acente_id=?
      ORDER BY a.assigned_at DESC
      LIMIT 200
    ";
    $st = $conn->prepare($sql);
    $st->bind_param('ii', $periodId, $agencyId);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) { $rows[] = $r; }
    $st->close();
  }
}

// Ozetler
$sum = [
  'onaylandi' => 0.0,
  'odendI' => 0.0,
  'beklemede' => 0.0,
  'itirazda' => 0.0,
];
foreach ($rows as $r) {
  $hak = (float)($r['summary_hakedis'] ?? 0);
  $stt = (string)($r['status'] ?? '');
  if ($stt === 'ONAYLANDI') $sum['onaylandi'] += $hak;
  if ($stt === 'ODENDI') $sum['odendI'] += $hak;
  if ($stt === 'BEKLEMEDE') $sum['beklemede'] += $hak;
  if ($stt === 'ITIRAZDA') $sum['itirazda'] += $hak;
}

/* =========================================================
   Render
========================================================= */

require_once __DIR__ . '/../../partials/header.php';

?>

<div class="container">
  <div class="d-flex align-items-center justify-content-between">
    <div>
      <h2>Ödemeler</h2>
      <div>Mutabakat v2 → Atama → Onay → Ödeme</div>
    </div>
  </div>

  <?php if ($flashOk): ?>
    <div class="alert alert-success"><?= h($flashOk) ?></div>
  <?php endif; ?>
  <?php if ($flashErr): ?>
    <div class="alert alert-danger"><?= h($flashErr) ?></div>
  <?php endif; ?>

  <form method="get" class="card">
    <div class="d-flex">
      <div>
        <label>Dönem</label>
        <select name="period_id" class="form-control">
          <?php foreach ($periods as $p):
            $pid = (int)$p['id'];
            $label = sprintf('%04d-%02d (%s)', (int)$p['year'], (int)$p['month'], (string)$p['status']);
          ?>
            <option value="<?= $pid ?>" <?= $pid===$periodId?'selected':''; ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button class="btn btn-primary" type="submit">Göster</button>
      </div>
      <div class="ms-auto">
        <div class="badge bg-light text-dark">
          Onaylandı: <b><?= h(fmt_try($sum['onaylandi'])) ?></b>
        </div>
        <div class="badge bg-light text-dark">
          Ödendi: <b><?= h(fmt_try($sum['odendI'])) ?></b>
        </div>
        <div class="badge bg-light text-dark">
          Beklemede: <b><?= h(fmt_try($sum['beklemede'])) ?></b>
        </div>
        <div class="badge bg-light text-dark">
          İtirazda: <b><?= h(fmt_try($sum['itirazda'])) ?></b>
        </div>
      </div>
    </div>
  </form>

  <div class="card">
    <div>Atama Ödemeleri</div>
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <?php if ($isMain): ?><th>Tali</th><?php endif; ?>
            <th>Durum</th>
            <th>Hakedis</th>
            <th>Atandı</th>
            <th>Onay</th>
            <th>Ödeme</th>
            <?php if ($isMain): ?><th>İşlem</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="<?= $isMain?8:7 ?>">Kayıt yok.</td></tr>
          <?php else: foreach ($rows as $r):
            $stt = (string)($r['status'] ?? '');
            $badge = 'secondary';
            if ($stt==='BEKLEMEDE') $badge='warning';
            if ($stt==='ONAYLANDI') $badge='success';
            if ($stt==='REDDEDILDI') $badge='danger';
            if ($stt==='ITIRAZDA') $badge='info';
            if ($stt==='ODENDI') $badge='dark';
          ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <?php if ($isMain): ?><td><?= h((string)($r['tali_name'] ?? ('#'.(int)$r['tali_acente_id']))) ?></td><?php endif; ?>
              <td><span class="badge bg-<?= h($badge) ?>"><?= h($stt) ?></span></td>
              <td><b><?= h(fmt_try($r['summary_hakedis'] ?? 0)) ?></b> TRY</td>
              <td>
                <?= h((string)($r['assigned_at'] ?? '')) ?>
              </td>
              <td>
                <?= h((string)($r['approved_at'] ?? '')) ?>
              </td>
              <td>
                <?= h((string)($r['paid_at'] ?? '')) ?>
              </td>
              <?php if ($isMain): ?>
                <td>
                  <?php if ($stt==='ONAYLANDI'): ?>
                    <form method="post">
                      <input type="hidden" name="action" value="mark_paid">
                      <input type="hidden" name="assignment_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="period_id" value="<?= (int)$periodId ?>">
                      <input class="form-control" name="paid_note" placeholder="Not (opsiyonel)" />
                      <button class="btn btn-dark" type="submit" onclick="return confirm('Ödendi olarak işaretlensin mi?');">Ödendi</button>
                    </form>
                  <?php elseif ($stt==='ODENDI'): ?>
                    <form method="post">
                      <input type="hidden" name="action" value="unmark_paid">
                      <input type="hidden" name="assignment_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="period_id" value="<?= (int)$periodId ?>">
                      <button class="btn btn-outline-secondary btn-sm" type="submit" onclick="return confirm('Ödeme geri alınsın mı? (ONAYLANDI)');">Geri Al</button>
                    </form>
                  <?php else: ?>
                    <span>—</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../partials/footer.php'; ?>
