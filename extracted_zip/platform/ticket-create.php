<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

/* ---- AUTH GUARD ---- */
if (function_exists('require_login')) {
  require_login();
} else {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (!isset($_SESSION['user_id'])) {
    header("Location: " . base_url("login.php"));
    exit;
  }
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? '');
$agencyId = (int)($_SESSION['agency_id'] ?? 0);

/* ---- ROLE CHECK ---- */
$allowedCreate = ['ACENTE_YETKILISI','TALI_ACENTE_YETKILISI','PERSONEL'];
if (!in_array($userRole, $allowedCreate, true)) {
  http_response_code(403);
  exit("Bu sayfaya erişim yetkiniz yok.");
}

/* ---- DB ---- */
$conn = function_exists('db') ? db() : ($GLOBALS['conn'] ?? null);
if (!$conn) {
  http_response_code(500);
  exit("DB bağlantısı kurulamadı.");
}
$conn->set_charset((string)config('db.charset', 'utf8mb4'));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* =========================================================
   ✅ YENİ: Ek veri tablosu (branş alanları + şirket seçimi)
========================================================= */
function ensure_ticket_extras_table(mysqli $conn): void {
  $sql = "
    CREATE TABLE IF NOT EXISTS ticket_extras (
      ticket_id INT NOT NULL,
      branch_key VARCHAR(50) NOT NULL,
      fields_json LONGTEXT NULL,
      companies_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (ticket_id),
      KEY idx_branch (branch_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ";
  @$conn->query($sql);
}

/* =========================================================
   ✅ DROPDOWN OPT HELPERS
========================================================= */
function opts_years(int $from = 1970, int $to = 0): array {
  if ($to <= 0) $to = (int)date('Y') + 1;
  $o = ['' => 'Seçiniz'];
  for ($y=$to; $y>=$from; $y--) $o[(string)$y] = (string)$y;
  return $o;
}
function opts_days(): array {
  $o = ['' => 'Gün'];
  for ($d=1; $d<=31; $d++) $o[(string)$d] = str_pad((string)$d, 2, '0', STR_PAD_LEFT);
  return $o;
}
function opts_months(): array {
  return [
    ''=>'Ay','1'=>'01','2'=>'02','3'=>'03','4'=>'04','5'=>'05','6'=>'06',
    '7'=>'07','8'=>'08','9'=>'09','10'=>'10','11'=>'11','12'=>'12'
  ];
}
function opts_yesno(): array {
  return ['' => 'Seçiniz', 'evet' => 'Evet', 'hayir' => 'Hayır'];
}
function opts_square_m2(): array {
  $o = ['' => 'Seçiniz'];
  for ($m=50; $m<=400; $m+=10) $o[(string)$m] = $m . " m²";
  return $o;
}
function opts_floors(): array {
  $o = ['' => 'Seçiniz'];
  for ($i=-2; $i<=40; $i++){
    $k = (string)$i;
    $o[$k] = ($i < 0) ? ("Bodrum " . abs($i)) : (($i===0) ? "Zemin" : ($i . ". Kat"));
  }
  return $o;
}
function opts_vehicle_usage(): array {
  return [
    ''=>'Seçiniz',
    'hususi'=>'Hususi',
    'ticari'=>'Ticari',
    'rentacar'=>'Rent a Car',
    'taksi'=>'Taksi',
    'dolmus'=>'Dolmuş/Minibüs',
    'kamyonet'=>'Kamyonet',
    'kamyon'=>'Kamyon',
    'cekici'=>'Çekici',
    'otobus'=>'Otobüs',
    'motosiklet'=>'Motosiklet'
  ];
}
function opts_vehicle_body(): array {
  return [
    ''=>'Seçiniz',
    'sedan'=>'Sedan',
    'hatchback'=>'Hatchback',
    'station'=>'Station',
    'suv'=>'SUV',
    'pickup'=>'Pickup',
    'van'=>'Van',
    'coupe'=>'Coupe',
    'cabrio'=>'Cabrio',
    'minivan'=>'Minivan'
  ];
}
function opts_branches_health(): array {
  return [
    ''=>'Seçiniz',
    'ayakta'=>'Ayakta',
    'yatarak'=>'Yatarak',
    'ayakta_yatarak'=>'Ayakta + Yatarak',
  ];
}
function opts_trip_days(): array {
  $o = ['' => 'Seçiniz'];
  foreach ([3,5,7,10,15,30,60,90,120,180,365] as $d) $o[(string)$d] = $d . " gün";
  return $o;
}
function opts_cargo_type(): array {
  return [
    ''=>'Seçiniz',
    'genel'=>'Genel Kargo',
    'gida'=>'Gıda',
    'tekstil'=>'Tekstil',
    'elektronik'=>'Elektronik',
    'kimyasal'=>'Kimyasal',
    'makine'=>'Makine/Ekipman',
    'insaat'=>'İnşaat Malzemesi',
    'mobilya'=>'Mobilya',
    'diger'=>'Diğer',
  ];
}
function opts_engineering_type(): array {
  return [
    ''=>'Seçiniz',
    'insaat_allrisk'=>'İnşaat All Risk',
    'montaj_allrisk'=>'Montaj All Risk',
    'makine_kirilmasi'=>'Makine Kırılması',
    'elektronik_cihaz'=>'Elektronik Cihaz',
    'emtea'=>'Emtea',
    'diger'=>'Diğer',
  ];
}
function opts_liability_type(): array {
  return [
    ''=>'Seçiniz',
    'imm'=>'İMM',
    'mali_mesuliyet'=>'Mali Mesuliyet',
    'mesleki'=>'Mesleki Sorumluluk',
    'urun'=>'Ürün Sorumluluk',
    'diger'=>'Diğer',
  ];
}
function opts_tarim_type(): array {
  return [
    ''=>'Seçiniz',
    'bitkisel'=>'Bitkisel Ürün',
    'sera'=>'Sera',
    'hayvan'=>'Hayvan Hayat',
    'aricilik'=>'Arıcılık',
    'su_urunleri'=>'Su Ürünleri',
    'diger'=>'Diğer',
  ];
}

/* =========================================================
   ✅ Talep Türü (Dropdown) - Kurumsal Konu
========================================================= */
$REQUEST_TYPES = [
  ''              => 'Seçiniz',
  'POLICE_TALEBI' => 'Poliçe Talebi',
  'ZEYIL_TALEBI'  => 'Zeyil Talebi',
  'IPTAL_TALEBI'  => 'İptal Talebi',
  'HASAR'         => 'Hasar Bildirimi',
  'BILGI'         => 'Bilgi Talebi',
  'DIGER'         => 'Diğer',
];

/* =========================================================
   ✅ Branşlar
========================================================= */
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

/* =========================================================
   ✅ Branşa göre dinamik alanlar
========================================================= */
$BRANCH_FIELDS = [
  'trafik' => [
    ['k'=>'plate',          'l'=>'Plaka',              't'=>'text',   'ph'=>'34ABC123'],
    ['k'=>'tc',             'l'=>'T.C. Kimlik No',     't'=>'text',   'ph'=>'11 hane'],
    ['k'=>'birth_day',      'l'=>'Doğum Günü',         't'=>'select', 'opts'=>opts_days()],
    ['k'=>'birth_month',    'l'=>'Doğum Ayı',          't'=>'select', 'opts'=>opts_months()],
    ['k'=>'birth_year',     'l'=>'Doğum Yılı',         't'=>'select', 'opts'=>opts_years(1930)],
    ['k'=>'license_serial', 'l'=>'Ruhsat Seri No',     't'=>'text',   'ph'=>'AA123456'],
  ],
  'kasko' => [
    ['k'=>'plate',          'l'=>'Plaka',              't'=>'text',   'ph'=>'34ABC123'],
    ['k'=>'tc',             'l'=>'T.C. Kimlik No',     't'=>'text',   'ph'=>'11 hane'],
    ['k'=>'license_serial', 'l'=>'Ruhsat Seri No',     't'=>'text',   'ph'=>'AA123456'],
    ['k'=>'brand_code',     'l'=>'Marka Kodu',         't'=>'select', 'opts'=>[
      ''=>'Seçiniz',
      'RENAULT'=>'Renault','FIAT'=>'Fiat','FORD'=>'Ford','VOLKSWAGEN'=>'Volkswagen','TOYOTA'=>'Toyota',
      'HYUNDAI'=>'Hyundai','PEUGEOT'=>'Peugeot','CITROEN'=>'Citroen','OPEL'=>'Opel','MERCEDES'=>'Mercedes',
      'BMW'=>'BMW','AUDI'=>'Audi','NISSAN'=>'Nissan','KIA'=>'Kia','HONDA'=>'Honda','SKODA'=>'Skoda','SEAT'=>'Seat',
      'VOLVO'=>'Volvo','DACIA'=>'Dacia','MG'=>'MG','TESLA'=>'Tesla','CHERY'=>'Chery','TOGG'=>'TOGG','DIGER'=>'Diğer',
    ]],
    ['k'=>'model_code',     'l'=>'Model Kodu',         't'=>'select', 'opts'=>[
      ''=>'Seçiniz',
      'BILINMIYOR'=>'Bilmiyorum',
      'C_SEGMENT'=>'C Segment','B_SEGMENT'=>'B Segment','SUV'=>'SUV','PICKUP'=>'Pickup','VAN'=>'Van','LUKS'=>'Lüks','DIGER'=>'Diğer'
    ]],
    ['k'=>'model_year',     'l'=>'Model Yılı',         't'=>'select', 'opts'=>opts_years(1985)],
    ['k'=>'body_type',      'l'=>'Kasa Tipi',          't'=>'select', 'opts'=>opts_vehicle_body()],
    ['k'=>'usage_type',     'l'=>'Kullanım Türü',      't'=>'select', 'opts'=>opts_vehicle_usage()],
  ],
  'dask' => [
    ['k'=>'address',        'l'=>'Adres',              't'=>'text',   'ph'=>'İl/İlçe/Mahalle...'],
    ['k'=>'uavt',           'l'=>'UAVT No',            't'=>'text',   'ph'=>''],
    ['k'=>'building_year',  'l'=>'Bina Yapım Yılı',    't'=>'select', 'opts'=>opts_years(1950)],
    ['k'=>'square_m2',      'l'=>'Brüt m²',            't'=>'select', 'opts'=>opts_square_m2()],
    ['k'=>'floor',          'l'=>'Kat',                't'=>'select', 'opts'=>opts_floors()],
  ],
  'konut' => [
    ['k'=>'address',        'l'=>'Adres',              't'=>'text',   'ph'=>'İl/İlçe/Mahalle...'],
    ['k'=>'square_m2',      'l'=>'Brüt m²',            't'=>'select', 'opts'=>opts_square_m2()],
    ['k'=>'floor',          'l'=>'Kat',                't'=>'select', 'opts'=>opts_floors()],
    ['k'=>'has_dask',       'l'=>'DASK var mı?',       't'=>'select', 'opts'=>opts_yesno()],
  ],
  'isyeri' => [
    ['k'=>'company_title',  'l'=>'İşyeri Ünvanı',      't'=>'text',   'ph'=>''],
    ['k'=>'tax_no',         'l'=>'Vergi No',           't'=>'text',   'ph'=>''],
    ['k'=>'address',        'l'=>'Adres',              't'=>'text',   'ph'=>''],
    ['k'=>'square_m2',      'l'=>'Brüt m²',            't'=>'select', 'opts'=>opts_square_m2()],
    ['k'=>'building_year',  'l'=>'Bina Yapım Yılı',    't'=>'select', 'opts'=>opts_years(1950)],
    ['k'=>'has_alarm',      'l'=>'Alarm var mı?',      't'=>'select', 'opts'=>opts_yesno()],
  ],
  'saglik' => [
    ['k'=>'tc',             'l'=>'T.C. Kimlik No',     't'=>'text',   'ph'=>'11 hane'],
    ['k'=>'birth_year',     'l'=>'Doğum Yılı',         't'=>'select', 'opts'=>opts_years(1930)],
    ['k'=>'gender',         'l'=>'Cinsiyet',           't'=>'select', 'opts'=>[''=>'Seçiniz','kadin'=>'Kadın','erkek'=>'Erkek','diger'=>'Diğer']],
    ['k'=>'coverage',       'l'=>'Teminat',            't'=>'select', 'opts'=>opts_branches_health()],
  ],
  'tss' => [
    ['k'=>'tc',             'l'=>'T.C. Kimlik No',     't'=>'text',   'ph'=>'11 hane'],
    ['k'=>'birth_year',     'l'=>'Doğum Yılı',         't'=>'select', 'opts'=>opts_years(1930)],
    ['k'=>'gender',         'l'=>'Cinsiyet',           't'=>'select', 'opts'=>[''=>'Seçiniz','kadin'=>'Kadın','erkek'=>'Erkek','diger'=>'Diğer']],
  ],
  'seyahat' => [
    ['k'=>'tc',             'l'=>'T.C. Kimlik No',     't'=>'text',   'ph'=>'11 hane'],
    ['k'=>'trip_days',      'l'=>'Seyahat Süresi',     't'=>'select', 'opts'=>opts_trip_days()],
    ['k'=>'region',         'l'=>'Bölge',              't'=>'select', 'opts'=>[''=>'Seçiniz','schengen'=>'Schengen','world'=>'Dünya','turkiye'=>'Türkiye']],
  ],
  'ferdi_kaza' => [
    ['k'=>'tc',             'l'=>'T.C. Kimlik No',     't'=>'text',   'ph'=>'11 hane'],
    ['k'=>'birth_year',     'l'=>'Doğum Yılı',         't'=>'select', 'opts'=>opts_years(1930)],
    ['k'=>'occupation',     'l'=>'Meslek Grubu',       't'=>'select', 'opts'=>[''=>'Seçiniz','dusuk'=>'Düşük Risk','orta'=>'Orta Risk','yuksek'=>'Yüksek Risk']],
  ],
  'nakliyat' => [
    ['k'=>'cargo_type',     'l'=>'Yük Tipi',           't'=>'select', 'opts'=>opts_cargo_type()],
    ['k'=>'transport',      'l'=>'Taşıma Şekli',       't'=>'select', 'opts'=>[''=>'Seçiniz','kara'=>'Kara','deniz'=>'Deniz','hava'=>'Hava','demiryolu'=>'Demiryolu']],
    ['k'=>'route',          'l'=>'Güzergah',           't'=>'select', 'opts'=>[''=>'Seçiniz','yurtici'=>'Yurtiçi','uluslararasi'=>'Uluslararası']],
  ],
  'sorumluluk' => [
    ['k'=>'liability_type', 'l'=>'Sorumluluk Türü',     't'=>'select', 'opts'=>opts_liability_type()],
    ['k'=>'limit',          'l'=>'Limit',              't'=>'select', 'opts'=>[
      ''=>'Seçiniz','1000000'=>'1.000.000','2000000'=>'2.000.000','5000000'=>'5.000.000','10000000'=>'10.000.000','20000000'=>'20.000.000','diger'=>'Diğer'
    ]],
  ],
  'muhendislik' => [
    ['k'=>'eng_type',       'l'=>'Ürün',               't'=>'select', 'opts'=>opts_engineering_type()],
    ['k'=>'project_year',   'l'=>'Proje Yılı',         't'=>'select', 'opts'=>opts_years(2000)],
  ],
  'hayat' => [
    ['k'=>'tc',             'l'=>'T.C. Kimlik No',     't'=>'text',   'ph'=>'11 hane'],
    ['k'=>'birth_year',     'l'=>'Doğum Yılı',         't'=>'select', 'opts'=>opts_years(1930)],
    ['k'=>'gender',         'l'=>'Cinsiyet',           't'=>'select', 'opts'=>[''=>'Seçiniz','kadin'=>'Kadın','erkek'=>'Erkek','diger'=>'Diğer']],
  ],
  'bes' => [
    ['k'=>'tc',             'l'=>'T.C. Kimlik No',     't'=>'text',   'ph'=>'11 hane'],
    ['k'=>'birth_year',     'l'=>'Doğum Yılı',         't'=>'select', 'opts'=>opts_years(1930)],
  ],
  'tarim' => [
    ['k'=>'tarim_type',     'l'=>'TARSİM Türü',        't'=>'select', 'opts'=>opts_tarim_type()],
    ['k'=>'city',           'l'=>'İl',                 't'=>'select', 'opts'=>[''=>'Seçiniz','istanbul'=>'İstanbul','ankara'=>'Ankara','izmir'=>'İzmir','bursa'=>'Bursa','antalya'=>'Antalya','diger'=>'Diğer']],
  ],
  'tekne' => [
    ['k'=>'boat_type',      'l'=>'Tekne Tipi',         't'=>'select', 'opts'=>[''=>'Seçiniz','yat'=>'Yat','tekne'=>'Tekne','sürat'=>'Sürat Teknesi','diger'=>'Diğer']],
    ['k'=>'length',         'l'=>'Uzunluk',            't'=>'select', 'opts'=>[''=>'Seçiniz','0_6'=>'0-6 m','6_9'=>'6-9 m','9_12'=>'9-12 m','12_18'=>'12-18 m','18p'=>'18m+']],
    ['k'=>'model_year',     'l'=>'Model Yılı',         't'=>'select', 'opts'=>opts_years(1970)],
  ],
  '_default' => [
    ['k'=>'tc',             'l'=>'T.C. Kimlik No / Vergi No', 't'=>'text','ph'=>''],
    ['k'=>'phone',          'l'=>'Telefon',                   't'=>'text','ph'=>'05xx...'],
    ['k'=>'note',           'l'=>'Ek Not',                    't'=>'text','ph'=>''],
  ],
];

/* =========================================================
   ✅ GLOBAL: Tüm branşlara Ad Soyad + Doğum Tarihi (tek input)
   - insured_name: Ad Soyad
   - birth_date  : dd/mm/yyyy
   - eski birth_day/birth_month/birth_year alanlarını kaldırır
========================================================= */
function apply_global_person_fields(array $BRANCH_FIELDS): array {
  $personFields = [
    ['k'=>'insured_name', 'l'=>'Ad Soyad',     't'=>'text', 'ph'=>'Örn: Ahmet Yılmaz'],
    ['k'=>'birth_date',   'l'=>'Doğum Tarihi', 't'=>'text', 'ph'=>'20/09/1999'],
  ];

  foreach ($BRANCH_FIELDS as $bKey => $fields) {
    if (!is_array($fields)) continue;

    $filtered = [];
    foreach ($fields as $f) {
      $k = $f['k'] ?? '';
      if (in_array($k, ['birth_day','birth_month','birth_year'], true)) continue;
      if (in_array($k, ['insured_name','birth_date'], true)) continue;
      $filtered[] = $f;
    }

    $BRANCH_FIELDS[$bKey] = array_merge($personFields, $filtered);
  }

  return $BRANCH_FIELDS;
}

function is_valid_birth_date(string $s): bool {
  $s = trim($s);
  if ($s === '') return true;
  if (!preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $s, $m)) return false;
  $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
  if ($y < 1900 || $y > (int)date('Y') + 1) return false;
  return checkdate($mo, $d, $y);
}

$BRANCH_FIELDS = apply_global_person_fields($BRANCH_FIELDS);

/* =========================================================
   ✅ Şirketleri DB’den çek (insurance_companies + agency_companies)
========================================================= */
function fetch_all_companies(mysqli $conn): array {
  $rows = [];
  $st = mysqli_prepare($conn, "SELECT id, name FROM insurance_companies WHERE is_active=1 ORDER BY name ASC");
  if ($st) {
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $id, $name);
    while (mysqli_stmt_fetch($st)) {
      $rows[] = ['id'=>(int)$id, 'name'=>(string)$name];
    }
    mysqli_stmt_close($st);
  }
  return $rows;
}
function fetch_agency_company_ids(mysqli $conn, int $agencyId): array {
  if ($agencyId <= 0) return [];
  $ids = [];
  $st = mysqli_prepare($conn, "SELECT company_id FROM agency_companies WHERE agency_id=?");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $agencyId);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $cid);
    while (mysqli_stmt_fetch($st)) $ids[] = (int)$cid;
    mysqli_stmt_close($st);
  }
  $ids = array_values(array_unique(array_filter($ids)));
  return $ids;
}

