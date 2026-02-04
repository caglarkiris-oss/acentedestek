<?php
// /platform/mutabakat/atama.php
// Mutabakat V2 - Atama ekrani (MAIN: atama yap, TALI: onayla/reddet)

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

$mainAgencyId = $isMain ? $agencyId : get_main_agency_id($conn, $agencyId);
$taliAgencyId = $isTali ? $agencyId : 0;

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
   Tali acente listesi (sadece MAIN için)
========================================================= */
$taliAcenteler = [];
if ($isMain) {
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
}

$selectedTaliId = (int)($_GET['tali_id'] ?? 0);

/* =========================================================
   POST: Actions
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    /* ---------- MAIN: Atama oluştur ---------- */
    if ($action === 'create_assignment' && $isMain) {
        $taliId = (int)($_POST['tali_acente_id'] ?? 0);
        $rowIds = $_POST['row_ids'] ?? [];
        $notes  = trim((string)($_POST['notes'] ?? ''));
        
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

                // Assignment - BEKLEMEDE olarak oluştur
                $status = 'BEKLEMEDE';
                $notesDb = $notes === '' ? null : $notes;
                $stA = $conn->prepare("INSERT INTO mutabakat_v2_assignments 
                    (period_id, tali_acente_id, assigned_by, assigned_at, status, summary_satis, summary_iptal, summary_zeyil, summary_hakedis, notes, created_at)
                    VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, NOW())");
                $stA->bind_param('iiisdddds', $periodId, $taliId, $userId, $status, $summarySatis, $summaryIptal, $summaryZeyil, $summaryHakedis, $notesDb);
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
                $flashOk = "Atama olusturuldu ve ONAY BEKLIYOR. " . count($validRows) . " satir. Hakedis: " . _fmt_tr_money($summaryHakedis) . " TRY";
            } catch (Throwable $e) {
                $conn->rollback();
                $flashErr = "Atama hatasi: " . $e->getMessage();
            }
        }
    }

    /* ---------- TALI: Onayla ---------- */
    if ($action === 'approve_assignment' && $isTali) {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $taliNotes = trim((string)($_POST['tali_notes'] ?? ''));

        if ($assignmentId <= 0) {
            $flashErr = 'Gecersiz atama.';
        } else {
            $conn->begin_transaction();
            try {
                // Yetki kontrolü
                $st = $conn->prepare("SELECT id, status FROM mutabakat_v2_assignments WHERE id=? AND tali_acente_id=? AND status='BEKLEMEDE' LIMIT 1");
                $st->bind_param('ii', $assignmentId, $agencyId);
                $st->execute();
                $st->store_result();
                if ($st->num_rows === 0) {
                    $st->close();
                    throw new Exception('Atama bulunamadi veya onaylanamaz durumda.');
                }
                $st->close();

                // Onayla
                $taliNotesDb = $taliNotes === '' ? null : $taliNotes;
                $st = $conn->prepare("UPDATE mutabakat_v2_assignments SET status='ONAYLANDI', approved_by=?, approved_at=NOW(), tali_notes=?, updated_at=NOW() WHERE id=?");
                $st->bind_param('isi', $userId, $taliNotesDb, $assignmentId);
                $st->execute();
                $st->close();

                $conn->commit();
                $flashOk = "Atama ONAYLANDI.";
            } catch (Throwable $e) {
                $conn->rollback();
                $flashErr = "Onay hatasi: " . $e->getMessage();
            }
        }
    }

    /* ---------- TALI: Reddet ---------- */
    if ($action === 'reject_assignment' && $isTali) {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $rejectionReason = trim((string)($_POST['rejection_reason'] ?? ''));

        if ($assignmentId <= 0) {
            $flashErr = 'Gecersiz atama.';
        } elseif ($rejectionReason === '') {
            $flashErr = 'Red sebebi girilmelidir.';
        } else {
            $conn->begin_transaction();
            try {
                // Yetki kontrolü
                $st = $conn->prepare("SELECT id, status FROM mutabakat_v2_assignments WHERE id=? AND tali_acente_id=? AND status='BEKLEMEDE' LIMIT 1");
                $st->bind_param('ii', $assignmentId, $agencyId);
                $st->execute();
                $st->store_result();
                if ($st->num_rows === 0) {
                    $st->close();
                    throw new Exception('Atama bulunamadi veya reddedilemez durumda.');
                }
                $st->close();

                // Reddet
                $st = $conn->prepare("UPDATE mutabakat_v2_assignments SET status='REDDEDILDI', approved_by=?, approved_at=NOW(), rejection_reason=?, updated_at=NOW() WHERE id=?");
                $st->bind_param('isi', $userId, $rejectionReason, $assignmentId);
                $st->execute();
                $st->close();

                $conn->commit();
                $flashOk = "Atama REDDEDILDI.";
            } catch (Throwable $e) {
                $conn->rollback();
                $flashErr = "Red hatasi: " . $e->getMessage();
            }
        }
    }

    /* ---------- TALI: Itiraz ---------- */
    if ($action === 'dispute_assignment' && $isTali) {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $disputeReason = trim((string)($_POST['dispute_reason'] ?? ''));

        if ($assignmentId <= 0) {
            $flashErr = 'Gecersiz atama.';
        } elseif ($disputeReason === '') {
            $flashErr = 'Itiraz sebebi girilmelidir.';
        } else {
            $conn->begin_transaction();
            try {
                // Yetki kontrolü
                $st = $conn->prepare("SELECT id, status FROM mutabakat_v2_assignments WHERE id=? AND tali_acente_id=? AND status='BEKLEMEDE' LIMIT 1");
                $st->bind_param('ii', $assignmentId, $agencyId);
                $st->execute();
                $st->store_result();
                if ($st->num_rows === 0) {
                    $st->close();
                    throw new Exception('Atama bulunamadi veya itiraz edilemez durumda.');
                }
                $st->close();

                // Itiraz durumuna al
                $st = $conn->prepare("UPDATE mutabakat_v2_assignments SET status='ITIRAZDA', tali_notes=?, updated_at=NOW() WHERE id=?");
                $st->bind_param('si', $disputeReason, $assignmentId);
                $st->execute();
                $st->close();

                $conn->commit();
                $flashOk = "Atama ITIRAZDA durumuna alindi. Ana acente inceleyecek.";
            } catch (Throwable $e) {
                $conn->rollback();
                $flashErr = "Itiraz hatasi: " . $e->getMessage();
            }
        }
    }
}

