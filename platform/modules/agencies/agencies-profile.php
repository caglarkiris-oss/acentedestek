<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db.php';

/* ---------- LOGIN ---------- */
if (function_exists('require_login')) {
  require_login();
} else {
  if (!isset($_SESSION['user_id'])) {
    header("Location: " . base_url("login.php"));
    exit;
  }
}

/* ---------- DB ---------- */
$conn = $GLOBALS['conn'] ?? (function_exists('db') ? db() : null);
if (!$conn) {
  http_response_code(500);
  exit("DB bağlantısı yok");
}

/* ---------- SAFE MYSQLI (hataları yakalayalım) ---------- */
mysqli_report(MYSQLI_REPORT_OFF);

/* ---------- HELPERS ---------- */
function table_exists(mysqli $conn, string $table): bool {
  $dbRes = $conn->query("SELECT DATABASE() AS db");
  $dbRow = $dbRes ? $dbRes->fetch_assoc() : null;
  $db = $dbRow['db'] ?? '';
  if ($db === '') return false;

  $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=? LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("ss", $db, $table);
  $st->execute();
  $st->store_result();
  $ok = ($st->num_rows > 0);
  $st->close();
  return $ok;
}

function post_str(string $key, int $maxLen = 255): string {
  $v = trim((string)($_POST[$key] ?? ''));
  if ($maxLen > 0) $v = mb_substr($v, 0, $maxLen);
  return $v;
}

/* ---------- USER ---------- */
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

if ($agencyId <= 0) {
  http_response_code(403);
  exit("Acente bilgisi yok");
}

/* ---------- ACENTE TİPİ ---------- */
$parentId = 0;
$agencyName = '';

$st = $conn->prepare("SELECT parent_id, name FROM agencies WHERE id=? LIMIT 1");
if (!$st) {
  http_response_code(500);
  exit("SQL PREPARE HATASI (agencies)");
}
$st->bind_param("i", $agencyId);
$st->execute();
$st->bind_result($parentId, $agencyName);
$st->fetch();
$st->close();

$isAnaAcente = ((int)$parentId === 0);

/* ---------- YETKİ (DOĞRU KURAL) ----------
  - Ana acente profili: ACENTE_YETKILISI + PERSONEL
  - Tali acente profili: TALI_ACENTE_YETKILISI + PERSONEL
------------------------------------------ */
$canEdit = false;

if ($isAnaAcente) {
  $canEdit = in_array($userRole, ['ACENTE_YETKILISI','PERSONEL'], true);
} else {
  $canEdit = in_array($userRole, ['TALI_ACENTE_YETKILISI','PERSONEL'], true);
}

if (!$canEdit) {
  http_response_code(403);
  exit("Bu sayfaya erişim yetkiniz yok");
}

/* ---------- TABLO KONTROLLERİ ---------- */
$hasProfiles  = table_exists($conn, 'agency_profiles');
$hasCompanies = table_exists($conn, 'insurance_companies');
$hasPivot     = table_exists($conn, 'agency_companies');

$errors = [];
$warnings = [];
$saved = false;

if (!$hasProfiles)  $errors[]  = "HATA: 'agency_profiles' tablosu mevcut değil.";
if (!$hasCompanies) $warnings[] = "UYARI: 'insurance_companies' tablosu yok (şirket listesi gösterilemez).";
if (!$hasPivot)     $warnings[] = "UYARI: 'agency_companies' tablosu yok (şirket seçimleri kaydedilemez).";

/* ---------- PROFİL VERİSİ (agency_profiles) ---------- */
$profile = [
  'title'      => '',
  'phone'      => '',
  'email'      => '',
  'website'    => '',
  'city'       => '',
  'district'   => '',
  'address'    => '',
  'tax_office' => '',
  'tax_no'     => '',
  'logo_path'  => '',
];

