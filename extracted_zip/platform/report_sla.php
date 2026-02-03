<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/* AUTH */
if (function_exists('require_login')) {
  require_login();
} else {
  if (empty($_SESSION['user_id'])) {
    header("Location: ".base_url("login.php"));
    exit;
  }
}

$role     = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

$allowed = ['ACENTE_YETKILISI','PERSONEL'];
if (!in_array($role, $allowed, true)) {
  http_response_code(403);
  exit("Bu raporu sadece ana acente görebilir.");
}

$conn = db();
$conn->set_charset((string)config('db.charset','utf8mb4'));

/* SLA QUERY */
$q = $conn->prepare("
  SELECT
    u.id              AS user_id,
    u.name            AS user_name,
    COUNT(t.id)       AS ticket_sayisi,
    ROUND(
      AVG(TIMESTAMPDIFF(SECOND, t.created_at, t.first_response_at)) / 60,
      2
    ) AS avg_first_response_min
  FROM tickets t
  JOIN users u ON u.id = t.first_response_by
  WHERE
    t.target_agency_id = ?
    AND t.first_response_at IS NOT NULL
    AND t.first_response_by IS NOT NULL
    AND t.first_response_by <> 0
  GROUP BY t.first_response_by
  ORDER BY avg_first_response_min ASC
");
$q->bind_param("i", $agencyId);
$q->execute();
$res = $q->get_result();

require_once __DIR__ . '/layout/header.php';
?>

<main class="page">
  <div class="card">
    <h2 class="card-title">SLA / İlk Yanıt Süresi</h2>
    <div class="card-sub">Ana acente personel performansı</div>

    <div class="hr"></div>

    <table class="table">
      <thead>
        <tr>
          <th>Personel</th>
          <th>Ticket Sayısı</th>
          <th>Ort. İlk Yanıt (dk)</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($res->num_rows === 0): ?>
          <tr>
            <td colspan="3" class="muted">Veri yok</td>
          </tr>
        <?php else: ?>
          <?php while ($r = $res->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($r['user_name'] ?? ('#'.$r['user_id'])) ?></td>
              <td><?= (int)$r['ticket_sayisi'] ?></td>
              <td><?= htmlspecialchars($r['avg_first_response_min']) ?></td>
            </tr>
          <?php endwhile; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div style="margin-top:16px">
      <a href="<?= base_url('reports.php') ?>" class="btn">← Raporlara Dön</a>
    </div>
  </div>
</main>

</body>
</html>