/* ---- Kullanıcının acentesi tali mi? ---- */
$errors = [];
$successId = null;

$currentAgencyName = '';
$currentParentId   = 0;
$isTaliContext     = false;

if ($agencyId <= 0) {
  $errors[] = "Hesabınız bir acenteye bağlı değil (agency_id boş/0).";
} else {
  $st = mysqli_prepare($conn, "SELECT name, COALESCE(parent_id,0) AS parent_id FROM agencies WHERE id=? LIMIT 1");
  mysqli_stmt_bind_param($st, "i", $agencyId);
  mysqli_stmt_execute($st);
  mysqli_stmt_bind_result($st, $aname, $pid);
  mysqli_stmt_fetch($st);
  mysqli_stmt_close($st);

  $currentAgencyName = (string)($aname ?? '');
  $currentParentId   = (int)($pid ?? 0);
  if ($currentParentId > 0) $isTaliContext = true;
}

/* ---- Hedef belirleme ---- */
$targetAgencyId   = 0;
$targetAgencyName = '';

$mode = (string)($_POST['mode'] ?? $_GET['mode'] ?? 'main');
if (!in_array($mode, ['main','sub'], true)) $mode = 'main';

/* ✅ hedefi POST > GET */
$selectedTargetId = (int)($_POST['to_agency_id'] ?? $_GET['to_agency_id'] ?? 0);

