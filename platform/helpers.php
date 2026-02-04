<?php
// Boot error logging early (no output before this file!)
require_once __DIR__ . '/core/error.php';
init_error_logging();

// /platform/helpers.php
// Mutabakat V2 - Yardımcı fonksiyonlar

/* =========================
   CONFIG
========================= */
function config(string $key = null, $default = null) {
    static $cfg = null;

    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        $loaded = file_exists($path) ? require $path : [];
        $cfg = is_array($loaded) ? $loaded : [];
    }

    if ($key === null || $key === '') return $cfg;

    $segments = explode('.', $key);
    $value = $cfg;

    foreach ($segments as $seg) {
        if (is_array($value) && array_key_exists($seg, $value)) {
            $value = $value[$seg];
        } else {
            return $default;
        }
    }
    return $value;
}

/* =========================
   REQUEST / BASE (fallback)
========================= */
function request_scheme(): string {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
    return $isHttps ? 'https' : 'http';
}

function request_host(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    // host boşsa (CLI gibi) base_url relative kalsın
    return trim($host);
}

/**
 * config('url.base') tanımlı değilse request'ten türet.
 * Örn: https://acentedestek.com
 */
function detect_base_origin(): string {
    $cfg = rtrim((string)config('url.base', ''), '/');
    if ($cfg !== '') return $cfg;

    $host = request_host();
    if ($host === '') return '';
    return request_scheme() . '://' . $host;
}

/**
 * config('url.path') tanımlı değilse /platform varsay.
 */
function detect_app_root(): string {
    $root = '/' . trim((string)config('url.path', 'platform'), '/');
    return $root === '' ? '/platform' : $root;
}

/* =========================
   SESSION
========================= */
function ensure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $httponly = (bool) config('security.cookie_httponly', true);
    $samesite = (string) config('security.cookie_samesite', 'Lax');

    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);

    $secureCfg = (bool) config('security.cookie_secure', false);
    $secure    = $isHttps ? $secureCfg : false;

    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ]);
    }

    session_start();
}

/* =========================
   URL
========================= */
function base_url(string $path = ''): string {
    $base = detect_base_origin();
    $root = detect_app_root();

    $p = trim($path);

    if ($p === '' || $p === '/') return $base . $root . '/';
    if (preg_match('#^https?://#i', $p)) return $p;
    if ($p[0] !== '/') $p = '/' . $p;

    if ($root !== '/' && (strpos($p, $root . '/') === 0 || $p === $root)) {
        return $base . $p;
    }

    return $base . $root . $p;
}

/**
 * Pretty route helper.
 * Örn: route_url('tickets') -> https://domain.com/platform/tickets
 */
function route_url(string $route = ''): string {
    $route = trim($route);
    if ($route === '') return base_url('/');
    // route slash ile gelirse normalize et
    $route = ltrim($route, '/');
    return base_url('/' . $route);
}

/**
 * Pretty route helper
 * url('tickets') -> /platform/tickets
 */
function url(string $route = ''): string {
    $route = trim($route);
    if ($route === '' || $route === '/') return base_url('');
    return base_url($route);
}

/* =========================
   ESCAPE (XSS)
========================= */
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* =========================
   REDIRECT
========================= */
function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}

/* =========================
   AUTH HELPERS
========================= */
function auth_role(): string {
    return (string)($_SESSION['role'] ?? '');
}

function require_roles(array $roles): void {
    ensure_session();
    $r = auth_role();
    if ($r === '' || !in_array($r, $roles, true)) {
        http_response_code(403);
        echo "Bu sayfaya erisim yetkiniz yok.";
        exit;
    }
}

function require_login(): void {
    ensure_session();
    if (empty($_SESSION['user_id'])) {
        // Pretty route'e yönlendir (Rewrite ile login.php'ye gider)
        redirect(route_url('login'));
    }
}

function require_auth(): void { require_login(); }