if ($hasProfiles) {
  $st = $conn->prepare("
    SELECT title, phone, email, website, city, district, address, tax_office, tax_no, logo_path
    FROM agency_profiles
    WHERE agency_id=?
    LIMIT 1
  ");
  if ($st) {
    $st->bind_param("i", $agencyId);
    $st->execute();
    $st->bind_result(
      $profile['title'],
      $profile['phone'],
      $profile['email'],
      $profile['website'],
      $profile['city'],
      $profile['district'],
      $profile['address'],
      $profile['tax_office'],
      $profile['tax_no'],
      $profile['logo_path']
    );
    $st->fetch();
    $st->close();
  } else {
    $errors[] = "SQL PREPARE HATASI (agency_profiles select)";
  }
}

/* ---------- ŞİRKETLER LİSTESİ ---------- */
$companies = [];
if ($hasCompanies) {
  $res = $conn->query("SELECT id, name FROM insurance_companies WHERE is_active=1 ORDER BY name ASC");
  if ($res) {
    while ($r = $res->fetch_assoc()) $companies[] = $r;
  } else {
    $warnings[] = "Şirket listesi çekilemedi (insurance_companies).";
  }
}

/* ---------- SEÇİLİ ŞİRKETLER ---------- */
$selected = [];
if ($hasPivot) {
  $st = $conn->prepare("SELECT company_id FROM agency_companies WHERE agency_id=?");
  if ($st) {
    $st->bind_param("i", $agencyId);
    $st->execute();
    $st->bind_result($cid);
    while ($st->fetch()) $selected[] = (int)$cid;
    $st->close();
  } else {
    $warnings[] = "SQL PREPARE HATASI (agency_companies select)";
  }
}

/* ---------- POST (KAYDET) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!$hasProfiles) {
    $errors[] = "Profil kaydedilemedi: agency_profiles tablosu yok.";
  } else {

    // form alanları
    $title      = post_str('title', 190);
    $phone      = post_str('phone', 50);
    $email      = post_str('email', 190);
    $website    = post_str('website', 190);
    $city       = post_str('city', 90);
    $district   = post_str('district', 90);
    $address    = post_str('address', 500);
    $tax_office = post_str('tax_office', 120);
    $tax_no     = post_str('tax_no', 60);

    // şirketler
    $checked = $_POST['companies'] ?? [];
    $checked = is_array($checked) ? array_values($checked) : [];

    // şirket seçimi zorunlu olsun (tablo varsa)
    if ($hasPivot && $hasCompanies) {
      if (count($checked) < 1) {
        $errors[] = "En az 1 sigorta şirketi seçmelisiniz.";
      }
    }

    if (empty($errors)) {
      $conn->begin_transaction();

      try {
        // 1) agency_profiles UPSERT (agency_id unique varsayımıyla)
        // Eğer unique yoksa: önce UPDATE dene, etkilemediyse INSERT at.
        $st = $conn->prepare("
          UPDATE agency_profiles
          SET title=?, phone=?, email=?, website=?, city=?, district=?, address=?, tax_office=?, tax_no=?
          WHERE agency_id=?
        ");
        if (!$st) throw new Exception("SQL PREPARE HATASI (agency_profiles update)");
        $st->bind_param(
          "sssssssssi",
          $title, $phone, $email, $website, $city, $district, $address, $tax_office, $tax_no, $agencyId
        );
        $st->execute();
        $affected = $st->affected_rows;
        $st->close();

        if ($affected === 0) {
          $st = $conn->prepare("
            INSERT INTO agency_profiles (agency_id, title, phone, email, website, city, district, address, tax_office, tax_no)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          ");
          if (!$st) throw new Exception("SQL PREPARE HATASI (agency_profiles insert)");
          $st->bind_param(
            "isssssssss",
            $agencyId, $title, $phone, $email, $website, $city, $district, $address, $tax_office, $tax_no
          );
          $st->execute();
          $st->close();
        }

        // 2) agency_companies (varsa)
        if ($hasPivot && $hasCompanies) {

          $st = $conn->prepare("DELETE FROM agency_companies WHERE agency_id=?");
          if (!$st) throw new Exception("SQL PREPARE HATASI (agency_companies delete)");
          $st->bind_param("i", $agencyId);
          $st->execute();
          $st->close();

          if (!empty($checked)) {
            $st = $conn->prepare("INSERT INTO agency_companies (agency_id, company_id) VALUES (?, ?)");
            if (!$st) throw new Exception("SQL PREPARE HATASI (agency_companies insert)");

            foreach ($checked as $cid) {
              $cid = (int)$cid;
              if ($cid <= 0) continue;
              $st->bind_param("ii", $agencyId, $cid);
              $st->execute();
            }
            $st->close();
          }
        }

        $conn->commit();
        $saved = true;

        // ekranda güncel kalsın
        $profile = [
          'title'      => $title,
          'phone'      => $phone,
          'email'      => $email,
          'website'    => $website,
          'city'       => $city,
          'district'   => $district,
          'address'    => $address,
          'tax_office' => $tax_office,
          'tax_no'     => $tax_no,
          'logo_path'  => $profile['logo_path'] ?? '',
        ];
        $selected = array_map('intval', $checked);

      } catch (Throwable $e) {
        $conn->rollback();
        $errors[] = "Kayıt sırasında hata: " . $e->getMessage();
      }
    }
  }
}

require_once __DIR__ . '/../../layout/header.php';
?>

<section class="page">
  <section class="card">
    <h2 class="card-title">Acente Profili</h2>
    <p class="card-sub">
      <b><?= e($agencyName) ?></b>
      <?php if ($isAnaAcente): ?>
        – Ana Acente
      <?php else: ?>
        – Tali Acente
      <?php endif; ?>
      (Bu sayfayı düzenleyebilirsiniz.)
    </p>

    <?php if (!empty($errors)): ?>
      <div class="alert err">
        <b>Hata:</b><br>
        <?= e(implode(" / ", $errors)) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($warnings)): ?>
      <div class="alert warn">
        <?= e(implode(" / ", $warnings)) ?>
      </div>
    <?php endif; ?>

    <?php if ($saved): ?>
      <div class="alert ok">Profil güncellendi.</div>
    <?php endif; ?>

    <form method="post" novalidate>

      <div class="form">
        <div class="field">
          <div class="label">Ünvan</div>
          <input class="input" name="title" value="<?= e($profile['title']) ?>" placeholder="Örn: ABC Sigorta Aracılık Hiz. Ltd. Şti.">
        </div>

        <div class="field">
          <div class="label">Telefon</div>
          <input class="input" name="phone" value="<?= e($profile['phone']) ?>" placeholder="05xx xxx xx xx">
        </div>

        <div class="field">
          <div class="label">E-posta</div>
          <input class="input" name="email" value="<?= e($profile['email']) ?>" placeholder="ornek@acente.com">
        </div>

        <div class="field">
          <div class="label">Web sitesi</div>
          <input class="input" name="website" value="<?= e($profile['website']) ?>" placeholder="https://...">
        </div>

        <div class="field">
          <div class="label">İl</div>
          <input class="input" name="city" value="<?= e($profile['city']) ?>" placeholder="İstanbul">
        </div>

        <div class="field">
          <div class="label">İlçe</div>
          <input class="input" name="district" value="<?= e($profile['district']) ?>" placeholder="Kadıköy">
        </div>

        <div class="field">
          <div class="label">Adres</div>
          <textarea class="input" name="address" placeholder="Açık adres..."><?= e($profile['address']) ?></textarea>
        </div>

        <div class="field">
          <div class="label">Vergi Dairesi</div>
          <input class="input" name="tax_office" value="<?= e($profile['tax_office']) ?>">
        </div>

        <div class="field">
          <div class="label">Vergi No</div>
          <input class="input" name="tax_no" value="<?= e($profile['tax_no']) ?>">
        </div>
      </div>

      <div class="hr"></div>

      <h3>Çalışılan Sigorta Şirketleri</h3>

      <?php if ($hasCompanies): ?>
        <div class="grid">
          <?php foreach ($companies as $c): ?>
            <label class="check">
              <input
                type="checkbox"
                name="companies[]"
                value="<?= (int)$c['id'] ?>"
                <?= in_array((int)$c['id'], $selected, true) ? 'checked' : '' ?>
              >
              <span><?= e($c['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if (!$hasPivot): ?>
          <div class="small">Not: agency_companies tablosu olmadığı için seçimler kaydedilemez.</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="small">Şirket listesi henüz hazır değil.</div>
      <?php endif; ?>

      <div class="actions">
        <button class="btn primary" type="submit">Kaydet</button>
      </div>

    </form>

  </section>
</section>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