/* ✅ Tali context: hedef otomatik parent */
if (empty($errors) && $isTaliContext) {
  $targetAgencyId = $currentParentId;

  $st = mysqli_prepare($conn, "SELECT name FROM agencies WHERE id=? LIMIT 1");
  mysqli_stmt_bind_param($st, "i", $targetAgencyId);
  mysqli_stmt_execute($st);
  mysqli_stmt_bind_result($st, $tname2);
  mysqli_stmt_fetch($st);
  mysqli_stmt_close($st);

  $targetAgencyName = (string)($tname2 ?? '');
}

/* ✅ Ana acente context: hedef seçilecek */
if (empty($errors) && !$isTaliContext) {
  if ($selectedTargetId > 0) {
    if ($selectedTargetId === $agencyId) {
      $errors[] = "Kendi acentene ticket açamazsın.";
    } else {
      if ($mode === 'sub') {
        $st = mysqli_prepare($conn, "SELECT id, name FROM agencies WHERE id=? AND COALESCE(parent_id,0)=? LIMIT 1");
        mysqli_stmt_bind_param($st, "ii", $selectedTargetId, $agencyId);
      } else {
        $st = mysqli_prepare($conn, "SELECT id, name FROM agencies WHERE id=? AND COALESCE(parent_id,0)=0 LIMIT 1");
        mysqli_stmt_bind_param($st, "i", $selectedTargetId);
      }

      mysqli_stmt_execute($st);
      mysqli_stmt_bind_result($st, $tid, $tname);
      if (mysqli_stmt_fetch($st)) {
        $targetAgencyId   = (int)$tid;
        $targetAgencyName = (string)$tname;
      }
      mysqli_stmt_close($st);

      if ($targetAgencyId <= 0) $errors[] = "Seçilen hedef geçersiz.";
    }
  }
}

