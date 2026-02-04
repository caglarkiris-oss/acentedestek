<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers.php';

/* Login */
if (function_exists('require_login')) {
  require_login();
} elseif (function_exists('require_auth')) {
  require_auth();
} else {
  if (!isset($_SESSION['user_id'])) {
    header("Location: " . base_url("login.php"));
    exit;
  }
}

/* DB bağlantı */
$conn = function_exists('db') ? db() : ($GLOBALS['conn'] ?? null);
if (!$conn) {
  http_response_code(500);
  exit("DB bağlantısı bulunamadı.");
}
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

$role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
$allowedRoles = ['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI'];
if (!in_array($role, $allowedRoles, true)) {
  http_response_code(403);
  exit("Bu sayfaya erişim yetkiniz yok.");
}

$currentUserId   = (int)($_SESSION['user_id'] ?? 0);
$currentAgencyId = (int)($_SESSION['agency_id'] ?? 0);

/* current agency_id (mysqlnd'siz) */
if ($currentAgencyId <= 0 && $currentUserId > 0) {
  $stmt = $conn->prepare("SELECT agency_id FROM users WHERE id=? LIMIT 1");
  if (!$stmt) { http_response_code(500); exit("DB prepare hatası: ".$conn->error); }
  $stmt->bind_param("i", $currentUserId);
  $stmt->execute();
  $stmt->bind_result($aid);
  $stmt->fetch();
  $stmt->close();

  $currentAgencyId = (int)($aid ?? 0);
  $_SESSION['agency_id'] = $currentAgencyId;
}

/* Rol label */
$roleLabels = [
  'SUPERADMIN'            => 'Süper Admin',
  'ACENTE_YETKILISI'      => 'Acente Yetkilisi',
  'TALI_ACENTE_YETKILISI' => 'Tali Acente Yetkilisi',
  'PERSONEL'              => 'Personel',
];

/**
 * ✅ Bu sayfada hangi rolleri açabilir?
 */