/* =========================================================
   MAIN: Atanabilir satirlar (ESLESEN, henuz atanmamis)
========================================================= */
$assignableRows = [];
if ($periodId && $isMain) {
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
        if (isset($assignedRowIds[(int)$r['id']])) continue;
        $assignableRows[] = $r;
    }
    $st->close();
}

/* =========================================================
   TALI: Bekleyen atamalar (onay bekleyen)
========================================================= */
$pendingAssignments = [];
if ($periodId && $isTali) {
    $st = $conn->prepare("SELECT a.id, a.assigned_at, a.status, 
                                 a.summary_satis, a.summary_iptal, a.summary_zeyil, a.summary_hakedis, a.notes,
                                 (SELECT COUNT(*) FROM mutabakat_v2_assignment_rows ar WHERE ar.assignment_id = a.id) AS row_count
                          FROM mutabakat_v2_assignments a
                          WHERE a.period_id = ? AND a.tali_acente_id = ? AND a.status = 'BEKLEMEDE'
                          ORDER BY a.id DESC");
    if ($st) {
        $st->bind_param('ii', $periodId, $agencyId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $pendingAssignments[] = $r;
        }
        $st->close();
    }
}

/* =========================================================
   Tüm atamalar (MAIN ve TALI için)
========================================================= */
$allAssignments = [];
if ($periodId) {
    $query = "SELECT a.id, a.tali_acente_id, a.assigned_at, a.approved_at, a.status, 
                     a.summary_satis, a.summary_iptal, a.summary_zeyil, a.summary_hakedis,
                     a.notes, a.rejection_reason, a.tali_notes,
                     (SELECT COUNT(*) FROM mutabakat_v2_assignment_rows ar WHERE ar.assignment_id = a.id) AS row_count,
                     ag.name AS tali_name
              FROM mutabakat_v2_assignments a
              LEFT JOIN agencies ag ON ag.id = a.tali_acente_id
              WHERE a.period_id = ?";
    
    if ($isTali) {
        $query .= " AND a.tali_acente_id = ?";
    }
    $query .= " ORDER BY a.id DESC LIMIT 100";

    $st = $conn->prepare($query);
    if ($isTali) {
        $st->bind_param('ii', $periodId, $agencyId);
    } else {
        $st->bind_param('i', $periodId);
    }
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $allAssignments[] = $r;
    }
    $st->close();
}

