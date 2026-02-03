<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

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

/* ✅ KRİTİK: mysqli hatalarını exception yap (agency_companies niye yazmıyor sorunu burada çözülür) */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ---------- USER ---------- */
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

if ($agencyId <= 0) {
  http_response_code(403);
  exit("Acente bilgisi yok");
}

/* ✅ Senin kararın: personel de düzenleyebilir */
$canEdit = in_array($userRole, ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'], true);

/* ---------- ACENTE BİLGİSİ ---------- */
$agencyName = '';
$parentId   = 0;

$st = $conn->prepare("SELECT parent_id, name FROM agencies WHERE id=? LIMIT 1");
$st->bind_param("i", $agencyId);
$st->execute();
$st->bind_result($parentId, $agencyName);
$st->fetch();
$st->close();

$isAnaAcente  = ((int)$parentId === 0);
$agencyTypeTx = $isAnaAcente ? "Ana Acente" : "Tali Acente";

/* ✅ Artık Tali acenteyi engellemiyoruz — aynı profil sayfası ikisine de açık */

/* ---------- PROFİL (GET) ---------- */
$profile = [
  'title' => '',
  'phone' => '',
  'email' => '',
  'website' => '',
  'city' => '',
  'district' => '',
  'address' => '',
  'tax_office' => '',
  'tax_no' => '',
  'logo_path' => '',
];

$st = $conn->prepare("
  SELECT title, phone, email, website, city, district, address, tax_office, tax_no, logo_path
  FROM agency_profiles
  WHERE agency_id=?
  LIMIT 1
");
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
if (!$st->fetch()) {
  // kayıt yoksa boş kalsın (ilk giriş)
}
$st->close();

/* ---------- ŞİRKETLER (GET) ---------- */
$companies = [];
$res = $conn->query("SELECT id, name FROM insurance_companies WHERE is_active=1 ORDER BY name ASC");
while ($r = $res->fetch_assoc()) $companies[] = $r;

/* ---------- SEÇİLİ ŞİRKETLER (GET) ---------- */
$selected = [];
$st = $conn->prepare("SELECT company_id FROM agency_companies WHERE agency_id=?");
$st->bind_param("i", $agencyId);
$st->execute();
$st->bind_result($cid);
while ($st->fetch()) $selected[] = (int)$cid;
$st->close();

/* ---------- POST ---------- */
$saved = false;
$errMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {

  $f_title      = trim((string)($_POST['title'] ?? ''));
  $f_phone      = trim((string)($_POST['phone'] ?? ''));
  $f_email      = trim((string)($_POST['email'] ?? ''));
  $f_website    = trim((string)($_POST['website'] ?? ''));
  $f_city       = trim((string)($_POST['city'] ?? ''));
  $f_district   = trim((string)($_POST['district'] ?? ''));
  $f_address    = trim((string)($_POST['address'] ?? ''));
  $f_tax_office = trim((string)($_POST['tax_office'] ?? ''));
  $f_tax_no     = trim((string)($_POST['tax_no'] ?? ''));

  $checked = $_POST['companies'] ?? [];
  $checked = is_array($checked) ? $checked : [];

  $conn->begin_transaction();

  try {
    // 1) agency_profiles UPSERT
    $st = $conn->prepare("
      INSERT INTO agency_profiles
        (agency_id, title, phone, email, website, city, district, address, tax_office, tax_no)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        title=VALUES(title),
        phone=VALUES(phone),
        email=VALUES(email),
        website=VALUES(website),
        city=VALUES(city),
        district=VALUES(district),
        address=VALUES(address),
        tax_office=VALUES(tax_office),
        tax_no=VALUES(tax_no)
    ");
    $st->bind_param(
      "isssssssss",
      $agencyId,
      $f_title, $f_phone, $f_email, $f_website,
      $f_city, $f_district, $f_address, $f_tax_office, $f_tax_no
    );
    $st->execute();
    $st->close();

    // 2) agency_companies reset
    $st = $conn->prepare("DELETE FROM agency_companies WHERE agency_id=?");
    $st->bind_param("i", $agencyId);
    $st->execute();
    $st->close();

    // 3) agency_companies insert (✅ daha sağlam: bind bir kere, loop’ta sadece değişkeni değiştir)
    if (!empty($checked)) {
      $ins = $conn->prepare("INSERT INTO agency_companies (agency_id, company_id) VALUES (?, ?)");
      $aid = $agencyId;
      $companyId = 0;
      $ins->bind_param("ii", $aid, $companyId);

      foreach ($checked as $x) {
        $companyId = (int)$x;
        if ($companyId <= 0) continue;
        $ins->execute();
      }
      $ins->close();
    }

    $conn->commit();
    $saved = true;

    // ekrana basılan verileri güncelle
    $profile['title'] = $f_title;
    $profile['phone'] = $f_phone;
    $profile['email'] = $f_email;
    $profile['website'] = $f_website;
    $profile['city'] = $f_city;
    $profile['district'] = $f_district;
    $profile['address'] = $f_address;
    $profile['tax_office'] = $f_tax_office;
    $profile['tax_no'] = $f_tax_no;

    $selected = array_map('intval', $checked);

  } catch (Throwable $e) {
    $conn->rollback();
    // ✅ artık gerçek hatayı göreceksin
    $errMsg = "Şirket seçimleri kaydedilirken hata: " . $e->getMessage();
  }
}

require_once __DIR__ . '/layout/header.php';
?>

<main class="page">
  <section class="card">

    <h2 class="card-title">Acente Profili</h2>
    <p class="card-sub">
      <b><?= e($agencyName) ?></b>
      <span class="badge info" style="margin-left:8px;"><?= e($agencyTypeTx) ?></span><br>
      Kurumsal bilgilerinizi güncelleyin ve çalıştığınız sigorta şirketlerini seçin.
    </p>

    <?php if ($saved): ?>
      <div class="alert ok">Profil güncellendi.</div>
    <?php endif; ?>

    <?php if (!empty($errMsg)): ?>
      <div class="alert err"><?= e($errMsg) ?></div>
    <?php endif; ?>

    <form method="post">

      <div class="hr"></div>
      <h3 style="margin:0 0 8px;font-size:14px;font-weight:900;">Kurumsal Bilgiler</h3>

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

        <div class="field" style="grid-column:1/-1">
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
      <h3 style="margin:0 0 8px;font-size:14px;font-weight:900;">Çalışılan Sigorta Şirketleri</h3>

      <?php if (empty($companies)): ?>
        <div class="alert warn">insurance_companies tablosunda aktif şirket yok. (is_active=1)</div>
      <?php endif; ?>

      <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px">
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

      <div style="margin-top:16px">
        <button class="btn primary">Kaydet</button>
      </div>

    </form>

  </section>
</main>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