/* =========================
   CSRF
========================= */
function csrf_token(): string {
    ensure_session();
    $key = (string) config('security.csrf_key', 'csrf_token');
    if (empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    // Backward-compat alias (some legacy endpoints use _csrf)
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = (string)$_SESSION[$key];
    }
    return $_SESSION[$key];
}

function csrf_field(): string {
    $key = (string) config('security.csrf_key', 'csrf_token');
    return '<input type="hidden" name="'.$key.'" value="'.e(csrf_token()).'">';
}

function csrf_verify(): void {
    ensure_session();
    $key  = (string) config('security.csrf_key', 'csrf_token');

    // Accept token from multiple sources for backward compatibility
    $sent = (string)(
        $_POST[$key]
        ?? $_POST['csrf']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
    );

    $sess = (string)(
        $_SESSION[$key]
        ?? $_SESSION['_csrf']
        ?? ''
    );

    if ($sent === '' || $sess === '' || !hash_equals($sess, $sent)) {
        http_response_code(419);
        exit('CSRF dogrulamasi basarisiz.');
    }
}

/* =========================
   MYSQLI STMT FETCH
========================= */
function stmt_fetch_one(mysqli_stmt $stmt): ?array {
    $meta = $stmt->result_metadata();
    if (!$meta) return null;

    $fields = [];
    while ($f = $meta->fetch_field()) $fields[] = $f->name;
    $meta->free();

    $row = [];
    $bind = [];
    foreach ($fields as $name) {
        $row[$name] = null;
        $bind[] = &$row[$name];
    }

    call_user_func_array([$stmt, 'bind_result'], $bind);

    if ($stmt->fetch()) {
        $out = [];
        foreach ($row as $k => $v) $out[$k] = $v;
        return $out;
    }
    return null;
}

function stmt_fetch_all(mysqli_stmt $stmt): array {
    $meta = $stmt->result_metadata();
    if (!$meta) return [];

    $fields = [];
    while ($f = $meta->fetch_field()) $fields[] = $f->name;
    $meta->free();

    $row = [];
    $bind = [];
    foreach ($fields as $name) {
        $row[$name] = null;
        $bind[] = &$row[$name];
    }

    call_user_func_array([$stmt, 'bind_result'], $bind);

    $rows = [];
    while ($stmt->fetch()) {
        $out = [];
        foreach ($row as $k => $v) $out[$k] = $v;
        $rows[] = $out;
    }
    return $rows;
}

/* =========================
   CSV HELPERS
========================= */
function _guess_delim(string $line): string {
    $candidates = [";", "\t", ",", "|"];
    $best = ";";
    $bestScore = -1;
    foreach ($candidates as $d) {
        $score = substr_count($line, $d);
        if ($score > $bestScore) { $bestScore = $score; $best = $d; }
    }
    return $best;
}

function _csv_fix_row(array $row, string $delim): array {
    if (count($row) === 1) {
        $cell = (string)($row[0] ?? '');
        $cands = [';', "\t", ',', '|'];
        $cands = array_values(array_unique(array_merge([$delim], $cands)));
        foreach ($cands as $d) {
            if ($d !== '' && substr_count($cell, $d) >= 1) {
                $parts = explode($d, $cell);
                if (count($parts) > 1) return $parts;
            }
        }
    }
    return $row;
}

function _ensure_utf8(string $s): string {
    if (!preg_match('//u', $s)) {
        if (function_exists('mb_convert_encoding')) {
            $s = @mb_convert_encoding($s, 'UTF-8', 'UTF-8, Windows-1254, ISO-8859-9, ISO-8859-1');
        } elseif (function_exists('iconv')) {
            $s = @iconv('Windows-1254', 'UTF-8//IGNORE', $s);
        }
    }
    return $s;
}