/* =========================================================
   Atama detay (modal için)
========================================================= */
$assignmentDetail = null;
$assignmentRows = [];
$viewAssignmentId = (int)($_GET['view'] ?? 0);
if ($viewAssignmentId > 0) {
    $st = $conn->prepare("SELECT a.*, ag.name AS tali_name
                          FROM mutabakat_v2_assignments a
                          LEFT JOIN agencies ag ON ag.id = a.tali_acente_id
                          WHERE a.id = ? LIMIT 1");
    $st->bind_param('i', $viewAssignmentId);
    $st->execute();
    $res = $st->get_result();
    if ($res && ($r = $res->fetch_assoc())) {
        // Yetki kontrolü
        if ($isMain || ($isTali && (int)$r['tali_acente_id'] === $agencyId)) {
            $assignmentDetail = $r;
        }
    }
    $st->close();

    if ($assignmentDetail) {
        $st = $conn->prepare("SELECT r.id, r.policy_no, r.sigortali_adi, r.txn_type, r.tanzim_tarihi, r.araci_kom_payi
                              FROM mutabakat_v2_assignment_rows ar
                              JOIN mutabakat_v2_rows r ON r.id = ar.row_id
                              WHERE ar.assignment_id = ?
                              ORDER BY r.id");
        $st->bind_param('i', $viewAssignmentId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $assignmentRows[] = $r;
        }
        $st->close();
    }
}

$pageTitle = 'Mutabakat - Atama';
$currentPage = 'atama';
require_once __DIR__ . '/../layout/header.php';
?>

<style>
.status-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}
.status-beklemede { background: rgba(245,158,11,.15); color: #92400e; }
.status-onaylandi { background: rgba(16,185,129,.15); color: #065f46; }
.status-reddedildi { background: rgba(239,68,68,.15); color: #9f1239; }
.status-itirazda { background: rgba(139,92,246,.15); color: #5b21b6; }

.action-btn-group { display: flex; gap: 8px; flex-wrap: wrap; }

.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 1000;
  display: none;
  align-items: center;
  justify-content: center;
}
.modal-overlay.show { display: flex; }

.modal-content {
  background: #fff;
  border-radius: 16px;
  max-width: 700px;
  width: 95%;
  max-height: 85vh;
  overflow-y: auto;
  padding: 24px;
  box-shadow: 0 20px 50px rgba(0,0,0,.15);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.modal-title { font-size: 18px; font-weight: 800; }

.modal-close {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  opacity: .6;
}
.modal-close:hover { opacity: 1; }

.detail-row {
  display: flex;
  gap: 12px;
  margin-bottom: 8px;
}
.detail-label { font-weight: 700; min-width: 120px; color: #64748b; }
.detail-value { flex: 1; }

textarea.form-control {
  min-height: 80px;
  resize: vertical;
}
</style>

<div class="container">
  <!-- Baslik -->
  <div class="card">
    <div class="card-header">
      <div>
        <h1 class="card-title">Mutabakat - Atama</h1>
        <div class="card-subtitle">
          <?php if ($isMain): ?>
            Eslesen satirlari tali acentelere atama
          <?php else: ?>
            Size atanan kayitlari onaylayin veya reddedin
          <?php endif; ?>
        </div>
      </div>

      <form method="get" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
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

        <?php if ($isMain && !empty($taliAcenteler)): ?>
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

  <!-- TALI: Onay Bekleyen Atamalar -->
  <?php if ($isTali && !empty($pendingAssignments)): ?>
  <div class="card" style="margin-top:14px; border: 2px solid rgba(245,158,11,.35);">
    <div style="font-weight:900; margin-bottom:12px; color:#92400e;">
      ⏳ Onay Bekleyen Atamalar (<?= count($pendingAssignments) ?>)
    </div>
    <div class="table-wrapper">
      <table class="excel-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Atama Tarihi</th>
            <th>Satir Sayisi</th>
            <th style="text-align:right;">Hakedis</th>
            <th>Not</th>
            <th style="width:280px;">Islem</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingAssignments as $pa): ?>
            <tr>
              <td>
                <a href="?period_id=<?= $periodId ?>&view=<?= $pa['id'] ?>" style="font-weight:700;">#<?= h($pa['id']) ?></a>
              </td>
              <td><?= h($pa['assigned_at']) ?></td>
              <td><?= h($pa['row_count']) ?></td>
              <td style="text-align:right;"><?= _money_span($pa['summary_hakedis']) ?></td>
              <td><?= h($pa['notes'] ?? '-') ?></td>
              <td>
                <div class="action-btn-group">
                  <form method="post" style="margin:0;" onsubmit="return confirm('Atamayi onaylamak istediginize emin misiniz?');">
                    <input type="hidden" name="action" value="approve_assignment">
                    <input type="hidden" name="assignment_id" value="<?= $pa['id'] ?>">
                    <button type="submit" class="btn btn-success" style="padding:6px 12px;">Onayla</button>
                  </form>
                  <button type="button" class="btn btn-danger" style="padding:6px 12px;" onclick="showRejectModal(<?= $pa['id'] ?>)">Reddet</button>
                  <button type="button" class="btn" style="padding:6px 12px;" onclick="showDisputeModal(<?= $pa['id'] ?>)">Itiraz</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- MAIN: Atanabilir Satirlar -->
  <?php if ($isMain): ?>
  <div class="card" style="margin-top:14px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
      <div style="font-weight:900;">Atanabilir Satirlar (<?= count($assignableRows) ?>)</div>
      
      <?php if (!empty($assignableRows)): ?>
      <form method="post" id="assignForm" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="action" value="create_assignment">
        <select name="tali_acente_id" class="form-control" required>
          <option value="">Tali Acente Sec...</option>
          <?php foreach ($taliAcenteler as $ta): ?>
            <option value="<?= $ta['id'] ?>"><?= h($ta['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="notes" class="form-control" placeholder="Not (opsiyonel)" style="width:200px;">
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
  <?php endif; ?>

  <!-- Tüm Atamalar -->
  <div class="card" style="margin-top:14px;">
    <div style="font-weight:900; margin-bottom:12px;">Tum Atamalar</div>
    <div class="table-wrapper">
      <table class="excel-table">
        <thead>
          <tr>
            <th>ID</th>
            <?php if ($isMain): ?><th>Tali Acente</th><?php endif; ?>
            <th>Atama Tarihi</th>
            <th>Onay Tarihi</th>
            <th>Satir</th>
            <th style="text-align:right;">Satis</th>
            <th style="text-align:right;">Iptal</th>
            <th style="text-align:right;">Zeyil</th>
            <th style="text-align:right;">Hakedis</th>
            <th>Durum</th>
            <th>Detay</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allAssignments)): ?>
            <tr><td colspan="<?= $isMain ? 11 : 10 ?>" style="opacity:.7;">Atama yok.</td></tr>
          <?php else: ?>
            <?php foreach ($allAssignments as $a): 
              $statusClass = 'status-beklemede';
              if ($a['status'] === 'ONAYLANDI') $statusClass = 'status-onaylandi';
              elseif ($a['status'] === 'REDDEDILDI') $statusClass = 'status-reddedildi';
              elseif ($a['status'] === 'ITIRAZDA') $statusClass = 'status-itirazda';
            ?>
              <tr>
                <td>#<?= h($a['id']) ?></td>
                <?php if ($isMain): ?><td><?= h($a['tali_name'] ?? 'Tali #'.$a['tali_acente_id']) ?></td><?php endif; ?>
                <td><?= h($a['assigned_at']) ?></td>
                <td><?= h($a['approved_at'] ?? '-') ?></td>
                <td><?= h($a['row_count']) ?></td>
                <td style="text-align:right;"><?= _money_span($a['summary_satis']) ?></td>
                <td style="text-align:right;"><?= _money_span($a['summary_iptal']) ?></td>
                <td style="text-align:right;"><?= _money_span($a['summary_zeyil']) ?></td>
                <td style="text-align:right;"><?= _money_span($a['summary_hakedis']) ?></td>
                <td><span class="status-badge <?= $statusClass ?>"><?= h($a['status']) ?></span></td>
                <td>
                  <a href="?period_id=<?= $periodId ?>&view=<?= $a['id'] ?>" class="btn" style="padding:4px 10px;">Goster</a>
                </td>
              </tr>
              <?php if (!empty($a['rejection_reason'])): ?>
              <tr style="background:rgba(239,68,68,.05);">
                <td colspan="<?= $isMain ? 11 : 10 ?>" style="font-size:12px; color:#9f1239;">
                  <strong>Red Sebebi:</strong> <?= h($a['rejection_reason']) ?>
                </td>
              </tr>
              <?php endif; ?>
              <?php if (!empty($a['tali_notes']) && $a['status'] === 'ITIRAZDA'): ?>
              <tr style="background:rgba(139,92,246,.05);">
                <td colspan="<?= $isMain ? 11 : 10 ?>" style="font-size:12px; color:#5b21b6;">
                  <strong>Itiraz Notu:</strong> <?= h($a['tali_notes']) ?>
                </td>
              </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Reddet Modal -->
<div id="rejectModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-header">
      <div class="modal-title">Atamayi Reddet</div>
      <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="reject_assignment">
      <input type="hidden" name="assignment_id" id="rejectAssignmentId" value="">
      <div style="margin-bottom:16px;">
        <label class="form-label">Red Sebebi (zorunlu)</label>
        <textarea name="rejection_reason" class="form-control" required placeholder="Neden reddediyorsunuz?"></textarea>
      </div>
      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" class="btn" onclick="closeModal('rejectModal')">Iptal</button>
        <button type="submit" class="btn btn-danger">Reddet</button>
      </div>
    </form>
  </div>
</div>

<!-- Itiraz Modal -->
<div id="disputeModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-header">
      <div class="modal-title">Itiraz Et</div>
      <button class="modal-close" onclick="closeModal('disputeModal')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="dispute_assignment">
      <input type="hidden" name="assignment_id" id="disputeAssignmentId" value="">
      <div style="margin-bottom:16px;">
        <label class="form-label">Itiraz Sebebi (zorunlu)</label>
        <textarea name="dispute_reason" class="form-control" required placeholder="Neye itiraz ediyorsunuz? Ana acente inceleyecek."></textarea>
      </div>
      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" class="btn" onclick="closeModal('disputeModal')">Iptal</button>
        <button type="submit" class="btn" style="background:rgba(139,92,246,.15); color:#5b21b6;">Itiraz Et</button>
      </div>
    </form>
  </div>
</div>

<!-- Atama Detay Modal -->
<?php if ($assignmentDetail): ?>
<div id="detailModal" class="modal-overlay show">
  <div class="modal-content">
    <div class="modal-header">
      <div class="modal-title">Atama Detayi #<?= h($assignmentDetail['id']) ?></div>
      <a href="?period_id=<?= $periodId ?>" class="modal-close">&times;</a>
    </div>

    <div style="margin-bottom:20px;">
      <div class="detail-row">
        <div class="detail-label">Tali Acente:</div>
        <div class="detail-value"><?= h($assignmentDetail['tali_name'] ?? 'Tali #'.$assignmentDetail['tali_acente_id']) ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Atama Tarihi:</div>
        <div class="detail-value"><?= h($assignmentDetail['assigned_at']) ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Durum:</div>
        <div class="detail-value">
          <?php 
            $statusClass = 'status-beklemede';
            if ($assignmentDetail['status'] === 'ONAYLANDI') $statusClass = 'status-onaylandi';
            elseif ($assignmentDetail['status'] === 'REDDEDILDI') $statusClass = 'status-reddedildi';
            elseif ($assignmentDetail['status'] === 'ITIRAZDA') $statusClass = 'status-itirazda';
          ?>
          <span class="status-badge <?= $statusClass ?>"><?= h($assignmentDetail['status']) ?></span>
        </div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Hakedis:</div>
        <div class="detail-value" style="font-weight:700;"><?= _money_span($assignmentDetail['summary_hakedis']) ?> TRY</div>
      </div>
      <?php if (!empty($assignmentDetail['notes'])): ?>
      <div class="detail-row">
        <div class="detail-label">Not:</div>
        <div class="detail-value"><?= h($assignmentDetail['notes']) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($assignmentDetail['rejection_reason'])): ?>
      <div class="detail-row" style="background:rgba(239,68,68,.08); padding:8px; border-radius:8px;">
        <div class="detail-label" style="color:#9f1239;">Red Sebebi:</div>
        <div class="detail-value" style="color:#9f1239;"><?= h($assignmentDetail['rejection_reason']) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($assignmentDetail['tali_notes'])): ?>
      <div class="detail-row" style="background:rgba(139,92,246,.08); padding:8px; border-radius:8px;">
        <div class="detail-label" style="color:#5b21b6;">Tali Notu:</div>
        <div class="detail-value" style="color:#5b21b6;"><?= h($assignmentDetail['tali_notes']) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <div style="font-weight:800; margin-bottom:12px;">Atanan Satirlar (<?= count($assignmentRows) ?>)</div>
    <div class="table-wrapper" style="max-height:300px; overflow-y:auto;">
      <table class="excel-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Police No</th>
            <th>Sigortali</th>
            <th>Tip</th>
            <th>Tanzim</th>
            <th style="text-align:right;">Araci Pay</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($assignmentRows as $ar): ?>
            <tr>
              <td><?= h($ar['id']) ?></td>
              <td><?= h($ar['policy_no']) ?></td>
              <td><?= h($ar['sigortali_adi']) ?></td>
              <td><?= h($ar['txn_type']) ?></td>
              <td><?= h($ar['tanzim_tarihi']) ?></td>
              <td style="text-align:right;"><?= _money_span($ar['araci_kom_payi']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($isTali && $assignmentDetail['status'] === 'BEKLEMEDE'): ?>
    <div style="margin-top:20px; padding-top:16px; border-top:1px solid rgba(15,23,42,.1); display:flex; gap:10px; justify-content:flex-end;">
      <form method="post" style="margin:0;" onsubmit="return confirm('Atamayi onaylamak istediginize emin misiniz?');">
        <input type="hidden" name="action" value="approve_assignment">
        <input type="hidden" name="assignment_id" value="<?= $assignmentDetail['id'] ?>">
        <button type="submit" class="btn btn-success">Onayla</button>
      </form>
      <button type="button" class="btn btn-danger" onclick="showRejectModal(<?= $assignmentDetail['id'] ?>); closeModal('detailModal');">Reddet</button>
      <button type="button" class="btn" onclick="showDisputeModal(<?= $assignmentDetail['id'] ?>); closeModal('detailModal');">Itiraz</button>
    </div>
    <?php endif; ?>

    <div style="margin-top:16px; text-align:right;">
      <a href="?period_id=<?= $periodId ?>" class="btn">Kapat</a>
    </div>
  </div>
</div>
<?php endif; ?>

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

function showRejectModal(id) {
  document.getElementById('rejectAssignmentId').value = id;
  document.getElementById('rejectModal').classList.add('show');
}

function showDisputeModal(id) {
  document.getElementById('disputeAssignmentId').value = id;
  document.getElementById('disputeModal').classList.add('show');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('show');
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(m){
  m.addEventListener('click', function(e){
    if (e.target === m) m.classList.remove('show');
  });
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