/* ---- Listeler (seçim ekranı için) ---- */
$mainAgencies = [];
$subAgencies  = [];

if (empty($errors) && !$isTaliContext) {

  $st = mysqli_prepare($conn, "
    SELECT id, name
    FROM agencies
    WHERE COALESCE(parent_id,0)=0
      AND id <> ?
    ORDER BY name ASC
  ");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $agencyId);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $id, $name);
    while (mysqli_stmt_fetch($st)) {
      $mainAgencies[] = ['id' => (int)$id, 'name' => (string)$name];
    }
    mysqli_stmt_close($st);
  }

  $st = mysqli_prepare($conn, "
    SELECT id, name
    FROM agencies
    WHERE COALESCE(parent_id,0)=?
    ORDER BY name ASC
  ");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $agencyId);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $id, $name);
    while (mysqli_stmt_fetch($st)) {
      $subAgencies[] = ['id' => (int)$id, 'name' => (string)$name];
    }
    mysqli_stmt_close($st);
  }
}

/* =========================================================
   ✅ Şirket listesi + hedef acentenin çalıştıkları
========================================================= */
$ALL_COMPANIES = fetch_all_companies($conn);
$ALL_COMPANY_IDS = array_map(fn($r)=> (int)$r['id'], $ALL_COMPANIES);
$ALL_COMPANY_ID_SET = array_flip($ALL_COMPANY_IDS);