function _norm_header(string $h): string {
    $h = _ensure_utf8($h);
    $h = trim($h);
    $h = str_replace(["\xEF\xBB\xBF"], '', $h);
    $h = mb_strtolower($h, 'UTF-8');
    $h = str_replace(["\t", "\r", "\n", "\xC2\xA0"], ' ', $h);
    $h = preg_replace('/\s+/u', ' ', $h);
    $h = trim($h, " \"'\t\r\n");
    $h = str_replace(['(tl)', '(try)', '(₺)', '₺', '(tl )', '( try )'], '', $h);
    $h = trim($h);

    $fold = strtr($h, [
        'ç'=>'c', 'ğ'=>'g', 'ı'=>'i', 'i̇'=>'i', 'ö'=>'o', 'ş'=>'s', 'ü'=>'u',
        'â'=>'a', 'î'=>'i', 'û'=>'u'
    ]);
    $fold = preg_replace('/\s+/u', ' ', trim($fold));

    $map = [
        // Tali CSV
        't.c / v.n.'=>'tc_vn', 't.c / v.n'=>'tc_vn', 'tc / vn'=>'tc_vn', 't.c'=>'tc_vn', 'v.n'=>'tc_vn', 'tc'=>'tc_vn', 'vn'=>'tc_vn', 'tc_vn'=>'tc_vn', 'tc/vn'=>'tc_vn',
        'sigortalı'=>'sigortali', 'sigortali'=>'sigortali', 'sigortalı adı'=>'sigortali', 'sigortali adi'=>'sigortali',
        'plaka'=>'plaka',
        'şirket'=>'sirket', 'sirket'=>'sirket', 'sigorta şirketi'=>'sirket', 'sigorta sirketi'=>'sirket',
        'branş'=>'brans', 'brans'=>'brans',
        'tip'=>'tip', 'txn type'=>'tip', 'type'=>'tip', 'islem tipi'=>'tip',
        'tanzim'=>'tanzim', 'tanzim tarihi'=>'tanzim_tarihi', 'tanzim_tarihi'=>'tanzim_tarihi',
        'poliçe no'=>'police_no', 'police no'=>'police_no', 'poliçe'=>'police_no', 'police'=>'police_no', 'policy no'=>'police_no', 'police_no'=>'police_no', 'policeno'=>'police_no',
        'brüt prim'=>'brut_prim', 'brut prim'=>'brut_prim', 'brutprim'=>'brut_prim', 'brütprim'=>'brut_prim', 'brut_prim'=>'brut_prim',

        // Ana CSV
        'bitiş tarihi'=>'bitis_tarihi', 'bitis tarihi'=>'bitis_tarihi', 'bitis_tarihi'=>'bitis_tarihi',
        'sig. kimlik no'=>'sig_kimlik_no', 'sig kimlik no'=>'sig_kimlik_no', 'sigortalı kimlik no'=>'sig_kimlik_no', 'sig kimlik'=>'sig_kimlik_no', 'sig_kimlik_no'=>'sig_kimlik_no', 'kimlik no'=>'sig_kimlik_no',
        'ürün'=>'urun', 'urun'=>'urun',
        'zeyil türü'=>'zeyil_turu', 'zeyil turu'=>'zeyil_turu', 'zeyil_turu'=>'zeyil_turu', 'zeyilturu'=>'zeyil_turu',
        'net prim'=>'net_prim', 'netprim'=>'net_prim', 'net_prim'=>'net_prim',
        'komisyon tutarı'=>'komisyon_tutari', 'komisyon tutari'=>'komisyon_tutari', 'komisyon'=>'komisyon_tutari', 'komisyon_tutari'=>'komisyon_tutari',
        'aracı kom. payı'=>'araci_kom_payi', 'araci kom payi'=>'araci_kom_payi', 'aracı komisyon payı'=>'araci_kom_payi', 'araci komisyon payi'=>'araci_kom_payi', 'araci_kom_payi'=>'araci_kom_payi',

        'id'=>'id',
    ];

    if (isset($map[$h])) return $map[$h];
    if (isset($map[$fold])) return $map[$fold];

    // heuristic fallback
    $clean = preg_replace('/[^a-z0-9]+/', ' ', $fold);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    if (strpos($clean, 'brut') !== false && strpos($clean, 'prim') !== false) return 'brut_prim';
    if (strpos($clean, 'net') !== false && strpos($clean, 'prim') !== false) return 'net_prim';
    if (strpos($clean, 'komisyon') !== false && (strpos($clean, 'tutar') !== false || strpos($clean, 'tutari') !== false)) return 'komisyon_tutari';
    if (strpos($clean, 'araci') !== false && strpos($clean, 'pay') !== false) return 'araci_kom_payi';
    if ((strpos($clean, 'police') !== false || strpos($clean, 'polic') !== false) && strpos($clean, 'no') !== false) return 'police_no';
    if (strpos($clean, 'tanzim') !== false) return 'tanzim_tarihi';
    if (strpos($clean, 'bitis') !== false) return 'bitis_tarihi';
    if (strpos($clean, 'kimlik') !== false && strpos($clean, 'no') !== false) return 'sig_kimlik_no';
    if (strpos($clean, 'sigortali') !== false) return 'sigortali';

    return $h;
}

