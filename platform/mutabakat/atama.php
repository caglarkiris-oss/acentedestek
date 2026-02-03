<?php
// /platform/mutabakat/atama.php
// Mutabakat V2 - Atama ekrani

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Europe/Istanbul');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_mutabakat_atama_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

require_mutabakat_access();

$conn = db();
if (!$conn) { http_response_code(500); exit('DB yok'); }
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$userId   = (int)($_SESSION['user_id'] ?? 0);
$agencyId = (int)($_SESSION['agency_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$isMain   = is_main_role();
$isTali   = is_tali_role();

if (!$agencyId) { http_response_code(403); exit('Agency yok'); }

// Sadece MAIN atama yapabilir
if (!$isMain) {
    http_response_code(403);
    exit('Atama sayfasina sadece ana acente erisebilir.');
}

$mainAgencyId = $agencyId;

/* =========================================================
   Donem secimi
========================================================= */
$periodId = (int)($_GET['period_id'] ?? 0);
$periods  = [];

$st = $conn->prepare("SELECT id, year, month, status FROM mutabakat_v2_periods WHERE ana_acente_id=? ORDER BY year DESC, month DESC");
if ($st) {
    $st->bind_param('i', $mainAgencyId);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($row = $res->fetch_assoc())) $periods[] = $row;
    $st->close();
}

if (!$periodId && !empty($periods)) {
    $periodId = (int)$periods[0]['id'];
}

$flashErr = '';
$flashOk  = '';

/* =========================================================
   Tali acente listesi
========================================================= */
$taliAcenteler = [];
// agency_relations tablosu varsa
if (col_exists($conn, 'agency_relations', 'child_agency_id') && col_exists($conn, 'agency_relations', 'parent_agency_id')) {
    $st = $conn->prepare("SELECT ar.child_agency_id, a.name 
                          FROM agency_relations ar 
                          LEFT JOIN agencies a ON a.id = ar.child_agency_id 
                          WHERE ar.parent_agency_id = ?");
    if ($st) {
        $st->bind_param('i', $mainAgencyId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $taliAcenteler[] = ['id' => (int)$row['child_agency_id'], 'name' => $row['name'] ?? 'Tali #'.$row['child_agency_id']];
        }
        $st->close();
    }
}

// Secili tali acente
$selectedTaliId = (int)($_GET['tali_id'] ?? 0);

/* =========================================================
   POST: Atama islemi
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_assignment') {
        $taliId = (int)($_POST['tali_acente_id'] ?? 0);
        $rowIds = $_POST['row_ids'] ?? [];
        
        if (!is_array($rowIds)) $rowIds = [];
        $rowIds = array_map('intval', $rowIds);
        $rowIds = array_filter($rowIds, fn($x) => $x > 0);

        if ($taliId <= 0) {
            $flashErr = 'Tali acente secilmedi.';
        } elseif (empty($rowIds)) {
            $flashErr = 'Atanacak satir secilmedi.';
        } else {
            $conn->begin_transaction();
            try {
                // Ozet hesapla
                $summarySatis = 0;
                $summaryIptal = 0;
                $summaryZeyil = 0;

                $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
                $types = str_repeat('i', count($rowIds));
                
                $st = $conn->prepare("SELECT id, txn_type, araci_kom_payi FROM mutabakat_v2_rows WHERE id IN ($placeholders) AND period_id=? AND ana_acente_id=?");
                $params = array_merge($rowIds, [$periodId, $mainAgencyId]);
                $st->bind_param($types . 'ii', ...$params);
                $st->execute();
                $res = $st->get_result();
                $validRows = [];
                while ($res && ($r = $res->fetch_assoc())) {
                    $validRows[] = $r;
                    $komPay = (float)($r['araci_kom_payi'] ?? 0);
                    switch ($r['txn_type']) {
                        case 'SATIS': $summarySatis += $komPay; break;
                        case 'IPTAL': $summaryIptal += $komPay; break;
                        case 'ZEYIL': $summaryZeyil += $komPay; break;
                    }
                }
                $st->close();

                if (empty($validRows)) {
                    throw new Exception('Gecerli satir bulunamadi.');
                }

                $summaryHakedis = $summarySatis + $summaryIptal + $summaryZeyil;

                // Assignment olustur
                $stA = $conn->prepare("INSERT INTO mutabakat_v2_assignments 
                    (period_id, tali_acente_id, assigned_by, assigned_at, status, summary_satis, summary_iptal, summary_zeyil, summary_hakedis, created_at)
                    VALUES (?, ?, ?, NOW(), 'ATANDI', ?, ?, ?, ?, NOW())");
                $stA->bind_param('iiidddd', $periodId, $taliId, $userId, $summarySatis, $summaryIptal, $summaryZeyil, $summaryHakedis);
                $stA->execute();
                $assignmentId = (int)$stA->insert_id;
                $stA->close();

                // Assignment rows ekle
                $stAR = $conn->prepare("INSERT INTO mutabakat_v2_assignment_rows (assignment_id, row_id, created_at) VALUES (?, ?, NOW())");
                foreach ($validRows as $vr) {
                    $rowId = (int)$vr['id'];
                    $stAR->bind_param('ii', $assignmentId, $rowId);
                    $stAR->execute();
                }
                $stAR->close();

                $conn->commit();
                $flashOk = "Atama olusturuldu. " . count($validRows) . " satir atandi. Hakedis: " . _fmt_tr_money($summaryHakedis) . " TRY";
            } catch (Throwable $e) {
                $conn->rollback();
                $flashErr = "Atama hatasi: " . $e->getMessage();
            }
        }
    }
}

/* =========================================================
   Atanabilir satirlar (ESLESEN, henuz atanmamis)
========================================================= */
$assignableRows = [];
if ($periodId) {
    // Daha once atanmis row_id'leri bul
    $assignedRowIds = [];
    $st = $conn->prepare("SELECT ar.row_id FROM mutabakat_v2_assignment_rows ar 
                          JOIN mutabakat_v2_assignments a ON a.id = ar.assignment_id 
                          WHERE a.period_id = ?");
    if ($st) {
        $st->bind_param('i', $periodId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $assignedRowIds[(int)$r['row_id']] = true;
        }
        $st->close();
    }

    // ESLESEN ANA_CSV satirlari - tali_acente_id'si match uzerinden belirlenir
    $query = "SELECT r.id, r.policy_no, r.sigortali_adi, r.txn_type, r.araci_kom_payi, r.tanzim_tarihi,
                     m.tali_row_id, tr.tali_acente_id
              FROM mutabakat_v2_rows r
              JOIN mutabakat_v2_matches m ON m.ana_row_id = r.id AND m.period_id = r.period_id
              JOIN mutabakat_v2_rows tr ON tr.id = m.tali_row_id
              WHERE r.period_id = ? AND r.ana_acente_id = ? AND r.source_type = 'ANA_CSV' AND r.row_status = 'ESLESEN'";
    
    if ($selectedTaliId > 0) {
        $query .= " AND tr.tali_acente_id = ?";
    }
    $query .= " ORDER BY r.id DESC LIMIT 500";

    $st = $conn->prepare($query);
    if ($selectedTaliId > 0) {
        $st->bind_param('iii', $periodId, $mainAgencyId, $selectedTaliId);
    } else {
        $st->bind_param('ii', $periodId, $mainAgencyId);
    }
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        // Daha once atanmis mi?
        if (isset($assignedRowIds[(int)$r['id']])) continue;
        $assignableRows[] = $r;
    }
    $st->close();
}

/* =========================================================
   Mevcut atamalar
========================================================= */
$existingAssignments = [];
if ($periodId) {
    $st = $conn->prepare("SELECT a.id, a.tali_acente_id, a.assigned_at, a.status, 
                                 a.summary_satis, a.summary_iptal, a.summary_zeyil, a.summary_hakedis,
                                 (SELECT COUNT(*) FROM mutabakat_v2_assignment_rows ar WHERE ar.assignment_id = a.id) AS row_count
                          FROM mutabakat_v2_assignments a
                          WHERE a.period_id = ?
                          ORDER BY a.id DESC LIMIT 100");
    if ($st) {
        $st->bind_param('i', $periodId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $existingAssignments[] = $r;
        }
        $st->close();
    }
}

$pageTitle = 'Mutabakat - Atama';
$currentPage = 'atama';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="container">
  <!-- Baslik -->
  <div class="card">
    <div class="card-header">
      <div>
        <h1 class="card-title">Mutabakat - Atama</h1>
        <div class="card-subtitle">Eslesen satirlari tali acentelere atama</div>
      </div>

      <form method="get" style="display:flex; align-items:center; gap:10px;">
        <label style="font-size:13px; opacity:.75;">Donem</label>
        <select name="period_id" onchange="this.form.submit()" class="form-control">
          <?php if (empty($periods)): ?>
            <option value="0">Donem yok</option>
          <?php else: ?>
            <?php foreach ($periods as $p):
              $pid = (int)$p['id'];
              $ym  = sprintf('%04d-%02d', (int)$p['year'], (int)$p['month']);
            ?>
              <option value="<?= $pid ?>" <?= $pid === $periodId ? 'selected' : '' ?>><?= h($ym) ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>

        <?php if (!empty($taliAcenteler)): ?>
          <label style="font-size:13px; opacity:.75;">Tali Acente</label>
          <select name="tali_id" onchange="this.form.submit()" class="form-control">
            <option value="0">Tumunu Goster</option>
            <?php foreach ($taliAcenteler as $ta): ?>
              <option value="<?= $ta['id'] ?>" <?= $ta['id'] === $selectedTaliId ? 'selected' : '' ?>><?= h($ta['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </form>
    </div>

    <?php if ($flashErr): ?>
      <div class="alert alert-error"><?= h($flashErr) ?></div>
    <?php endif; ?>
    <?php if ($flashOk): ?>
      <div class="alert alert-success"><?= h($flashOk) ?></div>
    <?php endif; ?>
  </div>

  <!-- Mevcut Atamalar -->
  <?php if (!empty($existingAssignments)): ?>
  <div class="card" style="margin-top:14px;">
    <div style="font-weight:900; margin-bottom:12px;">Mevcut Atamalar</div>
    <div class="table-wrapper">
      <table class="excel-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Tali Acente ID</th>
            <th>Atama Tarihi</th>
            <th>Satir Sayisi</th>
            <th style="text-align:right;">Satis</th>
            <th style="text-align:right;">Iptal</th>
            <th style="text-align:right;">Zeyil</th>
            <th style="text-align:right;">Hakedis</th>
            <th>Durum</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($existingAssignments as $ea): ?>
            <tr>
              <td><?= h($ea['id']) ?></td>
              <td><?= h($ea['tali_acente_id']) ?></td>
              <td><?= h($ea['assigned_at']) ?></td>
              <td><?= h($ea['row_count']) ?></td>
              <td style="text-align:right;"><?= _money_span($ea['summary_satis']) ?></td>
              <td style="text-align:right;"><?= _money_span($ea['summary_iptal']) ?></td>
              <td style="text-align:right;"><?= _money_span($ea['summary_zeyil']) ?></td>
              <td style="text-align:right;"><?= _money_span($ea['summary_hakedis']) ?></td>
              <td><span class="badge-status badge-eslesen"><?= h($ea['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Atanabilir Satirlar -->
  <div class="card" style="margin-top:14px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
      <div style="font-weight:900;">Atanabilir Satirlar (<?= count($assignableRows) ?>)</div>
      
      <?php if (!empty($assignableRows)): ?>
      <form method="post" id="assignForm" style="display:flex; gap:10px; align-items:center;">
        <input type="hidden" name="action" value="create_assignment">
        <select name="tali_acente_id" class="form-control" required>
          <option value="">Tali Acente Sec...</option>
          <?php foreach ($taliAcenteler as $ta): ?>
            <option value="<?= $ta['id'] ?>"><?= h($ta['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-success" id="btnAssign" disabled>Secilenleri Ata</button>
        <span id="selectedCount" style="font-size:12px; opacity:.75;">0 secili</span>
      </form>
      <?php endif; ?>
    </div>

    <div class="table-wrapper">
      <table class="excel-table">
        <thead>
          <tr>
            <th style="width:40px;"><input type="checkbox" id="selectAll"></th>
            <th>ID</th>
            <th>Tali ID</th>
            <th>Police No</th>
            <th>Sigortali</th>
            <th>Tip</th>
            <th>Tanzim</th>
            <th style="text-align:right;">Araci Pay</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($assignableRows)): ?>
            <tr><td colspan="8" style="opacity:.7;">Atanabilir satir yok.</td></tr>
          <?php else: ?>
            <?php foreach ($assignableRows as $r): ?>
              <tr>
                <td><input type="checkbox" name="row_ids[]" value="<?= h($r['id']) ?>" class="row-checkbox" form="assignForm"></td>
                <td><?= h($r['id']) ?></td>
                <td><?= h($r['tali_acente_id'] ?? '-') ?></td>
                <td><?= h($r['policy_no']) ?></td>
                <td><?= h($r['sigortali_adi']) ?></td>
                <td><?= h($r['txn_type']) ?></td>
                <td><?= h($r['tanzim_tarihi']) ?></td>
                <td style="text-align:right;"><?= _money_span($r['araci_kom_payi']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function(){
  var selectAll = document.getElementById('selectAll');
  var checkboxes = document.querySelectorAll('.row-checkbox');
  var btnAssign = document.getElementById('btnAssign');
  var countSpan = document.getElementById('selectedCount');

  function updateState() {
    var checked = document.querySelectorAll('.row-checkbox:checked').length;
    if (btnAssign) btnAssign.disabled = checked === 0;
    if (countSpan) countSpan.textContent = checked + ' secili';
  }

  if (selectAll) {
    selectAll.addEventListener('change', function() {
      checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
      updateState();
    });
  }

  checkboxes.forEach(function(cb) {
    cb.addEventListener('change', updateState);
  });

  updateState();
})();
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