if ($role === 'SUPERADMIN') {
  $creatableRoles = ['SUPERADMIN','ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
} elseif ($role === 'ACENTE_YETKILISI') {
  $creatableRoles = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
} else { // TALI_ACENTE_YETKILISI
  $creatableRoles = ['TALI_ACENTE_YETKILISI','PERSONEL'];
}

/* ✅ Branş sözlüğü (PERSONEL için kullanılacak) */
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

/* SUPERADMIN ise acente listesi */
$agencies = [];
if ($role === 'SUPERADMIN') {
  $res = $conn->query("SELECT id, name FROM agencies ORDER BY name ASC");
  if ($res) {
    while ($a = $res->fetch_assoc()) $agencies[] = $a;
    $res->free();
  }
}

/* ✅ Giriş yapanın acente adını gösterelim (SUPERADMIN olmayanlarda) */
$currentAgencyName = '';
if ($role !== 'SUPERADMIN' && $currentAgencyId > 0) {
  $st = $conn->prepare("SELECT name FROM agencies WHERE id=? LIMIT 1");
  if ($st) {
    $st->bind_param("i", $currentAgencyId);
    $st->execute();
    $st->bind_result($aname);
    $st->fetch();
    $st->close();
    $currentAgencyName = (string)($aname ?? '');
  }
}

function _redirect_to(string $path): void {
  header("Location: " . (function_exists('base_url') ? base_url($path) : $path));
  exit;
}

$errors = [];

/* ✅ SUPERADMIN değilse ve agency_id yoksa: create kapalı */
if ($role !== 'SUPERADMIN' && $currentAgencyId <= 0) {
  $errors[] = "Hesabınız bir acenteye bağlı değil (agency_id boş/0). Önce kullanıcıyı doğru acenteye bağlayın.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
  csrf_verify();

  $name     = trim((string)($_POST['name'] ?? ''));
  $email    = trim((string)($_POST['email'] ?? ''));
  $pass     = (string)($_POST['password'] ?? '');
  $newRole  = strtoupper(trim((string)($_POST['role'] ?? '')));
  $isActive = (int)($_POST['is_active'] ?? 1);

  /**
   * ✅ agencyId kuralı
   */
  if ($role === 'SUPERADMIN') {
    $agencyId = (int)($_POST['agency_id'] ?? 0);
  } else {
    $agencyId = (int)$currentAgencyId;
  }

  if ($name === '') $errors[] = "Ad alanı zorunludur.";
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Geçerli bir email giriniz.";
  if (!in_array($newRole, $creatableRoles, true)) $errors[] = "Bu rolü oluşturma yetkiniz yok.";

  if ($role === 'SUPERADMIN' && $agencyId <= 0) $errors[] = "Acente seçimi zorunludur.";

  // ✅ Geçici şifre: basit olabilir
  if ($pass === '') {
    $errors[] = "Şifre alanı zorunludur.";
  } elseif (strlen($pass) < 4) {
    $errors[] = "Şifre en az 4 karakter olmalı.";
  }

  /* ✅ PERSONEL branşları (DB: user_branch_scopes) */
  $selectedBranches = [];
  if ($newRole === 'PERSONEL') {
    $branches = $_POST['branches'] ?? [];
    $branches = is_array($branches) ? $branches : [];
    $branches = array_values(array_unique(array_filter(array_map('strval', $branches))));

    $allowedBranchKeys = array_keys($BRANCHES);

    $norm = [];
    foreach ($branches as $b) {
      $k = normalize_branch_key((string)$b);
      if ($k !== '') $norm[] = $k;
    }
    $norm = array_values(array_unique($norm));
    $selectedBranches = array_values(array_intersect($norm, $allowedBranchKeys));

    if (count($selectedBranches) < 1) {
      $errors[] = "Personel için en az 1 branş seçmelisin.";
    }
  }

  /* email uniq (mysqlnd'siz) */
  if (empty($errors)) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    if (!$stmt) {
      $errors[] = "DB prepare hatası (email kontrol): " . $conn->error;
    } else {
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $stmt->bind_result($existingId);
      if ($stmt->fetch()) $errors[] = "Bu email zaten kayıtlı.";
      $stmt->close();
    }
  }

  /* INSERT + SCOPES */
  if (empty($errors)) {
    $hash = password_hash($pass, PASSWORD_DEFAULT);

    try {
      $conn->begin_transaction();

      // users insert: branches_json KULLANMIYORUZ (pivot tablo var)
      $stmt = $conn->prepare("
        INSERT INTO users (agency_id, name, email, password, must_change_password, role, is_active, created_at)
        VALUES (?, ?, ?, ?, 1, ?, ?, NOW())
      ");
      if (!$stmt) {
        throw new Exception("DB prepare hatası (insert): " . $conn->error);
      }

      // agency_id(i), name(s), email(s), password(s), role(s), is_active(i)
      $stmt->bind_param("issssi", $agencyId, $name, $email, $hash, $newRole, $isActive);
      $ok = $stmt->execute();
      if (!$ok) {
        $stmt->close();
        throw new Exception("Kayıt oluşturulamadı: " . $stmt->error);
      }
      $newUserId = (int)$conn->insert_id;
      $stmt->close();

      // PERSONEL ise branch scope yaz
      if ($newRole === 'PERSONEL') {
        // Güvenli olsun diye temizle (normalde yeni user ama yine de)
        $del = $conn->prepare("DELETE FROM user_branch_scopes WHERE user_id=?");
        if (!$del) throw new Exception("DB prepare hatası (scope delete): " . $conn->error);
        $del->bind_param("i", $newUserId);
        if (!$del->execute()) { $del->close(); throw new Exception("Scope temizlenemedi: " . $del->error); }
        $del->close();

        $ins = $conn->prepare("INSERT INTO user_branch_scopes (user_id, branch_key) VALUES (?, ?)");
        if (!$ins) throw new Exception("DB prepare hatası (scope insert): " . $conn->error);

        foreach ($selectedBranches as $bk) {
          $bk = normalize_branch_key((string)$bk);
          if ($bk === '') continue;
          $ins->bind_param("is", $newUserId, $bk);
          if (!$ins->execute()) {
            $ins->close();
            throw new Exception("Scope yazılamadı ($bk): " . $conn->error);
          }
        }
        $ins->close();
      }

      $conn->commit();
      _redirect_to("users.php?created=1");

    } catch (Throwable $e) {
      try { $conn->rollback(); } catch (Throwable $__) {}
      $errors[] = (string)$e->getMessage();
    }
  }
}

require_once __DIR__ . '/../../layout/header.php';
$backUrl = base_url("users.php");

/* formda seçili branşları geri basmak için */
$postBranches = $_POST['branches'] ?? [];
$postBranches = is_array($postBranches) ? array_values(array_unique(array_filter(array_map('strval', $postBranches)))) : [];
?>



<section class="page">
  <div class="card">
    <h2 class="card-title">Yeni Kullanıcı Oluştur</h2>
    <div class="card-sub">
      Rolüne göre açabileceğin kullanıcı tipleri otomatik sınırlandırılır. Acenteye bağlama kuralı sistem tarafından uygulanır.
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert err">
        <b>Kaydedilemedi.</b>
        <div class="small">
          <?php foreach ($errors as $e): ?>
            • <?= htmlspecialchars((string)$e) ?><br>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="hr"></div>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>

      <div class="form">
        <div class="field">
          <div class="label">Ad Soyad</div>
          <input class="input" type="text" name="name" value="<?= htmlspecialchars((string)($_POST['name'] ?? '')) ?>" required>
        </div>

        <div class="field">
          <div class="label">Email</div>
          <input class="input" type="email" name="email" value="<?= htmlspecialchars((string)($_POST['email'] ?? '')) ?>" required>
        </div>
      </div>

      <div class="form">
        <div class="field">
          <div class="label">Geçici Şifre</div>
          <input class="input" type="password" name="password" minlength="4" required>
          <div class="help">Geçici şifre (ilk girişte değiştirilir). Min 4 karakter.</div>
        </div>

        <div class="field">
          <div class="label">Rol</div>
          <select class="input" name="role" id="jsRole" required>
            <?php foreach ($creatableRoles as $cr): ?>
              <option value="<?= htmlspecialchars($cr) ?>" <?= (strtoupper(trim((string)($_POST['role'] ?? ''))) === $cr ? 'selected' : '') ?>>
                <?= htmlspecialchars((string)($roleLabels[$cr] ?? $cr)) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="help">Bu liste, giriş yaptığın role göre otomatik kısıtlanır.</div>
        </div>
      </div>

      <!-- ✅ SADECE PERSONEL için branş seçimi -->
      <div class="form one" id="jsBranchesWrap">
        <div class="field">
          <div class="label">Personel Branş Yetkileri</div>
          <div class="branch-box">
            <div class="branch-grid">
              <?php foreach ($BRANCHES as $k => $v): ?>
                <label class="branch-item">
                  <input type="checkbox" name="branches[]" value="<?= htmlspecialchars($k) ?>" <?= in_array($k, $postBranches, true) ? 'checked' : '' ?>>
                  <span><?= htmlspecialchars($v) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="branch-muted">Not: Bu seçim sadece PERSONEL için geçerli. Yetkililer tüm branşları görür.</div>
          </div>
        </div>
      </div>

      <div class="form">
        <?php if ($role === 'SUPERADMIN'): ?>
          <div class="field">
            <div class="label">Acente</div>
            <select class="input" name="agency_id" required>
              <option value="">Seçiniz</option>
              <?php foreach ($agencies as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= ((int)($_POST['agency_id'] ?? 0) === (int)$a['id'] ? 'selected' : '') ?>>
                  <?= htmlspecialchars((string)($a['name'] ?? '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="help">Süper Admin, kullanıcıyı istediği acenteye bağlayabilir.</div>
          </div>
        <?php else: ?>
          <div class="field">
            <div class="label">Acente</div>
            <input class="input" type="text" value="<?= htmlspecialchars($currentAgencyName ?: 'Kendi acenten') ?>" disabled>
            <div class="help">Not: Bu ekranda acente seçimi yok. Oluşturulan kullanıcı otomatik kendi acentenize bağlanır.</div>
          </div>
        <?php endif; ?>

        <div class="field">
          <div class="label">Durum</div>
          <select class="input" name="is_active">
            <option value="1" <?= ((string)($_POST['is_active'] ?? '1') === '1' ? 'selected' : '') ?>>Aktif</option>
            <option value="0" <?= ((string)($_POST['is_active'] ?? '1') === '0' ? 'selected' : '') ?>>Pasif</option>
          </select>
        </div>
      </div>

      <div class="actions">
        <a class="btn" href="<?= htmlspecialchars($backUrl) ?>">Vazgeç</a>
        <button class="btn primary" type="submit">Kaydet</button>
      </div>

    </form>
  </div>
</section>

<script>
(function(){
  var roleSel = document.getElementById('jsRole');
  var wrap = document.getElementById('jsBranchesWrap');
  function sync(){
    if (!roleSel || !wrap) return;
    var v = (roleSel.value || '').toUpperCase();
    wrap.style.display = (v === 'PERSONEL') ? '' : 'none';
  }
  if (roleSel) roleSel.addEventListener('change', sync);
  sync();
})();
</script>

</body>
</html>