/* hedef seçilmediyse boş */
$TARGET_COMPANY_IDS = ($targetAgencyId > 0) ? fetch_agency_company_ids($conn, $targetAgencyId) : [];
$TARGET_COMPANY_ID_SET = array_flip($TARGET_COMPANY_IDS);

/* ---- FORM INPUT ---- */
$branch        = trim((string)($_POST['branch'] ?? ''));
$requestType   = trim((string)($_POST['request_type'] ?? ''));
$subjectDetail = trim((string)($_POST['subject_detail'] ?? ''));
$description   = trim((string)($_POST['description'] ?? ''));

/* dinamik alanlar + şirketler */
$postFieldsRaw = $_POST['bf'] ?? [];

/* şirketler artık company_id olarak geliyor */
$selectedCompanies = $_POST['companies'] ?? [];
$selectedCompanies = is_array($selectedCompanies) ? $selectedCompanies : [];

/* ✅ normalize selected company ids (SADECE hedef acentenin çalıştıkları) */
$selectedCompanyIds = [];
$VALID_COMPANY_SET = ($targetAgencyId > 0) ? $TARGET_COMPANY_ID_SET : [];
foreach ($selectedCompanies as $c) {
  $cid = (int)$c;
  if ($cid > 0 && isset($VALID_COMPANY_SET[$cid])) $selectedCompanyIds[] = $cid;
}
$selectedCompanyIds = array_values(array_unique($selectedCompanyIds));

/* subject'i dropdown + detaydan üret (DB değişmeden) */
$subject = '';
if ($requestType !== '' && isset($REQUEST_TYPES[$requestType])) {
  $subject = $REQUEST_TYPES[$requestType];
  if ($subjectDetail !== '') $subject .= " — " . $subjectDetail;
}

