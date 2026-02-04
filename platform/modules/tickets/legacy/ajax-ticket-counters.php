<?php
// /public_html/platform/ajax-ticket-counters.php  ✅ PERSONEL scope FIX (user_branch_scopes)

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_ticket_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
  if (function_exists('require_login')) {
    require_login();
  } else {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) {
      echo json_encode(['ok'=>false,'error'=>'Yetkisiz']);
      exit;
    }
  }

  $conn = db();
  $conn->set_charset((string)config('db.charset', 'utf8mb4'));

  $userId   = (int)($_SESSION['user_id'] ?? 0);
  $userRole = (string)($_SESSION['role'] ?? '');
  $agencyId = (int)($_SESSION['agency_id'] ?? 0);

  $ALLOWED = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
  if ($userId <= 0 || !in_array($userRole, $ALLOWED, true)) {
    echo json_encode(['ok'=>false,'error'=>'Yetkisiz']);
    exit;
  }

  // ✅ Kolon var mı? (dinamik)
  $has_col = function(string $table, string $col) use ($conn): bool {
    $sql = "
      SELECT 1
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
      LIMIT 1
    ";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param("ss", $table, $col);
    $st->execute();
    $st->store_result();
    $ok = ($st->num_rows > 0);
    $st->close();
    return $ok;
  };

  $hasCreatedByAgency = $has_col('tickets','created_by_agency_id');
  $hasTargetAgency    = $has_col('tickets','target_agency_id');
  $hasCreatedByUser   = $has_col('tickets','created_by_user_id');
  $hasBranch          = $has_col('tickets','branch');

  // USER CREATED AT (flag fix)
  $fetch_user_created_at = function(mysqli $conn, int $userId): string {
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
    return date('Y-m-d H:i:s'); // kolon yoksa eski ticketlar unread olmasın
  };
  $userCreatedAt = (string)$fetch_user_created_at($conn, $userId);

  // PERSONEL branş sözlüğü (key list)
  $BRANCHES = [
    'trafik','kasko','dask','konut','isyeri','saglik','tss','seyahat','ferdi_kaza','nakliyat',
    'sorumluluk','muhendislik','hayat','bes','tarim','tekne'
  ];

  $normalize_branch_key = function(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $s = str_replace(['İ','I','ı'], ['i','i','i'], $s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace([' ', '-'], '_', $s);
    $s = preg_replace('~[^a-z0-9_]+~', '', $s);
    return $s ?: '';
  };

  /* =========================================================
     ✅ PERSONEL BRANCH SCOPE FIX
     - Önce: user_branch_scopes (senin db’de var)
     - Fallback: users.branches_json
     - Boşsa: HEPSİNE AÇMA! -> sayaç 0 (güvenli)
  ========================================================= */
  $personelAllowedBranches = [];

  if ($userRole === 'PERSONEL' && $hasBranch) {

    // 1) user_branch_scopes varsa buradan oku
    if ($has_col('user_branch_scopes','user_id') && $has_col('user_branch_scopes','branch_key')) {
      $stx = $conn->prepare("SELECT branch_key FROM user_branch_scopes WHERE user_id=?");
      if ($stx) {
        $stx->bind_param("i", $userId);
        $stx->execute();
        $stx->bind_result($bk);
        $tmp = [];
        while ($stx->fetch()) {
          $k = $normalize_branch_key((string)$bk);
          if ($k !== '') $tmp[] = $k;
        }
        $stx->close();
        $personelAllowedBranches = array_values(array_unique($tmp));
      }
    }

    // 2) fallback: users.branches_json
    if (empty($personelAllowedBranches)) {
      $bj = null;
      $stb = $conn->prepare("SELECT branches_json FROM users WHERE id=? LIMIT 1");
      if ($stb) {
        $stb->bind_param("i", $userId);
        $stb->execute();
        $stb->bind_result($bj);
        $stb->fetch();
        $stb->close();
      }

      $arr = [];
      if (is_string($bj)) {
        $raw = trim($bj);
        if ($raw !== '') {
          $tmp = json_decode($raw, true);
          if (is_array($tmp)) $arr = $tmp;
          else $arr = preg_split('~\s*,\s*~', $raw);
        }
      }

      $norm = [];
      foreach ((array)$arr as $x) {
        if (is_array($x) || is_object($x)) continue;
        $k = $normalize_branch_key((string)$x);
        if ($k !== '') $norm[] = $k;
      }
      $norm = array_values(array_unique($norm));
      $personelAllowedBranches = array_values(array_intersect($norm, $BRANCHES));
    }

    // ✅ EN KRİTİK SATIR: boşsa hepsi yapma!
    // (boşsa personel hiçbir branş görmesin -> sayaç 0)
    if (empty($personelAllowedBranches)) {
      // Burada erken dönmüyoruz; countUnread içinde 1=0 yapacağız.
      $personelAllowedBranches = [];
    }
  }

  // ✅ unread say (satır bazlı SUM) + scope fallback
  $countUnread = function(string $box) use (
    $conn, $userId, $userRole, $agencyId,
    $userCreatedAt,
    $personelAllowedBranches,
    $hasCreatedByAgency, $hasTargetAgency, $hasCreatedByUser, $hasBranch
  ): int {

    $where = [];
    $params = [];
    $types = "";

    // IN scope
    if ($box === 'in') {
      $inParts = [];

      if ($hasTargetAgency && $agencyId > 0) {
        $inParts[] = "t.target_agency_id = ?";
        $params[] = $agencyId; $types .= "i";
      }

      if (empty($inParts)) {
        $where[] = "1=0";
      } else {
        $where[] = "(" . implode(" OR ", $inParts) . ")";
      }
    }

    // OUT scope
    if ($box === 'out') {
      $outParts = [];

      if ($hasCreatedByAgency && $agencyId > 0) {
        $outParts[] = "t.created_by_agency_id = ?";
        $params[] = $agencyId; $types .= "i";
      }

      if ($hasCreatedByUser && $userId > 0) {
        $outParts[] = "t.created_by_user_id = ?";
        $params[] = $userId; $types .= "i";
      }

      if (empty($outParts)) {
        $where[] = "1=0";
      } else {
        $where[] = "(" . implode(" OR ", $outParts) . ")";
      }
    }

    // PERSONEL branch scope
    if ($userRole === 'PERSONEL' && $hasBranch) {
      if (!empty($personelAllowedBranches)) {
        $ph = implode(',', array_fill(0, count($personelAllowedBranches), '?'));
        $where[] = "t.branch IN ($ph)";
        foreach ($personelAllowedBranches as $b) { $params[] = $b; $types .= "s"; }
      } else {
        $where[] = "1=0";
      }
    }

    $whereSql = "WHERE " . implode(" AND ", $where);

    $sql = "
      SELECT
        COALESCE(SUM(
          CASE
            WHEN COALESCE(t.updated_at, t.created_at) > COALESCE(tus.last_seen_at, '1970-01-01 00:00:00')
             AND COALESCE(t.updated_at, t.created_at) > ?
            THEN 1 ELSE 0
          END
        ),0) AS unread_cnt
      FROM tickets t
      LEFT JOIN ticket_user_state tus
        ON tus.ticket_id = t.id AND tus.user_id = ?
      $whereSql
    ";

    $st = $conn->prepare($sql);
    if (!$st) return 0;

    $bindTypes = "si" . $types;
    $bindParams = array_merge([$userCreatedAt, $userId], $params);

    $tmp = [];
    $tmp[] = $bindTypes;
    for ($i=0; $i<count($bindParams); $i++) $tmp[] = &$bindParams[$i];
    call_user_func_array([$st, 'bind_param'], $tmp);

    $cnt = 0;
    $st->execute();
    $st->bind_result($cnt);
    $st->fetch();
    $st->close();

    return (int)$cnt;
  };

  $in  = $countUnread('in');
  $out = $countUnread('out');

  echo json_encode([
    'ok'    => true,
    'total' => (int)($in + $out),
    'in'    => (int)$in,
    'out'   => (int)$out
  ]);

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'Sunucu hatası']);
}