function _parse_date_any(?string $s): ?string {
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

    if (preg_match('/^(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{4})$/', $s, $m)) {
        $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $y = $m[3];
        return "{$y}-{$mo}-{$d}";
    }

    $ts = strtotime($s);
    if ($ts) return date('Y-m-d', $ts);
    return null;
}

function _to_decimal(?string $s): ?string {
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;
    $s = str_replace([' ', '₺', 'TRY'], '', $s);
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '.', $s);
    }
    if (!is_numeric($s)) return null;
    return number_format((float)$s, 2, '.', '');
}

function _sci_to_int_string(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $s = str_replace(' ', '', $s);
    $s = str_replace(',', '.', $s);
    $s = strtoupper($s);

    if (!preg_match('/^([0-9]+)(?:\.([0-9]+))?E([+\-]?[0-9]+)$/', $s, $m)) {
        return $s;
    }
    $intPart = $m[1];
    $fracPart = $m[2] ?? '';
    $exp = (int)$m[3];

    $digits = $intPart . $fracPart;
    $digits = ltrim($digits, '0');
    if ($digits === '') $digits = '0';

    $decPos = strlen($intPart);
    $newDecPos = $decPos + $exp;

    if ($newDecPos <= 0) {
        return '0';
    }

    if ($newDecPos >= strlen($digits)) {
        return $digits . str_repeat('0', $newDecPos - strlen($digits));
    }

    return substr($digits, 0, $newDecPos);
}

function _policy_clean(?string $s): ?string {
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;

    if (stripos($s, 'E+') !== false || stripos($s, 'E-') !== false) {
        $s2 = _sci_to_int_string($s);
        $s = $s2;
    }

    $s = trim($s);

    if (preg_match('/[A-Za-z]/', $s)) {
        $s = strtoupper($s);
        $s = preg_replace('/\s+/', '', $s);
        return $s;
    }

    $digits = preg_replace('/\D+/', '', $s);
    return $digits === '' ? null : $digits;
}

function _policy_norm(?string $s): string {
    $s2 = _policy_clean($s);
    if ($s2 === null) return '';
    if (ctype_digit($s2)) {
        $t = ltrim($s2, '0');
        return $t === '' ? '0' : $t;
    }
    return $s2;
}

/* =========================
   MONEY FORMAT (TR)
========================= */
function _fmt_tr_money($v): string {
    if ($v === null || $v === '') return '';
    $n = (float)$v;
    return number_format($n, 2, ',', '.');
}

function _money_span($v): string {
    if ($v === null || $v === '') return '';
    $n = (float)$v;
    $cls = ($n < 0) ? 'neg' : (($n > 0) ? 'pos' : 'zero');
    $sign = ($n > 0) ? '+' : '';
    return '<span class="money '.$cls.'">'.$sign.number_format($n, 2, ',', '.').'</span>';
}