/* ---- FORM SUBMIT ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!$isTaliContext) {
    $mode = (string)($_POST['mode'] ?? $mode);
    if (!in_array($mode, ['main','sub'], true)) $mode = 'main';

    $selectedTargetId = (int)($_POST['to_agency_id'] ?? 0);

    if ($selectedTargetId <= 0) {
      $errors[] = "Hedef seçmelisin.";
    } elseif ($selectedTargetId === $agencyId) {
      $errors[] = "Kendi acentene ticket açamazsın.";
    } else {
      $tmpId = 0; $tmpName = '';
      if ($mode === 'sub') {
        $st = mysqli_prepare($conn, "SELECT id, name FROM agencies WHERE id=? AND COALESCE(parent_id,0)=? LIMIT 1");
        mysqli_stmt_bind_param($st, "ii", $selectedTargetId, $agencyId);
      } else {
        $st = mysqli_prepare($conn, "SELECT id, name FROM agencies WHERE id=? AND COALESCE(parent_id,0)=0 LIMIT 1");
        mysqli_stmt_bind_param($st, "i", $selectedTargetId);
      }
      mysqli_stmt_execute($st);
      mysqli_stmt_bind_result($st, $tid, $tname);
      if (mysqli_stmt_fetch($st)) { $tmpId = (int)$tid; $tmpName = (string)$tname; }
      mysqli_stmt_close($st);

      if ($tmpId <= 0) $errors[] = "Seçilen hedef geçersiz.";
      else { $targetAgencyId = $tmpId; $targetAgencyName = $tmpName; }
    }
  }

  if (!isset($BRANCHES[$branch])) $errors[] = "Geçerli branş seçmelisin.";
  if ($requestType === '' || !isset($REQUEST_TYPES[$requestType])) $errors[] = "Talep türü seçmelisin.";
  if ($subjectDetail !== '' && mb_strlen($subjectDetail) < 3) $errors[] = "Konu detayı en az 3 karakter olmalı (boş bırakabilirsin).";
  if (mb_strlen($description) < 10) $errors[] = "Açıklama en az 10 karakter olmalı.";
  if ($targetAgencyId <= 0) $errors[] = "Hedef acente belirlenemedi.";

  /* ✅ şirket seçimi zorunlu + hedef acente şirketi yoksa blokla */
  if ($targetAgencyId <= 0) $errors[] = "Önce hedef acente seçmelisin.";
  if ($targetAgencyId > 0 && count($TARGET_COMPANY_IDS) < 1) $errors[] = "Bu acenteye tanımlı şirket yok.";
  if (count($selectedCompanyIds) < 1) $errors[] = "En az 1 sigorta şirketi seçmelisin.";

  // branşa göre allowed field keys
  $defs = $BRANCH_FIELDS[$branch] ?? $BRANCH_FIELDS['_default'];
  $allowedKeys = [];
  foreach ($defs as $d) $allowedKeys[] = $d['k'];

  $cleanFields = [];
  if (is_array($postFieldsRaw)) {
    foreach ($allowedKeys as $k) {
      $v = isset($postFieldsRaw[$k]) ? trim((string)$postFieldsRaw[$k]) : '';
      if ($v !== '') $cleanFields[$k] = $v;
    }
  }

  // ✅ Doğum tarihi format kontrolü
  if (isset($cleanFields['birth_date']) && !is_valid_birth_date((string)$cleanFields['birth_date'])) {
    $errors[] = "Doğum Tarihi formatı geçersiz. Örn: 20/09/1999";
  }

  // subject fallback güvenliği
  if ($subject === '') $subject = "Talep";

  if (!$errors) {
    ensure_ticket_extras_table($conn);

    $conn->begin_transaction();

    try {
      // ✅ FIX: request_type tickets tablosuna yazılıyor
      $sql = "
        INSERT INTO tickets
          (created_by_user_id, created_by_agency_id, target_agency_id, subject, description, branch, request_type, status, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, 'open', NOW(), NOW())
      ";
      $st = mysqli_prepare($conn, $sql);
      if (!$st) throw new Exception("Ticket oluşturulamadı: SQL prepare hatası.");

      mysqli_stmt_bind_param($st, "iiissss", $userId, $agencyId, $targetAgencyId, $subject, $description, $branch, $requestType);
      if (!mysqli_stmt_execute($st)) {
        $err = mysqli_stmt_error($st);
        mysqli_stmt_close($st);
        throw new Exception("Ticket oluşturulamadı: ".$err);
      }
      $successId = (int)mysqli_insert_id($conn);
      mysqli_stmt_close($st);

      if ($successId <= 0) throw new Exception("Ticket oluşturulamadı (ID alınamadı).");

      $fieldsJson    = json_encode($cleanFields, JSON_UNESCAPED_UNICODE);
      $companiesJson = json_encode($selectedCompanyIds, JSON_UNESCAPED_UNICODE);

      $stx = mysqli_prepare($conn, "
        INSERT INTO ticket_extras (ticket_id, branch_key, fields_json, companies_json)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          branch_key = VALUES(branch_key),
          fields_json = VALUES(fields_json),
          companies_json = VALUES(companies_json)
      ");
      if ($stx) {
        mysqli_stmt_bind_param($stx, "isss", $successId, $branch, $fieldsJson, $companiesJson);
        mysqli_stmt_execute($stx);
        mysqli_stmt_close($stx);
      }

      // Oluşturan kullanıcı için "görüldü" kaydı
      $stSeen = mysqli_prepare($conn, "
        INSERT INTO ticket_user_state (ticket_id, user_id, last_seen_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE last_seen_at = NOW()
      ");
      if ($stSeen) {
        mysqli_stmt_bind_param($stSeen, "ii", $successId, $userId);
        mysqli_stmt_execute($stSeen);
        mysqli_stmt_close($stSeen);
      }

      $conn->commit();

      header("Location: " . base_url("tickets.php?box=out&created=1"));
      exit;

    } catch (Throwable $e) {
      $conn->rollback();
      $errors[] = $e->getMessage() ?: "Ticket oluşturulamadı.";
      $successId = null;
    }
  }
}

$page_title = "Ticket Aç";
include __DIR__ . "/layout/header.php";
?>

<style>
.ticket-create-grid{
  display:grid;
  grid-template-columns: 1.15fr .85fr;
  gap:14px;
  align-items:start;
}
@media (max-width: 980px){
  .ticket-create-grid{ grid-template-columns:1fr; }
}
.side-box{
  border:1px solid rgba(15,23,42,.10);
  border-radius:12px;
  background: rgba(255,255,255,.65);
  backdrop-filter: blur(6px);
  padding:12px;
}
.side-title{
  font-weight:800;
  font-size:13px;
  color: rgba(15,23,42,.75);
  margin-bottom:10px;
}
.company-search{ width:100%; margin-bottom:10px; }
.company-list{
  display:grid; gap:8px;
  max-height: 360px; overflow:auto;
  padding-right:6px;
}
.company-item{
  display:flex; gap:10px; align-items:center;
  padding:8px 10px;
  border:1px solid rgba(15,23,42,.10);
  border-radius:10px;
  background:#fff;
}
.company-item:hover{ border-color: rgba(29,78,216,.35); }
.badge-mini{
  display:inline-flex; align-items:center;
  padding:3px 8px;
  border-radius:999px;
  border:1px solid rgba(15,23,42,.12);
  background:#fff;
  font-size:12px;
  opacity:.85;
}
.branch-fields{ display:grid; gap:10px; }
.smallmuted{ font-size:12px; color: rgba(15,23,42,.65); }
.hr2{ height:1px; background: rgba(15,23,42,.10); margin:10px 0; }

/* ✅ DROPDOWN (SELECT) YUMUŞATMA FIX (SADECE BU SAYFA) */
.ticket-create select.input{
  -webkit-appearance:none;
  -moz-appearance:none;
  appearance:none;

  border-radius:14px;
  border:1px solid rgba(15,23,42,.12);
  background-color: rgba(255,255,255,.96);

  padding:10px 42px 10px 12px;
  line-height:1.25;
  cursor:pointer;

  box-shadow:0 1px 0 rgba(15,23,42,.04);
  transition:border-color .15s ease, box-shadow .15s ease;

  background-image:
    linear-gradient(45deg, transparent 50%, rgba(15,23,42,.55) 50%),
    linear-gradient(135deg, rgba(15,23,42,.55) 50%, transparent 50%);
  background-position:
    calc(100% - 18px) 55%,
    calc(100% - 12px) 55%;
  background-size:6px 6px,6px 6px;
  background-repeat:no-repeat;
}
.ticket-create select.input:hover{
  border-color: rgba(29,78,216,.30);
}
.ticket-create select.input:focus{
  outline:none;
  border-color: rgba(29,78,216,.55);
  box-shadow: 0 0 0 4px rgba(29,78,216,.10);
}
.ticket-create select.input:disabled{
  opacity:.75;
  cursor:not-allowed;
}
</style>

<main class="page ticket-create">
  <div class="card">
    <div class="row" style="align-items:flex-start;">
      <div>
        <h2 class="card-title" style="margin-bottom:6px;">Ticket Aç</h2>
        <div class="card-sub">
          <?php if ($isTaliContext): ?>
            <span class="badge-mini">Tali acente</span>
            Ticket otomatik <b>ana acentene</b> gider.
          <?php else: ?>
            Önce hedef seç, sonra ticket oluştur.
          <?php endif; ?>
        </div>
      </div>
      <a class="btn" href="<?= h(base_url('tickets.php')) ?>">Ticket Kutusu</a>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert err" style="margin-top:12px;">
        <b>İşlem yapılamadı.</b>
        <div class="small" style="margin-top:6px;line-height:1.6;">
          <?php foreach ($errors as $e): ?>
            • <?= h($e) ?><br>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="hr"></div>

    <form method="post" autocomplete="off">
      <div class="ticket-create-grid">

        <!-- SOL: Ticket form -->
        <div>
          <?php if (!$isTaliContext): ?>
            <div class="form one" style="margin-bottom:10px;">
              <div class="field">
                <div class="label">Hedef Türü</div>
                <div style="display:flex; gap:14px; flex-wrap:wrap;">
                  <label style="display:flex; gap:8px; align-items:center;">
                    <input type="radio" name="mode" value="main" <?= ($mode==='main'?'checked':'') ?>>
                    <span>Ana Acente</span>
                  </label>
                  <label style="display:flex; gap:8px; align-items:center;">
                    <input type="radio" name="mode" value="sub" <?= ($mode==='sub'?'checked':'') ?>>
                    <span>Tali Acentem</span>
                  </label>
                </div>
                <div class="help">Ana acente seçince tüm ana acenteler listelenir. Tali seçince sadece senin talilerin listelenir.</div>
              </div>
            </div>

            <div class="form">
              <div class="field" id="wrap-main">
                <div class="label">Ana Acenteler</div>
                <select class="input" name="to_agency_id" id="select-main">
                  <option value="">Seçiniz</option>
                  <?php foreach ($mainAgencies as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= ($mode==='main' && $selectedTargetId==(int)$a['id'])?'selected':'' ?>>
                      <?= h($a['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field" id="wrap-sub">
                <div class="label">Tali Acentelerim</div>
                <select class="input" name="to_agency_id" id="select-sub">
                  <option value="">Seçiniz</option>
                  <?php foreach ($subAgencies as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= ($mode==='sub' && $selectedTargetId==(int)$a['id'])?'selected':'' ?>>
                      <?= h($a['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <script>
              (function(){
                function sync(){
                  var mode  = document.querySelector('input[name="mode"]:checked')?.value || 'main';
                  var wMain = document.getElementById('wrap-main');
                  var wSub  = document.getElementById('wrap-sub');
                  var sMain = document.getElementById('select-main');
                  var sSub  = document.getElementById('select-sub');
                  if (!wMain || !wSub || !sMain || !sSub) return;

                  if (mode === 'main') {
                    wMain.style.display = '';
                    wSub.style.display  = 'none';
                    sMain.disabled = false;
                    sSub.disabled  = true;
                    sSub.value = '';
                  } else {
                    wSub.style.display  = '';
                    wMain.style.display = 'none';
                    sSub.disabled  = false;
                    sMain.disabled = true;
                    sMain.value = '';
                  }
                }
                document.querySelectorAll('input[name="mode"]').forEach(function(r){
                  r.addEventListener('change', sync);
                });
                sync();
              })();
            </script>
          <?php else: ?>
            <div class="form one">
              <div class="field">
                <div class="label">Hedef</div>
                <input class="input" type="text" value="<?= h($targetAgencyName ?: '-') ?>" disabled>
                <div class="help">Tali acentelerde hedef otomatik ana acentedir.</div>
              </div>
            </div>
          <?php endif; ?>

          <div class="hr"></div>

          <div class="form">
            <div class="field">
              <div class="label">Branş</div>
              <select class="input" name="branch" id="jsBranch" required>
                <option value="">Seçiniz</option>
                <?php foreach ($BRANCHES as $k=>$v): ?>
                  <option value="<?= h($k) ?>" <?= ($branch===$k?'selected':'') ?>>
                    <?= h($v) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="help">Branş seçince sağ tarafta ilgili bilgi alanları açılır.</div>
            </div>

            <div class="field">
              <div class="label">Talep Türü</div>
              <select class="input" name="request_type" required>
                <?php foreach ($REQUEST_TYPES as $k=>$v): ?>
                  <option value="<?= h($k) ?>" <?= ($requestType===$k?'selected':'') ?>><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="help">Ticket konusu raporlama için standart tutulur.</div>
            </div>

            <div class="field">
              <div class="label">Konu Detayı</div>
              <input class="input" type="text" name="subject_detail" value="<?= h($subjectDetail) ?>" placeholder="Örn: 34ABC123 / müşteri adı / kısa not">
              <div class="help">İstersen boş bırakabilirsin.</div>
            </div>
          </div>

          <div class="form one">
            <div class="field">
              <div class="label">Açıklama</div>
              <textarea class="input" name="description" required><?= h($description) ?></textarea>
            </div>
          </div>

          <div class="actions">
            <a class="btn" href="<?= h(base_url('dashboard.php')) ?>">Vazgeç</a>
            <button class="btn primary" type="submit">Ticket Oluştur</button>
          </div>
        </div>

        <!-- SAĞ: Branşa göre alanlar + Şirket seçimi -->
        <div class="side-box">
          <div class="side-title">Branş Bilgileri & Şirket Seçimi</div>

          <div id="jsBranchFields" class="branch-fields"></div>

          <div class="hr" style="margin:12px 0;"></div>

          <div class="side-title" style="margin-bottom:8px;">Teklif İstenecek Şirketler</div>

          <?php $hasTarget = ($targetAgencyId > 0); ?>

          <?php if (!$hasTarget && !$isTaliContext): ?>
            <div class="smallmuted" style="margin:8px 0 10px 0;">
              Önce <b>hedef acenteyi</b> seç. Hedef seçilmeden şirket listesi açılmaz.
            </div>
          <?php endif; ?>

          <?php if ($hasTarget): ?>
            <label style="display:flex; gap:8px; align-items:center; margin-bottom:10px;">
              <input type="checkbox" id="jsSelectAll">
              <span><b>Tümünü seç</b></span>
            </label>

            <input class="input company-search" id="jsCompanySearch" type="text" placeholder="Şirket ara...">
          <?php endif; ?>

          <?php
            // SADECE hedef acentenin çalıştıkları
            $inList = [];
            if ($hasTarget) {
              foreach ($ALL_COMPANIES as $r) {
                $cid = (int)$r['id'];
                if (isset($TARGET_COMPANY_ID_SET[$cid])) $inList[] = $r;
              }
            }
          ?>

          <div class="company-list" id="jsCompanyList">
            <?php if ($hasTarget): ?>
              <?php if (count($inList) < 1): ?>
                <div class="smallmuted">Bu acenteye tanımlı şirket yok.</div>
              <?php else: ?>
                <?php foreach ($inList as $r): ?>
                  <?php $cid = (int)$r['id']; $name = (string)$r['name']; $checked = in_array($cid, (array)$selectedCompanyIds, true); ?>
                  <label class="company-item" data-name="<?= h(mb_strtolower($name)) ?>">
                    <input type="checkbox" class="jsCompanyCb" name="companies[]" value="<?= (int)$cid ?>" <?= $checked ? 'checked' : '' ?>>
                    <span><?= h($name) ?></span>
                  </label>
                <?php endforeach; ?>
              <?php endif; ?>
            <?php else: ?>
              <div class="smallmuted">Hedef seçilince şirketler burada listelenecek.</div>
            <?php endif; ?>
          </div>

          <div class="help" style="margin-top:10px;">
            En az 1 şirket seçmek zorunlu.
          </div>
        </div>

      </div>
    </form>
  </div>
</main>

<script>
(function(){
  const defs = <?= json_encode($BRANCH_FIELDS, JSON_UNESCAPED_UNICODE) ?>;
  const prev = <?= json_encode((array)$postFieldsRaw, JSON_UNESCAPED_UNICODE) ?>;

  const elBranch = document.getElementById('jsBranch');
  const elWrap   = document.getElementById('jsBranchFields');

  function esc(s){
    return String(s)
      .replace(/&/g,"&amp;")
      .replace(/</g,"&lt;")
      .replace(/>/g,"&gt;")
      .replace(/"/g,"&quot;")
      .replace(/'/g,"&#039;");
  }

  function renderFields(branch){
    const list = defs[branch] || defs["_default"] || [];
    let html = '';

    html += '<div class="side-title">Branşa Göre Bilgiler</div>';

    if (!branch){
      html += '<div class="card-sub">Branş seçince alanlar burada açılır.</div>';
      elWrap.innerHTML = html;
      return;
    }

    for (const f of list){
      const key = f.k;
      const label = f.l || key;
      const type = f.t || 'text';
      const ph = f.ph || '';
      const val = (prev && prev[key] != null) ? String(prev[key]) : '';

      html += `
        <div class="field">
          <div class="label">${esc(label)}</div>
          <input class="input" type="text" name="bf[${esc(key)}]" value="${esc(val)}" placeholder="${esc(ph)}">
        </div>
      `;
    }

    elWrap.innerHTML = html;
  }

  if (elBranch) {
    elBranch.addEventListener('change', function(){
      renderFields(this.value);
    });
    renderFields(elBranch.value || '');
  }

  // Company search (hedef yoksa çalışmaz)
  const s = document.getElementById('jsCompanySearch');
  const list = document.getElementById('jsCompanyList');
  if (s && list){
    s.addEventListener('input', function(){
      const q = this.value.trim().toLowerCase();
      list.querySelectorAll('[data-name]').forEach(function(item){
        const name = (item.getAttribute('data-name') || '').toLowerCase();
        item.style.display = (!q || name.indexOf(q) >= 0) ? '' : 'none';
      });
    });
  }

  // Select all (hedef yoksa çalışmaz)
  const selAll = document.getElementById('jsSelectAll');
  function allCheckboxes(){ return Array.from(document.querySelectorAll('.jsCompanyCb')); }
  function syncSelectAll(){
    if (!selAll) return;
    const cbs = allCheckboxes();
    const checked = cbs.filter(cb => cb.checked).length;
    selAll.checked = (cbs.length > 0 && checked === cbs.length);
    selAll.indeterminate = (checked > 0 && checked < cbs.length);
  }
  if (selAll){
    selAll.addEventListener('change', function(){
      const cbs = allCheckboxes();
      cbs.forEach(cb => cb.checked = selAll.checked);
      syncSelectAll();
    });
  }
  document.querySelectorAll('.jsCompanyCb').forEach(cb => {
    cb.addEventListener('change', syncSelectAll);
  });
  syncSelectAll();
})();
</script>

<?php require_once __DIR__ . "/layout/footer.php"; ?>