/* =========================
   TXN TYPE MAPPING
========================= */
function _map_tali_txn_type(string $tipRaw, ?string $brut): string {
    $tip = mb_strtoupper(trim($tipRaw), 'UTF-8');
    
    if (strpos($tip, 'IPTAL') !== false || strpos($tip, 'IADE') !== false) {
        return 'IPTAL';
    }
    if (strpos($tip, 'ZEYIL') !== false) {
        return 'ZEYIL';
    }
    if (strpos($tip, 'SATIS') !== false || strpos($tip, 'YENİ') !== false || strpos($tip, 'YENI') !== false) {
        return 'SATIS';
    }
    
    // brut prim negatifse iptal olabilir
    if ($brut !== null && (float)$brut < 0) {
        return 'IPTAL';
    }
    
    return 'SATIS';
}

function _map_ana_txn_type(string $zeyilTuru): string {
    $zeyilNorm = mb_strtolower(trim($zeyilTuru), 'UTF-8');
    
    if ($zeyilNorm === '') {
        return 'SATIS';
    }
    if (strpos($zeyilNorm, 'iptal') !== false || strpos($zeyilNorm, 'iade') !== false) {
        return 'IPTAL';
    }
    if (strpos($zeyilNorm, 'zeyil') !== false) {
        return 'ZEYIL';
    }
    
    return 'SATIS';
}

/* =========================
   COLUMN EXISTS CHECK
========================= */
function col_exists(mysqli $conn, string $table, string $col): bool {
    $dbName = $conn->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
    if ($dbName === '') return false;
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('sss', $dbName, $table, $col);
    $st->execute();
    $st->store_result();
    $ok = $st->num_rows > 0;
    $st->close();
    return $ok;
}

/* =========================
   GET MAIN AGENCY ID
========================= */
function get_main_agency_id(mysqli $conn, int $taliAgencyId): int {
    // agency_relations
    if (col_exists($conn, 'agency_relations', 'child_agency_id') && col_exists($conn, 'agency_relations', 'parent_agency_id')) {
        $st = $conn->prepare("SELECT parent_agency_id FROM agency_relations WHERE child_agency_id=? LIMIT 1");
        if ($st) {
            $st->bind_param('i', $taliAgencyId);
            $st->execute();
            $res = $st->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $pid = (int)($row['parent_agency_id'] ?? 0);
                $st->close();
                if ($pid > 0) return $pid;
            }
            $st->close();
        }
    }

    // agencies aday kolonlar
    $candidates = ['parent_id', 'main_agency_id', 'ana_acente_id', 'main_id'];
    foreach ($candidates as $col) {
        if (!col_exists($conn, 'agencies', $col)) continue;
        $sql = "SELECT $col AS pid FROM agencies WHERE id=? LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) continue;
        $st->bind_param('i', $taliAgencyId);
        $st->execute();
        $res = $st->get_result();
        if ($res && ($r = $res->fetch_assoc())) {
            $pid = (int)($r['pid'] ?? 0);
            $st->close();
            if ($pid > 0) return $pid;
        }
        $st->close();
    }
    return $taliAgencyId;
}

/* =========================
   GET TALI WORK MODE
========================= */
function get_tali_work_mode(mysqli $conn, int $agencyId): string {
    if (!col_exists($conn, 'agencies', 'work_mode')) {
        return 'ticket';
    }
    $st = $conn->prepare("SELECT work_mode FROM agencies WHERE id=? LIMIT 1");
    if (!$st) return 'ticket';
    $st->bind_param('i', $agencyId);
    $st->execute();
    $res = $st->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $v = strtolower(trim((string)($row['work_mode'] ?? 'ticket')));
        $st->close();
        if (in_array($v, ['ticket', 'csv'], true)) return $v;
    } else {
        $st->close();
    }
    return 'ticket';
}
